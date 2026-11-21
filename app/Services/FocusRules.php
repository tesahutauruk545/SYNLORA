<?php

namespace App\Services;

/**
 * Aturan status fokus di SISI SERVER.
 *
 * Klien hanya melaporkan JENIS EVENT. Status dan sumbernya ditentukan di sini, sehingga klien yang
 * dimodifikasi tidak bisa mengirim status sembarangan (mis. "HIJAU" bersama event "tab ditinggalkan").
 * Ini bukan jaminan mutlak: pengguna yang mengutak-atik browser tetap bisa tidak mengirim apa pun,
 * tetapi akibatnya terlihat (DISCONNECTED, HEARTBEAT_LOST) dan bukan status hijau palsu.
 *
 * Status adalah INDIKATOR aktivitas browser dan (opsional) deteksi wajah, bukan bukti konsentrasi.
 */
class FocusRules
{
    /** Event yang tidak mengubah status warna (hanya dicatat / mengubah keadaan kamera). */
    public const NO_STATUS = [
        'CAMERA_ON', 'CAMERA_OFF', 'CAMERA_DENIED', 'CAMERA_UNAVAILABLE', 'CAMERA_ERROR', 'MODEL_LOAD_FAILED',
        'MONITOR_ERROR', 'ORIENTATION_CHANGE', 'FACE_LOST_LONG',
        // Sinyal tambahan: dicatat untuk ditinjau dosen, TIDAK mengubah warna status.
        'CAMERA_INTERRUPTED', 'CAMERA_LOW_QUALITY', 'CAMERA_QUALITY_OK', 'POSE_AWAY', 'POSE_FORWARD',
    ];

    /** Kejadian yang menandakan kamera bermasalah (dihitung sebagai gangguan kamera, bukan tuduhan). */
    public const CAMERA_ISSUES = ['CAMERA_DENIED', 'CAMERA_UNAVAILABLE', 'CAMERA_ERROR', 'MODEL_LOAD_FAILED', 'CAMERA_INTERRUPTED'];

    /**
     * Teks alasan MILIK SERVER. Teks bebas dari klien tidak pernah dipakai sebagai alasan status atau
     * keterangan yang dilihat dosen, sehingga klien yang dimodifikasi tidak bisa menulis keterangan menyesatkan.
     */
    public const LABELS = [
        'MONITOR_START' => 'Pemantauan dimulai',
        'TAB_SWITCH_OUT' => 'Tab SYNLORA ditinggalkan',
        'TAB_SWITCH_IN' => 'Kembali ke tab SYNLORA',
        'TAB_AWAY_LONG' => 'Tab/aplikasi SYNLORA ditinggalkan lebih dari batas',
        'WINDOW_BLUR' => 'Jendela SYNLORA tidak aktif (fokus di aplikasi lain)',
        'WINDOW_FOCUS' => 'Jendela SYNLORA aktif kembali',
        'FACE_DETECTED' => 'Wajah terdeteksi',
        'FACE_LOST_SHORT' => 'Wajah tidak terdeteksi (hanya indikator)',
        'FACE_LOST_LONG' => 'Wajah tidak terdeteksi lebih dari 10 detik',
        'CAMERA_ON' => 'Kamera diaktifkan',
        'CAMERA_OFF' => 'Kamera dimatikan oleh pengguna',
        'CAMERA_DENIED' => 'Izin kamera ditolak',
        'CAMERA_UNAVAILABLE' => 'Kamera tidak tersedia pada perangkat',
        'CAMERA_ERROR' => 'Kamera gagal dibuka (mungkin dipakai aplikasi lain)',
        'MODEL_LOAD_FAILED' => 'Model deteksi wajah gagal dimuat',
        'CAMERA_INTERRUPTED' => 'Kamera terputus di tengah sesi (dicabut, ditutup, atau berhenti)',
        'CAMERA_LOW_QUALITY' => 'Gambar kamera terlalu gelap/tidak jelas (keandalan terbatas)',
        'CAMERA_QUALITY_OK' => 'Kualitas gambar kamera pulih',
        'POSE_AWAY' => 'Kepala menghadap ke samping/bawah cukup lama (sinyal tambahan, bukan bukti)',
        'POSE_FORWARD' => 'Kepala kembali menghadap layar',
        'ORIENTATION_CHANGE' => 'Orientasi layar berubah',
        'MONITOR_ERROR' => 'Pemantau di browser mengalami galat',
    ];

    public static function reason(string $eventType): string
    {
        return self::LABELS[$eventType] ?? $eventType;
    }

    public const CAMERA_STATES = [
        'CAMERA_ON' => 'on',
        'CAMERA_OFF' => 'off',
        'CAMERA_DENIED' => 'denied',
        'CAMERA_UNAVAILABLE' => 'unavailable',
        'CAMERA_ERROR' => 'error',
        'MODEL_LOAD_FAILED' => 'model_failed',
        'CAMERA_INTERRUPTED' => 'interrupted',
    ];

    /**
     * @return array{0:string,1:string}|null [status, source] atau null bila event tidak mengubah status
     */
    public static function derive(string $eventType, ?string $clientStatus, ?string $previousEvent, ?int $secondsSincePrevious): ?array
    {
        $allowed = ['HIJAU', 'KUNING', 'MERAH'];

        return match ($eventType) {
            'MONITOR_START' => [in_array($clientStatus, $allowed, true) ? $clientStatus : 'HIJAU', 'browser'],
            'TAB_SWITCH_OUT' => ['KUNING', 'browser'],
            'WINDOW_BLUR' => ['KUNING', 'browser'],
            // MERAH hanya sah bila server memang sudah melihat tab ditinggalkan cukup lama;
            // klaim yang terlalu dini diabaikan sepenuhnya (tidak mengubah status maupun jam eskalasi).
            'TAB_AWAY_LONG' => (($previousEvent === 'TAB_SWITCH_OUT' && ($secondsSincePrevious ?? 0) >= (int) config('synlora.focus.hidden_grace_seconds', 10) - 2) || $previousEvent === 'TAB_AWAY_LONG')
                ? ['MERAH', 'browser'] : null,
            'TAB_SWITCH_IN', 'WINDOW_FOCUS' => [$clientStatus === 'KUNING' ? 'KUNING' : 'HIJAU', $clientStatus === 'KUNING' ? 'face' : 'browser'],
            'FACE_DETECTED' => ['HIJAU', 'face'],
            'FACE_LOST_SHORT' => ['KUNING', 'face'],
            default => null,
        };
    }
}
