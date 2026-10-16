<?php

namespace App\Support\Network;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Hacia dónde puede abrir conexiones el servidor cuando el destino lo escribe
 * alguien en el CRM: el relé HTTP o el ZKTeco del torniquete.
 *
 * PROHIBIDO siempre: el propio servidor (127.0.0.0/8, ::1, 0.0.0.0), el enlace
 * local —ahí viven los metadatos de la nube, 169.254.169.254, y en IPv6
 * fe80::/10 y fd00:ec2::254—, y multicast o reservado. Una IPv4 que viaja
 * dentro de una IPv6 (::ffff:…, NAT64) se juzga como la IPv4 que lleva.
 *
 * PERMITIDO: las redes privadas (10/8, 172.16/12, 192.168/16, fc00::/7). Un
 * relé Sonoff/Shelly/ESP32 o un ZKTeco viven en la LAN de la sede y para eso
 * existen estos endpoints. Lo que queda abierto hacia esas redes es CIEGO: sin
 * redirecciones, sin devolver ni guardar el cuerpo —solo el estado HTTP—, y
 * solo para una persona con `classes.manage`, con traza de auditoría.
 *
 * Se resuelve UNA vez y se conecta a esa misma IP (`forUrl()['pin']`). Si se
 * validara el nombre y luego se dejara resolver a curl, un DNS que cambia de
 * respuesta entre las dos consultas (DNS rebinding) llevaría la conexión a
 * 127.0.0.1 después de haber pasado el control.
 */
final class OutboundDestination
{
    private const FORBIDDEN = [
        '0.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96',
        'fe80::/10',
        'ff00::/8',
        'fd00:ec2::254/128',
    ];

    /** Prefijos IPv6 que llevan una IPv4 en sus últimos 32 bits. */
    private const EMBEDDED_IPV4 = ['::ffff:0:0/96', '64:ff9b::/96'];

    public static function isForbiddenIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && IpUtils::checkIp($ip, self::EMBEDDED_IPV4)) {
            return self::isForbiddenIp((string) inet_ntop(substr((string) inet_pton($ip), 12)));
        }

        return IpUtils::checkIp($ip, self::FORBIDDEN);
    }

    /**
     * IP a la que conectarse para `$host`, o null si no resuelve o si CUALQUIERA
     * de sus direcciones está prohibida.
     */
    public static function resolve(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isForbiddenIp($host) ? null : $host;
        }

        // gethostbynamel también resuelve las formas numéricas raras
        // (2130706433, 0x7f000001, 127.1) a la IPv4 que de verdad son.
        $ips = @gethostbynamel($host) ?: self::ipv6Of($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (self::isForbiddenIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * Destino de una URL de relé. Null si no se permite; si no, `pin` es la
     * entrada CURLOPT_RESOLVE (`host:puerto:ip`) que fija la IP ya validada, o
     * null cuando el host ya es una IP.
     *
     * @return array{pin: string|null}|null
     */
    public static function forUrl(string $url): ?array
    {
        // Barras invertidas, espacios o control: formas en que el host que ve
        // PHP y el que ve curl dejan de ser el mismo.
        if (preg_match('/[\\\\\s\x00-\x1f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        // Un relé se autentica por cabecera (auth_header), no con usuario en
        // la URL; «http://algo@127.0.0.1/» es la forma clásica de colar otro host.
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $ip = self::resolve($host);
        if ($ip === null) {
            return null;
        }

        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return ['pin' => null];
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return ['pin' => "{$bare}:{$port}:{$address}"];
    }

    /** @return list<string> */
    private static function ipv6Of(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA);

        return array_values(array_filter(array_map(
            fn (array $r): ?string => $r['ipv6'] ?? null,
            is_array($records) ? $records : [],
        )));
    }
}
