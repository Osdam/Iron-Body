<?php

namespace Tests\Feature\Billing;

use App\Jobs\SendElectronicInvoiceEmailJob;
use App\Mail\ElectronicInvoiceMail;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendElectronicInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.customer_email_delivery.enabled' => true]);
    }

    private function invoice(array $attrs = []): ElectronicInvoice
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();
        $p = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 119000,
            'method' => 'cash', 'reference' => 'PAY-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ]);

        return ElectronicInvoice::create(array_merge([
            'source_type' => Payment::class,
            'source_id'   => $p->id,
            'type'        => 'invoice',
            'status'      => 'validated',
            'currency'    => 'COP',
            'subtotal'    => 100000, 'tax_total' => 19000, 'total' => 119000,
            'full_number' => 'SETP990000001', 'cufe' => 'cufe-x', 'validated_at' => now(),
            'customer_name' => 'Cliente Demo', 'customer_doc_number' => '222222222222',
            'customer_email' => 'cliente@example.com',
        ], $attrs));
    }

    public function test_does_not_send_when_no_valid_email(): void
    {
        Mail::fake();
        $inv = $this->invoice(['customer_email' => null]);

        SendElectronicInvoiceEmailJob::dispatchSync($inv->id);

        Mail::assertNothingSent();
        $this->assertNull($inv->fresh()->customer_email_sent_at);
        $this->assertSame('failed', $inv->fresh()->customer_email_status);
        // La factura sigue intacta.
        $this->assertSame('validated', $inv->fresh()->status->value);
    }

    public function test_sends_when_validated_and_email_valid(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        SendElectronicInvoiceEmailJob::dispatchSync($inv->id);

        Mail::assertSent(ElectronicInvoiceMail::class, function ($mail) use ($inv) {
            return $mail->hasTo('cliente@example.com') && $mail->invoice->id === $inv->id;
        });
        $this->assertNotNull($inv->fresh()->customer_email_sent_at);
        $this->assertSame('sent', $inv->fresh()->customer_email_status);
    }

    public function test_does_not_block_invoice_when_mail_fails(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $inv = $this->invoice();

        SendElectronicInvoiceEmailJob::dispatchSync($inv->id);

        $fresh = $inv->fresh();
        $this->assertSame('validated', $fresh->status->value); // No se revierte.
        $this->assertNull($fresh->customer_email_sent_at);
        $this->assertSame('failed', $fresh->customer_email_status);
    }

    public function test_does_not_resend_when_already_sent(): void
    {
        Mail::fake();
        $inv = $this->invoice(['customer_email_sent_at' => now(), 'customer_email_status' => 'sent']);

        SendElectronicInvoiceEmailJob::dispatchSync($inv->id);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_feature_disabled(): void
    {
        config(['billing.customer_email_delivery.enabled' => false]);
        Mail::fake();
        $inv = $this->invoice();

        SendElectronicInvoiceEmailJob::dispatchSync($inv->id);

        Mail::assertNothingSent();
    }
}
