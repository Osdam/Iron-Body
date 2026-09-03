<?php

namespace App\Support\Caja;

/**
 * De dónde nace un `payments`, y si por tanto pertenece a una caja física.
 *
 * NO es una heurística sobre el texto de `method`: es una clasificación por
 * PUNTO DE ENTRADA del código. Solo existen tres sitios en todo el backend que
 * crean filas en `payments` (verificado sobre app/ y database/):
 *
 *   1. PaymentController::store()                    → COUNTER
 *      POST /api/payments, tras ProtectAdminPaths. Lo ejecuta una persona del
 *      mostrador con sesión de administrador. El dinero cambia de manos allí.
 *
 *   2. PaymentMembershipActivator::activate()        → GATEWAY
 *      Llamado por WompiTransactionService (`wompi`) y NequiPushPaymentService
 *      (`nequi`) al confirmarse la transacción. Se dispara por webhook, sin
 *      nadie presente y a cualquier hora.
 *
 *   3. ImportLegacyCrmCommand                        → LEGACY
 *      Importación del CRM anterior, referencias `MIGR-*` y `method='manual'`.
 *      Comando de consola; no forma parte de la operación diaria.
 *
 * La regla que se deriva:
 *
 *   COUNTER  → EXIGE turno gym abierto. Es dinero presencial, y sin caja
 *              abierta ese dinero quedaría fuera de todo arqueo, que es
 *              exactamente el agujero que este trabajo cierra.
 *   GATEWAY  → NUNCA se asocia a un turno. Un pago por pasarela a las 3 de la
 *              madrugada no puede depender de que alguien abriera la caja, y
 *              cuadrarlo contra billetes no tendría sentido.
 *   LEGACY   → NUNCA se asocia. Es historia, no operación.
 *
 * El origen se decide en el servidor a partir de qué código está corriendo.
 * Nunca llega en el payload: aceptarlo del cliente permitiría marcar un cobro
 * presencial como "de pasarela" y sacarlo del arqueo.
 */
enum PaymentOrigin: string
{
    case COUNTER = 'counter';
    case GATEWAY = 'gateway';
    case LEGACY = 'legacy';
    case AUTOMATION = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::COUNTER => 'Mostrador',
            self::GATEWAY => 'Pasarela',
            self::LEGACY => 'Migración',
            self::AUTOMATION => 'Automatización',
        };
    }

    /**
     * Origen de una petición al endpoint de cobro del CRM.
     *
     * El mismo endpoint lo usan dos cosas distintas: una PERSONA con sesión de
     * administrador —el mostrador— y el token compartido de automatizaciones,
     * que no es nadie. Distinguirlas importa: un turno de caja tiene un
     * responsable con nombre, y exigirle uno a un proceso desatendido rompería
     * integraciones legítimas sin que nadie pudiera abrirle la caja.
     *
     * Lo decide la presencia de actor, no un campo del payload.
     */
    public static function forCrmRequest(?object $actor): self
    {
        return $actor === null ? self::AUTOMATION : self::COUNTER;
    }

    /**
     * ¿Este pago debe pertenecer a un turno de caja del gimnasio?
     *
     * Solo el mostrador. Y como es una exigencia y no una preferencia, el
     * cobro se rechaza si no hay turno abierto: ver CashShiftService.
     */
    public function requiresOpenGymShift(): bool
    {
        return $this === self::COUNTER;
    }
}
