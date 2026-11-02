<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioPublications;
use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVersionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ULTRON STUDIO: versiones (contrato §A2 y §A3, rutas 6–14). Ver
 * {@see StudioVersions} para la máquina de estados, los cuatro ojos y la
 * revalidación; y {@see UltronStudioController} para las dos puertas de
 * autorización, comunes a los tres controladores del Studio.
 *
 * Sin route-model binding: el uuid llega como texto (la ruta ya exige su
 * formato) y lo resuelve el servicio, con 404 `version_not_found`.
 *
 * Aprobar aquí no cambia al agente.
 *
 * Desde el Hito G, también la comparación (ruta 21, contrato G §A4).
 *
 * Desde el Hito 5 (contrato H5 §A5 y §A7), publicar en canario, promover y
 * revertir de verdad, más la vista previa y la evidencia del canario (rutas 13,
 * 14, 34, 35 y 36), con {@see StudioPublications}. Ese servicio lee el motor y
 * se resuelve en cada acción, no en el constructor: las demás rutas de
 * versiones no tocan ninguna clase del motor. Las cuatro escrituras responden
 * 201 con la entrada creada (`PublicationEntryDto`) y 200 `replayed` con la de
 * la primera vez si la petición se repite.
 */
class UltronStudioVersionController extends Controller
{
    use ResolvesPagination;
    use ValidatesListSizesFirst;

    /** Un lado de `GET compare`: `baseline`, `draft` o el uuid de una versión. */
    private const COMPARE_SIDE = '/^(baseline|draft|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/';

    /** `previewHash` y `evidenceHash`: sha256 en hexadecimal en minúsculas, como los calcula el servidor (se comparan exactos). */
    private const SHA256 = '/^[0-9a-f]{64}$/';

    public function __construct(private readonly StudioVersions $versions) {}

