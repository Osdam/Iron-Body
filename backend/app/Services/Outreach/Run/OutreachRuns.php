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
