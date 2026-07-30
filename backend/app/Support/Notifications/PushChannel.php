<?php

namespace App\Support\Notifications;

/**
 * Canal de Android al que va cada categoría, y con qué prioridad de entrega.
 *
 * Los identificadores DEBEN coincidir con los que crea
 * `android/app/src/main/kotlin/com/ironbodyneiva/app/IronBodyApplication.kt`.
 * Si el backend pide un canal que la app no creó, Android descarta el aviso o
 * lo degrada al canal de respaldo, mudo — que es exactamente el fallo que esto
 * viene a cerrar. Hay una prueba que fija esa correspondencia.
 */
final class PushChannel
{
    public const HIGH = 'iron_body_high';

    public const CLASSES = 'iron_body_classes';

    public const GENERAL = 'iron_body_general';

    public const SOCIAL = 'iron_body_social';

    public const WELLNESS = 'iron_body_wellness';

    public const PROMOS = 'iron_body_promos';

    public const ALL = [
        self::HIGH,
        self::CLASSES,
        self::GENERAL,
        self::SOCIAL,
        self::WELLNESS,
        self::PROMOS,
    ];

    public static function forCategory(string $category): string
    {
        return match ($category) {
            NotificationCategory::ACCOUNT_SECURITY,
            NotificationCategory::PAYMENTS,
            NotificationCategory::MEMBERSHIP => self::HIGH,

            NotificationCategory::CLASSES,
            NotificationCategory::WORKOUTS => self::CLASSES,

            NotificationCategory::SOCIAL => self::SOCIAL,

            NotificationCategory::MOTIVATION,
            NotificationCategory::HYDRATION,
            NotificationCategory::RECOVERY,
            NotificationCategory::NUTRITION,
            NotificationCategory::SUPPLEMENTS => self::WELLNESS,

            NotificationCategory::PROMOTIONS => self::PROMOS,

            default => self::GENERAL,
        };
    }

    /**
     * Prioridad de ENTREGA de FCM (distinta de la importancia del canal).
     *
     * `normal` deja que Android agrupe el mensaje y lo retrase en Doze, lo que
     * está bien para un consejo de hidratación y muy mal para un pago rechazado.
     * Las tuberías antiguas no mandaban prioridad alguna, así que todo quedaba
     * a merced del ahorro de batería.
     */
    public static function priorityForCategory(string $category): string
    {
        return match (self::forCategory($category)) {
            self::HIGH, self::CLASSES => 'high',
            default => 'normal',
        };
    }

    /** Bloque `android` completo del mensaje HTTP v1. */
    public static function androidBlock(string $category): array
    {
        $channel = self::forCategory($category);
        $priority = self::priorityForCategory($category);

        $notification = ['channel_id' => $channel];
        // El sonido lo decide el canal; pedirlo aquí en los silenciosos solo
        // sirve para que Android lo ignore y quede la duda de si funcionó.
        if ($priority === 'high') {
            $notification['sound'] = 'default';
        }

        return [
            'priority' => $priority,
            'notification' => $notification,
        ];
    }
}
