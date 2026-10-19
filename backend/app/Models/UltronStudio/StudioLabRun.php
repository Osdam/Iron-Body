<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una ejecución del laboratorio (contrato H4 §A3): un objetivo (versión,
 * borrador propio o línea base) sobre unos escenarios, en un modo (`openai` o
 * `fake`). Es una SIMULACIÓN DEL STUDIO, no ULTRON.
 *
 *   queued ─▶ running ─▶ completed | cancelled | stopped (con `stop_reason`)
 *
 * Al crearse congela su objetivo (`target_snapshot`), su modo, su modelo, sus
 * precios, su estimación y su autoría ({@see IMMUTABLE}). Lo demás
 * ({@see MUTABLE}) lo mueven la máquina de estados ({@see StudioLabRunner}) y
 * la cancelación ({@see StudioLabRuns}) con UPDATE condicionados al estado
 * ({@see advance()}), hasta que la ejecución es terminal: desde ahí, la base
 * rechaza cualquier UPDATE (`uslr_forbid_terminal_update`). Por eso el paso a
 * terminal lleva TODO (resumen, propuesta, huella, coste) en un solo UPDATE.
 *
 * Nada se borra (este modelo y `uslr_forbid_delete`). Las dos listas son las
 * de la migración 2026_10_19_100003, que no las lee de aquí a propósito: se
 * aplica antes que el código.
 *
 * `model` y `prices` son NOT NULL: en `fake`, `model` es '' y los precios 0.
 * `prices` congela también los máximos de salida de cada papel: con ellos se
 * calculó la reserva, y con ellos se cobra un timeout.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $target_kind
 * @property int|null $target_version_id
 * @property int|null $target_draft_admin_id
 * @property string $target_label
 * @property string $target_content_hash
 * @property array<string, mixed> $target_snapshot
 * @property string $catalog_version
 * @property string $mode
 * @property string $model
 * @property string $prompt_version
 * @property array{input:int,output:int,actorMaxOutputTokens:int,judgeMaxOutputTokens:int} $prices
 * @property string $status
 * @property string|null $stop_reason
 * @property int $case_count
 * @property int $estimate_expected_micros
 * @property int $estimate_max_micros
 * @property int $accepted_max_micros
 * @property int $cost_micros
 * @property int $input_tokens
 * @property int $output_tokens
 * @property array<string, mixed>|null $summary
 * @property string|null $ai_recommendation
 * @property string|null $results_hash
 * @property string $budget_day
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $cancel_requested_at
 * @property string|null $cancel_requested_by_name
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudioVersion|null $targetVersion
 * @property-read StudioLabVerdict|null $verdict
 */
class StudioLabRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_STOPPED = 'stopped';

    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_STOPPED];

    /** Una ejecución en curso o en cola: la única que admite cambios. */
    public const LIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    /** Terminales e inmutables (disparador `uslr_forbid_terminal_update`). */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_STOPPED];

    public const TARGET_VERSION = 'version';

    public const TARGET_DRAFT = 'draft';

    public const TARGET_BASELINE = 'baseline';

    public const TARGET_KINDS = [self::TARGET_VERSION, self::TARGET_DRAFT, self::TARGET_BASELINE];

    /** Los motivos de `stopped` (§A3). */
    public const STOP_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STOP_DEADLINE = 'deadline';

    public const STOP_LAB_DISABLED = 'lab_disabled';

    public const STOP_NOT_CONFIGURED = 'not_configured';

    public const STOP_EXPIRED = 'expired';

    public const STOP_REASONS = [
        self::STOP_BUDGET_EXHAUSTED, self::STOP_DEADLINE, self::STOP_LAB_DISABLED, self::STOP_NOT_CONFIGURED, self::STOP_EXPIRED,
        'provider_auth', 'provider_quota', 'provider_rejected', 'provider_errors',
    ];

    /** Lo único que la máquina de estados, la cancelación y el cierre cambian. */
    public const MUTABLE = [
        'status', 'stop_reason', 'cost_micros', 'input_tokens', 'output_tokens', 'summary',
        'ai_recommendation', 'results_hash', 'cancel_requested_at', 'cancel_requested_by_name',
        'started_at', 'finished_at', 'updated_at',
    ];

    /** Lo que la ejecución congela al crearse (con `id`, que nadie cambia). */
    public const IMMUTABLE = [
        'id', 'uuid', 'client_request_id',
        'target_kind', 'target_version_id', 'target_draft_admin_id', 'target_label', 'target_content_hash', 'target_snapshot',
        'catalog_version', 'mode', 'model', 'prompt_version', 'prices', 'case_count',
        'estimate_expected_micros', 'estimate_max_micros', 'accepted_max_micros', 'budget_day',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    protected $table = 'ultron_studio_lab_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'target_version_id' => 'integer',
            'target_draft_admin_id' => 'integer',
            'target_snapshot' => 'array',
            'prices' => 'array',
            'case_count' => 'integer',
            'estimate_expected_micros' => 'integer',
            'estimate_max_micros' => 'integer',
            'accepted_max_micros' => 'integer',
            'cost_micros' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'summary' => 'array',
            'created_by_admin_id' => 'integer',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });

        // Ningún valor que el contrato no conoce llega a la base, tampoco en
        // SQLite (donde no existen los CHECK `uslr_*_chk`).
        static::saving(function (self $run): void {
            if (! in_array($run->status, self::STATUSES, true) || ! in_array($run->target_kind, self::TARGET_KINDS, true)) {
                throw new LogicException('ultron_studio_lab: estado u objetivo de ejecución desconocido.');
            }
        });

        static::updating(function (self $run): void {
            $cambiadas = array_values(array_intersect(self::IMMUTABLE, array_keys($run->getDirty())));
            if ($cambiadas !== []) {
                throw new LogicException('ultron_studio_lab: una ejecución congela al crearse '.implode(', ', $cambiadas).'.');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio_lab: las ejecuciones no se borran.'));
    }

    /** @return HasMany<StudioLabRunCase, $this> */
    public function cases(): HasMany
    {
        return $this->hasMany(StudioLabRunCase::class, 'run_id')->orderBy('position');
    }

    /** @return HasOne<StudioLabVerdict, $this> */
    public function verdict(): HasOne
    {
        return $this->hasOne(StudioLabVerdict::class, 'run_id');
    }

    /** @return BelongsTo<StudioVersion, $this> */
    public function targetVersion(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'target_version_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * El cambio CONDICIONADO al estado: un solo UPDATE con
     * `WHERE status IN (…)`, sin `FOR UPDATE`. Devuelve false si otra petición
     * o paso la movió antes (0 filas). Un UPDATE que no encuentra la fila no
     * dispara las guardas de la base.
     *
     * Solo admite columnas de {@see MUTABLE}; cualquier otra es un fallo de
     * código y lanza antes de llegar al disparador.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function advance(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio_lab: una ejecución solo cambia '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }
        if (array_key_exists('status', $values) && ! in_array($values['status'], self::STATUSES, true)) {
            throw new LogicException('ultron_studio_lab: estado de ejecución desconocido.');
        }
        if (array_key_exists('summary', $values) && is_array($values['summary'])) {
            // El UPDATE del constructor no aplica los casts del modelo.
            $values['summary'] = json_encode($values['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        // `update()` del constructor de Eloquent añade `updated_at` (mutable).
        return static::query()->whereKey($id)->whereIn('status', $from)->update($values) === 1;
    }
}
