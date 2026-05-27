<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginMemberRequest;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberDeviceToken;
use App\Models\MemberSecurityEvent;
use App\Services\DeviceSessionService;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\SecurityEventService;
use App\Support\MemberPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Autenticación de miembros con verificación en dos pasos (OTP por SMS) y
 * control de sesiones por dispositivo.
 *
 * Flujo:
 *   1. POST members/login          → valida documento y dispara el OTP.
 *   2. POST members/login/verify   → valida el código y emite la sesión.
 *   3. POST members/biometric-unlock → reusa una sesión viva del dispositivo
 *      (Face ID / Touch ID / huella) sin pedir SMS de nuevo.
 *
 * El bearer real de la app pasa a ser el `session_token` del dispositivo; al
 * verificar un login nuevo se revocan las sesiones de los demás equipos
 * (anti-cuentas-compartidas).
 */
class AuthController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private DeviceSessionService $sessions,
        private SecurityEventService $security,
        private NotificationService $notifications,
    ) {
    }

    /** POST members/login — paso 1: documento → reto OTP (o acceso directo). */
    public function login(LoginMemberRequest $request): JsonResponse
    {
        $member = Member::query()
            ->with('user')
            ->where('document_number', $request->validated('document_number'))
            ->first();

        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Documento no encontrado.'], 404);
        }

        $context = $this->context($request);

        // Control de concurrencia: si la cuenta ya está activa en otro
        // dispositivo, se bloquea el ingreso (no se le roba la sesión al
        // dispositivo principal) y ni siquiera se envía el OTP.
        if ($active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null)) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        // Vínculo dispositivo↔cuenta: si el equipo ya está asociado a OTRO
        // miembro, se deniega ("cuenta asociada a otro usuario").
        if ($denied = $this->deviceBindingDenied($member, $context)) {
            return $denied;
        }

        // Sin teléfono registrado no se puede mandar SMS.
        if (! $this->otp->canChallenge($member)) {
            if (! config('otp.skip_when_no_phone', true)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Tu cuenta no tiene teléfono para la verificación. Contacta a recepción.',
                ], 422);
            }
            // Modo demo: entra sin OTP pero sí con control de dispositivo.
            return $this->grantSession($member, $context, otpVerified: false);
        }

        $result    = $this->otp->startChallenge($member, $context);
        $challenge = $result['challenge'];

        $data = [
            'requires_otp'    => true,
            'challenge_id'    => $challenge->uuid,
            'destination'     => $challenge->maskedDestination(),
            'expires_in'      => (int) config('otp.ttl', 300),
            'resend_cooldown' => (int) config('otp.resend_cooldown', 60),
            'channel'         => 'sms',
        ];

        if ($this->otp->exposeCode()) {
            $data['dev_code'] = $result['code'];
            $data['dev_note'] = 'Código visible sólo en modo desarrollo (OTP_DRIVER=dev).';
        } elseif (! $result['sent']) {
            $data['delivery_warning'] = 'No pudimos confirmar el envío del SMS. Puedes reenviar el código.';
        }

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** POST members/login/verify — paso 2: valida el código y emite la sesión. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code'         => ['required', 'string'],
        ]);

        $context = $this->context($request);

        try {
            $res = $this->otp->verify($validated['challenge_id'], $validated['code'], $context);
        } catch (OtpException $e) {
            return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
        }

        $member = $res['member'];

        // Re-chequeo de concurrencia (otro dispositivo pudo activarse durante el
        // tiempo del OTP): si la cuenta ya está en uso, bloquear el ingreso.
        if ($member && ($active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null))) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        // Segundo factor biométrico: si el miembro tiene rostro de referencia,
        // NO se emite la sesión aún; el dispositivo debe pasar el escaneo facial
        // (reconocimiento on-device contra la referencia). El `ticket` autoriza
        // los pasos face-reference/face-verify.
        if ($member && $this->faceRequiredFor($member)) {
            return response()->json([
                'ok'   => true,
                'data' => [
                    'requires_otp'  => false,
                    'requires_face' => true,
                    'ticket'        => $res['challenge']->uuid,
                    'face_ttl'      => (int) config('otp.face.ticket_ttl', 600),
                ],
            ]);
        }

        return $this->grantSession($member, $context, otpVerified: true);
    }

    /** POST members/login/face-reference — entrega la referencia facial (ticket). */
    public function faceReference(Request $request): JsonResponse
    {
        $validated = $request->validate(['ticket' => ['required', 'string']]);

        $challenge = $this->validFaceTicket($validated['ticket']);
        if (! $challenge) {
            return response()->json(['ok' => false, 'message' => 'La verificación expiró. Inicia sesión nuevamente.'], 410);
        }

        $member = $challenge->member;
        $member?->loadMissing('biometric');
        $bio = $member?->biometric;
        if (! $bio || ! $bio->face_path) {
            return response()->json(['ok' => false, 'message' => 'No hay referencia facial registrada.'], 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($bio->face_path)) {
            return response()->json(['ok' => false, 'message' => 'No se encontró la referencia facial.'], 404);
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'mime'  => $bio->face_mime ?: 'image/jpeg',
                'image' => base64_encode($disk->get($bio->face_path)),
            ],
        ]);
    }

    /** POST members/login/face-verify — recibe el veredicto on-device y decide. */
    public function faceVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket'  => ['required', 'string'],
            'matched' => ['required', 'boolean'],
            'score'   => ['nullable', 'numeric'],
        ]);

        $challenge = $this->validFaceTicket($validated['ticket']);
        if (! $challenge) {
            return response()->json(['ok' => false, 'message' => 'La verificación expiró. Inicia sesión nuevamente.'], 410);
        }

        $member  = $challenge->member;
        $context = $this->context($request);

        // Por si cambió el estado durante el escaneo.
        if ($member && ($active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null))) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        $matched = (bool) $validated['matched'];
        $score   = isset($validated['score']) ? (float) $validated['score'] : null;

        $selfiePath = null;
        if ($matched === false && config('otp.face.store_selfie', false) && $request->hasFile('selfie') && $member) {
            try {
                $selfiePath = $request->file('selfie')->store("members/{$member->member_uuid}/face_attempts", 'local');
            } catch (\Throwable) {
                $selfiePath = null;
            }
        }

        if (! $matched) {
            $challenge->increment('attempts');
            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_FACE_FAILED, $context, array_filter([
                    'score'  => $score,
                    'selfie' => $selfiePath,
                ]));
                $this->notifications->notifyFaceMismatch($member, $context['device_name'] ?? null, $context['device_id'] ?? null);
            }
            if ($challenge->attempts >= (int) config('otp.face.max_attempts', 3)) {
                $challenge->update(['status' => MemberAuthChallenge::STATUS_BLOCKED]);
            }

            return response()->json([
                'ok'      => false,
                'code'    => 'face_mismatch',
                'message' => 'Acceso denegado: cuenta asociada a otro usuario.',
            ], 403);
        }

        // El rostro coincide con el titular → emitir sesión.
        $challenge->update(['status' => MemberAuthChallenge::STATUS_COMPLETED]);
        if ($member) {
            $this->security->record($member, MemberSecurityEvent::TYPE_FACE_VERIFIED, $context, array_filter(['score' => $score]));
        }

        return $this->grantSession($member, $context, otpVerified: true, faceVerified: true);
    }

    /** POST members/login/resend — reenvía el código (cooldown + tope). */
    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string'],
        ]);

        $context = $this->context($request);

        try {
            $res = $this->otp->resend($validated['challenge_id'], $context);
        } catch (OtpException $e) {
            return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
        }

        $challenge = $res['challenge'];
        $data = [
            'requires_otp'    => true,
            'challenge_id'    => $challenge->uuid,
            'destination'     => $challenge->maskedDestination(),
            'expires_in'      => (int) config('otp.ttl', 300),
            'resend_cooldown' => (int) config('otp.resend_cooldown', 60),
        ];
        if ($this->otp->exposeCode()) {
            $data['dev_code'] = $res['code'];
        }

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** POST members/biometric-unlock — reusa la sesión viva del dispositivo. */
    public function biometricUnlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'     => ['required', 'string'],
            'session_token' => ['required', 'string'],
        ]);

        $context = $this->context($request);
        $session = $this->sessions->resolveByToken($validated['session_token']);

        if (! $session || $session->device_id !== $validated['device_id']) {
            return response()->json([
                'ok'             => false,
                'requires_login' => true,
                'message'        => 'Tu sesión expiró o se cerró desde otro dispositivo. Ingresa con tu documento.',
            ], 401);
        }

        $member = $session->member;
        $this->sessions->touch($session);
        $this->security->record($member, MemberSecurityEvent::TYPE_BIOMETRIC_UNLOCK, $context, [
            'device' => $session->device_name,
        ]);

        $token = $validated['session_token'];

        return response()->json([
            'ok'   => true,
            'data' => [
                'requires_otp' => false,
                'token'        => $token,
                'session'      => ['uuid' => $session->uuid, 'device_id' => $session->device_id],
                'member'       => MemberPayload::build($member, $token),
            ],
        ]);
    }

    /** GET members/devices — sesiones activas del miembro autenticado. */
    public function devices(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $currentDeviceId = $this->currentDeviceId($request);

        $sessions = $this->sessions->activeSessions($member)
            ->map(fn (MemberDeviceSession $s) => $s->toPublicArray($currentDeviceId))
            ->values();

        return response()->json(['ok' => true, 'data' => $sessions]);
    }

    /** POST members/devices/{uuid}/revoke — cierra sesión en un dispositivo. */
    public function revokeDevice(Request $request, string $uuid): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $session = MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->where('uuid', $uuid)
            ->active()
            ->first();

        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Dispositivo no encontrado.'], 404);
        }

        $this->sessions->revoke($session, 'revoked_by_user');
        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_REVOKED, $this->context($request), [
            'device' => $session->device_name,
        ]);

        return response()->json(['ok' => true, 'message' => 'Sesión cerrada en ese dispositivo.']);
    }

    /** POST members/logout — cierra la sesión del dispositivo actual. */
    public function logout(Request $request): JsonResponse
    {
        $member  = $request->attributes->get('auth_member');
        $session = $request->attributes->get('auth_device_session');

        if ($session instanceof MemberDeviceSession) {
            $this->sessions->revoke($session, 'logout');
            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_LOGOUT, $this->context($request), [
                    'device' => $session->device_name,
                ]);
            }
        }

        return response()->json(['ok' => true, 'message' => 'Sesión cerrada.']);
    }

    /** POST members/push-token — registra/renueva el token FCM del dispositivo. */
    public function registerPushToken(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $validated = $request->validate([
            'token'    => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);
        $ctx = $this->context($request);

        MemberDeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'member_id'    => $member->id,
                'device_id'    => $ctx['device_id'] ?? null,
                'platform'     => $validated['platform'] ?? $ctx['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** POST members/push-token/remove — da de baja un token FCM (logout). */
    public function removePushToken(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $validated = $request->validate(['token' => ['required', 'string', 'max:512']]);

        MemberDeviceToken::query()
            ->where('member_id', $member->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** POST admin/devices/{deviceId}/release — libera el vínculo de un equipo (CRM). */
    public function releaseDeviceBinding(Request $request, string $deviceId): JsonResponse
    {
        $binding = MemberDeviceBinding::query()->where('device_id', $deviceId)->first();
        if (! $binding) {
            return response()->json(['ok' => false, 'message' => 'Vínculo no encontrado.'], 404);
        }

        $member = $binding->member;
        $binding->delete();

        if ($member) {
            $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_RELEASED, $this->context($request), [
                'device_id' => $deviceId,
            ]);
        }

        return response()->json(['ok' => true, 'message' => 'Dispositivo liberado.']);
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /** Emite la sesión del dispositivo y dispara avisos de seguridad. */
    private function grantSession(Member $member, array $context, bool $otpVerified, bool $faceVerified = false): JsonResponse
    {
        // Asocia el equipo a este titular (anti-uso-compartido por dispositivo).
        $this->bindDevice($member, $context);

        $issued  = $this->sessions->issueSession($member, $context);
        $token   = $issued['token'];
        $session = $issued['session'];

        if (! empty($issued['revoked'])) {
            $this->security->record($member, MemberSecurityEvent::TYPE_CONCURRENT, $context, [
                'revoked_count' => count($issued['revoked']),
            ]);
            $this->notifications->notifyConcurrentSessionRevoked($member, $session->device_name);
        } elseif ($issued['was_new_device']) {
            $this->security->record($member, MemberSecurityEvent::TYPE_NEW_DEVICE, $context, [
                'device' => $session->device_name,
            ]);
            $this->notifications->notifyNewDeviceLogin($member, $session->device_name, $context['ip_address'] ?? null);
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'requires_otp'          => false,
                'otp_verified'          => $otpVerified,
                'face_verified'         => $faceVerified,
                'token'                 => $token,
                'session'               => ['uuid' => $session->uuid, 'device_id' => $session->device_id],
                'member'                => MemberPayload::build($member, $token),
                'revoked_other_devices' => count($issued['revoked']),
            ],
        ]);
    }

    /** Asocia el dispositivo al miembro titular si aún no lo está. */
    private function bindDevice(?Member $member, array $context): void
    {
        if (! $member || ! config('otp.device_binding.enabled', true)) {
            return;
        }
        $deviceId = $context['device_id'] ?? null;
        if ($deviceId === null || trim((string) $deviceId) === '') {
            return;
        }

        $existing = MemberDeviceBinding::forDevice($deviceId);
        if ($existing && $existing->member_id === $member->id) {
            return; // ya vinculado a este miembro
        }

        MemberDeviceBinding::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'member_id'   => $member->id,
                'device_name' => $context['device_name'] ?? null,
                'platform'    => $context['platform'] ?? null,
                'bound_at'    => now(),
            ],
        );

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_BOUND, $context, []);
    }

    /**
     * Deniega el acceso si el equipo ya está asociado a OTRO miembro
     * ("cuenta asociada a otro usuario"). Audita y avisa al titular del equipo.
     */
    private function deviceBindingDenied(Member $member, array $context): ?JsonResponse
    {
        if (! config('otp.device_binding.enabled', true)) {
            return null;
        }
        $deviceId = $context['device_id'] ?? null;
        $binding  = MemberDeviceBinding::forDevice($deviceId);
        if (! $binding || $binding->member_id === $member->id) {
            return null;
        }

        $owner = $binding->member;
        if ($owner) {
            $this->security->record($owner, MemberSecurityEvent::TYPE_DEVICE_MISMATCH, $context, [
                'attempted_document' => $member->document_number,
                'attempted_member'   => $member->id,
            ]);
            $this->notifications->notifyDeviceMismatch(
                $owner,
                $member->document_number,
                $binding->device_name ?? ($context['device_name'] ?? null),
                $deviceId,
            );
        }

        return response()->json([
            'ok'      => false,
            'code'    => 'account_mismatch',
            'message' => 'Acceso denegado: cuenta asociada a otro usuario.',
            'data'    => ['owner_device' => $binding->device_name],
        ], 403);
    }

    /** ¿El miembro tiene rostro de referencia y la verificación facial está activa? */
    private function faceRequiredFor(Member $member): bool
    {
        if (! config('otp.face.enabled', true) || ! config('otp.face.required', true)) {
            return false;
        }
        $member->loadMissing('biometric');

        return $member->biometric && (bool) $member->biometric->face_path;
    }

    /** Ticket facial válido = reto verificado por OTP dentro de la ventana. */
    private function validFaceTicket(string $uuid): ?MemberAuthChallenge
    {
        $challenge = MemberAuthChallenge::query()->where('uuid', $uuid)->first();
        if (! $challenge || $challenge->status !== MemberAuthChallenge::STATUS_VERIFIED) {
            return null;
        }
        $ttl = (int) config('otp.face.ticket_ttl', 600);
        if ($challenge->consumed_at && $challenge->consumed_at->lt(now()->subSeconds($ttl))) {
            return null;
        }

        return $challenge;
    }

    /**
     * Respuesta de bloqueo por concurrencia: audita, notifica al miembro y al
     * CRM, y devuelve 409 con el código que la app usa para mostrar la pantalla
     * premium "cuenta en uso en otro dispositivo principal".
     */
    private function concurrencyBlocked(Member $member, MemberDeviceSession $active, array $context): JsonResponse
    {
        $this->security->record($member, MemberSecurityEvent::TYPE_CONCURRENT_BLOCKED, $context, [
            'active_device'    => $active->device_name,
            'active_device_id' => $active->device_id,
            'active_last_seen' => $active->last_seen_at?->toIso8601String(),
        ]);

        $this->notifications->notifyConcurrentBlocked(
            $member,
            $context['device_name'] ?? null,
            $active->device_name,
        );

        return response()->json([
            'ok'      => false,
            'code'    => 'concurrency_blocked',
            'message' => 'La cuenta ya está en uso en otro dispositivo principal.',
            'data'    => [
                'active_device'    => $active->device_name ?: 'Dispositivo principal',
                'active_platform'  => $active->platform,
                'active_last_seen' => $active->last_seen_at?->diffForHumans(),
            ],
        ], 409);
    }

    private function context(Request $request): array
    {
        return [
            'device_id'   => $this->currentDeviceId($request),
            'device_name' => $request->input('device_name') ?? $request->header('X-Device-Name'),
            'platform'    => $request->input('platform') ?? $request->header('X-Platform'),
            'app_version' => $request->input('app_version') ?? $request->header('X-App-Version'),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ];
    }

    private function currentDeviceId(Request $request): ?string
    {
        $id = $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? $request->query('device_id');

        return ($id !== null && trim((string) $id) !== '') ? (string) $id : null;
    }
}
