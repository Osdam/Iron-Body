<?php

namespace App\Models;

use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Marketing\Meta\MetaSpendReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Gasto real de un anuncio de Meta en un día de la cuenta publicitaria.
 *
 * `date` se trata como texto 'Y-m-d', sin cast a fecha, a propósito: es un DÍA
 * de la cuenta, no un instante. Un cast a Carbon lo convertiría en medianoche
 * UTC y bastaría un `->setTimezone()` para correrlo al día anterior. Además, el
 * cast de fecha de Eloquent guarda 'Y-m-d H:i:s', y en SQLite eso deja la fila
 * fuera de un `whereBetween` por días.
 *
 * La escribe solo {@see MetaAdsSync}; se lee con {@see MetaSpendReader}, que
 * sabe cuándo una suma vacía significa cero y cuándo significa «no se sabe».
 */
class MetaAdInsightDaily extends Model
{
    protected $table = 'meta_ad_insights_daily';

    protected $fillable = [
        'ad_account_id', 'date', 'campaign_id', 'campaign_name', 'adset_id', 'adset_name',
        'ad_id', 'ad_name', 'spend', 'currency', 'impressions', 'reach', 'clicks', 'synced_at',
    ];

    protected $casts = [
        'spend' => 'decimal:2',
        'impressions' => 'integer',
        'reach' => 'integer',
        'clicks' => 'integer',
        'synced_at' => 'datetime',
    ];

    /** Normaliza a 'Y-m-d' si llega un objeto fecha; el texto se guarda tal cual. */
    protected function date(): Attribute
    {
        return Attribute::make(
            set: static fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value,
        );
    }
}
