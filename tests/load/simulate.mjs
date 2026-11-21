// Simulasi N mahasiswa + 1 dosen secara bersamaan terhadap server HTTP sungguhan.
// Jalankan: BASE=http://127.0.0.1:8000 node tests/load/simulate.mjs
// Laju permintaan sengaja ±5x LEBIH TINGGI daripada browser sungguhan (heartbeat 3s, feed 4s, state 6s).
const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const N = Number(process.env.N || 40);
const DURATION = Number(process.env.DURATION || 60) * 1000;
const ROOM = process.env.ROOM || 'LOAD01';
const PASSWORD = 'password123';
// RATE=real  : laju browser sungguhan (heartbeat 15 dtk, state 20 dtk, feed 4 dtk)
// RATE=stress: ±5x lebih tinggi (heartbeat 3 dtk, state 6 dtk, feed 4 dtk)
const REAL = (process.env.RATE || 'real') === 'real';
const HB = REAL ? 15000 : 3000;
const STATE = REAL ? 20000 : 6000;
const STAGGER = Number(process.env.STAGGER || 120);

class Client {
    constructor() { this.cookies = new Map(); }
    jar() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }
    store(res) {
        for (const c of res.headers.getSetCookie?.() ?? []) {
            const [pair] = c.split(';');
            const i = pair.indexOf('=');
            this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
        }
    }
    async req(method, path, { json, form, headers = {} } = {}) {
        const h = { Cookie: this.jar(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...headers };
        let body;
        if (json) { body = JSON.stringify(json); h['Content-Type'] = 'application/json'; }
        if (form) { body = new URLSearchParams(form).toString(); h['Content-Type'] = 'application/x-www-form-urlencoded'; }
        const xsrf = this.cookies.get('XSRF-TOKEN');
        if (xsrf) h['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
        const t0 = performance.now();
        const res = await fetch(BASE + path, { method, headers: h, body, redirect: 'manual' });
        const ms = performance.now() - t0;
        this.store(res);
        return { res, ms };
    }
}

const stats = {};
const errors = [];
function rec(name, r, okStatuses = [200, 201, 204, 302]) {
    (stats[name] ??= { ms: [], n: 0, bad: 0, s429: 0 });
    const s = stats[name];
    s.n++; s.ms.push(r.ms);
    if (r.res.status === 429) s.s429++;
    if (!okStatuses.includes(r.res.status)) { s.bad++; if (errors.length < 15) errors.push(`${name} -> HTTP ${r.res.status}`); }
}
const pct = (a, p) => { const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(s.length * p))] ?? 0; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(c, email) {
    let r = await c.req('GET', '/login', { headers: { Accept: 'text/html' } });
    const html = await r.res.text();
    const token = /name="_token" value="([^"]+)"/.exec(html)?.[1];
    r = await c.req('POST', '/login', { form: { _token: token, email, password: PASSWORD }, headers: { Accept: 'text/html' } });
    rec('login', r);
    return r.res.status === 302;
}

// Skenario per mahasiswa, proporsional terhadap N
function scenario(i) {
    const f = i / N;
    if (f < 0.25) return 'HIJAU';
    if (f < 0.45) return 'KUNING';
    if (f < 0.60) return 'MERAH';
    if (f < 0.75) return 'JEDA';
    if (f < 0.875) return 'PUTUS';
    return 'HIJAU';
}

