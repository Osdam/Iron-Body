<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\CashShiftException;
use App\Exceptions\ReceivableException;
use App\Exceptions\PlanReplayException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use App\Services\Audit\FinancialAudit;
use App\Services\Caja\DebtorDirectory;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\PaymentMembershipActivator;
use App\Support\Access\AdminActor;
use App\Support\Caja\PaymentMethodKind;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            // Las cuatro vistas de Caja, por nombre. Antes el CRM las componía
            // con `outstanding=true`, y ese `true` viajaba como TEXTO: la regla
            // `boolean` de Laravel admite 1, 0, "1" y "0" pero no "true", así
            // que la pestaña Créditos respondía 422 y en pantalla salía «No se
            // pudo cargar las cuentas por cobrar». Un parámetro con nombre no
            // se puede escribir mal de esa manera.
            'scope' => ['nullable', Rule::in(['all', 'credits', 'installments', 'paid'])],
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
        match ($filtros['scope'] ?? null) {
            // «Todas» son las que todavía se deben; una cuenta saldada ya no se
            // cobra y no tiene sitio en un listado de cobro.
            'all' => $q->outstanding(),
            'credits' => $q->fromCreditSale(),
            'installments' => $q->withInstallments(),
            'paid' => $q->settled(),
            default => null,
        };

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

        // Los deudores se resuelven de una vez, agrupados por tabla: uno a uno
        // serían 300 consultas para pintar una pantalla.
        $fichas = app(DebtorDirectory::class)->resolveMany(
            $cuentas->filter(fn (Receivable $r) => $r->debtor_type !== null && $r->debtor_id !== null)
                ->map(fn (Receivable $r) => [$r->debtor_type, $r->debtor_id])
                ->all(),
        );

        return response()->json([
            'ok' => true,
            'data' => $cuentas->map(fn (Receivable $r) => $r->toCrmArray(
                debtor: $fichas[$r->debtor_type?->value.':'.$r->debtor_id] ?? null,
            ))->all(),
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

    /**
     * GET /api/admin/receivables/debtors — a quién se le puede fiar.
     *
     * Se busca DENTRO de un tipo, nunca en todos a la vez: quien está en el
     * mostrador ya sabe si le está fiando a un socio o a la recepcionista, y
     * mezclarlos es lo que hacía que dos personas con el mismo nombre fueran
     * indistinguibles.
     */
    public function debtors(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(DebtorType::values())],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => app(DebtorDirectory::class)->search(
                DebtorType::from($data['type']),
                $data['search'] ?? '',
            ),
        ]);
    }

    /**
     * El deudor que viene en la petición, sea en la forma nueva o en la vieja.
     *
     * @param  array<string,mixed>  $data
     * @return array{0: ?DebtorType, 1: int}
     */
    private function debtorFrom(array $data): array
    {
        if (! empty($data['debtor_type']) && ! empty($data['debtor_id'])) {
            return [DebtorType::from($data['debtor_type']), (int) $data['debtor_id']];
        }

        if (! empty($data['member_id'])) {
            return [DebtorType::MEMBER, (int) $data['member_id']];
        }

        return [null, 0];
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
            // `member_id` se sigue admitiendo para no romper a quien ya llama
            // así; el par debtor_type/debtor_id es la forma general.
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'debtor_type' => ['nullable', Rule::in(DebtorType::values())],
            'debtor_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', Rule::in(CashShiftType::values())],
            'concept' => ['required', 'string', 'min:3', 'max:160'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            // Fecha límite para saldar. Opcional: sin ella la deuda no vence y
            // nunca bloquea. `after_or_equal:today` porque pactar un plazo ya
            // pasado sería crear un moroso de nacimiento.
            'due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        [$debtorType, $debtorId] = $this->debtorFrom($data);

        if ($debtorType === null) {
            return response()->json([
                'ok' => false,
                'code' => 'debtor_required',
                'message' => 'Hay que decir a quién se le fía.',
            ], 422);
        }

        try {
            $cuenta = app(ReceivableService::class)->create(
                debtorType: $debtorType,
                debtorId: $debtorId,
                type: CashShiftType::from($data['type']),
                concept: $data['concept'],
                total: Money::fromAmount($data['total_amount']),
                actor: AdminActor::from($request),
                notes: $data['notes'] ?? null,
                dueAt: $this->plazoPactado($data['due_at'] ?? null, derivarPorDefecto: false),
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
            // Fecha límite del saldo. Si no viene, se deriva del plazo
            // configurado en config/caja.php; el mostrador siempre manda.
            'due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
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
     * GET /api/admin/receivables/account/{member} — estado de cuenta del socio.
     *
     * UNA PERSONA, DOS CAJAS. El socio ve un único total —lo que debe— pero cada
     * obligación conserva su caja, porque el dinero de la cafetería y el del
     * gimnasio se arquean por separado. Sumarlos aquí es una respuesta a «cuánto
     * debe Alejandro»; mezclarlos al cobrar sería otra cosa, y no ocurre: el
     * turno lo decide `receivable.type` en el servidor.
     *
     * NO CREA OBLIGACIONES NI LAS DUPLICA: lee las que ya existen.
     *
     * El resumen se calcula sobre TODAS las deudas vivas y la lista va acotada.
     * Al revés —resumir lo que cupo en la página— el total dependería de cuánto
     * se pidiera, que es la peor forma de equivocarse con dinero.
     */
    /**
     * La fecha límite que se va a grabar.
     *
     * Si el mostrador la pactó, manda esa. Si no y procede derivarla, sale del
     * plazo configurado en `config/caja.php` — nunca de un número escrito a
     * mano aquí, que es como se acaba teniendo tres plazos distintos según el
     * endpoint por el que entre la deuda.
     */
    private function plazoPactado(?string $pactada, bool $derivarPorDefecto): ?CarbonInterface
    {
        if ($pactada !== null && $pactada !== '') {
            return Carbon::parse($pactada, config('caja.timezone', Member::BUSINESS_TZ))->startOfDay();
        }

        $dias = (int) config('caja.default_payment_term_days', 0);

        if (! $derivarPorDefecto || $dias <= 0) {
            return null;
        }

        return Receivable::businessToday()->addDays($dias);
    }

    public function account(Request $request, Member $member): JsonResponse
    {
        $limite = min(max((int) $request->integer('limit', 100), 1), 200);

        // Resumen: solo deuda VIVA, sin acotar. Son pocas filas por socio.
        $vivas = Receivable::where('member_id', $member->id)
            ->outstanding()
            ->withSum('appliedPayments', 'amount')
            ->get(['id', 'type', 'total_amount']);

        $saldoDe = static fn (CashShiftType $caja) => $vivas
            ->filter(fn (Receivable $r) => $r->type === $caja)
            ->reduce(fn (Money $acc, Receivable $r) => $acc->plus($r->balance()), Money::zero());

        $productos = $saldoDe(CashShiftType::PRODUCTS);
        $membresias = $saldoDe(CashShiftType::GYM);

        // Historial: incluye las liquidadas y las anuladas. Un estado de cuenta
        // que solo enseña lo que falta no es un estado de cuenta.
        $obligaciones = Receivable::where('member_id', $member->id)
            ->with('member:id,full_name,document_number,is_staff')
            ->withSum('appliedPayments', 'amount')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limite)
            ->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'member' => [
                    'id' => $member->id,
                    'name' => $member->full_name,
                    'document' => $member->document_number,
                    'is_staff' => (bool) $member->is_staff,
                ],
                'summary' => [
                    'products_outstanding' => $productos->toFloat(),
                    'memberships_outstanding' => $membresias->toFloat(),
                    'total_outstanding' => $productos->plus($membresias)->toFloat(),
                    'outstanding_count' => $vivas->count(),
                ],
                // Por qué está retenido y desde cuándo: es lo que recepción
                // necesita para cobrar, no solo para negar el paso.
                'financial_standing' => app(MembershipFinancialStanding::class)->summary($member),
                'obligations' => $obligaciones
                    ->map(fn (Receivable $r) => $r->toCrmArray())
                    ->all(),
                'obligations_truncated' => $obligaciones->count() >= $limite,
            ],
        ]);
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
            // Fecha límite del saldo. Si no viene, se deriva del plazo
            // configurado en config/caja.php; el mostrador siempre manda.
            'due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Camino rápido del reintento: si esta misma petición ya se atendió, se
        // devuelve aquella operación sin volver a mirar plan, socio ni caja. El
        // índice único de abajo es el que de verdad lo garantiza; esto solo
        // evita el trabajo.
        if (! empty($data['client_request_id'])
            && $previo = ReceivablePayment::where('client_request_id', $data['client_request_id'])->first()) {
            return $this->planYaRegistrado($previo);
        }

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
        $requestId = $data['client_request_id'] ?? null;

        // TODO EN UNA TRANSACCIÓN. Antes el `Payment` se confirmaba solo y la
        // cuenta se creaba después: bastaba que fallara el segundo paso —o que
        // no hubiera caja abierta— para dejar un plan cobrado sin deuda que
        // cobrar. Y con la repetición era peor: el índice único de
        // `client_request_id` frenaba el abono duplicado, pero el `Payment` y la
        // cuenta de la segunda petición ya estaban escritos.
        try {
            $vence = $this->plazoPactado($data['due_at'] ?? null, derivarPorDefecto: true);

            [$pago, $cuenta, $abono] = DB::transaction(function () use ($data, $plan, $total, $primero, $member, $actor, $request, $requestId, $vence) {
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

                $cuenta = app(ReceivableService::class)->create(
                    debtorType: DebtorType::MEMBER,
                    debtorId: $member->id,
                    type: CashShiftType::GYM,
                    concept: 'Plan '.$plan->name,
                    total: $total,
                    actor: $actor,
                    source: $pago,
                    notes: $data['notes'] ?? null,
                    dueAt: $vence,
                );

                $abono = app(ReceivableService::class)->settle(
                    receivable: $cuenta,
                    amount: $primero,
                    method: $data['method'],
                    actor: $actor,
                    clientRequestId: $requestId,
                    reference: $data['reference'] ?? null,
                );

                // `settle()` devuelve el abono YA REGISTRADO cuando la petición
                // se repite. Si el que vuelve no es de la cuenta que acabamos de
                // abrir, esta petición es la segunda: se deshace entera.
                if ($abono->receivable_id !== $cuenta->id) {
                    throw new PlanReplayException($abono);
                }

                return [$pago, $cuenta, $abono];
            });
        } catch (PlanReplayException $e) {
            return $this->planYaRegistrado($e->original);
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        } catch (CashShiftException $e) {
            return response()->json([
                'ok' => false, 'code' => $e->code_,
                'message' => 'No hay una caja de gimnasio abierta. Ábrela antes de registrar el cobro.',
            ], 409);
        }

        // UNA sola vez, aquí. Los abonos siguientes pasan por `pay()`, que no
        // toca membresías. Queda FUERA de la transacción a propósito: extender
        // la membresía notifica al socio, y no se avisa de algo que todavía
        // podría deshacerse.
        app(PaymentMembershipActivator::class)->extendMembership($pago);

        return response()->json([
            'ok' => true,
            'data' => $cuenta->fresh()->load('member')->toCrmArray(withPayments: true),
            'payment_id' => $pago->id,
            'first_payment' => $abono->toCrmArray(),
        ], 201);
    }

    /**
     * La misma venta a plazos, otra vez: se responde lo de la primera.
     *
     * Mismo cuerpo que el alta original y 200 en lugar de 201, porque esta
     * petición concreta no ha creado nada. El CRM pinta lo mismo en los dos
     * casos; quien mire los códigos sabrá cuál fue la que contó.
     */
    private function planYaRegistrado(ReceivablePayment $original): JsonResponse
    {
        $cuenta = $original->receivable()->with('member')->firstOrFail();

        return response()->json([
            'ok' => true,
            'replayed' => true,
            'data' => $cuenta->toCrmArray(withPayments: true),
            'payment_id' => $cuenta->source_id,
            'first_payment' => $original->toCrmArray(),
        ], 200);
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
