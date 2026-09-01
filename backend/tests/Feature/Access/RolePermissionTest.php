<?php

namespace Tests\Feature\Access;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\TaxRate;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuración → Roles debe cambiar la autorización REAL.
 *
 * Antes la pantalla escribía en `localStorage` y no llamaba al servidor: quien
 * creía estar revocando un permiso para la organización no lo revocaba en
 * ninguna parte. Estas pruebas fijan que ahora el cambio llega hasta el 403.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, string $name = 'Test'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'test-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        // Con tarifa asignada, como los de producción: PricingService rechaza
        // cobrar un producto facturable sin tratamiento tributario.
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Bebidas',
            'sale_price' => 3000, 'cost_price' => 1200,
            'stock' => 10, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $rate->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    // ── Con la tabla vacía nada cambia ──────────────────────────────────────

    public function test_sin_politica_guardada_el_sistema_se_comporta_igual_que_antes(): void
    {
        // Es la garantía de que la migración no altera el acceso de nadie.
        $this->assertSame(
            CrmPermission::defaultsFor(Admin::ROLE_RECEPCION),
            app(RolePermissionPolicy::class)->effectiveFor(Admin::ROLE_RECEPCION),
        );
        $this->assertSame(0, RolePermission::count());
    }

    // ── CASO 1, 3 · revocar y que el backend lo aplique ─────────────────────

    public function test_caso_1_y_3_revocar_caja_sell_hace_que_el_backend_devuelva_403(): void
    {
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Super'));
        $recepcion = $this->admin(Admin::ROLE_RECEPCION, 'Ana');
        $product = $this->product();
        $this->openCashShift();

        // Antes de revocar, Recepción vende.
        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $this->actingAsAdmin($recepcion))->assertStatus(201);

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION,
            'permission' => CrmPermission::CAJA_SELL,
            'granted' => false,
        ], $super)->assertOk();

        // Y después ya no.
        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $this->actingAsAdmin($recepcion))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'caja.sell');
    }

    // ── CASO 2 · el cambio persiste ─────────────────────────────────────────

    public function test_caso_2_el_cambio_sobrevive_a_una_sesion_nueva(): void
    {
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION,
            'permission' => CrmPermission::CAJA_SELL,
            'granted' => false,
        ], $super)->assertOk();

        // Está en la base, no en el navegador de nadie.
        $this->assertDatabaseHas('role_permissions', [
            'role' => Admin::ROLE_RECEPCION,
            'permission' => 'caja.sell',
            'granted' => false,
        ]);

        // Una credencial completamente nueva ve el mismo estado.
        $otro = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Otro'));
        $matrix = $this->getJson('/api/admin/roles/permissions', $otro)->assertOk()->json('matrix');
        $this->assertFalse($matrix[Admin::ROLE_RECEPCION]['caja.sell']['granted']);
        $this->assertTrue($matrix[Admin::ROLE_RECEPCION]['caja.sell']['overridden'], 'difiere del código');
    }

    // ── CASO 4 · reactivar ──────────────────────────────────────────────────

    public function test_caso_4_reactivar_devuelve_el_permiso(): void
    {
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));
        $policy = app(RolePermissionPolicy::class);

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION, 'permission' => CrmPermission::CAJA_SELL, 'granted' => false,
        ], $super)->assertOk();
        $this->assertNotContains('caja.sell', $policy->effectiveFor(Admin::ROLE_RECEPCION));

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION, 'permission' => CrmPermission::CAJA_SELL, 'granted' => true,
        ], $super)->assertOk();
        $this->assertContains('caja.sell', $policy->effectiveFor(Admin::ROLE_RECEPCION));

        // Al volver al valor por defecto la fila se borra: la tabla guarda solo
        // excepciones reales.
        $this->assertSame(0, RolePermission::count());
    }

    public function test_conceder_un_permiso_que_el_rol_no_tenia_tambien_funciona(): void
    {
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION,
            'permission' => CrmPermission::INVENTORY_EDIT,
            'granted' => true,
        ], $super)->assertOk();

        $recepcion = $this->admin(Admin::ROLE_RECEPCION);
        $product = $this->product();

        $this->postJson("/api/admin/products/{$product->id}/entry",
            ['quantity' => 5, 'origin' => 'purchase'], $this->actingAsAdmin($recepcion))
            ->assertStatus(201);
    }

    // ── CASO 5, 6 · nadie se eleva a sí mismo ───────────────────────────────

    public function test_caso_5_y_6_un_rol_no_super_admin_no_puede_tocar_la_politica(): void
    {
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO] as $role) {
            $headers = $this->actingAsAdmin($this->admin($role));

            $this->getJson('/api/admin/roles/permissions', $headers)->assertStatus(403);

            $this->putJson('/api/admin/roles/permissions', [
                'role' => $role,
                'permission' => CrmPermission::BILLING_MANAGE,
                'granted' => true,
            ], $headers)->assertStatus(403);
        }

        $this->assertSame(0, RolePermission::count(), 'nadie consiguió escribir nada');
    }

    public function test_el_token_compartido_tampoco_reparte_permisos(): void
    {
        config(['admin.api_token' => 'token-de-automatizacion']);

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION, 'permission' => CrmPermission::CAJA_MANAGE, 'granted' => true,
        ], ['Authorization' => 'Bearer token-de-automatizacion'])->assertStatus(403);
    }

    public function test_super_admin_no_puede_modificarse_a_si_mismo(): void
    {
        // Es la cuenta que recupera el sistema si alguien se equivoca
        // repartiendo permisos: dejar que se cierre la puerta desde dentro
        // convertiría un error en un bloqueo irreversible.
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_SUPER_ADMIN,
            'permission' => CrmPermission::BILLING_MANAGE,
            'granted' => false,
        ], $super)->assertStatus(422)->assertJsonPath('code', 'role_locked');
    }

    // ── Vocabulario cerrado ─────────────────────────────────────────────────

    public function test_no_se_aceptan_roles_ni_permisos_inventados(): void
    {
        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN));

        $this->putJson('/api/admin/roles/permissions', [
            'role' => 'Rol Inventado', 'permission' => CrmPermission::CAJA_SELL, 'granted' => true,
        ], $super)->assertStatus(422)->assertJsonValidationErrors('role');

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION, 'permission' => 'permiso.inventado', 'granted' => true,
        ], $super)->assertStatus(422)->assertJsonValidationErrors('permission');
    }

    // ── Auditoría ───────────────────────────────────────────────────────────

    public function test_el_cambio_de_permisos_queda_auditado_con_su_autor(): void
    {
        $super = $this->admin(Admin::ROLE_SUPER_ADMIN, 'Supervisora');

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_RECEPCION, 'permission' => CrmPermission::CAJA_SELL, 'granted' => false,
        ], $this->actingAsAdmin($super))->assertOk();

        $log = AuditLog::where('module', 'Roles')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Supervisora', $log->actor_name);
        $this->assertStringContainsString('Revocado caja.sell a Recepción', $log->summary);
    }
}
