<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PlanTransferException;
use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Services\Membership\PlanTransferEvaluator;
use App\Services\Membership\PlanTransfers;
use App\Support\Access\AdminActor;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TRASLADAR SOCIOS ENTRE PLANES desde el CRM. Ver {@see PlanTransfers}.
 *
 * Las siete rutas exigen `plans.transfer`, declarado ruta por ruta en
 * AuthorizationMap::OVERRIDES y NO por controlador: por controlador, las
 * lecturas resolverían a `plans.view`, que tiene recepción y que el token de
 * automatizaciones obtiene por ser lectura. Aquí hasta leer la lista de
 * candidatos es parte de decidir un traslado.
 *
 * Y además una PERSONA. El traslado queda firmado por quien lo hizo, y una
 * credencial sin nadie detrás no puede firmar: se rechaza aquí aunque algún
 * día alguien le concediera el permiso por error.
 *
 * Ningún importe ni ningún «a quién» decide el navegador: los socios se eligen
 * por id, y todo lo demás —quién puede, qué cambia, qué se escribe— lo calcula
 * el servidor sobre la base.
 */
class PlanTransferController extends Controller
{
    use ResolvesPagination;

    /** Más que esto en una sola operación ya no se revisa de verdad. */
    public const MAX_MEMBERS = 300;

    /** GET /api/admin/plan-transfers/candidates?from_plan_id&to_plan_id */
    public function candidates(Request $request, PlanTransferEvaluator $evaluator): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate($this->planRules());

        try {
            return response()->json($evaluator->candidates(
                Plan::findOrFail($data['from_plan_id']),
                Plan::findOrFail($data['to_plan_id']),
            ));
        } catch (PlanTransferException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/admin/plan-transfers/dry-run — no escribe nada. */
    public function dryRun(Request $request, PlanTransferEvaluator $evaluator): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $this->guardSize($request);
        $data = $request->validate($this->planRules() + $this->memberRules(true));

        try {
            return response()->json($evaluator->simulate(
                Plan::findOrFail($data['from_plan_id']),
                Plan::findOrFail($data['to_plan_id']),
                array_map('intval', $data['user_ids']),
            ));
        } catch (PlanTransferException $e) {
            return $this->error($e);
        }
    }

    /** GET /api/admin/plan-transfers — historial, del más reciente al más viejo. */
    public function index(Request $request, PlanTransfers $transfers): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'kind' => ['sometimes', 'nullable', Rule::in([PlanTransferBatch::KIND_TRANSFER, PlanTransferBatch::KIND_REVERSAL])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $consulta = PlanTransferBatch::query()
            ->withCount(['items as reverted_count' => fn ($q) => $q->where('status', PlanTransferItem::STATUS_REVERTED)])
            ->orderByDesc('id');
        if (! empty($data['plan_id'])) {
            $plan = (int) $data['plan_id'];
            $consulta->where(fn ($q) => $q->where('from_plan_id', $plan)->orWhere('to_plan_id', $plan));
        }
        if (! empty($data['kind'])) {
            $consulta->where('kind', $data['kind']);
        }

        return response()->json(
            $consulta->paginate($this->resolvePerPage($request))
                ->through(fn (PlanTransferBatch $lote): array => $transfers->row($lote)),
        );
    }

    /**
     * POST /api/admin/plan-transfers — ejecuta lo que se simuló.
     *
     * 201 si se trasladó; 200 con `replayed` si esta misma operación ya se
     * había atendido (doble clic, reintento de red).
     */
    public function store(Request $request, PlanTransfers $transfers): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $this->guardSize($request);
        $data = $request->validate($this->planRules() + $this->memberRules(true) + $this->confirmationRules());

        try {
            $resultado = $transfers->execute(
                $request,
                (int) $data['from_plan_id'],
                (int) $data['to_plan_id'],
                array_map('intval', $data['user_ids']),
                $data['fingerprint'],
                $data['reason'],
                $data['client_request_id'],
                (bool) ($data['confirm_warnings'] ?? false),
            );
        } catch (PlanTransferException $e) {
            return $this->error($e);
        }

