<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberDeviceSession;
use App\Models\Notification;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Notificaciones para la app Flutter (audience = member).
 *
 * El miembro se identifica por `document` (o por Bearer access_hash si la ruta
 * va protegida). Solo se exponen datos públicos vía Notification::toPublicArray.
 */
class NotificationController extends Controller
{
    /** Mapa categoría (app) → type (BD). 'all' y 'unread' son especiales. */
    private const CATEGORY_TYPES = [
        'payment'    => 'payment',
        'pagos'      => 'payment',
        'membership' => 'membership',
        'membresias' => 'membership',
        'class'      => 'class',
        'clases'     => 'class',
        'system'     => 'system',
        'sistema'    => 'system',
        'trainer'    => 'trainer',
        'entrenador' => 'trainer',
        'promotion'  => 'promotion',
        'promociones'=> 'promotion',
        'iron_ai'    => 'iron_ai',
        'routine'    => 'routine',
        'rutina'     => 'routine',
        'nutrition'  => 'nutrition',
        'nutricion'  => 'nutrition',
        'security'   => 'security',
        'seguridad'  => 'security',
    ];

    /** GET /api/notifications?document=&category=&search=&status= */
    public function index(Request $request): JsonResponse
    {
        if ($revoked = $this->revokedSessionResponse($request)) {
            return $revoked;
        }

        $member = $this->resolveMember($request);

        if (! $member) {
            return response()->json(['ok' => true, 'data' => [], 'unread_count' => 0]);
        }

        $query = Notification::forMember($member->id, $member->document_number, $member->created_at);

        $this->applyCategory($query, (string) $request->query('category', 'all'));

        if ($request->filled('status') && in_array($request->query('status'), ['read', 'unread'], true)) {
            $query->where('status', $request->query('status'));
        }

        $query->search($request->query('search'));

        $items = $query->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $unread = Notification::forMember($member->id, $member->document_number, $member->created_at)
            ->unread()
            ->count();

        return response()->json([
            'ok'           => true,
            'data'         => $items->map->toPublicArray()->values(),
            'unread_count' => $unread,
        ]);
    }

    /**
     * GET /api/notifications/stream — tiempo real (SSE). Empuja cada notificación
     * NUEVA del miembro en cuanto se crea. El cliente reconecta solo y mantiene
     * el polling como fallback. La dedup la dan `event_key` (backend) y el uuid
     * (cliente).
     */
    public function stream(Request $request): SymfonyResponse
    {
        if ($revoked = $this->revokedSessionResponse($request)) {
            return $revoked;
        }
        $member = $this->resolveMember($request);
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Sesión requerida.'], 401);
        }

        // Sólo lo nuevo tras conectar; lo histórico llega por el fetch normal.
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (Notification::forMember($member->id, $member->document_number, $member->created_at)->max('id') ?? 0);

