<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * El techo de gasto de la voz (contrato H8, D8-4, D8-15 y protección 12;
 * fixture `policy-tables.json` §costReservation). Separado del `OtpCostGuard`
 * (otra cuenta, otras variables): la voz no lee `otp.*`.
 *
 * TODO EN MICRO-US$ ENTEROS (un decimal no tiene una única escritura):
 *  - El presupuesto (`VOICE_DAILY_BUDGET_USD`, texto decimal): «5.50» →
 *    5 500 000; más de 6 decimales se truncan; vacío, negativo, notación
 *    científica o texto → 0 (DF-P13). Con 0 no hay ninguna saliente.
 *  - La reserva de una llamada: ceil(segundos máximos / 60) × (ElevenLabs +
 *    el tramo de Twilio según el destino + Media Streams), más el tramo de
 *    transferencia al precio de su destino (DF-P12: max_duration_seconds no
 *    acota el tramo transferido). Precios oficiales con su fecha en
 *    `voice.prices`.
 *  - Cabe si lo comprometido del día de Bogotá + la nueva ≤ presupuesto (el
 *    borde vale); con presupuesto 0, nunca.
 *
 * LO COMPROMETIDO de una llamada del día (conservador: nunca cuenta de menos):
 *  - `canceled` y `failed_to_start`: 0 (DF-G7, no salió nada);
 *  - `busy`, `no_answer` y `failed`: lo real conocido (no hubo conversación);
 *  - el resto (en curso, `ambiguous`, `completed`, `hung_up`, `denied`): el
 *    mayor entre la reserva y lo real. El coste real de ElevenLabs llega en
 *    unidades [por confirmar] y no se convierte: hasta confirmarlas, la
 *    reserva manda.
 *
 * LA RESERVA ES ATÓMICA: quien reserva lo hace dentro de la transacción del
 * cerrojo de la puerta (`VoiceLocks::gate()`), leyendo lo comprometido y
 * escribiendo la fila en la misma transacción.
 */
final class VoiceCostGuard
{
    public const MICROS = 1_000_000;

    public const KIND_MOBILE = 'mobile';

    public const KIND_LANDLINE = 'landline';

    public const KIND_INBOUND = 'inbound';

    public const RELEASED_STATUSES = ['canceled', 'failed_to_start'];

    public const NO_CONVERSATION_STATUSES = ['busy', 'no_answer', 'failed'];

    /** Lo que se usa si `voice.prices` falta (los oficiales del 03-11-2026, como el fixture). */
    public const DEFAULT_PRICES = [
        'as_of' => '2026-11-03',
        'elevenlabs' => 80000,
        'twilio_outbound_mobile_co' => 37700,
        'twilio_outbound_landline_co' => 70000,
        'twilio_inbound_local_co' => 94500,
        'twilio_media_streams' => 4400,
    ];

    /** DF-P13: el presupuesto en micro-US$ (0 si no es un decimal positivo bien escrito). */
    public static function budgetMicros(mixed $raw): int
    {
        $texto = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if (preg_match('/^([0-9]{1,9})(?:\.([0-9]+))?$/D', $texto, $m) !== 1) {
            return 0;
        }
        $fraccion = substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);

        return (int) $m[1] * self::MICROS + (int) $fraccion;
    }

    /** El presupuesto configurado (`voice.limits.daily_budget_usd`). */
    public static function budgetFromConfig(): int
    {
        return self::budgetMicros(config('voice.limits.daily_budget_usd'));
    }

    /**
     * DF-P12: la reserva de una llamada.
     *
     * @param  string  $destinationKind  mobile | landline (salientes) o inbound
     * @param  string|null  $transferKind  mobile | landline si la llamada puede transferir; null si no
     * @param  array<string, int|string>  $prices
     */
    public static function reserveMicros(string $destinationKind, int $maxDurationSeconds, ?string $transferKind, array $prices): int
    {
        $minutos = intdiv(max(1, $maxDurationSeconds) + 59, 60);
        $tramo = match ($destinationKind) {
            self::KIND_MOBILE => (int) $prices['twilio_outbound_mobile_co'],
            self::KIND_INBOUND => (int) $prices['twilio_inbound_local_co'],
            default => (int) $prices['twilio_outbound_landline_co'],
        };
        $total = $minutos * ((int) $prices['elevenlabs'] + $tramo + (int) $prices['twilio_media_streams']);
        if ($transferKind !== null) {
            $total += $minutos * ($transferKind === self::KIND_MOBILE ? (int) $prices['twilio_outbound_mobile_co'] : (int) $prices['twilio_outbound_landline_co']);
        }

        return $total;
    }

    /** Cabe si comprometido + nueva ≤ presupuesto (el borde vale); con presupuesto 0, nunca. */
    public static function fits(int $budgetMicros, int $committedMicros, int $newMicros): bool
    {
        return $budgetMicros > 0 && $committedMicros + $newMicros <= $budgetMicros;
    }

    /**
     * El tipo de destino de un E.164 para su precio: en Colombia, `+573…` es
     * móvil y `+5760…` fijo; cualquier otro, el precio de fijo (el más caro de
     * los dos: nunca se reserva de menos).
     */
    public static function destinationKind(#[\SensitiveParameter] string $e164): string
    {
        return preg_match('/^\+573[0-9]{9}$/D', $e164) === 1 ? self::KIND_MOBILE : self::KIND_LANDLINE;
    }

    /** @return array<string, int|string> los precios de `voice.prices`, con los oficiales donde falte uno */
    public static function prices(): array
    {
        $c = config('voice.prices');
        $c = is_array($c) ? $c : [];
        $out = self::DEFAULT_PRICES;
        foreach ($out as $clave => $defecto) {
            if ($clave === 'as_of') {
                $out[$clave] = is_string($c[$clave] ?? null) ? $c[$clave] : $defecto;

                continue;
            }
            $valor = $c[$clave] ?? null;
            $out[$clave] = is_int($valor) && $valor >= 0 ? $valor : $defecto;
        }

        return $out;
    }

    /** El día de Bogotá (`Y-m-d`) de un instante: el del presupuesto y de los cupos. */
    public static function budgetDay(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(OutboundSchedule::ZONE))->format('Y-m-d');
    }

    /** Lo comprometido de un día de Bogotá, en micro-US$, con la regla de arriba (una sentencia). */
    public static function committedMicros(string $budgetDay): int
    {
        $real = '(COALESCE(provider_cost_micros, 0) + COALESCE(carrier_cost_micros, 0))';
        $liberadas = "'".implode("', '", self::RELEASED_STATUSES)."'";
        $sinConversacion = "'".implode("', '", self::NO_CONVERSATION_STATUSES)."'";

        return (int) DB::table('voice_calls')
            ->where('budget_day', $budgetDay)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$liberadas}) THEN 0 WHEN status IN ({$sinConversacion}) THEN {$real} "
                ."WHEN reserved_micros >= {$real} THEN reserved_micros ELSE {$real} END), 0) AS comprometido")
            ->value('comprometido');
    }
}
