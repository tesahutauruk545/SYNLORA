<?php

namespace App\Services;

use App\Models\StudySession;
use Illuminate\Support\Facades\Cache;

/**
 * Status ONLINE website (terpisah dari status belajar/Pomodoro).
 *
 * Browser yang sedang membuka SYNLORA mengirim ping ringan tiap ~25 dtk (POST /presence/ping) dan setiap
 * permintaan sesi belajar (heartbeat/state/events) juga menyentuh presence. Server hanya menyimpan satu angka
 * waktu per pengguna di cache dengan TTL pendek: tanpa ping selama TTL, kunci kedaluwarsa sendiri -> OFFLINE.
 * Tidak ada tulisan ke database dan tidak ada data aktivitas yang disimpan (hanya "terakhir terlihat").
 */
class OnlinePresence
{
    public static function ttl(): int
    {
        return max(15, (int) config('synlora.presence.ttl_seconds', 90));
    }

    private static function key(int $userId): string
    {
        return 'online:'.$userId;
    }

    /** Catat bahwa pengguna terlihat sekarang. */
    public static function touch(int $userId, ?int $at = null): void
    {
        $at ??= now()->getTimestamp();
        Cache::put(self::key($userId), $at, self::ttl() + 30);
    }

    /**
     * Waktu terakhir terlihat (timestamp) untuk banyak pengguna sekaligus.
     *
     * @param  iterable<int>  $userIds
     * @return array<int,int>  userId => timestamp (hanya yang pernah tercatat)
     */
    public static function lastSeen(iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($i) => (int) $i)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $found = Cache::many($ids->map(fn ($i) => self::key($i))->all());
        $out = [];
        foreach ($ids as $id) {
            $v = $found[self::key($id)] ?? null;
            if (is_numeric($v)) {
                $out[$id] = (int) $v;
            }
        }

        return $out;
    }

    /**
     * Apakah pengguna ONLINE: ada ping segar, ATAU sesi terbuka yang detaknya segar.
     * Cache yang kedaluwarsa / hilang berarti offline (tidak pernah "terbawa" status lama).
     */
    public static function isOnline(?int $lastSeenTs, ?StudySession $openSession = null, ?int $now = null): bool
    {
        $now ??= now()->getTimestamp();
        $ttl = self::ttl();

        if ($lastSeenTs !== null && $now - $lastSeenTs <= $ttl) {
            return true;
        }

        $hb = $openSession?->last_heartbeat_at?->getTimestamp();

        return $hb !== null && $now - $hb <= $ttl;
    }

    /**
     * Presence gabungan: offline | online | belajar | istirahat | jeda.
     * Sesi Pomodoro hanya menentukan sub-status SAAT pengguna online; tidak adanya sesi BUKAN berarti offline.
     */
    public static function state(bool $online, ?StudySession $session): string
    {
        if (! $online) {
            return 'offline';
        }

        return match (true) {
            $session === null => 'online',
            $session->isPaused() => 'jeda',
            $session->phase === StudySession::BREAK => 'istirahat',
            default => 'belajar',
        };
    }
}
