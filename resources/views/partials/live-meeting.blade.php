{{--
    Study Meet (LiveKit) tertanam di halaman room. Variabel: $room
    Token dibuat server; API Key/Secret tidak pernah dikirim ke browser.
--}}
@php
    $meetingCfg = [
        'tokenUrl' => route('rooms.meeting.token', $room),
        'csrf' => csrf_token(),
    ];
    $meetingReady = \App\Services\LiveKitToken::configured();
@endphp

<script type="application/json" id="meeting-config">@json($meetingCfg)</script>

<div class="card overflow-hidden mb-6" id="study-meet">
    <div class="p-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
            <h3 class="text-lg font-semibold text-synlora-800">🎥 Study Meet</h3>
            <span class="text-sm text-synlora-600">Peserta: <strong id="meet-count">0</strong></span>
        </div>
        <p class="text-xs text-synlora-500 mb-4">
            Video meeting khusus anggota room ini. Pomodoro dan Monitoring Fokus tetap berjalan bersamaan; kamera yang sama dipakai bersama (tidak diminta dua kali).
        </p>

        @unless ($meetingReady)
            <div class="mb-4 p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm" role="status">
                Study Meet belum dikonfigurasi di server ini. Administrator perlu mengisi <code>LIVEKIT_URL</code>, <code>LIVEKIT_API_KEY</code>, dan <code>LIVEKIT_API_SECRET</code> di <code>.env</code>.
            </div>
        @endunless

        <div id="meet-error" class="hidden mb-4 p-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg text-sm" role="alert"></div>

        <div class="flex flex-wrap items-center gap-3 mb-4">
            <span id="meet-status" class="text-sm text-synlora-600" aria-live="polite">Belum tersambung</span>
            <button id="meet-join" type="button" class="btn-primary btn-sm" @disabled(! $meetingReady)>▶️ Gabung Meeting</button>
            <div id="meet-controls" class="hidden flex flex-wrap gap-2">
                <button id="meet-mic" type="button" class="btn-ghost btn-sm" aria-pressed="false">🔇 Mikrofon: Mati</button>
                <button id="meet-cam" type="button" class="btn-ghost btn-sm" aria-pressed="false">🚫 Kamera: Mati</button>
                <button id="meet-screen" type="button" class="btn-ghost btn-sm" aria-pressed="false">🖥️ Berbagi layar</button>
                <button id="meet-leave" type="button" class="btn-danger btn-sm">🚪 Keluar</button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_16rem]">
            <div>
                <div id="meet-grid" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3"></div>
                <p id="meet-empty" class="text-sm text-synlora-500">Belum ada peserta di meeting. Klik "Gabung Meeting" untuk memulai.</p>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-synlora-600 mb-2">Daftar peserta</div>
                <ul id="meet-participants" class="space-y-2"></ul>
            </div>
        </div>

        <p class="mt-4 text-xs text-synlora-500">
            Video meeting dilihat peserta meeting lewat LiveKit sesuai pilihan Anda (kamera dan mikrofon mati secara bawaan). Deteksi wajah untuk Monitoring Fokus tetap diproses lokal di perangkat Anda; SYNLORA tidak menyimpan atau mengirim gambar wajah ke server.
        </p>
    </div>
</div>
