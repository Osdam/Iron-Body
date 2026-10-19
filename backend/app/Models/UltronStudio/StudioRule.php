<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioRuleCoherence;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una regla comercial del Studio. Dos procedencias que no se mezclan:
 *
 *  - `legacy_baseline` (R-01…R-12): las que APLICA EL CÓDIGO hoy, transcritas
 *    y copiadas por `ultron-studio:import-baseline`. Siempre `enforcement =
 *    code` y de solo lectura desde la API.
 *  - `studio` (S-001…): propuestas escritas en el Studio. NUNCA activas ni
 *    aplicadas por el motor (`draft`|`archived`, `enforcement = none`).
 *
 * En PostgreSQL lo garantizan además dos CHECK (`usr_studio_never_active_chk`,
 * `usr_legacy_is_code_chk`); en SQLite no existen, así que este modelo repite la
 * comprobación al guardar. Nada se borra: una regla se archiva con motivo. La
 * historia vive en `revision`, en la auditoría y en la copia de cada versión
 * ({@see StudioRules}).
 *
 * `conditions` (Hito G, contrato §A3): la estructura verificable de la regla,
 * en su forma canónica (`{when, then}`), o NULL si la regla es documental
 * (todas las de F). La forma la garantiza el servicio
 * ({@see StudioRuleCoherence::canonicalize()}); la
 * base no la comprueba.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $source
 * @property string $enforcement
 * @property string $status
 * @property string $description
 * @property string $type
 * @property string $priority
 * @property list<string> $layers
 * @property array{configs:list<array{layer:string,key:string}>,edges:list<string>,knowledgeKeys:list<string>,knowledgeCategories:list<string>} $refs
 * @property array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}|null $conditions
 * @property string|null $origin
 * @property int $revision
 * @property string $content_hash
 * @property string|null $client_request_id
 * @property int|null $created_by_admin_id
 * @property string|null $created_by_name
 * @property int|null $updated_by_admin_id
 * @property string|null $updated_by_name
 * @property Carbon|null $archived_at
 * @property string|null $archive_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StudioRule extends Model
{
    public const SOURCE_LEGACY = 'legacy_baseline';

    public const SOURCE_STUDIO = 'studio';

    public const ENFORCEMENT_CODE = 'code';

    public const ENFORCEMENT_NONE = 'none';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DRAFT, self::STATUS_ARCHIVED];

    public const TYPES = ['restriction', 'permission', 'condition', 'style'];

    public const PRIORITIES = ['critical', 'high', 'normal'];

    protected $table = 'ultron_studio_rules';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'layers' => 'array',
            'refs' => 'array',
            'conditions' => 'array',
            'revision' => 'integer',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->uuid ??= (string) Str::uuid();
        });

        static::saving(function (self $rule): void {
            if ($rule->source === self::SOURCE_STUDIO
                && ($rule->enforcement !== self::ENFORCEMENT_NONE || ! in_array($rule->status, [self::STATUS_DRAFT, self::STATUS_ARCHIVED], true))) {
                throw new LogicException('ultron_studio: una regla del Studio nunca está activa ni la aplica el motor.');
            }
            if ($rule->source === self::SOURCE_LEGACY && $rule->enforcement !== self::ENFORCEMENT_CODE) {
                throw new LogicException('ultron_studio: una regla del código la aplica el código (enforcement = code).');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio: las reglas no se borran; se archivan con motivo.'));
    }

    public function isLegacy(): bool
    {
        return $this->source === self::SOURCE_LEGACY;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
