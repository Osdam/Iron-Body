<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceIds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * La PUERTA DE LAS ENTRANTES: el webhook de iniciación de ElevenLabs, ya
 * autenticado por `VoiceInitiationAuth` (contrato H8, D8-11; E1-2; fixture
 * `elevenlabs-webhook-bodies.json` §initiation). Presupuesto: p95 < 300 ms y
 * NINGUNA petición saliente.
 *
 *  1. Un cuerpo autenticado pero mal formado (sin `call_sid` o sin
 *     `conversation_id`) se deniega explícitamente, sin fila (DF-E6).
 *  2. Idempotente por `call_sid`: la misma iniciación otra vez devuelve la
 *     misma respuesta y no crea otra fila. Si el SID es el de una SALIENTE de
 *     Laravel (B8-g), responde como saliente y no crea nada
 *     ({@see responseFor()}).
 *  3. Dentro del cerrojo de la puerta: la cadena (la parte `canary`), el
 *     llamante (el registro de canarios, solo lectura), el freno, la
 *     concurrencia y el cupo del día → `InboundPolicy`. Además, la puerta
 *     deniega un `called_number` que no es el número de voz (DF-E13) y un
 *     `agent_id` que no es el sellado de la parte vigente (DF-E12: además,
 *     `seal_mismatch` y freno: alguien cambió a qué agente apunta el número,
 *     E2-3). Con el número declarado SOLO SALIENTE
 *     (`VOICE_NUMBER_INBOUND_CAPABLE=false`; B8-g, SEC8-c M-2) deniega SIEMPRE,
 *     sean cuales sean las banderas y la constante, con
 *     `number_outbound_only` como primer motivo: un «Outbound Only» no recibe
 *     llamadas, así que si llega una iniciación, o el número no es lo que se
 *     declaró o la lanzó otro.
 *  4. TODA iniciación deja su fila (E1-2): `active` si se autoriza, `denied`
 *     si no, con el sello, la huella y los 4 dígitos del que llama (nunca el
 *     número; con el caller ID oculto, ninguno de los dos, DF-E17) y su
 *     reserva de coste.
 *
 * La respuesta NUNCA lleva datos personales: solo las cuatro variables
 * dinámicas (`caller_authorized`, `call_direction`, `call_ref` y
 * `version_label`) y ninguna sobrescritura de la configuración.
 */
final class InboundGate
{
    public const RESPONSE_TYPE = 'conversation_initiation_client_data';

    /** Motivos de la puerta, además de los de `InboundPolicy` (códigos de `voice_calls.policy_reason`). */
    public const REASON_WRONG_CALLED_NUMBER = 'wrong_called_number';

    public const REASON_SEAL_MISMATCH = 'seal_mismatch';

    /** B8-g (SEC8-c M-2): el número importado está declarado «Outbound Only»: ninguna entrante, nunca. */
    public const REASON_NUMBER_OUTBOUND_ONLY = 'number_outbound_only';

    public const MAX_DEPTH = 8;

    /** @return array<string, mixed> el cuerpo de la respuesta (siempre 200) */
    public function initiate(string $rawBody): array
    {
        $datos = json_decode($rawBody, true, self::MAX_DEPTH);
        $sid = is_array($datos) ? VoiceIds::callSidOrNull($datos['call_sid'] ?? null) : null;
        $conversacion = is_array($datos) ? VoiceIds::providerIdOrNull($datos['conversation_id'] ?? null) : null;
        if ($sid === null || $conversacion === null) {
            Log::channel('voice')->warning('voice.inbound.malformed', ['has_call_sid' => $sid !== null, 'has_conversation_id' => $conversacion !== null]);

            return self::response(VoiceAgentSpec::CALLER_NOT_AUTHORIZED, (string) Str::uuid(), 'canario · sin publicación');
        }

        $existente = VoiceCall::query()->where('provider_call_sid', $sid)->first();
        if ($existente !== null) {
            return self::responseFor($existente);
        }

        $llamante = is_string($datos['caller_id'] ?? null) && VoicePhone::isE164($datos['caller_id']) ? $datos['caller_id'] : null;
        $agente = VoiceIds::providerIdOrNull($datos['agent_id'] ?? null);
        $llamado = is_string($datos['called_number'] ?? null) ? $datos['called_number'] : null;

        try {
            return DB::transaction(fn (): array => $this->decideAndRecord($sid, $conversacion, $llamante, $agente, $llamado));
        } catch (UniqueConstraintViolationException) {
            // Otra entrega de la misma iniciación ganó la carrera: la suya es la respuesta.
            $fila = VoiceCall::query()->where('provider_call_sid', $sid)->first();
            if ($fila === null) {
                throw new \RuntimeException('voice: iniciación duplicada sin fila.');
            }

            return self::responseFor($fila);
        }
    }

