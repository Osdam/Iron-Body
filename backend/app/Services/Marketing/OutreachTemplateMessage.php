<?php

namespace App\Services\Marketing;

use InvalidArgumentException;

/**
 * UNA PLANTILLA DE WHATSAPP de la automatización comercial (Hito 9, paquete
 * W9; contrato H9, D9-9.3 «W-B»): lo que la puerta de Marketing necesita para
 * crear el mensaje COMO HOY y para que el outbox lo entregue —también en sus
 * reintentos— como la plantilla que aprobó Meta, nunca como texto libre.
 *
 * La arma la automatización (`WhatsappTemplateChannel`) con la plantilla
 * APROBADA del Studio y sus marcadores ya resueltos por Laravel; Marketing no
 * decide nada de su contenido, solo aplica sus guardas y la entrega.
 * Inmutable y validada al nacer: un nombre, un idioma o un parámetro que Meta
 * rechazaría (Cloud API no admite saltos de línea, tabuladores ni más de
 * cuatro espacios seguidos en un parámetro) no llega a crear un mensaje.
 *
 * En `marketing_messages` vive así: `body` = el texto ya compuesto (lo que ve
 * el Inbox y lo que recibe la persona) y `metadata` =
 * `{kind: outreach_template, template: {name, language, category, hash,
 * parameters}, outreach_job_uuid}`. El outbox la reconstruye de ahí en cada
 * intento ({@see fromStored()}); si no puede, el mensaje NO sale.
 */
final class OutreachTemplateMessage
{
    /** `metadata.kind` de los salientes de plantilla del H9 (no es un aviso de pago: la pausa del agente los retiene). */
    public const KIND = 'outreach_template';

    public const LANGUAGES = ['es', 'es_CO'];

    public const CATEGORIES = ['marketing', 'utility'];

    /** Como las variables de una plantilla del Studio (`StudioMessageTemplates::MAX_VARIABLES`). */
    public const MAX_PARAMETERS = 10;

    public const PARAMETER_MAX = 200;

    /** El límite del cuerpo de una plantilla de Meta (y el de `bodyPreview` en el Studio). */
    public const BODY_MAX = 1024;

    private const NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * @param  string  $name  el nombre de la plantilla en Meta (`metaName` del Studio)
     * @param  string  $templateHash  la huella de la versión del Studio (`content_hash`)
     * @param  list<string>  $parameters  los valores de `{{1}}`…`{{n}}`, en orden
     * @param  string  $body  el texto compuesto (`bodyPreview` con los valores)
     *
     * @throws InvalidArgumentException si Meta no la aceptaría o la forma no es la del contrato
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly string $category,
        public readonly string $templateHash,
        public readonly array $parameters,
        public readonly string $body,
    ) {
        $problema = self::problem($name, $language, $category, $templateHash, $parameters, $body);
        if ($problema !== null) {
            throw new InvalidArgumentException("Plantilla de WhatsApp de la automatización comercial inválida: {$problema}.");
        }
    }

    /**
     * Los `components` de Cloud API: el cuerpo con sus parámetros de texto, en
     * orden (`{{1}}`…). Sin parámetros, ninguno.
     *
     * @return list<array<string, mixed>>
     */
    public function components(): array
    {
        if ($this->parameters === []) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(static fn (string $p): array => ['type' => 'text', 'text' => $p], $this->parameters),
        ]];
    }

    /**
     * Lo que queda en `marketing_messages.metadata`: el tipo, la plantilla
     * (para que el outbox la reconstruya en cada intento) y el trabajo de la
     * automatización que la originó.
     *
     * @return array{kind: string, template: array{name: string, language: string, category: string, hash: string, parameters: list<string>}, outreach_job_uuid: string}
     */
    public function metadata(string $outreachJobUuid): array
    {
        $uuid = strtolower($outreachJobUuid);
        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new InvalidArgumentException('El trabajo de la automatización comercial se nombra por su uuid.');
        }

        return [
            'kind' => self::KIND,
            'template' => [
                'name' => $this->name,
                'language' => $this->language,
                'category' => $this->category,
                'hash' => $this->templateHash,
                'parameters' => $this->parameters,
            ],
            'outreach_job_uuid' => $uuid,
        ];
    }

    /** ¿Es este `metadata` el de un saliente de plantilla del H9? */
    public static function isTemplate(mixed $metadata): bool
    {
        return is_array($metadata) && ($metadata['kind'] ?? null) === self::KIND;
    }

    /**
     * La plantilla de un mensaje ya guardado (el outbox, en cada intento);
     * null si no es de plantilla o si lo guardado no tiene la forma del
     * contrato (en ese caso NO se envía nada: ni texto libre ni plantilla).
     */
    public static function fromStored(mixed $metadata, ?string $body): ?self
    {
        if (! self::isTemplate($metadata) || ! is_array($metadata['template'] ?? null)) {
            return null;
        }
        $t = $metadata['template'];
        $parametros = $t['parameters'] ?? null;
        if (! is_string($t['name'] ?? null) || ! is_string($t['language'] ?? null) || ! is_string($t['category'] ?? null)
            || ! is_string($t['hash'] ?? null) || ! is_array($parametros) || ! array_is_list($parametros) || ! is_string($body)) {
            return null;
        }
        foreach ($parametros as $p) {
            if (! is_string($p)) {
                return null;
            }
        }
        if (self::problem($t['name'], $t['language'], $t['category'], $t['hash'], $parametros, $body) !== null) {
            return null;
        }

        return new self($t['name'], $t['language'], $t['category'], $t['hash'], $parametros, $body);
    }

    /** El primer motivo por el que Meta o el contrato no la aceptarían; null si vale. */
    private static function problem(string $name, string $language, string $category, string $hash, array $parameters, string $body): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'el nombre de Meta no tiene la forma [a-z0-9_]{1,64}';
        }
        if (! in_array($language, self::LANGUAGES, true)) {
            return 'idioma fuera del conjunto cerrado';
        }
        if (! in_array($category, self::CATEGORIES, true)) {
            return 'categoría fuera del conjunto cerrado';
        }
        if (preg_match(self::HASH_PATTERN, $hash) !== 1) {
            return 'la huella de la plantilla del Studio no es sha256';
        }
        if (! array_is_list($parameters) || count($parameters) > self::MAX_PARAMETERS) {
            return 'demasiados parámetros o sin orden';
        }
        foreach ($parameters as $p) {
            if (! is_string($p) || ! mb_check_encoding($p, 'UTF-8') || trim($p) === '' || mb_strlen($p) > self::PARAMETER_MAX
                || preg_match('/[\x00-\x1F\x7F]/u', $p) === 1 || str_contains($p, '     ')) {
                return 'un parámetro vacío, demasiado largo o con saltos, tabuladores o espacios seguidos (Cloud API lo rechaza)';
            }
        }
        if (! mb_check_encoding($body, 'UTF-8') || trim($body) === '' || mb_strlen($body) > self::BODY_MAX) {
            return 'el texto compuesto está vacío o pasa del límite de Meta';
        }

        return null;
    }
}
