<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * Los puntos que debe tener una serie, incluidos los que no tienen datos.
 *
 * Un gráfico que solo dibuja los días con cobros miente por omisión: tres días
 * cerrados se convierten en una línea recta entre el lunes y el viernes, y se
 * lee como si el negocio no hubiera parado. Aquí se generan todos los periodos
 * del rango para que los huecos se vean como lo que son: ceros.
 *
 * Las etiquetas se calculan una vez, aquí, y no en el navegador: la serie viaja
 * con el texto que hay que pintar, y el CRM no tiene que saber español de
 * fechas ni acertar con la zona horaria.
 */
final class ReportCalendar
{
    /**
     * @return list<array{key: string, label: string, date: string}>
     */
    public static function buckets(ReportRange $range, string $granularity): array
    {
        $puntos = [];
        $cursor = match ($granularity) {
            'month' => $range->from->startOfMonth(),
            'week' => $range->from->startOfWeek(),
            default => $range->from->startOfDay(),
        };
        $fin = $range->to->startOfDay();

        while ($cursor->lessThanOrEqualTo($fin)) {
            $puntos[] = [
                'key' => self::key($cursor, $granularity),
                'label' => self::label($cursor, $granularity),
                'date' => $cursor->toDateString(),
            ];

            $cursor = match ($granularity) {
                'month' => $cursor->addMonthNoOverflow(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        return $puntos;
    }

    /**
     * Clave del periodo al que pertenece una fecha.
     *
     * Las consultas agrupan SIEMPRE por día —`DATE` con la zona del negocio es
     * lo único que PostgreSQL y SQLite cuentan igual— y las semanas y los meses
     * se pliegan aquí, en PHP. Agrupar semanas en SQL habría dado claves
     * distintas en cada motor (`IYYY-"W"IW` es ISO; `%W` empieza en domingo) y
     * el gráfico habría salido en cero en los tests o en producción.
     */
    public static function key(CarbonImmutable $fecha, string $granularity): string
    {
        return match ($granularity) {
            'month' => $fecha->format('Y-m'),
            'week' => $fecha->startOfWeek()->toDateString(),
            default => $fecha->toDateString(),
        };
    }

    /**
     * Pliega sumas diarias (`YYYY-MM-DD` => importe) en los periodos pedidos.
     *
     * @param  array<string, float>  $porDia
     * @return array<string, float>
     */
    public static function fold(array $porDia, string $granularity): array
    {
        if ($granularity === 'day') {
            return $porDia;
        }

        $plegado = [];
        foreach ($porDia as $dia => $valor) {
            $clave = self::key(CarbonImmutable::parse((string) $dia, ReportRange::TZ), $granularity);
            $plegado[$clave] = ($plegado[$clave] ?? 0) + (float) $valor;
        }

        return $plegado;
    }

    private static function label(CarbonImmutable $fecha, string $granularity): string
    {
        $es = $fecha->locale('es');

        return match ($granularity) {
            'month' => mb_convert_case($es->isoFormat('MMM YYYY'), MB_CASE_TITLE),
            'week' => 'Sem '.$es->isoFormat('D MMM'),
            default => $es->isoFormat('D MMM'),
        };
    }
}
