<?php

namespace App\Services\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Voice\VoiceCallContext;
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
        $alcance = config('voice.outbound.scope') === OutboundPolicy::SCOPE_CONTACTS ? OutboundPolicy::SCOPE_CONTACTS : OutboundPolicy::SCOPE_CANARY;

        // 1. La idempotencia.
        $previa = VoiceCall::query()->where('requested_by_admin_id', $actor->id)->where('client_request_id', $crid)->first();
        $destino = $this->target($targetKind, $uuid);
        if ($previa !== null && ($previa->target_source !== $destino['source'] || ($destino['canaryUuid'] ?? null) !== $previa->canary_number_uuid || ($destino['contactId'] ?? null) !== $previa->voice_contact_id)) {
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otro destino.');
        }

        // 2. El número, solo en memoria.
        $numero = $destino['number'] instanceof SensitiveParameterValue ? $destino['number']->getValue() : null;
        $huella = $numero === null ? null : VoicePhone::hash($numero);

        // 3. Los hechos.
        $parte = VoicePublishedConfig::read()[VoicePublishedConfig::partForScope($alcance)];
        $exclusion = $huella !== null && VoiceCallFacts::excluded($huella);
        $reglas = VoiceExclusionRule::evaluate($alcance, $exclusion, $destino['lead'], $destino['rne'], $ahora, (int) config('voice.contacts.rne_validity_days', 0));
        $frecuencia = $huella === null ? false
            : VoiceFrequency::exceeded(VoiceFrequency::count($ahora, VoiceCallFacts::historyFor($huella, $ahora)), VoiceFrequency::perWeekFromConfig(), VoiceFrequency::weekModeFromConfig());
        $calendario = OutboundSchedule::evaluate($ahora, OutboundSchedule::ownFromConfig());
        $precios = VoiceCostGuard::prices();
        $maxima = (int) config('voice.limits.per_call_max_seconds', 300);
        $reserva = VoiceCostGuard::reserveMicros($numero === null ? VoiceCostGuard::KIND_LANDLINE : VoiceCostGuard::destinationKind($numero), $maxima, VoiceCostGuard::KIND_LANDLINE, $precios);
        $cabe = VoiceCostGuard::fits(VoiceCostGuard::budgetFromConfig(), VoiceCostGuard::committedMicros($dia), $reserva);

        $decision = OutboundPolicy::decide([
            'outboundEnabled' => config('voice.outbound.enabled') === true,
            'twilioVoiceEnabled' => config('voice.twilio.enabled') === true,
            'voiceFromConfigured' => VoicePhone::isE164(config('voice.twilio.from')),
            'voiceNumberCollides' => VoiceNumberGuard::collidesWithConfig(),
            'providerState' => VoiceProviderState::current(),
            'publication' => $parte->policyState(),
            'agentDrift' => $parte->ok() && Cache::get(self::DRIFT_CACHE_PREFIX.$parte->providerAgentId) === true,
            'braked' => VoiceBrake::isOpen(),
            'scope' => $alcance,
            'source' => $destino['source'],
            'canary' => $destino['canary'],
            'contactAuthorized' => $destino['contactActive'],
            'voiceConsent' => $destino['consent'],
            'excluded' => $reglas['excluded'],
            'rneCheckMissing' => $reglas['rneCheckMissing'],
            'outsideHours' => $calendario['outsideHours'],
            'holiday' => $calendario['holiday'],
            'frequencyExceeded' => $frecuencia,
            'activeOrDialing' => VoiceCallFacts::liveCount(),
            'maxConcurrent' => max(1, (int) config('voice.limits.max_concurrent_calls', 1)),
            'dailyCap' => (int) config('voice.twilio.daily_call_cap', 0),
            'placedToday' => VoiceCallFacts::outboundPlacedOn($dia),
            'budgetFits' => $cabe,
            'duplicate' => $previa !== null,
        ]);

        if ($previa !== null && $decision['reasons'] === [OutboundPolicy::REASON_DUPLICATE]) {
            return ['http' => 200, 'body' => ['ok' => true, 'duplicate' => true, 'call' => VoiceCallsQuery::row($previa)]];
        }
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

            return ['source' => VoiceCallContext::SOURCE_CANARY_REGISTRY, 'canary' => $canario['state'], 'canaryUuid' => $canario['uuid'],
                'contactActive' => false, 'consent' => false, 'lead' => null, 'rne' => null, 'number' => $canario['number']];
        }

        $contacto = VoiceContact::query()->where('uuid', $uuid)->first();
        if ($contacto === null) {
            throw TelephonyException::notFound('target');
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
