<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HITO 8 (voz y telefonía), paquete DB8: LA TELEFONÍA. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md, D8-8, D8-10, D8-12, D8-13,
 * D8-17, D8-18, D8-19, «Contratos de datos» y la Enmienda 1 (E1-1 y E1-2); el
 * grafo de estados, alineado con `tests/fixtures/voice/state-transitions.json`
 * (DF-S2, DF-S3). Informe: plans/informes-h8/informe-db.md.
 *
 * Siete tablas NUEVAS, SOLO ADITIVAS E INERTES: crearlas no enciende nada
 * (protecciones 1 y 10), no alteran ninguna tabla existente y no referencian
 * NINGUNA tabla de fuera de este paquete: ni del motor (`marketing_calls` no se
 * toca ni es origen de un marcador, protección 5) ni del Studio (el registro de
 * canarios se lee y no se migra; aquí solo su uuid, sin FK). Es el espacio
 * `App\Services\Telephony` de D8-2: el de los modelos `App\Models\Voice\*`.
 *
 *  · voice_contacts     — los contactos de la fase 4b (D8-18): el teléfono
 *                         CIFRADO, su huella y sus 4 últimos dígitos, el origen
 *                         (lead | member | manual) con su id SIN FK, y la
 *                         petición de consentimiento pendiente de la segunda
 *                         persona. Se retiran; no se borran.
 *  · voice_rne_checks   — las consultas al RNE (solo inserción).
 *  · voice_calls        — una fila por llamada, con el SELLO de D8-8 escrito al
 *                         empezar, sus estados de D8-13 (solo hacia delante), la
 *                         huella y los 4 dígitos del otro extremo y NUNCA el
 *                         número. Toda llamada que el proveedor enseñe tiene
 *                         fila (E1-2): la entrante denegada nace `denied`, y el
 *                         TRAMO DE TRANSFERENCIA es una fila que apunta a su
 *                         llamada madre (`transfer_of_call_id`, RESTRICT).
 *  · voice_call_events  — los eventos de D8-13 (solo inserción), deduplicados
 *                         por (provider, kind, provider_event_key); sin texto de
 *                         transcripción. `provider` = elevenlabs | twilio |
 *                         laravel (los propios, como el ejemplo de DF-S7).
 *  · voice_consents     — consentimientos de voz (solo inserción); los que carga
 *                         una persona llevan CUATRO OJOS [P-18].
 *  · voice_exclusions   — exclusiones por huella (solo inserción); solo las
 *                         quita el procedimiento de supresión.
 *  · voice_brakes       — el freno (solo inserción): ABRIR y SOLTAR son filas
 *                         (`open` y `release`); una apertura se suelta una vez
 *                         (`vbr_release_once`) y lo que se suelta es una apertura
 *                         (`vbr_release_targets_open`).
 *
 * DATOS PERSONALES (Ley 1581, D8-17). Ningún teléfono en claro en ninguna
 * columna. Los teléfonos son su HUELLA (`*_hash`: el HMAC con la subclave
 * `voice-phone` de la `APP_KEY`, 64 hex) y sus 4 últimos dígitos; el número
 * completo solo CIFRADO en `voice_contacts.phone_encrypted` (cast `encrypted`),
 * que solo se borra. Los motivos, códigos y claves son CÓDIGOS
 * (`^[a-z][a-z0-9_]*$` o `[A-Za-z0-9_.:-]`), no texto libre; los JSON de códigos
 * tienen un tope de 4 KB (no cabe una transcripción). Quien actúa se guarda como
 * INSTANTÁNEA SIN FK a `admins`. La sección de voz de la supresión 1581 (OPS8)
 * cubre huellas, 4 dígitos, cifrados, evidencias, notas y nombres.
 *
 * LAS GUARDAS (la tercera capa), con su PROPIA función, `voice_forbid()`, como
 * el laboratorio del H4 con la suya: la telefonía no depende de las guardas de
 * F ni al revés (D8-2), y su texto de error empieza por «voice:».
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000.
 *  · Disparadores de fila en los dos motores, con el mismo nombre y el mismo
 *    texto; PG suma uno de sentencia contra TRUNCATE por tabla.
 *  · Columnas congeladas por LISTA (`BEFORE UPDATE OF …`), clasificadas una
 *    sola vez (up() lo comprueba).
 *  · `voice_calls`: nace `requested` (saliente), `active` o `denied`
 *    (entrante) o, un tramo, en el estado en que lo ve el proveedor
 *    (`vc_starts_live`), y SOLO AVANZA por el grafo CALL_TRANSITIONS
 *    (`vc_status_forward`): un estado final no cambia. La conversación, la
 *    llamada de Twilio, la observación del sello y el motivo de la política se
 *    fijan UNA vez, y el cupo de herramientas (D8-10: 20 por llamada) solo crece
 *    y solo con la llamada en `dialing` o `active` (E1-1; `vc_bindings_once`).
 *  · Solo en PG, los CHECK de dominio y de forma (huellas, 4 dígitos, sello
 *    completo o vacío, ids del proveedor, códigos…) y el de cuatro ojos de los
 *    consentimientos. En SQLite, los cuatro ojos tienen su disparador
 *    equivalente (`vcs_four_eyes`); los de forma los vigila el servicio y la
 *    puerta PG es obligatoria.
 *  · Índices únicos PARCIALES, mismo texto en los dos motores:
 *     — `vct_one_live`: un contacto vivo por teléfono;
 *     — `vc_one_live_outbound`: a lo sumo UNA saliente viva (requested,
 *       ambiguous, dialing o active) por número: el respaldo del cerrojo
 *       consultivo de D8-12, y una `ambiguous` bloquea el reintento hasta
 *       conciliarla. Los tramos no cuentan (dos transferencias a la vez a
 *       recepción son legítimas);
 *     — `vex_one_opt_out_per_call`: una revocación oral por llamada (la
 *       herramienta `no_volver_a_llamar` es idempotente).
 *  · UNIQUE de D8-12: `client_request_id` POR PERSONA, `provider_conversation_id`
 *    y `provider_call_sid`; y (`parent_call_id`, `attempt`) para los reintentos
 *    de negocio, que son filas nuevas.
 *
 * EL ROL DE LA APLICACIÓN pierde UPDATE y DELETE en las cinco de solo inserción
 * (eventos, consentimientos, exclusiones, consultas al RNE y frenos; protección
 * 10), con la comprobación posterior y la guarda de producción de DB7 y la misma
 * variable para ensayar con otro rol (`ULTRON_STUDIO_DB_APP_ROLE`). Contactos y
 * llamadas conservan UPDATE (su máquina de estados) y DELETE (lo para su
 * disparador).
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite): `json`, no `jsonb`; FKs
 * todas RESTRICT y nacidas con su tabla, todas entre tablas de este paquete;
 * `unsigned*` solo documental en PG. Índices para lo que la política consulta
 * en cada saliente (estado, frecuencia por huella, cupos del día, exclusiones,
 * RNE vigente) y para las FKs que se recorren (eventos de una llamada,
 * consentimientos de un contacto).
 *
 * PostgreSQL, cerrojos: tablas nuevas que nadie usa; ninguna FK sale de ellas,
 * así que no pide cerrojo sobre ninguna tabla existente. El REVOKE no toma
 * cerrojo de tabla.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración falla
 * y no queda registrada (en PG, la transacción de Laravel; en SQLite, la que abre
 * esta migración). `CREATE OR REPLACE FUNCTION`: `migrate:fresh` borra tablas
 * pero no funciones. Con `migrate --pretend` no se lee nada.
 *
 * Lo que NO cubre:
 *  · El dueño puede desactivar o borrar disparadores y devolver privilegios; el
 *    rol de la aplicación, no (42501). La supresión 1581 (OPS8) tiene que
 *    reconocer `voice_forbid()` como función de guarda para apagar SOLO los
 *    disparadores de UPDATE que la usan, en su transacción.
 *  · Que el canario sellado esté activo, que la huella sea la del número que se
 *    marca, que la reserva cuadre con el techo, que el consentimiento vigente
 *    sea el último, que una exclusión del RNE venga de una consulta `listed`, o
 *    quién suelta un freno que abrió su propia acción [por decidir, D8-22]: lo
 *    garantiza el servicio (B8) en la misma transacción.
 *  · Que la madre de un tramo no sea a su vez un tramo: lo garantiza el servicio.
 *  · Con dos violaciones en la misma sentencia, el motivo que se ve puede
 *    depender del motor (PG dispara por orden alfabético; SQLite, por orden
 *    inverso de creación); la sentencia falla igual en los dos.
 *  · Una restauración de SOLO DATOS sobre el esquema ya creado pasa por los
 *    disparadores: `vc_starts_live` rechaza las llamadas que no estén en su
 *    estado inicial (hace falta `--disable-triggers`, superusuario). La
 *    restauración completa crea los disparadores después de los datos.
 *  · SQLite: `INSERT OR REPLACE` borra sin disparar el DELETE; Laravel no lo usa.
 *
 * down() SE NIEGA si hay alguna fila en cualquiera de las siete tablas, antes de
 * tocar nada y con los cerrojos ya tomados, y NUNCA borra filas. Sin filas borra
 * las tablas y sus dos funciones. En producción no se ejecuta nunca.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'voice:';

    public const FUNCTION_NAME = 'voice_forbid';

    public const BRAKE_FUNCTION = 'voice_brake_release';

    public const CONTACTS = 'voice_contacts';

    public const RNE_CHECKS = 'voice_rne_checks';

    public const CALLS = 'voice_calls';

    public const EVENTS = 'voice_call_events';

    public const CONSENTS = 'voice_consents';

    public const EXCLUSIONS = 'voice_exclusions';

    public const BRAKES = 'voice_brakes';

    /** En orden de creación (cada una después de las que referencia); down() las borra al revés. */
    public const TABLES = [self::CONTACTS, self::RNE_CHECKS, self::CALLS, self::EVENTS, self::CONSENTS, self::EXCLUSIONS, self::BRAKES];

    /** Las de solo inserción (protección 10): sin UPDATE ni DELETE para la aplicación. */
    public const INSERT_ONLY_TABLES = [self::EVENTS, self::CONSENTS, self::EXCLUSIONS, self::RNE_CHECKS, self::BRAKES];

    public const REVOKED_PRIVILEGES = ['UPDATE', 'DELETE'];

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    /** D8-13 y Enmienda 1 (E1-2: `denied`), en orden. */
    public const CALL_STATUSES = ['requested', 'canceled', 'failed_to_start', 'ambiguous', 'dialing', 'active', 'denied', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    public const CALL_LIVE_STATUSES = ['requested', 'ambiguous', 'dialing', 'active'];

    public const CALL_FINAL_STATUSES = ['canceled', 'failed_to_start', 'denied', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    /**
     * Solo hacia delante (D8-13), con las decisiones del fixture de TEST8-a
     * (`state-transitions.json`) y la Enmienda 1:
     *  · DF-S2: el webhook puede llegar antes de que el job guarde `dialing`
     *    (se casa por `call_ref`): de `requested` a su final de llamada.
     *  · DF-S3: `ambiguous` se resuelve hacia delante (en curso o un final de
     *    llamada), nunca a `canceled` ni a `failed_to_start` (ya se intentó).
     *  · E1-1: una saliente contestada pasa de `dialing` a `active` con la
     *    primera herramienta o el primer evento de la conversación.
     *  · E1-2: `denied` (la entrante denegada en la iniciación) nace así y es
     *    final.
     * Los estados sin salidas son finales.
     *
     * @var array<string, list<string>>
     */
    public const CALL_TRANSITIONS = [
        'requested' => ['canceled', 'failed_to_start', 'ambiguous', 'dialing', 'completed', 'busy', 'no_answer', 'failed'],
        'ambiguous' => ['dialing', 'completed', 'busy', 'no_answer', 'failed'],
        'dialing' => ['active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'],
        'active' => ['completed', 'failed', 'hung_up'],
    ];

    /**
     * Cómo nace una llamada: la saliente que pide Laravel, `requested`; la
     * entrante, `active` (autorizada) o `denied` (E1-2); el TRAMO DE
     * TRANSFERENCIA (E1-2), en el estado en que lo ve el proveedor, nunca
     * pedido ni cancelado por Laravel.
     */
    public const LEG_INITIAL_STATUSES = ['dialing', 'active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    /** E1-1: las herramientas responden, y su cupo crece, solo con la llamada en curso. */
    public const TOOL_STATUSES = ['dialing', 'active'];

    /** D8-13, en orden. */
    public const EVENT_KINDS = [
        'outbound_requested', 'outbound_started', 'outbound_rejected', 'outbound_ambiguous', 'initiation_received',
        'post_call_transcription', 'post_call_audio_discarded', 'call_initiation_failure',
        'tool_call', 'guard_verdict', 'hangup_requested',
        'reconciled', 'seal_mismatch', 'cost_recorded', 'unknown_provider_call',
    ];

    /** Los que siempre son de una llamada que tiene su fila. */
    public const EVENT_KINDS_WITH_CALL = [
        'outbound_requested', 'outbound_started', 'outbound_rejected', 'outbound_ambiguous',
        'tool_call', 'guard_verdict', 'seal_mismatch', 'hangup_requested',
    ];

    /** El catálogo CERRADO de herramientas del H8 (D8-10). */
    public const TOOLS = ['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar'];

    /** D8-19: lo que abre el freno solo. */
    public const BRAKE_REASONS = ['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call'];

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    /** Lo que un contacto fija al registrarse. */
    public const CONTACT_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'phone_hash', 'phone_last4', 'source_type', 'source_id',
        'registered_by_admin_id', 'registered_by_name', 'registered_by_role', 'created_at',
    ];

    /** El cifrado (solo hacia NULL), el estado, la petición de consentimiento pendiente y la retirada. */
    public const CONTACT_MUTABLE_COLUMNS = [
        'phone_encrypted', 'status',
        'consent_request_source', 'consent_request_evidence', 'consent_requested_by_admin_id',
        'consent_requested_by_name', 'consent_requested_by_role', 'consent_requested_at',
        'retired_by_admin_id', 'retired_by_name', 'retired_by_role', 'retired_at', 'retire_reason',
        'status_changed_at', 'updated_at',
    ];

    /** Lo que una llamada fija al empezar: quién, a quién, el sello y la reserva. */
    public const CALL_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'call_ref', 'direction', 'target_source', 'canary_number_uuid', 'voice_contact_id',
        'remote_hash', 'remote_last4', 'caller_authorized',
        'client_request_id', 'requested_by_type', 'requested_by_admin_id', 'requested_by_name', 'requested_by_role',
        'attempt', 'parent_call_id', 'transfer_of_call_id',
        'voice_publication_seq', 'part', 'stamp_state', 'studio_version_uuid', 'studio_content_hash',
        'voice_profile_hash', 'spec_hash', 'payload_hash', 'compiler_version', 'provider', 'provider_agent_id',
        'budget_day', 'reserved_micros', 'created_at',
    ];

    /** Lo que llega después: el estado, el proveedor (una vez), la observación (una vez), el coste y el cupo. */
    public const CALL_MUTABLE_COLUMNS = [
        'status', 'policy_reason', 'provider_conversation_id', 'provider_call_sid',
        'observed_agent_id', 'observed_version_id', 'observed_branch_id', 'seal_verified',
        'provider_cost_micros', 'carrier_cost_micros', 'duration_seconds', 'termination_reason', 'failure_code',
        'tool_calls', 'started_at', 'ended_at', 'status_changed_at', 'updated_at',
    ];

    /** Lo que se fija una vez (NULL → valor) y ya no cambia. */
    public const CALL_ONCE_COLUMNS = [
        'provider_conversation_id', 'provider_call_sid', 'observed_agent_id', 'observed_version_id',
        'observed_branch_id', 'seal_verified', 'policy_reason',
    ];

    public const MAX_TOOL_CALLS = 20;

    public const BRAKE_RELEASE_TRIGGER = 'vbr_release_targets_open';

    public const BRAKE_RELEASE_REASON = 'un freno se suelta una sola vez y lo que se suelta es una apertura (una fila release apunta a una open)';

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'vct_forbid_truncate' => [self::CONTACTS, 'los contactos de voz se retiran, no se borran'],
        'vrne_forbid_truncate' => [self::RNE_CHECKS, 'las consultas al RNE son de solo inserción'],
        'vc_forbid_truncate' => [self::CALLS, 'las llamadas no se borran'],
        'vce_forbid_truncate' => [self::EVENTS, 'los eventos de llamada son de solo inserción'],
        'vcs_forbid_truncate' => [self::CONSENTS, 'los consentimientos de voz son de solo inserción'],
        'vex_forbid_truncate' => [self::EXCLUSIONS, 'las exclusiones de voz son de solo inserción'],
        'vbr_forbid_truncate' => [self::BRAKES, 'el freno de voz es de solo inserción'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. */
    public const DOMAIN_CHECKS = [
        self::CONTACTS => [
            'vct_status_chk' => ['status', ['active', 'retired']],
        ],
        self::RNE_CHECKS => [
            'vrne_result_chk' => ['result', ['listed', 'not_listed']],
            'vrne_method_chk' => ['method', ['manual_portal', 'api']],
        ],
        self::CALLS => [
            'vc_direction_chk' => ['direction', ['inbound', 'outbound']],
            'vc_status_chk' => ['status', self::CALL_STATUSES],
            'vc_provider_chk' => ['provider', ['elevenlabs']],
        ],
        self::EVENTS => [
            'vce_provider_chk' => ['provider', ['elevenlabs', 'twilio', 'laravel']],
            'vce_kind_chk' => ['kind', self::EVENT_KINDS],
        ],
        self::CONSENTS => [
            'vcs_kind_chk' => ['kind', ['granted', 'revoked']],
            'vcs_channel_chk' => ['channel', ['voice_call']],
            'vcs_purpose_chk' => ['purpose', ['commercial']],
            'vcs_source_chk' => ['source', ['written_form', 'recorded_inbound_request', 'admin_with_evidence']],
        ],
        self::EXCLUSIONS => [
            'vex_source_chk' => ['source', ['rne', 'spoken_opt_out', 'lead_do_not_contact', 'lead_consent_denied', 'admin']],
        ],
        self::BRAKES => [
            'vbr_kind_chk' => ['kind', ['open', 'release']],
        ],
    ];

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Toda
     * columna que se compara sin `IS [NOT] NULL` delante es NOT NULL o va
     * protegida por un `IS NOT NULL` en la misma conjunción: ninguna expresión
     * puede dar NULL (que un CHECK aceptaría).
     *
     * @return array<string, array<string, string>>
     */
    public static function rowChecks(): array
    {
        $hex = static fn (string $c): string => "{$c} ~ '^[0-9a-f]{64}$'";
        $hexOrNull = static fn (string $c): string => "({$c} IS NULL OR {$c} ~ '^[0-9a-f]{64}$')";
        $codeOrNull = static fn (string $c, int $max): string => "({$c} IS NULL OR {$c} ~ '^[a-z][a-z0-9_]{0,".($max - 1)."}$')";
        $providerIdOrNull = static fn (string $c): string => "({$c} IS NULL OR {$c} ~ '^[A-Za-z0-9_-]{1,128}$')";
        $keyOrNull = static fn (string $c): string => "({$c} IS NULL OR {$c} ~ '^[A-Za-z0-9_.:-]{1,128}$')";
        $last4 = static fn (string $c): string => "{$c} ~ '^[0-9]{4}$'";
        $notLive = self::literalList(['requested', 'canceled', 'failed_to_start', 'ambiguous']);
        $sealColumns = ['part', 'provider_agent_id', 'studio_version_uuid', 'studio_content_hash', 'voice_profile_hash', 'spec_hash', 'payload_hash', 'compiler_version'];
        $sealSet = implode(' AND ', array_map(static fn (string $c): string => "{$c} IS NOT NULL", ['voice_publication_seq', ...$sealColumns]));
        $sealEmpty = implode(' AND ', array_map(static fn (string $c): string => "{$c} IS NULL", $sealColumns));

        return [
            self::CONTACTS => [
                'vct_source_chk' => "(source_type IN ('lead', 'member') AND source_id IS NOT NULL) OR (source_type = 'manual' AND source_id IS NULL)",
                'vct_phone_chk' => $hex('phone_hash').' AND '.$last4('phone_last4'),
                'vct_cipher_chk' => "(status = 'active' AND phone_encrypted IS NOT NULL) OR (status = 'retired' AND phone_encrypted IS NULL)",
                'vct_retire_chk' => "(status = 'active' AND retired_at IS NULL AND retired_by_admin_id IS NULL AND retire_reason IS NULL) OR (status = 'retired' AND retired_at IS NOT NULL AND retired_by_admin_id IS NOT NULL AND retire_reason IS NOT NULL)",
                'vct_consent_request_chk' => '(consent_request_source IS NULL AND consent_request_evidence IS NULL AND consent_requested_by_admin_id IS NULL AND consent_requested_by_name IS NULL AND consent_requested_by_role IS NULL AND consent_requested_at IS NULL) '
                    ."OR (status = 'active' AND consent_request_source IS NOT NULL AND consent_request_source IN ('written_form', 'admin_with_evidence') AND consent_request_evidence IS NOT NULL AND consent_requested_by_admin_id IS NOT NULL AND consent_requested_by_name IS NOT NULL AND consent_requested_at IS NOT NULL)",
            ],
            self::RNE_CHECKS => [
                'vrne_phone_chk' => $hex('phone_hash'),
                'vrne_actor_chk' => "actor_type IN ('admin', 'system') AND (actor_type = 'system' OR actor_admin_id IS NOT NULL) AND (method <> 'manual_portal' OR actor_type = 'admin')",
                'vrne_time_chk' => 'checked_at <= created_at',
            ],
            self::CALLS => [
                'vc_direction_status_chk' => "(direction = 'inbound' AND status IN ('active', 'denied', 'completed', 'failed', 'hung_up')) OR (direction = 'outbound' AND status <> 'denied')",
                // Entrante; saliente pedida por Laravel (canario o contacto); o tramo de transferencia (E1-2), solo saliente.
                'vc_target_chk' => "(direction = 'inbound' AND transfer_of_call_id IS NULL AND target_source IS NULL AND voice_contact_id IS NULL) "
                    ."OR (direction = 'outbound' AND transfer_of_call_id IS NULL AND target_source IS NOT NULL AND ((target_source = 'canary_registry' AND canary_number_uuid IS NOT NULL AND voice_contact_id IS NULL) "
                    ."OR (target_source = 'voice_contacts' AND voice_contact_id IS NOT NULL AND canary_number_uuid IS NULL))) "
                    ."OR (direction = 'outbound' AND transfer_of_call_id IS NOT NULL AND target_source IS NOT NULL AND target_source = 'transfer_destination' AND canary_number_uuid IS NULL AND voice_contact_id IS NULL)",
                'vc_remote_chk' => $hexOrNull('remote_hash')." AND (remote_last4 IS NULL OR remote_last4 ~ '^[0-9]{4}$') "
                    ."AND ((remote_hash IS NULL AND remote_last4 IS NULL) OR (remote_hash IS NOT NULL AND remote_last4 IS NOT NULL)) AND (direction = 'inbound' OR remote_hash IS NOT NULL)",
                // Nadie pide una entrante ni un tramo; una saliente la pide una persona (con su petición) o, solo en un reintento, la política.
                'vc_requester_chk' => "((direction = 'inbound' OR transfer_of_call_id IS NOT NULL) AND requested_by_type IS NULL AND requested_by_admin_id IS NULL AND requested_by_name IS NULL AND client_request_id IS NULL) "
                    ."OR (direction = 'outbound' AND transfer_of_call_id IS NULL AND requested_by_type IS NOT NULL AND ((requested_by_type = 'admin' AND requested_by_admin_id IS NOT NULL AND requested_by_name IS NOT NULL AND client_request_id IS NOT NULL) "
                    ."OR (requested_by_type = 'system' AND requested_by_admin_id IS NULL AND attempt > 1)))",
                'vc_attempt_chk' => 'attempt >= 1 AND ((attempt = 1 AND parent_call_id IS NULL) OR (attempt > 1 AND parent_call_id IS NOT NULL AND parent_call_id <> id)) '
                    ."AND ((direction = 'outbound' AND transfer_of_call_id IS NULL) OR attempt = 1)",
                // El sello de D8-8: completo (applied) o vacío; un tramo no lleva sello propio (inherited: el de su llamada madre).
                'vc_stamp_chk' => "stamp_state IN ('applied', 'off', 'fallback', 'inherited') AND (part IS NULL OR part IN ('canary', 'rest')) AND (voice_publication_seq IS NULL OR voice_publication_seq >= 1) "
                    ."AND ((stamp_state = 'applied' AND {$sealSet}) OR (stamp_state <> 'applied' AND {$sealEmpty})) "
                    ."AND ((transfer_of_call_id IS NOT NULL AND stamp_state = 'inherited' AND voice_publication_seq IS NULL) OR (transfer_of_call_id IS NULL AND stamp_state <> 'inherited')) "
                    ."AND (direction = 'inbound' OR transfer_of_call_id IS NOT NULL OR stamp_state = 'applied')",
                'vc_hashes_chk' => implode(' AND ', [
                    $hexOrNull('studio_content_hash'), $hexOrNull('voice_profile_hash'), $hexOrNull('spec_hash'), $hexOrNull('payload_hash'),
                    "(compiler_version IS NULL OR compiler_version ~ '^ultron-voice-compiler/[1-9][0-9]{0,5}$')",
                ]),
                'vc_provider_ids_chk' => implode(' AND ', [
                    $providerIdOrNull('provider_agent_id'), $providerIdOrNull('provider_conversation_id'),
                    "(provider_call_sid IS NULL OR provider_call_sid ~ '^CA[0-9a-f]{32}$')",
                    $providerIdOrNull('observed_agent_id'), $providerIdOrNull('observed_version_id'), $providerIdOrNull('observed_branch_id'),
                ]),
                // Entrante: llega con las dos. Tramo: solo su llamada de Twilio (la conversación es de la madre). Saliente: ninguna hasta marcar; después, al menos una.
                'vc_provider_binding_chk' => "(direction = 'inbound' AND provider_call_sid IS NOT NULL AND provider_conversation_id IS NOT NULL) "
                    ."OR (direction = 'outbound' AND transfer_of_call_id IS NOT NULL AND provider_call_sid IS NOT NULL AND provider_conversation_id IS NULL) "
                    ."OR (direction = 'outbound' AND transfer_of_call_id IS NULL AND ((status IN ({$notLive}) AND provider_call_sid IS NULL AND provider_conversation_id IS NULL) "
                    ."OR (status NOT IN ({$notLive}) AND (provider_call_sid IS NOT NULL OR provider_conversation_id IS NOT NULL))))",
                // `denied` nace en la iniciación: su fin llega (o no) con la post-llamada.
                'vc_ended_chk' => '(status IN ('.self::literalList(self::CALL_LIVE_STATUSES).') AND ended_at IS NULL) '
                    .'OR (status IN ('.self::literalList(array_values(array_diff(self::CALL_FINAL_STATUSES, ['denied']))).") AND ended_at IS NOT NULL) OR status = 'denied'",
                'vc_counters_chk' => 'tool_calls BETWEEN 0 AND '.self::MAX_TOOL_CALLS." AND (status <> 'denied' OR tool_calls = 0) AND reserved_micros >= 0 AND (provider_cost_micros IS NULL OR provider_cost_micros >= 0) "
                    .'AND (carrier_cost_micros IS NULL OR carrier_cost_micros >= 0) AND (duration_seconds IS NULL OR duration_seconds >= 0)',
                'vc_seal_chk' => 'seal_verified IS NULL OR (observed_agent_id IS NOT NULL AND ((seal_verified = true AND provider_agent_id IS NOT NULL AND observed_agent_id = provider_agent_id) '
                    .'OR (seal_verified = false AND (provider_agent_id IS NULL OR observed_agent_id <> provider_agent_id))))',
                'vc_codes_chk' => implode(' AND ', [$codeOrNull('policy_reason', 48), $codeOrNull('termination_reason', 48), $codeOrNull('failure_code', 48)]),
                // Entrante: autorizada (sin motivo, con sello) o `denied` (con su motivo). Saliente: solo una cancelada lleva motivo.
                'vc_authorization_chk' => "(direction = 'inbound' AND caller_authorized IS NOT NULL AND ((caller_authorized = true AND policy_reason IS NULL AND stamp_state = 'applied' AND status <> 'denied') "
                    ."OR (caller_authorized = false AND policy_reason IS NOT NULL AND status = 'denied'))) "
                    ."OR (direction = 'outbound' AND caller_authorized IS NULL AND ((status = 'canceled' AND policy_reason IS NOT NULL) OR (status <> 'canceled' AND policy_reason IS NULL)))",
                // E1-2: el tramo de transferencia no es una llamada de Laravel: ni observación del sello, ni herramientas, ni reserva propia (va en la de su madre), y nunca su propia madre.
                'vc_transfer_leg_chk' => 'transfer_of_call_id IS NULL OR (transfer_of_call_id <> id AND seal_verified IS NULL AND observed_agent_id IS NULL AND observed_version_id IS NULL '
                    .'AND observed_branch_id IS NULL AND tool_calls = 0 AND reserved_micros = 0)',
            ],
            self::EVENTS => [
                'vce_tool_chk' => "(kind = 'tool_call' AND tool IS NOT NULL AND tool IN (".self::literalList(self::TOOLS).")) OR (kind <> 'tool_call' AND tool IS NULL)",
                'vce_call_chk' => '(kind NOT IN ('.self::literalList(self::EVENT_KINDS_WITH_CALL).") OR call_id IS NOT NULL) AND (kind <> 'unknown_provider_call' OR call_id IS NULL)",
                'vce_actor_chk' => "actor_type IN ('provider', 'system', 'admin') AND (actor_type <> 'admin' OR (actor_admin_id IS NOT NULL AND actor_name IS NOT NULL))",
                'vce_codes_chk' => $keyOrNull('provider_event_key').' AND '.$codeOrNull('outcome', 32).' AND (codes IS NULL OR octet_length(codes::text) <= 4096)',
            ],
            self::CONSENTS => [
                'vcs_actor_chk' => "actor_type IN ('admin', 'system') AND ((actor_type = 'admin' AND recorded_by_admin_id IS NOT NULL) OR (actor_type = 'system' AND recorded_by_admin_id IS NULL AND source = 'recorded_inbound_request'))",
                'vcs_four_eyes_chk' => "kind = 'revoked' OR source = 'recorded_inbound_request' OR (recorded_by_admin_id IS NOT NULL AND confirmed_by_admin_id IS NOT NULL AND confirmed_by_name IS NOT NULL AND confirmed_by_admin_id <> recorded_by_admin_id)",
                'vcs_time_chk' => 'recorded_at <= created_at',
            ],
            self::EXCLUSIONS => [
                'vex_phone_chk' => $hex('phone_hash').' AND '.$last4('phone_last4'),
                'vex_actor_chk' => "actor_type IN ('admin', 'system') AND (actor_type = 'system' OR actor_admin_id IS NOT NULL)",
                'vex_source_shape_chk' => "(source <> 'spoken_opt_out' OR (actor_type = 'system' AND call_id IS NOT NULL)) AND (source <> 'admin' OR actor_type = 'admin') "
                    ."AND ((source = 'rne' AND rne_check_id IS NOT NULL) OR (source <> 'rne' AND rne_check_id IS NULL)) "
                    ."AND (source NOT IN ('lead_do_not_contact', 'lead_consent_denied') OR contact_id IS NOT NULL)",
            ],
            self::BRAKES => [
                'vbr_reason_chk' => 'reason IS NULL OR reason IN ('.self::literalList(self::BRAKE_REASONS).')',
                'vbr_shape_chk' => "(kind = 'open' AND brake_id IS NULL AND reason IS NOT NULL AND open_key IS NOT NULL AND note IS NULL AND client_request_id IS NULL AND actor_type = 'system') "
                    ."OR (kind = 'release' AND brake_id IS NOT NULL AND reason IS NULL AND open_key IS NULL AND call_id IS NULL AND event_id IS NULL AND evidence IS NULL "
                    ."AND note IS NOT NULL AND client_request_id IS NOT NULL AND actor_type = 'admin')",
                'vbr_actor_chk' => "(actor_type = 'system' AND actor_admin_id IS NULL) OR (actor_type = 'admin' AND actor_admin_id IS NOT NULL)",
                'vbr_codes_chk' => $keyOrNull('open_key').' AND (evidence IS NULL OR octet_length(evidence::text) <= 4096)',
            ],
        ];
    }

    /** Índices únicos parciales, en los dos motores: nombre → [tabla, columnas, predicado]. */
    public const PARTIAL_UNIQUE_INDEXES = [
        'vct_one_live' => [self::CONTACTS, ['phone_hash'], "status <> 'retired'"],
        'vc_one_live_outbound' => [self::CALLS, ['remote_hash'], "direction = 'outbound' AND transfer_of_call_id IS NULL AND status IN ('requested', 'ambiguous', 'dialing', 'active')"],
        'vex_one_opt_out_per_call' => [self::EXCLUSIONS, ['call_id'], "source = 'spoken_opt_out'"],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Laravel ya la envuelve en una transacción; esta (un SAVEPOINT
            // dentro de ella) hace up() atómico también fuera del migrador.
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

    /**
     * El rol de la aplicación al que se revoca: `iron_app`, o el de
     * `ULTRON_STUDIO_DB_APP_ROLE` en el entorno (la misma variable que DB7). Va
     * en el SQL como identificador: solo minúsculas, dígitos y `_`, hasta 63.
     */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('voice (Hito 8): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * Disparadores de fila en los dos motores: nombre → [tabla, evento, columnas
     * de `UPDATE OF` (o null), condición WHEN (o null), motivo]. `%DISTINCT%` es
     * `IS DISTINCT FROM` en PG e `IS NOT` en SQLite.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function rowTriggers(): array
    {
        $unaVez = implode(' OR ', array_map(
            static fn (string $c): string => "(OLD.{$c} IS NOT NULL AND NEW.{$c} %DISTINCT% OLD.{$c})",
            self::CALL_ONCE_COLUMNS,
        ));

        return [
            // Contactos.
            'vct_forbid_delete' => [self::CONTACTS, 'DELETE', null, null, 'los contactos de voz se retiran, no se borran'],
            'vct_forbid_immutable_update' => [self::CONTACTS, 'UPDATE', self::CONTACT_IMMUTABLE_COLUMNS, null,
                'un contacto de voz es inmutable en su huella, sus 4 dígitos, su origen y su registro'],
            'vct_retired_is_final' => [self::CONTACTS, 'UPDATE', null, "OLD.status = 'retired'", 'un contacto de voz retirado es definitivo'],
            'vct_cipher_only_shrinks' => [self::CONTACTS, 'UPDATE', ['phone_encrypted'],
                'NEW.phone_encrypted IS NOT NULL AND (OLD.phone_encrypted IS NULL OR NEW.phone_encrypted <> OLD.phone_encrypted)',
                'el cifrado del teléfono de un contacto solo se borra: no se escribe ni se cambia después del registro'],
            // Consultas al RNE.
            'vrne_forbid_update' => [self::RNE_CHECKS, 'UPDATE', null, null, 'las consultas al RNE son de solo inserción'],
            'vrne_forbid_delete' => [self::RNE_CHECKS, 'DELETE', null, null, 'las consultas al RNE son de solo inserción'],
            // Llamadas.
            'vc_forbid_delete' => [self::CALLS, 'DELETE', null, null, 'las llamadas no se borran'],
            'vc_forbid_immutable_update' => [self::CALLS, 'UPDATE', self::CALL_IMMUTABLE_COLUMNS, null,
                'una llamada fija al empezar su dirección, su destino, quién la pidió, su sello y su reserva'],
            'vc_bindings_once' => [self::CALLS, 'UPDATE', [...self::CALL_ONCE_COLUMNS, 'tool_calls'],
                "{$unaVez} OR NEW.tool_calls < OLD.tool_calls OR NEW.tool_calls > ".self::MAX_TOOL_CALLS
                .' OR (NEW.tool_calls > OLD.tool_calls AND OLD.status NOT IN ('.self::literalList(self::TOOL_STATUSES).'))',
                'una llamada fija una sola vez su conversación, su llamada de Twilio, la observación del sello y el motivo de la política, y su cupo de herramientas solo crece, hasta '
                .self::MAX_TOOL_CALLS.', y solo con la llamada en curso (dialing o active)'],
            'vc_status_forward' => [self::CALLS, 'UPDATE', ['status'], self::forwardOnly(self::CALL_TRANSITIONS),
                'una llamada solo avanza por los estados de D8-13 y un estado final no cambia'],
            'vc_starts_live' => [self::CALLS, 'INSERT', null,
                "NOT ((NEW.direction = 'outbound' AND NEW.transfer_of_call_id IS NULL AND NEW.status = 'requested') "
                ."OR (NEW.direction = 'inbound' AND NEW.transfer_of_call_id IS NULL AND NEW.status IN ('active', 'denied')) "
                ."OR (NEW.direction = 'outbound' AND NEW.transfer_of_call_id IS NOT NULL AND NEW.status IN (".self::literalList(self::LEG_INITIAL_STATUSES).'))) '
                .'OR NEW.tool_calls <> 0 OR NEW.seal_verified IS NOT NULL OR NEW.observed_agent_id IS NOT NULL OR (NEW.ended_at IS NOT NULL AND NEW.transfer_of_call_id IS NULL)',
                'una llamada nace requested (saliente), active o denied (entrante) o, si es un tramo de transferencia, en el estado en que lo ve el proveedor; sin herramientas usadas ni observación del sello'],
            // Eventos.
            'vce_forbid_update' => [self::EVENTS, 'UPDATE', null, null, 'los eventos de llamada son de solo inserción'],
            'vce_forbid_delete' => [self::EVENTS, 'DELETE', null, null, 'los eventos de llamada son de solo inserción'],
            // Consentimientos.
            'vcs_forbid_update' => [self::CONSENTS, 'UPDATE', null, null, 'los consentimientos de voz son de solo inserción'],
            'vcs_forbid_delete' => [self::CONSENTS, 'DELETE', null, null, 'los consentimientos de voz son de solo inserción'],
            // Exclusiones.
            'vex_forbid_update' => [self::EXCLUSIONS, 'UPDATE', null, null, 'las exclusiones de voz son de solo inserción'],
            'vex_forbid_delete' => [self::EXCLUSIONS, 'DELETE', null, null, 'las exclusiones de voz son de solo inserción'],
            // El freno.
            'vbr_forbid_update' => [self::BRAKES, 'UPDATE', null, null, 'el freno de voz es de solo inserción'],
            'vbr_forbid_delete' => [self::BRAKES, 'DELETE', null, null, 'el freno de voz es de solo inserción'],
        ];
    }

    /**
     * Solo SQLite: el equivalente de `vcs_four_eyes_chk`. Misma forma que
     * rowTriggers().
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function sqliteOnlyRowTriggers(): array
    {
        return [
            'vcs_four_eyes' => [self::CONSENTS, 'INSERT', null,
                "NEW.kind = 'granted' AND NEW.source <> 'recorded_inbound_request' AND (NEW.recorded_by_admin_id IS NULL OR NEW.confirmed_by_admin_id IS NULL OR NEW.confirmed_by_admin_id = NEW.recorded_by_admin_id)",
                'un consentimiento de voz cargado por una persona lo confirma otra (cuatro ojos)'],
        ];
    }

    /**
     * «Solo hacia delante»: el WHEN de un `BEFORE UPDATE OF status` que salta
     * si el estado cambia a algo que el grafo no admite (también a un valor que
     * no es un estado). Escribir el mismo estado no cambia nada y pasa. Un
     * estado sin salidas en el grafo es final. El texto vale para los dos
     * motores.
     *
     * @param  array<string, list<string>>  $graph
     */
    public static function forwardOnly(array $graph): string
    {
        $pares = [];
        foreach ($graph as $from => $to) {
            $pares[] = '(OLD.status = '.self::literal($from).' AND NEW.status IN ('.self::literalList($to).'))';
        }

        return 'OLD.status <> NEW.status AND NOT ('.implode(' OR ', $pares).')';
    }

    private function createTables(): void
    {
        Schema::create(self::CONTACTS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('client_request_id')->unique();
            // HMAC `voice-phone` del E.164 (64 hex) y sus 4 últimos dígitos.
            $table->char('phone_hash', 64);
            $table->char('phone_last4', 4);
            // El E.164 CIFRADO (cast `encrypted`): NULL al retirar, y solo hacia NULL.
            $table->text('phone_encrypted')->nullable();
            // lead | member | manual, con su id como referencia SIN FK al motor.
            $table->string('source_type', 8);
            $table->unsignedBigInteger('source_id')->nullable();
            // active | retired (definitivo).
            $table->string('status', 10);
            // La petición de consentimiento pendiente de la SEGUNDA persona [P-18]:
            // todo NULL o todo puesto. Al confirmarla, `voice_consents` recibe la fila.
            $table->string('consent_request_source', 32)->nullable();
            $table->string('consent_request_evidence', 300)->nullable();
            $table->unsignedBigInteger('consent_requested_by_admin_id')->nullable();
            $table->string('consent_requested_by_name', 120)->nullable();
            $table->string('consent_requested_by_role', 60)->nullable();
            $table->timestamp('consent_requested_at')->nullable();
            $table->unsignedBigInteger('registered_by_admin_id');
            $table->string('registered_by_name', 120);
            $table->string('registered_by_role', 60)->nullable();
            $table->unsignedBigInteger('retired_by_admin_id')->nullable();
            $table->string('retired_by_name', 120)->nullable();
            $table->string('retired_by_role', 60)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('retire_reason', 500)->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['status', 'id'], 'vct_status_idx');
        });

        Schema::create(self::RNE_CHECKS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('client_request_id')->nullable()->unique();
            $table->char('phone_hash', 64);
            $table->foreignId('contact_id')->nullable()->constrained(self::CONTACTS)->restrictOnDelete();
            // Cuándo se consultó (el portal de la CRC; la vigencia es [P-14]).
            $table->timestamp('checked_at');
            // listed | not_listed.
            $table->string('result', 12);
            // manual_portal | api (el conector, cuando se confirme).
            $table->string('method', 16);
            // La referencia de la evidencia, sin teléfonos.
            $table->string('evidence_ref', 120);
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            $table->timestamp('created_at');

            // La consulta vigente de un teléfono.
            $table->index(['phone_hash', 'checked_at'], 'vrne_phone_idx');
        });

        Schema::create(self::CALLS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // El `call_ref` opaco de las variables dinámicas y de `user_id` (D8-11, D8-12).
            $table->uuid('call_ref')->unique();
            // inbound | outbound.
            $table->string('direction', 8);
            // D8-13 (CALL_STATUSES), solo hacia delante.
            $table->string('status', 16);
            // Salientes: canary_registry | voice_contacts (`marketing_calls`, nunca); los tramos, transfer_destination.
            $table->string('target_source', 24)->nullable();
            // El canario sellado (o el que llamó), por su uuid y SIN FK: el registro es del Studio y solo se lee.
            $table->uuid('canary_number_uuid')->nullable();
            $table->foreignId('voice_contact_id')->nullable()->constrained(self::CONTACTS)->restrictOnDelete();
            // El otro extremo: huella `voice-phone` y 4 dígitos, NUNCA el número. NULL solo en una entrante sin identificador.
            $table->char('remote_hash', 64)->nullable();
            $table->char('remote_last4', 4)->nullable();
            // Entrantes: la decisión de la iniciación (si/no) y, si es no, su motivo.
            $table->boolean('caller_authorized')->nullable();
            $table->string('policy_reason', 48)->nullable();
            // Salientes: la idempotencia es POR PERSONA (`vc_client_request_unique`).
            $table->uuid('client_request_id')->nullable();
            // admin | system (solo reintentos programados por la política).
            $table->string('requested_by_type', 8)->nullable();
            $table->unsignedBigInteger('requested_by_admin_id')->nullable();
            $table->string('requested_by_name', 120)->nullable();
            $table->string('requested_by_role', 60)->nullable();
            // Reintentos de negocio: filas nuevas.
            $table->unsignedSmallInteger('attempt');
            $table->foreignId('parent_call_id')->nullable()->constrained(self::CALLS)->restrictOnDelete();
            // E1-2: el tramo de transferencia apunta a su llamada madre (la conciliación lo reconoce y no abre el freno).
            $table->foreignId('transfer_of_call_id')->nullable()->constrained(self::CALLS)->restrictOnDelete();
            // EL SELLO (D8-8), escrito al empezar y congelado; `inherited` en un tramo.
            $table->unsignedInteger('voice_publication_seq')->nullable();
            $table->string('part', 8)->nullable();
            $table->string('stamp_state', 10);
            $table->uuid('studio_version_uuid')->nullable();
            $table->char('studio_content_hash', 64)->nullable();
            $table->char('voice_profile_hash', 64)->nullable();
            $table->char('spec_hash', 64)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->string('compiler_version', 48)->nullable();
            $table->string('provider', 16);
            $table->string('provider_agent_id', 128)->nullable();
            // El proveedor, una vez cada uno.
            $table->string('provider_conversation_id', 128)->nullable()->unique();
            $table->string('provider_call_sid', 34)->nullable()->unique();
            // Después (webhook post-llamada), una vez cada uno.
            $table->string('observed_agent_id', 128)->nullable();
            $table->string('observed_version_id', 128)->nullable();
            $table->string('observed_branch_id', 128)->nullable();
            $table->boolean('seal_verified')->nullable();
            // Coste (micro-US$): la reserva al empezar; lo real, del webhook y de la conciliación.
            $table->date('budget_day');
            $table->unsignedBigInteger('reserved_micros');
            $table->unsignedBigInteger('provider_cost_micros')->nullable();
            $table->unsignedBigInteger('carrier_cost_micros')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            // CÓDIGOS, no texto.
            $table->string('termination_reason', 48)->nullable();
            $table->string('failure_code', 48)->nullable();
            // Peticiones a las herramientas (D8-10): solo crece, hasta 20.
            $table->unsignedSmallInteger('tool_calls')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['requested_by_admin_id', 'client_request_id'], 'vc_client_request_unique');
            $table->unique(['parent_call_id', 'attempt'], 'vc_retry_unique');
            // Concurrencia (dialing + active), atascadas y ambiguas.
            $table->index(['status', 'id'], 'vc_status_idx');
            // Frecuencia por contacto (1 al día, [P-11]).
            $table->index(['remote_hash', 'created_at'], 'vc_remote_idx');
            // Cupos y coste del día de Bogotá.
            $table->index(['budget_day', 'direction'], 'vc_day_idx');
            // Los tramos de una llamada (E1-2).
            $table->index(['transfer_of_call_id'], 'vc_transfer_idx');
        });

        Schema::create(self::EVENTS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->nullable()->constrained(self::CALLS)->restrictOnDelete();
            // elevenlabs | twilio | internal.
            $table->string('provider', 16);
            // D8-13 (EVENT_KINDS).
            $table->string('kind', 32);
            // La clave de deduplicación del proveedor (conversation_id, IdempotencyToken…); un código, no texto.
            $table->string('provider_event_key', 128)->nullable();
            // tool_call: el nombre (catálogo cerrado) y el veredicto, nada más.
            $table->string('tool', 32)->nullable();
            $table->string('outcome', 32)->nullable();
            // Códigos y números de turno (guarda), coste… Nunca texto de transcripción (tope de 4 KB en PG).
            $table->json('codes')->nullable();
            // provider | system | admin.
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->string('actor_role', 60)->nullable();
            // Lo que dice el proveedor; created_at es cuándo llegó.
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at');

            $table->unique(['provider', 'kind', 'provider_event_key'], 'vce_dedupe_unique');
            $table->index(['call_id', 'id'], 'vce_call_idx');
            // La salud del webhook: la última entrega buena de un tipo.
            $table->index(['kind', 'id'], 'vce_kind_idx');
        });

        Schema::create(self::CONSENTS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('client_request_id')->nullable()->unique();
            $table->foreignId('contact_id')->constrained(self::CONTACTS)->restrictOnDelete();
            // granted | revoked.
            $table->string('kind', 8);
            // voice_call y commercial (D8-18): el de WhatsApp no vale para la voz.
            $table->string('channel', 16);
            $table->string('purpose', 16);
            // written_form | recorded_inbound_request | admin_with_evidence.
            $table->string('source', 32);
            // ≤ 300, PlainText estricto y sin teléfonos (lo valida el servicio).
            $table->string('evidence', 300);
            // admin | system.
            $table->string('actor_type', 8);
            // Quien cargó la evidencia, y cuándo.
            $table->unsignedBigInteger('recorded_by_admin_id')->nullable();
            $table->string('recorded_by_name', 120);
            $table->string('recorded_by_role', 60)->nullable();
            $table->timestamp('recorded_at');
            // La SEGUNDA persona (cuatro ojos), distinta de la primera.
            $table->unsignedBigInteger('confirmed_by_admin_id')->nullable();
            $table->string('confirmed_by_name', 120)->nullable();
            $table->string('confirmed_by_role', 60)->nullable();
            $table->timestamp('created_at');

            // El consentimiento vigente de un contacto: el último.
            $table->index(['contact_id', 'id'], 'vcs_contact_idx');
        });

        Schema::create(self::EXCLUSIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('client_request_id')->nullable()->unique();
            // Por huella `voice-phone`: vale para cualquier contacto o canario con ese número.
            $table->char('phone_hash', 64);
            $table->char('phone_last4', 4);
            // rne | spoken_opt_out | lead_do_not_contact | lead_consent_denied | admin.
            $table->string('source', 24);
            $table->foreignId('call_id')->nullable()->constrained(self::CALLS)->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained(self::CONTACTS)->restrictOnDelete();
            $table->foreignId('rne_check_id')->nullable()->constrained(self::RNE_CHECKS)->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            $table->timestamp('created_at');

            // Se consulta antes de cada saliente.
            $table->index(['phone_hash'], 'vex_phone_idx');
        });

        Schema::create(self::BRAKES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // open | release.
            $table->string('kind', 8);
            // release → la apertura que suelta, UNA vez (`vbr_release_once`).
            $table->foreignId('brake_id')->nullable()->constrained(self::BRAKES)->restrictOnDelete();
            // open: la causa (BRAKE_REASONS) y su clave de idempotencia (una por causa concreta).
            $table->string('reason', 32)->nullable();
            $table->string('open_key', 128)->nullable()->unique();
            $table->foreignId('call_id')->nullable()->constrained(self::CALLS)->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained(self::EVENTS)->restrictOnDelete();
            // Códigos, sin datos personales (tope de 4 KB en PG).
            $table->json('evidence')->nullable();
            // release: la nota obligatoria de la persona que lo suelta.
            $table->string('note', 500)->nullable();
            $table->uuid('client_request_id')->nullable()->unique();
            // open: system (lo abren solos); release: admin (lo suelta una persona).
            $table->string('actor_type', 8);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            $table->timestamp('created_at');

            $table->unique(['brake_id'], 'vbr_release_once');
        });
    }

    private function upPostgres(): void
    {
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

        // La función de guarda de la telefonía: el mismo cuerpo que la de F con
        // su propio prefijo. Reemplazarla es idempotente (`migrate:fresh` no
        // borra funciones); si el rol no es su dueño, falla (42501).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION voice_forbid() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'voice: % (% en %)',
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

        // Soltar: una fila release apunta a una apertura (open) que existe; una
        // que no existe la para la FK (23503), y la segunda vez, el UNIQUE de
        // `brake_id` (23505). Tabla cualificada (una temporal no la suplanta).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION voice_brake_release() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF NEW.kind IS DISTINCT FROM 'release' OR NEW.brake_id IS NULL
                   OR EXISTS (SELECT 1 FROM public.voice_brakes AS b WHERE b.id = NEW.brake_id AND b.kind <> 'open') THEN
                    RAISE EXCEPTION 'voice: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $fn$
            SQL);
        DB::statement('CREATE TRIGGER '.self::BRAKE_RELEASE_TRIGGER.' BEFORE INSERT ON '.self::BRAKES.' '
            ."FOR EACH ROW WHEN (NEW.kind = 'release' OR NEW.brake_id IS NOT NULL) "
            .'EXECUTE FUNCTION '.self::BRAKE_FUNCTION.'('.self::literal(self::BRAKE_RELEASE_REASON).')');

        $this->createPartialUniqueIndexes();

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
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
        DB::statement('DROP FUNCTION IF EXISTS '.self::BRAKE_FUNCTION.'()');
        DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCTION_NAME.'()');
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
        DB::statement('CREATE TRIGGER '.self::BRAKE_RELEASE_TRIGGER.' BEFORE INSERT ON '.self::BRAKES.' FOR EACH ROW '
            ."WHEN ((NEW.kind = 'release' OR NEW.brake_id IS NOT NULL) AND (NEW.kind IS NOT 'release' OR NEW.brake_id IS NULL "
            .'OR EXISTS (SELECT 1 FROM '.self::BRAKES." AS b WHERE b.id = NEW.brake_id AND b.kind <> 'open'))) "
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::BRAKE_RELEASE_REASON, 'INSERT', self::BRAKES)).'); END');

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

    /**
     * Cada columna de contactos y llamadas es mutable o inmutable,
     * explícitamente y una sola vez; las de una vez son mutables; las demás
     * tablas son de solo inserción enteras. Con `--pretend` no se lee nada.
     */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            self::CONTACTS => [self::CONTACT_IMMUTABLE_COLUMNS, self::CONTACT_MUTABLE_COLUMNS],
            self::CALLS => [self::CALL_IMMUTABLE_COLUMNS, self::CALL_MUTABLE_COLUMNS],
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

        $fueraDeMutables = array_values(array_diff(self::CALL_ONCE_COLUMNS, self::CALL_MUTABLE_COLUMNS));
        if ($fueraDeMutables !== []) {
            throw new RuntimeException(self::CALLS.': columnas «una vez» que no son mutables ['.implode(', ', $fueraDeMutables).'].');
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
     * REVOKE UPDATE, DELETE del rol de la aplicación sobre las cinco de solo
     * inserción, y la comprobación de que de verdad ya no puede (por tabla, por
     * columna y por pertenencia a otros roles). Sin el rol en el clúster no hay
     * nada que revocar, salvo en producción, donde es un error.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $revoke = 'REVOKE '.implode(', ', self::REVOKED_PRIVILEGES).' ON '.implode(', ', self::INSERT_ONLY_TABLES).' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($revoke);

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("voice (Hito 8): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE de UPDATE y DELETE sobre las tablas de solo inserción de la voz no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($revoke);

        $todavia = [];
        foreach (self::INSERT_ONLY_TABLES as $table) {
            $puede = DB::selectOne(
                "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                [$role, 'public.'.$table, $role, 'public.'.$table],
            );
            foreach (['UPDATE' => $puede->u, 'DELETE' => $puede->d] as $privilegio => $tiene) {
                if ((int) $tiene === 1) {
                    $todavia[] = "{$privilegio} en {$table}";
                }
            }
        }
        if ($todavia !== []) {
            throw new RuntimeException("voice (Hito 8): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia)
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * Protección 10: con filas, revertir destruiría el registro de llamadas,
     * consentimientos, exclusiones y frenos (DROP no pasa por los disparadores
     * de fila). Se niega con un mensaje claro, antes de tocar nada, y nunca
     * borra.
     */
    private function refuseWithRows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        if (array_sum($rows) > 0) {
            throw new RuntimeException('voice (Hito 8): down() se niega a revertir la telefonía con datos '
                ."({$rows[self::CONTACTS]} contactos, {$rows[self::RNE_CHECKS]} consultas al RNE, {$rows[self::CALLS]} llamadas, "
                ."{$rows[self::EVENTS]} eventos, {$rows[self::CONSENTS]} consentimientos, {$rows[self::EXCLUSIONS]} exclusiones "
                ."y {$rows[self::BRAKES]} filas del freno). Revertirlo los destruiría y nunca borra nada. "
                .'Para volver al código anterior no hace falta revertir el esquema (D8-20, nivel 4: las tablas se quedan).');
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
        if ($when === null) {
            return '';
        }

        return 'WHEN ('.str_replace('%DISTINCT%', $driver === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT', $when).') ';
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
        return new RuntimeException("La telefonía del Hito 8 solo existe para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
