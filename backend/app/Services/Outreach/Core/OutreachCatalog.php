<?php

namespace App\Services\Outreach\Core;

use DateTimeInterface;

/**
 * El CATÁLOGO del H9 (contrato H9, D9-3 y D9-4; fixture
 * `catalog-snapshot.json`, que lo fija): el conjunto cerrado y versionado de
 * EVENTOS (lo que abre la posibilidad de inscribir a una persona) y de HECHOS
 * tipados (lo que se puede preguntar de ella), con sus operadores, unidades,
 * canales, finalidades y límites. PURO: sin base, red, configuración, log ni
 * reloj.
 *
 * ES LA AUTORIDAD de lo que la interfaz puede COMPONER (`GET
 * strategies/catalog`, §7 #1): {@see export()} devuelve EXACTAMENTE el
 * `catalog` del fixture (mismas claves, mismos valores, listas en el mismo
 * orden) y {@see hash()} es el sha256 de su JSON canónico. Un evento o un hecho
 * nuevo exige un adaptador, una entrada aquí, su fixture y subir
 * {@see VERSION} (un commit revisado, nunca la interfaz). Los cambios son
 * ADITIVOS: retirar un hecho exige una versión mayor y deja las estrategias que
 * lo usan en `catalog_incompatible` ({@see compatibility()}).
 *
 * LO QUE NO ES: no es `StudioCatalog` (congelado en `2026-10-19.1`, protección
 * 15) ni lo lee el motor de ULTRON. Los dominios que vienen del motor
 * comercial (objetivos, eventos, fases del lead, consentimiento) están COPIADOS
 * como literales para que el catálogo se describa entero aquí y su huella
 * cambie solo aquí; `OutreachCatalogTest` los ata a sus fuentes.
 *
 * EL VALOR NULO ES «DESCONOCIDO» (D9-4): nunca 0 ni `false`; `nba.open_goal`
 * sin objetivo abierto es null (DF-T7).
 *
 * LOS TIPOS (D9-4, DF-T1, DF-T2, DF-T4):
 *  - `bool`, `enum` (dominio cerrado o dinámico: `plans`, `class_types`);
 *  - `int`: recuentos y días CON SIGNO (`membership.days_to_expiry`);
 *  - `duration`: «días/horas desde lo último» (≥ 0), mismos operadores que `int`;
 *  - `decimal`: un entero en CENTÉSIMAS (`scale` 2: 2,50 → 250);
 *  - `date_relative`: un desplazamiento con signo respecto a «ahora» (futuro >
 *    0) en la unidad del hecho, con los operadores de fecha.
 */
final class OutreachCatalog
{
    /**
     * `2026-11-11.1` (I-5 de la auditoría de cierre B): los eventos sin emisor
     * ({@see EVENTS_WITHOUT_EMITTER}) dejan de estar disponibles como
     * disparador. Antes, `2026-11-10.2` (L9-29): la clave de ciclo de las clases
     * es `cls:{class_id}:{session_date}` (keyVersion 2).
     */
    public const VERSION = 'outreach-catalog/2026-11-11.1';

    /** La forma de {@see export()}. */
    public const EXPORT_CONTRACT = 'outreach-catalog-export/1';

    public const GRAMMAR = 'outreach-conditions/1';

    // ── Tipos y operadores (D9-4, D9-5) ─────────────────────────────────────

    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_ENUM = 'enum';

    public const TYPE_DURATION = 'duration';

    public const TYPE_DATE_RELATIVE = 'date_relative';

    public const TYPES = [self::TYPE_BOOL, self::TYPE_DATE_RELATIVE, self::TYPE_DECIMAL, self::TYPE_DURATION, self::TYPE_ENUM, self::TYPE_INT];

    /** Los numéricos (`int`, `decimal`, `duration` y los recuentos). */
    public const NUMERIC_OPERATORS = ['between', 'eq', 'gt', 'gte', 'lt', 'lte', 'neq'];

    /** Operadores por tipo y por nodo, ordenados por bytes (los que no aparecen son 422). */
    public const OPERATORS = [
        'any' => ['is_known', 'is_unknown'],
        'bool' => ['eq'],
        'count' => self::NUMERIC_OPERATORS,
        'date_relative' => ['between_days', 'more_than_ago', 'within_last', 'within_next'],
        'decimal' => self::NUMERIC_OPERATORS,
        'duration' => self::NUMERIC_OPERATORS,
        'enum' => ['eq', 'in', 'neq', 'not_in'],
        'event' => ['absent', 'exists'],
        'int' => self::NUMERIC_OPERATORS,
    ];

