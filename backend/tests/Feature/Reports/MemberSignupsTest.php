<?php

namespace Tests\Feature\Reports;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Reports\MemberInsights;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «NUEVOS EN EL PERIODO», que contaba como altas las cargas del sistema anterior.
 *
 * El export del CRM viejo se vuelve a importar cada tanto. Cada carga crea miles
 * de fichas con `created_at` del día de la importación, y el informe las contaba
 * como socios nuevos: el periodo de la carga enseñaba tres mil altas y el
 * siguiente un desplome del 99 %. Una caída que nunca ocurrió, leída por el
 * gimnasio como si fuera real.
 *
 * Las fichas importadas se marcan (`imported_at`) y se cuentan aparte. Estos
 * tests fijan que la marca no se lleve por delante a las altas de verdad.
 */
class MemberSignupsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño',
            'email' => 'altas-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function socio(string $nombre, CarbonImmutable $registrada, ?CarbonImmutable $importada = null): User
    {
        $u = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        $u->forceFill([
            'created_at' => $registrada,
            'imported_at' => $importada,
        ])->save();

        return $u->refresh();
    }

    private function ultimos30(): MemberInsights
    {
        return new MemberInsights(ReportRange::fromRequest(['preset' => 'last_30']));
    }

    public function test_una_ficha_importada_no_es_un_alta(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');

        $this->socio('Del Mostrador', $hoy->subDays(3));
        $this->socio('Del Export', $hoy->subDays(3), $hoy->subDays(3));

        $totales = $this->ultimos30()->totals();

        $this->assertSame(1, $totales['new']);
        // La carga sí se cuenta, pero como lo que es.
        $this->assertSame(1, $totales['imported']);
        // Y las dos siguen siendo socios del gimnasio.
        $this->assertSame(2, $totales['members']);
    }

    public function test_el_grafico_de_altas_tampoco_las_pinta(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');

        $this->socio('Del Mostrador', $hoy->subDays(2));
        for ($i = 0; $i < 5; $i++) {
            $this->socio('Importado '.$i, $hoy->subDays(2), $hoy->subDays(2));
        }

        $serie = $this->ultimos30()->signupSeries();
        $total = array_sum(array_column($serie, 'count'));

        // Sin esto, el gráfico enseñaba un pico el día de la carga.
        $this->assertSame(1, $total);
    }

    public function test_la_migracion_marca_a_quien_pago_antes_de_existir_su_ficha(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');
        $plan = Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'active' => true]);

        // Así quedaron las fichas que ya se habían importado: creadas ayer, con
        // un pago de hace un año. Nadie paga antes de existir.
        $viejo = $this->socio('Historia Vieja', $hoy->subDay());
        Payment::create([
            'user_id' => $viejo->id,
            'plan_id' => $plan->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $hoy->subYear(),
            'origin' => PaymentOrigin::LEGACY->value,
            'reference' => 'MIGR-4821',
        ]);

        // Y en la misma tanda entró una persona del export que nunca compró: no
        // tiene nada que delate su edad, solo el minuto en que se creó.
        $sinHistoria = $this->socio('Nunca Compró', $hoy->subDay()->addSeconds(30));

        // Un alta de verdad, hecha hoy en el mostrador.
        $real = $this->socio('Del Mostrador', $hoy->subHours(2));

        $this->runImportBackfill();

        $this->assertNotNull($viejo->refresh()->imported_at, 'el que pagó antes de existir');
        $this->assertNotNull($sinHistoria->refresh()->imported_at, 'el de la misma tanda');
        $this->assertNull($real->refresh()->imported_at, 'el alta real no se toca');
    }

    public function test_un_pago_con_fecha_atrasada_no_convierte_al_socio_en_importado(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');
        $plan = Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'active' => true]);

        // En el mostrador se registra con fecha atrasada: «pagó el lunes y lo
        // subimos el miércoles». Sin margen, esto marcaría a un socio real.
        $real = $this->socio('Pagó El Lunes', $hoy->subHours(3));
        Payment::create([
            'user_id' => $real->id,
            'plan_id' => $plan->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $hoy->subDays(3),
            'origin' => PaymentOrigin::COUNTER->value,
        ]);

        $this->runImportBackfill();

        $this->assertNull($real->refresh()->imported_at);
        $this->assertSame(1, $this->ultimos30()->totals()['new']);
    }

    public function test_el_informe_publica_las_importadas_aparte(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');
        $this->socio('Del Export', $hoy->subDay(), $hoy->subDay());

        $this->getJson('/api/admin/reports/members?preset=last_30', $this->actingAsAdmin($this->admin))
            ->assertOk()
            ->assertJsonPath('totals.new', 0)
            ->assertJsonPath('totals.imported', 1);
    }

    /**
     * Corre la parte de relleno de la migración sobre los datos del test.
     *
     * La migración ya se aplicó al crear la base de pruebas —con la tabla
     * vacía—, así que para comprobar lo que hace hay que volver a ejecutarla
     * ahora que hay filas.
     */
    private function runImportBackfill(): void
    {
        $migracion = require database_path('migrations/2026_10_04_000001_add_imported_at_to_users_table.php');
        $migracion->up();
    }
}
