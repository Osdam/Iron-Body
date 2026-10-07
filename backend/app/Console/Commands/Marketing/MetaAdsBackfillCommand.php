<?php

namespace App\Console\Commands\Marketing;

use App\Models\MetaAdEntity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Relleno del pasado de Meta Ads (FASE 5), por tramos de días y por cuenta.
 *
 *  - Con `--dry-run` solo pregunta a Meta y cuenta lo que traería (días,
 *    campañas, conjuntos, anuncios, filas y peticiones); no escribe nada.
 *  - Sin él, sincroniza tramo a tramo con la MISMA pasada que el scheduler
 *    (mismo cerrojo, misma transacción, misma clave única): repetirlo no
 *    duplica gasto, y un tramo a medias no deja nada a medias.
 *  - Reanudable: salta los tramos que una pasada buena DE ESA CUENTA ya cubre
 *    (salvo `--force`). Si se corta, se vuelve a lanzar igual.
 *  - Con pausa entre tramos y, ante un límite de Meta o un fallo pasajero,
 *    espera creciente y reintento; ante un permiso denegado, se detiene.
 *  - Nunca antes de 2026-01-01. Solo lee de Meta.
 *
 * MULTICUENTA: por defecto todas las cuentas conectadas, una detrás de otra;
 * con `--account`, solo esas. Al final, estados y alcance por cuenta, y un
 * resumen por cuenta y el total. Una cuenta que falla (un permiso, por ejemplo)
 * no impide las demás; un límite de Meta que no se pasa reintentando sí las
 * detiene, porque el cupo es de la app y lo comparten todas.
 */
class MetaAdsBackfillCommand extends Command
{
    /** El primer día que se puede rellenar [DECISIÓN DEL USUARIO]: el mismo suelo que cualquier pasada. */
    public const MIN_DATE = MetaAdsSync::MIN_DATE;

    /** Espera antes de cada reintento, en segundos. */
    public const BACKOFF = [60, 180, 600];

    protected $signature = 'marketing:meta-ads-backfill
        {--from=2026-01-01 : Primer día, AAAA-MM-DD en la zona de la cuenta (no antes de 2026-01-01)}
        {--to= : Último día, inclusive. Por defecto, hoy}
        {--account=* : Cuenta(s) conectada(s), con o sin act_ (se puede repetir o separar por comas). Por defecto, todas}
        {--batch-days=14 : Días por tramo}
        {--pause=2 : Segundos de pausa entre tramos}
        {--max-retries=3 : Reintentos por tramo ante límites o fallos pasajeros}
        {--force : Repite también los tramos que ya están cubiertos}
        {--dry-run : Solo cuenta lo que traería, sin escribir nada}';

    protected $description = 'Rellena el gasto de Meta Ads del pasado por tramos y por cuenta conectada (reanudable, idempotente, con reintentos). Desde 2026-01-01.';

