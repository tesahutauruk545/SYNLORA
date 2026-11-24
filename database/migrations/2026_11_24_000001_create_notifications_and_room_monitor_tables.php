<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditif: tidak mengubah tabel lama.
 *  - user_notifications : kotak masuk notifikasi (tugas baru, peringatan monitoring), unik per (user, dedupe_key)
 *  - room_monitor_states: keadaan pemantauan terkini per mahasiswa per room (kamera/wajah/HP/tab/tugas), terpisah dari sesi Pomodoro
 *  - room_monitor_alerts: riwayat peringatan monitoring
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
                $t->string('type', 40);
                $t->string('dedupe_key', 120);
                $t->string('title', 200);
                $t->string('body', 500)->nullable();
                $t->json('data')->nullable();
                $t->string('action_url', 300)->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['user_id', 'dedupe_key']);
                $t->index(['user_id', 'read_at', 'id']);
            });
        }

        if (! Schema::hasTable('room_monitor_states')) {
            Schema::create('room_monitor_states', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('room_id')->constrained()->cascadeOnDelete();
                $t->string('camera_state', 12)->default('off');   // off|on|lost|denied|error
                $t->string('face_state', 16)->default('unknown'); // unknown|facing|not_facing|turned_left|turned_right|not_found
                $t->string('phone_state', 12)->default('none');   // none|detected
                $t->string('phone_orientation', 12)->nullable();  // vertical|horizontal
                $t->float('phone_confidence')->nullable();
                $t->string('tab_state', 10)->default('visible');  // visible|away
                $t->timestamp('tab_left_at')->nullable();
                $t->unsignedInteger('tab_away_count')->default(0);
                $t->unsignedInteger('tab_away_seconds')->default(0);
                $t->string('task_state', 12)->default('idle');    // idle|opened|submitted
                $t->unsignedBigInteger('task_assignment_id')->nullable();
                $t->string('last_event_type', 30)->nullable();
                $t->timestamp('last_event_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'room_id']);
                $t->index(['room_id', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('room_monitor_alerts')) {
            Schema::create('room_monitor_alerts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('room_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('type', 24);
                $t->string('orientation', 12)->nullable();
                $t->float('confidence')->nullable();
                $t->string('status_snapshot', 120)->nullable();
                $t->unsignedInteger('duration_seconds')->nullable();
                $t->boolean('notified')->default(false);
                $t->timestamp('occurred_at');
                $t->timestamp('resolved_at')->nullable();
                $t->index(['room_id', 'occurred_at']);
                $t->index(['user_id', 'type', 'resolved_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_monitor_alerts');
        Schema::dropIfExists('room_monitor_states');
        Schema::dropIfExists('user_notifications');
    }
};
