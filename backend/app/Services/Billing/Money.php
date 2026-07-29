<?php

namespace App\Services\Billing;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Valor monetario exacto. NUNCA usa float para operar.
 *
 * Representación interna: ENTERO en unidad mínima (centavos de COP). Todas las
 * operaciones son aritmética entera de 64 bits, por lo que no existe deriva
 * binaria ni errores de representación tipo 0.1 + 0.2.
 *
 * POLÍTICA DE REDONDEO (única y explícita): HALF_UP sobre el centavo. Se aplica
 * exclusivamente en los dos puntos donde una división puede producir fracción:
 *   - Extracción de base en modo legacy_inclusive:  base = gross*10000/(10000+bp)
 *   - Cálculo de impuesto en modo base_plus_tax:    tax  = base*bp/10000
 * El resto de operaciones (sumas, restas, multiplicación por cantidad entera)
 * son exactas por construcción.
 *
 * REGLA ANTI-DERIVA: el impuesto se calcula SIEMPRE sobre el importe agregado de
 * la línea (base unitaria x cantidad), nunca por unidad y luego multiplicado.
 * Así 2 x 80.000 con IVA 19% da exactamente 30.400 de IVA y no 2 x 15.200
 * redondeados por separado.
 *
 * Las tasas se manejan en PUNTOS BÁSICOS enteros (19.00% => 1900 bp) para no
 * introducir un float en el único lugar donde importaría.
 */
final class Money implements JsonSerializable, Stringable
{
    /** Unidades mínimas por unidad mayor (COP: 100 centavos por peso). */
    public const SCALE = 100;

    private function __construct(private readonly int $cents) {}

    // ── Constructores ───────────────────────────────────────────────────────

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Construye desde un valor "en pesos" proveniente de BD, request o modelo.
     *
     * Acepta int, float, string decimal o numeric-string. El float se acepta por
     * compatibilidad con los casts existentes (`decimal:2` de Eloquent devuelve
     * string, pero `float` en Plan::price devuelve float): se normaliza vía
     * string con 2 decimales ANTES de volverse entero, de modo que el float
     * nunca participa en una operación aritmética.
     */
    public static function fromAmount(int|float|string|null $amount): self
    {
        if ($amount === null || $amount === '') {
            return new self(0);
        }

        if (is_int($amount)) {
            return new self($amount * self::SCALE);
        }

        $normalized = is_float($amount)
            ? number_format($amount, 2, '.', '')
            : trim((string) $amount);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("Importe no numérico: {$normalized}");
        }

        // number_format redondea HALF_UP y fija 2 decimales; el string resultante
        // se parte por el punto para obtener el entero exacto sin pasar por float.
        $fixed = number_format((float) $normalized, 2, '.', '');
        $negative = str_starts_with($fixed, '-');
        [$units, $decimals] = explode('.', ltrim($fixed, '-'));

        $cents = ((int) $units) * self::SCALE + (int) $decimals;

        return new self($negative ? -$cents : $cents);
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    public function cents(): int
    {
        return $this->cents;
    }

    /** Centavos tal como los espera Wompi (`amount_in_cents`). */
    public function toWompiCents(): int
    {
        return $this->cents;
    }

    /** String decimal con 2 posiciones: el formato que espera Factus. */
    public function toDecimalString(): string
    {
        $negative = $this->cents < 0;
        $abs = abs($this->cents);
        $units = intdiv($abs, self::SCALE);
        $decimals = $abs % self::SCALE;

        return ($negative ? '-' : '').$units.'.'.str_pad((string) $decimals, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Valor en pesos para persistir en columnas `decimal(x,2)`.
     * Devuelve string para que el driver no lo convierta a float en el camino.
     */
    public function toDatabase(): string
    {
        return $this->toDecimalString();
    }

    /** Solo para presentación/serialización JSON hacia el frontend. */
    public function toFloat(): float
    {
        return (float) $this->toDecimalString();
    }

    // ── Operaciones (exactas) ───────────────────────────────────────────────

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    // ── Operaciones con redondeo HALF_UP explícito ──────────────────────────

    /**
     * Impuesto que se SUMA por encima de esta base: tax = base * bp / 10000.
     * Modo base_plus_tax.
     */
    public function taxOnTop(int $rateBasisPoints): self
    {
        if ($rateBasisPoints <= 0) {
            return self::zero();
        }

        return new self(self::divideHalfUp($this->cents * $rateBasisPoints, 10000));
    }

    /**
     * Base contenida en este importe bruto: base = gross * 10000 / (10000 + bp).
     * Modo legacy_inclusive (extracción hacia atrás).
     */
    public function baseFromGross(int $rateBasisPoints): self
    {
        if ($rateBasisPoints <= 0) {
            return $this;
        }

        return new self(self::divideHalfUp($this->cents * 10000, 10000 + $rateBasisPoints));
    }

    // ── Comparación ─────────────────────────────────────────────────────────

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    /** Diferencia absoluta, para conciliación. */
    public function absoluteDifference(self $other): self
    {
        return new self(abs($this->cents - $other->cents));
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * División entera con redondeo HALF_UP, preservando el signo del dividendo.
     * Único punto de redondeo de toda la aritmética monetaria del sistema.
     */
    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('División monetaria por cero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);

        $quotient = intdiv($n, $d);
        if (($n % $d) * 2 >= $d) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
