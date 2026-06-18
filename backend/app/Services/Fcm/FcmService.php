<?php

namespace App\Services\Fcm;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía notificaciones push (FCM) a los dispositivos de un miembro. Best-effort:
 * si FCM no está configurado o algo falla, registra en log y NO rompe el flujo
 * (el SSE in-app sigue cubriendo la app abierta).
 */
class FcmService
{
    public function __construct(private FcmHttpV1Client $client)
    {
    }

    public function enabled(): bool
    {
        return $this->client->isConfigured();
    }

    /** Empuja una notificación a todos los tokens activos del miembro. */
    public function sendToMember(?Member $member, Notification $notification): void
    {
        if (! $member) {
            return;
        }
        if (! $this->enabled()) {
            Log::info('FCM no configurado: push omitido (solo SSE in-app).', [
                'member' => $member->id,
                'notif'  => $notification->uuid,
            ]);
            return;
        }

        $tokens = MemberDeviceToken::query()
            ->where('member_id', $member->id)
            ->pluck('token');

        foreach ($tokens as $token) {
            try {
                $unregistered = false;
                $ok = $this->client->send($this->buildMessage($token, $notification), $unregistered);
                if (! $ok && $unregistered) {
                    MemberDeviceToken::where('token', $token)->delete(); // limpia tokens muertos
                }
            } catch (Throwable $e) {
                Log::warning('FCM: fallo enviando a token', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Empuja a TODOS los dispositivos activos (broadcast). Para notificaciones de
     * miembro sin destinatario fijo (member_id null), como un evento publicado.
     * Best-effort: tokens muertos se limpian; un fallo nunca rompe el flujo.
     */
    public function sendToAllMembers(Notification $notification): void
    {
        if (! $this->enabled()) {
            Log::info('FCM no configurado: broadcast omitido (solo SSE in-app).', [
                'notif' => $notification->uuid,
            ]);
            return;
        }

        MemberDeviceToken::query()
            ->distinct()
            ->pluck('token')
            ->chunk(500)
            ->each(function ($tokens) use ($notification): void {
                foreach ($tokens as $token) {
                    try {
                        $unregistered = false;
                        $ok = $this->client->send($this->buildMessage($token, $notification), $unregistered);
                        if (! $ok && $unregistered) {
                            MemberDeviceToken::where('token', $token)->delete();
                        }
                    } catch (Throwable $e) {
                        Log::warning('FCM: fallo enviando a token (broadcast)', ['error' => $e->getMessage()]);
                    }
                }
            });
    }

    /** Mensaje HTTP v1: notification (visible app cerrada) + data (ruteo/tap). */
    private function buildMessage(string $token, Notification $n): array
    {
        return [
            'token'        => $token,
            'notification' => [
                'title' => (string) $n->title,
                'body'  => (string) $n->message,
            ],
            'data' => array_map('strval', array_filter([
                'uuid'        => $n->uuid,
                'type'        => $n->type,
                'action_type' => $n->action_type,
                'priority'    => $n->priority,
            ], fn ($v) => $v !== null)),
            'android' => [
                'priority'     => 'high',
                'notification' => [
                    'channel_id' => 'iron_body_high',
                    'sound'      => 'default',
                ],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }
}
