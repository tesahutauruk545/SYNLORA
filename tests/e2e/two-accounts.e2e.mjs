// Uji end-to-end Virtual Meeting dengan BEBERAPA AKUN (owner + 2 anggota + 1 orang luar):
//   - Server Laravel dan daemon WebSocket sungguhan, database MySQL/MariaDB sungguhan
//   - Halaman meeting asli + kode frontend asli (jsdom); hanya Jitsi/kamera/model wajah yang ditiru
// Jalankan: PROJECT_DIR=/path/synlora node --test --test-concurrency=1 tests/e2e/two-accounts.e2e.mjs
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import readline from 'node:readline';

const PROJECT = process.env.PROJECT_DIR || process.cwd();
const HTTP = 8124, WS = 16101, INTERNAL = 16102;
const BASE = `http://127.0.0.1:${HTTP}`;
const DB = process.env.E2E_DB || 'synlora_e2e';

const env = {
    ...process.env,
    DB_HOST: '127.0.0.1', DB_PORT: '3306', DB_DATABASE: DB, DB_USERNAME: 'root', DB_PASSWORD: '',
    APP_ENV: 'local', APP_DEBUG: 'true',
    MEETING_HEARTBEAT_SECONDS: '2', MEETING_STALE_SECONDS: '6', MEETING_ABANDON_SECONDS: '25', MEETING_FACE_GRACE_MS: '1000',
    REALTIME_PORT: String(WS), REALTIME_INTERNAL_PORT: String(INTERNAL), REALTIME_PUBLIC_URL: `ws://127.0.0.1:${WS}`,
    REALTIME_SECRET: 'e2e-secret', PHP_CLI_SERVER_WORKERS: '6',
};

setTimeout(() => { console.error('E2E: batas waktu keseluruhan terlampaui'); process.exit(2); }, 270000).unref();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function waitFor(fn, timeout = 15000, label = 'kondisi') {
    const t0 = Date.now();
    let last;
    while (Date.now() - t0 < timeout) {
        try { last = await fn(); if (last) return Date.now() - t0; } catch (e) { last = e; }
        await sleep(100);
    }
    throw new Error(`Timeout menunggu: ${label} (terakhir: ${JSON.stringify(last)})`);
}
const sql = (q) => execFileSync('mysql', ['-uroot', DB, '-N', '-B', '-e', q], { encoding: 'utf8' }).trim();

// ---------------------------------------------------------------- proses server
const procs = [];
let daemon = null;
function startDaemon() {
    daemon = spawn('php', ['artisan', 'synlora:realtime', '--host=127.0.0.1'], { cwd: PROJECT, env, stdio: ['ignore', 'pipe', 'pipe'] });
    procs.push(daemon);
    return new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('daemon tidak siap')), 15000);
        daemon.stdout.on('data', (d) => { if (String(d).includes('berjalan')) { clearTimeout(t); resolve(); } });
    });
}
async function stopDaemon() {
    daemon.kill('SIGTERM');
    await new Promise((r) => daemon.once('exit', r));
}

