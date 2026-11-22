@php
    use App\Services\SessionRecap as R;
    $icons = [
        'SESSION_START' => ['🚀', 'text-emerald-700', 'bg-emerald-50'],
        'SESSION_PAUSE' => ['⏸️', 'text-amber-700', 'bg-amber-50'],
        'SESSION_RESUME' => ['▶️', 'text-emerald-700', 'bg-emerald-50'],
        'SESSION_END' => ['🏁', 'text-synlora-700', 'bg-synlora-50'],
        'PHASE_BREAK' => ['☕', 'text-synlora-700', 'bg-synlora-50'],
        'PHASE_FOCUS' => ['📚', 'text-synlora-700', 'bg-synlora-50'],
        'MONITOR_START' => ['🛰️', 'text-slate-700', 'bg-slate-50'],
        'CAMERA_ON' => ['🎥', 'text-slate-700', 'bg-slate-50'],
        'CAMERA_OFF' => ['🚫', 'text-slate-700', 'bg-slate-50'],
        'FACE_DETECTED' => ['🟢', 'text-emerald-700', 'bg-emerald-50'],
        'FACE_LOST_SHORT' => ['🟡', 'text-amber-700', 'bg-amber-50'],
        'FACE_LOST_LONG' => ['🟡', 'text-amber-700', 'bg-amber-50'],
        'TAB_SWITCH_OUT' => ['🔄', 'text-rose-700', 'bg-rose-50'],
        'TAB_SWITCH_IN' => ['↩️', 'text-amber-700', 'bg-amber-50'],
        'ORIENTATION_CHANGE' => ['📱', 'text-rose-700', 'bg-rose-50'],
        'MONITOR_ERROR' => ['⚠️', 'text-rose-700', 'bg-rose-50'],
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
                📊 Rekap Sesi: {{ $session->room?->name ?? 'Pomodoro Solo' }}
            </h2>
            <a href="{{ $session->room && $session->room->hasMember(Auth::user()) ? route('rooms.show', $session->room) : ($session->room ? route('dashboard') : route('pomodoro.index')) }}"
               class="btn-primary btn-sm">
                ← Kembali
            </a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-synlora-900 mb-2">Rekap Sesi Belajar</h3>
                    <p class="text-synlora-600">
                        <strong>{{ $isMine ? 'Anda' : $session->user->name }}</strong>
                        @if ($session->room) di room <strong>{{ $session->room->name }}</strong> @else dalam sesi <strong>Pomodoro solo</strong> @endif
                    </p>
                    <p class="text-sm text-synlora-500 mt-2">
                        Mulai: {{ $session->started_at->translatedFormat('d M Y, H:i') }} |
                        @if ($session->isOpen())
                            Status: {{ $session->isPaused() ? 'masih dijeda' : 'masih berjalan' }}
                        @else
                            Selesai: {{ $session->ended_at->translatedFormat('d M Y, H:i') }}
                            @if ($session->end_reason === 'abandoned') (ditutup otomatis karena halaman tidak aktif) @endif
                        @endif
                    </p>
                    @unless ($isMine)
                        <p class="text-xs text-synlora-500 mt-2">Anda melihat rekap ini sebagai dosen di room tersebut.</p>
                    @endunless
                </div>
            </div>

            @if ($isMine)
                @php
                    $aiPolicy = $aiPolicy ?? 'declare';
                    $aiLabels = ['none' => 'Tidak memakai AI', 'idea' => 'AI untuk ide', 'explain' => 'AI untuk penjelasan', 'draft' => 'AI untuk draf'];
                    $aiOptions = ($session->room && $aiPolicy === 'forbidden') ? ['none' => $aiLabels['none']] : $aiLabels;
                @endphp
                <div class="card overflow-hidden mb-6">
                    <form id="reflection-form" class="p-6 space-y-3" data-url="{{ route('sessions.reflection', $session) }}">
                        <h4 class="text-lg font-semibold text-synlora-900">Refleksi &amp; deklarasi AI</h4>
                        @if ($session->room)
                            <p class="text-xs text-synlora-600">
                                Kebijakan AI room:
                                <strong>{{ ['forbidden' => 'tidak diperbolehkan', 'declare' => 'boleh, wajib dideklarasikan', 'allowed' => 'boleh'][$aiPolicy] ?? $aiPolicy }}</strong>
                            </p>
                        @endif
                        <label class="block text-sm text-synlora-700">Refleksi singkat (opsional)
                            <textarea name="reflection" maxlength="2000" rows="3" class="mt-1 w-full rounded-xl border-sand-300 text-sm">{{ $session->reflection }}</textarea>
                        </label>
                        <label class="block text-sm text-synlora-700">Penggunaan AI pada sesi ini
                            <select name="ai_usage" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                                @foreach ($aiOptions as $v => $l)
                                    <option value="{{ $v }}" @selected(($session->ai_usage ?? 'none') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if (! $session->room || $aiPolicy !== 'forbidden')
                            <label class="block text-sm text-synlora-700">Catatan penggunaan AI {{ $aiPolicy === 'declare' && $session->room ? '(wajib bila memakai AI)' : '(opsional)' }}
                                <input name="ai_usage_note" maxlength="255" value="{{ $session->ai_usage_note }}" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                            </label>
                        @endif
                        <div class="flex items-center gap-3">
                            <button class="btn-primary !px-4 !py-2 text-sm">Simpan</button>
                            <span id="reflection-msg" class="text-sm" role="status"></span>
                        </div>
                    </form>
                </div>
                <script>
                    (() => {
                        const f = document.getElementById('reflection-form'), msg = document.getElementById('reflection-msg');
                        f.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            msg.textContent = 'Menyimpan…'; msg.className = 'text-sm text-synlora-600';
                            try {
                                const res = await fetch(f.dataset.url, {
                                    method: 'POST', credentials: 'same-origin',
                                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                                    body: JSON.stringify(Object.fromEntries(new FormData(f))),
                                });
                                const j = await res.json().catch(() => ({}));
                                msg.textContent = res.ok ? 'Tersimpan.' : (j.message || 'Gagal menyimpan.');
                                msg.className = res.ok ? 'text-sm text-emerald-700' : 'text-sm text-rose-700';
                            } catch (err) { msg.textContent = 'Gagal menyimpan. Periksa koneksi.'; msg.className = 'text-sm text-rose-700'; }
                        });
                    })();
                </script>
            @endif

            <!-- Waktu -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="card overflow-hidden border-l-4 border-synlora-500">
                    <div class="p-6">
                        <div class="text-sm font-medium text-synlora-600 uppercase">Waktu Fokus Efektif</div>
                        <div class="mt-2 text-3xl font-bold text-synlora-900">{{ R::fmt($effective_seconds) }}</div>
                        <div class="text-xs text-synlora-500 mt-1">Jeda &amp; istirahat tidak dihitung</div>
                    </div>
                </div>
                <div class="card overflow-hidden border-l-4 border-emerald-500">
                    <div class="p-6">
                        <div class="text-sm font-medium text-emerald-600 uppercase">Pomodoro Selesai</div>
                        <div class="mt-2 text-3xl font-bold text-synlora-900">{{ $completed_pomodoros }}</div>
                        <div class="text-xs text-synlora-500 mt-1">siklus {{ $session->focus_duration_minutes }}/{{ $session->break_duration_minutes }} menit</div>
                    </div>
                </div>
                <div class="card overflow-hidden border-l-4 border-slate-400">
                    <div class="p-6">
                        <div class="text-sm font-medium text-slate-600 uppercase">Total Durasi Sesi</div>
                        <div class="mt-2 text-3xl font-bold text-synlora-900">{{ R::fmt($total_seconds) }}</div>
                        <div class="text-xs text-synlora-500 mt-1">sejak mulai hingga selesai</div>
                    </div>
                </div>
            </div>

            @if ($monitoring_used)
                <!-- Indikator pemantauan -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="card overflow-hidden border-l-4 border-emerald-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-emerald-600 uppercase">🟢 Indikator Fokus</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ R::fmt($seconds['HIJAU']) }}</div>
                        </div>
                    </div>
                    <div class="card overflow-hidden border-l-4 border-amber-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-amber-600 uppercase">🟡 Wajah Tidak Terlihat</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ R::fmt($seconds['KUNING']) }}</div>
                            <div class="text-xs text-synlora-500 mt-1">peringatan saja, bukan gangguan</div>
                        </div>
                    </div>
                    <div class="card overflow-hidden border-l-4 border-rose-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-rose-600 uppercase">🔴 Terindikasi Terganggu</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ R::fmt($seconds['MERAH']) }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="card overflow-hidden border-l-4 border-amber-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-amber-600 uppercase">🔄 Pindah Tab</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ $counts['tab_switch'] }} kali</div>
                        </div>
                    </div>
                    <div class="card overflow-hidden border-l-4 border-orange-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-orange-600 uppercase">📡 Koneksi Terputus</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ $counts['heartbeat_lost'] }} kali</div>
                        </div>
                    </div>
                    <div class="card overflow-hidden border-l-4 border-yellow-500">
                        <div class="p-6">
                            <div class="text-sm font-medium text-yellow-600 uppercase">👤 Wajah Hilang</div>
                            <div class="mt-2 text-3xl font-bold text-synlora-900">{{ $counts['face_lost_short'] }} kali</div>
                            <div class="text-xs text-synlora-500 mt-1">{{ $counts['face_lost_long'] }}× lebih dari 10 detik</div>
                        </div>
                    </div>
                </div>
            @else
                <div class="card overflow-hidden mb-6">
                    <div class="p-6 text-sm text-synlora-600">
                        Tidak ada data pemantauan fokus pada sesi ini (pemantauan hanya berjalan selama fase fokus yang sedang aktif).
                    </div>
                </div>
            @endif

            <!-- Timeline -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h4 class="text-lg font-semibold text-synlora-800 mb-4">📝 Timeline Aktivitas</h4>
                    @if ($activities->count() > 0)
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            @foreach ($activities as $act)
                                @php [$icon, $color, $bg] = $icons[$act->event_type] ?? ['📝', 'text-slate-700', 'bg-slate-50']; @endphp
                                <div class="{{ $bg }} p-3 rounded-lg {{ $color }}">
                                    <span class="font-mono text-xs text-slate-500">[{{ $act->occurred_at->format('H:i:s') }}]</span>
                                    <strong>{{ $icon }}</strong>
                                    <span class="text-sm ml-1">{{ $act->details ?? $act->event_type }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-slate-500 text-center">Tidak ada aktivitas tercatat.</p>
                    @endif
                </div>
            </div>

            @if ($monitoring_used && $focus_percent !== null)
                <!-- Ringkasan indikator -->
                <div class="card overflow-hidden">
                    <div class="p-6">
                        <h4 class="text-lg font-semibold text-synlora-800 mb-4">📈 Ringkasan Indikator</h4>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-synlora-700">Porsi waktu terpantau dengan indikator hijau</span>
                            <span class="font-bold text-synlora-900">{{ $focus_percent }}%</span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-4">
                            <div class="bg-emerald-500 h-4 rounded-full" style="width: {{ $focus_percent }}%"></div>
                        </div>
                        <p class="mt-3 text-xs text-synlora-500">
                            Dihitung dari {{ R::fmt($monitored_seconds) }} waktu yang terpantau.
                            Angka ini hanya indikator (tab aktif, wajah terlihat) dan bukan ukuran konsentrasi yang sebenarnya.
                            @unless ($camera_used) Kamera tidak diaktifkan, sehingga hanya tab dan orientasi yang dipantau. @endunless
                        </p>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
