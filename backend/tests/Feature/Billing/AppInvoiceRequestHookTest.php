<?php

namespace Tests\Feature\Billing;

use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\Plan;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\PaymentMembershipActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * La app puede SOLICITAR factura electrónica al pagar (metadata.wants_invoice).
 * En ese caso, al aprobarse el pago se FUERZA la emisión a Factus aunque el flag
 * global auto_emit esté apagado. Sin la solicitud, se respeta auto_emit (off).
 */
class AppInvoiceRequestHookTest extends TestCase
{
    use RefreshDatabase;

    private function approvedTx(array $metadata): PaymentTransaction
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return PaymentTransaction::create([
            'reference'       => 'IRON-'.uniqid(),
            'idempotency_key' => 'IDEM-'.uniqid(),
            'provider'        => 'wompi',
            'status'    => PaymentTransaction::STATUS_APPROVED,
            'amount'    => 100000,
            'currency'  => 'COP',
            'user_id'   => $user->id,
            'plan_id'   => $plan->id,
            'paid_at'   => now(),
            'metadata'  => $metadata,
        ]);
    }

    public function test_wants_invoice_forces_emission_even_with_auto_emit_off(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.memberships' => false]);
        Queue::fake();

        $tx = $this->approvedTx(['wants_invoice' => true, 'invoice_email' => 'cliente@correo.com']);
        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
    }

    public function test_without_request_respects_auto_emit_off(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.memberships' => false]);
        Queue::fake();

        $tx = $this->approvedTx([]); // el cliente NO solicitó factura
        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        Queue::assertNotPushed(EmitElectronicInvoiceJob::class);
    }
}
