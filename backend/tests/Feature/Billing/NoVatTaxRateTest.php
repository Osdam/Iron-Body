<?php

namespace Tests\Feature\Billing;

use App\Models\TaxRate;
use App\Services\Billing\TaxPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Política de emisor NO RESPONSABLE DE IVA aplicada en el punto de corte
 * universal: {@see TaxRate::factor()}.
 *
 * Estas pruebas NO dependen del motor Pricing V2, así que verifican exactamente
 * el código que corre hoy en producción — el mismo que emitió IBFE2–IBFE8
 * partiendo $80.000 en $67.226,89 + $12.773,11.
 *
 * Cualquier consumidor del cálculo (el `InvoiceDtoBuilder` desplegado usa
 * `$rate?->factor()`) obtiene 0 y por tanto deja el precio comercial intacto.
 */
class NoVatTaxRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();

        config([
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    private function rate(float $percent): TaxRate
    {
        return TaxRate::create([
            'code' => 'R'.uniqid(),
            'name' => "IVA {$percent}%",
            'rate' => $percent,
            'factus_tribute_id' => '01',
            'active' => true,
        ]);
    }

    // ── El punto de corte ─────────────────────────────────────────────────

    public function test_el_factor_de_una_tarifa_del_19_es_cero(): void
    {
        $rate = $this->rate(19.00);

        $this->assertSame(0.0, $rate->factor());
        $this->assertSame(0.0, $rate->effectiveRate());
    }

    public function test_el_dato_original_de_la_tarifa_no_se_altera(): void
    {
        // No se migran datos: `rate` conserva 19.00 en la base.
        $rate = $this->rate(19.00);

        $this->assertSame('19.00', (string) $rate->fresh()->rate);
        $this->assertDatabaseHas('tax_rates', ['id' => $rate->id, 'rate' => 19.00]);
    }

    public function test_ninguna_tarifa_del_catalogo_produce_factor_positivo(): void
    {
        foreach ([19.00, 8.00, 5.00, 0.00] as $percent) {
            $this->assertSame(
                0.0,
                $this->rate($percent)->factor(),
                "La tarifa {$percent}% debería quedar neutralizada.",
            );
        }
    }

    // ── El cálculo que hace el builder desplegado ─────────────────────────

    public function test_el_reparto_base_iva_conserva_el_precio_comercial(): void
    {
        // Reproduce `splitTax($gross, $rate->factor(), $includesTax)` del
        // InvoiceDtoBuilder en producción, con precio incluido de impuesto.
        $gross = 80000.00;
        $factor = $this->rate(19.00)->factor();

        $base = $factor > 0 ? round($gross / (1 + $factor), 2) : $gross;
        $tax = round($gross - $base, 2);

        $this->assertSame(80000.00, $base, 'subtotal');
        $this->assertSame(0.00, $tax, 'IVA');
        $this->assertSame(80000.00, round($base + $tax, 2), 'total');

        // Los valores exactos que se emitieron mal siete veces.
        $this->assertNotSame(67226.89, $base);
        $this->assertNotSame(12773.11, $tax);
    }

    public function test_tampoco_se_suma_iva_cuando_el_precio_es_base(): void
    {
        $base = 80000.00;
        $factor = $this->rate(19.00)->factor();
        $tax = round($base * $factor, 2);

        $this->assertSame(0.00, $tax);
        $this->assertSame(80000.00, round($base + $tax, 2));
        $this->assertNotSame(95200.00, round($base + $tax, 2));
    }

    // ── La barrera dura, sin depender de Money ───────────────────────────

    public function test_la_barrera_bloquea_el_importe_exacto_del_incidente(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/responsabilidad 49/');

        app(TaxPolicy::class)->assertNoVat('12773.11', 'IBFE de prueba');
    }

    public function test_la_barrera_acepta_cero_en_todas_sus_formas(): void
    {
        $policy = app(TaxPolicy::class);

        foreach ([null, 0, 0.0, '0', '0.00', '0.000'] as $zero) {
            $policy->assertNoVat($zero);
        }

        $this->assertTrue(true, 'Ninguna representación de cero debe bloquear.');
    }

    public function test_la_barrera_no_usa_coma_flotante_para_decidir(): void
    {
        $policy = app(TaxPolicy::class);

        // Un céntimo sí debe bloquear.
        $this->expectException(RuntimeException::class);
        $policy->assertNoVat('0.01');
    }

    // ── Reversibilidad ────────────────────────────────────────────────────

    public function test_si_el_emisor_pasara_a_responsable_la_tarifa_vuelve(): void
    {
        $rate = $this->rate(19.00);
        $this->assertSame(0.0, $rate->factor());

        config([
            'tax_policy.issuer_is_vat_responsible' => true,
            'tax_policy.vat_collection_enabled' => true,
        ]);

        $this->assertSame(0.19, round($rate->factor(), 2));
    }
}
