<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-2xl font-semibold leading-tight text-synlora-900">Monitoring belajar</h2>
                <p class="mt-1 text-sm text-synlora-900/60">Mahasiswa di room yang sama dengan Anda. Diperbarui otomatis tiap {{ $config['pollSeconds'] }} detik.</p>
            </div>
            <div class="flex items-center gap-2">
                <span id="live-indicator" class="inline-flex items-center gap-2 rounded-full border border-sand-300 bg-white px-3 py-1.5 text-xs text-synlora-900/70" aria-live="polite">
                    <span class="dot dot-ABU" id="live-dot"></span><span id="live-text">Memuat…</span>
                </span>
                <a id="export-link" href="{{ route('dosen.export') }}" class="btn-ghost !px-4 !py-2 text-sm">Unduh rekap (CSV)</a>
            </div>
        </div>
    </x-slot>

    <script type="application/json" id="monitor-config">@json($config)</script>

    <div class="py-6 sm:py-10">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            @if ($rooms->isEmpty())
                <div class="card p-10 text-center">
                    <h3 class="font-display text-2xl font-semibold">Anda belum berada di room mana pun</h3>
                    <p class="mx-auto mt-2 max-w-md text-synlora-900/70">Buat room untuk kelas Anda atau gabung ke room mahasiswa lewat kode. Mahasiswa di room yang sama dengan Anda akan muncul di sini.</p>
                    <div class="mt-6 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('rooms.create') }}" class="btn-primary">Buat room kelas</a>
                        <a href="{{ route('rooms.join') }}" class="btn-ghost">Gabung dengan kode</a>
                    </div>
                </div>
            @else
                {{-- Ringkasan --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" id="summary">
                    <div class="card p-4 sm:p-5"><div class="text-sm text-synlora-900/60">Mahasiswa</div><div class="mt-1 font-display text-3xl font-semibold" data-sum="total">–</div><div class="text-xs text-synlora-900/50">hadir hari ini <span data-sum="present">–</span> · target tercapai <span data-sum="goal">–</span></div></div>
                    <div class="card p-4 sm:p-5"><div class="text-sm text-synlora-900/60">Sedang belajar</div><div class="mt-1 font-display text-3xl font-semibold" data-sum="studying">–</div></div>
                    <div class="card p-4 sm:p-5"><div class="text-sm text-synlora-900/60">Perlu perhatian</div><div class="mt-1 font-display text-3xl font-semibold" data-sum="attention">–</div><div class="text-xs text-synlora-900/50">kuning + merah + terputus</div></div>
                    <div class="card p-4 sm:p-5"><div class="text-sm text-synlora-900/60">Rata-rata hari ini</div><div class="mt-1 font-display text-3xl font-semibold"><span data-sum="avg">–</span> <span class="text-base font-medium text-synlora-900/50">menit</span></div></div>
                </div>

                {{-- Filter --}}
                <div class="card mt-4 p-4">
                    <div class="grid gap-3 md:grid-cols-[1.2fr_1fr_1fr_1fr]">
                        <label class="block">
                            <span class="sr-only">Cari nama atau NIM</span>
                            <input id="f-search" type="search" placeholder="Cari nama atau NIM" autocomplete="off"
                                   class="w-full rounded-xl border-sand-300 bg-white text-sm focus:border-synlora-500 focus:ring-synlora-500">
                        </label>
                        <label class="block">
                            <span class="sr-only">Room</span>
                            <select id="f-room" class="w-full rounded-xl border-sand-300 bg-white text-sm focus:border-synlora-500 focus:ring-synlora-500">
                                <option value="">Semua room saya</option>
                                @foreach ($rooms as $r)
                                    <option value="{{ $r->code }}">{{ $r->name }} ({{ $r->code }})</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="sr-only">Kelas</span>
                            <select id="f-kelas" class="w-full rounded-xl border-sand-300 bg-white text-sm focus:border-synlora-500 focus:ring-synlora-500"><option value="">Semua kelas</option></select>
                        </label>
                        <label class="block">
                            <span class="sr-only">Urutkan</span>
                            <select id="f-sort" class="w-full rounded-xl border-sand-300 bg-white text-sm focus:border-synlora-500 focus:ring-synlora-500">
                                <option value="attention">Urutkan: perlu perhatian dulu</option>
                                <option value="name">Urutkan: nama</option>
                                <option value="nim">Urutkan: NIM</option>
                                <option value="time">Urutkan: waktu belajar (terbanyak)</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2" id="status-chips" role="group" aria-label="Saring menurut status">
                        <button type="button" data-status="" class="chip is-on">Semua <span data-count="all">0</span></button>
                        <button type="button" data-status="HIJAU" class="chip"><span class="dot dot-HIJAU"></span> Fokus <span data-count="HIJAU">0</span></button>
                        <button type="button" data-status="KUNING" class="chip"><span class="dot dot-KUNING"></span> Perhatian <span data-count="KUNING">0</span></button>
                        <button type="button" data-status="MERAH" class="chip"><span class="dot dot-MERAH"></span> Terganggu <span data-count="MERAH">0</span></button>
                        <button type="button" data-status="ABU" class="chip"><span class="dot dot-ABU"></span> Tidak aktif <span data-count="ABU">0</span></button>
                        <button type="button" data-status="DISCONNECTED" class="chip"><span class="dot dot-DISCONNECTED"></span> Terputus <span data-count="DISCONNECTED">0</span></button>
                        <button type="button" data-status="UNKNOWN" class="chip"><span class="dot dot-UNKNOWN"></span> Belum ada data <span data-count="UNKNOWN">0</span></button>
                        <button type="button" data-status="CAMERA_OFF" class="chip" title="Sedang belajar tanpa kamera aktif">Tanpa kamera <span data-count="CAMERA_OFF">0</span></button>
                    </div>
                </div>

                {{-- Daftar mahasiswa --}}
                <div class="card mt-4 overflow-hidden">
                    <div class="hidden grid-cols-[2.2fr_.8fr_1.6fr_1.4fr_1fr_1fr_auto] gap-4 border-b border-sand-300/70 bg-sand-100/60 px-5 py-3 text-xs font-semibold text-synlora-900/60 md:grid" aria-hidden="true">
                        <div>Mahasiswa</div><div>Kelas</div><div>Status</div><div>Pomodoro</div><div>Hari ini</div><div>Terakhir aktif</div><div class="w-20"></div>
                    </div>
                    <ul id="student-list" class="divide-y divide-sand-300/60" aria-live="off"></ul>
                    <p id="empty-state" class="hidden p-8 text-center text-sm text-synlora-900/60"></p>
                </div>

                <p class="mt-4 text-xs leading-relaxed text-synlora-900/55">
                    Status adalah indikator dari aktivitas browser dan (bila diaktifkan mahasiswa) deteksi wajah, bukan ukuran konsentrasi.
                    Anda hanya melihat data sesi di room yang Anda ikuti; sesi solo mahasiswa tidak tampil. Video kamera tidak pernah dikirim ke server.
                </p>
            @endif
        </div>
    </div>

    {{-- Panel riwayat --}}
    <div id="drawer" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="drawer-title">
        <div id="drawer-backdrop" class="absolute inset-0 bg-synlora-900/40 backdrop-blur-[2px]"></div>
        <aside class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col overflow-y-auto bg-cream shadow-2xl">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-sand-300/70 bg-cream/95 px-5 py-4 backdrop-blur">
                <div>
                    <h3 id="drawer-title" class="font-display text-xl font-semibold">Riwayat</h3>
                    <p id="drawer-sub" class="text-sm text-synlora-900/60"></p>
                </div>
                <button type="button" id="drawer-close" class="rounded-full p-2 text-synlora-900/70 hover:bg-sand-200" aria-label="Tutup">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div id="drawer-body" class="space-y-6 px-5 py-5 text-sm"></div>
        </aside>
    </div>

</x-app-layout>
