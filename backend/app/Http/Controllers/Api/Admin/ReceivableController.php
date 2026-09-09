<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Exceptions\ReceivableException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use App\Services\Audit\FinancialAudit;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\PaymentMembershipActivator;
use App\Support\Access\AdminActor;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cuentas por cobrar: quién debe, cuánto, y el registro de sus abonos.
 *
 * Este controlador NO calcula saldos ni decide estados: eso vive en
 * {@see ReceivableService}, que es quien bloquea la fila y garantiza que
 * TOTAL = PAGADO + SALDO. Aquí solo se valida la forma de la petición y se
 * traduce el resultado.
 *
 * Los créditos de PRODUCTO no se crean aquí sino en {@see CajaController}, con
 * `credit: true`: necesitan cotizar líneas, congelar el snapshot fiscal y mover
 * existencias, y duplicar ese flujo sería tener dos formas distintas de vender
 * lo mismo.
 */
class ReceivableController extends Controller
{
    /** GET /api/admin/receivables */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'member_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Receivable::STATUSES)],
            'type' => ['nullable', Rule::in(CashShiftType::values())],
            'outstanding' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $q = Receivable::query()->with('member:id,full_name,document_number,is_staff');

        if (! empty($filtros['member_id'])) {
            $q->where('member_id', (int) $filtros['member_id']);
        }
        if (! empty($filtros['status'])) {
            $q->where('status', $filtros['status']);
        }
        if (! empty($filtros['type'])) {
            $q->where('type', $filtros['type']);
        }
        if ($request->boolean('outstanding')) {
            $q->outstanding();
        }
        if (! empty($filtros['from'])) {
            $q->whereDate('created_at', '>=', $filtros['from']);
        }
        if (! empty($filtros['to'])) {
            $q->whereDate('created_at', '<=', $filtros['to']);
        }
        if (! empty($filtros['search'])) {
            $texto = trim($filtros['search']);
            $q->where(function ($sub) use ($texto): void {
                $sub->where('concept', 'like', "%{$texto}%")
                    ->orWhereHas('member', fn ($m) => $m
                        ->where('full_name', 'like', "%{$texto}%")
                        ->orWhere('document_number', 'like', "%{$texto}%"));
            });
        }

