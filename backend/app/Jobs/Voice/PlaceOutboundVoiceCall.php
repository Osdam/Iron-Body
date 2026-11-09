<?php

namespace App\Jobs\Voice;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCanaryDirectory;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceHangup;
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
 * cancela SIN PETICIÓN (`not_attempted`: la reserva se libera, sin coste):
 *  1. la constante y las banderas (`calls_disabled_in_code`,
 *     `outbound_disabled`, `twilio_voice_disabled`);
 *  2. el freno (`voice_braked`);
 *  3. la entrada vigente de su parte de la cadena: si su `seq` no es la
 *     sellada, `publication_moved` (D8-9: conservador a propósito);
 *  4. la deriva del agente sellado, con su caché de 5 min (D8-21): una
 *     proyección observada distinta es `agent_drift` y abre el freno; sin
 *     poder observarla, `drift_unverified`;
 *  5. EL DESTINO Y EL CALENDARIO, con EXACTAMENTE las reglas y los motivos
 *     de la puerta ({@see OutboundPolicy::targetReasons()} sobre
 *     {@see OutboundGate::targetFacts()}; revisión de seguridad, TOCTOU):
 *     entre encolar y marcar pudo revocarse el consentimiento
 *     (`no_voice_consent`), añadirse una exclusión (`excluded`: la del
 *     número, también la sellada, la oral, la del lead o el RNE marcado),
 *     caducar la consulta al RNE (`rne_check_missing`), dejar de ser un
 *     canario activo o un contacto activo, o cerrarse la ventana legal o
 *     propia (`outside_hours`) o empezar un festivo (`holiday`), a la hora
 *     en que corre el job;
 *  6. el destino, descifrado SOLO EN MEMORIA, que tiene que seguir siendo
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

    /**
     * El job murió o agotó su plazo: no se sabe si se marcó → `ambiguous`, nunca
     * un reintento. Si murió con una saliente YA cortada que el proveedor inició
     * y sin un colgado confirmado (p. ej. a mitad del colgado), el freno en el
     * acto (E12-5, B8-e; {@see VoiceHangup::brakeIfUnconfirmedAfterCancel()}).
     */
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
        VoiceHangup::brakeIfUnconfirmedAfterCancel($llamada);
    }

    private function recheck(VoiceCall $llamada): ?string
    {
        if (VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== true) {
            return 'calls_disabled_in_code';
        }

        return $this->recheckAfterCode($llamada);
    }

    /**
     * Los pasos 1 (banderas) a 5 de DF-G9, ya comprobada la constante. Aparte
     * solo para que las pruebas los recorran en el árbol compartido, donde la
     * constante está cerrada y `run()` nunca pasa de ella: no es un atajo
     * (solo lo llama {@see recheck()} y no marca nada).
     */
    private function recheckAfterCode(VoiceCall $llamada): ?string
    {
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
        // E10-6: solo una observación hecha AQUÍ puede rearmar un freno soltado;
        // el veredicto de la caché es de antes (la misma observación).
        $rearma = static fn (): bool => false;
        if ($deriva === null) {
            $observada = now();
            $observacion = VoiceAdapters::deployer()->describeAgent((string) $parte->providerAgentId);
            if ($observacion->outcome !== VoiceAgentObservation::OUTCOME_OBSERVED) {
                return 'drift_unverified';
            }
            $deriva = ! hash_equals((string) $parte->projectionHash, (string) $observacion->projectionHash);
            Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId, $deriva, OutboundGate::DRIFT_CACHE_SECONDS);
            $rearma = VoiceBrake::observedAfter($observada);
        }
        if ($deriva === true) {
            VoiceBrake::openOrRearm('agent_drift', 'drift:'.$parte->providerAgentId, ['where' => 'outbound_job', 'seq' => $parte->seq, 'part' => $parte->part], $rearma, (int) $llamada->id);

            return 'agent_drift';
        }

        // 5. El destino y el calendario, justo antes de marcar y con las reglas de la puerta.
        $motivos = OutboundPolicy::targetReasons(OutboundGate::targetFacts(OutboundGate::targetOf($llamada), OutboundGate::currentScope(), now(), $llamada->remote_hash));
        if ($motivos !== []) {
            Log::channel('voice')->info('voice.outbound.target_recheck', ['call_uuid' => $llamada->uuid, 'reasons' => $motivos]);

            return $motivos[0];
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

    /**
     * Lo que dejó el proveedor. GANA EL CORTE (E10-5, SEC8-b M-3): si mientras
     * el job esperaba una persona cortó la saliente (`requested` → `canceled`,
     * {@see VoiceHangup}) y el proveedor la inició igual, la fila no cambia (un
     * final no cambia, DB8), sus ids quedan en el evento (`afterCancel`; la
     * fila `canceled` no los admite) para que la conciliación la reconozca, y
     * la llamada recién iniciada se cuelga EN EL ACTO con el transportista,
     * fuera de la transacción (sin cerrojo durante la red). Si ese colgado no
     * se confirma, el freno se echa en el acto (E12-5, B8-e) y la conciliación
     * no la reconoce hasta que un colgado la confirme.
     */
    private function record(VoiceCall $llamada, OutboundCallResult $r): string
    {
        $id = (int) $llamada->id;
        $codigos = ['reason' => $r->reason, 'http' => $r->httpStatus];
        $colgar = null;

        $estado = DB::transaction(function () use ($id, $llamada, $r, $codigos, &$colgar): string {
            $fila = VoiceCall::query()->lockForUpdate()->find($id);
            $cortada = $fila->status === VoiceCall::STATUS_CANCELED;
            if ($cortada && $r->callSid !== null && in_array($r->outcome, [OutboundCallResult::OUTCOME_STARTED, OutboundCallResult::OUTCOME_AMBIGUOUS], true)) {
                $colgar = $r->callSid;
            }

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
                    VoiceEvents::record('outbound_started', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, (string) $r->conversationId, ['outcome' => 'started',
                        'codes' => $codigos + ($cortada ? ['conversationId' => $r->conversationId, 'callSid' => $r->callSid, 'afterCancel' => true] : [])]);
                    break;

                case OutboundCallResult::OUTCOME_REJECTED:
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_FAILED_TO_START, ['failure_code' => $r->reason, 'ended_at' => now()]);
                    VoiceEvents::record('outbound_rejected', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'rej:'.$llamada->uuid, ['outcome' => (string) $r->reason, 'codes' => $codigos]);
                    break;

                case OutboundCallResult::OUTCOME_AMBIGUOUS:
                    VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_AMBIGUOUS, ['failure_code' => $r->reason]);
                    // Los ids que vinieran se guardan en el evento (la fila ambigua no los admite, DB8) para conciliar.
                    VoiceEvents::record('outbound_ambiguous', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'amb:'.$llamada->uuid, [
                        'outcome' => (string) $r->reason, 'codes' => $codigos + ['conversationId' => $r->conversationId, 'callSid' => $r->callSid] + ($cortada ? ['afterCancel' => true] : []),
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

        if ($colgar !== null) {
            VoiceHangup::startedAfterCancel($llamada, $colgar, VoiceAdapters::carrier());
        }

        return $estado;
    }

    private function cancel(VoiceCall $llamada, string $motivo): string
    {
        VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => $motivo, 'ended_at' => now()]);
        Log::channel('voice')->info('voice.outbound.canceled', ['call_uuid' => $llamada->uuid, 'reason' => $motivo]);

        return (string) VoiceCall::query()->whereKey($llamada->id)->value('status');
    }
}
