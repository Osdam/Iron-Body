<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EL MONITOR (`outreach:monitor`, contrato H9, D9-14): SOLO APAGA. Abre un
 * freno de forma automática (con su incidente de IRON GUARD, fuente
 * `outreach`) ante lo que la tabla de D9-14 enumera, y NUNCA suelta nada
 * (las reglas 1 y 2 de `UltronAbortLatch`):
 *
 *  | Situación                                                   | Freno            |
 *  | la cadena ilegible (la cabeza no verifica)                  | global           |
 *  | la entrada de una estrategia no verifica                    | de la estrategia |
 *  | una estrategia con trabajos vivos sin estar activa (deriva) | de la estrategia |
 *  | tasa de opt-out de una estrategia > umbral en 24 h (P9-20)  | de la estrategia |
 *  | tasa de fallos de un canal > umbral en 24 h                 | del canal        |
 *  | gasto reservado hoy por encima del techo configurado        | del canal        |
 *  | un envío con metadatos del H9 sin su trabajo (unknown_send) | global (W9/V9)   |
 *
 * Solo trabaja con su bandera (`outreach.monitor.enabled`) y la maestra
 * encendidas y un modo distinto de `off`: si no, no escribe nada y lo dice.
 * `unknown_send` queda para W9/V9: exige leer los mensajes y las llamadas, y
 * la ejecución no lee tablas ajenas (D9-2). Cada pasada deja su fila.
 */
final class OutreachMonitor
{
    public const WINDOW_HOURS = 24;

    public function __construct(
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly OutreachStop $stop = new OutreachStop,
        private readonly OutreachRuns $runs = new OutreachRuns,
    ) {}

    /**
     * @return array<string, mixed> `{enabled, skipped, opened: list<{scope, scopeRef, reason}>, checks: {...}, runId}`
     */
    public function run(?DateTimeInterface $now = null): array
    {
        $ahora = $now === null ? new DateTimeImmutable('now', new \DateTimeZone('UTC')) : DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'));
        if (! OutreachConfig::monitorEnabled() || ! OutreachConfig::active()) {
            return ['enabled' => OutreachConfig::monitorEnabled(), 'active' => OutreachConfig::active(), 'skipped' => true, 'opened' => [], 'checks' => [], 'runId' => null];
        }
        $modo = OutreachConfig::mode();
        $runId = $this->runs->open('monitor', $modo, $ahora);
        $abiertos = [];
        $comprobaciones = [];
        try {
            $comprobaciones['chain'] = $this->checkChain($abiertos);
            $comprobaciones['drift'] = $this->checkDrift($abiertos);
            $comprobaciones['optOut'] = $this->checkOptOutRates($ahora, $abiertos);
            $comprobaciones['failures'] = $this->checkFailureRates($ahora, $abiertos);
            $comprobaciones['budget'] = $this->checkBudgets($ahora, $abiertos);
            $this->runs->complete($runId, $ahora, ['opened' => count($abiertos), 'checks' => array_map(static fn (array $c): int => (int) ($c['flagged'] ?? 0), $comprobaciones)]);
        } catch (Throwable $e) {
            $this->runs->fail($runId, $ahora, ['opened' => count($abiertos)], OutreachRuns::errorCode($e));

            throw $e;
        }

        return ['enabled' => true, 'active' => true, 'skipped' => false, 'opened' => $abiertos, 'checks' => $comprobaciones, 'runId' => $runId];
    }

    /** @param  list<array{scope: string, scopeRef: string|null, reason: string}>  $abiertos */
    private function checkChain(array &$abiertos): array
    {
        $cabeza = $this->bridge->headVerified();
        if (! $cabeza['verified']) {
            $this->brake($abiertos, OutreachStop::SCOPE_GLOBAL, null, 'chain_unreadable', ['result' => (string) $cabeza['result'], 'seq' => $cabeza['seq']]);

            return ['head' => $cabeza['result'], 'flagged' => 1];
        }
        $marcadas = 0;
        foreach ($this->bridge->liveStrategies() as $l) {
            if (! $l->chainReadable()) {
                $this->brake($abiertos, OutreachStop::SCOPE_STRATEGY, $l->strategyUuid, 'chain_unreadable', ['result' => $l->chainVerified, 'seq' => $l->publicationSeq]);
                $marcadas++;
            }
        }

        return ['head' => 'ok', 'flagged' => $marcadas];
    }

    /**
     * Deriva: una estrategia con trabajos vivos que ya no está activa en la
     * cadena (pausada, revertida, apagada) o cuya versión viva no se puede
     * releer. El despachador los cancela (`strategy_moved`); el freno deja
     * constancia y para lo que quede.
     */
    private function checkDrift(array &$abiertos): array
    {
        $vivas = [];
        foreach ($this->bridge->liveStrategies() as $l) {
            if ($l->runnable() && ! $l->paused()) {
                $vivas[$l->versionId] = true;
            }
        }
        $conTrabajos = DB::table(OutreachTables::JOBS.' as j')->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->whereIn('j.status', OutreachTables::JOB_LIVE_STATUSES)->groupBy('j.strategy_version_id', 's.uuid')
            ->get(['j.strategy_version_id', 's.uuid']);
        $marcadas = 0;
        foreach ($conTrabajos as $f) {
            if (! isset($vivas[(int) $f->strategy_version_id])) {
                $this->brake($abiertos, OutreachStop::SCOPE_STRATEGY, (string) $f->uuid, 'drift', ['version_id' => (int) $f->strategy_version_id]);
                $marcadas++;
            }
        }

        return ['flagged' => $marcadas];
    }

