<?php

namespace App\Console\Commands\Marketing;

use App\Models\MetaAdEntity;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Attribution\MetaDashboardService;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaSpendReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Conciliación de un periodo (FASE 4): lo que dice Meta, lo que hay en las
 * tablas del CRM y lo que enseña el panel, cifra a cifra.
 *
 *  B) Graph API: totales de la CUENTA en el rango (gasto, impresiones, alcance)
 *     y la lista de campañas con su gasto, como el Administrador de anuncios.
 *  C) Tablas locales: la suma de meta_ad_insights_daily y la foto de alcance
 *     del rango exacto, si la hay.
 *  D) Panel: lo que devuelve MetaDashboardService para ese periodo.
 *
 * El gasto concuerda si B, C y D difieren en menos de un peso (redondeo por
 * fila de Meta). Las impresiones, al ser recuentos, tienen que ser iguales. El
 * alcance de Meta y el de la foto pueden diferir si el rango incluye hoy (Meta
 * lo recalcula cada hora); se informa, no se exige.
 *
 * Solo lee. Falla (código distinto de 0) si el gasto o las impresiones no
 * concuerdan, o si el panel no da el gasto como completo.
 */
class MetaAdsReconcileCommand extends Command
{
    /** Diferencia de gasto tolerada, en la moneda de la cuenta: el redondeo por fila de Meta. */
    public const SPEND_TOLERANCE = 1.0;

    protected $signature = 'marketing:meta-ads-reconcile
        {--period=30d : today, 7d, 30d, this_month, last_month o custom}
        {--from= : Con custom, primer día AAAA-MM-DD}
        {--to= : Con custom, último día AAAA-MM-DD}';

    protected $description = 'Concilia un periodo entre Meta (Graph API), las tablas del CRM y el panel: gasto, impresiones, alcance y campañas.';

    public function handle(MetaAdsApiClient $client, MetaSpendReader $reader, MetaDashboardService $dashboard): int
    {
        $problem = $client->configurationProblem();
        if ($problem !== null) {
            $this->error(MetaAdsApiClient::problemMessage($problem));

            return self::FAILURE;
        }

        try {
            $period = $dashboard->period($this->option('period'), $this->option('from'), $this->option('to'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $days = $reader->days($period['start'], $period['end']);
        if (is_string($days)) {
            $this->error("El periodo no tiene días que conciliar ({$days}).");

            return self::FAILURE;
        }
        [$first, $last] = $days;
        $account = (string) $client->accountId();

        // B) Meta.
        try {
            $metaTotals = $client->accountTotals($first, $last) ?? [];
            $metaCampaigns = $client->reach('campaign', $first, $last);
        } catch (MetaAdsApiException $e) {
            $this->error("Meta no contestó ({$e->category}): {$e->getMessage()}");

            return self::FAILURE;
        }
        $metaSpend = round((float) ($metaTotals['spend'] ?? 0), 2);
        $metaImpressions = (int) ($metaTotals['impressions'] ?? 0);
        $metaReach = (int) ($metaTotals['reach'] ?? 0);

        // C) Tablas locales.
        $local = DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->whereBetween('date', [$first, $last])
            ->selectRaw('COALESCE(SUM(spend), 0) as spend, COALESCE(SUM(impressions), 0) as impressions')
            ->first();
        $localSpend = round((float) $local->spend, 2);
        $localImpressions = (int) $local->impressions;
        $localReach = $reader->accountReach($first, $last);

        // D) Panel.
        $panel = $dashboard->dashboard($period);
        $panelSpend = $panel['spend']['amount'];
        $panelRows = collect($panel['campaigns']['rows'])->filter(fn (array $r): bool => $r['id'] !== MetaDashboardService::UNRESOLVED);

        $spendMatch = $panelSpend !== null
            && ($panel['spend']['complete'] ?? false) === true
            && abs($metaSpend - $localSpend) < self::SPEND_TOLERANCE
            && abs($metaSpend - (float) $panelSpend) < self::SPEND_TOLERANCE;
        $impressionsMatch = $metaImpressions === $localImpressions;

        $this->info("Periodo {$period['key']}: {$first} → {$last} (días de la cuenta)");
        $this->table(['cifra', 'Meta (Graph API)', 'Tablas del CRM', 'Panel', '¿concuerda?'], [
            ['Gasto', $this->money($metaSpend), $this->money($localSpend), $panelSpend === null ? '— ('.$panel['spend']['status'].')' : $this->money((float) $panelSpend), $spendMatch ? 'SÍ' : 'NO'],
            ['Impresiones', $metaImpressions, $localImpressions, $panelRows->sum(fn (array $r): int => (int) ($r['impressions'] ?? 0)), $impressionsMatch ? 'SÍ' : 'NO'],
            ['Alcance (cuenta)', $metaReach, $localReach === null ? '— (sin foto del rango)' : $localReach['reach'], '—', $localReach === null ? '—' : ($localReach['reach'] === $metaReach ? 'SÍ' : 'difiere (se recalcula)')],
        ]);

        // Campañas: con gasto en Meta frente a las filas del panel.
        $metaWithSpend = collect($metaCampaigns)->filter(fn (array $c): bool => (float) ($c['spend'] ?? 0) > 0);
        // Por id, no por nombre: Meta permite nombres repetidos. La fila del panel
        // lleva el id opaco, que se calcula igual desde el de Meta.
        $panelById = $panelRows->keyBy(fn (array $r): string => (string) $r['id']);
        $campaignRows = $metaWithSpend->map(function (array $c) use ($panelById): array {
            $name = (string) LeadSourceClassifier::maskDigits((string) ($c['campaign_name'] ?? ''));
            $row = $panelById->get(MetaDashboardService::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, (string) ($c['campaign_id'] ?? '')));
            $spend = round((float) ($c['spend'] ?? 0), 2);

            return [
                $name,
                $this->money($spend),
                $row === null ? 'no está' : ($row['spend'] === null ? '—' : $this->money((float) $row['spend'])),
                (int) ($c['reach'] ?? 0),
                $row['reach'] ?? '—',
                $row !== null && $row['spend'] !== null && abs($spend - (float) $row['spend']) < self::SPEND_TOLERANCE ? 'SÍ' : 'NO',
            ];
        })->values()->all();

        $this->table(['campaña', 'gasto Meta', 'gasto panel', 'alcance Meta', 'alcance panel', '¿concuerda?'], $campaignRows);

        $campaignsMatch = collect($campaignRows)->every(fn (array $r): bool => $r[5] === 'SÍ');

        $this->line('META_SPEND='.$this->money($metaSpend));
        $this->line('LOCAL_SPEND='.$this->money($localSpend));
        $this->line('DASHBOARD_SPEND='.($panelSpend === null ? '—' : $this->money((float) $panelSpend)));
        $this->line('SPEND_MATCH='.($spendMatch ? 'YES' : 'NO'));
        $this->line('IMPRESSIONS_MATCH='.($impressionsMatch ? 'YES' : 'NO'));
        $this->line('CAMPAIGNS_META='.$metaWithSpend->count().' · CAMPAIGNS_PANEL='.$panelRows->filter(fn (array $r): bool => ($r['spend'] ?? 0) > 0)->count().' · CAMPAIGNS_MATCH='.($campaignsMatch ? 'YES' : 'NO'));

        return $spendMatch && $impressionsMatch ? self::SUCCESS : self::FAILURE;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
