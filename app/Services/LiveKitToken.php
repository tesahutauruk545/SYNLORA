<?php

namespace App\Services;

use RuntimeException;

/**
 * Pembuat access token LiveKit (JWT HS256) tanpa dependensi tambahan.
 * API Key/Secret hanya dibaca dari config (.env) di server dan tidak pernah dikirim ke browser.
 */
class LiveKitToken
{
    public static function configured(): bool
    {
        return filled(config('livekit.url')) && filled(config('livekit.api_key')) && filled(config('livekit.api_secret'));
    }

    /** Nama room LiveKit stabil (memakai id, bukan kode yang dapat diganti owner). */
    public static function roomName(int $roomId): string
    {
        return 'synlora-room-'.$roomId;
    }

    public static function make(string $identity, string $name, string $livekitRoom, array $metadata = [], ?int $ttl = null, ?int $now = null): string
    {
        if (! self::configured()) {
            throw new RuntimeException('LiveKit belum dikonfigurasi.');
        }

        $now ??= time();
        $payload = [
            'iss' => config('livekit.api_key'),
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 10,
            'exp' => $now + ($ttl ?? (int) config('livekit.token_ttl', 7200)),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'video' => [
                'room' => $livekitRoom,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = $b64(hash_hmac('sha256', "$head.$body", (string) config('livekit.api_secret'), true));

        return "$head.$body.$sig";
    }
}
