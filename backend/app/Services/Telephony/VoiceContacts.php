<?php

namespace App\Services\Telephony;

use App\Models\Admin;
use App\Models\Member;
use App\Models\Voice\VoiceConsent;
use App\Models\Voice\VoiceContact;
use App\Models\Voice\VoiceExclusion;
use App\Models\Voice\VoiceRneCheck;
use App\Services\UltronStudio\StudioCanaryPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los contactos de la fase 4b y su consentimiento (contrato H8, D8-18, [P-18],
 * [P-20]; Enmienda 3, E3-4). Todo lo escribe una PERSONA con
 * `telephony.contacts_manage` (lo comprueban la puerta y el controlador).
 *
 *  - Un contacto guarda su número CIFRADO, su huella `voice-phone` y sus 4
 *    dígitos; el origen (`lead`, `member` o `manual`) es una referencia sin FK
 *    al motor. Un número vivo por contacto (`vct_one_live`).
 *  - CONSENTIMIENTO A CUATRO OJOS (E3-4): una cuenta lo CARGA con su evidencia
 *    (queda pendiente en el contacto) y OTRA lo CONFIRMA; solo entonces hay
 *    fila `granted` en `voice_consents` y habilita salientes. Revocar no espera
 *    a nadie.
 *  - Las exclusiones (de una persona) y las consultas al RNE (manuales, el
 *    portal de la CRC; un `listed` excluye) son de solo inserción.
 *
 * Idempotente por `client_request_id`. Los textos (evidencia, nota,
 * referencia) son PlainText estricto y sin teléfonos ni correos.
 */
final class VoiceContacts
{
    public const EVIDENCE_MAX = 300;

    public const REF_MAX = 120;

    /** Lo que una persona puede cargar: el formulario escrito o la evidencia de una persona del equipo. */
    public const PERSON_SOURCES = VoiceConsentRule::PERSON_SOURCES;

    // ── Lecturas para la política ────────────────────────────────────────────

    /** ¿Tiene el contacto consentimiento de voz vigente? (E3-4; la grabación, apagada por P-7). */
    public static function hasConsent(int $contactId): bool
    {
        $eventos = VoiceConsent::query()->where('contact_id', $contactId)->orderBy('id')
            ->get(['id', 'kind', 'source', 'recorded_at', 'recorded_by_admin_id', 'confirmed_by_admin_id'])
            ->map(static fn (VoiceConsent $c): array => [
                'id' => (int) $c->id, 'kind' => (string) $c->kind, 'source' => (string) $c->source, 'at' => $c->recorded_at,
                'actorId' => $c->recorded_by_admin_id, 'approvedBy' => $c->confirmed_by_admin_id,
            ])->all();

        return VoiceConsentRule::hasConsent($eventos, false);
    }

    /** @return array{checkedAt: Carbon, result: string}|null la consulta al RNE más reciente de una huella */
    public static function latestRneCheck(string $phoneHash): ?array
    {
        $c = VoiceRneCheck::query()->where('phone_hash', $phoneHash)->orderByDesc('checked_at')->orderByDesc('id')->first(['checked_at', 'result']);

        return $c === null ? null : ['checkedAt' => $c->checked_at, 'result' => (string) $c->result];
    }

    // ── Escrituras ───────────────────────────────────────────────────────────

