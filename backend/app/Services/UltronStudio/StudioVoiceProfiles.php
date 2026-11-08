<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * LOS PERFILES DE VOZ (contrato H8, D8-6 y D8-22): crear una revisión, enviarla
 * a revisión, aprobarla o rechazarla, y leerlos. Es REGISTRO del Studio: nada
 * de aquí cambia lo que dice la voz hasta que una versión aprobada y un perfil
 * aprobado se publican ({@see StudioVoicePublishing}).
 *
 * EL CONTENIDO es el conjunto CERRADO del compilador
 * ({@see StudioVoiceCompiler::PROFILE_FIELDS}) y se comprueba con SU función
 * ({@see StudioVoiceCompiler::profileContent()}): al crear, al enviar y al
 * aprobar, con el mismo motivo y el mismo campo que daría al compilar (422
 * `voice_compile_refused`). Lo que no decide quien escribe lo pone el servidor:
 * el formato, el idioma (`es`), el AVISO de la plantilla vigente (P-9, E1-3:
 * aprobar un perfil es aprobar también ese texto exacto), y la grabación y el
 * audio guardado APAGADOS (P-7, P-8; columnas `record_calls` y `record_voice`
 * forzadas a false, E3-3).
 *
 * LOS DESTINOS DE TRANSFERENCIA (D8-14, D8-17, como mucho 3): en el contenido
 * solo su alias y la HUELLA `voice-phone` de su teléfono ({@see phoneHash()});
 * el número va CIFRADO aparte (cast `encrypted:array`) y solo se descifra en
 * memoria al desplegar ({@see transferNumbers()}). Ningún DTO lleva el número
 * ni su huella: el alias y los 4 últimos dígitos.
 *
 * CUATRO OJOS POR IDENTIDAD: solo quien creó el perfil lo envía a revisión
 * (como las versiones del H5) y nadie aprueba ni rechaza el que creó (la base
 * lo impide también: `usvp_four_eyes_chk`). El contenido NACE CONGELADO (D-7
 * de DB8): cambiar un perfil es crear otra revisión (`basedOn`).
 *
 * IDEMPOTENCIA: la misma `clientRequestId`, la misma persona y el mismo cuerpo
 * devuelven el perfil de la primera vez (200 `replayed`); otra cosa es 409
 * `client_request_reused`. El 23505 se resuelve FUERA de la transacción.
 */
final class StudioVoiceProfiles
{
    /** D8-22: editar el perfil de voz, enviarlo a revisión y previsualizar la compilación. */
    public const MANAGE = 'ultron.voice_manage';

    /** La etiqueta de la subclave de la huella de un teléfono de voz (D8-17). */
    public const VOICE_PHONE_LABEL = 'voice-phone';

    public const LABEL_MIN = 3;

    public const LABEL_MAX = 80;

    public const NOTE_MIN = 5;

    public const NOTE_MAX = 500;

    public const PER_PAGE_MAX = 50;

    /** Los campos que escribe quien crea el perfil; el resto lo pone el servidor. */
    public const INPUT_FIELDS = ['voiceId', 'greetings', 'holes', 'maxDurationSeconds', 'tools', 'systemTools', 'transfer', 'privacy', 'voicemail'];

    /** Un destino, como lo escribe quien crea el perfil: el teléfono va en claro hasta cifrarlo. */
    public const DESTINATION_INPUT_FIELDS = ['alias', 'phone'];

    /** Las columnas de la lista (sin el contenido ni el cifrado). */
    private const LIST_COLUMNS = [
        'id', 'uuid', 'revision', 'based_on_profile_id', 'label', 'language', 'max_duration_seconds', 'retention_days', 'record_calls', 'record_voice',
        'transfer_count', 'content_hash', 'status', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
        'submitted_by_admin_id', 'submitted_by_name', 'submitted_by_role', 'submitted_at',
        'reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at', 'review_note', 'status_changed_at',
    ];

    private const STATUS_LABELS = [
        StudioVoiceProfile::STATUS_DRAFT => 'Borrador',
        StudioVoiceProfile::STATUS_SUBMITTED => 'En revisión',
        StudioVoiceProfile::STATUS_APPROVED => 'Aprobado',
        StudioVoiceProfile::STATUS_REJECTED => 'Rechazado',
    ];

