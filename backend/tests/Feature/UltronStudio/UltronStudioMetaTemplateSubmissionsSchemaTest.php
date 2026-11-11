<?php

namespace Tests\Feature\UltronStudio;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * PLANTILLAS DE WHATSAPP DE META DESDE EL CRM, paquete 1a (L9-96;
 * `plans/PLANTILLAS_META_PLAN.md` §5.4, §5.6 y §5.7.1): la tabla nueva
 * `ultron_studio_meta_template_submissions` (migración `2026_11_15_100001`).
 *
 * En los dos motores (SQL a pelo, sin modelos: lo que se prueba es la BASE):
 *  - las columnas y los tipos del plan, sin datos de personas;
 *  - un envío NACE `SENDING`, sin nada observado, sin error y sin comprobar;
 *  - solo AVANZA (SENDING → SUBMITTED | FAILED | UNKNOWN; UNKNOWN → SUBMITTED |
 *    FAILED), FAILED es definitivo y libera la versión, el id de Meta se fija
 *    una vez, las columnas inmutables no cambian (ni con el mismo valor) y
 *    nada se borra;
 *  - las guardas entre tablas: plantilla de WhatsApp APROBADA, de su huella,
 *    nombre, idioma y categoría; quien envía, una persona vinculada viva;
 *  - un envío vivo por versión y un nombre + idioma vivo por versión, con el
 *    mismo WHERE en los dos motores;
 *  - `down()` se niega mientras la ampliación `2026_11_15_100002` la cite o
 *    con filas, y sin ellas la ida y vuelta deja la tabla idéntica.
 * Solo en PostgreSQL: los 10 CHECK (los que un disparador tapa, con
 * `violatedChecks`: sin apagar nada), las dos funciones propias INVOKER, la de
 * F intacta, el REVOKE DELETE comprobado (rol de prueba) y el `lock_timeout`.
 */
