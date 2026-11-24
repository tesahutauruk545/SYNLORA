import test from 'node:test';
import assert from 'node:assert/strict';
import { headDirection, pickPhone, Hold, FrameVote, FaceStateMachine } from '../../resources/js/synlora/monitor-logic.js';

// Titik: [mata kanan subjek (kiri gambar), mata kiri subjek (kanan gambar), hidung, mulut]
const kp = (noseX) => [{ x: 0.4, y: 0.4 }, { x: 0.6, y: 0.4 }, { x: noseX, y: 0.52 }, { x: 0.5, y: 0.65 }];

test('arah kepala: depan, kiri, kanan tidak tertukar', () => {
    assert.equal(headDirection(kp(0.5)).state, 'forward');
    assert.equal(headDirection(kp(0.65)).state, 'left');   // hidung bergeser ke kanan gambar = subjek menoleh ke kirinya
    assert.equal(headDirection(kp(0.35)).state, 'right');
    assert.equal(headDirection(null).state, 'unknown');
    assert.equal(headDirection([{ x: 0.4, y: 0.4 }]).state, 'unknown');
});

test('menunduk terdeteksi lewat rasio vertikal', () => {
    const down = [{ x: 0.4, y: 0.4 }, { x: 0.6, y: 0.4 }, { x: 0.5, y: 0.43 }, { x: 0.5, y: 0.65 }];
    assert.equal(headDirection(down).state, 'down');
});

const det = (name, score, w, h) => ({ categories: [{ categoryName: name, score }], boundingBox: { originX: 0, originY: 0, width: w, height: h } });

test('HP: orientasi vertikal/horizontal dari kotak, dan ambang confidence', () => {
    assert.deepEqual(pickPhone([det('cell phone', 0.8, 60, 120)])?.orientation, 'vertical');
    assert.equal(pickPhone([det('cell phone', 0.8, 120, 60)])?.orientation, 'horizontal');
    assert.equal(pickPhone([det('cell phone', 0.8, 100, 105)])?.orientation, null); // hampir persegi: tidak ditebak
    assert.equal(pickPhone([det('cell phone', 0.3, 60, 120)], 0.45), null);          // di bawah ambang
    assert.equal(pickPhone([det('remote', 0.9, 60, 120), det('book', 0.9, 60, 120)]), null); // objek mirip bukan HP
    assert.equal(pickPhone([det('cell phone', 0.5, 60, 120), det('cell phone', 0.9, 120, 60)]).orientation, 'horizontal'); // terbaik
});

test('FrameVote: butuh beberapa frame, tidak aktif oleh satu frame', () => {
    const v = new FrameVote(3, 5);
    assert.equal(v.push(true).on, false);
    assert.equal(v.push(true).on, false);
    assert.equal(v.push(true).on, true);
    for (let i = 0; i < 4; i++) v.push(false);
    assert.equal(v.push(false).off, true);
});

test('Hold: masuk dan keluar dengan histeresis waktu', () => {
    const h = new Hold(1000, 500);
    assert.equal(h.update(true, 0), null);
    assert.equal(h.update(true, 1000), 'enter');
    assert.equal(h.update(false, 1100), null);
    assert.equal(h.update(false, 1600), 'exit');
});

test('FaceStateMachine: gerakan sesaat tidak memicu, konsisten melewati ambang memicu', () => {
    const f = new FaceStateMachine({ notFoundMs: 5000, awayMs: 6000, turnMs: 5000, backMs: 2000 });
    assert.equal(f.update({ faces: 1, dir: 'forward' }, 0), 'FACE_FACING');
    // menoleh 2 dtk lalu kembali: tidak ada peringatan
    assert.equal(f.update({ faces: 1, dir: 'left' }, 1000), null);
    assert.equal(f.update({ faces: 1, dir: 'left' }, 3000), null);
    assert.equal(f.update({ faces: 1, dir: 'forward' }, 3100), null);
    assert.equal(f.state, 'facing');
    // menoleh konsisten 5 dtk
    assert.equal(f.update({ faces: 1, dir: 'right' }, 10000), null);
    assert.equal(f.update({ faces: 1, dir: 'right' }, 15000), 'FACE_TURN_RIGHT');
    assert.equal(f.update({ faces: 1, dir: 'right' }, 16000), null); // tidak berulang
    // kembali stabil 2 dtk
    assert.equal(f.update({ faces: 1, dir: 'forward' }, 17000), null);
    assert.equal(f.update({ faces: 1, dir: 'forward' }, 19100), 'FACE_FACING');
    // wajah hilang
    assert.equal(f.update({ faces: 0, dir: 'unknown' }, 20000), null);
    assert.equal(f.update({ faces: 0, dir: 'unknown' }, 25000), 'FACE_NOT_FOUND');
    // arah tidak diketahui tidak mengubah apa pun
    assert.equal(f.update({ faces: 1, dir: 'unknown' }, 26000), null);
});
