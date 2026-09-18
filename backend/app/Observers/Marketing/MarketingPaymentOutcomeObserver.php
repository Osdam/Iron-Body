<?php

namespace App\Observers\Marketing;

use App\Models\PaymentTransaction;
use App\Services\Marketing\MarketingPaymentOutcomeService;

/**
 * Escucha el cambio de estado de las transacciones (webhook firmado,
 * reconciliación o consulta en vivo) y se lo pasa al motor de resultados de
 * marketing, que decide si es suyo. Siempre armado: no depende de
 * `commercial.events_enabled`, porque el inicio de un socio que pagó no es un
 * evento analítico, es la promesa del link que el CRM envió.
 */
final class MarketingPaymentOutcomeObserver
{
    public function updated(PaymentTransaction $tx): void
    {
        if ($tx->wasChanged('status')) {
            app(MarketingPaymentOutcomeService::class)->handle($tx);
        }
    }
}