    public function __construct(private readonly StudioAudit $audit) {}

    // ── Lecturas ────────────────────────────────────────────────────────────

    /** `GET voice/profiles`: de la más reciente a la más antigua, sin el contenido. */
    public static function page(StudioAccess $access, ?string $status, int $perPage): LengthAwarePaginator
    {
        return StudioVoiceProfile::query()
            ->select(self::LIST_COLUMNS)
            ->with('basedOn:id,uuid,revision')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(static fn (StudioVoiceProfile $p): array => self::summaryDto($p, $access));
    }

    /** @throws UltronStudioException 404 */
    public static function findByUuid(string $uuid): StudioVoiceProfile
    {
        return StudioVoiceProfile::query()->with('basedOn:id,uuid,revision')->where('uuid', strtolower($uuid))->first()
            ?? throw self::notFound();
    }

    // ── Crear (POST voice/profiles) ─────────────────────────────────────────

    /**
     * Una revisión nueva, en `draft`, con el contenido congelado.
     *
     * @param  array<string, mixed>  $input  los campos de {@see INPUT_FIELDS} (validados en la forma por el controlador)
     * @return array{profile:StudioVoiceProfile,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, string $clientRequestId, string $label, ?string $basedOnUuid, #[\SensitiveParameter] array $input): array
    {
        $access->require(self::MANAGE);

        $base = $basedOnUuid === null ? null : self::findByUuid($basedOnUuid);
        [$contenido, $destinos] = self::buildContent($input);

        // El mismo veredicto que al compilar, con su campo (sin repetir el
        // texto), ANTES de firmar: lo que no tiene forma (un decimal) no se firma.
        StudioVoiceCompiler::profileContent($contenido, StudioVoicePublishing::maxCallSeconds());
        $huella = StudioVoiceCompiler::profileHash($contenido);
        $cuerpo = ['label' => $label, 'basedOn' => $base?->id, 'contentHash' => $huella];

        if ($previo = $this->replay($access, $clientRequestId, $cuerpo)) {
            return ['profile' => $previo, 'replayed' => true];
        }

        try {
            $perfil = DB::transaction(function () use ($access, $request, $clientRequestId, $label, $base, $contenido, $destinos, $huella): StudioVoiceProfile {
                $ahora = now();
                $yo = $access->snapshot();
                $perfil = StudioVoiceProfile::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => $clientRequestId,
                    'revision' => $base === null ? 1 : (int) $base->revision + 1,
                    'based_on_profile_id' => $base?->id,
                    'label' => $label,
                    'language' => StudioVoiceTemplate::LANGUAGE,
                    'max_duration_seconds' => $contenido['maxDurationSeconds'],
                    'retention_days' => $contenido['privacy']['retentionDays'],
                    // P-7 y P-8 (E3-3): nunca otra cosa.
                    'record_calls' => false,
                    'record_voice' => false,
                    'transfer_count' => count($destinos),
                    'transfer_destinations_encrypted' => $destinos === [] ? null : $destinos,
                    'content' => $contenido,
                    'content_hash' => $huella,
                    'status' => StudioVoiceProfile::STATUS_DRAFT,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'created_at' => $ahora,
                    'status_changed_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                $this->audit->record($request, $access->actor, 'voice_profile.created', StudioAuditEvent::SUBJECT_INTEGRATION, $perfil->uuid,
                    "Creó el perfil de voz «{$label}» (revisión {$perfil->revision})", [
                        'toStatus' => StudioVoiceProfile::STATUS_DRAFT,
                        'contentHash' => $huella,
                        'diff' => ['revision' => (int) $perfil->revision, 'basedOn' => $base?->uuid, 'transferCount' => count($destinos)],
                    ]);

                return $perfil;
            });
        } catch (UniqueConstraintViolationException $e) {
            // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada).
            if ($previo = $this->replay($access, $clientRequestId, $cuerpo)) {
                return ['profile' => $previo, 'replayed' => true];
            }

            throw $e;
        }

        return ['profile' => self::findByUuid((string) $perfil->uuid), 'replayed' => false];
    }

    // ── Enviar, aprobar, rechazar ───────────────────────────────────────────

    /** `POST voice/profiles/{uuid}/submit`: solo quien lo creó, desde `draft`. */
    public function submit(StudioAccess $access, Request $request, string $uuid): StudioVoiceProfile
    {
        $access->require(self::MANAGE);
        $perfil = self::findByUuid($uuid);
        if ((int) $perfil->created_by_admin_id !== $access->id()) {
            throw new UltronStudioException('Solo quien creó el perfil de voz puede enviarlo a revisión.', 'not_profile_owner', 403);
        }
        self::assertStatus($perfil, [StudioVoiceProfile::STATUS_DRAFT], 'enviar a revisión');
        self::assertReviewable($perfil);

        DB::transaction(function () use ($access, $request, $perfil): void {
            $ahora = now();
            $yo = $access->snapshot();
            if (! StudioVoiceProfile::transition($perfil->id, [StudioVoiceProfile::STATUS_DRAFT], [
                'status' => StudioVoiceProfile::STATUS_SUBMITTED,
                'submitted_by_admin_id' => $yo['id'],
                'submitted_by_name' => $yo['name'],
                'submitted_by_role' => $yo['role'],
                'submitted_at' => $ahora,
                'status_changed_at' => $ahora,
            ])) {
                throw self::movedMeanwhile($perfil, 'enviar a revisión', [StudioVoiceProfile::STATUS_DRAFT]);
            }

            $this->audit->record($request, $access->actor, 'voice_profile.submitted', StudioAuditEvent::SUBJECT_INTEGRATION, $perfil->uuid,
                "Envió a revisión el perfil de voz «{$perfil->label}»", [
                    'fromStatus' => StudioVoiceProfile::STATUS_DRAFT,
                    'toStatus' => StudioVoiceProfile::STATUS_SUBMITTED,
                    'contentHash' => $perfil->content_hash,
                ]);
        });

        return self::findByUuid($perfil->uuid);
    }

