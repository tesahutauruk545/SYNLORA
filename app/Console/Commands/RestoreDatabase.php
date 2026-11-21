<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;

class RestoreDatabase extends Command
{
    protected $signature = 'synlora:restore {file : Path file .sql.gz hasil synlora:backup} {--force : Lewati konfirmasi} {--verify-only : Hanya periksa file, jangan memulihkan}';

    protected $description = 'Memulihkan database dari backup (dengan verifikasi checksum, backup pengaman, dan rollback otomatis bila gagal).';

    public function handle(): int
    {
        $file = $this->argument('file');

        try {
            $m = DatabaseBackup::verify($file);
            $this->info('File valid: '.count($m['tables']).' tabel, '.array_sum($m['tables']).' baris, dibuat '.$m['created_at']);

            if ($this->option('verify-only')) {
                return self::SUCCESS;
            }
            if (! $this->option('force') && ! $this->confirm('SEMUA data saat ini akan ditimpa dengan isi backup. Lanjutkan?')) {
                $this->warn('Dibatalkan.');

                return self::FAILURE;
            }

            $r = DatabaseBackup::restore($file);
            $this->info("Pemulihan selesai ({$r['tables']} tabel, {$r['rows']} baris). Backup pengaman: {$r['safety']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Pemulihan GAGAL: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
