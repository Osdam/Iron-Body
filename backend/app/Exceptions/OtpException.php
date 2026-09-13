<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error de negocio del flujo 2FA (código inválido, expirado, bloqueado, etc.).
 * Lleva el código HTTP a devolver y datos extra (intentos restantes, cooldown)
 * para que el controlador arme una respuesta clara para la app.
 */
class OtpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Se renderiza sola con la MISMA forma que arman los controladores que la
     * capturan a mano. Hace falta porque la política de coste puede cortar un
     * envío desde dentro de `startChallenge()`, y los controladores que lo
     * llaman (el login entre ellos) no la esperaban: sin esto, un 429 legítimo
     * saldría como un 500.
     */
    public function render(): JsonResponse
    {
        return response()->json(
            array_merge(['ok' => false, 'message' => $this->getMessage()], $this->extra),
            $this->status,
        );
    }
}
