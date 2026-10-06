<?php

namespace App\Services\Marketing\Meta;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cuánto se gastó en Meta Ads en un periodo, y con qué certeza.
 *
 * El panel enseñaba $0 cuando no sabía nada, porque convertía la ausencia en
 * cero. Aquí la regla es la contraria: `amount = 0` SOLO cuando una pasada con
 * éxito cubrió todos los días del periodo y la suma dio cero (`real_zero`). En
 * cualquier otro caso de ignorancia, `amount = null` y un estado que dice por qué.
 *
 * El periodo llega como el rango `[desde, hasta)` del CRM, con el fin exclusivo
 * como en CashReceipts::businessDay, y se traduce a días de la cuenta
 * publicitaria en su zona horaria: lo gastado entre las 19:00 y la medianoche de
 * Bogotá es de ese día de Bogotá, no del siguiente día UTC. Un `hasta` que no
 * cae a medianoche exacta (`now()`, un `endOfDay()`) incluye su día.
 *
 * Esa traducción solo es exacta si la cuenta parte los días en la misma zona
 * que el CRM (`caja.timezone`). Si no, un día de Bogotá toca dos días de la
 * cuenta, y sin el desglose por hora no hay forma de repartirlos: el gasto es
 * `unknown` con `reason = timezone_mismatch`, nunca una suma aproximada.
 */
class MetaSpendReader
{
    /** La zona de la cuenta no es la del CRM: sus días no se pueden repartir en días del CRM. */
    public const REASON_TIMEZONE_MISMATCH = 'timezone_mismatch';

    /** Suma mayor que cero, con un éxito reciente. */
    public const OK = 'ok';

    /** Una pasada con éxito cubrió el periodo y no hubo gasto. Único caso con amount = 0. */
    public const REAL_ZERO = 'real_zero';

    /** No hay ninguna pasada con éxito que cubra el periodo entero. */
    public const UNKNOWN = 'unknown';

    /** Falta el token o la cuenta. */
    public const NOT_CONFIGURED = 'not_configured';

    /** El último intento falló y ningún éxito cubre el periodo. */
    public const SYNC_FAILED = 'sync_failed';

    /** Hay suma, pero el último éxito es más viejo que `stale_after_minutes`. */
    public const STALE = 'stale';

    public function __construct(
        private readonly MetaAdsApiClient $client,
        private readonly MetaAdsSync $sync,
    ) {}

    /**
     * El gasto del periodo.
     *
     * `reason` explica los estados sin cifra (`missing_access_token`,
     * `timezone_mismatch`, `never_synced`, `not_covered`, el código del último
     * error…) y vale `stale` cuando la cifra viene de un éxito viejo; también con
     * `real_zero`, que sigue siendo un cero comprobado.
     *
     * @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string,
     *     covered_to: ?string, reason: ?string}
     */
    public function snapshot(CarbonInterface $from, CarbonInterface $to): array
    {
        $problem = $this->client->configurationProblem();
        if ($problem !== null) {
            return $this->snapshotOf(self::NOT_CONFIGURED, null, null, null, null, $problem);
        }

        $sync = $this->sync->status();
        $syncedAt = $sync['last_success_at'];
        $coveredTo = $sync['covered_to'];

        if ($this->timezoneMismatch()) {
            return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $coveredTo, self::REASON_TIMEZONE_MISMATCH);
        }

