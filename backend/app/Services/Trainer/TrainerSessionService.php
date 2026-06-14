<?php

namespace App\Services\Trainer;

use App\Models\Trainer;
use App\Models\TrainerDeviceSession;
use App\Services\DeviceSessionService;
use Illuminate\Support\Str;

/**
 * Sesiones por dispositivo del portal profesional: emite el `session_token`
 * opaco (bearer real), aplica "una sola sesión activa por dispositivo" y resuelve
 * el token entrante para `auth.trainer`. Espejo de {@see DeviceSessionService}
 * pero sobre `trainer_device_sessions`, de modo que la sesión profesional y la de
 * miembro queden aisladas (scopes separados).
 */
class TrainerSessionService
{
    /**
     * Emite/rota la sesión del dispositivo para el entrenador. No revoca las de
     * otros dispositivos del entrenador (un profesional puede trabajar desde
     * varios equipos); la política anti-cuentas-compartidas se aplica del lado
     * del miembro.
     *
     * @return array{token: string, session: TrainerDeviceSession, was_new_device: bool}
     */
    public function issueSession(Trainer $trainer, array $context): array
    {
        $deviceId = $context['device_id'] ?? null;
        $deviceId = ($deviceId !== null && trim((string) $deviceId) !== '')
            ? (string) $deviceId
            : 'auto-'.Str::random(24);

        $token = Str::random(64);

        $session = TrainerDeviceSession::updateOrCreate(
            ['trainer_id' => $trainer->id, 'device_id' => $deviceId],
            [
                'token_hash' => TrainerDeviceSession::hashToken($token),
                'device_name' => $context['device_name'] ?? null,
                'platform' => $context['platform'] ?? null,
                'app_version' => $context['app_version'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
                'last_seen_at' => now(),
                'trusted_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ],
        );

        return [
            'token' => $token,
            'session' => $session,
            'was_new_device' => $session->wasRecentlyCreated,
        ];
    }

    /** Resuelve la sesión activa a partir del token bearer (o null). */
    public function resolveByToken(string $token): ?TrainerDeviceSession
    {
        return TrainerDeviceSession::query()
            ->active()
            ->where('token_hash', TrainerDeviceSession::hashToken($token))
            ->first();
    }

    /** Sesión activa de un dispositivo concreto (para el desbloqueo biométrico). */
    public function activeForDevice(Trainer $trainer, string $deviceId): ?TrainerDeviceSession
    {
        return TrainerDeviceSession::query()
            ->where('trainer_id', $trainer->id)
            ->where('device_id', $deviceId)
            ->active()
            ->first();
    }

    /** Marca actividad reciente, como mucho una escritura por minuto. */
    public function touch(TrainerDeviceSession $session): void
    {
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    public function revoke(TrainerDeviceSession $session, string $reason): void
    {
        $session->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    /**
     * Revoca TODAS las sesiones activas del entrenador (p.ej. al desactivarlo en
     * el CRM o ante riesgo). Devuelve cuántas se revocaron.
     */
    public function revokeAll(Trainer $trainer, string $reason): int
    {
        return TrainerDeviceSession::query()
            ->where('trainer_id', $trainer->id)
            ->active()
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);
    }

    /** Sesiones activas del entrenador, la más reciente primero. */
    public function activeSessions(Trainer $trainer)
    {
        return TrainerDeviceSession::query()
            ->where('trainer_id', $trainer->id)
            ->active()
            ->orderByDesc('last_seen_at')
            ->get();
    }
}
