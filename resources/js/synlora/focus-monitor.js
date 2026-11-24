import { cameraHub } from './camera-hub.js';
import { computeFocus, THRESHOLDS, eventForTransition, SIGNALS, meanLuminance, estimateHeadPose, Hysteresis } from './focus-state.js';

/** ID acak untuk mendeteksi dua tab / dua browser pada satu sesi (bukan identitas pribadi). */
function stableId(storage, key) {
    try {
        let v = storage.getItem(key);
        if (!v) {
            v = (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Math.random().toString(36).slice(2) + Date.now().toString(36)).slice(0, 32);
            storage.setItem(key, v);
        }
        return v;
    } catch (_) {
        return null; // penyimpanan diblokir: deteksi dua tab/perangkat tidak tersedia, pemantauan lain tetap berjalan
    }
}

/**
 * Pemantauan fokus di browser. Dua sinyal dibaca terpisah (lihat focus-state.js):
 *   - aktivitas BROWSER : Page Visibility API (tab tersembunyi) + window focus/blur
 *   - deteksi WAJAH     : MediaPipe Face Detector, opsional, 100% di perangkat
 *
 * Privasi:
 *   - Frame kamera hanya dibaca oleh detektor lokal. TIDAK ditampilkan, TIDAK dikirim, TIDAK disimpan.
 *   - Server hanya menerima: jenis event, warna status, sumber sinyal, dan teks alasan singkat.
 *   - Kamera otomatis dimatikan ketika sesi dijeda / istirahat / selesai, dan dinyalakan lagi (jika
 *     pengguna memintanya) saat fase fokus berikutnya.
 *   - Tidak ada pengganti berbasis warna kulit: jika model gagal dimuat, error ditampilkan apa adanya
 *     dan pemantauan tab tetap berjalan.
 */
export class FocusMonitor {
    /**
     * @param {object} opts
     * @param {HTMLVideoElement} opts.video
     * @param {(type:string,status:string|null,details:string,duration?:number,source?:string)=>void} opts.send
     * @param {(status:string,reason:string,source:string)=>void} opts.onStatus
     * @param {(msg:string|null)=>void} opts.onError
     * @param {(on:boolean)=>void} opts.onCameraChange
     * @param {{wasmPath:string, modelPaths:string[]}} opts.mediapipe
     */
    constructor(opts) {
        this.o = opts;
        this.running = false;
        this.wantCamera = false; // pengguna meminta kamera (bertahan lintas jeda/istirahat)
        this.cameraOn = false;
        this.stream = null;
        this.detector = null;
        this.loop = null;
        this.ticker = null;
        this.faceMissingSince = null;
        this.hiddenSince = document.hidden ? Date.now() : null;
        this.windowBlurred = false;
        this.otherVisibleAt = 0; // tab SYNLORA lain (dengan sesi) terlihat -> tab ini tidak dianggap meninggalkan
        this.current = null; // status terakhir yang DILAPORKAN
        this.deviceId = stableId(window.localStorage, 'synlora-device');
        this.tabId = stableId(window.sessionStorage, 'synlora-tab');
        this.lastVideoTime = -1;
        this.lastFrameAt = 0;
        this.dark = new Hysteresis(SIGNALS.darkReportMs, SIGNALS.qualityOkMs);
        this.pose = new Hysteresis(SIGNALS.poseAwayMs, SIGNALS.poseBackMs);
        this.lumaCanvas = null;
        this.shown = { status: 'ABU', reason: 'Pemantauan belum aktif', source: 'system' };

        document.addEventListener('visibilitychange', () => this.#onVisibility());
        window.addEventListener('blur', () => this.#onWindowFocus(false));
        window.addEventListener('focus', () => this.#onWindowFocus(true));
        this.#setupTabSync();
        window.addEventListener('pagehide', () => this.#stopStream());
        // Laptop bangun dari sleep / halaman dipulihkan (Page Lifecycle): periksa ulang fokus dan kamera.
        document.addEventListener('resume', () => this.#afterWake());
        window.addEventListener('pageshow', () => this.#afterWake());
    }

    // ---------------------------------------------------------- status

    #hiddenMs() {
        return this.hiddenSince === null ? 0 : Date.now() - this.hiddenSince;
    }

    #faceMissingMs() {
        return this.faceMissingSince === null ? 0 : Date.now() - this.faceMissingSince;
    }

    #compute() {
        return computeFocus({
            running: this.running,
            tabHidden: this.#effectiveHidden(),
            hiddenMs: this.#hiddenMs(),
            windowBlurred: this.windowBlurred,
            cameraOn: this.cameraOn,
            faceMissingMs: this.#faceMissingMs(),
        });
    }

    /**
     * Hitung ulang status, perbarui UI, dan laporkan ke server HANYA bila status/sumber berubah
     * (atau bila `force` diberikan untuk event yang selalu dicatat).
     */
    #evaluate(force = null) {
        const next = this.#compute();
        this.shown = next;
        this.o.onStatus(next.status, next.reason, next.source);

        if (!this.running) return;

        const changed = !this.current || this.current.status !== next.status || this.current.source !== next.source;
        if (!changed && !force) return;

        const type = force || eventForTransition(this.current, next);
        this.current = next;
        this.o.send(type, next.status, next.reason, undefined, next.source);
    }

    /** Dipanggil oleh timer Pomodoro: true hanya saat fase FOKUS sedang berjalan (bukan jeda/istirahat). */
    setRunning(isRunning) {
        if (this.running === isRunning) return;
        this.running = isRunning;
        this.faceMissingSince = null;
        this.current = null;

        if (isRunning) {
            this.windowBlurred = !document.hasFocus() && !document.hidden;
            this.hiddenSince = document.hidden ? Date.now() : null;
            this.#startTicker();
            this.#evaluate('MONITOR_START');
            // Kamera dimatikan selama jeda/istirahat demi privasi; nyalakan lagi bila pengguna menginginkannya.
            if (this.wantCamera && !this.cameraOn) this.#startCamera(true);
        } else {
            this.#stopTicker();
            if (this.cameraOn) this.#suspendCamera();
            this.#evaluate();
        }
    }

    #startTicker() {
        this.#stopTicker();
        // Mengevaluasi transisi berbasis waktu (mis. tab tersembunyi > 10 detik menjadi MERAH).
        this.ticker = setInterval(() => this.#evaluate(), 1000);
    }

    #stopTicker() {
        if (this.ticker) clearInterval(this.ticker);
        this.ticker = null;
    }

    // ----------------------------------------------- tab dan jendela

    #onVisibility() {
        if (document.hidden) {
            this.hiddenSince = Date.now();
            this.channel?.postMessage({ visible: false });
        } else {
            this.hiddenSince = null;
            this.channel?.postMessage({ visible: true });
            this.faceMissingSince = null; // beri waktu kamera "bangun" kembali
            this.windowBlurred = !document.hasFocus();
        }
        this.#evaluate();
    }

