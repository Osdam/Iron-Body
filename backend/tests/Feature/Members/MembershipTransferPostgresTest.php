<?php

namespace Tests\Feature\Members;

use App\Models\MembershipTransfer;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TRANSFERIR MEMBRESÍA: lo que SQLite no puede ver, contra PostgreSQL de verdad.
 *
 * SQLite compila `lockForUpdate()` a nada y no aborta una transacción por una
 * violación de unicidad; producción corre en PostgreSQL 14, donde las dos
 * cosas existen y son las que sostienen la concurrencia del traspaso. Commits
 * reales (`DatabaseTruncation`), como PlanTransferPostgresTest.
 *
 *   DB_CONNECTION=pgsql … vendor/bin/phpunit --group pgsql
 *   (o docs/ultron-studio/ops/pgunit.sh con UNIT_DB=ultron_studio_transferencia)
 *
 * La base se VACÍA en cada prueba: nunca contra una base con datos. La carrera
 * entre dos procesos de verdad está en tests/Concurrency/TransferenciaDeMembresias.
 */
#[Group('pgsql')]
class MembershipTransferPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use MembershipTransferFixtures;

    private ?Connection $otra = null;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: FOR UPDATE, el índice parcial y la transacción abortada no existen en SQLite.');
        }

        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->prepararTraspasos();
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
            MembershipTransfer::flushEventListeners();
            // Lo confirmado aquí es de verdad: se vacía, y se avisa de que la
            // base ya no está sembrada (ver PlanTransferPostgresTest).
            $this->truncateDatabaseTables();
            RefreshDatabaseState::$migrated = false;
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function otraConexion(): Connection
    {
        config(['database.connections.pgsql_otra' => config('database.connections.pgsql')]);

        return $this->otra = DB::connection('pgsql_otra');
    }

    public function test_bloquea_socios_cobro_y_deudas_en_ese_orden_con_for_update(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto PG');
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        $this->transferir($a, $b)->assertCreated();

        $orden = array_map(fn (string $patron): ?int => $this->indice($sql, $patron), [
            '/^select \* from "users" where "users"\."id" in \(.*\) order by "id" asc for update$/',
            '/^select \* from "payments" where "user_id" = \? .*order by "id" asc for update$/',
            '/^select \* from "receivables" where "source_type" = \? and "source_id" = \? order by "id" asc for update$/',
        ]);
        $this->assertNotContains(null, $orden, 'Falta un bloqueo: '.json_encode($orden));
        $ordenado = $orden;
        sort($ordenado);
        $this->assertSame($ordenado, $orden, 'Los bloqueos no van socios → cobro → deudas.');
        $this->assertSame([], preg_grep('/for update/i', array_filter($sql, fn (string $s): bool => preg_match('/\b(count|max|min|sum)\(/i', $s) === 1)),
            'Un agregado con FOR UPDATE: PostgreSQL lo rechaza.');

        // La reversión: socios → cobro → traspaso.
        $sql = [];
        $this->revertir((int) MembershipTransfer::value('id'))->assertOk();
        $orden = array_map(fn (string $patron): ?int => $this->indice($sql, $patron), [
            '/^select \* from "users" where "users"\."id" in \(.*\) order by "id" asc for update$/',
            '/^select \* from "payments" where "payments"\."id" = \? limit 1 for update$/',
            '/^select \* from "membership_transfers" where "membership_transfers"\."id" = \? limit 1 for update$/',
        ]);
        $this->assertNotContains(null, $orden, 'Falta un bloqueo de la reversión: '.json_encode($orden));
        $ordenado = $orden;
        sort($ordenado);
        $this->assertSame($ordenado, $orden);
    }

    public function test_el_indice_unico_es_parcial_en_postgres(): void
    {
        $def = (string) DB::table('pg_indexes')
            ->where('tablename', 'membership_transfers')
            ->where('indexname', 'membership_transfers_one_live_per_payment')
            ->value('indexdef');

        $this->assertStringContainsString('CREATE UNIQUE INDEX', $def);
        $this->assertStringContainsString('(source_payment_id) WHERE (reverted_at IS NULL)', $def);
    }

    public function test_el_bloqueo_de_la_titular_es_real_y_quien_espera_no_escribe_nada(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Espera');

        // Otra sesión tiene a la titular bloqueada (anulando su cobro, otro traspaso).
        $otra = $this->otraConexion();
        $otra->beginTransaction();
        $otra->select('select id from users where id = ? for update', [$a->id]);

        DB::statement("SET lock_timeout = '300ms'");
        $inicio = microtime(true);
        $this->transferir($a, $b)->assertStatus(500);
        $espera = microtime(true) - $inicio;
        DB::statement('SET lock_timeout = 0');

        $this->assertGreaterThanOrEqual(0.25, $espera, 'No esperó al bloqueo: el FOR UPDATE no está.');
        $this->assertSame(0, MembershipTransfer::count());
        $this->assertSame('2027-09-01', User::findOrFail($a->id)->membershipEndDate);
        $this->assertNull(User::findOrFail($b->id)->plan);

        $otra->rollBack();
        $this->transferir($a, $b)->assertCreated();
    }

    public function test_la_violacion_del_indice_parcial_se_resuelve_fuera_de_la_transaccion_abortada(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Pierde');
        $c = $this->socio('Carla Gana');

        // Justo antes de que esta petición inserte su traspaso, otra sesión
        // confirma uno vivo del MISMO cobro con otra llave. En la vida real los
        // bloqueos lo impiden; esto prueba la red de debajo: el índice.
        // `session_replication_role = replica` solo evita que las claves
        // foráneas de esa sesión esperen a los FOR UPDATE de esta (en un solo
        // proceso sería un abrazo mortal); el índice único se comprueba igual.
        $otra = $this->otraConexion();
        $otra->statement('SET session_replication_role = replica');
        MembershipTransfer::creating(function (MembershipTransfer $t) use ($otra, $c): void {
            $otra->table('membership_transfers')->insert([
                'holder_user_id' => $t->holder_user_id, 'beneficiary_user_id' => $c->id,
                'source_payment_id' => $t->source_payment_id, 'plan_id' => $t->plan_id, 'plan_name' => $t->plan_name,
                'effective_date' => '2026-10-15', 'end_date' => '2027-09-01', 'days' => 322,
                'reason' => 'La otra pestaña', 'holder_consent_at' => now(), 'beneficiary_acceptance_at' => now(),
                'client_request_id' => 'la-otra-pestana', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $r = $this->transferir($a, $b)->assertStatus(409)->assertJsonPath('code', 'transfer_blocked');
        MembershipTransfer::flushEventListeners();

        $this->assertSame(['source_already_transferred'], array_column($r->json('blockers'), 'code'));
        // Lo de esta petición se deshizo entero, y la conexión sigue viva.
        $this->assertSame(1, MembershipTransfer::where('source_payment_id', $cobro->id)->count());
        $this->assertSame('la-otra-pestana', MembershipTransfer::value('client_request_id'));
        $this->assertSame('2027-09-01', User::findOrFail($a->id)->membershipEndDate);
        $this->assertNull(User::findOrFail($b->id)->plan);
        $this->assertSame(0, DB::table('audit_logs')->where('metadata->event', 'membership.transferred')->count());
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
