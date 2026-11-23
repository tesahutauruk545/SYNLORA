<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi bug: SQLSTATE[42S22] Unknown column 'mode' in 'INSERT INTO' saat memulai Pomodoro Solo.
 */
class PomodoroSoloSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['mode', 'study_goal', 'consented_at', 'heartbeat_lost_count', 'multi_device_count',
        'multi_tab_count', 'camera_issue_count', 'reflection', 'ai_usage', 'ai_usage_note', 'reflected_at'];

    public function test_solo_flow_persists_all_columns_and_appears_in_history(): void
    {
        $u = User::factory()->create();

        $this->actingAs($u)->get(route('pomodoro.index'))->assertOk();

        $r = $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 25, 'break_minutes' => 5, 'study_goal' => 'Selesaikan latihan bab 3'])->assertCreated();

        $row = DB::table('study_sessions')->where('id', $r->json('session.id'))->first();
        $this->assertSame('standard', $row->mode);
        $this->assertSame('Selesaikan latihan bab 3', $row->study_goal);
        $this->assertNull($row->consented_at);
        $this->assertNull($row->room_id);

        $urls = $r->json('urls');
        $this->actingAs($u)->postJson($urls['pause'])->assertOk()->assertJsonPath('session.status', 'PAUSED');
        $this->actingAs($u)->postJson($urls['resume'])->assertOk()->assertJsonPath('session.status', 'ACTIVE');
        $this->actingAs($u)->postJson($urls['heartbeat'], ['hidden' => false])->assertOk();
        $this->actingAs($u)->postJson($urls['events'], ['event_type' => 'MONITOR_START'])->assertOk();
        $this->actingAs($u)->postJson($urls['finish'])->assertOk()->assertJsonPath('session.status', 'ENDED');

        $this->actingAs($u)->get(route('sessions.index'))->assertOk();
        $this->actingAs($u)->get($urls['recap'])->assertOk();
    }

    public function test_repair_migration_restores_missing_columns_without_losing_data(): void
    {
        $u = User::factory()->create();
        $old = StudySession::begin($u, null, 25, 5, ['study_goal' => 'lama']);
        $old->finish('manual');

        // Simulasikan database yang kolomnya hilang (migration strict belum jalan / hasil restore lama).
        Schema::table('study_sessions', fn ($t) => $t->dropColumn(self::COLUMNS));
        Schema::table('rooms', fn ($t) => $t->dropColumn('strict_mode'));
        $this->assertFalse(Schema::hasColumn('study_sessions', 'mode'));

        $migration = require database_path('migrations/2026_11_23_000001_repair_study_sessions_missing_columns.php');
        $migration->up();
        $migration->up(); // idempoten

        foreach (self::COLUMNS as $col) {
            $this->assertTrue(Schema::hasColumn('study_sessions', $col), "kolom $col harus ada");
        }
        $this->assertTrue(Schema::hasColumn('rooms', 'strict_mode'));

        // Data lama utuh, dan sesi baru dapat dibuat.
        $this->assertSame(StudySession::ENDED, $old->fresh()->status);
        $this->assertSame('standard', $old->fresh()->mode);
        $this->actingAs($u)->postJson(route('pomodoro.start'), ['focus_minutes' => 10, 'break_minutes' => 2])->assertCreated();
    }
}
