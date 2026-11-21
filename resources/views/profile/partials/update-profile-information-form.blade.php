<section>
    <header>
        <h2 class="font-display text-xl font-semibold text-white">
            Informasi profil
        </h2>

        <p class="mt-1.5 text-sm text-synlora-900/65">
            Perbarui nama, data kelas, dan email akun Anda.
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Nama lengkap')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        @if ($user->isMahasiswa())
            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <x-input-label for="nim" :value="__('NIM')" />
                    <x-text-input id="nim" name="nim" type="text" inputmode="numeric" class="mt-1 block w-full" :value="old('nim', $user->nim)" required autocomplete="off" />
                    <x-input-error class="mt-2" :messages="$errors->get('nim')" />
                </div>
                <div>
                    <x-input-label for="kelas" :value="__('Kelas')" />
                    <x-text-input id="kelas" name="kelas" type="text" class="mt-1 block w-full uppercase" :value="old('kelas', $user->kelas)" required autocomplete="off" />
                    <x-input-error class="mt-2" :messages="$errors->get('kelas')" />
                </div>
            </div>
        @else
            <p class="text-sm text-synlora-900/60">Peran akun: <strong>Dosen</strong>. Peran hanya dapat diubah oleh administrator.</p>
        @endif

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-synlora-900/80">
                        Email Anda belum diverifikasi.

                        <button form="send-verification" class="underline text-sm text-synlora-600 hover:text-amber-200 rounded-md focus:outline-none">
                            Kirim ulang email verifikasi.
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            Tautan verifikasi baru sudah dikirim ke email Anda.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Simpan') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Tersimpan.') }}</p>
            @endif
        </div>
    </form>
</section>
