<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Services\UltronStudio\StudioVoiceLabVerdicts;
use App\Support\Access\AuthorizationMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO · laboratorio hablado: estado, sesiones (abrir, terminar y
 * listar) y veredicto (contrato H8, D8-23 y «API», laboratorio). Voz en el
 * navegador contra un agente de laboratorio, sin teléfono.
 *
 * CERRADURAS, en este orden: la puerta ({@see AuthorizationMap}, una llave
 * por ruta) → una persona ({@see StudioAccess::requirePerson()}) → la llave de
 * la ruta → la bandera del laboratorio hablado (409 `voice_lab_disabled`).
 * `GET voice-lab/status` no mira la bandera: responde 200 también apagado,
 * con `{enabled:false, message, can}`.
 *
 * Sin route-model binding: el uuid llega como texto y lo resuelve el servicio
 * (un uuid sin forma es el mismo 404 que uno que no existe).
 *
 * Abrir una sesión responde 201 con `SessionDto` y, SOLO esa vez, la URL
 * firmada en `data.connection.signedUrl`; repetir la misma petición, 200 con
 * `replayed` y sin la URL. Terminar y emitir el veredicto responden
 * `SessionDto`. La lista es el paginador de Laravel.
 */
class UltronStudioVoiceLabController extends Controller
{
    use ResolvesPagination;
    use ValidatesListSizesFirst;

    public function __construct(
        private readonly StudioVoiceLabSessions $sessions,
        private readonly StudioVoiceLabVerdicts $verdicts,
    ) {}

    /** GET voice-lab/status — siempre 200 a quien tiene `ultron.view`, también con la bandera apagada. */
    public function status(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $this->sessions->status($access)]);
    }

    /** GET voice-lab/sessions?versionUuid&profileUuid&specHash&status&scope=all|mine&page&per_page */
    public function index(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);
        $data = $request->validate([
            // Validado ANTES de consultar: en PostgreSQL un uuid mal formado contra una columna uuid es un 500 (22P02).
            'versionUuid' => ['sometimes', 'nullable', 'uuid'],
            'profileUuid' => ['sometimes', 'nullable', 'uuid'],
            'specHash' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'status' => ['sometimes', 'nullable', Rule::in(StudioVoiceLabSession::STATUSES)],
            'scope' => ['sometimes', 'nullable', 'in:all,mine'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->sessions->page($access, [
            'version' => isset($data['versionUuid']) ? strtolower($data['versionUuid']) : null,
            'profile' => isset($data['profileUuid']) ? strtolower($data['profileUuid']) : null,
            'specHash' => isset($data['specHash']) ? strtolower($data['specHash']) : null,
            'status' => $data['status'] ?? null,
            'scope' => $data['scope'] ?? 'all',
        ], $this->resolvePerPage($request))]);
    }

    /** GET voice-lab/sessions/{uuid} — SessionDto. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioAccess::VIEW);

        return response()->json(['ok' => true, 'data' => $this->sessions->detail($access, $this->sessions->find($uuid))]);
    }

    /**
     * POST voice-lab/sessions   {versionUuid, profileUuid, acceptedMaxCostMicros, clientRequestId}
     *
     * 201 con SessionDto y la URL firmada (una vez); 200 con `replayed` si esta misma petición ya se había atendido.
     */
    public function store(Request $request): JsonResponse
    {
        $access = $this->open($request, StudioVoiceLabSessions::LAB_RUN);
        $data = $request->validate([
            'versionUuid' => ['required', 'string', 'uuid'],
            'profileUuid' => ['required', 'string', 'uuid'],
            'acceptedMaxCostMicros' => ['required', 'integer:strict', 'min:0', 'max:1000000000000'],
            'clientRequestId' => ['required', 'string', 'uuid'],
        ]);

        $resultado = $this->sessions->open(
            $access,
            strtolower($data['versionUuid']),
            strtolower($data['profileUuid']),
            $data['acceptedMaxCostMicros'],
            strtolower($data['clientRequestId']),
        );
        $detalle = $this->sessions->detail($access, $resultado['session'], $resultado['signedUrl']);

        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $detalle]);
        }

        return response()->json(['ok' => true, 'data' => $detalle], 201);
    }

    /**
     * POST voice-lab/sessions/{uuid}/end   {objectives, restrictionsBroken, interruptions, errors}
     *
     * Solo quien abrió la sesión; lo observado, una vez y con códigos de los catálogos de `GET voice-lab/status`.
     */
    public function end(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioVoiceLabSessions::LAB_RUN);
        $data = $this->validateListSizesFirst($request, [
            'objectives' => ['present', 'array', 'list', 'max:'.count(StudioVoiceLabSessions::OBJECTIVES)],
            'objectives.*' => ['string', 'distinct', Rule::in(array_keys(StudioVoiceLabSessions::OBJECTIVES))],
            'restrictionsBroken' => ['present', 'array', 'list', 'max:'.count(StudioVoiceLabSessions::RESTRICTIONS)],
            'restrictionsBroken.*' => ['string', 'distinct', Rule::in(array_keys(StudioVoiceLabSessions::RESTRICTIONS))],
            'interruptions' => ['required', 'integer:strict', 'min:0', 'max:'.StudioVoiceLabSessions::MAX_INTERRUPTIONS],
            'errors' => ['present', 'array', 'list', 'max:'.count(StudioVoiceLabSessions::ERRORS)],
            'errors.*' => ['string', 'distinct', Rule::in(array_keys(StudioVoiceLabSessions::ERRORS))],
        ]);

        $sesion = $this->sessions->end($access, $this->sessions->find($uuid), [
            'objectives' => array_values($data['objectives']),
            'restrictionsBroken' => array_values($data['restrictionsBroken']),
            'interruptions' => $data['interruptions'],
            'errors' => array_values($data['errors']),
        ]);

        return response()->json(['ok' => true, 'data' => $this->sessions->detail($access, $sesion)]);
    }

    /**
     * POST voice-lab/sessions/{uuid}/verdict   {decision, note (obligatoria en rejected), specHash}
     */
    public function verdict(Request $request, string $uuid): JsonResponse
    {
        $access = $this->open($request, StudioAccess::APPROVE);
        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(StudioVoiceLabSession::VERDICTS)],
            'note' => ['required_if:decision,'.StudioVoiceLabSession::VERDICT_REJECTED, 'nullable', 'string', 'min:5', 'max:'.StudioVoiceLabSessions::VERDICT_NOTE_MAX, new PlainText(multiline: true, strict: true)],
            'specHash' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);

        $sesion = $this->verdicts->record(
            $access,
            $this->sessions->find($uuid),
            $data['decision'],
            $data['note'] ?? null,
            strtolower($data['specHash']),
        );

        return response()->json(['ok' => true, 'data' => $this->sessions->detail($access, $sesion)]);
    }

    /** Persona, llave y bandera, en el orden del contrato. */
    private function open(Request $request, string $permission): StudioAccess
    {
        $access = StudioAccess::requirePerson($request);
        $access->require($permission);
        StudioVoiceLabSessions::assertEnabled();

        return $access;
    }
}
