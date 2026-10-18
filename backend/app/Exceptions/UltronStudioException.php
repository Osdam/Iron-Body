<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lo que ULTRON STUDIO no permite, dicho para el CRM: un borrador que cambió en
 * otra pestaña, una versión que no admite esa transición, aprobar lo propio,
 * publicar mientras el motor no lee versiones…
 *
 * Mismo patrón que {@see WorkforceException}: un código ESTABLE que el CRM usa
 * para decidir (contrato docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md §A3,
 * «Errores»), el estado HTTP y, cuando hace falta, datos que viajan junto al
 * error (`current`, `findings`, `warnings`, `currentStatus`…). Se lanza dentro
 * de la transacción cuando hay que deshacer: al salir no queda nada escrito.
 *
 * Los mensajes no llevan internals (consultas, rutas, clases): solo lo que la
 * persona necesita para seguir.
 */
class UltronStudioException extends RuntimeException
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Un rechazo del dominio es una respuesta prevista, no un fallo del
     * servidor: no va al log de errores, donde taparía los de verdad. Lo que
     * importa para la traza ya queda en `ultron_studio_audit_events`.
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
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->payload), $this->status);
    }

    // ── 403 ─────────────────────────────────────────────────────────────────

    public static function personRequired(): self
    {
        return new self('El Studio de ULTRON lo usa una persona: entra con tu cuenta del CRM.', 'person_required', 403);
    }

    public static function forbidden(string $permission): self
    {
        return new self('No tienes permiso para esta acción.', 'forbidden', 403, ['required_permission' => $permission]);
    }

    public static function notVersionOwner(): self
    {
        return new self('Solo quien creó la versión puede enviarla a revisión.', 'not_version_owner', 403);
    }

    public static function fourEyesRequired(): self
    {
        return new self('Nadie aprueba ni rechaza lo que creó: pide a otra persona con permiso que revise esta versión.', 'four_eyes_required', 403);
    }

    // ── 404 ─────────────────────────────────────────────────────────────────

    public static function versionNotFound(): self
    {
        return new self('La versión no existe.', 'version_not_found', 404);
    }

    public static function ruleNotFound(): self
    {
        return new self('La regla no existe.', 'rule_not_found', 404);
    }

    public static function draftNotFound(): self
    {
        return new self('No tienes un borrador guardado. Guárdalo antes de crear una versión: la versión es una copia del borrador guardado.', 'draft_not_found', 404);
    }

    // ── 409 ─────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $current  el borrador tal como está en el servidor (forma de GET draft) */
    public static function draftConflict(array $current): self
    {
        return new self(
            'El borrador cambió en otra pestaña o en otro equipo. Recarga para ver lo guardado, o sobrescríbelo.',
            'draft_conflict',
            409,
            ['current' => $current],
        );
    }

    /** @param  array<string, mixed>  $current  la regla tal como está en el servidor */
    public static function ruleConflict(array $current): self
    {
        return new self('La regla cambió mientras la editabas. Revisa cómo quedó antes de seguir.', 'rule_conflict', 409, ['current' => $current]);
    }

    /** @param  list<string>  $allowedFrom */
    public static function invalidTransition(string $currentStatus, string $currentLabel, string $action, array $allowedFrom): self
    {
        return new self(
            "Una versión en estado «{$currentLabel}» no admite «{$action}».",
            'invalid_transition',
            409,
            ['currentStatus' => $currentStatus, 'allowedFrom' => $allowedFrom],
        );
    }

    /** @param  list<string>  $warnings  los avisos actuales: lo que hay que reconocer */
    public static function warningsNotAcknowledged(array $warnings): self
    {
        return new self(
            'Para aprobar hay que reconocer exactamente los avisos actuales de la versión, ni uno más ni uno menos.',
            'warnings_not_acknowledged',
            409,
            ['warnings' => $warnings],
        );
    }

    /** @param  list<array<string, mixed>>  $findings */
    public static function revalidationBlocked(array $findings): self
    {
        return new self(
            'La versión ya no pasa la validación con el catálogo actual: tiene bloqueos y no se puede aprobar.',
            'revalidation_blocked',
            409,
            ['findings' => $findings],
        );
    }

    public static function integrityMismatch(): self
    {
        return new self(
            'La huella de la versión no coincide con su contenido. No se puede enviar ni aprobar: avisa a quien administra el sistema.',
            'integrity_mismatch',
        );
    }

    public static function publishingRefused(string $code, string $message): self
    {
        return new self($message, $code);
    }

    public static function legacyRuleReadOnly(): self
    {
        return new self(
            'Las reglas del código son de solo lectura: las aplica el motor y solo cambian cuando cambia el código.',
            'legacy_rule_read_only',
        );
    }

    public static function ruleArchived(): self
    {
        return new self('La regla está archivada: ya no se edita ni se vuelve a archivar.', 'rule_archived');
    }

    public static function clientRequestReused(): self
    {
        return new self(
            'Ese identificador de petición ya se usó para otra operación. Vuelve a intentarlo desde el Studio.',
            'client_request_reused',
        );
    }

    // ── 422 ─────────────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $findings */
    public static function validationBlocked(string $message, array $findings): self
    {
        return new self($message, 'validation_blocked', 422, ['findings' => $findings]);
    }
}
