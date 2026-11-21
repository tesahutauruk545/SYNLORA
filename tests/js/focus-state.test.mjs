import test from 'node:test';
import assert from 'node:assert/strict';
import { computeFocus, THRESHOLDS, formatClock, eventForTransition } from '../../resources/js/synlora/focus-state.js';

const base = { running: true, tabHidden: false, hiddenMs: 0, windowBlurred: false, cameraOn: false, faceMissingMs: 0 };
const f = (o) => computeFocus({ ...base, ...o });

// ---- ABU
test('tidak berjalan -> ABU (system)', () => {
    const r = f({ running: false });
    assert.equal(r.status, 'ABU');
    assert.equal(r.source, 'system');
});
test('tidak berjalan mengalahkan semua sinyal lain', () =>
    assert.equal(f({ running: false, tabHidden: true, hiddenMs: 99999 }).status, 'ABU'));

// ---- HIJAU
test('kamera mati & tab aktif -> HIJAU dari browser', () => {
    const r = f({});
    assert.equal(r.status, 'HIJAU');
    assert.equal(r.source, 'browser');
});
test('wajah terlihat -> HIJAU dari face', () => {
    const r = f({ cameraOn: true });
    assert.equal(r.status, 'HIJAU');
    assert.equal(r.source, 'face');
});
test('wajah hilang < 5 dtk -> masih HIJAU', () => assert.equal(f({ cameraOn: true, faceMissingMs: 4900 }).status, 'HIJAU'));

// ---- KUNING
test('wajah hilang >= 5 dtk -> KUNING (sumber face)', () => {
    const r = f({ cameraOn: true, faceMissingMs: THRESHOLDS.faceLostShortMs });
    assert.equal(r.status, 'KUNING');
    assert.equal(r.source, 'face');
});
test('wajah hilang SANGAT lama tetap KUNING, tidak pernah MERAH', () =>
    assert.equal(f({ cameraOn: true, faceMissingMs: 3_600_000 }).status, 'KUNING'));
test('wajah hilang diabaikan jika kamera mati', () => assert.equal(f({ cameraOn: false, faceMissingMs: 99999 }).status, 'HIJAU'));
test('jendela kehilangan fokus (tab masih terlihat) -> KUNING browser, bukan MERAH', () => {
    const r = f({ windowBlurred: true });
    assert.equal(r.status, 'KUNING');
    assert.equal(r.source, 'browser');
});
test('jendela blur lama tetap KUNING', () => assert.equal(f({ windowBlurred: true, cameraOn: true }).status, 'KUNING'));
test('tab tersembunyi < 10 dtk -> KUNING (masa tenggang)', () => {
    const r = f({ tabHidden: true, hiddenMs: 9999 });
    assert.equal(r.status, 'KUNING');
    assert.equal(r.source, 'browser');
});

// ---- MERAH
test('tab tersembunyi tepat 10 dtk -> MERAH', () => assert.equal(f({ tabHidden: true, hiddenMs: THRESHOLDS.tabHiddenRedMs }).status, 'MERAH'));
test('tab tersembunyi lama -> MERAH sumber browser', () => assert.equal(f({ tabHidden: true, hiddenMs: 120000 }).source, 'browser'));
test('posisi HP (landscape) TIDAK memengaruhi status', () => {
    // computeFocus tidak lagi menerima sinyal orientasi: argumen tambahan diabaikan.
    assert.equal(f({ landscape: true }).status, 'HIJAU');
    assert.equal(f({ landscape: true, cameraOn: true }).source, 'face');
});
test('tanpa kamera (CAMERA_OFF): pemantauan tab tetap berjalan', () => {
    assert.equal(f({ cameraOn: false }).status, 'HIJAU');
    assert.equal(f({ cameraOn: false, tabHidden: true, hiddenMs: 2000 }).status, 'KUNING');
    assert.equal(f({ cameraOn: false, tabHidden: true, hiddenMs: 12000 }).status, 'MERAH');
});
test('MERAH (tab lama) mengalahkan KUNING (wajah)', () =>
    assert.equal(f({ cameraOn: true, faceMissingMs: 9000, tabHidden: true, hiddenMs: 20000 }).status, 'MERAH'));
test('tab tersembunyi: sinyal browser didahulukan dari wajah', () =>
    assert.equal(f({ cameraOn: true, faceMissingMs: 9000, tabHidden: true, hiddenMs: 3000 }).source, 'browser'));

