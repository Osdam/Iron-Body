<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HITO 9 (autonomía comercial), paquete DB9: LA EJECUCIÓN (`outreach_*`).
 * Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md, D9-8 (las nueve
 * etapas, todas filas), D9-10 (resultados y estados), D9-14 (frenos y PARADA
 * GLOBAL), §5 (el sello), §6.2 y la «Congelación» (P9-8, P9-9).
 * Informe: plans/informes-h9/informe-db.md.
 *
 * Ocho tablas NUEVAS, SOLO ADITIVAS E INERTES (protección 11): crearlas no
 * enciende nada. No alteran ninguna tabla existente ni nombran el motor
 * comercial, Marketing, la voz ni n8n (protecciones 5–9): sus únicas FKs van
 * al gobierno del H9 (estrategias, versiones, la cadena por su `seq` y las
 * plantillas) y entre ellas. Socio y lead son REFERENCIAS sin FK.
 *
 *  · outreach_runs           — una fila por pasada; nace `running` y se
 *                              cierra una vez (completed | failed | aborted).
 *  · outreach_cursors        — marcas de agua por fuente: `last_id` solo crece.
 *  · outreach_enrollments    — persona × estrategia × ciclo × modo. UNA
 *                              OCURRENCIA → UNA INSCRIPCIÓN
 *                              (`oenr_occurrence_unique`: estrategia, persona,
 *                              clave de ciclo y modo) y como mucho UNA ABIERTA
 *                              por estrategia, persona y modo (`oenr_one_open`).
 *                              Estados de D9-10; el paso y los contactos solo
 *                              crecen; el sello es el de una versión VIVA de su
 *                              estrategia y con la finalidad de su estrategia.
 *  · outreach_jobs           — los trabajos (con el `phone_ref` del número, L9-30): `planned` → `claimed` →
 *                              `dispatching` → started | simulated |
 *                              failed_to_start | ambiguous, y desde planned o
 *                              claimed, canceled | expired (y claimed →
 *                              ambiguous: la conciliación). UN TRABAJO VIVO POR
 *                              PERSONA Y MODO (`ojob_one_live`); un intento es
 *                              una fila (`ojob_attempt_unique`); la clave de
 *                              idempotencia, única; la reserva del día de
 *                              Bogotá congelada y liberada una vez; las
 *                              referencias del canal, una vez. En sombra o por
 *                              el canal simulado NUNCA sale nada ni se reserva.
 *  · outreach_decisions      — solo inserción: la decisión con su traza
 *                              (`trace_hash`), su foto de hechos derivados y el
 *                              sello; motivos de un conjunto cerrado (códigos).
 *  · outreach_outcomes       — solo inserción: los 18 resultados de D9-10 con
 *                              lo que necesita el historial (L9-3: estrategia,
 *                              persona, canal, motivo y próxima acción), la
 *                              matriz fuente → resultado y resultado →
 *                              transición de D9-10 (la de `OutcomeTable`), la
 *                              regla asimétrica de la IA (P9-6: el clasificador
 *                              solo frena o deriva) y UNIQUE (inscripción,
 *                              fuente, clave de la fuente).
 *  · outreach_brakes         — frenos y PARADA GLOBAL: se abre y se suelta, una
 *                              vez cada cosa; uno abierto por alcance; lo suelta
 *                              otra cuenta y otra persona, con nota (P9-20).
 *  · outreach_budget_counters — la RESERVA ATÓMICA por día de Bogotá, alcance
 *                              (canal, estrategia o teléfono, L9-2 y L9-30) y modo: la comprobación va
 *                              en el MISMO UPDATE (`… WHERE reserved + n <=
 *                              cap`) y, por debajo, `reserved <= cap` en la
 *                              base: dos reservas que juntas pasan del techo
 *                              no caben.
 *
 * DATOS PERSONALES (protección 10, Ley 1581, P9-9). Ninguna columna lleva
 * teléfono, nombre, documento, cuerpo de mensaje ni importe: la persona es su
 * `subject_key` interno (`m:<id>` | `l:<id>`) y su seudónimo `subject_ref`
 * (64 hex, HMAC `outreach-subject/1`); las trazas y los hechos son derivados.
 * RETENCIÓN de 18 meses y SUPRESIÓN 1581 (L9-5): `redacted_at` en inscripciones,
 * trabajos, decisiones y resultados; en el trabajo, además, `phone_ref` a NULL.
 * Los contadores por teléfono (`phone:<ref>`) los borra la supresión. La aplicación no puede escribirla (es inmutable para
 * ella); la supresión de OPS9 la pone con los disparadores apagados en su
 * transacción, y el CHECK de PG solo admite la forma suprimida: `subject_key`
 * = 'x:<id>', `subject_ref` = 64 ceros y, en la inscripción, sin socio ni lead.
 * Los únicos nombres son los del personal que reclama o suelta un freno.
 *
 * LAS GUARDAS, con su PROPIA función `outreach_forbid()` («outreach: …»), como
 * la telefonía con la suya: la ejecución no depende de las funciones del
 * Studio ni al revés.
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000.
 *  · Disparadores de fila en los dos motores, mismo nombre y mismo texto; PG
 *    suma uno de sentencia contra TRUNCATE por tabla.
 *  · Columnas congeladas por LISTA, clasificadas una sola vez (up() lo
 *    comprueba); estados solo hacia delante; «una vez» para las referencias,
 *    la reclamación del asistido y la liberación de la reserva.
 *  · Guardas ENTRE TABLAS (el sello contra su versión o su inscripción, la
 *    entrada de la cadena de su estrategia, la plantilla aprobada de su
 *    huella y su canal, el trabajo de su inscripción): en PG una función
 *    propia por guarda (`outreach_chk_*`), plpgsql, INVOKER, con `public.`;
 *    en SQLite, la misma condición en el WHEN.
 *  · Solo en PG, los CHECK de dominio y de forma. En SQLite tienen
 *    equivalente los de cuatro ojos del freno y el techo de la reserva; los
 *    demás los vigila el servicio y la puerta PG es obligatoria.
 *  · Índices únicos PARCIALES, el mismo texto en los dos motores.
 *
 * EL ROL DE LA APLICACIÓN pierde UPDATE y DELETE en las dos de solo inserción
 * (decisiones y resultados) y DELETE en las otras seis, con la comprobación
 * posterior y la guarda de producción (`ULTRON_STUDIO_DB_APP_ROLE`, la misma
 * variable que DB7/DB8). La aplicación conserva INSERT en todas: un
 * `insertOrIgnore` (ON CONFLICT DO NOTHING) vale; `FOR UPDATE` y ON CONFLICT
 * DO UPDATE sobre decisiones y resultados, no (42501).
 *
 * Compatibilidad PostgreSQL 14 + SQLite: `json`, no `jsonb`; FKs RESTRICT
 * nacidas con su tabla y con nombre corto explícito; el índice del freno
 * abierto lleva una expresión (`COALESCE(scope_ref, '')`), admitida por los
 * dos motores.
 *
 * CERROJOS (PG). Si la sesión no trae `lock_timeout`, `SET LOCAL lock_timeout
 * = '5s'`. Las FKs piden SHARE ROW EXCLUSIVE sobre las tablas del gobierno del
 * H9 (nuevas, nadie las usa todavía); ninguna tabla existente se toca.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. `CREATE OR REPLACE FUNCTION`: `migrate:fresh`
 * borra tablas pero no funciones. Con `migrate --pretend` no se lee nada.
 *
 * Lo que NO cubre:
 *  · El dueño puede desactivar o borrar disparadores y devolver privilegios;
 *    el rol de la aplicación, no (42501). La supresión 1581 (OPS9) tiene que
 *    reconocer `outreach_forbid()` y las `outreach_chk_*` como guardas.
 *  · Que el contador de la reserva sea la suma de las reservas vivas, que la
 *    traza sea la del evaluador, que la decisión sea la que toca o que un
 *    trabajo respete la escalera N1–N7: lo garantiza el servicio (B9-run) en
 *    la misma transacción y lo vigila el monitor.
 *  · Con dos violaciones en la misma sentencia, el motivo que se ve puede
 *    depender del motor; la sentencia falla igual en los dos.
 *  · SQLite: `INSERT OR REPLACE` borra sin disparar el DELETE; Laravel no lo usa.
 *  · Una restauración de SOLO DATOS pasa por los disparadores fila a fila:
 *    los de «nace en su estado inicial» rechazan filas en estados posteriores
 *    (`--disable-triggers`, superusuario).
 *
 * down() SE NIEGA si hay alguna fila en cualquiera de las ocho tablas, antes de
 * tocar nada y con los cerrojos ya tomados, y NUNCA borra filas. Sin filas
 * borra las tablas y sus funciones. En producción no se ejecuta nunca.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'outreach:';

    public const FUNCTION_NAME = 'outreach_forbid';

    public const CHECK_FUNCTION_PREFIX = 'outreach_chk_';

    public const RUNS = 'outreach_runs';

    public const CURSORS = 'outreach_cursors';

    public const ENROLLMENTS = 'outreach_enrollments';

    public const JOBS = 'outreach_jobs';

    public const DECISIONS = 'outreach_decisions';

    public const OUTCOMES = 'outreach_outcomes';

    public const BRAKES = 'outreach_brakes';

    public const BUDGETS = 'outreach_budget_counters';

    /** Del gobierno (2026_11_12_100001): solo se referencian. */
    public const STRATEGIES = 'ultron_studio_strategies';

    public const VERSIONS = 'ultron_studio_strategy_versions';

    public const PUBLICATIONS = 'ultron_studio_strategy_publications';

    public const TEMPLATES = 'ultron_studio_message_templates';

    /** En orden de creación (cada una después de las que referencia); down() las borra al revés. */
    public const TABLES = [self::RUNS, self::CURSORS, self::ENROLLMENTS, self::JOBS, self::DECISIONS, self::OUTCOMES, self::BRAKES, self::BUDGETS];

    /** Solo inserción (§6.2): sin UPDATE ni DELETE para la aplicación. */
    public const INSERT_ONLY_TABLES = [self::DECISIONS, self::OUTCOMES];

    /** Con estados o contadores: conservan UPDATE, pierden DELETE. */
    public const STATEFUL_TABLES = [self::RUNS, self::CURSORS, self::ENROLLMENTS, self::JOBS, self::BRAKES, self::BUDGETS];

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    public const LOCK_TIMEOUT = '5s';

    public const MODES = ['shadow', 'live'];

    public const PURPOSES = ['commercial', 'collections', 'service'];

    public const PARTS = ['canary', 'rest'];

    public const RUN_KINDS = ['ingest', 'sweep', 'dispatch', 'monitor', 'reconcile', 'replay'];

    /** @var array<string, list<string>> */
    public const RUN_TRANSITIONS = ['running' => ['completed', 'failed', 'aborted']];

    public const RUN_STATUSES = ['running', 'completed', 'failed', 'aborted'];

    /** D9-10: abiertas y finales, en orden. */
    public const ENROLLMENT_STATUSES = ['active', 'waiting', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];

    public const ENROLLMENT_OPEN_STATUSES = ['active', 'waiting'];

    public const ENROLLMENT_FINAL_STATUSES = ['completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];

    /**
     * Las dos abiertas se alternan (esperar el retraso del paso ⇄ planificar el
     * siguiente) y cualquiera va a un final; un final no cambia.
     *
     * @var array<string, list<string>>
     */
    public const ENROLLMENT_TRANSITIONS = [
        'active' => ['waiting', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'],
        'waiting' => ['active', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'],
    ];

    public const CHANNELS = ['simulated', 'assisted', 'whatsapp_template', 'voice_call'];

    public const JOB_STATUSES = ['planned', 'claimed', 'dispatching', 'started', 'simulated', 'failed_to_start', 'ambiguous', 'canceled', 'expired'];

    public const JOB_LIVE_STATUSES = ['planned', 'claimed', 'dispatching'];

    public const JOB_FINAL_STATUSES = ['started', 'simulated', 'failed_to_start', 'ambiguous', 'canceled', 'expired'];

    /**
     * §6.2 y D9-8: etapas 5–7 y la conciliación (claimed | dispatching con
     * más de 10 min → ambiguous; planned vencido → expired). Un estado final
     * no cambia: un `ambiguous` se concilia con un RESULTADO, no moviendo el
     * trabajo.
     *
     * @var array<string, list<string>>
     */
    public const JOB_TRANSITIONS = [
        'planned' => ['claimed', 'canceled', 'expired'],
        'claimed' => ['dispatching', 'canceled', 'expired', 'ambiguous'],
        'dispatching' => ['started', 'simulated', 'failed_to_start', 'ambiguous'],
    ];

    /**
     * Lo que un trabajo, también final, recibe una sola vez: la referencia del
     * canal, la reclamación del asistido y la liberación de su reserva.
     * `claimed_at` es «una vez» mientras el trabajo vive y, como
     * `cancel_reason`, lo congela el disparador de «definitivo» después.
     */
    public const JOB_ONCE_COLUMNS = [
        'marketing_message_id', 'voice_call_id', 'assisted_claimed_by_admin_id', 'assisted_claimed_by_name', 'assisted_claimed_at', 'budget_released_at',
    ];

    /** Lo que se congela cuando el trabajo llega a un final. */
    public const JOB_CLOSED_COLUMNS = ['status', 'not_before', 'cancel_reason', 'claimed_at', 'status_changed_at'];

    public const DECISION_KINDS = ['enroll', 'exclude', 'deny', 'defer', 'plan', 'cancel', 'exit', 'handoff'];

    public const LEVELS = ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7'];

    /** D9-10: los 18 resultados, conjunto cerrado. */
    public const OUTCOMES_SET = [
        'blocked', 'not_attempted', 'failed', 'ambiguous', 'sent', 'delivered', 'read', 'no_answer', 'answered',
        'replied', 'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out',
        'appointment_booked', 'converted', 'no_response',
    ];

    /**
     * D9-10, columna «Fuentes»: qué resultados da cada fuente (canal = sistema,
     * estado de Meta o llamada; asistido = personal; AI9 = clasificador, que
     * solo frena o deriva, P9-6).
     *
     * @var array<string, list<string>>
     */
    public const SOURCE_OUTCOMES = [
        'simulated' => ['not_attempted'],
        'staff' => ['no_answer', 'answered', 'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked'],
        'crm_fact' => ['appointment_booked', 'converted'],
        'system' => ['blocked', 'failed', 'ambiguous', 'converted', 'no_response'],
        'meta_status' => ['failed', 'sent', 'delivered', 'read'],
        'inbound_message' => ['replied', 'human_requested', 'opted_out'],
        'voice_call' => ['failed', 'sent', 'no_answer', 'answered', 'human_requested', 'wrong_person', 'opted_out'],
        'classifier' => ['callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out'],
    ];

    /**
     * D9-10, «Transiciones permitidas» (la de `OutcomeTable::TABLE`): la próxima
     * acción que puede seguir a cada resultado. Vacío: ninguna (se espera o se
     * concilia). Un resultado con transiciones lleva siempre la suya (la de la
     * estrategia o la de por defecto, D9-10).
     *
     * @var array<string, list<string>>
     */
    public const OUTCOME_TRANSITIONS = [
        'ambiguous' => [], 'delivered' => [], 'not_attempted' => [], 'read' => [], 'sent' => [],
        'answered' => ['end_exit', 'end_success', 'handoff_human', 'next_step'],
        'appointment_booked' => ['end_success', 'next_step'],
        'blocked' => ['end_exit', 'wait'],
        'callback_requested' => ['end_exit', 'wait'],
        'converted' => ['end_success'],
        'failed' => ['end_exit', 'next_step', 'retry_step'],
        'human_requested' => ['handoff_human'],
        'no_answer' => ['end_exit', 'next_step', 'retry_step', 'wait'],
        'no_response' => ['end_exit'],
        'not_interested' => ['end_exit'],
        'opted_out' => ['end_exit'],
        'replied' => ['end_exit', 'handoff_human'],
        'wrong_person' => ['end_exit'],
    ];

    /** P9-6: lo único a lo que puede llevar un resultado del clasificador (la IA solo frena o deriva). */
    public const CLASSIFIER_TRANSITIONS = ['end_exit', 'handoff_human', 'wait'];

    public const BRAKE_SCOPES = ['global', 'strategy', 'channel'];

    /** D9-14: lo que abre una persona (PARADA GLOBAL o pausa) y lo que abre el monitor solo. */
    public const BRAKE_PERSON_REASONS = ['manual', 'legal_hold'];

    public const BRAKE_SYSTEM_REASONS = ['opt_out_spike', 'failure_spike', 'budget_breach', 'unknown_send', 'chain_unreadable', 'drift'];

    /** Los canales que se pueden frenar (el simulado no sale hacia nadie). */
    public const BRAKE_CHANNELS = ['assisted', 'whatsapp_template', 'voice_call'];

    public const CATALOG_VERSION_PATTERN = '^outreach-catalog/[0-9]{4}-[0-9]{2}-[0-9]{2}\.[1-9][0-9]{0,3}$';

    public const EVALUATOR_VERSION_PATTERN = '^[a-z][a-z0-9-]{1,30}/[0-9A-Za-z._-]{1,16}$';

    public const SUBJECT_KEY_PATTERN = '^(m|l):[1-9][0-9]{0,18}$';

    /** Un motivo (D9-7) es un código; el de la voz lleva su motivo detrás (`voice_policy:<motivo>`). */
    public const REASON_CODE_PATTERN = '[a-z][a-z0-9_:]{0,63}';

    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    public const RUN_IMMUTABLE_COLUMNS = ['id', 'uuid', 'kind', 'mode', 'strategy_id', 'trigger_key', 'started_at', 'created_at'];

    public const RUN_MUTABLE_COLUMNS = ['status', 'finished_at', 'counts', 'error_code', 'updated_at'];

    public const CURSOR_IMMUTABLE_COLUMNS = ['source', 'created_at'];

    public const CURSOR_MUTABLE_COLUMNS = ['last_id', 'updated_at'];

    /** El sello (§5), común a inscripciones, trabajos y decisiones. */
    public const SEAL_COLUMNS = [
        'strategy_id', 'strategy_version_id', 'content_hash', 'publication_seq', 'part', 'studio_ref_kind',
        'studio_version_uuid', 'studio_content_hash', 'catalog_version', 'evaluator_version', 'mode',
    ];

    public const ENROLLMENT_IMMUTABLE_COLUMNS = [
        'id', 'uuid', ...self::SEAL_COLUMNS, 'subject_key', 'subject_ref', 'member_id', 'lead_id', 'cycle_key', 'purpose',
        'enrolled_at', 'window_ends_at', 'redacted_at', 'created_at',
    ];

    public const ENROLLMENT_MUTABLE_COLUMNS = [
        'status', 'step_index', 'touches', 'next_due_at', 'last_outcome', 'exit_reason', 'ended_at', 'status_changed_at', 'updated_at',
    ];

    public const JOB_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'enrollment_id', ...self::SEAL_COLUMNS, 'subject_key', 'subject_ref', 'phone_ref', 'step_index', 'attempt', 'channel', 'medium',
        'template_version_id', 'template_hash', 'idempotency_key', 'expires_at', 'budget_day', 'reserved_units', 'reserved_micros',
        'redacted_at', 'created_at',
    ];

    public const JOB_MUTABLE_COLUMNS = [
        'status', 'not_before', 'cancel_reason', 'claimed_at', ...self::JOB_ONCE_COLUMNS, 'status_changed_at', 'updated_at',
    ];

    public const BRAKE_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'scope', 'scope_ref', 'reason', 'note', 'opened_by_type', 'opened_by_admin_id',
        'opened_by_name', 'opened_by_person_id', 'opened_at', 'created_at',
    ];

    public const BRAKE_MUTABLE_COLUMNS = [
        'released_at', 'released_by_admin_id', 'released_by_name', 'released_by_person_id', 'release_note', 'updated_at',
    ];

    public const BUDGET_IMMUTABLE_COLUMNS = ['id', 'budget_day', 'scope', 'mode', 'created_at'];

    public const BUDGET_MUTABLE_COLUMNS = ['cap_units', 'cap_micros', 'reserved_units', 'reserved_micros', 'updated_at'];

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'orun_forbid_truncate' => [self::RUNS, 'las pasadas no se borran'],
        'ocur_forbid_truncate' => [self::CURSORS, 'los cursores solo avanzan'],
        'oenr_forbid_truncate' => [self::ENROLLMENTS, 'las inscripciones no se borran'],
        'ojob_forbid_truncate' => [self::JOBS, 'los trabajos no se borran'],
        'odec_forbid_truncate' => [self::DECISIONS, 'las decisiones son de solo inserción'],
        'oout_forbid_truncate' => [self::OUTCOMES, 'los resultados son de solo inserción'],
        'obrk_forbid_truncate' => [self::BRAKES, 'los frenos se sueltan, no se borran'],
        'obud_forbid_truncate' => [self::BUDGETS, 'las reservas del día no se borran'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. Un NULL pasa (el de forma dice si cabe). */
    public const DOMAIN_CHECKS = [
        self::RUNS => [
            'orun_kind_chk' => ['kind', self::RUN_KINDS],
            'orun_mode_chk' => ['mode', self::MODES],
            'orun_status_chk' => ['status', self::RUN_STATUSES],
        ],
        self::ENROLLMENTS => [
            'oenr_status_chk' => ['status', self::ENROLLMENT_STATUSES],
            'oenr_purpose_chk' => ['purpose', self::PURPOSES],
            'oenr_mode_chk' => ['mode', self::MODES],
            'oenr_part_chk' => ['part', self::PARTS],
            'oenr_studio_ref_kind_chk' => ['studio_ref_kind', ['version', 'baseline']],
            'oenr_last_outcome_chk' => ['last_outcome', self::OUTCOMES_SET],
        ],
        self::JOBS => [
            'ojob_channel_chk' => ['channel', self::CHANNELS],
            'ojob_status_chk' => ['status', self::JOB_STATUSES],
            'ojob_mode_chk' => ['mode', self::MODES],
            'ojob_part_chk' => ['part', self::PARTS],
            'ojob_studio_ref_kind_chk' => ['studio_ref_kind', ['version', 'baseline']],
        ],
        self::DECISIONS => [
            'odec_kind_chk' => ['kind', self::DECISION_KINDS],
            'odec_level_chk' => ['level', self::LEVELS],
            'odec_mode_chk' => ['mode', self::MODES],
            'odec_part_chk' => ['part', self::PARTS],
            'odec_studio_ref_kind_chk' => ['studio_ref_kind', ['version', 'baseline']],
        ],
        self::OUTCOMES => [
            'oout_outcome_chk' => ['outcome', self::OUTCOMES_SET],
            'oout_source_chk' => ['source', ['simulated', 'staff', 'crm_fact', 'system', 'meta_status', 'inbound_message', 'voice_call', 'classifier']],
            'oout_channel_chk' => ['channel', self::CHANNELS],
        ],
        self::BRAKES => [
            'obrk_scope_chk' => ['scope', self::BRAKE_SCOPES],
            'obrk_reason_chk' => ['reason', [...self::BRAKE_PERSON_REASONS, ...self::BRAKE_SYSTEM_REASONS]],
            'obrk_opened_by_type_chk' => ['opened_by_type', ['person', 'system']],
        ],
        self::BUDGETS => [
            'obud_mode_chk' => ['mode', self::MODES],
        ],
    ];

    /**
     * Índices únicos parciales, en los dos motores: nombre → [tabla, columnas
     * o expresiones, predicado].
     */
    public const PARTIAL_UNIQUE_INDEXES = [
        'oenr_one_open' => [self::ENROLLMENTS, ['strategy_id', 'subject_key', 'mode'], "status IN ('active', 'waiting')"],
        'ojob_one_live' => [self::JOBS, ['subject_key', 'mode'], "status IN ('planned', 'claimed', 'dispatching')"],
        'obrk_one_open' => [self::BRAKES, ['scope', "COALESCE(scope_ref, '')"], 'released_at IS NULL'],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::transaction(fn () => $this->upPostgres());

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->upSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::transaction(fn () => $this->downPostgres());

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->downSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    /** El texto del error, igual en los dos motores. */
    public static function message(string $reason, string $operation, string $table): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.$table.')';
    }

    /** El rol de la aplicación al que se revoca (la misma regla y la misma variable que DB7/DB8). */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('outreach (Hito 9): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Toda
     * columna que se compara sin `IS [NOT] NULL` delante es NOT NULL o va
     * protegida en la misma conjunción: ninguna expresión puede dar NULL.
     *
     * @return array<string, array<string, string>>
     */
    public static function rowChecks(): array
    {
        $hex = static fn (string $c): string => "{$c} ~ '^[0-9a-f]{64}$'";
        $code = static fn (string $c, int $max): string => "{$c} ~ '^[a-z][a-z0-9_]{0,".($max - 1)."}$'";
        $json = static fn (string $c, string $type, int $max): string => "(json_typeof({$c}) = '{$type}' AND octet_length({$c}::text) <= {$max})";
        $filled = static fn (string $c): string => "({$c} IS NOT NULL AND char_length(btrim({$c})) >= 1)";
        $seal = $hex('content_hash').' AND '.$hex('studio_content_hash').' AND publication_seq >= 1 '
            ."AND catalog_version ~ '".self::CATALOG_VERSION_PATTERN."' AND evaluator_version ~ '".self::EVALUATOR_VERSION_PATTERN."' "
            ."AND ((studio_ref_kind = 'version' AND studio_version_uuid IS NOT NULL) OR (studio_ref_kind = 'baseline' AND studio_version_uuid IS NULL))";
        // La persona: su clave y su seudónimo, o la forma suprimida (1581).
        $subject = "(redacted_at IS NULL AND subject_key ~ '".self::SUBJECT_KEY_PATTERN."' AND ".$hex('subject_ref').') '
            ."OR (redacted_at IS NOT NULL AND subject_key = 'x:' || id::text AND subject_ref = repeat('0', 64))";
        $template = '(template_version_id IS NULL AND template_hash IS NULL) OR (template_version_id IS NOT NULL AND template_hash IS NOT NULL AND '.$hex('template_hash').')';
        $finals = self::literalList(self::ENROLLMENT_FINAL_STATUSES);
        $open = self::literalList(self::ENROLLMENT_OPEN_STATUSES);
        $reasons = "reasons::text ~ '^\\[(\"".self::REASON_CODE_PATTERN.'"(,"'.self::REASON_CODE_PATTERN."\"){0,15})?\\]$'";
        $nextActions = implode(' OR ', array_map(
            static fn (string $outcome, array $actions): string => $actions === []
                ? "(outcome = '{$outcome}' AND next_action IS NULL AND next_action_at IS NULL)"
                : "(outcome = '{$outcome}' AND next_action IS NOT NULL AND next_action IN (".self::literalList($actions).'))',
            array_keys(self::OUTCOME_TRANSITIONS),
            self::OUTCOME_TRANSITIONS,
        ));
        $sourceOutcomes = implode(' OR ', array_map(
            static fn (string $source, array $outcomes): string => "(source = '{$source}' AND outcome IN (".self::literalList($outcomes).'))',
            array_keys(self::SOURCE_OUTCOMES),
            self::SOURCE_OUTCOMES,
        ));

        return [
            self::RUNS => [
                'orun_closed_chk' => "(status = 'running' AND finished_at IS NULL) OR (status <> 'running' AND finished_at IS NOT NULL AND finished_at >= started_at)",
                'orun_error_chk' => "(status IN ('running', 'completed') AND error_code IS NULL) OR (status = 'failed' AND error_code IS NOT NULL AND ".$code('error_code', 48).') '
                    ."OR (status = 'aborted' AND (error_code IS NULL OR ".$code('error_code', 48).'))',
                'orun_counts_chk' => 'counts IS NULL OR '.$json('counts', 'object', 4096),
                'orun_trigger_key_chk' => "trigger_key IS NULL OR trigger_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'",
            ],
            self::CURSORS => [
                'ocur_source_chk' => "source ~ '^[a-z][a-z0-9_.:-]{0,95}$'",
                'ocur_last_id_chk' => 'last_id >= 0',
            ],
            self::ENROLLMENTS => [
                'oenr_subject_chk' => "({$subject}) AND ((redacted_at IS NULL AND ((member_id IS NOT NULL AND subject_key = 'm:' || member_id::text) "
                    ."OR (member_id IS NULL AND lead_id IS NOT NULL AND subject_key = 'l:' || lead_id::text))) OR (redacted_at IS NOT NULL AND member_id IS NULL AND lead_id IS NULL))",
                'oenr_cycle_chk' => "cycle_key ~ '^[A-Za-z0-9_.:-]{1,96}$'",
                // ≤ 6 pasos y ≤ 6 contactos por inscripción (D9-6).
                'oenr_counters_chk' => 'step_index BETWEEN 0 AND 5 AND touches BETWEEN 0 AND 6',
                // La ventana de la inscripción, como mucho 60 días (D9-6).
                'oenr_window_chk' => "window_ends_at > enrolled_at AND window_ends_at <= enrolled_at + interval '60 days'",
                'oenr_closed_chk' => "(status IN ({$open}) AND ended_at IS NULL AND exit_reason IS NULL) "
                    ."OR (status IN ({$finals}) AND ended_at IS NOT NULL AND exit_reason IS NOT NULL AND ".$code('exit_reason', 48).')',
                'oenr_seal_chk' => $seal,
            ],
            self::JOBS => [
                'ojob_subject_chk' => $subject,
                'ojob_attempt_chk' => 'attempt BETWEEN 1 AND 3 AND step_index BETWEEN 0 AND 5',
                'ojob_medium_chk' => "(channel = 'assisted' AND medium IS NOT NULL AND medium IN ('whatsapp', 'voice')) OR (channel <> 'assisted' AND medium IS NULL)",
                // Todo canal que sale lleva su plantilla (D9-6); el simulado puede llevar la del paso.
                'ojob_template_chk' => "({$template}) AND (channel = 'simulated' OR template_version_id IS NOT NULL)",
                'ojob_window_chk' => 'expires_at > not_before',
                // La reserva: nunca negativa y CERO en sombra o por el canal simulado (no cuentan, D9-8).
                'ojob_budget_chk' => "reserved_units >= 0 AND reserved_micros >= 0 AND ((mode = 'live' AND channel <> 'simulated') OR (reserved_units = 0 AND reserved_micros = 0))",
                'ojob_cancel_chk' => "(status = 'canceled' AND cancel_reason IS NOT NULL AND ".$code('cancel_reason', 48).") OR (status <> 'canceled' AND cancel_reason IS NULL)",
                'ojob_claimed_chk' => "(status = 'planned' AND claimed_at IS NULL) OR (status IN ('canceled', 'expired')) "
                    ."OR (status IN ('claimed', 'dispatching', 'started', 'simulated', 'failed_to_start', 'ambiguous') AND claimed_at IS NOT NULL)",
                // Las referencias del canal, de su canal y con el trabajo fuera; la reclamación del asistido, completa y sobre uno que salió.
                'ojob_refs_chk' => "(marketing_message_id IS NULL OR (channel = 'whatsapp_template' AND status IN ('started', 'ambiguous'))) "
                    ."AND (voice_call_id IS NULL OR (channel = 'voice_call' AND status IN ('started', 'ambiguous'))) "
                    .'AND ((assisted_claimed_by_admin_id IS NULL AND assisted_claimed_by_name IS NULL AND assisted_claimed_at IS NULL) '
                    ."OR (channel = 'assisted' AND status = 'started' AND assisted_claimed_by_admin_id IS NOT NULL AND assisted_claimed_by_name IS NOT NULL AND assisted_claimed_at IS NOT NULL))",
                // Liberar la reserva solo si no salió (cancelar o vencer la libera, D9-8).
                'ojob_release_chk' => "budget_released_at IS NULL OR status IN ('canceled', 'expired', 'failed_to_start')",
                // En sombra o por el canal simulado nunca sale nada; `simulated` solo es de ahí.
                'ojob_shadow_chk' => "(status <> 'simulated' OR mode = 'shadow' OR channel = 'simulated') "
                    ."AND ((mode = 'live' AND channel <> 'simulated') OR status NOT IN ('started', 'failed_to_start', 'ambiguous'))",
                'ojob_idempotency_chk' => $hex('idempotency_key'),
                // L9-30: el seudónimo del teléfono (HMAC `outreach-phone/1`) o nada; suprimido, nada.
                'ojob_phone_chk' => '(redacted_at IS NULL AND (phone_ref IS NULL OR '.$hex('phone_ref').')) OR (redacted_at IS NOT NULL AND phone_ref IS NULL)',
                'ojob_seal_chk' => $seal,
            ],
            self::DECISIONS => [
                'odec_subject_chk' => $subject,
                'odec_level_rule_chk' => "(kind IN ('deny', 'defer') AND level IS NOT NULL) OR (kind IN ('enroll', 'plan') AND level IS NULL) OR kind IN ('exclude', 'cancel', 'exit', 'handoff')",
                // Como mucho 16 códigos del conjunto cerrado (JSON compacto); toda negativa lleva al menos uno.
                'odec_reasons_chk' => "json_typeof(reasons) = 'array' AND {$reasons} AND (kind IN ('enroll', 'plan') OR reasons::text <> '[]')",
                'odec_retry_chk' => "(kind = 'defer' AND retry_at IS NOT NULL) OR (kind <> 'defer' AND retry_at IS NULL)",
                'odec_facts_chk' => $json('facts_json', 'object', 4096).' AND '.$hex('facts_hash'),
                'odec_trace_chk' => $json('trace_json', 'array', 16384).' AND '.$hex('trace_hash'),
                'odec_template_chk' => $template,
                'odec_seal_chk' => $seal,
            ],
            self::OUTCOMES => [
                'oout_source_outcome_chk' => $sourceOutcomes,
                // Solo el clasificador es probabilístico (P9-6).
                'oout_classifier_chk' => "(source = 'classifier' AND deterministic = false AND confidence IS NOT NULL AND confidence BETWEEN 0 AND 1) "
                    ."OR (source <> 'classifier' AND deterministic = true AND confidence IS NULL)",
                'oout_staff_chk' => "(source = 'staff' AND recorded_by_admin_id IS NOT NULL AND recorded_by_name IS NOT NULL) "
                    ."OR (source <> 'staff' AND recorded_by_admin_id IS NULL AND recorded_by_name IS NULL)",
                // El motivo es un código del conjunto cerrado (D9-7); un `blocked` siempre dice cuál.
                'oout_codes_chk' => "source_key ~ '^[A-Za-z0-9_.:-]{1,96}$' AND (detail_code IS NULL OR ".$code('detail_code', 48).') '
                    ."AND (reason_code IS NULL OR reason_code ~ '^".self::REASON_CODE_PATTERN."$') AND (outcome <> 'blocked' OR reason_code IS NOT NULL)",
                // Lo que sale de un trabajo lleva su trabajo y su canal; sin trabajo, solo un hecho del CRM o el sistema (ventana vencida, éxito).
                'oout_links_chk' => "(job_id IS NOT NULL OR source IN ('crm_fact', 'system')) AND ((job_id IS NULL AND channel IS NULL) OR (job_id IS NOT NULL AND channel IS NOT NULL))",
                // D9-10: la próxima acción es una de las permitidas por el resultado (siempre, si las tiene); la hora, solo para seguir o esperar.
                'oout_next_action_chk' => '('.$nextActions.') AND (next_action_at IS NULL OR (next_action IS NOT NULL AND next_action IN (\'next_step\', \'retry_step\', \'wait\')))',
                // P9-6, la regla asimétrica: un resultado del clasificador solo frena o deriva.
                'oout_classifier_brakes_chk' => "source <> 'classifier' OR (next_action IS NOT NULL AND next_action IN (".self::literalList(self::CLASSIFIER_TRANSITIONS).'))',
                'oout_subject_chk' => $subject,
            ],
            self::BRAKES => [
                'obrk_scope_ref_chk' => "(scope = 'global' AND scope_ref IS NULL) OR (scope = 'strategy' AND scope_ref IS NOT NULL AND scope_ref ~ '^".self::UUID_PATTERN."$') "
                    ."OR (scope = 'channel' AND scope_ref IS NOT NULL AND scope_ref IN (".self::literalList(self::BRAKE_CHANNELS).'))',
                // Abre una persona (con su motivo escrito) o el monitor (D9-14), cada uno con sus causas y su alcance.
                'obrk_opener_chk' => "(opened_by_type = 'person' AND opened_by_admin_id IS NOT NULL AND reason IN (".self::literalList(self::BRAKE_PERSON_REASONS).') AND '.$filled('note').') '
                    ."OR (opened_by_type = 'system' AND opened_by_admin_id IS NULL AND opened_by_person_id IS NULL AND reason IN (".self::literalList(self::BRAKE_SYSTEM_REASONS).'))',
                'obrk_cause_scope_chk' => "(reason <> 'opt_out_spike' OR scope = 'strategy') AND (reason NOT IN ('failure_spike', 'budget_breach') OR scope = 'channel') "
                    ."AND (reason <> 'unknown_send' OR scope = 'global') AND (reason <> 'chain_unreadable' OR scope IN ('global', 'strategy')) AND (reason <> 'drift' OR scope = 'strategy')",
                'obrk_release_chk' => '(released_at IS NULL AND released_by_admin_id IS NULL AND released_by_name IS NULL AND released_by_person_id IS NULL AND release_note IS NULL) '
                    .'OR (released_at IS NOT NULL AND released_at >= opened_at AND released_by_admin_id IS NOT NULL AND released_by_name IS NOT NULL AND '.$filled('release_note').')',
                // P9-20: nadie suelta un freno que abrió él mismo, ni con otra cuenta de la misma persona.
                'obrk_four_eyes_chk' => '(released_by_admin_id IS NULL OR opened_by_admin_id IS NULL OR released_by_admin_id <> opened_by_admin_id) '
                    .'AND (released_by_person_id IS NULL OR opened_by_person_id IS NULL OR released_by_person_id <> opened_by_person_id)',
            ],
            self::BUDGETS => [
                // Por canal, por estrategia o, L9-30, por teléfono (el tope de un contacto al día también por número).
                'obud_scope_chk' => "scope ~ '^(channel:(".implode('|', self::BRAKE_CHANNELS).')|strategy:'.self::UUID_PATTERN."|phone:[0-9a-f]{64})$'",
                // La reserva no pasa del techo: la que lo haría falla aquí aunque el UPDATE no lo hubiera comprobado.
                'obud_limits_chk' => 'cap_units >= 0 AND cap_micros >= 0 AND reserved_units BETWEEN 0 AND cap_units AND reserved_micros BETWEEN 0 AND cap_micros',
            ],
        ];
    }

    /**
     * Disparadores de fila en los dos motores: nombre → [tabla, evento, columnas
     * de `UPDATE OF` (o null), condición WHEN (o null), motivo]. `%DISTINCT%`
     * es `IS DISTINCT FROM` en PG e `IS NOT` en SQLite.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function rowTriggers(): array
    {
        $finales = self::literalList(self::JOB_FINAL_STATUSES);
        $unaVez = implode(' OR ', array_map(
            static fn (string $c): string => "(OLD.{$c} IS NOT NULL AND NEW.{$c} %DISTINCT% OLD.{$c})",
            self::JOB_ONCE_COLUMNS,
        ))." OR (OLD.claimed_at IS NOT NULL AND NEW.claimed_at %DISTINCT% OLD.claimed_at AND OLD.status NOT IN ({$finales}))";

        return [
            // Pasadas.
            'orun_forbid_delete' => [self::RUNS, 'DELETE', null, null, 'las pasadas no se borran'],
            'orun_forbid_immutable_update' => [self::RUNS, 'UPDATE', self::RUN_IMMUTABLE_COLUMNS, null, 'una pasada fija al empezar su tipo, su modo y su alcance'],
            'orun_closed_is_final' => [self::RUNS, 'UPDATE', null, "OLD.status <> 'running'", 'una pasada cerrada es definitiva'],
            'orun_status_forward' => [self::RUNS, 'UPDATE', ['status'], self::forwardOnly(self::RUN_TRANSITIONS, ['completed', 'failed', 'aborted']),
                'una pasada solo avanza: running → completed, failed o aborted'],
            'orun_starts_running' => [self::RUNS, 'INSERT', null, "NEW.status <> 'running' OR NEW.finished_at IS NOT NULL OR NEW.error_code IS NOT NULL",
                'una pasada nace running, sin cerrar'],
            // Cursores.
            'ocur_forbid_delete' => [self::CURSORS, 'DELETE', null, null, 'los cursores solo avanzan'],
            'ocur_forbid_immutable_update' => [self::CURSORS, 'UPDATE', self::CURSOR_IMMUTABLE_COLUMNS, null, 'un cursor no cambia de fuente'],
            'ocur_last_id_forward' => [self::CURSORS, 'UPDATE', ['last_id'], 'NEW.last_id < OLD.last_id', 'un cursor solo avanza (last_id nunca baja)'],
            // Inscripciones.
            'oenr_forbid_delete' => [self::ENROLLMENTS, 'DELETE', null, null, 'las inscripciones no se borran'],
            'oenr_forbid_immutable_update' => [self::ENROLLMENTS, 'UPDATE', self::ENROLLMENT_IMMUTABLE_COLUMNS, null,
                'una inscripción fija al nacer su persona, su ciclo, su finalidad, su ventana y su sello'],
            'oenr_closed_is_final' => [self::ENROLLMENTS, 'UPDATE', null, 'OLD.status IN ('.self::literalList(self::ENROLLMENT_FINAL_STATUSES).')',
                'una inscripción cerrada es definitiva'],
            'oenr_status_forward' => [self::ENROLLMENTS, 'UPDATE', ['status'], self::forwardOnly(self::ENROLLMENT_TRANSITIONS, self::ENROLLMENT_FINAL_STATUSES),
                'una inscripción solo avanza: active ⇄ waiting y de ahí a un final'],
            'oenr_counters_forward' => [self::ENROLLMENTS, 'UPDATE', ['step_index', 'touches'],
                'OLD.status NOT IN ('.self::literalList(self::ENROLLMENT_FINAL_STATUSES).') AND (NEW.step_index < OLD.step_index OR NEW.touches < OLD.touches)',
                'el paso y los contactos de una inscripción solo crecen'],
            'oenr_starts_open' => [self::ENROLLMENTS, 'INSERT', null,
                'NEW.status NOT IN ('.self::literalList(self::ENROLLMENT_OPEN_STATUSES).') OR NEW.step_index <> 0 OR NEW.touches <> 0 OR NEW.last_outcome IS NOT NULL '
                .'OR NEW.exit_reason IS NOT NULL OR NEW.ended_at IS NOT NULL OR NEW.redacted_at IS NOT NULL',
                'una inscripción nace active o waiting, en el paso 0, sin contactos ni resultado'],
            // Trabajos.
            'ojob_forbid_delete' => [self::JOBS, 'DELETE', null, null, 'los trabajos no se borran'],
            'ojob_forbid_immutable_update' => [self::JOBS, 'UPDATE', self::JOB_IMMUTABLE_COLUMNS, null,
                'un trabajo fija al planificarse su persona, su paso, su intento, su canal, su plantilla, su reserva y su sello'],
            'ojob_closed_is_final' => [self::JOBS, 'UPDATE', self::JOB_CLOSED_COLUMNS, 'OLD.status IN ('.self::literalList(self::JOB_FINAL_STATUSES).')',
                'un trabajo en un estado final no cambia de estado (solo recibe, una vez, la reclamación, la referencia del canal o la liberación de la reserva)'],
            'ojob_status_forward' => [self::JOBS, 'UPDATE', ['status'], self::forwardOnly(self::JOB_TRANSITIONS, self::JOB_FINAL_STATUSES),
                'un trabajo solo avanza: planned → claimed → dispatching → un final, o se cancela o vence antes de salir'],
            'ojob_refs_once' => [self::JOBS, 'UPDATE', [...self::JOB_ONCE_COLUMNS, 'claimed_at'], $unaVez,
                'un trabajo fija una sola vez su reclamación, las referencias del canal y la liberación de su reserva'],
            'ojob_not_before_forward' => [self::JOBS, 'UPDATE', ['not_before'],
                "NEW.not_before %DISTINCT% OLD.not_before AND OLD.status NOT IN ({$finales}) AND (OLD.status <> 'planned' OR NEW.not_before < OLD.not_before)",
                'un trabajo solo se aplaza (not_before nunca adelanta) y solo mientras está planned'],
            'ojob_starts_planned' => [self::JOBS, 'INSERT', null,
                "NEW.status <> 'planned' OR NEW.claimed_at IS NOT NULL OR NEW.cancel_reason IS NOT NULL OR NEW.marketing_message_id IS NOT NULL "
                .'OR NEW.voice_call_id IS NOT NULL OR NEW.assisted_claimed_by_admin_id IS NOT NULL OR NEW.assisted_claimed_at IS NOT NULL '
                .'OR NEW.budget_released_at IS NOT NULL OR NEW.redacted_at IS NOT NULL',
                'un trabajo nace planned, sin reclamar ni salir'],
            // Solo inserción.
            'odec_forbid_update' => [self::DECISIONS, 'UPDATE', null, null, 'las decisiones son de solo inserción'],
            'odec_forbid_delete' => [self::DECISIONS, 'DELETE', null, null, 'las decisiones son de solo inserción'],
            'oout_forbid_update' => [self::OUTCOMES, 'UPDATE', null, null, 'los resultados son de solo inserción'],
            'oout_forbid_delete' => [self::OUTCOMES, 'DELETE', null, null, 'los resultados son de solo inserción'],
            // Frenos.
            'obrk_forbid_delete' => [self::BRAKES, 'DELETE', null, null, 'los frenos se sueltan, no se borran'],
            'obrk_forbid_immutable_update' => [self::BRAKES, 'UPDATE', self::BRAKE_IMMUTABLE_COLUMNS, null,
                'un freno fija al abrirse su alcance, su causa, su nota y quién lo abrió'],
            'obrk_released_is_final' => [self::BRAKES, 'UPDATE', null, 'OLD.released_at IS NOT NULL', 'un freno se suelta una sola vez'],
            'obrk_starts_open' => [self::BRAKES, 'INSERT', null,
                'NEW.released_at IS NOT NULL OR NEW.released_by_admin_id IS NOT NULL OR NEW.released_by_person_id IS NOT NULL OR NEW.release_note IS NOT NULL',
                'un freno nace abierto'],
            // Reservas.
            'obud_forbid_delete' => [self::BUDGETS, 'DELETE', null, null, 'las reservas del día no se borran'],
            'obud_forbid_immutable_update' => [self::BUDGETS, 'UPDATE', self::BUDGET_IMMUTABLE_COLUMNS, null,
                'una reserva del día no cambia de día, de alcance ni de modo'],
        ];
    }

    /**
     * Solo SQLite: los equivalentes de `obrk_four_eyes_chk` y de
     * `obud_limits_chk` (la reserva que no se pasa vale también en la suite).
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function sqliteOnlyRowTriggers(): array
    {
        $fuera = 'NEW.reserved_units < 0 OR NEW.reserved_micros < 0 OR NEW.cap_units < 0 OR NEW.cap_micros < 0 '
            .'OR NEW.reserved_units > NEW.cap_units OR NEW.reserved_micros > NEW.cap_micros';

        return [
            'obrk_four_eyes' => [self::BRAKES, 'UPDATE', null,
                'NEW.released_by_admin_id = NEW.opened_by_admin_id OR NEW.released_by_person_id = NEW.opened_by_person_id',
                'un freno lo suelta otra cuenta y otra persona que quien lo abrió (P9-20)'],
            'obud_within_cap_insert' => [self::BUDGETS, 'INSERT', null, $fuera, 'una reserva del día no pasa de su techo'],
            'obud_within_cap_update' => [self::BUDGETS, 'UPDATE', null, $fuera, 'una reserva del día no pasa de su techo'],
        ];
    }

    /**
     * Guardas ENTRE TABLAS, en los dos motores: nombre → [tabla, evento,
     * columnas de `UPDATE OF` (o null), condición WHEN sin subconsultas (o
     * null), violación (`%T%` = `public.` en PG), motivo].
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string, 5: string}>
     */
    public static function crossTriggers(): array
    {
        $sello = static fn (string $alias): string => "{$alias}.strategy_id = NEW.strategy_id AND {$alias}.content_hash = NEW.content_hash "
            ."AND {$alias}.catalog_version = NEW.catalog_version AND {$alias}.evaluator_version = NEW.evaluator_version "
            ."AND {$alias}.studio_ref_kind = NEW.studio_ref_kind AND {$alias}.studio_version_uuid %NOT_DISTINCT% NEW.studio_version_uuid "
            ."AND {$alias}.studio_content_hash = NEW.studio_content_hash";
        $deLaInscripcion = static fn (string $extra = ''): string => 'NOT EXISTS (SELECT 1 FROM %T%'.self::ENROLLMENTS.' AS e WHERE e.id = NEW.enrollment_id '
            .'AND e.strategy_version_id = NEW.strategy_version_id AND e.subject_key = NEW.subject_key AND e.subject_ref = NEW.subject_ref AND e.mode = NEW.mode AND '
            .$sello('e').$extra.')';
        $deSuEstrategia = 'NOT EXISTS (SELECT 1 FROM %T%'.self::PUBLICATIONS.' AS p WHERE p.seq = NEW.publication_seq AND p.strategy_id = NEW.strategy_id)';

        return [
            'oenr_seal_matches_version' => [self::ENROLLMENTS, 'INSERT', null, null,
                'NOT EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.strategy_version_id AND '.$sello('v')." AND v.status IN ('CANARY', 'PUBLISHED'))",
                'una inscripción lleva el sello exacto de una versión viva (CANARY o PUBLISHED) de su estrategia'],
            'oenr_purpose_matches_strategy' => [self::ENROLLMENTS, 'INSERT', null, null,
                'NOT EXISTS (SELECT 1 FROM %T%'.self::STRATEGIES.' AS s WHERE s.id = NEW.strategy_id AND s.purpose = NEW.purpose)',
                'una inscripción tiene la finalidad de su estrategia'],
            'oenr_publication_of_strategy' => [self::ENROLLMENTS, 'INSERT', null, null, $deSuEstrategia,
                'una inscripción nombra una entrada de la cadena de su estrategia'],
            'ojob_seal_matches_enrollment' => [self::JOBS, 'INSERT', null, null,
                $deLaInscripcion(" AND e.status IN ('active', 'waiting')"),
                'un trabajo es de una inscripción abierta y lleva su persona, su modo y su sello'],
            'ojob_publication_of_strategy' => [self::JOBS, 'INSERT', null, null, $deSuEstrategia,
                'un trabajo nombra una entrada de la cadena de su estrategia'],
            'ojob_template_matches' => [self::JOBS, 'INSERT', null, 'NEW.template_version_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::TEMPLATES." AS t WHERE t.id = NEW.template_version_id AND t.content_hash = NEW.template_hash AND t.status = 'APPROVED' "
                ."AND (NEW.channel = 'simulated' OR (NEW.channel = 'assisted' AND t.channel = 'assisted') OR (NEW.channel = 'whatsapp_template' AND t.channel = 'whatsapp') "
                ."OR (NEW.channel = 'voice_call' AND t.channel = 'voice')))",
                'un trabajo usa una plantilla aprobada, de su huella exacta y de su canal'],
            'odec_seal_matches' => [self::DECISIONS, 'INSERT', null, null,
                '(NEW.enrollment_id IS NOT NULL AND '.$deLaInscripcion().') OR (NEW.enrollment_id IS NULL AND NOT EXISTS (SELECT 1 FROM %T%'.self::VERSIONS
                .' AS v WHERE v.id = NEW.strategy_version_id AND '.$sello('v').'))',
                'una decisión lleva el sello de su inscripción (o, sin ella, el de su versión) y su persona'],
            'odec_job_of_enrollment' => [self::DECISIONS, 'INSERT', null, 'NEW.job_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::JOBS.' AS j WHERE j.id = NEW.job_id AND j.enrollment_id = NEW.enrollment_id)',
                'una decisión sobre un trabajo es de la inscripción de ese trabajo'],
            'odec_template_matches' => [self::DECISIONS, 'INSERT', null, 'NEW.template_version_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::TEMPLATES.' AS t WHERE t.id = NEW.template_version_id AND t.content_hash = NEW.template_hash)',
                'una decisión cita una plantilla por su huella exacta'],
            'odec_publication_of_strategy' => [self::DECISIONS, 'INSERT', null, null, $deSuEstrategia,
                'una decisión nombra una entrada de la cadena de su estrategia'],
            'oout_of_enrollment' => [self::OUTCOMES, 'INSERT', null, null,
                'NOT EXISTS (SELECT 1 FROM %T%'.self::ENROLLMENTS.' AS e WHERE e.id = NEW.enrollment_id AND e.strategy_id = NEW.strategy_id '
                .'AND e.subject_key = NEW.subject_key AND e.subject_ref = NEW.subject_ref)',
                'un resultado lleva la estrategia y la persona de su inscripción'],
            'oout_job_of_enrollment' => [self::OUTCOMES, 'INSERT', null, 'NEW.job_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::JOBS.' AS j WHERE j.id = NEW.job_id AND j.enrollment_id = NEW.enrollment_id AND j.channel = NEW.channel)',
                'un resultado de un trabajo es de la inscripción de ese trabajo y de su canal'],
        ];
    }

    /**
     * «Solo hacia delante»: el WHEN de un `BEFORE UPDATE OF status` que salta
     * si el estado cambia a algo que el grafo no admite. Escribir el mismo
     * estado pasa; las filas cerradas son de su disparador de «definitivo».
     *
     * @param  array<string, list<string>>  $graph
     * @param  list<string>  $closed
     */
    public static function forwardOnly(array $graph, array $closed = []): string
    {
        $pares = [];
        foreach ($graph as $from => $to) {
            $pares[] = '(OLD.status = '.self::literal($from).' AND NEW.status IN ('.self::literalList($to).'))';
        }

        return 'OLD.status <> NEW.status'
            .($closed === [] ? '' : ' AND OLD.status NOT IN ('.self::literalList($closed).')')
            .' AND NOT ('.implode(' OR ', $pares).')';
    }

    public static function crossFunction(string $trigger): string
    {
        return self::CHECK_FUNCTION_PREFIX.$trigger;
    }

    /** @return list<string> */
    public static function ownFunctions(): array
    {
        return [...array_map(self::crossFunction(...), array_keys(self::crossTriggers())), self::FUNCTION_NAME];
    }

    private function createTables(): void
    {
        Schema::create(self::RUNS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('orun_uuid_unique');
            // ingest | sweep | dispatch | monitor | reconcile | replay.
            $table->string('kind', 10);
            $table->string('mode', 8);
            $table->foreignId('strategy_id')->nullable()->constrained(self::STRATEGIES, 'id', 'orun_strategy_fk')->restrictOnDelete();
            $table->string('trigger_key', 64)->nullable();
            // running → completed | failed | aborted, una vez.
            $table->string('status', 10);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // Recuentos acotados (evaluados, false, unknown, inscritos, negados por nivel…): los que no califican solo están aquí (P9-9).
            $table->json('counts')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['kind', 'id'], 'orun_kind_idx');
            $table->index(['strategy_id', 'id'], 'orun_strategy_idx');
        });

        Schema::create(self::CURSORS, function (Blueprint $table): void {
            // `commercial_events`, o una clave con su alcance (`…:<estrategia>`): un cursor nuevo es una fila nueva.
            $table->string('source', 96)->primary();
            $table->unsignedBigInteger('last_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create(self::ENROLLMENTS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('oenr_uuid_unique');
            $this->sealColumns($table, 'oenr');
            // La persona: su clave interna, su seudónimo y las referencias SIN FK (socio manda).
            $table->string('subject_key', 24);
            $table->char('subject_ref', 64);
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            // La identidad de la ocurrencia (D9-3): `exp:<fecha>`, la semana ISO, `rcv:<id>:<generación>`…
            $table->string('cycle_key', 96);
            $table->string('purpose', 12);
            // active | waiting | completed | exited | opted_out | handed_off | expired | canceled.
            $table->string('status', 10);
            $table->unsignedSmallInteger('step_index');
            $table->unsignedSmallInteger('touches');
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('window_ends_at');
            $table->string('last_outcome', 24)->nullable();
            $table->string('exit_reason', 48)->nullable();
            $table->timestamp('ended_at')->nullable();
            // Supresión 1581 (OPS9), con los disparadores apagados: la aplicación no la escribe.
            $table->timestamp('redacted_at')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            // UNA OCURRENCIA → UNA INSCRIPCIÓN (D9-8, prueba 14).
            $table->unique(['strategy_id', 'subject_key', 'cycle_key', 'mode'], 'oenr_occurrence_unique');
            $table->index(['status', 'next_due_at'], 'oenr_due_idx');
            $table->index(['subject_key', 'mode', 'status'], 'oenr_subject_idx');
            $table->index(['subject_ref', 'id'], 'oenr_subject_ref_idx');
            $table->index(['strategy_version_id', 'status'], 'oenr_version_idx');
            $table->index(['window_ends_at'], 'oenr_window_idx');
        });

        Schema::create(self::JOBS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('ojob_uuid_unique');
            $table->foreignId('enrollment_id')->constrained(self::ENROLLMENTS, 'id', 'ojob_enrollment_fk')->restrictOnDelete();
            $this->sealColumns($table, 'ojob');
            $table->string('subject_key', 24);
            $table->char('subject_ref', 64);
            // L9-30: el seudónimo del número principal (HMAC `outreach-phone/1`) para el tope diario por teléfono; NULL si no lo hay.
            $table->char('phone_ref', 64)->nullable();
            $table->unsignedSmallInteger('step_index');
            // Un reintento de negocio es una fila nueva (attempt + 1).
            $table->unsignedSmallInteger('attempt');
            // simulated | assisted (con su medio) | whatsapp_template | voice_call.
            $table->string('channel', 20);
            $table->string('medium', 10)->nullable();
            $table->foreignId('template_version_id')->nullable()->constrained(self::TEMPLATES, 'id', 'ojob_template_fk')->restrictOnDelete();
            $table->char('template_hash', 64)->nullable();
            // sha256(enrollment_uuid | paso | intento): viaja al canal.
            $table->char('idempotency_key', 64)->unique('ojob_idempotency_unique');
            $table->string('status', 16);
            $table->timestamp('not_before');
            $table->timestamp('expires_at');
            // La reserva del día de Bogotá (congelada al planificar) y su liberación, una vez.
            $table->date('budget_day');
            $table->unsignedSmallInteger('reserved_units');
            $table->unsignedBigInteger('reserved_micros');
            $table->timestamp('budget_released_at')->nullable();
            $table->string('cancel_reason', 48)->nullable();
            $table->timestamp('claimed_at')->nullable();
            // Las referencias del canal (W9, V9), una vez cada una.
            $table->unsignedBigInteger('marketing_message_id')->nullable()->unique('ojob_marketing_message_unique');
            $table->unsignedBigInteger('voice_call_id')->nullable()->unique('ojob_voice_call_unique');
            // El asistido: quién lo reclamó (una vez).
            $table->unsignedBigInteger('assisted_claimed_by_admin_id')->nullable();
            $table->string('assisted_claimed_by_name', 120)->nullable();
            $table->timestamp('assisted_claimed_at')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['enrollment_id', 'step_index', 'attempt'], 'ojob_attempt_unique');
            // El despachador reclama los vencidos.
            $table->index(['status', 'not_before'], 'ojob_due_idx');
            $table->index(['strategy_id', 'status'], 'ojob_strategy_idx');
            // Cupos y coste del día por canal.
            $table->index(['budget_day', 'channel', 'status'], 'ojob_day_idx');
            // El presupuesto de contacto por persona (D9-7) y el historial (L9-3: por subject_key).
            $table->index(['subject_key', 'created_at', 'id'], 'ojob_subject_idx');
            // L9-30: el tope diario por teléfono.
            $table->index(['phone_ref', 'budget_day'], 'ojob_phone_idx');
        });

        Schema::create(self::DECISIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('odec_uuid_unique');
            $table->foreignId('run_id')->nullable()->constrained(self::RUNS, 'id', 'odec_run_fk')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained(self::ENROLLMENTS, 'id', 'odec_enrollment_fk')->restrictOnDelete();
            $table->foreignId('job_id')->nullable()->constrained(self::JOBS, 'id', 'odec_job_fk')->restrictOnDelete();
            $this->sealColumns($table, 'odec');
            $table->string('subject_key', 24);
            $table->char('subject_ref', 64);
            // enroll | exclude | deny | defer | plan | cancel | exit | handoff.
            $table->string('kind', 10);
            // N1…N7 o NULL.
            $table->string('level', 2)->nullable();
            // ≤ 16 códigos del conjunto cerrado (D9-7).
            $table->json('reasons');
            $table->timestamp('retry_at')->nullable();
            // La foto de hechos DERIVADOS (≤ 4 KB) y la traza (≤ 16 KB), con sus huellas (D9-12: `outreach:replay`).
            $table->json('facts_json');
            $table->char('facts_hash', 64);
            $table->json('trace_json');
            $table->char('trace_hash', 64);
            $table->foreignId('template_version_id')->nullable()->constrained(self::TEMPLATES, 'id', 'odec_template_fk')->restrictOnDelete();
            $table->char('template_hash', 64)->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamp('redacted_at')->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            $table->index(['enrollment_id', 'id'], 'odec_enrollment_idx');
            $table->index(['run_id', 'id'], 'odec_run_idx');
            $table->index(['job_id'], 'odec_job_idx');
            $table->index(['subject_ref', 'id'], 'odec_subject_ref_idx');
            // El historial por persona (L9-3).
            $table->index(['subject_key', 'evaluated_at', 'id'], 'odec_subject_idx');
            // Métricas por estrategia (negativas por nivel y motivo) y la retención de 18 meses.
            $table->index(['strategy_id', 'created_at'], 'odec_strategy_idx');
            $table->index(['created_at'], 'odec_created_idx');
        });

        Schema::create(self::OUTCOMES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('oout_uuid_unique');
            $table->foreignId('job_id')->nullable()->constrained(self::JOBS, 'id', 'oout_job_fk')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained(self::ENROLLMENTS, 'id', 'oout_enrollment_fk')->restrictOnDelete();
            // L9-3: lo que necesita el historial, sin recomponerlo: la estrategia, la persona y el canal.
            $table->foreignId('strategy_id')->constrained(self::STRATEGIES, 'id', 'oout_strategy_fk')->restrictOnDelete();
            $table->string('subject_key', 24);
            $table->char('subject_ref', 64);
            $table->string('channel', 20)->nullable();
            // Los 18 de D9-10.
            $table->string('outcome', 24);
            // simulated | staff | crm_fact | system | meta_status | inbound_message | voice_call | classifier.
            $table->string('source', 16);
            // La clave de la fuente (el id del hecho, el uuid de la petición del personal…): la idempotencia.
            $table->string('source_key', 96);
            $table->boolean('deterministic');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('detail_code', 48)->nullable();
            // L9-3: el motivo (un código de D9-7) y la próxima acción (una transición de D9-10) con su hora.
            $table->string('reason_code', 64)->nullable();
            $table->string('next_action', 16)->nullable();
            $table->timestamp('next_action_at')->nullable();
            // Solo `staff`.
            $table->unsignedBigInteger('recorded_by_admin_id')->nullable();
            $table->string('recorded_by_name', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('redacted_at')->nullable();
            $table->timestamp('created_at');

            $table->unique(['enrollment_id', 'source', 'source_key'], 'oout_source_unique');
            $table->index(['job_id'], 'oout_job_idx');
            // El historial por persona (L9-3).
            $table->index(['subject_key', 'occurred_at', 'id'], 'oout_subject_idx');
        });

        Schema::create(self::BRAKES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('obrk_uuid_unique');
            // POST brakes es idempotente por la petición (el monitor abre sin ella).
            $table->uuid('client_request_id')->nullable()->unique('obrk_client_request_unique');
            // global (PARADA GLOBAL) | strategy (su uuid) | channel (assisted | whatsapp_template | voice_call).
            $table->string('scope', 10);
            $table->string('scope_ref', 36)->nullable();
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            // person | system (el monitor, D9-14).
            $table->string('opened_by_type', 8);
            $table->unsignedBigInteger('opened_by_admin_id')->nullable();
            $table->string('opened_by_name', 120);
            $table->unsignedBigInteger('opened_by_person_id')->nullable();
            $table->timestamp('opened_at');
            // Se suelta una vez, con nota, por otra cuenta y otra persona.
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by_admin_id')->nullable();
            $table->string('released_by_name', 120)->nullable();
            $table->unsignedBigInteger('released_by_person_id')->nullable();
            $table->string('release_note', 500)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create(self::BUDGETS, function (Blueprint $table): void {
            $table->id();
            // El día de Bogotá, el alcance (`channel:<canal>` | `strategy:<uuid>`) y el modo.
            $table->date('budget_day');
            $table->string('scope', 80);
            $table->string('mode', 8);
            // El techo (unidades y micro-US$) y lo reservado: reserved <= cap, siempre.
            $table->unsignedInteger('cap_units');
            $table->unsignedBigInteger('cap_micros');
            $table->unsignedInteger('reserved_units');
            $table->unsignedBigInteger('reserved_micros');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['budget_day', 'scope', 'mode'], 'obud_scope_unique');
        });
    }

    /** El sello de §5 (estrategia, versión, huella, entrada de la cadena, referencia del Studio, versiones y modo). */
    private function sealColumns(Blueprint $table, string $prefix): void
    {
        $table->foreignId('strategy_id')->constrained(self::STRATEGIES, 'id', "{$prefix}_strategy_fk")->restrictOnDelete();
        $table->foreignId('strategy_version_id')->constrained(self::VERSIONS, 'id', "{$prefix}_version_fk")->restrictOnDelete();
        $table->char('content_hash', 64);
        // La entrada de la cadena que la hizo viva (por su `seq`, único) y la parte.
        $table->unsignedInteger('publication_seq');
        $table->foreign('publication_seq', "{$prefix}_publication_fk")->references('seq')->on(self::PUBLICATIONS)->restrictOnDelete();
        $table->string('part', 8);
        $table->string('studio_ref_kind', 8);
        $table->uuid('studio_version_uuid')->nullable();
        $table->char('studio_content_hash', 64);
        $table->string('catalog_version', 48);
        $table->string('evaluator_version', 48);
        // shadow | live: parte de las claves (sombra y live no chocan).
        $table->string('mode', 8);
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        $this->createTables();
        $this->assertColumnsClassified();

        foreach (self::DOMAIN_CHECKS as $table => $checks) {
            foreach ($checks as $name => [$column, $values]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
            }
        }
        foreach (self::rowChecks() as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }

        // La función de guarda de la ejecución: el cuerpo de la de F con su
        // propio prefijo. Reemplazarla es idempotente (`migrate:fresh` no borra
        // funciones); si el rol no es su dueño, falla (42501).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION outreach_forbid() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'outreach: % (% en %)',
                    COALESCE(TG_ARGV[0], 'operación no permitida'), TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'P0001';
            END;
            $fn$
            SQL);

        foreach (self::rowTriggers() as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        foreach (self::TRUNCATE_TRIGGERS as $name => [$table, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE TRUNCATE ON {$table} "
                .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }

        foreach (self::crossTriggers() as $name => [$table, $event, $columns, $when, $violation, $reason]) {
            DB::statement(strtr(<<<'SQL'
                CREATE OR REPLACE FUNCTION {fn}() RETURNS trigger
                LANGUAGE plpgsql
                AS $fn$
                BEGIN
                    IF ({violation}) THEN
                        RAISE EXCEPTION 'outreach: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                            USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END;
                $fn$
                SQL, ['{fn}' => self::crossFunction($name), '{violation}' => self::dialect($violation, 'pgsql')]));
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::crossFunction($name).'('.self::literal($reason).')');
        }

        $this->createPartialUniqueIndexes();

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        // Los cerrojos ANTES de contar: nadie inserta entre el recuento y el
        // DROP. Con `--pretend` las lecturas devuelven vacío: se escribe igual.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        if ($tables !== []) {
            DB::statement('LOCK TABLE '.implode(', ', $tables).' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithRows();

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        // Después de los disparadores, que dependen de ellas.
        foreach (self::ownFunctions() as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    private function upSqlite(): void
    {
        $this->createTables();
        $this->assertColumnsClassified();

        foreach ([...self::rowTriggers(), ...self::sqliteOnlyRowTriggers()] as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'sqlite')
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, $table)).'); END');
        }
        foreach (self::crossTriggers() as $name => [$table, $event, $columns, $when, $violation, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} FOR EACH ROW "
                .'WHEN ('.($when === null ? '' : self::dialect($when, 'sqlite').') AND (').self::dialect($violation, 'sqlite').') '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, $table)).'); END');
        }

        $this->createPartialUniqueIndexes();
        // Sin roles: no hay privilegios que revocar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Sin un límite en la sesión, no se espera más de LOCK_TIMEOUT por un cerrojo (55P03 y se reintenta). */
    private function boundLockWait(): void
    {
        $sentencia = "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'";
        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if ((string) DB::scalar("SELECT current_setting('lock_timeout')") === '0') {
            DB::statement($sentencia);
        }
    }

    /** Cada columna de las seis tablas con estados es mutable o inmutable, explícitamente y una sola vez. */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            self::RUNS => [self::RUN_IMMUTABLE_COLUMNS, self::RUN_MUTABLE_COLUMNS],
            self::CURSORS => [self::CURSOR_IMMUTABLE_COLUMNS, self::CURSOR_MUTABLE_COLUMNS],
            self::ENROLLMENTS => [self::ENROLLMENT_IMMUTABLE_COLUMNS, self::ENROLLMENT_MUTABLE_COLUMNS],
            self::JOBS => [self::JOB_IMMUTABLE_COLUMNS, self::JOB_MUTABLE_COLUMNS],
            self::BRAKES => [self::BRAKE_IMMUTABLE_COLUMNS, self::BRAKE_MUTABLE_COLUMNS],
            self::BUDGETS => [self::BUDGET_IMMUTABLE_COLUMNS, self::BUDGET_MUTABLE_COLUMNS],
        ] as $table => [$immutable, $mutable]) {
            $declared = [...$immutable, ...$mutable];
            $actual = Schema::getColumnListing($table);

            $unclassified = array_values(array_diff($actual, $declared));
            $missing = array_values(array_diff($declared, $actual));
            $repeated = array_values(array_unique(array_diff_assoc($declared, array_unique($declared))));

            if ($unclassified !== [] || $missing !== [] || $repeated !== []) {
                throw new RuntimeException("{$table}: columnas sin clasificar [".implode(', ', $unclassified)
                    .'], que no existen ['.implode(', ', $missing).'] o clasificadas dos veces ['.implode(', ', $repeated)
                    .']. Cada columna es mutable o inmutable, explícitamente.');
            }
        }

        foreach ([[self::JOB_ONCE_COLUMNS, self::JOB_MUTABLE_COLUMNS, 'una vez'], [self::JOB_CLOSED_COLUMNS, self::JOB_MUTABLE_COLUMNS, 'congeladas al cerrar']] as [$sub, $of, $que]) {
            $fuera = array_values(array_diff($sub, $of));
            if ($fuera !== []) {
                throw new RuntimeException(self::JOBS.": columnas «{$que}» que no son mutables [".implode(', ', $fuera).'].');
            }
        }
    }

    /** El mismo texto en los dos motores; SQLite lo guarda tal cual en `sqlite_master`. */
    private function createPartialUniqueIndexes(): void
    {
        foreach (self::PARTIAL_UNIQUE_INDEXES as $name => [$table, $columns, $predicate]) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} (".implode(', ', $columns).") WHERE {$predicate}");
        }
    }

    /**
     * REVOKE del rol de la aplicación (UPDATE y DELETE en las de solo
     * inserción; DELETE en las de estados) y la comprobación posterior. Sin el
     * rol en el clúster no hay nada que revocar, salvo en producción.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $sentencias = [
            'REVOKE UPDATE, DELETE ON '.implode(', ', self::INSERT_ONLY_TABLES).' FROM "'.$role.'"',
            'REVOKE DELETE ON '.implode(', ', self::STATEFUL_TABLES).' FROM "'.$role.'"',
        ];

        if (DB::connection()->pretending()) {
            foreach ($sentencias as $sentencia) {
                DB::statement($sentencia);
            }

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("outreach (Hito 9): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE sobre las tablas de la ejecución no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        foreach ($sentencias as $sentencia) {
            DB::statement($sentencia);
        }

        $todavia = [];
        foreach (self::TABLES as $table) {
            $puede = DB::selectOne(
                "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                [$role, 'public.'.$table, $role, 'public.'.$table],
            );
            if (in_array($table, self::INSERT_ONLY_TABLES, true) && (int) $puede->u === 1) {
                $todavia[] = "UPDATE en {$table}";
            }
            if ((int) $puede->d === 1) {
                $todavia[] = "DELETE en {$table}";
            }
        }
        if ($todavia !== []) {
            throw new RuntimeException("outreach (Hito 9): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia)
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * Protección 11: con filas, revertir destruiría decisiones, resultados,
     * frenos y reservas (DROP no pasa por los disparadores de fila). Se niega
     * con un mensaje claro, antes de tocar nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        if (array_sum($rows) > 0) {
            throw new RuntimeException('outreach (Hito 9): down() se niega a revertir la ejecución con datos '
                ."({$rows[self::RUNS]} pasadas, {$rows[self::CURSORS]} cursores, {$rows[self::ENROLLMENTS]} inscripciones, {$rows[self::JOBS]} trabajos, "
                ."{$rows[self::DECISIONS]} decisiones, {$rows[self::OUTCOMES]} resultados, {$rows[self::BRAKES]} frenos y {$rows[self::BUDGETS]} reservas del día). "
                .'Revertirlo los destruiría y nunca borra nada. Para volver al código anterior no hace falta revertir el esquema (§9.2, nivel 3: las tablas se quedan).');
        }
    }

    /** @param  list<string>|null  $columns */
    private static function eventClause(string $event, ?array $columns): string
    {
        return $columns === null ? $event : $event.' OF '.implode(', ', $columns);
    }

    /** `WHEN (…) ` con su espacio final, o nada. */
    private static function whenClause(?string $when, string $driver): string
    {
        return $when === null ? '' : 'WHEN ('.self::dialect($when, $driver).') ';
    }

    /** `%DISTINCT%`, `%NOT_DISTINCT%` y `%T%` en el dialecto de cada motor. */
    private static function dialect(string $sql, string $driver): string
    {
        return strtr($sql, [
            '%NOT_DISTINCT%' => $driver === 'pgsql' ? 'IS NOT DISTINCT FROM' : 'IS',
            '%DISTINCT%' => $driver === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT',
            '%T%' => $driver === 'pgsql' ? 'public.' : '',
        ]);
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /** @param  list<string>  $values */
    private static function literalList(array $values): string
    {
        return implode(', ', array_map(self::literal(...), $values));
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("La ejecución del Hito 9 (outreach) solo existe para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
