<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los recuentos que las puertas leen DENTRO de su cerrojo (contrato H8, D8-11,
 * D8-12 y D8-15). Cada uno, una sentencia con su índice de DB8.
 *
 *  - En curso: `requested`, `ambiguous`, `dialing` y `active` de las dos
 *    direcciones, sin los tramos de transferencia. D8-15 cuenta `dialing` +
 *    `active`; aquí se cuentan también la pedida (va a marcar) y la ambigua
 *    (pudo marcar): nunca de menos.
 *  - Entrantes del día: las AUTORIZADAS del día de Bogotá (una denegada no
 *    gasta el cupo de los canarios).
 *  - Salientes del día: las del día de Bogotá que salieron o pueden haber
 *    salido (no `canceled` ni `failed_to_start`).
 */
final class VoiceCallFacts
{
    public static function liveCount(): int
    {
        return VoiceCall::query()->whereIn('status', VoiceCall::LIVE)->whereNull('transfer_of_call_id')->count();
    }

    public static function inboundAuthorizedOn(string $budgetDay): int
    {
        return VoiceCall::query()->where('budget_day', $budgetDay)->where('direction', VoiceCall::DIRECTION_INBOUND)->where('caller_authorized', true)->count();
    }

    public static function outboundPlacedOn(string $budgetDay): int
    {
        return VoiceCall::query()->where('budget_day', $budgetDay)->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereNull('transfer_of_call_id')->whereNotIn('status', VoiceCostGuard::RELEASED_STATUSES)->count();
    }

    /**
     * Las salientes a una huella de los últimos 8 días (la frecuencia, D8-15).
     *
     * @return list<array{at: string, status: string, direction: string}>
     */
    public static function historyFor(string $remoteHash, DateTimeInterface $now): array
    {
        return VoiceCall::query()
            ->where('remote_hash', $remoteHash)
            ->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereNull('transfer_of_call_id')
            ->where('created_at', '>=', Carbon::instance($now)->subDays(8))
            ->get(['created_at', 'status', 'direction'])
            ->map(static fn (VoiceCall $c): array => ['at' => $c->created_at->toIso8601String(), 'status' => (string) $c->status, 'direction' => (string) $c->direction])
            ->all();
    }

    /** ¿Hay alguna exclusión para esta huella? */
    public static function excluded(string $phoneHash): bool
    {
        return DB::table('voice_exclusions')->where('phone_hash', $phoneHash)->exists();
    }
}
