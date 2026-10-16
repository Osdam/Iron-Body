<?php

namespace Tests\Feature\Plans;

use App\Http\Controllers\Api\Admin\PlanTransferController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\PlanTransferBatch;
use App\Services\Membership\PlanTransferEvaluator;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * QUIÉN PUEDE TRASLADAR SOCIOS ENTRE PLANES: el Administrador y el Super
 * Admin, y nadie más por defecto. Ni recepción, ni el entrenador, ni el rol
 * administrativo, ni el token de automatizaciones — tampoco en las lecturas,
 * porque ver la lista de candidatos ya es preparar un traslado.
 */
class PlanTransferPermissionsTest extends TestCase
{
    use PlanTransferFixtures;
    use RefreshDatabase;

    private int $lote;

    private int $socio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraslado();

        $socio = $this->socio();
        $this->socio = $socio->id;
        $this->lote = (int) $this->trasladar([$socio->id], [], null, null, $this->adminHeaders())->assertCreated()->json('batch.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Las siete rutas, con un cuerpo que pasaría la validación. Todas desde la
     * misma IP: el cupo de peticiones es por administrador
     * (PlanTransferHardeningTest), así que cinco credenciales seguidas no se
     * estorban.
     */
    private function golpearTodas(array $h): array
    {
        $plans = ['from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id];
        $confirmar = ['fingerprint' => str_repeat('a', 64), 'reason' => 'Prueba de permisos', 'client_request_id' => 'perm-'.uniqid(), 'confirm_warnings' => true];

        return [
            'GET candidates' => $this->getJson('/api/admin/plan-transfers/candidates?'.http_build_query($plans), $h)->status(),
            'POST dry-run' => $this->postJson('/api/admin/plan-transfers/dry-run', $plans + ['user_ids' => [$this->socio]], $h)->status(),
            'GET index' => $this->getJson('/api/admin/plan-transfers', $h)->status(),
            'POST store' => $this->postJson('/api/admin/plan-transfers', $plans + ['user_ids' => [$this->socio]] + $confirmar, $h)->status(),
            'GET show' => $this->getJson("/api/admin/plan-transfers/{$this->lote}", $h)->status(),
            'POST revert/dry-run' => $this->postJson("/api/admin/plan-transfers/{$this->lote}/revert/dry-run", [], $h)->status(),
            'POST revert' => $this->postJson("/api/admin/plan-transfers/{$this->lote}/revert", $confirmar, $h)->status(),
        ];
    }

    public function test_recepcion_entrenador_administrativo_y_token_reciben_403_en_las_siete_rutas(): void
    {
        AdminRole::firstOrCreate(['name' => CrmPermission::ROLE_ENTRENADOR], ['is_system' => true]);
        $credenciales = [
            'Recepción' => $this->adminAs(Admin::ROLE_RECEPCION),
            'Entrenador' => $this->adminAs(CrmPermission::ROLE_ENTRENADOR),
            'Administrativo' => $this->adminAs(Admin::ROLE_ADMINISTRATIVO),
            'token compartido' => $this->sharedTokenHeaders(),
            // Una persona con todo lo de planes MENOS trasladar.
            'persona sin el permiso' => $this->adminWithPermissions(['plans.view', 'plans.manage', 'members.view', 'members.edit']),
        ];

        foreach ($credenciales as $quien => $h) {
            foreach ($this->golpearTodas($h) as $ruta => $estado) {
                $this->assertSame(403, $estado, "{$quien} pasó por {$ruta}.");
            }
        }

        $this->assertSame(1, PlanTransferBatch::count(), 'Alguien sin permiso creó un lote.');
    }

    public function test_el_403_dice_que_falta_plans_transfer(): void
    {
        $this->getJson('/api/admin/plan-transfers', $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'plans.transfer');
    }

    public function test_administrador_y_super_admin_pueden_leer_y_simular(): void
    {
        foreach ([$this->adminAs(Admin::ROLE_ADMINISTRADOR), $this->adminHeaders()] as $h) {
            $estados = $this->golpearTodas($h);
            foreach (['GET candidates', 'POST dry-run', 'GET index', 'GET show', 'POST revert/dry-run'] as $ruta) {
                $this->assertSame(200, $estados[$ruta], "No pudo {$ruta}.");
            }
            // Con una huella inventada la ejecución no aplica nada, pero la
            // puerta sí le dejó llegar.
            $this->assertSame(409, $estados['POST store']);
            $this->assertSame(409, $estados['POST revert']);
        }
    }

    public function test_la_politica_por_defecto(): void
    {
        $admin = fn (string $rol): Admin => Admin::create(['name' => $rol, 'email' => 'p-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);
        AdminRole::firstOrCreate(['name' => CrmPermission::ROLE_ENTRENADOR], ['is_system' => true]);

        $this->assertTrue(CrmPermission::allows($admin(Admin::ROLE_ADMINISTRADOR), 'plans.transfer'));
        $this->assertTrue(CrmPermission::allows($admin(Admin::ROLE_SUPER_ADMIN), 'plans.transfer'));
        foreach ([Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, Admin::ROLE_ADMINISTRATIVO] as $rol) {
            $this->assertFalse(CrmPermission::allows($admin($rol), 'plans.transfer'), "{$rol} no debe trasladar.");
        }
        $this->assertNotContains('plans.transfer', CrmPermission::readOnly());
        $this->assertFalse(CrmPermission::allows(null, 'plans.transfer'));

        // En el catálogo, con su nombre y su explicación.
        $fila = collect(PermissionCatalog::rows())->firstWhere('key', 'plans.transfer');
        $this->assertSame('plans', $fila['domain']);
        $this->assertSame('Trasladar socios', $fila['label']);
        $this->assertStringContainsString('sin cobrar nada', $fila['help']);
    }

    public function test_las_siete_rutas_estan_declaradas_una_a_una(): void
    {
        $rutas = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/plan-transfers'))
            ->mapWithKeys(fn ($r) => [AuthorizationMap::routeKey($r) => AuthorizationMap::resolve($r)])
            ->all();

        $this->assertCount(7, $rutas);
        $this->assertSame(array_fill_keys(array_keys($rutas), 'plans.transfer'), $rutas);
    }

    public function test_sin_persona_el_controlador_rechaza_aunque_la_puerta_dejara_pasar(): void
    {
        // La segunda cerradura: si algún día alguien le concediera el permiso
        // al token compartido, el controlador seguiría exigiendo una persona.
        $request = Request::create('/api/admin/plan-transfers/candidates', 'GET', [
            'from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id,
        ]);

        $respuesta = app(PlanTransferController::class)->candidates($request, app(PlanTransferEvaluator::class));

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertSame('person_required', $respuesta->getData(true)['code']);
    }
}
