<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * La especificación NEUTRA de un agente de voz (contrato H8, D8-6): lo que sale
 * de compilar una versión aprobada del Studio y un perfil de voz aprobado, sin
 * un solo nombre de ningún proveedor, sin secretos y SIN TELÉFONOS. La produce
 * el compilador del Studio (S8, `StudioVoiceCompiler::compile()`, como array
 * `{spec, specHash}`); esta clase es su LECTOR ESTRICTO en el espacio neutro,
 * que es lo que consume un mapper de proveedor (B8, `ElevenLabsAgentMapper`).
 * Pura: sin configuración, base, reloj ni red.
 *
 * LA FORMA (exactamente estas claves, con estos tipos; la fuente es el
 * compilador de S8 y los fixtures de TEST8):
 *
 *     contract          'ultron-voice-spec/1'
 *     compilerVersion   p. ej. 'ultron-voice-compiler/1'
 *     template          {version, hash}
 *     source            {studioVersionUuid, studioContentHash, catalogVersion, voiceProfileUuid, voiceProfileHash}
 *     language          'es'
 *     voiceId           el id de la voz del proveedor
 *     firstMessage      el aviso fijo [P-9], sin variables
 *     systemPrompt      el prompt compilado de la plantilla; solo variables de {@see VARIABLES} o `system__…`
 *     dynamicVariables  exactamente {@see VARIABLES}, con `caller_authorized = no` (fallo-cerrado, D8-11)
 *     overridesAllowed  false (D8-11: sin `conversation_config_override`)
 *     maxDurationSeconds 60–600 (D8-15)
 *     tools             [{name, description}] del catálogo cerrado de D8-10
 *     systemTools       [{name, description}]: `end_call` siempre; `transfer_to_number` si y solo si hay destinos
 *     transfer          {mode: 'conference', destinations: [{alias, ref, condition}]} (como mucho 3)
 *     prices            'catalog_tool_only' | 'never' [P-3]
 *     forbiddenPhrases  [texto]
 *     privacy           {retentionDays, storeAudio: false, recording: false} [P-7, P-8]
 *     voicemail         'hang_up' [P-10; Enmienda 1, E1-8 y E1-9: `leave_message` no existe]
 *
 * Un destino de transferencia es su alias y `ref`, la HUELLA de su teléfono:
 * el número no está aquí. Lo pone, descifrado en memoria, quien despliega
 * ({@see VoiceAgentTarget::transferNumbers()}); D8-14: «en claro solo dentro
 * de la carga compilada».
 *
 * Las comprobaciones de CONTENIDO (cifras monetarias, vocabulario de
 * descuento, URL, teléfonos o invisibles en los textos; cuatro ojos; huellas
 * de la versión y del perfil) son del compilador, que conoce el catálogo y la
 * plantilla. Aquí se comprueba la FORMA y las invariantes que protegen al
 * proveedor aunque el compilador fallara: el defecto fallo-cerrado, las
 * sobrescrituras cerradas, la grabación y el audio apagados, `end_call`, la
 * coherencia de la transferencia y que el prompt no nombra variables ajenas.
 *
 * `specHash` = sha256 del JSON canónico de la especificación tal cual
 * ({@see VoiceCanonicalJson}): el mismo valor que calcula el compilador con
 * `StudioContentHasher` (una prueba de paridad lo fija).
 */
final class VoiceAgentSpec
{
    public const CONTRACT = 'ultron-voice-spec/1';

    public const LANGUAGES = ['es'];

    // ── Herramientas (D8-10): catálogo CERRADO del H8 ──────────────────────

    public const TOOL_CONSULTAR_HORARIOS = 'consultar_horarios';

    public const TOOL_CONSULTAR_PLANES = 'consultar_planes';

    public const TOOL_NO_VOLVER_A_LLAMAR = 'no_volver_a_llamar';

    public const TOOL_SOLICITAR_HUMANO = 'solicitar_humano';

    /** En orden de bytes. */
    public const TOOLS = [self::TOOL_CONSULTAR_HORARIOS, self::TOOL_CONSULTAR_PLANES, self::TOOL_NO_VOLVER_A_LLAMAR, self::TOOL_SOLICITAR_HUMANO];

    public const SYSTEM_TOOL_END_CALL = 'end_call';

    public const SYSTEM_TOOL_TRANSFER = 'transfer_to_number';

