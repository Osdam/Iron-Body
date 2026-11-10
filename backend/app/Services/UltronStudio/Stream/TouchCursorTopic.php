<?php

namespace App\Services\UltronStudio\Stream;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Base de los temas cuya tabla SE ACTUALIZA (no es una cola de solo
 * inserción): el cursor no puede ser el id, es `<unix>-<id>` sobre el ÚLTIMO
 * TOQUE de cada fila ({@see touchSql()}), recorrido por (toque, id) creciente.
 *
 * Límite conocido, común a estos temas: la base guarda los instantes con
 * precisión de SEGUNDO. Dos toques a la MISMA fila en el mismo segundo son un
 * solo evento; dos toques a filas distintas en el mismo segundo salen en
 * orden de id y, si el de id menor llega después de emitido el mayor, se ve
 * en el sondeo siguiente solo si cambia de nuevo o al reconectar (`ready` con
 * `resync`: el panel relee su lista). Un cursor que no tiene la forma arranca
 * en la cabeza: no se vuelve a emitir la tabla.
 */
abstract class TouchCursorTopic implements StreamTopic
{
    /** La consulta base (tabla y filtros fijos), sin orden ni límite. */
    abstract protected function query(): Builder;

    /** El último toque de la fila, como SQL sobre las columnas de la tabla (sin alias). */
    abstract protected function touchSql(): string;

    /**
     * Las columnas que necesita {@see event()} además de `id` y `touch`.
     *
     * @return list<string>
     */
    abstract protected function columns(): array;

    abstract protected function event(object $row, string $cursor): StreamEvent;

    public function cursor(): string
    {
        $fila = $this->query()
            ->selectRaw('id, '.$this->touchSql().' as touch')
            ->orderByDesc('touch')->orderByDesc('id')
            ->first();

        return $fila === null ? '0-0' : self::cursorOf($fila->touch, (int) $fila->id);
    }

    public function since(string $cursor, int $limit): array
    {
        [$unix, $id] = self::parse($cursor) ?? self::parse($this->cursor()) ?? [0, 0];
        $instante = Carbon::createFromTimestamp($unix, config('app.timezone'))->format('Y-m-d H:i:s');
        $toque = $this->touchSql();

        $filas = $this->query()
            ->selectRaw(implode(', ', ['id', ...$this->columns()]).", {$toque} as touch")
            ->where(fn (Builder $q) => $q
                ->whereRaw("{$toque} > ?", [$instante])
                ->orWhere(fn (Builder $q2) => $q2->whereRaw("{$toque} = ?", [$instante])->where('id', '>', $id)))
            ->orderBy('touch')->orderBy('id')
            ->limit($limit)
            ->get();

        $eventos = [];
        foreach ($filas as $f) {
            $eventos[] = $this->event($f, self::cursorOf($f->touch, (int) $f->id));
        }

        return $eventos;
    }

    /** El mayor de varios instantes, en el SQL de cada motor. Quien llama pone COALESCE: el MAX escalar de SQLite se anula con un NULL. */
    protected static function greatest(string ...$columnas): string
    {
        $mayor = DB::connection()->getDriverName() === 'pgsql' ? 'GREATEST' : 'MAX';

        return $mayor.'('.implode(', ', $columnas).')';
    }

    protected static function cursorOf(mixed $touch, int $id): string
    {
        return Carbon::parse((string) $touch, config('app.timezone'))->getTimestamp().'-'.$id;
    }

    /** @return array{0:int,1:int}|null */
    protected static function parse(string $cursor): ?array
    {
        return preg_match('/^(\d{1,12})-(\d{1,18})$/D', $cursor, $m) === 1 ? [(int) $m[1], (int) $m[2]] : null;
    }
}
