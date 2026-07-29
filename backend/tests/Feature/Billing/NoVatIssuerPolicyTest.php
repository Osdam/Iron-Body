<?php

namespace Tests\Feature\Billing;

use App\Models\TaxRate;
use App\Services\Billing\Money;
use App\Services\Billing\PricingMode;
use App\Services\Billing\PricingService;
use App\Services\Billing\TaxPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Política fiscal de emisor NO RESPONSABLE DE IVA (responsabilidad RUT 49).
 *
 * Regresión de un incidente real: IBFE2–IBFE8 se emitieron ante la DIAN
 * discriminando 19 % porque la tarifa salía de `plans.tax_rate_id` →
 * «IVA 19% incluido», y el precio comercial se partía en base + IVA
 * ($80.000 → $67.226,89 + $12.773,11).
 *
 * El contrato que fijan estas pruebas: el precio comercial es el subtotal, el
 * IVA es cero, y ninguna tarifa del catálogo puede cambiarlo.
 */
class NoVatIssuerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private TaxPolicy $policy;

    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();

        // Política vigente de Iron Body.
        config([
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
            'tax_policy.default_vat_rate' => 0,
        ]);

        $this->policy = app(TaxPolicy::class);
        $this->pricing = app(PricingService::class);
    }

    /** Tarifa del 19 % tal como existe hoy en la tabla `tax_rates`. */
    private function vat19(): TaxRate
    {
        return TaxRate::create([
            'code' => 'IVA19-'.uniqid(),
            'name' => 'IVA 19% incluido',
            'rate' => 19.00,
            'factus_tribute_id' => '01',
            'active' => true,
        ]);
    }

    // ── El caso exacto del enunciado ──────────────────────────────────────

    public function test_un_plan_de_80000_factura_80000_sin_iva(): void
    {
        $quote = $this->pricing->quoteLegacyInclusive(
            Money::fromAmount(80000), $this->vat19(), 1,
        );

        $this->assertSame('80000.00', $quote->baseAmount->toDecimalString(), 'subtotal');
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString(), 'IVA');
        $this->assertSame('80000.00', $quote->grossAmount->toDecimalString(), 'total');
        $this->assertSame(0, $quote->taxRateBasisPoints);
        $this->assertFalse($quote->hasTax());
    }

    public function test_no_se_extrae_iva_del_precio(): void
    {
        // El valor prohibido: 80000 / 1.19 = 67226.89 (lo que hizo IBFE3–IBFE8).
        $quote = $this->pricing->quoteLegacyInclusive(
            Money::fromAmount(80000), $this->vat19(), 1,
        );

        $this->assertNotSame('67226.89', $quote->baseAmount->toDecimalString());
        $this->assertNotSame('12773.11', $quote->taxAmount->toDecimalString());
    }

    public function test_no_se_suma_iva_por_encima(): void
    {
        // El otro extremo: 80000 * 1.19 = 95200.
        $quote = $this->pricing->quoteBasePlusTax(
            Money::fromAmount(80000), $this->vat19(), 1,
        );

        $this->assertSame('80000.00', $quote->grossAmount->toDecimalString());
        $this->assertNotSame('95200.00', $quote->grossAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
    }

    public function test_el_modo_base_plus_tax_tampoco_agrega_iva(): void
    {
        // Ni siquiera marcando explícitamente el modo «IVA por encima».
        foreach ([PricingMode::LEGACY_INCLUSIVE, PricingMode::BASE_PLUS_TAX] as $mode) {
            $quote = $mode === PricingMode::LEGACY_INCLUSIVE
                ? $this->pricing->quoteLegacyInclusive(Money::fromAmount(45000), $this->vat19(), 1)
                : $this->pricing->quoteBasePlusTax(Money::fromAmount(45000), $this->vat19(), 1);

            $this->assertSame('0.00', $quote->taxAmount->toDecimalString(), $mode->value);
            $this->assertSame('45000.00', $quote->grossAmount->toDecimalString(), $mode->value);
        }
    }

    // ── Independencia del catálogo ────────────────────────────────────────

    public function test_ninguna_tarifa_del_catalogo_reintroduce_iva(): void
    {
        foreach ([19.00, 5.00, 8.00, 0.00] as $rate) {
            $tr = TaxRate::create([
                'code' => 'R'.str_replace('.', '', (string) $rate).uniqid(),
                'name' => "IVA {$rate}", 'rate' => $rate,
                'factus_tribute_id' => '01', 'active' => true,
            ]);

            $this->assertSame(
                0,
                $this->policy->effectiveBasisPoints($tr),
                "La tarifa {$rate}% debería quedar neutralizada.",
            );
        }
    }

    public function test_sin_tarifa_asignada_tampoco_hay_iva(): void
    {
        $this->assertSame(0, $this->policy->effectiveBasisPoints(null));
    }

    // ── Descuentos, cantidades y redondeo ─────────────────────────────────

    public function test_el_descuento_se_aplica_sobre_el_precio_comercial(): void
    {
        $quote = $this->pricing->quoteLegacyInclusive(
            Money::fromAmount(80000), $this->vat19(), 1, Money::fromAmount(10000),
        );

        $this->assertSame('80000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('10000.00', $quote->discountAmount->toDecimalString());
        $this->assertSame('70000.00', $quote->grossAmount->toDecimalString());
    }

    public function test_varias_unidades_no_derivan_centavos(): void
    {
        $quote = $this->pricing->quoteLegacyInclusive(
            Money::fromAmount(33333), $this->vat19(), 3,
        );

        $this->assertSame('99999.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('99999.00', $quote->grossAmount->toDecimalString());
    }

    public function test_precios_con_centavos_se_conservan_exactos(): void
    {
        $quote = $this->pricing->quoteLegacyInclusive(
            Money::fromAmount('45999.99'), $this->vat19(), 1,
        );

        $this->assertSame('45999.99', $quote->grossAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
    }

    public function test_precio_cero_no_rompe(): void
    {
        $quote = $this->pricing->quoteLegacyInclusive(Money::zero(), null, 1);

        $this->assertSame('0.00', $quote->grossAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
    }

    // ── La barrera dura ───────────────────────────────────────────────────

    public function test_la_barrera_bloquea_cualquier_iva_mayor_que_cero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/responsabilidad 49/');

        $this->policy->assertNoVat(Money::fromAmount('12773.11'), 'factura de prueba');
    }

    public function test_la_barrera_deja_pasar_iva_cero(): void
    {
        $this->policy->assertNoVat(Money::zero());
        $this->policy->assertNoVatAmount('0.00');
        $this->policy->assertNoVatAmount(null);

        $this->assertTrue(true, 'No debe lanzar con IVA cero.');
    }

    public function test_la_barrera_detecta_el_importe_exacto_de_ibfe3_a_ibfe8(): void
    {
        // El importe real que se emitió mal siete veces.
        $this->expectException(RuntimeException::class);
        $this->policy->assertNoVatAmount('12773.11', 'IBFE');
    }

    // ── Snapshot fiscal ───────────────────────────────────────────────────

    public function test_el_snapshot_fiscal_registra_la_politica_aplicada(): void
    {
        $snapshot = $this->policy->toSnapshot();

        $this->assertSame('49', $snapshot['issuer_vat_responsibility']);
        $this->assertFalse($snapshot['issuer_is_vat_responsible']);
        $this->assertFalse($snapshot['vat_collection_enabled']);
        $this->assertSame(0.0, $snapshot['default_vat_rate']);
        $this->assertStringContainsString('no responsable', strtolower($snapshot['issuer_legend']));
        $this->assertNotEmpty($snapshot['policy_version']);
    }

    // ── Reversibilidad controlada ─────────────────────────────────────────

    public function test_la_politica_es_reversible_por_configuracion(): void
    {
        // Si algún día el emisor pasara a ser responsable, se reactiva sin
        // tocar código ni migrar datos. Se prueba para documentar la salida.
        config([
            'tax_policy.issuer_is_vat_responsible' => true,
            'tax_policy.vat_collection_enabled' => true,
        ]);

        $this->assertSame(1900, app(TaxPolicy::class)->effectiveBasisPoints($this->vat19()));
    }
}
