<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategyConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: los VÍNCULOS CUENTA → PERSONA de los cuatro ojos por persona
 * (contrato H9, D9-11.2, D9-13, §7 #26–#29). Solo con
 * `ultron.person_bindings_manage` (Super Admin por defecto); nunca el propio.
 * Sin documento ni teléfono: la persona sale como `identityRef` (huella) y el
 * nombre de su perfil laboral. Las dos primeras personas se vinculan por
 * consola (`ultron-studio:person-bindings --bootstrap`).
 */
class UltronStudioStrategyPersonBindingController extends Controller
{
    use ResolvesPagination;

    /** GET strategies/person-bindings?status&page&per_page (≤ 50) */
    public function index(Request $request): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in([StudioPersonBindings::STATUS_PENDING, StudioPersonBindings::STATUS_VERIFIED, StudioPersonBindings::STATUS_REVOKED])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(['ok' => true, 'data' => StudioPersonBindings::page($access, $data['status'] ?? null, $this->resolvePerPage($request, 20, StudioPersonBindings::PER_PAGE_MAX)),
            'meta' => ['person' => ['bound' => StudioPersonBindings::personOf($access->id()) !== null]]]);
    }

    /** POST strategies/person-bindings   {clientRequestId, adminId, identityUuid | staffProfileUuid}: para OTRA cuenta; queda pendiente de verificar */
    public function store(Request $request, StudioPersonBindings $bindings): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'adminId' => ['required', 'integer', 'min:1'],
            'identityUuid' => ['nullable', 'uuid', 'required_without:staffProfileUuid', 'prohibits:staffProfileUuid'],
            'staffProfileUuid' => ['nullable', 'uuid', 'required_without:identityUuid'],
        ]);
        $r = $bindings->create($access, $request, $data['clientRequestId'], (int) $data['adminId'], $data['identityUuid'] ?? null, $data['staffProfileUuid'] ?? null);
        $dto = StudioPersonBindings::dto($r['binding']);

        return $r['replayed'] ? response()->json(['ok' => true, 'replayed' => true, 'data' => $dto]) : response()->json(['ok' => true, 'data' => $dto], 201);
    }

    /** POST strategies/person-bindings/{uuid}/verify: otra persona verificada, distinta de la vinculada y de quien lo creó */
    public function verify(Request $request, StudioPersonBindings $bindings, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();

        return response()->json(['ok' => true, 'data' => StudioPersonBindings::dto($bindings->verify($access, $request, $uuid))]);
    }

    /** POST strategies/person-bindings/{uuid}/revoke   {reason 5–500}: una vez, nunca el propio */
    public function revoke(Request $request, StudioPersonBindings $bindings, string $uuid): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        StudioStrategyConfig::assertEnabled();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:'.StudioPersonBindings::REASON_MIN, 'max:'.StudioPersonBindings::REASON_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => StudioPersonBindings::dto($bindings->revoke($access, $request, $uuid, $data['reason']))]);
    }
}
