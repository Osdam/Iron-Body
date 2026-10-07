<?php

namespace App\Models;

use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una campaña, un conjunto o un anuncio de Meta, tal como lo dio la Marketing
 * API en la última sincronización. También la propia cuenta publicitaria, en
 * el nivel {@see self::LEVEL_ACCOUNT}, solo para guardar su nombre.
 *
 * Sirve para resolver el `ad_id` de un referral a su conjunto y su campaña con
 * datos de Meta, nunca por deducción. `campaign_id` y `adset_id` describen la
 * jerarquía incluida la propia fila (ver la migración).
 *
 * Cada fila es de una cuenta publicitaria (`ad_account_id`). Para resolver un
 * anuncio hay que mirar SOLO las cuentas conectadas, con
 * {@see self::scopeForConfiguredAccount()}: una entidad de una cuenta que ya no
 * está conectada no tiene gasto conocido. Los ids de Meta son globales: una
 * entidad es de una sola cuenta.
 *
 * Sin `created_at`/`updated_at`: `synced_at` es la última vez que Meta la
 * devolvió, que es lo único que importa de su historia.
 */
class MetaAdEntity extends Model
{
    public const LEVEL_CAMPAIGN = 'campaign';

    public const LEVEL_ADSET = 'adset';

    public const LEVEL_AD = 'ad';

    /**
     * La cuenta misma (`entity_id` = `ad_account_id`), para su nombre. NO está
     * en {@see LEVELS}: los niveles de la tabla y de las rutas siguen siendo
     * campaña, conjunto y anuncio.
     */
    public const LEVEL_ACCOUNT = 'account';

    public const LEVELS = [self::LEVEL_CAMPAIGN, self::LEVEL_ADSET, self::LEVEL_AD];

    public $timestamps = false;

    protected $fillable = [
        'ad_account_id', 'level', 'entity_id', 'name', 'campaign_id', 'adset_id', 'effective_status', 'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * Solo las entidades de las cuentas publicitarias CONECTADAS
     * (META_AD_ACCOUNT_IDS, o META_AD_ACCOUNT_ID como respaldo).
     *
     * Sin ninguna cuenta válida no devuelve nada: no hay contra qué resolver, y
     * resolver contra cualquier cuenta daría por buena una campaña cuyo gasto no
     * se conoce.
     *
     * Uso: `MetaAdEntity::forConfiguredAccount()->where('level', 'ad')->…`.
     */
    public function scopeForConfiguredAccount(Builder $query): Builder
    {
        $accounts = app(MetaAdsApiClient::class)->accountIds();

        return $accounts === []
            ? $query->whereRaw('0 = 1')
            : $query->whereIn($query->qualifyColumn('ad_account_id'), $accounts);
    }

    /**
     * Clave pública y estable de una cuenta, una campaña, un conjunto o un
     * anuncio.
     *
     * Con la clave de la aplicación (HMAC): un SHA-256 sin secreto del id de
     * Meta se podría invertir por fuerza bruta a partir del `ref` («***1234») y
     * del prefijo típico de los ids de Meta.
     */
    public static function opaqueId(string $level, string $entity): string
    {
        return $level.':'.substr(hash_hmac('sha256', $level.'|'.$entity, (string) config('app.key')), 0, 16);
    }

    /**
     * Cómo se enseña una cuenta conectada fuera del backend: su clave opaca,
     * «***» con sus cuatro últimos dígitos y su nombre sin series de dígitos
     * (el nombre lo escribe una persona y puede llevar un id o un teléfono).
     *
     * @return array{id: string, ref: ?string, name: ?string}
     */
    public static function publicAccount(string $account, ?string $name): array
    {
        return [
            'id' => self::opaqueId(self::LEVEL_ACCOUNT, $account),
            'ref' => LeadSourceClassifier::maskId($account),
            'name' => LeadSourceClassifier::maskDigits($name),
        ];
    }

    /**
     * El nombre de cada cuenta según su fila de nivel `account` (la escribe la
     * sincronización), o null si aún no se ha leído. Una consulta.
     *
     * @param  list<string>  $accounts
     * @return array<string, ?string> cuenta → nombre, en el orden pedido
     */
    public static function accountNames(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $found = self::query()
            ->where('level', self::LEVEL_ACCOUNT)
            ->whereIn('ad_account_id', $accounts)
            ->whereIn('entity_id', $accounts)
            ->get(['ad_account_id', 'entity_id', 'name'])
            // La fila de la cuenta es la suya: entity_id = ad_account_id.
            ->filter(fn (self $e): bool => (string) $e->entity_id === (string) $e->ad_account_id)
            ->mapWithKeys(fn (self $e): array => [(string) $e->ad_account_id => $e->name]);

        $names = [];
        foreach ($accounts as $account) {
            $name = $found->get($account);
            $names[$account] = is_string($name) && trim($name) !== '' ? $name : null;
        }

        return $names;
    }
}
