<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('study_sessions')) {
            return;
        }

        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('room_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('focus_duration_minutes')->default(25);
            $table->integer('break_duration_minutes')->default(5);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('total_effective_seconds')->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};