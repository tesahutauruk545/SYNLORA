<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneLogs extends Command
{
    protected $signature = 'synlora:prune-logs {--days= : Hapus log aktivitas/fokus lebih lama dari N hari (bawaan LOG_RETENTION_DAYS; 0 = nonaktif)}';

    protected $description = 'Menghapus log aktivitas dan log fokus yang sudah lama agar database tidak membengkak. Data sesi/statistik tidak dihapus.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('synlora.log_retention_days', 0));
        if ($days <= 0) {
            $this->info('Retensi log dinonaktifkan (0 hari). Tidak ada yang dihapus.');

            return self::SUCCESS;
        }
        if ($days < 30) {
            $this->error('Retensi minimal 30 hari agar rekap sesi tidak rusak.');

            return self::FAILURE;
        }
        $cut = now()->subDays($days);
        $a = 0;
        $f = 0;
        do { $n = DB::table('activity_logs')->where('occurred_at', '<', $cut)->limit(5000)->delete(); $a += $n; } while ($n === 5000);
        do { $n = DB::table('focus_logs')->where('occurred_at', '<', $cut)->limit(5000)->delete(); $f += $n; } while ($n === 5000);
        $this->info("Dihapus: {$a} log aktivitas, {$f} log fokus (lebih lama dari {$days} hari).");

        return self::SUCCESS;
    }
}