    /**
     * @param  array{clientRequestId: string, number: string, sourceType: string, sourceId: ?int}  $in
     * @return array{http: int, contact: array<string, mixed>}
     *
     * @throws TelephonyException
     */
    public function register(Admin $actor, array $in): array
    {
        $leido = StudioCanaryPhone::parse($in['number'], StudioCanaryPhone::CHANNEL_VOICE, [StudioCanaryPhone::COLOMBIA]);
        if (($leido['ok'] ?? false) !== true) {
            throw TelephonyException::invalid(['number' => [(string) ($leido['error'] ?? StudioCanaryPhone::INVALID_FORMAT)]]);
        }
        $e164 = (string) $leido['e164'];
        $huella = VoicePhone::hash($e164);
        $crid = strtolower($in['clientRequestId']);

        $previo = VoiceContact::query()->where('client_request_id', $crid)->first();
        if ($previo !== null) {
            if ((int) $previo->registered_by_admin_id === (int) $actor->id && hash_equals((string) $previo->phone_hash, $huella)) {
                return ['http' => 200, 'contact' => self::row($previo)];
            }
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }
        $this->assertSource($in['sourceType'], $in['sourceId']);

        try {
            $contacto = DB::transaction(static function () use ($actor, $in, $e164, $huella, $crid): VoiceContact {
                $c = (new VoiceContact)->forceFill([
                    'client_request_id' => $crid,
                    'phone_hash' => $huella,
                    'phone_last4' => VoicePhone::last4($e164),
                    'phone_encrypted' => $e164,
                    'source_type' => $in['sourceType'],
                    'source_id' => $in['sourceType'] === VoiceContact::SOURCE_MANUAL ? null : $in['sourceId'],
                    'status' => VoiceContact::STATUS_ACTIVE,
                    'registered_by_admin_id' => $actor->id,
                    'registered_by_name' => (string) $actor->name,
                    'registered_by_role' => $actor->role,
                    'status_changed_at' => now(),
                ]);
                $c->save();

                return $c;
            });
        } catch (UniqueConstraintViolationException) {
            throw TelephonyException::conflict('contact_exists', 'Ese número ya es un contacto de voz.');
        }
        Log::channel('voice')->info('voice.contact.registered', ['contact_uuid' => $contacto->uuid, 'source_type' => $contacto->source_type, 'actor_admin_id' => $actor->id]);

        return ['http' => 201, 'contact' => self::row($contacto)];
    }

    /**
     * La PRIMERA persona carga el consentimiento con su evidencia: queda
     * pendiente de otra (E3-4).
     *
     * @param  array{source: string, evidence: string}  $in
     * @return array<string, mixed>
     *
     * @throws TelephonyException
     */
    public function requestConsent(Admin $actor, string $contactUuid, array $in): array
    {
        $contacto = self::contact($contactUuid);
        self::assertText('evidence', $in['evidence'], self::EVIDENCE_MAX);
        if (! in_array($in['source'], self::PERSON_SOURCES, true)) {
            throw TelephonyException::invalid(['source' => ['in']]);
        }
        $puesto = VoiceContact::setConsentRequest((int) $contacto->id, [
            'consent_request_source' => $in['source'],
            'consent_request_evidence' => $in['evidence'],
            'consent_requested_by_admin_id' => $actor->id,
            'consent_requested_by_name' => (string) $actor->name,
            'consent_requested_by_role' => $actor->role,
            'consent_requested_at' => now(),
        ], onlyIfEmpty: true);
        if (! $puesto) {
            throw TelephonyException::conflict('consent_request_pending', 'Este contacto ya tiene un consentimiento pendiente de confirmar, o no está activo.');
        }
        Log::channel('voice')->info('voice.consent.requested', ['contact_uuid' => $contacto->uuid, 'source' => $in['source'], 'actor_admin_id' => $actor->id]);

        return ['ok' => true, 'pending' => true, 'contact' => self::row(self::contact($contactUuid))];
    }

