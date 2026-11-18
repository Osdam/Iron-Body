<?php

namespace App\Models;

use App\Services\Valuations\ValuationFollowUps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que el CRM fijó para el seguimiento de valoraciones de UN socio
 * (CONTRATO_CICLO_VALORACIONES.md §3): intervalo propio, pausa y fecha puntual.
 *
 * Solo datos de entrada. El próximo control y el estado se calculan al leer en
 * {@see ValuationFollowUps}, que es también quien escribe (con versión y
 * auditoría): un `save()` suelto desde otro sitio se saltaría las dos.
 *
 * `due_on` va SIN cast de fecha, como texto `Y-m-d`: es una fecha de calendario
 * de Bogotá, no un instante, y así la auditoría guarda el valor legible.
 */
class MemberValuationFollowUp extends Model
{
    /** Campos que el PUT cambia y que la auditoría registra con su antes y después. */
    public const FIELDS = ['interval_days', 'enabled', 'due_on'];

    protected $fillable = ['member_id', 'interval_days', 'enabled', 'due_on', 'version', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'interval_days' => 'integer',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** `Y-m-d` o null, lea el motor lo que lea (PostgreSQL da la fecha; SQLite, el texto guardado). */
    public function dueOn(): ?string
    {
        $valor = $this->getAttribute('due_on');

        return $valor === null || $valor === '' ? null : substr((string) $valor, 0, 10);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
