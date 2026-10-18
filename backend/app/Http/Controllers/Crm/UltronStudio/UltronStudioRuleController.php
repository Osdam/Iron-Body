<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Controller;
use App\Models\MarketingKnowledgeItem;
use App\Models\UltronStudio\StudioRule;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ULTRON STUDIO: reglas (contrato §A3, rutas 15–18). Ver {@see StudioRules} y,
 * para las dos puertas de autorización, {@see UltronStudioController}.
 *
 * Las del código (R-01…R-12) son de solo lectura: 409 `legacy_rule_read_only`.
 * Las del Studio nacen S-NNN en borrador y el motor no las aplica nunca.
 */
class UltronStudioRuleController extends Controller
{
    public function __construct(private readonly StudioRules $rules) {}

    /** GET /api/admin/ultron-studio/rules?status=active,draft (por defecto, sin archivadas) */
    public function index(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $data = $request->validate(['status' => ['sometimes', 'nullable', 'string', 'max:60']]);

        $estados = [];
        if (isset($data['status']) && trim($data['status']) !== '') {
            $estados = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['status'])), static fn (string $s): bool => $s !== '')));
            if (array_diff($estados, StudioRule::STATUSES) !== []) {
                throw ValidationException::withMessages(['status' => ['Hay estados de regla que no existen.']]);
            }
        }

        return response()->json(['ok' => true, 'data' => $this->rules->list($estados)]);
    }

    /**
     * POST /api/admin/ultron-studio/rules   {description, type, priority, layers, refs?, clientRequestId}
     *
     * 201 si se creó; 200 con `replayed` si esta misma petición ya se había atendido.
     */
    public function store(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:500', new PlainText(multiline: true)],
            'type' => ['required', Rule::in(StudioRule::TYPES)],
            'priority' => ['required', Rule::in(StudioRule::PRIORITIES)],
            'layers' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'layers.*' => ['string', 'distinct', Rule::in(StudioCatalog::layerIds())],
            'clientRequestId' => ['required', 'uuid'],
        ] + self::refsRules());

        $resultado = $this->rules->create(
            $access,
            $request,
            Arr::only($data, ['description', 'type', 'priority', 'layers', 'refs']),
            strtolower($data['clientRequestId']),
        );

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $resultado['rule']]);
        }

        return response()->json(['ok' => true, 'data' => $resultado['rule']], 201);
    }

    /** PATCH /api/admin/ultron-studio/rules/{uuid}   {baseRevision, …solo los campos que cambian} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'baseRevision' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
            'description' => ['sometimes', 'required', 'string', 'max:500', new PlainText(multiline: true)],
            'type' => ['sometimes', 'required', Rule::in(StudioRule::TYPES)],
            'priority' => ['sometimes', 'required', Rule::in(StudioRule::PRIORITIES)],
            'layers' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:10'],
            'layers.*' => ['string', 'distinct', Rule::in(StudioCatalog::layerIds())],
        ] + self::refsRules());

        $regla = $this->rules->findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => $this->rules->update(
            $access,
            $request,
            $regla,
            $data['baseRevision'],
            Arr::only($data, StudioRules::EDITABLE),
        )]);
    }

    /** POST /api/admin/ultron-studio/rules/{uuid}/archive   {baseRevision, reason} */
    public function archive(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'baseRevision' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(multiline: true)],
        ]);

        $regla = $this->rules->findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => $this->rules->archive($access, $request, $regla, $data['baseRevision'], $data['reason'])]);
    }

    /**
     * La FORMA de `refs`. Que cada parámetro exista en su capa y cada clave en
     * la base de conocimiento lo comprueba el servicio (también 422).
     *
     * @return array<string, list<mixed>>
     */
    private static function refsRules(): array
    {
        $max = StudioRules::MAX_REFS;

        return [
            'refs' => ['sometimes', 'nullable', 'array:configs,edges,knowledgeKeys,knowledgeCategories'],
            'refs.configs' => ['sometimes', 'array', 'list', "max:{$max}"],
            'refs.configs.*' => ['array:layer,key'],
            'refs.configs.*.layer' => ['required', 'string', Rule::in(StudioCatalog::layerIds())],
            'refs.configs.*.key' => ['required', 'string', 'max:60'],
            'refs.edges' => ['sometimes', 'array', 'list', "max:{$max}"],
            'refs.edges.*' => ['string', 'distinct', Rule::in(array_column(StudioCatalog::graph()['edges'], 'id'))],
            'refs.knowledgeKeys' => ['sometimes', 'array', 'list', "max:{$max}"],
            'refs.knowledgeKeys.*' => ['string', 'distinct', 'max:255', new PlainText],
            'refs.knowledgeCategories' => ['sometimes', 'array', 'list', "max:{$max}"],
            'refs.knowledgeCategories.*' => ['string', 'distinct', Rule::in(MarketingKnowledgeItem::CATEGORIES)],
        ];
    }
}
