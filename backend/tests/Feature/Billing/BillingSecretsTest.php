<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\PayloadSanitizer;
use App\Services\Billing\SandboxProbe;
use App\Services\Billing\TaxPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Ninguna credencial puede acabar en la base de datos, en un log ni en el texto
 * de una excepción.
 *
 * El riesgo es concreto: los payloads de facturación se persisten en
 * `request_payload` y `response_payload` para poder diagnosticar, y las
 * respuestas del proveedor traen el PDF y el XML completos en base64. Sin
 * limpieza, una fila de diagnóstico se convierte en una filtración.
 */
class BillingSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SECRET = 'sk-super-secreto-de-produccion-000';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        SandboxProbe::reset();

        config([
            'billing.enabled' => true,
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.tax_decision_confirmed' => false,
            'billing.credentials' => [
                'username' => 'usuario@ironbody.test',
                'password' => self::FAKE_SECRET,
                'client_id' => 'client-id-real',
                'client_secret' => self::FAKE_SECRET,
            ],
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    // ── El sanitizador ────────────────────────────────────────────────────

    public function test_redacta_credenciales_a_cualquier_profundidad(): void
    {
        $clean = app(PayloadSanitizer::class)->sanitize([
            'access_token' => self::FAKE_SECRET,
            'nivel1' => [
                'client_secret' => self::FAKE_SECRET,
                'nivel2' => [
                    'Authorization' => 'Bearer '.self::FAKE_SECRET,
                    'password' => self::FAKE_SECRET,
                ],
            ],
            'numbering_range_id' => 2076,
        ]);

        $json = json_encode($clean);

        $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $json);
        $this->assertSame('[redactado]', $clean['access_token']);
        $this->assertSame('[redactado]', $clean['nivel1']['nivel2']['password']);

        // Lo que no es secreto sobrevive intacto: el objetivo es diagnosticar.
        $this->assertSame(2076, $clean['numbering_range_id']);
    }

    public function test_recorta_los_binarios_en_base64(): void
    {
        $clean = app(PayloadSanitizer::class)->sanitize([
            'pdf_base64' => str_repeat('A', 50_000),
            'xml_base64' => str_repeat('B', 40_000),
            'number' => 'IBFE1',
        ]);

        $this->assertStringContainsString('recortado', $clean['pdf_base64']);
        $this->assertStringContainsString('recortado', $clean['xml_base64']);
        $this->assertSame('IBFE1', $clean['number']);
        $this->assertLessThan(1000, strlen((string) json_encode($clean)));
    }

    public function test_una_cadena_larga_sin_nombre_sospechoso_tambien_se_recorta(): void
    {
        // Defensa por tamaño, no solo por nombre: un campo nuevo del proveedor
        // no debería poder inflar la fila.
        $clean = app(PayloadSanitizer::class)->sanitize([
            'campo_desconocido' => str_repeat('x', 5000),
        ]);

        $this->assertStringContainsString('recortado', $clean['campo_desconocido']);
    }

    // ── Nada se filtra por los mensajes de error ──────────────────────────

    public function test_el_bloqueo_de_emision_no_revela_credenciales(): void
    {
        Http::fake();

        try {
            app(FactusClient::class)->createInvoice([
                'reference_code' => 'lo-que-sea',
                'items' => [['price' => '80000.00']],
            ]);
            $this->fail('Debió bloquearse.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $e->getMessage());
            $this->assertStringNotContainsString('client-id-real', $e->getMessage());
        }
    }

    public function test_la_barrera_tributaria_no_revela_credenciales(): void
    {
        $policy = app(TaxPolicy::class);

        try {
            $policy->assertNoVat('12773.11', 'comprobante de prueba');
            $this->fail('Debió bloquearse.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $e->getMessage());
        }
    }

    // ── Nada se filtra por los logs ───────────────────────────────────────

    public function test_ningun_secreto_llega_al_log_cuando_falla_la_emision(): void
    {
        $written = [];

        Log::listen(function ($message) use (&$written) {
            $written[] = $message->message.' '.json_encode($message->context);
        });

        Http::fake();

        try {
            app(FactusClient::class)->createInvoice([
                'reference_code' => 'lo-que-sea',
                'items' => [['price' => '80000.00']],
            ]);
        } catch (RuntimeException) {
            // El fallo es el escenario; lo que se afirma es lo que quedó escrito.
        }

        foreach ($written as $line) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $line);
            $this->assertStringNotContainsString('client-id-real', $line);
        }

        $this->assertTrue(true, 'Ninguna línea de log contiene credenciales.');
    }

    public function test_la_configuracion_no_se_serializa_en_el_payload(): void
    {
        // Regresión: el cliente recibe config(billing) entero en el constructor.
        // Ese array contiene las credenciales y jamás debe viajar en el cuerpo.
        config([
            'billing.tax_decision_confirmed' => true,
            // La sonda solo existe en sandbox; el ambiente debe ser coherente.
            'billing.env' => 'sandbox',
            'billing.base_url' => 'https://api-sandbox.factus.com.co',
        ]);

        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['number' => 'IBFE1']], 201),
        ]);

        SandboxProbe::run(fn () => app(FactusClient::class)->createInvoice([
            'reference_code' => SandboxProbe::REFERENCE_PREFIX.'X',
            'items' => [['price' => '80000.00']],
        ]));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'bills')) {
                return true;
            }

            $body = json_encode($request->data());
            $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $body);
            $this->assertStringNotContainsString('credentials', (string) $body);

            return true;
        });
    }
}
