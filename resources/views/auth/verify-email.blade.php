<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold leading-tight text-white">Verifikasi email Anda</h1>
    <p class="mt-2 text-sm text-synlora-900/70">
        Kami sudah mengirim tautan verifikasi ke email Anda. Buka tautan itu untuk mulai belajar. Belum menerima emailnya? Kami bisa mengirim ulang.
    </p>

    @if (session('status') == 'verification-link-sent')
        <x-auth-session-status class="mt-5" status="Tautan verifikasi baru sudah dikirim ke email yang Anda gunakan saat mendaftar." />
    @endif

    <x-input-error :messages="$errors->get('email')" class="mt-4" />

    <div class="mt-7 flex flex-wrap items-center justify-between gap-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>Kirim ulang email verifikasi</x-primary-button>
        </form>

        <a href="{{ route('profile.edit') }}" class="rounded-md text-sm text-synlora-900/70 underline hover:text-white focus:outline-none">Salah mengetik email? Ganti email</a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rounded-md text-sm text-synlora-900/70 underline hover:text-white focus:outline-none">
                Keluar
            </button>
        </form>
    </div>
</x-guest-layout>
