<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Exceptions\ReceiptException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Models\ReceiptSource;
use App\Services\Caja\ReceiptIssuer;
use App\Services\Caja\ReceiptPresenter;
use App\Services\Caja\ReceiptPrints;
use App\Services\Caja\ReceiptSourceResolver;
use App\Services\Caja\ShiftReceipts;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recibos térmicos de caja: emitir, consultar y dejar rastro de cada impresión.
 *
 * NO TOCA DINERO. Ningún endpoint de aquí crea, modifica ni anula un cobro, una
 * venta, un abono, un turno, un movimiento de inventario ni una factura: un
 * recibo es una LECTURA congelada de una operación que ya ocurrió. Si emitirlo
 * pudiera mover algo, imprimir dos veces podría cobrar dos veces.
 *
 * AUTORIZACIÓN EN DOS PUERTAS. El mapa central deja pasar a quien tenga algún
 * permiso de caja (ver AuthorizationMap::RECEIPT_CASH_PERMISSIONS); aquí se exige
 * además:
 *
 *  · una PERSONA. El token compartido de automatizaciones obtiene `*.view` por
 *    ser lectura, y con eso pasaría la primera puerta. Un recibo lo emite y lo
 *    imprime alguien con nombre, o nadie.
 *  · el permiso de LA CAJA del recibo concreto: con `cash.products.*` se imprime
 *    la cafetería, no el gimnasio. La caja sale del dato, no de lo que diga el
 *    cliente.
 */
class ReceiptController extends Controller
{
    /** Abonos por recibo: un «cobrar todo» real no llega ni a la mitad. */
    private const MAX_SOURCE_IDS = 50;

    public function __construct(
        private readonly ReceiptSourceResolver $resolver,
        private readonly ReceiptIssuer $issuer,
        private readonly ReceiptPresenter $presenter,
        private readonly ReceiptPrints $prints,
    ) {}

    /**
     * POST /api/admin/receipts — emitir (o recuperar) el recibo de una operación.
     *
     * body: {source_type, source_id} | {source_type: 'receivable_payment', source_ids: [...]}
     * 201 si se creó alguno, 200 si todos existían. Siempre una LISTA: un cobro
     * de deudas de las dos cajas produce dos recibos.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        // Antes de validar: `distinct` compara cada id con todos y una lista de
        // 20.000 tardaba segundos de CPU en llegar al mismo 422.
        if (is_array($request->input('source_ids')) && count($request->input('source_ids')) > self::MAX_SOURCE_IDS) {
            throw ValidationException::withMessages([
                'source_ids' => ['Como mucho '.self::MAX_SOURCE_IDS.' abonos por recibo.'],
            ]);
        }

        $data = $request->validate([
            'source_type' => ['required', 'string', Rule::in(ReceiptSource::TYPES)],
            'source_id' => ['nullable', 'integer', 'min:1', 'prohibits:source_ids'],
            'source_ids' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_SOURCE_IDS],
            'source_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        $ids = isset($data['source_ids']) ? array_values($data['source_ids']) : (isset($data['source_id']) ? [$data['source_id']] : []);

        if ($ids === []) {
            throw ValidationException::withMessages(['source_id' => ['Indica de qué operación es el recibo.']]);
        }
        if (isset($data['source_ids']) && $data['source_type'] !== ReceiptSource::TYPE_RECEIVABLE_PAYMENT) {
            throw ValidationException::withMessages([
                'source_ids' => ['Solo los abonos se piden en grupo; un cobro o una venta llevan source_id.'],
            ]);
        }

        // LA CAJA PRIMERO, antes de decir nada sobre la operación: si se
        // resolviera antes, el motivo de un rechazo («fue por pasarela», «está
        // anulado») le contaría a quien solo tiene productos cosas del gimnasio.
        // Y la de CADA recibo, antes de emitir ninguno: con permiso solo para una
        // caja no se emite media petición.
        foreach ($this->resolver->cashTypesFor($data['source_type'], $ids) as $caja) {
            if (! $this->canWriteCash($actor, $caja)) {
                return $this->forbidden($caja->operatePermission());
            }
        }

        try {
            ['existing' => $existentes, 'candidates' => $candidatos] = $this->resolver->resolve($data['source_type'], $ids);
        } catch (ReceiptException $e) {
            return $this->receiptError($e);
        }

        $creado = false;
        $recibos = $existentes;

        try {
            foreach ($candidatos as $candidato) {
                [$recibo, $nuevo] = $this->issuer->issue($candidato, $actor);
                $recibos[] = $recibo;
                $creado = $creado || $nuevo;
            }
        } catch (ReceiptException $e) {
            return $this->receiptError($e);
        }

        // Un recibo puede llegar dos veces (dos abonos de la lista que ya
        // estaban en el mismo): se devuelve una vez.
        $unicos = collect($recibos)->unique('id')->sortBy('id')->values();

        return response()->json([
            'ok' => true,
            'data' => $unicos->map(fn (Receipt $r) => $this->presenter->toArray($r))->all(),
        ], $creado ? 201 : 200);
    }

    /** GET /api/admin/receipts/{uuid} — el recibo con sus últimos intentos. */
    public function show(Request $request, string $receipt): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        [$recibo, $rechazo] = $this->receiptFor($actor, $receipt);
        if ($rechazo) {
            return $rechazo;
        }

