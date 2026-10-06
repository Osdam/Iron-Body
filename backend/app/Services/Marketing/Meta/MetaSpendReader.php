<?php

namespace App\Services\Marketing\Meta;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaAdReachSnapshot;
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
 * Con una excepción documentada: si la última pasada falló o va retrasada pero
 * hay datos buenos SIN HUECOS desde el primer día del periodo hasta un día
 * anterior al último, se da lo que se sabe: el importe de esos días, con
 * `complete = false` y `covered_to` = el último día cubierto. El panel lo enseña
 * como «datos actualizados hasta…» y no calcula ROAS ni CAC con él. Reemplazar
 * por un guion lo último bueno porque falló una pasada sería perder información;
 * reemplazarlo por cero, mentir.
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

    /** El importe es de una parte del periodo: hay datos buenos solo hasta `covered_to`. */
    public const REASON_PARTIAL = 'partial';

    /** Suma mayor que cero, con un éxito reciente. */
    public const OK = 'ok';

    /** Una pasada con éxito cubrió el periodo y no hubo gasto. Único caso con amount = 0 completo. */
    public const REAL_ZERO = 'real_zero';

    /** No hay ninguna pasada con éxito que cubra el periodo entero. */
    public const UNKNOWN = 'unknown';

    /** Falta el token o la cuenta. */
    public const NOT_CONFIGURED = 'not_configured';

    /** El último intento falló y ningún éxito cubre el periodo. */
    public const SYNC_FAILED = 'sync_failed';

    /** Hay suma, pero el último éxito es más viejo que `stale_after_minutes`. */
    public const STALE = 'stale';

    /** @var array<string, bool>|null */
    private ?array $available = null;

    public function __construct(
        private readonly MetaAdsApiClient $client,
        private readonly MetaAdsSync $sync,
    ) {}

    /**
     * El gasto del periodo.
     *
     * `reason` explica los estados sin cifra (`missing_access_token`,
     * `timezone_mismatch`, `never_synced`, `not_covered`, el código del último
     * error…), vale `stale` cuando la cifra viene de un éxito viejo y `partial`
     * cuando solo cubre una parte; también con `real_zero`, que sigue siendo un
     * cero comprobado. `complete` dice si el importe es de TODO el periodo.
     *
     * @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string,
     *     covered_to: ?string, reason: ?string, complete: bool}
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
        $account = (string) $this->client->accountId();
        $failed = $sync['status'] === 'failed';
        $seen = $this->seenThrough($sync);
        if ($seen !== null && $coveredTo !== null && $seen < $coveredTo) {
            $coveredTo = $seen;
        }

        if (! $this->covered($first, $last, $seen)) {
            $through = $this->through($first, $last, $seen);

            if ($through !== null) {
                // Lo último bueno, dicho como lo que es: una parte del periodo.
                [$amount, $currency, $mixed] = $this->sum($account, $first, $through);
                if ($mixed) {
                    return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $through, 'mixed_currency');
                }

                return $this->snapshotOf(
                    $failed ? self::SYNC_FAILED : self::STALE,
                    $amount,
                    $currency,
                    $syncedAt,
                    $through,
                    $failed ? ($sync['last_error']['code'] ?? 'failed') : self::REASON_PARTIAL,
                    false,
                );
            }

            if ($failed) {
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

        [$amount, $currency, $mixed] = $this->sum($account, $first, $last);
        if ($mixed) {
            // Sumar dos monedas da una cifra sin sentido, y no se elige una.
            return $this->snapshotOf(self::UNKNOWN, null, null, $syncedAt, $coveredTo, 'mixed_currency');
        }

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
     * Qué días del periodo se pueden afirmar.
     *
     *  - `known`: hay datos buenos desde el primer día (sin huecos) y la cuenta
     *    parte los días como el CRM.
     *  - `complete`: cubren el periodo entero.
     *  - `through`: el último día con datos buenos dentro del periodo.
     *
     * @return array{first: ?string, last: ?string, through: ?string, known: bool, complete: bool}
     */
    public function coverage(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $this->days($from, $to);
        if (is_string($days) || $this->client->configurationProblem() !== null || $this->timezoneMismatch()) {
            return [
                'first' => is_array($days) ? $days[0] : null,
                'last' => is_array($days) ? $days[1] : null,
                'through' => null,
                'known' => false,
                'complete' => false,
            ];
        }

        [$first, $last] = $days;
        $through = $this->through($first, $last, $this->seenThrough($this->sync->status()));

        return [
            'first' => $first,
            'last' => $last,
            'through' => $through,
            'known' => $through !== null,
            'complete' => $through === $last,
        ];
    }

    /**
     * Métricas del periodo agrupadas por campaña, conjunto o anuncio, de mayor a
     * menor gasto.
     *
     * Cada fila: id, name, campaign_id, adset_id, status (el estado efectivo de
     * Meta, si se conoce), spend, currency, impressions, clicks, link_clicks,
     * messaging_started, messaging_replied, landing_page_views, reach y
     * frequency, y `partial`. Nombre, estado y jerarquía salen de la dimensión
     * de la cuenta configurada, nunca de otra.
     *
     * Salen las entidades con actividad en el periodo y, además, las de
     * `$include` (por ejemplo, aquellas a las que llevan los leads) aunque no la
     * tengan:
     *  - con alguna fila de gasto en la cuenta configurada (de cualquier día) y
     *    el periodo cubierto, sus cifras son 0 comprobado;
     *  - sin NINGUNA fila de gasto en la cuenta configurada (es de otra cuenta, o
     *    su gasto nunca se vio), sus cifras son null: no se sabe, y no es cero.
     *
     * Si los datos buenos llegan solo hasta un día del periodo, las sumas son de
     * esos días y `partial = true`. Sin ningún dato bueno desde el primer día, o
     * si la cuenta parte los días en otra zona que el CRM, las cifras van en
     * null. El alcance solo sale para una campaña con foto de Meta de
     * EXACTAMENTE ese periodo completo: no se suma por días.
     *
     * `$parent` limita a los hijos de una campaña (nivel adset) o de un conjunto
     * (nivel ad).
     *
     * @param  iterable<int|string>  $include  ids de entidades de ese nivel que deben salir siempre
     * @param  array{level: string, id: string}|null  $parent
     * @return Collection<int, array<string, mixed>>
     */
    public function byLevel(
        string $level,
        CarbonInterface $from,
        CarbonInterface $to,
        iterable $include = [],
        ?array $parent = null,
    ): Collection {
        [$column, $nameColumn] = match ($level) {
            MetaAdEntity::LEVEL_CAMPAIGN => ['campaign_id', 'campaign_name'],
            MetaAdEntity::LEVEL_ADSET => ['adset_id', 'adset_name'],
            MetaAdEntity::LEVEL_AD => ['ad_id', 'ad_name'],
            default => throw new InvalidArgumentException("Nivel de Meta Ads desconocido: {$level}"),
        };
        $parentColumn = match ($parent['level'] ?? null) {
            null => null,
            MetaAdEntity::LEVEL_CAMPAIGN => 'campaign_id',
            MetaAdEntity::LEVEL_ADSET => 'adset_id',
            default => throw new InvalidArgumentException('Padre de Meta Ads desconocido.'),
        };

        $account = $this->client->accountId();
        if ($account === null || $this->client->configurationProblem() !== null) {
            return collect();
        }

        $cov = $this->coverage($from, $to);
        $known = $cov['known'];
        $partial = $known && ! $cov['complete'];
        $groups = collect();

        if ($cov['first'] !== null) {
            // Con datos buenos, solo los días cubiertos; sin ellos, todo el periodo
            // para listar las entidades (con sus cifras en null).
            $lastDay = $known ? $cov['through'] : $cov['last'];

            // $column, $nameColumn y $parentColumn salen de los match de arriba, nunca de la petición.
            $groups = DB::table('meta_ad_insights_daily')
                ->where('ad_account_id', $account)
                ->whereBetween('date', [$cov['first'], $lastDay])
                ->whereNotNull($column)
                ->when($parentColumn !== null, fn ($q) => $q->where($parentColumn, $parent['id']))
                ->groupBy($column)
                ->selectRaw("{$column} as entity_id")
                ->selectRaw('SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
                ->selectRaw('SUM(inline_link_clicks) as link_clicks, SUM(landing_page_views) as landing_page_views')
                ->selectRaw('SUM(messaging_conversations_started) as messaging_started, SUM(messaging_conversations_replied) as messaging_replied')
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

        // De las que no tuvieron actividad en el periodo, las que son de esta
        // cuenta: tienen alguna fila de gasto suya, de cualquier día.
        $ofAccount = $missing->isEmpty() ? collect() : DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->whereIn($column, $missing->all())
            ->distinct()
            ->pluck($column)
            ->mapWithKeys(fn (mixed $id): array => [(string) $id => true]);

        $currency = $known && $ofAccount->isNotEmpty() ? $this->lastKnownCurrency($account) : null;

        $reach = $level === MetaAdEntity::LEVEL_CAMPAIGN && $cov['complete']
            ? $this->campaignReach($account, $cov['first'], $cov['last'], $groups->keys()->merge($missing)->all())
            : [];

        $parentIds = function (string $id, ?MetaAdEntity $entity, ?object $g) use ($level): array {
            return [
                'campaign_id' => $level === MetaAdEntity::LEVEL_CAMPAIGN ? $id : ($g?->parent_campaign_id ?? $entity?->campaign_id),
                'adset_id' => match ($level) {
                    MetaAdEntity::LEVEL_CAMPAIGN => null,
                    MetaAdEntity::LEVEL_ADSET => $id,
                    default => $g?->parent_adset_id ?? $entity?->adset_id,
                },
            ];
        };

        $spent = $groups->values()->map(function (object $g) use ($entities, $known, $partial, $reach, $parentIds): array {
            $id = (string) $g->entity_id;
            $entity = $entities->get($id);
            $value = fn (mixed $v): ?int => $known ? (int) $v : null;

            return [
                'id' => $id,
                'name' => $entity?->name ?? $g->fallback_name,
                ...$parentIds($id, $entity, $g),
                'spend' => $known ? round((float) $g->spend, 2) : null,
                'currency' => $g->currency,
                'impressions' => $value($g->impressions),
                'clicks' => $value($g->clicks),
                'link_clicks' => $value($g->link_clicks),
                'messaging_started' => $value($g->messaging_started),
                'messaging_replied' => $value($g->messaging_replied),
                'landing_page_views' => $value($g->landing_page_views),
                'reach' => $reach[$id]['reach'] ?? null,
                'frequency' => $reach[$id]['frequency'] ?? null,
                'status' => $entity?->effective_status,
                'partial' => $partial,
            ];
        });

        $idle = $missing->map(function (string $id) use ($entities, $known, $partial, $ofAccount, $currency, $reach, $parentIds): array {
            $entity = $entities->get($id);
            $zero = $known && $ofAccount->has($id);

            return [
                'id' => $id,
                'name' => $entity?->name,
                ...$parentIds($id, $entity, null),
                'spend' => $zero ? 0.0 : null,
                'currency' => $zero ? $currency : null,
                'impressions' => $zero ? 0 : null,
                'clicks' => $zero ? 0 : null,
                'link_clicks' => $zero ? 0 : null,
                'messaging_started' => $zero ? 0 : null,
                'messaging_replied' => $zero ? 0 : null,
                'landing_page_views' => $zero ? 0 : null,
                'reach' => $reach[$id]['reach'] ?? null,
                'frequency' => $reach[$id]['frequency'] ?? null,
                'status' => $entity?->effective_status,
                'partial' => $partial,
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
     * Alcance, impresiones y frecuencia de la CUENTA en exactamente `[first,
     * last]` (días de la cuenta), si Meta los dio para ese rango. Null si no hay
     * foto de ese rango: el alcance no se suma por días.
     *
     * @return array{reach: int, impressions: int, frequency: ?float, synced_at: ?string}|null
     */
    public function accountReach(string $first, string $last): ?array
    {
        $account = $this->client->accountId();
        if ($account === null) {
            return null;
        }

        $row = DB::table('meta_ad_reach_snapshots')
            ->where('ad_account_id', $account)
            ->where('level', MetaAdReachSnapshot::LEVEL_ACCOUNT)
            ->where('entity_id', $account)
            ->where('date_from', $first)
            ->where('date_to', $last)
            ->first(['reach', 'impressions', 'frequency', 'synced_at']);

        return $row === null ? null : [
            'reach' => (int) $row->reach,
            'impressions' => (int) $row->impressions,
            'frequency' => $row->frequency !== null ? (float) $row->frequency : null,
            'synced_at' => $row->synced_at !== null ? (string) $row->synced_at : null,
        ];
    }

    /**
     * ¿Reporta Meta estas métricas para la cuenta? Una métrica que no aparece en
     * ninguna fila de la cuenta (todas a 0) no se enseña como 0, sino como «no
     * reportada»: puede ser que la cuenta no la tenga o que Meta la llame de
     * otra forma, y en los dos casos un 0 sería inventado.
     *
     * @return array{messaging_started: bool, messaging_replied: bool, landing_page_views: bool, reach: bool}
     */
    public function metricsAvailable(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $account = $this->client->accountId();
        if ($account === null || $this->client->configurationProblem() !== null) {
            return $this->available = ['messaging_started' => false, 'messaging_replied' => false, 'landing_page_views' => false, 'reach' => false];
        }

        $max = DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->selectRaw('MAX(messaging_conversations_started) as started, MAX(messaging_conversations_replied) as replied, MAX(landing_page_views) as lpv')
            ->first();

        return $this->available = [
            'messaging_started' => (int) ($max->started ?? 0) > 0,
            'messaging_replied' => (int) ($max->replied ?? 0) > 0,
            'landing_page_views' => (int) ($max->lpv ?? 0) > 0,
            'reach' => DB::table('meta_ad_reach_snapshots')->where('ad_account_id', $account)->exists(),
        ];
    }

    /**
     * El último día de la cuenta que se puede dar por visto ENTERO, o null si
     * vale todo lo cubierto.
     *
     * Una pasada ve su último día solo hasta la hora a la que corrió: la de las
     * 00:15 cubre «hoy» con quince minutos de datos. Mientras el último éxito es
     * reciente (`stale_after_minutes`) eso es lo esperado: hoy, hasta hace un
     * rato, aunque el intento siguiente haya fallado. Pero si el último éxito es
     * viejo, el día de ese éxito solo se vio en parte, y darlo por completo
     * pondría un cero comprobado, o un ROAS, donde no se sabe. Entonces solo
     * cuenta hasta el día anterior.
     *
     * @param  array<string, mixed>  $sync  {@see MetaAdsSync::status()}
     */
    private function seenThrough(array $sync): ?string
    {
        $minutes = $sync['minutes_since_success'];
        if ($sync['last_success_at'] === null || $minutes === null
            || $minutes <= max(1, (int) config('meta.ads.stale_after_minutes', 180))) {
            return null;
        }

        return CarbonImmutable::parse($sync['last_success_at'])
            ->setTimezone($this->client->timezone())
            ->subDay()
            ->toDateString();
    }

    /** ¿Hay datos buenos, vistos enteros, de todos los días de `[first, last]`? */
    private function covered(string $first, string $last, ?string $seen): bool
    {
        return ($seen === null || $last <= $seen) && $this->sync->covers($first, $last);
    }

    /** El último día de `[first, last]` con datos buenos sin huecos desde `first` y vistos enteros, o null. */
    private function through(string $first, string $last, ?string $seen): ?string
    {
        $end = $seen !== null && $seen < $last ? $seen : $last;
        if ($end < $first) {
            return null;
        }

        return $this->sync->covers($first, $end) ? $end : $this->sync->coveredThrough($first, $end);
    }

    /**
     * Días de la cuenta [primero, último] que abarca `[from, to)`, o el motivo por
     * el que no abarca ninguno.
     *
     * @return array{0: string, 1: string}|string
     */
    public function days(CarbonInterface $from, CarbonInterface $to): array|string
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
     * Gasto de la cuenta en `[first, last]`, su moneda y si mezcla monedas.
     *
     * @return array{0: float, 1: ?string, 2: bool}
     */
    private function sum(string $account, string $first, string $last): array
    {
        $base = MetaAdInsightDaily::query()
            ->where('ad_account_id', $account)
            ->whereBetween('date', [$first, $last]);

        $currencies = (clone $base)->whereNotNull('currency')->distinct()->pluck('currency')->all();
        if (count($currencies) > 1) {
            return [0.0, null, true];
        }

        return [
            round((float) (clone $base)->sum('spend'), 2),
            $currencies[0] ?? $this->lastKnownCurrency($account),
            false,
        ];
    }

    /**
     * Alcance por campaña de exactamente `[first, last]`, una consulta.
     *
     * @param  list<int|string>  $campaignIds
     * @return array<string, array{reach: int, frequency: ?float}>
     */
    private function campaignReach(string $account, string $first, string $last, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_map('strval', $campaignIds), 500) as $chunk) {
            $rows = DB::table('meta_ad_reach_snapshots')
                ->where('ad_account_id', $account)
                ->where('level', MetaAdReachSnapshot::LEVEL_CAMPAIGN)
                ->where('date_from', $first)
                ->where('date_to', $last)
                ->whereIn('entity_id', $chunk)
                ->get(['entity_id', 'reach', 'frequency']);

            foreach ($rows as $r) {
                $out[(string) $r->entity_id] = [
                    'reach' => (int) $r->reach,
                    'frequency' => $r->frequency !== null ? (float) $r->frequency : null,
                ];
            }
        }

        return $out;
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

    /** @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string, covered_to: ?string, reason: ?string, complete: bool} */
    private function snapshotOf(
        string $status,
        ?float $amount,
        ?string $currency,
        ?string $syncedAt,
        ?string $coveredTo,
        ?string $reason,
        bool $complete = true,
    ): array {
        return [
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'last_synced_at' => $syncedAt,
            'covered_to' => $coveredTo,
            'reason' => $reason,
            // Sin importe no hay nada que completar: solo un importe parcial dice false.
            'complete' => $amount === null ? true : $complete,
        ];
    }
}
