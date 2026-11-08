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
     * @return array<string, mixed>
     */
    public static function summary(string $part, int $seq): array
    {
        $llamadas = VoiceCall::query()->where('voice_publication_seq', $seq)->where('part', $part)->whereNull('transfer_of_call_id');
        $ids = (clone $llamadas)->pluck('id')->all();
        $primera = (clone $llamadas)->min('created_at');
        $ultima = (clone $llamadas)->max('created_at');
        $eventos = static fn (string $tipo): int => $ids === [] ? 0 : VoiceCallEvent::query()->whereIn('call_id', $ids)->where('kind', $tipo)->count();
        $violaciones = $ids === [] ? 0 : VoiceCallEvent::query()->whereIn('call_id', $ids)->where('kind', 'guard_verdict')->where('outcome', 'violation')->count();
        $desde = $primera === null ? null : Carbon::parse($primera);

        return [
            'part' => $part,
            'seq' => $seq,
            'sealedCalls' => count($ids),
            'completedCalls' => (clone $llamadas)->where('status', VoiceCall::STATUS_COMPLETED)->count(),
            'sealVerified' => (clone $llamadas)->where('seal_verified', true)->count(),
            'sealMismatches' => $eventos('seal_mismatch'),
            'guardViolations' => $violaciones,
            'unknownProviderCalls' => $desde === null ? 0 : VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('created_at', '>=', $desde)->count(),
            'committedMicros' => (int) (clone $llamadas)->sum('reserved_micros'),
            'firstCallAt' => $desde?->toIso8601String(),
            'lastCallAt' => $ultima === null ? null : Carbon::parse($ultima)->toIso8601String(),
            'hoursObserved' => $desde === null ? 0 : (int) floor($desde->diffInHours(now(), true)),
            'brakeOpen' => VoiceBrake::isOpen(),
        ];
    }
}
