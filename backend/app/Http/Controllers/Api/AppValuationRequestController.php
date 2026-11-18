<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ValuationException;
use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Valuations\ValuationRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Solicitar valoración» desde la app (CONTRATO_SOLICITUDES_VALORACION.md §2).
 *
 *  GET  /api/app/valuation-requests/current → estado del socio autenticado
 *  POST /api/app/valuation-requests         → pedirla (idempotente por `client_request_id`)
 *
 * El socio SIEMPRE sale del bearer (`auth.member`): no se acepta `member_id`
 * ni ningún otro identificador de socio, así que nadie lee ni crea la de otro.
 *
 * Respuestas con la forma de la agenda de valoraciones: éxito `{ok:true,
 * data}`, error `{ok:false, code, message, data?|errors?}`. Los 409 llevan el
 * estado actual en `data`: la app lo pinta en vez de un error.
 */
class AppValuationRequestController extends Controller
{
    use ValuationResponses;

    public function __construct(private readonly ValuationRequests $solicitudes) {}

    /** GET /api/app/valuation-requests/current */
    public function current(Request $request): JsonResponse
    {
        $socio = $this->socio($request);
        if ($socio === null) {
            return $this->noAutenticado();
        }

        return response()->json(['ok' => true, 'data' => $this->solicitudes->stateFor($socio)]);
    }

    /** POST /api/app/valuation-requests — 201 nueva; 200 si es la repetición de la misma. */
    public function store(Request $request): JsonResponse
    {
        $socio = $this->socio($request);
        if ($socio === null) {
            return $this->noAutenticado();
        }

        try {
            $datos = $this->validar($request, ['client_request_id' => ['required', 'uuid']]);
            $r = $this->solicitudes->submit($socio, (string) $datos['client_request_id']);
        } catch (ValidationException $e) {
            return $this->invalido($e->errors());
        } catch (ValuationException $e) {
            return $this->fallo($e);
        }

        return response()->json(['ok' => true, 'data' => $this->solicitudes->stateFor($socio)], $r['created'] ? 201 : 200);
    }

    private function socio(Request $request): ?Member
    {
        $socio = $request->attributes->get('auth_member');

        return $socio instanceof Member ? $socio : null;
    }

    /** Sin `auth.member` delante no se llega aquí; si se llegara, no es nadie. */
    private function noAutenticado(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'unauthenticated', 'message' => 'No autenticado.'], 401);
    }
}