    /**
     * OTRA persona lo confirma: nace el `granted` (cuatro ojos; la base lo exige
     * también, `vcs_four_eyes_chk`).
     *
     * @return array<string, mixed>
     *
     * @throws TelephonyException
     */
    public function confirmConsent(Admin $actor, string $contactUuid, string $clientRequestId): array
    {
        $crid = strtolower($clientRequestId);
        $previo = VoiceConsent::query()->where('client_request_id', $crid)->first();
        if ($previo !== null) {
            if ((int) $previo->confirmed_by_admin_id === (int) $actor->id) {
                return ['ok' => true, 'consent' => self::consentRow($previo)];
            }
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }

        return DB::transaction(function () use ($actor, $contactUuid, $crid): array {
            $contacto = VoiceContact::query()->where('uuid', strtolower($contactUuid))->lockForUpdate()->first();
            if ($contacto === null) {
                throw TelephonyException::notFound('contact');
            }
            if ($contacto->consent_request_source === null || $contacto->status !== VoiceContact::STATUS_ACTIVE) {
                throw TelephonyException::conflict('no_consent_request', 'No hay ningún consentimiento pendiente de confirmar.');
            }
            if ((int) $contacto->consent_requested_by_admin_id === (int) $actor->id) {
                throw TelephonyException::fourEyes();
            }
            $consentimiento = (new VoiceConsent)->forceFill([
                'client_request_id' => $crid,
                'contact_id' => $contacto->id,
                'kind' => VoiceConsentRule::KIND_GRANTED,
                'channel' => VoiceConsent::CHANNEL,
                'purpose' => VoiceConsent::PURPOSE,
                'source' => $contacto->consent_request_source,
                'evidence' => $contacto->consent_request_evidence,
                'actor_type' => 'admin',
                'recorded_by_admin_id' => $contacto->consent_requested_by_admin_id,
                'recorded_by_name' => $contacto->consent_requested_by_name,
                'recorded_by_role' => $contacto->consent_requested_by_role,
                'recorded_at' => $contacto->consent_requested_at,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_by_name' => (string) $actor->name,
                'confirmed_by_role' => $actor->role,
            ]);
            $consentimiento->save();
            VoiceContact::setConsentRequest((int) $contacto->id, [
                'consent_request_source' => null, 'consent_request_evidence' => null, 'consent_requested_by_admin_id' => null,
                'consent_requested_by_name' => null, 'consent_requested_by_role' => null, 'consent_requested_at' => null,
            ], onlyIfEmpty: false);
            Log::channel('voice')->info('voice.consent.confirmed', ['contact_uuid' => $contacto->uuid, 'consent_uuid' => $consentimiento->uuid, 'actor_admin_id' => $actor->id]);

            return ['ok' => true, 'consent' => self::consentRow($consentimiento)];
        });
    }

    /**
     * Revocar no espera a nadie (D8-18, DF-P5).
     *
     * @param  array{clientRequestId: string, source: string, evidence: string}  $in
     * @return array<string, mixed>
     *
     * @throws TelephonyException
     */
    public function revoke(Admin $actor, string $contactUuid, array $in): array
    {
        $contacto = self::contact($contactUuid);
        self::assertText('evidence', $in['evidence'], self::EVIDENCE_MAX);
        if (! in_array($in['source'], self::PERSON_SOURCES, true)) {
            throw TelephonyException::invalid(['source' => ['in']]);
        }
        $crid = strtolower($in['clientRequestId']);
        $previo = VoiceConsent::query()->where('client_request_id', $crid)->first();
        if ($previo !== null) {
            if ((int) $previo->recorded_by_admin_id === (int) $actor->id && (int) $previo->contact_id === (int) $contacto->id) {
                return ['ok' => true, 'consent' => self::consentRow($previo)];
            }
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }
        $consentimiento = (new VoiceConsent)->forceFill([
            'client_request_id' => $crid,
            'contact_id' => $contacto->id,
            'kind' => VoiceConsentRule::KIND_REVOKED,
            'channel' => VoiceConsent::CHANNEL,
            'purpose' => VoiceConsent::PURPOSE,
            'source' => $in['source'],
            'evidence' => $in['evidence'],
            'actor_type' => 'admin',
            'recorded_by_admin_id' => $actor->id,
            'recorded_by_name' => (string) $actor->name,
            'recorded_by_role' => $actor->role,
            'recorded_at' => now(),
        ]);
        $consentimiento->save();
        Log::channel('voice')->info('voice.consent.revoked', ['contact_uuid' => $contacto->uuid, 'actor_admin_id' => $actor->id]);

        return ['ok' => true, 'consent' => self::consentRow($consentimiento)];
    }

