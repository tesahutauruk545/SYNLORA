# Laporan Perbaikan SYNLORA

## Hasil pengujian
- 51 test PHP (feature test, MariaDB 10.11): **lulus semua**, mencakup autentikasi, room, hak akses, Pomodoro, pemantauan fokus, privasi, dashboard, materi, dan tugas.
- 12 test JavaScript untuk aturan status fokus: **lulus semua**.
- Uji HTTP nyata (server berjalan): daftar → dashboard → buat room → mulai sesi → kirim event → selesai → rekap; CSRF (419) dan akses tanpa login (401) bekerja.
- Uji upgrade: database dengan skema lama + data diisi, lalu `migrate` → data utuh; `rollback` dan migrate ulang bersih.

**Belum teruji:** kamera dan MediaPipe di browser sungguhan (sandbox tidak punya browser/kamera). Kode sudah dicek sintaks dan ter-build, tetapi alur kamera perlu Anda coba sendiri (checklist di bawah).

## Bug yang ditemukan dan diperbaiki
| Masalah di kode awal | Perbaikan |
|---|---|
| Deteksi "wajah" memakai warna kulit | Diganti MediaPipe Face Detector (lokal di browser) |
| Timer hanya di JavaScript; tidak tersimpan, hilang saat refresh | Timer dihitung server; jeda/lanjut/selesai/fase istirahat tersimpan di database |
| Siapa pun bisa membaca aktivitas room mana pun, dan menulis log, ubah status, atau melihat rekap sesi orang lain (hanya `auth`) | Policy pada semua endpoint (anggota, owner, pemilik sesi) |
| Rekap bisa dibuka dengan id sesi mana pun | Hanya pemilik sesi atau owner room |
| Feed memakai `innerHTML` (nama/detail bisa menyisipkan skrip) | Dirender dengan `textContent`; rekap di-escape Blade |
| Dashboard hardcode "0 Jam 0 Menit / 0 Sesi / 0 Hari"; tombol Pomodoro Solo `href="#"` | Statistik dari `study_sessions`; Pomodoro Solo berfungsi |
| `last_active` pivot dipanggil sebagai tanggal padahal string (error 500 saat halaman room/feed dibuka) | Di-parse dengan benar |
| Logo rusak (`logo.png.png` vs `logo.png`); halaman login memakai logo Laravel | File disalin, halaman login memakai logo SYNLORA |
| Error "Table 'rooms' already exists" saat migrate | Migration lama diberi penjaga `hasTable`/`hasColumn` (struktur tidak diubah) |
| Kode room dari `md5(uniqid())` bisa bentrok | Generator acak tanpa karakter ambigu + cek keunikan |
| Waktu campur UTC dan DB sehingga jam bisa meleset | Timezone `Asia/Jakarta` di app dan koneksi DB; semua waktu diisi dari PHP |
| `focus_logs` tidak pernah dipakai | Menyimpan perubahan status fokus; dasar perhitungan rekap |
| Validasi form create room tidak tampil; error join/upload tidak jelas | Pesan error ditampilkan |
| File kosong bernama `id()`, `onDelete('cascade')`, dst. di root project | Dihapus (sisa salah tempel kode) |
| `start.bat` memakai path `C:\projects\synlora` | Memakai folder tempat file berada |

## Fitur yang ditambahkan
- **Room:** peran owner/anggota, keluar room, owner mengeluarkan anggota / menghapus room, daftar "Room Saya", tombol salin kode, throttle pada pencarian kode.
- **Pomodoro:** mulai, jeda, lanjutkan, akhiri; pergantian fokus ↔ istirahat otomatis di server; Pomodoro solo; halaman Riwayat; sesi yang ditinggalkan (tidak ada detak 15 menit) ditutup otomatis pada waktu detak terakhir agar waktu belajar tidak menggelembung; hanya satu sesi terbuka per pengguna.
- **Monitoring:** tab (Page Visibility), orientasi HP, wajah (MediaPipe, opsional); penghitungan memakai cap waktu sehingga tidak meleset di tab latar belakang.
- **Dashboard:** total waktu fokus, jumlah pomodoro dan sesi, streak, grafik 7 hari, room saya, tugas belum dikumpulkan, sesi terbaru.
- **Materi:** owner unggah (maks. 10 MB, daftar ekstensi dibatasi), anggota unduh, file privat.
- **Tugas:** owner membuat tugas dengan batas waktu; anggota mengumpulkan file/catatan (bisa diperbarui, ditandai terlambat); owner melihat semua pengumpulan, menilai, dan melihat siapa yang belum mengumpulkan.

