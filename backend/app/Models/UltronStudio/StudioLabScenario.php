<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioLabScenarios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un escenario SINTÉTICO del laboratorio (contrato H4 §A2): una conversación
 * escrita por personas, con sus hechos y sus criterios esperados. Nunca una
 * conversación real ni los datos de un prospecto.
 *
 * Dos procedencias que no se mezclan:
 *  - `catalog` (C-01…): los del código, copiados por
 *    `ultron-studio:lab-import-scenarios`. De solo lectura desde la API.
 *  - `studio` (E-001…): los del personal. Se editan con `baseRevision` y se
 *    archivan con motivo.
 *
 * Nada se borra: lo impiden este modelo y el disparador `usls_forbid_delete`.
 * El contenido (`{turns, facts, criteria}`) llega ya normalizado por
 * {@see StudioLabScenarios}; los `id` de los criterios (`c1…`) los asigna el
 * servidor por posición.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $source
 * @property string $status
 * @property string $category
 * @property string $title
 * @property string|null $description
 * @property array{turns:list<array{role:string,text:string}>,facts:array{paymentState:string,handoffRequested:bool},criteria:list<array{id:string,kind:string,text:string,rules:list<string>}>} $content
 * @property string $content_hash
 * @property int $revision
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
class StudioLabScenario extends Model
{
    public const SOURCE_CATALOG = 'catalog';

    public const SOURCE_STUDIO = 'studio';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    protected $table = 'ultron_studio_lab_scenarios';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'revision' => 'integer',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $scenario): void {
            $scenario->uuid ??= (string) Str::uuid();
        });

        static::deleting(fn () => throw new LogicException('ultron_studio_lab: los escenarios se archivan, no se borran.'));
    }

    public function isCatalog(): bool
    {
        return $this->source === self::SOURCE_CATALOG;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
