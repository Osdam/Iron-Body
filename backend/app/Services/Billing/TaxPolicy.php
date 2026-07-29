<?php

namespace App\Services\Billing;

use App\Models\TaxRate;
use RuntimeException;

/**
 * Política fiscal ÚNICA de Iron Body.
 *
 * Iron Body es **No responsable de IVA** (responsabilidad RUT 49), confirmado
 * por su contador. En consecuencia:
 *
 *  - No cobra IVA.
 *  - No discrimina IVA en el comprobante.
 *  - No suma 19 % al precio.
 *  - No extrae 19 % de dentro del precio.
 *  - El precio comercial se conserva íntegro: $80.000 factura $80.000.
 *
 * Por qué existe esta clase y no un `if` repartido por el código: el sistema ya
 * emitió 7 facturas reales (IBFE2–IBFE8) que discriminaron IVA porque la tarifa
 * salía de `plans.tax_rate_id` → «IVA 19% incluido». Mientras la tarifa se
 * resolviera en varios sitios, bastaba con que uno se olvidara para volver a
 * emitir mal. Ahora TODA tarifa pasa por {@see effectiveBasisPoints()}, y con
 * el emisor no responsable el resultado es 0 sin excepción — sin importar lo
 * que diga el plan, el producto o la tabla `tax_rates`.
 *
 * No se modifican datos: los planes conservan su `tax_rate_id`. La política
 * gana en tiempo de cotización, así que revertirla es cambiar una variable de
 * entorno, no una migración de datos.
 *
 * REPRESENTACIÓN ANTE EL PROVEEDOR. Aparte de cuánto se cobra (0), está cómo se
 * DECLARA. El contador confirmó que las membresías son EXENTAS de IVA y el
 * soporte de Factus confirmó que un exento se envía como IVA con tarifa 0 %:
 * `[{code:'01', rate:'0.00'}]`. Nunca `is_excluded`, que es otro tratamiento.
 * {@see itemTaxes()} es el ÚNICO sitio que construye ese bloque, por la misma
 * razón que {@see effectiveBasisPoints()} es el único que resuelve la tarifa.
 */
class TaxPolicy
{
    /** Tratamiento aprobado: el ítem es exento (IVA declarado al 0 %). */
    public const TREATMENT_EXEMPT = 'exempt';

    /** Código de responsabilidad tributaria del emisor (RUT). */
    public function issuerVatResponsibility(): string
    {
        return (string) config('tax_policy.issuer_vat_responsibility', '49');
    }

    /** ¿El emisor es responsable de IVA? Para Iron Body: NO. */
    public function issuerIsVatResponsible(): bool
    {
        return (bool) config('tax_policy.issuer_is_vat_responsible', false);
    }

    /**
     * ¿Se cobra IVA en alguna operación?
     *
     * Un emisor no responsable no cobra IVA nunca. La segunda condición
     * (`vat_collection_enabled`) permite apagar el cobro incluso si algún día
     * el emisor pasara a ser responsable, sin tener que tocar código.
     */
    public function collectsVat(): bool
    {
        return $this->issuerIsVatResponsible()
            && (bool) config('tax_policy.vat_collection_enabled', false);
    }

    /**
     * Tarifa EFECTIVA en puntos básicos (1900 = 19 %).
     *
     * Con el emisor no responsable devuelve SIEMPRE 0, aunque la tarifa
     * asociada al plan o producto diga 19 %. Este es el punto donde se corta
     * de raíz la extracción del IVA: con 0 puntos básicos, `PricingService`
     * toma la rama `base = precio comercial, tax = 0` y nunca divide por 1,19.
     */
    public function effectiveBasisPoints(?TaxRate $taxRate): int
    {
        if (! $this->collectsVat()) {
            return 0;
        }

        if ($taxRate === null) {
            return 0;
        }

        return max(0, (int) round(((float) $taxRate->rate) * 100));
    }

    /** Tarifa por defecto para presentación. Siempre 0 con emisor no responsable. */
    public function defaultVatRate(): float
    {
        return $this->collectsVat()
            ? (float) config('tax_policy.default_vat_rate', 0)
            : 0.0;
    }

    // ── Representación del tributo en el ítem ───────────────────────────────

    /** Tratamiento declarado del ítem. Hoy, por decisión del contador: exento. */
    public function itemTaxTreatment(): string
    {
        return (string) config('tax_policy.item_tax_treatment', self::TREATMENT_EXEMPT);
    }