        return SseStream::response(function () use ($member, &$cursor): void {
            $items = Notification::forMember($member->id, $member->document_number, $member->created_at)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(20)
                ->get();
            foreach ($items as $n) {
                SseStream::emit('notification', $n->toPublicArray(), $n->id);
                $cursor = $n->id;
            }
        }, 20, 1000); // sondeo 1s para latencia mínima del lado del usuario
    }

    /** GET /api/notifications/unread-count?document= */
    public function unreadCount(Request $request): JsonResponse
    {
        if ($revoked = $this->revokedSessionResponse($request)) {
            return $revoked;
        }

        $member = $this->resolveMember($request);

        $count = $member
            ? Notification::forMember($member->id, $member->document_number, $member->created_at)->unread()->count()
            : 0;

        return response()->json(['ok' => true, 'unread_count' => $count]);
    }

    /**
     * GET /api/notifications/popup-pending?document=
     * Notificaciones marcadas para mostrarse como push interno y aún no mostradas.
     * Ordenadas por prioridad (alta primero) y fecha; máximo 3.
     */
    public function popupPending(Request $request): JsonResponse
    {
        if ($revoked = $this->revokedSessionResponse($request)) {
            return $revoked;
        }

        $member = $this->resolveMember($request);

        if (! $member) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        $items = Notification::forMember($member->id, $member->document_number, $member->created_at)
            ->where('should_popup', true)
            ->whereNull('popup_shown_at')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $items->map->toPublicArray()->values(),
        ]);
    }

    /**
     * POST /api/notifications/{uuid}/popup-shown
     * Sella la notificación como ya mostrada (no se vuelve a hacer popup).
     */
    public function popupShown(Request $request, string $uuid): JsonResponse
    {
        $member = $this->resolveMember($request);

        $notification = Notification::where('uuid', $uuid)
            ->where('audience', Notification::AUDIENCE_MEMBER)
            ->first();

        if (! $notification || ! $this->belongsToMember($notification, $member)) {
            return response()->json(['ok' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        if ($notification->popup_shown_at === null) {
            $notification->popup_shown_at = now();
            $notification->save();
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/notifications/{uuid}/read */
    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $member = $this->resolveMember($request);

        $notification = Notification::where('uuid', $uuid)
            ->where('audience', Notification::AUDIENCE_MEMBER)
            ->first();

        if (! $notification || ! $this->belongsToMember($notification, $member)) {
            return response()->json(['ok' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->markRead();

        return response()->json(['ok' => true, 'data' => $notification->toPublicArray()]);
    }

    /** POST /api/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $member = $this->resolveMember($request);

        if (! $member) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        $updated = Notification::forMember($member->id, $member->document_number, $member->created_at)
            ->unread()
            ->update(['status' => Notification::STATUS_READ, 'read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /** DELETE /api/notifications/{uuid} — ocultar (opcional). */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $member = $this->resolveMember($request);

        $notification = Notification::where('uuid', $uuid)
            ->where('audience', Notification::AUDIENCE_MEMBER)
            ->first();

        if (! $notification || ! $this->belongsToMember($notification, $member)) {
            return response()->json(['ok' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->delete();

        return response()->json(['ok' => true]);
    }

    // ── Internos ───────────────────────────────────────────────────────────────

    /** Resuelve el miembro por Bearer access_hash o por ?document=. */
    /**
     * Si el bearer corresponde a una sesión REVOCADA (la cuenta se está usando
     * en otro dispositivo), devuelve 401 con `session_revoked` para que la app
     * —que sondea estos endpoints constantemente— redirija al login de inmediato.
     */
    private function revokedSessionResponse(Request $request): ?JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }
        if (Member::resolveByToken($token)) {
            return null; // sesión válida o access_hash legacy
        }
        $isRevoked = MemberDeviceSession::query()
            ->whereNotNull('revoked_at')
            ->where('token_hash', MemberDeviceSession::hashToken($token))
            ->exists();
        if (! $isRevoked) {
            return null;
        }

        return response()->json([
            'ok'      => false,
            'code'    => 'session_revoked',
            'message' => 'Tu sesión se cerró porque la cuenta se está usando en otro dispositivo.',
        ], 401);
    }

    private function resolveMember(Request $request): ?Member
    {
        if ($preset = $request->attributes->get('auth_member')) {
            return $preset;
        }

        if ($token = $request->bearerToken()) {
            $byToken = Member::resolveByToken($token);
            if ($byToken) {
                return $byToken;
            }
        }

        $document = Member::normalizeDocumentNumber(
            $request->query('document') ?? $request->input('document')
        );

        if (! $document) {
            return null;
        }

        return Member::where('document_number', $document)->first();
    }

    private function belongsToMember(Notification $n, ?Member $member): bool
    {
        if (! $member) {
            return false;
        }
        if ($n->member_id && (int) $n->member_id === (int) $member->id) {
            return true;
        }
        if ($n->document && $n->document === $member->document_number) {
            return true;
        }
        // Difusión global (sin destinatario concreto).
        return $n->member_id === null && $n->document === null;
    }

    private function applyCategory($query, string $category): void
    {
        $category = strtolower(trim($category));

        if ($category === '' || $category === 'all' || $category === 'todas') {
            return;
        }
        if (in_array($category, ['unread', 'no_leidas', 'noleidas', 'no-leidas'], true)) {
            $query->unread();
            return;
        }
        if (isset(self::CATEGORY_TYPES[$category])) {
            $query->where('type', self::CATEGORY_TYPES[$category]);
        }
    }
}
