<?php

namespace App\Services\Sms;

/**
 * Contrato de envío de SMS. El resto del sistema sólo conoce esta interfaz; el
 * proveedor concreto se elige por configuración (config/otp.php → driver).
 */
interface SmsSender
{
    /**
     * Envía un SMS. Devuelve true si el proveedor aceptó el mensaje.
     * Nunca debe lanzar: ante un fallo registra y devuelve false para que el
     * flujo de login decida cómo seguir.
     */
    public function send(string $to, string $message): bool;

    /** Identificador legible del canal (para logs/auditoría). */
    public function name(): string;
}
