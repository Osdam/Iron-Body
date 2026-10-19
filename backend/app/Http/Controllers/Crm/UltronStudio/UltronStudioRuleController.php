<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Models\MarketingKnowledgeItem;
use App\Models\UltronStudio\StudioRule;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioRuleCoherence;
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
 *
 * `conditions` (Hito G, contrato G §A6): aquí solo se valida su FORMA (un
 * objeto con `when` y `then`, listas de objetos y sus tamaños). La semántica
 * (catálogo, valores, imposibles, controles del motor y capas) la hace
 * {@see StudioRuleCoherence} en el servicio, con un 422 que trae todas las
 * claves a la vez; la contradicción con otra regla es un 409
 * `rule_contradiction`.
 *
 * Las listas con reglas por elemento (`layers.*`, `refs.*`, `conditions.*`)
 * se validan en dos fases ({@see ValidatesListSizesFirst}): primero su forma y
 * su tope, después cada elemento. Una lista enorme se rechaza sin recorrerla
 * (revisión de seguridad de la API del Hito G, M-2).
 */
class UltronStudioRuleController extends Controller
{
    use ValidatesListSizesFirst;

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
     * POST /api/admin/ultron-studio/rules   {description, type, priority, layers, refs?, conditions?, clientRequestId}
     *
     * 201 si se creó; 200 con `replayed` si esta misma petición ya se había atendido.
     */
    public function store(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $this->validateListSizesFirst($request, [
            'description' => ['required', 'string', 'max:500', new PlainText(multiline: true)],
            'type' => ['required', Rule::in(StudioRule::TYPES)],
            'priority' => ['required', Rule::in(StudioRule::PRIORITIES)],
            'layers' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'layers.*' => ['string', 'distinct', Rule::in(StudioCatalog::layerIds())],
            'clientRequestId' => ['required', 'uuid'],
        ] + self::refsRules() + self::conditionsRules());

        $resultado = $this->rules->create(
            $access,
            $request,
            self::withRawConditions($request, Arr::only($data, ['description', 'type', 'priority', 'layers', 'refs', 'conditions'])),
            strtolower($data['clientRequestId']),
        );

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $resultado['rule']]);
        }

        return response()->json(['ok' => true, 'data' => $resultado['rule']], 201);
    }

    /**
     * PATCH /api/admin/ultron-studio/rules/{uuid}   {baseRevision, …solo los campos que cambian}
     *
     * `conditions: null` las quita (la regla pasa a documental); sin la clave, no cambian.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $this->validateListSizesFirst($request, [
            'baseRevision' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
            'description' => ['sometimes', 'required', 'string', 'max:500', new PlainText(multiline: true)],
            'type' => ['sometimes', 'required', Rule::in(StudioRule::TYPES)],
            'priority' => ['sometimes', 'required', Rule::in(StudioRule::PRIORITIES)],
            'layers' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:10'],
            'layers.*' => ['string', 'distinct', Rule::in(StudioCatalog::layerIds())],
        ] + self::refsRules() + self::conditionsRules());

        $regla = $this->rules->findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => $this->rules->update(
            $access,
            $request,
            $regla,
            $data['baseRevision'],
            self::withRawConditions($request, Arr::only($data, StudioRules::EDITABLE)),
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

    /**
     * La FORMA de `conditions` (contrato G §A6), con las mismas claves de error
     * que da la canonización para esos mismos fallos. Que falten `when` o
     * `then`, y todo lo demás, lo dice {@see StudioRuleCoherence::check()}.
     *
     * @return array<string, list<mixed>>
     */
    private static function conditionsRules(): array
    {
        return [
            'conditions' => ['sometimes', 'nullable', 'array:when,then'],
            'conditions.when' => ['sometimes', 'array', 'list', 'max:'.StudioRuleCoherence::MAX_WHEN],
            'conditions.when.*' => ['array'],
            'conditions.then' => ['sometimes', 'array', 'list', 'min:1', 'max:'.StudioRuleCoherence::MAX_THEN],
            'conditions.then.*' => ['array'],
        ];
    }

    /**
     * `conditions` TAL COMO LLEGÓ, si llegó. Los middleware globales recortan
     * los textos y convierten "" en null también dentro de un JSON anidado, y la
     * canonización es la validación del borde de las condiciones (como el
     * normalizador lo es del contenido del borrador, ver
     * {@see UltronStudioController}): tiene que ver lo mismo que el CRM, que
     * recorta con el conjunto de espacios de JavaScript. La forma ya se validó.
     *
     * @param  array<string, mixed>  $data  lo validado
     * @return array<string, mixed>
     */
    private static function withRawConditions(Request $request, array $data): array
    {
        if (! array_key_exists('conditions', $data) || ! $request->isJson()) {
            return $data;
        }

        $cuerpo = json_decode((string) $request->getContent(), true);
        if (is_array($cuerpo) && array_key_exists('conditions', $cuerpo)) {
            $data['conditions'] = $cuerpo['conditions'];
        }

        return $data;
    }
}
