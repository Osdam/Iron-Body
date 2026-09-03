<?php

namespace Tests\Feature\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La traza de las mutaciones administrativas, ahora escrita por el servidor.
 *
 * Eran 41 llamadas del navegador a `POST /api/admin/audit-logs`, y ese endpoint
 * exige `roles.manage`: todo el que no fuera Super Admin recibía 403. El fallo
 * ni siquiera se veía, porque el CRM pintaba la entrada en la vista y en
 * `localStorage` ANTES de intentar guardarla y descartaba el error. Quien
 * operaba abría el registro, veía su acción listada, y no estaba en la tabla.
 *
 * Se cubren aquí los dominios migrados. Lo que fijan estas pruebas es que la
 * traza existe con el actor real, que NO se inventa cuando la operación falla,
 * y que nadie ganó permisos por el camino.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function comoRol(string $rol): array
    {
        return $this->actingAsAdmin(Admin::create([
            'name' => "Prueba {$rol}",
            'email' => 'a-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]));
    }

    /**
     * Recepción, que es quien destapó el fallo. Por defecto solo atiende y
     * cobra: inventario y control de acceso los opera Administrador, salvo que
     * se le amplíen desde la pantalla de Roles (como está hoy en producción).
     */
    private function recepcion(): array
    {
        return $this->comoRol(Admin::ROLE_RECEPCION);
    }

    /** Rol con permisos operativos por defecto, y aun así NO Super Admin. */
    private function administrador(): array
    {
        return $this->comoRol(Admin::ROLE_ADMINISTRADOR);
    }

    private function producto(): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Cafetería', 'sale_price' => 3000,
            'cost_price' => 1200, 'stock' => 10, 'min_stock' => 1, 'active' => true,
            'visible_in_app' => true, 'tax_rate_id' => $rate->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    /** La única traza escrita, o falla diciendo cuántas hay. */
    private function traza(string $entity): AuditLog
    {
        return AuditLog::query()->where('entity', $entity)->sole();
    }

    // ── Inventario ──────────────────────────────────────────────────────────

    public function test_una_entrada_de_inventario_deja_traza_con_su_actor(): void
    {
        $h = $this->administrador();
        $p = $this->producto();

        $this->postJson("/api/admin/products/{$p->id}/entry", ['quantity' => 5], $h)
            ->assertStatus(201);

        $log = $this->traza('entrada');
        $this->assertSame('Inventario', $log->module);
        $this->assertNotNull($log->actor_id);
        $this->assertSame('Administrador', $log->actor_role);
        $this->assertSame(15, $log->metadata['stock_after']);
    }

    public function test_una_salida_de_inventario_registra_el_motivo(): void
    {
        // Una salida es una merma: sin motivo y sin autor no se puede auditar.
        $h = $this->administrador();
        $p = $this->producto();

        $this->postJson("/api/admin/products/{$p->id}/exit", [
            'quantity' => 2, 'origin' => 'expiration', 'reason' => 'Producto vencido',
        ], $h)->assertStatus(201);

        $log = $this->traza('salida');
        $this->assertSame('Producto vencido', $log->metadata['reason']);
        $this->assertSame(8, $log->metadata['stock_after']);
    }

    public function test_una_salida_sin_stock_no_deja_traza(): void
    {
        $h = $this->administrador();
        $p = $this->producto();

        $this->postJson("/api/admin/products/{$p->id}/exit", [
            'quantity' => 999, 'origin' => 'expiration', 'reason' => 'Prueba',
        ], $h)->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->count(), 'lo que no ocurrió no se audita');
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_crear_un_producto_deja_una_sola_traza(): void
    {
        $h = $this->administrador();

        $this->postJson('/api/admin/products', [
            'name' => 'Barrita', 'category' => 'Cafetería', 'sale_price' => 5000, 'stock' => 4,
        ], $h)->assertStatus(201);

        $log = $this->traza('producto');
        $this->assertSame('create', $log->action);
        $this->assertSame('Barrita', $log->target_name);
    }

    // ── Miembros ────────────────────────────────────────────────────────────

    public function test_dar_de_baja_a_un_socio_conserva_su_nombre_en_la_traza(): void
    {
        // Se escribe ANTES de borrar: después el nombre ya no existe, y una baja
        // sin nombre no sirve para auditar nada.
        $h = $this->administrador();
        $user = User::factory()->create(['name' => 'Socio Que Se Va']);

        $this->deleteJson("/api/users/{$user->id}", [], $h)->assertNoContent();

        $log = $this->traza('cliente');
        $this->assertSame('delete', $log->action);
        $this->assertSame('Socio Que Se Va', $log->target_name);
        $this->assertSame((string) $user->id, $log->entity_id);
    }

    public function test_la_traza_de_un_socio_no_reproduce_sus_datos_personales(): void
    {
        $h = $this->administrador();
        $user = User::factory()->create(['name' => 'Socio', 'document' => '1234567890']);

        $this->deleteJson("/api/users/{$user->id}", [], $h)->assertNoContent();

        $this->assertStringNotContainsString('1234567890', json_encode($this->traza('cliente')->toArray()));
    }

    // ── Acceso físico ───────────────────────────────────────────────────────

    public function test_cambiar_la_configuracion_del_torniquete_deja_traza(): void
    {
        $h = $this->administrador();

        $this->putJson('/api/turnstile', ['enabled' => true, 'fire_on_entry' => true], $h)
            ->assertOk();

        $log = $this->traza('torniquete');
        $this->assertSame('settings', $log->action);
        $this->assertNotNull($log->actor_id);
    }

    // ── Lo que NO debe auditarse ────────────────────────────────────────────

    public function test_una_lectura_no_deja_traza(): void
    {
        $h = $this->recepcion();
        $this->producto();

        $this->getJson('/api/admin/products', $h)->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_una_operacion_sin_permiso_no_deja_traza(): void
    {
        // El bloqueo va antes: auditar intentos rechazados ensuciaría el
        // registro con cosas que nunca pasaron.
        $administrativo = $this->actingAsAdmin(Admin::create([
            'name' => 'Administrativo', 'email' => 'adm-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRATIVO, 'status' => 'active',
        ]));
        $p = $this->producto();

        $this->postJson("/api/admin/products/{$p->id}/entry", ['quantity' => 5], $administrativo)
            ->assertStatus(403);

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(10, $p->fresh()->stock);
    }

    // ── Sin escalada ────────────────────────────────────────────────────────

    public function test_recepcion_sigue_sin_poder_escribir_auditoria_a_mano(): void
    {
        // El arreglo NO fue dar permisos. Si esto devolviera 201, se habría
        // concedido `roles.manage` por la puerta de atrás.
        $this->postJson('/api/admin/audit-logs', [
            'action' => 'create', 'module' => 'Inventario', 'entity' => 'producto',
        ], $this->recepcion())->assertStatus(403);
    }

    public function test_recepcion_no_gano_ningun_permiso(): void
    {
        $admin = Admin::create([
            'name' => 'R', 'email' => 'r-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);

        foreach (['roles.manage', 'audit.view', 'reports.view'] as $permiso) {
            $this->assertFalse(
                CrmPermission::allows($admin, $permiso),
                "recepción no debe tener {$permiso}",
            );
        }
    }

    // ── Un Super Admin sigue funcionando igual ──────────────────────────────

    public function test_un_super_admin_tambien_deja_traza_server_side(): void
    {
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '']);

        $this->postJson('/api/plans', [
            'name' => 'Trimestral', 'price' => 210000, 'duration_days' => 90, 'benefits' => '', 'active' => true,
        ], $this->adminHeaders())->assertStatus(201);

        $log = $this->traza('plan');
        $this->assertSame('Super Admin', $log->actor_role);
        $this->assertSame('Trimestral', $log->target_name);
    }
}