## Privasi dan keamanan
- Anggota biasa hanya melihat **aktivitas dirinya sendiri**; anggota lain hanya tampil sebagai "Sedang belajar / Istirahat / Dijeda / Tidak sedang belajar". Status fokus (hijau/kuning/merah) dan feed semua anggota hanya untuk **owner room** dan pemilik datanya. Email anggota tidak pernah ditampilkan.
- Video tidak ditampilkan, tidak dikirim, tidak disimpan. Server hanya menerima jenis event + status; waktu event ditentukan server.
- Rekap sesi solo hanya bisa dilihat pemiliknya.
- `.env` tidak dibaca ke laporan ini dan tidak disertakan dalam paket.

## Keputusan yang mengubah perilaku lama (silakan tinjau)
1. **Wajah tidak terlihat tidak lagi MERAH.** Sebelumnya 10 detik tanpa wajah = "TIDAK FOKUS". Sekarang ≥ 5 detik = KUNING (peringatan saja), dan MERAH hanya untuk pindah tab/aplikasi atau HP horizontal, karena wajah hilang bisa terjadi saat menulis atau menunduk dan bukan bukti gangguan. Rekap menyebutnya "indikator", bukan ukuran konsentrasi. Ambang ada di `resources/js/synlora/focus-state.js` jika ingin diubah.
2. **Tombol "Koreksi Manual" dihapus.** Status kini pulih otomatis saat penyebabnya hilang, sehingga tombol itu hanya menjadi cara menimpa data pemantauan.
3. **Pemantauan tab/orientasi kini berjalan walau kamera tidak diaktifkan** (kamera opsional); sebelumnya hanya setelah kamera menyala.
4. **Penilaian/kesimpulan seperti "Mahasiswa sangat fokus" dihapus** dari rekap diganti dengan angka indikator.
5. Anggota tidak lagi melihat status fokus sesama anggota (sebelumnya semua orang melihat semuanya).

## Batasan yang perlu diketahui
- "Real-time" memakai polling tiap 4 detik, bukan WebSocket (`BROADCAST_DRIVER=log`, Pusher belum diatur).
- Model face detector diunduh dari Google saat pertama dipakai (atau letakkan manual; lihat panduan).
- Kamera butuh localhost atau HTTPS.
- Halaman registrasi tetap memakai nama, email, peran, password (tidak ada NIM/kelas). Peran "dosen" dipilih sendiri saat daftar; hak akses fitur ditentukan peran di room (owner/anggota), bukan peran akun.
- Verifikasi email dan reset password lewat email butuh pengaturan SMTP.
- `APP_DEBUG=true` pada `.env` Anda; ubah ke `false` saat dipakai publik.

## Checklist uji manual (yang tidak bisa saya uji di sini)
1. Buka http://localhost:8000, login, masuk room, **Mulai Sesi**, klik **Aktifkan Kamera**, izinkan. Status harus HIJAU saat wajah terlihat.
2. Tutup wajah dengan tangan ≥ 5 detik → KUNING; buka → HIJAU. (Tidak boleh MERAH.)
3. Pindah ke tab lain → MERAH; kembali → pulih. Cek Rekap: "Pindah Tab" bertambah.
4. Di HP (HTTPS): putar horizontal → MERAH; kembali tegak → pulih.
5. Refresh halaman saat sesi berjalan → timer melanjutkan, tidak mulai ulang.
6. Login sebagai anggota lain: tidak boleh melihat status fokus/aktivitas anggota lain.

## Daftar file
Baru: `app/Policies/*`, `app/Services/*`, `app/Http/Controllers/{Dashboard,Session,Material,Assignment}Controller.php`, `app/Models/{Material,Assignment,Submission}.php`, 5 migration `2026_11_14_*`, `resources/js/synlora/*`, view `partials/session-panel`, `pomodoro/solo`, `sessions/{index,recap}`, `assignments/show`, `tests/Feature/SynloraTest.php`, `tests/js/*`, `public/vendor/mediapipe/*`.
Diubah: `routes/web.php`, `RoomController`, semua model, `config/app.php` (timezone), `config/database.php` (timezone DB), layout dan navigasi, `dashboard`, `rooms/show|create|join`, `tailwind.config.js`, `package.json`, `.env.example`, `start.bat`, test Breeze lama (`RegistrationTest`, `ExampleTest`) disesuaikan dengan field peran dan redirect `/`.
Dihapus: `rooms/recap.blade.php` (digantikan `sessions/recap`), `ActivityController` kosong, file sampah di root.
