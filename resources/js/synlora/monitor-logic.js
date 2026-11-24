/**
 * Logika murni pemantauan per room (mudah diuji, tanpa DOM). Semua hasil hanyalah INDIKATOR.
 * Pemrosesan 100% di perangkat; yang dikirim ke server hanya jenis event (bukan gambar).
 */

/**
 * Arah kepala dari 6 titik MediaPipe FaceDetector: [0] mata kanan, [1] mata kiri, [2] hidung, [3] mulut.
 * Gambar kamera TIDAK dicerminkan di sini. Subjek menoleh ke KIRI-nya sendiri => hidung bergeser ke sisi
 * KANAN gambar relatif titik tengah mata => yaw > 0 => 'left'. (Heuristik 2D, bukan pose 3D.)
 */
export function headDirection(kp, limits = {}) {
    const yawLimit = limits.yaw ?? 0.55;
    const pitchLimit = limits.pitch ?? 0.3;
    if (!Array.isArray(kp) || kp.length < 4) return { state: 'unknown' };
    const [re, le, nose, mouth] = kp;
    if (![re, le, nose, mouth].every((p) => p && Number.isFinite(p.x) && Number.isFinite(p.y))) return { state: 'unknown' };
    const eyeDist = Math.hypot(le.x - re.x, le.y - re.y);
    const mid = { x: (re.x + le.x) / 2, y: (re.y + le.y) / 2 };
    const eyeToMouth = mouth.y - mid.y;
    if (eyeDist < 1e-3 || eyeToMouth < 1e-3) return { state: 'unknown' };
    const yaw = (nose.x - mid.x) / eyeDist;
    const pitch = (nose.y - mid.y) / eyeToMouth;
    if (yaw > yawLimit) return { state: 'left', yaw, pitch };
    if (yaw < -yawLimit) return { state: 'right', yaw, pitch };
    if (pitch < pitchLimit) return { state: 'down', yaw, pitch };
    return { state: 'forward', yaw, pitch };
}

/**
 * Pilih handphone dari hasil ObjectDetector (kelas COCO "cell phone") dan tentukan orientasi dari bentuk kotak.
 * Orientasi hanya perkiraan dari rasio kotak pembatas (lebar > tinggi*1.15 = horizontal, tinggi > lebar*1.15 = vertikal);
 * kotak yang hampir persegi atau HP miring => orientasi null (tidak dilaporkan, bukan ditebak).
 */
export function pickPhone(detections, minConfidence = 0.45) {
    let best = null;
    for (const d of detections || []) {
        const c = (d.categories || [])[0];
        if (!c || !/cell\s*phone|mobile\s*phone|smartphone/i.test(c.categoryName || '')) continue;
        if (!(c.score >= minConfidence)) continue;
        if (!best || c.score > best.score) best = { score: c.score, box: d.boundingBox };
    }
    if (!best) return null;
    const { width: w, height: h } = best.box || {};
    let orientation = null;
    if (w > 0 && h > 0) orientation = w > h * 1.15 ? 'horizontal' : h > w * 1.15 ? 'vertical' : null;

    return { confidence: best.score, orientation, box: best.box };
}

/** Penundaan keadaan: berubah ke "aktif" setelah kondisi bertahan >= enterMs, kembali setelah hilang >= exitMs. */
export class Hold {
    constructor(enterMs, exitMs) {
        this.enterMs = enterMs;
        this.exitMs = exitMs;
        this.active = false;
        this.since = null;
    }

    /** @returns {'enter'|'exit'|null} */
    update(cond, now) {
        if (cond === this.active) { this.since = null; return null; }
        this.since ??= now;
        if (now - this.since >= (this.active ? this.exitMs : this.enterMs)) {
            this.active = !this.active;
            this.since = null;
            return this.active ? 'enter' : 'exit';
        }
        return null;
    }

    reset() { this.active = false; this.since = null; }
}

/** Pemilih berbasis beberapa frame: aktif bila >= need dari window frame terakhir positif (mengurangi deteksi palsu). */
export class FrameVote {
    constructor(need = 3, window = 5) {
        this.need = need;
        this.window = window;
        this.buf = [];
    }

    push(positive) {
        this.buf.push(!!positive);
        if (this.buf.length > this.window) this.buf.shift();
        const hits = this.buf.filter(Boolean).length;
        return { on: hits >= this.need, off: this.buf.length >= this.window && hits === 0 };
    }

    reset() { this.buf = []; }
}

/**
 * Mesin status wajah: menerima satu pengamatan per frame, mengembalikan event hanya saat keadaan BERUBAH.
 *   obs: {faces:number, dir:'forward'|'left'|'right'|'down'|'unknown'}
 * Event: FACE_FACING | FACE_NOT_FACING | FACE_TURN_LEFT | FACE_TURN_RIGHT | FACE_NOT_FOUND
 */
export class FaceStateMachine {
    constructor(t = {}) {
        this.t = { notFoundMs: 5000, awayMs: 6000, turnMs: 5000, backMs: 2000, ...t };
        this.state = 'unknown';
        this.cand = null;
        this.candSince = 0;
    }

    reset() { this.state = 'unknown'; this.cand = null; }

    update(obs, now) {
        let target;
        let need;
        if (obs.faces === 0) { target = 'not_found'; need = this.t.notFoundMs; }
        else if (obs.dir === 'left') { target = 'turned_left'; need = this.t.turnMs; }
        else if (obs.dir === 'right') { target = 'turned_right'; need = this.t.turnMs; }
        else if (obs.dir === 'down') { target = 'not_facing'; need = this.t.awayMs; }
        else if (obs.dir === 'forward') { target = 'facing'; need = this.state === 'unknown' ? 0 : this.t.backMs; }
        else return null; // arah tidak diketahui: tidak mengubah apa pun

        if (target === this.state) { this.cand = null; return null; }
        if (this.cand !== target) { this.cand = target; this.candSince = now; }
        if (now - this.candSince < need) return null;

        this.state = target;
        this.cand = null;
        return { not_found: 'FACE_NOT_FOUND', turned_left: 'FACE_TURN_LEFT', turned_right: 'FACE_TURN_RIGHT', not_facing: 'FACE_NOT_FACING', facing: 'FACE_FACING' }[target];
    }
}
