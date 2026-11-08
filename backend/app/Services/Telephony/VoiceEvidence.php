<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use Illuminate\Support\Carbon;

/**
 * La evidencia MEDIDA EN EL SERVIDOR de una publicación de voz, para promover
 * de `canary` a `rest` (contrato H8, D8-19 [P-16]; Enmienda 2, E2-6: el
 * Studio la lee a través de este servicio y nunca de las tablas `voice_*`).
 * Solo recuentos y fechas; ni números, ni textos, ni uuids de personas.
 */
final class VoiceEvidence
{
    /** @return array<string, mixed> */
    public static function forPublication(string $part, int $seq): array
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
