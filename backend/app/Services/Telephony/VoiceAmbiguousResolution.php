<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use DateTimeInterface;

/**
 * LA SALIDA DE UNA `ambiguous` QUE NO SALIÓ (Enmienda del 22-11-2026, E19-3;
 * H-1 del informe `informe-be-voz.md`). Una saliente queda `ambiguous` cuando
 * el job no sabe si marcó (tiempo agotado, 5xx, el job murió o se perdió).
 * Si la llamada salió, se resuelve sola (la post-llamada por su `call_ref`, o
 * la conciliación por el SID de su evento). Si NO salió, no llega nada, sigue
 * viva para la concurrencia (`VoiceCall::LIVE`) y bloquea todas las salientes
 * hasta que alguien toque la base a mano. Aquí, las reglas de sus dos salidas:
 *
 *  - AUTOMÁTICA, en la conciliación ({@see VoiceReconciler}): solo con una
 *    pasada COMPLETA del operador (todas sus páginas listadas, sin truncar y
 *    sin cortar por el tope) que cubra la ventana de la fila
 *    ({@see windowCovered()}) y no vea NINGUNA llamada desconocida (la de esta
 *    fila lo sería: no tiene SID), y con la fila más vieja que el plazo de
 *    gracia y sin ningún id del proveedor en sus eventos ({@see rowHold()});
 *  - MANUAL, por consola (`voice:resolve-ambiguous`), con su motivo: las
 *    mismas reglas de la fila, sin la pasada (la decide una persona).
 *
 * El final es `failed_to_start` (D8-13: «no llegó a iniciarse»; la reserva se
 * libera, `VoiceCostGuard::RELEASED_STATUSES`, y no cuenta para la
 * frecuencia), con su evento `reconciled` de resultado {@see OUTCOME} y la
 * evidencia (la pasada y la ventana, o el motivo). FALLA CERRADO: ante
 * cualquier duda la fila se queda `ambiguous` y sigue contando como viva.
 *
 * EL ESQUEMA AÚN NO LO ADMITE: el grafo de D8-13 (DB8, disparador
 * `vc_status_forward`; DF-S3) no deja pasar de `ambiguous` a
 * `failed_to_start`, y en PostgreSQL `vc_provider_binding_chk` tampoco deja
 * `ambiguous` → `failed` sin un id del proveedor. Hasta la migración E19-3
 * (database-specialist, con la autorización del propietario), las dos salidas
 * dicen {@see HELD_SCHEMA} y no cambian nada ({@see schemaAllows()}).
 */
final class VoiceAmbiguousResolution
{
    /** D8-13: el final de «no llegó a iniciarse». */
    public const FINAL = VoiceCall::STATUS_FAILED_TO_START;

    /** El resultado del evento `reconciled` que deja dicha la salida. */
    public const OUTCOME = 'ambiguous_resolved';

    /** El resultado del evento `reconciled` de la anomalía: una post-llamada de una fila cerrada así (la llamada sí existió). */
    public const OUTCOME_REAPPEARED = 'resolved_call_reappeared';

    /**
     * El plazo de gracia, desde que la fila pasó a `ambiguous` (el último
     * instante en que pudo salir el POST: después, el job ya no marca). Cubre
     * de sobra lo que tarda en verse una llamada que sí salió: el timbre (como
     * mucho 60 s, `VOICE_OUTBOUND_RINGING_SECONDS`), la duración máxima (600 s,
     * el recorte de `VOICE_PER_CALL_MAX_SECONDS`) y el retraso de la
     * post-llamada y de la lista de Twilio (minutos): unos 12 min en el peor
     * caso, cinco veces. Y en una hora pasan cinco conciliaciones (:10…:50):
     * la llamada habría salido en varias pasadas completas.
     */
    public const GRACE_MINUTES = 60;

