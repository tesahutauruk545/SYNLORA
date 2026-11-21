# Laporan Perubahan SYNLORA (17 November 2026)

Pendekatan: project lama diperbaiki, bukan dibangun ulang. Teknologi utama (Laravel 10, Blade, Tailwind, MySQL, MediaPipe) dan fitur yang berfungsi dipertahankan. Fitur Virtual Meeting tidak disentuh. Migrasi lama tidak dihapus; migrasi baru hanya menambah.

## 1. Perubahan

**Landing page.** `/` kini halaman publik (sebelumnya redirect ke login). Berisi hero, fitur, indikator fokus, privasi, cara kerja, manfaat, dan tombol Masuk / Mulai Belajar. Pengguna yang sudah login melihat tombol "Buka dashboard". Font disimpan lokal (Fraunces, Plus Jakarta Sans), animasi mengikuti `prefers-reduced-motion`. Ilustrasi papan kelas diberi label "Ilustrasi" dan tidak menampilkan angka palsu. Halaman login, daftar, profil, dan layout aplikasi diselaraskan.

**Monitoring fokus.**
- Aturan baru (`focus-state.js`, fungsi murni): sinyal browser (tab tersembunyi, jendela kehilangan fokus), wajah, dan orientasi HP dibaca terpisah; sumber dicatat di `focus_logs.source` dan ditampilkan.
- Tab tersembunyi: kuning selama kurang dari 10 detik, lalu merah. Wajah hilang: kuning saja, tidak pernah merah. Abu: tidak berjalan atau koneksi terputus.
- Heartbeat tiap 15 detik tetap berjalan saat tab tersembunyi. Server menampilkan abu "Koneksi terputus" bila tidak ada heartbeat lebih dari 60 detik.
- Kamera otomatis mati saat jeda/istirahat dan menyala lagi pada fase fokus bila pengguna memintanya.
- MediaPipe: file wasm lokal (identik dengan paket npm). Model diutamakan dari file lokal; `php artisan synlora:install-face-model` mengunduhnya sekali. Tanpa model, aplikasi menampilkan pesan jelas dan tidak memakai pengganti deteksi warna kulit.
- Privasi: frame kamera tidak ditampilkan, dikirim, atau disimpan.

**Akun dan hak akses.** Kolom `nim` (unik) dan `kelas`; wajib untuk mahasiswa (registrasi dan profil). Registrasi dosen memerlukan `DOSEN_INVITE_CODE` (tertutup bila kosong), opsional pembatasan domain email, dan perintah `synlora:make-dosen`. `role` tidak bisa diisi massal dan tidak bisa diubah lewat profil. Middleware `role:dosen` melindungi `/dosen/*`. Registrasi dibatasi 10 percobaan per menit.

**Dashboard dosen (`/dosen`).** Ringkasan, daftar mahasiswa dengan status, sumber, durasi hari ini, fase dan sisa waktu Pomodoro, terakhir aktif. Filter room, kelas, status, pencarian, pengurutan. Panel riwayat (7 hari, sesi terbaru, 40 aktivitas terakhir) dan ekspor CSV yang aman dari formula injection. Dosen hanya melihat mahasiswa di room miliknya, dan sesi solo tidak tampil. Pembaruan tiap 5 detik hanya saat tab terlihat.

**Produktivitas.** Target harian (10-720 menit) dengan progres, streak saat ini dan terpanjang, grafik 7 hari, rekap 30 hari, rekap per room, rata-rata hari aktif, persentase indikator hijau 30 hari. Semua dihitung dari database; sebuah sesi dihitung pada hari mulainya.

**Optimasi dan keamanan.**
- Indeks baru pada `study_sessions`, `activity_logs`, dan `users`; status fokus terakhir disalin ke `study_sessions` agar dashboard tidak melakukan query per mahasiswa (jumlah query konstan, diuji).
- Presence room tidak lagi menulis ke database tiap polling dan di-cache 2 detik. Dashboard dosen di-cache 3 detik.
- Deadlock saat memulai sesi diperbaiki (kunci per pengguna dan ulang otomatis).
- Header keamanan (nosniff, X-Frame-Options, Referrer-Policy, HSTS saat HTTPS). `APP_DEBUG` bawaan `false`.

## 2. Hasil pengujian

Lingkungan: PHP 8.3, MariaDB 10.11, 1 core CPU, Chromium headless.

| Pengujian | Hasil |
|---|---|
| PHP (`php artisan test`) | 78 lulus (51 bawaan + 27 baru: landing, akun, hak akses, status fokus, dashboard dosen 40 mahasiswa, isolasi data, CSV, target/streak) |
| JavaScript (logika status) | 21 lulus |
| Migrasi | fresh, rollback 2 langkah, migrate ulang, dan upgrade dari data lama: lulus, data lama utuh |
| Simulasi 40 mahasiswa + 1 dosen, 60 detik, server HTTP 16 worker | 0 galat, 0 respons 429, distribusi status di dashboard dosen sesuai skenario (15 hijau, 8 kuning, 6 merah, 11 abu). Heartbeat/feed/state p95 sekitar 45 ms. Login serentak p50 sekitar 4 detik di 1 core (hash password) |
| Browser sungguhan, 13 langkah | Lulus: mulai sesi, tab tersembunyi (kuning lalu merah), kembali, jendela blur, kamera tanpa model (pesan jelas), jeda, lanjut, heartbeat; tanpa galat JavaScript |
| Responsivitas | 8 halaman, lebar 390 px dan 1440 px, tanpa overflow horizontal |

Run pertama simulasi menemukan 2 HTTP 500 (deadlock); sudah diperbaiki dan diuji ulang.

## 3. Kendala dan hal yang belum terselesaikan

1. **Deteksi wajah belum teruji end-to-end.** Model `blaze_face_short_range.tflite` tidak bisa diunduh dari lingkungan pengujian (server Google tidak terjangkau), jadi tidak ikut di ZIP. Hanya jalur gagalnya yang teruji. Jalankan `php artisan synlora:install-face-model` di komputer Anda, lalu uji kamera sendiri.
2. **Belum diuji di Windows/Laragon (MySQL 8)** dan perangkat HP asli. Aturan "HP horizontal" hanya diuji di tingkat logika.
3. **Tab tersembunyi disimulasikan** lewat kode di halaman (bukan pindah tab sungguhan). Browser yang sangat membatasi timer latar belakang dapat menunda perubahan kuning ke merah.
4. **Pembuat room berupa mahasiswa** masih bisa melihat status fokus anggotanya (desain sebelumnya yang dipertahankan). Dosen memakai `/dosen`.
5. **Mahasiswa lama** perlu mengisi NIM dan kelas di Profil.
6. **Belum ada**: CSP ketat (agar Alpine dan Jitsi tidak rusak), verifikasi email, dan konfigurasi SMTP untuk reset kata sandi.
7. **Beban nyata**: simulasi memakai laju sekitar 5 kali lipat browser, tetapi hanya di 1 core dan 1 mesin. Kinerja di server produksi belum diukur.
8. `php artisan test` menghapus isi database yang dipakai (`RefreshDatabase`); gunakan database terpisah.
