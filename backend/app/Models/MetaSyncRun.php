<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Un intento de sincronizar Meta Ads y cómo terminó.
 *
 * Es la diferencia entre «se gastó $0» y «no se sabe cuánto se gastó»: una
 * suma vacía solo vale cero si una pasada con éxito cubrió esos días.
 *
 * `range_from` y `range_to` son días de la cuenta publicitaria ('Y-m-d', ambos
 * inclusive) y se tratan como texto por la misma razón que
 * {@see MetaAdInsightDaily::$date}.
 */
class MetaSyncRun extends Model
{
    public const KIND_INSIGHTS = 'insights';

    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGERS = [self::TRIGGER_SCHEDULE, self::TRIGGER_MANUAL];

    /** La pasada anterior murió sin cerrar su fila (proceso caído o cerrojo vencido). */
    public const ERROR_INTERRUPTED = 'interrupted';

    /**
     * Falló algo nuestro (la base, un bug), no Meta. El detalle no se guarda aquí:
     * esta fila llega al panel, y el mensaje de una QueryException trae el host,
     * el puerto, la base y el SQL. Va al log del canal y, desde el job, a
     * `failed_jobs`.
     */
    public const ERROR_INTERNAL = 'internal';

    /** Lo único que se guarda de un fallo interno. */
    public const INTERNAL_ERROR_MESSAGE = 'Error interno al guardar la sincronización.';

    protected $fillable = [
        'kind', 'status', 'trigger', 'ad_account_id', 'range_from', 'range_to', 'rows_upserted',
        'error_code', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'rows_upserted' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected function rangeFrom(): Attribute
    {
        return Attribute::make(set: static fn ($value) => self::day($value));
    }

    protected function rangeTo(): Attribute
    {
        return Attribute::make(set: static fn ($value) => self::day($value));
    }

    private static function day(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
