{{--
    Panel Pomodoro + Pemantauan Fokus. Dipakai oleh halaman room dan Pomodoro solo.
    Variabel: $mode ('room'|'solo'), $activeSession, $otherSession, $room (mode room), $defaultFocusMinutes
--}}
@php
    // Model lokal diutamakan. Unduhan dari Google hanya dipakai bila file lokal belum ada DAN diizinkan di .env.
    $modelFile = config('synlora.mediapipe.model_file');
    $faceModelPaths = [];
    if (is_file(public_path('vendor/mediapipe/models/'.$modelFile))) {
        $faceModelPaths[] = asset('vendor/mediapipe/models/'.$modelFile);
    } elseif (config('synlora.mediapipe.allow_remote_model')) {
        $faceModelPaths[] = config('synlora.mediapipe.model_remote_url');
    }
    $cfg = [
        'mode' => $mode,
        'csrf' => csrf_token(),
        'loginUrl' => route('login'),
        'startUrl' => $mode === 'room' ? route('rooms.sessions.store', $room) : route('pomodoro.start'),
        'feedUrl' => $mode === 'room' ? route('rooms.getActivities', $room) : null,
        'defaultSeconds' => $defaultFocusMinutes * 60,
        'initial' => $activeSession ? \App\Http\Controllers\SessionController::payloadFor($activeSession) : null,
        'mediapipe' => [
            'wasmPath' => asset('vendor/mediapipe/wasm'),
            'modelPaths' => $faceModelPaths,
        ],
    ];
@endphp

<script type="application/json" id="synlora-config">@json($cfg)</script>

<div id="session-error" class="hidden mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg text-sm" role="alert"></div>

@if ($otherSession)
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
        Anda masih punya sesi {{ $otherSession->isPaused() ? 'yang dijeda' : 'yang berjalan' }}
        {{ $otherSession->room ? 'di room "'.$otherSession->room->name.'"' : '(Pomodoro solo)' }}.
        Memulai sesi baru di sini akan mengakhiri sesi tersebut.
        <a class="underline font-semibold" href="{{ $otherSession->room ? route('rooms.show', $otherSession->room) : route('pomodoro.index') }}">Buka sesi itu</a>
    </div>
@endif

<!-- Pomodoro Timer -->
<div class="card overflow-hidden mb-6">
    <div class="p-6">
        <h3 class="text-lg font-semibold text-synlora-800 mb-4">⏱️ Pomodoro Timer</h3>
        <div class="text-center">
            <div id="timer-phase" class="text-sm font-semibold text-synlora-600 uppercase tracking-wider mb-1">Siap</div>
            <div id="timer-display" class="text-6xl sm:text-7xl font-bold text-synlora-900 mb-2 tabular-nums">
                {{ str_pad((string) $defaultFocusMinutes, 2, '0', STR_PAD_LEFT) }}:00
            </div>
            <div id="timer-count" class="text-sm text-synlora-500 mb-2 h-5"></div>
            <div id="timer-status" class="text-lg text-synlora-600 mb-4">Siap memulai sesi fokus</div>

            <div id="timer-settings" class="mb-4 flex flex-wrap justify-center gap-4 text-sm text-synlora-700">
                @if ($mode === 'solo')
                    <label class="flex items-center gap-2">Fokus (menit)
                        <input id="input-focus-minutes" type="number" min="1" max="180" value="25"
                               class="w-20 px-2 py-1 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                    </label>
                @else
                    <span>Fokus: <strong>{{ $room->target_minutes }} menit</strong> (target room)</span>
                @endif
                <label class="flex items-center gap-2">Istirahat (menit)
                    <input id="input-break-minutes" type="number" min="1" max="60" value="5"
                           class="w-20 px-2 py-1 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                </label>
                <div class="w-full max-w-lg space-y-3 text-left">
                    <label class="block">Tujuan belajar sesi ini (opsional)
                        <input id="input-study-goal" type="text" maxlength="160" placeholder="mis. Selesaikan latihan bab 3"
                               class="mt-1 w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                    </label>
                    <p class="text-xs text-synlora-600">
                        Yang dipantau: apakah tab/jendela SYNLORA aktif dan, bila Anda mengaktifkannya, apakah wajah terlihat kamera.
                        Video tidak direkam dan tidak dikirim; deteksi wajah berjalan di perangkat Anda. Hasilnya hanya indikator, bukan bukti konsentrasi.
                        Gangguan kamera atau koneksi tidak dianggap pelanggaran.
                    </p>
                    @if ($mode === 'room' && $room->strict_mode)
                        <label class="flex items-start gap-2 text-xs text-synlora-700">
                            <input id="input-consent" type="checkbox" class="mt-0.5 rounded border-synlora-300">
                            <span><strong>Mode ketat.</strong> Sesi ini diawasi dosen. Saya memahami pemantauan di atas, dan dosen dapat meninjau riwayat aktivitas, gangguan koneksi/kamera, dan refleksi sesi saya.</span>
                        </label>
                    @endif
                </div>
            </div>

            <div class="flex justify-center gap-3 flex-wrap">
                <button id="btn-start" type="button"
                    class="btn-primary">
                    ▶️ Mulai Sesi
                </button>
                <button id="btn-pause" type="button"
                    class="btn-warn hidden">
                    ⏸️ Jeda
                </button>
                <button id="btn-resume" type="button"
                    class="btn-ok hidden">
                    ▶️ Lanjutkan
                </button>
                <button id="btn-end" type="button"
                    class="btn-danger hidden">
                    🏁 Akhiri &amp; Lihat Rekap
                </button>
            </div>
            <p class="mt-4 text-xs text-synlora-500">
                Waktu dihitung oleh server dan tersimpan di database, jadi tetap akurat walau halaman dimuat ulang.
            </p>
        </div>
    </div>