    /**
     * Una exclusión de una persona, por contacto o por número (solo su huella).
     *
     * @param  array{clientRequestId: string, contactUuid: ?string, number: ?string, note: ?string}  $in
     * @return array<string, mixed>
     *
     * @throws TelephonyException
     */
    public function exclude(Admin $actor, array $in): array
    {
        if ($in['note'] !== null) {
            self::assertText('note', $in['note'], self::EVIDENCE_MAX);
        }
        [$huella, $ultimos, $contactoId] = $this->destination($in['contactUuid'], $in['number']);
        $crid = strtolower($in['clientRequestId']);
        $previa = VoiceExclusion::query()->where('client_request_id', $crid)->first();
        if ($previa !== null) {
            if ((int) $previa->actor_admin_id === (int) $actor->id && hash_equals((string) $previa->phone_hash, $huella)) {
                return ['ok' => true, 'exclusion' => self::exclusionRow($previa)];
            }
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }
        $exclusion = (new VoiceExclusion)->forceFill([
            'client_request_id' => $crid,
            'phone_hash' => $huella,
            'phone_last4' => $ultimos,
            'source' => VoiceExclusion::SOURCE_ADMIN,
            'contact_id' => $contactoId,
            'note' => $in['note'],
            'actor_type' => 'admin',
            'actor_admin_id' => $actor->id,
            'actor_name' => (string) $actor->name,
            'actor_role' => $actor->role,
        ]);
        $exclusion->save();
        Log::channel('voice')->info('voice.exclusion.admin', ['exclusion_uuid' => $exclusion->uuid, 'actor_admin_id' => $actor->id]);

        return ['ok' => true, 'exclusion' => self::exclusionRow($exclusion)];
    }

    /**
     * Una consulta MANUAL al RNE (el portal de la CRC): queda registrada; un
     * `listed` excluye el número desde ese momento.
     *
     * @param  array{clientRequestId: string, contactUuid: string, checkedAt: string, result: string, evidenceRef: string}  $in
     * @return array<string, mixed>
     *
     * @throws TelephonyException
     */
    public function rneCheck(Admin $actor, array $in): array
    {
        $contacto = self::contact($in['contactUuid']);
        self::assertText('evidenceRef', $in['evidenceRef'], self::REF_MAX);
        $cuando = Carbon::parse($in['checkedAt']);
        if ($cuando->isFuture()) {
            throw TelephonyException::invalid(['checkedAt' => ['before_or_equal_now']]);
        }
        $crid = strtolower($in['clientRequestId']);
        $previa = VoiceRneCheck::query()->where('client_request_id', $crid)->first();
        if ($previa !== null) {
            if ((int) $previa->actor_admin_id === (int) $actor->id && (int) $previa->contact_id === (int) $contacto->id) {
                return ['ok' => true, 'check' => self::rneRow($previa)];
            }
            throw TelephonyException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }

        return DB::transaction(function () use ($actor, $in, $contacto, $cuando, $crid): array {
            $consulta = (new VoiceRneCheck)->forceFill([
                'client_request_id' => $crid,
                'phone_hash' => $contacto->phone_hash,
                'contact_id' => $contacto->id,
                'checked_at' => $cuando,
                'result' => $in['result'],
                'method' => VoiceRneCheck::METHOD_MANUAL_PORTAL,
                'evidence_ref' => $in['evidenceRef'],
                'actor_type' => 'admin',
                'actor_admin_id' => $actor->id,
                'actor_name' => (string) $actor->name,
                'actor_role' => $actor->role,
            ]);
            $consulta->save();
            if ($in['result'] === VoiceExclusionRule::RNE_LISTED) {
                (new VoiceExclusion)->forceFill([
                    'phone_hash' => $contacto->phone_hash,
                    'phone_last4' => $contacto->phone_last4,
                    'source' => VoiceExclusion::SOURCE_RNE,
                    'contact_id' => $contacto->id,
                    'rne_check_id' => $consulta->id,
                    'actor_type' => 'admin',
                    'actor_admin_id' => $actor->id,
                    'actor_name' => (string) $actor->name,
                    'actor_role' => $actor->role,
                ])->save();
            }
            Log::channel('voice')->info('voice.rne.checked', ['contact_uuid' => $contacto->uuid, 'result' => $in['result'], 'actor_admin_id' => $actor->id]);

            return ['ok' => true, 'check' => self::rneRow($consulta)];
        });
    }

    // ── Lecturas para el CRM ─────────────────────────────────────────────────

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function list(int $page, int $perPage): array
    {
        $porPagina = max(1, min(100, $perPage));
        $p = VoiceContact::query()->orderByDesc('id')->paginate($porPagina, ['*'], 'page', max(1, $page));

        return [
            'data' => array_map(self::row(...), $p->items()),
            'meta' => ['page' => $p->currentPage(), 'perPage' => $p->perPage(), 'total' => $p->total(), 'lastPage' => $p->lastPage()],
        ];
    }

