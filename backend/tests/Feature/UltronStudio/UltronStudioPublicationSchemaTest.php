<?php

namespace Tests\Feature\UltronStudio;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * El esquema del Hito 5 (contrato H5 §A3): la cadena `ultron_studio_publications`
 * (2026_10_25_100001) y sus guardas (2026_10_25_100002).
 *
 * Se prueba en los dos motores (la suite en SQLite y la puerta obligatoria
 * `docs/ultron-studio/ops/pgunit.sh` en PostgreSQL), con SQL a pelo y sin pasar
 * por los modelos: lo que se comprueba es la BASE.
 *
 *  · La tabla tiene las columnas y los tipos del contrato (`effective_json`
 *    es `text`, nunca json/jsonb) y versiones no gana ninguna (§A3.3).
 *  · Recuentos del Studio: PG 44 disparadores y 50 CHECK con los Hitos 6 y 7 (H5 solo: 30 y 27);
 *    SQLite 32 disparadores (H5 solo: 20). Si un hito posterior añade guardas,
 *    estos números se actualizan a conciencia, como el de rutas. El Hito 7
 *    (2026_11_02_100001) añade a la cadena `uspub_chain_next` (los dos motores),
 *    `uspub_seq_chk` (PG) y `uspub_seq_min` (SQLite): las entradas sembradas
 *    aquí son la siguiente a la cabeza y apuntan a ella, y lo que se comprueba
 *    del H5 son sus guardas (el down()/up() del H5 recrea la tabla sin las del H7).
 *  · La cadena es de solo inserción: P0001 en PG y 23000 en SQLite, con el
 *    texto de `ultron_studio_forbid()`.
 *  · `usv_status_chk` admite los 9 estados y rechaza el resto (23514, PG).
 *  · `usv_one_canary` y `usv_one_published` conservan su WHERE en los dos
 *    motores (memoria «SQLite reconstruye y pierde el WHERE») y hacen lo que
 *    dicen: una segunda CANARY o PUBLISHED choca (23505 en PG; 23000 «UNIQUE
 *    constraint failed» en SQLite).
 *  · down() se niega con filas de H5 sin tocar nada, y sin ellas deja el
 *    esquema de H4 y se puede volver a aplicar.
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en una transacción
 * anidada (SAVEPOINT): sin ella el error abortaría la transacción de la prueba.
 */
class UltronStudioPublicationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'ultron_studio_publications';

    private const CREATE = '2026_10_25_100001_create_ultron_studio_publications_table';

    private const GUARDS = '2026_10_25_100002_add_ultron_studio_publication_guards';

    private const REASON = 'la cadena de publicaciones es de solo inserción';

    private const NINE_STATUSES = ['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    private const H5_STATUSES = ['CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    /** Las guardas del Hito 7 sobre la cadena (2026_11_02_100001): disparadores de los dos motores y el CHECK de PG. */
    private const H7_CHAIN_GUARDS = ['uspub_chain_next', 'uspub_seq_min', 'uspub_seq_chk'];

    /** Contrato §A3.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
    private const COLUMNS = [
        'id' => ['int8', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'seq' => ['int4', 'integer', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'action' => ['varchar', 'varchar', false],
        'version_id' => ['int8', 'integer', true],
        'restores_publication_id' => ['int8', 'integer', true],
        'previous_publication_id' => ['int8', 'integer', true],
        'engine_contract' => ['varchar', 'varchar', false],
        'effective_json' => ['text', 'text', false],
        'effective_hash' => ['bpchar', 'varchar', false],
        'signature' => ['bpchar', 'varchar', false],
        'catalog_version' => ['varchar', 'varchar', false],
        'lab_gate' => ['json', 'text', false],
        'acknowledged' => ['json', 'text', true],
        'evidence' => ['json', 'text', true],
        'reason' => ['varchar', 'varchar', true],
        'actor_admin_id' => ['int8', 'integer', false],
        'actor_name' => ['varchar', 'varchar', false],
        'actor_role' => ['varchar', 'varchar', true],
        'created_at' => ['timestamp', 'datetime', false],
    ];

    /** Las columnas de F (2026_10_18_100002, inmutables + mutables): H5 no añade ninguna (§A3.3). */
    private const VERSION_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'label', 'note', 'status', 'content', 'content_hash', 'catalog_version',
        'catalog_hash', 'source_draft_revision', 'created_by_admin_id', 'created_by_name', 'created_by_role',
        'last_validation', 'submitted_at', 'status_changed_at', 'created_at', 'updated_at',
    ];

    /** @var array<string, Migration> */
    private array $migrations = [];

    private int $seq = 0;

    // ── La tabla ────────────────────────────────────────────────────────────

    public function test_la_cadena_tiene_las_columnas_y_los_tipos_del_contrato(): void
    {
        $tipo = $this->isPostgres() ? 0 : 1;
        $esperado = array_map(static fn (array $c): array => [$c[$tipo], $c[2]], self::COLUMNS);

        $real = [];
        foreach (Schema::getColumns(self::TABLE) as $c) {
            $real[$c['name']] = [$c['type_name'], (bool) $c['nullable']];
        }

        $this->assertSame($esperado, $real);
        if ($this->isPostgres()) {
            // Los bytes que firma el Studio y lee el motor: text, no json/jsonb.
            $this->assertSame('text', DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'effective_json'", [self::TABLE])->data_type);
            $tipos = collect(Schema::getColumns(self::TABLE))->pluck('type', 'name');
            $this->assertSame('character varying(10)', $tipos['action']);
            $this->assertSame('character varying(32)', $tipos['engine_contract']);
            $this->assertSame('character(64)', $tipos['effective_hash']);
            $this->assertSame('character(64)', $tipos['signature']);
            $this->assertSame('character varying(500)', $tipos['reason']);
            $this->assertSame('character varying(120)', $tipos['actor_name']);
            $this->assertSame('character varying(60)', $tipos['actor_role']);
        }
    }

    public function test_indices_y_claves_foraneas_de_la_cadena(): void
    {
        $indices = [];
        foreach (Schema::getIndexes(self::TABLE) as $i) {
            if (! $i['primary']) {
                $indices[$i['name']] = [$i['columns'], $i['unique']];
            }
        }
        ksort($indices);
        $this->assertSame([
            'ultron_studio_publications_client_request_id_unique' => [['client_request_id'], true],
            'ultron_studio_publications_seq_unique' => [['seq'], true],
            'ultron_studio_publications_uuid_unique' => [['uuid'], true],
            'uspub_version_idx' => [['version_id', 'id'], false],
        ], $indices);

        $fks = [];
        foreach (Schema::getForeignKeys(self::TABLE) as $fk) {
            $fks[implode(',', $fk['columns'])] = [$fk['foreign_table'], $fk['foreign_columns'], $fk['on_delete']];
        }
        ksort($fks);
        $this->assertSame([
            'previous_publication_id' => [self::TABLE, ['id'], 'restrict'],
            'restores_publication_id' => [self::TABLE, ['id'], 'restrict'],
            'version_id' => ['ultron_studio_versions', ['id'], 'restrict'],
        ], $fks);

        // La FK a versiones se cumple: una versión que no existe no entra.
        $this->assertRejected(
            fn () => $this->insertPublication(['version_id' => 999999]),
            $this->isPostgres() ? '23503' : '23000',
            $this->isPostgres() ? 'ultron_studio_publications_version_id_foreign' : 'FOREIGN KEY constraint failed',
        );
    }

    public function test_versiones_no_gana_columnas_ni_pierde_sus_guardas_de_f(): void
    {
        $this->assertSame(self::VERSION_COLUMNS, Schema::getColumnListing('ultron_studio_versions'));

        // Si alguien reconstruyera la tabla en SQLite, se llevaría estos dos.
        $this->assertSame(['usv_forbid_delete', 'usv_forbid_immutable_update'], $this->triggersOn('ultron_studio_versions', rowOnly: true));
    }

    // ── Recuentos ───────────────────────────────────────────────────────────

    public function test_recuentos_de_disparadores_y_check_del_studio(): void
    {
        if ($this->isPostgres()) {
            $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
            $this->assertSame(137, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55 + W9 4 + plantillas de Meta 10');
            $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
            $this->assertSame(153, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53 + W9 4 + plantillas de Meta 12 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
            // Los disparadores usan la función de F; H5 no crea otra ni una sobrecarga.
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
            $this->assertSame(3, (int) DB::selectOne(
                "SELECT count(*) AS n FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND t.tgrelid = 'ultron_studio_publications'::regclass AND p.proname = 'ultron_studio_forbid'")->n);

            return;
        }

        $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
        $this->assertSame(120, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53 + W9 3 + plantillas de Meta 9');
    }

    // ── La cadena es de solo inserción ──────────────────────────────────────

    public function test_la_cadena_admite_insertar_y_rechaza_cambiar_o_borrar(): void
    {
        $primera = $this->insertPublication();
        $segunda = $this->insertPublication(['previous_publication_id' => $primera, 'action' => 'legacy', 'version_id' => null, 'reason' => 'Volver a Legacy']);

        foreach ([
            'UPDATE' => [
                fn () => DB::table(self::TABLE)->where('id', $primera)->update(['actor_name' => 'Otra persona']),
                // Por fila y sin mirar valores: escribir el MISMO valor también falla.
                fn () => DB::table(self::TABLE)->where('id', $primera)->update(['effective_json' => DB::raw('effective_json')]),
                fn () => DB::table(self::TABLE)->update(['reason' => 'todas']),
                // ON CONFLICT DO UPDATE pasa por el disparador de UPDATE.
                fn () => DB::table(self::TABLE)->upsert([$this->publicationRow(['uuid' => DB::table(self::TABLE)->where('id', $primera)->value('uuid')])], ['uuid'], ['actor_name']),
            ],
            'DELETE' => [
                fn () => DB::table(self::TABLE)->where('id', $segunda)->delete(),
                // En SQLite es el camino de un TRUNCATE.
                fn () => DB::table(self::TABLE)->delete(),
            ],
        ] as $operacion => $intentos) {
            foreach ($intentos as $i => $intento) {
                $this->assertForbidden($intento, $operacion, "{$operacion} #{$i}");
            }
        }

        // Un UPDATE que no encuentra filas no dispara nada.
        $this->assertSame(0, DB::table(self::TABLE)->where('id', -1)->update(['reason' => 'nada']));
        $this->assertSame(2, DB::table(self::TABLE)->count());
        $this->assertSame('Prueba', DB::table(self::TABLE)->where('id', $primera)->value('actor_name'));
    }

    public function test_solo_en_postgres_truncate_de_la_cadena(): void
    {
        $this->requirePostgres('TRUNCATE no existe en SQLite; allí `DELETE FROM` sin WHERE pasa por el disparador de DELETE (cubierto arriba).');

        $this->insertPublication();
        $this->assertForbidden(fn () => DB::statement('TRUNCATE '.self::TABLE), 'TRUNCATE', 'TRUNCATE');
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_solo_en_postgres_los_check_de_la_cadena(): void
    {
        $this->requirePostgres('SQLite no admite añadir CHECK a una tabla existente: lo vigila el servicio.');

        $this->assertRejected(fn () => $this->insertPublication(['action' => 'otra']), '23514', 'uspub_action_chk');
        // Enmienda M-1 (2026_11_16_100001): /1 y /2 entran; /3 no.
        $this->assertRejected(fn () => $this->insertPublication(['engine_contract' => 'ultron-engine-config/3']), '23514', 'uspub_engine_contract_chk');
        $this->assertRejected(fn () => $this->insertPublication(['action' => 'rollback', 'reason' => null]), '23514', 'uspub_reason_chk');
        $this->assertRejected(fn () => $this->insertPublication(['action' => 'legacy', 'version_id' => null, 'reason' => null]), '23514', 'uspub_reason_chk');

        // Publicar y promover no exigen motivo; revertir y Legacy con motivo pasan.
        foreach ([['action' => 'publish'], ['action' => 'promote'], ['action' => 'rollback', 'reason' => 'Revertir'], ['action' => 'legacy', 'version_id' => null, 'reason' => 'Legacy']] as $fila) {
            $this->insertPublication($fila);
        }
        $this->assertSame(4, DB::table(self::TABLE)->count());
    }

    // ── Versiones: CHECK ampliado e índices parciales ───────────────────────

    public function test_solo_en_postgres_usv_status_chk_admite_los_nueve_estados_y_ninguno_mas(): void
    {
        $this->requirePostgres('El CHECK de estados solo existe en PostgreSQL (F); en SQLite lo vigila el modelo.');

        $this->assertSame(self::NINE_STATUSES, $this->statusCheckValues());

        $id = $this->insertVersion('APPROVED');
        foreach (self::NINE_STATUSES as $estado) {
            $this->assertSame(1, DB::table('ultron_studio_versions')->where('id', $id)->update(['status' => $estado]), $estado);
        }
        foreach (['VALIDATING', 'SUPERSEDED', 'canary', ''] as $inventado) {
            $this->assertRejected(fn () => DB::table('ultron_studio_versions')->where('id', $id)->update(['status' => $inventado]), '23514', 'usv_status_chk');
            $this->assertRejected(fn () => $this->insertVersion($inventado), '23514', 'usv_status_chk');
        }
    }

    public function test_los_indices_parciales_conservan_su_where_en_los_dos_motores(): void
    {
        foreach (['usv_one_canary' => 'CANARY', 'usv_one_published' => 'PUBLISHED'] as $indice => $estado) {
            if ($this->isPostgres()) {
                $fila = DB::selectOne(
                    'SELECT i.indisunique AS unico, pg_get_expr(i.indpred, i.indrelid) AS predicado, a.attname AS columna
                       FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
                       JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                      WHERE c.relname = ? AND i.indrelid = ?::regclass AND i.indnatts = 1', [$indice, 'ultron_studio_versions']);
                $this->assertNotNull($fila, $indice);
                $this->assertTrue((bool) $fila->unico, $indice);
                $this->assertSame('status', $fila->columna, $indice);
                $this->assertSame("((status)::text = '{$estado}'::text)", $fila->predicado, $indice);

                continue;
            }

            $this->assertSame(
                "CREATE UNIQUE INDEX {$indice} ON ultron_studio_versions (status) WHERE status = '{$estado}'",
                DB::table('sqlite_master')->where('type', 'index')->where('name', $indice)->value('sql'),
            );
            $lista = collect(DB::select("PRAGMA index_list('ultron_studio_versions')"))->keyBy('name');
            $this->assertSame(1, (int) $lista[$indice]->unique, $indice);
            $this->assertSame(1, (int) $lista[$indice]->partial, $indice);
        }
    }

    public function test_como_mucho_una_version_canary_y_una_published(): void
    {
        $canario = $this->insertVersion('CANARY');
        $this->assertUniqueViolation(fn () => $this->insertVersion('CANARY'), 'usv_one_canary');

        // Una CANARY y una PUBLISHED a la vez, sí.
        $publicada = $this->insertVersion('PUBLISHED');
        $this->assertUniqueViolation(fn () => $this->insertVersion('PUBLISHED'), 'usv_one_published');

        // También por UPDATE: la transición que daría una segunda choca.
        $aprobada = $this->insertVersion('APPROVED');
        $this->assertUniqueViolation(fn () => DB::table('ultron_studio_versions')->where('id', $aprobada)->update(['status' => 'CANARY']), 'usv_one_canary');
        $this->assertUniqueViolation(fn () => DB::table('ultron_studio_versions')->where('id', $aprobada)->update(['status' => 'PUBLISHED']), 'usv_one_published');

        // Los demás estados no tienen tope.
        foreach (['RETIRED', 'RETIRED', 'ROLLED_BACK', 'ROLLED_BACK', 'APPROVED', 'REJECTED'] as $estado) {
            $this->insertVersion($estado);
        }

        // Cuando la CANARY sale (revertir), cabe otra; cuando la PUBLISHED se sustituye, también.
        DB::table('ultron_studio_versions')->where('id', $canario)->update(['status' => 'ROLLED_BACK']);
        $this->assertSame(1, DB::table('ultron_studio_versions')->where('id', $aprobada)->update(['status' => 'CANARY']));
        DB::table('ultron_studio_versions')->where('id', $publicada)->update(['status' => 'RETIRED']);
        $this->assertSame(1, DB::table('ultron_studio_versions')->where('id', $canario)->update(['status' => 'PUBLISHED']));

        $this->assertSame(1, DB::table('ultron_studio_versions')->where('status', 'CANARY')->count());
        $this->assertSame(1, DB::table('ultron_studio_versions')->where('status', 'PUBLISHED')->count());
    }

    // ── down() (§A3.4) ──────────────────────────────────────────────────────

    public function test_down_se_niega_con_una_publicacion_y_no_toca_nada(): void
    {
        $this->insertVersion('APPROVED');
        $this->insertPublication(['version_id' => null, 'action' => 'legacy', 'reason' => 'Sembrada']);

        foreach ([self::GUARDS, self::CREATE] as $migracion) {
            $this->assertDownRefused($migracion, '(1 publicaciones, 0 versiones');
        }
        $this->assertH5SchemaPresent();
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_down_se_niega_con_una_version_en_cualquier_estado_de_h5_aunque_no_haya_publicaciones(): void
    {
        $id = $this->insertVersion('APPROVED');

        foreach (self::H5_STATUSES as $estado) {
            DB::table('ultron_studio_versions')->where('id', $id)->update(['status' => $estado]);
            foreach ([self::GUARDS, self::CREATE] as $migracion) {
                $this->assertDownRefused($migracion, '(0 publicaciones, 1 versiones', $estado);
            }
        }
        $this->assertH5SchemaPresent();
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_down_sin_filas_de_h5_deja_el_esquema_de_h4_y_se_puede_volver_a_aplicar(): void
    {
        // Filas de F en sus estados: el CHECK de 5 estados las vuelve a validar.
        foreach (['DRAFT', 'APPROVED', 'REJECTED', 'APPROVED'] as $estado) {
            $this->insertVersion($estado);
        }

        $this->migration(self::GUARDS)->down();
        $this->migration(self::CREATE)->down();

        $this->assertFalse(Schema::hasTable(self::TABLE));
        $this->assertSame([], $this->partialIndexesOnVersions());
        if ($this->isPostgres()) {
            $this->assertSame(['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'], $this->statusCheckValues());
            $this->assertSame(133, $this->studioTriggerCount());  // con el H8 (+24), el H9 (+55), W9 (+4) y las plantillas de Meta (+10)
            $this->assertSame(149, $this->studioCheckCount());    // con el H8 (+34), el H9 (+53), W9 (+4) y las plantillas de Meta (+12)
            $this->assertRejected(fn () => $this->insertVersion('CANARY'), '23514', 'usv_status_chk');
            // La función es de F: down() no la toca.
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
        } else {
            $this->assertSame(116, $this->studioTriggerCount());  // con el H8 (+23), el H9 (+53), W9 (+3) y las plantillas de Meta (+9)
        }
        $this->assertSame(4, DB::table('ultron_studio_versions')->count());

        $this->migration(self::CREATE)->up();
        $this->migration(self::GUARDS)->up();

        $this->assertH5SchemaPresent();
        $this->assertSame($this->isPostgres() ? 136 : 118, $this->studioTriggerCount());   // con el H8, el H9, W9 y las plantillas de Meta
        if ($this->isPostgres()) {
            $this->assertSame(152, $this->studioCheckCount());
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    private function migration(string $name): Migration
    {
        return $this->migrations[$name] ??= require database_path("migrations/{$name}.php");
    }

    private function assertDownRefused(string $migracion, string $recuento, string $contexto = ''): void
    {
        try {
            $this->migration($migracion)->down();
            $this->fail("{$migracion}::down() no se negó {$contexto}");
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('down() se niega', $e->getMessage(), $contexto);
            $this->assertStringContainsString($recuento, $e->getMessage(), $contexto);
            $this->assertStringContainsString('§A3.4', $e->getMessage(), $contexto);
        }
    }

    /**
     * Tabla, disparadores, índices parciales y (en PG) CHECK de 9 estados: nada
     * se deshizo. Solo las guardas del H5: las del Hito 7 están o no según si
     * la prueba recreó la tabla con el down()/up() del H5.
     */
    private function assertH5SchemaPresent(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(['usv_one_canary', 'usv_one_published'], $this->partialIndexesOnVersions());
        $this->assertSame(
            $this->isPostgres() ? ['uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'] : ['uspub_forbid_delete', 'uspub_forbid_update'],
            array_values(array_diff($this->triggersOn(self::TABLE), self::H7_CHAIN_GUARDS)),
        );
        if ($this->isPostgres()) {
            $this->assertSame(self::NINE_STATUSES, $this->statusCheckValues());
            $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk'], array_values(array_diff($this->checksOn(self::TABLE), self::H7_CHAIN_GUARDS)));
        }
    }

    /**
     * La sentencia tiene que fallar en la BASE por un disparador de la cadena,
     * con el texto de `ultron_studio_forbid()`. En un SAVEPOINT.
     */
    private function assertForbidden(Closure $sentencia, string $operacion, string $que): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base dejó pasar: {$que}");
        } catch (QueryException $e) {
            $this->assertSame($this->isPostgres() ? 'P0001' : '23000', (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString('ultron_studio: '.self::REASON." ({$operacion} en ".self::TABLE.')', $e->getMessage(), $que);
        }
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    private function assertUniqueViolation(Closure $sentencia, string $indice): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed: ultron_studio_versions.status');
    }

    private function insertVersion(string $status): int
    {
        return (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'label' => 'Sembrada '.$status,
            'status' => $status,
            'content' => '{}',
            'content_hash' => str_repeat('a', 64),
            'catalog_version' => '2026-10-19.1',
            'catalog_hash' => str_repeat('b', 64),
            'source_draft_revision' => 1,
            'created_by_admin_id' => 1,
            'created_by_name' => 'Prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertPublication(array $overrides = []): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($this->publicationRow($overrides));
    }

    /**
     * Una entrada válida: `publish` sobre una versión sembrada, salvo lo que se
     * sobrescriba. Es la SIGUIENTE a la cabeza de ahora y apunta a ella (lo
     * exige `uspub_chain_next`, Hito 7): una inserción rechazada no consume
     * número.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publicationRow(array $overrides = []): array
    {
        $cabeza = DB::table(self::TABLE)->orderByDesc('seq')->first(['id', 'seq']);
        $this->seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $json = '{"canary":null,"contract":"ultron-engine-config/1","entry":"x","issuedAt":"2026-10-25T00:00:00Z","rest":null,"seq":'.$this->seq.'}';

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'seq' => $this->seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => 'publish',
            'version_id' => array_key_exists('version_id', $overrides) ? null : $this->insertVersion('APPROVED'),
            'restores_publication_id' => null,
            'previous_publication_id' => $cabeza === null ? null : (int) $cabeza->id,
            'engine_contract' => 'ultron-engine-config/1',
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => str_repeat('c', 64),
            'catalog_version' => '2026-10-19.1',
            'lab_gate' => '{"mode":"required"}',
            'acknowledged' => null,
            'evidence' => null,
            'reason' => null,
            'actor_admin_id' => 1,
            'actor_name' => 'Prueba',
            'actor_role' => null,
            'created_at' => now(),
        ], $overrides);
    }

    /** @return list<string> */
    private function triggersOn(string $table, bool $rowOnly = false): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT t.tgname FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgrelid = ?::regclass'
                .($rowOnly ? ' AND (t.tgtype & 1) = 1' : '').' ORDER BY 1', [$table]));
        }

        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->orderBy('name')->pluck('name')->all();
    }

    private function studioTriggerCount(): int
    {
        return $this->isPostgres()
            ? (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%'")->n
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count();
    }

    /** @return list<string> */
    private function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    private function studioCheckCount(): int
    {
        return (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%'")->n;
    }

    /** @return list<string> los valores de `usv_status_chk`, en su orden. */
    private function statusCheckValues(): array
    {
        $def = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'usv_status_chk' AND conrelid = 'ultron_studio_versions'::regclass")->def;
        preg_match_all("/'([A-Z_]+)'::character varying/", $def, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function partialIndexesOnVersions(): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->relname, DB::select(
                "SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = 'ultron_studio_versions'::regclass AND i.indpred IS NOT NULL ORDER BY 1"));
        }

        return collect(DB::select("PRAGMA index_list('ultron_studio_versions')"))
            ->filter(static fn (object $i): bool => (int) $i->partial === 1)->pluck('name')->sort()->values()->all();
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            // OMITIDA, no verde: el hueco tiene que verse en la suite.
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue.' Ejecuta el grupo con docs/ultron-studio/ops/pgunit.sh.');
        }
    }
}
