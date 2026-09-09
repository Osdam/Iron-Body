<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El rol ENTRENADOR como cuenta del CRM.
 *
 * EL FALLO QUE SE VIGILA
 * ----------------------
 * El rol estaba a medio construir. `CrmPermission::ROLE_ENTRENADOR` ya tenía
 * sus permisos por defecto y el front ya lo pintaba en la pantalla de Roles,
 * pero nadie lo materializó en el catálogo `admin_roles`. Como
 * `AdminUserController` valida con `Rule::in(AdminRole::assignableNames())` y
 * esa lista sale del catálogo, crear una cuenta de entrenador devolvía 422.
 *
 * Existía en el papel y era inasignable en la práctica: la pantalla ofrecía un
 * rol que no se podía dar a nadie.
 *
 * Lo que aquí se fija es que el rol EXISTE, es ASIGNABLE, y que sus permisos
 * los decide el BACKEND en las dos direcciones: concede lo suyo y deniega el
 * resto con 403. Ocultar el botón en Angular no es autorización.
 */
class TrainerRoleTest extends TestCase
{
    use RefreshDatabase;

    private const ROL = CrmPermission::ROLE_ENTRENADOR;

    private function admin(string $rol, string $nombre = 'Persona'): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => 'rol-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    // ── El catálogo ─────────────────────────────────────────────────────────

    public function test_el_rol_entrenador_existe_en_el_catalogo(): void
    {
        $rol = AdminRole::where('name', self::ROL)->first();

        $this->assertNotNull($rol, 'el rol Entrenador no está en admin_roles');
        $this->assertNull($rol->archived_at, 'nace activo, no archivado');
    }

    /**
     * El nombre está protegido porque el CÓDIGO lo nombra por constante. Si se
     * pudiera renombrar, los entrenadores existentes quedarían apuntando a un
     * rol sin defaults —o sea, sin permisos— y sin que nada avisara.
     */
    public function test_el_rol_es_del_sistema_y_no_se_puede_renombrar_ni_archivar(): void
    {
        $rol = AdminRole::where('name', self::ROL)->firstOrFail();
        $this->assertTrue($rol->is_system);

        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Root'));

        $this->patchJson("/api/admin/roles/{$rol->id}", ['name' => 'Coach'], $h)
            ->assertStatus(422);
        $this->postJson("/api/admin/roles/{$rol->id}/archive", [], $h)
            ->assertStatus(422);

        $this->assertSame(self::ROL, $rol->fresh()->name);
        $this->assertNull($rol->fresh()->archived_at);
    }

    // ── Asignable: el fallo reportado ───────────────────────────────────────

    public function test_el_rol_se_ofrece_como_asignable(): void
    {
        $this->assertContains(self::ROL, AdminRole::assignableNames());

        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Root'));

        $this->getJson('/api/admin/roles/assignable', $h)
            ->assertOk()
            ->assertJsonFragment(['data' => AdminRole::assignableNames()]);
    }

    /**
     * ESTE es el test que fallaba antes de materializar el rol: 422, porque el
     * nombre no estaba en la lista contra la que valida el controlador.
     */
    public function test_se_puede_crear_una_cuenta_del_crm_con_rol_entrenador(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Root'));

        $this->postJson('/api/admin/users', [
            'name' => 'Oscar Entrenador',
            'email' => 'oscar.coach@ironbody.test',
            'role' => self::ROL,
        ], $h)->assertStatus(201)->assertJsonPath('data.role', self::ROL);

        $creado = Admin::where('email', 'oscar.coach@ironbody.test')->firstOrFail();
        $this->assertSame(self::ROL, $creado->role);
        $this->assertSame('active', $creado->status);
    }

    // ── Permisos efectivos ──────────────────────────────────────────────────

    /**
     * Los permisos que el backend le concede. No es una lista decorativa: es lo
     * que viaja al front al iniciar sesión y lo que exige cada ruta.
     */
    public function test_sus_permisos_son_los_de_rutinas_clases_y_ficha_de_socio(): void
    {
        $entrenador = $this->admin(self::ROL, 'Oscar');

        $permisos = CrmPermission::forAdmin($entrenador);

        foreach ([
            'members.view',
            'routines.view', 'routines.manage',
            'classes.view', 'classes.manage',
            'trainers.view',
        ] as $p) {
            $this->assertContains($p, $permisos, "le falta {$p}");
        }
    }

    /**
     * Y sobre todo: lo que NO puede. No cobra, no toca existencias, no reparte
     * permisos y no ve las ganancias del negocio.
     */
    public function test_no_tiene_caja_ni_inventario_ni_administracion(): void
    {
        $entrenador = $this->admin(self::ROL, 'Oscar');

        foreach ([
            'cash.products.operate', 'cash.gym.operate', 'caja.sell',
            'inventory.edit', 'inventory.create', 'inventory.delete',
            'roles.manage', 'users.manage', 'audit.view',
            'earnings.view', 'billing.manage', 'integrations.manage',
        ] as $p) {
            $this->assertFalse(
                CrmPermission::allows($entrenador, $p),
                "NO debería poder {$p}",
            );
        }
    }

