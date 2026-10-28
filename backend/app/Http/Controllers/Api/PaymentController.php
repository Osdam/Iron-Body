<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Exceptions\CorrectionException;
use App\Http\Controllers\Controller;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CorrectionGuards;
use App\Services\Caja\PaymentCorrections;
use App\Models\CashShift;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Receivable;
use App\Services\Caja\CashReceipts;
use App\Services\Audit\FinancialAudit;
use App\Services\Audit\AuditTrail;
use App\Services\RealtimeEvents;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Models\Plan;
use App\Models\User;
use App\Rules\DeliverableInvoiceEmail;
use App\Rules\PaymentMethodInput;
use App\Services\Billing\InvoiceEmail;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\Money;
use App\Services\Billing\PriceQuote;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use App\Services\Payments\MembershipPeriod;
use App\Services\Payments\PaymentSplits;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentController extends Controller
{
    use ResolvesPagination;

    /** Vocabulario histórico de estados (ES/EN) agrupado por significado. */
    public const PAID_STATUSES = ['paid', 'pagado', 'pagada', 'approved', 'aprobado', 'completed', 'completado'];

    public const PENDING_STATUSES = ['pending', 'pendiente', 'overdue', 'vencido', 'vencida'];

    public const FAILED_STATUSES = ['failed', 'cancelled', 'canceled', 'anulado', 'anulada'];

    /**
     * Qué estados acepta cada valor del filtro de la tabla.
     *
     * Existe porque el filtro comparaba `status` con un literal exacto y
     * sensible a mayúsculas, mientras que las tarjetas de arriba cuentan con
     * `LOWER(status) IN (…)` sobre varias grafías. Resultado: la tarjeta
     * «Fallidos / Cancelados» sumaba pagos guardados como `anulado`, `canceled`
     * o `Cancelled` que NINGÚN filtro de la pantalla podía sacar a la luz, así
     * que el número no había forma de auditarlo.
     *
     * `failed` y `cancelled` van por separado —no son lo mismo: uno es un cobro
     * que no cuajó y el otro una anulación deliberada— y `failed_or_cancelled`
     * reproduce exactamente el grupo que cuenta la tarjeta.
     */
    private const STATUS_FILTER_GROUPS = [
        'paid' => self::PAID_STATUSES,
        'pending' => self::PENDING_STATUSES,
        'failed' => ['failed'],
        'cancelled' => ['cancelled', 'canceled', 'anulado', 'anulada'],
        'failed_or_cancelled' => self::FAILED_STATUSES,
        'refunded' => ['refunded', 'reembolsado', 'reembolsada'],
    ];

    public function index(Request $request)
    {
        $query = Payment::query()->with(['user:id,name,email', 'plan:id,name', 'electronicInvoice', 'splits'])->latest();

        $this->applyCrmFilters($query, $request);

        $payments = $query->paginate($this->resolvePerPage($request));
        $payments->getCollection()->transform(
            fn (Payment $p) => $p->append('invoice_summary')->makeHidden('electronicInvoice')
        );

        return $payments;
    }

    /**
     * GET /api/admin/payments/stats — KPIs del módulo Pagos.
     *
     * El CRM los calculaba descargando TODOS los pagos página por página. Aquí
     * salen de una sola consulta agregada. Acepta los mismos filtros que el
     * listado (por defecto, ninguno: las tarjetas resumen el total histórico).
     */
    public function crmStats(Request $request)
    {
        // BASE DE CAJA. El asiento de devengo de un plan a plazos queda fuera:
        // es el contrato por el importe completo, no dinero en el cajón.
        // Contarlo hacía que «Total recaudado» marcara los 80.000 de un plan en
        // cuanto entraban los primeros 40.000 — y que el abono final no sumara
        // nada, porque ese dinero llega por otra tabla. Ver {@see CashReceipts}.
        $recibos = app(CashReceipts::class);

        $query = $recibos->cashPayments();
        $this->applyCrmFilters($query, $request);

        $sumWhen = fn (array $statuses) => 'COALESCE(SUM(CASE WHEN LOWER(status) IN ('
            .$this->statusPlaceholders($statuses).') THEN amount ELSE 0 END), 0)';
        $countWhen = fn (array $statuses) => 'COUNT(CASE WHEN LOWER(status) IN ('
            .$this->statusPlaceholders($statuses).') THEN 1 END)';

        $row = $query->selectRaw(
            'COUNT(*) as total_count,'
            .' COALESCE(SUM(amount), 0) as total_amount,'
            .' '.$sumWhen(self::PAID_STATUSES).' as paid_amount,'
            .' '.$countWhen(self::PAID_STATUSES).' as paid_count,'
            .' '.$sumWhen(self::PENDING_STATUSES).' as pending_amount,'
            .' '.$countWhen(self::PENDING_STATUSES).' as pending_count,'
            .' '.$countWhen(self::FAILED_STATUSES).' as failed_count',
            array_merge(
                self::PAID_STATUSES,
                self::PAID_STATUSES,
                self::PENDING_STATUSES,
                self::PENDING_STATUSES,
                self::FAILED_STATUSES,
            )
        )->first();

        // Y los abonos SÍ entran: es el dinero de los planes a plazos y de los
        // créditos de cafetería, que de otro modo no aparecería en ninguna de
        // estas cifras. Cada abono es un cobro confirmado, así que cuenta como
        // operación además de como importe.
        $abonos = $recibos->appliedInstallments();
        $this->applyInstallmentFilters($abonos, $request);
        $cobrado = (float) $abonos->sum('receivable_payments.amount');
        $cobros = (int) $abonos->count();

        return response()->json([
            // Los contadores de arriba describen la TABLA de pagos, que es lo
            // que la pantalla lista; los importes describen el dinero.
            'total_count' => (int) ($row->total_count ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0) + $cobrado, 2),
            'paid_amount' => round((float) ($row->paid_amount ?? 0) + $cobrado, 2),
            'paid_count' => (int) ($row->paid_count ?? 0) + $cobros,
            'pending_amount' => round((float) ($row->pending_amount ?? 0), 2),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
        ]);
    }

    /**
     * Traslada a los abonos los filtros de la pantalla que tienen sentido en
     * ellos.
     *
     * `status` NO se traslada: un abono aplicado siempre es dinero cobrado, y
     * los estados de la pasarela (pendiente, fallido) no existen en su mundo.
     * Filtrar por «pendientes» debe devolver pagos pendientes, no abonos.
     */
    private function applyInstallmentFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $aceptados = $this->statusFilterValues((string) $request->status);
            // Si el filtro no incluye ningún estado de cobrado, los abonos no
            // pintan nada aquí.
            if (! array_intersect($aceptados, self::PAID_STATUSES)) {
                $query->whereRaw('1 = 0');

                return;
            }
        }

        if ($request->filled('cash_shift_id')) {
            $query->where('receivable_payments.cash_shift_id', (int) $request->cash_shift_id);
        }

        if ($request->filled('user_id')) {
            $query->whereHas('receivable.member', fn ($m) => $m->where('user_id', (int) $request->user_id));
        }

        if ($request->filled('search')) {
            $operator = $this->likeOperator($query->getConnection()->getDriverName());
            $like = $this->likeTerm((string) $request->search);
            $query->where(function ($q) use ($operator, $like) {
                $q->where('receivable_payments.reference', $operator, $like)
                    ->orWhereHas('receivable', fn ($r) => $r->where('concept', $operator, $like))
                    ->orWhereHas('receivable.member', fn ($m) => $m->where('full_name', $operator, $like));
            });
        }
    }

    /**
     * GET /api/admin/payments/latest-per-member — último pago de cada miembro.
     *
     * Lo consume el punto de control de acceso, que solo necesita saber cómo
     * quedó el pago más reciente de cada persona. Antes descargaba la tabla
     * ENTERA de pagos para quedarse con una fila por miembro: esto devuelve una
     * fila por miembro con pagos (acotado al padrón, no al histórico).
     */
    public function latestPerMember()
    {
        $effective = 'COALESCE(payments.paid_at, payments.created_at)';

        // Fecha efectiva máxima por miembro, en una subconsulta portable (sin
        // DISTINCT ON ni window functions, que no existen en todos los motores).
        $latest = Payment::query()
            ->whereNotNull('user_id')
            ->selectRaw("user_id, MAX({$effective}) as last_at")
            ->groupBy('user_id');

        $payments = Payment::query()
            ->joinSub($latest, 'latest', function ($join) use ($effective): void {
                $join->on('latest.user_id', '=', 'payments.user_id')
                    ->whereRaw("{$effective} = latest.last_at");
            })
            ->with(['user:id,name', 'plan:id,name'])
            ->orderBy('payments.id')
            ->get([
                'payments.id', 'payments.user_id', 'payments.plan_id', 'payments.amount',
                'payments.method', 'payments.reference', 'payments.status',
                'payments.paid_at', 'payments.created_at',
            ]);

        // Empate exacto de fecha en un mismo miembro: gana el id mayor (el más
        // reciente), igual que el criterio de desempate del CRM.
        $byUser = [];
        foreach ($payments as $payment) {
            $byUser[(int) $payment->user_id] = $payment;
        }

        return response()->json(['data' => array_values($byUser)]);
    }

    /** Filtros compartidos por el listado y los KPIs (status / search / user_id). */
    private function applyCrmFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $aceptados = $this->statusFilterValues((string) $request->status);
            $query->whereRaw(
                'LOWER(status) IN ('.$this->statusPlaceholders($aceptados).')',
                $aceptados,
            );
        }

        if ($request->filled('search')) {
            // `ilike` en PostgreSQL: con `like` la búsqueda por nombre distinguía
            // mayúsculas y no encontraba nada escrito en minúsculas.
            $operator = $this->likeOperator($query->getConnection()->getDriverName());
            $like = $this->likeTerm((string) $request->search);
            $query->where(function ($q) use ($operator, $like) {
                $q->where('reference', $operator, $like)
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', $operator, $like));
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        /*
         * Cobros de un turno de caja concreto.
         *
         * Las ventas de productos ya se podían filtrar así; los cobros del
         * gimnasio no, y esa asimetría dejaba el detalle de un cierre de
         * Gimnasio fuera del alcance de la API. Sin filtro el comportamiento no
         * cambia: siguen saliendo todos, incluidos los de pasarela y los
         * históricos, que tienen `cash_shift_id` nulo por diseño.
         */
        if ($request->filled('cash_shift_id')) {
            $query->where('cash_shift_id', (int) $request->cash_shift_id);
        }
    }

    /**
     * Estados que acepta un valor del filtro. Un valor sin grupo conocido se
     * compara consigo mismo en minúsculas, que sigue siendo mejor que el
     * `where` exacto de antes: al menos encuentra `Refunded` y `REFUNDED`.
     *
     * @return list<string>
     */
    private function statusFilterValues(string $filtro): array
    {
        $clave = strtolower(trim($filtro));

        return self::STATUS_FILTER_GROUPS[$clave] ?? [$clave];
    }

    /** Máximo de líneas en un cobro mixto. Más que esto no es un cobro, es un lío. */
    private const MAX_SPLITS = 6;

    /** Holgura al comparar con el precio del plan. Medio peso: redondeos, no descuentos. */
    private const PLAN_AMOUNT_TOLERANCE = 0.5;

    /**
     * El cobro de un plan tiene que ser por el precio del plan.
     *
     * Existía ya dentro de applyAuthoritativePricing(), pero solo se aplicaba
     * con `BILLING_PRICING_V2_ENABLED=true`, y está en false: hoy se puede
     * registrar un pago de 40.000 contra un plan de 70.000 sin que nada
     * proteste, y el desglose por medios cuadraría con los 40.000 mientras la
     * membresía se activa igual. Esta comprobación no depende de ese flag
     * porque no toca los snapshots fiscales, solo el importe.
     *
     * La vía de escape es la que ya existe para lo mismo: `amount_override`
     * con su justificación, que queda auditada. Un importe distinto sigue
     * siendo posible; lo que deja de ser posible es que ocurra en silencio.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertAmountMatchesPlan(array $data): void
    {
        if (empty($data['plan_id']) || ($data['amount_override'] ?? false)) {
            return;
        }

        $plan = Plan::find($data['plan_id']);
        if (! $plan || (float) $plan->price <= 0) {
            return;
        }

        $precio = round((float) $plan->price, 2);
        $cobrado = round((float) ($data['amount'] ?? 0), 2);

        if (abs($precio - $cobrado) > self::PLAN_AMOUNT_TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'El plan «%s» cuesta %s y el cobro es de %s. Ajusta el importe o justifica '
                    .'el cambio con amount_override y override_reason.',
                    $plan->name,
                    number_format($precio, 0, ',', '.'),
                    number_format($cobrado, 0, ',', '.'),
                )],
            ]);
        }
    }

    /**
     * Reglas del desglose por medio de pago.
     *
     * `min:2` a propósito: una sola línea no es un pago mixto, es un pago
     * normal, y aceptarla crearía dos formas de representar lo mismo.
     *
     * @return array<string, mixed>
     */
    private function splitRules(): array
    {
        return [
            'splits' => 'nullable|array|min:2|max:'.self::MAX_SPLITS,
            'splits.*.method' => PaymentSplits::methodRules(),
            'splits.*.amount' => 'required|numeric|min:0.01',
            'splits.*.reference' => 'nullable|string|max:120',
        ];
    }

    /**
     * Comprueba que las partes sumen EXACTAMENTE el total del cobro.
     *
     * Se compara en centavos enteros, no en float: con `0.1 + 0.2 !== 0.3` un
     * desglose correcto podría rechazarse, y uno descuadrado por un céntimo
     * colarse. Ese céntimo acaba apareciendo en el arqueo de caja.
     *
     * @param  array<int, array{method: string, amount: mixed}>  $splits
     *
     * @throws ValidationException
     */
    private function assertSplitsMatchTotal(array $splits, float $total): void
    {
        $centavosPartes = 0;
        foreach ($splits as $parte) {
            $centavosPartes += (int) round(((float) $parte['amount']) * 100);
        }
        $centavosTotal = (int) round($total * 100);

        if ($centavosPartes !== $centavosTotal) {
            throw ValidationException::withMessages([
                'splits' => sprintf(
                    'El desglose suma %s y el cobro es de %s. Tienen que coincidir.',
                    number_format($centavosPartes / 100, 2, ',', '.'),
                    number_format($centavosTotal / 100, 2, ',', '.'),
                ),
            ]);
        }
    }

    /**
     * Reemplaza el desglose de un cobro. Borra y reinserta en vez de conciliar
     * fila a fila: son cuatro líneas como mucho y así no puede quedar un resto
     * de un desglose anterior sumando en los reportes.
     *
     * @param  array<int, array{method: string, amount: mixed, reference?: string|null}>  $splits
     */
    private function syncSplits(Payment $payment, array $splits): void
    {
        $payment->splits()->delete();

        foreach ($splits as $parte) {
            $payment->splits()->create([
                'method' => $parte['method'],
                'amount' => round((float) $parte['amount'], 2),
                'reference' => $parte['reference'] ?? null,
            ]);
        }

        $payment->forceFill(['method' => Payment::MIXED_METHOD])->save();
    }

    /** Marcadores `?` para una lista de estados dentro de un selectRaw. */
    private function statusPlaceholders(array $statuses): string
    {
        return implode(', ', array_fill(0, count($statuses), '?'));
    }

    public function show(Request $request, Payment $payment)
    {
        $cobro = $payment->load([
            'user:id,name,email',
            'plan:id,name',
            'electronicInvoice',
            'splits',
        ])->append('invoice_summary')->makeHidden('electronicInvoice');

        // Lo que cuelga del cobro: deuda, turno, factura, recibos, devoluciones
        // y posibles duplicados. Se AÑADE al cuerpo de siempre, y SOLO para una
        // persona: lleva motivos, autores y referencias externas de las
        // devoluciones, y el token compartido de automatizaciones (que tiene
        // `payments.view` por ser lectura) no lee nada de eso.
        $actor = AdminActor::from($request);
        if ($actor === null) {
            return $cobro->toArray();
        }

        return array_merge($cobro->toArray(), [
            'links' => app(PaymentCorrections::class)->links($payment, $actor),
        ]);
    }

    /**
     * El historial y las vistas previas de una corrección son para una PERSONA
     * con sesión: enseñan quién corrigió qué y por qué, y anticipan bloqueos de
     * dinero. El token compartido de automatizaciones obtiene `payments.view`
     * por ser lectura, pero esto no lo lee (revisión de seguridad B-3/B-4).
     */
    public static function requirePerson(Request $request): ?JsonResponse
    {
        if (AdminActor::from($request) !== null) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'person_required',
            'message' => 'Esta consulta exige una persona con sesión en el CRM.',
        ], 403);
    }

    /**
     * El mismo cobro, pedido otra vez con el mismo `client_request_id`: se
     * responde el que ya existe, con 200 y `idempotent_replay`, y sin volver a
     * aplicar membresía, factura ni nada.
     *
     * Solo si ES el mismo cobro: mismo socio, mismo plan y mismo importe. Una
     * llave reutilizada para otra operación no devuelve —ni enseña— el cobro
     * ajeno: 422 `client_request_id_conflict` (revisión de seguridad B-2).
     *
     * @param  array<string, mixed>  $pedido
     */
    private function cobroYaRegistrado(Payment $previo, array $pedido): JsonResponse
    {
        $mismo = (int) $previo->user_id === (int) ($pedido['user_id'] ?? 0)
            && (int) ($previo->plan_id ?? 0) === (int) ($pedido['plan_id'] ?? 0)
            && (int) round(((float) $previo->amount) * 100) === (int) round(((float) ($pedido['amount'] ?? 0)) * 100);
        if (! $mismo) {
            throw CorrectionException::requestIdConflict();
        }

        return response()->json(array_merge(
            $previo->fresh()->load(['user:id,name,email', 'plan:id,name', 'splits'])->toArray(),
            ['idempotent_replay' => true],
        ), 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'nullable|exists:plans,id',
            'amount' => 'required|numeric|min:0',
            'method' => ['nullable', 'string', 'max:80', new PaymentMethodInput(devengoLoDecideElCobro: true)],
            'reference' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at' => 'nullable|date',
            // Desde cuándo empieza la membresía que compra este pago. Aparte de
            // la fecha del cobro: pagar hoy y empezar el lunes son dos datos.
            'starts_on' => 'nullable|date_format:Y-m-d',
            // Sobrescritura deliberada del total cotizado. Exige justificación y
            // queda auditada; sin ella, el importe del plan es el que manda.
            'amount_override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|min:10|max:500',
            // Factura electrónica: opt-in explícito del administrador a
            // petición del cliente. Un pago manual (efectivo, transferencia) no
            // crea transacción de pasarela, así que la solicitud se guarda en el
            // propio pago. Nunca se activa por defecto.
            'request_invoice' => 'nullable|boolean',
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail],
            // Lo genera el CRM por intento y lo reutiliza si reintenta: la misma
            // petición dos veces registra UN cobro (índice único en la base).
            'client_request_id' => 'nullable|string|max:100',
            ...$this->splitRules(),
        ]);

        // Un cobro NO nace devuelto. `refunded` lo escribe solo la devolución,
        // que además apunta la salida en `cash_refunds`; aceptarlo aquí metía en
        // el arqueo, Ganancias y el panel una entrada sin su salida: un ingreso
        // ficticio (revisión, bloqueante). Mismo 422 que en PATCH.
        if (($data['status'] ?? null) === 'refunded') {
            throw CorrectionException::useRefundEndpoint();
        }

        $llave = $data['client_request_id'] ?? null;
        $pedido = $data; // tal como llegó: con esto se compara un reintento
        if ($llave !== null && $previo = Payment::where('client_request_id', $llave)->first()) {
            return $this->cobroYaRegistrado($previo, $pedido);
        }

        $this->assertMedioSinCruzarElDevengo(null, $data);

        // El desglose no es una columna de payments: se guarda aparte, después
        // de crear el cobro, así que sale del payload antes de Payment::create.
        $splits = $data['splits'] ?? null;
        unset($data['splits']);

        $this->assertAmountMatchesPlan($data);
        $this->assertStartsOn($request, $data);

        $invoiceRequest = $this->extractInvoiceRequest($data);

        if (($data['status'] ?? 'pending') === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        try {
            $data = $this->applyAuthoritativePricing($data, $request);
        } catch (PricingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            throw $e;
        }

        $override = (bool) ($data['amount_override'] ?? false);
        $reason = $data['override_reason'] ?? null;
        unset($data['amount_override'], $data['override_reason']);

        // Se valida contra el importe YA fijado por applyAuthoritativePricing:
        // comprobarlo antes dejaría pasar un desglose que cuadra con lo que
        // pidió el cliente pero no con lo que de verdad se va a cobrar.
        if ($splits) {
            $this->assertSplitsMatchTotal($splits, (float) $data['amount']);
            $data['method'] = Payment::MIXED_METHOD;
        }

        // CAJA DEL GIMNASIO. Este endpoint es el mostrador: lo ejecuta una
        // persona con sesión de administrador y el dinero cambia de manos aquí.
        // PaymentOrigin::COUNTER exige turno abierto, porque sin él ese cobro
        // presencial quedaría fuera de todo arqueo — el agujero que este
        // trabajo cierra. Los pagos de pasarela NO pasan por aquí: los crea
        // PaymentMembershipActivator y nunca llevan turno.
        //
        // El turno lo resuelve el SERVIDOR. `cash_shift_id` no se acepta del
        // payload: si el cliente pudiera elegirlo, podría mandar su cobro a un
        // turno ajeno o a ninguno.
        unset($data['cash_shift_id']);
        $origen = PaymentOrigin::forCrmRequest(AdminActor::from($request));
        $data['origin'] = $origen->value;

        // EL COBRO ES DE HOY. En el mostrador el dinero cambia de manos ahora y
        // entra en la caja abierta ahora, así que su fecha no se elige: antes
        // se podía fechar la semana siguiente para «que empiece el lunes», y el
        // ingreso salía en los reportes el lunes aunque estuviera en el cajón
        // de hoy. El inicio de la membresía va en `starts_on`.
        //
        // La automatización (token compartido) conserva la fecha que envía: la
        // usan integraciones que registran cobros ya ocurridos.
        if ($origen === PaymentOrigin::COUNTER) {
            $data['paid_at'] = ($data['status'] ?? 'pending') === 'paid' ? now() : null;
        }

        if ($origen->requiresOpenGymShift()) {
            try {
                $data['cash_shift_id'] = app(CashShiftService::class)
                    ->requireOpen(CashShiftType::GYM)->id;
            } catch (CashShiftException $e) {
                return response()->json([
                    'ok' => false,
                    'code' => $e->code_,
                    'message' => 'No hay una caja de gimnasio abierta. Ábrela antes de registrar el cobro.',
                ], 409);
            }
        }

        // Creación y traza en la MISMA transacción. Sueltas, un fallo entre
        // ambas dejaría un cobro sin constancia de quién lo registró, que es
        // justo lo que este trabajo corrige.
        $actor = AdminActor::from($request);
        $data = array_merge($data, Payment::registrarAttributes($actor));
        try {
            $payment = DB::transaction(function () use ($data, $splits, $actor, $request, $origen) {
                // El turno se relee DENTRO de la transacción y con bloqueo
                // compartido: un cierre en paralelo espera a este cobro y lo
                // cuenta, o ya cerró y aquí no aparece abierto (ver lockOpen).
                if ($origen->requiresOpenGymShift()) {
                    $data['cash_shift_id'] = app(CashShiftService::class)->lockOpen(CashShiftType::GYM)->id;
                }
                $cobro = Payment::create($data);
                if ($splits) {
                    $this->syncSplits($cobro, $splits);
                }
                app(FinancialAudit::class)->paymentCreated($cobro, $actor, $request);

                return $cobro;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // La misma petición llegó dos veces a la vez y la otra ganó.
            if ($llave !== null && $previo = Payment::where('client_request_id', $llave)->first()) {
                return $this->cobroYaRegistrado($previo, $pedido);
            }

            throw $e;
        } catch (CashShiftException $e) {
            return response()->json([
                'ok' => false,
                'code' => $e->code_,
                'message' => 'No hay una caja de gimnasio abierta. Ábrela antes de registrar el cobro.',
            ], 409);
        }

        if ($override) {
            $this->auditAmountOverride($payment, $reason, $request);
        }

        // La solicitud se registra aunque el pago aún no esté cobrado: si se
        // confirma después (update), la intención ya consta y no se pierde.
        if ($invoiceRequest['requested']) {
            $payment->marcarFacturaSolicitada($invoiceRequest['email']);
        }

        if ($payment->status === 'paid') {
            app(MembershipPeriod::class)->apply($payment);
            // Facturación electrónica (best-effort, idempotente). Inerte si
            // FACTUS_ENABLED=false. Nunca rompe el registro del pago.
            // `force` sólo si el cliente la pidió: la solicitud expresa manda
            // sobre el flag global auto_emit.
            app(InvoicingService::class)->enqueueForPayment(
                $payment,
                force: (bool) $payment->invoice_requested,
            );
        }

        return response()->json($payment->fresh()->load(['user:id,name,email', 'plan:id,name', 'splits']), 201);
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at' => 'nullable|date',
            'starts_on' => 'nullable|date_format:Y-m-d',
            'method' => ['nullable', 'string', 'max:80', new PaymentMethodInput(actual: $payment->method, devengoLoDecideElCobro: true)],
            'reference' => 'nullable|string|max:120',
            'amount' => 'nullable|numeric|min:0',
            // El cliente puede pedir la factura al confirmar el pago.
            'request_invoice' => 'nullable|boolean',
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail],
            // Motivo de la corrección. Obligatorio al anular, al quitarle la
            // cobertura a un cobro pagado y al corregir su medio o su importe
            // (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §2).
            'reason' => 'nullable|string|min:5|max:500',
            ...$this->splitRules(),
        ]);

        $resultado = $this->applyUpdate($request, $payment, $data);

        $cuerpo = $payment->fresh()->load(['user:id,name,email', 'plan:id,name', 'splits'])->toArray();
        if ($resultado['already']) {
            $cuerpo['already'] = true;
        }

        return response()->json($cuerpo);
    }

    /**
     * POST /api/payments/{payment}/method — corregir el medio (y, con partes,
     * el desglose) de un cobro. `payments.correct`, motivo obligatorio.
     *
     * Es el mismo camino que PATCH, con el contrato acotado a lo que corrige:
     * pasar de «mixto» a un medio simple borra las partes en la misma
     * transacción, nunca entra ni sale de `receivable` y la pasarela no se
     * reetiqueta.
     */
    public function correctMethod(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:80', new PaymentMethodInput(actual: $payment->method, devengoLoDecideElCobro: true)],
            'reason' => 'required|string|min:5|max:500',
            ...$this->splitRules(),
        ]);

        $resultado = $this->applyUpdate($request, $payment, $data, soloMedio: true);

        return response()->json([
            'ok' => true,
            'already' => $resultado['already'],
            'data' => $payment->fresh()->load(['user:id,name,email', 'plan:id,name', 'splits']),
        ]);
    }

    /**
     * POST /api/payments/{payment}/refund — devolverle al socio el dinero.
     *
     * `payments.refund`. El cobro pasa a `refunded` y sigue contando en el turno
     * en que entró; la salida queda en el turno ABIERTO del gimnasio. Pasarela:
     * solo con la referencia de la devolución hecha en su panel, sin egreso.
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:500',
            // Cualquier medio de mostrador que la caja sabe contar (efectivo,
            // transferencia, Nequi, Daviplata, tarjeta…), nunca pasarela,
            // `receivable` ni `mixed`. La regla da el mensaje en español.
            'method' => ['nullable', 'string', 'max:80', new PaymentMethodInput(soloUnMedio: true)],
            'external_reference' => 'nullable|string|max:120',
            'client_request_id' => 'nullable|string|max:100',
        ]);

        $r = app(PaymentCorrections::class)->refund($payment, $data, AdminActor::from($request), $request);

        return response()->json([
            'ok' => true,
            'already' => $r['already'],
            'idempotent_replay' => $r['replay'],
            'data' => $r['payment']->fresh()->load(['user:id,name,email', 'plan:id,name', 'splits']),
            'refund' => $r['refund']?->toCrmArray(),
        ], ($r['already'] || $r['replay']) ? 200 : 201);
    }

    /**
     * GET /api/payments/{payment}/correction-preview?action=cancel|refund|method
     *
     * Efectos y bloqueos de la corrección, sin escribir nada. La corrección
     * real lo revalida todo bajo bloqueo: esto informa, no autoriza.
     */
    public function correctionPreview(Request $request, Payment $payment): JsonResponse
    {
        if ($sinPersona = self::requirePerson($request)) {
            return $sinPersona;
        }
        $data = $request->validate(['action' => ['required', Rule::in(['cancel', 'refund', 'method'])]]);

        return response()->json(
            app(PaymentCorrections::class)->preview($payment, $data['action'], AdminActor::from($request))
        );
    }

    /**
     * GET /api/payments/{payment}/history — la traza del cobro (audit_logs):
     * alta, cambios de estado, correcciones con su antes y después, devoluciones
     * y los días de membresía devueltos.
     */
    public function history(Request $request, Payment $payment): JsonResponse
    {
        if ($sinPersona = self::requirePerson($request)) {
            return $sinPersona;
        }
        $propias = AuditLog::query()
            ->whereIn('entity', ['pago', 'payment', 'mejora de plan'])
            ->where('entity_id', (string) $payment->id)
            ->orderBy('id')
            ->limit(500)
            ->get();

        // Los días devueltos se registraban sobre el SOCIO (entity membership);
        // se filtran aquí por el cobro, sin consultar el JSON en SQL.
        $membresia = $payment->user_id === null ? collect() : AuditLog::query()
            ->where('entity', 'membership')
            ->where('entity_id', (string) $payment->user_id)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (AuditLog $l) => (int) ($l->metadata['payment_id'] ?? 0) === (int) $payment->id);

        return response()->json([
            'ok' => true,
            'data' => $propias->concat($membresia)->sortBy('id')->values()
                ->map(fn (AuditLog $l) => \App\Http\Controllers\Api\Admin\ReceivableController::historyRow($l))
                ->all(),
        ]);
    }

    /**
     * El cambio de un cobro, entero, en UNA transacción y bajo bloqueo.
     *
     * Antes el estado previo se leía sin bloquear y la membresía se devolvía
     * FUERA de la transacción, con los errores tragados: dos anulaciones a la
     * vez escribían dos trazas, un doble clic al reconfirmar podía sumar el
     * periodo dos veces y un fallo a mitad dejaba el cobro anulado con los días
     * puestos. Ahora:
     *
     *   1. bloqueo socio → cobro → deuda (el orden de toda la caja);
     *   2. TODO se decide con lo leído bajo ese bloqueo: estado, pasarela,
     *      factura, turno cerrado, deuda viva, permiso y motivo;
     *   3. membresía, desglose y traza en la misma transacción;
     *   4. factura y avisos a la app, después del commit.
     *
     * Anular lo ya anulado devuelve `already` sin traza ni efectos.
     *
     * @param  array<string, mixed>  $data
     * @return array{already: bool}
     */
    private function applyUpdate(Request $request, Payment $payment, array $data, bool $soloMedio = false): array
    {
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        unset($data['reason']);

        // `refunded` no se pone a mano: la devolución registra la salida de caja.
        if (($data['status'] ?? null) === 'refunded'
            && ! in_array(strtolower((string) $payment->status), ['refunded', 'reembolsado', 'reembolsada'], true)) {
            throw CorrectionException::useRefundEndpoint(
                app(PaymentCorrections::class)->liveReceivable($payment, lock: false)?->id
            );
        }
        if (($data['status'] ?? null) === 'refunded') {
            unset($data['status']); // reenviar el estado que ya tiene no es un cambio
        }

        $this->assertMedioSinCruzarElDevengo($payment, $data);
        // Un cobro de pasarela lo registró la pasarela: su medio no se reetiqueta
        // a mano (los informes por medio lo contarían como otro dinero).
        if (PaymentMethodKind::normalize($payment->method) === PaymentMethodKind::WOMPI
            && (! empty($data['splits']) || (array_key_exists('method', $data)
                && strtolower(trim((string) $data['method'])) !== strtolower(trim((string) $payment->method))))) {
            throw ValidationException::withMessages(['method' => ['Un cobro de pasarela conserva su medio: lo registró la pasarela al aprobarlo.']]);
        }
        // El devengo conserva su marca tal como la escribió storePlan: un
        // 'Receivable' reenviado sigue siendo devengo para los informes, pero
        // ReceiptSourceResolver la compara exacta.
        if (app(CashReceipts::class)->isAccrual($payment->method)) {
            unset($data['method']);
        }

        $splits = $data['splits'] ?? null;
        unset($data['splits']);

        // Un importe o una fecha vacíos no borran el dato: no hay cobro sin
        // importe, y la fecha de cobro no se pierde por un formulario a medias.
        foreach (['amount', 'paid_at'] as $campo) {
            if (array_key_exists($campo, $data) && ($data[$campo] === null || $data[$campo] === '')) {
                unset($data[$campo]);
            }
        }

        if ($splits) {
            // Contra el importe nuevo si se está cambiando, contra el vigente si
            // no: validar siempre contra el guardado dejaría pasar un desglose
            // que ya no cuadra con el total que va a quedar.
            $this->assertSplitsMatchTotal($splits, (float) ($data['amount'] ?? $payment->amount));
            $data['method'] = Payment::MIXED_METHOD;
        } elseif ($soloMedio && strtolower((string) ($data['method'] ?? '')) === Payment::MIXED_METHOD) {
            throw ValidationException::withMessages(['splits' => ['Un cobro mixto necesita su desglose: indica cuánto entró por cada medio.']]);
        }

        $invoiceRequest = $this->extractInvoiceRequest($data);
        $actor = AdminActor::from($request);
        $guards = app(CorrectionGuards::class);
        $correcciones = app(PaymentCorrections::class);

        $r = DB::transaction(function () use ($payment, $data, $splits, $reason, $actor, $request, $guards, $correcciones, $soloMedio) {
            // 1 · Turno (compartido) → socio → cobro, y todo se decide con lo
            // leído aquí. El turno primero: un cierre en paralelo espera a esta
            // corrección y la cuenta, o ya cerró y se trata como posterior.
            if ($payment->cash_shift_id) {
                CashShift::whereKey($payment->cash_shift_id)->sharedLock()->first();
            }
            User::whereKey($payment->user_id)->lockForUpdate()->first();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->setRawAttributes($locked->getAttributes(), true);
            $payment->unsetRelation('splits');

            $estadoPrevio = (string) $payment->status;
            $estado = strtolower($estadoPrevio);
            $wasPaid = in_array($estado, self::PAID_STATUSES, true);
            $anulado = in_array($estado, ['cancelled', 'canceled', 'anulado', 'anulada'], true);
            $devuelto = in_array($estado, ['refunded', 'reembolsado', 'reembolsada'], true);
            $nuevo = $data['status'] ?? null;

            // Anular lo ya anulado: idempotente, sin traza ni efectos.
            if ($nuevo === 'cancelled' && $anulado) {
                return ['already' => true, 'quita' => false, 'reconfirma' => false];
            }
            if ($nuevo !== null && $nuevo !== 'paid' && strtolower($nuevo) === $estado) {
                unset($data['status']);
                $nuevo = null;
            }
            if ($devuelto && $nuevo !== null) {
                throw CorrectionException::alreadyRefunded();
            }

            $quita = $nuevo !== null && $nuevo !== 'paid' && $wasPaid;
            $reconfirma = $nuevo === 'paid' && ! $wasPaid;
            $campos = $this->camposDeDinero($payment, $data, $splits);

            // Lo devuelto no cambia de importe, medio ni fecha (M-1): la salida
            // de caja ya restó lo que se cobró, y cambiar la entrada dejaría un
            // faltante —o un sobrante— que nadie podría explicar.
            if ($devuelto && $campos !== []) {
                throw CorrectionException::alreadyRefunded();
            }

            // Corregir el medio al que ya tiene: idempotente, sin traza.
            if ($soloMedio && $campos === []) {
                return ['already' => true, 'quita' => false, 'reconfirma' => false];
            }

            // 2 · Permiso y motivo de lo que de verdad cambia.
            if ($campos !== [] && ! CrmPermission::allows($actor, CrmPermission::PAYMENTS_CORRECT)) {
                throw new HttpResponseException(response()->json([
                    'ok' => false,
                    'code' => 'forbidden',
                    'message' => 'Corregir el medio o el importe de un cobro exige el permiso «Corregir medio o importe».',
                    'required_permission' => CrmPermission::PAYMENTS_CORRECT,
                ], 403));
            }
            // 3 · Pasarela, turno cerrado, factura, deuda viva y mejoras.
            if (($quita || in_array('amount', $campos, true)) && CorrectionGuards::isGatewayPayment($payment)) {
                throw CorrectionException::gatewayPayment();
            }
            $turno = $payment->cash_shift_id ? CashShift::find($payment->cash_shift_id) : null;
            if ($quita || $reconfirma || $nuevo === 'cancelled' || $campos !== []) {
                $guards->assertShiftAllows($turno, $actor);
            }
            if ($quita || in_array('amount', $campos, true)) {
                $guards->assertInvoiceAllows($payment);
            }
            if ($quita) {
                $correcciones->assertNoDependentUpgrade($payment);
                // Su periodo se cedió a otra persona: primero se revierte el
                // traspaso (TRANSFERENCIA-DE-MEMBRESIAS.md §4).
                $guards->assertNotTransferred($payment);
                $this->assertSinDeudaViva($payment);
            }
            foreach (['user_id', 'plan_id', 'amount'] as $field) {
                if ($payment->upgraded_from_plan_id && array_key_exists($field, $data) && (float) $data[$field] !== (float) $payment->{$field}) {
                    throw ValidationException::withMessages([
                        $field => ['La mejora conserva el socio, el plan y el importe calculados al cobrar.'],
                    ]);
                }
            }
            // Cambiar el importe de un cobro repartido sin repartir el nuevo
            // dejaría partes que ya no suman el total y que el arqueo seguiría
            // contando.
            if (in_array('amount', $campos, true) && ! $splits && $payment->isMixed()
                && strtolower((string) ($data['method'] ?? Payment::MIXED_METHOD)) === Payment::MIXED_METHOD) {
                throw ValidationException::withMessages(['splits' => [
                    'Este cobro está repartido entre varios medios: al cambiar el importe, indica de nuevo el desglose.',
                ]]);
            }

            // El motivo, después de las reglas de negocio: si la corrección no se
            // puede hacer, decirlo antes que pedir una explicación inútil.
            if (($quita || $nuevo === 'cancelled' || $campos !== []) && ($reason === null || mb_strlen($reason) < 5)) {
                throw ValidationException::withMessages(['reason' => [
                    'Indica el motivo de la corrección (mínimo 5 caracteres): queda en la traza del cobro.',
                ]]);
            }

            // El inicio solo se puede elegir mientras el pago no ha activado nada:
            // cambiarlo después movería una membresía que ya está corriendo sin
            // recalcular el periodo, y el perfil contaría otra historia.
            if (array_key_exists('starts_on', $data)) {
                if ($wasPaid) {
                    unset($data['starts_on']);
                } else {
                    $this->assertStartsOn($request, $data, $payment);
                }
            }

            // Solo se fecha lo que nunca se cobró. Un cobro anulado que se
            // reconfirma se cobró cuando se cobró, y en el turno de entonces, que
            // conserva: ponerle la fecha de hoy movía el ingreso de día y, si el
            // día era otro, `confirmadoMasTarde` corría el inicio del periodo.
            if ($nuevo === 'paid' && empty($data['paid_at']) && ! $payment->paid_at) {
                $data['paid_at'] = now();
            }

            // 4 · Escribir: cobro, desglose, membresía y traza, juntos.
            $antes = FinancialAudit::paymentSnapshot($payment);
            $payment->update($data);
            if ($splits) {
                $this->syncSplits($payment, $splits);
            } elseif (array_key_exists('method', $data)
                && strtolower((string) $data['method']) !== Payment::MIXED_METHOD
                && $payment->splits()->exists()) {
                // De «mixto» a un medio simple: las partes viejas se borran AQUÍ.
                // Quedándose, el arqueo las seguía sumando además del cobro.
                $payment->splits()->delete();
            }

            $membresia = null;
            try {
                if ($quita) {
                    $membresia = $correcciones->revertMembership($payment);
                    if ($membresia && ! $payment->upgraded_from_plan_id) {
                        $this->auditMembershipReverted($payment, $membresia, $request);
                    }
                } elseif ($reconfirma && ! app(MembershipPeriod::class)->apply($payment) && $payment->upgraded_from_plan_id) {
                    throw new \RuntimeException('El plan de esta mejora ya no está disponible. Revisa el cobro antes de reconfirmarlo.');
                }
            } catch (\RuntimeException $e) {
                // Las mejoras de plan avisan así de que no se pueden deshacer o
                // rehacer (PlanUpgrades): es un 422 para quien corrige, no un 500.
                throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
            }

            $payment->unsetRelation('splits');
            app(FinancialAudit::class)->paymentUpdated($payment, $estadoPrevio, $actor, $request, $antes, $reason, array_filter([
                'shift_closed' => $turno !== null && ! $turno->isOpen(),
                'membership' => $membresia,
                'corrected' => $campos ?: null,
            ], static fn ($v) => $v !== null));

            if ($payment->upgraded_from_plan_id && ($payment->wasChanged() || $splits)) {
                $event = $quita ? 'membership.plan.upgrade.cancelled'
                    : ($reconfirma ? 'membership.plan.upgrade.restored' : 'membership.plan.upgrade.updated');
                app(AuditTrail::class)->record($request, [
                    'action' => 'update', 'module' => 'Pagos', 'entity' => 'mejora de plan',
                    'entity_id' => (string) $payment->id,
                    'summary' => 'Actualizó el cobro de una mejora de plan',
                    'metadata' => ['event' => $event, 'user_id' => $payment->user_id,
                        'plan_id' => $payment->plan_id,
                        'changed' => ['membership', 'payments', 'history', 'features', 'plans']],
                ]);
            }

            return ['already' => false, 'quita' => $quita, 'reconfirma' => $reconfirma];
        }, 3);

        if ($r['already']) {
            return ['already' => true];
        }

        if ($invoiceRequest['requested']) {
            $payment->marcarFacturaSolicitada($invoiceRequest['email']);
        }

        // Después del commit: facturar lo que se acaba de confirmar (nunca el
        // devengo de un plan a plazos) y avisar a la app.
        if ($r['reconfirma'] && ! app(CashReceipts::class)->isAccrual($payment->method)) {
            app(InvoicingService::class)->enqueueForPayment(
                $payment,
                force: (bool) $payment->fresh()->invoice_requested,
            );
        }
        if ($r['quita'] || $r['reconfirma'] || $payment->upgraded_from_plan_id) {
            $correcciones->notifyMember($payment);
        }

        return ['already' => false];
    }

    /**
     * Qué datos del DINERO cambia esta petición: medio, desglose, importe o
     * fecha de cobro. Se compara con lo guardado —normalizado— porque el CRM
     * reenvía el cobro entero al editar la referencia, y reenviar lo mismo no
     * es corregir nada.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{method: string, amount: mixed}>|null  $splits
     * @return list<string>
     */
    private function camposDeDinero(Payment $payment, array $data, ?array $splits): array
    {
        $campos = [];
        if (array_key_exists('method', $data)
            && strtolower(trim((string) $data['method'])) !== strtolower(trim((string) $payment->method))) {
            $campos[] = 'method';
        }
        if ($splits) {
            $huella = fn (iterable $partes) => collect($partes)
                ->map(fn ($p) => strtolower(trim((string) ($p['method'] ?? $p->method))).':'.(int) round(((float) ($p['amount'] ?? $p->amount)) * 100))
                ->sort()->values()->all();
            if ($huella($splits) !== $huella($payment->splits()->get()->map(fn ($p) => ['method' => $p->method, 'amount' => $p->amount]))) {
                $campos[] = 'splits';
            }
        }
        if (array_key_exists('amount', $data)
            && (int) round(((float) $data['amount']) * 100) !== (int) round(((float) $payment->amount) * 100)) {
            $campos[] = 'amount';
        }
        if (! empty($data['paid_at']) && $payment->paid_at !== null
            && Carbon::parse($data['paid_at'])->format('Y-m-d H:i:s') !== $payment->paid_at->format('Y-m-d H:i:s')) {
            $campos[] = 'paid_at';
        }

        return $campos;
    }

    /**
     * El medio de pago no cruza la marca de devengo, en ningún sentido.
     *
     * `receivable` no es un medio de pago: marca el cobro que activa un plan a
     * plazos, cuyo dinero entra por los abonos (ver CashReceipts). Quitársela
     * —con otro medio, con `null` o con un desglose, que lo vuelve `mixed`—
     * haría contar el plan entero como dinero recibido, además de los abonos.
     * Ponérsela a un cobro normal lo sacaría del arqueo de su turno con el
     * dinero en el cajón; y crearlo ya con ella (`$payment` null, desde
     * `store()`) activaría la membresía sin dinero en ningún arqueo y sin deuda
     * que cobrar. Esa marca solo la escribe la venta a plazos
     * (ReceivableController::storePlan).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertMedioSinCruzarElDevengo(?Payment $payment, array $data): void
    {
        $recibos = app(CashReceipts::class);
        $esDevengo = $payment !== null && $recibos->isAccrual($payment->method);
        $cruza = array_key_exists('method', $data) && $recibos->isAccrual($data['method']) !== $esDevengo;

        if ($cruza || ($esDevengo && ! empty($data['splits']))) {
            throw ValidationException::withMessages(['method' => [$esDevengo
                ? 'Este cobro es la venta de un plan a plazos: su medio de pago no se cambia. El dinero entra por los abonos, en Cuentas por cobrar.'
                : '«receivable» no es un medio de pago: las ventas a plazos se registran desde «Pago parcial», en Caja de Gimnasio, con su cuenta por cobrar.',
            ]]);
        }
    }

    /**
     * El cobro de un plan a plazos no se anula mientras su deuda siga en pie.
     *
     * Ese cobro y su cuenta por cobrar cuentan la MISMA venta. Anular solo el
     * cobro le quitaba al socio la membresía y dejaba la deuda viva —cobrable,
     * o «pagada» por una venta que ya no existía— con sus abonos en el arqueo.
     * Pasó el 27-10-2026: para deshacer un abono mal registrado se anuló el
     * cobro del plan, que era la puerta equivocada.
     *
     * Aquí solo se señala la buena. Un abono mal hecho se anula en Cuentas por
     * cobrar; deshacer la venta es anular allí la deuda y después este cobro.
     * Las deudas se bloquean todas, también las anuladas, después del socio y
     * del pago: el mismo orden que el resto de esta transacción.
     */
    private function assertSinDeudaViva(Payment $payment): void
    {
        $viva = Receivable::where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->id)
            ->lockForUpdate()
            ->get()
            ->first(fn (Receivable $deuda) => ! $deuda->isCancelled());

        if ($viva) {
            throw ValidationException::withMessages(['status' => [
                "Este cobro es un plan vendido a plazos y su cuenta por cobrar #{$viva->id} sigue vigente. "
                .'Si un abono se registró mal, anula ese abono en Cuentas por cobrar. '
                .'Para deshacer la venta, anula primero la cuenta por cobrar.',
            ]]);
        }
    }

    /**
     * El inicio pedido tiene sentido solo con plan, y dentro de la ventana que
     * fija MembershipPeriod. Sin plan se descarta: un pago libre no compra
     * membresía y guardar una fecha de inicio ahí sería ruido.
     *
     * HACIA ATRÁS HACE FALTA PERMISO. Cobrar es `payments.create` y lo tiene el
     * mostrador entero; fechar ese cobro antes de hoy es otra cosa, porque
     * retrocede la vigencia y con ella el vencimiento: mal puesta, deja al socio
     * vencido en el mismo momento de registrarlo, y mal usada regala o quita
     * días sin que se note en ninguna cifra de caja. Por eso va aparte, en
     * `payments.backdate`, y de fábrica no lo trae recepción.
     *
     * Hacia DELANTE no pide nada: programar un inicio futuro no mueve nada que
     * ya esté corriendo.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertStartsOn(Request $request, array &$data, ?Payment $existente = null): void
    {
        $conPlan = ! empty($data['plan_id']) || (bool) $existente?->plan_id;

        if (empty($data['starts_on']) || ! $conPlan) {
            unset($data['starts_on']);

            return;
        }

        if ($error = MembershipPeriod::startError($data['starts_on'])) {
            throw ValidationException::withMessages(['starts_on' => [$error]]);
        }

        $this->assertPuedeFecharAtras($request, (string) $data['starts_on']);
    }

    /**
     * Fechar el inicio en el pasado exige `payments.backdate`.
     *
     * Se compara contra el día del NEGOCIO, no contra `now()`: en Bogotá, a las
     * siete de la tarde el servidor ya está en el día siguiente en UTC, y sin
     * esto un cobro con la fecha de hoy se habría leído como retroactivo cada
     * noche. {@see MembershipPeriod::today()}.
     *
     * Falla CERRADO: una credencial sin persona detrás —el token de
     * automatizaciones— no resuelve a un Admin y no obtiene el permiso.
     */
    private function assertPuedeFecharAtras(Request $request, string $startsOn): void
    {
        if ($startsOn >= MembershipPeriod::today()->toDateString()) {
            return;
        }

        if (CrmPermission::allows(AdminActor::from($request), CrmPermission::PAYMENTS_BACKDATE)) {
            return;
        }

        abort(403, 'No tienes permiso para registrar un cobro con fecha de inicio anterior a hoy. '
            .'Pídeselo a un administrador o deja el inicio en hoy.');
    }

    /**
     * Extrae la solicitud de factura del request y la SACA de `$data`.
     *
     * Se retira del array porque `request_invoice`/`invoice_email` no son
     * columnas que deban ir en `Payment::create()`/`update()` sin más: la
     * solicitud se registra con `marcarFacturaSolicitada()`, que es idempotente
     * y conserva la fecha original.
     *
     * Exige correo entregable: pedir factura sin decir a dónde mandarla es una
     * solicitud incompleta, y descubrirlo después de cobrar es tarde.
     *
     * @param  array<string,mixed>  $data
     * @return array{requested: bool, email: ?string}
     */
    private function extractInvoiceRequest(array &$data): array
    {
        $requested = (bool) ($data['request_invoice'] ?? false);
        $email = $data['invoice_email'] ?? null;

        unset($data['request_invoice'], $data['invoice_email']);

        if ($requested && InvoiceEmail::normalizar($email) === null) {
            throw ValidationException::withMessages([
                'invoice_email' => ['Para solicitar la factura electrónica hace falta un correo real del cliente.'],
            ]);
        }

        return ['requested' => $requested, 'email' => $email];
    }

    /**
     * El backend es la autoridad financiera del pago manual.
     *
     * Con un plan seleccionado, el total lo fija PricingService (base + IVA
     * según el pricing_mode del plan) y se congela el snapshot: el administrador
     * ya no puede escribir en silencio un total incompatible con el plan, que
     * era el camino directo a facturar por un importe distinto al cobrado.
     *
     * Sobrescribir el total sigue siendo posible cuando hay una razón legítima
     * (acuerdo comercial, ajuste), pero es EXPLÍCITO: exige amount_override,
     * una justificación y queda auditado. El importe registrado y el facturado
     * siguen siendo el mismo valor, así que la conciliación nunca se rompe.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws PricingException|ValidationException
     */
    private function applyAuthoritativePricing(array $data, Request $request): array
    {
        if (empty($data['plan_id']) || ! config('billing.pricing.v2_enabled', false)) {
            return $data;
        }

        $plan = Plan::with('taxRate')->find($data['plan_id']);
        if (! $plan || (float) $plan->price <= 0) {
            return $data;
        }

        $quote = app(PricingService::class)->quoteForPlan($plan);
        $quoted = $quote->grossAmount->toFloat();
        $requested = round((float) ($data['amount'] ?? 0), 2);

        if (abs($quoted - $requested) > 0.5) {
            if (! ($data['amount_override'] ?? false)) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'El total del plan «%s» es %s (base %s + IVA %s). Recibido: %s. '
                        .'Para registrar un importe distinto marca amount_override e indica override_reason.',
                        $plan->name,
                        PriceQuote::formatCop($quote->grossAmount),
                        PriceQuote::formatCop($quote->baseAmount),
                        PriceQuote::formatCop($quote->taxAmount),
                        number_format($requested, 2, ',', '.'),
                    )],
                ]);
            }

            if (empty($data['override_reason'])) {
                throw ValidationException::withMessages([
                    'override_reason' => ['Indica la justificación del importe distinto al cotizado (mínimo 10 caracteres).'],
                ]);
            }

            // Con override, el snapshot se recalcula SOBRE EL IMPORTE REAL para
            // que base + IVA sigan sumando exactamente lo cobrado.
            $overrideQuote = app(PricingService::class)->quoteLegacyInclusive(
                Money::fromAmount($requested),
                $plan->taxRate,
            );

            return array_merge($data, $overrideQuote->toSnapshot(), ['amount' => $requested]);
        }

        return array_merge($data, $quote->toSnapshot(), ['amount' => $quoted]);
    }

    /**
     * Deja rastro de los días de membresía que se le quitaron al socio al
     * anular su cobro. Es un cambio de acceso, no sólo de contabilidad: sin
     * esta línea, mañana nadie sabe por qué al socio se le adelantó el
     * vencimiento.
     *
     * @param  array{end: string, previous_end: string, days: int}  $devuelto
     */
    private function auditMembershipReverted(Payment $payment, array $devuelto, Request $request): void
    {
        Log::info('Membresía recortada al anular un cobro', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
        ] + $devuelto);

        // Dentro de la transacción de la anulación y SIN try/catch: en
        // PostgreSQL un INSERT fallido aborta la transacción, y tragarse el
        // error dejaría las siguientes sentencias fallando a ciegas. Si la
        // traza no se escribe, la anulación no se confirma.
        AuditLog::create(array_filter([
            'action' => 'update',
            'module' => 'payments',
            'entity' => 'membership',
            'entity_id' => (string) $payment->user_id,
            'target_name' => $payment->reference,
            'actor_id' => AdminActor::id($request),
            'actor_name' => AdminActor::name($request),
            'summary' => 'Se devolvieron '.$devuelto['days'].' días de membresía al anular el cobro #'.$payment->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'membership_end_before' => $devuelto['previous_end'],
                'membership_end_after' => $devuelto['end'],
            ],
            'ip_address' => $request->ip(),
        ], static fn ($v) => $v !== null));
    }

    /** Deja rastro auditable de una sobrescritura manual del total. */
    private function auditAmountOverride(Payment $payment, ?string $reason, Request $request): void
    {
        try {
            // `actor_name` es NOT NULL con default: se omite si no hay una
            // persona detrás (por ejemplo, el token de automatizaciones).
            AuditLog::create(array_filter([
                'action' => 'update',
                'module' => 'payments',
                'entity' => 'payment',
                'entity_id' => (string) $payment->id,
                'target_name' => $payment->reference,
                // `$request->user()` está VACÍO en las rutas del CRM, así que
                // esta traza se guardaba sin responsable: decía que alguien
                // forzó un importe, no quién. Ver AdminActor.
                'actor_id' => AdminActor::id($request),
                'actor_name' => AdminActor::name($request),
                'summary' => 'Importe manual distinto al cotizado por el plan',
                'metadata' => [
                    'amount' => (float) $payment->amount,
                    'plan_id' => $payment->plan_id,
                    'reason' => $reason,
                ],
                'ip_address' => $request->ip(),
            ], static fn ($v) => $v !== null));
        } catch (Throwable $e) {
            // La auditoría es best-effort: no debe tumbar el registro del pago.
            Log::warning('No se pudo auditar la sobrescritura de importe', ['error' => $e->getMessage()]);
        }
    }
}