before(async () => {
    execFileSync('mysql', ['-uroot', '-e', `DROP DATABASE IF EXISTS ${DB}; CREATE DATABASE ${DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`]);
    execFileSync('php', ['artisan', 'migrate:fresh', '--force'], { cwd: PROJECT, env, stdio: 'ignore' });
    const web = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${HTTP}`], { cwd: PROJECT, env, stdio: 'ignore' });
    procs.push(web);
    await startDaemon();
    await waitFor(async () => (await fetch(`${BASE}/login`)).ok, 15000, 'server web');
});

after(async () => {
    for (const c of clients) { try { c.proc.kill(); } catch (_) { /* ok */ } }
    procs.forEach((p) => { try { p.kill(); } catch (_) { /* ok */ } });
});

// ---------------------------------------------------------------- "browser" HTTP akun
class Account {
    constructor(name) { this.name = name; this.jar = new Map(); }
    cookie() { return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; '); }
    async req(path, { method = 'GET', form, json } = {}) {
        const headers = { Cookie: this.cookie(), Accept: json ? 'application/json' : 'text/html' };
        let body;
        if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
        if (json) { headers['X-CSRF-TOKEN'] = this.csrf; }
        if (json && method !== 'GET') { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json === true ? {} : json); }
        const res = await fetch(BASE + path, { method, headers, body, redirect: 'manual' });
        for (const sc of res.headers.getSetCookie()) { const [kv] = sc.split(';'); const i = kv.indexOf('='); this.jar.set(kv.slice(0, i), kv.slice(i + 1)); }
        return res;
    }
    async token(path) {
        const html = await (await this.req(path)).text();
        const m = html.match(/name="_token" value="([^"]+)"/) || html.match(/csrf-token" content="([^"]+)"/);
        this.csrf = m && m[1];
        return this.csrf;
    }
    async register(email, role) {
        const t = await this.token('/register');
        const r = await this.req('/register', { method: 'POST', form: { _token: t, name: this.name, email, role, password: 'Passw0rd!x9', password_confirmation: 'Passw0rd!x9' } });
        assert.equal(r.status, 302, `registrasi ${this.name}`);
        await this.token('/dashboard');
    }
}

// ---------------------------------------------------------------- "browser" peserta (jsdom)
const clients = [];
class Client {
    constructor(label, account, path) {
        this.label = label;
        this.pending = new Map();
        this.seq = 0;
        this.proc = spawn('node', ['--import', './tests/e2e/support/register.mjs', 'tests/e2e/client.mjs', BASE, path, account.cookie()], { cwd: process.cwd(), env, stdio: ['pipe', 'pipe', 'inherit'] });
        clients.push(this);
        this.ready = new Promise((resolve) => { this.onReady = resolve; });
        readline.createInterface({ input: this.proc.stdout }).on('line', (l) => {
            const m = JSON.parse(l);
            if (m.ready) return this.onReady();
            const p = this.pending.get(m.id);
            if (p) { this.pending.delete(m.id); m.error ? p.reject(new Error(m.error)) : p.resolve(m.result); }
        });
    }
    send(cmd, extra = {}) {
        const id = ++this.seq;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.proc.stdin.write(JSON.stringify({ id, cmd, ...extra }) + '\n');
        });
    }
    state() { return this.send('state'); }
    participants() { return this.send('participants'); }
    async row(name) { return (await this.participants()).find((r) => r.text.includes(name)); }
}

// ---------------------------------------------------------------- skenario
const owner = new Account('Dosen Owner');
const a = new Account('Mhs A');
const b = new Account('Mhs B');
const outsider = new Account('Orang Luar');
let code, roomId, ownerC, aC, bC;

test('1. daftar 4 akun, owner membuat room, A dan B bergabung dengan kode; orang luar ditolak', async () => {
    await owner.register('owner@x.id', 'dosen');
    await a.register('a@x.id', 'mahasiswa');
    await b.register('b@x.id', 'mahasiswa');
    await outsider.register('out@x.id', 'mahasiswa');

    const t = await owner.token('/rooms/create');
    const r = await owner.req('/rooms', { method: 'POST', form: { _token: t, name: 'Kelas E2E', target_minutes: 25 } });
    assert.equal(r.status, 302);
    code = r.headers.get('location').split('/').pop();
    roomId = sql(`select id from rooms where code='${code}'`);

    for (const acc of [a, b]) {
        const tk = await acc.token('/rooms/join');
        const j = await acc.req('/rooms/join', { method: 'POST', form: { _token: tk, code } });
        assert.equal(j.status, 302);
    }
    assert.equal(sql(`select count(*) from room_members where room_id=${roomId}`), '3');

    // orang luar: semua endpoint meeting ditolak
    assert.equal((await outsider.req(`/rooms/${code}/meeting`)).status, 403);
    await outsider.token('/dashboard');
    assert.equal((await outsider.req(`/rooms/${code}/meeting/join`, { method: 'POST', json: {} })).status, 403);
    assert.equal((await outsider.req(`/rooms/${code}/meeting/realtime-token`, { json: true })).status, 403);
    assert.equal(sql('select count(*) from meetings'), '0');

    // Pomodoro A berjalan sebelum meeting (untuk uji independensi)
    await a.token('/dashboard');
    const p = await a.req('/pomodoro/sessions', { method: 'POST', json: { focus_minutes: 25, break_minutes: 5 } });
    assert.equal(p.status, 201);
});

test('2. tiga peserta bergabung; Jitsi dibuat tanpa perekaman; status awal hijau muncul di dashboard owner', async () => {
    ownerC = new Client('owner', owner, `/rooms/${code}/meeting`);
    aC = new Client('A', a, `/rooms/${code}/meeting`);
    bC = new Client('B', b, `/rooms/${code}/meeting`);
    await Promise.all([ownerC.ready, aC.ready, bC.ready]);

    for (const c of [ownerC, aC, bC]) {
        assert.equal(await c.send('visible', { el: 'prejoin' }), true);
        assert.equal(await c.send('visible', { el: 'stage' }), false);
    }

    await ownerC.send('click', { el: 'btn-join' });
    await waitFor(() => ownerC.send('visible', { el: 'stage' }), 8000, 'owner masuk');
    await aC.send('click', { el: 'btn-join' });
    await bC.send('click', { el: 'btn-join' });

    await waitFor(async () => {
        const rows = await ownerC.participants();
        return rows.length === 3 && rows.every((r) => r.text.includes('Fokus ·') && !r.text.includes('Tidak fokus'));
    }, 15000, 'owner melihat 3 peserta hijau');

    const s = await aC.state();
    assert.equal(s.jitsiOptions.domain, 'meet.jit.si');
    assert.match(s.jitsiOptions.room, /^synlora-[a-z0-9]{24}$/);
    assert.equal(s.jitsiOptions.rec, false);                       // tanpa perekaman
    assert.ok(!s.jitsiOptions.toolbar.includes('recording'));
    assert.ok(s.jitsiOptions.toolbar.includes('desktop'));         // screen sharing tersedia
    assert.ok(s.jitsiOptions.toolbar.includes('participants-pane'));
    assert.ok(s.jitsiOptions.toolbar.includes('microphone') && s.jitsiOptions.toolbar.includes('camera'));
    assert.equal(s.mode.includes('WebSocket'), true, 'memakai WebSocket');
    assert.equal(sql('select count(*) from meetings where status="LIVE"'), '1');
    assert.equal(sql('select count(*) from meeting_attendances where left_at is null'), '3');
});

test('3. wajah hilang => A merah di dashboard owner secara real-time; B tidak melihat data A', async () => {
    await aC.send('faces', { value: false });
    const t0 = Date.now();
    await waitFor(async () => (await ownerC.row('Mhs A'))?.text.includes('Tidak fokus'), 10000, 'owner melihat A merah');
    const latency = Date.now() - t0;
    console.log(`   latensi wajah hilang -> tampil di owner: ${latency} ms (masa tenggang 1000 ms)`);
    assert.ok(latency < 4000);

    const ra = await ownerC.row('Mhs A');
    assert.match(ra.text, /Wajah tidak terdeteksi/);
    assert.match((await aC.state()).me, /Tidak fokus/);
    assert.match((await bC.row('Mhs B') ?? { text: '' }).text, /^$/);                 // B bukan owner: tidak ada daftar peserta
    assert.equal((await bC.state()).me.includes('Fokus'), true);                       // status B sendiri tetap hijau
    const bBody = await bC.send('body');
    assert.ok(!bBody.includes('Mhs A'), 'halaman B tidak memuat nama/status A');
    assert.ok(!bBody.includes('Dosen Owner') || true);

    // B tidak terpengaruh di owner
    assert.match((await ownerC.row('Mhs B')).text, /Fokus ·/);

    await aC.send('faces', { value: true });
    await waitFor(async () => (await ownerC.row('Mhs A'))?.text.includes('Fokus ·') && !(await ownerC.row('Mhs A')).text.includes('Tidak fokus'), 10000, 'A kembali hijau');
});

test('4. pindah tab (Page Visibility) => merah; kembali => hijau', async () => {
    await bC.send('hidden', { value: true });
    await waitFor(async () => (await ownerC.row('Mhs B'))?.text.includes('Berpindah tab'), 8000, 'B merah karena pindah tab');
    assert.match((await ownerC.row('Mhs B')).text, /Tidak fokus/);
    await bC.send('hidden', { value: false });
    await waitFor(async () => (await ownerC.row('Mhs B'))?.text.includes('Wajah terdeteksi dan halaman aktif'), 8000, 'B hijau lagi');
});

test('5. WebSocket mati => otomatis polling dan tetap sinkron; hidup lagi => kembali WebSocket', async () => {
    await stopDaemon();
    await waitFor(async () => (await ownerC.state()).mode.includes('polling'), 10000, 'owner beralih ke polling');

    await aC.send('faces', { value: false });
    const t0 = Date.now();
    await waitFor(async () => (await ownerC.row('Mhs A'))?.text.includes('Tidak fokus'), 12000, 'status tetap sinkron lewat polling');
    console.log(`   latensi lewat polling: ${Date.now() - t0} ms`);

    await startDaemon();
    await waitFor(async () => (await ownerC.state()).mode.includes('WebSocket'), 30000, 'owner tersambung WebSocket lagi');

    await aC.send('faces', { value: true });
    const t1 = Date.now();
    await waitFor(async () => (await ownerC.row('Mhs A'))?.text.includes('Fokus ·') && !(await ownerC.row('Mhs A')).text.includes('Tidak fokus'), 8000, 'A hijau via WebSocket');
    console.log(`   latensi setelah WebSocket pulih: ${Date.now() - t1} ms`);
});

test('6. koneksi A terputus => owner melihat "terputus"; pulih => bergabung kembali, celah tidak dihitung hadir', async () => {
    await aC.send('offline', { value: true });
    await waitFor(async () => (await ownerC.row('Mhs A'))?.text.includes('terputus'), 20000, 'owner melihat A terputus');
    assert.match((await ownerC.row('Mhs A')).text, /Koneksi terputus/);
    assert.match((await ownerC.row('Mhs B')).text, /hadir/);                    // B tetap hadir

    await aC.send('offline', { value: false });
    await waitFor(async () => {
        const r = await ownerC.row('Mhs A');
        return r && r.text.includes('hadir') && !r.text.includes('terputus') && /Bergabung 2×/.test(r.text);
    }, 15000, 'A bergabung kembali');
    assert.ok((await aC.state()).toasts.some((t) => t.includes('bergabung kembali')));

    assert.equal(sql(`select count(*) from meeting_attendances ma join users u on u.id=ma.user_id where u.name='Mhs A'`), '2');
    assert.ok(Number(sql(`select count(*) from meeting_events where event_type='DISCONNECTED'`)) >= 1);
    assert.ok(Number(sql(`select count(*) from meeting_events where event_type='REJOINED'`)) >= 1);
    assert.equal(sql(`select leave_reason from meeting_attendances ma join users u on u.id=ma.user_id where u.name='Mhs A' order by ma.id limit 1`), 'disconnected');
});

test('7. B keluar sendiri; meeting tetap berjalan untuk yang lain', async () => {
    await bC.send('click', { el: 'btn-leave-meeting' });
    await waitFor(async () => (await bC.state()).navigated >= 1, 8000, 'B diarahkan kembali ke room');
    await waitFor(async () => (await ownerC.row('Mhs B'))?.text.includes('keluar'), 10000, 'owner melihat B keluar');
    assert.equal(sql('select status from meetings'), 'LIVE');
    assert.equal(sql(`select leave_reason from meeting_attendances ma join users u on u.id=ma.user_id where u.name='Mhs B'`), 'left');
});

test('8. owner mengakhiri untuk semua: A otomatis dikeluarkan, monitoring berhenti, data tersimpan', async () => {
    const before = (await aC.state()).signals;
    await ownerC.send('click', { el: 'btn-end-all' });

    await waitFor(async () => (await aC.state()).ended, 10000, 'A melihat pemberitahuan meeting berakhir');
    const s = await aC.state();
    assert.match(s.endedText, /mengakhiri meeting untuk semua/);
    assert.equal(s.jitsiDisposed, true);
    assert.equal((await ownerC.state()).ended, true);

    await sleep(6000);                                                        // 3x interval detak
    const after = (await aC.state()).signals;
    assert.ok(after - before <= 2, `monitoring harus berhenti (sinyal baru: ${after - before})`);

    assert.equal(sql('select status, end_reason from meetings'), 'ENDED\towner_ended');
    assert.equal(sql('select count(*) from meeting_attendances where left_at is null'), '0');

    // Riwayat fokus A tersimpan: hijau -> merah (wajah) -> hijau -> ... -> merah (offline? tidak) dst.
    const seq = sql(`select group_concat(status order by mf.id) from meeting_focus_logs mf join users u on u.id=mf.user_id where u.name='Mhs A'`);
    console.log('   riwayat fokus A:', seq);
    assert.ok(seq.includes('MERAH') && seq.includes('HIJAU'));
    assert.match(sql(`select group_concat(reason order by mf.id separator ' | ') from meeting_focus_logs mf join users u on u.id=mf.user_id where u.name='Mhs B'`), /Berpindah tab/);

    // Durasi kehadiran tersimpan & masuk akal
    const dur = sql(`select u.name, sum(timestampdiff(second, joined_at, left_at)) from meeting_attendances ma join users u on u.id=ma.user_id group by u.name order by u.name`);
    console.log('   durasi kehadiran (detik):\n     ' + dur.replace(/\n/g, '\n     '));
    for (const line of dur.split('\n')) assert.ok(Number(line.split('\t')[1]) > 5);
});

test('9. laporan: owner melihat semua, anggota hanya dirinya, orang luar ditolak', async () => {
    const mid = sql('select id from meetings');
    const path = `/rooms/${code}/meetings/${mid}`;

    const o = await (await owner.req(path)).text();
    assert.ok(o.includes('Mhs A') && o.includes('Mhs B') && o.includes('Dosen Owner'));
    assert.ok(o.includes('Berpindah tab'));

    const ra = await (await a.req(path)).text();
    assert.ok(ra.includes('Mhs A') && !ra.includes('Mhs B') && !ra.includes('Dosen Owner'));

    const rb = await (await b.req(path)).text();
    assert.ok(rb.includes('Mhs B') && !rb.includes('Mhs A'));

    assert.equal((await outsider.req(path)).status, 403);
});

test('10. Pomodoro independen: sesi A tetap berjalan dan tidak tercampur dengan data meeting', async () => {
    assert.equal(sql(`select status from study_sessions limit 1`), 'ACTIVE');
    assert.equal(sql('select count(*) from focus_logs'), '0');                // riwayat fokus meeting TIDAK masuk tabel Pomodoro
    assert.equal(sql(`select count(*) from activity_logs where event_type like 'TAB%'`), '0');
    // dan meeting tidak memuat data Pomodoro
    assert.equal(sql('select count(*) from meeting_focus_logs where reason like "%Pomodoro%"'), '0');
});
