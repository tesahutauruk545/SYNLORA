<?php

/*
 | Konfigurasi server Laravel Reverb (php artisan reverb:start). Paket: composer require laravel/reverb
 | Nilai yang sama dipakai klien lewat App\Support\Realtime dan siaran server lewat config/broadcasting.php (koneksi 'reverb').
 */
return [
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => false,
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => (int) env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                // Batasi asal koneksi WebSocket ke domain aplikasi (isi mis. REVERB_ALLOWED_ORIGINS=synlora.test,localhost).
                'allowed_origins' => array_filter(explode(',', env('REVERB_ALLOWED_ORIGINS', '*'))),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
            ],
        ],
    ],
];
