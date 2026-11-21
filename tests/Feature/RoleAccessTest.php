<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use App\Services\LecturerMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Registrasi terbuka untuk mahasiswa & dosen, dan hak monitoring berbasis keanggotaan room:
 * hanya DOSEN yang menjadi anggota room yang melihat data monitoring MAHASISWA di room itu.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeRoom(User $owner, string $name = 'Kelas A'): Room
    {
        $this->actingAs($owner)->post(route('rooms.store'), ['name' => $name, 'target_minutes' => 25])->assertRedirect();

        return Room::latest('id')->first();
    }

    private function join(User $u, Room $r): void
    {
        $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => $r->code])->assertRedirect(route('rooms.show', $r));
    }

    private function dosen(string $name = 'Dosen'): User
    {
        return User::factory()->dosen()->create(['name' => $name]);
    }

    private function mhs(string $name = 'Mhs'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    // ------------------------------------------------------------ registrasi

    public function test_both_roles_register_and_land_on_their_dashboard(): void
    {
        config(['synlora.dosen_registration' => 'open']); // mode terbuka eksplisit; mode bawaan diuji di SynloraV2Test
        $this->post('/register', ['name' => 'Mhs Baru', 'role' => 'mahasiswa', 'email' => 'm@example.com', 'nim' => '2203111009', 'kelas' => 'A',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect('/dashboard');
        $this->assertSame('mahasiswa', User::where('email', 'm@example.com')->value('role'));
        auth()->logout();

        $this->post('/register', ['name' => 'Dosen Baru', 'role' => 'dosen', 'email' => 'd@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect(route('dosen.monitor'));
        $this->assertSame('dosen', User::where('email', 'd@example.com')->value('role'));
    }

    public function test_register_rejects_unknown_role(): void
    {
        $this->post('/register', ['name' => 'X', 'role' => 'admin', 'email' => 'x@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    // ------------------------------------------------ room: kedua peran setara

    public function test_both_roles_can_create_manage_join_and_delete_rooms(): void
    {
        foreach ([$this->mhs('Pemilik M'), $this->dosen('Pemilik D')] as $owner) {
            $room = $this->makeRoom($owner, 'Room '.$owner->name);
            $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $owner->id, 'role' => 'owner']);

            $this->actingAs($owner)->patch(route('rooms.update', $room), ['name' => 'Baru', 'target_minutes' => 30, 'max_members' => 10])->assertRedirect();
            $this->assertSame('Baru', $room->fresh()->name);

            $joiner = $this->mhs();
            $this->join($joiner, $room);
            $this->actingAs($joiner)->patch(route('rooms.update', $room), ['name' => 'Hack', 'target_minutes' => 30, 'max_members' => 10])->assertForbidden();

            $this->actingAs($owner)->delete(route('rooms.destroy', $room), ['confirm_name' => 'Baru'])->assertRedirect(route('dashboard'));
            $this->assertNull(Room::find($room->id));
        }
    }

    public function test_dosen_can_join_room_by_code_and_use_pomodoro(): void
    {
        $owner = $this->mhs('Ketua');
        $room = $this->makeRoom($owner);
        $dosen = $this->dosen();
        $this->join($dosen, $room);

        $this->actingAs($dosen)->postJson(route('rooms.sessions.store', $room))->assertSuccessful();
        $this->actingAs($dosen)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5])->assertSuccessful();
    }

    // -------------------------------------- mahasiswa: hanya data miliknya

    public function test_student_owner_cannot_see_other_students_monitoring(): void
    {
        $owner = $this->mhs('Ketua');
        $a = $this->mhs('Mhs A');
        $room = $this->makeRoom($owner);
        $this->join($a, $room);

        $urlsA = $this->actingAs($a)->postJson(route('rooms.sessions.store', $room))->json('urls');
        $this->actingAs($a)->postJson($urlsA['events'], ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH', 'details' => 'Pindah tab']);
        $this->actingAs($a)->postJson($urlsA['finish']);

        // Feed: owner mahasiswa hanya melihat aktivitas sendiri, tanpa status fokus anggota lain
        $feed = $this->actingAs($owner)->getJson(route('rooms.getActivities', $room))->assertOk()->assertJsonPath('scope', 'self');
        $this->assertTrue(collect($feed->json('activities'))->every(fn ($x) => $x['is_me']));
        $this->assertNull(collect($feed->json('members'))->firstWhere('name', 'Mhs A')['focus_status']);

        // Rekap sesi anggota lain: terlarang, walau owner room
        $this->actingAs($owner)->get($urlsA['recap'])->assertForbidden();

        // Halaman room owner tidak menampilkan "Sesi Terbaru di Room Ini"
        $this->actingAs($owner)->get(route('rooms.show', $room))->assertOk()->assertDontSee('Sesi Terbaru di Room Ini');

        // Owner tetap bisa mengelola room
        $this->actingAs($owner)->patch(route('rooms.update', $room), ['name' => 'Ok', 'target_minutes' => 25, 'max_members' => 10])->assertRedirect();
    }

    public function test_student_cannot_reach_lecturer_endpoints_or_history_by_url_manipulation(): void
    {
        $dosen = $this->dosen();
        $a = $this->mhs('Mhs A');
        $b = $this->mhs('Mhs B');
        $room = $this->makeRoom($dosen);
        $this->join($a, $room);
        $this->join($b, $room);

        foreach (['dosen.monitor', 'dosen.data', 'dosen.export'] as $r) {
            $this->actingAs($a)->get(route($r))->assertForbidden();
        }
        $this->actingAs($a)->getJson(route('dosen.history', $b))->assertForbidden();
        $this->actingAs($a)->getJson(route('dosen.history', $a))->assertForbidden();
        $this->actingAs($a)->getJson(route('dosen.data', ['room' => $room->code]))->assertForbidden();

        // Sesi mahasiswa lain: state/events/rekap tertutup
        $urlsB = $this->actingAs($b)->postJson(route('rooms.sessions.store', $room))->json('urls');
        $this->actingAs($a)->get($urlsB['recap'])->assertForbidden();
        $this->actingAs($a)->getJson($urlsB['state'])->assertForbidden();
        $this->actingAs($a)->postJson($urlsB['events'], ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH'])->assertForbidden();
    }

    // ------------------------------- dosen: mahasiswa di room yang sama saja

    public function test_dosen_joined_to_student_owned_room_sees_that_rooms_students(): void
    {
        $owner = $this->mhs('Ketua');
        $a = $this->mhs('Mhs A');
        $room = $this->makeRoom($owner);
        $this->join($a, $room);
        $dosen = $this->dosen();
        $this->join($dosen, $room);

        $urlsA = $this->actingAs($a)->postJson(route('rooms.sessions.store', $room))->json('urls');
        $this->actingAs($a)->postJson($urlsA['events'], ['event_type' => 'TAB_SWITCH_OUT', 'status' => 'MERAH', 'details' => 'Pindah tab']);
        $this->travel(12)->seconds();
        $this->actingAs($a)->postJson($urlsA['finish']);

        // Feed room: dosen melihat aktivitas & status fokus mahasiswa
        $feed = $this->actingAs($dosen)->getJson(route('rooms.getActivities', $room))->assertOk()->assertJsonPath('scope', 'room');
        $this->assertTrue(collect($feed->json('activities'))->contains(fn ($x) => $x['user_name'] === 'Mhs A'));
        $this->assertNotNull(collect($feed->json('members'))->firstWhere('name', 'Mhs A')['focus_status']);

        // Rekap sesi, halaman room, dashboard monitor, dan riwayat
        $this->actingAs($dosen)->get($urlsA['recap'])->assertOk();
        $this->actingAs($dosen)->get(route('rooms.show', $room))->assertOk()->assertSee('Sesi Terbaru di Room Ini');
        $ids = collect($this->actingAs($dosen)->getJson(route('dosen.data'))->assertOk()->json('students'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->actingAs($dosen)->getJson(route('dosen.history', $a))->assertOk()->assertJsonStructure(['student', 'days', 'sessions', 'activities']);
    }

    public function test_dosen_cannot_see_students_outside_shared_rooms(): void
    {
        $dosen = $this->dosen('Dosen 1');
        $inMine = $this->mhs('Di room saya');
        $roomMine = $this->makeRoom($dosen, 'Milik dosen');
        $this->join($inMine, $roomMine);

        $otherOwner = $this->mhs('Ketua lain');
        $outside = $this->mhs('Di luar');
        $roomOther = $this->makeRoom($otherOwner, 'Room lain');
        $this->join($outside, $roomOther);

        // Mahasiswa yang ikut dua room: satu bersama dosen, satu tidak
        $both = $this->mhs('Di dua room');
        $this->join($both, $roomMine);
        $this->join($both, $roomOther);
        $urlsOther = $this->actingAs($both)->postJson(route('rooms.sessions.store', $roomOther))->json('urls');
        $this->actingAs($both)->postJson($urlsOther['finish']);
        $urlsMine = $this->actingAs($both)->postJson(route('rooms.sessions.store', $roomMine))->json('urls');
        $this->actingAs($both)->postJson($urlsMine['finish']);

        $ids = collect($this->actingAs($dosen)->getJson(route('dosen.data'))->json('students'))->pluck('id')->all();
        $this->assertContains($inMine->id, $ids);
        $this->assertContains($both->id, $ids);
        $this->assertNotContains($outside->id, $ids);

        $this->actingAs($dosen)->getJson(route('dosen.history', $outside))->assertForbidden();
        $this->actingAs($dosen)->getJson(route('dosen.history', $otherOwner))->assertForbidden();

        // Riwayat mahasiswa dua room hanya memuat sesi di room bersama
        $hist = $this->actingAs($dosen)->getJson(route('dosen.history', $both))->assertOk()->json('sessions');
        $this->assertCount(1, $hist);
        $this->assertSame('Milik dosen', $hist[0]['room']);

        // Rekap sesi di room yang tidak diikuti dosen: terlarang
        $this->actingAs($dosen)->get($urlsOther['recap'])->assertForbidden();
        $this->actingAs($dosen)->get($urlsMine['recap'])->assertOk();

        // Feed room yang bukan miliknya/tak diikuti
        $this->actingAs($dosen)->getJson(route('rooms.getActivities', $roomOther))->assertForbidden();
    }

    public function test_dosen_loses_access_after_leaving_room_and_cannot_view_other_dosen(): void
    {
        $owner = $this->mhs('Ketua');
        $a = $this->mhs('Mhs A');
        $room = $this->makeRoom($owner);
        $this->join($a, $room);
        $d1 = $this->dosen('D1');
        $d2 = $this->dosen('D2');
        $this->join($d1, $room);
        $this->join($d2, $room);

        $this->actingAs($d1)->getJson(route('dosen.history', $a))->assertOk();
        // dosen lain bukan "mahasiswa": datanya tidak dapat dilihat dosen
        $this->actingAs($d1)->getJson(route('dosen.history', $d2))->assertForbidden();
        $ids = collect($this->actingAs($d1)->getJson(route('dosen.data'))->json('students'))->pluck('id')->all();
        $this->assertNotContains($d2->id, $ids);

        $this->actingAs($d1)->post(route('rooms.leave', $room))->assertRedirect();
        $this->actingAs($d1)->getJson(route('dosen.history', $a))->assertForbidden();
        $this->assertSame([], $this->actingAs($d1)->getJson(route('dosen.data'))->json('students'));
        $this->assertFalse(LecturerMonitor::mayView($d1, $a));
    }

    public function test_dosen_owner_still_manages_room_and_sees_students(): void
    {
        $dosen = $this->dosen();
        $a = $this->mhs('Mhs A');
        $room = $this->makeRoom($dosen);
        $this->join($a, $room);

        $this->actingAs($dosen)->patch(route('rooms.update', $room), ['name' => 'Kelas A2', 'target_minutes' => 25, 'max_members' => 20])->assertRedirect();
        $this->actingAs($dosen)->delete(route('rooms.members.remove', [$room, $a->id]))->assertRedirect();
        $this->assertSame([], $this->actingAs($dosen)->getJson(route('dosen.data'))->json('students'));
    }
}