    /**
     * La pasada tiene que empezar su lista al menos esto ANTES de que se
     * pidiera la llamada (los relojes de Laravel y del operador; el filtro de
     * Twilio ya va por días, `StartTime>`).
     */
    public const CLOCK_SKEW_MINUTES = 5;

    /** La transición espera la migración E19-3. */
    public const HELD_SCHEMA = 'schema_pending';

    /** Más joven que el plazo de gracia. */
    public const HELD_WITHIN_GRACE = 'within_grace';

    /** Algún evento de la fila trae un id del proveedor (el job recibió un SID o una conversación): pudo salir. */
    public const HELD_PROVIDER_IDS = 'provider_ids';

    /** La pasada del operador no fue completa (sin credencial, un fallo, truncada o cortada por el tope de páginas). */
    public const HELD_PASS_INCOMPLETE = 'pass_incomplete';

    /** La pasada vio alguna llamada desconocida: pudo ser esta. */
    public const HELD_UNKNOWN_CALLS = 'unknown_calls';

    /** La lista de la pasada empieza después de que se pidiera la llamada. */
    public const HELD_WINDOW = 'window_not_covered';

    /** ¿Admite ya el esquema la salida? (El grafo de `VoiceCall`, el mismo que el disparador de DB8.) */
    public static function schemaAllows(): bool
    {
        return VoiceCall::canTransition(VoiceCall::STATUS_AMBIGUOUS, self::FINAL);
    }

    /**
     * Lo que impide cerrar ESTA fila por sí misma, o null: que no sea una
     * saliente `ambiguous` de Laravel, que no haya pasado el plazo de gracia o
     * que algún evento suyo traiga un id del proveedor.
     */
    public static function rowHold(VoiceCall $fila, DateTimeInterface $now): ?string
    {
        if ($fila->status !== VoiceCall::STATUS_AMBIGUOUS || $fila->direction !== VoiceCall::DIRECTION_OUTBOUND || $fila->transfer_of_call_id !== null) {
            return 'not_ambiguous';
        }
        $desde = $fila->status_changed_at ?? $fila->created_at;
        if ($desde === null || $desde->getTimestamp() > $now->getTimestamp() - self::GRACE_MINUTES * 60) {
            return self::HELD_WITHIN_GRACE;
        }
        if (self::hasProviderIds($fila)) {
            return self::HELD_PROVIDER_IDS;
        }

        return null;
    }

    /** ¿Cubre la lista de la pasada (desde `$since`) la ventana de la fila, con el margen de los relojes? */
    public static function windowCovered(VoiceCall $fila, DateTimeInterface $since): bool
    {
        return $fila->created_at !== null && $since->getTimestamp() <= $fila->created_at->getTimestamp() - self::CLOCK_SKEW_MINUTES * 60;
    }

    /**
     * ¿Trae la fila, o alguno de sus eventos, un id del proveedor? Una fila
     * `ambiguous` no admite ids (DB8): los que trajera el proveedor quedan en
     * sus eventos (`outbound_ambiguous`, `outbound_started`).
     */
    public static function hasProviderIds(VoiceCall $fila): bool
    {
        if ($fila->provider_call_sid !== null || $fila->provider_conversation_id !== null) {
            return true;
        }
        foreach (VoiceCallEvent::query()->where('call_id', $fila->id)->get(['codes']) as $e) {
            if (is_array($e->codes) && (($e->codes['callSid'] ?? null) !== null || ($e->codes['conversationId'] ?? null) !== null)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Se cerró la fila por esta salida? (`failed_to_start` con su evento `ambiguous_resolved`.) */
    public static function wasResolved(VoiceCall $fila): bool
    {
        return $fila->status === self::FINAL
            && VoiceCallEvent::query()->where('call_id', $fila->id)->where('kind', 'reconciled')->where('outcome', self::OUTCOME)->exists();
    }

    /** Los valores de la transición: el final, con su hora de fin (DB8, `vc_ended_chk`). @return array<string, mixed> */
    public static function transitionValues(): array
    {
        return ['ended_at' => now()];
    }
}
