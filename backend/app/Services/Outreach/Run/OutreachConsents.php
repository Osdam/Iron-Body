<?php

namespace App\Services\Outreach\Run;

use App\Models\OutreachConsent;
use App\Rules\PlainText;
use App\Services\Outreach\Facts\FactPass;
use App\Services\Outreach\Facts\OutreachConsentFacts;
use App\Services\Outreach\Facts\PhoneRef;
use App\Services\Outreach\Facts\Subject;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Facts\SubjectResolver;
use App\Support\Access\TrainerMemberScope;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * C9-3 · Las AUTORIZACIONES DE CONTACTO que registra el personal (Enmienda C9
 * del contrato H9; el libro `outreach_consents` de C9-1, SOLO INSERCIÓN): pedir
 * (una cuenta), confirmar (OTRA cuenta: cuatro ojos por cuenta, como la voz),
 * rechazar y retirar (sin segunda cuenta: retirar siempre es seguro).
 *
 * LAS CERRADURAS (además de la puerta de `AuthorizationMap` y la persona de
 * {@see OutreachAccess}): ver a la persona. Un socio exige `members.view` y un
 * prospecto `marketing.view`, comprobadas ANTES de leer nada de ella: sin la
 * llave, 403 sin decir si existe. Un prospecto de un socio exige también la de
 * socios; sin ella es 404, como uno que no existe (M-1 de la revisión de API:
 * un 403 diría que está enlazado a un socio, y Mercadeo no le enseña ese
 * vínculo). Por `uuid`, una autorización de una persona que no se
 * puede ver es 404, igual que una que no existe. Y el ALCANCE DEL ENTRENADOR
 * ({@see TrainerMemberScope}, C-3 de la revisión de API): una cuenta de
 * entrenador solo alcanza a los socios que tiene asignados; un socio ajeno
 * (o su prospecto) es 404, como en el resto del CRM, en la lectura y en las
 * cuatro escrituras.
 *
 * EL TEXTO LEGAL ({@see OutreachConsentConfig}): sin él, pedir y confirmar
 * dan 409 `consent_legal_text_missing` y no escriben nada. Se guarda su
 * versión y el sha256 del texto EXACTO que la pantalla enseñó (el CRM devuelve
 * la versión y la huella que recibió: si cambió entretanto, 409).
 *
 * EL NÚMERO: el servidor calcula el seudónimo (`phone_ref`, `outreach-phone/1`)
 * del número al que se escribiría hoy por WhatsApp (el de su identidad de
 * WhatsApp; si no tiene, su teléfono principal). El cliente nunca envía el
 * teléfono y ninguna respuesta lo devuelve, tampoco el seudónimo: solo si la
 * autorización es para el número de hoy (`numberCurrent`). Una RETIRADA
 * guarda también el de hoy, si lo hay (C-2 de la revisión de API): calla a
 * quien comparta ese número, como «no contactar» ({@see OutreachConsentFacts},
 * la regla de los hermanos); la lista lo dice con `numberRevoked`.
 *
 * IDEMPOTENTE por `clientRequestId`: repetir lo mismo devuelve lo mismo (200,
 * `replayed`); con otro contenido, 409 `client_request_reused`. Las carreras
 * las resuelve la base sin cerrojos (la aplicación no tiene UPDATE: ni `FOR
 * UPDATE`): dos confirmaciones a la vez, una gana y la otra 409 (el UNIQUE de
 * `request_uuid`); confirmar y retirar a la vez, gana la retirada al leer (la
 * confirmación cuenta en el lugar de su petición). Dos peticiones a la vez
 * para lo mismo pueden quedar las dos pendientes: inofensivo.
 *
 * LA EVIDENCIA se valida con TODO lo que la base rechazaría (`ocon_evidence_chk`):
 * nunca un 500 por un CHECK. El registro (`Log`) lleva el uuid, la finalidad,
 * la fuente y la cuenta; nunca la persona, su número, nombres ni la evidencia.
 */
final class OutreachConsents
{
    public const TABLE = 'outreach_consents';

    public const PERSON_MEMBER = 'member';

    public const PERSON_LEAD = 'lead';

    public const PERSON_TYPES = [self::PERSON_MEMBER, self::PERSON_LEAD];

    /** La llave de VER cada tipo de persona (autorización a nivel de objeto). */
    public const PERSON_VIEW = [self::PERSON_MEMBER => 'members.view', self::PERSON_LEAD => 'marketing.view'];

    public const EVIDENCE_MIN = 3;

    public const EVIDENCE_MAX = OutreachConsent::EVIDENCE_MAX;

    /** Los eventos que enseña la lista (los más recientes). */
    public const LIST_MAX = 200;

    /** La serie de 7 dígitos seguidos de `ocon_evidence_chk`. */
    private const DIGITS_7 = '/[0-9]{7,}/';

