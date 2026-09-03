<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La portada del CRM, acotada a lo que cada administrador puede ver.
 *
 * `GET /api/dashboard` exigía `reports.view` para las cinco cifras. Como es la
 * pantalla a la que se cae tras iniciar sesión, recepción recibía un 403 nada
 * más entrar: en producción se contaron 5 respuestas 403 en un día, y una
 * sesión de recepción se quedó doce segundos sin poder cargar nada.
 *
 * Conceder `reports.view` habría sido peor —abre además los informes y el
 * detalle de ingresos—, así que se acota la respuesta y no la puerta. Lo que
 * estas pruebas protegen: que recepción entre, y que `revenue` NO viaje con ella.
 */
class DashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private function recepcion(): Admin
    {
        return Admin::create([
            'name' => 'Recepción Prueba',
            'email' => 'rec-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);
    }

    private function conDatos(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '']);
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            'method' => 'cash',
            'status' => 'paid',
        ]);
    }

    // ── Recepción ya puede entrar ───────────────────────────────────────────

    public function test_recepcion_ya_no_recibe_403(): void
    {
        $this->conDatos();

        $this->getJson('/api/dashboard', $this->actingAsAdmin($this->recepcion()))
            ->assertOk();
    }

    public function test_recepcion_recibe_los_contadores_que_ya_podia_listar(): void
    {
        $this->conDatos();

        $res = $this->getJson('/api/dashboard', $this->actingAsAdmin($this->recepcion()))->assertOk();

        // No son datos nuevos para ella: recepción ya lista socios, planes,
        // pagos y clases. Es el mismo dato, contado.
        foreach (['users', 'active_plans', 'payments', 'classes'] as $campo) {
            $res->assertJsonStructure([$campo]);
        }
    }

    // ── Pero la facturación no viaja con ella ───────────────────────────────

    public function test_recepcion_no_recibe_la_facturacion(): void
    {
        $this->conDatos();

        $res = $this->getJson('/api/dashboard', $this->actingAsAdmin($this->recepcion()))->assertOk();

        $this->assertArrayNotHasKey('revenue', $res->json(),
            'la facturación histórica exige reports.view');
    }

    public function test_el_importe_no_aparece_por_ninguna_otra_via(): void
    {
        // Ni con otro nombre ni dentro de otro campo: el número no debe estar
        // en la respuesta de ninguna forma.
        $this->conDatos();

        $res = $this->getJson('/api/dashboard', $this->actingAsAdmin($this->recepcion()))->assertOk();

        $this->assertStringNotContainsString('80000', json_encode($res->json()));
    }

    public function test_un_super_admin_sigue_viendo_la_facturacion(): void
    {
        $this->conDatos();

        $res = $this->getJson('/api/dashboard', $this->adminHeaders())->assertOk();

        $this->assertArrayHasKey('revenue', $res->json());
        $this->assertEqualsWithDelta(80000, $res->json('revenue'), 0.01);
    }

    // ── Detalles que se rompen fácil ────────────────────────────────────────

    public function test_un_contador_en_cero_se_devuelve_igual(): void
    {
        // Un cero es un dato; ausente significa "no te compete". Confundirlos
        // haría desaparecer tarjetas legítimas de una portada recién estrenada.
        $res = $this->getJson('/api/dashboard', $this->actingAsAdmin($this->recepcion()))->assertOk();

        $this->assertSame(0, $res->json('payments'));
        $this->assertArrayHasKey('payments', $res->json());
    }

    public function test_sin_sesion_sigue_sin_haber_portada(): void
    {
        // Acotar la respuesta no abrió la puerta: sigue exigiendo sesión.
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_un_administrador_inactivo_no_ve_nada(): void
    {
        $inactivo = Admin::create([
            'name' => 'Baja',
            'email' => 'baja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'inactive',
        ]);

        $this->getJson('/api/dashboard', $this->actingAsAdmin($inactivo))
            ->assertStatus(403);
    }
}
