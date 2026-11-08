<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una llamada de voz (contrato H8, D8-8, D8-13 y D8-17; esquema de DB8,
 * `voice_calls`). Nace con su SELLO y solo avanza por el grafo de D8-13 (lo
 * vigila el disparador `vc_status_forward`). NUNCA el número: su huella
 * `voice-phone` y sus 4 últimos dígitos.
 *
 * Nadie guarda el modelo entero: se crea con {@see self::query()} y cambia
 * solo con {@see transition()} y {@see bind()}, actualizaciones condicionadas
 * que no tocan las columnas inmutables (como los canarios del H6).
 */
class VoiceCall extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_FAILED_TO_START = 'failed_to_start';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_DIALING = 'dialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DENIED = 'denied';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BUSY = 'busy';

    public const STATUS_NO_ANSWER = 'no_answer';

    public const STATUS_FAILED = 'failed';

    public const STATUS_HUNG_UP = 'hung_up';

    /** En curso (cuentan para la concurrencia y para el corte). */
    public const LIVE = [self::STATUS_REQUESTED, self::STATUS_AMBIGUOUS, self::STATUS_DIALING, self::STATUS_ACTIVE];

    /** Donde una herramienta responde (Enmienda 1, E1-1). */
    public const TOOL_STATUSES = [self::STATUS_DIALING, self::STATUS_ACTIVE];

    public const FINAL = [self::STATUS_CANCELED, self::STATUS_FAILED_TO_START, self::STATUS_DENIED, self::STATUS_COMPLETED,
        self::STATUS_BUSY, self::STATUS_NO_ANSWER, self::STATUS_FAILED, self::STATUS_HUNG_UP];

    /** El grafo de D8-13 (el mismo del disparador de DB8 y del fixture `state-transitions.json`). */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_CANCELED, self::STATUS_FAILED_TO_START, self::STATUS_AMBIGUOUS, self::STATUS_DIALING,
            self::STATUS_COMPLETED, self::STATUS_BUSY, self::STATUS_NO_ANSWER, self::STATUS_FAILED],
        self::STATUS_AMBIGUOUS => [self::STATUS_DIALING, self::STATUS_COMPLETED, self::STATUS_BUSY, self::STATUS_NO_ANSWER, self::STATUS_FAILED],
        self::STATUS_DIALING => [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_BUSY, self::STATUS_NO_ANSWER, self::STATUS_FAILED, self::STATUS_HUNG_UP],
        self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_HUNG_UP],
    ];

    public const TARGET_CANARY = 'canary_registry';

    public const TARGET_CONTACT = 'voice_contacts';

    public const MAX_TOOL_CALLS = 20;

    /** Lo que se puede escribir después de crear (las demás columnas son inmutables, DB8). */
    public const MUTABLE = [
        'status', 'policy_reason', 'provider_conversation_id', 'provider_call_sid',
        'observed_agent_id', 'observed_version_id', 'observed_branch_id', 'seal_verified',
        'provider_cost_micros', 'carrier_cost_micros', 'duration_seconds', 'termination_reason', 'failure_code',
        'tool_calls', 'started_at', 'ended_at', 'status_changed_at', 'updated_at',
    ];

    protected $table = 'voice_calls';

    protected $guarded = ['*'];

    protected $hidden = ['remote_hash'];

    protected function casts(): array
    {
        return [
            'caller_authorized' => 'boolean',
            'seal_verified' => 'boolean',
            'attempt' => 'integer',
            'voice_publication_seq' => 'integer',
            'reserved_micros' => 'integer',
            'provider_cost_micros' => 'integer',
            'carrier_cost_micros' => 'integer',
            'duration_seconds' => 'integer',
            'tool_calls' => 'integer',
            'requested_by_admin_id' => 'integer',
            'voice_contact_id' => 'integer',
            'parent_call_id' => 'integer',
            'transfer_of_call_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $llamada): void {
            $llamada->uuid ??= (string) Str::uuid();
            $llamada->call_ref ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: una llamada cambia solo con transiciones condicionadas.'));
        static::deleting(fn () => throw new LogicException('voice: las llamadas no se borran.'));
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoiceCallEvent::class, 'call_id');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Cambia el estado si la fila sigue en uno de `$from` y el grafo lo
     * admite. Devuelve si cambió (otra petición pudo adelantarse).
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values  solo columnas de {@see MUTABLE}
     */
    public static function transition(int $id, array $from, string $to, array $values = []): bool
    {
        $desde = array_values(array_filter($from, static fn (string $f): bool => self::canTransition($f, $to)));
        if ($desde === []) {
            return false;
        }
        self::assertMutable($values);

        return static::query()->whereKey($id)->whereIn('status', $desde)
            ->update(['status' => $to, 'status_changed_at' => now()] + $values) === 1;
    }

    /**
     * Escribe columnas mutables SIN cambiar el estado (DF-S1): los ids del
     * proveedor y la observación del sello una vez (solo si siguen a null),
     * el coste y la duración.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $onlyIfNull  columnas que solo se escriben si están a null
     */
    public static function bind(int $id, array $values, array $onlyIfNull = []): bool
    {
        self::assertMutable($values);
        if ($values === []) {
            return false;
        }
        $q = static::query()->whereKey($id);
        foreach ($onlyIfNull as $columna) {
            $q->whereNull($columna);
        }

        return $q->update($values) === 1;
    }

    /** @param  array<string, mixed>  $values */
    private static function assertMutable(array $values): void
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== [] || array_key_exists('status', $values)) {
            throw new LogicException('voice: una llamada no cambia '.implode(', ', $ajenas ?: ['status']).' por aquí.');
        }
    }
}
