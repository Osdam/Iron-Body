<?php

namespace Tests\Feature\Billing;

use App\Jobs\EmitElectronicInvoiceJob;
use App\Jobs\SyncFactusInvoiceStatusJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectronicInvoiceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function payment(array $attrs = []): Payment
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return Payment::create(array_merge([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 119000,
            'method' => 'cash', 'reference' => 'PAY-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ], $attrs));
    }

    private function invoice(array $attrs = []): ElectronicInvoice
    {
        $p = $this->payment();

        return ElectronicInvoice::create(array_merge([
            'source_type' => Payment::class,
            'source_id' => $p->id,
            'type' => 'invoice',
            'status' => 'pending',
            'currency' => 'COP',
            'subtotal' => 100000, 'tax_total' => 19000, 'total' => 119000,
            'customer_name' => 'Cliente Demo', 'customer_doc_number' => '222222222222',
        ], $attrs));
    }

    public function test_index_requires_admin_auth(): void
    {
        $this->getJson('/api/admin/electronic-invoices')->assertStatus(401);
    }

    public function test_index_lists_and_filters_by_status(): void
    {
        $this->invoice(['status' => 'validated', 'cufe' => 'c1']);
        $this->invoice(['status' => 'error']);

        $this->adminGetJson('/api/admin/electronic-invoices')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->adminGetJson('/api/admin/electronic-invoices?status=error')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'error');
    }

    public function test_show_returns_detail_with_logs(): void
    {
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'cufe-x']);
        $inv->logs()->create(['action' => 'emit', 'result' => 'ok', 'message' => 'ok']);

        $res = $this->adminGetJson("/api/admin/electronic-invoices/{$inv->id}")->assertOk();
        $res->assertJsonPath('data.cufe', 'cufe-x');
        $res->assertJsonStructure(['data' => ['logs', 'source', 'fiscal_summary', 'customer_full']]);
    }

    public function test_config_does_not_expose_secrets(): void
    {
        config([
            'billing.credentials.client_secret' => 'SUPERSECRET',
            'billing.credentials.password' => 'PWDLEAK',
            'billing.webhook.secret' => 'WHSECRET',
        ]);

        $res = $this->adminGetJson('/api/admin/electronic-invoices/config')->assertOk();
        $res->assertJsonPath('data.enabled', false);
        $res->assertDontSee('SUPERSECRET');
        $res->assertDontSee('PWDLEAK');
        $res->assertDontSee('WHSECRET');
        $res->assertJsonMissingPath('data.credentials');
        $res->assertJsonStructure(['data' => ['production_ready', 'production_issues']]);
    }

    public function test_manual_emit_is_idempotent(): void
    {
        config(['billing.enabled' => false]);
        Http::fake();
        $payment = $this->payment();
        // La emisión manual exige solicitud expresa del cliente (ver
        // InvoicingService::manualEmit). Sin ella no se encola nada.
        $payment->marcarFacturaSolicitada('cliente.real@correo.com');

        $a = $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'payment', 'source_id' => $payment->id,
        ])->assertStatus(201);

        $b = $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'payment', 'source_id' => $payment->id,
        ])->assertOk();

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());
        Http::assertNothingSent();
    }

    /**
     * Escenario 1: un pago COBRADO que nunca pidió factura sí puede facturarse
     * por la vía administrativa, y la autorización queda sellada.
     *
     * Es lo contrario de lo que este test comprobaba antes. La regla vieja
     * («sin `invoice_requested` no se emite nunca») nació de la solicitud #18,
     * donde el botón encolaba un job condenado; pero cerraba la puerta a un caso
     * legítimo y frecuente: el cliente que pide la factura en el mostrador días
     * después de pagar. Lo que sigue vigente es que no se encola nada condenado
     * —de eso se encargan ahora las comprobaciones de cobro, duplicado y
     * trazabilidad, no un booleano del día de la compra.
     */
    public function test_manual_emit_authorizes_a_source_without_an_explicit_request(): void
    {
        config(['billing.enabled' => true]);
        Http::fake();
        Queue::fake();
        $payment = $this->payment(); // sin marcarFacturaSolicitada()

        $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'payment', 'source_id' => $payment->id,
        ])->assertStatus(201)->assertJsonPath('ok', true);

        $invoice = ElectronicInvoice::where('source_id', $payment->id)->sole();
        $this->assertNotNull($invoice->manual_authorization_at, 'la autorización debe quedar sellada');
        // El hecho histórico NO se toca: el cliente no la pidió al comprar.
        $this->assertFalse((bool) $payment->fresh()->invoice_requested);

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    /** Escenario 4: un pago cancelado no se factura por ninguna vía. */
    public function test_manual_emit_refuses_a_cancelled_payment(): void
    {
        config(['billing.enabled' => true]);
        Queue::fake();
        $payment = $this->payment(['status' => 'cancelled']);

        $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'payment', 'source_id' => $payment->id,
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, ElectronicInvoice::where('source_id', $payment->id)->count());
        Queue::assertNothingPushed();
    }

    /** Escenario 3: con documento fiscal ya emitido, el endpoint responde 409. */
    public function test_manual_emit_conflicts_when_already_invoiced(): void
    {
        config(['billing.enabled' => true]);
        Queue::fake();
        $payment = $this->payment();
        ElectronicInvoice::create([
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => 'validated',
            'full_number' => 'IBFE100',
            'cufe' => 'CUFE-DEMO',
            'total' => $payment->amount,
        ]);

        $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'payment', 'source_id' => $payment->id,
        ])->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_manual_emit_rejects_unknown_source_type(): void
    {
        $this->adminPostJson('/api/admin/electronic-invoices/manual-emit', [
            'source_type' => 'hacker', 'source_id' => 1,
        ])->assertStatus(422);
    }

    public function test_retry_conflicts_when_disabled(): void
    {
        config(['billing.enabled' => false]);
        $inv = $this->invoice(['status' => 'error']);

        $this->adminPostJson("/api/admin/electronic-invoices/{$inv->id}/retry")->assertStatus(409);
    }

    public function test_retry_dispatches_when_enabled_and_state_valid(): void
    {
        config(['billing.enabled' => true]);
        Queue::fake();
        $inv = $this->invoice(['status' => 'error']);

        $this->adminPostJson("/api/admin/electronic-invoices/{$inv->id}/retry")->assertOk();
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
    }

    public function test_retry_conflicts_on_terminal_state(): void
    {
        config(['billing.enabled' => true]);
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'x']);

        $this->adminPostJson("/api/admin/electronic-invoices/{$inv->id}/retry")->assertStatus(409);
    }

    public function test_sync_requires_factus_id_and_dispatches(): void
    {
        config(['billing.enabled' => true]);
        Queue::fake();

        $noId = $this->invoice(['status' => 'processing']);
        $this->adminPostJson("/api/admin/electronic-invoices/{$noId->id}/sync")->assertStatus(422);

        $withId = $this->invoice(['status' => 'processing', 'factus_id' => 'F-1']);
        $this->adminPostJson("/api/admin/electronic-invoices/{$withId->id}/sync")->assertOk();
        Queue::assertPushed(SyncFactusInvoiceStatusJob::class, 1);
    }

    public function test_pdf_downloads_local_file(): void
    {
        Storage::fake('local');
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'x', 'pdf_path' => 'invoices/x/factura.pdf']);
        Storage::disk('local')->put('invoices/x/factura.pdf', '%PDF-1.4 demo');

        $this->adminGetJson("/api/admin/electronic-invoices/{$inv->id}/pdf")->assertOk();
    }

    public function test_pdf_redirects_when_only_url(): void
    {
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'x', 'pdf_url' => 'https://factus.example/x.pdf']);

        $this->get("/api/admin/electronic-invoices/{$inv->id}/pdf", $this->adminHeaders())
            ->assertStatus(302);
    }

    public function test_pdf_404_when_missing(): void
    {
        $inv = $this->invoice(['status' => 'pending']);
        $this->adminGetJson("/api/admin/electronic-invoices/{$inv->id}/pdf")->assertStatus(404);
    }

    public function test_stats_aggregates_by_status_and_total(): void
    {
        $this->invoice(['status' => 'validated', 'cufe' => 'a', 'total' => 119000]);
        $this->invoice(['status' => 'error']);
        $this->invoice(['status' => 'pending']);

        $res = $this->adminGetJson('/api/admin/electronic-invoices/stats')->assertOk();
        $res->assertJsonPath('data.total', 3);
        $res->assertJsonPath('data.validated', 1);
        $res->assertJsonPath('data.error', 1);
        $res->assertJsonPath('data.pending', 1);
        $this->assertEqualsWithDelta(119000, (float) $res->json('data.total_invoiced'), 0.5);
    }

    public function test_credit_note_controlled_error_when_original_not_validated(): void
    {
        $inv = $this->invoice(['status' => 'pending']);

        $this->postJson("/api/admin/electronic-invoices/{$inv->id}/credit-note",
            ['reason' => 'Anulación por error'], $this->actingAsAdmin())->assertStatus(422);
    }

    public function test_credit_note_forbidden_for_shared_token(): void
    {
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'cufe-orig']);

        // adminHeaders() usa el secreto compartido (no sesión de dueño) → 403.
        $this->adminPostJson("/api/admin/electronic-invoices/{$inv->id}/credit-note", [
            'reason' => 'Devolución total',
        ])->assertStatus(403);
    }

    public function test_credit_note_created_when_original_validated(): void
    {
        config(['billing.enabled' => false]);
        $inv = $this->invoice(['status' => 'validated', 'cufe' => 'cufe-orig']);

        $res = $this->postJson("/api/admin/electronic-invoices/{$inv->id}/credit-note",
            ['reason' => 'Devolución total'], $this->actingAsAdmin())->assertStatus(201);

        $this->assertSame('credit_note', $res->json('data.type'));
        $this->assertDatabaseHas('electronic_invoices', [
            'type' => 'credit_note', 'references_invoice_id' => $inv->id,
        ]);
    }
}
