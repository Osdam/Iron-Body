<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerProfessionalResource;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\TrainerAuditService;
use App\Services\Trainer\TrainerFaceService;
use App\Services\Trainer\TrainerOtpService;
use App\Services\Trainer\TrainerSessionService;
use App\Services\Workforce\FaceReferenceService;
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
        private readonly TrainerFaceService $face,
        private readonly FaceReferenceService $faceReferences,
    ) {}

    // ── Login facial en tablet (kiosko) ──────────────────────────────────────

    /**
     * Enrolamiento desde el portal (app 2.0.x): el embedding se calcula en el
     * dispositivo y aquí se guarda como PLANTILLA DE LA PERSONA, cifrada.
     *
     * Ya no sobrescribe: con una plantilla activa responde 409
     * `face_already_enrolled`, y una revocada exige la autorización de un
     * administrador (kiosko con código). Antes cualquiera con una sesión de
     * entrenador —que hoy se obtiene con el documento— podía cambiar el rostro
     * de esa persona por el suyo. Ver docs/asistencia-laboral/CONTRATO.md §6.3.
     */
    public function enrollFace(Request $request): JsonResponse
    {
        $trainer = $request->attributes->get('auth_trainer');

        $data = $request->validate([
            'embedding' => ['required', 'array'],
        ]);

        try {
            $this->faceReferences->enrollLegacyTrainer($trainer, $data['embedding'], $request);
        } catch (WorkforceException $e) {
            if ($e->code_ === 'embedding_invalid') {
                return response()->json([
                    'ok' => false,
                    'code' => 'invalid_embedding',
                    'message' => 'No pudimos procesar el rostro. Intenta de nuevo.',
                ], 422);
            }
            throw $e;
        }

        $this->audit->record(
            TrainerAuditLog::EVENT_LOGIN,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['face_enrolled' => true],
            request: $request,
        );

        return response()->json(['ok' => true, 'enrolled' => true]);
    }

    /** Lista de entrenadores activos con rostro enrolado (selector de tablet). */
    public function faceRoster(): JsonResponse
    {
        abort_unless((bool) config('trainer.face.login_enabled', false), 404, 'Recurso no disponible.');

        return response()->json(['ok' => true, 'data' => $this->face->roster()]);
    }

    /**
     * Login facial: el entrenador eligió su nombre y la cámara capturó su rostro;
     * el embedding vivo se compara contra la referencia EN EL BACKEND. Si coincide,
     * emite la sesión (sin OTP). Gated por `TRAINER_FACE_LOGIN_ENABLED`.
     */
    public function faceLogin(Request $request): JsonResponse
    {
        abort_unless((bool) config('trainer.face.login_enabled', false), 404, 'Recurso no disponible.');

        $data = $request->validate([
            'trainer_id' => ['required', 'integer'],
            'embedding' => ['required', 'array'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $trainer = Trainer::query()
            ->where('id', $data['trainer_id'])
            ->whereIn('status', ['active', 'activo'])
            ->first();

        // Mensaje uniforme para no distinguir "no existe" de "no coincide".
        $genericFail = response()->json([
            'ok' => false,
            'code' => 'face_no_match',
            'message' => 'El rostro no coincide. Intenta de nuevo o usa el código.',
        ], 422);

        if (! $trainer) {
            return $genericFail;
        }

        // Comparación en el servidor con las defensas del kiosko: vector de
        // norma 1 (un vector casi nulo coincidía con cualquiera), plantilla
        // activa (una revocada no entra) e intentos limitados.
        //
        // El contador es SOLO de esta vía, por persona y sin la IP:
        // - sin la IP, rotar direcciones (un /64 de IPv6 son millones) no da
        //   intentos infinitos: 5 por persona cada 15 min, venga de donde venga;
        // - solo de esta vía, porque el endpoint es público y el trainer_id
        //   secuencial: con el contador del kiosko, cualquiera dejaría sin
        //   rostro en la tablet (jornada y clases) a todos los entrenadores.
        // Lo peor que se consigue es bloquear el login facial PÚBLICO de alguien
        // un rato; sigue entrando con su documento y la tablet no se entera.
        try {
            $key = 'portal-login:'.($trainer->identity_id ? 'identity:'.$trainer->identity_id : 'trainer:'.$trainer->getKey());
            $distance = $this->faceReferences->verify($this->faceReferences->referenceForTrainer($trainer), $data['embedding'], $key, $request);
        } catch (WorkforceException $e) {
            $this->audit->record(
                TrainerAuditLog::EVENT_LOGIN,
                $trainer,
                actorType: TrainerAuditLog::ACTOR_TRAINER,
                metadata: ['face_login' => true, 'matched' => false, 'reason' => $e->code_],
                request: $request,
            );

            return $e->code_ === 'face_attempts_exceeded' ? $e->render() : $genericFail;
        }

        $issued = $this->sessions->issueSession($trainer, $this->context($request));
        $trainer->load('roleAssignments');

        $this->audit->record(
            TrainerAuditLog::EVENT_LOGIN,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['session' => $issued['session']->uuid, 'face_login' => true, 'matched' => true, 'distance' => round($distance, 4)],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'token' => $issued['token'],
            'trainer' => new TrainerProfessionalResource($trainer),
        ]);
    }

    public function access(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document' => ['required', 'string', 'max:50'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
        ]);

        $trainer = $this->resolveActiveTrainer($data['document']);

        // Dispositivo de confianza: si este equipo ya verificó por OTP a este
        // entrenador dentro de la ventana, entra SIN OTP (no gasta SMS) y sin
        // volver a pedir 2FA tras cerrar sesión. Solo dispara para un entrenador
        // real con una sesión de confianza previa en ESTE device_id; un equipo
        // sin confianza cae al flujo OTP uniforme (no filtra existencia).
        if ($trainer && (bool) config('trainer.trusted_device.enabled', true)) {
            $trusted = $this->sessions->trustedSessionForDevice(
                $trainer,
                $data['device_id'] ?? null,
                (int) config('trainer.trusted_device.ttl_days', 30),
            );

            if ($trusted) {
                $issued = $this->sessions->issueSession($trainer, $this->context($request));
                $trainer->load('roleAssignments');

                $this->audit->record(
                    TrainerAuditLog::EVENT_LOGIN,
                    $trainer,
                    actorType: TrainerAuditLog::ACTOR_TRAINER,
                    metadata: ['session' => $issued['session']->uuid, 'trusted_device' => true],
                    request: $request,
                );

                return response()->json([
                    'ok' => true,
                    'trusted' => true,
                    'token' => $issued['token'],
                    'trainer' => new TrainerProfessionalResource($trainer),
                ]);
            }
        }

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

    /**
     * Acceso de PRUEBAS sin OTP (gated por `TRAINER_OTP_DEV_BYPASS`). Emite la
     * sesión de un entrenador ACTIVO directamente, sin enviar ni validar código.
     * Pensado SOLO para QA/desarrollo: con el flag apagado responde 404 (igual que
     * una función deshabilitada, sin filtrar nada). Cada acceso queda auditado.
     */
    public function devLogin(Request $request): JsonResponse
    {
        abort_unless((bool) config('trainer.otp_dev_bypass', false), 404, 'Recurso no disponible.');

        $data = $request->validate([
            'document' => ['required', 'string', 'max:50'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $trainer = $this->resolveActiveTrainer($data['document']);
        // Transición (2026-10-20): la cédula sola solo entra desde un equipo
        // que ESE entrenador ya usaba. El `device_id` lo genera la app al azar y
        // lo guarda cifrado: quien sabe una cédula no lo tiene. Un id de kiosko
        // o generado por el servidor no cuenta. Misma respuesta si no hay
        // entrenador: no se revela qué cédulas lo son.
        if (! $trainer || ! $this->sessions->knownDevice($trainer, $this->context($request)['device_id'])) {
            return response()->json([
                'ok' => false,
                'code' => 'device_not_recognized',
                'message' => 'Este equipo no está reconocido para entrar solo con el documento. Actualiza la aplicación para entrar con el código que llega por SMS.',
            ], 403);
        }

        $issued = $this->sessions->issueSession($trainer, $this->context($request));
        $trainer->load('roleAssignments');

        $this->audit->record(
            TrainerAuditLog::EVENT_LOGIN,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['session' => $issued['session']->uuid, 'dev_bypass' => true],
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

        // Rota el token sobre el MISMO dispositivo de la sesión actual. Una
        // sesión de kiosko sigue siéndolo: ni gana confianza ni deja de caducar.
        $issued = $this->sessions->issueSession($trainer, array_merge(
            $this->context($request),
            ['device_id' => $session->device_id],
        ), trusted: ! $session->isEphemeral(), expiresAt: $session->expires_at);
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

    /**
     * Cierra la sesión del portal EN EL SERVIDOR. En una tablet compartida no
     * basta con borrar el token del equipo: si se copió, seguiría valiendo.
     */
    public function logout(Request $request): JsonResponse
    {
        $trainer = $request->attributes->get('auth_trainer');
        $session = $request->attributes->get('auth_trainer_session');
        $this->sessions->revoke($session, 'logout');

        $this->audit->record(
            TrainerAuditLog::EVENT_SESSION_REVOKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: ['session' => $session->uuid, 'reason' => 'logout'],
            request: $request,
        );

        return response()->json(['ok' => true]);
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
        // `kiosk:` es el espacio de nombres de las sesiones que emite el
        // kiosko. Un id DECLARADO por el cliente nunca puede caer ahí: pisaría
        // la sesión de la tablet (updateOrCreate por trainer + device_id).
        $deviceId = $request->input('device_id');
        if (is_string($deviceId) && str_starts_with($deviceId, 'kiosk:')) {
            $deviceId = 'client:'.$deviceId;
        }

        return [
            'device_id' => $deviceId,
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
