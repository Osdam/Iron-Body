<?php

namespace Tests\Feature\UltronStudio;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ENMIENDA M-1 DEL HITO 5 (E13 de `plans/STUDIO_MOTORES_PLAN.md`): el CHECK
 * `uspub_engine_contract_chk` admite `ultron-engine-config/1` y `/2`
 * (migración `2026_11_16_100001`, el patrón de SC-3 `2026_11_14_100001`).
 *
 *  - SOLO EN POSTGRESQL (el H5 no tiene CHECK de dominio en SQLite): `/1` y
 *    `/2` entran; `/3` y cualquier otro texto, 23514 con el nombre del CHECK;
 *    uno solo con ese nombre, validado, y el mismo número de CHECK del Studio;
 *  - el blindaje de la cadena no cambia: ni disparadores, ni ACL, ni filas; la
 *    cadena sigue siendo de solo inserción con una entrada `/2`;
 *  - `down()` se niega con una entrada `/2` sin tocar nada y, sin ellas
 *    (aunque haya entradas `/1`), restaura el CHECK EXACTO del H5 (comparado
 *    con el que escribe PostgreSQL para la expresión de su migración); `up()`
 *    otra vez lo amplía (ida y vuelta, una sola sentencia cada vez) y se niega
 *    a ampliar dos veces o sobre un esquema sin el CHECK;
 *  - no espera sin límite un cerrojo: sin `lock_timeout` en la sesión pone 5 s
 *    y, con otra transacción sobre la cadena, cae limpia (55P03);
 *  - en SQLite no hay CHECK ni disparador que mire el contrato: la migración
 *    no toca el esquema (lo vigila el Studio).
 *
 * Los recuentos son RELATIVOS (antes = después): esta migración sustituye un
 * CHECK, no suma ninguno; los absolutos los fija `UltronStudioPublicationSchemaTest`.
 * En PostgreSQL, cada sentencia que tiene que fallar va en un SAVEPOINT.
 */
