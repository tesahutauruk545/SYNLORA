{{-- Lonceng notifikasi. Diisi oleh resources/js/synlora/notifications.js (daftar tersimpan di server). --}}
<div class="relative" x-data="{ open: false }" data-notif-root @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" @click="open = ! open" class="relative grid h-10 w-10 place-items-center rounded-full border border-[var(--line)] bg-white/10 transition hover:bg-white/20 focus:outline-none" :aria-expanded="open" aria-label="Notifikasi">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 8-3 8h18s-3-1-3-8M13.7 20a2 2 0 01-3.4 0"/></svg>
        <span data-notif-badge class="hidden absolute -right-1 -top-1 min-w-[1.15rem] rounded-full bg-rose-500 px-1 text-center text-[10px] font-bold leading-[1.15rem] text-white">0</span>
    </button>
    <div x-show="open" x-cloak x-transition class="absolute right-0 z-50 mt-2 w-80 max-w-[85vw] overflow-hidden rounded-2xl border border-[var(--line)] bg-[rgba(11,10,28,.97)] text-white shadow-2xl backdrop-blur-xl">
        <div class="flex items-center justify-between border-b border-white/10 px-4 py-2.5 text-sm font-semibold">
            <span>Notifikasi</span>
            <button type="button" data-notif-readall class="text-xs font-medium underline opacity-80 hover:opacity-100">Tandai semua dibaca</button>
        </div>
        <div data-notif-list class="max-h-96 overflow-y-auto"></div>
    </div>
</div>
