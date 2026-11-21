<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InstallFaceModel extends Command
{
    protected $signature = 'synlora:install-face-model {--force : Timpa file yang sudah ada}';

    protected $description = 'Mengunduh model MediaPipe Face Detector ke public/vendor/mediapipe/models agar deteksi wajah berjalan lokal tanpa internet.';

    public function handle(): int
    {
        $dir = public_path('vendor/mediapipe/models');
        $file = $dir.DIRECTORY_SEPARATOR.config('synlora.mediapipe.model_file');

        if (is_file($file) && ! $this->option('force')) {
            $this->info('Model sudah ada: '.$file);

            return self::SUCCESS;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->line('Mengunduh model (±230 KB)...');
        $res = Http::timeout(60)->withOptions(['verify' => true])->get(config('synlora.mediapipe.model_remote_url'));

        $body = $res->body();
        // Validasi dasar: file TFLite memiliki penanda "TFL3" pada byte 4-7 dan ukurannya wajar.
        if (! $res->ok() || strlen($body) < 50_000 || strlen($body) > 2_000_000 || substr($body, 4, 4) !== 'TFL3') {
            $this->error('Unduhan gagal atau bukan file model TFLite yang valid (HTTP '.$res->status().').');

            return self::FAILURE;
        }

        file_put_contents($file, $body);
        $this->info('Model tersimpan: '.$file.' ('.number_format(strlen($body)).' byte)');

        return self::SUCCESS;
    }
}
