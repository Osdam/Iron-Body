<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerProfessionalResource;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\TrainerAuditService;
use App\Services\Trainer\TrainerOtpService;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Acceso al portal profesional por OTP. Flujo:
 *   access (documento → OTP al teléfono del CRM) → verify (código → sesión) .
 *
 * Anti-enumeración: `access` responde SIEMPRE de forma uniforme, exista o no un
 * perfil profesional activo. El OTP solo confirma posesión del teléfono; el rol
 * y los permisos los decide el backend (no el OTP, no Flutter).
 */
class TrainerAuthController extends Controller
{
    private const GENERIC_ACCESS_MESSAGE = 'Si el documento corresponde a un entrenador activo, enviamos un código a su teléfono registrado.';

    public function __construct(
        private readonly TrainerOtpService $otp,
        private readonly TrainerSessionService $sessions,
        private readonly IdentityLinkService $identities,
        private readonly TrainerAuditService $audit,
    ) {}

    public function access(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document' => ['required', 'string', 'max:50'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
        ]);

        $trainer = $this->resolveActiveTrainer($data['document']);

        // Respuesta uniforme. Sin entrenador activo (o sin teléfono) devolvemos
        // un challenge "señuelo" que jamás validará, para no filtrar existencia.
        if (! $trainer || $this->otp->resolvePhone($trainer) === null) {
            return response()->json([
                'ok' => true,
                'challenge_id' => (string) Str::uuid(),
                'message' => self::GENERIC_ACCESS_MESSAGE,
            ]);
        }

        $result = $this->otp->startChallenge($trainer, $this->context($request));

        $this->audit->record(
            TrainerAuditLog::EVENT_OTP_REQUESTED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['challenge' => $result['challenge']->uuid, 'sent' => $result['sent']],
            request: $request,
        );

        $payload = [
            'ok' => true,
            'challenge_id' => $result['challenge']->uuid,
            'message' => self::GENERIC_ACCESS_MESSAGE,
        ];

        // Solo en dev (driver dev): destino enmascarado y código, para pruebas.
        if ($this->otp->exposeCode()) {
            $payload['masked_destination'] = $result['challenge']->maskedDestination();
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'max:12'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $challenge = $this->otp->verify($data['challenge_id'], $data['code']);
        } catch (OtpException $e) {
            return $this->otpError($e);
        }

        $trainer = $challenge->trainer;
        if (! $trainer || ! $trainer->isActive()) {
            // El entrenador fue desactivado entre el envío y la verificación.
            return response()->json([
                'ok' => false,
                'code' => 'trainer_inactive',
                'message' => 'Tu acceso profesional no está disponible.',
            ], 403);
        }

        $issued = $this->sessions->issueSession($trainer, $this->context($request));
        $trainer->load('roleAssignments');

        $this->audit->record(
            TrainerAuditLog::EVENT_LOGIN,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['session' => $issued['session']->uuid, 'new_device' => $issued['was_new_device']],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'token' => $issued['token'],
            'trainer' => new TrainerProfessionalResource($trainer),
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
        ]);

        try {
            $result = $this->otp->resend($data['challenge_id']);
        } catch (OtpException $e) {
            return $this->otpError($e);
        }

        $payload = [
            'ok' => true,
            'message' => 'Reenviamos el código.',
        ];

        if ($this->otp->exposeCode()) {
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    /** Perfil profesional de la sesión actual (prueba de que el token funciona). */
    public function me(Request $request): JsonResponse
    {
        $trainer = $request->attributes->get('auth_trainer');
        $trainer->load('roleAssignments');

        return response()->json([
            'ok' => true,
            'trainer' => new TrainerProfessionalResource($trainer),
        ]);
    }

    /**
     * Fuente de verdad del portal: perfiles vigentes, permisos y espacios
     * autorizados para esta identidad. Flutter enruta según esto, nunca según un
     * estado local. La preferencia de espacio por dispositivo vive en el cliente
     * (almacenamiento seguro); el backend solo dice qué espacios son válidos.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $trainer = $request->attributes->get('auth_trainer');
        $trainer->load('roleAssignments');

        $workspaces = ['trainer'];
        if ($trainer->identity_id !== null && $trainer->identity?->hasMemberProfile()) {
            $workspaces[] = 'member';
        }

        return response()->json([
            'ok' => true,
            'identity_id' => $trainer->identity_id,
            'workspaces' => $workspaces,
            'trainer' => new TrainerProfessionalResource($trainer),
        ]);
    }

    /**
     * Desbloqueo biométrico del portal: la biometría se valida EN EL DISPOSITIVO
     * y desbloquea la credencial guardada; aquí se ROTA el token de sesión
     * (revoca el anterior, emite uno nuevo) sin volver a pedir OTP, siempre que
     * la sesión siga viva y el entrenador activo. Requiere sesión vigente.
     */
    public function biometricUnlock(Request $request): JsonResponse
    {
        $trainer = $request->attributes->get('auth_trainer');
        $session = $request->attributes->get('auth_trainer_session');

        // Rota el token sobre el MISMO dispositivo de la sesión actual.
        $issued = $this->sessions->issueSession($trainer, array_merge(
            $this->context($request),
            ['device_id' => $session->device_id],
        ));
        $trainer->load('roleAssignments');

        $this->audit->record(
            TrainerAuditLog::EVENT_LOGIN,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['session' => $issued['session']->uuid, 'biometric' => true],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'token' => $issued['token'],
            'trainer' => new TrainerProfessionalResource($trainer),
        ]);
    }

    private function resolveActiveTrainer(string $document): ?Trainer
    {
        $identity = $this->identities->findByDocument($document);
        if (! $identity) {
            return null;
        }

        return $identity->trainers()
            ->whereIn('status', ['active', 'activo'])
            ->first();
    }

    private function context(Request $request): array
    {
        return [
            'device_id' => $request->input('device_id'),
            'device_name' => $request->input('device_name'),
            'platform' => $request->input('platform'),
            'app_version' => $request->input('app_version'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    private function otpError(OtpException $e): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => 'otp_error',
            'message' => $e->getMessage(),
        ], $e->extra), $e->status);
    }
}
