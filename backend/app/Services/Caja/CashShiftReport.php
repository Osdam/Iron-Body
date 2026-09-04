<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\ProductSale;

/**
 * El informe de un turno de caja: lo que se ve en pantalla y lo que va al PDF.
 *
 * Existe para que haya UNA sola composición del reporte. Si la pantalla sumara
 * por su cuenta y el PDF por la suya, tarde o temprano dirían cosas distintas
 * sobre el mismo turno, y entonces ninguno de los dos sirve para un arqueo.
 *
 * REGLA CONTABLE, que es la razón de ser de esta clase:
 *
 * Los totales del cierre son los CONGELADOS en `cash_shifts` —`sales_total`,
 * los cinco por método, `expected_amount`—. NO se recalculan al consultar. Un
 * informe histórico debe decir lo que dijo el día que se cerró; recalcularlo
 * significaría que una venta anulada meses después cambiaría un arqueo ya
 * firmado, y eso no es un informe, es una reescritura.
 *
 * Las operaciones vinculadas por `cash_shift_id` se adjuntan como DETALLE que
 * explica esos totales, nunca como su origen.
 */
class CashShiftReport
{
    /**
     * Informe completo del turno.
     *
     * @return array{shift: array<string,mixed>, transactions: list<array<string,mixed>>, consistency: array<string,mixed>}
     */
    public function for(CashShift $shift): array
    {
        $transacciones = $shift->type === CashShiftType::PRODUCTS
            ? $this->ventas($shift)
            : $this->cobros($shift);

        return [
            'shift' => $this->cabecera($shift),
            'transactions' => $transacciones,
            'consistency' => $this->contraste($shift, $transacciones),
        ];
    }

    /**
     * Los datos del turno, con los totales tal como quedaron congelados.
     *
     * @return array<string, mixed>
     */
    private function cabecera(CashShift $shift): array
    {
        return array_merge($shift->toCrmArray(
            // Un turno CERRADO se cuenta con sus valores congelados y solo con
            // ellos. Uno ABIERTO no los tiene todavía, así que se calculan en
            // vivo para que la vista sirva de algo mientras la caja opera.
            withTotals: $shift->isOpen(),
        ), [
            // Duración solo para presentación: no se guarda ni se deriva nada de
            // ella. Un turno abierto no tiene fin, así que no tiene duración.
            'duration_minutes' => $shift->opened_at && $shift->closed_at
                ? $shift->opened_at->diffInMinutes($shift->closed_at)
                : null,
        ]);
    }

    /**
     * Ventas de mostrador del turno, con sus líneas.
     *
     * Se listan TODAS las vinculadas, incluidas las anuladas: un informe que
     * escondiera una venta cancelada dejaría un hueco inexplicable entre el
     * detalle y el total. El estado va en cada fila para que se entienda.
     *
     * @return list<array<string, mixed>>
     */
    private function ventas(CashShift $shift): array
    {
        return ProductSale::query()
            ->where('cash_shift_id', $shift->id)
            // Sin esto, una venta de veinte líneas dispara veinte consultas.
            ->with(['items:id,product_sale_id,name,quantity,unit_price,subtotal'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSale $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'at' => optional($v->created_at)->toIso8601String(),
                'cashier' => $v->cashier_name,
                'customer' => $v->customer_name,
                'payment_method' => $v->payment_method,
                'status' => $v->status,
                'total' => (float) $v->total,
                'lines' => $v->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'subtotal' => (float) $i->subtotal,
                ])->all(),
            ])
            ->all();
    }

    /**
     * Cobros de membresía del turno.
     *
     * Lo mínimo que un administrador necesita para revisar un arqueo: quién
     * pagó, qué plan, cuánto y cómo. NADA de documento, correo, teléfono,
     * dirección ni respuestas de la pasarela: un informe de caja circula por
     * correo y se imprime, y no es sitio para los datos personales del socio.
     *
     * @return list<array<string, mixed>>
     */
    private function cobros(CashShift $shift): array
    {
        return Payment::query()
            ->where('cash_shift_id', $shift->id)
            ->with(['user:id,name', 'plan:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'at' => optional($p->created_at)->toIso8601String(),
                'member' => $p->user?->name,
                'plan' => $p->plan?->name,
                'payment_method' => $p->method,
                'status' => $p->status,
                'total' => (float) $p->amount,
            ])
            ->all();
    }

    /**
     * ¿Cuadra el detalle con lo congelado?
     *
     * Se informa, NO se corrige. Una diferencia puede ser legítima —una venta
     * anulada después del cierre sigue vinculada al turno pero ya no suma— y
     * ajustar el total histórico para que encaje sería falsear el arqueo. Quien
     * lea el informe decide qué significa; el sistema solo se lo enseña.
     *
     * @param  list<array<string, mixed>>  $transacciones
     * @return array<string, mixed>
     */
    private function contraste(CashShift $shift, array $transacciones): array
    {
        $computables = array_filter(
            $transacciones,
            fn (array $t) => in_array($t['status'], ['paid', 'delivered'], true),
        );
        $sumaDetalle = round(array_sum(array_column($computables, 'total')), 2);
        $congelado = $shift->sales_total !== null ? round((float) $shift->sales_total, 2) : null;

        return [
            'frozen_total' => $congelado,
            'detail_total' => $sumaDetalle,
            'detail_count' => count($computables),
            'listed_count' => count($transacciones),
            'matches' => $congelado === null || abs($congelado - $sumaDetalle) < 0.01,
        ];
    }
}
