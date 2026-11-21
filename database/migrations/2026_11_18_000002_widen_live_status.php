<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** live_status (varchar 8) terlalu pendek untuk status DISCONNECTED / UNKNOWN. */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE study_sessions MODIFY live_status VARCHAR(16) NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('study_sessions')->whereIn('live_status', ['DISCONNECTED', 'UNKNOWN'])->update(['live_status' => null]);
            DB::statement('ALTER TABLE study_sessions MODIFY live_status VARCHAR(8) NULL');
        }
    }
};
