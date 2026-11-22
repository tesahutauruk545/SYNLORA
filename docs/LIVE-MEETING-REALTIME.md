# Live Meeting (LiveKit) & Notifikasi Fokus Realtime

## Aktivasi
1. **LiveKit Cloud (gratis)**: buat proyek di https://cloud.livekit.io, lalu isi `.env`:
   `LIVEKIT_URL=wss://<proyek>.livekit.cloud`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`. Kunci hanya di server; browser menerima token berumur pendek dari `POST /rooms/{kode}/meeting/token` (hanya anggota room).
2. **Realtime (Laravel Reverb)**, opsional. Tanpa ini, dashboard dosen tetap memperbarui lewat polling 5 detik.
   - `composer require laravel/reverb pusher/pusher-php-server` (Reverb membutuhkan PHP 8.2+).
   - `.env`: `BROADCAST_DRIVER=reverb`, isi `REVERB_APP_KEY` dan `REVERB_APP_SECRET` dengan string acak panjang; `REVERB_APP_ID`, `REVERB_HOST`, `REVERB_PORT` sesuai `.env.example`.
   - Jalankan di terminal terpisah: `php artisan reverb:start`.
   - Bila `config/reverb.php` bawaan paket menimpa file ini, pertahankan blok `apps` yang membaca `REVERB_*` dari `.env`.
3. Halaman memakai build yang sudah ikut di `public/build`. Hanya bila mengubah JS: `npm install` lalu `npm run build`.

## Cara kerja singkat
- **Satu kamera**: `resources/js/synlora/camera-hub.js` memegang satu `getUserMedia`; Study Meet dan MediaPipe berbagi stream yang sama. Kamera fisik mati setelah keduanya melepas.
- **Event**: `StudySession::logFocus` (satu-satunya tempat status berubah) menyiarkan `FocusStatusChanged` hanya saat status benar-benar berubah, sehingga tidak ada notifikasi berulang. Kegagalan siaran tidak mengganggu pencatatan.
- **Channel privat** (`routes/channels.php`): `room.{kode}.monitor` hanya dosen anggota room (`RoomPolicy@monitor`); `App.Models.User.{id}` hanya pemilik. Payload hanya status, alasan teks server, dan nama; tanpa gambar.
- **Riwayat**: tetap di `focus_logs` dan `activity_logs` (tanpa migration baru); dosen meninjau lewat Monitoring dosen.

## Pengujian
`php artisan test` (butuh database MySQL/MariaDB bernama mengandung "testing"), `node --test tests/js/focus-state.test.mjs`.
