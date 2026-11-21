<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use App\Services\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Backup, pemulihan, integritas, retensi log, dan gangguan database. Berjalan HANYA di database pengujian. */
class OpsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringContainsString('testing', DB::connection()->getDatabaseName(), 'Tes ini menghapus data: hanya boleh pada database pengujian.');
        $this->dir = sys_get_temp_dir().'/synlora-bk-'.uniqid();
        mkdir($this->dir, 0775, true);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);

        // Pemulihan memakai DROP/CREATE TABLE (DDL) yang meng-commit transaksi RefreshDatabase,
        // sehingga data uji tertinggal. Bersihkan agar tes lain tidak tercemar.
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $t = array_values((array) $row)[0];
                if ($t !== 'migrations') {
                    DB::table($t)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            // koneksi sengaja dimatikan pada tes gangguan database
        }
        parent::tearDown();
    }

    private function seedData(): void
    {
        $d = User::factory()->dosen()->create();
        $this->actingAs($d)->post(route('rooms.store'), ['name' => 'Backup Room', 'target_minutes' => 25]);
        $room = Room::latest('id')->first();
        foreach (range(1, 5) as $i) {
            $u = User::factory()->create(['name' => "Mhs \"kutip\" {$i}\nbaris baru 'x' \\ ü"]);
            $room->members()->attach($u->id, ['role' => 'member', 'focus_status' => 'ABU']);
            StudySession::begin($u, $room, 25, 5)->logFocus('HIJAU', 'ok', now(), 'browser', 'MONITOR_START');
        }
    }

    private function counts(): array
    {
        return collect(['users', 'rooms', 'room_members', 'study_sessions', 'activity_logs', 'focus_logs'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_S59_S60_backup_then_restore_brings_back_deleted_data_exactly(): void
    {
        $this->seedData();
        $before = $this->counts();
        $names = DB::table('users')->orderBy('id')->pluck('name')->all();

        $file = DatabaseBackup::create($this->dir);
        $this->assertFileExists($file);
        $man = array_intersect_key(DatabaseBackup::verify($file)['tables'], $before);
        ksort($man); $exp = $before; ksort($exp);
        $this->assertSame($exp, $man);

        DB::table('focus_logs')->delete(); DB::table('activity_logs')->delete(); DB::table('study_sessions')->delete();
        DB::table('room_members')->delete(); DB::table('rooms')->delete(); DB::table('users')->delete();
        $this->assertSame(0, DB::table('users')->count());

        $r = DatabaseBackup::restore($file, $this->dir);
        $this->assertFileExists($r['safety']);
        $this->assertSame($before, $this->counts());
        $this->assertSame($names, DB::table('users')->orderBy('id')->pluck('name')->all());   // karakter khusus utuh
    }

    public function test_S42_backup_fails_cleanly_when_folder_not_writable(): void
    {
        $this->seedData();
        $bad = $this->dir.'/tidak/ada/dan/dikunci';
        file_put_contents($this->dir.'/tidak', 'ini file, bukan folder');     // mkdir pasti gagal
        $code = Artisan::call('synlora:backup', ['--dir' => $bad]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('GAGAL', Artisan::output());
        $this->assertSame([], glob($this->dir.'/*.sql.gz*'));
    }

    public function test_S43_corrupt_or_tampered_backup_is_rejected_and_db_untouched(): void
    {
        $this->seedData();
        $before = $this->counts();
        $file = DatabaseBackup::create($this->dir);

        $bad = $this->dir.'/synlora-rusak.sql.gz';
        copy($file, $bad); copy(substr($file, 0, -7).'.json', substr($bad, 0, -7).'.json');
        file_put_contents($bad, 'x', FILE_APPEND);
        $this->assertSame(1, Artisan::call('synlora:restore', ['file' => $bad, '--force' => true]));
        $this->assertStringContainsString('Checksum', Artisan::output());

        $this->assertSame(1, Artisan::call('synlora:restore', ['file' => $this->dir.'/tidak-ada.sql.gz', '--force' => true]));
        $this->assertSame($before, $this->counts());
    }

    public function test_S43_failed_restore_rolls_back_to_previous_state(): void
    {
        $this->seedData();
        $file = DatabaseBackup::create($this->dir);

        // Manifest dimanipulasi (jumlah baris palsu) sehingga verifikasi pasca-pemulihan gagal.
        $mf = substr($file, 0, -7).'.json';
        $m = json_decode(file_get_contents($mf), true); $m['tables']['users'] += 99; file_put_contents($mf, json_encode($m));

        DB::table('focus_logs')->delete();   // keadaan "sekarang" yang harus dikembalikan
        $now = $this->counts();
        $this->expectException(\RuntimeException::class);
        try {
            DatabaseBackup::restore($file, $this->dir);
        } finally {
            $this->assertSame($now, $this->counts());
        }
    }

    public function test_backup_retention_keeps_newest(): void
    {
        $this->seedData();
        foreach (range(1, 4) as $i) { DatabaseBackup::create($this->dir); usleep(1100000); }
        $this->assertSame(2, DatabaseBackup::prune(2, $this->dir));
        $this->assertCount(2, glob($this->dir.'/synlora-*.sql.gz'));
        $this->assertCount(2, glob($this->dir.'/synlora-*.json'));
    }

    public function test_S33_integrity_check_finds_and_fixes_inconsistencies(): void
    {
        $u = User::factory()->create();
        foreach ([1, 2] as $i) {
            DB::table('study_sessions')->insert(['user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5, 'started_at' => now(), 'phase_started_at' => now(), 'phase' => 'FOCUS', 'status' => 'ACTIVE', 'last_heartbeat_at' => now(), 'total_effective_seconds' => 0, 'completed_pomodoros' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('study_sessions')->insert(['user_id' => $u->id, 'focus_duration_minutes' => 25, 'break_duration_minutes' => 5, 'started_at' => now()->subDay(), 'phase_started_at' => now()->subDay(), 'phase' => 'FOCUS', 'status' => 'ENDED', 'total_effective_seconds' => 0, 'completed_pomodoros' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('rooms/9/materials/yatim.pdf', 'x');

        $this->assertSame(1, Artisan::call('synlora:check-integrity'));           // ada temuan
        $this->assertSame(0, Artisan::call('synlora:check-integrity', ['--fix' => true]));
        $this->assertSame(1, StudySession::where('user_id', $u->id)->open()->count());
        $this->assertSame(0, DB::table('study_sessions')->where('status', 'ENDED')->whereNull('ended_at')->count());
        Storage::disk('local')->assertMissing('rooms/9/materials/yatim.pdf');
        $this->assertSame(0, Artisan::call('synlora:check-integrity'));           // bersih
    }

    public function test_S41_log_pruning_is_off_by_default_and_safe(): void
    {
        $u = User::factory()->create(); $s = StudySession::begin($u, null, 25, 5);
        DB::table('activity_logs')->where('session_id', $s->id)->update(['occurred_at' => now()->subDays(400)]);
        $n = DB::table('activity_logs')->count();

        $this->assertSame(0, Artisan::call('synlora:prune-logs'));
        $this->assertSame($n, DB::table('activity_logs')->count());                       // default: tidak menghapus
        $this->assertSame(1, Artisan::call('synlora:prune-logs', ['--days' => 5]));      // terlalu pendek ditolak
        $this->assertSame(0, Artisan::call('synlora:prune-logs', ['--days' => 365]));
        $this->assertSame(0, DB::table('activity_logs')->where('occurred_at', '<', now()->subDays(365))->count());
        $this->assertDatabaseHas('study_sessions', ['id' => $s->id]);                     // sesi/statistik tetap
    }

    public function test_S32_S34_S60_database_down_gives_friendly_503_and_recovers(): void
    {
        $this->get('/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok']);

        $orig = Config::get('database.connections.mysql');
        Config::set('database.connections.mysql.port', 1);
        Config::set('database.connections.mysql.host', '127.0.0.1');
        DB::purge('mysql');

        $this->get('/health')->assertStatus(503)->assertJson(['database' => 'down']);
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(503)->assertSee('Server sedang gangguan')->assertDontSee('SQLSTATE');
        $this->postJson('/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(503)->assertJson(['code' => 'db_unavailable']);

        Config::set('database.connections.mysql', $orig);
        DB::purge('mysql');
        $this->get('/health')->assertOk();
    }

    public function test_landing_still_works_when_database_is_down(): void
    {
        $orig = Config::get('database.connections.mysql');
        Config::set('database.connections.mysql.port', 1);
        DB::purge('mysql');
        $this->get('/')->assertOk()->assertSee('tetap terarah.');
        Config::set('database.connections.mysql', $orig);
        DB::purge('mysql');
    }
}
