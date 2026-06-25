<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualPaymentInvoiceHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_paid_payment_enqueues_invoice(): void
    {
        config(['billing.enabled' => false]);
        $user = User::factory()->create();
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);

        $res = $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount'  => 100000,
            'method'  => 'cash',
            'status'  => 'paid',
        ]);

        $res->assertCreated();
        $this->assertSame(1, ElectronicInvoice::where('source_type', \App\Models\Payment::class)
            ->where('source_id', $res->json('id'))->count());
    }

    public function test_payment_succeeds_even_if_factus_fails(): void
    {
        // Factus encendido pero caído: el cobro/registro NO debe fallar.
        config(['billing.enabled' => true, 'queue.default' => 'sync']);
        Http::fake([
            '*/oauth/token'       => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'down'], 500),
        ]);

        $user = User::factory()->create();
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);

        $res = $this->adminPostJson('/api/payments', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount'  => 100000,
            'method'  => 'cash',
            'status'  => 'paid',
        ]);

        // El pago se registró pese al fallo de facturación (best-effort).
        $res->assertCreated();
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'status' => 'paid']);
        // La factura quedó registrada (en error o pending), nunca rompió el pago.
        $this->assertSame(1, ElectronicInvoice::where('source_id', $res->json('id'))->count());
    }
}
