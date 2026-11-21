<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Atur ulang kata sandi</h1>
    <p class="mt-2 text-sm text-synlora-900/70">
        Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat kata sandi baru.
    </p>

    <x-auth-session-status class="mt-5" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="mt-7 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1.5 w-full px-4 py-3" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <x-primary-button class="w-full !py-3.5">Kirim tautan atur ulang</x-primary-button>
    </form>

    <p class="mt-7 text-center text-sm text-synlora-900/70"><a href="{{ route('login') }}" class="font-semibold text-amber-200 underline hover:text-white">Kembali ke halaman masuk</a></p>
</x-guest-layout>
