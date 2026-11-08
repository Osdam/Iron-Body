<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * La evidencia MEDIDA EN EL SERVIDOR de una publicación de voz (contrato H8,
 * D8-19 [P-16]; Enmienda 2, E2-6: el Studio la lee a través de este servicio
 * y nunca de las tablas `voice_*`). Solo recuentos y fechas; ni números, ni
 * textos, ni uuids de personas.
 *
 *  - {@see forPublication()} (Enmienda 4, E4-2): lo que mide el Studio para
 *    promover de `canary` a `rest`, con exactamente seis claves, enteros ≥ 0.
 *  - {@see summary()}: los recuentos ricos de una entrada, para
 *    `GET admin/voice/metrics` (telefonía).
 */
final class VoiceEvidence
{
    /** Las seis claves de E4-2, en su orden. */
    public const KEYS = ['calls', 'guardViolations', 'sealMismatches', 'driftEvents', 'unknownProviderCalls', 'budgetBreaches'];

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
     * E4-2: los recuentos de la entrada `$seq` de la parte `$part` desde
     * `$since`.
     *
     *  - `calls`: las llamadas SELLADAS con esa entrada y esa parte, sin tramos
     *    de transferencia, sin denegadas y sin las salientes que nunca llegaron
     *    al agente (`requested`, `canceled`, `failed_to_start`).
     *  - `guardViolations` y `sealMismatches`: los eventos de TODAS las filas
     *    selladas con esa entrada (también la denegada: un `seal_mismatch` en
     *    la iniciación deja la fila `denied`).
     *  - `driftEvents`, `unknownProviderCalls` y `budgetBreaches`: los de todo
     *    el sistema desde `$since` (las aperturas del freno por deriva y por
     *    gasto, y las llamadas que Laravel no inició).
     *
     * @return array{calls:int,guardViolations:int,sealMismatches:int,driftEvents:int,unknownProviderCalls:int,budgetBreaches:int}
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

        return [
            'calls' => $selladas()->whereNull('transfer_of_call_id')->whereNotIn('status', self::NOT_EVIDENCE)->count(),
            'guardViolations' => $eventos('guard_verdict')->where('outcome', 'violation')->count(),
            'sealMismatches' => $eventos('seal_mismatch')->count(),
            'driftEvents' => $frenos(self::DRIFT_REASONS),
            'unknownProviderCalls' => VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('created_at', '>=', $desde)->count(),
            'budgetBreaches' => $frenos(self::BUDGET_REASONS),
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
