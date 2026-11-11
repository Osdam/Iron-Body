<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategySimulation;
use App\Rules\PlainText;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyConfig;
use App\Services\UltronStudio\StudioStrategyEvidence;
use App\Services\UltronStudio\StudioStrategyPublications;
use App\Services\UltronStudio\StudioStrategyPublishing;
use App\Services\UltronStudio\StudioStrategySimulations;
use App\Services\UltronStudio\StudioStrategyValidator;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: el GOBIERNO DE LAS ESTRATEGIAS (contrato H9, D9-6, D9-11,
 * D9-12, D9-13, §7). Estrategias y borrador, validación, versiones,
 * simulaciones, revisión a cuatro ojos POR PERSONA, la cadena firmada
 * (publicar, promover, pausar, reanudar, revertir), la evidencia del canario
 * y el archivo. Ver {@see UltronStudioController} para las puertas comunes.
 *
 * LAS CERRADURAS: la puerta (`AuthorizationMap`, una a una: {@see ROUTES}) →
 * una persona (`StudioAccess::requirePerson`, que exige `ultron.view`) → la
 * bandera maestra (`ULTRON_STUDIO_STRATEGIES_ENABLED`, apagada: 409
 * `strategies_disabled` en TODA ruta, también las lecturas) → la llave en el
 * servicio → la persona VERIFICADA y los cuatro ojos por persona → la bandera
 * de publicar (publicar, promover y reanudar). CERO RED: nada de aquí sale a
 * la red. Ninguna respuesta lleva teléfonos, nombres de personas del CRM ni
 * textos de mensajes de nadie: las personas de una simulación real van por su
 * `subjectRef`.
 */
class UltronStudioStrategyController extends Controller
{
    use ResolvesPagination;

    /** Las rutas del Studio antes del Hito 9 (F, G, H4, H5, H6 y H8). */
    public const PREVIOUS_STUDIO_ROUTES = 77;

    /**
     * Las rutas del gobierno de estrategias (contrato H9, §7, fijadas por S9),
     * con su permiso, una a una: la lista que `AuthorizationMap` (B9-run,
     * «Traspasos») tiene que clasificar y que las guardas leen en lugar de un
     * recuento literal (E2-6 del H8). Todas bajo `admin/ultron-studio/`.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const ROUTES = [
        ['GET', 'strategies/catalog', StudioAccess::VIEW],
        ['GET', 'strategies', StudioAccess::VIEW],
        ['POST', 'strategies', StudioStrategyConfig::EDIT],
        ['GET', 'strategies/{uuid}', StudioAccess::VIEW],
        ['PUT', 'strategies/{uuid}/draft', StudioStrategyConfig::EDIT],
        ['POST', 'strategies/{uuid}/validate', StudioStrategyConfig::EDIT],
        ['POST', 'strategies/{uuid}/versions', StudioStrategyConfig::EDIT],
        ['POST', 'strategies/{uuid}/simulations', StudioStrategyConfig::EDIT],
        ['GET', 'strategies/versions/{uuid}', StudioAccess::VIEW],
        ['POST', 'strategies/versions/{uuid}/simulations', StudioStrategyConfig::EDIT],
        ['GET', 'strategies/versions/{uuid}/simulations', StudioAccess::VIEW],
        ['POST', 'strategies/versions/{uuid}/approve', StudioAccess::APPROVE],
        ['POST', 'strategies/versions/{uuid}/reject', StudioAccess::APPROVE],
        ['POST', 'strategies/versions/{uuid}/publish', StudioStrategyConfig::PUBLISH],
        ['POST', 'strategies/versions/{uuid}/promote', StudioStrategyConfig::PUBLISH],
        ['GET', 'strategies/versions/{uuid}/canary-evidence', StudioAccess::VIEW],
        ['POST', 'strategies/{uuid}/pause', StudioStrategyConfig::ROLLBACK],
        ['POST', 'strategies/{uuid}/resume', StudioStrategyConfig::PUBLISH],
        ['POST', 'strategies/{uuid}/rollback', StudioStrategyConfig::ROLLBACK],
        ['GET', 'strategies/{uuid}/publications', StudioAccess::VIEW],
        ['POST', 'strategies/{uuid}/archive', StudioStrategyConfig::EDIT],
        ['GET', 'strategies/templates', StudioAccess::VIEW],
        ['POST', 'strategies/templates', StudioStrategyConfig::EDIT],
        ['GET', 'strategies/templates/{uuid}', StudioAccess::VIEW],
        ['POST', 'strategies/templates/{uuid}/approve', StudioAccess::APPROVE],
        ['POST', 'strategies/templates/{uuid}/reject', StudioAccess::APPROVE],
        ['GET', 'strategies/person-bindings', StudioStrategyConfig::PERSON_BINDINGS],
        ['POST', 'strategies/person-bindings', StudioStrategyConfig::PERSON_BINDINGS],
        ['POST', 'strategies/person-bindings/{uuid}/verify', StudioStrategyConfig::PERSON_BINDINGS],
        ['POST', 'strategies/person-bindings/{uuid}/revoke', StudioStrategyConfig::PERSON_BINDINGS],
    ];

    /** Las que además del cupo llevan el limitador de las lecturas caras (`ultron-studio-evidence`). */
    public const EXPENSIVE_ROUTES = [
        'POST strategies/{uuid}/simulations',
        'POST strategies/versions/{uuid}/simulations',
        'GET strategies/versions/{uuid}/canary-evidence',
        'POST strategies/versions/{uuid}/promote',
    ];

