<?php

namespace App\Services\Outreach\Run\Stream;

use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\Stream\StreamTopic;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lo común de los temas `outreach.*` del canal SSE del Studio (contrato H9,
 * «Paquete SSE9»; interfaz `StreamTopic` de SSE9-b): colas de SOLO LECTURA
 * sobre las tablas de la ejecución, con un cursor monótono y opaco.
 *
 * Las filas con ESTADO (pasadas, trabajos, frenos) cambian en su sitio, así
 * que su cursor no puede ser el `id`: es `<segundos unix de updated_at>-<id>`,
 * recorrido por `(updated_at, id)` creciente; la ejecución escribe siempre
 * `updated_at` al cambiarlas. Las de solo inserción (resultados) van por
 * `id`. Un cursor con otra forma arranca en la cabeza (no se reemite la tabla
 * entera por un cursor roto).
 *
 * El permiso es el del GET de lista del módulo (`commercial_automation.view`).
 * `data` nunca lleva teléfono, nombre, documento, cuerpo ni importe: solo
 * códigos, uuids e instantes (la lista blanca de `StreamEvent` lo impone).
 */
abstract class OutreachTopic implements StreamTopic
{
    public const PERMISSION = OutreachAccess::VIEW;

    private const COMPOSITE = '/^([0-9]{1,12})-([0-9]{1,18})$/D';

    public function permission(): string
    {
        return self::PERMISSION;
    }

    /** La clave de `AuthorizationMap` del GET de lista del módulo cuyo permiso exige el tema (todas `commercial_automation.view`). */
    public function listRoute(): ?string
    {
        return 'GET api/admin/ultron-studio/outreach/'.static::LIST_PATH;
    }

    /** La cabeza de una tabla con estado: `<unix>-<id>` de la fila más reciente por `(updated_at, id)`. */
    protected function compositeHead(string $table): string
    {
        $fila = DB::table($table)->orderByDesc('updated_at')->orderByDesc('id')->first(['id', 'updated_at']);
        if ($fila === null) {
            return '0-0';
        }

        return self::compositeCursor($fila->updated_at, (int) $fila->id);
    }

    /** Las filas posteriores a un cursor compuesto, en orden creciente. */
    protected function compositeSince(Builder $q, string $cursor, int $limit): Builder
    {
        if (preg_match(self::COMPOSITE, $cursor, $m) !== 1) {
            // Un cursor roto arranca en la cabeza: nada que emitir todavía.
            return $q->whereRaw('1 = 0');
        }
        $desde = (new DateTimeImmutable('@'.$m[1]))->format('Y-m-d H:i:s');
        $id = (int) $m[2];

        return $q->where(fn ($w) => $w->where('updated_at', '>', $desde)->orWhere(fn ($x) => $x->where('updated_at', '=', $desde)->where('id', '>', $id)))
            ->orderBy('updated_at')->orderBy('id')->limit(max(1, $limit));
    }

    public static function compositeCursor(mixed $updatedAt, int $id): string
    {
        $instante = $updatedAt === null ? 0 : OutreachStore::instant($updatedAt)->getTimestamp();

        return max(0, $instante).'-'.$id;
    }

    /** @param  array<string, string|int|null>  $data */
    protected static function event(string $cursor, array $data): StreamEvent
    {
        return new StreamEvent($cursor, $data);
    }

    protected static function iso(mixed $at): string
    {
        return $at === null ? now()->utc()->toIso8601String() : OutreachStore::instant($at)->format('Y-m-d\TH:i:s\Z');
    }
}
