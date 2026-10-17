<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\SseStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notificaciones para el CRM Angular (audience = admin).
 *
 * Búsqueda funcional por nombre de miembro, documento, referencia de pago,
 * tipo, título, mensaje, estado y rango de fechas. Pensado para volúmenes
 * grandes: filtra en BD y pagina.
 *
 * VISIBILIDAD POR PERMISO. Una fila con `required_permission` solo existe para
 * la sesión que tiene ese permiso: no sale en la lista, no cuenta como no leída,
 * no viaja por el stream y no se puede marcar como leída (ni sola ni con
 * «marcar todas»). Las filas sin `required_permission` —todas las anteriores—
 * se comportan exactamente igual que antes. El leído/no leído sigue siendo
 * global, como siempre (limitación conocida).
 *
 * VISIBILIDAD POR TIPO. Las filas sin `required_permission` de un tipo de
 * {@see self::TYPE_PERMISSIONS} exigen el permiso de ver ese módulo: un aviso
 * de pago solo lo ve quien puede ver pagos. Antes la bandeja era global y
 * enseñaba los pagos a cualquier sesión (hallazgo P-1), y con las alertas
 * sonoras eso sería además un aviso audible para quien no debe. Los tipos que
 * no están en la lista (sistema, seguridad, promociones…) siguen como siempre.
 */
class NotificationController extends Controller
{
    private const CATEGORY_TYPES = [
        'payment' => 'payment',
        'pagos' => 'payment',
        'membership' => 'membership',
        'membresias' => 'membership',
        'class' => 'class',
        'clases' => 'class',
        'system' => 'system',
        'sistema' => 'system',
        'trainer' => 'trainer',
        'entrenador' => 'trainer',
        'promotion' => 'promotion',
        'promociones' => 'promotion',
        'iron_ai' => 'iron_ai',
        'routine' => 'routine',
        'rutina' => 'routine',
        'valuation' => 'valuation',
        'valuations' => 'valuation',
        'valoraciones' => 'valuation',
    ];

    /**
     * Lo ÚNICO que se crea a mano desde `store`: avisos internos y promociones.
     * Lista de permitidos y no de prohibidos: un `payment`, `security` o
     * `valuation` hecho a mano sonaría en el CRM como uno real, y cualquier tipo
     * de sistema que nazca mañana nacería ya fabricable.
     */
    private const MANUAL_TYPES = ['system', 'promotion'];

    /** Permiso para ver los avisos de cada tipo (las filas sin `required_permission`). */
    public const TYPE_PERMISSIONS = [
        'payment' => 'payments.view',
        'membership' => 'members.view',
        'class' => 'classes.view',
        'routine' => 'routines.view',
        'trainer' => 'trainers.view',
    ];

    public function __construct(private NotificationService $notifications) {}

    /** GET /api/admin/notifications?search=&category=&status=&from=&to=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->where('audience', Notification::AUDIENCE_ADMIN);
        $this->visibleTo($query, $request);

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
            'ok' => true,
            'data' => collect($page->items())->map->toPublicArray()->values(),
            'unread_count' => $unread,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $query = Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('status', Notification::STATUS_UNREAD);
        $count = $this->visibleTo($query, $request)->count();

        return response()->json(['ok' => true, 'unread_count' => $count]);
    }

    /**
     * GET /api/admin/notifications/stream — tiempo real (SSE) para el CRM.
     * Empuja cada notificación admin nueva en cuanto se crea. EventSource del
     * navegador reconecta solo; el polling queda como fallback.
     */
    public function stream(Request $request): StreamedResponse
    {
        // Una vez por conexión: el stream dura 20 s y el CRM reconecta.
        $permisos = $this->sessionPermissions($request);
        $cursor = $this->initialCursor($request, $permisos);

        // Al abrir, el cursor de partida (`ready`): el CRM lo devuelve como `after_id`
        // al reconectar y así no pierde lo creado en el hueco entre dos conexiones.
        // Solo es un número; no dice nada de ninguna notificación.
        $alAbrir = function () use (&$cursor): void {
            SseStream::emit('ready', ['cursor' => $cursor], $cursor);
        };

        return SseStream::response(function () use (&$cursor, $permisos): void {
            $items = $this->streamBatch($permisos, $cursor);
            foreach ($items as $n) {
                SseStream::emit('notification', $n->toPublicArray(), $n->id);
                $cursor = $n->id;
            }
        }, 20, 1500, $alAbrir); // sondeo 1.5s para el CRM (pocas conexiones admin)
    }

