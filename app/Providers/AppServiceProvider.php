<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Nama hari/bulan dan "x menit yang lalu" dalam Bahasa Indonesia.
        // Kata sandi: minimal 8 karakter, wajib mengandung huruf dan angka.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        Carbon::setLocale('id');
        setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'id');
    }
}