    /** @return array<string, mixed> un contacto sin su número: los 4 dígitos, el origen, el consentimiento y lo pendiente */
    public static function row(VoiceContact $c): array
    {
        return [
            'uuid' => $c->uuid,
            'last4' => $c->phone_last4,
            'status' => $c->status,
            'sourceType' => $c->source_type,
            'sourceId' => $c->source_id,
            'voiceConsent' => self::hasConsent((int) $c->id),
            'pendingConsent' => $c->consent_request_source === null ? null : [
                'source' => $c->consent_request_source,
                'requestedBy' => $c->consent_requested_by_name,
                'requestedById' => $c->consent_requested_by_admin_id,
                'requestedAt' => $c->consent_requested_at?->toIso8601String(),
            ],
            'excluded' => VoiceCallFacts::excluded((string) $c->phone_hash),
            'registeredBy' => $c->registered_by_name,
            'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }

    // ── Piezas ───────────────────────────────────────────────────────────────

    /** @throws TelephonyException 404 */
    private static function contact(string $uuid): VoiceContact
    {
        $c = VoiceContact::query()->where('uuid', strtolower($uuid))->first();
        if ($c === null) {
            throw TelephonyException::notFound('contact');
        }

        return $c;
    }

    /** @throws TelephonyException 422 */
    private function assertSource(string $tipo, ?int $id): void
    {
        $vale = match ($tipo) {
            VoiceContact::SOURCE_MANUAL => true,
            VoiceContact::SOURCE_LEAD => $id !== null && VoiceLeadSignals::forLead($id) !== null,
            VoiceContact::SOURCE_MEMBER => $id !== null && Member::query()->whereKey($id)->exists(),
            default => false,
        };
        if (! $vale) {
            throw TelephonyException::invalid(['sourceId' => ['exists']]);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: ?int} la huella, los 4 dígitos y el contacto
     *
     * @throws TelephonyException
     */
    private function destination(?string $contactUuid, #[\SensitiveParameter] ?string $number): array
    {
        if ($contactUuid !== null) {
            $c = self::contact($contactUuid);

            return [(string) $c->phone_hash, (string) $c->phone_last4, (int) $c->id];
        }
        $leido = $number === null ? ['ok' => false] : StudioCanaryPhone::parse($number, StudioCanaryPhone::CHANNEL_VOICE, [StudioCanaryPhone::COLOMBIA]);
        if (($leido['ok'] ?? false) !== true) {
            throw TelephonyException::invalid(['number' => [(string) ($leido['error'] ?? StudioCanaryPhone::INVALID_FORMAT)]]);
        }

        return [VoicePhone::hash((string) $leido['e164']), VoicePhone::last4((string) $leido['e164']), null];
    }

    /** @throws TelephonyException 422 */
    private static function assertText(string $campo, string $texto, int $max): void
    {
        if (mb_strlen($texto, 'UTF-8') > $max) {
            throw TelephonyException::invalid([$campo => ['max']]);
        }
        $errores = VoiceBrake::noteErrors($texto);
        if ($errores !== []) {
            throw TelephonyException::invalid([$campo => $errores['note']]);
        }
    }

    /** @return array<string, mixed> */
    private static function consentRow(VoiceConsent $c): array
    {
        return ['uuid' => $c->uuid, 'kind' => $c->kind, 'source' => $c->source, 'recordedBy' => $c->recorded_by_name, 'confirmedBy' => $c->confirmed_by_name,
            'recordedAt' => $c->recorded_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private static function exclusionRow(VoiceExclusion $e): array
    {
        return ['uuid' => $e->uuid, 'source' => $e->source, 'last4' => $e->phone_last4, 'createdAt' => $e->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private static function rneRow(VoiceRneCheck $c): array
    {
        return ['uuid' => $c->uuid, 'result' => $c->result, 'method' => $c->method, 'checkedAt' => $c->checked_at?->toIso8601String()];
    }
}
