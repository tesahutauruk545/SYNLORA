import test from 'node:test';
import assert from 'node:assert/strict';
import { RealtimeChannel } from '../../resources/js/synlora/meeting-realtime.js';

globalThis.document = { hidden: false };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

class FakeWS {
    static instances = [];
    constructor(url) { this.url = url; this.readyState = 0; this.sent = []; FakeWS.instances.push(this); }
    send(d) { this.sent.push(d); }
    close() { this.readyState = 3; this.onclose && this.onclose(); }
    open() { this.readyState = 1; this.onopen && this.onopen(); }
    msg(o) { this.onmessage && this.onmessage({ data: JSON.stringify(o) }); }
}

function make(over = {}) {
    FakeWS.instances = [];
    const log = { modes: [], events: [], polls: 0, infos: 0 };
    const ch = new RealtimeChannel({
        getInfo: async () => { log.infos++; return { enabled: true, url: 'ws://x/ws', token: 'T' + log.infos }; },
        onEvent: (e) => log.events.push(e),
        poll: async () => { log.polls++; },
        onMode: (m) => log.modes.push(m),
        pollMs: 30, pageIsSecure: false, WebSocketImpl: FakeWS, ...over,
    });
    return { ch, log };
}

test('terhubung: mode websocket, event diteruskan, hello/pong disaring, snapshot diambil saat tersambung', async () => {
    const { ch, log } = make();
    ch.start();
    await sleep(20);
    const ws = FakeWS.instances[0];
    assert.match(ws.url, /token=T1/);
    ws.open();
    ws.msg({ event: 'hello', data: {} });
    ws.msg({ event: 'pong', data: {} });
    ws.msg({ event: 'participant', data: { participant: { user_id: 1 } } });
    assert.equal(log.modes.at(-1), 'websocket');
    assert.equal(log.events.length, 1);
    assert.ok(log.polls >= 1);
    ch.stop();
});

test('WebSocket putus => fallback polling cepat, lalu sambung ulang dengan token BARU dan sinkron ulang', async () => {
    const { ch, log } = make();
    ch.start();
    await sleep(20);
    FakeWS.instances[0].open();
    const pollsBefore = log.polls;
    FakeWS.instances[0].close();           // koneksi terputus
    assert.equal(log.modes.at(-1), 'polling');
    await sleep(100);                       // polling 30ms berjalan
    assert.ok(log.polls > pollsBefore + 1, 'polling aktif saat WS mati');
    await sleep(1200);                      // backoff 1 dtk lalu menyambung lagi
    assert.ok(FakeWS.instances.length >= 2, 'mencoba sambung ulang');
    assert.match(FakeWS.instances[1].url, /token=T2/);
    FakeWS.instances[1].open();
    assert.equal(log.modes.at(-1), 'websocket');
    ch.stop();
});

test('server tanpa WebSocket (enabled=false) => tetap polling', async () => {
    const { ch, log } = make({ getInfo: async () => ({ enabled: false }) });
    ch.start();
    await sleep(120);
    assert.equal(FakeWS.instances.length, 0);
    assert.equal(log.modes.at(-1), 'polling');
    assert.ok(log.polls >= 2);
    ch.stop();
});

test('halaman HTTPS dengan ws:// (mixed content) => tidak mencoba WebSocket, memakai polling', async () => {
    const { ch, log } = make({ pageIsSecure: true });
    ch.start();
    await sleep(80);
    assert.equal(FakeWS.instances.length, 0);
    assert.equal(log.modes.at(-1), 'polling');
    ch.stop();
});

test('stop() menghentikan polling dan koneksi', async () => {
    const { ch, log } = make();
    ch.start();
    await sleep(20);
    FakeWS.instances[0].open();
    ch.stop();
    const n = log.polls;
    await sleep(100);
    assert.equal(log.polls, n);
    assert.equal(log.modes.at(-1), 'offline');
});
