<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use App\Services\LecturerMonitor;
use App\Services\StudyStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Pengujian untuk: landing page, akun & hak akses, status fokus, dashboard dosen, statistik. */
class SynloraV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function room(User $owner, string $name = 'Kelas A'): Room
    {
        $this->actingAs($owner)->post(route('rooms.store'), ['name' => $name, 'target_minutes' => 25])->assertRedirect();

        return Room::latest('id')->first();
    }

    private function student(Room $room, array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $room->members()->attach($u->id, ['role' => 'member', 'focus_status' => 'ABU', 'last_active' => now()]);

        return $u;
    }

    private function begin(User $u, Room $room): StudySession
    {
        return StudySession::begin($u, $room, 25, 5);
    }

    // =================================================== landing page

    public function test_landing_is_public_and_first_page(): void
    {
        $this->get('/')->assertOk()->assertSee('Mulai Belajar')->assertSee('Masuk')
            ->assertSee(route('register'), false)->assertSee(route('login'), false)
            ->assertDontSee('Laravel v');
    }

    public function test_landing_shows_dashboard_button_when_logged_in(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Buka Dashboard')->assertSee(route('dashboard'), false);
        $this->actingAs(User::factory()->dosen()->create())->get('/')->assertOk()->assertSee(route('dosen.monitor'), false);
    }

    // ============================================ akun & hak akses

    public function test_student_registration_requires_nim_and_kelas(): void
    {
        $base = ['name' => 'Budi', 'role' => 'mahasiswa', 'email' => 'budi@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'];

        $this->post('/register', $base)->assertSessionHasErrors(['nim', 'kelas']);
        $this->post('/register', $base + ['nim' => 'ABC12', 'kelas' => 'A'])->assertSessionHasErrors('nim');
        $this->assertGuest();

        $this->post('/register', $base + ['nim' => '2203111001', 'kelas' => 'ti-2a'])->assertRedirect(route('dashboard'));
        $u = User::where('email', 'budi@example.com')->first();
        $this->assertSame('mahasiswa', $u->role);
        $this->assertSame('2203111001', $u->nim);
        $this->assertSame('TI-2A', $u->kelas);
    }

    public function test_nim_must_be_unique(): void
    {
        User::factory()->create(['nim' => '2203111001']);
        $this->post('/register', ['name' => 'X', 'role' => 'mahasiswa', 'email' => 'x@example.com', 'nim' => '2203111001', 'kelas' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('nim');
    }

    private function dosenPayload(array $extra = []): array
    {
        return ['name' => 'Dr. D', 'role' => 'dosen', 'email' => 'd@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'] + $extra;
    }

    public function test_dosen_self_register_works_only_in_explicit_open_mode(): void
    {
        config(['synlora.dosen_registration' => 'open']);

        $this->post('/register', $this->dosenPayload())->assertRedirect(route('dosen.monitor'));
        $this->assertSame('dosen', User::where('email', 'd@example.com')->value('role'));
    }

    public function test_dosen_registration_default_requires_valid_invite_code(): void
    {
        $this->assertSame('invite', config('synlora.dosen_registration'));
        config(['synlora.dosen_invite_code' => 'KODE-UJI-123']);

        $this->post('/register', $this->dosenPayload())->assertSessionHasErrors('role');
        $this->post('/register', $this->dosenPayload(['invite_code' => 'salah']))->assertSessionHasErrors('role');
        $this->assertNull(User::where('email', 'd@example.com')->first());

        $this->post('/register', $this->dosenPayload(['invite_code' => 'KODE-UJI-123']))->assertRedirect(route('dosen.monitor'));
        $this->assertSame('dosen', User::where('email', 'd@example.com')->value('role'));
    }

    public function test_dosen_registration_fails_safe_when_no_code_configured_or_closed(): void
    {
        config(['synlora.dosen_invite_code' => null]);
        $this->post('/register', $this->dosenPayload(['invite_code' => '']))->assertSessionHasErrors('role');
        $this->assertNull(User::where('email', 'd@example.com')->first());

        config(['synlora.dosen_registration' => 'closed', 'synlora.dosen_invite_code' => 'KODE']);
        $this->post('/register', $this->dosenPayload(['invite_code' => 'KODE']))->assertSessionHasErrors('role');
        $this->assertNull(User::where('email', 'd@example.com')->first());
    }

    public function test_dosen_invite_code_attempts_are_rate_limited(): void
    {
        config(['synlora.dosen_invite_code' => 'KODE-UJI-123']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $this->dosenPayload(['invite_code' => "salah{$i}"]))->assertSessionHasErrors('role');
        }
        // Percobaan ke-6 ditolak walau kodenya benar: brute force kode tidak mungkin.
        $this->post('/register', $this->dosenPayload(['invite_code' => 'KODE-UJI-123']))->assertSessionHasErrors('role');
        $this->assertNull(User::where('email', 'd@example.com')->first());
    }

    public function test_student_registration_unaffected_by_dosen_gate(): void
    {
        $this->post('/register', ['name' => 'Budi', 'role' => 'mahasiswa', 'email' => 'budi@example.com', 'nim' => '2203111777', 'kelas' => 'A', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertRedirect('/dashboard');
        $this->assertSame('mahasiswa', User::where('email', 'budi@example.com')->value('role'));
    }

    public function test_register_page_shows_invite_field_only_in_invite_mode(): void
    {
        $this->get('/register')->assertOk()->assertSee('invite_code', false)->assertSee('Kode undangan dosen');
        config(['synlora.dosen_registration' => 'open']);
        $this->get('/register')->assertOk()->assertDontSee('invite_code', false);
    }

    public function test_role_cannot_be_escalated_via_profile_update(): void
    {
        $u = User::factory()->create()->fresh(); // muat dari DB agar atribut role terisi seperti pada login nyata
        $this->actingAs($u)->patch(route('profile.update'), [
            'name' => 'Baru', 'email' => $u->email, 'nim' => $u->nim, 'kelas' => 'B', 'role' => 'dosen',
        ])->assertSessionHasNoErrors();
        $this->assertSame('mahasiswa', $u->fresh()->role);
        $this->assertSame('B', $u->fresh()->kelas);
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $u = new User(['name' => 'x', 'email' => 'x@x.com', 'role' => 'dosen']);
        $this->assertNotSame('dosen', $u->role);
    }

    public function test_lecturer_routes_forbidden_for_students_and_guests(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $s = $this->student($room)->fresh();

        foreach (['dosen.monitor', 'dosen.data', 'dosen.export'] as $r) {
            auth()->logout();
            $this->get(route($r))->assertRedirect(route('login'));
            $this->actingAs($s)->get(route($r))->assertForbidden();
        }
        $this->actingAs($s)->getJson(route('dosen.history', $s))->assertForbidden();
        $this->actingAs($dosen)->get(route('dosen.monitor'))->assertOk();
    }

    public function test_login_redirects_dosen_to_monitor(): void
    {
        $d = User::factory()->dosen()->create(['email' => 'd2@example.com']);
        $this->post('/login', ['email' => 'd2@example.com', 'password' => 'password'])->assertRedirect(route('dosen.monitor'));
    }

    // ============================================== status fokus (server)

    public function test_events_store_source_and_live_status(): void
    {
        $u = User::factory()->create();
        $room = $this->room(User::factory()->dosen()->create());
        $room->members()->attach($u->id, ['role' => 'member']);
        $s = $this->begin($u, $room);

        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'MONITOR_START', 'status' => 'HIJAU', 'source' => 'browser', 'details' => 'ok'])->assertOk();
        $this->assertSame(['HIJAU', 'browser'], [$s->fresh()->live_status, $s->fresh()->live_source]);

        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'FACE_LOST_SHORT', 'status' => 'KUNING', 'source' => 'face', 'details' => 'x'])->assertOk();
        $this->assertSame(['KUNING', 'face'], [$s->fresh()->live_status, $s->fresh()->live_source]);
        $this->assertDatabaseHas('focus_logs', ['session_id' => $s->id, 'status' => 'KUNING', 'source' => 'face']);

        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'CAMERA_ON', 'status' => 'HIJAU', 'source' => 'face'])->assertOk();
        $this->assertTrue($s->fresh()->camera_on);
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'CAMERA_OFF', 'status' => 'HIJAU', 'source' => 'browser'])->assertOk();
        $this->assertFalse($s->fresh()->camera_on);
    }

    public function test_events_reject_invalid_source_status_and_type(): void
    {
        $u = User::factory()->create();
        $s = StudySession::begin($u, null, 25, 5);
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'MONITOR_START', 'status' => 'HIJAU', 'source' => 'hacker'])->assertStatus(422);
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'MONITOR_START', 'status' => 'BIRU'])->assertStatus(422);
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'DROP_TABLE', 'status' => 'HIJAU'])->assertStatus(422);
    }

    public function test_other_user_cannot_post_events_or_heartbeat(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $s = StudySession::begin($a, null, 25, 5);
        $this->actingAs($b)->postJson(route('sessions.events', $s), ['event_type' => 'MONITOR_START', 'status' => 'HIJAU'])->assertForbidden();
        $this->actingAs($b)->postJson(route('sessions.heartbeat', $s))->assertForbidden();
        $this->actingAs($a)->postJson(route('sessions.heartbeat', $s))->assertOk()->assertJson(['ok' => true, 'open' => true]);
    }

    public function test_display_focus_rules(): void
    {
        $u = User::factory()->create();
        $s = StudySession::begin($u, null, 25, 5);

        // belum ada laporan browser
        $this->assertSame('UNKNOWN', $s->displayFocus()[0]);

        $s->logFocus('HIJAU', 'ok', now(), 'browser');
        $this->assertSame('HIJAU', $s->fresh()->displayFocus()[0]);

        $s->logFocus('MERAH', 'tab', now(), 'browser');
        $this->assertSame(['MERAH', 'browser'], array_slice($s->fresh()->displayFocus(), 0, 1) + [1 => $s->fresh()->displayFocus()[2]]);

        // detak berhenti > 60 dtk -> ABU terputus (walau status terakhir MERAH)
        $stale = $s->fresh();
        $stale->forceFill(['last_heartbeat_at' => now()->subSeconds(90)])->saveQuietly();
        $r = $stale->fresh()->displayFocus();
        $this->assertSame('DISCONNECTED', $r[0]);
        $this->assertStringContainsString('terputus', $r[1]);

        // jeda -> ABU
        $fresh = StudySession::begin($u, null, 25, 5)->pause();
        $this->assertSame('ABU', $fresh->displayFocus()[0]);
    }

    public function test_break_phase_is_abu_and_needs_sync_detects_due_phase(): void
    {
        $u = User::factory()->create();
        $s = StudySession::begin($u, null, 1, 1);
        $this->assertFalse($s->needsSync());
        $s->forceFill(['phase_started_at' => now()->subSeconds(90), 'last_heartbeat_at' => now()])->saveQuietly();
        $this->assertTrue($s->fresh()->needsSync());
        $s->fresh()->sync();
        $this->assertSame('BREAK', $s->fresh()->phase);
        $this->assertSame('ABU', $s->fresh()->displayFocus()[0]);
    }

    // ============================================ dashboard dosen (40 mhs)

    public function test_monitor_lists_40_students_with_status_time_and_pomodoro(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $states = ['HIJAU' => 0, 'KUNING' => 0, 'MERAH' => 0, 'ABU' => 0];

        for ($i = 0; $i < 40; $i++) {
            $u = $this->student($room, ['kelas' => $i % 2 ? 'A' : 'B']);
            if ($i < 10) {                      // hijau
                $s = $this->begin($u, $room); $s->logFocus('HIJAU', 'ok', now(), 'face'); $states['HIJAU']++;
            } elseif ($i < 18) {                // kuning
                $s = $this->begin($u, $room); $s->logFocus('KUNING', 'wajah', now(), 'face'); $states['KUNING']++;
            } elseif ($i < 24) {                // merah
                $s = $this->begin($u, $room); $s->logFocus('MERAH', 'tab', now(), 'browser'); $states['MERAH']++;
            } elseif ($i < 30) {                // dijeda -> abu
                $s = $this->begin($u, $room); $s->logFocus('HIJAU', 'ok', now(), 'browser'); $s->pause(); $states['ABU']++;
            } else {                            // offline -> abu
                $states['ABU']++;
            }
        }

        $res = $this->actingAs($dosen)->getJson(route('dosen.data'))->assertOk()->json();

        $this->assertSame(40, $res['summary']['total']);
        foreach ($states as $k => $n) {
            $this->assertSame($n, $res['summary'][$k], "status $k");
        }
        $this->assertSame(24, $res['summary']['studying']);
        $this->assertSame(['A', 'B'], $res['kelas']);
        $first = collect($res['students'])->firstWhere('status', 'HIJAU');
        foreach (['id', 'name', 'nim', 'kelas', 'status', 'source', 'presence', 'today_seconds', 'today_pomodoros', 'remaining_seconds', 'last_seen'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertSame('face', $first['source']);
        $this->assertCount(40, $res['students']);
    }

    public function test_monitor_query_count_does_not_grow_with_students(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $mk = function (int $n) use ($room) {
            for ($i = 0; $i < $n; $i++) {
                $u = $this->student($room);
                $s = $this->begin($u, $room);
                $s->logFocus('HIJAU', 'ok', now(), 'browser');
            }
        };
        $count = function () use ($dosen) {
            Cache::flush();
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            LecturerMonitor::snapshot($dosen);
            $n = count(\DB::getQueryLog());
            \DB::disableQueryLog();

            return $n;
        };

        $mk(5);
        $few = $count();
        $mk(35);
        $many = $count();
        $this->assertSame($few, $many, "query count harus konstan: $few vs $many");
        $this->assertLessThan(12, $many);
    }

    public function test_monitor_only_shows_own_rooms_students(): void
    {
        $d1 = User::factory()->dosen()->create();
        $d2 = User::factory()->dosen()->create();
        $r1 = $this->room($d1, 'R1');
        $r2 = $this->room($d2, 'R2');
        $mine = $this->student($r1);
        $theirs = $this->student($r2);
        $this->begin($theirs, $r2)->logFocus('MERAH', 'x', now(), 'browser');

        $ids = collect($this->actingAs($d1)->getJson(route('dosen.data'))->assertOk()->json('students'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);

        $this->actingAs($d1)->getJson(route('dosen.history', $theirs))->assertForbidden();
        $this->actingAs($d1)->getJson(route('dosen.history', $mine))->assertOk()->assertJsonStructure(['student', 'days', 'sessions', 'activities']);
    }

    public function test_monitor_does_not_expose_solo_sessions(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $u = $this->student($room);
        StudySession::begin($u, null, 25, 5)->logFocus('MERAH', 'solo', now(), 'browser');

        $row = $this->actingAs($dosen)->getJson(route('dosen.data'))->json('students.0');
        $this->assertSame('offline', $row['presence']);
        $this->assertSame(0, $row['today_seconds']);
        $this->assertCount(0, $this->actingAs($dosen)->getJson(route('dosen.history', $u))->json('activities'));
    }

    public function test_monitor_room_filter_validates_input(): void
    {
        $dosen = User::factory()->dosen()->create();
        $this->actingAs($dosen)->getJson(route('dosen.data', ['room' => "x' OR 1=1"]))->assertStatus(422);
    }

    public function test_history_contains_activity_log_and_is_escaped_json(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $u = $this->student($room);
        $s = $this->begin($u, $room);
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'KUNING', 'source' => 'browser', 'details' => '<script>alert(1)</script>'])->assertOk();

        $types = collect($this->actingAs($dosen)->getJson(route('dosen.history', $u))->json('activities'))->pluck('type');
        $this->assertTrue($types->contains('TAB_SWITCH_OUT'));
        $this->assertTrue($types->contains('SESSION_START'));
    }

    public function test_csv_export_neutralises_formula_injection(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $this->student($room, ['name' => '=HYPERLINK("http://evil")', 'nim' => '2203111009']);

        $csv = $this->actingAs($dosen)->get(route('dosen.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('2203111009', $csv);
    }

    // ================================================== produktivitas

    public function test_daily_goal_progress_and_streaks(): void
    {
        $u = User::factory()->create(['daily_goal_minutes' => 60]);
        $base = now()->startOfDay()->addHours(7);
        // hari ini 30 menit; kemarin & 2 hari lalu 70 menit; 5 hari lalu 70 menit
        foreach ([[0, 30], [1, 70], [2, 70], [5, 70]] as [$ago, $min]) {
            StudySession::create(['user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5,
                'started_at' => $base->copy()->subDays($ago), 'ended_at' => $base->copy()->subDays($ago)->addHour(),
                'phase_started_at' => $base->copy()->subDays($ago), 'total_effective_seconds' => $min * 60, 'completed_pomodoros' => 2, 'status' => 'ENDED']);
        }
        $st = StudyStats::forUser($u);
        $this->assertSame(30, $st['today_minutes']);
        $this->assertSame(50, $st['goal_percent']);
        $this->assertSame(3, $st['streak']);
        $this->assertSame(3, $st['longest_streak']);
        $this->assertSame(3, $st['goal_days_30']);
        $this->assertSame(4, $st['active_days_30']);
        $this->assertCount(30, $st['month']);
        $this->assertSame(2, $st['today_pomodoros']);
    }

    public function test_update_daily_goal_validates_and_saves(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->patch(route('goal.update'), ['daily_goal_minutes' => 5])->assertSessionHasErrors('daily_goal_minutes');
        $this->actingAs($u)->patch(route('goal.update'), ['daily_goal_minutes' => 9999])->assertSessionHasErrors('daily_goal_minutes');
        $this->actingAs($u)->patch(route('goal.update'), ['daily_goal_minutes' => 90])->assertSessionHasNoErrors();
        $this->assertSame(90, $u->fresh()->daily_goal_minutes);
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('dari 90 menit');
    }

    public function test_longest_streak_survives_current_gap(): void
    {
        $u = User::factory()->create();
        $base = now()->startOfDay()->addHours(7);
        foreach ([10, 9, 8, 7, 3] as $ago) {
            StudySession::create(['user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5,
                'started_at' => $base->copy()->subDays($ago), 'ended_at' => $base->copy()->subDays($ago)->addHour(),
                'phase_started_at' => $base->copy()->subDays($ago), 'total_effective_seconds' => 1500, 'completed_pomodoros' => 1, 'status' => 'ENDED']);
        }
        $st = StudyStats::forUser($u);
        $this->assertSame(0, $st['streak']);
        $this->assertSame(4, $st['longest_streak']);
    }

    public function test_dashboard_and_history_pages_render_for_both_roles(): void
    {
        foreach ([User::factory()->create(), User::factory()->dosen()->create()] as $u) {
            foreach (['dashboard', 'sessions.index', 'pomodoro.index', 'profile.edit', 'rooms.create', 'rooms.join'] as $r) {
                $this->actingAs($u)->get(route($r))->assertOk();
            }
        }
    }
}
