<?php

namespace App\Models;

use App\Services\Caja\ReceiptAgentTickets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La autorización de UN trabajo del servicio local de impresión.
 *
 * El token (32 bytes aleatorios, base64url) se le entrega UNA vez a quien lo
 * emite y aquí se guarda solo su SHA-256: una copia de la base, un log de
 * consultas o la pantalla de eventos no bastan para canjear nada.
 *
 * Ciclo, cada paso UNA vez y por UPDATE condicional o bajo bloqueo de fila
 * ({@see ReceiptAgentTickets}):
 *
 *   emitido → (revocado | caducado) · emitido → canjeado → reportado
 *
 * Un ticket de impresión va atado a un intento `started` de `receipt_prints`;
 * los de prueba y configuración, solo a la estación.
 */
class ReceiptAgentTicket extends Model
{
    public const PURPOSE_PRINT = 'print';

    public const PURPOSE_TEST = 'test';

    public const PURPOSE_CONFIGURE = 'configure';

    public const PURPOSES = [self::PURPOSE_PRINT, self::PURPOSE_TEST, self::PURPOSE_CONFIGURE];

    /**
     * Páginas de prueba (contrato §5.4). Las `sample_*` son recibos SINTÉTICOS
     * maquetados en memoria: no existen en ninguna tabla.
     */
    public const VARIANTS = ['minimal', 'full', 'sample_1', 'sample_5', 'sample_20', 'sample_membership'];

    public const DEFAULT_VARIANT = 'minimal';

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_APPLIED = 'applied';

    protected $fillable = [
        'uuid', 'token_hash', 'purpose', 'receipt_station_id', 'receipt_print_id',
        'variant', 'payload', 'issued_by_admin_id', 'issued_by_name', 'issued_at',
        'expires_at', 'revoked_at', 'redeemed_at', 'redeemed_ip', 'agent_version',
        'columns', 'content_sha256', 'reported_at', 'outcome', 'error_code',
        'error_message', 'spool_job_id', 'printer_name',
    ];

    /** La huella del token tampoco sale en ninguna serialización. */
    protected $hidden = ['token_hash'];

    protected $casts = [
        'payload' => 'array',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'reported_at' => 'datetime',
        'columns' => 'integer',
        'spool_job_id' => 'integer',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(ReceiptStation::class, 'receipt_station_id');
    }

    public function print(): BelongsTo
    {
        return $this->belongsTo(ReceiptPrint::class, 'receipt_print_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'issued_by_admin_id');
    }
}
