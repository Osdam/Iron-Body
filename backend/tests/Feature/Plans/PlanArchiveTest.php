<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Support\MemberPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ARCHIVAR Y REACTIVAR UN PLAN (POST /api/plans/{plan}/archive | reactivate).
 *
 * Archivar lo saca de la venta y de la app y no toca a nadie: quien ya lo
 * tiene conserva su membresía, sus fechas, su acceso y sus funciones.
 * Reactivar exige que se pueda volver a vender. Las dos cosas quedan en la
 * auditoría y son idempotentes.
 */
class PlanArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota')->utc());
        $this->admin = Admin::create(['name' => 'Ana Administra', 'email' => 'ana-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);
        // Archivar no llega por defecto al Administrador: se le concede como lo
        // haría el propietario, desde la matriz de Usuarios y roles.
        $this->putJson('/api/admin/roles/permissions', ['role' => Admin::ROLE_ADMINISTRADOR,
            'permission' => 'plans.archive', 'granted' => true], $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertOk();
        $this->h = $this->actingAsAdmin($this->admin);
        $this->plan = Plan::create(['name' => 'Trimestral Élite', 'price' => 210000, 'duration_days' => 90,
            'active' => true, 'sellable' => true, 'visible_in_app' => true,
            'features' => ['iron_ia' => true, 'workouts' => true, 'classes' => true]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Archivar ───────────────────────────────────────────────────────────

    public function test_archivar_lo_saca_de_la_venta_y_de_la_app_y_deja_constancia(): void
    {
        $this->socioCon($this->plan);

        $r = $this->archivar()->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already', false)
            ->assertJsonPath('plan.id', $this->plan->id)
            ->assertJsonPath('plan.is_archived', true)
            ->assertJsonPath('plan.active', false)
            ->assertJsonPath('plan.sellable', false)
            ->assertJsonPath('plan.visible_in_app', false)
            ->assertJsonPath('plan.archived_by_name', 'Ana Administra')
            ->assertJsonPath('plan.archive_reason', 'Se reemplaza por el plan anual')
            ->assertJsonPath('members_with_plan', 1)
            ->assertJsonPath('live_subscriptions', 0);
        $this->assertNotNull($r->json('plan.archived_at'));
        $this->assertArrayNotHasKey('archived_by', $r->json('plan'), 'El id del admin no viaja: el listado es público.');

        $plan = $this->plan->fresh();
        $this->assertTrue($plan->isArchived());
        $this->assertSame((int) $this->admin->id, $plan->archived_by);
        $this->assertFalse($plan->isSellable());
        $this->assertFalse($plan->isVisibleInApp());
        $this->assertNotContains($plan->id, Plan::sellable()->pluck('id')->all());

        $traza = AuditLog::where('entity', 'plan')->where('action', 'status')->sole();
        $this->assertSame('Archivó el plan Trimestral Élite', $traza->summary);
        $this->assertSame('archive', $traza->metadata['operation']);
        $this->assertSame('plan.updated', $traza->metadata['event']);
        $this->assertSame('Se reemplaza por el plan anual', $traza->metadata['reason']);
        $this->assertSame(['active' => true, 'sellable' => true, 'visible_in_app' => true], $traza->metadata['before']);
        $this->assertSame((string) $this->admin->id, $traza->actor_id);
        $this->assertContains('active', array_column($traza->changes, 'field'));
    }

    public function test_archivar_dos_veces_responde_already_y_no_escribe(): void
    {
        $this->archivar()->assertOk();
        $trazas = AuditLog::count();
        $antes = $this->plan->fresh()->archived_at;

        $this->archivar('Otro motivo distinto')->assertOk()->assertJsonPath('already', true)
            ->assertJsonPath('plan.archive_reason', 'Se reemplaza por el plan anual');

        $this->assertSame($trazas, AuditLog::count());
        $this->assertEquals($antes, $this->plan->fresh()->archived_at);
    }

    public function test_archivar_exige_motivo_y_permiso_de_gestion(): void
    {
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'no'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'Motivo válido'], $this->adminWithPermissions(['plans.view']))
            ->assertForbidden();
        $this->postJson("/api/plans/{$this->plan->id}/reactivate", ['reason' => 'Motivo válido'], $this->adminWithPermissions(['plans.view']))
            ->assertForbidden();
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'Motivo válido'], $this->sharedTokenHeaders())
            ->assertForbidden();
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'Motivo válido'])->assertUnauthorized();

        $this->assertFalse($this->plan->fresh()->isArchived());
        // Editar planes (`plans.manage`) NO basta: archivar y reactivar tienen su llave.
        $soloEdita = $this->adminWithPermissions(['plans.view', 'plans.manage']);
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'Motivo válido'], $soloEdita)->assertForbidden();
        $this->postJson("/api/plans/{$this->plan->id}/reactivate", ['reason' => 'Motivo válido'], $soloEdita)->assertForbidden();
        $this->assertFalse($this->plan->fresh()->isArchived());

        // Con `plans.archive` sí, sin necesitar `plans.manage`.
        $archiva = $this->adminWithPermissions(['plans.view', 'plans.archive']);
        $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => 'Motivo válido'], $archiva)->assertOk();
        $this->assertTrue($this->plan->fresh()->isArchived());
        $this->postJson("/api/plans/{$this->plan->id}/reactivate", ['reason' => 'Motivo válido'], $archiva)->assertOk();
        $this->assertFalse($this->plan->fresh()->isArchived());
    }

    public function test_retirar_el_permiso_desde_la_matriz_cierra_archivar_y_reactivar(): void
    {
        $this->putJson('/api/admin/roles/permissions', ['role' => Admin::ROLE_ADMINISTRADOR,
            'permission' => 'plans.archive', 'granted' => false], $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertOk();
        \Illuminate\Support\Facades\Cache::flush();

        $this->archivar()->assertForbidden();
        $this->postJson("/api/plans/{$this->plan->id}/reactivate", ['reason' => 'Motivo válido'], $this->h)->assertForbidden();
        $this->assertFalse($this->plan->fresh()->isArchived());
    }

    public function test_archivar_y_reactivar_no_llega_por_defecto_mas_que_a_super_admin(): void
    {
        $roles = \App\Support\Access\CrmPermission::byRole();
        foreach ($roles as $rol => $permisos) {
            $this->assertSame($rol === \App\Models\Admin::ROLE_SUPER_ADMIN, in_array('plans.archive', $permisos, true), "Rol {$rol}");
        }
        $this->assertContains('plans.archive', \App\Support\Access\PermissionCatalog::all());
    }

    public function test_quien_ya_tiene_el_plan_conserva_membresia_acceso_funciones_cobros_y_facturas(): void
    {
        [$socio, $ficha] = $this->socioCon($this->plan);
        $vencida = $this->socioCon($this->plan, '2026-08-01', '2026-09-01')[0];
        $tablas = ['users', 'members', 'payments', 'receivables', 'receivable_payments', 'electronic_invoices',
            'membership_subscriptions', 'plan_transfer_items', 'membership_transfers'];
        $antes = $this->fotos($tablas);
        $acceso = app(MembershipAccess::class)->state($socio->fresh());
        $funciones = MemberPayload::featuresFor($socio->fresh());
        $this->assertTrue($acceso['can_enter']);

        $this->archivar()->assertOk();

        $this->assertSame($antes, $this->fotos($tablas), 'Archivar tocó socios, cobros o documentos.');
        $despues = app(MembershipAccess::class)->state($socio->fresh());
        $this->assertSame($acceso['can_enter'], $despues['can_enter']);
        $this->assertSame($acceso['rules'], $despues['rules']);
        $this->assertSame($funciones, MemberPayload::featuresFor($socio->fresh()));
        $this->assertSame($funciones, $ficha->fresh()->resolvedFeatures());
        $this->assertTrue($funciones['iron_ia'], 'Sigue con las funciones de su plan.');
        // En su historial el cobro sigue con su plan, ahora marcado histórico.
        $this->getJson("/api/admin/users/{$socio->id}/payment-history", $this->h)->assertOk()
            ->assertJsonPath('payments.0.plan.name', 'Trimestral Élite')
            ->assertJsonPath('payments.0.plan.active', false);

        // Reactivar tampoco toca a nadie (ni a la vencida).
        $this->reactivar()->assertOk();
        $this->assertSame($antes, $this->fotos($tablas));
        $this->assertSame('2026-09-01', $vencida->fresh()->membershipEndDate);
    }

    // ── Reactivar ──────────────────────────────────────────────────────────

    public function test_reactivar_lo_devuelve_a_la_venta_y_deja_constancia(): void
    {
        $this->archivar()->assertOk();

        $this->reactivar()->assertOk()
            ->assertJsonPath('already', false)
            ->assertJsonPath('plan.is_archived', false)
            ->assertJsonPath('plan.archived_at', null)
            ->assertJsonPath('plan.archived_by_name', null)
            ->assertJsonPath('plan.archive_reason', null)
            ->assertJsonPath('plan.active', true)
            ->assertJsonPath('plan.sellable', true)
            ->assertJsonPath('plan.visible_in_app', true);
        $this->assertTrue($this->plan->fresh()->isSellable());

        $traza = AuditLog::where('entity', 'plan')->where('action', 'status')->orderByDesc('id')->first();
        $this->assertSame('reactivate', $traza->metadata['operation']);
        $this->assertSame('Vuelve a venderse en temporada', $traza->metadata['reason']);
        $this->assertSame('Se reemplaza por el plan anual', $traza->metadata['previous_archive']['archive_reason']);
        $this->assertSame((int) $this->admin->id, $traza->metadata['previous_archive']['archived_by']);
    }

    public function test_reactivar_puede_dejarlo_fuera_de_la_app(): void
    {
        $this->archivar()->assertOk();

        $this->reactivar(['visible_in_app' => false])->assertOk()
            ->assertJsonPath('plan.active', true)
            ->assertJsonPath('plan.sellable', true)
            ->assertJsonPath('plan.visible_in_app', false);
    }

    public function test_reactivar_uno_ya_activo_responde_already(): void
    {
        $trazas = AuditLog::count(); // la concesión del permiso en setUp ya dejó la suya
        $this->reactivar()->assertOk()->assertJsonPath('already', true);
        $this->assertSame($trazas, AuditLog::count());
    }

    public static function invalidos(): array
    {
        return [
            'precio cero' => [['price' => 0], 'plan_price_invalid'],
            'duración cero' => [['duration_days' => 0], 'plan_duration_invalid'],
            'por entradas sin entradas' => [['access_mode' => 'entries', 'entry_credits' => null], 'plan_entries_invalid'],
            'nombre que no cabe en la ficha' => [['name' => str_repeat('x', 101)], 'plan_name_too_long'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_reactivar_un_plan_que_no_se_puede_vender_responde_422(array $columnas, string $codigo): void
    {
        $this->archivar()->assertOk();
        DB::table('plans')->where('id', $this->plan->id)->update($columnas);

        $r = $this->reactivar()->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('code', 'plan_not_reactivable');
        $this->assertContains($codigo, array_column($r->json('reasons'), 'code'));
        $this->assertTrue($this->plan->fresh()->isArchived(), 'Se quedó archivado.');
    }

    public function test_reactivar_choca_con_otro_plan_vendible_del_mismo_nombre(): void
    {
        $this->archivar()->assertOk();
        // Mismo nombre para la puerta (sin tildes ni mayúsculas).
        Plan::create(['name' => 'trimestral elite', 'price' => 200000, 'duration_days' => 90, 'active' => true, 'sellable' => true]);

        $r = $this->reactivar()->assertStatus(422)->assertJsonPath('code', 'plan_not_reactivable');
        $this->assertContains('plan_name_conflict', array_column($r->json('reasons'), 'code'));
    }

    // ── Edición y listado ──────────────────────────────────────────────────

    public function test_un_plan_archivado_no_se_activa_editandolo_pero_si_se_edita_lo_demas(): void
    {
        $this->archivar()->assertOk();

        $this->putJson("/api/plans/{$this->plan->id}", ['active' => true], $this->h)->assertStatus(422)->assertJsonPath('code', 'plan_archived');
        $this->putJson("/api/plans/{$this->plan->id}", ['visible_in_app' => true], $this->h)->assertStatus(422);
        $this->putJson("/api/plans/{$this->plan->id}", ['price' => 220000], $this->h)->assertOk()
            ->assertJsonPath('is_archived', true)->assertJsonPath('active', false);
    }

    public function test_el_listado_por_defecto_es_el_de_siempre_y_filtra_archivados_si_se_pide(): void
    {
        $otro = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->archivar()->assertOk();

        $this->assertSame([$this->plan->id, $otro->id], $this->ids($this->getJson('/api/plans', $this->h)), 'Por defecto: todos, como siempre.');
        $this->assertSame([$this->plan->id, $otro->id], $this->ids($this->getJson('/api/plans?archived=include', $this->h)));
        $this->assertSame([$otro->id], $this->ids($this->getJson('/api/plans?archived=exclude', $this->h)));
        $this->assertSame([$this->plan->id], $this->ids($this->getJson('/api/plans?archived=only', $this->h)));
        $this->getJson('/api/plans?archived=todo', $this->h)->assertStatus(422);

        $fila = collect($this->getJson('/api/plans', $this->h)->json('data'))->firstWhere('id', $this->plan->id);
        $this->assertSame(['is_archived' => true, 'archived_by_name' => 'Ana Administra', 'archive_reason' => 'Se reemplaza por el plan anual'],
            array_intersect_key($fila, array_flip(['is_archived', 'archived_by_name', 'archive_reason'])));
        $this->assertNotNull($fila['archived_at']);
        $libre = collect($this->getJson('/api/plans', $this->h)->json('data'))->firstWhere('id', $otro->id);
        $this->assertSame([false, null, null, null], [$libre['is_archived'], $libre['archived_at'], $libre['archived_by_name'], $libre['archive_reason']]);
    }

    public function test_sin_sesion_de_administrador_no_salen_quien_ni_por_que(): void
    {
        $this->archivar()->assertOk();

        foreach (['/api/plans', '/api/plans/'.$this->plan->id] as $ruta) {
            $r = $this->getJson($ruta)->assertOk();
            $fila = $ruta === '/api/plans' ? collect($r->json('data'))->firstWhere('id', $this->plan->id) : $r->json();
            $this->assertTrue($fila['is_archived']);
            $this->assertNull($fila['archived_by_name']);
            $this->assertNull($fila['archive_reason']);
            $this->assertArrayNotHasKey('archived_by', $fila);
        }
        // La sesión de un socio tampoco es la de un administrador.
        [, $ficha] = $this->socioCon($this->plan);
        $fila = $this->getJson('/api/plans/'.$this->plan->id, ['Authorization' => 'Bearer '.$ficha->access_hash])->assertOk()->json();
        $this->assertNull($fila['archive_reason']);
        // Con sesión de administrador, sí.
        $this->getJson('/api/plans/'.$this->plan->id, $this->h)->assertOk()->assertJsonPath('archive_reason', 'Se reemplaza por el plan anual');
    }

    // ────────────────────────────────────────────────────────────────────────

    private function archivar(string $motivo = 'Se reemplaza por el plan anual'): TestResponse
    {
        return $this->postJson("/api/plans/{$this->plan->id}/archive", ['reason' => $motivo], $this->h);
    }

    /** @param  array<string, mixed>  $extra */
    private function reactivar(array $extra = []): TestResponse
    {
        return $this->postJson("/api/plans/{$this->plan->id}/reactivate", ['reason' => 'Vuelve a venderse en temporada'] + $extra, $this->h);
    }

    /** @return list<int> */
    private function ids(TestResponse $r): array
    {
        return collect($r->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    /** @return array{0: User, 1: Member} */
    private function socioCon(Plan $plan, string $inicio = '2026-10-20', string $fin = '2027-01-18'): array
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Socio Archivo '.$n, 'email' => 'archivo-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'document' => (string) (73000000 + $n), 'status' => 'active', 'plan' => $plan->name,
            'membership_start_date' => $inicio, 'membership_end_date' => $fin]);
        Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => $plan->price, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(), 'starts_on' => $inicio, 'period_start' => $inicio, 'period_end' => $fin]);
        $ficha = Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'document_number' => (string) (73000000 + $n),
            'access_hash' => 'tok-archivo-'.$n.'-'.uniqid(), 'status' => Member::STATUS_ACTIVE]);

        return [$user->refresh(), $ficha];
    }

    /** @param  list<string>  $tablas */
    private function fotos(array $tablas): array
    {
        return array_combine($tablas, array_map(
            fn (string $t): array => DB::table($t)->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all(),
            $tablas,
        ));
    }
}
