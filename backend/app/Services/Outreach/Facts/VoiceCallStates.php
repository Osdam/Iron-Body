<?php

namespace App\Services\Outreach\Facts;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA del ESTADO de las llamadas que pidió una
 * estrategia (contrato H9, D9-3 clase `integration`, D9-10; paquete V9). Lo
 * usa el normalizador de la voz (`Run\VoiceCallOutcomes`; los hechos no dependen de la ejecución)
 * para convertir lo que ya registró la telefonía del H8 en un resultado del
 * conjunto cerrado. Tablas: `voice_calls`, `voice_call_events` y
 * `voice_exclusions`; de cada una, solo códigos, instantes, cifras y
 * referencias: NUNCA la huella del teléfono, sus 4 dígitos ni ningún texto
 * (no hay transcripciones en Laravel; interpretar lo que se dijo es AI9).
 *
 * Lo que da de cada llamada, todo DETERMINISTA y ya escrito por el H8:
 *  - su estado (D8-13), el código de fallo o el motivo de la política, la
 *    duración y el coste real (proveedor + transportista, en micros) si llegó;
 *  - si el proveedor la aceptó (tiene `conversation_id` o `callSid`);
 *  - el veredicto de la guarda posterior (`guard_verdict`: `clean` o
 *    `violation`), si ya pasó la post-llamada;
 *  - si en la llamada se pidió no volver a llamar (la exclusión
 *    `spoken_opt_out` de esa llamada, Ley 2300) o hablar con una persona (la
 *    herramienta `solicitar_humano`).
 */
final class VoiceCallStates
{
    public const TABLES = ['voice_calls', 'voice_call_events', 'voice_exclusions'];

    /** El solicitante de las salientes de una estrategia (`OutboundGate::REQUESTER_STRATEGY`). */
    public const REQUESTER = 'strategy';

    /**
     * Las llamadas que pidió un trabajo, por su uuid (`client_request_id`):
     * para enlazar un trabajo que quedó `ambiguous` sin su referencia.
     *
     * @param  list<string>  $jobUuids
     * @return array<string, int> uuid del trabajo → id de la llamada
     */
    public function forStrategyJobs(array $jobUuids): array
    {
        $uuids = array_values(array_unique(array_map('strtolower', $jobUuids)));
        if ($uuids === []) {
            return [];
        }
        $salida = [];
        foreach (DB::table('voice_calls')->where('requested_by_type', self::REQUESTER)->whereIn('client_request_id', $uuids)
            ->orderBy('id')->get(['id', 'client_request_id']) as $f) {
            $salida[strtolower((string) $f->client_request_id)] ??= (int) $f->id;
        }

        return $salida;
    }

    /**
     * @param  list<int>  $callIds
     * @return array<int, array{uuid: string, status: string, failureCode: ?string, policyReason: ?string, durationSeconds: ?int, costMicros: ?int,
     *                          providerAccepted: bool, guardVerdict: ?string, spokenOptOut: bool, humanRequested: bool,
     *                          startedAt: ?DateTimeImmutable, endedAt: ?DateTimeImmutable, statusChangedAt: ?DateTimeImmutable}>
     */
    public function forCallIds(array $callIds): array
    {
        $ids = array_values(array_unique(array_filter($callIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        $salida = [];
        foreach (DB::table('voice_calls')->whereIn('id', $ids)->orderBy('id')->get([
            'id', 'uuid', 'status', 'failure_code', 'policy_reason', 'duration_seconds', 'provider_cost_micros', 'carrier_cost_micros',
            'provider_conversation_id', 'provider_call_sid', 'started_at', 'ended_at', 'status_changed_at',
        ]) as $c) {
            $proveedor = $c->provider_cost_micros === null ? null : (int) $c->provider_cost_micros;
            $transportista = $c->carrier_cost_micros === null ? null : (int) $c->carrier_cost_micros;
            $salida[(int) $c->id] = [
                'uuid' => (string) $c->uuid,
                'status' => (string) $c->status,
                'failureCode' => $c->failure_code === null ? null : (string) $c->failure_code,
                'policyReason' => $c->policy_reason === null ? null : (string) $c->policy_reason,
                'durationSeconds' => $c->duration_seconds === null ? null : (int) $c->duration_seconds,
                'costMicros' => $proveedor === null && $transportista === null ? null : (int) $proveedor + (int) $transportista,
                'providerAccepted' => $c->provider_conversation_id !== null || $c->provider_call_sid !== null,
                'guardVerdict' => null,
                'spokenOptOut' => false,
                'humanRequested' => false,
                'startedAt' => FactValues::instant($c->started_at),
                'endedAt' => FactValues::instant($c->ended_at),
                'statusChangedAt' => FactValues::instant($c->status_changed_at),
            ];
        }
        if ($salida === []) {
            return [];
        }
        $leidas = array_keys($salida);

        // La guarda posterior (el último veredicto) y `solicitar_humano`, por sus eventos (códigos, sin texto).
        foreach (DB::table('voice_call_events')->whereIn('call_id', $leidas)
            ->where(static fn ($w) => $w->where('kind', 'guard_verdict')->orWhere(static fn ($t) => $t->where('kind', 'tool_call')->where('tool', 'solicitar_humano')))
            ->orderBy('id')->get(['call_id', 'kind', 'outcome']) as $e) {
            $id = (int) $e->call_id;
            if ($e->kind === 'guard_verdict') {
                $salida[$id]['guardVerdict'] = in_array($e->outcome, ['clean', 'violation'], true) ? (string) $e->outcome : 'unknown';
            } else {
                $salida[$id]['humanRequested'] = true;
            }
        }
        // «No volver a llamar» dicho en ESA llamada (la exclusión que deja la herramienta, una por llamada).
        foreach (DB::table('voice_exclusions')->whereIn('call_id', $leidas)->where('source', 'spoken_opt_out')->distinct()->pluck('call_id') as $id) {
            $salida[(int) $id]['spokenOptOut'] = true;
        }

        return $salida;
    }
}
