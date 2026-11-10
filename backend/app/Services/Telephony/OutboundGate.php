<?php

namespace App\Services\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Voice\VoiceCallContext;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameterValue;
use Throwable;

/**
 * LA PUERTA DE SALIENTES (contrato H8, D8-12, D8-15, D8-18; fixture
 * `outbound-gate-cases.json` §decide). La ÚNICA forma de pedir una llamada:
 * una persona con `telephony.call_place`, un destino del registro (un canario
 * de voz o un contacto con consentimiento) y un `client_request_id`.
 *
 * En UNA transacción, con el cerrojo de la puerta como primera sentencia:
 *  1. idempotencia: el mismo `client_request_id` de la misma persona para el
 *     mismo destino es `duplicate_request` (200 con la misma fila); para otro
 *     destino, 409 `client_request_reused`;
 *  2. el destino, con su número descifrado SOLO EN MEMORIA para calcular la
 *     huella, los 4 dígitos y el precio (nunca se guarda en claro);
 *  3. los hechos (banderas, número, proveedor, la parte de la cadena que
 *     atiende el alcance, la deriva que dejó en caché el job o el monitor, el
 *     freno, el destino, el calendario, la frecuencia, la concurrencia, el
 *     cupo y la reserva de coste contra el techo del día de Bogotá) →
 *     `OutboundPolicy`;
 *  4. si se permite: la fila `requested` con su SELLO y su reserva, el evento
 *     `outbound_requested` y el job en la cola `voice` (al confirmar).
 *
 * Con `CALLS_ENABLED_IN_CODE = false` (el árbol compartido y producción hasta
 * la fase 3), toda petición es 409 `calls_disabled_in_code`: no se crea nada.
 */
final class OutboundGate
{
    public const TARGET_CANARY = 'canary';

    public const TARGET_CONTACT = 'contact';

    public const TARGET_KINDS = [self::TARGET_CANARY, self::TARGET_CONTACT];

    /** La caché de la deriva de un agente (D8-21, ≤ 5 min). La escriben el job y la conciliación. */
    public const DRIFT_CACHE_PREFIX = 'voice:drift:';

    public const DRIFT_CACHE_SECONDS = 300;

    /**
     * @return array{http: int, body: array<string, mixed>}
     *
     * @throws TelephonyException 404 si el destino no existe; 409 si el id de la petición se reutiliza
     */
    public function request(Admin $actor, string $targetKind, string $targetUuid, string $clientRequestId): array
    {
        $crid = strtolower($clientRequestId);
        $uuid = strtolower($targetUuid);

        try {
            return DB::transaction(fn (): array => $this->decide($actor, $targetKind, $uuid, $crid));
        } catch (UniqueConstraintViolationException) {
            // El índice `vc_one_live_outbound` (una saliente viva por número) o el
            // `client_request_id` de la misma persona: otra petición ganó la carrera.
            throw TelephonyException::conflict('outbound_race', 'Otra petición a ese destino está en curso. Vuelve a consultar el registro de llamadas.');
        }
    }

