<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Alamat email pendaftaran wajib Gmail (@gmail.com) dengan format nama pengguna Gmail yang sah
 * (6-30 karakter huruf/angka/titik, tidak diawali/diakhiri titik, tanpa titik berurutan).
 * Gmail mengabaikan titik pada nama pengguna, jadi a.b@gmail.com dan ab@gmail.com dianggap
 * satu alamat yang sama agar satu Gmail tidak bisa mendaftar dua kali.
 * Gmail yang benar-benar aktif dibuktikan oleh tautan verifikasi yang dikirim ke alamat itu.
 */
class GmailAddress implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if (! preg_match('/^(?=.{6,30}@)[a-z0-9](?:[a-z0-9]|\.(?!\.))*[a-z0-9]@gmail\.com$/', $email)) {
            $fail('Gunakan alamat Gmail yang valid (contoh: nama@gmail.com).');

            return;
        }

        $canonical = str_replace('.', '', substr($email, 0, -strlen('@gmail.com')));

        $taken = User::query()
            ->when($this->ignoreUserId, fn ($q) => $q->where('id', '!=', $this->ignoreUserId))
            ->where('email', 'like', '%@gmail.com')
            ->whereRaw("REPLACE(LOWER(email), '.', '') = ?", [$canonical.'@gmailcom'])
            ->exists();

        if ($taken) {
            $fail('Gmail ini sudah terdaftar.');
        }
    }
}
