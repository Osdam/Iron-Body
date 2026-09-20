<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una parada de emergencia de ULTRON. Abierta = parado.
 *
 * @see database/migrations/2026_09_20_050000_create_ultron_aborts_table.php
 */
class UltronAbort extends Model
{
    /** Lo encontró el vigía: un entrante atendible se quedó sin desenlace. */
    public const REASON_ORPHANED_TURN = 'orphaned_turn';

    /** Lo paró una persona. */
    public const REASON_MANUAL = 'manual';

    protected $fillable = [
        'reason', 'engaged_by', 'engaged_at', 'evidence',
        'released_at', 'released_by', 'release_note',
    ];

    protected $casts = [
        'evidence' => 'array',
        'engaged_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('released_at');
    }
}
