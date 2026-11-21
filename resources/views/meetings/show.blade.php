<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-semibold text-xl text-synlora-800 leading-tight">🎥 Virtual Meeting: {{ $room->name }}</h2>
            <a href="{{ route('rooms.show', $room) }}" class="px-4 py-2 bg-synlora-600 text-white rounded-lg hover:bg-synlora-700">← Kembali ke Room</a>
        </div>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <script type="application/json" id="synlora-meeting-config">@json($config)</script>
            <div id="toasts" class="fixed top-4 right-4 z-50 space-y-2"></div>

            <div id="server-warning" class="hidden mb-4 p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg text-sm" role="alert"></div>
            <div id="instance-warning" class="hidden mb-4 p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm" role="alert"></div>

            {{-- Video disembunyikan: hanya sumber frame untuk deteksi wajah di perangkat ini. --}}
            <div class="relative">
                <video id="camera-probe" autoplay muted playsinline class="absolute w-px h-px opacity-0 pointer-events-none" aria-hidden="true"></video>
            </div>

            <!-- Sebelum bergabung -->
            <section id="prejoin" class="bg-white overflow-hidden shadow-sm rounded-lg border border-synlora-200">
                <div class="p-6 sm:p-8 max-w-3xl">
                    <h3 class="text-2xl font-bold text-synlora-900">Siap bergabung?</h3>
                    <p class="mt-2 text-synlora-600">
                        Meeting ini hanya untuk anggota room <strong>{{ $room->name }}</strong>.
                        @if ($live)
                            <span class="text-emerald-700 font-semibold">Meeting sedang berlangsung sejak {{ $live->started_at->format('H:i') }}.</span>
                        @else
                            Anda akan memulai meeting baru.
                        @endif
                    </p>

                    <div class="mt-5 p-4 bg-synlora-50 border-l-4 border-synlora-500 rounded-lg text-sm text-synlora-800 space-y-2">
                        <div class="font-bold">Monitoring fokus otomatis</div>
                        <p>Mulai saat Anda tersambung dan berhenti saat Anda keluar atau meeting berakhir.</p>
                        <ul class="space-y-1">
                            <li>🟢 <strong>Hijau</strong>: wajah terdeteksi dan halaman ini aktif</li>
                            <li>🔴 <strong>Merah</strong>: wajah tidak terdeteksi (lebih dari {{ round($config['faceGraceMs'] / 1000) }} detik) atau Anda berpindah tab</li>
                            <li>⚪ <strong>Abu-abu</strong>: tidak terpantau (mis. izin kamera ditolak)</li>
                        </ul>
                        <p class="text-xs text-synlora-700">
                            <strong>Privasi:</strong> Browser akan meminta izin kamera. Deteksi wajah berjalan di perangkat Anda; video
                            <strong>tidak direkam, tidak dikirim, dan tidak disimpan</strong> oleh SYNLORA. Hanya status (hijau/merah/abu), waktu kehadiran,
                            dan riwayat perubahan status yang disimpan.
                            @if ($isOwner)
                                Sebagai owner, Anda dapat melihat status semua peserta; peserta hanya melihat statusnya sendiri.
                            @else
                                Status Anda hanya dapat dilihat oleh Anda dan owner room.
                            @endif
                        </p>
                    </div>

                    <div id="prejoin-note" class="hidden mt-4 p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm"></div>
                    <div id="prejoin-msg" class="hidden mt-4 p-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg text-sm" role="alert"></div>

                    <button id="btn-join" type="button"
                            class="mt-6 px-8 py-4 bg-synlora-600 text-white text-lg font-semibold rounded-lg hover:bg-synlora-700 transition shadow-md">
                        🎥 Izinkan Kamera &amp; Gabung Meeting
                    </button>
                </div>
            </section>

            <!-- Di dalam meeting -->
            <section id="stage" class="hidden">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2">
                        <div class="relative bg-black rounded-lg overflow-hidden h-[65vh] lg:h-[78vh]">
                            <div id="jitsi-container" class="absolute inset-0"></div>
                            <div id="joining-overlay" class="absolute inset-0 flex items-center justify-center text-white bg-black/60 pointer-events-none">
                                Menyambungkan ke ruang video…
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button id="btn-leave-meeting" type="button"
                                    class="px-4 py-2 bg-white border border-rose-300 text-rose-700 rounded-lg hover:bg-rose-50">🚪 Keluar</button>
                            @if ($isOwner)
                                <button id="btn-end-all" type="button"
                                        class="px-4 py-2 bg-rose-600 text-white rounded-lg hover:bg-rose-700">⛔ Akhiri untuk Semua</button>
                            @endif
                            <span id="jitsi-count" class="text-sm text-synlora-600"></span>
                            <span id="conn-mode" class="text-xs text-synlora-500 sm:ml-auto"></span>
                        </div>
                    </div>

                    <aside class="space-y-6">
                        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-synlora-200">
                            <div class="p-5">
                                <h4 class="font-semibold text-synlora-800 mb-3">Status Fokus Saya</h4>
                                <div id="my-status-card" class="p-5 rounded-lg border-4 bg-slate-100 border-slate-300" aria-live="polite">
                                    <div id="my-status-title" class="font-bold text-xl text-slate-600">⚪ Tidak terpantau</div>
                                    <div id="my-status-reason" class="text-sm mt-1 text-slate-600">Menunggu tersambung…</div>
                                </div>
                                <div id="camera-note" class="hidden mt-3 p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-xs"></div>
                                <div class="mt-3 text-sm text-synlora-600">Durasi hadir: <span id="my-duration" class="font-mono font-semibold text-synlora-900">00:00:00</span></div>
                            </div>
                        </div>

                        @if ($isOwner)
                            <div id="owner-panel" class="bg-white overflow-hidden shadow-sm rounded-lg border border-synlora-200">
                                <div class="p-5">
                                    <h4 class="font-semibold text-synlora-800">👥 Status Peserta (real-time)</h4>
                                    <p id="summary-counts" class="text-xs text-synlora-500 mt-1 mb-3"></p>
                                    <div id="participants-list" class="max-h-[50vh] overflow-y-auto"></div>
                                </div>
                            </div>
                        @else
                            <div class="p-4 bg-synlora-50 border-l-4 border-synlora-500 rounded-lg text-xs text-synlora-700">
                                Anda hanya melihat status fokus Anda sendiri. Owner room dapat melihat status semua peserta.
                                Daftar peserta di ruang video tersedia pada tombol peserta di toolbar video.
                            </div>
                        @endif
                    </aside>
                </div>
            </section>

            <!-- Meeting berakhir -->
            <section id="ended-notice" class="hidden bg-white overflow-hidden shadow-sm rounded-lg border border-synlora-200">
                <div class="p-8 text-center">
                    <div class="text-4xl mb-2">🏁</div>
                    <p id="ended-text" class="text-synlora-800 text-lg"></p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        <a id="ended-report" href="#" class="hidden px-5 py-3 bg-synlora-600 text-white rounded-lg hover:bg-synlora-700">📊 Lihat Laporan Meeting</a>
                        <a href="{{ route('rooms.show', $room) }}" class="px-5 py-3 bg-white border border-synlora-300 text-synlora-700 rounded-lg hover:bg-synlora-50">Kembali ke Room</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
