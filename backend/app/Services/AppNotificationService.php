<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Member;
use Illuminate\Support\Facades\Log;

/**
 * Centro de notificaciones proactivas del coach IRON IA.
 *
 * Crea notificaciones internas para el miembro con límites anti-spam:
 *  - no repetir el mismo tipo dentro de una ventana (default 12h)
 *  - máximo N por tipo por día
 *  - máximo N totales por día
 * Sanea el payload (nunca datos sensibles). Push opcional vía AppPushService.
 */
class AppNotificationService
{
    /** Límites anti-spam (Fase I). */
    private const DEFAULT_WINDOW_MINUTES = 720; // 12h
    private const MAX_PER_TYPE_PER_DAY = 1;
    private const MAX_TOTAL_PER_DAY = 3;

    public function __construct(private readonly AppPushService $push)
    {
    }

    /**
     * Crea una notificación para el miembro respetando los límites.
     *
     * @return array{notification: ?AppNotification, status: string}
     *   status: created | skipped_duplicate | skipped_limit
     */
    public function createForMember(
        int $memberId,
        string $type,
        string $title,
        string $body,
        ?string $actionRoute = null,
        array $payload = [],
        string $priority = 'normal',
        string $source = 'automation',
    ): array {
        // 1) Anti-duplicado por ventana.
        if ($this->alreadySentRecently($memberId, $type, self::DEFAULT_WINDOW_MINUTES)) {
            return ['notification' => null, 'status' => 'skipped_duplicate'];
        }

        // 2) Límite por tipo/día y total/día.
        $startOfDay = now()->startOfDay();
        $perType = AppNotification::query()
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('created_at', '>=', $startOfDay)
            ->count();
        $total = AppNotification::query()
            ->where('member_id', $memberId)
            ->where('created_at', '>=', $startOfDay)
            ->count();

        if ($perType >= self::MAX_PER_TYPE_PER_DAY || $total >= self::MAX_TOTAL_PER_DAY) {
            return ['notification' => null, 'status' => 'skipped_limit'];
        }

        $notification = AppNotification::create([
            'member_id' => $memberId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'action_type' => $actionRoute !== null ? 'route' : null,
            'action_route' => $actionRoute,
            'payload_json' => $this->sanitize($payload),
            'source' => $source,
            'priority' => $priority,
        ]);

        // Push opcional (no rompe si FCM no está configurado).
        $member = Member::find($memberId);
        if ($member !== null) {
            $this->push->sendToMember($member, $notification);
        }

        Log::info('app_notification.created', [
            'member_id' => $memberId,
            'type' => $type,
            'notification_id' => $notification->id,
        ]);

        return ['notification' => $notification, 'status' => 'created'];
    }

    public function markAsRead(int $notificationId, int $memberId): bool
    {
        $n = AppNotification::query()
            ->where('id', $notificationId)
            ->where('member_id', $memberId)
            ->first();
        if ($n === null) {
            return false;
        }
        if ($n->read_at === null) {
            $n->update(['read_at' => now()]);
        }
        return true;
    }

    public function markAllAsRead(int $memberId): int
    {
        return AppNotification::query()
            ->where('member_id', $memberId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** @return array<int,array> */
    public function listForMember(int $memberId, int $limit = 50): array
    {
        return AppNotification::query()
            ->where('member_id', $memberId)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AppNotification $n) => $n->toPublicArray())
            ->all();
    }

    public function unreadCount(int $memberId): int
    {
        return AppNotification::query()
            ->where('member_id', $memberId)
            ->whereNull('read_at')
            ->count();
    }

    public function alreadySentRecently(int $memberId, string $type, int $windowMinutes): bool
    {
        return AppNotification::query()
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();
    }

    /** Saneo recursivo (reusa la lista de claves prohibidas de automation). */
    private function sanitize(array $payload): array
    {
        $forbidden = array_map('strtolower', (array) config('automation.forbidden_keys', []));
        $clean = function (array $data) use (&$clean, $forbidden): array {
            $out = [];
            foreach ($data as $key => $value) {
                $lower = is_string($key) ? strtolower($key) : $key;
                if (is_string($lower)) {
                    foreach ($forbidden as $bad) {
                        if (str_contains($lower, $bad)) {
                            continue 2;
                        }
                    }
                }
                $out[$key] = is_array($value) ? $clean($value) : $value;
            }
            return $out;
        };
        return $clean($payload);
    }
}
