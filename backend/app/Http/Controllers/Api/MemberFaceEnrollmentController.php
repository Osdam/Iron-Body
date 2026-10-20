<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberSecurityEvent;
use App\Services\NotificationService;
use App\Services\SecurityEventService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Perfil → Registro facial: el PRIMER rostro del socio, registrado por él
 * mismo con su sesión.
 *
 * No es `members/{id}/biometric`: esa ruta del alta solo lleva el token
 * compartido de la APK y sigue cerrada para cuentas activas (6850b81). Aquí
 * el socio sale del Bearer de su sesión de dispositivo, nunca de un id del
 * cliente, y además:
 *
 *  - la sesión viene de un equipo vinculado a ESTE socio con un OTP real
 *    dentro de la ventana de revalidación (un socio sin teléfono entra sin
 *    OTP y el access_hash del alta no prueba nada: los dos van a recepción);
 *  - acepta la autorización biométrica vigente (config members.biometric_consent);
 *  - no tiene ninguna referencia (activa, legacy o desactivada) y nunca usó
 *    este autorregistro: cambiar o volver a registrar el rostro es de
 *    recepción, que ve a la persona y su documento;
 *  - es mayor de edad: el dato biométrico de un menor lo autoriza su acudiente.
 *
 * Límites que esto NO cubre: no hay prueba de vida ni comparación 1:N contra
 * otros socios en el servidor (el match corre en el CRM y en LocalGym). La
 * mitigación es que solo el titular autenticado registra, una vez, y que
 * recepción recibe el aviso «Rostro registrado» con la foto en el CRM.
 */
class MemberFaceEnrollmentController extends Controller
{
    private const MESSAGES = [
        'face_already_registered' => 'Tu rostro ya está registrado correctamente. Si necesitas cambiarlo, acércate a recepción.',
        'face_enrollment_reception' => 'Tu registro facial se gestiona en recepción. Acércate con tu documento.',
        'minor_requires_guardian' => 'Por ser menor de edad, tu rostro se registra en recepción con tu acudiente.',
        'otp_session_required' => 'Por seguridad, cierra sesión y vuelve a entrar con el código que te enviamos por SMS para registrar tu rostro. Si no recibes SMS, acércate a recepción.',
        'consent_outdated' => 'La autorización cambió. Vuelve a abrir Registro facial para leerla y aceptarla.',
    ];

    public function __construct(
        private readonly SecurityEventService $security,
        private readonly NotificationService $notifications,
    ) {}

