<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSupportTicket;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Soporte desde la app: el miembro reporta un error / lo que le pasó. Adjunta
 * contexto técnico automático (plataforma, dispositivo, pantalla, errores
 * recientes) y aterriza en la bandeja de Soporte del CRM con aviso en vivo.
 */
class MemberSupportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'type'          => ['nullable', 'string', 'max:40'],
            'message'       => ['required', 'string', 'max:4000'],
            'app_version'   => ['nullable', 'string', 'max:40'],
            'platform'      => ['nullable', 'string', 'max:40'],
            'device_name'   => ['nullable', 'string', 'max:120'],
            'screen'        => ['nullable', 'string', 'max:120'],
            'recent_errors' => ['nullable', 'array'],
            'recent_errors.*' => ['string', 'max:1000'],
            'metadata'      => ['nullable', 'array'],
        ]);

        $ticket = MemberSupportTicket::create([
            'member_id'     => $member?->id,
            'user_id'       => $member?->user_id,
            'document'      => $member?->document_number,
            'type'          => $data['type'] ?? 'other',
            'message'       => $data['message'],
            'status'        => MemberSupportTicket::STATUS_NEW,
            'app_version'   => $data['app_version'] ?? null,
            'platform'      => $data['platform'] ?? null,
            'device_name'   => $data['device_name'] ?? null,
            'screen'        => $data['screen'] ?? null,
            'recent_errors' => $data['recent_errors'] ?? [],
            'metadata'      => $data['metadata'] ?? [],
        ]);

        // Aviso al CRM (campana + refresco en vivo de la bandeja).
        app(NotificationService::class)->notifySupportTicket($ticket->fresh('member'));

        return response()->json([
            'ok'      => true,
            'message' => 'Recibimos tu reporte. Nuestro equipo lo revisará.',
            'id'      => $ticket->id,
        ], 201);
    }
}
