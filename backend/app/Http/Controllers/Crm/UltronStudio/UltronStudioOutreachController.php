<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachReadModels;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * La API de la EJECUCIÓN de la automatización comercial (contrato H9, §7
 * E1–E10, D9-13, D9-14; L9-43: en `Crm/UltronStudio` y montada en
 * `routes/ultron-studio.php`, bloque `outreach/**`): el panel (estado y
 * métricas), las inscripciones, las decisiones (con su traza y su texto), los
 * trabajos, los frenos (la PARADA GLOBAL y soltar a cuatro ojos) y el
 * historial por persona.
 *
 * LAS CERRADURAS: la puerta (`AuthorizationMap`, una ruta, una llave de
 * {@see ROUTES}) → una persona → la llave otra vez dentro
 * ({@see OutreachAccess::require()}). Todo recurso se nombra por `uuid`; la
 * persona, por su `subjectRef` (no enumerable); las listas van paginadas
 * (≤ 50) y sin datos personales. Ninguna respuesta lleva teléfonos, nombres
 * de socios, documentos, cuerpos ni importes.
 */
class UltronStudioOutreachController extends Controller
{
    use ResolvesPagination;

    /**
     * Las 13 rutas de la ejecución (E1–E13 de §7), con su permiso, una a una:
     * la lista que `AuthorizationMap` tiene que clasificar. Todas bajo
     * `admin/ultron-studio/outreach/`.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const ROUTES = [
        ['GET', 'outreach/status', OutreachAccess::VIEW],
        ['GET', 'outreach/metrics', OutreachAccess::VIEW],
        ['GET', 'outreach/enrollments', OutreachAccess::VIEW],
        ['GET', 'outreach/enrollments/{uuid}', OutreachAccess::VIEW],
        ['GET', 'outreach/decisions/{uuid}', OutreachAccess::VIEW],
        ['GET', 'outreach/jobs', OutreachAccess::VIEW],
        ['GET', 'outreach/brakes', OutreachAccess::VIEW],
        ['POST', 'outreach/brakes', OutreachAccess::STOP],
        ['POST', 'outreach/brakes/{uuid}/release', OutreachAccess::RELEASE],
        ['GET', 'outreach/people/{subjectRef}/timeline', OutreachAccess::TIMELINE_VIEW],
        ['GET', 'outreach/assisted/queue', OutreachAccess::ASSISTED_WORK],
        ['POST', 'outreach/assisted/{uuid}/claim', OutreachAccess::ASSISTED_WORK],
        ['POST', 'outreach/assisted/{uuid}/outcome', OutreachAccess::ASSISTED_WORK],
    ];

    public const ROUTE_COUNT = 13;

    private const SUBJECT_REF = '/^[0-9a-f]{64}$/';

    /**
     * EL BLOQUE DE RUTAS de la ejecución (L9-43): las 13 rutas `outreach/**` que
     * el lead monta al FINAL de `routes/ultron-studio.php`, dentro del grupo del
     * Studio (`admin/ultron-studio`, las mismas cerraduras y el tope de 64 KB),
     * con UNA línea:
     *
     *     \App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController::routes();
     *
     * Lecturas con el cupo `ultron-studio-read`; escrituras con `ultron-studio-write`.
     * Sin route-model binding: el uuid es texto. Las pruebas registran este mismo bloque.
     */
    public static function routes(): void
    {
        Route::middleware('throttle:ultron-studio-read')->group(function (): void {
            Route::get('outreach/status', [self::class, 'status']);
            Route::get('outreach/metrics', [self::class, 'metrics']);
            Route::get('outreach/enrollments', [self::class, 'enrollments']);
            Route::get('outreach/enrollments/{uuid}', [self::class, 'enrollment'])->whereUuid('uuid');
            Route::get('outreach/decisions/{uuid}', [self::class, 'decision'])->whereUuid('uuid');
            Route::get('outreach/jobs', [self::class, 'jobs']);
            Route::get('outreach/brakes', [self::class, 'brakes']);
            Route::get('outreach/people/{subjectRef}/timeline', [self::class, 'timeline'])->where('subjectRef', '[0-9a-f]{64}');
            Route::get('outreach/assisted/queue', [UltronStudioOutreachAssistedController::class, 'queue']);
        });
        Route::middleware('throttle:ultron-studio-write')->group(function (): void {
            Route::post('outreach/brakes', [self::class, 'storeBrake']);
            Route::post('outreach/brakes/{uuid}/release', [self::class, 'releaseBrake'])->whereUuid('uuid');
            Route::post('outreach/assisted/{uuid}/claim', [UltronStudioOutreachAssistedController::class, 'claim'])->whereUuid('uuid');
            Route::post('outreach/assisted/{uuid}/outcome', [UltronStudioOutreachAssistedController::class, 'outcome'])->whereUuid('uuid');
        });
    }

