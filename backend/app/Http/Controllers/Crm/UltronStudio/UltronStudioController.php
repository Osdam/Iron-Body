<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioDrafts;
use App\Services\UltronStudio\StudioPublishing;
use App\Services\UltronStudio\StudioRules;
use App\Services\UltronStudio\StudioVersionStatus;
use App\Support\Access\AuthorizationMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: estado, catálogo, borrador y auditoría (contrato
 * docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md §A3, rutas 1–5 y 19).
 *
 * AUTORIZACIÓN EN DOS PUERTAS, igual en los tres controladores del Studio. El
 * mapa central ({@see AuthorizationMap::OVERRIDES}) exige el permiso `ultron.*`
 * de cada ruta, una a una, y este controlador NO está en CONTROLLERS: una ruta
 * nueva sin clasificar se deniega. Aquí se exige además una PERSONA
 * ({@see StudioAccess::requirePerson()}): el token compartido no firma nada.
 *
 * Todo en camelCase dentro de `{ok:true, data}`, salvo las claves del
 * paginador de Laravel. Los rechazos del dominio salen de
 * `UltronStudioException` (`{ok:false, code, message, …}`); la validación de
 * formato, con el 422 estándar de Laravel (`{message, errors}`).
 *
 * El motor de ULTRON no lee nada de esto.
 */
class UltronStudioController extends Controller
{
    use ResolvesPagination;

    public function __construct(
        private readonly StudioDrafts $drafts,
        private readonly StudioRules $rules,
        private readonly StudioAudit $audit,
    ) {}

    /** GET /api/admin/ultron-studio/status */
    public function status(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => [
            'engine' => StudioPublishing::engine(),
            'publishing' => StudioPublishing::status(),
            'can' => $access->capabilities(),
            'catalog' => ['version' => StudioCatalog::VERSION, 'hash' => StudioCatalog::hash()],
            'lifecycle' => StudioVersionStatus::lifecycle(),
            'legacyBaseline' => ['label' => StudioCatalog::BASELINE_LABEL, 'rulesImported' => $this->rules->legacyCount()],
        ]]);
    }

    /** GET /api/admin/ultron-studio/catalog — la autoridad: el CRM ya no lo empaqueta. */
    public function catalog(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => StudioCatalog::toArray()]);
    }

    /** GET /api/admin/ultron-studio/draft */
    public function draft(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $this->drafts->show($access)]);
    }

    /** PUT /api/admin/ultron-studio/draft   {baseRevision, content} */
    public function saveDraft(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'baseRevision' => ['required', 'integer:strict', 'min:0', 'max:2147483647'],
            'content' => ['required', 'array'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->drafts->save(
            $access,
            $request,
            $data['baseRevision'],
            $this->rawContent($request),
        )]);
    }

    /** POST /api/admin/ultron-studio/draft/discard   {baseRevision} — no borra la fila. */
    public function discardDraft(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'baseRevision' => ['required', 'integer:strict', 'min:0', 'max:2147483647'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->drafts->discard($access, $request, $data['baseRevision'])]);
    }

    /** GET /api/admin/ultron-studio/audit?subjectType&subjectUuid&event&page&per_page */
    public function audit(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'subjectType' => ['sometimes', 'nullable', Rule::in(StudioAuditEvent::SUBJECT_TYPES)],
            // Validado ANTES de consultar: en PostgreSQL un uuid mal formado
            // contra una columna uuid es un 500 (22P02).
            'subjectUuid' => ['sometimes', 'nullable', 'uuid'],
            'event' => ['sometimes', 'nullable', 'string', 'max:48', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $pagina = $this->audit->page($access, [
            'subjectType' => $data['subjectType'] ?? null,
            'subjectUuid' => isset($data['subjectUuid']) ? strtolower($data['subjectUuid']) : null,
            'event' => $data['event'] ?? null,
        ], $this->resolvePerPage($request));

        return response()->json(['ok' => true, 'data' => $pagina]);
    }

    /**
     * El contenido TAL COMO LLEGÓ. Los middleware globales recortan los textos
     * y convierten "" en null también dentro de un JSON anidado, y entonces una
     * etiqueta vacía llegaría como null: el normalizador, que es la validación
     * del borde del contenido, la rechazaría en lugar de descartarla como hace
     * el CRM. El formato ya se validó arriba; el contenido lo valida él.
     *
     * @return array<array-key, mixed>
     */
    private function rawContent(Request $request): array
    {
        if ($request->isJson()) {
            $cuerpo = json_decode((string) $request->getContent(), true);
            if (is_array($cuerpo) && is_array($cuerpo['content'] ?? null)) {
                return $cuerpo['content'];
            }
        }

        return (array) $request->input('content', []);
    }
}
