<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioCampaignLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un PERIODO de asociación entre una campaña de Meta y la línea base o una
 * versión del Studio (contrato H6 §I3.1). El historial son filas, nunca
 * ediciones: sustituir una asociación cierra la vieja y crea otra.
 *
 * Es REGISTRO del Studio: no cambia nada en Meta ni en lo que ULTRON dice o
 * hace. Los ids de Meta (`ad_account_id`, `campaign_id`) no salen del servidor:
 * fuera van su id opaco y «***» con sus cuatro últimos dígitos.
 *
 * Inmutable en tres capas, como las versiones:
 *  1. Ninguna ruta edita lo que fija la asociación: {@see StudioCampaignLinks}
 *     solo cierra (UPDATE condicionado, {@see close()}) y anota la sustituta
 *     ({@see markReplacedBy()}).
 *  2. Este modelo: `updating` lanza con una columna de {@see IMMUTABLE},
 *     `deleting` lanza siempre y no hay asignación en masa.
 *  3. La base: `uscl_forbid_immutable_update`, `uscl_inactive_is_final`,
 *     `uscl_replacement_once` y, en PG, sus CHECK (migración 2026_11_01_100002).
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $ad_account_id
 * @property string $campaign_id
 * @property string|null $campaign_name_at_link
 * @property string $target_kind
 * @property int|null $version_id
 * @property string|null $version_content_hash
 * @property string $status
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 * @property Carbon|null $deactivated_at
 * @property int|null $deactivated_by_admin_id
 * @property string|null $deactivated_by_name
 * @property string|null $deactivated_by_role
 * @property string|null $deactivation_reason
 * @property int|null $replaced_by_link_id
 * @property-read StudioVersion|null $version
 * @property-read StudioCampaignLink|null $replacedBy
 */
class StudioCampaignLink extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const TARGET_BASELINE = 'baseline';

    public const TARGET_VERSION = 'version';

    public const TARGET_KINDS = [self::TARGET_BASELINE, self::TARGET_VERSION];

    /** Lo que mueve una asociación: su cierre y la que la sustituye (§I3.2). */
    public const MUTABLE = [
        'status', 'deactivated_at', 'deactivated_by_admin_id', 'deactivated_by_name', 'deactivated_by_role',
        'deactivation_reason', 'replaced_by_link_id',
    ];

    /** Todo lo demás. Las dos listas son las de la migración 2026_11_01_100002, que no las lee de aquí. */
    public const IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'ad_account_id', 'campaign_id', 'campaign_name_at_link',
        'target_kind', 'version_id', 'version_content_hash',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    protected $table = 'ultron_studio_campaign_links';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'deactivated_by_admin_id' => 'integer',
            'replaced_by_link_id' => 'integer',
            'created_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $link): void {
            $cambiadas = array_values(array_intersect(self::IMMUTABLE, array_keys($link->getDirty())));
            if ($cambiadas !== []) {
                throw new LogicException('ultron_studio: una asociación de campaña es inmutable; no cambia '.implode(', ', $cambiadas).'.');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio: las asociaciones de campaña no se borran.'));
    }

    /** @return BelongsTo<StudioVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'version_id');
    }

    /** @return BelongsTo<StudioCampaignLink, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_link_id');
    }

    /**
     * Cierra la asociación SOLO si sigue activa: un UPDATE condicionado, sin
     * `FOR UPDATE`. Devuelve false si otra petición la cerró antes (0 filas).
     *
     * @param  array{id:int,name:string,role:?string}  $by
     */
    public static function close(int $id, array $by, string $reason, Carbon $at): bool
    {
        return static::query()->whereKey($id)->where('status', self::STATUS_ACTIVE)->update([
            'status' => self::STATUS_INACTIVE,
            'deactivated_at' => $at,
            'deactivated_by_admin_id' => $by['id'],
            'deactivated_by_name' => $by['name'],
            'deactivated_by_role' => $by['role'],
            'deactivation_reason' => $reason,
        ]) === 1;
    }

    /**
     * Anota la que la sustituye, UNA vez, en una asociación ya cerrada (la
     * guarda `uscl_replacement_once` lo exige también en la base).
     */
    public static function markReplacedBy(int $id, int $replacementId): bool
    {
        return static::query()->whereKey($id)
            ->where('status', self::STATUS_INACTIVE)
            ->whereNull('replaced_by_link_id')
            ->update(['replaced_by_link_id' => $replacementId]) === 1;
    }
}
