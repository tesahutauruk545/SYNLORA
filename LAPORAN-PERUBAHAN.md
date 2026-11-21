# Laporan SYNLORA V3 (18 November 2026)

Dasar: SYNLORA V2. Tidak dibangun ulang; migrasi hanya menambah; tidak ada data yang dihapus. Laporan V2 ada di `docs/`.

## 1. Yang diperbaiki
**Keamanan dan akun.** Batas percobaan login per email+IP dan per IP (longgar untuk NAT kampus), kata sandi wajib huruf+angka, registrasi dosen hanya dengan kode undangan, `role` tidak bisa dimanipulasi, header keamanan, reset sandi lewat log (SMTP opsional).
**Room.** Kapasitas per room (bawaan 60, maks 300), pendaftaran atomik (tanpa anggota ganda/melebihi kapasitas), pembatasan tebak kode (8 salah per 10 menit), ubah pengaturan, ganti kode, hapus room wajib mengetik nama, riwayat aktivitas anggota tidak lagi ikut terhapus (FK `activity_logs.room_id` diubah ke SET NULL; sebelumnya CASCADE).
**Monitoring fokus.** Aturan HP landscape dihapus. Status baru DISCONNECTED dan UNKNOWN; kamera dipisah sebagai CAMERA_ON/OFF dengan alasan. Server menurunkan status dari jenis event (klien tak bisa mengirim status palsu), menghitung eskalasi MERAH sendiri, dan keadaan "tab ditinggalkan" bersifat lengket sehingga event berulang tidak menunda MERAH. Detak membawa penanda tab tersembunyi sebagai pembanding silang; putus koneksi dicatat sebagai HEARTBEAT_LOST. Antrean event klien dengan ulang otomatis dan deduplikasi; dua tab tidak saling menandai "meninggalkan"; banner offline/sesi login berakhir/server gangguan.
**Materi dan tugas.** Kuota penyimpanan per room, nama file disanitasi, file yatim dibersihkan bila simpan gagal, pengumpulan atomik, opsi menutup pengumpulan setelah deadline, tugas yang sudah dinilai tidak bisa diubah.
**Database dan operasi.** Backup/restore tanpa mysqldump dengan checksum, backup pengaman, dan rollback otomatis; pemeriksa integritas; pemangkasan log opsional; jadwal harian; `/health`; halaman 503 ramah.
**Dashboard.** Dosen: kehadiran hari ini, target tercapai, status baru, kamera. Navigasi beralih ke menu hamburger di bawah 1024 px (di tablet tautan sebelumnya menabrak menu pengguna).
**Meeting/Jitsi/WebRTC.** Tidak ada kode meeting di project ini (hanya satu komentar di middleware, sudah disesuaikan); kamera hanya dipakai monitoring fokus.

Bug nyata yang ditemukan lewat pengujian dan diperbaiki: (a) kolom `live_status` (8 karakter) terlalu pendek untuk "DISCONNECTED" sehingga heartbeat setelah koneksi pulih error 500; (b) event "tab ditinggalkan lama" palsu dapat mereset jam eskalasi MERAH; (c) batas login/registrasi per IP akan memblokir satu kelas yang login dari satu IP; (d) `activity_logs` ikut terhapus bersama room.

## 2. Hasil pengujian
| Pengujian | Hasil |
|---|---|
| PHP | **115 lulus**, 0 gagal (710 assertion): 78 lama + 37 baru (skenario terburuk dan operasi) |
| JavaScript | **21 lulus** |
| Migrasi | fresh, rollback, migrate ulang, dan upgrade dari data lama: lulus |
| Backup/restore (di database pengujian) | pulih persis termasuk karakter khusus; file rusak ditolak; manifest dimanipulasi memicu rollback otomatis; folder tak bisa ditulis gagal bersih |
| Browser (Chromium headless) | tab tersembunyi kuning lalu merah, kembali hijau, kamera tidak tersedia (pesan jelas, tab tetap dipantau), dua tab, banner offline; 0 galat JavaScript; 40 tangkapan layar pada lebar 360/820/1024/1440 px tanpa overflow horizontal |
| Beban, laju browser nyata, 60 detik, 1 dosen + N mahasiswa | N=40, 50, 100, 200: **0 gagal, 0 respons 429, distribusi status di dashboard dosen tepat sesuai skenario** pada keempatnya |