    /** Las únicas herramientas de sistema admitidas (D8-6), en orden de bytes. */
    public const SYSTEM_TOOLS = [self::SYSTEM_TOOL_END_CALL, self::SYSTEM_TOOL_TRANSFER];

    /** D8-14: solo en conferencia (ni ciega ni SIP REFER). */
    public const TRANSFER_MODES = ['conference'];

    public const MAX_TRANSFER_DESTINATIONS = 3;

    // ── Variables dinámicas (D8-11 y D8-12, con la enmienda `call_direction` de S8) ──

    public const VAR_CALL_DIRECTION = 'call_direction';

    public const VAR_CALL_REF = 'call_ref';

    public const VAR_CALLER_AUTHORIZED = 'caller_authorized';

    public const VAR_VERSION_LABEL = 'version_label';

    /** El conjunto CERRADO, en orden de bytes. */
    public const VARIABLES = [self::VAR_CALL_DIRECTION, self::VAR_CALL_REF, self::VAR_CALLER_AUTHORIZED, self::VAR_VERSION_LABEL];

    public const CALLER_AUTHORIZED = 'si';

    public const CALLER_NOT_AUTHORIZED = 'no';

    public const DIRECTION_INBOUND = 'entrante';

    public const DIRECTION_OUTBOUND = 'saliente';

    public const DIRECTIONS = [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND];

    // ── Precios [P-3] y buzón [P-10] ────────────────────────────────────────

    public const PRICES_CATALOG_TOOL_ONLY = 'catalog_tool_only';

    public const PRICES_NEVER = 'never';

    public const PRICE_POLICIES = [self::PRICES_CATALOG_TOOL_ONLY, self::PRICES_NEVER];

    public const VOICEMAIL_HANG_UP = 'hang_up';

    /** Enmienda 1 (E1-8, E1-9): el buzón solo admite colgar; dejar un mensaje exigiría otra plantilla y otra enmienda. */
    public const VOICEMAIL_POLICIES = [self::VOICEMAIL_HANG_UP];

    // ── Límites de forma ────────────────────────────────────────────────────

    /** D8-15: la duración por llamada se recorta a 60–600 s en el H8 [C-A5]. */
    public const MIN_DURATION_SECONDS = 60;

    public const MAX_DURATION_SECONDS = 600;

    /** Cota de forma; el techo de [P-8] (30 días) lo aplica el compilador. */
    public const MIN_RETENTION_DAYS = 1;

    public const MAX_RETENTION_DAYS = 365;

    public const MAX_FIRST_MESSAGE = 1000;

    public const MAX_PROMPT = 20000;

    public const MAX_DESCRIPTION = 500;

    public const MAX_SHORT_TEXT = 300;

    public const MAX_FORBIDDEN_PHRASES = 200;

    public const ALIAS_MAX = 40;

    public const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    public const UUID_PATTERN = VoiceIds::UUID_PATTERN;

    /** `compilerVersion` y `template.version`: `nombre/entero`. */
    public const VERSION_PATTERN = '/^[a-z][a-z0-9-]{0,40}\/[1-9][0-9]{0,3}$/D';

    /** La versión del catálogo congelado, p. ej. `2026-10-19.1`. */
    public const CATALOG_VERSION_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[0-9]{1,3}$/D';

    /** El id de una voz del proveedor: letras y dígitos (no es un secreto). */
    public const VOICE_ID_PATTERN = '/^[A-Za-z0-9]{1,64}$/D';

    /** E.164 estricto (el de los canarios). */
    public const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    /** Una función, no una persona (el de los alias de los canarios); sin `\p{…}` (PCRE 10.39 en producción). */
    public const ALIAS_PATTERN = '/^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ .\-_()]+$/uD';

    /** Las claves de cada nivel, en orden de bytes. */
    private const KEYS = ['compilerVersion', 'contract', 'dynamicVariables', 'firstMessage', 'forbiddenPhrases', 'language', 'maxDurationSeconds', 'overridesAllowed',
        'prices', 'privacy', 'source', 'systemPrompt', 'systemTools', 'template', 'tools', 'transfer', 'voiceId', 'voicemail'];

    private const SOURCE_KEYS = ['catalogVersion', 'studioContentHash', 'studioVersionUuid', 'voiceProfileHash', 'voiceProfileUuid'];

    private const TEMPLATE_KEYS = ['hash', 'version'];

    private const TOOL_KEYS = ['description', 'name'];