        $cuentas = $q->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'ok' => true,
            'data' => $cuentas->map(fn (Receivable $r) => $r->toCrmArray())->all(),
            // El total pendiente de lo consultado, para no obligar al CRM a
            // sumarlo y arriesgarse a que su suma difiera de la del servidor.
            'summary' => [
                'count' => $cuentas->count(),
                'outstanding_total' => round(
                    $cuentas->reduce(fn ($acc, Receivable $r) => $acc + $r->balance()->toFloat(), 0.0),
                    2,
                ),
            ],
        ]);
    }

    /** GET /api/admin/receivables/{receivable} — estado de cuenta. */
    public function show(Receivable $receivable): JsonResponse
    {
        $receivable->load('member:id,full_name,document_number,is_staff');

        return response()->json([
            'ok' => true,
            'data' => $receivable->toCrmArray(withPayments: true),
        ]);
    }

    /**
     * POST /api/admin/receivables — deuda sin venta detrás.
     *
     * Para obligaciones acordadas en mostrador que no pasan por el catálogo de
     * productos. Un crédito de producto NO se crea por aquí: ver la nota de
     * clase.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'type' => ['required', Rule::in(CashShiftType::values())],
            'concept' => ['required', 'string', 'min:3', 'max:160'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $cuenta = app(ReceivableService::class)->create(
                member: Member::findOrFail((int) $data['member_id']),
                type: CashShiftType::from($data['type']),
                concept: $data['concept'],
                total: Money::fromAmount($data['total_amount']),
                actor: AdminActor::from($request),
                notes: $data['notes'] ?? null,
            );
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => $cuenta->load('member')->toCrmArray(),
        ], 201);
    }

    /**
     * POST /api/admin/receivables/{receivable}/payments — registrar un abono.
     *
     * `client_request_id` lo manda el CRM y hace la operación idempotente: si
     * la petición se repite —doble clic, reintento de red, dos pestañas— se
     * devuelve el abono ya registrado en vez de cobrar dos veces.
     */
    public function pay(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethodKind::selectableAtCounter())],
            'client_request_id' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $abono = app(ReceivableService::class)->settle(
                receivable: $receivable,
                amount: Money::fromAmount($data['amount']),
                method: $data['method'],
                actor: AdminActor::from($request),
                clientRequestId: $data['client_request_id'] ?? null,
                reference: $data['reference'] ?? null,
                notes: $data['notes'] ?? null,
            );
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        } catch (CashShiftException $e) {
            // Sin turno abierto no hay dónde registrar el dinero. 409, igual
            // que en el cobro de membresías.
            return response()->json([
                'ok' => false,
                'code' => $e->code_,
                'message' => 'No hay una caja de '.$receivable->type->label()
                    .' abierta. Ábrela antes de registrar el abono.',
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'data' => $abono->toCrmArray(),
            'receivable' => $receivable->fresh()->load('member')->toCrmArray(),
        ], 201);
    }

    /**
     * POST /api/admin/receivables/plan — vender un plan cobrando solo una parte.
     *
     * POLÍTICA: ACTIVATE_ON_FIRST_PAYMENT. La membresía se activa con el primer
     * pago válido y NO se vuelve a extender con los abonos siguientes. El socio
     * entra al gimnasio desde el primer día y la deuda se cobra después; volver
     * a extender en cada abono le regalaría un mes por cada plazo.
     *
     * QUIÉN CUENTA QUÉ, para no cobrar dos veces:
     *  · El `Payment` del plan nace SIN turno (`cash_shift_id` nulo). Es el
     *    registro de la membresía y la base del informe de ingresos, pero no
     *    entra en ningún arqueo.
     *  · El dinero que de verdad se recibe son los ABONOS, cada uno con su
     *    turno. El cierre del día cuenta esos, y solo esos.
     */
    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'first_payment' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethodKind::selectableAtCounter())],
            'client_request_id' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::findOrFail((int) $data['plan_id']);
        $total = Money::fromAmount($plan->price);
        $primero = Money::fromAmount($data['first_payment']);

        if (! $total->isPositive()) {
            return response()->json([
                'ok' => false, 'code' => 'invalid_total',
                'message' => 'El plan no tiene precio configurado.',
            ], 422);
        }

        // Un «abono» por el total no es un plan a plazos: es una venta normal,
        // y para eso ya está el cobro de siempre. Se rechaza para no abrir dos
        // caminos que hacen lo mismo y se contabilizan distinto.
        if ($primero->greaterThan($total) || $primero->equals($total)) {
            return response()->json([
                'ok' => false, 'code' => 'not_partial',
                'message' => 'Para cobrar el plan completo usa el cobro normal, no un plan a plazos.',
            ], 422);
        }

        $member = Member::where('user_id', (int) $data['user_id'])->first();
        if (! $member) {
            return response()->json([
                'ok' => false, 'code' => 'member_not_found',
                'message' => 'Esa cuenta no tiene ficha de socio.',
            ], 422);
        }

        $actor = AdminActor::from($request);

        // El Payment y la cuenta nacen juntos: si una de las dos fallara, un
        // plan quedaría activado sin deuda registrada, o al revés.
        $pago = DB::transaction(function () use ($data, $plan, $total, $actor, $request) {
            $pago = Payment::create([
                'user_id' => (int) $data['user_id'],
                'plan_id' => $plan->id,
                'amount' => $total->toFloat(),
                'method' => 'receivable',
                'status' => 'paid',
                'paid_at' => now(),
                // SIN turno, a propósito: ver la nota del método.
                'cash_shift_id' => null,
            ]);

            app(FinancialAudit::class)->paymentCreated($pago, $actor, $request);

            return $pago;
        });

        try {
            $cuenta = app(ReceivableService::class)->create(
                member: $member,
                type: CashShiftType::GYM,
                concept: 'Plan '.$plan->name,
                total: $total,
                actor: $actor,
                source: $pago,
                notes: $data['notes'] ?? null,
            );

            $abono = app(ReceivableService::class)->settle(
                receivable: $cuenta,
                amount: $primero,
                method: $data['method'],
                actor: $actor,
                clientRequestId: $data['client_request_id'] ?? null,
                reference: $data['reference'] ?? null,
            );
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        } catch (CashShiftException $e) {
            return response()->json([
                'ok' => false, 'code' => $e->code_,
                'message' => 'No hay una caja de gimnasio abierta. Ábrela antes de registrar el cobro.',
            ], 409);
        }

        // UNA sola vez, aquí. Los abonos siguientes pasan por `pay()`, que no
        // toca membresías.
        app(PaymentMembershipActivator::class)->extendMembership($pago);

        return response()->json([
            'ok' => true,
            'data' => $cuenta->fresh()->load('member')->toCrmArray(withPayments: true),
            'payment_id' => $pago->id,
            'first_payment' => $abono->toCrmArray(),
        ], 201);
    }

    /**
     * POST /api/admin/receivables/payments/{payment}/reverse — anular un abono.
     *
     * La ÚNICA corrección posible. No hay borrado ni edición del importe: el
     * dinero entró, y eso no se deshace reescribiendo la historia. Quedan las
     * dos operaciones —el abono y su anulación— y el saldo se recalcula solo.
     *
     * Exige `receivables.manage`, no `receivables.operate`: cobrar es del
     * mostrador, deshacer un cobro es de supervisión. Quien se equivoca no
     * debería poder tapar su propio error sin que nadie más lo vea.
     *
     * LA MEMBRESÍA NO SE TOCA. Ver la nota en ReceivableService::reverse().
     */
    public function reversePayment(Request $request, ReceivablePayment $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $abono = app(ReceivableService::class)
                ->reverse($payment, $data['reason'], AdminActor::from($request));
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => $abono->fresh()->toCrmArray(),
            'receivable' => $abono->receivable()->first()?->load('member')->toCrmArray(),
        ]);
    }

    /**
     * 422 con el código de la causa. El CRM distingue un sobrepago —donde el
     * mensaje lleva el saldo real y hay que reintentar con otra cifra— de una
     * cuenta ya saldada, donde no hay nada que reintentar.
     */
    private function receivableError(ReceivableException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $e->code_,
            'message' => $e->getMessage(),
        ], 422);
    }
}
