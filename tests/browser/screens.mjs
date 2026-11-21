import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
const BASE = 'http://127.0.0.1:8000';
const browser = await puppeteer.launch({ args: chromium.args.filter((a) => !['--single-process', '--no-zygote'].includes(a)), executablePath: await chromium.executablePath(), headless: 'shell' });
const sizes = { mobile: { width: 360, height: 740, isMobile: true, hasTouch: true, deviceScaleFactor: 1 }, tablet: { width: 820, height: 1180, isMobile: true, hasTouch: true }, laptop: { width: 1024, height: 640 }, desktop: { width: 1440, height: 900 } };
const overflow = [];

async function login(page, email) {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', 'password123');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
}
async function snap(page, name, size, full = true) {
    await new Promise((r) => setTimeout(r, 900));
    const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    if (o.sw > o.cw + 1) overflow.push(`${name}@${size}: scrollWidth ${o.sw} > ${o.cw}`);
    await page.screenshot({ path: `/tmp/shots/${name}-${size}.png`, fullPage: full });
}
for (const [size, vp] of Object.entries(sizes)) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await page.setViewport(vp);
    page.on('pageerror', (e) => console.log('PAGEERROR', size, e.message));
    page.on('console', (m) => { if (m.type() === 'error') console.log('CONSOLE', size, m.text().slice(0, 200)); });
    for (const [n, path] of [['landing', '/'], ['login', '/login'], ['register', '/register']]) {
        await page.goto(BASE + path, { waitUntil: 'networkidle0' });
        await snap(page, n, size);
    }
    await login(page, 'mhs01.load@example.com');
    for (const [n, path] of [['dashboard', '/dashboard'], ['room', '/rooms/LOAD01'], ['pomodoro', '/pomodoro'], ['profile', '/profile']]) {
        await page.goto(BASE + path, { waitUntil: 'networkidle0' });
        await snap(page, n, size);
    }
    await ctx.close();
    const ctx2 = await browser.createBrowserContext();
    const p2 = await ctx2.newPage();
    await p2.setViewport(vp);
    p2.on('pageerror', (e) => console.log('PAGEERROR dosen', size, e.message));
    await login(p2, 'dosen.load@example.com');
    await p2.goto(BASE + '/dosen', { waitUntil: 'networkidle0' });
    await snap(p2, 'dosen', size);
    await p2.goto(BASE + '/rooms/LOAD01', { waitUntil: 'networkidle0' });
    await snap(p2, 'room-owner', size);
    await p2.goto(BASE + '/pomodoro', { waitUntil: 'networkidle0' });
    await snap(p2, 'pomodoro-dosen', size);
    await ctx2.close();
}
console.log('OVERFLOW:', overflow.length ? overflow : 'tidak ada');
await browser.close();
