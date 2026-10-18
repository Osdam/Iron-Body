<?php

namespace App\Models\UltronStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * La decisión de cuatro ojos sobre una versión: aprobada o rechazada, por
 * quién, con qué avisos reconocidos y sobre qué huella. Una por versión en esta
 * fase (UNIQUE `version_id`).
 *
 * SOLO INSERCIÓN: actualizar o borrar lanza aquí, y la base lo impide además
 * con disparadores (`usvr_forbid_*`). El revisor se guarda como instantánea sin
 * FK, igual que el creador de la versión.
 *
 * @property int $id
 * @property int $version_id
 * @property string $decision
 * @property int $reviewer_admin_id
 * @property string $reviewer_name
 * @property string|null $reviewer_role
 * @property string|null $note
 * @property list<string>|null $acknowledged_warnings
 * @property array<string, mixed> $validation
 * @property string $content_hash
 * @property Carbon|null $created_at
 */
class StudioVersionReview extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    protected $table = 'ultron_studio_version_reviews';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_id' => 'integer',
            'reviewer_admin_id' => 'integer',
            'acknowledged_warnings' => 'array',
            'validation' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: las reseñas son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: las reseñas son de solo inserción.'));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'version_id');
    }
}
