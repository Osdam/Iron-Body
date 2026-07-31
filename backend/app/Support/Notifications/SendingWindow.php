<?php

namespace App\Support\Notifications;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * La franja del día en la que el gimnasio permite molestar.
 *
 * Existe porque el servidor corre en UTC y el gimnasio vive en UTC-5: fiarse de
 * la hora del servidor significaría que «las diez de la noche» son en realidad
 * las cinco de la tarde, y que a las dos de la madrugada de Neiva el sistema
 * creería que es media mañana.
 *
 * Es la ÚLTIMA barrera. Las preferencias del socio y sus horas de silencio
 * siguen decidiendo dentro de la ventana; esto solo pone un techo que ninguna
 * configuración —ni un disparo accidental de n8n— puede levantar.
 */
final class SendingWindow
{
    public static function timezone(): string
    {
        return (string) config('notifications.window.timezone', 'America/Bogota');
    }

    public static function startHour(): int
    {
        return (int) config('notifications.window.start_hour', 7);
    }

    public static function endHour(): int
    {
        return (int) config('notifications.window.end_hour', 22);
    }

    /**
     * ¿Se puede enviar AHORA?
     *
     * El inicio es inclusivo y el cierre exclusivo: a las 07:00 en punto sí, a
     * las 22:00 en punto ya no. «Cierre máximo a las 22:00» significa que a esa
     * hora ya no sale nada, no que sea la última oportunidad de mandarlo.
     */
    public static function isOpen(?CarbonImmutable $now = null): bool
    {
        $hour = self::localHour($now);
        if ($hour === null) {
            // Zona horaria corrupta: se abre. Perder un aviso por una mala
            // configuración es peor que enviarlo a una hora rara, y el fallo
            // queda igualmente registrado en el libro mayor.
            return true;
        }

        return $hour >= self::startHour() && $hour < self::endHour();
    }

    /** Hora local del gimnasio, o null si la zona horaria no es válida. */
    public static function localHour(?CarbonImmutable $now = null): ?int
    {
        try {
            return (int) ($now ?? CarbonImmutable::now())
                ->setTimezone(self::timezone())
                ->format('G');
        } catch (Throwable) {
            return null;
        }
    }

    /** Próxima apertura, para explicar en la respuesta cuándo se reanudará. */
    public static function nextOpening(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        try {
            $local = $now->setTimezone(self::timezone());
        } catch (Throwable) {
            return $now;
        }

        if (self::isOpen($now)) {
            return $now;
        }

        $target = $local->setTime(self::startHour(), 0);
        if ($target <= $local) {
            $target = $target->addDay();
        }

        return $target->setTimezone($now->getTimezone());
    }

    /** Resumen legible para logs, respuestas de n8n y el CRM. */
    public static function describe(?CarbonImmutable $now = null): array
    {
        return [
            'timezone' => self::timezone(),
            'start_hour' => self::startHour(),
            'end_hour' => self::endHour(),
            'local_hour' => self::localHour($now),
            'open' => self::isOpen($now),
        ];
    }
}
