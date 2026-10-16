<?php

namespace Tests\Feature\Workforce;

use App\Models\EmployeeAccess;
use App\Models\Identity;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workforce\Concerns\MemberKit;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * La ficha laboral de UNA persona y la comprobación previa del alta
 * (objetivo A, 2026-10-15). Casos 1-3, 7, 8, 11, 14, 16-19 del plan.
 *
 * Lo que se fija: el alta de una socia que aún no tiene identidad (Yuri) no
 * toca su ficha de socio; la ficha no sirve para recorrer socios; la membresía
 * pagada, el acceso gratuito y Story Live solo se enseñan con la ficha de
 * socio VINCULADA y con `members.view`.
 */
class StaffRecordTest extends TestCase
{
    use MemberKit;
    use RefreshDatabase;
    use WorkforceKit;

    private const YURI = '1075000777';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-10-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    private function quienVeTodo(): array
    {
        return $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view']);
    }

    /** Ficha de socio ya colgada de la identidad (estado de datos, no del flujo). */
    private function vincularComoFixture(Member $member, Identity $identity): Member
    {
        $member->forceFill(['identity_id' => $identity->id])->save();

        return $member->refresh();
    }

    // ── 1, 19: la socia que empieza a trabajar aquí (Yuri) ─────────────────

    public function test_el_alta_de_una_socia_sin_identidad_crea_la_identidad_y_no_toca_su_ficha_de_socio(): void
    {
        $yuri = $this->socio(self::YURI);
        $this->conPagoYAccesoGratuito($yuri);
        $antes = $this->intocable($yuri);
        $this->assertSame(0, Identity::count());

        $escrituras = [];
        DB::listen(function ($q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*"(members|users|payments|employee_accesses)"/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });
        $admin = $this->quienVeTodo();

        // Comprobar: es socia, sin vincular, sin perfil laboral. Y no se crea nada.
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1.075.000.777'], $admin)
            ->assertOk()
            ->assertJsonPath('data.identity_id', null)
            ->assertJsonPath('data.staff_profile', null)
            ->assertJsonPath('data.trainer', null)
            ->assertJsonPath('data.member.match', true)
            ->assertJsonPath('data.member.linked', false)
            ->assertJsonPath('data.member.full_name', 'Yuri Andrea Socia');
        $this->assertSame(0, Identity::count(), 'La comprobación previa no crea identidades.');

        $uuid = $this->postJson('/api/admin/workforce/staff', [
            'full_name' => 'Yuri Andrea', 'document' => '1.075.000.777', 'phone' => '+57 311 999 8877', 'positions' => ['administrative'],
        ], $admin)
            ->assertCreated()
            ->assertJsonPath('data.positions.0.key', 'administrative')
            ->assertJsonPath('data.capabilities.workday', true)
            ->json('data.identity_id');

        $this->assertSame(1, Identity::count());
        $this->assertNull($yuri->refresh()->identity_id, 'El alta no vincula la ficha de socio.');

        // La ficha la ve como socia por documento, SIN vincular: nada de su
        // membresía ni de su acceso gratuito hasta que se verifique.
        $this->getJson("/api/admin/workforce/staff/{$uuid}", $admin)
            ->assertOk()
            ->assertJsonPath('data.identity_id', $uuid)
            ->assertJsonPath('data.staff.status', 'active')
            ->assertJsonPath('data.trainer', null)
            ->assertJsonPath('data.phone', '+57 311 999 8877')
            ->assertJsonPath('data.member.link', 'unlinked')
            ->assertJsonPath('data.member.link_blocked_reason', null)
            ->assertJsonPath('data.member.can_verify', true)
            ->assertJsonPath('data.member.member_id', $yuri->id)
            ->assertJsonPath('data.member.user_id', $yuri->user_id)
            ->assertJsonPath('data.member.phone_masked', '•••• 1234')
            ->assertJsonPath('data.member.membership', null)
            ->assertJsonPath('data.member.story_live', null)
            ->assertJsonPath('data.member.employee_access', null);

        $this->assertSame($antes, $this->intocable($yuri), 'Ni ficha de socio, ni usuario, ni pagos, ni acceso gratuito, ni is_staff.');
        $this->assertSame([], $escrituras, 'Ninguna escritura sobre socios, usuarios, pagos ni accesos.');
    }