    /** @return array<string, mixed> */
    private function decideAndRecord(string $sid, string $conversacion, #[\SensitiveParameter] ?string $llamante, ?string $agente, #[\SensitiveParameter] ?string $llamado): array
    {
        VoiceLocks::gate();
        $repetida = VoiceCall::query()->where('provider_call_sid', $sid)->first();
        if ($repetida !== null) {
            return self::responseFor($repetida);
        }

        $ahora = now();
        $dia = VoiceCostGuard::budgetDay($ahora);
        $parte = VoicePublishedConfig::read()[VoicePublishedConfig::PART_CANARY];
        $canario = VoiceCanaryDirectory::lookup($llamante);
        $limite = (int) config('voice.limits.max_concurrent_calls', 1);

        $decision = InboundPolicy::decide([
            'inboundEnabled' => config('voice.inbound.enabled') === true,
            'scope' => (string) config('voice.inbound.scope', InboundPolicy::SCOPE_CANARY),
            'braked' => VoiceBrake::isOpen(),
            'publication' => $parte->policyState(),
            'caller' => $canario['state'],
            'activeOrDialing' => VoiceCallFacts::liveCount(),
            'maxConcurrent' => max(1, $limite),
            'inboundToday' => VoiceCallFacts::inboundAuthorizedOn($dia),
            'inboundDailyCap' => (int) config('voice.inbound.daily_cap', 0),
        ]);
        $motivos = $decision['reasons'];
        // B8-g (SEC8-c M-2): con el número declarado solo saliente no se atiende ninguna entrante, sean cuales sean las
        // banderas y la constante. Es el primer motivo (el que queda en `policy_reason`).
        if (config('voice.number.inbound_capable') === false) {
            array_unshift($motivos, self::REASON_NUMBER_OUTBOUND_ONLY);
        }

        $numero = config('voice.twilio.from');
        if (! is_string($numero) || ! VoicePhone::isE164($numero) || ! is_string($llamado) || ! hash_equals($numero, $llamado)) {
            $motivos[] = self::REASON_WRONG_CALLED_NUMBER;
        }
        $selloRoto = $parte->ok() && ($agente === null || ! hash_equals((string) $parte->providerAgentId, $agente));
        if ($selloRoto) {
            $motivos[] = self::REASON_SEAL_MISMATCH;
        }
        $autorizada = $motivos === [];

        $precios = VoiceCostGuard::prices();
        $maxima = (int) config('voice.limits.per_call_max_seconds', 300);
        $reserva = $autorizada
            // La entrante autorizada: su duración máxima y un posible tramo transferido (sin el directorio de destinos, el más caro).
            ? VoiceCostGuard::reserveMicros(VoiceCostGuard::KIND_INBOUND, $maxima, VoiceCostGuard::KIND_LANDLINE, $precios)
            // La denegada: el minuto que cuesta decir «línea no disponible» y colgar.
            : VoiceCostGuard::reserveMicros(VoiceCostGuard::KIND_INBOUND, 60, null, $precios);

        $llamada = (new VoiceCall)->forceFill([
            'direction' => VoiceCall::DIRECTION_INBOUND,
            'status' => $autorizada ? VoiceCall::STATUS_ACTIVE : VoiceCall::STATUS_DENIED,
            'target_source' => null,
            'canary_number_uuid' => $canario['uuid'],
            'voice_contact_id' => null,
            'remote_hash' => $llamante === null ? null : VoicePhone::hash($llamante),
            'remote_last4' => $llamante === null ? null : VoicePhone::last4($llamante),
            'caller_authorized' => $autorizada,
            'policy_reason' => $autorizada ? null : $motivos[0],
            'attempt' => 1,
            ...$parte->stampColumns(),
            'provider_conversation_id' => $conversacion,
            'provider_call_sid' => $sid,
            'budget_day' => $dia,
            'reserved_micros' => $reserva,
            'tool_calls' => 0,
            'started_at' => $autorizada ? $ahora : null,
            'status_changed_at' => $ahora,
        ]);
        $llamada->save();

        VoiceEvents::record('initiation_received', (int) $llamada->id, VoiceCallEvent::PROVIDER_ELEVENLABS, $sid, [
            'outcome' => $autorizada ? 'authorized' : 'denied',
            'codes' => ['reasons' => $motivos, 'caller' => $canario['state'], 'publication' => $parte->status, 'seq' => $parte->seq],
        ]);
        if ($selloRoto) {
            $evento = VoiceEvents::record('seal_mismatch', (int) $llamada->id, VoiceCallEvent::PROVIDER_ELEVENLABS, $sid, [
                'outcome' => 'agent_differs', 'codes' => ['where' => 'initiation', 'seq' => $parte->seq, 'part' => $parte->part],
            ]);
            VoiceBrake::open('seal_mismatch', 'seal:'.$sid, ['where' => 'initiation', 'seq' => $parte->seq, 'part' => $parte->part], (int) $llamada->id, $evento?->id);
        }
        Log::channel('voice')->info('voice.inbound.initiation', ['call_uuid' => $llamada->uuid, 'authorized' => $autorizada, 'reasons' => $motivos]);

        return self::response($autorizada ? VoiceAgentSpec::CALLER_AUTHORIZED : VoiceAgentSpec::CALLER_NOT_AUTHORIZED, (string) $llamada->call_ref, $parte->versionLabel());
    }

    /**
     * La respuesta de una fila ya creada: la misma iniciación otra vez, o
     * (B8-g, decisión del lead) una SALIENTE. La doc de ElevenLabs describe el
     * webhook de iniciación solo para entrantes; si lo llamara en una saliente
     * que inició Laravel, su SID ya está en la fila (se guarda al volver la
     * petición y el webhook llega al conectar): se responde como saliente, con
     * la autorización de esa fila (viva: la autorizó la puerta; si no, no), y
     * NO se crea ninguna entrante `denied`. Riesgo residual: si el webhook
     * llegara ANTES de que el job guarde el SID, no hay fila y sale una
     * entrante denegada (a vigilar en la primera llamada de la 4a: la línea
     * `voice.inbound.initiation_for_outbound` o una `denied` con el número de voz
     * como llamante).
     *
     * @return array<string, mixed>
     */
    private static function responseFor(VoiceCall $llamada): array
    {
        $parte = $llamada->part === VoicePublishedConfig::PART_REST ? 'resto' : 'canario';
        $etiqueta = $llamada->stamp_state === 'applied' ? "{$parte} · publicación {$llamada->voice_publication_seq}" : "{$parte} · sin publicación";

        if ($llamada->direction === VoiceCall::DIRECTION_OUTBOUND) {
            $viva = in_array($llamada->status, [VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE], true);
            Log::channel('voice')->warning('voice.inbound.initiation_for_outbound', ['call_uuid' => $llamada->uuid, 'status' => $llamada->status, 'authorized' => $viva]);

            return self::response($viva ? VoiceAgentSpec::CALLER_AUTHORIZED : VoiceAgentSpec::CALLER_NOT_AUTHORIZED, (string) $llamada->call_ref, $etiqueta,
                VoiceAgentSpec::DIRECTION_OUTBOUND);
        }

        return self::response($llamada->caller_authorized === true ? VoiceAgentSpec::CALLER_AUTHORIZED : VoiceAgentSpec::CALLER_NOT_AUTHORIZED, (string) $llamada->call_ref, $etiqueta);
    }

    /** @return array<string, mixed> */
    private static function response(string $autorizado, string $callRef, string $etiqueta, string $direccion = VoiceAgentSpec::DIRECTION_INBOUND): array
    {
        return [
            'type' => self::RESPONSE_TYPE,
            'dynamic_variables' => [
                VoiceAgentSpec::VAR_CALLER_AUTHORIZED => $autorizado,
                VoiceAgentSpec::VAR_CALL_DIRECTION => $direccion,
                VoiceAgentSpec::VAR_CALL_REF => $callRef,
                VoiceAgentSpec::VAR_VERSION_LABEL => $etiqueta,
            ],
        ];
    }
}
