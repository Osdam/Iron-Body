<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\TelephonyCarrier;
use App\Services\Voice\VoiceAgentDeployer;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA CONCILIACIÓN (contrato H8, D8-21; E1-2, E2-3; fixture
 * `twilio-webhook-bodies.json` §callsPage). Solo lee de los proveedores (GET):
 * las llamadas de la subcuenta en Twilio y la proyección de los agentes
 * vigentes en ElevenLabs.
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
 * pasa del presupuesto, freno por coste (el techo duro).
 *
 * PENDIENTE (E2-3, deriva del NÚMERO): leer a qué agente apunta el número
 * importado necesita un GET que las interfaces cerradas de D8-3 no tienen
 * (VoiceIsolationTest). Mientras, la iniciación lo vigila llamada a llamada
 * (DF-E12: un `agent_id` que no es el sellado abre el freno).
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

    /** @return array<string, mixed> el resumen (recuentos y resultados, sin datos personales) */
    public function run(TelephonyCarrier $carrier, VoiceAgentDeployer $deployer, DateTimeInterface $since): array
    {
        $resumen = ['staleRequested' => $this->staleRequested(), 'carrier' => null, 'known' => 0, 'legs' => 0, 'unknown' => 0, 'pages' => 0,
            'truncated' => false, 'drift' => [], 'stuckDialing' => 0, 'costCap' => false];

        $token = null;
        do {
            $pagina = $carrier->listCalls($since, $token);
            $resumen['carrier'] = $pagina->outcome;
            $resumen['pages']++;
            if ($pagina->outcome !== CarrierCallsPage::OUTCOME_LISTED) {
                break;
            }
            foreach ($pagina->calls as $llamada) {
                $resumen[DB::transaction(fn (): string => $this->one($llamada))]++;
            }
            $resumen['truncated'] = $pagina->truncated;
            $token = $pagina->nextPageToken;
        } while ($token !== null && $resumen['pages'] < self::MAX_PAGES);

        $resumen['drift'] = $this->drift($deployer);
        $resumen['stuckDialing'] = VoiceCall::query()->where('status', VoiceCall::STATUS_DIALING)
            ->where('status_changed_at', '<', now()->subMinutes(self::STUCK_DIALING_MINUTES))->count();
        $resumen['costCap'] = $this->costCap();
        Log::channel('voice')->info('voice.reconcile', $resumen);

        return $resumen;
    }

    /** @return 'known'|'legs'|'unknown' */
    public function one(CarrierCall $c): string
    {
        $fila = VoiceCall::query()->where('provider_call_sid', $c->sid)->lockForUpdate()->first() ?? $this->ambiguousFor($c->sid);
        if ($fila === null && $c->parentSid !== null) {
            $madre = VoiceCall::query()->where('provider_call_sid', $c->parentSid)->whereNull('transfer_of_call_id')->lockForUpdate()->first();
            if ($madre !== null) {
                $this->cost($madre, $c, 'leg:');

                return 'legs';
            }
        }
        if ($fila === null) {
            $evento = VoiceEvents::record('unknown_provider_call', null, VoiceCallEvent::PROVIDER_TWILIO, $c->sid, [
                'outcome' => (string) $c->status, 'codes' => ['direction' => $c->direction, 'carrierStatus' => $c->status],
            ]);
            if ($evento !== null) {
                VoiceBrake::open('unknown_provider_call', 'unknown:'.$c->sid, ['where' => 'reconcile', 'direction' => $c->direction], null, (int) $evento->id);
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
            if (VoiceCall::transition((int) $fila->id, [$antes], $destino, $valores)) {
                $despues = $destino;
            }
        }
        if (in_array($c->status, self::CARRIER_FINAL, true)) {
            VoiceEvents::record('reconciled', (int) $fila->id, VoiceCallEvent::PROVIDER_TWILIO, $c->sid.':'.$c->status, [
                'outcome' => $despues, 'codes' => ['carrierStatus' => $c->status, 'before' => $antes, 'after' => $despues],
            ]);
        }
        $this->cost($fila, $c, 'cost:');

        return 'known';
    }

    /** La ambigua cuyo evento guardó este SID (la fila ambigua no admite ids, DB8). */
    private function ambiguousFor(string $sid): ?VoiceCall
    {
        $evento = VoiceCallEvent::query()->where('kind', 'outbound_ambiguous')->whereNotNull('call_id')->orderByDesc('id')->limit(200)->get(['call_id', 'codes'])
            ->first(static fn (VoiceCallEvent $e): bool => is_array($e->codes) && ($e->codes['callSid'] ?? null) === $sid);

        return $evento === null ? null : VoiceCall::query()->whereKey($evento->call_id)->where('status', VoiceCall::STATUS_AMBIGUOUS)->lockForUpdate()->first();
    }

    /** El precio de Twilio (valor absoluto, DF-W6) en un `cost_recorded`, y el coste del operador = la suma de los suyos. */
    private function cost(VoiceCall $fila, CarrierCall $c, string $prefijo): void
    {
        if ($c->price === null) {
            return;   // sin tarificar todavía: la siguiente pasada lo recoge
        }
        $micros = VoiceCostGuard::budgetMicros(ltrim($c->price, '-'));
        $nuevo = VoiceEvents::record('cost_recorded', (int) $fila->id, VoiceCallEvent::PROVIDER_TWILIO, $prefijo.$c->sid, [
            'outcome' => $prefijo === 'leg:' ? 'transfer_leg' : 'carrier_price', 'codes' => ['micros' => $micros, 'unit' => $c->priceUnit],
        ]);
        if ($nuevo === null) {
            return;
        }
        $total = 0;
        foreach (VoiceCallEvent::query()->where('call_id', $fila->id)->where('kind', 'cost_recorded')->where('provider', VoiceCallEvent::PROVIDER_TWILIO)->get(['codes']) as $e) {
            $total += is_array($e->codes) && is_int($e->codes['micros'] ?? null) ? $e->codes['micros'] : 0;
        }
        VoiceCall::bind((int) $fila->id, ['carrier_cost_micros' => $total]);
    }

    /** Una `requested` vieja perdió su job: `ambiguous` (pudo haber salido). */
    private function staleRequested(): int
    {
        $n = 0;
        foreach (VoiceCall::query()->where('status', VoiceCall::STATUS_REQUESTED)->where('created_at', '<', now()->subMinutes(self::STALE_REQUESTED_MINUTES))->get(['id', 'uuid']) as $fila) {
            if (VoiceCall::transition((int) $fila->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_AMBIGUOUS, ['failure_code' => 'job_lost'])) {
                VoiceEvents::record('outbound_ambiguous', (int) $fila->id, VoiceCallEvent::PROVIDER_LARAVEL, 'lost:'.$fila->uuid, ['outcome' => 'job_lost']);
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
            $o = $deployer->describeAgent((string) $parte->providerAgentId);
            if ($o->outcome !== VoiceAgentObservation::OUTCOME_OBSERVED) {
                $out[$nombre] = 'unobserved_'.$o->outcome;

                continue;
            }
            $deriva = ! hash_equals((string) $parte->projectionHash, (string) $o->projectionHash);
            Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId, $deriva, OutboundGate::DRIFT_CACHE_SECONDS);
            if ($deriva) {
                VoiceBrake::open('agent_drift', 'drift:'.$parte->providerAgentId, ['where' => 'reconcile', 'seq' => $parte->seq, 'part' => $nombre]);
            }
            $out[$nombre] = $deriva ? 'drift' : 'ok';
        }

        return $out;
    }

    /** El techo duro: lo comprometido de hoy por encima del presupuesto abre el freno por coste. */
    private function costCap(): bool
    {
        $dia = VoiceCostGuard::budgetDay(now());
        $comprometido = VoiceCostGuard::committedMicros($dia);
        if ($comprometido <= VoiceCostGuard::budgetFromConfig() || $comprometido === 0) {
            return false;
        }
        VoiceBrake::open('cost_cap', 'cost_cap:'.$dia, ['day' => $dia, 'committedMicros' => $comprometido, 'budgetMicros' => VoiceCostGuard::budgetFromConfig()]);

        return true;
    }
}
