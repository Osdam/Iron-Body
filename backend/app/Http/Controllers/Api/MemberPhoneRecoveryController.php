<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Recuperación SEGURA de número desde el LOGIN ("Ya no tengo este número"),
 * cuando el usuario todavía NO tiene sesión y por eso no puede usar el cambio de
 * número autenticado (member/security/phone-change/*).
 *
 * Modelo de seguridad (sin sesión, no se confía en el cliente):
 *   1. can-self-recover / start: el backend decide si ESTE dispositivo es
 *      confiable para el documento (vínculo dispositivo↔miembro previo,
 *      anti-uso-compartido). Solo un dispositivo ya vinculado al titular puede
 *      intentar el cambio directo.
 *   2. start emite un recovery_ticket de UN SOLO USO y corta vida. La app pide
 *      biometría LOCAL (Face ID/Touch ID/huella) y recién entonces canjea el
 *      ticket. El ticket por sí solo NO actualiza nada.
 *   3. request: con el ticket válido + número nuevo, envía OTP (Twilio Verify)
 *      AL NÚMERO NUEVO. El ticket se consume aquí.
 *   4. verify: solo al validar el OTP del número nuevo se actualiza member.phone.
 *
 * Si el dispositivo NO es confiable, NO se cambia el número: la app cae a
 * recuperación asistida (soporte). El backend nunca revela si el documento
 * existe (respuesta genérica) para no filtrar cuentas.
 */
class MemberPhoneRecoveryController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private SecurityEventService $security,
        private NotificationService $notifications,
    ) {
    }

    /** POST member/phone-recovery/can-self-recover — ¿este equipo puede el cambio directo? */
    public function canSelfRecover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document'  => ['required', 'string', 'max:40'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        $member = $this->trustedMember($data['document'], $request->input('device_id'));

        return response()->json([
            'ok'               => true,
            'can_self_recover' => $member !== null,
            'reason'           => $member !== null ? 'trusted_device' : 'untrusted_device',
        ]);
    }

    /**
     * POST member/phone-recovery/start — valida dispositivo confiable y emite un
     * recovery_ticket de un solo uso (TTL corto). La biometría local ocurre en la
     * app; el ticket solo habilita el siguiente paso, no cambia nada.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document'  => ['required', 'string', 'max:40'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        $member = $this->trustedMember($data['document'], $request->input('device_id'));
        if (! $member) {
            // Respuesta genérica: no revela si el documento existe.
            return response()->json([
                'ok'               => true,
                'can_self_recover' => false,
                'reason'           => 'untrusted_device',
            ]);
        }

        $context = $this->securityContext($request);

        // Un solo ticket vivo por miembro: vence los pendientes anteriores.
        MemberAuthChallenge::query()
            ->where('member_id', $member->id)
            ->where('purpose', MemberAuthChallenge::PURPOSE_PHONE_RECOVERY_TICKET)
            ->where('status', MemberAuthChallenge::STATUS_PENDING)
            ->update(['status' => MemberAuthChallenge::STATUS_EXPIRED]);

        $ttl = (int) config('security.phone_recovery_ticket_ttl', 300);
        $ticket = MemberAuthChallenge::create([
            'member_id'   => $member->id,
            'purpose'     => MemberAuthChallenge::PURPOSE_PHONE_RECOVERY_TICKET,
            'risk_tier'   => MemberAuthChallenge::TIER_LOCAL,
            // Código aleatorio no derivable: este ticket no se valida por OTP.
            'code_hash'   => Hash::make(Str::random(40)),
            'channel'     => 'local',
            'device_id'   => $context['device_id'] ?? null,
            'device_name' => $context['device_name'] ?? null,
            'platform'    => $context['platform'] ?? null,
            'ip_address'  => $context['ip_address'] ?? null,
            'user_agent'  => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
            'status'      => MemberAuthChallenge::STATUS_PENDING,
            'expires_at'  => now()->addSeconds($ttl),
        ]);

        $this->security->record($member, MemberSecurityEvent::TYPE_PHONE_CHANGE_REQUESTED, $context, [
            'source' => 'lost_number_recovery',
            'stage'  => 'ticket_issued',
            'ticket' => $ticket->uuid,
        ]);

        return response()->json([
            'ok'               => true,
            'can_self_recover' => true,
            'reason'           => 'trusted_device',
            'recovery_ticket'  => $ticket->uuid,
            'expires_in'       => $ttl,
        ]);
    }

    /**
     * POST member/phone-recovery/request — canjea el recovery_ticket (tras la
     * biometría local de la app) + número nuevo, y envía OTP al NÚMERO NUEVO. El
     * ticket se consume aquí (un solo uso).
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recovery_ticket' => ['required', 'string'],
            'new_phone'       => ['required', 'string', 'min:7', 'max:30'],
            'device_id'       => ['nullable', 'string', 'max:191'],
        ]);

        $ticket = MemberAuthChallenge::query()
            ->where('uuid', $data['recovery_ticket'])
            ->where('purpose', MemberAuthChallenge::PURPOSE_PHONE_RECOVERY_TICKET)
            ->where('risk_tier', MemberAuthChallenge::TIER_LOCAL)
            ->where('status', MemberAuthChallenge::STATUS_PENDING)
            ->first();

        if (! $ticket || $ticket->isExpired()) {
            return response()->json([
                'ok'      => false,
                'message' => 'La verificación de seguridad expiró. Vuelve a intentarlo.',
            ], 422);
        }

        $member = $ticket->member;
        // Defensa en profundidad: el dispositivo debe seguir confiable para el
        // titular (no basta con tener un ticket válido).
        if (! $member || ! $this->deviceTrusted($member, $request->input('device_id'))) {
            return response()->json([
                'ok'      => false,
                'message' => 'No pudimos validar este dispositivo. Solicita ayuda al gimnasio.',
            ], 403);
        }

        $newPhone = trim($data['new_phone']);
        if ($this->phoneInUseByOther($member, $newPhone)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ese número ya está registrado en otra cuenta.',
            ], 422);
        }
        if ($this->sameDigits($newPhone, (string) $member->phone)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ese ya es el número de tu cuenta.',
            ], 422);
        }

        // Consume el ticket (un solo uso) ANTES de disparar el OTP.
        $ticket->update([
            'status'      => MemberAuthChallenge::STATUS_COMPLETED,
            'consumed_at' => now(),
        ]);

        $context   = $this->securityContext($request);
        $result    = $this->otp->startChallenge($member, $context, MemberAuthChallenge::PURPOSE_PHONE_CHANGE, $newPhone);
        $challenge = $result['challenge'];

        $this->security->record($member, MemberSecurityEvent::TYPE_PHONE_CHANGE_REQUESTED, $context, [
            'source'       => 'lost_number_recovery',
            'stage'        => 'otp_sent',
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
     * POST member/phone-recovery/verify — valida el OTP del número nuevo y, solo
     * entonces, actualiza member.phone (y el usuario vinculado). El número es el
     * `destination` del reto (no se confía en el body).
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code'         => ['required', 'string'],
            'device_id'    => ['nullable', 'string', 'max:191'],
        ]);

        $challenge = MemberAuthChallenge::query()
            ->where('uuid', $data['challenge_id'])
            ->where('purpose', MemberAuthChallenge::PURPOSE_PHONE_CHANGE)
            ->first();

        $member = $challenge?->member;
        if (! $challenge || ! $member || ! $this->deviceTrusted($member, $request->input('device_id'))) {
            return response()->json([
                'ok'      => false,
                'message' => 'No encontramos esta verificación o el dispositivo no es confiable.',
            ], 404);
        }

        $context = $this->securityContext($request);
        try {
            $this->otp->verifyAction(
                $member,
                MemberAuthChallenge::PURPOSE_PHONE_CHANGE,
                $data['challenge_id'],
                $data['code'],
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
            'source'       => 'lost_number_recovery',
            'masked_phone' => MemberAuthChallenge::maskPhone($newPhone),
        ]);
        $this->notifications->notifyPhoneChanged($member, MemberAuthChallenge::maskPhone($newPhone));
        \App\Services\RealtimeEvents::phone($member->id);

        return response()->json([
            'ok'      => true,
            'message' => 'Tu número verificado fue actualizado correctamente.',
            'phone'   => MemberAuthChallenge::maskPhone($newPhone),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Miembro SOLO si el documento existe y este dispositivo está vinculado a él. */
    private function trustedMember(string $document, ?string $deviceId): ?Member
    {
        $member = Member::query()
            ->where('document_number', trim($document))
            ->first();

        if (! $member || ! $this->deviceTrusted($member, $deviceId)) {
            return null;
        }

        return $member;
    }

    /** ¿El dispositivo está vinculado al miembro (confiable / equipo principal)? */
    private function deviceTrusted(Member $member, ?string $deviceId): bool
    {
        if ($deviceId === null || trim($deviceId) === '') {
            return false;
        }
        $binding = MemberDeviceBinding::forDevice(trim($deviceId));

        return $binding !== null && (int) $binding->member_id === (int) $member->id;
    }

    /** ¿El teléfono (por dígitos) pertenece a OTRO miembro? */
    private function phoneInUseByOther(Member $member, string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }

        $query = Member::query()->where('id', '!=', $member->id);
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
