<?php

namespace App\Realtime;

/**
 * Server WebSocket ringan (RFC 6455) tanpa dependensi, berbasis stream_select.
 *
 *  - Klien (browser) terhubung ke ws://host:PORT/ws?token=...  Token dibuat backend
 *    (RealtimeToken) dan berisi pengguna, room, serta peran. Tanpa token valid => 401.
 *  - Laravel mengirim event lewat soket internal 127.0.0.1:INTERNAL_PORT (JSON + HMAC).
 *  - Penerima disaring DI SINI berdasarkan token: owner room menerima event 'owners',
 *    peserta hanya menerima event yang ditujukan kepadanya, dan tidak pernah event room lain.
 *  - Server ini tidak menyimpan data apa pun; sumber kebenaran tetap MySQL.
 */
class WebSocketServer
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_FRAME = 65536;
    private const MAX_CONNECTIONS = 2000;
    private const PING_EVERY = 25;
    private const DEAD_AFTER = 75;

    /** @var resource */
    private $wsServer;
    /** @var resource */
    private $inServer;

    /** @var array<int,array> koneksi klien keyed by id */
    private array $conns = [];
    /** @var array<int,array> koneksi internal (publisher) keyed by id */
    private array $inConns = [];

    private int $nextId = 1;
    private bool $running = true;
    private int $lastHousekeeping = 0;

    public int $boundPort = 0;
    public int $boundInternalPort = 0;

    /**
     * @param  \Closure(string):void  $log
     * @param  string[]  $allowedOrigins  kosong = semua origin diizinkan
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $internalHost,
        private int $internalPort,
        private string $secret,
        private \Closure $log,
        private array $allowedOrigins = [],
    ) {
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function listen(): void
    {
        $errno = 0;
        $errstr = '';

        $this->wsServer = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (! $this->wsServer) {
            throw new \RuntimeException("Tidak bisa membuka port WebSocket {$this->port}: {$errstr}");
        }
        $this->inServer = @stream_socket_server("tcp://{$this->internalHost}:{$this->internalPort}", $errno, $errstr);
        if (! $this->inServer) {
            throw new \RuntimeException("Tidak bisa membuka port internal {$this->internalPort}: {$errstr}");
        }

        stream_set_blocking($this->wsServer, false);
        stream_set_blocking($this->inServer, false);

        $this->boundPort = (int) substr(strrchr(stream_socket_get_name($this->wsServer, false), ':'), 1);
        $this->boundInternalPort = (int) substr(strrchr(stream_socket_get_name($this->inServer, false), ':'), 1);
    }

    public function run(): void
    {
        if (! $this->wsServer) {
            $this->listen();
        }

        ($this->log)("WebSocket siap di ws://{$this->host}:{$this->boundPort}/ws  (internal {$this->internalHost}:{$this->boundInternalPort})");

        while ($this->running) {
            $this->tick(1);
        }

        foreach ($this->conns as $id => $_) {
            $this->drop($id);
        }
        @fclose($this->wsServer);
        @fclose($this->inServer);
    }

    /** Satu putaran event-loop (dipisah agar mudah diuji). */
    public function tick(int $timeoutSeconds = 1): void
    {
        $read = [$this->wsServer, $this->inServer];
        $write = [];
        foreach ($this->conns as $c) {
            $read[] = $c['sock'];
            if ($c['out'] !== '') {
                $write[] = $c['sock'];
            }
        }
        foreach ($this->inConns as $c) {
            $read[] = $c['sock'];
        }
        $except = null;

        $n = @stream_select($read, $write, $except, $timeoutSeconds);

        if ($n !== false && $n > 0) {
            foreach ($read as $sock) {
                if ($sock === $this->wsServer) {
                    $this->acceptClient();
                } elseif ($sock === $this->inServer) {
                    $this->acceptInternal();
                } else {
                    $this->readFrom($sock);
                }
            }
            foreach ($write as $sock) {
                $this->flush($this->idOf($sock));
            }
        }

        if (time() !== $this->lastHousekeeping) {
            $this->lastHousekeeping = time();
            $this->housekeeping();
        }
    }

    // ----------------------------------------------------------- koneksi

    private function acceptClient(): void
    {
        $sock = @stream_socket_accept($this->wsServer, 0);
        if (! $sock) {
            return;
        }
        if (count($this->conns) >= self::MAX_CONNECTIONS) {
            fclose($sock);

            return;
        }

        stream_set_blocking($sock, false);
        $id = $this->nextId++;
        $this->conns[$id] = [
            'id' => $id, 'sock' => $sock, 'state' => 'handshake', 'in' => '', 'out' => '',
            'user' => 0, 'room' => 0, 'owner' => false, 'exp' => 0,
            'frag' => '', 'fragOp' => 0, 'seen' => time(), 'pinged' => 0, 'closing' => false,
        ];
    }

    private function acceptInternal(): void
    {
        $sock = @stream_socket_accept($this->inServer, 0);
        if (! $sock) {
            return;
        }
        stream_set_blocking($sock, false);
        $id = $this->nextId++;
        $this->inConns[$id] = ['id' => $id, 'sock' => $sock, 'in' => '', 'seen' => time()];
    }

    private function idOf($sock): ?int
    {
        foreach ($this->conns as $id => $c) {
            if ($c['sock'] === $sock) {
                return $id;
            }
        }

        return null;
    }

    private function readFrom($sock): void
    {
        foreach ($this->inConns as $id => $c) {
            if ($c['sock'] === $sock) {
                $this->readInternal($id);

                return;
            }
        }

        $id = $this->idOf($sock);
        if ($id === null) {
            return;
        }

        $data = @fread($sock, 8192);
        if ($data === '' || $data === false) {
            if (feof($sock) || $data === false) {
                $this->drop($id);
            }

            return;
        }

        $this->conns[$id]['seen'] = time();
        $this->conns[$id]['in'] .= $data;

        if ($this->conns[$id]['state'] === 'handshake') {
            $this->handshake($id);
        }
        if (isset($this->conns[$id]) && $this->conns[$id]['state'] === 'open') {
            $this->parseFrames($id);
        }
    }

    // --------------------------------------------------------- handshake

    private function handshake(int $id): void
    {
        $c = &$this->conns[$id];

        if (strlen($c['in']) > 8192) {
            $this->reject($id, '431 Request Header Fields Too Large');

            return;
        }

        $end = strpos($c['in'], "\r\n\r\n");
        if ($end === false) {
            return;
        }

        $head = substr($c['in'], 0, $end);
        $c['in'] = (string) substr($c['in'], $end + 4);

        $lines = explode("\r\n", $head);
        $request = array_shift($lines);
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        if (! preg_match('#^GET (\S+) HTTP/1\.[01]$#', $request, $m)) {
            $this->reject($id, '400 Bad Request');

            return;
        }

        $url = parse_url($m[1]);
        parse_str($url['query'] ?? '', $query);

        if (($url['path'] ?? '') !== '/ws'
            || strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || ! str_contains(strtolower($headers['connection'] ?? ''), 'upgrade')
            || empty($headers['sec-websocket-key'])
            || ($headers['sec-websocket-version'] ?? '') !== '13') {
            $this->reject($id, '400 Bad Request');

            return;
        }

        if ($this->allowedOrigins && ! in_array($headers['origin'] ?? '', $this->allowedOrigins, true)) {
            $this->reject($id, '403 Forbidden');

            return;
        }

        $auth = RealtimeToken::verify((string) ($query['token'] ?? ''), $this->secret);
        if (! $auth) {
            $this->reject($id, '401 Unauthorized');

            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'].self::GUID, true));
        $c['out'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
        $c['state'] = 'open';
        $c['user'] = $auth['u'];
        $c['room'] = $auth['r'];
        $c['owner'] = $auth['o'];
        $c['exp'] = $auth['exp'];

        $this->flush($id);
        $this->sendText($id, json_encode(['event' => 'hello', 'data' => ['role' => $auth['o'] ? 'owner' : 'member']]));
    }

    private function reject(int $id, string $status): void
    {
        $c = &$this->conns[$id];
        $c['out'] .= "HTTP/1.1 {$status}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        $c['closing'] = true;
        $c['state'] = 'closing';
        $this->flush($id);
    }

    // ------------------------------------------------------------ frame

    private function parseFrames(int $id): void
    {
        while (isset($this->conns[$id]) && strlen($this->conns[$id]['in']) >= 2) {
            $buf = $this->conns[$id]['in'];
            $b1 = ord($buf[0]);
            $b2 = ord($buf[1]);
            $fin = (bool) ($b1 & 0x80);
            $op = $b1 & 0x0F;
            $masked = (bool) ($b2 & 0x80);
            $len = $b2 & 0x7F;
            $off = 2;

            if ($len === 126) {
                if (strlen($buf) < 4) {
                    return;
                }
                $len = unpack('n', substr($buf, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if (strlen($buf) < 10) {
                    return;
                }
                $len = unpack('J', substr($buf, 2, 8))[1];
                $off = 10;
            }

            if (! $masked) {
                $this->closeWith($id, 1002);

                return;
            }
            if ($len > self::MAX_FRAME || $len < 0) {
                $this->closeWith($id, 1009);

                return;
            }
            if (strlen($buf) < $off + 4 + $len) {
                return;
            }

            $mask = substr($buf, $off, 4);
            $payload = substr($buf, $off + 4, $len);
            if ($len > 0) {
                $payload = $payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
            }
            $this->conns[$id]['in'] = (string) substr($buf, $off + 4 + $len);

            switch ($op) {
                case 0x8: // close
                    $this->closeWith($id, 1000);

                    return;
                case 0x9: // ping
                    $this->sendFrame($id, 0xA, $payload);
                    break;
                case 0xA: // pong
                    $this->conns[$id]['pinged'] = 0;
                    break;
                case 0x1:
                case 0x2:
                case 0x0:
                    if ($op !== 0x0) {
                        $this->conns[$id]['fragOp'] = $op;
                        $this->conns[$id]['frag'] = '';
                    }
                    $this->conns[$id]['frag'] .= $payload;
                    if (strlen($this->conns[$id]['frag']) > self::MAX_FRAME) {
                        $this->closeWith($id, 1009);

                        return;
                    }
                    if ($fin) {
                        $message = $this->conns[$id]['frag'];
                        $this->conns[$id]['frag'] = '';
                        $this->onMessage($id, $message);
                    }
                    break;
                default:
                    $this->closeWith($id, 1002);

                    return;
            }
        }
    }

    /** Klien hanya boleh mengirim ping aplikasi; data apa pun dari klien tidak pernah disiarkan. */
    private function onMessage(int $id, string $message): void
    {
        $data = json_decode($message, true);
        if (is_array($data) && ($data['type'] ?? null) === 'ping') {
            $this->sendText($id, json_encode(['event' => 'pong', 'data' => ['t' => time()]]));
        }
    }

    private function sendText(int $id, string $text): void
    {
        $this->sendFrame($id, 0x1, $text);
    }

    private function sendFrame(int $id, int $op, string $payload): void
    {
        if (! isset($this->conns[$id])) {
            return;
        }

        $len = strlen($payload);
        $header = chr(0x80 | $op);
        if ($len < 126) {
            $header .= chr($len);
        } elseif ($len <= 65535) {
            $header .= chr(126).pack('n', $len);
        } else {
            $header .= chr(127).pack('J', $len);
        }

        $this->conns[$id]['out'] .= $header.$payload;

        if (strlen($this->conns[$id]['out']) > 1048576) { // klien terlalu lambat
            $this->drop($id);

            return;
        }

        $this->flush($id);
    }

    private function closeWith(int $id, int $code): void
    {
        if (! isset($this->conns[$id])) {
            return;
        }
        $this->conns[$id]['closing'] = true;
        $this->conns[$id]['state'] = 'closing';
        $header = chr(0x88).chr(2).pack('n', $code);
        $this->conns[$id]['out'] .= $header;
        $this->flush($id);
    }

    private function flush(?int $id): void
    {
        if ($id === null || ! isset($this->conns[$id])) {
            return;
        }

        $c = &$this->conns[$id];
        if ($c['out'] !== '') {
            $written = @fwrite($c['sock'], $c['out']);
            if ($written === false) {
                $this->drop($id);

                return;
            }
            $c['out'] = (string) substr($c['out'], $written);
        }

        if ($c['out'] === '' && $c['closing']) {
            $this->drop($id);
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->conns[$id])) {
            @fclose($this->conns[$id]['sock']);
            unset($this->conns[$id]);
        }
    }

    // ---------------------------------------------------- publish internal

    private function readInternal(int $id): void
    {
        $c = &$this->inConns[$id];
        $data = @fread($c['sock'], 65536);

        if ($data !== '' && $data !== false) {
            $c['in'] .= $data;
            if (strlen($c['in']) > 262144) {
                $this->dropInternal($id);

                return;
            }
        }

        while (($pos = strpos($c['in'], "\n")) !== false) {
            $line = substr($c['in'], 0, $pos);
            $c['in'] = (string) substr($c['in'], $pos + 1);
            $this->handlePublish($line);
        }

        if (feof($c['sock']) || $data === false) {
            if (trim($c['in']) !== '') {
                $this->handlePublish(trim($c['in']));
            }
            $this->dropInternal($id);
        }
    }

    private function dropInternal(int $id): void
    {
        if (isset($this->inConns[$id])) {
            @fclose($this->inConns[$id]['sock']);
            unset($this->inConns[$id]);
        }
    }

    private function handlePublish(string $line): void
    {
        $outer = json_decode($line, true);
        if (! is_array($outer) || ! isset($outer['sig'], $outer['payload'])
            || ! hash_equals(hash_hmac('sha256', (string) $outer['payload'], $this->secret), (string) $outer['sig'])) {
            ($this->log)('Pesan internal ditolak (tanda tangan tidak valid).');

            return;
        }

        $msg = json_decode((string) $outer['payload'], true);
        if (! is_array($msg) || ! isset($msg['room'], $msg['event'])) {
            return;
        }

        $this->dispatch((int) $msg['room'], (array) ($msg['to'] ?? []), (string) $msg['event'], (array) ($msg['data'] ?? []));
    }

    public function dispatch(int $room, array $to, string $event, array $data): int
    {
        $frame = json_encode(['event' => $event, 'data' => $data, 'ts' => time()], JSON_UNESCAPED_UNICODE);
        $all = ! empty($to['all']);
        $owners = ! empty($to['owners']);
        $users = array_map('intval', (array) ($to['users'] ?? []));
        $sent = 0;

        foreach ($this->conns as $id => $c) {
            if ($c['state'] !== 'open' || $c['room'] !== $room) {
                continue;
            }

            if ($all || ($owners && $c['owner']) || in_array($c['user'], $users, true)) {
                $this->sendText($id, $frame);
                $sent++;
            }
        }

        return $sent;
    }

    // ------------------------------------------------------ housekeeping

    private function housekeeping(): void
    {
        $now = time();

        foreach ($this->conns as $id => $c) {
            if ($c['state'] === 'handshake' && $now - $c['seen'] > 10) {
                $this->drop($id);
                continue;
            }
            if ($c['state'] !== 'open') {
                continue;
            }
            if ($c['exp'] > 0 && $now > $c['exp'] + 3600) { // token lama sekali: paksa sambung ulang
                $this->closeWith($id, 1000);
                continue;
            }
            if ($now - $c['seen'] > self::DEAD_AFTER) {
                $this->drop($id);
                continue;
            }
            if ($now - $c['seen'] > self::PING_EVERY && $c['pinged'] === 0) {
                $this->conns[$id]['pinged'] = $now;
                $this->sendFrame($id, 0x9, 'p');
            }
        }

        foreach ($this->inConns as $id => $c) {
            if ($now - $c['seen'] > 5) {
                $this->dropInternal($id);
            }
        }
    }

    public function connectionCount(): int
    {
        return count($this->conns);
    }
}
