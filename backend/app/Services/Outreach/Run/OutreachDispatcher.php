<?php

namespace App\Services\Outreach\Run;

use App\Jobs\Outreach\DispatchOutreachJob;
use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EL DESPACHADOR (contrato H9, D9-8 etapas 6 y 7; §13.1 #4, #13, #15, #18–#20,
 * #22; T15): reclama los trabajos vencidos y, JUSTO ANTES de ejecutar, vuelve
 * a mirarlo todo (la regla E6-6 del H8, TOCTOU): banderas y constante, frenos
 * y PARADA GLOBAL, el estado de la estrategia en la cadena, los hechos de
 * este instante (éxito y contexto), la escalera N1–N7 entera, el presupuesto
 * y la ventana. Lo que ya no vale se CANCELA con su motivo (una decisión
 * `cancel`), nunca se reinterpreta; lo que vale pasa a `dispatching` y el
 * canal hace UNA petición sin reintentos automáticos.
 *
 *  - RECLAMAR ({@see dispatch()}): `FOR UPDATE SKIP LOCKED` en PostgreSQL (dos
 *    despachadores no reclaman el mismo trabajo, #13) y un UPDATE condicionado
 *    al estado `planned` en los dos motores. Con la PARADA GLOBAL echada no se
 *    reclama nada (los `planned` ESPERAN, D9-14); un canal o una estrategia
 *    frenados no entran en la reclamación (sus trabajos esperan igual).
 *  - EJECUTAR ({@see process()}): la pasada entera por trabajo. En sombra el
 *    canal es el simulado (`simulated`, resultado `not_attempted`); en vivo,
 *    el asistido abre la tarea de la bandeja (`started`); WhatsApp y voz van
 *    por sus puertas (W9, V9): lo que no sale es `failed_to_start` con la
 *    reserva liberada y el resultado `failed`, salvo una condición PASAJERA
 *    ({@see TRANSIENT_FAILURES}), con la que la inscripción espera. Un trabajo
 *    que se cancela libera su reserva. Un `ambiguous` no se reintenta: se concilia.
 *
 * Cada reclamación deja su pasada (`outreach_runs`, `dispatch`); los trabajos
 * reclamados y no ejecutados (un proceso muerto) los recoge la conciliación
 * pasados 10 min (`ambiguous`).
 */
final class OutreachDispatcher
{
    /** Cuánto espera una inscripción cuyo trabajo se canceló por un freno o una carrera (minutos). */
    public const WAIT_MINUTES = 15;

    /**
     * Los `failed_to_start` de un canal que son una condición PASAJERA que la
     * escalera también mira y que cambió entre la etapa 6 y la puerta (una
     * carrera de milisegundos): no son un fallo de la persona ni del canal.
     * El trabajo queda `failed_to_start` (el grafo no admite otra salida desde
     * `dispatching`), la reserva se libera y la inscripción ESPERA lo mismo que
     * con un freno (N2 `waitsForRelease`), sin resultado `failed`: en la
     * siguiente pasada la escalera decide con lo que vea (W9 gancho 1).
     *
     *  - N2: la pausa del agente en la puerta de WhatsApp (`agent_paused`,
     *    = `marketing_agent_paused`), una persona que tomó la conversación
     *    (`human_takeover`, = `human_in_control`), el freno de voz del H8
     *    (`voice_braked`) y la maestra o el modo que dejaron de estar en vivo
     *    (`mode_not_live` en WhatsApp, `mode_off` en la voz);
     *  - N4: el canal apagado entre la escalera y la puerta (`channel_disabled`).
     *
     * NO están: las constantes de código (`sends_disabled_in_code`,
     * `channel_not_live`, `calls_disabled_in_code`), que la escalera y el canal
     * leen del mismo literal en el mismo proceso, y lo que la escalera no ve
     * (cupos, horario propio y concurrencia del H8…): esperar no los cambia y
     * repetiría el intento; para eso está el `on.failed` del paso.
     *
     * @var array<string, string> código → nivel de la decisión `cancel`
     */
    public const TRANSIENT_FAILURES = [
        'agent_paused' => 'N2',
        'human_takeover' => 'N2',
        'voice_braked' => 'N2',
        'mode_not_live' => 'N2',
        'mode_off' => 'N2',
        'channel_disabled' => 'N4',
    ];

    /** @var array<string, OutreachChannel> */
    private array $channels;

    /** @param  list<OutreachChannel>|null  $channels  los adaptadores (por defecto, los cuatro del conjunto cerrado) */
    public function __construct(
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
        private readonly OutreachStore $store = new OutreachStore,
        private readonly BudgetReservations $budgets = new BudgetReservations,
        private readonly OutreachRuns $runs = new OutreachRuns,
        private readonly OutreachStop $stop = new OutreachStop,
        private readonly LadderInputBuilder $inputs = new LadderInputBuilder,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        ?array $channels = null,
    ) {
        $this->channels = [];
        foreach ($channels ?? [new SimulatedChannel, new AssistedChannel, new WhatsappTemplateChannel, new VoiceStrategyChannel] as $c) {
            $this->channels[$c->name()] = $c;
        }
    }

    // ── Etapa 6 · Reclamar ──────────────────────────────────────────────────

    /**
     * Reclama hasta `$limit` trabajos vencidos y los despacha: en línea
     * (`$inline`, la consola) o por la cola (`DispatchOutreachJob`, un job por
     * trabajo reclamado).
     *
     * @return array<string, mixed>
     */
    public function dispatch(DateTimeInterface $now, ?int $limit = null, bool $inline = false): array
    {
        $ahora = RelativeTime::utc($now);
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off', 'claimed' => 0, 'results' => []];
        }
        $modo = OutreachConfig::mode();
        $abiertos = $this->stop->openScopes();
        if ($abiertos['global']) {
            $run = $this->runs->open('dispatch', $modo, $ahora);
            $this->runs->abort($run, $ahora, ['claimed' => 0, 'retained' => $this->retained($modo, $ahora)], 'outreach_brake_global');

            return ['skipped' => true, 'reason' => 'outreach_brake_global', 'claimed' => 0, 'results' => [], 'run' => $run];
        }
        $run = $this->runs->open('dispatch', $modo, $ahora);
        $reclamados = [];
        $resultados = [];
        try {
            $reclamados = $this->claim($modo, $ahora, $limit ?? OutreachConfig::dispatchBatch(), $abiertos);
            foreach ($reclamados as $id) {
                if ($inline) {
                    $r = $this->process($id, $ahora);
                    $resultados[$r] = ($resultados[$r] ?? 0) + 1;
                } else {
                    DispatchOutreachJob::dispatch($id);
                    $resultados['queued'] = ($resultados['queued'] ?? 0) + 1;
                }
            }
            $this->runs->complete($run, $ahora, ['claimed' => count($reclamados), 'results' => $resultados]);
        } catch (Throwable $e) {
            $this->runs->fail($run, $ahora, ['claimed' => count($reclamados), 'results' => $resultados], OutreachRuns::errorCode($e));

            throw $e;
        }

        return ['skipped' => false, 'claimed' => count($reclamados), 'results' => $resultados, 'run' => $run];
    }

    /**
     * La reclamación: los `planned` vencidos y no caducados cuyo canal y
     * estrategia no están frenados, los más antiguos primero. En PostgreSQL,
     * `FOR UPDATE OF j SKIP LOCKED` dentro de la transacción; en los dos
     * motores, un UPDATE condicionado por fila (0 filas = otro ganó).
     *
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @return list<int>
     */
    private function claim(string $modo, DateTimeImmutable $ahora, int $limit, array $abiertos): array
    {
        return DB::transaction(function () use ($modo, $ahora, $limit, $abiertos): array {
            $q = DB::table(OutreachTables::JOBS.' as j')
                ->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
                ->where('j.mode', $modo)
                ->where('j.status', 'planned')
                ->where('j.not_before', '<=', OutreachStore::sql($ahora))
                ->where('j.expires_at', '>', OutreachStore::sql($ahora))
                ->orderBy('j.not_before')->orderBy('j.id')
                ->limit(max(1, $limit));
            if ($abiertos['channels'] !== []) {
                $q->whereNotIn('j.channel', $abiertos['channels']);
            }
            if ($abiertos['strategies'] !== []) {
                $q->whereNotIn('s.uuid', $abiertos['strategies']);
            }
            if (DB::connection()->getDriverName() === 'pgsql') {
                $q->lock('for update of j skip locked');
            }
            $reclamados = [];
            foreach ($q->pluck('j.id') as $id) {
                if ($this->store->transitionJob((int) $id, ['planned'], 'claimed', $ahora, ['claimed_at' => OutreachStore::sql($ahora)])) {
                    $reclamados[] = (int) $id;
                }
            }

            return $reclamados;
        });
    }

    // ── Etapas 6 y 7 · Reevaluar y ejecutar ────────────────────────────────

    /**
     * La pasada entera de UN trabajo reclamado. Idempotente: si ya no está
     * `claimed`, no hace nada (`not_claimed`). Devuelve qué pasó.
     */
    public function process(int $jobId, DateTimeInterface $now): string
    {
        $ahora = RelativeTime::utc($now);
        $trabajo = $this->store->job($jobId);
        if ($trabajo === null) {
            return 'missing';
        }
        if ((string) $trabajo->status !== 'claimed') {
            return 'not_claimed';
        }
        $e = $this->store->enrollment((int) $trabajo->enrollment_id);
        if ($e === null) {
            return 'missing';
        }
        $modo = (string) $trabajo->mode;
        $vacia = FactSnapshot::fromArray(['facts' => []]);

        // 6.1 · Banderas y constante.
        if (! OutreachConfig::active() || OutreachConfig::mode() !== $modo) {
            return $this->cancel($trabajo, $e, OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off', 'N2', $vacia, $ahora, wait: true);
        }
        // 6.2 · Frenos y PARADA GLOBAL (leídos otra vez).
        $abiertos = $this->stop->openScopes();
        $uuid = (string) DB::table(OutreachTables::STRATEGIES)->where('id', (int) $trabajo->strategy_id)->value('uuid');
        $frenos = OutreachStop::ladderBrakes($abiertos, $uuid, (string) $trabajo->channel);
        foreach (['global' => 'outreach_brake_global', 'strategy' => 'outreach_brake_strategy', 'channel' => 'outreach_brake_channel'] as $alcance => $motivo) {
            if ($frenos[$alcance]) {
                return $this->cancel($trabajo, $e, $motivo, 'N2', $vacia, $ahora, wait: true);
            }
        }
        // 6.3 · La estrategia en la cadena.
        $live = $this->bridge->liveVersion((int) $e->strategy_version_id, (string) $e->part);
        if ($live === null && in_array((int) $e->strategy_id, $this->bridge->unverifiedStrategyIds(), true)) {
            // El par falta porque la cabeza de esa estrategia no verifica (REV9 m-10, L9-72): cadena ilegible, no huérfana.
            return $this->cancel($trabajo, $e, 'chain_unreadable', 'N5', $vacia, $ahora, wait: true);
        }
        $huerfana = $live === null ? 'strategy_moved' : $live->orphanReason();
        if ($huerfana !== null) {
            // La versión salió de la cadena (reversión, retirada) o su contenido no se puede releer: el trabajo y la inscripción se cancelan (D9-8).
            $r = $this->cancel($trabajo, $e, $huerfana, 'N5', $vacia, $ahora, wait: false);
            $this->store->closeEnrollment((int) $e->id, 'canceled', $huerfana, $ahora);

            return $r;
        }
        if (! $live->runnable()) {
            // La entrada de la cadena no verifica (D9-11.3; REV9 M-5, L9-69): N5 niega, el monitor frena; el trabajo se cancela y la inscripción ESPERA, no se cancela.
            return $this->cancel($trabajo, $e, 'chain_unreadable', 'N5', $vacia, $ahora, wait: true);
        }
        if ($live->paused()) {
            return $this->cancel($trabajo, $e, 'strategy_paused', 'N5', $vacia, $ahora, wait: true);
        }
        // 6.4 · La ventana del trabajo.
        if (OutreachStore::instant($trabajo->expires_at) <= $ahora) {
            if ($this->store->transitionJob((int) $trabajo->id, ['claimed'], 'expired', $ahora)) {
                $this->release($trabajo, $uuid, $ahora);
            }
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora)], $ahora);

            return 'expired';
        }
        // 6.5 · Los hechos de este instante, la puerta y la escalera por canal.
        $foto = $this->snapshots->build([(string) $e->subject_key], $ahora, CoreProjection::selectionFor($live->uses(), true));
        $s = $foto->subject((string) $e->subject_key);
        if ($s === null || ! $s->resolved()) {
            $r = $this->cancel($trabajo, $e, 'unknown_subject', 'N1', $vacia, $ahora, wait: false);
            $this->store->closeEnrollment((int) $e->id, 'exited', 'unknown_subject', $ahora);

            return $r;
        }
        $indice = (int) $trabajo->step_index;
        $paso = $live->step($indice);
        if ($paso === null) {
            $r = $this->cancel($trabajo, $e, 'sequence_done', 'N5', $vacia, $ahora, wait: false);
            $this->store->closeEnrollment((int) $e->id, 'exited', 'sequence_done', $ahora);

            return $r;
        }
        $hechos = CoreProjection::snapshot($s, $live->strategyUuid);
        $puerta = EnrollmentGate::evaluate($live->audience(), $live->exclusions(), $live->success(), $hechos, $ahora);
        $guarda = $live->guard($indice);
        $guardaResultado = $guarda === null ? null : ConditionEvaluator::evaluate($guarda, $hechos, $ahora, 'guard');
        $canales = $this->channelsToRecheck($paso['channels'], $trabajo);
        $ctx = [
            'stepIndex' => $indice,
            'gate' => EnrollmentGate::context($puerta),
            'stepGuard' => $guardaResultado?->label(),
            'humanRequestedRecently' => LadderInputBuilder::humanRequestedRecently($hechos->events, $ahora),
            'otherPurposeToday' => $this->otherPurposeToday($e, $trabajo, $ahora),
            'lowerPriority' => false,
            'touches' => (int) $e->touches,
            'enrollmentWindowEndsAt' => RelativeTime::format(OutreachStore::instant($e->window_ends_at)),
            'cooldownUntil' => null,
            'callbackAt' => null,
            'brakes' => $abiertos,
            'extraHistory' => $this->store->recentStartedWithoutStaffOutcome((string) $e->subject_key, $ahora),
            'budgetDay' => (string) $trabajo->budget_day,
        ];
        $porCanal = [];
        $politica = [];
        foreach ($canales as $canal) {
            $entrada = $this->inputs->build($live, $s, $foto->global, $canal, $ahora, $modo, $ctx);
            // Lo que este mismo trabajo reservó no es «otro» consumo: se descuenta para no negarse a sí mismo.
            $entrada = $this->discountOwnReservation($entrada, $trabajo);
            $salida = PolicyLadder::decide($entrada);
            $porCanal[$canal] = $salida;
            $politica[$canal] = ['input' => $entrada, 'output' => $salida];
        }
        $eleccion = ChannelSelector::choose($canales, $porCanal, null);
        $condiciones = array_filter(['audience' => $puerta['audience'], 'exclusions' => $puerta['exclusions'], 'success' => $puerta['success'], 'guard' => $guardaResultado]);
        $partes = ['policy' => $politica, 'channel' => $eleccion, 'gate' => ['reason' => $puerta['reason'], 'recheck' => 'dispatch']];
        $primero = $porCanal[$eleccion['order'][0]];

        if ($eleccion['verdict'] !== ChannelSelector::SELECTED) {
            return $this->notAllowed($trabajo, $e, $live, $hechos, $ahora, $eleccion, $porCanal, $primero, $condiciones, $partes, $indice);
        }

        // 7 · Ejecutar: UNA petición.
        if (! $this->store->transitionJob((int) $trabajo->id, ['claimed'], 'dispatching', $ahora)) {
            return 'lost_race';
        }
        $canal = $this->channels[(string) $trabajo->channel] ?? null;
        $intento = $canal === null ? ChannelAttempt::failed('channel_unknown') : $canal->attempt($trabajo, $e, $live, $ahora);

        return $this->settle($trabajo, $e, $uuid, $intento, $ahora);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** El estado final del trabajo tras el canal, su resultado y lo que toca a la inscripción. */
    private function settle(object $trabajo, object $e, string $uuid, ChannelAttempt $intento, DateTimeImmutable $ahora): string
    {
        $extra = [];
        if (isset($intento->refs['marketing_message_id'])) {
            $extra['marketing_message_id'] = (int) $intento->refs['marketing_message_id'];
        }
        if (isset($intento->refs['voice_call_id'])) {
            $extra['voice_call_id'] = (int) $intento->refs['voice_call_id'];
        }
        if (! $this->store->transitionJob((int) $trabajo->id, ['dispatching'], $intento->status, $ahora, $extra)) {
            return 'lost_race';
        }
        $trabajo = $this->store->job((int) $trabajo->id);
        switch ($intento->status) {
            case ChannelAttempt::SIMULATED:
                $this->store->updateOpenEnrollment((int) $e->id, ['touches' => (int) $e->touches + 1], $ahora);
                $this->outcomes->record($e, 'not_attempted', 'simulated', 'job:'.$trabajo->uuid, $ahora, ['job' => $trabajo]);

                return 'simulated';
            case ChannelAttempt::STARTED:
                $this->store->updateOpenEnrollment((int) $e->id, ['touches' => (int) $e->touches + 1], $ahora);

                return 'started';
            case ChannelAttempt::FAILED_TO_START:
                $nivel = self::TRANSIENT_FAILURES[(string) $intento->detailCode] ?? null;
                if ($nivel !== null) {
                    // Pasajera (la escalera la habría visto un instante antes): sin `failed`; la reserva vuelve y la inscripción espera.
                    DB::transaction(function () use ($trabajo, $e, $uuid, $intento, $nivel, $ahora): void {
                        $this->release($trabajo, $uuid, $ahora);
                        $this->decideCancel($trabajo, $e, FactSnapshot::fromArray(['facts' => []]), $ahora, $nivel, [(string) $intento->detailCode], [], ['gate' => ['recheck' => 'channel']]);
                        $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);
                    });

                    return 'waiting';
                }
                $this->release($trabajo, $uuid, $ahora);
                $this->outcomes->record($e, 'failed', 'system', 'job:'.$trabajo->uuid, $ahora, ['job' => $trabajo, 'detailCode' => $intento->detailCode]);

                return 'failed';
            default:
                $this->outcomes->record($e, 'ambiguous', 'system', 'job:'.$trabajo->uuid, $ahora, ['job' => $trabajo, 'detailCode' => $intento->detailCode]);

                return 'ambiguous';
        }
    }

    /**
     * La escalera o el selector no permiten salir AHORA: el trabajo se cancela
     * con su motivo (una decisión `cancel`) y la inscripción sigue lo que el
     * motivo manda (aplazar, esperar, convertir, derivar, salir o `blocked`).
     *
     * @param  array<string, array<string, mixed>>  $porCanal
     * @param  array<string, mixed>  $primero
     */
    private function notAllowed(object $trabajo, object $e, LiveStrategy $live, FactSnapshot $hechos, DateTimeImmutable $ahora, array $eleccion, array $porCanal, array $primero, array $condiciones, array $partes, int $indice): string
    {
        $motivos = $primero['reasons'] !== [] ? $primero['reasons'] : ['denied'];
        $motivo = (string) $motivos[0];
        $uuid = $live->strategyUuid;
        $cancelado = $this->cancelJob($trabajo, $uuid, $motivo, $ahora);
        $this->decideCancel($trabajo, $e, $hechos, $ahora, $primero['level'], $motivos, $condiciones, $partes);
        if (! $cancelado) {
            return 'lost_race';
        }
        if ($eleccion['verdict'] === ChannelSelector::DEFER) {
            $reintento = RelativeTime::parseInstant((string) $eleccion['retryAt']);
            if ($reintento >= OutreachStore::instant($e->window_ends_at)) {
                $this->windowOver($e, $ahora);

                return 'expired';
            }
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($reintento)], $ahora);

            return 'deferred';
        }
        $efecto = null;
        foreach ($eleccion['order'] as $canal) {
            if (($porCanal[$canal]['effect'] ?? null) !== null) {
                $efecto = $porCanal[$canal]['effect'];

                break;
            }
        }
        if ($efecto === 'exit_converted') {
            $this->outcomes->record($e, 'converted', 'system', 'success:'.$indice.':'.$ahora->format('Ymd'), $ahora);

            return 'converted';
        }
        if ($efecto === 'handoff') {
            $this->store->closeEnrollment((int) $e->id, 'handed_off', 'human_requested_recently', $ahora);

            return 'handed_off';
        }
        if (in_array('global_suppression', $motivos, true)) {
            // La persona está suprimida (opt-out o número equivocado): la inscripción sale, no espera.
            $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => 'global_suppression', 'on' => ['blocked' => 'end_exit'], 'exitReason' => 'global_suppression']);

            return 'canceled';
        }
        if ($primero['waitsForRelease']) {
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);

            return 'waiting';
        }
        if ($efecto === 'cancel_context_changed') {
            // La persona dejó la audiencia: `blocked` que SALE (no espera), con su motivo en la inscripción.
            $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => 'context_changed', 'on' => ['blocked' => 'end_exit'], 'exitReason' => 'context_changed']);

            return 'canceled';
        }
        $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => $motivo]);

        return 'denied';
    }

    /** Cancela un trabajo reclamado por algo que se vio ANTES de los hechos (banderas, frenos, cadena): decisión `cancel` sin foto. */
    private function cancel(object $trabajo, object $e, string $motivo, string $nivel, FactSnapshot $vacia, DateTimeImmutable $ahora, bool $wait): string
    {
        $uuid = (string) DB::table(OutreachTables::STRATEGIES)->where('id', (int) $trabajo->strategy_id)->value('uuid');
        $cancelado = $this->cancelJob($trabajo, $uuid, $motivo, $ahora);
        $this->decideCancel($trabajo, $e, $vacia, $ahora, $nivel, [$motivo], [], ['gate' => ['recheck' => 'dispatch']]);
        if ($cancelado && $wait) {
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);
        }

        return $cancelado ? 'canceled' : 'lost_race';
    }

    /** `claimed` → `canceled` con su motivo y la reserva liberada. */
    private function cancelJob(object $trabajo, string $uuid, string $motivo, DateTimeImmutable $ahora): bool
    {
        $codigo = preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $motivo) === 1 ? $motivo : 'denied';
        if (! $this->store->transitionJob((int) $trabajo->id, ['claimed'], 'canceled', $ahora, ['cancel_reason' => $codigo])) {
            return false;
        }
        $this->release($trabajo, $uuid, $ahora);

        return true;
    }

    private function release(object $trabajo, string $uuid, DateTimeImmutable $ahora): void
    {
        if ((int) $trabajo->reserved_units === 0 && (int) $trabajo->reserved_micros === 0) {
            return;
        }
        $this->budgets->release((string) $trabajo->budget_day, (string) $trabajo->mode, BudgetReservations::parcelsOfJob($trabajo, $uuid), $ahora);
        $this->store->markBudgetReleased((int) $trabajo->id, $ahora);
    }

    /** @param  list<string>  $motivos */
    private function decideCancel(object $trabajo, object $e, FactSnapshot $hechos, DateTimeImmutable $ahora, ?string $nivel, array $motivos, array $condiciones, array $partes): void
    {
        $traza = DecisionTrace::build(['kind' => 'cancel', 'level' => $nivel, 'reasons' => $motivos, 'retryAt' => null], $hechos, $ahora, $condiciones, $partes);
        $this->store->insertDecision(OutreachStore::sealOf($e), (string) $e->subject_key, (string) $e->subject_ref, 'cancel', $nivel, $motivos, null, $hechos, $traza, $ahora, null, (int) $e->id, (int) $trabajo->id,
            $trabajo->template_version_id !== null ? (int) $trabajo->template_version_id : null, $trabajo->template_hash);
    }

    /**
     * Los canales del paso que se vuelven a evaluar: en sombra, todos (el
     * trabajo simulado representa al que habría salido); en vivo, los del
     * canal de trabajo (y su medio, en el asistido).
     *
     * @param  list<string>  $canales
     * @return list<string>
     */
    private function channelsToRecheck(array $canales, object $trabajo): array
    {
        if ((string) $trabajo->channel === SimulatedChannel::NAME) {
            return $canales;
        }
        $mios = array_values(array_filter($canales, static fn (string $c): bool => OutreachSendGate::jobChannelOf($c) === (string) $trabajo->channel
            && ((string) $trabajo->channel !== AssistedChannel::NAME || OutreachCatalog::mediumOf($c) === $trabajo->medium)));

        return $mios !== [] ? $mios : $canales;
    }

    /** Otra finalidad ya contactó hoy a la persona (sin contar este trabajo). */
    private function otherPurposeToday(object $e, object $trabajo, DateTimeImmutable $ahora): bool
    {
        foreach ($this->store->startedTodayOf((string) $e->subject_key, (string) $trabajo->mode, RelativeTime::bogotaDate($ahora)) as $t) {
            if ((int) $t->id !== (int) $trabajo->id && (string) $t->purpose !== (string) $e->purpose) {
                return true;
            }
        }

        return false;
    }

    /**
     * El contador de hoy incluye lo que ESTE trabajo reservó al planificarse:
     * se descuenta en la entrada de N4 (`channelUsedToday`, `assistedUsedToday`,
     * `reservedTodayMicros`, `strategy.usedToday`), si no el trabajo se negaría
     * a sí mismo al llegar al tope.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function discountOwnReservation(array $entrada, object $trabajo): array
    {
        $unidades = (int) $trabajo->reserved_units;
        $micros = (int) $trabajo->reserved_micros;
        if ((string) $trabajo->channel === AssistedChannel::NAME) {
            $entrada['budget']['assistedUsedToday'] = max(0, $entrada['budget']['assistedUsedToday'] - $unidades);
        } else {
            $entrada['budget']['channelUsedToday'] = max(0, $entrada['budget']['channelUsedToday'] - $unidades);
            $entrada['budget']['reservedTodayMicros'] = max(0, $entrada['budget']['reservedTodayMicros'] - $micros);
        }
        // Este trabajo cuenta ya en los de hoy de su estrategia (está vivo): no es «otro» contacto.
        $entrada['strategy']['usedToday'] = max(0, $entrada['strategy']['usedToday'] - 1);
        // Y su propio teléfono de hoy (el trabajo lleva `phone_ref` y `budget_day`): un contacto a sí mismo no es otro.
        if ($trabajo->phone_ref !== null) {
            $propio = false;
            $entrada['phone']['contacts'] = array_values(array_filter($entrada['phone']['contacts'], static function (array $c) use ($trabajo, &$propio): bool {
                if (! $propio && $c['phoneRef'] === $trabajo->phone_ref && $c['at'] === RelativeTime::format(OutreachStore::instant($trabajo->not_before))) {
                    $propio = true;

                    return false;
                }

                return true;
            }));
        }

        return $entrada;
    }

    /** La ventana de la inscripción se acabó sin contacto: `expired` con `no_response`. */
    private function windowOver(object $e, DateTimeImmutable $ahora): void
    {
        $this->outcomes->record($e, 'no_response', 'system', 'window:'.$e->id, $ahora);
        $abierta = $this->store->enrollment((int) $e->id);
        if ($abierta !== null && in_array((string) $abierta->status, OutreachTables::ENROLLMENT_OPEN_STATUSES, true)) {
            $this->store->closeEnrollment((int) $e->id, 'expired', 'enrollment_window_over', $ahora, 'no_response');
        }
    }

    /** Los trabajos vivos que una PARADA GLOBAL deja esperando (la respuesta de D9-14). */
    private function retained(string $modo, DateTimeImmutable $ahora): int
    {
        return (int) DB::table(OutreachTables::JOBS)->where('mode', $modo)->whereIn('status', OutreachTables::JOB_LIVE_STATUSES)->where('expires_at', '>', OutreachStore::sql($ahora))->count();
    }
}
