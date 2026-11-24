<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Verifikasi Gmail + reset password. Memakai pengiriman email nyata ke transport "array"
 * (tanpa SMTP sungguhan) agar isi email dan tautannya ikut teruji.
 */
class GmailVerificationAndResetTest extends TestCase
{
    use RefreshDatabase;

    private function mhsPayload(array $extra = []): array
    {
        return $extra + ['name' => 'Budi Santoso', 'role' => 'mahasiswa', 'email' => 'budi.santoso@gmail.com', 'nim' => '2203111001', 'kelas' => 'A',
            'password' => 'password123', 'password_confirmation' => 'password123'];
    }

    private function dosenPayload(array $extra = []): array
    {
        config(['synlora.dosen_registration' => 'invite', 'synlora.dosen_invite_code' => 'KODE-UJI']);

        return $extra + ['name' => 'Dr. Dewi', 'role' => 'dosen', 'email' => 'dewi.dosen@gmail.com', 'invite_code' => 'KODE-UJI',
            'password' => 'password123', 'password_confirmation' => 'password123'];
    }

    /** Email terakhir yang benar-benar dikirim lewat transport array. */
    private function lastMail(): \Symfony\Component\Mime\Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'Tidak ada email yang terkirim.');

        return $messages->last()->getOriginalMessage();
    }

    /** Ambil tautan tombol dari isi email (HTML). */
    private function linkFromMail(string $startsWith): string
    {
        $html = html_entity_decode($this->lastMail()->getHtmlBody());
        preg_match_all('#https?://[^\s"<>\']+#', $html, $m);
        foreach ($m[0] as $url) {
            if (str_starts_with($url, $startsWith)) {
                return $url;
            }
        }
        $this->fail("Tautan {$startsWith} tidak ditemukan di email.");
    }

    // ------------------------------------------------------------ 1. Gmail wajib

    public function test_registration_rejects_non_gmail_for_both_roles(): void
    {
        foreach (['budi@yahoo.com', 'budi@example.com', 'budiuji@gmail.co', 'budiuji@gmail.com.evil.com', 'budiuji@mail.gmail.com', 'budiuji@googlemail.com', 'budiuji@GMAIL.COM.id'] as $bad) {
            $this->post('/register', $this->mhsPayload(['email' => $bad]))->assertSessionHasErrors('email');
            $this->post('/register', $this->dosenPayload(['email' => $bad]))->assertSessionHasErrors('email');
        }
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_registration_rejects_malformed_gmail_usernames(): void
    {
        foreach (['abc@gmail.com', 'abcde@gmail.com', '.budiuji@gmail.com', 'budiuji.@gmail.com', 'budi..uji@gmail.com', 'budi uji@gmail.com', 'budi+tag@gmail.com', str_repeat('a', 31).'@gmail.com'] as $bad) {
            $this->post('/register', $this->mhsPayload(['email' => $bad]))->assertSessionHasErrors('email');
        }
        $this->assertSame(0, User::count());
    }

    public function test_gmail_is_case_insensitive_and_dots_do_not_create_duplicates(): void
    {
        Notification::fake();
        $this->post('/register', $this->mhsPayload(['email' => '  Budi.Santoso@GMAIL.com ']))->assertRedirect(route('verification.notice'));
        $this->assertSame('budi.santoso@gmail.com', User::first()->email);

        auth()->logout();

        // Gmail mengabaikan titik: budisantoso@gmail.com adalah kotak masuk yang sama.
        $this->post('/register', $this->mhsPayload(['email' => 'budisantoso@gmail.com', 'nim' => '2203111002']))->assertSessionHasErrors('email');
        $this->post('/register', $this->mhsPayload(['email' => 'budi.santoso@gmail.com', 'nim' => '2203111002']))->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    // ------------------------------------------------------------ 2. Verifikasi sebelum dipakai

    public function test_new_student_and_dosen_are_created_unverified_and_get_verification_email(): void
    {
        Notification::fake();

        $this->post('/register', $this->mhsPayload())->assertRedirect(route('verification.notice'));
        $mhs = User::where('email', 'budi.santoso@gmail.com')->first();
        $this->assertNull($mhs->email_verified_at);
        $this->assertSame('mahasiswa', $mhs->role);
        Notification::assertSentTo($mhs, VerifyEmail::class);

        auth()->logout();

        $this->post('/register', $this->dosenPayload())->assertRedirect(route('verification.notice'));
        $dosen = User::where('email', 'dewi.dosen@gmail.com')->first();
        $this->assertNull($dosen->email_verified_at);
        $this->assertSame('dosen', $dosen->role);
        Notification::assertSentTo($dosen, VerifyEmail::class);
    }

    public function test_unverified_accounts_cannot_use_the_app_but_can_reach_verification_and_profile(): void
    {
        $mhs = User::factory()->unverified()->create();
        $dosen = User::factory()->dosen()->unverified()->create();

        foreach ([$mhs, $dosen] as $u) {
            foreach (['/dashboard', '/rooms/join', '/rooms/create', '/pomodoro', '/riwayat', '/notifications'] as $url) {
                $this->actingAs($u)->get($url)->assertRedirect(route('verification.notice'));
            }
            $this->actingAs($u)->post(route('rooms.store'), ['name' => 'Room', 'target_minutes' => 25])->assertRedirect(route('verification.notice'));
            $this->actingAs($u)->getJson('/notifications')->assertForbidden();
            $this->actingAs($u)->get('/verify-email')->assertOk();
            $this->actingAs($u)->get('/profile')->assertOk();
        }
        $this->actingAs($dosen)->get('/dosen')->assertRedirect(route('verification.notice'));
    }

    public function test_full_flow_register_receive_email_click_link_then_use_app(): void
    {
        $this->post('/register', $this->mhsPayload())->assertRedirect(route('verification.notice'));
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));

        $mail = $this->lastMail();
        $this->assertSame('budi.santoso@gmail.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Verifikasi email akun SYNLORA', $mail->getSubject());

        $link = $this->linkFromMail(url('/verify-email'));
        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('expires=', $link);

        $this->get($link)->assertRedirect('/dashboard');
        $this->assertTrue(User::first()->hasVerifiedEmail());
        $this->get('/dashboard')->assertOk();
    }

    public function test_verified_dosen_lands_on_monitoring_page(): void
    {
        $this->post('/register', $this->dosenPayload())->assertRedirect(route('verification.notice'));
        $this->get($this->linkFromMail(url('/verify-email')))->assertRedirect(route('dosen.monitor').'?verified=1');
        $this->get('/dosen')->assertOk();
    }

    public function test_verification_link_expired_tampered_or_for_other_user_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($expired)->assertForbidden();

        $valid = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($valid.'x')->assertForbidden();

        // Tautan milik orang lain tidak dapat memverifikasi akun ini.
        $this->actingAs($other)->get($valid)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_resend_verification_email_works_and_survives_smtp_failure(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmail::class);

        // SMTP bermasalah: bukan halaman error 500, melainkan pesan yang bisa dibaca.
        Notification::swap(new class extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function send($notifiables, $notification) { throw new \RuntimeException('SMTP down'); }
            public function sendNow($notifiables, $notification, array $channels = null) { throw new \RuntimeException('SMTP down'); }
        });
        $this->actingAs($user)->from('/verify-email')->post(route('verification.send'))->assertRedirect('/verify-email')->assertSessionHasErrors('email');
    }

    public function test_registration_still_creates_account_when_mail_server_fails(): void
    {
        Event::listen(Registered::class, fn () => throw new \RuntimeException('SMTP down'));

        $this->post('/register', $this->mhsPayload())->assertRedirect(route('verification.notice'))->assertSessionHasErrors('email');
        $this->assertNotNull(User::where('email', 'budi.santoso@gmail.com')->first());
        $this->assertAuthenticated();
    }

    public function test_verified_user_is_not_asked_to_verify_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/verify-email')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------ 3-6. Lupa password

    public function test_login_page_has_forgot_password_link_and_page_opens(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false)->assertSee('Lupa kata sandi?');
        $this->get('/forgot-password')->assertOk();
    }

    public function test_reset_link_is_sent_only_to_registered_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'terdaftar.user@gmail.com']);

        $this->post('/forgot-password', ['email' => 'tidakada.user@gmail.com'])->assertSessionHasErrors('email');
        Notification::assertNothingSent();

        $this->post('/forgot-password', ['email' => 'terdaftar.user@gmail.com'])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_full_reset_flow_token_is_hashed_single_use_and_new_password_works(): void
    {
        $user = User::factory()->create(['email' => 'terdaftar.user@gmail.com']);

        $this->post('/forgot-password', ['email' => 'terdaftar.user@gmail.com'])->assertSessionHas('status');

        $mail = $this->lastMail();
        $this->assertSame('Atur ulang kata sandi SYNLORA', $mail->getSubject());
        $link = $this->linkFromMail(url('/reset-password'));
        $this->assertStringContainsString('email=terdaftar.user%40gmail.com', $link);
        $token = basename(parse_url($link, PHP_URL_PATH));

        // Token di database hanya berupa hash, tidak sama dengan token di email.
        $stored = \DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));

        $this->get($link)->assertOk();

        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'KataBaru12345', 'password_confirmation' => 'KataBaru12345'];
        $this->post('/reset-password', $payload)->assertRedirect(route('login'))->assertSessionHas('status');

        // Sekali pakai: token terhapus dan tidak dapat dipakai lagi.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->post('/reset-password', ['password' => 'LainLagi12345', 'password_confirmation' => 'LainLagi12345'] + $payload)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('KataBaru12345', $user->fresh()->password));

        // Login dengan password baru berhasil, password lama tidak.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'KataBaru12345'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_token_expires_after_the_time_limit(): void
    {
        $user = User::factory()->create(['email' => 'terdaftar.user@gmail.com']);
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = basename(parse_url($this->linkFromMail(url('/reset-password')), PHP_URL_PATH));
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'KataBaru12345', 'password_confirmation' => 'KataBaru12345'];

        $this->travel(61)->minutes();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_rejects_wrong_token_wrong_email_and_weak_password(): void
    {
        $user = User::factory()->create(['email' => 'terdaftar.user@gmail.com']);
        $other = User::factory()->create(['email' => 'orang.lain@gmail.com']);
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = basename(parse_url($this->linkFromMail(url('/reset-password')), PHP_URL_PATH));
        $ok = ['password' => 'KataBaru12345', 'password_confirmation' => 'KataBaru12345'];

        $this->post('/reset-password', ['token' => 'salah', 'email' => $user->email] + $ok)->assertSessionHasErrors('email');
        // Token milik akun A tidak berlaku untuk akun B.
        $this->post('/reset-password', ['token' => $token, 'email' => $other->email] + $ok)->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'KataBaru12345', 'password_confirmation' => 'beda'])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'terdaftar.user@gmail.com']);
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => 'terdaftar.user@gmail.com']);
        }
        $this->post('/forgot-password', ['email' => 'terdaftar.user@gmail.com'])->assertStatus(429);
    }

    public function test_forgot_password_survives_smtp_failure(): void
    {
        User::factory()->create(['email' => 'terdaftar.user@gmail.com']);
        Notification::swap(new class extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function send($notifiables, $notification) { throw new \RuntimeException('SMTP down'); }
            public function sendNow($notifiables, $notification, array $channels = null) { throw new \RuntimeException('SMTP down'); }
        });

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'terdaftar.user@gmail.com'])
            ->assertRedirect('/forgot-password')->assertSessionHasErrors('email');
    }

    // ------------------------------------------------------------ akun lama & profil

    public function test_existing_unverified_accounts_are_grandfathered_by_migration(): void
    {
        $old = User::factory()->unverified()->create(['email' => 'lama@kampus.ac.id']);
        $newer = User::factory()->create(['email_verified_at' => now()->subDay()]);

        (require database_path('migrations/2026_11_24_000002_verify_existing_users_email.php'))->up();

        $this->assertNotNull($old->fresh()->email_verified_at);
        $this->assertEquals($newer->email_verified_at->timestamp, $newer->fresh()->email_verified_at->timestamp);
        $this->actingAs($old->fresh())->get('/dashboard')->assertOk();
    }

    public function test_legacy_non_gmail_account_can_still_edit_profile_without_changing_email(): void
    {
        $user = User::factory()->create(['email' => 'lama@kampus.ac.id']);

        $this->actingAs($user)->patch('/profile', ['name' => 'Nama Baru', 'email' => 'lama@kampus.ac.id', 'nim' => $user->nim, 'kelas' => 'B'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nama Baru', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changing_email_requires_gmail_and_triggers_new_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lama@kampus.ac.id']);
        $base = ['name' => $user->name, 'nim' => $user->nim, 'kelas' => 'B'];

        $this->actingAs($user)->patch('/profile', $base + ['email' => 'baru@yahoo.com'])->assertSessionHasErrors('email');
        $this->assertSame('lama@kampus.ac.id', $user->fresh()->email);

        $this->actingAs($user)->patch('/profile', $base + ['email' => 'baru.user@gmail.com'])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('baru.user@gmail.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        // Setelah ganti email, akun wajib verifikasi ulang sebelum memakai fitur lain.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_user_model_requires_email_verification_and_role_is_not_mass_assignable(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Auth\MustVerifyEmail::class, new User);
        $this->assertNotContains('role', (new User)->getFillable());
    }

    public function test_mail_credentials_are_read_from_env_not_hardcoded(): void
    {
        $mail = file_get_contents(config_path('mail.php'));
        $this->assertStringContainsString("env('MAIL_PASSWORD')", $mail);
        $this->assertStringContainsString("env('MAIL_USERNAME')", $mail);
        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=$/m', $example);
        $this->assertMatchesRegularExpression('/^MAIL_USERNAME=$/m', $example);
        $this->assertStringContainsString('MAIL_HOST=smtp.gmail.com', $example);
    }
}
