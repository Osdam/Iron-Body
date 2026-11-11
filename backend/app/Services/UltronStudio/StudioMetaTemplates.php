<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioMetaTemplateSubmission as Submission;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\Templates\MetaTemplate;
use App\Services\Meta\Templates\TemplateApiErrors;
use App\Services\Meta\Templates\TemplateApiResult;
use App\Services\Meta\Templates\WhatsappTemplateClient;
use App\Services\Meta\Templates\WhatsappTemplateClientFactory;
use App\Services\Meta\Templates\WhatsappTemplateProbe;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use stdClass;
use Throwable;

/**
 * PLANTILLAS DE WHATSAPP DE META DESDE EL CRM (plan
 * docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md §5.4–§5.12; contrato H9,
 * L9-96): enviar a Meta una versión de plantilla del Studio APROBADA a cuatro
 * ojos, comprobar lo que Meta dice de ella y dejarlo escrito, SOLO, en el
 * registro que lee el canal de W9 (`ultron_studio_template_meta_checks`).
 *
 * LA RED, POR EL CLIENTE NEUTRO ({@see WhatsappTemplateClient}, en
 * `app/Services/Meta/Templates`): este fichero no compone peticiones, no ve el
 * token y nunca habla con el WhatsApp de mensajería (ni `/messages`, ni el
 * outbox, ni el webhook). Con el doble (`fake`, solo local y pruebas) no hay
 * red; en producción el cliente es siempre el de Graph.
 *
 * LAS BANDERAS (todas apagadas por defecto, protección 1): la maestra
 * (`meta_templates.enabled`: apagada, 409 `meta_templates_disabled` salvo el
 * estado, y la sincronización no hace nada), la de enviar (`submit_enabled`, lo
 * ÚNICO que escribe en Meta, que además exige `META_ENABLED=true`) y la de
 * sincronizar (`sync_enabled`, la programación). Sin la cuenta de WhatsApp o
 * su credencial: 409 `meta_not_configured`, sin red.
 *
 * ENVIAR ({@see submit()}): solo una versión de WhatsApp APROBADA, íntegra, con
 * los ejemplos de sus variables, revisada por OTRA persona (se vuelve a mirar,
 * por persona) y sin otro envío vivo de ella ni de su nombre en Meta. Lo que
 * sale se RECONSTRUYE del contenido inmutable (`metaPayload()`): el CRM solo
 * dice «envía la versión {uuid}». El envío nace SENDING y se confirma ANTES de
 * la red; el POST va fuera de toda transacción, con un cerrojo por versión, y
 * NUNCA se repite: si no se sabe si Meta lo recibió queda UNKNOWN y la
 * sincronización lo busca por nombre e idioma (si a los 15 minutos no
 * aparece, FAILED). Un fallo de Meta nunca es un 5xx: es el estado del envío.
 *
 * LO QUE DICE META (D-PM-3, §5.6): la sincronización y «Comprobar ahora»
 * escriben una fila `meta_api` cuando cambia lo que compara el canal (estado,
 * nombre, idioma, categoría, huellas del cuerpo y de los componentes, id). Una
 * fila POSITIVA (APPROVED y todo casa) la firma quien envió la versión (la
 * sincronización) o quien pulsó «Comprobar ahora», si EN ESE MOMENTO está
 * activo, tiene la llave y su persona verificada; si no, no se escribe y el
 * envío queda `attester_unavailable`. Una NEGATIVA (cualquier otra) se escribe
 * siempre, a nombre de quien dio fe la última vez: falla cerrado. La calidad
 * no crea filas (el canal no la usa); va al envío y al libro.
 *
 * SIN DATOS PERSONALES Y SIN SECRETOS en los DTOs, el libro y los registros:
 * códigos, huellas, estados de Meta y la instantánea del personal.
 */
final class StudioMetaTemplates
{
    public const PERMISSION = StudioAccess::META_TEMPLATES_SUBMIT;

    public const SUBJECT = StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE;

    /** El actor de sistema de la sincronización en el libro. */
    public const SYSTEM_SYNC = 'ultron-studio:meta-templates-sync';

    // ── Códigos (los del plan §5.8.2 y §5.8.3, más los de «Comprobar ahora») ─

    public const CODE_DISABLED = 'meta_templates_disabled';

    public const CODE_SUBMIT_DISABLED = 'meta_submit_disabled';

    public const CODE_MESSAGING_DISABLED = 'meta_messaging_disabled';

    public const CODE_NOT_CONFIGURED = 'meta_not_configured';

    public const CODE_NOT_APPROVED = 'template_not_approved';

    public const CODE_FOUR_EYES = 'four_eyes_required';

    public const CODE_ALREADY_SUBMITTED = 'already_submitted';

    public const CODE_NAME_TAKEN = 'meta_name_taken';

    public const CODE_EXAMPLES_REQUIRED = 'meta_examples_required';

    public const CODE_NOT_WHATSAPP = 'template_not_whatsapp';

    /** «Comprobar ahora» de una versión que nadie envió ni registró en Meta. */
    public const CODE_NOT_TRACKED = 'meta_not_tracked';

    /** «Comprobar ahora» mientras la versión se está enviando o comprobando. */
    public const CODE_IN_PROGRESS = 'meta_check_in_progress';

    // ── Lo que se registra en `ultron_studio_template_meta_checks` (migración 2026_11_15_100002) ─

    public const SOURCE_API = 'meta_api';

    /** Los estados de la lectura de Meta que se registran tal cual; cualquier otro, UNKNOWN. */
    public const API_STATUSES = ['APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'PENDING', 'IN_APPEAL', 'PENDING_DELETION', 'DELETED', 'ARCHIVED', 'LIMIT_EXCEEDED'];

    public const STATUS_APPROVED = 'APPROVED';

    /** Meta ya no la tiene (borrada fuera del CRM o cuenta cambiada). */
    public const STATUS_NOT_FOUND = 'NOT_FOUND';

    /** Meta devolvió algo que este código no sabe registrar: bloquea. */
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const CHECK_CATEGORIES = ['marketing', 'utility', 'authentication'];

    /** «No casa con el Studio», por qué (los de `compare()` más dos locales). */
    public const MISMATCH_FIELD_CODES = ['meta_name_mismatch', 'language_mismatch', 'category_mismatch', 'body_mismatch', 'components_mismatch'];

    public const MISMATCH_NOT_FOUND = 'meta_template_not_found';

    public const MISMATCH_UNREADABLE = 'meta_unreadable';

    // ── Tiempos, topes y caché ──────────────────────────────────────────────

    /** Un envío UNKNOWN que no aparece en Meta en este tiempo pasa a FAILED (`not_created`). */
    public const UNKNOWN_GRACE_SECONDS = 900;

    /** Un SENDING más viejo que esto es de un proceso que murió a mitad: pasa a UNKNOWN (el POST dura ≤ 20 s). */
    public const SENDING_STALE_SECONDS = 120;

    public const PROBE_COOLDOWN_SECONDS = 300;

    public const PROBE_TTL_SECONDS = 86400;

    /** Páginas de 100 de la lista de plantillas por pasada, como mucho. */
    public const SYNC_MAX_PAGES = 10;

    public const TIMELINE_MAX = 50;

    /** El limitador propio de enviar y «Comprobar ahora» (`ultron-studio-meta`, en AppServiceProvider). */
    public const PER_HOUR_PER_PERSON = 20;

    public const PER_HOUR_PER_NETWORK = 60;

    /** Con el doble (solo local y pruebas), la cuenta de WhatsApp si no hay una configurada. */
    public const FAKE_WABA = '100000000000001';

    private const CACHE_PROBE = 'ultron-studio:meta-templates:last-probe';

    private const CACHE_SYNC = 'ultron-studio:meta-templates:last-sync';

    private const LOCK_PREFIX = 'ultron-studio:meta-template:';

    private const PROBE_LOCK = 'ultron-studio:meta-templates:probe';

    /** El cerrojo de una versión: el POST (≤ 20 s) y su registro caben de sobra. */
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 5;

    /** El espacio de los UUIDv5 de las filas de la sincronización (idempotencia: dos pasadas a la vez chocan en el UNIQUE). */
    private const CHECK_NAMESPACE = 'c5b1f9a2-3d4e-5f60-8a7b-9c0d1e2f3a4b';

    /** La huella del cuerpo cuando no hay cuerpo que leer (sha256 de ''). */
    private const EMPTY_BODY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private ?WhatsappTemplateClient $client;

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly MetaAuthService $auth,
        ?WhatsappTemplateClient $client = null,
    ) {
        $this->client = $client;
    }

    // ── Configuración ───────────────────────────────────────────────────────

    public static function enabled(): bool
    {
        return config('ultron_studio.meta_templates.enabled') === true;
    }

    public static function submitEnabled(): bool
    {
        return config('ultron_studio.meta_templates.submit_enabled') === true;
    }

    public static function syncEnabled(): bool
    {
        return config('ultron_studio.meta_templates.sync_enabled') === true;
    }

    /** El driver que de verdad se usa (en producción, siempre `graph`). */
    public static function driver(): string
    {
        return WhatsappTemplateClientFactory::effectiveDriver(config('ultron_studio.meta_templates.driver'));
    }

    public static function graphVersion(): string
    {
        return WhatsappTemplateClientFactory::effectiveGraphVersion(config('ultron_studio.meta_templates.graph_version'));
    }