    /** Todos los operadores de la gramática: uno que no esté aquí es `unknown_operator`. */
    public const ALL_OPERATORS = [
        'absent', 'between', 'between_days', 'eq', 'exists', 'gt', 'gte', 'in', 'is_known', 'is_unknown', 'lt', 'lte',
        'more_than_ago', 'neq', 'not_in', 'within_last', 'within_next',
    ];

    // ── Límites de la gramática (D9-5) ──────────────────────────────────────

    public const MAX_DEPTH = 5;

    public const MAX_NODES = 50;

    public const MAX_BYTES = 8192;

    public const MAX_WINDOW_DAYS = 730;

    public const MAX_COUNT = 100;

    public const MAX_ENUM_VALUES = 50;

    /** El mayor desplazamiento de `between_days` (días con signo). */
    public const MAX_BETWEEN_DAYS = 730;

    // ── Unidades ────────────────────────────────────────────────────────────

    public const UNITS = ['days', 'hours', 'weeks'];

    public const UNIT_DAYS = ['days' => 1, 'weeks' => 7];

    public const UNIT_HOURS = ['days' => 24, 'hours' => 1, 'weeks' => 168];

    // ── Finalidades, medios y canales (D9-6, D9-9, D9-15) ───────────────────

    public const PURPOSE_COMMERCIAL = 'commercial';

    public const PURPOSE_COLLECTIONS = 'collections';

    public const PURPOSE_SERVICE = 'service';

    public const PURPOSES = [self::PURPOSE_COLLECTIONS, self::PURPOSE_COMMERCIAL, self::PURPOSE_SERVICE];

    /** D9-8 etapa 3 [P9-7]: `service` > `collections` > `commercial`. */
    public const PURPOSE_RANK = [self::PURPOSE_SERVICE => 3, self::PURPOSE_COLLECTIONS => 2, self::PURPOSE_COMMERCIAL => 1];

    public const MEDIUM_WHATSAPP = 'whatsapp';

    public const MEDIUM_VOICE = 'voice';

    /** El medio por el que llega un contacto a la persona (lo que cuentan la frecuencia y la regla de un canal). */
    public const MEDIUMS = [self::MEDIUM_VOICE, self::MEDIUM_WHATSAPP];

    public const CHANNEL_WHATSAPP_TEMPLATE = 'whatsapp_template';

    public const CHANNEL_VOICE_CALL = 'voice_call';

    public const CHANNEL_ASSISTED_WHATSAPP = 'assisted:whatsapp';

    public const CHANNEL_ASSISTED_VOICE = 'assisted:voice';

    /** Los canales que puede declarar un paso (D9-6): clave → canal del trabajo y medio. */
    public const STEP_CHANNELS = [
        self::CHANNEL_ASSISTED_VOICE => ['channel' => 'assisted', 'medium' => self::MEDIUM_VOICE],
        self::CHANNEL_ASSISTED_WHATSAPP => ['channel' => 'assisted', 'medium' => self::MEDIUM_WHATSAPP],
        self::CHANNEL_VOICE_CALL => ['channel' => self::CHANNEL_VOICE_CALL, 'medium' => self::MEDIUM_VOICE],
        self::CHANNEL_WHATSAPP_TEMPLATE => ['channel' => self::CHANNEL_WHATSAPP_TEMPLATE, 'medium' => self::MEDIUM_WHATSAPP],
    ];

    /** Los que salen solos hacia la persona (W9/V9): la constante de código los cierra (protección 2). */
    public const AUTOMATIC_CHANNELS = [self::CHANNEL_WHATSAPP_TEMPLATE, self::CHANNEL_VOICE_CALL];

    /** `outreach_jobs.channel` (D9-9.1). */
    public const JOB_CHANNELS = ['simulated', 'assisted', self::CHANNEL_WHATSAPP_TEMPLATE, self::CHANNEL_VOICE_CALL];

    // ── Eventos (D9-3) ──────────────────────────────────────────────────────

    public const CLASS_CRM_FACT = 'crm_fact';

