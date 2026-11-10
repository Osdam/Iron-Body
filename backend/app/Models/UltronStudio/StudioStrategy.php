<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioStrategies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una ESTRATEGIA del Studio (contrato H9, D9-6, D9-11.0, §6.1): la identidad
 * (`key`, `purpose`, inmutables) y su BORRADOR compartido (`draft_json`, con
 * `draft_revision` para el UPDATE condicionado de {@see StudioStrategies::saveDraft()}).
 * Sus versiones inmutables son {@see StudioStrategyVersion}.
 *
 * NUNCA SE BORRA: archivar pone `archived_at` una vez (sin versiones en
 * CANARY ni PUBLISHED). La base lo impide además con sus disparadores (DB9).
 * La autoría es una instantánea sin FK, como en F.
 *
 * `draft_json` son los bytes del borrador TAL COMO LLEGÓ normalizado a JSON
 * (no canónico: un borrador puede no validar todavía). Se lee como texto.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $key
 * @property string $name
 * @property string $purpose
 * @property string $draft_json
 * @property int $draft_revision
 * @property int|null $draft_updated_by_admin_id
 * @property string|null $draft_updated_by_name
 * @property Carbon|null $draft_updated_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_admin_id
 * @property string|null $archived_by_name
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StudioStrategy extends Model
{
    /** Lo que se puede cambiar de una fila: el borrador y el archivo (una vez). */
    public const MUTABLE = [
        'draft_json', 'draft_revision', 'draft_updated_by_admin_id', 'draft_updated_by_name', 'draft_updated_at',
        'archived_at', 'archived_by_admin_id', 'archived_by_name', 'updated_at',
    ];

    protected $table = 'ultron_studio_strategies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'draft_revision' => 'integer',
            'draft_updated_by_admin_id' => 'integer',
            'draft_updated_at' => 'datetime',
            'archived_at' => 'datetime',
            'archived_by_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $s): void {
            $tocadas = array_keys($s->getDirty());
            if (array_diff($tocadas, self::MUTABLE) !== []) {
                throw new LogicException('ultron_studio: la clave, el nombre y la finalidad de una estrategia no cambian.');
            }
            if ($s->getOriginal('archived_at') !== null && $s->isDirty('archived_at')) {
                throw new LogicException('ultron_studio: una estrategia se archiva una sola vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: una estrategia no se borra; se archiva.'));
    }

    /** @return HasMany<StudioStrategyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(StudioStrategyVersion::class, 'strategy_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
