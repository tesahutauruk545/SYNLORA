import { MeetingMonitor } from './meeting-monitor.js';
import { RealtimeChannel } from './meeting-realtime.js';
import {
    applyParticipantEvent, effectiveConnection, effectiveStatus, liveSeconds, formatDuration, summarize,
} from './meeting-state.js';

const STYLE = {
    HIJAU: { box: 'bg-emerald-50 border-emerald-500', text: 'text-emerald-700', dot: 'bg-emerald-500', icon: '🟢', title: 'Fokus' },
    MERAH: { box: 'bg-rose-50 border-rose-500', text: 'text-rose-700', dot: 'bg-rose-500', icon: '🔴', title: 'Tidak fokus' },
    ABU: { box: 'bg-slate-100 border-slate-300', text: 'text-slate-600', dot: 'bg-slate-400', icon: '⚪', title: 'Tidak terpantau' },
};

const TOOLBAR = [
    'microphone', 'camera', 'desktop', 'chat', 'raisehand', 'participants-pane',
    'tileview', 'fullscreen', 'select-background', 'settings', 'videoquality', 'hangup',
];

function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text; // selalu textContent: aman dari XSS
    return e;
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (window.JitsiMeetExternalAPI) return resolve();
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = () => (window.JitsiMeetExternalAPI ? resolve() : reject(new Error('API Jitsi tidak tersedia')));
        s.onerror = () => reject(new Error('Tidak dapat memuat skrip Jitsi'));
        document.head.appendChild(s);
    });
}

