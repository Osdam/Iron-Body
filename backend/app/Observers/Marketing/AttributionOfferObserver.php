<?php

namespace App\Observers\Marketing;

use App\Models\MarketingLeadAttribution;
use App\Services\Marketing\Attribution\AttributionContextService;
use App\Services\Observability\ChannelLog;
use Throwable;

/**
 * Revisa la coherencia de la pauta cuando cambia lo que anunciaba.
 *
 * El registro del contacto ya revisa al crear la atribución, pero eso no cubre
 * el caso que de verdad va a ocurrir: hoy Meta no dice qué plan promocionaba un
 * anuncio, así que `advertised_plan_id` lo rellena alguien del equipo -o lo
 * rellenará un mapeo futuro- DESPUÉS. Sin este observador, ese momento pasaba
 * sin que nadie comprobara nada y la incoherencia solo aparecía si por
 * casualidad llegaba otro contacto del mismo lead.
 *
 * Escucha la tabla, no el servicio que la escribe, por el mismo motivo que el
 * resto de observadores del proyecto: da igual quién haga el cambio —un
 * comando, el CRM, una migración de datos—, la revisión ocurre.
 */
class AttributionOfferObserver
{
    public function updated(MarketingLeadAttribution $attribution): void
    {
        // Solo cuando cambia LO ANUNCIADO. Un contacto nuevo actualiza fechas y
        // titulares constantemente, y revisar en cada uno repetiría el aviso.
        if (! $attribution->wasChanged(['advertised_plan_id', 'advertised_product'])) {
            return;
        }

        $this->review($attribution);
    }

    private function review(MarketingLeadAttribution $attribution): void
    {
        try {
            app(AttributionContextService::class)->reviewAndAlert($attribution);
        } catch (Throwable $e) {
            // Un aviso que rompe algo ha dejado de ser un aviso.
            ChannelLog::warning('attribution.offer_review_failed', [
                'lead_id' => $attribution->marketing_lead_id,
                'error_class' => class_basename($e),
            ]);
        }
    }
}
