<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - focus_logs.source: pembeda sumber status (browser / face / orientation / system).
 * - study_sessions: salinan status fokus TERAKHIR agar dashboard dosen tidak perlu
 *   query focus_logs untuk tiap mahasiswa (hindari N+1 saat memantau 40+ orang).
 * - indeks tambahan untuk polling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('focus_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('focus_logs', 'source')) {
                $table->string('source', 12)->nullable()->after('status');
            }
        });

        Schema::table('study_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('study_sessions', 'live_status')) {
                $table->string('live_status', 8)->nullable()->after('end_reason');
                $table->string('live_source', 12)->nullable()->after('live_status');
                $table->string('live_reason', 255)->nullable()->after('live_source');
                $table->timestamp('live_changed_at')->nullable()->after('live_reason');
                $table->boolean('camera_on')->default(false)->after('live_changed_at');
            }
        });

        // Idempoten: bila indeks sudah ada (mis. setelah rollback yang menyisakan indeks), lewati.
        $add = function (string $table, array $cols, string $name) {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), 'Duplicate key name') && ! str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        };

        $add('study_sessions', ['room_id', 'status'], 'study_sessions_room_status_index');
        $add('study_sessions', ['status', 'last_heartbeat_at'], 'study_sessions_status_hb_index');
        $add('activity_logs', ['user_id', 'occurred_at'], 'activity_logs_user_time_index');
    }

    public function down(): void
    {
        // MySQL menolak menghapus indeks yang sedang dipakai foreign key (mis. user_id / room_id).
        // Indeks seperti itu dibiarkan: tidak berbahaya, dan rollback tetap tuntas.
        $drop = function (string $table, string $index) {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            } catch (\Throwable $e) {
                // dibiarkan
            }
        };

        $drop('activity_logs', 'activity_logs_user_time_index');
        $drop('study_sessions', 'study_sessions_room_status_index');
        $drop('study_sessions', 'study_sessions_status_hb_index');

        Schema::table('study_sessions', function (Blueprint $t) {
            $t->dropColumn(['live_status', 'live_source', 'live_reason', 'live_changed_at', 'camera_on']);
        });
        Schema::table('focus_logs', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
