<?php

namespace App\Services\Valuations;

use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use Carbon\CarbonInterface;

/**
 * Los textos de los avisos de la agenda (contrato §5): datos reales, hora de
 * Bogotá con «a. m.»/«p. m.», el nombre de pila del entrenador y nada sensible
 * (ni documento, ni teléfono, ni notas, ni el tipo de valoración).
 *
 * Los de la bandeja del CRM nombran al socio por su nombre y la inicial del
 * apellido («Laura G.»).
 */
final class ValuationMessages
{
    /**
     * Título y mensaje de un aviso. La cita debe traer `member` y `trainer`.
     *
     * @return array{title: string, message: string}
     */
    public static function forReminder(string $kind, ValuationAppointment $cita, CarbonInterface $ahora): array
    {
        $dia = ValuationTime::dayMonth($cita->starts_at);
        $hora = ValuationTime::clock($cita->starts_at);
        $entrenador = self::firstName($cita->trainer?->full_name);
        $socio = self::shortName($cita->member?->full_name);
        $relativo = ValuationTime::relativeDay($cita->starts_at, $ahora);

        return match ($kind) {
            ValuationReminder::KIND_CONFIRMATION => [
                'title' => 'Valoración programada',
                'message' => "Tu valoración en Iron Body quedó programada para el {$dia} a las {$hora} con {$entrenador}.",
            ],
            ValuationReminder::KIND_REMINDER_LONG => [
                'title' => 'Recordatorio de tu valoración',
                'message' => "Te recordamos que {$relativo} tienes una valoración en Iron Body a las {$hora} con {$entrenador}.",
            ],
            ValuationReminder::KIND_REMINDER_SHORT => [
                'title' => 'Tu valoración es pronto',
                'message' => "Tu valoración en Iron Body es {$relativo} a las {$hora} con {$entrenador}.",
            ],
            ValuationReminder::KIND_RESCHEDULED => [
                'title' => 'Valoración reprogramada',
                'message' => "Tu valoración fue reprogramada. Nueva fecha: {$dia}, {$hora}, con {$entrenador}.",
            ],
            ValuationReminder::KIND_CANCELLATION => [
                'title' => 'Valoración cancelada',
                'message' => "Tu valoración del {$dia} a las {$hora} fue cancelada.",
            ],
            ValuationReminder::KIND_INTERNAL_UPCOMING => [
                'title' => 'Valoración próxima',
                'message' => 'Valoración en '.max(0, (int) ceil($ahora->diffInSeconds($cita->starts_at, false) / 60))." min — {$socio} con {$entrenador} a las {$hora}",
            ],
            ValuationReminder::KIND_INTERNAL_UNATTENDED => [
                'title' => 'Valoración sin iniciar',
                'message' => "Valoración sin iniciar — {$socio} con {$entrenador} ({$hora})",
            ],
            default => throw new \InvalidArgumentException("Aviso desconocido: {$kind}"),
        };
    }

    /**
     * Avisos inmediatos de la bandeja del CRM (no son recordatorios).
     *
     * @return array{title: string, message: string}
     */
    public static function forInternalEvent(string $accion, ValuationAppointment $cita): array
    {
        $dia = ValuationTime::dayMonth($cita->starts_at);
        $hora = ValuationTime::clock($cita->starts_at);
        $entrenador = self::firstName($cita->trainer?->full_name);
        $socio = self::shortName($cita->member?->full_name);

        return match ($accion) {
            'created' => ['title' => 'Valoración agendada', 'message' => "Nueva valoración — {$socio} con {$entrenador}, {$dia} a las {$hora}"],
            'rescheduled' => ['title' => 'Valoración reprogramada', 'message' => "Valoración reprogramada — {$socio} con {$entrenador}: {$dia}, {$hora}"],
            'cancelled' => ['title' => 'Valoración cancelada', 'message' => "Valoración cancelada — {$socio} con {$entrenador} ({$dia}, {$hora})"],
            'confirmed' => ['title' => 'Valoración confirmada', 'message' => "Valoración confirmada — {$socio} con {$entrenador}, {$dia} a las {$hora}"],
            default => throw new \InvalidArgumentException("Evento desconocido: {$accion}"),
        };
    }

    /** «Andrés Pérez» → «Andrés». */
    public static function firstName(?string $nombre): string
    {
        $primero = (preg_split('/\s+/u', trim((string) $nombre)) ?: [''])[0];

        return $primero !== '' ? $primero : 'tu entrenador';
    }

    /** «Laura Gómez Pérez» → «Laura G.»; «Laura» → «Laura». */
    public static function shortName(?string $nombre): string
    {
        $partes = array_values(array_filter(preg_split('/\s+/u', trim((string) $nombre)) ?: [], fn ($p) => $p !== ''));
        if ($partes === []) {
            return 'Socio';
        }
        if (count($partes) === 1) {
            return $partes[0];
        }

        return $partes[0].' '.mb_strtoupper(mb_substr($partes[1], 0, 1)).'.';
    }
}
