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
 * `plans/PLANTILLAS_META_PLAN.md` §5.3, §5.6, §5.7.2 y R6): la AMPLIACIÓN de
 * `ultron_studio_template_meta_checks` (W9, `2026_11_13_200001`) por la
 * migración `2026_11_15_100002`.
 *
 * En los dos motores:
 *  - tres columnas nuevas (`source` NOT NULL DEFAULT 'manual', `submission_id`,
 *    `meta_components_hash`) y, en PG, `status` varchar(20) y `category`
 *    varchar(16);
 *  - una fila manual de hoy (sin nombrar las columnas nuevas) entra igual y es
 *    `manual`;
 *  - una fila que cita un envío cita uno de su MISMA plantilla y huella;
 *  - la tabla SIGUE siendo de solo inserción, también en las columnas nuevas;
 *  - `down()` se niega con cualquier fila que el esquema de W9 no admita (R6),
 *    sin tocar nada; sin ellas deja EXACTAMENTE W9 (comparado con W9 recién
 *    creada) y las filas de W9 intactas; `up()` se niega a ampliar dos veces.
 * Solo en PostgreSQL: los CHECK (estados de Meta y locales, `authentication`,
 * `source` y su forma), la función propia INVOKER, el REVOKE de W9 comprobado
 * de nuevo (y la migración falla si el rol puede escribir) y el `lock_timeout`.
 */
