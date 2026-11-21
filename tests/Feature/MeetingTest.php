<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use App\Realtime\RealtimeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRealtimePublisher;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    private FakeRealtimePublisher $rt;
    private User $owner;
    private User $a;
    private User $b;
    private User $stranger;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rt = new FakeRealtimePublisher();
        $this->app->instance(RealtimePublisher::class, $this->rt);

        $this->owner = User::factory()->create(['name' => 'Dosen Owner']);
        $this->a = User::factory()->create(['name' => 'Mhs A']);
        $this->b = User::factory()->create(['name' => 'Mhs B']);
        $this->stranger = User::factory()->create(['name' => 'Orang Luar']);

        $this->actingAs($this->owner)->post(route('rooms.store'), ['name' => 'Kelas Matdis', 'target_minutes' => 25]);
        $this->room = Room::first();
        foreach ([$this->a, $this->b] as $u) {
            $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => $this->room->code]);
        }
    }

    private function join(User $u): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->postJson(route('meeting.join', $this->room));
    }

    private function signal(User $u, string $status, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->postJson(route('meeting.signal', $this->room), ['status' => $status] + $extra);
    }

    /** Waktu berjalan sambil peserta yang masih terhubung mengirim detak seperti browser sungguhan. */
    private function pass(int $seconds, array $users): void
    {
        for ($t = 0; $t < $seconds; $t += 30) {
            $this->travel(min(30, $seconds - $t))->seconds();
            foreach ($users as $u) {
                $this->signal($u, 'HIJAU');
            }
        }
    }

    private function state(User $u): array
    {
        return $this->actingAs($u)->getJson(route('meeting.state', $this->room))->assertOk()->json();
    }

    private function row(array $state, User $u): ?array
    {
        return collect($state['participants'])->firstWhere('user_id', $u->id);
    }

    // ---------------------------------------------------------- keamanan

    public function test_guest_and_non_member_are_blocked_on_every_meeting_endpoint(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->postJson(route('meeting.join', $this->room))->assertUnauthorized();

        $s = $this->stranger;
        $this->actingAs($s)->get(route('meeting.show', $this->room))->assertForbidden();
        $this->actingAs($s)->postJson(route('meeting.join', $this->room))->assertForbidden();
        $this->actingAs($s)->postJson(route('meeting.signal', $this->room), ['status' => 'HIJAU'])->assertForbidden();
        $this->actingAs($s)->postJson(route('meeting.leave', $this->room))->assertForbidden();
        $this->actingAs($s)->postJson(route('meeting.end', $this->room))->assertForbidden();
        $this->actingAs($s)->getJson(route('meeting.state', $this->room))->assertForbidden();
        $this->actingAs($s)->getJson(route('meeting.token', $this->room))->assertForbidden();
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_only_owner_can_end_meeting_for_everyone(): void
    {
        $this->join($this->a);
        $this->actingAs($this->a)->postJson(route('meeting.end', $this->room))->assertForbidden();
        $this->assertEquals('LIVE', Meeting::first()->status);
    }

    // ----------------------------------------------------- masuk & Jitsi

    public function test_each_room_has_its_own_meeting_and_jitsi_room(): void
    {
        $this->actingAs($this->owner)->post(route('rooms.store'), ['name' => 'Kelas Lain', 'target_minutes' => 25]);
        $other = Room::latest('id')->first();

        $r1 = $this->join($this->owner)->assertOk();
        $r2 = $this->actingAs($this->owner)->postJson(route('meeting.join', $other))->assertOk();

        $this->assertNotEquals($r1->json('jitsi.room_name'), $r2->json('jitsi.room_name'));
        $this->assertMatchesRegularExpression('/^synlora-[a-z0-9]{24}$/', $r1->json('jitsi.room_name'));
        $this->assertEquals(2, Meeting::count());
        $this->assertEquals($this->room->id, Meeting::find($r1->json('meeting.id'))->room_id);
    }

    public function test_two_members_join_the_same_meeting_and_see_correct_jitsi_config(): void
    {
        $o = $this->join($this->owner)->assertOk();
        $a = $this->join($this->a)->assertOk();

        $this->assertTrue($o->json('meeting.started_now'));
        $this->assertFalse($a->json('meeting.started_now'));
        $this->assertSame($o->json('jitsi.room_name'), $a->json('jitsi.room_name'));
        $this->assertSame('Mhs A', $a->json('jitsi.display_name'));
        $this->assertTrue($o->json('jitsi.is_moderator'));
        $this->assertFalse($a->json('jitsi.is_moderator'));
        $this->assertSame('meet.jit.si', $a->json('jitsi.domain'));
        $this->assertTrue($a->json('jitsi.public_instance'));
        $this->assertNull($a->json('jitsi.jwt'));
        $this->assertStringNotContainsString('@', json_encode($a->json('jitsi'))); // tidak ada email
        $this->assertEquals(1, Meeting::count());
        $this->assertEquals(2, Meeting::first()->peak_participants);
    }

    public function test_realtime_token_carries_role_and_cannot_be_forged(): void
    {
        $o = $this->join($this->owner)->json('realtime');
        $a = $this->join($this->a)->json('realtime');

        $this->assertTrue($o['enabled']);
        $this->assertStringEndsWith(':6001/ws', $o['url']);

        $po = RealtimeToken::verify($o['token']);
        $pa = RealtimeToken::verify($a['token']);
        $this->assertTrue($po['o']);
        $this->assertFalse($pa['o']);
        $this->assertSame($this->room->id, $pa['r']);
        $this->assertSame($this->a->id, $pa['u']);

        // anggota mencoba menaikkan perannya -> tanda tangan tidak cocok
        [$body, $sig] = explode('.', $a['token']);
        $forged = rtrim(strtr(base64_encode(json_encode(['u' => $this->a->id, 'r' => $this->room->id, 'o' => true, 'exp' => time() + 999])), '+/', '-_'), '=');
        $this->assertNull(RealtimeToken::verify($forged.'.'.$sig));
        $this->assertNull(RealtimeToken::verify('abc.def'));
        $this->assertNull(RealtimeToken::verify($a['token'], null, time() + 3600)); // kedaluwarsa
    }

    public function test_jwt_is_signed_and_restricts_when_private_key_configured(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $pub = openssl_pkey_get_details($key)['key'];

        config(['synlora.jitsi.domain' => '8x8.vc', 'synlora.jitsi.app_id' => 'vpaas-magic-cookie-abc', 'synlora.jitsi.kid' => 'vpaas-magic-cookie-abc/key1', 'synlora.jitsi.private_key' => $pem]);

        $r = $this->join($this->owner)->assertOk();
        $m = Meeting::first();

        $this->assertSame('vpaas-magic-cookie-abc/'.$m->jitsi_name, $r->json('jitsi.room_name'));
        $this->assertSame('https://8x8.vc/vpaas-magic-cookie-abc/external_api.js', $r->json('jitsi.script_url'));
        $this->assertTrue($r->json('jitsi.restricted'));
        $this->assertFalse($r->json('jitsi.public_instance'));

        [$h, $p, $s] = explode('.', $r->json('jitsi.jwt'));
        $dec = fn ($x) => json_decode(base64_decode(strtr($x, '-_', '+/')), true);
        $this->assertSame(1, openssl_verify("$h.$p", base64_decode(strtr($s, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256));
        $this->assertSame('RS256', $dec($h)['alg']);
        $this->assertSame('vpaas-magic-cookie-abc/key1', $dec($h)['kid']);
        $claims = $dec($p);
        $this->assertSame($m->jitsi_name, $claims['room']);
        $this->assertSame('true', $claims['context']['user']['moderator']);
        $this->assertSame('false', $claims['context']['features']['recording']);
        $this->assertSame('jitsi', $claims['aud']);

        $rm = $this->join($this->a)->json('jitsi.jwt');
        $this->assertSame('false', $dec(explode('.', $rm)[1])['context']['user']['moderator']);
    }

    // ---------------------------------------- status fokus & privasi

    public function test_focus_status_saved_to_database_and_deduplicated(): void
    {
        $this->join($this->a);

        $this->signal($this->a, 'HIJAU', ['reason' => 'Wajah terdeteksi', 'event_type' => 'MONITOR_START'])->assertOk()->assertJsonPath('rejoined', false);
        $this->signal($this->a, 'HIJAU')->assertOk();               // sama -> tidak dobel
        $this->signal($this->a, 'MERAH', ['reason' => 'Berpindah tab', 'event_type' => 'TAB_HIDDEN'])->assertOk();
        $this->signal($this->a, 'HIJAU', ['reason' => 'Kembali', 'event_type' => 'TAB_VISIBLE'])->assertOk();

        $this->assertEquals(['HIJAU', 'MERAH', 'HIJAU'], \App\Models\MeetingFocusLog::where('user_id', $this->a->id)->orderBy('id')->pluck('status')->all());
        $this->assertDatabaseHas('meeting_events', ['user_id' => $this->a->id, 'event_type' => 'TAB_HIDDEN']);
        $this->assertDatabaseHas('meeting_attendances', ['user_id' => $this->a->id, 'current_status' => 'HIJAU']);
    }

    public function test_signal_validation(): void
    {
        $this->join($this->a);
        $this->signal($this->a, 'UNGU')->assertStatus(422);
        $this->signal($this->a, 'HIJAU', ['event_type' => 'HACK'])->assertStatus(422);
        $this->signal($this->a, 'HIJAU', ['reason' => str_repeat('x', 300)])->assertStatus(422);
        $this->actingAs($this->a)->postJson(route('meeting.signal', $this->room), [])->assertStatus(422);
    }

    public function test_owner_sees_everyone_but_member_sees_only_self(): void
    {
        $this->join($this->owner);
        $this->join($this->a);
        $this->join($this->b);
        $this->signal($this->a, 'HIJAU', ['reason' => 'Wajah terdeteksi']);
        $this->signal($this->b, 'MERAH', ['reason' => 'Berpindah tab']);

        $o = $this->state($this->owner);
        $this->assertSame('room', $o['scope']);
        $this->assertCount(3, $o['participants']);
        $this->assertSame('HIJAU', $this->row($o, $this->a)['status']);
        $this->assertSame('MERAH', $this->row($o, $this->b)['status']);
        $this->assertTrue($this->row($o, $this->owner)['is_owner']);

        $ma = $this->state($this->a);
        $this->assertSame('self', $ma['scope']);
        $this->assertCount(1, $ma['participants']);
        $this->assertSame($this->a->id, $ma['participants'][0]['user_id']);
        $this->assertNull($this->row($ma, $this->b));
        $this->assertStringNotContainsString('Mhs B', json_encode($ma));
        $this->assertStringNotContainsString('@', json_encode($ma));   // tidak membocorkan email
    }

    public function test_realtime_pushes_target_only_owner_and_the_participant_themselves(): void
    {
        $this->join($this->a);
        $this->rt->sent = [];

        $this->signal($this->a, 'MERAH', ['reason' => 'Berpindah tab']);

        $msgs = $this->rt->of('participant');
        $this->assertCount(1, $msgs);
        $m = $msgs[0];
        $this->assertSame($this->room->id, $m['roomId']);
        $this->assertTrue($m['to']['owners']);
        $this->assertSame([$this->a->id], $m['to']['users']);   // B tidak pernah menjadi penerima
        $this->assertArrayNotHasKey('all', $m['to']);
        $this->assertSame('MERAH', $m['data']['participant']['status']);
        $this->assertSame('Mhs A', $m['data']['participant']['name']);
    }

    // --------------------------------- keluar / terputus / bergabung kembali

    public function test_attendance_duration_leave_and_meeting_ends_when_last_one_leaves(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->join($this->owner);
        $this->join($this->a);

        $this->pass(300, [$this->a, $this->owner]);

        $this->actingAs($this->a)->postJson(route('meeting.leave', $this->room))->assertOk();
        $this->assertEquals('LIVE', Meeting::first()->status);          // owner masih di dalam
        $this->assertSame('left', MeetingAttendance::where('user_id', $this->a->id)->first()->leave_reason);

        $this->pass(300, [$this->owner]);
        $this->actingAs($this->owner)->postJson(route('meeting.leave', $this->room))->assertOk();

        $m = Meeting::first();
        $this->assertEquals('ENDED', $m->status);
        $this->assertSame('all_left', $m->end_reason);
        $this->assertSame(600, $m->ended_at->getTimestamp() - $m->started_at->getTimestamp());

        $rowA = $this->row($this->state($this->owner) + ['participants' => []], $this->a);   // meeting sudah berakhir
        $this->assertNull($rowA);

        $rep = \App\Services\MeetingReport::build($m, $this->room, $this->owner);
        $byName = collect($rep['participants'])->keyBy('name');
        $this->assertSame(300, $byName['Mhs A']['attendance_seconds']);
        $this->assertSame(600, $byName['Dosen Owner']['attendance_seconds']);
    }

    public function test_disconnect_then_rejoin_creates_new_segment_and_excludes_gap(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->join($this->owner);
        $this->join($this->a);
        $this->signal($this->a, 'HIJAU');

        $this->travel(30)->seconds();
        $this->signal($this->a, 'HIJAU');
        $this->signal($this->owner, 'HIJAU');

        // A kehilangan koneksi (tidak ada detak) 70 dtk; owner tetap mengirim detak
        $this->travel(50)->seconds();
        $this->signal($this->owner, 'HIJAU');
        $this->travel(20)->seconds();
        $this->signal($this->owner, 'HIJAU');

        $row = $this->row($this->state($this->owner), $this->a);
        $this->assertSame('disconnected', $row['connection']);
        $this->assertSame('ABU', $row['status']);
        $this->assertSame('Koneksi terputus', $row['reason']);

        $this->signal($this->a, 'HIJAU', ['reason' => 'Wajah terdeteksi'])->assertOk()->assertJsonPath('rejoined', true);

        $segments = MeetingAttendance::where('user_id', $this->a->id)->orderBy('id')->get();
        $this->assertCount(2, $segments);
        $this->assertSame('disconnected', $segments[0]->leave_reason);
        $this->assertSame(30, $segments[0]->left_at->getTimestamp() - $segments[0]->joined_at->getTimestamp()); // celah 70 dtk tidak dihitung
        $this->assertDatabaseHas('meeting_events', ['user_id' => $this->a->id, 'event_type' => 'DISCONNECTED']);
        $this->assertDatabaseHas('meeting_events', ['user_id' => $this->a->id, 'event_type' => 'REJOINED']);
        $this->assertSame(2, $this->row($this->state($this->owner), $this->a)['join_count']);
        $this->assertSame('online', $this->row($this->state($this->owner), $this->a)['connection']);
        $this->assertSame('HIJAU', $this->row($this->state($this->owner), $this->a)['status']);
    }

    public function test_page_refresh_replaces_attendance_without_logging_a_leave(): void
    {
        $this->join($this->a);
        $this->signal($this->a, 'HIJAU');
        $r = $this->join($this->a)->assertOk();

        $this->assertTrue($r->json('rejoined'));
        $this->assertEquals(1, MeetingAttendance::where('user_id', $this->a->id)->whereNull('left_at')->count());
        $this->assertDatabaseMissing('meeting_events', ['user_id' => $this->a->id, 'event_type' => 'LEFT']);
        $this->assertDatabaseMissing('meeting_events', ['user_id' => $this->a->id, 'event_type' => 'DISCONNECTED']);
    }

    public function test_leave_beacon_from_old_page_does_not_close_the_new_attendance_after_refresh(): void
    {
        $first = $this->join($this->a)->json('attendance_id');
        $second = $this->join($this->a)->json('attendance_id');     // halaman dimuat ulang
        $this->assertNotEquals($first, $second);

        // beacon "pagehide" milik halaman lama tiba terlambat
        $this->actingAs($this->a)->postJson(route('meeting.leave', $this->room), ['attendance_id' => $first])->assertOk();

        $this->assertNotNull(MeetingAttendance::find($second));
        $this->assertNull(MeetingAttendance::find($second)->left_at, 'kehadiran baru tidak boleh ikut tertutup');
        $this->assertEquals('LIVE', Meeting::first()->status);
    }

    public function test_meeting_is_closed_when_everyone_disconnects_and_ended_at_is_last_heartbeat(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->join($this->a);
        $this->travel(20)->seconds();
        $this->signal($this->a, 'HIJAU');
        $lastSeen = now()->getTimestamp();

        $this->travel(10)->minutes();                         // peserta menutup laptop

        $s = $this->state($this->owner);
        $this->assertNull($s['meeting']);
        $m = Meeting::first();
        $this->assertSame('ENDED', $m->status);
        $this->assertSame('abandoned', $m->end_reason);
        $this->assertSame($lastSeen, $m->ended_at->getTimestamp());          // bukan 10 menit kemudian
        $this->assertSame($lastSeen, MeetingAttendance::first()->left_at->getTimestamp());

        // meeting berikutnya = meeting baru dengan nama ruang Jitsi baru
        $r = $this->join($this->b)->assertOk();
        $this->assertTrue($r->json('meeting.started_now'));
        $this->assertNotEquals($m->jitsi_name, Meeting::latest('id')->first()->jitsi_name);
        $this->assertEquals(2, Meeting::count());
    }

    public function test_signal_without_live_meeting_returns_409(): void
    {
        $this->signal($this->a, 'HIJAU')->assertStatus(409)->assertJsonPath('error', 'meeting_ended');
    }

    public function test_owner_ends_meeting_for_all_and_everyone_is_notified(): void
    {
        $this->join($this->owner);
        $this->join($this->a);
        $this->rt->sent = [];

        $this->actingAs($this->owner)->postJson(route('meeting.end', $this->room))->assertOk()->assertJsonPath('ended', true);

        $m = Meeting::first();
        $this->assertSame('ENDED', $m->status);
        $this->assertSame('owner_ended', $m->end_reason);
        $this->assertEquals(0, MeetingAttendance::whereNull('left_at')->count());
        $this->assertEquals(2, MeetingAttendance::where('leave_reason', 'meeting_ended')->count());

        $ended = $this->rt->of('meeting.ended');
        $this->assertCount(1, $ended);
        $this->assertTrue($ended[0]['to']['all']);
        $this->signal($this->a, 'HIJAU')->assertStatus(409)->assertJsonPath('reason', 'owner_ended');
        $this->actingAs($this->a)->getJson(route('meeting.state', $this->room))->assertOk()->assertJsonPath('meeting', null)->assertJsonPath('ended_reason', 'owner_ended');
    }

    public function test_removed_member_is_dropped_from_meeting_and_loses_access(): void
    {
        $this->join($this->owner);
        $this->join($this->a);
        $this->rt->sent = [];

        $this->actingAs($this->owner)->delete(route('rooms.members.remove', [$this->room, $this->a->id]))->assertRedirect();

        $this->assertEquals(0, MeetingAttendance::where('user_id', $this->a->id)->whereNull('left_at')->count());
        $this->assertCount(1, $this->rt->of('meeting.removed'));
        $this->assertSame([$this->a->id], $this->rt->of('meeting.removed')[0]['to']['users']);
        $this->signal($this->a, 'HIJAU')->assertForbidden();
        $this->join($this->a)->assertForbidden();
    }

    // -------------------------------------------- laporan & independensi

    public function test_report_access_owner_sees_all_member_only_self_outsider_denied(): void
    {
        $this->join($this->owner);
        $this->join($this->a);
        $this->signal($this->a, 'HIJAU', ['reason' => 'ok']);
        $this->signal($this->a, 'MERAH', ['reason' => 'Berpindah tab']);
        $this->actingAs($this->a)->postJson(route('meeting.leave', $this->room));
        $this->actingAs($this->owner)->postJson(route('meeting.leave', $this->room));
        $m = Meeting::first();
        $url = route('meeting.report', [$this->room, $m]);

        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Mhs A')->assertSee('Dosen Owner');
        $this->actingAs($this->a)->get($url)->assertOk()->assertSee('Mhs A')->assertDontSee('Dosen Owner');
        $this->actingAs($this->b)->get($url)->assertForbidden();           // anggota tapi tidak hadir
        $this->actingAs($this->stranger)->get($url)->assertForbidden();
    }

    public function test_pomodoro_and_meeting_are_independent(): void
    {
        $this->actingAs($this->a)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertCreated();
        $before = StudySession::first()->only(['status', 'phase', 'total_effective_seconds']);

        $this->join($this->a);
        $this->signal($this->a, 'MERAH', ['reason' => 'Berpindah tab']);
        $this->actingAs($this->a)->postJson(route('meeting.leave', $this->room));

        // sesi Pomodoro tidak tersentuh oleh meeting
        $this->assertEquals($before, StudySession::first()->only(['status', 'phase', 'total_effective_seconds']));
        $this->assertEquals(0, \App\Models\FocusLog::count());
        $this->assertEquals(0, \App\Models\ActivityLog::where('event_type', 'like', 'TAB%')->count());

        // dan sebaliknya: menjeda/mengakhiri Pomodoro tidak menghentikan kehadiran meeting
        $this->join($this->a);
        $urls = StudySession::first();
        $this->actingAs($this->a)->postJson(route('sessions.finish', $urls))->assertOk();
        $this->assertEquals(1, MeetingAttendance::where('user_id', $this->a->id)->whereNull('left_at')->count());
        $this->signal($this->a, 'HIJAU')->assertOk();
    }

    public function test_deleting_room_ends_meeting(): void
    {
        $this->join($this->a);
        $this->actingAs($this->owner)->delete(route('rooms.destroy', $this->room))->assertRedirect();
        $this->assertEquals(0, Meeting::count());   // cascade
    }
}