    /** Código de tributo IVA en el catálogo Factus/DIAN. */
    public function exemptTaxCode(): string
    {
        return (string) config('tax_policy.exempt_tax_code', '01');
    }

    /** Tarifa del exento, como string con dos decimales («0.00»). */
    public function exemptTaxRateString(): string
    {
        return (string) config('tax_policy.exempt_tax_rate', '0.00');
    }

    /**
     * Bloque `items[].taxes` cuando NO se cobra IVA.
     *
     * Devuelve el tributo IVA con tarifa 0 %, que es como se representa un bien
     * EXENTO. Deliberadamente NO devuelve `is_excluded` ni un array vacío:
     *
     *   - `is_excluded: true` declararía el servicio EXCLUIDO, un tratamiento
     *     distinto del confirmado por el contador;
     *   - omitir el bloque hace que Factus responda 422 («El campo código
     *     tributo es obligatorio») y no declara tratamiento alguno.
     *
     * @return array<int,array<string,string>>
     */
    public function itemTaxes(?string $taxCode = null): array
    {
        return [[
            'code' => $taxCode ?: $this->exemptTaxCode(),
            'rate' => $this->exemptTaxRateString(),
        ]];
    }

    /**
     * Texto legal que debe aparecer en el comprobante y en las interfaces.
     * Se lee de configuración para poder ajustarlo sin desplegar.
     */
    public function issuerLegend(): string
    {
        return (string) config(
            'tax_policy.issuer_legend',
            'Emisor no responsable de IVA',
        );
    }

    /**
     * Importe en centavos, sin coma flotante.
     *
     * Se evita `float` a propósito: comparar 0.00 con un float acumulado puede
     * dar «distinto de cero» por representación binaria, y aquí eso significaría
     * bloquear una factura correcta o —peor— dejar pasar una incorrecta.
     */
    private function cents(int|float|string|object|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        // Acepta objetos monetarios (p. ej. Money) sin acoplarse a su clase.
        if (is_object($amount)) {
            $amount = method_exists($amount, 'toDecimalString')
                ? $amount->toDecimalString()
                : (string) $amount;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * BARRERA DURA antes de llamar al proveedor.
     *
     * Si por cualquier camino —un dato viejo, un snapshot congelado con el
     * modelo anterior, una regresión futura— llegara un importe de IVA mayor
     * que cero con el emisor no responsable, se ABORTA la emisión.
     *
     * Es deliberadamente una excepción y no un ajuste silencioso: corregir el
     * importe al vuelo escondería el error y emitiría un documento legal cuyo
     * origen nadie podría explicar. Preferimos no emitir y que quede la traza.
     *
     * @throws RuntimeException
     */
    public function assertNoVat(int|float|string|object|null $taxAmount, string $context = 'comprobante'): void
    {
        if ($this->collectsVat()) {
            return;
        }

        $cents = $this->cents($taxAmount);

        if ($cents !== 0) {
            throw new RuntimeException(sprintf(
                'Bloqueo tributario: %s con IVA %s. Iron Body es responsabilidad %s '
                .'(no responsable de IVA) y no puede emitir un documento que discrimine IVA.',
                $context,
                number_format($cents / 100, 2, '.', ''),
                $this->issuerVatResponsibility(),
            ));
        }
    }

    /** Alias por claridad en los call-sites que parten de un importe de BD. */
    public function assertNoVatAmount(int|float|string|object|null $taxAmount, string $context = 'comprobante'): void
    {
        $this->assertNoVat($taxAmount, $context);
    }

    /**
     * Resumen de la política, para snapshots fiscales y para el CRM/app.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'issuer_vat_responsibility' => $this->issuerVatResponsibility(),
            'issuer_is_vat_responsible' => $this->issuerIsVatResponsible(),
            'vat_collection_enabled' => $this->collectsVat(),
            'default_vat_rate' => $this->defaultVatRate(),
            'item_tax_treatment' => $this->itemTaxTreatment(),
            'item_tax_code' => $this->exemptTaxCode(),
            'item_tax_rate' => $this->exemptTaxRateString(),
            'issuer_legend' => $this->issuerLegend(),
            'policy_version' => (string) config('tax_policy.version', 'exempt-vat-0.2026.07'),
        ];
    }
}
