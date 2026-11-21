<?php

namespace App\Console\Commands;

use App\Realtime\RealtimeToken;
use App\Realtime\WebSocketServer;
use Illuminate\Console\Command;

class RealtimeServe extends Command
{
    protected $signature = 'synlora:realtime
                            {--host= : Alamat bind WebSocket (bawaan REALTIME_HOST)}
                            {--port= : Port WebSocket (bawaan REALTIME_PORT=6001)}
                            {--internal-port= : Port internal untuk Laravel (bawaan REALTIME_INTERNAL_PORT=6002)}';

    protected $description = 'Menjalankan server WebSocket SYNLORA untuk status fokus meeting secara real-time';

    public function handle(): int
    {
        $cfg = config('synlora.realtime');

        $server = new WebSocketServer(
            $this->option('host') ?: $cfg['host'],
            (int) ($this->option('port') ?: $cfg['port']),
            $cfg['internal_host'],
            (int) ($this->option('internal-port') ?: $cfg['internal_port']),
            RealtimeToken::secret(),
            fn (string $m) => $this->line('['.date('H:i:s').'] '.$m),
            $cfg['allowed_origins'],
        );

        try {
            $server->listen();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('Port sudah dipakai? Tutup proses lama, atau ubah REALTIME_PORT / REALTIME_INTERNAL_PORT di .env.');

            return self::FAILURE;
        }

        $this->info('SYNLORA Realtime berjalan. Tekan Ctrl+C untuk berhenti.');
        $server->run();

        return self::SUCCESS;
    }
}