class UltronStudioEngineConfigV2SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'ultron_studio_publications';

    private const MIGRATION = '2026_11_16_100001_allow_engine_config_v2_in_ultron_studio_publications';

    private const CHECK = 'uspub_engine_contract_chk';

    private const V1 = 'ultron-engine-config/1';

    private const V2 = 'ultron-engine-config/2';

    /** Una segunda conexión a la misma base, para sostener un cerrojo desde otra transacción. */
    private const OTRA = 'pgsql_m1_cerrojo';

    private ?Migration $migration = null;

    protected function tearDown(): void
    {
        DB::purge(self::OTRA);
        parent::tearDown();
    }

    // ── Los dos motores ─────────────────────────────────────────────────────

    public function test_la_expresion_nueva_es_la_del_h5_mas_el_contrato_2_y_ninguno_mas(): void
    {
        $m = $this->migration();
        $this->assertSame([self::V1, self::V2], $m::CONTRACTS);
        $this->assertSame("engine_contract IN ('ultron-engine-config/1', 'ultron-engine-config/2')", $m::expression());
        $this->assertSame("engine_contract IN ('ultron-engine-config/1')", $m::h5Expression(), 'la del H5, leída de su migración');
        $this->assertSame(str_replace(", '".self::V2."')", ')', $m::expression()), $m::h5Expression(), 'quitando el nuevo queda la del H5, literal');
    }

    public function test_en_sqlite_no_hay_check_ni_disparador_del_contrato_y_la_migracion_no_toca_el_esquema(): void
    {
        if ($this->isPostgres()) {
            $this->markTestSkipped('Solo SQLite: en PostgreSQL lo cubren las pruebas de abajo.');
        }
        $objetos = DB::table('sqlite_master')->where('tbl_name', self::TABLE)->orderBy('name')->get(['type', 'name', 'sql']);
        $esquema = static fn (): array => DB::table('sqlite_master')->where('tbl_name', self::TABLE)->orderBy('name')->get(['type', 'name', 'sql'])
            ->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();

        // No hay equivalente que ampliar: ni CHECK en la tabla ni disparador que mire el contrato.
        $tabla = (string) $objetos->firstWhere('type', 'table')->sql;
        $this->assertStringNotContainsStringIgnoringCase('check', $tabla);
        $disparadores = $objetos->where('type', 'trigger');
        $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $disparadores->pluck('name')->values()->all());
        foreach ($disparadores as $d) {
            $this->assertStringNotContainsString('engine_contract', (string) $d->sql, $d->name);
            $this->assertStringNotContainsString('engine-config', (string) $d->sql, $d->name);
        }

        $antes = $esquema();
        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame($antes, $esquema());
        $this->assertAccepted(fn () => $this->insertPublication(['engine_contract' => self::V2]), 'una entrada /2');
    }

    // ── Solo PostgreSQL ─────────────────────────────────────────────────────

    public function test_solo_en_postgres_el_check_admite_1_y_2_y_rechaza_3_y_cualquier_otro(): void
    {
        $this->requirePostgres();

        $h = $this->huella();
        $this->assertSame([1, true], [$h['named'], $h['validated']], 'uno solo con ese nombre, validado');
        $this->assertSame("CHECK (((engine_contract)::text = ANY ((ARRAY['ultron-engine-config/1'::character varying, 'ultron-engine-config/2'::character varying])::text[])))", $h['definition']);
        $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE), 'la cadena conserva sus cuatro CHECK');

        foreach ([self::V1, self::V2] as $contrato) {
            $this->assertAccepted(fn () => $this->insertPublication(['engine_contract' => $contrato]), $contrato);
        }
        foreach (['ultron-engine-config/3', 'ultron-engine-config/0', 'ULTRON-ENGINE-CONFIG/2', 'ultron-engine-config/2 ', ' ultron-engine-config/2', 'ultron-engine-config/', ''] as $otro) {
            $this->assertRejected(fn () => $this->insertPublication(['engine_contract' => $otro]), '23514', self::CHECK, "«{$otro}»");
        }
        $this->assertSame([self::V1 => 1, self::V2 => 1], $this->filasPorContrato());
    }

    public function test_solo_en_postgres_una_entrada_2_sigue_siendo_de_solo_insercion(): void
    {
        $this->requirePostgres();
        $id = $this->insertPublication(['engine_contract' => self::V2]);

        foreach ([
            'UPDATE' => fn () => DB::table(self::TABLE)->where('id', $id)->update(['engine_contract' => self::V1]),
            'DELETE' => fn () => DB::table(self::TABLE)->where('id', $id)->delete(),
            'TRUNCATE' => fn () => DB::statement('TRUNCATE '.self::TABLE),
        ] as $operacion => $intento) {
            try {
                DB::transaction(fn () => $intento());
                $this->fail("La base dejó pasar {$operacion} sobre una entrada /2");
            } catch (QueryException $e) {
                $this->assertSame('P0001', (string) $e->getCode(), $e->getMessage());
                $this->assertStringContainsString("ultron_studio: la cadena de publicaciones es de solo inserción ({$operacion} en ".self::TABLE.')', $e->getMessage());
            }
        }
        $this->assertSame([self::V2 => 1], $this->filasPorContrato());
    }

    public function test_solo_en_postgres_down_se_niega_con_una_entrada_2_y_no_toca_nada(): void
    {
        $this->requirePostgres();
        $this->insertPublication(['engine_contract' => self::V1]);
        $this->insertPublication(['engine_contract' => self::V2]);
        $antes = $this->huella();

        try {
            $this->migration()->down();
            $this->fail('down() no se negó con una entrada /2');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('down() se niega a restaurar el CHECK del Hito 5', $e->getMessage());
            $this->assertStringContainsString('(1 con «ultron-engine-config/2»)', $e->getMessage());
            $this->assertStringContainsString('R10', $e->getMessage());
        }
        $this->assertSame($antes, $this->huella(), 'down() no tocó nada');
        $this->assertSame([self::V1 => 1, self::V2 => 1], $this->filasPorContrato(), 'ni borró ninguna fila');
    }

    public function test_solo_en_postgres_ida_y_vuelta_restaura_el_check_exacto_del_h5_y_no_cambia_el_blindaje(): void
    {
        $this->requirePostgres();
        $m = $this->migration();

        // Una ACL explícita (vuelve atrás con la transacción de la prueba), para que comparar privilegios signifique algo.
        DB::statement('GRANT SELECT ON '.self::TABLE.' TO PUBLIC');
        // Entradas /1: down() las admite (el CHECK del H5 también).
        $this->insertPublication(['engine_contract' => self::V1]);
        $this->insertPublication(['engine_contract' => self::V1]);
        $ampliado = $this->huella();
        $this->assertNotSame('', $ampliado['acl']);

        DB::enableQueryLog();
        $m->down();
        $this->assertUnaSolaSentencia('down()');
        $h5 = $this->huella();
        $this->assertSame($this->definicionDe($m::h5Expression()), $h5['definition'], 'down() deja el CHECK EXACTO del H5');
        $this->assertSame("CHECK (((engine_contract)::text = 'ultron-engine-config/1'::text))", $h5['definition']);
        $this->assertSame(array_diff_key($ampliado, ['definition' => 0]), array_diff_key($h5, ['definition' => 0]),
            'mismo número de CHECK, uno validado, mismos disparadores, misma ACL, mismas filas');
        $this->assertRejected(fn () => $this->insertPublication(['engine_contract' => self::V2]), '23514', self::CHECK, 'el H5 no admite /2');

        DB::flushQueryLog();   // el ALTER del oráculo (tabla temporal) no es de la migración
        $m->up();
        $this->assertUnaSolaSentencia('up()');
        $this->assertSame($ampliado, $this->huella(), 'up() sobre el H5 deja lo mismo que el migrate');
        $this->assertAccepted(fn () => $this->insertPublication(['engine_contract' => self::V2]), 'otra vez /2');
        DB::disableQueryLog();
        $this->assertSame([self::V1 => 2, self::V2 => 1], $this->filasPorContrato());
    }

    public function test_solo_en_postgres_up_se_niega_a_ampliar_dos_veces_o_sin_el_check(): void
    {
        $this->requirePostgres();
        $antes = $this->huella();

        try {
            $this->migration()->up();
            $this->fail('up() amplió dos veces el CHECK');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
            $this->assertStringContainsString('ya admite «ultron-engine-config/2»', $e->getMessage());
        }
        $this->assertSame($antes, $this->huella());

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CHECK);
        try {
            $this->migration()->up();
            $this->fail('up() se aplicó sobre un esquema sin '.self::CHECK);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(self::CHECK.' no existe exactamente una vez en '.self::TABLE.' (0)', $e->getMessage());
        }
        $this->assertSame(0, $this->huella()['named'], 'no lo creó a medias');
    }

    public function test_solo_en_postgres_no_espera_sin_limite_un_cerrojo(): void
    {
        $this->requirePostgres();
        $antes = $this->huella();
        $m = $this->migration();

        // Otra transacción LEE la cadena (AccessShareLock, como el lector del motor): ACCESS EXCLUSIVE choca con ella.
        config(['database.connections.'.self::OTRA => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection(self::OTRA);
        $otra->beginTransaction();
        $otra->statement('LOCK TABLE '.self::TABLE.' IN ACCESS SHARE MODE NOWAIT');
        try {
            DB::statement("SET LOCAL lock_timeout = '200ms'");
            $t0 = microtime(true);
            try {
                $m->down();
                $this->fail('down() esperó el cerrojo o lo atravesó');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
            }
            $this->assertLessThan(5.0, microtime(true) - $t0, 'cae con el lock_timeout de la sesión, no espera sin límite');
            $this->assertSame($antes, $this->huella(), 'no tocó nada');
        } finally {
            $otra->rollBack();
        }

        // Sin límite en la sesión, la migración pone el suyo (5 s) para lo que queda de la transacción.
        DB::statement('SET LOCAL lock_timeout = 0');
        $m->down();
        $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"));
        DB::statement('SET LOCAL lock_timeout = 0');
        $m->up();
        $this->assertSame('5s', (string) DB::scalar("SELECT current_setting('lock_timeout')"));
        $this->assertSame($antes, $this->huella());
    }

    // ────────────────────────────────────────────────────────────────────────

    private function migration(): Migration
    {
        return $this->migration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** DROP + ADD en UNA sentencia: un solo cerrojo y ningún instante sin CHECK (vacía el registro de consultas). */
    private function assertUnaSolaSentencia(string $que): void
    {
        $alters = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $q): bool => str_starts_with($q, 'ALTER TABLE')));
        DB::flushQueryLog();
        $this->assertCount(1, $alters, "{$que}: un solo ALTER TABLE");
        $this->assertMatchesRegularExpression('/^ALTER TABLE ultron_studio_publications DROP CONSTRAINT (IF EXISTS )?uspub_engine_contract_chk, ADD CONSTRAINT uspub_engine_contract_chk CHECK \\(engine_contract IN \\(/', $alters[0], $que);
    }

    /**
     * Cómo escribe PostgreSQL la expresión, sin tocar la cadena: un CHECK
     * idéntico sobre una tabla temporal con la misma columna (desaparece con
     * la transacción de la prueba).
     */
    private function definicionDe(string $expression): string
    {
        DB::statement('CREATE TEMP TABLE m1_oraculo (engine_contract varchar(32) NOT NULL) ON COMMIT DROP');
        DB::statement('ALTER TABLE m1_oraculo ADD CONSTRAINT m1_oraculo_chk CHECK ('.$expression.')');
        $def = (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'm1_oraculo_chk' AND conrelid = 'm1_oraculo'::regclass");
        DB::statement('DROP TABLE m1_oraculo');

        return $def;
    }

    /** @return array{checks: int, named: int, validated: bool, definition: string, triggers: list<string>, acl: string, rows: array<string, int>} */
    private function huella(): array
    {
        $filas = DB::select('SELECT pg_get_constraintdef(oid) AS def, convalidated AS validado FROM pg_constraint WHERE contype = ? AND conrelid = ?::regclass AND conname = ?',
            ['c', self::TABLE, self::CHECK]);

        return [
            'checks' => (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\\_studio\\_%'"),
            'named' => count($filas),
            'validated' => (bool) ($filas[0]->validado ?? false),
            'definition' => (string) ($filas[0]->def ?? ''),
            'triggers' => array_map(static fn (object $t): string => $t->tgname.':'.$t->tgenabled,
                DB::select('SELECT tgname, tgenabled FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY tgname', [self::TABLE])),
            'acl' => (string) DB::scalar('SELECT coalesce(relacl::text, \'\') FROM pg_class WHERE oid = ?::regclass', [self::TABLE]),
            'rows' => $this->filasPorContrato(),
        ];
    }

    /** @return array<string, int> */
    private function filasPorContrato(): array
    {
        return DB::table(self::TABLE)->groupBy('engine_contract')->orderBy('engine_contract')
            ->selectRaw('engine_contract AS c, count(*) AS n')->pluck('n', 'c')->map(static fn ($n): int => (int) $n)->all();
    }

    /** @return list<string> */
    private function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    private function assertAccepted(Closure $sentencia, string $que): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->addToAssertionCount(1);
        } catch (QueryException $e) {
            $this->fail("La base rechazó {$que}: ".$e->getMessage());
        }
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó {$que} ({$fragmento}).");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    private function insertVersion(): int
    {
        return (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'label' => 'Sembrada M-1',
            'status' => 'APPROVED',
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

    /**
     * Una entrada `publish` válida: la SIGUIENTE a la cabeza y apuntando a
     * ella (`uspub_chain_next`, Hito 7), con el contrato también dentro de los
     * bytes firmados. Una inserción rechazada no consume número.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertPublication(array $overrides = []): int
    {
        $cabeza = DB::table(self::TABLE)->orderByDesc('seq')->first(['id', 'seq']);
        $seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $contrato = (string) ($overrides['engine_contract'] ?? self::V1);
        $json = '{"canary":null,"contract":'.json_encode($contrato).',"entry":"x","issuedAt":"2026-11-16T00:00:00Z","rest":null,"seq":'.$seq.'}';

        return (int) DB::table(self::TABLE)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => 'publish',
            'version_id' => $this->insertVersion(),
            'restores_publication_id' => null,
            'previous_publication_id' => $cabeza === null ? null : (int) $cabeza->id,
            'engine_contract' => $contrato,
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
        ], $overrides));
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(): void
    {
        if (! $this->isPostgres()) {
            // OMITIDA, no verde: el hueco tiene que verse en la suite.
            $this->markTestSkipped('Solo PostgreSQL: el CHECK de dominio de la cadena solo existe allí (H5). Ejecuta el grupo con docs/ultron-studio/ops/pgunit.sh.');
        }
    }
}
