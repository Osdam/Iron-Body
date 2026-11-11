<?php

namespace App\Services\Outreach\Run;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Las PASADAS (`outreach_runs`, §6.2): una fila por pasada, que nace
 * `running` y se cierra UNA vez (`completed`, `failed` o `aborted`) con sus
 * recuentos acotados (≤ 4 KB) y, si falló, el código del error (nunca el
 * texto: puede llevar internals). Todo lo que la ejecución hace deja su
 * pasada: la vigilancia y el panel leen de aquí.
 */
final class OutreachRuns
{
    public const KINDS = ['ingest', 'sweep', 'dispatch', 'monitor', 'reconcile', 'replay'];

    public const MAX_COUNTS_BYTES = 4096;

    /**
     * I-4: la POSICIÓN del barrido de una estrategia y un disparador vive en
     * los recuentos de su última pasada `sweep` COMPLETADA: desde dónde empezó
     * (`cursorFrom`) y desde dónde empieza la siguiente (`cursorNext`; `''` =
     * se acabó la vuelta y la siguiente empieza otra). Una pasada fallida o
     * abortada no la mueve. Son posiciones de {@see TriggerPrefilters::pageFrom()}
     * y llevan ids internos (de socios, cartera, pagos o seguimientos), así que
     * no salen de la base: {@see publicCounts()}.
     */
    public const SWEEP_CURSOR_FROM = 'cursorFrom';

    public const SWEEP_CURSOR_NEXT = 'cursorNext';

    /** Los recuentos que se guardan pero no se enseñan (ni en la API ni en la salida de las órdenes). */
    public const PRIVATE_COUNT_KEYS = [self::SWEEP_CURSOR_FROM, self::SWEEP_CURSOR_NEXT];

    /** Abre una pasada. `$mode` es `shadow` o `live` (nunca `off`: con `off` no se abre ninguna). */
    public function open(string $kind, string $mode, DateTimeInterface $now, ?int $strategyId = null, ?string $triggerKey = null): int
    {
        if (! in_array($kind, self::KINDS, true) || ! in_array($mode, OutreachConfig::MODES, true)) {
            throw new \InvalidArgumentException('Tipo o modo de pasada fuera del conjunto cerrado.');
        }

        return (int) DB::table(OutreachTables::RUNS)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'kind' => $kind,
            'mode' => $mode,
            'strategy_id' => $strategyId,
            'trigger_key' => $triggerKey,
            'status' => 'running',
            'started_at' => $now,
            'finished_at' => null,
            'counts' => null,
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param  array<string, mixed>  $counts */
    public function complete(int $runId, DateTimeInterface $now, array $counts): void
    {
        $this->close($runId, $now, 'completed', $counts, null);
    }

    /** @param  array<string, mixed>  $counts */
    public function fail(int $runId, DateTimeInterface $now, array $counts, string $errorCode): void
    {
        $this->close($runId, $now, 'failed', $counts, $errorCode);
    }

    /** @param  array<string, mixed>  $counts */
    public function abort(int $runId, DateTimeInterface $now, array $counts, ?string $errorCode = null): void
    {
        $this->close($runId, $now, 'aborted', $counts, $errorCode);
    }

    /**
     * El código de un fallo para `error_code` (`^[a-z][a-z0-9_]{0,47}$`): la
     * clase de la excepción en snake_case, nunca su mensaje.
     */
    public static function errorCode(Throwable $e): string
    {
        $codigo = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($e)));
        $codigo = (string) preg_replace('/[^a-z0-9_]/', '_', $codigo);
        $codigo = ltrim($codigo, '0123456789_');

        return substr($codigo === '' ? 'error' : $codigo, 0, 48);
    }

    /**
     * Los recuentos que se pueden enseñar: sin {@see PRIVATE_COUNT_KEYS}.
     *
     * @param  array<string, mixed>|null  $counts
     * @return array<string, mixed>|null
     */
    public static function publicCounts(?array $counts): ?array
    {
        return $counts === null ? null : array_diff_key($counts, array_flip(self::PRIVATE_COUNT_KEYS));
    }

    /**
     * Desde dónde sigue el barrido de una estrategia y un disparador en un modo:
     * `cursorNext` de su última pasada COMPLETADA, o `''` (el principio) si no
     * hay ninguna o no trae posición (las de antes de I-4). Las pasadas
     * `failed`, `aborted` o aún `running` no cuentan: si una muere a mitad, la
     * siguiente repite su página, nunca avanza en falso.
     */
    public function sweepPosition(int $strategyId, string $triggerKey, string $mode): string
    {
        $counts = DB::table(OutreachTables::RUNS)->where('kind', 'sweep')->where('strategy_id', $strategyId)->where('trigger_key', $triggerKey)
            ->where('mode', $mode)->where('status', 'completed')->orderByDesc('id')->value('counts');
        $c = is_string($counts) ? json_decode($counts, true) : null;
        $p = is_array($c) ? ($c[self::SWEEP_CURSOR_NEXT] ?? '') : '';

        // Algo que no sea una cadena corta es una posición ilegible: se empieza de nuevo (se repasa, no se salta).
        return is_string($p) && strlen($p) <= 64 ? $p : '';
    }

    /**
     * Recuentos acotados a 4 KB: solo escalares y listas cortas de códigos.
     *
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    public static function boundedCounts(array $counts): array
    {
        ksort($counts, SORT_STRING);
        $json = json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && strlen($json) <= self::MAX_COUNTS_BYTES) {
            return $counts;
        }
        // Se recorta de forma determinista: primero las listas, después todo lo que no sea un entero.
        foreach ($counts as $k => $v) {
            if (is_array($v)) {
                $counts[$k] = count($v);
            }
        }
        $json = json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && strlen($json) <= self::MAX_COUNTS_BYTES) {
            return $counts + ['truncated' => true];
        }

        return array_filter($counts, static fn (mixed $v): bool => is_int($v)) + ['truncated' => true];
    }

    /** @param  array<string, mixed>  $counts */
    private function close(int $runId, DateTimeInterface $now, string $status, array $counts, ?string $errorCode): void
    {
        DB::table(OutreachTables::RUNS)->where('id', $runId)->where('status', 'running')->update([
            'status' => $status,
            'finished_at' => $now,
            // Un OBJETO JSON siempre (`orun_counts_chk`): sin recuentos, `{}` y no `[]`.
            'counts' => json_encode((object) self::boundedCounts($counts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_code' => $errorCode,
            'updated_at' => $now,
        ]);
    }
}
