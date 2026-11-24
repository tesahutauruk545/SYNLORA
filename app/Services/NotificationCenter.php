<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Models\Assignment;
use App\Models\Room;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/** Pembuatan notifikasi tersimpan (tidak hilang saat refresh/offline) dengan dedup per (user, dedupe_key). */
class NotificationCenter
{
    /** @return UserNotification|null null bila sudah pernah dibuat (duplikat) atau gagal. */
    public static function send(int $userId, string $type, string $dedupeKey, string $title, ?string $body = null, array $data = [], ?string $url = null, ?int $roomId = null): ?UserNotification
    {
        try {
            $n = UserNotification::firstOrCreate(
                ['user_id' => $userId, 'dedupe_key' => mb_substr($dedupeKey, 0, 120)],
                ['room_id' => $roomId, 'type' => $type, 'title' => mb_substr($title, 0, 200), 'body' => $body ? mb_substr($body, 0, 500) : null, 'data' => $data, 'action_url' => $url],
            );
        } catch (QueryException $e) {
            return null; // balapan dua permintaan: baris unik sudah ada
        }

        if (! $n->wasRecentlyCreated) {
            return null;
        }

        try {
            event(new UserNotificationCreated($n));
        } catch (\Throwable $e) {
            Log::warning('Siaran notifikasi gagal (polling tetap berjalan): '.$e->getMessage());
        }

        return $n;
    }

    /** Tugas baru -> seluruh mahasiswa anggota room tersebut (bukan pembuat tugas, bukan room lain). */
    public static function assignmentPublished(Assignment $a, Room $room, User $creator): int
    {
        $ids = $room->members()->where('users.id', '!=', $creator->id)->where('users.role', 'mahasiswa')->pluck('users.id');
        $url = route('assignments.show', [$room, $a]);
        $sent = 0;

        foreach ($ids as $uid) {
            $ok = self::send((int) $uid, 'assignment', 'assignment:'.$a->id, 'Tugas baru: '.$a->title,
                $creator->name.' · '.$room->name.($a->due_at ? ' · batas '.$a->due_at->translatedFormat('d M Y, H:i') : ' · tanpa batas waktu'),
                [
                    'assignment_id' => $a->id,
                    'title' => $a->title,
                    'lecturer' => $creator->name,
                    'room' => $room->name,
                    'room_code' => $room->code,
                    'given_at' => $a->created_at?->toIso8601String(),
                    'due_at' => $a->due_at?->toIso8601String(),
                ], $url, $room->id);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }
}
