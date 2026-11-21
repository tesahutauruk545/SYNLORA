<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'synlora:backup {--keep=14 : Jumlah backup terbaru yang disimpan} {--dir= : Folder tujuan (bawaan storage/app/backups)} {--files : Sertakan juga file materi/tugas (zip)}';

    protected $description = 'Membuat backup database (dan opsional file unggahan) beserta checksum.';

    public function handle(): int
    {
        try {
            $path = DatabaseBackup::create($this->option('dir') ?: null);
            $this->info('Backup database: '.$path.' ('.number_format(filesize($path) / 1024, 1).' KB)');

            if ($this->option('files')) {
                $zip = $this->zipFiles(dirname($path));
                $this->info($zip ? 'Backup file unggahan: '.$zip : 'Tidak ada file unggahan untuk di-backup (atau ekstensi zip tidak aktif).');
            }

            $removed = DatabaseBackup::prune((int) $this->option('keep'), $this->option('dir') ?: null);
            if ($removed) {
                $this->line("Backup lama dihapus: {$removed}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup GAGAL: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
    }

    private function zipFiles(string $dir): ?string
    {
        $root = storage_path('app/rooms');
        if (! class_exists(\ZipArchive::class) || ! is_dir($root)) {
            return null;
        }
        $zipPath = $dir.DIRECTORY_SEPARATOR.'synlora-files-'.date('Ymd-His').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return null;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), 'rooms/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/'));
                $n++;
            }
        }
        $zip->close();

        return $n ? $zipPath : (@unlink($zipPath) ? null : null);
    }
}
