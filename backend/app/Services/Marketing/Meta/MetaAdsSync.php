<?php

namespace App\Services\Marketing\Meta;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaSyncRun;
use App\Services\Observability\ChannelLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Trae el gasto de Meta Ads a `meta_ad_insights_daily` y deja constancia de cada
 * intento en `meta_sync_runs`.
 *
 * La regla que ordena todo: o se escribe la foto ENTERA del rango, o no se toca
 * nada. Primero se descargan todas las páginas; solo entonces, en una
 * transacción, se actualiza lo que vino y se borra lo que Meta ya no devuelve
 * para esos días. Un fallo a mitad deja intacta la última foto buena y el
 * intento registrado como fallido: el panel podrá decir «desactualizado» o
 * «error», pero nunca enseñar $0 por una caída de Meta.
 *
 * Idempotente: la clave (cuenta, día, anuncio) hace que repetir una pasada
 * actualice en vez de duplicar, y un cerrojo impide dos pasadas a la vez.
 */
class MetaAdsSync
{
    public const LOCK_KEY = 'meta-ads-sync';

    /** Lo máximo que se espera de una pasada, y el plazo para darla por muerta. */
    public const LOCK_SECONDS = 900;

    /** Filas por sentencia: lejos del límite de parámetros de PostgreSQL y de SQLite. */
    private const CHUNK = 200;

    public function __construct(private readonly MetaAdsApiClient $client) {}