    /** POST /api/admin/notifications/{uuid}/read */
    public function markRead(Request $request, string $uuid): JsonResponse
    {
        // Lo que no se puede ver no se puede marcar: 404, igual que si no existiera.
        $query = Notification::where('uuid', $uuid)
            ->where('audience', Notification::AUDIENCE_ADMIN);
        $notification = $this->visibleTo($query, $request)->first();

        if (! $notification) {
            return response()->json(['ok' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->markRead();

        return response()->json(['ok' => true, 'data' => $notification->toPublicArray()]);
    }

    /** POST /api/admin/notifications/read-all — solo las que esta sesión puede ver. */
    public function readAll(Request $request): JsonResponse
    {
        $query = Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('status', Notification::STATUS_UNREAD);
        $updated = $this->visibleTo($query, $request)
            ->update(['status' => Notification::STATUS_READ, 'read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /**
     * POST /api/admin/notifications — notificación manual desde el CRM.
     * Puede dirigirse a un miembro (member_id/document) o difundirse a todos
     * los miembros, o quedar como nota interna del CRM (audience=admin).
     *
     * Solo los tipos de {@see self::MANUAL_TYPES}; el resto los escribe el
     * sistema (422). Una `valuation` con `priority=high` hace sonar la alerta de
     * la agenda en el CRM (revisión de seguridad V-1), y un `payment` o un
     * `security` harían sonar las generales. `required_permission` no se acepta
     * aquí nunca (no está en la validación).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'message' => 'required|string|max:1000',
            'audience' => 'nullable|in:member,admin',
            'type' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! in_array(mb_strtolower(trim($value)), self::MANUAL_TYPES, true)) {
                    $fail('A mano solo se crean avisos del sistema o promociones; los demás tipos los crea el sistema.');
                }
            }],
            'priority' => 'nullable|in:low,medium,high',
            'member_id' => 'nullable|integer|exists:members,id',
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
            'type' => isset($data['type']) ? mb_strtolower(trim($data['type'])) : 'promotion',
            'title' => $data['title'],
            'message' => $data['message'],
            'priority' => $data['priority'] ?? 'medium',
        ];

        // Un aviso interno escrito a mano se distingue de uno del sistema por un
        // dato, no por el texto. Solo en los del CRM: a la app del socio no viaja
        // el id de quien lo escribió.
        $notification = $audience === Notification::AUDIENCE_ADMIN
            ? $this->notifications->createAdminNotification(array_merge($attrs, [
                'member' => $member,
                'metadata' => ['source' => 'manual', 'created_by_admin_id' => AdminActor::from($request)?->id],
            ]))
            : $this->notifications->createMemberNotification($member, $attrs);

        return response()->json(['ok' => true, 'data' => $notification?->toPublicArray()], 201);
    }

    // ── Internos ───────────────────────────────────────────────────────────────

    /**
     * Desde dónde empieza el stream: el `after_id` del cliente o la última fila que
     * ESTA sesión puede ver. Viaja al cliente en `ready`; el máximo global contaría
     * lo que no ve (pagos, citas).
     *
     * @param  list<string>  $permisos
     */
    private function initialCursor(Request $request, array $permisos): int
    {
        if ($request->filled('after_id')) {
            return (int) $request->query('after_id');
        }

        return (int) ($this->applyVisibility(Notification::where('audience', Notification::AUDIENCE_ADMIN), $permisos)->max('id') ?? 0);
    }

    /**
     * El lote que empuja el stream en cada vuelta: las notificaciones admin
     * posteriores al cursor que esta sesión puede ver.
     *
     * @param  list<string>  $permisos
     * @return Collection<int, Notification>
     */
    private function streamBatch(array $permisos, int $cursor): Collection
    {
        return $this->applyVisibility(Notification::where('audience', Notification::AUDIENCE_ADMIN), $permisos)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(30)
            ->get();
    }

    /** Solo las filas sin permiso exigido o con un permiso que la sesión tiene. */
    private function visibleTo(Builder $query, Request $request): Builder
    {
        return $this->applyVisibility($query, $this->sessionPermissions($request));
    }

    /** @param  list<string>  $permisos */
    private function applyVisibility(Builder $query, array $permisos): Builder
    {
        // Tipos cuyo permiso de ver NO tiene esta sesión: sus filas sin permiso explícito no existen para ella.
        $vedados = array_keys(array_filter(
            self::TYPE_PERMISSIONS,
            fn (string $permiso): bool => ! in_array($permiso, $permisos, true),
        ));

        return $query->where(function (Builder $q) use ($permisos, $vedados): void {
            $q->where(function (Builder $sin) use ($vedados): void {
                $sin->whereNull('required_permission');
                if ($vedados !== []) {
                    // `type` NULL no es ninguno de los vedados (NOT IN con NULL daría NULL y la escondería).
                    $sin->where(fn (Builder $t) => $t->whereNull('type')->orWhereNotIn('type', $vedados));
                }
            });
            if ($permisos !== []) {
                $q->orWhereIn('required_permission', $permisos);
            }
        });
    }

    /**
     * Permisos efectivos de la sesión (nombres canónicos). El token compartido
     * no es una persona: obtiene lo de siempre, solo lectura.
     *
     * @return list<string>
     */
    private function sessionPermissions(Request $request): array
    {
        return array_values(array_unique(array_map(
            CrmPermission::canonical(...),
            CrmPermission::forAdmin(AdminActor::from($request)),
        )));
    }

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
        $like = '%'.$term.'%';

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
