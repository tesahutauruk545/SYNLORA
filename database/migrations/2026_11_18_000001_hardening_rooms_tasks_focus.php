<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - rooms.max_members            : kapasitas room.
 * - assignments.allow_late       : dosen dapat menutup pengumpulan setelah deadline.
 * - study_sessions.live_event    : jenis event terakhir (server bisa menaikkan KUNING -> MERAH tanpa percaya timer klien).
 * - study_sessions.camera_state  : off | on | denied | unavailable | error | model_failed.
 * - activity_logs.room_id        : ON DELETE SET NULL (sebelumnya CASCADE: menghapus room ikut menghapus riwayat aktivitas belajar anggota).
 * - menutup sesi ganda yang mungkin sudah ada (satu pengguna = satu sesi terbuka).
 * Tidak ada data yang dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $t) {
            if (! Schema::hasColumn('rooms', 'max_members')) {
                $t->unsignedSmallInteger('max_members')->default(60)->after('target_minutes');
            }
        });

        Schema::table('assignments', function (Blueprint $t) {
            if (! Schema::hasColumn('assignments', 'allow_late')) {
                $t->boolean('allow_late')->default(true)->after('due_at');
            }
        });

        Schema::table('study_sessions', function (Blueprint $t) {
            if (! Schema::hasColumn('study_sessions', 'live_event')) {
                $t->string('live_event', 24)->nullable()->after('live_reason');
                $t->string('camera_state', 16)->default('off')->after('camera_on');
            }
        });

        // activity_logs.room_id: CASCADE -> SET NULL
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->dropForeign(['room_id']);
        });
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });

        // Sesi ganda lama: pertahankan yang terbaru, tutup sisanya.
        $dupes = DB::table('study_sessions')->where('status', '!=', 'ENDED')
            ->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->pluck('user_id');
        foreach ($dupes as $uid) {
            $keep = DB::table('study_sessions')->where('user_id', $uid)->where('status', '!=', 'ENDED')->orderByDesc('id')->value('id');
            DB::table('study_sessions')->where('user_id', $uid)->where('status', '!=', 'ENDED')->where('id', '!=', $keep)
                ->update(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'duplicate_closed']);
        }
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->dropForeign(['room_id']);
        });
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
        Schema::table('study_sessions', fn (Blueprint $t) => $t->dropColumn(['live_event', 'camera_state']));
        Schema::table('assignments', fn (Blueprint $t) => $t->dropColumn('allow_late'));
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('max_members'));
    }
};
