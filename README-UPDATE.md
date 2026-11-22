# SYNLORA — Update Live Meeting & Notifikasi Realtime

## Cara update di hosting
1. Backup database dan folder aplikasi lama.
2. Upload isi ZIP, timpa file lama. **Jangan timpa `.env` Anda.**
3. `composer install --no-dev -o` (folder `vendor` tidak disertakan).
4. `php artisan config:clear && php artisan route:clear && php artisan view:clear`
5. Pastikan `storage/` dan `bootstrap/cache/` dapat ditulis web server.
6. Migration: **tidak ada migration baru** (`php artisan migrate --force` aman dijalankan, tidak mengubah apa pun).
7. `public/build` sudah berisi hasil build; `npm install`/`npm run build` hanya perlu bila Anda mengubah JS.

## Konfigurasi
Aplikasi tetap berjalan normal tanpa dua fitur di bawah ini.

**Live Meeting (LiveKit)**, di `.env` (jangan taruh di frontend):
`LIVEKIT_URL=wss://...livekit.cloud`, `LIVEKIT_API_KEY=`, `LIVEKIT_API_SECRET=`
Kosong = kartu Study Meet menampilkan "belum dikonfigurasi".

**Realtime (Reverb)**, opsional:
`composer require laravel/reverb pusher/pusher-php-server` (butuh PHP 8.2+), lalu di `.env`:
`BROADCAST_DRIVER=reverb`, `REVERB_APP_KEY=`, `REVERB_APP_SECRET=` (string acak panjang), `REVERB_APP_ID`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`; jalankan `php artisan reverb:start`.
`BROADCAST_DRIVER=log` (bawaan) = dashboard dosen tetap polling 5 detik.

## Status pengujian
Teruji: 140 test PHP, 29 test JS, token LiveKit, otorisasi channel, satu kamera bersama (browser headless).
**Belum teruji**: koneksi LiveKit nyata, WebSocket Reverb ujung ke ujung, deteksi wajah MediaPipe dengan model nyata. Jangan anggap aktif sebelum Anda mencobanya. Detail: `docs/LIVE-MEETING-REALTIME.md`.
