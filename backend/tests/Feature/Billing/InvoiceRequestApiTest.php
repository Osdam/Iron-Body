<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Contrato HTTP de la solicitud de factura: `request_invoice` + `invoice_email`.
 *
 * El campo tiene que llegar hasta el hecho económico en TODOS los flujos que
 * registran dinero, no sólo en el de pasarela. Y nunca puede activarse solo:
 * facturar sin que nadie lo pidiera genera un documento fiscal a nombre de un
 * cliente que no lo autorizó.
 */
class InvoiceRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['billing.enabled' => false]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Plan Mensual QA', 'price' => 80000,
            'duration_days' => 30, 'active' => true,
        ]);
    }

    private function product(): Product
    {
        $rate = TaxRate::create([
            'code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido', 'rate' => 19,
            'price_includes_tax' => true, 'active' => true, 'factus_tribute_id' => '01',
        ]);

        return Product::create([
            'name' => 'Proteína', 'sale_price' => 50000, 'cost_price' => 30000,
            'stock' => 10, 'min_stock' => 1, 'active' => true,
            'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);
    }

    // ── Pago manual / efectivo (POST /api/payments) ───────────────────────

    public function test_pago_manual_con_solicitud_guarda_la_intencion(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
            'request_invoice' => true,
            'invoice_email' => 'cliente.real@correo.com',
        ])->assertCreated();

        $p = Payment::latest('id')->first();
        $this->assertTrue($p->invoice_requested);
        $this->assertSame('cliente.real@correo.com', $p->invoice_email);
        $this->assertNotNull($p->invoice_requested_at);
    }

    public function test_pago_manual_sin_el_campo_no_solicita_factura(): void
    {
        $user = User::factory()->create();

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
        ])->assertCreated();

        $p = Payment::latest('id')->first();
        $this->assertFalse($p->invoice_requested);
        $this->assertNull($p->invoice_requested_at);
    }

    public function test_pago_manual_con_request_invoice_false_no_solicita(): void
    {
        $user = User::factory()->create();

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
            'request_invoice' => false,
        ])->assertCreated();

        $this->assertFalse(Payment::latest('id')->first()->invoice_requested);
    }

    public function test_pago_manual_con_solicitud_y_sin_correo_es_rechazado(): void
    {
        $user = User::factory()->create();

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
            'request_invoice' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_email');

        // El pago NO se creó: se avisa antes de registrar dinero a medias.
        $this->assertSame(0, Payment::count());
    }

    public function test_pago_manual_rechaza_correo_sintetico(): void
    {
        $user = User::factory()->create();

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
            'request_invoice' => true,
            'invoice_email' => 'socio-1033751057@ironbody.local',
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_email');

        $this->assertSame(0, Payment::count());
    }

    public function test_confirmar_un_pago_pendiente_puede_solicitar_factura(): void
    {
        $user = User::factory()->create();
        $p = Payment::create([
            'user_id' => $user->id, 'amount' => '80000.00',
            'status' => 'pending', 'method' => 'efectivo',
        ]);

        $this->adminPutJson("/api/payments/{$p->id}", [
            'status' => 'paid',
            'request_invoice' => true,
            'invoice_email' => 'confirmado@correo.com',
        ])->assertOk();

        $p->refresh();
        $this->assertTrue($p->invoice_requested);
        $this->assertSame('confirmado@correo.com', $p->invoice_email);
    }

    // ── Caja / venta de productos ─────────────────────────────────────────

    public function test_venta_de_caja_con_solicitud_guarda_la_intencion(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
            'request_invoice' => true,
            'invoice_email' => 'compra.tienda@correo.com',
        ])->assertCreated();

        $sale = ProductSale::find($res->json('data.id'));
        $this->assertTrue($sale->invoice_requested);
        $this->assertSame('compra.tienda@correo.com', $sale->invoice_email);
        $this->assertNotNull($sale->invoice_requested_at);
    }

    public function test_venta_de_caja_sin_el_campo_no_solicita_factura(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertCreated();

        $this->assertFalse(ProductSale::find($res->json('data.id'))->invoice_requested);
    }

    public function test_venta_de_caja_con_solicitud_sin_correo_es_rechazada(): void
    {
        $product = $this->product();

        $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
            'request_invoice' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_email');

        $this->assertSame(0, ProductSale::count());
    }

    public function test_venta_de_caja_rechaza_correo_sintetico(): void
    {
        $product = $this->product();

        $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
            'request_invoice' => true,
            'invoice_email' => 'x@algo.invalid',
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_email');
    }

    public function test_cobrar_una_venta_pendiente_puede_solicitar_factura(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => false,
        ])->assertCreated();

        $saleId = $res->json('data.id');
        $this->assertFalse(ProductSale::find($saleId)->invoice_requested);

        $this->adminPostJson("/api/admin/caja/sales/{$saleId}/pay", [
            'payment_method' => 'cash',
            'request_invoice' => true,
            'invoice_email' => 'al.cobrar@correo.com',
        ])->assertOk();

        $sale = ProductSale::find($saleId);
        $this->assertTrue($sale->invoice_requested);
        $this->assertSame('al.cobrar@correo.com', $sale->invoice_email);
    }

    public function test_cobrar_dos_veces_no_duplica_la_solicitud(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => false,
        ])->assertCreated();
        $saleId = $res->json('data.id');

        $this->adminPostJson("/api/admin/caja/sales/{$saleId}/pay", [
            'request_invoice' => true, 'invoice_email' => 'uno@correo.com',
        ])->assertOk();
        $fecha = ProductSale::find($saleId)->invoice_requested_at;

        $this->travel(5)->minutes();
        $this->adminPostJson("/api/admin/caja/sales/{$saleId}/pay", [
            'request_invoice' => true, 'invoice_email' => 'dos@correo.com',
        ])->assertOk();

        $sale = ProductSale::find($saleId);
        $this->assertEquals($fecha->timestamp, $sale->invoice_requested_at->timestamp);
        // Y sólo existe UNA solicitud de factura para esa venta.
        $this->assertSame(1, \App\Models\ElectronicInvoice::where('source_type', ProductSale::class)
            ->where('source_id', $saleId)->count());
    }

    // ── Wompi: el contrato de la app ──────────────────────────────────────

    public function test_wompi_rechaza_un_correo_de_facturacion_sintetico(): void
    {
        // No se llega a tokenizar nada: la validación corta antes.
        $member = \App\Models\Member::create([
            'full_name' => 'Cliente QA',
            'document_number' => '1010101010',
            'phone' => '3001112222',
            'status' => \App\Models\Member::STATUS_ACTIVE,
        ]);
        $plan = $this->plan();

        $this->withHeader('Authorization', 'Bearer '.$member->access_hash)
            ->postJson('/api/payments/wompi/card', [
                'plan_id' => $plan->id,
                'card_token' => 'tok_test_123',
                'accepted_terms' => true,
                'accepted_personal_data' => true,
                'request_invoice' => true,
                'invoice_email' => 'socio-1@ironbody.local',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_email');

        Http::assertNothingSent();
    }
}