    // ── 2: perfil ausente ───────────────────────────────────────────────────

    public function test_la_ficha_no_sirve_para_recorrer_identidades_de_socios(): void
    {
        $soloSocio = app(IdentityLinkService::class)->ensureIdentity('1075000555');
        $this->vincularComoFixture($this->socio('1075000555'), $soloSocio);
        $admin = $this->quienVeTodo();

        $deSocio = $this->getJson("/api/admin/workforce/staff/{$soloSocio->uuid}", $admin)
            ->assertNotFound()->assertJsonPath('code', 'employee_not_found');
        $inexistente = $this->getJson('/api/admin/workforce/staff/'.Str::uuid(), $admin)
            ->assertNotFound()->assertJsonPath('code', 'employee_not_found');

        // Misma respuesta: no se distingue «es socio» de «no existe».
        $this->assertSame($inexistente->json(), $deSocio->json());
    }

    public function test_la_comprobacion_previa_responde_lo_que_hay_sin_crear_nada(): void
    {
        $admin = $this->quienVeTodo();

        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075009999'], $admin)
            ->assertOk()
            ->assertExactJson(['ok' => true, 'data' => [
                'identity_id' => null, 'full_name' => null, 'staff_profile' => null, 'trainer' => null, 'member' => null,
            ]]);
        $this->assertSame(0, Identity::count());

        $ana = $this->receptionist('1075000001', 'Ana María Rojas');
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000001'], $admin)
            ->assertJsonPath('data.identity_id', $ana->uuid)
            ->assertJsonPath('data.full_name', 'Ana María Rojas')
            ->assertJsonPath('data.staff_profile.status', 'active')
            ->assertJsonPath('data.member', null);

        $coach = $this->floorTrainer('1075000002');
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000002'], $admin)
            ->assertJsonPath('data.identity_id', $coach->identity->uuid)
            ->assertJsonPath('data.staff_profile', null)
            ->assertJsonPath('data.trainer.status', 'active')
            ->assertJsonPath('data.trainer.positions', [['key' => 'trainer_floor', 'label' => 'Entrenador de planta']]);

        // Socia ya vinculada a su identidad: `linked`.
        $marta = app(IdentityLinkService::class)->ensureIdentity('1075000556');
        $this->vincularComoFixture($this->socio('1075000556', ['full_name' => 'Marta Vinculada']), $marta);
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000556'], $admin)
            ->assertJsonPath('data.identity_id', $marta->uuid)
            ->assertJsonPath('data.member.linked', true)
            ->assertJsonPath('data.member.full_name', 'Marta Vinculada');
        // Sin `members.view`, de la ficha de socio no se dice nada.
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000556'], $this->adminWithPermissions(['workforce.manage']))
            ->assertJsonPath('data.member', null);

        $this->assertSame(3, Identity::count(), 'Ninguna comprobación creó identidades.');
    }

    public function test_sin_permiso_de_miembros_la_comprobacion_no_revela_que_un_documento_es_de_un_socio(): void
    {
        // B4: una identidad que solo es de un socio no se nombra a quien no
        // puede ver socios; se le responde como a una persona nueva.
        $soloSocio = app(IdentityLinkService::class)->ensureIdentity('1075000556');
        $this->vincularComoFixture($this->socio('1075000556'), $soloSocio);
        $ana = $this->receptionist('1075000001');
        $sinMiembros = $this->adminWithPermissions(['workforce.manage']);
        $nueva = ['ok' => true, 'data' => [
            'identity_id' => null, 'full_name' => null, 'staff_profile' => null, 'trainer' => null, 'member' => null,
        ]];

        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000556'], $sinMiembros)
            ->assertOk()->assertExactJson($nueva);
        // Misma respuesta que un documento que no existe.
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075009999'], $sinMiembros)
            ->assertOk()->assertExactJson($nueva);

        // Con `members.view` sí, y a quien ya es del personal se le nombra siempre.
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000556'], $this->adminWithPermissions(['workforce.manage', 'members.view']))
            ->assertJsonPath('data.identity_id', $soloSocio->uuid)
            ->assertJsonPath('data.member.linked', true);
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000001'], $sinMiembros)
            ->assertJsonPath('data.identity_id', $ana->uuid);

        // El alta sin `identity_id` sigue como hoy: enlaza por documento.
        $this->postJson('/api/admin/workforce/staff', ['full_name' => 'Marta Socia', 'document' => '1075000556', 'positions' => ['receptionist']], $sinMiembros)
            ->assertCreated()->assertJsonPath('data.identity_id', $soloSocio->uuid);
        $this->assertSame(2, Identity::count());
    }

