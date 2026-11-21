<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            if (! Schema::hasColumn('room_members', 'role')) {
                $table->string('role', 10)->default('member')->after('user_id');
            }
        });

        // Backfill: pembuat room pada data lama otomatis berperan owner.
        DB::table('room_members')
            ->whereIn('room_id', function ($q) {
                $q->select('id')->from('rooms');
            })
            ->update([
                'role' => DB::raw("CASE WHEN user_id = (SELECT owner_id FROM rooms WHERE rooms.id = room_members.room_id) THEN 'owner' ELSE 'member' END"),
            ]);

        // Pastikan setiap owner juga tercatat sebagai anggota (data lama bisa tidak konsisten).
        $missing = DB::table('rooms')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('room_members')
                    ->whereColumn('room_members.room_id', 'rooms.id')
                    ->whereColumn('room_members.user_id', 'rooms.owner_id');
            })->get(['id', 'owner_id']);

        foreach ($missing as $room) {
            DB::table('room_members')->insert([
                'room_id' => $room->id,
                'user_id' => $room->owner_id,
                'role' => 'owner',
                'focus_status' => 'ABU',
                'last_active' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            if (Schema::hasColumn('room_members', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
