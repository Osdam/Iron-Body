<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\TelephonyCarrier;
use App\Services\Voice\VoiceAgentDeployer;
use App\Services\Voice\VoiceNumberObserver;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA CONCILIACIÓN (contrato H8, D8-21; E1-2, E2-3; fixture
 * `twilio-webhook-bodies.json` §callsPage). Lee de los proveedores (GET):
 * las llamadas de la subcuenta en Twilio y la proyección de los agentes
 * vigentes en ElevenLabs. Su ÚNICA escritura a un proveedor (E12-5, B8-e):
 * volver a colgar una saliente cortada que Twilio enseña viva (nunca en seco).
 *
 * Cada llamada que enseña Twilio:
 *  - CONOCIDA (tiene fila por su SID, o es la ambigua cuyo SID dejó el job en
 *    su evento): su estado avanza hacia delante según Twilio (DF-E14:
 *    `queued`, `ringing` e `in-progress` siguen en `dialing`; `canceled` es
 *    `failed`), con `reconciled` la primera vez que Twilio la da por terminada;
 *    su precio (el valor absoluto, en micro-US$; null = sin tarificar, se
 *    reintenta, DF-W6) va con `cost_recorded`. Una entrante denegada es
 *    conocida (E1-2).
 *  - TRAMO DE TRANSFERENCIA (su `parent_call_sid` es una llamada con fila): su
 *    coste se suma a la madre con `cost_recorded` y no abre nada (E1-2,
 *    DF-W4). No se le crea fila: el operador no da su número (D8-17) y la
 *    base exige la huella del destino.
 *  - DESCONOCIDA (iniciada fuera de Laravel): `unknown_provider_call` y FRENO.
 *
 * Además: una saliente `requested` de más de 15 minutos perdió su job →
 * `ambiguous` (sin red; la resuelve esta misma conciliación); la deriva de los
 * agentes vigentes (`describeAgent`, solo GET) abre el freno y deja su
 * veredicto en la caché que lee el job (≤ 5 min); y si lo comprometido del día
 * pasa del presupuesto (y de cero: E10-8), freno por coste (el techo duro).
 * La deriva y el techo SE REARMAN si la evidencia cambió después de soltar el
 * freno (E10-6: {@see VoiceBrake::openOrRearm()}).
 *
 * LA `ambiguous` QUE NO SALIÓ (Enmienda del 22-11-2026, E19-3; H-1;
 * {@see VoiceAmbiguousResolution}): con una pasada COMPLETA del operador (todas
 * sus páginas listadas, ninguna truncada y sin cortar por el tope) que cubre la
 * ventana de la fila y no ve NINGUNA llamada desconocida, una `ambiguous` sin
 * ids del proveedor y más vieja que el plazo de gracia pasa a
 * `failed_to_start`, con su `reconciled` (`ambiguous_resolved`, la pasada y la
 * ventana como evidencia). Si no, se queda `ambiguous` y el resumen dice por
 * qué (`ambiguous.held`). Hasta la migración E19-3, `schema_pending`; y si
 * el esquema niega la transición que el código cree admitida (un despliegue
 * en mal orden), también `schema_pending`, y la pasada sigue (C-0).
 *
 * Una saliente CORTADA mientras el job esperaba al proveedor y que el
 * proveedor inició igual (E10-5, SEC8-b M-3): su SID está en su evento
 * `outbound_started` (la fila `canceled` no admite ids, DB8). FALLA CERRADO
 * (E12-5, B8-e): es CONOCIDA solo si su ÚLTIMO colgado del sistema se confirmó
 * (`hung_up` o `not_found`, {@see VoiceHangup::AFTER_CANCEL_CONFIRMED}). Si no
 * (falló, no se sabe si surtió efecto, o nunca se colgó porque el proceso
 * murió), pudo seguir hasta su máximo: sigue el camino de una desconocida y
 * echa el freno con la clave del job (no duplica la apertura), y su precio
 * queda en su fila (lo real de una cortada cuenta, E10-8). Si Twilio la enseña
 * VIVA, antes se vuelve a colgar fuera de la transacción
 * ({@see reconcileCall()}); en seco no se cuelga y cuenta como si fallara.
 *
 * EN SECO (`$dryRun`, E10-9; `voice:reconcile --dry-run`): lee lo mismo
 * (también los GET a los proveedores) y decide lo mismo, pero NO ESCRIBE NADA
 * (ni filas, ni eventos, ni freno, ni incidentes, ni la caché del job) y sin
 * cerrojos; {@see dryRunReport()} dice lo que habría hecho.
 *
 * LA DERIVA DEL NÚMERO (E2-3; Enmienda 4, E4-8; {@see numbers()}, la pide
 * `voice:reconcile --numbers`): el agente al que apunta el número importado en
 * ElevenLabs (`describeNumber`, solo GET) frente al que debe atenderlo, el de
 * la parte `canary` de la cadena (en el H8 las entrantes son solo de canarios,
 * E2-8; sin entrada o con la parte apagada, ninguno). Si difieren: freno
 * (`number_drift`, que la base guarda como `agent_drift`) e incidente. La
 * iniciación lo vigila además llamada a llamada (DF-E12). Un número «Outbound
 * Only» (B8-g: declarado con `VOICE_NUMBER_INBOUND_CAPABLE=false` o con
 * `supports_inbound = false` en el proveedor) no se puede asignar a ningún
 * agente: sin agente es `outbound_only` y no abre nada; un agente ajeno en él
 * sigue siendo deriva.
 */
final class VoiceReconciler
{
    public const STALE_REQUESTED_MINUTES = 15;

    public const STUCK_DIALING_MINUTES = 10;

    public const MAX_PAGES = 10;

    /** El estado de Twilio → el de `voice_calls` (DF-E14). */
    public const CARRIER_STATUS = [
        'queued' => VoiceCall::STATUS_DIALING,
        'ringing' => VoiceCall::STATUS_DIALING,
        'in-progress' => VoiceCall::STATUS_DIALING,
        'completed' => VoiceCall::STATUS_COMPLETED,
        'busy' => VoiceCall::STATUS_BUSY,
        'no-answer' => VoiceCall::STATUS_NO_ANSWER,
        'failed' => VoiceCall::STATUS_FAILED,
        'canceled' => VoiceCall::STATUS_FAILED,
    ];

