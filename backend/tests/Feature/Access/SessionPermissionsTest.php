<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La sesión lleva los permisos efectivos, para que el CRM deje de inventarlos.
 *
 * Había dos catálogos sin contacto: el backend calculaba los permisos de cada
 * administrador —valores por defecto del rol más lo concedido o revocado en
 * `role_permissions`— y los aplicaba en cada petición, mientras el navegador
 * decidía qué botones pintar con una política guardada en `localStorage`.
 *
 * Se vio en producción: a Ana (Recepción) se le concedió `inventory.edit` desde
 * la pantalla de Roles, el backend lo respetaba, y en pantalla no le aparecía un
 * solo control de edición. El CRM nunca preguntaba.
 *
 * Lo que fija esta prueba: que `login` y `me` envían la lista real, y que esa
 * lista refleja lo persistido y no solo el valor por defecto del rol.
 */
class SessionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function recepcion(): Admin
    {
        return Admin::create([
            'name' => 'Recepción Prueba',
            'email' => 'rec-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);
    }

    private function meDe(Admin $admin): array
    {
        return $this->getJson('/api/admin/auth/me', $this->actingAsAdmin($admin))
            ->assertOk()
            ->json('user');
    }

    // ── La sesión ya transporta los permisos ────────────────────────────────

    public function test_la_sesion_incluye_los_permisos_efectivos(): void
    {
        $user = $this->meDe($this->recepcion());

        $this->assertArrayHasKey('permissions', $user, 'sin esto el CRM se los inventa');
        $this->assertIsArray($user['permissions']);
        $this->assertNotEmpty($user['permissions']);
    }

    public function test_son_exactamente_los_que_aplica_el_backend(): void
    {
        // Misma lista que usa EnforceAdminAuthorization. Si divergieran, la
        // interfaz volvería a ofrecer lo que el servidor rechaza.
        $admin = $this->recepcion();

        $this->assertSame(
            CrmPermission::forAdmin($admin),
            $this->meDe($admin)['permissions'],
        );
    }

    public function test_el_login_los_envia_igual_que_me(): void
    {
        $admin = $this->recepcion();

        $login = $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk()->json('user');

        $this->assertSame($this->meDe($admin)['permissions'], $login['permissions']);
    }

    // ── Y reflejan lo persistido, no solo el rol ────────────────────────────

    public function test_un_permiso_concedido_al_rol_llega_a_la_sesion(): void
    {
        // El caso de Ana: `inventory.edit` no está en los valores por defecto de
        // recepción, se concede desde la pantalla de Roles, y tiene que llegar.
        $admin = $this->recepcion();
        $this->assertNotContains('inventory.edit', $this->meDe($admin)['permissions']);

        // Igual que la pantalla de Roles: persiste e invalida la caché.
        app(RolePermissionPolicy::class)
            ->set(Admin::ROLE_RECEPCION, 'inventory.edit', true);

        $this->assertContains('inventory.edit', $this->meDe($admin->fresh())['permissions']);
    }

    public function test_un_permiso_revocado_desaparece_de_la_sesion(): void
    {
        $admin = $this->recepcion();
        $this->assertContains('payments.create', $this->meDe($admin)['permissions']);

        app(RolePermissionPolicy::class)
            ->set(Admin::ROLE_RECEPCION, 'payments.create', false);

        $this->assertNotContains('payments.create', $this->meDe($admin->fresh())['permissions']);
    }

    // ── Bordes que fallan cerrado ───────────────────────────────────────────

    public function test_un_super_admin_recibe_su_lista_completa(): void
    {
        $user = $this->getJson('/api/admin/auth/me', $this->adminHeaders())->assertOk()->json('user');

        $this->assertContains('roles.manage', $user['permissions']);
        $this->assertContains('audit.view', $user['permissions']);
    }

    public function test_un_administrador_inactivo_no_recibe_permisos(): void
    {
        $inactivo = Admin::create([
            'name' => 'Baja', 'email' => 'baja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'inactive',
        ]);

        // Ni siquiera llega a la sesión: la credencial de un inactivo no vale.
        $this->getJson('/api/admin/auth/me', $this->actingAsAdmin($inactivo))
            ->assertStatus(403);
    }

    public function test_la_sesion_no_expone_nada_mas_de_la_cuenta(): void
    {
        // Añadir permisos no puede convertir el payload en un volcado del
        // registro: nada de contraseñas ni de datos internos.
        $user = $this->meDe($this->recepcion());

        $this->assertSame(['id', 'name', 'email', 'role', 'permissions'], array_keys($user));
    }

    // ── Enviar la lista no autoriza nada ────────────────────────────────────

    public function test_la_lista_es_informativa_el_backend_sigue_decidiendo(): void
    {
        // Recepción recibe sus permisos, y aun así una ruta que exige otro sigue
        // devolviendo 403. La sesión informa a la interfaz; no la autoriza.
        $admin = $this->recepcion();
        $permisos = $this->meDe($admin)['permissions'];

        $this->assertNotContains('roles.manage', $permisos);
        $this->postJson('/api/admin/audit-logs', [
            'action' => 'create', 'module' => 'X', 'entity' => 'y',
        ], $this->actingAsAdmin($admin))->assertStatus(403);
    }
}