    /** @return array{http: int, body: array<string, mixed>} */
    private function decide(Admin $actor, string $targetKind, string $uuid, string $crid): array
    {
        VoiceLocks::gate();
        $ahora = now();
        $dia = VoiceCostGuard::budgetDay($ahora);
        $alcance = self::currentScope();

        // 1. La idempotencia.
        $previa = VoiceCall::query()->where('requested_by_admin_id', $actor->id)->where('client_request_id', $crid)->first();
        $destino = $this->target($targetKind, $uuid);
        if ($previa !== null && ($previa->target_source !== $destino['source'] || ($destino['canaryUuid'] ?? null) !== $previa->canary_number_uuid || ($destino['contactId'] ?? null) !== $previa->voice_contact_id)) {
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otro destino.');
        }
        if ($previa !== null) {
            // TEST8-c H-4 (B8-h): la idempotencia se resuelve ANTES de la política. La petición original ya pasó la puerta;
            // repetirla devuelve la misma fila (DF-G5) sin volver a contar frecuencia, cupo ni presupuesto. Antes la propia
            // llamada contaba para la frecuencia de 1 al día y el 200 no era alcanzable: 409 [frequency_exceeded, duplicate_request].
            return ['http' => 200, 'body' => ['ok' => true, 'duplicate' => true, 'call' => VoiceCallsQuery::row($previa)]];
        }

        // 2. El número, solo en memoria.
        $numero = $destino['number'] instanceof SensitiveParameterValue ? $destino['number']->getValue() : null;
        $huella = $numero === null ? null : VoicePhone::hash($numero);

        // 3. Los hechos.
        $parte = VoicePublishedConfig::read()[VoicePublishedConfig::partForScope($alcance)];
        $frecuencia = $huella === null ? false
            : VoiceFrequency::exceeded(VoiceFrequency::count($ahora, VoiceCallFacts::historyFor($huella, $ahora)), VoiceFrequency::perWeekFromConfig(), VoiceFrequency::weekModeFromConfig());
        $precios = VoiceCostGuard::prices();
        $maxima = (int) config('voice.limits.per_call_max_seconds', 300);
        $reserva = VoiceCostGuard::reserveMicros($numero === null ? VoiceCostGuard::KIND_LANDLINE : VoiceCostGuard::destinationKind($numero), $maxima, VoiceCostGuard::KIND_LANDLINE, $precios);
        $cabe = VoiceCostGuard::fits(VoiceCostGuard::budgetFromConfig(), VoiceCostGuard::committedMicros($dia), $reserva);

        $decision = OutboundPolicy::decide([
            'outboundEnabled' => config('voice.outbound.enabled') === true,
            'twilioVoiceEnabled' => config('voice.twilio.enabled') === true,
            'voiceFromConfigured' => VoicePhone::isE164(config('voice.twilio.from')),
            // D8-5 + SEC8-c B-1: la configuración o el número importado que observó la conciliación.
            'voiceNumberCollides' => VoiceNumberGuard::collidesForPolicy(),
            // B8-g (decisión del lead): sin una conciliación del número de menos de 2 h que cuadre, cerrado.
            'numberCheck' => VoiceNumberGuard::policyCheck(),
            'providerState' => VoiceProviderState::current(),
            'publication' => $parte->policyState(),
            'agentDrift' => $parte->ok() && Cache::get(self::DRIFT_CACHE_PREFIX.$parte->providerAgentId) === true,
            'braked' => VoiceBrake::isOpen(),
            // El destino y el calendario: los mismos hechos que vuelve a calcular el job antes de marcar.
            ...self::targetFacts($destino, $alcance, $ahora),
            'frequencyExceeded' => $frecuencia,
            'activeOrDialing' => VoiceCallFacts::liveCount(),
            'maxConcurrent' => max(1, (int) config('voice.limits.max_concurrent_calls', 1)),
            'dailyCap' => (int) config('voice.twilio.daily_call_cap', 0),
            'placedToday' => VoiceCallFacts::outboundPlacedOn($dia),
            'budgetFits' => $cabe,
            'duplicate' => false, // la idempotencia ya se resolvió arriba (H-4); el motivo sigue en el conjunto cerrado de la política
        ]);

        if (! $decision['allowed']) {
            Log::channel('voice')->info('voice.outbound.denied', ['reasons' => $decision['reasons'], 'actor_admin_id' => $actor->id, 'target_kind' => $targetKind]);

            return ['http' => $decision['http'], 'body' => ['ok' => false, 'code' => 'outbound_denied', 'message' => 'La política de salientes no permite esta llamada.', 'reasons' => $decision['reasons']]];
        }

        // 4. La fila, el evento y el job (al confirmar).
        $llamada = (new VoiceCall)->forceFill([
            'direction' => VoiceCall::DIRECTION_OUTBOUND,
            'status' => VoiceCall::STATUS_REQUESTED,
            'target_source' => $destino['source'],
            'canary_number_uuid' => $destino['canaryUuid'] ?? null,
            'voice_contact_id' => $destino['contactId'] ?? null,
            'remote_hash' => $huella,
            'remote_last4' => VoicePhone::last4((string) $numero),
            'caller_authorized' => null,
            'policy_reason' => null,
            'client_request_id' => $crid,
            'requested_by_type' => 'admin',
            'requested_by_admin_id' => $actor->id,
            'requested_by_name' => (string) $actor->name,
            'requested_by_role' => $actor->role,
            'attempt' => 1,
            ...$parte->stampColumns(),
            'budget_day' => $dia,
            'reserved_micros' => $reserva,
            'tool_calls' => 0,
            'status_changed_at' => $ahora,
        ]);
        $llamada->save();
        VoiceEvents::record('outbound_requested', (int) $llamada->id, VoiceCallEvent::PROVIDER_LARAVEL, 'req:'.$llamada->uuid, [
            'actor_type' => VoiceCallEvent::ACTOR_ADMIN, 'actor_admin_id' => $actor->id, 'actor_name' => (string) $actor->name, 'actor_role' => $actor->role,
            'codes' => ['scope' => $alcance, 'seq' => $parte->seq, 'part' => $parte->part, 'reservedMicros' => $reserva],
        ]);
        PlaceOutboundVoiceCall::dispatch((int) $llamada->id)->afterCommit();
        Log::channel('voice')->info('voice.outbound.requested', ['call_uuid' => $llamada->uuid, 'actor_admin_id' => $actor->id]);

        return ['http' => 202, 'body' => ['ok' => true, 'call' => VoiceCallsQuery::row($llamada->refresh())]];
    }

