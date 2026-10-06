<?php

namespace App\Console\Commands\Marketing;

use App\Models\MetaSyncRun;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Relleno del pasado de Meta Ads (FASE 5), por tramos de días.
 *
 *  - Con `--dry-run` solo pregunta a Meta y cuenta lo que traería (días,
 *    campañas, conjuntos, anuncios, filas y peticiones); no escribe nada.
 *  - Sin él, sincroniza tramo a tramo con la MISMA pasada que el scheduler
 *    (mismo cerrojo, misma transacción, misma clave única): repetirlo no
 *    duplica gasto, y un tramo a medias no deja nada a medias.
 *  - Reanudable: salta los tramos que una pasada buena ya cubre (salvo
 *    `--force`). Si se corta, se vuelve a lanzar igual.
 *  - Con pausa entre tramos y, ante un límite de Meta o un fallo pasajero,
 *    espera creciente y reintento; ante un permiso denegado, se detiene.
 *  - Nunca antes de 2026-01-01.
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
        {--batch-days=14 : Días por tramo}
        {--pause=2 : Segundos de pausa entre tramos}
        {--max-retries=3 : Reintentos por tramo ante límites o fallos pasajeros}
        {--force : Repite también los tramos que ya están cubiertos}
        {--dry-run : Solo cuenta lo que traería, sin escribir nada}';

    protected $description = 'Rellena el gasto de Meta Ads del pasado por tramos (reanudable, idempotente, con reintentos). Desde 2026-01-01.';

    public function handle(MetaAdsSync $sync, MetaAdsApiClient $client): int
    {
        $problem = $client->configurationProblem();
        if ($problem !== null) {
            $this->error(MetaAdsApiClient::problemMessage($problem));

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

        return $this->option('dry-run')
            ? $this->dryRun($client, $batches, $days)
            : $this->apply($sync, $client, $batches, $days, $from->toDateString(), $to->toDateString());
    }

    /** @param  list<array{0: string, 1: string}>  $batches */
    private function dryRun(MetaAdsApiClient $client, array $batches, int $days): int
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

                return self::FAILURE;
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
        // Por tramo en la pasada real: /campaigns (1) y los estados de conjuntos y
        // anuncios por id (de 50 en 50); el alcance, una vez al final (5 rangos × 2).
        $estimated = $requests + count($batches) + (int) ceil(count($adsets) / 50) + (int) ceil(count($ads) / 50) + 10;

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

        return self::SUCCESS;
    }

    /** @param  list<array{0: string, 1: string}>  $batches */
    private function apply(MetaAdsSync $sync, MetaAdsApiClient $client, array $batches, int $days, string $from, string $to): int
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
                $this->summary($client, $from, $to, $days, count($batches), $done, $skipped, 'PARTIAL');

                return self::FAILURE;
            }

            $done++;
            $this->line("{$label}: OK · {$result['rows_upserted']} filas, {$result['rows_deleted']} borradas");
            $this->pause();
        }

        $reach = $sync->refreshReach();
        $this->line("Alcance de los rangos del panel: {$reach['ranges_ok']} al día, {$reach['ranges_failed']} con fallo.");
        $this->summary($client, $from, $to, $days, count($batches), $done, $skipped, 'OK');

        return self::SUCCESS;
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

    private function summary(MetaAdsApiClient $client, string $from, string $to, int $days, int $batches, int $done, int $skipped, string $state): void
    {
        $account = (string) $client->accountId();
        $base = fn () => DB::table('meta_ad_insights_daily')->where('ad_account_id', $account)->whereBetween('date', [$from, $to]);

        $this->table(['dato', 'valor'], [
            ['BACKFILL_2026', $state],
            ['days', $days],
            ['batches', "{$batches} ({$done} sincronizados, {$skipped} ya cubiertos)"],
            ['INSIGHT_ROWS', $base()->count()],
            ['CAMPAIGNS_SYNCED', $base()->whereNotNull('campaign_id')->distinct()->count('campaign_id')],
            ['ADSETS_SYNCED', $base()->whereNotNull('adset_id')->distinct()->count('adset_id')],
            ['ADS_SYNCED', $base()->distinct()->count('ad_id')],
            ['spend', number_format((float) $base()->sum('spend'), 2, '.', '')],
        ]);
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
