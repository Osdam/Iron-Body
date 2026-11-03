<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * DÓNDE y CON QUÉ LÍMITES se despliega una especificación (contrato H8, D8-7
 * paso 4 y D8-15): lo que no sale de compilar la versión y el perfil, sino de
 * la configuración del servidor en el momento de desplegar. Lo construye quien
 * publica (S8) o quien abre el laboratorio (LAB8); el mapper lo recibe junto a
 * la {@see VoiceAgentSpec}. Pura y sin secretos: las referencias son ids de
 * recursos del proveedor (un secreto guardado en su gestor, un webhook), no
 * sus valores.
 *
 *  - `purpose`: `publication` o `lab` (D8-7). Solo cambia el nombre y las
 *    etiquetas del agente: la proyección (lo que se compara para la deriva) es
 *    la misma, porque el laboratorio usa «la misma compilación».
 *  - `webhookBaseUrl`: la base canónica de Laravel (`VOICE_WEBHOOK_BASE_URL`),
 *    https; http solo hacia 127.0.0.1 o localhost (el banco).
 *  - `toolAuthSecretRef` e `initiationAuthSecretRef`: los ids de los secretos
 *    del gestor del proveedor que van en las cabeceras de las herramientas y
 *    del webhook de iniciación (D8-16).
 *  - `postCallWebhookRef`: el id del webhook post-llamada del workspace; con
 *    él, el agente fija sus eventos sin audio (D8-16). Opcional.
 *  - `dailyCallLimit` y `concurrencyLimit`: los `call_limits` del agente
 *    (D8-7 paso 4: el techo diario de D8-15 y `VOICE_MAX_CONCURRENT_CALLS`).
 *  - `transferNumbers`: el E.164 de cada destino de transferencia, por su
 *    `ref` (la huella que lleva la especificación), DESCIFRADO EN MEMORIA por
 *    quien despliega (D8-14: «en claro solo dentro de la carga compilada»). Va
 *    envuelto en `SensitiveParameterValue` (`dump()`, `var_dump()` y
 *    `print_r()` no lo enseñan y `serialize()` se niega); {@see toArray()}
 *    solo lleva las referencias.
 */
final class VoiceAgentTarget
{
    public const PURPOSE_PUBLICATION = 'publication';

    public const PURPOSE_LAB = 'lab';

    public const PURPOSES = [self::PURPOSE_LAB, self::PURPOSE_PUBLICATION];

    /** El id de un recurso del proveedor (secreto o webhook): no es un secreto. */
    public const REF_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    public const MAX_DAILY_CALL_LIMIT = 1000;

    /** D8-15: `VOICE_MAX_CONCURRENT_CALLS` se recorta a 1–4. */
    public const MAX_CONCURRENCY = 4;

    /** Los hosts que admiten http (el banco local). */
    public const LOCAL_HOSTS = ['127.0.0.1', 'localhost'];

    public readonly string $webhookBaseUrl;

    /** @var SensitiveParameterValue<array<string, string>> */
    private readonly SensitiveParameterValue $transferNumbers;

    /**
     * @param  array<string, string>  $transferNumbers  ref (64 hex) → E.164
     *
     * @throws InvalidArgumentException con el campo que falla, nunca con su valor
     */
    public function __construct(
        public readonly string $purpose,
        string $webhookBaseUrl,
        public readonly ?string $toolAuthSecretRef,
        public readonly ?string $initiationAuthSecretRef,
        public readonly ?string $postCallWebhookRef,
        public readonly int $dailyCallLimit,
        public readonly int $concurrencyLimit,
        #[\SensitiveParameter] array $transferNumbers = [],
    ) {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('purpose fuera del conjunto cerrado.');
        }
        $base = self::cleanBase($webhookBaseUrl);
        if ($base === null) {
            throw new InvalidArgumentException('webhookBaseUrl tiene que ser una base https limpia (http solo hacia el banco local).');
        }
        $this->webhookBaseUrl = $base;
        foreach (['toolAuthSecretRef' => $toolAuthSecretRef, 'initiationAuthSecretRef' => $initiationAuthSecretRef, 'postCallWebhookRef' => $postCallWebhookRef] as $campo => $ref) {
            if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
                throw new InvalidArgumentException("{$campo} no tiene la forma de un id del proveedor.");
            }
        }
        if ($dailyCallLimit < 1 || $dailyCallLimit > self::MAX_DAILY_CALL_LIMIT) {
            throw new InvalidArgumentException('dailyCallLimit fuera de 1–'.self::MAX_DAILY_CALL_LIMIT.'.');
        }
        if ($concurrencyLimit < 1 || $concurrencyLimit > self::MAX_CONCURRENCY) {
            throw new InvalidArgumentException('concurrencyLimit fuera de 1–'.self::MAX_CONCURRENCY.'.');
        }

        if (count($transferNumbers) > VoiceAgentSpec::MAX_TRANSFER_DESTINATIONS || ($transferNumbers !== [] && array_is_list($transferNumbers))) {
            throw new InvalidArgumentException('transferNumbers tiene que ser un mapa ref → E.164 de como mucho '.VoiceAgentSpec::MAX_TRANSFER_DESTINATIONS.' destinos.');
        }
        foreach ($transferNumbers as $ref => $numero) {
            if (! is_string($ref) || preg_match(VoiceAgentSpec::HASH_PATTERN, $ref) !== 1 || ! is_string($numero) || preg_match(VoiceAgentSpec::E164_PATTERN, $numero) !== 1) {
                throw new InvalidArgumentException('transferNumbers lleva una referencia o un número sin forma.');
            }
        }
        if (count(array_unique($transferNumbers)) !== count($transferNumbers)) {
            throw new InvalidArgumentException('transferNumbers lleva un número repetido.');
        }
        ksort($transferNumbers, SORT_STRING);
        $this->transferNumbers = new SensitiveParameterValue($transferNumbers);
    }

    /** @return array<string, string> ref → E.164, EN CLARO: solo para la carga del proveedor */
    public function transferNumbers(): array
    {
        return $this->transferNumbers->getValue();
    }

    /** @return array<string, mixed> sin secretos ni teléfonos (solo referencias) */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'webhookBaseUrl' => $this->webhookBaseUrl,
            'toolAuthSecretRef' => $this->toolAuthSecretRef,
            'initiationAuthSecretRef' => $this->initiationAuthSecretRef,
            'postCallWebhookRef' => $this->postCallWebhookRef,
            'dailyCallLimit' => $this->dailyCallLimit,
            'concurrencyLimit' => $this->concurrencyLimit,
            'transferRefs' => array_keys($this->transferNumbers->getValue()),
        ];
    }

    /**
     * La base sin barra final si es una URL limpia: https con host, o http
     * hacia el banco local; sin usuario, contraseña, consulta ni fragmento. Si
     * no, null. Puede llevar un prefijo de ruta (p. ej. `/backend`).
     */
    public static function cleanBase(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $partes = parse_url($url);
        if (! is_array($partes) || ($partes['host'] ?? '') === '' || isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])) {
            return null;
        }
        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        $host = strtolower((string) $partes['host']);
        if ($esquema !== 'https' && ! ($esquema === 'http' && in_array($host, self::LOCAL_HOSTS, true))) {
            return null;
        }

        return rtrim($url, '/');
    }
}
