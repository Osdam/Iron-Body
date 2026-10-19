<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioLabScenario;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabScenarios;
use App\Support\Access\AuthorizationMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO · laboratorio: escenarios (contrato H4 §A2 y §A7, rutas 23 y
 * 28–30). Ver {@see StudioLabScenarios}.
 *
 * CERRADURAS, en este orden (§A7): la puerta ({@see AuthorizationMap},
 * `ultron.view` o `ultron.lab_manage`) → una persona ({@see StudioAccess::requirePerson()})
 * → la llave de la ruta → la bandera del laboratorio (409 `lab_disabled`).
 * Quien no tiene permiso recibe 403 aunque la bandera esté apagada.
 *
 * Validación en dos fases ({@see ValidatesListSizesFirst}) para `turns` y
 * `criteria`; y los códigos de `criteria.*.rules.*`, en una tercera, cuando
 * cada lista ya cabe en su tope (el rasgo no admite dos niveles de comodín).
 * Los textos se miden en caracteres; el filtro de datos personales y que las
 * reglas citadas existan los comprueba el servicio, con un 422 por campo.
 */
class UltronStudioLabScenarioController extends Controller
{
    use ValidatesListSizesFirst;

    public function __construct(private readonly StudioLabScenarios $scenarios) {}

    /** GET lab/scenarios?status=active|archived&category= — lista sin paginar. */
    public function index(Request $request): JsonResponse
    {
        $this->open($request, StudioAccess::VIEW);
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(StudioLabScenario::STATUSES)],
            'category' => ['sometimes', 'nullable', Rule::in(StudioLabCatalog::CATEGORIES)],
        ]);

        return response()->json(['ok' => true, 'data' => $this->scenarios->list($data['status'] ?? null, $data['category'] ?? null)]);
    }

    /**
     * POST lab/scenarios   {category, title, description?, turns, facts?, criteria, clientRequestId}
     *
     * 201 si se creó; 200 con `replayed` si esta misma petición ya se había atendido.
     */
    public function store(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioLabScenarios::LAB_MANAGE);
        $data = $this->validated($request, partial: false, extra: ['clientRequestId' => ['required', 'uuid']]);

        $resultado = $this->scenarios->create($access, Arr::only($data, StudioLabScenarios::EDITABLE), strtolower($data['clientRequestId']));

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $resultado['scenario']]);
        }

        return response()->json(['ok' => true, 'data' => $resultado['scenario']], 201);
    }

    /** PATCH lab/scenarios/{uuid}   {baseRevision, …solo los campos que cambian} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioLabScenarios::LAB_MANAGE);
        $data = $this->validated($request, partial: true, extra: ['baseRevision' => ['required', 'integer:strict', 'min:1', 'max:2147483647']]);

        $escenario = $this->scenarios->findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => $this->scenarios->update(
            $access,
            $escenario,
            $data['baseRevision'],
            Arr::only($data, StudioLabScenarios::EDITABLE),
        )]);
    }

    /** POST lab/scenarios/{uuid}/archive   {reason, baseRevision?} */
    public function archive(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioLabScenarios::LAB_MANAGE);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(multiline: true)],
            'baseRevision' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:2147483647'],
        ]);

        $escenario = $this->scenarios->findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => $this->scenarios->archive($access, $escenario, $data['reason'], $data['baseRevision'] ?? null)]);
    }

    /**
     * Las tres cerraduras de dentro, en el orden del contrato: persona, llave
     * y bandera.
     */
    private function open(Request $request, string $permission): StudioAccess
    {
        $access = StudioAccess::requirePerson($request);
        $access->require($permission);
        StudioLabRuns::assertEnabled();

        return $access;
    }

    /**
     * La FORMA de un escenario. Con `$partial`, cada campo es opcional (PATCH).
     *
     * @param  array<string, list<mixed>>  $extra
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial, array $extra): array
    {
        $siLlega = $partial ? ['sometimes', 'required'] : ['required'];
        $data = $this->validateListSizesFirst($request, $extra + [
            'category' => [...$siLlega, 'string', Rule::in(StudioLabCatalog::CATEGORIES)],
            'title' => [...$siLlega, 'string', 'min:'.StudioLabCatalog::TITLE_MIN, 'max:'.StudioLabCatalog::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.StudioLabCatalog::DESCRIPTION_MAX],
            'turns' => [...$siLlega, 'array', 'list', 'min:'.StudioLabCatalog::TURNS_MIN, 'max:'.StudioLabCatalog::TURNS_MAX],
            'turns.*' => ['array:role,text'],
            'turns.*.role' => ['required', 'string', Rule::in(StudioLabCatalog::TURN_ROLES)],
            'turns.*.text' => ['required', 'string', 'min:'.StudioLabCatalog::TURN_TEXT_MIN, 'max:'.StudioLabCatalog::TURN_TEXT_MAX],
            'facts' => ['sometimes', 'nullable', 'array:paymentState,handoffRequested'],
            'facts.paymentState' => ['sometimes', 'string', Rule::in(StudioLabCatalog::PAYMENT_STATES)],
            'facts.handoffRequested' => ['sometimes', 'boolean:strict'],
            'criteria' => [...$siLlega, 'array', 'list', 'min:'.StudioLabCatalog::CRITERIA_MIN, 'max:'.StudioLabCatalog::CRITERIA_MAX],
            'criteria.*' => ['array:kind,text,rules'],
            'criteria.*.kind' => ['required', 'string', Rule::in(StudioLabCatalog::CRITERION_KINDS)],
            'criteria.*.text' => ['required', 'string', 'min:'.StudioLabCatalog::CRITERION_TEXT_MIN, 'max:'.StudioLabCatalog::CRITERION_TEXT_MAX],
            'criteria.*.rules' => ['sometimes', 'array', 'list', 'max:'.StudioLabCatalog::RULE_REFS_MAX],
        ]);

        // Tercera fase: cada lista de reglas ya cabe en su tope (8 × 5 como mucho).
        if (isset($data['criteria'])) {
            $request->validate(['criteria.*.rules.*' => ['string', 'max:16', 'regex:/^(R-\d{2,}|S-\d{3,})$/']]);
        }

        return $data;
    }
}
