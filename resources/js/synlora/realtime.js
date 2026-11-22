/**
 * Klien realtime (Laravel Echo -> Reverb). Konfigurasi publik datang dari server saat runtime (bukan dari build Vite).
 * Bila Reverb tidak aktif / gagal tersambung, fungsi mengembalikan null dan halaman tetap memakai polling yang sudah ada.
 * Hak akses channel privat divalidasi di backend (routes/channels.php), bukan di klien.
 */
let echoPromise = null;

export function getEcho(rt, csrf) {
    if (!rt || !rt.key) return Promise.resolve(null);
    echoPromise ??= Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([E, P]) => {
            window.Pusher = P.default;
            const Echo = E.default;
            return new Echo({
                broadcaster: 'reverb',
                key: rt.key,
                wsHost: rt.host || window.location.hostname,
                wsPort: rt.port,
                wssPort: rt.port,
                forceTLS: rt.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: rt.authEndpoint,
                auth: { headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } },
            });
        })
        .catch((err) => {
            console.warn('Realtime tidak aktif, memakai polling:', err);
            return null;
        });
    return echoPromise;
}

/** Teks notifikasi untuk dosen/mahasiswa dari payload FocusStatusChanged. */
export function describe(ev, forStudent) {
    const who = forStudent ? 'Anda' : ev.student_name;
    switch (ev.kind) {
        case 'face_lost':
            return forStudent
                ? ['Wajah tidak terdeteksi 5 detik', 'Posisikan wajah di depan kamera agar status fokus kembali HIJAU.']
                : [`${ev.student_name}: wajah tidak terdeteksi 5 detik`, 'Status KUNING (indikator, bukan bukti tidak fokus).'];
        case 'recovered':
            return forStudent
                ? ['Wajah/aktivitas terdeteksi kembali', 'Status fokus Anda pulih ke HIJAU.']
                : [`${ev.student_name} kembali HIJAU`, 'Status fokus telah pulih.'];
        default:
            return [`${who}: ${ev.status === 'MERAH' ? 'terindikasi terganggu' : 'perlu perhatian'}`, ev.reason || ''];
    }
}
