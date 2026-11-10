<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Rules\PlainText;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategyConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: las PLANTILLAS DE MENSAJE versionadas de las estrategias
 * (contrato H9, D9-6, §7 #21–#25). Crear la versión siguiente de una clave,
 * listarlas, leerlas (con la referencia `{uuid, hash}` para instanciarlas en
 * un paso y su vista previa) y aprobarlas o rechazarlas a cuatro ojos POR
 * PERSONA. Mismas cerraduras que {@see UltronStudioStrategyController}.
 */
class UltronStudioStrategyTemplateController extends Controller
{
    use ResolvesPagination;

    /** GET strategies/templates?key&channel&status&page&per_page (≤ 50): sin el texto */
    public function index(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'key' => ['sometimes', 'nullable', 'string', 'max:48', 'regex:'.StudioMessageTemplates::KEY_PATTERN],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(StudioMessageTemplates::CHANNELS)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(StudioMessageTemplate::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(['ok' => true, 'data' => StudioMessageTemplates::page(
            $data['key'] ?? null, $data['channel'] ?? null, $data['status'] ?? null,
            $this->resolvePerPage($request, 20, StudioMessageTemplates::PER_PAGE_MAX),
        )]);
    }

    /** GET strategies/templates/{uuid}: contenido, vista previa, restricciones y acciones */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioMessageTemplates::detailDto(StudioMessageTemplates::findByUuid($uuid), $access)]);
    }

    /**
     * POST strategies/templates   {clientRequestId, key, channel, purpose, content}:
     * la versión siguiente de la clave, en DRAFT. 201; 200 `replayed` si se repite.
     */
    public function store(Request $request, StudioMessageTemplates $templates, StudioPersonBindings $persons): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'key' => ['required', 'string', 'max:48', 'regex:'.StudioMessageTemplates::KEY_PATTERN],
            'channel' => ['required', 'string', Rule::in(StudioMessageTemplates::CHANNELS)],
            'purpose' => ['required', 'string', Rule::in(OutreachCatalog::PURPOSES)],
            'content' => ['required', 'array'],
        ]);
        $r = $templates->create($access, $request, $persons, $data['clientRequestId'], $data['key'], $data['channel'], $data['purpose'], $request->input('content'));
        $dto = StudioMessageTemplates::detailDto($r['template'], $access);

        return $r['replayed'] ? response()->json(['ok' => true, 'replayed' => true, 'data' => $dto]) : response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /** POST strategies/templates/{uuid}/approve   {note?}: otra persona verificada */
    public function approve(Request $request, StudioMessageTemplates $templates, StudioPersonBindings $persons, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioMessageTemplates::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioMessageTemplates::detailDto($templates->approve($access, $request, $persons, $uuid, $data['note'] ?? null), $access)]);
    }

    /** POST strategies/templates/{uuid}/reject   {note 5–500}: otra persona verificada, con motivo */
    public function reject(Request $request, StudioMessageTemplates $templates, StudioPersonBindings $persons, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'note' => ['required', 'string', 'min:'.StudioMessageTemplates::NOTE_MIN, 'max:'.StudioMessageTemplates::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioMessageTemplates::detailDto($templates->reject($access, $request, $persons, $uuid, $data['note']), $access)]);
    }
}
