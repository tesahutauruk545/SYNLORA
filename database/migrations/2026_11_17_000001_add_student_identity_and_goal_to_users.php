<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NIM, kelas, dan target belajar harian. Aman dijalankan pada data lama (semua kolom nullable/berdefault). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'nim')) {
                $table->string('nim', 20)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'kelas')) {
                $table->string('kelas', 30)->nullable()->after('nim');
            }
            if (! Schema::hasColumn('users', 'daily_goal_minutes')) {
                $table->unsignedSmallInteger('daily_goal_minutes')->default(120)->after('role');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'kelas'], 'users_role_kelas_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_kelas_index');
        });
        Schema::table('users', function (Blueprint $table) {
            foreach (['nim', 'kelas', 'daily_goal_minutes'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    if ($c === 'nim') {
                        $table->dropUnique(['nim']);
                    }
                    $table->dropColumn($c);
                }
            }
        });
    }
};
