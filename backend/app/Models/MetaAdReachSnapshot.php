<?php

namespace App\Models;

use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Marketing\Meta\MetaSpendReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * El alcance (personas únicas) de un rango de días, tal como lo calcula Meta,
 * de la cuenta publicitaria o de una de sus campañas.
 *
 * No se suma ni se reparte: vale solo para su rango exacto `[date_from,
 * date_to]`. Las fechas son DÍAS de la cuenta, en texto 'Y-m-d', por la misma
 * razón que en {@see MetaAdInsightDaily}.
 *
 * La escribe {@see MetaAdsSync::refreshReach()} y la lee {@see MetaSpendReader}.
 */
class MetaAdReachSnapshot extends Model
{
    public const LEVEL_ACCOUNT = 'account';

    public const LEVEL_CAMPAIGN = 'campaign';

    public const LEVELS = [self::LEVEL_ACCOUNT, self::LEVEL_CAMPAIGN];

    protected $table = 'meta_ad_reach_snapshots';

    protected $fillable = [
        'ad_account_id', 'level', 'entity_id', 'date_from', 'date_to', 'reach', 'impressions', 'frequency', 'synced_at',
    ];

    protected $casts = [
        'reach' => 'integer',
        'impressions' => 'integer',
        'frequency' => 'float',
        'synced_at' => 'datetime',
    ];

    protected function dateFrom(): Attribute
    {
        return Attribute::make(set: static fn ($value) => self::day($value));
    }

    protected function dateTo(): Attribute
    {
        return Attribute::make(set: static fn ($value) => self::day($value));
    }

    private static function day(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