    public const CLASS_PERIODIC = 'periodic';

    public const CLASS_OUTCOME = 'outcome';

    public const CLASS_INTEGRATION = 'integration';

    public const EVENT_CLASSES = [self::CLASS_CRM_FACT, self::CLASS_INTEGRATION, self::CLASS_OUTCOME, self::CLASS_PERIODIC];

    /**
     * Los 27 eventos de `CommercialVocabulary::EVENTS` (copiados; la prueba los
     * ata) → sus finalidades posibles. Sin finalidad: no abren inscripciones
     * (`complaint_created`, DF-K4, y `human_requested`, que solo DETIENE).
     */
    public const CRM_EVENTS = [
        'app_linked' => ['commercial'], 'appointment_completed' => ['commercial', 'service'], 'appointment_created' => ['commercial', 'service'],
        'attendance_milestone' => ['commercial'], 'complaint_created' => [], 'customer_satisfied' => ['commercial'], 'first_checkin' => ['commercial'],
        'human_requested' => [], 'inactivity_detected' => ['commercial'], 'invoice_rejected' => ['service'], 'invoice_requested' => ['service'],
        'invoice_validated' => ['service'], 'lead_created' => ['commercial'], 'lead_qualified' => ['commercial'], 'membership_activated' => ['commercial'],
        'membership_expired' => ['commercial'], 'membership_renewed' => ['commercial'], 'objection_raised' => ['commercial'], 'offer_presented' => ['commercial'],
        'offer_rejected' => ['commercial'], 'payment_approved' => ['commercial'], 'payment_expired' => ['commercial'], 'payment_failed' => ['commercial'],
        'payment_link_created' => ['commercial'], 'payment_pending' => ['commercial'], 'referral_opportunity' => ['commercial'], 'renewal_window_opened' => ['commercial'],
    ];

    /**
     * I-5 (auditoría de cierre B, 11-11-2026): los eventos del vocabulario que
     * HOY nadie registra en `commercial_events`. No los registra ningún
     * observador de `app/Observers/Commercial` ni `commercial:scan`, y
     * tampoco ninguna ruta interna de las que llama n8n.
     *  - Van con `available: false` y `triggerable: false`, como los de
     *    integración (un disparador siempre está disponible): el constructor
     *    no los ofrece y una estrategia no los acepta como disparador
     *    (`trigger_not_allowed`). Conservan su finalidad posible.
     *  - Siguen legibles en condiciones, donde valen «no ocurrió» mientras
     *    nadie los emita.
     *  - `OutreachCatalogEmittersTest` lo ata a los emisores reales: quien le dé
     *    un emisor a uno de ellos lo saca de aquí y sube {@see VERSION}.
     */
    public const EVENTS_WITHOUT_EMITTER = ['complaint_created', 'customer_satisfied', 'objection_raised', 'offer_presented', 'offer_rejected', 'referral_opportunity'];

    /** Los eventos del vocabulario que llevan el plan como atributo filtrable (`where`). */
    public const PAYMENT_EVENTS = ['payment_approved', 'payment_expired', 'payment_failed', 'payment_link_created', 'payment_pending'];

    // ── Dominios cerrados (copiados de sus fuentes; la prueba los ata) ──────

    /** `CommercialVocabulary::GOALS`, por bytes. */
    public const GOALS = [
        'activate_membership', 'book_assessment', 'book_visit', 'close_plan', 'collect_data', 'collect_payment', 'complete_onboarding',
        'cross_sell', 'escalate', 'increase_adherence', 'link_app', 'reactivate', 'recover_payment_link', 'recover_satisfaction', 'renew',
        'request_referral', 'resolve_objection', 'upgrade',
    ];

    /** `CommercialVocabulary::GOAL_PRIORITY` (desempate 4 de D9-8, etapa 3). */
    public const GOAL_PRIORITY = [
        'escalate' => 95, 'recover_payment_link' => 90, 'collect_payment' => 85, 'activate_membership' => 80,
        'recover_satisfaction' => 78, 'renew' => 75, 'close_plan' => 70, 'resolve_objection' => 65, 'reactivate' => 60,
        'complete_onboarding' => 55, 'increase_adherence' => 50, 'book_assessment' => 48, 'book_visit' => 45,
        'upgrade' => 40, 'link_app' => 35, 'cross_sell' => 30, 'collect_data' => 25, 'request_referral' => 20,
    ];

