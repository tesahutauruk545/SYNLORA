<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
            {{ __('Gabung Virtual Room') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="card overflow-hidden">
                <div class="p-6">
                    @if ($errors->any())
                        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <ul class="list-disc list-inside text-red-600">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('rooms.joinRoom') }}">
                        @csrf

                        <div class="mb-6">
                            <label for="code" class="block text-sm font-medium text-synlora-700 mb-2">Kode Room (6 karakter)</label>
                            <input type="text" name="code" id="code" required maxlength="6"
                                class="w-full px-4 py-2 border border-synlora-300 rounded-lg focus:ring-2 focus:ring-synlora-500 focus:border-transparent text-center text-2xl font-mono tracking-widest uppercase"
                                placeholder="ABC123" value="{{ old('code') }}" autocomplete="off" autocapitalize="characters" autofocus>
                        </div>

                        <div class="flex gap-4">
                            <button type="submit"
                                class="btn-primary">
                                Gabung Room
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