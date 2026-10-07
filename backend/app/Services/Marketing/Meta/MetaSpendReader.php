<?php

namespace App\Services\Marketing\Meta;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaAdReachSnapshot;
use App\Services\Observability\ChannelLog;
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
 *
 * MULTICUENTA. Sin ligar, lo que devuelve es CONSOLIDADO sobre las cuentas
 * conectadas: cada cuenta se lee por separado con {@see forAccount()} (la
 * lectura de una sola cuenta de siempre) y se juntan sus cifras, sin contar
 * nada dos veces (los ids de Meta son globales: una entidad es de una cuenta).
 * Un total solo se afirma si se puede afirmar el de cada cuenta: si una no
 * tiene datos que cubran el periodo, el total es «no se sabe», no la suma de
 * las demás. Y si una solo los tiene hasta un día, todo lo consolidado (el
 * importe, las campañas y las filas por cuenta) llega hasta ese mismo día.
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

    /** @var array<string, array{messaging_started: bool, messaging_replied: bool, landing_page_views: bool, reach: bool}>|null */
    private ?array $availability = null;

    public function __construct(
        private readonly MetaAdsApiClient $client,
        private readonly MetaAdsSync $sync,
    ) {}

    /**
     * El lector de UNA cuenta conectada: lo de siempre, para esa cuenta.
     *
     * @throws InvalidArgumentException si la cuenta no está entre las conectadas
     */
    public function forAccount(string $id): self
    {
        return new self($this->client->forAccount($id), $this->sync->forAccount($id));
    }

    /**
     * El gasto del periodo: el de la cuenta ligada o, sin ligar, el de TODAS
     * las conectadas, consolidado ({@see consolidate()}), con el de cada una en
     * `accounts` (`id` opaco, `ref`, `name`, `status`, `amount`, `currency`,
     * `complete`, `covered_to` y `reason`).
     *
     * `reason` explica los estados sin cifra (`missing_access_token`,
     * `timezone_mismatch`, `never_synced`, `not_covered`, el código del último
     * error…), vale `stale` cuando la cifra viene de un éxito viejo y `partial`
     * cuando solo cubre una parte; también con `real_zero`, que sigue siendo un
     * cero comprobado. `complete` dice si el importe es de TODO el periodo.
     *
     * @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string,
     *     covered_to: ?string, reason: ?string, complete: bool, accounts?: list<array<string, mixed>>}
     */
    public function snapshot(CarbonInterface $from, CarbonInterface $to): array
    {
        if ($this->client->isBound()) {
            return $this->accountSnapshot($from, $to);
        }

        $problem = $this->client->configurationProblem();
        if ($problem !== null) {
            return $this->snapshotOf(self::NOT_CONFIGURED, null, null, null, null, $problem) + ['accounts' => []];
        }

        $accounts = $this->client->accountIds();
        $names = MetaAdEntity::accountNames($accounts);
        $parts = [];
        $public = [];
        foreach ($accounts as $account) {
            $part = $this->forAccount($account)->accountSnapshot($from, $to);
            $parts[] = $part;
            $public[] = MetaAdEntity::publicAccount($account, $names[$account] ?? null) + [
                'status' => $part['status'],
                'amount' => $part['amount'],
                'currency' => $part['currency'],
                'complete' => $part['complete'],
                'covered_to' => $part['covered_to'],
                'reason' => $part['reason'],
            ];
        }

        return $this->consolidate($parts, $accounts, $from, $to) + ['accounts' => $public];
    }

    /**
     * El gasto de todas las cuentas a partir del de cada una:
     *
     *  - gasto en monedas distintas → `unknown` / `mixed_currency`: no se suman
     *    (la moneda es solo la de quien gastó, {@see currencyOf()});
     *  - alguna sin cifra (sin datos que cubran el periodo, o un fallo sin nada
     *    bueno) → sin cifra, con su estado y su motivo: el total no se puede
     *    afirmar sin ella;
     *  - alguna parcial (`stale`/`sync_failed` con importe hasta un día) → TODO
     *    se corta en el día común D, el más temprano hasta el que llegan: el
     *    importe es lo de CADA cuenta hasta D (también el de una completa que
     *    siguió gastando después), `covered_to` = D, `complete = false` y el
     *    peor estado (`sync_failed` antes que `stale`). Las filas de
     *    {@see byLevel()} y de {@see accounts()} se cortan en el mismo día: así
     *    «hasta D» es verdad en la tarjeta, en la tabla y en las filas;
     *  - todas completas → la SUMA: `real_zero` si todas son cero comprobado,
     *    `stale` si alguna lo es, y si no `ok`.
     *
     * `last_synced_at` es el más viejo (null si alguna nunca sincronizó). Con
     * una sola cuenta, el resultado es el de esa cuenta tal cual: su día es D.
     *
     * @param  list<array<string, mixed>>  $parts  {@see accountSnapshot()} de cada cuenta, en el orden de `$accounts`
     * @param  list<string>  $accounts
     * @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string,
     *     covered_to: ?string, reason: ?string, complete: bool}
     */
    private function consolidate(array $parts, array $accounts, CarbonInterface $from, CarbonInterface $to): array
    {
        $synced = $this->oldest(array_map(fn (array $p): ?string => $p['last_synced_at'], $parts));
        $coveredTo = $this->earliest(array_map(fn (array $p): ?string => $p['covered_to'], $parts));
        $missing = array_values(array_filter($parts, fn (array $p): bool => $p['amount'] === null));
        $partials = array_values(array_filter($parts, fn (array $p): bool => $p['complete'] === false));

        // Con alguna parcial y ninguna sin cifra, D es el día más temprano hasta el que llegan, y lo
        // que se suma es lo de cada cuenta hasta D. (Una cuenta parcial tiene días que leer: el
        // periodo los tiene.)
        $days = $this->days($from, $to);
        $cut = is_array($days) && $missing === [] && $partials !== []
            ? $this->earliest(array_map(fn (array $p): ?string => $p['covered_to'], $partials))
            : null;
        $summed = $parts;
        if ($cut !== null) {
            $summed = [];
            foreach ($accounts as $account) {
                [$amount, $currency, $mixed] = $this->sum($account, $days[0], $cut);
                if ($mixed) {
                    return $this->snapshotOf(self::UNKNOWN, null, null, $synced, $cut, 'mixed_currency');
                }
                $summed[] = ['amount' => $amount, 'currency' => $currency];
            }
        }

        [$currency, $mixedCurrency] = $this->currencyOf($summed);
        if ($mixedCurrency) {
            return $this->snapshotOf(self::UNKNOWN, null, null, $synced, $coveredTo, 'mixed_currency');
        }

        // Una cuenta sin cifra: el total no se afirma sin ella. Un fallo antes que un «no cubierto».
        if ($missing !== []) {
            $culprit = $this->firstWithStatus($missing, self::SYNC_FAILED) ?? $missing[0];

            return $this->snapshotOf($culprit['status'], null, null, $synced, $culprit['covered_to'], $culprit['reason']);
        }

        $amount = round(array_sum(array_map(fn (array $p): float => (float) $p['amount'], $summed)), 2);

        if ($cut !== null) {
            $worst = $this->firstWithStatus($partials, self::SYNC_FAILED) ?? $partials[0];

            return $this->snapshotOf($worst['status'], $amount, $currency, $synced, $cut, $worst['reason'], false);
        }

        // Un cero comprobado viejo también es viejo: lo dice su `reason`.
        $stale = $this->firstWithStatus($parts, self::STALE) !== null
            || in_array('stale', array_map(fn (array $p): ?string => $p['reason'], $parts), true);

        if (abs($amount) < 0.005) {
            return $this->snapshotOf(self::REAL_ZERO, 0.0, $currency, $synced, $coveredTo, $stale ? 'stale' : null);
        }

        return $this->snapshotOf($stale ? self::STALE : self::OK, $amount, $currency, $synced, $coveredTo, $stale ? 'stale' : null);
    }

    /**
     * La moneda de una suma entre cuentas, y si mezcla monedas. Solo cuenta la
     * de las cuentas que GASTARON (|importe| ≥ 0,005):
     *
     *  - dos monedas entre ellas, o un gasto con moneda junto a otro sin ella
     *    (podría no ser la misma) → mezcla: no se suman;
     *  - un gasto sin moneda conocida y ninguno con ella → sin moneda (null),
     *    como con una sola cuenta: el panel no lo compara con la caja en pesos.
     *    No hereda la de una cuenta que no gastó;
     *  - nadie gastó → la de cualquiera que la tenga: un cero comprobado sigue
     *    diciendo en qué moneda.
     *
     * @param  list<array{amount: ?float, currency: ?string}>  $parts
     * @return array{0: ?string, 1: bool} la moneda y si mezcla
     */
    private function currencyOf(array $parts): array
    {
        $currencies = [];
        $withoutCurrency = false;
        foreach ($parts as $p) {
            if ($p['amount'] === null || abs((float) $p['amount']) < 0.005) {
                continue;
            }
            if ($p['currency'] === null) {
                $withoutCurrency = true;
            } else {
                $currencies[$p['currency']] = true;
            }
        }

        if (count($currencies) > 1 || ($withoutCurrency && $currencies !== [])) {
            return [null, true];
        }
        if ($withoutCurrency || $currencies !== []) {
            return [$withoutCurrency ? null : (string) array_key_first($currencies), false];
        }

        foreach ($parts as $p) {
            if ($p['currency'] !== null) {
                return [$p['currency'], false];
            }
        }

        return [null, false];
    }

    /**
     * El gasto de UNA cuenta, la de este lector (la ligada o la principal).
     *
     * @return array{status: string, amount: ?float, currency: ?string, last_synced_at: ?string,
     *     covered_to: ?string, reason: ?string, complete: bool}
     */
    private function accountSnapshot(CarbonInterface $from, CarbonInterface $to): array
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
     * Sin ligar, de TODAS las cuentas conectadas: `known` si lo es en todas,
     * `complete` si lo es en todas, y `through` el más temprano.
     *
     * @return array{first: ?string, last: ?string, through: ?string, known: bool, complete: bool}
     */
    public function coverage(CarbonInterface $from, CarbonInterface $to): array
    {
        if ($this->client->isBound()) {
            return $this->accountCoverage($from, $to);
        }

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
        $through = $last;
        foreach ($this->client->accountIds() as $account) {
            $c = $this->forAccount($account)->accountCoverage($from, $to);
            if (! $c['known']) {
                return ['first' => $first, 'last' => $last, 'through' => null, 'known' => false, 'complete' => false];
            }
            $through = min($through, (string) $c['through']);
        }

        return ['first' => $first, 'last' => $last, 'through' => $through, 'known' => true, 'complete' => $through === $last];
    }

    /**
     * {@see coverage()} de la cuenta de este lector.
     *
     * @return array{first: ?string, last: ?string, through: ?string, known: bool, complete: bool}
     */
    private function accountCoverage(CarbonInterface $from, CarbonInterface $to): array
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
     * frequency, `partial`, `last_day` y `account_id` (la cuenta, cruda: solo
     * para uso interno). Nombre, estado y jerarquía salen de la dimensión de la
     * cuenta de la fila, nunca de otra.
     *
     * Sin ligar, la UNIÓN de las filas de cada cuenta conectada, cada una con
     * los datos de su cuenta. Los `$include` (por ejemplo, las entidades a las
     * que llevan los leads) se reparten por su cuenta dueña
     * (`meta_ad_entities.ad_account_id`); una entidad sin cuenta conectada no
     * sale: el panel la trata como de una cuenta no conectada. Nunca dos filas
     * para la misma entidad. Si alguna cuenta es parcial, las de todas se
     * cortan en el día común, como el importe ({@see accountCoverages()}).
     *
     * Ligado, salen las entidades de la cuenta con actividad en el periodo y,
     * además, las de `$include` aunque no la tengan:
     *  - con alguna fila de gasto en la cuenta (de cualquier día) y el periodo
     *    cubierto, sus cifras son 0 comprobado;
     *  - sin NINGUNA fila de gasto en la cuenta (es de otra cuenta, o su gasto
     *    nunca se vio), sus cifras son null: no se sabe, y no es cero.
     *
     * Si los datos buenos de la cuenta llegan solo hasta un día del periodo, las
     * sumas son de esos días y `partial = true`. Sin ningún dato bueno desde el
     * primer día, o si la cuenta parte los días en otra zona que el CRM, las
     * cifras van en null. El alcance solo sale para una campaña con foto de Meta
     * de EXACTAMENTE ese periodo completo: no se suma por días.
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
        // Las mismas validaciones, antes que nada y también sin cuentas.
        $this->levelColumns($level, $parent);

        if ($this->client->isBound()) {
            return $this->accountByLevel($level, $from, $to, $include, $parent);
        }

        if ($this->client->configurationProblem() !== null) {
            return collect();
        }

        $accounts = $this->client->accountIds();
        $owners = $this->ownersOf($level, $include, $accounts);
        $coverages = $this->accountCoverages($from, $to, $accounts);

        $rows = collect();
        $seen = [];
        foreach ($accounts as $i => $account) {
            $mine = [];
            foreach ($owners as $entity => $owner) {
                if ($owner === $account) {
                    $mine[] = (string) $entity;
                }
            }

            foreach ($this->forAccount($account)->accountByLevel($level, $from, $to, $mine, $parent, $coverages[$i]) as $row) {
                // Los ids de Meta son globales; si una entidad saliera en dos cuentas,
                // la segunda contaría dos veces lo mismo: se queda la primera y se avisa.
                if (isset($seen[$row['id']])) {
                    ChannelLog::warning('meta.ads.entity_in_two_accounts', ['level' => $level]);

                    continue;
                }
                $seen[$row['id']] = true;
                $rows->push($row);
            }
        }

        return $rows->sort($this->bySpend(...))->values();
    }

    /**
     * {@see byLevel()} de la cuenta de este lector.
     *
     * @param  iterable<int|string>  $include
     * @param  array{level: string, id: string}|null  $parent
     * @param  array<string, mixed>|null  $coverage  la de la cuenta, ya cortada en el día común ({@see accountCoverages()}); null = la suya
     * @return Collection<int, array<string, mixed>>
     */
    private function accountByLevel(
        string $level,
        CarbonInterface $from,
        CarbonInterface $to,
        iterable $include = [],
        ?array $parent = null,
        ?array $coverage = null,
    ): Collection {
        [$column, $nameColumn, $parentColumn] = $this->levelColumns($level, $parent);

        $account = $this->client->accountId();
        if ($account === null || $this->client->configurationProblem() !== null) {
            return collect();
        }

        $cov = $coverage ?? $this->accountCoverage($from, $to);
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
                // El último día con actividad en el periodo: el panel lo cruza con la cobertura del CRM.
                ->selectRaw('MAX(date) as last_day')
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
            ->where('ad_account_id', $account)
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

        $spent = $groups->values()->map(function (object $g) use ($entities, $known, $partial, $reach, $parentIds, $account): array {
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
                'last_day' => $g->last_day === null ? null : substr((string) $g->last_day, 0, 10),
                'account_id' => $account,
            ];
        });

        $idle = $missing->map(function (string $id) use ($entities, $known, $partial, $ofAccount, $currency, $reach, $parentIds, $account): array {
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
                'last_day' => null,
                'account_id' => $account,
            ];
        });

        return $spent->concat($idle)->sort($this->bySpend(...))->values();
    }

    /**
     * El orden de las filas: de mayor a menor gasto, y un gasto que no se sabe
     * detrás de un cero comprobado; a igual gasto, por nombre.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function bySpend(array $a, array $b): int
    {
        return [$b['spend'] ?? -1, (string) $a['name']] <=> [$a['spend'] ?? -1, (string) $b['name']];
    }

    /**
     * Las columnas de un nivel y de su padre. Salen de aquí, nunca de la
     * petición: van tal cual en el SQL.
     *
     * @param  array{level: string, id: string}|null  $parent
     * @return array{0: string, 1: string, 2: ?string}
     *
     * @throws InvalidArgumentException con un nivel o un padre desconocidos
     */
    private function levelColumns(string $level, ?array $parent): array
    {
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

        return [$column, $nameColumn, $parentColumn];
    }

    /**
     * De qué cuenta conectada es cada entidad pedida, según la dimensión. Las
     * que no son de ninguna no salen.
     *
     * @param  iterable<int|string>  $include
     * @param  list<string>  $accounts
     * @return array<string, string> entidad → cuenta
     */
    private function ownersOf(string $level, iterable $include, array $accounts): array
    {
        $ids = collect($include)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($ids === [] || $accounts === []) {
            return [];
        }

        $rank = array_flip($accounts);
        $owners = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = MetaAdEntity::query()
                ->where('level', $level)
                ->whereIn('ad_account_id', $accounts)
                ->whereIn('entity_id', $chunk)
                ->get(['ad_account_id', 'entity_id']);

            foreach ($found as $e) {
                $entity = (string) $e->entity_id;
                $account = (string) $e->ad_account_id;
                // Si saliera en dos cuentas, manda la primera en el orden de las conectadas.
                if (! isset($owners[$entity]) || $rank[$account] < $rank[$owners[$entity]]) {
                    $owners[$entity] = $account;
                }
            }
        }

        return $owners;
    }

    /**
     * Alcance, impresiones y frecuencia de la CUENTA en exactamente `[first,
     * last]` (días de la cuenta), si Meta los dio para ese rango. Null si no hay
     * foto de ese rango: el alcance no se suma por días.
     *
     * Sin ligar, de todas las conectadas, y el alcance (personas únicas) TAMPOCO
     * se suma entre cuentas: si en el rango tuvo impresiones (según la base)
     * exactamente una cuenta, es su foto; si ninguna o varias, null.
     *
     * @return array{reach: int, impressions: int, frequency: ?float, synced_at: ?string}|null
     */
    public function accountReach(string $first, string $last): ?array
    {
        if ($this->client->isBound()) {
            return $this->reachOf((string) $this->client->accountId(), $first, $last);
        }

        $accounts = $this->client->accountIds();
        if ($accounts === []) {
            return null;
        }

        $active = DB::table('meta_ad_insights_daily')
            ->whereIn('ad_account_id', $accounts)
            ->whereBetween('date', [$first, $last])
            ->where('impressions', '>', 0)
            ->distinct()
            ->pluck('ad_account_id')
            ->all();

        return count($active) === 1 ? $this->reachOf((string) $active[0], $first, $last) : null;
    }

    /**
     * La foto de alcance de una cuenta para exactamente `[first, last]`, o null.
     *
     * @return array{reach: int, impressions: int, frequency: ?float, synced_at: ?string}|null
     */
    private function reachOf(string $account, string $first, string $last): ?array
    {
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
     * ¿Reporta Meta estas métricas? Una métrica que no aparece en ninguna fila
     * (todas a 0) no se enseña como 0, sino como «no reportada»: puede ser que
     * la cuenta no la tenga o que Meta la llame de otra forma, y en los dos
     * casos un 0 sería inventado.
     *
     * De la cuenta ligada o, sin ligar, de cualquiera de las conectadas: una
     * métrica existe si alguna cuenta la reporta. Para las filas de cada cuenta,
     * {@see availabilityByAccount()}.
     *
     * @return array{messaging_started: bool, messaging_replied: bool, landing_page_views: bool, reach: bool}
     */
    public function metricsAvailable(): array
    {
        $out = ['messaging_started' => false, 'messaging_replied' => false, 'landing_page_views' => false, 'reach' => false];
        foreach ($this->availabilityByAccount() as $flags) {
            foreach ($flags as $metric => $reported) {
                $out[$metric] = $out[$metric] || $reported;
            }
        }

        return $out;
    }

    /**
     * {@see metricsAvailable()} de cada cuenta (la ligada, o todas las
     * conectadas): dos consultas, no una por cuenta.
     *
     * @return array<string, array{messaging_started: bool, messaging_replied: bool, landing_page_views: bool, reach: bool}>
     */
    public function availabilityByAccount(): array
    {
        if ($this->availability !== null) {
            return $this->availability;
        }

        $accounts = $this->client->configurationProblem() !== null
            ? []
            : ($this->client->isBound() ? [(string) $this->client->accountId()] : $this->client->accountIds());
        if ($accounts === []) {
            return $this->availability = [];
        }

        $max = DB::table('meta_ad_insights_daily')
            ->whereIn('ad_account_id', $accounts)
            ->groupBy('ad_account_id')
            ->selectRaw('ad_account_id, MAX(messaging_conversations_started) as started, MAX(messaging_conversations_replied) as replied, MAX(landing_page_views) as lpv')
            ->get()
            ->keyBy(fn (object $r): string => (string) $r->ad_account_id);
        $withReach = DB::table('meta_ad_reach_snapshots')
            ->whereIn('ad_account_id', $accounts)
            ->distinct()
            ->pluck('ad_account_id')
            ->map(fn (mixed $a): string => (string) $a)
            ->all();

        $out = [];
        foreach ($accounts as $account) {
            $m = $max->get($account);
            $out[$account] = [
                'messaging_started' => (int) ($m->started ?? 0) > 0,
                'messaging_replied' => (int) ($m->replied ?? 0) > 0,
                'landing_page_views' => (int) ($m->lpv ?? 0) > 0,
                'reach' => in_array($account, $withReach, true),
            ];
        }

        return $this->availability = $out;
    }

    /**
     * Una fila por cuenta conectada (o solo la ligada) con las sumas del
     * periodo: spend, currency, impressions, clicks, link_clicks,
     * messaging_started, messaging_replied, landing_page_views, `partial`,
     * `covered_to`, `last_day` (el último día con actividad) y `reach` (solo la
     * foto EXACTA de esa cuenta para ese periodo). Más `account` (crudo, para uso
     * interno) y `name` (null si aún no se leyó).
     *
     * Con las reglas de {@see byLevel()}: una cuenta sin actividad en un periodo
     * que su cobertura cubre da ceros comprobados; sin cobertura, null. Los datos
     * parciales, de los días cubiertos y con `partial = true`; si alguna cuenta
     * es parcial, las de todas se cortan en el día común, como el importe
     * ({@see accountCoverages()}).
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(CarbonInterface $from, CarbonInterface $to): array
    {
        if ($this->client->configurationProblem() !== null) {
            return [];
        }

        $bound = $this->client->isBound();
        $accounts = $bound ? [(string) $this->client->accountId()] : $this->client->accountIds();
        $names = MetaAdEntity::accountNames($accounts);
        $coverages = $bound ? [] : $this->accountCoverages($from, $to, $accounts);

        $out = [];
        foreach ($accounts as $i => $account) {
            $out[] = ['account' => $account, 'name' => $names[$account] ?? null]
                + $this->forAccount($account)->accountTotals($from, $to, $coverages[$i] ?? null);
        }

        return $out;
    }

    /**
     * La cobertura del periodo de cada cuenta ({@see accountCoverage()}), en el
     * orden de `$accounts`, cortada en el día común D: si todas tienen datos
     * desde el primer día y alguna solo llega hasta un día anterior al último,
     * ninguna pasa del más temprano de esos días. Es el corte del importe
     * ({@see consolidate()}): las filas de una cuenta completa no traen días
     * que otra no cubre. Con alguna sin datos (el total ni se afirma) o todas
     * completas, cada una con la suya.
     *
     * @param  list<string>  $accounts
     * @return list<array{first: ?string, last: ?string, through: ?string, known: bool, complete: bool}>
     */
    private function accountCoverages(CarbonInterface $from, CarbonInterface $to, array $accounts): array
    {
        $coverages = array_map(fn (string $account): array => $this->forAccount($account)->accountCoverage($from, $to), $accounts);

        foreach ($coverages as $c) {
            if (! $c['known']) {
                return $coverages;
            }
        }

        $day = $coverages === [] ? null : min(array_column($coverages, 'through'));

        return array_map(
            fn (array $c): array => $day !== null && $c['through'] > $day ? array_merge($c, ['through' => $day, 'complete' => false]) : $c,
            $coverages,
        );
    }

    /**
     * Las sumas del periodo de la cuenta de este lector ({@see accounts()}).
     *
     * @param  array<string, mixed>|null  $coverage  la de la cuenta, ya cortada en el día común; null = la suya
     * @return array<string, mixed>
     */
    private function accountTotals(CarbonInterface $from, CarbonInterface $to, ?array $coverage = null): array
    {
        $account = (string) $this->client->accountId();
        $cov = $coverage ?? $this->accountCoverage($from, $to);
        $known = $cov['known'];
        $partial = $known && ! $cov['complete'];

        $totals = [
            'spend' => null, 'currency' => null, 'impressions' => null, 'clicks' => null, 'link_clicks' => null,
            'messaging_started' => null, 'messaging_replied' => null, 'landing_page_views' => null, 'reach' => null,
            'partial' => $partial, 'covered_to' => $partial ? $cov['through'] : null, 'last_day' => null,
        ];

        if ($cov['first'] === null) {
            return $totals;
        }

        // Con datos buenos, solo los días cubiertos; sin ellos, el periodo entero,
        // solo para saber hasta cuándo hubo actividad (las cifras van en null).
        $lastDay = $known ? $cov['through'] : $cov['last'];
        $base = fn () => DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->whereBetween('date', [$cov['first'], $lastDay]);

        $sums = $base()
            ->selectRaw('SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->selectRaw('SUM(inline_link_clicks) as link_clicks, SUM(landing_page_views) as landing_page_views')
            ->selectRaw('SUM(messaging_conversations_started) as messaging_started, SUM(messaging_conversations_replied) as messaging_replied')
            ->selectRaw('MAX(date) as last_day')
            ->first();
        $totals['last_day'] = $sums?->last_day === null ? null : substr((string) $sums->last_day, 0, 10);

        if (! $known) {
            return $totals;
        }

        // Sin filas en los días cubiertos: cero comprobado, como byLevel().
        $count = fn (mixed $v): int => (int) ($v ?? 0);
        foreach (['impressions', 'clicks', 'link_clicks', 'messaging_started', 'messaging_replied', 'landing_page_views'] as $metric) {
            $totals[$metric] = $count($sums?->{$metric});
        }

        $currencies = $base()->whereNotNull('currency')->distinct()->pluck('currency')->all();
        if (count($currencies) <= 1) {
            // Dos monedas no se suman: el gasto de la cuenta, sin cifra.
            $totals['spend'] = round((float) ($sums?->spend ?? 0), 2);
            $totals['currency'] = $currencies[0] ?? $this->lastKnownCurrency($account);
        }

        if ($cov['complete']) {
            $totals['reach'] = $this->reachOf($account, (string) $cov['first'], (string) $cov['last'])['reach'] ?? null;
        }

        return $totals;
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

    /**
     * El instante más viejo de la lista (ISO 8601), o null si alguno lo es: lo
     * que nunca pasó es más viejo que cualquier fecha.
     *
     * @param  list<?string>  $instants
     */
    private function oldest(array $instants): ?string
    {
        $oldest = null;
        foreach ($instants as $at) {
            if ($at === null) {
                return null;
            }
            if ($oldest === null || CarbonImmutable::parse($at)->lessThan(CarbonImmutable::parse($oldest))) {
                $oldest = $at;
            }
        }

        return $oldest;
    }

    /**
     * El día más temprano de la lista ('Y-m-d'), sin contar los null.
     *
     * @param  list<?string>  $days
     */
    private function earliest(array $days): ?string
    {
        $known = array_filter($days, fn (?string $d): bool => $d !== null);

        return $known === [] ? null : min($known);
    }

    /**
     * La primera lectura de la lista con ese estado, o null.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>|null
     */
    private function firstWithStatus(array $parts, string $status): ?array
    {
        foreach ($parts as $p) {
            if ($p['status'] === $status) {
                return $p;
            }
        }

        return null;
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
