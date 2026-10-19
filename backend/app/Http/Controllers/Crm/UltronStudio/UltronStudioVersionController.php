<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
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
 * Aprobar aquí no cambia al agente; publicar y revertir son 409 siempre.
 *
 * Desde el Hito G, también la comparación (ruta 21, contrato G §A4).
 */
class UltronStudioVersionController extends Controller
{
    use ResolvesPagination;
    use ValidatesListSizesFirst;

    /** Un lado de `GET compare`: `baseline`, `draft` o el uuid de una versión. */
    private const COMPARE_SIDE = '/^(baseline|draft|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/';

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
            'label' => ['required', 'string', 'max:80', new PlainText],
            'note' => ['sometimes', 'nullable', 'string', 'max:500', new PlainText(multiline: true)],
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
            'note' => ['sometimes', 'nullable', 'string', 'max:500', new PlainText(multiline: true)],
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
            'reason' => ['required', 'string', 'min:5', 'max:500', new PlainText(multiline: true)],
        ]);

        $version = $this->versions->reject($access, $request, $this->versions->findByUuid($uuid), $data['reason']);

        return response()->json(['ok' => true, 'data' => $this->versions->detail($access, $version)]);
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/publish — 409 siempre en esta fase (queda auditado). */
    public function publish(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        throw $this->versions->refuse($access, $request, $this->versions->findByUuid($uuid), 'publish');
    }

    /** POST /api/admin/ultron-studio/versions/{uuid}/rollback — 409 siempre en esta fase (queda auditado). */
    public function rollback(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        throw $this->versions->refuse($access, $request, $this->versions->findByUuid($uuid), 'rollback');
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
