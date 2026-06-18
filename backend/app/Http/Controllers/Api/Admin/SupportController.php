<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberSupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de Soporte del CRM: lista, detalle y gestión de estado de los reportes
 * que envían los miembros desde la app. Patrón del resto del CRM admin.
 */
class SupportController extends Controller
{
    /** GET /api/admin/support?status=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = MemberSupportTicket::query()->with('member:id,full_name')->orderByDesc('id');

        $status = (string) $request->query('status', '');
        if (in_array($status, ['new', 'in_progress', 'resolved'], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('message', 'like', $like)
                    ->orWhere('document', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $like));
            });
        }

        $unread = MemberSupportTicket::where('status', MemberSupportTicket::STATUS_NEW)->count();
        $page = $query->paginate((int) min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'ok'        => true,
            'data'      => collect($page->items())->map->toPublicArray()->values(),
            'new_count' => $unread,
            'meta'      => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/support/{ticket} */
    public function show(MemberSupportTicket $ticket): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $ticket->load('member:id,full_name')->toPublicArray()]);
    }

    /** PATCH /api/admin/support/{ticket} — cambia estado / agrega nota interna. */
    public function update(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status'     => ['nullable', 'in:new,in_progress,resolved'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $ticket->status = $data['status'];
            $ticket->resolved_at = $data['status'] === MemberSupportTicket::STATUS_RESOLVED ? now() : null;
        }
        if (array_key_exists('admin_note', $data)) {
            $ticket->admin_note = $data['admin_note'];
        }
        $ticket->save();

        return response()->json(['ok' => true, 'data' => $ticket->fresh('member')->toPublicArray()]);
    }

    /** GET /api/admin/support/unread-count */
    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'ok'        => true,
            'new_count' => MemberSupportTicket::where('status', MemberSupportTicket::STATUS_NEW)->count(),
        ]);
    }
}