        return $this->executed($resultado, $transfers);
    }

    /** GET /api/admin/plan-transfers/{batch} */
    public function show(Request $request, PlanTransferBatch $batch, PlanTransfers $transfers): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        return response()->json($transfers->detail($batch));
    }

    /** POST /api/admin/plan-transfers/{batch}/revert/dry-run — no escribe nada. */
    public function revertDryRun(Request $request, PlanTransferBatch $batch, PlanTransfers $transfers): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $this->guardSize($request);
        $data = $request->validate($this->memberRules(false));

        try {
            return response()->json($transfers->simulateRevert($batch, $this->ids($data)));
        } catch (PlanTransferException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/admin/plan-transfers/{batch}/revert — mismo contrato que ejecutar. */
    public function revert(Request $request, PlanTransferBatch $batch, PlanTransfers $transfers): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $this->guardSize($request);
        $data = $request->validate($this->memberRules(false) + $this->confirmationRules());

        try {
            $resultado = $transfers->revert(
                $request,
                $batch,
                $this->ids($data),
                $data['fingerprint'],
                $data['reason'],
                $data['client_request_id'],
                (bool) ($data['confirm_warnings'] ?? false),
            );
        } catch (PlanTransferException $e) {
            return $this->error($e);
        }

        return $this->executed($resultado, $transfers);
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Sin persona no hay traslado. La puerta ya lo impide —el token
     * compartido no tiene `plans.transfer`—; esto es la segunda cerradura.
     *
     * Y tampoco una cuenta de ENTRENADOR, aunque alguien le conceda el
     * permiso desde Roles: su alcance son los socios que tiene asignados, y un
     * traslado (su detalle, su reversión) habla de socios de todo el gimnasio.
     * El scope global del modelo no basta aquí: los lotes guardan nombres y
     * fotos de socios que no pasan por `User`.
     */
    private function requirePerson(Request $request): ?JsonResponse
    {
        if (AdminActor::from($request) === null) {
            return response()->json([
                'ok' => false,
                'code' => 'person_required',
                'message' => 'Un traslado de plan lo firma una persona. Entra con tu cuenta.',
            ], 403);
        }

        if (app(TrainerMemberScope::class)->applies()) {
            return response()->json([
                'ok' => false,
                'code' => 'trainer_scope',
                'message' => 'Una cuenta de entrenador solo trabaja con sus socios asignados: no puede trasladar socios entre planes.',
            ], 403);
        }

        return null;
    }

    /** @return array<string, list<mixed>> */
    private function planRules(): array
    {
        return [
            'from_plan_id' => ['required', 'integer', 'exists:plans,id'],
            'to_plan_id' => ['required', 'integer', 'exists:plans,id'],
        ];
    }

    /**
     * El tope se mira ANTES de validar el contenido: `distinct` compara cada
     * id con todos los demás, y una lista de 20.000 tardaba segundos en
     * rechazarse. Con más de los que caben no hay nada que validar.
     *
     * @throws ValidationException
     */
    private function guardSize(Request $request): void
    {
        $ids = $request->input('user_ids');

        if (is_array($ids) && count($ids) > self::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'user_ids' => ['Como mucho '.self::MAX_MEMBERS.' socios por operación. Divide el traslado en varios lotes.'],
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    private function memberRules(bool $required): array
    {
        return [
            // `list`: un objeto {"a": 5} no es una lista de socios.
            'user_ids' => [$required ? 'required' : 'sometimes', 'array', 'list', 'min:1', 'max:'.self::MAX_MEMBERS],
            // Repetidos se rechazan: un id dos veces es un error de quien arma
            // la lista, y aplicarlo «una sola vez» lo escondería.
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function confirmationRules(): array
    {
        return [
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'client_request_id' => ['required', 'string', 'min:8', 'max:100'],
            'confirm_warnings' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private function ids(array $data): ?array
    {
        return isset($data['user_ids']) ? array_map('intval', $data['user_ids']) : null;
    }

    /** @param  array{batch: PlanTransferBatch, replayed: bool}  $resultado */
    private function executed(array $resultado, PlanTransfers $transfers): JsonResponse
    {
        $detalle = $transfers->detail($resultado['batch']->fresh());

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'batch' => $detalle]);
        }

        return response()->json(['ok' => true, 'batch' => $detalle], 201);
    }

    private function error(PlanTransferException $e): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => false,
            'code' => $e->code_,
            'message' => $e->getMessage(),
            'simulation' => $e->simulation,
        ], fn ($v) => $v !== null), $e->status);
    }
}
