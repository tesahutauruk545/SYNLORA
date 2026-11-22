import { Room, RoomEvent, Track, LocalVideoTrack, ConnectionState } from 'livekit-client';
import { cameraHub } from './camera-hub.js';

/**
 * Study Meet: video meeting LiveKit yang tertanam di halaman room.
 * - Token dibuat server Laravel (API Key/Secret tidak pernah ada di browser).
 * - Kamera diambil dari cameraHub (satu sumber dengan MediaPipe), BUKAN lewat setCameraEnabled milik LiveKit,
 *   agar tidak ada getUserMedia video ganda. Mikrofon memakai pemintaan audio terpisah (tidak bentrok).
 * - Semua teks dari server dimasukkan lewat textContent (aman dari XSS).
 */
const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
};

export function init(cfg) {
    const $ = (id) => document.getElementById(id);
    const grid = $('meet-grid');
    if (!grid) return;

    const ui = {
        status: $('meet-status'),
        error: $('meet-error'),
        join: $('meet-join'),
        controls: $('meet-controls'),
        mic: $('meet-mic'),
        cam: $('meet-cam'),
        screen: $('meet-screen'),
        leave: $('meet-leave'),
        count: $('meet-count'),
        list: $('meet-participants'),
        empty: $('meet-empty'),
    };

    let room = null;
    let leaving = false;
    let cameraTrack = null; // LocalVideoTrack yang dipublikasikan dari cameraHub
    const tiles = new Map(); // key -> { root, video, label, icons }
    const audioEls = new Map();

    const setStatus = (t) => { ui.status.textContent = t; };
    const showError = (msg) => { ui.error.textContent = msg || ''; ui.error.classList.toggle('hidden', !msg); };
    const toggleBtn = (btn, on, onLabel, offLabel) => { btn.textContent = on ? onLabel : offLabel; btn.setAttribute('aria-pressed', on ? 'true' : 'false'); };

    // ------------------------------------------------------------------ tile
    function tileFor(key, name, isLocal, isScreen) {
        let t = tiles.get(key);
        if (t) return t;
        const root = el('div', 'relative aspect-video overflow-hidden rounded-2xl border border-white/10 bg-black/40');
        const video = el('video', `h-full w-full ${isScreen ? 'object-contain' : 'object-cover'} ${isLocal && !isScreen ? '[transform:scaleX(-1)]' : ''}`);
        video.autoplay = true; video.muted = true; video.playsInline = true;
        const ph = el('div', 'absolute inset-0 flex items-center justify-center text-3xl font-bold text-white/70', (name || '?').trim().charAt(0).toUpperCase());
        const bar = el('div', 'absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/70 to-transparent px-3 py-2 text-xs text-white');
        const label = el('span', 'truncate font-medium', `${isScreen ? '🖥️ Layar ' : ''}${name}${isLocal ? ' (Anda)' : ''}`);
        const icons = el('span', 'shrink-0');
        bar.append(label, icons);
        root.append(ph, video, bar);
        grid.append(root);
        t = { root, video, ph, icons };
        tiles.set(key, t);
        return t;
    }

    function dropTile(key) {
        const t = tiles.get(key);
        if (!t) return;
        t.root.remove();
        tiles.delete(key);
    }

    function pubOf(p, source) {
        return p.getTrackPublication(source);
    }

    // Render ulang satu partisipan: tile kamera, tile layar, ikon status.
    function renderParticipant(p) {
        const isLocal = p === room.localParticipant;
        const name = p.name || p.identity;
        const camPub = pubOf(p, Track.Source.Camera);
        const micPub = pubOf(p, Track.Source.Microphone);
        const scrPub = pubOf(p, Track.Source.ScreenShare);

        const t = tileFor(p.identity, name, isLocal, false);
        const camOn = !!camPub && !camPub.isMuted && !!camPub.track;
        const micOn = !!micPub && !micPub.isMuted;
        if (camOn) {
            camPub.track.attach(t.video);
            t.ph.classList.add('hidden');
            t.video.classList.remove('hidden');
        } else {
            t.video.classList.add('hidden');
            t.ph.classList.remove('hidden');
        }
        t.icons.textContent = `${camOn ? '📹' : '🚫'} ${micOn ? '🎙️' : '🔇'}`;
        t.icons.title = `Kamera ${camOn ? 'aktif' : 'mati'}, mikrofon ${micOn ? 'aktif' : 'mati'}`;

        const sKey = `${p.identity}#screen`;
        if (scrPub && scrPub.track && !scrPub.isMuted) {
            const st = tileFor(sKey, name, isLocal, true);
            scrPub.track.attach(st.video);
            st.ph.classList.add('hidden');
        } else {
            dropTile(sKey);
        }
        return { camOn, micOn, sharing: !!(scrPub && scrPub.track) };
    }

    function renderAll() {
        if (!room) return;
        const parts = [room.localParticipant, ...room.remoteParticipants.values()];
        const seen = new Set();
        const rows = [];
        parts.forEach((p) => {
            const r = renderParticipant(p);
            seen.add(p.identity); seen.add(`${p.identity}#screen`);
            rows.push({ p, ...r });
        });
        [...tiles.keys()].forEach((k) => { if (!seen.has(k)) dropTile(k); });

        ui.count.textContent = String(parts.length);
        ui.list.replaceChildren(...rows.map(({ p, camOn, micOn, sharing }) => {
            const li = el('li', 'flex items-center justify-between gap-2 text-sm');
            li.append(
                el('span', 'min-w-0 truncate', `${p.name || p.identity}${p === room.localParticipant ? ' (Anda)' : ''}`),
                el('span', 'shrink-0 text-xs', `${camOn ? '📹' : '🚫'} ${micOn ? '🎙️' : '🔇'}${sharing ? ' 🖥️' : ''}`),
            );
            return li;
        }));
        ui.empty.classList.toggle('hidden', parts.length > 1);
    }

    // -------------------------------------------------------------- audio
    function attachAudio(track, participant) {
        const key = `${participant.identity}:${track.sid}`;
        if (audioEls.has(key)) return;
        const a = track.attach();
        a.classList.add('hidden');
        document.body.append(a);
        audioEls.set(key, a);
    }
    function detachAudio(track, participant) {
        const key = `${participant.identity}:${track.sid}`;
        const a = audioEls.get(key);
        if (a) { track.detach(a); a.remove(); audioEls.delete(key); }
    }

    // ------------------------------------------------------------- kamera
    async function cameraOn() {
        if (!room || cameraTrack) return;
        showError('');
        let stream;
        try {
            stream = await cameraHub.acquire('meeting');
        } catch (err) {
            const n = err && err.name;
            showError(n === 'NotAllowedError' || n === 'PermissionDeniedError'
                ? 'Izin kamera ditolak. Izinkan kamera pada pengaturan browser, lalu klik Kamera lagi. Anda tetap dapat ikut meeting tanpa kamera.'
                : n === 'NotFoundError' ? 'Kamera tidak ditemukan pada perangkat ini.'
                : 'Kamera tidak dapat dibuka (mungkin dipakai aplikasi lain).');
            return;
        }
        const mst = stream.getVideoTracks()[0];
        // userProvidedTrack=true: LiveKit tidak menghentikan/membuka ulang track; pemiliknya cameraHub.
        cameraTrack = new LocalVideoTrack(mst, undefined, true);
        mst.addEventListener('ended', onCameraEnded);
        try {
            await room.localParticipant.publishTrack(cameraTrack, { source: Track.Source.Camera, name: 'camera' });
        } catch (err) {
            await cameraOff();
            showError('Gagal mengirim video ke meeting. Coba lagi.');
            return;
        }
        toggleBtn(ui.cam, true, '📹 Kamera: Aktif', '🚫 Kamera: Mati');
        renderAll();
    }

    async function cameraOff() {
        if (cameraTrack) {
            const t = cameraTrack;
            cameraTrack = null;
            t.mediaStreamTrack.removeEventListener('ended', onCameraEnded);
            try { await room?.localParticipant.unpublishTrack(t, false); } catch (_) { /* sudah terputus */ }
        }
        // Lepas bagian meeting; Face Monitoring (jika aktif) tetap memegang kamera yang sama.
        cameraHub.release('meeting');
        toggleBtn(ui.cam, false, '📹 Kamera: Aktif', '🚫 Kamera: Mati');
        renderAll();
    }

    function onCameraEnded() {
        showError('Kamera terputus (dicabut, ditutup, atau dipakai aplikasi lain). Klik Kamera untuk menyalakan lagi.');
        cameraOff();
    }

    // -------------------------------------------------------------- koneksi
    async function fetchToken() {
        const res = await fetch(cfg.tokenUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            const err = new Error(body.message || `HTTP ${res.status}`);
            err.status = res.status;
            throw err;
        }
        return body;
    }

    async function join() {
        showError('');
        ui.join.disabled = true;
        setStatus('Menyambungkan…');
        let creds;
        try {
            creds = await fetchToken();
        } catch (err) {
            ui.join.disabled = false;
            setStatus('Belum tersambung');
            showError(err.status === 503
                ? 'Study Meet belum dikonfigurasi di server. Minta administrator mengisi LIVEKIT_URL, LIVEKIT_API_KEY, dan LIVEKIT_API_SECRET di .env.'
                : err.status === 403 ? 'Hanya anggota room ini yang dapat masuk meeting.'
                : err.status === 401 || err.status === 419 ? 'Sesi login berakhir. Muat ulang halaman lalu masuk lagi.'
                : 'Tidak dapat mengambil akses meeting. Periksa koneksi lalu coba lagi.');
            return;
        }

        leaving = false;
        room = new Room({ adaptiveStream: true, dynacast: true });
        bind(room);
        try {
            await room.connect(creds.url, creds.token);
            await room.startAudio().catch(() => {});
        } catch (err) {
            room = null;
            ui.join.disabled = false;
            setStatus('Belum tersambung');
            showError('Gagal tersambung ke server meeting. Periksa koneksi internet lalu coba lagi.');
            return;
        }

        ui.join.classList.add('hidden');
        ui.controls.classList.remove('hidden');
        ui.join.disabled = false;
        setStatus('Tersambung ke Study Meet');
        toggleBtn(ui.mic, false, '🎙️ Mikrofon: Aktif', '🔇 Mikrofon: Mati');
        toggleBtn(ui.cam, false, '📹 Kamera: Aktif', '🚫 Kamera: Mati');
        toggleBtn(ui.screen, false, '🖥️ Berbagi layar: Aktif', '🖥️ Berbagi layar');
        renderAll();
    }

    function bind(r) {
        const rerender = () => renderAll();
        r.on(RoomEvent.ParticipantConnected, rerender)
            .on(RoomEvent.ParticipantDisconnected, rerender)
            .on(RoomEvent.TrackPublished, rerender)
            .on(RoomEvent.TrackUnpublished, rerender)
            .on(RoomEvent.TrackMuted, rerender)
            .on(RoomEvent.TrackUnmuted, rerender)
            .on(RoomEvent.LocalTrackPublished, rerender)
            .on(RoomEvent.LocalTrackUnpublished, rerender)
            .on(RoomEvent.TrackSubscribed, (track, pub, p) => { if (track.kind === Track.Kind.Audio) attachAudio(track, p); renderAll(); })
            .on(RoomEvent.TrackUnsubscribed, (track, pub, p) => { if (track.kind === Track.Kind.Audio) detachAudio(track, p); renderAll(); })
            .on(RoomEvent.AudioPlaybackStatusChanged, () => {
                if (!r.canPlaybackAudio) showError('Browser memblokir suara meeting. Klik di mana saja pada halaman untuk mengaktifkan suara.');
            })
            .on(RoomEvent.Reconnecting, () => setStatus('Koneksi terputus, menyambung ulang…'))
            .on(RoomEvent.Reconnected, () => { setStatus('Tersambung ke Study Meet'); showError(''); renderAll(); })
            .on(RoomEvent.MediaDevicesError, (e) => showError(`Perangkat media bermasalah: ${(e && e.message) || 'tidak diketahui'}`))
            .on(RoomEvent.Disconnected, () => {
                const unexpected = !leaving;
                teardown();
                if (unexpected) {
                    setStatus('Terputus dari meeting');
                    showError('Koneksi meeting terputus. Klik "Sambung Ulang" untuk masuk lagi.');
                    ui.join.textContent = '🔄 Sambung Ulang';
                }
            });
    }

    function teardown() {
        if (cameraTrack) {
            cameraTrack.mediaStreamTrack.removeEventListener('ended', onCameraEnded);
            cameraTrack = null;
        }
        cameraHub.release('meeting'); // Face Monitoring tidak terpengaruh; kamera fisik mati bila tak ada pemakai lain
        audioEls.forEach((a) => a.remove());
        audioEls.clear();
        [...tiles.keys()].forEach(dropTile);
        ui.list.replaceChildren();
        ui.count.textContent = '0';
        ui.empty.classList.remove('hidden');
        ui.controls.classList.add('hidden');
        ui.join.classList.remove('hidden');
        ui.join.disabled = false;
        room = null;
    }

    async function leave() {
        if (!room) return;
        leaving = true;
        const r = room;
        await cameraOff();
        try { await r.disconnect(); } catch (_) { /* sudah terputus */ }
        if (room) teardown();
        ui.join.textContent = '▶️ Gabung Meeting';
        setStatus('Anda keluar dari meeting');
    }

    // --------------------------------------------------------------- kontrol
    ui.join.addEventListener('click', join);
    ui.leave.addEventListener('click', leave);

    ui.mic.addEventListener('click', async () => {
        if (!room) return;
        const want = !room.localParticipant.isMicrophoneEnabled;
        try {
            await room.localParticipant.setMicrophoneEnabled(want);
            showError('');
        } catch (err) {
            showError(err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')
                ? 'Izin mikrofon ditolak. Izinkan mikrofon pada pengaturan browser untuk situs ini.'
                : 'Mikrofon tidak dapat dibuka.');
            return;
        }
        toggleBtn(ui.mic, room.localParticipant.isMicrophoneEnabled, '🎙️ Mikrofon: Aktif', '🔇 Mikrofon: Mati');
        renderAll();
    });

    ui.cam.addEventListener('click', () => { if (cameraTrack) cameraOff(); else cameraOn(); });

    ui.screen.addEventListener('click', async () => {
        if (!room) return;
        const want = !room.localParticipant.isScreenShareEnabled;
        try {
            await room.localParticipant.setScreenShareEnabled(want);
            showError('');
        } catch (err) {
            if (!(err && err.name === 'NotAllowedError')) showError('Berbagi layar tidak dapat dimulai di browser ini.');
        }
        toggleBtn(ui.screen, room.localParticipant.isScreenShareEnabled, '🖥️ Berbagi layar: Aktif', '🖥️ Berbagi layar');
        renderAll();
    });

    // Layar dibagikan lalu dihentikan lewat tombol browser: sinkronkan tombol.
    document.addEventListener('visibilitychange', () => { if (room && room.state === ConnectionState.Connected) renderAll(); });
    window.addEventListener('beforeunload', () => { if (room) { leaving = true; room.disconnect(); } });
}
