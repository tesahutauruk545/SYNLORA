<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Email verifikasi & reset password berbahasa Indonesia. Tautan bertanda tangan/token tetap
        // dibuat oleh Laravel (kedaluwarsa & sekali pakai diatur oleh config/auth.php).
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            $minutes = (int) config('auth.verification.expire', 60);

            return (new MailMessage)
                ->subject('Verifikasi email akun SYNLORA')
                ->greeting('Halo, '.($notifiable->name ?? 'pengguna').'!')
                ->line('Terima kasih sudah mendaftar di SYNLORA. Klik tombol di bawah untuk memverifikasi alamat Gmail Anda.')
                ->action('Verifikasi Email', $url)
                ->line("Tautan ini berlaku selama {$minutes} menit. Jika sudah kedaluwarsa, minta tautan baru dari halaman verifikasi.")
                ->line('Jika Anda tidak merasa mendaftar di SYNLORA, abaikan email ini.')
                ->salutation('Salam, Tim SYNLORA');
        });

        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Atur ulang kata sandi SYNLORA')
                ->greeting('Halo, '.($notifiable->name ?? 'pengguna').'!')
                ->line('Kami menerima permintaan untuk mengatur ulang kata sandi akun SYNLORA Anda.')
                ->action('Atur Ulang Kata Sandi', $url)
                ->line("Tautan ini hanya berlaku {$minutes} menit dan hanya bisa dipakai satu kali.")
                ->line('Jika Anda tidak meminta reset kata sandi, abaikan email ini; kata sandi Anda tidak akan berubah.')
                ->salutation('Salam, Tim SYNLORA');
        });

        Carbon::setLocale('id');
        setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'id');
    }
}
