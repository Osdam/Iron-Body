<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\ProductSaleSplit;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use App\Support\Caja\PaymentMethodKind;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * LO QUE SE IMPRIME. El snapshot de un recibo, compuesto con los datos reales de
 * la operación en el momento de emitirlo.
 *
 * Regla única: TODO sale de una fila que existe. Nada se calcula a partir de lo
 * que el cajero dijo en pantalla, y nada se inventa para rellenar la tirilla:
 *
 *  · El «valor recibido» y el «cambio» NO existen en ninguna tabla —el CRM no los
 *    guarda— y por eso no se imprimen. Inventarlos a partir del total sería
 *    imprimir como hecho algo que nadie registró.
 *  · Del cliente va SOLO el nombre, como en el informe de cierre: una tirilla se
 *    queda en el mostrador o en la basura, y no es sitio para un documento de
 *    identidad ni un teléfono.
 *  · Los totales CUADRAN con la fila de origen por construcción: `total` es el
 *    importe cobrado (`payments.amount`, `product_sales.total`, el abono), el
 *    descuento es el congelado, y `subtotal = total + descuento`. Así la tirilla
 *    suma siempre, también en las ventas antiguas sin desglose fiscal.
 *  · Los impuestos son INFORMATIVOS y van marcados como incluidos: el precio de
 *    mostrador ya los contiene (o los sumó el cotizador antes de cobrar), así
 *    que `subtotal` ya los lleva dentro.
 *
 * Las fechas van YA formateadas en la zona del gimnasio (`caja.timezone`).
 * `app.timezone` es UTC: formatear en el navegador o en el servidor sin zona
 * imprimía las 00:30 del día siguiente para un cobro de las 19:30.
 */
final class ReceiptComposer
{
    private const TITLES = [
        Receipt::KIND_MEMBERSHIP_PAYMENT => 'Recibo de pago',
        Receipt::KIND_PLAN_UPGRADE => 'Recibo de pago · Mejora de plan',
        Receipt::KIND_INSTALLMENT_FIRST_PAYMENT => 'Recibo de pago · Primer abono del plan',
        Receipt::KIND_RECEIVABLE_PAYMENT => 'Recibo de abono',
        Receipt::KIND_SETTLEMENT => 'Recibo de pago de cuentas',
        Receipt::KIND_COMMISSION => 'Recibo de pago · Piso de entrenador',
        Receipt::KIND_PRODUCT_SALE => 'Recibo de venta',
        Receipt::KIND_PRODUCT_CREDIT_SALE => 'Comprobante de entrega a crédito',
    ];

    /**
     * Las grafías que PaymentMethodKind agrupa bajo «Transferencia» pero que en
     * la tirilla se dicen por su nombre: el cliente pagó con Nequi, no con «una
     * transferencia».
     */
    private const SPECIFIC_METHOD_LABELS = [
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'pse' => 'PSE',
    ];

    /** @return array<string, mixed> */
    public function compose(ReceiptCandidate $c): array
    {
        $partes = match (true) {
            $c->payment !== null => $this->payment($c, $c->payment),
            $c->productSale !== null => $this->productSale($c->productSale),
            default => $this->receivablePayments($c),
        };

        return [
            'business' => $this->business(),
            'title' => self::TITLES[$c->kind],
            'cash_label' => $c->cashType === CashShiftType::GYM ? 'Caja de pagos' : 'Caja de productos',
            'operation_ref' => $c->operationRef,
            'cashier' => $partes['cashier'],
            'customer' => $partes['customer'],
            'local_datetime' => $this->localDateTime($c->operationAt),
            'lines' => $partes['lines'],
            'totals' => $partes['totals'],
            'payments' => $partes['payments'],
            'membership' => $partes['membership'],
            'debt' => $partes['debt'],
            'legend' => (string) config('caja.receipts.legend'),
        ];
    }

    /** El título que lleva un recibo de ese tipo (también los de muestra). */
    public static function titleFor(string $kind): string
    {
        return self::TITLES[$kind];
    }

    /** La fecha y hora de la operación, en la hora del gimnasio. */
    public function localDateTime(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone((string) config('caja.timezone'))->format('d/m/Y H:i');
    }

    // ── Cobro de membresía y mejora de plan ─────────────────────────────────