class UltronStudioMetaTemplateSubmissionsSchemaTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const SUBS = 'ultron_studio_meta_template_submissions';

    private const SUBS_MIGRATION = '2026_11_15_100001_create_ultron_studio_meta_template_submissions_table';

    private const AMPL_MIGRATION = '2026_11_15_100002_add_meta_api_source_to_ultron_studio_template_meta_checks';

    /** Una segunda conexión a la misma base, para sostener un cerrojo desde otra transacción. */
    private const OTRA_CONEXION = 'pgsql_plantillas_envios_cerrojo';

    /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
    private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';

    private const T = 'timestamp(0) without time zone';

    /** §5.7.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
    private const COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'template_id' => ['bigint', 'integer', false],
        'template_content_hash' => ['character(64)', 'varchar', false],
        'meta_name' => ['character varying(64)', 'varchar', false],
        'language' => ['character varying(5)', 'varchar', false],
        'category' => ['character varying(10)', 'varchar', false],
        'request_hash' => ['character(64)', 'varchar', false],
        'state' => ['character varying(10)', 'varchar', false],
        'meta_template_id' => ['character varying(32)', 'varchar', true],
        'meta_status' => ['character varying(24)', 'varchar', true],
        'meta_category' => ['character varying(16)', 'varchar', true],
        'meta_correct_category' => ['character varying(16)', 'varchar', true],
        'meta_rejected_reason' => ['character varying(32)', 'varchar', true],
        'meta_quality' => ['character varying(8)', 'varchar', true],
        'meta_body_hash' => ['character(64)', 'varchar', true],
        'meta_components_hash' => ['character(64)', 'varchar', true],
        'matches_studio' => ['boolean', 'tinyint', true],
        'mismatch_codes' => ['character varying(200)', 'varchar', true],
        'attester_unavailable' => ['boolean', 'tinyint', false],
        'error_code' => ['integer', 'integer', true],
        'error_subcode' => ['integer', 'integer', true],
        'error_user_message' => ['character varying(300)', 'varchar', true],
        'submitted_by_admin_id' => ['bigint', 'integer', false],
        'submitted_by_person_id' => ['bigint', 'integer', false],
        'submitted_by_name' => ['character varying(120)', 'varchar', false],
        'submitted_by_role' => ['character varying(60)', 'varchar', true],
        'submitted_at' => [self::T, 'datetime', false],
        'last_checked_at' => [self::T, 'datetime', true],
        'last_changed_at' => [self::T, 'datetime', true],
        'created_at' => [self::T, 'datetime', false],
        'updated_at' => [self::T, 'datetime', true],
    ];

    /** @var array<string, Migration> */
    private array $plantillasMigrations = [];

    protected function tearDown(): void
    {
        DB::purge(self::OTRA_CONEXION);
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        parent::tearDown();
    }

    // ── La tabla ────────────────────────────────────────────────────────────

    public function test_la_tabla_tiene_las_columnas_y_los_tipos_del_plan(): void
    {
        $tipo = $this->isPostgres() ? 0 : 1;
        $real = [];
        foreach (Schema::getColumns(self::SUBS) as $c) {
            $real[$c['name']] = [$this->isPostgres() ? $c['type'] : $c['type_name'], (bool) $c['nullable']];
        }
        $this->assertSame(array_map(static fn (array $c): array => [$c[$tipo], $c[2]], self::COLUMNS), $real);

        // Lo que la migración declara, clasificado una vez: toda columna es inmutable o mutable.
        $m = $this->plantillasMigration(self::SUBS_MIGRATION);
        $declaradas = [...$m::IMMUTABLE_COLUMNS, ...$m::MUTABLE_COLUMNS];
        sort($declaradas);
        $columnas = array_keys(self::COLUMNS);
        sort($columnas);
        $this->assertSame($columnas, $declaradas);

        // Sin datos de personas: ni teléfonos, ni documentos, ni el texto de la plantilla.
        foreach (array_keys(self::COLUMNS) as $columna) {
            $this->assertDoesNotMatchRegularExpression('/phone|document|body_text|body$|preview|content_json|email/', $columna);
        }
    }

    public function test_indices_unicos_y_parciales_con_el_mismo_where_en_los_dos_motores(): void
    {
        $indices = [];
        foreach (Schema::getIndexes(self::SUBS) as $i) {
            if (! $i['primary']) {
                $indices[$i['name']] = [$i['columns'], (bool) $i['unique']];
            }
        }
        ksort($indices);
        $this->assertSame([
            'usmsub_client_request_unique' => [['client_request_id'], true],
            'usmsub_one_live_per_meta_name' => [['meta_name', 'language'], true],
            'usmsub_one_live_per_template' => [['template_id'], true],
            'usmsub_uuid_unique' => [['uuid'], true],
        ], $indices);

        foreach (['usmsub_one_live_per_meta_name' => 'meta_name, language', 'usmsub_one_live_per_template' => 'template_id'] as $indice => $columnas) {
            if ($this->isPostgres()) {
                $this->assertSame("((state)::text <> 'FAILED'::text)", DB::scalar(
                    'SELECT pg_get_expr(i.indpred, i.indrelid) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$indice]), $indice);

                continue;
            }
            $this->assertSame("CREATE UNIQUE INDEX {$indice} ON ".self::SUBS." ({$columnas}) WHERE state <> 'FAILED'",
                DB::table('sqlite_master')->where('type', 'index')->where('name', $indice)->value('sql'));
        }
    }

    public function test_recuentos_y_nombres_de_las_guardas(): void
    {
        $m = $this->plantillasMigration(self::SUBS_MIGRATION);
        if ($this->isPostgres()) {
            $this->assertSame([
                'usmsub_failed_is_final', 'usmsub_forbid_delete', 'usmsub_forbid_immutable_update', 'usmsub_forbid_truncate', 'usmsub_meta_template_id_once',
                'usmsub_starts_sending', 'usmsub_state_forward', 'usmsub_submitter_is_bound', 'usmsub_template_approved_whatsapp',
            ], $this->nombres('SELECT tgname AS n FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass'));
            $this->assertSame([
                'usmsub_category_chk', 'usmsub_error_chk', 'usmsub_hashes_chk', 'usmsub_language_chk', 'usmsub_match_chk', 'usmsub_meta_name_chk',
                'usmsub_observed_chk', 'usmsub_state_chk', 'usmsub_state_shape_chk', 'usmsub_submitter_chk',
            ], $this->nombres("SELECT conname AS n FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass"));
            $this->assertSame($m::checkNames(), $this->nombres("SELECT conname AS n FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass"));
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass AND tgenabled <> 'O'", [self::SUBS]), 'ninguno apagado');

            return;
        }

        $this->assertSame($m::triggerNames('sqlite'), DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::SUBS)->orderBy('name')->pluck('name')->all());
        $this->assertCount(8, $m::triggerNames('sqlite'), 'los 9 de PG menos el de TRUNCATE');
    }

    // ── Nace SENDING y solo avanza ──────────────────────────────────────────

    public function test_un_envio_nace_sending_sin_nada_observado_ni_error(): void
    {
        $plantilla = $this->whatsappTemplate();
        $motivo = 'un envío a Meta nace SENDING, sin nada observado de Meta, sin error y sin comprobaciones (INSERT en '.self::SUBS.')';

        foreach ([
            ['state' => 'SUBMITTED', 'meta_template_id' => '1234567'], ['state' => 'UNKNOWN'], ['state' => 'FAILED'],
            ['meta_status' => 'APPROVED'], ['meta_template_id' => '1234567'], ['meta_body_hash' => hash('sha256', 'x')], ['matches_studio' => true],
            ['attester_unavailable' => true], ['error_code' => 100], ['error_user_message' => 'Nombre en uso'], ['last_checked_at' => now()], ['last_changed_at' => now()],
        ] as $cambio) {
            $this->assertGuarded(fn () => DB::table(self::SUBS)->insert($this->envio($plantilla, $cambio)), 'ultron_studio: '.$motivo, json_encode($cambio));
        }
        $this->assertSame(0, DB::table(self::SUBS)->count());

        $id = $this->insertEnvio($plantilla);
        $fila = DB::table(self::SUBS)->where('id', $id)->first();
        $this->assertSame(['SENDING', false, null], [$fila->state, (bool) $fila->attester_unavailable, $fila->meta_template_id]);
    }

    public function test_solo_avanza_failed_es_definitivo_y_el_id_de_meta_se_fija_una_vez(): void
    {
        $plantilla = $this->whatsappTemplate();
        $avanza = 'ultron_studio: un envío a Meta solo avanza: SENDING → SUBMITTED, FAILED o UNKNOWN; UNKNOWN → SUBMITTED o FAILED (UPDATE en '.self::SUBS.')';
        $definitivo = 'ultron_studio: un envío a Meta fallido es definitivo (la versión queda libre para un envío nuevo) (UPDATE en '.self::SUBS.')';
        $unaVez = 'ultron_studio: el id de Meta de un envío se fija una sola vez (UPDATE en '.self::SUBS.')';
        $actualiza = fn (int $id, array $cambio) => DB::table(self::SUBS)->where('id', $id)->update($cambio + ['updated_at' => now()]);

        // SENDING → UNKNOWN → SUBMITTED (con el id de Meta), y lo observado se sobrescribe.
        $id = $this->insertEnvio($plantilla);
        $this->assertAccepted(fn () => $actualiza($id, ['state' => 'UNKNOWN']), 'SENDING → UNKNOWN');
        $this->assertGuarded(fn () => $actualiza($id, ['state' => 'SENDING']), $avanza, 'UNKNOWN → SENDING');
        $this->assertAccepted(fn () => $actualiza($id, ['state' => 'SUBMITTED', 'meta_template_id' => '1234567890', 'meta_status' => 'PENDING', 'last_checked_at' => now()]), 'UNKNOWN → SUBMITTED');
        $this->assertAccepted(fn () => $actualiza($id, ['meta_status' => 'APPROVED', 'meta_category' => 'MARKETING', 'meta_quality' => 'GREEN', 'meta_body_hash' => hash('sha256', 'b'),
            'meta_components_hash' => hash('sha256', 'c'), 'matches_studio' => true, 'last_changed_at' => now()]), 'lo observado');
        $this->assertAccepted(fn () => $actualiza($id, ['matches_studio' => false, 'mismatch_codes' => 'body_mismatch,components_mismatch', 'attester_unavailable' => true]), 'no casa');
        $this->assertAccepted(fn () => $actualiza($id, ['meta_template_id' => '1234567890']), 'el mismo id otra vez');
        foreach (['FAILED', 'UNKNOWN', 'SENDING'] as $estado) {
            $this->assertGuarded(fn () => $actualiza($id, ['state' => $estado]), $avanza, "SUBMITTED → {$estado}");
        }
        $this->assertGuarded(fn () => $actualiza($id, ['meta_template_id' => '999']), $unaVez, 'otro id');
        $this->assertGuarded(fn () => $actualiza($id, ['meta_template_id' => null]), $unaVez, 'quitar el id');

        // SENDING → FAILED: definitivo, y libera la versión para un envío nuevo.
        $otra = $this->whatsappTemplate(['metaName' => 'renovacion_w9_b']);
        $fallido = $this->insertEnvio($otra);
        $this->assertAccepted(fn () => $actualiza($fallido, ['state' => 'FAILED', 'error_code' => 100, 'error_subcode' => 2388024, 'error_user_message' => 'Ya existe una plantilla con ese nombre']));
        foreach ([['state' => 'SUBMITTED', 'meta_template_id' => '1'], ['state' => 'UNKNOWN'], ['last_checked_at' => now()], ['error_user_message' => 'otro']] as $cambio) {
            $this->assertGuarded(fn () => $actualiza($fallido, $cambio), $definitivo, json_encode($cambio));
        }
        $this->assertAccepted(fn () => $this->insertEnvio($otra), 'la versión, libre tras FAILED');
        $this->assertSame(['FAILED', 'SENDING'], DB::table(self::SUBS)->where('template_id', $otra)->orderBy('id')->pluck('state')->all());
    }

    public function test_las_columnas_inmutables_no_cambian_ni_con_el_mismo_valor_y_nada_se_borra(): void
    {
        $m = $this->plantillasMigration(self::SUBS_MIGRATION);
        $id = $this->insertEnvio($this->whatsappTemplate());
        $inmutable = 'ultron_studio: un envío a Meta fija al nacer la versión, su huella, lo que se envió y quién lo envió (UPDATE en '.self::SUBS.')';

        foreach ($m::IMMUTABLE_COLUMNS as $columna) {
            $this->assertGuarded(fn () => DB::table(self::SUBS)->where('id', $id)->update([$columna => DB::raw($columna)]), $inmutable, $columna);
        }
        $this->assertGuarded(fn () => DB::table(self::SUBS)->where('id', $id)->delete(), 'ultron_studio: los envíos de plantillas a Meta no se borran (DELETE en '.self::SUBS.')');
        $this->assertGuarded(fn () => DB::table(self::SUBS)->delete(), 'ultron_studio: los envíos de plantillas a Meta no se borran (DELETE en '.self::SUBS.')', 'sin WHERE');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::SUBS), 'ultron_studio: los envíos de plantillas a Meta no se borran (TRUNCATE en '.self::SUBS.')');
        }
        $this->assertSame(1, DB::table(self::SUBS)->count());
        // Un UPDATE que no encuentra filas no dispara nada.
        $this->assertSame(0, DB::table(self::SUBS)->where('id', -1)->update(['meta_name' => 'nada']));
    }

    // ── Guardas entre tablas ────────────────────────────────────────────────

    public function test_la_version_es_una_plantilla_de_whatsapp_aprobada_y_quien_envia_una_persona_vinculada(): void
    {
        $plantilla = $this->whatsappTemplate();
        $deLaPlantilla = 'ultron_studio: un envío a Meta es de una plantilla de WhatsApp del Studio APROBADA, de su huella exacta y con su nombre en Meta, su idioma y su categoría (INSERT en '.self::SUBS.')';
        $vinculada = 'ultron_studio: quien envía una plantilla a Meta es una persona vinculada (su vínculo verificado y vivo) (INSERT en '.self::SUBS.')';

        $borrador = $this->insertTemplate(['content' => ['bodyPreview' => 'Hola {{1}}. Responde BAJA para no recibir más.', 'category' => 'marketing', 'language' => 'es',
            'metaName' => 'borrador_w9', 'optOutLine' => true, 'variables' => ['1' => 'first_name']]]);
        $asistida = $this->approvedTemplate(['channel' => 'assisted']);
        $sinVerificar = $this->insertBinding(5, $this->insertIdentity());
        $identidadSinVerificar = (int) DB::table('ultron_studio_person_bindings')->where('id', $sinVerificar)->value('identity_id');

        foreach ([
            'una plantilla en borrador' => [$borrador, []],
            'una plantilla que no es de WhatsApp' => [$asistida, ['meta_name' => 'asistida', 'language' => 'es', 'category' => 'marketing']],
            'otra huella' => [$plantilla, ['template_content_hash' => str_repeat('a', 64)]],
            'otro nombre en Meta' => [$plantilla, ['meta_name' => 'otro_nombre']],
            'otro idioma' => [$plantilla, ['language' => 'es_CO']],
            'otra categoría' => [$plantilla, ['category' => 'utility']],
            'una plantilla que no existe' => [$plantilla, ['template_id' => 999999]],
        ] as $que => [$id, $cambio]) {
            $this->assertGuarded(fn () => DB::table(self::SUBS)->insert($this->envio($id, $cambio)), $deLaPlantilla, $que);
        }
        foreach ([
            'una cuenta sin vínculo' => ['submitted_by_admin_id' => 99],
            'la cuenta de Ana con la persona de Beto' => ['submitted_by_person_id' => $this->people()[2]],
            'un vínculo sin verificar' => ['submitted_by_admin_id' => 5, 'submitted_by_person_id' => $identidadSinVerificar],
        ] as $que => $cambio) {
            $this->assertGuarded(fn () => DB::table(self::SUBS)->insert($this->envio($plantilla, $cambio)), $vinculada, $que);
        }
        $this->assertSame(0, DB::table(self::SUBS)->count());
        $this->assertAccepted(fn () => $this->insertEnvio($plantilla, ['submitted_by_admin_id' => 3, 'submitted_by_person_id' => $this->people()[3], 'submitted_by_name' => 'Caro']), 'Caro, verificada por Beto');
    }

    public function test_un_envio_vivo_por_version_y_un_nombre_de_meta_vivo_por_version(): void
    {
        $plantilla = $this->whatsappTemplate();
        $this->insertEnvio($plantilla);
        // La misma versión trae por fuerza el mismo nombre e idioma (la guarda entre tablas lo exige): chocan los DOS índices y cada
        // motor nombra uno; el de la versión es la segunda barrera. Su existencia, columna y WHERE los fija la prueba de índices.
        $this->assertRejected(fn () => $this->insertEnvio($plantilla), $this->isPostgres() ? '23505' : '23000',
            $this->isPostgres() ? 'usmsub_one_live_per_' : 'UNIQUE constraint failed: '.self::SUBS.'.', 'segundo envío vivo de la misma versión');

        // Otra versión con el MISMO nombre de Meta e idioma: Meta tendría dos plantillas con un nombre.
        $mismoNombre = $this->whatsappTemplate(['bodyPreview' => 'Hola {{1}}, tu plan termina el {{2}}. Responde BAJA para no recibir más mensajes.']);
        $this->assertNotSame($plantilla, $mismoNombre);
        $this->assertUniqueRejects(fn () => $this->insertEnvio($mismoNombre), 'usmsub_one_live_per_meta_name', self::SUBS, ['meta_name', 'language'], 'mismo nombre e idioma vivos');

        // Con el primero FAILED, los dos quedan libres (los índices son parciales).
        DB::table(self::SUBS)->where('template_id', $plantilla)->update(['state' => 'FAILED', 'updated_at' => now()]);
        $this->assertAccepted(fn () => $this->insertEnvio($mismoNombre), 'el nombre, libre tras FAILED');
        $this->assertUniqueRejects(fn () => $this->insertEnvio($plantilla), 'usmsub_one_live_per_meta_name', self::SUBS, ['meta_name', 'language'], 'ahora el nombre lo tiene la otra');
    }

    // ── Solo PostgreSQL ─────────────────────────────────────────────────────

    public function test_solo_en_postgres_los_check_de_dominio_y_de_forma(): void
    {
        $this->requirePostgres('los CHECK de la tabla solo existen en PostgreSQL (como W9).');
        $plantilla = $this->whatsappTemplate();
        // La tabla de prueba (LIKE) no copia los valores por defecto: el de `attester_unavailable`, explícito.
        $base = $this->envio($plantilla, ['attester_unavailable' => false]);
        $sometido = ['state' => 'SUBMITTED', 'meta_template_id' => '1234567890', 'meta_status' => 'APPROVED', 'matches_studio' => true];

        // Los que un disparador tapa al insertar o actualizar: la expresión, evaluada sobre una fila de prueba (sin apagar nada).
        $this->assertSame([], $this->violatedChecks(self::SUBS, $base));
        $this->assertSame([], $this->violatedChecks(self::SUBS, array_merge($base, $sometido)));
        foreach ([
            'usmsub_state_chk' => ['state' => 'PENDING'],
            'usmsub_language_chk' => ['language' => 'en_US'],
            // Cabe en la columna (10) y no es del dominio: así salta el CHECK y no el tamaño (22001).
            'usmsub_category_chk' => ['category' => 'promo'],
            'usmsub_meta_name_chk' => ['meta_name' => 'Con Mayúsculas'],
        ] as $check => $cambio) {
            $this->assertSame([$check], $this->violatedChecks(self::SUBS, array_merge($base, $cambio)), $check);
        }

        // Los demás, por la base de verdad.
        $this->assertRejected(fn () => DB::table(self::SUBS)->insert($this->envio($plantilla, ['request_hash' => strtoupper(hash('sha256', 'x'))])), '23514', 'usmsub_hashes_chk');
        $this->assertRejected(fn () => DB::table(self::SUBS)->insert($this->envio($plantilla, ['submitted_by_name' => '   '])), '23514', 'usmsub_submitter_chk');
        $id = $this->insertEnvio($plantilla);
        $actualiza = fn (array $cambio) => DB::table(self::SUBS)->where('id', $id)->update($cambio);
        foreach ([
            'usmsub_state_shape_chk' => [['state' => 'SUBMITTED'], ['state' => 'UNKNOWN', 'meta_status' => 'PENDING'], ['state' => 'FAILED', 'attester_unavailable' => true],
                ['state' => 'FAILED', 'matches_studio' => true]],
            'usmsub_observed_chk' => [['state' => 'SUBMITTED', 'meta_template_id' => 'abc'], ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'meta_status' => 'approved'],
                ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'meta_quality' => 'green'], ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'meta_rejected_reason' => 'INVALID FORMAT']],
            'usmsub_match_chk' => [['state' => 'SUBMITTED', 'meta_template_id' => '1', 'matches_studio' => false], ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'matches_studio' => true, 'mismatch_codes' => 'body_mismatch'],
                ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'matches_studio' => false, 'mismatch_codes' => 'Body_Mismatch'], ['state' => 'SUBMITTED', 'meta_template_id' => '1', 'matches_studio' => false, 'mismatch_codes' => 'a,,b']],
            'usmsub_hashes_chk' => [['state' => 'SUBMITTED', 'meta_template_id' => '1', 'meta_body_hash' => str_repeat('g', 64)]],
            'usmsub_error_chk' => [['state' => 'FAILED', 'error_code' => 0], ['state' => 'FAILED', 'error_subcode' => 5], ['state' => 'FAILED', 'error_user_message' => "una\nlínea"],
                ['state' => 'FAILED', 'error_user_message' => '  ']],
        ] as $check => $cambios) {
            foreach ($cambios as $cambio) {
                $this->assertRejected(fn () => $actualiza($cambio), '23514', $check, json_encode($cambio));
            }
        }
        $this->assertSame('SENDING', DB::table(self::SUBS)->where('id', $id)->value('state'));
        $this->assertAccepted(fn () => $actualiza($sometido + ['meta_category' => 'MARKETING', 'meta_rejected_reason' => 'NONE', 'meta_quality' => 'UNKNOWN']), 'un SUBMITTED bien formado');
    }

    public function test_solo_en_postgres_las_funciones_son_propias_invoker_y_la_de_f_no_cambia(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');
        $m = $this->plantillasMigration(self::SUBS_MIGRATION);

        $this->assertEqualsCanonicalizing(['ultron_studio_chk_usmsub_submitter_is_bound', 'ultron_studio_chk_usmsub_template_approved_whatsapp'], $m::ownFunctions());
        foreach (['ultron_studio_chk_usmsub_submitter_is_bound' => 'public.ultron_studio_person_bindings', 'ultron_studio_chk_usmsub_template_approved_whatsapp' => 'public.ultron_studio_message_templates'] as $funcion => $tabla) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertNotNull($f, $funcion);
            $this->assertSame(['plpgsql', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, (int) $f->definer, $f->owner], $funcion);
            $this->assertStringContainsString("FROM {$tabla} AS ", (string) $f->src, 'la tabla, cualificada');
        }
        // Los seis de fila y el de TRUNCATE usan la de F, que ni se redeclara ni cambia.
        $this->assertSame(7, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND t.tgrelid = ?::regclass AND p.proname = 'ultron_studio_forbid'", [self::SUBS]));
        $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        $this->assertStringNotContainsString('FUNCTION ultron_studio_forbid', (string) file_get_contents(database_path('migrations/'.self::SUBS_MIGRATION.'.php')));
    }

    public function test_solo_en_postgres_el_rol_de_la_aplicacion_escribe_y_avanza_pero_no_borra(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');
        $plantilla = $this->whatsappTemplate();
        $rol = $this->appRoleName();

        $this->withAppRole(function () use ($plantilla, $rol): void {
            // Las guardas entre tablas son INVOKER: el rol lee plantillas y vínculos como la aplicación de hoy.
            DB::statement('GRANT SELECT ON ultron_studio_message_templates, ultron_studio_person_bindings TO '.$rol);
            $this->plantillasMigration(self::AMPL_MIGRATION)->down();
            $this->plantillasMigration(self::SUBS_MIGRATION)->down();
            $this->plantillasMigration(self::SUBS_MIGRATION)->up();
            $this->plantillasMigration(self::AMPL_MIGRATION)->up();

            $this->assertSame([true, true, true, false, false], $this->privileges(self::SUBS), 'SELECT, INSERT y UPDATE sí; DELETE y TRUNCATE no');
            $fila = $this->envio($plantilla);
            $this->assertSame([null, 'deshecho'], $this->asApp(function () use ($fila): void {
                $id = (int) DB::table(self::SUBS)->insertGetId($fila);
                DB::table(self::SUBS)->where('id', $id)->update(['state' => 'UNKNOWN', 'updated_at' => now()]);
            }), 'la aplicación envía y avanza');
            $this->assertSame('42501', $this->asApp(fn () => DB::table(self::SUBS)->where('id', '>', 0)->delete())[0], 'la aplicación no borra');
        });
    }

    public function test_solo_en_postgres_up_falla_si_el_rol_todavia_puede_borrar(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            // Un DELETE que llega por PUBLIC: el REVOKE al rol no lo quita, y la comprobación lo tiene que ver.
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT DELETE ON TABLES TO PUBLIC');
            $this->plantillasMigration(self::AMPL_MIGRATION)->down();
            $this->plantillasMigration(self::SUBS_MIGRATION)->down();
            try {
                $this->plantillasMigration(self::SUBS_MIGRATION)->up();
                $this->fail('up() se aplicó con el rol de la aplicación pudiendo borrar');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede borrar en '.self::SUBS, $e->getMessage());
            }
            $this->assertFalse(Schema::hasTable(self::SUBS), 'no quedó a medias');
        });
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_mientras_la_ampliacion_la_cite(): void
    {
        $antes = $this->definicion();
        try {
            $this->plantillasMigration(self::SUBS_MIGRATION)->down();
            $this->fail('down() quitó la tabla con la ampliación aplicada');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('ultron_studio_template_meta_checks.submission_id exista (la ampliación 2026_11_15_100002 sigue aplicada): revierte primero esa', $e->getMessage());
        }
        $this->assertSame($antes, $this->definicion());
    }

    public function test_down_se_niega_con_filas_y_nunca_borra(): void
    {
        $this->insertEnvio($this->whatsappTemplate());
        $this->plantillasMigration(self::AMPL_MIGRATION)->down();
        $antes = $this->definicion();
        try {
            $this->plantillasMigration(self::SUBS_MIGRATION)->down();
            $this->fail('down() quitó la tabla con un envío');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('down() se niega a revertir los envíos de plantillas a Meta con datos (1 filas)', $e->getMessage());
        }
        $this->assertSame($antes, $this->definicion());
        $this->assertSame(1, DB::table(self::SUBS)->count());
    }

    public function test_ida_y_vuelta_sin_filas_deja_la_tabla_identica_y_los_recuentos_cuadran(): void
    {
        $sub = $this->plantillasMigration(self::SUBS_MIGRATION);
        $ampl = $this->plantillasMigration(self::AMPL_MIGRATION);
        $migrada = $this->definicion();
        $totales = $this->totales();

        $ampl->down();
        $sinAmpliar = $this->totales();
        $sub->down();
        $this->assertFalse(Schema::hasTable(self::SUBS));
        $d = $sub::FOOTPRINT_DELTA;
        $pg = $this->isPostgres();
        $this->assertSame([
            'tables' => $sinAmpliar['tables'] - $d['tables'],
            'triggers' => $sinAmpliar['triggers'] - $d[$pg ? 'pg_triggers' : 'sqlite_triggers'],
            'checks' => $sinAmpliar['checks'] - ($pg ? $d['pg_checks'] : 0),
            'partial' => $sinAmpliar['partial'] - $d['partial'],
            'functions' => $sinAmpliar['functions'] - ($pg ? 2 : 0),
        ], $this->totales(), 'sin la tabla: −1 tabla, −2 parciales, −9/−8 disparadores, −10 CHECK y −2 funciones');
        if ($pg) {
            $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"), 'la de F se queda');
        }

        $sub->up();
        $ampl->up();
        $this->assertSame($migrada, $this->definicion(), 'up() deja lo mismo que el migrate');
        $this->assertSame($totales, $this->totales());
    }

    public function test_solo_en_postgres_no_espera_sin_limite_un_cerrojo(): void
    {
        $this->requirePostgres('lock_timeout y los cerrojos de tabla son de PostgreSQL.');
        $antes = $this->definicion();
        config(['database.connections.'.self::OTRA_CONEXION => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection(self::OTRA_CONEXION);
        $otra->beginTransaction();
        $otra->statement('LOCK TABLE '.self::SUBS.' IN ACCESS SHARE MODE NOWAIT');
        try {
            DB::statement("SET LOCAL lock_timeout = '200ms'");
            $t0 = microtime(true);
            try {
                $this->plantillasMigration(self::SUBS_MIGRATION)->down();
                $this->fail('down() esperó el cerrojo o lo atravesó');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
            }
            $this->assertLessThan(5.0, microtime(true) - $t0);
        } finally {
            $otra->rollBack();
        }
        $this->assertSame($antes, $this->definicion(), 'no tocó nada');

        // Sin límite en la sesión, cada paso pone el suyo (5 s) para lo que queda de la transacción.
        foreach ([[self::AMPL_MIGRATION, 'down'], [self::SUBS_MIGRATION, 'down'], [self::SUBS_MIGRATION, 'up'], [self::AMPL_MIGRATION, 'up']] as [$migracion, $paso]) {
            DB::statement('SET LOCAL lock_timeout = 0');
            $this->plantillasMigration($migracion)->{$paso}();
            $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"), "{$migracion} {$paso}()");
        }
        $this->assertSame($antes, $this->definicion());
    }

    // ────────────────────────────────────────────────────────────────────────

    private function plantillasMigration(string $name): Migration
    {
        return $this->plantillasMigrations[$name] ??= require database_path("migrations/{$name}.php");
    }

    /**
     * Un envío recién nacido de la versión `$plantilla`, con lo que dice su
     * contenido, enviado por Ana (cuenta 1, vínculo verificado).
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function envio(int $plantilla, array $o = []): array
    {
        $t = DB::table('ultron_studio_message_templates')->where('id', $plantilla)->first(['content_json', 'content_hash', 'category']);
        $c = $t === null ? ['metaName' => 'no_existe', 'language' => 'es'] : (array) json_decode((string) $t->content_json, true);

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'template_id' => $plantilla,
            'template_content_hash' => (string) ($t->content_hash ?? str_repeat('0', 64)),
            'meta_name' => (string) ($c['metaName'] ?? 'sin_nombre'),
            'language' => (string) ($c['language'] ?? 'es'),
            'category' => (string) ($t->category ?? 'marketing'),
            'request_hash' => hash('sha256', 'cuerpo-canonico-'.$plantilla),
            'state' => 'SENDING',
            'submitted_by_admin_id' => 1,
            'submitted_by_person_id' => $this->people()[1],
            'submitted_by_name' => 'Ana',
            'submitted_by_role' => 'Super Admin',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    private function insertEnvio(int $plantilla, array $o = []): int
    {
        return (int) DB::table(self::SUBS)->insertGetId($this->envio($plantilla, $o));
    }

    /** @return list<string> ordenados byte a byte (el ORDER BY de PG seguiría la collation) */
    private function nombres(string $sql): array
    {
        $n = array_map(static fn (object $f): string => (string) $f->n, DB::select($sql, [self::SUBS]));
        sort($n, SORT_STRING);

        return $n;
    }

    /**
     * La definición completa de la tabla: columnas (tipo, nulo, por defecto),
     * índices, disparadores, CHECK y funciones propias (PG), o lo que guarda
     * `sqlite_master` (SQLite). Vacía si la tabla no existe.
     *
     * @return array<string, mixed>
     */
    private function definicion(): array
    {
        if (! Schema::hasTable(self::SUBS)) {
            return [];
        }
        $columnas = array_map(static fn (array $c): string => $c['name'].' '.$c['type'].' '.($c['nullable'] ? 'NULL' : 'NOT NULL').' '.($c['default'] ?? '∅'), Schema::getColumns(self::SUBS));
        if (! $this->isPostgres()) {
            return ['columns' => $columnas, 'objects' => DB::table('sqlite_master')->where('tbl_name', self::SUBS)->orderBy('name')->get(['type', 'name', 'sql'])
                ->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all()];
        }

        return [
            'columns' => $columnas,
            'indexes' => $this->nombres('SELECT pg_get_indexdef(indexrelid) AS n FROM pg_index WHERE indrelid = ?::regclass'),
            'triggers' => $this->nombres('SELECT pg_get_triggerdef(oid) AS n FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass'),
            'checks' => $this->nombres("SELECT conname || ': ' || pg_get_constraintdef(oid) AS n FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass"),
            'functions' => array_map(static fn (object $f): string => $f->proname.' '.$f->md5,
                DB::select("SELECT proname, md5(pg_get_functiondef(oid)) AS md5 FROM pg_proc WHERE proname LIKE 'ultron\\_studio\\_chk\\_usmsub\\_%' ORDER BY proname COLLATE \"C\"")),
            'acl' => (string) DB::scalar("SELECT coalesce(relacl::text, '') FROM pg_class WHERE oid = ?::regclass", [self::SUBS]),
        ];
    }

    /** @return array{tables: int, triggers: int, checks: int, partial: int, functions: int} en `ultron_studio_%` */
    private function totales(): array
    {
        if (! $this->isPostgres()) {
            $m = static fn (string $type) => DB::table('sqlite_master')->where('type', $type);

            return [
                'tables' => $m('table')->where('name', 'like', 'ultron_studio_%')->count(),
                'triggers' => $m('trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count(),
                'checks' => 0,
                'partial' => $m('index')->where('tbl_name', 'like', 'ultron_studio_%')->where('sql', 'like', '% WHERE %')->count(),
                'functions' => 0,
            ];
        }

        return [
            'tables' => (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\\_studio\\_%'"),
            'triggers' => $this->countLike('trigger', 'ultron\\_studio\\_%'),
            'checks' => $this->countLike('check', 'ultron\\_studio\\_%'),
            'partial' => (int) DB::scalar("SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\\_studio\\_%' AND i.indpred IS NOT NULL"),
            'functions' => (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'ultron\\_studio\\_%'"),
        ];
    }
}
