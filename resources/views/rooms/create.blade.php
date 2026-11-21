<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
            {{ __('Buat Virtual Room') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="card overflow-hidden">
                <div class="p-6">
                    @if ($errors->any())
                        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <ul class="list-disc list-inside text-red-600 text-sm">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('rooms.store') }}">
                        @csrf

                        <div class="mb-6">
                            <label for="name" class="block text-sm font-medium text-synlora-700 mb-2">Nama Room</label>
                            <input type="text" name="name" id="name" required maxlength="100" value="{{ old('name') }}"
                                class="w-full px-4 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent"
                                placeholder="Contoh: Belajar Matematika Bersama">
                        </div>

                        <div class="mb-6">
                            <label for="target_minutes" class="block text-sm font-medium text-synlora-700 mb-2">Target Belajar (menit)</label>
                            <input type="number" name="target_minutes" id="target_minutes" required min="5" max="120" value="{{ old('target_minutes', 25) }}"
                                class="w-full px-4 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                        </div>

                        <div class="mb-6">
                            <label for="max_members" class="block text-sm font-medium text-synlora-700 mb-2">Kapasitas anggota</label>
                            <input type="number" name="max_members" id="max_members" min="2" max="300" value="{{ old('max_members', config('synlora.room_max_members', 60)) }}"
                                class="w-full px-4 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent">
                            <p class="mt-1 text-xs text-synlora-600">Termasuk owner. Bisa diubah nanti.</p>
                        </div>

                        <div class="flex gap-4">
                            <button type="submit"
                                class="btn-primary">
                                Buat Room
                            </button>
                            <a href="{{ route('dashboard') }}"
                                class="btn-ghost">
                                Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>