<?php

namespace Tests\Feature\Plans;

use App\Http\Controllers\Api\MemberAppStateController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Models\PlanTransferBatch;
use App\Models\RolePermission;
use App\Models\Trainer;
use App\Services\Admin\AdminSessionService;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use App\Support\DocumentMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Las correcciones de la revisión de seguridad del traslado, cada una con la
 * prueba que falla si se quita:
 *
 *   M1  el documento sale tapado, y de quien no está en el plan origen no se
 *       enseña nada de su membresía;
 *   B1  el cupo de peticiones es por ADMINISTRADOR, no por IP;
 *   B2  una lista enorme de socios se rechaza sin validarla id por id;
 *   B3  una cuenta de entrenador no traslada aunque le den el permiso;
 *   I1  un plan cuyo nombre no cabe en `users.plan` se rechaza antes;
 *   I2  la misma llave con otros planes no es un replay;
 *   I4  `user_ids` tiene que ser una lista.
 */
class PlanTransferHardeningTest extends TestCase
{
    use PlanTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraslado();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── M1 · Datos personales ───────────────────────────────────────────────

    public function test_el_documento_sale_tapado_en_candidatos_y_simulacion(): void
    {
        $socio = $this->socio(null, ['document' => '1075123456']);

        $candidatos = $this->getJson('/api/admin/plan-transfers/candidates?from_plan_id='.$this->origen->id
            .'&to_plan_id='.$this->destino->id, $this->h)->assertOk();
        $simulacion = $this->simular([$socio->id])->assertOk();

        $this->assertSame('••••••3456', $candidatos->json('members.0.document'));
        $this->assertSame('••••••3456', $simulacion->json('items.0.document'));
        $this->assertStringNotContainsString('1075123456', $candidatos->getContent());
        $this->assertStringNotContainsString('1075123456', $simulacion->getContent());

        // Ni el lote, ni su detalle, ni la auditoría lo guardan.
        $lote = $this->ejecutar($simulacion->json(), [$socio->id])->assertCreated();
        $this->assertStringNotContainsString('1075123456', $lote->getContent());
        $this->assertStringNotContainsString('1075123456', json_encode(DB::table('plan_transfer_items')->get()));
        $this->assertStringNotContainsString('1075123456', json_encode(DB::table('plan_transfer_batches')->get()));
        $this->assertStringNotContainsString('1075123456', json_encode(AuditLog::all()->toArray()));
    }

    public function test_de_quien_no_esta_en_el_origen_no_se_ensena_su_membresia_ni_su_plan(): void
    {
        $ajeno = $this->socio('Plan Corporativo Reservado', ['document' => '52987654321']);

        $r = $this->simular([$ajeno->id, 999999])->assertOk();

        $r->assertJsonPath('items.0.blockers.0.code', 'plan_mismatch')
            ->assertJsonPath('items.0.blockers.0.message', 'No está en «TOTAL ACCESS - ELITE».')
            ->assertJsonPath('items.0.before', null)
            ->assertJsonPath('items.0.after', null)
            ->assertJsonPath('items.0.situation', null)
            ->assertJsonPath('items.0.warnings', [])
            ->assertJsonPath('items.0.document', '•••••••4321')
            ->assertJsonPath('items.1.blockers.0.code', 'member_not_found')
            ->assertJsonPath('items.1.before', null)
            ->assertJsonPath('items.1.after', null);
        $this->assertStringNotContainsString('Plan Corporativo Reservado', $r->getContent());
        $this->assertStringNotContainsString($ajeno->membershipEndDate, $r->getContent());
    }

    public function test_la_huella_sigue_valiendo_para_ejecutar_con_un_ajeno_en_la_lista(): void
    {
        // La simulación y la ejecución usan el mismo simulate(): que el ajeno
        // salga sin foto no puede romper la comparación de huellas.
        $bueno = $this->socio();
        $ajeno = $this->socio('Mensual');

        $this->trasladar([$bueno->id, $ajeno->id])->assertCreated()
            ->assertJsonPath('batch.members_count', 1)
            ->assertJsonPath('batch.excluded.0.code', 'plan_mismatch');
    }

    public function test_el_enmascarado_es_el_mismo_que_ya_usaba_la_app(): void
    {
        $original = new ReflectionMethod(MemberAppStateController::class, 'maskDocument');
        $controlador = app(MemberAppStateController::class);

        foreach (['1075123456' => '••••••3456', '1234' => '••••', '123' => '•••', '' => null, 'AB12345' => '•••2345'] as $doc => $esperado) {
            $this->assertSame($esperado, DocumentMask::mask((string) $doc));
            $this->assertSame($esperado, $original->invoke($controlador, (string) $doc), 'Cambió el enmascarado de la app.');
        }
        $this->assertNull(DocumentMask::mask(null));
    }