    /** Todas las rutas del Studio con el Hito 9 (77 + 30). Una prueba lo ata a las listas y a las rutas reales. */
    public const STUDIO_ROUTE_COUNT_WITH_STRATEGIES = self::PREVIOUS_STUDIO_ROUTES + 30;

    private const SHA256 = '/^[0-9a-f]{64}$/D';

    // ── Catálogo y lecturas ─────────────────────────────────────────────────

    /** GET strategies/catalog: eventos, hechos, operadores, canales, resultados, transiciones y límites, con su versión y su huella; marcadores y textos */
    public function catalog(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => [
            'catalog' => OutreachCatalog::export(),
            'catalogHash' => OutreachCatalog::hash(),
            'dynamicDomains' => (new CatalogDomains)->read(),
            'contentFormat' => StrategyContent::FORMAT,
            'limits' => StrategyContent::LIMITS,
            'limitsOptional' => StrategyContent::LIMITS_OPTIONAL,
            'templates' => [
                'channels' => StudioMessageTemplates::CHANNELS,
                'languages' => StudioMessageTemplates::LANGUAGES,
                'categoryByPurpose' => StudioMessageTemplates::CATEGORY_BY_PURPOSE,
                'markers' => StudioMessageTemplates::MARKERS,
                'markersForbiddenByPurpose' => StudioMessageTemplates::MARKERS_FORBIDDEN_BY_PURPOSE,
                'bodyMax' => StudioMessageTemplates::BODY_MAX,
                'scriptMax' => StudioMessageTemplates::SCRIPT_MAX,
                // Aditivo (plantillas de Meta, §5.1): el contenido ampliado de WhatsApp.
                'meta' => StudioMessageTemplates::metaCatalog(),
            ],
            'traceText' => DecisionTraceText::export(),
            'studioBaseline' => ['catalogVersion' => StudioCatalog::VERSION, 'catalogHash' => StudioCatalog::hash()],
            'governance' => StudioStrategyConfig::status(now()),
            'can' => StudioStrategyConfig::capabilities($access),
            'person' => ['bound' => StudioPersonBindings::personOf($access->id()) !== null],
        ]]);
    }

    /** GET strategies?purpose&archived&page&per_page (≤ 50): sin el borrador; con el estado de gobierno y el de la cadena */
    public function index(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'purpose' => ['sometimes', 'nullable', 'string', Rule::in(OutreachCatalog::PURPOSES)],
            'archived' => ['sometimes', 'boolean'],
        ] + self::pagination());

        return response()->json(['ok' => true, 'data' => StudioStrategies::page(
            $data['purpose'] ?? null,
            (bool) ($data['archived'] ?? false),
            $this->resolvePerPage($request, 20, StudioStrategies::PER_PAGE_MAX),
        ), 'meta' => ['governance' => StudioStrategyConfig::status(now()), 'can' => StudioStrategyConfig::capabilities($access),
            'person' => ['bound' => StudioPersonBindings::personOf($access->id()) !== null]]]);
    }

    /** GET strategies/{uuid}: el borrador, las versiones, la cadena y las acciones */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioStrategies::detailDto(StudioStrategies::findByUuid($uuid), $access)]);
    }

    // ── Estrategias y borrador ──────────────────────────────────────────────

    /** POST strategies   {clientRequestId, key, name 3–80, purpose, draft?}: 201; 200 `replayed` si se repite */
    public function store(Request $request, StudioStrategies $strategies): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'key' => ['required', 'string', 'max:48', 'regex:'.OutreachCatalog::STRATEGY_KEY_PATTERN],
            'name' => ['required', 'string', 'min:3', 'max:'.StudioStrategies::NAME_MAX, new PlainText(strict: true), StudioCanaryPhone::contactDataRule()],
            'purpose' => ['required', 'string', Rule::in(OutreachCatalog::PURPOSES)],
            'draft' => ['sometimes', 'nullable', 'array'],
        ]);
        $r = $strategies->create($access, $request, $data['clientRequestId'], $data['key'], $data['name'], $data['purpose'], $request->input('draft'));
        $dto = StudioStrategies::detailDto($r['strategy'], $access);

        return $r['replayed'] ? response()->json(['ok' => true, 'replayed' => true, 'data' => $dto]) : response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /** PUT strategies/{uuid}/draft   {baseRevision, draft}: 409 `draft_moved` si otra pestaña lo cambió */
    public function saveDraft(Request $request, StudioStrategies $strategies, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'baseRevision' => ['required', 'integer', 'min:0'],
            'draft' => ['required', 'array'],
        ]);
        $s = $strategies->saveDraft($access, $request, $uuid, (int) $data['baseRevision'], (array) $request->input('draft'));

        return response()->json(['ok' => true, 'data' => ['strategy' => StudioStrategies::summaryDto($s), 'draft' => StudioStrategies::draftApiDto($s)]]);
    }

    /**
     * POST strategies/{uuid}/validate   {content?}: PURA, sin escritura. Sin
     * `content`, el borrador guardado. Siempre 200 con errores, bloqueantes y avisos.
     */
    public function validateDraft(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $access->require(StudioStrategyConfig::EDIT);
        $request->validate(['content' => ['sometimes', 'nullable', 'array']]);
        $s = StudioStrategies::findByUuid($uuid);
        $contenido = $request->has('content') ? $request->input('content') : StudioStrategies::draftDto($s)['content'];
        $r = StudioStrategyValidator::validate($contenido, StudioStrategyValidator::context(), (string) $s->purpose);

        return response()->json(['ok' => true, 'data' => StudioStrategyValidator::record($r) + ['canonical' => $r['canonical']]]);
    }

    /** POST strategies/{uuid}/archive: solo sin versiones en canario ni publicadas */
    public function archive(Request $request, StudioStrategies $strategies, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioStrategies::detailDto($strategies->archive($access, $request, $uuid), $access)]);
    }

    // ── Versiones ───────────────────────────────────────────────────────────

    /** POST strategies/{uuid}/versions   {clientRequestId}: congela el borrador; 422 con todas las claves */
    public function freeze(Request $request, StudioStrategyVersions $versions, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate(['clientRequestId' => ['required', 'uuid']]);
        $r = $versions->freeze($access, $request, $uuid, $data['clientRequestId']);
        $dto = StudioStrategyVersions::detailDto($r['version'], $access);

        return $r['replayed'] ? response()->json(['ok' => true, 'replayed' => true, 'data' => $dto]) : response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /** GET strategies/versions/{uuid}: contenido, diferencias, validación, simulaciones, reseña y acciones */
    public function showVersion(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioStrategyVersions::detailDto(StudioStrategyVersions::findByUuid($uuid), $access)]);
    }

    /** POST strategies/versions/{uuid}/approve   {acknowledgedWarnings[], note?}: otra persona verificada */
    public function approve(Request $request, StudioStrategyVersions $versions, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'acknowledgedWarnings' => ['present', 'array', 'list', 'max:'.StudioStrategyVersions::MAX_WARNINGS],
            'acknowledgedWarnings.*' => ['string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioStrategyVersions::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);
        $v = $versions->approve($access, $request, $uuid, $data['acknowledgedWarnings'], $data['note'] ?? null);

        return response()->json(['ok' => true, 'data' => StudioStrategyVersions::detailDto($v, $access)]);
    }

    /** POST strategies/versions/{uuid}/reject   {note 5–500}: otra persona verificada, con motivo */
    public function reject(Request $request, StudioStrategyVersions $versions, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'note' => ['required', 'string', 'min:'.StudioStrategyVersions::NOTE_MIN, 'max:'.StudioStrategyVersions::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioStrategyVersions::detailDto($versions->reject($access, $request, $uuid, $data['note']), $access)]);
    }

    // ── Simulaciones ────────────────────────────────────────────────────────

    /** POST strategies/versions/{uuid}/simulations   {kind: synthetic|sample, people?, at?, size?} */
    public function simulateVersion(Request $request, StudioStrategySimulations $simulations, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $this->simulationInput($request);
        $version = StudioStrategyVersions::findByUuid($uuid);
        $r = $simulations->simulate($access, $request, $version->strategy, $version, $data['kind'], $data);

        return response()->json(['ok' => true, 'data' => self::simulationDto($r)], 201);
    }

    /** POST strategies/{uuid}/simulations   {kind, people?, at?, size?}: la simulación del BORRADOR (iterativa) */
    public function simulateDraft(Request $request, StudioStrategySimulations $simulations, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $this->simulationInput($request);
        $r = $simulations->simulate($access, $request, StudioStrategies::findByUuid($uuid), null, $data['kind'], $data);

        return response()->json(['ok' => true, 'data' => self::simulationDto($r)], 201);
    }

    /** GET strategies/versions/{uuid}/simulations: las de la misma huella (≤ 50), sin trazas por persona */
    public function simulations(Request $request, string $uuid): JsonResponse
    {
        StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioStrategySimulations::forVersion(StudioStrategyVersions::findByUuid($uuid))]);
    }

    // ── La cadena ───────────────────────────────────────────────────────────

    /** POST strategies/versions/{uuid}/publish   {clientRequestId, expectedContentHash, note?} */
    public function publish(Request $request, StudioStrategyPublishing $publishing, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'expectedContentHash' => ['required', 'string', 'regex:'.self::SHA256],
            'note' => self::optionalNote(),
        ]);

        return self::written($publishing->publish($access, $request, $uuid, $data['clientRequestId'], $data['expectedContentHash'], $data['note'] ?? null));
    }

    /** POST strategies/versions/{uuid}/promote   {clientRequestId, evidenceHash, note?} */
    public function promote(Request $request, StudioStrategyPublishing $publishing, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'evidenceHash' => ['required', 'string', 'regex:'.self::SHA256],
            'note' => self::optionalNote(),
        ]);

        return self::written($publishing->promote($access, $request, $uuid, $data['clientRequestId'], $data['evidenceHash'], $data['note'] ?? null));
    }

    /** POST strategies/{uuid}/pause   {clientRequestId, reason 5–500}: siempre (el sentido seguro) */
    public function pause(Request $request, StudioStrategyPublishing $publishing, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate(['clientRequestId' => ['required', 'uuid'], 'reason' => self::requiredReason()]);

        return self::written($publishing->pause($access, $request, $uuid, $data['clientRequestId'], $data['reason']));
    }

    /** POST strategies/{uuid}/resume   {clientRequestId, note?}: con la bandera y la ventana de publicar */
    public function resume(Request $request, StudioStrategyPublishing $publishing, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate(['clientRequestId' => ['required', 'uuid'], 'note' => self::optionalNote()]);

        return self::written($publishing->resume($access, $request, $uuid, $data['clientRequestId'], $data['note'] ?? null));
    }

    /** POST strategies/{uuid}/rollback   {clientRequestId, reason 5–500, part?: canary|rest}: siempre */
    public function rollback(Request $request, StudioStrategyPublishing $publishing, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'reason' => self::requiredReason(),
            'part' => ['sometimes', 'nullable', 'string', Rule::in(StudioStrategyPublication::PARTS)],
        ]);

        return self::written($publishing->rollback($access, $request, $uuid, $data['clientRequestId'], $data['reason'], $data['part'] ?? null));
    }

    /** GET strategies/{uuid}/publications?page&per_page (≤ 50): cada entrada con su `verified` */
    public function publications(Request $request, string $uuid): JsonResponse
    {
        StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $request->validate(self::pagination());
        $s = StudioStrategies::findByUuid($uuid);

        return response()->json(['ok' => true, 'data' => StudioStrategyPublications::page($s, $this->resolvePerPage($request, 20, StudioStrategyPublications::PER_PAGE_MAX)),
            'meta' => ['state' => StudioStrategies::chainSummary(StudioStrategyPublications::stateOf($s))]]);
    }

    /** GET strategies/versions/{uuid}/canary-evidence: medida en el servidor, en solo lectura */
    public function evidence(Request $request, string $uuid): JsonResponse
    {
        StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioStrategyEvidence::measure(StudioStrategyVersions::findByUuid($uuid))['dto']]);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function simulationInput(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'string', Rule::in(StudioStrategySimulation::KINDS)],
            'people' => ['sometimes', 'array', 'list', 'max:'.StudioStrategySimulations::MAX_SYNTHETIC],
            'at' => ['sometimes', 'nullable', 'string', 'max:20'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:'.StudioStrategySimulations::MAX_SAMPLE],
        ]) + ['people' => $request->input('people')];
    }

    /**
     * @param  array{simulation: StudioStrategySimulation, people: list<array<string,mixed>>, sequence: list<array<string,mixed>>}  $r
     * @return array<string,mixed>
     */
    private static function simulationDto(array $r): array
    {
        return StudioStrategySimulations::summaryDto($r['simulation']) + ['people' => $r['people'], 'sequence' => $r['sequence']];
    }

    /** @param  array{entry: array<string,mixed>, replayed: bool}  $r */
    private static function written(array $r): JsonResponse
    {
        return $r['replayed']
            ? response()->json(['ok' => true, 'replayed' => true, 'data' => $r['entry']])
            : response()->json(['ok' => true, 'data' => $r['entry']], 201);
    }

    /** @return list<mixed> */
    private static function requiredReason(): array
    {
        return ['required', 'string', 'min:'.StudioStrategyPublishing::REASON_MIN, 'max:'.StudioStrategyPublishing::REASON_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()];
    }

    /** @return list<mixed> */
    private static function optionalNote(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:'.StudioStrategyPublishing::REASON_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()];
    }

    /** @return array<string, list<string>> */
    private static function pagination(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
