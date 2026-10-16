<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Services\Identity\MemberOwnershipLink;
use App\Services\OtpService;
use App\Services\Workforce\StaffRecord;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vincular la ficha de socio de una persona del personal, con su código SMS.
 * Contrato §7.2 (d) y (e).
 *
 * `workforce.manage` lo pide el mapa central (dominio `workforce`); además
 * hace falta `members.edit`, que se comprueba aquí porque lo que se escribe es
 * la ficha de un socio (mismo patrón que el código de respaldo de clases).
 */
class WorkforceMemberLinkController extends Controller
{
    public function __construct(
        private readonly StaffRecord $records,
        private readonly MemberOwnershipLink $links,
        private readonly OtpService $otp,
    ) {}

    /** Paso 1: código al teléfono registrado del socio. */
    public function start(Request $request, string $identity): JsonResponse
    {
        if ($denied = $this->deniedWithoutMembersEdit($request)) {
            return $denied;
        }
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'min:1'],
        ]);
        $person = $this->records->resolve($identity);

        $result = $this->links->start($person, (int) $data['member_id'], $request);
        if ($result['result'] === MemberOwnershipLink::RESULT_ALREADY_LINKED) {
            return response()->json(['ok' => true, 'result' => $result['result'], 'data' => $this->record($request, $person)]);
        }

        $challenge = $result['challenge'];
        $payload = [
            'ok' => true,
            'challenge_id' => $challenge->uuid,
            'destination' => MemberOwnershipLink::maskPhone($challenge->destination),
            // Lo que le queda de verdad: un código reutilizado no tiene 300 s.
            'expires_in' => (int) max(0, ceil(now()->diffInSeconds($challenge->expires_at))),
            'reused' => $result['reused'],
        ];
        // Solo con el driver `dev` fuera de producción (OtpService::exposeCode).
        if ($this->otp->exposeCode() && $result['code'] !== '') {
            $payload['dev_code'] = $result['code'];
        } elseif (! $result['sent']) {
            $payload['delivery_warning'] = 'No pudimos confirmar el envío del SMS. Espera un minuto y pide otro código.';
        }

        return response()->json($payload, 201);
    }

    /** Paso 2: el código que la persona dicta. */
    public function confirm(Request $request, string $identity): JsonResponse
    {
        if ($denied = $this->deniedWithoutMembersEdit($request)) {
            return $denied;
        }
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'min:1'],
            // uuid: en PostgreSQL un texto cualquiera contra la columna uuid
            // sería un error del motor (500), no un «no encontrado».
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        $person = $this->records->resolve($identity);

        $result = $this->links->confirm($person, (int) $data['member_id'], $data['challenge_id'], $data['code'], $request);

        return response()->json(['ok' => true, 'result' => $result, 'data' => $this->record($request, $person)]);
    }

    private function deniedWithoutMembersEdit(Request $request): ?JsonResponse
    {
        if (CrmPermission::allows(AdminActor::from($request), 'members.edit')) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'Vincular la ficha de socio exige además el permiso de editar miembros.',
            'required_permission' => 'members.edit',
        ], 403);
    }

    private function record(Request $request, Identity $person): array
    {
        $admin = AdminActor::from($request);

        return $this->records->record(
            $person->refresh(),
            withPhone: CrmPermission::allows($admin, 'workforce.manage'),
            withMember: CrmPermission::allows($admin, 'members.view'),
        );
    }
}
