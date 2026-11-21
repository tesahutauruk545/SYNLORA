# SYNLORA: Learn Together. Evolve Together.

Platform belajar bersama (Laravel 10, Blade, Tailwind, MySQL): study room, Pomodoro tersinkron server, indikator fokus (MediaPipe + Page Visibility), dashboard dosen, statistik belajar, materi dan tugas.

## Instalasi di Windows dengan Laragon

**Yang dibutuhkan:** Laragon Full (PHP 8.1 atau lebih baru, MySQL, Node.js, Composer). Folder `vendor` dan `public/build` sudah ikut di ZIP, jadi `composer install` dan `npm install` tidak wajib untuk menjalankan aplikasi.

1. Ekstrak ZIP ke `C:\laragon\www\synlora`.
2. Buka Laragon, klik **Start All**.
3. Buat database kosong `synlora_db` (Menu > MySQL > phpMyAdmin, collation `utf8mb4_unicode_ci`).
4. Buka **Terminal Laragon** di folder project, lalu jalankan:
   ```
   copy .env.example .env
   php artisan key:generate
   ```
5. Buka `.env`, periksa `DB_USERNAME` dan `DB_PASSWORD` (bawaan Laragon: `root` dan kosong). Tidak ada kode undangan: mahasiswa dan dosen mendaftar sendiri.
6. Jalankan:
   ```
   php artisan migrate
   php artisan synlora:install-face-model
   php artisan serve
   ```
   Atau cukup klik dua kali `start.bat` (melakukan migrasi, memasang model wajah, lalu menjalankan server).
7. Buka `http://localhost:8000`. Dengan Auto Virtual Host Laragon, alamatnya bisa `http://synlora.test`.

**Model deteksi wajah (lokal).** `php artisan synlora:install-face-model` mengunduh `blaze_face_short_range.tflite` (±230 KB) ke `public/vendor/mediapipe/models/`. Setelah itu deteksi wajah berjalan sepenuhnya di browser tanpa internet. Jika file tidak ada, kamera tidak dipakai dan aplikasi menampilkan pesan jelas; pemantauan tab tetap berjalan. Untuk melarang unduhan dari Google saat file lokal tidak ada, set `MEDIAPIPE_ALLOW_REMOTE_MODEL=false`. Browser hanya mengizinkan kamera di `localhost` atau HTTPS.

## Peningkatan dari proyek lama
Salin file baru ke folder project (jangan menimpa `.env`), lalu `php artisan migrate`. Migrasi hanya **menambah** kolom dan indeks; data lama tetap. Jangan memakai `migrate:fresh`. Mahasiswa lama punya NIM dan kelas kosong sampai mereka mengisinya di menu Profil.

## Peran dan hak akses
- Mahasiswa dan dosen **mendaftar sendiri** di halaman Daftar, tanpa kode undangan.
- Keduanya dapat membuat, mengelola, dan menghapus room miliknya, bergabung lewat kode, memakai Pomodoro, dan melihat riwayat sendiri.
- **Mahasiswa** hanya melihat statistik, riwayat, dan data monitoring miliknya sendiri (termasuk bila ia owner room).
- **Dosen** juga melihat status fokus, aktivitas, statistik, dan riwayat monitoring **mahasiswa yang menjadi anggota room yang sama** dengannya (room miliknya maupun room yang ia ikuti lewat kode). Data mahasiswa di luar room itu tidak terlihat.
- Peran dipilih saat mendaftar dan tidak bisa diubah dari halaman web. Mengubah peran akun yang sudah ada: `php artisan synlora:make-dosen email@kampus.ac.id` (tambahkan `--revoke` untuk mencabut).

## Aturan status fokus
Status hanyalah **indikator** aktivitas browser dan (opsional) deteksi wajah, bukan bukti konsentrasi. Server menentukan status dari *jenis event* yang dilaporkan browser, bukan dari status kiriman klien.

