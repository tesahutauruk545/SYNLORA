/**
 * Aturan status fokus (fungsi murni, mudah diuji). Server menentukan ulang status dari JENIS EVENT
 * (lihat app/Services/FocusRules.php); fungsi ini dipakai klien untuk tampilan lokal dan untuk memilih
 * event yang dikirim.
 *
 * Status hanyalah INDIKATOR, bukan bukti konsentrasi. Dua sinyal dibaca TERPISAH:
 *   - BROWSER : tab tersembunyi / jendela kehilangan fokus (Page Visibility API + window focus)
 *   - WAJAH   : ada/tidaknya wajah di kamera (MediaPipe), hanya bila kamera diaktifkan dan berfungsi
 *
 * Hasil:
 *   ABU    : pemantauan tidak berjalan (jeda / istirahat / belum mulai)
 *   HIJAU  : tab aktif dan (jika kamera menyala) wajah terdeteksi
 *   KUNING : wajah tidak terlihat >= 5 dtk, jendela kehilangan fokus, atau tab baru ditinggalkan (< 10 dtk)
 *   MERAH  : tab/aplikasi ditinggalkan >= 10 dtk
 * Orientasi HP TIDAK memengaruhi status. Wajah hilang SENDIRIAN tidak pernah MERAH.
 * Status tambahan yang diturunkan server: DISCONNECTED (tidak ada detak) dan UNKNOWN (belum ada laporan).
 * Kamera dipisahkan sebagai CAMERA_ON / CAMERA_OFF; tanpa kamera pemantauan tab tetap berjalan.
 */
export const THRESHOLDS = {
    faceLostShortMs: 5000,
    faceLostLongMs: 10000,
    tabHiddenRedMs: 10000,
};

export function computeFocus({
    running,
    tabHidden = false,
    hiddenMs = 0,
    windowBlurred = false,
    cameraOn = false,
    faceMissingMs = 0,
}) {
    if (!running) {
        return { status: 'ABU', source: 'system', reason: 'Pemantauan tidak berjalan' };
    }
    if (tabHidden) {
        const s = Math.floor(hiddenMs / 1000);
        if (hiddenMs >= THRESHOLDS.tabHiddenRedMs) {
            return { status: 'MERAH', source: 'browser', reason: `Tab/aplikasi SYNLORA ditinggalkan ${s} detik` };
        }
        return { status: 'KUNING', source: 'browser', reason: `Tab SYNLORA baru saja ditinggalkan (${s} detik)` };
    }
    if (windowBlurred) {
        return { status: 'KUNING', source: 'browser', reason: 'Jendela SYNLORA tidak sedang aktif (fokus ada di aplikasi lain)' };
    }
    if (cameraOn && faceMissingMs >= THRESHOLDS.faceLostShortMs) {
        const s = Math.floor(faceMissingMs / 1000);
        return { status: 'KUNING', source: 'face', reason: `Wajah tidak terdeteksi ${s} detik (hanya indikator)` };
    }
    if (cameraOn) {
        return { status: 'HIJAU', source: 'face', reason: 'Tab aktif dan wajah terdeteksi' };
    }
    return { status: 'HIJAU', source: 'browser', reason: 'Tab aktif (kamera tidak dipakai, hanya aktivitas browser yang dipantau)' };
}

