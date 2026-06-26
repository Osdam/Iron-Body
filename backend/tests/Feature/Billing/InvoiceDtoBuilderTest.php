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

    public function test_price_excluding_tax_adds_tax_on_top(): void
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

        $this->assertEqualsWithDelta(100000, (float) $snap['subtotal'], 0.5);
        $this->assertEqualsWithDelta(19000, (float) $snap['tax_total'], 0.5);
        $this->assertEqualsWithDelta(119000, (float) $snap['total'], 0.5);
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
