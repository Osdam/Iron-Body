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
 * tablas del CRM y lo que enseña el panel, cifra a cifra, POR CUENTA conectada:
 *
 *  B) Graph API: totales de la CUENTA en el rango (gasto, impresiones, alcance)
 *     y la lista de campañas con su gasto, como el Administrador de anuncios.
 *  C) Tablas locales: la suma de meta_ad_insights_daily de esa cuenta y la foto
 *     de alcance del rango exacto, si la hay.
 *  D) Panel: la fila de esa cuenta y sus campañas en MetaDashboardService.
 *
 * Y una comprobación CONSOLIDADA: el gasto del panel (el de todas las cuentas)
 * es la suma de lo que Meta da para cada una, y las filas por cuenta del panel
 * suman lo mismo. Así se ve que nada se cuenta dos veces ni se queda fuera.
 *
 * El gasto concuerda si B, C y D difieren en menos de un peso (redondeo por
 * fila de Meta). Las impresiones, al ser recuentos, tienen que ser iguales. El
 * alcance de Meta y el de la foto pueden diferir si el rango incluye hoy (Meta
 * lo recalcula cada hora); se informa, no se exige.
 *
 * Solo lee. Falla (código distinto de 0) si el gasto o las impresiones de
 * alguna cuenta no concuerdan, si el panel no da el gasto como completo, o si
 * el consolidado no cuadra. Si Meta pide frenar en una cuenta, las siguientes
 * no se piden (el cupo es de la app): quedan «no pedidas», el total de Meta
 * no se sabe y también falla.
 */
class MetaAdsReconcileCommand extends Command
{
    /** Diferencia de gasto tolerada, en la moneda de la cuenta: el redondeo por fila de Meta. */
    public const SPEND_TOLERANCE = 1.0;

    protected $signature = 'marketing:meta-ads-reconcile
        {--period=30d : today, 7d, 30d, this_month, last_month o custom}
        {--from= : Con custom, primer día AAAA-MM-DD}
        {--to= : Con custom, último día AAAA-MM-DD}';

    protected $description = 'Concilia un periodo entre Meta (Graph API), las tablas del CRM y el panel, por cuenta y en total: gasto, impresiones, alcance y campañas.';

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

        // D) El panel, una vez: el gasto consolidado, las filas por cuenta y las campañas.
        $panel = $dashboard->dashboard($period);
        $accountRows = collect($panel['accounts']['rows'])->keyBy(fn (array $r): string => (string) $r['id']);
        $campaignRows = collect($panel['campaigns']['rows'])->filter(fn (array $r): bool => $r['id'] !== MetaDashboardService::UNRESOLVED);

        $accounts = $client->accountIds();
        $names = MetaAdEntity::accountNames($accounts);
        $this->info("Periodo {$period['key']}: {$first} → {$last} (días de la cuenta)");