    public const CARRIER_FINAL = ['completed', 'busy', 'no-answer', 'failed', 'canceled'];

    /** E10-9: ¿en seco? Lo fija cada pasada ({@see run()}, {@see numbers()}). */
    private bool $dryRun = false;

    /** @var array{events: array<string, int>, brakes: list<array{reason: string, cause: string}>, transitions: int, costs: int} lo que habría hecho en seco */
    private array $would = ['events' => [], 'brakes' => [], 'transitions' => 0, 'costs' => 0];

    /**
     * En seco, lo que la pasada habría cambiado de cada llamada (su estado y el
     * precio de Twilio que le sumaría), para que el techo de gasto y las
     * atascadas se calculen como después de la pasada de verdad.
     *
     * @var array<int, array{status?: string, carrierAdd?: int}>
     */
    private array $simulated = [];

    /** @var array<string, true> las claves del freno que esta pasada en seco ya abriría (una vez cada una, como `open()`) */
    private array $plannedKeys = [];

    /** @return array<string, mixed> el resumen (recuentos y resultados, sin datos personales) */
    public function run(TelephonyCarrier $carrier, VoiceAgentDeployer $deployer, DateTimeInterface $since, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $this->simulated = [];
        $resumen = ['staleRequested' => $this->staleRequested(), 'carrier' => null, 'known' => 0, 'legs' => 0, 'unknown' => 0, 'pages' => 0,
            'truncated' => false, 'drift' => [], 'stuckDialing' => 0, 'costCap' => false, 'ambiguous' => ['resolved' => 0, 'held' => []]];

        // E19-3: ¿la pasada vio la lista ENTERA? Todas las páginas listadas, ninguna truncada, y sin cortar por el tope.
        $completa = true;
        $token = null;
        do {
            $pagina = $carrier->listCalls($since, $token);
            $resumen['carrier'] = $pagina->outcome;
            $resumen['pages']++;
            if ($pagina->outcome !== CarrierCallsPage::OUTCOME_LISTED) {
                $completa = false;
                break;
            }
            foreach ($pagina->calls as $llamada) {
                $resumen[$this->reconcileCall($carrier, $llamada)]++;
            }
            $resumen['truncated'] = $pagina->truncated;
            $completa = $completa && ! $pagina->truncated;
            $token = $pagina->nextPageToken;
        } while ($token !== null && $resumen['pages'] < self::MAX_PAGES);
        $completa = $completa && $token === null;

        $resumen['ambiguous'] = $this->unstartedAmbiguous($completa, $since, $resumen['unknown'], $resumen['pages']);

        $resumen['drift'] = $this->drift($deployer);
        $atascadas = VoiceCall::query()->where('status', VoiceCall::STATUS_DIALING)
            ->where('status_changed_at', '<', now()->subMinutes(self::STUCK_DIALING_MINUTES));
        $salen = array_keys(array_filter($this->simulated, static fn (array $s): bool => isset($s['status'])));
        if ($salen !== []) {
            $atascadas->whereNotIn('id', $salen);   // en seco: las que esta pasada sacaría de `dialing`
        }
        $resumen['stuckDialing'] = $atascadas->count();
        $resumen['costCap'] = $this->costCap();
        Log::channel('voice')->info('voice.reconcile', $this->dryRun ? $resumen + ['dryRun' => true, 'would' => $this->would] : $resumen);

        return $resumen;
    }

    /**
     * E10-9: lo que las pasadas en seco de esta instancia habrían hecho, y se
     * vacía. `wouldOpenBrake`: alguna apertura (cada una, con su incidente).
     *
     * @return array{dryRun: true, wouldOpenBrake: bool, would: array{brakes: list<array{reason: string, cause: string}>, incidents: int, events: array<string, int>, transitions: int, costs: int}}
     */
    public function dryRunReport(): array
    {
        $w = $this->would;
        $this->would = ['events' => [], 'brakes' => [], 'transitions' => 0, 'costs' => 0];
        $this->plannedKeys = [];
        ksort($w['events']);

        return ['dryRun' => true, 'wouldOpenBrake' => $w['brakes'] !== [], 'would' => [
            'brakes' => $w['brakes'], 'incidents' => count($w['brakes']), 'events' => $w['events'], 'transitions' => $w['transitions'], 'costs' => $w['costs'],
        ]];
    }

    /**
     * Una llamada de Twilio, cada una en su transacción ({@see one()}; en seco
     * solo se lee: ni transacción ni cerrojos).
     *
     * E12-5 (B8-e): si es una saliente CORTADA que el proveedor inició igual y
     * Twilio la enseña VIVA, antes se vuelve a colgar, FUERA de la transacción
     * (sin cerrojo durante la red); si no se confirma,
     * {@see VoiceHangup::startedAfterCancel()} echa el freno, y one() decide con
     * el colgado que quedó. Ninguna fila lleva su SID (la cortada no admite
     * ids) y la vía de las ambiguas no puede tenerlo: cada SID sale de la
     * respuesta del proveedor a UNA fila, y esa fila está cortada. En seco no se
     * cuelga (colgar no es una lectura): se apunta el `hangup_requested` que
     * habría y one() la cuenta como si el colgado fallara (en el sentido seguro).
     *
     * @return 'known'|'legs'|'unknown'
     */
    private function reconcileCall(TelephonyCarrier $carrier, CarrierCall $c): string
    {
        if (! in_array($c->status, self::CARRIER_FINAL, true) && VoiceCall::query()->where('provider_call_sid', $c->sid)->doesntExist()
            && ($cortada = $this->canceledStartFor($c->sid, false)) !== null) {
            if ($this->dryRun) {
                $this->would['events']['hangup_requested'] = ($this->would['events']['hangup_requested'] ?? 0) + 1;
            } else {
                VoiceHangup::startedAfterCancel($cortada, $c->sid, $carrier, VoiceHangup::WHERE_RECONCILE);
            }
        }

        return $this->dryRun ? $this->one($c) : DB::transaction(fn (): string => $this->one($c));
    }