    /**
     * La serie de 8 dígitos con separadores de `ocon_evidence_chk`, y MÁS
     * estricta: allí el separador es lo que no es `[:alpha:]` ni `[:digit:]`
     * (depende del idioma de la base); aquí, lo que no es una letra o una cifra ASCII.
     */
    private const DIGIT_SERIES = '/[0-9](?:[^A-Za-z0-9]{0,3}[0-9]){7,}/u';

    /** La de la voz y los canarios (`StudioCanaryPhone::CONTACT_DIGIT_RUN`), con cifras Unicode. */
    private const DIGIT_SERIES_UNICODE = '/\d(?:[^\p{L}\d]{0,3}\d){7,}/u';

    /** El correo de `ocon_evidence_chk`, más estricto: el espacio que lo corta es solo el ASCII. */
    private const EMAIL = '/[^\x{0009}-\x{000D}\x{0020}@]+@[^\x{0009}-\x{000D}\x{0020}@]+\.[^\x{0009}-\x{000D}\x{0020}@]+/u';

    /** Los controles de `[[:cntrl:]]` en cualquier idioma de la base (C0, DEL, C1 y los separadores de línea y párrafo). */
    private const CONTROLS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u';

    private const COLUMNS = [
        'id', 'uuid', 'client_request_id', 'subject_key', 'member_id', 'lead_id', 'purpose', 'channel', 'kind', 'request_uuid', 'source',
        'evidence', 'legal_version', 'legal_hash', 'phone_ref', 'actor_type', 'recorded_by_admin_id', 'recorded_by_name', 'recorded_by_role',
        'confirmed_by_admin_id', 'confirmed_by_name', 'confirmed_by_role', 'created_at',
    ];

    public function __construct(private readonly SubjectResolver $resolver = new SubjectResolver) {}

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * GET: el estado por finalidad (la regla del lector de C9-2), las peticiones
     * pendientes, los últimos eventos y el texto legal vigente de cada finalidad.
     *
     * @return array<string, mixed>
     */
    public function list(OutreachAccess $access, string $type, int $id): array
    {
        $p = $this->person($access, $type, $id);
        $claves = OutreachConsentFacts::keysOf($p['subject']);
        $numeros = $this->phoneRefs($p['subject']);
        $hoy = $numeros['current'];
        $identidad = $numeros['whatsapp'] !== null;
        // La misma lectura que el motor: lo suyo y las retiradas vivas de otras claves con uno de sus números.
        $lectura = OutreachConsentFacts::read(['p' => $claves], ['p' => $numeros['all']]);
        $ganadores = $lectura['own']['p'] ?? [];
        $porNumero = $lectura['byNumber']['p'] ?? [];

        $pendientes = DB::table(self::TABLE.' as c')->whereIn('c.subject_key', $claves)->where('c.kind', OutreachConsent::KIND_REQUESTED)
            ->whereNotExists(fn ($q) => $q->from(self::TABLE.' as r')->whereColumn('r.request_uuid', 'c.uuid'))
            ->orderBy('c.id')->get(array_map(static fn (string $c): string => 'c.'.$c, self::COLUMNS))->all();
        $eventos = DB::table(self::TABLE)->whereIn('subject_key', $claves)->orderByDesc('id')->limit(self::LIST_MAX)->get(self::COLUMNS)->all();

        $finalidades = [];
        foreach (OutreachConsent::PURPOSES as $finalidad) {
            $g = $ganadores[$finalidad] ?? null;
            $vigente = $g !== null && $g['kind'] === OutreachConsent::KIND_GRANTED && $g['phone_ref'] !== null && $hoy !== null && hash_equals($hoy, $g['phone_ref']);
            $legal = OutreachConsentConfig::legalText($finalidad);
            $suyas = array_values(array_filter($pendientes, static fn (object $f): bool => $f->purpose === $finalidad));
            $finalidades[$finalidad] = [
                'state' => $g === null ? 'none' : (string) $g['kind'],
                'numberCurrent' => $g !== null && $g['kind'] === OutreachConsent::KIND_GRANTED ? $vigente : null,
                // Otra ficha con uno de sus números lo retiró y sigue retirado: el motor lo niega aunque aquí esté concedida.
                'numberRevoked' => isset($porNumero[$finalidad]),
                // Lo que el motor usaría HOY: confirmada, para el número de hoy, con identidad de WhatsApp y sin retirada por el número.
                'effective' => $vigente && $identidad && ! isset($porNumero[$finalidad]),
                'legal' => $legal === null ? null : self::legalView($legal),
                'pending' => array_map(fn (object $f): array => self::present($f, $hoy) + [
                    'ownRequest' => (int) $f->recorded_by_admin_id === $access->id(),
                    'superseded' => $this->superseded($f, $claves),
                ], $suyas),
            ];
        }

        return [
            'person' => ['type' => $p['type'], 'id' => $p['id'], 'recordsAs' => $p['recordsAs']],
            'whatsappIdentity' => $identidad,
            'hasPhone' => $hoy !== null,
            'purposes' => $finalidades,
            'events' => array_map(fn (object $f): array => self::present($f, $hoy), $eventos),
            'sources' => OutreachConsentConfig::sources(),
            'revokeSources' => OutreachConsent::SOURCES,
            'can' => ['record' => $access->can(OutreachAccess::CONSENTS_RECORD), 'confirm' => $access->can(OutreachAccess::CONSENTS_CONFIRM)],
        ];
    }

