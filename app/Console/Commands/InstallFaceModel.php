<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InstallFaceModel extends Command
{
    protected $signature = 'synlora:install-face-model {--force : Timpa file yang sudah ada} {--object : Unduh juga model deteksi objek (HP), EfficientDet-Lite0 ±4 MB}';

    protected $description = 'Mengunduh model MediaPipe Face Detector ke public/vendor/mediapipe/models agar deteksi wajah berjalan lokal tanpa internet.';

    public function handle(): int
    {
        $dir = public_path('vendor/mediapipe/models');
        $file = $dir.DIRECTORY_SEPARATOR.config('synlora.mediapipe.model_file');

        if (is_file($file) && ! $this->option('force')) {
            $this->info('Model sudah ada: '.$file);

            return $this->option('object') ? $this->installObjectModel($dir) : self::SUCCESS;
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

        return $this->option('object') ? $this->installObjectModel($dir) : self::SUCCESS;
    }

    /** Model deteksi objek (kelas COCO "cell phone") untuk fitur deteksi handphone. Dipakai bersama MediaPipe yang sama. */
    private function installObjectModel(string $dir): int
    {
        $file = $dir.DIRECTORY_SEPARATOR.config('synlora.room_monitor.object_model_file');
        if (is_file($file) && ! $this->option('force')) {
            $this->info('Model objek sudah ada: '.$file);

            return self::SUCCESS;
        }

        $this->line('Mengunduh model deteksi objek (±4 MB)...');
        $res = Http::timeout(120)->withOptions(['verify' => true])->get(config('synlora.room_monitor.object_model_remote_url'));
        $body = $res->body();
        if (! $res->ok() || strlen($body) < 500_000 || strlen($body) > 20_000_000 || substr($body, 4, 4) !== 'TFL3') {
            $this->error('Unduhan model objek gagal atau bukan file TFLite yang valid (HTTP '.$res->status().').');

            return self::FAILURE;
        }
        file_put_contents($file, $body);
        $this->info('Model objek tersimpan: '.$file.' ('.number_format(strlen($body)).' byte)');

        return self::SUCCESS;
    }
}