| Status | Arti |
|---|---|
| HIJAU | Tab aktif, dan (jika kamera menyala) wajah terdeteksi |
| KUNING | Wajah tidak terlihat 5 detik atau lebih, jendela kehilangan fokus, atau tab baru ditinggalkan (kurang dari 10 detik) |
| MERAH | Tab ditinggalkan 10 detik atau lebih (dihitung juga oleh server). Wajah hilang saja tidak pernah merah. Posisi HP (landscape/portrait) tidak memengaruhi status |
| ABU | Dijeda atau istirahat |
| DISCONNECTED | Server tidak menerima detak browser lebih dari 60 detik (tab ditutup, internet putus, JavaScript dihentikan) |
| UNKNOWN | Sesi berjalan tetapi browser belum melaporkan apa pun |

Kamera adalah dimensi terpisah: `CAMERA_ON` atau `CAMERA_OFF` (alasan: tidak dipakai, izin ditolak, tidak tersedia, rusak/dipakai aplikasi lain, model wajah gagal dimuat). Tanpa kamera, pemantauan tab tetap berjalan. Batas waktu dapat diubah lewat `FOCUS_*` di `.env`. Video kamera tidak ditampilkan, dikirim, atau disimpan.

Batasan jujur: pengguna yang sengaja memodifikasi browser tetap dapat menghentikan laporan. Akibatnya terlihat (DISCONNECTED, `HEARTBEAT_LOST` di riwayat), bukan status hijau palsu.

## Backup dan pemulihan database
```
php artisan synlora:backup --files            # backup database (+ file materi/tugas) ke storage/app/backups, simpan 14 terbaru
php artisan synlora:restore <file.sql.gz>     # verifikasi checksum, backup pengaman otomatis, pulihkan, verifikasi jumlah baris
php artisan synlora:restore <file> --verify-only
php artisan synlora:check-integrity [--fix]   # sesi ganda, sesi tanpa akhir, file yatim
php artisan synlora:prune-logs --days=365     # opsional; bawaan tidak menghapus apa pun (LOG_RETENTION_DAYS=0)
```
Backup tidak memerlukan `mysqldump` (aman di Windows). Jika pemulihan gagal di tengah jalan, database dikembalikan ke keadaan sebelumnya secara otomatis. **Jadwal otomatis di Windows:** jalankan `scripts\jadwalkan-backup.bat` sekali sebagai Administrator (backup harian 02:00). Pulihkan dengan `scripts\pulihkan.bat <file>`. Simpan salinan folder `storage\app\backups` di disk/komputer lain.

## Keandalan
- `GET /health` memeriksa database dan folder tulis (503 bila bermasalah), cocok untuk pemantauan.
- Saat MySQL mati, pengguna melihat halaman "Server sedang gangguan" (bukan jejak error); klien JavaScript menampilkan banner dan mencoba lagi sendiri. Timer mengikuti waktu server sehingga tetap benar setelah pulih.
- Event pemantauan yang gagal terkirim diantrekan, dikirim ulang, dan tidak tercatat ganda.
- Reset kata sandi: bawaan `MAIL_MAILER=log`, tautan reset ditulis ke `storage/logs`. Isi SMTP di `.env` untuk pemakaian nyata.
- Kata sandi minimal 8 karakter, wajib huruf dan angka. Batas per-IP sengaja longgar (satu kelas sering memakai satu IP kampus); ubah lewat `LOGIN_IP_MAX_FAILURES` dan `REGISTER_PER_MINUTE`.

## Pengujian
```
php artisan test                           # 115 tes. WAJIB database terpisah (mis. DB_DATABASE=synlora_testing): tes menghapus isi database
node --test tests/js/focus-state.test.mjs  # 21 tes logika status
node tests/load/simulate.mjs               # simulasi beban (seed: tests/load/seed.php; petunjuk di komentar file)
tests/browser/                             # skrip browser (Puppeteer), lihat README.txt di dalamnya
```

## Pengembangan
`npm install` lalu `npm run dev` (hot reload) atau `npm run build` (produksi). Hapus `public/hot` bila dev server dimatikan.

## Pemecahan masalah
- **Halaman tampil tanpa gaya:** `npm run build`, lalu `php artisan optimize:clear`.
- **Kamera tidak menyala:** izinkan kamera di browser, pakai `localhost`/HTTPS, dan pastikan model terpasang (langkah 6).
- **Mahasiswa tampil abu padahal belajar:** browser tidak mengirim heartbeat (tab ditutup atau internet putus).
- **Error 500:** set `APP_DEBUG=true` sementara di `.env`, lihat `storage/logs/laravel.log`.
