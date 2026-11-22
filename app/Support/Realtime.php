<?php

namespace App\Support;

/** Konfigurasi publik klien realtime (Laravel Echo -> Reverb). Nilai berasal dari .env saat runtime, bukan dari build Vite. */
class Realtime
{
    public static function config(): ?array
    {
        $c = config('broadcasting.connections.reverb');
        if (config('broadcasting.default') !== 'reverb' || empty($c['key'])) {
            return null;
        }

        return [
            'key' => $c['key'],
            'host' => $c['options']['host'] ?? null,
            'port' => (int) ($c['options']['port'] ?? 8080),
            'scheme' => $c['options']['scheme'] ?? 'http',
            'authEndpoint' => url('/broadcasting/auth'),
        ];
    }
}