    public function handle(MetaAdsSync $sync, MetaAdsApiClient $client): int
    {
        $problem = $client->configurationProblem();
        if ($problem !== null) {
            $this->error(MetaAdsApiClient::problemMessage($problem));

            return self::FAILURE;
        }

        $accounts = $this->accountsOption($client);
        if ($accounts === false) {
            return self::FAILURE;
        }

        $tz = $client->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = $this->day((string) $this->option('from'), $tz);
        $to = $this->option('to') ? $this->day((string) $this->option('to'), $tz) : $today;
        $batch = max(1, (int) $this->option('batch-days'));

        if ($from === null || $to === null) {
            return self::FAILURE;
        }
        if ($from->toDateString() < self::MIN_DATE) {
            $this->error('El relleno no puede empezar antes de '.self::MIN_DATE.'.');

            return self::FAILURE;
        }
        if ($to->greaterThan($today)) {
            $to = $today;
        }
        if ($from->greaterThan($to)) {
            $this->error('El primer día es posterior al último.');

            return self::FAILURE;
        }

        $batches = [];
        for ($start = $from; $start->lessThanOrEqualTo($to); $start = $start->addDays($batch)) {
            $end = $start->addDays($batch - 1);
            $batches[] = [$start->toDateString(), ($end->greaterThan($to) ? $to : $end)->toDateString()];
        }

        $days = (int) $from->diffInDays($to) + 1;
        $names = MetaAdEntity::accountNames($accounts);
        $several = count($accounts) > 1;

        $failed = false;
        $stopped = false;
        $totals = [];
        foreach ($accounts as $i => $account) {
            if ($several) {
                $this->info('Cuenta '.$this->label($account, $names[$account] ?? null).':');
            }

            if ($stopped) {
                $this->warn('  No se pidió: Meta pidió frenar en una cuenta anterior (el cupo es de la app). Vuelve a lanzarlo más tarde.');
                $failed = true;

                continue;
            }
            if ($i > 0) {
                $this->pause();
            }

            $outcome = $this->option('dry-run')
                ? $this->dryRun($client->forAccount($account), $batches, $days)
                : $this->apply($sync->forAccount($account), $client->forAccount($account), $batches, $days, $from->toDateString(), $to->toDateString());

            $failed = $failed || $outcome['status'] !== self::SUCCESS;
            $stopped = $outcome['rate_limited'];
            if ($outcome['totals'] !== null) {
                $totals[$account] = $outcome['totals'];
            }
        }

        if ($several && $totals !== []) {
            $this->info('Total de las cuentas:');
            $this->table(['dato', 'valor'], [
                ['accounts', count($totals).' de '.count($accounts)],
                ['insight_rows', array_sum(array_column($totals, 'rows'))],
                ['spend', number_format(array_sum(array_column($totals, 'spend')), 2, '.', '')],
            ]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $batches
     * @return array{status: int, rate_limited: bool, totals: ?array{rows: int, spend: float}}
     */
    private function dryRun(MetaAdsApiClient $client, array $batches, int $days): array
    {
        $campaigns = [];
        $adsets = [];
        $ads = [];
        $rows = 0;
        $requests = 0;
        $spend = 0.0;

        foreach ($batches as [$since, $until]) {
            try {
                $raw = $client->insights($since, $until);
            } catch (MetaAdsApiException $e) {
                $this->error("Tramo {$since} → {$until}: Meta falló ({$e->category}): {$e->getMessage()}");

                return ['status' => self::FAILURE, 'rate_limited' => $e->category === MetaAdsApiException::RATE_LIMIT, 'totals' => null];
            }

            $requests += max(1, (int) ceil(count($raw) / 500));
            $rows += count($raw);
            foreach ($raw as $r) {
                $campaigns[(string) ($r['campaign_id'] ?? '')] = true;
                $adsets[(string) ($r['adset_id'] ?? '')] = true;
                $ads[(string) ($r['ad_id'] ?? '')] = true;
                $spend += is_numeric($r['spend'] ?? null) ? (float) $r['spend'] : 0.0;
            }
            $this->line("  {$since} → {$until}: ".count($raw).' filas');
            $this->pause();
        }

        unset($campaigns[''], $adsets[''], $ads['']);
        // La pasada real pide lo mismo de insights (una página por cada 500 filas)
        // y, una vez al final, el alcance (5 rangos × 2 niveles) y los estados (los
        // bordes /campaigns, /adsets y /ads: una página cada uno, más si alguno pasa
        // de 500 objetos). Los tramos no piden estados.
        $estimated = $requests + 10 + 3;

        $this->table(['dato', 'valor'], [
            ['days', $days],
            ['batches', count($batches)],
            ['campaigns', count($campaigns)],
            ['adsets', count($adsets)],
            ['ads', count($ads)],
            ['insight_rows', $rows],
            ['spend', number_format($spend, 2, '.', '')],
            ['estimated_requests', $estimated],
        ]);
        $this->info('Simulación: no se escribió nada.');

        return ['status' => self::SUCCESS, 'rate_limited' => false, 'totals' => ['rows' => $rows, 'spend' => $spend]];
    }

    /**
     * El relleno de UNA cuenta: sus tramos, y al final sus estados y su alcance.
     *
     * @param  list<array{0: string, 1: string}>  $batches
     * @return array{status: int, rate_limited: bool, totals: ?array{rows: int, spend: float}}
     */
    private function apply(MetaAdsSync $sync, MetaAdsApiClient $client, array $batches, int $days, string $from, string $to): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($batches as $i => [$since, $until]) {
            $label = sprintf('[%d/%d] %s → %s', $i + 1, count($batches), $since, $until);

            if (! $this->option('force') && $sync->covers($since, $until)) {
                $skipped++;
                $this->line("{$label}: ya cubierto, se salta");

                continue;
            }

            $result = $this->runWithRetries($sync, $client->timezone(), $since, $until, $label);
            if ($result['status'] !== MetaSyncRun::STATUS_OK) {
                $this->error("{$label}: se detiene el relleno ({$result['error_code']}). Lo hecho queda guardado; vuelve a lanzarlo para seguir.");
                $totals = $this->summary($client, $from, $to, $days, count($batches), $done, $skipped, 'PARTIAL');

                // Un límite que no se pasó reintentando es del cupo de la app: detiene también las demás cuentas.
                return [
                    'status' => self::FAILURE,
                    'rate_limited' => $result['error_code'] === MetaAdsApiException::RATE_LIMIT || $sync->limited(),
                    'totals' => $totals,
                ];
            }

            $done++;
            $this->line("{$label}: OK · {$result['rows_upserted']} filas, {$result['rows_deleted']} borradas");
            $this->pause();
        }

        $reach = $sync->refreshReach();
        $this->line($reach['status'] === MetaAdsSync::REACH_SKIPPED_INACTIVE
            ? 'Alcance de los rangos del panel: no se pidió, sin impresiones en '.MetaAdsSync::REACH_ACTIVE_DAYS.' días (su alcance es 0).'
            : "Alcance de los rangos del panel: {$reach['ranges_ok']} al día, {$reach['ranges_failed']} con fallo.");
        // Los tramos no piden estados (son de toda la cuenta): una sola vez, aquí.
        $estados = $sync->refreshStatuses();
        $this->line("Estados de Meta aplicados: {$estados['campaigns']} campañas, {$estados['adsets']} conjuntos y {$estados['ads']} anuncios ({$estados['status']}).");
        $totals = $this->summary($client, $from, $to, $days, count($batches), $done, $skipped, 'OK');

        return ['status' => self::SUCCESS, 'rate_limited' => $sync->limited(), 'totals' => $totals];
    }

    /** @return array<string, mixed> el resultado de la última pasada del tramo */
    private function runWithRetries(MetaAdsSync $sync, string $tz, string $since, string $until, string $label): array
    {
        $max = max(0, (int) $this->option('max-retries'));

        for ($attempt = 0; ; $attempt++) {
            // Días de la CUENTA: la pasada los vuelve a leer en su zona.
            $result = $sync->run(
                MetaSyncRun::TRIGGER_BACKFILL,
                CarbonImmutable::createFromFormat('!Y-m-d', $since, $tz),
                CarbonImmutable::createFromFormat('!Y-m-d', $until, $tz),
            );

            $retryable = $result['status'] === MetaSyncRun::STATUS_RUNNING
                || ($result['status'] === MetaSyncRun::STATUS_FAILED && ($result['retryable'] ?? false) === true);

            if ($result['status'] === MetaSyncRun::STATUS_OK || ! $retryable || $attempt >= $max) {
                return $result + ['error_code' => $result['error_code'] ?? $result['status'], 'rows_upserted' => 0, 'rows_deleted' => 0];
            }

            $wait = self::BACKOFF[min($attempt, count(self::BACKOFF) - 1)];
            $why = $result['status'] === MetaSyncRun::STATUS_RUNNING ? 'hay otra pasada en curso' : 'Meta pidió esperar ('.$result['error_code'].')';
            $this->warn("{$label}: {$why}; reintento en {$wait} s.");
            Sleep::for($wait)->seconds();
        }
    }

    /**
     * El resumen de la cuenta, de lo que hay en la base para el rango.
     *
     * @return array{rows: int, spend: float}
     */
    private function summary(MetaAdsApiClient $client, string $from, string $to, int $days, int $batches, int $done, int $skipped, string $state): array
    {
        $account = (string) $client->accountId();
        $base = fn () => DB::table('meta_ad_insights_daily')->where('ad_account_id', $account)->whereBetween('date', [$from, $to]);
        $rows = $base()->count();
        $spend = (float) $base()->sum('spend');

        $this->table(['dato', 'valor'], [
            ['BACKFILL_2026', $state],
            ['days', $days],
            ['batches', "{$batches} ({$done} sincronizados, {$skipped} ya cubiertos)"],
            ['INSIGHT_ROWS', $rows],
            ['CAMPAIGNS_SYNCED', $base()->whereNotNull('campaign_id')->distinct()->count('campaign_id')],
            ['ADSETS_SYNCED', $base()->whereNotNull('adset_id')->distinct()->count('adset_id')],
            ['ADS_SYNCED', $base()->distinct()->count('ad_id')],
            ['spend', number_format($spend, 2, '.', '')],
        ]);

        return ['rows' => $rows, 'spend' => $spend];
    }

    /**
     * Las cuentas de `--account`, normalizadas, o todas las conectadas si no
     * vino; false si alguna no está conectada.
     *
     * @return list<string>|false
     */
    private function accountsOption(MetaAdsApiClient $client): array|false
    {
        $connected = $client->accountIds();
        $raw = (array) $this->option('account');
        if ($raw === []) {
            return $connected;
        }

        $wanted = MetaAdsApiClient::parseAccountIds($raw);
        if ($wanted === [] || array_diff($wanted, $connected) !== []) {
            $this->error('--account tiene que ser una cuenta conectada (META_AD_ACCOUNT_IDS): '.implode(', ', $connected).'.');

            return false;
        }

        return $wanted;
    }

    private function label(string $account, ?string $name): string
    {
        $ref = LeadSourceClassifier::maskId($account);

        return $name !== null ? "{$name} ({$ref})" : (string) $ref;
    }

    private function pause(): void
    {
        $seconds = max(0, (int) $this->option('pause'));
        if ($seconds > 0) {
            Sleep::for($seconds)->seconds();
        }
    }

    private function day(string $raw, string $tz): ?CarbonImmutable
    {
        $raw = trim($raw);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $raw, $tz) : false;

        // createFromFormat acepta el 31 de febrero y lo corre a marzo; esa no es la fecha que se pidió.
        if ($day === false || $day->format('Y-m-d') !== $raw) {
            $this->error("No entiendo esa fecha: {$raw} (usa AAAA-MM-DD).");

            return null;
        }

        return $day;
    }
}