    private const TRANSFER_KEYS = ['destinations', 'mode'];

    private const DESTINATION_KEYS = ['alias', 'condition', 'ref'];

    private const PRIVACY_KEYS = ['recording', 'retentionDays', 'storeAudio'];

    public readonly string $specHash;

    /** @param  array<string, mixed>  $spec  ya comprobada */
    private function __construct(private readonly array $spec)
    {
        $this->specHash = VoiceCanonicalJson::hash($spec);
    }

    /**
     * La lectura estricta de una especificación compilada.
     *
     * @param  array<string, mixed>  $spec
     *
     * @throws InvalidArgumentException con la ruta del campo que falla, nunca con su valor
     */
    public static function fromArray(array $spec): self
    {
        self::keys($spec, self::KEYS, '$');

        if ($spec['contract'] !== self::CONTRACT) {
            throw new InvalidArgumentException('contract desconocido.');
        }
        self::match($spec['compilerVersion'], self::VERSION_PATTERN, 'compilerVersion');

        $plantilla = self::object($spec['template'], 'template');
        self::keys($plantilla, self::TEMPLATE_KEYS, 'template');
        self::match($plantilla['version'], self::VERSION_PATTERN, 'template.version');
        self::match($plantilla['hash'], self::HASH_PATTERN, 'template.hash');

        $origen = self::object($spec['source'], 'source');
        self::keys($origen, self::SOURCE_KEYS, 'source');
        self::match($origen['studioVersionUuid'], self::UUID_PATTERN, 'source.studioVersionUuid');
        self::match($origen['studioContentHash'], self::HASH_PATTERN, 'source.studioContentHash');
        self::match($origen['catalogVersion'], self::CATALOG_VERSION_PATTERN, 'source.catalogVersion');
        self::match($origen['voiceProfileUuid'], self::UUID_PATTERN, 'source.voiceProfileUuid');
        self::match($origen['voiceProfileHash'], self::HASH_PATTERN, 'source.voiceProfileHash');

        if (! in_array($spec['language'], self::LANGUAGES, true)) {
            throw new InvalidArgumentException('language fuera del conjunto cerrado.');
        }
        self::match($spec['voiceId'], self::VOICE_ID_PATTERN, 'voiceId');
        self::plainText($spec['firstMessage'], 'firstMessage', 1, self::MAX_FIRST_MESSAGE);

        self::dynamicVariables($spec['dynamicVariables']);
        self::promptText($spec['systemPrompt']);

        if ($spec['overridesAllowed'] !== false) {
            throw new InvalidArgumentException('overridesAllowed tiene que ser false (D8-11).');
        }
        $duracion = $spec['maxDurationSeconds'];
        if (! is_int($duracion) || $duracion < self::MIN_DURATION_SECONDS || $duracion > self::MAX_DURATION_SECONDS) {
            throw new InvalidArgumentException('maxDurationSeconds tiene que ser un entero de '.self::MIN_DURATION_SECONDS.' a '.self::MAX_DURATION_SECONDS.'.');
        }

        $herramientas = self::namedList($spec['tools'], self::TOOLS, 'tools');
        $sistema = self::namedList($spec['systemTools'], self::SYSTEM_TOOLS, 'systemTools');
        if (! in_array(self::SYSTEM_TOOL_END_CALL, $sistema, true)) {
            throw new InvalidArgumentException('systemTools tiene que llevar end_call.');
        }
        unset($herramientas);

        $destinos = self::transfer($spec['transfer']);
        if (in_array(self::SYSTEM_TOOL_TRANSFER, $sistema, true) !== ($destinos > 0)) {
            throw new InvalidArgumentException('transfer_to_number va en systemTools si y solo si hay destinos de transferencia.');
        }

        if (! in_array($spec['prices'], self::PRICE_POLICIES, true)) {
            throw new InvalidArgumentException('prices fuera del conjunto cerrado.');
        }
        if (! is_array($spec['forbiddenPhrases']) || ! array_is_list($spec['forbiddenPhrases']) || count($spec['forbiddenPhrases']) > self::MAX_FORBIDDEN_PHRASES) {
            throw new InvalidArgumentException('forbiddenPhrases tiene que ser una lista de como mucho '.self::MAX_FORBIDDEN_PHRASES.' textos.');
        }
        foreach ($spec['forbiddenPhrases'] as $i => $frase) {
            self::plainText($frase, "forbiddenPhrases[{$i}]", 1, self::MAX_SHORT_TEXT);
        }

        $privacidad = self::object($spec['privacy'], 'privacy');
        self::keys($privacidad, self::PRIVACY_KEYS, 'privacy');
        $dias = $privacidad['retentionDays'];
        if (! is_int($dias) || $dias < self::MIN_RETENTION_DAYS || $dias > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException('privacy.retentionDays tiene que ser un entero de '.self::MIN_RETENTION_DAYS.' a '.self::MAX_RETENTION_DAYS.'.');
        }
        if ($privacidad['recording'] !== false) {
            throw new InvalidArgumentException('privacy.recording tiene que ser false: la grabación está apagada en el H8 [P-7].');
        }
        if ($privacidad['storeAudio'] !== false) {
            throw new InvalidArgumentException('privacy.storeAudio tiene que ser false: el audio guardado está apagado en el H8 [P-8].');
        }

        if (! in_array($spec['voicemail'], self::VOICEMAIL_POLICIES, true)) {
            throw new InvalidArgumentException('voicemail fuera del conjunto cerrado.');
        }

        return new self($spec);
    }

