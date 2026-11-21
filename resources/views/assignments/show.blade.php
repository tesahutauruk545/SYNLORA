<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">📝 {{ $assignment->title }}</h2>
            <a href="{{ route('rooms.show', $room) }}" class="btn-primary btn-sm">← Kembali ke Room</a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg" role="alert">
                    <ul class="list-disc list-inside text-red-600 text-sm">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <div class="text-sm text-synlora-500 mb-2">
                        Room {{ $room->name }} ·
                        @if ($assignment->due_at)
                            <span class="{{ $assignment->isPastDue() ? 'text-rose-600 font-semibold' : '' }}">Batas {{ $assignment->due_at->translatedFormat('d M Y, H:i') }}{{ $assignment->isPastDue() ? ' (sudah lewat)' : '' }}</span>
                        @else
                            Tanpa batas waktu
                        @endif
                    </div>
                    @if ($assignment->description)
                        <div class="text-synlora-900 whitespace-pre-line">{{ $assignment->description }}</div>
                    @else
                        <div class="text-synlora-500 text-sm">Tidak ada instruksi tambahan.</div>
                    @endif

                    @if ($isOwner)
                        <form method="POST" action="{{ route('assignments.destroy', [$room, $assignment]) }}" class="mt-4"
                              onsubmit="return confirm('Hapus tugas ini beserta semua pengumpulannya?')">
                            @csrf @method('DELETE')
                            <button class="btn-ghost btn-sm border-rose-300 text-rose-700">Hapus Tugas</button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($isOwner)
                <div class="card overflow-hidden mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-synlora-800 mb-4">Pengumpulan ({{ $submissions->count() }})</h3>

                        @forelse ($submissions as $s)
                            <div class="p-4 mb-3 rounded-lg">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div class="font-semibold text-synlora-900">{{ $s->user->name }}</div>
                                        <div class="text-xs text-synlora-500">
                                            {{ $s->submitted_at->translatedFormat('d M Y, H:i') }}
                                            @if ($s->is_late) <span class="text-rose-600 font-semibold">· terlambat</span> @endif
                                        </div>
                                    </div>
                                    @if ($s->file_path)
                                        <a href="{{ route('assignments.submissions.download', [$room, $assignment, $s]) }}" class="btn-primary btn-sm">
                                            Unduh {{ $s->original_name }}
                                        </a>
                                    @endif
                                </div>
                                @if ($s->notes)
                                    <div class="mt-2 text-sm text-synlora-800 whitespace-pre-line">{{ $s->notes }}</div>
                                @endif

                                <form method="POST" action="{{ route('assignments.grade', [$room, $assignment, $s]) }}" class="mt-3 flex flex-wrap items-end gap-3">
                                    @csrf
                                    <label class="text-sm text-synlora-700">Nilai (0–100)
                                        <input type="number" name="score" min="0" max="100" required value="{{ $s->score }}"
                                               class="mt-1 block w-24 px-2 py-1 border border-synlora-300 rounded-lg">
                                    </label>
                                    <label class="text-sm text-synlora-700 flex-1 min-w-[12rem]">Umpan balik
                                        <input type="text" name="feedback" maxlength="2000" value="{{ $s->feedback }}"
                                               class="mt-1 block w-full px-2 py-1 border border-synlora-300 rounded-lg">
                                    </label>
                                    <button class="btn-ok btn-sm">Simpan Nilai</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-sm text-synlora-500">Belum ada yang mengumpulkan.</p>
                        @endforelse

                        @if ($notSubmitted->isNotEmpty())
                            <div class="mt-4 text-sm text-synlora-600">
                                <strong>Belum mengumpulkan:</strong> {{ $notSubmitted->pluck('name')->join(', ') }}
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="card overflow-hidden mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-synlora-800 mb-4">Pengumpulan Anda</h3>

                        @if ($mine)
                            <div class="mb-4 p-4 bg-synlora-50 rounded-lg text-sm text-synlora-800">
                                Dikumpulkan {{ $mine->submitted_at->translatedFormat('d M Y, H:i') }}
                                @if ($mine->is_late) <span class="text-rose-600 font-semibold">(terlambat)</span> @endif
                                @if ($mine->file_path)
                                    · <a class="underline" href="{{ route('assignments.submissions.download', [$room, $assignment, $mine]) }}">{{ $mine->original_name }}</a>
                                @endif
                                @if ($mine->notes)
                                    <div class="mt-2 whitespace-pre-line">{{ $mine->notes }}</div>
                                @endif
                                @if ($mine->score !== null)
                                    <div class="mt-3 p-3 bg-emerald-50 border border-emerald-200 rounded">
                                        <strong>Nilai: {{ $mine->score }}/100</strong>
                                        @if ($mine->feedback)<div class="text-emerald-800">{{ $mine->feedback }}</div>@endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if (! $mine || $mine->score === null)
                            <form method="POST" action="{{ route('assignments.submit', [$room, $assignment]) }}" enctype="multipart/form-data" class="space-y-3">
                                @csrf
                                <textarea name="notes" rows="3" maxlength="2000" placeholder="Catatan / jawaban (opsional jika melampirkan file)"
                                          class="w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">{{ old('notes', $mine?->notes) }}</textarea>
                                <input type="file" name="file" class="block w-full text-sm text-synlora-700">
                                <p class="text-xs text-synlora-500">Maks. 10 MB. {{ $mine ? 'Mengunggah file baru akan mengganti file sebelumnya.' : '' }}
                                    @if ($assignment->isPastDue()) <span class="text-rose-600 font-semibold">Batas waktu sudah lewat; pengumpulan ditandai terlambat.</span> @endif
                                </p>
                                <button class="btn-primary btn-sm">
                                    {{ $mine ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
