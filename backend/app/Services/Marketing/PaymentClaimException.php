<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Ultron\UltronCommitException;
use RuntimeException;

/**
 * Motivo por el que un pago huérfano del CRM NO se enlaza a un socio.
 *
 * Cada precondición tiene su propio código estable y su propio estado HTTP,
 * por la misma razón que {@see UltronCommitException}
 * los lleva: al otro lado hay una pantalla que tiene que decirle a quien está
 * en recepción qué hacer, y «no se pudo» no le dice nada. «El teléfono no
 * coincide» se resuelve pidiendo la cédula; «ese pago ya está enlazado» se
 * resuelve mirando a qué socio; «no es un pago del CRM» no se resuelve.
 *
 * La separación 409/422 es deliberada: 422 es «esto que me mandas no puede
 * reclamarse nunca» (no es un link del CRM, el pago es viejo, el socio no tiene
 * usuario) y 409 es «el estado actual lo impide» (ya reclamado, no aprobado,
 * el teléfono no coincide, hay otro reclamo reciente) — reintentar tras
 * corregir el estado sí tiene sentido.
 *
 * El mensaje es para una persona del equipo: nunca lleva internals (rutas,
 * SQL, excepciones del proveedor) ni datos de terceros.
 */
class PaymentClaimException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** El id de la ruta no corresponde a ninguna transacción. */
    public static function unknownTransaction(): self
    {
        return new self('not_found', 'Ese pago no existe.', 404);
    }

    public static function notApproved(): self
    {
        return new self(
            'claim_not_approved',
            'Ese pago no está aprobado, así que no hay nada que enlazar.',
            409,
        );
    }

    /** Ya tiene dueño. Enlazarlo otra vez regalaría un segundo periodo. */
    public static function alreadyClaimed(): self
    {
        return new self(
            'already_claimed',
            'Ese pago ya está enlazado a un socio.',
            409,
        );
    }

    /**
     * No nació de un link del CRM. Sin esta puerta, el endpoint sería una vía
     * para colgarle a cualquier socio el pago de cualquier otro.
     */
    public static function notAMarketingClaim(): self
    {
        return new self(
            'not_a_marketing_claim',
            'Ese pago no viene de un link de WhatsApp del CRM y no se reclama por aquí.',
            422,
        );
    }

    public static function expired(int $days): self
    {
        return new self(
            'claim_expired',
            'Ese pago tiene más de '.$days.' días: ya no explica un registro de ahora. Revísalo con administración.',
            422,
        );
    }

    /** Sin plan no hay periodo que activar; el enlace no serviría de nada. */
    public static function withoutPlan(): self
    {
        return new self(
            'claim_without_plan',
            'Ese pago no tiene plan asociado, así que no hay membresía que activar.',
            422,
        );
    }

    /** Un socio suspendido no se reactiva desde aquí. */
    /** Ya se descartó una vez: revertirlo exige responder por ello. */
    public static function alreadyRejected(): self
    {
        return new self(
            'claim_already_rejected',
            'Este reclamo ya se descartó antes. Si respondes por el enlace, márcalo como forzado.',
            409,
        );
    }

    public static function memberSuspended(): self
    {
        return new self(
            'member_suspended',
            'Ese socio está suspendido. Levantar la suspensión es otra decisión y no se toma desde un reclamo de pago.',
            409,
        );
    }

    public static function memberWithoutUser(): self
    {
        return new self(
            'member_without_user',
            'Ese socio todavía no tiene cuenta en la app. Pídele que complete el registro y vuelve a intentarlo.',
            422,
        );
    }

    /** El lead del prefijo se borró: sin él no hay conversación que cerrar. */
    public static function leadNotFound(): self
    {
        return new self(
            'lead_not_found',
            'No encontramos el prospecto que originó ese pago.',
            422,
        );
    }

    /**
     * El teléfono no coincide. Se rechaza en vez de enlazar: el número es la
     * ÚNICA pista que trae la propuesta y, si falla, no queda ninguna prueba.
     * Quien tenga otra (la cédula, el comprobante) puede firmarlo con `force`.
     */
    /**
     * No hay ninguna propuesta viva para ese prospecto.
     *
     * Aceptar algo que nadie propuso no es aceptar: sería una vía para enlazar
     * dinero a mano sin que ningún hecho del CRM lo respalde.
     */
    public static function withoutProposal(): self
    {
        return new self(
            'no_live_proposal',
            'No hay una revisión de reclamo pendiente para ese prospecto. Si aun así respondes por el enlace, márcalo como forzado.',
            409,
        );
    }

    public static function phoneMismatch(): self
    {
        return new self(
            'phone_mismatch',
            'El teléfono del socio no coincide con el del prospecto que pagó. Verifica la identidad y, si respondes por ello, márcalo como forzado.',
            409,
        );
    }

    /**
     * Ya se le enlazó otro pago del mismo plan hace nada. Casi siempre son dos
     * personas resolviendo la misma alerta a la vez.
     */
    public static function recentDuplicate(int $days): self
    {
        return new self(
            'recent_duplicate_claim',
            'A ese socio ya se le enlazó otro pago del mismo plan en los últimos '.$days.' días. Compruébalo antes de enlazar este.',
            409,
        );
    }
}