    /** `POST voice/profiles/{uuid}/approve`: otra identidad que quien lo creó, desde `submitted`. */
    public function approve(StudioAccess $access, Request $request, string $uuid, ?string $note): StudioVoiceProfile
    {
        return $this->review($access, $request, $uuid, StudioVoiceProfile::STATUS_APPROVED, $note);
    }

    /** `POST voice/profiles/{uuid}/reject`: otra identidad, con nota, desde `submitted`. */
    public function reject(StudioAccess $access, Request $request, string $uuid, string $note): StudioVoiceProfile
    {
        return $this->review($access, $request, $uuid, StudioVoiceProfile::STATUS_REJECTED, $note);
    }

    // ── Para quien publica (S8-b) y el laboratorio (LAB8) ───────────────────

    /**
     * La entrada del compilador desde la fila: `{uuid, status, contentHash,
     * content, createdBy, approvedBy}` (la forma de {@see StudioVoiceCompiler::compile()}).
     *
     * @return array{uuid:string,status:string,contentHash:string,content:array<string,mixed>,createdBy:int,approvedBy:int|null}
     */
    public static function compilerInput(StudioVoiceProfile $p): array
    {
        return [
            'uuid' => (string) $p->uuid,
            'status' => (string) $p->status,
            'contentHash' => (string) $p->content_hash,
            'content' => is_array($p->content) ? $p->content : [],
            'createdBy' => (int) $p->created_by_admin_id,
            'approvedBy' => $p->status === StudioVoiceProfile::STATUS_APPROVED && $p->reviewed_by_admin_id !== null ? (int) $p->reviewed_by_admin_id : null,
        ];
    }

    /**
     * Los teléfonos de los destinos, DESCIFRADOS EN MEMORIA para la carga del
     * proveedor: `ref` (la huella) → E.164. Solo para desplegar; nunca se
     * guarda, se devuelve ni se registra.
     *
     * @return array<string, string>
     */
    public static function transferNumbers(StudioVoiceProfile $p): array
    {
        if ((int) $p->transfer_count === 0) {
            return [];
        }
        $lista = $p->transfer_destinations_encrypted;
        $numeros = [];
        foreach (is_array($lista) ? $lista : [] as $d) {
            if (is_array($d) && is_string($d['phoneHash'] ?? null) && is_string($d['e164'] ?? null)) {
                $numeros[$d['phoneHash']] = $d['e164'];
            }
        }

        return $numeros;
    }

