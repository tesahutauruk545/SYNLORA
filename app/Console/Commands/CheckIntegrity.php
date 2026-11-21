<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CheckIntegrity extends Command
{
    protected $signature = 'synlora:check-integrity {--fix : Perbaiki masalah yang aman diperbaiki otomatis}';

    protected $description = 'Memeriksa inkonsistensi data (sesi ganda, sesi tanpa akhir, file yatim, anggota ganda) dan opsional memperbaikinya.';

    public function handle(): int
    {
        $problems = 0;
        $fix = (bool) $this->option('fix');

        // 1. Pengguna dengan lebih dari satu sesi terbuka.
        $dupes = DB::table('study_sessions')->where('status', '!=', 'ENDED')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->pluck('user_id');
        foreach ($dupes as $uid) {
            $problems++;
            $this->warn("Pengguna #{$uid} punya lebih dari satu sesi terbuka.");
            if ($fix) {
                $keep = DB::table('study_sessions')->where('user_id', $uid)->where('status', '!=', 'ENDED')->orderByDesc('id')->value('id');
                DB::table('study_sessions')->where('user_id', $uid)->where('status', '!=', 'ENDED')->where('id', '!=', $keep)
                    ->update(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'duplicate_closed']);
            }
        }

        // 2. Sesi ENDED tanpa ended_at / sesi terbuka yang sudah sangat lama.
        $noEnd = DB::table('study_sessions')->where('status', 'ENDED')->whereNull('ended_at')->count();
        if ($noEnd) {
            $problems += $noEnd;
            $this->warn("{$noEnd} sesi berstatus ENDED tanpa waktu selesai.");
            if ($fix) {
                DB::table('study_sessions')->where('status', 'ENDED')->whereNull('ended_at')->update(['ended_at' => DB::raw('COALESCE(last_heartbeat_at, started_at)')]);
            }
        }
        $stale = DB::table('study_sessions')->where('status', '!=', 'ENDED')->where('last_heartbeat_at', '<', now()->subHours(12))->count();
        if ($stale) {
            $problems += $stale;
            $this->warn("{$stale} sesi terbuka tanpa detak lebih dari 12 jam.");
            if ($fix) {
                DB::table('study_sessions')->where('status', '!=', 'ENDED')->where('last_heartbeat_at', '<', now()->subHours(12))
                    ->update(['status' => 'ENDED', 'ended_at' => DB::raw('last_heartbeat_at'), 'end_reason' => 'abandoned']);
            }
        }

        // 3. Owner room yang bukan anggota room.
        $orphanOwners = DB::table('rooms')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('room_members')->whereColumn('room_members.room_id', 'rooms.id')->whereColumn('room_members.user_id', 'rooms.owner_id'))->get(['id', 'owner_id']);
        foreach ($orphanOwners as $r) {
            $problems++;
            $this->warn("Owner room #{$r->id} tidak tercatat sebagai anggota.");
            if ($fix) {
                DB::table('room_members')->insert(['room_id' => $r->id, 'user_id' => $r->owner_id, 'role' => 'owner', 'focus_status' => 'ABU', 'last_active' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // 4. Baris file tanpa berkas fisik, dan berkas fisik tanpa baris.
        $known = [];
        foreach (['materials', 'submissions'] as $t) {
            foreach (DB::table($t)->whereNotNull('file_path')->pluck('file_path') as $p) {
                $known[$p] = true;
                if (! Storage::disk('local')->exists($p)) {
                    $problems++;
                    $this->warn("Berkas hilang dari penyimpanan ({$t}): {$p} (tidak diperbaiki otomatis).");
                }
            }
        }
        foreach (Storage::disk('local')->allFiles('rooms') as $f) {
            if (! isset($known[$f])) {
                $problems++;
                $this->warn("Berkas yatim (tidak ada barisnya): {$f}");
                if ($fix) {
                    Storage::disk('local')->delete($f);
                }
            }
        }

        $this->info($problems ? "Selesai: {$problems} temuan".($fix ? ' (yang aman sudah diperbaiki).' : ' (jalankan dengan --fix untuk memperbaiki).') : 'Tidak ada inkonsistensi ditemukan.');

        return $problems && ! $fix ? self::FAILURE : self::SUCCESS;
    }
}
