<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Exceptions\CorrectionException;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Admin;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\ReceiptSource;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Audit\FinancialAudit;
use App\Services\Payments\MembershipPeriod;
use App\Services\RealtimeEvents;
use App\Support\Access\CrmPermission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Correcciones de un cobro de la Caja de Gimnasio que no son editar el cobro:
 * DEVOLVER su dinero, la VISTA PREVIA de cualquier corrección y los ENLACES que
 * el detalle enseña. Anular y corregir el medio siguen en
 * PaymentController::update(), que es por donde siempre se hizo.
 *
 * ANULAR ≠ DEVOLVER (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §1): anular dice
 * que el dinero nunca entró; devolver, que entró y sale hoy. Por eso la
 * devolución deja el cobro contando en su turno y apunta la salida en el turno
 * ABIERTO (`cash_refunds`).
 *
 * Orden de bloqueo, el de toda la caja del gimnasio: socio → cobro → deuda.
 */
class PaymentCorrections
{
    public function __construct(
        private readonly CorrectionGuards $guards,
        private readonly CashShiftService $shifts,
    ) {}

    /**
     * Devuelve el dinero de un cobro.
     *
     * @param  array{reason: string, method?: ?string, external_reference?: ?string, client_request_id?: ?string}  $input
     * @return array{payment: Payment, refund: ?CashRefund, already: bool, replay: bool, member_id: ?int}
     */
    public function refund(Payment $payment, array $input, ?Admin $actor, Request $request): array
    {
        $llave = $input['client_request_id'] ?? null;

        // Camino rápido del reintento; el índice único es el que lo garantiza.
        if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
            return $this->replay($previa, $payment);
        }

        try {
            $resultado = DB::transaction(fn () => $this->refundLocked($payment, $input, $actor, $request), 3);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones con la misma llave a la vez: la otra ganó. No es un
            // error, la devolución existe —una sola vez—.
            if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
                return $this->replay($previa, $payment);
            }

