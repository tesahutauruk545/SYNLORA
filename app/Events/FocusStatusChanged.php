<?php

namespace App\Events;

use App\Models\StudySession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Perubahan status fokus mahasiswa di room (realtime). Dikirim HANYA saat status benar-benar berubah
 * (StudySession::logFocus sudah membuang pencatatan status yang sama), sehingga tidak ada notifikasi berulang.
 *
 * Privasi: payload hanya berisi status, alasan teks milik server, dan identitas ringkas. Tidak ada gambar/video.
 * Saluran:
 *   - private room.{kode}.monitor        : hanya dosen anggota room (RoomPolicy@monitor)
 *   - private App.Models.User.{id}       : hanya mahasiswa yang bersangkutan
 */
class FocusStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public array $payload;

    public function __construct(
        public string $roomCode,
        public int $studentId,
        array $payload,
    ) {
        $this->payload = $payload;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.'.$this->roomCode.'.monitor'),
            new PrivateChannel('App.Models.User.'.$this->studentId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'focus.status';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /**
     * Dipanggil dari StudySession::logFocus. Kegagalan siaran (Reverb mati/belum dikonfigurasi) tidak boleh
     * mengganggu pencatatan fokus: dashboard dosen tetap memperbarui lewat polling.
     */
    public static function announce(StudySession $session, string $status, ?string $previous, string $reason, ?string $source, ?string $event, $at): void
    {
        if (! $session->room_id || ! in_array($status, ['HIJAU', 'KUNING', 'MERAH', 'ABU', 'DISCONNECTED'], true)) {
            return;
        }

        try {
            $session->loadMissing('room:id,code', 'user:id,name,role');
            if (! $session->room || ! $session->user || $session->user->isDosen()) {
                return; // hanya mahasiswa yang dipantau
            }

            $kind = match (true) {
                $status === 'KUNING' && $source === 'face' => 'face_lost',
                in_array($status, ['KUNING', 'MERAH'], true) => 'alert',
                $status === 'HIJAU' && in_array($previous, ['KUNING', 'MERAH'], true) => 'recovered',
                default => 'update',
            };

            event(new self($session->room->code, (int) $session->user_id, [
                'kind' => $kind,
                'notify' => $kind !== 'update',
                'student_id' => (int) $session->user_id,
                'student_name' => $session->user->name,
                'room' => $session->room->code,
                'status' => $status,
                'previous' => $previous,
                'source' => $source,
                'event' => $event,
                'reason' => $reason,
                'at' => $at->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('Siaran status fokus gagal: '.$e->getMessage());
        }
    }
}
