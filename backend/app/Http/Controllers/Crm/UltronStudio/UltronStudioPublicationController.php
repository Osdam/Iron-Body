<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioPublications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ULTRON STUDIO: la cadena de publicaciones (contrato H5 §A7, rutas 37 y 38):
 * su historial y volver a Legacy. Ver {@see StudioPublications} para las
 * puertas y la cadena, y {@see UltronStudioController} para las dos puertas de
 * autorización, comunes a los controladores del Studio.
 *
 * Volver a Legacy es el «parar de declarar» del Studio: no depende de ninguna
 * llave ni de la ventana, y responde como las otras tres escrituras (201 con la
 * entrada creada; 200 `replayed` con la de la primera vez). El servicio se
 * resuelve solo en esa acción (firma con la clase del motor): leer el
 * historial no toca ninguna clase del motor.
 */
class UltronStudioPublicationController extends Controller
{
    use ResolvesPagination;

    /** GET /api/admin/ultron-studio/publications?page&per_page (≤ 50), de la más reciente a la más antigua */
    public function index(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $request->validate([
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['ok' => true, 'data' => StudioPublications::page(
            $this->resolvePerPage($request, 20, StudioPublications::PER_PAGE_MAX),
        )]);
    }

    /** POST /api/admin/ultron-studio/publications/legacy   {clientRequestId, reason 5–500} */
    public function legacy(Request $request, StudioPublications $publications): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:'.StudioPublications::REASON_MIN, 'max:'.StudioPublications::REASON_MAX, new PlainText(multiline: true)],
        ]);

        $resultado = $publications->legacy($access, $request, strtolower($data['clientRequestId']), $data['reason']);
        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $resultado['entry']]);
        }

        return response()->json(['ok' => true, 'data' => $resultado['entry']], 201);
    }
}
