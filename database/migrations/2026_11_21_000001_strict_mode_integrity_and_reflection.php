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
        Schema::table('rooms', function (Blueprint $t) {
            if (! Schema::hasColumn('rooms', 'strict_mode')) {
                $t->boolean('strict_mode')->default(false);
            }
            if (! Schema::hasColumn('rooms', 'ai_policy')) {
                $t->string('ai_policy', 12)->default('declare'); // forbidden | declare | allowed
            }
        });

        // Setiap kolom dicek sendiri-sendiri agar aman dijalankan ulang bila sebelumnya terhenti di tengah.
        $columns = [
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
        ];

        Schema::table('study_sessions', function (Blueprint $t) use ($columns) {
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('study_sessions', $name)) {
                    $add($t);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', fn (Blueprint $t) => $t->dropColumn([
            'mode', 'heartbeat_lost_count', 'multi_device_count', 'multi_tab_count', 'camera_issue_count',
            'study_goal', 'reflection', 'ai_usage', 'ai_usage_note', 'reflected_at', 'consented_at',
        ]));
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn(['strict_mode', 'ai_policy']));
    }
};
