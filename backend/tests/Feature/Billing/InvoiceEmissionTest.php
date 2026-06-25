<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class InvoiceEmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.credentials' => [
            'username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's',
        ]]);
    }

    private function paidPayment(): Payment
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 100000,
            'method' => 'cash', 'reference' => 'PAY-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    public function test_flag_off_creates_pending_invoice_and_never_calls_factus(): void
    {
        config(['billing.enabled' => false]);
        Queue::fake();
        Http::fake();

        $payment = $this->paidPayment();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment);

        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_enqueue_is_idempotent_per_source_and_type(): void
    {
        config(['billing.enabled' => false]);
        $payment = $this->paidPayment();

        $a = app(InvoicingService::class)->enqueueForPayment($payment);
        $b = app(InvoicingService::class)->enqueueForPayment($payment);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());
    }

    public function test_flag_on_dispatches_emit_job_once(): void
    {
        config(['billing.enabled' => true]);
        Queue::fake();

        $payment = $this->paidPayment();
        app(InvoicingService::class)->enqueueForPayment($payment);

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
    }

    public function test_emit_job_validates_invoice_on_success(): void
    {
        config(['billing.enabled' => true]);
        Http::fake([
            '*/oauth/token'       => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['data' => ['bill' => [
                'id' => 'F123', 'number' => '990000001', 'prefix' => 'SETP',
                'cufe' => 'cufe-abc-123', 'status' => 'Validada',
            ]]], 201),
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
            '*' => Http::response([], 200),
        ]);
        Queue::fake(); // evita auto-ejecución; corremos el job a mano

        $payment = $this->paidPayment();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::VALIDATED, $invoice->status);
        $this->assertSame('cufe-abc-123', $invoice->cufe);
        $this->assertSame('990000001', $invoice->number);
        $this->assertNotNull($invoice->validated_at);
        $this->assertNotNull($invoice->pdf_path); // descargado por número tras validar
    }

    public function test_emit_job_marks_error_and_throws_on_server_error(): void
    {
        config(['billing.enabled' => true]);
        Http::fake([
            '*/oauth/token'       => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'boom'], 500),
        ]);
        Queue::fake();

        $payment = $this->paidPayment();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment);

        $threw = false;
        try {
            app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);
        } catch (RuntimeException) {
            $threw = true; // técnico → relanza para backoff
        }

        $this->assertTrue($threw);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertSame(1, (int) $invoice->retry_count);
    }

    public function test_emit_job_marks_rejected_without_throw_on_validation_error(): void
    {
        config(['billing.enabled' => true]);
        Http::fake([
            '*/oauth/token'       => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'datos inválidos'], 422),
        ]);
        Queue::fake();

        $payment = $this->paidPayment();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']); // no debe lanzar

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REJECTED, $invoice->status);
    }

    public function test_emit_skips_in_production_when_not_ready(): void
    {
        config([
            'billing.enabled' => true,
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.tax_decision_confirmed' => false, // bloqueo tributario
        ]);
        Queue::fake();
        Http::fake();

        $payment = $this->paidPayment();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status); // no emitió
        Http::assertNothingSent();
    }
}
