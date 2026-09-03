<?php

namespace Tests\Feature\Audit;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La traza de lo que mueve dinero, ahora escrita por el servidor.
 *
 * El fallo medido en producción: el CRM dejaba la traza con un segundo
 * `POST /api/admin/audit-logs`, y recepción recibía 403 porque escribir en el
 * dominio `audit` exige `roles.manage`. La llamada iba en un `tap()` sin
 * manejador de error, así que fallaba callando. Las ventas del Super Admin
 * quedaban auditadas y las de recepción no: la traza dependía de quién cobrara.
 *
 * Lo que se fija aquí es que ya no depende de nadie ni de una segunda petición,
 * y —tan importante— que no se inventa: si la operación no ocurre, no hay traza.
 */
class FinancialAuditTest extends TestCase
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

    private function producto(array $over = []): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create(array_merge([
            'name' => 'Agua 600 ml',
            'category' => 'Cafetería',
            'sale_price' => 3000,
            'cost_price' => 1200,
            'stock' => 10,
            'min_stock' => 1,
            'active' => true,
            'visible_in_app' => true,
            'tax_rate_id' => $rate->id,
            'pricing_mode' => 'legacy_inclusive',
        ], $over));
    }

    private function vender(array $headers, ?Product $product = null, array $over = []): TestResponse
    {
        $product ??= $this->producto();

        return $this->postJson('/api/admin/caja/sales', array_merge([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ], $over), $headers);
    }

    private function cobrar(array $headers, array $over = []): TestResponse
    {
        $user = User::factory()->create();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '']);

        return $this->postJson('/api/payments', array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            'method' => 'cash',
            'status' => 'paid',
        ], $over), $headers);
    }

    // ── Recepción deja traza, que era lo roto ───────────────────────────────

    public function test_una_venta_de_recepcion_deja_exactamente_una_traza(): void
    {
        $admin = $this->recepcion();
        $headers = $this->actingAsAdmin($admin);
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->vender($headers)->assertStatus(201);

        $logs = AuditLog::query()->where('entity', 'venta')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $sale = ProductSale::query()->firstOrFail();
        $this->assertSame((string) $admin->id, $log->actor_id, 'el actor es quien cobró de verdad');
        $this->assertSame($admin->name, $log->actor_name);
        $this->assertSame((string) $sale->id, $log->entity_id);
        $this->assertSame('create', $log->action);
        $this->assertSame('Caja', $log->module);
    }

    public function test_un_cobro_de_recepcion_deja_exactamente_una_traza(): void
    {
        $admin = $this->recepcion();
        $headers = $this->actingAsAdmin($admin);
        $this->openCashShift(null, CashShiftType::GYM);

        $this->cobrar($headers)->assertSuccessful();

        $logs = AuditLog::query()->where('entity', 'pago')->get();
        $this->assertCount(1, $logs);

        $payment = Payment::query()->firstOrFail();
        $this->assertSame((string) $admin->id, $logs->first()->actor_id);
        $this->assertSame((string) $payment->id, $logs->first()->entity_id);
        $this->assertSame('create', $logs->first()->action);
    }

    public function test_confirmar_un_cobro_pendiente_deja_traza_del_salto(): void
    {
        // Modificar un cobro exige `payments.cancel`, que recepción NO tiene:
        // puede registrar dinero, no rectificarlo. Lo confirma quien supervisa.
        $this->openCashShift(null, CashShiftType::GYM);
        $supervisor = $this->adminHeaders();

        $this->cobrar($supervisor, ['status' => 'pending'])->assertSuccessful();
        $payment = Payment::query()->firstOrFail();
        AuditLog::query()->delete(); // aislar la traza del cambio de estado

        $this->patchJson("/api/payments/{$payment->id}", ['status' => 'paid'], $supervisor)
            ->assertSuccessful();

        $log = AuditLog::query()->where('entity', 'pago')->sole();
        $this->assertSame('status', $log->action, 'un cambio de estado no es un update cualquiera');
        $this->assertNotNull($log->actor_id, 'la traza del salto tiene responsable');
        $this->assertStringContainsString('pending', $log->summary);
        $this->assertStringContainsString('paid', $log->summary);
    }

    public function test_recepcion_sigue_sin_poder_modificar_un_cobro(): void
    {
        // Que ahora deje traza al registrar no le dio permiso para rectificar.
        $this->openCashShift(null, CashShiftType::GYM);
        $headers = $this->actingAsAdmin($this->recepcion());

        $this->cobrar($headers)->assertSuccessful();
        $payment = Payment::query()->firstOrFail();

        $this->patchJson("/api/payments/{$payment->id}", ['status' => 'cancelled'], $headers)
            ->assertStatus(403);

        $this->assertSame('paid', $payment->fresh()->status);
    }

    // ── Y no se inventa ─────────────────────────────────────────────────────

    public function test_una_venta_rechazada_no_deja_traza(): void
    {
        $admin = $this->recepcion();
        $headers = $this->actingAsAdmin($admin);
        $this->openCashShift(null, CashShiftType::PRODUCTS);
        $bloqueado = $this->producto(['name' => 'Sin tarifa', 'tax_rate_id' => null]);

        $this->vender($headers, $bloqueado)->assertStatus(422);

        $this->assertSame(0, ProductSale::query()->count());
        $this->assertSame(0, AuditLog::query()->where('entity', 'venta')->count(),
            'una venta que no existe no puede tener traza');
    }

    public function test_sin_turno_abierto_no_hay_venta_ni_traza(): void
    {
        $headers = $this->actingAsAdmin($this->recepcion());

        $this->vender($headers)->assertStatus(409);

        $this->assertSame(0, AuditLog::query()->where('entity', 'venta')->count());
    }

    public function test_quien_no_puede_vender_no_genera_traza(): void
    {
        // El bloqueo de permisos sigue antes que todo lo demás: ni venta ni
        // auditoría. Una traza de un intento rechazado ensuciaría el registro.
        // Administrativo no tiene ningún permiso de caja.
        $administrativo = Admin::create([
            'name' => 'Administrativo',
            'email' => 'adm-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_ADMINISTRATIVO,
            'status' => 'active',
        ]);

        $this->vender($this->actingAsAdmin($administrativo))->assertStatus(403);

        $this->assertSame(0, ProductSale::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    // ── Sin duplicar y sin subir privilegios ────────────────────────────────

    public function test_dos_ventas_dejan_dos_trazas_no_cuatro(): void
    {
        $headers = $this->actingAsAdmin($this->recepcion());
        $this->openCashShift(null, CashShiftType::PRODUCTS);
        $product = $this->producto();

        $this->vender($headers, $product)->assertStatus(201);
        $this->vender($headers, $product)->assertStatus(201);

        $this->assertSame(2, AuditLog::query()->where('entity', 'venta')->count());
    }

    public function test_recepcion_sigue_sin_poder_escribir_auditoria_a_mano(): void
    {
        // El arreglo NO consistió en darle permiso: la traza la escribe el
        // servidor. Si esto empezara a devolver 201, se habría concedido
        // `roles.manage` por la puerta de atrás.
        $this->postJson('/api/admin/audit-logs', [
            'action' => 'create',
            'module' => 'Caja',
            'entity' => 'venta',
        ], $this->actingAsAdmin($this->recepcion()))->assertStatus(403);
    }

    public function test_recepcion_no_gano_ningun_permiso(): void
    {
        $admin = $this->recepcion();

        foreach (['roles.manage', 'cash.products.manage', 'cash.gym.manage', 'audit.view'] as $permiso) {
            $this->assertFalse(
                CrmPermission::allows($admin, $permiso),
                "recepción no debe tener {$permiso}",
            );
        }
    }

    public function test_un_super_admin_sigue_dejando_traza(): void
    {
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->vender($this->adminHeaders())->assertStatus(201);

        $this->assertSame(1, AuditLog::query()->where('entity', 'venta')->count());
    }

    // ── Contenido de la traza ───────────────────────────────────────────────

    public function test_la_traza_no_guarda_el_objeto_entero(): void
    {
        // El CRM mandaba `metadata: { payment }` con el pago completo. Server
        // side se guarda lo justo para reconstruir qué pasó.
        $headers = $this->actingAsAdmin($this->recepcion());
        $this->openCashShift(null, CashShiftType::GYM);

        $this->cobrar($headers)->assertSuccessful();

        $meta = AuditLog::query()->where('entity', 'pago')->sole()->metadata;
        $this->assertSame(['amount', 'method', 'status', 'plan_id', 'cash_shift_id'], array_keys($meta));
    }

    public function test_la_traza_de_la_venta_identifica_la_operacion(): void
    {
        $headers = $this->actingAsAdmin($this->recepcion());
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->vender($headers)->assertStatus(201);

        $log = AuditLog::query()->where('entity', 'venta')->sole();
        $sale = ProductSale::query()->firstOrFail();
        $this->assertSame($sale->code, $log->target_name);
        $this->assertSame((string) $sale->total, $log->metadata['total']);
    }
}
