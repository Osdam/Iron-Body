<?php

namespace Tests\Feature\Trainer;

use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que solo PostgreSQL hace cumplir de las potencias
 * (database/migrations/2026_10_30_100001_add_iron_ai_powers_to_membership_ai_capabilities.php).
 *
 * El controlador valida 1..5, pero alguien puede escribir sin pasar por él (un
 * UPDATE a mano, un script). Un 0 o un 9 no tienen estrategia y caerían al
 * nivel 1 sin que nadie lo viera: el CHECK lo rechaza en la base. Y la
 * migración tiene que poder deshacerse sin dejar el CHECK colgando.
 */
class IronAiPowersPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: el CHECK de 1..5 no existe en SQLite.');
        }

        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_el_check_rechaza_niveles_fuera_de_1_a_5(): void
    {
        $plan = Plan::create(['name' => 'Plan PG', 'price' => 1000, 'duration_days' => 30, 'active' => true]);
        $base = ['membership_plan_id' => $plan->id, 'plan_code' => 'plan_pg', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];

        foreach ([['nutrition_power', 0], ['nutrition_power', 6], ['routine_power', 0], ['routine_power', 9]] as [$col, $v]) {
            try {
                DB::transaction(fn () => DB::table('membership_ai_capabilities')->insert($base + [$col => $v]));
                $this->fail("{$col}={$v} entró en la base");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0] ?? $e->getCode(), 'debe ser una violación de CHECK');
            }
        }

        DB::table('membership_ai_capabilities')->insert($base + ['nutrition_power' => 5, 'routine_power' => 1]);
        $fila = DB::table('membership_ai_capabilities')->where('membership_plan_id', $plan->id)->first();
        $this->assertSame([5, 1], [(int) $fila->nutrition_power, (int) $fila->routine_power]);

        // Una fila sin las columnas nace en 1: lo de antes.
        $otro = Plan::create(['name' => 'Plan PG 2', 'price' => 1000, 'duration_days' => 30, 'active' => true]);
        DB::table('membership_ai_capabilities')->insert(['membership_plan_id' => $otro->id, 'plan_code' => 'plan_pg_2', 'is_active' => true]);
        $this->assertSame(1, (int) DB::table('membership_ai_capabilities')->where('membership_plan_id', $otro->id)->value('routine_power'));
    }

    public function test_las_dos_migraciones_se_deshacen_y_se_rehacen(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('membership_ai_capabilities', 'nutrition_power'));
        $this->assertFalse(Schema::hasColumn('nutrition_ai_runs', 'total_tokens'));
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_constraint where conname like 'membership_ai_capabilities_%_power_chk'")->n);

        Artisan::call('migrate', ['--force' => true]);
        $this->assertTrue(Schema::hasColumn('membership_ai_capabilities', 'routine_power'));
        $this->assertTrue(Schema::hasColumn('nutrition_ai_runs', 'usage_json'));
        $this->assertSame(2, (int) DB::selectOne("select count(*) as n from pg_constraint where conname like 'membership_ai_capabilities_%_power_chk'")->n);
    }
}
