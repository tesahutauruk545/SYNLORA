import { formatClock } from './focus-state.js';

/**
 * Pomodoro di sisi browser. Sumber kebenaran waktu adalah SERVER:
 *  - browser menerima `ends_at` (epoch detik) + `server_time`, lalu hanya menampilkan hitung mundur;
 *  - saat hitung mundur habis, browser bertanya ke server (server yang memindahkan fase fokus <-> istirahat);
 *  - refresh/tutup halaman tidak mengubah timer karena sesi tersimpan di database.
 */
export class PomodoroUI {
    /**
     * @param {object} cfg
     * @param {string} cfg.startUrl           endpoint POST untuk memulai sesi
     * @param {() => object} cfg.startBody    pengambil body (menit istirahat / fokus)
     * @param {number} cfg.defaultSeconds     tampilan awal sebelum sesi dimulai
     * @param {object|null} cfg.initial       payload sesi aktif (jika ada)
     * @param {string} cfg.csrf
     * @param {(running:boolean)=>void} cfg.onRunningChange  true saat fase FOKUS berjalan
     * @param {(msg:string|null)=>void} cfg.onError
     */
    constructor(cfg) {
        this.c = cfg;
        this.el = {
            display: document.getElementById('timer-display'),
            status: document.getElementById('timer-status'),
            phase: document.getElementById('timer-phase'),
            count: document.getElementById('timer-count'),
            start: document.getElementById('btn-start'),
            pause: document.getElementById('btn-pause'),
            resume: document.getElementById('btn-resume'),
            end: document.getElementById('btn-end'),
            settings: document.getElementById('timer-settings'),
        };
        this.session = null;
        this.urls = null;
        this.offset = 0; // detik: waktuServer - waktuBrowser
        this.busy = false;
        this.lastSync = 0;
        this.lastPhase = null;

        this.el.start?.addEventListener('click', () => this.start());
        this.el.pause?.addEventListener('click', () => this.action('pause'));
        this.el.resume?.addEventListener('click', () => this.action('resume'));
        this.el.end?.addEventListener('click', () => this.finish());

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.session) this.refresh();
        });

        setInterval(() => this.tick(), 250);
        setInterval(() => { if (this.session && !document.hidden) this.refresh(); }, 20000);
        // Detak ringan tiap 15 dtk, TETAP berjalan walau tab tersembunyi, agar sesi dianggap terhubung.
        setInterval(() => this.heartbeat(), 15000);

    }

    /** Dipanggil halaman SETELAH instance dibuat, supaya callback bisa memakai instance ini. */
    boot() {
        if (this.c.initial) this.apply(this.c.initial);
        else this.render();
    }

    // -------------------------------------------------------- jaringan

    async request(url, method = 'POST', body = null) {
        const res = await fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.c.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });

        if (res.status === 401 || res.status === 419) {
            throw new Error('Sesi login Anda berakhir. Muat ulang halaman lalu login kembali.');
        }
        if (!res.ok) {
            let msg = `Permintaan gagal (${res.status}).`;
            try {
                const j = await res.json();
                if (j.errors) msg = Object.values(j.errors).flat().join(' ');
                else if (j.message) msg = j.message;
            } catch (_) { /* bukan JSON */ }
            throw new Error(msg);
        }
        return res.json();
    }

    async guard(fn) {
        if (this.busy) return;
        this.busy = true;
        this.setButtonsDisabled(true);
        this.c.onError(null);
        try {
            await fn();
        } catch (err) {
            this.c.onError(err.message || 'Terjadi kesalahan.');
        } finally {
            this.busy = false;
            this.setButtonsDisabled(false);
        }
    }

    start() {
        return this.guard(async () => {
            const data = await this.request(this.c.startUrl, 'POST', this.c.startBody());
            this.apply(data);
        });
    }

    action(name) {
        return this.guard(async () => {
            this.apply(await this.request(this.urls[name]));
        });
    }

    finish() {
        if (!this.session) return;
        if (!window.confirm('Akhiri sesi belajar sekarang dan lihat rekap?')) return;
        return this.guard(async () => {
            const data = await this.request(this.urls.finish);
            window.location.assign(data.urls.recap);
        });
    }

    async heartbeat() {
        if (!this.urls || !this.urls.heartbeat || this.busy || !this.session || this.session.status === 'ENDED') return;
        try {
            const extras = (this.c.heartbeatExtras && this.c.heartbeatExtras()) || {};
            const res = await fetch(this.urls.heartbeat, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.c.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                keepalive: true,
                body: JSON.stringify(extras),
            });
            const conn = window.__synloraConn;
            if (res.status === 401 || res.status === 419) { conn && conn.login(); return; }
            if (res.status >= 500) { conn && conn.server(); return; }
            if (res.ok) {
                conn && conn.online();
                const j = await res.json();
                // Sesi sudah ditutup server (mis. dimulai di perangkat lain): ambil keadaan terbaru.
                if (j && j.open === false) this.refresh();
            }
        } catch (_) {
            window.__synloraConn && window.__synloraConn.offline();
        }
    }

    async refresh() {
        if (!this.urls || this.busy) return;
        try {
            this.apply(await this.request(this.urls.state, 'GET'));
        } catch (err) {
            this.c.onError(err.message);
        }
    }

    // ---------------------------------------------------------- state

    apply(payload) {
        this.offset = payload.server_time - Date.now() / 1000;
        this.session = payload.session;
        this.urls = payload.urls;
        this.lastSync = Date.now();

        if (this.session.status === 'ENDED') {
            // Sesi ditutup server (mis. otomatis karena tidak aktif atau diganti dari perangkat lain).
            window.location.assign(this.urls.recap);
            return;
        }

        if (this.lastPhase && this.lastPhase !== this.session.phase) this.beep();
        this.lastPhase = this.session.phase;

        this.render();
    }

    remaining() {
        const s = this.session;
        if (!s) return this.c.defaultSeconds;
        if (s.status === 'PAUSED') return s.remaining_seconds;
        return Math.max(0, s.ends_at - (Date.now() / 1000 + this.offset));
    }

    tick() {
        if (!this.session) return;
        const rem = this.remaining();
        this.paintClock(rem);

        // Waktu fase habis -> minta server memajukan fase (maks 1x per 2 detik).
        if (this.session.status === 'ACTIVE' && rem <= 0 && !this.busy && Date.now() - this.lastSync > 2000) {
            this.lastSync = Date.now();
            this.refresh();
        }
    }

    paintClock(rem) {
        const text = formatClock(Math.ceil(rem));
        if (this.el.display) this.el.display.textContent = text;
        const label = this.session ? (this.session.phase === 'BREAK' ? 'Istirahat' : 'Fokus') : '';
        document.title = this.session ? `${text} · ${label} — SYNLORA` : 'SYNLORA';
    }

    render() {
        const s = this.session;
        const show = (el, on) => el && el.classList.toggle('hidden', !on);

        show(this.el.start, !s);
        show(this.el.pause, !!s && s.status === 'ACTIVE');
        show(this.el.resume, !!s && s.status === 'PAUSED');
        show(this.el.end, !!s);
        show(this.el.settings, !s);

        if (this.el.phase) {
            this.el.phase.textContent = !s ? 'Siap' : (s.phase === 'BREAK' ? '☕ Istirahat' : '📚 Fokus');
        }
        if (this.el.count) {
            this.el.count.textContent = s ? `Pomodoro selesai: ${s.completed_pomodoros}` : '';
        }
        if (this.el.status) {
            this.el.status.textContent = !s
                ? 'Siap memulai sesi fokus'
                : s.status === 'PAUSED'
                    ? '⏸️ Sesi dijeda'
                    : s.phase === 'BREAK'
                        ? '☕ Waktunya istirahat sejenak'
                        : '🟢 Sesi fokus berjalan';
        }

        this.paintClock(this.remaining());
        this.c.onRunningChange(!!s && s.status === 'ACTIVE' && s.phase === 'FOCUS');
    }

    setButtonsDisabled(disabled) {
        ['start', 'pause', 'resume', 'end'].forEach((k) => {
            if (this.el[k]) {
                this.el[k].disabled = disabled;
                this.el[k].classList.toggle('opacity-60', disabled);
            }
        });
    }

    /** Bunyi singkat saat fase berganti (best effort; diabaikan bila browser memblokir audio). */
    beep() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = 880;
            gain.gain.value = 0.08;
            osc.connect(gain).connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
            osc.onended = () => ctx.close();
        } catch (_) { /* abaikan */ }
    }

    /** Dipakai halaman agar bisa mengirim event hanya ketika ada sesi. */
    get eventsUrl() {
        return this.urls ? this.urls.events : null;
    }
}
