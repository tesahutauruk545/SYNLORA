<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual Meeting (Jitsi). Terpisah total dari study_sessions/Pomodoro:
 * tidak ada kolom yang menghubungkan keduanya, sehingga Pomodoro tidak
 * mengendalikan durasi monitoring meeting dan sebaliknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->onDelete('cascade');
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('jitsi_name', 80)->unique();
                $table->string('status', 10)->default('LIVE');
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->string('end_reason', 20)->nullable();
                $table->unsignedInteger('peak_participants')->default(0);
                $table->timestamps();

                $table->index(['room_id', 'status']);
                $table->index(['room_id', 'started_at']);
            });
        }

        if (! Schema::hasTable('meeting_attendances')) {
            Schema::create('meeting_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->timestamp('joined_at');
                $table->timestamp('left_at')->nullable();
                $table->timestamp('last_seen_at');
                $table->string('leave_reason', 20)->nullable();
                $table->string('current_status', 10)->default('ABU');
                $table->string('current_reason')->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->timestamps();

                $table->index(['meeting_id', 'user_id']);
                $table->index(['meeting_id', 'left_at']);
            });
        }

        if (! Schema::hasTable('meeting_focus_logs')) {
            Schema::create('meeting_focus_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
                $table->foreignId('attendance_id')->constrained('meeting_attendances')->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('status', 10);
                $table->string('reason')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['meeting_id', 'user_id', 'occurred_at']);
                $table->index(['attendance_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('meeting_events')) {
            Schema::create('meeting_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
                $table->foreignId('attendance_id')->nullable()->constrained('meeting_attendances')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 30);
                $table->string('details')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['meeting_id', 'occurred_at']);
                $table->index(['meeting_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_events');
        Schema::dropIfExists('meeting_focus_logs');
        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('meetings');
    }
};