        $days = $this->days($from, $to);
        if (is_string($days)) {
            return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $coveredTo, $days);
        }
        [$first, $last] = $days;

        if (! $this->sync->covers($first, $last)) {
            if ($sync['status'] === 'failed') {
                return $this->snapshotOf(
                    self::SYNC_FAILED, null, null, $syncedAt, $coveredTo, $sync['last_error']['code'] ?? 'failed',
                );
            }

            $reason = match (true) {
                $sync['status'] === 'running' => 'sync_running',
                $syncedAt === null => 'never_synced',
                default => 'not_covered',
            };

            return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $coveredTo, $reason);
        }

        $account = (string) $this->client->accountId();
        $base = MetaAdInsightDaily::query()
            ->where('ad_account_id', $account)
            ->whereBetween('date', [$first, $last]);

        $currencies = (clone $base)->whereNotNull('currency')->distinct()->pluck('currency')->all();
        if (count($currencies) > 1) {
            // Sumar dos monedas da una cifra sin sentido, y no se elige una.
            return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $coveredTo, 'mixed_currency');
        }

        $amount = round((float) (clone $base)->sum('spend'), 2);
        $currency = $currencies[0] ?? $this->lastKnownCurrency($account);
        $minutes = $sync['minutes_since_success'];
        $stale = $minutes === null || $minutes > max(1, (int) config('meta.ads.stale_after_minutes', 180));

        if (abs($amount) < 0.005) {
            // Cero de verdad: una pasada con éxito miró esos días y no hubo gasto.
            // Si esa pasada es vieja se dice en `reason`, pero sigue siendo un cero
            // comprobado y no uno por defecto.
            return $this->snapshotOf(self::REAL_ZERO, 0.0, $currency, $syncedAt, $coveredTo, $stale ? 'stale' : null);
        }

        return $this->snapshotOf(
            $stale ? self::STALE : self::OK, $amount, $currency, $syncedAt, $coveredTo, $stale ? 'stale' : null,
        );
    }

    /**
     * Gasto del periodo agrupado por campaña, conjunto o anuncio, de mayor a menor.
     *
     * Cada fila: id, name, campaign_id, adset_id, spend, currency, impressions,
     * clicks y status (el estado efectivo de Meta, si se conoce). Nombre, estado
     * y jerarquía salen de la dimensión de la cuenta configurada, nunca de otra.
     *
     * Salen las entidades con gasto en el periodo y, además, las de `$include`
     * (por ejemplo, aquellas a las que llevan los leads) aunque no lo tengan:
     *  - con alguna fila de gasto en la cuenta configurada (de cualquier día) y
     *    el periodo cubierto, su gasto es 0,00 comprobado;
     *  - sin NINGUNA fila de gasto en la cuenta configurada (es de otra cuenta, o
     *    su gasto nunca se vio), su gasto es null: no se sabe, y no es cero.
     *
     * Las cifras van en null también si ninguna pasada con éxito cubrió el
     * periodo entero (una suma parcial no se presenta como el total) o si la
     * cuenta parte los días en otra zona que el CRM. Sin configuración, colección
     * vacía.
     *
     * @param  iterable<int|string>  $include  ids de entidades de ese nivel que deben salir siempre
     * @return Collection<int, array{id: string, name: ?string, campaign_id: ?string, adset_id: ?string,
     *     spend: ?float, currency: ?string, impressions: ?int, clicks: ?int, status: ?string}>
     */
    public function byLevel(string $level, CarbonInterface $from, CarbonInterface $to, iterable $include = []): Collection
    {
        [$column, $nameColumn] = match ($level) {
            MetaAdEntity::LEVEL_CAMPAIGN => ['campaign_id', 'campaign_name'],
            MetaAdEntity::LEVEL_ADSET => ['adset_id', 'adset_name'],
            MetaAdEntity::LEVEL_AD => ['ad_id', 'ad_name'],
            default => throw new InvalidArgumentException("Nivel de Meta Ads desconocido: {$level}"),
        };

        $account = $this->client->accountId();
        if ($account === null || $this->client->configurationProblem() !== null) {
            return collect();
        }

        $known = false;
        $groups = collect();

        $days = $this->days($from, $to);
        if (is_array($days)) {
            [$first, $last] = $days;

            // Una cifra solo vale si una pasada con éxito cubrió el periodo entero
            // y los días de la cuenta son los días del CRM.
            $known = ! $this->timezoneMismatch() && $this->sync->covers($first, $last);

            // $column y $nameColumn salen del match de arriba, nunca de la petición.
            $groups = DB::table('meta_ad_insights_daily')
                ->where('ad_account_id', $account)
                ->whereBetween('date', [$first, $last])
                ->whereNotNull($column)
                ->groupBy($column)
                ->selectRaw("{$column} as entity_id")
                ->selectRaw('SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
                ->selectRaw('MAX(currency) as currency, MAX(campaign_id) as parent_campaign_id, MAX(adset_id) as parent_adset_id')
                ->selectRaw("MAX({$nameColumn}) as fallback_name")
                ->get()
                ->keyBy(fn (object $g): string => (string) $g->entity_id);
        }

        $missing = collect($include)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter(fn (string $id): bool => $id !== '' && ! $groups->has($id))
            ->unique()
            ->values();

        if ($groups->isEmpty() && $missing->isEmpty()) {
            return collect();
        }

        $entities = MetaAdEntity::query()
            ->forConfiguredAccount()
            ->where('level', $level)
            ->whereIn('entity_id', $groups->keys()->map(fn (int|string $id): string => (string) $id)->merge($missing)->all())
            ->get()
            ->keyBy('entity_id');

        // De las que no gastaron en el periodo, las que son de esta cuenta: tienen
        // alguna fila de gasto suya, de cualquier día.
        $ofAccount = $missing->isEmpty() ? collect() : DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->whereIn($column, $missing->all())
            ->distinct()
            ->pluck($column)
            ->mapWithKeys(fn (mixed $id): array => [(string) $id => true]);

        $currency = $known && $ofAccount->isNotEmpty() ? $this->lastKnownCurrency($account) : null;

        $spent = $groups->values()->map(function (object $g) use ($level, $entities, $known): array {
            $id = (string) $g->entity_id;
            $entity = $entities->get($id);

            return [
                'id' => $id,
                'name' => $entity?->name ?? $g->fallback_name,
                'campaign_id' => $level === MetaAdEntity::LEVEL_CAMPAIGN
                    ? $id
                    : ($g->parent_campaign_id ?? $entity?->campaign_id),
                'adset_id' => match ($level) {
                    MetaAdEntity::LEVEL_CAMPAIGN => null,
                    MetaAdEntity::LEVEL_ADSET => $id,
                    default => $g->parent_adset_id ?? $entity?->adset_id,
                },
                'spend' => $known ? round((float) $g->spend, 2) : null,
                'currency' => $g->currency,
                'impressions' => $known ? (int) $g->impressions : null,
                'clicks' => $known ? (int) $g->clicks : null,
                'status' => $entity?->effective_status,
            ];
        });

        $idle = $missing->map(function (string $id) use ($level, $entities, $known, $ofAccount, $currency): array {
            $entity = $entities->get($id);
            $zero = $known && $ofAccount->has($id);

            return [
                'id' => $id,
                'name' => $entity?->name,
                'campaign_id' => $level === MetaAdEntity::LEVEL_CAMPAIGN ? $id : $entity?->campaign_id,
                'adset_id' => match ($level) {
                    MetaAdEntity::LEVEL_CAMPAIGN => null,
                    MetaAdEntity::LEVEL_ADSET => $id,
                    default => $entity?->adset_id,
                },
                'spend' => $zero ? 0.0 : null,
                'currency' => $zero ? $currency : null,
                'impressions' => $zero ? 0 : null,
                'clicks' => $zero ? 0 : null,
                'status' => $entity?->effective_status,
            ];
        });

        // De mayor a menor gasto, y un gasto que no se sabe detrás de un cero
        // comprobado; a igual gasto, por nombre.
        return $spent
            ->concat($idle)
            ->sort(fn (array $a, array $b): int => [$b['spend'] ?? -1, (string) $a['name']] <=> [$a['spend'] ?? -1, (string) $b['name']])
            ->values();
    }

    /**
     * Días de la cuenta [primero, último] que abarca `[from, to)`, o el motivo por
     * el que no abarca ninguno.
     *
     * @return array{0: string, 1: string}|string
     */
    private function days(CarbonInterface $from, CarbonInterface $to): array|string
    {
        $tz = $this->client->timezone();
        $start = CarbonImmutable::instance($from)->setTimezone($tz);
        $end = CarbonImmutable::instance($to)->setTimezone($tz);

        $first = $start->toDateString();
        $last = $end->toDateString();

        // Fin exclusivo: un `hasta` a medianoche exacta no incluye ese día.
        if ($end->greaterThan($start) && $end->format('H:i:s.u') === '00:00:00.000000') {
            $last = $end->subDay()->toDateString();
        }

        if ($last < $first) {
            return 'invalid_range';
        }

        $today = CarbonImmutable::now($tz)->toDateString();
        if ($first > $today) {
            return 'future_period';
        }

        // Meta no tiene días que no han pasado: un periodo que llega a fin de mes
        // queda cubierto hasta hoy.
        return [$first, min($last, $today)];
    }

    /**
     * ¿Parte la cuenta los días en otra zona que el CRM (`caja.timezone`)?
     *
     * Se comparan los nombres de zona sin distinguir mayúsculas. Una zona con
     * otro nombre cuenta como distinta aunque hoy tenga el mismo desfase: la
     * regla es simple a propósito, y equivocarse aquí da «no se sabe», nunca una
     * cifra corrida un día.
     */
    private function timezoneMismatch(): bool
    {
        $crm = trim((string) config('caja.timezone'));

        return $crm === '' || strcasecmp($crm, $this->client->timezone()) !== 0;
    }

    private function lastKnownCurrency(string $account): ?string
    {
        $currency = MetaAdInsightDaily::query()
            ->where('ad_account_id', $account)
            ->whereNotNull('currency')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('currency');

        return is_string($currency) ? $currency : null;
    }

    /** @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string, covered_to: ?string, reason: ?string} */
    private function snapshotOf(
        string $status,
        ?float $amount,
        ?string $currency,
        ?string $syncedAt,
        ?string $coveredTo,
        ?string $reason,
    ): array {
        return [
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'last_synced_at' => $syncedAt,
            'covered_to' => $coveredTo,
            'reason' => $reason,
        ];
    }
}
