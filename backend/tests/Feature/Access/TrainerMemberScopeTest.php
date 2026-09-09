<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberRiskLock;
use App\Models\MemberTrainerAssignment;
use App\Models\PhysicalEvaluation;
use App\Models\RolePermission;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un entrenador solo alcanza a los socios que tiene asignados.
 *
 * EL FALLO QUE SE VIGILA
 * ----------------------
 * `members.view` es una llave de PUERTA: dice si se entra al módulo de socios,
 * no a QUIÉN se puede ver. Con el rol recién materializado, la cuenta de un
 * entrenador con ese permiso leía la ficha, la nutrición, las valoraciones, la
 * membresía y el riesgo de CUALQUIER socio del gimnasio con solo cambiar el
 * número de la URL. Bastaba conocer un `member_id`.
 *
 * Permiso y alcance son dos preguntas distintas y hacían falta las dos. Aquí se
 * fija la segunda, y se fija sobre el SERVIDOR: un filtro en Angular no es una
 * respuesta a «¿qué pasa si llamo a la API directamente?».
 *
 * Y en el otro sentido, igual de importante: Super Admin y Recepción NO cambian
 * de comportamiento. Un cambio de alcance que se lleva por delante al resto del
 * gimnasio no es una mejora de seguridad, es una avería.
 */
class TrainerMemberScopeTest extends TestCase
{
    use RefreshDatabase;

    private const ROL = CrmPermission::ROLE_ENTRENADOR;

    private Trainer $trainerA;

    private Trainer $trainerB;

    private Member $suyo;      // asignado al entrenador A

    private Member $ajeno;     // asignado al entrenador B

    private Admin $cuentaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trainerA = $this->trainer('Ana Entrenadora');
        $this->trainerB = $this->trainer('Beto Entrenador');

        $this->suyo = $this->member('Socio Propio', 'propio@ironbody.test');
        $this->ajeno = $this->member('Socio Ajeno', 'ajeno@ironbody.test');

        $this->assign($this->trainerA, $this->suyo);
        $this->assign($this->trainerB, $this->ajeno);

        $this->cuentaA = $this->adminTrainer($this->trainerA);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function trainer(string $nombre): Trainer
    {
        return Trainer::create([
            'full_name' => $nombre,
            'email' => 'coach-'.uniqid().'@ironbody.test',
            'status' => 'active',
        ]);
    }

