<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\Factus\FactusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BLOQUEO DE EMISIÓN mientras la decisión tributaria no esté confirmada.
 *
 * Por qué estas pruebas existen: apagar `auto_emit` NO detiene la emisión.
 * `WompiTransactionService` y `PaymentMembershipActivator` la fuerzan
 * explícitamente sin consultar ese flag, y un job encolado puede reintentar por
 * su cuenta. Ocultar botones en el CRM tampoco detiene nada.
 *
 * Por eso la barrera está en {@see FactusClient::send()}, lo último que corre
 * antes del HTTP saliente, y se verifica por su EFECTO observable: no sale
 * ninguna petición a la red.
 */
class EmissionBlockedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'billing.enabled' => true,
            'billing.base_url' => 'https://api-sandbox.factus.test',
            // La condición bajo prueba: decisión tributaria SIN confirmar.
            'billing.tax_decision_confirmed' => false,
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'numbering_range_id' => 2076,
            'reference_code' => 'QA-BLOQUEO-1',
            'items' => [[
                'code_reference' => 'PLAN-1',
                'name' => 'Plan de prueba',
                'quantity' => '1',
                'price' => '80000.00',
            ]],
        ];
    }

    // ── El bloqueo ────────────────────────────────────────────────────────

    public function test_emitir_una_factura_lanza_excepcion(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Emisión bloqueada/');

        app(FactusClient::class)->createInvoice($this->payload());
    }

    public function test_no_sale_ninguna_peticion_http(): void
    {
        Http::fake();

        try {
            app(FactusClient::class)->createInvoice($this->payload());
            $this->fail('Debió abortar antes de cualquier POST.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_el_bloqueo_ocurre_antes_de_pedir_el_token(): void
    {
        // Si llegara a pedir el token, la petición al endpoint de OAuth saldría.
        // No debe salir NADA: ni token, ni documento.
        Http::fake();

        try {
            app(FactusClient::class)->createInvoice($this->payload());
        } catch (RuntimeException) {
            // Silencio deliberado: lo que se afirma es la ausencia de tráfico.
        }

        Http::assertNothingSent();
    }

    public function test_una_nota_credito_tambien_queda_bloqueada(): void
    {
        // La barrera cubre TODO POST, no solo /v2/bills/validate.
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Emisión bloqueada/');

        app(FactusClient::class)->createCreditNote(['billing_reference' => ['number' => 'IBFE1']]);
    }

    public function test_el_mensaje_deja_constancia_de_que_no_hubo_consecutivo(): void
    {
        Http::fake();

        try {
            app(FactusClient::class)->createInvoice($this->payload());
            $this->fail('Debió abortar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no se consumió consecutivo', $e->getMessage());
            $this->assertStringContainsString('responsabilidad 49', $e->getMessage());
        }
    }

    public function test_un_reintento_del_job_tampoco_puede_emitir(): void
    {
        // Un reintento es simplemente otra llamada: la barrera no tiene estado
        // ni "primera vez", así que bloquea todas por igual.
        Http::fake();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                app(FactusClient::class)->createInvoice($this->payload());
                $this->fail("El intento {$attempt} no debió emitir.");
            } catch (RuntimeException) {
                // esperado
            }
        }

        Http::assertNothingSent();
    }

    // ── Lo que SÍ debe seguir funcionando ─────────────────────────────────

    public function test_la_lectura_para_reconciliacion_sigue_permitida(): void
    {
        // Leer no crea documentos ni consume consecutivos, y la reconciliación
        // fiscal depende de ello.
        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['bill' => ['number' => 'IBFE1']]], 200),
        ]);

        $result = app(FactusClient::class)->getInvoice('IBFE1');

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
    }

    // ── Reversibilidad ────────────────────────────────────────────────────

    public function test_con_la_decision_confirmada_la_emision_vuelve(): void
    {
        config(['billing.tax_decision_confirmed' => true]);

        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['bill' => ['number' => 'IBFE999']]], 201),
        ]);

        $result = app(FactusClient::class)->createInvoice($this->payload());

        $this->assertTrue($result['ok']);
    }
}
