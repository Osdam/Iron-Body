<?php

namespace App\Services\Marketing\Meta;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaAdReachSnapshot;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
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
 *
 * MULTICUENTA: una pasada es de UNA cuenta, la del cliente (la ligada con
 * {@see forAccount()} o, sin ligar, la principal), con su cerrojo, su
 * transacción y su fila en `meta_sync_runs`. Lo que falla en una cuenta no toca
 * los datos de las demás: todo lo que se escribe o se borra va acotado a la
 * cuenta de la pasada. {@see runAll()} y {@see statusAll()} recorren todas.
 */
class MetaAdsSync
{
    /** Prefijo del cerrojo: hay uno por cuenta ({@see lockKey()}). */
    public const LOCK_KEY = 'meta-ads-sync';

    /** Lo máximo que se espera de una pasada, y el plazo para darla por muerta. */
    public const LOCK_SECONDS = 900;

    /** Una cuenta que {@see runAll()} no pidió en esa vuelta (motivo en `reason`). */
    public const STATUS_SKIPPED = 'skipped';

    /** La vuelta de {@see runAll()} terminó bien en unas cuentas y no en otras. */
    public const STATUS_PARTIAL = 'partial';

    /** {@see refreshReach()} no pidió nada: la cuenta no tuvo impresiones en {@see REACH_ACTIVE_DAYS} días. */
    public const REACH_SKIPPED_INACTIVE = 'skipped_inactive';

    /**
     * Días hacia atrás que miran los rangos de alcance del panel: el mes
     * anterior entero empieza como mucho 61 días antes de hoy (31 de agosto →
     * 1 de julio). Sin impresiones en ellos, el alcance de esos rangos es 0 y
     * pedirlo solo gastaría cupo.
     */
    public const REACH_ACTIVE_DAYS = 62;

    /** Horas que vale el nombre guardado de una cuenta antes de volver a pedirlo. */
    public const ACCOUNT_PROFILE_HOURS = 24;

    /** Filas por sentencia: lejos del límite de parámetros de PostgreSQL y de SQLite. */
    private const CHUNK = 200;

    /**
     * El primer día que se sincroniza [DECISIÓN DEL USUARIO]: nada de antes de
     * 2026, venga del relleno, del comando manual o de quien sea.
     */
    public const MIN_DATE = '2026-01-01';

    /**
     * Los `action_type` de Meta que se guardan en columnas propias. Lo demás se
     * conserva en `actions` sin interpretar. Si Meta no reporta uno de ellos
     * para la cuenta, la columna queda en 0 y el panel lo trata como «no
     * reportado», nunca como cero ({@see MetaSpendReader::metricsAvailable()}).
     */
    public const ACTION_MESSAGING_STARTED = 'onsite_conversion.messaging_conversation_started_7d';

    public const ACTION_MESSAGING_REPLIED = 'onsite_conversion.messaging_conversation_replied_7d';

    public const ACTION_LANDING_PAGE_VIEW = 'landing_page_view';

    /** Días que se conserva una foto de alcance después de su último día. */
    private const REACH_RETENTION_DAYS = 120;

    /**
     * Meta contestó «límite» (17/2446079…) a una petición accesoria de esta
     * pasada: no se le pide nada más (ni los otros bordes de estados ni el
     * alcance). La app tiene un cupo corto, y seguir pidiendo lo alarga.
     */
    private bool $limited = false;

    public function __construct(private readonly MetaAdsApiClient $client) {}

    /**
     * La misma sincronización, ligada a UNA cuenta conectada: sus pasadas, su
     * cerrojo, su estado, su cobertura, su alcance y sus estados.
     *
     * @throws InvalidArgumentException si la cuenta no está entre las conectadas
     */
    public function forAccount(string $id): self
    {
        return new self($this->client->forAccount($id));
    }

    /** Las cuentas conectadas, en orden ({@see MetaAdsApiClient::accountIds()}). */
    public function accountIds(): array
    {
        return $this->client->accountIds();
    }

    /**
     * El cerrojo de la cuenta de esta sincronización: una pasada de una cuenta
     * no bloquea la de otra (cada una tiene su transacción y sus filas).
     */
    public function lockKey(): string
    {
        return self::LOCK_KEY.':'.($this->client->accountId() ?? 'none');
    }

