/**
 * Dashboard monitoring dosen. Data dari /dosen/data (JSON), diperbarui berkala hanya saat tab terlihat.
 * Seluruh teks dari server dimasukkan lewat textContent (bukan innerHTML) agar aman dari XSS.
 */
const STATUS = {
    HIJAU: { label: 'Fokus', cls: 'text-emerald-700' },
    KUNING: { label: 'Perhatian', cls: 'text-amber-700' },
    MERAH: { label: 'Terganggu', cls: 'text-rose-700' },
    ABU: { label: 'Tidak aktif', cls: 'text-slate-500' },
    DISCONNECTED: { label: 'Terputus', cls: 'text-slate-700' },
    UNKNOWN: { label: 'Belum ada data', cls: 'text-slate-500' },
};
const SOURCE = { browser: 'browser', face: 'wajah', orientation: 'orientasi HP', system: 'sistem', server: 'server' };
const PRESENCE = { belajar: 'Belajar', istirahat: 'Istirahat', jeda: 'Dijeda', offline: 'Offline' };
const SEVERITY = { MERAH: 0, DISCONNECTED: 1, KUNING: 2, UNKNOWN: 3, HIJAU: 4, ABU: 5 };
const EVENT_LABEL = {
    SESSION_START: 'Sesi dimulai', SESSION_PAUSE: 'Sesi dijeda', SESSION_RESUME: 'Sesi dilanjutkan', SESSION_END: 'Sesi selesai',
    PHASE_BREAK: 'Mulai istirahat', PHASE_FOCUS: 'Kembali fokus', MONITOR_START: 'Pemantauan dimulai',
    CAMERA_ON: 'Kamera aktif', CAMERA_OFF: 'Kamera mati', FACE_DETECTED: 'Wajah terdeteksi', FACE_LOST_SHORT: 'Wajah tidak terlihat',
    FACE_LOST_LONG: 'Wajah tidak terlihat lama', TAB_SWITCH_OUT: 'Meninggalkan tab', TAB_SWITCH_IN: 'Kembali ke tab',
    TAB_AWAY_LONG: 'Tab ditinggalkan lama', WINDOW_BLUR: 'Jendela tidak aktif', WINDOW_FOCUS: 'Jendela aktif lagi',
    ORIENTATION_CHANGE: 'Orientasi HP berubah', MONITOR_ERROR: 'Galat pemantauan', HEARTBEAT_LOST: 'Koneksi terputus',
    CAMERA_DENIED: 'Izin kamera ditolak', CAMERA_UNAVAILABLE: 'Kamera tidak tersedia', CAMERA_ERROR: 'Kamera bermasalah', MODEL_LOAD_FAILED: 'Model wajah gagal dimuat',
};

const MON_LEVEL = { HIJAU: 'text-emerald-700', KUNING: 'text-amber-700', ABU: 'text-slate-500' };
const CAM = { off: 'kamera mati', on: 'kamera aktif', lost: 'kamera terputus', denied: 'izin kamera ditolak', error: 'kamera bermasalah' };
const FACE = { unknown: 'menunggu deteksi', facing: 'menghadap monitor', not_facing: 'tidak menghadap', turned_left: 'menoleh kiri', turned_right: 'menoleh kanan', not_found: 'wajah tidak terdeteksi' };
const TASK = { idle: 'tidak ada', opened: 'membuka tugas', submitted: 'sudah mengumpulkan' };
const ORI = { vertical: 'vertikal', horizontal: 'horizontal' };

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
};

const mm = (sec) => {
    const m = Math.floor(sec / 60);
    return m >= 60 ? `${Math.floor(m / 60)}j ${m % 60}m` : `${m} mnt`;
};

