<?php

namespace App\Models\UltronStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * La RESEÑA de una versión de estrategia (contrato H9, D9-11.0, §6.1): una
 * por versión, de SOLO INSERCIÓN. Quien revisa es otra persona (P9-4), con su
 * cuenta y su identidad, y reconoce EXACTAMENTE los avisos de la versión.
 *
 * @property int $id
 * @property string $uuid
 * @property int $version_id
 * @property string $decision
 * @property string $content_hash
 * @property list<string>|null $acknowledged_warnings
 * @property string|null $note
 * @property int $reviewer_admin_id
 * @property int $reviewer_person_id
 * @property string $reviewer_name
 * @property string|null $reviewer_role
 * @property Carbon|null $created_at
 */
class StudioStrategyReview extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISIONS = [self::DECISION_APPROVED, self::DECISION_REJECTED];

    protected $table = 'ultron_studio_strategy_reviews';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_id' => 'integer',
            'acknowledged_warnings' => 'array',
            'reviewer_admin_id' => 'integer',
            'reviewer_person_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: una reseña de estrategia es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: una reseña de estrategia es de solo inserción.'));
    }
}