    /** `CommercialPhaseMachine::PHASES`, por bytes. */
    public const LEAD_PHASES = [
        'BARRIER_DISCOVERY', 'BUYING_SIGNAL', 'CLOSING', 'DISCOVERY', 'DO_NOT_CONTACT', 'FOLLOW_UP', 'GOAL_DISCOVERY', 'HUMAN_HANDOFF',
        'LOST', 'NEW_LEAD', 'NURTURE', 'OBJECTION_HANDLING', 'PAYMENT_HANDOFF', 'QUALIFICATION', 'RAPPORT', 'RECOMMENDATION',
        'VALUE_BUILDING', 'WON',
    ];

    /** `marketing_leads.temperature` (`SalesIntents`). */
    public const LEAD_TEMPERATURES = ['cold', 'hot', 'risk', 'very_hot', 'warm'];

    /** `MarketingLead::CONSENT_STATUSES`. */
    public const LEAD_CONSENT_STATUSES = ['denied', 'granted', 'pending', 'unknown'];

    /** Las máquinas cerradas de las transiciones (D9-4). */
    public const ENTITIES = [
        'lead_phase' => self::LEAD_PHASES,
        'membership' => ['active', 'expired', 'frozen'],
        'payment' => ['approved', 'declined', 'expired', 'pending'],
    ];

    public const DOMAIN_PLANS = 'plans';

    public const DOMAIN_CLASS_TYPES = 'class_types';

    /** Los dominios que vienen de la base: el validador recibe su instantánea (DF-T5). `plans` es de enteros. */
    public const DYNAMIC_DOMAINS = [self::DOMAIN_CLASS_TYPES, self::DOMAIN_PLANS];

    /** Tramos de antigüedad de la cartera que enseña la traza (DF-T6). */
    public const RECEIVABLE_BUCKETS = [
        ['label' => '0-7', 'max' => 7, 'min' => 0],
        ['label' => '8-30', 'max' => 30, 'min' => 8],
        ['label' => '31-90', 'max' => 90, 'min' => 31],
        ['label' => '>90', 'max' => null, 'min' => 91],
    ];

    /** Los filtros de los contactos (`count` de contactos y argumentos del historial; DF-C9). */
    public const CONTACT_FILTERS = [
        'channel' => self::MEDIUMS,
        'outcome' => null,
        'purpose' => self::PURPOSES,
        'strategy' => ['other', 'self'],
    ];

    public const STRATEGY_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{2,47}$/D';

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public const HEX64_PATTERN = '/^[0-9a-f]{64}$/D';

    /** `outreach_enrollments.cycle_key` (≤ 96). */
    public const MAX_CYCLE_KEY = 96;

    public const COMPATIBLE = 'compatible';

    public const CATALOG_INCOMPATIBLE = 'catalog_incompatible';

    /** @var array<string,mixed>|null */
    private static ?array $export = null;

    /** @var array<string, array<string,mixed>>|null */
    private static ?array $facts = null;

    /** @var array<string, array<string,mixed>>|null */
    private static ?array $events = null;

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * El catálogo exportado (`GET strategies/catalog`): exactamente el
     * `catalog` del fixture.
     *
     * @return array<string,mixed>
     */
    public static function export(): array
    {
        return self::$export ??= self::build();
    }

    /** `catalogHash`: sha256 del JSON canónico de {@see export()}. */
    public static function hash(): string
    {
        return CanonicalJson::hash(self::export());
    }

