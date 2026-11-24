import { cameraHub } from './camera-hub.js';
import { headDirection, pickPhone, FaceStateMachine, FrameVote } from './monitor-logic.js';

/**
 * Pemantauan per room dengan siklus hidup SENDIRI: kamera (lewat CameraHub bersama), deteksi wajah, deteksi HP,
 * dan perpindahan tab berjalan terus walau Pomodoro dijeda/selesai atau halaman tugas dibuka. Hanya berhenti saat
 * pengguna mematikan kamera / keluar sesi. Niat "kamera aktif" disimpan di sessionStorage agar halaman berikutnya
 * (mis. halaman tugas) menyalakan kamera lagi otomatis tanpa dialog izin baru (izin browser sudah diberikan).
 *
 * Privasi: frame hanya dibaca detektor lokal; tidak ditampilkan, tidak disimpan, tidak dikirim. Server menerima
 * jenis event (+ keyakinan & orientasi HP). Lencana "Kamera aktif" selalu tampil selama pemantauan berjalan.
 */
const KEY = 'synlora-monitor';
const HOLDER = 'room-monitor';

const store = {
    get() { try { return JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (_) { return {}; } },
    set(v) { try { sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (_) { /* diblokir */ } },
};

export function init(cfg) {
    if (window.__synloraRoomMonitor) return window.__synloraRoomMonitor;
    const mon = new RoomMonitorClient(cfg);
    window.__synloraRoomMonitor = mon;
    mon.boot();
    return mon;
}

class RoomMonitorClient {
    constructor(cfg) {
        this.cfg = cfg;
        this.on = false;
        this.video = null;
        this.face = null;
        this.obj = null;
        this.fsm = new FaceStateMachine({
            notFoundMs: cfg.thresholds.faceNotFoundMs, awayMs: cfg.thresholds.faceAwayMs, turnMs: cfg.thresholds.faceTurnMs,
        });
        this.vote = new FrameVote(cfg.thresholds.phoneFrames, Math.max(5, cfg.thresholds.phoneFrames + 2));
        this.phoneOn = false;
        this.queue = [];
        this.flushing = false;
        this.timers = [];
        this.lastObjAt = 0;
        this.badge = null;
    }

    boot() {
        document.addEventListener('visibilitychange', () => this.#tab());
        window.addEventListener('pagehide', () => this.#pageHide());
        window.addEventListener('synlora:camera-request', (e) => (e.detail?.on ? this.start() : this.stop(true)));
        this.#renderBadge();
        if (store.get().room === this.cfg.roomCode && store.get().want) this.start(true);
    }

    // ---------------------------------------------------------------- siklus hidup kamera
    async start(resumed = false) {
        if (this.on) return true;
        try {
            const stream = await cameraHub.acquire(HOLDER);
            this.video = this.video || Object.assign(document.createElement('video'), { muted: true, playsInline: true });
            this.video.setAttribute('playsinline', '');
            this.video.srcObject = stream;
            await this.video.play().catch(() => {});
            stream.getVideoTracks().forEach((t) => {
                t.addEventListener('ended', () => this.#lost());
                t.addEventListener('mute', () => setTimeout(() => { if (t.muted) this.#lost(); }, 3000));
            });
        } catch (err) {
            cameraHub.release(HOLDER);
            const denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
            if (!resumed || denied) store.set({ room: this.cfg.roomCode, want: false });
            this.#send(denied ? 'CAMERA_DENIED' : 'CAMERA_ERROR');
            return false;
        }

        this.on = true;
        store.set({ room: this.cfg.roomCode, want: true });
        this.fsm.reset();
        this.vote.reset();
        this.phoneOn = false;
        this.#send('CAMERA_ON');
        this.#renderBadge();
        this.#startTimers();
        this.#loadDetectors();
        this.tabSent = false;
        this.#send('TAB_RETURNED'); // halaman baru dalam keadaan terlihat: tutup keadaan "tab ditinggalkan" yang mungkin tersisa
        if (document.hidden) this.#tab();
        return true;
    }

    /** Dimatikan pengguna (userStop=true) atau pemantauan berakhir. Tidak dipanggil oleh Pomodoro/tugas. */
    stop(userStop = false) {
        if (!this.on) return;
        this.#stopTimers();
        this.on = false;
        if (userStop) store.set({ room: this.cfg.roomCode, want: false });
        cameraHub.release(HOLDER);
        if (this.video) this.video.srcObject = null;
        this.#send(userStop ? 'CAMERA_OFF' : 'MONITOR_STOP');
        this.#renderBadge();
    }

    #lost() {
        if (!this.on) return;
        this.#stopTimers();
        this.on = false;
        cameraHub.release(HOLDER);
        this.#send('CAMERA_LOST');
        this.#renderBadge('Kamera terputus. Aktifkan lagi dari tombol kamera.');
    }

    /** Pindah halaman: kamera dilepas sementara; halaman berikutnya menyalakan lagi (want tetap true). */
    #pageHide() {
        if (!this.on) return;
        this.#stopTimers();
        cameraHub.release(HOLDER);
        this.on = false;
    }

    // ---------------------------------------------------------------- tab
    #tab() {
        if (!this.on) return;
        // TAB_LEFT ditunda 3 dtk: berpindah halaman di SYNLORA (mis. buka tugas) atau melirik sekilas bukan pelanggaran,
        // dan timer ikut mati saat halaman dibongkar sehingga tidak ada peringatan palsu.
        clearTimeout(this.tabTimer);
        if (document.hidden) {
            this.tabTimer = setTimeout(() => { this.tabSent = true; this.#send('TAB_LEFT'); }, 3000);
        } else if (this.tabSent) {
            this.tabSent = false;
            this.#send('TAB_RETURNED');
        }
    }

    // ---------------------------------------------------------------- deteksi
    async #loadDetectors() {
        if (this.face || this.loading) return;
        this.loading = true;
        try {
            const { FaceDetector, ObjectDetector, FilesetResolver } = await import('@mediapipe/tasks-vision');
            const vision = await FilesetResolver.forVisionTasks(this.cfg.mediapipe.wasmPath);
            this.face = await this.#make(FaceDetector, vision, this.cfg.mediapipe.faceModelPaths, { minDetectionConfidence: 0.5 });
            if (this.cfg.mediapipe.objectModelPaths.length) {
                try {
                    this.obj = await this.#make(ObjectDetector, vision, this.cfg.mediapipe.objectModelPaths, { scoreThreshold: Math.min(0.3, this.cfg.thresholds.phoneMinConfidence), maxResults: 5 });
                } catch (e) {
                    console.warn('Model deteksi objek (HP) tidak tersedia:', e); // wajah & tab tetap berjalan
                }
            }
        } catch (e) {
            console.warn('Model deteksi wajah tidak tersedia:', e);
            this.#renderBadge('Model deteksi wajah belum terpasang; hanya kamera dan tab yang dipantau.');
        } finally {
            this.loading = false;
        }
    }

    async #make(Cls, vision, paths, extra) {
        let last = null;
        for (const modelAssetPath of paths) {
            for (const delegate of ['GPU', 'CPU']) {
                try { return await Cls.createFromOptions(vision, { baseOptions: { modelAssetPath, delegate }, runningMode: 'VIDEO', ...extra }); } catch (e) { last = e; }
            }
        }
        throw last || new Error('model tidak dapat dimuat');
    }

    #startTimers() {
        this.#stopTimers();
        this.timers.push(setInterval(() => this.#detect(), 500));
        this.timers.push(setInterval(() => this.#send('HEARTBEAT', { hidden: document.hidden }), 15000));
        this.timers.push(setInterval(() => this.#flush(), 5000));
    }

    #stopTimers() {
        this.timers.forEach(clearInterval);
        this.timers = [];
    }

    #detect() {
        const v = this.video;
        if (!this.on || !v || !this.face || v.readyState < 2 || !v.videoWidth || document.hidden) return;
        const now = performance.now();
        let faces = [];
        try { faces = this.face.detectForVideo(v, now).detections || []; } catch (_) { return; }

        const dir = faces.length === 1 ? headDirection(faces[0].keypoints).state : faces.length > 1 ? 'forward' : 'unknown';
        const ev = this.fsm.update({ faces: faces.length, dir }, Date.now());
        if (ev) this.#send(ev);

        // Objek lebih berat: ±1 kali per detik.
        if (this.obj && now - this.lastObjAt >= 1000) {
            this.lastObjAt = now;
            let dets = [];
            try { dets = this.obj.detectForVideo(v, now + 1).detections || []; } catch (_) { return; }
            const phone = pickPhone(dets, this.cfg.thresholds.phoneMinConfidence);
            const r = this.vote.push(!!phone);
            if (r.on && !this.phoneOn) {
                this.phoneOn = true;
                this.#send('PHONE_DETECTED', { confidence: Number(phone.confidence.toFixed(3)), ...(phone.orientation ? { orientation: phone.orientation } : {}) });
            } else if (r.off && this.phoneOn) {
                this.phoneOn = false;
                this.#send('PHONE_CLEARED');
            }
        }
    }

    // ---------------------------------------------------------------- pengiriman (antrean, ulang otomatis)
    #send(type, extra = {}) {
        this.queue.push({ body: { type, ...extra }, at: Date.now() });
        if (this.queue.length > 100) this.queue.shift();
        this.#flush();
    }

    async #flush() {
        if (this.flushing) return;
        this.flushing = true;
        try {
            while (this.queue.length) {
                const it = this.queue[0];
                if (Date.now() - it.at > 5 * 60 * 1000) { this.queue.shift(); continue; }
                let res;
                try {
                    res = await fetch(this.cfg.eventUrl, {
                        method: 'POST', credentials: 'same-origin', keepalive: true,
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify(it.body),
                    });
                } catch (_) { break; }
                if (res.status >= 500 || res.status === 429 || res.status === 401 || res.status === 419) break;
                this.queue.shift();
            }
        } finally {
            this.flushing = false;
        }
    }

    // ---------------------------------------------------------------- lencana transparansi
    #renderBadge(msg) {
        if (!this.badge) {
            this.badge = document.createElement('div');
            this.badge.setAttribute('role', 'status');
            this.badge.className = 'syn-toast fixed bottom-4 left-4 z-40 max-w-xs rounded-2xl border px-3 py-2 text-xs shadow-lg';
            document.body.append(this.badge);
        }
        const show = this.on || !!msg;
        this.badge.classList.toggle('hidden', !show);
        this.badge.classList.toggle('bg-emerald-50', this.on);
        this.badge.classList.toggle('border-emerald-200', this.on);
        this.badge.classList.toggle('text-emerald-800', this.on);
        this.badge.classList.toggle('bg-amber-50', !this.on);
        this.badge.classList.toggle('border-amber-200', !this.on);
        this.badge.classList.toggle('text-amber-900', !this.on);
        this.badge.textContent = this.on
            ? '🎥 Kamera aktif · pemantauan room berlangsung. Gambar diproses di perangkat Anda dan tidak dikirim/disimpan.'
            : msg || '';
    }
}
