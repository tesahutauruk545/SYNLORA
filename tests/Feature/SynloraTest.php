<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SynloraTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoom(User $owner, int $target = 25): Room
    {
        $this->actingAs($owner)->post(route('rooms.store'), ['name' => 'Matdis', 'target_minutes' => $target])->assertRedirect();

        return Room::latest('id')->first();
    }

    private function join(User $u, Room $r): void
    {
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => strtolower($r->code)])->assertRedirect(route('rooms.show', $r));
    }

    // ------------------------------------------------------------ room

    public function test_guest_cannot_access_app_pages(): void
    {
        foreach (['dashboard', 'rooms.create', 'rooms.join', 'pomodoro.index', 'sessions.index'] as $r) {
            $this->get(route($r))->assertRedirect(route('login'));
        }
        $this->postJson('/sessions/1/pause')->assertUnauthorized();
    }

    public function test_create_room_stores_owner_and_member_role(): void
    {
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $room->code);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAs($owner)->get(route('rooms.show', $room))->assertOk()->assertSee($room->code);
    }

    public function test_room_validation(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post(route('rooms.store'), ['name' => '', 'target_minutes' => 1])->assertSessionHasErrors(['name', 'target_minutes']);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_join_by_code_and_invalid_code(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $room = $this->makeRoom($owner);

        $this->join($member, $room);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $member->id, 'role' => 'member']);

        // idempoten
        $this->join($member, $room);
        $this->assertEquals(2, $room->members()->count());

        $this->actingAs($member)->post(route('rooms.joinRoom'), ['code' => 'ZZZZZZ'])->assertSessionHasErrors('code');
        $this->actingAs($member)->post(route('rooms.joinRoom'), ['code' => 'abc'])->assertSessionHasErrors('code');
    }

    public function test_non_member_cannot_see_room_or_use_its_endpoints(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $room = $this->makeRoom($owner);

        $this->actingAs($stranger)->get(route('rooms.show', $room))->assertRedirect(route('rooms.join'));
        $this->actingAs($stranger)->getJson(route('rooms.getActivities', $room))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('rooms.sessions.store', $room))->assertForbidden();
        $this->actingAs($stranger)->post(route('materials.store', $room), [])->assertForbidden();
        $this->actingAs($stranger)->delete(route('rooms.destroy', $room))->assertForbidden();
    }

    public function test_member_cannot_manage_room(): void
    {
        $owner = User::factory()->create();
        $m = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->join($m, $room);

        $this->actingAs($m)->delete(route('rooms.destroy', $room))->assertForbidden();
        $this->actingAs($m)->post(route('assignments.store', $room), ['title' => 'x'])->assertForbidden();
        $this->actingAs($m)->delete(route('rooms.members.remove', [$room, $owner->id]))->assertForbidden();
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_owner_can_remove_member_and_member_can_leave(): void
    {
        $owner = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->join($a, $room);
        $this->join($b, $room);

        $this->actingAs($owner)->delete(route('rooms.members.remove', [$room, $a->id]))->assertRedirect();
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $a->id]);

        $this->actingAs($b)->post(route('rooms.leave', $room))->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $b->id]);

        $this->actingAs($owner)->delete(route('rooms.members.remove', [$room, $owner->id]))->assertStatus(422);
        $this->actingAs($owner)->post(route('rooms.leave', $room))->assertForbidden();
    }

    // --------------------------------------------------------- pomodoro

    public function test_pomodoro_lifecycle_start_pause_resume_finish(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $u = User::factory()->create();
        $room = $this->makeRoom($u, 25);

        $r = $this->actingAs($u)->postJson(route('rooms.sessions.store', $room), ['break_minutes' => 5])->assertCreated();
        $r->assertJsonPath('session.status', 'ACTIVE')->assertJsonPath('session.phase', 'FOCUS')->assertJsonPath('session.remaining_seconds', 1500);
        $urls = $r->json('urls');

        $this->travel(10)->minutes();
        $this->actingAs($u)->getJson($urls['state'])->assertJsonPath('session.remaining_seconds', 900);

        $this->actingAs($u)->postJson($urls['pause'])->assertJsonPath('session.status', 'PAUSED');
        foreach (range(1, 6) as $_) { // jeda 30 menit dengan halaman tetap terbuka (polling)
            $this->travel(5)->minutes();
            $this->actingAs($u)->getJson($urls['state']);
        }
        $s = $this->actingAs($u)->getJson($urls['state']);
        $s->assertJsonPath('session.status', 'PAUSED')->assertJsonPath('session.remaining_seconds', 900);

        $this->actingAs($u)->postJson($urls['resume'])->assertJsonPath('session.status', 'ACTIVE')->assertJsonPath('session.remaining_seconds', 900);

        $this->travel(5)->minutes();
        $res = $this->actingAs($u)->postJson($urls['finish'])->assertOk();
        $res->assertJsonPath('session.status', 'ENDED');

        $session = StudySession::first();
        $this->assertSame(15 * 60, $session->total_effective_seconds); // 10 + 5 menit fokus, jeda tidak dihitung
        $this->assertNotNull($session->ended_at);
        foreach (['SESSION_START', 'SESSION_PAUSE', 'SESSION_RESUME', 'SESSION_END'] as $t) {
            $this->assertDatabaseHas('activity_logs', ['session_id' => $session->id, 'event_type' => $t]);
        }
    }

    public function test_phase_switches_to_break_then_back_and_counts_pomodoro(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $u = User::factory()->create();
        $r = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertCreated();
        $urls = $r->json('urls');

        foreach ([10, 10, 6] as $min) { // halaman terbuka: browser polling state
            $this->travel($min)->minutes();
            $s = $this->actingAs($u)->getJson($urls['state']);
        }
        $s->assertJsonPath('session.phase', 'BREAK')->assertJsonPath('session.completed_pomodoros', 1)->assertJsonPath('session.remaining_seconds', 4 * 60);

        $this->travel(5)->minutes(); // 31 menit: istirahat selesai, fokus ke-2 berjalan 1 menit
        $this->actingAs($u)->getJson($urls['state'])->assertJsonPath('session.phase', 'FOCUS')->assertJsonPath('session.remaining_seconds', 24 * 60);

        $this->actingAs($u)->postJson($urls['finish']);
        $this->assertSame((25 + 1) * 60, StudySession::first()->total_effective_seconds);
    }

    public function test_abandoned_session_is_closed_at_last_heartbeat(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $u = User::factory()->create();
        $r = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5]);
        $urls = $r->json('urls');

        $this->travel(5)->minutes();
        $this->actingAs($u)->getJson($urls['state']); // heartbeat di menit ke-5

        $this->travel(5)->hours(); // pengguna menutup halaman & lupa
        $s = $this->actingAs($u)->getJson($urls['state'])->assertJsonPath('session.status', 'ENDED');

        $session = StudySession::first();
        $this->assertSame('abandoned', $session->end_reason);
        $this->assertSame(5 * 60, $session->total_effective_seconds); // bukan 5 jam
    }

    public function test_starting_new_session_closes_previous_one(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertCreated();
        $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 10, 'break_minutes' => 2])->assertCreated();

        $this->assertEquals(1, StudySession::where('user_id', $u->id)->open()->count());
        $this->assertEquals(2, StudySession::count());
    }

    public function test_pomodoro_validation(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 0, 'break_minutes' => 999])->assertStatus(422);
    }

    public function test_only_owner_of_session_can_control_it(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $urls = $this->actingAs($a)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->json('urls');

        $this->actingAs($b)->getJson($urls['state'])->assertForbidden();
        $this->actingAs($b)->postJson($urls['pause'])->assertForbidden();
        $this->actingAs($b)->postJson($urls['finish'])->assertForbidden();
        $this->actingAs($b)->postJson($urls['events'], ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH'])->assertForbidden();
        $this->actingAs($b)->get($urls['recap'])->assertForbidden();
    }

    // ----------------------------------------------------- focus monitor

    public function test_focus_events_are_saved_and_recap_is_computed_from_real_data(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $u = User::factory()->create();
        $urls = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->json('urls');

        $ev = fn (array $d) => $this->actingAs($u)->postJson($urls['events'], $d)->assertOk();

        $ev(['event_type' => 'MONITOR_START', 'status' => 'HIJAU', 'details' => 'Pemantauan fokus dimulai']);
        $this->travel(60)->seconds();
        $ev(['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH', 'details' => 'Pindah tab/aplikasi lain']); // klien menyebut MERAH, server menetapkan KUNING
        $this->travel(10)->seconds();
        $ev(['event_type' => 'TAB_AWAY_LONG', 'details' => 'Tab ditinggalkan 10 detik']);
        $this->travel(20)->seconds();
        $ev(['event_type' => 'TAB_SWITCH_IN', 'status' => 'HIJAU', 'details' => 'Kembali ke tab SYNLORA']);
        $this->travel(20)->seconds();
        $ev(['event_type' => 'FACE_LOST_SHORT', 'status' => 'KUNING', 'details' => 'Wajah tidak terdeteksi 5 detik']);
        $this->travel(10)->seconds();
        $this->actingAs($u)->postJson($urls['finish'])->assertOk();

        $this->assertDatabaseCount('focus_logs', 6); // HIJAU, KUNING, MERAH, HIJAU, KUNING, ABU(selesai)

        $page = $this->actingAs($u)->get($urls['recap'])->assertOk();
        $page->assertSee('Pindah Tab')->assertSee('1 kali');

        $recap = \App\Services\SessionRecap::build(StudySession::first());
        $this->assertSame(60 + 20, $recap['seconds']['HIJAU']);
        $this->assertSame(20, $recap['seconds']['MERAH']);
        $this->assertSame(10 + 10, $recap['seconds']['KUNING']);
        $this->assertSame(1, $recap['counts']['tab_switch']);
    }

    public function test_events_validate_type_and_status_and_require_active_focus(): void
    {
        $u = User::factory()->create();
        $urls = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->json('urls');

        $this->actingAs($u)->postJson($urls['events'], ['event_type' => 'HACK', 'status' => 'HIJAU'])->assertStatus(422);
        $this->actingAs($u)->postJson($urls['events'], ['event_type' => 'FACE_DETECTED', 'status' => 'UNGU'])->assertStatus(422);

        $this->actingAs($u)->postJson($urls['pause']);
        $this->actingAs($u)->postJson($urls['events'], ['event_type' => 'FACE_DETECTED', 'status' => 'HIJAU'])->assertStatus(409);
    }

    public function test_client_free_text_is_never_stored_and_recap_output_is_escaped(): void
    {
        $u = User::factory()->create();
        $urls = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->json('urls');
        $this->actingAs($u)->postJson($urls['events'], ['event_type' => 'MONITOR_ERROR', 'details' => '<script>alert(1)</script>'])->assertOk();

        // Teks bebas dari klien tidak masuk database: keterangan berasal dari server.
        $this->assertSame(0, \App\Models\ActivityLog::where('details', 'like', '%<script>%')->count());
        $this->assertSame('Pemantau di browser mengalami galat', \App\Models\ActivityLog::where('event_type', 'MONITOR_ERROR')->value('details'));

        // Lapisan kedua: bila data berbahaya entah bagaimana ada di DB, tampilan tetap meng-escape.
        \App\Models\ActivityLog::query()->where('event_type', 'MONITOR_ERROR')->update(['details' => '<script>alert(1)</script>']);
        $this->actingAs($u)->get($urls['recap'])->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // ----------------------------------------------------------- privasi

    public function test_privacy_member_sees_only_own_activity_and_no_other_focus_status(): void
    {
        $owner = User::factory()->dosen()->create(['name' => 'Dosen Budi']);
        $a = User::factory()->create(['name' => 'Mhs A']);
        $b = User::factory()->create(['name' => 'Mhs B']);
        $room = $this->makeRoom($owner);
        $this->join($a, $room);
        $this->join($b, $room);

        $urlsA = $this->actingAs($a)->postJson(route('rooms.sessions.store', $room))->json('urls');
        $this->actingAs($a)->postJson($urlsA['events'], ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH', 'details' => 'Pindah tab/aplikasi lain']);
        $this->travel(12)->seconds(); // server menaikkan KUNING -> MERAH setelah 10 detik tanpa kembali
        $urlsB = $this->actingAs($b)->postJson(route('rooms.sessions.store', $room))->json('urls');

        // B tidak boleh melihat aktivitas A, dan tidak boleh melihat status fokus A
        $feedB = $this->actingAs($b)->getJson(route('rooms.getActivities', $room))->assertOk();
        $feedB->assertJsonPath('scope', 'self');
        $this->assertTrue(collect($feedB->json('activities'))->every(fn ($x) => $x['is_me']));
        $memberA = collect($feedB->json('members'))->firstWhere('name', 'Mhs A');
        $this->assertNull($memberA['focus_status']);
        $this->assertSame('belajar', $memberA['presence']);
        $this->assertArrayNotHasKey('email', $memberA);

        // Dosen anggota room melihat data fokus mahasiswa
        $feedO = $this->actingAs($owner)->getJson(route('rooms.getActivities', $room))->assertOk()->assertJsonPath('scope', 'room');
        $this->assertSame('MERAH', collect($feedO->json('members'))->firstWhere('name', 'Mhs A')['focus_status']);
        $this->assertTrue(collect($feedO->json('activities'))->contains(fn ($x) => $x['user_name'] === 'Mhs A' && $x['event_type'] === 'TAB_SWITCH_OUT'));

        // Rekap: B tidak boleh membuka rekap A; dosen anggota room boleh; A boleh
        $this->actingAs($b)->get($urlsA['recap'])->assertForbidden();
        $this->actingAs($owner)->get($urlsA['recap'])->assertOk();
        $this->actingAs($a)->get($urlsA['recap'])->assertOk();

        // Halaman room B tidak memuat status fokus A
        $this->actingAs($b)->get(route('rooms.show', $room))->assertOk()->assertDontSee('TIDAK FOKUS');
    }

    public function test_solo_session_recap_not_visible_to_others_even_room_owner(): void
    {
        $a = User::factory()->create();
        $o = User::factory()->create();
        $urls = $this->actingAs($a)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->json('urls');
        $this->actingAs($o)->get($urls['recap'])->assertForbidden();
    }

    public function test_session_history_lists_only_own_sessions(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5]);
        $this->actingAs($b)->get(route('sessions.index'))->assertOk()->assertSee('Belum ada riwayat sesi');
        $this->actingAs($a)->get(route('sessions.index'))->assertOk()->assertSee('Pomodoro Solo');
    }

    // --------------------------------------------------------- dashboard

    public function test_dashboard_shows_real_stats_not_hardcoded(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('0j 0m')->assertSee('0 hari');

        $day = now()->startOfDay()->addHours(8);
        foreach ([2, 1, 0] as $ago) { // 3 hari berturut-turut
            StudySession::create([
                'user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5,
                'started_at' => $day->copy()->subDays($ago), 'ended_at' => $day->copy()->subDays($ago)->addMinutes(80),
                'phase_started_at' => $day->copy()->subDays($ago), 'total_effective_seconds' => 75 * 60,
                'completed_pomodoros' => 3, 'status' => 'ENDED',
            ]);
        }

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('3j 45m')   // 3 x 75 menit
            ->assertSee('9')
            ->assertSee('3 hari');
        $this->assertSame(3, \App\Services\StudyStats::forUser($u)['streak']);
    }

    public function test_streak_resets_after_gap(): void
    {
        $u = User::factory()->create();
        $base = now()->startOfDay()->addHours(8);
        foreach ([5, 4] as $ago) {
            StudySession::create(['user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5,
                'started_at' => $base->copy()->subDays($ago), 'ended_at' => $base->copy()->subDays($ago)->addMinutes(30),
                'phase_started_at' => $base->copy()->subDays($ago), 'total_effective_seconds' => 1500, 'status' => 'ENDED']);
        }
        $this->assertSame(0, \App\Services\StudyStats::forUser($u)['streak']);
    }

    // ------------------------------------------------- materi & tugas

    public function test_owner_uploads_material_members_download_strangers_cannot(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $m = User::factory()->create();
        $x = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->join($m, $room);

        $file = UploadedFile::fake()->create('bab1.pdf', 200, 'application/pdf');
        $this->actingAs($owner)->post(route('materials.store', $room), ['title' => 'Bab 1', 'file' => $file])->assertSessionHasNoErrors();

        $mat = $room->materials()->first();
        $this->assertNotNull($mat);
        Storage::disk('local')->assertExists($mat->file_path);

        // anggota tidak boleh unggah
        $this->actingAs($m)->post(route('materials.store', $room), ['title' => 'x', 'file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();
        // anggota boleh unduh, non-anggota tidak
        $this->actingAs($m)->get(route('materials.download', [$room, $mat]))->assertOk();
        $this->actingAs($x)->get(route('materials.download', [$room, $mat]))->assertForbidden();
        // tipe file berbahaya ditolak
        $this->actingAs($owner)->post(route('materials.store', $room), ['title' => 'Jahat', 'file' => UploadedFile::fake()->create('shell.php', 5)])->assertSessionHasErrors('file');
        // materi room lain tidak bisa diakses lewat room ini (scoped binding)
        $other = $this->makeRoom(User::factory()->create());
        $this->actingAs($owner)->get(route('materials.download', [$other, $mat]))->assertNotFound();

        $this->actingAs($owner)->delete(route('materials.destroy', [$room, $mat]))->assertRedirect();
        Storage::disk('local')->assertMissing($mat->file_path);
    }

    public function test_assignment_flow_create_submit_grade(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $m = User::factory()->create();
        $other = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->join($m, $room);
        $this->join($other, $room);

        $this->actingAs($owner)->post(route('assignments.store', $room), ['title' => 'Tugas 1', 'description' => 'Kerjakan', 'due_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $a = Assignment::first();

        $this->actingAs($m)->post(route('assignments.submit', [$room, $a]), [])->assertSessionHasErrors('file');
        $this->actingAs($m)->post(route('assignments.submit', [$room, $a]), ['notes' => 'jawaban', 'file' => UploadedFile::fake()->create('jawab.pdf', 50)])->assertSessionHasNoErrors();
        $sub = $a->submissions()->first();
        $this->assertFalse($sub->is_late);
        $this->actingAs($owner)->post(route('assignments.submit', [$room, $a]), ['notes' => 'x'])->assertForbidden();

        // pengumpulan ulang tidak membuat duplikat
        $this->actingAs($m)->post(route('assignments.submit', [$room, $a]), ['notes' => 'revisi'])->assertSessionHasNoErrors();
        $this->assertEquals(1, $a->submissions()->count());

        // hanya pemilik + owner yang bisa unduh; sesama anggota tidak
        $this->actingAs($m)->get(route('assignments.submissions.download', [$room, $a, $sub]))->assertOk();
        $this->actingAs($owner)->get(route('assignments.submissions.download', [$room, $a, $sub]))->assertOk();
        $this->actingAs($other)->get(route('assignments.submissions.download', [$room, $a, $sub]))->assertForbidden();

        // anggota lain tidak melihat pengumpulan orang lain; owner melihat semua
        $this->actingAs($other)->get(route('assignments.show', [$room, $a]))->assertOk()->assertDontSee('revisi');
        $this->actingAs($owner)->get(route('assignments.show', [$room, $a]))->assertOk()->assertSee('revisi')->assertSee('Belum mengumpulkan');

        // penilaian
        $this->actingAs($m)->post(route('assignments.grade', [$room, $a, $sub]), ['score' => 100])->assertForbidden();
        $this->actingAs($owner)->post(route('assignments.grade', [$room, $a, $sub]), ['score' => 88, 'feedback' => 'Bagus'])->assertSessionHasNoErrors();
        $this->assertSame(88, $sub->fresh()->score);
        $this->actingAs($owner)->post(route('assignments.grade', [$room, $a, $sub]), ['score' => 101])->assertSessionHasErrors('score');
        // sesudah dinilai tidak bisa diubah
        $this->actingAs($m)->post(route('assignments.submit', [$room, $a]), ['notes' => 'ubah'])->assertSessionHasErrors('file');
    }

    public function test_late_submission_is_flagged(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $m = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->join($m, $room);
        $a = Assignment::create(['room_id' => $room->id, 'user_id' => $owner->id, 'title' => 'Lama', 'due_at' => now()->subHour()]);

        $this->actingAs($m)->post(route('assignments.submit', [$room, $a]), ['notes' => 'telat']);
        $this->assertTrue($a->submissions()->first()->is_late);
    }

    public function test_deleting_room_removes_files_but_keeps_personal_sessions(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $room = $this->makeRoom($owner);
        $this->actingAs($owner)->post(route('materials.store', $room), ['title' => 'M', 'file' => UploadedFile::fake()->create('a.pdf', 10)]);
        $urls = $this->actingAs($owner)->postJson(route('rooms.sessions.store', $room))->json('urls');
        $path = $room->materials()->first()->file_path;

        $this->actingAs($owner)->delete(route('rooms.destroy', $room), ['confirm_name' => $room->name])->assertRedirect(route('dashboard'));

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $s = StudySession::first();
        $this->assertNotNull($s);
        $this->assertNull($s->room_id);
        $this->assertSame('ENDED', $s->status);
    }

    public function test_pages_render_ok_for_logged_in_user(): void
    {
        $u = User::factory()->create();
        foreach (['dashboard', 'rooms.create', 'rooms.join', 'pomodoro.index', 'sessions.index', 'profile.edit'] as $r) {
            $this->actingAs($u)->get(route($r))->assertOk();
        }
    }
}