    /** El alcance de las salientes ahora: `contacts` (4b) o, con cualquier otro valor, `canary` (4a). */
    public static function currentScope(): string
    {
        return config('voice.outbound.scope') === OutboundPolicy::SCOPE_CONTACTS ? OutboundPolicy::SCOPE_CONTACTS : OutboundPolicy::SCOPE_CANARY;
    }

    /**
     * Los hechos del DESTINO y del CALENDARIO para
     * {@see OutboundPolicy::targetReasons()}, calculados IGUAL en la puerta y
     * en el job, que los repite justo antes de marcar (revisión de seguridad,
     * TOCTOU): la exclusión (la del número, la oral, la del lead y el RNE
     * marcado), el consentimiento, la vigencia de la consulta al RNE y la
     * ventana legal y propia con sus festivos, a `$now`. El número se usa SOLO
     * EN MEMORIA, para su huella. Con `$sealedHash` (el job) cuenta también una
     * exclusión de la huella SELLADA en la fila.
     *
     * @param  array{source: string, canary: ?string, contactActive: bool, consent: bool, lead: ?array, rne: ?array, number: ?SensitiveParameterValue}  $destino
     * @return array{scope: string, source: string, canary: ?string, contactAuthorized: bool, voiceConsent: bool, excluded: bool,
     *               rneCheckMissing: bool, outsideHours: bool, holiday: bool}
     */
    public static function targetFacts(array $destino, string $scope, DateTimeInterface $now, ?string $sealedHash = null): array
    {
        $numero = $destino['number'] instanceof SensitiveParameterValue ? $destino['number']->getValue() : null;
        $huella = VoicePhone::isE164($numero) ? VoicePhone::hash($numero) : null;
        unset($numero);
        $exclusion = ($huella !== null && VoiceCallFacts::excluded($huella))
            || ($sealedHash !== null && $sealedHash !== $huella && VoiceCallFacts::excluded($sealedHash));
        $reglas = VoiceExclusionRule::evaluate($scope, $exclusion, $destino['lead'], $destino['rne'], $now, (int) config('voice.contacts.rne_validity_days', 0));
        $calendario = OutboundSchedule::evaluate($now, OutboundSchedule::ownFromConfig());

        return [
            'scope' => $scope,
            'source' => $destino['source'],
            'canary' => $destino['canary'],
            'contactAuthorized' => $destino['contactActive'],
            'voiceConsent' => $destino['consent'],
            'excluded' => $reglas['excluded'],
            'rneCheckMissing' => $reglas['rneCheckMissing'],
            'outsideHours' => $calendario['outsideHours'],
            'holiday' => $calendario['holiday'],
        ];
    }

