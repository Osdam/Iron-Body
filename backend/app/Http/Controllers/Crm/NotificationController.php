<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\SseStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notificaciones para el CRM Angular (audience = admin).
 *
 * Búsqueda funcional por nombre de miembro, documento, referencia de pago,
 * tipo, título, mensaje, estado y rango de fechas. Pensado para volúmenes
 * grandes: filtra en BD y pagina.
 */
class NotificationController extends Controller
{
    private const CATEGORY_TYPES = [
        'payment'     => 'payment',
        'pagos'       => 'payment',
        'membership'  => 'membership',
        'membresias'  => 'membership',
        'class'       => 'class',
        'clases'      => 'class',
        'system'      => 'system',
        'sistema'     => 'system',
        'trainer'     => 'trainer',
        'entrenador'  => 'trainer',
        'promotion'   => 'promotion',
        'promociones' => 'promotion',
        'iron_ai'     => 'iron_ai',
        'routine'     => 'routine',
        'rutina'      => 'routine',
    ];

    public function __construct(private NotificationService $notifications)
    {
    }

    /** GET /api/admin/notifications?search=&category=&status=&from=&to=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->where('audience', Notification::AUDIENCE_ADMIN);

        $this->applyCategory($query, (string) $request->query('category', 'all'));

        if ($request->filled('status') && in_array($request->query('status'), ['read', 'unread'], true)) {
            $query->where('status', $request->query('status'));
        }

        $this->applySearch($query, $request->query('search'));

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        $unread = (clone $query)->where('status', Notification::STATUS_UNREAD)->count();

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'ok'           => true,
            'data'         => collect($page->items())->map->toPublicArray()->values(),
            'unread_count' => $unread,
            'meta'         => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/notifications/unread-count */
    public function unreadCount(): JsonResponse
    {
        $count = Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('status', Notification::STATUS_UNREAD)
            ->count();

        return response()->json(['ok' => true, 'unread_count' => $count]);
    }

    /**
     * GET /api/admin/notifications/stream — tiempo real (SSE) para el CRM.
     * Empuja cada notificación admin nueva en cuanto se crea. EventSource del
     * navegador reconecta solo; el polling queda como fallback.
     */
    public function stream(Request $request): StreamedResponse
    {
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (Notification::where('audience', Notification::AUDIENCE_ADMIN)->max('id') ?? 0);

        return SseStream::response(function () use (&$cursor): void {
            $items = Notification::where('audience', Notification::AUDIENCE_ADMIN)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(30)
                ->get();
            foreach ($items as $n) {
                SseStream::emit('notification', $n->toPublicArray(), $n->id);
                $cursor = $n->id;
            }
        }, 20, 1500); // sondeo 1.5s para el CRM (pocas conexiones admin)
    }

    /** POST /api/admin/notifications/{uuid}/read */
    public function markRead(string $uuid): JsonResponse
    {
        $notification = Notification::where('uuid', $uuid)
            ->where('audience', Notification::AUDIENCE_ADMIN)
            ->first();

        if (! $notification) {
            return response()->json(['ok' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->markRead();

        return response()->json(['ok' => true, 'data' => $notification->toPublicArray()]);
    }

    /** POST /api/admin/notifications/read-all */
    public function readAll(): JsonResponse
    {
        $updated = Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('status', Notification::STATUS_UNREAD)
            ->update(['status' => Notification::STATUS_READ, 'read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /**
     * POST /api/admin/notifications — notificación manual desde el CRM.
     * Puede dirigirse a un miembro (member_id/document) o difundirse a todos
     * los miembros, o quedar como nota interna del CRM (audience=admin).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => 'required|string|max:160',
            'message'  => 'required|string|max:1000',
            'audience' => 'nullable|in:member,admin',
            'type'     => 'nullable|string|max:40',
            'priority' => 'nullable|in:low,medium,high',
            'member_id'=> 'nullable|integer|exists:members,id',
            'document' => 'nullable|string|max:40',
        ]);

        $audience = $data['audience'] ?? Notification::AUDIENCE_MEMBER;
        $member = null;
        if (! empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        } elseif (! empty($data['document'])) {
            $member = Member::where('document_number', Member::normalizeDocumentNumber($data['document']))->first();
        }

        $attrs = [
            'type'     => $data['type'] ?? 'promotion',
            'title'    => $data['title'],
            'message'  => $data['message'],
            'priority' => $data['priority'] ?? 'medium',
        ];

        $notification = $audience === Notification::AUDIENCE_ADMIN
            ? $this->notifications->createAdminNotification(array_merge($attrs, ['member' => $member]))
            : $this->notifications->createMemberNotification($member, $attrs);

        return response()->json(['ok' => true, 'data' => $notification?->toPublicArray()], 201);
    }

    // ── Internos ───────────────────────────────────────────────────────────────

    private function applyCategory(Builder $query, string $category): void
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'all' || $category === 'todas') {
            return;
        }
        if (in_array($category, ['unread', 'no_leidas', 'noleidas'], true)) {
            $query->where('status', Notification::STATUS_UNREAD);
            return;
        }
        if (isset(self::CATEGORY_TYPES[$category])) {
            $query->where('type', self::CATEGORY_TYPES[$category]);
        }
    }

    /** Búsqueda por nombre de miembro, documento, referencia, tipo, etc. */
    private function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $like = '%' . $term . '%';

        $query->where(function (Builder $sub) use ($like): void {
            $sub->where('title', 'like', $like)
                ->orWhere('message', 'like', $like)
                ->orWhere('document', 'like', $like)
                ->orWhere('type', 'like', $like)
                ->orWhere('status', 'like', $like)
                // metadata es JSON guardado como texto: cubre referencia, monto
                // y member_name aunque el miembro ya no exista.
                ->orWhere('metadata', 'like', $like)
                // Nombre/email vigente del miembro enlazado.
                ->orWhereHas('member', function (Builder $m) use ($like): void {
                    $m->where('full_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('document_number', 'like', $like);
                });
        });
    }
}
