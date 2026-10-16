<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Operación rechazada del dominio de personal: jornada, biometría, dispositivo
 * o autorización. Lleva el código estable del contrato
 * (docs/asistencia-laboral/CONTRATO.md), el estado HTTP y, si hace falta,
 * campos extra para que el cliente pinte la situación real (p. ej. la jornada
 * que ya está abierta).
 *
 * Excepción y no booleano: un `false` ignorado aquí es una jornada fantasma o
 * una identidad dada por buena.
 */
class WorkforceException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        string $message,
        public readonly string $code_,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Un rechazo del dominio es una respuesta prevista (rostro que no coincide,
     * equipo sin firma, jornada ya abierta), no un fallo del servidor: no va al
     * log de errores, donde taparía los de verdad. Lo que importa para
     * seguridad ya queda en sus tablas (`device_security_events`,
     * `trainer_audit_logs`, historial de la jornada). Un 5xx sí se registra.
     *
     * Laravel no registra la excepción si esto devuelve algo distinto de false.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => $this->code_,
            'message' => $this->getMessage(),
        ], $this->extra), $this->status);
    }

    // ── Dispositivo ─────────────────────────────────────────────────────────

    public static function deviceSignatureRequired(): self
    {
        return new self('Este dispositivo no está autorizado para esta operación.', 'device_signature_required', 401);
    }

    public static function deviceUnknown(): self
    {
        return new self('Este dispositivo no está vinculado. Pide a un administrador que lo vincule.', 'device_unknown', 401);
    }

    public static function deviceRevoked(): self
    {
        return new self('Este dispositivo fue revocado por la administración.', 'device_revoked', 403);
    }

    public static function challengeInvalid(): self
    {
        return new self('La verificación del dispositivo caducó. Intenta de nuevo.', 'device_challenge_invalid', 401);
    }

    public static function signatureInvalid(): self
    {
        return new self('No pudimos verificar este dispositivo.', 'device_signature_invalid', 401);
    }

    public static function deviceNotAuthorized(): self
    {
        return new self('Esta operación solo se puede hacer desde un dispositivo autorizado.', 'device_not_authorized', 403);
    }

    public static function pairingCodeInvalid(): self
    {
        return new self('El código de vinculación no es válido o ya caducó.', 'pairing_code_invalid', 422);
    }

    // ── Identidad y biometría ───────────────────────────────────────────────

    public static function employeeNotFound(): self
    {
        return new self('No encontramos a un empleado activo con ese documento.', 'employee_not_found', 404);
    }

    public static function employeeRefInvalid(): self
    {
        return new self('La identificación caducó. Vuelve a empezar.', 'employee_ref_invalid', 401);
    }

    public static function embeddingInvalid(): self
    {
        return new self('No pudimos procesar el rostro. Intenta de nuevo con buena luz.', 'embedding_invalid', 422);
    }

    public static function faceNoMatch(): self
    {
        return new self('El rostro no coincide. Intenta de nuevo o usa un código de administrador.', 'face_no_match', 422);
    }

    public static function faceAttemptsExceeded(int $minutes): self
    {
        return new self("Demasiados intentos. Espera {$minutes} minutos o usa un código de administrador.", 'face_attempts_exceeded', 429);
    }

    public static function biometricNotEnrolled(string $status): self
    {
        return new self(
            'Esta persona no tiene un rostro registrado. Un administrador debe autorizar el registro.',
            'biometric_not_enrolled',
            409,
            ['biometric' => ['status' => $status]],
        );
    }

    public static function notATrainer(): self
    {
        return new self('Esta persona no tiene acceso al portal de entrenadores.', 'not_a_trainer', 403);
    }

    public static function authorizationCodeInvalid(): self
    {
        return new self('El código no es válido o ya caducó.', 'authorization_code_invalid', 422);
    }

    /**
     * No hay ningún código vivo de ESA operación para esa persona: lo que se
     * escribió era de otra operación, de otra persona, o no se generó. El
     * mensaje lo arma AuthorizationCodeService: dice qué hace falta y, si hay
     * un código vigente de otra operación, para qué sirve y dónde se escribe.
     */
    public static function authorizationCodeNotIssued(string $message): self
    {
        return new self($message, 'authorization_code_not_issued', 422);
    }

    public static function authorizationCodeExpired(string $message): self
    {
        return new self($message, 'authorization_code_expired', 422);
    }

    public static function authorizationCodeUsed(string $message): self
    {
        return new self($message, 'authorization_code_used', 422);
    }

    public static function authorizationCodeAttemptsExceeded(): self
    {
        return new self('Demasiados intentos con este código. Pide uno nuevo a un administrador.', 'authorization_code_attempts_exceeded', 429);
    }

    public static function consentRequired(): self
    {
        return new self('Para registrar tu rostro debes aceptar la autorización de tratamiento de datos.', 'consent_required', 422);
    }

    public static function embeddingsInconsistent(): self
    {
        return new self('Las capturas no coinciden entre sí. Repite el registro mirando de frente a la cámara.', 'embeddings_inconsistent', 422);
    }

    public static function faceAlreadyLinked(): self
    {
        return new self('Este rostro ya está registrado para otra persona. Contacta a un administrador.', 'face_already_linked', 409);
    }

    public static function enrollmentInvalid(): self
    {
        return new self('El registro de rostro caducó. Vuelve a empezar.', 'enrollment_invalid', 401);
    }

    public static function faceAlreadyEnrolled(): self
    {
        return new self('Ya tienes un rostro registrado. Para cambiarlo, pide a un administrador que autorice uno nuevo.', 'face_already_enrolled', 409);
    }

    public static function enrollmentRequiresAuthorization(): self
    {
        return new self('Para registrar tu rostro necesitas la autorización de un administrador.', 'enrollment_requires_authorization', 409);
    }

    // ── Jornada ─────────────────────────────────────────────────────────────

    public static function verificationInvalid(): self
    {
        return new self('La verificación caducó. Vuelve a identificarte.', 'verification_invalid', 401);
    }

    public static function workdayNotApplicable(): self
    {
        return new self('Tu cargo no registra jornada laboral.', 'workday_not_applicable', 403);
    }

    /** @param array<string, mixed> $workday */
    public static function workdayAlreadyOpen(array $workday): self
    {
        return new self('Ya tienes una jornada activa.', 'workday_already_open', 409, ['workday' => $workday]);
    }

    /** @param array<string, mixed>|null $last */
    public static function noOpenWorkday(?array $last): self
    {
        return new self('No tienes una jornada activa para cerrar.', 'no_open_workday', 409, ['last' => $last]);
    }

    public static function idempotencyConflict(): self
    {
        return new self('Esta operación ya se usó para otra cosa. Vuelve a empezar.', 'idempotency_conflict', 409);
    }

    public static function workdayNotIncident(): self
    {
        return new self('Esta jornada no tiene una incidencia pendiente.', 'workday_not_incident', 409);
    }

    public static function clockOutInvalid(string $why): self
    {
        return new self($why, 'clock_out_invalid', 422);
    }

    public static function staffProfileExists(): self
    {
        return new self('Esta persona ya tiene un perfil de personal.', 'staff_profile_exists', 409);
    }

    // ── Ficha de una persona y vínculo con su ficha de socio ────────────────

    /**
     * Mismo código que {@see employeeNotFound()}, con un texto que no habla de
     * documentos: la ficha se abre por identidad. Se responde igual para una
     * identidad inexistente y para una que solo es de un socio, para que la
     * ficha no sirva para recorrer identidades de socios.
     */
    public static function staffRecordNotFound(): self
    {
        return new self('Esta persona no está registrada en el personal.', 'employee_not_found', 404);
    }

    /** El documento del alta ya no es de la persona que se comprobó. */
    public static function identityMismatch(): self
    {
        return new self('El documento ya no corresponde a la persona que se comprobó. Vuelve a comprobarlo.', 'identity_mismatch', 409);
    }

    /**
     * Inexistente o con otro documento: la misma respuesta, para no confirmar
     * qué ids de socio existen.
     */
    public static function memberNotFound(): self
    {
        return new self('No encontramos una ficha de socio con el documento de esta persona.', 'member_not_found', 404);
    }

    public static function memberLinkTrainerUnsupported(): self
    {
        return new self('Esta persona tiene perfil de entrenador: su ficha de socio no se vincula desde aquí.', 'member_link_trainer_unsupported', 409);
    }

    public static function memberLinkedElsewhere(): self
    {
        return new self('Esta ficha de socio ya está vinculada con otra persona.', 'member_linked_elsewhere', 409);
    }

    public static function identityHasMember(): self
    {
        return new self('Esta persona ya tiene otra ficha de socio vinculada.', 'identity_has_member', 409);
    }

    public static function memberWithoutPhone(): self
    {
        return new self('La ficha de socio no tiene teléfono: no se puede verificar por SMS.', 'member_without_phone', 422);
    }

    /** El vínculo con la ficha de socio está apagado (config/workforce.php). */
    public static function memberLinkDisabled(): self
    {
        return new self('La verificación de la ficha de socio está desactivada. Revisa la coincidencia en Miembros.', 'member_link_disabled', 409);
    }

    /**
     * Registro sin terminar (o cuenta no activa): la app pública lo puede
     * reanudar y reescribir, teléfono incluido, así que no prueba titularidad.
     */
    public static function memberRegistrationIncomplete(): self
    {
        return new self('La ficha de socio no tiene el registro terminado: no se puede verificar por SMS. Revísala en Miembros.', 'member_registration_incomplete', 409);
    }

    /** El código no fue (o ya no es) al teléfono propio de la ficha de socio. */
    public static function memberLinkDestinationMismatch(): self
    {
        return new self('El código no corresponde al teléfono actual de la ficha de socio. No se vinculó nada: pide uno nuevo.', 'member_link_destination_mismatch', 409);
    }

    /**
     * Un número recién puesto no prueba que la ficha sea de quien lo recibe:
     * pudo ponerlo quien ahora pide el código.
     */
    public static function memberPhoneRecentlyChanged(): self
    {
        return new self('El teléfono de la ficha de socio cambió hace poco: todavía no sirve para verificar. Revísalo en Miembros.', 'member_phone_recently_changed', 409);
    }

    /**
     * Rechazos del motor de OTP de socios, con un código estable de este
     * contrato. El texto es el del propio motor; los campos que la pantalla
     * necesita (`retry_after`, `remaining`) se conservan.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function otp(string $code, int $status, string $message, array $extra = []): self
    {
        return new self($message, $code, $status, $extra);
    }
}
