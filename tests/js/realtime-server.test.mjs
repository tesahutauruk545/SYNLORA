// Uji integrasi server WebSocket SYNLORA: menjalankan daemon PHP sungguhan lalu terhubung
// dengan klien WebSocket nyata. Jalankan: node --test tests/js/realtime-server.test.mjs
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import net from 'node:net';
import crypto from 'node:crypto';

const WS_PORT = 16001;
const IN_PORT = 16002;
const SECRET = 'test-secret-123';
const env = { ...process.env, REALTIME_SECRET: SECRET, REALTIME_INTERNAL_PORT: String(IN_PORT), REALTIME_PORT: String(WS_PORT) };

const b64 = (b) => Buffer.from(b).toString('base64url');
function token(payload, secret = SECRET) {
    const body = b64(JSON.stringify(payload));
    return `${body}.${crypto.createHmac('sha256', secret).update(body).digest('base64url')}`;
}
const tok = (u, r, o, exp = Math.floor(Date.now() / 1000) + 600) => token({ u, r, o, exp });

let daemon;

before(async () => {
    daemon = spawn('php', ['artisan', 'synlora:realtime', `--port=${WS_PORT}`, `--internal-port=${IN_PORT}`, '--host=127.0.0.1'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('daemon tidak siap')), 15000);
        daemon.stdout.on('data', (d) => { if (String(d).includes('berjalan')) { clearTimeout(t); resolve(); } });
        daemon.stderr.on('data', (d) => process.stderr.write(d));
    });
});

after(() => daemon && daemon.kill());

function connect(tokenValue) {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(`ws://127.0.0.1:${WS_PORT}/ws?token=${encodeURIComponent(tokenValue)}`);
        ws.inbox = [];
        ws.onmessage = (e) => ws.inbox.push(JSON.parse(e.data));
        ws.onopen = () => resolve(ws);
        ws.onerror = () => reject(new Error('handshake ditolak'));
    });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const events = (ws, name) => ws.inbox.filter((m) => m.event === name);

function publish(room, to, event, data) {
    // Memakai kelas PHP yang sebenarnya dipakai aplikasi (TcpRealtimePublisher).
    const code = `echo json_encode(app(App\\Realtime\\RealtimePublisher::class)->publish(${room}, json_decode('${JSON.stringify(to)}', true), '${event}', json_decode('${JSON.stringify(data)}', true)));`;
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], { env, encoding: 'utf8' }).trim();
}

test('token palsu, kedaluwarsa, atau tanpa token ditolak saat handshake', async () => {
    await assert.rejects(connect('abc.def'));
    await assert.rejects(connect(token({ u: 1, r: 1, o: true, exp: Math.floor(Date.now() / 1000) + 600 }, 'secret-lain')));
    await assert.rejects(connect(tok(1, 1, true, Math.floor(Date.now() / 1000) - 5)));
    await assert.rejects(connect(''));
});

test('permintaan HTTP biasa ke port WebSocket ditolak', async () => {
    const res = await fetch(`http://127.0.0.1:${WS_PORT}/ws`).catch((e) => e);
    // fetch tanpa Upgrade => server membalas 400 (atau menutup koneksi)
    assert.ok(res instanceof Error || res.status === 400);
});

test('owner menerima status semua peserta; peserta hanya statusnya sendiri; room lain tidak menerima apa pun', async () => {
    const owner = await connect(tok(1, 100, true));
    const a = await connect(tok(2, 100, false));
    const b = await connect(tok(3, 100, false));
    const otherOwner = await connect(tok(9, 200, true));
    await sleep(200);

    assert.equal(events(owner, 'hello')[0].data.role, 'owner');
    assert.equal(events(a, 'hello')[0].data.role, 'member');

    // Status peserta A: dikirim ke owner room + A sendiri
    assert.equal(publish(100, { owners: true, users: [2] }, 'participant', { participant: { user_id: 2, status: 'MERAH' } }), 'true');
    await sleep(300);

    assert.equal(events(owner, 'participant').length, 1);
    assert.equal(events(a, 'participant')[0].data.participant.status, 'MERAH');
    assert.equal(events(b, 'participant').length, 0, 'peserta lain TIDAK boleh menerima status A');
    assert.equal(events(otherOwner, 'participant').length, 0, 'owner room lain TIDAK boleh menerima');

    // Siaran ke seluruh room
    publish(100, { all: true }, 'meeting.ended', { reason: 'owner_ended' });
    await sleep(300);
    for (const ws of [owner, a, b]) assert.equal(events(ws, 'meeting.ended').length, 1);
    assert.equal(events(otherOwner, 'meeting.ended').length, 0);

    // Pengguna yang sama pemilik room: tidak menerima duplikat
    publish(100, { owners: true, users: [1] }, 'participant', { participant: { user_id: 1 } });
    await sleep(300);
    assert.equal(events(owner, 'participant').length, 2); // 1 sebelumnya + 1 ini (bukan 3)

    [owner, a, b, otherOwner].forEach((w) => w.close());
});

