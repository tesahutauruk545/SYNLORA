<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\GmailAddress;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'nim' => preg_replace('/\s+/', '', (string) $request->input('nim')),
            'kelas' => strtoupper(trim((string) $request->input('kelas'))),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $isDosen = $request->input('role') === 'dosen';

        if ($isDosen) {
            $this->authorizeDosenRegistration($request);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'max:255', new GmailAddress, 'unique:'.User::class],
            'role' => ['required', 'in:mahasiswa,dosen'],
            'nim' => [Rule::requiredIf(! $isDosen), 'nullable', 'regex:/^[0-9]{6,20}$/', 'unique:users,nim'],
            'kelas' => [Rule::requiredIf(! $isDosen), 'nullable', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9 \-\/]*$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'nim.required' => 'NIM wajib diisi untuk mahasiswa.',
            'nim.regex' => 'NIM harus berupa angka (6-20 digit).',
            'nim.unique' => 'NIM ini sudah terdaftar.',
            'kelas.required' => 'Kelas wajib diisi untuk mahasiswa.',
            'kelas.regex' => 'Kelas hanya boleh berisi huruf, angka, spasi, tanda hubung, atau garis miring.',
        ]);

        $user = new User([
            'name' => trim($request->name),
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        $user->role = $isDosen ? 'dosen' : 'mahasiswa';
        if (! $isDosen) {
            $user->nim = $request->nim;
            $user->kelas = $request->kelas;
        }
        $user->save();

        // Email verifikasi dikirim lewat event Registered. Bila SMTP bermasalah, akun tetap dibuat
        // dan pengguna dapat meminta kirim ulang dari halaman verifikasi (bukan halaman error 500).
        $mailFailed = false;
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            $mailFailed = true;
            Log::error('Gagal mengirim email verifikasi saat registrasi: '.$e->getMessage(), ['user_id' => $user->id]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $redirect = redirect()->route('verification.notice');

        return $mailFailed
            ? $redirect->withErrors(['email' => 'Akun dibuat, tetapi email verifikasi belum terkirim. Klik "Kirim ulang email verifikasi" di bawah.'])
            : $redirect;
    }

    /**
     * Peran dosen memberi hak monitoring, jadi pemilihan peran tidak boleh bebas.
     * Mode (config synlora.dosen_registration): invite (bawaan) | closed | open (perilaku lama).
     * Kode undangan dibandingkan dengan hash_equals dan percobaan salah dibatasi per IP.
     */
    private function authorizeDosenRegistration(Request $request): void
    {
        $mode = (string) config('synlora.dosen_registration', 'invite');

        if ($mode === 'open') {
            return;
        }

        $fail = fn (string $msg) => throw ValidationException::withMessages(['role' => $msg]);

        if ($mode === 'closed') {
            $fail('Pendaftaran akun dosen ditutup. Hubungi admin institusi untuk dijadikan dosen.');
        }

        $expected = (string) config('synlora.dosen_invite_code', '');
        if ($expected === '') {
            $fail('Pendaftaran dosen belum dikonfigurasi oleh admin (kode undangan belum diatur).');
        }

        $key = 'dosen-invite:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $fail('Terlalu banyak percobaan kode undangan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.');
        }

        $given = trim((string) $request->input('invite_code'));
        if ($given === '' || ! hash_equals($expected, $given)) {
            RateLimiter::hit($key, 600);
            $fail('Kode undangan dosen tidak valid.');
        }

        RateLimiter::clear($key);
    }
}