    /** @return array<string,mixed>|null la entrada del hecho, o null si no está en el catálogo */
    public static function fact(string $key): ?array
    {
        self::$facts ??= array_column(self::export()['facts'], null, 'key');

        return self::$facts[$key] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function event(string $key): ?array
    {
        self::$events ??= array_column(self::export()['events'], null, 'key');

        return self::$events[$key] ?? null;
    }

    /** @return list<string> */
    public static function outcomes(): array
    {
        return array_column(self::export()['outcomes'], 'code');
    }

    /** El medio de un canal de paso. */
    public static function mediumOf(string $stepChannel): ?string
    {
        return self::STEP_CHANNELS[$stepChannel]['medium'] ?? null;
    }

    /** El tramo de antigüedad de la cartera de un número de días (DF-T6). */
    public static function receivableBucket(int $days): string
    {
        foreach (self::RECEIVABLE_BUCKETS as $t) {
            if ($days >= $t['min'] && ($t['max'] === null || $days <= $t['max'])) {
                return $t['label'];
            }
        }

        return self::RECEIVABLE_BUCKETS[0]['label'];
    }

    /**
     * ¿Es compatible lo que usa una versión con el catálogo vigente (DF-K6)?
     * Cada hecho tiene que existir y cada evento existir con su `keyVersion`;
     * si no, `catalog_incompatible` (nivel N3, L9-21) con lo que falta,
     * ordenado por bytes. La cadena de versión no decide sola (los cambios son
     * aditivos).
     *
     * @param  list<string>  $facts
     * @param  list<array{key: string, keyVersion: int}>  $events
     * @return array{result: string, missing: list<string>, level: string|null}
     */
    public static function compatibility(array $facts, array $events): array
    {
        $faltan = [];
        foreach ($facts as $f) {
            if (! is_string($f) || self::fact($f) === null) {
                $faltan[] = 'fact:'.(is_string($f) ? $f : '?');
            }
        }
        foreach ($events as $e) {
            $clave = is_array($e) && is_string($e['key'] ?? null) ? $e['key'] : '?';
            $vigente = self::event($clave);
            if ($vigente === null) {
                $faltan[] = "event:{$clave}";
            } elseif (($e['keyVersion'] ?? null) !== $vigente['keyVersion']) {
                $faltan[] = "event:{$clave}@".(is_int($e['keyVersion'] ?? null) ? $e['keyVersion'] : '?');
            }
        }
        $faltan = array_values(array_unique($faltan));
        sort($faltan, SORT_STRING);

        // L9-21: una versión incompatible la niega N3 de la escalera (`catalog_incompatible`).
        return $faltan === []
            ? ['result' => self::COMPATIBLE, 'missing' => [], 'level' => null]
            : ['result' => self::CATALOG_INCOMPATIBLE, 'missing' => $faltan, 'level' => 'N3'];
    }

    /**
     * La clave de ciclo de una ocurrencia (DF-K5): la plantilla del evento con
     * sus piezas. Sin una pieza (null o ausente) no hay clave y NO se inscribe:
     * devuelve null. La semana ISO de la inactividad es la del día de Bogotá de
     * `now` (`o-\WW`, como `ScanCommercialFacts`).
     *
     * @param  array<string, int|string|null>  $occurrence
     */
    public static function cycleKey(string $eventKey, array $occurrence, DateTimeInterface $now): ?string
    {
        $evento = self::event($eventKey);
        if ($evento === null) {
            return null;
        }
        $clave = $evento['cycleKey'];
        preg_match_all('/\{([a-z_]+)\}/', $clave, $m);
        foreach ($m[1] as $pieza) {
            $valor = $pieza === 'bogota_iso_week' ? RelativeTime::local($now)->format('o-\WW') : ($occurrence[$pieza] ?? null);
            if (! self::validCyclePart($pieza, $valor)) {
                return null;
            }
            $clave = str_replace('{'.$pieza.'}', (string) $valor, $clave);
        }

        return strlen($clave) <= self::MAX_CYCLE_KEY ? $clave : null;
    }

    private static function validCyclePart(string $pieza, mixed $valor): bool
    {
        return match ($pieza) {
            'membership_end_date', 'session_date' => is_string($valor) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $valor, $f) === 1 && checkdate((int) $f[2], (int) $f[3], (int) $f[1]),
            'bogota_iso_week' => is_string($valor) && preg_match('/^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$/D', $valor) === 1,
            'generation' => is_int($valor) && $valor >= 0,
            'outreach_job_uuid' => is_string($valor) && preg_match(self::UUID_PATTERN, $valor) === 1,
            'status' => is_string($valor) && preg_match('/^[a-z_]{1,32}$/D', $valor) === 1,
            default => is_int($valor) && $valor >= 1,
        };
    }

    // ── La construcción (los datos del fixture) ─────────────────────────────

