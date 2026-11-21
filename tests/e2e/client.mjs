// Satu "browser" peserta: memuat halaman meeting ASLI dari server Laravel (HTML hasil render Blade),
// menjalankan kode frontend ASLI (meeting-page.js) di jsdom, dengan backend dan WebSocket sungguhan.
// Yang ditiru hanya perangkat keras: Jitsi (iframe), kamera, dan model wajah.
//   node --import ./tests/e2e/support/register.mjs tests/e2e/client.mjs <baseUrl> <path> <cookie>
import { JSDOM, VirtualConsole } from 'jsdom';
import readline from 'node:readline';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const [, , base, pagePath, cookie0 = ''] = process.argv;
const realFetch = globalThis.fetch;
const jar = new Map(cookie0.split(';').map((c) => c.trim()).filter(Boolean).map((c) => c.split('=')).map(([k, ...v]) => [k, v.join('=')]));
const state = { offline: false, signals: 0, navigated: 0, errors: [], toasts: [] };

globalThis.fetch = async (url, init = {}) => {
    if (state.offline && String(url).includes('/meeting/signal')) throw new TypeError('network down');
    if (String(url).includes('/meeting/signal')) state.signals++;
    const headers = { ...(init.headers || {}), Cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; ') };
    const res = await realFetch(url, { ...init, headers });
    for (const sc of res.headers.getSetCookie?.() || []) {
        const [kv] = sc.split(';');
        const i = kv.indexOf('=');
        jar.set(kv.slice(0, i), kv.slice(i + 1));
    }
    return res;
};

const res = await globalThis.fetch(base + pagePath);
const html = await res.text();

const vc = new VirtualConsole();
vc.on('jsdomError', (e) => { if (/navigation/i.test(e.message)) state.navigated++; else state.errors.push(e.message); });
vc.on('error', (e) => state.errors.push(String(e)));

const dom = new JSDOM(html, { url: base + pagePath, pretendToBeVisual: true, runScripts: 'outside-only', virtualConsole: vc });
const { window } = dom;
const { document } = window;

Object.defineProperty(globalThis, 'window', { value: window, configurable: true });
Object.defineProperty(globalThis, 'document', { value: document, configurable: true });
Object.defineProperty(globalThis, 'navigator', { value: window.navigator, configurable: true });
Object.defineProperty(globalThis, 'location', { value: window.location, configurable: true });
globalThis.__faces = true;

// --- perangkat keras tiruan ---
const track = { stop() {}, addEventListener() {} };
Object.defineProperty(window.navigator, 'mediaDevices', {
    value: { getUserMedia: async () => ({ getTracks: () => [track], getVideoTracks: () => [track] }) },
});
window.HTMLMediaElement.prototype.play = async () => {};
const probe = document.getElementById('camera-probe');
Object.defineProperty(probe, 'readyState', { value: 4 });
Object.defineProperty(probe, 'videoWidth', { value: 320 });
window.confirm = () => true;

let hidden = false;
Object.defineProperty(document, 'hidden', { get: () => hidden, configurable: true });

class FakeJitsi {
    constructor(domain, options) {
        this.domain = domain; this.options = options; this.l = {};
        globalThis.__jitsi = this;
        setTimeout(() => this.emit('videoConferenceJoined'), 80);
    }
    addListener(e, f) { (this.l[e] ||= []).push(f); }
    emit(e) { (this.l[e] || []).forEach((f) => f()); }
    getNumberOfParticipants() { return 3; }
    dispose() { this.disposed = true; }
}
window.JitsiMeetExternalAPI = FakeJitsi;

// --- jalankan kode aplikasi yang asli ---
const cfg = JSON.parse(document.getElementById('synlora-meeting-config').textContent);
const srcDir = path.resolve(process.cwd(), 'resources/js/synlora');
const page = await import(pathToFileURL(path.join(srcDir, 'meeting-page.js')).href);
page.init(cfg);

// --- kendali dari test ---
const text = (id) => (document.getElementById(id)?.textContent || '').trim();
const rl = readline.createInterface({ input: process.stdin });
const out = (o) => process.stdout.write(JSON.stringify(o) + '\n');

rl.on('line', async (line) => {
    const m = JSON.parse(line);
    let result = null;
    try {
        switch (m.cmd) {
            case 'click': document.getElementById(m.el).click(); break;
            case 'faces': globalThis.__faces = m.value; break;
            case 'hidden':
                hidden = m.value;
                document.dispatchEvent(new window.Event('visibilitychange'));
                break;
            case 'offline': state.offline = m.value; break;
            case 'jitsiHangup': globalThis.__jitsi.emit('readyToClose'); break;
            case 'text': result = text(m.el); break;
            case 'visible': result = !document.getElementById(m.el)?.classList.contains('hidden'); break;
            case 'participants':
                result = [...document.querySelectorAll('#participants-list [data-user-id]')].map((r) => ({
                    id: r.dataset.userId, text: r.textContent.replace(/\s+/g, ' ').trim(),
                }));
                break;
            case 'body': result = document.body.textContent.replace(/\s+/g, ' '); break;
            case 'state':
                result = {
                    signals: state.signals, navigated: state.navigated, errors: state.errors,
                    me: text('my-status-title'), reason: text('my-status-reason'), mode: text('conn-mode'),
                    camera: text('camera-note'), toasts: [...document.querySelectorAll('#toasts > div')].map((d) => d.textContent),
                    ended: !document.getElementById('ended-notice').classList.contains('hidden'),
                    endedText: text('ended-text'), jitsiDisposed: !!globalThis.__jitsi?.disposed,
                    jitsiOptions: globalThis.__jitsi ? { domain: globalThis.__jitsi.domain, room: globalThis.__jitsi.options.roomName, hasJwt: !!globalThis.__jitsi.options.jwt, rec: globalThis.__jitsi.options.configOverwrite.fileRecordingsEnabled, toolbar: globalThis.__jitsi.options.configOverwrite.toolbarButtons } : null,
                };
                break;
            case 'exit': out({ id: m.id, result: 'bye' }); process.exit(0);
        }
    } catch (e) {
        out({ id: m.id, error: String(e && e.stack || e) });
        return;
    }
    out({ id: m.id, result });
});

out({ ready: true });
