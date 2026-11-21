<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi Pomodoro solo tidak punya room, jadi activity_logs.room_id harus boleh NULL.
 * Ditambah indeks untuk query rekap dan feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE activity_logs MODIFY room_id BIGINT UNSIGNED NULL');
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['room_id', 'occurred_at'], 'activity_logs_room_time_index');
            $table->index(['session_id', 'occurred_at'], 'activity_logs_session_time_index');
        });

        Schema::table('focus_logs', function (Blueprint $table) {
            $table->index(['session_id', 'occurred_at'], 'focus_logs_session_time_index');
        });
    }

    public function down(): void
    {
        // MySQL memakai indeks komposit sebagai indeks foreign key; sediakan indeks biasa sebelum menghapusnya.
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('room_id', 'activity_logs_room_id_index');
            $table->index('session_id', 'activity_logs_session_id_index');
        });
        Schema::table('focus_logs', function (Blueprint $table) {
            $table->index('session_id', 'focus_logs_session_id_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_room_time_index');
            $table->dropIndex('activity_logs_session_time_index');
        });
        Schema::table('focus_logs', function (Blueprint $table) {
            $table->dropIndex('focus_logs_session_time_index');
        });
        // Catatan: kolom room_id sengaja dibiarkan nullable agar data sesi solo tidak hilang.
    }
};
