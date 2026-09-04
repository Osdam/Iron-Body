<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftStatus;
use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CashShift;
use App\Services\Caja\CashShiftOrchestrator;
use App\Services\Caja\CashShiftService;
use App\Services\Audit\AuditTrail;
use App\Services\Caja\CashShiftPdfService;
use App\Services\Caja\CashShiftReport;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Turnos de las dos cajas: productos y gimnasio.
 *
 * Un solo controlador para ambas. El tipo es un parámetro, no un módulo
 * distinto: duplicar esto en dos controladores garantizaría que dentro de seis
 * meses uno tenga un arreglo que al otro le falta.
 *
 * NINGÚN importe llega del cliente. La apertura es en cero y el cierre lo
 * recalcula el backend dentro de la transacción. Si Angular enviara totales,
 * serían totales que Angular puede elegir.
 */
class CashShiftController extends Controller
{
    public function __construct(
        private readonly CashShiftService $shifts,
        private readonly CashShiftOrchestrator $orchestrator,
    ) {}

    /** GET /api/admin/caja/shift?type=products|gym */
    public function current(Request $request): JsonResponse
    {
        $type = $this->type($request);
        if (! CrmPermission::allows(AdminActor::from($request), $type->viewPermission())) {
            return $this->forbidden($type->viewPermission());
        }

        $shift = CashShift::currentOfType($type);

        return response()->json([
            'data' => $shift?->toCrmArray(withTotals: true),
        ]);
    }

