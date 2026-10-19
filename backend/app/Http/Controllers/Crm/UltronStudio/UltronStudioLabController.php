<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioLabConfig;
use App\Services\UltronStudio\StudioLabEvents;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabVerdicts;
use App\Support\Access\AuthorizationMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO · laboratorio de IA: estado, estimación, ejecuciones,
 * cancelación, veredicto y libro (contrato H4 §A3, §A6 y §A7, rutas 22 y
 * 24–27, 31–33). Es una SIMULACIÓN DEL STUDIO, no ULTRON.
 *
 * CERRADURAS, en este orden: la puerta
 * ({@see AuthorizationMap}, una llave por ruta) → una
 * persona ({@see StudioAccess::requirePerson()}) → la llave de la ruta → la
 * bandera del laboratorio (409 `lab_disabled`). `GET lab/status` no mira la
 * bandera: responde 200 también apagado, con `{enabled:false, message, can}`.
 *
 * Sin route-model binding: el uuid llega como texto y lo resuelve el servicio.
 * Una ejecución sobre el borrador de otra persona responde el mismo 404
 * `run_not_found` que un uuid que no existe.
 *
 * Las respuestas de crear, cancelar y emitir el veredicto llevan `RunDetail`
 * (Enmienda 2, §4). Las listas (`runs`, `events`) son el paginador de Laravel.
 */
class UltronStudioLabController extends Controller
{
    use ResolvesPagination;
    use ValidatesListSizesFirst;

    public function __construct(
        private readonly StudioLabRuns $runs,
        private readonly StudioLabVerdicts $verdicts,
        private readonly StudioLabEvents $events,
    ) {}

