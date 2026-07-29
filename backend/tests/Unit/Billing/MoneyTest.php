<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Aritmética monetaria exacta. Sin base de datos ni framework: es dominio puro.
 */
class MoneyTest extends TestCase
{
    public function test_builds_from_int_float_and_string_without_drift(): void
    {
        $this->assertSame(8000000, Money::fromAmount(80000)->cents());
        $this->assertSame(8000000, Money::fromAmount(80000.00)->cents());
        $this->assertSame(8000000, Money::fromAmount('80000.00')->cents());
        $this->assertSame(6722689, Money::fromAmount('67226.89')->cents());
        $this->assertSame(0, Money::fromAmount(null)->cents());
        $this->assertSame(-1550, Money::fromAmount('-15.50')->cents());
    }

    public function test_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromAmount('ochenta mil');
    }

    /** El caso comercial exacto del negocio. */
    public function test_tax_on_top_19_percent(): void
    {
        $base = Money::fromAmount(80000);
        $tax = $base->taxOnTop(1900);

        $this->assertSame('15200.00', $tax->toDecimalString());
        $this->assertSame('95200.00', $base->plus($tax)->toDecimalString());
    }

    /** Extracción hacia atrás: el caso de las 8 facturas pending en producción. */
    public function test_base_from_gross_19_percent(): void
    {
        $gross = Money::fromAmount(80000);
        $base = $gross->baseFromGross(1900);

        $this->assertSame('67226.89', $base->toDecimalString());
        $this->assertSame('12773.11', $gross->minus($base)->toDecimalString());
    }

    /** El impuesto se calcula sobre el agregado: 2 x 80.000 da 30.400, no 2 x 15.200. */
    public function test_no_rounding_drift_on_multiple_quantities(): void
    {
        $lineBase = Money::fromAmount(80000)->multipliedBy(2);
        $tax = $lineBase->taxOnTop(1900);

        $this->assertSame('160000.00', $lineBase->toDecimalString());
        $this->assertSame('30400.00', $tax->toDecimalString());
        $this->assertSame('190400.00', $lineBase->plus($tax)->toDecimalString());
    }

    public function test_half_up_rounding_is_explicit(): void
    {
        // 1 centavo al 50% → 0.005, redondea HACIA ARRIBA.
        $this->assertSame(1, Money::fromCents(1)->taxOnTop(5000)->cents());
        // 0.4 centavos → redondea hacia abajo.
        $this->assertSame(0, Money::fromCents(1)->taxOnTop(4000)->cents());
    }

    public function test_zero_rate_leaves_amount_untouched(): void
    {
        $base = Money::fromAmount(100000);

        $this->assertTrue($base->taxOnTop(0)->isZero());
        $this->assertSame('100000.00', $base->baseFromGross(0)->toDecimalString());
    }

    public function test_wompi_cents_conversion(): void
    {
        $this->assertSame(9520000, Money::fromAmount(95200)->toWompiCents());
        $this->assertSame(8000000, Money::fromAmount('80000.00')->toWompiCents());
    }

    public function test_absolute_difference_for_reconciliation(): void
    {
        $a = Money::fromAmount(95200);
        $b = Money::fromAmount(80000);

        $this->assertSame('15200.00', $a->absoluteDifference($b)->toDecimalString());
        $this->assertSame('15200.00', $b->absoluteDifference($a)->toDecimalString());
    }
}