export function init(cfg) {
    const $ = (id) => document.getElementById(id);
    const st = {
        attendanceId: null,
        meetingId: null,
        api: null,
        participants: [],
        offset: 0,
        ended: false,
        joined: false,
        me: { status: 'ABU', reason: 'Pemantauan belum aktif' },
        camera: { state: 'off', message: null },
        queue: Promise.resolve(),
        hbTimer: null,
        tickTimer: null,
        serverOk: true,
    };

    const serverNow = () => Date.now() / 1000 + st.offset;
    const stale = cfg.staleSeconds;

    // ------------------------------------------------------------ jaringan

    async function request(url, method = 'POST', body = null) {
        const res = await fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': cfg.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = null;
        try { json = await res.json(); } catch (_) { /* tanpa body JSON */ }
        return { ok: res.ok, status: res.status, json };
    }

    // --------------------------------------------------------------- UI

    function toast(text) {
        const box = $('toasts');
        if (!box) return;
        const t = el('div', 'px-4 py-2 bg-synlora-800 text-white text-sm rounded-lg shadow-lg', text);
        box.append(t);
        setTimeout(() => t.remove(), 4000);
    }

    function showMessage(id, text) {
        const e = $(id);
        if (!e) return;
        e.textContent = text || '';
        e.classList.toggle('hidden', !text);
    }

    function paintMe() {
        const s = STYLE[st.me.status] || STYLE.ABU;
        const box = $('my-status-card');
        if (box) box.className = `p-5 rounded-lg border-4 ${s.box}`;
        const t = $('my-status-title');
        if (t) { t.className = `font-bold text-xl ${s.text}`; t.textContent = `${s.icon} ${s.title}`; }
        const r = $('my-status-reason');
        if (r) { r.className = `text-sm mt-1 ${s.text}`; r.textContent = st.me.reason || ''; }

        const note = $('camera-note');
        if (note) {
            note.textContent = st.camera.message || '';
            note.classList.toggle('hidden', !st.camera.message);
        }
        const mine = st.participants.find((p) => p.user_id === cfg.myId);
        const dur = $('my-duration');
        if (dur && mine) dur.textContent = formatDuration(liveSeconds(mine, serverNow(), stale));
    }

    function paintParticipants() {
        paintMe();
        if (!cfg.isOwner) return;

        const list = $('participants-list');
        if (!list) return;
        const now = serverNow();
        list.replaceChildren();

        if (!st.participants.length) {
            list.append(el('p', 'text-sm text-synlora-500', 'Belum ada peserta.'));
        }

        st.participants.forEach((p) => {
            const conn = effectiveConnection(p, now, stale);
            const status = effectiveStatus(p, now, stale);
            const s = STYLE[status] || STYLE.ABU;

            const row = el('div', 'flex items-center gap-3 p-3 mb-2 border border-synlora-200 rounded-lg');
            row.dataset.userId = String(p.user_id);
            row.append(el('div', `w-3 h-3 shrink-0 rounded-full ${s.dot}`));

            const body = el('div', 'min-w-0 flex-1');
            const name = el('div', 'font-semibold text-synlora-900 truncate', p.name);
            if (p.is_owner) name.append(el('span', 'ml-1 text-xs text-synlora-500', '(owner)'));
            if (p.user_id === cfg.myId) name.append(el('span', 'ml-1 text-xs text-synlora-500', '(Anda)'));
            body.append(name);

            const reason = conn === 'online' ? p.reason
                : conn === 'disconnected' ? 'Koneksi terputus' : 'Sudah keluar dari meeting';
            body.append(el('div', `text-xs ${s.text}`, `${s.title}${reason ? ' · ' + reason : ''}`));
            if (p.join_count > 1) {
                body.append(el('div', 'text-xs text-synlora-500', `Bergabung ${p.join_count}× (termasuk sambung ulang)`));
            }
            row.append(body);

            const right = el('div', 'text-right shrink-0');
            right.append(el('div', 'font-mono text-sm text-synlora-800', formatDuration(liveSeconds(p, now, stale))));
            right.append(el('div', 'text-xs text-synlora-500',
                conn === 'online' ? 'hadir' : conn === 'disconnected' ? 'terputus' : 'keluar'));
            row.append(right);

            list.append(row);
        });

        const sum = summarize(st.participants, now, stale);
        const line = $('summary-counts');
        if (line) {
            line.textContent = `🟢 ${sum.HIJAU} fokus · 🔴 ${sum.MERAH} tidak fokus · ⚪ ${sum.ABU} tidak terpantau · ` +
                `⚠️ ${sum.disconnected} terputus · 🚪 ${sum.left} keluar`;
        }
    }

    function setMode(mode) {
        const e = $('conn-mode');
        if (!e) return;
        e.textContent = mode === 'websocket' ? '🟢 Real-time: WebSocket'
            : mode === 'polling' ? '🟡 Real-time: polling 2 detik (WebSocket tidak aktif)'
                : '⚪ Real-time berhenti';
    }

    function setServerOk(ok) {
        if (st.serverOk === ok) return;
        st.serverOk = ok;
        showMessage('server-warning', ok ? '' : 'Koneksi ke server SYNLORA terputus. Mencoba menyambung kembali…');
        if (ok) toast('Koneksi ke server pulih.');
    }

    // ----------------------------------------------------- sinyal fokus

    function enqueueSignal(status, reason, eventType = null, details = null) {
        if (!st.attendanceId || st.ended) return;
        st.queue = st.queue.then(() => doSignal(status, reason, eventType, details)).catch(() => {});
    }

    async function doSignal(status, reason, eventType, details) {
        if (st.ended) return;
        try {
            const body = { status, reason };
            if (eventType) body.event_type = eventType;
            if (details) body.details = details;
            const r = await request(cfg.urls.signal, 'POST', body);

            if (r.status === 409) return endLocal(r.json && r.json.reason === 'owner_ended' ? 'owner_ended' : 'meeting_ended');
            if (r.status === 403) return endLocal('removed');
            if (r.status === 401 || r.status === 419) {
                showMessage('server-warning', 'Sesi login berakhir. Muat ulang halaman dan login kembali.');
                return;
            }
            if (r.ok) {
                setServerOk(true);
                if (r.json && r.json.rejoined) {
                    st.attendanceId = r.json.attendance_id;
                    toast('Koneksi pulih: Anda bergabung kembali ke meeting.');
                }
            } else {
                setServerOk(false);
            }
        } catch (_) {
            setServerOk(false);
        }
    }

    // -------------------------------------------------------- snapshot/real-time

    function applySnapshot(s) {
        if (!s) return;
        st.offset = s.server_time - Date.now() / 1000;
        st.participants = s.participants || [];
        paintParticipants();
    }

    async function pollState() {
        if (st.ended) return;
        const r = await request(cfg.urls.state, 'GET');
        if (r.status === 403) return endLocal('removed');
        if (!r.ok || !r.json) return;
        if (r.json.meeting === null && st.attendanceId) return endLocal(r.json.ended_reason === 'owner_ended' ? 'owner_ended' : 'meeting_ended');
        applySnapshot(r.json);
    }

    function onRealtime(ev) {
        if (st.ended) return;
        if (ev.event === 'participant') {
            st.participants = applyParticipantEvent(st.participants, ev);
            if (ev.data.server_time) st.offset = ev.data.server_time - Date.now() / 1000;
            const p = ev.data.participant;
            if (cfg.isOwner && p.user_id !== cfg.myId) {
                if (ev.data.kind === 'joined') toast(`${p.name} bergabung`);
                if (ev.data.kind === 'rejoined') toast(`${p.name} bergabung kembali`);
                if (ev.data.kind === 'left') toast(`${p.name} keluar`);
            }
            paintParticipants();
        } else if (ev.event === 'meeting.ended') {
            endLocal(ev.data && ev.data.reason === 'owner_ended' ? 'owner_ended' : 'meeting_ended');
        } else if (ev.event === 'meeting.removed') {
            endLocal('removed');
        }
    }

    let channel = null;
    function startRealtime(initial) {
        let first = initial;
        channel = new RealtimeChannel({
            getInfo: async () => {
                if (first) { const t = first; first = null; return t; }
                const r = await request(cfg.urls.token, 'GET');
                return r.ok ? r.json : { enabled: false };
            },
            onEvent: onRealtime,
            poll: pollState,
            onMode: setMode,
            pollMs: 2000,
        });
        channel.start();
    }

    // ---------------------------------------------------------- monitor

    const monitor = new MeetingMonitor({
        video: $('camera-probe'),
        graceMs: cfg.faceGraceMs,
        mediapipe: cfg.mediapipe,
        onChange: ({ status, reason, eventType, details }) => {
            st.me = { status, reason };
            paintMe();
            enqueueSignal(status, reason, eventType, details);
        },
        onCamera: (state, message) => {
            st.camera = { state, message };
            paintMe();
        },
    });

    // ----------------------------------------------------------- Jitsi

    function createJitsi(j) {
        const options = {
            roomName: j.room_name,
            parentNode: $('jitsi-container'),
            width: '100%',
            height: '100%',
            userInfo: { displayName: j.display_name },
            configOverwrite: {
                prejoinConfig: { enabled: false },
                prejoinPageEnabled: false,
                disableDeepLinking: true,
                enableWelcomePage: false,
                enableClosePage: false,
                // Tidak ada perekaman / siaran: SYNLORA tidak merekam atau menyimpan video.
                fileRecordingsEnabled: false,
                liveStreamingEnabled: false,
                localRecording: { disable: true },
                disableInviteFunctions: true,
                toolbarButtons: TOOLBAR,
            },
            interfaceConfigOverwrite: {
                SHOW_JITSI_WATERMARK: false,
                SHOW_BRAND_WATERMARK: false,
                MOBILE_APP_PROMO: false,
            },
        };
        if (j.jwt) options.jwt = j.jwt;

        const api = new window.JitsiMeetExternalAPI(j.domain, options);
        st.api = api;

        api.addListener('videoConferenceJoined', () => {
            st.joined = true;
            $('joining-overlay')?.classList.add('hidden');
            monitor.start(); // monitoring aktif otomatis sejak peserta tersambung
            enqueueSignal(monitor.current.status, monitor.current.reason, 'JITSI_JOINED', 'Tersambung ke konferensi');
            updateCount();
        });
        api.addListener('videoConferenceLeft', () => finishByUser());
        api.addListener('readyToClose', () => finishByUser());
        api.addListener('participantJoined', updateCount);
        api.addListener('participantLeft', updateCount);
    }

    function updateCount() {
        try {
            const n = st.api.getNumberOfParticipants();
            const e = $('jitsi-count');
            if (e) e.textContent = `${n} peserta di ruang video`;
        } catch (_) { /* belum siap */ }
    }

    // ------------------------------------------------------- siklus hidup

    async function joinMeeting() {
        const btn = $('btn-join');
        btn.disabled = true;
        btn.classList.add('opacity-60');
        showMessage('prejoin-msg', '');

        const perm = await monitor.requestPermission();
        if (perm !== 'granted') {
            showMessage('prejoin-note', st.camera.message);
        }

        let data;
        try {
            const r = await request(cfg.urls.join);
            if (!r.ok) throw new Error(r.status === 403 ? 'Anda bukan anggota room ini.' : `Gagal bergabung (kode ${r.status}).`);
            data = r.json;
        } catch (err) {
            showMessage('prejoin-msg', err.message);
            btn.disabled = false;
            btn.classList.remove('opacity-60');
            return;
        }

        st.attendanceId = data.attendance_id;
        st.meetingId = data.meeting.id;

        $('prejoin').classList.add('hidden');
        $('stage').classList.remove('hidden');
        if (data.jitsi.public_instance) {
            showMessage('instance-warning',
                'Anda memakai server publik meet.jit.si (hanya untuk uji coba): meeting yang disematkan dibatasi ±5 menit. ' +
                'Untuk penggunaan nyata, atur JaaS atau server Jitsi sendiri (lihat panduan).');
        }
        applySnapshot(data.snapshot);

        try {
            await loadScript(data.jitsi.script_url);
            createJitsi(data.jitsi);
        } catch (err) {
            // Keluar lagi agar kehadiran tidak menggantung.
            await request(cfg.urls.leave, 'POST', { attendance_id: st.attendanceId }).catch(() => {});
            st.attendanceId = null;
            $('stage').classList.add('hidden');
            $('prejoin').classList.remove('hidden');
            showMessage('prejoin-msg', `${err.message} dari ${data.jitsi.domain}. Periksa koneksi internet atau JITSI_DOMAIN di .env.`);
            btn.disabled = false;
            btn.classList.remove('opacity-60');
            return;
        }

        startRealtime(data.realtime);
        paintMe();

        st.hbTimer = setInterval(() => enqueueSignal(st.me.status, st.me.reason), cfg.heartbeatSeconds * 1000);
        st.tickTimer = setInterval(paintParticipants, 1000);
    }

    function teardown() {
        st.ended = true;
        clearInterval(st.hbTimer);
        clearInterval(st.tickTimer);
        monitor.destroy();
        if (channel) channel.stop();
        try { st.api && st.api.dispose(); } catch (_) { /* abaikan */ }
        st.api = null;
    }

    /** Pengguna menutup panggilan di Jitsi. */
    async function finishByUser() {
        if (st.ended) return;
        const id = st.attendanceId;
        teardown();
        try {
            await request(cfg.urls.leave, 'POST', { attendance_id: id });
        } catch (_) { /* server akan menutup lewat deteksi terputus */ }
        window.location.assign(cfg.roomUrl);
    }

    /** Meeting berakhir / peserta dikeluarkan: hentikan monitoring dan tampilkan pemberitahuan. */
    function endLocal(reason) {
        if (st.ended) return;
        teardown();

        const text = {
            meeting_ended: 'Meeting telah berakhir. Pemantauan fokus dihentikan.',
            owner_ended: 'Owner room mengakhiri meeting untuk semua peserta. Pemantauan fokus dihentikan.',
            owner_ended_self: 'Anda mengakhiri meeting untuk semua peserta.',
            removed: 'Anda tidak lagi menjadi anggota room ini, sehingga meeting dihentikan.',
        }[reason] || 'Meeting berakhir.';

        $('stage')?.classList.add('hidden');
        $('prejoin')?.classList.add('hidden');
        $('ended-text').textContent = text;
        const link = $('ended-report');
        if (link && st.meetingId && reason !== 'removed') {
            link.href = cfg.urls.report.replace('__ID__', st.meetingId);
            link.classList.remove('hidden');
        }
        $('ended-notice').classList.remove('hidden');
    }

    // ------------------------------------------------------- tombol

    $('btn-join')?.addEventListener('click', joinMeeting);
    $('btn-leave-meeting')?.addEventListener('click', () => {
        if (window.confirm('Keluar dari meeting?')) finishByUser();
    });
    $('btn-end-all')?.addEventListener('click', async () => {
        if (!window.confirm('Akhiri meeting untuk SEMUA peserta?')) return;
        const r = await request(cfg.urls.end);
        if (r.ok) endLocal('owner_ended_self');
        else toast('Gagal mengakhiri meeting.');
    });

    // Tab ditutup / dimuat ulang: beri tahu server (kehadiran lama ditutup, bukan dianggap terputus).
    window.addEventListener('pagehide', () => {
        if (!st.attendanceId || st.ended) return;
        const fd = new FormData();
        fd.append('_token', cfg.csrf);
        fd.append('attendance_id', String(st.attendanceId));
        try { navigator.sendBeacon(cfg.urls.leave, fd); } catch (_) { /* abaikan */ }
    });

    paintMe();
}
