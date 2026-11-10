<?php

namespace App\Services\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\TelephonyCarrier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El CORTE de emergencia (contrato H8, D8-20 nivel 0b): la llamada a
 * `completed` en Twilio con la key de Laravel, y en Laravel a `hung_up`.
 * Idempotente. Una saliente aún sin marcar (`requested`) se cancela sin red
 * (DF-S9: el corte de una pedida es `canceled`); una `ambiguous` no se puede
 * cortar sin su llamada de Twilio: la resuelve la conciliación primero.
 *
 * Funciona con la voz ya apagada (las banderas no lo impiden): cortar es lo
 * que se hace cuando algo va mal.
 *
 * GANA EL CORTE (E10-5, SEC8-b M-3) en los dos órdenes de la carrera con el
 * job, que no tiene cerrojo mientras espera al proveedor:
 *  - el corte llega primero (`requested` → `canceled`) y el proveedor la
 *    inicia igual: el job la cuelga al volver ({@see startedAfterCancel()});
 *  - el job marca primero (`dialing`) entre la lectura de la fila y el corte:
 *    la cancelación no aplica y el corte sigue por la vía de una llamada viva.
 * Y FALLA CERRADO (E12-5, B8-e): si el colgado de la que se inició después
 * del corte no se confirma, el freno se echa en el acto.
 *
 * UNA CORTADA SE PUEDE VOLVER A COLGAR A MANO (E12-5, B8-g: `voice:hangup` y el
 * CRM): si el proveedor la inició después del corte (su SID en el evento con
 * `afterCancel`) y su último colgado no se confirmó, cortarla repite el colgado
 * con {@see startedAfterCancel()} (`where: manual`, con la persona si la hay),
 * que también frena si no se confirma. Sin SID, o con el colgado ya
 * confirmado, no hay nada que colgar (`not_live`, sin red).
 */
final class VoiceHangup
{
    public const RESULT_HUNG_UP = 'hung_up';

    public const RESULT_CANCELED = 'canceled';

    public const RESULT_NOT_LIVE = 'not_live';

    public const RESULT_NO_CARRIER_ID = 'no_carrier_id';

    /*
     * E12-5 (B8-e): el `outcome` del `hangup_requested` del SISTEMA que cuelga
     * una saliente iniciada después del corte ({@see afterCancelResult()}; lo
     * que dijo el transportista queda en `codes.carrierOutcome`):
     *  - `hung_up`: aceptó el cambio a `completed` (`requested`);
     *  - `not_found`: la llamada no existe (404): nada que colgar;
     *  - `failed`: dijo que no o no se pudo pedir (`rejected`, `auth_failed`,
     *    `not_configured`, `invalid_request`);
     *  - `ambiguous`: no se sabe si surtió efecto (`unreachable`, `error`, o el
     *    adaptador lanzó).
     */
    public const AFTER_CANCEL_FAILED = 'failed';

    public const AFTER_CANCEL_AMBIGUOUS = 'ambiguous';

    /** Los únicos que CONFIRMAN el corte: sin uno de estos, freno y la conciliación no la reconoce. */
    public const AFTER_CANCEL_CONFIRMED = [self::RESULT_HUNG_UP, CarrierResult::OUTCOME_NOT_FOUND];

    /** Quién cuelga después del corte: el job al volver del proveedor, la conciliación que la ve viva o una persona (B8-g). */
    public const WHERE_JOB = 'outbound_job';

    public const WHERE_RECONCILE = 'reconcile';

    public const WHERE_MANUAL = 'manual';

    /**
     * B8-g: cuánto después del corte puede seguir viva una saliente que el
     * proveedor inició igual. El job vuelve en ≤ 30 s, el timbre dura ≤ 60 s y
     * la llamada ≤ 600 s (`VOICE_PER_CALL_MAX_SECONDS`, techo del esquema): unos
     * 12 minutos. `voice:hangup --all` repite el colgado de las de esta
     * ventana; las anteriores ya no pueden estar vivas y las vigila la
     * conciliación (desconocidas y freno).
     */
    public const AFTER_CANCEL_LIVE_WINDOW_MINUTES = 60;

