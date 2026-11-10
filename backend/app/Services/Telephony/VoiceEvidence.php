<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * La evidencia MEDIDA EN EL SERVIDOR de una publicación de voz (contrato H8,
 * D8-19 [P-16]; Enmienda 2, E2-6: el Studio la lee a través de este servicio
 * y nunca de las tablas `voice_*`). Solo recuentos y fechas; ni números, ni
 * textos, ni uuids de personas.
 *
 *  - {@see forPublication()} (Enmienda 4, E4-2; Enmienda 13, E13-3): lo que
 *    mide el Studio para promover de `canary` a `rest`, con exactamente siete
 *    claves, enteros ≥ 0.
 *  - {@see summary()}: los recuentos ricos de una entrada, para
 *    `GET admin/voice/metrics` (telefonía).
 */
final class VoiceEvidence
{
    /** Las seis claves de E4-2 y la séptima de E13-3 (`missingPostCall`, la última), en su orden. */
    public const KEYS = ['calls', 'guardViolations', 'sealMismatches', 'driftEvents', 'unknownProviderCalls', 'budgetBreaches', 'missingPostCall'];

    /**
     * E13-3: la post-llamada PROCESADA de una conversación es su veredicto de
     * la guarda posterior (`VoicePostCall` lo escribe al recorrer la
     * transcripción, con o sin violaciones). Solo con él una llamada es
     * evidencia de cómo se porta el agente.
     */
    public const POST_CALL_PROCESSED = 'guard_verdict';

    /**
     * E13-3: la post-llamada de una llamada que NO llegó a conectar: el
     * proveedor dice que no hubo conversación (comunicando, sin respuesta u
     * otro fallo al iniciarla), así que no habrá transcripción que esperar.
     */
    public const POST_CALL_NO_CONVERSATION = 'call_initiation_failure';

    /**
     * E13-3: los estados que, por sí solos, dicen que no hubo conversación
     * (también cuando los pone la conciliación desde Twilio): nunca tendrán
     * transcripción, así que no cuentan como post-llamada que falta.
     */
    public const NEVER_CONNECTED = [VoiceCall::STATUS_BUSY, VoiceCall::STATUS_NO_ANSWER, VoiceCall::STATUS_FAILED];

    /**
     * Las que nunca llegaron al agente del proveedor (DB8,
     * `vc_provider_binding_chk`: no llevan ningún id del proveedor) y la
     * entrante DENEGADA (E1-2): no son evidencia de cómo se porta el agente.
     */
    public const NOT_EVIDENCE = [VoiceCall::STATUS_DENIED, VoiceCall::STATUS_REQUESTED, VoiceCall::STATUS_CANCELED, VoiceCall::STATUS_FAILED_TO_START];

    /** La deriva (del agente y, E4-8, del número, que la base guarda con el mismo motivo). */
    public const DRIFT_REASONS = ['agent_drift'];

    /** Los techos de gasto: el duro del día y el aviso de la subcuenta. */
    public const BUDGET_REASONS = ['cost_cap', 'subaccount_alert'];