        $allMatch = true;
        $metaTotal = 0.0;
        $metaKnown = true;
        $limited = false;
        foreach ($accounts as $account) {
            $ref = (string) LeadSourceClassifier::maskId($account);
            $this->info('Cuenta '.(($names[$account] ?? null) !== null ? "{$names[$account]} ({$ref})" : $ref).':');

            // El cupo de Meta es de la app y lo comparten todas: tras un límite, no se pide nada más.
            if ($limited) {
                $this->warn('  No se pidió: Meta pidió frenar en una cuenta anterior (el cupo es de la app). Vuelve a lanzarlo más tarde.');
                $allMatch = false;
                $metaKnown = false;

                continue;
            }

            // Con el consolidado parcial, el panel corta TODAS las cuentas en el día común, y una
            // cuenta completa saldría descuadrada sin estarlo: cada una se concilia entonces con su
            // propia lectura, sin cortar. Lo consolidado lo comprueba aparte el bloque de abajo.
            $own = $reader->forAccount($account);
            $panelComplete = ($panel['spend']['complete'] ?? false) === true;
            $match = $this->reconcileAccount(
                $client->forAccount($account),
                $own,
                $account,
                $first,
                $last,
                $panelComplete
                    ? $accountRows->get(MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_ACCOUNT, $account))
                    : ($own->accounts($period['start'], $period['end'])[0] ?? null),
                $panelComplete
                    ? $campaignRows->filter(fn (array $r): bool => ($r['account_id'] ?? null) === MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_ACCOUNT, $account))
                    : $own->byLevel(MetaAdEntity::LEVEL_CAMPAIGN, $period['start'], $period['end'])
                        ->map(fn (array $r): array => ['id' => MetaDashboardService::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, (string) $r['id'])] + $r)
                        ->all(),
            );

            $allMatch = $allMatch && $match['ok'];
            $limited = $match['limited'];
            if ($match['meta_spend'] === null) {
                $metaKnown = false;
            } else {
                $metaTotal += $match['meta_spend'];
            }
        }

        // El consolidado: el gasto del panel es la suma de las cuentas, y las filas por cuenta suman lo mismo.
        $panelSpend = $panel['spend']['amount'];
        $rowsSpend = $accountRows->every(fn (array $r): bool => $r['spend'] !== null && $r['partial'] === false)
            ? round($accountRows->sum(fn (array $r): float => (float) $r['spend']), 2)
            : null;
        $consolidated = $metaKnown
            && $panelSpend !== null
            && ($panel['spend']['complete'] ?? false) === true
            && $rowsSpend !== null
            && abs($metaTotal - (float) $panelSpend) < self::SPEND_TOLERANCE
            && abs((float) $panelSpend - $rowsSpend) < 0.005;

        $this->info('Consolidado ('.count($accounts).' cuentas):');
        $this->line('TOTAL_META_SPEND='.($metaKnown ? $this->money($metaTotal) : '—'));
        $this->line('DASHBOARD_TOTAL_SPEND='.($panelSpend === null ? '— ('.$panel['spend']['status'].')' : $this->money((float) $panelSpend)));
        $this->line('ACCOUNT_ROWS_SPEND='.($rowsSpend === null ? '—' : $this->money($rowsSpend)));
        $this->line('CONSOLIDATED_MATCH='.($consolidated ? 'YES' : 'NO'));

        return $allMatch && $consolidated ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Una cuenta: Meta, las tablas y su fila del panel.
     *
     * `limited`: Meta contestó «límite»; quien recorre las cuentas no pide las siguientes.
     *
     * @param  array<string, mixed>|null  $panelRow  la fila de la cuenta en `accounts.rows`
     * @param  iterable<array<string, mixed>>  $panelCampaigns  sus filas en `campaigns.rows`
     * @return array{ok: bool, meta_spend: ?float, limited: bool}
     */
    private function reconcileAccount(
        MetaAdsApiClient $client,
        MetaSpendReader $reader,
        string $account,
        string $first,
        string $last,
        ?array $panelRow,
        iterable $panelCampaigns,
    ): array {
        // B) Meta.
        try {
            $metaTotals = $client->accountTotals($first, $last) ?? [];
            $metaCampaigns = $client->reach('campaign', $first, $last);
        } catch (MetaAdsApiException $e) {
            $this->error("Meta no contestó ({$e->category}): {$e->getMessage()}");
            $limited = $e->category === MetaAdsApiException::RATE_LIMIT;
            if ($limited) {
                $this->warn('Meta pidió frenar: las cuentas siguientes no se piden en esta vuelta.');
            }

            return ['ok' => false, 'meta_spend' => null, 'limited' => $limited];
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

        // D) Panel: la fila de la cuenta.
        $panelSpend = $panelRow['spend'] ?? null;
        $panelComplete = $panelRow !== null && $panelSpend !== null && $panelRow['partial'] === false;
        $panelById = collect($panelCampaigns)->keyBy(fn (array $r): string => (string) $r['id']);

        $spendMatch = $panelComplete
            && abs($metaSpend - $localSpend) < self::SPEND_TOLERANCE
            && abs($metaSpend - (float) $panelSpend) < self::SPEND_TOLERANCE;
        $impressionsMatch = $metaImpressions === $localImpressions;

        $this->table(['cifra', 'Meta (Graph API)', 'Tablas del CRM', 'Panel', '¿concuerda?'], [
            ['Gasto', $this->money($metaSpend), $this->money($localSpend), $panelSpend === null ? '— (sin cifra)' : $this->money((float) $panelSpend).($panelComplete ? '' : ' (parcial)'), $spendMatch ? 'SÍ' : 'NO'],
            ['Impresiones', $metaImpressions, $localImpressions, $panelRow['impressions'] ?? '—', $impressionsMatch ? 'SÍ' : 'NO'],
            ['Alcance (cuenta)', $metaReach, $localReach === null ? '— (sin foto del rango)' : $localReach['reach'], $panelRow['reach'] ?? '—', $localReach === null ? '—' : ($localReach['reach'] === $metaReach ? 'SÍ' : 'difiere (se recalcula)')],
        ]);

        // Campañas: con gasto en Meta frente a las filas del panel de esa cuenta.
        $metaWithSpend = collect($metaCampaigns)->filter(fn (array $c): bool => (float) ($c['spend'] ?? 0) > 0);
        // Por id, no por nombre: Meta permite nombres repetidos. La fila del panel
        // lleva el id opaco, que se calcula igual desde el de Meta.
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
        $this->line('CAMPAIGNS_META='.$metaWithSpend->count().' · CAMPAIGNS_PANEL='.$panelById->filter(fn (array $r): bool => ($r['spend'] ?? 0) > 0)->count().' · CAMPAIGNS_MATCH='.($campaignsMatch ? 'YES' : 'NO'));

        return ['ok' => $spendMatch && $impressionsMatch, 'meta_spend' => $metaSpend, 'limited' => false];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
