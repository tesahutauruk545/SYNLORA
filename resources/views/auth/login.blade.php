<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Masuk ke SYNLORA</h1>
    <p class="mt-2 text-sm text-synlora-900/70">Lanjutkan sesi belajar Anda.</p>

    <x-auth-session-status class="mt-5" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1.5 w-full px-4 py-3" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Kata sandi')" />
            <x-text-input id="password" class="block mt-1.5 w-full px-4 py-3" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <label for="remember_me" class="inline-flex cursor-pointer items-center">
                <input id="remember_me" type="checkbox" class="rounded" name="remember">
                <span class="ms-2 text-sm text-synlora-900/70">Ingat saya</span>
            </label>
            @if (Route::has('password.request'))
                <a class="text-sm text-synlora-600 underline hover:text-amber-200" href="{{ route('password.request') }}">Lupa kata sandi?</a>
            @endif
        </div>

        <x-primary-button class="w-full !py-3.5">Masuk</x-primary-button>
    </form>

    <p class="mt-7 text-center text-sm text-synlora-900/70">Belum punya akun? <a href="{{ route('register') }}" class="font-semibold text-amber-200 underline hover:text-white">Mulai belajar</a></p>
</x-guest-layout>
