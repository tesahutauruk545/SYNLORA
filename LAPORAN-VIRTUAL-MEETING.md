# Laporan Pengembangan Virtual Meeting SYNLORA

## Ringkasan hasil uji
| Pengujian | Hasil |
|---|---|
| Test PHP (feature; 4 akun: owner, 2 anggota, orang luar) | **72 lulus** |
| Test JavaScript (status fokus, kanal real-time dengan WebSocket tiruan) | **28 lulus** |
| Server WebSocket dengan klien WebSocket nyata (autentikasi, penyaringan penerima, frame, beban 60 klien) | **8 lulus** |
| **E2E multi-akun**: server Laravel + daemon WebSocket + MySQL sungguhan, halaman meeting & kode frontend asli di jsdom | **10 lulus** |
| Migrasi pada DB lama berisi data, rollback, migrate ulang | bersih |
| Smoke test HTTP nyata (CSRF, beacon keluar, semua route meeting di balik `auth`) | lolos |

Terukur pada E2E: status wajah-hilang tampil di dashboard owner **±240 ms setelah masa tenggang** lewat WebSocket (1235 ms total termasuk tenggang 1000 ms), ±2 detik lewat polling saat WebSocket mati, dan WebSocket tersambung kembali otomatis setelah server dihidupkan lagi.

### Yang TIDAK dapat saya uji di sini (jujur)
- **Video call Jitsi sungguhan** (audio, video, kamera/mikrofon, screen sharing, daftar peserta): sandbox tidak punya browser dan tidak bisa menjangkau `meet.jit.si`. Integrasi IFrame API (opsi, event, toolbar) diuji dengan Jitsi tiruan; JWT RS256 diuji tanda tangan dan klaimnya, tetapi belum terhadap JaaS asli.
- **Kamera + MediaPipe di browser sungguhan**: pada E2E, kamera dan detektor wajah ditiru (status "wajah ada/hilang" dikendalikan test). Logika status, masa tenggang, dan pengiriman data diuji dengan kode asli; akurasi deteksi wajah nyata perlu Anda coba (checklist di bawah).
- HTTPS/`wss://`, perangkat HP, dan beban puluhan peserta sungguhan.

## Fitur yang dibuat
1. **Virtual Meeting per room** (`/rooms/{kode}/meeting`): tombol **Join Virtual Meeting** di setiap room, Jitsi IFrame API dengan mikrofon, kamera, screen sharing, chat, angkat tangan, tampilan tile, dan daftar peserta (toolbar Jitsi). Setiap meeting mendapat nama ruang acak sendiri; perekaman, siaran, dan perekaman lokal dimatikan.
2. **Monitoring fokus otomatis**: mulai ketika peserta tersambung ke konferensi, berhenti ketika keluar atau meeting berakhir. MediaPipe Face Detector + Page Visibility API. 🟢 wajah terdeteksi dan halaman aktif; 🔴 wajah tidak terdeteksi (> 3 detik) atau pindah tab; ⚪ tidak terpantau (izin kamera ditolak/kamera gagal).
3. **Monitoring peserta**: owner melihat status semua peserta secara real-time (status, alasan, durasi hadir berjalan, jumlah masuk-ulang, terputus/keluar). Peserta hanya melihat statusnya sendiri. Pemfilteran dilakukan di backend dan di server WebSocket, bukan hanya di tampilan.
4. **Database**: waktu meeting, durasi kehadiran per segmen, riwayat perubahan fokus, dan log kejadian per peserta.
5. **Keamanan & privasi**: hanya anggota room (policy di semua endpoint), izin kamera diminta saat bergabung, video tidak direkam/dikirim/disimpan, email tidak dikirim ke Jitsi atau ke peserta lain.
6. **Peserta masuk, keluar, terputus, bergabung kembali**; owner dapat mengakhiri meeting untuk semua.
7. **Laporan meeting** (`/rooms/{kode}/meetings/{id}`) dan riwayat meeting di halaman room.
8. **Real-time**: server WebSocket PHP bawaan tanpa dependensi (`php artisan synlora:realtime`) dengan fallback polling otomatis.

## Perilaku siklus hidup
- **Mulai**: anggota pertama yang masuk membuat meeting. **Berakhir**: peserta terakhir keluar, semua peserta terputus > `MEETING_ABANDON_SECONDS`, atau owner menekan "Akhiri untuk Semua". Meeting baru = nama ruang Jitsi baru.
- **Detak** tiap 10 detik. Tanpa detak 45 detik → "terputus" (tampil di owner). Detak kembali setelah jeda > 45 detik → segmen lama ditutup pada detak terakhir dan segmen baru dibuka ("bergabung kembali"); **celah waktu tidak dihitung hadir**. Tanpa detak 120 detik → kehadiran ditutup pada detak terakhir.
- **Muat ulang halaman**: kehadiran lama ditutup ("replaced") dan dibuka baru tanpa tercatat sebagai keluar; beacon keluar dari halaman lama membawa `attendance_id` sehingga tidak menutup kehadiran baru.
- **Dikeluarkan dari room / room dihapus**: kehadiran ditutup, peserta langsung diberi tahu dan monitoring berhenti.
- **Pomodoro independen**: tidak ada kolom/relasi antara meeting dan `study_sessions`; riwayat fokus meeting disimpan di `meeting_focus_logs`, bukan `focus_logs`. Terverifikasi di test PHP dan E2E.

