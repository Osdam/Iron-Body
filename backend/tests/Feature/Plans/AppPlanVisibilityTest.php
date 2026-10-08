<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OCULTAR UN PLAN EN LA APP SIN DEJAR DE COBRARLO.
 *
 * Había dos interruptores y ninguno servía: `active` lo saca de todas partes
 * —incluido el mostrador, así que deja de poder cobrarse— y `sellable` gobierna
 * cotizaciones y campañas. Lo que el gimnasio pedía es otra cosa: un plan que
 * se sigue cobrando en caja todos los días pero que no quiere anunciar en la
 * app, porque es un precio heredado, un convenio o algo que está retirando.
 *
 * La app NO se toca: lee `GET /api/membership-plans` y es ese endpoint el que
 * deja de incluirlo. Por eso el test que importa es el que comprueba las dos
 * caras a la vez — invisible allá, cobrable aquí.
 */
class AppPlanVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->h = $this->actingAsAdmin(Admin::create([
            'name' => 'Dueño',
            'email' => 'planes-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]));
    }

    private function plan(string $nombre, array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $nombre,
            'price' => 80000,
            'duration_days' => 30,
            'active' => true,
        ], $extra));
    }

    /** Los nombres que la app recibiría en su catálogo. */
    private function catalogoDeLaApp(): array
    {
        return array_column(
            $this->getJson('/api/membership-plans')->assertOk()->json('data'),
            'name'
        );
    }

    public function test_un_plan_oculto_desaparece_de_la_app_pero_sigue_en_el_crm(): void
    {
        $visible = $this->plan('Plan Mensual');
        $oculto = $this->plan('Convenio empresa', ['visible_in_app' => false]);

        $this->assertSame(['Plan Mensual'], $this->catalogoDeLaApp());

        // Y en el CRM sigue estando entero: es lo que permite cobrarlo en caja.
        $nombres = array_column($this->getJson('/api/plans', $this->h)->assertOk()->json('data'), 'name');
        $this->assertContains('Convenio empresa', $nombres);
        $this->assertTrue($oculto->refresh()->active, 'ocultar no es desactivar');
        $this->assertNotNull($visible->id);
    }

    public function test_el_plan_oculto_tampoco_se_abre_por_su_enlace_directo(): void
    {
        $oculto = $this->plan('Convenio empresa', ['visible_in_app' => false]);

        // 404 y no 403: para la app ese plan no existe.
        $this->getJson('/api/membership-plans/'.$oculto->id)->assertStatus(404);
    }

    public function test_lo_que_ya_existia_sigue_viendose(): void
    {
        // La columna nace en `true`, así que ningún plan desaparece de la app
        // por haber corrido la migración. Ocultar es siempre explícito.
        $plan = $this->plan('Plan Mensual');

        $this->assertTrue($plan->refresh()->visible_in_app);
        $this->assertSame(['Plan Mensual'], $this->catalogoDeLaApp());
    }

    public function test_se_oculta_y_se_vuelve_a_mostrar_desde_el_crm(): void
    {
        $plan = $this->plan('Plan Mensual');

        $this->putJson("/api/plans/{$plan->id}", ['visible_in_app' => false], $this->h)->assertOk();
        $this->assertSame([], $this->catalogoDeLaApp());

        $this->putJson("/api/plans/{$plan->id}", ['visible_in_app' => true], $this->h)->assertOk();
        $this->assertSame(['Plan Mensual'], $this->catalogoDeLaApp());
    }

    public function test_un_plan_inactivo_sigue_fuera_de_la_app_aunque_este_marcado_visible(): void
    {
        // Las dos condiciones se suman: desactivar sigue sacándolo de la app.
        $this->plan('Plan viejo', ['active' => false, 'visible_in_app' => true]);

        $this->assertSame([], $this->catalogoDeLaApp());
    }
}
