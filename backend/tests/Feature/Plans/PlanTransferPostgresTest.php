<?php

namespace Tests\Feature\Plans;

use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * LO QUE SQLITE NO PUEDE VER, contra PostgreSQL de verdad.
 *
 * La suite corre en SQLite, que compila `lockForUpdate()` a nada y no aborta
 * una transacción por una violación de unicidad. Producción corre en
 * PostgreSQL 14, donde las dos cosas existen y son justo las que sostienen la
 * concurrencia del traslado. Aquí se comprueban con commits reales —por eso
 * `DatabaseTruncation` y no `RefreshDatabase`: dentro de la transacción de la
 * prueba, una segunda conexión no vería a los socios, y un error en un
 * SAVEPOINT no abortaría nada—.
 *
 * Se ejecuta con
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=… DB_DATABASE=<base desechable>
 *   DB_USERNAME=… DB_PASSWORD= vendor/bin/phpunit --group pgsql
 * La base se VACÍA en cada prueba: nunca contra una base con datos.
 */
#[Group('pgsql')]
class PlanTransferPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use PlanTransferFixtures;

    private ?Connection $otra = null;

    protected function setUp(): void
    {
        // Antes de arrancar la aplicación: en SQLite el trait de truncado
        // migraría y vaciaría para nada.
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: FOR UPDATE y la transacción abortada por una violación de '.
                'unicidad no existen en SQLite. Ejecutar con DB_CONNECTION=pgsql contra una base desechable.');
        }

        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->prepararTraslado();
    }

    protected function tearDown(): void
    {
        if ($this->otra !== null) {
            if ($this->otra->transactionLevel() > 0) {
                $this->otra->rollBack();
            }
            $this->otra->disconnect();
        }
        if ($this->app !== null) {
            DB::statement('SET lock_timeout = 0');
            PlanTransferBatch::flushEventListeners();
            // Lo confirmado aquí es de verdad: si se quedara, las pruebas con
            // RefreshDatabase que corran después en la misma base lo verían
            // (dos «Élite» y el traslado se negaría por nombre ambiguo).
            $this->truncateDatabaseTables();
            // Pero truncar vacía TODAS las tablas, también las que siembran
            // las migraciones (los roles del sistema en `admin_roles`), y
            // RefreshDatabase no vuelve a migrar mientras crea que la base ya
            // está migrada: las pruebas de roles que corrieran después en el
            // mismo proceso encontrarían el catálogo vacío. Se le dice que no
            // lo está, y la siguiente —con RefreshDatabase o con este trait—
            // hace `migrate:fresh` y vuelve a sembrar.
            RefreshDatabaseState::$migrated = false;
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una segunda sesión de PostgreSQL: otro cajero, otra pestaña. */
    private function otraConexion(): Connection
    {
        config(['database.connections.pgsql_otra' => config('database.connections.pgsql')]);

        return $this->otra = DB::connection('pgsql_otra');
    }

    public function test_bloquea_socios_y_despues_planes_con_for_update(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->assertOk()->json();
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        $r = $this->ejecutar($simulacion, [$socio->id])->assertCreated();

        $socios = $this->indice($sql, '/^select \* from "users" where "users"\."id" in \(.*\) order by "id" asc for update$/');
        $planes = $this->indice($sql, '/^select \* from "plans" where "plans"\."id" in \(.*\) order by "id" asc for update$/');
        $this->assertNotNull($socios, 'Los socios no se bloquearon con FOR UPDATE.');
        $this->assertNotNull($planes, 'Los planes no se bloquearon con FOR UPDATE.');
        $this->assertLessThan($planes, $socios, 'Los planes se bloquearon antes que los socios.');
        $this->assertSame([], preg_grep('/for update/i', array_filter($sql, fn (string $s): bool => preg_match('/\b(count|max|min|sum)\(/i', $s) === 1)),
            'Un agregado con FOR UPDATE: PostgreSQL lo rechaza.');

        // La reversión bloquea socios, planes, lote e ítems, en ese orden.
        $sql = [];
        $this->revertir((int) $r->json('batch.id'))->assertCreated();
        $orden = array_map(fn (string $patron): ?int => $this->indice($sql, $patron), [
            '/^select \* from "users" where "users"\."id" in \(.*\) order by "id" asc for update$/',
            '/^select \* from "plans" where "plans"\."id" in \(.*\) order by "id" asc for update$/',
            '/^select \* from "plan_transfer_batches" where "plan_transfer_batches"\."id" = \? limit 1 for update$/',
            '/^select \* from "plan_transfer_items" where "batch_id" = \? order by "id" asc for update$/',
        ]);
        $this->assertNotContains(null, $orden, 'Falta un bloqueo de la reversión: '.json_encode($orden));
        $ordenado = $orden;
        sort($ordenado);
        $this->assertSame($ordenado, $orden);
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->refresh()->plan);
    }

    public function test_el_bloqueo_del_socio_es_real_y_quien_espera_no_escribe_nada(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->assertOk()->json();

        // Otra sesión tiene al socio bloqueado (un cobro en curso, una mejora).
        $otra = $this->otraConexion();
        $otra->beginTransaction();
        $otra->select('select id from users where id = ? for update', [$socio->id]);

        DB::statement("SET lock_timeout = '300ms'");
        $inicio = microtime(true);
        $this->ejecutar($simulacion, [$socio->id])->assertStatus(500);
        $espera = microtime(true) - $inicio;
        DB::statement('SET lock_timeout = 0');

        $this->assertGreaterThanOrEqual(0.25, $espera, 'No esperó al bloqueo: el FOR UPDATE no está.');
        $this->assertSame(0, PlanTransferBatch::count());
        $this->assertSame('TOTAL ACCESS - ELITE', User::findOrFail($socio->id)->plan);

        // Liberado el socio, la misma operación entra.
        $otra->rollBack();
        $this->ejecutar($simulacion, [$socio->id])->assertCreated();
        $this->assertSame('Élite', User::findOrFail($socio->id)->plan);
    }

    public function test_la_violacion_de_unicidad_se_resuelve_fuera_de_la_transaccion_abortada(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->assertOk()->json();
        $llave = 'op-carrera-pg-1';

        // La carrera: justo cuando esta petición va a insertar su lote, otra
        // sesión confirma uno con la misma llave y la misma huella.
        //
        // Sus planes se le ponen DESPUÉS del rollback de esta petición: con la
        // clave foránea a `plans`, insertarlos mientras esta transacción tiene
        // los planes con FOR UPDATE haría esperar a la otra sesión por esta, y
        // esta (dentro del listener) por la otra. En la vida real la otra
        // petición también bloquea los planes y se ordenan solas.
        $otra = $this->otraConexion();
        $planes = [];
        PlanTransferBatch::creating(function (PlanTransferBatch $lote) use ($otra, &$planes): void {
            $planes = ['from_plan_id' => $lote->from_plan_id, 'to_plan_id' => $lote->to_plan_id];
            $otra->table('plan_transfer_batches')->insert([
                'kind' => 'transfer', 'status' => 'applied',
                'from_plan_name' => $lote->from_plan_name, 'to_plan_name' => $lote->to_plan_name,
                'from_conditions' => '{}', 'to_conditions' => '{}',
                'reason' => 'La otra pestaña', 'fingerprint' => $lote->fingerprint,
                'client_request_id' => $lote->client_request_id, 'members_count' => 1,
                'business_day' => '2026-10-15', 'executed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $deshecha = false;
        Event::listen(TransactionRolledBack::class, function () use ($otra, $llave, &$planes, &$deshecha): void {
            $deshecha = true;
            $otra->table('plan_transfer_batches')->where('client_request_id', $llave)->update($planes);
        });

        $r = $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('batch.reason', 'La otra pestaña');
        PlanTransferBatch::flushEventListeners();

        // Lo de esta petición se deshizo entero, y la conexión sigue viva.
        $this->assertTrue($deshecha, 'No hubo rollback: la violación de unicidad no ocurrió dentro de la transacción.');
        $this->assertSame(1, PlanTransferBatch::count());
        $this->assertSame(0, PlanTransferItem::count());
        $this->assertSame('TOTAL ACCESS - ELITE', User::findOrFail($socio->id)->plan);
        $this->assertSame($r->json('batch.id'), (int) PlanTransferBatch::where('client_request_id', $llave)->value('id'));
    }

    public function test_la_misma_llave_con_otra_huella_en_carrera_es_un_conflicto(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->assertOk()->json();

        $otra = $this->otraConexion();
        PlanTransferBatch::creating(function (PlanTransferBatch $lote) use ($otra): void {
            $otra->table('plan_transfer_batches')->insert([
                'kind' => 'transfer', 'status' => 'applied', 'from_plan_name' => 'X', 'to_plan_name' => 'Y',
                'from_conditions' => '{}', 'to_conditions' => '{}', 'reason' => 'Otra operación',
                'fingerprint' => str_repeat('b', 64), 'client_request_id' => $lote->client_request_id,
                'members_count' => 1, 'business_day' => '2026-10-15', 'executed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => 'op-carrera-pg-2'])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        PlanTransferBatch::flushEventListeners();

        $this->assertSame('TOTAL ACCESS - ELITE', User::findOrFail($socio->id)->plan);
        $this->assertSame(0, PlanTransferItem::count());
    }

    public function test_la_huella_del_escenario_fijo_es_la_misma_que_en_sqlite(): void
    {
        // El escenario usa sus propios planes: los del traslado de prueba
        // chocarían por nombre.
        DB::table('plans')->delete();

        $this->assertSame(PlanTransferFingerprintScenario::EXPECTED, PlanTransferFingerprintScenario::build());
    }

    /** @param  list<string>  $sql */
    private function indice(array $sql, string $patron): ?int
    {
        foreach ($sql as $i => $s) {
            if (preg_match($patron, $s) === 1) {
                return $i;
            }
        }

        return null;
    }
}
