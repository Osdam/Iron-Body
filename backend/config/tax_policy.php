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

    // Leyenda legal para comprobantes, CRM y app.
    'issuer_legend' => env('BILLING_ISSUER_LEGEND', 'Emisor no responsable de IVA'),

    'version' => 'no-vat.2026.07',

];