    /**
     * La maestra del módulo.
     *
     * @throws UltronStudioException 409 `meta_templates_disabled`
     */
    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new UltronStudioException('Las plantillas de Meta desde el CRM están desactivadas en el servidor: las enciende quien administra el sistema.', self::CODE_DISABLED, 409);
        }
    }

    public function client(): WhatsappTemplateClient
    {
        return $this->client ??= WhatsappTemplateClientFactory::make(
            config('ultron_studio.meta_templates.driver'),
            config('ultron_studio.meta_templates.graph_version'),
            config('ultron_studio.meta_templates.graph_base'),
        );
    }

    /** La cuenta de WhatsApp (WABA) en uso, solo dígitos; con el doble, la ficticia si falta. */
    public function wabaId(): ?string
    {
        $waba = trim((string) $this->auth->wabaId());
        if (preg_match('/^[0-9]{1,32}$/D', $waba) === 1) {
            return $waba;
        }

        return self::driver() === WhatsappTemplateClientFactory::DRIVER_FAKE ? self::FAKE_WABA : null;
    }

    /** Lo que impide hablar con Meta ahora, sin red: `missing_waba`, `missing_token`, `malformed_token`, `no_simulator`; o null. */
    public function configurationProblem(): ?string
    {
        return $this->wabaId() === null ? 'missing_waba' : $this->client()->configurationProblem();
    }

    // ── GET strategies/templates/meta/status ────────────────────────────────

    /**
     * Banderas, conexión (sin el token ni la cuenta), la última sonda, la
     * última pasada y los recuentos. Responde también con el módulo apagado.
     *
     * @return array<string, mixed>
     */
    public function status(StudioAccess $access): array
    {
        $problema = $this->configurationProblem();
        $sonda = Cache::get(self::CACHE_PROBE);
        $pasada = Cache::get(self::CACHE_SYNC);
        $estados = array_fill_keys(Submission::STATES, 0);
        $enMeta = [];
        foreach (Submission::query()->selectRaw('state, meta_status, count(*) as n')->groupBy('state', 'meta_status')->get() as $f) {
            $estados[(string) $f->state] = ($estados[(string) $f->state] ?? 0) + (int) $f->n;
            if ((string) $f->state === Submission::STATE_SUBMITTED) {
                $clave = $f->meta_status !== null ? (string) $f->meta_status : self::STATUS_UNKNOWN;
                $enMeta[$clave] = ($enMeta[$clave] ?? 0) + (int) $f->n;
            }
        }
        ksort($enMeta, SORT_STRING);
        $puede = $access->can(StudioAccess::VIEW) && $access->can(self::PERMISSION);

        return [
            'enabled' => self::enabled(),
            'submitEnabled' => self::submitEnabled(),
            'syncEnabled' => self::syncEnabled(),
            'metaEnabled' => $this->auth->enabled(),
            'driver' => self::driver(),
            'graphVersion' => self::graphVersion(),
            'configured' => $problema === null,
            'configurationProblem' => $problema,
            'credentialSource' => $this->auth->credentialSource(),
            'lastProbe' => is_array($sonda) ? $sonda : null,
            'lastSyncAt' => is_array($pasada) ? ($pasada['at'] ?? null) : null,
            'lastSync' => is_array($pasada) ? $pasada : null,
            // Los estados en Meta de los envíos SUBMITTED; un objeto aunque esté vacío.
            'counts' => $enMeta === [] ? new stdClass : $enMeta,
            'submissions' => $estados,
            'can' => [
                'submit' => $puede,
                'refresh' => $puede,
                'probe' => $access->can(StudioAccess::VIEW) && $access->can(StudioAccess::INTEGRATIONS_MANAGE),
            ],
        ];
    }

    // ── POST strategies/templates/meta/probe ────────────────────────────────

    /**
     * La sonda de solo lectura del permiso de plantillas (§5.9): dos GET. Dentro
     * del enfriamiento de 300 s, la última sin red (`reused`). Se guarda 24 h
     * en la caché y en el libro (`meta_templates.probed`).
     *
     * @return array{probe: array<string, mixed>, reused: bool}
     */
    public function probe(StudioAccess $access, Request $request, StudioPersonBindings $persons, DateTimeInterface $now): array
    {
        $access->require(StudioAccess::INTEGRATIONS_MANAGE);
        $persons->requirePerson($access, $request, 'meta_templates.probe', StudioAuditEvent::SUBJECT_INTEGRATION, null);
        $waba = $this->assertConfigured();

        if ($reciente = self::recentProbe($now)) {
            return ['probe' => $reciente, 'reused' => true];
        }
        $cerrojo = Cache::lock(self::PROBE_LOCK, 60);
        try {
            $cerrojo->block(40);
        } catch (LockTimeoutException) {
            // Una sonda anterior murió con el cerrojo puesto (caduca solo): se
            // sigue sin él. Lo peor son dos GET más, y lo acota el limitador.
            $cerrojo = null;
        }

        try {
            if ($reciente = self::recentProbe($now)) {
                return ['probe' => $reciente, 'reused' => true];
            }
            $r = WhatsappTemplateProbe::run($this->client(), $waba);
            $yo = $access->snapshot();
            $sonda = [
                'uuid' => strtolower((string) Str::uuid()),
                'at' => RelativeTime::format($now),
                'outcome' => $r['outcome'],
                'scopes' => $r['scopes'],
                'templatesReadable' => $r['templatesReadable'],
                'templatesError' => $r['templatesError'],
                'driver' => self::driver(),
                'graphVersion' => self::graphVersion(),
                'by' => ['name' => $yo['name'], 'role' => $yo['role']],
                // Lo que la sonda NO prueba (se dice en pantalla).
                'writeVerified' => false,
            ];
            $this->audit->record($request, $access->actor, 'meta_templates.probed', StudioAuditEvent::SUBJECT_INTEGRATION, $sonda['uuid'],
                'Comprobación de solo lectura del permiso de plantillas de Meta', ['diff' => [
                    'outcome' => $r['outcome'], 'templatesReadable' => $r['templatesReadable'], 'templatesError' => $r['templatesError'],
                    'managementGranted' => $r['scopes'][WhatsappTemplateProbe::PERMISSION],
                ]]);
            Cache::put(self::CACHE_PROBE, $sonda, self::PROBE_TTL_SECONDS);

            return ['probe' => $sonda, 'reused' => false];
        } finally {
            $cerrojo?->release();
        }
    }

    // ── POST strategies/templates/{uuid}/meta/submit ────────────────────────

    /**
     * Envía a Meta una versión aprobada (§5.4). Idempotente por
     * `clientRequestId`: la misma petición devuelve el mismo envío
     * (`replayed`); con otra versión o de otra persona, 409.
     *
     * @return array{template: StudioMessageTemplate, replayed: bool}
     *
     * @throws UltronStudioException
     */
    public function submit(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $uuid, string $clientRequestId, DateTimeInterface $now): array
    {
        $access->require(self::PERMISSION);
        if (! self::submitEnabled()) {
            throw new UltronStudioException('Enviar plantillas a Meta está desactivado en el servidor: lo enciende quien administra el sistema.', self::CODE_SUBMIT_DISABLED, 409);
        }
        if (! $this->auth->enabled()) {
            throw new UltronStudioException('El WhatsApp de Meta está apagado en el servidor (META_ENABLED): no se envían plantillas.', self::CODE_MESSAGING_DISABLED, 409);
        }
        $waba = $this->assertConfigured();
        $llave = strtolower($clientRequestId);
        if ($previo = Submission::query()->where('client_request_id', $llave)->first()) {
            return $this->replaySubmit($access, $previo, $uuid);
        }
        $persona = $persons->requirePerson($access, $request, 'meta_template.submit', self::SUBJECT, strtolower($uuid));
        $t = StudioMessageTemplates::findByUuid($uuid);
        $contenido = self::content($t);
        if ($bloqueo = $this->submitBlocker($t, $contenido)) {
            throw $bloqueo;
        }
        $payload = StudioMessageTemplates::metaPayload($contenido);

        $cerrojo = Cache::lock(self::LOCK_PREFIX.$t->id, self::LOCK_SECONDS);
        if (! $cerrojo->get()) {
            // Otro envío, una comprobación o una pasada de esta versión en curso.
            if ($previo = Submission::query()->where('client_request_id', $llave)->first()) {
                return $this->replaySubmit($access, $previo, $uuid);
            }

            throw new UltronStudioException('Esta versión se está enviando o comprobando ahora mismo: espera un momento y recarga.', self::CODE_ALREADY_SUBMITTED, 409);
        }

        try {
            // Con el cerrojo: lo que pudo cambiar mientras tanto.
            if ($bloqueo = $this->submitBlocker($t->refresh(), $contenido)) {
                throw $bloqueo;
            }
            try {
                $envio = DB::transaction(function () use ($access, $request, $t, $llave, $payload, $persona): Submission {
                    $yo = $access->snapshot();
                    $envio = Submission::query()->forceCreate([
                        'uuid' => strtolower((string) Str::uuid()),
                        'client_request_id' => $llave,
                        'template_id' => (int) $t->id,
                        'template_content_hash' => (string) $t->content_hash,
                        'meta_name' => $payload['name'],
                        'language' => $payload['language'],
                        'category' => (string) $t->category,
                        'request_hash' => CanonicalJson::hash($payload),
                        'state' => Submission::STATE_SENDING,
                        'attester_unavailable' => false,
                        'submitted_by_admin_id' => $yo['id'],
                        'submitted_by_person_id' => $persona['identityId'],
                        'submitted_by_name' => $yo['name'],
                        'submitted_by_role' => $yo['role'],
                        'submitted_at' => now(),
                    ]);
                    $this->audit->record($request, $access->actor, 'meta_template.submit_requested', self::SUBJECT, (string) $t->uuid,
                        "Pidió enviar a Meta la versión {$t->number} de la plantilla «{$t->key}»",
                        ['toStatus' => Submission::STATE_SENDING, 'contentHash' => (string) $t->content_hash, 'diff' => ['metaName' => $payload['name'], 'language' => $payload['language'], 'category' => $payload['category']]]);

                    return $envio;
                });
            } catch (UniqueConstraintViolationException) {
                // Otro proceso ganó la carrera (sin la caché compartida): el índice único manda.
                if ($previo = Submission::query()->where('client_request_id', $llave)->first()) {
                    return $this->replaySubmit($access, $previo, $uuid);
                }

                throw $this->submitBlocker($t, $contenido) ?? new UltronStudioException('Esta versión ya tiene un envío a Meta en curso.', self::CODE_ALREADY_SUBMITTED, 409);
            }

            $resultado = $this->createAtMeta($envio, $waba, $payload, $t, $request, $access->actor);
            $this->settleCreate($envio, $resultado, $t, $request, $access->actor);

            // Si Meta la aprobó en el acto (raro, pero posible), se lee ya para sus huellas (§5.4).
            if ($envio->state === Submission::STATE_SUBMITTED && $resultado->createdStatus === self::STATUS_APPROVED) {
                $leida = $this->readOne($envio->meta_template_id, null, null, $waba);
                if ($leida['ok']) {
                    $this->observe($t, $contenido, $envio, $leida['template'], $access->actor, $request, RelativeTime::utc($now), false);
                }
            }
        } finally {
            $cerrojo->release();
        }

        return ['template' => $t->refresh(), 'replayed' => false];
    }

    // ── POST strategies/templates/{uuid}/meta/refresh («Comprobar ahora») ───

    /**
     * Lee de Meta ESTA versión (un GET) y registra lo que haya cambiado, con
     * quien la pide como quien da fe (§5.6). Un fallo de Meta no es un error
     * de la API: va en `check.outcome`.
     *
     * @return array{template: StudioMessageTemplate, check: array{outcome: string, error: string|null, changed: bool, rowWritten: bool, channelAccepts: bool}}
     *
     * @throws UltronStudioException
     */
    public function refresh(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $uuid, DateTimeInterface $now): array
    {
        $access->require(self::PERMISSION);
        $waba = $this->assertConfigured();
        $persons->requirePerson($access, $request, 'meta_template.refresh', self::SUBJECT, strtolower($uuid));
        $t = StudioMessageTemplates::findByUuid($uuid);
        if ((string) $t->channel !== StudioMessageTemplates::CHANNEL_WHATSAPP) {
            throw new UltronStudioException('Solo las plantillas de WhatsApp tienen correspondencia en Meta.', self::CODE_NOT_WHATSAPP, 422);
        }
        $ahora = RelativeTime::utc($now);

        $cerrojo = Cache::lock(self::LOCK_PREFIX.$t->id, self::LOCK_SECONDS);
        try {
            $cerrojo->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new UltronStudioException('Esta versión se está enviando o comprobando ahora mismo: espera un momento y vuelve a intentarlo.', self::CODE_IN_PROGRESS, 409);
        }

        try {
            $envio = self::liveSubmission((int) $t->id);
            if ($envio !== null && $envio->state === Submission::STATE_SENDING) {
                if (! $this->staleSending($envio, $ahora, null)) {
                    throw new UltronStudioException('Esta versión se está enviando ahora mismo: espera un momento y recarga.', self::CODE_IN_PROGRESS, 409);
                }
            }
            $ultima = self::latestCheck((int) $t->id);
            $objetivo = self::target($envio, $ultima);
            if ($objetivo === null) {
                throw new UltronStudioException('Esta versión no se ha enviado a Meta ni tiene una comprobación registrada: no hay nada que comprobar.', self::CODE_NOT_TRACKED, 409);
            }
            $this->audit->record($request, $access->actor, 'meta_template.check_requested', self::SUBJECT, (string) $t->uuid,
                "Pidió comprobar en Meta la versión {$t->number} de la plantilla «{$t->key}»");

            $leida = $this->readOne($objetivo['id'], $objetivo['name'], $objetivo['language'], $waba);
            if (! $leida['ok']) {
                return ['template' => $t->refresh(), 'check' => ['outcome' => 'failed', 'error' => $leida['error'], 'changed' => false, 'rowWritten' => false,
                    'channelAccepts' => app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id)]];
            }
            $efecto = $this->observe($t, self::content($t), $envio, $leida['template'], $access->actor, $request, $ahora, false);

            return ['template' => $t->refresh(), 'check' => [
                'outcome' => $leida['template'] === null ? 'not_found' : 'ok', 'error' => null,
                'changed' => $efecto['changed'], 'rowWritten' => $efecto['rowWritten'], 'channelAccepts' => $efecto['channelAccepts'],
            ]];
        } finally {
            $cerrojo->release();
        }
    }

    // ── La sincronización (orden ultron-studio:meta-templates-sync) ─────────

    /**
     * UNA pasada (§5.5): las versiones vigiladas (las que tienen un envío vivo
     * y las que solo tienen comprobaciones sin envío, como las registradas a
     * mano), una lectura de la lista de Meta (y una búsqueda suelta para las
     * que no salen en ella) y lo que haya cambiado. Nunca lanza por Meta: si
     * Meta falla, no se inventa nada y lo último registrado sigue mandando.
     *
     * @return array<string, mixed>
     */
    public function sync(?string $templateUuid, bool $dryRun, DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        $base = ['at' => RelativeTime::format($ahora), 'dryRun' => $dryRun, 'watched' => 0, 'requests' => 0, 'error' => null, 'templates' => []];
        if (! self::enabled() || ! self::syncEnabled()) {
            return ['outcome' => 'disabled'] + $base;
        }
        $problema = $this->configurationProblem();
        if ($problema !== null) {
            return ['outcome' => 'not_configured', 'error' => $problema] + $base;
        }
        $waba = (string) $this->wabaId();

        $vigiladas = $this->watched();
        if ($templateUuid !== null) {
            $vigiladas = array_filter($vigiladas, static fn (array $v): bool => (string) $v['template']->uuid === $templateUuid);
            if ($vigiladas === []) {
                return ['outcome' => 'not_watched'] + $base;
            }
        }
        $base['watched'] = count($vigiladas);
        if ($vigiladas === []) {
            return ['outcome' => 'nothing_to_watch'] + $base;
        }

        // Los SENDING de un proceso que murió a mitad: UNKNOWN (no depende de Meta).
        foreach ($vigiladas as $i => $v) {
            if ($v['submission']?->state === Submission::STATE_SENDING) {
                if ($dryRun || ! $this->staleSending($v['submission'], $ahora, null)) {
                    unset($vigiladas[$i]);
                }
            }
        }

        // La lectura: la lista (una página si hay una sola versión pedida, con su búsqueda suelta).
        $peticiones = 0;
        $porId = [];
        $porNombre = [];
        if ($templateUuid === null) {
            $lista = $this->readAll($waba);
            $peticiones += $lista['requests'];
            if (! $lista['ok']) {
                Log::warning('ultron_studio.meta_templates.sync_failed', ['error_class' => $lista['error'], 'http_status' => $lista['httpStatus'], 'error_code' => $lista['metaCode']]);
                $resumen = ['outcome' => 'meta_failed', 'requests' => $peticiones, 'error' => $lista['error']] + $base;
                if (! $dryRun) {
                    Cache::put(self::CACHE_SYNC, self::syncSummary($resumen), self::PROBE_TTL_SECONDS);
                }

                return $resumen;
            }
            foreach ($lista['templates'] as $m) {
                $porId[$m->id] = $m;
                if ($m->name !== null && $m->language !== null) {
                    $porNombre[$m->name.'|'.$m->language][] = $m;
                }
            }
        }

        $filas = [];
        $sinResolver = 0;
        foreach ($vigiladas as $v) {
            $objetivo = self::target($v['submission'], $v['latest']);
            if ($objetivo === null) {
                continue;
            }
            $hallada = false;
            $observada = null;
            if ($objetivo['id'] !== null && isset($porId[$objetivo['id']])) {
                [$hallada, $observada] = [true, $porId[$objetivo['id']]];
            } elseif ($objetivo['id'] === null && count($porNombre[$objetivo['name'].'|'.$objetivo['language']] ?? []) === 1) {
                [$hallada, $observada] = [true, $porNombre[$objetivo['name'].'|'.$objetivo['language']][0]];
            }
            if (! $hallada) {
                // No sale en la lista (o se pidió una sola): la búsqueda suelta decide si existe.
                $leida = $this->readOne($objetivo['id'], $objetivo['name'], $objetivo['language'], $waba);
                $peticiones += $leida['requests'];
                if (! $leida['ok']) {
                    $sinResolver++;
                    $filas[] = self::row($v['template'], ['note' => 'unresolved', 'error' => $leida['error']]);

                    continue;
                }
                $observada = $leida['template'];
            }
            $filas[] = self::row($v['template'], $this->observeLocked($v['template'], $observada, $ahora, $dryRun));
        }

        $resumen = ['outcome' => $sinResolver === 0 ? 'ok' : 'partial', 'requests' => $peticiones, 'templates' => $filas] + $base;
        if (! $dryRun) {
            Cache::put(self::CACHE_SYNC, self::syncSummary($resumen), self::PROBE_TTL_SECONDS);
        }

        return $resumen;
    }

    // ── Lo que ve el CRM (DTOs aditivos de las plantillas, §5.8.2) ──────────

    /**
     * El bloque `meta` del detalle de una plantilla de WhatsApp: el envío, la
     * última comprobación, si está lista para campañas (lo dice el PUENTE del
     * canal, nunca el CRM) y por qué no, el historial y lo que puede hacer
     * quien mira (con el motivo cuando no).
     *
     * @return array<string, mixed>
     */
    public function detailBlock(StudioMessageTemplate $t, StudioAccess $access): array
    {
        $contenido = self::content($t);
        $envio = self::currentSubmission((int) $t->id);
        $ultima = self::latestCheck((int) $t->id);
        $listo = app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id);
        $motivos = $listo ? [] : ($ultima !== null ? StudioTemplateMetaChecks::compare((array) $ultima, $t, $contenido)['reasons'] : []);
        if (! $listo && $motivos === []) {
            $motivos = ['template_not_approved_by_meta'];
        }
        $historial = StudioAuditEvent::query()->where('subject_type', self::SUBJECT)->where('subject_uuid', (string) $t->uuid)
            ->orderByDesc('id')->limit(self::TIMELINE_MAX)->get(['event', 'summary', 'actor_name', 'created_at']);

        return [
            'status' => self::metaStatusOf($envio, $ultima),
            'state' => $envio?->state,
            'campaignReady' => $listo,
            'campaignReasons' => $motivos,
            'submission' => $envio !== null ? self::submissionDto($envio) : null,
            'attempts' => Submission::query()->where('template_id', $t->id)->count(),
            'latestCheck' => $ultima === null ? null : [
                'status' => (string) $ultima->status,
                'source' => (string) ($ultima->source ?? 'manual'),
                'checkedAt' => RelativeTime::format(new DateTimeImmutable((string) $ultima->checked_at, new \DateTimeZone('UTC'))),
                'recordedBy' => (string) $ultima->recorded_by_name,
            ],
            'timeline' => $historial->map(static fn (StudioAuditEvent $e): array => [
                'at' => $e->created_at?->toIso8601String(),
                'event' => (string) $e->event,
                'summary' => (string) $e->summary,
                'actor' => (string) $e->actor_name,
            ])->all(),
            'actions' => [
                'submit' => self::allowed($this->submitBlockerCode($t, $contenido, $access, $envio)),
                'refresh' => self::allowed($this->refreshBlockerCode($t, $access, $envio, $ultima)),
            ],
        ];
    }

    /**
     * El bloque `meta` de la LISTA (`{status, state, campaignReady}` por
     * plantilla de WhatsApp; null en las demás), en tres consultas para toda
     * la página (sin N+1). «Lista para campañas» con la MISMA regla que el
     * puente (`compare()`, que una prueba ata al puente).
     *
     * @param  iterable<StudioMessageTemplate>  $templates
     * @return array<int, array{status: string|null, state: string|null, campaignReady: bool}|null>
     */
    public static function summaries(iterable $templates): array
    {
        $ids = [];
        $todas = [];
        foreach ($templates as $t) {
            $todas[(int) $t->id] = $t;
            if ((string) $t->channel === StudioMessageTemplates::CHANNEL_WHATSAPP) {
                $ids[] = (int) $t->id;
            }
        }
        $out = array_fill_keys(array_keys($todas), null);
        if ($ids === []) {
            return $out;
        }
        $ultimas = DB::table(StudioTemplateMetaChecks::TABLE)
            ->whereIn('id', DB::table(StudioTemplateMetaChecks::TABLE)->selectRaw('max(id)')->whereIn('template_id', $ids)->groupBy('template_id'))
            ->get()->keyBy('template_id');
        $envios = [];
        foreach (Submission::query()->whereIn('template_id', $ids)->orderByDesc('id')->get() as $s) {
            $actual = $envios[$s->template_id] ?? null;
            // El vivo manda; si no hay, el último.
            if ($actual === null || (! $actual->isLive() && $s->isLive())) {
                $envios[$s->template_id] = $s;
            }
        }
        foreach ($ids as $id) {
            $ultima = $ultimas[$id] ?? null;
            $envio = $envios[$id] ?? null;
            $out[$id] = [
                'status' => self::metaStatusOf($envio, $ultima),
                'state' => $envio?->state,
                'campaignReady' => $ultima !== null && StudioTemplateMetaChecks::compare((array) $ultima, $todas[$id], self::content($todas[$id]))['channelAccepts'],
            ];
        }

        return $out;
    }

    // ── Piezas: enviar ──────────────────────────────────────────────────────

    /** Lo que impide enviar esta versión, como excepción (el orden de {@see submitBlockerCode()}), o null. */
    private function submitBlocker(StudioMessageTemplate $t, array $contenido): ?UltronStudioException
    {
        if ((string) $t->channel !== StudioMessageTemplates::CHANNEL_WHATSAPP) {
            return new UltronStudioException('Solo las plantillas de WhatsApp se envían a Meta.', self::CODE_NOT_WHATSAPP, 422);
        }
        if ((string) $t->status !== StudioMessageTemplate::STATUS_APPROVED) {
            return new UltronStudioException('Solo se envía a Meta una versión aprobada en el Studio por otra persona.', self::CODE_NOT_APPROVED, 409, ['currentStatus' => (string) $t->status]);
        }
        if (! hash_equals(hash('sha256', (string) $t->content_json), (string) $t->content_hash)) {
            return UltronStudioException::integrityMismatch('La huella de la plantilla no coincide con su contenido: no se puede enviar a Meta.');
        }
        if (self::sameAuthorAndReviewer($t)) {
            return new UltronStudioException('Quien escribió la plantilla y quien la aprobó resultan ser la misma persona: no se envía.', self::CODE_FOUR_EYES, 409);
        }
        if (StudioMessageTemplates::missingMetaExamples($contenido)) {
            return new UltronStudioException('La plantilla tiene variables sin sus ejemplos (Meta los exige para revisarla): crea una versión nueva con ejemplos.', self::CODE_EXAMPLES_REQUIRED, 422);
        }
        if (self::liveSubmission((int) $t->id) !== null) {
            return new UltronStudioException('Esta versión ya se envió a Meta: compruébala en lugar de enviarla otra vez.', self::CODE_ALREADY_SUBMITTED, 409);
        }
        $otra = Submission::query()->where('meta_name', (string) ($contenido['metaName'] ?? ''))->where('language', (string) ($contenido['language'] ?? ''))
            ->where('state', '<>', Submission::STATE_FAILED)->where('template_id', '<>', $t->id)->first();
        if ($otra !== null) {
            $suya = StudioMessageTemplate::query()->find($otra->template_id, ['uuid', 'key', 'number']);

            return new UltronStudioException('Otra versión ya reclama ese nombre e idioma en Meta: usa otro nombre en una versión nueva.', self::CODE_NAME_TAKEN, 409,
                ['template' => $suya === null ? null : ['uuid' => (string) $suya->uuid, 'key' => (string) $suya->key, 'number' => (int) $suya->number]]);
        }

        return null;
    }

    /** El código que el CRM enseña en `actions.submit` (el mismo orden que el servidor al enviar), o null si se puede. */
    private function submitBlockerCode(StudioMessageTemplate $t, array $contenido, StudioAccess $access, ?Submission $envio): ?string
    {
        return match (true) {
            ! $access->can(StudioAccess::VIEW) || ! $access->can(self::PERMISSION) => 'forbidden',
            ! self::enabled() => self::CODE_DISABLED,
            ! self::submitEnabled() => self::CODE_SUBMIT_DISABLED,
            ! $this->auth->enabled() => self::CODE_MESSAGING_DISABLED,
            $this->configurationProblem() !== null => self::CODE_NOT_CONFIGURED,
            StudioPersonBindings::personOf($access->id()) === null => 'person_binding_required',
            $envio !== null && $envio->isLive() => self::CODE_ALREADY_SUBMITTED,
            default => $this->submitBlocker($t, $contenido)?->errorCode,
        };
    }

    /** El código que el CRM enseña en `actions.refresh`, o null si se puede. */
    private function refreshBlockerCode(StudioMessageTemplate $t, StudioAccess $access, ?Submission $envio, ?object $ultima): ?string
    {
        $vivo = $envio !== null && $envio->isLive() ? $envio : null;

        return match (true) {
            ! $access->can(StudioAccess::VIEW) || ! $access->can(self::PERMISSION) => 'forbidden',
            ! self::enabled() => self::CODE_DISABLED,
            $this->configurationProblem() !== null => self::CODE_NOT_CONFIGURED,
            StudioPersonBindings::personOf($access->id()) === null => 'person_binding_required',
            self::target($vivo, $ultima) === null => self::CODE_NOT_TRACKED,
            $vivo?->state === Submission::STATE_SENDING => self::CODE_IN_PROGRESS,
            default => null,
        };
    }

    /**
     * La misma petición repetida: el mismo envío. Con otra versión o de otra
     * persona, 409 `client_request_reused`.
     *
     * @return array{template: StudioMessageTemplate, replayed: bool}
     */
    private function replaySubmit(StudioAccess $access, Submission $previo, string $uuid): array
    {
        $t = StudioMessageTemplate::query()->find($previo->template_id);
        if ($t === null || (string) $t->uuid !== strtolower($uuid) || (int) $previo->submitted_by_admin_id !== $access->id()) {
            throw UltronStudioException::clientRequestReused();
        }

        return ['template' => $t, 'replayed' => true];
    }

    /** El POST, sin dejar el envío en SENDING pase lo que pase. */
    private function createAtMeta(Submission $envio, string $waba, array $payload, StudioMessageTemplate $t, ?Request $request, ?Admin $actor): TemplateApiResult
    {
        try {
            return $this->client()->create($waba, $payload);
        } catch (InvalidArgumentException $e) {
            // El cliente rechazó la plantilla ANTES de la red (no salió nada): es
            // un contenido que el validador del Studio dejó pasar. Se cierra el
            // envío y se dice, nunca se reintenta. El cierre lleva su evento,
            // como los de settleCreate() (M-2): estado y libro juntos o ninguno
            // (si el proceso muere a mitad, el SENDING lo recoge staleSending()).
            Log::error('ultron_studio.meta_templates.payload_refused', ['submission_uuid' => (string) $envio->uuid, 'error_class' => $e::class]);
            DB::transaction(function () use ($envio, $t, $request, $actor): void {
                $envio->forceFill(['state' => Submission::STATE_FAILED])->save();
                $this->audit->record($request, $actor, 'meta_template.submit_failed', self::SUBJECT, (string) $t->uuid,
                    "No se envió a Meta la versión {$t->number} de la plantilla «{$t->key}»: Meta no la admitiría tal como está guardada",
                    ['fromStatus' => Submission::STATE_SENDING, 'toStatus' => Submission::STATE_FAILED, 'diff' => ['detail' => 'payload_refused']], self::SYSTEM_SYNC);
            });

            throw new UltronStudioException('Meta no admitiría esta plantilla tal como está guardada: crea una versión nueva.', 'meta_payload_invalid', 422);
        } catch (Throwable $e) {
            // El contrato del cliente es no lanzar por la red: esto es otra cosa y
            // no se sabe si la petición salió. Desconocido (la sincronización la
            // busca por nombre e idioma) y queda en el registro con su clase.
            Log::error('ultron_studio.meta_templates.create_crashed', ['submission_uuid' => (string) $envio->uuid, 'error_class' => $e::class]);

            return TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::UNKNOWN, TemplateApiErrors::TRANSPORT, 'maybe_sent');
        }
    }

    /** El envío según lo que contestó Meta, con su evento. */
    private function settleCreate(Submission $envio, TemplateApiResult $r, StudioMessageTemplate $t, ?Request $request, ?Admin $actor): void
    {
        $ahora = now();
        $nombre = "la versión {$t->number} de la plantilla «{$t->key}»";
        if ($r->ok()) {
            $envio->forceFill([
                'state' => Submission::STATE_SUBMITTED, 'meta_template_id' => $r->createdId,
                'meta_status' => $r->createdStatus, 'meta_category' => $r->createdCategory,
                'last_checked_at' => $ahora, 'last_changed_at' => $ahora,
            ])->save();
            $this->audit->record($request, $actor, 'meta_template.submitted', self::SUBJECT, (string) $t->uuid, "Meta recibió {$nombre}",
                ['fromStatus' => Submission::STATE_SENDING, 'toStatus' => Submission::STATE_SUBMITTED, 'diff' => ['metaStatus' => $r->createdStatus, 'metaCategory' => $r->createdCategory]],
                self::SYSTEM_SYNC);

            return;
        }
        $error = self::errorColumns($r);
        $estado = $r->unknown() ? Submission::STATE_UNKNOWN : Submission::STATE_FAILED;
        $envio->forceFill(['state' => $estado] + $error)->save();
        $this->audit->record($request, $actor, $r->unknown() ? 'meta_template.submit_unknown' : 'meta_template.submit_failed', self::SUBJECT, (string) $t->uuid,
            $r->unknown() ? "No se sabe si Meta recibió {$nombre}: se comprobará sola" : "Meta no aceptó {$nombre}",
            ['fromStatus' => Submission::STATE_SENDING, 'toStatus' => $estado, 'diff' => [
                'errorClass' => $r->error, 'detail' => $r->detail, 'errorCode' => $error['error_code'], 'errorSubcode' => $error['error_subcode'], 'httpStatus' => $r->httpStatus,
            ]], self::SYSTEM_SYNC);
    }

    /**
     * Las columnas de error de un fallo de Meta: códigos positivos que caben en
     * un `integer` (el subcódigo, solo con su código) y el texto para el
     * usuario ya saneado por el cliente (una línea, ≤ 300).
     *
     * @return array{error_code: int|null, error_subcode: int|null, error_user_message: string|null}
     */
    private static function errorColumns(TemplateApiResult $r): array
    {
        $entero = static fn (?int $v): ?int => $v !== null && $v > 0 && $v <= 2147483647 ? $v : null;
        $codigo = $entero($r->metaCode);
        $texto = $r->userMessage ?? $r->userTitle;
        $texto = is_string($texto) ? trim((string) preg_replace('/[[:cntrl:]]+/u', ' ', $texto)) : '';

        return [
            'error_code' => $codigo,
            'error_subcode' => $codigo !== null ? $entero($r->metaSubcode) : null,
            'error_user_message' => $texto === '' ? null : mb_substr($texto, 0, 300),
        ];
    }

    // ── Piezas: leer de Meta ────────────────────────────────────────────────

    /**
     * Una plantilla: por id o, sin él, por nombre e idioma. `template` null =
     * Meta no la tiene. Si la versión de Graph no conoce `rejected_reason`
     * (V-2: error 100), se pide otra vez sin él (es una lectura).
     *
     * @return array{ok: bool, template: MetaTemplate|null, error: string|null, requests: int}
     */
    private function readOne(?string $id, ?string $nombre, ?string $idioma, string $waba): array
    {
        $leer = function (array $campos) use ($id, $nombre, $idioma, $waba): TemplateApiResult {
            return $id !== null
                ? $this->client()->find($id, $campos)
                : $this->client()->findByName($waba, (string) $nombre, $idioma, $campos);
        };
        $r = $this->withoutUnknownFields($leer);
        if ($r['result']->ok()) {
            $halladas = $r['result']->templates;
            if ($id === null && count($halladas) > 1) {
                // Meta no admite dos con el mismo nombre e idioma: si llegan, no se adivina cuál.
                return ['ok' => false, 'template' => null, 'error' => TemplateApiErrors::INVALID_RESPONSE, 'requests' => $r['requests']];
            }

            return ['ok' => true, 'template' => $halladas[0] ?? null, 'error' => null, 'requests' => $r['requests']];
        }
        if ($id !== null && $r['result']->error === TemplateApiErrors::NOT_FOUND) {
            return ['ok' => true, 'template' => null, 'error' => null, 'requests' => $r['requests']];
        }

        return ['ok' => false, 'template' => null, 'error' => $r['result']->error, 'requests' => $r['requests']];
    }

    /**
     * Todas las plantillas de la cuenta, página a página (como mucho
     * {@see SYNC_MAX_PAGES}). Una página que falla hace fallar la lectura.
     *
     * @return array{ok: bool, templates: list<MetaTemplate>, error: string|null, httpStatus: int|null, metaCode: int|null, requests: int}
     */
    private function readAll(string $waba): array
    {
        $plantillas = [];
        $cursor = null;
        $peticiones = 0;
        for ($pagina = 1; $pagina <= self::SYNC_MAX_PAGES; $pagina++) {
            $r = $this->withoutUnknownFields(fn (array $campos): TemplateApiResult => $this->client()->list($waba, $campos, $cursor, WhatsappTemplateClient::MAX_PAGE_SIZE));
            $peticiones += $r['requests'];
            $res = $r['result'];
            if (! $res->ok()) {
                return ['ok' => false, 'templates' => [], 'error' => $res->error, 'httpStatus' => $res->httpStatus, 'metaCode' => $res->metaCode, 'requests' => $peticiones];
            }
            array_push($plantillas, ...$res->templates);
            if ($res->nextCursor === null) {
                break;
            }
            $cursor = $res->nextCursor;
        }

        return ['ok' => true, 'templates' => $plantillas, 'error' => null, 'httpStatus' => null, 'metaCode' => null, 'requests' => $peticiones];
    }

    /**
     * V-2: `rejected_reason` solo consta en documentación de terceros. Si la
     * versión fijada contesta 100 («nonexisting field»), una segunda lectura
     * sin él (una lectura no cambia nada en Meta) y queda en el registro.
     *
     * @param  callable(list<string>): TemplateApiResult  $leer
     * @return array{result: TemplateApiResult, requests: int}
     */
    private function withoutUnknownFields(callable $leer): array
    {
        $r = $leer(WhatsappTemplateClient::SYNC_FIELDS);
        if ($r->failed() && $r->error === TemplateApiErrors::VALIDATION && $r->metaCode === 100) {
            Log::warning('ultron_studio.meta_templates.field_dropped', ['field' => 'rejected_reason', 'graph_version' => self::graphVersion()]);
            $otra = $leer(array_values(array_diff(WhatsappTemplateClient::SYNC_FIELDS, ['rejected_reason'])));

            return ['result' => $otra, 'requests' => $r->requests + $otra->requests];
        }

        return ['result' => $r, 'requests' => $r->requests];
    }

    // ── Piezas: registrar lo observado ──────────────────────────────────────

    /** {@see observe()} con el cerrojo de la versión (si está ocupado, esta pasada la salta). */
    private function observeLocked(StudioMessageTemplate $t, ?MetaTemplate $observada, DateTimeImmutable $ahora, bool $dryRun): array
    {
        $cerrojo = Cache::lock(self::LOCK_PREFIX.$t->id, self::LOCK_SECONDS);
        if (! $cerrojo->get()) {
            return ['note' => 'busy', 'changed' => false, 'rowWritten' => false];
        }
        try {
            // Releído con el cerrojo: un envío o una comprobación pudo cambiarlo.
            $envio = self::liveSubmission((int) $t->id);

            return $this->observe($t->refresh(), self::content($t), $envio, $observada, null, null, $ahora, $dryRun);
        } finally {
            $cerrojo->release();
        }
    }

    /**
     * Lo que Meta enseña de UNA versión, registrado (§5.5 punto 6 y §5.6).
     * `$attester` es quien da fe AHORA de una fila positiva (quien pulsó
     * «Comprobar ahora» o quien envió y Meta aprobó en el acto); null en la
     * sincronización, donde da fe quien envió, si puede.
     *
     * @param  array<string, mixed>  $contenido
     * @return array{note: string|null, before: string|null, after: string|null, changed: bool, rowWritten: bool, attesterUnavailable: bool, channelAccepts: bool}
     */
    private function observe(StudioMessageTemplate $t, array $contenido, ?Submission $envio, ?MetaTemplate $observada, ?Admin $attester, ?Request $request, DateTimeImmutable $ahora, bool $dryRun): array
    {
        $nada = ['note' => null, 'before' => null, 'after' => null, 'changed' => false, 'rowWritten' => false, 'attesterUnavailable' => false, 'channelAccepts' => false];
        $nombre = "la versión {$t->number} de la plantilla «{$t->key}»";

        // 1. El envío que no se sabía si llegó: aparece (SUBMITTED) o, pasado el plazo, FAILED.
        if ($envio !== null && $envio->state === Submission::STATE_UNKNOWN) {
            if ($observada === null) {
                $vencido = $envio->submitted_at !== null && $envio->submitted_at->diffInSeconds($ahora, false) >= self::UNKNOWN_GRACE_SECONDS;
                if ($vencido && ! $dryRun) {
                    $envio->forceFill(['state' => Submission::STATE_FAILED])->save();
                    $this->audit->record($request, $attester, 'meta_template.submit_failed', self::SUBJECT, (string) $t->uuid, "Meta no creó {$nombre} (no apareció en 15 minutos)",
                        ['fromStatus' => Submission::STATE_UNKNOWN, 'toStatus' => Submission::STATE_FAILED, 'diff' => ['detail' => 'not_created']], self::SYSTEM_SYNC);
                }

                return ['note' => $vencido ? 'not_created' : 'waiting'] + $nada;
            }
            if (! $dryRun) {
                $envio->forceFill(['state' => Submission::STATE_SUBMITTED, 'meta_template_id' => $observada->id, 'error_code' => null, 'error_subcode' => null, 'error_user_message' => null])->save();
                $this->audit->record($request, $attester, 'meta_template.submitted', self::SUBJECT, (string) $t->uuid, "Meta sí recibió {$nombre} (encontrada por nombre e idioma)",
                    ['fromStatus' => Submission::STATE_UNKNOWN, 'toStatus' => Submission::STATE_SUBMITTED, 'diff' => ['detail' => 'reconciled']], self::SYSTEM_SYNC);
            }
        }
        if ($envio !== null && ! in_array($envio->state, [Submission::STATE_SUBMITTED, Submission::STATE_UNKNOWN], true)) {
            return ['note' => 'not_observable'] + $nada;
        }

        // 2. La observación, con la forma que admite el registro.
        $ultima = self::latestCheck((int) $t->id);
        $obs = self::observation($t, $contenido, $envio, $ultima, $observada);
        $comparacion = StudioTemplateMetaChecks::compare($obs['row'], $t, $contenido);
        $desajustes = array_values(array_intersect($comparacion['reasons'], self::MISMATCH_FIELD_CODES));
        if ($obs['extraMismatch'] !== null) {
            $desajustes = array_values(array_unique([$obs['extraMismatch'], ...$desajustes]));
        }
        $positiva = $obs['row']['status'] === self::STATUS_APPROVED && $desajustes === [];

        // 3. El envío: lo último observado y sus eventos.
        $cambio = false;
        if ($envio !== null) {
            $antes = ['meta_status' => $envio->meta_status, 'meta_quality' => $envio->meta_quality, 'meta_category' => $envio->meta_category, 'matches_studio' => $envio->matches_studio];
            $nuevo = [
                'meta_status' => $obs['submission']['meta_status'], 'meta_category' => $obs['submission']['meta_category'],
                'meta_correct_category' => $obs['submission']['meta_correct_category'], 'meta_rejected_reason' => $obs['submission']['meta_rejected_reason'],
                'meta_quality' => $obs['submission']['meta_quality'], 'meta_body_hash' => $obs['row']['meta_body_hash'],
                'meta_components_hash' => $obs['row']['meta_components_hash'],
                'matches_studio' => $desajustes === [], 'mismatch_codes' => $desajustes === [] ? null : mb_substr(implode(',', $desajustes), 0, 200),
            ];
            foreach ($nuevo as $columna => $valor) {
                if ($envio->{$columna} !== $valor) {
                    $cambio = true;
                }
            }
            if (! $dryRun) {
                $envio->forceFill($nuevo + ['last_checked_at' => $ahora] + ($cambio ? ['last_changed_at' => $ahora] : []))->save();
                $this->changeEvents($t, $antes, $nuevo, $attester, $request);
            }
        }

        // 4. El registro del canal: una fila nueva solo si cambió lo que compara.
        $escrita = false;
        $sinTestigo = false;
        if (! self::sameTuple($obs['row'], $ultima)) {
            $testigo = $positiva
                ? ($attester ?? ($envio !== null && self::canAttest((int) $envio->submitted_by_admin_id) ? Admin::query()->find($envio->submitted_by_admin_id) : null))
                : null;
            $firma = match (true) {
                $testigo instanceof Admin => ['id' => (int) $testigo->id, 'name' => self::staffName($testigo)],
                $positiva => null,
                $attester !== null => ['id' => (int) $attester->id, 'name' => self::staffName($attester)],
                $ultima !== null => ['id' => (int) $ultima->recorded_by_admin_id, 'name' => (string) $ultima->recorded_by_name],
                $envio !== null => ['id' => (int) $envio->submitted_by_admin_id, 'name' => (string) $envio->submitted_by_name],
                default => null,
            };
            if ($firma === null) {
                // Una positiva sin nadie que pueda dar fe ahora: no se escribe el APPROVED (§5.6).
                $sinTestigo = $positiva;
            } elseif (! $dryRun) {
                $escrita = $this->insertCheck($t, $envio, $ultima, $obs['row'], $firma, $ahora);
                if ($escrita) {
                    $this->audit->record($request, $attester, 'meta_template.meta_check_recorded', self::SUBJECT, (string) $t->uuid,
                        "Quedó registrado lo que dice Meta de {$nombre}: {$obs['row']['status']}",
                        ['toStatus' => $obs['row']['status'], 'contentHash' => (string) $t->content_hash, 'diff' => ['source' => self::SOURCE_API, 'matches' => $desajustes === [], 'attestedBy' => $firma['name']]],
                        self::SYSTEM_SYNC);
                }
            } else {
                $escrita = true;
            }
        }

        // 5. Quién da fe: el aviso al CRM mientras una positiva espera a alguien que pueda firmarla.
        if ($envio !== null && $envio->state === Submission::STATE_SUBMITTED && ! $dryRun) {
            $espera = $sinTestigo;
            if ($espera !== (bool) $envio->attester_unavailable) {
                $envio->forceFill(['attester_unavailable' => $espera])->save();
                if ($espera) {
                    $this->audit->record($request, $attester, 'meta_template.attester_unavailable', self::SUBJECT, (string) $t->uuid,
                        "Meta aprobó {$nombre}, pero quien la envió ya no puede dar fe: falta que otra persona con la llave pulse «Comprobar ahora»",
                        ['toStatus' => self::STATUS_APPROVED], self::SYSTEM_SYNC);
                }
            }
        }

        return [
            'note' => $observada === null ? 'not_found' : null,
            'before' => $ultima !== null ? (string) $ultima->status : null,
            'after' => $obs['row']['status'],
            'changed' => $cambio || $escrita,
            'rowWritten' => $escrita,
            'attesterUnavailable' => $sinTestigo,
            // En seco, lo que diría el puente si se escribiera; si no, el puente.
            'channelAccepts' => $dryRun ? $comparacion['channelAccepts'] && ! $sinTestigo : app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id),
        ];
    }

    /**
     * Lo observado con la forma del registro: lo que Meta dice, o UNKNOWN
     * (con lo del Studio en su lugar) si no se puede registrar tal cual; si no
     * está, NOT_FOUND con huellas que nunca casan.
     *
     * @param  array<string, mixed>  $contenido
     * @return array{row: array<string, mixed>, submission: array<string, string|null>, extraMismatch: string|null}
     */
    private static function observation(StudioMessageTemplate $t, array $contenido, ?Submission $envio, ?object $ultima, ?MetaTemplate $m): array
    {
        $previo = [
            'meta_name' => $ultima !== null ? (string) $ultima->meta_name : (string) ($envio?->meta_name ?? $contenido['metaName'] ?? ''),
            'language' => $ultima !== null ? (string) $ultima->language : (string) ($envio?->language ?? $contenido['language'] ?? ''),
            'category' => $ultima !== null ? (string) $ultima->category : (string) ($contenido['category'] ?? ''),
            'meta_template_id' => $envio?->meta_template_id ?? ($ultima !== null && $ultima->meta_template_id !== null ? (string) $ultima->meta_template_id : null),
        ];
        if ($m === null) {
            return [
                'row' => $previo + [
                    'status' => self::STATUS_NOT_FOUND, 'meta_body_hash' => self::EMPTY_BODY_HASH,
                    'meta_components_hash' => CanonicalJson::hash(['notFound' => true]),
                ],
                'submission' => ['meta_status' => self::STATUS_NOT_FOUND, 'meta_category' => $envio?->meta_category, 'meta_correct_category' => null,
                    'meta_rejected_reason' => null, 'meta_quality' => $envio?->meta_quality],
                'extraMismatch' => self::MISMATCH_NOT_FOUND,
            ];
        }

        $nombre = is_string($m->name) && preg_match(StudioMessageTemplates::META_NAME_PATTERN, $m->name) === 1 ? $m->name : null;
        $idioma = is_string($m->language) && in_array($m->language, StudioMessageTemplates::LANGUAGES, true) ? $m->language : null;
        $categoria = is_string($m->category) && in_array(strtolower($m->category), self::CHECK_CATEGORIES, true) ? strtolower($m->category) : null;
        $estado = is_string($m->status) && in_array($m->status, self::API_STATUSES, true) ? $m->status : null;
        $ilegible = $nombre === null || $idioma === null || $categoria === null || $estado === null || $m->components === null;

        $texto = $m->components !== null ? StudioMessageTemplates::metaBodyText($m->components) : null;
        $huellaCuerpo = $texto === null ? self::EMPTY_BODY_HASH : (StudioTemplateMetaChecks::bodyHash($texto) ?? hash('sha256', $texto));
        $huellaComponentes = $m->components !== null ? StudioMessageTemplates::metaComponentsFingerprint($m->components) : CanonicalJson::hash(['unreadable' => true]);

        return [
            'row' => [
                'status' => $ilegible ? self::STATUS_UNKNOWN : $estado,
                'meta_name' => $nombre ?? $previo['meta_name'],
                'language' => $idioma ?? $previo['language'],
                'category' => $categoria ?? $previo['category'],
                'meta_body_hash' => $huellaCuerpo,
                'meta_components_hash' => $huellaComponentes,
                'meta_template_id' => $m->id,
            ],
            'submission' => [
                'meta_status' => $m->status, 'meta_category' => $m->category, 'meta_correct_category' => $m->correctCategory,
                'meta_rejected_reason' => $m->rejectedReason, 'meta_quality' => $m->qualityScore,
            ],
            'extraMismatch' => $ilegible ? self::MISMATCH_UNREADABLE : null,
        ];
    }

    /**
     * ¿Es lo mismo que la última fila, en lo que compara el canal? Una fila
     * manual sin huella de componentes vale lo que una plantilla sin ellos
     * (la regla del puente), y el id solo cuenta si los dos lo tienen.
     *
     * @param  array<string, mixed>  $fila
     */
    private static function sameTuple(array $fila, ?object $ultima): bool
    {
        if ($ultima === null) {
            return false;
        }
        $suya = $ultima->meta_components_hash !== null ? (string) $ultima->meta_components_hash : StudioMessageTemplates::componentsFingerprint([]);
        $ids = $ultima->meta_template_id === null || $fila['meta_template_id'] === null || (string) $ultima->meta_template_id === (string) $fila['meta_template_id'];

        return (string) $ultima->status === $fila['status'] && (string) $ultima->meta_name === $fila['meta_name'] && (string) $ultima->language === $fila['language']
            && (string) $ultima->category === $fila['category'] && hash_equals((string) $ultima->meta_body_hash, (string) $fila['meta_body_hash'])
            && hash_equals($suya, (string) $fila['meta_components_hash']) && $ids;
    }

    /**
     * UNA fila `meta_api`, idempotente: su `client_request_id` es un UUIDv5 de
     * la versión, lo observado y la fila a la que sigue; dos pasadas a la vez
     * chocan en el UNIQUE y la segunda no escribe. Devuelve si la escribió.
     *
     * @param  array<string, mixed>  $fila
     * @param  array{id: int, name: string}  $firma
     */
    private function insertCheck(StudioMessageTemplate $t, ?Submission $envio, ?object $ultima, array $fila, array $firma, DateTimeImmutable $ahora): bool
    {
        $semilla = implode('|', [(int) $t->id, $fila['status'], $fila['meta_name'], $fila['language'], $fila['category'], $fila['meta_body_hash'],
            $fila['meta_components_hash'], (string) $fila['meta_template_id'], $ultima !== null ? (int) $ultima->id : 0]);
        $marca = $ahora->format('Y-m-d H:i:s');
        try {
            DB::table(StudioTemplateMetaChecks::TABLE)->insert([
                'uuid' => strtolower((string) Str::uuid()),
                'client_request_id' => strtolower(Uuid::uuid5(self::CHECK_NAMESPACE, $semilla)->toString()),
                'template_id' => (int) $t->id,
                'template_content_hash' => (string) $t->content_hash,
                'meta_name' => $fila['meta_name'],
                'language' => $fila['language'],
                'category' => $fila['category'],
                'meta_body_hash' => $fila['meta_body_hash'],
                'meta_template_id' => $fila['meta_template_id'],
                'status' => $fila['status'],
                'checked_at' => $marca,
                'recorded_by_admin_id' => $firma['id'],
                'recorded_by_name' => mb_substr($firma['name'], 0, 120),
                'created_at' => $marca,
                'source' => self::SOURCE_API,
                'submission_id' => $envio?->id,
                'meta_components_hash' => $fila['meta_components_hash'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra pasada escribió ESTA misma observación (mismo UUIDv5): nada que hacer.
            return false;
        }

        return true;
    }

    /** Los eventos del libro de lo que cambió en el envío (estado, calidad, categoría, coincidencia). */
    private function changeEvents(StudioMessageTemplate $t, array $antes, array $nuevo, ?Admin $actor, ?Request $request): void
    {
        $de = "la versión {$t->number} de la plantilla «{$t->key}»";
        $eventos = [
            'meta_status' => ['meta_template.status_changed', "Meta cambió el estado de {$de}"],
            'meta_quality' => ['meta_template.quality_changed', "Meta cambió la calidad de {$de}"],
            'meta_category' => ['meta_template.category_changed', "Meta cambió la categoría de {$de}"],
        ];
        foreach ($eventos as $columna => [$evento, $resumen]) {
            if ($antes[$columna] !== $nuevo[$columna]) {
                $resumen .= ': '.($antes[$columna] ?? '—').' → '.($nuevo[$columna] ?? '—');
                $this->audit->record($request, $actor, $evento, self::SUBJECT, (string) $t->uuid, $resumen,
                    ['fromStatus' => self::code20($antes[$columna]), 'toStatus' => self::code20($nuevo[$columna])], self::SYSTEM_SYNC);
            }
        }
        if ($nuevo['matches_studio'] === false && $antes['matches_studio'] !== false) {
            $this->audit->record($request, $actor, 'meta_template.mismatch_detected', self::SUBJECT, (string) $t->uuid,
                "Lo que Meta tiene de {$de} no casa con el Studio: no se usará en campañas",
                ['diff' => ['mismatch' => $nuevo['mismatch_codes']]], self::SYSTEM_SYNC);
        }
    }

    // ── Piezas: lecturas de la base ─────────────────────────────────────────

    /**
     * Lo que vigila la sincronización: (a) las versiones con un envío vivo y
     * (b) las de WhatsApp cuya última comprobación no cita un envío (las
     * registradas a mano y las que se siguen desde entonces).
     *
     * @return list<array{template: StudioMessageTemplate, submission: Submission|null, latest: object|null}>
     */
    private function watched(): array
    {
        $envios = Submission::query()->where('state', '<>', Submission::STATE_FAILED)->get()->keyBy('template_id');
        $ultimas = DB::table(StudioTemplateMetaChecks::TABLE)
            ->whereIn('id', DB::table(StudioTemplateMetaChecks::TABLE)->selectRaw('max(id)')->groupBy('template_id'))
            ->get()->keyBy('template_id');
        $ids = array_values(array_unique([...$envios->keys()->all(), ...$ultimas->filter(static fn (object $u): bool => $u->submission_id === null)->keys()->all()]));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (StudioMessageTemplate::query()->whereIn('id', $ids)->where('channel', StudioMessageTemplates::CHANNEL_WHATSAPP)->orderBy('id')->get() as $t) {
            $out[] = ['template' => $t, 'submission' => $envios[$t->id] ?? null, 'latest' => $ultimas[$t->id] ?? null];
        }

        return $out;
    }

    /**
     * Cómo encontrar la versión en Meta: el envío vivo (su id; sin él, su
     * nombre e idioma) o, sin envío, la última comprobación sin envío.
     *
     * @return array{id: string|null, name: string, language: string}|null
     */
    private static function target(?Submission $envio, ?object $ultima): ?array
    {
        if ($envio !== null && $envio->isLive()) {
            return ['id' => $envio->meta_template_id, 'name' => (string) $envio->meta_name, 'language' => (string) $envio->language];
        }
        if ($ultima !== null && $ultima->submission_id === null) {
            return ['id' => $ultima->meta_template_id !== null ? (string) $ultima->meta_template_id : null, 'name' => (string) $ultima->meta_name, 'language' => (string) $ultima->language];
        }

        return null;
    }

    /** Un SENDING de un proceso que murió a mitad pasa a UNKNOWN (con su evento). ¿Lo era? */
    private function staleSending(Submission $envio, DateTimeImmutable $ahora, ?Admin $actor): bool
    {
        if ($envio->created_at === null || $envio->created_at->diffInSeconds($ahora, false) < self::SENDING_STALE_SECONDS) {
            return false;
        }
        $envio->forceFill(['state' => Submission::STATE_UNKNOWN])->save();
        $t = StudioMessageTemplate::query()->find($envio->template_id);
        $this->audit->record(null, $actor, 'meta_template.submit_unknown', self::SUBJECT, $t?->uuid,
            'Un envío a Meta quedó a medias: no se sabe si llegó y se comprobará solo',
            ['fromStatus' => Submission::STATE_SENDING, 'toStatus' => Submission::STATE_UNKNOWN, 'diff' => ['detail' => 'stale_sending']], self::SYSTEM_SYNC);

        return true;
    }

    private static function liveSubmission(int $templateId): ?Submission
    {
        return Submission::query()->where('template_id', $templateId)->where('state', '<>', Submission::STATE_FAILED)->orderByDesc('id')->first();
    }

    /** El envío que enseña el CRM: el vivo o, si no hay, el último (fallido). */
    private static function currentSubmission(int $templateId): ?Submission
    {
        return self::liveSubmission($templateId) ?? Submission::query()->where('template_id', $templateId)->orderByDesc('id')->first();
    }

    private static function latestCheck(int $templateId): ?object
    {
        return DB::table(StudioTemplateMetaChecks::TABLE)->where('template_id', $templateId)->orderByDesc('id')->first();
    }

    /** El estado en Meta que enseña el CRM: lo último observado del envío vivo; si no, la última comprobación. */
    private static function metaStatusOf(?Submission $envio, ?object $ultima): ?string
    {
        if ($envio !== null && $envio->state === Submission::STATE_SUBMITTED && $envio->meta_status !== null) {
            return (string) $envio->meta_status;
        }

        return $ultima !== null ? (string) $ultima->status : null;
    }

    /** ¿Puede dar fe ahora? Activa, con la llave (y la de ver) y su persona verificada (§5.6). */
    private static function canAttest(int $adminId): bool
    {
        $cuenta = Admin::query()->find($adminId);
        if (! $cuenta instanceof Admin || ! $cuenta->isActive()) {
            return false;
        }
        $acceso = new StudioAccess($cuenta);

        return $acceso->can(StudioAccess::VIEW) && $acceso->can(self::PERMISSION) && StudioPersonBindings::personOf($adminId) !== null;
    }

    /** Defensiva (§5.8.3): ¿la escribió y la aprobó la misma persona (por cuenta o por identidad)? */
    private static function sameAuthorAndReviewer(StudioMessageTemplate $t): bool
    {
        if ($t->reviewed_by_admin_id === null) {
            return true;
        }

        return StudioPersonBindings::samePerson((int) $t->created_by_admin_id, (int) $t->created_by_person_id, (int) $t->reviewed_by_admin_id,
            $t->reviewed_by_person_id !== null ? (int) $t->reviewed_by_person_id : null);
    }

    /** @return array<string, mixed> */
    private static function content(StudioMessageTemplate $t): array
    {
        $c = json_decode((string) $t->content_json, true);

        return is_array($c) ? $c : [];
    }

    /**
     * La referencia legible de un id de Meta (`***NNNN`): la misma forma que la de las cuentas y las campañas del H6
     * (`LeadSourceClassifier::maskId`), escrita aquí para que este servicio NO nombre una clase del motor (guarda del
     * H5, `UltronStudioEngineReadOnlyTest`: solo los adaptadores de su lista pueden hacerlo).
     */
    public static function maskedRef(?string $id): ?string
    {
        $id = trim((string) $id);

        return $id === '' ? null : '***'.substr($id, -4);
    }

    /** @return array<string, mixed> */
    public static function submissionDto(Submission $s): array
    {
        $error = $s->error_code !== null || $s->error_user_message !== null
            ? ['code' => $s->error_code, 'subcode' => $s->error_subcode, 'message' => $s->error_user_message]
            : null;

        return [
            'uuid' => (string) $s->uuid,
            'state' => (string) $s->state,
            'metaName' => (string) $s->meta_name,
            'language' => (string) $s->language,
            'category' => (string) $s->category,
            // El id de Meta no sale del servidor (§I2 del contrato del H6): solo su referencia legible `***NNNN`.
            // En el Administrador de WhatsApp la plantilla se busca por su nombre y su idioma.
            'metaTemplateRef' => self::maskedRef($s->meta_template_id === null ? null : (string) $s->meta_template_id),
            'metaStatus' => $s->meta_status,
            'metaCategory' => $s->meta_category,
            'correctCategory' => $s->meta_correct_category,
            'rejectedReason' => $s->meta_rejected_reason,
            'quality' => $s->meta_quality,
            'matchesStudio' => $s->matches_studio,
            'mismatch' => $s->mismatch_codes === null ? [] : explode(',', (string) $s->mismatch_codes),
            'attesterUnavailable' => (bool) $s->attester_unavailable,
            'error' => $error,
            'submittedBy' => ['name' => (string) $s->submitted_by_name, 'role' => $s->submitted_by_role],
            'submittedAt' => $s->submitted_at?->toIso8601String(),
            'lastCheckedAt' => $s->last_checked_at?->toIso8601String(),
            'lastChangedAt' => $s->last_changed_at?->toIso8601String(),
        ];
    }

    /** @return array{allowed: bool, code?: string} */
    private static function allowed(?string $code): array
    {
        return $code === null ? ['allowed' => true] : ['allowed' => false, 'code' => $code];
    }

    /**
     * La cuenta de WhatsApp para llamar, o 409 `meta_not_configured`.
     *
     * @throws UltronStudioException
     */
    private function assertConfigured(): string
    {
        $problema = $this->configurationProblem();
        if ($problema !== null) {
            throw new UltronStudioException('La conexión con Meta no está configurada en el servidor (la cuenta de WhatsApp o su credencial).', self::CODE_NOT_CONFIGURED, 409, ['reason' => $problema]);
        }

        return (string) $this->wabaId();
    }

    /** @return array<string, mixed>|null la última sonda, si es de dentro del enfriamiento */
    private static function recentProbe(DateTimeInterface $now): ?array
    {
        $sonda = Cache::get(self::CACHE_PROBE);
        if (! is_array($sonda) || ! is_string($sonda['at'] ?? null) || ! RelativeTime::isInstant($sonda['at'])) {
            return null;
        }
        $edad = RelativeTime::utc($now)->getTimestamp() - RelativeTime::parseInstant($sonda['at'])->getTimestamp();

        return $edad >= 0 && $edad < self::PROBE_COOLDOWN_SECONDS ? $sonda : null;
    }

    /** @param  array<string, mixed>  $efecto @return array<string, mixed> */
    private static function row(StudioMessageTemplate $t, array $efecto): array
    {
        return ['uuid' => (string) $t->uuid, 'key' => (string) $t->key, 'number' => (int) $t->number] + $efecto;
    }

    /** Lo que `status` enseña de la última pasada (sin el detalle por plantilla). @param  array<string, mixed>  $r @return array<string, mixed> */
    private static function syncSummary(array $r): array
    {
        $filas = $r['templates'] ?? [];

        return [
            'at' => $r['at'], 'outcome' => $r['outcome'], 'watched' => $r['watched'], 'requests' => $r['requests'], 'error' => $r['error'],
            'changed' => count(array_filter($filas, static fn (array $f): bool => ($f['changed'] ?? false) === true)),
            'rowsWritten' => count(array_filter($filas, static fn (array $f): bool => ($f['rowWritten'] ?? false) === true)),
        ];
    }

    private static function staffName(Admin $cuenta): string
    {
        $nombre = trim((string) $cuenta->name);

        return mb_substr($nombre !== '' ? $nombre : 'Cuenta '.$cuenta->id, 0, 120);
    }

    /** Un estado para `from_status`/`to_status` del libro (varchar(20)). */
    private static function code20(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? mb_substr($v, 0, 20) : null;
    }
}
