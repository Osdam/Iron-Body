<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CashShiftException;
use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Services\Caja\CashShiftService;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turnos de caja: apertura, cierre y arqueo.
 *
 * Una sola caja física, así que hay como máximo un turno abierto. La garantía
 * dura es un índice único parcial en la base de datos, no una comprobación en
 * PHP: dos aperturas simultáneas pasarían las dos.
 */
class CashShiftController extends Controller
{
    public function __construct(private readonly CashShiftService $shifts) {}

    /** GET /api/admin/caja/shift — el turno abierto, con sus totales al vuelo. */
    public function current(): JsonResponse
    {
        $shift = CashShift::current();

        return response()->json([
            'data' => $shift?->toCrmArray(withTotals: true),
            'open' => $shift !== null,
        ]);
    }

    /** GET /api/admin/caja/shifts — histórico paginado. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = CashShift::query()->latest('id');
        if (isset($filters['from'])) {
            $query->whereDate('opened_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('opened_at', '<=', $filters['to']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (CashShift $s) => $s->toCrmArray())->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /api/admin/caja/shift/open  { opening_amount, notes? } */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = AdminActor::from($request);
        if ($admin === null) {
            // El token de automatizaciones no es una persona, y un turno tiene
            // que tener un responsable con nombre.
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'Abrir caja exige una sesión de administrador.',
            ], 403);
        }

        try {
            $shift = $this->shifts->open($admin, (float) $data['opening_amount'], $data['notes'] ?? null);
        } catch (CashShiftException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $shift->toCrmArray(withTotals: true)], 201);
    }

    /**
     * POST /api/admin/caja/shift/close  { counted_amount, notes?, forced_reason? }
     *
     * Cerrar el turno de otra persona exige `caja.manage` y motivo: es una
     * intervención de supervisión, no la operación normal de cierre.
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'forced_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $admin = AdminActor::from($request);
        if ($admin === null) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'Cerrar caja exige una sesión de administrador.',
            ], 403);
        }

        $canManage = CrmPermission::allows($admin, CrmPermission::CAJA_MANAGE);
        $shift = CashShift::current();

        // Si va a cerrar un turno ajeno, el motivo es obligatorio: un cierre
        // forzado sin explicación es un descuadre sin responsable.
        if ($shift !== null && (int) $shift->opened_by !== (int) $admin->id) {
            if (! $canManage) {
                return response()->json([
                    'ok' => false,
                    'code' => 'forbidden',
                    'message' => 'Este turno lo abrió otra persona.',
                    'required_permission' => CrmPermission::CAJA_MANAGE,
                ], 403);
            }
            if (blank($data['forced_reason'] ?? null)) {
                return response()->json([
                    'ok' => false,
                    'code' => 'forced_reason_required',
                    'message' => 'Cerrar el turno de otra persona exige indicar el motivo.',
                ], 422);
            }
        }

        try {
            $closed = $this->shifts->close(
                admin: $admin,
                countedAmount: (float) $data['counted_amount'],
                notes: $data['notes'] ?? null,
                canManage: $canManage,
                forcedReason: $data['forced_reason'] ?? null,
            );
        } catch (CashShiftException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $closed->toCrmArray()]);
    }

    /**
     * 409 y no 422: el problema no es lo que se envió, es el estado del turno.
     * El CRM distingue por `code` para decir qué hacer.
     */
    private function error(CashShiftException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $e->code_,
            'message' => $e->getMessage(),
        ], 409);
    }
}
