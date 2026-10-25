<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioPublications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una entrada de la CADENA DE PUBLICACIONES (contrato H5 §A3.1): publicar,
 * promover, revertir o volver a Legacy. La cabeza es la fila con `max(seq)`, y
 * es lo único del Studio que lee el motor (`UltronPublishedConfig`).
 *
 * SOLO INSERCIÓN, en tres capas: solo la escribe {@see StudioPublications},
 * este modelo lanza al actualizar o borrar, y la base lo impide además con los
 * disparadores `uspub_forbid_*` (también contra TRUNCATE en PostgreSQL).
 *
 * `effective_json` son los BYTES EXACTOS que firma el Studio y verifica el
 * motor: se guarda y se lee como texto, nunca como estructura (sin cast). Quien
 * actúa es una instantánea sin FK, como en F.
 *
 * @property int $id
 * @property string $uuid
 * @property int $seq
 * @property string $client_request_id
 * @property string $action
 * @property int|null $version_id
 * @property int|null $restores_publication_id
 * @property int|null $previous_publication_id
 * @property string $engine_contract
 * @property string $effective_json
 * @property string $effective_hash
 * @property string $signature
 * @property string $catalog_version
 * @property array<string, mixed> $lab_gate
 * @property array<string, mixed>|null $acknowledged
 * @property array<string, mixed>|null $evidence
 * @property string|null $reason
 * @property int $actor_admin_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property Carbon|null $created_at
 * @property-read StudioVersion|null $version
 */
class StudioPublication extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ultron_studio_publications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'version_id' => 'integer',
            'restores_publication_id' => 'integer',
            'previous_publication_id' => 'integer',
            'lab_gate' => 'array',
            'acknowledged' => 'array',
            'evidence' => 'array',
            'actor_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: la cadena de publicaciones es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: la cadena de publicaciones es de solo inserción.'));
    }

    /** @return BelongsTo<StudioVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'version_id');
    }
}
