<?php

namespace App\Models;

use App\Services\Valuations\ValuationRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Una SOLICITUD de valoración hecha por el socio desde la app
 * (CONTRATO_SOLICITUDES_VALORACION.md §1).
 *
 * No es una cita: no tiene hora ni entrenador. Administración la convierte en
 * una cita NORMAL de la agenda ({@see ValuationAppointment}) y la enlaza
 * (`scheduled`), la da por atendida sin cita (`attended`) o la rechaza con un
 * motivo que lee el socio (`rejected`).
 *
 * Toda escritura pasa por {@see ValuationRequests}: cerrojo del socio al
 * crear, versión optimista en el CRM, auditoría y avisos después del commit.
 * Un `save()` suelto desde otro sitio se saltaría todo eso.
 */
class ValuationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_REJECTED = 'rejected';

    /** Reservado por el contrato (§1); la v1 no tiene ningún camino que lo escriba. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_SCHEDULED, self::STATUS_ATTENDED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_SCHEDULED => 'Agendada',
        self::STATUS_ATTENDED => 'Atendida',
        self::STATUS_REJECTED => 'Rechazada',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    public const SOURCE_APP = 'app';

    protected $fillable = [
        'uuid', 'member_id', 'status', 'source', 'client_request_id', 'appointment_id',
        'handled_by_admin_id', 'handled_by_name', 'handled_at', 'rejection_reason', 'version',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
            'member_id' => 'integer',
            'appointment_id' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ValuationRequest $r): void {
            $r->uuid ??= (string) Str::uuid();
            $r->version ??= 1;
            $r->source ??= self::SOURCE_APP;
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ValuationAppointment::class, 'appointment_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
