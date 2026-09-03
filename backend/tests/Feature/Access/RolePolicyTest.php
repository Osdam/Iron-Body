<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Qué puede y qué NO puede cada rol, comprobado contra el servidor.
 *
 * La UI puede ocultar un botón; esto verifica lo único que cuenta: que llamar
 * al endpoint a mano devuelva 403. Cada caso está escrito como una frase que
 * el negocio entiende, porque es el negocio quien decide si la política es la
 * correcta.
 */
class RolePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, string $name = 'Prueba'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'pol-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function recepcion(): array
    {
        return $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION, 'Recepción'));
    }

    /** ¿La política concede este permiso a este rol? */
    private function puede(string $rol, string $permiso): bool
    {
        return CrmPermission::allows($this->admin($rol), $permiso);
    }

    // ── RECEPCIÓN: lo que necesita para atender ─────────────────────────────

    public function test_recepcion_puede_lo_que_necesita_en_el_mostrador(): void
    {
        foreach ([
            'members.view', 'members.create',
            'plans.view',
            'payments.view', 'payments.create',
            'cash.products.view', 'cash.products.operate',
            'cash.gym.view', 'cash.gym.operate',
            'inventory.view',
            'classes.view',
        ] as $permiso) {
            $this->assertTrue(
                $this->puede(Admin::ROLE_RECEPCION, $permiso),
                "Recepción DEBE poder {$permiso}: sin eso no puede atender.",
            );
        }
    }

    public function test_recepcion_no_puede_lo_que_no_le_corresponde(): void
    {
        foreach ([
            'earnings.view' => 'ver cuánto factura el negocio',
            'audit.view' => 'leer el registro de auditoría',
            'roles.manage' => 'repartir permisos',
            'users.manage' => 'crear cuentas del CRM',
            'integrations.manage' => 'desconectar WhatsApp',
            'billing.manage' => 'emitir facturación electrónica',
            'members.archive' => 'retirar la ficha de un socio',
            'inventory.edit' => 'mover existencias',
            'inventory.delete' => 'archivar productos',
            'cash.products.manage' => 'cerrar turnos ajenos',
            'cash.gym.manage' => 'registrar arqueos físicos',
            'moderation.manage' => 'sancionar en la comunidad',
            'security.manage' => 'tocar la seguridad de la plataforma',
            'marketing.manage' => 'lanzar campañas',
        ] as $permiso => $porque) {
            $this->assertFalse(
                $this->puede(Admin::ROLE_RECEPCION, $permiso),
                "Recepción NO debe poder {$porque} ({$permiso}).",
            );
        }
    }

    // ── RECEPCIÓN: contra el servidor, no contra la política ────────────────

    public function test_recepcion_recibe_403_al_forzar_un_endpoint_prohibido(): void
    {
        $h = $this->recepcion();

        $this->getJson('/api/admin/earnings', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'earnings.view');

        $this->getJson('/api/admin/audit-logs', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'audit.view');

        $this->getJson('/api/admin/roles', $h)->assertStatus(403);
        $this->postJson('/api/admin/roles', ['name' => 'Inventado'], $h)->assertStatus(403);
        $this->postJson('/api/admin/integrations/whatsapp/disconnect', [], $h)->assertStatus(403);
    }

    public function test_recepcion_no_puede_borrar_un_miembro(): void
    {
        // El caso que más duele: hasta ahora el servidor lo permitía. Se usa un
        // usuario REAL para que el 403 sea de autorización y no un 404 de
        // vinculación de modelo.
        $victima = \App\Models\User::create([
            'name' => 'Socio', 'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $this->deleteJson("/api/users/{$victima->id}", [], $this->recepcion())
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'members.archive');

        $this->assertDatabaseHas('users', ['id' => $victima->id]);
    }

    public function test_recepcion_si_puede_consultar_miembros_y_planes(): void
    {
        $h = $this->recepcion();

        $this->getJson('/api/users', $h)->assertOk();
        $this->getJson('/api/plans', $h)->assertOk();
    }

    public function test_recepcion_puede_abrir_las_dos_cajas(): void
    {
        $h = $this->recepcion();

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)
            ->assertStatus(201)
            ->assertJsonPath('results.products.result', 'opened');

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $h)
            ->assertStatus(201)
            ->assertJsonPath('results.gym.result', 'opened');
    }

    public function test_recepcion_no_puede_editar_inventario(): void
    {
        $this->postJson('/api/admin/products', ['name' => 'X'], $this->recepcion())
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'inventory.create');
    }

    // ── ENTRENADOR ──────────────────────────────────────────────────────────

    public function test_entrenador_solo_alcanza_sus_dominios(): void
    {
        AdminRole::firstOrCreate(['name' => CrmPermission::ROLE_ENTRENADOR], ['is_system' => true]);

        foreach (['members.view', 'routines.manage', 'classes.manage'] as $p) {
            $this->assertTrue($this->puede(CrmPermission::ROLE_ENTRENADOR, $p), "Entrenador debe poder {$p}.");
        }
        foreach (['payments.create', 'cash.products.operate', 'inventory.edit', 'earnings.view', 'roles.manage'] as $p) {
            $this->assertFalse($this->puede(CrmPermission::ROLE_ENTRENADOR, $p), "Entrenador NO debe poder {$p}.");
        }
    }

    // ── ADMINISTRADOR: sin regresión, pero sin autoridad sobre el sistema ───

    public function test_administrador_conserva_su_operacion(): void
    {
        foreach ([
            'members.view', 'members.edit', 'members.archive',
            'payments.view', 'payments.create',
            'inventory.edit', 'billing.manage',
            'marketing.manage', 'classes.manage', 'trainers.manage',
            'earnings.view', 'reports.view',
            'cash.products.manage', 'cash.gym.manage',
        ] as $p) {
            $this->assertTrue($this->puede(Admin::ROLE_ADMINISTRADOR, $p), "Administrador debe conservar {$p}.");
        }
    }

    public function test_administrador_no_tiene_autoridad_sobre_el_sistema(): void
    {
        foreach (['roles.manage', 'users.manage', 'integrations.manage', 'audit.view'] as $p) {
            $this->assertFalse(
                $this->puede(Admin::ROLE_ADMINISTRADOR, $p),
                "Administrador NO debe tener {$p}: es autoridad de Super Admin.",
            );
        }
    }

    // ── SUPER ADMIN ─────────────────────────────────────────────────────────

    public function test_super_admin_alcanza_todo_el_catalogo(): void
    {
        $super = $this->admin(Admin::ROLE_SUPER_ADMIN, 'Root');
        $faltan = array_values(array_filter(
            CrmPermission::all(),
            fn (string $p) => ! CrmPermission::allows($super, $p),
        ));

        $this->assertSame([], $faltan, 'Super Admin debe poder todo: '.implode(', ', $faltan));
    }

    public function test_super_admin_no_recibe_403_en_ninguna_ruta_mapeada(): void
    {
        $super = $this->admin(Admin::ROLE_SUPER_ADMIN, 'Root');
        $denegadas = [];

        foreach (Route::getRoutes() as $route) {
            if (! AuthorizationMap::isAdministrative($route)) {
                continue;
            }
            $p = AuthorizationMap::resolve($route);
            if ($p === null || in_array($p, [AuthorizationMap::PUBLIC, AuthorizationMap::SELF], true)) {
                continue;
            }
            if (! CrmPermission::allows($super, $p)) {
                $denegadas[] = AuthorizationMap::routeKey($route).' → '.$p;
            }
        }

        $this->assertSame([], $denegadas,
            'Super Admin quedaría fuera de rutas del propio CRM: '.implode(' · ', $denegadas));
    }

    // ── Credenciales sin persona ────────────────────────────────────────────

    public function test_el_token_compartido_solo_lee(): void
    {
        config(['admin.api_token' => 'token-de-automatizacion']);
        $h = ['Authorization' => 'Bearer token-de-automatizacion'];

        $victima = \App\Models\User::create([
            'name' => 'Socio', 'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $this->getJson('/api/users', $h)->assertOk();
        $this->postJson('/api/admin/products', ['name' => 'X'], $h)->assertStatus(403);
        $this->deleteJson("/api/users/{$victima->id}", [], $h)->assertStatus(403);
    }

    public function test_sin_credencial_no_se_pasa_de_la_puerta(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
        $this->getJson('/api/admin/earnings')->assertStatus(401);
    }
}
