<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Buat kata sandi baru</h1>
    <p class="mt-2 text-sm text-synlora-900/70">Pilih kata sandi baru untuk akun SYNLORA Anda.</p>

    <form method="POST" action="{{ route('password.store') }}" class="mt-7 space-y-5">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1.5 w-full px-4 py-3" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Kata sandi baru')" />
            <x-text-input id="password" class="block mt-1.5 w-full px-4 py-3" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Ulangi kata sandi')" />
            <x-text-input id="password_confirmation" class="block mt-1.5 w-full px-4 py-3"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button class="w-full !py-3.5">Simpan kata sandi</x-primary-button>
    </form>
</x-guest-layout>
