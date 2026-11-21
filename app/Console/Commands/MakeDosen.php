<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeDosen extends Command
{
    protected $signature = 'synlora:make-dosen {email : Email akun yang sudah terdaftar} {--revoke : Kembalikan menjadi mahasiswa}';

    protected $description = 'Menjadikan akun terdaftar sebagai dosen (atau mencabutnya) lewat server, bukan lewat form web.';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('Akun dengan email tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        $user->role = $this->option('revoke') ? 'mahasiswa' : 'dosen';
        $user->save();
        $this->info("{$user->email} sekarang berperan {$user->role}.");

        return self::SUCCESS;
    }
}
