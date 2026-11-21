<?php

namespace App\Realtime;

use App\Models\Room;
use App\Models\User;

/**
 * Token koneksi WebSocket: ditandatangani HMAC oleh backend, berisi pengguna, room,
 * dan peran (owner/anggota). Daemon hanya mempercayai isi token ini, sehingga klien
 * tidak dapat menyamar sebagai owner atau membaca room lain.
 */
class RealtimeToken
{
    public static function secret(): string
    {
        return config('synlora.realtime.secret')
            ?: hash('sha256', 'synlora-realtime|'.config('app.key'));
    }

    public static function issue(User $user, Room $room, bool $isOwner, ?int $now = null): string
    {
        $now ??= time();

        return self::sign([
            'u' => (int) $user->id,
            'r' => (int) $room->id,
            'o' => $isOwner,
            'exp' => $now + (int) config('synlora.realtime.token_ttl'),
        ]);
    }

    public static function sign(array $payload, ?string $secret = null): string
    {
        $body = self::b64(json_encode($payload));

        return $body.'.'.self::b64(hash_hmac('sha256', $body, $secret ?? self::secret(), true));
    }

    /** @return array{u:int,r:int,o:bool,exp:int}|null */
    public static function verify(string $token, ?string $secret = null, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        $expected = self::b64(hash_hmac('sha256', $parts[0], $secret ?? self::secret(), true));
        if (! hash_equals($expected, $parts[1])) {
            return null;
        }

        $data = json_decode(self::unb64($parts[0]), true);
        if (! is_array($data) || ! isset($data['u'], $data['r'], $data['exp']) || (int) $data['exp'] < ($now ?? time())) {
            return null;
        }

        return ['u' => (int) $data['u'], 'r' => (int) $data['r'], 'o' => (bool) ($data['o'] ?? false), 'exp' => (int) $data['exp']];
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