    /** GET lab/status — siempre 200 a quien tiene `ultron.view`, también con la bandera apagada. */
    public function status(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $this->runs->status($access)]);
    }

    /** GET lab/runs?version=<uuid>&target=version|draft|baseline&scope=all|mine&page&per_page */
    public function runs(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);
        $data = $request->validate([
            // Validado ANTES de consultar: en PostgreSQL un uuid mal formado contra una columna uuid es un 500 (22P02).
            'version' => ['sometimes', 'nullable', 'uuid'],
            'target' => ['sometimes', 'nullable', Rule::in(StudioLabRun::TARGET_KINDS)],
            'scope' => ['sometimes', 'nullable', 'in:all,mine'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->runs->page($access, [
            'version' => isset($data['version']) ? strtolower($data['version']) : null,
            'target' => $data['target'] ?? null,
            'scope' => $data['scope'] ?? 'all',
        ], $this->resolvePerPage($request))]);
    }

    /** GET lab/runs/{uuid} — RunDetail. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);

        return response()->json(['ok' => true, 'data' => $this->runs->detail($access, $this->runs->findVisible($access, $uuid))]);
    }

    /** POST lab/estimate   {target, scenarioUuids} — no escribe nada (cupo de lectura). */
    public function estimate(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);
        $data = $this->validateListSizesFirst($request, self::targetRules());

        return response()->json(['ok' => true, 'data' => $this->runs->estimate($access, $data['target'], self::uuids($data['scenarioUuids']))]);
    }

    /** GET lab/events?subjectType=scenario|run|system&subjectUuid&event&page&per_page */
    public function events(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);
        $data = $request->validate([
            'subjectType' => ['sometimes', 'nullable', Rule::in(StudioLabEvent::SUBJECT_TYPES)],
            'subjectUuid' => ['sometimes', 'nullable', 'uuid'],
            'event' => ['sometimes', 'nullable', 'string', 'max:48', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->events->page($access, [
            'subjectType' => $data['subjectType'] ?? null,
            'subjectUuid' => isset($data['subjectUuid']) ? strtolower($data['subjectUuid']) : null,
            'event' => $data['event'] ?? null,
        ], $this->resolvePerPage($request))]);
    }

    /**
     * POST lab/runs   {target, scenarioUuids, acceptedMaxCostMicros, clientRequestId}
     *
     * 201 con RunDetail; 200 con `replayed` si esta misma petición ya se había atendido.
     */
    public function store(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioLabRuns::LAB_RUN);
        $data = $this->validateListSizesFirst($request, self::targetRules() + [
            'acceptedMaxCostMicros' => ['required', 'integer:strict', 'min:0', 'max:1000000000000'],
            'clientRequestId' => ['required', 'uuid'],
        ]);

        $resultado = $this->runs->create(
            $access,
            $data['target'],
            self::uuids($data['scenarioUuids']),
            $data['acceptedMaxCostMicros'],
            strtolower($data['clientRequestId']),
        );
        $detalle = $this->runs->detail($access, $resultado['run']->fresh() ?? $resultado['run']);

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $detalle]);
        }

        return response()->json(['ok' => true, 'data' => $detalle], 201);
    }

    /** POST lab/runs/{uuid}/cancel (sin cuerpo) — RunDetail. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioLabRuns::LAB_RUN);
        $run = $this->runs->cancel($access, $this->runs->findVisible($access, $uuid));

        return response()->json(['ok' => true, 'data' => $this->runs->detail($access, $run)]);
    }

    /**
     * POST lab/runs/{uuid}/verdict   {decision, note, acknowledgedCases (solo approved), resultsHash}
     *
     * `acknowledgedCases` llega SIEMPRE en `approved` (puede ser `[]`), y nunca
     * en las otras dos decisiones (Enmienda 2, §5).
     */
    public function verdict(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioAccess::APPROVE);
        $data = $this->validateListSizesFirst($request, [
            'decision' => ['required', 'string', Rule::in(StudioLabVerdict::DECISIONS)],
            'note' => ['required_unless:decision,approved', 'nullable', 'string', 'min:5', 'max:'.StudioLabRuns::VERDICT_NOTE_MAX, new PlainText(multiline: true)],
            'acknowledgedCases' => ['present_if:decision,approved', 'prohibited_unless:decision,approved', 'array', 'list', 'max:'.StudioLabConfig::HARD_MAX_CASES_PER_RUN],
            'acknowledgedCases.*' => ['string', 'max:16', 'distinct'],
            'resultsHash' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);

        $run = $this->verdicts->record(
            $access,
            $this->runs->findVisible($access, $uuid),
            $data['decision'],
            $data['note'] ?? null,
            $data['decision'] === StudioLabVerdict::DECISION_APPROVED ? array_values($data['acknowledgedCases'] ?? []) : null,
            $data['resultsHash'],
        );

        return response()->json(['ok' => true, 'data' => $this->runs->detail($access, $run)]);
    }

    /** Persona, llave y bandera, en el orden del contrato. */
    private function open(Request $request, string $permission): StudioAccess
    {
        $access = StudioAccess::requirePerson($request);
        $access->require($permission);
        StudioLabRuns::assertEnabled();

        return $access;
    }

    /**
     * La FORMA del objetivo y de los escenarios (estimar y crear). El tope de
     * escenarios es el de la configuración (como mucho 20).
     *
     * @return array<string, list<mixed>>
     */
    private static function targetRules(): array
    {
        $maximo = StudioLabConfig::current()->maxCasesPerRun;

        return [
            'target' => ['required', 'array:kind,uuid,revision'],
            'target.kind' => ['required', 'string', Rule::in(StudioLabRun::TARGET_KINDS)],
            'target.uuid' => ['required_if:target.kind,version', 'prohibited_unless:target.kind,version', 'nullable', 'uuid'],
            'target.revision' => ['required_if:target.kind,draft', 'prohibited_unless:target.kind,draft', 'nullable', 'integer:strict', 'min:1', 'max:2147483647'],
            'scenarioUuids' => ['required', 'array', 'list', 'min:1', "max:{$maximo}"],
            'scenarioUuids.*' => ['string', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /**
     * @param  list<string>  $uuids
     * @return list<string>
     */
    private static function uuids(array $uuids): array
    {
        return array_values(array_map(static fn (string $u): string => strtolower($u), $uuids));
    }
}
