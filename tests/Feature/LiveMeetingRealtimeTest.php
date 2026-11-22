<?php

namespace Tests\Feature;

use App\Events\FocusStatusChanged;
use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\TestCase;

/** Live Meeting (LiveKit) dan notifikasi fokus realtime: token, hak akses channel, dan siaran perubahan status. */
class LiveMeetingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function room(User $owner): Room
    {
        $this->actingAs($owner)->post(route('rooms.store'), ['name' => 'Kelas A', 'target_minutes' => 25])->assertRedirect();

        return Room::latest('id')->first();
    }

    private function join(Room $room, User $u): User
    {
        $room->members()->attach($u->id, ['role' => 'member', 'focus_status' => 'ABU', 'last_active' => now()]);

        return $u;
    }

    private function configureLiveKit(): void
    {
        config(['livekit.url' => 'wss://demo.livekit.cloud', 'livekit.api_key' => 'APIkeyTEST', 'livekit.api_secret' => 'secret-super-rahasia-123']);
    }

    private function decode(string $jwt): array
    {
        [$h, $p, $s] = explode('.', $jwt);
        $b64d = fn ($x) => base64_decode(strtr($x, '-_', '+/'));
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'secret-super-rahasia-123', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s, 'Tanda tangan JWT harus HS256 dengan API secret.');

        return json_decode($b64d($p), true);
    }

    /** Jalankan callback otorisasi channel milik routes/channels.php. */
    private function canJoin(?User $user, string $channel): bool
    {
        $driver = app(BroadcastManager::class)->driver();
        $m = new ReflectionMethod($driver, 'verifyUserCanAccessChannel');
        $m->setAccessible(true);
        $req = Request::create('/broadcasting/auth', 'POST');
        $req->setUserResolver(fn () => $user);

        try {
            $m->invoke($driver, $req, $channel);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return false;
        }
    }

    // ======================================================= LiveKit token

    public function test_member_gets_token_with_room_grant_and_no_secret_leak(): void
    {
        $this->configureLiveKit();
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $mhs = $this->join($room, User::factory()->create(['name' => 'Budi']));

        $res = $this->actingAs($mhs)->postJson(route('rooms.meeting.token', $room))->assertOk();
        $res->assertJsonPath('url', 'wss://demo.livekit.cloud');
        $this->assertStringNotContainsString('secret-super-rahasia-123', $res->getContent());
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $claims = $this->decode($res->json('token'));
        $this->assertSame('APIkeyTEST', $claims['iss']);
        $this->assertSame('u'.$mhs->id, $claims['sub']);
        $this->assertSame('Budi', $claims['name']);
        $this->assertSame('synlora-room-'.$room->id, $claims['video']['room']);
        $this->assertTrue($claims['video']['roomJoin']);
        $this->assertGreaterThan(time() + 3600, $claims['exp']);
        $this->assertSame(['role' => 'mahasiswa'], json_decode($claims['metadata'], true));
    }

    public function test_lecturer_member_and_student_owner_both_get_tokens_for_same_room(): void
    {
        $this->configureLiveKit();
        $mhsOwner = User::factory()->create();
        $room = $this->room($mhsOwner);
        $dosen = $this->join($room, User::factory()->dosen()->create());

        $a = $this->decode($this->actingAs($mhsOwner)->postJson(route('rooms.meeting.token', $room))->assertOk()->json('token'));
        $b = $this->decode($this->actingAs($dosen)->postJson(route('rooms.meeting.token', $room))->assertOk()->json('token'));
        $this->assertSame($a['video']['room'], $b['video']['room']);
        $this->assertSame('dosen', json_decode($b['metadata'], true)['role']);
    }

    public function test_non_member_and_guest_cannot_get_token(): void
    {
        $this->configureLiveKit();
        $room = $this->room(User::factory()->create());

        $this->actingAs(User::factory()->create())->postJson(route('rooms.meeting.token', $room))->assertForbidden();
        $this->actingAs(User::factory()->dosen()->create())->postJson(route('rooms.meeting.token', $room))->assertForbidden();

        auth()->logout();
        $this->postJson(route('rooms.meeting.token', $room))->assertUnauthorized();
    }

    public function test_token_reports_not_configured_instead_of_pretending(): void
    {
        config(['livekit.url' => null, 'livekit.api_key' => null, 'livekit.api_secret' => null]);
        $owner = User::factory()->create();
        $room = $this->room($owner);

        $this->actingAs($owner)->postJson(route('rooms.meeting.token', $room))->assertStatus(503)->assertJsonMissing(['token']);
    }

    public function test_room_page_embeds_meeting_card_without_secrets(): void
    {
        $this->configureLiveKit();
        $owner = User::factory()->create();
        $room = $this->room($owner);

        $html = $this->actingAs($owner)->get(route('rooms.show', $room))->assertOk()->assertSee('Study Meet')->getContent();
        $this->assertStringContainsString(str_replace('/', '\\/', route('rooms.meeting.token', $room)), $html); // JSON meng-escape garis miring
        $this->assertStringNotContainsString('secret-super-rahasia-123', $html);
        $this->assertStringNotContainsString('APIkeyTEST', $html);
    }

    // ================================================== otorisasi channel

    public function test_monitor_channel_only_for_lecturer_members(): void
    {
        $mhsOwner = User::factory()->create();
        $room = $this->room($mhsOwner);
        $dosenIn = $this->join($room, User::factory()->dosen()->create());
        $dosenOut = User::factory()->dosen()->create();
        $mhs = $this->join($room, User::factory()->create());
        $channel = 'room.'.$room->code.'.monitor';

        $this->assertTrue($this->canJoin($dosenIn, $channel));
        $this->assertFalse($this->canJoin($dosenOut, $channel), 'Dosen non-anggota tidak boleh.');
        $this->assertFalse($this->canJoin($mhs, $channel), 'Mahasiswa tidak boleh memantau.');
        $this->assertFalse($this->canJoin($mhsOwner, $channel), 'Owner mahasiswa tetap tidak boleh memantau.');
        $this->assertFalse($this->canJoin($dosenIn, 'room.ZZZZZZ.monitor'), 'Room yang tidak ada ditolak.');
    }

    public function test_student_channel_is_private_to_that_student(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $dosen = User::factory()->dosen()->create();

        $this->assertTrue($this->canJoin($a, 'App.Models.User.'.$a->id));
        $this->assertFalse($this->canJoin($b, 'App.Models.User.'.$a->id));
        $this->assertFalse($this->canJoin($dosen, 'App.Models.User.'.$a->id));
    }

    // =========================================== siaran perubahan status

    private function startFocus(User $u, Room $room): StudySession
    {
        $this->actingAs($u)->postJson(route('rooms.sessions.store', $room), ['break_minutes' => 5])->assertCreated();

        return StudySession::where('user_id', $u->id)->latest('id')->first();
    }

    private function send(User $u, StudySession $s, string $type, string $status): void
    {
        $this->actingAs($u)->postJson(route('sessions.events', $s), ['event_type' => $type, 'status' => $status, 'client_id' => bin2hex(random_bytes(8))])->assertOk();
    }

    public function test_face_lost_then_recovered_broadcasts_once_each_to_correct_channels(): void
    {
        Event::fake([FocusStatusChanged::class]);
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $mhs = $this->join($room, User::factory()->create(['name' => 'Siti']));
        $s = $this->startFocus($mhs, $room);

        $this->send($mhs, $s, 'MONITOR_START', 'HIJAU');
        $this->send($mhs, $s, 'FACE_LOST_SHORT', 'KUNING');
        $this->send($mhs, $s, 'FACE_LOST_SHORT', 'KUNING'); // diulang: tidak boleh siaran lagi
        $this->send($mhs, $s, 'FACE_DETECTED', 'HIJAU');
        $this->send($mhs, $s, 'FACE_DETECTED', 'HIJAU');

        $kinds = [];
        Event::assertDispatched(FocusStatusChanged::class, function ($e) use (&$kinds, $room, $mhs) {
            $kinds[] = $e->payload['kind'].':'.$e->payload['status'];
            $this->assertSame($room->code, $e->roomCode);
            $this->assertSame($mhs->id, $e->studentId);
            $names = array_map(fn ($c) => $c->name, $e->broadcastOn());
            $this->assertSame(['private-room.'.$room->code.'.monitor', 'private-App.Models.User.'.$mhs->id], $names);
            $this->assertSame('focus.status', $e->broadcastAs());
            $this->assertArrayNotHasKey('image', $e->payload);

            return true;
        });
        $this->assertSame(['update:HIJAU', 'face_lost:KUNING', 'recovered:HIJAU'], $kinds, 'Hanya perubahan status yang disiarkan.');
    }

    public function test_tab_away_alert_and_lecturer_or_solo_sessions_do_not_leak_events(): void
    {
        Event::fake([FocusStatusChanged::class]);
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $mhs = $this->join($room, User::factory()->create());

        // Sesi dosen sendiri di room: tidak disiarkan (hanya mahasiswa yang dipantau).
        $ds = $this->startFocus($dosen, $room);
        $this->send($dosen, $ds, 'MONITOR_START', 'HIJAU');
        $this->send($dosen, $ds, 'FACE_LOST_SHORT', 'KUNING');
        Event::assertNotDispatched(FocusStatusChanged::class);

        // Sesi solo (tanpa room): tidak disiarkan.
        $this->actingAs($mhs)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertCreated();
        $solo = StudySession::where('user_id', $mhs->id)->latest('id')->first();
        $this->send($mhs, $solo, 'MONITOR_START', 'HIJAU');
        $this->send($mhs, $solo, 'FACE_LOST_SHORT', 'KUNING');
        Event::assertNotDispatched(FocusStatusChanged::class);
    }

    public function test_broadcast_failure_never_breaks_focus_logging(): void
    {
        $dosen = User::factory()->dosen()->create();
        $room = $this->room($dosen);
        $mhs = $this->join($room, User::factory()->create());
        $s = $this->startFocus($mhs, $room);

        Event::listen(FocusStatusChanged::class, fn () => throw new \RuntimeException('Reverb mati'));
        $this->send($mhs, $s, 'MONITOR_START', 'HIJAU');
        $this->send($mhs, $s, 'FACE_LOST_SHORT', 'KUNING');

        $this->assertSame('KUNING', $s->fresh()->live_status);
        $this->assertDatabaseHas('focus_logs', ['session_id' => $s->id, 'status' => 'KUNING', 'source' => 'face']);
    }

    public function test_realtime_config_only_exposed_when_reverb_is_active(): void
    {
        $owner = User::factory()->create();
        $room = $this->room($owner);

        config(['broadcasting.default' => 'log']);
        $this->assertNull(\App\Support\Realtime::config());

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'pubkey', 'broadcasting.connections.reverb.secret' => 'topsecret']);
        $html = $this->actingAs($owner)->get(route('rooms.show', $room))->getContent();
        $this->assertStringContainsString('pubkey', $html);
        $this->assertStringNotContainsString('topsecret', $html);
    }

    /** Regresi: BROADCAST_DRIVER=reverb diisi sebelum paket Reverb/Pusher terpasang tidak boleh membuat aplikasi mogok saat boot. */
    public function test_app_boots_when_reverb_driver_is_set_before_packages_are_installed(): void
    {
        $p = new \Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'route:list', '--json'], base_path(), array_merge($_ENV, [
            'BROADCAST_DRIVER' => 'reverb', 'REVERB_APP_KEY' => 'k', 'REVERB_APP_SECRET' => 's', 'REVERB_APP_ID' => 'a',
        ]));
        $p->run();

        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
        $this->assertStringContainsString('broadcasting\/auth', $p->getOutput());
    }
}
