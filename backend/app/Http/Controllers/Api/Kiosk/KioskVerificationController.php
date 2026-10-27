<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerProfessionalResource;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\TrainerAuditLog;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Trainer\TrainerAuditService;
use App\Services\Trainer\TrainerSessionService;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\EmployeeProfile;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\KioskTokens;
use App\Services\Workforce\WorkdayService;
use App\Support\TrainerFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificar a la persona (contrato §3): su rostro comparado AQUÍ contra su
 * plantilla, o un código de respaldo que generó un administrador.
 *
 * El resultado es una verificación de un solo uso para registrar jornada, o
 * —camino «Entrenadores»— la sesión del portal profesional emitida al
 * dispositivo autorizado.
 */
class KioskVerificationController extends Controller
{
    public function __construct(
        private readonly KioskTokens $tokens,
        private readonly EmployeeDirectory $directory,
        private readonly FaceReferenceService $faces,
        private readonly AuthorizationCodeService $codes,
        private readonly WorkdayService $workdays,
        private readonly TrainerSessionService $sessions,
        private readonly TrainerAuditService $audit,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ref' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'in:workday,trainer_session'],
            'embedding' => ['required', 'array'],
            // Segundo factor cuando el rostro no basta (contrato §10.1).
            'pin' => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ]);
        /** @var AuthorizedDevice $device */
        $device = $request->attributes->get('auth_device');
        [$identity, $profile] = $this->person($data['employee_ref'], $device);

        // Interruptor de incidente de esta vía (rostro → portal), propio desde
        // 2026-10-20: el del login facial público ya no la apaga. La jornada no
        // depende de él (I2).
        if ($data['purpose'] === 'trainer_session' && ! (bool) config('workforce.kiosk.trainer_portal_face', true)) {
            throw new WorkforceException('El acceso de entrenadores en esta tablet está desactivado por administración. Puedes consultar tus clases en tu teléfono.', 'trainer_face_login_disabled', 403);
        }

        $match = $this->faces->verify(
            $this->faces->referenceForIdentity($identity),
            $data['embedding'],
            // Contador propio del kiosko (solo lo alcanza una tablet vinculada):
            // fallar en el login público o en el teléfono no lo agota.
            'kiosk:identity:'.$identity->getKey(),
            $request,
            $data['pin'] ?? null,
        );

        return $this->verified($request, $identity, $profile, $device, $data['purpose'], $match->method(), $match->distance, null);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ref' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'in:workday,trainer_session'],
            'code' => ['required', 'string', 'max:20'],
        ]);
        /** @var AuthorizedDevice $device */
        $device = $request->attributes->get('auth_device');
        [$identity, $profile] = $this->person($data['employee_ref'], $device);

        // El portal del entrenador en la tablet existe para operar clases, y
        // una clase solo se opera con rostro: el código de clases no abre nada.
        if ($data['purpose'] === 'trainer_session' && ! (bool) config('workforce.classes.fallback_code', false)) {
            throw WorkforceException::classFallbackDisabled();
        }

        $code = $this->codes->consume(
            $identity,
            $data['purpose'] === 'workday' ? WorkforceAuthorizationCode::PURPOSE_FALLBACK_WORKDAY : WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS,
            $data['code'],
            $device->getKey(),
        );

        return $this->verified($request, $identity, $profile, $device, $data['purpose'], 'admin_code', null, $code->getKey());
    }

    /** @return array{0: Identity, 1: EmployeeProfile} */
    private function person(string $ref, AuthorizedDevice $device): array
    {
        $identity = $this->tokens->resolveRef($ref, $device);
        $profile = $this->directory->profile($identity);
        if (! $profile->isActive()) {
            throw WorkforceException::employeeNotFound();
        }

        return [$identity, $profile];
    }

    private function verified(Request $request, Identity $identity, EmployeeProfile $profile, AuthorizedDevice $device, string $purpose, string $method, ?float $distance, ?int $codeId): JsonResponse
    {
        $employee = [
            'display_name' => $profile->fullName,
            'positions' => array_map(fn (array $p) => ['key' => $p['key'], 'label' => $p['label']], $profile->describedPositions()),
            'capabilities' => $profile->capabilities(),
        ];

        if ($purpose === 'trainer_session') {
            $trainer = $profile->trainer;
            if ($trainer === null || ! TrainerFeatures::enabled('trainer_auth_enabled', $identity)) {
                throw WorkforceException::notATrainer();
            }
            $issued = $this->sessions->issueSession($trainer, [
                'device_id' => 'kiosk:'.$device->uuid,
                'device_name' => $device->name,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], trusted: false, expiresAt: now()->addHours((int) config('workforce.kiosk.trainer_session_hours', 12)));
            $trainer->load('roleAssignments');
            $this->audit->record(TrainerAuditLog::EVENT_LOGIN, $trainer, actorType: TrainerAuditLog::ACTOR_TRAINER, metadata: [
                'session' => $issued['session']->uuid,
                'kiosk' => true,
                'method' => $method,
                'device' => $device->uuid,
            ], request: $request);

            return response()->json([
                'ok' => true,
                'employee' => $employee,
                'trainer_session' => [
                    'token' => $issued['token'],
                    'trainer' => new TrainerProfessionalResource($trainer),
                ],
            ]);
        }

        $verification = $this->tokens->issueVerification($identity, $device, 'workday', $method, $distance, $codeId);
        $state = $this->workdays->state($identity);

        return response()->json([
            'ok' => true,
            'verification' => [
                'token' => $verification['token'],
                'expires_at' => $verification['expires_at']->toIso8601String(),
                'method' => $method,
            ],
            'employee' => $employee,
            'workday' => $state,
            'allowed_actions' => $this->workdays->allowedActions($profile, $state),
        ]);
    }
}
