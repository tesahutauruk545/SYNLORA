/**
 * Ping presence ringan: memberi tahu server bahwa pengguna sedang membuka SYNLORA (online), terlepas dari
 * ada-tidaknya sesi Pomodoro. Tidak mengirim data aktivitas. Berhenti sendiri bila sesi login berakhir.
 */
export function startPresence({ url, intervalSeconds = 25 }) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let stopped = false;
    let timer = null;

    async function ping() {
        if (stopped) return;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                keepalive: true,
            });
            if (res.status === 401 || res.status === 419) stopped = true; // login berakhir: berhenti
        } catch (e) { /* offline sementara: coba lagi pada putaran berikutnya */ }
    }

    ping();
    // Tetap berdetak walau tab di latar belakang (tab tersembunyi masih "online"); frekuensi ditentukan browser.
    timer = setInterval(ping, Math.max(10, intervalSeconds) * 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) ping(); });
    window.addEventListener('online', ping);
    window.addEventListener('pagehide', () => clearInterval(timer));
}
