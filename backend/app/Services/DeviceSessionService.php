<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberDeviceSession;
use Illuminate\Support\Str;

/**
 * Gestiona las sesiones por dispositivo de un miembro: emite el `session_token`
 * opaco (bearer real de la app), aplica la política de "una sola sesión activa"
 * (revoca el resto de dispositivos al verificar un login nuevo) y resuelve el
 * token entrante del middleware.
 */
class DeviceSessionService
{
    /**
     * Emite/rota la sesión del dispositivo y revoca las demás del miembro.
     *
     * @return array{token: string, session: MemberDeviceSession, revoked: array<int, MemberDeviceSession>, was_new_device: bool}
     */
    public function issueSession(Member $member, array $context): array
    {
        $deviceId = $context['device_id'] ?? null;
        $deviceId = ($deviceId !== null && trim((string) $deviceId) !== '')
            ? (string) $deviceId
            : 'auto-' . Str::random(24);

        $token = Str::random(64);

        // Sesiones activas en OTROS dispositivos (antes de la alta de este).
        $others = MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->active()
            ->where('device_id', '!=', $deviceId)
            ->get();

        $session = MemberDeviceSession::updateOrCreate(
            ['member_id' => $member->id, 'device_id' => $deviceId],
            [
                'token_hash'     => MemberDeviceSession::hashToken($token),
                'device_name'    => $context['device_name'] ?? null,
                'platform'       => $context['platform'] ?? null,
                'app_version'    => $context['app_version'] ?? null,
                'ip_address'     => $context['ip_address'] ?? null,
                'user_agent'     => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
                'last_seen_at'   => now(),
                'trusted_at'     => now(),
                'revoked_at'     => null,
                'revoked_reason' => null,
            ],
        );

        $wasNewDevice = $session->wasRecentlyCreated;

        // Política anti-cuentas-compartidas: una sola sesión activa por miembro.
        $revoked = [];
        foreach ($others as $other) {
            $other->update([
                'revoked_at'     => now(),
                'revoked_reason' => 'superseded_by_new_login',
            ]);
            $revoked[] = $other;
        }

        return [
            'token'          => $token,
            'session'        => $session,
            'revoked'        => $revoked,
            'was_new_device' => $wasNewDevice,
        ];
    }

    /**
     * Sesión activa y "viva" en OTRO dispositivo (para bloquear el ingreso
     * concurrente). Devuelve null si no hay, o si la única sesión activa está
     * inactiva más allá de la ventana de gracia (se permite el relevo).
     */
    public function concurrentActiveSession(Member $member, ?string $deviceId): ?MemberDeviceSession
    {
        if (! (bool) config('otp.concurrency.block_concurrent', true)) {
            return null;
        }

        $query = MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->active();

        if ($deviceId !== null && trim($deviceId) !== '') {
            $query->where('device_id', '!=', $deviceId);
        }

        foreach ($query->orderByDesc('last_seen_at')->get() as $session) {
            if ($this->isAlive($session)) {
                return $session;
            }
        }

        return null;
    }

    /** ¿La sesión tuvo actividad dentro de la ventana de gracia? */
    public function isAlive(MemberDeviceSession $session): bool
    {
        $grace = (int) config('otp.concurrency.session_grace', 180);
        $ref = $session->last_seen_at ?? $session->trusted_at;
        if ($ref === null) {
            return true; // recién creada: cuenta como viva.
        }

        return $ref->gt(now()->subSeconds($grace));
    }

    /** Resuelve la sesión activa a partir del token bearer (o null). */
    public function resolveByToken(string $token): ?MemberDeviceSession
    {
        return MemberDeviceSession::query()
            ->active()
            ->where('token_hash', MemberDeviceSession::hashToken($token))
            ->first();
    }

    /**
     * Sesión activa de un dispositivo concreto (para el desbloqueo biométrico).
     */
    public function activeForDevice(Member $member, string $deviceId): ?MemberDeviceSession
    {
        return MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->where('device_id', $deviceId)
            ->active()
            ->first();
    }

    /** Marca actividad reciente, como mucho una escritura por minuto. */
    public function touch(MemberDeviceSession $session): void
    {
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    public function revoke(MemberDeviceSession $session, string $reason): void
    {
        $session->update([
            'revoked_at'     => now(),
            'revoked_reason' => $reason,
        ]);
    }

    /** Sesiones activas del miembro, la más reciente primero. */
    public function activeSessions(Member $member)
    {
        return MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->active()
            ->orderByDesc('last_seen_at')
            ->get();
    }
}
