<?php

namespace App\Support\Caja;

use App\Enums\CashShiftType;
use App\Models\Receivable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * El plazo que se concede para saldar una deuda, según de qué caja sea.
 *
 * SON POLÍTICAS COMERCIALES DISTINTAS y por eso no comparten número. Financiar
 * un plan de gimnasio es dar crédito sobre un servicio que se está consumiendo
 * todo el mes: el plazo tiene que caber dentro de ese mes, porque si la deuda
 * sobrevive al plan que la originó el socio acaba renovando mientras todavía
 * debe el anterior. Fiar un batido en la cafetería es otra cosa: importes
 * pequeños, frecuentes, y la costumbre del mostrador es saldarlos en la semana.
 *
 * Quien cobra SIEMPRE puede pactar otra fecha. Este número solo evita que una
 * deuda nazca sin plazo por olvido — y sin plazo no vence, así que no avisa a
 * nadie y no se cobra nunca.
 */
class PaymentTerm
{
    /** Días concedidos para esta caja. 0 significa «sin plazo por defecto». */
    public static function days(CashShiftType $type): int
    {
        $porCaja = (array) config('caja.payment_terms', []);

        // El valor único anterior sigue sirviendo de respaldo para no romper una
        // configuración ya desplegada que solo lo tenga a él.
        $respaldo = (int) config('caja.default_payment_term_days', 0);

        return max((int) ($porCaja[$type->value] ?? $respaldo), 0);
    }

    /**
     * La fecha límite que se va a grabar.
     *
     * Si el mostrador la pactó, manda esa. Si no y procede derivarla, sale del
     * plazo configurado para esa caja — nunca de un número escrito a mano en un
     * controlador, que es como se acaba teniendo tres plazos distintos según el
     * endpoint por el que entre la deuda.
     */
    public static function resolve(?string $pactada, CashShiftType $type, bool $derivarPorDefecto = true): ?CarbonInterface
    {
        if ($pactada !== null && trim($pactada) !== '') {
            return Carbon::parse(trim($pactada), config('caja.timezone', 'America/Bogota'))->startOfDay();
        }

        $dias = self::days($type);

        if (! $derivarPorDefecto || $dias <= 0) {
            return null;
        }

        return Receivable::businessToday()->addDays($dias);
    }
}