    /**
     * GET /api/admin/caja/shifts?type=&status=&from=&to=&opened_by=
     *
     * Las fechas se interpretan en la zona OPERATIVA del negocio: quien filtra
     * "3 de septiembre" quiere el día del gimnasio, no una ventana UTC que
     * empieza a las 19:00 del día anterior.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'type' => ['nullable', Rule::in(CashShiftType::values())],
            'status' => ['nullable', Rule::in(CashShiftStatus::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'opened_by' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $admin = AdminActor::from($request);
        $visibles = array_values(array_filter(
            CashShiftType::cases(),
            fn (CashShiftType $t) => CrmPermission::allows($admin, $t->viewPermission()),
        ));
        if ($visibles === []) {
            return $this->forbidden(CashShiftType::PRODUCTS->viewPermission());
        }

        $query = CashShift::query()->orderByDesc('id');

        // Nunca se listan turnos de una caja que el actor no puede ver, aunque
        // pida explícitamente ese `type`.
        if (isset($filtros['type'])) {
            $pedido = CashShiftType::from($filtros['type']);
            if (! in_array($pedido, $visibles, true)) {
                return $this->forbidden($pedido->viewPermission());
            }
            $query->ofType($pedido);
        } else {
            $query->whereIn('type', array_map(fn (CashShiftType $t) => $t->value, $visibles));
        }

        if (isset($filtros['status'])) {
            $query->where('status', $filtros['status']);
        }
        if (isset($filtros['opened_by'])) {
            $query->where('opened_by', $filtros['opened_by']);
        }

        $tz = config('caja.timezone');
        if (isset($filtros['from'])) {
            $query->where('opened_at', '>=', \Carbon\CarbonImmutable::parse($filtros['from'], $tz)->startOfDay()->utc());
        }
        if (isset($filtros['to'])) {
            $query->where('opened_at', '<=', \Carbon\CarbonImmutable::parse($filtros['to'], $tz)->endOfDay()->utc());
        }

        $page = $query->paginate((int) ($filtros['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (CashShift $s) => $s->toCrmArray())->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/caja/shift/open  { type, also? }
     *
     * Sin importe: `opening_amount` es siempre 0 (política `zero`). `also=true`
     * abre además la otra caja, y el permiso de esa otra se comprueba dentro
     * del orquestador: marcar la casilla no concede nada.
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Opcional con 'products' por defecto: los clientes anteriores a las
            // dos cajas no lo envían y deben seguir operando la de siempre.
            'type' => ['nullable', Rule::in(CashShiftType::values())],
            'also' => ['nullable', 'boolean'],
        ]);

        $admin = AdminActor::from($request);
        if ($admin === null) {
            // El token de automatizaciones no es una persona, y un turno tiene
            // que tener un responsable con nombre.
            return $this->forbidden(null, 'Abrir caja exige una sesión de administrador.');
        }

        $type = CashShiftType::from($data['type'] ?? CashShiftType::PRODUCTS->value);
        $types = ($data['also'] ?? false) ? [$type, $type->other()] : [$type];

        if ($rechazo = $this->denegarSiNoOperaNinguna($admin, $types)) {
            return $rechazo;
        }

        $resultado = $this->orchestrator->open($admin, $types);

        return response()->json([
            'ok' => $this->allOk($resultado),
            'results' => $resultado,
        ], $this->allOk($resultado) ? 201 : 207);
    }

    /**
     * POST /api/admin/caja/shift/close  { type, also?, note?, forced_reason? }
     *
     * No acepta `counted_amount`: el cierre cotidiano no cuenta billetes. El
     * arqueo físico es {@see difference()}, con permiso de supervisión.
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(CashShiftType::values())],
            'also' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
            'forced_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $admin = AdminActor::from($request);
        if ($admin === null) {
            return $this->forbidden(null, 'Cerrar caja exige una sesión de administrador.');
        }

        $type = CashShiftType::from($data['type'] ?? CashShiftType::PRODUCTS->value);
        $types = ($data['also'] ?? false) ? [$type, $type->other()] : [$type];

        if ($rechazo = $this->denegarSiNoOperaNinguna($admin, $types)) {
            return $rechazo;
        }

        $resultado = $this->orchestrator->close(
            $admin,
            $types,
            $data['note'] ?? null,
            $data['forced_reason'] ?? null,
        );

        return response()->json([
            'ok' => $this->allOk($resultado),
            'results' => $resultado,
        ], $this->allOk($resultado) ? 200 : 207);
    }

    /**
     * POST /api/admin/caja/shifts/{shift}/difference  { counted_amount, reason }
     *
     * Arqueo físico EXCEPCIONAL sobre un turno ya cerrado. Separado del cierre
     * a propósito: pedir el conteo todos los días convertía una comprobación
     * real en un trámite que se rellena de cualquier manera.
     */
    /**
     * GET /api/admin/caja/shifts/{shift} — informe de un turno.
     *
     * Sustituye a la captura de pantalla: antes, cerrar la caja y no guardar
     * una imagen significaba perder el arqueo. Aquí el turno se puede volver a
     * abrir siempre, con su detalle.
     *
     * Basta `view` de SU tipo. Consultar un cierre no es operar una caja, y
     * exigir `manage` para leer dejaría el informe fuera del alcance de quien
     * lo necesita a diario.
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        if ($rechazo = $this->denegarSiNoPuedeVer($request, $shift)) {
            return $rechazo;
        }

        return response()->json(app(CashShiftReport::class)->for($shift));
    }

    /**
     * GET /api/admin/caja/shifts/{shift}/pdf — el mismo informe, en papel.
     *
     * Misma autorización que `show`: si alguien no puede ver el turno en
     * pantalla, tampoco puede descargarlo. Comprobar solo una de las dos
     * puertas dejaría la otra abierta cambiando la URL.
     */
    public function pdf(Request $request, CashShift $shift): SymfonyResponse
    {
        if ($rechazo = $this->denegarSiNoPuedeVer($request, $shift)) {
            return $rechazo;
        }

        $servicio = app(CashShiftPdfService::class);

        return response($servicio->render($shift), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$servicio->filename($shift).'"',
        ]);
    }

    /**
     * ¿Puede este actor ver ESTE turno?
     *
     * La comprobación es por el tipo del turno pedido, no por el que el cliente
     * diga: sin esto, quien solo ve Productos leería un cierre del gimnasio
     * cambiando el id en la URL.
     */
    /**
     * ¿Puede este actor operar ALGUNA de las cajas que pide?
     *
     * Si no puede con ninguna, es un 403 y no una operación que devuelve dos
     * negativas: la ruta ya no lleva permiso fijo, y sin esta guarda quien no
     * tiene nada recibiría un 207 con todo denegado, que parece un resultado
     * parcial cuando en realidad no se le permitió intentar nada.
     *
     * NO sustituye a la comprobación por caja del orquestador, que sigue siendo
     * la que autoriza: aquí basta con que UNA sea operable, y las demás se
     * deniegan después una por una. Esa asimetría es deliberada —abrir las dos
     * de un clic teniendo permiso solo para una debe abrir la que sí— y es el
     * comportamiento ya validado en producción.
     *
     * @param  CashShiftType[]  $types
     */
    private function denegarSiNoOperaNinguna(Admin $admin, array $types): ?JsonResponse
    {
        foreach ($types as $type) {
            if (CrmPermission::allows($admin, $type->operatePermission())) {
                return null;
            }
        }

        return $this->forbidden($types[0]->operatePermission());
    }

    private function denegarSiNoPuedeVer(Request $request, CashShift $shift): ?JsonResponse
    {
        $admin = AdminActor::from($request);
        if ($admin === null || ! CrmPermission::allows($admin, $shift->type->viewPermission())) {
            return $this->forbidden($shift->type->viewPermission());
        }

        return null;
    }

    public function difference(Request $request, CashShift $shift): JsonResponse
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $admin = AdminActor::from($request);
        if ($admin === null) {
            return $this->forbidden(null, 'Registrar un arqueo exige una sesión de administrador.');
        }
        if (! CrmPermission::allows($admin, $shift->type->managePermission())) {
            return $this->forbidden($shift->type->managePermission());
        }

        try {
            $actualizado = DB::transaction(function () use ($admin, $shift, $data, $request) {
                $resultado = $this->shifts->registerDifference(
                    $admin, $shift, (float) $data['counted_amount'], $data['reason'],
                );

                // Dentro de la MISMA transacción: un arqueo aplicado sin traza
                // sería un descuadre firmado por nadie.
                app(AuditTrail::class)->record($request, [
                    'action' => 'update',
                    'module' => 'Caja',
                    'entity' => 'arqueo',
                    'entity_id' => $resultado->id,
                    'target_name' => 'Caja '.$resultado->type->label().' · turno #'.$resultado->id,
                    'summary' => sprintf('Registró el arqueo del turno #%d', $resultado->id),
                    'metadata' => [
                        'cash_shift_id' => $resultado->id,
                        'type' => $resultado->type->value,
                        'expected_amount' => (string) $resultado->expected_amount,
                        'counted_amount' => (string) $resultado->counted_amount,
                        'difference' => (string) $resultado->difference,
                    ],
                ]);

                return $resultado;
            });
        } catch (CashShiftException $e) {
            return response()->json(['ok' => false, 'code' => $e->code_, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'data' => $actualizado->toCrmArray()]);
    }

    /** El tipo pedido, con productos por defecto para compatibilidad. */
    private function type(Request $request): CashShiftType
    {
        $raw = (string) $request->query('type', CashShiftType::PRODUCTS->value);

        return CashShiftType::tryFrom($raw) ?? CashShiftType::PRODUCTS;
    }

    /** @param array<string, array{result: string}> $resultado */
    private function allOk(array $resultado): bool
    {
        foreach ($resultado as $r) {
            if (! in_array($r['result'], ['opened', 'closed'], true)) {
                return false;
            }
        }

        return true;
    }

    private function forbidden(?string $permission, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => false,
            'code' => 'forbidden',
            'message' => $message ?? 'No tienes permiso para esta acción.',
            'required_permission' => $permission,
        ], fn ($v) => $v !== null), 403);
    }
}