    /**
     * La salida del compilador (`{spec, specHash}`): además de la forma, exige
     * que la huella que trae sea la que se vuelve a calcular aquí.
     *
     * @param  array<string, mixed>  $compiled
     *
     * @throws InvalidArgumentException
     */
    public static function fromCompiled(array $compiled): self
    {
        self::keys($compiled, ['spec', 'specHash'], '$');
        $spec = self::fromArray(self::object($compiled['spec'], 'spec'));
        if (! is_string($compiled['specHash']) || ! hash_equals($spec->specHash, $compiled['specHash'])) {
            throw new InvalidArgumentException('specHash no cuadra con la especificación.');
        }

        return $spec;
    }

    /** @return array<string, mixed> la especificación tal cual (sin teléfonos ni secretos: se puede guardar) */
    public function toArray(): array
    {
        return $this->spec;
    }

    // ── Lectura tipada (lo que usan el mapper y la telefonía) ──────────────

    public function compilerVersion(): string
    {
        return $this->spec['compilerVersion'];
    }

    public function templateVersion(): string
    {
        return $this->spec['template']['version'];
    }

    public function templateHash(): string
    {
        return $this->spec['template']['hash'];
    }

    public function studioVersionUuid(): string
    {
        return $this->spec['source']['studioVersionUuid'];
    }

    public function studioContentHash(): string
    {
        return $this->spec['source']['studioContentHash'];
    }

    public function catalogVersion(): string
    {
        return $this->spec['source']['catalogVersion'];
    }

    public function voiceProfileUuid(): string
    {
        return $this->spec['source']['voiceProfileUuid'];
    }

    public function voiceProfileHash(): string
    {
        return $this->spec['source']['voiceProfileHash'];
    }

    public function language(): string
    {
        return $this->spec['language'];
    }

    public function voiceId(): string
    {
        return $this->spec['voiceId'];
    }

    public function firstMessage(): string
    {
        return $this->spec['firstMessage'];
    }

    public function systemPrompt(): string
    {
        return $this->spec['systemPrompt'];
    }

    /**
     * Los valores por defecto de las variables dinámicas: FALLO-CERRADO. Son
     * los que el proveedor usa si nadie le da otros (la iniciación de una
     * entrante falló o tardó): `caller_authorized = no`.
     *
     * @return array<string, string> en el orden de {@see VARIABLES}
     */
    public function dynamicVariableDefaults(): array
    {
        $out = [];
        foreach (self::VARIABLES as $nombre) {
            $out[$nombre] = $this->spec['dynamicVariables'][$nombre];
        }

        return $out;
    }

    public function maxDurationSeconds(): int
    {
        return $this->spec['maxDurationSeconds'];
    }

    /** @return list<array{name:string, description:string}> las herramientas del servidor, en su orden */
    public function tools(): array
    {
        return $this->spec['tools'];
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_column($this->spec['tools'], 'name');
    }

    /** @return list<array{name:string, description:string}> */
    public function systemTools(): array
    {
        return $this->spec['systemTools'];
    }

    /** @return list<string> */
    public function systemToolNames(): array
    {
        return array_column($this->spec['systemTools'], 'name');
    }