        return response()->json([
            'ok' => true,
            'data' => $this->presenter->toArray($recibo, withPrints: true),
        ]);
    }

    /**
     * POST /api/admin/receipts/{uuid}/prints — «voy a imprimir».
     *
     * body: {client_attempt_id, context, print_mode}
     * El SERVIDOR decide si es el original, un reintento o una reimpresión.
     * Repetir el mismo `client_attempt_id` devuelve 200 con la misma respuesta.
     */
    public function startPrint(Request $request, string $receipt): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        [$recibo, $rechazo] = $this->receiptFor($actor, $receipt, write: true);
        if ($rechazo) {
            return $rechazo;
        }

        $data = $request->validate([
            'client_attempt_id' => ['required', 'uuid'],
            'context' => ['required', Rule::in(ReceiptPrint::CONTEXTS)],
            'print_mode' => ['nullable', Rule::in(ReceiptPrint::PRINT_MODES)],
        ]);

        $resultado = $this->prints->start(
            $recibo,
            strtolower($data['client_attempt_id']),
            $data['context'],
            $data['print_mode'] ?? 'unknown',
            $actor,
            $request,
        );

        if (isset($resultado['conflict'])) {
            return $this->attemptConflict();
        }

        $intento = $resultado['print'];

        return response()->json([
            'ok' => true,
            'data' => [
                'client_attempt_id' => $intento->uuid,
                'kind' => $intento->kind,
                'copy_label' => $this->prints->copyLabel($intento),
            ],
        ], $resultado['created'] ? 201 : 200);
    }

    /**
     * PATCH /api/admin/receipts/{uuid}/prints/{client_attempt_id} — cómo terminó.
     *
     * body: {status: 'sent'|'error', error_message?}
     * Solo desde `started`, una vez, y solo quien lo abrió: 403 si es otro,
     * 409 si ya estaba cerrado.
     */
    public function finishPrint(Request $request, string $receipt, string $attempt): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        [$recibo, $rechazo] = $this->receiptFor($actor, $receipt, write: true);
        if ($rechazo) {
            return $rechazo;
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([ReceiptPrint::STATUS_SENT, ReceiptPrint::STATUS_ERROR])],
            // Lo que contó el navegador. Se guarda recortado a 255: un mensaje
            // largo no puede hacer fallar el registro de que la impresión falló.
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! Str::isUuid($attempt)) {
            return $this->notFound('Ese intento de impresión no existe.');
        }

        $resultado = $this->prints->finish(
            $recibo,
            strtolower($attempt),
            $data['status'],
            $data['error_message'] ?? null,
            $actor,
        );

        if (isset($resultado['error'])) {
            return match ($resultado['error']) {
                'not_found' => $this->notFound('Ese intento de impresión no existe.'),
                'forbidden' => $this->forbidden(null, 'Solo quien inició esta impresión puede cerrarla.'),
                'not_a_print_attempt' => response()->json([
                    'ok' => false,
                    'code' => 'not_a_print_attempt',
                    'message' => 'Ese identificador es una omisión, no una impresión.',
                    'status' => $resultado['status'] ?? null,
                ], 409),
                default => response()->json([
                    'ok' => false,
                    'code' => 'print_already_finished',
                    'message' => 'Este intento de impresión ya estaba cerrado.',
                    'status' => $resultado['status'] ?? null,
                ], 409),
            };
        }

        $intento = $resultado['print'];

        return response()->json([
            'ok' => true,
            'data' => [
                'client_attempt_id' => $intento->uuid,
                'kind' => $intento->kind,
                'status' => $intento->status,
                'print_state' => $recibo->printState($recibo->prints()->get()),
            ],
        ]);
    }

    /**
     * POST /api/admin/receipts/{uuid}/skip — no imprimir esta vez.
     *
     * body: {client_attempt_id, reason?, context?}
     * Con `receipts.print_optional`: 200, queda `skipped` con su autor. Sin él:
     * se registra el intento como `denied` y se audita ANTES de responder 403.
     * Si ya salió papel, 409: no hay nada que omitir.
     */
    public function skip(Request $request, string $receipt): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        [$recibo, $rechazo] = $this->receiptFor($actor, $receipt, write: true);
        if ($rechazo) {
            return $rechazo;
        }

        $data = $request->validate([
            'client_attempt_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', Rule::in(ReceiptPrint::CONTEXTS)],
        ]);

        $resultado = $this->prints->skip(
            $recibo,
            strtolower($data['client_attempt_id']),
            isset($data['reason']) ? trim($data['reason']) : null,
            $data['context'] ?? 'checkout',
            $actor,
            $request,
        );

        if (isset($resultado['conflict'])) {
            return $this->attemptConflict();
        }

        if (isset($resultado['already_printed'])) {
            return response()->json([
                'ok' => false,
                'code' => 'already_printed',
                'message' => 'Este recibo ya se imprimió: no hay nada que omitir.',
            ], 409);
        }

        $intento = $resultado['print'];

        if ($intento->status === ReceiptPrint::STATUS_DENIED) {
            return $this->forbidden(
                ReceiptPrints::PERMISSION_OPTIONAL,
                'No tienes permiso para omitir la impresión del recibo.',
            );
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'client_attempt_id' => $intento->uuid,
                'kind' => $intento->kind,
                'status' => $intento->status,
                'print_state' => $recibo->printState($recibo->prints()->get()),
            ],
        ]);
    }

    /**
     * GET /api/admin/caja/shifts/{shift}/receipts — qué tirillas faltan.
     *
     * Misma regla que el informe del turno: la caja del TURNO decide el permiso,
     * no la que diga el cliente.
     */
    public function shift(Request $request, CashShift $shift, ShiftReceipts $vista): JsonResponse
    {
        if (! $actor = AdminActor::from($request)) {
            return $this->sinPersona();
        }

        if (! $this->canUseCash($actor, $shift->type)) {
            return $this->forbidden($shift->type->viewPermission());
        }

        return response()->json(array_merge(['ok' => true], $vista->for($shift)));
    }

    // ── Comunes ─────────────────────────────────────────────────────────────

    /**
     * El recibo pedido, si existe y si el actor puede con SU caja.
     *
     * @return array{0: Receipt|null, 1: JsonResponse|null}
     */
    private function receiptFor(Admin $actor, string $uuid, bool $write = false): array
    {
        // Antes de consultar: en PostgreSQL la columna es `uuid` y un texto que
        // no lo sea revienta la consulta con un 500 en vez de dar un 404.
        $recibo = Str::isUuid($uuid) ? Receipt::where('uuid', strtolower($uuid))->first() : null;

        if ($recibo === null) {
            return [null, $this->notFound('Ese recibo no existe.')];
        }

        if ($write ? ! $this->canWriteCash($actor, $recibo->cashType()) : ! $this->canUseCash($actor, $recibo->cashType())) {
            return [null, $this->forbidden($write ? $recibo->cashType()->operatePermission() : $recibo->cashType()->viewPermission())];
        }

        return [$recibo, null];
    }

    /**
     * Emitir, imprimir u omitir ESCRIBE: exige operar o supervisar esa caja.
     * Con solo «ver» se consulta (el recibo y la vista del turno), nada más.
     */
    private function canWriteCash(Admin $actor, CashShiftType $caja): bool
    {
        return CrmPermission::allows($actor, $caja->operatePermission())
            || CrmPermission::allows($actor, $caja->managePermission());
    }

    /** Cualquiera de los tres permisos de ESA caja: ver, operar o supervisar. */
    private function canUseCash(Admin $actor, CashShiftType $caja): bool
    {
        foreach ([$caja->viewPermission(), $caja->operatePermission(), $caja->managePermission()] as $permiso) {
            if (CrmPermission::allows($actor, $permiso)) {
                return true;
            }
        }

        return false;
    }

    private function receiptError(ReceiptException $e): JsonResponse
    {
        $estado = match ($e->code_) {
            'not_found' => 404,
            'not_printable' => 422,
            'receipt_conflict' => 409,
            default => 422,
        };

        return response()->json(array_filter([
            'ok' => false,
            'code' => $e->code_,
            'reason' => $e->reason,
            'message' => $e->getMessage(),
            'source_type' => $e->sourceType,
            'source_id' => $e->sourceId,
        ], fn ($v) => $v !== null), $estado);
    }

    private function attemptConflict(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'attempt_conflict',
            'message' => 'Ese identificador de intento ya se usó para otra acción u otro recibo.',
        ], 409);
    }

    private function sinPersona(): JsonResponse
    {
        return $this->forbidden(null, 'Los recibos de caja exigen una sesión de administrador.');
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'not_found', 'message' => $message], 404);
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
