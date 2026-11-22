/**
 * Satu sumber kamera untuk seluruh halaman. Study Meet (LiveKit) dan Face Monitoring (MediaPipe) mengambil
 * MediaStream yang SAMA dari sini, sehingga getUserMedia untuk video hanya dipanggil sekali dan tidak ada
 * rebutan perangkat / permintaan izin ganda. Kamera fisik baru dimatikan setelah SEMUA pemakai melepasnya.
 *
 * Privasi: stream hanya dibaca MediaPipe secara lokal di perangkat. Yang dikirim ke peserta meeting hanyalah
 * video meeting itu sendiri (lewat LiveKit, atas pilihan pengguna); server SYNLORA tidak menerima gambar wajah.
 */
class CameraHub {
    constructor() {
        this.stream = null;
        this.holders = new Set();
        this.pending = null;
    }

    #live() {
        return !!this.stream && this.stream.getVideoTracks().some((t) => t.readyState === 'live');
    }

    /** Ambil (atau gunakan ulang) stream kamera. Melempar galat getUserMedia apa adanya (NotAllowedError, dst.). */
    async acquire(holder) {
        this.holders.add(holder);
        try {
            if (this.#live()) return this.stream;
            if (this.stream) this.#stop(); // sisa stream yang sudah mati

            if (!this.pending) {
                this.pending = navigator.mediaDevices
                    .getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false })
                    .then((s) => { this.stream = s; return s; })
                    .finally(() => { this.pending = null; });
            }
            return await this.pending;
        } catch (err) {
            this.holders.delete(holder);
            throw err;
        }
    }

    /** Lepaskan kamera. Perangkat dimatikan hanya bila tidak ada pemakai lain. */
    release(holder) {
        this.holders.delete(holder);
        if (this.holders.size === 0) this.#stop();
    }

    isHeldBy(holder) {
        return this.holders.has(holder);
    }

    get videoTrack() {
        return this.#live() ? this.stream.getVideoTracks()[0] : null;
    }

    #stop() {
        if (this.stream) this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
    }
}

export const cameraHub = window.__synloraCameraHub ??= new CameraHub();
