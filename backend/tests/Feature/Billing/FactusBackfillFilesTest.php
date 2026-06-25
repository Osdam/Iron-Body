<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FactusBackfillFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
        ]);
    }

    private function validatedInvoice(array $attrs = []): ElectronicInvoice
    {
        return ElectronicInvoice::create(array_merge([
            'source_type' => Payment::class, 'source_id' => 1, 'type' => 'invoice',
            'status' => 'validated', 'full_number' => 'SETP990006968', 'cufe' => 'c', 'total' => 1000,
        ], $attrs));
    }

    public function test_recovers_missing_pdf_and_xml(): void
    {
        Storage::fake('local');
        $inv = $this->validatedInvoice(); // sin pdf_path/xml_path
        Http::fake([
            '*oauth/token'  => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*download-pdf' => Http::response(['data' => ['pdf_base_64_encoded' => base64_encode('%PDF-1.4 ok')]]),
            '*download-xml' => Http::response(['data' => ['xml_base_64_encoded' => base64_encode('<?xml version="1.0"?><Invoice/>')]]),
        ]);

        $this->artisan('billing:factus-backfill-files')->assertExitCode(0);

        $inv->refresh();
        $this->assertSame('invoices/' . $inv->uuid . '/factura.pdf', $inv->pdf_path);
        $this->assertSame('invoices/' . $inv->uuid . '/factura.xml', $inv->xml_path);
        Storage::disk('local')->assertExists($inv->pdf_path);
    }

    public function test_dry_run_makes_no_changes_and_no_calls(): void
    {
        Http::fake();
        $inv = $this->validatedInvoice();

        $this->artisan('billing:factus-backfill-files --dry-run')->assertExitCode(0);

        $this->assertNull($inv->fresh()->pdf_path);
        Http::assertNothingSent();
    }

    public function test_skips_invoice_without_real_full_number(): void
    {
        Http::fake();
        // full_number tipo uuid (con guiones) → debe saltarse, sin llamar a Factus.
        $inv = $this->validatedInvoice(['full_number' => 'b6c82591-1529-4950-961c-ff1aed3affb8']);

        $this->artisan('billing:factus-backfill-files')->assertExitCode(0);

        $this->assertNull($inv->fresh()->pdf_path);
        Http::assertNothingSent();
    }

    public function test_ignores_non_validated_invoices(): void
    {
        Http::fake();
        $this->validatedInvoice(['status' => 'pending', 'source_id' => 2]);

        $this->artisan('billing:factus-backfill-files')->assertExitCode(0);
        Http::assertNothingSent(); // pending no es candidata
    }

    public function test_does_not_touch_totals_or_status(): void
    {
        Storage::fake('local');
        $inv = $this->validatedInvoice(['total' => 1234]);
        Http::fake([
            '*oauth/token'  => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*download-pdf' => Http::response(['data' => ['pdf_base_64_encoded' => base64_encode('%PDF ok')]]),
            '*download-xml' => Http::response(['data' => ['xml_base_64_encoded' => base64_encode('<?xml?>')]]),
        ]);

        $this->artisan('billing:factus-backfill-files')->assertExitCode(0);

        $inv->refresh();
        $this->assertSame('validated', $inv->status->value); // estado intacto
        $this->assertEqualsWithDelta(1234, (float) $inv->total, 0.01); // total intacto
    }
}