    /**
     * Sincroniza un rango de días de la cuenta, ambos inclusive.
     *
     * Sin rango, los últimos `meta.ads.sync_days` días hasta hoy. De `$from` y
     * `$to` se toma la fecha en la zona de la cuenta; un `$to` futuro se recorta
     * a hoy, porque Meta no tiene días que no han pasado.
     *
     * Devuelve `['status' => 'running']` si ya hay una pasada en curso, y si no
     * el resultado de esta: status (ok|failed|not_configured), run_id,
     * range_from, range_to, rows_upserted, rows_deleted, error_code,
     * error_message (saneado) y retryable.
     *
     * Un fallo de Meta NO lanza: queda en la fila del intento y en `retryable`.
     * Un fallo propio (la base, un bug) se registra como `internal` con un texto
     * genérico, y se relanza con su detalle.
     *
     * @return array<string,mixed>
     */
    public function run(string $trigger = 'schedule', ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if (! in_array($trigger, MetaSyncRun::TRIGGERS, true)) {
            throw new InvalidArgumentException("Disparador de sincronización desconocido: {$trigger}");
        }

        $problem = $this->client->configurationProblem();
        if ($problem !== null) {
            $run = MetaSyncRun::create([
                'kind' => MetaSyncRun::KIND_INSIGHTS,
                'status' => MetaSyncRun::STATUS_NOT_CONFIGURED,
                'trigger' => $trigger,
                'ad_account_id' => $this->client->accountId(),
                'error_code' => $problem,
                'error_message' => MetaAdsApiClient::problemMessage($problem),
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            ChannelLog::info('meta.ads.sync.not_configured', ['run_id' => $run->id, 'reason' => $problem]);

            return $this->result($run);
        }

        [$since, $until] = $this->range($from, $to);

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['status' => MetaSyncRun::STATUS_RUNNING];
        }

        try {
            return $this->runLocked($trigger, $since, $until);
        } finally {
            $lock->release();
        }
    }

    /**
     * Cómo está la sincronización, para la cabecera del panel y para decidir si
     * se puede lanzar otra.
     *
     * `status`:
     *  - not_configured: falta el token o la cuenta (`reason` dice qué).
     *  - running: hay una pasada en curso.
     *  - failed: el último intento falló (`last_error`), aunque quede una foto
     *    anterior buena.
     *  - stale: el último éxito es más viejo que `stale_after_minutes`, o todavía
     *    no ha habido ninguno.
     *  - ok: lo demás.
     *
     * Las fechas van en ISO 8601. `covered_from`/`covered_to` son el tramo
     * continuo de días cubierto más reciente.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $problem = $this->client->configurationProblem();
        $account = $this->client->accountId();

        $runs = fn () => MetaSyncRun::query()
            ->where('kind', MetaSyncRun::KIND_INSIGHTS)
            ->when($account !== null, fn ($q) => $q->where('ad_account_id', $account));

        $lastSuccess = $runs()->where('status', MetaSyncRun::STATUS_OK)->orderByDesc('id')->first();
        $lastAttempt = $runs()
            ->whereIn('status', [MetaSyncRun::STATUS_RUNNING, MetaSyncRun::STATUS_OK, MetaSyncRun::STATUS_FAILED])
            ->orderByDesc('id')
            ->first();

        $running = false;
        $lastError = null;

        if ($lastAttempt?->status === MetaSyncRun::STATUS_RUNNING) {
            $running = $lastAttempt->started_at !== null
                && $lastAttempt->started_at->greaterThan(now()->subSeconds(self::LOCK_SECONDS));

            if (! $running) {
                // Murió sin cerrar su fila: a efectos del estado, es un fallo.
                $lastError = ['code' => MetaSyncRun::ERROR_INTERRUPTED, 'message' => 'La última pasada no terminó.'];
            }
        } elseif ($lastAttempt?->status === MetaSyncRun::STATUS_FAILED) {
            $lastError = ['code' => $lastAttempt->error_code, 'message' => $lastAttempt->error_message];
        }

        $successAt = $lastSuccess?->finished_at ?? $lastSuccess?->started_at;
        $minutes = $successAt !== null ? max(0, (int) floor($successAt->diffInMinutes(now()))) : null;
        $staleAfter = max(1, (int) config('meta.ads.stale_after_minutes', 180));

        $status = match (true) {
            $problem !== null => MetaSyncRun::STATUS_NOT_CONFIGURED,
            $running => MetaSyncRun::STATUS_RUNNING,
            $lastError !== null => MetaSyncRun::STATUS_FAILED,
            $minutes === null, $minutes > $staleAfter => 'stale',
            default => MetaSyncRun::STATUS_OK,
        };

        $intervals = $this->coverage();
        $latest = $intervals === [] ? null : $intervals[array_key_last($intervals)];

        return [
            'status' => $status,
            'configured' => $problem === null,
            'reason' => $problem,
            'last_success_at' => $successAt?->toIso8601String(),
            'last_attempt_at' => $lastAttempt?->started_at?->toIso8601String(),
            'minutes_since_success' => $minutes,
            'last_error' => $lastError,
            'covered_from' => $latest[0] ?? null,
            'covered_to' => $latest[1] ?? null,
        ];
    }

    /**
     * Días de la cuenta configurada cubiertos por pasadas CON ÉXITO, fundidos en
     * tramos continuos y ordenados. Es lo que separa «cero» de «no se sabe».
     *
     * @return list<array{0: string, 1: string}> tramos [desde, hasta], inclusive
     */
    public function coverage(): array
    {
        $account = $this->client->accountId();
        if ($account === null) {
            return [];
        }

        $ranges = MetaSyncRun::query()
            ->toBase()
            ->where('kind', MetaSyncRun::KIND_INSIGHTS)
            ->where('status', MetaSyncRun::STATUS_OK)
            ->where('ad_account_id', $account)
            ->whereNotNull('range_from')
            ->whereNotNull('range_to')
            ->distinct()
            ->orderBy('range_from')
            ->orderBy('range_to')
            ->get(['range_from', 'range_to']);

        $merged = [];
        foreach ($ranges as $range) {
            $from = substr((string) $range->range_from, 0, 10);
            $to = substr((string) $range->range_to, 0, 10);
            $last = array_key_last($merged);

            // Se funden los tramos que se solapan o se tocan: el 7 y el 8 son continuos.
            if ($last !== null && $from <= CarbonImmutable::parse($merged[$last][1])->addDay()->toDateString()) {
                if ($to > $merged[$last][1]) {
                    $merged[$last][1] = $to;
                }

                continue;
            }

            $merged[] = [$from, $to];
        }

        return $merged;
    }

    /** ¿Una pasada con éxito cubrió todos los días de `[from, to]` ('Y-m-d', inclusive)? */
    public function covers(string $from, string $to): bool
    {
        foreach ($this->coverage() as [$start, $end]) {
            if ($start <= $from && $to <= $end) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function runLocked(string $trigger, string $since, string $until): array
    {
        $account = (string) $this->client->accountId();

        // Con el cerrojo en la mano, cualquier fila `running` es de una pasada que
        // murió sin cerrarla. Se cierra para que el estado no diga «en curso».
        $this->closeInterrupted();

        $run = MetaSyncRun::create([
            'kind' => MetaSyncRun::KIND_INSIGHTS,
            'status' => MetaSyncRun::STATUS_RUNNING,
            'trigger' => $trigger,
            'ad_account_id' => $account,
            'range_from' => $since,
            'range_to' => $until,
            'started_at' => now(),
        ]);

        try {
            // Todo lo que viene de la red, ANTES de abrir la transacción.
            $rows = $this->normalize($this->client->insights($since, $until), $account, $since, $until);
            $campaigns = $this->campaignStatuses();

            $deleted = DB::transaction(function () use ($run, $rows, $campaigns, $account, $since, $until): int {
                $this->upsertInsights($rows);
                $deleted = $this->deleteVanished($rows, $account, $since, $until);
                $this->upsertEntities($rows, $campaigns, $account);

                $run->forceFill([
                    'status' => MetaSyncRun::STATUS_OK,
                    'rows_upserted' => count($rows),
                    'finished_at' => now(),
                ])->save();

                return $deleted;
            });
        } catch (MetaAdsApiException $e) {
            $run = $this->markFailed($run, $e->category, $e->getMessage());

            ChannelLog::warning('meta.ads.sync.failed', [
                'run_id' => $run->id,
                'trigger' => $trigger,
                'error_code' => $e->category,
                'http_status' => $e->httpStatus,
                'meta_code' => $e->metaCode,
                'meta_subcode' => $e->metaSubcode,
            ]);

            return $this->result($run, retryable: $e->retryable());
        } catch (Throwable $e) {
            // Un fallo nuestro (la base, un bug): se anota y se relanza, porque
            // dejarlo pasar por «Meta falló» mandaría a buscar donde no es.
            //
            // En la fila solo va un texto genérico: status() la enseña en el panel
            // a cualquiera con marketing.view, y el mensaje de una QueryException
            // trae el host, el puerto, la base y el SQL con sus valores. El
            // detalle va al log del canal y, como se relanza, el job lo deja en
            // `failed_jobs`.
            $recordError = null;
            try {
                $this->markFailed($run, MetaSyncRun::ERROR_INTERNAL, MetaSyncRun::INTERNAL_ERROR_MESSAGE);
            } catch (Throwable $record) {
                $recordError = class_basename($record);
            }

            ChannelLog::error('meta.ads.sync.crashed', [
                'run_id' => $run->id,
                'trigger' => $trigger,
                'error_code' => MetaSyncRun::ERROR_INTERNAL,
                'exception' => class_basename($e),
                'error_message' => MetaAdsApiClient::sanitize($e->getMessage(), 1000),
                'record_error' => $recordError,
            ]);

            throw $e;
        }

        // `since`/`until` y no `range_from`/`range_to`: LogRedactor toma por
        // teléfono toda clave con «from» o «to» y enmascararía la fecha.
        ChannelLog::info('meta.ads.sync.ok', [
            'run_id' => $run->id,
            'trigger' => $trigger,
            'since' => $since,
            'until' => $until,
            'rows_upserted' => count($rows),
            'rows_deleted' => $deleted,
        ]);

        return $this->result($run, deleted: $deleted);
    }

    /**
     * Valida y da forma a las filas de Meta.
     *
     * Una fila sin día o sin anuncio, un día fuera del rango pedido o un gasto que
     * no es un número invalidan la pasada entera: guardar el resto daría un total
     * que parece completo y no lo es.
     *
     * @param  list<array<string,mixed>>  $raw
     * @return array<string, array<string,mixed>> por «día|anuncio», en orden
     */
    private function normalize(array $raw, string $account, string $since, string $until): array
    {
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($raw as $r) {
            $date = is_string($r['date_start'] ?? null) ? $r['date_start'] : '';
            $adId = $this->id($r['ad_id'] ?? null);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $adId === null) {
                throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta devolvió una fila de insights sin día o sin anuncio.');
            }

            if ($date < $since || $date > $until) {
                throw new MetaAdsApiException(MetaAdsApiException::OTHER, "Meta devolvió un día ({$date}) fuera del rango pedido.");
            }

            $spend = $r['spend'] ?? '0';
            if (! is_numeric($spend)) {
                throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta devolvió un gasto que no es un número.');
            }

            // Si Meta repitiera una fila (dos páginas que se solapan), vale la
            // última: en PostgreSQL un upsert con la misma clave dos veces falla.
            $rows[$date.'|'.$adId] = [
                'ad_account_id' => $account,
                'date' => $date,
                'campaign_id' => $this->id($r['campaign_id'] ?? null),
                'campaign_name' => $this->name($r['campaign_name'] ?? null),
                'adset_id' => $this->id($r['adset_id'] ?? null),
                'adset_name' => $this->name($r['adset_name'] ?? null),
                'ad_id' => $adId,
                'ad_name' => $this->name($r['ad_name'] ?? null),
                'spend' => number_format((float) $spend, 2, '.', ''),
                'currency' => $this->currency($r['account_currency'] ?? null),
                'impressions' => $this->count($r['impressions'] ?? null),
                'reach' => $this->count($r['reach'] ?? null),
                'clicks' => $this->count($r['clicks'] ?? null),
                'synced_at' => $now,
            ];
        }

        // Por día: al montar la dimensión, el nombre del día más reciente gana.
        ksort($rows);

        return $rows;
    }

    /**
     * Estado efectivo de las campañas. Es accesorio: si falla, la pasada sigue
     * (el gasto ya está completo) y los estados se quedan como estaban.
     *
     * @return list<array{id: string, name: ?string, effective_status: ?string}>|null
     */
    private function campaignStatuses(): ?array
    {
        try {
            $raw = $this->client->campaigns();
        } catch (MetaAdsApiException $e) {
            ChannelLog::warning('meta.ads.sync.campaign_status_skipped', [
                'error_code' => $e->category,
                'http_status' => $e->httpStatus,
                'meta_code' => $e->metaCode,
            ]);

            return null;
        }

        $campaigns = [];
        foreach ($raw as $c) {
            $id = $this->id($c['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $status = is_string($c['effective_status'] ?? null) ? mb_substr(trim($c['effective_status']), 0, 32) : '';

            // Por id: una campaña repetida entre páginas haría fallar el upsert en
            // PostgreSQL (la misma clave dos veces en una sentencia).
            $campaigns[$id] = [
                'id' => $id,
                'name' => $this->name($c['name'] ?? null),
                'effective_status' => $status !== '' ? $status : null,
            ];
        }

        return array_values($campaigns);
    }

    /** @param  array<string, array<string,mixed>>  $rows */
    private function upsertInsights(array $rows): void
    {
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            MetaAdInsightDaily::query()->upsert(
                $chunk,
                ['ad_account_id', 'date', 'ad_id'],
                [
                    'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'ad_name',
                    'spend', 'currency', 'impressions', 'reach', 'clicks', 'synced_at',
                ],
            );
        }
    }

    /**
     * Borra las filas del rango que Meta ya no devuelve: un anuncio eliminado, un
     * gasto corregido a nada. Solo dentro del rango pedido y de esta cuenta; lo
     * que queda fuera no se ha vuelto a mirar y se respeta.
     *
     * @param  array<string, array<string,mixed>>  $rows
     */
    private function deleteVanished(array $rows, string $account, string $since, string $until): int
    {
        $vanished = DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->whereBetween('date', [$since, $until])
            ->get(['id', 'date', 'ad_id'])
            ->reject(fn (object $r): bool => isset($rows[substr((string) $r->date, 0, 10).'|'.$r->ad_id]))
            ->pluck('id')
            ->all();

        foreach (array_chunk($vanished, 500) as $ids) {
            DB::table('meta_ad_insights_daily')->whereIn('id', $ids)->delete();
        }

        return count($vanished);
    }

    /**
     * La dimensión campaña → conjunto → anuncio con lo que traen los insights.
     * El estado efectivo solo se escribe si se pudo leer de `/campaigns`; si no,
     * se conserva el que hubiera.
     *
     * Todo va con la cuenta de la pasada: la clave es (cuenta, nivel, id), y una
     * entidad de otra cuenta no se pisa ni se adopta.
     *
     * @param  array<string, array<string,mixed>>  $rows
     * @param  list<array{id: string, name: ?string, effective_status: ?string}>|null  $campaigns
     */
    private function upsertEntities(array $rows, ?array $campaigns, string $account): void
    {
        $now = now()->toDateTimeString();
        $entities = [];
        $uniqueBy = ['ad_account_id', 'level', 'entity_id'];

        $put = function (string $level, string $id, ?string $name, ?string $campaignId, ?string $adsetId) use (&$entities, $now, $account): void {
            $key = $level.'|'.$id;
            $entities[$key] = [
                'ad_account_id' => $account,
                'level' => $level,
                'entity_id' => $id,
                // Una fila sin nombre no borra el que trajo otra del mismo lote.
                'name' => $name ?? ($entities[$key]['name'] ?? null),
                'campaign_id' => $campaignId,
                'adset_id' => $adsetId,
                'synced_at' => $now,
            ];
        };

        foreach ($rows as $r) {
            if ($r['campaign_id'] !== null) {
                $put(MetaAdEntity::LEVEL_CAMPAIGN, $r['campaign_id'], $r['campaign_name'], $r['campaign_id'], null);
            }
            if ($r['adset_id'] !== null) {
                $put(MetaAdEntity::LEVEL_ADSET, $r['adset_id'], $r['adset_name'], $r['campaign_id'], $r['adset_id']);
            }
            $put(MetaAdEntity::LEVEL_AD, $r['ad_id'], $r['ad_name'], $r['campaign_id'], $r['adset_id']);
        }

        foreach (array_chunk(array_values($entities), self::CHUNK) as $chunk) {
            MetaAdEntity::query()->upsert($chunk, $uniqueBy, ['name', 'campaign_id', 'adset_id', 'synced_at']);
        }

        if ($campaigns === null || $campaigns === []) {
            return;
        }

        $statusRows = array_map(static fn (array $c): array => [
            'ad_account_id' => $account,
            'level' => MetaAdEntity::LEVEL_CAMPAIGN,
            'entity_id' => $c['id'],
            'name' => $c['name'] ?? ($entities[MetaAdEntity::LEVEL_CAMPAIGN.'|'.$c['id']]['name'] ?? null),
            'campaign_id' => $c['id'],
            'adset_id' => null,
            'effective_status' => $c['effective_status'],
            'synced_at' => $now,
        ], $campaigns);

        foreach (array_chunk($statusRows, self::CHUNK) as $chunk) {
            MetaAdEntity::query()->upsert($chunk, $uniqueBy, ['name', 'effective_status', 'synced_at']);
        }
    }

    /**
     * Rango de días de la cuenta a pedir, 'Y-m-d' inclusive.
     *
     * @return array{0: string, 1: string}
     */
    private function range(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $tz = $this->client->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $until = $to !== null ? CarbonImmutable::instance($to)->setTimezone($tz)->startOfDay() : $today;
        if ($until->greaterThan($today)) {
            $until = $today;
        }

        $since = $from !== null
            ? CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay()
            : $until->subDays(max(1, (int) config('meta.ads.sync_days', 7)) - 1);

        if ($since->greaterThan($until)) {
            throw new InvalidArgumentException('El rango a sincronizar empieza después de terminar, o en el futuro.');
        }

        return [$since->toDateString(), $until->toDateString()];
    }

    private function closeInterrupted(): void
    {
        MetaSyncRun::query()
            ->where('kind', MetaSyncRun::KIND_INSIGHTS)
            ->where('status', MetaSyncRun::STATUS_RUNNING)
            ->update([
                'status' => MetaSyncRun::STATUS_FAILED,
                'error_code' => MetaSyncRun::ERROR_INTERRUPTED,
                'error_message' => 'La pasada no terminó: el proceso se cortó o superó el plazo del cerrojo.',
                'finished_at' => now(),
            ]);
    }

    private function markFailed(MetaSyncRun $run, string $code, string $message): MetaSyncRun
    {
        // Se relee: si la transacción se deshizo después de guardar el «ok», la
        // copia en memoria ya no es lo que hay en la base.
        $run = $run->fresh() ?? $run;

        $run->forceFill([
            'status' => MetaSyncRun::STATUS_FAILED,
            'rows_upserted' => 0,
            'error_code' => $code,
            'error_message' => MetaAdsApiClient::sanitize($message),
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    /** @return array<string,mixed> */
    private function result(MetaSyncRun $run, int $deleted = 0, bool $retryable = false): array
    {
        return [
            'status' => $run->status,
            'run_id' => $run->id,
            'range_from' => $run->range_from,
            'range_to' => $run->range_to,
            'rows_upserted' => (int) $run->rows_upserted,
            'rows_deleted' => $deleted,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'retryable' => $retryable,
        ];
    }

    private function id(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : mb_substr($id, 0, 64);
    }

    private function name(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, 255);
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) === 1
            ? strtoupper(trim($value))
            : null;
    }

    private function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