    /** Tasa de opt-out por estrategia en 24 h: `opted_out` / trabajos que salieron (`started`), en vivo. */
    private function checkOptOutRates(DateTimeImmutable $ahora, array &$abiertos): array
    {
        $umbral = OutreachConfig::monitorThresholds();
        $desde = $ahora->modify('-'.self::WINDOW_HOURS.' hours')->format('Y-m-d H:i:s');
        $salidos = DB::table(OutreachTables::JOBS)->where('mode', OutreachConfig::MODE_LIVE)->where('status', 'started')->where('status_changed_at', '>=', $desde)
            ->groupBy('strategy_id')->selectRaw('strategy_id, count(*) as n')->pluck('n', 'strategy_id')->all();
        $bajas = DB::table(OutreachTables::OUTCOMES.' as o')->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'o.enrollment_id')
            ->where('e.mode', OutreachConfig::MODE_LIVE)->where('o.outcome', 'opted_out')->where('o.occurred_at', '>=', $desde)
            ->groupBy('o.strategy_id')->selectRaw('o.strategy_id, count(*) as n')->pluck('n', 'o.strategy_id')->all();
        $uuids = DB::table(OutreachTables::STRATEGIES)->whereIn('id', array_keys($bajas))->pluck('uuid', 'id')->all();
        $marcadas = 0;
        foreach ($bajas as $strategyId => $n) {
            $muestra = (int) ($salidos[$strategyId] ?? 0);
            if ($muestra >= $umbral['minSample'] && (int) $n * 1000 > $umbral['optOutPermille'] * $muestra) {
                $this->brake($abiertos, OutreachStop::SCOPE_STRATEGY, (string) $uuids[$strategyId], 'opt_out_spike', ['opted_out' => (int) $n, 'started' => $muestra, 'permille_threshold' => $umbral['optOutPermille']]);
                $marcadas++;
            }
        }

        return ['flagged' => $marcadas];
    }

    /** Tasa de fallos por canal en 24 h: `failed_to_start` / (`started` + `failed_to_start`), en vivo. */
    private function checkFailureRates(DateTimeImmutable $ahora, array &$abiertos): array
    {
        $umbral = OutreachConfig::monitorThresholds();
        $desde = $ahora->modify('-'.self::WINDOW_HOURS.' hours')->format('Y-m-d H:i:s');
        $filas = DB::table(OutreachTables::JOBS)->where('mode', OutreachConfig::MODE_LIVE)->whereIn('status', ['started', 'failed_to_start'])
            ->where('status_changed_at', '>=', $desde)->whereIn('channel', OutreachStop::CHANNELS)
            ->groupBy('channel', 'status')->selectRaw('channel, status, count(*) as n')->get();
        $porCanal = [];
        foreach ($filas as $f) {
            $porCanal[(string) $f->channel][(string) $f->status] = (int) $f->n;
        }
        $marcados = 0;
        foreach ($porCanal as $canal => $n) {
            $fallos = $n['failed_to_start'] ?? 0;
            $total = $fallos + ($n['started'] ?? 0);
            if ($total >= $umbral['minSample'] && $fallos * 1000 > $umbral['failurePermille'] * $total) {
                $this->brake($abiertos, OutreachStop::SCOPE_CHANNEL, $canal, 'failure_spike', ['failed' => $fallos, 'total' => $total, 'permille_threshold' => $umbral['failurePermille']]);
                $marcados++;
            }
        }

        return ['flagged' => $marcados];
    }

    /** Techo de gasto: lo reservado hoy (día de Bogotá) por canal frente al techo que la configuración dice AHORA. */
    private function checkBudgets(DateTimeImmutable $ahora, array &$abiertos): array
    {
        $dia = RelativeTime::bogotaDate($ahora);
        $techos = [
            OutreachStop::CHANNELS[1] => ['units' => OutreachConfig::whatsappDailyCap(), 'micros' => OutreachConfig::whatsappDailyBudgetMicros()],
            OutreachStop::CHANNELS[0] => ['units' => OutreachConfig::assistedDailyCap(), 'micros' => 0],
        ];
        $marcados = 0;
        foreach ($techos as $canal => $techo) {
            $fila = DB::table(OutreachTables::BUDGETS)->where('budget_day', $dia)->where('scope', 'channel:'.$canal)->where('mode', OutreachConfig::MODE_LIVE)->first(['reserved_units', 'reserved_micros']);
            if ($fila === null) {
                continue;
            }
            if ((int) $fila->reserved_units > $techo['units'] || (int) $fila->reserved_micros > $techo['micros']) {
                $this->brake($abiertos, OutreachStop::SCOPE_CHANNEL, $canal, 'budget_breach', ['reserved_units' => (int) $fila->reserved_units, 'cap_units' => $techo['units'], 'reserved_micros' => (int) $fila->reserved_micros, 'cap_micros' => $techo['micros']]);
                $marcados++;
            }
        }

        return ['flagged' => $marcados];
    }

    /** @param  list<array{scope: string, scopeRef: string|null, reason: string}>  $abiertos */
    private function brake(array &$abiertos, string $scope, ?string $scopeRef, string $reason, array $evidence): void
    {
        if ($this->stop->openBySystem($scope, $scopeRef, $reason, $evidence) !== null) {
            $abiertos[] = ['scope' => $scope, 'scopeRef' => $scopeRef, 'reason' => $reason];
        }
    }
}