// ---- pemetaan event
test('eventForTransition: tab keluar -> TAB_SWITCH_OUT, kembali -> TAB_SWITCH_IN', () => {
    const green = f({});
    const yellow = f({ tabHidden: true, hiddenMs: 1000 });
    const red = f({ tabHidden: true, hiddenMs: 11000 });
    assert.equal(eventForTransition(green, yellow), 'TAB_SWITCH_OUT');
    assert.equal(eventForTransition(yellow, red), 'TAB_AWAY_LONG');
    assert.equal(eventForTransition(red, green), 'TAB_SWITCH_IN');
});
test('eventForTransition: wajah hilang/kembali', () => {
    const ok = f({ cameraOn: true });
    const lost = f({ cameraOn: true, faceMissingMs: 6000 });
    assert.equal(eventForTransition(ok, lost), 'FACE_LOST_SHORT');
    assert.equal(eventForTransition(lost, ok), 'FACE_DETECTED');
});
test('eventForTransition: tanpa status sebelumnya -> MONITOR_START', () => assert.equal(eventForTransition(null, f({})), 'MONITOR_START'));

test('formatClock', () => {
    assert.equal(formatClock(1500), '25:00');
    assert.equal(formatClock(59.2), '00:59');
    assert.equal(formatClock(-3), '00:00');
});

// ---------------------------------------------------------------- sinyal tambahan kamera
import { meanLuminance, estimateHeadPose, Hysteresis, SIGNALS } from '../../resources/js/synlora/focus-state.js';

const kp = (nx, ny, my = 0.62) => [{ x: 0.4, y: 0.4 }, { x: 0.6, y: 0.4 }, { x: nx, y: ny }, { x: 0.5, y: my }, { x: 0.3, y: 0.45 }, { x: 0.7, y: 0.45 }];

test('meanLuminance: gelap total, putih, dan data kosong', () => {
    assert.equal(meanLuminance(new Uint8ClampedArray(16 * 4)), 0);
    assert.ok(meanLuminance(new Uint8ClampedArray(16 * 4).fill(255)) > 254);
    assert.equal(meanLuminance(new Uint8ClampedArray(0)), null);
    assert.equal(meanLuminance(null), null);
});

test('estimateHeadPose: menghadap depan', () => {
    assert.equal(estimateHeadPose(kp(0.5, 0.5)).state, 'forward');
});

test('estimateHeadPose: menoleh ke kedua sisi', () => {
    assert.equal(estimateHeadPose(kp(0.66, 0.5)).state, 'turned');
    assert.equal(estimateHeadPose(kp(0.34, 0.5)).state, 'turned');
});

test('estimateHeadPose: menunduk (hidung mendekati garis mata)', () => {
    assert.equal(estimateHeadPose(kp(0.5, 0.43)).state, 'down');
});

test('estimateHeadPose: data tidak lengkap/tidak masuk akal -> unknown, bukan tuduhan', () => {
    assert.equal(estimateHeadPose(undefined).state, 'unknown');
    assert.equal(estimateHeadPose([]).state, 'unknown');
    assert.equal(estimateHeadPose([{ x: 0.5, y: 0.4 }, { x: 0.5, y: 0.4 }, { x: 0.5, y: 0.5 }, { x: 0.5, y: 0.6 }]).state, 'unknown'); // mata berimpit
    assert.equal(estimateHeadPose(kp(0.5, 0.5, 0.3)).state, 'unknown'); // mulut di atas mata
    assert.equal(estimateHeadPose([{ x: NaN, y: 0 }, { x: 1, y: 1 }, { x: 1, y: 1 }, { x: 1, y: 1 }]).state, 'unknown');
});

test('Hysteresis: baru melapor setelah kondisi bertahan, dan sekali saja', () => {
    const h = new Hysteresis(6000, 3000);
    assert.equal(h.update(true, 0), null);
    assert.equal(h.update(true, 5999), null);
    assert.equal(h.update(true, 6000), 'enter');
    assert.equal(h.update(true, 9000), null); // tidak melapor berulang
    assert.equal(h.update(false, 10000), null);
    assert.equal(h.update(false, 12999), null);
    assert.equal(h.update(false, 13000), 'exit');
});

test('Hysteresis: kondisi yang berkedip singkat tidak melapor sama sekali', () => {
    const h = new Hysteresis(6000, 3000);
    h.update(true, 0);
    h.update(true, 3000);
    h.update(false, 3500); // pulih sebelum 6 dtk: hitung mundur dibatalkan
    assert.equal(h.update(true, 4000), null);
    assert.equal(h.update(true, 9999), null);
    assert.equal(h.update(true, 10000), 'enter');
});

test('SIGNALS: batas pulih lebih terang daripada batas gelap (histeresis kecerahan)', () => {
    assert.ok(SIGNALS.brightLuminance > SIGNALS.darkLuminance);
});
