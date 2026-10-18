<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioDrafts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * El borrador de ULTRON STUDIO de una persona: uno por cuenta del CRM
 * (UNIQUE `admin_id`), editable y con revisión.
 *
 * La revisión es la concurrencia optimista: cada guardado o descarte la sube en
 * uno con un `UPDATE … WHERE admin_id = ? AND revision = ?`, y si no cambia
 * ninguna fila es que otra pestaña guardó antes ({@see StudioDrafts}).
 *
 * Nada se escribe por asignación en masa (`$guarded = ['*']`): el servicio usa
 * `forceFill` o el UPDATE condicionado, con el contenido ya normalizado. Y nada
 * se borra en el Studio: descartar vuelve a la línea base sin tocar la fila. La
 * única baja es la de la base, en cascada, si se borra la cuenta.
 *
 * @property int $id
 * @property string $uuid
 * @property int $admin_id
 * @property int $revision
 * @property string $catalog_version
 * @property array<string, mixed> $content
 * @property string $content_hash
 */
class StudioDraft extends Model
{
    protected $table = 'ultron_studio_drafts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'revision' => 'integer',
            'content' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $draft): void {
            $draft->uuid ??= (string) Str::uuid();
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: un borrador no se borra; se descarta y vuelve a la línea base.'));
    }
}