    /**
     * ¿Contestó Meta «límite» a alguna petición de la última pasada (o del
     * alcance) de esta instancia? El cupo es de la app, compartido por todas las
     * cuentas: quien recorre varias deja de pedir.
     */
    public function limited(): bool
    {
        return $this->limited;
    }

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
        $this->limited = false;

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);
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
     * Una vuelta por TODAS las cuentas conectadas (o por `$only`), en orden:
     * cada una en su propia pasada ({@see run()}), con su transacción y su fila
     * en `meta_sync_runs`.
     *
     *  - Si una cuenta falla, las demás siguen: sus datos no dependen de ella.
     *  - Si Meta contesta «límite» en una, las siguientes NO se piden en esta
     *    vuelta (el cupo es de la app y lo comparten todas) y quedan `skipped`
     *    con motivo `rate_limit`.
     *
     * Devuelve `status` (ok | partial | failed | running | not_configured) y,
     * por cuenta, el resultado de su pasada (más `limited`, el de sus peticiones
     * accesorias) o el del salto. Si la vuelta llegó a pedir algo, `limited`
     * dice si Meta contestó «límite» en ALGUNA cuenta, también en la petición
     * principal de la última, donde ya no queda nadie a quien saltar: quien
     * llama no debe pedir nada más en esa vuelta (el alcance, por ejemplo).
     *
     * @param  list<string>|null  $only  un subconjunto de las conectadas; null = todas
     * @return array{status: string, reason?: string, limited?: bool, accounts: array<string, array<string, mixed>>}
     *
     * @throws InvalidArgumentException con un disparador o un rango que no valen,
     *                                  o una cuenta de `$only` que no está conectada
     */
    public function runAll(
        string $trigger = 'schedule',
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?array $only = null,
    ): array {
        if (! in_array($trigger, MetaSyncRun::TRIGGERS, true)) {
            throw new InvalidArgumentException("Disparador de sincronización desconocido: {$trigger}");
        }

        $problem = $this->client->configurationProblem();
        if ($problem !== null) {
            // La pasada de siempre deja constancia del motivo, sin llamar a Meta.
            $this->run($trigger, $from, $to);

            return ['status' => MetaSyncRun::STATUS_NOT_CONFIGURED, 'reason' => $problem, 'accounts' => []];
        }

        // Antes de pedir nada: un rango que no vale no vale para ninguna cuenta.
        $this->range($from, $to);

        // Cuentas que no están conectadas, también antes: no se deja una vuelta a medias.
        // Pares [cuenta, sincronización]: una clave numérica de PHP dejaría de ser texto.
        $syncs = [];
        foreach ($only ?? $this->client->accountIds() as $account) {
            $sync = $this->forAccount((string) $account);
            $syncs[] = [(string) $sync->client->accountId(), $sync];
        }

        $results = [];
        $limited = false;
        foreach ($syncs as [$account, $sync]) {
            if (array_key_exists($account, $results)) {
                continue;
            }

            if ($limited) {
                $results[$account] = ['account' => $account, 'status' => self::STATUS_SKIPPED, 'reason' => MetaAdsApiException::RATE_LIMIT];

                continue;
            }

            try {
                $result = $sync->run($trigger, $from, $to);
            } catch (Throwable $e) {
                // Un fallo NUESTRO en esta cuenta no tumba la vuelta de las demás.
                // No se esconde: va al log del canal con su detalle saneado (y, si
                // llegó a abrir la pasada, run() ya dejó su fila como `internal`).
                ChannelLog::error('meta.ads.sync.account_crashed', [
                    'account' => LeadSourceClassifier::maskId($account),
                    'trigger' => $trigger,
                    'exception' => class_basename($e),
                    'error_message' => MetaAdsApiClient::sanitize($e->getMessage(), 1000),
                ]);
                $result = [
                    'status' => MetaSyncRun::STATUS_FAILED,
                    'error_code' => MetaSyncRun::ERROR_INTERNAL,
                    'error_message' => MetaSyncRun::INTERNAL_ERROR_MESSAGE,
                    'retryable' => false,
                ];
            }

            // Un límite en la pasada, o en una petición accesoria de ella, es del cupo de la app.
            $limited = $sync->limited()
                || (($result['status'] ?? null) === MetaSyncRun::STATUS_FAILED && ($result['error_code'] ?? null) === MetaAdsApiException::RATE_LIMIT);
            $results[$account] = ['account' => $account] + $result + ['limited' => $sync->limited()];
        }

        // Una vez en true no vuelve a false: las cuentas de después se saltaron sin pedir nada.
        return ['status' => $this->overall(array_column($results, 'status')), 'limited' => $limited, 'accounts' => $results];
    }

    /**
     * Cómo está la sincronización de la cuenta de esta instancia (la ligada o la
     * principal). El de todas, consolidado, es {@see statusAll()}.
     *
     * `status`:
     *  - not_configured: falta el token o la cuenta (`reason` dice qué).
     *  - running: hay una pasada en curso (también un tramo del relleno).
     *  - failed: el último intento falló (`last_error`), aunque quede una foto
     *    anterior buena.
     *  - stale: el último éxito es más viejo que `stale_after_minutes`, o todavía
     *    no ha habido ninguno.
     *  - ok: lo demás.
     *
     * «Intento» y «éxito» son los de las pasadas que mantienen el panel al día:
     * las que llegan hasta el día en que corren (la de cada hora y la del
     * botón). Un tramo del relleno, o una pasada manual de días viejos (un
     * re-sync de enero), trae cobertura del pasado: ni dice si lo de hoy está al
     * día ni su fallo es el de esas pasadas. Cualquier pasada en curso sí cuenta,
     * porque ocupa la sincronización.
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

        // Llega hasta el día (de la cuenta) en que corrió: es de las que mantienen el panel al día.
        $tz = $this->client->timezone();
        $current = fn (MetaSyncRun $run): bool => $run->range_to === null || $run->started_at === null
            || (string) $run->range_to >= $run->started_at->copy()->setTimezone($tz)->toDateString();

        $recent = $runs()
            ->where('trigger', '<>', MetaSyncRun::TRIGGER_BACKFILL)
            ->whereIn('status', [MetaSyncRun::STATUS_RUNNING, MetaSyncRun::STATUS_OK, MetaSyncRun::STATUS_FAILED])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter($current)
            ->values();
        $lastSuccess = $recent->firstWhere('status', MetaSyncRun::STATUS_OK);
        $lastAttempt = $recent->first();
        $inFlight = $runs()->where('status', MetaSyncRun::STATUS_RUNNING)->orderByDesc('id')->first();

        $alive = fn (?MetaSyncRun $run): bool => $run?->status === MetaSyncRun::STATUS_RUNNING
            && $run->started_at !== null
            && $run->started_at->greaterThan(now()->subSeconds(self::LOCK_SECONDS));

        $running = $alive($lastAttempt) || $alive($inFlight);
        $lastError = null;

        if ($lastAttempt?->status === MetaSyncRun::STATUS_RUNNING) {
            if (! $alive($lastAttempt)) {
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
     * El estado de TODAS las cuentas conectadas, con las mismas claves que
     * {@see status()} y el detalle por cuenta en `accounts`:
     *
     *  - status: `not_configured` sin token o sin ninguna cuenta; si no, el peor
     *    de las cuentas (running > failed > stale > ok).
     *  - last_success_at y minutes_since_success: los del éxito MÁS VIEJO (null
     *    si alguna cuenta nunca lo tuvo): «al día» solo si lo están todas.
     *  - last_attempt_at: el intento más reciente de cualquiera.
     *  - last_error: el de una cuenta fallida (la primera en orden), con su
     *    `account_id` enmascarado.
     *  - covered_from y covered_to: la intersección de los tramos de cada
     *    cuenta (el `from` más tardío y el `to` más temprano), o null.
     *  - accounts: por cuenta, `id` opaco, `ref` («***» y cuatro dígitos),
     *    `name` (null si aún no se leyó) y su estado, con su último intento
     *    (`last_attempt_at`, ISO como el consolidado): así se ve qué cuenta ya
     *    corrió de las que despachó el abanico.
     *
     * @return array<string, mixed>
     */
    public function statusAll(): array
    {
        $problem = $this->client->configurationProblem();
        $accounts = $this->client->accountIds();
        $names = MetaAdEntity::accountNames($accounts);

        $states = [];
        foreach ($accounts as $account) {
            $states[$account] = $this->forAccount($account)->status();
        }

        $rows = [];
        $lastError = null;
        foreach ($accounts as $account) {
            $s = $states[$account];
            $rows[] = MetaAdEntity::publicAccount($account, $names[$account] ?? null) + [
                'status' => $s['status'],
                'last_success_at' => $s['last_success_at'],
                'last_attempt_at' => $s['last_attempt_at'],
                'minutes_since_success' => $s['minutes_since_success'],
                'last_error' => $s['last_error'],
                'covered_from' => $s['covered_from'],
                'covered_to' => $s['covered_to'],
            ];
            if ($lastError === null && $s['status'] === MetaSyncRun::STATUS_FAILED && $s['last_error'] !== null) {
                $lastError = $s['last_error'] + ['account_id' => LeadSourceClassifier::maskId($account)];
            }
        }

        $successes = array_column($states, 'last_success_at');
        $everySucceeded = $states !== [] && ! in_array(null, $successes, true);
        $oldest = $everySucceeded ? $this->oldestState($states) : null;

        $attempts = array_filter(array_column($states, 'last_attempt_at'), fn (?string $at): bool => $at !== null);
        $lastAttempt = $attempts === [] ? null : array_reduce(
            $attempts,
            fn (?string $carry, string $at): string => $carry === null || CarbonImmutable::parse($at)->greaterThan(CarbonImmutable::parse($carry)) ? $at : $carry,
        );

        [$coveredFrom, $coveredTo] = $this->intersection($states);

        $statuses = array_column($states, 'status');
        $status = match (true) {
            $problem !== null || $states === [] => MetaSyncRun::STATUS_NOT_CONFIGURED,
            in_array(MetaSyncRun::STATUS_RUNNING, $statuses, true) => MetaSyncRun::STATUS_RUNNING,
            in_array(MetaSyncRun::STATUS_FAILED, $statuses, true) => MetaSyncRun::STATUS_FAILED,
            in_array('stale', $statuses, true) => 'stale',
            default => MetaSyncRun::STATUS_OK,
        };

        return [
            'status' => $status,
            'configured' => $problem === null && $states !== [],
            'reason' => $problem ?? ($states === [] ? 'missing_ad_account_id' : null),
            'last_success_at' => $oldest['last_success_at'] ?? null,
            'last_attempt_at' => $lastAttempt,
            'minutes_since_success' => $oldest['minutes_since_success'] ?? null,
            'last_error' => $lastError,
            'covered_from' => $coveredFrom,
            'covered_to' => $coveredTo,
            'accounts' => $rows,
        ];
    }

    /**
     * Días de la cuenta de esta sincronización cubiertos por pasadas CON ÉXITO,
     * fundidos en tramos continuos y ordenados. Es lo que separa «cero» de «no
     * se sabe». Cada cuenta tiene los suyos: los de una no cubren a otra.
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

    /**
     * Hasta qué día de `[from, to]` hay datos buenos SIN HUECOS desde `from`: el
     * final del tramo cubierto que contiene `from`, sin pasar de `to`. Null si
     * `from` no está cubierto. Es lo que permite decir «datos hasta el 4» cuando
     * la última pasada falló, en vez de enseñar un guion o, peor, un cero.
     */
    public function coveredThrough(string $from, string $to): ?string
    {
        foreach ($this->coverage() as [$start, $end]) {
            if ($start <= $from && $from <= $end) {
                return min($end, $to);
            }
        }

        return null;
    }

    /**
     * Fotos de ALCANCE de los rangos que el panel ofrece (hoy, 7 días, 30 días,
     * este mes y el mes anterior), de la cuenta y de cada campaña, tal como las
     * calcula Meta. El alcance no se suma por días: un rango que no está aquí
     * enseña el alcance como «—».
     *
     * Accesorio: cada rango se pide y se guarda por separado, y un fallo se anota
     * en el log del canal sin tocar las fotos que ya hay ni la pasada de gasto.
     * Nunca lanza.
     *
     * Es de la cuenta de esta sincronización. Una cuenta sin impresiones en los
     * últimos {@see REACH_ACTIVE_DAYS} días no se pide (`skipped_inactive`): su
     * alcance en esos rangos es 0 y pedirlo gastaría el cupo de la app, que
     * comparten todas. Tampoco se le escribe un 0: sin foto, el panel dice «—».
     *
     * @return array{status: string, ranges_ok: int, ranges_failed: int, rows: int}
     */
    public function refreshReach(): array
    {
        if ($this->client->configurationProblem() !== null) {
            return ['status' => MetaSyncRun::STATUS_NOT_CONFIGURED, 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0];
        }

        if ($this->limited) {
            ChannelLog::warning('meta.ads.reach.skipped', ['error_code' => MetaAdsApiException::RATE_LIMIT, 'account' => $this->accountRef()]);

            return ['status' => MetaAdsApiException::RATE_LIMIT, 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0];
        }

        $account = (string) $this->client->accountId();

        try {
            $active = $this->hadRecentImpressions($account);
        } catch (Throwable $e) {
            // Sin poder leer la base no se le pide nada a Meta; nunca lanza, pero no se esconde.
            ChannelLog::error('meta.ads.reach.crashed', [
                'account' => $this->accountRef(),
                'exception' => class_basename($e),
                'error_message' => MetaAdsApiClient::sanitize($e->getMessage(), 1000),
            ]);

            return ['status' => MetaSyncRun::STATUS_FAILED, 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0];
        }

        if (! $active) {
            ChannelLog::info('meta.ads.reach.skipped_inactive', ['account' => $this->accountRef(), 'days' => self::REACH_ACTIVE_DAYS]);
            $this->pruneReach($account);

            return ['status' => self::REACH_SKIPPED_INACTIVE, 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0];
        }

        $ok = 0;
        $failed = 0;
        $written = 0;

        foreach ($this->reachRanges() as [$since, $until]) {
            if ($this->limited) {
                $failed++;

                continue;
            }
            try {
                $accountRow = $this->client->reach('account', $since, $until)[0] ?? null;
                $campaignRows = $this->client->reach('campaign', $since, $until);

                $written += DB::transaction(fn (): int => $this->writeReach($account, $since, $until, $accountRow, $campaignRows));
                $ok++;
            } catch (MetaAdsApiException $e) {
                $failed++;
                $this->limited = $this->limited || $e->category === MetaAdsApiException::RATE_LIMIT;
                ChannelLog::warning('meta.ads.reach.skipped', [
                    'account' => $this->accountRef(),
                    'since' => $since,
                    'until' => $until,
                    'error_code' => $e->category,
                    'http_status' => $e->httpStatus,
                    'meta_code' => $e->metaCode,
                ]);
            } catch (Throwable $e) {
                // Un fallo nuestro (la base, un bug) no tumba el gasto, pero no se
                // esconde: va como error al log del canal, con su detalle saneado.
                $failed++;
                ChannelLog::error('meta.ads.reach.crashed', [
                    'account' => $this->accountRef(),
                    'since' => $since,
                    'until' => $until,
                    'exception' => class_basename($e),
                    'error_message' => MetaAdsApiClient::sanitize($e->getMessage(), 1000),
                ]);
            }
        }

        $this->pruneReach($account);

        return [
            'status' => $failed === 0 ? MetaSyncRun::STATUS_OK : MetaSyncRun::STATUS_FAILED,
            'ranges_ok' => $ok,
            'ranges_failed' => $failed,
            'rows' => $written,
        ];
    }

    /**
     * Los rangos de alcance que se guardan, en días de la cuenta: los mismos que
     * calcula MetaDashboardService::period() para cada botón del panel.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function reachRanges(): array
    {
        $today = CarbonImmutable::now($this->client->timezone())->startOfDay();
        $previousMonth = $today->startOfMonth()->subMonthNoOverflow();

        $ranges = [
            [$today, $today],
            [$today->subDays(6), $today],
            [$today->subDays(29), $today],
            [$today->startOfMonth(), $today],
            [$previousMonth, $previousMonth->endOfMonth()->startOfDay()],
        ];

        $out = [];
        foreach ($ranges as [$from, $to]) {
            $out[$from->toDateString().'|'.$to->toDateString()] = [$from->toDateString(), $to->toDateString()];
        }

        return array_values($out);
    }

    /** ¿Tuvo la cuenta alguna impresión en los últimos {@see REACH_ACTIVE_DAYS} días (de la base)? */
    private function hadRecentImpressions(string $account): bool
    {
        return DB::table('meta_ad_insights_daily')
            ->where('ad_account_id', $account)
            ->where('date', '>=', CarbonImmutable::now($this->client->timezone())->subDays(self::REACH_ACTIVE_DAYS)->toDateString())
            ->where('impressions', '>', 0)
            ->exists();
    }

    /** Borra las fotos de alcance de la cuenta que ya no sirven a ningún rango del panel. Nunca lanza. */
    private function pruneReach(string $account): void
    {
        try {
            DB::table('meta_ad_reach_snapshots')
                ->where('ad_account_id', $account)
                ->where('date_to', '<', CarbonImmutable::now($this->client->timezone())->subDays(self::REACH_RETENTION_DAYS)->toDateString())
                ->delete();
        } catch (Throwable $e) {
            ChannelLog::warning('meta.ads.reach.prune_skipped', ['exception' => class_basename($e), 'account' => $this->accountRef()]);
        }
    }

    /** La cuenta de esta sincronización como va a los logs: «***» y sus cuatro últimos dígitos. */
    private function accountRef(): ?string
    {
        return LeadSourceClassifier::maskId($this->client->accountId());
    }

    /** @return array<string,mixed> */
    private function runLocked(string $trigger, string $since, string $until): array
    {
        $account = (string) $this->client->accountId();

        // Con el cerrojo de la cuenta en la mano, cualquier fila `running` suya es
        // de una pasada que murió sin cerrarla. Se cierra para que el estado no
        // diga «en curso».
        $this->closeInterrupted($account);

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
            // Los estados son de toda la cuenta: un tramo del relleno no los pide
            // (la app tiene un cupo de peticiones corto); el relleno los refresca
            // una vez al final con refreshStatuses().
            $backfill = $trigger === MetaSyncRun::TRIGGER_BACKFILL;
            $campaigns = $backfill ? null : $this->campaignStatuses();
            $statuses = $backfill ? [] : $this->entityStatuses();
            // El nombre de la cuenta, una vez al día como mucho: accesorio, como los estados.
            $profile = $this->accountProfile($account);

            $deleted = DB::transaction(function () use ($run, $rows, $campaigns, $statuses, $profile, $account, $since, $until): int {
                $this->upsertInsights($rows);
                $deleted = $this->deleteVanished($rows, $account, $since, $until);
                $this->upsertEntities($rows, $campaigns, $account, $statuses);
                if ($profile !== null) {
                    $this->upsertAccountProfile($account, $profile['name']);
                }

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
                'account' => $this->accountRef(),
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
                'account' => $this->accountRef(),
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
            'account' => $this->accountRef(),
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

            $actions = $this->actions($r['actions'] ?? null);

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
                'inline_link_clicks' => $this->count($r['inline_link_clicks'] ?? null),
                'messaging_conversations_started' => $actions[self::ACTION_MESSAGING_STARTED] ?? 0,
                'messaging_conversations_replied' => $actions[self::ACTION_MESSAGING_REPLIED] ?? 0,
                'landing_page_views' => $actions[self::ACTION_LANDING_PAGE_VIEW] ?? 0,
                // El upsert va por el query builder, que no aplica casts: se codifica aquí.
                'actions' => $actions === [] ? null : json_encode(
                    array_map(static fn (string $type, int $value): array => ['action_type' => $type, 'value' => $value], array_keys($actions), $actions),
                ),
                'synced_at' => $now,
            ];
        }

        // Por día: al montar la dimensión, el nombre del día más reciente gana.
        ksort($rows);

        return $rows;
    }

    /**
     * Estado efectivo de las campañas de la cuenta (el borde `/campaigns`, sin
     * eliminadas). Es accesorio: si falla, la pasada sigue (el gasto ya está
     * completo) y los estados se quedan como estaban.
     *
     * @return list<array{id: string, name: ?string, effective_status: ?string}>|null
     */
    private function campaignStatuses(): ?array
    {
        try {
            $raw = $this->client->campaigns();
        } catch (MetaAdsApiException $e) {
            $this->limited = $this->limited || $e->category === MetaAdsApiException::RATE_LIMIT;
            ChannelLog::warning('meta.ads.sync.campaign_status_skipped', [
                'account' => $this->accountRef(),
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

    /**
     * Estado efectivo de los conjuntos y anuncios de TODA la cuenta, de los bordes
     * `/adsets` y `/ads` (`?ids=` ya no existe en v26). Los eliminados no se
     * pueden pedir a esos bordes: su estado se queda como el último conocido.
     *
     * Accesorio, nivel a nivel: si un borde falla (un límite de Meta, por
     * ejemplo), la pasada sigue, se anota y los estados de ese nivel se quedan
     * como estaban.
     *
     * @return array<string, array<string, string>> nivel → (id → estado)
     */
    private function entityStatuses(): array
    {
        $out = [];
        foreach ([MetaAdEntity::LEVEL_ADSET => fn () => $this->client->adsets(), MetaAdEntity::LEVEL_AD => fn () => $this->client->ads()] as $level => $edge) {
            if ($this->limited) {
                break;
            }
            try {
                $raw = $edge();
            } catch (MetaAdsApiException $e) {
                $this->limited = $this->limited || $e->category === MetaAdsApiException::RATE_LIMIT;
                ChannelLog::warning('meta.ads.sync.entity_status_skipped', [
                    'account' => $this->accountRef(),
                    'level' => $level,
                    'error_code' => $e->category,
                    'http_status' => $e->httpStatus,
                    'meta_code' => $e->metaCode,
                ]);

                continue;
            }

            $out[$level] = [];
            foreach ($raw as $entity) {
                $id = $this->id($entity['id'] ?? null);
                $status = is_string($entity['effective_status'] ?? null) ? mb_substr(trim($entity['effective_status']), 0, 32) : '';
                if ($id !== null && $status !== '') {
                    $out[$level][$id] = $status;
                }
            }
        }

        return $out;
    }

    /**
     * Las acciones de una fila de Meta como `action_type => valor`, solo las que
     * tienen forma válida. Una lista malformada no invalida la pasada (el gasto
     * no depende de ella): se queda sin acciones.
     *
     * @return array<string, int>
     */
    private function actions(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $a) {
            $type = is_array($a) && is_string($a['action_type'] ?? null) ? trim($a['action_type']) : '';
            $value = is_array($a) ? ($a['value'] ?? null) : null;

            if (preg_match('/^[a-z0-9_.]{1,100}$/i', $type) !== 1 || ! is_numeric($value)) {
                continue;
            }

            $out[$type] = ($out[$type] ?? 0) + max(0, (int) round((float) $value));
        }

        ksort($out);

        return $out;
    }

    /**
     * Guarda el alcance de UN rango: la fila de la cuenta y las de sus campañas.
     * Las campañas de ese mismo rango que Meta ya no devuelve se borran; las de
     * otros rangos no se tocan.
     *
     * @param  array<string,mixed>|null  $accountRow
     * @param  list<array<string,mixed>>  $campaignRows
     */
    private function writeReach(string $account, string $since, string $until, ?array $accountRow, array $campaignRows): int
    {
        $now = now()->toDateTimeString();
        $rows = [];

        $put = function (string $level, string $entity, array $r) use (&$rows, $account, $since, $until, $now): void {
            $rows[$level.'|'.$entity] = [
                'ad_account_id' => $account,
                'level' => $level,
                'entity_id' => $entity,
                'date_from' => $since,
                'date_to' => $until,
                'reach' => $this->count($r['reach'] ?? null),
                'impressions' => $this->count($r['impressions'] ?? null),
                'frequency' => is_numeric($r['frequency'] ?? null) ? round((float) $r['frequency'], 4) : null,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // Sin fila de cuenta, la cuenta no tuvo entrega en el rango: alcance 0 comprobado.
        $put(MetaAdReachSnapshot::LEVEL_ACCOUNT, $account, $accountRow ?? []);

        foreach ($campaignRows as $r) {
            $campaign = $this->id($r['campaign_id'] ?? null);
            if ($campaign !== null) {
                $put(MetaAdReachSnapshot::LEVEL_CAMPAIGN, $campaign, $r);
            }
        }

        DB::table('meta_ad_reach_snapshots')
            ->where('ad_account_id', $account)
            ->where('level', MetaAdReachSnapshot::LEVEL_CAMPAIGN)
            ->where('date_from', $since)
            ->where('date_to', $until)
            ->whereNotIn('entity_id', array_map(static fn (array $r): string => $r['entity_id'], array_values($rows)))
            ->delete();

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            DB::table('meta_ad_reach_snapshots')->upsert(
                $chunk,
                ['ad_account_id', 'level', 'entity_id', 'date_from', 'date_to'],
                ['reach', 'impressions', 'frequency', 'synced_at', 'updated_at'],
            );
        }

        return count($rows);
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
                    'spend', 'currency', 'impressions', 'reach', 'clicks', 'inline_link_clicks',
                    'messaging_conversations_started', 'messaging_conversations_replied', 'landing_page_views',
                    'actions', 'synced_at',
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
     * @param  array<string, list<array{id: string, name: ?string, effective_status: ?string}>>|null  $statuses  conjuntos y anuncios
     */
    private function upsertEntities(array $rows, ?array $campaigns, string $account, ?array $statuses = null): void
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

        $this->applyStatuses($account, $statuses ?? []);

        // Las campañas, igual: solo las que ya están en la dimensión (vienen del
        // gasto). El borde trae todas las de la cuenta, también las que nunca
        // gastaron, y la dimensión sale de las filas de gasto.
        if ($campaigns !== null && $campaigns !== []) {
            $this->applyStatuses($account, [MetaAdEntity::LEVEL_CAMPAIGN => array_column($campaigns, 'effective_status', 'id')]);
        }
    }

    /**
     * El nombre de la cuenta, si hace falta pedirlo: su fila de nivel `account`
     * no existe o tiene más de {@see ACCOUNT_PROFILE_HOURS} horas. `GET act_{id}`
     * con los campos de la ficha. Accesorio: si falla, la pasada sigue y se
     * pedirá en la siguiente; tras un límite de Meta, no se pide.
     *
     * @return array{name: ?string}|null null si no hay nada que escribir
     */
    private function accountProfile(string $account): ?array
    {
        if ($this->limited) {
            return null;
        }

        $syncedAt = MetaAdEntity::query()
            ->where('ad_account_id', $account)
            ->where('level', MetaAdEntity::LEVEL_ACCOUNT)
            ->where('entity_id', $account)
            ->value('synced_at');

        if ($syncedAt instanceof CarbonInterface && $syncedAt->greaterThan(now()->subHours(self::ACCOUNT_PROFILE_HOURS))) {
            return null;
        }

        try {
            $body = $this->client->account();
        } catch (MetaAdsApiException $e) {
            $this->limited = $this->limited || $e->category === MetaAdsApiException::RATE_LIMIT;
            ChannelLog::warning('meta.ads.sync.account_profile_skipped', [
                'account' => $this->accountRef(),
                'error_code' => $e->category,
                'http_status' => $e->httpStatus,
                'meta_code' => $e->metaCode,
            ]);

            return null;
        }

        return ['name' => $this->name($body['name'] ?? null)];
    }

    /**
     * Guarda el nombre de la cuenta en su fila de nivel `account` (entity_id =
     * la cuenta). Si Meta no trajo nombre, se conserva el que había y solo se
     * anota que se miró.
     */
    private function upsertAccountProfile(string $account, ?string $name): void
    {
        MetaAdEntity::query()->upsert(
            [[
                'ad_account_id' => $account,
                'level' => MetaAdEntity::LEVEL_ACCOUNT,
                'entity_id' => $account,
                'name' => $name,
                'campaign_id' => null,
                'adset_id' => null,
                'synced_at' => now()->toDateTimeString(),
            ]],
            ['ad_account_id', 'level', 'entity_id'],
            $name === null ? ['synced_at'] : ['name', 'synced_at'],
        );
    }

    /**
     * Pone el estado de Meta a las campañas, conjuntos y anuncios que YA están
     * en la dimensión de la cuenta (de esta pasada o de otras, como las del
     * relleno). No añade entidades: la dimensión sale de las filas de gasto.
     *
     * @param  array<string, array<string, ?string>>  $statuses  nivel → (id → estado)
     * @return array<string, int> nivel → filas de la dimensión actualizadas
     */
    private function applyStatuses(string $account, array $statuses): array
    {
        $now = now()->toDateTimeString();
        $touched = [];
        foreach ($statuses as $level => $byId) {
            $touched[$level] = 0;
            $byStatus = [];
            foreach ($byId as $id => $status) {
                if (is_string($status) && $status !== '') {
                    $byStatus[$status][] = (string) $id;
                }
            }
            foreach ($byStatus as $status => $ids) {
                foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                    $touched[$level] += MetaAdEntity::query()
                        ->where('ad_account_id', $account)
                        ->where('level', $level)
                        ->whereIn('entity_id', $chunk)
                        ->update(['effective_status' => $status, 'synced_at' => $now]);
                }
            }
        }

        return $touched;
    }

    /**
     * Estados de campañas, conjuntos y anuncios de toda la cuenta, fuera de una
     * pasada de gasto: lo usa el relleno al terminar, una sola vez por cuenta.
     * Con el cerrojo de la cuenta (si hay una pasada suya en curso, no hace nada:
     * la siguiente pasada ya aplica los estados a toda la dimensión). Accesorio:
     * nunca lanza.
     *
     * @return array{status: string, campaigns: int, adsets: int, ads: int} filas de la dimensión actualizadas
     */
    public function refreshStatuses(): array
    {
        $nothing = ['campaigns' => 0, 'adsets' => 0, 'ads' => 0];
        $account = $this->client->accountId();
        if ($account === null || $this->client->configurationProblem() !== null) {
            return ['status' => MetaSyncRun::STATUS_NOT_CONFIGURED] + $nothing;
        }

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['status' => MetaSyncRun::STATUS_RUNNING] + $nothing;
        }

        try {
            $this->limited = false;
            $campaigns = $this->campaignStatuses();
            $statuses = $this->entityStatuses();
            if ($campaigns !== null) {
                $statuses[MetaAdEntity::LEVEL_CAMPAIGN] = array_column($campaigns, 'effective_status', 'id');
            }

            $touched = DB::transaction(fn (): array => $this->applyStatuses($account, $statuses));

            return [
                'status' => count($statuses) < 3 ? 'partial' : MetaSyncRun::STATUS_OK,
                'campaigns' => $touched[MetaAdEntity::LEVEL_CAMPAIGN] ?? 0,
                'adsets' => $touched[MetaAdEntity::LEVEL_ADSET] ?? 0,
                'ads' => $touched[MetaAdEntity::LEVEL_AD] ?? 0,
            ];
        } catch (Throwable $e) {
            ChannelLog::error('meta.ads.statuses.crashed', [
                'account' => $this->accountRef(),
                'exception' => class_basename($e),
                'error_message' => MetaAdsApiClient::sanitize($e->getMessage(), 1000),
            ]);

            return ['status' => MetaSyncRun::STATUS_FAILED] + $nothing;
        } finally {
            $lock->release();
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

        if ($since->toDateString() < self::MIN_DATE) {
            if ($from !== null) {
                throw new InvalidArgumentException('Meta Ads no se sincroniza antes de '.self::MIN_DATE.'.');
            }
            // La ventana por defecto solo se recorta: la pasada de cada hora no falla por ella.
            $since = CarbonImmutable::createFromFormat('!Y-m-d', self::MIN_DATE, $tz);
        }

        if ($since->greaterThan($until)) {
            throw new InvalidArgumentException('El rango a sincronizar empieza después de terminar, o en el futuro.');
        }

        return [$since->toDateString(), $until->toDateString()];
    }

    /**
     * El estado de la vuelta de {@see runAll()} a partir de los de cada cuenta.
     *
     * @param  list<string>  $statuses
     */
    private function overall(array $statuses): string
    {
        $unique = array_values(array_unique($statuses));

        return match (true) {
            $unique === [] => MetaSyncRun::STATUS_NOT_CONFIGURED,
            count($unique) === 1 && $unique[0] !== self::STATUS_SKIPPED => $unique[0],
            in_array(MetaSyncRun::STATUS_OK, $unique, true) => self::STATUS_PARTIAL,
            default => MetaSyncRun::STATUS_FAILED,
        };
    }

    /**
     * El estado de la cuenta cuyo último éxito es el MÁS VIEJO.
     *
     * @param  array<string, array<string, mixed>>  $states  todas con `last_success_at`
     * @return array<string, mixed>
     */
    private function oldestState(array $states): array
    {
        $oldest = null;
        foreach ($states as $s) {
            if ($oldest === null || CarbonImmutable::parse($s['last_success_at'])->lessThan(CarbonImmutable::parse($oldest['last_success_at']))) {
                $oldest = $s;
            }
        }

        return $oldest ?? [];
    }

    /**
     * La intersección de los tramos cubiertos de cada cuenta: el `from` más
     * tardío y el `to` más temprano. Null si alguna no tiene tramo o si no se
     * solapan.
     *
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: ?string, 1: ?string}
     */
    private function intersection(array $states): array
    {
        $froms = array_column($states, 'covered_from');
        $tos = array_column($states, 'covered_to');
        if ($states === [] || in_array(null, $froms, true) || in_array(null, $tos, true)) {
            return [null, null];
        }

        $from = max($froms);
        $to = min($tos);

        return $from <= $to ? [$from, $to] : [null, null];
    }

    /**
     * Con el cerrojo de la cuenta en la mano, cualquier fila `running` de ESA
     * cuenta es de una pasada que murió sin cerrarla. Las de otras cuentas
     * tienen su propio cerrojo y pueden estar corriendo de verdad.
     */
    private function closeInterrupted(string $account): void
    {
        MetaSyncRun::query()
            ->where('kind', MetaSyncRun::KIND_INSIGHTS)
            ->where('status', MetaSyncRun::STATUS_RUNNING)
            ->where('ad_account_id', $account)
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
