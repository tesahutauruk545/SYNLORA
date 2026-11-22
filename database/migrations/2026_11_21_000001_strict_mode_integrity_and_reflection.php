<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STRICT SUPERVISED MODE, penghitung integritas sesi, dan refleksi belajar.
 * Hanya MENAMBAH kolom (dengan nilai bawaan); tidak ada data yang diubah atau dihapus.
 *
 * rooms.strict_mode / rooms.ai_policy : kebijakan dosen/owner untuk sesi di room ini.
 * study_sessions.mode                  : standard | strict (disalin dari room saat sesi dimulai).
 * study_sessions.*_count               : penghitung ringan agar dashboard tidak perlu membaca seluruh log.
 * study_sessions.study_goal/reflection/ai_usage*: tujuan belajar, refleksi, dan deklarasi penggunaan AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent PER KOLOM: aman dijalankan ulang, dan aman bila sebagian kolom sudah ada di hosting.
        $this->addMissing('rooms', [
            'strict_mode' => fn (Blueprint $t) => $t->boolean('strict_mode')->default(false),
            'ai_policy' => fn (Blueprint $t) => $t->string('ai_policy', 12)->default('declare'), // forbidden | declare | allowed
        ]);

        $this->addMissing('study_sessions', [
            'mode' => fn (Blueprint $t) => $t->string('mode', 10)->default('standard'),
            'heartbeat_lost_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('heartbeat_lost_count')->default(0),
            'multi_device_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('multi_device_count')->default(0),
            'multi_tab_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('multi_tab_count')->default(0),
            'camera_issue_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('camera_issue_count')->default(0),
            'study_goal' => fn (Blueprint $t) => $t->string('study_goal', 160)->nullable(),
            'reflection' => fn (Blueprint $t) => $t->text('reflection')->nullable(),
            'ai_usage' => fn (Blueprint $t) => $t->string('ai_usage', 12)->nullable(), // none | idea | explain | draft
            'ai_usage_note' => fn (Blueprint $t) => $t->string('ai_usage_note', 255)->nullable(),
            'reflected_at' => fn (Blueprint $t) => $t->timestamp('reflected_at')->nullable(),
            'consented_at' => fn (Blueprint $t) => $t->timestamp('consented_at')->nullable(),
        ]);
    }

    /** Menambah hanya kolom yang BELUM ada, satu per satu. Tidak pernah mengubah/menghapus data. */
    private function addMissing(string $table, array $columns): void
    {
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        }
    }

    /** Sengaja tidak menghapus kolom: rollback tidak boleh menghilangkan data pengguna/riwayat belajar. */
    public function down(): void
    {
    }
};
