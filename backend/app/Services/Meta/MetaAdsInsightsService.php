<?php

namespace App\Services\Meta;

use App\Models\MarketingCampaign;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincronización de métricas de Meta Ads (campañas) hacia marketing_campaigns.
 *
 * GATED por META_ENABLED: si no está habilitado/configurado, NO llama a Graph y
 * devuelve 0 (no inventa métricas). La lógica real de /insights queda lista.
 */
class MetaAdsInsightsService
{
    public function __construct(private readonly MetaAuthService $auth)
    {
    }

    /**
     * Sincroniza campañas + insights del ad account. Devuelve cuántas campañas
     * se actualizaron. 0 si Meta está deshabilitado (sin contactar a la API).
     */
    public function syncCampaigns(?string $since = null, ?string $until = null): int
    {
        if (! $this->auth->isConfigured()) {
            Log::info('meta.ads.sync.skipped', ['reason' => 'disabled_or_unconfigured']);
            return 0;
        }

        $adAccount = (string) config('meta.ad_account_id');
        if ($adAccount === '') {
            return 0;
        }

        try {
            $resp = Http::withToken((string) $this->auth->accessToken())
                ->timeout($this->auth->timeout())
                ->get($this->auth->graphUrl("act_{$adAccount}/campaigns"), [
                    'fields' => 'id,name,status,objective,'
                        . 'insights{spend,impressions,reach,clicks,ctr,cpc,cpm,date_start,date_stop}',
                    'time_range' => $since && $until
                        ? json_encode(['since' => $since, 'until' => $until])
                        : null,
                ]);

            if ($resp->failed()) {
                Log::warning('meta.ads.sync.failed', ['status' => $resp->status()]);
                return 0;
            }

            $count = 0;
            foreach (($resp->json('data') ?? []) as $c) {
                $ins = $c['insights']['data'][0] ?? [];
                MarketingCampaign::updateOrCreate(
                    ['meta_campaign_id' => $c['id'] ?? null],
                    [
                        'name'        => $c['name'] ?? 'Campaña',
                        'status'      => $c['status'] ?? null,
                        'objective'   => $c['objective'] ?? null,
                        'spend'       => (float) ($ins['spend'] ?? 0),
                        'impressions' => (int) ($ins['impressions'] ?? 0),
                        'reach'       => (int) ($ins['reach'] ?? 0),
                        'clicks'      => (int) ($ins['clicks'] ?? 0),
                        'ctr'         => isset($ins['ctr']) ? (float) $ins['ctr'] : null,
                        'cpc'         => isset($ins['cpc']) ? (float) $ins['cpc'] : null,
                        'cpm'         => isset($ins['cpm']) ? (float) $ins['cpm'] : null,
                        'date_start'  => $ins['date_start'] ?? null,
                        'date_stop'   => $ins['date_stop'] ?? null,
                        'raw_metrics' => $ins ?: null,
                        'synced_at'   => now(),
                    ],
                );
                $count++;
            }

            return $count;
        } catch (Throwable $e) {
            Log::warning('meta.ads.sync.exception', ['error' => class_basename($e)]);
            return 0;
        }
    }
}
