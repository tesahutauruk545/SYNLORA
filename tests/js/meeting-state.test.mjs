import test from 'node:test';
import assert from 'node:assert/strict';
import {
    computeMeetingFocus, applyParticipantEvent, effectiveConnection, effectiveStatus, liveSeconds, formatDuration, summarize,
} from '../../resources/js/synlora/meeting-state.js';

const base = { monitoring: true, tabHidden: false, cameraState: 'ready', faceMissingMs: 0, graceMs: 3000 };

test('wajah terdeteksi + halaman aktif => HIJAU', () => assert.equal(computeMeetingFocus(base).status, 'HIJAU'));
test('berpindah tab => MERAH seketika', () => assert.equal(computeMeetingFocus({ ...base, tabHidden: true }).status, 'MERAH'));
test('wajah hilang lebih dari masa tenggang => MERAH', () => assert.equal(computeMeetingFocus({ ...base, faceMissingMs: 3000 }).status, 'MERAH'));
test('wajah hilang sebentar (kedip/jeda deteksi) => masih HIJAU', () => assert.equal(computeMeetingFocus({ ...base, faceMissingMs: 2900 }).status, 'HIJAU'));
test('pemantauan belum aktif => ABU', () => assert.equal(computeMeetingFocus({ ...base, monitoring: false }).status, 'ABU'));
test('izin kamera ditolak => ABU (tidak terpantau), tetapi pindah tab tetap MERAH', () => {
    assert.equal(computeMeetingFocus({ ...base, cameraState: 'denied' }).status, 'ABU');
    assert.equal(computeMeetingFocus({ ...base, cameraState: 'denied', tabHidden: true }).status, 'MERAH');
});
test('kamera error/pending/off => ABU dengan alasan jelas', () => {
    for (const c of ['error', 'pending', 'off']) {
        const r = computeMeetingFocus({ ...base, cameraState: c });
        assert.equal(r.status, 'ABU');
        assert.ok(r.reason.length > 5);
    }
});

const P = (o) => ({ user_id: 1, name: 'A', status: 'HIJAU', reason: 'ok', connection: 'online', attendance_seconds: 100, last_seen: 1000, join_count: 1, ...o });

test('event participant mengganti baris yang sama dan menambah baris baru', () => {
    let list = [P({ user_id: 1, name: 'Budi' })];
    list = applyParticipantEvent(list, { event: 'participant', data: { participant: P({ user_id: 1, name: 'Budi', status: 'MERAH' }) } });
    assert.equal(list.length, 1);
    assert.equal(list[0].status, 'MERAH');
    list = applyParticipantEvent(list, { event: 'participant', data: { participant: P({ user_id: 2, name: 'Andi' }) } });
    assert.deepEqual(list.map((x) => x.name), ['Andi', 'Budi']);
    assert.equal(applyParticipantEvent(list, { event: 'lain' }), list);
});

test('peserta tanpa detak > stale tampil terputus & ABU walau belum ada event', () => {
    const p = P({ last_seen: 1000 });
    assert.equal(effectiveConnection(p, 1040, 45), 'online');
    assert.equal(effectiveConnection(p, 1046, 45), 'disconnected');
    assert.equal(effectiveStatus(p, 1046, 45), 'ABU');
    assert.equal(effectiveStatus(p, 1010, 45), 'HIJAU');
    assert.equal(effectiveConnection(P({ connection: 'left' }), 1010, 45), 'left');
});

test('durasi hidup bertambah saat online, berhenti saat terputus/keluar', () => {
    const p = P({ attendance_seconds: 100, last_seen: 1000 });
    assert.equal(liveSeconds(p, 1020, 45), 120);
    assert.equal(liveSeconds(p, 1500, 45), 100);                       // terputus: tidak bertambah
    assert.equal(liveSeconds(P({ connection: 'left', attendance_seconds: 50 }), 2000, 45), 50);
});

test('ringkasan & format durasi', () => {
    const list = [P({ user_id: 1, status: 'HIJAU' }), P({ user_id: 2, status: 'MERAH' }), P({ user_id: 3, status: 'ABU' }),
        P({ user_id: 4, connection: 'left' }), P({ user_id: 5, last_seen: 100 })];
    assert.deepEqual(summarize(list, 1010, 45), { HIJAU: 1, MERAH: 1, ABU: 1, disconnected: 1, left: 1, online: 3 });
    assert.equal(formatDuration(3725), '01:02:05');
    assert.equal(formatDuration(-5), '00:00:00');
});
