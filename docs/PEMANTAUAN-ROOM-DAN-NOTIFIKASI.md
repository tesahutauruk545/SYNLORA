# Pemantauan per room & notifikasi (24 Nov 2026)

## Menjalankan
    composer install && npm install && npm run build
    php artisan migrate                      # tabel baru saja; data lama tidak disentuh
    php artisan synlora:install-face-model --object   # model wajah + model objek (HP), sekali saja, butuh internet
    php artisan serve  (atau Laragon)

Tanpa file `efficientdet_lite0.tflite` di `public/vendor/mediapipe/models/` (dan `MEDIAPIPE_ALLOW_REMOTE_MODEL=false`),
deteksi HP tidak aktif; wajah, tab, dan notifikasi tetap berjalan.

## Ringkas arsitektur
- Notifikasi: tabel `user_notifications` (unik per user+dedupe_key). Lonceng di navbar, polling 10 dtk saat tab terlihat,
  dorongan Reverb bila aktif. Tersimpan di server, jadi ada setelah refresh / saat offline.
- Pemantauan: `RoomMonitor` + `room_monitor_states` + `room_monitor_alerts`. Terpisah dari `study_sessions` (Pomodoro).
  Klien: `monitor-hub.js` (kamera via CameraHub, MediaPipe FaceDetector + ObjectDetector, Page Visibility) dan `monitor-logic.js` (murni, teruji).
- Server menurunkan status dari jenis event, menghitung durasi tab dari jam server, menolak sinyal wajah/HP bila kamera tidak aktif.
- Dashboard dosen: ringkasan + kolom status + riwayat peringatan memakai komponen yang sudah ada.

## Batasan yang jujur
- Browser tidak dapat melihat aplikasi lain; hanya tab/jendela SYNLORA.
- HP vertikal/horizontal hanya perkiraan dari bentuk kotak; HP di meja tidak dibedakan dari HP yang dipegang. Selalu "terdeteksi", bukan "memakai".
- Menoleh = heuristik 2D dari 4 titik wajah (bukan pose 3D); kacamata/sudut kamera memengaruhi.
- Pindah halaman SYNLORA (buka tugas) melepas dan menyalakan ulang kamera dalam ±1–2 dtk (halaman dimuat ulang); niat kamera
  disimpan di sessionStorage, izin tidak diminta lagi. Kamera tidak "terus menyala tanpa putus" karena situs ini multi-halaman.
- Hak monitoring tidak diubah: hanya DOSEN anggota room yang menerima peringatan; owner room mahasiswa tidak.
