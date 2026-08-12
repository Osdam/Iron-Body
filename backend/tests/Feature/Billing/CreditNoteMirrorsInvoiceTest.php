<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitCreditNoteJob;
use App\Models\ElectronicInvoice;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Una nota crédito ESPEJA el documento que anula.
 *
 * El job reconstruía el adquiriente desde el origen con la política vigente en
 * el momento de anular. Parecía equivalente y no lo es: si el perfil fiscal del
 * cliente cambia entre la emisión y la anulación, la nota sale a nombre de
 * otro. Es exactamente lo que iba a ocurrir con IBFE10 —emitida a consumidor
 * final— cuando el perfil ya tenía NIT: se habría declarado ante la DIAN una
 * anulación atribuida a una empresa distinta del adquiriente original.
 *
 * No es un detalle de formato. Es un documento legal a nombre de quien no
 * corresponde, y no se puede deshacer.
 */
class CreditNoteMirrorsInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.credentials' => [
                'username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's',
            ],
            'billing.consumer_final' => [
                'document_type' => '3',
                'document_number' => '222222222222',
                'name' => 'CONSUMIDOR FINAL',
            ],
            'billing.numbering.credit_range_id' => 2077,
        ]);
    }

    private function paidPayment(): Payment
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'reference' => 'REC-'.random_int(1000, 9999),
            'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /**
     * El caso real: factura a consumidor final, perfil fiscal completado
     * DESPUÉS, y anulación. La nota debe llevar el adquiriente de la factura.
     */
    public function test_la_nota_credito_usa_el_adquiriente_de_la_factura_original(): void
    {
        $capturado = null;
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/credit-notes/validate' => function ($request) use (&$capturado) {
                $capturado = $request->data();

                return Http::response(['data' => ['bill' => [
                    'id' => 'NC-1', 'number' => 'IBNC1', 'cufe' => 'cufe-nc', 'status' => 'Validada',
                ]]], 201);
            },
            '*' => Http::response([], 200),
        ]);

        $payment = $this->paidPayment();

        // 1. Se emite a CONSUMIDOR FINAL (el cliente no tenía perfil fiscal).
        $original = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true);
        $original->forceFill([
            'status' => InvoiceStatus::VALIDATED->value,
            'full_number' => 'IBFE10',
            'cufe' => 'cufe-original',
        ])->save();

        $this->assertSame('222222222222', $original->customer_doc_number);

        // 2. DESPUÉS alguien completa el perfil fiscal del cliente.
        FiscalProfile::create([
            'user_id' => $payment->user_id,
            'doc_type' => 'NIT',
            'doc_number' => '901499742',
            'dv' => '7',
            'person_type' => 'juridica',
            'legal_name' => 'COSTRUMETALICA ROCHIS S.A.S',
        ]);

        // 3. Se anula.
        $nota = app(InvoicingService::class)->createCreditNote($original->fresh(), 'Anulación por duplicidad');
        app()->call([new EmitCreditNoteJob($nota->id), 'handle']);

        $this->assertNotNull($capturado, 'no se llegó a llamar a credit-notes/validate');
        $this->assertSame(
            '222222222222',
            $capturado['customer']['identification'],
            'la nota crédito debe anular a nombre del adquiriente de la factura, no del perfil actual'
        );
        $this->assertSame('IBFE10', $capturado['bill_number']);
        $this->assertSame(2077, $capturado['numbering_range_id'], 'la NC usa su propio rango');

        // La original queda anulada por la nota validada.
        $this->assertSame(InvoiceStatus::CANCELLED, $original->fresh()->status);
    }

    /**
     * Y si por lo que sea el adquiriente no coincidiera —una factura antigua
     * sin payload congelado, por ejemplo—, se aborta antes de tocar la red.
     */
    public function test_un_adquiriente_distinto_aborta_sin_emitir(): void
    {
        // Sólo se permite el token: si algo intentara emitir de verdad, la
        // llamada a credit-notes/validate quedaría registrada y el test lo dice.
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*' => Http::response([], 200),
        ]);

        $payment = $this->paidPayment();

        // La original se monta a mano —sin pasar por manualEmit— para que este
        // test hable sólo de la nota crédito y no dispare la emisión de la factura.
        $original = ElectronicInvoice::create([
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => InvoiceStatus::VALIDATED->value,
            'full_number' => 'IBFE10',
            'cufe' => 'cufe-original',
            'currency' => 'COP',
            'subtotal' => '80000.00', 'tax_total' => '0.00', 'total' => '80000.00',
            // Sin payload congelado: obliga a la rama de reconstrucción…
            'payload_snapshot' => null,
            // …y el adquiriente registrado no coincide con lo que resolvería hoy.
            'customer_doc_type' => '3',
            'customer_doc_number' => '999999999',
            'customer_name' => 'OTRO ADQUIRIENTE',
            'is_final_consumer' => true,
        ]);

        $nota = app(InvoicingService::class)->createCreditNote($original->fresh(), 'Anulación');
        app()->call([new EmitCreditNoteJob($nota->id), 'handle']);

        $nota->refresh();
        $this->assertSame(InvoiceStatus::CREDIT_NOTE_ERROR, $nota->status);
        $this->assertStringContainsString('Adquiriente incoherente', (string) $nota->failure_reason);
        $this->assertNull($nota->cufe);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'credit-notes/validate'));

        // Y la factura original sigue como estaba: nadie la anuló.
        $this->assertSame(InvoiceStatus::VALIDATED, $original->fresh()->status);
    }
}
