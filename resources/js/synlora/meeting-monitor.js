import { computeMeetingFocus, DEFAULT_FACE_GRACE_MS } from './meeting-state.js';
import { loadFaceDetector } from './face-detector-loader.js';

/**
 * Monitoring fokus untuk Virtual Meeting.
 *  - Deteksi wajah: MediaPipe Face Detector, berjalan di perangkat. Video tidak ditampilkan,
 *    dikirim, atau disimpan; hanya status + alasan yang dikirim ke server.
 *  - Halaman aktif: Page Visibility API.
 *  - Aktif sejak start() (saat peserta masuk ke konferensi) sampai stop() (keluar / meeting berakhir).
 *  - Tidak ada hubungan dengan Pomodoro.
 */
export class MeetingMonitor {
    /**
     * @param {object} o
     * @param {HTMLVideoElement} o.video
     * @param {number} [o.graceMs]
     * @param {{wasmPath:string, modelPaths:string[]}} o.mediapipe
     * @param {(c:{status:string, reason:string, eventType:string|null})=>void} o.onChange
     * @param {(state:string, message:string|null)=>void} o.onCamera
     */
    constructor(o) {
        this.o = o;
        this.graceMs = o.graceMs ?? DEFAULT_FACE_GRACE_MS;
        this.monitoring = false;
        this.tabHidden = document.hidden;
        this.cameraState = 'off';
        this.faceMissingSince = null;
        this.faceLost = false;
        this.stream = null;
        this.detector = null;
        this.loop = null;
        this.current = { status: 'ABU', reason: 'Pemantauan belum aktif' };

        this._onVisibility = () => this.#visibility();
        document.addEventListener('visibilitychange', this._onVisibility);
    }

