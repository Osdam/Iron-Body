<?php

namespace App\Http\Controllers\Crm\UltronStudio;

/**
 * El total de rutas bajo `api/admin/ultron-studio`, por hitos (contrato H9, L9-38):
 * el 77 del Hito 8 ({@see UltronStudioVoiceController::STUDIO_ROUTE_COUNT}) no cambia
 * nunca; el Hito 9 SUMA el gobierno (30), el canal (1) y la ejecución (13). Las guardas
 * que atan el total a las rutas reales comparan con esta constante.
 */
final class StudioRouteCount
{
    public const TOTAL = UltronStudioStrategyController::STUDIO_ROUTE_COUNT_WITH_STRATEGIES
        + UltronStudioStreamController::ROUTE_COUNT
        + UltronStudioOutreachController::ROUTE_COUNT;
}