    /**
     * La huella de voz de un E.164 (D8-17): HMAC-SHA256 con la subclave de la
     * `APP_KEY` de etiqueta `voice-phone`, la misma derivación que las demás
     * huellas (la `APP_KEY` es la CLAVE y la etiqueta, el dato). La telefonía
     * calcula la del número de una llamada con la MISMA etiqueta: el fixture
     * `policy-tables.json` (sección `phoneHash`) fija las dos.
     *
     * @throws InvalidArgumentException si no es un E.164 estricto
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function phoneHash(#[\SensitiveParameter] string $e164): string
    {
        if (preg_match(PhoneFingerprint::E164_PATTERN, $e164) !== 1) {
            throw new InvalidArgumentException('El teléfono no es un E.164 estricto.');
        }
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7));
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se calcula la huella de un teléfono de voz.');
        }

        return hash_hmac('sha256', $e164, hash_hmac('sha256', self::VOICE_PHONE_LABEL, $clave, true));
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * `VoiceProfileSummaryDto`: sin el contenido ni los destinos.
     *
     * @return array<string, mixed>
     */
    public static function summaryDto(StudioVoiceProfile $p, ?StudioAccess $viewer = null): array
    {
        $dto = [
            'uuid' => (string) $p->uuid,
            'revision' => (int) $p->revision,
            'basedOn' => $p->relationLoaded('basedOn') && $p->basedOn !== null ? ['uuid' => (string) $p->basedOn->uuid, 'revision' => (int) $p->basedOn->revision] : null,
            'label' => (string) $p->label,
            'status' => (string) $p->status,
            'statusLabel' => self::STATUS_LABELS[$p->status] ?? (string) $p->status,
            'contentHash' => (string) $p->content_hash,
            'shortHash' => substr((string) $p->content_hash, 0, 8),
            'language' => (string) $p->language,
            'maxDurationSeconds' => (int) $p->max_duration_seconds,
            'retentionDays' => (int) $p->retention_days,
            'recordCalls' => (bool) $p->record_calls,
            'recordVoice' => (bool) $p->record_voice,
            'transferCount' => (int) $p->transfer_count,
            'createdBy' => ['name' => (string) $p->created_by_name, 'role' => $p->created_by_role],
            'createdAt' => $p->created_at?->toIso8601String(),
            'submittedBy' => $p->submitted_by_admin_id === null ? null : ['name' => (string) $p->submitted_by_name, 'role' => $p->submitted_by_role],
            'submittedAt' => $p->submitted_at?->toIso8601String(),
            'reviewedBy' => $p->reviewed_by_admin_id === null ? null : ['name' => (string) $p->reviewed_by_name, 'role' => $p->reviewed_by_role],
            'reviewedAt' => $p->reviewed_at?->toIso8601String(),
            'reviewNote' => $p->review_note,
        ];
        if ($viewer !== null) {
            $dto['actions'] = self::actions($viewer, $p);
        }

        return $dto;
    }