    /** @return array<string, mixed> */
    private function payment(ReceiptCandidate $c, Payment $p): array
    {
        $p->loadMissing(['plan:id,name', 'upgradedFrom:id,name', 'user:id,name', 'member:id,full_name', 'splits']);

        $total = Money::fromAmount($p->amount);
        $conDesglose = $p->hasFinancialSnapshot();
        $descuento = $conDesglose ? Money::fromAmount($p->discount_amount) : Money::zero();
        $subtotal = $total->plus($descuento);

        $esMejora = $c->kind === Receipt::KIND_PLAN_UPGRADE;
        // El plan de origen: la relación si sigue existiendo, o el nombre que la
        // propia mejora congeló al registrarse.
        $desde = $esMejora
            ? ($p->upgradedFrom?->name ?? ($p->upgrade_snapshot['from_plan_name'] ?? null))
            : null;
        $plan = $p->plan?->name;

        $descripcion = match (true) {
            $esMejora => 'Mejora de plan: '.($desde ?? '—').' → '.($plan ?? '—'),
            $plan !== null => 'Plan '.$plan,
            default => 'Pago de membresía',
        };

        $impuestos = [];
        if ($conDesglose && Money::fromAmount($p->tax_amount)->isPositive()) {
            $impuestos[] = [
                'label' => $this->taxLabel($p->tax_rate),
                'amount' => Money::fromAmount($p->tax_amount)->toFloat(),
            ];
        }

        return [
            'cashier' => $p->registered_by_name,
            'customer' => $p->user?->name ?? $p->member?->full_name,
            'lines' => [[
                'description' => $descripcion,
                'quantity' => 1,
                'unit_price' => $subtotal->toFloat(),
                'discount' => $descuento->toFloat(),
                'total' => $total->toFloat(),
            ]],
            'totals' => [
                'subtotal' => $subtotal->toFloat(),
                'discount' => $descuento->toFloat(),
                'taxes' => $impuestos,
                'total' => $total->toFloat(),
                'paid_now' => $total->toFloat(),
                'balance_after' => null,
            ],
            'payments' => $p->splits->isNotEmpty()
                ? $p->splits->sortBy('id')->map(fn (PaymentSplit $s) => [
                    'method_label' => $this->methodLabel($s->method),
                    'amount' => Money::fromAmount($s->amount)->toFloat(),
                    'reference' => $s->reference,
                ])->values()->all()
                // Un solo medio. La referencia del cobro ya va arriba como
                // `operation_ref`; repetirla aquí la imprimiría dos veces.
                : [[
                    'method_label' => $this->methodLabel($p->method),
                    'amount' => $total->toFloat(),
                    'reference' => null,
                ]],
            'membership' => $plan === null ? null : [
                'plan' => $plan,
                'period_from' => $this->date($p->period_start),
                'period_to' => $this->date($p->period_end),
                'upgraded_from' => $desde,
            ],
            'debt' => null,
        ];
    }

    // ── Venta de productos (cobrada o a crédito) ────────────────────────────

    /** @return array<string, mixed> */
    private function productSale(ProductSale $v): array
    {
        $v->loadMissing(['items', 'splits', 'member:id,full_name']);

        $aCredito = $v->status === ProductSale::STATUS_CREDIT;
        $total = Money::fromAmount($v->total);
        $descuento = Money::fromAmount($v->discount);
        $subtotal = $total->plus($descuento);

        $lineas = $v->items->sortBy('id')->map(function (ProductSaleItem $i): array {
            $cantidad = max(1, (int) $i->quantity);

            if ($i->hasFinancialSnapshot()) {
                // Con desglose congelado: lo que valía la línea antes del
                // descuento es base + IVA, y lo cobrado es el bruto.
                $antes = Money::fromAmount($i->base_amount)->plus(Money::fromAmount($i->tax_amount));
                $bruto = Money::fromAmount($i->gross_amount);
                $descuentoLinea = $antes->minus($bruto);

                return [
                    'description' => (string) $i->name,
                    'quantity' => $cantidad,
                    'unit_price' => round($antes->toFloat() / $cantidad, 2),
                    'discount' => $descuentoLinea->isNegative() ? 0.0 : $descuentoLinea->toFloat(),
                    'total' => $bruto->toFloat(),
                ];
            }

            // Venta antigua sin desglose: el descuento se aplicó a la venta, no
            // a la línea, y así se imprime.
            return [
                'description' => (string) $i->name,
                'quantity' => $cantidad,
                'unit_price' => Money::fromAmount($i->unit_price)->toFloat(),
                'discount' => 0.0,
                'total' => Money::fromAmount($i->subtotal)->toFloat(),
            ];
        })->values()->all();

        $deuda = $aCredito
            ? Receivable::query()
                ->where('source_type', ProductSale::class)
                ->where('source_id', $v->id)
                ->orderBy('id')
                ->first()
            : null;

        return [
            'cashier' => $v->cashier_name,
            'customer' => $v->customer_name ?? $v->member?->full_name,
            'lines' => $lineas,
            'totals' => [
                'subtotal' => $subtotal->toFloat(),
                'discount' => $descuento->toFloat(),
                'taxes' => $this->saleTaxes($v),
                'total' => $total->toFloat(),
                // A crédito se entregó el producto y NO entró dinero.
                'paid_now' => $aCredito ? 0.0 : $total->toFloat(),
                'balance_after' => $aCredito && $deuda ? $deuda->total()->toFloat() : null,
            ],
            'payments' => $aCredito ? [] : $this->salePayments($v, $total),
            'membership' => null,
            // La deuda que nace con la entrega: antes no existía, después vale
            // lo que se llevó.
            'debt' => $deuda ? [
                'concept' => $deuda->concept,
                'balance_before' => 0.0,
                'balance_after' => $deuda->total()->toFloat(),
                'due_at' => $this->date($deuda->due_at),
            ] : null,
        ];
    }

