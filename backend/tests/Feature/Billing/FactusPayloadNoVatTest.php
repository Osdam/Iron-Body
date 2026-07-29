<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\TaxPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * El payload que sale hacia Factus no puede declarar IVA.
 *
 * Regresión de un defecto RESIDUAL encontrado en producción tras la primera
 * corrección: los importes ya salían bien (base = precio comercial, IVA = 0)
 * porque `TaxRate::factor()` devolvía 0, pero el constructor del DTO seguía
 * leyendo la tasa CRUDA del catálogo para decidir el bloque `taxes[]`:
 *
 *     $hasTax = $taxRate !== null && $taxRate > 0;   // 19.00 → true
 *     'taxes' => [['code' => '01', 'rate' => '19.00']]
 *
 * Es decir, se habría emitido un documento que declara 19 % con importe cero.
 *
 * CORRECCIÓN POSTERIOR (contador): el IVA cero proviene de la condición del
 * EMISOR —responsabilidad 49, no responsable— y NO de la naturaleza de los
 * productos. Marcar `is_excluded = true` afirmaría que el plan es un bien
 * jurídicamente excluido, lo que sería falso ante la DIAN. El ítem se declara
 * explícitamente NO excluido y sin tarifa.
 */
class FactusPayloadNoVatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config([
            'billing.enabled' => true,
            'billing.base_url' => 'https://api-sandbox.factus.test',
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    /** Captura el cuerpo realmente enviado por HTTP. */
    private function captureSentBody(array $payload): array
    {
        // El cliente autentica antes de emitir: el token también se simula.
        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['bill' => ['number' => 'IBFE999']]], 201),
        ]);

        app(FactusClient::class)->createInvoice($payload);

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'bills')) {
                $sent = $request->data();
            }

            return true;
        });

        return $sent;
    }

    /** Payload tal como lo construye el builder desplegado con tarifa 19 %. */
    private function payloadWithVatRate(): array
    {
        return [
            'numbering_range_id' => 2076,
            'reference_code' => 'QA-NOVAT-1',
            'items' => [[
                'code_reference' => 'PLAN-1',
                'name' => 'Plan de prueba',
                'quantity' => '1',
                'price' => '80000.00',
                'unit_measure_code' => '94',
                'standard_code' => '999',
                // El bloque defectuoso.
                'taxes' => [['code' => '01', 'rate' => '19.00']],
            ]],
        ];
    }

    // ── El defecto residual ───────────────────────────────────────────────

    public function test_una_tarifa_del_19_se_retira_sin_declarar_exclusion(): void
    {
        $sent = $this->captureSentBody($this->payloadWithVatRate());

        $this->assertSame([['is_excluded' => false]], $sent['items'][0]['taxes']);
    }

    public function test_el_producto_no_se_clasifica_como_excluido_ni_exento(): void
    {
        // La causa del IVA cero es el emisor, no la naturaleza del bien.
        $sent = $this->captureSentBody($this->payloadWithVatRate());
        $json = json_encode($sent);

        $this->assertNotSame(true, $sent['items'][0]['taxes'][0]['is_excluded']);
        $this->assertStringNotContainsString('"is_excluded":true', $json);
        $this->assertStringNotContainsString('exento', strtolower($json));
        $this->assertStringNotContainsString('excluido', strtolower($json));
    }

    public function test_si_apareciera_is_excluded_true_la_emision_se_bloquea(): void
    {
        // Defensa en profundidad: si un camino futuro inyectara la exclusión
        // despues de normalizar, la barrera lo rechaza en vez de emitirlo.
        $policy = app(TaxPolicy::class);
        $client = app(FactusClient::class);

        $ref = new \ReflectionMethod($client, 'assertPayloadIsTaxFree');
        $payload = $this->payloadWithVatRate();
        $payload['items'][0]['taxes'] = [['is_excluded' => true]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/EXCLUIDO/');
        $ref->invoke($client, $payload, $policy);
    }

    public function test_el_emisor_declara_responsabilidad_49(): void
    {
        $this->assertSame('49', app(TaxPolicy::class)->issuerVatResponsibility());
        $this->assertFalse(app(TaxPolicy::class)->issuerIsVatResponsible());
    }

    public function test_un_subtotal_que_no_es_el_precio_comercial_se_bloquea(): void
    {
        // 80000/1.19 = 67226.89 → sería una extracción de IVA encubierta.
        Http::fake(['*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200)]);

        $payload = $this->payloadWithVatRate();
        $payload['subtotal'] = '67226.89';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no coincide con el precio comercial/');

        app(FactusClient::class)->createInvoice($payload);
    }

    public function test_un_subtotal_igual_al_precio_comercial_pasa(): void
    {
        $payload = $this->payloadWithVatRate();
        $payload['subtotal'] = '80000.00';

        $sent = $this->captureSentBody($payload);

        $this->assertSame('80000.00', $sent['subtotal']);
        $this->assertSame('80000.00', $sent['items'][0]['price']);
    }

    public function test_el_cuerpo_enviado_no_contiene_la_cadena_19(): void
    {
        $sent = $this->captureSentBody($this->payloadWithVatRate());

        $this->assertStringNotContainsString('19.00', json_encode($sent['items']));
        $this->assertStringNotContainsString('"rate"', json_encode($sent['items']));
    }

    public function test_el_precio_comercial_no_se_altera(): void
    {
        // La normalización toca la DECLARACIÓN del tributo, nunca los importes.
        $sent = $this->captureSentBody($this->payloadWithVatRate());

        $this->assertSame('80000.00', $sent['items'][0]['price']);
        $this->assertSame('1', $sent['items'][0]['quantity']);
    }

    public function test_varias_lineas_se_normalizan_todas(): void
    {
        $payload = $this->payloadWithVatRate();
        $payload['items'][] = [
            'code_reference' => 'PROD-2',
            'name' => 'Producto',
            'quantity' => '2',
            'price' => '15000.00',
            'taxes' => [['code' => '01', 'rate' => '19.00']],
        ];

        $sent = $this->captureSentBody($payload);

        foreach ($sent['items'] as $i => $item) {
            $this->assertSame([['is_excluded' => false]], $item['taxes'], "línea {$i}");
        }
    }

    public function test_una_linea_marcada_excluida_por_el_builder_se_corrige(): void
    {
        // El builder desplegado marca is_excluded=true cuando la tarifa es 0.
        // Esa clasificación es falsa para un emisor no responsable: se corrige.
        $payload = $this->payloadWithVatRate();
        $payload['items'][0]['taxes'] = [['is_excluded' => true]];

        $sent = $this->captureSentBody($payload);

        $this->assertSame([['is_excluded' => false]], $sent['items'][0]['taxes']);
    }

    // ── Barrera sobre los importes ────────────────────────────────────────

    public function test_un_importe_de_iva_en_el_payload_aborta_la_emision(): void
    {
        Http::fake(['*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200)]);

        $payload = $this->payloadWithVatRate();
        $payload['tax_amount'] = '12773.11';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/responsabilidad 49/');

        app(FactusClient::class)->createInvoice($payload);
    }

    public function test_si_aborta_no_se_hace_ninguna_peticion_http(): void
    {
        Http::fake();

        $payload = $this->payloadWithVatRate();
        $payload['tax_amount'] = '0.01';

        try {
            app(FactusClient::class)->createInvoice($payload);
            $this->fail('Debió abortar antes del POST.');
        } catch (RuntimeException) {
            // Nada debe haber salido a la red.
            Http::assertNothingSent();
        }
    }

    // ── Reversibilidad ────────────────────────────────────────────────────

    public function test_con_emisor_responsable_el_payload_no_se_toca(): void
    {
        config([
            'tax_policy.issuer_is_vat_responsible' => true,
            'tax_policy.vat_collection_enabled' => true,
        ]);

        $sent = $this->captureSentBody($this->payloadWithVatRate());

        $this->assertSame([['code' => '01', 'rate' => '19.00']], $sent['items'][0]['taxes']);
    }

    // ── Coherencia con la política ────────────────────────────────────────

    public function test_la_politica_y_el_cliente_coinciden(): void
    {
        $policy = app(TaxPolicy::class);

        $this->assertFalse($policy->collectsVat());
        $this->assertSame('49', $policy->issuerVatResponsibility());

        $sent = $this->captureSentBody($this->payloadWithVatRate());
        $this->assertFalse($sent['items'][0]['taxes'][0]['is_excluded']);
    }
}
