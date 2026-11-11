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
 * EL CONTENIDO AMPLIADO DE WHATSAPP (plantillas de Meta desde el CRM;
 * PLANTILLAS_META_PLAN §5.1–§5.3, enmienda de D9-6 en L9-96): cuatro claves
 * OPCIONALES más, `header` (solo TEXT, ≤ 60, una línea, sin marcadores ni
 * Markdown), `footer` (≤ 60, una línea, sin marcadores), `buttons` (1–3:
 * respuestas rápidas —una puede ser LA BAJA, con una etiqueta de
 * {@see OPT_OUT_LABELS}—, URL estática hacia un host de la lista del servidor
 * y «Llamar» al número del gimnasio de la configuración) y `examples` (un
 * valor ficticio por marcador, lo que Meta pide para revisar). Sin ellas, el
 * contenido es el de siempre, con los mismos bytes canónicos y la misma huella.
 * Las reglas solo corren al CREAR ({@see content()}): las versiones guardadas
 * no cambian. De ese contenido salen, PURAS y deterministas, la petición a
 * Meta ({@see metaPayload()}), la huella de los componentes que compara el
 * canal ({@see componentsFingerprint()}, la misma normalización que
 * {@see metaComponentsFingerprint()} sobre lo que devuelve Meta) y la vista
 * previa estructurada ({@see waPreview()}). Lo que dice Meta lo registra
 * `StudioTemplateMetaChecks`; este fichero no sale a la red.
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

    // ── El contenido ampliado de WhatsApp (plantillas de Meta; PLANTILLAS_META_PLAN §5.1) ──

    public const HEADER_FORMAT_TEXT = 'TEXT';

    /** Fase 1: solo encabezado de TEXTO (los medios piden parámetros al enviar: fase 2, D-PM-6). */
    public const HEADER_FORMATS = [self::HEADER_FORMAT_TEXT];

    public const HEADER_MAX = 60;

    public const FOOTER_MAX = 60;

    public const BUTTON_TEXT_MAX = 25;

    /** Hasta 3: así se ven todos, también en WhatsApp de escritorio (Meta oculta 4 o más). */
    public const BUTTONS_MAX = 3;

    /** Meta: dos botones de URL y uno de teléfono como mucho. */
    public const URL_BUTTONS_MAX = 2;

    public const PHONE_BUTTONS_MAX = 1;

    /** Meta admite 2 000; 200 basta para una URL propia y hace caber el peor caso en 8 KB (R11). */
    public const URL_MAX = 200;

    /** Un ejemplo de marcador: 1–40 (los límites que hacen caber el peor caso en 8 KB, R11). */
    public const EXAMPLE_MAX = 40;

    public const BUTTON_QUICK_REPLY = 'QUICK_REPLY';

    public const BUTTON_URL = 'URL';

    public const BUTTON_PHONE = 'PHONE_NUMBER';

    public const BUTTON_TYPES = [self::BUTTON_PHONE, self::BUTTON_QUICK_REPLY, self::BUTTON_URL];

    /** Una respuesta rápida que es LA BAJA (Ley 2300): su etiqueta sale de {@see OPT_OUT_LABELS}. */
    public const ROLE_OPT_OUT = 'opt_out';

    /**
     * Las etiquetas CERRADAS de una respuesta rápida de baja. Tocarla llega
     * como un mensaje cuyo texto es la etiqueta, y el normalizador de W9 solo
     * reconoce frases enteras de su lista: cada una, normalizada, es una frase
     * de `WhatsappSignals::OPT_OUT_PHRASES` (una prueba lo fija).
     */
    public const OPT_OUT_LABELS = ['Darme de baja', 'No más mensajes', 'Detener promociones'];

    /**
     * Los ejemplos FICTICIOS por defecto de cada marcador (lo que Meta pide
     * para revisar una plantilla con variables): el catálogo los da y el CRM
     * los rellena. Nunca datos de un socio; pasan las mismas reglas que un
     * ejemplo escrito a mano (R-01: el precio no lleva cifras; R-03: el enlace
     * no es una URL).
     */
    public const META_EXAMPLES = [
        'class_name' => 'Spinning',
        'class_time_text' => 'mañana a las 6:00 p. m.',
        'days_to_expiry' => '5',
        'first_name' => 'Laura',
        'gym_name' => 'Iron Body',
        'membership_end_date_text' => '15 de diciembre',
        'payment_link' => 'el enlace de pago',
        'plan_name' => 'Plan Trimestral',
        'plan_price' => 'el valor de tu plan',
    ];

    /** La categoría en Meta de cada categoría del Studio (AUTHENTICATION no existe aquí: D-PM-7). */
    public const META_CATEGORIES = [self::CATEGORY_MARKETING => 'MARKETING', self::CATEGORY_UTILITY => 'UTILITY'];

    public const META_PARAMETER_FORMAT = 'POSITIONAL';

    /** De dónde sale el número del botón «Llamar» (Q2 de L9-96): el teléfono publicado del gimnasio. */
    public const GYM_PHONE_CONFIG = 'legal.privacy_phone';

    /** Avisos de Meta (no errores) de la vista previa: el CRM los enseña; el servidor no bloquea por ellos. */
    public const WARNING_VARIABLE_DENSITY = 'meta_variable_density';

    public const WARNING_BUTTONS_DESKTOP = 'meta_buttons_hidden_on_desktop';

    public const WARNING_EXAMPLES_MISSING = 'meta_examples_missing';

    /** Más de una variable por cada 4 palabras: Meta puede rechazarla por «demasiadas variables para su longitud». */
    public const WORDS_PER_VARIABLE = 4;

    /** Lo que el encabezado no admite (Meta: «sin caracteres especiales de Markdown»). */
    private const MARKDOWN_CHARACTERS = ['*', '_', '~', '`'];

    /** Lo que un parámetro (y su ejemplo) no admite: `OutreachTemplateMessage::problem()` y la revisión de Meta. */
    private const EXAMPLE_FORBIDDEN_CHARACTERS = ['#', '$', '%', '{', '}'];

    /** Una URL de botón: `https://` y solo caracteres de URI (RFC 3986), sin espacios, comillas, llaves ni `\`. */
    private const URL_PATTERN = '/^https:\/\/[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+$/D';

    /** Un marcador `{{n}}` al principio o al final del cuerpo (Meta lo rechaza). */
    private const DANGLING_PARAMETER = '/^\{\{\d+\}\}|\{\{\d+\}\}$/D';

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

    // ── Meta: del contenido a la plantilla de Meta (puro; PLANTILLAS_META_PLAN §5.2, §5.3) ──

    /**
     * ¿Tiene la plantilla algo más que el cuerpo (encabezado, pie o botones)?
     * Los ejemplos no cuentan: no llegan al socio. Una comprobación MANUAL de
     * Meta solo vale para una plantilla sin componentes (§5.3, §5.6).
     *
     * @param  array<string, mixed>  $content
     */
    public static function hasComponents(array $content): bool
    {
        return is_array($content['header'] ?? null) || is_array($content['footer'] ?? null) || (is_array($content['buttons'] ?? null) && $content['buttons'] !== []);
    }

    /**
     * La petición de creación a Meta (`POST /{WABA}/message_templates`) de un
     * contenido canónico de WhatsApp, PURA y determinista: nombre, idioma,
     * categoría en mayúsculas, parámetros posicionales y los componentes en el
     * orden de Meta (encabezado, cuerpo con sus ejemplos, pie y botones). Sin
     * `allow_category_change` (desde 2025 es el comportamiento por defecto) ni
     * `message_send_ttl_seconds` (fase 2). El CRM nunca manda esto: se
     * reconstruye siempre desde la versión inmutable aprobada.
     *
     * @param  array<string, mixed>  $content
     * @return array{name: string, language: string, category: string, parameter_format: string, components: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException si el contenido no es el de una plantilla de WhatsApp completa (o le faltan los ejemplos de sus variables)
     */
    public static function metaPayload(array $content): array
    {
        $variables = $content['variables'] ?? null;
        if (! is_string($content['metaName'] ?? null) || ! is_string($content['language'] ?? null) || ! isset(self::META_CATEGORIES[$content['category'] ?? ''])
            || ! is_string($content['bodyPreview'] ?? null) || ! is_array($variables) || ! array_is_list($variables)) {
            throw new InvalidArgumentException('El contenido no es el de una plantilla de WhatsApp del Studio.');
        }
        $componentes = [];
        if (is_array($content['header'] ?? null)) {
            $componentes[] = ['type' => 'HEADER', 'format' => (string) $content['header']['format'], 'text' => (string) $content['header']['text']];
        }
        $cuerpo = ['type' => 'BODY', 'text' => $content['bodyPreview']];
        if ($variables !== []) {
            $ejemplos = is_array($content['examples'] ?? null) ? $content['examples'] : [];
            $fila = [];
            foreach ($variables as $marcador) {
                if (! is_string($ejemplos[$marcador] ?? null)) {
                    throw new InvalidArgumentException('meta_examples_required: la plantilla tiene variables y le faltan sus ejemplos.');
                }
                $fila[] = $ejemplos[$marcador];
            }
            $cuerpo['example'] = ['body_text' => [$fila]];
        }
        $componentes[] = $cuerpo;
        if (is_array($content['footer'] ?? null)) {
            $componentes[] = ['type' => 'FOOTER', 'text' => (string) $content['footer']['text']];
        }
        if (is_array($content['buttons'] ?? null) && $content['buttons'] !== []) {
            $componentes[] = ['type' => 'BUTTONS', 'buttons' => array_map(static fn (array $b): array => match ($b['type']) {
                self::BUTTON_URL => ['type' => self::BUTTON_URL, 'text' => (string) $b['text'], 'url' => (string) $b['url']],
                self::BUTTON_PHONE => ['type' => self::BUTTON_PHONE, 'text' => (string) $b['text'], 'phone_number' => (string) $b['phone']],
                default => ['type' => self::BUTTON_QUICK_REPLY, 'text' => (string) $b['text']],
            }, array_values($content['buttons']))];
        }

        return [
            'name' => $content['metaName'],
            'language' => $content['language'],
            'category' => self::META_CATEGORIES[$content['category']],
            'parameter_format' => self::META_PARAMETER_FORMAT,
            'components' => $componentes,
        ];
    }

    /**
     * ¿Le faltan a esta versión los ejemplos que Meta exige (tiene variables y
     * no todos sus ejemplos)? Se comprueba ANTES de enviar: 422
     * `meta_examples_required` (una versión vieja se corrige con otra nueva).
     *
     * @param  array<string, mixed>  $content
     */
    public static function missingMetaExamples(array $content): bool
    {
        $ejemplos = is_array($content['examples'] ?? null) ? $content['examples'] : [];
        foreach ((array) ($content['variables'] ?? []) as $marcador) {
            if (! is_string($ejemplos[$marcador] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * LA HUELLA DE LOS COMPONENTES de un contenido del Studio (§5.3): el
     * encabezado, el pie y los botones en su orden, con la normalización que
     * {@see metaComponentsFingerprint()} aplica a lo que devuelve Meta (tipos
     * en mayúsculas, del teléfono solo los dígitos, los textos tal cual; los
     * ejemplos y la marca de baja no entran: no llegan al socio). Una
     * plantilla de solo cuerpo da siempre la misma huella.
     *
     * @param  array<string, mixed>  $content
     */
    public static function componentsFingerprint(array $content): string
    {
        $encabezado = is_array($content['header'] ?? null)
            ? ['f' => strtoupper((string) ($content['header']['format'] ?? '')), 't' => is_string($content['header']['text'] ?? null) ? $content['header']['text'] : null]
            : null;
        $pie = is_array($content['footer'] ?? null) && is_string($content['footer']['text'] ?? null) ? $content['footer']['text'] : null;
        $botones = [];
        foreach (is_array($content['buttons'] ?? null) ? array_values($content['buttons']) : [] as $b) {
            $botones[] = self::fingerprintButton(is_array($b) ? $b['type'] ?? null : null, is_array($b) ? $b['text'] ?? null : null, is_array($b) ? $b['url'] ?? null : null, is_array($b) ? $b['phone'] ?? null : null);
        }

        return CanonicalJson::hash(['b' => $botones, 'f' => $pie, 'h' => $encabezado]);
    }

    /**
     * La MISMA huella calculada sobre los `components` que devuelve Meta al
     * leer una plantilla. El cuerpo no entra (tiene su propia huella) ni los
     * ejemplos. Lo que este código no conoce (un componente de otro tipo, un
     * segundo encabezado o pie, o las claves que `MetaTemplate` nombra en
     * `extraKeys` de un componente o de un botón) se añade aparte, así que
     * nunca casa con una versión del Studio: falla cerrado.
     *
     * @param  list<mixed>  $components
     */
    public static function metaComponentsFingerprint(array $components): string
    {
        $encabezado = null;
        $pie = null;
        $botones = [];
        $ajenos = [];
        $vistos = [];
        foreach ($components as $c) {
            $tipo = is_array($c) && is_string($c['type'] ?? null) ? strtoupper($c['type']) : '?';
            $repetido = in_array($tipo, ['HEADER', 'FOOTER', 'BUTTONS'], true) && in_array($tipo, $vistos, true);
            $vistos[] = $tipo;
            if ($repetido) {
                $ajenos[] = $tipo;

                continue;
            }
            if (is_array($c) && is_array($c['extraKeys'] ?? null) && $c['extraKeys'] !== []) {
                $ajenos[] = $tipo.'+'.implode('+', array_map('strval', $c['extraKeys']));
            }
            match ($tipo) {
                'HEADER' => $encabezado = ['f' => is_string($c['format'] ?? null) ? strtoupper($c['format']) : '', 't' => is_string($c['text'] ?? null) ? $c['text'] : null],
                'FOOTER' => $pie = is_string($c['text'] ?? null) ? $c['text'] : null,
                'BODY' => null,
                'BUTTONS' => $botones = array_map(static fn (mixed $b): array => is_array($b)
                    ? self::fingerprintButton($b['type'] ?? null, $b['text'] ?? null, $b['url'] ?? null, $b['phone_number'] ?? null)
                        + (is_array($b['extraKeys'] ?? null) && $b['extraKeys'] !== [] ? ['k' => array_map('strval', $b['extraKeys'])] : [])
                    : ['p' => null, 't' => '?', 'u' => null, 'x' => null], is_array($c['buttons'] ?? null) ? array_values($c['buttons']) : []),
                default => $ajenos[] = $tipo,
            };
        }
        $huella = ['b' => $botones, 'f' => $pie, 'h' => $encabezado];
        if ($ajenos !== []) {
            $huella['x'] = $ajenos;
        }

        return CanonicalJson::hash($huella);
    }

    /**
     * El texto del cuerpo que devuelve Meta (el del ÚNICO componente BODY);
     * null si no hay uno solo con texto.
     *
     * @param  list<mixed>  $components
     */
    public static function metaBodyText(array $components): ?string
    {
        $cuerpos = array_values(array_filter($components, static fn (mixed $c): bool => is_array($c) && is_string($c['type'] ?? null) && strtoupper($c['type']) === 'BODY'));

        return count($cuerpos) === 1 && is_string($cuerpos[0]['text'] ?? null) ? $cuerpos[0]['text'] : null;
    }

    /** @return array{p: string|null, t: string, u: string|null, x: string|null} */
    private static function fingerprintButton(mixed $tipo, mixed $texto, mixed $url, mixed $telefono): array
    {
        $digitos = is_string($telefono) ? preg_replace('/\D+/', '', $telefono) : null;

        return [
            'p' => is_string($digitos) && $digitos !== '' ? $digitos : null,
            't' => is_string($tipo) ? strtoupper($tipo) : '?',
            'u' => is_string($url) ? $url : null,
            'x' => is_string($texto) ? $texto : null,
        ];
    }

    /**
     * LA VISTA PREVIA ESTRUCTURADA de una plantilla de WhatsApp (el componente
     * `ca-wa-preview` del CRM la pinta como la verá el socio): el encabezado,
     * el cuerpo CON sus marcadores `{{n}}` (el CRM los sustituye por los
     * ejemplos o los enseña como fichas), el pie, los botones y los ejemplos
     * (los de la versión; si no los tiene, los ficticios del catálogo y el
     * aviso `meta_examples_missing`), con los avisos de Meta que no bloquean.
     * Sin datos de nadie. `preview` (la cadena de siempre) no cambia.
     *
     * @param  array<string, mixed>  $content
     * @return array{header: string|null, body: string, footer: string|null, buttons: list<array<string, string>>, variables: list<string>, examples: array<string, string>, metaWarnings: list<string>}
     */
    public static function waPreview(array $content): array
    {
        $variables = array_values(array_filter((array) ($content['variables'] ?? []), 'is_string'));
        $propios = is_array($content['examples'] ?? null) ? $content['examples'] : [];
        $ejemplos = [];
        foreach ($variables as $marcador) {
            $ejemplos[$marcador] = is_string($propios[$marcador] ?? null) ? $propios[$marcador] : (self::META_EXAMPLES[$marcador] ?? $marcador);
        }
        $botones = [];
        foreach (is_array($content['buttons'] ?? null) ? array_values($content['buttons']) : [] as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null) || ! is_string($b['text'] ?? null)) {
                continue;
            }
            $boton = ['type' => $b['type'], 'text' => $b['text']];
            foreach (['role', 'url', 'phone'] as $k) {
                if (is_string($b[$k] ?? null)) {
                    $boton[$k] = $b[$k];
                }
            }
            $botones[] = $boton;
        }

        return [
            'header' => is_array($content['header'] ?? null) && is_string($content['header']['text'] ?? null) ? $content['header']['text'] : null,
            'body' => is_string($content['bodyPreview'] ?? null) ? $content['bodyPreview'] : '',
            'footer' => is_array($content['footer'] ?? null) && is_string($content['footer']['text'] ?? null) ? $content['footer']['text'] : null,
            'buttons' => $botones,
            'variables' => $variables,
            'examples' => $ejemplos,
            'metaWarnings' => self::metaWarnings($content),
        ];
    }

    /**
     * Los avisos de Meta que NO bloquean (los enseña el CRM): demasiadas
     * variables para la longitud del texto (más de una por cada
     * {@see WORDS_PER_VARIABLE} palabras), botones que WhatsApp de escritorio
     * no enseña (respuestas rápidas mezcladas con otros tipos) y ejemplos que
     * faltan (sin ellos no se puede enviar a Meta).
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function metaWarnings(array $content): array
    {
        $avisos = [];
        $cuerpo = is_string($content['bodyPreview'] ?? null) ? $content['bodyPreview'] : '';
        $marcadores = (int) preg_match_all('/\{\{\d+\}\}/u', $cuerpo);
        // Palabras de verdad: con alguna letra o cifra (los marcadores y la puntuación suelta no cuentan).
        $trozos = preg_split('/\s+/u', (string) preg_replace('/\{\{\d+\}\}/u', ' ', $cuerpo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $palabras = count(array_filter($trozos, static fn (string $t): bool => preg_match('/[\p{L}\p{N}]/u', $t) === 1));
        if ($marcadores > 0 && $marcadores * self::WORDS_PER_VARIABLE > $palabras) {
            $avisos[] = self::WARNING_VARIABLE_DENSITY;
        }
        $tipos = array_values(array_unique(array_map(static fn (mixed $b): string => is_array($b) ? (string) ($b['type'] ?? '') : '', is_array($content['buttons'] ?? null) ? $content['buttons'] : [])));
        if (in_array(self::BUTTON_QUICK_REPLY, $tipos, true) && count($tipos) > 1) {
            $avisos[] = self::WARNING_BUTTONS_DESKTOP;
        }
        if (self::missingMetaExamples($content)) {
            $avisos[] = self::WARNING_EXAMPLES_MISSING;
        }

        return $avisos;
    }

    /**
     * `templates.meta` del catálogo (`GET strategies/catalog`, aditivo): los
     * límites del contenido ampliado, qué botones admite el servidor (Q1, Q2),
     * las etiquetas de baja y los ejemplos ficticios por defecto. El número
     * del gimnasio es el publicado (`legal.privacy_phone`), no un secreto.
     *
     * @return array<string, mixed>
     */
    public static function metaCatalog(): array
    {
        return [
            'headerFormats' => self::HEADER_FORMATS,
            'headerMax' => self::HEADER_MAX,
            'footerMax' => self::FOOTER_MAX,
            'buttonTextMax' => self::BUTTON_TEXT_MAX,
            'buttonsMax' => self::BUTTONS_MAX,
            'urlButtonsMax' => self::URL_BUTTONS_MAX,
            'urlMax' => self::URL_MAX,
            'exampleMax' => self::EXAMPLE_MAX,
            'buttonTypes' => self::BUTTON_TYPES,
            'urlButtons' => self::urlHosts() !== [],
            'urlHosts' => self::urlHosts(),
            'phoneButton' => self::gymPhone() !== null,
            'phoneNumber' => self::gymPhone(),
            'optOutLabels' => self::OPT_OUT_LABELS,
            'examples' => self::META_EXAMPLES,
            'wordsPerVariable' => self::WORDS_PER_VARIABLE,
        ];
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

    /**
     * `GET strategies/templates` (≤ 50), de la más reciente a la más antigua; sin el texto.
     * Aditivo (plantillas de Meta, §5.8.2): `meta` `{status, state, campaignReady}` en
     * las de WhatsApp (null en las demás), en tres consultas para toda la página.
     */
    public static function page(?string $key, ?string $channel, ?string $status, int $perPage): LengthAwarePaginator
    {
        $pagina = StudioMessageTemplate::query()
            ->when($key, fn ($q, string $k) => $q->where('key', $k))
            ->when($channel, fn ($q, string $c) => $q->where('channel', $c))
            ->when($status, fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min($perPage, self::PER_PAGE_MAX));
        $meta = StudioMetaTemplates::summaries($pagina->getCollection());

        return $pagina->through(static fn (StudioMessageTemplate $t): array => self::summaryDto($t) + ['meta' => $meta[(int) $t->id] ?? null]);
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
            // Aditivo (plantillas de Meta, §5.8.2): la vista previa estructurada y el bloque de Meta; solo WhatsApp.
            'waPreview' => (string) $t->channel === self::CHANNEL_WHATSAPP ? self::waPreview($contenido) : null,
            'meta' => (string) $t->channel === self::CHANNEL_WHATSAPP ? app(StudioMetaTemplates::class)->detailBlock($t, $access) : null,
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
        // El contenido ampliado (plantillas de Meta).
        'leading_trailing_spaces' => 'Sin espacios ni saltos al principio o al final (Meta los quita y la plantilla dejaría de casar).',
        'meta_dangling_parameter' => 'El texto no puede empezar ni terminar con un marcador {{n}} (regla de Meta).',
        'variables_not_allowed' => 'Aquí no se admiten marcadores {{n}}: solo en el texto del cuerpo.',
        'markdown_not_allowed' => 'El encabezado no admite formato de WhatsApp (*, _, ~ ni `).',
        'example_forbidden_character' => 'Un ejemplo no admite #, $, %, { ni } (regla de Meta para los parámetros).',
        'too_many_spaces' => 'Un ejemplo no admite cuatro espacios seguidos (regla de Meta para los parámetros).',
        'meta_button_grouping' => 'Las respuestas rápidas van juntas: todas antes o todas después de los demás botones (regla de Meta).',
        'opt_out_label_not_allowed' => 'El botón de baja solo admite una de las etiquetas de la lista (Darme de baja, No más mensajes, Detener promociones).',
        'button_type_disabled' => 'Ese tipo de botón no está habilitado en el servidor.',
        'url_host_not_allowed' => 'Ese dominio no está en la lista del servidor para los botones de URL.',
        'phone_not_allowed' => 'El botón «Llamar» usa el número del gimnasio de la configuración: no se puede escribir otro.',
        'phone_not_configured' => 'No hay un número del gimnasio válido en la configuración para el botón «Llamar».',
    ];

    /**
     * @param  array<string,mixed>  $raw
     * @param  callable(string,string):void  $falla
     * @return array<string,mixed>|null
     */
    private static function whatsapp(array $raw, string $purpose, callable $falla): ?array
    {
        // Lo ampliado es OPCIONAL: ausente, `null` o vacío (`[]`/`{}`) es lo mismo y no se guarda (los bytes de siempre).
        foreach (['buttons', 'examples', 'footer', 'header'] as $opcional) {
            if (array_key_exists($opcional, $raw) && ($raw[$opcional] === null || $raw[$opcional] === [])) {
                unset($raw[$opcional]);
            }
        }
        $bien = self::keys($raw, ['bodyPreview', 'category', 'language', 'metaName', 'optOutLine', 'variables'], ['buttons', 'examples', 'footer', 'header'], $falla);
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
                } elseif (trim($texto) !== $texto) {
                    // Meta guarda el texto sin los blancos de los bordes: la huella del cuerpo dejaría de casar (V-1).
                    $bien = self::failFalse($falla, 'content.bodyPreview', 'leading_trailing_spaces');
                } elseif (preg_match(self::DANGLING_PARAMETER, $texto) === 1) {
                    // Regla de Meta para las versiones NUEVAS: ni empieza ni termina con un marcador (las guardadas no cambian).
                    $bien = self::failFalse($falla, 'content.bodyPreview', 'meta_dangling_parameter');
                } else {
                    $out['bodyPreview'] = $texto;
                }
            }
        }
        if (array_key_exists('header', $raw)) {
            $encabezado = self::metaHeader($raw['header'], $falla);
            $encabezado === null ? $bien = false : $out['header'] = $encabezado;
        }
        if (array_key_exists('footer', $raw)) {
            $pie = self::metaFooter($raw['footer'], $falla);
            $pie === null ? $bien = false : $out['footer'] = $pie;
        }
        if (array_key_exists('buttons', $raw)) {
            $botones = self::metaButtons($raw['buttons'], $falla);
            $botones === null ? $bien = false : $out['buttons'] = $botones;
        }
        if (array_key_exists('examples', $raw)) {
            $ejemplos = self::metaExamples($raw['examples'], $variables, $falla);
            $ejemplos === null ? $bien = false : $out['examples'] = $ejemplos;
        }

        return $bien ? $out : null;
    }

    // ── Piezas del contenido ampliado (puras; lo único que leen es la configuración de Q1 y Q2) ──

    /**
     * @param  callable(string,string):void  $falla
     * @return array{format: string, text: string}|null
     */
    private static function metaHeader(mixed $v, callable $falla): ?array
    {
        if (! is_array($v) || array_is_list($v)) {
            $falla('content.header', 'not_an_object');

            return null;
        }
        $bien = self::keys($v, ['format', 'text'], [], $falla, 'content.header');
        if (array_key_exists('format', $v) && ! in_array($v['format'], self::HEADER_FORMATS, true)) {
            $bien = self::failFalse($falla, 'content.header.format', 'value_not_in_domain');
        }
        $texto = array_key_exists('text', $v) ? self::metaLine($v['text'], 'content.header.text', self::HEADER_MAX, $falla, markdown: false) : null;

        return $bien && $texto !== null ? ['format' => self::HEADER_FORMAT_TEXT, 'text' => $texto] : null;
    }

    /**
     * @param  callable(string,string):void  $falla
     * @return array{text: string}|null
     */
    private static function metaFooter(mixed $v, callable $falla): ?array
    {
        if (! is_array($v) || array_is_list($v)) {
            $falla('content.footer', 'not_an_object');

            return null;
        }
        $bien = self::keys($v, ['text'], [], $falla, 'content.footer');
        $texto = array_key_exists('text', $v) ? self::metaLine($v['text'], 'content.footer.text', self::FOOTER_MAX, $falla) : null;

        return $bien && $texto !== null ? ['text' => $texto] : null;
    }

    /**
     * Los botones, en su orden: 1–3, agrupados como pide Meta (las respuestas
     * rápidas, todas antes o todas después de los demás), sin etiquetas
     * repetidas, con como mucho una baja, dos URL y un teléfono.
     *
     * @param  callable(string,string):void  $falla
     * @return list<array<string, string>>|null
     */
    private static function metaButtons(mixed $v, callable $falla): ?array
    {
        if (! is_array($v) || ! array_is_list($v)) {
            $falla('content.buttons', 'invalid_type');

            return null;
        }
        if (count($v) > self::BUTTONS_MAX) {
            $falla('content.buttons', 'too_many_values');

            return null;
        }
        $bien = true;
        $out = [];
        $tipos = [];
        $textos = [];
        $bajas = 0;
        foreach ($v as $i => $boton) {
            $clave = "content.buttons.{$i}";
            if (! is_array($boton) || array_is_list($boton)) {
                $bien = self::failFalse($falla, $clave, 'not_an_object');

                continue;
            }
            $tipo = $boton['type'] ?? null;
            if (! is_string($tipo) || ! in_array($tipo, self::BUTTON_TYPES, true)) {
                $bien = self::failFalse($falla, "{$clave}.type", array_key_exists('type', $boton) ? 'value_not_in_domain' : 'missing_key');

                continue;
            }
            $tipos[] = $tipo;
            // Q1 y Q2: la URL, solo con hosts en la lista del servidor; la llamada, solo con su bandera.
            if (($tipo === self::BUTTON_URL && self::urlHosts() === []) || ($tipo === self::BUTTON_PHONE && ! self::phoneButtonEnabled())) {
                $bien = self::failFalse($falla, "{$clave}.type", 'button_type_disabled');

                continue;
            }
            [$obligatorias, $opcionales] = match ($tipo) {
                self::BUTTON_QUICK_REPLY => [['text', 'type'], ['role']],
                self::BUTTON_URL => [['text', 'type', 'url'], []],
                default => [['text', 'type'], ['phone']],
            };
            $forma = self::keys($boton, $obligatorias, $opcionales, $falla, $clave);
            $texto = array_key_exists('text', $boton) ? self::metaLine($boton['text'], "{$clave}.text", self::BUTTON_TEXT_MAX, $falla) : null;
            if (! $forma || $texto === null) {
                $bien = false;

                continue;
            }
            if (in_array($texto, $textos, true)) {
                $bien = self::failFalse($falla, "{$clave}.text", 'duplicate_value');

                continue;
            }
            $textos[] = $texto;
            $canonico = ['text' => $texto, 'type' => $tipo];
            if ($tipo === self::BUTTON_QUICK_REPLY && array_key_exists('role', $boton)) {
                if ($boton['role'] !== self::ROLE_OPT_OUT) {
                    $bien = self::failFalse($falla, "{$clave}.role", 'value_not_in_domain');

                    continue;
                }
                if (! in_array($texto, self::OPT_OUT_LABELS, true)) {
                    $bien = self::failFalse($falla, "{$clave}.text", 'opt_out_label_not_allowed');

                    continue;
                }
                $bajas++;
                $canonico['role'] = self::ROLE_OPT_OUT;
            }
            if ($tipo === self::BUTTON_URL) {
                $url = self::metaUrl($boton['url'], "{$clave}.url", $falla);
                if ($url === null) {
                    $bien = false;

                    continue;
                }
                $canonico['url'] = $url;
            }
            if ($tipo === self::BUTTON_PHONE) {
                $telefono = self::metaPhone($boton, $clave, $falla);
                if ($telefono === null) {
                    $bien = false;

                    continue;
                }
                $canonico['phone'] = $telefono;
            }
            $out[] = $canonico;
        }
        $cuenta = array_count_values($tipos);
        if (($cuenta[self::BUTTON_URL] ?? 0) > self::URL_BUTTONS_MAX || ($cuenta[self::BUTTON_PHONE] ?? 0) > self::PHONE_BUTTONS_MAX || $bajas > 1) {
            $bien = self::failFalse($falla, 'content.buttons', 'too_many_values');
        }
        if (! self::quickRepliesGrouped($tipos)) {
            $bien = self::failFalse($falla, 'content.buttons', 'meta_button_grouping');
        }

        return $bien ? $out : null;
    }

    /** Meta: las respuestas rápidas van juntas, todas antes o todas después de los demás botones. @param  list<string>  $tipos */
    private static function quickRepliesGrouped(array $tipos): bool
    {
        $grupos = 0;
        $anterior = null;
        foreach ($tipos as $tipo) {
            $esRapida = $tipo === self::BUTTON_QUICK_REPLY;
            if ($esRapida !== $anterior) {
                $grupos++;
                $anterior = $esRapida;
            }
        }

        return $grupos <= 2;
    }

    /**
     * Una URL de botón ESTÁTICA (Q1): `https://`, ≤ {@see URL_MAX}, solo
     * caracteres de URI, sin marcadores, sin usuario, contraseña ni puerto, y
     * con el host EXACTO en la lista del servidor (vacía: ningún botón de URL).
     *
     * @param  callable(string,string):void  $falla
     */
    private static function metaUrl(mixed $v, string $clave, callable $falla): ?string
    {
        if (! is_string($v) || $v === '') {
            $falla($clave, is_string($v) ? 'missing_key' : 'invalid_type');

            return null;
        }
        if (strlen($v) > self::URL_MAX) {
            $falla($clave, 'too_long');

            return null;
        }
        $partes = preg_match(self::URL_PATTERN, $v) === 1 ? parse_url($v) : false;
        if (! is_array($partes) || ($partes['scheme'] ?? null) !== 'https' || ! is_string($partes['host'] ?? null) || $partes['host'] === ''
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['port'])) {
            $falla($clave, 'invalid_format');

            return null;
        }
        if (! in_array(strtolower($partes['host']), self::urlHosts(), true)) {
            $falla($clave, 'url_host_not_allowed');

            return null;
        }

        return $v;
    }

    /**
     * El número del botón «Llamar» (Q2): SIEMPRE el del gimnasio de la
     * configuración. Si llega uno, tiene que ser ese mismo (se comparan los
     * dígitos); nunca se guarda uno tecleado.
     *
     * @param  array<string, mixed>  $boton
     * @param  callable(string,string):void  $falla
     */
    private static function metaPhone(array $boton, string $clave, callable $falla): ?string
    {
        $gimnasio = self::gymPhone();
        if ($gimnasio === null) {
            $falla("{$clave}.type", 'phone_not_configured');

            return null;
        }
        if (array_key_exists('phone', $boton) && (! is_string($boton['phone']) || preg_replace('/\D+/', '', $boton['phone']) !== $gimnasio)) {
            $falla("{$clave}.phone", 'phone_not_allowed');

            return null;
        }

        return $gimnasio;
    }

    /**
     * Los ejemplos: EXACTAMENTE los marcadores de `variables`, cada uno con un
     * valor ficticio de una línea, ≤ {@see EXAMPLE_MAX}, sin `#`, `$`, `%`,
     * llaves ni cuatro espacios seguidos, sin datos de contacto y sin incumplir
     * R-01/R-02/R-03/R-06.
     *
     * @param  list<string>  $variables  los marcadores ya validados
     * @param  callable(string,string):void  $falla
     * @return array<string, string>|null
     */
    private static function metaExamples(mixed $v, array $variables, callable $falla): ?array
    {
        if (! is_array($v) || array_is_list($v)) {
            $falla('content.examples', 'invalid_type');

            return null;
        }
        $bien = true;
        $out = [];
        foreach ($v as $marcador => $valor) {
            $marcador = (string) $marcador;
            $clave = 'content.examples.'.(preg_match('/^[A-Za-z0-9_]{1,64}$/D', $marcador) === 1 ? $marcador : '_invalid_key');
            if (! in_array($marcador, $variables, true)) {
                $bien = self::failFalse($falla, $clave, 'unexpected_key');

                continue;
            }
            $texto = self::metaLine($valor, $clave, self::EXAMPLE_MAX, $falla, example: true);
            $texto === null ? $bien = false : $out[$marcador] = $texto;
        }
        foreach ($variables as $marcador) {
            if (! array_key_exists($marcador, $v)) {
                $bien = self::failFalse($falla, "content.examples.{$marcador}", 'missing_key');
            }
        }

        return $bien ? $out : null;
    }

    /**
     * Un texto de UNA línea del contenido ampliado (encabezado, pie, botón o
     * ejemplo): cadena, ≤ `$max`, `PlainText` estricto sin saltos, sin blancos
     * en los bordes, sin marcadores `{{`, sin datos de contacto y sin incumplir
     * R-01/R-02/R-03/R-06. El encabezado, además, sin Markdown; un ejemplo,
     * sin `#`, `$`, `%`, llaves ni cuatro espacios seguidos.
     *
     * @param  callable(string,string):void  $falla
     */
    private static function metaLine(mixed $v, string $clave, int $max, callable $falla, bool $markdown = true, bool $example = false): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            $falla($clave, is_string($v) ? 'missing_key' : 'invalid_type');

            return null;
        }
        if (mb_strlen($v) > $max) {
            $falla($clave, 'too_long');

            return null;
        }
        if (Validator::make(['t' => $v], ['t' => [new PlainText(multiline: false, strict: true)]])->fails()) {
            $falla($clave, 'plain_text');

            return null;
        }
        $codigo = match (true) {
            trim($v) !== $v => 'leading_trailing_spaces',
            $example && str_replace(self::EXAMPLE_FORBIDDEN_CHARACTERS, '', $v) !== $v => 'example_forbidden_character',
            $example && str_contains($v, '    ') => 'too_many_spaces',
            str_contains($v, '{{') => 'variables_not_allowed',
            ! $markdown && str_replace(self::MARKDOWN_CHARACTERS, '', $v) !== $v => 'markdown_not_allowed',
            StudioCanaryPhone::contactDataError($v) !== null => 'contact_data',
            self::restrictionViolations($v) !== [] => 'restriction_violated',
            default => null,
        };
        if ($codigo !== null) {
            $falla($clave, $codigo);

            return null;
        }

        return $v;
    }

    /** Los hosts de los botones de URL (Q1): la lista del servidor, en minúsculas; vacía, ninguno. @return list<string> */
    public static function urlHosts(): array
    {
        $hosts = config('ultron_studio.meta_templates.url_hosts', []);

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }

    public static function phoneButtonEnabled(): bool
    {
        return (bool) config('ultron_studio.meta_templates.phone_button', false);
    }

    /**
     * El número del gimnasio para el botón «Llamar» (Q2): los dígitos (con el
     * prefijo de país, sin `+`, como lo pide Meta) del teléfono publicado en la
     * configuración; null si el botón está apagado o el número no tiene la
     * forma de un E.164 (8–15 dígitos).
     */
    public static function gymPhone(): ?string
    {
        if (! self::phoneButtonEnabled()) {
            return null;
        }
        $digitos = preg_replace('/\D+/', '', (string) config(self::GYM_PHONE_CONFIG, ''));

        return is_string($digitos) && preg_match('/^[1-9][0-9]{7,14}$/D', $digitos) === 1 ? $digitos : null;
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
    private static function keys(array $v, array $obligatorias, array $opcionales, callable $falla, string $prefijo = 'content'): bool
    {
        $bien = true;
        foreach (array_keys($v) as $k) {
            if (! in_array((string) $k, [...$obligatorias, ...$opcionales], true)) {
                $bien = self::failFalse($falla, $prefijo.'.'.(preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $k) === 1 ? $k : '_invalid_key'), 'unexpected_key');
            }
        }
        foreach ($obligatorias as $k) {
            if (! array_key_exists($k, $v)) {
                $bien = self::failFalse($falla, "{$prefijo}.{$k}", 'missing_key');
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
