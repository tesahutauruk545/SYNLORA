<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Room;
use App\Models\RoomMonitorAlert;
use App\Models\RoomMonitorState;
use App\Models\StudySession;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoomMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $dosen;
    private User $mhs;
    private User $mhs2;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dosen = User::factory()->dosen()->create(['name' => 'Bu Dosen']);
        $this->mhs = User::factory()->create(['name' => 'Mhs Satu'])->fresh();
        $this->mhs2 = User::factory()->create(['name' => 'Mhs Dua'])->fresh();
        $this->actingAs($this->dosen)->post(route('rooms.store'), ['name' => 'Kelas A', 'target_minutes' => 25])->assertRedirect();
        $this->room = Room::latest('id')->first();
        foreach ([$this->mhs, $this->mhs2] as $u) {
            $this->actingAs($u)->post(route('rooms.joinRoom'), ['code' => $this->room->code])->assertRedirect();
        }
    }

    private function ev(User $u, string $type, array $extra = [], ?Room $room = null)
    {
        return $this->actingAs($u)->postJson(route('rooms.monitor.event', $room ?? $this->room), ['type' => $type] + $extra);
    }

    // ------------------------------------------------------------- notifikasi tugas

    public function test_new_assignment_notifies_only_students_of_that_room_without_duplicates(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->dosen)->post(route('assignments.store', $this->room), ['title' => 'Tugas 1', 'due_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();

        $this->assertSame(1, UserNotification::where('user_id', $this->mhs->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->mhs2->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $other->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->dosen->id)->count());

        $n = UserNotification::where('user_id', $this->mhs->id)->first();
        $this->assertSame('Tugas 1', $n->data['title']);
        $this->assertSame('Bu Dosen', $n->data['lecturer']);
        $this->assertSame('Kelas A', $n->data['room']);
        $this->assertNotNull($n->data['due_at']);
        $this->assertStringContainsString('/assignments/', $n->action_url);

        // Pemberitahuan ulang untuk tugas yang sama tidak menggandakan.
        \App\Services\NotificationCenter::assignmentPublished(Assignment::first(), $this->room, $this->dosen);
        $this->assertSame(1, UserNotification::where('user_id', $this->mhs->id)->count());
    }

    public function test_notifications_persist_have_read_state_and_are_private(): void
    {
        $this->actingAs($this->dosen)->post(route('assignments.store', $this->room), ['title' => 'T'])->assertRedirect();

        $res = $this->actingAs($this->mhs)->getJson(route('notifications.index'))->assertOk();
        $this->assertSame(1, $res->json('unread'));
        $id = $res->json('items.0.id');

        // Refresh (permintaan baru): masih ada.
        $this->actingAs($this->mhs)->getJson(route('notifications.index'))->assertJsonPath('unread', 1);

        // Milik orang lain tidak dapat ditandai dibaca.
        $this->actingAs($this->mhs2)->postJson(route('notifications.read', $id))->assertNotFound();

        $this->actingAs($this->mhs)->postJson(route('notifications.read', $id))->assertOk();
        $this->actingAs($this->mhs)->getJson(route('notifications.index'))->assertJsonPath('unread', 0)->assertJsonPath('items.0.read', true);
        $this->getJson(route('notifications.index'))->assertOk();
        auth()->logout();
        $this->getJson(route('notifications.index'))->assertUnauthorized();
    }

    // ------------------------------------------------------------- pemantauan

    public function test_monitor_works_without_pomodoro_session_and_ignores_vision_when_camera_off(): void
    {
        $this->assertSame(0, StudySession::count());
        $this->ev($this->mhs, 'FACE_NOT_FACING')->assertOk()->assertJsonPath('ignored', 'camera_not_on');
        $this->assertSame(0, RoomMonitorAlert::count());

        $this->ev($this->mhs, 'CAMERA_ON')->assertOk();
        $this->ev($this->mhs, 'FACE_FACING')->assertOk();
        $this->assertSame('facing', RoomMonitorState::first()->face_state);
        $this->assertSame(0, StudySession::count()); // tidak membuat/memerlukan sesi Pomodoro
    }

    public function test_turning_alerts_lecturer_once_with_dedup_and_recovers(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'FACE_TURN_LEFT');
        $this->ev($this->mhs, 'FACE_TURN_LEFT'); // berulang: dedup
        $this->assertSame(1, RoomMonitorAlert::where('type', 'turn_left')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->dosen->id)->where('type', 'monitor')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->mhs2->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->mhs->id)->count());

        // Kiri -> kanan: peringatan lama ditutup, yang baru dibuka.
        $this->ev($this->mhs, 'FACE_TURN_RIGHT');
        $this->assertNotNull(RoomMonitorAlert::where('type', 'turn_left')->first()->resolved_at);
        $this->assertNull(RoomMonitorAlert::where('type', 'turn_right')->first()->resolved_at);

        $this->ev($this->mhs, 'FACE_FACING');
        $this->assertNotNull(RoomMonitorAlert::where('type', 'turn_right')->first()->resolved_at);
        $this->assertSame('facing', RoomMonitorState::first()->face_state);
    }

    public function test_cooldown_suppresses_repeated_notifications_but_history_is_kept(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'FACE_NOT_FOUND');
        $this->ev($this->mhs, 'FACE_FACING');
        $this->ev($this->mhs, 'FACE_NOT_FOUND'); // dalam cooldown
        $this->assertSame(2, RoomMonitorAlert::where('type', 'face_not_found')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->dosen->id)->count());
    }

    public function test_phone_requires_confidence_and_records_orientation_and_confidence(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'PHONE_DETECTED', ['orientation' => 'vertical', 'confidence' => 0.2])->assertJsonPath('ignored', 'below_threshold');
        $this->assertSame(0, RoomMonitorAlert::count());

        $this->ev($this->mhs, 'PHONE_DETECTED', ['orientation' => 'horizontal', 'confidence' => 0.8])->assertOk();
        $a = RoomMonitorAlert::first();
        $this->assertSame('phone_horizontal', $a->type);
        $this->assertSame('horizontal', $a->orientation);
        $this->assertEqualsWithDelta(0.8, $a->confidence, 0.001);
        $this->assertStringContainsString('hp:detected', $a->status_snapshot);

        $this->ev($this->mhs, 'PHONE_CLEARED');
        $this->assertNotNull($a->fresh()->resolved_at);
        $this->assertSame('none', RoomMonitorState::first()->phone_state);

        $this->ev($this->mhs, 'PHONE_DETECTED', ['confidence' => 1.7])->assertStatus(422);
    }

    public function test_tab_duration_comes_from_server_clock_and_camera_stays_on(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'TAB_LEFT');
        $this->ev($this->mhs, 'TAB_LEFT'); // dedup
        Carbon::setTestNow('2026-11-24 10:00:42');
        $this->ev($this->mhs, 'TAB_RETURNED');
        Carbon::setTestNow();

        $st = RoomMonitorState::first();
        $this->assertSame(1, $st->tab_away_count);
        $this->assertSame(42, $st->tab_away_seconds);
        $this->assertSame('on', $st->camera_state); // meninggalkan tab tidak mematikan kamera
        $this->assertSame(42, RoomMonitorAlert::where('type', 'tab_left')->first()->duration_seconds);
    }

    public function test_heartbeat_cross_checks_hidden_tab(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'HEARTBEAT', ['hidden' => true]);
        $this->assertSame('away', RoomMonitorState::first()->tab_state);
        $this->ev($this->mhs, 'HEARTBEAT', ['hidden' => false]);
        $this->assertSame('visible', RoomMonitorState::first()->tab_state);
    }

    public function test_camera_lost_is_technical_and_alerts_lecturer(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'CAMERA_LOST');
        $this->assertSame('lost', RoomMonitorState::first()->camera_state);
        $this->assertSame(1, RoomMonitorAlert::where('type', 'camera_lost')->count());
        $this->ev($this->mhs, 'CAMERA_ON'); // diaktifkan kembali
        $this->assertSame('on', RoomMonitorState::first()->camera_state);
        $this->assertNotNull(RoomMonitorAlert::where('type', 'camera_lost')->first()->resolved_at);
    }

    public function test_pomodoro_and_assignment_flow_do_not_stop_monitoring(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->actingAs($this->mhs)->postJson(route('rooms.sessions.store', $this->room), ['break_minutes' => 5])->assertCreated();
        $this->actingAs($this->dosen)->post(route('assignments.store', $this->room), ['title' => 'Tugas'])->assertRedirect();
        $a = Assignment::first();

        $this->actingAs($this->mhs)->get(route('assignments.show', [$this->room, $a]))->assertOk();
        $st = RoomMonitorState::where('user_id', $this->mhs->id)->first();
        $this->assertSame('on', $st->camera_state);
        $this->assertSame('opened', $st->task_state);

        $this->actingAs($this->mhs)->post(route('assignments.submit', [$this->room, $a]), ['notes' => 'jawaban'])->assertRedirect();
        $st->refresh();
        $this->assertSame('submitted', $st->task_state);
        $this->assertSame('on', $st->camera_state);

        // Event pemantauan tetap diterima saat Pomodoro berjalan.
        $this->ev($this->mhs, 'FACE_NOT_FACING')->assertOk()->assertJsonMissing(['ignored' => 'camera_not_on']);
        $this->assertSame(1, RoomMonitorAlert::where('type', 'not_facing')->count());
    }

    public function test_access_control_for_events_and_dashboard(): void
    {
        $outsider = User::factory()->create()->fresh();
        $this->ev($outsider, 'CAMERA_ON')->assertForbidden();
        $this->ev($this->dosen, 'CAMERA_ON')->assertOk()->assertJsonPath('ignored', 'not_monitored');
        $this->ev($this->mhs, 'BUKAN_EVENT')->assertStatus(422);

        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'FACE_TURN_LEFT');

        $this->actingAs($this->mhs)->getJson(route('dosen.alerts'))->assertForbidden();
        $this->actingAs($this->mhs)->getJson(route('dosen.data'))->assertForbidden();

        $otherDosen = User::factory()->dosen()->create();
        $this->actingAs($otherDosen)->getJson(route('dosen.alerts'))->assertOk()->assertJsonCount(0, 'alerts');
        $this->actingAs($this->dosen)->getJson(route('dosen.alerts'))->assertOk()->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.type', 'turn_left');
    }

    public function test_dashboard_summary_and_student_rows_reflect_real_monitor_state(): void
    {
        $this->ev($this->mhs, 'CAMERA_ON');
        $this->ev($this->mhs, 'FACE_FACING');
        $this->ev($this->mhs2, 'CAMERA_ON');
        $this->ev($this->mhs2, 'TAB_LEFT');

        $d = $this->actingAs($this->dosen)->getJson(route('dosen.data'))->assertOk()->json();
        $this->assertSame(2, $d['summary']['total']);
        $this->assertSame(2, $d['summary']['mon_active']);
        $this->assertSame(1, $d['summary']['mon_focus']);
        $this->assertSame(1, $d['summary']['mon_tab_away']);

        $row = collect($d['students'])->firstWhere('id', $this->mhs2->id);
        $this->assertSame('away', $row['mon']['tab']);

        $this->ev($this->mhs, 'PHONE_DETECTED', ['orientation' => 'vertical', 'confidence' => 0.9]);
        $d = $this->actingAs($this->dosen)->getJson(route('dosen.data'))->json();
        $this->assertSame(1, $d['summary']['mon_undetected']);
        $this->assertSame(1, $d['summary']['mon_phone']);
    }
}
