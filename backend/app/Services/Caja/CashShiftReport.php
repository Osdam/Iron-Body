<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\AuditLog;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\ReceivablePayment;
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
        $devoluciones = $this->devoluciones($shift);

        return [
            'shift' => $this->cabecera($shift) + [
                // Ya restado de los totales por medio; aquí, para enseñarlo.
                'refunds_total' => round(array_sum(array_column($devoluciones, 'amount')), 2),
            ],
            'transactions' => $transacciones,
            // Dinero que SALIÓ de este turno: devoluciones de cobros o ventas
            // (de este turno o de otros). Ya están restadas de los totales.
            'refunds' => $devoluciones,
            // Correcciones hechas DESPUÉS de cerrar sobre operaciones de este
            // turno. El cierre firmado no cambia: aquí se ve qué pasó después.
            'post_close_corrections' => $this->correccionesPosteriores($shift, $transacciones),
            'consistency' => $this->contraste($shift, $transacciones, $devoluciones),
        ];
    }

    /**
     * Devoluciones pagadas desde este turno.
     *
     * @return list<array<string, mixed>>
     */
    private function devoluciones(CashShift $shift): array
    {
        return CashRefund::query()
            ->where('cash_shift_id', $shift->id)
            ->orderBy('id')
            ->get()
            ->map(fn (CashRefund $r) => $r->toCrmArray() + [
                'source_type' => 'cash_refund',
                'source_id' => $r->id,
            ])
            ->all();
    }

    /**
     * Las correcciones (anular, devolver, cambiar medio o importe) registradas
     * en `audit_logs` DESPUÉS del cierre sobre cobros, ventas o abonos de este
     * turno.
     *
     * Se buscan por la operación —entidad + id— y no consultando el JSON de
     * `metadata`: la misma consulta tiene que funcionar en PostgreSQL y en las
     * pruebas sobre SQLite, y el JSON se compara distinto en cada uno.
     *
     * @param  list<array<string, mixed>>  $transacciones
     * @return list<array<string, mixed>>
     */
    private function correccionesPosteriores(CashShift $shift, array $transacciones): array
    {
        if ($shift->isOpen() || $shift->closed_at === null) {
            return [];
        }

        $porEntidad = ['pago' => [], 'venta' => [], 'abono' => []];
        foreach ($transacciones as $t) {
            $entidad = match ($t['source_type']) {
                'payment' => 'pago',
                'product_sale' => 'venta',
                'receivable_payment' => 'abono',
                default => null,
            };
            if ($entidad !== null) {
                $porEntidad[$entidad][] = (string) $t['source_id'];
            }
        }
        $porEntidad = array_filter($porEntidad);
        if ($porEntidad === []) {
            return [];
        }

        return AuditLog::query()
            ->where('created_at', '>', $shift->closed_at)
            ->whereIn('action', ['status', 'update', 'refund'])
            ->where(function ($q) use ($porEntidad): void {
                foreach ($porEntidad as $entidad => $ids) {
                    $q->orWhere(fn ($e) => $e->where('entity', $entidad)->whereIn('entity_id', $ids));
                }
            })
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'at' => optional($l->created_at)->toIso8601String(),
                'action' => $l->action,
                'entity' => $l->entity,
                'entity_id' => $l->entity_id,
                'summary' => $l->summary,
                'actor_name' => $l->actor_name,
                'reason' => $l->metadata['reason'] ?? null,
                'changes' => $l->changes,
            ])
            ->all();
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
        $ventas = ProductSale::query()
            ->where('cash_shift_id', $shift->id)
            // Sin esto, una venta de veinte líneas dispara veinte consultas.
            ->with(['items:id,product_sale_id,name,quantity,unit_price,subtotal,refunded_quantity'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSale $v) => [
                'id' => $v->id,
                // De qué fila sale, con el mismo vocabulario que los recibos de
                // caja: el CRM cruza cada línea con su tirilla por aquí.
                'source_type' => 'product_sale',
                'source_id' => $v->id,
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
                    'refunded_quantity' => (int) ($i->refunded_quantity ?? 0),
                ])->all(),
            ])
            ->all();

        // LOS ABONOS TAMBIÉN (hallazgo H9): el total congelado de la caja de
        // cafetería ya los sumaba —CashShiftTotalsService— y el detalle no los
        // listaba, así que un turno con un crédito cobrado salía «descuadrado»
        // contra sus propias líneas sin que nada estuviera mal.
        return array_merge($ventas, $this->abonos($shift));
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
        $cobros = Payment::query()
            ->where('cash_shift_id', $shift->id)
            ->with(['user:id,name', 'plan:id,name', 'upgradedFrom:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'source_type' => 'payment',
                'source_id' => $p->id,
                'reference' => $p->reference,
                'at' => optional($p->created_at)->toIso8601String(),
                'member' => $p->user?->name,
                // Una mejora dice de dónde viene: sin eso, en el informe
                // aparece un Total Access cobrado a 70.000 y parece un error
                // de precio. Ver PlanUpgrades.
                'plan' => $p->upgraded_from_plan_id
                    ? ($p->plan?->name.' · mejora desde '.($p->upgradedFrom?->name ?? 'otro plan'))
                    : $p->plan?->name,
                'payment_method' => $p->method,
                'status' => $p->status,
                'total' => (float) $p->amount,
            ])
            ->all();

        return array_merge($cobros, $this->abonos($shift));
    }

    /**
     * LOS ABONOS A CUENTAS POR COBRAR TAMBIÉN SON DINERO DE ESTE TURNO.
     *
     * Faltaban, y el informe se contradecía solo: el total congelado al cerrar
     * sale de CashShiftTotalsService, que SÍ los suma, mientras que el detalle
     * solo listaba `payments`. Resultado: un turno con un abono salía con un
     * total que no cuadraba con sus propias líneas, y el contraste lo marcaba
     * como descuadre sin que nada estuviera mal.
     *
     * Se notaba poco porque en la caja del gimnasio los abonos eran raros. Con
     * el piso de los entrenadores pasan a ser de todos los días, y un arqueo
     * que no cuadra por diseño es un arqueo que nadie vuelve a mirar.
     *
     * Se listan TODOS, también los anulados, por la misma razón que las ventas
     * canceladas: esconderlos dejaría un hueco inexplicable. El estado va en la
     * fila, y el contraste solo cuenta los que siguen siendo dinero.
     *
     * @return list<array<string, mixed>>
     */
    private function abonos(CashShift $shift): array
    {
        return ReceivablePayment::query()
            ->where('cash_shift_id', $shift->id)
            ->with(['receivable:id,concept,debtor_type', 'receivable.member:id,full_name'])
            ->orderBy('id')
            ->get()
            ->map(fn (ReceivablePayment $a) => [
                'id' => 'abono-'.$a->id,
                'source_type' => 'receivable_payment',
                'source_id' => $a->id,
                'reference' => null,
                'at' => optional($a->created_at)->toIso8601String(),
                'member' => $a->receivable?->member?->full_name ?? $a->created_by_name,
                // El concepto de la deuda es lo que explica el apunte meses
                // después: «Piso de entrenador · Carlos → Olga · octubre».
                'plan' => $a->receivable?->concept,
                'payment_method' => $a->method,
                // El contraste solo suma 'paid' y 'delivered'; un abono
                // aplicado tiene que contar, y uno revertido no.
                'status' => $a->isApplied() ? 'paid' : 'reversed',
                'total' => (float) $a->amount,
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
    private function contraste(CashShift $shift, array $transacciones, array $devoluciones = []): array
    {
        // `refunded` cuenta: el dinero entró en este turno y salió por una
        // devolución, que se resta aparte (la lista `refunds`).
        $computables = array_filter(
            $transacciones,
            fn (array $t) => in_array($t['status'], ['paid', 'delivered', 'refunded'], true),
        );
        $salidas = round(array_sum(array_column($devoluciones, 'amount')), 2);
        $sumaDetalle = round(array_sum(array_column($computables, 'total')) - $salidas, 2);
        $congelado = $shift->sales_total !== null ? round((float) $shift->sales_total, 2) : null;

        return [
            'frozen_total' => $congelado,
            'detail_total' => $sumaDetalle,
            'detail_count' => count($computables),
            'listed_count' => count($transacciones),
            'refunds_total' => $salidas,
            'matches' => $congelado === null || abs($congelado - $sumaDetalle) < 0.01,
        ];
    }
}
