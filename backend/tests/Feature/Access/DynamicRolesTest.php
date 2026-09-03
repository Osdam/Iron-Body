<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roles administrables desde el CRM.
 *
 * Hasta ahora eran cuatro constantes: se podían editar sus permisos pero no
 * crear uno nuevo sin desplegar. El catálogo `admin_roles` lo permite sin tocar
 * la resolución de permisos, que ya trabajaba con nombres de rol en texto.
 *
 * Lo que estos tests protegen sobre todo es que la evolución NO abra una vía de
 * escalación: un rol nuevo nace sin nada, los del sistema no se pueden
 * renombrar y solo `roles.manage` administra el catálogo.
 */
class DynamicRolesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_SUPER_ADMIN, string $name = 'Root'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'roles-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_los_cuatro_roles_existentes_se_conservan_como_del_sistema(): void
    {
        foreach (Admin::ROLES as $nombre) {
            $rol = AdminRole::where('name', $nombre)->first();
            $this->assertNotNull($rol, "falta el rol {$nombre}");
            $this->assertTrue($rol->is_system, "{$nombre} debe ser del sistema");
        }
    }

    public function test_crea_un_rol_nuevo_desde_el_crm(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        $this->postJson('/api/admin/roles', [
            'name' => 'Cajero de gimnasio',
            'description' => 'Solo opera la caja del gimnasio',
        ], $h)
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Cajero de gimnasio')
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('admin_roles', ['name' => 'Cajero de gimnasio']);
    }

    public function test_un_rol_nuevo_nace_sin_permisos(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/roles', ['name' => 'Cajero de gimnasio'], $h)->assertStatus(201);

        // Heredar algo por parecido de nombre sería conceder lo que nadie pidió.
        $this->assertSame([], app(RolePermissionPolicy::class)->effectiveFor('Cajero de gimnasio'));
    }

    public function test_se_le_asignan_permisos_y_pasan_a_ser_efectivos(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/roles', ['name' => 'Cajero de gimnasio'], $h)->assertStatus(201);

        $this->putJson('/api/admin/roles/permissions', [
            'role' => 'Cajero de gimnasio',
            'permission' => CrmPermission::CASH_GYM_OPERATE,
            'granted' => true,
        ], $h)->assertOk();

        $empleado = $this->admin('Cajero de gimnasio', 'Nuevo');
        $this->assertTrue(CrmPermission::allows($empleado, CrmPermission::CASH_GYM_OPERATE));
        $this->assertFalse(CrmPermission::allows($empleado, CrmPermission::CASH_PRODUCTS_OPERATE));
    }

    public function test_el_rol_asignado_funciona_de_extremo_a_extremo(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/roles', ['name' => 'Cajero de gimnasio'], $h)->assertStatus(201);
        foreach (['cash.gym.view', 'cash.gym.operate', 'cash.products.view'] as $permiso) {
            RolePermission::create(['role' => 'Cajero de gimnasio', 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        $cajero = $this->actingAsAdmin($this->admin('Cajero de gimnasio', 'Nuevo'));

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $cajero)
            ->assertStatus(201)
            ->assertJsonPath('results.gym.result', 'opened');

        // Pero no la de productos: solo puede verla.
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $cajero)
            ->assertStatus(207)
            ->assertJsonPath('results.products.result', 'forbidden');
    }

    public function test_renombrar_arrastra_admins_y_permisos(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $res = $this->postJson('/api/admin/roles', ['name' => 'Cajero'], $h)->assertStatus(201);
        $id = $res->json('data.id');

        RolePermission::create(['role' => 'Cajero', 'permission' => 'cash.gym.view', 'granted' => true]);
        $empleado = $this->admin('Cajero', 'Nuevo');

        $this->patchJson("/api/admin/roles/{$id}", ['name' => 'Cajero de gimnasio'], $h)->assertOk();

        // Las dos referencias por valor viajan con el nombre.
        $this->assertSame('Cajero de gimnasio', $empleado->fresh()->role);
        $this->assertDatabaseHas('role_permissions', ['role' => 'Cajero de gimnasio', 'permission' => 'cash.gym.view']);
        $this->assertDatabaseMissing('role_permissions', ['role' => 'Cajero']);
        $this->assertTrue(CrmPermission::allows($empleado->fresh(), 'cash.gym.view'), 'no pierde sus permisos');
    }

    public function test_un_rol_del_sistema_no_se_renombra(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $rol = AdminRole::where('name', Admin::ROLE_RECEPCION)->first();

        $this->patchJson("/api/admin/roles/{$rol->id}", ['name' => 'Mostrador'], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 'system_role_immutable');

        $this->assertSame(Admin::ROLE_RECEPCION, $rol->fresh()->name);
    }

    public function test_no_se_archiva_un_rol_con_gente_asignada(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $id = $this->postJson('/api/admin/roles', ['name' => 'Cajero'], $h)->json('data.id');
        $this->admin('Cajero', 'Nuevo');

        $this->postJson("/api/admin/roles/{$id}/archive", [], $h)
            ->assertStatus(409)
            ->assertJsonPath('code', 'role_in_use')
            ->assertJsonPath('admins_count', 1);
    }

    public function test_archivar_no_borra_y_se_puede_restaurar(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $id = $this->postJson('/api/admin/roles', ['name' => 'Temporal'], $h)->json('data.id');

        $this->postJson("/api/admin/roles/{$id}/archive", [], $h)->assertOk()->assertJsonPath('data.archived', true);

        // Sigue existiendo: perderlo dejaría sin traza qué permisos tuvo.
        $this->assertDatabaseHas('admin_roles', ['id' => $id]);
        $this->assertNotContains('Temporal', AdminRole::assignableNames());

        $this->postJson("/api/admin/roles/{$id}/restore", [], $h)->assertOk()->assertJsonPath('data.archived', false);
        $this->assertContains('Temporal', AdminRole::assignableNames());
    }

    public function test_un_rol_del_sistema_no_se_archiva(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $rol = AdminRole::where('name', Admin::ROLE_SUPER_ADMIN)->first();

        $this->postJson("/api/admin/roles/{$rol->id}/archive", [], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 'system_role_immutable');
    }

    public function test_sin_roles_manage_no_se_administran_roles(): void
    {
        // Recepción cobra, pero no reparte capacidades: administrar roles es el
        // privilegio más alto del CRM y no se deriva de ninguno operativo.
        $h = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION, 'Ana'));

        $this->getJson('/api/admin/roles', $h)->assertStatus(403);
        $this->postJson('/api/admin/roles', ['name' => 'Inventado'], $h)->assertStatus(403);

        $this->assertDatabaseMissing('admin_roles', ['name' => 'Inventado']);
    }

    public function test_la_matriz_de_permisos_incluye_los_roles_nuevos(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/roles', ['name' => 'Cajero de gimnasio'], $h)->assertStatus(201);

        $res = $this->getJson('/api/admin/roles/permissions', $h)->assertOk();

        $this->assertContains('Cajero de gimnasio', $res->json('roles'));
        foreach (Admin::ROLES as $nombre) {
            $this->assertContains($nombre, $res->json('roles'), "el rol del sistema {$nombre} sigue en la matriz");
        }
    }

    public function test_no_se_duplica_el_nombre_de_un_rol(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/roles', ['name' => 'Cajero'], $h)->assertStatus(201);

        $this->postJson('/api/admin/roles', ['name' => 'Cajero'], $h)->assertStatus(422);
        $this->postJson('/api/admin/roles', ['name' => Admin::ROLE_RECEPCION], $h)->assertStatus(422);
    }
}
