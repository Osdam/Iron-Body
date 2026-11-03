<?php

namespace App\Services\Twilio;

use InvalidArgumentException;

/**
 * La firma `X-Twilio-Signature` de lo que la SUBCUENTA DE VOZ envía a Laravel
 * (contrato H8, D8-4 y D8-16; auditoría §c.2 OTP-3 y §d.2). NUEVA y separada
 * del validador privado del OTP, que no se toca.
 *
 * EL ALGORITMO (documentación oficial de seguridad de webhooks y
 * `RequestValidator` de los SDK oficiales; los 6 vectores oficiales de
 * `twilio/twilio-python` lo fijan en las pruebas):
 *  - base = la URL EXACTA (sin decodificar ni recodificar) y, por cada clave
 *    de los parámetros POST en orden de bytes, la clave seguida de cada uno de
 *    sus valores DISTINTOS en orden de bytes (las claves repetidas cuentan);
 *  - firma = base64(HMAC-SHA1(token, base));
 *  - con cuerpo JSON, Twilio añade `bodySHA256` (sha256 hex del cuerpo crudo)
 *    a la consulta de la URL: no hay parámetros, la base es la URL y el
 *    sha256 del cuerpo tiene que ser exactamente ese valor;
 *  - vale contra la URL sin puerto o con él (los SDK prueban las dos: quitan
 *    cualquier puerto, o ponen el de por defecto si no lo hay).
 *
 * LA URL ES LA BASE CANÓNICA CONFIGURADA + LA URI TAL COMO LLEGA
 * ({@see canonicalUrl()}), NUNCA `fullUrl()`: `fullUrl()` reordena la
 * consulta, quita los puertos 80 y 443 y, tras un proxy TLS sin
 * `trustProxies`, ve `http` (el fallo T-1 del validador del OTP).
 *
 * Todo es PURO salvo {@see verifyRequest()} y {@see secretFingerprints()}, que
 * son los únicos sitios que leen el token de firma de la subcuenta
 * (`voice.twilio.signing_token`, D8-5: lector enumerado), en el momento de
 * usarlo y sin guardarlo. Las comparaciones son en tiempo constante.
 */
final class TwilioRequestSignature
{
    public const OK = 'ok';

    public const MISSING_SIGNATURE = 'missing_signature';

    public const MALFORMED_SIGNATURE = 'malformed_signature';

    /** Con cuerpo JSON: falta `bodySHA256`, está repetido, no tiene forma o no es el sha256 del cuerpo. */
    public const BODY_HASH_MISMATCH = 'body_hash_mismatch';

    public const MISMATCH = 'mismatch';

    /** Solo {@see verifyRequest()}: falta el token de firma o la base canónica. */
    public const NOT_CONFIGURED = 'not_configured';

    /** Solo {@see verifyRequest()}: la URI que llega no se puede firmar (no empieza por `/` o lleva espacios o un fragmento). */
    public const INVALID_URL = 'invalid_url';

    /** Conjunto cerrado, en su orden. */
    public const RESULTS = [self::OK, self::MISSING_SIGNATURE, self::MALFORMED_SIGNATURE, self::BODY_HASH_MISMATCH, self::MISMATCH, self::NOT_CONFIGURED, self::INVALID_URL];

    /** base64 de los 20 bytes de un HMAC-SHA1. */
    public const SIGNATURE_PATTERN = '/^[A-Za-z0-9+\/]{27}=$/D';

    public const BODY_HASH_PARAM = 'bodySHA256';

    /** Las huellas de los secretos son los primeros 8 hexadecimales de un HMAC-SHA256 (como las del H6). */
    public const FINGERPRINT_HEX_LENGTH = 8;

