<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ajuste a mano sobre la membresía de un socio: días o entradas.
 *
 * Es un libro, no un contador: cada fila dice quién dio (o quitó) qué, cuándo y
 * por qué. Ver la migración para el porqué de cada columna.
 */
class MembershipAdjustment extends Model
{
    /** Mueve la fecha de vencimiento. */
    public const KIND_DAYS = 'days';

    /** Suma entradas al periodo en curso de un plan por consumo. */
    public const KIND_ENTRIES = 'entries';

    public const KINDS = [self::KIND_DAYS, self::KIND_ENTRIES];

    protected $fillable = [
        'user_id',
        'kind',
        'amount',
        'window_start',
        'window_end',
        'previous_end_date',
        'new_end_date',
        'reason',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'window_start' => 'date',
            'window_end' => 'date',
            'previous_end_date' => 'date',
            'new_end_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDays(): bool
    {
        return $this->kind === self::KIND_DAYS;
    }

    /** Cómo se lee en la ficha del socio: «+3 días» / «−2 entradas». */
    public function label(): string
    {
        $cantidad = (int) $this->amount;
        $signo = $cantidad < 0 ? '−' : '+';
        $unidad = $this->isDays()
            ? (abs($cantidad) === 1 ? 'día' : 'días')
            : (abs($cantidad) === 1 ? 'entrada' : 'entradas');

        return $signo.abs($cantidad).' '.$unidad;
    }
}
