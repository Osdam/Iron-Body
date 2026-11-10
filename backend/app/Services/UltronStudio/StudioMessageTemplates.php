<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Rules\PlainText;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutreachCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * LAS PLANTILLAS DE MENSAJE VERSIONADAS de las estrategias (contrato H9, D9-6
 * «Plantillas versionadas», D9-15, §6.1, §7 #21–#25): crear la versión
 * siguiente de una clave, listarlas, leerlas (con la REFERENCIA `{uuid, hash}`
 * lista para instanciarla en un paso y la VISTA PREVIA de sus marcadores) y
 * aprobarlas o rechazarlas a cuatro ojos POR PERSONA (P9-4). Una plantilla no
 * se edita: se crea su versión siguiente; el contenido es inmutable.
 *
 * EL CONTENIDO es un conjunto CERRADO por canal ({@see content()}, PURO):
 *  - `whatsapp`: `metaName`, `language` (`es`/`es_CO`), `category`
 *    (`utility`/`marketing`), `bodyPreview` (el texto que aprobó Meta, con
 *    `{{1}}`…`{{n}}`, solo para mostrar y comparar), `variables` (la lista de
 *    marcadores CERRADOS por posición, {@see MARKERS}) y `optOutLine: true`
 *    (obligatorio: el texto dice cómo dejar de recibir mensajes, Ley 2300 art.
 *    5 par. 2). Comercial solo `marketing`; cobranza y servicio solo
 *    `utility` (cobranza sin ofertas ni precio, P9-7).
 *  - `assisted`: `medium` (`whatsapp`/`voice`) y `script` (≤ 600, el guion
 *    que ve el personal).
 *  - `voice` (V9): `purposeKey`, un objetivo de la lista cerrada del motor
 *    comercial (`OutreachCatalog::GOALS`) hasta que el H8 compile su lista
 *    (`ultron-voice-template/2`, Enmienda 16).
 *
 * LOS TEXTOS (`bodyPreview`, `script`) pasan `PlainText` estricto, la guarda de
 * datos de contacto (AS-1) y las restricciones del código ({@see restrictionViolations()}):
 * R-01 (importes), R-02 (descuentos), R-03 (URL o datos de tarjeta) y R-06
 * (promesas). Ninguna cifra: el precio solo sale del catálogo con el marcador
 * `plan_price` (R-01), que Laravel resuelve al despachar.
 *
 * SIN EL ESTADO DE META el 15-11: llega en W9 con su tabla.
 */
final class StudioMessageTemplates
{
    public const CREATE = StudioStrategyConfig::EDIT;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_ASSISTED = 'assisted';

    public const CHANNEL_VOICE = 'voice';

    public const CHANNELS = [self::CHANNEL_ASSISTED, self::CHANNEL_VOICE, self::CHANNEL_WHATSAPP];

    public const LANGUAGES = ['es', 'es_CO'];

    public const CATEGORY_UTILITY = 'utility';

    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORIES = [self::CATEGORY_MARKETING, self::CATEGORY_UTILITY];

    /** La categoría que admite cada finalidad (D9-6, D9-15, P9-7). */
    public const CATEGORY_BY_PURPOSE = [
        OutreachCatalog::PURPOSE_COMMERCIAL => self::CATEGORY_MARKETING,
        OutreachCatalog::PURPOSE_COLLECTIONS => self::CATEGORY_UTILITY,
        OutreachCatalog::PURPOSE_SERVICE => self::CATEGORY_UTILITY,
    ];

    /**
     * Los marcadores CERRADOS de una plantilla de WhatsApp (D9-6): los resuelve
     * Laravel al despachar con los hechos de ese instante. `plan_price` es el
     * único importe (el del catálogo, R-01); `payment_link` marca la plantilla
     * como «de enlace de pago» (N3 `payment_links_disabled`).
     */
    public const MARKERS = [
        'class_name', 'class_time_text', 'days_to_expiry', 'first_name', 'gym_name', 'membership_end_date_text', 'payment_link', 'plan_name', 'plan_price',
    ];

    /** La cobranza no ofrece nada (P9-7): ni precio de plan. */
    public const MARKERS_FORBIDDEN_BY_PURPOSE = [OutreachCatalog::PURPOSE_COLLECTIONS => ['plan_price']];

    public const PAYMENT_LINK_MARKER = 'payment_link';

    public const MAX_VARIABLES = 10;

    public const BODY_MAX = 1024;

    public const SCRIPT_MAX = 600;

    public const MAX_CONTENT_BYTES = 8192;

    public const KEY_PATTERN = OutreachCatalog::STRATEGY_KEY_PATTERN;

    public const META_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    public const NOTE_MIN = 5;

    public const NOTE_MAX = 500;

    public const PER_PAGE_MAX = 50;

    // Las restricciones del código sobre un texto (comprobadas aquí, en la validación de la estrategia y otra vez al despachar, W9).
    public const R01_AMOUNT = 'R-01';

    public const R02_DISCOUNT = 'R-02';

    public const R03_URL = 'R-03';

    public const R06_PROMISE = 'R-06';

    /** Importes: signo de moneda con cifra, cifras con separador de miles o moneda escrita. */
    private const AMOUNT_PATTERNS = ['/[$€]\s*\d/u', '/\b\d{1,3}(?:[.,]\d{3})+\b/u', '/\b\d+(?:[.,]\d+)?\s*(?:pesos|cop|usd|dolares|mil)\b/u'];

    /** Descuentos (R-02: «gratis», «2x1» y «precio especial» cuentan como descuento). */
    private const DISCOUNT_PATTERNS = ['/\bgratis\b/u', '/\b2\s*x\s*1\b/u', '/\bprecio\s+especial\b/u', '/\bdescuentos?\b/u', '/\brebajas?\b/u', '/\d+\s*%/u', '/\bdcto\b/u'];

    /** URL o datos de tarjeta (R-03). */
    private const URL_PATTERNS = ['/\bhttps?:\/\//u', '/\bwww\./u', '/\b[a-z0-9-]+\.(?:com|co|net|org|io|app|ly|me|link|info|biz|shop|store|site|online)\b/u', '/\b(?:\d[ -]?){13,19}\b/u'];

    /** Promesas de efectos que no ocurren (R-06). */
    private const PROMISE_PATTERNS = ['/\bgarantiz/u', '/\bte\s+prometo\b/u', '/\bprometemos\b/u', '/\bresultados?\s+asegurados?\b/u', '/\b100\s*%\s*segur/u'];

    public function __construct(private readonly StudioAudit $audit) {}

    // ── El contenido (puro) ─────────────────────────────────────────────────

    /**
     * Valida el contenido de una versión de plantilla y lo devuelve canónico.
     * Todos los errores a la vez, como pares `{key, code}` (raíz `content`).
     *
     * @return array{canonical: array<string,mixed>|null, json: string|null, hash: string|null, errors: list<array{key:string,code:string}>, usesPaymentLink: bool}
     */
    public static function content(string $channel, string $purpose, mixed $raw): array
    {
        $errores = [];
        $falla = static function (string $clave, string $codigo) use (&$errores): void {
            $errores[] = ['key' => $clave, 'code' => $codigo];
        };
        if (! in_array($channel, self::CHANNELS, true)) {
            $falla('channel', 'value_not_in_domain');
        }
        if (! in_array($purpose, OutreachCatalog::PURPOSES, true)) {
            $falla('purpose', 'value_not_in_domain');
        }
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $falla('content', 'not_an_object');

            return self::result(null, $errores);
        }
        $canonico = match ($channel) {
            self::CHANNEL_WHATSAPP => self::whatsapp($raw, $purpose, $falla),
            self::CHANNEL_ASSISTED => self::assisted($raw, $falla),
            self::CHANNEL_VOICE => self::voice($raw, $falla),
            default => null,
        };

        return self::result($errores === [] ? $canonico : null, $errores);
    }

    /**
     * Las restricciones del código que un texto incumple (R-01, R-02, R-03,
     * R-06), ordenadas y sin repetir. Pura; con UTF-8 inválido falla cerrada.
     *
     * @return list<string>
     */
    public static function restrictionViolations(string $text): array
    {
        if (preg_match('//u', $text) !== 1) {
            return [self::R01_AMOUNT, self::R02_DISCOUNT, self::R03_URL, self::R06_PROMISE];
        }
        // Sin tildes ni mayúsculas: «Garantízalo» y «GRATIS» cuentan igual.
        $plano = mb_strtolower(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ü' => 'u', 'Ü' => 'u']));
        // Los marcadores {{n}} no son cifras del texto.
        $plano = (string) preg_replace('/\{\{\d+\}\}/u', ' ', $plano);
        $fuera = [];
        foreach ([self::R01_AMOUNT => self::AMOUNT_PATTERNS, self::R02_DISCOUNT => self::DISCOUNT_PATTERNS, self::R03_URL => self::URL_PATTERNS, self::R06_PROMISE => self::PROMISE_PATTERNS] as $regla => $patrones) {
            foreach ($patrones as $p) {
                if (preg_match($p, $plano) === 1) {
                    $fuera[] = $regla;

                    break;
                }
            }
        }

        return $fuera;
    }

    /**
     * ¿Sirve una plantilla (su fila) para el canal de un paso? `whatsapp_template`
     * ↔ `whatsapp`; `voice_call` ↔ `voice`; `assisted:<medio>` ↔ `assisted` con
     * ese medio.
     *
     * @param  array<string,mixed>  $content  el contenido canónico de la plantilla
     */
    public static function fitsStepChannel(string $stepChannel, string $templateChannel, array $content): bool
    {
        return match ($stepChannel) {
            OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE => $templateChannel === self::CHANNEL_WHATSAPP,
            OutreachCatalog::CHANNEL_VOICE_CALL => $templateChannel === self::CHANNEL_VOICE,
            OutreachCatalog::CHANNEL_ASSISTED_WHATSAPP => $templateChannel === self::CHANNEL_ASSISTED && ($content['medium'] ?? null) === OutreachCatalog::MEDIUM_WHATSAPP,
            OutreachCatalog::CHANNEL_ASSISTED_VOICE => $templateChannel === self::CHANNEL_ASSISTED && ($content['medium'] ?? null) === OutreachCatalog::MEDIUM_VOICE,
            default => false,
        };
    }

    /** @param  array<string,mixed>  $content */
    public static function usesPaymentLink(array $content): bool
    {
        return in_array(self::PAYMENT_LINK_MARKER, (array) ($content['variables'] ?? []), true);
    }

    /**
     * Las restricciones que incumple el texto de una plantilla guardada (para
     * N3 al validar la estrategia).
     *
     * @param  array<string,mixed>  $content
     * @return list<string>
     */
    public static function violationsOf(array $content): array
    {
        $texto = (string) ($content['bodyPreview'] ?? $content['script'] ?? '');

        return $texto === '' ? [] : self::restrictionViolations($texto);
    }

    /**
     * La vista previa: el texto con cada `{{n}}` sustituido por la etiqueta de
     * su marcador (`[first_name]`). Sin datos de nadie.
     *
     * @param  array<string,mixed>  $content
     */
    public static function preview(string $channel, array $content): ?string
    {
        if ($channel === self::CHANNEL_WHATSAPP) {
            $variables = array_values((array) ($content['variables'] ?? []));

            return (string) preg_replace_callback('/\{\{(\d+)\}\}/u', static fn (array $m): string => '['.($variables[(int) $m[1] - 1] ?? '?').']', (string) ($content['bodyPreview'] ?? ''));
        }

        return $channel === self::CHANNEL_ASSISTED ? (string) ($content['script'] ?? '') : null;
    }

    // ── Crear (POST strategies/templates) ───────────────────────────────────

    /**
     * La versión siguiente de una clave (la primera si la clave es nueva), en
     * DRAFT. Exige la persona verificada de quien crea (sin ella no hay cuatro
     * ojos por persona después). Idempotente por `clientRequestId`.
     *
     * @return array{template:StudioMessageTemplate,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $clientRequestId, string $key, string $channel, string $purpose, mixed $content): array
    {
        $access->require(self::CREATE);
        $llave = strtolower($clientRequestId);
        $c = self::content($channel, $purpose, $content);
        if ($c['canonical'] === null) {
            throw self::invalid($c['errors']);
        }
        $previa = StudioMessageTemplate::query()->where('client_request_id', $llave)->first();
        if ($previa !== null) {
            if ((int) $previa->created_by_admin_id !== $access->id() || $previa->key !== $key || $previa->channel !== $channel || $previa->purpose !== $purpose || $previa->content_hash !== $c['hash']) {
                throw UltronStudioException::clientRequestReused();
            }

            return ['template' => $previa, 'replayed' => true];
        }
        $persona = $persons->requirePerson($access, $request, 'message_template.create', StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE, null);
        $anterior = StudioMessageTemplate::query()->where('key', $key)->orderByDesc('number')->first();
        if ($anterior !== null && ($anterior->channel !== $channel || $anterior->purpose !== $purpose)) {
            throw new UltronStudioException('Una clave de plantilla no cambia de canal ni de finalidad: usa otra clave.', 'template_key_mismatch', 409,
                ['channel' => $anterior->channel, 'purpose' => $anterior->purpose]);
        }

        try {
            $fila = DB::transaction(function () use ($access, $request, $llave, $key, $channel, $purpose, $c, $persona): StudioMessageTemplate {
                // Las altas de una MISMA clave, en serie (PG: cerrojo consultivo y READ COMMITTED; `FOR UPDATE`
                // no vale con un agregado). La base garantiza además el número (`usmt_number_next` y el UNIQUE).
                StudioIntegrations::lock('ultron_studio_template:'.$key);
                $numero = (int) StudioMessageTemplate::query()->where('key', $key)->max('number') + 1;
                $yo = $access->snapshot();
                $ahora = now();
                $t = StudioMessageTemplate::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => $llave,
                    'key' => $key,
                    'number' => $numero,
                    'channel' => $channel,
                    'purpose' => $purpose,
                    // La categoría, solo en WhatsApp: la del contenido (DB9, `usmt_category_chk`).
                    'category' => $channel === self::CHANNEL_WHATSAPP ? $c['canonical']['category'] : null,
                    'content_json' => $c['json'],
                    'content_hash' => $c['hash'],
                    'status' => StudioMessageTemplate::STATUS_DRAFT,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_person_id' => $persona['identityId'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'status_changed_at' => $ahora,
                ]);
                $this->audit->record($request, $access->actor, 'message_template.created', StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE, $t->uuid,
                    "Creó la versión {$numero} de la plantilla «{$key}»", ['toStatus' => StudioMessageTemplate::STATUS_DRAFT, 'contentHash' => $c['hash'],
                        'diff' => ['key' => $key, 'number' => $numero, 'channel' => $channel, 'purpose' => $purpose]]);

                return $t;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UltronStudioException('Otra persona creó a la vez una versión de esta plantilla: vuelve a intentarlo.', 'template_moved', 409);
        }

        return ['template' => $fila, 'replayed' => false];
    }

    // ── Aprobar y rechazar (cuatro ojos por persona) ────────────────────────

    public function approve(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $uuid, ?string $note): StudioMessageTemplate
    {
        return $this->review($access, $request, $persons, $uuid, StudioMessageTemplate::STATUS_APPROVED, $note);
    }

    public function reject(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $uuid, string $note): StudioMessageTemplate
    {
        return $this->review($access, $request, $persons, $uuid, StudioMessageTemplate::STATUS_REJECTED, $note);
    }

    private function review(StudioAccess $access, Request $request, StudioPersonBindings $persons, string $uuid, string $estado, ?string $note): StudioMessageTemplate
    {
        $access->require(StudioStrategyConfig::APPROVE);
        $t = self::findByUuid($uuid);
        $persona = $persons->requirePerson($access, $request, 'message_template.review', StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE, $t->uuid);
        if (StudioPersonBindings::samePerson($access->id(), $persona['identityId'], (int) $t->created_by_admin_id, (int) $t->created_by_person_id)) {
            $this->audit->recordDenied($request, $access->actor, 'message_template.four_eyes_denied', StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE, $t->uuid,
                'Intentó revisar una plantilla propia (la misma cuenta o la misma persona)', ['diff' => ['decision' => $estado]]);

            throw new UltronStudioException('Nadie aprueba ni rechaza una plantilla propia (ni con otra cuenta de la misma persona): la revisa otra persona.', 'four_eyes_required', 403);
        }
        if ($t->status !== StudioMessageTemplate::STATUS_DRAFT) {
            throw new UltronStudioException('Solo se revisa una plantilla en borrador.', 'invalid_transition', 409, ['currentStatus' => $t->status, 'allowedFrom' => [StudioMessageTemplate::STATUS_DRAFT]]);
        }
        // La integridad antes de aprobar: lo que se aprueba es lo que se guardó.
        if (! hash_equals(hash('sha256', (string) $t->content_json), (string) $t->content_hash)) {
            throw UltronStudioException::integrityMismatch('La huella de la plantilla no coincide con su contenido: no se puede aprobar.');
        }

        return DB::transaction(function () use ($access, $request, $t, $estado, $note, $persona): StudioMessageTemplate {
            $ahora = now();
            $yo = $access->snapshot();
            $hechas = StudioMessageTemplate::query()->whereKey($t->id)->where('status', StudioMessageTemplate::STATUS_DRAFT)->update([
                'status' => $estado,
                'status_changed_at' => $ahora,
                'reviewed_by_admin_id' => $yo['id'],
                'reviewed_by_name' => $yo['name'],
                'reviewed_by_role' => $yo['role'],
                'reviewed_by_person_id' => $persona['identityId'],
                'review_note' => $note,
                'reviewed_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('La plantilla cambió mientras la revisabas: recárgala.', 'template_moved', 409);
            }
            $verbo = $estado === StudioMessageTemplate::STATUS_APPROVED ? 'Aprobó' : 'Rechazó';
            $this->audit->record($request, $access->actor, $estado === StudioMessageTemplate::STATUS_APPROVED ? 'message_template.approved' : 'message_template.rejected',
                StudioStrategyConfig::SUBJECT_MESSAGE_TEMPLATE, $t->uuid, "{$verbo} la versión {$t->number} de la plantilla «{$t->key}»",
                ['fromStatus' => StudioMessageTemplate::STATUS_DRAFT, 'toStatus' => $estado, 'contentHash' => $t->content_hash, 'diff' => ['note' => $note]]);

            return $t->refresh();
        });
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    public static function findByUuid(string $uuid): StudioMessageTemplate
    {
        $t = StudioMessageTemplate::query()->where('uuid', strtolower($uuid))->first();
        if ($t === null) {
            throw new UltronStudioException('La plantilla no existe.', 'template_not_found', 404);
        }

        return $t;
    }

    /** `GET strategies/templates` (≤ 50), de la más reciente a la más antigua; sin el texto. */
    public static function page(?string $key, ?string $channel, ?string $status, int $perPage): LengthAwarePaginator
    {
        return StudioMessageTemplate::query()
            ->when($key, fn ($q, string $k) => $q->where('key', $k))
            ->when($channel, fn ($q, string $c) => $q->where('channel', $c))
            ->when($status, fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min($perPage, self::PER_PAGE_MAX))
            ->through(static fn (StudioMessageTemplate $t): array => self::summaryDto($t));
    }

    /** @return array<string, mixed> */
    public static function summaryDto(StudioMessageTemplate $t): array
    {
        return [
            'uuid' => (string) $t->uuid,
            'key' => (string) $t->key,
            'number' => (int) $t->number,
            'channel' => (string) $t->channel,
            'purpose' => (string) $t->purpose,
            'status' => (string) $t->status,
            'contentHash' => (string) $t->content_hash,
            // «Instanciar»: la referencia exacta que lleva un paso de una estrategia.
            'reference' => ['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash],
            'createdBy' => ['name' => (string) $t->created_by_name, 'role' => $t->created_by_role],
            'createdAt' => $t->created_at?->toIso8601String(),
            'statusChangedAt' => $t->status_changed_at?->toIso8601String(),
        ];
    }

    /**
     * `MessageTemplateDto`: el contenido, la vista previa, las restricciones que
     * incumple su texto, lo que puede hacer quien mira y por qué no.
     *
     * @return array<string, mixed>
     */
    public static function detailDto(StudioMessageTemplate $t, StudioAccess $access): array
    {
        $contenido = json_decode((string) $t->content_json, true);
        $contenido = is_array($contenido) ? $contenido : [];
        $persona = StudioPersonBindings::personOf($access->id());
        $propia = StudioPersonBindings::samePerson($access->id(), $persona['identityId'] ?? null, (int) $t->created_by_admin_id, (int) $t->created_by_person_id);
        $no = static fn (string $code): array => ['allowed' => false, 'code' => $code];
        $revisar = match (true) {
            ! $access->can(StudioStrategyConfig::APPROVE) => $no('forbidden'),
            $persona === null => $no('person_binding_required'),
            $propia => $no('four_eyes_required'),
            $t->status !== StudioMessageTemplate::STATUS_DRAFT => $no('invalid_transition'),
            default => ['allowed' => true],
        };

        return self::summaryDto($t) + [
            'content' => $contenido,
            'preview' => self::preview((string) $t->channel, $contenido),
            'restrictions' => self::violationsOf($contenido),
            'usesPaymentLink' => self::usesPaymentLink($contenido),
            'integrity' => hash_equals(hash('sha256', (string) $t->content_json), (string) $t->content_hash),
            'review' => $t->reviewed_at === null ? null : [
                'note' => $t->review_note,
                'reviewer' => ['name' => (string) $t->reviewed_by_name, 'role' => $t->reviewed_by_role],
                'at' => $t->reviewed_at->toIso8601String(),
            ],
            'actions' => ['approve' => $revisar, 'reject' => $revisar],
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * @param  list<array{key:string,code:string}>  $errores
     */
    public static function invalid(array $errores): UltronStudioException
    {
        $mensajes = [];
        foreach ($errores as $e) {
            $mensajes[$e['key']][] = self::MESSAGES[$e['code']] ?? 'Valor no admitido.';
        }
        ksort($mensajes, SORT_STRING);

        return new UltronStudioException('La plantilla no pasa la validación.', 'template_invalid', 422, ['errors' => array_map(static fn (array $m): array => array_values(array_unique($m)), $mensajes), 'findings' => $errores]);
    }

    public const MESSAGES = [
        'not_an_object' => 'Tiene que ser un objeto.',
        'missing_key' => 'Falta este campo.',
        'unexpected_key' => 'Clave no admitida.',
        'invalid_type' => 'Tipo de valor incorrecto.',
        'value_not_in_domain' => 'El valor no está en el dominio cerrado.',
        'invalid_format' => 'El formato no es válido.',
        'too_long' => 'El texto es demasiado largo.',
        'plain_text' => 'El texto contiene caracteres no permitidos.',
        'contact_data' => 'El texto no puede llevar teléfonos ni correos.',
        'restriction_violated' => 'El texto incumple una restricción del código (importes, descuentos, URL o promesas).',
        'category_purpose_mismatch' => 'La categoría no corresponde a la finalidad (comercial: marketing; cobranza y servicio: utility).',
        'opt_out_required' => 'La plantilla tiene que decir cómo dejar de recibir mensajes (optOutLine: true).',
        'placeholders_mismatch' => 'Los marcadores {{n}} del texto no cuadran con las variables.',
        'duplicate_value' => 'Valor repetido.',
        'too_many_values' => 'Demasiados valores.',
        'marker_not_allowed' => 'Ese marcador no se admite con esta finalidad.',
        'too_large' => 'El contenido canónico pasa de 8 KB.',
    ];

    /**
     * @param  array<string,mixed>  $raw
     * @param  callable(string,string):void  $falla
     * @return array<string,mixed>|null
     */
    private static function whatsapp(array $raw, string $purpose, callable $falla): ?array
    {
        $bien = self::keys($raw, ['bodyPreview', 'category', 'language', 'metaName', 'optOutLine', 'variables'], [], $falla);
        $out = [];
        $nombre = $raw['metaName'] ?? null;
        if (array_key_exists('metaName', $raw)) {
            is_string($nombre) && preg_match(self::META_NAME_PATTERN, $nombre) === 1 ? $out['metaName'] = $nombre : $bien = self::failFalse($falla, 'content.metaName', 'invalid_format');
        }
        if (array_key_exists('language', $raw)) {
            in_array($raw['language'], self::LANGUAGES, true) ? $out['language'] = $raw['language'] : $bien = self::failFalse($falla, 'content.language', 'value_not_in_domain');
        }
        if (array_key_exists('category', $raw)) {
            if (! in_array($raw['category'], self::CATEGORIES, true)) {
                $bien = self::failFalse($falla, 'content.category', 'value_not_in_domain');
            } elseif (in_array($purpose, OutreachCatalog::PURPOSES, true) && self::CATEGORY_BY_PURPOSE[$purpose] !== $raw['category']) {
                $bien = self::failFalse($falla, 'content.category', 'category_purpose_mismatch');
            } else {
                $out['category'] = $raw['category'];
            }
        }
        if (array_key_exists('optOutLine', $raw)) {
            $raw['optOutLine'] === true ? $out['optOutLine'] = true : $bien = self::failFalse($falla, 'content.optOutLine', 'opt_out_required');
        }
        $variables = [];
        if (array_key_exists('variables', $raw)) {
            $v = $raw['variables'];
            if (! is_array($v) || ($v !== [] && ! array_is_list($v))) {
                $bien = self::failFalse($falla, 'content.variables', 'invalid_type');
            } elseif (count($v) > self::MAX_VARIABLES) {
                $bien = self::failFalse($falla, 'content.variables', 'too_many_values');
            } else {
                $prohibidos = self::MARKERS_FORBIDDEN_BY_PURPOSE[$purpose] ?? [];
                foreach ($v as $i => $marcador) {
                    if (! is_string($marcador) || ! in_array($marcador, self::MARKERS, true)) {
                        $bien = self::failFalse($falla, "content.variables.{$i}", is_string($marcador) ? 'value_not_in_domain' : 'invalid_type');
                    } elseif (in_array($marcador, $variables, true)) {
                        $bien = self::failFalse($falla, "content.variables.{$i}", 'duplicate_value');
                    } elseif (in_array($marcador, $prohibidos, true)) {
                        $bien = self::failFalse($falla, "content.variables.{$i}", 'marker_not_allowed');
                    } else {
                        $variables[] = $marcador;
                    }
                }
                $out['variables'] = $variables;
            }
        }
        if (array_key_exists('bodyPreview', $raw)) {
            $texto = self::text($raw['bodyPreview'], 'content.bodyPreview', self::BODY_MAX, $falla);
            if ($texto === null) {
                $bien = false;
            } else {
                preg_match_all('/\{\{(\d+)\}\}/u', $texto, $m);
                $usados = array_values(array_unique(array_map('intval', $m[1])));
                sort($usados);
                if (array_key_exists('variables', $raw) && is_array($raw['variables']) && $usados !== ($variables === [] ? [] : range(1, count($variables)))) {
                    $bien = self::failFalse($falla, 'content.bodyPreview', 'placeholders_mismatch');
                } else {
                    $out['bodyPreview'] = $texto;
                }
            }
        }

        return $bien ? $out : null;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  callable(string,string):void  $falla
     * @return array<string,mixed>|null
     */
    private static function assisted(array $raw, callable $falla): ?array
    {
        $bien = self::keys($raw, ['medium', 'script'], [], $falla);
        $out = [];
        if (array_key_exists('medium', $raw)) {
            in_array($raw['medium'], OutreachCatalog::MEDIUMS, true) ? $out['medium'] = $raw['medium'] : $bien = self::failFalse($falla, 'content.medium', 'value_not_in_domain');
        }
        if (array_key_exists('script', $raw)) {
            $texto = self::text($raw['script'], 'content.script', self::SCRIPT_MAX, $falla);
            $texto === null ? $bien = false : $out['script'] = $texto;
        }

        return $bien ? $out : null;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  callable(string,string):void  $falla
     * @return array<string,mixed>|null
     */
    private static function voice(array $raw, callable $falla): ?array
    {
        $bien = self::keys($raw, ['purposeKey'], [], $falla);
        if (array_key_exists('purposeKey', $raw) && ! in_array($raw['purposeKey'], OutreachCatalog::GOALS, true)) {
            $bien = self::failFalse($falla, 'content.purposeKey', 'value_not_in_domain');
        }

        return $bien ? ['purposeKey' => $raw['purposeKey']] : null;
    }

    /**
     * Un texto de plantilla: cadena, ≤ `$max`, `PlainText` estricto (varias
     * líneas), sin datos de contacto y sin incumplir R-01/R-02/R-03/R-06.
     *
     * @param  callable(string,string):void  $falla
     */
    private static function text(mixed $v, string $clave, int $max, callable $falla): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            $falla($clave, is_string($v) ? 'missing_key' : 'invalid_type');

            return null;
        }
        if (mb_strlen($v) > $max) {
            $falla($clave, 'too_long');

            return null;
        }
        $plano = Validator::make(['t' => $v], ['t' => [new PlainText(multiline: true, strict: true)]]);
        if ($plano->fails()) {
            $falla($clave, 'plain_text');

            return null;
        }
        if (StudioCanaryPhone::contactDataError($v) !== null) {
            $falla($clave, 'contact_data');

            return null;
        }
        if (self::restrictionViolations($v) !== []) {
            $falla($clave, 'restriction_violated');

            return null;
        }

        return $v;
    }

    /**
     * @param  array<string,mixed>  $v
     * @param  list<string>  $obligatorias
     * @param  list<string>  $opcionales
     * @param  callable(string,string):void  $falla
     */
    private static function keys(array $v, array $obligatorias, array $opcionales, callable $falla): bool
    {
        $bien = true;
        foreach (array_keys($v) as $k) {
            if (! in_array((string) $k, [...$obligatorias, ...$opcionales], true)) {
                $bien = self::failFalse($falla, 'content.'.(preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $k) === 1 ? $k : '_invalid_key'), 'unexpected_key');
            }
        }
        foreach ($obligatorias as $k) {
            if (! array_key_exists($k, $v)) {
                $bien = self::failFalse($falla, "content.{$k}", 'missing_key');
            }
        }

        return $bien;
    }

    /** @param  callable(string,string):void  $falla */
    private static function failFalse(callable $falla, string $clave, string $codigo): false
    {
        $falla($clave, $codigo);

        return false;
    }

    /**
     * @param  array<string,mixed>|null  $canonico
     * @param  list<array{key:string,code:string}>  $errores
     * @return array{canonical: array<string,mixed>|null, json: string|null, hash: string|null, errors: list<array{key:string,code:string}>, usesPaymentLink: bool}
     */
    private static function result(?array $canonico, array $errores): array
    {
        if ($canonico === null || $errores !== []) {
            return ['canonical' => null, 'json' => null, 'hash' => null, 'errors' => $errores, 'usesPaymentLink' => false];
        }
        try {
            $json = CanonicalJson::encode($canonico);
        } catch (InvalidArgumentException) {
            // Tragado a propósito: lo que no se puede escribir canónico no se guarda; es un 422.
            return ['canonical' => null, 'json' => null, 'hash' => null, 'errors' => [['key' => 'content', 'code' => 'invalid_type']], 'usesPaymentLink' => false];
        }
        if (strlen($json) > self::MAX_CONTENT_BYTES) {
            return ['canonical' => null, 'json' => null, 'hash' => null, 'errors' => [['key' => 'content', 'code' => 'too_large']], 'usesPaymentLink' => false];
        }

        return ['canonical' => $canonico, 'json' => $json, 'hash' => hash('sha256', $json), 'errors' => [], 'usesPaymentLink' => self::usesPaymentLink($canonico)];
    }
}