    /**
     * `VoiceProfileDto`: el resumen más el contenido, con cada destino como su
     * alias y sus 4 últimos dígitos (descifrados en memoria), nunca el número
     * ni su huella.
     *
     * @return array<string, mixed>
     */
    public static function detailDto(StudioVoiceProfile $p, StudioAccess $viewer): array
    {
        $contenido = is_array($p->content) ? $p->content : [];
        $ultimos = [];
        try {
            foreach (is_array($p->transfer_destinations_encrypted) ? $p->transfer_destinations_encrypted : [] as $d) {
                if (is_array($d) && is_string($d['phoneHash'] ?? null) && is_string($d['e164'] ?? null)) {
                    $ultimos[$d['phoneHash']] = substr($d['e164'], -4);
                }
            }
        } catch (Throwable $e) {
            // Tragado a propósito: sin poder descifrar (APP_KEY rotada) se ve
            // el alias sin dígitos; nunca se inventan. Al log, solo la clase.
            Log::warning('ultron_studio.voice_profile_destinations_unreadable', ['error_class' => class_basename($e)]);
        }
        $destinos = [];
        foreach (is_array($contenido['transfer']['destinations'] ?? null) ? $contenido['transfer']['destinations'] : [] as $d) {
            $destinos[] = [
                'alias' => (string) ($d['alias'] ?? ''),
                'last4' => is_string($d['phoneHash'] ?? null) ? ($ultimos[$d['phoneHash']] ?? null) : null,
            ];
        }
        unset($contenido['transfer']);
        $contenido['transfer'] = ['destinations' => $destinos];

        return self::summaryDto($p, $viewer) + ['content' => $contenido];
    }

