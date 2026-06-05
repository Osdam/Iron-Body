<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginMemberRequest;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberBiometric;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberDeviceToken;
use App\Models\MemberReenrollmentToken;
use App\Models\MemberSecurityEvent;
use App\Services\AccountRiskService;
use App\Services\DeviceSessionService;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\SecurityEventService;
use App\Support\MemberPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        private AccountRiskService $risk,
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

        // Cuenta eliminada/anonimizada por el usuario: no se permite reactivarla.
        if ($member->status === Member::STATUS_DELETED) {
            return response()->json([
                'ok' => false,
                'code' => 'account_deleted',
                'message' => 'Esta cuenta fue eliminada. Crea una cuenta nueva para usar la app.',
            ], 403);
        }

        // Cuenta suspendida por seguridad (bloqueo manual del CRM o automático):
        // no se permite el ingreso; el usuario puede abrir soporte.
        if ($member->isSuspended()) {
            return $this->suspendedResponse($member);
        }

        $context = $this->context($request);

        // Evaluación de riesgo (Fase 10): puede avisar o —si autosuspend está
        // activo— suspender. Por defecto solo avisa; re-chequeamos por si acaso.
        $this->risk->assess($member, $context);
        if ($member->isSuspended()) {
            return $this->suspendedResponse($member);
        }

        // Control de concurrencia: si la cuenta ya está activa en otro
        // dispositivo, se bloquea el ingreso (no se le roba la sesión al
        // dispositivo principal) y ni siquiera se envía el OTP. El usuario puede
        // reintentar con force=true ("cerrar sesión en otros dispositivos y
        // continuar"), que SÍ revoca la sesión anterior del MISMO miembro.
        if (! $request->boolean('force')
            && $active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null)) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        // Vínculo dispositivo↔cuenta: si el equipo ya está asociado a OTRO
        // miembro, se deniega ("cuenta asociada a otro usuario").
        if ($denied = $this->deviceBindingDenied($member, $context)) {
            return $denied;
        }

        // Login adaptativo por riesgo (Bloque 3b). Con el flag APAGADO (default)
        // $riskTier queda null y todo se comporta EXACTAMENTE como antes.
        $riskTier = null;
        if (config('security.adaptive_login', false)) {
            $deviceId  = $context['device_id'] ?? null;
            $trusted   = $this->isTrustedDevice($member, $deviceId);
            // Revalidación periódica: aunque el equipo sea confiable, si pasaron
            // > trusted_reauth_days desde el último OTP (o nunca lo hubo), se pide
            // un OTP una vez antes de volver al desbloqueo local.
            $binding   = $trusted ? MemberDeviceBinding::forDevice($deviceId) : null;
            $reauthDue = $binding !== null && $binding->needsOtpReauth();
            $tier      = $this->risk->loginTier($member, $trusted, $request->boolean('prefer_otp'), $reauthDue);

            // Riesgo bajo + dispositivo confiable: desbloqueo local (Face ID/huella
            // del dispositivo) sin SMS ni match facial; la app canjea el ticket.
            if ($tier === MemberAuthChallenge::TIER_LOCAL) {
                $ticket = $this->otp->createLocalTicket($member, $context);

                return response()->json(['ok' => true, 'data' => [
                    'requires_otp'          => false,
                    'requires_local_unlock' => true,
                    'unlock_ticket'         => $ticket->uuid,
                    'expires_in'            => (int) config('security.local_ticket_ttl', 180),
                ]]);
            }

            $riskTier = $tier; // 'otp' (solo SMS) | 'otp_face' (SMS + cara)
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

        $result    = $this->otp->startChallenge($member, $context, MemberAuthChallenge::PURPOSE_LOGIN, null, $riskTier);
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
        // tiempo del OTP): si la cuenta ya está en uso, bloquear el ingreso —
        // salvo force=true (el usuario eligió cerrar las otras sesiones).
        if (! $request->boolean('force')
            && $member && ($active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null))) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        // Login adaptativo: en el tier `otp` (dispositivo confiable, riesgo medio)
        // se omite el match facial; los tiers `otp_face`/clásico (null) lo exigen
        // si el miembro tiene rostro de referencia.
        $skipFace = config('security.adaptive_login', false)
            && $res['challenge']->risk_tier === MemberAuthChallenge::TIER_OTP;

        // Segundo factor biométrico: si el miembro tiene rostro de referencia,
        // NO se emite la sesión aún; el dispositivo debe pasar el escaneo facial
        // (reconocimiento on-device contra la referencia). El `ticket` autoriza
        // los pasos face-reference/face-verify.
        if (! $skipFace && $member && $this->faceRequiredFor($member)) {
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

        // OTP por SMS verificado: refresca la ventana de revalidación de 30 días.
        return $this->grantSession($member, $context, otpVerified: true, otpReauth: true);
    }

    /** POST members/login/face-reference — entrega la referencia facial (ticket). */
    /**
     * POST members/login/trusted-unlock — canjea el ticket de desbloqueo local
     * (login adaptativo, tier `local`). La app ya pasó la biometría LOCAL del
     * dispositivo; el backend confía en el vínculo equipo↔titular + bajo riesgo y
     * emite la sesión sin SMS ni match facial. Re-aplica todos los guardas.
     */
    public function trustedUnlock(Request $request): JsonResponse
    {
        if (! config('security.adaptive_login', false)) {
            return response()->json(['ok' => false, 'message' => 'No disponible.'], 404);
        }

        $validated = $request->validate([
            'ticket'          => ['required', 'string'],
            'document_number' => ['required', 'string'],
        ]);

        $member = Member::query()->with('user')
            ->where('document_number', $validated['document_number'])
            ->first();
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Documento no encontrado.'], 404);
        }
        if ($member->status === Member::STATUS_DELETED) {
            return response()->json(['ok' => false, 'code' => 'account_deleted', 'message' => 'Esta cuenta fue eliminada.'], 403);
        }
        if ($member->isSuspended()) {
            return $this->suspendedResponse($member);
        }

        $context = $this->context($request);

        // El dispositivo debe seguir siendo confiable Y no estar vencido para
        // revalidación periódica; si no, a OTP (defensa: un ticket viejo no puede
        // saltarse la revalidación de 30 días).
        $binding = MemberDeviceBinding::forDevice($context['device_id'] ?? null);
        $trusted = $binding !== null && $binding->member_id === $member->id;
        if (! $trusted || $binding->needsOtpReauth()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'verification_required',
                'message' => 'Necesitamos verificar tu identidad. Inicia sesión con tu documento.',
            ], 409);
        }

        $challenge = $this->otp->consumeLocalTicket($member, $validated['ticket']);
        if (! $challenge) {
            return response()->json([
                'ok'      => false,
                'code'    => 'ticket_expired',
                'message' => 'La verificación expiró. Inténtalo de nuevo.',
            ], 410);
        }

        // Mismos guardas que el login normal.
        if (! $request->boolean('force')
            && $active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null)) {
            return $this->concurrencyBlocked($member, $active, $context);
        }
        if ($denied = $this->deviceBindingDenied($member, $context)) {
            return $denied;
        }

        // Desbloqueo local (Face ID/huella): NO refresca la ventana de OTP — la
        // revalidación periódica sigue contando desde el último OTP real.
        return $this->grantSession($member, $context, otpVerified: true);
    }

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

    /**
     * POST members/login/face-verify — recibe el veredicto del match on-device
     * y DECIDE con una respuesta estructurada (sin "Acceso Denegado" genérico).
     *
     * Devuelve 200 también cuando no coincide: el cuerpo trae `approved=false`
     * con `reason` (low_score | re_enrollment_required | too_many_attempts) y
     * las banderas can_retry / can_reenroll / requires_additional_factor para
     * que la app muestre el flujo correcto. Los errores duros (ticket vencido,
     * concurrencia) siguen como respuestas no-2xx.
     */
    public function faceVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket'             => ['required', 'string'],
            'matched'            => ['required', 'boolean'],
            'score'              => ['nullable', 'numeric'],
            'normalizer_version' => ['nullable', 'string', 'max:40'],
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

        $member?->loadMissing('biometric');
        $bio = $member?->biometric;

        $this->logFaceDecision('start', $member, $challenge, $bio, $score, null);

        // ── Coincide → emitir sesión ─────────────────────────────────────────
        if ($matched) {
            $challenge->update(['status' => MemberAuthChallenge::STATUS_COMPLETED]);
            $bio?->update(['last_biometric_verified_at' => now()]);
            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_FACE_VERIFIED, $context, array_filter(['score' => $score]));
            }
            $this->logFaceDecision('approved', $member, $challenge, $bio, $score, 'approved');

            return $this->grantSession($member, $context, otpVerified: true, faceVerified: true, otpReauth: true);
        }

        // ── No coincide → auditar y decidir el motivo ────────────────────────
        $challenge->increment('attempts');

        $selfiePath = null;
        if (config('otp.face.store_selfie', false) && $request->hasFile('selfie') && $member) {
            try {
                $selfiePath = $request->file('selfie')->store("members/{$member->member_uuid}/face_attempts", 'local');
            } catch (\Throwable) {
                $selfiePath = null;
            }
        }
        if ($member) {
            $this->security->record($member, MemberSecurityEvent::TYPE_FACE_FAILED, $context, array_filter([
                'score'  => $score,
                'selfie' => $selfiePath,
            ]));
            $this->notifications->notifyFaceMismatch($member, $context['device_name'] ?? null, $context['device_id'] ?? null);
        }

        // Demasiados intentos → bloquear el ticket.
        if ($challenge->attempts >= (int) config('otp.face.max_attempts', 3)) {
            $challenge->update(['status' => MemberAuthChallenge::STATUS_BLOCKED]);
            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_FACE_LOCKED, $context, ['attempts' => $challenge->attempts]);
            }
            $this->logFaceDecision('denied', $member, $challenge, $bio, $score, 'too_many_attempts');

            return $this->faceDecision('too_many_attempts',
                'Demasiados intentos. Intenta más tarde o contacta al gimnasio.',
                canRetry: false, canReenroll: false, requiresAdditionalFactor: false);
        }

        // ¿Referencia legacy + "casi" coincide → ofrecer re-enrolamiento? Sólo
        // un near-miss (banda controlada) cuenta como incompatibilidad de
        // plantilla; una distancia enorme es otra persona → low_score normal.
        $reenrollEnabled = (bool) config('otp.face.reenroll.enabled', true);
        $scoreMax        = (float) config('otp.face.reenroll.score_max', 1.6);
        $isLegacy        = $bio && $bio->isLegacy();
        $nearMiss        = $score !== null && $score <= $scoreMax;

        if ($reenrollEnabled && $isLegacy && $nearMiss) {
            $bio->update([
                'biometric_reference_status' => MemberBiometric::STATUS_RE_ENROLLMENT_REQUIRED,
                'biometric_legacy_reason'    => 'cross_platform_template',
            ]);
            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_FACE_REENROLL_REQUIRED, $context, array_filter([
                    'score'    => $score,
                    'platform' => $context['platform'] ?? null,
                ]));
            }
            $this->logFaceDecision('denied', $member, $challenge, $bio, $score, 're_enrollment_required');

            return $this->faceDecision('re_enrollment_required',
                'Tu verificación facial debe actualizarse para este dispositivo.',
                canRetry: false, canReenroll: true, requiresAdditionalFactor: true);
        }

        // Caso normal: no coincide y no aplica re-enrolamiento → reintentar.
        $this->logFaceDecision('denied', $member, $challenge, $bio, $score, 'low_score');

        return $this->faceDecision('low_score',
            'No pudimos confirmar que seas tú. Intenta con mejor luz.',
            canRetry: true, canReenroll: false, requiresAdditionalFactor: false);
    }

    /**
     * POST members/login/face-reenroll/request — paso 1 del re-enrolamiento.
     * Sólo para una referencia legacy/incompatible. Exige un SEGUNDO FACTOR:
     * envía un OTP fresco por SMS. No cambia el rostro todavía.
     */
    public function faceReenrollRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:60'],
        ]);

        if (! config('otp.face.reenroll.enabled', true)) {
            return response()->json(['ok' => false, 'message' => 'El re-enrolamiento no está disponible.'], 403);
        }

        $challenge = $this->validFaceTicket($validated['ticket']);
        if (! $challenge || ! $challenge->member) {
            return response()->json(['ok' => false, 'message' => 'La verificación expiró. Inicia sesión nuevamente.'], 410);
        }

        $member  = $challenge->member;
        $context = $this->context($request);

        $member->loadMissing('biometric');
        $bio = $member->biometric;
        if (! $bio || ! $bio->isLegacy()) {
            // Sólo se re-enrola una referencia legacy/incompatible.
            return response()->json(['ok' => false, 'message' => 'Tu verificación facial no requiere actualización.'], 422);
        }

        if (! $this->otp->canChallenge($member)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tu cuenta no tiene teléfono para enviar el código. Contacta a recepción.',
            ], 422);
        }

        // Segundo factor: OTP fresco. No afecta al ticket facial (verificado).
        $result    = $this->otp->startChallenge($member, $context);
        $otpChall  = $result['challenge'];

        $this->security->record($member, MemberSecurityEvent::TYPE_FACE_REENROLL_REQUESTED, $context, array_filter([
            'reason' => $validated['reason'] ?? null,
        ]));

        $data = [
            'reenroll_challenge_id' => $otpChall->uuid,
            'destination'           => $otpChall->maskedDestination(),
            'expires_in'            => (int) config('otp.ttl', 300),
            'resend_cooldown'       => (int) config('otp.resend_cooldown', 60),
            'channel'               => 'sms',
        ];
        if ($this->otp->exposeCode()) {
            $data['dev_code'] = $result['code'];
        } elseif (! $result['sent']) {
            $data['delivery_warning'] = 'No pudimos confirmar el envío del SMS. Puedes reenviar el código.';
        }

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * POST members/login/face-reenroll/confirm — paso 2: valida el OTP y emite
     * un re_enrollment_token de UN SOLO USO y vida corta, atado al miembro y al
     * ticket facial. Sin este token no se puede sustituir el rostro.
     */
    public function faceReenrollConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket'                => ['required', 'string'],
            'reenroll_challenge_id' => ['required', 'string'],
            'otp_code'              => ['required', 'string'],
        ]);

        $challenge = $this->validFaceTicket($validated['ticket']);
        if (! $challenge || ! $challenge->member) {
            return response()->json(['ok' => false, 'message' => 'La verificación expiró. Inicia sesión nuevamente.'], 410);
        }
        $member  = $challenge->member;
        $context = $this->context($request);

        try {
            $res = $this->otp->verify($validated['reenroll_challenge_id'], $validated['otp_code'], $context);
        } catch (OtpException $e) {
            return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
        }

        // El OTP confirmado debe pertenecer al MISMO miembro del ticket facial.
        if (! $res['member'] || $res['member']->id !== $member->id) {
            return response()->json(['ok' => false, 'message' => 'No pudimos validar el código para esta cuenta.'], 422);
        }

        // Token de un solo uso. Se persiste sólo su hash (sha256 del secreto).
        $secret = Str::random(64);
        $ttl    = (int) config('otp.face.reenroll.token_ttl', 300);
        MemberReenrollmentToken::create([
            'member_id'      => $member->id,
            'challenge_uuid' => $challenge->uuid,
            'token_hash'     => hash('sha256', $secret),
            'reason'         => 'template_legacy',
            'status'         => MemberReenrollmentToken::STATUS_PENDING,
            'device_id'      => $context['device_id'] ?? null,
            'ip_address'     => $context['ip_address'] ?? null,
            'expires_at'     => now()->addSeconds($ttl),
        ]);

        return response()->json([
            'ok'   => true,
            'data' => [
                're_enrollment_token' => $secret,
                'expires_in'          => $ttl,
            ],
        ]);
    }

    /**
     * POST members/login/face-reenroll/complete — paso 3 (multipart): con el
     * re_enrollment_token válido, sustituye la referencia facial por la nueva
     * (ya normalizada en el cliente con normalizer_version actual), marca la
     * anterior como reemplazada y emite la sesión. Token de un solo uso.
     */
    public function faceReenrollComplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            're_enrollment_token' => ['required', 'string'],
            'face'                => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'normalizer_version'  => ['required', 'string', 'max:40'],
            'platform'            => ['nullable', 'string', 'max:20'],
            'camera'              => ['nullable', 'string', 'max:20'],
            'image_width'         => ['nullable', 'integer', 'min:0'],
            'image_height'        => ['nullable', 'integer', 'min:0'],
        ]);

        $context = $this->context($request);

        $token = MemberReenrollmentToken::query()
            ->where('token_hash', hash('sha256', $validated['re_enrollment_token']))
            ->first();

        if (! $token || ! $token->isUsable()) {
            return response()->json(['ok' => false, 'message' => 'La autorización para actualizar tu rostro expiró. Vuelve a solicitarla.'], 410);
        }

        $challenge = $this->validFaceTicket($token->challenge_uuid);
        $member    = $challenge?->member ?? $token->member;
        if (! $member || ! $challenge || $challenge->member?->id !== $member->id) {
            return response()->json(['ok' => false, 'message' => 'La verificación expiró. Inicia sesión nuevamente.'], 410);
        }

        // Concurrencia: por si la cuenta se activó en otro equipo entretanto.
        if ($active = $this->sessions->concurrentActiveSession($member, $context['device_id'] ?? null)) {
            return $this->concurrencyBlocked($member, $active, $context);
        }

        // Consumir el token (un solo uso) ANTES de tocar la referencia.
        $token->update(['status' => MemberReenrollmentToken::STATUS_USED, 'used_at' => now()]);

        $member->loadMissing('biometric');
        $oldPath = $member->biometric?->face_path;

        try {
            $stored = $request->file('face')->store("members/{$member->member_uuid}/biometrics/faces", 'local');
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'No pudimos guardar tu nueva referencia facial.'], 500);
        }

        $disk = Storage::disk('local');
        $member->biometric()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'face_path'                   => $stored,
                'face_mime'                   => $request->file('face')->getClientMimeType() ?: 'image/jpeg',
                'face_size'                   => $disk->exists($stored) ? $disk->size($stored) : null,
                'captured_at'                 => now(),
                'bytes_length'                => $disk->exists($stored) ? $disk->size($stored) : null,
                'normalizer_version'          => $validated['normalizer_version'],
                'enrolled_platform'           => $validated['platform'] ?? ($context['platform'] ?? null),
                'enrolled_device_type'        => $context['device_name'] ?? null,
                'biometric_reference_status'  => MemberBiometric::STATUS_ACTIVE,
                'biometric_legacy_reason'     => null,
                'last_biometric_enrolled_at'  => now(),
            ],
        );

        // Borra la referencia anterior (no acumular biometría innecesaria).
        if ($oldPath && $oldPath !== $stored) {
            try {
                $disk->delete($oldPath);
            } catch (\Throwable) {/* best-effort */}
        }

        $challenge->update(['status' => MemberAuthChallenge::STATUS_COMPLETED]);
        $this->security->record($member, MemberSecurityEvent::TYPE_FACE_REENROLL_COMPLETED, $context, array_filter([
            'normalizer_version' => $validated['normalizer_version'],
            'platform'           => $validated['platform'] ?? null,
        ]));
        $this->security->record($member, MemberSecurityEvent::TYPE_FACE_VERIFIED, $context, []);

        Log::info('2fa:face', ['stage' => 'reenroll_completed', 'member_id' => $member->id, 'decision' => 'approved']);

        // El segundo factor (OTP) + nueva referencia recién capturada autorizan
        // la sesión; no hace falta re-escanear contra sí misma.
        return $this->grantSession($member, $context, otpVerified: true, faceVerified: true, otpReauth: true);
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

    /**
     * POST members/devices/revoke-others (alias sessions/logout-others):
     * cierra TODAS las sesiones del miembro EXCEPTO la del dispositivo actual.
     * Solo afecta al propio miembro autenticado.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $current = $request->attributes->get('auth_device_session');
        $currentId = $current instanceof MemberDeviceSession ? $current->id : null;
        // Conservar también por device_id (cuando se autentica con access_hash
        // no hay auth_device_session, pero el cliente envía su device_id).
        $currentDeviceId = $this->currentDeviceId($request);

        $count = 0;
        foreach ($this->sessions->activeSessions($member) as $session) {
            $isCurrent = ($currentId !== null && $session->id === $currentId)
                || ($currentDeviceId !== null && $session->device_id === $currentDeviceId);
            if ($isCurrent) {
                continue; // conserva la sesión actual
            }
            $this->sessions->revoke($session, 'revoked_others_by_user');
            $count++;
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_REVOKED, $this->context($request), [
            'scope'         => 'others',
            'revoked_count' => $count,
        ]);
        Log::info('session:logout-others', ['member_id' => $member->id, 'count' => $count]);

        return response()->json([
            'ok'            => true,
            'revoked_count' => $count,
            'message'       => $count > 0
                ? 'Se cerraron las sesiones en los demás dispositivos.'
                : 'No había otras sesiones activas.',
        ]);
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

    // ── Acciones sensibles con 2FA (Fase 6 / Fase 8) ─────────────────────────

    /** POST members/devices/{uuid}/revoke-request — dispara el OTP para revocar. */
    public function revokeDeviceRequest(Request $request, string $uuid): JsonResponse
    {
        $member  = $request->attributes->get('auth_member');
        $session = MemberDeviceSession::query()
            ->where('member_id', $member->id)
            ->where('uuid', $uuid)
            ->active()
            ->first();

        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Dispositivo no encontrado.'], 404);
        }

        return $this->sensitiveChallengeResponse(
            $member,
            MemberAuthChallenge::PURPOSE_DEVICE_REVOKE,
            $this->context($request),
            ['device' => $session->device_name],
        );
    }

    /** POST members/devices/{uuid}/revoke-confirm — valida OTP y cierra la sesión. */
    public function revokeDeviceConfirm(Request $request, string $uuid): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if ($err = $this->verifySensitive($request, $member, MemberAuthChallenge::PURPOSE_DEVICE_REVOKE)) {
            return $err;
        }

        return $this->revokeDevice($request, $uuid);
    }

    /** POST member/devices/revoke-others-request — dispara el OTP para cerrar las demás. */
    public function revokeOthersRequest(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        return $this->sensitiveChallengeResponse(
            $member,
            MemberAuthChallenge::PURPOSE_DEVICE_REVOKE_OTHERS,
            $this->context($request),
        );
    }

    /** POST member/devices/revoke-others-confirm — valida OTP y cierra las demás. */
    public function revokeOthersConfirm(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if ($err = $this->verifySensitive($request, $member, MemberAuthChallenge::PURPOSE_DEVICE_REVOKE_OTHERS)) {
            return $err;
        }

        return $this->revokeOthers($request);
    }

    /** POST members/logout/unbind-request — dispara el OTP para cerrar sesión + desvincular este equipo. */
    public function logoutUnbindRequest(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        return $this->sensitiveChallengeResponse(
            $member,
            MemberAuthChallenge::PURPOSE_DEVICE_UNBIND,
            $this->context($request),
        );
    }

    /**
     * POST members/logout/unbind-confirm — cierra la sesión actual Y desvincula
     * este equipo (borra su binding): el próximo ingreso desde este o cualquier
     * dispositivo exigirá verificación completa (OTP + cara). Fase 8.
     */
    public function logoutUnbindConfirm(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if ($err = $this->verifySensitive($request, $member, MemberAuthChallenge::PURPOSE_DEVICE_UNBIND)) {
            return $err;
        }

        $session  = $request->attributes->get('auth_device_session');
        $deviceId = $this->currentDeviceId($request)
            ?? ($session instanceof MemberDeviceSession ? $session->device_id : null);

        if ($session instanceof MemberDeviceSession) {
            $this->sessions->revoke($session, 'logout_unbind');
        }
        if ($deviceId !== null) {
            MemberDeviceBinding::query()
                ->where('member_id', $member->id)
                ->where('device_id', $deviceId)
                ->delete();
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_UNBOUND, $this->context($request), [
            'device_id' => $deviceId,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Dispositivo desvinculado. El próximo ingreso pedirá verificación completa.',
        ]);
    }

    /**
     * Dispara el OTP de una acción sensible y arma la respuesta `requires_otp`.
     * Si el miembro no tiene teléfono (no hay segundo factor posible) devuelve
     * `requires_otp:false` para no dejarlo atrapado: el *-confirm procederá.
     */
    private function sensitiveChallengeResponse(Member $member, string $purpose, array $context, array $extra = []): JsonResponse
    {
        if (! $this->otp->canChallenge($member)) {
            return response()->json(array_merge(['ok' => true, 'requires_otp' => false], $extra));
        }

        $result    = $this->otp->startChallenge($member, $context, $purpose);
        $challenge = $result['challenge'];
        $this->security->record($member, MemberSecurityEvent::TYPE_SENSITIVE_OTP_SENT, $context, [
            'purpose'   => $purpose,
            'challenge' => $challenge->uuid,
        ]);

        $payload = array_merge([
            'ok'              => true,
            'requires_otp'    => true,
            'challenge_id'    => $challenge->uuid,
            'destination'     => $challenge->maskedDestination(),
            'expires_in'      => (int) config('otp.ttl', 300),
            'resend_cooldown' => (int) config('otp.resend_cooldown', 60),
        ], $extra);
        if ($this->otp->exposeCode()) {
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    /**
     * Verifica el OTP de una acción sensible. Devuelve null si todo bien (o si
     * no hay teléfono y por tanto no hay 2FA), o un JsonResponse de error listo.
     */
    private function verifySensitive(Request $request, Member $member, string $purpose): ?JsonResponse
    {
        if (! $this->otp->canChallenge($member)) {
            return null;
        }

        $validated = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code'         => ['required', 'string'],
        ]);

        try {
            $this->otp->verifyAction($member, $purpose, $validated['challenge_id'], $validated['code'], $this->context($request));
        } catch (OtpException $e) {
            return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
        }

        return null;
    }

    /** Respuesta estándar para una cuenta suspendida por seguridad. */
    private function suspendedResponse(Member $member): JsonResponse
    {
        $lock = $member->activeRiskLock();

        return response()->json([
            'ok'           => false,
            'code'         => 'account_suspended',
            'message'      => 'Por seguridad, tu cuenta fue suspendida temporalmente. Acércate al gimnasio o contacta a soporte.',
            'locked_until' => $lock?->locked_until?->toIso8601String(),
        ], 423);
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

    /** Respuesta estructurada de rechazo facial (HTTP 200, evaluado). */
    private function faceDecision(
        string $reason,
        string $message,
        bool $canRetry,
        bool $canReenroll,
        bool $requiresAdditionalFactor,
    ): JsonResponse {
        return response()->json([
            'ok'                         => false,
            'approved'                   => false,
            'reason'                     => $reason,
            'message'                    => $message,
            'can_retry'                  => $canRetry,
            'can_reenroll'               => $canReenroll,
            'requires_additional_factor' => $requiresAdditionalFactor,
        ], 200);
    }

    /**
     * Log seguro de la decisión facial (Fase 4). NO registra imagen, biometría
     * cruda, tokens ni el código OTP; sólo metadata y el score redondeado.
     */
    private function logFaceDecision(
        string $stage,
        ?Member $member,
        MemberAuthChallenge $challenge,
        ?MemberBiometric $bio,
        ?float $score,
        ?string $decision,
    ): void {
        Log::info('2fa:face', [
            'stage'              => $stage, // start | approved | denied
            'member_id'          => $member?->id,
            'challenge'          => $this->maskUuid($challenge->uuid),
            'reference_exists'   => $bio !== null,
            'reference_platform' => $bio?->enrolled_platform,
            'reference_status'   => $bio?->biometric_reference_status,
            'normalizer_version' => $bio?->normalizer_version,
            'match_score'        => $score !== null ? round($score, 3) : null,
            'reenroll_score_max' => (float) config('otp.face.reenroll.score_max', 1.6),
            'decision'           => $decision,
        ]);
    }

    private function maskUuid(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return substr($uuid, 0, 8) . '…';
    }

    /** Emite la sesión del dispositivo y dispara avisos de seguridad. */
    private function grantSession(Member $member, array $context, bool $otpVerified, bool $faceVerified = false, bool $otpReauth = false): JsonResponse
    {
        // Asocia el equipo a este titular (anti-uso-compartido por dispositivo) y,
        // si esta entrada fue por OTP real, refresca la marca de revalidación.
        $this->bindDevice($member, $context, $otpReauth);

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
    private function bindDevice(?Member $member, array $context, bool $otpReauth = false): void
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
            // Ya vinculado a este miembro: si esta entrada fue por OTP real,
            // refresca la ventana de revalidación de 30 días.
            if ($otpReauth) {
                $existing->forceFill(['last_otp_reauth_at' => now()])->save();
            }

            return;
        }

        MemberDeviceBinding::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'member_id'   => $member->id,
                'device_name' => $context['device_name'] ?? null,
                'platform'    => $context['platform'] ?? null,
                'bound_at'    => now(),
                // Un binding nuevo siempre nace de un login fuerte (OTP); marca la
                // revalidación para no exigir OTP de nuevo dentro de la ventana.
                'last_otp_reauth_at' => $otpReauth ? now() : null,
            ],
        );

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_BOUND, $context, []);
    }

    /**
     * ¿Este dispositivo es CONFIABLE para el miembro? Lo es si ya está vinculado
     * a ÉL (member_device_bindings), es decir, completó un login fuerte antes en
     * este equipo. Si el binding está apagado o no hay device_id → no confiable.
     */
    private function isTrustedDevice(?Member $member, ?string $deviceId): bool
    {
        if (! $member || ! config('otp.device_binding.enabled', true)) {
            return false;
        }
        if ($deviceId === null || trim($deviceId) === '') {
            return false;
        }

        $binding = MemberDeviceBinding::forDevice($deviceId);

        return $binding !== null && $binding->member_id === $member->id;
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
            'ok'          => false,
            'code'        => 'account_mismatch',
            'reason_code' => 'device_bound_to_another_member',
            'message'     => 'Acceso denegado: cuenta asociada a otro usuario.',
            'data'        => [
                // Solo el nombre genérico del equipo ("iPhone"); NUNCA el
                // email/nombre/documento del titular del binding.
                'owner_device'     => $binding->device_name,
                'recovery_options' => [
                    'support_report'          => true,  // abrir flujo de soporte
                    'can_reset_local_session' => true,  // limpiar sesión local del device
                    'can_request_rebind'      => false, // rebind exige OTP+cara o revisión CRM
                ],
            ],
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
