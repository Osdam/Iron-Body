<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\CashShiftException;
use App\Exceptions\ReceivableException;
use App\Exceptions\PlanReplayException;
use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use App\Services\Audit\FinancialAudit;
use App\Services\Caja\CashReceipts;
use App\Services\Caja\DebtorDirectory;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Caja\ReceivableDueNotifier;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\MembershipPeriod;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\RealtimeEvents;
use App\Support\Access\AdminActor;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use App\Support\Caja\PaymentTerm;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    use ResolvesPagination;

    /** Las pestañas de Caja. `credits` se conserva por compatibilidad. */
    private const SCOPES = ['all', 'outstanding', 'credits', 'installments', 'paid', 'cancelled'];

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/admin/receivables — el listado, paginado en el servidor.
     *
     * Antes devolvía `limit(300)` sin paginar y el CRM filtraba lo que le
     * llegaba. Eso tenía dos problemas que crecen con el gimnasio: el navegador
     * se descargaba trescientas filas para enseñar veinticinco, y —peor— a
     * partir de la fila 301 la lista MENTÍA en silencio, porque el total
     * pendiente se sumaba sobre lo que había cabido.
     *
     * Aquí la página son 25 filas y los agregados se calculan en SQL sobre el
     * universo filtrado completo, no sobre la página. Un contador que depende de
     * cuántas filas pidió el navegador no es un contador.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'member_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Receivable::STATUSES)],
            'type' => ['nullable', Rule::in(CashShiftType::values())],
            // Las pestañas de Caja, por nombre. Antes el CRM las componía con
            // `outstanding=true`, y ese `true` viajaba como TEXTO: la regla
            // `boolean` de Laravel admite 1, 0, "1" y "0" pero no "true", así
            // que la pestaña Créditos respondía 422 y en pantalla salía «No se
            // pudo cargar las cuentas por cobrar». Un parámetro con nombre no
            // se puede escribir mal de esa manera.
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'outstanding' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $pagina = $this->scoped($this->filtered($filtros, $request), $filtros['scope'] ?? null)
            // Orden ESTABLE, y por eso desempatado por id: ordenar solo por
            // fecha hace que dos filas del mismo segundo puedan intercambiarse
            // entre consultas, y entonces una misma deuda sale en la página 2 y
            // en la 3 mientras otra no sale en ninguna.
            ->orderByDesc('receivables.created_at')
            ->orderByDesc('receivables.id')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));

        $cuentas = $pagina->getCollection();

        // Los deudores se resuelven de una vez, agrupados por tabla: uno a uno
        // serían veinticinco consultas para pintar una pantalla.
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
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'last_page' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'from' => $pagina->firstItem(),
                'to' => $pagina->lastItem(),
            ],
            // El total pendiente del UNIVERSO filtrado, no de la página: es la
            // respuesta a «cuánto me deben», y esa cifra no puede depender de en
            // qué página esté mirando quien pregunta.
            'summary' => $this->summary($filtros, $request, $filtros['scope'] ?? null),
            // Y los contadores de las pestañas, en una sola consulta agregada.
            'tabs' => $this->tabCounts($filtros, $request),
        ]);
    }

    /**
     * La consulta base con los filtros, SIN pestaña.
     *
     * Lo abonado entra por un `join` agregado y no por `withSum`: así el saldo
     * es una expresión SQL que se puede filtrar y sumar en la misma consulta
     * —`withSum` la deja fuera del WHERE— y de paso cae en el atributo que
     * `paidAmount()` ya sabe leer, así que pintar la página no dispara una
     * consulta por fila.
     */
    private function filtered(array $filtros, Request $request): Builder
    {
        $abonos = DB::table('receivable_payments')
            ->selectRaw('receivable_id, SUM(amount) AS aplicado, COUNT(*) AS abonos')
            ->where('status', ReceivablePayment::STATUS_APPLIED)
            ->groupBy('receivable_id');

        $q = Receivable::query()
            ->leftJoinSub($abonos, 'ap', 'ap.receivable_id', '=', 'receivables.id')
            ->select('receivables.*')
            ->selectRaw('COALESCE(ap.aplicado, 0) AS applied_payments_sum_amount')
            ->with('member:id,full_name,document_number,is_staff')
            ->with('cancelledBy:id,name');

        if (! empty($filtros['member_id'])) {
            $q->where('receivables.member_id', (int) $filtros['member_id']);
        }
        if (! empty($filtros['status'])) {
            $q->where('receivables.status', $filtros['status']);
        }
        if (! empty($filtros['type'])) {
            $q->where('receivables.type', $filtros['type']);
        }
        if ($request->boolean('outstanding')) {
            $q->outstanding();
        }
        // Los extremos se convierten al RANGO UTC del día del negocio. Con
        // `whereDate` sobre una columna en UTC, una deuda abierta a las ocho de
        // la noche se guarda con fecha del día siguiente y desaparecía del
        // filtro del día en que de verdad ocurrió.
        $recibos = app(CashReceipts::class);
        if (! empty($filtros['from'])) {
            $q->where('receivables.created_at', '>=', $recibos->businessDay(Carbon::parse($filtros['from']))[0]);
        }
        if (! empty($filtros['to'])) {
            $q->where('receivables.created_at', '<=', $recibos->businessDay(Carbon::parse($filtros['to']))[1]);
        }
        if (! empty($filtros['search'])) {
            $texto = trim($filtros['search']);
            $q->where(function (Builder $sub) use ($texto): void {
                $sub->where('receivables.concept', 'like', "%{$texto}%")
                    ->orWhere('receivables.created_by_name', 'like', "%{$texto}%")
                    ->orWhereHas('member', fn ($m) => $m
                        ->where('full_name', 'like', "%{$texto}%")
                        ->orWhere('document_number', 'like', "%{$texto}%"));
            });
        }

        return $q;
    }

    /**
     * La pestaña, aplicada en SQL.
     *
     * SALDOS y ABONOS SE SOLAPAN A PROPÓSITO, y esto es lo que estaba mal: un
     * plan de 80.000 con 70.000 abonados salía en «Abonos» y no en «Saldos»,
     * así que quien preguntaba «¿quién me debe?» no veía los 10.000 que
     * faltaban. Son dos preguntas distintas sobre las mismas filas —«¿quién
     * todavía me debe?» y «¿qué cuentas abiertas ya recibieron pagos?»— y
     * forzarlas a ser conjuntos disjuntos es lo que escondía la deuda.
     */
    private function scoped(Builder $q, ?string $scope): Builder
    {
        return match ($scope) {
            // Toda obligación ABIERTA con saldo, haya recibido abonos o no.
            'outstanding' => $q->outstanding()->whereRaw($this->saldoSql().' > 0'),
            // Abiertas que YA recibieron al menos un abono aplicado.
            'installments' => $q->outstanding()->whereRaw('COALESCE(ap.abonos, 0) > 0'),
            'credits' => $q->outstanding()->where('receivables.source_type', ProductSale::class),
            'paid' => $q->where('receivables.status', Receivable::STATUS_PAID),
            'cancelled' => $q->where('receivables.status', Receivable::STATUS_CANCELLED),
            // «Todas» es TODO el historial del contexto, incluidas las saldadas
            // y las anuladas: es el libro, no la lista de cobro.
            default => $q,
        };
    }

    /**
     * La misma consulta, preparada para CONTAR en vez de para listar.
     *
     * Hay que vaciar la lista de columnas antes de pedir los agregados: sin
     * eso, el `select('receivables.*')` del listado se queda y la consulta
     * acaba siendo `SELECT receivables.*, COUNT(*) …`. SQLite lo tolera y
     * PostgreSQL —que es lo que corre en producción— lo rechaza con «column
     * receivables.id must appear in the GROUP BY clause». Es un 500 que la
     * suite local no puede ver, y por eso hay una prueba que mira el SQL.
     *
     * `toBase()` además evita hidratar modelos y arrastrar los `with()` del
     * listado: para contar no hace falta traerse a nadie.
     */
    private function agregado(Builder $q): QueryBuilder
    {
        return $q->toBase()->reorder()->select([]);
    }

    /** El saldo, en SQL: total menos lo aplicado. */
    private function saldoSql(): string
    {
        return '(receivables.total_amount - COALESCE(ap.aplicado, 0))';
    }

    /**
     * Los agregados del universo filtrado.
     *
     * El pendiente suma SOLO las abiertas: una deuda anulada conserva su saldo
     * en la fila —no se reescribe el total— pero ya no se exige, y contarla
     * diría que hay dinero por recuperar que nadie va a reclamar.
     *
     * @return array{count:int, outstanding_total:float}
     */
    private function summary(array $filtros, Request $request, ?string $scope): array
    {
        $fila = $this->agregado($this->scoped($this->filtered($filtros, $request), $scope))
            ->selectRaw('COUNT(*) AS filas')
            ->selectRaw('COALESCE(SUM(CASE WHEN receivables.status IN (?, ?) THEN '
                .$this->saldoSql().' ELSE 0 END), 0) AS pendiente',
                [Receivable::STATUS_PENDING, Receivable::STATUS_PARTIALLY_PAID])
            ->first();

        return [
            'count' => (int) ($fila->filas ?? 0),
            'outstanding_total' => round((float) ($fila->pendiente ?? 0), 2),
        ];
    }

    /**
     * Cuántas filas tiene cada pestaña, en UNA consulta.
     *
     * Se cuenta en SQL y no sobre arrays descargados: contar en el navegador
     * obliga a traerse las filas para no enseñarlas, que es exactamente lo que
     * esta pantalla dejó de hacer.
     *
     * @return array<string,int>
     */
    private function tabCounts(array $filtros, Request $request): array
    {
        $abiertas = '(receivables.status IN (\''.Receivable::STATUS_PENDING
            .'\', \''.Receivable::STATUS_PARTIALLY_PAID.'\'))';

        $fila = $this->agregado($this->filtered($filtros, $request))
            ->selectRaw('COUNT(*) AS todas')
            ->selectRaw("SUM(CASE WHEN {$abiertas} AND ".$this->saldoSql().' > 0 THEN 1 ELSE 0 END) AS saldos')
            ->selectRaw("SUM(CASE WHEN {$abiertas} AND COALESCE(ap.abonos, 0) > 0 THEN 1 ELSE 0 END) AS abonos")
            ->selectRaw('SUM(CASE WHEN receivables.status = ? THEN 1 ELSE 0 END) AS pagadas', [Receivable::STATUS_PAID])
            ->selectRaw('SUM(CASE WHEN receivables.status = ? THEN 1 ELSE 0 END) AS anuladas', [Receivable::STATUS_CANCELLED])
            ->first();

        return [
            'all' => (int) ($fila->todas ?? 0),
            'outstanding' => (int) ($fila->saldos ?? 0),
            'installments' => (int) ($fila->abonos ?? 0),
            'paid' => (int) ($fila->pagadas ?? 0),
            'cancelled' => (int) ($fila->anuladas ?? 0),
        ];
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
        $receivable->load('member:id,full_name,document_number,is_staff', 'cancelledBy:id,name');

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
                dueAt: PaymentTerm::resolve($data['due_at'] ?? null, CashShiftType::from($data['type']), derivarPorDefecto: false),
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
            // Inicio de la membresía, igual que en el cobro completo: se paga
            // hoy la primera parte y se puede empezar otro día.
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($error = MembershipPeriod::startError($data['starts_on'] ?? null)) {
            throw ValidationException::withMessages(['starts_on' => [$error]]);
        }

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
            $vence = PaymentTerm::resolve($data['due_at'] ?? null, CashShiftType::GYM);

            [$pago, $cuenta, $abono] = DB::transaction(function () use ($data, $plan, $total, $primero, $member, $actor, $request, $requestId, $vence) {
                $pago = Payment::create([
                    'user_id' => (int) $data['user_id'],
                    'plan_id' => $plan->id,
                    'amount' => $total->toFloat(),
                    'method' => 'receivable',
                    'status' => 'paid',
                    'paid_at' => now(),
                    'starts_on' => $data['starts_on'] ?? null,
                    // SIN turno, a propósito: ver la nota del método.
                    'cash_shift_id' => null,
                    // Pero sí del mostrador, y con quien lo vendió: la venta
                    // es del gimnasio aunque el dinero llegue en abonos.
                    'origin' => PaymentOrigin::COUNTER->value,
                    ...Payment::registrarAttributes($actor),
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
     * POST /api/admin/receivables/{receivable}/cancel — anular una deuda.
     *
     * ANULAR NO ES NINGUNA DE ESTAS TRES COSAS, y la confusión es cara:
     *
     *   · No devuelve dinero. Los abonos cobrados siguen cobrados y siguen en
     *     el arqueo del turno donde entraron. Para devolverlos está el reverso,
     *     que es otra decisión y otro permiso.
     *   · No devuelve el producto. El inventario tiene su propio flujo.
     *   · No cancela la membresía. Que la deuda se perdone no dice que el socio
     *     dejara de entrenar.
     *
     * Lo único que deja de existir es la EXIGENCIA del saldo. Por eso exige
     * `receivables.manage` y no `receivables.operate`: quien cobra en el
     * mostrador no decide qué deudas dejan de cobrarse.
     */
    public function cancel(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $reteniaAntes = $this->retieneAlSocio($receivable);

        try {
            $cuenta = app(ReceivableService::class)
                ->cancel($receivable, $data['reason'], AdminActor::from($request));
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        }

        // La alerta de mora se cierra DICIENDO que fue por anulación: dejarla
        // abierta pondría a alguien a perseguir un cobro que ya no se exige, y
        // cerrarla como «pagada» diría que entró un dinero que no entró.
        app(ReceivableDueNotifier::class)->cerrarAlerta(
            $cuenta,
            ReceivableDueNotifier::RESOLUTION_CANCELLED,
            'La deuda se anuló: '.$data['reason'],
        );

        return $this->respondWithReceivable($cuenta, $reteniaAntes);
    }

    /**
     * POST /api/admin/receivables/{receivable}/reopen — deshacer una anulación.
     *
     * Anular es una decisión humana, y las decisiones humanas se toman mal a
     * veces. El estado al que vuelve NO se elige: lo recalcula el saldo.
     *
     * Si su plazo ya pasó, reabrir vuelve a retener al socio en el acto y a
     * levantar la alerta de administración. Esa alerta se reabre AQUÍ y no se
     * deja al detector nocturno: su llave de idempotencia lleva la fecha y la
     * generación del saldo, que al reabrir son las mismas de antes, así que la
     * mora no volvería a emitirse y el problema se quedaría sin dueño.
     */
    public function reopen(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $reteniaAntes = $this->retieneAlSocio($receivable);

        try {
            $cuenta = app(ReceivableService::class)
                ->reopen($receivable, $data['reason'], AdminActor::from($request));
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        }

        if ($cuenta->isOverdue()) {
            app(ReceivableDueNotifier::class)->alertarAdministracion($cuenta, Receivable::businessToday());
        }

        return $this->respondWithReceivable($cuenta, $reteniaAntes);
    }

    /**
     * PATCH /api/admin/receivables/{receivable}/due-date — pactar otro plazo.
     *
     * Es la operación del mostrador: alguien no pudo pagar y se acuerda una
     * fecha nueva. Basta `receivables.operate` porque no perdona nada —la deuda
     * sigue entera— y es exactamente la conversación que tiene quien atiende.
     *
     * Se admite mover el plazo al PASADO. Suena raro y es deliberado: sirve
     * para corregir una fecha mal tecleada, y quien lo haga verá que la deuda
     * queda vencida en el acto, que es el resultado correcto.
     */
    public function changeDueDate(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'due_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $this->applyDueDate(
            $receivable,
            Carbon::parse($data['due_at'])->startOfDay(),
            $data['reason'],
            $request,
        );
    }

    /**
     * DELETE /api/admin/receivables/{receivable}/due-date — dejarla sin plazo.
     *
     * QUITAR EL PLAZO NO PERDONA NADA: la deuda sigue existiendo y sigue
     * teniendo saldo. Lo que deja de tener es fecha, y por tanto deja de estar
     * vencida y deja de retener. Sirve mientras un caso está en revisión o se
     * negocia un acuerdo especial.
     *
     * Justo por eso exige `receivables.manage` y no `operate`: es la forma
     * silenciosa de levantar una retención, y no puede quedar al alcance de
     * quien solo cobra.
     */
    public function removeDueDate(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $this->applyDueDate($receivable, null, $data['reason'], $request);
    }

    /** El tronco común de cambiar y quitar el plazo. */
    private function applyDueDate(
        Receivable $receivable,
        ?CarbonInterface $dueAt,
        string $reason,
        Request $request,
    ): JsonResponse {
        $reteniaAntes = $this->retieneAlSocio($receivable);

        try {
            $cuenta = app(ReceivableService::class)
                ->changeDueDate($receivable, $dueAt, $reason, AdminActor::from($request));
        } catch (ReceivableException $e) {
            return $this->receivableError($e);
        }

        // Si dejó de estar vencida, la alerta abierta describe un problema que
        // ya no existe. Se cierra como APLAZADA —no como pagada— para que
        // vuelva a abrirse sola si el plazo nuevo también se pasa.
        if (! $cuenta->isOverdue()) {
            app(ReceivableDueNotifier::class)->cerrarAlerta(
                $cuenta,
                ReceivableDueNotifier::RESOLUTION_RESCHEDULED,
                $dueAt === null
                    ? 'Se le quitó el plazo: '.$reason
                    : 'Se pactó un plazo nuevo al '.$dueAt->toDateString().': '.$reason,
            );
        }

        return $this->respondWithReceivable($cuenta, $reteniaAntes);
    }

    /**
     * ¿Esta deuda está reteniendo a su socio AHORA MISMO?
     *
     * Se pregunta por el SOCIO y no por la deuda: tener otra obligación vencida
     * deja la retención puesta igual, y avisar a la app de un cambio que no
     * ocurrió la haría mostrar «al día» a quien no lo está.
     */
    private function retieneAlSocio(Receivable $receivable): bool
    {
        return $receivable->member_id !== null
            && app(MembershipFinancialStanding::class)->isOverdue($receivable->member);
    }

    /**
     * Respuesta común de las correcciones administrativas.
     *
     * Empuja el refresco de la app SOLO si la retención cambió de verdad. Una
     * corrección administrativa la hace una persona y no hay riesgo de despertar
     * a todos los móviles, pero un `app_state` que no corresponde a ningún
     * cambio enseña a la app a recargar por nada.
     */
    private function respondWithReceivable(Receivable $cuenta, bool $reteniaAntes): JsonResponse
    {
        if ($cuenta->member_id !== null && $this->retieneAlSocio($cuenta) !== $reteniaAntes) {
            RealtimeEvents::emit($cuenta->member_id, RealtimeEvents::APP_STATE, ['membership', 'financial']);
        }

        return response()->json([
            'ok' => true,
            'data' => $cuenta->fresh()->load('member', 'cancelledBy:id,name')->toCrmArray(),
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