export function formatClock(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const m = Math.floor(s / 60);
    return `${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

/** Jenis event yang dikirim ke server ketika status berubah. */
export function eventForTransition(prev, next) {
    if (!prev) return 'MONITOR_START';
    if (next.source === 'browser' && next.status === 'MERAH') return 'TAB_AWAY_LONG';
    if (next.source === 'browser' && next.status === 'KUNING') return /Jendela/.test(next.reason) ? 'WINDOW_BLUR' : 'TAB_SWITCH_OUT';
    if (prev.source === 'browser' && prev.status !== 'HIJAU') return /Jendela/.test(prev.reason) ? 'WINDOW_FOCUS' : 'TAB_SWITCH_IN';
    if (next.source === 'face' && next.status === 'KUNING') return 'FACE_LOST_SHORT';
    if (next.status === 'HIJAU') return 'FACE_DETECTED';
    return 'MONITOR_START';
}

// ---------------------------------------------------------------- sinyal tambahan kamera
// Semuanya hanya SINYAL TAMBAHAN: dicatat untuk ditinjau dosen, tidak mengubah warna status, dan bukan bukti
// bahwa mahasiswa memakai HP/AI. Pemrosesan 100% di perangkat; tidak ada gambar yang dikirim.

export const SIGNALS = {
    darkLuminance: 28, // rata-rata kecerahan (0-255) di bawah ini dianggap gelap
    brightLuminance: 40, // pulih bila di atas ini (histeresis agar tidak berkedip)
    darkReportMs: 6000,
    qualityOkMs: 3000,
    poseAwayMs: 8000,
    poseBackMs: 2000,
    frozenFrameMs: 4000,
    yawLimit: 0.55, // |offset hidung / jarak antar-mata|
    pitchDownLimit: 0.3, // rasio mata-hidung terhadap mata-mulut; kecil = menunduk
};

/** Rata-rata kecerahan dari data piksel RGBA (mis. canvas kecil hasil menggambar frame video). */
export function meanLuminance(rgba) {
    if (!rgba || rgba.length < 4) return null;
    let sum = 0;
    let n = 0;
    for (let i = 0; i + 2 < rgba.length; i += 4) {
        sum += 0.299 * rgba[i] + 0.587 * rgba[i + 1] + 0.114 * rgba[i + 2];
        n++;
    }
    return n ? sum / n : null;
}

/**
 * Perkiraan kasar arah kepala dari 6 titik MediaPipe FaceDetector (normalisasi 0..1):
 * [0] mata kanan, [1] mata kiri, [2] ujung hidung, [3] tengah mulut, [4-5] telinga.
 * Hanya heuristik 2D: kacamata, sudut kamera, dan wajah kecil memengaruhi hasil. state:
 *   forward | turned (menoleh) | down (menunduk) | unknown (titik tidak lengkap/tidak masuk akal)
 */
export function estimateHeadPose(kp) {
    const unknown = { state: 'unknown', yaw: null, pitch: null };
    if (!Array.isArray(kp) || kp.length < 4) return unknown;
    const [re, le, nose, mouth] = kp;
    if (![re, le, nose, mouth].every((p) => p && Number.isFinite(p.x) && Number.isFinite(p.y))) return unknown;

    const eyeDist = Math.hypot(le.x - re.x, le.y - re.y);
    const eyeMid = { x: (re.x + le.x) / 2, y: (re.y + le.y) / 2 };
    const eyeToMouth = mouth.y - eyeMid.y;
    if (eyeDist < 1e-3 || eyeToMouth < 1e-3) return unknown;

    const yaw = (nose.x - eyeMid.x) / eyeDist;
    const pitch = (nose.y - eyeMid.y) / eyeToMouth;

    if (Math.abs(yaw) > SIGNALS.yawLimit) return { state: 'turned', yaw, pitch };
    if (pitch < SIGNALS.pitchDownLimit) return { state: 'down', yaw, pitch };
    return { state: 'forward', yaw, pitch };
}

/**
 * Mesin keadaan kecil dengan histeresis waktu: melaporkan "masuk" setelah kondisi bertahan >= enterMs dan
 * "keluar" setelah kondisi hilang >= exitMs. Dipakai untuk gambar gelap dan pose kepala (hanya melapor saat berubah).
 */
export class Hysteresis {
    constructor(enterMs, exitMs) {
        this.enterMs = enterMs;
        this.exitMs = exitMs;
        this.active = false;
        this.since = null;
    }

    /** @returns {'enter'|'exit'|null} */
    update(condition, nowMs) {
        if (condition !== !this.active) {
            // kondisi searah dengan keadaan sekarang: batalkan hitung mundur perubahan
            this.since = null;
            return null;
        }
        if (this.since === null) this.since = nowMs;
        if (nowMs - this.since >= (this.active ? this.exitMs : this.enterMs)) {
            this.active = !this.active;
            this.since = null;
            return this.active ? 'enter' : 'exit';
        }
        return null;
    }

    reset() {
        this.active = false;
        this.since = null;
    }
}
