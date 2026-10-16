<?php

namespace App\Support\Network;

/**
 * La RED de quien llama, para cupos por red y para atar la ventana de un alta.
 *
 * IPv4: la dirección tal cual. IPv6: su prefijo /64. Un /64 es la red de UN
 * abonado y, dentro de él, el teléfono rota sus direcciones temporales
 * (RFC 8981) sin cambiar de red. Por dirección completa, un cupo se esquiva
 * rotando direcciones y una ventana se cerraría sola a mitad del alta.
 *
 * SUPOSICIÓN: `$request->ip()` es la IP del cliente. El proyecto no configura
 * TrustProxies, así que es REMOTE_ADDR, que nginx pasa a PHP-FPM con la IP de
 * quien abrió la conexión. Si algún día se pone un CDN o un balanceador
 * delante sin configurar TrustProxies, todos los clientes compartirían la IP
 * del proxy: los cupos serían globales y la ventana del alta no distinguiría
 * redes. Si se configura TrustProxies, esto sigue valiendo sin cambios.
 */
final class ClientNetwork
{
    public static function of(?string $ip): string
    {
        $ip = trim((string) $ip);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }

        $bytes = (string) inet_pton($ip);

        // ::ffff:a.b.c.d es un cliente IPv4 visto por un socket de doble pila:
        // es esa IPv4, no el /64 que comparten todas las IPv4 del mundo.
        if (str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        return inet_ntop(substr($bytes, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
