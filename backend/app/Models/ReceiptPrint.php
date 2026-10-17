<?php

namespace App\Models;

use App\Exceptions\ReceiptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de impresión de un recibo, o la decisión de no imprimirlo.
 *
 * APPEND-ONLY. Cada fila nace con su resultado o en `started`, y una fila en
 * `started` transiciona UNA vez, a `sent` o `error`. No se borra ni se reescribe:
 * es la respuesta a «¿se le entregó la tirilla?» y a «¿quién decidió no
 * entregarla?», y una traza que se puede editar no responde a ninguna de las dos.
 *
 * `uuid` es el `client_attempt_id` que genera el CRM: hace idempotente el
 * registro del intento ante reintentos de red y dobles clics.
 */
class ReceiptPrint extends Model
{
    public const KIND_ORIGINAL = 'original';

    public const KIND_RETRY = 'retry';

    public const KIND_REPRINT = 'reprint';

    public const KIND_SKIP = 'skip';

    public const STATUS_STARTED = 'started';

    public const STATUS_SENT = 'sent';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_DENIED = 'denied';

    public const CONTEXTS = ['checkout', 'shift_report', 'pending'];

    /**
     * Cómo imprimió el equipo. `agent` = el servicio local de impresión
     * (ESC/POS por el spooler de Windows): ese intento lo cierra el propio
     * servicio al reportar, y `sent` significa «aceptado por el sistema».
     */
    public const PRINT_MODE_AGENT = 'agent';

    public const PRINT_MODES = ['silent', 'dialog', 'unknown', self::PRINT_MODE_AGENT];

    public $timestamps = false;

    protected $fillable = [
        'uuid', 'receipt_id', 'kind', 'status', 'context', 'print_mode', 'reason',
        'error_message', 'actor_admin_id', 'actor_name', 'actor_role', 'ip',
        'user_agent', 'started_at', 'finished_at', 'station_id', 'dispatched_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Solo DOS modificaciones admitidas, y cada una una vez:
        //
        //  · el CIERRE de un intento abierto (started → sent|error);
        //  · el DESPACHO: el servicio local canjeó el ticket de un intento que
        //    sigue abierto y aún no se había despachado. Cambia SOLO
        //    `dispatched_at` (de null a una fecha): ni el estado ni nada más.
        //
        // Ambos van por UPDATE condicional en ReceiptPrints (que es lo que los
        // hace seguros frente a dos peticiones a la vez); esto cubre el resto
        // del código, para que nadie pueda reescribir una fila con un save().
        static::updating(function (ReceiptPrint $print): void {
            $cambiados = array_keys($print->getDirty());
            $abierto = $print->getOriginal('status') === self::STATUS_STARTED;

            $esCierre = $abierto
                && in_array($print->status, [self::STATUS_SENT, self::STATUS_ERROR], true)
                && array_diff($cambiados, ['status', 'error_message', 'finished_at']) === [];

            $esDespacho = $abierto
                && $print->getOriginal('dispatched_at') === null
                && $print->dispatched_at !== null
                && $cambiados === ['dispatched_at'];

            if (! $esCierre && ! $esDespacho) {
                throw ReceiptException::printImmutable();
            }
        });

        static::deleting(fn () => throw ReceiptException::printImmutable());
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(ReceiptStation::class, 'station_id');
    }

    public function isPrintAttempt(): bool
    {
        return $this->kind !== self::KIND_SKIP;
    }
}
