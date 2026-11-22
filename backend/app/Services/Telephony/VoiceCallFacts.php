<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * C-3 (E19-3): una `failed_to_start` cerrada por la salida de una `ambiguous`
 * ({@see VoiceAmbiguousResolution::resolvedSql()}) PUDO SONAR: cuenta en las
 * salientes del día y, para la frecuencia, {@see historyFor()} la devuelve
 * como `ambiguous` (`VoiceFrequency` no cambia). En curso no cuenta: soltar
 * la concurrencia es el objeto de la salida.
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
        [$resuelta, $valores] = VoiceAmbiguousResolution::resolvedSql();

        return VoiceCall::query()->where('budget_day', $budgetDay)->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereNull('transfer_of_call_id')
            ->where(static fn (Builder $w) => $w->whereNotIn('status', VoiceCostGuard::RELEASED_STATUSES)->orWhereRaw($resuelta, $valores))
            ->count();
    }

    /**
     * Las salientes a una huella de los últimos 8 días (la frecuencia, D8-15).
     * C-3: la cerrada por E19-3, como `ambiguous` (un intento que pudo sonar).
     *
     * @return list<array{at: string, status: string, direction: string}>
     */
    public static function historyFor(string $remoteHash, DateTimeInterface $now): array
    {
        [$resuelta, $valores] = VoiceAmbiguousResolution::resolvedSql();

        return VoiceCall::query()
            ->where('remote_hash', $remoteHash)
            ->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereNull('transfer_of_call_id')
            ->where('created_at', '>=', Carbon::instance($now)->subDays(8))
            ->select(['created_at', 'status', 'direction'])
            ->selectRaw("CASE WHEN {$resuelta} THEN 1 ELSE 0 END AS unstarted_resolved", $valores)
            ->get()
            ->map(static fn (VoiceCall $c): array => [
                'at' => $c->created_at->toIso8601String(),
                'status' => (int) $c->getAttribute('unstarted_resolved') === 1 ? VoiceCall::STATUS_AMBIGUOUS : (string) $c->status,
                'direction' => (string) $c->direction,
            ])
            ->all();
    }

    /** ¿Hay alguna exclusión para esta huella? */
    public static function excluded(string $phoneHash): bool
    {
        return DB::table('voice_exclusions')->where('phone_hash', $phoneHash)->exists();
    }

    /**
     * {@see excluded()} de una página entera en UNA sentencia (la lista de
     * contactos del CRM; PERF8, P8-2).
     *
     * @param  list<string>  $phoneHashes
     * @return array<string, true> las huellas que tienen alguna exclusión
     */
    public static function excludedAmong(array $phoneHashes): array
    {
        if ($phoneHashes === []) {
            return [];
        }

        return array_fill_keys(DB::table('voice_exclusions')->whereIn('phone_hash', array_values(array_unique($phoneHashes)))
            ->distinct()->pluck('phone_hash')->map(static fn ($h): string => (string) $h)->all(), true);
    }
}
