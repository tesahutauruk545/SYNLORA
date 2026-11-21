<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
            {{ __('Riwayat Sesi Belajar') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="card overflow-hidden">
                <div class="p-6">
                    @forelse ($sessions as $s)
                        <a href="{{ route('sessions.recap', $s) }}"
                           class="flex flex-wrap items-center justify-between gap-3 p-4 mb-3 rounded-lg hover:bg-synlora-50 transition">
                            <div class="min-w-0">
                                <div class="font-semibold text-synlora-900">{{ $s->room?->name ?? 'Pomodoro Solo' }}</div>
                                <div class="text-xs text-synlora-500">
                                    {{ $s->started_at->translatedFormat('d M Y, H:i') }}
                                    @if ($s->ended_at) – {{ $s->ended_at->format('H:i') }} @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm text-synlora-700">
                                <span>⏱️ {{ \App\Services\SessionRecap::fmt($s->effectiveSecondsNow()) }} fokus</span>
                                <span>🍅 {{ $s->completed_pomodoros }} pomodoro</span>
                                <span class="text-xs px-2 py-0.5 rounded {{ $s->isOpen() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $s->isOpen() ? ($s->isPaused() ? 'Dijeda' : 'Berjalan') : ($s->end_reason === 'abandoned' ? 'Ditutup otomatis' : 'Selesai') }}
                                </span>
                            </div>
                        </a>
                    @empty
                        <p class="text-synlora-500 text-center py-8">
                            Belum ada riwayat sesi. <a class="underline" href="{{ route('pomodoro.index') }}">Mulai Pomodoro</a> atau masuk ke sebuah room.
                        </p>
                    @endforelse

                    <div class="mt-4">{{ $sessions->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
