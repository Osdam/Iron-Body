<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\TrainerFaceReference;
use App\Models\WorkforceAuthorizationCode;
use App\Models\WorkforceKioskToken;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\KioskTokens;
use App\Services\Workforce\WorkdayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Enrolamiento facial autorizado (contrato §5).
 *
 *  1. start: la persona dicta el código que le dio un administrador. El
 *     documento NUNCA basta: identifica, no prueba.
 *  2. complete: consentimiento + tres capturas coherentes; el servidor
 *     comprueba que ese rostro no sea ya de otra persona, guarda la plantilla
 *     cifrada y devuelve una verificación para registrar la entrada en el mismo
 *     flujo, sin volver al inicio.
 *
 * Si algo falla en las capturas, el código aceptado sigue valiendo hasta que
 * caduque: repetir las fotos no obliga a pedir otro código.
 */
class KioskEnrollmentController extends Controller
{
    public function __construct(
        private readonly KioskTokens $tokens,
        private readonly EmployeeDirectory $directory,
        private readonly AuthorizationCodeService $codes,
        private readonly FaceReferenceService $faces,
        private readonly WorkdayService $workdays,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ref' => ['required', 'string', 'max:2000'],
            'authorization_code' => ['required', 'string', 'max:20'],
        ]);
        /** @var AuthorizedDevice $device */
        $device = $request->attributes->get('auth_device');
        $identity = $this->tokens->resolveRef($data['employee_ref'], $device);
        if (! $this->directory->profile($identity)->isActive()) {
            throw WorkforceException::employeeNotFound();
        }

        $code = $this->codes->consume($identity, WorkforceAuthorizationCode::PURPOSE_ENROLLMENT, $data['authorization_code'], $device->getKey());
        $enrollment = $this->tokens->issueEnrollment($identity, $device, $code->getKey());

        return response()->json([
            'ok' => true,
            'enrollment' => ['token' => $enrollment['token'], 'expires_at' => $enrollment['expires_at']->toIso8601String()],
            'consent' => [
                'version' => config('workforce.consent.version'),
                'title' => config('workforce.consent.title'),
                'text' => config('workforce.consent.text'),
            ],
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:200'],
            'consent_version' => ['required', 'string', 'max:20'],
            'consent_accepted' => ['required', 'boolean'],
            'embeddings' => ['required', 'array', 'min:3', 'max:5'],
            'embeddings.*' => ['array'],
            // Aceptado por compatibilidad; el reintento lo reconoce el token.
            'operation_id' => ['nullable', 'uuid'],
        ]);
        /** @var AuthorizedDevice $device */
        $device = $request->attributes->get('auth_device');

        $token = $this->tokens->find($data['enrollment_token'], $device);
        if ($token === null || $token->kind !== WorkforceKioskToken::KIND_ENROLLMENT) {
            throw WorkforceException::enrollmentInvalid();
        }
        if ($token->consumed_at !== null) {
            return $this->replay($token, $device, $request);
        }
        if ($token->expires_at->isPast()) {
            throw WorkforceException::enrollmentInvalid();
        }
        if ($data['consent_accepted'] !== true || $data['consent_version'] !== config('workforce.consent.version')) {
            throw WorkforceException::consentRequired();
        }

        $identity = Identity::query()->findOrFail($token->identity_id);
        $code = $token->authorization_code_id ? WorkforceAuthorizationCode::find($token->authorization_code_id) : null;

        // Fuera de la transacción: un rechazo por rostro ajeno deja su traza.
        $this->faces->precheckEnrollment($identity, array_values($data['embeddings']), $request);

        DB::transaction(function () use ($data, $device, $identity, $code, $request): void {
            $this->tokens->consume($data['enrollment_token'], WorkforceKioskToken::KIND_ENROLLMENT, 'enrollment', $device);
            $this->faces->enroll($identity, array_values($data['embeddings']), [
                'device_id' => $device->getKey(),
                'admin_id' => $code?->created_by_admin_id,
                'admin_name' => $code?->created_by_name,
                'consent_version' => (string) $data['consent_version'],
            ], $request);
        });

        return $this->enrolled($identity, $device, 201);
    }

    /**
     * Reintento de un enrolamiento que SÍ se completó (la respuesta se perdió):
     * mientras el token no haya caducado y la plantilla sea la registrada desde
     * este dispositivo con él, se responde como entonces.
     */
    private function replay(WorkforceKioskToken $token, AuthorizedDevice $device, Request $request): JsonResponse
    {
        $identity = Identity::query()->find($token->identity_id);
        $ref = $identity ? $this->faces->referenceForIdentity($identity) : null;
        $fresh = $ref !== null && $ref->isActive()
            && $ref->enrolled_via === TrainerFaceReference::VIA_KIOSK
            && (int) $ref->enrolled_device_id === (int) $device->getKey()
            && $ref->enrolled_at !== null && $ref->enrolled_at->gte($token->created_at)
            && $token->expires_at->isFuture();

        if (! $fresh) {
            throw WorkforceException::enrollmentInvalid();
        }

        return $this->enrolled($identity, $device, 200);
    }

    private function enrolled(Identity $identity, AuthorizedDevice $device, int $status): JsonResponse
    {
        $profile = $this->directory->profile($identity);
        $ref = $this->faces->referenceForIdentity($identity);
        $verification = $this->tokens->issueVerification($identity, $device, 'workday', 'enrollment');
        $state = $this->workdays->state($identity);

        return response()->json([
            'ok' => true,
            'biometric' => ['status' => $this->faces->status($ref), 'enrolled_at' => $ref?->enrolled_at?->toIso8601String()],
            'verification' => [
                'token' => $verification['token'],
                'expires_at' => $verification['expires_at']->toIso8601String(),
                'method' => 'enrollment',
            ],
            'employee' => [
                'display_name' => $profile->fullName,
                'positions' => array_map(fn (array $p) => ['key' => $p['key'], 'label' => $p['label']], $profile->describedPositions()),
                'capabilities' => $profile->capabilities(),
            ],
            'workday' => $state,
            'allowed_actions' => $this->workdays->allowedActions($profile, $state),
        ], $status);
    }
}
