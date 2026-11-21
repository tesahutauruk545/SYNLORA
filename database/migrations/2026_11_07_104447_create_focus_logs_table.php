<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('focus_logs')) {
            return;
        }

        Schema::create('focus_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('study_sessions')->onDelete('cascade');
            $table->string('status');
            $table->string('reason');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_logs');
    }
};