    // ── B1 · Cupo por administrador ─────────────────────────────────────────

    /** Una ejecución con huella inventada: cuenta como escritura y no escribe. */
    private function escritura(array $h): TestResponse
    {
        return $this->postJson('/api/admin/plan-transfers', [
            'from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id, 'user_ids' => [$this->socio()->id],
            'fingerprint' => str_repeat('c', 64), 'reason' => 'Prueba de cupo', 'client_request_id' => 'cupo-'.uniqid('', true),
        ], $h);
    }

    public function test_el_trafico_anonimo_de_la_misma_ip_no_gasta_el_cupo_del_admin(): void
    {
        // Doce intentos sin sesión contra la misma ruta y el login del CRM,
        // desde la misma IP: la app y la recepción salen por el mismo Wi-Fi.
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/admin/plan-transfers', []);
            $this->postJson('/api/admin/auth/login', ['email' => 'nadie@ironbody.test', 'password' => 'mala']);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->escritura($this->h)->assertStatus(409);
        }
    }

    public function test_los_traslados_no_agotan_el_login_del_crm(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->escritura($this->h)->assertStatus(409);
        }

        $login = $this->postJson('/api/admin/auth/login', ['email' => 'nadie@ironbody.test', 'password' => 'mala']);
        $this->assertNotSame(429, $login->status(), 'Diez traslados dejaron el login del CRM bloqueado.');
    }

    public function test_la_undecima_escritura_del_mismo_admin_desde_otra_sesion_da_429(): void
    {
        $admin = Admin::create(['name' => 'Dueña', 'email' => 'duena-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);
        $sesionA = $this->actingAsAdmin($admin);
        $sesionB = ['Authorization' => 'Bearer '.app(AdminSessionService::class)->issueSession($admin)['token']];

        for ($i = 0; $i < 10; $i++) {
            $this->escritura($sesionA)->assertStatus(409);
        }

        $this->escritura($sesionB)->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonStructure(['message', 'retry_after']);

        // Otra persona, desde la misma IP, conserva su cupo entero.
        $this->escritura($this->h)->assertStatus(409);
        // Y sus lecturas van por otro cubo.
        $this->simular([$this->socio()->id], null, null, $sesionB)->assertOk();
    }

    // ── B2 · Listas enormes ─────────────────────────────────────────────────

    public function test_veinte_mil_socios_se_rechazan_sin_validarlos_uno_a_uno(): void
    {
        $ids = range(1, 20000);
        $lote = PlanTransferBatch::create([
            'kind' => 'transfer', 'status' => 'applied', 'from_plan_name' => 'A', 'to_plan_name' => 'B',
            'from_conditions' => [], 'to_conditions' => [], 'reason' => 'Lote de prueba', 'fingerprint' => str_repeat('d', 64),
            'client_request_id' => 'lote-b2-0001', 'members_count' => 0, 'business_day' => '2026-10-15', 'executed_at' => now(),
        ]);
        $confirmar = ['fingerprint' => str_repeat('d', 64), 'reason' => 'Prueba de tamaño', 'client_request_id' => 'b2-'.uniqid()];
        $planes = ['from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id];

        foreach ([
            ['/api/admin/plan-transfers/dry-run', $planes],
            ['/api/admin/plan-transfers', $planes + $confirmar],
            ["/api/admin/plan-transfers/{$lote->id}/revert/dry-run", []],
            ["/api/admin/plan-transfers/{$lote->id}/revert", $confirmar],
        ] as [$ruta, $cuerpo]) {
            $inicio = microtime(true);
            $r = $this->postJson($ruta, $cuerpo + ['user_ids' => $ids], $this->h);
            $segundos = microtime(true) - $inicio;

            $r->assertStatus(422)->assertJsonValidationErrors('user_ids');
            $this->assertStringContainsString('Como mucho 300 socios', $r->json('errors.user_ids.0'));
            $this->assertLessThan(1.5, $segundos, "{$ruta} tardó {$segundos}s en rechazar 20.000 ids.");
        }
    }

    // ── B3 · Entrenador con el permiso concedido ────────────────────────────

    public function test_un_entrenador_con_plans_transfer_recibe_403_en_las_siete_rutas(): void
    {
        $socio = $this->socio();
        $lote = (int) $this->trasladar([$socio->id])->assertCreated()->json('batch.id');

        AdminRole::firstOrCreate(['name' => CrmPermission::ROLE_ENTRENADOR], ['is_system' => true]);
        RolePermission::updateOrCreate(['role' => CrmPermission::ROLE_ENTRENADOR, 'permission' => 'plans.transfer'], ['granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $entrenador = Admin::create(['name' => 'Coach', 'email' => 'coach-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => CrmPermission::ROLE_ENTRENADOR, 'status' => 'active',
            'trainer_id' => Trainer::create(['full_name' => 'Coach', 'status' => 'active'])->id]);
        $this->assertTrue(CrmPermission::allows($entrenador, 'plans.transfer'), 'La prueba necesita que la puerta le deje pasar.');
        $h = $this->actingAsAdmin($entrenador);

        $planes = ['from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id];
        $confirmar = ['fingerprint' => str_repeat('e', 64), 'reason' => 'Prueba de alcance', 'client_request_id' => 'b3-'.uniqid()];
        $respuestas = [
            $this->getJson('/api/admin/plan-transfers/candidates?'.http_build_query($planes), $h),
            $this->postJson('/api/admin/plan-transfers/dry-run', $planes + ['user_ids' => [$socio->id]], $h),
            $this->getJson('/api/admin/plan-transfers', $h),
            $this->postJson('/api/admin/plan-transfers', $planes + ['user_ids' => [$socio->id]] + $confirmar, $h),
            $this->getJson("/api/admin/plan-transfers/{$lote}", $h),
            $this->postJson("/api/admin/plan-transfers/{$lote}/revert/dry-run", [], $h),
            $this->postJson("/api/admin/plan-transfers/{$lote}/revert", $confirmar, $h),
        ];

        foreach ($respuestas as $i => $r) {
            $r->assertStatus(403)->assertJsonPath('code', 'trainer_scope');
            $this->assertStringNotContainsString($socio->name, $r->getContent(), "La ruta {$i} enseñó al socio.");
        }
        $this->assertSame(1, PlanTransferBatch::count());
    }

    // ── I1 · Nombres que no caben ───────────────────────────────────────────

    public function test_un_plan_con_nombre_de_mas_de_cien_caracteres_se_rechaza_antes(): void
    {
        $socio = $this->socio();
        $largo = $this->plan(str_repeat('É', 101));

        $this->simular([$socio->id], null, $largo)->assertStatus(422)->assertJsonPath('code', 'to_plan_name_too_long');
        // Y al ejecutar, también 422 (antes, en PostgreSQL, un 500 en el UPDATE).
        $this->ejecutar(['fingerprint' => str_repeat('a', 64)], [$socio->id], [], null, $largo)
            ->assertStatus(422)->assertJsonPath('code', 'to_plan_name_too_long');
        $this->getJson('/api/admin/plan-transfers/candidates?from_plan_id='.$this->origen->id.'&to_plan_id='.$largo->id, $this->h)
            ->assertStatus(422)->assertJsonPath('code', 'to_plan_name_too_long');

        $origenLargo = $this->plan(str_repeat('x', 101));
        $this->simular([$socio->id], $origenLargo, $this->destino)->assertStatus(422)->assertJsonPath('code', 'from_plan_name_too_long');
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->refresh()->plan);
    }

    public function test_cien_caracteres_justos_caben_y_se_trasladan(): void
    {
        // El borde: cien caracteres (con tilde, que en bytes son más) caben en
        // varchar(100). En PostgreSQL esta prueba ejecuta el INSERT de verdad.
        $socio = $this->socio();
        $cien = $this->plan(str_repeat('É', 100));

        $this->trasladar([$socio->id], [], null, $cien)->assertCreated();
        $this->assertSame(str_repeat('É', 100), $socio->refresh()->plan);
    }

    // ── I2 · El replay mira el cuerpo ───────────────────────────────────────

    public function test_la_misma_llave_y_huella_con_otros_planes_es_un_conflicto(): void
    {
        $socio = $this->socio();
        $otroDestino = $this->plan('Premium');
        $simulacion = $this->simular([$socio->id])->assertOk()->json();
        $llave = 'op-i2-misma-llave';

        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave])->assertCreated();

        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave], null, $otroDestino)
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave], $otroDestino, $this->destino)
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        // El reintento de verdad sigue siendo un replay.
        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, PlanTransferBatch::count());
        $this->assertSame('Élite', $socio->refresh()->plan);
    }

    // ── I4 · Una lista y no un objeto ───────────────────────────────────────

    public function test_user_ids_tiene_que_ser_una_lista(): void
    {
        $socio = $this->socio();

        $this->postJson('/api/admin/plan-transfers/dry-run', [
            'from_plan_id' => $this->origen->id, 'to_plan_id' => $this->destino->id, 'user_ids' => ['a' => $socio->id],
        ], $this->h)->assertStatus(422)->assertJsonValidationErrors('user_ids');
    }
}
