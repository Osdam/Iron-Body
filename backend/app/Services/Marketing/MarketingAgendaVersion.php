<?php

namespace App\Services\Marketing;

use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La versión de la Agenda comercial: un contador que sube con cada escritura
 * de una cita. El canal SSE la compara para avisar al CRM sin depender de
 * `updated_at` (dos cambios en el mismo segundo se verían como uno).
 *
 * Solo avisa: la verdad son las filas, y el CRM relee la lista al recibir el
 * aviso. Por eso una tabla que todavía no existe (despliegue a medias) no
 * rompe ninguna escritura de citas: se avisa en el log y se sigue.
 */
class MarketingAgendaVersion
{
    public static function current(): int
    {
        try {
            return (int) DB::table('marketing_agenda_state')->where('id', 1)->value('version');
        } catch (QueryException $e) {
            return self::sinTabla($e, 0);
        }
    }

    public static function bump(): void
    {
        try {
            // En su propio savepoint: en PostgreSQL un fallo dentro de una
            // transacción la deja abortada aunque se capture, y la escritura de
            // la cita que la rodea fallaría después. El savepoint lo aísla.
            DB::transaction(fn () => DB::table('marketing_agenda_state')->where('id', 1)->increment('version'));
        } catch (QueryException $e) {
            self::sinTabla($e, null);
        }
    }

    private static function sinTabla(QueryException $e, ?int $valor): ?int
    {
        $sinTabla = in_array((string) $e->getCode(), ['42P01', '42S02'], true) || str_contains($e->getMessage(), 'no such table');
        if (! $sinTabla) {
            throw $e;
        }
        ChannelLog::warning('marketing.agenda.version_unavailable', ['error_class' => class_basename($e)]);

        return $valor;
    }
}