## Migration baru (tidak mengubah migration lama)
`2026_11_15_000001_create_meetings_table.php` membuat 4 tabel (semua dengan penjaga `hasTable`, FK cascade):
- `meetings`: room, pembuat, `jitsi_name` (unik), status LIVE/ENDED, `started_at`, `ended_at`, `end_reason`, `peak_participants`
- `meeting_attendances`: satu baris per segmen hadir: `joined_at`, `left_at`, `last_seen_at`, `leave_reason`, status fokus saat ini
- `meeting_focus_logs`: riwayat perubahan status (HIJAU/MERAH/ABU) + alasan + waktu
- `meeting_events`: JOINED, REJOINED, LEFT, DISCONNECTED, TAB_HIDDEN, FACE_LOST, CAMERA_DENIED, dst.

## Konfigurasi (`config/synlora.php` + `.env`)
`JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_KID`, `JITSI_PRIVATE_KEY_PATH`; `REALTIME_ENABLED`, `REALTIME_PORT`, `REALTIME_INTERNAL_PORT`, `REALTIME_PUBLIC_URL`, `REALTIME_SECRET`, `REALTIME_ALLOWED_ORIGINS`; `MEETING_HEARTBEAT_SECONDS`, `MEETING_STALE_SECONDS`, `MEETING_ABANDON_SECONDS`, `MEETING_FACE_GRACE_MS`. Contoh lengkap ada di `.env.example`. Langkah Laragon ada di `PANDUAN-LARAGON.md`.

## Hal penting yang harus Anda ketahui
1. **meet.jit.si publik hanya untuk uji coba**: embed dibatasi ±5 menit dan meeting anonim tidak diizinkan (berdasarkan dokumentasi/komunitas Jitsi saat ini). Untuk dipakai nyata gunakan JaaS atau server sendiri (lihat panduan). SYNLORA menampilkan peringatan ini di halaman meeting bila tanpa JWT.
2. **Pembatasan anggota**: tanpa JWT, hanya nama ruang acak yang melindungi ruang video; dengan JWT (JaaS/self-hosted), Jitsi sendiri menolak non-anggota dan owner menjadi moderator. "Akhiri untuk Semua" menutup meeting SYNLORA dan memaksa semua halaman keluar; pada server tanpa JWT, ruang Jitsi tidak ikut dimatikan di sisi Jitsi (hanya hilang karena tidak ada yang tersisa dan nama baru dipakai untuk meeting berikutnya).
3. **Aturan merah di meeting berbeda dari ruang belajar/Pomodoro**: sesuai permintaan, di meeting wajah tidak terdeteksi = merah (dengan tenggang 3 detik agar tidak berkedip). Panel monitoring di ruang belajar/Pomodoro tidak diubah (di sana wajah hilang tetap kuning).
4. **Kamera dipakai dua kali** (Jitsi dan detektor wajah). Di desktop Chrome/Edge/Firefox umumnya aman. Di sebagian HP (terutama iOS Safari) kamera hanya boleh satu pengguna; bila direbut, status menjadi ⚪ "Kamera terputus/dipakai aplikasi lain", bukan dipalsukan. Untuk monitoring wajah yang andal, gunakan laptop.
5. **Menolak izin kamera** tetap boleh ikut meeting, tetapi statusnya ⚪ tidak terpantau (pindah tab tetap 🔴). Owner melihat hal ini. Jika Anda ingin kamera wajib, itu perubahan kecil yang bisa ditambahkan.
6. Tab yang disembunyikan lama dapat diperlambat browser (timer detak). Karena ada koneksi WebRTC Jitsi, Chrome biasanya tidak memperlambatnya; bila terjadi, owner melihat "terputus" sementara lalu pulih otomatis.
7. Server WebSocket adalah satu proses PHP tanpa TLS; untuk HTTPS gunakan reverse proxy `wss://`. Tanpa itu aplikasi otomatis memakai polling.

## Checklist uji manual (yang tidak bisa saya uji)
1. Dua perangkat/akun gabung meeting → video dan audio dua arah terlihat; coba matikan/hidupkan mic & kamera, **screen sharing**, dan tombol daftar peserta.
2. Dengan kamera aktif: wajah terlihat → hijau; tutup wajah > 3 detik → merah di akun owner; buka → hijau.
3. Pindah tab → merah; kembali → hijau. Tolak izin kamera → ⚪ dan pesan jelas.
4. Matikan jaringan akun 2 selama ±1 menit → owner melihat "terputus"; nyalakan → "bergabung kembali".
5. Matikan `php artisan synlora:realtime` → indikator beralih ke polling dan status tetap tersinkron; hidupkan lagi → kembali WebSocket.
6. Owner "Akhiri untuk Semua" → akun lain diberi tahu dan kamera berhenti; buka **Laporan** dan cocokkan durasi.

## Berkas
Baru: `app/Models/Meeting*.php`, `app/Services/{MeetingService,MeetingReport,JitsiService}.php`, `app/Http/Controllers/MeetingController.php`, `app/Policies/MeetingPolicy.php`, `app/Realtime/*`, `app/Console/Commands/{RealtimeServe,MeetingsReap}.php`, `config/synlora.php`, `database/migrations/2026_11_15_000001_create_meetings_table.php`, `resources/views/meetings/{show,report}.blade.php`, `resources/js/synlora/{meeting-page,meeting-monitor,meeting-state,meeting-realtime,face-detector-loader}.js`, `tests/Feature/MeetingTest.php`, `tests/Support/*`, `tests/js/*`, `tests/e2e/*`.
Diubah: `routes/web.php`, `RoomController` (kartu meeting, tutup kehadiran saat keluar/dikeluarkan), `Room` & `AppServiceProvider` & `AuthServiceProvider`, `rooms/show.blade.php`, `resources/js/app.js`, `.env.example`, `start.bat`, `package.json` (skrip `test:js`/`test:e2e`, devDependency `jsdom`), `public/build` (hasil build).
Fitur sebelumnya (Pomodoro, ruang belajar, materi, tugas, dashboard) tidak diubah dan seluruh test lamanya tetap lulus.