    /** GET /api/admin/ultron-studio/versions?status=A,B&scope=all|mine|reviewable&page&per_page */
    public function index(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:200'],
            'scope' => ['sometimes', 'nullable', 'in:all,mine,reviewable'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $estados = self::statuses($data['status'] ?? null);

        return response()->json(['ok' => true, 'data' => $this->versions->page(
            $access,
            $estados,
            $data['scope'] ?? 'all',
            $this->resolvePerPage($request),
        )]);
    }

    /**
     * POST /api/admin/ultron-studio/versions   {label, note, draftRevision, clientRequestId}
     *
     * 201 si se creó; 200 con `replayed` si esta misma petición ya se había
     * atendido (doble clic, reintento de red).
     */
    public function store(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80', new PlainText(strict: true)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500', new PlainText(multiline: true, strict: true)],
            'draftRevision' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
            'clientRequestId' => ['required', 'uuid'],
        ]);

        $resultado = $this->versions->create(
            $access,
            $request,
            $data['label'],
            $data['note'] ?? null,
            $data['draftRevision'],
            strtolower($data['clientRequestId']),
        );
        $detalle = $this->versions->detail($access, $resultado['version']);

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $detalle]);
        }

        return response()->json(['ok' => true, 'data' => $detalle], 201);
    }

    /** GET /api/admin/ultron-studio/versions/{uuid} — con `integrityOk` recalculado. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $this->versions->detail($access, $this->versions->findByUuid($uuid))]);
    }

    /**
     * GET /api/admin/ultron-studio/compare?from=<baseline|draft|uuid>&to=<baseline|draft|uuid>
     *
     * Solo lee. `draft` es SIEMPRE el borrador de quien pide; un uuid solo se
     * busca entre las versiones (404 `version_not_found`). 422 con un lado que
     * no es ninguna de las tres cosas o con dos lados iguales.
     */
    public function compare(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $lado = ['required', 'string', 'max:36', 'regex:'.self::COMPARE_SIDE];
        $data = $request->validate(['from' => $lado, 'to' => $lado]);

        $desde = strtolower($data['from']);
        $hasta = strtolower($data['to']);
        if ($desde === $hasta) {
            throw ValidationException::withMessages(['to' => ['Compara dos cosas distintas: los dos lados son el mismo.']]);
        }

        return response()->json(['ok' => true, 'data' => $this->versions->compare($access, $desde, $hasta)]);
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/validate — simulación: no escribe nada. */
    public function simulate(Request $request, string $uuid): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $this->versions->simulate($this->versions->findByUuid($uuid))]);
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/submit (sin cuerpo) */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $version = $this->versions->submit($access, $request, $this->versions->findByUuid($uuid));

        return response()->json(['ok' => true, 'data' => $this->versions->detail($access, $version)]);
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/approve   {acknowledgedWarnings, note} */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        // En dos fases: una lista de miles de avisos se rechaza por su tope sin
        // recorrerla (revisión de seguridad de la API del Hito G, M-2).
        $data = $this->validateListSizesFirst($request, [
            // `present`: una lista vacía es válida (versión sin avisos), pero
            // tiene que venir: no reconocer nada no es lo mismo que no decirlo.
            'acknowledgedWarnings' => ['present', 'array', 'list', 'max:100'],
            'acknowledgedWarnings.*' => ['string', 'max:80', 'distinct'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500', new PlainText(multiline: true, strict: true)],
        ]);

        $version = $this->versions->approve(
            $access,
            $request,
            $this->versions->findByUuid($uuid),
            array_values($data['acknowledgedWarnings']),
            $data['note'] ?? null,
        );

        return response()->json(['ok' => true, 'data' => $this->versions->detail($access, $version)]);
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/reject   {reason} */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(multiline: true, strict: true)],
        ]);

        $version = $this->versions->reject($access, $request, $this->versions->findByUuid($uuid), $data['reason']);

        return response()->json(['ok' => true, 'data' => $this->versions->detail($access, $version)]);
    }

    /**
     * POST /api/admin/ultron-studio/versions/{uuid}/publish
     *   {clientRequestId, previewHash, acknowledgedNoEffect[≤64], note?≤500}
     *
     * Siempre en canario (contrato H5 §A5.1). En dos fases: una lista de miles
     * de elementos se rechaza por su tope sin recorrerla.
     */
    public function publish(Request $request, string $uuid, StudioPublications $publications): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $this->validateListSizesFirst($request, [
            'clientRequestId' => ['required', 'uuid'],
            'previewHash' => ['required', 'string', 'regex:'.self::SHA256],
            // `present`: reconocer nada (una versión sin nada sin efecto) es
            // decirlo, no callarlo.
            'acknowledgedNoEffect' => ['present', 'array', 'list', 'max:'.StudioPublications::MAX_ACKNOWLEDGED],
            'acknowledgedNoEffect.*' => ['string', 'max:80', 'regex:/^[A-Za-z0-9_.:\-]+$/', 'distinct'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioPublications::NOTE_MAX, new PlainText(multiline: true, strict: true)],
        ]);

        return self::written($publications->publish(
            $access,
            $request,
            $uuid,
            strtolower($data['clientRequestId']),
            $data['previewHash'],
            array_values($data['acknowledgedNoEffect']),
            $data['note'] ?? null,
        ));
    }

    /**
     * POST /api/admin/ultron-studio/versions/{uuid}/promote   {clientRequestId, evidenceHash, note?≤500}
     *
     * De canario a todas (contrato H5 §A5.2).
     */
    public function promote(Request $request, string $uuid, StudioPublications $publications): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'evidenceHash' => ['required', 'string', 'regex:'.self::SHA256],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioPublications::NOTE_MAX, new PlainText(multiline: true, strict: true)],
        ]);

        return self::written($publications->promote(
            $access,
            $request,
            $uuid,
            strtolower($data['clientRequestId']),
            $data['evidenceHash'],
            $data['note'] ?? null,
        ));
    }

    /**
     * POST /api/admin/ultron-studio/versions/{uuid}/rollback   {clientRequestId, reason 5–500}
     *
     * Sin llaves, sin cuatro ojos y sin ventana (contrato H5 §A5.3).
     */
    public function rollback(Request $request, string $uuid, StudioPublications $publications): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:'.StudioPublications::REASON_MIN, 'max:'.StudioPublications::REASON_MAX, new PlainText(multiline: true, strict: true)],
        ]);

        return self::written($publications->rollback($access, $request, $uuid, strtolower($data['clientRequestId']), $data['reason']));
    }

    /** GET /api/admin/ultron-studio/versions/{uuid}/publication-preview — solo lee (`PublicationPreviewDto`). */
    public function preview(Request $request, string $uuid, StudioPublications $publications): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $publications->preview($access, $this->versions->findByUuid($uuid))]);
    }

    /**
     * GET /api/admin/ultron-studio/versions/{uuid}/canary-evidence — solo lee
     * (`CanaryEvidenceDto`), y solo de la versión en canario. Es la lectura más
     * cara (cuenta los turnos del canario y ejecuta el acta): tiene su propio
     * limitador además del de lectura.
     */
    public function evidence(Request $request, string $uuid, StudioPublications $publications): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $publications->evidence($this->versions->findByUuid($uuid))]);
    }

    /**
     * 201 con la entrada creada; 200 `replayed` con la de la primera vez.
     *
     * @param  array{entry:array<string,mixed>,replayed:bool}  $resultado
     */
    private static function written(array $resultado): JsonResponse
    {
        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $resultado['entry']]);
        }

        return response()->json(['ok' => true, 'data' => $resultado['entry']], 201);
    }

    /**
     * `status=VALIDATED,REVIEW_REQUIRED` → lista. Un estado que no existe es
     * 422 (sin repetir lo que mandó el cliente).
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function statuses(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $estados = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '')));
        if (array_diff($estados, StudioVersionStatus::values()) !== []) {
            throw ValidationException::withMessages(['status' => ['Hay estados que no existen.']]);
        }

        return $estados;
    }
}
