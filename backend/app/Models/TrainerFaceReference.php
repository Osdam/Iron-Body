<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia facial (embedding 192-D) de un entrenador para el login en tablet.
 * Ver `create_trainer_face_references_table`. El embedding es sensible: nunca se
 * expone al cliente (solo se compara en el backend).
 */
class TrainerFaceReference extends Model
{
    protected $fillable = [
        'trainer_id',
        'embedding',
        'enrolled_at',
    ];

    protected $hidden = [
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'enrolled_at' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