    /** La causa del freno de un colgado sin confirmar (motivo `unknown_provider_call`, {@see VoiceBrake::TRIGGERS}). */
    public const BRAKE_CAUSE = 'hangup_failed';

    /** Los eventos donde el job dejó el SID de una saliente iniciada después del corte (`afterCancel`). */
    public const AFTER_CANCEL_START_KINDS = ['outbound_started', 'outbound_ambiguous'];

    /** @return array{result: string, carrier: ?string, status: string} */
    public static function hangup(VoiceCall $llamada, TelephonyCarrier $carrier, ?Admin $actor): array
    {
        $id = (int) $llamada->id;
        $quien = $actor === null
            ? ['actor_type' => VoiceCallEvent::ACTOR_SYSTEM]
            : ['actor_type' => VoiceCallEvent::ACTOR_ADMIN, 'actor_admin_id' => $actor->id, 'actor_name' => (string) $actor->name, 'actor_role' => $actor->role];

        if ($llamada->status === VoiceCall::STATUS_REQUESTED) {
            if (VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => 'hangup', 'ended_at' => now()])) {
                VoiceEvents::record('hangup_requested', $id, VoiceCallEvent::PROVIDER_LARAVEL, null, $quien + ['outcome' => 'canceled_before_dial']);

                return ['result' => self::RESULT_CANCELED, 'carrier' => null, 'status' => self::status($id)];
            }
            // Ya no estaba pedida (E10-5): si el job la marcó entre tanto, se corta como una viva.
            $llamada = VoiceCall::query()->find($id) ?? $llamada;
        }
        if ($llamada->status === VoiceCall::STATUS_CANCELED) {
            return self::hangupCanceled($llamada, $carrier, $actor);
        }
        if (! in_array($llamada->status, [VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE], true)) {
            return ['result' => self::RESULT_NOT_LIVE, 'carrier' => null, 'status' => (string) $llamada->status];
        }
        if ($llamada->provider_call_sid === null) {
            VoiceEvents::record('hangup_requested', $id, VoiceCallEvent::PROVIDER_LARAVEL, null, $quien + ['outcome' => self::RESULT_NO_CARRIER_ID]);

            return ['result' => self::RESULT_NO_CARRIER_ID, 'carrier' => null, 'status' => (string) $llamada->status];
        }

        $r = $carrier->hangup((string) $llamada->provider_call_sid);
        VoiceEvents::record('hangup_requested', $id, VoiceCallEvent::PROVIDER_LARAVEL, null, $quien + [
            'outcome' => $r->outcome, 'codes' => ['carrierStatus' => $r->providerStatus, 'http' => $r->httpStatus, 'errorCode' => $r->providerErrorCode],
        ]);
        $hecho = in_array($r->outcome, [CarrierResult::OUTCOME_REQUESTED, CarrierResult::OUTCOME_NOT_FOUND], true)
            && VoiceCall::transition($id, [VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE], VoiceCall::STATUS_HUNG_UP, ['termination_reason' => 'emergency_hangup', 'ended_at' => now()]);
        Log::channel('voice')->warning('voice.hangup', ['call_uuid' => $llamada->uuid, 'carrier_outcome' => $r->outcome, 'hung_up' => $hecho]);

        return ['result' => $hecho ? self::RESULT_HUNG_UP : $r->outcome, 'carrier' => $r->outcome, 'status' => self::status($id)];
    }

    /**
     * E10-5: la saliente se cortó (`canceled`) mientras el job esperaba al
     * proveedor y el proveedor la inició igual: se cuelga EN EL ACTO la llamada
     * recién iniciada (Twilio a `completed`, con la key de Laravel). La fila no
     * cambia (un final no cambia, DB8); queda `hangup_requested`, actor sistema,
     * con su resultado ({@see afterCancelResult()}) y el SID en sus códigos (la
     * conciliación lo casa por él; el detalle de la llamada no lo enseña).
     *
     * FALLA CERRADO (E12-5, B8-e): si el colgado no se confirma (el
     * transportista dice que no, no se sabe si surtió efecto o el adaptador
     * lanza), la llamada pudo seguir hasta su máximo (≤ 300 s): el FRENO se echa
     * en el acto, en la misma transacción que el evento y con la clave de la
     * conciliación para ese SID (`unknown:<sid>`, {@see brakeKey()}), así que
     * ninguna de las dos duplica la apertura. Se rearma si, ya soltado, un
     * intento posterior vuelve a fallar ({@see VoiceBrake::observedAfter()}).
     *
     * La llaman el job al volver del proveedor, la conciliación que la ve viva
     * y, B8-g, una persona que vuelve a cortar la cortada (`$where`; su
     * identidad en el evento, `$actor`), siempre FUERA de una transacción: sin
     * cerrojo durante la red.
     *
     * @return string `hung_up`, `not_found`, `failed` o `ambiguous`
     */
    public static function startedAfterCancel(VoiceCall $llamada, string $callSid, TelephonyCarrier $carrier, string $where = self::WHERE_JOB, ?Admin $actor = null): string
    {
        return self::afterCancelHangup($llamada, $callSid, $carrier, $where, $actor)['result'];
    }

    /**
     * El cuerpo de {@see startedAfterCancel()}, con lo que dijo el transportista.
     *
     * @return array{result: string, carrier: ?string}
     */
    private static function afterCancelHangup(VoiceCall $llamada, string $callSid, TelephonyCarrier $carrier, string $where, ?Admin $actor): array
    {
        $intento = now();
        $r = null;
        $fallo = null;
        try {
            $r = $carrier->hangup($callSid);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea, como en el adaptador.
            throw $e;
        } catch (Throwable $e) {
            // El adaptador no lanza por diseño; si lanza, no se sabe si la petición llegó. No se traga: su clase
            // queda en el evento y en el registro, y echa el freno. No se relanza: el freno ya protege, y en la
            // conciliación relanzar dejaría la pasada sin el resto (las demás llamadas, la deriva, el techo).
            $fallo = $e;
        }
        $resultado = $r === null ? self::AFTER_CANCEL_AMBIGUOUS : self::afterCancelResult($r->outcome);
        $confirmado = in_array($resultado, self::AFTER_CANCEL_CONFIRMED, true);

        $quien = $actor === null
            ? ['actor_type' => VoiceCallEvent::ACTOR_SYSTEM]
            : ['actor_type' => VoiceCallEvent::ACTOR_ADMIN, 'actor_admin_id' => $actor->id, 'actor_name' => (string) $actor->name, 'actor_role' => $actor->role];

        DB::transaction(static function () use ($llamada, $callSid, $where, $r, $fallo, $resultado, $confirmado, $intento, $quien): void {
            $evento = VoiceEvents::record('hangup_requested', (int) $llamada->id, VoiceCallEvent::PROVIDER_LARAVEL, null, $quien + [
                'outcome' => $resultado,
                'codes' => ['carrierStatus' => $r?->providerStatus, 'http' => $r?->httpStatus, 'errorCode' => $r?->providerErrorCode, 'afterCancel' => true,
                    'callSid' => $callSid, 'carrierOutcome' => $r?->outcome]
                    + ($fallo === null ? [] : ['error_class' => $fallo::class])
                    + ($where === self::WHERE_JOB ? [] : ['where' => $where]),
            ]);
            if (! $confirmado) {
                VoiceBrake::openOrRearm(self::BRAKE_CAUSE, self::brakeKey($callSid), ['where' => $where, 'outcome' => $resultado, 'http' => $r?->httpStatus],
                    VoiceBrake::observedAfter($intento), (int) $llamada->id, $evento?->id === null ? null : (int) $evento->id);
            }
        });

        $contexto = ['call_uuid' => $llamada->uuid, 'where' => $where, 'outcome' => $resultado, 'carrier_outcome' => $r?->outcome]
            + ($actor === null ? [] : ['actor_admin_id' => $actor->id]);
        if ($confirmado) {
            Log::channel('voice')->warning('voice.hangup.after_cancel', $contexto);
        } else {
            Log::channel('voice')->error('voice.hangup.after_cancel_failed', $contexto + ($fallo === null ? [] : ['error_class' => $fallo::class]));
        }

        return ['result' => $resultado, 'carrier' => $r?->outcome];
    }

    /**
     * E12-5 (B8-g): cortar una saliente ya CORTADA. Si el proveedor la inició
     * después del corte y su último colgado no se confirmó, se repite el
     * colgado (`where: manual`), que frena si tampoco se confirma. Si no hay
     * SID o ya se confirmó, no hay nada que colgar: `not_live`, sin red. La
     * fila no cambia (un final no cambia, DB8).
     *
     * @return array{result: string, carrier: ?string, status: string}
     */
    private static function hangupCanceled(VoiceCall $llamada, TelephonyCarrier $carrier, ?Admin $actor): array
    {
        $sid = self::afterCancelSid($llamada);
        if ($sid === null || in_array(self::lastAfterCancelResult((int) $llamada->id, $sid), self::AFTER_CANCEL_CONFIRMED, true)) {
            return ['result' => self::RESULT_NOT_LIVE, 'carrier' => null, 'status' => (string) $llamada->status];
        }
        $r = self::afterCancelHangup($llamada, $sid, $carrier, self::WHERE_MANUAL, $actor);

        return [
            // Ya no existe (404): nada que colgar, como una que no está viva.
            'result' => $r['result'] === CarrierResult::OUTCOME_NOT_FOUND ? self::RESULT_NOT_LIVE : $r['result'],
            'carrier' => $r['carrier'],
            'status' => self::status((int) $llamada->id),
        ];
    }

    /**
     * B8-g: el SID de la llamada que el proveedor inició DESPUÉS del corte de
     * esta saliente (el último evento `outbound_started`/`outbound_ambiguous`
     * con `afterCancel`), o null si no lo hubo.
     */
    public static function afterCancelSid(VoiceCall $llamada): ?string
    {
        if ($llamada->status !== VoiceCall::STATUS_CANCELED) {
            return null;
        }

        return VoiceCallEvent::query()->where('call_id', $llamada->id)->whereIn('kind', self::AFTER_CANCEL_START_KINDS)->orderByDesc('id')->limit(20)->get(['codes'])
            ->map(static fn (VoiceCallEvent $e): mixed => is_array($e->codes) && ($e->codes['afterCancel'] ?? false) === true ? ($e->codes['callSid'] ?? null) : null)
            ->first(static fn (mixed $s): bool => is_string($s));
    }

    /**
     * B8-g (`voice:hangup --all`): las salientes CORTADAS dentro de
     * {@see AFTER_CANCEL_LIVE_WINDOW_MINUTES} que el proveedor inició igual y
     * cuyo último colgado no se confirmó: las que aún pueden estar vivas.
     *
     * @return Collection<int, VoiceCall>
     */
    public static function unconfirmedAfterCancel(): Collection
    {
        return VoiceCall::query()->where('status', VoiceCall::STATUS_CANCELED)->where('direction', VoiceCall::DIRECTION_OUTBOUND)->whereNull('transfer_of_call_id')
            ->where('status_changed_at', '>=', now()->subMinutes(self::AFTER_CANCEL_LIVE_WINDOW_MINUTES))
            ->orderBy('id')->get()
            ->filter(static function (VoiceCall $c): bool {
                $sid = self::afterCancelSid($c);

                return $sid !== null && ! in_array(self::lastAfterCancelResult((int) $c->id, $sid), self::AFTER_CANCEL_CONFIRMED, true);
            })
            ->values();
    }

    /**
     * E12-5 (B8-e): lo que dijo el transportista → el resultado del colgado
     * después del corte. Lo que no confirma el corte, en el sentido seguro: un
     * resultado que no se conoce es `failed`.
     *
     * EL 21220 (E14-4; calibración documentada en B8-g, `informe-backend.md`
     * §B8-g): un 400 con el código 21220 («Invalid call state»: la operación en
     * tiempo real llegó a una llamada que no está `in-progress`) es `rejected` →
     * `failed`, y frena. La referencia oficial no lo describe para
     * `Status=completed` (solo grabar o redirigir una llamada terminada); darlo
     * por confirmado fallaría abierto si viniera de una llamada viva. Se queda
     * así hasta medirlo en la fase 4a con respuestas reales (el código queda en
     * `codes.errorCode` del evento y Twilio da el `end_time` en la conciliación).
     */
    public static function afterCancelResult(string $carrierOutcome): string
    {
        return match ($carrierOutcome) {
            CarrierResult::OUTCOME_REQUESTED => self::RESULT_HUNG_UP,
            CarrierResult::OUTCOME_NOT_FOUND => CarrierResult::OUTCOME_NOT_FOUND,
            CarrierResult::OUTCOME_UNREACHABLE, CarrierResult::OUTCOME_ERROR => self::AFTER_CANCEL_AMBIGUOUS,
            default => self::AFTER_CANCEL_FAILED,
        };
    }

    /**
     * La clave del freno de una llamada de Twilio que nadie controla: la misma
     * que usa la conciliación para una desconocida ({@see VoiceReconciler::one()}),
     * para que el job y la conciliación no dupliquen la apertura.
     */
    public static function brakeKey(string $callSid): string
    {
        return 'unknown:'.$callSid;
    }

    /**
     * E12-5 (B8-e): el resultado del ÚLTIMO colgado después del corte de esa
     * saliente con ese SID (el más reciente manda: un reintento que falla anula
     * un «colgada» anterior), o null si nunca se colgó (el proceso murió antes).
     * Del sistema (el job, la conciliación, `voice:hangup`) o, B8-g, de una
     * persona que la volvió a cortar desde el CRM: solo
     * {@see afterCancelHangup()} escribe un `hangup_requested` con `afterCancel`.
     */
    public static function lastAfterCancelResult(int $callId, string $callSid): ?string
    {
        $ultimo = VoiceCallEvent::query()->where('call_id', $callId)->where('kind', 'hangup_requested')
            ->orderByDesc('id')->limit(200)->get(['outcome', 'codes'])
            ->first(static fn (VoiceCallEvent $e): bool => is_array($e->codes) && ($e->codes['afterCancel'] ?? false) === true && ($e->codes['callSid'] ?? null) === $callSid);

        return $ultimo?->outcome;
    }

    /**
     * E12-5 (B8-e): el job murió (o agotó su plazo: 20 s del proveedor más 10 s
     * de Twilio llegan a sus 30 s) después de que el proveedor iniciara una
     * saliente YA cortada (su SID en el evento con `afterCancel`) y ningún
     * colgado lo confirmó: el freno, en el acto y con la misma clave (no
     * duplica). Devuelve si abrió una apertura.
     */
    public static function brakeIfUnconfirmedAfterCancel(VoiceCall $llamada): bool
    {
        $sid = self::afterCancelSid($llamada);
        if ($sid === null || in_array(self::lastAfterCancelResult((int) $llamada->id, $sid), self::AFTER_CANCEL_CONFIRMED, true)) {
            return false;
        }
        $abierta = VoiceBrake::openOrRearm(self::BRAKE_CAUSE, self::brakeKey($sid), ['where' => self::WHERE_JOB, 'outcome' => 'job_failed', 'http' => null],
            VoiceBrake::observedAfter(now()), (int) $llamada->id);
        Log::channel('voice')->error('voice.hangup.after_cancel_unconfirmed', ['call_uuid' => $llamada->uuid, 'brake_opened' => $abierta !== null]);

        return $abierta !== null;
    }

    private static function status(int $id): string
    {
        return (string) VoiceCall::query()->whereKey($id)->value('status');
    }
}
