<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Otorisasi channel privat wajib login; hak akses diperiksa di routes/channels.php (backend).
        Broadcast::routes(['middleware' => ['web', 'auth']]);

        // Gagal-aman: bila BROADCAST_DRIVER=reverb diisi sebelum paket (laravel/reverb, pusher/pusher-php-server) terpasang,
        // pendaftaran channel gagal, tetapi aplikasi tetap berjalan dan memakai polling.
        try {
            require base_path('routes/channels.php');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Channel realtime tidak terdaftar: '.$e->getMessage());
        }
    }
}
