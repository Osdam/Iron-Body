<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioMessageTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una VERSIÓN DE PLANTILLA de mensaje (contrato H9, D9-6 «Plantillas
 * versionadas», §6.1): una fila por versión de una clave (`key` + `number`).
 * El contenido (`content_json`, JSON canónico ≤ 8 KB, con su `content_hash`)
 * es INMUTABLE: una plantilla no se edita, se crea su versión siguiente. Los
 * estados solo avanzan: DRAFT → APPROVED | REJECTED; APPROVED → RETIRED. Cuatro
 * ojos por persona: aprueba otra persona verificada (P9-4). Ver
 * {@see StudioMessageTemplates}.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $key
 * @property int $number
 * @property string $channel
 * @property string $purpose
 * @property string|null $category
 * @property string $content_json
 * @property string $content_hash
 * @property string $status
 * @property int $created_by_admin_id
 * @property int $created_by_person_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property int|null $reviewed_by_admin_id
 * @property string|null $reviewed_by_name
 * @property string|null $reviewed_by_role
 * @property int|null $reviewed_by_person_id
 * @property string|null $review_note
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StudioMessageTemplate extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RETIRED];

    /** Hacia delante, solo así. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [self::STATUS_RETIRED],
        self::STATUS_REJECTED => [],
        self::STATUS_RETIRED => [],
    ];

    /** `TEMPLATE_MUTABLE_COLUMNS` de DB9: el estado y la revisión (una vez). */
    public const MUTABLE = ['status', 'reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_by_person_id', 'reviewed_at', 'review_note', 'status_changed_at', 'updated_at'];

    protected $table = 'ultron_studio_message_templates';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'created_by_admin_id' => 'integer',
            'created_by_person_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'reviewed_by_person_id' => 'integer',
            'reviewed_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $t): void {
            if (array_diff(array_keys($t->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('ultron_studio: el contenido de una plantilla no cambia; se crea su versión siguiente.');
            }
            if ($t->isDirty('status')) {
                $antes = (string) $t->getOriginal('status');
                if (! in_array($t->status, self::TRANSITIONS[$antes] ?? [], true)) {
                    throw new LogicException('ultron_studio: los estados de una plantilla solo avanzan.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: una plantilla no se borra.'));
    }
}