    /** Un entrenador inactivo no obtiene nada. Falla cerrado. */
    public function test_un_entrenador_inactivo_no_obtiene_ningun_permiso(): void
    {
        $entrenador = $this->admin(self::ROL, 'Oscar');
        $entrenador->update(['status' => 'inactive']);

        $this->assertSame([], CrmPermission::forAdmin($entrenador->fresh()));
    }

    // ── RBAC de verdad: el backend responde 403 ─────────────────────────────

    public function test_entra_donde_le_corresponde(): void
    {
        $h = $this->actingAsAdmin($this->admin(self::ROL, 'Oscar'));

        // Rutinas y ejercicios: su trabajo.
        $this->getJson('/api/admin/exercises', $h)->assertOk();
    }

    /**
     * La prueba que importa: el backend DENIEGA, y dice qué permiso faltaba.
     * Si esto solo se comprobara en Angular, bastaría con llamar a la API.
     */
    public function test_el_backend_le_deniega_lo_que_no_es_suyo(): void
    {
        $h = $this->actingAsAdmin($this->admin(self::ROL, 'Oscar'));

        $this->getJson('/api/admin/roles/permissions', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'roles.manage');

        $this->getJson('/api/admin/users', $h)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    /** Y no puede crearse cuentas a sí mismo ni repartirse permisos. */
    public function test_no_puede_crear_usuarios_del_crm(): void
    {
        $h = $this->actingAsAdmin($this->admin(self::ROL, 'Oscar'));

        $this->postJson('/api/admin/users', [
            'name' => 'Cuenta fantasma',
            'email' => 'fantasma@ironbody.test',
            'role' => Admin::ROLE_SUPER_ADMIN,
        ], $h)->assertStatus(403);

        $this->assertDatabaseMissing('admins', ['email' => 'fantasma@ironbody.test']);
    }

    // ── El front recibe la política, no la inventa ──────────────────────────

    /**
     * `toPublicArray()` es lo que devuelve el login. El guard de Angular lee de
     * ahí: si el backend no enviara los permisos, el front no sabría qué
     * ofrecer y volvería a decidir por su cuenta, que es el fallo que la
     * arquitectura actual ya corrigió.
     */
    public function test_la_sesion_entrega_al_front_los_permisos_efectivos(): void
    {
        $entrenador = $this->admin(self::ROL, 'Oscar');

        $publico = $entrenador->toPublicArray();

        $this->assertSame(self::ROL, $publico['role']);
        $this->assertContains('routines.manage', $publico['permissions']);
        $this->assertNotContains('inventory.edit', $publico['permissions']);
    }

    // ── Configurable desde la pantalla de Roles ─────────────────────────────

    /**
     * El requisito es «permisos configurables», no «permisos fijos en código».
     * `role_permissions` es una capa SOBRE los defaults, así que conceder y
     * revocar tiene que funcionar también para este rol.
     */
    public function test_sus_permisos_se_pueden_ajustar_desde_configuracion_roles(): void
    {
        $entrenador = $this->admin(self::ROL, 'Oscar');
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Root'));

        // Conceder algo que por defecto no tiene.
        $this->putJson('/api/admin/roles/permissions', [
            'role' => self::ROL,
            'permission' => 'equipment.view',
            'granted' => true,
        ], $h)->assertOk();

        $this->assertTrue(CrmPermission::allows($entrenador->fresh(), 'equipment.view'));

        // Y revocar uno que sí tenía por defecto.
        $this->putJson('/api/admin/roles/permissions', [
            'role' => self::ROL,
            'permission' => 'classes.manage',
            'granted' => false,
        ], $h)->assertOk();

        $this->assertFalse(CrmPermission::allows($entrenador->fresh(), 'classes.manage'));
    }

    /** El rol aparece en la matriz de permisos, o no habría dónde ajustarlo. */
    public function test_el_rol_aparece_en_la_matriz_de_permisos(): void
    {
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Root'));

        $r = $this->getJson('/api/admin/roles/permissions', $h)->assertOk();

        $this->assertContains(self::ROL, $r->json('roles'));

        // Y con su fila real: la pantalla necesita saber, permiso a permiso,
        // qué tiene concedido y si eso se apartó del valor por defecto.
        $fila = $r->json('matrix.'.self::ROL);
        $this->assertIsArray($fila);
        $this->assertTrue($fila['routines.manage']['granted']);
        $this->assertTrue($fila['routines.manage']['default']);
        $this->assertFalse($fila['inventory.edit']['granted']);
    }
}
