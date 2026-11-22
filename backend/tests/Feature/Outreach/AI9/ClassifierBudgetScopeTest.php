<?php

namespace Tests\Feature\Outreach\AI9;

use App\Services\Outreach\Run\BudgetReservations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * AI9 · EL CONTADOR DEL CLASIFICADOR (migración `2026_11_22_100001`): el
 * CHECK `obud_scope_chk` de PostgreSQL admite `classifier:reply` y nada más
 * nuevo; lo que se rechazaba se sigue rechazando; la vuelta atrás devuelve el
 * CHECK estrecho solo sin filas del clasificador y se niega con ellas. En
 * SQLite (sin CHECK) la forma la vigila `BudgetReservations::parcel()`.
 */
class ClassifierBudgetScopeTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_22_100001_allow_classifier_budget_scope.php';

    public function test_la_parcela_admite_el_alcance_del_clasificador_y_ningun_otro_nuevo(): void
    {
        $p = BudgetReservations::parcel(BudgetReservations::SCOPE_CLASSIFIER, 1, 900, 10, 1_000_000);
        $this->assertSame('classifier:reply', $p['scope']);
        foreach (['classifier:', 'classifier:otro', 'classifier:reply:1', 'classifier', 'channel:classifier', 'channel:simulated'] as $mal) {
            try {
                BudgetReservations::parcel($mal, 1, 0, 1, 0);
                $this->fail("«{$mal}» no es un alcance");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_reserva_del_clasificador_es_atomica_contra_su_techo(): void
    {
        $r = new BudgetReservations;
        $parcela = BudgetReservations::parcel(BudgetReservations::SCOPE_CLASSIFIER, 1, 400, 2, 1_000);
        $this->assertNull($r->reserve('2026-11-17', 'live', [$parcela], now()));
        $this->assertNull($r->reserve('2026-11-17', 'live', [$parcela], now()));
        $this->assertSame('classifier:reply', $r->reserve('2026-11-17', 'live', [$parcela], now()), 'la tercera no cabe en el cupo de 2');
        $this->assertSame(['units' => 2, 'micros' => 800], $r->used('2026-11-17', 'live', 'classifier:reply'));
        $this->assertSame('classifier:reply', $r->reserve('2026-11-17', 'live', [BudgetReservations::parcel(BudgetReservations::SCOPE_CLASSIFIER, 1, 400, 5, 1_000)], now()), 'ni con más cupo: el techo en micros');
    }

    public function test_en_postgresql_el_check_admite_classifier_reply_y_sigue_rechazando_lo_demas(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL: en SQLite los CHECK de la ejecución no existen.');
        }
        $this->assertTrue($this->inserts('classifier:reply'));
        foreach (['classifier:otro', 'classifier:', 'channel:simulated', 'phone:573001234567', 'strategy:renovacion-7d'] as $mal) {
            $this->assertFalse($this->inserts($mal), "«{$mal}» lo rechaza obud_scope_chk");
        }
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conname = 'obud_scope_chk'"), 'el mismo nombre, una vez');
    }

    public function test_en_postgresql_la_vuelta_atras_se_niega_con_filas_y_sin_ellas_devuelve_el_check_estrecho(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL.');
        }
        $m = require database_path(self::MIGRATION);

        // Sin filas del clasificador: vuelve el CHECK de 2026_11_12_100002 y `classifier:reply` deja de caber.
        DB::transaction(fn () => $m->down());
        $this->assertFalse($this->inserts('classifier:reply'));
        $this->assertTrue($this->inserts('channel:assisted'));
        DB::transaction(fn () => $m->up());
        $this->assertTrue($this->inserts('classifier:reply'));

        // Con una fila del clasificador, se niega (las reservas del día no se borran).
        try {
            DB::transaction(fn () => $m->down());
            $this->fail('down() tenía que negarse con filas del clasificador');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fila(s) del contador del clasificador', $e->getMessage());
        }
        $this->assertTrue($this->inserts('classifier:reply', '2026-11-18'), 'y el CHECK ancho sigue en su sitio');
    }

    /** ¿Entra una fila con ese alcance? En su punto de guardado: un CHECK que salta no aborta la transacción de la prueba. */
    private function inserts(string $scope, string $day = '2026-11-17'): bool
    {
        try {
            DB::transaction(fn () => DB::table('outreach_budget_counters')->insert([
                'budget_day' => $day, 'scope' => $scope, 'mode' => 'live', 'cap_units' => 1, 'cap_micros' => 0, 'reserved_units' => 0, 'reserved_micros' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]));

            return true;
        } catch (QueryException $e) {
            $this->assertStringContainsString('obud_scope_chk', $e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->fail('Fallo inesperado: '.$e::class);
        }
    }
}