    // ── 3: alta segura ──────────────────────────────────────────────────────

    public function test_el_alta_con_una_identidad_que_no_coincide_no_crea_nada(): void
    {
        $admin = $this->adminWithPermissions(['workforce.manage']);
        $cuerpo = fn (string $doc, ?string $identity) => [
            'full_name' => 'Lina Lozano', 'document' => $doc, 'positions' => ['receptionist'], 'identity_id' => $identity,
        ];

        // Persona nueva, pero el CRM dice haber visto a alguien.
        $this->postJson('/api/admin/workforce/staff', $cuerpo('1075000901', (string) Str::uuid()), $admin)
            ->assertStatus(409)->assertJsonPath('code', 'identity_mismatch');
        $this->assertSame(0, Identity::count());
        $this->assertSame(0, StaffProfile::count());

        // El documento es de otra identidad que la comprobada.
        $lina = app(IdentityLinkService::class)->ensureIdentity('1075000902');
        $otra = app(IdentityLinkService::class)->ensureIdentity('1075000903');
        $this->postJson('/api/admin/workforce/staff', $cuerpo('1075000902', $otra->uuid), $admin)
            ->assertStatus(409)->assertJsonPath('code', 'identity_mismatch');
        $this->assertSame(0, StaffProfile::count());
        $this->assertSame(2, Identity::count());

        // La que se comprobó: enlaza con ella.
        $this->postJson('/api/admin/workforce/staff', $cuerpo('1.075.000.902', $lina->uuid), $admin)
            ->assertCreated()->assertJsonPath('data.identity_id', $lina->uuid);
        $this->assertSame(2, Identity::count());

        // Sin `identity_id`, el alta de siempre (AdminWorkforceTest fija el enlace).
        $this->postJson('/api/admin/workforce/staff', $cuerpo('1075000904', null), $admin)->assertCreated();
        $this->assertSame(3, Identity::count());
    }

    // ── 7, 8: entrenadores ──────────────────────────────────────────────────