    /** @return list<array{label: string, amount: float}> */
    private function saleTaxes(ProductSale $v): array
    {
        $porTasa = [];

        foreach ($v->items as $i) {
            if (! $i->hasFinancialSnapshot() || ! Money::fromAmount($i->tax_amount)->isPositive()) {
                continue;
            }
            $clave = (string) $i->tax_rate;
            $porTasa[$clave] = ($porTasa[$clave] ?? Money::zero())->plus(Money::fromAmount($i->tax_amount));
        }

        ksort($porTasa, SORT_NUMERIC);

        $out = [];
        foreach ($porTasa as $tasa => $importe) {
            $out[] = ['label' => $this->taxLabel((string) $tasa), 'amount' => $importe->toFloat()];
        }

        return $out;
    }

    /** @return list<array{method_label: string, amount: float, reference: string|null}> */
    private function salePayments(ProductSale $v, Money $total): array
    {
        if ($v->splits->isNotEmpty()) {
            return $v->splits->sortBy('id')->map(fn (ProductSaleSplit $s) => [
                'method_label' => $this->methodLabel($s->method),
                'amount' => Money::fromAmount($s->amount)->toFloat(),
                'reference' => $s->reference,
            ])->values()->all();
        }

        return [[
            'method_label' => $this->methodLabel($v->payment_method),
            'amount' => $total->toFloat(),
            'reference' => $v->payment_reference,
        ]];
    }

    // ── Abonos: suelto, comisión, primer abono de plan o cobrar todo ────────

    /** @return array<string, mixed> */
    private function receivablePayments(ReceiptCandidate $c): array
    {
        /** @var list<ReceivablePayment> $abonos */
        $abonos = $c->receivablePayments;
        $primero = $abonos[0];
        $deuda = $primero->receivable;

        $suma = array_reduce($abonos, fn (Money $acc, ReceivablePayment $a) => $acc->plus($a->amountMoney()), Money::zero());
        $saldoDespues = array_reduce(
            $abonos,
            fn (Money $acc, ReceivablePayment $a) => $acc->plus(Money::fromAmount($a->balance_after)),
            Money::zero(),
        );

        $lineas = array_map(fn (ReceivablePayment $a) => [
            'description' => (string) $a->receivable->concept,
            'quantity' => 1,
            'unit_price' => $a->amountMoney()->toFloat(),
            'discount' => 0.0,
            'total' => $a->amountMoney()->toFloat(),
        ], $abonos);

        $suelto = count($abonos) === 1;

        return [
            'cashier' => $primero->created_by_name,
            // El deudor resuelto contra su tabla (socio, entrenador o personal),
            // no el nombre que se copió al fiar.
            'customer' => $deuda->debtor()['name'] ?? $deuda->member?->full_name,
            'lines' => $lineas,
            'totals' => [
                'subtotal' => $suma->toFloat(),
                'discount' => 0.0,
                'taxes' => [],
                'total' => $suma->toFloat(),
                'paid_now' => $suma->toFloat(),
                // Lo que queda en las cuentas que se abonaron, tal y como lo
                // dejó cada abono al registrarse.
                'balance_after' => $saldoDespues->toFloat(),
            ],
            'payments' => $this->receivableMethods($abonos),
            'membership' => $c->kind === Receipt::KIND_INSTALLMENT_FIRST_PAYMENT
                ? $this->installmentMembership($deuda)
                : null,
            // Con varias deudas no hay UN saldo que contar: cada línea es su
            // concepto y el saldo restante va en los totales.
            'debt' => $suelto ? [
                'concept' => $deuda->concept,
                'balance_before' => Money::fromAmount($primero->balance_before)->toFloat(),
                'balance_after' => Money::fromAmount($primero->balance_after)->toFloat(),
                'due_at' => $this->date($deuda->due_at),
            ] : null,
        ];
    }

