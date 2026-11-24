import { notify } from './notify.js';
import { getEcho } from './realtime.js';

/**
 * Lonceng notifikasi (tugas baru untuk mahasiswa, peringatan monitoring untuk dosen). Daftar tersimpan di server,
 * jadi tetap ada setelah refresh dan untuk pengguna yang offline saat notifikasi dibuat. Pembaruan: polling ringan
 * (hanya saat tab terlihat) + dorongan Reverb bila aktif. Semua teks lewat textContent.
 */
export function init(cfg) {
    const roots = [...document.querySelectorAll('[data-notif-root]')];
    if (!roots.length) return;
    const seenKey = 'synlora-notif-toasted';
    const seen = new Set((() => { try { return JSON.parse(sessionStorage.getItem(seenKey) || '[]'); } catch (_) { return []; } })());
    let first = true;
    let busy = false;
    let items = [];

    const post = (url) => fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});

    function paint(unread) {
        roots.forEach((r) => {
            const badge = r.querySelector('[data-notif-badge]');
            if (badge) { badge.textContent = unread > 99 ? '99+' : String(unread); badge.classList.toggle('hidden', unread === 0); }
            const list = r.querySelector('[data-notif-list]');
            if (!list) return;
            list.replaceChildren();
            if (!items.length) {
                const e = document.createElement('div');
                e.className = 'px-4 py-6 text-center text-sm opacity-70';
                e.textContent = 'Belum ada notifikasi.';
                list.append(e);
                return;
            }
            items.forEach((n) => {
                const a = document.createElement(n.url ? 'a' : 'div');
                if (n.url) a.href = n.url;
                a.className = `block border-b border-white/10 px-4 py-3 text-sm transition hover:bg-white/10 ${n.read ? 'opacity-60' : ''}`;
                const t = document.createElement('div');
                t.className = 'font-semibold';
                t.textContent = (n.read ? '' : '● ') + n.title;
                const b = document.createElement('div');
                b.className = 'mt-0.5 text-xs opacity-80';
                b.textContent = n.body || '';
                const w = document.createElement('div');
                w.className = 'mt-1 text-[11px] opacity-60';
                w.textContent = n.at_human || '';
                a.append(t, b, w);
                if (n.type === 'assignment') {
                    const o = document.createElement('div');
                    o.className = 'mt-1 text-xs font-semibold underline';
                    o.textContent = 'Buka tugas';
                    a.append(o);
                }
                a.addEventListener('click', () => { if (!n.read) post(cfg.readUrl.replace('__ID__', n.id)); });
                list.append(a);
            });
        });
    }

    async function load() {
        if (busy) return;
        busy = true;
        try {
            const res = await fetch(cfg.indexUrl, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            const data = await res.json();
            items = data.items;
            paint(data.unread);
            // Toast untuk notifikasi belum dibaca yang baru (tidak diulang tiap polling / refresh).
            items.filter((n) => !n.read && !seen.has(n.id)).reverse().slice(-3).forEach((n) => {
                if (!first || items.filter((x) => !x.read).length <= 3) notify(n.type === 'monitor' ? 'warn' : 'ok', n.title, n.body, 9000);
            });
            items.forEach((n) => seen.add(n.id));
            try { sessionStorage.setItem(seenKey, JSON.stringify([...seen].slice(-200))); } catch (_) { /* diblokir */ }
            first = false;
        } catch (_) { /* jaringan putus: coba lagi pada putaran berikutnya */ } finally { busy = false; }
    }

    roots.forEach((r) => {
        r.querySelector('[data-notif-readall]')?.addEventListener('click', async () => { await post(cfg.readAllUrl); load(); });
    });

    load();
    setInterval(() => { if (!document.hidden) load(); }, (cfg.pollSeconds || 10) * 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

    if (cfg.realtime && cfg.userId) {
        getEcho(cfg.realtime, cfg.csrf).then((echo) => echo?.private(`App.Models.User.${cfg.userId}`).listen('.notification.created', () => load())).catch(() => {});
    }
}