    #onWindowFocus(focused) {
        this.windowBlurred = !focused && !document.hidden;
        this.#evaluate();
    }

    /**
     * Beberapa tab SYNLORA: hanya tab yang sedang terlihat yang melaporkan. Tab lain yang tersembunyi
     * tidak dianggap "meninggalkan" selama tab SYNLORA lain (dengan sesi aktif) sedang terlihat.
     */
    #setupTabSync() {
        if (!('BroadcastChannel' in window)) return;
        try {
            this.channel = new BroadcastChannel('synlora-visible');
            this.channel.onmessage = (e) => {
                if (e.data && e.data.visible) this.otherVisibleAt = Date.now();
            };
            setInterval(() => {
                if (this.running && !document.hidden) this.channel.postMessage({ visible: true });
            }, 2000);
        } catch (_) { /* tidak didukung: tiap tab bekerja sendiri */ }
    }

    #effectiveHidden() {
        return document.hidden && Date.now() - this.otherVisibleAt > 5000;
    }

    /** Data tambahan untuk detak ke server (pembanding silang bila event gagal terkirim). */
    heartbeatExtras() {
        const ids = {};
        if (this.deviceId) ids.device_id = this.deviceId;
        if (this.tabId) ids.tab_id = this.tabId;

        return this.running ? { hidden: this.#effectiveHidden(), ...ids } : ids;
    }

    #afterWake() {
        this.windowBlurred = !document.hasFocus() && !document.hidden;
        this.hiddenSince = document.hidden ? this.hiddenSince ?? Date.now() : null;
        if (this.cameraOn && this.stream && this.stream.getVideoTracks().every((t) => t.readyState === 'ended')) {
            this.#cameraInterrupted();
        }
        this.#evaluate();
    }

    // ------------------------------------------------------ kamera

    /** Dipanggil tombol "Aktifkan Kamera". */
    async enableCamera() {
        this.wantCamera = true;
        window.dispatchEvent(new CustomEvent('synlora:camera-request', { detail: { on: true } }));
        return this.#startCamera(false);
    }

    async #startCamera(silent) {
        this.o.onError(null);

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.wantCamera = false;
            this.o.onError('Browser ini tidak mendukung akses kamera (atau halaman tidak memakai HTTPS/localhost). Pemantauan tab tetap berjalan tanpa kamera.');
            this.o.send('CAMERA_UNAVAILABLE', null, 'Browser tidak mendukung kamera', undefined, 'face');
            return false;
        }

        try {
            // Stream kamera dipakai bersama (CameraHub): tidak ada getUserMedia ganda dan kamera tidak mati
            // selama pemantauan room masih memakainya (mis. saat Pomodoro dijeda).
            this.stream = await cameraHub.acquire('focus-monitor');
        } catch (err) {
            this.wantCamera = false;
            this.o.onError(this.#cameraErrorMessage(err));
            const n = err && err.name;
            const type = n === 'NotAllowedError' || n === 'PermissionDeniedError' ? 'CAMERA_DENIED'
                : n === 'NotFoundError' || n === 'DevicesNotFoundError' ? 'CAMERA_UNAVAILABLE' : 'CAMERA_ERROR';
            this.o.send(type, null, (err && err.name) || 'Kamera gagal', undefined, 'face');
            return false;
        }

        const video = this.o.video;
        video.srcObject = this.stream;
        video.muted = true;
        video.setAttribute('playsinline', '');
        try {
            await video.play();
        } catch (_) { /* autoplay sudah ditangani atribut */ }

        // Kamera dicabut / ditutup / dipakai aplikasi lain di tengah sesi: track berakhir atau berhenti (mute).
        this.stream.getVideoTracks().forEach((t) => {
            t.addEventListener('ended', () => this.#cameraInterrupted());
            t.addEventListener('mute', () => setTimeout(() => { if (t.muted) this.#cameraInterrupted(); }, 3000));
        });

        if (!this.detector) {
            try {
                this.detector = await this.#loadDetector();
            } catch (err) {
                console.error('Gagal memuat MediaPipe Face Detector:', err);
                this.wantCamera = false;
                this.#stopStream();
                this.o.send('MODEL_LOAD_FAILED', null, 'Model deteksi wajah gagal dimuat', undefined, 'face');
                this.o.onError(
                    'Model deteksi wajah belum tersedia di server ini, sehingga kamera tidak dipakai. ' +
                    'Jalankan "php artisan synlora:install-face-model" (atau lihat README) agar deteksi wajah berjalan lokal. ' +
                    'Pemantauan tab tetap berjalan tanpa kamera.'
                );
                return false;
            }
        }

        this.cameraOn = true;
        this.faceMissingSince = null;
        this.lastVideoTime = -1;
        this.lastFrameAt = Date.now();
        this.dark.reset();
        this.pose.reset();
        this.o.onCameraChange(true);
        if (!silent) this.o.send('CAMERA_ON', this.current ? this.current.status : null, 'Kamera aktif', undefined, 'face');
        this.loop = setInterval(() => this.#detect(), 400);
        this.#evaluate();
        return true;
    }

    /** Matikan kamera oleh pengguna. */
    disableCamera() {
        this.wantCamera = false;
        window.dispatchEvent(new CustomEvent('synlora:camera-request', { detail: { on: false } }));
        const wasOn = this.cameraOn;
        this.#teardownCamera();
        if (wasOn) this.o.send('CAMERA_OFF', this.current ? this.current.status : null, 'Kamera dimatikan', undefined, 'face');
        this.#evaluate();
    }

    /**
     * Kamera terputus di tengah sesi. Tanpa ini, frame terakhir yang membeku bisa terus tampak "ada wajah" dan status
     * HIJAU dari sumber wajah bertahan. Setelah terputus, sumber status kembali ke aktivitas browser saja, dan
     * server mencatat CAMERA_INTERRUPTED (keandalan terbatas, bukan tuduhan).
     */
    #cameraInterrupted() {
        if (!this.cameraOn) return;
        this.wantCamera = false;
        this.#teardownCamera();
        this.o.onError('Kamera terputus (dicabut, ditutup, atau dipakai aplikasi lain). Pemantauan tab tetap berjalan; klik "Aktifkan Kamera" bila ingin memakainya lagi.');
        this.o.send('CAMERA_INTERRUPTED', this.current ? this.current.status : null, 'Kamera terputus', undefined, 'face');
        this.#evaluate();
    }

    /** Matikan kamera sementara (jeda/istirahat) tanpa melupakan keinginan pengguna. */
    #suspendCamera() {
        this.#teardownCamera();
    }

    #teardownCamera() {
        if (this.loop) clearInterval(this.loop);
        this.loop = null;
        this.#stopStream();
        this.cameraOn = false;
        this.faceMissingSince = null;
        this.o.onCameraChange(false);
    }

    #stopStream() {
        if (this.stream) {
            cameraHub.release('focus-monitor');
            this.stream = null;
        }
        if (this.o.video) this.o.video.srcObject = null;
    }

    #cameraErrorMessage(err) {
        switch (err && err.name) {
            case 'NotAllowedError':
            case 'PermissionDeniedError':
                return 'Izin kamera ditolak. Izinkan kamera pada pengaturan browser untuk situs ini, lalu coba lagi.';
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                return 'Kamera tidak ditemukan pada perangkat ini.';
            case 'NotReadableError':
            case 'TrackStartError':
                return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi tersebut lalu coba lagi.';
            default:
                return 'Tidak dapat mengakses kamera: ' + ((err && err.message) || 'kesalahan tidak diketahui');
        }
    }

    async #loadDetector() {
        const { FaceDetector, FilesetResolver } = await import('@mediapipe/tasks-vision');
        const vision = await FilesetResolver.forVisionTasks(this.o.mediapipe.wasmPath);

        let lastErr = null;
        for (const modelAssetPath of this.o.mediapipe.modelPaths) {
            for (const delegate of ['GPU', 'CPU']) {
                try {
                    return await FaceDetector.createFromOptions(vision, {
                        baseOptions: { modelAssetPath, delegate },
                        runningMode: 'VIDEO',
                        minDetectionConfidence: 0.5,
                    });
                } catch (err) {
                    lastErr = err;
                }
            }
        }
        throw lastErr || new Error('Model tidak dapat dimuat');
    }

    /** Sinyal tambahan dari frame: frame beku, gambar gelap, arah kepala. Hanya dicatat, tidak mengubah warna status. */
    #signals(video, faces) {
        const now = Date.now();

        // 1) Frame beku: waktu video tidak maju padahal kamera dianggap aktif.
        if (video.currentTime !== this.lastVideoTime) {
            this.lastVideoTime = video.currentTime;
            this.lastFrameAt = now;
        } else if (now - this.lastFrameAt > SIGNALS.frozenFrameMs) {
            this.#cameraInterrupted();
            return;
        }

        // 2) Kualitas gambar: canvas 16x12 saja (tidak ditampilkan, tidak disimpan, tidak dikirim).
        try {
            this.lumaCanvas ??= Object.assign(document.createElement('canvas'), { width: 16, height: 12 });
            const ctx = this.lumaCanvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, 0, 0, 16, 12);
            const luma = meanLuminance(ctx.getImageData(0, 0, 16, 12).data);
            if (luma !== null) {
                const ev = this.dark.update(this.dark.active ? luma < SIGNALS.brightLuminance : luma < SIGNALS.darkLuminance, now);
                if (ev === 'enter') this.o.send('CAMERA_LOW_QUALITY', this.current ? this.current.status : null, 'Gambar kamera gelap', undefined, 'face');
                if (ev === 'exit') this.o.send('CAMERA_QUALITY_OK', this.current ? this.current.status : null, 'Gambar kamera pulih', undefined, 'face');
            }
        } catch (_) { /* canvas tidak tersedia: lewati sinyal ini */ }

        // 3) Arah kepala (hanya bila tepat satu wajah terdeteksi; hasil heuristik, bukan bukti).
        if (faces.length === 1) {
            const pose = estimateHeadPose(faces[0].keypoints);
            if (pose.state !== 'unknown') {
                const ev = this.pose.update(pose.state !== 'forward', now);
                if (ev === 'enter') this.o.send('POSE_AWAY', this.current ? this.current.status : null, `Kepala ${pose.state}`, undefined, 'face');
                if (ev === 'exit') this.o.send('POSE_FORWARD', this.current ? this.current.status : null, 'Kepala menghadap layar', undefined, 'face');
            }
        }
    }

    #detect() {
        const video = this.o.video;
        if (!this.detector || !video || video.readyState < 2 || !video.videoWidth) return;
        if (document.hidden) return; // frame kamera tidak andal saat tab tersembunyi

        let faces = [];
        try {
            faces = this.detector.detectForVideo(video, performance.now()).detections || [];
        } catch (err) {
            console.error('Deteksi wajah gagal:', err);
            return;
        }

        this.#signals(video, faces);

        if (!this.cameraOn) return; // #signals dapat menandai kamera terputus

        if (faces.length > 0) {
            this.faceMissingSince = null;
        } else if (this.faceMissingSince === null) {
            this.faceMissingSince = Date.now();
        }
        // Perubahan status (HIJAU <-> KUNING karena wajah) ditangani #evaluate bersama ticker 1 dtk,
        // tetapi evaluasi segera agar kembali HIJAU terasa instan.
        if (faces.length > 0 || this.#faceMissingMs() >= THRESHOLDS.faceLostShortMs) this.#evaluate();
    }
}
