<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;

/**
 * Lo que el LABORATORIO HABLADO no permite, dicho para el CRM (contrato H8,
 * D8-23). Las mismas reglas que {@see UltronStudioException}: un código ESTABLE
 * que el CRM usa para decidir, el estado HTTP y, cuando hace falta, datos que
 * viajan con el error. Los mensajes no llevan internals ni repiten lo que se
 * escribió.
 *
 * Vive aquí (y no en la excepción) porque es lo propio del paquete del
 * laboratorio hablado: la excepción es de todo el Studio. Lo que ya existe y
 * significa lo mismo se reutiliza tal cual: `person_required`, `forbidden`,
 * `client_request_reused` y `version_not_found`.
 */
final class StudioVoiceLabErrors
{
    // ── 403 ─────────────────────────────────────────────────────────────────

    public static function notSessionOwner(): UltronStudioException
    {
        return new UltronStudioException('Solo quien abrió la sesión la da por terminada y cuenta lo que pasó en ella.', 'voice_lab_not_session_owner', 403);
    }

    /** @param  string  $reason  `session_creator` | `version_author` */
    public static function fourEyesRequired(string $reason): UltronStudioException
    {
        return new UltronStudioException(
            $reason === 'version_author'
                ? 'Nadie emite el veredicto sobre su propia versión: pide a otra persona con permiso que lo emita.'
                : 'Nadie emite el veredicto sobre la sesión que abrió: pide a otra persona con permiso que lo emita.',
            'four_eyes_required',
            403,
            ['reason' => $reason],
        );
    }

    // ── 404 ─────────────────────────────────────────────────────────────────

    public static function sessionNotFound(): UltronStudioException
    {
        return new UltronStudioException('La sesión del laboratorio hablado no existe.', 'voice_lab_session_not_found', 404);
    }

    public static function profileNotFound(): UltronStudioException
    {
        return new UltronStudioException('El perfil de voz no existe.', 'voice_profile_not_found', 404);
    }

    // ── 409 ─────────────────────────────────────────────────────────────────

    public static function disabled(): UltronStudioException
    {
        return new UltronStudioException('El laboratorio hablado está apagado en el servidor.', 'voice_lab_disabled');
    }

    /**
     * @param  list<string>  $reasons  los de {@see StudioVoiceLabConfig::REASONS}
     * @param  ?string  $mode  `fake`, `elevenlabs` o null con un driver no válido
     */
    public static function notConfigured(array $reasons, ?string $mode): UltronStudioException
    {
        return new UltronStudioException(
            'El laboratorio hablado no está configurado para abrir sesiones en este modo. Lo arregla quien administra el servidor.',
            'voice_lab_not_configured',
            409,
            ['reasons' => array_values($reasons), 'mode' => $mode],
        );
    }

    public static function dailySessionsExceeded(int $today, int $max): UltronStudioException
    {
        return new UltronStudioException(
            'Ya abriste hoy todas las sesiones del laboratorio hablado que admite el servidor. Vuelve a intentarlo mañana.',
            'voice_lab_daily_sessions_exceeded',
            409,
            ['sessionsToday' => $today, 'maxSessionsPerDay' => $max],
        );
    }

    public static function sessionBudgetExceeded(int $requiredMicros, int $capMicros): UltronStudioException
    {
        return new UltronStudioException(
            'Una sesión con esta duración máxima cuesta más que el tope por sesión del servidor.',
            'voice_lab_session_budget_exceeded',
            409,
            ['requiredMicros' => $requiredMicros, 'capMicros' => $capMicros],
        );
    }

    public static function dailyBudgetExceeded(int $requiredMicros, int $remainingMicros): UltronStudioException
    {
        return new UltronStudioException(
            'No queda presupuesto del día para otra sesión del laboratorio hablado. Espera a mañana.',
            'voice_lab_daily_budget_exceeded',
            409,
            ['requiredMicros' => $requiredMicros, 'remainingMicros' => $remainingMicros],
        );
    }

    /** @param  array<string, mixed>  $estimate  el de `GET voice-lab/status` en este momento */
    public static function estimateChanged(array $estimate): UltronStudioException
    {
        return new UltronStudioException(
            'La reserva de la sesión cambió desde que la viste (cambió la configuración del servidor). Revísala y vuelve a abrirla.',
            'voice_lab_estimate_changed',
            409,
            ['estimate' => $estimate],
        );
    }

    public static function durationExceedsSession(int $maxDurationSeconds, int $maxSessionSeconds): UltronStudioException
    {
        return new UltronStudioException(
            'El perfil permite llamadas más largas que una sesión del laboratorio hablado: el agente no se puede probar aquí sin pasarse del tope de la sesión.',
            'voice_lab_duration_exceeds_session',
            409,
            ['maxDurationSeconds' => $maxDurationSeconds, 'maxSessionSeconds' => $maxSessionSeconds],
        );
    }

    /**
     * Los destinos de transferencia van EN CLARO en la carga del agente
     * (D8-14): hace falta el descifrado del perfil de voz (S8-b). Hasta
     * enlazarlo, un perfil con destinos no se prueba aquí.
     */
    public static function transfersNotSupported(): UltronStudioException
    {
        return new UltronStudioException(
            'Este perfil tiene destinos de transferencia y el laboratorio hablado todavía no los puede montar en el agente de prueba.',
            'voice_lab_transfers_not_supported',
        );
    }

    public static function sessionNotOpen(string $currentStatus): UltronStudioException
    {
        return new UltronStudioException('La sesión ya está cerrada: no hay nada que terminar.', 'voice_lab_session_not_open', 409, ['currentStatus' => $currentStatus]);
    }

    public static function sessionNotStarted(): UltronStudioException
    {
        return new UltronStudioException('La sesión todavía no tiene su conexión: espera a que termine de abrirse o a que caduque.', 'voice_lab_session_not_started');
    }

    public static function alreadyReported(): UltronStudioException
    {
        return new UltronStudioException('Ya contaste lo que pasó en esta sesión: se cuenta una vez.', 'voice_lab_already_reported');
    }

    /** @param  string  $reason  la clave de {@see StudioVoiceLabSessions::NOT_REVIEWABLE} */
    public static function notReviewable(string $reason, string $message): UltronStudioException
    {
        return new UltronStudioException($message, 'voice_lab_session_not_reviewable', 409, ['reason' => $reason]);
    }

    public static function verdictExists(): UltronStudioException
    {
        return new UltronStudioException('Esta sesión ya tiene veredicto: se emite uno solo.', 'verdict_exists');
    }

    public static function integrityMismatch(): UltronStudioException
    {
        return new UltronStudioException(
            'La huella del agente que viste no es la de esta sesión. Vuelve a cargar la sesión antes de emitir el veredicto.',
            'voice_lab_integrity_mismatch',
        );
    }

    // ── 502 ─────────────────────────────────────────────────────────────────

    /**
     * El proveedor no dejó abrir la sesión (el agente no se creó, no cuadra con
     * lo compilado o no hubo URL firmada). La sesión queda `failed` y su
     * reserva, liberada; viaja su detalle, sin nada de la respuesta del
     * proveedor.
     *
     * @param  array<string, mixed>  $session  SessionDto
     */
    public static function providerFailed(string $endReason, array $session): UltronStudioException
    {
        return new UltronStudioException(
            'El proveedor de voz no dejó abrir la sesión. No se gastó nada: vuelve a intentarlo más tarde o avisa a quien administra el servidor.',
            'voice_lab_provider_failed',
            502,
            ['endReason' => $endReason, 'session' => $session],
        );
    }
}
