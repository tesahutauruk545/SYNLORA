<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
                {{ $room->name }}
            </h2>
            <div class="flex items-center gap-2">
                <span class="px-4 py-2 bg-synlora-100 text-synlora-700 rounded-lg font-mono font-bold" id="room-code">
                    Kode: {{ $room->code }}
                </span>
                <button type="button" class="btn-ghost btn-sm"
                        onclick="navigator.clipboard.writeText('{{ $room->code }}').then(() => { this.textContent = 'Tersalin ✓'; setTimeout(() => this.textContent = 'Salin', 1500); })">
                    Salin
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg" role="alert">
                    <ul class="list-disc list-inside text-red-600 text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Info Room -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <div class="text-sm text-synlora-600">Target Belajar</div>
                            <div class="text-2xl font-bold text-synlora-900">{{ $room->target_minutes }} menit</div>
                        </div>
                        <div>
                            <div class="text-sm text-synlora-600">Jumlah Anggota</div>
                            <div class="text-2xl font-bold text-synlora-900">{{ $members->count() }} <span class="text-base font-medium text-synlora-600">/ {{ $room->max_members }}</span></div>
                        </div>
                        <div>
                            <div class="text-sm text-synlora-600">Owner</div>
                            <div class="text-lg font-semibold text-synlora-900">{{ $room->owner->name }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-synlora-600">Peran Anda di Room</div>
                            <div class="text-lg font-semibold text-synlora-900">{{ $isOwner ? '👑 Owner' : '🎓 Anggota' }}</div>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($isOwner)
                            <details class="w-full rounded-xl border border-sand-300 bg-white/70 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-synlora-800">Pengaturan room</summary>
                                <form method="POST" action="{{ route('rooms.update', $room) }}" class="mt-4 grid gap-3 sm:grid-cols-4 sm:items-end">
                                    @csrf @method('PATCH')
                                    <label class="block text-xs text-synlora-700 sm:col-span-2">Nama room
                                        <input name="name" required maxlength="100" value="{{ old('name', $room->name) }}" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                                    </label>
                                    <label class="block text-xs text-synlora-700">Target (menit)
                                        <input type="number" name="target_minutes" min="5" max="120" required value="{{ old('target_minutes', $room->target_minutes) }}" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                                    </label>
                                    <label class="block text-xs text-synlora-700">Kapasitas
                                        <input type="number" name="max_members" min="{{ max(2, $members->count()) }}" max="300" required value="{{ old('max_members', $room->max_members) }}" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                                    </label>
                                    @if (auth()->user()->isDosen())
                                        <label class="block text-xs text-synlora-700 sm:col-span-2">Kebijakan penggunaan AI
                                            <select name="ai_policy" class="mt-1 w-full rounded-xl border-sand-300 text-sm">
                                                @foreach (['declare' => 'Boleh, wajib dideklarasikan', 'allowed' => 'Boleh', 'forbidden' => 'Tidak diperbolehkan'] as $v => $l)
                                                    <option value="{{ $v }}" @selected(old('ai_policy', $room->ai_policy ?? 'declare') === $v)>{{ $l }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="flex items-start gap-2 text-xs text-synlora-700 sm:col-span-2">
                                            <input type="checkbox" name="strict_mode" value="1" @checked(old('strict_mode', $room->strict_mode)) class="mt-0.5 rounded border-sand-300">
                                            <span>Mode ketat (sesi diawasi): anggota wajib menyetujui pemantauan sebelum memulai. Kamera tetap opsional dan diproses lokal.</span>
                                        </label>
                                    @endif
                                    <div class="sm:col-span-4 flex flex-wrap gap-2">
                                        <button class="btn-primary !px-4 !py-2 text-sm">Simpan pengaturan</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('rooms.regenerate', $room) }}" class="mt-3" onsubmit="return confirm('Ganti kode room? Kode lama tidak berlaku lagi untuk anggota baru.')">
                                    @csrf
                                    <button class="btn-ghost !px-4 !py-2 text-sm">Ganti kode room</button>
                                </form>
                            </details>

                            <details class="w-full rounded-xl border border-rose-200 bg-rose-50/50 p-4">
                                <summary class="cursor-pointer text-sm font-semibold text-rose-700">Hapus room</summary>
                                <p class="mt-3 text-sm text-rose-800">Materi, tugas, dan pengumpulan di room ini ikut terhapus dan tidak bisa dikembalikan. Riwayat belajar pribadi anggota tetap tersimpan.</p>
                                <form method="POST" action="{{ route('rooms.destroy', $room) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                    @csrf @method('DELETE')
                                    <label class="block text-xs text-rose-800">Ketik nama room untuk konfirmasi: <strong>{{ $room->name }}</strong>
                                        <input name="confirm_name" autocomplete="off" required class="mt-1 w-64 max-w-full rounded-xl border-rose-300 text-sm" placeholder="{{ $room->name }}">
                                    </label>
                                    <button class="btn-danger btn-sm">Hapus permanen</button>
                                </form>
                            </details>
                        @else
                            <form method="POST" action="{{ route('rooms.leave', $room) }}"
                                  onsubmit="return confirm('Keluar dari room ini?')">
                                @csrf
                                <button class="btn-ghost btn-sm border-rose-300 text-rose-700">Keluar dari Room</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            @include('partials.session-panel', [
                'mode' => 'room',
                'room' => $room,
                'activeSession' => $activeSession,
                'otherSession' => $otherSession,
                'defaultFocusMinutes' => $room->target_minutes,
            ])

            <!-- Aktivitas -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex flex-wrap justify-between items-center gap-2 mb-1">
                        <h3 class="text-lg font-semibold text-synlora-800">📡 Aktivitas Terbaru</h3>
                        <span class="text-xs text-synlora-500">Diperbarui otomatis setiap 4 detik</span>
                    </div>
                    <p id="activity-scope" class="text-xs text-synlora-500 mb-3"></p>
                    <div id="activity-feed" class="max-h-64 overflow-y-auto space-y-2 bg-slate-50 p-4 rounded-lg">
                        <div class="text-slate-500 text-sm text-center">Memuat aktivitas...</div>
                    </div>
                </div>
            </div>

            <!-- Anggota -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-synlora-800 mb-1">👥 Anggota Room</h3>
                    <p class="text-xs text-synlora-500 mb-4">
                        @if ($canMonitor)
                            Sebagai dosen di room ini, Anda dapat melihat status fokus mahasiswa yang bergabung.
                        @else
                            Anda hanya melihat apakah anggota sedang belajar. Status fokus anggota lain bersifat privat.
                        @endif
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($members as $member)
                            <div class="p-4 border-2 border-synlora-200 rounded-lg" data-member-id="{{ $member['id'] }}">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 shrink-0 bg-synlora-500 rounded-full flex items-center justify-center text-white font-bold text-xl">
                                        {{ mb_strtoupper(mb_substr($member['name'], 0, 1)) }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-synlora-900 truncate">
                                            {{ $member['name'] }}
                                            @if ($member['is_owner']) <span class="text-xs text-synlora-500">(owner)</span> @endif
                                            @if ($member['is_me']) <span class="text-xs text-synlora-500">(Anda)</span> @endif
                                        </div>
                                        <div class="text-sm" data-role="presence">{{ $member['presence_label'] }}</div>
                                        <div class="text-xs mt-1" data-role="focus"></div>
                                    </div>
                                </div>
                                @if ($isOwner && ! $member['is_owner'])
                                    <form method="POST" action="{{ route('rooms.members.remove', [$room, $member['id']]) }}" class="mt-3"
                                          onsubmit="return confirm('Keluarkan anggota ini dari room?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-rose-600 hover:underline">Keluarkan dari room</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Materi -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-synlora-800 mb-4">📚 Materi</h3>

                    @forelse ($materials as $m)
                        <div class="flex flex-wrap items-center justify-between gap-3 p-3 mb-2 rounded-lg">
                            <div class="min-w-0">
                                <div class="font-semibold text-synlora-900">{{ $m->title }}</div>
                                @if ($m->description)
                                    <div class="text-sm text-synlora-600">{{ $m->description }}</div>
                                @endif
                                <div class="text-xs text-synlora-500">{{ $m->original_name }} · {{ $m->human_size }} · {{ $m->created_at->translatedFormat('d M Y, H:i') }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('materials.download', [$room, $m]) }}" class="btn-primary btn-sm">Unduh</a>
                                @if ($isOwner)
                                    <form method="POST" action="{{ route('materials.destroy', [$room, $m]) }}" onsubmit="return confirm('Hapus materi ini?')">
                                        @csrf @method('DELETE')
                                        <button class="btn-ghost btn-sm border-rose-300 text-rose-700">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-synlora-500 mb-2">Belum ada materi{{ $isOwner ? '. Unggah materi pertama di bawah.' : '.' }}</p>
                    @endforelse

                    @if ($isOwner)
                        <form method="POST" action="{{ route('materials.store', $room) }}" enctype="multipart/form-data" class="mt-4 p-4 bg-synlora-50 rounded-lg space-y-3">
                            @csrf
                            <div class="font-semibold text-synlora-800 text-sm">Unggah materi baru</div>
                            <input type="text" name="title" required maxlength="150" placeholder="Judul materi" value="{{ old('title') }}"
                                   class="w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                            <textarea name="description" rows="2" maxlength="1000" placeholder="Deskripsi (opsional)"
                                      class="w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">{{ old('description') }}</textarea>
                            <input type="file" name="file" required class="block w-full text-sm text-synlora-700">
                            <p class="text-xs text-synlora-500">Maks. 10 MB. Format: pdf, doc, docx, ppt, pptx, xls, xlsx, txt, csv, zip, png, jpg.</p>
                            <button class="btn-primary btn-sm">Unggah</button>
                        </form>
                    @endif
                </div>
            </div>

            <!-- Tugas -->
            <div class="card overflow-hidden mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-synlora-800 mb-4">📝 Tugas</h3>

                    @forelse ($assignments as $a)
                        @php $mine = $a->submissions->first(); @endphp
                        <a href="{{ route('assignments.show', [$room, $a]) }}" class="flex flex-wrap items-center justify-between gap-3 p-3 mb-2 rounded-lg hover:bg-synlora-50 transition">
                            <div class="min-w-0">
                                <div class="font-semibold text-synlora-900">{{ $a->title }}</div>
                                <div class="text-xs text-synlora-500">
                                    @if ($a->due_at)
                                        <span class="{{ $a->isPastDue() ? 'text-rose-600 font-semibold' : '' }}">Batas {{ $a->due_at->translatedFormat('d M Y, H:i') }}</span>
                                    @else
                                        Tanpa batas waktu
                                    @endif
                                </div>
                            </div>
                            <div class="text-sm">
                                @if ($isOwner)
                                    <span class="px-2 py-1 bg-synlora-100 text-synlora-700 rounded">{{ $a->submissions_count }} pengumpulan</span>
                                @elseif ($mine)
                                    <span class="px-2 py-1 rounded {{ $mine->score !== null ? 'bg-emerald-100 text-emerald-700' : 'bg-synlora-100 text-synlora-700' }}">
                                        {{ $mine->score !== null ? 'Nilai: '.$mine->score : 'Sudah dikumpulkan' }}
                                    </span>
                                @else
                                    <span class="px-2 py-1 bg-amber-100 text-amber-700 rounded">Belum dikumpulkan</span>
                                @endif
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-synlora-500 mb-2">Belum ada tugas{{ $isOwner ? '. Buat tugas pertama di bawah.' : '.' }}</p>
                    @endforelse

                    @if ($isOwner)
                        <form method="POST" action="{{ route('assignments.store', $room) }}" class="mt-4 p-4 bg-synlora-50 rounded-lg space-y-3">
                            @csrf
                            <div class="font-semibold text-synlora-800 text-sm">Buat tugas baru</div>
                            <input type="text" name="title" required maxlength="150" placeholder="Judul tugas"
                                   class="w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                            <textarea name="description" rows="3" maxlength="5000" placeholder="Instruksi tugas"
                                      class="w-full px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent"></textarea>
                            <label class="block text-sm text-synlora-700">Batas waktu (opsional)
                                <input type="datetime-local" name="due_at"
                                       class="mt-1 block w-full sm:w-auto px-3 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                            </label>
                            <label class="flex items-center gap-2 text-sm text-synlora-700">
                                <input type="hidden" name="allow_late" value="0">
                                <input type="checkbox" name="allow_late" value="1" checked class="rounded border-sand-300 text-synlora-600">
                                Terima pengumpulan terlambat (ditandai "terlambat")
                            </label>
                            <button class="btn-primary btn-sm">Buat Tugas</button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($canMonitor)
                <!-- Rekap sesi mahasiswa (hanya dosen anggota room) -->
                <div class="card overflow-hidden mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-synlora-800 mb-4">📊 Sesi Terbaru di Room Ini</h3>
                        @forelse ($recentSessions as $s)
                            <a href="{{ route('sessions.recap', $s) }}" class="flex flex-wrap items-center justify-between gap-2 p-3 mb-2 rounded-lg hover:bg-synlora-50 transition">
                                <div>
                                    <div class="font-semibold text-synlora-900">{{ $s->user->name }}</div>
                                    <div class="text-xs text-synlora-500">{{ $s->started_at->translatedFormat('d M Y, H:i') }}</div>
                                </div>
                                <div class="text-sm text-synlora-700">{{ \App\Services\SessionRecap::fmt($s->total_effective_seconds) }} fokus · {{ $s->completed_pomodoros }} pomodoro</div>
                            </a>
                        @empty
                            <p class="text-sm text-synlora-500">Belum ada sesi yang selesai di room ini.</p>
                        @endforelse
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