    /** @return 'known'|'legs'|'unknown' */
    public function one(CarrierCall $c): string
    {
        $vistos = [];
        $fila = self::lockedWithMarks(VoiceCall::query()->where('provider_call_sid', $c->sid), $c, 'cost:', $vistos, ! $this->dryRun)
            ?? $this->ambiguousFor($c->sid);
        // E12-5 (B8-e): la cortada que el proveedor inició igual es conocida SOLO con su último colgado confirmado.
        $cortada = $fila === null ? $this->canceledStartFor($c->sid) : null;
        $colgado = $cortada === null ? null : $this->afterCancelResult($cortada, $c);
        if ($cortada !== null && in_array($colgado, VoiceHangup::AFTER_CANCEL_CONFIRMED, true)) {
            [$fila, $cortada] = [$cortada, null];
        }
        if ($fila === null && $cortada === null && $c->parentSid !== null) {
            $madre = self::lockedWithMarks(VoiceCall::query()->where('provider_call_sid', $c->parentSid)->whereNull('transfer_of_call_id'), $c, 'leg:', $vistos, ! $this->dryRun);
            if ($madre !== null) {
                $this->cost($madre, $c, 'leg:', $vistos);

                return 'legs';
            }
        }
        if ($fila === null) {
            $evento = $this->event('unknown_provider_call', null, VoiceCallEvent::PROVIDER_TWILIO, $c->sid, [
                'outcome' => (string) $c->status, 'codes' => ['direction' => $c->direction, 'carrierStatus' => $c->status],
            ]);
            if ($cortada !== null) {
                // E12-5 (B8-e): sin un colgado confirmado, freno con la clave del job (no duplica) y su precio en su fila
                // (lo real de una cortada cuenta). Viva, el colgado de ahora es evidencia nueva: rearma uno ya soltado (de
                // verdad ya lo hizo el reintento de reconcileCall(); aquí, en seco, se anuncia). Terminada, cada clave una vez.
                $viva = ! in_array($c->status, self::CARRIER_FINAL, true);
                $this->brake(VoiceHangup::BRAKE_CAUSE, VoiceHangup::brakeKey($c->sid), ['where' => 'reconcile', 'carrierStatus' => $c->status, 'outcome' => $colgado],
                    $viva ? VoiceBrake::observedAfter(now()) : null, $evento?->id === null ? null : (int) $evento->id, (int) $cortada->id);
                $this->cost($cortada, $c, 'cost:');
            } elseif ($evento !== null) {
                $this->brake('unknown_provider_call', 'unknown:'.$c->sid, ['where' => 'reconcile', 'direction' => $c->direction], null, $evento->id === null ? null : (int) $evento->id);
            }

            return 'unknown';
        }

        $antes = (string) $fila->status;
        $destino = self::CARRIER_STATUS[$c->status ?? ''] ?? null;
        $despues = $antes;
        if ($destino !== null && $destino !== $antes && VoiceCall::canTransition($antes, $destino)) {
            $valores = [];
            if ($fila->provider_call_sid === null) {
                $valores['provider_call_sid'] = $c->sid;
            }
            if (in_array($destino, VoiceCall::FINAL, true)) {
                $valores['ended_at'] = now();
                if ($c->durationSeconds !== null && $fila->duration_seconds === null) {
                    $valores['duration_seconds'] = $c->durationSeconds;
                }
            }
            if ($this->transition($fila, [$antes], $destino, $valores)) {
                $despues = $destino;
            }
        }
        if (in_array($c->status, self::CARRIER_FINAL, true) && ! ($vistos['reconciled'] ?? false)) {
            $this->event('reconciled', (int) $fila->id, VoiceCallEvent::PROVIDER_TWILIO, $c->sid.':'.$c->status, [
                'outcome' => $despues, 'codes' => ['carrierStatus' => $c->status, 'before' => $antes, 'after' => $despues],
            ]);
        }
        $this->cost($fila, $c, 'cost:', $vistos);

        return 'known';
    }

    /**
     * La fila BLOQUEADA y, en la MISMA sentencia, dos marcas (PERF8 P8-7): si ya
     * están los eventos que la conciliación escribe una sola vez para esta
     * llamada de Twilio (`reconciled` del estado que enseña y el
     * `cost_recorded` de su precio, `cost:` o `leg:`). En régimen ya están:
     * sin las marcas, cada pasada intentaba dos INSERT por llamada que el
     * UNIQUE rechazaba (su punto de guardado, su excepción y su vuelta atrás;
     * perfilado, el 80 % del tiempo de la pasada). Los eventos son de solo
     * inserción: una marca a «sí» no envejece; una a «no» que se quede vieja
     * (otra conciliación a la vez) vuelve al camino de siempre, donde el
     * repetido devuelve null. Las marcas no son columnas: salen de la fila.
     *
     * @param  array{reconciled?: bool, cost?: bool}  $seen  las marcas (salida)
     * @param  bool  $lock  sin cerrojo solo en seco (E10-9), que no escribe
     */
    private static function lockedWithMarks(Builder $query, CarrierCall $c, string $costPrefix, array &$seen, bool $lock = true): ?VoiceCall
    {
        $existe = 'EXISTS (SELECT 1 FROM voice_call_events e WHERE e.provider = ? AND e.kind = ? AND e.provider_event_key = ?)';
        $query = $query->select('voice_calls.*')
            ->selectRaw("{$existe} AS seen_reconciled", [VoiceCallEvent::PROVIDER_TWILIO, 'reconciled', $c->sid.':'.$c->status])
            ->selectRaw("{$existe} AS seen_cost", [VoiceCallEvent::PROVIDER_TWILIO, 'cost_recorded', $costPrefix.$c->sid]);
        $fila = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $fila instanceof VoiceCall) {
            return null;
        }
        $seen = ['reconciled' => (bool) $fila->getAttribute('seen_reconciled'), 'cost' => (bool) $fila->getAttribute('seen_cost')];
        $fila->setRawAttributes(array_diff_key($fila->getAttributes(), ['seen_reconciled' => true, 'seen_cost' => true]), true);

