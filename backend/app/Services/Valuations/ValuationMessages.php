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
    /** Meses abreviados para la bandeja del CRM («18 nov»). */
    private const SHORT_MONTHS = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'sept', 10 => 'oct', 11 => 'nov', 12 => 'dic',
    ];

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
            // Es push desde CONTRATO_SOLICITUDES_VALORACION.md §4: fecha, hora y
            // el entrenador con su nombre corto («Carlos P.»), como lo enseña la app.
            ValuationReminder::KIND_CONFIRMATION => [
                'title' => 'Valoración programada',
                'message' => self::sentence("Tu valoración en Iron Body quedó programada para el {$dia} a las {$hora} con ".self::trainerShortName($cita->trainer?->full_name)),
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

    /**
     * El resultado del envío de una valoración profesional, en la bandeja del
     * CRM (contrato del ciclo §1). Nombres cortos y NINGUNA medida.
     *
     *  · `linked`             → «Carlos P. finalizó la valoración de María G. · 18 nov, 10:40 a. m.»
     *  · `none`               → «… registró la valoración de María G. · 18 nov. No había cita pendiente ese día.»
     *  · `different_trainer`  → «… · 18 nov. La cita del día era con Ana R.»
     *  · `ambiguous`          → «… · 18 nov. Había 2 citas ese día.»
     *
     * «pendiente» y no el «No había cita ese día» del ejemplo del contrato: la
     * segunda valoración del mismo día también cae aquí (la cita ya la cerró la
     * primera), y decir que no había cita sería falso.
     *
     * @param  string  $resultado  ValuationAgenda::MATCH_*
     * @return array{title: string, message: string}
     */
    public static function forAssessmentOutcome(
        string $resultado,
        CarbonInterface $enviadaEn,
        ?string $entrenador,
        ?string $socio,
        ?string $otroEntrenador = null,
        int $candidatas = 0,
    ): array {
        $quien = self::trainerShortName($entrenador, 'Un entrenador');
        $deQuien = self::shortName($socio);
        $dia = self::dayShortMonth($enviadaEn);

        return match ($resultado) {
            ValuationAgenda::MATCH_LINKED => [
                'title' => 'Valoración completada',
                'message' => "{$quien} finalizó la valoración de {$deQuien} · {$dia}, ".ValuationTime::clock($enviadaEn),
            ],
            ValuationAgenda::MATCH_NONE => [
                'title' => 'Valoración realizada sin cita',
                'message' => "{$quien} registró la valoración de {$deQuien} · {$dia}. No había cita pendiente ese día.",
            ],
            ValuationAgenda::MATCH_DIFFERENT_TRAINER => [
                'title' => 'Valoración por revisar',
                'message' => self::sentence("{$quien} registró la valoración de {$deQuien} · {$dia}. La cita del día era con ".self::trainerShortName($otroEntrenador, 'otro entrenador')),
            ],
            ValuationAgenda::MATCH_AMBIGUOUS => [
                'title' => 'Valoración por revisar',
                'message' => "{$quien} registró la valoración de {$deQuien} · {$dia}. Había {$candidatas} citas ese día.",
            ],
            default => throw new \InvalidArgumentException("Resultado desconocido: {$resultado}"),
        };
    }

    /** Cierra la frase con punto, sin duplicarlo si ya acaba en una inicial («Ana R.»). */
    private static function sentence(string $texto): string
    {
        return str_ends_with($texto, '.') ? $texto : $texto.'.';
    }

    /** «18 nov», en Bogotá y sin `ext-intl`. */
    public static function dayShortMonth(CarbonInterface $instante): string
    {
        $l = ValuationTime::local($instante);

        return $l->day.' '.self::SHORT_MONTHS[$l->month];
    }

    /** El entrenador con su nombre corto («Carlos P.»), o `$siNo` si no se resuelve. */
    public static function trainerShortName(?string $nombre, string $siNo = 'tu entrenador'): string
    {
        return trim((string) $nombre) === '' ? $siNo : self::shortName($nombre);
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