    /**
     * La firma de una URL y sus parámetros POST: base64(HMAC-SHA1(token, {@see signingBase()})).
     *
     * @param  list<array{0:string, 1:string}>  $pairs  los pares clave → valor en su orden (las claves pueden repetirse)
     *
     * @throws InvalidArgumentException si $token === '' (un token vacío es un fallo de configuración, no una firma mala)
     */
    public static function compute(string $url, array $pairs, #[\SensitiveParameter] string $token): string
    {
        if ($token === '') {
            throw new InvalidArgumentException('El token de firma de Twilio no puede estar vacío.');
        }

        return base64_encode(hash_hmac('sha1', self::signingBase($url, $pairs), $token, true));
    }

    /**
     * El texto que se firma: la URL tal cual y, por clave en orden de bytes,
     * la clave seguida de cada uno de sus valores distintos en orden de bytes.
     * Pura y sin secretos (`'10'` va antes que `'9'`; las claves distinguen mayúsculas).
     *
     * @param  list<array{0:string, 1:string}>  $pairs
     *
     * @throws InvalidArgumentException si un par no es [clave, valor] de textos
     */
    public static function signingBase(string $url, array $pairs): string
    {
        $porClave = [];
        foreach ($pairs as $par) {
            if (! is_array($par) || ! is_string($par[0] ?? null) || ! is_string($par[1] ?? null)) {
                throw new InvalidArgumentException('Cada parámetro es un par [clave, valor] de textos.');
            }
            $porClave[$par[0]][] = $par[1];
        }
        ksort($porClave, SORT_STRING);

        $base = $url;
        foreach ($porClave as $clave => $valores) {
            $valores = array_values(array_unique($valores));
            sort($valores, SORT_STRING);
            foreach ($valores as $valor) {
                $base .= $clave.$valor;
            }
        }

        return $base;
    }

    /**
     * Verifica la firma de una URL con sus parámetros (formulario).
     *
     * @param  list<array{0:string, 1:string}>  $pairs
     * @return 'ok'|'missing_signature'|'malformed_signature'|'mismatch'
     *
     * @throws InvalidArgumentException si $token === ''
     */
    public static function verifyPairs(string $url, array $pairs, ?string $header, #[\SensitiveParameter] string $token): string
    {
        if ($token === '') {
            throw new InvalidArgumentException('El token de firma de Twilio no puede estar vacío.');
        }
        $firma = self::headerValue($header);
        if (! in_array($firma, [self::MISSING_SIGNATURE, self::MALFORMED_SIGNATURE], true)) {
            return self::matchesAnyVariant($url, $pairs, $firma, $token) ? self::OK : self::MISMATCH;
        }

        return $firma;
    }

    /**
     * Verifica un cuerpo `application/x-www-form-urlencoded`, con sus claves
     * repetidas (que `$request->post()` perdería).
     *
     * @return 'ok'|'missing_signature'|'malformed_signature'|'mismatch'
     */
    public static function verifyForm(string $url, string $rawBody, ?string $header, #[\SensitiveParameter] string $token): string
    {
        return self::verifyPairs($url, self::formPairs($rawBody), $header, $token);
    }

    /**
     * Verifica un cuerpo JSON: `bodySHA256` de la consulta tiene que ser,
     * exactamente, el sha256 hex en minúsculas del cuerpo crudo, y la firma es
     * la de la URL sola.
     *
     * @return 'ok'|'missing_signature'|'malformed_signature'|'body_hash_mismatch'|'mismatch'
     */
    public static function verifyJson(string $url, string $rawBody, ?string $header, #[\SensitiveParameter] string $token): string
    {
        if ($token === '') {
            throw new InvalidArgumentException('El token de firma de Twilio no puede estar vacío.');
        }
        $firma = self::headerValue($header);
        if (in_array($firma, [self::MISSING_SIGNATURE, self::MALFORMED_SIGNATURE], true)) {
            return $firma;
        }
        $huellas = self::queryValues($url, self::BODY_HASH_PARAM);
        if (count($huellas) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $huellas[0]) !== 1 || ! hash_equals($huellas[0], hash('sha256', $rawBody))) {
            return self::BODY_HASH_MISMATCH;
        }

        return self::matchesAnyVariant($url, [], $firma, $token) ? self::OK : self::MISMATCH;
    }

    /**
     * Los pares de un cuerpo de formulario crudo, en su orden y sin perder las
     * claves repetidas: se parte por `&`, cada parte por el PRIMER `=`, y se
     * decodifica como un formulario (`+` es un espacio). Una parte vacía se
     * ignora; una sin `=` es una clave con valor vacío.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function formPairs(string $rawBody): array
    {
        $out = [];
        foreach (explode('&', $rawBody) as $parte) {
            if ($parte === '') {
                continue;
            }
            $igual = strpos($parte, '=');
            $clave = $igual === false ? $parte : substr($parte, 0, $igual);
            $valor = $igual === false ? '' : substr($parte, $igual + 1);
            $out[] = [urldecode($clave), urldecode($valor)];
        }

        return $out;
    }

    /**
     * La URL que firmó Twilio: la base canónica configurada (esquema y host,
     * con puerto si lo tiene; SIN ruta, consulta ni fragmento) más la URI tal
     * como llega (`$request->getRequestUri()`: ruta y consulta crudas). Nada se
     * decodifica ni se reordena. null si alguna de las dos no tiene forma.
     */
    public static function canonicalUrl(string $base, string $requestUri): ?string
    {
        $base = trim($base);
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $partes = parse_url($base);
        if (! is_array($partes) || ! in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true) || ($partes['host'] ?? '') === ''
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment']) || ! in_array($partes['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        if ($requestUri === '' || $requestUri[0] !== '/' || str_starts_with($requestUri, '//') || str_contains($requestUri, '#') || preg_match('/[\x00-\x20\x7f]/', $requestUri) === 1) {
            return null;
        }

        return rtrim($base, '/').$requestUri;
    }

    /**
     * Verifica una petición de la subcuenta con el token de firma configurado.
     * El ÚNICO lector de `voice.twilio.signing_token` junto a
     * {@see secretFingerprints()}. Con `bodySHA256` en la consulta, como JSON;
     * si no, como formulario.
     *
     * @param  string  $requestUri  `$request->getRequestUri()`, tal cual
     * @return 'ok'|'missing_signature'|'malformed_signature'|'body_hash_mismatch'|'mismatch'|'not_configured'|'invalid_url'
     */
    public static function verifyRequest(string $requestUri, string $rawBody, ?string $header): string
    {
        $token = trim((string) config('voice.twilio.signing_token'));
        $base = config('voice.webhook_base_url');
        if ($token === '' || ! is_string($base) || self::canonicalUrl($base, '/') === null) {
            return self::NOT_CONFIGURED;
        }
        $url = self::canonicalUrl($base, $requestUri);
        if ($url === null) {
            return self::INVALID_URL;
        }

        return self::queryValues($url, self::BODY_HASH_PARAM) === []
            ? self::verifyForm($url, $rawBody, $header, $token)
            : self::verifyJson($url, $rawBody, $header, $token);
    }

    /**
     * La huella del token de firma, sin red: los primeros 8 hexadecimales de
     * `hash_hmac('sha256', <token recortado>, $key)`, o null si no está. La
     * clave la pone quien describe la configuración (S8); de aquí solo sale la huella.
     *
     * @return array{signing_token: ?string}
     *
     * @throws InvalidArgumentException si $key === ''
     */
    public static function secretFingerprints(#[\SensitiveParameter] string $key): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('La clave de las huellas no puede estar vacía.');
        }
        $token = trim((string) config('voice.twilio.signing_token'));

        return ['signing_token' => $token === '' ? null : substr(hash_hmac('sha256', $token, $key), 0, self::FINGERPRINT_HEX_LENGTH)];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** La firma de la cabecera recortada, o `missing_signature` / `malformed_signature`. */
    private static function headerValue(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return self::MISSING_SIGNATURE;
        }
        $firma = trim($header);

        return preg_match(self::SIGNATURE_PATTERN, $firma) === 1 ? $firma : self::MALFORMED_SIGNATURE;
    }

    /**
     * ¿Coincide la firma con la URL sin puerto o con puerto? Se comparan las
     * dos, cada una en tiempo constante y sin cortocircuito.
     *
     * @param  list<array{0:string, 1:string}>  $pairs
     */
    private static function matchesAnyVariant(string $url, array $pairs, string $firma, string $token): bool
    {
        $coincide = false;
        foreach (self::portVariants($url) as $variante) {
            $coincide = hash_equals(self::compute($variante, $pairs, $token), $firma) || $coincide;
        }

        return $coincide;
    }

    /**
     * La URL sin puerto y con puerto, como los SDK: sin = se quita cualquier
     * puerto; con = el que trae o, si no trae, el de por defecto del esquema.
     * El resto de la URL (ruta y consulta) no se toca.
     *
     * @return list<string>
     */
    private static function portVariants(string $url): array
    {
        if (preg_match('#^(https?)://([^/:?\#]+)(?::([0-9]{1,5}))?(.*)$#iD', $url, $m) !== 1) {
            return [$url];
        }
        [, $esquema, $host, $puerto, $resto] = $m;
        $porDefecto = strtolower($esquema) === 'https' ? '443' : '80';
        $sin = "{$esquema}://{$host}{$resto}";
        $con = "{$esquema}://{$host}:".($puerto !== '' ? $puerto : $porDefecto).$resto;

        return array_values(array_unique([$sin, $con]));
    }

    /**
     * Los valores crudos de una clave de la consulta de la URL, en su orden
     * (sin decodificar las claves que no son esa).
     *
     * @return list<string>
     */
    private static function queryValues(string $url, string $clave): array
    {
        $consulta = parse_url($url, PHP_URL_QUERY);
        if (! is_string($consulta) || $consulta === '') {
            return [];
        }
        $out = [];
        foreach (self::formPairs($consulta) as [$k, $v]) {
            if ($k === $clave) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
