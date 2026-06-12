<?php

namespace App\Services\Wompi;

use InvalidArgumentException;

/**
 * Máquina de estados interna del pago (independiente de la pasarela). Lógica
 * PURA y determinística — sin BD ni side effects — para poder testearla con
 * vectores fijos. La aplicación de la transición a la fila (con lockForUpdate)
 * vive en WompiTransactionService.
 *
 * Estados:
 *   created          intento creado, aún sin enviar a la pasarela.
 *   tokenizing       (tarjeta) esperando token desde el cliente.
 *   pending          enviado a Wompi, esperando resultado.
 *   requires_action  requiere autenticación externa (PSE / 3DS / OTP).
 *   approved   (T)   pago aprobado → ÚNICO estado que activa membresía.
 *   declined   (T)   rechazado por el banco/procesador.
 *   voided     (T)   anulado.
 *   error      (T)   error del procesador.
 *   expired    (T)   venció sin completarse.
 *
 * (T) = terminal. Reglas clave:
 *   - approved es definitivo: no se sale de él jamás.
 *   - un estado terminal no regresa a pending/requires_action.
 *   - solo approved activa membresía.
 */
class PaymentStateMachine
{
    public const CREATED         = 'created';
    public const TOKENIZING      = 'tokenizing';
    public const PENDING         = 'pending';
    public const REQUIRES_ACTION = 'requires_action';
    public const APPROVED        = 'approved';
    public const DECLINED        = 'declined';
    public const VOIDED          = 'voided';
    public const ERROR           = 'error';
    public const EXPIRED         = 'expired';

    public const TERMINAL = [
        self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
    ];

    /** Estados "en vuelo": admiten avanzar y se reconcilian. */
    public const IN_FLIGHT = [
        self::CREATED, self::TOKENIZING, self::PENDING, self::REQUIRES_ACTION,
    ];

    /** Transiciones permitidas: origen => [destinos válidos]. */
    private const GRAPH = [
        self::CREATED => [
            self::TOKENIZING, self::PENDING, self::REQUIRES_ACTION,
            self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
        ],
        self::TOKENIZING => [
            self::PENDING, self::REQUIRES_ACTION,
            self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
        ],
        self::PENDING => [
            self::PENDING, self::REQUIRES_ACTION,
            self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
        ],
        self::REQUIRES_ACTION => [
            self::REQUIRES_ACTION, self::PENDING,
            self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
        ],
        // Terminales: no salen (idempotencia / anti doble pago).
        self::APPROVED => [],
        self::DECLINED => [],
        self::VOIDED   => [],
        self::ERROR    => [],
        self::EXPIRED  => [],
    ];

    /** Mapa estado Wompi → estado interno. */
    public function mapWompiStatus(string $wompiStatus): string
    {
        return match (strtoupper(trim($wompiStatus))) {
            'APPROVED' => self::APPROVED,
            'DECLINED' => self::DECLINED,
            'VOIDED'   => self::VOIDED,
            'ERROR'    => self::ERROR,
            'PENDING'  => self::PENDING,
            default    => self::PENDING, // desconocido → pending (nunca aprueba)
        };
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public function isInFlight(string $state): bool
    {
        return in_array($state, self::IN_FLIGHT, true);
    }

    /** Solo approved activa membresía. */
    public function activatesMembership(string $state): bool
    {
        return $state === self::APPROVED;
    }

    /**
     * ¿Es válida la transición $from → $to?
     *
     * - Cualquier estado puede "transicionar" a sí mismo SOLO si no es terminal
     *   (refresco idempotente de pending/requires_action). approved→approved es
     *   no-op permitido aparte (ver applyGuards en el servicio), pero aquí lo
     *   tratamos como NO transición para forzar el camino idempotente.
     */
    public function canTransition(string $from, string $to): bool
    {
        if (! array_key_exists($from, self::GRAPH)) {
            throw new InvalidArgumentException("Estado origen desconocido: {$from}");
        }
        if (! in_array($to, $this->allStates(), true)) {
            throw new InvalidArgumentException("Estado destino desconocido: {$to}");
        }
        return in_array($to, self::GRAPH[$from], true);
    }

    /**
     * Resuelve el estado siguiente de forma SEGURA: si la transición no está
     * permitida (p. ej. degradar un terminal a pending, o salir de approved), se
     * conserva el estado actual. Devuelve el estado que debe quedar persistido.
     */
    public function resolveNext(string $current, string $target): string
    {
        if ($current === $target) {
            return $current;
        }
        // approved es absorbente.
        if ($current === self::APPROVED) {
            return self::APPROVED;
        }
        // No degradar un terminal a un estado en vuelo.
        if ($this->isTerminal($current) && $this->isInFlight($target)) {
            return $current;
        }
        return $this->canTransition($current, $target) ? $target : $current;
    }

    /** Columna *_at que corresponde a un estado terminal (o null). */
    public function timestampColumnFor(string $state): ?string
    {
        return match ($state) {
            self::APPROVED => 'approved_at',
            self::DECLINED => 'declined_at',
            self::VOIDED   => 'voided_at',
            self::ERROR    => 'failed_at',
            self::EXPIRED  => 'expires_at',
            default        => null,
        };
    }

    /** @return string[] */
    public function allStates(): array
    {
        return [
            self::CREATED, self::TOKENIZING, self::PENDING, self::REQUIRES_ACTION,
            self::APPROVED, self::DECLINED, self::VOIDED, self::ERROR, self::EXPIRED,
        ];
    }
}
