<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use App\Services\OnlinePresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Perbaikan: migrasi Strict Mode idempotent, kebijakan AI di backend, dan presence online mahasiswa. */
class StrictPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dosenRoom(?User $dosen = null): array
    {
        $dosen ??= User::factory()->dosen()->create();
        $this->actingAs($dosen)->post(route('rooms.store'), ['name' => 'Kelas A', 'target_minutes' => 25])->assertRedirect();

        return [$dosen, Room::latest('id')->first()];
    }

    private function join(Room $room, ?User $u = null): User
    {
        $u ??= User::factory()->create();
        $room->members()->attach($u->id, ['role' => 'member', 'focus_status' => 'ABU', 'last_active' => now()]);

        return $u;
    }

    private function forgetMonitorCache(): void
    {
        foreach (User::where('role', 'dosen')->pluck('id') as $id) {
            Cache::forget('monitor:'.$id.':all');
            Room::pluck('code')->each(fn ($c) => Cache::forget('monitor:'.$id.':'.$c));
        }
        Room::pluck('id')->each(fn ($id) => Cache::forget('presence:'.$id));
    }

    private function row(User $dosen, int $studentId, ?string $room = null): ?array
    {
        $this->forgetMonitorCache(); // pembacaan segar; cache 3 dtk diuji terpisah (presence TIDAK ikut dibuang)
        $url = route('dosen.data').($room ? '?room='.$room : '');
        $json = $this->actingAs($dosen)->getJson($url)->assertOk()->json();

        return collect($json['students'])->firstWhere('id', $studentId);
    }

    // ============================================ MASALAH 1: Strict Mode

    /**
     * DDL (ALTER TABLE) di MySQL melakukan commit implisit sehingga rollback RefreshDatabase tidak lagi
     * membersihkan baris yang dibuat tes ini. Bersihkan manual (hanya di database pengujian).
     */
    private function wipeTestData(): void
    {
        $this->assertStringContainsString('testing', DB::getDatabaseName(), 'Hanya boleh pada database pengujian.');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['focus_logs', 'activity_logs', 'study_sessions', 'room_members', 'rooms', 'users'] as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_migration_adds_missing_columns_and_keeps_data(): void
    {
        $this->beforeApplicationDestroyed(fn () => $this->wipeTestData());

        [$dosen, $room] = $this->dosenRoom();
        $student = $this->join($room);
        $s = StudySession::begin($student, $room, 25, 5);

        // Simulasikan database hosting: kolom strict_mode / ai_policy TIDAK ada, sebagian kolom lain ada.
        Schema::table('rooms', fn ($t) => $t->dropColumn(['strict_mode', 'ai_policy']));
        Schema::table('study_sessions', fn ($t) => $t->dropColumn(['reflection', 'ai_usage']));
        $this->assertFalse(Schema::hasColumn('rooms', 'strict_mode'));

        // Di hosting migrasi perbaikan belum tercatat; di sini RefreshDatabase sudah mencatatnya, jadi hapus catatannya.
        DB::table('migrations')->where('migration', '2026_11_22_000001_repair_strict_mode_columns')->delete();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_22_000001_repair_strict_mode_columns.php', '--realpath' => false])->assertExitCode(0);

        foreach (['strict_mode', 'ai_policy'] as $c) {
            $this->assertTrue(Schema::hasColumn('rooms', $c), $c);
        }
        foreach (['reflection', 'ai_usage', 'mode', 'consented_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('study_sessions', $c), $c);
        }

        // Data tetap utuh dengan nilai bawaan aman.
        $this->assertSame(1, User::where('id', $student->id)->count());
        $this->assertSame($room->name, DB::table('rooms')->where('id', $room->id)->value('name'));
        $this->assertSame(0, (int) DB::table('rooms')->where('id', $room->id)->value('strict_mode'));
        $this->assertSame('declare', DB::table('rooms')->where('id', $room->id)->value('ai_policy'));
        $this->assertSame(1, DB::table('study_sessions')->where('id', $s->id)->count());
        $this->assertSame(1, DB::table('room_members')->where('room_id', $room->id)->where('user_id', $student->id)->count());
    }

    public function test_both_strict_migrations_are_idempotent_when_columns_already_exist(): void
    {
        foreach (['2026_11_21_000001_strict_mode_integrity_and_reflection.php', '2026_11_22_000001_repair_strict_mode_columns.php'] as $f) {
            $m = require base_path('database/migrations/'.$f);
            $m->up();
            $m->up(); // dua kali: tidak boleh error
        }
        $this->assertTrue(Schema::hasColumn('rooms', 'strict_mode'));
    }

    public function test_dosen_can_enable_and_disable_strict_mode_and_it_persists(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $base = ['name' => 'Kelas A', 'target_minutes' => 25, 'max_members' => 60];

        $this->actingAs($dosen)->patch(route('rooms.update', $room), $base + ['strict_mode' => '1', 'ai_policy' => 'forbidden'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        $room->refresh();
        $this->assertTrue($room->strict_mode);
        $this->assertSame('forbidden', $room->ai_policy);

        // tetap ada setelah halaman dimuat ulang
        $this->actingAs($dosen)->get(route('rooms.show', $room))->assertOk()->assertSee('value="forbidden" selected', false);

        $this->actingAs($dosen)->patch(route('rooms.update', $room), $base + ['ai_policy' => 'declare'])->assertSessionHasNoErrors();
        $room->refresh();
        $this->assertFalse($room->strict_mode);
        $this->assertSame('declare', $room->ai_policy);

        $this->actingAs($dosen)->patch(route('rooms.update', $room), $base + ['strict_mode' => '1', 'ai_policy' => 'bebas'])->assertSessionHasErrors('ai_policy');
    }

    public function test_student_owner_cannot_set_strict_mode_or_ai_policy(): void
    {
        $mhs = User::factory()->create();
        $this->actingAs($mhs)->post(route('rooms.store'), ['name' => 'Kelompok', 'target_minutes' => 25])->assertRedirect();
        $room = Room::latest('id')->first();

        $this->actingAs($mhs)->patch(route('rooms.update', $room), ['name' => 'Kelompok', 'target_minutes' => 25, 'max_members' => 10, 'strict_mode' => '1', 'ai_policy' => 'forbidden'])->assertSessionHasNoErrors();
        $room->refresh();
        $this->assertFalse($room->strict_mode);
        $this->assertSame('declare', $room->ai_policy);
    }

    public function test_ai_policy_forbidden_is_enforced_in_backend(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $room->update(['strict_mode' => true, 'ai_policy' => 'forbidden']);
        $mhs = $this->join($room);
        $s = StudySession::begin($mhs, $room, 25, 5);

        foreach (['idea', 'explain', 'draft'] as $v) {
            $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => $v, 'ai_usage_note' => 'pakai AI'])
                ->assertStatus(422)->assertJsonPath('errors.ai_usage.0', 'Penggunaan AI tidak diperbolehkan di room ini.');
        }
        $this->assertNull($s->fresh()->ai_usage);

        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'none', 'reflection' => 'Belajar bab 3'])->assertOk();
        $this->assertSame('none', $s->fresh()->ai_usage);

        // Kebijakan dibaca dari room SAAT INI: dosen mengubah ke forbidden setelah sesi dibuat tetap berlaku.
        $room->update(['ai_policy' => 'allowed']);
        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'idea'])->assertOk();
        $room->update(['ai_policy' => 'forbidden']);
        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'draft'])->assertStatus(422);
    }

    public function test_declare_requires_note_and_allowed_accepts_everything(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);
        $s = StudySession::begin($mhs, $room, 25, 5);

        $room->update(['ai_policy' => 'declare']);
        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'explain'])->assertStatus(422)->assertJsonPath('errors.ai_usage_note.0', 'Catatan penggunaan AI wajib diisi.');
        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'explain', 'ai_usage_note' => 'Minta penjelasan rekursi'])->assertOk();
        $this->assertSame('explain', $s->fresh()->ai_usage);
        $this->assertSame('Minta penjelasan rekursi', $s->fresh()->ai_usage_note);

        $room->update(['ai_policy' => 'allowed']);
        $this->actingAs($mhs)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'draft'])->assertOk();
        $this->assertSame('draft', $s->fresh()->ai_usage);
    }

    public function test_reflection_only_by_session_owner_and_invalid_values_rejected(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $a = $this->join($room);
        $b = $this->join($room);
        $s = StudySession::begin($a, $room, 25, 5);

        $this->actingAs($b)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'none'])->assertForbidden();
        $this->actingAs($dosen)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'none'])->assertForbidden();
        $this->actingAs($a)->postJson(route('sessions.reflection', $s), ['ai_usage' => 'hack'])->assertStatus(422);
    }

    public function test_strict_session_requires_consent_and_recap_page_renders_form(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $room->update(['strict_mode' => true, 'ai_policy' => 'forbidden']);
        $mhs = $this->join($room);

        $this->actingAs($mhs)->postJson(route('rooms.sessions.store', $room), [])->assertStatus(422);
        $res = $this->actingAs($mhs)->postJson(route('rooms.sessions.store', $room), ['consent' => true])->assertCreated();
        $s = StudySession::find($res->json('session.id'));
        $this->assertSame('strict', $s->mode);

        $html = $this->actingAs($mhs)->get(route('sessions.recap', $s))->assertOk()->getContent();
        $this->assertStringContainsString('reflection-form', $html);
        $this->assertStringNotContainsString('value="draft"', $html); // opsi AI tidak ditawarkan saat forbidden
    }

    // ============================================ MASALAH 2: Presence

    public function test_student_online_on_website_without_pomodoro_is_online_not_offline(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);

        $this->assertSame('offline', $this->row($dosen, $mhs->id)['presence']); // belum pernah membuka website

        $this->actingAs($mhs)->postJson(route('presence.ping'))->assertOk();
        $r = $this->row($dosen, $mhs->id);
        $this->assertSame('online', $r['presence']);
        $this->assertTrue($r['online']);
        $this->assertNull($r['phase']);          // tidak ada sesi Pomodoro
        $this->assertSame('Online, belum memulai sesi', $r['reason']);
    }

    public function test_pomodoro_states_study_pause_break_and_finish_back_to_online(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);
        $this->actingAs($mhs)->postJson(route('presence.ping'))->assertOk();

        $res = $this->actingAs($mhs)->postJson(route('rooms.sessions.store', $room), [])->assertCreated();
        $sid = $res->json('session.id');
        $this->assertSame('belajar', $this->row($dosen, $mhs->id)['presence']);

        // Pause: langsung terlihat (cache dosen dibuang), tanpa Cache::flush manual.
        $this->actingAs($dosen)->getJson(route('dosen.data'))->assertOk(); // isi cache
        $this->actingAs($mhs)->postJson(route('sessions.pause', $sid))->assertOk();
        $json = $this->actingAs($dosen)->getJson(route('dosen.data'))->json();
        $this->assertSame('jeda', collect($json['students'])->firstWhere('id', $mhs->id)['presence']);

        $this->actingAs($mhs)->postJson(route('sessions.resume', $sid))->assertOk();
        $this->assertSame('belajar', collect($this->actingAs($dosen)->getJson(route('dosen.data'))->json('students'))->firstWhere('id', $mhs->id)['presence']);

        // Fase istirahat
        StudySession::whereKey($sid)->update(['phase' => 'BREAK']);
        $this->assertSame('istirahat', $this->row($dosen, $mhs->id)['presence']);
        StudySession::whereKey($sid)->update(['phase' => 'FOCUS']);

        // Selesai -> tetap ONLINE (website masih dibuka), bukan offline.
        $this->actingAs($mhs)->postJson(route('sessions.finish', $sid))->assertOk();
        $this->assertSame('online', collect($this->actingAs($dosen)->getJson(route('dosen.data'))->json('students'))->firstWhere('id', $mhs->id)['presence']);
    }

    public function test_student_becomes_offline_after_heartbeats_stop_and_cache_does_not_keep_old_status(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);
        $ttl = OnlinePresence::ttl();

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->actingAs($mhs)->postJson(route('presence.ping'))->assertOk();
        $this->assertSame('online', $this->row($dosen, $mhs->id)['presence']);

        Carbon::setTestNow(Carbon::now()->addSeconds($ttl - 5));
        $this->assertSame('online', $this->row($dosen, $mhs->id)['presence']);

        // Browser ditutup: tidak ada ping lagi. Lewat TTL -> OFFLINE.
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->assertSame('offline', $this->row($dosen, $mhs->id)['presence']);

        // Dashboard dosen di-cache hanya beberapa detik: setelah masa cache lewat, status baru muncul (tidak menempel).
        $this->actingAs($mhs)->postJson(route('presence.ping'))->assertOk();
        $cache = (int) config('synlora.monitor.cache_seconds', 3);
        $this->forgetMonitorCache();
        $this->actingAs($dosen)->getJson(route('dosen.data'))->assertOk();
        Carbon::setTestNow(Carbon::now()->addSeconds($ttl + $cache + 1));
        $stale = collect($this->actingAs($dosen)->getJson(route('dosen.data'))->json('students'))->firstWhere('id', $mhs->id);
        $this->assertSame('offline', $stale['presence']);
    }

    public function test_open_session_with_stale_heartbeat_is_offline_not_online(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);

        Carbon::setTestNow('2026-11-22 10:00:00');
        $s = StudySession::begin($mhs, $room, 25, 5);
        $this->assertSame('belajar', $this->row($dosen, $mhs->id)['presence']);

        Carbon::setTestNow(Carbon::now()->addSeconds(OnlinePresence::ttl() + 10));
        $this->assertSame('offline', $this->row($dosen, $mhs->id)['presence']);
    }

    public function test_session_heartbeat_also_keeps_student_online(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $mhs = $this->join($room);
        $s = StudySession::begin($mhs, $room, 25, 5);

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->actingAs($mhs)->postJson(route('sessions.heartbeat', $s), [])->assertOk();
        $this->assertSame(1, count(OnlinePresence::lastSeen([$mhs->id])));
        Carbon::setTestNow(Carbon::now()->addSeconds(OnlinePresence::ttl() - 1));
        $this->assertSame('belajar', $this->row($dosen, $mhs->id)['presence']);
    }

    public function test_dosen_only_sees_students_in_own_rooms_and_no_leak_from_other_rooms(): void
    {
        [$dosen1, $room1] = $this->dosenRoom();
        [$dosen2, $room2] = $this->dosenRoom();
        $a = $this->join($room1);
        $b = $this->join($room2);
        $both = $this->join($room1);
        $room2->members()->attach($both->id, ['role' => 'member', 'focus_status' => 'ABU', 'last_active' => now()]);

        foreach ([$a, $b, $both] as $u) {
            $this->actingAs($u)->postJson(route('presence.ping'))->assertOk();
        }
        // $b sedang belajar di room2; dosen1 tidak boleh melihatnya sama sekali.
        $sb = StudySession::begin($b, $room2, 25, 5);
        // $both belajar di room2; dosen1 hanya boleh tahu ia ONLINE, tanpa detail sesi room2.
        $sBoth = StudySession::begin($both, $room2, 25, 5);

        $this->forgetMonitorCache();
        $ids = collect($this->actingAs($dosen1)->getJson(route('dosen.data'))->json('students'))->keyBy('id');
        $this->assertTrue($ids->has($a->id));
        $this->assertFalse($ids->has($b->id));
        $this->assertTrue($ids->has($both->id));

        $row = $ids[$both->id];
        $this->assertSame('online', $row['presence']);   // bukan 'belajar': sesi room2 tidak bocor
        $this->assertNull($row['phase']);
        $this->assertSame(0, $row['session_pomodoros']);
        $this->assertSame(0, $row['today_seconds']);

        // Riwayat mahasiswa room lain ditolak.
        $this->actingAs($dosen1)->getJson(route('dosen.history', $b))->assertForbidden();
        // Mahasiswa tidak dapat membuka dashboard dosen.
        $this->actingAs($a)->getJson(route('dosen.data'))->assertForbidden();
        // Filter ke room yang bukan miliknya tidak menghasilkan data.
        $this->assertSame([], $this->actingAs($dosen1)->getJson(route('dosen.data', ['room' => $room2->code]))->json('students'));
    }

    public function test_presence_ping_requires_login_and_is_light(): void
    {
        $this->postJson(route('presence.ping'))->assertUnauthorized();

        $u = User::factory()->create();
        DB::enableQueryLog();
        $this->actingAs($u)->postJson(route('presence.ping'))->assertOk()->assertJsonPath('ok', true);
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']))->count();
        $this->assertSame(0, $writes, 'ping tidak boleh menulis ke database');
    }

    public function test_room_page_shows_online_label_to_members_without_leaking_focus(): void
    {
        [$dosen, $room] = $this->dosenRoom();
        $a = $this->join($room);
        $b = $this->join($room);
        $this->actingAs($b)->postJson(route('presence.ping'))->assertOk();

        $json = $this->actingAs($a)->getJson(route('rooms.getActivities', $room))->assertOk()->json('members');
        $row = collect($json)->firstWhere('id', $b->id);
        $this->assertSame('online', $row['presence']);
        $this->assertSame('Online', $row['presence_label']);
        $this->assertNull($row['focus_status']);
    }

    public function test_layout_injects_presence_meta_only_for_logged_in_users(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('name="synlora-presence"', false);
    }
}
