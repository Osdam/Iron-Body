<?php

namespace App\Jobs\Voice;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCanaryDirectory;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\VoiceAdapters;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceCallStarter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * MARCAR una saliente ya aceptada por la puerta (contrato H8, D8-9, D8-12;
 * fixture `outbound-gate-cases.json` §job). El ÚNICO sitio que llama a
 * `VoiceCallStarter::startOutbound()` (TelephonyIsolationTest lo vigila).
 *
 * UN SOLO INTENTO, en su carril `voice` (worker `ironbody-voice`), con un
 * plazo de 30 s (el iniciador corta antes, a los 20). Nunca reintenta: un
 * tiempo agotado, un 5xx o un fallo que no prueba que no se marcó deja la
 * llamada `ambiguous`, y la resuelve la conciliación antes de cualquier
 * reintento manual (que sería OTRA fila).
 *
 * Antes de marcar vuelve a mirar, en este orden (DF-G9), y si algo falla
 * cancela SIN PETICIÓN (la reserva se libera):
 *  1. la constante y las banderas (`calls_disabled_in_code`,
 *     `outbound_disabled`, `twilio_voice_disabled`);
 *  2. el freno (`voice_braked`);
 *  3. la entrada vigente de su parte de la cadena: si su `seq` no es la
 *     sellada, `publication_moved` (D8-9: conservador a propósito);
 *  4. la deriva del agente sellado, con su caché de 5 min (D8-21): una
 *     proyección observada distinta es `agent_drift` y abre el freno; sin
 *     poder observarla, `drift_unverified`;
 *  5. el destino, descifrado SOLO EN MEMORIA, que tiene que seguir siendo
 *     el sellado (su huella); si no se puede, `error` (DF-G8).
 * La frecuencia y los cupos no se repiten: ya están reservados.
 */
