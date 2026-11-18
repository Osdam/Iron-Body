<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Services\Valuations\ValuationAccess;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Quién lee y quién cambia el seguimiento (contrato §3): el alcance de la
 * agenda, probado como IDOR. Un entrenador vinculado no ve ni cambia el
 * seguimiento de un socio que no tiene asignado aunque conozca su id (404, no
 * 403: no se confirma que exista). Sin permiso, 403. El token compartido de
 * automatizaciones, nada.
 */
class FollowUpAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeguimientoFixtures;

    private Trainer $andres;

    private Trainer $sofia;

    private Member $suyo;

    private Member $ajeno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-18 12:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->sofia = $this->trainer('Sofía Ríos');
        $this->suyo = $this->member('Laura Gómez');
        $this->ajeno = $this->member('Pedro Ruiz');
        $this->assign($this->suyo, $this->andres);
        $this->assign($this->ajeno, $this->sofia);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_las_rutas_exigen_leer_la_agenda_o_editarla(): void
    {
        $permisos = [];
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/admin/valuations/members/') && ! str_contains($r->uri(), 'by-user')) {
                $permisos[AuthorizationMap::routeKey($r)] = AuthorizationMap::resolve($r);
            }
        }
        $this->assertSame([
            'GET api/admin/valuations/members/search' => AuthorizationMap::VALUATIONS_READ,
            'GET api/admin/valuations/members/{memberId}/follow-up' => AuthorizationMap::VALUATIONS_READ,
            'PUT api/admin/valuations/members/{memberId}/follow-up' => 'valuations.update',
        ], $permisos);
    }

    public function test_el_token_compartido_no_lee_ni_escribe(): void
    {
        $t = $this->sharedTokenHeaders();
        $this->seguimiento($t, $this->suyo)->assertForbidden();
        $this->cambiar($t, $this->suyo, ['interval_days' => 30])->assertForbidden();
        $this->assertDatabaseCount('member_valuation_follow_ups', 0);
    }

    public function test_sin_permiso_403(): void
    {
        // Solo agendar: no lee el seguimiento.
        $this->seguimiento($this->h($this->staffWith([ValuationAccess::CREATE])), $this->suyo)->assertForbidden();
        // `view` sin entrenador vinculado: sin alcance ninguno.
        $this->seguimiento($this->h($this->staffWith([ValuationAccess::VIEW])), $this->suyo)
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        // Lee todo pero no edita.
        $lector = $this->h($this->staffWith([ValuationAccess::VIEW_ALL]));
        $this->seguimiento($lector, $this->suyo)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->cambiar($lector, $this->suyo, ['interval_days' => 30])->assertForbidden();
        // Administrativo (ver todo, por defecto) tampoco edita.
        $this->cambiar($this->h($this->staff(Admin::ROLE_ADMINISTRATIVO)), $this->suyo, ['interval_days' => 30])->assertForbidden();
        $this->assertDatabaseCount('member_valuation_follow_ups', 0);
    }

    public function test_entrenador_vinculado_solo_sus_socios_asignados(): void
    {
        $entrenador = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::UPDATE], $this->andres));

        $this->seguimiento($entrenador, $this->suyo)->assertOk()->assertJsonPath('data.member.id', $this->suyo->id);
        $this->cambiar($entrenador, $this->suyo, ['interval_days' => 30])->assertOk();

        // IDOR: el socio de otro entrenador, por id.
        $this->seguimiento($entrenador, $this->ajeno)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->cambiar($entrenador, $this->ajeno, ['interval_days' => 30])->assertNotFound();
        $this->assertDatabaseMissing('member_valuation_follow_ups', ['member_id' => $this->ajeno->id]);

        // Una asignación terminada ya no es llave.
        MemberTrainerAssignment::query()->where('member_id', $this->suyo->id)->update(['status' => 'ended']);
        $this->seguimiento($entrenador, $this->suyo)->assertNotFound();
    }

    public function test_entrenador_vinculado_busca_solo_sus_socios_y_no_ve_citas_ajenas(): void
    {
        $entrenador = $this->h($this->staffWith([ValuationAccess::VIEW], $this->andres));

        // Buscador: el socio de otro entrenador no aparece; el suyo sí.
        $this->getJson('/api/admin/valuations/members/search?q=Pedro', $entrenador)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/valuations/members/search?q=Laura', $entrenador)->assertOk()
            ->assertJsonPath('data.0.id', $this->suyo->id);

        // Su socio tiene una cita futura con OTRA entrenadora: ni quién ni el enlace.
        $this->cita($this->suyo, $this->sofia, '2026-11-25 10:00', ValuationAppointment::STATUS_SCHEDULED);
        $vista = $this->seguimiento($entrenador, $this->suyo)->assertOk();
        $vista->assertJsonPath('data.next_appointment', null);
        $fila = collect($vista->json('data.history'))->firstWhere('kind', 'appointment');
        $this->assertSame([null, null, 'scheduled'], [$fila['trainer'], $fila['appointment_uuid'], $fila['status']]);

        // Quien ve toda la agenda, sí.
        $todo = $this->h($this->staffWith([ValuationAccess::VIEW_ALL], null));
        $this->seguimiento($todo, $this->suyo)->assertOk()
            ->assertJsonPath('data.next_appointment.trainer.name', 'Sofía Ríos');
    }

    public function test_rol_entrenador_real_con_vinculo(): void
    {
        // El rol Entrenador por defecto: `view` + `complete`, sin `update`.
        $h = $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $this->andres));
        $this->seguimiento($h, $this->suyo)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->seguimiento($h, $this->ajeno)->assertNotFound();
        $this->cambiar($h, $this->suyo, ['interval_days' => 30])->assertForbidden();
    }

    public function test_view_all_ve_a_cualquiera_y_404_si_no_existe_o_esta_anonimizado(): void
    {
        $h = $this->reception();
        $this->seguimiento($h, $this->ajeno)->assertOk();
        $this->getJson('/api/admin/valuations/members/999999/follow-up', $h)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->putJson('/api/admin/valuations/members/999999/follow-up', [
            'interval_days' => 30, 'enabled' => true, 'due_on' => null, 'expected_version' => 0,
        ], $h)->assertNotFound();

        $this->ajeno->forceFill(['anonymized_at' => now()])->save();
        $this->seguimiento($h, $this->ajeno)->assertNotFound();
        // Id no numérico: la ruta no existe.
        $this->getJson('/api/admin/valuations/members/abc/follow-up', $h)->assertNotFound();
    }
}
