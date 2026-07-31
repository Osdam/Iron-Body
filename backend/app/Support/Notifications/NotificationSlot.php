<?php

namespace App\Support\Notifications;

use Carbon\CarbonImmutable;

/**
 * Las cinco franjas del día en las que puede salir una notificación de bienestar.
 *
 * Existen porque «una al día» y «cinco al día» no se diferencian subiendo un
 * contador: hay que saber a CUÁL de las cinco pertenece un envío. La franja es
 * lo que hace que la llave de idempotencia distinga la tanda de las once de la
 * de las tres, y lo que permite que el contenido de las siete de la mañana no
 * hable de cenar.
 *
 * Cada franja ABRE a una hora y se extiende hasta que abre la siguiente. n8n
 * dispara al principio de cada una, pero una ejecución retrasada —un reintento,
 * un contenedor que arrancó tarde— sigue cayendo en la franja correcta en vez
 * de perderse. Lo que no se estira es el cierre: a las 22:00 no hay franja.
 */
final class NotificationSlot
{
    public const MORNING = 'morning';

    public const MIDMORNING = 'midmorning';

    public const AFTERNOON = 'afternoon';

    public const EVENING = 'evening';

    public const NIGHT = 'night';

    public const ALL = [
        self::MORNING,
        self::MIDMORNING,
        self::AFTERNOON,
        self::EVENING,
        self::NIGHT,
    ];

    /**
     * Minuto del día (hora local del gimnasio) en que ABRE cada franja.
     *
     * La nocturna abre a las 21:30 aunque el disparo sea a las 21:45: así una
     * ejecución que se adelante o se retrase unos minutos sigue siendo «la de
     * la noche» y no se convierte en una sexta notificación.
     */
    private const OPENS_AT = [
        self::MORNING => 7 * 60,
        self::MIDMORNING => 11 * 60,
        self::AFTERNOON => 15 * 60,
        self::EVENING => 19 * 60,
        self::NIGHT => 21 * 60 + 30,
    ];

    /**
     * Hora a la que n8n dispara cada franja.
     *
     * La nocturna va a las 21:45 y no a las 22:00 a propósito: el cierre duro es
     * a las 22:00, así que disparar en ese minuto dejaría el envío a merced de
     * cualquier retraso. Quince minutos de margen es lo que separa «llega» de
     * «lo bloqueó la ventana».
     */
    private const FIRES_AT = [
        self::MORNING => [7, 0],
        self::MIDMORNING => [11, 0],
        self::AFTERNOON => [15, 0],
        self::EVENING => [19, 0],
        self::NIGHT => [21, 45],
    ];

    /**
     * Orden en que se intentan las categorías en cada franja.
     *
     * No es decoración: es lo que evita que a las siete de la mañana se hable de
     * descanso y a las diez de la noche de preentreno. El planificador reordena
     * esta lista según lo que hace el socio, pero no sale de ella.
     */
    private const CATEGORIES = [
        // Empezar el día: ánimo, agua y desayuno.
        self::MORNING => [
            NotificationCategory::MOTIVATION,
            NotificationCategory::HYDRATION,
            NotificationCategory::NUTRITION,
        ],
        // Media mañana: hábitos, comida y preparación.
        self::MIDMORNING => [
            NotificationCategory::NUTRITION,
            NotificationCategory::HYDRATION,
            NotificationCategory::SUPPLEMENTS,
            NotificationCategory::MOTIVATION,
        ],
        // Antes de entrenar: constancia, técnica y cabeza.
        self::AFTERNOON => [
            NotificationCategory::MOTIVATION,
            NotificationCategory::RECOVERY,
            NotificationCategory::SUPPLEMENTS,
            NotificationCategory::HYDRATION,
        ],
        // Cierre del día activo: comer bien y recuperar.
        self::EVENING => [
            NotificationCategory::NUTRITION,
            NotificationCategory::RECOVERY,
            NotificationCategory::MOTIVATION,
            NotificationCategory::HYDRATION,
        ],
        // Noche: balance, sueño y mañana. Nada que invite a moverse.
        self::NIGHT => [
            NotificationCategory::RECOVERY,
            NotificationCategory::MOTIVATION,
        ],
    ];

    private const LABELS = [
        self::MORNING => 'Mañana (07:00)',
        self::MIDMORNING => 'Media mañana (11:00)',
        self::AFTERNOON => 'Tarde (15:00)',
        self::EVENING => 'Noche (19:00)',
        self::NIGHT => 'Cierre del día (21:45)',
    ];

    /**
     * Franja a la que pertenece este instante, o null si está fuera de horario.
     *
     * Se calcula en la hora del gimnasio, nunca en la del servidor. Antes de las
     * 07:00 y a partir del cierre duro no hay franja: no es que esté vacía, es
     * que no existe, y quien pregunte recibe null en vez de una franja falsa.
     */
    public static function at(?CarbonImmutable $now = null): ?string
    {
        $minute = self::localMinute($now);
        if ($minute === null || ! SendingWindow::isOpen($now)) {
            return null;
        }

        $found = null;
        foreach (self::OPENS_AT as $slot => $opensAt) {
            if ($minute >= $opensAt) {
                $found = $slot;
            }
        }

        return $found;
    }

    /** @return list<string> */
    public static function categoriesFor(string $slot): array
    {
        return self::CATEGORIES[$slot] ?? [];
    }

    public static function isValid(?string $slot): bool
    {
        return $slot !== null && in_array($slot, self::ALL, true);
    }

    public static function label(string $slot): string
    {
        return self::LABELS[$slot] ?? $slot;
    }

    /** Posición de la franja en el día: 0 la primera, 4 la última. */
    public static function index(string $slot): int
    {
        $position = array_search($slot, self::ALL, true);

        return $position === false ? -1 : $position;
    }

    /** Franja inmediatamente anterior del mismo día, si la hay. */
    public static function previous(string $slot): ?string
    {
        $position = self::index($slot);

        return $position > 0 ? self::ALL[$position - 1] : null;
    }

    /**
     * Horas de disparo, para configurar n8n y documentar el horario.
     *
     * @return list<array{slot:string,hour:int,minute:int,at:string}>
     */
    public static function schedule(): array
    {
        $rows = [];
        foreach (self::FIRES_AT as $slot => [$hour, $minute]) {
            $rows[] = [
                'slot' => $slot,
                'hour' => $hour,
                'minute' => $minute,
                'at' => sprintf('%02d:%02d', $hour, $minute),
            ];
        }

        return $rows;
    }

    /** Minutos transcurridos del día en la hora del gimnasio. */
    private static function localMinute(?CarbonImmutable $now = null): ?int
    {
        $hour = SendingWindow::localHour($now);
        if ($hour === null) {
            return null;
        }

        try {
            $local = ($now ?? CarbonImmutable::now())->setTimezone(SendingWindow::timezone());
        } catch (\Throwable) {
            return null;
        }

        return $hour * 60 + (int) $local->format('i');
    }
}
