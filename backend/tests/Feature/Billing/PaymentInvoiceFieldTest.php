<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentInvoiceFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_index_includes_invoice_summary(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 119000,
            'method' => 'cash', 'reference' => 'R-1', 'status' => 'paid', 'paid_at' => now(),
        ]);
        ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => 'validated', 'full_number' => 'SETP990', 'cufe' => 'cufe-1', 'total' => 119000,
        ]);

        $res = $this->adminGetJson('/api/payments')->assertOk();
        $res->assertJsonPath('data.0.invoice_summary.status', 'validated');
        $res->assertJsonPath('data.0.invoice_summary.full_number', 'SETP990');
        $res->assertJsonMissingPath('data.0.electronic_invoice'); // relación oculta
    }

    public function test_payment_without_invoice_returns_null_summary(): void
    {
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'reference' => 'R-2', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->adminGetJson('/api/payments')->assertOk()
            ->assertJsonPath('data.0.invoice_summary', null);
    }

    public function test_caja_sale_includes_invoice_field(): void
    {
        $sale = ProductSale::create([
            'channel' => 'pos', 'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 10000, 'total' => 10000,
        ]);
        ElectronicInvoice::create([
            'source_type' => ProductSale::class, 'source_id' => $sale->id, 'type' => 'invoice',
            'status' => 'pending', 'total' => 10000,
        ]);

        $res = $this->adminGetJson('/api/admin/caja/sales')->assertOk();
        $res->assertJsonPath('data.0.invoice.status', 'pending');
    }
}
