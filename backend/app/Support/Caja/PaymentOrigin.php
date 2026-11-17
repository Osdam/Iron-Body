<?php

namespace App\Support\Caja;

/**
 * De dónde nace un `payments`, y si por tanto pertenece a una caja física.
 *
 * NO es una heurística sobre el texto de `method`: es una clasificación por
 * PUNTO DE ENTRADA del código. Estos son los sitios del backend que crean filas
 * en `payments` (verificado sobre app/ y database/ el 17-11-2026):
 *
 *   1. PaymentController::store()                    → COUNTER o AUTOMATION
 *      POST /api/payments, tras ProtectAdminPaths. Con una persona del
 *      mostrador con sesión de administrador es COUNTER: el dinero cambia de
 *      manos allí. Con el token compartido de automatizaciones, que no es
 *      nadie, es AUTOMATION ({@see forCrmRequest()}).
 *
 *   2. ReceivableController::storePlan()             → COUNTER
 *      El plan a plazos: el asiento de devengo (`method = receivable`), sin
 *      turno; el dinero entra por los abonos.
 *
 *   3. PlanUpgrades::upgrade()                       → COUNTER
 *      La diferencia de una mejora de plan, en el turno abierto.
 *
 *   4. PaymentMembershipActivator::activate()        → GATEWAY
 *      Llamado por WompiTransactionService (`wompi`) y NequiPushPaymentService
 *      (`nequi`) al confirmarse la transacción. Se dispara por webhook, sin
 *      nadie presente y a cualquier hora.
 *
 *   5. ImportLegacyCrmCommand                        → LEGACY
 *      Importación del CRM anterior, referencias `MIGR-*` y `method='manual'`.
 *      Comando de consola; no forma parte de la operación diaria.
 *
 * QUÉ PLAN se puede cobrar por cada uno no lo decide el origen sino el canal
 * de venta, con su regla del modelo: las entradas 1 a 3 son la Caja de
 * Gimnasio y exigen Plan::isSellableAtCounter() (también la automatización,
 * que entra por la 1); la pasarela cobra lo vendido por la app
 * (Plan::isSellableInApp()) o por un link comercial (Plan::isSellable()), y se
 * comprueba al CREAR la transacción o el link: el webhook de una ya pagada
 * activa siempre, porque ese dinero es real.
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
     * Por dónde pagó el socio, dicho como lo diría el negocio.
     *
     * Es la pregunta de Analítica —«¿quién pagó por la app y quién en el
     * gimnasio?»—, y no coincide del todo con el origen técnico: para el
     * gimnasio da igual que el cobro lo registrara el mostrador; lo que cuenta
     * es que el dinero entró por la caja.
     */
    public function channel(): string
    {
        return match ($this) {
            self::COUNTER => 'gym',
            self::GATEWAY => 'app',
            self::LEGACY => 'legacy',
            self::AUTOMATION => 'automation',
        };
    }

    public function channelLabel(): string
    {
        return self::channelLabelFor($this->channel());
    }

    public static function channelLabelFor(string $channel): string
    {
        return match ($channel) {
            'gym' => 'Gimnasio (caja)',
            'app' => 'App',
            'legacy' => 'Sistema anterior',
            'automation' => 'Automatización',
            default => 'Sin clasificar',
        };
    }

    /** Lectura tolerante de la columna `payments.origin`. */
    public static function fromStored(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
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
