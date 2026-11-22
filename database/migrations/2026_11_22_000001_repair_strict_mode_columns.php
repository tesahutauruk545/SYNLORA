<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERBAIKAN: memastikan kolom Strict Mode ada di database hosting.
 * Dibutuhkan karena migrasi 2026_11_21_000001 bisa saja sudah tercatat "selesai" (atau gagal sebagian)
 * sementara kolom rooms.strict_mode / rooms.ai_policy tidak ada ("Unknown column 'strict_mode'").
 *
 * Idempotent per kolom: hanya MENAMBAH kolom yang belum ada. Tidak ada data yang diubah atau dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addMissing('rooms', [
            'strict_mode' => fn (Blueprint $t) => $t->boolean('strict_mode')->default(false),
            'ai_policy' => fn (Blueprint $t) => $t->string('ai_policy', 12)->default('declare'),
        ]);

        $this->addMissing('study_sessions', [
            'mode' => fn (Blueprint $t) => $t->string('mode', 10)->default('standard'),
            'heartbeat_lost_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('heartbeat_lost_count')->default(0),
            'multi_device_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('multi_device_count')->default(0),
            'multi_tab_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('multi_tab_count')->default(0),
            'camera_issue_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('camera_issue_count')->default(0),
            'study_goal' => fn (Blueprint $t) => $t->string('study_goal', 160)->nullable(),
            'reflection' => fn (Blueprint $t) => $t->text('reflection')->nullable(),
            'ai_usage' => fn (Blueprint $t) => $t->string('ai_usage', 12)->nullable(),
            'ai_usage_note' => fn (Blueprint $t) => $t->string('ai_usage_note', 255)->nullable(),
            'reflected_at' => fn (Blueprint $t) => $t->timestamp('reflected_at')->nullable(),
            'consented_at' => fn (Blueprint $t) => $t->timestamp('consented_at')->nullable(),
        ]);
    }

    private function addMissing(string $table, array $columns): void
    {
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        }
    }

    /** Tidak ada rollback destruktif. */
    public function down(): void
    {
    }
};
