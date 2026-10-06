<?php

namespace App\Models;

use App\Services\Marketing\Meta\MetaAdsApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una campaña, un conjunto o un anuncio de Meta, tal como lo dio la Marketing
 * API en la última sincronización.
 *
 * Sirve para resolver el `ad_id` de un referral a su conjunto y su campaña con
 * datos de Meta, nunca por deducción. `campaign_id` y `adset_id` describen la
 * jerarquía incluida la propia fila (ver la migración).
 *
 * Cada fila es de una cuenta publicitaria (`ad_account_id`). Para resolver un
 * anuncio hay que mirar SOLO la cuenta configurada, con
 * {@see self::scopeForConfiguredAccount()}: una entidad de una cuenta anterior
 * no tiene gasto conocido en la actual.
 *
 * Sin `created_at`/`updated_at`: `synced_at` es la última vez que Meta la
 * devolvió, que es lo único que importa de su historia.
 */
class MetaAdEntity extends Model
{
    public const LEVEL_CAMPAIGN = 'campaign';

    public const LEVEL_ADSET = 'adset';

    public const LEVEL_AD = 'ad';

    public const LEVELS = [self::LEVEL_CAMPAIGN, self::LEVEL_ADSET, self::LEVEL_AD];

    public $timestamps = false;

    protected $fillable = [
        'ad_account_id', 'level', 'entity_id', 'name', 'campaign_id', 'adset_id', 'effective_status', 'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * Solo las entidades de la cuenta publicitaria configurada
     * (META_AD_ACCOUNT_ID, con o sin `act_`).
     *
     * Sin una cuenta válida no devuelve nada: no hay contra qué resolver, y
     * resolver contra cualquier cuenta daría por buena una campaña cuyo gasto no
     * se conoce.
     *
     * Uso: `MetaAdEntity::forConfiguredAccount()->where('level', 'ad')->…`.
     */
    public function scopeForConfiguredAccount(Builder $query): Builder
    {
        $account = app(MetaAdsApiClient::class)->accountId();

        return $account === null
            ? $query->whereRaw('0 = 1')
            : $query->where($query->qualifyColumn('ad_account_id'), $account);
    }
}
