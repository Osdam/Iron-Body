<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminSession;
use Illuminate\Support\Str;

/**
 * Sesiones del panel/CRM: emite el `token` opaco (bearer real), lo resuelve para
 * `auth.admin` y lo revoca en el logout. Espejo reducido de
 * {@see \App\Services\Trainer\TrainerSessionService}: el token en claro nunca se
 * persiste (solo su hash SHA-256).
 */
class AdminSessionService
{
    /**
     * Emite una sesión nueva para el admin. `remember` extiende la caducidad.
     *
     * @return array{token: string, session: AdminSession}
     */
    public function issueSession(Admin $admin, array $context = [], bool $remember = false): array
    {
        $token = Str::random(64);

        $ttlMinutes = $remember
            ? (int) config('admin.session_remember_days', 30) * 24 * 60
            : (int) config('admin.session_ttl_minutes', 720);

        $session = AdminSession::create([
            'admin_id' => $admin->id,
            'token_hash' => AdminSession::hashToken($token),
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => isset($context['user_agent'])
                ? mb_substr((string) $context['user_agent'], 0, 500)
                : null,
            'last_seen_at' => now(),
            'expires_at' => $ttlMinutes > 0 ? now()->addMinutes($ttlMinutes) : null,
        ]);

        return ['token' => $token, 'session' => $session];
    }

    /** Resuelve la sesión activa (no revocada ni caducada) por el token bearer. */
    public function resolveByToken(string $token): ?AdminSession
    {
        return AdminSession::query()
            ->active()
            ->where('token_hash', AdminSession::hashToken($token))
            ->first();
    }

    public function touch(AdminSession $session): void
    {
        $session->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function revoke(AdminSession $session, string $reason = 'logout'): void
    {
        $session->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }
}
