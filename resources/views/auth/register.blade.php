<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Buat akun SYNLORA</h1>
    <p class="mt-2 text-sm text-synlora-900/70">Daftar untuk bergabung ke room dan mencatat waktu belajar.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-7 space-y-5"
          x-data="{ role: '{{ old('role', 'mahasiswa') }}' }">
        @csrf

        <div>
            <span class="block text-sm font-medium text-synlora-900/80">Daftar sebagai</span>
            <div class="mt-1.5 grid grid-cols-2 gap-2.5" role="radiogroup" aria-label="Peran">
                <label class="cursor-pointer">
                    <input type="radio" name="role" value="mahasiswa" x-model="role" class="peer sr-only">
                    <span class="block rounded-xl border border-[var(--line)] bg-white/5 px-4 py-3 text-center text-sm font-semibold text-synlora-900/80 transition duration-300 ease-synlora hover:border-amber-200/50 hover:bg-white/10 peer-checked:border-[var(--amber)] peer-checked:bg-[rgba(255,184,112,.16)] peer-checked:text-[var(--amber-2)] peer-focus-visible:ring-2 peer-focus-visible:ring-synlora-500">Mahasiswa</span>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="role" value="dosen" x-model="role" class="peer sr-only">
                    <span class="block rounded-xl border border-[var(--line)] bg-white/5 px-4 py-3 text-center text-sm font-semibold text-synlora-900/80 transition duration-300 ease-synlora hover:border-amber-200/50 hover:bg-white/10 peer-checked:border-[var(--amber)] peer-checked:bg-[rgba(255,184,112,.16)] peer-checked:text-[var(--amber-2)] peer-focus-visible:ring-2 peer-focus-visible:ring-synlora-500">Dosen</span>
                </label>
            </div>
            <x-input-error :messages="$errors->get('role')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="name" :value="__('Nama lengkap')" />
            <x-text-input id="name" class="block mt-1.5 w-full px-4 py-3" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div x-show="role === 'mahasiswa'" class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="nim" :value="__('NIM')" />
                <x-text-input id="nim" class="block mt-1.5 w-full px-4 py-3" type="text" inputmode="numeric" name="nim" :value="old('nim')" x-bind:required="role === 'mahasiswa'" autocomplete="off" />
                <x-input-error :messages="$errors->get('nim')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="kelas" :value="__('Kelas')" />
                <x-text-input id="kelas" class="block mt-1.5 w-full px-4 py-3 uppercase" type="text" name="kelas" placeholder="mis. A / TI-2A" :value="old('kelas')" x-bind:required="role === 'mahasiswa'" autocomplete="off" />
                <x-input-error :messages="$errors->get('kelas')" class="mt-2" />
            </div>
        </div>

        <p x-show="role === 'dosen'" x-cloak class="rounded-xl border border-[var(--line)] bg-white/5 px-4 py-3 text-sm text-synlora-900/70">
            Akun dosen dapat membuat room sendiri dan memantau mahasiswa yang bergabung di room yang sama.
        </p>

        @if (config('synlora.dosen_registration') === 'invite')
            <div x-show="role === 'dosen'" x-cloak>
                <x-input-label for="invite_code" :value="__('Kode undangan dosen')" />
                <x-text-input id="invite_code" class="block mt-1.5 w-full px-4 py-3" type="password" name="invite_code" x-bind:required="role === 'dosen'" autocomplete="off" />
                <p class="mt-1.5 text-xs text-synlora-900/55">Kode diberikan oleh institusi/admin. Tanpa kode, daftar sebagai mahasiswa.</p>
            </div>
        @endif

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1.5 w-full px-4 py-3" type="email" name="email" placeholder="nama@gmail.com" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="password" :value="__('Kata sandi')" />
                <x-text-input id="password" class="block mt-1.5 w-full px-4 py-3" type="password" name="password" required autocomplete="new-password" />
            </div>
            <div>
                <x-input-label for="password_confirmation" :value="__('Ulangi kata sandi')" />
                <x-text-input id="password_confirmation" class="block mt-1.5 w-full px-4 py-3" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>
        </div>
        <x-input-error :messages="$errors->get('password')" />
        <x-input-error :messages="$errors->get('password_confirmation')" />

        <x-primary-button class="w-full !py-3.5">Buat akun</x-primary-button>
    </form>

    <p class="mt-7 text-center text-sm text-synlora-900/70">Sudah punya akun? <a href="{{ route('login') }}" class="font-semibold text-amber-200 underline hover:text-white">Masuk</a></p>
</x-guest-layout>
