<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Konfirmasi kata sandi</h1>
    <p class="mt-2 text-sm text-synlora-900/70">
        Ini area yang dilindungi. Masukkan kata sandi Anda untuk melanjutkan.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-7 space-y-5">
        @csrf

        <div>
            <x-input-label for="password" :value="__('Kata sandi')" />
            <x-text-input id="password" class="block mt-1.5 w-full px-4 py-3"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-primary-button class="w-full !py-3.5">Konfirmasi</x-primary-button>
    </form>
</x-guest-layout>
