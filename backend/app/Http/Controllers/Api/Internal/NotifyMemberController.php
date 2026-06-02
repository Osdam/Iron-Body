<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint INTERNO disparado por n8n (firmado HMAC, middleware
 * automation.internal). n8n mapea el evento a un mensaje seguro y llama aquí;
 * Laravel crea la notificación interna (con anti-spam) y, si hay FCM, push.
 *
 *  POST /api/internal/automation/notify-member
 *
 * n8n NO consulta PostgreSQL ni construye contexto IA. Solo mensaje + ruta.
 */
class NotifyMemberController extends Controller
{
    public function __construct(private readonly AppNotificationService $service)
    {
    }

    public function notify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'required|integer',
            'type' => 'required|string|max:80',
            'title' => 'required|string|max:140',
            'body' => 'required|string|max:500',
            'action_route' => 'nullable|string|max:200',
            'priority' => 'nullable|string|in:low,normal,high',
            'payload' => 'nullable|array',
        ]);

        // No notificar a miembros inexistentes.
        $member = Member::find($data['member_id']);
        if ($member === null) {
            return response()->json(['ok' => false, 'message' => 'Miembro no encontrado.'], 404);
        }

        $result = $this->service->createForMember(
            memberId: $member->id,
            type: $data['type'],
            title: $data['title'],
            body: $data['body'],
            actionRoute: $data['action_route'] ?? null,
            payload: $data['payload'] ?? [],
            priority: $data['priority'] ?? 'normal',
            source: 'automation',
        );

        return response()->json([
            'ok' => true,
            'notification_id' => $result['notification']?->id,
            'status' => $result['status'], // created | skipped_duplicate | skipped_limit
        ]);
    }
}
