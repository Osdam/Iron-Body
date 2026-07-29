<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * billing:freeze-pending-invoices — congela el payload de las facturas pending
 * legacy SIN alterar sus importes.
 *
 * Reproduce el caso real de producción: 8 facturas pending de pagos de $80.000
 * con subtotal 67.226,89 + IVA 12.773,11. Deben seguir totalizando 80.000
 * después de congelarse, pase lo que pase con el catálogo.
 */
class FreezePendingInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['billing.reconciliation_guard.tolerance' => 1]);
    }

    private function legacyPendingInvoice(float $paid = 80000): ElectronicInvoice
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'benefits' => '', 'active' => true,
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => $paid,
            'method' => 'wompi', 'reference' => 'LEG-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ]);

        return ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => InvoiceStatus::PENDING->value,
            'subtotal' => 67226.89, 'tax_total' => 12773.11, 'discount' => 0, 'total' => 80000,
            'currency' => 'COP',
            'customer_doc_type' => '13', 'customer_doc_number' => '222222222222',
            'customer_name' => 'Consumidor final', 'is_final_consumer' => true,
        ]);
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $invoice = $this->legacyPendingInvoice();

        $this->artisan('billing:freeze-pending-invoices')
            ->assertSuccessful();

        $this->assertNull($invoice->fresh()->payload_snapshot);
        Http::assertNothingSent();
    }

    public function test_apply_freezes_payload_preserving_amounts(): void
    {
        $invoice = $this->legacyPendingInvoice();

        $this->artisan('billing:freeze-pending-invoices --apply')
            ->assertSuccessful();

        $invoice->refresh();

        // Importes INTACTOS.
        $this->assertSame(67226.89, (float) $invoice->subtotal);
        $this->assertSame(12773.11, (float) $invoice->tax_total);
        $this->assertSame(80000.0, (float) $invoice->total);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);

        // Payload congelado y coherente con esos importes.
        $payload = $invoice->payload_snapshot;
        $this->assertIsArray($payload);
        $this->assertSame('67226.89', $payload['items'][0]['price']);
        $this->assertSame('19.00', $payload['items'][0]['taxes'][0]['rate']);
        $this->assertSame('80000.00', $payload['payment_details'][0]['amount']);
        $this->assertFalse($payload['send_email']);

        Http::assertNothingSent();
    }

    /** Congelada la factura, un cambio de catálogo ya no puede alterarla. */
    public function test_frozen_invoice_survives_catalog_change(): void
    {
        $invoice = $this->legacyPendingInvoice();
        $this->artisan('billing:freeze-pending-invoices --apply')->assertSuccessful();

        $plan = Plan::first();
        $plan->forceFill(['price' => 200000, 'pricing_mode' => 'base_plus_tax'])->save();

        $this->assertSame('80000.00', $invoice->fresh()->payload_snapshot['payment_details'][0]['amount']);
    }

    public function test_is_idempotent(): void
    {
        $invoice = $this->legacyPendingInvoice();

        $this->artisan('billing:freeze-pending-invoices --apply')->assertSuccessful();
        $first = $invoice->fresh()->payload_snapshot;

        // La segunda pasada ya no la encuentra (tiene snapshot) y no la altera.
        $this->artisan('billing:freeze-pending-invoices --apply')->assertSuccessful();

        $this->assertEquals($first, $invoice->fresh()->payload_snapshot);
    }

    /** Una factura descuadrada contra su pago se RECHAZA, no se congela. */
    public function test_rejects_invoice_that_does_not_match_its_payment(): void
    {
        $invoice = $this->legacyPendingInvoice(paid: 50000); // pago != total factura

        $this->artisan('billing:freeze-pending-invoices --apply')
            ->assertFailed();

        $this->assertNull($invoice->fresh()->payload_snapshot);
        $this->assertSame(80000.0, (float) $invoice->fresh()->total);
    }

    public function test_rejects_internally_unbalanced_invoice(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'cash',
            'reference' => 'BAD-1', 'status' => 'paid', 'paid_at' => now(),
        ]);
        $invoice = ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => InvoiceStatus::PENDING->value,
            'subtotal' => 60000, 'tax_total' => 12773.11, 'discount' => 0, 'total' => 80000,
            'currency' => 'COP',
        ]);

        $this->artisan('billing:freeze-pending-invoices --apply')->assertFailed();

        $this->assertNull($invoice->fresh()->payload_snapshot);
    }

    public function test_does_not_touch_validated_invoices(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'wompi',
            'reference' => 'VAL-1', 'status' => 'paid', 'paid_at' => now(),
        ]);
        $validated = ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => InvoiceStatus::VALIDATED->value,
            'subtotal' => 67226.89, 'tax_total' => 12773.11, 'discount' => 0, 'total' => 80000,
            'currency' => 'COP', 'cufe' => 'CUFE-XYZ', 'full_number' => 'SETP-1',
        ]);

        $this->artisan('billing:freeze-pending-invoices --apply')->assertSuccessful();

        $validated->refresh();
        $this->assertNull($validated->payload_snapshot);
        $this->assertSame(InvoiceStatus::VALIDATED, $validated->status);
        $this->assertSame(80000.0, (float) $validated->total);
        $this->assertSame('CUFE-XYZ', $validated->cufe);
    }

    public function test_can_target_a_single_invoice(): void
    {
        $a = $this->legacyPendingInvoice();
        $b = $this->legacyPendingInvoice();

        $this->artisan("billing:freeze-pending-invoices --apply --invoice-id={$a->id}")
            ->assertSuccessful();

        $this->assertNotNull($a->fresh()->payload_snapshot);
        $this->assertNull($b->fresh()->payload_snapshot);
    }
}
