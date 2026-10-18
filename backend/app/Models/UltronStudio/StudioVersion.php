<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una versión del Studio: copia INMUTABLE de un borrador, con las reglas que
 * había al crearla, su huella y la validación que la acompañó.
 *
 * Inmutable en tres capas (contrato §A1):
 *  1. No existe ruta ni método de servicio que edite su contenido.
 *  2. Este modelo: `updating` lanza si cambia cualquier columna de
 *     {@see IMMUTABLE}, `deleting` lanza siempre y no hay asignación en masa.
 *  3. La base: el disparador `usv_forbid_immutable_update` salta en cuanto una
 *     columna inmutable aparece en el SET, AUNQUE el valor sea el mismo. Por
 *     eso las transiciones escriben SOLO columnas de {@see MUTABLE}
 *     ({@see transition()}); Eloquent manda únicamente las sucias.
 *
 * Las dos listas son las de la migración 2026_10_18_100002 (que no las lee de
 * aquí a propósito: se aplica antes que el código).
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $label
 * @property string|null $note
 * @property string $status
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string $catalog_version
 * @property string $catalog_hash
 * @property int $source_draft_revision
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property array<string, mixed>|null $last_validation
 * @property Carbon|null $submitted_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudioVersionReview|null $review
 */
class StudioVersion extends Model
{
    /** Lo único que la máquina de estados (§A2) cambia de una versión. */
    public const MUTABLE = ['status', 'last_validation', 'submitted_at', 'status_changed_at', 'updated_at'];

    /** Todo lo demás: lo que se revisó y lo que se aprobó no cambia nunca. */
    public const IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'label', 'note', 'content', 'content_hash',
        'catalog_version', 'catalog_hash', 'source_draft_revision',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    protected $table = 'ultron_studio_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'last_validation' => 'array',
            'source_draft_revision' => 'integer',
            'created_by_admin_id' => 'integer',
            'submitted_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->uuid ??= (string) Str::uuid();
        });

        // Ningún estado que el motor no respalda llega a la base, tampoco en
        // SQLite (donde no existe el CHECK `usv_status_chk`).
        static::saving(function (self $version): void {
            $estado = StudioVersionStatus::tryFrom((string) $version->status);
            if ($estado === null || ! $estado->reachable()) {
                throw new LogicException('ultron_studio: estado de versión no alcanzable en esta fase: '.$version->status);
            }
        });

        static::updating(function (self $version): void {
            $cambiadas = array_values(array_intersect(self::IMMUTABLE, array_keys($version->getDirty())));
            if ($cambiadas !== []) {
                throw new LogicException('ultron_studio: una versión es inmutable; no cambia '.implode(', ', $cambiadas).'.');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio: las versiones no se borran.'));
    }

    public function review(): HasOne
    {
        return $this->hasOne(StudioVersionReview::class, 'version_id');
    }

    public function statusEnum(): StudioVersionStatus
    {
        return StudioVersionStatus::from($this->status);
    }

    /**
     * La transición de estado CONDICIONADA al estado esperado: un solo UPDATE
     * con `WHERE status IN (…)`, sin `FOR UPDATE` ni interbloqueos. Devuelve
     * false si otra petición la movió antes (0 filas): quien llama responde 409.
     *
     * Solo admite columnas de {@see MUTABLE}; cualquier otra es un fallo de
     * código y lanza antes de llegar al disparador. Ver {@see StudioVersions}.
     *
     * @param  list<string>  $from  estados de los que se puede partir
     * @param  array<string, mixed>  $values
     */
    public static function transition(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio: una transición solo escribe '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }

        $destino = StudioVersionStatus::tryFrom((string) ($values['status'] ?? ''));
        if (array_key_exists('status', $values) && ($destino === null || ! $destino->reachable())) {
            throw new LogicException('ultron_studio: estado de versión no alcanzable en esta fase: '.$values['status']);
        }

        if (array_key_exists('last_validation', $values) && is_array($values['last_validation'])) {
            $values['last_validation'] = json_encode($values['last_validation'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        // `update()` del constructor de Eloquent añade `updated_at` (mutable).
        return static::query()->whereKey($id)->whereIn('status', $from)->update($values) === 1;
    }
}
