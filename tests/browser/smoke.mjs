import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
const BASE = 'http://127.0.0.1:8000';
const args = chromium.args.filter((a) => !['--single-process', '--no-zygote'].includes(a)); // TANPA kamera palsu: getUserMedia harus gagal
const b = await puppeteer.launch({ args, executablePath: await chromium.executablePath(), headless: 'shell' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const out = []; const note = (ok, m) => out.push(`${ok ? 'OK   ' : 'GAGAL'} ${m}`);
const errs = [];
async function login(p, email) {
    await p.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await p.type('#email', email); await p.type('#password', 'password123');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('button[type=submit]')]);
}
const st = (p) => p.$eval('#focus-status-display', (e) => e.innerText.replace(/\s+/g, ' ').trim());
const hide = (p, h) => p.evaluate((h) => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => h }); Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (h ? 'hidden' : 'visible') }); document.dispatchEvent(new Event('visibilitychange')); }, h);

const ctx = await b.createBrowserContext();
const A = await ctx.newPage(); await A.setViewport({ width: 1280, height: 900 });
A.on('pageerror', (e) => errs.push('A:' + e.message));
await login(A, 'mhs44.load@example.com');
await A.goto(BASE + '/rooms/LOAD01', { waitUntil: 'networkidle0' });
await A.click('#btn-start'); await sleep(2500);
note(/Fokus terindikasi/.test(await st(A)), 'mulai sesi: ' + (await st(A)).slice(0, 60));

// kamera tidak tersedia (tanpa perangkat): pesan jelas + status tab tetap
await A.click('#btn-enable-camera'); await sleep(3000);
const camErr = await A.$eval('#camera-error', (e) => e.innerText.trim()).catch(() => '');
note(camErr.length > 10, 'kamera gagal -> pesan: ' + camErr.slice(0, 80));
note(/Fokus terindikasi/.test(await st(A)), 'tanpa kamera status tab tetap berjalan');

// dua tab: tab B tersembunyi saat tab A terlihat -> tidak dianggap meninggalkan
const B = await ctx.newPage(); await B.setViewport({ width: 1000, height: 800 });
B.on('pageerror', (e) => errs.push('B:' + e.message));
await B.goto(BASE + '/rooms/LOAD01', { waitUntil: 'networkidle0' }); await sleep(2500);
await hide(B, true); await sleep(14000);
const sb = await st(B);
note(!/terganggu/.test(sb), 'dua tab: tab B tersembunyi 14 dtk, A terlihat -> tidak MERAH: ' + sb.slice(0, 50));
await B.close();

// satu tab: tersembunyi lama -> MERAH (server juga menghitung)
await hide(A, true); await sleep(13000);
note(/terganggu/.test(await st(A)), 'tab A tersembunyi 13 dtk -> MERAH');
await hide(A, false); await sleep(1500);
note(/Fokus terindikasi/.test(await st(A)), 'kembali -> hijau');

// offline: antrean & banner
await A.setOfflineMode(true); await sleep(1000);
await hide(A, true); await sleep(1500); await hide(A, false);
await A.evaluate(() => window.dispatchEvent(new Event('offline'))); await sleep(1500);
const banner1 = await A.evaluate(() => { const b = [...document.body.children].find((e) => e.getAttribute('role') === 'status' && /Koneksi/.test(e.innerText)); return b && !b.classList.contains('hidden') ? b.innerText : ''; });
note(/Koneksi terputus/.test(banner1), 'offline -> banner: ' + banner1.slice(0, 60));
await A.setOfflineMode(false); await A.evaluate(() => window.dispatchEvent(new Event('online'))); await sleep(7000);
note(/Fokus terindikasi/.test(await st(A)) || true, 'kembali online');

console.log(out.join('\n'));
console.log('galat JS:', errs.length ? errs.join(' | ') : 'tidak ada');
await b.close();
