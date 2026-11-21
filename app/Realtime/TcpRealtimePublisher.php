<?php

namespace App\Realtime;

use Illuminate\Support\Facades\Log;

/**
 * Mengirim pesan ke daemon `php artisan synlora:realtime` lewat soket TCP lokal
 * (127.0.0.1). Pesan ditandatangani HMAC agar proses lain di mesin yang sama
 * tidak bisa menyuntikkan event. Bila daemon tidak berjalan, hasilnya false dan
 * aplikasi tetap bekerja: browser memakai polling sebagai cadangan.
 */
class TcpRealtimePublisher implements RealtimePublisher
{
    public function publish(int $roomId, array $to, string $event, array $data): bool
    {
        if (! config('synlora.realtime.enabled')) {
            return false;
        }

        $json = json_encode(['room' => $roomId, 'to' => $to, 'event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        $line = json_encode(['sig' => hash_hmac('sha256', $json, RealtimeToken::secret()), 'payload' => $json])."\n";

        $host = config('synlora.realtime.internal_host');
        $port = config('synlora.realtime.internal_port');

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.25);
        if (! $sock) {
            return false;
        }

        stream_set_timeout($sock, 0, 250000);
        $ok = @fwrite($sock, $line) === strlen($line);
        fclose($sock);

        if (! $ok) {
            Log::debug('Realtime publish gagal menulis ke daemon.');
        }

        return $ok;
    }
}
