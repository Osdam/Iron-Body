<?php

/*
|--------------------------------------------------------------------------
| Política fiscal del EMISOR — Iron Body
|--------------------------------------------------------------------------
|
| Iron Body es **No responsable de IVA** (responsabilidad RUT 49), confirmado
| por su contador. Consecuencias, sin excepción:
|
|   - No se cobra IVA.            - No se discrimina IVA.
|   - No se suma 19 %.            - No se extrae 19 % del precio.
|   - El precio comercial se conserva: $80.000 factura $80.000.
|
| DECISIÓN TRIBUTARIA DEFINITIVA (contador de Iron Body + soporte Halltec/Factus)
| ------------------------------------------------------------------------------
| Las membresías se facturan como EXENTAS de IVA. Ante la API eso se declara
| enviando el tributo IVA con tarifa 0 %:
|
|     "taxes": [{"code": "01", "rate": "0.00"}]
|
| `is_excluded` NO se usa: exento y excluido son tratamientos distintos ante la
| DIAN, y el confirmado por el contador es EXENTO. La antigua omisión completa
| del bloque `taxes` queda descartada — Factus respondía 422 («El campo código
| tributo es obligatorio») y además no declaraba tratamiento alguno.
|
| Esto NO cambia ningún importe: la tarifa efectiva sigue siendo 0, así que el
| precio comercial se conserva íntegro. Sólo cambia la REPRESENTACIÓN del
| tributo en el ítem, que es lo que el proveedor exige para validar.
|
| Esta política GANA sobre `plans.tax_rate_id`, `products.tax_rate_id` y la
| tabla `tax_rates`. No se migran datos: App\Services\Billing\TaxPolicy fuerza
| la tarifa efectiva a 0, de modo que revertir es cambiar una variable de
| entorno y no una migración.
|
| Vive en su propio archivo (y no dentro de config/billing.php) para que la
| decisión tributaria sea localizable de un vistazo y para no mezclarse con la
| configuración del proveedor.
|
| Motivo de su existencia: IBFE2–IBFE8 se emitieron discriminando 19 % porque
| la tarifa se resolvía en varios puntos del código a la vez.
|
*/

return [

    // Código de responsabilidad tributaria del RUT.
    'issuer_vat_responsibility' => env('BILLING_ISSUER_VAT_RESPONSIBILITY', '49'),

    // ¿El emisor es responsable de IVA? Para Iron Body: NO.
    'issuer_is_vat_responsible' => filter_var(
        env('BILLING_ISSUER_IS_VAT_RESPONSIBLE', false), FILTER_VALIDATE_BOOLEAN
    ),

    // Segundo interruptor: permite dejar de cobrar IVA incluso si algún día el
    // emisor pasara a ser responsable, sin tocar código.
    'vat_collection_enabled' => filter_var(
        env('BILLING_VAT_COLLECTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN
    ),

    'default_vat_rate' => (float) env('BILLING_DEFAULT_VAT_RATE', 0),

    // -- Representación del tributo en el ítem (decisión definitiva) ----------
    // `exempt` => taxes: [{code: '01', rate: '0.00'}]. Es el único tratamiento
    // aprobado. Se deja env-driven para poder corregirlo sin desplegar si el
    // contador cambiara el criterio, pero NO admite `excluded`: si algún día
    // hiciera falta, sería una decisión nueva y debe escribirse como tal.
    'item_tax_treatment' => env('BILLING_ITEM_TAX_TREATMENT', 'exempt'),

    // Código de tributo IVA en el catálogo Factus/DIAN.
    'exempt_tax_code' => (string) env('BILLING_EXEMPT_TAX_CODE', '01'),

    // Tarifa como STRING con dos decimales: la API la espera así, y un float
    // 0.0 se serializaría como `0` y no como `"0.00"`.
    'exempt_tax_rate' => (string) env('BILLING_EXEMPT_TAX_RATE', '0.00'),

    // Leyenda legal para comprobantes, CRM y app.
    'issuer_legend' => env('BILLING_ISSUER_LEGEND', 'Emisor no responsable de IVA'),

    // Barrera de producción (App\Services\Billing\InvoiceEmissionGuard): rechaza
    // facturar pagos de sandbox, tarjetas de prueba, cobros no realizados,
    // solicitudes canceladas o ya facturadas, y endpoints incoherentes con el
    // ambiente.
    //
    // Vive aquí y no en config/billing.php porque ese archivo tiene trabajo sin
    // commitear cuyos cambios no se pueden separar de los míos.
    //
    // Encendida SIEMPRE en producción. La suite de pruebas la apaga en su línea
    // base (phpunit.xml) porque la mayoría de las pruebas de facturación emiten
    // con payloads sintéticos que no corresponden a ningún pago real; las
    // pruebas de la barrera la encienden explícitamente.
    'emission_guard_enabled' => filter_var(
        env('BILLING_EMISSION_GUARD', true), FILTER_VALIDATE_BOOLEAN
    ),

    // Cambia con la política, no con el despliegue: queda congelado en el
    // snapshot fiscal de cada comprobante para poder auditar años después bajo
    // qué criterio se emitió.
    'version' => 'exempt-vat-0.2026.07',

];
