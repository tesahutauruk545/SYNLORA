@php
    use App\Services\SessionRecap as R;
    $label = ['HIJAU' => ['🟢', 'Fokus', 'text-emerald-700'], 'MERAH' => ['🔴', 'Tidak fokus', 'text-rose-700'], 'ABU' => ['⚪', 'Tidak terpantau', 'text-slate-600']];
    $evLabel = [
        'MEETING_STARTED' => '🚀', 'MEETING_ENDED' => '🏁', 'JOINED' => '➡️', 'REJOINED' => '🔁', 'LEFT' => '🚪', 'DISCONNECTED' => '⚠️',
        'TAB_HIDDEN' => '🔴', 'TAB_VISIBLE' => '↩️', 'FACE_LOST' => '🔴', 'FACE_FOUND' => '🟢',
        'CAMERA_GRANTED' => '🎥', 'CAMERA_DENIED' => '🚫', 'CAMERA_ERROR' => '⚠️', 'MONITOR_START' => '🛰️', 'MONITOR_STOP' => '🛑', 'JITSI_JOINED' => '📡',
    ];
    $reasonText = ['all_left' => 'semua peserta keluar', 'owner_ended' => 'diakhiri oleh owner', 'abandoned' => 'semua peserta terputus'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-semibold text-xl text-synlora-800 leading-tight">📊 Laporan Meeting: {{ $room->name }}</h2>
            <a href="{{ route('rooms.show', $room) }}" class="px-4 py-2 bg-synlora-600 text-white rounded-lg hover:bg-synlora-700">← Kembali</a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white overflow-hidden shadow-sm rounded-lg mb-6 border border-synlora-200">
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div><div class="text-sm text-synlora-600">Mulai</div><div class="font-semibold text-synlora-900">{{ $meeting->started_at->translatedFormat('d M Y, H:i') }}</div></div>
                        <div><div class="text-sm text-synlora-600">Selesai</div><div class="font-semibold text-synlora-900">{{ $meeting->ended_at ? $meeting->ended_at->translatedFormat('d M Y, H:i') : 'Masih berlangsung' }}</div></div>
                        <div><div class="text-sm text-synlora-600">Durasi meeting</div><div class="font-semibold text-synlora-900">{{ R::fmt($duration_seconds) }}</div></div>
                        <div><div class="text-sm text-synlora-600">Puncak peserta</div><div class="font-semibold text-synlora-900">{{ $meeting->peak_participants }} orang</div></div>
                    </div>
                    @if ($meeting->end_reason)
                        <p class="mt-3 text-xs text-synlora-500">Berakhir karena {{ $reasonText[$meeting->end_reason] ?? $meeting->end_reason }}.</p>
                    @endif
                    <p class="mt-3 text-xs text-synlora-500">
                        {{ $is_owner ? 'Sebagai owner, Anda melihat data semua peserta.' : 'Anda hanya melihat data Anda sendiri.' }}
                        Status fokus hanyalah indikator (wajah terdeteksi dan halaman aktif), bukan ukuran konsentrasi yang sebenarnya.
                        Video tidak pernah direkam atau disimpan.
                    </p>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm rounded-lg mb-6 border border-synlora-200">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-synlora-800 mb-4">Kehadiran &amp; Fokus per Peserta</h3>

                    @forelse ($participants as $p)
                        <div class="mb-4 p-4 border border-synlora-200 rounded-lg">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="font-semibold text-synlora-900">
                                    {{ $p['name'] }}
                                    @if ($p['is_owner']) <span class="text-xs text-synlora-500">(owner)</span> @endif
                                    @if ($p['is_me']) <span class="text-xs text-synlora-500">(Anda)</span> @endif
                                </div>
                                <div class="text-sm text-synlora-700">
                                    Hadir <strong>{{ R::fmt($p['attendance_seconds']) }}</strong>
                                    · masuk {{ $p['join_count'] }}×
                                </div>
                            </div>

                            @if ($p['monitored_seconds'] > 0)
                                <div class="mt-3 grid grid-cols-3 gap-3 text-center text-sm">
                                    <div class="p-2 bg-emerald-50 rounded"><div class="text-emerald-700 font-semibold">🟢 {{ R::fmt($p['seconds']['HIJAU']) }}</div><div class="text-xs text-synlora-500">Fokus</div></div>
                                    <div class="p-2 bg-rose-50 rounded"><div class="text-rose-700 font-semibold">🔴 {{ R::fmt($p['seconds']['MERAH']) }}</div><div class="text-xs text-synlora-500">Tidak fokus</div></div>
                                    <div class="p-2 bg-slate-100 rounded"><div class="text-slate-700 font-semibold">⚪ {{ R::fmt($p['seconds']['ABU']) }}</div><div class="text-xs text-synlora-500">Tidak terpantau</div></div>
                                </div>
                                <div class="mt-3">
                                    <div class="flex justify-between text-xs text-synlora-600 mb-1"><span>Porsi indikator hijau dari waktu terpantau</span><strong>{{ $p['focus_percent'] }}%</strong></div>
                                    <div class="w-full bg-slate-200 rounded-full h-3"><div class="bg-emerald-500 h-3 rounded-full" style="width: {{ $p['focus_percent'] }}%"></div></div>
                                </div>
                            @else
                                <p class="mt-2 text-sm text-synlora-500">Tidak ada data pemantauan fokus (mis. kamera tidak diaktifkan atau durasi terlalu singkat).</p>
                            @endif

                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm text-synlora-600">Segmen kehadiran &amp; riwayat status</summary>
                                <div class="mt-2 text-xs text-synlora-700 space-y-1">
                                    @foreach ($p['segments'] as $seg)
                                        <div>
                                            ▸ {{ $seg->joined_at->format('H:i:s') }} → {{ $seg->left_at ? $seg->left_at->format('H:i:s') : 'masih hadir' }}
                                            ({{ R::fmt($seg->seconds()) }}){{ $seg->leave_reason ? ' · '.['left' => 'keluar', 'disconnected' => 'terputus', 'meeting_ended' => 'meeting berakhir', 'replaced' => 'muat ulang halaman', 'removed' => 'dikeluarkan'][$seg->leave_reason] ?? $seg->leave_reason : '' }}
                                        </div>
                                    @endforeach
                                </div>
                                @if (count($p['timeline']))
                                    <div class="mt-2 space-y-1 max-h-64 overflow-y-auto">
                                        @foreach ($p['timeline'] as $t)
                                            <div class="text-xs {{ $label[$t['status']][2] ?? '' }}">
                                                <span class="font-mono text-slate-500">[{{ $t['at']->format('H:i:s') }}]</span>
                                                {{ $label[$t['status']][0] ?? '' }} {{ $label[$t['status']][1] ?? $t['status'] }}
                                                @if ($t['reason']) — {{ $t['reason'] }} @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        </div>
                    @empty
                        <p class="text-synlora-500">Belum ada data kehadiran.</p>
                    @endforelse
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-synlora-200">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-synlora-800 mb-4">📝 Timeline Aktivitas</h3>
                    @if ($events->count())
                        <div class="space-y-1 max-h-96 overflow-y-auto">
                            @foreach ($events as $e)
                                <div class="text-sm p-2 bg-slate-50 rounded">
                                    <span class="font-mono text-xs text-slate-500">[{{ $e->occurred_at->format('H:i:s') }}]</span>
                                    {{ $evLabel[$e->event_type] ?? '📝' }}
                                    @if ($e->user) <strong>{{ $e->user->name }}</strong> @endif
                                    {{ $e->details ?? $e->event_type }}
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-slate-500">Tidak ada aktivitas tercatat.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
