<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberSecurityEvent;
use App\Models\Payment;
use App\Models\SupportSecurityReport;
use App\Services\DeviceSessionService;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\SecurityEventService;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Estado de cuenta del miembro autenticado. Es la FUENTE DE VERDAD del "gate"
 * de acceso de la app móvil (ActivationGate): el Home/AppShell solo es
 * accesible con membresía activa O un pago aprobado/verificado.
 *
 * No muta nada; solo lee. Pensado para llamarse en cada entrada a la app
 * (splash/login/otp/post-pago) y resolver AppShell vs ActivationGate.
 */
class MemberAccountController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private SecurityEventService $security,
        private NotificationService $notifications,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing('user');
        $user = $member->user;

        // Membresía activa: hay plan vinculado y la fecha de fin no está vencida.
        $endsAt = $user && $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->endOfDay()
            : null;
        $hasPlan = (bool) ($user && $user->plan);
        $membershipActive = $hasPlan && (! $endsAt || $endsAt->isFuture());

        // Pago aprobado/verificado del miembro (solo informativo; NO habilita el
        // Home por sí mismo: un pago aprobado en el pasado no implica membresía
        // vigente — debe reflejarse como membresía activa, que sí expira).
        $hasApprovedPayment = Payment::where('member_id', $member->id)
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'paid'])
            ->exists();

        // REGLA CENTRAL DE ACCESO (única fuente de verdad): el Home solo se
        // desbloquea con MEMBRESÍA ACTIVA y vigente. Ni el flag CRM
        // member.status=ACTIVE (no expira) ni "tuvo algún pago aprobado" (no
        // expira) habilitan el Home: ambos dejarían entrar a usuarios sin
        // membresía vigente. El único desbloqueo válido es un pago aprobado por
        // webhook → MembershipService extiende la membresía → isActive()=true.
        $canAccessApp = $membershipActive;

        $daysRemaining = $endsAt
            ? max(0, (int) Carbon::now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false))
            : null;

        return response()->json([
            'ok' => true,
            // 'active' = puede entrar al Home; 'activation_required' = ActivationGate.
            'account_status' => $canAccessApp ? 'active' : 'activation_required',
            'can_access_app' => $canAccessApp,
            'membership_active' => $membershipActive,
            'has_approved_payment' => $hasApprovedPayment,
            'member_status' => $member->status,
            'plan_name' => $hasPlan ? $user->plan : null,
            'membership_end_date' => $endsAt?->toDateString(),
            'days_remaining' => $daysRemaining,
        ]);
    }

    // ── Eliminación de cuenta / datos (App Store Guideline 5.1.1(v)) ──────────

    /** GET member/account/deletion-status — estado de la última solicitud. */
    public function deletionStatus(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->latest('id')->first();

        return response()->json([
            'ok' => true,
            'has_request' => (bool) $req,
            'status' => $req?->status,
            'requested_at' => $req?->requested_at?->toIso8601String(),
            'completed_at' => $req?->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * POST member/account/delete-request — registra la solicitud (idempotente) y
     * dispara el 2FA: la eliminación de cuenta es destructiva, así que exige
     * verificación por OTP/SMS antes de ejecutarse (Fase 7).
     *
     * Si el miembro no tiene teléfono para recibir OTP (la base demo tiene
     * varios), NO se le deja atrapado: se permite el borrado sin OTP para
     * cumplir App Store 5.1.1(v), pero queda auditado como `no_phone`.
     */
    public function deleteRequest(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $context = $this->securityContext($request);

        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->whereIn('status', [
                AccountDeletionRequest::STATUS_PENDING,
                AccountDeletionRequest::STATUS_PROCESSING,
            ])
            ->latest('id')->first()
            ?? AccountDeletionRequest::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'status' => AccountDeletionRequest::STATUS_PENDING,
                'reason' => $data['reason'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'requested_at' => now(),
                'metadata' => ['source' => 'mobile_app'],
            ]);

        $this->security->record($member, MemberSecurityEvent::TYPE_ACCOUNT_DELETE_REQUESTED, $context, [
            'request_id' => $req->id,
        ]);

        // Sin teléfono => no hay segundo factor posible: se permite continuar.
        if (! $this->otp->canChallenge($member)) {
            return response()->json([
                'ok' => true,
                'status' => $req->status,
                'requested_at' => $req->requested_at?->toIso8601String(),
                'requires_otp' => false,
            ]);
        }

        $result    = $this->otp->startChallenge($member, $context, MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE);
        $challenge = $result['challenge'];
        $this->security->record($member, MemberSecurityEvent::TYPE_SENSITIVE_OTP_SENT, $context, [
            'purpose'   => MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE,
            'challenge' => $challenge->uuid,
        ]);

        $payload = [
            'ok' => true,
            'status' => $req->status,
            'requested_at' => $req->requested_at?->toIso8601String(),
            'requires_otp' => true,
            'challenge_id' => $challenge->uuid,
            'destination' => $challenge->maskedDestination(),
            'expires_in' => (int) config('otp.ttl', 300),
            'resend_cooldown' => (int) config('otp.resend_cooldown', 60),
        ];
        if ($this->otp->exposeCode()) {
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    /**
     * POST member/account/delete-confirm — ejecuta el borrado:
     *  - revoca todas las sesiones (logout inmediato y bloqueo de login);
     *  - borra datos personales NO requeridos legalmente (rostro/documento,
     *    tokens y vínculos de dispositivo, retos de auth, biometría);
     *  - anonimiza el PII del miembro y del usuario;
     *  - CONSERVA contratos firmados y pagos/facturación por obligación legal.
     *
     * Funciona aunque el miembro no tenga membresía activa (cuenta creada sin
     * pagar también se puede eliminar).
     */
    public function deleteConfirm(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $context = $this->securityContext($request);

        // 2FA obligatorio si el miembro tiene teléfono: sin un OTP válido de
        // propósito `account_delete` no se ejecuta el borrado.
        $otpVerified = false;
        if ($this->otp->canChallenge($member)) {
            $validated = $request->validate([
                'challenge_id' => ['required', 'string'],
                'code'         => ['required', 'string'],
            ]);
            try {
                $this->otp->verifyAction(
                    $member,
                    MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE,
                    $validated['challenge_id'],
                    $validated['code'],
                    $context,
                );
                $otpVerified = true;
            } catch (OtpException $e) {
                return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
            }
        }

        $member->loadMissing(['user', 'identityDocument', 'biometric']);
        $user = $member->user;

        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->whereIn('status', [
                AccountDeletionRequest::STATUS_PENDING,
                AccountDeletionRequest::STATUS_PROCESSING,
            ])
            ->latest('id')->first()
            ?? AccountDeletionRequest::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'status' => AccountDeletionRequest::STATUS_PROCESSING,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'requested_at' => now(),
                'metadata' => ['source' => 'mobile_app'],
            ]);

        // 1) Revocar todas las sesiones activas (cierra sesión en todos lados).
        $sessions = app(DeviceSessionService::class);
        foreach ($sessions->activeSessions($member) as $session) {
            $sessions->revoke($session, 'account_deleted');
        }

        // 2) Borrar archivos personales NO requeridos legalmente (rostro/doc).
        //    NUNCA se tocan plantillas ni los PDFs de contratos firmados.
        $deletedFiles = 0;
        foreach ([
            $member->biometric?->face_path,
            $member->identityDocument?->front_path,
            $member->identityDocument?->back_path,
        ] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
                $deletedFiles++;
            }
        }

        DB::transaction(function () use ($member, $user, $req, $deletedFiles): void {
            // 3) Borrar PII sensible no contable + credenciales de dispositivo.
            $member->biometric()->delete();
            $member->identityDocument()->delete();
            foreach ([
                'member_device_tokens', 'member_device_bindings',
                'member_reenrollment_tokens', 'member_auth_challenges',
            ] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'member_id')) {
                    DB::table($table)->where('member_id', $member->id)->delete();
                }
            }

            // 4) Anonimizar el miembro (se conserva el id para contratos/pagos).
            $member->forceFill([
                'full_name' => 'Cuenta eliminada',
                'email' => null,
                'phone' => null,
                'gender' => null,
                'goal' => null,
                'injuries' => null,
                'birth_date' => null,
                // Libera el documento real y rompe el login por documento.
                'document_number' => 'DEL-'.$member->id,
                'biometric_status' => Member::BIOMETRIC_SKIPPED,
                'status' => Member::STATUS_DELETED,
                'anonymized_at' => now(),
            ])->save();

            // 5) Anonimizar el usuario vinculado (conserva pagos/facturación).
            if ($user) {
                $user->forceFill([
                    'name' => 'Cuenta eliminada',
                    'email' => 'deleted-'.$user->id.'@deleted.local',
                    'document' => 'DEL-'.$user->id,
                    'phone' => null,
                ])->save();
            }

            // 6) Cerrar la solicitud con auditoría de lo realizado/conservado.
            $req->forceFill([
                'status' => AccountDeletionRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'metadata' => array_merge((array) $req->metadata, [
                    'deleted_files' => $deletedFiles,
                    'anonymized' => ['member', $user ? 'user' : null],
                    'retained_for_legal' => ['member_contracts', 'payments'],
                ]),
            ])->save();
        });

        $this->security->record($member, MemberSecurityEvent::TYPE_ACCOUNT_DELETED, $context, [
            'otp_verified' => $otpVerified,
            'request_id'   => $req->id,
        ]);

        return response()->json([
            'ok' => true,
            'status' => AccountDeletionRequest::STATUS_COMPLETED,
            'message' => 'Tu cuenta y tus datos personales fueron eliminados. '
                .'Conservamos únicamente lo exigido por obligación legal/contable.',
        ]);
    }

    // ── Cambio de número de teléfono (Fase 5) ────────────────────────────────

    /**
     * POST member/security/phone-change/request — "Ya no cuento con este número".
     * Valida el número NUEVO (formato + que no pertenezca a otro miembro) y envía
     * el OTP AL NÚMERO NUEVO (prueba de control del nuevo número). El miembro ya
     * está autenticado (probó titularidad al iniciar sesión).
     */
    public function phoneChangeRequest(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $data = $request->validate([
            'new_phone' => ['required', 'string', 'min:7', 'max:30'],
        ]);
        $context = $this->securityContext($request);

        $newPhone = trim($data['new_phone']);
        if ($this->phoneInUseByOther($member, $newPhone)) {
            return response()->json([
                'ok' => false,
                'message' => 'Ese número ya está registrado en otra cuenta.',
            ], 422);
        }
        if ($this->sameDigits($newPhone, (string) $member->phone)) {
            return response()->json([
                'ok' => false,
                'message' => 'Ese ya es el número de tu cuenta.',
            ], 422);
        }

        $result    = $this->otp->startChallenge($member, $context, MemberAuthChallenge::PURPOSE_PHONE_CHANGE, $newPhone);
        $challenge = $result['challenge'];
        $this->security->record($member, MemberSecurityEvent::TYPE_PHONE_CHANGE_REQUESTED, $context, [
            'challenge'    => $challenge->uuid,
            'masked_phone' => $challenge->maskedDestination(),
        ]);

        $payload = [
            'ok'              => true,
            'requires_otp'    => true,
            'challenge_id'    => $challenge->uuid,
            'destination'     => $challenge->maskedDestination(),
            'expires_in'      => (int) config('otp.ttl', 300),
            'resend_cooldown' => (int) config('otp.resend_cooldown', 60),
        ];
        if ($this->otp->exposeCode()) {
            $payload['dev_code'] = $result['code'];
        }

        return response()->json($payload);
    }

    /**
     * POST member/security/phone-change/verify — valida el OTP enviado al número
     * nuevo y actualiza el teléfono del miembro (y del usuario vinculado). El
     * número verificado es el `destination` del reto (no se confía en el body).
     */
    public function phoneChangeVerify(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $validated = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code'         => ['required', 'string'],
        ]);
        $context = $this->securityContext($request);

        try {
            $challenge = $this->otp->verifyAction(
                $member,
                MemberAuthChallenge::PURPOSE_PHONE_CHANGE,
                $validated['challenge_id'],
                $validated['code'],
                $context,
            );
        } catch (OtpException $e) {
            return response()->json(array_merge(['ok' => false, 'message' => $e->getMessage()], $e->extra), $e->status);
        }

        $newPhone = trim((string) $challenge->destination);
        if ($newPhone === '') {
            return response()->json(['ok' => false, 'message' => 'No pudimos leer el número nuevo. Inténtalo otra vez.'], 422);
        }
        // Recheque de unicidad (pudo registrarse en otra cuenta durante el OTP).
        if ($this->phoneInUseByOther($member, $newPhone)) {
            return response()->json(['ok' => false, 'message' => 'Ese número ya está registrado en otra cuenta.'], 422);
        }

        $member->forceFill(['phone' => $newPhone])->save();
        if ($member->user) {
            $member->user->forceFill(['phone' => $newPhone])->save();
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_PHONE_CHANGED, $context, [
            'masked_phone' => MemberAuthChallenge::maskPhone($newPhone),
        ]);
        $this->notifications->notifyPhoneChanged($member, MemberAuthChallenge::maskPhone($newPhone));
        \App\Services\RealtimeEvents::phone($member->id);

        return response()->json([
            'ok'      => true,
            'message' => 'Tu número de teléfono se actualizó correctamente.',
            'phone'   => MemberAuthChallenge::maskPhone($newPhone),
        ]);
    }

    /**
     * POST member/security/phone-change/support-request — cuando el usuario NO
     * puede recibir el OTP en el número nuevo (o el caso es de alto riesgo): crea
     * un ticket de soporte para revisión del CRM. NO cambia el teléfono.
     */
    public function phoneChangeSupportRequest(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $data = $request->validate([
            'description'     => ['nullable', 'string', 'max:2000'],
            'contact_channel' => ['nullable', 'string', 'max:40'],
            'new_phone'       => ['nullable', 'string', 'max:30'],
        ]);

        $report = SupportSecurityReport::create([
            'member_id'       => $member->id,
            'document_number' => $member->document_number,
            'name'            => $member->full_name,
            'phone'           => $data['new_phone'] ?? null,
            'email'           => $member->email,
            'report_type'     => SupportSecurityReport::TYPE_PHONE_CHANGED,
            'status'          => SupportSecurityReport::STATUS_PENDING,
            'description'     => $data['description'] ?? null,
            'contact_channel' => $data['contact_channel'] ?? null,
            'ip_address'      => $request->ip(),
            'user_agent'      => mb_substr((string) $request->userAgent(), 0, 512),
            'metadata'        => ['source' => 'app_phone_change'],
        ]);

        $this->security->record($member, MemberSecurityEvent::TYPE_SUPPORT_REPORT, $this->securityContext($request), [
            'report_id' => $report->id,
            'report_type' => $report->report_type,
        ]);
        $this->notifications->notifySecuritySupportReport($report, $member);

        return response()->json([
            'ok'      => true,
            'message' => 'Recibimos tu solicitud. El equipo del gimnasio revisará tu caso.',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** ¿El teléfono (por dígitos) pertenece a OTRO miembro? */
    private function phoneInUseByOther(Member $member, string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }

        $query = Member::query()->where('id', '!=', $member->id);

        // En Postgres comparamos por dígitos (ignora formato); en otros drivers
        // (SQLite de tests) caemos a comparación exacta para mantener portabilidad.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->where(function ($q) use ($phone, $digits): void {
                $q->where('phone', $phone)
                    ->orWhereRaw("regexp_replace(phone, '\\D', '', 'g') = ?", [$digits]);
            });
        } else {
            $query->where('phone', $phone);
        }

        return $query->exists();
    }

    /** ¿Dos teléfonos tienen los mismos dígitos? */
    private function sameDigits(?string $a, ?string $b): bool
    {
        $da = preg_replace('/\D+/', '', (string) $a) ?? '';
        $db = preg_replace('/\D+/', '', (string) $b) ?? '';

        return $da !== '' && $da === $db;
    }

    /** Contexto de seguridad (dispositivo/red) para auditoría y retos OTP. */
    private function securityContext(Request $request): array
    {
        $deviceId = $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? $request->query('device_id');
        $deviceId = ($deviceId !== null && trim((string) $deviceId) !== '') ? (string) $deviceId : null;

        return [
            'device_id'   => $deviceId,
            'device_name' => $request->input('device_name') ?? $request->header('X-Device-Name'),
            'platform'    => $request->input('platform') ?? $request->header('X-Platform'),
            'app_version' => $request->input('app_version') ?? $request->header('X-App-Version'),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ];
    }
}
