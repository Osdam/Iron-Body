<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Support\Privacy\PhoneFingerprint;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * EL ENDURECIMIENTO DEL HITO 7 (contrato H7, paquete DB7; AS-3 / N-3 y
 * Enmienda 2 §E2.5 del H5): la migración 2026_11_02_100001 y la comprobación
 * previa del canario duplicado (H-2 del H6).
 *
 * Se prueba la BASE, con SQL a pelo y sin los modelos, en los dos motores (la
 * suite en SQLite y la puerta `docs/ultron-studio/ops/pgunit.sh` en
 * PostgreSQL). Lo esperado sale del contrato y está escrito aquí, no tomado de
 * las constantes de la migración:
 *  · la cadena solo admite la SIGUIENTE a la cabeza y apuntando a ella: un
 *    `seq` saltado o una entrada sin encadenar es P0001 (23000 en SQLite);
 *    `seq` < 1 es 23514 (`uspub_seq_chk`; en SQLite, el disparador
 *    `uspub_seq_min`); un `seq` repetido sigue siendo el UNIQUE (23505), la
 *    carrera perdida que el servicio traduce a 409 `publication_moved`;
 *  · en PG, el rol de la aplicación (modelo dueño/app reproducido con un rol de
 *    prueba creado y deshecho dentro de la transacción de la prueba) ya no puede
 *    UPDATE ni DELETE en la cadena, el libro y las instantáneas (42501), y sigue
 *    insertando y leyendo; asociaciones y canarios no se tocan;
 *  · up() se niega con una cadena existente rota; down() se niega con filas en
 *    cualquiera de las tres tablas y, sin ellas, deja el esquema del H6 y up() lo
 *    vuelve a dejar igual;
 *  · recuentos del Studio: PG 43 → 44 disparadores y 49 → 50 CHECK; SQLite
 *    30 → 32 disparadores. `ultron_studio_forbid()` no cambia (md5 de F).
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en un SAVEPOINT: sin él
 * el error abortaría la transacción de la prueba.
 */
