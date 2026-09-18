<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\Payment;
use App\Models\ReceivablePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Qué cuenta como DINERO RECIBIDO, y qué no.
 *
 * Existe porque la respuesta estaba repetida en cuatro sitios —el arqueo del
 * turno, el informe de ganancias, los KPIs de Pagos y los de Caja Productos— y
 * tres de ellos la habían contestado distinto. El resultado lo vio QA: un plan
 * de 80.000 pagado en dos abonos de 40.000 aparecía como 80.000 recaudados
 * desde el primer abono, y el segundo no sumaba nada.
 *
 * LA REGLA, en una línea: se reconoce el dinero el día que entra, ni antes ni
 * dos veces.
 *
 *   venta a crédito / plan a plazos → NO es caja. Es una promesa de pago.
 *   abono aplicado                  → SÍ es caja, el día en que se cobró.
 *   abono anulado                   → deja de serlo, porque el dinero salió.
 *
 * EL ASIENTO DE DEVENGO. Cuando se vende un plan a plazos se crea un `Payment`
 * por el importe COMPLETO con `method = receivable`, que es el contrato: sirve
 * para activar la membresía y para que la venta exista como hecho. Pero ese
 * importe no entró en el cajón, y sumarlo reconocería dinero que nadie ha
 * pagado todavía. Ese asiento no lleva turno de caja, así que el arqueo ya lo
 * excluía por construcción; los informes que suman `payments` sin mirar el
 * turno, no.
 */
class CashReceipts
{
    /**
     * El `method` del asiento de devengo de un plan a plazos.
     *
     * No es un medio de pago: nadie paga «con receivable». Es la marca que
     * distingue el contrato del dinero.
     */
    public const ACCRUAL_METHOD = 'receivable';

    /**
     * Los dos instantes UTC que delimitan un día del gimnasio.
     *
     * Hace falta porque los timestamps se guardan en UTC y el día del negocio
     * empieza a medianoche en Bogotá, cinco horas después. Comparar un
     * `created_at` en UTC contra una fecha de Colombia hace que «hoy» cambie a
     * las 19:00 locales: lo cobrado entre las 19:00 y la medianoche aparecía en
     * el día siguiente, y el cierre de la tarde salía corto.
     *
     * Se devuelve un RANGO en vez de convertir la columna en SQL a propósito:
     * `AT TIME ZONE` solo existe en PostgreSQL, y esto vale igual en producción
     * y en los tests.
     *
     * @return array{0: Carbon,1: Carbon} [inicio inclusive, fin exclusivo]
     */
    public function businessDay(?Carbon $dia = null): array
    {
        $tz = config('caja.timezone', 'America/Bogota');
        $fecha = ($dia ?? Carbon::now($tz))->format('Y-m-d');

        $inicio = Carbon::parse($fecha, $tz)->startOfDay()->utc();

        return [$inicio, $inicio->copy()->addDay()];
    }

    /** ¿Este apunte es el contrato, y no dinero? */
    public function isAccrual(?string $method): bool
    {
        return $method !== null && strtolower(trim($method)) === self::ACCRUAL_METHOD;
    }

    /**
     * Pagos que representan dinero de verdad.
     *
     * `method` nulo también cuenta: hay pagos históricos importados sin medio, y
     * excluirlos borraría ingresos reales del pasado.
     */
    public function cashPayments(): Builder
    {
        // Columnas CUALIFICADAS: esta consulta se usa como base de informes que
        // la unen con `payment_splits`, que también tiene `method`, y sin el
        // prefijo la base de datos no sabe a cuál de las dos se refiere.
        return Payment::query()->where(function (Builder $q): void {
            $q->whereNull('payments.method')->orWhereRaw('LOWER(payments.method) <> ?', [self::ACCRUAL_METHOD]);
        });
    }

    /**
     * Abonos que sí entraron. Los anulados quedan fuera por su estado, así que
     * revertir uno lo saca del informe igual que lo saca del arqueo.
     */
    public function appliedInstallments(?CashShiftType $type = null): Builder
    {
        $q = ReceivablePayment::query()
            ->where('receivable_payments.status', ReceivablePayment::STATUS_APPLIED);

        if ($type !== null) {
            // La caja la decide la cuenta, no el abono: un crédito de cafetería
            // se cobra en la caja de productos aunque quien cobre esté en otra.
            $q->whereHas('receivable', fn ($r) => $r->where('type', $type->value));
        }

        return $q;
    }
}
