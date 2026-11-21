<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->text('description')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamps();

                $table->index(['room_id', 'due_at']);
            });
        }

        if (! Schema::hasTable('submissions')) {
            Schema::create('submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->text('notes')->nullable();
                $table->string('file_path')->nullable();
                $table->string('original_name')->nullable();
                $table->string('mime_type', 150)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->timestamp('submitted_at');
                $table->boolean('is_late')->default(false);
                $table->unsignedTinyInteger('score')->nullable();
                $table->text('feedback')->nullable();
                $table->timestamp('graded_at')->nullable();
                $table->timestamps();

                $table->unique(['assignment_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignments');
    }
};
