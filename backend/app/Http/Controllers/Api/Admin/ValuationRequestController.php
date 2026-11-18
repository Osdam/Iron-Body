<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Models\ValuationRequest;
use App\Rules\PlainText;
use App\Services\Valuations\ValuationAccess;
use App\Services\Valuations\ValuationRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SOLICITUDES DE VALORACIÓN en el CRM (CONTRATO_SOLICITUDES_VALORACION.md §3).
 *
 *  GET  /api/admin/valuations/requests?status=pending|all&page=   valuations.view
 *  GET  /api/admin/valuations/requests/{requestUuid}             valuations.view
 *  POST /api/admin/valuations/requests/{requestUuid}/schedule    valuations.create
 *  POST /api/admin/valuations/requests/{requestUuid}/attend      valuations.update
 *  POST /api/admin/valuations/requests/{requestUuid}/reject      valuations.update
 *
 * AUTORIZACIÓN EN DOS PUERTAS, como la agenda: el mapa central exige el
 * permiso de cada ruta, y aquí se exige una PERSONA ({@see conPersona}, el
 * token compartido no entra) y el ALCANCE de la agenda ({@see ValuationAccess}):
 * quien solo ve lo suyo solo ve las solicitudes de sus socios asignados o
 * enlazadas a una cita de su entrenador; fuera de eso, 404.
 *
 * Sin route-model binding: el uuid llega como texto y lo resuelve el servicio.
 * El motivo del rechazo lo lee el socio: pasa por PlainText (UTF-8 válido, sin
 * controles) y se guarda tal cual, como texto plano.
 */
class ValuationRequestController extends Controller
{
    use ValuationResponses;

    public function __construct(private readonly ValuationRequests $solicitudes) {}

    /** GET /api/admin/valuations/requests */
    public function index(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $datos = $this->validar($request, [
                'status' => ['nullable', Rule::in(['pending', 'all'])],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $r = $this->solicitudes->list($access, (string) ($datos['status'] ?? 'pending'), (int) ($datos['per_page'] ?? 50));
            $pagina = $r['page'];

            return response()->json([
                'ok' => true,
                'data' => $r['rows'],
                'meta' => [
                    'current_page' => $pagina->currentPage(),
                    'last_page' => $pagina->lastPage(),
                    'per_page' => $pagina->perPage(),
                    'total' => $pagina->total(),
                ],
            ]);
        });
    }

    /** GET /api/admin/valuations/requests/{requestUuid} */
    public function show(Request $request, string $requestUuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($requestUuid): JsonResponse {
            $solicitud = $this->solicitudes->load($access, $requestUuid);

            return response()->json(['ok' => true, 'data' => $this->solicitudes->present($solicitud, $access)]);
        });
    }

    /** POST …/schedule — enlaza una cita ya creada con el asistente. */
    public function schedule(Request $request, string $requestUuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $requestUuid): JsonResponse {
            $datos = $this->validar($request, [
                'appointment_uuid' => ['required', 'uuid'],
                'expected_version' => ['required', 'integer', 'min:1'],
            ]);

            return $this->resultado($access, $this->solicitudes->schedule(
                $access, $requestUuid, (string) $datos['appointment_uuid'], (int) $datos['expected_version'], $request,
            ));
        });
    }

    /** POST …/attend — atendida sin cita. */
    public function attend(Request $request, string $requestUuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $requestUuid): JsonResponse {
            $datos = $this->validar($request, [
                'expected_version' => ['required', 'integer', 'min:1'],
            ]);

            return $this->resultado($access, $this->solicitudes->attend($access, $requestUuid, (int) $datos['expected_version'], $request));
        });
    }

    /** POST …/reject — con motivo (10..500), que lee el socio. */
    public function reject(Request $request, string $requestUuid): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $requestUuid): JsonResponse {
            $datos = $this->validar($request, [
                'reason' => ['required', 'string', 'min:10', 'max:500', new PlainText(multiline: true)],
                'expected_version' => ['required', 'integer', 'min:1'],
            ]);

            return $this->resultado($access, $this->solicitudes->reject(
                $access, $requestUuid, (string) $datos['reason'], (int) $datos['expected_version'], $request,
            ));
        });
    }

    /** @param  array{request: ValuationRequest, changed: bool}  $r */
    private function resultado(ValuationAccess $access, array $r): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'changed' => $r['changed'],
            'data' => $this->solicitudes->present($r['request']->fresh(), $access),
        ]);
    }
}
