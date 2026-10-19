<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioLabRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Un caso de una ejecución del laboratorio: un escenario, con su entrada
 * congelada (`scenario_snapshot`) y su reserva del presupuesto del día
 * (`budget_day`, `reserved_micros`). Contrato H4 §A3.
 *
 *   pending ─▶ running ─▶ done (con `outcome`) | error (con `error_code`) | skipped
 *
 * El reclamo es un UPDATE condicionado `pending → running` (dos pasos
 * duplicados nunca procesan el mismo caso) y la liquidación otro
 * `running → done|error` que lleva TODO el resultado: desde ese momento la
 * base rechaza cualquier UPDATE de la fila (`uslc_forbid_terminal_update`).
 * Ver {@see StudioLabRunner}.
 *
 * `calls` guarda cada llamada al proveedor (`{role, class, inputTokens,
 * outputTokens, costMicros, latencyMs}`), sin cuerpos ni cabeceras: de ahí
 * sale `judgeError` cuando el actor respondió y el juez no.
 *
 * @property int $id
 * @property int $run_id
 * @property int $position
 * @property int $scenario_id
 * @property string $scenario_code
 * @property int $scenario_revision
 * @property array<string, mixed> $scenario_snapshot
 * @property string $status
 * @property string|null $outcome
 * @property string|null $error_code
 * @property string|null $reply
 * @property list<array<string, mixed>>|null $checks
 * @property array<string, mixed>|null $judge
 * @property list<array<string, mixed>>|null $calls
 * @property string $budget_day
 * @property int $reserved_micros
 * @property int|null $cost_micros
 * @property int $overrun_micros
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudioLabScenario|null $scenario
 */
class StudioLabRunCase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_DONE, self::STATUS_ERROR, self::STATUS_SKIPPED];

    /** Terminales e inmutables (disparador `uslc_forbid_terminal_update`). */
    public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_ERROR, self::STATUS_SKIPPED];

    /** Lo que escribe el paso que procesa el caso (o lo salta). */
    public const MUTABLE = [
        'status', 'outcome', 'error_code', 'reply', 'checks', 'judge', 'calls',
        'cost_micros', 'overrun_micros', 'input_tokens', 'output_tokens',
        'started_at', 'finished_at', 'updated_at',
    ];

    /** Lo que el caso congela al crearse (con `id`). */
    public const IMMUTABLE = [
        'id', 'run_id', 'position', 'scenario_id', 'scenario_code', 'scenario_revision', 'scenario_snapshot',
        'budget_day', 'reserved_micros', 'created_at',
    ];

    /** Lo que se escribe en JSON: el UPDATE del constructor no aplica los casts. */
    private const JSON_COLUMNS = ['checks', 'judge', 'calls'];

    protected $table = 'ultron_studio_lab_run_cases';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'position' => 'integer',
            'scenario_id' => 'integer',
            'scenario_revision' => 'integer',
            'scenario_snapshot' => 'array',
            'checks' => 'array',
            'judge' => 'array',
            'calls' => 'array',
            'reserved_micros' => 'integer',
            'cost_micros' => 'integer',
            'overrun_micros' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $case): void {
            if (! in_array($case->status, self::STATUSES, true)) {
                throw new LogicException('ultron_studio_lab: estado de caso desconocido.');
            }
        });

        static::updating(function (self $case): void {
            $cambiadas = array_values(array_intersect(self::IMMUTABLE, array_keys($case->getDirty())));
            if ($cambiadas !== []) {
                throw new LogicException('ultron_studio_lab: un caso congela al crearse '.implode(', ', $cambiadas).'.');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio_lab: los casos no se borran.'));
    }

    /** @return BelongsTo<StudioLabScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(StudioLabScenario::class, 'scenario_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * El cambio CONDICIONADO al estado del caso (un solo UPDATE). Devuelve
     * false si otro paso lo movió antes. Solo columnas de {@see MUTABLE}.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function advance(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio_lab: un caso solo cambia '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }
        if (array_key_exists('status', $values) && ! in_array($values['status'], self::STATUSES, true)) {
            throw new LogicException('ultron_studio_lab: estado de caso desconocido.');
        }
        foreach (self::JSON_COLUMNS as $columna) {
            if (array_key_exists($columna, $values) && is_array($values[$columna])) {
                $values[$columna] = json_encode($values[$columna], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        return static::query()->whereKey($id)->whereIn('status', $from)->update($values) === 1;
    }
}
