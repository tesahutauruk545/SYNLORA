<?php

return [
    /*
     | Peran dosen memberi hak MONITORING (lihat RoomPolicy::monitor), jadi tidak boleh dipilih bebas.
     | Registrasi mahasiswa tetap terbuka. Registrasi dosen diatur oleh DOSEN_REGISTRATION:
     |   invite : wajib memasukkan kode undangan (DOSEN_INVITE_CODE)  <- bawaan, paling aman
     |   closed : akun dosen hanya lewat `php artisan synlora:make-dosen email`
     |   open   : siapa pun dapat memilih dosen (perilaku lama; TIDAK disarankan)
     | Bila mode `invite` tetapi DOSEN_INVITE_CODE kosong, registrasi dosen ditolak (gagal aman).
     */
    'dosen_registration' => env('DOSEN_REGISTRATION', 'invite'),
    'dosen_invite_code' => env('DOSEN_INVITE_CODE'),

    // Kapasitas bawaan room (dapat diubah per room oleh owner, maksimal 300).
    'room_max_members' => (int) env('ROOM_MAX_MEMBERS', 60),

    // Batas total penyimpanan file (materi + pengumpulan) per room, dalam MB.
    'room_storage_mb' => (int) env('ROOM_STORAGE_MB', 500),

    // Retensi log aktivitas/fokus dalam hari. 0 = tidak pernah dihapus otomatis.
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 0),

    // Batas per alamat IP. Satu kelas sering login dari SATU IP kampus (NAT), jadi batas ini sengaja longgar;
    // perlindungan utama brute force adalah batas per email+IP (5 percobaan).
    'login_ip_max_failures' => (int) env('LOGIN_IP_MAX_FAILURES', 150),
    'register_per_minute' => (int) env('REGISTER_PER_MINUTE', 120),

    'focus' => [
        // Browser tidak mengirim detak lebih dari ini -> status dianggap ABU (terputus) di dashboard dosen.
        'stale_seconds' => (int) env('FOCUS_STALE_SECONDS', 60),
        // Tab tersembunyi lebih dari ini -> MERAH (kurang dari itu hanya KUNING).
        'hidden_grace_seconds' => (int) env('FOCUS_HIDDEN_GRACE_SECONDS', 10),
        // Wajah tidak terlihat selama ini -> KUNING (tidak pernah MERAH).
        'face_missing_seconds' => (int) env('FOCUS_FACE_MISSING_SECONDS', 5),
    ],

    'integrity' => [
        // Batas jendela deteksi dua tab / dua perangkat memakai akun dan sesi yang sama.
        'multi_client_window_seconds' => (int) env('INTEGRITY_MULTI_CLIENT_WINDOW', 45),
        // Celah antar-kontak yang dianggap koneksi putus nyata (menentukan batas mundur timestamp event tertunda).
        'gap_seconds' => (int) env('INTEGRITY_GAP_SECONDS', 20),
        // Persentase waktu tidak terpantau (terputus/belum mulai) yang membuat data dianggap tidak lengkap.
        'incomplete_unmonitored_percent' => (int) env('INTEGRITY_INCOMPLETE_PERCENT', 30),
        // Jumlah episode MERAH (tab ditinggalkan lama) pada satu sesi yang ditandai "perlu ditinjau".
        'review_red_episodes' => (int) env('INTEGRITY_REVIEW_RED_EPISODES', 3),
    ],

    'mediapipe' => [
        // Bila model tidak ada di public/vendor/mediapipe/models, boleh diunduh dari server Google (hanya model, bukan data pengguna).
        'allow_remote_model' => (bool) env('MEDIAPIPE_ALLOW_REMOTE_MODEL', true),
        'model_file' => 'blaze_face_short_range.tflite',
        'model_remote_url' => 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite',
    ],

    'monitor' => [
        'poll_seconds' => (int) env('MONITOR_POLL_SECONDS', 5),
        'cache_seconds' => (int) env('MONITOR_CACHE_SECONDS', 3),
    ],
];
