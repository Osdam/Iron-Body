<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\ElectronicInvoice;
use App\Models\InventoryMovement;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleSplit;
use App\Models\Receivable;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Correcciones de Ventas y de la Caja de Cafetería
 * (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §4).
 *
 * Las pruebas de un hallazgo (H1…H9) dicen qué hacía 2425eef.
 */
class CorreccionesCajaCafeteriaTest extends TestCase
{
    use RefreshDatabase;

    private Admin $super;

    private CashShift $turno;

    private const MOTIVO = 'Corrección registrada en la prueba';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 11:30', 'America/Bogota')->utc());

        $this->super = Admin::create([
            'name' => 'Paola Cafetería',
            'email' => 'cafeteria-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->turno = app(CashShiftService::class)->open($this->super, CashShiftType::PRODUCTS);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(): array
    {
        return $this->actingAsAdmin($this->super);
    }

    private function producto(string $nombre = 'Agua 600 ml', float $precio = 3000, int $stock = 100): Product
    {
        $tarifa = TaxRate::firstOrCreate(
            ['code' => 'EXEMPT'],
            ['name' => 'Exento', 'rate' => 0, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => $nombre, 'category' => 'Cafetería', 'sale_price' => $precio,
            'cost_price' => $precio / 2, 'stock' => $stock, 'min_stock' => 0, 'active' => true,
            'visible_in_app' => true, 'tax_rate_id' => $tarifa->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    private function socio(): Member
    {
        $user = User::create([
            'name' => 'Socia Fiada', 'email' => 'fiada-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Socia Fiada',
            'document_number' => (string) random_int(10000000, 99999999), 'is_staff' => false,
        ]);
    }

    /** @param list<array{0: Product, 1: int}> $lineas */
    private function vender(array $lineas, array $extra = []): ProductSale
    {
        $id = $this->postJson('/api/admin/caja/sales', array_merge([
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lineas),
            'payment_method' => 'cash',
        ], $extra), $this->h())->assertStatus(201)->json('data.id');

        return ProductSale::with('items')->findOrFail($id);
    }

    private function totales(): array
    {
        return app(CashShiftTotalsService::class)->for($this->turno->fresh());
    }

    // ── Anular / cancelar ───────────────────────────────────────────────────

    /** Un pedido pendiente se cancela sin mover existencias (nunca salieron). */
    public function test_cancelar_un_pedido_pendiente(): void
    {
        $agua = $this->producto();
        $pedido = $this->vender([[$agua, 2]], ['paid' => false]);
        $this->assertSame('pending', $pedido->status);

        $this->postJson("/api/admin/caja/sales/{$pedido->id}/cancel", [], $this->h())->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/caja/sales/{$pedido->id}/cancel", ['reason' => 'El cliente ya no lo quiso'], $this->h())
            ->assertOk()->assertJsonPath('already', false)->assertJsonPath('restocked', false)->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(100, $agua->fresh()->stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * Anular una venta cobrada: deja de contar en el turno abierto, el producto
     * vuelve (por defecto) y queda la traza con motivo. Doble anulación:
     * `already`, sin segundo reingreso ni segunda traza. En 2425eef `cancel()`
     * solo cambiaba el estado: sin reingreso, sin motivo, sin traza ni bloqueo.
     */
    public function test_anular_una_venta_cobrada_con_reingreso_es_idempotente(): void
    {
        $agua = $this->producto();
        $venta = $this->vender([[$agua, 3]]);
        $this->assertSame(97, $agua->fresh()->stock);
        $this->assertSame('9000.00', $this->totales()['expected_cash']);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonPath('restocked', true);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonPath('already', true)->assertJsonPath('restocked', false);

        $this->assertSame(100, $agua->fresh()->stock, 'vuelve una sola vez');
        $this->assertSame(1, InventoryMovement::where('origin', 'return')->count());
        $this->assertSame('0.00', $this->totales()['expected_cash']);
        $venta->refresh();
        $this->assertSame('cancelled', $venta->status);
        $this->assertSame('cancelled', $venta->payment_status, 'el guardián de emisión no la factura');
        $trazas = AuditLog::where('entity', 'venta')->where('entity_id', (string) $venta->id)->where('action', 'status')->get();
        $this->assertCount(1, $trazas);
        $this->assertSame(self::MOTIVO, $trazas[0]->metadata['reason']);
    }

    /** Sin reingreso (el producto se perdió): el stock no vuelve. Entregada: por defecto tampoco. */
    public function test_anular_sin_reingreso_y_entregada_por_defecto(): void
    {
        $agua = $this->producto();
        $una = $this->vender([[$agua, 1]]);
        $this->postJson("/api/admin/caja/sales/{$una->id}/cancel", ['reason' => self::MOTIVO, 'restock' => false], $this->h())
            ->assertOk()->assertJsonPath('restocked', false);
        $this->assertSame(99, $agua->fresh()->stock);

        $otra = $this->vender([[$agua, 1]]);
        $this->postJson("/api/admin/caja/sales/{$otra->id}/deliver", [], $this->h())->assertOk();
        $this->postJson("/api/admin/caja/sales/{$otra->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonPath('restocked', false);
        $this->assertSame(98, $agua->fresh()->stock);
    }

    /** Fiada sin abonos: su deuda se anula con ella. Con abonos: 422 hasta anularlos. */
    public function test_anular_una_venta_fiada(): void
    {
        $agua = $this->producto();
        $socio = $this->socio();
        $fiada = $this->vender([[$agua, 2]], ['credit' => true, 'payment_method' => null, 'member_id' => $socio->id]);
        $deuda = Receivable::where('source_id', $fiada->id)->where('source_type', ProductSale::class)->firstOrFail();

        $abono = $this->postJson("/api/admin/receivables/{$deuda->id}/payments", ['amount' => 1000, 'method' => 'cash'], $this->h())->json('data.id');
        $this->postJson("/api/admin/caja/sales/{$fiada->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'receivable_has_payments')->assertJsonPath('receivable_id', $deuda->id);

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'Se anula para deshacer la venta'], $this->h())->assertOk();
        $this->postJson("/api/admin/caja/sales/{$fiada->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonPath('receivable_id', $deuda->id)->assertJsonPath('restocked', true);

        $this->assertSame(Receivable::STATUS_CANCELLED, $deuda->fresh()->status);
        $this->assertSame(100, $agua->fresh()->stock);
        // La deuda de una venta anulada no se reabre ni ofrece hacerlo.
        $this->assertFalse($this->getJson("/api/admin/receivables/{$deuda->id}", $this->h())->json('data.can_reopen'));
        $this->postJson("/api/admin/receivables/{$deuda->id}/reopen", ['reason' => 'Intento de reabrirla'], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'receivable_source_cancelled');
    }

    /** Factura validada: anular y devolver en parte dan 409 `invoice_validated`. */
    public function test_la_factura_validada_bloquea_anular_y_devolver_en_parte(): void
    {
        $agua = $this->producto();
        $venta = $this->vender([[$agua, 2]]);
        ElectronicInvoice::updateOrCreate(
            ['source_type' => $venta->getMorphClass(), 'source_id' => $venta->id, 'type' => 'invoice'],
            ['status' => 'validated', 'full_number' => 'IBFE20', 'total' => '6000.00', 'cufe' => 'cufe-20'],
        );

        $this->postJson("/api/admin/caja/sales/{$venta->id}/cancel", ['reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'invoice_validated');
        $item = $venta->items->first();
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(409)->assertJsonPath('code', 'invoice_validated');
        $this->assertSame('paid', $venta->fresh()->status);
    }

    // ── Devolver ────────────────────────────────────────────────────────────

    /**
     * Devolución total: la venta queda `refunded`, sigue contando en su turno y
     * la salida se resta del turno abierto; el producto vuelve.
     */
    public function test_devolucion_total(): void
    {
        $agua = $this->producto();
        $venta = $this->vender([[$agua, 2]]);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", ['reason' => 'Producto vencido'], $this->adminAs(Admin::ROLE_ADMINISTRADOR))
            ->assertStatus(403);

        $r = $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", ['reason' => 'Producto vencido'], $this->h())
            ->assertStatus(201)->assertJsonPath('data.status', 'refunded');
        $this->assertEquals(6000, $r->json('refund.amount'));
        $this->assertSame($this->turno->id, $r->json('refund.cash_shift_id'));
        $this->assertSame(100, $agua->fresh()->stock);

        $t = $this->totales();
        $this->assertSame('0.00', $t['expected_cash']);
        $this->assertSame(1, $t['operations_count'], 'la venta devuelta sigue siendo una operación del turno');
        $informe = $this->getJson("/api/admin/caja/shifts/{$this->turno->id}", $this->h())->assertOk();
        $this->assertTrue($informe->json('consistency.matches'));
        $this->assertCount(1, $informe->json('refunds'));

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", ['reason' => 'Producto vencido'], $this->h())
            ->assertOk()->assertJsonPath('already', true);
        $this->assertSame(1, CashRefund::count());
    }

    /**
     * Devolución parcial, nunca más de lo vendido; la segunda completa la venta
     * y la suma es exactamente lo cobrado. Con el mismo `client_request_id` no
     * hay segundo reingreso ni segunda salida.
     */
    public function test_devolucion_parcial_sin_pasar_de_lo_vendido(): void
    {
        $agua = $this->producto('Agua', 3000);
        $barra = $this->producto('Barra', 5000);
        $venta = $this->vender([[$agua, 3], [$barra, 1]], ['discount' => 1000]);
        $total = (float) $venta->total;
        $lineaAgua = $venta->items->firstWhere('product_id', $agua->id);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $lineaAgua->id, 'quantity' => 4]],
        ], $this->h())->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_sold');

        $peticion = ['reason' => self::MOTIVO, 'items' => [['item_id' => $lineaAgua->id, 'quantity' => 2]], 'client_request_id' => 'dev-agua-1'];
        $primera = $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", $peticion, $this->h())
            ->assertStatus(201)->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", $peticion, $this->h())
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertSame(99, $agua->fresh()->stock, '97 + 2 devueltas, una sola vez');
        $this->assertSame(2, $lineaAgua->fresh()->refunded_quantity);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $lineaAgua->id, 'quantity' => 2]],
        ], $this->h())->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_sold');

        $segunda = $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", ['reason' => self::MOTIVO], $this->h())
            ->assertStatus(201)->assertJsonPath('data.status', 'refunded');
        $this->assertEqualsWithDelta($total, $primera->json('refund.amount') + $segunda->json('refund.amount'), 0.001,
            'devuelta a trozos, suma exactamente lo cobrado');
        $this->assertSame(2, CashRefund::count());
        $this->assertSame('0.00', $this->totales()['gross_total']);
    }

    /** Una venta fiada se devuelve por abonos: 409. */
    public function test_una_venta_fiada_se_devuelve_por_abonos(): void
    {
        $fiada = $this->vender([[$this->producto(), 1]], ['credit' => true, 'payment_method' => null, 'member_id' => $this->socio()->id]);

        $this->postJson("/api/admin/caja/sales/{$fiada->id}/refund", ['reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'use_receivable_flow');
    }

    // ── Cobrar, entregar y alta ─────────────────────────────────────────────

    /**
     * H1: cobrar un pedido exige el turno de cafetería abierto y lo asigna. En
     * 2425eef se cobraba sin turno y el dinero quedaba fuera de todo arqueo.
     */
    public function test_cobrar_un_pedido_exige_turno_y_lo_asigna(): void
    {
        $agua = $this->producto();
        $pedido = $this->vender([[$agua, 1]], ['paid' => false]);
        app(CashShiftService::class)->close($this->super, CashShiftType::PRODUCTS);

        $this->postJson("/api/admin/caja/sales/{$pedido->id}/pay", ['payment_method' => 'cash'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'no_open_shift');
        $this->assertSame('pending', $pedido->fresh()->status);

        $nuevo = app(CashShiftService::class)->open($this->super, CashShiftType::PRODUCTS);
        $this->postJson("/api/admin/caja/sales/{$pedido->id}/pay", ['payment_method' => 'cash'], $this->h())->assertOk();
        $this->assertSame($nuevo->id, $pedido->fresh()->cash_shift_id);
        $this->assertSame(99, $agua->fresh()->stock);
    }

    /**
     * H2: entregar solo desde `paid` (o fiada, que ya se entregó). En 2425eef
     * pending → delivered contaba en el arqueo sin cobrar ni mover stock, y una
     * anulada volvía a la vida.
     */
    public function test_entregar_solo_lo_cobrado(): void
    {
        $agua = $this->producto();
        $pendiente = $this->vender([[$agua, 1]], ['paid' => false]);
        $this->postJson("/api/admin/caja/sales/{$pendiente->id}/deliver", [], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'sale_not_deliverable');
        $this->assertSame('pending', $pendiente->fresh()->status);
        $this->assertSame('0.00', $this->totales()['gross_total']);

        $this->postJson("/api/admin/caja/sales/{$pendiente->id}/cancel", ['reason' => self::MOTIVO], $this->h())->assertOk();
        $this->postJson("/api/admin/caja/sales/{$pendiente->id}/deliver", [], $this->h())->assertStatus(409);
        $this->assertSame('cancelled', $pendiente->fresh()->status);

        $fiada = $this->vender([[$agua, 1]], ['credit' => true, 'payment_method' => null, 'member_id' => $this->socio()->id]);
        $this->postJson("/api/admin/caja/sales/{$fiada->id}/deliver", [], $this->h())->assertOk()->assertJsonPath('data.status', 'credit');

        $cobrada = $this->vender([[$agua, 1]]);
        $this->postJson("/api/admin/caja/sales/{$cobrada->id}/deliver", [], $this->h())->assertOk()->assertJsonPath('data.status', 'delivered');
    }

    /** El mismo `client_request_id` dos veces es UNA venta y UN descuento de stock. */
    public function test_alta_con_client_request_id_repetido(): void
    {
        $agua = $this->producto();
        $cuerpo = ['items' => [['product_id' => $agua->id, 'quantity' => 2]], 'payment_method' => 'cash', 'client_request_id' => 'venta-xyz'];

        $uno = $this->postJson('/api/admin/caja/sales', $cuerpo, $this->h())->assertStatus(201)->json('data.id');
        $dos = $this->postJson('/api/admin/caja/sales', $cuerpo, $this->h())->assertOk()->assertJsonPath('idempotent_replay', true)->json('data.id');

        $this->assertSame($uno, $dos);
        $this->assertSame(1, ProductSale::count());
        $this->assertSame(98, $agua->fresh()->stock);
    }

    /**
     * H5: el alta es UNA transacción. Sin existencias para cobrar, no queda
     * nada. En 2425eef la venta quedaba creada y `pending` (con su traza) y el
     * cobro fallaba después, fuera de la transacción.
     */
    public function test_el_alta_es_atomica(): void
    {
        $agua = $this->producto('Agua', 3000, 1);
        $barra = $this->producto('Barra', 5000, 5);
        // La primera pasada de existencias ve stock suficiente; entre esa
        // comprobación y el cobro, otro mostrador se lleva la última agua.
        $this->app->instance(InventoryService::class, new class extends InventoryService
        {
            public function assertAvailability(array $lines): void
            {
                parent::assertAvailability($lines);
                Product::where('name', 'Agua')->update(['stock' => 0]);
            }
        });

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $barra->id, 'quantity' => 1], ['product_id' => $agua->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ], $this->h())->assertStatus(422)->assertJsonPath('error', 'insufficient_stock');

        $this->assertSame(0, ProductSale::count(), 'ni venta pendiente ni traza a medias');
        $this->assertSame(0, AuditLog::where('entity', 'venta')->count());
        $this->assertSame(5, $barra->fresh()->stock);
    }

    /** H8: el código sale del id (único), no de max(id)+1. */
    public function test_el_codigo_de_la_venta_sale_de_su_id(): void
    {
        $agua = $this->producto();
        $a = $this->vender([[$agua, 1]]);
        // Un hueco en la secuencia (una alta que se deshizo, o una fila borrada).
        DB::table('product_sale_items')->where('product_sale_id', $a->id)->delete();
        ProductSale::whereKey($a->id)->delete();
        $b = $this->vender([[$agua, 1]]);

        // En 2425eef: max(id)+1 = id de A → «V-000001» para la venta con id 2.
        $this->assertSame('V-'.str_pad((string) $b->id, 6, '0', STR_PAD_LEFT), $b->code);
        $this->assertNotSame($a->code, $b->code);
    }

    /**
     * H6: los productos se bloquean en orden de id, no en el de las líneas: dos
     * ventas con los mismos productos al revés ya no se esperan en sentido
     * contrario. En 2425eef el orden era el de las líneas.
     */
    public function test_los_productos_se_bloquean_en_orden_de_id(): void
    {
        $a = $this->producto('A', 1000);
        $b = $this->producto('B', 1000);
        $orden = [];
        DB::listen(function ($q) use (&$orden): void {
            // Lectura de un producto por id: find() y, con `for update` en
            // PostgreSQL, el bloqueo (en SQLite la cláusula no existe).
            if (preg_match('/^select \* from "products" where "products"\."id" = \? and "products"\."deleted_at" is null limit 1( for update)?$/', $q->sql)) {
                $orden[] = (int) $q->bindings[0];
            }
        });

        $this->vender([[$b, 1], [$a, 1]]);

        // Las cuatro últimas lecturas son las del descuento de stock (leer y
        // bloquear cada producto); antes van la cotización y la comprobación.
        $descuento = array_values(array_unique(array_slice($orden, -4)));
        $this->assertSame([$a->id, $b->id], $descuento);
    }

    /**
     * H7: si otra persona CIERRA el turno entre la comprobación rápida y el
     * alta, la venta no queda apuntada a un cierre que no la cuenta: el turno
     * se relee dentro de la transacción (`lockOpen`) y responde 409, sin crear
     * nada. En 2425eef la venta se creaba con el turno ya cerrado (201).
     */
    public function test_un_cierre_entre_la_comprobacion_y_el_alta_no_deja_ventas_fuera_del_arqueo(): void
    {
        $agua = $this->producto();
        $this->app->instance(CashShiftService::class, new class(app(CashShiftTotalsService::class)) extends CashShiftService
        {
            public function requireOpen(CashShiftType $type): CashShift
            {
                $turno = parent::requireOpen($type);
                // Justo después de comprobarlo, otra persona lo cierra.
                CashShift::whereKey($turno->id)->update(['status' => 'closed', 'closed_at' => now()]);

                return $turno;
            }
        });

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]], 'payment_method' => 'cash',
        ], $this->h())->assertStatus(409)->assertJsonPath('code', 'no_open_shift');

        $this->assertSame(0, ProductSale::count());
        $this->assertSame(100, $agua->fresh()->stock);
    }

    // ── Medio de pago e informe ─────────────────────────────────────────────

    /** Corregir el medio: de mixto a efectivo borra las partes; idempotente. */
    public function test_corregir_el_medio_de_una_venta(): void
    {
        $agua = $this->producto('Agua', 10000);
        $venta = $this->vender([[$agua, 1]], ['payment_method' => 'mixed', 'splits' => [
            ['method' => 'cash', 'amount' => 4000], ['method' => 'card', 'amount' => 6000],
        ]]);
        $this->assertSame('4000.00', $this->totales()['cash_total']);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/method", ['method' => 'card', 'reason' => 'Fue todo con tarjeta'], $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertStatus(403);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/method", ['method' => 'card', 'reason' => 'Fue todo con tarjeta'], $this->h())
            ->assertOk()->assertJsonPath('already', false)->assertJsonPath('data.payment_method', 'card');
        $this->postJson("/api/admin/caja/sales/{$venta->id}/method", ['method' => 'card', 'reason' => 'Fue todo con tarjeta'], $this->h())
            ->assertOk()->assertJsonPath('already', true);

        $this->assertSame(0, ProductSaleSplit::where('product_sale_id', $venta->id)->count());
        $t = $this->totales();
        $this->assertSame('0.00', $t['cash_total']);
        $this->assertSame('10000.00', $t['card_total']);
    }

    /**
     * H9: el informe del turno de cafetería lista los abonos que su total ya
     * sumaba. En 2425eef no los listaba y el contraste salía «descuadrado».
     */
    public function test_el_informe_de_cafeteria_lista_sus_abonos(): void
    {
        $agua = $this->producto();
        $fiada = $this->vender([[$agua, 1]], ['credit' => true, 'payment_method' => null, 'member_id' => $this->socio()->id]);
        $deuda = Receivable::where('source_type', ProductSale::class)->where('source_id', $fiada->id)->firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", ['amount' => 3000, 'method' => 'cash'], $this->h())->assertStatus(201);
        app(CashShiftService::class)->close($this->super, CashShiftType::PRODUCTS);

        $informe = $this->getJson("/api/admin/caja/shifts/{$this->turno->id}", $this->h())->assertOk();
        $this->assertContains('receivable_payment', array_column($informe->json('transactions'), 'source_type'));
        $this->assertTrue($informe->json('consistency.matches'));
        $this->assertEquals(3000, $informe->json('consistency.frozen_total'));
        $this->assertSame(200, $this->get("/api/admin/caja/shifts/{$this->turno->id}/pdf", $this->h())->getStatusCode());
    }

    /** La vista previa enseña existencias, importe devolvible y bloqueos, sin escribir. */
    public function test_la_vista_previa_de_una_venta(): void
    {
        $agua = $this->producto();
        $venta = $this->vender([[$agua, 2]]);

        $p = $this->getJson("/api/admin/caja/sales/{$venta->id}/correction-preview?action=refund", $this->h())->assertOk()->json();
        $this->assertTrue($p['allowed']);
        $this->assertEquals(6000, $p['effects']['refund_amount']);
        $this->assertSame($this->turno->id, $p['effects']['refund']['shift_id']);
        $this->assertSame(2, $p['effects']['stock'][0]['refundable_quantity']);

        $p = $this->getJson("/api/admin/caja/sales/{$venta->id}/correction-preview?action=cancel", $this->h())->assertOk()->json();
        $this->assertTrue($p['allowed']);
        $this->assertTrue($p['effects']['restock_default']);
        $this->assertSame(98, $agua->fresh()->stock);
        $this->assertSame(0, AuditLog::where('action', 'status')->count());
    }
}