    /** Dipanggil saat tombol Gabung ditekan (gesture pengguna) untuk memunculkan dialog izin kamera. */
    async requestPermission() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.#setCamera('error', 'Browser tidak mendukung kamera (butuh HTTPS atau localhost).');
            return 'error';
        }
        try {
            const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            s.getTracks().forEach((t) => t.stop()); // hanya meminta izin; kamera dibuka lagi setelah Jitsi tersambung
            return 'granted';
        } catch (err) {
            const denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
            this.#setCamera(denied ? 'denied' : 'error', this.#cameraMessage(err));
            return denied ? 'denied' : 'error';
        }
    }

    async start() {
        if (this.monitoring) return;
        this.monitoring = true;
        this.tabHidden = document.hidden;
        this.faceMissingSince = null;
        this.faceLost = false;

        if (this.cameraState !== 'denied') {
            this.#setCamera('pending', null);
        }
        this.#recompute('MONITOR_START', 'Pemantauan fokus dimulai');

        if (this.cameraState === 'denied') return;
        await this.#openCamera();
    }

    stop() {
        if (!this.monitoring && !this.stream) return;
        const was = this.monitoring;
        this.monitoring = false;
        if (this.loop) clearInterval(this.loop);
        this.loop = null;
        this.#closeCamera();
        this.current = { status: 'ABU', reason: 'Pemantauan berhenti' };
        if (was) this.o.onChange({ ...this.current, eventType: null });
    }

    destroy() {
        this.stop();
        document.removeEventListener('visibilitychange', this._onVisibility);
    }

    // --------------------------------------------------------------- internal

    #visibility() {
        this.tabHidden = document.hidden;
        if (!this.monitoring) return;
        if (this.tabHidden) {
            this.#recompute('TAB_HIDDEN', 'Berpindah tab atau jendela lain');
        } else {
            this.faceMissingSince = null; // beri waktu kamera "bangun" kembali
            this.faceLost = false;
            this.#recompute('TAB_VISIBLE', 'Kembali ke halaman meeting');
        }
    }

    #faceMissingMs() {
        return this.faceMissingSince === null ? 0 : Date.now() - this.faceMissingSince;
    }

    #recompute(eventType = null, details = null) {
        const next = computeMeetingFocus({
            monitoring: this.monitoring,
            tabHidden: this.tabHidden,
            cameraState: this.cameraState,
            faceMissingMs: this.#faceMissingMs(),
            graceMs: this.graceMs,
        });
        const changed = next.status !== this.current.status || next.reason !== this.current.reason;
        this.current = next;
        if (changed || eventType) {
            this.o.onChange({ ...next, eventType, details });
        }
    }

    #setCamera(state, message) {
        this.cameraState = state;
        this.o.onCamera(state, message);
    }

    async #openCamera() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 320 }, height: { ideal: 240 } },
                audio: false,
            });
        } catch (err) {
            const denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
            this.#setCamera(denied ? 'denied' : 'error', this.#cameraMessage(err));
            this.#recompute(denied ? 'CAMERA_DENIED' : 'CAMERA_ERROR', this.#cameraMessage(err));
            return;
        }

        const video = this.o.video;
        video.srcObject = this.stream;
        video.muted = true;
        video.setAttribute('playsinline', '');
        try { await video.play(); } catch (_) { /* autoplay ditangani atribut */ }

        // Jika kamera direbut aplikasi lain (mis. HP yang hanya mengizinkan satu pengguna kamera).
        this.stream.getVideoTracks().forEach((t) => {
            t.addEventListener('ended', () => {
                if (!this.monitoring) return;
                this.#setCamera('error', 'Kamera terputus atau dipakai aplikasi lain.');
                this.#recompute('CAMERA_ERROR', 'Kamera terputus atau dipakai aplikasi lain');
            });
        });

        try {
            this.detector = await loadFaceDetector(this.o.mediapipe);
        } catch (err) {
            console.error('MediaPipe gagal dimuat:', err);
            this.#closeCamera();
            this.#setCamera('error', 'Model deteksi wajah gagal dimuat (perlu internet saat pertama kali, atau letakkan file model di public/vendor/mediapipe/models/).');
            this.#recompute('CAMERA_ERROR', 'Model deteksi wajah gagal dimuat');
            return;
        }

        if (!this.monitoring) { this.#closeCamera(); return; }

        this.#setCamera('ready', null);
        this.#recompute('CAMERA_GRANTED', 'Kamera aktif, deteksi wajah berjalan');
        this.loop = setInterval(() => this.#detect(), 250);
    }

    #closeCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach((t) => t.stop());
            this.stream = null;
        }
        if (this.o.video) this.o.video.srcObject = null;
        if (this.detector && this.detector.close) {
            try { this.detector.close(); } catch (_) { /* abaikan */ }
        }
        this.detector = null;
        if (this.cameraState === 'ready' || this.cameraState === 'pending') this.cameraState = 'off';
    }

    #detect() {
        const v = this.o.video;
        if (!this.monitoring || !this.detector || !v || v.readyState < 2 || !v.videoWidth) return;
        if (document.hidden) return; // frame kamera tidak andal saat tab tersembunyi; sudah MERAH karena pindah tab

        let faces = [];
        try {
            faces = this.detector.detectForVideo(v, performance.now()).detections || [];
        } catch (err) {
            console.error('Deteksi wajah gagal:', err);
            return;
        }

        if (faces.length > 0) {
            this.faceMissingSince = null;
            if (this.faceLost) {
                this.faceLost = false;
                this.#recompute('FACE_FOUND', 'Wajah kembali terdeteksi');
            }
            return;
        }

        if (this.faceMissingSince === null) this.faceMissingSince = Date.now();
        if (!this.faceLost && this.#faceMissingMs() >= this.graceMs) {
            this.faceLost = true;
            this.#recompute('FACE_LOST', 'Wajah tidak terdeteksi');
        }
    }

    #cameraMessage(err) {
        switch (err && err.name) {
            case 'NotAllowedError':
            case 'PermissionDeniedError':
                return 'Izin kamera ditolak. Anda tetap dapat mengikuti meeting, tetapi status fokus akan tampil "tidak terpantau".';
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                return 'Kamera tidak ditemukan pada perangkat ini.';
            case 'NotReadableError':
            case 'TrackStartError':
                return 'Kamera sedang dipakai aplikasi lain sehingga wajah tidak dapat dipantau.';
            default:
                return 'Tidak dapat mengakses kamera: ' + ((err && err.message) || 'kesalahan tidak diketahui');
        }
    }
}
