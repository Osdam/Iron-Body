<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\CashShift;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Support\Facades\DB;

/**
 * Totales de un turno, desglosados por medio de pago.
 *
 * ÚNICA fuente de estos números. El controlador no calcula y Angular no envía:
 * un importe que llegara del cliente sería un importe que el cliente puede
 * elegir, y el arqueo dejaría de significar nada.
 *
 * Se suma en SQL con `sum()` sobre columnas DECIMAL y se formatea con
 * `number_format` a 2 decimales; nunca se acumula en float de PHP, donde
 * 0.1 + 0.2 no es 0.3 y un día de ventas termina descuadrado por céntimos.
 */
class CashShiftTotalsService
{
    /**
     * Totales del turno, calculados sobre su fuente canónica según el tipo.
     *
     * @return array{
     *   cash_total: string, transfer_total: string, card_total: string,
     *   wompi_total: string, other_total: string, gross_total: string,
     *   expected_cash: string, operations_count: int
     * }
     */
    public function for(CashShift $shift): array
    {
        $porMedio = $shift->type === CashShiftType::PRODUCTS
            ? $this->productSalesByMethod($shift)
            : $this->gymPaymentsByMethod($shift);

        $totales = [];
        foreach (PaymentMethodKind::cases() as $medio) {
            $totales[$medio->value] = $porMedio['totals'][$medio->value] ?? '0.00';
        }

        $bruto = $this->sumStrings(array_values($totales));

        return [
            'cash_total' => $totales[PaymentMethodKind::CASH->value],
            'transfer_total' => $totales[PaymentMethodKind::TRANSFER->value],
            'card_total' => $totales[PaymentMethodKind::CARD->value],
            'wompi_total' => $totales[PaymentMethodKind::WOMPI->value],
            'other_total' => $totales[PaymentMethodKind::OTHER->value],
            'gross_total' => $bruto,
            // Solo el efectivo. Transferencia, tarjeta y Wompi no dejan billetes
            // en el cajón; sumarlos haría que la caja "faltara" siempre.
            'expected_cash' => $this->sumStrings([
                (string) $shift->opening_amount,
                $totales[PaymentMethodKind::CASH->value],
            ]),
            'operations_count' => $porMedio['count'],
        ];
    }

    /**
     * Ventas de producto cobradas durante el turno.
     *
     * `paid` y `delivered` cuentan —el dinero ya entró—; `pending` y
     * `cancelled` no. Una venta anulada no puede seguir sumando al arqueo.
     *
     * @return array{totals: array<string,string>, count: int}
     */
    private function productSalesByMethod(CashShift $shift): array
    {
        $filas = DB::table('product_sales')
            ->where('cash_shift_id', $shift->id)
            ->whereIn('status', ['paid', 'delivered'])
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('SUM(total) AS suma'), DB::raw('COUNT(*) AS n'))
            ->get();

        return $this->fold($filas, 'payment_method');
    }

    /**
     * Pagos del gimnasio cobrados durante el turno.
     *
     * Solo `paid`: un pago pendiente o cancelado no es dinero recibido. El
     * filtro por `cash_shift_id` ya excluye por construcción los pagos de
     * pasarela y los `MIGR-*` históricos, que nunca llevan turno.
     *
     * @return array{totals: array<string,string>, count: int}
     */
    private function gymPaymentsByMethod(CashShift $shift): array
    {
        $filas = DB::table('payments')
            ->where('cash_shift_id', $shift->id)
            ->where('status', 'paid')
            ->groupBy('method')
            ->select('method', DB::raw('SUM(amount) AS suma'), DB::raw('COUNT(*) AS n'))
            ->get();

        return $this->fold($filas, 'method');
    }

    /**
     * Agrupa las filas crudas por medio canónico. Varios valores de origen
     * pueden caer en el mismo medio (`efectivo` y `cash` son ambos CASH), así
     * que se acumulan en vez de sobrescribirse.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $filas
     * @return array{totals: array<string,string>, count: int}
     */
    private function fold($filas, string $columna): array
    {
        $totals = [];
        $count = 0;

        foreach ($filas as $fila) {
            $medio = PaymentMethodKind::normalize($fila->{$columna})->value;
            $totals[$medio] = $this->sumStrings([$totals[$medio] ?? '0.00', (string) $fila->suma]);
            $count += (int) $fila->n;
        }

        return ['totals' => $totals, 'count' => $count];
    }

    /**
     * Suma en enteros de centavos y devuelve una cadena con 2 decimales.
     *
     * @param  string[]  $valores
     */
    private function sumStrings(array $valores): string
    {
        $centavos = 0;
        foreach ($valores as $valor) {
            $centavos += (int) round(((float) $valor) * 100);
        }

        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Resumen legible que se guarda en el cierre. Sustituye a la observación
     * manual: el operador ya no escribe nada y el cierre igual se explica solo.
     *
     * @param  array<string,mixed>  $t
     */
    public function observation(CashShift $shift, array $t): string
    {
        $money = static fn (string $v): string => '$'.number_format((float) $v, 0, ',', '.');

        return sprintf(
            'Cierre automático de caja %s. Operaciones: %d. Total generado: %s. '.
            'Efectivo esperado: %s. Transferencias: %s. Tarjeta: %s. Wompi: %s. Otros: %s.',
            $shift->type->label(),
            $t['operations_count'],
            $money($t['gross_total']),
            $money($t['expected_cash']),
            $money($t['transfer_total']),
            $money($t['card_total']),
            $money($t['wompi_total']),
            $money($t['other_total']),
        );
    }
}
