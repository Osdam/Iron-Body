<?php

namespace App\Services\Telephony;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Un rechazo de la telefonía con su código cerrado y su estado HTTP (como
 * `UltronStudioException`). El mensaje es para una persona y NUNCA lleva un
 * teléfono, un secreto, SQL ni una ruta; `payload` solo códigos.
 */
final class TelephonyException extends RuntimeException
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

    /** Un rechazo esperado no se registra como error de la aplicación. */
    public function report(): bool
    {
        return $this->status < 500;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge(['ok' => false, 'code' => $this->errorCode, 'message' => $this->getMessage()], $this->payload), $this->status);
    }

    public static function personRequired(): self
    {
        return new self('La voz la opera una persona: entra con tu cuenta del CRM.', 'person_required', 403);
    }

    public static function forbidden(string $permission): self
    {
        return new self('No tienes permiso para esta acción.', 'forbidden', 403, ['required_permission' => $permission]);
    }

    public static function notFound(string $what): self
    {
        return new self('No existe.', $what.'_not_found', 404);
    }

    /** @param  array<string, list<string>>  $fields  campo → códigos, sin valores */
    public static function invalid(array $fields): self
    {
        return new self('Revisa los datos enviados.', 'validation_failed', 422, ['errors' => $fields]);
    }

    public static function conflict(string $code, string $message, array $payload = []): self
    {
        return new self($message, $code, 409, $payload);
    }

    public static function fourEyes(): self
    {
        return new self('Nadie confirma lo que cargó: pide a otra persona con permiso que lo confirme.', 'four_eyes_required', 403);
    }
}