    private function member(string $nombre, string $email): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);

        return Member::create([
            'full_name' => $nombre,
            'email' => $email,
            'document_number' => (string) random_int(10_000_000, 99_999_999),
            'phone' => '30'.random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
            'user_id' => $user->id,
        ]);
    }

    private function assign(
        Trainer $t,
        Member $m,
        string $status = MemberTrainerAssignment::STATUS_ACTIVE,
    ): MemberTrainerAssignment {
        return MemberTrainerAssignment::create([
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'status' => $status,
            'assigned_at' => now(),
        ]);
    }

    private function adminTrainer(?Trainer $t): Admin
    {
        return Admin::create([
            'name' => 'Cuenta '.uniqid(),
            'email' => 'scope-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => self::ROL,
            'trainer_id' => $t?->id,
            'status' => 'active',
        ]);
    }

    private function admin(string $rol): Admin
    {
        return Admin::create([
            'name' => 'Cuenta '.uniqid(),
            'email' => 'scope-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    // ── El vínculo es obligatorio para el rol Entrenador ────────────────────

    public function test_una_cuenta_de_entrenador_exige_vinculo_con_un_entrenador(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->postJson('/api/admin/users', [
            'name' => 'Sin vincular',
            'email' => 'sinvincular@ironbody.test',
            'role' => self::ROL,
        ], $h)->assertStatus(422)->assertJsonValidationErrors('trainer_id');

        $this->assertDatabaseMissing('admins', ['email' => 'sinvincular@ironbody.test']);
    }

    public function test_los_demas_roles_no_necesitan_vinculo(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        foreach ([Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRADOR] as $i => $rol) {
            $this->postJson('/api/admin/users', [
                'name' => 'Cuenta '.$i,
                'email' => "sinvinculo{$i}@ironbody.test",
                'role' => $rol,
            ], $h)->assertStatus(201);
        }
    }

    public function test_el_entrenador_vinculado_debe_existir(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->postJson('/api/admin/users', [
            'name' => 'Apunta a la nada',
            'email' => 'fantasma@ironbody.test',
            'role' => self::ROL,
            'trainer_id' => 999999,
        ], $h)->assertStatus(422)->assertJsonValidationErrors('trainer_id');
    }

    /** Dos cuentas para el mismo entrenador serían dos sesiones sin traza. */
    public function test_un_entrenador_no_puede_tener_dos_cuentas(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->postJson('/api/admin/users', [
            'name' => 'Segunda cuenta',
            'email' => 'segunda@ironbody.test',
            'role' => self::ROL,
            'trainer_id' => $this->trainerA->id, // ya lo tiene $this->cuentaA
        ], $h)->assertStatus(422)->assertJsonValidationErrors('trainer_id');
    }

    /**
     * Cambiar de rol suelta el vínculo. Dejarlo puesto crearía una asociación
     * huérfana que volvería a activarse sola si algún día se le devolviera el
     * rol de entrenador.
     */
    public function test_al_dejar_de_ser_entrenador_se_suelta_el_vinculo(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->patchJson("/api/admin/users/{$this->cuentaA->id}", [
            'role' => Admin::ROLE_RECEPCION,
        ], $h)->assertOk();

        $this->assertNull($this->cuentaA->fresh()->trainer_id);
    }

    // ── Acceso por id directo: el caso obligatorio ──────────────────────────

    public function test_el_entrenador_ve_la_ficha_del_socio_que_tiene_asignado(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->suyo->id}", $h)->assertOk();
    }

    /**
     * EL CASO QUE DEFINE ESTA FASE. Conocer el `member_id` no puede bastar.
     *
     * Responde 404, no 403: un 403 confirmaría que ese socio existe, y quien no
     * puede verlo tampoco debería poder averiguar que está ahí.
     */
    public function test_el_entrenador_no_alcanza_al_socio_de_otro_entrenador(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->ajeno->id}", $h)->assertStatus(404);
    }

    public function test_no_alcanza_la_nutricion_ajena(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->suyo->id}/nutrition", $h)->assertOk();
        $this->getJson("/api/admin/members/{$this->ajeno->id}/nutrition", $h)->assertStatus(404);
        $this->getJson("/api/admin/members/{$this->ajeno->id}/nutrition/recommendations", $h)
            ->assertStatus(404);
    }

    public function test_no_alcanza_las_valoraciones_ajenas(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->suyo->id}/physical-evaluations", $h)->assertOk();
        $this->getJson("/api/admin/members/{$this->ajeno->id}/physical-evaluations", $h)
            ->assertStatus(404);
    }

    /**
     * Ni por la puerta de atrás: la valoración se referencia por su PROPIO id,
     * sin que el socio aparezca en la URL.
     *
     * Editar valoraciones exige `members.edit`, que el entrenador no tiene por
     * defecto —ahí le pararía el permiso con un 403 y no se probaría nada—. Así
     * que aquí se le CONCEDE desde la pantalla de Roles, que es algo que el
     * gimnasio puede hacer perfectamente, y se comprueba lo que importa: con el
     * permiso puesto, sigue sin alcanzar la valoración de un socio ajeno.
     *
     * Es la separación entre las dos capas: el permiso dice si puede editar; el
     * alcance dice de quién.
     */
    public function test_no_alcanza_una_valoracion_ajena_por_su_id(): void
    {
        RolePermission::create([
            'role' => self::ROL,
            'permission' => 'members.edit',
            'granted' => true,
        ]);

        $propia = PhysicalEvaluation::create([
            'member_id' => $this->suyo->id,
            'trainer_id' => $this->trainerA->id,
            'weight_kg' => 70,
        ]);
        $ajena = PhysicalEvaluation::create([
            'member_id' => $this->ajeno->id,
            'trainer_id' => $this->trainerB->id,
            'weight_kg' => 80,
        ]);

        $h = $this->actingAsAdmin($this->cuentaA);

        // La suya sí: el permiso está concedido y el socio es suyo.
        $this->putJson("/api/admin/physical-evaluations/{$propia->id}", [
            'weight_kg' => 68,
        ], $h)->assertOk();
        $this->assertSame(68.0, (float) $propia->fresh()->weight_kg);

        // La ajena no, y ni siquiera se le confirma que exista.
        $this->putJson("/api/admin/physical-evaluations/{$ajena->id}", [
            'weight_kg' => 60,
        ], $h)->assertStatus(404);
        $this->assertSame(80.0, (float) $ajena->fresh()->weight_kg);
    }

    /**
     * Y sin ese permiso, ni la suya. El alcance no sustituye al permiso: son
     * dos preguntas, y hay que pasar las dos.
     */
    public function test_sin_permiso_de_edicion_no_edita_ni_la_suya(): void
    {
        $propia = PhysicalEvaluation::create([
            'member_id' => $this->suyo->id,
            'trainer_id' => $this->trainerA->id,
            'weight_kg' => 70,
        ]);

        $this->putJson("/api/admin/physical-evaluations/{$propia->id}", [
            'weight_kg' => 60,
        ], $this->actingAsAdmin($this->cuentaA))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'members.edit');
    }

    public function test_no_alcanza_la_membresia_ajena(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/memberships/{$this->suyo->id}", $h)->assertOk();
        $this->getJson("/api/admin/memberships/{$this->ajeno->id}", $h)->assertStatus(404);
    }

    public function test_no_alcanza_la_cuenta_de_app_del_socio_ajeno(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/users/{$this->suyo->user_id}", $h)->assertOk();
        $this->getJson("/api/users/{$this->ajeno->user_id}", $h)->assertStatus(404);
    }

    // ── Listados: no basta con proteger el detalle ──────────────────────────

    public function test_el_listado_de_socios_solo_trae_los_suyos(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $cuerpo = $this->getJson('/api/users', $h)->assertOk()->json();
        $texto = json_encode($cuerpo);

        $this->assertStringContainsString('propio@ironbody.test', $texto);
        $this->assertStringNotContainsString('ajeno@ironbody.test', $texto);
    }

    public function test_el_listado_de_bloqueos_solo_trae_los_suyos(): void
    {
        MemberRiskLock::create([
            'member_id' => $this->suyo->id,
            'reason' => 'propio',
            'status' => MemberRiskLock::STATUS_ACTIVE,
        ]);
        MemberRiskLock::create([
            'member_id' => $this->ajeno->id,
            'reason' => 'ajeno',
            'status' => MemberRiskLock::STATUS_ACTIVE,
        ]);

        $h = $this->actingAsAdmin($this->cuentaA);

        $ids = collect($this->getJson('/api/admin/security/locks', $h)->assertOk()->json('data'))
            ->pluck('member_id')->all();

        $this->assertContains($this->suyo->id, $ids);
        $this->assertNotContains($this->ajeno->id, $ids);
    }

    // ── Las asignaciones mandan, y solo las vigentes ────────────────────────

    public function test_una_asignacion_terminada_ya_no_da_acceso(): void
    {
        // Antes lo atendía; ya no. La ficha deja de estar a su alcance.
        MemberTrainerAssignment::where('member_id', $this->suyo->id)
            ->where('trainer_id', $this->trainerA->id)
            ->update([
                'status' => MemberTrainerAssignment::STATUS_INACTIVE,
                'ended_at' => now(),
            ]);

        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->suyo->id}", $h)->assertStatus(404);
    }

    public function test_una_reasignacion_traslada_el_acceso(): void
    {
        // El socio pasa del entrenador B al A: la cuenta de A empieza a verlo.
        MemberTrainerAssignment::where('member_id', $this->ajeno->id)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);
        $this->assign($this->trainerA, $this->ajeno);

        $h = $this->actingAsAdmin($this->cuentaA);

        $this->getJson("/api/admin/members/{$this->ajeno->id}", $h)->assertOk();
    }

    /**
     * Una cuenta de entrenador SIN vincular no ve a nadie. Es lo correcto: a
     * quien no se le dijo a quién representa no puede representar a todos. La
     * validación impide crearla por la API, pero el alcance no puede depender
     * de eso —quedan las creadas antes de esta fase, y el `nullOnDelete` de la
     * FK deja una cuenta sin vínculo si se da de baja el perfil profesional.
     */
    public function test_una_cuenta_de_entrenador_sin_vincular_no_ve_a_nadie(): void
    {
        $h = $this->actingAsAdmin($this->adminTrainer(null));

        $this->getJson("/api/admin/members/{$this->suyo->id}", $h)->assertStatus(404);
        $this->getJson("/api/admin/members/{$this->ajeno->id}", $h)->assertStatus(404);
    }

    /** Y no puede ampliarse el alcance a sí misma: asignar es members.create. */
    public function test_el_entrenador_no_puede_asignarse_socios(): void
    {
        $h = $this->actingAsAdmin($this->cuentaA);

        $this->postJson("/api/admin/members/{$this->ajeno->id}/assign-trainer", [
            'trainer_id' => $this->trainerA->id,
        ], $h)->assertStatus(403);

        $this->assertDatabaseMissing('member_trainer_assignments', [
            'member_id' => $this->ajeno->id,
            'trainer_id' => $this->trainerA->id,
        ]);
    }

    // ── Nadie más cambia de comportamiento ──────────────────────────────────

    public function test_super_admin_sigue_viendolo_todo(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->getJson("/api/admin/members/{$this->suyo->id}", $h)->assertOk();
        $this->getJson("/api/admin/members/{$this->ajeno->id}", $h)->assertOk();
    }

    public function test_recepcion_conserva_su_comportamiento(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        $this->getJson("/api/admin/members/{$this->suyo->id}", $h)->assertOk();
        $this->getJson("/api/admin/members/{$this->ajeno->id}", $h)->assertOk();

        $cuerpo = json_encode($this->getJson('/api/users', $h)->assertOk()->json());
        $this->assertStringContainsString('propio@ironbody.test', $cuerpo);
        $this->assertStringContainsString('ajeno@ironbody.test', $cuerpo);
    }

    /**
     * Y fuera de una petición administrativa el filtro no existe. Si tocara
     * también a las colas o a la API del socio, media aplicación empezaría a no
     * encontrar socios sin motivo aparente.
     */
    public function test_sin_sesion_administrativa_no_se_filtra_nada(): void
    {
        $this->assertSame(2, Member::query()->count());
        $this->assertSame(2, User::query()->count());
    }
}
