<?php

namespace App\Services\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\TelephonyCarrier;
use Illuminate\Support\Facades\Log;

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
 */
final class VoiceHangup
{
    public const RESULT_HUNG_UP = 'hung_up';

    public const RESULT_CANCELED = 'canceled';

    public const RESULT_NOT_LIVE = 'not_live';

    public const RESULT_NO_CARRIER_ID = 'no_carrier_id';

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
     * cambia (un final no cambia, DB8); queda `hangup_requested` con lo que dijo
     * el transportista. Si no se pudo colgar, error en el registro: la
     * conciliación verá la llamada viva y echará el freno.
     */
    public static function startedAfterCancel(VoiceCall $llamada, string $callSid, TelephonyCarrier $carrier): string
    {
        $r = $carrier->hangup($callSid);
        VoiceEvents::record('hangup_requested', (int) $llamada->id, VoiceCallEvent::PROVIDER_LARAVEL, null, [
            'actor_type' => VoiceCallEvent::ACTOR_SYSTEM, 'outcome' => $r->outcome,
            'codes' => ['carrierStatus' => $r->providerStatus, 'http' => $r->httpStatus, 'errorCode' => $r->providerErrorCode, 'afterCancel' => true],
        ]);
        if (in_array($r->outcome, [CarrierResult::OUTCOME_REQUESTED, CarrierResult::OUTCOME_NOT_FOUND], true)) {
            Log::channel('voice')->warning('voice.hangup.after_cancel', ['call_uuid' => $llamada->uuid, 'carrier_outcome' => $r->outcome]);
        } else {
            Log::channel('voice')->error('voice.hangup.after_cancel_failed', ['call_uuid' => $llamada->uuid, 'carrier_outcome' => $r->outcome]);
        }

        return $r->outcome;
    }

    private static function status(int $id): string
    {
        return (string) VoiceCall::query()->whereKey($id)->value('status');
    }
}