class PlaceOutboundVoiceCall implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $callId)
    {
        $this->onConnection('voice');
        $this->onQueue('voice');
    }

    public function handle(): void
    {
        $this->run(VoiceAdapters::callStarter());
    }

    /**
     * El cuerpo del job con el iniciador inyectado (las pruebas pasan uno
     * falso; `handle()` usa la fábrica). Devuelve el estado en que queda.
     */
    public function run(VoiceCallStarter $starter): ?string
    {
        $llamada = VoiceCall::query()->find($this->callId);
        if ($llamada === null || $llamada->status !== VoiceCall::STATUS_REQUESTED) {
            return $llamada?->status;
        }

        $motivo = $this->recheck($llamada);
        if ($motivo !== null) {
            return $this->cancel($llamada, $motivo);
        }

        try {
            $peticion = $this->request($llamada);
        } catch (InvalidArgumentException) {
            // Un fallo local antes de enviar (el número no se descifra, falta el
            // id del número importado…): sin petición (DF-G8).
            $peticion = null;
        }
        if ($peticion === null) {
            return $this->cancel($llamada, 'error');
        }

        $resultado = $starter->startOutbound($peticion);
        unset($peticion);

        return $this->record($llamada, $resultado);
    }

    /** El job murió o agotó su plazo: no se sabe si se marcó → `ambiguous`, nunca un reintento. */
    public function failed(?Throwable $e): void
    {
        $llamada = VoiceCall::query()->find($this->callId);
        if ($llamada === null) {
            return;
        }
        if (VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_AMBIGUOUS, ['failure_code' => 'job_failed'])) {
            VoiceEvents::record('outbound_ambiguous', (int) $llamada->id, VoiceCallEvent::PROVIDER_LARAVEL, 'job:'.$llamada->uuid, [
                'outcome' => 'job_failed', 'codes' => ['error_class' => $e === null ? null : $e::class],
            ]);
        }
        Log::channel('voice')->error('voice.outbound.job_failed', ['call_uuid' => $llamada->uuid, 'error_class' => $e === null ? null : $e::class]);
    }

    private function recheck(VoiceCall $llamada): ?string
    {
        if (VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== true) {
            return 'calls_disabled_in_code';
        }
        if (config('voice.outbound.enabled') !== true) {
            return 'outbound_disabled';
        }
        if (config('voice.twilio.enabled') !== true) {
            return 'twilio_voice_disabled';
        }
        if (VoiceBrake::isOpen()) {
            return 'voice_braked';
        }
        $parte = VoicePublishedConfig::read()[(string) $llamada->part] ?? null;
        if ($parte === null || ! $parte->ok() || $parte->seq !== $llamada->voice_publication_seq || $parte->providerAgentId !== $llamada->provider_agent_id) {
            return 'publication_moved';
        }

        $deriva = Cache::get(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId);
        if ($deriva === null) {
            $observacion = VoiceAdapters::deployer()->describeAgent((string) $parte->providerAgentId);
            if ($observacion->outcome !== VoiceAgentObservation::OUTCOME_OBSERVED) {
                return 'drift_unverified';
            }
            $deriva = ! hash_equals((string) $parte->projectionHash, (string) $observacion->projectionHash);
            Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId, $deriva, OutboundGate::DRIFT_CACHE_SECONDS);
        }
        if ($deriva === true) {
            VoiceBrake::open('agent_drift', 'drift:'.$parte->providerAgentId, ['where' => 'outbound_job', 'seq' => $parte->seq, 'part' => $parte->part], (int) $llamada->id);

            return 'agent_drift';
        }

        return null;
    }

    /** La petición, con el número descifrado en memoria. null si el destino ya no es el sellado. */
    private function request(VoiceCall $llamada): ?OutboundCallRequest
    {
        $numero = null;
        if ($llamada->target_source === VoiceCallContext::SOURCE_CANARY_REGISTRY && $llamada->canary_number_uuid !== null) {
            $canario = VoiceCanaryDirectory::forDial((string) $llamada->canary_number_uuid);
            $numero = $canario !== null && $canario['state'] === VoiceCallContext::CANARY_ACTIVE_VOICE ? $canario['number']?->getValue() : null;
        } elseif ($llamada->target_source === VoiceCallContext::SOURCE_VOICE_CONTACTS && $llamada->voice_contact_id !== null) {
            $contacto = VoiceContact::query()->whereKey($llamada->voice_contact_id)->where('status', VoiceContact::STATUS_ACTIVE)->first();
            try {
                $numero = $contacto?->phone_encrypted;
            } catch (Throwable) {
                // Un cifrado ilegible: no se marca.
                $numero = null;
            }
        }
        if (! VoicePhone::isE164($numero) || ! hash_equals((string) $llamada->remote_hash, VoicePhone::hash($numero))) {
            return null;
        }

        return new OutboundCallRequest(
            agentId: (string) $llamada->provider_agent_id,
            phoneNumberRef: (string) config('voice.elevenlabs.agent_phone_number_id'),
            toNumber: $numero,
            callRef: (string) $llamada->call_ref,
            dynamicVariables: [
                VoiceAgentSpec::VAR_CALLER_AUTHORIZED => VoiceAgentSpec::CALLER_AUTHORIZED,
                VoiceAgentSpec::VAR_CALL_DIRECTION => VoiceAgentSpec::DIRECTION_OUTBOUND,
                VoiceAgentSpec::VAR_CALL_REF => (string) $llamada->call_ref,
                VoiceAgentSpec::VAR_VERSION_LABEL => ($llamada->part === VoicePublishedConfig::PART_REST ? 'resto' : 'canario').' · publicación '.$llamada->voice_publication_seq,
            ],
            ringingTimeoutSeconds: (int) config('voice.outbound.ringing_timeout_seconds', 30),
            voicemailPolicy: VoiceAgentSpec::VOICEMAIL_HANG_UP,
        );
    }

    private function record(VoiceCall $llamada, OutboundCallResult $r): string
    {
        $id = (int) $llamada->id;
        $codigos = ['reason' => $r->reason, 'http' => $r->httpStatus];

        return DB::transaction(function () use ($id, $llamada, $r, $codigos): string {
            $fila = VoiceCall::query()->lockForUpdate()->find($id);

            switch ($r->outcome) {
                case OutboundCallResult::OUTCOME_STARTED:
                    $valores = ['started_at' => now()];
                    if ($fila->provider_conversation_id === null) {
                        $valores['provider_conversation_id'] = $r->conversationId;
                    }
                    if ($fila->provider_call_sid === null) {
                        $valores['provider_call_sid'] = $r->callSid;
                    }
                    // El webhook pudo llegar antes y dejarla en un final (DF-S2): entonces ya no se escribe `dialing`.
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_DIALING, $valores);
                    VoiceEvents::record('outbound_started', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, (string) $r->conversationId, ['outcome' => 'started', 'codes' => $codigos]);
                    break;

                case OutboundCallResult::OUTCOME_REJECTED:
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_FAILED_TO_START, ['failure_code' => $r->reason, 'ended_at' => now()]);
                    VoiceEvents::record('outbound_rejected', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'rej:'.$llamada->uuid, ['outcome' => (string) $r->reason, 'codes' => $codigos]);
                    break;

                case OutboundCallResult::OUTCOME_AMBIGUOUS:
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_AMBIGUOUS, ['failure_code' => $r->reason]);
                    // Los ids que vinieran se guardan en el evento (la fila ambigua no los admite, DB8) para conciliar.
                    VoiceEvents::record('outbound_ambiguous', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'amb:'.$llamada->uuid, [
                        'outcome' => (string) $r->reason, 'codes' => $codigos + ['conversationId' => $r->conversationId, 'callSid' => $r->callSid],
                    ]);
                    break;

                default:
                    // not_attempted: no salió nada a la red (la constante o la configuración incompleta).
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => (string) $r->reason, 'ended_at' => now()]);
                    break;
            }
            Log::channel('voice')->info('voice.outbound.result', ['call_uuid' => $llamada->uuid, 'outcome' => $r->outcome, 'reason' => $r->reason, 'http_status' => $r->httpStatus]);

            return (string) VoiceCall::query()->whereKey($id)->value('status');
        });
    }

    private function cancel(VoiceCall $llamada, string $motivo): string
    {
        VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => $motivo, 'ended_at' => now()]);
        Log::channel('voice')->info('voice.outbound.canceled', ['call_uuid' => $llamada->uuid, 'reason' => $motivo]);

        return (string) VoiceCall::query()->whereKey($llamada->id)->value('status');
    }
}
