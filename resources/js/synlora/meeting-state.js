/**
 * Logika murni untuk Virtual Meeting (tanpa DOM), agar mudah diuji.
 *
 *  HIJAU : wajah terdeteksi DAN halaman aktif
 *  MERAH : wajah tidak terdeteksi (lebih lama dari masa tenggang) ATAU berpindah tab
 *  ABU   : tidak terpantau (belum mulai, kamera ditolak/gagal, terputus, sudah keluar)
 */
export const DEFAULT_FACE_GRACE_MS = 3000;

export function computeMeetingFocus({ monitoring, tabHidden, cameraState, faceMissingMs, graceMs = DEFAULT_FACE_GRACE_MS }) {
    if (!monitoring) {
        return { status: 'ABU', reason: 'Pemantauan belum aktif' };
    }
    if (tabHidden) {
        return { status: 'MERAH', reason: 'Berpindah tab atau jendela lain' };
    }
    switch (cameraState) {
        case 'ready':
            break;
        case 'denied':
            return { status: 'ABU', reason: 'Izin kamera ditolak, wajah tidak dapat dipantau' };
        case 'error':
            return { status: 'ABU', reason: 'Kamera atau model deteksi wajah tidak dapat dipakai' };
        case 'pending':
            return { status: 'ABU', reason: 'Menyiapkan kamera dan deteksi wajah' };
        default:
            return { status: 'ABU', reason: 'Kamera tidak aktif' };
    }
    if (faceMissingMs >= graceMs) {
        return { status: 'MERAH', reason: 'Wajah tidak terdeteksi' };
    }
    return { status: 'HIJAU', reason: 'Wajah terdeteksi dan halaman aktif' };
}

/** Mengganti/menambah baris peserta berdasarkan event real-time. Mengembalikan array baru. */
export function applyParticipantEvent(list, event) {
    if (!event || event.event !== 'participant' || !event.data || !event.data.participant) return list;
    const p = event.data.participant;
    const next = list.filter((x) => x.user_id !== p.user_id);
    next.push(p);
    next.sort((a, b) => a.name.localeCompare(b.name, 'id'));
    return next;
}

/** Peserta tampak "terputus" bila server belum menerima detak > staleSeconds, walau belum ada event. */
export function effectiveConnection(p, serverNowSec, staleSeconds) {
    if (p.connection === 'online' && serverNowSec - p.last_seen > staleSeconds) return 'disconnected';
    return p.connection;
}

export function effectiveStatus(p, serverNowSec, staleSeconds) {
    return effectiveConnection(p, serverNowSec, staleSeconds) === 'online' ? p.status : 'ABU';
}

/** Durasi kehadiran yang terus berjalan untuk peserta online (dibatasi oleh masa stale). */
export function liveSeconds(p, serverNowSec, staleSeconds) {
    if (effectiveConnection(p, serverNowSec, staleSeconds) !== 'online') return p.attendance_seconds;
    return p.attendance_seconds + Math.max(0, Math.min(serverNowSec - p.last_seen, staleSeconds));
}

export function formatDuration(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(h)}:${pad(m)}:${pad(s % 60)}`;
}

export function summarize(list, serverNowSec, staleSeconds) {
    const out = { HIJAU: 0, MERAH: 0, ABU: 0, disconnected: 0, left: 0, online: 0 };
    for (const p of list) {
        const c = effectiveConnection(p, serverNowSec, staleSeconds);
        if (c === 'left') { out.left++; continue; }
        if (c === 'disconnected') { out.disconnected++; continue; }
        out.online++;
        out[p.status] = (out[p.status] || 0) + 1;
    }
    return out;
}