    public function test_la_ficha_del_entrenador_de_planta_trae_su_bloque_y_no_se_edita_como_personal(): void
    {
        $coach = $this->floorTrainer('1075000002');

        $this->getJson("/api/admin/workforce/staff/{$coach->identity->uuid}", $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('data.staff_profile', false)
            ->assertJsonPath('data.staff', null)
            ->assertJsonPath('data.trainer_id', $coach->id)
            ->assertJsonPath('data.trainer.id', $coach->id)
            ->assertJsonPath('data.trainer.status', 'active')
            ->assertJsonPath('data.trainer.positions', [['key' => 'trainer_floor', 'label' => 'Entrenador de planta']])
            ->assertJsonPath('data.capabilities', ['workday' => true, 'classes' => false])
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.member', null);
    }

    public function test_el_entrenador_funcional_dicta_clases_y_no_registra_jornada(): void
    {
        $coach = $this->trainerPerson('1075000003', [TrainerRole::FUNCTIONAL]);

        $this->getJson("/api/admin/workforce/staff/{$coach->identity->uuid}", $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('data.capabilities', ['workday' => false, 'classes' => true])
            ->assertJsonPath('data.trainer.positions.0.key', 'trainer_functional');
    }

    // ── 11: inactivo ────────────────────────────────────────────────────────

    public function test_desactivar_corta_kiosko_y_respaldo_pero_no_el_acceso_gratuito(): void
    {
        $ana = $this->receptionist('1075000001');
        $socia = $this->vincularComoFixture($this->socio('1075000001', ['full_name' => 'Ana María Rojas']), $ana);
        $this->conPagoYAccesoGratuito($socia);
        $tablet = $this->tablet();
        $this->enroll($ana, '1075000001', $this->face(1), $tablet)->assertCreated();
        $verificada = $this->verifyFace('1075000001', $this->face(1), $tablet)->json('verification.token');
        $admin = $this->adminWithPermissions(['workforce.view', 'workforce.manage', 'members.view']);

        $this->patchJson("/api/admin/workforce/staff/{$ana->uuid}", ['status' => 'inactive'], $admin)->assertOk();

        // Una verificación de antes de desactivar ya no abre jornada.
        $this->clock('in', $verificada, $tablet)->assertStatus(403)->assertJsonPath('code', 'workday_not_applicable');
        $this->identify('1075000001', $tablet)->assertNotFound()->assertJsonPath('code', 'employee_not_found');
        $this->postJson("/api/admin/workforce/staff/{$ana->uuid}/fallback-code", ['purpose' => 'workday', 'reason' => 'La cámara falla'], $admin)
            ->assertNotFound()->assertJsonPath('code', 'employee_not_found');

        // La ficha sigue abriéndose y el acceso gratuito sigue como estaba.
        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $admin)
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.staff.status', 'inactive')
            ->assertJsonPath('data.staff.deactivated_at', now()->toIso8601String())
            ->assertJsonPath('data.member.link', 'linked')
            ->assertJsonPath('data.member.employee_access.source', 'manual')
            ->assertJsonPath('data.member.employee_access.active', true)
            ->assertJsonPath('data.member.employee_access.current', true);
        $acceso = EmployeeAccess::query()->where('user_id', $socia->user_id)->sole();
        $this->assertTrue($acceso->active);
        $this->assertNull($acceso->revoked_at);
    }

    // ── 14: jornada en la ficha ─────────────────────────────────────────────

    public function test_la_ficha_dice_si_esta_en_jornada_y_si_dejo_una_abierta_de_mas(): void
    {
        $ana = $this->receptionist('1075000001');
        $tablet = $this->tablet();
        $this->clock('in', $this->enroll($ana, '1075000001', $this->face(1), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $admin = $this->adminWithPermissions(['workforce.view']);

        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $admin)
            ->assertOk()
            ->assertJsonPath('data.workday.open', true)
            ->assertJsonPath('data.workday.since', now()->toIso8601String())
            ->assertJsonPath('data.workday_state.state', 'open')
            ->assertJsonPath('data.workday_state.current.status', 'open')
            ->assertJsonPath('data.workday_state.current.position.key', 'receptionist')
            ->assertJsonPath('data.workday_state.pending_incident', null);

        // Más de 16 h abierta: no está «en jornada», es una incidencia pendiente.
        // (Sesión nueva: la de administrador caduca a las 12 h.)
        $this->reloj('2026-10-16 02:00');
        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('data.workday_state.state', 'none')
            ->assertJsonPath('data.workday_state.current', null)
            ->assertJsonPath('data.workday_state.pending_incident.business_date', '2026-10-15');
    }

    // ── 16, 17: acceso gratuito y comisiones ────────────────────────────────

    public function test_el_acceso_gratuito_solo_se_ve_con_la_ficha_vinculada_y_permiso_de_miembros(): void
    {
        $ana = $this->receptionist('1075000001');
        $socia = $this->vincularComoFixture($this->socio('1075000001', ['full_name' => 'Ana María Rojas', 'is_staff' => true]), $ana);
        $this->conPagoYAccesoGratuito($socia);

        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $this->adminWithPermissions(['workforce.view']))
            ->assertOk()->assertJsonPath('data.member', null);

        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $this->adminWithPermissions(['workforce.view', 'members.view']))
            ->assertOk()
            ->assertJsonPath('data.member.link', 'linked')
            ->assertJsonPath('data.member.can_verify', false)
            ->assertJsonPath('data.member.user_id', $socia->user_id)
            ->assertJsonPath('data.member.membership', ['plan' => 'INGRESO EMPLEADOS', 'end_date' => '2027-09-18'])
            ->assertJsonPath('data.member.story_live', true)
            ->assertJsonPath('data.member.employee_access.source', 'manual')
            ->assertJsonPath('data.member.employee_access.position', 'Administrativa')
            ->assertJsonPath('data.member.employee_access.granted_by_name', 'Dueño Iron Body')
            ->assertJsonPath('data.member.employee_access.open_now', true)
            // Teléfono del perfil laboral: solo para quien edita.
            ->assertJsonPath('data.phone', null);

        // Los mismos campos que la pantalla de Miembros.
        $miembros = $this->getJson("/api/users/{$socia->user_id}/employee-access", $this->adminWithPermissions(['members.view']))
            ->assertOk()->json('access.employee');
        $ficha = $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $this->adminWithPermissions(['workforce.view', 'members.view']))
            ->json('data.member.employee_access');
        unset($ficha['source']);
        $this->assertSame($miembros, $ficha);
    }

    public function test_ni_el_alta_ni_la_jornada_crean_o_apagan_el_acceso_gratuito(): void
    {
        $yuri = $this->socio(self::YURI);
        $acceso = $this->conPagoYAccesoGratuito($yuri);
        $sinAcceso = $this->socio('1075000888', ['full_name' => 'Sin Acceso'], ['name' => 'Sin Acceso']);
        $admin = $this->adminWithPermissions(['workforce.manage']);

        $uuid = $this->postJson('/api/admin/workforce/staff', ['full_name' => 'Yuri Andrea', 'document' => self::YURI, 'positions' => ['administrative']], $admin)
            ->assertCreated()->json('data.identity_id');
        $this->postJson('/api/admin/workforce/staff', ['full_name' => 'Sin Acceso', 'document' => '1075000888', 'positions' => ['maintenance']], $admin)
            ->assertCreated();

        $tablet = $this->tablet();
        $identity = Identity::query()->where('uuid', $uuid)->firstOrFail();
        $this->clock('in', $this->enroll($identity, self::YURI, $this->face(3), $tablet)->json('verification.token'), $tablet)->assertCreated();
        $this->reloj('2026-10-15 17:00');
        $this->clock('out', $this->verifyFace(self::YURI, $this->face(3), $tablet)->json('verification.token'), $tablet)->assertOk();

        $this->assertSame(1, EmployeeAccess::count(), 'Nadie recibió acceso gratuito por darse de alta o fichar.');
        $this->assertSame($acceso->only(['id', 'active', 'source', 'position']), $acceso->fresh()->only(['id', 'active', 'source', 'position']));
        $this->assertNull(EmployeeAccess::query()->where('user_id', $sinAcceso->user_id)->first());
    }

    public function test_el_acceso_abierto_por_comisiones_dice_de_donde_sale_y_el_entrenador_no_se_vincula(): void
    {
        // Entrenador cuya ficha de socio ya cuelga de su identidad (backfill).
        $coach = $this->floorTrainer('1075000020');
        $suya = $this->vincularComoFixture($this->socio('1075000020', ['full_name' => 'Coach Carlos Pérez']), $coach->identity);
        EmployeeAccess::create(['user_id' => $suya->user_id, 'position' => 'Entrenador', 'source' => 'commission', 'active' => true, 'ends_on' => '2026-11-14']);
        $admin = $this->adminWithPermissions(['workforce.view', 'members.view']);

        $this->getJson("/api/admin/workforce/staff/{$coach->identity->uuid}", $admin)
            ->assertOk()
            ->assertJsonPath('data.member.link', 'linked')
            ->assertJsonPath('data.member.employee_access.source', 'commission')
            ->assertJsonPath('data.member.employee_access.ends_on', '2026-11-14');

        // Entrenador con una ficha de socio del mismo documento SIN vincular:
        // se ve, pero esta fase no lo vincula.
        $otro = $this->trainerPerson('1075000021', [TrainerRole::FLOOR], 'Laura Méndez');
        $this->socio('1075000021', ['full_name' => 'Laura Méndez']);
        $this->getJson("/api/admin/workforce/staff/{$otro->identity->uuid}", $admin)
            ->assertJsonPath('data.member.link', 'unlinked')
            ->assertJsonPath('data.member.link_blocked_reason', 'trainer_profile')
            ->assertJsonPath('data.member.can_verify', false)
            ->assertJsonPath('data.member.employee_access', null);
    }

    // ── 18: permisos ────────────────────────────────────────────────────────

    public function test_permisos_de_la_ficha_y_de_la_comprobacion(): void
    {
        $ana = $this->receptionist('1075000001');
        $socia = $this->socio('1075000001');
        $rutas = [
            ['GET', "/api/admin/workforce/staff/{$ana->uuid}", []],
            ['POST', '/api/admin/workforce/staff/lookup', ['document' => '1075000001']],
            ['POST', "/api/admin/workforce/staff/{$ana->uuid}/member-link", ['member_id' => $socia->id]],
            ['POST', "/api/admin/workforce/staff/{$ana->uuid}/member-link/confirm", ['member_id' => $socia->id, 'challenge_id' => (string) Str::uuid(), 'code' => '123456']],
        ];

        foreach ($rutas as [$metodo, $uri, $cuerpo]) {
            $this->json($metodo, $uri, $cuerpo, $this->adminAs('Recepción'))->assertForbidden();
            $this->json($metodo, $uri, $cuerpo, $this->sharedTokenHeaders())->assertForbidden();
        }

        // Ver la ficha es `workforce.view`; ver socios no la abre.
        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $this->adminWithPermissions(['members.view']))->assertForbidden();
        // Comprobar un documento es preparar un alta: con solo ver, no.
        $this->postJson('/api/admin/workforce/staff/lookup', ['document' => '1075000001'], $this->adminWithPermissions(['workforce.view', 'members.view']))->assertForbidden();
        $this->assertNull($socia->refresh()->identity_id);
    }

    public function test_el_token_compartido_no_recibe_ningun_permiso_de_personal(): void
    {
        // Guarda de readOnly(): filtra por el dominio que devuelve
        // PermissionCatalog::split, que corta por el ÚLTIMO punto. Un permiso
        // futuro `workforce.algo.view` tendría dominio `workforce.algo`, no
        // estaría en PERSONNEL_DOMAINS y llegaría al token compartido. Aquí se
        // compara el PRIMER segmento, que es el que no se puede colar.
        $this->assertSame('workforce.algo', PermissionCatalog::split('workforce.algo.view')[0], 'La trampa que esta guarda vigila.');

        $colados = array_values(array_filter(
            CrmPermission::readOnly(),
            fn (string $p) => in_array(explode('.', $p)[0], CrmPermission::PERSONNEL_DOMAINS, true),
        ));
        $this->assertSame([], $colados, 'El token compartido no puede leer personal, biometría ni dispositivos.');
    }

    // ── 19: Story Live ──────────────────────────────────────────────────────

    public function test_la_ficha_no_cambia_story_live(): void
    {
        $ana = $this->receptionist('1075000001');
        $socia = $this->vincularComoFixture($this->socio('1075000001', ['is_staff' => true]), $ana);
        $sinStory = $this->vincularComoFixture(
            $this->socio('1075000002', ['is_staff' => false]),
            $this->receptionist('1075000002', 'Pedro Gómez'),
        );
        $admin = $this->quienVeTodo();

        $this->getJson("/api/admin/workforce/staff/{$ana->uuid}", $admin)->assertJsonPath('data.member.story_live', true);
        $this->getJson("/api/admin/workforce/staff/{$sinStory->identity->uuid}", $admin)->assertJsonPath('data.member.story_live', false);

        $this->assertTrue((bool) $socia->refresh()->is_staff);
        $this->assertFalse((bool) $sinStory->refresh()->is_staff);
    }
}
