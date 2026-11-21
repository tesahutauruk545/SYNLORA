/**
 * Kanal real-time Virtual Meeting.
 *  - Utama : WebSocket ke server SYNLORA (php artisan synlora:realtime).
 *  - Cadangan : polling snapshot tiap pollMs bila WebSocket tidak tersedia / terputus / halaman HTTPS
 *    tetapi server hanya ws://. Setelah tersambung kembali, snapshot diambil sekali untuk menyamakan data.
 * Sumber kebenaran tetap database; event WebSocket hanya mempercepat tampilan.
 */
export class RealtimeChannel {
    /**
     * @param {object} o
     * @param {() => Promise<{enabled:boolean,url?:string,token?:string}>} o.getInfo
     * @param {(ev:object)=>void} o.onEvent
     * @param {()=>Promise<void>} o.poll        ambil snapshot dari server
     * @param {(mode:'websocket'|'polling'|'offline')=>void} o.onMode
     * @param {number} [o.pollMs]
     * @param {boolean} [o.pageIsSecure]
     * @param {typeof WebSocket} [o.WebSocketImpl]
     */
    constructor(o) {
        this.o = o;
        this.pollMs = o.pollMs ?? 2000;
        this.reconcileMs = 15000;
        this.secure = o.pageIsSecure ?? (typeof location !== 'undefined' && location.protocol === 'https:');
        this.WS = o.WebSocketImpl || (typeof WebSocket !== 'undefined' ? WebSocket : null);
        this.ws = null;
        this.mode = 'offline';
        this.stopped = false;
        this.failures = 0;
        this.pollTimer = null;
        this.retryTimer = null;
    }

    start() {
        this.stopped = false;
        this.#mode('polling');
        this.#setPolling(this.pollMs); // polling dulu agar tampilan langsung terisi
        this.#connect();
    }

    stop() {
        this.stopped = true;
        clearInterval(this.pollTimer);
        clearInterval(this.pingTimer);
        clearTimeout(this.retryTimer);
        if (this.ws) {
            try { this.ws.close(); } catch (_) { /* abaikan */ }
        }
        this.ws = null;
        this.#mode('offline');
    }

    #mode(m) {
        if (this.mode !== m) {
            this.mode = m;
            this.o.onMode(m);
        }
    }

    #setPolling(ms) {
        clearInterval(this.pollTimer);
        const tick = () => { if (!document.hidden || this.mode !== 'websocket') this.o.poll().catch(() => {}); };
        this.pollTimer = setInterval(tick, ms);
    }

    async #connect() {
        if (this.stopped || !this.WS) return;

        let info;
        try {
            info = await this.o.getInfo();
        } catch (_) {
            return this.#scheduleRetry();
        }

        if (!info || !info.enabled || !info.url || (this.secure && info.url.startsWith('ws://'))) {
            this.#mode('polling'); // WebSocket tidak dipakai: tetap polling
            return;
        }

        let ws;
        try {
            ws = new this.WS(`${info.url}?token=${encodeURIComponent(info.token)}`);
        } catch (_) {
            return this.#scheduleRetry();
        }

        this.ws = ws;
        let opened = false;

        ws.onopen = () => {
            opened = true;
            this.failures = 0;
            this.#mode('websocket');
            this.#setPolling(this.reconcileMs);
            this.o.poll().catch(() => {}); // samakan data setelah (re)connect
        };

        ws.onmessage = (e) => {
            let msg = null;
            try { msg = JSON.parse(e.data); } catch (_) { return; }
            if (msg && msg.event && msg.event !== 'hello' && msg.event !== 'pong') this.o.onEvent(msg);
        };

        ws.onclose = () => {
            if (this.stopped) return;
            this.ws = null;
            this.#mode('polling');
            this.#setPolling(this.pollMs);
            if (!opened) this.failures++;
            this.#scheduleRetry();
        };

        ws.onerror = () => { /* onclose menangani pemulihan */ };

        // Jaga koneksi tetap hidup dan deteksi koneksi mati diam-diam.
        clearInterval(this.pingTimer);
        this.pingTimer = setInterval(() => {
            if (this.ws && this.ws.readyState === 1) this.ws.send(JSON.stringify({ type: 'ping' }));
        }, 20000);
    }

    #scheduleRetry() {
        if (this.stopped) return;
        const delay = this.failures >= 3 ? 30000 : Math.min(15000, 1000 * 2 ** this.failures);
        clearTimeout(this.retryTimer);
        this.retryTimer = setTimeout(() => this.#connect(), delay);
    }
}