    /**
     * Medios de los abonos, agrupados: un «cobrar todo» paga cinco deudas con UN
     * billete, y la tirilla no debe decir cinco veces «Efectivo».
     *
     * @param  list<ReceivablePayment>  $abonos
     * @return list<array{method_label: string, amount: float, reference: string|null}>
     */
    private function receivableMethods(array $abonos): array
    {
        $grupos = [];

        foreach ($abonos as $a) {
            $clave = $this->methodLabel($a->method).'|'.($a->reference ?? '');
            $grupos[$clave] ??= [
                'method_label' => $this->methodLabel($a->method),
                'amount' => Money::zero(),
                'reference' => $a->reference,
            ];
            $grupos[$clave]['amount'] = $grupos[$clave]['amount']->plus($a->amountMoney());
        }

        return array_values(array_map(fn (array $g) => [
            'method_label' => $g['method_label'],
            'amount' => $g['amount']->toFloat(),
            'reference' => $g['reference'],
        ], $grupos));
    }

    /**
     * El plan que activó el primer abono, con el periodo que de verdad cubre el
     * cobro (MembershipPeriod lo fija en el Payment del plan).
     *
     * @return array<string, mixed>|null
     */
    private function installmentMembership(Receivable $deuda): ?array
    {
        $pago = $deuda->source;
        if (! $pago instanceof Payment) {
            return null;
        }

        $pago->loadMissing('plan:id,name');
        if ($pago->plan === null) {
            return null;
        }

        return [
            'plan' => $pago->plan->name,
            'period_from' => $this->date($pago->period_start),
            'period_to' => $this->date($pago->period_end),
            'upgraded_from' => null,
        ];
    }

    // ── Comunes ─────────────────────────────────────────────────────────────

    /**
     * Cabecera de la tirilla. La razón social, el NIT, la dirección y el
     * teléfono son los que ya se declaran ante la DIAN (`billing.company`); el
     * nombre comercial, el que encabeza el informe de cierre.
     *
     * Pública para los recibos de MUESTRA de la página de prueba
     * (ReceiptTextLayout): el encabezado tiene que ser el mismo que el real.
     *
     * @return array{name: string|null, legal_name: string|null, nit: string|null, address: string|null, phone: string|null}
     */
    public function business(): array
    {
        $empresa = (array) config('billing.company', []);
        $nit = $this->blankToNull($empresa['nit'] ?? null);
        $dv = $this->blankToNull($empresa['dv'] ?? null);

        return [
            'name' => $this->blankToNull(config('caja.receipts.business_name')),
            'legal_name' => $this->blankToNull($empresa['name'] ?? null),
            'nit' => $nit === null ? null : ($dv === null ? $nit : $nit.'-'.$dv),
            'address' => $this->blankToNull($empresa['address'] ?? null),
            'phone' => $this->blankToNull($empresa['phone'] ?? null),
        ];
    }

    private function methodLabel(?string $raw): string
    {
        return self::SPECIFIC_METHOD_LABELS[strtolower(trim((string) $raw))]
            ?? PaymentMethodKind::normalize($raw)->label();
    }

    /** «IVA 19% (incluido)». La tasa llega como texto decimal ("19.00"). */
    private function taxLabel(?string $rate): string
    {
        $tasa = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');

        return 'IVA '.$tasa.'% (incluido)';
    }

    /**
     * Una fecha de calendario, como se lee en Colombia.
     *
     * Se formatea con `format()` y SIN convertir de zona: `period_start` y
     * `due_at` son días pactados, no instantes. Convertirlos a Bogotá desde la
     * medianoche UTC los movería al día anterior.
     */
    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d/m/Y');
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return null;
    }

    private function blankToNull(mixed $value): ?string
    {
        $texto = trim((string) $value);

        return $texto === '' ? null : $texto;
    }
}
