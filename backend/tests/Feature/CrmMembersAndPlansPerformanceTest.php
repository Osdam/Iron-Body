<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rendimiento y autenticación de los módulos Miembros y Planes del CRM.
 *
 * Contrato que se fija aquí:
 *   - GET /api/users pagina en el servidor (`per_page`) y filtra (`search`,
 *     `status`), para que el CRM NO recorra todas las páginas al abrir Miembros.
 *   - GET /api/admin/members/incomplete acepta la SESIÓN ADMIN. La gemela
 *     /api/members/incomplete exige el secreto de registro de la app y devolvía
 *     401, lo que cerraba la sesión del CRM al entrar a Miembros.
 *   - GET /api/admin/plans/stats devuelve suscriptores e ingresos del mes ya
 *     agregados (antes el cliente descargaba todos los miembros y pagos).
 */
class CrmMembersAndPlansPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name, array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        return User::create(array_merge([
            'name'     => $name,
            'email'    => "member{$seq}@example.test",
            'password' => bcrypt('secret-password'),
            'document' => (string) (1000000 + $seq),
            'phone'    => '30000000' . $seq,
            'status'   => 'active',
        ], $attributes));
    }

    public function test_listado_de_miembros_respeta_per_page(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->makeUser("Miembro {$i}");
        }

        $response = $this->getJson('/api/users?per_page=3', $this->adminHeaders())
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('per_page'));
        $this->assertSame(7, $response->json('total'));
        $this->assertSame(3, $response->json('last_page'));
    }

    public function test_per_page_esta_acotado_a_100(): void
    {
        $this->makeUser('Miembro único');

        $this->getJson('/api/users?per_page=5000', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    public function test_busqueda_de_miembros_ocurre_en_el_servidor(): void
    {
        $this->makeUser('Ana Gómez');
        $this->makeUser('Luis Pérez', ['document' => '99887766']);

        $porNombre = $this->getJson('/api/users?search=ana', $this->adminHeaders())->assertOk();
        $this->assertSame(1, $porNombre->json('total'));
        $this->assertSame('Ana Gómez', $porNombre->json('data.0.name'));

        $porDocumento = $this->getJson('/api/users?search=99887766', $this->adminHeaders())->assertOk();
        $this->assertSame(1, $porDocumento->json('total'));
        $this->assertSame('Luis Pérez', $porDocumento->json('data.0.name'));

        $this->getJson('/api/users?search=nadie-con-este-nombre', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_filtro_de_estado_se_combina_con_la_busqueda(): void
    {
        $this->makeUser('Carla Activa', ['status' => 'active']);
        $this->makeUser('Carla Inactiva', ['status' => 'inactive']);

        $response = $this->getJson('/api/users?search=carla&status=inactive', $this->adminHeaders())
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Carla Inactiva', $response->json('data.0.name'));
    }

    public function test_registros_incompletos_aceptan_la_sesion_admin(): void
    {
        // Con el secreto de registro configurado, la ruta de la app rechaza el
        // token admin: esa era la causa del "tu sesión expiró" en Miembros.
        config(['services.member_registration.token' => 'app-registration-secret']);

        Member::create([
            'full_name'       => 'Registro Incompleto',
            'document_number' => '55443322',
            'phone'           => '3001112233',
            'status'          => Member::STATUS_INCOMPLETE,
        ]);

        $this->getJson('/api/members/incomplete', $this->adminHeaders())
            ->assertStatus(401);

        $this->getJson('/api/admin/members/incomplete', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Registro Incompleto');
    }

    public function test_registros_incompletos_siguen_exigiendo_credencial(): void
    {
        $this->getJson('/api/admin/members/incomplete')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    public function test_stats_de_planes_agrega_suscriptores_e_ingresos(): void
    {
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $anual = Plan::create(['name' => 'Anual', 'price' => 800000, 'duration_days' => 365, 'active' => false]);

        $conMensual = $this->makeUser('Con Mensual', ['plan' => 'Mensual']);
        $this->makeUser('Otro Mensual', ['plan' => 'Mensual']);
        // Inactivo: no cuenta como suscriptor activo.
        $this->makeUser('Mensual Inactivo', ['plan' => 'Mensual', 'status' => 'inactive']);
        $this->makeUser('Sin Plan');

        // Pago del mes en curso atribuido al plan por plan_id.
        Payment::create([
            'user_id' => $conMensual->id,
            'plan_id' => $mensual->id,
            'amount'  => 80000,
            'status'  => 'paid',
            'paid_at' => now(),
        ]);
        // Pago sin plan_id: se atribuye por el plan del miembro.
        Payment::create([
            'user_id' => $conMensual->id,
            'amount'  => 20000,
            'status'  => 'approved',
            'paid_at' => now(),
        ]);
        // Pago del mes pasado: fuera del cálculo.
        Payment::create([
            'user_id' => $conMensual->id,
            'plan_id' => $mensual->id,
            'amount'  => 999000,
            'status'  => 'paid',
            'paid_at' => now()->subMonthNoOverflow()->startOfMonth(),
        ]);
        // Pago pendiente: no cuenta.
        Payment::create([
            'user_id' => $conMensual->id,
            'plan_id' => $mensual->id,
            'amount'  => 500000,
            'status'  => 'pending',
            'paid_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/plans/stats', $this->adminHeaders())->assertOk();

        $this->assertSame(1, $response->json('active_plans'));
        $this->assertSame(2, $response->json('subscribers_total'));
        $this->assertSame(100000.0, (float) $response->json('monthly_income'));

        $porPlan = collect($response->json('plans'))->keyBy('plan_id');
        $this->assertSame(2, $porPlan[$mensual->id]['subscribers']);
        $this->assertSame(100000.0, (float) $porPlan[$mensual->id]['month_income']);
        $this->assertSame(0, $porPlan[$anual->id]['subscribers']);
        $this->assertSame(0.0, (float) $porPlan[$anual->id]['month_income']);
    }

    public function test_stats_de_planes_exige_credencial_admin(): void
    {
        $this->getJson('/api/admin/plans/stats')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }
}
