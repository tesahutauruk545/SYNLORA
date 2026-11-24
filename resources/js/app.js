import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Animasi muncul saat scroll (landing page). Tanpa IntersectionObserver / reduced-motion: langsung tampil.
(() => {
    const els = document.querySelectorAll('[data-reveal]');
    if (!els.length) return;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !('IntersectionObserver' in window)) {
        els.forEach((el) => el.classList.add('is-in'));
        return;
    }
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (e.isIntersecting) {
                e.target.classList.add('is-in');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    els.forEach((el) => io.observe(el));
})();

// Halaman dengan Pomodoro / pemantauan fokus menyertakan konfigurasi JSON.
// Modul (termasuk MediaPipe) dimuat hanya di halaman itu, tidak di seluruh situs.
const sessionConfig = document.getElementById('synlora-config');
if (sessionConfig) {
    import('./synlora/session-page.js').then((m) => m.init(JSON.parse(sessionConfig.textContent)));
}

// Dashboard monitoring dosen.
const monitorConfig = document.getElementById('monitor-config');
if (monitorConfig) {
    import('./synlora/lecturer-monitor.js').then((m) => m.init(JSON.parse(monitorConfig.textContent)));
}

// Pemantauan per room (kamera/wajah/HP/tab) berjalan terpisah dari Pomodoro; dimuat hanya di halaman room & tugas.
const roomMonitorConfig = document.getElementById('room-monitor-config');
if (roomMonitorConfig) {
    import('./synlora/monitor-hub.js').then((m) => m.init(JSON.parse(roomMonitorConfig.textContent)));
}

// Lonceng notifikasi (semua halaman berlogin).
const notifConfig = document.getElementById('notif-config');
if (notifConfig) {
    import('./synlora/notifications.js').then((m) => m.init(JSON.parse(notifConfig.textContent)));
}