        return $fila;
    }

    /** La ambigua cuyo evento guardó este SID (la fila ambigua no admite ids, DB8). */
    private function ambiguousFor(string $sid): ?VoiceCall
    {
        $evento = VoiceCallEvent::query()->where('kind', 'outbound_ambiguous')->whereNotNull('call_id')->orderByDesc('id')->limit(200)->get(['call_id', 'codes'])
            ->first(static fn (VoiceCallEvent $e): bool => is_array($e->codes) && ($e->codes['callSid'] ?? null) === $sid);

        return $evento === null ? null : $this->locked(VoiceCall::query()->whereKey($evento->call_id)->where('status', VoiceCall::STATUS_AMBIGUOUS));
    }

    /**
     * E10-5 (SEC8-b M-3): la saliente CORTADA (`canceled`) mientras el job
     * esperaba al proveedor, que el proveedor inició igual: el SID quedó en su
     * `outbound_started` (o en el `outbound_ambiguous` que trajo SID) con
     * `afterCancel`, porque la fila `canceled` no admite ids (DB8). Viva o
     * terminada: si es conocida lo decide su colgado (E12-5, B8-e,
     * {@see afterCancelResult()}). Con `$lock` false, sin cerrojo (lo lee
     * {@see reconcileCall()} fuera de la transacción).
     */
    private function canceledStartFor(string $sid, bool $lock = true): ?VoiceCall
    {
        $evento = VoiceCallEvent::query()->whereIn('kind', VoiceHangup::AFTER_CANCEL_START_KINDS)->whereNotNull('call_id')
            ->whereIn('call_id', VoiceCall::query()->select('id')->where('status', VoiceCall::STATUS_CANCELED))
            ->orderByDesc('id')->limit(200)->get(['call_id', 'codes'])
            ->first(static fn (VoiceCallEvent $e): bool => is_array($e->codes) && ($e->codes['afterCancel'] ?? false) === true && ($e->codes['callSid'] ?? null) === $sid);
        if ($evento === null) {
            return null;
        }
        $query = VoiceCall::query()->whereKey($evento->call_id)->where('status', VoiceCall::STATUS_CANCELED);

        return $lock ? $this->locked($query) : $query->first();
    }

    /**
     * E12-5 (B8-e): el resultado del último colgado del sistema de la cortada
     * con este SID (null: nunca se colgó). En seco y con la llamada viva, null:
     * la pasada de verdad volvería a colgarla antes de decidir, y en seco no se
     * cuelga; cuenta como si fallara (en el sentido seguro).
     */
    private function afterCancelResult(VoiceCall $cortada, CarrierCall $c): ?string
    {
        if ($this->dryRun && ! in_array($c->status, self::CARRIER_FINAL, true)) {
            return null;
        }

        return VoiceHangup::lastAfterCancelResult((int) $cortada->id, $c->sid);
    }

    /** La fila con su cerrojo; en seco, sin él (E10-9). */
    private function locked(Builder $query): ?VoiceCall
    {
        $fila = ($this->dryRun ? $query : $query->lockForUpdate())->first();

        return $fila instanceof VoiceCall ? $fila : null;
    }

    /**
     * Un evento de la conciliación. En seco no se escribe: devuelve un evento
     * SIN GUARDAR si sería nuevo (o null si ya está, como el repetido) y lo
     * apunta en {@see $would}.
     *
     * @param  array<string, mixed>  $extra
     */
    private function event(string $kind, ?int $callId, string $provider, string $key, array $extra = []): ?VoiceCallEvent
    {
        if (! $this->dryRun) {
            return VoiceEvents::record($kind, $callId, $provider, $key, $extra);
        }
        if (VoiceEvents::exists($kind, $provider, $key)) {
            return null;
        }
        $this->would['events'][$kind] = ($this->would['events'][$kind] ?? 0) + 1;

        return new VoiceCallEvent;
    }

    /**
     * Una transición de la conciliación; en seco, si la habría hecho (la
     * fila sigue en uno de `$from` y el grafo lo admite).
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    private function transition(VoiceCall $fila, array $from, string $to, array $values = []): bool
    {
        if (! $this->dryRun) {
            return VoiceCall::transition((int) $fila->id, $from, $to, $values);
        }
        $haria = in_array((string) $fila->status, $from, true) && VoiceCall::canTransition((string) $fila->status, $to);
        if ($haria) {
            $this->would['transitions']++;
            $this->simulated[(int) $fila->id]['status'] = $to;
        }

        return $haria;
    }

    /**
     * Abre el freno (con `$changedAfterRelease`, la causa se rearma: E10-6).
     * Devuelve si se abrió; en seco, si se abriría (y lo apunta).
     *
     * @param  array<string, int|string|bool|null|list<mixed>>  $evidence
     * @param  (Closure(VoiceBrakeEntry, VoiceBrakeEntry): bool)|null  $changedAfterRelease
     */
    private function brake(string $cause, string $key, array $evidence, ?Closure $changedAfterRelease, ?int $eventId = null, ?int $callId = null): bool
    {
        if ($this->dryRun) {
            $abriria = ! isset($this->plannedKeys[$key]) && VoiceBrake::nextOpening($key, $changedAfterRelease) !== null;
            if ($abriria) {
                $this->plannedKeys[$key] = true;
                $this->would['brakes'][] = ['reason' => VoiceBrake::TRIGGERS[$cause] ?? $cause, 'cause' => $cause];
            }

            return $abriria;
        }

        return ($changedAfterRelease === null
            ? VoiceBrake::open($cause, $key, $evidence, $callId, $eventId)
            : VoiceBrake::openOrRearm($cause, $key, $evidence, $changedAfterRelease, $callId, $eventId)) !== null;
    }

    /**
     * El precio de Twilio (valor absoluto, DF-W6) en un `cost_recorded`, y el
     * coste del operador = la suma de los suyos. Si la marca de
     * {@see lockedWithMarks()} dice que ya está, nada (lo mismo que cuando el
     * repetido devolvía null).
     *
     * @param  array{reconciled?: bool, cost?: bool}  $seen
     */
    private function cost(VoiceCall $fila, CarrierCall $c, string $prefijo, array $seen = []): void
    {
        if ($c->price === null) {
            return;   // sin tarificar todavía: la siguiente pasada lo recoge
        }
        if ($seen['cost'] ?? false) {
            return;
        }
        $micros = VoiceCostGuard::budgetMicros(ltrim($c->price, '-'));
        $nuevo = $this->event('cost_recorded', (int) $fila->id, VoiceCallEvent::PROVIDER_TWILIO, $prefijo.$c->sid, [
            'outcome' => $prefijo === 'leg:' ? 'transfer_leg' : 'carrier_price', 'codes' => ['micros' => $micros, 'unit' => $c->priceUnit],
        ]);
        if ($nuevo === null) {
            return;
        }
        if ($this->dryRun) {
            $this->would['costs']++;
            $this->simulated[(int) $fila->id]['carrierAdd'] = ($this->simulated[(int) $fila->id]['carrierAdd'] ?? 0) + $micros;

            return;
        }
        $total = 0;
        foreach (VoiceCallEvent::query()->where('call_id', $fila->id)->where('kind', 'cost_recorded')->where('provider', VoiceCallEvent::PROVIDER_TWILIO)->get(['codes']) as $e) {
            $total += is_array($e->codes) && is_int($e->codes['micros'] ?? null) ? $e->codes['micros'] : 0;
        }
        VoiceCall::bind((int) $fila->id, ['carrier_cost_micros' => $total]);
    }

    /**
     * E19-3 (H-1): las `ambiguous` que NO salieron, con las reglas de
     * {@see VoiceAmbiguousResolution} y la evidencia de ESTA pasada. Cada una,
     * en su transacción y con su cerrojo (las reglas de la fila se vuelven a
     * mirar sobre la fila bloqueada: una post-llamada o otra pasada pudo
     * adelantarse); en seco, lo que haría. Como mucho 50 por pasada.
     *
     * @return array{resolved: int, held: array<string, int>}
     */
    private function unstartedAmbiguous(bool $completa, DateTimeInterface $since, int $desconocidas, int $paginas): array
    {
        $out = ['resolved' => 0, 'held' => []];
        $ahora = now();
        // En seco: las que esta misma pasada habría pasado a `ambiguous` (sin job) lo serían desde ahora, dentro del plazo de
        // gracia, como de verdad; y las que ya saca por su SID no se miran.
        $recienAmbiguas = count(array_filter($this->simulated, static fn (array $s): bool => ($s['status'] ?? null) === VoiceCall::STATUS_AMBIGUOUS));
        if ($recienAmbiguas > 0) {
            $out['held'][VoiceAmbiguousResolution::HELD_WITHIN_GRACE] = $recienAmbiguas;
        }
        $filas = VoiceCall::query()->where('status', VoiceCall::STATUS_AMBIGUOUS)->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereNull('transfer_of_call_id')->whereNotIn('id', array_keys($this->simulated))->orderBy('id')->limit(50)->get();
        foreach ($filas as $fila) {
            $motivo = VoiceAmbiguousResolution::rowHold($fila, $ahora) ?? match (true) {
                ! $completa => VoiceAmbiguousResolution::HELD_PASS_INCOMPLETE,
                $desconocidas > 0 => VoiceAmbiguousResolution::HELD_UNKNOWN_CALLS,
                ! VoiceAmbiguousResolution::windowCovered($fila, $since) => VoiceAmbiguousResolution::HELD_WINDOW,
                ! VoiceAmbiguousResolution::schemaAllows() => VoiceAmbiguousResolution::HELD_SCHEMA,
                default => null,
            };
            if ($motivo !== null) {
                $out['held'][$motivo] = ($out['held'][$motivo] ?? 0) + 1;

                continue;
            }
            $evidencia = [
                'pass' => ['since' => self::utc($since), 'until' => self::utc($ahora), 'pages' => $paginas, 'carrier' => CarrierCallsPage::OUTCOME_LISTED, 'unknown' => 0],
                'window' => ['requestedAt' => self::utc($fila->created_at), 'ambiguousAt' => self::utc($fila->status_changed_at),
                    'graceMinutes' => VoiceAmbiguousResolution::GRACE_MINUTES, 'skewMinutes' => VoiceAmbiguousResolution::CLOCK_SKEW_MINUTES],
                'before' => VoiceCall::STATUS_AMBIGUOUS, 'after' => VoiceAmbiguousResolution::FINAL,
            ];
            try {
                $cerrada = $this->dryRun ? $this->resolveUnstarted($fila, $evidencia) : DB::transaction(fn (): bool => $this->resolveUnstarted($fila, $evidencia));
            } catch (QueryException $e) {
                // C-0 (revisión de security del 22-11-2026): el código cree admitida la transición (lee el grafo del
                // modelo) y el esquema la niega (sin la migración E19-3, o con su down() aplicado antes que el código).
                // La transacción ya se deshizo: la fila sigue `ambiguous` y viva (falla cerrado). Se cuenta como
                // `schema_pending` y la pasada SIGUE, para que la deriva y el techo de gasto corran igual. Cualquier otro
                // fallo de la base no es esto y sube, como antes.
                if (! self::schemaRefused($e)) {
                    throw $e;
                }
                $out['held'][VoiceAmbiguousResolution::HELD_SCHEMA] = ($out['held'][VoiceAmbiguousResolution::HELD_SCHEMA] ?? 0) + 1;
                Log::channel('voice')->warning('voice.reconcile.ambiguous_schema_pending', ['call_uuid' => $fila->uuid, 'sqlstate' => self::sqlState($e)]);

                continue;
            }
            if ($cerrada) {
                $out['resolved']++;
                if (! $this->dryRun) {
                    Log::channel('voice')->warning('voice.reconcile.ambiguous_resolved', ['call_uuid' => $fila->uuid, 'pages' => $paginas]);
                }
            }
        }
        if ($out['held'] !== []) {
            Log::channel('voice')->info('voice.reconcile.ambiguous_held', ['held' => $out['held']] + ($this->dryRun ? ['dry_run' => true] : []));
        }

        return $out;
    }

    /**
     * E19-3: UNA `ambiguous` que no salió, a `failed_to_start` con su
     * `reconciled`. Sobre la fila BLOQUEADA y con sus reglas otra vez; en seco,
     * si lo haría (y lo apunta).
     *
     * @param  array<string, mixed>  $evidencia
     */
    private function resolveUnstarted(VoiceCall $fila, array $evidencia): bool
    {
        $bloqueada = $this->locked(VoiceCall::query()->whereKey($fila->id)->where('status', VoiceCall::STATUS_AMBIGUOUS));
        if ($bloqueada === null || VoiceAmbiguousResolution::rowHold($bloqueada, now()) !== null) {
            return false;
        }
        if (! $this->transition($bloqueada, [VoiceCall::STATUS_AMBIGUOUS], VoiceAmbiguousResolution::FINAL, VoiceAmbiguousResolution::transitionValues())) {
            return false;
        }
        $this->event('reconciled', (int) $bloqueada->id, VoiceCallEvent::PROVIDER_LARAVEL, 'unstarted:'.$bloqueada->uuid, [
            'outcome' => VoiceAmbiguousResolution::OUTCOME, 'codes' => $evidencia,
        ]);

        return true;
    }

    /**
     * C-0: ¿la negó una guarda del esquema de la voz? Son las de DB8: un
     * disparador `voice_forbid()` (PostgreSQL, SQLSTATE `P0001`; SQLite,
     * `RAISE(ABORT, …)`, `23000`) o un CHECK (PostgreSQL, `23514`).
     */
    private static function schemaRefused(QueryException $e): bool
    {
        return in_array(self::sqlState($e), ['P0001', '23514', '23000'], true);
    }

    /** El SQLSTATE de un fallo de la base (un código de cinco caracteres, nunca su mensaje: lleva el SQL). */
    private static function sqlState(QueryException $e): string
    {
        $estado = (string) ($e->errorInfo[0] ?? $e->getCode());

        return preg_match('/^[0-9A-Z]{5}$/D', $estado) === 1 ? $estado : 'unknown';
    }

    /** Un instante en UTC, ISO 8601 (la evidencia de E19-3). */
    private static function utc(?DateTimeInterface $t): ?string
    {
        return $t === null ? null : \DateTimeImmutable::createFromInterface($t)->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /** Una `requested` vieja perdió su job: `ambiguous` (pudo haber salido). En seco, cuántas pasarían. */
    private function staleRequested(): int
    {
        $n = 0;
        foreach (VoiceCall::query()->where('status', VoiceCall::STATUS_REQUESTED)->where('created_at', '<', now()->subMinutes(self::STALE_REQUESTED_MINUTES))->get(['id', 'uuid', 'status']) as $fila) {
            if ($this->transition($fila, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_AMBIGUOUS, ['failure_code' => 'job_lost'])) {
                $this->event('outbound_ambiguous', (int) $fila->id, VoiceCallEvent::PROVIDER_LARAVEL, 'lost:'.$fila->uuid, ['outcome' => 'job_lost']);
                $n++;
            }
        }

        return $n;
    }

    /**
     * La deriva de los agentes vigentes (D8-21): la proyección observada frente
     * a la compilada. Solo GET; con el proveedor sin configurar, sin red.
     *
     * @return array<string, string> parte → resultado
     */
    private function drift(VoiceAgentDeployer $deployer): array
    {
        $out = [];
        foreach (VoicePublishedConfig::read() as $nombre => $parte) {
            if (! $parte->ok()) {
                $out[$nombre] = $parte->status;

                continue;
            }
            $observada = now();
            $o = $deployer->describeAgent((string) $parte->providerAgentId);
            if ($o->outcome !== VoiceAgentObservation::OUTCOME_OBSERVED) {
                $out[$nombre] = 'unobserved_'.$o->outcome;

                continue;
            }
            $deriva = ! hash_equals((string) $parte->projectionHash, (string) $o->projectionHash);
            if (! $this->dryRun) {
                Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId, $deriva, OutboundGate::DRIFT_CACHE_SECONDS);
            }
            if ($deriva) {
                // Una observación nueva, posterior a una suelta, la rearma (E10-6).
                $this->brake('agent_drift', 'drift:'.$parte->providerAgentId, ['where' => 'reconcile', 'seq' => $parte->seq, 'part' => $nombre], VoiceBrake::observedAfter($observada));
            }
            $out[$nombre] = $deriva ? 'drift' : 'ok';
        }

        return $out;
    }

    /** La parte de la cadena cuyo agente atiende el número (las entrantes del H8 son de canarios, E2-8). */
    public const NUMBER_PART = VoicePublishedConfig::PART_CANARY;

    public const NUMBER_OK = 'ok';

    public const NUMBER_DRIFT = 'drift';

    public const NUMBER_NOT_CONFIGURED = 'not_configured';

    /** La cadena no se puede leer: no se sabe qué agente debería atender (ya deniega todo, D8-8). */
    public const NUMBER_EXPECTED_UNKNOWN = 'expected_unknown';

    /**
     * B8-g: el número es «Outbound Only» (caller ID verificado en Twilio:
     * ElevenLabs no lo deja asignar a ningún agente ni recibe llamadas) y no
     * tiene agente. Las salientes usan el agente sellado de su entrada, nunca
     * el del número: no es deriva y no abre el freno.
     */
    public const NUMBER_OUTBOUND_ONLY = 'outbound_only';

    /** SEC8-c B-1: el número importado (con el que llama ElevenLabs) no es `TWILIO_VOICE_FROM`. Freno. */
    public const NUMBER_MISMATCH = 'number_mismatch';

    /** SEC8-c B-1: el número importado es uno de la mensajería del motor (protección 6), diga lo que diga `TWILIO_VOICE_FROM`. Freno. */
    public const NUMBER_IS_MESSAGING = 'number_is_messaging';

    /** Los resultados del número que abren el freno (y hacen salir el comando con 1). */
    public const NUMBER_BRAKING = [self::NUMBER_DRIFT, self::NUMBER_MISMATCH, self::NUMBER_IS_MESSAGING];

    /**
     * La deriva del número (E2-3, E4-8). Solo GET; sin el id del número
     * importado o sin el proveedor configurado, sin red.
     *
     * Un número SOLO SALIENTE (B8-g; declarado `false` en
     * `voice.number.inbound_capable` o con `supports_inbound = false` en el
     * proveedor) sin agente es {@see NUMBER_OUTBOUND_ONLY}: la falta del agente
     * de la parte canaria no es deriva, porque no se le puede asignar. Con un
     * agente, la regla de siempre (el publicado, bien; otro, deriva). El
     * resumen lleva los dos: `inboundCapable` (lo que dice el proveedor; null si
     * no lo dice) e `inboundDeclared` (lo declarado; null sin declarar).
     *
     * En seco (E10-9), `brakeOpened` es false y `wouldOpenBrake` dice si se
     * abriría (y queda en {@see dryRunReport()}).
     *
     * Antes que la asignación, la IDENTIDAD del número (SEC8-c B-1): el E.164
     * con el que llama ElevenLabs tiene que ser `TWILIO_VOICE_FROM`
     * (`number_mismatch`) y no uno de la mensajería (`number_is_messaging`, protección
     * 6); si no, freno. El veredicto (nunca el número) queda en la caché para la
     * política (`VoiceNumberGuard::rememberObservation()`); en seco, no.
     *
     * Enmienda del 22-11-2026 (protección 6 y D8-5, por decisión expresa del
     * propietario): el número empresarial NO echa el freno `number_is_messaging`
     * si la identidad compartida se ADMITE con esta misma observación
     * (`VoiceNumberGuard::sharedVerdict()`: la declaración, que `TWILIO_VOICE_FROM`
     * y el importado sean el visible de la mensajería y el mismo, solo saliente declarado
     * y observado, entrantes apagadas y sin agente); entonces es
     * `outbound_only`. Con cualquiera sin cumplir, el freno de siempre. El
     * resumen lo dice en `sharedWithMessaging` (null sin observación) y la caché
     * guarda también los tres hechos del proveedor (en seco, nada).
     *
     * @return array{result: string, observed: string, expected: string, seq: ?int, outcome: ?string, inboundCapable: ?bool, inboundDeclared: ?bool, matchesFrom: ?bool, collidesWithMessaging: ?bool, sharedWithMessaging: array{declared: bool, admitted: bool, reason: ?string}|null, brakeOpened: bool, wouldOpenBrake?: bool}
     */
    public function numbers(VoiceNumberObserver $observer, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $id = config('voice.elevenlabs.agent_phone_number_id');
        $parte = VoicePublishedConfig::read()[self::NUMBER_PART];
        $esperado = $parte->ok() ? $parte->providerAgentId : null;
        $esperadoEstado = $parte->status === VoicePublishedPart::STATUS_UNREADABLE ? VoiceNumberAssignment::STATE_UNKNOWN
            : ($esperado === null ? VoiceNumberAssignment::STATE_UNASSIGNED : VoiceNumberAssignment::STATE_ASSIGNED);
        $declarado = config('voice.number.inbound_capable');
        $declarado = is_bool($declarado) ? $declarado : null;
        $resumen = ['result' => self::NUMBER_NOT_CONFIGURED, 'observed' => VoiceNumberAssignment::STATE_UNKNOWN, 'expected' => $esperadoEstado,
            'seq' => $parte->seq, 'outcome' => null, 'inboundCapable' => null, 'inboundDeclared' => $declarado,
            'matchesFrom' => null, 'collidesWithMessaging' => null, 'sharedWithMessaging' => null, 'brakeOpened' => false];
        if (! is_string($id) || trim($id) === '') {
            return $resumen;
        }

        $observada = now();
        $abre = false;
        $o = $observer->describeNumber(trim($id));
        $resumen['outcome'] = $o->outcome;
        $resumen['observed'] = $o->state();
        $resumen['inboundCapable'] = $o->inboundCapable;
        // SEC8-c M-2: un número «Outbound Only» (declarado o visto) se espera SIN agente: publicar no lo asigna y
        // ElevenLabs no lo deja asignar. Cualquier agente en él, también el publicado, es deriva.
        $soloSaliente = $declarado === false || $o->inboundCapable === false;
        if ($soloSaliente) {
            $esperado = null;
            $resumen['expected'] = VoiceNumberAssignment::STATE_UNASSIGNED;
        }
        // SEC8-c B-1: el número con el que llama ElevenLabs (solo en memoria) frente a `TWILIO_VOICE_FROM` y a los de
        // WhatsApp. Se queda el veredicto, nunca el número: la política lo lee (VoiceNumberGuard::collidesForPolicy()).
        $desde = config('voice.twilio.from');
        $coincide = VoiceNumberGuard::sameNumber($o->phoneNumber(), VoicePhone::isE164($desde) ? $desde : null);
        $choca = $o->phoneNumber() !== null && VoiceNumberGuard::collidesWithMessaging($o->phoneNumber());
        $observado = $o->outcome === VoiceNumberAssignment::OUTCOME_OBSERVED;
        // Enmienda del 22-11-2026: la identidad compartida, con ESTA observación (fresca y del id configurado por construcción).
        // Ronda 2: la condición 2 sobre lo configurado (como VoiceNumberGuard::sharedIdentity()) y sobre lo observado.
        $visible = $observado ? VoiceNumberGuard::isBusinessNumber($o->phoneNumber()) : null;
        $configuracion = VoiceNumberGuard::sharedConfigFacts();
        $compartida = ['declared' => $configuracion['declared']] + VoiceNumberGuard::sharedVerdict($configuracion + [
            'businessNumber' => VoiceNumberGuard::isBusinessNumber(VoicePhone::isE164($desde) ? $desde : null),
            'observationVerified' => $observado && $coincide === true,
            'observedBusinessNumber' => $visible,
            'observedInboundCapable' => $o->inboundCapable,
            'observedAgentAssigned' => $observado ? $o->agentId !== null : null,
        ]);
        if ($observado) {
            $resumen['matchesFrom'] = $coincide;
            $resumen['collidesWithMessaging'] = $choca;
            $resumen['sharedWithMessaging'] = $compartida;
            if (! $this->dryRun) {
                VoiceNumberGuard::rememberObservation((string) $o->phoneNumberId, $coincide, $choca, $o->inboundCapable, $o->agentId !== null, $visible);
            }
        }
        $identidad = ['where' => 'reconcile', 'part' => self::NUMBER_PART, 'seq' => $parte->seq];
        if ($o->outcome !== VoiceNumberAssignment::OUTCOME_OBSERVED) {
            $resumen['result'] = 'unobserved_'.$o->outcome;
        } elseif ($choca && ! $compartida['admitted']) {
            // Protección 6: con el número de WhatsApp no se llama ni se atiende, aunque `TWILIO_VOICE_FROM` diga otro.
            // Enmienda del 22-11-2026: salvo la identidad compartida admitida, que sigue abajo y acaba en `outbound_only`.
            $resumen['result'] = self::NUMBER_IS_MESSAGING;
            $abre = $this->brake('number_is_messaging', 'number:'.$o->phoneNumberId.':messaging', $identidad, VoiceBrake::observedAfter($observada));
            $resumen['brakeOpened'] = $abre && ! $this->dryRun;
        } elseif ($coincide === false) {
            // El número importado no es el declarado: lo que se revisó (la guarda de WhatsApp, el expediente) no es lo que llama.
            $resumen['result'] = self::NUMBER_MISMATCH;
            $abre = $this->brake('number_mismatch', 'number:'.$o->phoneNumberId.':mismatch', $identidad, VoiceBrake::observedAfter($observada));
            $resumen['brakeOpened'] = $abre && ! $this->dryRun;
        } elseif (! $soloSaliente && $esperadoEstado === VoiceNumberAssignment::STATE_UNKNOWN) {
            $resumen['result'] = self::NUMBER_EXPECTED_UNKNOWN;
        } elseif ($o->agentId === $esperado) {
            $resumen['result'] = $soloSaliente ? self::NUMBER_OUTBOUND_ONLY : self::NUMBER_OK;
        } else {
            $resumen['result'] = self::NUMBER_DRIFT;
            // Una observación nueva, posterior a una suelta, la rearma (E10-6, SEC8-b B-2).
            $abre = $this->brake('number_drift', 'number:'.$o->phoneNumberId.':'.($o->agentId ?? 'none'), [
                'where' => 'reconcile', 'part' => self::NUMBER_PART, 'seq' => $parte->seq,
                'observedAgentId' => $o->agentId, 'expectedAgentId' => $esperado,
            ], VoiceBrake::observedAfter($observada));
            $resumen['brakeOpened'] = $abre && ! $this->dryRun;
        }
        if ($this->dryRun) {
            $resumen['wouldOpenBrake'] = $abre;
        }
        Log::channel('voice')->info('voice.reconcile.number', ['result' => $resumen['result'], 'observed' => $resumen['observed'], 'expected' => $resumen['expected']]
            + ($this->dryRun ? ['dry_run' => true] : []));

        return $resumen;
    }

    /**
     * El techo duro: lo comprometido de hoy MAYOR que el presupuesto Y MAYOR QUE
     * CERO abre el freno por coste (E10-8: con presupuesto 0 y solo denegadas,
     * que no comprometen gasto, no). Devuelve si se pasa del techo (el estado,
     * se abra ahora o no). Soltado, se rearma si lo comprometido supera lo
     * registrado en la apertura soltada (E10-6): la suelta no lleva evidencia
     * (`vbr_shape_chk`), así que lo registrado es el `committedMicros` de esa
     * apertura (≤ lo que había al soltar: en el sentido seguro).
     */
    private function costCap(): bool
    {
        $dia = VoiceCostGuard::budgetDay(now());
        $comprometido = VoiceCostGuard::committedMicros($dia) + $this->simulatedCommittedDelta($dia);
        $presupuesto = VoiceCostGuard::budgetFromConfig();
        if ($comprometido <= 0 || $comprometido <= $presupuesto) {
            return false;
        }
        $this->brake('cost_cap', 'cost_cap:'.$dia, ['day' => $dia, 'committedMicros' => $comprometido, 'budgetMicros' => $presupuesto],
            static fn (VoiceBrakeEntry $abierta): bool => $comprometido > (int) ($abierta->evidence['committedMicros'] ?? 0));

        return true;
    }

    /**
     * En seco (E10-9), cuánto cambiaría lo comprometido de `$dia` lo que la
     * pasada habría hecho (estados nuevos y precios de Twilio sumados), con la
     * misma regla ({@see VoiceCostGuard::contribution()}). De verdad, 0.
     */
    private function simulatedCommittedDelta(string $dia): int
    {
        if (! $this->dryRun || $this->simulated === []) {
            return 0;
        }
        $delta = 0;
        $filas = VoiceCall::query()->whereIn('id', array_keys($this->simulated))->where('budget_day', $dia)
            ->get(['id', 'status', 'reserved_micros', 'provider_cost_micros', 'carrier_cost_micros']);
        foreach ($filas as $f) {
            $s = $this->simulated[(int) $f->id];
            $proveedor = (int) $f->provider_cost_micros;
            $operador = (int) $f->carrier_cost_micros;
            if (isset($s['carrierAdd'])) {
                // Como cost(): el del operador pasa a ser la suma de TODOS sus precios de Twilio.
                $operador = $s['carrierAdd'] + (int) VoiceCallEvent::query()->where('call_id', $f->id)->where('kind', 'cost_recorded')
                    ->where('provider', VoiceCallEvent::PROVIDER_TWILIO)->get(['codes'])
                    ->sum(static fn (VoiceCallEvent $e): int => is_array($e->codes) && is_int($e->codes['micros'] ?? null) ? $e->codes['micros'] : 0);
            }
            $delta += VoiceCostGuard::contribution($s['status'] ?? (string) $f->status, (int) $f->reserved_micros, $proveedor + $operador)
                - VoiceCostGuard::contribution((string) $f->status, (int) $f->reserved_micros, $proveedor + (int) $f->carrier_cost_micros);
        }

        return $delta;
    }
}
