<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\InvoiceDtoBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDtoBuilderTest extends TestCase
{
    use AssumesVatResponsibleIssuer;

    protected function setUp(): void
    {
        parent::setUp();
        // Estas pruebas verifican el MOTOR de calculo, no la politica
        // vigente de Iron Body (no responsable de IVA). Ver el trait.
        $this->assumeVatResponsibleIssuer();
    }

    use RefreshDatabase;

    private function consumer(array $overrides = []): array
    {
        return array_merge([
            'doc_type' => '13', 'doc_number' => '222222222222', 'dv' => null,
            'name' => 'Consumidor final', 'legal_name' => 'Consumidor final',
            'email' => null, 'phone' => null, 'address' => null,
            'city_code' => null, 'department_code' => null, 'is_final_consumer' => true,
        ], $overrides);
    }

    private function payment(): Payment
    {
        $user = User::factory()->create();

        return Payment::create([
            'user_id' => $user->id, 'plan_id' => null, 'amount' => 50000,
            'method' => 'cash', 'reference' => 'T-EMAIL', 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    public function test_send_email_true_when_flag_on_and_email_valid(): void
    {
        config(['billing.send_email' => true]);

        $payload = app(InvoiceDtoBuilder::class)
            ->forPayment($this->payment(), $this->consumer(['email' => 'cliente@iron.com']))['payload'];

        $this->assertTrue($payload['send_email']);
    }

    public function test_send_email_false_when_flag_on_but_email_invalid(): void
    {
        config(['billing.send_email' => true]);

        $payload = app(InvoiceDtoBuilder::class)
            ->forPayment($this->payment(), $this->consumer(['email' => 'no-es-correo']))['payload'];

        $this->assertFalse($payload['send_email']);
    }

    public function test_send_email_false_when_flag_off_even_with_valid_email(): void
    {
        config(['billing.send_email' => false]);

        $payload = app(InvoiceDtoBuilder::class)
            ->forPayment($this->payment(), $this->consumer(['email' => 'cliente@iron.com']))['payload'];

        $this->assertFalse($payload['send_email']);
    }

    public function test_price_including_tax_is_split_backwards(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        $plan = Plan::create([
            'name' => 'Premium', 'price' => 119000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 119000,
            'method' => 'cash', 'reference' => 'T-1', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $built = app(InvoiceDtoBuilder::class)->forPayment($payment, $this->consumer());
        $snap = $built['snapshot'];

        $this->assertEqualsWithDelta(100000, (float) $snap['subtotal'], 0.5);
        $this->assertEqualsWithDelta(19000, (float) $snap['tax_total'], 0.5);
        $this->assertEqualsWithDelta(119000, (float) $snap['total'], 0.5);
        $this->assertTrue((bool) $snap['is_final_consumer']);
    }

    /**
     * CAMBIO DE CONTRATO (Pricing V2).
     *
     * Antes, un plan con price_includes_tax=false hacía que un pago de 100.000
     * se facturara por 119.000: se declaraban 19.000 de IVA que nunca se
     * cobraron. Ese era el defecto de origen.
     *
     * Ahora un pago SIN snapshot financiero es siempre legacy_inclusive: el
     * importe cobrado es el bruto y la base se extrae hacia atrás. Da igual cómo
     * esté marcado el plan hoy — lo que manda es lo que efectivamente se cobró.
     *
     * El IVA por encima existe, pero se decide ANTES de cobrar y viaja en el
     * snapshot del pago (ver test_payment_snapshot_base_plus_tax_is_used_as_is).
     */
    public function test_legacy_payment_without_snapshot_never_adds_tax_on_top(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        $plan = Plan::create([
            'name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id, 'price_includes_tax' => false,
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 100000,
            'method' => 'cash', 'reference' => 'T-2', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $snap = app(InvoiceDtoBuilder::class)->forPayment($payment, $this->consumer())['snapshot'];

        // El total facturado NO puede superar lo cobrado.
        $this->assertEqualsWithDelta(100000, (float) $snap['total'], 0.01);
        $this->assertEqualsWithDelta(84033.61, (float) $snap['subtotal'], 0.01);
        $this->assertEqualsWithDelta(15966.39, (float) $snap['tax_total'], 0.01);
    }

    /**
     * Operación NUEVA: el snapshot manda. Base 80.000 + IVA 19% = 95.200, que es
     * exactamente lo que se le cobró al cliente.
     */
    public function test_payment_snapshot_base_plus_tax_is_used_as_is(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true, 'factus_tribute_id' => '01']);
        $plan = Plan::create([
            'name' => 'Premium', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id, 'pricing_mode' => 'base_plus_tax',
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 95200,
            'method' => 'wompi', 'reference' => 'T-V2', 'status' => 'paid', 'paid_at' => now(),
            'base_amount' => 80000, 'tax_amount' => 15200, 'gross_amount' => 95200,
            'discount_amount' => 0, 'tax_rate_id' => $rate->id, 'tax_rate' => 19,
            'pricing_mode' => 'base_plus_tax', 'pricing_rules_version' => 'v2.2026.07',
            'currency' => 'COP', 'priced_at' => now(),
        ]);

        $built = app(InvoiceDtoBuilder::class)->forPayment($payment, $this->consumer());

        $this->assertEqualsWithDelta(80000, (float) $built['snapshot']['subtotal'], 0.01);
        $this->assertEqualsWithDelta(15200, (float) $built['snapshot']['tax_total'], 0.01);
        $this->assertEqualsWithDelta(95200, (float) $built['snapshot']['total'], 0.01);

        // Factus recibe la BASE unitaria y la tasa; el IVA lo calcula él.
        $item = $built['payload']['items'][0];
        $this->assertSame('80000.00', $item['price']);
        $this->assertSame('19.00', $item['taxes'][0]['rate']);
        $this->assertSame('95200.00', $built['payload']['payment_details'][0]['amount']);
    }

    /**
     * Un cambio posterior del catálogo NO puede alterar una operación ya cobrada.
     */
    public function test_catalog_change_after_payment_does_not_alter_invoice(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        $plan = Plan::create([
            'name' => 'Premium', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id, 'pricing_mode' => 'base_plus_tax',
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 95200,
            'method' => 'wompi', 'reference' => 'T-V3', 'status' => 'paid', 'paid_at' => now(),
            'base_amount' => 80000, 'tax_amount' => 15200, 'gross_amount' => 95200,
            'discount_amount' => 0, 'tax_rate_id' => $rate->id, 'tax_rate' => 19,
            'pricing_mode' => 'base_plus_tax', 'pricing_rules_version' => 'v2.2026.07',
            'currency' => 'COP', 'priced_at' => now(),
        ]);

        // El gimnasio sube el precio y cambia la tarifa DESPUÉS del cobro.
        $newRate = TaxRate::create(['code' => 'IVA_5', 'name' => 'IVA 5%', 'rate' => 5, 'active' => true]);
        $plan->forceFill(['price' => 200000, 'tax_rate_id' => $newRate->id])->save();

        $snap = app(InvoiceDtoBuilder::class)->forPayment($payment->fresh(), $this->consumer())['snapshot'];

        $this->assertEqualsWithDelta(80000, (float) $snap['subtotal'], 0.01);
        $this->assertEqualsWithDelta(15200, (float) $snap['tax_total'], 0.01);
        $this->assertEqualsWithDelta(95200, (float) $snap['total'], 0.01);
    }

    public function test_plan_without_tax_rate_has_zero_tax(): void
    {
        $plan = Plan::create(['name' => 'Lite', 'price' => 50000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 50000,
            'method' => 'cash', 'reference' => 'T-3', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $snap = app(InvoiceDtoBuilder::class)->forPayment($payment, $this->consumer())['snapshot'];

        $this->assertEqualsWithDelta(0, (float) $snap['tax_total'], 0.01);
        $this->assertEqualsWithDelta(50000, (float) $snap['total'], 0.5);
    }

    public function test_product_iva_19_included_splits_base_and_tax(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido', 'rate' => 19, 'price_includes_tax' => true, 'active' => true]);
        $product = Product::create([
            'name' => 'Proteína', 'sale_price' => 119000, 'cost_price' => 80000, 'stock' => 10,
            'active' => true, 'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);
        $sale = ProductSale::create([
            'channel' => 'pos', 'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 119000, 'total' => 119000,
        ]);
        $sale->items()->create(['product_id' => $product->id, 'name' => 'Proteína', 'unit_price' => 119000, 'quantity' => 1, 'subtotal' => 119000]);

        $snap = app(InvoiceDtoBuilder::class)->forSale($sale->fresh('items'), $this->consumer())['snapshot'];

        $this->assertEqualsWithDelta(100000, (float) $snap['subtotal'], 0.5);
        $this->assertEqualsWithDelta(19000, (float) $snap['tax_total'], 0.5);
        $this->assertEqualsWithDelta(119000, (float) $snap['total'], 0.5);
    }
}
