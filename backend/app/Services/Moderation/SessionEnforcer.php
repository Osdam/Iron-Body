<?php

namespace App\Services\Moderation;

use App\Models\Member;
use App\Models\MemberDeviceSession;
use App\Models\MemberSuspension;
use App\Models\ModerationAuditLog;
use App\Support\Moderation\ModerationScope;
use Illuminate\Support\Facades\Log;

/**
 * Convierte una sanción de `full_app_access` en una pérdida REAL de acceso.
 *
 * El problema que resuelve: `MemberSuspension` describía la sanción pero no la
 * ejecutaba. Un miembro suspendido conservaba su `session_token` y su
 * `access_hash`, así que seguía usando la app con normalidad; y si cerraba
 * sesión, volvía a entrar sin obstáculo. La sanción existía sólo como texto en
 * `/api/app/moderation/status`.
 *
 * Aquí se cierra el círculo en los dos extremos:
 *  - HACIA ATRÁS: se revocan las sesiones de dispositivo ya emitidas, para que
 *    un token anterior no sobreviva a la sanción.
 *  - HACIA DELANTE: {@see AuthenticateMember} y {@see AuthController} preguntan
 *    por {@see blockFor()} antes de resolver o emitir cualquier sesión.
 *
 * Alcance deliberadamente estrecho: SOLO `full_app_access` corta el acceso.
 * Una sanción social (no publicar, no reaccionar) no debe expulsar a nadie de
 * la app — sigue entrando y conserva rutinas, nutrición, clases y su membresía.
 */
class SessionEnforcer
{
    /** Motivo que queda escrito en `member_device_sessions.revoked_reason`. */
    public const REVOKE_REASON = 'moderation_full_app_suspension';

    public function __construct(private ModerationAudit $audit) {}

    /**
     * ¿Esta sanción retira el acceso completo a la app?
     *
     * Se pregunta por la jerarquía, no por igualdad de cadena: hoy sólo
     * `full_app_access` la implica, pero si mañana se añade un scope superior
     * quedará cubierto sin tocar los call-sites.
     */
    public static function blocksAppAccess(string $scope): bool
    {
        return in_array(
            ModerationScope::FULL_APP_ACCESS,
            ModerationScope::implies($scope),
            true,
        );
    }

    /**
     * La sanción EFECTIVA que hoy le impide entrar a la app, o null.
     *
     * Es el predicado que consultan las barreras de autenticación. Devuelve el
     * modelo (no un booleano) porque la app necesita explicar al usuario qué
     * pasó: motivo público, si es permanente y cuándo termina.
     */
    public function blockFor(int $memberId): ?MemberSuspension
    {
        return MemberSuspension::query()
            ->where('member_id', $memberId)
            ->where('scope', ModerationScope::FULL_APP_ACCESS)
            ->effective()
            // Una permanente pesa más que una temporal a efectos del mensaje.
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('ends_at')
            ->first();
    }

    /**
     * Revoca todas las sesiones vivas del miembro si la sanción corta el acceso.
     *
     * Idempotente y silencioso para sanciones sociales: devuelve 0 sin tocar
     * nada. No lanza — una sanción correctamente registrada no debe deshacerse
     * porque falle la limpieza de sesiones; el fallo se registra y las barreras
     * de autenticación siguen bloqueando igualmente en la siguiente petición.
     */
    public function enforce(MemberSuspension $suspension): int
    {
        if (! self::blocksAppAccess((string) $suspension->scope)) {
            return 0;
        }
        if (! $suspension->isEffective()) {
            return 0;
        }

        $memberId = (int) $suspension->member_id;

        try {
            $revoked = MemberDeviceSession::query()
                ->where('member_id', $memberId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => self::REVOKE_REASON,
                    'updated_at' => now(),
                ]);

            // El `access_hash` permanente es la segunda vía de autenticación
            // (compatibilidad). Dejarlo vivo mantendría abierta justo la puerta
            // que esta sanción cierra.
            Member::whereKey($memberId)
                ->whereNull('access_hash_revoked_at')
                ->update(['access_hash_revoked_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('moderation.session_revocation_failed', [
                'member_id' => $memberId,
                'suspension_id' => $suspension->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $this->audit->system(
            ModerationAuditLog::ACTION_SESSIONS_REVOKED,
            'member_suspension',
            (int) $suspension->id,
            [
                'member_id' => $memberId,
                'scope' => $suspension->scope,
                'sessions_revoked' => (int) $revoked,
                'permanent' => $suspension->isPermanent(),
            ],
        );

        Log::info('moderation.sessions_revoked', [
            'member_id' => $memberId,
            'sessions_revoked' => (int) $revoked,
        ]);

        return (int) $revoked;
    }

    /**
     * Cuerpo de la respuesta 401 que recibe una cuenta con el acceso retirado.
     *
     * Un `code` estable y los datos de la sanción para que la app pueda mostrar
     * su pantalla de cuenta restringida en vez de un error técnico. Nunca se
     * incluye `internal_reason` ni nada del caso que la originó.
     *
     * @return array<string, mixed>
     */
    public static function payload(MemberSuspension $suspension): array
    {
        return [
            'ok' => false,
            'code' => 'account_moderation_suspended',
            'message' => ModerationScope::memberExplanation((string) $suspension->scope),
            'data' => [
                'scope' => $suspension->scope,
                'public_reason' => $suspension->public_reason,
                'starts_at' => $suspension->starts_at?->toIso8601String(),
                'ends_at' => $suspension->ends_at?->toIso8601String(),
                'is_permanent' => $suspension->isPermanent(),
                'can_appeal' => (bool) config('ugc.appeals_enabled', true)
                    && $suspension->action?->isAppealable() === true,
            ],
        ];
    }
}
