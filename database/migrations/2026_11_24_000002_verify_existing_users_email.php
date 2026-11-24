<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi email kini wajib. Akun yang SUDAH ADA sebelum fitur ini dianggap terverifikasi supaya
 * dosen/mahasiswa lama tidak terkunci. Hanya akun baru (setelah migration ini) yang wajib verifikasi.
 * Tidak mengubah struktur tabel; down() sengaja kosong karena tidak bisa membedakan akun mana yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
