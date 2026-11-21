<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Material;
use App\Models\Room;
use App\Models\Submission;
use App\Models\StudySession;
use App\Models\User;
use App\Services\LecturerMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Skenario terburuk. Nomor "S##" mengacu pada daftar 60 skenario pada permintaan audit
 * (skenario beban, responsivitas, dan browser diuji lewat skrip terpisah; lihat LAPORAN).
 */
class WorstCaseTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');
        Storage::fake('local');
    }

    private function dosen(): User { return User::factory()->dosen()->create()->fresh(); }
    private function mhs(array $a = []): User { return User::factory()->create($a)->fresh(); }

    private function room(User $owner, array $extra = []): Room
    {
        $this->actingAs($owner)->post(route('rooms.store'), ['name' => 'Kelas Uji', 'target_minutes' => 25] + $extra)->assertRedirect();

        return Room::latest('id')->first();
    }

    private function join(User $u, Room $r): void
    {
        $r->members()->syncWithoutDetaching([$u->id => ['role' => 'member', 'focus_status' => 'ABU']]);
    }

    private function pdf(string $name = 'ok.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    private function ev(User $u, StudySession $s, string $type, array $extra = [])
    {
        return $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => $type] + $extra);
    }

    // ===== S01-S04 akun =====
    public function test_S02_brute_force_per_email_and_per_ip(): void
    {
        $u = $this->mhs(['email' => 'a@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'a@example.com', 'password' => 'salah']);
        }
        $this->post('/login', ['email' => 'a@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        config(['synlora.login_ip_max_failures' => 25]);
        RateLimiter::clear('login-ip|127.0.0.1');
        for ($i = 0; $i < 25; $i++) {
            $this->post('/login', ['email' => "x{$i}@example.com", 'password' => 'salah']);
        }
        $this->post('/login', ['email' => 'a@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_S03_S55_role_cannot_be_forced_by_extra_fields(): void
    {
        $this->post('/register', ['name' => 'X', 'role' => 'admin', 'email' => 'x@example.com', 'nim' => '2203999999', 'kelas' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('role');
        $this->post('/register', ['name' => 'X', 'role' => 'mahasiswa', 'is_admin' => 1, 'email' => 'x@example.com', 'nim' => '2203999999', 'kelas' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123']);
        $this->assertSame('mahasiswa', User::where('email', 'x@example.com')->value('role'));
    }

    public function test_S04_guest_and_wrong_role_blocked_everywhere(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $s = $this->mhs(); $this->join($s, $room);
        foreach (['dashboard', 'pomodoro.index', 'rooms.create', 'sessions.index'] as $r) {
            auth()->logout();
            $this->get(route($r))->assertRedirect(route('login'));
        }
        auth()->logout();
        $this->getJson(route('rooms.getActivities', $room))->assertStatus(401);
        $this->actingAs($s)->get(route('dosen.monitor'))->assertForbidden();
    }

    public function test_S50_expired_session_redirects_html_and_401_json(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson('/dashboard')->assertStatus(401);
        $u = $this->mhs(['email' => 'back@example.com']);
        $this->get('/rooms/join');
        $this->post('/login', ['email' => 'back@example.com', 'password' => 'password'])->assertRedirect('/rooms/join');
    }

    // ===== S05-S08 room =====
    public function test_S05_room_code_guessing_is_locked_out(): void
    {
        $u = $this->mhs();
        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => 'ZZZZ0'.$i])->assertSessionHasErrors('code');
        }
        $r = $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => 'ZZZZ99']);
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
    }

    public function test_S06_duplicate_member_not_possible(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs();
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => $room->code]);
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => strtolower($room->code)]);
        $this->assertSame(1, $room->members()->where('users.id', $u->id)->count());
    }

    public function test_S07_room_capacity_enforced_and_supports_50(): void
    {
        $d = $this->dosen(); $room = $this->room($d, ['max_members' => 3]);
        foreach (range(1, 2) as $i) { $this->actingAs($this->mhs())->post(route('rooms.joinRoom'), ['code' => $room->code]); }
        $late = $this->mhs();
        $this->actingAs($late)->post(route('rooms.joinRoom'), ['code' => $room->code])->assertSessionHasErrors('code');
        $this->assertStringContainsString('penuh', session('errors')->first('code'));
        $this->assertFalse($room->fresh()->hasMember($late));

        $big = $this->room($d, ['max_members' => 60]);
        foreach (range(1, 50) as $i) { $this->join($this->mhs(), $big); }
        $this->assertSame(51, $big->members()->count());
        $this->actingAs($this->mhs())->post(route('rooms.joinRoom'), ['code' => $big->code])->assertRedirect();
        $this->assertSame(52, $big->members()->count());
    }

    public function test_S08_S49_room_delete_needs_typed_name_and_keeps_history(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);
        $sess = StudySession::begin($u, $room, 25, 5);
        $logs = ActivityLog::where('user_id', $u->id)->count();
        $this->assertGreaterThan(0, $logs);

        $this->actingAs($d)->delete(route('rooms.destroy', $room), ['confirm_name' => 'salah'])->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertStringContainsString('tidak cocok', session('errors')->first('confirm_name'));
        $this->actingAs($u)->delete(route('rooms.destroy', $room), ['confirm_name' => $room->name])->assertForbidden();

        $this->actingAs($d)->delete(route('rooms.destroy', $room), ['confirm_name' => '  kelas UJI '])->assertRedirect();
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('study_sessions', ['id' => $sess->id]);              // riwayat belajar utuh
        $this->assertGreaterThanOrEqual($logs, ActivityLog::where('user_id', $u->id)->count()); // log aktivitas tidak berkurang
    }

    public function test_S30_dosen_cannot_manage_or_view_other_dosens_room(): void
    {
        $d1 = $this->dosen(); $d2 = $this->dosen(); $room = $this->room($d1);
        $this->actingAs($d2)->patch(route('rooms.update', $room), ['name' => 'Curi', 'target_minutes' => 25, 'max_members' => 10])->assertForbidden();
        $this->actingAs($d2)->post(route('rooms.regenerate', $room))->assertForbidden();
        $this->actingAs($d2)->delete(route('rooms.destroy', $room), ['confirm_name' => $room->name])->assertForbidden();
        $this->actingAs($d2)->get(route('rooms.show', $room))->assertRedirect(route('rooms.join'));   // bukan anggota: tidak bisa membuka isi room
        $this->actingAs($d2)->postJson(route('materials.store', $room), ['title' => 't', 'file' => $this->pdf()])->assertForbidden();
    }

    public function test_room_settings_update_and_code_regeneration(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $old = $room->code;
        $this->join($this->mhs(), $room); $this->join($this->mhs(), $room);
        $this->actingAs($d)->patch(route('rooms.update', $room), ['name' => 'Baru', 'target_minutes' => 30, 'max_members' => 1])->assertSessionHasErrors('max_members');
        $this->actingAs($d)->patch(route('rooms.update', $room), ['name' => 'Baru', 'target_minutes' => 30, 'max_members' => 40])->assertSessionHasNoErrors();
        $this->assertSame(40, $room->fresh()->max_members);
        $this->actingAs($d)->post(route('rooms.regenerate', $room))->assertRedirect();
        $this->assertNotSame($old, $room->fresh()->code);
        $this->assertSame(3, $room->fresh()->members()->count());
    }

    // ===== S09-S11 fokus =====
    public function test_S09_S10_S11_server_derives_status_and_escalates(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);
        $s = StudySession::begin($u, $room, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();

        // klien memalsukan HIJAU bersama "tab ditinggalkan": server menetapkan KUNING
        $this->ev($u, $s, 'TAB_SWITCH_OUT', ['status' => 'HIJAU', 'source' => 'face'])->assertOk();
        $this->assertSame(['KUNING', 'browser'], [$s->fresh()->live_status, $s->fresh()->live_source]);

        // klaim "ditinggalkan lama" tanpa waktu cukup: tidak jadi MERAH
        $this->ev($u, $s, 'TAB_AWAY_LONG', ['status' => 'MERAH'])->assertOk();
        $this->assertSame('KUNING', $s->fresh()->live_status);

        // klien diam (diblokir), server tetap menaikkan ke MERAH setelah batas
        $this->travel(12)->seconds();
        $this->assertSame('MERAH', $s->fresh()->displayFocus()[0]);

        // jendela blur = KUNING, kembali = HIJAU
        $this->ev($u, $s, 'TAB_SWITCH_IN')->assertOk();
        $this->assertSame('HIJAU', $s->fresh()->live_status);
        $this->ev($u, $s, 'WINDOW_BLUR')->assertOk();
        $this->assertSame('KUNING', $s->fresh()->live_status);
    }

    public function test_S12_S13_S14_S16_camera_problems_do_not_break_monitoring(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);
        $s = StudySession::begin($u, $room, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();

        foreach (['CAMERA_UNAVAILABLE' => 'unavailable', 'CAMERA_DENIED' => 'denied', 'CAMERA_ERROR' => 'error', 'MODEL_LOAD_FAILED' => 'model_failed'] as $event => $state) {
            $this->ev($u, $s, $event, ['status' => 'MERAH'])->assertOk();       // klien tidak bisa memakai kamera untuk mengubah status
            $this->assertSame($state, $s->fresh()->camera_state);
            $this->assertSame('HIJAU', $s->fresh()->live_status);               // fallback tanpa kamera: status tab tetap
        }
        $row = $this->actingAs($d)->getJson(route('dosen.data'))->json('students.0');
        $this->assertSame('CAMERA_OFF', $row['camera']);
        $this->assertSame('HIJAU', $row['status']);
        $this->ev($u, $s, 'CAMERA_ON')->assertOk();
        $this->assertSame('on', $s->fresh()->camera_state);
    }

    public function test_S15_phone_orientation_never_changes_status(): void
    {
        $u = $this->mhs(); $s = StudySession::begin($u, null, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();
        $this->ev($u, $s, 'ORIENTATION_CHANGE', ['status' => 'MERAH', 'source' => 'orientation'])->assertOk();
        $this->assertSame('HIJAU', $s->fresh()->live_status);
        $this->assertSame('HIJAU', $s->fresh()->displayFocus()[0]);
    }

    public function test_S17_S18_missing_heartbeat_is_disconnected_and_recovers(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);
        $s = StudySession::begin($u, $room, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();

        $this->travel(90)->seconds();
        $this->assertSame('DISCONNECTED', $s->fresh()->displayFocus()[0]);
        $this->assertSame('DISCONNECTED', $this->actingAs($d)->getJson(route('dosen.data'))->json('students.0.status'));

        $this->actingAs($u)->postJson(route('sessions.heartbeat', $s), ['hidden' => false])->assertOk();
        $this->assertSame('HIJAU', $s->fresh()->displayFocus()[0]);
        $this->assertDatabaseHas('activity_logs', ['session_id' => $s->id, 'event_type' => 'HEARTBEAT_LOST']);
        $this->assertDatabaseHas('focus_logs', ['session_id' => $s->id, 'status' => 'DISCONNECTED']);
    }

    public function test_heartbeat_detects_missed_tab_events(): void
    {
        $u = $this->mhs(); $s = StudySession::begin($u, null, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();
        $this->actingAs($u)->postJson(route('sessions.heartbeat', $s), ['hidden' => true])->assertOk();
        $this->assertSame('KUNING', $s->fresh()->live_status);
        $this->actingAs($u)->postJson(route('sessions.heartbeat', $s), ['hidden' => false])->assertOk();
        $this->assertSame('HIJAU', $s->fresh()->live_status);
    }

    public function test_S19_S51_S57_events_are_idempotent_and_backdated(): void
    {
        $u = $this->mhs(); $s = StudySession::begin($u, null, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();
        $this->travel(30)->seconds();
        $a = $this->ev($u, $s, 'TAB_SWITCH_OUT', ['client_id' => 'abc123', 'delay_seconds' => 20])->assertOk();
        $b = $this->ev($u, $s, 'TAB_SWITCH_OUT', ['client_id' => 'abc123', 'delay_seconds' => 20])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, ActivityLog::where('session_id', $s->id)->where('event_type', 'TAB_SWITCH_OUT')->count());
        $log = ActivityLog::where('session_id', $s->id)->where('event_type', 'TAB_SWITCH_OUT')->first();
        $this->assertEqualsWithDelta(now()->subSeconds(20)->getTimestamp(), $log->occurred_at->getTimestamp(), 2);
        // event tertunda tidak boleh mendahului sesi
        $this->ev($u, $s, 'WINDOW_FOCUS', ['delay_seconds' => 600])->assertOk();
        $this->assertGreaterThanOrEqual($s->fresh()->started_at->getTimestamp(), ActivityLog::where('session_id', $s->id)->latest('id')->first()->occurred_at->getTimestamp());
    }

    public function test_S20_second_device_replaces_first_session(): void
    {
        $u = $this->mhs();
        $first = StudySession::begin($u, null, 25, 5);
        $second = StudySession::begin($u, null, 25, 5);
        $this->assertSame('ENDED', $first->fresh()->status);
        $this->assertSame(1, StudySession::where('user_id', $u->id)->open()->count());
        $this->ev($u, $first->fresh(), 'MONITOR_START', ['status' => 'HIJAU'])->assertStatus(409);      // perangkat lama tidak bisa mengirim status
        $this->actingAs($u)->getJson(route('sessions.state', $first))->assertOk();
    }

    // ===== S21-S24 Pomodoro =====
    public function test_S21_S22_S23_S53_timer_follows_server_time_and_never_doubles(): void
    {
        $u = $this->mhs();
        $r1 = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertSuccessful()->json();
        $r2 = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertSuccessful()->json();
        $this->assertSame(1, StudySession::where('user_id', $u->id)->open()->count());

        $s = StudySession::where('user_id', $u->id)->open()->first();
        $this->travel(5)->minutes();   // "refresh" 5 menit kemudian
        $state = $this->actingAs($u)->getJson(route('sessions.state', $s))->assertOk()->json();
        $remaining = $state['session']['remaining_seconds'] ?? $state['remaining_seconds'] ?? null;
        $this->assertEqualsWithDelta(20 * 60, $remaining, 3);
        $this->assertArrayHasKey('server_time', $state);

        $this->travel(10)->minutes();  // refresh lagi (detak menjaga sesi tetap hidup)
        $this->actingAs($u)->getJson(route('sessions.state', $s))->assertOk();
        $this->travel(12)->minutes();  // total 27 menit: fase fokus habis -> server pindah ke istirahat tanpa klien
        $state = $this->actingAs($u)->getJson(route('sessions.state', $s))->json();
        $this->assertSame('BREAK', $state['session']['phase'] ?? $state['phase']);
    }

    public function test_S24_finish_is_idempotent_and_closed_session_rejects_events(): void
    {
        $u = $this->mhs(); $s = StudySession::begin($u, null, 25, 5);
        $this->actingAs($u)->postJson(route('sessions.finish', $s))->assertSuccessful();
        $this->actingAs($u)->postJson(route('sessions.finish', $s))->assertSuccessful();
        $this->assertSame('ENDED', $s->fresh()->status);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertStatus(409);
    }

    // ===== S25-S29, S51, S54 berkas & tugas =====
    public function test_S25_S51_failed_or_oversized_upload_leaves_no_trace(): void
    {
        $d = $this->dosen(); $room = $this->room($d);
        $big = UploadedFile::fake()->createWithContent('besar.pdf', self::PDF)->mimeType('application/pdf');
        config(['synlora.room_storage_mb' => 0]);
        $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T', 'file' => $this->pdf()])->assertSessionHasErrors('file');
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        config(['synlora.room_storage_mb' => 500]);
        $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T'])->assertSessionHasErrors('file');       // tidak ada file (upload terputus)
        $broken = new UploadedFile(__FILE__, 'x.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
        $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T', 'file' => $broken])->assertSessionHasErrors('file');
        $this->assertSame(0, Material::count());
    }

    public function test_S26_dangerous_files_rejected(): void
    {
        $d = $this->dosen(); $room = $this->room($d);
        // File ASLI di disk (bukan fake) agar pemeriksaan isi file (finfo) benar-benar berjalan.
        $real = function (string $name, string $content) {
            $p = tempnam(sys_get_temp_dir(), 'syn'); file_put_contents($p, $content);

            return new UploadedFile($p, $name, null, null, true);
        };
        $bad = [
            $real('shell.php', '<?php system($_GET["c"]);'),
            $real('shell.pdf', '<?php system($_GET["c"]);'),      // PHP menyamar sebagai PDF
            $real('x.html', '<script>alert(1)</script>'),
            $real('x.svg', '<svg onload="alert(1)"/>'),
            $real('x.exe', "MZ\x90\x00\x03\x00"),
            $real('x.jpg', '<?php echo 1;'),                       // PHP menyamar sebagai JPG
        ];
        foreach ($bad as $f) {
            $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T', 'file' => $f])->assertSessionHasErrors('file');
        }
        $this->assertSame(0, Material::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T', 'file' => $this->pdf('../../etc/passwd.pdf')])->assertSessionHasNoErrors();
        $m = Material::first();
        $this->assertStringNotContainsString('..', $m->original_name);
        $this->assertStringNotContainsString('/', $m->original_name);
        $this->assertStringStartsWith("rooms/{$room->id}/materials/", $m->file_path);
    }

    public function test_S27_S54_private_files_only_for_members_and_not_by_direct_url(): void
    {
        $d = $this->dosen(); $room = $this->room($d);
        $in = $this->mhs(); $out = $this->mhs(); $this->join($in, $room);
        $this->actingAs($d)->post(route('materials.store', $room), ['title' => 'T', 'file' => $this->pdf()]);
        $m = Material::first();

        $this->actingAs($in)->get(route('materials.download', [$room, $m]))->assertOk();
        $this->actingAs($out)->get(route('materials.download', [$room, $m]))->assertForbidden();
        auth()->logout();
        $this->get(route('materials.download', [$room, $m]))->assertRedirect(route('login'));
        foreach (['/storage/'.$m->file_path, '/'.$m->file_path, '/storage/app/'.$m->file_path] as $url) {
            $this->assertContains($this->get($url)->status(), [404, 405], "URL langsung tidak boleh menyajikan berkas: {$url}");
        }
    }

    public function test_S55_ids_from_another_room_do_not_work(): void
    {
        $d = $this->dosen(); $roomA = $this->room($d); $roomB = $this->room($d);
        $mat = Material::create(['room_id' => $roomB->id, 'user_id' => $d->id, 'title' => 'B', 'file_path' => 'rooms/x/y.pdf', 'original_name' => 'y.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]);
        $this->actingAs($d)->get(route('materials.download', [$roomA, $mat]))->assertNotFound();
        $this->actingAs($d)->delete(route('materials.destroy', [$roomA, $mat]))->assertNotFound();
        $this->assertDatabaseHas('materials', ['id' => $mat->id]);
    }

    public function test_S28_S29_S52_deadline_closed_late_and_graded_lock(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);

        $mk = fn (bool $late) => Assignment::create(['room_id' => $room->id, 'user_id' => $d->id, 'title' => 'T', 'due_at' => now()->addHour(), 'allow_late' => $late]);
        $closed = $mk(false); $open = $mk(true);
        $this->travel(2)->hours();

        $this->actingAs($u)->post(route('assignments.submit', [$room, $closed]), ['notes' => 'jawab'])->assertSessionHasErrors('file');
        $this->assertSame(0, Submission::where('assignment_id', $closed->id)->count());

        $this->actingAs($u)->post(route('assignments.submit', [$room, $open]), ['notes' => 'jawab', 'file' => $this->pdf()])->assertSessionHasNoErrors();
        $sub = Submission::where('assignment_id', $open->id)->first();
        $this->assertTrue((bool) $sub->is_late);

        $this->actingAs($d)->post(route('assignments.grade', [$room, $open, $sub]), ['score' => 80, 'feedback' => 'bagus'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('assignments.submit', [$room, $open]), ['notes' => 'ubah diam-diam'])->assertSessionHasErrors('file');
        $this->assertSame('jawab', $sub->fresh()->notes);
        $this->assertSame(80, $sub->fresh()->score);
        $this->actingAs($u)->post(route('assignments.grade', [$room, $open, $sub]), ['score' => 100])->assertForbidden();
    }

    // ===== S31, S39, S56 dashboard =====
    public function test_S39_dashboard_cache_is_invalidated_by_new_events(): void
    {
        $d = $this->dosen(); $room = $this->room($d); $u = $this->mhs(); $this->join($u, $room);
        $s = StudySession::begin($u, $room, 25, 5);
        $this->ev($u, $s, 'MONITOR_START', ['status' => 'HIJAU'])->assertOk();
        $this->assertSame('HIJAU', $this->actingAs($d)->getJson(route('dosen.data'))->json('students.0.status'));
        $this->ev($u, $s, 'WINDOW_BLUR')->assertOk();   // tanpa menunggu kedaluwarsa cache 3 detik
        $this->assertSame('KUNING', $this->actingAs($d)->getJson(route('dosen.data'))->json('students.0.status'));
    }

    public function test_S56_dashboard_polling_is_rate_limited(): void
    {
        $d = $this->dosen(); $this->room($d);
        $codes = [];
        for ($i = 0; $i < 45; $i++) { $codes[] = $this->actingAs($d)->getJson(route('dosen.data'))->status(); }
        $this->assertContains(429, $codes);
        $this->assertSame(200, $codes[0]);
    }

    public function test_dashboard_numbers_match_database_and_cover_attendance(): void
    {
        $d = $this->dosen(); $room = $this->room($d);
        $a = $this->mhs(); $b = $this->mhs(); $this->join($a, $room); $this->join($b, $room);
        $s = StudySession::begin($a, $room, 25, 5); $s->forceFill(['total_effective_seconds' => 1800, 'completed_pomodoros' => 1])->saveQuietly();
        $sum = $this->actingAs($d)->getJson(route('dosen.data'))->json('summary');
        $this->assertSame(2, $sum['total']);
        $this->assertSame(1, $sum['present_today']);
        $this->assertSame(1, $sum['absent_today']);
    }

    // ===== S49 pesan error dapat dipahami =====
    public function test_S49_error_messages_are_clear_indonesian(): void
    {
        $u = $this->mhs();
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => 'ABC'])->assertSessionHasErrors('code');
        $this->assertStringContainsString('6 karakter', session('errors')->first('code'));
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => 'QQQQQQ']);
        $this->assertStringContainsString('tidak ditemukan', session('errors')->first('code'));
    }
}
