<?php

namespace Tests\Feature\Valuations;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationSetting;
use App\Services\Valuations\ValuationAccess;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Quién puede qué en la agenda de valoraciones (contrato §2), por permisos y
 * `admins.trainer_id`, nunca por nombre de rol; y el alcance «solo mis citas»
 * probado como IDOR: un entrenador no ve ni cierra la cita de otro aunque tenga
 * su uuid.
 */
class ValuationAccessTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private Trainer $andres;

    private Trainer $sofia;

    private Member $laura;

    private string $deAndres;

    private string $deSofia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($this->andres);
        $this->everyDay($this->sofia);
        $this->laura = $this->member('Laura Gómez');
        $rec = $this->reception();
        $this->deAndres = $this->book($rec, $this->laura, $this->andres, '2026-10-19T10:00:00-05:00', ['notes' => 'Nota de recepción'])->assertCreated()->json('data.uuid');
        $this->deSofia = $this->book($rec, $this->member('Pedro Ruiz'), $this->sofia, '2026-10-19T10:00:00-05:00')->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rango(): string
    {
        return 'from=2026-10-19T00:00:00-05:00&to=2026-10-20T00:00:00-05:00';
    }

    // ── Defaults por rol ───────────────────────────────────────────────────

    public function test_permisos_por_defecto_de_cada_rol(): void
    {
        $todos = ValuationAccess::ALL;
        $this->assertSame([], array_values(array_diff($todos, CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN))), 'Super Admin: todo');
        $this->assertSame(['valuations.manage_settings'], array_values(array_diff($todos, CrmPermission::defaultsFor(Admin::ROLE_ADMINISTRADOR))), 'Administrador: todo menos ajustes');

        $de = fn (string $rol) => array_values(array_filter(CrmPermission::defaultsFor($rol), fn ($p) => str_starts_with($p, 'valuations.')));
        $this->assertEqualsCanonicalizing(['valuations.view', 'valuations.view_all'], $de(Admin::ROLE_ADMINISTRATIVO));
        $this->assertEqualsCanonicalizing(['valuations.view', 'valuations.view_all', 'valuations.create', 'valuations.update', 'valuations.cancel'], $de(Admin::ROLE_RECEPCION));
        $this->assertEqualsCanonicalizing(['valuations.view', 'valuations.complete'], $de(CrmPermission::ROLE_ENTRENADOR));
    }

    public function test_el_token_compartido_no_recibe_nada_de_la_agenda(): void
    {
        $this->assertSame([], array_values(array_filter(CrmPermission::readOnly(), fn ($p) => str_starts_with($p, 'valuations.'))));

        $t = $this->sharedTokenHeaders();
        $this->getJson('/api/admin/valuations/capabilities', $t)->assertForbidden();
        $this->getJson('/api/admin/valuations?'.$this->rango(), $t)->assertForbidden();
        $this->getJson("/api/admin/valuations/{$this->deAndres}", $t)->assertForbidden();
        $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $t)->assertForbidden();
        $this->getJson('/api/admin/valuations/settings', $t)->assertForbidden();
        $this->postJson('/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00'), $t)->assertForbidden();
    }

    public function test_el_catalogo_describe_el_dominio_y_cada_permiso(): void
    {
        $filas = collect(PermissionCatalog::rows())->where('domain', 'valuations')->values();
        $this->assertEqualsCanonicalizing(ValuationAccess::ALL, $filas->pluck('key')->all());
        $this->assertSame('Agenda de valoraciones', $filas->first()['domain_label']);
        foreach ($filas as $f) {
            $this->assertNotSame($f['key'], $f['label'], "{$f['key']} tiene etiqueta en español");
            $this->assertNotNull($f['help'], "{$f['key']} tiene ayuda");
        }
        $this->assertSame('valuations.view', $filas->first()['key'], 'ver primero');
    }

    public function test_todas_las_rutas_de_la_agenda_estan_clasificadas_una_a_una(): void
    {
        $rutas = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/valuations'));
        $this->assertCount(22, $rutas);
        foreach ($rutas as $r) {
            $p = AuthorizationMap::resolve($r);
            $this->assertNotNull($p, AuthorizationMap::routeKey($r));
            $this->assertNotContains($p, [AuthorizationMap::SELF, AuthorizationMap::PUBLIC, AuthorizationMap::CONTROLLER], AuthorizationMap::routeKey($r));
            foreach ((array) $p as $permiso) {
                $this->assertStringStartsWith('valuations.', $permiso, AuthorizationMap::routeKey($r));
            }
        }
    }

    // ── Cada permiso abre lo suyo y nada más ───────────────────────────────

    public function test_view_sin_vinculo_no_tiene_alcance(): void
    {
        $h = $this->h($this->staffWith([ValuationAccess::VIEW]));
        $this->getJson('/api/admin/valuations/capabilities', $h)->assertOk()->assertJsonPath('data.can.view', true)->assertJsonPath('data.own_trainer_id', null);
        $this->getJson('/api/admin/valuations?'.$this->rango(), $h)->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/admin/valuations/{$this->deAndres}", $h)->assertForbidden();
    }

    public function test_view_all_lee_todo_pero_no_escribe(): void
    {
        $h = $this->h($this->staffWith([ValuationAccess::VIEW_ALL]));
        $this->assertCount(2, $this->getJson('/api/admin/valuations?'.$this->rango(), $h)->assertOk()->json('data'));
        $this->postJson('/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00'), $h)->assertForbidden();
        $this->postJson("/api/admin/valuations/{$this->deAndres}/cancel", ['expected_version' => 1, 'reason' => 'Prueba de permiso'], $h)->assertForbidden();
        $this->postJson("/api/admin/valuations/{$this->deAndres}/confirm", ['expected_version' => 1], $h)->assertForbidden();
        $this->getJson('/api/admin/valuations/members/search?q=lau', $h)->assertForbidden();
    }

    public function test_create_update_cancel_complete_cada_uno_lo_suyo(): void
    {
        $crea = $this->h($this->staffWith([ValuationAccess::VIEW_ALL, ValuationAccess::CREATE]));
        $u = $this->book($crea, $this->member('Nuevo'), $this->andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T11:00:00-05:00'], $crea)->assertForbidden();

        $mueve = $this->h($this->staffWith([ValuationAccess::VIEW_ALL, ValuationAccess::UPDATE]));
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T11:00:00-05:00'], $mueve)->assertOk();
        $this->patchJson("/api/admin/valuations/{$u}", ['expected_version' => 2, 'notes' => 'x'], $mueve)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 2, 'reason' => 'Sin permiso'], $mueve)->assertForbidden();

        $cierra = $this->h($this->staffWith([ValuationAccess::VIEW_ALL, ValuationAccess::COMPLETE]));
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 2], $cierra)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 3, 'reason' => 'Sin permiso'], $cierra)->assertForbidden();

        $cancela = $this->h($this->staffWith([ValuationAccess::VIEW_ALL, ValuationAccess::CANCEL]));
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 3, 'reason' => 'Con permiso'], $cancela)->assertOk();
    }

    public function test_disponibilidad_y_ajustes_con_sus_permisos(): void
    {
        $sinNada = $this->h($this->staffWith([ValuationAccess::VIEW_ALL]));
        $cuerpo = ['rules' => [['weekday' => 1, 'start_time' => '07:00', 'end_time' => '12:00']]];
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", $cuerpo, $sinNada)->assertForbidden();
        $this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", $sinNada)->assertForbidden();

        $gestor = $this->h($this->staffWith([ValuationAccess::MANAGE_AVAILABILITY]));
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", $cuerpo, $gestor)->assertOk();
        $this->getJson("/api/admin/valuations/trainers/{$this->sofia->id}/availability", $gestor)->assertOk();
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(), $gestor)->assertForbidden();

        $config = $this->h($this->staffWith([ValuationAccess::MANAGE_SETTINGS]));
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(['buffer_minutes' => 10]), $config)->assertOk()->assertJsonPath('data.buffer_minutes', 10);
        $this->getJson('/api/admin/valuations/settings', $config)->assertOk()->assertJsonPath('meta.can_edit', true);
        $this->getJson('/api/admin/valuations/settings', $sinNada)->assertOk()->assertJsonPath('meta.can_edit', false);
    }

    // ── Roles reales ───────────────────────────────────────────────────────

    public function test_recepcion_agenda_mueve_y_cancela_pero_no_cierra_ni_configura(): void
    {
        $h = $this->reception();
        $u = $this->book($h, $this->member('Mostrador'), $this->andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T11:00:00-05:00'], $h)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 2], $h)->assertForbidden();
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(), $h)->assertForbidden();
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => []], $h)->assertForbidden();
        $this->getJson('/api/admin/valuations/settings', $h)->assertOk()->assertJsonPath('meta.can_edit', false);
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 2, 'reason' => 'Lo pidió el socio'], $h)->assertOk();
    }

    public function test_administrador_todo_menos_ajustes_ni_agendar_en_el_pasado(): void
    {
        $h = $this->h($this->staff(Admin::ROLE_ADMINISTRADOR));
        $this->getJson('/api/admin/valuations/capabilities', $h)->assertOk()
            ->assertJsonPath('data.can.manage_settings', false)
            ->assertJsonPath('data.can.manage_availability', true);
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(), $h)->assertForbidden();
        $this->book($h, $this->member('Pasado'), $this->andres, '2026-10-19T07:00:00-05:00', ['allow_past' => true, 'past_reason' => 'Ya ocurrió y faltó registrarla'])
            ->assertStatus(422)->assertJsonPath('code', 'in_past');
    }

    public function test_administrativo_consulta_sin_operar(): void
    {
        $h = $this->h($this->staff(Admin::ROLE_ADMINISTRATIVO));
        $this->assertCount(2, $this->getJson('/api/admin/valuations?'.$this->rango(), $h)->assertOk()->json('data'));
        $this->book($h, $this->member('X'), $this->andres, '2026-10-24T09:00:00-05:00')->assertForbidden();
    }

    // ── Entrenador vinculado: solo lo suyo (IDOR) ──────────────────────────

    public function test_entrenador_vinculado_solo_ve_y_cierra_sus_citas(): void
    {
        $cuenta = $this->staff(CrmPermission::ROLE_ENTRENADOR, $this->andres, 'Andrés');
        $h = $this->h($cuenta);

        $this->getJson('/api/admin/valuations/capabilities', $h)->assertOk()->assertJsonPath('data.own_trainer_id', $this->andres->id);
        $lista = $this->getJson('/api/admin/valuations?'.$this->rango(), $h)->assertOk()->json('data');
        $this->assertSame([$this->deAndres], array_column($lista, 'uuid'));
        // Pedir la agenda de otro entrenador por filtro: 403, no la lista de otro.
        $this->getJson('/api/admin/valuations?trainer_id='.$this->sofia->id, $h)->assertForbidden();
        $this->assertSame([$this->deAndres], array_column($this->getJson('/api/admin/valuations?member_id='.$this->laura->id, $h)->json('data'), 'uuid'));

        // IDOR: el uuid de la cita de Sofía no le sirve de nada.
        $this->getJson("/api/admin/valuations/{$this->deSofia}", $h)->assertStatus(404);
        $this->clock('2026-10-19 10:05');
        $h = $this->h($cuenta);
        $this->postJson("/api/admin/valuations/{$this->deSofia}/complete", ['expected_version' => 1], $h)->assertStatus(404);
        $this->postJson("/api/admin/valuations/{$this->deSofia}/no-show", ['expected_version' => 1], $h)->assertStatus(404);
        $this->assertSame('scheduled', $this->appointment($this->deSofia)->status);

        // La suya sí, aunque el socio NO le esté asignado (no se usa el alcance de socios).
        $d = $this->getJson("/api/admin/valuations/{$this->deAndres}", $h)->assertOk()->json('data');
        $this->assertSame('Laura Gómez', $d['member']['name']);
        $this->assertSame('Nota de recepción', $d['notes'], 'las notas de su propia cita');
        $this->postJson("/api/admin/valuations/{$this->deAndres}/complete", ['expected_version' => 1], $h)->assertOk()->assertJsonPath('data.status', 'completed');

        // Huecos y entrenadores: solo los suyos.
        $this->getJson("/api/admin/valuations/slots?trainer_id={$this->sofia->id}&date=2026-10-24", $h)->assertForbidden();
        $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $h)->assertOk();
        $this->assertSame([$this->andres->id], array_column($this->getJson('/api/admin/valuations/trainers', $h)->json('data'), 'id'));

        // Su disponibilidad la ve; la de otro no; no la edita.
        $this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", $h)->assertOk();
        $this->getJson("/api/admin/valuations/trainers/{$this->sofia->id}/availability", $h)->assertForbidden();
        $this->putJson("/api/admin/valuations/trainers/{$this->andres->id}/availability", ['rules' => []], $h)->assertForbidden();
    }

    public function test_entrenador_con_create_solo_agenda_con_su_entrenador(): void
    {
        $h = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::CREATE, ValuationAccess::UPDATE], $this->andres));
        $socio = $this->member('Asignado');
        $this->assign($socio, $this->andres);

        $this->book($h, $socio, $this->sofia, '2026-10-24T09:00:00-05:00')->assertForbidden();
        $u = $this->book($h, $socio, $this->andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T11:00:00-05:00', 'trainer_id' => $this->sofia->id], $h)
            ->assertForbidden();
        $this->assertSame($this->andres->id, $this->appointment($u)->trainer_id);
    }

    public function test_entrenador_sin_trainer_id_no_ve_nada(): void
    {
        $h = $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, null));
        $this->getJson('/api/admin/valuations?'.$this->rango(), $h)->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/admin/valuations/{$this->deAndres}", $h)->assertForbidden();
        $this->getJson('/api/admin/valuations/trainers', $h)->assertForbidden();
        $this->postJson("/api/admin/valuations/{$this->deAndres}/confirm", ['expected_version' => 1], $h)->assertForbidden();
    }

    public function test_una_cita_que_paso_a_otro_entrenador_deja_de_ser_suya(): void
    {
        // Recepción la pasó a Sofía: Andrés ya no puede cerrarla aunque la
        // tuviera abierta en pantalla.
        // (La de Sofía a la misma hora se cancela antes: en PostgreSQL la
        // restricción EXCLUDE impediría, con razón, dos activas a la vez.)
        $this->appointment($this->deSofia)->forceFill(['status' => 'cancelled'])->save();
        $cita = $this->appointment($this->deAndres);
        $cita->forceFill(['trainer_id' => $this->sofia->id])->save();
        $this->clock('2026-10-19 10:05');
        $h = $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $this->andres));
        $this->postJson("/api/admin/valuations/{$this->deAndres}/complete", ['expected_version' => 1], $h)->assertStatus(404);
        $this->assertSame('scheduled', $cita->fresh()->status);
    }

    // ── Búsqueda de socios ─────────────────────────────────────────────────

    public function test_busqueda_de_socios_minima_y_sin_contacto(): void
    {
        $h = $this->reception();
        $this->assign($this->laura, $this->andres);
        $this->member('Laureano Díaz', null);
        $socio = $this->member('Ana Torres', '2026-09-30', ['document_number' => '1075987654']);

        $r = $this->getJson('/api/admin/valuations/members/search?q=LAUR', $h)->assertOk()->json('data');
        $this->assertSame(['Laura Gómez', 'Laureano Díaz'], array_column($r, 'name'));
        $this->assertSame(['id', 'name', 'document_tail', 'membership_status', 'membership_label', 'assigned_trainer'], array_keys($r[0]));
        $this->assertSame(['id' => $this->andres->id, 'name' => 'Andrés Pérez'], $r[0]['assigned_trainer']);
        $this->assertSame('none', $r[1]['membership_status']);
        $this->assertNull($r[1]['assigned_trainer']);

        $json = json_encode($r);
        $this->assertStringNotContainsString($this->laura->phone, $json);
        $this->assertStringNotContainsString($this->laura->email, $json);
        $this->assertStringNotContainsString($this->laura->document_number, $json);

        $porDoc = $this->getJson('/api/admin/valuations/members/search?q=1075.987', $h)->assertOk()->json('data');
        $this->assertSame([$socio->id], array_column($porDoc, 'id'));
        $this->assertSame('7654', $porDoc[0]['document_tail']);
        $this->assertSame('expired', $porDoc[0]['membership_status']);
        $this->assertSame('Vencida', $porDoc[0]['membership_label']);

        $this->getJson('/api/admin/valuations/members/search?q=a', $h)->assertStatus(422);
        $this->getJson('/api/admin/valuations/members/search?q=%25', $h)->assertStatus(422);
        $this->assertSame([], $this->getJson('/api/admin/valuations/members/search?q=%25%25', $h)->assertOk()->json('data'), 'los comodines se escapan');
    }

    public function test_mapa_de_user_a_member(): void
    {
        $h = $this->reception();
        $this->getJson('/api/admin/valuations/members/by-user/'.$this->laura->user_id, $h)->assertOk()->assertJsonPath('data.member_id', $this->laura->id);
        $this->getJson('/api/admin/valuations/members/by-user/999999', $h)->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    private function ajustesValidos(array $cambios = []): array
    {
        return array_merge([
            'default_duration_minutes' => 60, 'slot_interval_minutes' => 30, 'buffer_minutes' => 0,
            'min_notice_minutes' => 0, 'max_horizon_days' => 90, 'block_on_class_conflict' => true,
            'confirmation_enabled' => true, 'reminder_long_enabled' => true, 'reminder_long_hours' => 24,
            'reminder_short_enabled' => true, 'reminder_short_minutes' => 60, 'internal_alert_enabled' => true,
            'internal_alert_minutes' => 15, 'internal_unattended_enabled' => true, 'member_app_enabled' => false,
        ], $cambios);
    }

    public function test_ajustes_se_validan_enteros(): void
    {
        $h = $this->h($this->superAdmin());
        $this->putJson('/api/admin/valuations/settings', ['buffer_minutes' => 10], $h)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(['slot_interval_minutes' => 20]), $h)->assertStatus(422);
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(['default_duration_minutes' => 47]), $h)->assertStatus(422);
        $r = $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(['reminder_long_hours' => 1, 'reminder_short_minutes' => 60]), $h)->assertStatus(422);
        $this->assertArrayHasKey('reminder_short_minutes', $r->json('errors'));
        $this->putJson('/api/admin/valuations/settings', $this->ajustesValidos(['member_app_enabled' => true, 'max_horizon_days' => 30]), $h)->assertOk()
            ->assertJsonPath('data.member_app_enabled', true)->assertJsonPath('data.max_horizon_days', 30);
        $this->getJson('/api/admin/valuations/capabilities', $h)->assertJsonPath('data.channels.member_app.enabled', true)
            ->assertJsonPath('data.settings.max_horizon_days', 30);
        $this->assertSame(1, AuditLog::where('entity', 'ajustes_valoraciones')->count());
        $this->assertSame(1, ValuationSetting::count());
        $this->assertNotNull(ValuationAppointment::first());
    }
}
