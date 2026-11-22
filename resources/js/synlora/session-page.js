import { PomodoroUI } from './pomodoro.js';
import { FocusMonitor } from './focus-monitor.js';
import { RoomFeed } from './room-feed.js';
import { notify } from './notify.js';
import { getEcho, describe } from './realtime.js';

const STYLE = {
    HIJAU: { box: 'bg-emerald-50 border-emerald-500', text: 'text-emerald-700', icon: '🟢', title: 'Fokus terindikasi' },
    KUNING: { box: 'bg-amber-50 border-amber-500', text: 'text-amber-700', icon: '🟡', title: 'Peringatan' },
    MERAH: { box: 'bg-rose-50 border-rose-500', text: 'text-rose-700', icon: '🔴', title: 'Terindikasi terganggu' },
    ABU: { box: 'bg-slate-100 border-slate-300', text: 'text-slate-600', icon: '⚪', title: 'Pemantauan tidak berjalan' },
};

export function init(cfg) {
    const $ = (id) => document.getElementById(id);

    // ---- pesan error (menggantikan alert()) ----
    const errBox = $('session-error');
    const showError = (msg) => {
        if (!errBox) return;
        errBox.textContent = msg || '';
        errBox.classList.toggle('hidden', !msg);
    };

    // ---- UI status fokus ----
    const statusBox = $('focus-status-display');
    const redAlert = $('red-alert');
    const redReason = $('red-alert-reason');
    const SOURCE_LABEL = { browser: 'Sumber: aktivitas browser', face: 'Sumber: deteksi wajah', server: 'Sumber: server', system: '' };
    const paintStatus = (status, reason, source) => {
        const s = STYLE[status] || STYLE.ABU;
        if (statusBox) {
            statusBox.className = `p-6 rounded-lg border-4 ${s.box}`;
            statusBox.replaceChildren();
            const t = document.createElement('div');
            t.className = `${s.text} font-bold text-xl`;
            t.textContent = `${s.icon} ${s.title}`;
            const r = document.createElement('div');
            r.className = `text-sm ${s.text} mt-2`;
            r.textContent = reason;
            statusBox.append(t, r);
            if (source && SOURCE_LABEL[source]) {
                const src = document.createElement('div');
                src.className = `text-xs ${s.text} mt-1 opacity-75`;
                src.textContent = SOURCE_LABEL[source];
                statusBox.append(src);
            }
        }
        if (redAlert) {
            redAlert.classList.toggle('hidden', status !== 'MERAH');
            if (redReason && status === 'MERAH') redReason.textContent = reason;
        }
    };

    // ---- banner koneksi (offline / sesi login berakhir / server gangguan) ----
    const banner = document.createElement('div');
    banner.setAttribute('role', 'status');
    banner.className = 'syn-toast hidden fixed bottom-4 left-1/2 z-50 w-[92%] max-w-xl -translate-x-1/2 rounded-2xl border px-4 py-3 text-sm shadow-lg';
    document.body.append(banner);
    let bannerKind = null;
    const setBanner = (kind, html) => {
        if (kind === bannerKind) return;
        bannerKind = kind;
        banner.classList.toggle('hidden', !kind);
        banner.className = banner.className.replace(/\b(bg|border|text)-(amber|rose|emerald)-\d+\b/g, '').trim();
        if (!kind) return;
        const tone = kind === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : kind === 'login' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-amber-50 border-amber-200 text-amber-900';
        banner.className += ' ' + tone;
        banner.innerHTML = html;
    };
    const clearBannerSoon = () => { setBanner('ok', 'Koneksi pulih. Data pemantauan terkirim.'); setTimeout(() => { if (bannerKind === 'ok') setBanner(null); }, 2500); };
    window.__synloraConn = {
        offline: () => setBanner('offline', 'Koneksi terputus. Timer tetap mengikuti waktu server; data pemantauan dikirim ulang otomatis saat tersambung.'),
        online: () => { if (bannerKind === 'offline' || bannerKind === 'server') clearBannerSoon(); },
        server: () => setBanner('server', 'Server sedang mengalami gangguan. Mencoba menyambung kembali otomatis…'),
        login: () => setBanner('login', 'Sesi login berakhir. <a class="font-semibold underline" href="' + (cfg.loginUrl || '/login') + '">Masuk lagi</a>; sesi belajar Anda tetap berjalan di server.'),
    };
    window.addEventListener('offline', () => window.__synloraConn.offline());
    window.addEventListener('online', () => { window.__synloraConn.online(); flush(); });

    // ---- kirim event pemantauan ke server (antrean + ulang otomatis + idempoten) ----
    let pomodoro = null;
    const queue = [];
    let flushing = false;
    const uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Math.random().toString(36).slice(2) + Date.now().toString(36)).slice(0, 32);
    const send = (type, status, details, duration, source) => {
        queue.push({ body: { event_type: type, status, source: source ?? null, details, duration_seconds: duration ?? null, client_id: uid() }, at: Date.now() });
        if (queue.length > 200) queue.shift();
        flush();
    };
    async function flush() {
        if (flushing) return;
        flushing = true;
        try {
            while (queue.length) {
                const url = pomodoro && pomodoro.eventsUrl;
                if (!url) break;
                const item = queue[0];
                if (Date.now() - item.at > 10 * 60 * 1000) { queue.shift(); continue; } // terlalu basi
                let res;
                try {
                    res = await fetch(url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        keepalive: true,
                        body: JSON.stringify({ ...item.body, delay_seconds: Math.min(600, Math.round((Date.now() - item.at) / 1000)) }),
                    });
                } catch (_) {
                    window.__synloraConn.offline();
                    break;
                }
                if (res.status === 401 || res.status === 419) { window.__synloraConn.login(); break; }
                if (res.status >= 500 || res.status === 429) { if (res.status >= 500) window.__synloraConn.server(); break; }
                queue.shift(); // 2xx, atau 4xx permanen (409 bukan fase fokus, 422, 403): jangan diulang
                window.__synloraConn.online();
            }
        } finally {
            flushing = false;
        }
    }
    setInterval(flush, 5000);

    // ---- monitor ----
    const camErr = $('camera-error');
    const btnOn = $('btn-enable-camera');
    const btnOff = $('btn-disable-camera');

    // Peringatan langsung untuk mahasiswa saat wajah hilang 5 detik, dan informasi pemulihan saat kembali (tanpa pengulangan).
    let prevFaceLost = false;
    const studentNotify = (status, reason, source) => {
        const lost = status === 'KUNING' && source === 'face';
        if (lost && !prevFaceLost) {
            notify('warn', 'Wajah tidak terdeteksi 5 detik', 'Posisikan wajah di depan kamera agar status fokus kembali HIJAU.' + (cfg.mode === 'room' ? ' Dosen di room ini (jika ada) ikut diberi tahu.' : ''));
        } else if (!lost && prevFaceLost && status === 'HIJAU') {
            notify('ok', 'Wajah terdeteksi kembali', 'Status fokus Anda pulih ke HIJAU.');
        }
        prevFaceLost = lost;
    };

    const monitor = new FocusMonitor({
        video: $('camera-feed'),
        send,
        onStatus: (status, reason, source) => { paintStatus(status, reason, source); studentNotify(status, reason, source); },
        onError: (msg) => {
            if (camErr) {
                camErr.textContent = msg || '';
                camErr.classList.toggle('hidden', !msg);
            }
        },
        onCameraChange: (on) => {
            btnOn?.classList.toggle('hidden', on);
            btnOff?.classList.toggle('hidden', !on);
        },
        mediapipe: cfg.mediapipe,
    });

    btnOn?.addEventListener('click', async () => {
        btnOn.disabled = true;
        await monitor.enableCamera();
        btnOn.disabled = false;
    });
    btnOff?.addEventListener('click', () => monitor.disableCamera());

    // ---- pomodoro ----
    pomodoro = new PomodoroUI({
        startUrl: cfg.startUrl,
        startBody: () => {
            const num = (id) => {
                const el = $(id);
                return el ? parseInt(el.value, 10) : undefined;
            };
            const goal = ($('input-study-goal')?.value || '').trim();
            const extra = goal ? { study_goal: goal } : {};
            const consent = $('input-consent');
            if (consent) {
                // Mode ketat: persetujuan wajib; server juga memeriksanya (422 bila tidak dicentang).
                if (!consent.checked) showError('Mode ketat: centang persetujuan pemantauan terlebih dahulu.');
                extra.consent = consent.checked ? 1 : 0;
            }
            return cfg.mode === 'solo'
                ? { focus_minutes: num('input-focus-minutes'), break_minutes: num('input-break-minutes'), ...extra }
                : { break_minutes: num('input-break-minutes'), ...extra };
        },
        defaultSeconds: cfg.defaultSeconds,
        initial: cfg.initial,
        csrf: cfg.csrf,
        onRunningChange: (running) => monitor.setRunning(running),
        heartbeatExtras: () => monitor.heartbeatExtras(),
        onError: showError,
    });

    paintStatus('ABU', 'Pemantauan belum aktif. Mulai sesi fokus untuk mengaktifkannya.');
    pomodoro.boot();

    // ---- notifikasi realtime (Reverb). Tanpa konfigurasi: tidak aktif, polling yang sudah ada tetap berjalan ----
    if (cfg.realtime && cfg.roomCode) {
        getEcho(cfg.realtime, cfg.csrf).then((echo) => {
            if (!echo) return;
            if (cfg.canMonitor) {
                // Dosen anggota room: status mahasiswa diperbarui seketika.
                const BADGE = { HIJAU: ['🟢 Fokus terindikasi', 'text-emerald-600 font-semibold'], KUNING: ['🟡 Perhatian', 'text-amber-600 font-semibold'], MERAH: ['🔴 Terindikasi terganggu', 'text-rose-600 font-bold'] };
                echo.private(`room.${cfg.roomCode}.monitor`).listen('.focus.status', (ev) => {
                    const card = document.querySelector(`[data-member-id="${ev.student_id}"] [data-role="focus"]`);
                    const b = BADGE[ev.status];
                    if (card && b) { card.className = `text-xs mt-1 ${b[1]}`; card.textContent = b[0]; }
                    if (ev.notify) {
                        const [title, msg] = describe(ev, false);
                        notify(ev.kind === 'recovered' ? 'ok' : ev.status === 'MERAH' ? 'danger' : 'warn', title, msg);
                    }
                });
            } else if (cfg.userId) {
                // Mahasiswa: hanya channel pribadinya. Bila perangkat ini sendiri yang memantau, peringatan lokal sudah tampil.
                echo.private(`App.Models.User.${cfg.userId}`).listen('.focus.status', (ev) => {
                    if (!ev.notify || monitor.running) return;
                    const [title, msg] = describe(ev, true);
                    notify(ev.kind === 'recovered' ? 'ok' : 'warn', title, msg);
                });
            }
        });
    }

    // ---- feed aktivitas room ----
    if (cfg.feedUrl) {
        new RoomFeed({ url: cfg.feedUrl, feedEl: $('activity-feed'), scopeEl: $('activity-scope') }).start();
    }
}