    /** GET member/face-enrollment — qué puede hacer el socio, antes de abrir la cámara. */
    public function show(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $blocked = $this->blockedReason($request, $member);

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => $this->hasReference($member) ? 'registered' : 'not_registered',
                'can_enroll' => $blocked === null,
                'code' => $blocked,
                'message' => $blocked !== null ? self::MESSAGES[$blocked] : null,
                'consent' => $blocked === null ? $this->consent() : null,
            ],
        ]);
    }

    /** POST member/face-enrollment (multipart) — guarda el primer rostro. */
    public function store(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        if ($blocked = $this->blockedReason($request, $member)) {
            return $this->reject($member, $blocked);
        }

        $validated = $request->validate([
            'face' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:8192'],
            'consent_accepted' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'max:20'],
            'captured_at' => ['nullable', 'date'],
            'bytes_length' => ['nullable', 'integer', 'min:1'],
            'normalizer_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        if (! hash_equals((string) config('members.biometric_consent.version'), $validated['consent_version'])) {
            return $this->reject($member, 'consent_outdated');
        }

        $file = $request->file('face');
        try {
            $path = $file->store("members/{$member->member_uuid}/biometrics/faces", 'local');
        } catch (Throwable) {
            $path = false;
        }
        if (! $path) {
            return response()->json(['ok' => false, 'code' => 'storage_failed', 'message' => 'No pudimos guardar tu rostro. Inténtalo de nuevo.'], 500);
        }

        // La ficha bloqueada serializa dos envíos a la vez; el único de
        // member_biometrics.member_id es la segunda red. Lo que no se confirma
        // no deja ni la fila ni el fichero.
        try {
            $created = DB::transaction(function () use ($member, $validated, $file, $path): bool {
                $locked = Member::query()->whereKey($member->id)->lockForUpdate()->first();
                if (! $locked || $this->hasReference($locked)) {
                    return false;
                }

                MemberBiometric::create([
                    'member_id' => $locked->id,
                    'face_path' => $path,
                    'face_mime' => $file->getMimeType(),
                    'face_size' => $file->getSize(),
                    'captured_at' => $validated['captured_at'] ?? now(),
                    'bytes_length' => $validated['bytes_length'] ?? $file->getSize(),
                    'normalizer_version' => $validated['normalizer_version'] ?? null,
                    'enrolled_platform' => $validated['platform'] ?? null,
                    'biometric_reference_status' => MemberBiometric::STATUS_ACTIVE,
                    'last_biometric_enrolled_at' => now(),
                    'consent_version' => $validated['consent_version'],
                    'consent_accepted_at' => now(),
                    'enrolled_via' => 'app_profile',
                ]);

                $locked->biometric_status = Member::BIOMETRIC_REGISTERED;
                $locked->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $created = false;
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            Log::error('member:face-enrollment:error', ['member_id' => $member->id, 'error' => class_basename($e)]);

            return response()->json(['ok' => false, 'code' => 'server_error', 'message' => 'No pudimos guardar tu rostro. Inténtalo de nuevo.'], 500);
        }

        if (! $created) {
            Storage::disk('local')->delete($path);

            return $this->reject($member, 'face_already_registered');
        }

        $member->refresh();
        $session = $this->session($request);
        $this->security->record($member, MemberSecurityEvent::TYPE_FACE_SELF_ENROLLED, [
            'device_id' => $session?->device_id,
            'device_name' => $session?->device_name,
            'platform' => $validated['platform'] ?? $session?->platform,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], array_filter([
            'consent_version' => $validated['consent_version'],
            'normalizer_version' => $validated['normalizer_version'] ?? null,
        ]));
        Log::info('member:face-enrollment:created', ['member_id' => $member->id]);

        // Recepción recibe el aviso con la foto en el CRM y el terminal facial
        // se re-indexa; LocalGym la baja en su próxima sincronización.
        $this->notifications->notifyFaceEnrolled($member);

        return response()->json([
            'ok' => true,
            'code' => 'face_registered',
            'message' => 'Rostro registrado. Ya puedes ingresar con reconocimiento facial.',
            'data' => ['biometric_status' => $member->biometric_status],
        ], 201);
    }

    /** Null = puede registrar. Si no, el código del motivo (ver MESSAGES). */
    private function blockedReason(Request $request, Member $member): ?string
    {
        if ($this->hasReference($member)) {
            return 'face_already_registered';
        }
        // Una sola vez por cuenta: si recepción borró el rostro que subió el
        // socio, el siguiente se registra en recepción.
        if (MemberSecurityEvent::query()
            ->where('member_id', $member->id)
            ->where('type', MemberSecurityEvent::TYPE_FACE_SELF_ENROLLED)
            ->exists()) {
            return 'face_enrollment_reception';
        }
        if ($member->is_minor) {
            return 'minor_requires_guardian';
        }
        if (! $this->hasOtpProof($request, $member)) {
            return 'otp_session_required';
        }

        return null;
    }

    /**
     * Cualquier referencia cuenta, también la desactivada: un rostro revocado
     * no autoriza a registrar otro desde la app.
     */
    private function hasReference(Member $member): bool
    {
        return $member->biometric_status === Member::BIOMETRIC_REGISTERED
            || $member->biometric()->exists();
    }

    /**
     * La sesión es de dispositivo (no el access_hash del alta) y su equipo
     * está vinculado a este socio con un OTP real reciente: bindDevice() solo
     * fecha last_otp_reauth_at en las entradas con código (no en el modo sin
     * teléfono ni en el desbloqueo local).
     */
    private function hasOtpProof(Request $request, Member $member): bool
    {
        $session = $this->session($request);
        if (! $session || (int) $session->member_id !== (int) $member->id) {
            return false;
        }

        $binding = MemberDeviceBinding::forDevice($session->device_id);
        if (! $binding || (int) $binding->member_id !== (int) $member->id || $binding->last_otp_reauth_at === null) {
            return false;
        }

        $days = (int) config('security.trusted_reauth_days', 30);

        return $days <= 0 || $binding->last_otp_reauth_at->gt(now()->subDays($days));
    }

    private function session(Request $request): ?MemberDeviceSession
    {
        $session = $request->attributes->get('auth_device_session');

        return $session instanceof MemberDeviceSession ? $session : null;
    }

    /** @return array{version: string, title: string, text: string} */
    private function consent(): array
    {
        return [
            'version' => (string) config('members.biometric_consent.version'),
            'title' => (string) config('members.biometric_consent.title'),
            'text' => (string) config('members.biometric_consent.text'),
        ];
    }

    /**
     * 409 si la cuenta ya tiene (o tuvo) rostro, 403 si falta la prueba de
     * identidad o la edad, 422 si la autorización no es la vigente. Nunca 401:
     * la app cierra la sesión ante un 401.
     */
    private function reject(Member $member, string $code): JsonResponse
    {
        Log::warning('member:face-enrollment:rejected', ['member_id' => $member->id, 'reason' => $code]);

        $status = match ($code) {
            'face_already_registered', 'face_enrollment_reception' => 409,
            'consent_outdated' => 422,
            default => 403,
        };

        return response()->json(['ok' => false, 'code' => $code, 'message' => self::MESSAGES[$code]], $status);
    }
}
