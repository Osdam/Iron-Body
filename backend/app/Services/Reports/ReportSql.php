<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Los tres trozos de SQL que todo informe necesita y que cambian según el motor.
 *
 * Producción es PostgreSQL y los tests corren en SQLite. Sin esto, cada informe
 * escribía su propia versión —y la del panel viejo agrupaba por `DATE(col)`, que
 * es UTC—: lo cobrado después de las 19:00 de Neiva aparecía en el día
 * siguiente. Colombia no tiene horario de verano, así que el desfase es siempre
 * de cinco horas y se puede restar sin tabla de zonas.
 */
final class ReportSql
{
    public const TZ = ReportRange::TZ;

    /**
     * La fecha del NEGOCIO (`YYYY-MM-DD`) de una columna `timestamp` guardada
     * en UTC.
     *
     * Es la única agrupación temporal que se hace en la base. Semanas y meses
     * se pliegan después en PHP ({@see ReportCalendar::fold()}) porque cada
     * motor las numera a su manera y el gráfico saldría distinto en los tests y
     * en producción.
     */
    public static function businessDate(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR(({$column} AT TIME ZONE 'UTC' AT TIME ZONE '".self::TZ."'), 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', {$column}, '-5 hours')",
            default => "DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', '-05:00'), '%Y-%m-%d')",
        };
    }

    /** `?, ?, ?` para una lista de valores dentro de un whereRaw. */
    public static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Comparación de texto insensible a mayúsculas que funciona en los dos
     * motores: `ilike` es de PostgreSQL y `like` en SQLite ya lo es.
     */
    public static function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** Escapa los comodines de un término de búsqueda del usuario. */
    public static function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
