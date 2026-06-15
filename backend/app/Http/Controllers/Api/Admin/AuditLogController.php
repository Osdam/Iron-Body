<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bitácora de auditoría del CRM (append-only). Patrón /admin/* del CRM (sin auth
 * a nivel de ruta; el actor lo reporta el cliente y solo sirve como traza).
 *
 *  • GET  /api/admin/audit-logs   → lista filtrable (días, usuario, módulo,
 *                                   acción, búsqueda, solo-cambios). Reciente 1º.
 *  • POST /api/admin/audit-logs   → registra un evento (lo emite el CRM tras una
 *                                   operación exitosa contra el backend).
 */
class AuditLogController extends Controller
{
    /** Tope duro para no devolver historiales gigantes de una sola vez. */
    private const MAX_LIMIT = 1000;

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query();

        // Periodo por días (0 / ausente = todo el historial).
        $days = (int) $request->query('days', 0);
        if ($days > 0) {
            $query->where('created_at', '>=', Carbon::now()->subDays($days)->startOfDay());
        }
        // Rango explícito opcional (tiene prioridad si llega).
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
        }

        if ($request->filled('actor')) {
            $query->where('actor_name', $request->query('actor'));
        }
        if ($request->filled('module')) {
            $query->where('module', $request->query('module'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }
        if ($request->boolean('only_changes')) {
            $query->whereNotNull('changes')->where('changes', '!=', '[]');
        }
        if ($request->filled('search')) {
            $term = '%' . $request->query('search') . '%';
            $query->where(fn ($q) => $q->where('summary', 'like', $term)
                ->orWhere('module', 'like', $term)
                ->orWhere('entity', 'like', $term)
                ->orWhere('entity_id', 'like', $term)
                ->orWhere('target_name', 'like', $term)
                ->orWhere('actor_name', 'like', $term));
        }

        $limit = min(max((int) $request->query('limit', 500), 1), self::MAX_LIMIT);

        $items = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => $log->toPublicArray())
            ->values();

        return response()->json([
            'data' => $items,
            'count' => $items->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|in:' . implode(',', AuditLog::ACTIONS),
            'module' => 'required|string|max:60',
            'entity' => 'required|string|max:60',
            'entityId' => 'nullable',
            'targetName' => 'nullable|string|max:191',
            'actorId' => 'nullable',
            'actorName' => 'nullable|string|max:120',
            'actorRole' => 'nullable|string|max:60',
            'summary' => 'nullable|string|max:1000',
            'changes' => 'nullable|array',
            'changes.*.field' => 'required|string|max:120',
            'metadata' => 'nullable|array',
        ]);

        $log = AuditLog::create([
            'action' => $data['action'],
            'module' => $data['module'],
            'entity' => $data['entity'],
            'entity_id' => isset($data['entityId']) ? (string) $data['entityId'] : null,
            'target_name' => $data['targetName'] ?? null,
            'actor_id' => isset($data['actorId']) ? (string) $data['actorId'] : null,
            'actor_name' => $data['actorName'] ?? 'Sistema',
            'actor_role' => $data['actorRole'] ?? null,
            'summary' => $data['summary'] ?? null,
            'changes' => $data['changes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'created_at' => Carbon::now(),
        ]);

        return response()->json(['data' => $log->toPublicArray()], 201);
    }
}
