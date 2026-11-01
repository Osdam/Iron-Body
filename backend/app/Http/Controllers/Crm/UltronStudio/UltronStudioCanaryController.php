<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCanaryNumbers;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioIntegrations;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: el registro de números canarios (contrato H6 §I4, rutas 42 y
 * 49–54). Ver {@see StudioCanaryNumbers} para la máquina de estados, los
 * cuatro ojos y los topes, y {@see UltronStudioController} para las dos
 * puertas comunes.
 *
 * Este registro NO cambia a quién responde ULTRON: el motor sigue atendiendo
 * solo la conversación canaria configurada en el servidor. El teléfono llega
 * SOLO en el cuerpo del registro y se valida aquí ({@see StudioCanaryPhone}),
 * con textos fijos que no lo repiten; el número completo nunca sale, tampoco
 * tras registrarlo. Sin route-model binding: el uuid es texto.
 *
 * Orden (D-13 del H5): la forma (422) antes que las llaves (409).
 */
class UltronStudioCanaryController extends Controller
{
    use ResolvesPagination;

    /** GET /api/admin/ultron-studio/integrations/canaries?status&channel&page&per_page (≤ 50) */
    public function index(Request $request, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in([...StudioCanaryNumber::STATUSES, 'all'])],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(StudioCanaryPhone::CHANNELS)],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['ok' => true, 'data' => $canaries->page(
            $access,
            ['status' => $data['status'] ?? null, 'channel' => $data['channel'] ?? null],
            $this->resolvePerPage($request, 20, StudioCanaryNumbers::PER_PAGE_MAX),
        )]);
    }

    /**
     * POST /api/admin/ultron-studio/integrations/canaries
     *   {clientRequestId, channel, phone, alias, consentAttested:true, expectedVersionUuid?}
     *
     * 201 `{replayed:false, number}`; 200 `{replayed:true, number}` si se repite.
     */
    public function store(Request $request, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'channel' => ['required', 'string', Rule::in(StudioCanaryPhone::CHANNELS)],
            'phone' => ['required', 'string', 'max:'.StudioCanaryPhone::RAW_MAX, self::phoneRule($request)],
            'alias' => ['required', 'string', self::aliasRule(), new PlainText],
            'consentAttested' => ['required', 'boolean:strict', 'accepted'],
            'expectedVersionUuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $resultado = $canaries->register(
            $access,
            $request,
            strtolower($data['clientRequestId']),
            $data['channel'],
            $data['phone'],
            $data['alias'],
            $data['expectedVersionUuid'] ?? null,
        );
        unset($data);

        return response()->json(['ok' => true, 'data' => $resultado], $resultado['replayed'] ? 200 : 201);
    }

    /** POST /api/admin/ultron-studio/integrations/canaries/{uuid}/authorize (sin cuerpo): otra persona que quien registró */
    public function authorize(Request $request, string $uuid, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $canaries->authorize($access, $request, $uuid)]);
    }

    /** POST /api/admin/ultron-studio/integrations/canaries/{uuid}/activate (sin cuerpo): otra persona que quien registró */
    public function activate(Request $request, string $uuid, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $canaries->activate($access, $request, $uuid)]);
    }

    /** POST /api/admin/ultron-studio/integrations/canaries/{uuid}/deactivate   {reason 5–500} */
    public function deactivate(Request $request, string $uuid, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate(['reason' => self::reasonRules()]);

        return response()->json(['ok' => true, 'data' => $canaries->deactivate($access, $request, $uuid, $data['reason'])]);
    }

    /** POST /api/admin/ultron-studio/integrations/canaries/{uuid}/revoke   {reason 5–500}: el cifrado se borra en la misma sentencia */
    public function revoke(Request $request, string $uuid, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate(['reason' => self::reasonRules()]);

        return response()->json(['ok' => true, 'data' => $canaries->revoke($access, $request, $uuid, $data['reason'])]);
    }

    /** POST /api/admin/ultron-studio/integrations/canaries/{uuid}/expectation   {versionUuid: uuid|null} — expectativa, nunca autoridad */
    public function expectation(Request $request, string $uuid, StudioCanaryNumbers $canaries): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate(['versionUuid' => ['present', 'nullable', 'uuid']]);

        return response()->json(['ok' => true, 'data' => $canaries->setExpectation($access, $request, $uuid, $data['versionUuid'])]);
    }

    /** @return list<mixed> */
    private static function reasonRules(): array
    {
        // AS-1 (Enmienda 3): el motivo queda para siempre en la fila y en el libro: sin teléfonos ni correos.
        return ['required', 'string', 'min:'.StudioCanaryNumbers::REASON_MIN, 'max:'.StudioCanaryNumbers::REASON_MAX, new PlainText(multiline: true), StudioCanaryPhone::contactDataRule()];
    }

    /**
     * El teléfono, con {@see StudioCanaryPhone::parse()}: uno de los tres
     * textos fijos, que no repiten lo que se escribió. Con un canal que no
     * existe no se interpreta (ya falla `channel`).
     */
    private static function phoneRule(Request $request): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($request): void {
            $canal = $request->input('channel');
            if (! is_string($value) || ! is_string($canal) || ! in_array($canal, StudioCanaryPhone::CHANNELS, true)) {
                return;
            }
            $leido = StudioCanaryPhone::parse($value, $canal, StudioIntegrations::countryCodes());
            if ($leido['ok'] !== true) {
                $fail(StudioCanaryPhone::message($leido['error'], $value, $canal));
            }
        };
    }

    private static function aliasRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && StudioCanaryPhone::aliasError($value) !== null) {
                $fail(StudioCanaryPhone::ALIAS_MESSAGE);
            }
        };
    }
}
