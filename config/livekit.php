<?php

/*
 | Live Meeting (LiveKit). Kunci HANYA dibaca di server (.env); frontend hanya menerima token berumur pendek
 | dari LiveMeetingController. Bila salah satu kosong, fitur meeting menampilkan pesan "belum dikonfigurasi".
 | LiveKit Cloud Free Tier: https://cloud.livekit.io (URL berbentuk wss://nama-proyek.livekit.cloud)
 */
return [
    'url' => env('LIVEKIT_URL'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // Masa berlaku token akses (detik). Klien meminta token baru saat reconnect.
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 7200),
];
