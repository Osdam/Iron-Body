<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NutritionGuideResource;
use App\Models\Member;
use App\Models\NutritionGuide;
use App\Services\Trainer\NutritionGuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vista de SOLO LECTURA de las guías nutricionales para el socio.
 *
 * Puede ver la vigente, el histórico y marcar una como leída; nunca editarla:
 * no existen endpoints de escritura de contenido. Solo salen las versiones
 * publicadas o corregidas —un borrador es trabajo en curso del entrenador y una
 * anulada dejó de ser válida— y siempre acotadas al propio socio, que es lo que
 * cierra la puerta a leer la guía de otro cambiando el uuid.
 */
class MemberNutritionGuideController extends Controller
{
    public function __construct(private readonly NutritionGuideService $guides) {}

    /**
     * La guía VIGENTE.
     *
     * Devuelve 200 con `data: null` cuando todavía no hay ninguna, en vez de
     * 404: "aún no tienes guía" es un estado normal del socio, no un error, y la
     * app necesita distinguirlo de un fallo para poder explicarlo.
     */
    public function current(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $guide = NutritionGuide::latestPublishedFor((int) $member->getKey());

        return response()->json([
            'ok' => true,
            'data' => $guide
                ? new NutritionGuideResource($guide->load(['trainer', 'parent']))
                : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $items = NutritionGuide::forMember((int) $member->getKey())
            ->visibleToMember()
            ->with('trainer')
            ->orderByDesc('published_at')
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => NutritionGuideResource::collection($items),
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $guide = $this->find($request, $uuid);

        return response()->json([
            'ok' => true,
            'data' => new NutritionGuideResource($guide->load(['trainer', 'parent'])),
        ]);
    }

    public function acknowledge(Request $request, string $uuid): JsonResponse
    {
        $this->guides->acknowledge($this->find($request, $uuid));

        return response()->json(['ok' => true, 'message' => 'Marcada como leída.']);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** Resuelve la guía del PROPIO socio y visible; 404 en cualquier otro caso. */
    private function find(Request $request, string $uuid): NutritionGuide
    {
        $member = $this->member($request);

        $guide = NutritionGuide::query()
            ->where('uuid', $uuid)
            ->forMember((int) $member->getKey())
            ->visibleToMember()
            ->first();

        // 404 y no 403: confirmar que la guía existe pero es de otro ya sería
        // decirle a quien prueba uuids que ha acertado con uno.
        abort_if($guide === null, 404, 'Guía nutricional no encontrada.');

        return $guide;
    }
}
