<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\UltronStudio\StudioVoiceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: el GOBIERNO DE LA VOZ (contrato H8, «API», D8-6, D8-7, D8-19,
 * D8-20 y D8-22): perfiles de voz, la previsualización de la compilación, la
 * cadena de voz (publicar, promover, revertir, apagar), la evidencia del
 * canario y el freno. Ver {@see UltronStudioController} para las dos puertas
 * comunes a los controladores del Studio.
 *
 * LAS CERRADURAS: la puerta (`AuthorizationMap`, una a una, ver {@see ROUTES})
 * → una persona → la llave en el servicio → las banderas (publicar y promover:
 * `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`, apagada) → cuatro ojos por
 * identidad. CERO RED POR DEFECTO: las lecturas y la previsualización no salen
 * a la red nunca; publicar, promover, revertir y apagar solo lo hacen después
 * de sus puertas y por las interfaces de D8-3. Ninguna respuesta lleva
 * teléfonos, secretos ni el id del proveedor en claro.
 */
class UltronStudioVoiceController extends Controller
{
    use ResolvesPagination;

    /** Las rutas del Studio antes del Hito 8 (F, G, H4, H5 y H6). */
    public const PREVIOUS_STUDIO_ROUTES = 56;

    /**
     * Las rutas de voz del Studio (contrato H8, «API»), con su permiso, una a
     * una: la lista que `AuthorizationMap` (B8) tiene que clasificar y que las
     * guardas leen en lugar de un recuento literal (E2-6). `GET
     * integrations/voice` (la 45) ya existía y no cuenta.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const ROUTES = [
        ['GET', 'voice/profiles', 'ultron.view'],
        ['GET', 'voice/profiles/{uuid}', 'ultron.view'],
        ['POST', 'voice/profiles', StudioVoiceProfiles::MANAGE],
        ['POST', 'voice/profiles/{uuid}/submit', StudioVoiceProfiles::MANAGE],
        ['POST', 'voice/profiles/{uuid}/approve', StudioAccess::APPROVE],
        ['POST', 'voice/profiles/{uuid}/reject', StudioAccess::APPROVE],
        ['POST', 'voice/compile-preview', StudioVoiceProfiles::MANAGE],
        ['GET', 'voice/publications', 'ultron.view'],
        ['POST', 'voice/publish', StudioVoicePublishing::PUBLISH],
        ['POST', 'voice/promote', StudioVoicePublishing::PUBLISH],
        ['POST', 'voice/rollback', StudioVoicePublishing::PUBLISH],
        ['POST', 'voice/off', StudioVoicePublishing::PUBLISH],
        ['GET', 'voice/canary-evidence', 'ultron.view'],
        ['GET', 'voice/brakes', 'ultron.view'],
        ['POST', 'voice/brakes/{uuid}/release', StudioVoicePublishing::PUBLISH],
    ];

    /**
     * Las del laboratorio hablado (LAB8, `UltronStudioVoiceLabController`;
     * las monta S8 en `routes/ultron-studio.php`, «Traspasos»), con su permiso.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const LAB_ROUTES = [
        ['GET', 'voice-lab/status', 'ultron.view'],
        ['GET', 'voice-lab/sessions', 'ultron.view'],
        ['GET', 'voice-lab/sessions/{uuid}', 'ultron.view'],
        ['POST', 'voice-lab/sessions', 'ultron.voice_lab_run'],
        ['POST', 'voice-lab/sessions/{uuid}/end', 'ultron.voice_lab_run'],
        ['POST', 'voice-lab/sessions/{uuid}/verdict', StudioAccess::APPROVE],
    ];

    /** Todas las rutas del Studio con el Hito 8: 56 + 15 + 6 (E2-6; una prueba lo ata a las dos listas y a las rutas reales). */
    public const STUDIO_ROUTE_COUNT = 77;

    /** `fingerprint` de la evidencia: sha256 en hexadecimal en minúsculas. */
    private const SHA256 = '/^[0-9a-f]{64}$/D';

    private const BRAKES_DEFAULT = 20;

    private const BRAKES_MAX = 50;

    // ── Perfiles ────────────────────────────────────────────────────────────

