<?php

namespace App\Services;

use Firebase\JWT\JWT;

/**
 * Proveedor de video en vivo: LiveKit (Bloque 5). Acuña tokens de acceso a la
 * sala SERVER-SIDE (la api_secret nunca llega a la app). Si faltan credenciales,
 * `isConfigured()` devuelve false y la función se presenta como "no disponible"
 * (nunca crashea). Ver docs/STORY_LIVE.md.
 *
 * El token es un JWT firmado HS256 con el formato que espera LiveKit (claim
 * `video` con los grants). No requiere SDK de servidor: solo firebase/php-jwt.
 */
class LiveKitService
{
    public function isConfigured(): bool
    {
        return (bool) config('live.enabled', false)
            && filled(config('live.livekit.url'))
            && filled(config('live.livekit.api_key'))
            && filled(config('live.livekit.api_secret'));
    }

    public function url(): ?string
    {
        return config('live.livekit.url');
    }

    /**
     * Acuña un token de acceso a la sala. `canPublish` true para el host
     * (transmite cámara/mic); false para espectadores (solo miran).
     */
    public function mintToken(string $room, string $identity, string $name, bool $canPublish): string
    {
        $key = (string) config('live.livekit.api_key');
        $secret = (string) config('live.livekit.api_secret');
        $ttl = (int) config('live.livekit.token_ttl', 3600);
        $now = time();

        $payload = [
            'iss' => $key,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now,
            'iat' => $now,
            'exp' => $now + $ttl,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }
}
