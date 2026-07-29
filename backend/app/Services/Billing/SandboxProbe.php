<?php

namespace App\Services\Billing;

/**
 * Dos autorizaciones que deliberadamente NO viven en configuración.
 *
 * Todo lo que se pueda activar desde `.env` acabará activado en producción
 * alguna madrugada. Estas dos concesiones solo pueden concederse escribiendo
 * código que las pida de forma explícita, y duran lo que dure el proceso:
 *
 *  1. {@see REFERENCE_PREFIX} marca las emisiones de prueba del comando
 *     `billing:test-factus-sandbox`, que no corresponden a ninguna venta y por
 *     tanto no tienen factura en la base. La barrera las deja pasar solo si el
 *     ambiente completo es sandbox.
 *
 *  2. {@see authorizeRejectedRetry()} habilita reintentar una solicitud que el
 *     proveedor rechazó. Un rechazo sin CUFE no consumió consecutivo y es
 *     técnicamente reintentable, pero tuvo una causa: reintentar a ciegas
 *     repetiría el error contra la DIAN.
 */
final class SandboxProbe
{
    /** Prefijo que identifica una emisión de prueba, nunca una venta. */
    public const REFERENCE_PREFIX = 'SANDBOX-PROBE-';

    /** @var array<int,true> */
    private static array $authorizedRetries = [];

    private static bool $active = false;

    /**
     * Ejecuta $fn con la sonda activa y la desactiva pase lo que pase.
     *
     * Mientras está activa, la barrera tributaria permite el POST — pero solo
     * si el ambiente NO es producción; esa comprobación la hace la barrera, no
     * este método, para que la concesión no pueda ampliarse desde aquí.
     */
    public static function run(callable $fn): mixed
    {
        self::$active = true;

        try {
            return $fn();
        } finally {
            self::$active = false;
        }
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Autoriza UN reintento de una solicitud rechazada, para este proceso.
     * No se persiste: si el proceso muere, la autorización desaparece.
     */
    public static function authorizeRejectedRetry(int $invoiceId): void
    {
        self::$authorizedRetries[$invoiceId] = true;
    }

    public static function rejectedRetryAuthorized(int $invoiceId): bool
    {
        return isset(self::$authorizedRetries[$invoiceId]);
    }

    /** Revoca todas las autorizaciones. Lo usan las pruebas entre casos. */
    public static function reset(): void
    {
        self::$authorizedRetries = [];
    }

    /** Referencia de prueba legible y única. */
    public static function reference(): string
    {
        return self::REFERENCE_PREFIX.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
    }
}
