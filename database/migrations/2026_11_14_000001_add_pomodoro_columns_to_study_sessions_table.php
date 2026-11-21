<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom yang dibutuhkan agar Pomodoro berjalan sinkron dengan waktu server.
 * Migration lama TIDAK diubah; semua perubahan ada di file baru ini dan aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('study_sessions', 'phase')) {
                $table->string('phase', 10)->default('FOCUS')->after('status');
            }
            if (! Schema::hasColumn('study_sessions', 'phase_started_at')) {
                $table->timestamp('phase_started_at')->nullable()->after('phase');
            }
            if (! Schema::hasColumn('study_sessions', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('phase_started_at');
            }
            if (! Schema::hasColumn('study_sessions', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('paused_at');
            }
            if (! Schema::hasColumn('study_sessions', 'completed_pomodoros')) {
                $table->unsignedInteger('completed_pomodoros')->default(0)->after('total_effective_seconds');
            }
            if (! Schema::hasColumn('study_sessions', 'end_reason')) {
                $table->string('end_reason', 20)->nullable()->after('completed_pomodoros');
            }
        });

        // Sesi lama (sebelum migration ini) dianggap mulai fase fokus saat started_at.
        \Illuminate\Support\Facades\DB::table('study_sessions')
            ->whereNull('phase_started_at')
            ->update(['phase_started_at' => \Illuminate\Support\Facades\DB::raw('started_at')]);

        Schema::table('study_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'study_sessions_user_status_index');
            $table->index(['user_id', 'started_at'], 'study_sessions_user_started_index');
        });
    }

    public function down(): void
    {
        // Indeks pengganti untuk foreign key user_id sebelum indeks komposit dihapus (syarat MySQL).
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->index('user_id', 'study_sessions_user_id_index');
        });

        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropIndex('study_sessions_user_status_index');
            $table->dropIndex('study_sessions_user_started_index');
        });

        Schema::table('study_sessions', function (Blueprint $table) {
            foreach (['phase', 'phase_started_at', 'paused_at', 'last_heartbeat_at', 'completed_pomodoros', 'end_reason'] as $col) {
                if (Schema::hasColumn('study_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