    /** @return array<string,mixed> */
    private static function build(): array
    {
        $resultados = array_map(static fn (string $c): array => ['code' => $c, 'group' => OutcomeTable::TABLE[$c]['group']], OutcomeTable::sortedOutcomes());
        $canales = [];
        foreach (self::STEP_CHANNELS as $clave => $c) {
            $canales[] = ['key' => $clave, 'channel' => $c['channel'], 'medium' => $c['medium']];
        }
        $filtros = [];
        foreach (self::CONTACT_FILTERS as $nombre => $dominio) {
            $filtros[] = ['name' => $nombre, 'domain' => $dominio ?? OutcomeTable::sortedOutcomes()];
        }
        $transiciones = OutcomeTable::TRANSITIONS;
        sort($transiciones, SORT_STRING);

        return [
            'contactFilters' => $filtros,
            'contract' => self::EXPORT_CONTRACT,
            'dynamicDomains' => self::DYNAMIC_DOMAINS,
            'entities' => self::ENTITIES,
            'eventClasses' => self::EVENT_CLASSES,
            'events' => self::events(),
            'facts' => self::facts(),
            'followUpTransitions' => $transiciones,
            'grammar' => self::GRAMMAR,
            'limits' => [
                'maxCanonicalBytes' => self::MAX_BYTES, 'maxCount' => self::MAX_COUNT, 'maxDepth' => self::MAX_DEPTH,
                'maxEnumValues' => self::MAX_ENUM_VALUES, 'maxNodes' => self::MAX_NODES, 'maxWindowDays' => self::MAX_WINDOW_DAYS,
            ],
            'operators' => self::OPERATORS,
            'outcomes' => $resultados,
            'purposes' => self::PURPOSES,
            'stepChannels' => $canales,
            'types' => self::TYPES,
            'unitDays' => self::UNIT_DAYS,
            'unitHours' => self::UNIT_HOURS,
            'units' => self::UNITS,
            'version' => self::VERSION,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function events(): array
    {
        $evento = static fn (string $key, string $class, array $extra = []): array => $extra + [
            'attrs' => [], 'available' => true, 'cadenceMinutes' => null, 'class' => $class, 'conditionUsable' => false,
            'cost' => 'cheap', 'cycleKey' => '', 'key' => $key, 'keyVersion' => 1, 'paramConstraints' => [], 'params' => [],
            'purposes' => [], 'subject' => 'member_or_lead', 'triggerable' => false,
        ];
        $entero = static fn (string $nombre, int $min, int $max, string $unidad = 'days'): array => ['max' => $max, 'min' => $min, 'name' => $nombre, 'required' => true, 'type' => self::TYPE_INT, 'unit' => $unidad];
        $plan = [['domain' => ['dynamic' => self::DOMAIN_PLANS], 'name' => 'plan_id', 'type' => self::TYPE_ENUM]];

        $eventos = [];
        foreach (self::CRM_EVENTS as $clave => $finalidades) {
            $eventos[] = $evento($clave, self::CLASS_CRM_FACT, [
                'attrs' => in_array($clave, self::PAYMENT_EVENTS, true) ? $plan : [],
                'available' => ! in_array($clave, self::EVENTS_WITHOUT_EMITTER, true),
                'conditionUsable' => true,
                'cycleKey' => 'ce:{commercial_event_id}',
                'purposes' => $finalidades,
                'triggerable' => $finalidades !== [] && ! in_array($clave, self::EVENTS_WITHOUT_EMITTER, true),
            ]);
        }
        $periodico = static fn (string $key, string $cycleKey, array $params, string $subject, int $cadence, string $cost, array $purposes, array $constraints = []): array => $evento($key, self::CLASS_PERIODIC, [
            'cadenceMinutes' => $cadence, 'cost' => $cost, 'cycleKey' => $cycleKey, 'paramConstraints' => $constraints,
            'params' => $params, 'purposes' => $purposes, 'subject' => $subject, 'triggerable' => true,
        ]);
        // L9-29: la fila de la sesión solo nace al iniciar la clase; la clave es la clase y la fecha. Cambió la forma: keyVersion 2
        // (una versión hecha con la clave antigua sale `catalog_incompatible`, no se reinterpreta).
        $eventos[] = array_replace($periodico('class.upcoming_with_capacity', 'cls:{class_id}:{session_date}', [$entero('withinDays', 1, 14)], 'member', 60, 'expensive', ['commercial']), ['keyVersion' => 2]);
        $eventos[] = $periodico('lead.followup_due', 'fu:{marketing_followup_id}', [], 'lead', 15, 'cheap', ['commercial']);
        $eventos[] = $periodico('member.inactive', 'inact:{bogota_iso_week}', [$entero('days', 7, 365)], 'member', 60, 'expensive', ['commercial']);
        $eventos[] = $periodico('membership.expired_window', 'exp:{membership_end_date}', [$entero('daysAfterMax', 1, 365), $entero('daysAfterMin', 1, 365)], 'member', 60, 'cheap', ['commercial'],
            [['left' => 'daysAfterMin', 'op' => 'lte', 'right' => 'daysAfterMax']]);
        $eventos[] = $periodico('membership.expiring_window', 'exp:{membership_end_date}', [$entero('daysBefore', 0, 60)], 'member', 60, 'cheap', ['commercial']);
        $eventos[] = $periodico('payment_link.abandoned', 'tx:{payment_transaction_id}', [$entero('graceHours', 1, 168, 'hours')], 'member', 15, 'cheap', ['commercial']);
        $eventos[] = $periodico('receivable.overdue', 'rcv:{receivable_id}:{generation}', [$entero('daysMax', 1, 730), $entero('daysMin', 1, 730)], 'member', 60, 'cheap', ['collections'],
            [['left' => 'daysMin', 'op' => 'lte', 'right' => 'daysMax']]);
        $eventos[] = $periodico('valuation.outdated', 'val:{last_valuation_id}', [$entero('days', 1, 730)], 'member', 60, 'expensive', ['commercial']);

        foreach (['integration.inbound_message' => 'msg:{marketing_message_id}', 'integration.meta_status' => 'msg:{marketing_message_id}:{status}', 'integration.voice_call_status' => 'call:{voice_call_id}:{status}'] as $clave => $ciclo) {
            $eventos[] = $evento($clave, self::CLASS_INTEGRATION, ['available' => false, 'cycleKey' => $ciclo]);
        }
        foreach (['outcome.callback_requested', 'outcome.no_answer', 'outcome.replied'] as $clave) {
            $eventos[] = $evento($clave, self::CLASS_OUTCOME, ['cycleKey' => 'job:{outreach_job_uuid}']);
        }

        return self::sortByKey($eventos);
    }

    /** @return list<array<string,mixed>> */
    private static function facts(): array
    {
        $todas = self::PURPOSES;
        $h = static fn (string $key, string $group, string $type, array $extra = []): array => $extra + [
            'args' => [], 'buckets' => null, 'domain' => null, 'group' => $group, 'key' => $key, 'max' => null, 'min' => null,
            'nullable' => true, 'prefilter' => false, 'purposes' => $todas, 'scale' => null, 'trace' => 'value', 'type' => $type, 'unit' => null,
        ];
        $rango = static fn (string $unit, int $min, int $max, array $extra = []): array => $extra + ['unit' => $unit, 'min' => $min, 'max' => $max];
        $arg = static fn (string $nombre, ?array $dominio, string $tipo = 'enum', bool $requerido = false): array => ['domain' => $dominio, 'name' => $nombre, 'required' => $requerido, 'type' => $tipo];
        $canal = $arg('channel', self::MEDIUMS);
        $finalidad = $arg('purpose', self::PURPOSES);
        $estrategia = $arg('strategy', self::CONTACT_FILTERS['strategy']);
        $soloCobranza = ['purposes' => [self::PURPOSE_COLLECTIONS]];
        $persona = ['nullable' => false];

        $hechos = [
            $h('attendance.count_last_30d', 'attendance', self::TYPE_INT, $rango('count', 0, 1000)),
            $h('attendance.days_since_last', 'attendance', self::TYPE_DURATION, $rango('days', 0, 36500, ['prefilter' => true])),
            $h('attendance.weekly_rate', 'attendance', self::TYPE_DECIMAL, $rango('visits_per_week', 0, 10000, ['scale' => 2])),
            $h('class.attended_type_last_60d', 'agenda', self::TYPE_ENUM, ['domain' => ['dynamic' => self::DOMAIN_CLASS_TYPES]]),
            $h('class.reserved_next_7d', 'agenda', self::TYPE_BOOL),
            $h('contact.count', 'history', self::TYPE_INT, $rango('count', 0, self::MAX_COUNT, ['args' => [$canal, $finalidad, $estrategia, $arg('within', null, 'window', true)]])),
            $h('contact.days_since_last', 'history', self::TYPE_DURATION, $rango('days', 0, 36500, ['args' => [$canal, $finalidad]])),
            $h('contact.last_outcome', 'history', self::TYPE_ENUM, ['args' => [$canal, $estrategia], 'domain' => OutcomeTable::sortedOutcomes()]),
            $h('conversation.human_in_control', 'conversation', self::TYPE_BOOL),
            $h('conversation.window_open', 'conversation', self::TYPE_BOOL),
            $h('lead.commercial_phase', 'lead', self::TYPE_ENUM, ['domain' => self::LEAD_PHASES]),
            $h('lead.consent_status', 'lead', self::TYPE_ENUM, ['domain' => self::LEAD_CONSENT_STATUSES]),
            $h('lead.days_since_last_inbound', 'lead', self::TYPE_DURATION, $rango('days', 0, 36500)),
            $h('lead.followup_due', 'lead', self::TYPE_BOOL),
            $h('lead.last_inbound_at', 'lead', self::TYPE_DATE_RELATIVE, $rango('hours', -87600, 0)),
            $h('lead.temperature', 'lead', self::TYPE_ENUM, ['domain' => self::LEAD_TEMPERATURES]),
            $h('membership.active', 'membership', self::TYPE_BOOL),
            $h('membership.days_as_member', 'membership', self::TYPE_DURATION, $rango('days', 0, 36500)),
            $h('membership.days_since_expiry', 'membership', self::TYPE_INT, $rango('days', -3650, 3650)),
            $h('membership.days_to_expiry', 'membership', self::TYPE_INT, $rango('days', -3650, 3650, ['prefilter' => true])),
            $h('membership.end_date', 'membership', self::TYPE_DATE_RELATIVE, $rango('days', -3650, 3650)),
            $h('membership.frozen', 'membership', self::TYPE_BOOL),
            $h('membership.plan_duration_days', 'membership', self::TYPE_INT, $rango('days', 1, 3650)),
            $h('membership.plan_id', 'membership', self::TYPE_ENUM, ['domain' => ['dynamic' => self::DOMAIN_PLANS]]),
            $h('nba.open_goal', 'engine', self::TYPE_ENUM, ['domain' => self::GOALS]),
            $h('payment.approved_count', 'money', self::TYPE_INT, $rango('count', 0, 100000)),
            $h('payment.declined_last_7d', 'money', self::TYPE_INT, $rango('count', 0, 1000)),
            $h('payment.has_pending_link', 'money', self::TYPE_BOOL),
            $h('payment.pending_link_age_hours', 'money', self::TYPE_DURATION, $rango('hours', 0, 8760)),
            $h('receivable.count_overdue', 'receivable', self::TYPE_INT, $rango('count', 0, 1000, $soloCobranza)),
            $h('receivable.has_overdue', 'receivable', self::TYPE_BOOL, $soloCobranza),
            $h('receivable.overdue_days_max', 'receivable', self::TYPE_INT, $rango('days', 0, 36500, $soloCobranza + ['buckets' => self::RECEIVABLE_BUCKETS, 'prefilter' => true, 'trace' => 'bucket'])),
            $h('subject.has_voice_contact', 'subject', self::TYPE_BOOL, $persona),
            $h('subject.has_whatsapp_identity', 'subject', self::TYPE_BOOL, $persona),
            $h('subject.is_lead', 'subject', self::TYPE_BOOL, $persona),
            $h('subject.is_member', 'subject', self::TYPE_BOOL, $persona),
            $h('subject.is_minor', 'subject', self::TYPE_BOOL),
            $h('subscription.active', 'money', self::TYPE_BOOL),
            $h('subscription.failed_attempts', 'money', self::TYPE_INT, $rango('count', 0, 1000)),
            $h('valuation.days_since_last', 'agenda', self::TYPE_DURATION, $rango('days', 0, 36500)),
            $h('valuation.has_upcoming', 'agenda', self::TYPE_BOOL),
            $h('valuation.next_date', 'agenda', self::TYPE_DATE_RELATIVE, $rango('days', 0, 3650)),
        ];

        return self::sortByKey($hechos);
    }

    /**
     * @param  list<array<string,mixed>>  $lista
     * @return list<array<string,mixed>>
     */
    private static function sortByKey(array $lista): array
    {
        usort($lista, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $lista;
    }
}
