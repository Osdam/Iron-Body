<?php

namespace Tests\Feature;

use App\Services\Nutrition\BarcodeNormalizer;
use Tests\TestCase;

/**
 * Normalización de códigos de barras EAN/UPC/GTIN: ceros a la izquierda,
 * conversiones, variantes y dígito de control (sin bloquear recuperables).
 */
class NutritionBarcodeNormalizerTest extends TestCase
{
    private BarcodeNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new BarcodeNormalizer();
    }

    public function test_clean_preserves_leading_zeros_and_strips_symbols(): void
    {
        $this->assertSame('0075012345678', $this->n->clean(' 0075-0123 4567 8 '));
        // Nunca se trata como integer (no pierde el cero inicial).
        $this->assertSame('00012345', $this->n->clean('0-0-0-1-2-3-4-5'));
    }

    public function test_upc_a_canonical_is_ean13_with_leading_zero(): void
    {
        // UPC-A 12 dígitos → EAN-13 con cero inicial.
        $this->assertSame('0036000291452', $this->n->canonical('036000291452'));
        $this->assertSame('UPC-A', $this->n->type('036000291452'));
    }

    public function test_variants_cover_upca_ean13_and_gtin14(): void
    {
        $variants = $this->n->variants('036000291452'); // UPC-A
        $this->assertContains('036000291452', $variants);   // original
        $this->assertContains('0036000291452', $variants);  // EAN-13
        $this->assertContains('00036000291452', $variants); // GTIN-14
    }

    public function test_ean13_with_leading_zero_yields_upca_variant(): void
    {
        $variants = $this->n->variants('0036000291452'); // EAN-13
        $this->assertContains('036000291452', $variants); // UPC-A
    }

    public function test_gtin14_strips_to_ean13(): void
    {
        $this->assertContains('0036000291452', $this->n->variants('00036000291452'));
    }

    public function test_check_digit_validation(): void
    {
        // EAN-13 válido conocido (dígito de control correcto).
        $this->assertTrue($this->n->hasValidCheckDigit('4006381333931'));
        // Mismo con último dígito alterado → inválido (pero NO se bloquea el flujo).
        $this->assertFalse($this->n->hasValidCheckDigit('4006381333930'));
    }

    public function test_upc_e_expands_only_for_number_system_0_or_1(): void
    {
        $expanded = $this->n->expandUpcE('04252614');
        $this->assertNotNull($expanded);
        $this->assertSame(12, strlen($expanded));
        // Sistema numérico distinto de 0/1 no es UPC-E expandible.
        $this->assertNull($this->n->expandUpcE('54252614'));
    }

    public function test_plausible_accepts_8_to_14_digits(): void
    {
        $this->assertTrue($this->n->isPlausible('12345678'));     // 8
        $this->assertTrue($this->n->isPlausible('7700112233'));   // 10 (recuperable)
        $this->assertTrue($this->n->isPlausible('4006381333931')); // 13
        $this->assertFalse($this->n->isPlausible('123'));         // muy corto
        $this->assertFalse($this->n->isPlausible('123456789012345')); // 15
    }
}
