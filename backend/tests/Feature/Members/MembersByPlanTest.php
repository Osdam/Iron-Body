<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «MIEMBROS» EN LA TARJETA DE UN PLAN.
 *
 * El botón navegaba a /users?plan=3 y el listado no miraba ese parámetro
 * nunca: se abría la lista completa de socios y el botón parecía estropeado.
 *
 * Aquí se fija la otra mitad —la del servidor—: que el filtro exista, que
 * compare bien contra una columna de TEXTO heredada (`users.plan` guarda el
 * nombre, no el id) y que un plan inexistente no devuelva a todo el mundo.
 */
class MembersByPlanTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->h = $this->actingAsAdmin(Admin::create([
            'name' => 'Dueño',
            'email' => 'plan-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]));
    }

    private function socio(string $nombre, ?string $plan): User
    {
        return User::create([
            'name' => $nombre,
            'email' => strtolower($nombre).'-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $plan,
        ]);
    }

    /** @return list<string> */
    private function nombresConPlan(int|string $planId): array
    {
        return array_column(
            $this->getJson('/api/users?plan='.$planId, $this->h)->assertOk()->json('data'),
            'name',
        );
    }

    public function test_solo_salen_los_socios_de_ese_plan(): void
    {
        $valera = Plan::create(['name' => 'Valera', 'price' => 65000, 'duration_days' => 30, 'active' => true]);
        Plan::create(['name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true]);

        $this->socio('Ana', 'Valera');
        $this->socio('Beto', 'Mensual');
        $this->socio('Caro', null);

        $this->assertSame(['Ana'], $this->nombresConPlan($valera->id));
    }

    public function test_las_filas_importadas_con_otra_grafia_tambien_cuentan(): void
    {
        // El sistema anterior escribió el mismo plan de varias maneras.
        $valera = Plan::create(['name' => 'Valera', 'price' => 65000, 'duration_days' => 30, 'active' => true]);

        $this->socio('Ana', 'valera');
        $this->socio('Beto', '  VALERA ');
        $this->socio('Caro', 'Mensual');

        $nombres = $this->nombresConPlan($valera->id);
        sort($nombres);
        $this->assertSame(['Ana', 'Beto'], $nombres);
    }

    public function test_un_plan_que_no_existe_no_devuelve_a_todo_el_mundo(): void
    {
        // Es exactamente el fallo que se está arreglando: una lista completa
        // que parece el resultado de un filtro.
        $this->socio('Ana', 'Valera');
        $this->socio('Beto', 'Mensual');

        $this->assertSame([], $this->nombresConPlan(999999));
    }

    public function test_sin_el_parametro_siguen_saliendo_todos(): void
    {
        $this->socio('Ana', 'Valera');
        $this->socio('Beto', 'Mensual');

        $this->assertCount(2, $this->getJson('/api/users', $this->h)->assertOk()->json('data'));
    }

    public function test_el_filtro_de_plan_se_combina_con_la_busqueda(): void
    {
        $valera = Plan::create(['name' => 'Valera', 'price' => 65000, 'duration_days' => 30, 'active' => true]);
        $this->socio('Ana', 'Valera');
        $this->socio('Andrea', 'Valera');
        $this->socio('Ana Mensual', 'Mensual');

        $nombres = array_column(
            $this->getJson('/api/users?plan='.$valera->id.'&search=Ana', $this->h)->assertOk()->json('data'),
            'name',
        );

        $this->assertSame(['Ana'], $nombres);
    }
}
