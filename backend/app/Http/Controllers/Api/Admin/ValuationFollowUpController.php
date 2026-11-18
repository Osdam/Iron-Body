<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Services\Valuations\ValuationAccess;
use App\Services\Valuations\ValuationFollowUps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEGUIMIENTO DE VALORACIONES de un socio (CONTRATO_CICLO_VALORACIONES.md §3):
 * última valoración, intervalo, próximo control, próxima cita e historial.
 *
 * AUTORIZACIÓN EN DOS PUERTAS, como el resto de la agenda. El mapa central
 * (AuthorizationMap::OVERRIDES) exige leer la agenda para el GET y
 * `valuations.update` para el PUT; el token compartido no recibe nada de
 * `valuations`. Aquí se exige además una PERSONA y el ALCANCE del socio
 * ({@see ValuationFollowUps::memberInScope()}): fuera de él, 404.
 *
 * Sin route-model binding (`{memberId}` numérico), como `members/by-user`: con
 * `{member}` enlazado, TrainerMemberScope decidiría el alcance por su cuenta.
 */
class ValuationFollowUpController extends Controller
{
    use ValuationResponses;

    public function __construct(private readonly ValuationFollowUps $followUps) {}

    /** GET /api/admin/valuations/members/{memberId}/follow-up */
    public function show(Request $request, int $memberId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($memberId): JsonResponse {
            $socio = $this->followUps->memberInScope($access, $memberId);

            return response()->json(['ok' => true, 'data' => $this->followUps->present($socio, $access)]);
        });
    }

    /**
     * PUT /api/admin/valuations/members/{memberId}/follow-up
     * `{interval_days, enabled, due_on, expected_version}` → lo mismo que el GET;
     * 409 `follow_up_changed` (con `data` actual) si la versión no coincide.
     */
    public function update(Request $request, int $memberId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $memberId): JsonResponse {
            $access->require(ValuationAccess::UPDATE);
            $socio = $this->followUps->memberInScope($access, $memberId);
            $datos = $this->validar($request, ValuationFollowUps::rules());

            return response()->json(['ok' => true, 'data' => $this->followUps->update($access, $socio, $datos, $request)]);
        });
    }
}