    /** GET voice/profiles?status&page&per_page (≤ 50): sin el contenido ni los destinos */
    public function profiles(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in(StudioVoiceProfile::STATUSES)],
        ] + self::pagination());

        return response()->json(['ok' => true, 'data' => StudioVoiceProfiles::page(
            $access,
            $data['status'] ?? null,
            $this->resolvePerPage($request, 20, StudioVoiceProfiles::PER_PAGE_MAX),
        )]);
    }

    /** GET voice/profiles/{uuid}: el contenido, con cada destino como alias y 4 dígitos */
    public function profile(Request $request, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => StudioVoiceProfiles::detailDto(StudioVoiceProfiles::findByUuid($uuid), $access)]);
    }

    /**
     * POST voice/profiles   {clientRequestId, label 3–80, basedOn?, content}
     *
     * `content` = `{voiceId, greetings{inbound, outbound}, holes{presentation,
     * closing}, maxDurationSeconds, tools[], systemTools[], transfer{destinations:
     * [{alias, phone(E.164)}]}, privacy{retentionDays}, voicemail}`. El formato,
     * el idioma, el aviso y la privacidad apagada los pone el servidor. 201 con
     * el perfil; 200 `replayed` si se repite.
     */
    public function storeProfile(Request $request, StudioVoiceProfiles $profiles): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $texto = static fn (int $max): array => ['required', 'string', 'max:'.$max];
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'label' => ['required', 'string', 'min:'.StudioVoiceProfiles::LABEL_MIN, 'max:'.StudioVoiceProfiles::LABEL_MAX, new PlainText(strict: true), StudioCanaryPhone::contactDataRule()],
            'basedOn' => ['sometimes', 'nullable', 'uuid'],
            'content' => ['required', 'array'],
            'content.voiceId' => $texto(64),
            'content.greetings' => ['required', 'array'],
            'content.greetings.inbound' => $texto(StudioVoiceTemplate::GREETING_MAX),
            'content.greetings.outbound' => $texto(StudioVoiceTemplate::GREETING_MAX),
            'content.holes' => ['required', 'array'],
            'content.holes.presentation' => $texto(StudioVoiceTemplate::HOLES['presentation']['max']),
            'content.holes.closing' => $texto(StudioVoiceTemplate::HOLES['closing']['max']),
            'content.maxDurationSeconds' => ['required', 'integer', 'min:'.StudioVoiceCompiler::MIN_DURATION_SECONDS, 'max:'.StudioVoiceCompiler::MAX_DURATION_CEILING],
            'content.tools' => ['required', 'array', 'list', 'max:'.count(StudioVoiceTemplate::SERVER_TOOLS)],
            'content.tools.*' => ['string', 'max:40'],
            'content.systemTools' => ['required', 'array', 'list', 'max:'.count(StudioVoiceTemplate::SYSTEM_TOOLS)],
            'content.systemTools.*' => ['string', 'max:40'],
            'content.transfer' => ['required', 'array'],
            'content.transfer.destinations' => ['present', 'array', 'list', 'max:'.StudioVoiceCompiler::MAX_DESTINATIONS],
            'content.transfer.destinations.*' => ['array'],
            'content.transfer.destinations.*.alias' => $texto(StudioCanaryPhone::ALIAS_MAX),
            'content.transfer.destinations.*.phone' => $texto(20),
            'content.privacy' => ['required', 'array'],
            'content.privacy.retentionDays' => ['required', 'integer', 'min:1', 'max:'.StudioVoiceCompiler::MAX_RETENTION_DAYS],
            'content.privacy.storeAudio' => ['sometimes', 'boolean'],
            'content.privacy.recording' => ['sometimes', 'boolean'],
            'content.voicemail' => $texto(20),
        ]);

        $resultado = $profiles->create($access, $request, strtolower($data['clientRequestId']), $data['label'], $data['basedOn'] ?? null, $request->input('content'));
        $dto = StudioVoiceProfiles::detailDto($resultado['profile'], $access);
        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $dto]);
        }

        return response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /** POST voice/profiles/{uuid}/submit: solo quien lo creó */
    public function submitProfile(Request $request, StudioVoiceProfiles $profiles, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => StudioVoiceProfiles::detailDto($profiles->submit($access, $request, $uuid), $access)]);
    }

    /** POST voice/profiles/{uuid}/approve   {note?≤500}: otra identidad */
    public function approveProfile(Request $request, StudioVoiceProfiles $profiles, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioVoiceProfiles::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioVoiceProfiles::detailDto($profiles->approve($access, $request, $uuid, $data['note'] ?? null), $access)]);
    }

    /** POST voice/profiles/{uuid}/reject   {note 5–500}: otra identidad, con motivo */
    public function rejectProfile(Request $request, StudioVoiceProfiles $profiles, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'note' => ['required', 'string', 'min:'.StudioVoiceProfiles::NOTE_MIN, 'max:'.StudioVoiceProfiles::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioVoiceProfiles::detailDto($profiles->reject($access, $request, $uuid, $data['note']), $access)]);
    }

    // ── La compilación ──────────────────────────────────────────────────────

    /** POST voice/compile-preview   {versionUuid, profileUuid, purpose?: publication|lab} — sin red y sin escribir */
    public function preview(Request $request, StudioVoicePublishing $publishing): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'versionUuid' => ['required', 'uuid'],
            'profileUuid' => ['required', 'uuid'],
            'purpose' => ['sometimes', 'string', Rule::in(StudioVoiceCompiler::PURPOSES)],
        ]);

        return response()->json(['ok' => true, 'data' => $publishing->preview(
            $access,
            $data['versionUuid'],
            $data['profileUuid'],
            $data['purpose'] ?? StudioVoiceCompiler::PURPOSE_PUBLICATION,
        )]);
    }

    // ── La cadena de voz ────────────────────────────────────────────────────

    /** GET voice/publications?page&per_page (≤ 50): el estado (lo vigente de cada parte) y el historial, cada entrada con `verified` */
    public function publications(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $request->validate(self::pagination());

        return response()->json(['ok' => true, 'data' => [
            'state' => StudioVoicePublications::state(),
            'publishing' => StudioVoicePublishing::status(),
            'history' => StudioVoicePublications::page($this->resolvePerPage($request, 20, StudioVoicePublications::PER_PAGE_MAX)),
        ]]);
    }

    /** POST voice/publish   {clientRequestId, versionUuid, profileUuid, note?≤500} */
    public function publish(Request $request, StudioVoicePublishing $publishing): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'versionUuid' => ['required', 'uuid'],
            'profileUuid' => ['required', 'uuid'],
            'note' => self::noteRules(),
        ]);

        return self::written($publishing->publish($access, $request, strtolower($data['clientRequestId']), $data['versionUuid'], $data['profileUuid'], $data['note'] ?? null));
    }

    /** POST voice/promote   {clientRequestId, evidenceHash, note?≤500} */
    public function promote(Request $request, StudioVoicePublishing $publishing): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'evidenceHash' => ['required', 'string', 'regex:'.self::SHA256],
            'note' => self::noteRules(),
        ]);

        return self::written($publishing->promote($access, $request, strtolower($data['clientRequestId']), $data['evidenceHash'], $data['note'] ?? null));
    }

    /** POST voice/rollback   {clientRequestId, scope: canary|rest, reason 5–500} — sin bandera ni ventana */
    public function rollback(Request $request, StudioVoicePublishing $publishing): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'scope' => ['required', 'string', Rule::in(StudioVoicePublication::SCOPES)],
            'reason' => self::reasonRules(),
        ]);

        return self::written($publishing->rollback($access, $request, strtolower($data['clientRequestId']), $data['scope'], $data['reason']));
    }

    /** POST voice/off   {clientRequestId, reason 5–500} — sin bandera ni ventana */
    public function off(Request $request, StudioVoicePublishing $publishing): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'reason' => self::reasonRules(),
        ]);

        return self::written($publishing->off($access, $request, strtolower($data['clientRequestId']), $data['reason']));
    }

    // ── El canario y el freno ───────────────────────────────────────────────

    /** GET voice/canary-evidence: solo recuentos y huellas (lo cuenta la telefonía) */
    public function evidence(Request $request, StudioVoiceEvidence $evidence): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $evidence->canary()['dto']]);
    }

    /** GET voice/brakes?limit (≤ 50): el estado del freno y sus aperturas y sueltas (de la telefonía) */
    public function brakes(Request $request): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $data = $request->validate(['limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::BRAKES_MAX]]);

        return response()->json(['ok' => true, 'data' => StudioVoiceEvidence::brakes((int) ($data['limit'] ?? self::BRAKES_DEFAULT))]);
    }

    /** POST voice/brakes/{uuid}/release   {clientRequestId, note 5–500}: solo una persona con `ultron.voice_publish` */
    public function releaseBrake(Request $request, StudioVoiceEvidence $evidence, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'note' => ['required', 'string', 'min:'.StudioVoiceEvidence::NOTE_MIN, 'max:'.StudioVoiceEvidence::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => $evidence->release($access, $request, strtolower($uuid), $data['note'], strtolower($data['clientRequestId']))]);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * 201 con lo escrito; 200 `replayed` con lo de la primera vez.
     *
     * @param  array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}  $resultado
     */
    private static function written(array $resultado): JsonResponse
    {
        $data = ['entries' => $resultado['entries'], 'agent' => $resultado['agent'], 'number' => $resultado['number']];
        if ($resultado['replayed']) {
            return response()->json(['ok' => true, 'replayed' => true, 'data' => $data]);
        }

        return response()->json(['ok' => true, 'data' => $data], 201);
    }

    /** @return list<mixed> */
    private static function noteRules(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:'.StudioVoicePublishing::NOTE_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()];
    }

    /** @return list<mixed> */
    private static function reasonRules(): array
    {
        return ['required', 'string', 'min:'.StudioVoicePublishing::REASON_MIN, 'max:'.StudioVoicePublishing::REASON_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()];
    }

    /** @return array<string, list<string>> */
    private static function pagination(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
