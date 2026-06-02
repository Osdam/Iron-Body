<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centro de notificaciones del miembro (auth.member). Las notificaciones
 * proactivas del coach IRON IA viven en app_notifications.
 */
class AppNotificationController extends Controller
{
    public function __construct(private readonly AppNotificationService $service)
    {
    }

    /** GET /api/app/notifications */
    public function index(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return $this->unauth();
        }
        return response()->json([
            'success' => true,
            'data' => $this->service->listForMember($member->id),
            'unread_count' => $this->service->unreadCount($member->id),
        ]);
    }

    /** GET /api/app/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return $this->unauth();
        }
        return response()->json([
            'success' => true,
            'unread_count' => $this->service->unreadCount($member->id),
        ]);
    }

    /** POST /api/app/notifications/{id}/read */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return $this->unauth();
        }
        $ok = $this->service->markAsRead($id, $member->id);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'No encontrada.'], 404);
        }
        return response()->json(['success' => true]);
    }

    /** POST /api/app/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return $this->unauth();
        }
        $updated = $this->service->markAllAsRead($member->id);
        return response()->json(['success' => true, 'updated' => $updated]);
    }

    private function unauth(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
    }
}