Latensi (median / p95, ms): N=40: 11-14 / 21-253; N=50: 11-14 / 20-76; N=100: 14-16 / 54-353 (feed 107); N=200: 123-499 / 530-1800. Lingkungan: **1 core CPU**, `php artisan serve` 16 worker tanpa opcache, MariaDB 10.11, `BCRYPT_ROUNDS=4` agar login tidak mendominasi. Pada 200 pengguna latensi naik tetapi tidak ada kegagalan; server produksi (PHP-FPM + opcache, lebih dari 1 core) diharapkan jauh lebih cepat, namun itu **belum diukur**.

### Matriks 60 skenario
Teruji penuh (45): S01-S09, S11, S12, S15-S19, S21, S22, S24-S33, S35-S40, S42, S43, S49, S50, S53-S57, S59, S60. (S35-S38 = beban 40/50/100/200 di atas.)
Teruji sebagian (15), dengan alasan:
- S10 aplikasi lain, S13 izin kamera ditolak, S14 kamera dipakai aplikasi lain: jalur server dan pemetaan error teruji, tetapi peristiwa sungguhan (blur lintas aplikasi, dialog izin, perangkat sibuk) tidak bisa direproduksi di Chromium headless.
- S20 dua perangkat: jaminan satu sesi terbuka teruji; dua perangkat fisik tidak.
- S23 timer tidak sinkron: waktu server terbukti; pergeseran jam perangkat tidak diuji terpisah.
- S34 server overload: tidak ada kegagalan sampai 200 pengguna di 1 core, tetapi tidak ada mekanisme pelindung beban selain batas laju.
- S41 log penuh: pemangkasan teruji; rotasi log harian hanya dikonfigurasi (`LOG_CHANNEL=daily`, 14 hari), tidak diuji.
- S44-S46 HP/tablet/laptop: diuji pada ukuran layar emulasi, bukan perangkat asli.
- S47 tombol, S48 form: tombol utama sesi dan validasi form utama teruji; tidak setiap tombol/form diuji di browser.
- S51 upload terputus: file rusak/tidak lengkap teruji; pemutusan di tengah transfer sungguhan tidak.
- S52 dua pengguna mengubah bersamaan: kunci baris diterapkan dan urutan nilai-lalu-ubah teruji; balapan nyata paralel tidak diuji.
- S58 browser tanpa dukungan fitur: jalur "tanpa kamera" teruji; browser lama sungguhan tidak.

## 3. Belum terselesaikan / keterbatasan
1. **Model deteksi wajah tidak ikut ZIP** (lingkungan pengujian tidak dapat mengunduhnya). Jalankan `php artisan synlora:install-face-model`. Jalur kamera + deteksi wajah **belum pernah diuji tuntas**; hanya kegagalannya yang teruji.
2. **Belum diuji di Windows/Laragon (MySQL 8)**, macOS, dan perangkat asli. Pengujian memakai PHP 8.3 + MariaDB 10.11 di Linux.
3. Tab "tersembunyi" disimulasikan lewat kode; browser yang membatasi timer latar belakang dapat menunda perubahan, meski server tetap menaikkan ke MERAH.
4. Pembuat room berupa mahasiswa masih melihat status fokus anggotanya (desain lama dipertahankan); dosen memakai `/dosen`.
5. Tidak ada CSP ketat, verifikasi email, dan SMTP bawaan. Batas satu-sesi-per-pengguna dijaga lewat aplikasi (kunci baris), bukan constraint database (tidak dipasang karena batasan MySQL pada kolom turunan dengan FK cascade; tidak dapat diuji di MySQL 8 di sini).
6. Backup ke folder yang sama dengan aplikasi tidak melindungi dari kegagalan disk; salin keluar secara berkala.
7. Mahasiswa lama perlu mengisi NIM dan kelas di Profil.