</div>

<!-- Monitoring Fokus -->
<div class="card overflow-hidden mb-6 mf">
    <div class="p-6 mf-body">
        <h3 class="text-lg font-semibold text-synlora-800 mb-4">📷 Monitoring Fokus</h3>

        {{-- Video disembunyikan secara visual namun tetap dirender agar frame dapat dibaca detektor (juga di Safari/iOS). --}}
        <div class="relative">
            <video id="camera-feed" autoplay muted playsinline
                   class="absolute w-px h-px opacity-0 pointer-events-none" aria-hidden="true"></video>
        </div>

        <div class="mf-grid">
            {{-- Kolom kiri: status, kontrol kamera, error, red alert --}}
            <div class="mf-col mf-left">
                <div class="mf-label">Status monitoring</div>
                <div id="focus-status-display" class="p-6 bg-slate-100 rounded-lg border-4 border-slate-300" aria-live="polite">
                    <div class="text-slate-600 font-bold text-xl">⚪ Pemantauan tidak berjalan</div>
                    <div class="text-sm text-slate-600 mt-2">Mulai sesi fokus untuk mengaktifkannya.</div>
                </div>

                <div id="camera-error" class="hidden p-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg text-sm" role="alert"></div>

                <div id="red-alert" class="p-4 bg-rose-600 text-white rounded-lg hidden">
                    <div class="font-bold text-lg">🚨 Terindikasi terganggu</div>
                    <div id="red-alert-reason" class="text-sm mt-1"></div>
                </div>

                <div class="mf-cam">
                    <div class="flex gap-2">
                        <button id="btn-enable-camera" type="button"
                            class="btn-primary flex-1">
                            🎥 Aktifkan Kamera (opsional)
                        </button>
                        <button id="btn-disable-camera" type="button"
                            class="btn-danger flex-1 hidden">
                            🚫 Matikan Kamera
                        </button>
                    </div>
                    <p class="mf-hint">Tanpa kamera, status tetap dipantau dari aktivitas tab SYNLORA.</p>
                </div>
            </div>

            {{-- Kolom kanan: penjelasan indikator --}}
            <div class="mf-col mf-right">
                <h5 class="mf-title">Cara kerja indikator</h5>
                <ul class="mf-legend">
                    <li><i class="mf-dot g" aria-hidden="true"></i><span><strong>HIJAU</strong> — tab SYNLORA aktif dan (jika kamera menyala) wajah terdeteksi</span></li>
                    <li><i class="mf-dot y" aria-hidden="true"></i><span><strong>KUNING</strong> — perlu perhatian: wajah tidak terlihat ≥ 5 detik, jendela kehilangan fokus, atau tab baru ditinggalkan (&lt; 10 detik). Wajah hilang <em>saja</em> tidak pernah menjadi merah</span></li>
                    <li><i class="mf-dot r" aria-hidden="true"></i><span><strong>MERAH</strong> — tab/aplikasi ditinggalkan ≥ 10 detik (posisi HP tidak memengaruhi status)</span></li>
                    <li><i class="mf-dot x" aria-hidden="true"></i><span><strong>ABU</strong> — jeda atau istirahat. Di dashboard dosen ada pula status DISCONNECTED (koneksi terputus) dan UNKNOWN (belum ada data)</span></li>
                </ul>
            </div>
        </div>

        {{-- Ringkasan privasi (full-width di bawah kedua kolom) --}}
        <div class="mf-privacy">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
            <div>
                <p><strong>Privasi:</strong> deteksi wajah (MediaPipe) berjalan di perangkat Anda. Video tidak ditampilkan, tidak dikirim, dan tidak disimpan; server hanya menerima jenis kejadian, warna status, dan sumber sinyalnya. Kamera otomatis mati saat sesi dijeda atau istirahat.</p>
                <p>Indikator ini bukan ukuran konsentrasi yang sebenarnya.
                    @if ($mode === 'room')
                        Data fokus Anda hanya dapat dilihat oleh Anda dan dosen yang menjadi anggota room ini.
                    @else
                        Data fokus sesi solo hanya dapat dilihat oleh Anda.
                    @endif
                </p>
            </div>
        </div>
    </div>
</div>