test('pesan internal dengan tanda tangan salah diabaikan', async () => {
    const owner = await connect(tok(1, 300, true));
    await sleep(100);

    await new Promise((resolve) => {
        const s = net.connect(IN_PORT, '127.0.0.1', () => {
            const payload = JSON.stringify({ room: 300, to: { all: true }, event: 'palsu', data: {} });
            s.write(JSON.stringify({ sig: 'deadbeef', payload }) + '\n', () => { s.end(); resolve(); });
        });
    });
    await sleep(300);
    assert.equal(events(owner, 'palsu').length, 0);
    owner.close();
});

test('klien tidak bisa menyiarkan pesan; ping aplikasi dibalas pong', async () => {
    const a = await connect(tok(2, 400, false));
    const b = await connect(tok(3, 400, false));
    await sleep(100);

    a.send(JSON.stringify({ event: 'participant', data: { participant: { status: 'HIJAU' } }, room: 400, to: { all: true } }));
    a.send(JSON.stringify({ type: 'ping' }));
    await sleep(300);

    assert.equal(events(b, 'participant').length, 0);
    assert.equal(events(a, 'pong').length, 1);
    [a, b].forEach((w) => w.close());
});

// ---- protokol tingkat rendah: fragmentasi, frame tanpa mask, payload terlalu besar ----

function rawClient() {
    return new Promise((resolve, reject) => {
        const s = net.connect(WS_PORT, '127.0.0.1');
        const key = crypto.randomBytes(16).toString('base64');
        const t = tok(5, 500, false);
        let buf = Buffer.alloc(0);
        s.on('data', (d) => { buf = Buffer.concat([buf, d]); s.buf = buf; });
        s.on('connect', () => s.write(`GET /ws?token=${t} HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n\r\n`));
        s.on('error', reject);
        setTimeout(() => {
            const head = s.buf?.toString('latin1') || '';
            const expected = crypto.createHash('sha1').update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
            assert.ok(head.startsWith('HTTP/1.1 101'), 'handshake 101');
            assert.ok(head.includes(`Sec-WebSocket-Accept: ${expected}`), 'Sec-WebSocket-Accept benar');
            resolve(s);
        }, 300);
    });
}

function maskedFrame(op, payload, fin = true) {
    const mask = crypto.randomBytes(4);
    const data = Buffer.from(payload);
    const masked = Buffer.from(data.map((b, i) => b ^ mask[i % 4]));
    let header;
    if (data.length < 126) header = Buffer.from([(fin ? 0x80 : 0) | op, 0x80 | data.length]);
    else if (data.length <= 65535) { header = Buffer.alloc(4); header[0] = (fin ? 0x80 : 0) | op; header[1] = 0x80 | 126; header.writeUInt16BE(data.length, 2); }
    else { header = Buffer.alloc(10); header[0] = (fin ? 0x80 : 0) | op; header[1] = 0x80 | 127; header.writeBigUInt64BE(BigInt(data.length), 2); }
    return Buffer.concat([header, mask, masked]);
}

test('pesan terfragmentasi dirakit dengan benar (ping aplikasi dibalas)', async () => {
    const s = await rawClient();
    const before = s.buf.length;
    const msg = JSON.stringify({ type: 'ping' });
    s.write(maskedFrame(0x1, msg.slice(0, 5), false));
    await sleep(100);
    s.write(maskedFrame(0x0, msg.slice(5), true));
    await sleep(300);
    assert.ok(s.buf.subarray(before).toString().includes('"event":"pong"'));
    s.destroy();
});

test('ping protokol dibalas pong; frame tanpa mask => close 1002; payload > 64KB => close 1009', async () => {
    let s = await rawClient();
    let before = s.buf.length;
    s.write(maskedFrame(0x9, 'hai'));
    await sleep(200);
    const pong = s.buf.subarray(before);
    assert.equal(pong[0], 0x8A);
    assert.equal(pong.subarray(2).toString(), 'hai');
    s.destroy();

    s = await rawClient();
    before = s.buf.length;
    s.write(Buffer.from([0x81, 0x02, 0x68, 0x69])); // frame TANPA mask dari klien = pelanggaran
    await sleep(300);
    let tail = s.buf.subarray(before);
    assert.equal(tail[0], 0x88);
    assert.equal(tail.readUInt16BE(2), 1002);
    s.destroy();

    s = await rawClient();
    before = s.buf.length;
    s.write(maskedFrame(0x1, 'x'.repeat(70000)));
    await sleep(500);
    tail = s.buf.subarray(before);
    assert.equal(tail[0], 0x88);
    assert.equal(tail.readUInt16BE(2), 1009);
    s.destroy();
});

test('banyak klien sekaligus menerima siaran (beban ringan)', async () => {
    const clients = await Promise.all(Array.from({ length: 60 }, (_, i) => connect(tok(1000 + i, 600, i === 0))));
    await sleep(200);
    publish(600, { all: true }, 'meeting.ended', {});
    await sleep(500);
    assert.equal(clients.filter((c) => events(c, 'meeting.ended').length === 1).length, 60);
    clients.forEach((c) => c.close());
});
