<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cuentas administrativas del CRM.
 *
 * Lo que más importa aquí no es crear usuarios —eso es CRUD— sino que el
 * sistema no se pueda dejar sin dueño ni escalar desde dentro. Cada invariante
 * tiene su prueba, porque un fallo en cualquiera de ellos se descubre cuando ya
 * no hay forma de entrar.
 */
class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(string $name = 'Root'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'sa-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
    }

    // ── Alta ────────────────────────────────────────────────────────────────

    public function test_crea_una_cuenta_de_recepcion_y_entrega_la_clave_una_vez(): void
    {
        $h = $this->actingAsAdmin($this->superAdmin());

        $res = $this->postJson('/api/admin/users', [
            'name' => 'Recepcionista Mañana',
            'email' => 'manana@ironbody.test',
            'role' => Admin::ROLE_RECEPCION,
        ], $h)->assertStatus(201);

        $temporal = $res->json('temporary_password');
        $this->assertIsString($temporal);
        $this->assertGreaterThanOrEqual(16, strlen($temporal));

        $creado = Admin::where('email', 'manana@ironbody.test')->first();
        $this->assertNotNull($creado);
        $this->assertSame(Admin::ROLE_RECEPCION, $creado->role);
        $this->assertTrue($creado->isActive());

        // La contraseña se guarda hasheada, nunca en claro.
        $this->assertNotSame($temporal, $creado->password);
        $this->assertTrue(Hash::check($temporal, $creado->password));
    }

    public function test_la_cuenta_creada_puede_iniciar_sesion(): void
    {
        $h = $this->actingAsAdmin($this->superAdmin());

        $clave = $this->postJson('/api/admin/users', [
            'name' => 'Recepcionista Tarde',
            'email' => 'tarde@ironbody.test',
            'role' => Admin::ROLE_RECEPCION,
        ], $h)->json('temporary_password');

        // De extremo a extremo: la clave entregada sirve de verdad.
        $this->postJson('/api/admin/auth/login', [
            'email' => 'tarde@ironbody.test',
            'password' => $clave,
        ])->assertOk();
    }

    public function test_no_se_repite_el_correo(): void
    {
        $h = $this->actingAsAdmin($this->superAdmin());
        $this->postJson('/api/admin/users', [
            'name' => 'Uno', 'email' => 'repe@ironbody.test', 'role' => Admin::ROLE_RECEPCION,
        ], $h)->assertStatus(201);

        $this->postJson('/api/admin/users', [
            'name' => 'Dos', 'email' => 'repe@ironbody.test', 'role' => Admin::ROLE_RECEPCION,
        ], $h)->assertStatus(422);
    }

    public function test_no_se_acepta_un_rol_inventado(): void
    {
        $this->postJson('/api/admin/users', [
            'name' => 'Fulano', 'email' => 'f@ironbody.test', 'role' => 'Dios',
        ], $this->actingAsAdmin($this->superAdmin()))->assertStatus(422);
    }

    // ── Escalación ──────────────────────────────────────────────────────────

    public function test_solo_un_super_admin_nombra_a_otro_super_admin(): void
    {
        // Un rol con users.manage pero que no es Super Admin.
        $h = $this->adminWithPermissions(['users.view', 'users.manage']);

        $this->postJson('/api/admin/users', [
            'name' => 'Aspirante', 'email' => 'asp@ironbody.test', 'role' => Admin::ROLE_SUPER_ADMIN,
        ], $h)
            ->assertStatus(403)
            ->assertJsonPath('code', 'requires_super_admin');

        $this->assertDatabaseMissing('admins', ['email' => 'asp@ironbody.test']);
    }

    public function test_nadie_se_asciende_a_si_mismo(): void
    {
        $yo = Admin::create([
            'name' => 'Yo', 'email' => 'yo@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
        // Con permiso de gestionar usuarios, pero sobre sí mismo: no.
        \App\Models\RolePermission::updateOrCreate(
            ['role' => Admin::ROLE_RECEPCION, 'permission' => 'users.manage'], ['granted' => true],
        );
        app(\App\Support\Access\RolePermissionPolicy::class)->flush();

        $this->patchJson("/api/admin/users/{$yo->id}", ['role' => Admin::ROLE_ADMINISTRADOR],
            $this->actingAsAdmin($yo))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_modify_self');

        $this->assertSame(Admin::ROLE_RECEPCION, $yo->fresh()->role);
    }

    public function test_nadie_se_desactiva_a_si_mismo(): void
    {
        $root = $this->superAdmin();
        $this->superAdmin('Otro');   // hay más de uno, así que no es el último

        $this->postJson("/api/admin/users/{$root->id}/status", ['status' => 'inactive'],
            $this->actingAsAdmin($root))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_modify_self');

        $this->assertTrue($root->fresh()->isActive());
    }

    // ── El último Super Admin ───────────────────────────────────────────────

    public function test_no_se_desactiva_al_ultimo_super_admin(): void
    {
        $unico = $this->superAdmin('Único');

        // Quien ejecuta tiene users.manage pero no es Super Admin, así que no
        // puede quedarse él con la autoridad: el sistema se quedaría sin dueño.
        $h = $this->adminWithPermissions(['users.view', 'users.manage']);

        $this->postJson("/api/admin/users/{$unico->id}/status", ['status' => 'inactive'], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 'last_super_admin');

        $this->assertTrue($unico->fresh()->isActive(), 'El último Super Admin sigue activo');
    }

    public function test_al_ultimo_super_admin_no_se_le_quita_el_rol(): void
    {
        $unico = $this->superAdmin('Único');
        $ejecutor = $this->superAdmin('Ejecutor');
        $h = $this->actingAsAdmin($ejecutor);

        // Con dos Super Admin activos sí se puede degradar a uno.
        $this->patchJson("/api/admin/users/{$unico->id}", ['role' => Admin::ROLE_ADMINISTRADOR], $h)
            ->assertOk();

        // Ahora el ejecutor es el último: no puede degradarse ni ser degradado.
        $tercero = Admin::create([
            'name' => 'T', 'email' => 't@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]);
        \App\Models\RolePermission::updateOrCreate(
            ['role' => Admin::ROLE_ADMINISTRADOR, 'permission' => 'users.manage'], ['granted' => true],
        );
        app(\App\Support\Access\RolePermissionPolicy::class)->flush();

        $this->patchJson("/api/admin/users/{$ejecutor->id}", ['role' => Admin::ROLE_RECEPCION],
            $this->actingAsAdmin($tercero))
            ->assertStatus(422)
            ->assertJsonPath('code', 'last_super_admin');

        $this->assertSame(Admin::ROLE_SUPER_ADMIN, $ejecutor->fresh()->role);
    }

    // ── Sin borrado ─────────────────────────────────────────────────────────

    public function test_desactivar_conserva_la_cuenta_y_bloquea_el_acceso(): void
    {
        $h = $this->actingAsAdmin($this->superAdmin());
        $clave = $this->postJson('/api/admin/users', [
            'name' => 'Temporal', 'email' => 'temp@ironbody.test', 'role' => Admin::ROLE_RECEPCION,
        ], $h)->json('temporary_password');

        $creado = Admin::where('email', 'temp@ironbody.test')->first();
        $this->postJson("/api/admin/users/{$creado->id}/status", ['status' => 'inactive'], $h)->assertOk();

        // Sigue existiendo: su rastro de auditoría conserva dueño.
        $this->assertDatabaseHas('admins', ['id' => $creado->id]);
        // Pero ya no entra.
        $this->postJson('/api/admin/auth/login', [
            'email' => 'temp@ironbody.test', 'password' => $clave,
        ])->assertStatus(401);
    }

    public function test_no_existe_borrado_de_cuentas(): void
    {
        $victima = Admin::create([
            'name' => 'V', 'email' => 'v@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);

        $this->deleteJson("/api/admin/users/{$victima->id}", [],
            $this->actingAsAdmin($this->superAdmin()))
            ->assertStatus(405);   // el método no existe, a propósito

        $this->assertDatabaseHas('admins', ['id' => $victima->id]);
    }

    // ── Permisos de la propia pantalla ──────────────────────────────────────

    public function test_recepcion_no_administra_cuentas(): void
    {
        $recepcion = Admin::create([
            'name' => 'R', 'email' => 'r@ironbody.test', 'password' => 'x',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
        $h = $this->actingAsAdmin($recepcion);

        $this->getJson('/api/admin/users', $h)->assertStatus(403);
        $this->postJson('/api/admin/users', [
            'name' => 'X', 'email' => 'x@ironbody.test', 'role' => Admin::ROLE_RECEPCION,
        ], $h)->assertStatus(403);
    }

    public function test_restablecer_contrasena_entrega_una_nueva_y_la_anterior_deja_de_valer(): void
    {
        $h = $this->actingAsAdmin($this->superAdmin());
        $anterior = $this->postJson('/api/admin/users', [
            'name' => 'Reset', 'email' => 'reset@ironbody.test', 'role' => Admin::ROLE_RECEPCION,
        ], $h)->json('temporary_password');

        $creado = Admin::where('email', 'reset@ironbody.test')->first();
        $nueva = $this->postJson("/api/admin/users/{$creado->id}/reset-password", [], $h)
            ->assertOk()->json('temporary_password');

        $this->assertNotSame($anterior, $nueva);
        $this->postJson('/api/admin/auth/login', ['email' => 'reset@ironbody.test', 'password' => $anterior])
            ->assertStatus(401);
        $this->postJson('/api/admin/auth/login', ['email' => 'reset@ironbody.test', 'password' => $nueva])
            ->assertOk();
    }
}
