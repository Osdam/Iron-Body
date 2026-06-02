<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Services\Fcm\FcmHttpV1Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío de PUSH para las notificaciones del coach (centro app_notifications).
 *
 * Reutiliza el cliente FCM HTTP v1 existente. Si FCM no está configurado, NO
 * rompe: simplemente no envía (la notificación in-app sigue creada). Solo manda
 * título, body y action_route — nunca datos sensibles. Marca delivered_at.
 */
class AppPushService
{
    public function __construct(private readonly FcmHttpV1Client $client)
    {
    }

    public function enabled(): bool
    {
        return $this->client->isConfigured();
    }

    public function sendToMember(Member $member, AppNotification $notification): void
    {
        if (!$this->enabled()) {
            // FCM no listo → push omitido (la notificación in-app ya existe).
            return;
        }

        // Solo tokens ACTIVOS (los inactivos se descartan).
        $tokens = MemberDeviceToken::query()
            ->where('member_id', $member->id)
            ->where('is_active', true)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            return;
        }

        $delivered = false;
        foreach ($tokens as $token) {
            try {
                $unregistered = false;
                $ok = $this->client->send($this->buildMessage($token, $notification), $unregistered);
                if ($ok) {
                    $delivered = true;
                } elseif ($unregistered) {
                    // Token inválido → desactivar (no borrar; conserva auditoría).
                    MemberDeviceToken::where('token', $token)->update(['is_active' => false]);
                }
            } catch (Throwable $e) {
                Log::warning('AppPushService: fallo enviando token', ['error' => class_basename($e)]);
            }
        }

        if ($delivered) {
            $notification->update(['delivered_at' => now()]);
        }
    }

    /** Mensaje FCM: solo título/body + data de ruteo (action_route). */
    private function buildMessage(string $token, AppNotification $n): array
    {
        return [
            'token' => $token,
            'notification' => [
                'title' => (string) $n->title,
                'body' => (string) $n->body,
            ],
            'data' => array_map('strval', array_filter([
                'notification_id' => (string) $n->id,
                'type' => $n->type,
                'action_type' => $n->action_type,
                'action_route' => $n->action_route,
                'priority' => $n->priority,
                'source' => 'iron_body',
            ], fn ($v) => $v !== null)),
        ];
    }
}
