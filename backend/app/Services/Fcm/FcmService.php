<?php

namespace App\Services\Fcm;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\Notification;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\PushChannel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía notificaciones push (FCM) a los dispositivos de un miembro. Best-effort:
 * si FCM no está configurado o algo falla, registra en log y NO rompe el flujo
 * (el SSE in-app sigue cubriendo la app abierta).
 */
class FcmService
{
    public function __construct(private FcmHttpV1Client $client) {}

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
                'notif' => $notification->uuid,
            ]);

            return;
        }

        $tokens = MemberDeviceToken::query()
            ->where('member_id', $member->id)
            ->where('is_active', true)
            ->pluck('token');

        foreach ($tokens as $token) {
            try {
                $unregistered = false;
                $ok = $this->client->send($this->buildMessage($token, $notification), $unregistered);
                if (! $ok && $unregistered) {
                    $this->deactivate($token);
                }
            } catch (Throwable $e) {
                Log::warning('FCM: fallo enviando a token', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Un token muerto se DESACTIVA, no se borra.
     *
     * Borrarlo perdía el rastro de qué dispositivo tuvo el socio y desde
     * cuándo, y hacía que el mismo teléfono reapareciera como si fuera nuevo.
     */
    private function deactivate(string $token): void
    {
        MemberDeviceToken::where('token', $token)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
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
            ->where('is_active', true)
            ->distinct()
            ->pluck('token')
            ->chunk(500)
            ->each(function ($tokens) use ($notification): void {
                foreach ($tokens as $token) {
                    try {
                        $unregistered = false;
                        $ok = $this->client->send($this->buildMessage($token, $notification), $unregistered);
                        if (! $ok && $unregistered) {
                            $this->deactivate($token);
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
        $category = NotificationCategory::fromLegacyType($n->type);

        return [
            'token' => $token,
            'notification' => [
                'title' => (string) $n->title,
                'body' => (string) $n->message,
            ],
            'data' => array_map('strval', array_filter([
                'uuid' => $n->uuid,
                'type' => $n->type,
                'category' => $category,
                'action_type' => $n->action_type,
                'priority' => $n->priority,
            ], fn ($v) => $v !== null)),
            'android' => PushChannel::androidBlock($category),
            'apns' => PushChannel::apnsBlock($category),
        ];
    }
}