    /**
     * El destino SELLADO de una saliente ya pedida, con la misma forma que el
     * de la puerta, para que el job lo vuelva a mirar. Si ya no existe, un
     * destino sin autorizar: la política da su motivo (nunca se marca).
     *
     * @return array{source: string, canary: ?string, canaryUuid?: ?string, contactId?: ?int, contactActive: bool, consent: bool, lead: ?array, rne: ?array, number: ?SensitiveParameterValue}
     */
    public static function targetOf(VoiceCall $call): array
    {
        if ($call->target_source === VoiceCallContext::SOURCE_CANARY_REGISTRY) {
            return self::canaryTarget($call->canary_number_uuid === null ? null : VoiceCanaryDirectory::forDial((string) $call->canary_number_uuid));
        }
        if ($call->target_source === VoiceCallContext::SOURCE_VOICE_CONTACTS) {
            return self::contactTarget($call->voice_contact_id === null ? null : VoiceContact::query()->whereKey($call->voice_contact_id)->first());
        }

        return ['source' => (string) $call->target_source, 'canary' => null, 'contactActive' => false, 'consent' => false, 'lead' => null, 'rne' => null, 'number' => null];
    }

    /**
     * El destino: su origen y lo que la política necesita saber de él. El
     * número va envuelto y solo en memoria.
     *
     * @return array{source: string, canary: ?string, canaryUuid?: ?string, contactId?: ?int, contactActive: bool, consent: bool, lead: ?array, rne: ?array, number: ?SensitiveParameterValue}
     *
     * @throws TelephonyException 404
     */
    private function target(string $kind, string $uuid): array
    {
        if ($kind === self::TARGET_CANARY) {
            $canario = VoiceCanaryDirectory::forDial($uuid);
            if ($canario === null) {
                throw TelephonyException::notFound('target');
            }

            return self::canaryTarget($canario);
        }

        $contacto = VoiceContact::query()->where('uuid', $uuid)->first();
        if ($contacto === null) {
            throw TelephonyException::notFound('target');
        }

        return self::contactTarget($contacto);
    }

    /**
     * @param  array{state: string, uuid: string, number: ?SensitiveParameterValue}|null  $canario  null = ya no está en el registro
     * @return array{source: string, canary: string, canaryUuid: ?string, contactActive: false, consent: false, lead: null, rne: null, number: ?SensitiveParameterValue}
     */
    private static function canaryTarget(?array $canario): array
    {
        return ['source' => VoiceCallContext::SOURCE_CANARY_REGISTRY, 'canary' => $canario['state'] ?? VoiceCallContext::CANARY_NOT_REGISTERED,
            'canaryUuid' => $canario['uuid'] ?? null, 'contactActive' => false, 'consent' => false, 'lead' => null, 'rne' => null, 'number' => $canario['number'] ?? null];
    }

    /**
     * @param  VoiceContact|null  $contacto  null = ya no existe
     * @return array{source: string, canary: null, contactId: ?int, contactActive: bool, consent: bool, lead: ?array, rne: ?array, number: ?SensitiveParameterValue}
     */
    private static function contactTarget(?VoiceContact $contacto): array
    {
        if ($contacto === null) {
            return ['source' => VoiceCallContext::SOURCE_VOICE_CONTACTS, 'canary' => null, 'contactId' => null, 'contactActive' => false, 'consent' => false,
                'lead' => null, 'rne' => null, 'number' => null];
        }
        $numero = null;
        if ($contacto->status === VoiceContact::STATUS_ACTIVE) {
            try {
                $claro = $contacto->phone_encrypted;
                $numero = VoicePhone::isE164($claro) ? new SensitiveParameterValue($claro) : null;
            } catch (Throwable) {
                // Un cifrado ilegible no se marca: el contacto queda como no autorizado.
                $numero = null;
            }
        }

        return [
            'source' => VoiceCallContext::SOURCE_VOICE_CONTACTS,
            'canary' => null,
            'contactId' => (int) $contacto->id,
            'contactActive' => $contacto->status === VoiceContact::STATUS_ACTIVE && $numero !== null,
            'consent' => VoiceContacts::hasConsent((int) $contacto->id),
            'lead' => $contacto->source_type === VoiceContact::SOURCE_LEAD && $contacto->source_id !== null ? VoiceLeadSignals::forLead((int) $contacto->source_id) : null,
            'rne' => VoiceContacts::latestRneCheck((string) $contacto->phone_hash),
            'number' => $numero,
        ];
    }
}