    /**
     * Lo que puede hacer quien mira con este perfil, y si no, por qué. Solo lo
     * que se sabe sin leer nada más: el permiso, la autoría y el estado.
     *
     * @return array{submit:array{allowed:bool,code?:string},approve:array{allowed:bool,code?:string},reject:array{allowed:bool,code?:string}}
     */
    public static function actions(StudioAccess $viewer, StudioVoiceProfile $p): array
    {
        $mio = (int) $p->created_by_admin_id === $viewer->id();
        $no = static fn (string $code): array => ['allowed' => false, 'code' => $code];
        $revisar = match (true) {
            ! $viewer->can(StudioAccess::APPROVE) => $no('forbidden'),
            $mio => $no('four_eyes_required'),
            $p->status !== StudioVoiceProfile::STATUS_SUBMITTED => $no('invalid_transition'),
            default => ['allowed' => true],
        };

        return [
            'submit' => match (true) {
                ! $viewer->can(self::MANAGE) => $no('forbidden'),
                ! $mio => $no('not_profile_owner'),
                $p->status !== StudioVoiceProfile::STATUS_DRAFT => $no('invalid_transition'),
                default => ['allowed' => true],
            },
            'approve' => $revisar,
            'reject' => $revisar,
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * El contenido del perfil a partir de lo que escribe quien lo crea: el
     * servidor pone el formato, el idioma, el aviso de la plantilla y la
     * privacidad apagada; los destinos pasan a `{alias, phoneHash}` y su número
     * va aparte, para cifrarlo.
     *
     * @param  array<string, mixed>  $input
     * @return array{0:array<string,mixed>,1:list<array{alias:string,phoneHash:string,e164:string}>}
     */
    private static function buildContent(#[\SensitiveParameter] array $input): array
    {
        // Un conjunto CERRADO también en la entrada: un campo inventado no se
        // ignora en silencio (`profile_field_unknown`, con su ruta).
        self::closedInput($input, self::INPUT_FIELDS, 'profile.content');
        foreach (['greetings' => StudioVoiceCompiler::GREETING_FIELDS, 'holes' => array_keys(StudioVoiceTemplate::HOLES),
            'transfer' => StudioVoiceCompiler::TRANSFER_FIELDS, 'privacy' => StudioVoiceCompiler::PRIVACY_FIELDS] as $campo => $claves) {
            if (is_array($input[$campo] ?? null)) {
                self::closedInput($input[$campo], $claves, "profile.content.{$campo}");
            }
        }
        foreach (is_array($input['transfer']['destinations'] ?? null) ? $input['transfer']['destinations'] : [] as $i => $d) {
            if (is_array($d)) {
                self::closedInput($d, self::DESTINATION_INPUT_FIELDS, "profile.content.transfer.destinations.{$i}");
            }
        }

        $destinos = [];
        $enContenido = [];
        foreach (is_array($input['transfer']['destinations'] ?? null) ? $input['transfer']['destinations'] : [] as $d) {
            $alias = is_array($d) && is_string($d['alias'] ?? null) ? $d['alias'] : '';
            $telefono = is_array($d) && is_string($d['phone'] ?? null) ? trim($d['phone']) : '';
            try {
                $huella = self::phoneHash($telefono);
            } catch (InvalidArgumentException) {
                throw StudioVoiceCompiler::refusal('profile_field_invalid', 'profile.content.transfer.destinations.'.count($enContenido).'.phone');
            }
            $enContenido[] = ['alias' => $alias, 'phoneHash' => $huella];
            $destinos[] = ['alias' => $alias, 'phoneHash' => $huella, 'e164' => $telefono];
        }
        usort($destinos, static fn (array $a, array $b): int => strcmp($a['alias'], $b['alias']));

        $privacidad = is_array($input['privacy'] ?? null) ? $input['privacy'] : [];
        $contenido = [
            'format' => StudioVoiceCompiler::PROFILE_FORMAT,
            'voiceId' => $input['voiceId'] ?? null,
            'language' => StudioVoiceTemplate::LANGUAGE,
            'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            'greetings' => $input['greetings'] ?? null,
            'holes' => $input['holes'] ?? null,
            'maxDurationSeconds' => $input['maxDurationSeconds'] ?? null,
            'tools' => $input['tools'] ?? null,
            'systemTools' => $input['systemTools'] ?? null,
            'transfer' => ['destinations' => $enContenido],
            // P-7 y P-8: apagados salvo que alguien pida encenderlos, y entonces
            // el compilador se niega con su motivo (nadie cree haberlos encendido).
            'privacy' => [
                'retentionDays' => $privacidad['retentionDays'] ?? null,
                'storeAudio' => $privacidad['storeAudio'] ?? false,
                'recording' => $privacidad['recording'] ?? false,
            ],
            'voicemail' => $input['voicemail'] ?? null,
        ];

        return [$contenido, $destinos];
    }

    /**
     * Solo claves de `$keys`; la primera que no lo es, `profile_field_unknown`
     * con su ruta (recortada: nunca repite un texto largo).
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys
     */
    private static function closedInput(array $value, array $keys, string $base): void
    {
        foreach (array_keys($value) as $clave) {
            if (! in_array($clave, $keys, true)) {
                throw StudioVoiceCompiler::refusal('profile_field_unknown', $base.'.'.mb_substr((string) $clave, 0, 40, 'UTF-8'));
            }
        }
    }

    /**
     * Aprobar o rechazar: el permiso, el perfil, los cuatro ojos (el intento
     * queda en el libro), el estado y, al aprobar, que sigue íntegro y que su
     * contenido sigue compilando con la plantilla y el tope de hoy.
     */
    private function review(StudioAccess $access, Request $request, string $uuid, string $destino, ?string $note): StudioVoiceProfile
    {
        $access->require(StudioAccess::APPROVE);
        $perfil = self::findByUuid($uuid);
        $verbo = $destino === StudioVoiceProfile::STATUS_APPROVED ? 'aprobar' : 'rechazar';
        if ((int) $perfil->created_by_admin_id === $access->id()) {
            $this->audit->recordDenied($request, $access->actor, 'voice_profile.four_eyes_denied', StudioAuditEvent::SUBJECT_INTEGRATION, $perfil->uuid,
                'Intentó '.$verbo." su propio perfil de voz «{$perfil->label}»", [
                    'fromStatus' => $perfil->status,
                    'contentHash' => $perfil->content_hash,
                    'diff' => ['action' => $verbo],
                ]);

            throw new UltronStudioException('Nadie aprueba ni rechaza lo que creó: pide a otra persona con permiso que revise este perfil de voz.', 'four_eyes_required', 403);
        }
        self::assertStatus($perfil, [StudioVoiceProfile::STATUS_SUBMITTED], $verbo);
        if ($destino === StudioVoiceProfile::STATUS_APPROVED) {
            self::assertReviewable($perfil);
        }

        DB::transaction(function () use ($access, $request, $perfil, $destino, $note, $verbo): void {
            $ahora = now();
            $yo = $access->snapshot();
            if (! StudioVoiceProfile::transition($perfil->id, [StudioVoiceProfile::STATUS_SUBMITTED], [
                'status' => $destino,
                'reviewed_by_admin_id' => $yo['id'],
                'reviewed_by_name' => $yo['name'],
                'reviewed_by_role' => $yo['role'],
                'reviewed_at' => $ahora,
                'review_note' => $note,
                'status_changed_at' => $ahora,
            ])) {
                throw self::movedMeanwhile($perfil, $verbo, [StudioVoiceProfile::STATUS_SUBMITTED]);
            }

            $this->audit->record($request, $access->actor, $destino === StudioVoiceProfile::STATUS_APPROVED ? 'voice_profile.approved' : 'voice_profile.rejected',
                StudioAuditEvent::SUBJECT_INTEGRATION, $perfil->uuid,
                ($destino === StudioVoiceProfile::STATUS_APPROVED ? 'Aprobó' : 'Rechazó')." el perfil de voz «{$perfil->label}»", [
                    'fromStatus' => StudioVoiceProfile::STATUS_SUBMITTED,
                    'toStatus' => $destino,
                    'contentHash' => $perfil->content_hash,
                    'diff' => ['action' => $verbo, 'note' => $note],
                ]);
        });

        return self::findByUuid($perfil->uuid);
    }

    /**
     * Que el perfil siga íntegro (su huella es la de su contenido) y que su
     * contenido compile con la plantilla y el tope de hoy: una plantilla con
     * otro aviso deja el perfil sin poder aprobarse (`notice_mismatch`).
     */
    private static function assertReviewable(StudioVoiceProfile $p): void
    {
        $contenido = is_array($p->content) ? $p->content : [];
        try {
            $huella = StudioVoiceCompiler::profileHash($contenido);
        } catch (Throwable) {
            $huella = null;   // no se puede firmar: no es íntegro
        }
        if ($huella === null || ! hash_equals((string) $p->content_hash, $huella)) {
            throw StudioVoiceCompiler::refusal('profile_hash_mismatch', 'profile.contentHash');
        }
        StudioVoiceCompiler::profileContent($contenido, StudioVoicePublishing::maxCallSeconds());
    }

    /** @param  list<string>  $from */
    private static function assertStatus(StudioVoiceProfile $p, array $from, string $accion): void
    {
        if (! in_array($p->status, $from, true)) {
            throw self::invalidTransition((string) $p->status, $accion, $from);
        }
    }

    /**
     * La fila ya no estaba donde la dejaron las comprobaciones: 409
     * `invalid_transition` con el estado de AHORA.
     *
     * @param  list<string>  $from
     */
    private static function movedMeanwhile(StudioVoiceProfile $p, string $accion, array $from): UltronStudioException
    {
        $ahora = (string) StudioVoiceProfile::query()->whereKey($p->id)->value('status');

        return self::invalidTransition($ahora, $accion, $from);
    }

    /** @param  list<string>  $from */
    private static function invalidTransition(string $actual, string $accion, array $from): UltronStudioException
    {
        $etiqueta = self::STATUS_LABELS[$actual] ?? $actual;

        return new UltronStudioException("Un perfil de voz en estado «{$etiqueta}» no admite «{$accion}».", 'invalid_transition', 409,
            ['currentStatus' => $actual, 'allowedFrom' => $from]);
    }

    private static function notFound(): UltronStudioException
    {
        return new UltronStudioException('El perfil de voz no existe.', 'voice_profile_not_found', 404);
    }

    /**
     * La misma petición repetida devuelve el perfil de la primera vez; la misma
     * llave de otra persona o con otro cuerpo es 409 `client_request_reused`.
     *
     * @param  array{label:string,basedOn:int|null,contentHash:string}  $cuerpo
     */
    private function replay(StudioAccess $access, string $clientRequestId, array $cuerpo): ?StudioVoiceProfile
    {
        $previo = StudioVoiceProfile::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previo === null) {
            return null;
        }
        if ((int) $previo->created_by_admin_id !== $access->id()
            || $previo->label !== $cuerpo['label']
            || ($previo->based_on_profile_id === null ? null : (int) $previo->based_on_profile_id) !== $cuerpo['basedOn']
            || ! hash_equals((string) $previo->content_hash, $cuerpo['contentHash'])) {
            throw UltronStudioException::clientRequestReused();
        }

        return self::findByUuid((string) $previo->uuid);
    }
}
