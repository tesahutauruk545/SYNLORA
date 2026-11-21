<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use RuntimeException;

/** Menyiapkan konfigurasi Jitsi untuk satu peserta: domain, nama ruang, dan (opsional) JWT RS256. */
class JitsiService
{
    public function domain(): string
    {
        return (string) config('synlora.jitsi.domain', 'meet.jit.si');
    }

    public function isJaas(): bool
    {
        return $this->domain() === '8x8.vc';
    }

    public function jwtEnabled(): bool
    {
        return (bool) config('synlora.jitsi.app_id') && $this->privateKey() !== null;
    }

    public function clientConfig(Meeting $meeting, User $user, bool $isModerator): array
    {
        $appId = (string) config('synlora.jitsi.app_id');
        $jaas = $this->isJaas();

        return [
            'domain' => $this->domain(),
            'script_url' => $jaas
                ? "https://8x8.vc/{$appId}/external_api.js"
                : "https://{$this->domain()}/external_api.js",
            'room_name' => $jaas ? "{$appId}/{$meeting->jitsi_name}" : $meeting->jitsi_name,
            'jwt' => $this->jwtEnabled() ? $this->makeJwt($meeting, $user, $isModerator) : null,
            'display_name' => $user->name,
            'is_moderator' => $isModerator,
            // Email sengaja tidak dikirim ke Jitsi.
            'restricted' => $this->jwtEnabled(),
            'public_instance' => $this->domain() === 'meet.jit.si' && ! $this->jwtEnabled(),
        ];
    }

    public function makeJwt(Meeting $meeting, User $user, bool $isModerator, ?int $now = null): string
    {
        $key = $this->privateKey();
        if ($key === null) {
            throw new RuntimeException('Kunci privat JWT Jitsi belum dikonfigurasi.');
        }

        $now ??= time();
        $appId = (string) config('synlora.jitsi.app_id');
        $jaas = $this->isJaas();

        $payload = [
            'aud' => $jaas ? 'jitsi' : $appId,
            'iss' => $jaas ? 'chat' : $appId,
            'sub' => $jaas ? $appId : $this->domain(),
            'room' => $meeting->jitsi_name,
            'iat' => $now,
            'nbf' => $now - 10,
            'exp' => $now + (int) config('synlora.jitsi.jwt_ttl'),
            'context' => [
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'moderator' => $isModerator ? 'true' : 'false',
                ],
                // Tidak ada perekaman / siaran / transkripsi.
                'features' => [
                    'recording' => 'false',
                    'livestreaming' => 'false',
                    'transcription' => 'false',
                    'outbound-call' => 'false',
                ],
            ],
        ];

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($kid = config('synlora.jitsi.kid')) {
            $header['kid'] = $kid;
        }

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode($header)).'.'.$b64(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $pkey = openssl_pkey_get_private($key);
        if ($pkey === false || ! openssl_sign($input, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal menandatangani JWT Jitsi: kunci privat tidak valid.');
        }

        return $input.'.'.$b64($sig);
    }

    private function privateKey(): ?string
    {
        if ($inline = config('synlora.jitsi.private_key')) {
            return str_replace('\n', "\n", $inline);
        }

        $path = config('synlora.jitsi.private_key_path');
        if (! $path) {
            return null;
        }

        if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path)) {
            $path = base_path($path);
        }

        return is_readable($path) ? file_get_contents($path) : null;
    }
}