    /**
     * E4-2 y E13-3: los recuentos de la entrada `$seq` de la parte `$part`
     * desde `$since`. Las llamadas que cuentan son las SELLADAS con esa entrada
     * y esa parte, sin tramos de transferencia, sin denegadas y sin las
     * salientes que nunca llegaron al agente (`requested`, `canceled`,
     * `failed_to_start`; E6-2); entre ellas:
     *
     *  - `calls` (E13-3): solo las que tienen su post-llamada PROCESADA, su
     *    `guard_verdict` ({@see POST_CALL_PROCESSED}). Una llamada sellada cuya
     *    transcripción nunca pasó por la guarda no es evidencia de que el
     *    agente se porta bien (REV8 M-3: el webhook caído o una saliente sin
     *    respuesta ya no cuentan).
     *  - `missingPostCall` (E13-3, la séptima): las que deberían tenerla y no
     *    la tienen: ni `guard_verdict` ni `call_initiation_failure`
     *    ({@see POST_CALL_NO_CONVERSATION}), y fuera de los estados que dicen
     *    que nunca conectaron ({@see NEVER_CONNECTED}). Una llamada en curso
     *    (`dialing`, `active`) o sin resolver (`ambiguous`) cuenta hasta que
     *    llegue su post-llamada o la resuelva la conciliación: bloquea promover
     *    mientras tanto, en el sentido seguro.
     *  - `guardViolations` y `sealMismatches`: los eventos de TODAS las filas
     *    selladas con esa entrada (también la denegada: un `seal_mismatch` en
     *    la iniciación deja la fila `denied`).
     *  - `driftEvents`, `unknownProviderCalls` y `budgetBreaches`: los de todo
     *    el sistema desde `$since` (las aperturas del freno por deriva y por
     *    gasto, y las llamadas que Laravel no inició).
     *
     * @return array{calls:int,guardViolations:int,sealMismatches:int,driftEvents:int,unknownProviderCalls:int,budgetBreaches:int,missingPostCall:int}
     */
    public static function forPublication(int $seq, string $part, DateTimeInterface $since): array
    {
        $desde = Carbon::instance($since);
        $selladas = static fn (): Builder => VoiceCall::query()
            ->where('stamp_state', 'applied')
            ->where('part', $part)
            ->where('voice_publication_seq', $seq)
            ->where('created_at', '>=', $desde);
        $eventos = static fn (string $tipo): Builder => VoiceCallEvent::query()
            ->where('kind', $tipo)
            ->where('created_at', '>=', $desde)
            ->whereIn('call_id', $selladas()->select('id'));
        $frenos = static fn (array $motivos): int => VoiceBrakeEntry::query()
            ->where('kind', VoiceBrakeEntry::KIND_OPEN)
            ->whereIn('reason', $motivos)
            ->where('created_at', '>=', $desde)
            ->count();
        // Las que cuentan como evidencia (E6-2) y, de ellas, si tienen su post-llamada (E13-3): un semi-join por llamada.
        $evidencia = static fn (): Builder => $selladas()->whereNull('transfer_of_call_id')->whereNotIn('status', self::NOT_EVIDENCE);
        $suPostLlamada = static fn (array $tipos): Closure => static fn (QueryBuilder $e): QueryBuilder => $e->selectRaw('1')->from('voice_call_events')
            ->whereColumn('voice_call_events.call_id', 'voice_calls.id')->whereIn('voice_call_events.kind', $tipos);

        return [
            'calls' => $evidencia()->whereExists($suPostLlamada([self::POST_CALL_PROCESSED]))->count(),
            'guardViolations' => $eventos('guard_verdict')->where('outcome', 'violation')->count(),
            'sealMismatches' => $eventos('seal_mismatch')->count(),
            'driftEvents' => $frenos(self::DRIFT_REASONS),
            'unknownProviderCalls' => VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('created_at', '>=', $desde)->count(),
            'budgetBreaches' => $frenos(self::BUDGET_REASONS),
            'missingPostCall' => $evidencia()->whereNotIn('status', self::NEVER_CONNECTED)
                ->whereNotExists($suPostLlamada([self::POST_CALL_PROCESSED, self::POST_CALL_NO_CONVERSATION]))->count(),
        ];
    }

    /**
     * Los recuentos ricos de una entrada de la cadena, para las métricas de la
     * telefonía (`GET admin/voice/metrics`).
     *
     * Como mucho TRES sentencias (PERF8, P8-1): una agregación de las llamadas
     * de la entrada y su parte (por `vc_publication_idx`), una de sus eventos
     * con semi-join (sin traer los ids a PHP) y las desconocidas desde la
     * primera llamada; sin llamadas, ni eventos ni desconocidas. El mismo SQL
     * en los dos motores (`count(CASE …)`, que da 0 y no NULL sin filas). Quien
     * ya leyó el freno lo pasa en `$brakeOpen`; si no, se lee aquí.
     *
     * @return array<string, mixed>
     */
    public static function summary(string $part, int $seq, ?bool $brakeOpen = null): array
    {
        $llamadas = static fn (): Builder => VoiceCall::query()->where('voice_publication_seq', $seq)->where('part', $part)->whereNull('transfer_of_call_id');
        $f = $llamadas()->toBase()->selectRaw(
            'count(*) AS sealed, count(CASE WHEN status = ? THEN 1 END) AS completed, count(CASE WHEN seal_verified = ? THEN 1 END) AS verified, '
            .'sum(reserved_micros) AS committed, min(created_at) AS first_at, max(created_at) AS last_at',
            [VoiceCall::STATUS_COMPLETED, true],
        )->first();
        $selladas = (int) ($f->sealed ?? 0);
        $eventos = $selladas === 0 ? null : VoiceCallEvent::query()->toBase()
            ->whereIn('call_id', $llamadas()->select('id'))
            ->whereIn('kind', ['seal_mismatch', 'guard_verdict'])
            ->selectRaw('count(CASE WHEN kind = ? THEN 1 END) AS mismatches, count(CASE WHEN kind = ? AND outcome = ? THEN 1 END) AS violations',
                ['seal_mismatch', 'guard_verdict', 'violation'])
            ->first();
        $desde = ($f->first_at ?? null) === null ? null : Carbon::parse($f->first_at);

        return [
            'part' => $part,
            'seq' => $seq,
            'sealedCalls' => $selladas,
            'completedCalls' => (int) ($f->completed ?? 0),
            'sealVerified' => (int) ($f->verified ?? 0),
            'sealMismatches' => (int) ($eventos->mismatches ?? 0),
            'guardViolations' => (int) ($eventos->violations ?? 0),
            'unknownProviderCalls' => $desde === null ? 0 : VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('created_at', '>=', $desde)->count(),
            'committedMicros' => (int) ($f->committed ?? 0),
            'firstCallAt' => $desde?->toIso8601String(),
            'lastCallAt' => ($f->last_at ?? null) === null ? null : Carbon::parse($f->last_at)->toIso8601String(),
            'hoursObserved' => $desde === null ? 0 : (int) floor($desde->diffInHours(now(), true)),
            'brakeOpen' => $brakeOpen ?? VoiceBrake::isOpen(),
        ];
    }
}