    /**
     * El bloque literal de `AuthorizationMap::OVERRIDES` (el lead lo pega; una
     * guarda puede compararlo con {@see ROUTES}).
     *
     * @return array<string, string>
     */
    public static function authorizationMap(): array
    {
        $mapa = [];
        foreach (self::ROUTES as [$metodo, $ruta, $llave]) {
            $mapa["{$metodo} api/admin/ultron-studio/{$ruta}"] = $llave;
        }

        return $mapa;
    }

    // ── E1, E2 · panel ──────────────────────────────────────────────────────

    /** GET outreach/status: banderas, modo, constante, frenos (propios y ajenos), cadena y fuentes */
    /** L9-47 (H-15 de OPS9-b; L9-68): con la maestra apagada, toda ruta de la ejecución salvo `status` responde 409 `outreach_disabled`. */
    private static function requireEnabled(): void
    {
        if (! OutreachConfig::enabled()) {
            throw OutreachException::disabled();
        }
    }

    public function status(Request $request, OutreachReadModels $read): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::VIEW);

        return response()->json(['ok' => true, 'data' => $read->status($access, RelativeTime::utc(now()))]);
    }

    /** GET outreach/metrics?mode: por estrategia, canal, nivel y motivo */
    public function metrics(Request $request, OutreachReadModels $read): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();
        $data = $request->validate(['mode' => ['sometimes', 'nullable', 'string', Rule::in(['shadow', 'live'])]]);

        return response()->json(['ok' => true, 'data' => $read->metrics(RelativeTime::utc(now()), $data['mode'] ?? null)]);
    }

    // ── E3–E6 · inscripciones, decisiones y trabajos ────────────────────────

    /** GET outreach/enrollments?strategy&status&mode&page&per_page (≤ 50) */
    public function enrollments(Request $request, OutreachReadModels $read): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();
        $data = $request->validate([
            'strategy' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'waiting', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'])],
            'mode' => ['sometimes', 'nullable', 'string', Rule::in(['shadow', 'live'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(['ok' => true, 'data' => $read->enrollments($data, $this->resolvePerPage($request, 20, OutreachReadModels::PER_PAGE_MAX))]);
    }

    /** GET outreach/enrollments/{uuid}: con sus decisiones, trabajos y resultados */
    public function enrollment(Request $request, OutreachReadModels $read, string $uuid): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();

        return response()->json(['ok' => true, 'data' => $read->enrollment($uuid)]);
    }

    /** GET outreach/decisions/{uuid}: la traza, la foto derivada y su texto */
    public function decision(Request $request, OutreachReadModels $read, string $uuid): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();

        return response()->json(['ok' => true, 'data' => $read->decision($uuid)]);
    }

    /** GET outreach/jobs?strategy&status&mode&channel&page&per_page (≤ 50) */
    public function jobs(Request $request, OutreachReadModels $read): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();
        $data = $request->validate([
            'strategy' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['planned', 'claimed', 'dispatching', 'started', 'simulated', 'failed_to_start', 'ambiguous', 'canceled', 'expired'])],
            'mode' => ['sometimes', 'nullable', 'string', Rule::in(['shadow', 'live'])],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(OutreachCatalog::JOB_CHANNELS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(['ok' => true, 'data' => $read->jobs($data, $this->resolvePerPage($request, 20, OutreachReadModels::PER_PAGE_MAX))]);
    }

    // ── E7–E9 · frenos y PARADA GLOBAL ──────────────────────────────────────

    /** GET outreach/brakes?limit (≤ 200): las aperturas, las vivas primero */
    public function brakes(Request $request, OutreachStop $stop): JsonResponse
    {
        OutreachAccess::require($request, OutreachAccess::VIEW);
        self::requireEnabled();
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:200']]);

        return response()->json(['ok' => true, 'data' => $stop->list((int) ($data['limit'] ?? 50)), 'meta' => ['open' => $stop->openScopes()]]);
    }

    /**
     * POST outreach/brakes   {clientRequestId, scope: global|strategy|channel, scopeRef?, reason: manual|legal_hold, note}
     *
     * La PARADA GLOBAL (`scope: global`) o el freno de una estrategia (su uuid)
     * o un canal (`assisted`, `whatsapp_template`, `voice_call`). 201 con el
     * freno y los trabajos retenidos; 200 `replayed` si ese alcance ya estaba
     * frenado o la petición se repite.
     */
    public function storeBrake(Request $request, OutreachStop $stop): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::STOP);
        self::requireEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'scope' => ['required', 'string', Rule::in(OutreachStop::SCOPES)],
            'scopeRef' => ['sometimes', 'nullable', 'string', 'max:36'],
            'reason' => ['sometimes', 'nullable', 'string', Rule::in(OutreachStop::PERSON_REASONS)],
            'note' => ['required', 'string', 'min:'.OutreachStop::NOTE_MIN, 'max:'.OutreachStop::NOTE_MAX],
        ]);
        $r = $stop->open((string) $data['scope'], $data['scopeRef'] ?? null, (string) ($data['reason'] ?? OutreachStop::REASON_MANUAL), (string) $data['note'], $access->actor, (string) $data['clientRequestId']);

        return response()->json(['ok' => true, 'data' => $r['brake'], 'meta' => ['replayed' => $r['replayed'], 'retainedJobs' => $r['retainedJobs']]], $r['replayed'] ? 200 : 201);
    }

    /** POST outreach/brakes/{uuid}/release   {clientRequestId, note}: otra cuenta Y otra persona que quien lo abrió */
    public function releaseBrake(Request $request, OutreachStop $stop, string $uuid): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::RELEASE);
        self::requireEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'note' => ['required', 'string', 'min:'.OutreachStop::NOTE_MIN, 'max:'.OutreachStop::NOTE_MAX],
        ]);

        return response()->json(['ok' => true, 'data' => $stop->release($access->actor, $uuid, (string) $data['note'], (string) $data['clientRequestId'])]);
    }

    // ── E10 · historial ─────────────────────────────────────────────────────

    /** GET outreach/people/{subjectRef}/timeline?limit&cursor: metadatos, sin cuerpos ni números */
    public function timeline(Request $request, OutreachReadModels $read, string $subjectRef): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::TIMELINE_VIEW);
        self::requireEnabled();
        if (preg_match(self::SUBJECT_REF, $subjectRef) !== 1) {
            return response()->json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Los datos no son válidos.', 'errors' => ['subjectRef' => ['invalid']]], 422);
        }
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return response()->json(['ok' => true, 'data' => $read->timeline($access, $subjectRef, (int) ($data['limit'] ?? 50), $data['cursor'] ?? null)]);
    }

    /** Las tablas que esta API lee (para la guarda de solo lectura de SEC9). @return list<string> */
    public static function tables(): array
    {
        return [OutreachTables::RUNS, OutreachTables::CURSORS, OutreachTables::ENROLLMENTS, OutreachTables::JOBS, OutreachTables::DECISIONS, OutreachTables::OUTCOMES, OutreachTables::BRAKES, OutreachTables::BUDGETS];
    }
}
