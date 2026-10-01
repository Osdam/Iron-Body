<?php

namespace App\Models;

use App\Services\Classes\ClassEventBus;
use Illuminate\Database\Eloquent\Model;

/**
 * Un hecho de clases o reservas, con id creciente para leerlo por cursor.
 * Ver la migración `create_class_events_table` y {@see ClassEventBus}.
 */
class ClassEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'class_id', 'session_date', 'reservation_id', 'source', 'reason', 'created_at'];

    protected function casts(): array
    {
        return [
            'class_id' => 'integer',
            'reservation_id' => 'integer',
            'session_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
