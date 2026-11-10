<?php

namespace App\Services\Outreach\Run;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lo que la ejecución del H9 no permite, dicho para el CRM y la consola: un
 * código ESTABLE, el estado HTTP y, si hace falta, datos junto al error. El
 * mismo patrón que `UltronStudioException` y `TelephonyException`: se lanza
 * dentro de la transacción cuando hay que deshacer, y un rechazo del dominio
 * no va al log de errores (es una respuesta prevista). Los mensajes no llevan
 * internals ni datos personales.
 */
class OutreachException extends RuntimeException
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

    public static function personRequired(): self
    {
        return new self('Las automatizaciones comerciales las maneja una persona: entra con tu cuenta del CRM.', 'person_required', 403);
    }

    public static function forbidden(string $permission): self
    {
        return new self('No tienes permiso para esta acción.', 'forbidden', 403, ['required_permission' => $permission]);
    }

    public static function fourEyes(): self
    {
        return new self('Nadie suelta un freno que abrió: tiene que soltarlo otra persona, con otra cuenta.', 'four_eyes_required', 403);
    }

    public static function notFound(string $what): self
    {
        return new self('No existe.', $what.'_not_found', 404);
    }

    /** @param  array<string, list<string>>  $fields */
    public static function invalid(array $fields): self
    {
        return new self('Los datos no son válidos.', 'validation_failed', 422, ['errors' => $fields]);
    }

    /** @param  array<string, mixed>  $payload */
    public static function conflict(string $code, string $message, array $payload = []): self
    {
        return new self($message, $code, 409, $payload);
    }

    public static function disabled(): self
    {
        return new self('La automatización comercial está apagada en el servidor: la enciende quien administra el sistema.', 'outreach_disabled', 409);
    }
}