    // ── Escrituras ──────────────────────────────────────────────────────────

    /**
     * PEDIR: la primera cuenta registra la autorización con su evidencia y el
     * texto legal que se enseñó. Queda pendiente de otra cuenta.
     *
     * @param  array{clientRequestId: string, personType: string, personId: int, purpose: string, source: string, evidence: string, legalVersion: string, legalHash: string}  $in
     * @return array{http: int, consent: array<string, mixed>, replayed: bool, legal: array{version: string, text: string, hash: string}}
     *
     * @throws OutreachException
     */
    public function record(OutreachAccess $access, array $in): array
    {
        $legal = OutreachConsentConfig::legalText($in['purpose']);
        if ($legal === null) {
            throw self::legalMissing();
        }
        $errores = self::evidenceErrors($in['evidence']);
        if (! in_array($in['source'], OutreachConsentConfig::sources(), true)) {
            $errores['source'] = ['in'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $p = $this->person($access, $in['personType'], $in['personId']);
        $crid = strtolower($in['clientRequestId']);

        $mismo = static fn (object $f): bool => $f->kind === OutreachConsent::KIND_REQUESTED && (int) $f->recorded_by_admin_id === $access->id()
            && $f->subject_key === $p['key'] && $f->purpose === $in['purpose'] && $f->source === $in['source'] && $f->evidence === $in['evidence'];
        $previa = self::byClientRequest($crid);
        if ($previa !== null) {
            return self::replay($previa, $mismo, $this->currentPhoneRef($p['subject']), $legal);
        }
        if (! hash_equals($legal['version'], $in['legalVersion']) || ! hash_equals($legal['hash'], strtolower($in['legalHash']))) {
            throw OutreachException::conflict('consent_legal_text_changed', 'El texto legal cambió mientras registrabas: enséñale a la persona el vigente y vuelve a registrar.', ['legal' => self::legalView($legal)]);
        }
        $hoy = $this->currentPhoneRef($p['subject']);
        if ($hoy === null) {
            throw OutreachException::conflict('consent_no_phone', 'Esta persona no tiene un número válido: no hay a qué número autorizar.');
        }
        $pendiente = DB::table(self::TABLE.' as c')->whereIn('c.subject_key', OutreachConsentFacts::keysOf($p['subject']))
            ->where('c.kind', OutreachConsent::KIND_REQUESTED)->where('c.purpose', $in['purpose'])
            ->whereNotExists(fn ($q) => $q->from(self::TABLE.' as r')->whereColumn('r.request_uuid', 'c.uuid'))
            ->value('c.uuid');
        if ($pendiente !== null) {
            throw OutreachException::conflict('consent_request_pending', 'Ya hay una autorización de esta finalidad pendiente de confirmar para esta persona.', ['pendingUuid' => (string) $pendiente]);
        }

        $fila = [
            'uuid' => strtolower((string) Str::uuid()),
            'client_request_id' => $crid,
            'subject_key' => $p['key'],
            'member_id' => $p['memberId'],
            'lead_id' => $p['leadId'],
            'purpose' => $in['purpose'],
            'channel' => OutreachConsent::CHANNEL_WHATSAPP,
            'kind' => OutreachConsent::KIND_REQUESTED,
            'request_uuid' => null,
            'source' => $in['source'],
            'evidence' => $in['evidence'],
            'legal_version' => $legal['version'],
            'legal_hash' => $legal['hash'],
            'phone_ref' => $hoy,
            'actor_type' => OutreachConsent::ACTOR_ADMIN,
            ...self::recordedBy($access),
            'confirmed_by_admin_id' => null,
            'confirmed_by_name' => null,
            'confirmed_by_role' => null,
            'redacted_at' => null,
            'created_at' => now(),
        ];
        if (DB::table(self::TABLE)->insertOrIgnore($fila) === 0) {
            return self::replay(self::mustByClientRequest($crid), $mismo, $hoy, $legal);
        }
        Log::info('outreach.consent.requested', ['consent_uuid' => $fila['uuid'], 'purpose' => $fila['purpose'], 'source' => $fila['source'], 'by' => $access->id()]);

        return ['http' => 201, 'consent' => self::present(self::mustByClientRequest($crid), $hoy), 'replayed' => false, 'legal' => self::legalView($legal)];
    }

    /**
     * CONFIRMAR: OTRA cuenta (cuatro ojos por cuenta; la base también lo exige,
     * `ocon_four_eyes_chk`). Con el texto legal vigente igual al de la petición,
     * sin una retirada posterior y para el número de hoy.
     *
     * @return array{http: int, consent: array<string, mixed>, replayed: bool}
     *
     * @throws OutreachException
     */
    public function confirm(OutreachAccess $access, string $requestUuid, string $clientRequestId): array
    {
        $peticion = $this->visibleRequest($access, $requestUuid);
        $crid = strtolower($clientRequestId);
        $subject = $peticion['subject'];
        $f = $peticion['row'];
        $hoy = $this->currentPhoneRef($subject);
        $mismo = static fn (object $x): bool => $x->kind === OutreachConsent::KIND_GRANTED && $x->request_uuid === $f->uuid && (int) $x->confirmed_by_admin_id === $access->id();
        $previa = self::byClientRequest($crid);
        if ($previa !== null) {
            return self::replay($previa, $mismo, $hoy);
        }
        self::assertUnresolved($f);
        if ((int) $f->recorded_by_admin_id === $access->id()) {
            Log::warning('outreach.consent.four_eyes_denied', ['consent_uuid' => (string) $f->uuid, 'purpose' => (string) $f->purpose, 'by' => $access->id()]);

            throw new OutreachException('Nadie confirma una autorización que registró: tiene que confirmarla otra persona, con otra cuenta.', 'four_eyes_required', 403);
        }
        $legal = OutreachConsentConfig::legalText((string) $f->purpose);
        if ($legal === null) {
            throw self::legalMissing();
        }
        if ($legal['version'] !== $f->legal_version || $legal['hash'] !== trim((string) $f->legal_hash)) {
            throw OutreachException::conflict('consent_legal_text_changed', 'El texto legal cambió después de registrar esta autorización: hay que registrarla otra vez con el vigente.', ['legal' => self::legalView($legal)]);
        }
        $claves = OutreachConsentFacts::keysOf($subject);
        if ($this->superseded($f, $claves)) {
            throw self::superseded409();
        }
        if ($hoy === null || ! hash_equals($hoy, trim((string) $f->phone_ref))) {
            throw OutreachException::conflict('consent_number_changed', 'El número de la persona cambió después de registrar esta autorización: hay que registrarla otra vez.');
        }

        $fila = self::resolution($f, $crid, OutreachConsent::KIND_GRANTED, (string) $f->evidence, $access) + [
            'phone_ref' => trim((string) $f->phone_ref), 'legal_version' => $f->legal_version, 'legal_hash' => trim((string) $f->legal_hash),
        ];
        try {
            // Una sentencia, en su propio punto de guardado: si la guarda de la base la rechaza, la transacción de quien llama sigue viva (PG).
            $n = DB::transaction(static fn (): int => DB::table(self::TABLE)->insertOrIgnore($fila));
        } catch (QueryException $e) {
            // La carrera con una retirada que la base ya vio (`ocon_grant_not_superseded`): es una respuesta prevista.
            if ($this->superseded($f, $claves)) {
                throw self::superseded409();
            }

            throw $e;
        }
        if ($n === 0) {
            $previa = self::byClientRequest($crid);
            if ($previa !== null) {
                return self::replay($previa, $mismo, $hoy);
            }

            // El UNIQUE de `request_uuid`: otra confirmación o un rechazo llegaron antes.
            throw self::resolved409();
        }
        Log::info('outreach.consent.confirmed', ['consent_uuid' => $fila['uuid'], 'request_uuid' => (string) $f->uuid, 'purpose' => (string) $f->purpose, 'by' => $access->id()]);

        return ['http' => 201, 'consent' => self::present(self::mustByClientRequest($crid), $hoy), 'replayed' => false];
    }

    /**
     * RECHAZAR: la evidencia no basta. No espera a nadie (es la dirección segura).
     *
     * @return array{http: int, consent: array<string, mixed>, replayed: bool}
     *
     * @throws OutreachException
     */
    public function reject(OutreachAccess $access, string $requestUuid, string $clientRequestId, string $evidence): array
    {
        $errores = self::evidenceErrors($evidence);
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $peticion = $this->visibleRequest($access, $requestUuid);
        $f = $peticion['row'];
        $crid = strtolower($clientRequestId);
        $hoy = $this->currentPhoneRef($peticion['subject']);
        $mismo = static fn (object $x): bool => $x->kind === OutreachConsent::KIND_REJECTED && $x->request_uuid === $f->uuid
            && (int) $x->confirmed_by_admin_id === $access->id() && $x->evidence === $evidence;
        $previa = self::byClientRequest($crid);
        if ($previa !== null) {
            return self::replay($previa, $mismo, $hoy);
        }
        self::assertUnresolved($f);

        $fila = self::resolution($f, $crid, OutreachConsent::KIND_REJECTED, $evidence, $access) + ['phone_ref' => null, 'legal_version' => null, 'legal_hash' => null];
        if (DB::table(self::TABLE)->insertOrIgnore($fila) === 0) {
            $previa = self::byClientRequest($crid);
            if ($previa !== null) {
                return self::replay($previa, $mismo, $hoy);
            }

            throw self::resolved409();
        }
        Log::info('outreach.consent.rejected', ['consent_uuid' => $fila['uuid'], 'request_uuid' => (string) $f->uuid, 'purpose' => (string) $f->purpose, 'by' => $access->id()]);

        return ['http' => 201, 'consent' => self::present(self::mustByClientRequest($crid), $hoy), 'replayed' => false];
    }

    /**
     * RETIRAR: la persona ya no quiere (o nunca quiso) que le escriban por
     * WhatsApp para esta finalidad. Sin segunda cuenta y sin texto legal: vale
     * para la persona y gana a cualquier autorización anterior. Guarda el
     * seudónimo de su número de hoy, si lo tiene: calla también a quien
     * comparta ese número (la regla de los hermanos del lector). Sin número,
     * solo a ella.
     *
     * @param  array{clientRequestId: string, personType: string, personId: int, purpose: string, source: string, evidence: string}  $in
     * @return array{http: int, consent: array<string, mixed>, replayed: bool}
     *
     * @throws OutreachException
     */
    public function revoke(OutreachAccess $access, array $in): array
    {
        $errores = self::evidenceErrors($in['evidence']);
        if (! in_array($in['source'], OutreachConsent::SOURCES, true)) {
            $errores['source'] = ['in'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $p = $this->person($access, $in['personType'], $in['personId']);
        $crid = strtolower($in['clientRequestId']);
        $mismo = static fn (object $f): bool => $f->kind === OutreachConsent::KIND_REVOKED && (int) $f->recorded_by_admin_id === $access->id()
            && $f->subject_key === $p['key'] && $f->purpose === $in['purpose'] && $f->source === $in['source'] && $f->evidence === $in['evidence'];
        $previa = self::byClientRequest($crid);
        if ($previa !== null) {
            return self::replay($previa, $mismo, null);
        }
        $fila = [
            'uuid' => strtolower((string) Str::uuid()),
            'client_request_id' => $crid,
            'subject_key' => $p['key'],
            'member_id' => $p['memberId'],
            'lead_id' => $p['leadId'],
            'purpose' => $in['purpose'],
            'channel' => OutreachConsent::CHANNEL_WHATSAPP,
            'kind' => OutreachConsent::KIND_REVOKED,
            'request_uuid' => null,
            'source' => $in['source'],
            'evidence' => $in['evidence'],
            'legal_version' => null,
            'legal_hash' => null,
            // El número de hoy (o null): el lector calla con él a quien lo comparta (C-2).
            'phone_ref' => $this->currentPhoneRef($p['subject']),
            'actor_type' => OutreachConsent::ACTOR_ADMIN,
            ...self::recordedBy($access),
            'confirmed_by_admin_id' => null,
            'confirmed_by_name' => null,
            'confirmed_by_role' => null,
            'redacted_at' => null,
            'created_at' => now(),
        ];
        if (DB::table(self::TABLE)->insertOrIgnore($fila) === 0) {
            return self::replay(self::mustByClientRequest($crid), $mismo, null);
        }
        Log::info('outreach.consent.revoked', ['consent_uuid' => $fila['uuid'], 'purpose' => $fila['purpose'], 'source' => $fila['source'], 'by' => $access->id()]);

        return ['http' => 201, 'consent' => self::present(self::mustByClientRequest($crid), null), 'replayed' => false];
    }

    // ── Validación ──────────────────────────────────────────────────────────

    /**
     * Todo lo que `ocon_evidence_chk` (PG) o su disparador (SQLite) rechazarían,
     * y lo que la voz rechaza en sus notas: 422 con el campo, nunca su valor.
     *
     * @return array<string, list<string>>
     */
    public static function evidenceErrors(mixed $evidence, string $field = 'evidence'): array
    {
        if (! is_string($evidence)) {
            return [$field => ['invalid']];
        }
        $v = Validator::make([$field => $evidence], [$field => ['required', 'string', 'max:'.self::EVIDENCE_MAX, new PlainText(strict: true)]]);
        if ($v->fails() || ! mb_check_encoding($evidence, 'UTF-8') || preg_match(self::CONTROLS, $evidence) !== 0) {
            return [$field => ['invalid']];
        }
        if (mb_strlen(trim($evidence), 'UTF-8') < self::EVIDENCE_MIN || mb_strlen($evidence, 'UTF-8') > self::EVIDENCE_MAX) {
            return [$field => ['length']];
        }
        if (preg_match(self::DIGITS_7, $evidence) === 1 || preg_match(self::DIGIT_SERIES, $evidence) === 1
            || preg_match(self::DIGIT_SERIES_UNICODE, $evidence) === 1 || preg_match(self::EMAIL, $evidence) === 1) {
            return [$field => ['contact_data']];
        }

        return [];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La persona, si quien llama puede verla: la llave de su tipo ANTES de leer
     * nada (sin ella, 403 sin decir si existe); después, 404 si no es nadie,
     * si es un prospecto de un socio y no puede ver socios, o si es un socio
     * fuera del alcance del entrenador.
     *
     * @return array{subject: Subject, key: string, memberId: ?int, leadId: ?int, type: string, id: int, recordsAs: array{type: string, id: int}}
     *
     * @throws OutreachException
     */
    private function person(OutreachAccess $access, string $type, int $id): array
    {
        self::assertMayView($access, $type);
        $pedida = $type === self::PERSON_MEMBER ? SubjectKey::member($id) : SubjectKey::lead($id);
        $s = $this->resolver->forKeys([$pedida])[$pedida] ?? null;
        if ($s === null || ! $s->resolved() || $s->key === null) {
            throw OutreachException::notFound('person');
        }
        // El socio manda: un prospecto de un socio se registra como el socio, y verlo exige también ver socios. Sin esa llave,
        // 404 y no 403: que el prospecto es de un socio es justo lo que no puede saber (M-1).
        $canonica = SubjectKey::parse($s->key);
        $tipo = $canonica['kind'] === SubjectKey::MEMBER ? self::PERSON_MEMBER : self::PERSON_LEAD;
        if (! $access->can(self::PERSON_VIEW[$tipo])) {
            throw OutreachException::notFound('person');
        }
        if ($tipo === self::PERSON_MEMBER && ! self::inTrainerScope($access, (int) $canonica['id'])) {
            throw OutreachException::notFound('person');
        }

        return [
            'subject' => $s, 'key' => $s->key, 'type' => $type, 'id' => $id,
            'memberId' => $tipo === self::PERSON_MEMBER ? (int) $canonica['id'] : null,
            'leadId' => $tipo === self::PERSON_LEAD ? (int) $canonica['id'] : null,
            'recordsAs' => ['type' => $tipo, 'id' => (int) $canonica['id']],
        ];
    }

    /**
     * Una petición viva por su uuid, si quien llama puede ver a su persona; si
     * no, 404 como si no existiera.
     *
     * @return array{row: object, subject: Subject}
     *
     * @throws OutreachException
     */
    private function visibleRequest(OutreachAccess $access, string $uuid): array
    {
        $f = DB::table(self::TABLE)->where('uuid', strtolower($uuid))->where('kind', OutreachConsent::KIND_REQUESTED)->whereNull('redacted_at')->first(self::COLUMNS);
        if ($f === null || ! SubjectKey::isValid((string) $f->subject_key)) {
            throw OutreachException::notFound('consent');
        }
        $clave = (string) $f->subject_key;
        $tipo = SubjectKey::parse($clave)['kind'] === SubjectKey::MEMBER ? self::PERSON_MEMBER : self::PERSON_LEAD;
        if (! $access->can(self::PERSON_VIEW[$tipo])) {
            throw OutreachException::notFound('consent');
        }
        $s = $this->resolver->forKeys([$clave])[$clave] ?? null;
        if ($s === null || ! $s->resolved() || $s->key === null) {
            throw OutreachException::notFound('consent');
        }
        $partes = SubjectKey::parse($s->key);
        $canonica = $partes['kind'] === SubjectKey::MEMBER ? self::PERSON_MEMBER : self::PERSON_LEAD;
        if (! $access->can(self::PERSON_VIEW[$canonica])) {
            throw OutreachException::notFound('consent');
        }
        if ($canonica === self::PERSON_MEMBER && ! self::inTrainerScope($access, (int) $partes['id'])) {
            throw OutreachException::notFound('consent');
        }

        return ['row' => $f, 'subject' => $s];
    }

    /**
     * ¿Alcanza esta cuenta al socio? El alcance del entrenador del CRM
     * ({@see TrainerMemberScope::allowsMember()}): inerte para quien no es una
     * cuenta de entrenador. Fuera de su alcance, el socio no existe para ella
     * (404); el registro lo dice sin la persona.
     */
    private static function inTrainerScope(OutreachAccess $access, int $memberId): bool
    {
        if (app(TrainerMemberScope::class)->allowsMember($memberId)) {
            return true;
        }
        // Un entrenador tanteando socios ajenos es señal, no ruido (como `EnforceTrainerMemberScope`), sin la persona.
        Log::warning('outreach.consent.out_of_trainer_scope', ['by' => $access->id()]);

        return false;
    }

    /** @throws OutreachException 403 */
    private static function assertMayView(OutreachAccess $access, string $type): void
    {
        $llave = self::PERSON_VIEW[$type] ?? null;
        if ($llave === null) {
            throw OutreachException::invalid(['personType' => ['in']]);
        }
        if (! $access->can($llave)) {
            throw OutreachException::forbidden($llave);
        }
    }

    /** El seudónimo del número de hoy: el de su identidad de WhatsApp; si no tiene, el principal. */
    private function currentPhoneRef(Subject $s): ?string
    {
        return $this->phoneRefs($s)['current'];
    }

    /**
     * Los seudónimos de sus números de hoy: el de su identidad de WhatsApp (la
     * regla del lector de C9-2; null si no tiene), el que se autoriza al
     * registrar (ese o, sin identidad de WhatsApp, su teléfono principal) y
     * todos los suyos (la regla de los hermanos, {@see FactPass::phonesOf()}).
     *
     * @return array{whatsapp: ?string, current: ?string, all: list<string>}
     */
    private function phoneRefs(Subject $s): array
    {
        $pase = new FactPass(now(), [(string) $s->key => $s]);
        $whatsapp = OutreachConsentFacts::whatsappDestination($s, $pase->leadRows(), OutreachConsentFacts::whatsappLeads(array_map('intval', $s->leadIds)));
        $numero = $whatsapp ?? $pase->primaryPhoneOf($s);
        $todos = $pase->phonesOf($s);
        $clave = $numero === null && $todos === [] ? null : SubjectRef::appKey();

        return [
            'whatsapp' => $whatsapp === null ? null : PhoneRef::withKey($whatsapp, (string) $clave),
            'current' => $numero === null ? null : PhoneRef::withKey($numero, (string) $clave),
            'all' => array_map(static fn (string $n): string => PhoneRef::withKey($n, (string) $clave), $todos),
        ];
    }

    /** ¿Hay una retirada de lo mismo posterior a esta petición, en CUALQUIERA de las claves de la persona? @param  list<string>  $claves */
    private function superseded(object $peticion, array $claves): bool
    {
        return DB::table(self::TABLE)->whereIn('subject_key', $claves)->where('purpose', $peticion->purpose)->where('channel', $peticion->channel)
            ->where('kind', OutreachConsent::KIND_REVOKED)->where('id', '>', (int) $peticion->id)->exists();
    }

    /** @throws OutreachException 409 si la petición ya tiene confirmación o rechazo */
    private static function assertUnresolved(object $peticion): void
    {
        if (DB::table(self::TABLE)->where('request_uuid', $peticion->uuid)->exists()) {
            throw self::resolved409();
        }
    }

    /**
     * La confirmación o el rechazo copian de su petición lo que la base exige
     * (`ocon_resolves_request`); quien resuelve es la cuenta que llama.
     *
     * @return array<string, mixed>
     */
    private static function resolution(object $f, string $crid, string $kind, string $evidence, OutreachAccess $access): array
    {
        $quien = self::recordedBy($access);

        return [
            'uuid' => strtolower((string) Str::uuid()),
            'client_request_id' => $crid,
            'subject_key' => $f->subject_key,
            'member_id' => $f->member_id !== null ? (int) $f->member_id : null,
            'lead_id' => $f->lead_id !== null ? (int) $f->lead_id : null,
            'purpose' => $f->purpose,
            'channel' => $f->channel,
            'kind' => $kind,
            'request_uuid' => $f->uuid,
            'source' => $f->source,
            'evidence' => $evidence,
            'actor_type' => $f->actor_type,
            'recorded_by_admin_id' => $f->recorded_by_admin_id !== null ? (int) $f->recorded_by_admin_id : null,
            'recorded_by_name' => $f->recorded_by_name,
            'recorded_by_role' => $f->recorded_by_role,
            'confirmed_by_admin_id' => $quien['recorded_by_admin_id'],
            'confirmed_by_name' => $quien['recorded_by_name'],
            'confirmed_by_role' => $quien['recorded_by_role'],
            'redacted_at' => null,
            'created_at' => now(),
        ];
    }

    /** @return array{recorded_by_admin_id: int, recorded_by_name: string, recorded_by_role: ?string} la cuenta que actúa (instantánea, como la voz) */
    private static function recordedBy(OutreachAccess $access): array
    {
        $nombre = trim(mb_substr((string) $access->actor->name, 0, 120, 'UTF-8'));
        $rol = $access->actor->role !== null ? mb_substr((string) $access->actor->role, 0, 60, 'UTF-8') : null;

        return [
            'recorded_by_admin_id' => $access->id(),
            // `ocon_actor_chk` exige un nombre: una cuenta sin nombre se registra por su número.
            'recorded_by_name' => $nombre !== '' ? $nombre : 'Cuenta '.$access->id(),
            'recorded_by_role' => $rol !== null && trim($rol) !== '' ? $rol : null,
        ];
    }

    private static function byClientRequest(string $crid): ?object
    {
        return DB::table(self::TABLE)->where('client_request_id', $crid)->first(self::COLUMNS);
    }

    private static function mustByClientRequest(string $crid): object
    {
        $f = self::byClientRequest($crid);
        if ($f === null) {
            // Se acaba de insertar (o la base dijo que ya existía): no verla es un fallo de la base, no del usuario.
            throw new \RuntimeException('outreach: la autorización recién escrita no se puede leer.');
        }

        return $f;
    }

    /**
     * Una petición repetida: lo mismo, 200 con lo que se guardó; otra cosa con el mismo identificador, 409.
     *
     * @param  callable(object): bool  $mismo
     * @param  array{version: string, text: string, hash: string}|null  $legal
     * @return array{http: int, consent: array<string, mixed>, replayed: bool, legal?: array{version: string, text: string, hash: string}}
     *
     * @throws OutreachException
     */
    private static function replay(object $previa, callable $mismo, ?string $hoy, ?array $legal = null): array
    {
        if (! $mismo($previa)) {
            throw OutreachException::conflict('client_request_reused', 'Ese identificador de petición ya se usó para otra cosa.');
        }
        $r = ['http' => 200, 'consent' => self::present($previa, $hoy), 'replayed' => true];
        if ($legal !== null) {
            $r['legal'] = self::legalView($legal);
        }

        return $r;
    }

    /** @return array<string, mixed> una fila del libro para el CRM: sin número, sin seudónimos, sin la persona */
    private static function present(object $f, ?string $hoy): array
    {
        // `numberCurrent` es de una autorización (petición o confirmación); el número de una retirada no se enseña.
        $ref = $f->phone_ref !== null && $f->kind !== OutreachConsent::KIND_REVOKED ? trim((string) $f->phone_ref) : null;

        return [
            'uuid' => (string) $f->uuid,
            'kind' => (string) $f->kind,
            'purpose' => (string) $f->purpose,
            'channel' => (string) $f->channel,
            'source' => (string) $f->source,
            'evidence' => (string) $f->evidence,
            'requestUuid' => $f->request_uuid !== null ? (string) $f->request_uuid : null,
            'legalVersion' => $f->legal_version !== null ? (string) $f->legal_version : null,
            'legalHash' => $f->legal_hash !== null ? trim((string) $f->legal_hash) : null,
            'numberCurrent' => $ref === null ? null : ($hoy !== null && PhoneRef::isValid($ref) && hash_equals($hoy, $ref)),
            'recordedBy' => [
                'type' => (string) $f->actor_type,
                'id' => $f->recorded_by_admin_id !== null ? (int) $f->recorded_by_admin_id : null,
                'name' => (string) $f->recorded_by_name,
                'role' => $f->recorded_by_role !== null ? (string) $f->recorded_by_role : null,
            ],
            'confirmedBy' => $f->confirmed_by_admin_id === null ? null : [
                'id' => (int) $f->confirmed_by_admin_id,
                'name' => (string) $f->confirmed_by_name,
                'role' => $f->confirmed_by_role !== null ? (string) $f->confirmed_by_role : null,
            ],
            'createdAt' => (new DateTimeImmutable((string) $f->created_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param  array{version: string, text: string, hash: string}  $legal
     * @return array{version: string, text: string, hash: string}
     */
    private static function legalView(array $legal): array
    {
        return ['version' => $legal['version'], 'text' => $legal['text'], 'hash' => $legal['hash']];
    }

    private static function legalMissing(): OutreachException
    {
        return OutreachException::conflict(OutreachConsentConfig::CODE_LEGAL_TEXT_MISSING,
            'Todavía no hay un texto legal aprobado para esta autorización: lo fija el propietario con su asesoría. No se ha guardado nada.');
    }

    private static function resolved409(): OutreachException
    {
        return OutreachException::conflict('consent_request_resolved', 'Esta autorización ya se confirmó o se rechazó.');
    }

    private static function superseded409(): OutreachException
    {
        return OutreachException::conflict('consent_request_superseded', 'La persona retiró esta autorización después de que se registrara: hace falta registrarla otra vez.');
    }
}
