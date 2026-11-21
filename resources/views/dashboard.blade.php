<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
            {{ __('Dashboard Belajar') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="card overflow-hidden mb-6">
                <div class="p-6 text-synlora-900">
                    <h3 class="font-display text-3xl font-semibold text-white">Halo, {{ Auth::user()->name }}</h3>
                    <p class="mt-2 text-synlora-600">@if(Auth::user()->nim) NIM {{ Auth::user()->nim }} · Kelas {{ Auth::user()->kelas }} @else Selamat datang di SYNLORA. @endif</p>

                    @if ($openSession)
                        <div class="mt-4 p-4 bg-synlora-50 rounded-lg flex flex-wrap items-center justify-between gap-3">
                            <div class="text-sm text-synlora-800">
                                Anda masih punya sesi {{ $openSession->isPaused() ? 'yang dijeda' : 'yang berjalan' }}
                                {{ $openSession->room ? 'di room "'.$openSession->room->name.'"' : '(Pomodoro solo)' }}.
                            </div>
                            <a href="{{ $openSession->room ? route('rooms.show', $openSession->room) : route('pomodoro.index') }}"
                               class="btn-primary btn-sm">
                                Lanjutkan sesi
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Target harian + ringkasan: semua dihitung dari study_sessions milik pengguna --}}
            @php
                $circ = 2 * M_PI * 52;
                $offset = $circ * (1 - $stats['goal_percent'] / 100);
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="card p-6 lg:col-span-1">
                    <h4 class="font-display text-lg font-semibold">Target hari ini</h4>
                    <div class="mt-4 flex items-center gap-5">
                        <div class="relative h-32 w-32 shrink-0">
                            <svg viewBox="0 0 120 120" class="h-32 w-32 -rotate-90" aria-hidden="true">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.12)" stroke-width="10"/>
                                <circle cx="60" cy="60" r="52" fill="none" stroke="{{ $stats['goal_percent'] >= 100 ? '#34d399' : '#ffb870' }}" stroke-width="10" stroke-linecap="round" style="filter: drop-shadow(0 0 8px rgba(255,184,112,.45)); transition: stroke-dashoffset 1.6s cubic-bezier(.16,1,.3,1)"
                                        stroke-dasharray="{{ round($circ, 1) }}" stroke-dashoffset="{{ round($offset, 1) }}"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="font-display text-3xl font-semibold tabular-nums">{{ $stats['goal_percent'] }}%</span>
                            </div>
                        </div>
                        <div class="min-w-0 text-sm">
                            <div><span class="font-display text-2xl font-semibold tabular-nums">{{ $stats['today_minutes'] }}</span> <span class="text-synlora-900/60">dari {{ $stats['goal_minutes'] }} menit</span></div>
                            <div class="mt-1 text-synlora-900/60">{{ $stats['today_pomodoros'] }} pomodoro selesai hari ini</div>
                            @if ($stats['goal_percent'] >= 100)
                                <div class="mt-2 font-medium text-emerald-700">Target tercapai.</div>
                            @else
                                <div class="mt-2 text-synlora-900/60">Sisa {{ max(0, $stats['goal_minutes'] - $stats['today_minutes']) }} menit.</div>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('goal.update') }}" class="mt-5 flex items-end gap-2 border-t border-sand-300/70 pt-4">
                        @csrf @method('PATCH')
                        <label class="block flex-1 text-xs text-synlora-900/60">Ubah target (menit/hari)
                            <input type="number" name="daily_goal_minutes" min="10" max="720" step="5" value="{{ old('daily_goal_minutes', $stats['goal_minutes']) }}"
                                   class="mt-1 w-full rounded-xl border-sand-300 text-sm focus:border-synlora-500 focus:ring-synlora-500">
                        </label>
                        <button class="btn-primary !px-4 !py-2.5">Simpan</button>
                    </form>
                    @error('daily_goal_minutes') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="lg:col-span-2 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">Total waktu fokus</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ $stats['total_hours'] }}j {{ $stats['total_minutes'] }}m</div>
                        <div class="mt-1 text-xs text-synlora-900/50">Jeda dan istirahat tidak dihitung.</div>
                    </div>
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">Streak saat ini</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ $stats['streak'] }} hari</div>
                        <div class="mt-1 text-xs text-synlora-900/50">Terpanjang: {{ $stats['longest_streak'] }} hari</div>
                    </div>
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">Pomodoro</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ $stats['pomodoros'] }}</div>
                        <div class="mt-1 text-xs text-synlora-900/50">dari {{ $stats['session_count'] }} sesi</div>
                    </div>
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">30 hari terakhir</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ intdiv($stats['total_30_minutes'], 60) }}j {{ $stats['total_30_minutes'] % 60 }}m</div>
                        <div class="mt-1 text-xs text-synlora-900/50">{{ $stats['active_days_30'] }} hari aktif</div>
                    </div>
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">Rata-rata hari aktif</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ $stats['avg_active_day_minutes'] }} mnt</div>
                        <div class="mt-1 text-xs text-synlora-900/50">target tercapai {{ $stats['goal_days_30'] }}x</div>
                    </div>
                    <div class="card p-5">
                        <div class="text-sm text-synlora-900/60">Indikator hijau (30 hari)</div>
                        <div class="mt-1 font-display text-2xl font-semibold">{{ $stats['focus_percent_30'] !== null ? $stats['focus_percent_30'].'%' : '—' }}</div>
                        <div class="mt-1 text-xs text-synlora-900/50">{{ $stats['focus_percent_30'] !== null ? 'dari waktu yang dipantau' : 'belum ada data pemantauan' }}</div>
                    </div>
                </div>
            </div>

            {{-- Grafik 7 hari + kalender 30 hari --}}
            @php $maxMin = max(1, collect($stats['week'])->max('minutes')); @endphp
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="card p-6">
                    <h4 class="font-display text-lg font-semibold mb-4">Waktu belajar 7 hari terakhir</h4>
                    <div class="flex items-end gap-2 sm:gap-3 h-40">
                        @foreach ($stats['week'] as $d)
                            <div class="flex-1 flex flex-col items-center justify-end h-full">
                                <div class="text-xs text-synlora-900/60 mb-1">{{ $d['minutes'] }}m</div>
                                <div class="w-full rounded-t {{ $d['is_today'] ? 'bg-amber-400' : 'bg-synlora-300' }} {{ $d['minutes'] === 0 ? 'opacity-40' : '' }}"
                                     style="height: {{ $d['minutes'] === 0 ? 4 : max(6, (int) round($d['minutes'] / $maxMin * 100)) }}%"
                                     title="{{ $d['date'] }}: {{ $d['minutes'] }} menit"></div>
                                <div class="text-xs text-synlora-900/50 mt-1">{{ $d['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card p-6">
                    <div class="flex items-baseline justify-between gap-3 mb-4">
                        <h4 class="font-display text-lg font-semibold">Rekap 30 hari</h4>
                        <span class="text-xs text-synlora-900/50">Gelap = mendekati/mencapai target</span>
                    </div>
                    <div class="grid grid-cols-10 gap-1.5" role="img" aria-label="Kalender 30 hari terakhir; warna lebih gelap berarti waktu belajar lebih banyak">
                        @foreach ($stats['month'] as $m)
                            <div class="heat heat-{{ $m['level'] }}" title="{{ $m['date'] }}: {{ $m['minutes'] }} menit"></div>
                        @endforeach
                    </div>
                    @if ($stats['best_day'])
                        <p class="mt-4 text-sm text-synlora-900/65">Hari terbaik: {{ $stats['best_day']['date'] }} ({{ $stats['best_day']['minutes'] }} menit).</p>
                    @endif
                    @if (count($stats['by_room']))
                        <ul class="mt-4 space-y-1.5 text-sm">
                            @foreach ($stats['by_room'] as $br)
                                <li class="flex items-center justify-between gap-3"><span class="truncate">{{ $br['name'] }}</span><span class="shrink-0 text-synlora-900/60">{{ $br['minutes'] }} mnt · {{ $br['sessions'] }} sesi</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h4 class="text-lg font-semibold text-synlora-800 mb-4">Mulai Aktivitas</h4>
                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('rooms.create') }}" class="btn-primary">
                            🚀 Buat Virtual Room
                        </a>
                        <a href="{{ route('rooms.join') }}" class="btn-ghost">
                            🔗 Gabung Room (Kode)
                        </a>
                        <a href="{{ route('pomodoro.index') }}" class="btn-ghost">
                            ⏱️ Mulai Pomodoro Solo
                        </a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Room saya --}}
                <div class="card overflow-hidden">
                    <div class="p-6">
                        <h4 class="text-lg font-semibold text-synlora-800 mb-4">Room Saya</h4>
                        @forelse ($rooms as $r)
                            <a href="{{ route('rooms.show', $r) }}" class="flex items-center justify-between gap-3 p-3 mb-2 rounded-lg hover:bg-synlora-50 transition">
                                <div class="min-w-0">
                                    <div class="font-semibold text-synlora-900 truncate">{{ $r->name }}</div>
                                    <div class="text-xs text-synlora-500">
                                        {{ $r->members_count }} anggota · {{ $r->owner_id === Auth::id() ? 'Anda owner' : 'Owner: '.$r->owner->name }}
                                    </div>
                                </div>
                                <span class="shrink-0 px-2 py-1 bg-synlora-100 text-synlora-700 rounded font-mono text-sm font-bold">{{ $r->code }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-synlora-500">Anda belum bergabung ke room mana pun. Buat room baru atau gabung dengan kode.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Tugas belum dikumpulkan --}}
                <div class="card overflow-hidden">
                    <div class="p-6">
                        <h4 class="text-lg font-semibold text-synlora-800 mb-4">Tugas Belum Dikumpulkan</h4>
                        @forelse ($pendingAssignments as $a)
                            <a href="{{ route('assignments.show', [$a->room, $a]) }}" class="block p-3 mb-2 rounded-lg hover:bg-synlora-50 transition">
                                <div class="font-semibold text-synlora-900">{{ $a->title }}</div>
                                <div class="text-xs text-synlora-500">
                                    {{ $a->room->name }} ·
                                    @if ($a->due_at)
                                        <span class="{{ $a->isPastDue() ? 'text-rose-600 font-semibold' : '' }}">Batas {{ $a->due_at->translatedFormat('d M Y, H:i') }}</span>
                                    @else
                                        Tanpa batas waktu
                                    @endif
                                </div>
                            </a>
                        @empty
                            <p class="text-sm text-synlora-500">Tidak ada tugas yang menunggu.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Sesi terbaru --}}
            <div class="card overflow-hidden mt-6">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-semibold text-synlora-800">Sesi Terbaru</h4>
                        <a href="{{ route('sessions.index') }}" class="text-sm text-synlora-600 hover:text-synlora-800 underline">Lihat semua</a>
                    </div>
                    @forelse ($recentSessions as $s)
                        <a href="{{ route('sessions.recap', $s) }}" class="flex flex-wrap items-center justify-between gap-2 p-3 mb-2 rounded-lg hover:bg-synlora-50 transition">
                            <div>
                                <div class="font-semibold text-synlora-900">{{ $s->room?->name ?? 'Pomodoro Solo' }}</div>
                                <div class="text-xs text-synlora-500">{{ $s->started_at->translatedFormat('d M Y, H:i') }}</div>
                            </div>
                            <div class="text-sm text-synlora-700">
                                {{ \App\Services\SessionRecap::fmt($s->effectiveSecondsNow()) }} fokus · {{ $s->completed_pomodoros }} pomodoro
                                <span class="ml-2 text-xs px-2 py-0.5 rounded {{ $s->isOpen() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $s->isOpen() ? ($s->isPaused() ? 'Dijeda' : 'Berjalan') : 'Selesai' }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-synlora-500">Belum ada sesi belajar. Mulai Pomodoro untuk mencatat sesi pertama Anda.</p>
                    @endforelse
                </div>
            </div>

            <div class="mt-6 text-center text-xs text-synlora-400">
                SYNLORA v1.0 — Synchronized Network for Learning, Organization, Reflection &amp; Achievement
            </div>

        </div>
    </div>
</x-app-layout>
