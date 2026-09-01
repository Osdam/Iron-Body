<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Admin;
use App\Models\ElectronicInvoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Autorización de la superficie FISCAL del CRM.
 *
 * Emitir un comprobante ante la DIAN y cambiar la tarifa de IVA de un producto
 * son actos con efecto económico y tributario. Ambos exigían únicamente
 * credencial administrativa: con la sesión de Recepción se podía emitir una
 * factura real de cualquier venta —y luego no poder anularla, porque la nota
 * crédito sí comprobaba el rol— y cambiar lo que Caja cobra.
 */
class BillingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ninguna de estas pruebas debe alcanzar la red: si una lo intenta, es
        // que la autorización la dejó pasar cuando no debía.
        Http::preventStrayRequests();
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => "Test {$role}",
            'email' => 'test-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Bebidas',
            'sale_price' => 3000, 'cost_price' => 1200,
            'stock' => 10, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
        ]);
    }

    private function taxRate(): TaxRate
    {
        return TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );
    }

    // ── Emisión de comprobantes ─────────────────────────────────────────────

    public function test_recepcion_no_puede_emitir_una_factura_electronica(): void
    {
        $this->postJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'product_sale',
            'source_id' => 1,
        ], $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'billing.manage');
    }

    public function test_administrativo_tampoco_puede_emitir(): void
    {
        $this->postJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'product_sale',
            'source_id' => 1,
        ], $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'billing.manage');
    }

    public function test_reemitir_y_sincronizar_tambien_exigen_permiso(): void
    {
        // Hace falta un comprobante REAL: la resolución del modelo de ruta corre
        // antes que la autorización, así que con un id inexistente la respuesta
        // sería 404 y no probaría nada sobre el permiso.
        $sale = ProductSale::create([
            'channel' => 'pos', 'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 3000, 'discount' => 0, 'total' => 3000,
        ]);
        $invoice = ElectronicInvoice::create([
            'source_type' => $sale->getMorphClass(),
            'source_id' => $sale->id,
            'type' => InvoiceType::INVOICE->value,
            'status' => InvoiceStatus::ERROR->value,
            'total' => 3000,
        ]);

        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        // Reintentar es volver a emitir: mismo acto fiscal, mismo permiso.
        $this->postJson("/api/admin/electronic-invoices/{$invoice->id}/retry", [], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        $this->postJson("/api/admin/electronic-invoices/{$invoice->id}/sync", [], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        $this->postJson("/api/admin/electronic-invoices/{$invoice->id}/credit-note", [], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');
    }

    public function test_el_token_compartido_no_puede_emitir(): void
    {
        // Un secreto estático sin persona detrás no firma documentos fiscales.
        config(['admin.api_token' => 'token-de-automatizacion-para-pruebas']);

        $this->postJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'product_sale',
            'source_id' => 1,
        ], ['Authorization' => 'Bearer token-de-automatizacion-para-pruebas'])
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'billing.manage');
    }

    // ── Tarifas y modo de precio ────────────────────────────────────────────

    public function test_recepcion_no_puede_cambiar_el_iva_de_un_producto(): void
    {
        $product = $this->product();
        $rate = $this->taxRate();
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        $this->putJson("/api/admin/billing/products/{$product->id}/tax-rate",
            ['tax_rate_id' => $rate->id], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        $this->putJson("/api/admin/billing/products/{$product->id}/pricing-mode",
            ['pricing_mode' => 'tax_exclusive'], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        // Alcanza al catálogo entero de una sola llamada.
        $this->postJson('/api/admin/billing/products/bulk-tax',
            ['tax_rate_id' => $rate->id], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        $fresh = $product->fresh();
        $this->assertNull($fresh->tax_rate_id, 'la tarifa no se tocó');
    }

    public function test_recepcion_no_puede_cambiar_el_precio_fiscal_de_un_plan(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30]);
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        $this->putJson("/api/admin/billing/plans/{$plan->id}/tax-rate",
            ['tax_rate_id' => $this->taxRate()->id], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');

        $this->putJson("/api/admin/billing/plans/{$plan->id}/pricing-mode",
            ['pricing_mode' => 'tax_exclusive'], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'billing.manage');
    }

    // ── Quien sí debe poder, puede ──────────────────────────────────────────

    public function test_administrador_si_puede_cambiar_la_tarifa(): void
    {
        $product = $this->product();
        $rate = $this->taxRate();

        $this->putJson("/api/admin/billing/products/{$product->id}/tax-rate",
            ['tax_rate_id' => $rate->id],
            $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRADOR)))
            ->assertOk();

        $this->assertSame($rate->id, $product->fresh()->tax_rate_id);
    }

    public function test_el_mapa_de_permisos_de_facturacion(): void
    {
        $this->assertTrue(CrmPermission::allows($this->admin(Admin::ROLE_SUPER_ADMIN), CrmPermission::BILLING_MANAGE));
        $this->assertTrue(CrmPermission::allows($this->admin(Admin::ROLE_ADMINISTRADOR), CrmPermission::BILLING_MANAGE));
        $this->assertFalse(CrmPermission::allows($this->admin(Admin::ROLE_RECEPCION), CrmPermission::BILLING_MANAGE));
        $this->assertFalse(CrmPermission::allows($this->admin(Admin::ROLE_ADMINISTRATIVO), CrmPermission::BILLING_MANAGE));
        $this->assertFalse(CrmPermission::allows(null, CrmPermission::BILLING_MANAGE));
    }
}