    public function transferMode(): string
    {
        return $this->spec['transfer']['mode'];
    }

    /** @return list<array{alias:string, ref:string, condition:string}> los destinos SIN teléfono: alias y huella */
    public function transferDestinations(): array
    {
        return $this->spec['transfer']['destinations'];
    }

    public function pricePolicy(): string
    {
        return $this->spec['prices'];
    }

    /** @return list<string> */
    public function forbiddenPhrases(): array
    {
        return $this->spec['forbiddenPhrases'];
    }

    public function retentionDays(): int
    {
        return $this->spec['privacy']['retentionDays'];
    }

    public function voicemailPolicy(): string
    {
        return $this->spec['voicemail'];
    }

    // ── Validación ──────────────────────────────────────────────────────────

    private static function match(mixed $valor, string $patron, string $campo): void
    {
        if (! is_string($valor) || preg_match($patron, $valor) !== 1) {
            throw new InvalidArgumentException("{$campo} no tiene la forma esperada.");
        }
    }

    /**
     * Texto de una línea: UTF-8 válido, sin espacios alrededor, sin saltos, sin
     * caracteres de control, de formato (invisibles, bidireccionales), privados
     * ni sin asignar, y sin `{{` ni `}}` (las variables solo van en el prompt).
     */
    private static function plainText(mixed $texto, string $campo, int $min, int $max): void
    {
        if (! is_string($texto) || preg_match('//u', $texto) !== 1) {
            throw new InvalidArgumentException("{$campo} tiene que ser texto UTF-8.");
        }
        $largo = mb_strlen($texto, 'UTF-8');
        if ($largo < $min || $largo > $max) {
            throw new InvalidArgumentException("{$campo} tiene que tener de {$min} a {$max} caracteres.");
        }
        if ($texto !== trim($texto) || preg_match('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}\p{Cn}\x{2028}\x{2029}]/u', $texto) === 1) {
            throw new InvalidArgumentException("{$campo} lleva espacios alrededor o caracteres de control o invisibles.");
        }
        if (str_contains($texto, '{{') || str_contains($texto, '}}')) {
            throw new InvalidArgumentException("{$campo} no admite variables.");
        }
    }