async function student(i, t0, done) {
    const c = new Client();
    const email = `mhs${String(i + 1).padStart(2, '0')}.load@example.com`;
    if (!(await login(c, email))) { errors.push(`login gagal ${email}`); return; }
    let r = await c.req('POST', `/rooms/${ROOM}/sessions`, { json: { break_minutes: 5 } });
    rec('start-sesi', r);
    const info = await r.res.json().catch(() => null);
    if (!info?.urls) { errors.push(`sesi gagal ${email}: ${r.res.status}`); return; }
    const urls = Object.fromEntries(Object.entries(info.urls).map(([k, v]) => [k, new URL(v).pathname]));
    const send = async (type, status, source, details) => rec('event', await c.req('POST', urls.events, { json: { event_type: type, status, source, details } }));
    await send('MONITOR_START', 'HIJAU', 'browser', 'Tab aktif');

    const kind = scenario(i);
    let act = false;
    const timers = [];
    const every = (ms, fn) => timers.push(setInterval(async () => { try { await fn(); } catch (e) { errors.push(String(e)); } }, ms + Math.random() * 400));

    if (kind !== 'PUTUS') {
        every(HB, async () => rec('heartbeat', await c.req('POST', urls.heartbeat)));
        every(STATE, async () => rec('state', await c.req('GET', urls.state)));
    }
    every(4000, async () => { if (kind !== 'PUTUS' || !act) rec('feed-room', await c.req('GET', `/rooms/${ROOM}/activities`)); });

    await sleep(8000 + Math.random() * 2000);
    if (kind === 'KUNING') await send('FACE_LOST_SHORT', 'KUNING', 'face', 'Wajah tidak terdeteksi 6 detik');
    if (kind === 'MERAH') { await send('TAB_SWITCH_OUT', 'KUNING', 'browser', 'Tab ditinggalkan'); await sleep(1000); await send('TAB_AWAY_LONG', 'MERAH', 'browser', 'Tab ditinggalkan 10 detik'); }
    if (kind === 'JEDA') rec('pause', await c.req('POST', urls.pause));
    if (kind === 'PUTUS') { act = true; timers.forEach(clearInterval); }   // browser "tertutup": tidak ada detak lagi

    await sleep(Math.max(0, DURATION - (performance.now() - t0)));
    timers.forEach(clearInterval);
    done.push({ i, kind });
}

async function dosen(t0, snapshots) {
    const c = new Client();
    if (!(await login(c, 'dosen.load@example.com'))) { errors.push('login dosen gagal'); return; }
    const tick = async () => {
        const r = await c.req('GET', '/dosen/data');
        rec('dosen-data', r);
        if (r.res.status === 200) snapshots.push({ t: (performance.now() - t0) / 1000, ...(await r.res.json()) });
    };
    await tick();
    const id = setInterval(() => tick().catch((e) => errors.push(String(e))), 5000);
    await sleep(DURATION + 500);
    clearInterval(id);
    await tick();
}

const t0 = performance.now();
const done = [];
const snapshots = [];
console.log(`Simulasi ${N} mahasiswa + 1 dosen selama ${DURATION / 1000} detik, laju ${REAL ? 'nyata' : 'stres'}, ke ${BASE}`);
await Promise.all([dosen(t0, snapshots), ...Array.from({ length: N }, (_, i) => sleep(i * STAGGER).then(() => student(i, t0, done)))]);

console.log('\n=== Latensi (ms) ===');
console.log('endpoint'.padEnd(14), 'n'.padStart(6), 'p50'.padStart(7), 'p95'.padStart(7), 'maks'.padStart(7), 'gagal'.padStart(6), '429'.padStart(5));
for (const [k, s] of Object.entries(stats)) {
    console.log(k.padEnd(14), String(s.n).padStart(6), pct(s.ms, .5).toFixed(0).padStart(7), pct(s.ms, .95).toFixed(0).padStart(7), Math.max(...s.ms).toFixed(0).padStart(7), String(s.bad).padStart(6), String(s.s429).padStart(5));
}
const last = snapshots.at(-1);
const mid = snapshots.filter((s) => s.t > DURATION / 1000 - 12).at(0) ?? last;
console.log('\n=== Snapshot dosen mendekati akhir (t=' + mid?.t?.toFixed(0) + 's) ===');
const mine = (mid?.students ?? []).filter((x) => Number(String(x.nim).slice(4)) <= N);
const got = { total: mine.length, HIJAU: 0, KUNING: 0, MERAH: 0, ABU: 0, DISCONNECTED: 0, UNKNOWN: 0 };
mine.forEach((x) => { got[x.status] = (got[x.status] ?? 0) + 1; });
console.log(JSON.stringify(got));
const kinds = Array.from({ length: N }, (_, i) => scenario(i));
const cnt = (k) => kinds.filter((x) => x === k).length;
const expected = { total: N, HIJAU: cnt('HIJAU'), KUNING: cnt('KUNING'), MERAH: cnt('MERAH'), ABU: cnt('JEDA'), DISCONNECTED: cnt('PUTUS') };
const ok = mid && Object.entries(expected).every(([k, v]) => got[k] === v);
console.log('Distribusi sesuai harapan', JSON.stringify(expected), '=>', ok ? 'YA' : 'TIDAK');
console.log('Mahasiswa menyelesaikan skenario:', done.length, '/', N);
if (errors.length) console.log('\nContoh galat:', errors.slice(0, 10));
process.exit(ok && !errors.length && Object.values(stats).every((s) => s.bad === 0) ? 0 : 1);
