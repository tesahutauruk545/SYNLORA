<section class="space-y-6">
    <header>
        <h2 class="font-display text-xl font-semibold text-white">
            Hapus akun
        </h2>

        <p class="mt-1.5 text-sm text-synlora-900/65">
            Setelah akun dihapus, seluruh data Anda ikut terhapus permanen. Unduh dulu data yang ingin Anda simpan.
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >Hapus akun</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="font-display text-xl font-semibold text-white">
                Yakin ingin menghapus akun Anda?
            </h2>

            <p class="mt-1.5 text-sm text-synlora-900/65">
                Akun dan seluruh datanya akan dihapus permanen. Masukkan kata sandi untuk mengonfirmasi.
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Kata sandi') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Kata sandi') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    Batal
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    Hapus akun
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