            throw $e;
        }

        if (! $resultado['already'] && ! $resultado['replay']) {
            $this->notifyMember($resultado['payment']);
        }

        return $resultado;
    }

    /** @return array{payment: Payment, refund: ?CashRefund, already: bool, replay: bool, member_id: ?int} */
    private function refundLocked(Payment $payment, array $input, ?Admin $actor, Request $request): array
    {
        // Turno abierto PRIMERO (compartido), como toda operación que apunta
        // dinero a un turno; después socio → cobro → deuda. Si no hay turno se
        // dice más abajo, cuando ya se sabe que no es pasarela ni ya devuelto.
        $turnoAbierto = null;
        if (! CorrectionGuards::isGatewayPayment($payment)) {
            try {
                $turnoAbierto = $this->shifts->lockOpen(CashShiftType::GYM);
            } catch (CashShiftException) {
                $turnoAbierto = null;
            }
        }

        User::whereKey($payment->user_id)->lockForUpdate()->first();
        $cobro = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

        // La llave, OTRA VEZ con el cobro bloqueado: el reintento que esperó
        // detrás de su propia petición recibe la devolución ya hecha.
        $llave = $input['client_request_id'] ?? null;
        if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
            return $this->replay($previa, $cobro);
        }

        $estado = strtolower((string) $cobro->status);

        // Ya devuelto: idempotente, sin traza nueva ni dinero que salga dos veces.
        if (in_array($estado, ['refunded', 'reembolsado', 'reembolsada'], true)) {
            return [
                'payment' => $cobro, 'already' => true, 'replay' => false, 'member_id' => null,
                'refund' => CashRefund::where('refundable_type', Payment::class)->where('refundable_id', $cobro->id)->latest('id')->first(),
            ];
        }

        if (! in_array($estado, PaymentController::PAID_STATUSES, true)) {
            throw CorrectionException::notPaid('payment_not_paid', $cobro->status);
        }

        // El devengo de un plan a plazos no es dinero: el que entró está en sus
        // abonos, y se devuelve por ahí (Créditos).
        $deuda = $this->liveReceivable($cobro);
        if (app(CashReceipts::class)->isAccrual($cobro->method) || $deuda !== null) {
            throw CorrectionException::useReceivableFlow($deuda?->id
                ?? Receivable::where('source_type', $cobro->getMorphClass())->where('source_id', $cobro->id)->value('id'));
        }

        $this->assertNoDependentUpgrade($cobro);
        $this->guards->assertNotTransferred($cobro);
        $this->guards->assertInvoiceAllows($cobro);

        $pasarela = CorrectionGuards::isGatewayPayment($cobro);
        $turno = null;
        if ($pasarela) {
            // El dinero volvió por el panel de la pasarela; aquí solo se deja
            // constancia con su referencia. Del cajón no sale nada.
            if (blank($input['external_reference'] ?? null)) {
                throw ValidationException::withMessages(['external_reference' => [
                    'Un cobro de pasarela se devuelve en el panel de la pasarela: indica aquí la referencia de esa devolución.',
                ]]);
            }
            $medio = (string) $cobro->method;
        } else {
            $turno = $turnoAbierto ?? throw CorrectionException::noOpenShift('gimnasio');
            $medio = $input['method'] ?? null;
            if ($medio === null) {
                if ($cobro->isMixed()) {
                    throw ValidationException::withMessages(['method' => [
                        'Este cobro se pagó con varios medios: indica por cuál sale la devolución.',
                    ]]);
                }
                $medio = (string) $cobro->method;
            }
            $medio = strtolower(trim($medio));
        }

        $estadoPrevio = (string) $cobro->status;
        $cobro->update(['status' => 'refunded']);

        // Se devuelven los días EN LA MISMA TRANSACCIÓN: sin dinero no hay plan.
        $devuelto = $this->revertMembership($cobro);

        $devolucion = CashRefund::create([
            'cash_type' => CashShiftType::GYM->value,
            'cash_shift_id' => $turno?->id,
            'refundable_type' => $cobro->getMorphClass(),
            'refundable_id' => $cobro->id,
            'amount' => number_format((float) $cobro->amount, 2, '.', ''),
            'method' => $medio,
            'reason' => trim((string) $input['reason']),
            'external_reference' => $input['external_reference'] ?? null,
            'client_request_id' => $input['client_request_id'] ?? null,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
        ]);

        $original = $cobro->cash_shift_id ? CashShift::find($cobro->cash_shift_id) : null;
        app(FinancialAudit::class)->paymentRefunded($cobro, $devolucion, $estadoPrevio, $actor, $request, array_filter([
            'shift_closed' => $original !== null && ! $original->isOpen(),
            'gateway' => $pasarela,
            'membership' => $devuelto,
        ], static fn ($v) => $v !== null));

        return ['payment' => $cobro, 'refund' => $devolucion, 'already' => false, 'replay' => false, 'member_id' => null];
    }

    /** @return array{payment: Payment, refund: CashRefund, already: bool, replay: bool, member_id: ?int} */
    private function replay(CashRefund $previa, Payment $payment): array
    {
        if ($previa->refundable_type !== $payment->getMorphClass() || (int) $previa->refundable_id !== (int) $payment->id) {
            throw CorrectionException::requestIdConflict();
        }

        return ['payment' => $payment->fresh(), 'refund' => $previa, 'already' => false, 'replay' => true, 'member_id' => null];
    }

    /**
     * Devuelve los días que dio el cobro. Una mejora de plan TIENE que poder
     * devolverse —su periodo sustituyó otro—; un cobro normal cuya cadena no
     * cuadra se deja como está (MembershipPeriod::revert lo registra).
     *
     * @return array{end: string, previous_end: string, days: int}|null
     */
    public function revertMembership(Payment $cobro): ?array
    {
        $devuelto = app(MembershipPeriod::class)->revert($cobro);

        if ($cobro->upgraded_from_plan_id && ! $devuelto) {
            throw ValidationException::withMessages(['status' => [
                'La membresía tiene cambios posteriores. Revisa sus periodos antes de deshacer esta mejora.',
            ]]);
        }

        return $devuelto;
    }

    /** Un cobro del que depende una mejora de plan cobrada no se deshace antes que ella. */
    public function assertNoDependentUpgrade(Payment $cobro): void
    {
        if ($this->dependentUpgradeExists($cobro)) {
            throw ValidationException::withMessages([
                'status' => ['Primero anula la mejora que depende de este cobro.'],
            ]);
        }
    }

    public function dependentUpgradeExists(Payment $cobro): bool
    {
        return Payment::where('user_id', $cobro->user_id)
            ->whereNotNull('upgraded_from_plan_id')
            ->where('upgrade_snapshot->source_payment_id', $cobro->id)
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')', PaymentController::PAID_STATUSES)
            ->exists();
    }

    /** La deuda no anulada que cuelga del cobro (plan a plazos), bloqueada. */
    public function liveReceivable(Payment $cobro, bool $lock = true): ?Receivable
    {
        $q = Receivable::where('source_type', $cobro->getMorphClass())->where('source_id', $cobro->id);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get()->first(fn (Receivable $d) => ! $d->isCancelled());
    }

    /** Avisa a la app después del commit: membresía, pagos y beneficios. */
    public function notifyMember(Payment $cobro): void
    {
        $memberId = User::find($cobro->user_id)?->appMember?->id;
        RealtimeEvents::payment($memberId);
        RealtimeEvents::membership($memberId);
        RealtimeEvents::features($memberId);
    }

    // ── Vista previa ────────────────────────────────────────────────────────

    /**
     * Lo que haría una corrección, SIN escribir nada.
     *
     * Los días que se quitarían se calculan con el mismo código que la
     * corrección real, dentro de una transacción que se deshace siempre: así la
     * cifra que ve el administrador no puede discrepar de la que se aplicará.
     * Aun así no se da por buena: la corrección revalida todo bajo bloqueo.
     *
     * @return array<string, mixed>
     */
    public function preview(Payment $payment, string $accion, ?Admin $actor): array
    {
        $bloqueos = [];
        $estado = strtolower((string) $payment->status);
        $pagado = in_array($estado, PaymentController::PAID_STATUSES, true);
        $anulado = in_array($estado, ['cancelled', 'canceled', 'anulado', 'anulada'], true);
        $devuelto = in_array($estado, ['refunded', 'reembolsado', 'reembolsada'], true);
        $pasarela = CorrectionGuards::isGatewayPayment($payment);
        $devengo = app(CashReceipts::class)->isAccrual($payment->method);
        $turno = $payment->cash_shift_id ? CashShift::find($payment->cash_shift_id) : null;
        $deuda = Receivable::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)
            ->latest('id')->first();
        $bloquea = static function (?CorrectionException $e) use (&$bloqueos): void {
            if ($e !== null) {
                $bloqueos[] = $e->toBlocker();
            }
        };

        $permiso = match ($accion) {
            'refund' => CrmPermission::PAYMENTS_REFUND,
            'method' => CrmPermission::PAYMENTS_CORRECT,
            default => 'payments.cancel',
        };
        if (! CrmPermission::allows($actor, $permiso)) {
            $bloqueos[] = ['code' => 'forbidden', 'message' => 'No tienes permiso para esta acción.', 'required_permission' => $permiso];
        }

        $refundEffect = null;
        if ($accion === 'cancel') {
            if ($anulado) {
                $bloqueos[] = ['code' => 'already_cancelled', 'message' => 'Este cobro ya está anulado.'];
            }
            if ($devuelto) {
                $bloquea(CorrectionException::alreadyRefunded());
            }
            if ($pagado && $pasarela) {
                $bloquea(CorrectionException::gatewayPayment());
            }
            if ($pagado) {
                $bloquea($this->guards->transferBlocker($payment));
                $bloquea($this->guards->invoiceBlocker($payment));
                if ($deuda !== null && ! $deuda->isCancelled()) {
                    $bloqueos[] = [
                        'code' => 'receivable_alive',
                        'message' => "Este cobro es un plan a plazos y su cuenta por cobrar #{$deuda->id} sigue vigente. Anúlala primero en Créditos.",
                        'link' => ['type' => 'receivable', 'id' => $deuda->id],
                    ];
                }
                if ($this->dependentUpgradeExists($payment)) {
                    $bloqueos[] = ['code' => 'upgrade_depends', 'message' => 'Primero anula la mejora que depende de este cobro.'];
                }
            }
            $bloquea($this->guards->shiftBlocker($turno, $actor));
        } elseif ($accion === 'refund') {
            if ($devuelto) {
                $bloqueos[] = ['code' => 'already_refunded', 'message' => 'Este cobro ya está devuelto.'];
            } elseif (! $pagado) {
                $bloquea(CorrectionException::notPaid('payment_not_paid', (string) $payment->status));
            }
            if ($devengo || ($deuda !== null && ! $deuda->isCancelled())) {
                $bloquea(CorrectionException::useReceivableFlow($deuda?->id));
            }
            if ($pagado) {
                $bloquea($this->guards->transferBlocker($payment));
                $bloquea($this->guards->invoiceBlocker($payment));
                if ($this->dependentUpgradeExists($payment)) {
                    $bloqueos[] = ['code' => 'upgrade_depends', 'message' => 'Primero anula la mejora que depende de este cobro.'];
                }
            }
            $abierto = $pasarela ? null : CashShift::currentOfType(CashShiftType::GYM);
            if (! $pasarela && $abierto === null) {
                $bloquea(CorrectionException::noOpenShift('gimnasio'));
            }
            $refundEffect = [
                'shift_id' => $abierto?->id,
                'method' => $pasarela ? $payment->method : ($payment->isMixed() ? null : $payment->method),
                'amount' => (float) $payment->amount,
                'requires_external_reference' => $pasarela,
                'requires_method' => ! $pasarela && $payment->isMixed(),
            ];
        } else { // method
            if ($devuelto) {
                $bloquea(CorrectionException::alreadyRefunded());
            }
            if ($pasarela) {
                $bloquea(CorrectionException::gatewayPayment());
            }
            if ($devengo) {
                $bloqueos[] = ['code' => 'accrual_method', 'message' => 'El cobro de un plan a plazos no tiene medio: su dinero entra por los abonos.'];
            }
            $bloquea($this->guards->shiftBlocker($turno, $actor));
        }

        $membresia = null;
        if (in_array($accion, ['cancel', 'refund'], true) && $pagado && $payment->plan_id) {
            $membresia = $this->simulateRevert($payment);
            if (isset($membresia['blocked_reason'])) {
                // La corrección real lo rechazaría con este mismo mensaje.
                $bloqueos[] = ['code' => 'membership_blocked', 'message' => $membresia['blocked_reason']];
                unset($membresia['blocked_reason']);
            }
        }

        return [
            'action' => $accion,
            'allowed' => $bloqueos === [],
            'blockers' => array_values($bloqueos),
            'effects' => [
                'cash_shift' => $turno === null ? null : [
                    'id' => $turno->id,
                    'status' => $turno->status->value,
                    'closed_at' => optional($turno->closed_at)->toIso8601String(),
                ],
                'membership' => $membresia,
                // Igual que en los enlaces: la deuda, solo con `receivables.view`.
                'receivable' => ($deuda === null || ! CrmPermission::allows($actor, 'receivables.view')) ? null : [
                    'id' => $deuda->id,
                    'status' => $deuda->status,
                    'balance' => $deuda->balance()->toFloat(),
                    'applied_count' => $deuda->appliedPayments()->count(),
                ],
                'invoice' => $this->guards->invoiceSummary($payment),
                'receipts' => $this->receipts($payment),
                'gateway' => $pasarela,
                'refund' => $refundEffect,
            ],
        ];
    }

    /**
     * Los días que quitaría la anulación o la devolución, calculados con
     * MembershipPeriod::revert() dentro de una transacción que SIEMPRE se
     * deshace.
     *
     * @return array{current_end: ?string, end_after: ?string, days_removed: int}|null
     */
    private function simulateRevert(Payment $payment): ?array
    {
        $finActual = User::whereKey($payment->user_id)->value('membership_end_date');
        $actual = $finActual ? (string) $finActual : null;
        $copia = Payment::findOrFail($payment->id);

        DB::beginTransaction();
        try {
            // `simulacion`: revert() no deja en el log avisos de una anulación
            // que no va a ocurrir (revisión de seguridad B-4).
            $devuelto = app(MembershipPeriod::class)->revert($copia, simulacion: true);
        } catch (ValidationException $e) {
            // Una mejora que no se puede deshacer: la corrección real la
            // rechazaría con este mensaje, y la vista previa lo dice como bloqueo.
            $devuelto = collect($e->errors())->flatten()->first() ?? $e->getMessage();
        } catch (RuntimeException $e) {
            $devuelto = $e->getMessage();
        } finally {
            DB::rollBack();
        }

        if (is_string($devuelto)) {
            return ['current_end' => $actual, 'end_after' => null, 'days_removed' => 0, 'blocked' => true, 'blocked_reason' => $devuelto];
        }

        // null: no hay días que devolver (sin periodo congelado, o la cadena no
        // explica el vencimiento y revert() deja la membresía como está).
        return [
            'current_end' => $actual,
            'end_after' => is_array($devuelto) ? $devuelto['end'] : $actual,
            'days_removed' => is_array($devuelto) ? (int) $devuelto['days'] : 0,
        ];
    }

    // ── Enlaces del detalle ─────────────────────────────────────────────────

    /**
     * Todo lo que cuelga de un cobro, para que el detalle lo enseñe sin que el
     * CRM tenga que buscarlo pantalla por pantalla.
     *
     * @return array<string, mixed>
     */
    public function links(Payment $payment, ?Admin $actor = null): array
    {
        $deuda = Receivable::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)
            ->latest('id')->first();
        $turno = $payment->cash_shift_id ? CashShift::find($payment->cash_shift_id) : null;

        $enlaces = [
            'receivable' => $deuda === null ? null : [
                'id' => $deuda->id,
                'status' => $deuda->status,
                'balance' => $deuda->balance()->toFloat(),
                'applied_count' => $deuda->appliedPayments()->count(),
            ],
            'cash_shift' => $turno === null ? null : [
                'id' => $turno->id,
                'status' => $turno->status->value,
                'closed_at' => optional($turno->closed_at)->toIso8601String(),
            ],
            'invoice' => $this->guards->invoiceSummary($payment),
            'receipts' => $this->receipts($payment),
            'refunds' => CashRefund::where('refundable_type', $payment->getMorphClass())
                ->where('refundable_id', $payment->id)->orderBy('id')->get()
                ->map(fn (CashRefund $r) => $r->toCrmArray())->all(),
            'possible_duplicates' => $this->possibleDuplicates($payment),
        ];

        // La deuda vinculada es de Créditos: solo con `receivables.view`.
        if (! CrmPermission::allows($actor, 'receivables.view')) {
            unset($enlaces['receivable']);
        }

        return $enlaces;
    }

    /**
     * Otro cobro NO anulado del mismo socio, mismo importe y mismo concepto
     * (plan), registrado a menos de 10 minutos. Es la huella del doble clic o
     * del cobro registrado dos veces por dos personas.
     *
     * @return list<array<string, mixed>>
     */
    public function possibleDuplicates(Payment $payment): array
    {
        if ($payment->created_at === null || $payment->user_id === null) {
            return [];
        }

        $desde = $payment->created_at->copy()->subMinutes(10);
        $hasta = $payment->created_at->copy()->addMinutes(10);
        $anulados = ['cancelled', 'canceled', 'anulado', 'anulada', 'failed'];

        return Payment::query()
            ->where('user_id', $payment->user_id)
            ->whereKeyNot($payment->id)
            ->when($payment->plan_id, fn ($q) => $q->where('plan_id', $payment->plan_id), fn ($q) => $q->whereNull('plan_id'))
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereRaw('LOWER(status) NOT IN ('.implode(',', array_fill(0, count($anulados), '?')).')', $anulados)
            ->orderBy('id')
            ->get(['id', 'amount', 'status', 'method', 'reference', 'created_at'])
            ->filter(fn (Payment $p) => (int) round($p->amount * 100) === (int) round((float) $payment->amount * 100))
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'method' => $p->method,
                'reference' => $p->reference,
                'created_at' => optional($p->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, number: string}> */
    public function receipts(Payment $payment): array
    {
        return DB::table('receipt_sources')
            ->join('receipts', 'receipts.id', '=', 'receipt_sources.receipt_id')
            ->where('receipt_sources.source_type', ReceiptSource::TYPE_PAYMENT)
            ->where('receipt_sources.source_id', $payment->id)
            ->orderBy('receipts.id')
            ->get(['receipts.id', 'receipts.number'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => (string) $r->number])
            ->all();
    }
}
