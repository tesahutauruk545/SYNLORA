<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERBAIKAN BUG Pomodoro Solo:
 *   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'mode' in 'INSERT INTO'
 *
 * Penyebab: kolom yang dipakai StudySession::begin() (mode, study_goal, consented_at, dst.) hanya dibuat oleh
 * migration 2026_11_21_000001. Pada database yang belum menjalankannya (atau yang tercatat sudah menjalankannya
 * tetapi kolomnya tidak ada, mis. setelah import/restore dari database lama), INSERT sesi gagal.
 *
 * Migration ini HANYA MENAMBAH kolom yang belum ada (dicek satu per satu), dengan nilai bawaan yang aman,
 * dan melebarkan live_status bila masih terlalu pendek. Tidak ada data yang diubah atau dihapus, dan aman
 * dijalankan berulang kali / di database yang sudah lengkap (tidak melakukan apa pun).
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
                $t->string('ai_policy', 12)->default('declare');
            }
            if (! Schema::hasColumn('rooms', 'max_members')) {
                $t->unsignedSmallInteger('max_members')->default(60);
            }
        });

        // Daftar lengkap kolom study_sessions yang dibaca/ditulis kode aplikasi (selain kolom dasar tabel).
        $columns = [
            // Pomodoro berbasis waktu server
            'phase' => fn (Blueprint $t) => $t->string('phase', 10)->default('FOCUS'),
            'phase_started_at' => fn (Blueprint $t) => $t->timestamp('phase_started_at')->nullable(),
            'paused_at' => fn (Blueprint $t) => $t->timestamp('paused_at')->nullable(),
            'last_heartbeat_at' => fn (Blueprint $t) => $t->timestamp('last_heartbeat_at')->nullable(),
            'completed_pomodoros' => fn (Blueprint $t) => $t->unsignedInteger('completed_pomodoros')->default(0),
            'end_reason' => fn (Blueprint $t) => $t->string('end_reason', 20)->nullable(),
            // Status fokus terakhir + kamera
            'live_status' => fn (Blueprint $t) => $t->string('live_status', 16)->nullable(),
            'live_source' => fn (Blueprint $t) => $t->string('live_source', 12)->nullable(),
            'live_reason' => fn (Blueprint $t) => $t->string('live_reason', 255)->nullable(),
            'live_event' => fn (Blueprint $t) => $t->string('live_event', 24)->nullable(),
            'live_changed_at' => fn (Blueprint $t) => $t->timestamp('live_changed_at')->nullable(),
            'camera_on' => fn (Blueprint $t) => $t->boolean('camera_on')->default(false),
            'camera_state' => fn (Blueprint $t) => $t->string('camera_state', 16)->default('off'),
            // Mode ketat, integritas, tujuan belajar, refleksi
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
        ];

        $missing = array_filter($columns, fn ($add, $name) => ! Schema::hasColumn('study_sessions', $name), ARRAY_FILTER_USE_BOTH);

        if ($missing) {
            Schema::table('study_sessions', function (Blueprint $t) use ($missing) {
                foreach ($missing as $add) {
                    $add($t);
                }
            });
        }

        // Sesi lama yang belum punya awal fase: anggap fase fokus dimulai saat started_at (sama seperti migration asal).
        if (isset($missing['phase_started_at'])) {
            DB::table('study_sessions')->whereNull('phase_started_at')->update(['phase_started_at' => DB::raw('started_at')]);
        }

        // live_status bervarchar(8) tidak muat untuk "DISCONNECTED"; lebarkan hanya bila memang masih sempit.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $width = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', 'study_sessions')
                ->where('column_name', 'live_status')
                ->value('character_maximum_length');

            if ($width !== null && (int) $width < 16) {
                DB::statement('ALTER TABLE study_sessions MODIFY live_status VARCHAR(16) NULL');
            }
        }
    }

    public function down(): void
    {
        // Sengaja kosong: kolom-kolom ini dimiliki migration sebelumnya; menghapusnya akan menghilangkan data sesi.
    }
};