class UltronStudioTemplateMetaChecksMetaApiSchemaTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const CHECKS_TABLE = 'ultron_studio_template_meta_checks';

    private const SUBS = 'ultron_studio_meta_template_submissions';

    private const W9_MIGRATION = '2026_11_13_200001_create_ultron_studio_template_meta_checks_table';

    private const AMPL_MIGRATION = '2026_11_15_100002_add_meta_api_source_to_ultron_studio_template_meta_checks';

    private const OTRA_CONEXION = 'pgsql_plantillas_checks_cerrojo';

    private const SOLO_INSERCION = 'las comprobaciones de plantillas en Meta son de solo inserción';

    private const CITA = 'ultron_studio: una comprobación de Meta que cita un envío cita uno de la misma plantilla y de su huella exacta (INSERT en '.self::CHECKS_TABLE.')';

    /** Los 4 de W9 y los 8 nuevos, en el orden del CHECK. */
    private const STATUSES = ['APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'PENDING', 'IN_APPEAL', 'PENDING_DELETION', 'DELETED', 'ARCHIVED', 'LIMIT_EXCEEDED', 'NOT_FOUND', 'UNKNOWN'];

    /** @var array<string, Migration> */
    private array $plantillasMigrations = [];

    protected function tearDown(): void
    {
        DB::purge(self::OTRA_CONEXION);
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        parent::tearDown();
    }

    // ── Las columnas ────────────────────────────────────────────────────────

    public function test_tres_columnas_nuevas_y_los_largos_de_estado_y_categoria(): void
    {
        $c = collect(Schema::getColumns(self::CHECKS_TABLE))->keyBy('name');
        $this->assertSame([
            'id', 'uuid', 'client_request_id', 'template_id', 'template_content_hash', 'meta_name', 'language', 'category', 'meta_body_hash',
            'meta_template_id', 'status', 'checked_at', 'recorded_by_admin_id', 'recorded_by_name', 'created_at', 'source', 'submission_id', 'meta_components_hash',
        ], $c->keys()->all(), 'las 15 de W9 en su orden y las 3 nuevas al final');

        $forma = fn (string $col): array => [$this->isPostgres() ? $c[$col]['type'] : $c[$col]['type_name'], (bool) $c[$col]['nullable'], $c[$col]['default']];
        if ($this->isPostgres()) {
            $this->assertSame(['character varying(10)', false, "'manual'::character varying"], $forma('source'));
            $this->assertSame(['bigint', true, null], $forma('submission_id'));
            $this->assertSame(['character varying(64)', true, null], $forma('meta_components_hash'));
            $this->assertSame(['character varying(20)', false, null], $forma('status'));
            $this->assertSame(['character varying(16)', false, null], $forma('category'));
            // Las otras huellas de W9, del mismo tipo que la nueva.
            $this->assertSame('character varying(64)', $c['meta_body_hash']['type']);

            return;
        }
        $this->assertSame(['varchar', false, "'manual'"], $forma('source'));
        $this->assertSame(['integer', true, null], $forma('submission_id'));
        $this->assertSame(['varchar', true, null], $forma('meta_components_hash'));
    }

    public function test_una_fila_manual_de_hoy_entra_igual_y_es_manual(): void
    {
        $plantilla = $this->whatsappTemplate();
        // Como la escribe hoy la orden de consola: sin nombrar ninguna columna nueva.
        $id = $this->metaCheck($plantilla);
        $fila = DB::table(self::CHECKS_TABLE)->where('id', $id)->first(['source', 'submission_id', 'meta_components_hash']);
        $this->assertSame(['manual', null, null], [$fila->source, $fila->submission_id, $fila->meta_components_hash]);
    }

    public function test_una_fila_que_cita_un_envio_cita_uno_de_su_misma_plantilla_y_huella(): void
    {
        $plantilla = $this->whatsappTemplate();
        $otra = $this->whatsappTemplate(['metaName' => 'renovacion_w9_b']);
        $envio = $this->insertEnvio($plantilla);
        $envioDeOtra = $this->insertEnvio($otra);

        $this->assertAccepted(fn () => $this->metaApi($plantilla, ['submission_id' => $envio]), 'su envío');
        $this->assertAccepted(fn () => $this->metaApi($plantilla), 'sin envío (§5.5 3b: una registrada a mano que vigila la sincronización)');
        $this->assertGuarded(fn () => $this->metaApi($plantilla, ['submission_id' => $envioDeOtra]), self::CITA, 'el envío de otra plantilla');
        $this->assertGuarded(fn () => $this->metaApi($plantilla, ['submission_id' => 999999]), self::CITA, 'un envío que no existe');
        // La guarda de W9 sigue: la huella de la plantilla manda también para las filas de la API.
        $this->assertGuarded(fn () => $this->metaApi($plantilla, ['template_content_hash' => str_repeat('a', 64)]),
            'ultron_studio: una comprobación de Meta es de una plantilla de WhatsApp del Studio y de su huella exacta (INSERT en '.self::CHECKS_TABLE.')', 'otra huella');
        $this->assertSame(['manual' => 0, 'meta_api' => 2], [
            'manual' => DB::table(self::CHECKS_TABLE)->where('source', 'manual')->count(),
            'meta_api' => DB::table(self::CHECKS_TABLE)->where('source', 'meta_api')->count(),
        ]);
    }

    public function test_sigue_siendo_de_solo_insercion_tambien_en_las_columnas_nuevas(): void
    {
        $plantilla = $this->whatsappTemplate();
        $id = $this->metaApi($plantilla, ['submission_id' => $this->insertEnvio($plantilla)]);

        foreach ([['source' => 'manual'], ['submission_id' => null], ['meta_components_hash' => hash('sha256', 'otra')], ['status' => 'PAUSED']] as $cambio) {
            $this->assertGuarded(fn () => DB::table(self::CHECKS_TABLE)->where('id', $id)->update($cambio), 'ultron_studio: '.self::SOLO_INSERCION.' (UPDATE en '.self::CHECKS_TABLE.')', json_encode($cambio));
        }
        $this->assertGuarded(fn () => DB::table(self::CHECKS_TABLE)->where('id', $id)->delete(), 'ultron_studio: '.self::SOLO_INSERCION.' (DELETE en '.self::CHECKS_TABLE.')');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::CHECKS_TABLE), 'ultron_studio: '.self::SOLO_INSERCION.' (TRUNCATE en '.self::CHECKS_TABLE.')');
        }
        $this->assertSame(1, DB::table(self::CHECKS_TABLE)->count());
    }

    // ── Solo PostgreSQL ─────────────────────────────────────────────────────

    public function test_solo_en_postgres_los_check_de_estado_categoria_y_origen(): void
    {
        $this->requirePostgres('los CHECK de W9 y de su ampliación solo existen en PostgreSQL.');
        $m = $this->plantillasMigration(self::AMPL_MIGRATION);
        $plantilla = $this->whatsappTemplate();
        $envio = $this->insertEnvio($plantilla);

        $this->assertSame(self::STATUSES, $m::statuses());
        $this->assertSame(['ustmc_category_chk', 'ustmc_language_chk', 'ustmc_shape_chk', 'ustmc_source_chk', 'ustmc_source_shape_chk', 'ustmc_status_chk'],
            $this->checkNames());
        $this->assertSame($m::checkNames(), $this->checkNames());

        foreach (self::STATUSES as $estado) {
            $this->assertAccepted(fn () => $this->metaApi($plantilla, ['status' => $estado]), $estado);
        }
        $this->assertAccepted(fn () => $this->metaApi($plantilla, ['category' => 'authentication']), 'una recategorización a AUTHENTICATION se registra');
        $this->assertAccepted(fn () => $this->metaCheck($plantilla, ['status' => 'PENDING']), 'una manual con un estado de Meta nuevo');

        foreach ([
            'ustmc_status_chk' => ['status' => 'INVENTADO'],
            'ustmc_category_chk' => ['category' => 'promo'],
        ] as $check => $cambio) {
            $this->assertRejected(fn () => $this->metaApi($plantilla, $cambio), '23514', $check, json_encode($cambio));
        }
        foreach ([
            'otro origen' => ['source' => 'consola'],
            'de la API sin huella de componentes' => ['meta_components_hash' => null],
            'de la API con una huella que no es hex' => ['meta_components_hash' => strtoupper(hash('sha256', 'c'))],
            'manual con huella de componentes' => ['source' => 'manual'],
            'manual citando un envío' => ['source' => 'manual', 'meta_components_hash' => null, 'submission_id' => $envio],
        ] as $que => $cambio) {
            $this->assertRejected(fn () => $this->metaApi($plantilla, $cambio), '23514', 'ustmc_source', $que);
        }
        // Lo que no sabe la orden de consola (§5.6) no entra por la puerta manual.
        $this->assertSame(['ustmc_source_shape_chk'], $this->violatedChecks(self::CHECKS_TABLE, $this->metaApiRow($plantilla, ['source' => 'manual'])));
    }

    public function test_solo_en_postgres_la_funcion_propia_es_invoker_y_el_disparador_solo_mira_las_que_citan(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');
        $f = DB::selectOne("SELECT l.lanname AS lang, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
            FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = 'ultron_studio_chk_ustmc_submission_of_template'");
        $this->assertNotNull($f);
        $this->assertSame(['plpgsql', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, (int) $f->definer, $f->owner]);
        $this->assertStringContainsString('FROM public.'.self::SUBS.' AS s', (string) $f->src);
        $this->assertStringContainsString('BEFORE INSERT ON public.'.self::CHECKS_TABLE.' FOR EACH ROW WHEN ((new.submission_id IS NOT NULL)) EXECUTE FUNCTION ultron_studio_chk_ustmc_submission_of_template(',
            (string) DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'ustmc_submission_of_template'"));
        $this->assertSame(['ustmc_forbid_delete', 'ustmc_forbid_truncate', 'ustmc_forbid_update', 'ustmc_submission_of_template', 'ustmc_template_of_whatsapp'], $this->triggerNames());
    }

    public function test_solo_en_postgres_el_revoke_de_w9_se_comprueba_de_nuevo_y_la_aplicacion_solo_inserta(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');
        $plantilla = $this->whatsappTemplate();
        $envio = $this->insertEnvio($plantilla);
        $rol = $this->appRoleName();

        $this->withAppRole(function () use ($plantilla, $envio, $rol): void {
            // Las guardas entre tablas son INVOKER: el rol lee plantillas y envíos como la aplicación.
            DB::statement('GRANT SELECT ON ultron_studio_message_templates, '.self::SUBS.' TO '.$rol);
            $w9 = $this->plantillasMigration(self::W9_MIGRATION);
            $this->plantillasMigration(self::AMPL_MIGRATION)->down();
            $w9->down();
            $w9->up();                                                  // REVOKE UPDATE, DELETE de W9 al rol
            $this->plantillasMigration(self::AMPL_MIGRATION)->up();     // lo vuelve a comprobar, columnas nuevas incluidas

            $this->assertSame([true, true, false, false, false], $this->privileges(self::CHECKS_TABLE), 'solo SELECT e INSERT');
            $manual = $this->metaCheckRow($plantilla);
            $api = $this->metaApiRow($plantilla, ['submission_id' => $envio]);
            $this->assertSame([null, 'deshecho'], $this->asApp(function () use ($manual, $api): void {
                DB::table(self::CHECKS_TABLE)->insert($manual);
                DB::table(self::CHECKS_TABLE)->insert($api);
            }), 'la aplicación registra filas manuales y de la API');
            $this->assertSame('42501', $this->asApp(fn () => DB::table(self::CHECKS_TABLE)->where('id', '>', 0)->update(['source' => 'manual']))[0]);
            $this->assertSame('42501', $this->asApp(fn () => DB::table(self::CHECKS_TABLE)->where('id', '>', 0)->delete())[0]);

            // Si alguien concedió UPDATE fuera de las migraciones, ampliar falla y no se registra.
            $this->plantillasMigration(self::AMPL_MIGRATION)->down();
            DB::statement('GRANT UPDATE (meta_name) ON '.self::CHECKS_TABLE.' TO '.$rol);
            try {
                $this->plantillasMigration(self::AMPL_MIGRATION)->up();
                $this->fail('up() amplió con el rol de la aplicación pudiendo hacer UPDATE');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('puede hacer UPDATE (en la tabla o en alguna columna)', $e->getMessage());
            }
            $this->assertFalse(Schema::hasColumn(self::CHECKS_TABLE, 'source'), 'no quedó a medias');
        });
    }

    // ── down() y up() ───────────────────────────────────────────────────────

    public function test_down_se_niega_con_cualquier_fila_que_w9_no_admite_y_no_toca_nada(): void
    {
        $plantilla = $this->whatsappTemplate();
        $envio = $this->insertEnvio($plantilla);
        $m = $this->plantillasMigration(self::AMPL_MIGRATION);
        $antes = $this->definicion();

        foreach ([
            '(1 de la API de Meta, 0 con un estado nuevo, 0 con una categoría nueva y 1 con un envío o una huella de componentes)' => fn () => $this->metaApi($plantilla, ['submission_id' => $envio]),
            '(0 de la API de Meta, 1 con un estado nuevo, 0 con una categoría nueva y 0 con un envío o una huella de componentes)' => fn () => $this->metaCheck($plantilla, ['status' => 'NOT_FOUND']),
            '(0 de la API de Meta, 0 con un estado nuevo, 1 con una categoría nueva y 0 con un envío o una huella de componentes)' => fn () => $this->metaCheck($plantilla, ['category' => 'authentication']),
        ] as $recuento => $sembrar) {
            DB::beginTransaction();
            try {
                $sembrar();
                try {
                    $m->down();
                    $this->fail("down() revirtió con {$recuento}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('down() se niega a devolver '.self::CHECKS_TABLE.' al esquema de W9', $e->getMessage());
                    $this->assertStringContainsString($recuento, $e->getMessage());
                    $this->assertStringContainsString('R6', $e->getMessage());
                }
                $this->assertSame($antes, $this->definicion(), "down() tocó el esquema con {$recuento}");
                $this->assertSame(1, DB::table(self::CHECKS_TABLE)->count(), 'y no borró la fila');
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_ida_y_vuelta_sin_filas_deja_exactamente_w9_y_vuelve_a_ampliar_igual(): void
    {
        $m = $this->plantillasMigration(self::AMPL_MIGRATION);
        $w9 = $this->plantillasMigration(self::W9_MIGRATION);
        $ampliada = $this->definicion();
        $totales = $this->totales();

        DB::enableQueryLog();
        $m->down();
        $restaurada = $this->definicion();
        $this->assertSame(['triggers' => $totales['triggers'] - 1, 'checks' => $totales['checks'] - ($this->isPostgres() ? 2 : 0)], $this->totales());
        if ($this->isPostgres()) {
            $alters = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $q): bool => str_starts_with($q, 'ALTER TABLE')));
            $this->assertCount(1, $alters, 'down(): un solo ALTER TABLE (un cerrojo, ningún instante sin CHECK)');
        }
        DB::disableQueryLog();

        // W9 recién creada: la verdad con la que se compara.
        $w9->down();
        $w9->up();
        $this->assertSame($this->definicion(), $restaurada, 'down() deja EXACTAMENTE la tabla de W9');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $m->up();
        if ($this->isPostgres()) {
            $alters = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $q): bool => str_starts_with($q, 'ALTER TABLE')));
            $this->assertCount(1, $alters, 'up(): un solo ALTER TABLE');
            $this->assertStringContainsString('ADD COLUMN source varchar(10) NOT NULL DEFAULT \'manual\'', $alters[0]);
        }
        DB::disableQueryLog();
        $this->assertSame($ampliada, $this->definicion(), 'up() deja lo mismo que el migrate');
        $this->assertSame($totales, $this->totales());
    }

    public function test_ida_y_vuelta_con_filas_de_w9_no_las_toca(): void
    {
        $plantilla = $this->whatsappTemplate();
        $ids = [$this->metaCheck($plantilla), $this->metaCheck($plantilla, ['status' => 'PAUSED', 'meta_template_id' => null])];
        $huella = fn (): string => md5((string) json_encode(DB::table(self::CHECKS_TABLE)->orderBy('id')->get(
            ['id', 'uuid', 'client_request_id', 'template_id', 'template_content_hash', 'meta_name', 'language', 'category', 'meta_body_hash',
                'meta_template_id', 'status', 'checked_at', 'recorded_by_admin_id', 'recorded_by_name', 'created_at'])));
        $antes = $huella();

        $this->plantillasMigration(self::AMPL_MIGRATION)->down();
        $this->assertFalse(Schema::hasColumn(self::CHECKS_TABLE, 'source'));
        $this->assertSame($antes, $huella(), 'down() no toca las filas de W9');
        $this->plantillasMigration(self::AMPL_MIGRATION)->up();
        $this->assertSame($antes, $huella(), 'up() tampoco');
        $this->assertSame([['manual', null, null], ['manual', null, null]], DB::table(self::CHECKS_TABLE)->whereIn('id', $ids)->orderBy('id')->get(['source', 'submission_id', 'meta_components_hash'])
            ->map(static fn (object $f): array => [$f->source, $f->submission_id, $f->meta_components_hash])->all(), 'las que ya había leen manual');
    }

    public function test_up_se_niega_a_ampliar_dos_veces(): void
    {
        $antes = $this->definicion();
        try {
            $this->plantillasMigration(self::AMPL_MIGRATION)->up();
            $this->fail('up() amplió dos veces');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('para ampliar, '.self::CHECKS_TABLE.' tiene que estar como la deja W9', $e->getMessage());
        }
        $this->assertSame($antes, $this->definicion());
    }

    public function test_solo_en_postgres_no_espera_sin_limite_un_cerrojo(): void
    {
        $this->requirePostgres('lock_timeout y los cerrojos de tabla son de PostgreSQL.');
        $antes = $this->definicion();
        $m = $this->plantillasMigration(self::AMPL_MIGRATION);
        config(['database.connections.'.self::OTRA_CONEXION => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection(self::OTRA_CONEXION);
        $otra->beginTransaction();
        // Otra transacción LEE la tabla (el canal de W9 lee la última fila): ACCESS EXCLUSIVE choca con ella.
        $otra->statement('LOCK TABLE '.self::CHECKS_TABLE.' IN ACCESS SHARE MODE NOWAIT');
        try {
            foreach (['down', 'up'] as $paso) {
                DB::statement("SET LOCAL lock_timeout = '200ms'");
                $t0 = microtime(true);
                try {
                    $m->{$paso}();
                    $this->fail("{$paso}() esperó el cerrojo o lo atravesó");
                } catch (QueryException $e) {
                    $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
                }
                $this->assertLessThan(5.0, microtime(true) - $t0, "{$paso}(): cae con el lock_timeout de la sesión");
            }
        } finally {
            $otra->rollBack();
        }
        $this->assertSame($antes, $this->definicion(), 'no tocó nada');

        foreach (['down', 'up'] as $paso) {
            DB::statement('SET LOCAL lock_timeout = 0');
            $m->{$paso}();
            $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"), "{$paso}()");
        }
        $this->assertSame($antes, $this->definicion());
    }

    // ────────────────────────────────────────────────────────────────────────

    private function plantillasMigration(string $name): Migration
    {
        return $this->plantillasMigrations[$name] ??= require database_path("migrations/{$name}.php");
    }

    /** Una fila manual como la de hoy, sin insertarla. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function metaCheckRow(int $plantilla, array $o = []): array
    {
        $t = DB::table('ultron_studio_message_templates')->where('id', $plantilla)->first(['content_json', 'content_hash']);
        $c = (array) json_decode((string) $t->content_json, true);

        return array_merge([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $plantilla, 'template_content_hash' => (string) $t->content_hash,
            'meta_name' => (string) $c['metaName'], 'language' => (string) $c['language'], 'category' => (string) $c['category'],
            'meta_body_hash' => hash('sha256', (string) $c['bodyPreview']), 'meta_template_id' => '7700112233', 'status' => 'APPROVED',
            'checked_at' => now()->subHour(), 'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana', 'created_at' => now(),
        ], $o);
    }

    /** Una fila de la API (la sincronización o «Comprobar ahora»), sin insertarla. @param  array<string, mixed>  $o @return array<string, mixed> */
    private function metaApiRow(int $plantilla, array $o = []): array
    {
        return $this->metaCheckRow($plantilla, array_merge(['source' => 'meta_api', 'submission_id' => null, 'meta_components_hash' => hash('sha256', 'componentes-'.$plantilla)], $o));
    }

    /** @param  array<string, mixed>  $o */
    private function metaApi(int $plantilla, array $o = []): int
    {
        return (int) DB::table(self::CHECKS_TABLE)->insertGetId($this->metaApiRow($plantilla, $o));
    }

    /** Un envío vivo (SENDING) de la plantilla, por Ana. */
    private function insertEnvio(int $plantilla): int
    {
        $t = DB::table('ultron_studio_message_templates')->where('id', $plantilla)->first(['content_json', 'content_hash', 'category']);
        $c = (array) json_decode((string) $t->content_json, true);

        return (int) DB::table(self::SUBS)->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $plantilla, 'template_content_hash' => (string) $t->content_hash,
            'meta_name' => (string) $c['metaName'], 'language' => (string) $c['language'], 'category' => (string) $t->category,
            'request_hash' => hash('sha256', 'cuerpo-'.$plantilla), 'state' => 'SENDING', 'submitted_by_admin_id' => 1, 'submitted_by_person_id' => $this->people()[1],
            'submitted_by_name' => 'Ana', 'submitted_by_role' => 'Super Admin', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function checkNames(): array
    {
        $n = array_map(static fn (object $f): string => $f->conname, DB::select("SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass", [self::CHECKS_TABLE]));
        sort($n, SORT_STRING);

        return $n;
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        $n = $this->isPostgres()
            ? array_map(static fn (object $f): string => $f->tgname, DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass', [self::CHECKS_TABLE]))
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::CHECKS_TABLE)->pluck('name')->all();
        sort($n, SORT_STRING);

        return $n;
    }

    /**
     * La definición de la tabla: columnas (tipo, nulo, por defecto, en su
     * orden), índices, disparadores y CHECK (PG) o su `PRAGMA table_info`, sus
     * índices y disparadores (SQLite), y su ACL (PG).
     *
     * @return array<string, mixed>
     */
    private function definicion(): array
    {
        if (! $this->isPostgres()) {
            return [
                'columns' => array_map(static fn (object $c): string => "{$c->cid} {$c->name} {$c->type} {$c->notnull} ".($c->dflt_value ?? '∅')." {$c->pk}", DB::select('PRAGMA table_info('.self::CHECKS_TABLE.')')),
                'objects' => DB::table('sqlite_master')->where('tbl_name', self::CHECKS_TABLE)->where('type', '<>', 'table')->orderBy('name')->get(['type', 'name', 'sql'])
                    ->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all(),
            ];
        }
        $lista = function (string $sql): array {
            $n = array_map(static fn (object $f): string => (string) $f->n, DB::select($sql, [self::CHECKS_TABLE]));
            sort($n, SORT_STRING);

            return $n;
        };

        return [
            'columns' => array_map(static fn (array $c): string => $c['name'].' '.$c['type'].' '.($c['nullable'] ? 'NULL' : 'NOT NULL').' '.($c['default'] ?? '∅'), Schema::getColumns(self::CHECKS_TABLE)),
            'indexes' => $lista('SELECT pg_get_indexdef(indexrelid) AS n FROM pg_index WHERE indrelid = ?::regclass'),
            'triggers' => $lista('SELECT pg_get_triggerdef(oid) AS n FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass'),
            'checks' => $lista("SELECT conname || ': ' || pg_get_constraintdef(oid) AS n FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass"),
            'acl' => (string) DB::scalar("SELECT coalesce(relacl::text, '') FROM pg_class WHERE oid = ?::regclass", [self::CHECKS_TABLE]),
        ];
    }

    /** @return array{triggers: int, checks: int} en `ultron_studio_%` */
    private function totales(): array
    {
        if (! $this->isPostgres()) {
            return ['triggers' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count(), 'checks' => 0];
        }

        return ['triggers' => $this->countLike('trigger', 'ultron\\_studio\\_%'), 'checks' => $this->countLike('check', 'ultron\\_studio\\_%')];
    }
}