class UltronStudioHardeningH7Test extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const MIGRATION = '2026_11_02_100001_add_ultron_studio_hardening';

    private const TABLE = 'ultron_studio_publications';

    private const AUDIT = 'ultron_studio_audit_events';

    private const SNAPSHOTS = 'ultron_studio_integration_snapshots';

    private const LINKS = 'ultron_studio_campaign_links';

    private const CANARIES = 'ultron_studio_canary_numbers';

    /** Las tres de solo inserción (DB7 b): sin UPDATE ni DELETE para la aplicación. */
    private const INSERT_ONLY = [self::TABLE, self::AUDIT, self::SNAPSHOTS];

    private const CHAIN_MESSAGE = 'ultron_studio: una entrada nueva de la cadena tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id) (INSERT en ultron_studio_publications)';

    private const SEQ_MESSAGE = 'ultron_studio: seq empieza en 1 (INSERT en ultron_studio_publications)';

    /** Recuentos de todo el Studio (tablas `ultron_studio_%`). */
    private const PG_TRIGGERS_H6 = 122;  // con el H8 (+24) y el H9 (+55)

    private const PG_TRIGGERS_H7 = 123;  // + uspub_chain_next

    private const PG_CHECKS_H6 = 136;    // con el H8 (+34) y el H9 (+53)

    private const PG_CHECKS_H7 = 137;    // + uspub_seq_chk

    private const SQLITE_TRIGGERS_H6 = 106; // con el H8 (+23) y el H9 (+53)

    private const SQLITE_TRIGGERS_H7 = 108; // + uspub_chain_next y uspub_seq_min

    /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
    private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';

    /** El rol de prueba que hace de `iron_app` (solo PG); se crea y se deshace dentro de cada prueba. */
    private const APP_ROLE = 'ultron_studio_h7_db_app';

    private const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    /** Un teléfono que solo usa esta prueba: su huella permite buscarlo en el log del servidor. */
    private const PHONE = '+573009990707';

    private ?Migration $migration = null;

    protected function tearDown(): void
    {
        putenv(self::APP_ROLE_ENV);
        parent::tearDown();
    }

    // ── Recuentos y nombres ─────────────────────────────────────────────────

    public function test_recuentos_y_nombres_de_las_guardas_de_la_cadena(): void
    {
        if ($this->isPostgres()) {
            $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
            $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
            $this->assertSame('CHECK ((seq >= 1))', DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_seq_chk'"));
            $this->assertSame(self::PG_TRIGGERS_H7, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
            $this->assertSame(self::PG_CHECKS_H7, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53');

            // El disparador de la cadena: BEFORE INSERT, por fila, con su función; los tres de H5 siguen con la de F.
            $def = (string) DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'uspub_chain_next'");
            $this->assertStringContainsString('BEFORE INSERT ON public.ultron_studio_publications FOR EACH ROW EXECUTE FUNCTION ultron_studio_publication_chain(', $def);
            $this->assertSame(3, (int) DB::scalar(
                "SELECT count(*) FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND t.tgrelid = 'ultron_studio_publications'::regclass AND p.proname = 'ultron_studio_forbid'"));

            // La función propia: plpgsql, VOLATILE, INVOKER, del rol que migra, y la tabla CUALIFICADA.
            $f = DB::selectOne("SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = 'ultron_studio_publication_chain'");
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner]);
            $this->assertStringContainsString('FROM public.ultron_studio_publications', $f->src);

            // La de F, intacta (ni se redeclara).
            $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
            $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
            $this->assertStringNotContainsString('FUNCTION ultron_studio_forbid', (string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php')));

            return;
        }

        $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
        $this->assertSame(self::SQLITE_TRIGGERS_H7, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
    }

    // ── La cadena: la siguiente, encadenada ─────────────────────────────────

    public function test_la_cadena_admite_la_siguiente_encadenada(): void
    {
        $uno = $this->insertPublication(1, null);
        $dos = $this->insertPublication(2, $uno);
        $tres = $this->insertPublication(3, $dos);

        $this->assertSame([[1, null], [2, $uno], [3, $dos]], DB::table(self::TABLE)->orderBy('seq')->get(['seq', 'previous_publication_id'])
            ->map(static fn (object $f): array => [(int) $f->seq, $f->previous_publication_id === null ? null : (int) $f->previous_publication_id])->all());
        $this->assertSame($tres, (int) DB::table(self::TABLE)->orderByDesc('seq')->value('id'));
    }

    /**
     * @return array<string, array{0: int, 1: Closure(array<int, int>): array{int, ?int}}>
     */
    public static function brokenEntries(): array
    {
        // $ids: seq → id de las dos entradas sembradas (1 y 2). Cada caso: [entradas a sembrar, la nueva].
        return [
            'cadena vacía: seq 2' => [0, static fn (array $ids): array => [2, null]],
            'cadena vacía: seq 1 apuntando a algo' => [0, static fn (array $ids): array => [1, 999999]],
            'seq saltado (3 tras 1)' => [1, static fn (array $ids): array => [3, $ids[1]]],
            'la siguiente sin encadenar (NULL)' => [1, static fn (array $ids): array => [2, null]],
            'el seq envenenado de AS-3' => [1, static fn (array $ids): array => [2147483647, $ids[1]]],
            'la siguiente apuntando a la anterior de la cabeza' => [2, static fn (array $ids): array => [3, $ids[1]]],
            'la siguiente apuntando a sí misma por adelantado' => [2, static fn (array $ids): array => [3, 999999]],
        ];
    }

    /** @param  Closure(array<int, int>): array{int, ?int}  $nueva */
    #[DataProvider('brokenEntries')]
    public function test_un_seq_saltado_o_una_entrada_sin_encadenar_la_para_el_disparador(int $sembradas, Closure $nueva): void
    {
        $ids = [];
        for ($seq = 1; $seq <= $sembradas; $seq++) {
            $ids[$seq] = $this->insertPublication($seq, $ids[$seq - 1] ?? null);
        }
        [$seq, $previa] = $nueva($ids);

        $this->assertChainRejected(fn () => $this->insertPublication($seq, $previa));

        // Nada cambió, y la siguiente de verdad sigue entrando.
        $this->assertSame($sembradas, DB::table(self::TABLE)->count());
        $this->insertPublication($sembradas + 1, $ids[$sembradas] ?? null);
    }

    public function test_seq_cero_o_negativo_lo_para_uspub_seq_chk(): void
    {
        foreach ([0, -1] as $seq) {
            $this->assertSeqRejected(fn () => $this->insertPublication($seq, null), "cadena vacía, seq {$seq}");
        }
        $uno = $this->insertPublication(1, null);
        foreach ([0, -5] as $seq) {
            $this->assertSeqRejected(fn () => $this->insertPublication($seq, $uno), "tras la 1, seq {$seq}");
        }
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    /**
     * La carrera perdida tiene que seguir siendo el UNIQUE de `seq`: el
     * servicio reconoce ESTE error (nombre del índice en PG, columna en SQLite)
     * y responde 409 `publication_moved` (contrato H5 §A5, paso 12).
     */
    public function test_un_seq_repetido_sigue_siendo_el_unique_de_la_carrera_perdida(): void
    {
        $uno = $this->insertPublication(1, null);
        $dos = $this->insertPublication(2, $uno);

        foreach ([[2, $uno], [1, null], [2, null], [1, $dos]] as [$seq, $previa]) {
            $this->isPostgres()
                ? $this->assertRejected(fn () => $this->insertPublication($seq, $previa), '23505', 'ultron_studio_publications_seq_unique')
                : $this->assertRejected(fn () => $this->insertPublication($seq, $previa), '23000', 'UNIQUE constraint failed: ultron_studio_publications.seq');
        }
        $this->assertSame(2, DB::table(self::TABLE)->count());
    }

    public function test_solo_en_postgres_una_tabla_temporal_no_suplanta_a_la_cadena(): void
    {
        $this->requirePostgres('pg_temp y la búsqueda de tablas temporales solo existen en PostgreSQL.');

        $uno = $this->insertPublication(1, null);
        // Una tabla temporal con el mismo nombre se busca ANTES que public: con
        // ella, una función sin el esquema leería esta cabeza falsa.
        DB::statement('CREATE TEMP TABLE ultron_studio_publications (id bigint, seq bigint) ON COMMIT DROP');
        DB::statement('INSERT INTO pg_temp.ultron_studio_publications (id, seq) VALUES (?, 2147483646)', [$uno]);

        $this->assertChainRejected(fn () => DB::table('public.'.self::TABLE)->insert($this->publicationRow(2147483647, $uno)));
        $this->assertSame(1, (int) DB::table('public.'.self::TABLE)->count());
        DB::statement('DROP TABLE pg_temp.ultron_studio_publications');
    }

    // ── El rol de la aplicación (solo PG) ───────────────────────────────────

    public function test_el_rol_de_la_aplicacion_es_iron_app_salvo_que_el_entorno_diga_otro(): void
    {
        $m = $this->migration();
        putenv(self::APP_ROLE_ENV);
        $this->assertSame('iron_app', $m::appRole());
        putenv(self::APP_ROLE_ENV.'=h5_app');
        $this->assertSame('h5_app', $m::appRole());
        foreach (['Iron_App', 'iron-app', 'iron_app"; DROP TABLE x; --', '1iron', str_repeat('a', 64)] as $malo) {
            putenv(self::APP_ROLE_ENV.'='.$malo);
            try {
                $m::appRole();
                $this->fail("Aceptó un nombre de rol inválido: {$malo}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no es un nombre de rol válido', $e->getMessage());
            }
        }
    }

    public function test_solo_en_postgres_el_rol_de_la_aplicacion_no_modifica_ni_borra_las_tablas_de_solo_insercion(): void
    {
        $this->requirePostgres('Los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (Migration $m): void {
            $this->grantProductionModel();
            foreach (self::INSERT_ONLY as $t) {
                $this->assertSame([true, true, true, true, false], $this->privileges($t), "{$t}, antes (arwd por los privilegios por defecto)");
            }

            $m->down();   // tablas vacías: se puede
            $m->up();     // el REVOKE, ahora con el rol presente

            foreach (self::INSERT_ONLY as $t) {
                // SELECT, INSERT, UPDATE (también por columna), DELETE, TRUNCATE.
                $this->assertSame([true, true, false, false, false], $this->privileges($t), $t);
                $this->assertTrue((bool) DB::scalar("SELECT has_sequence_privilege(?, ?, 'USAGE')::int", [self::APP_ROLE, "public.{$t}_id_seq"]), "{$t}: secuencia");
            }
            foreach ([self::LINKS, self::CANARIES] as $t) {
                $this->assertSame([true, true, true, true, false], $this->privileges($t), "{$t}: no se toca (UPDATE es su máquina de estados)");
            }

            // Inserta (también la entrada encadenada, cuya FK propia comprueba el dueño) y lee.
            $this->assertNull($this->asApp(function (): void {
                $uno = $this->insertPublication(1, null);
                $this->insertPublication(2, $uno);
                $this->insertEvent();
                $this->insertSnapshot();
                $this->assertSame([2, 1, 1], [DB::table(self::TABLE)->count(), DB::table(self::AUDIT)->count(), DB::table(self::SNAPSHOTS)->count()]);
            })[0], 'el rol de la aplicación ya no inserta');

            // Ni modifica ni borra (42501, antes que los disparadores), ni bloquea filas.
            foreach (self::INSERT_ONLY as $t) {
                foreach ([
                    "UPDATE {$t} SET created_at = created_at WHERE id = -1",
                    "DELETE FROM {$t} WHERE id = -1",
                    "SELECT id FROM {$t} WHERE id = -1 FOR UPDATE",
                    "SELECT id FROM {$t} WHERE id = -1 FOR SHARE",
                    "TRUNCATE {$t}",
                ] as $sql) {
                    [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                    $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
                    $this->assertStringContainsString("permission denied for table {$t}", $mensaje, $sql);
                }
            }
            // Ni toca las guardas nuevas.
            foreach ([
                'ALTER TABLE ultron_studio_publications DISABLE TRIGGER uspub_chain_next',
                'DROP TRIGGER uspub_chain_next ON ultron_studio_publications',
                'ALTER TABLE ultron_studio_publications DROP CONSTRAINT uspub_seq_chk',
                'CREATE OR REPLACE FUNCTION ultron_studio_publication_chain() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
            ] as $sql) {
                $this->assertSame('42501', $this->asApp(fn () => DB::statement($sql))[0], $sql);
            }

            // down() le devuelve UPDATE y DELETE (conserva INSERT) y up() se los vuelve a quitar.
            $m->down();
            foreach (self::INSERT_ONLY as $t) {
                $this->assertSame([true, true, true, true, false], $this->privileges($t), "{$t}, tras down()");
            }
            $m->up();
            foreach (self::INSERT_ONLY as $t) {
                $this->assertSame([true, true, false, false, false], $this->privileges($t), "{$t}, tras up()");
            }
        });
    }

    public function test_solo_en_postgres_up_comprueba_que_el_rol_ya_no_puede_y_si_no_falla_sin_dejar_nada(): void
    {
        $this->requirePostgres('Los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (Migration $m): void {
            $this->grantProductionModel();
            $m->down();
            // UPDATE por PUBLIC (o heredado de otro rol): el REVOKE al rol no lo quita.
            DB::statement('GRANT UPDATE ON '.self::AUDIT.' TO PUBLIC');

            try {
                $m->up();
                $this->fail('up() dio por buena una aplicación que todavía puede modificar el libro');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede UPDATE en ultron_studio_audit_events', $e->getMessage());
            }
            // up() es atómico: ni disparador, ni CHECK, ni función, ni REVOKE.
            $this->assertSame(['uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
            $this->assertNotContains('uspub_seq_chk', $this->checksOn(self::TABLE));
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_publication_chain'"));
            $this->assertSame([true, true, true, true, false], $this->privileges(self::TABLE));
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('Los roles solo existen en PostgreSQL.');

        $m = $this->migration();
        $nadie = 'ultron_studio_h7_rol_inexistente';
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$nadie]));
        putenv(self::APP_ROLE_ENV.'='.$nadie);

        DB::beginTransaction();
        try {
            $m->down();
            $m->up();   // en «testing»: nada que revocar, las guardas sí
            $this->assertContains('uspub_chain_next', $this->triggersOn(self::TABLE));

            $m->down();
            $this->app['env'] = 'production';
            try {
                $m->up();
                $this->fail('En producción, up() se calló un REVOKE que no podía aplicar');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("el rol de la aplicación «{$nadie}» no existe en este clúster y APP_ENV es production", $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';
            }
            $this->assertNotContains('uspub_chain_next', $this->triggersOn(self::TABLE), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('uspub_chain_next', $this->triggersOn(self::TABLE));
    }

    // ── up() ante una cadena existente rota ─────────────────────────────────

    /** @return array<string, array{list<array{int, ?string}>, string}> */
    public static function brokenChains(): array
    {
        // [seq, a quién apunta: null, o el seq de otra entrada sembrada], y lo que dice el error.
        return [
            'con un hueco' => [[[1, null], [3, '1']], '(1 de 2 entradas'],
            'sin encadenar' => [[[1, null], [2, null]], '(1 de 2 entradas'],
            'empieza en 2' => [[[2, null], [3, '2']], '(2 de 2 entradas'],
            'encadenada a la anterior de la cabeza' => [[[1, null], [2, '1'], [3, '1']], '(1 de 3 entradas'],
            'el seq envenenado' => [[[1, null], [2147483647, '1']], '(1 de 2 entradas'],
        ];
    }

    /** @param  list<array{int, ?string}>  $entradas */
    #[DataProvider('brokenChains')]
    public function test_up_se_niega_con_una_cadena_existente_rota_y_no_crea_nada(array $entradas, string $recuento): void
    {
        $m = $this->migration();
        $antes = $this->guardsFootprint();

        DB::beginTransaction();
        try {
            $m->down();
            $ids = [];
            foreach ($entradas as [$seq, $apunta]) {
                $ids[$seq] = $this->insertPublication($seq, $apunta === null ? null : $ids[(int) $apunta]);
            }
            try {
                $m->up();
                $this->fail('up() aplicó las guardas sobre una cadena rota');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('la cadena de publicaciones no es consecutiva desde 1 o no está encadenada', $e->getMessage());
                $this->assertStringContainsString($recuento, $e->getMessage());
            }
            $this->assertNotContains('uspub_chain_next', $this->triggersOn(self::TABLE), 'up() dejó la mitad');
            $this->assertSame(count($entradas), DB::table(self::TABLE)->count(), 'up() tocó la cadena');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->guardsFootprint());
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_con_filas_en_cualquiera_de_las_tres_tablas_y_no_toca_nada(): void
    {
        $m = $this->migration();
        $antes = $this->guardsFootprint();

        foreach ([
            'una publicación' => ['(1 publicaciones, 0 eventos del libro y 0 instantáneas de integración)', fn () => $this->insertPublication(1, null)],
            'un evento del libro' => ['(0 publicaciones, 1 eventos del libro y 0 instantáneas de integración)', fn () => $this->insertEvent()],
            'una instantánea' => ['(0 publicaciones, 0 eventos del libro y 1 instantáneas de integración)', fn () => $this->insertSnapshot()],
        ] as $que => [$recuento, $siembra]) {
            // Cada caso en su SAVEPOINT: la fila sembrada no se puede borrar (para eso está la guarda).
            DB::beginTransaction();
            try {
                $siembra();
                try {
                    $m->down();
                    $this->fail("down() no se negó con {$que}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('down() se niega a quitar el endurecimiento', $e->getMessage(), $que);
                    $this->assertStringContainsString($recuento, $e->getMessage(), $que);
                }
                $this->assertSame($antes, $this->guardsFootprint(), "down() tocó el esquema con {$que}");
                $this->assertSame(1, DB::table(self::TABLE)->count() + DB::table(self::AUDIT)->count() + DB::table(self::SNAPSHOTS)->count(), $que);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_filas_deja_el_esquema_del_hito_6_y_up_lo_vuelve_a_dejar_igual(): void
    {
        $m = $this->migration();
        $h7 = $this->guardsFootprint();
        $tabla = $this->tableDefinition();

        $m->down();
        $h6 = $this->guardsFootprint();
        if ($this->isPostgres()) {
            $this->assertSame(['uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
            $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk'], $this->checksOn(self::TABLE));
            $this->assertSame([self::PG_TRIGGERS_H6, self::PG_CHECKS_H6], [$this->studioTriggerCount(), $this->studioCheckCount()]);
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_publication_chain'"));
            $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        } else {
            $this->assertSame(['uspub_forbid_delete', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
            $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount());
        }
        // Sin la guarda, la cadena vuelve a aceptar lo que el H6 aceptaba (prueba de que down() la quitó de verdad).
        DB::beginTransaction();
        try {
            $this->insertPublication(5, null);
            $this->assertSame(1, DB::table(self::TABLE)->count());
        } finally {
            DB::rollBack();
        }

        $m->up();
        $this->assertSame($h7, $this->guardsFootprint(), 'up() no dejó lo mismo que la primera vez');
        $this->assertSame($tabla, $this->tableDefinition(), 'la tabla de la cadena cambió (SQLite no la reconstruye; PG no gana columnas ni índices)');
        $this->assertNotSame($h6, $h7);
    }

    // ── H-2 del H6: el canario duplicado no llega al 23505 ──────────────────

    /**
     * El mismo teléfono ya vivo en el canal se contesta con la comprobación
     * previa, DESPUÉS del cerrojo consultivo de vivos (en PG) y SIN llegar al
     * INSERT: el 23505 de `uscn_one_live` (cuyo DETAIL lleva la huella al log
     * del servidor) queda para la carrera real, que cubre
     * `UltronStudioIntegrationsRaceTest` en PG.
     */
    public function test_el_telefono_ya_vivo_se_contesta_bajo_el_cerrojo_sin_llegar_al_insert(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: true);
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $uuid = (string) $this->registerCanary($ana, self::PHONE)->assertCreated()->json('data.number.uuid');

        // ANTES de ejecutarse: también las que fallan (un INSERT duplicado
        // rechazado por `uscn_one_live` no llega a QueryExecuted).
        $sentencias = [];
        DB::connection()->beforeExecuting(static function (string $query) use (&$sentencias): void {
            $sentencias[] = strtolower($query);
        });
        $r = $this->registerCanary($ana, '+57 300 999 0707', alias: 'Otra función')->assertStatus(409)->assertJsonPath('code', 'canary_number_exists');

        $insert = array_keys(array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'insert into "ultron_studio_canary_numbers"')));
        $this->assertSame([], $insert, 'el duplicado llegó al INSERT (y en PG, al 23505 de uscn_one_live)');
        $existe = array_keys(array_filter($sentencias, static fn (string $s): bool => str_contains($s, 'from "ultron_studio_canary_numbers" where "channel" = ? and "phone_hash" = ? and "status" <> ?')));
        $this->assertCount(1, $existe, 'la comprobación previa no corrió (o corrió dos veces)');
        if ($this->isPostgres()) {
            $cerrojo = array_keys(array_filter($sentencias, static fn (string $s): bool => str_contains($s, 'pg_advisory_xact_lock')));
            $this->assertNotSame([], $cerrojo);
            $this->assertLessThan($existe[0], $cerrojo[0], 'la comprobación previa corrió antes de tomar el cerrojo de vivos');
        }

        $this->assertSame([$uuid], StudioCanaryNumber::query()->where('phone_hash', PhoneFingerprint::of(self::PHONE))->pluck('uuid')->all());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'canary_number.registered')->count());
        $this->assertStringNotContainsString(PhoneFingerprint::of(self::PHONE), (string) $r->getContent());
        $this->assertStringNotContainsString('0707', (string) $r->getContent());

        // En el otro canal es otra fila, y revocado se puede volver a registrar.
        $this->registerCanary($ana, self::PHONE, 'voice', 'El mismo en voz')->assertCreated();
        $this->integrations('POST', "canaries/{$uuid}/revoke", $ana, ['reason' => 'Se vuelve a registrar'])->assertOk();
        $this->registerCanary($ana, self::PHONE, alias: 'Vuelto a registrar')->assertCreated();
    }

    /** El tope de vivos se cuenta antes que el duplicado: con los dos, el 409 sigue siendo el del tope, como antes. */
    public function test_con_el_tope_de_vivos_lleno_gana_el_tope_como_antes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: true);
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->registerCanary($ana, self::PHONE)->assertCreated();
        for ($i = 1; $i <= 9; $i++) {
            $this->registerCanary($ana, sprintf('+5730099900%02d', $i), alias: "Relleno {$i}")->assertCreated();
        }

        $this->registerCanary($ana, self::PHONE, alias: 'Otra vez')->assertStatus(409)->assertJsonPath('code', 'canary_live_limit');
        $this->assertSame(10, StudioCanaryNumber::query()->where('channel', 'whatsapp')->where('status', '<>', 'revoked')->count());
    }

    // ────────────────────────────────────────────────────────────────────────

    private function migration(): Migration
    {
        return $this->migration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /**
     * El modelo dueño/app de producción dentro de un SAVEPOINT que SIEMPRE se
     * deshace (el rol de prueba incluido): `iron_app` se ensaya con su
     * variable de entorno, como en el banco de devops.
     *
     * @param  Closure(Migration): void  $fn
     */
    private function withAppRole(Closure $fn): void
    {
        DB::beginTransaction();
        try {
            DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.self::APP_ROLE.'\') THEN CREATE ROLE '.self::APP_ROLE.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
            $rol = DB::selectOne('SELECT rolsuper::int AS su, rolbypassrls::int AS rls, rolinherit::int AS inh FROM pg_roles WHERE rolname = ?', [self::APP_ROLE]);
            $this->assertSame([0, 0, 0], [(int) $rol->su, (int) $rol->rls, (int) $rol->inh]);
            putenv(self::APP_ROLE_ENV.'='.self::APP_ROLE);
            $fn($this->migration());
        } finally {
            putenv(self::APP_ROLE_ENV);
            DB::rollBack();
        }
    }

    /** Lo que da producción por los privilegios por defecto de `iron`: tablas `arwd`, secuencias `rwU`, `USAGE` en el esquema. */
    private function grantProductionModel(): void
    {
        $tablas = [...self::INSERT_ONLY, self::LINKS, self::CANARIES];
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::APP_ROLE);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON '.implode(', ', $tablas).' TO '.self::APP_ROLE);
        DB::statement('GRANT USAGE, SELECT, UPDATE ON '.implode(', ', array_map(static fn (string $t): string => "{$t}_id_seq", $tablas)).' TO '.self::APP_ROLE);
    }

    /** @return list<bool> SELECT, INSERT, UPDATE (tabla o columna), DELETE y TRUNCATE del rol de prueba. */
    private function privileges(string $table): array
    {
        $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d, has_table_privilege(?, ?, 'TRUNCATE')::int AS t",
            array_merge(...array_fill(0, 5, [self::APP_ROLE, "public.{$table}"])));

        return [(int) $p->s === 1, (int) $p->i === 1, (int) $p->u === 1, (int) $p->d === 1, (int) $p->t === 1];
    }

    /**
     * Ejecuta `$fn` como el rol de prueba dentro de un SAVEPOINT que SIEMPRE se
     * deshace. Devuelve [SQLSTATE o null, mensaje].
     *
     * @return array{0:?string,1:string}
     */
    private function asApp(Closure $fn): array
    {
        $deshacer = new RuntimeException('deshecho');
        try {
            DB::transaction(function () use ($fn, $deshacer): void {
                DB::statement('SET LOCAL ROLE '.self::APP_ROLE);
                $this->assertSame(self::APP_ROLE, DB::scalar('SELECT current_user'));
                $fn();

                throw $deshacer;
            });
        } catch (QueryException $e) {
            $this->assertNotSame(self::APP_ROLE, DB::scalar('SELECT current_user'), 'el rol no se deshizo');

            return [(string) ($e->getPrevious()?->errorInfo[0] ?? $e->getCode()), $e->getMessage()];
        } catch (RuntimeException $e) {
            if ($e === $deshacer) {
                $this->assertNotSame(self::APP_ROLE, DB::scalar('SELECT current_user'), 'el rol no se deshizo');

                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }

    private function insertPublication(int $seq, ?int $previous): int
    {
        return (int) DB::table(self::TABLE)->insertGetId($this->publicationRow($seq, $previous));
    }

    /** @return array<string, mixed> Una entrada `legacy` (sin versión), firmada con basura: aquí se prueba la base, no la firma. */
    private function publicationRow(int $seq, ?int $previous): array
    {
        $json = '{"canary":null,"contract":"ultron-engine-config/1","entry":"x","issuedAt":"2026-11-02T00:00:00Z","rest":null,"seq":'.$seq.'}';

        return [
            'uuid' => (string) Str::uuid(),
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => 'legacy',
            'version_id' => null,
            'restores_publication_id' => null,
            'previous_publication_id' => $previous,
            'engine_contract' => 'ultron-engine-config/1',
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => str_repeat('c', 64),
            'catalog_version' => '2026-10-19.1',
            'lab_gate' => '{"mode":null}',
            'acknowledged' => null,
            'evidence' => null,
            'reason' => 'Sembrada por la prueba del Hito 7',
            'actor_admin_id' => 1,
            'actor_name' => 'Prueba',
            'actor_role' => null,
            'created_at' => now(),
        ];
    }

    private function insertEvent(): int
    {
        return (int) DB::table(self::AUDIT)->insertGetId([
            'event' => 'system.hardening_probe',
            'subject_type' => 'system',
            'subject_uuid' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_name' => 'prueba',
            'summary' => 'Sembrado por la prueba del Hito 7',
            'created_at' => now(),
        ]);
    }

    private function insertSnapshot(): int
    {
        $facts = '{"otpDriver":"twilio"}';

        return (int) DB::table(self::SNAPSHOTS)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => null,
            'provider' => 'twilio',
            'kind' => 'config',
            'fingerprint' => hash('sha256', $facts),
            'facts' => $facts,
            'outcome' => null,
            'actor_type' => 'system',
            'actor_admin_id' => null,
            'actor_name' => 'ultron-studio:integrations-snapshot',
            'actor_role' => null,
            'note' => null,
            'created_at' => now(),
        ]);
    }

    private function assertChainRejected(Closure $sentencia): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, 'P0001', self::CHAIN_MESSAGE)
            : $this->assertRejected($sentencia, '23000', self::CHAIN_MESSAGE);
    }

    private function assertSeqRejected(Closure $sentencia, string $que): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23514', 'uspub_seq_chk', $que)
            : $this->assertRejected($sentencia, '23000', self::SEQ_MESSAGE, $que);
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}) {$que}");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    /** @return array<string, mixed> Las guardas de la cadena y los recuentos del Studio, para comparar antes y después. */
    private function guardsFootprint(): array
    {
        if (! $this->isPostgres()) {
            return [
                'disparadores' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLE)->orderBy('name')->pluck('sql', 'name')->all(),
                'studio' => $this->studioTriggerCount(),
            ];
        }

        return [
            'disparadores' => array_map(static fn (object $f): string => $f->def, DB::select(
                "SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = 'ultron_studio_publications'::regclass ORDER BY tgname")),
            'check' => array_map(static fn (object $f): string => $f->conname.' '.$f->def, DB::select(
                "SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE contype = 'c' AND conrelid = 'ultron_studio_publications'::regclass ORDER BY conname")),
            'funcion' => DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_publication_chain'"),
            'forbid' => DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"),
            'studio' => [$this->studioTriggerCount(), $this->studioCheckCount()],
        ];
    }

    /** @return array<string, mixed> La tabla de la cadena sin sus disparadores: columnas e índices. */
    private function tableDefinition(): array
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('tbl_name', self::TABLE)->where('type', '<>', 'trigger')->orderBy('name')->pluck('sql', 'name')->all();
        }

        return [
            'columnas' => array_map(static fn (array $c): string => $c['name'].' '.$c['type'].($c['nullable'] ? ' null' : ''), Schema::getColumns(self::TABLE)),
            'indices' => array_map(static fn (object $f): string => $f->indexdef, DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ? ORDER BY indexname', [self::TABLE])),
        ];
    }

    /** @return list<string> */
    private function triggersOn(string $table): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY 1', [$table]));
        }

        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<string> */
    private function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    private function studioTriggerCount(): int
    {
        return $this->isPostgres()
            ? (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%'")
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count();
    }

    private function studioCheckCount(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%'");
    }

    private function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue);
        }
    }
}