export function init(cfg) {
    const $ = (id) => document.getElementById(id);
    const list = $('student-list');
    if (!list) return;

    const state = { data: null, status: '', kelas: '', search: '', sort: 'attention', room: '' };
    let timer = null;
    let inflight = false;
    let failures = 0;

    // ---------------------------------------------------------- render
    const matches = (s) => {
        if (state.status === 'CAMERA_OFF') { if (!(s.presence === 'belajar' && s.camera === 'CAMERA_OFF')) return false; }
        else if (state.status && s.status !== state.status) return false;
        if (state.kelas && s.kelas !== state.kelas) return false;
        if (state.search) {
            const q = state.search.toLowerCase();
            if (!(s.name || '').toLowerCase().includes(q) && !(s.nim || '').toLowerCase().includes(q)) return false;
        }
        return true;
    };

    const sorter = {
        attention: (a, b) => (SEVERITY[a.status] - SEVERITY[b.status]) || a.name.localeCompare(b.name, 'id'),
        name: (a, b) => a.name.localeCompare(b.name, 'id'),
        nim: (a, b) => (a.nim || '').localeCompare(b.nim || ''),
        time: (a, b) => b.today_seconds - a.today_seconds || a.name.localeCompare(b.name, 'id'),
    };

    function statusCell(s) {
        const meta = STATUS[s.status] || STATUS.ABU;
        const wrap = el('div', 'min-w-0');
        const top = el('div', `flex items-center gap-2 font-semibold ${meta.cls}`);
        top.append(el('span', `dot dot-${s.status}`), document.createTextNode(meta.label));
        wrap.append(top);
        const why = s.source === 'system' ? (s.reason || '') : (SOURCE[s.source] || '');
        const sub = el('div', 'mt-0.5 truncate text-xs text-synlora-900/55', `${PRESENCE[s.presence] || ''}${s.presence === 'belajar' && why ? ` · ${why}` : ''}${s.presence === 'belajar' ? ` · ${s.camera === 'CAMERA_ON' ? 'kamera' : 'tanpa kamera'}` : ''}`);
        sub.title = `${s.reason || ''} | ${s.camera_label || ''}`;
        wrap.append(sub);
        wrap.append(...monLines(s));
        return wrap;
    }

    /** Baris pemantauan kamera/tab (terpisah dari status Pomodoro). Hanya tampil bila pemantauan room aktif. */
    function monLines(s) {
        const m = s.mon;
        if (!m || !m.active) return [];
        const lv = MON_LEVEL[m.level] || 'text-synlora-900/70';
        const a = el('div', `mt-1 text-xs font-medium ${lv}`, `${m.reason}${m.tab === 'away' ? ' · meninggalkan tab' : ''}`);
        const phone = m.phone === 'detected' ? `HP: terdeteksi${m.phone_orientation ? ` (${ORI[m.phone_orientation]}` : ''}${m.phone_confidence ? `${m.phone_orientation ? ', ' : ' ('}${Math.round(m.phone_confidence * 100)}%` : ''}${m.phone_orientation || m.phone_confidence ? ')' : ''}` : 'HP: tidak terdeteksi';
        const b = el('div', 'text-[11px] leading-snug text-synlora-900/55',
            `${CAM[m.camera] || m.camera} · wajah: ${FACE[m.face] || m.face} · ${phone} · tab: ${m.tab === 'away' ? 'ditinggalkan' : 'aktif'} (${m.tab_away_count}x, ${Math.round(m.tab_away_seconds)} dtk) · tugas: ${TASK[m.task] || m.task}${m.last_event_human ? ` · ${m.last_event_human}` : ''}`);
        return [a, b];
    }

    function pomodoroCell(s) {
        const wrap = el('div', 'text-sm');
        if (s.presence === 'offline') {
            wrap.append(el('span', 'text-synlora-900/45', `${s.today_pomodoros} selesai hari ini`));
        } else {
            const phase = s.phase === 'BREAK' ? 'Istirahat' : 'Fokus';
            const rem = s.presence === 'jeda' ? 'dijeda' : `${Math.max(0, Math.ceil((s.remaining_seconds || 0) / 60))} mnt lagi`;
            wrap.append(el('div', 'font-medium', `${phase} · ${rem}`), el('div', 'text-xs text-synlora-900/55', `${s.session_pomodoros} pomodoro sesi ini`));
        }
        return wrap;
    }

    function row(s) {
        const li = el('li', 'grid grid-cols-2 gap-x-4 gap-y-2 px-4 py-4 md:grid-cols-[2.2fr_.8fr_1.6fr_1.4fr_1fr_1fr_auto] md:items-center md:px-5');
        li.dataset.id = s.id;

        const who = el('div', 'col-span-2 min-w-0 md:col-span-1');
        who.append(el('div', 'truncate font-semibold', s.name), el('div', 'text-xs text-synlora-900/55', s.nim || '—'));

        const kelas = el('div', 'text-sm');
        kelas.append(el('span', 'md:hidden text-xs text-synlora-900/45', 'Kelas '), document.createTextNode(s.kelas || '—'));

        const today = el('div', 'text-sm');
        today.append(el('span', 'md:hidden text-xs text-synlora-900/45', 'Hari ini '), el('span', 'font-medium', mm(s.today_seconds)));

        const lost = s.presence !== 'offline' && s.status === 'DISCONNECTED';
        const seen = el('div', `text-xs ${lost ? 'font-medium text-amber-700' : 'text-synlora-900/55'}`,
            s.presence === 'offline' ? (s.last_seen ? `Terakhir ${s.last_seen}` : 'Belum pernah')
                : lost ? `Terputus, terakhir ${s.last_seen || '—'}` : 'Sedang terhubung');

        const btn = el('button', 'btn-ghost col-span-2 !px-4 !py-2 text-sm md:col-span-1 md:w-20', 'Riwayat');
        btn.type = 'button';
        btn.addEventListener('click', () => openDrawer(s));

        li.append(who, kelas, statusCell(s), pomodoroCell(s), today, seen, btn);
        return li;
    }

    function render() {
        const d = state.data;
        if (!d) return;

        const sum = d.summary;
        const set = (k, v) => { const n = document.querySelector(`[data-sum="${k}"]`); if (n) n.textContent = v; };
        set('total', sum.total);
        set('studying', sum.studying);
        set('present', sum.present_today ?? 0);
        set('goal', sum.goal_met_today ?? 0);
        set('attention', sum.KUNING + sum.MERAH + (sum.DISCONNECTED || 0));
        set('avg', sum.avg_today_minutes);
        document.querySelector('[data-count="all"]').textContent = sum.total;
        ['HIJAU', 'KUNING', 'MERAH', 'ABU', 'DISCONNECTED', 'UNKNOWN'].forEach((k) => { document.querySelector(`[data-count="${k}"]`).textContent = sum[k] ?? 0; });
        document.querySelector('[data-count="CAMERA_OFF"]').textContent = sum.camera_off ?? 0;
        set('m-total', sum.total);
        set('m-active', sum.mon_active ?? 0);
        set('m-focus', sum.mon_focus ?? 0);
        set('m-attention', sum.mon_attention ?? 0);
        set('m-undetected', sum.mon_undetected ?? 0);
        set('m-tab', sum.mon_tab_away ?? 0);
        set('m-phone', sum.mon_phone ?? 0);

        // Isi pilihan kelas tanpa menghapus pilihan aktif.
        const ks = $('f-kelas');
        const want = ['', ...d.kelas].join('|');
        if (ks.dataset.sig !== want) {
            ks.replaceChildren(new Option('Semua kelas', ''), ...d.kelas.map((k) => new Option(k, k)));
            ks.value = d.kelas.includes(state.kelas) ? state.kelas : '';
            state.kelas = ks.value;
            ks.dataset.sig = want;
        }

        const rows = d.students.filter(matches).sort(sorter[state.sort]);
        list.replaceChildren(...rows.map(row));

        const empty = $('empty-state');
        if (!rows.length) {
            empty.classList.remove('hidden');
            empty.textContent = d.students.length
                ? 'Tidak ada mahasiswa yang cocok dengan penyaringan ini.'
                : 'Belum ada mahasiswa di room Anda. Buat room atau bergabung ke room lewat kode, lalu bagikan kodenya.';
        } else {
            empty.classList.add('hidden');
        }
    }

    // ---------------------------------------------------------- polling
    function liveIndicator(ok, text) {
        $('live-dot').className = `dot ${ok ? 'dot-HIJAU' : 'dot-MERAH'}`;
        $('live-text').textContent = text;
    }

    async function load() {
        if (inflight) return;
        inflight = true;
        try {
            const url = new URL(cfg.dataUrl, window.location.origin);
            if (state.room) url.searchParams.set('room', state.room);
            const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.status === 401 || res.status === 419) { liveIndicator(false, 'Sesi login berakhir. Muat ulang halaman.'); return; }
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            state.data = await res.json();
            failures = 0;
            render();
            loadAlerts();
            const t = new Date(state.data.generated_at * 1000);
            liveIndicator(true, `Terbarui ${t.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`);
        } catch (err) {
            failures++;
            liveIndicator(false, 'Gagal memuat, mencoba lagi…');
        } finally {
            inflight = false;
            schedule();
        }
    }

    // Riwayat peringatan monitoring (kamera/wajah/HP/tab): 50 terbaru di room yang diikuti dosen.
    async function loadAlerts() {
        const box = $('alert-list');
        if (!box || !cfg.alertsUrl) return;
        try {
            const url = new URL(cfg.alertsUrl, window.location.origin);
            if (state.room) url.searchParams.set('room', state.room);
            const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok) return;
            const { alerts } = await res.json();
            if (!alerts.length) { box.replaceChildren(el('li', 'px-4 py-6 text-center text-sm text-synlora-900/55', 'Belum ada peringatan monitoring.')); return; }
            box.replaceChildren(...alerts.map((a) => {
                const li = el('li', 'grid gap-x-4 gap-y-1 px-4 py-3 text-sm md:grid-cols-[1.1fr_1.6fr_1fr_.8fr_.8fr]');
                const ori = a.orientation ? ` · ${ORI[a.orientation]}` : '';
                const conf = a.confidence ? ` · ${Math.round(a.confidence * 100)}%` : '';
                li.append(
                    el('div', 'font-semibold', a.student || '—'),
                    el('div', '', `${a.label}${ori}${conf}`),
                    el('div', 'text-xs text-synlora-900/55', `${a.room || ''} · ${a.at}`),
                    el('div', 'text-xs text-synlora-900/70', a.duration_seconds !== null ? `${a.duration_seconds} dtk` : '—'),
                    el('div', `text-xs font-medium ${a.state === 'berlangsung' ? 'text-amber-700' : 'text-emerald-700'}`, a.state),
                );
                li.title = a.status || '';
                return li;
            }));
        } catch (_) { /* dicoba lagi pada pembaruan berikutnya */ }
    }

    function schedule() {
        clearTimeout(timer);
        // Backoff ringan bila gagal berulang; berhenti saat tab tersembunyi.
        const base = cfg.pollSeconds * 1000 * (failures > 2 ? Math.min(6, failures) : 1);
        timer = setTimeout(() => { if (!document.hidden) load(); else schedule(); }, base);
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

    // ---------------------------------------------------------- kontrol
    $('f-search').addEventListener('input', (e) => { state.search = e.target.value.trim(); render(); });
    $('f-kelas').addEventListener('change', (e) => { state.kelas = e.target.value; render(); });
    $('f-sort').addEventListener('change', (e) => { state.sort = e.target.value; render(); });
    $('f-room').addEventListener('change', (e) => {
        state.room = e.target.value;
        const exp = $('export-link');
        const u = new URL(exp.href, window.location.origin);
        if (state.room) u.searchParams.set('room', state.room); else u.searchParams.delete('room');
        exp.href = u.toString();
        clearTimeout(timer);
        load();
    });
    $('status-chips').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-status]');
        if (!b) return;
        state.status = b.dataset.status;
        document.querySelectorAll('#status-chips .chip').forEach((c) => c.classList.toggle('is-on', c === b));
        render();
    });

    // ---------------------------------------------------------- panel riwayat
    const drawer = $('drawer');
    let lastFocusEl = null;
    const closeDrawer = () => { drawer.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); lastFocusEl?.focus(); };
    $('drawer-close').addEventListener('click', closeDrawer);
    $('drawer-backdrop').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !drawer.classList.contains('hidden')) closeDrawer(); });

    async function openDrawer(s) {
        lastFocusEl = document.activeElement;
        drawer.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        $('drawer-title').textContent = s.name;
        $('drawer-sub').textContent = `${s.nim || '—'} · Kelas ${s.kelas || '—'}`;
        const body = $('drawer-body');
        body.replaceChildren(el('p', 'text-synlora-900/60', 'Memuat riwayat…'));
        $('drawer-close').focus();

        try {
            const res = await fetch(cfg.historyUrl.replace('__ID__', s.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const h = await res.json();
            body.replaceChildren(...historyBlocks(h));
        } catch (err) {
            body.replaceChildren(el('p', 'text-rose-700', 'Riwayat gagal dimuat. Coba lagi.'));
        }
    }

    function section(title) {
        const wrap = el('section');
        wrap.append(el('h4', 'mb-2 font-display text-base font-semibold', title));
        return wrap;
    }

    function historyBlocks(h) {
        const blocks = [];

        const d7 = section('Waktu belajar 7 hari (di room yang sama)');
        const max = Math.max(1, ...h.days.map((d) => d.minutes));
        const bars = el('div', 'flex h-28 items-end gap-2');
        h.days.forEach((d) => {
            const col = el('div', 'flex h-full flex-1 flex-col items-center justify-end');
            col.append(el('div', 'mb-1 text-[11px] text-synlora-900/60', `${d.minutes}m`));
            const bar = el('div', `w-full rounded-t bg-synlora-400 ${d.minutes === 0 ? 'opacity-30' : ''}`);
            bar.style.height = `${d.minutes === 0 ? 4 : Math.max(6, Math.round((d.minutes / max) * 100))}%`;
            col.append(bar, el('div', 'mt-1 text-[10px] text-synlora-900/50', d.label.split(' ')[0]));
            bars.append(col);
        });
        d7.append(bars);
        blocks.push(d7);

        const ss = section('Sesi terbaru');
        if (!h.sessions.length) ss.append(el('p', 'text-synlora-900/55', 'Belum ada sesi di room yang sama.'));
        h.sessions.forEach((x) => {
            const a = el('a', 'mb-2 flex items-center justify-between gap-3 rounded-xl border border-sand-300/70 bg-white/70 p-3 hover:bg-white');
            a.href = x.recap_url;
            const l = el('div');
            l.append(el('div', 'font-medium', x.started_at), el('div', 'text-xs text-synlora-900/55', `${x.room || ''} · ${x.status === 'ENDED' ? 'Selesai' : 'Berjalan'}`));
            const r = el('div', 'text-right text-xs text-synlora-900/70');
            r.append(el('div', 'font-medium text-synlora-900', x.effective), el('div', '', `${x.pomodoros} pomodoro${x.focus_percent !== null ? ` · ${x.focus_percent}% hijau` : ''}`));
            a.append(l, r);
            ss.append(a);
        });
        blocks.push(ss);

        const ac = section('Riwayat aktivitas');
        if (!h.activities.length) ac.append(el('p', 'text-synlora-900/55', 'Belum ada aktivitas tercatat.'));
        const ul = el('ul', 'space-y-1.5');
        h.activities.forEach((a) => {
            const li = el('li', 'flex gap-3 text-[13px]');
            li.append(el('span', 'w-24 shrink-0 text-synlora-900/45 tabular-nums', a.time), el('span', '', EVENT_LABEL[a.type] || a.type));
            if (a.details && !EVENT_LABEL[a.type]) li.append(el('span', 'text-synlora-900/55', a.details));
            ul.append(li);
        });
        ac.append(ul);
        blocks.push(ac);

        return blocks;
    }

    load();
}
