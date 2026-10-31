<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Models\MembershipTransfer;
use App\Models\User;
use App\Services\Membership\MembershipTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TRANSFERIR MEMBRESÍA desde la ficha del socio (Miembros → Ver detalles →
 * Membresía). Ver {@see MembershipTransfers} y
 * docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md §6.
 *
 * Vista previa, transferir y revertir exigen `members.transfer_membership`,
 * declarado ruta por ruta en AuthorizationMap::OVERRIDES (el controlador NO
 * está en CONTROLLERS: por controlador, la vista previa resolvería a
 * `members.view`, que tiene recepción y el token compartido). El historial de
 * la ficha va con `members.view`: es la única forma de que el Administrador vea
 * la traza, porque `audit.view` no es suyo.
 *
 * Y además una PERSONA: el traspaso queda firmado por quien lo hizo, y una
 * credencial sin nadie detrás no puede firmar (AuditTrail no escribiría).
 */
class MembershipTransferController extends Controller
{
    /** GET /api/users/{user}/membership-transfer/preview?beneficiary_id= */
    public function preview(Request $request, User $user, MembershipTransfers $transfers): JsonResponse
    {
        if ($sinPersona = PaymentController::requirePerson($request)) {
            return $sinPersona;
        }
        $data = $request->validate([
            'beneficiary_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
        ]);

        $beneficiario = isset($data['beneficiary_id']) ? User::find((int) $data['beneficiary_id']) : null;

        return response()->json($transfers->preview($user, $beneficiario));
    }

    /**
     * POST /api/users/{user}/membership-transfer
     *
     * 201 con el traspaso hecho; 200 con `idempotent_replay` si esta misma
     * petición ya se había atendido (doble clic, reintento de red); 409
     * `transfer_blocked` con todos los motivos si la revalidación bajo bloqueo
     * no lo admite.
     */
    public function store(Request $request, User $user, MembershipTransfers $transfers): JsonResponse
    {
        if ($sinPersona = PaymentController::requirePerson($request)) {
            return $sinPersona;
        }
        $data = $request->validate([
            'beneficiary_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            // Los dos consentimientos son obligatorios y tienen que ser un SÍ
            // explícito: el traspaso toca la membresía de dos personas.
            'holder_consent' => ['required', 'accepted'],
            'beneficiary_acceptance' => ['required', 'accepted'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'client_request_id' => ['required', 'string', 'max:100'],
        ], [
            'holder_consent.accepted' => 'Falta la autorización expresa de la titular.',
            'holder_consent.required' => 'Falta la autorización expresa de la titular.',
            'beneficiary_acceptance.accepted' => 'Falta la aceptación del beneficiario.',
            'beneficiary_acceptance.required' => 'Falta la aceptación del beneficiario.',
        ]);

        $r = $transfers->transfer($request, $user, (int) $data['beneficiary_id'], [
            'reason' => trim($data['reason']),
            'evidence' => isset($data['evidence']) ? (trim($data['evidence']) ?: null) : null,
            'client_request_id' => $data['client_request_id'],
        ]);
        $t = $r['transfer'];

        return response()->json([
            'ok' => true,
            'idempotent_replay' => $r['replayed'],
            'transfer' => $transfers->toArray($t),
            'holder' => $this->membershipOf(User::find($t->holder_user_id)),
            'beneficiary' => $this->membershipOf(User::find($t->beneficiary_user_id)),
        ], $r['replayed'] ? 200 : 201);
    }

    /** POST /api/membership-transfers/{transfer}/revert */
    public function revert(Request $request, MembershipTransfer $transfer, MembershipTransfers $transfers): JsonResponse
    {
        if ($sinPersona = PaymentController::requirePerson($request)) {
            return $sinPersona;
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $r = $transfers->revert($request, $transfer, trim($data['reason']));

        return response()->json([
            'ok' => true,
            'already' => $r['already'],
            'transfer' => $transfers->toArray($r['transfer']),
            'holder' => $this->membershipOf($r['transfer']->holder_user_id ? User::find($r['transfer']->holder_user_id) : null),
            'beneficiary' => $this->membershipOf($r['transfer']->beneficiary_user_id ? User::find($r['transfer']->beneficiary_user_id) : null),
        ]);
    }

    /** GET /api/users/{user}/membership-transfers — cedidos y recibidos, para la ficha. */
    public function index(Request $request, User $user, MembershipTransfers $transfers): JsonResponse
    {
        // Motivo y evidencia de dos personas: lo lee una persona con sesión,
        // no el token compartido de automatizaciones (que sí tiene members.view).
        if ($sinPersona = PaymentController::requirePerson($request)) {
            return $sinPersona;
        }

        return response()->json(['ok' => true, 'data' => $transfers->forUser($user)]);
    }

    /** Lo que el CRM repinta de cada socio, con las mismas claves que congelar. */
    private function membershipOf(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'status' => $user->status,
            'plan' => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate' => $user->membershipEndDate,
        ];
    }
}
