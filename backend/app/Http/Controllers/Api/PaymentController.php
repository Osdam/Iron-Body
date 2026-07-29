<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Rules\DeliverableInvoiceEmail;
use App\Services\Billing\InvoiceEmail;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\Money;
use App\Services\Billing\PriceQuote;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

    public function index(Request $request)
    {
        $query = Payment::query()->with(['user:id,name,email', 'plan:id,name', 'electronicInvoice'])->latest();

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
        $query = Payment::query();
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

        return response()->json([
            'total_count' => (int) ($row->total_count ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0), 2),
            'paid_amount' => round((float) ($row->paid_amount ?? 0), 2),
            'paid_count' => (int) ($row->paid_count ?? 0),
            'pending_amount' => round((float) ($row->pending_amount ?? 0), 2),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
        ]);
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
            $query->where('status', $request->status);
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
    }

    /** Marcadores `?` para una lista de estados dentro de un selectRaw. */
    private function statusPlaceholders(array $statuses): string
    {
        return implode(', ', array_fill(0, count($statuses), '?'));
    }

    public function show(Payment $payment)
    {
        return $payment->load([
            'user:id,name,email',
            'plan:id,name',
            'electronicInvoice',
        ])->append('invoice_summary')->makeHidden('electronicInvoice');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'nullable|exists:plans,id',
            'amount' => 'required|numeric|min:0',
            'method' => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at' => 'nullable|date',
            // Sobrescritura deliberada del total cotizado. Exige justificación y
            // queda auditada; sin ella, el importe del plan es el que manda.
            'amount_override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|min:10|max:500',
            // Factura electrónica: opt-in explícito del administrador a
            // petición del cliente. Un pago manual (efectivo, transferencia) no
            // crea transacción de pasarela, así que la solicitud se guarda en el
            // propio pago. Nunca se activa por defecto.
            'request_invoice' => 'nullable|boolean',
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail()],
        ]);

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

        $payment = Payment::create($data);

        if ($override) {
            $this->auditAmountOverride($payment, $reason, $request);
        }

        // La solicitud se registra aunque el pago aún no esté cobrado: si se
        // confirma después (update), la intención ya consta y no se pierde.
        if ($invoiceRequest['requested']) {
            $payment->marcarFacturaSolicitada($invoiceRequest['email']);
        }

        if ($payment->status === 'paid') {
            $this->applyMembershipExtension($payment);
            // Facturación electrónica (best-effort, idempotente). Inerte si
            // FACTUS_ENABLED=false. Nunca rompe el registro del pago.
            // `force` sólo si el cliente la pidió: la solicitud expresa manda
            // sobre el flag global auto_emit.
            app(InvoicingService::class)->enqueueForPayment(
                $payment,
                force: (bool) $payment->invoice_requested,
            );
        }

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name']), 201);
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at' => 'nullable|date',
            'method' => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:120',
            'amount' => 'nullable|numeric|min:0',
            // El cliente puede pedir la factura al confirmar el pago.
            'request_invoice' => 'nullable|boolean',
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail()],
        ]);

        $invoiceRequest = $this->extractInvoiceRequest($data);
        $wasPaid = $payment->status === 'paid';

        if (isset($data['status']) && $data['status'] === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $payment->update($data);

        if ($invoiceRequest['requested']) {
            $payment->marcarFacturaSolicitada($invoiceRequest['email']);
        }

        if (! $wasPaid && $payment->status === 'paid') {
            $this->applyMembershipExtension($payment);
            // Facturación electrónica al confirmar (correcciones / histórico).
            app(InvoicingService::class)->enqueueForPayment(
                $payment,
                force: (bool) $payment->fresh()->invoice_requested,
            );
        }

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name']));
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

    /** Deja rastro auditable de una sobrescritura manual del total. */
    private function auditAmountOverride(Payment $payment, ?string $reason, Request $request): void
    {
        try {
            // `actor_name` es NOT NULL con default: se omite si no hay usuario
            // en sesión (por ejemplo, acceso por token de servicio).
            AuditLog::create(array_filter([
                'action' => 'update',
                'module' => 'payments',
                'entity' => 'payment',
                'entity_id' => (string) $payment->id,
                'target_name' => $payment->reference,
                'actor_id' => optional($request->user())->id,
                'actor_name' => optional($request->user())->name,
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

    private function applyMembershipExtension(Payment $payment): void
    {
        if (! $payment->plan_id) {
            return;
        }

        /** @var User|null $user */
        $user = User::find($payment->user_id);
        /** @var Plan|null $plan */
        $plan = Plan::find($payment->plan_id);

        if (! $user || ! $plan || (int) $plan->duration_days <= 0) {
            return;
        }

        $paidDate = $payment->paid_at
            ? Carbon::parse($payment->paid_at)->startOfDay()
            : Carbon::today();
        $currentEnd = $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->startOfDay()
            : null;

        $baseDate = $currentEnd && $currentEnd->greaterThan($paidDate)
            ? $currentEnd
            : $paidDate;

        if (! $currentEnd || $currentEnd->lessThan($paidDate) || ! $user->membership_start_date) {
            $user->membership_start_date = $paidDate->toDateString();
        }

        $user->membership_end_date = $baseDate->copy()->addDays((int) $plan->duration_days)->toDateString();
        $user->plan = $plan->name;
        $user->status = 'active';
        $user->save();
    }
}
