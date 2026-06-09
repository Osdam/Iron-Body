<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GymEquipment;
use App\Services\GymEquipmentContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Equipos/máquinas del gimnasio.
 *
 * Dos audiencias:
 *  1) CRM admin → CRUD completo (rutas /api/admin/equipment*). Sigue el patrón
 *     del resto del CRM: protegido por la capa de red/front, sin auth propia.
 *  2) IRON IA → catálogo de solo lectura (GET /api/iron-ai/equipment-catalog),
 *     pensado para que el equipo de IA valide ejercicios contra el equipamiento
 *     real. La forma de la respuesta es estable: ver GymEquipment::aiCatalog().
 */
class GymEquipmentController extends Controller
{
    public function __construct(private readonly GymEquipmentContextService $context) {}

    // ── CRM admin ────────────────────────────────────────────────────────────

    // GET /api/admin/equipment
    public function index(Request $request): JsonResponse
    {
        $query = GymEquipment::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('zone', 'like', $term);
            });
        }

        $items = $query->orderBy('category')->orderBy('name')->get();

        return response()->json(['data' => $items]);
    }

    // GET /api/admin/equipment/stats
    public function stats(): JsonResponse
    {
        return response()->json([
            'total'        => GymEquipment::count(),
            'operational'  => GymEquipment::where('status', 'operational')->count(),
            'maintenance'  => GymEquipment::where('status', 'maintenance')->count(),
            'out_of_service' => GymEquipment::where('status', 'out_of_service')->count(),
            'ai_available' => GymEquipment::forAi()->count(),
            'by_category'  => GymEquipment::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')->pluck('count', 'category'),
        ]);
    }

    // GET /api/admin/equipment/{equipment}
    public function show(GymEquipment $equipment): JsonResponse
    {
        return response()->json(['data' => $equipment]);
    }

    // POST /api/admin/equipment
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $equipment = GymEquipment::create($data);
        $this->context->flush();

        return response()->json(['data' => $equipment], 201);
    }

    // PUT/PATCH /api/admin/equipment/{equipment}
    public function update(Request $request, GymEquipment $equipment): JsonResponse
    {
        $data = $this->validatePayload($request, $equipment->id);

        $equipment->update($data);
        $this->context->flush();

        return response()->json(['data' => $equipment->fresh()]);
    }

    // DELETE /api/admin/equipment/{equipment}
    public function destroy(GymEquipment $equipment): JsonResponse
    {
        $equipment->delete();
        $this->context->flush();

        return response()->json(['ok' => true]);
    }

    // ── IRON IA ──────────────────────────────────────────────────────────────

    /**
     * GET /api/iron-ai/equipment-catalog
     *
     * Catálogo de equipos disponibles para que la IA NO recomiende ejercicios
     * con máquinas inexistentes. Solo lectura, cacheado. Respuesta estable:
     * { generated_at, total, names[], by_category{}, items[] }.
     */
    public function aiCatalog(): JsonResponse
    {
        return response()->json($this->context->catalog());
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'slug'                => ['nullable', 'string', 'max:255'],
            'category'            => ['nullable', Rule::in(GymEquipment::CATEGORIES)],
            'muscle_groups'       => ['nullable', 'array'],
            'muscle_groups.*'     => ['string', 'max:120'],
            'aliases'             => ['nullable', 'array'],
            'aliases.*'           => ['string', 'max:120'],
            'brand'               => ['nullable', 'string', 'max:255'],
            'model'               => ['nullable', 'string', 'max:255'],
            'serial_number'       => ['nullable', 'string', 'max:255'],
            'zone'                => ['nullable', 'string', 'max:255'],
            'quantity'            => ['nullable', 'integer', 'min:0'],
            'status'              => ['nullable', Rule::in(GymEquipment::STATUSES)],
            'image_url'           => ['nullable', 'string', 'max:1024'],
            'notes'               => ['nullable', 'string'],
            'is_available_for_ai' => ['nullable', 'boolean'],
            'acquired_at'         => ['nullable', 'date'],
            'last_maintenance_at' => ['nullable', 'date'],
        ]);
    }
}
