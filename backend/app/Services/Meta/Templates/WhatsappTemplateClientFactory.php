<?php

namespace App\Services\Meta\Templates;

use App\Services\Meta\MetaAuthService;
use Illuminate\Support\Facades\Cache;

/**
 * Quién construye el cliente de plantillas: el doble o el de Graph. NEUTRO:
 * no lee la configuración de quien lo usa; la recibe (driver, versión de
 * Graph y base del simulador) y decide con dos reglas que no se negocian:
 *
 *  1. EN PRODUCCIÓN, SIEMPRE GRAPH. El doble escribiría aprobaciones que Meta
 *     no dio y quien las lee las daría por buenas: aunque la configuración
 *     diga `fake` (es su valor por defecto), en `production` no se construye
 *     ({@see FakeWhatsappTemplateClient::allowedHere()}). Graph en producción
 *     habla siempre con https://graph.facebook.com.
 *  2. EL DOBLE SOLO DONDE EXISTE (`local` y `testing`). En cualquier otro
 *     entorno (un `staging`, un nombre mal escrito) se usa Graph, que fuera de
 *     producción solo habla con la base del simulador; sin ella, cada llamada
 *     es `not_configured` y no sale a la red.
 *
 * Un driver que no es `fake` ni `graph` es `graph` (con las mismas dos reglas).
 * Una versión de Graph mal escrita es la de por defecto del cliente.
 */
final class WhatsappTemplateClientFactory
{
    public const DRIVER_GRAPH = 'graph';

    public const DRIVER_FAKE = 'fake';

    public const DRIVERS = [self::DRIVER_FAKE, self::DRIVER_GRAPH];

    /** El driver que de verdad se usa con esa configuración, en este entorno. */
    public static function effectiveDriver(mixed $configured): string
    {
        $driver = is_string($configured) ? strtolower(trim($configured)) : '';

        return $driver === self::DRIVER_FAKE && FakeWhatsappTemplateClient::allowedHere() ? self::DRIVER_FAKE : self::DRIVER_GRAPH;
    }

    /** La versión de Graph que usará el cliente con esa configuración. */
    public static function effectiveGraphVersion(mixed $configured): string
    {
        return is_string($configured) && preg_match(GraphWhatsappTemplateClient::GRAPH_VERSION_PATTERN, $configured) === 1
            ? $configured
            : GraphWhatsappTemplateClient::DEFAULT_GRAPH_VERSION;
    }

    public static function make(mixed $driver, mixed $graphVersion, mixed $simulatorBase): WhatsappTemplateClient
    {
        if (self::effectiveDriver($driver) === self::DRIVER_FAKE) {
            return new FakeWhatsappTemplateClient(Cache::store());
        }

        return new GraphWhatsappTemplateClient(
            app(MetaAuthService::class),
            self::effectiveGraphVersion($graphVersion),
            is_string($simulatorBase) ? $simulatorBase : null,
        );
    }
}
