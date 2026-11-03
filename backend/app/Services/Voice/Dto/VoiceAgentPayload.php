<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * La carga de un proveedor para crear UN agente (contrato H8, D8-6 «Salidas» y
 * D8-7): la construye un mapper puro desde una {@see VoiceAgentSpec} y un
 * {@see VoiceAgentTarget}, y la consume `VoiceAgentDeployer::createAgent()`.
 *
 *  - `agentBody`: el cuerpo de creación del agente, SIN los ids de las
 *    herramientas (los da el proveedor al crearlas: son aleatorios y no entran
 *    en ninguna huella).
 *  - `tools`: las herramientas de servidor que el agente usa, por nombre.
 *  - `projection`: los campos de la carga que controla el compilador, en la
 *    forma con la que se comparan con lo que devuelve el proveedor al leer el
 *    agente (D8-7 paso 3 y D8-21): el proveedor puede añadir valores por
 *    defecto, así que no se compara la carga entera.
 *
 * Huellas (sha256 del JSON canónico, {@see VoiceCanonicalJson}), calculadas
 * aquí y no recibidas, para que no puedan descuadrar con su contenido:
 *  - `payloadHash` = `{mapperVersion, agent, tools}`: identifica los bytes que
 *    se envían (el nombre y las etiquetas, que dependen de `purpose`, incluidos);
 *  - `projectionHash` = la proyección: es la misma para `lab` y `publication`.
 *
 * LA CARGA LLEVA EN CLARO los destinos de transferencia y el prompt compilado:
 * el cuerpo, las herramientas y la proyección van envueltos en
 * `SensitiveParameterValue` (`dump()`, `var_dump()` y `print_r()` no los
 * enseñan y `serialize()` se niega). Se construye en memoria al desplegar y se
 * descarta: de ella se guardan solo las huellas.
 */
final class VoiceAgentPayload
{
    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const PROVIDERS = [self::PROVIDER_ELEVENLABS];

    public const MAPPER_VERSION_PATTERN = '/^[a-z][a-z0-9-]{0,40}\/[1-9][0-9]{0,3}$/D';

    /** Las herramientas de servidor de una carga (el catálogo cerrado de D8-10 tiene 4). */
    public const MAX_TOOLS = 4;

    public readonly string $payloadHash;

    public readonly string $projectionHash;

    /** @var list<string> en orden de bytes */
    public readonly array $toolNames;

    /** @var SensitiveParameterValue<array<string, mixed>> */
    private readonly SensitiveParameterValue $agentBody;

    /** @var SensitiveParameterValue<array<string, array<string, mixed>>> */
    private readonly SensitiveParameterValue $tools;

    /** @var SensitiveParameterValue<array<string, mixed>> */
    private readonly SensitiveParameterValue $projection;

    /**
     * @param  array<string, mixed>  $agentBody
     * @param  array<string, array<string, mixed>>  $tools  nombre → configuración de la herramienta
     * @param  array<string, mixed>  $projection
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $mapperVersion,
        public readonly string $specHash,
        public readonly string $purpose,
        #[\SensitiveParameter] array $agentBody,
        #[\SensitiveParameter] array $tools,
        #[\SensitiveParameter] array $projection,
    ) {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('provider fuera del conjunto cerrado.');
        }
        if (preg_match(self::MAPPER_VERSION_PATTERN, $mapperVersion) !== 1) {
            throw new InvalidArgumentException('mapperVersion no tiene la forma esperada.');
        }
        if (preg_match(VoiceAgentSpec::HASH_PATTERN, $specHash) !== 1) {
            throw new InvalidArgumentException('specHash no tiene la forma esperada.');
        }
        if (! in_array($purpose, VoiceAgentTarget::PURPOSES, true)) {
            throw new InvalidArgumentException('purpose fuera del conjunto cerrado.');
        }
        if ($agentBody === [] || array_is_list($agentBody)) {
            throw new InvalidArgumentException('agentBody tiene que ser un objeto con contenido.');
        }
        if ($projection === [] || array_is_list($projection)) {
            throw new InvalidArgumentException('projection tiene que ser un objeto con contenido.');
        }
        if (count($tools) > self::MAX_TOOLS || ($tools !== [] && array_is_list($tools))) {
            throw new InvalidArgumentException('tools tiene que ser un mapa nombre → configuración de como mucho '.self::MAX_TOOLS.'.');
        }
        foreach ($tools as $nombre => $config) {
            if (! is_string($nombre) || ! in_array($nombre, VoiceAgentSpec::TOOLS, true) || ! is_array($config) || $config === []) {
                throw new InvalidArgumentException('tools lleva una herramienta fuera del catálogo o sin configuración.');
            }
        }
        ksort($tools, SORT_STRING);

        $this->toolNames = array_keys($tools);
        $this->payloadHash = VoiceCanonicalJson::hash(['mapperVersion' => $mapperVersion, 'agent' => $agentBody, 'tools' => $tools]);
        $this->projectionHash = VoiceCanonicalJson::hash($projection);
        $this->agentBody = new SensitiveParameterValue($agentBody);
        $this->tools = new SensitiveParameterValue($tools);
        $this->projection = new SensitiveParameterValue($projection);
    }

    /** @return array<string, mixed> EN CLARO: solo para enviarlo al proveedor */
    public function agentBody(): array
    {
        return $this->agentBody->getValue();
    }

    /** @return array<string, array<string, mixed>> EN CLARO, en orden de bytes por nombre */
    public function tools(): array
    {
        return $this->tools->getValue();
    }

    /** @return array<string, mixed> EN CLARO: para comparar con lo observado, nunca para guardarla */
    public function projection(): array
    {
        return $this->projection->getValue();
    }

    /** @return array{provider:string, mapperVersion:string, specHash:string, purpose:string, payloadHash:string, projectionHash:string, toolNames:list<string>} sin contenido */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'mapperVersion' => $this->mapperVersion,
            'specHash' => $this->specHash,
            'purpose' => $this->purpose,
            'payloadHash' => $this->payloadHash,
            'projectionHash' => $this->projectionHash,
            'toolNames' => $this->toolNames,
        ];
    }
}