    /**
     * El prompt admite saltos de línea y tabuladores, y variables `{{nombre}}`
     * solo de {@see VARIABLES} o del sistema (`system__…`). Nunca `secret__…`
     * (esas solo van en cabeceras) ni llaves sueltas.
     */
    private static function promptText(mixed $prompt): void
    {
        if (! is_string($prompt) || preg_match('//u', $prompt) !== 1) {
            throw new InvalidArgumentException('systemPrompt tiene que ser texto UTF-8.');
        }
        $largo = mb_strlen($prompt, 'UTF-8');
        if ($largo < 1 || $largo > self::MAX_PROMPT || $prompt !== trim($prompt)) {
            throw new InvalidArgumentException('systemPrompt tiene que tener de 1 a '.self::MAX_PROMPT.' caracteres, sin espacios alrededor.');
        }
        $sinSaltos = str_replace(["\n", "\t"], '', $prompt);
        if (str_contains($prompt, "\r") || preg_match('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}\p{Cn}\x{2028}\x{2029}]/u', $sinSaltos) === 1) {
            throw new InvalidArgumentException('systemPrompt lleva caracteres de control o invisibles.');
        }

        $resto = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]{1,80})\s*\}\}/', static function (array $m): string {
            if (! in_array($m[1], self::VARIABLES, true) && preg_match('/^system__[a-z][a-z_]{0,63}$/D', $m[1]) !== 1) {
                throw new InvalidArgumentException('systemPrompt referencia una variable fuera del conjunto cerrado.');
            }

            return '';
        }, $prompt);
        if (! is_string($resto) || str_contains($resto, '{{') || str_contains($resto, '}}')) {
            throw new InvalidArgumentException('systemPrompt lleva llaves de variable mal formadas.');
        }
    }

    /** Exactamente {@see VARIABLES}, cada una con un texto corto; `caller_authorized` por defecto `no`. */
    private static function dynamicVariables(mixed $variables): void
    {
        $variables = self::object($variables, 'dynamicVariables');
        self::keys($variables, self::VARIABLES, 'dynamicVariables');
        foreach ($variables as $nombre => $valor) {
            self::plainText($valor, "dynamicVariables.{$nombre}", 1, self::ALIAS_MAX);
        }
        if ($variables[self::VAR_CALLER_AUTHORIZED] !== self::CALLER_NOT_AUTHORIZED) {
            throw new InvalidArgumentException('dynamicVariables.caller_authorized tiene que valer «no» por defecto (fallo-cerrado, D8-11).');
        }
        if (! in_array($variables[self::VAR_CALL_DIRECTION], self::DIRECTIONS, true)) {
            throw new InvalidArgumentException('dynamicVariables.call_direction fuera del conjunto cerrado.');
        }
        if (VoiceIds::isUuid($variables[self::VAR_CALL_REF])) {
            throw new InvalidArgumentException('dynamicVariables.call_ref no puede ser un uuid por defecto: ninguna herramienta lo tiene que aceptar.');
        }
    }

    /**
     * Una lista de `{name, description}` con los nombres del conjunto, sin repetir.
     *
     * @param  list<string>  $conjunto
     * @return list<string> los nombres
     */
    private static function namedList(mixed $lista, array $conjunto, string $campo): array
    {
        if (! is_array($lista) || ! array_is_list($lista)) {
            throw new InvalidArgumentException("{$campo} tiene que ser una lista.");
        }
        $nombres = [];
        foreach ($lista as $i => $item) {
            $item = self::object($item, "{$campo}[{$i}]");
            self::keys($item, self::TOOL_KEYS, "{$campo}[{$i}]");
            if (! is_string($item['name']) || ! in_array($item['name'], $conjunto, true)) {
                throw new InvalidArgumentException("{$campo}[{$i}].name fuera del conjunto cerrado.");
            }
            self::plainText($item['description'], "{$campo}[{$i}].description", 1, self::MAX_DESCRIPTION);
            $nombres[] = $item['name'];
        }
        if (count(array_unique($nombres)) !== count($nombres)) {
            throw new InvalidArgumentException("{$campo} lleva nombres repetidos.");
        }

        return $nombres;
    }

    /** @return int cuántos destinos hay */
    private static function transfer(mixed $transfer): int
    {
        $transfer = self::object($transfer, 'transfer');
        self::keys($transfer, self::TRANSFER_KEYS, 'transfer');
        if (! in_array($transfer['mode'], self::TRANSFER_MODES, true)) {
            throw new InvalidArgumentException('transfer.mode fuera del conjunto cerrado (solo conferencia, D8-14).');
        }
        $destinos = $transfer['destinations'];
        if (! is_array($destinos) || ! array_is_list($destinos) || count($destinos) > self::MAX_TRANSFER_DESTINATIONS) {
            throw new InvalidArgumentException('transfer.destinations tiene que ser una lista de como mucho '.self::MAX_TRANSFER_DESTINATIONS.' destinos.');
        }
        $alias = [];
        $refs = [];
        foreach ($destinos as $i => $d) {
            $d = self::object($d, "transfer.destinations[{$i}]");
            self::keys($d, self::DESTINATION_KEYS, "transfer.destinations[{$i}]");
            self::plainText($d['alias'], "transfer.destinations[{$i}].alias", 1, self::ALIAS_MAX);
            self::match($d['alias'], self::ALIAS_PATTERN, "transfer.destinations[{$i}].alias");
            self::match($d['ref'], self::HASH_PATTERN, "transfer.destinations[{$i}].ref");
            self::plainText($d['condition'], "transfer.destinations[{$i}].condition", 1, self::MAX_SHORT_TEXT);
            $alias[] = mb_strtolower($d['alias'], 'UTF-8');
            $refs[] = $d['ref'];
        }
        if (count(array_unique($alias)) !== count($alias) || count(array_unique($refs)) !== count($refs)) {
            throw new InvalidArgumentException('transfer.destinations lleva un alias o un destino repetido.');
        }

        return count($destinos);
    }

    /**
     * @param  array<mixed>  $objeto
     * @param  list<string>  $esperadas  en orden de bytes
     */
    private static function keys(array $objeto, array $esperadas, string $ruta): void
    {
        $claves = array_map('strval', array_keys($objeto));
        sort($claves, SORT_STRING);
        if ($claves !== $esperadas) {
            throw new InvalidArgumentException("{$ruta} no tiene exactamente las claves esperadas.");
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $valor, string $campo): array
    {
        if (! is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            throw new InvalidArgumentException("{$campo} tiene que ser un objeto.");
        }

        return $valor;
    }
}
