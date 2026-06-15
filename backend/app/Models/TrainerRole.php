<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asignación de un rol profesional a un entrenador (pivot `trainer_roles`).
 * Los roles válidos son `trainer_floor` (entrenador de planta) y
 * `trainer_functional` (entrenador funcional); una persona puede tener ambos.
 *
 * El rol `member` NO vive aquí: ser miembro es la existencia de un perfil de
 * miembro, no un rol profesional.
 */
class TrainerRole extends Model
{
    public const FLOOR = 'trainer_floor';

    public const FUNCTIONAL = 'trainer_functional';

    public const ALL = [
        self::FLOOR,
        self::FUNCTIONAL,
    ];

    protected $fillable = [
        'trainer_id',
        'role',
    ];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }
}
