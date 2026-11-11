<?php

namespace App\Services\UltronStudio;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use InvalidArgumentException;
use Throwable;

/**
 * EL PAQUETE DE PROMPTS DE UNA VERSIÓN (Enmienda M-1 del contrato H5; plan de
 * motores §B.3, §B.4 y §D): el único fichero del Studio que nombra el
 * compilador de prompts del motor. Lo vigila `UltronStudioEngineReadOnlyTest`.
 *
 * PURO, como las dos clases que nombra (M1-6: «el Studio importa del motor solo
 * `UltronPromptBase` y `UltronPromptCompiler`, puros»): sin base de datos, sin
 * configuración, sin reloj y sin log. Lo que el Studio necesita del compilador
 * para publicar en `ultron-engine-config/2`:
 *
 *  - {@see compile()}: los cuatro valores gobernados de una versión → lo que se
 *    firma en su parte (`governed` y `prompts = {base, bundleHash, compiler}`)
 *    y lo que enseña la vista previa (la tabla de cambios con el texto exacto
 *    de cada parche, por parámetro y rol). Nunca devuelve los textos
 *    compilados: el Studio enseña los parches, no los prompts enteros.
 *  - {@see offer()}: la oferta que haría `decide` ahora a la conversación
 *    canaria, calculada sobre la cabeza que ya verificó el Studio. Es la MISMA
 *    regla que `UltronPromptOffer` (§B.5, pasos 1 a 8), con sus motivos; una
 *    prueba compara las dos sobre la misma cadena.
 *
 * Un fallo del compilador sale como InvalidArgumentException con el motivo
 * cerrado del compilador en el mensaje (`invalid_governed`, `base_corrupt`…),
 * nunca con texto de los prompts.
 *
 * COMPILADOR 2 (reglas S-NNN en WhatsApp, opción A de la auditoría de cierre
 * §3.2): lo gobernado sale del CONTENIDO entero de la versión: los cuatro
 * valores de su configuración y las frases prohibidas de sus reglas S-NNN
 * ({@see StudioRulePhrases}, la semántica de la voz). {@see phrases()} dice,
 * frase a frase y regla a regla, lo que no cabe en el juego cerrado del
 * compilador: la vista previa lo enseña y publicar se niega
 * ({@see CODE_PHRASES_INVALID}); nada se trunca ni se descarta.
 */
final class StudioPromptBundle
{
    /** El código del 409 al publicar un paquete que no compila (§B.4). */
    public const CODE_COMPILE_FAILED = 'prompts_compile_failed';

    /**
     * Compilador 2: el código de la vista previa (`blocking` y
     * `prompts.error`) y del 409 al publicar cuando las frases prohibidas de
     * las reglas no caben en los prompts de WhatsApp: más de 12, o alguna
     * fuera de 3–60 caracteres, sin ninguna letra ni cifra o fuera del juego
     * cerrado. El detalle va en `prompts.phrases.problems` si son de la
     * versión que se publica, o en `prompts.carried.problems` si son de la que
     * se arrastra del resto (y en el `problems` del 409, con su `part`).
     */
    public const CODE_PHRASES_INVALID = 'prompts_rule_phrases_invalid';

    /**
     * El `part` del 409 {@see CODE_PHRASES_INVALID}: de quién son las frases
     * que no caben. `version`, la versión que se publica; `carried`, la que hoy
     * rige el resto y cuya parte `/1` esta publicación arrastraría a `/2`.
     */
    public const PHRASES_PART_VERSION = 'version';

    public const PHRASES_PART_CARRIED = 'carried';

    /** Los modos de la oferta (`UltronPromptOffer::MODE_*`; una prueba los ata). */
    public const MODE_STUDIO = 'studio';

    public const MODE_LEGACY = 'legacy';

    /** Los motivos Legacy de la oferta (`UltronPromptOffer`, §B.5; una prueba los ata). */
    public const PROMPTS_OFF = 'prompts_off';

    public const STUDIO_CONFIG_OFF = 'studio_config_off';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const NO_PUBLICATION = 'no_publication';

    public const PART_LEGACY = 'part_legacy';

    public const DECLARATION_V1 = 'declaration_v1';

    public const PUBLICATION_PREFIX = 'publication_';

    public const BASE_CHANGED = 'base_changed';

    public const COMPILER_CHANGED = 'compiler_changed';

    public const BUNDLE_MISMATCH = 'bundle_mismatch';

    public const TOO_LARGE = 'too_large';

    public const ERROR = 'error';

    /** La base del repositorio con la que compila este código (`prompts.base` en `/2`). */
    public static function base(): string
    {
        return UltronPromptBase::ID;
    }

    /** La versión del compilador (`prompts.compiler` en `/2`). */
    public static function compilerVersion(): int
    {
        return UltronPromptCompiler::VERSION;
    }

    /** La huella del paquete Legacy: la base sin ningún parche. */
    public static function legacyBundleHash(): string
    {
        return UltronPromptBase::LEGACY_BUNDLE_HASH;
    }

    /**
     * Los cuatro parámetros DEL CATÁLOGO que gobiernan los prompts, en el
     * orden en que el compilador aplica sus parches (los que pueden salir con
     * la clase `prompt` en `params`). Las frases de las reglas no son un
     * parámetro del catálogo: {@see phrasesParameter()}.
     *
     * @return list<string>
     */
    public static function parameters(): array
    {
        return array_values(array_filter(UltronPromptCompiler::PARAMETERS, static fn (string $id): bool => $id !== UltronPromptCompiler::PHRASES));
    }

    /** Compilador 2: la clave de lo gobernado (y el `param` de su cambio) con las frases de las reglas. */
    public static function phrasesParameter(): string
    {
        return UltronPromptCompiler::PHRASES;
    }

    /** Compilador 2: cuántas frases caben como mucho en los prompts. */
    public static function maxPhrases(): int
    {
        return UltronPromptCompiler::MAX_PHRASES;
    }

    /**
     * Los cinco roles, en su orden canónico.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        return UltronPromptBase::ROLES;
    }

    /**
     * Lo gobernado de una versión, por id: los cuatro valores de su
     * configuración (completa: el normalizador rellena con la línea base) y,
     * en {@see phrasesParameter()}, las frases prohibidas de sus reglas S-NNN
     * ({@see StudioRulePhrases}: canónicas, sin repetir y por orden de bytes).
     * Las frases no se validan aquí: las valida el compilador, y
     * {@see phrases()} dice cuál no cabe.
     *
     * @param  array<string,mixed>  $content  el `content` de la versión
     * @param  list<string>|null  $phrases  las frases ya calculadas ({@see phrases()}`['list']`), o null para calcularlas
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException si falta alguno de los cuatro
     */
    public static function governedFrom(array $content, ?array $phrases = null): array
    {
        $configs = is_array($content['configs'] ?? null) ? $content['configs'] : [];
        $out = [];
        foreach (array_keys(D::GOVERNED) as $id) {
            [$capa, $clave] = explode('.', $id, 2);
            if (! is_array($configs[$capa] ?? null) || ! array_key_exists($clave, $configs[$capa])) {
                throw new InvalidArgumentException("Falta configs.{$id}: el contenido llega normalizado y completo.");
            }
            $out[$id] = $configs[$capa][$clave];
        }
        $out[UltronPromptCompiler::PHRASES] = $phrases ?? StudioRulePhrases::sorted(StudioRulePhrases::byRule($content));

        return $out;
    }

    /**
     * Las frases de las reglas S-NNN que entrarían en los prompts de WhatsApp
     * y lo que no cabe, para la vista previa:
     *
     *  - `list`: las frases (canónicas, sin repetir, por orden de bytes), las
     *    que irían en lo gobernado;
     *  - `rules`: los códigos de las reglas que las aportan, por orden de bytes
     *    (los de la clase `prompt`);
     *  - `max`: el tope ({@see maxPhrases()});
     *  - `problems`: `{rule, reason, phrase}` por cada frase que no cabe, en
     *    el orden de las reglas y de sus frases (`reason`: `length`, `charset`,
     *    `no_text`, `spacing` o `anchor`; `rule` es su código y `phrase`, la frase
     *    canónica, que es texto de la propia versión), y `{rule: null, reason:
     *    too_many, phrase: null}` si son más de `max`. Vacía = compilan.
     *
     * @param  array<string,mixed>  $content
     * @return array{list:list<string>,rules:list<string>,max:int,problems:list<array{rule:?string,reason:string,phrase:?string}>}
     */
    public static function phrases(array $content): array
    {
        $porRegla = StudioRulePhrases::byRule($content);
        $lista = StudioRulePhrases::sorted($porRegla);

        $problemas = [];
        $vistos = [];
        foreach ($porRegla as $regla) {
            foreach ($regla['phrases'] as $frase) {
                $motivo = UltronPromptCompiler::phraseViolation($frase);
                $clave = $regla['code']."\n".$frase;
                if ($motivo !== null && ! isset($vistos[$clave])) {
                    $vistos[$clave] = true;
                    $problemas[] = ['rule' => $regla['code'], 'reason' => $motivo, 'phrase' => $frase];
                }
            }
        }
        if (count($lista) > UltronPromptCompiler::MAX_PHRASES) {
            $problemas[] = ['rule' => null, 'reason' => UltronPromptCompiler::PHRASES_TOO_MANY, 'phrase' => null];
        }

        return [
            'list' => $lista,
            'rules' => StudioRulePhrases::codes($porRegla),
            'max' => UltronPromptCompiler::MAX_PHRASES,
            'problems' => $problemas,
        ];
    }

    /**
     * El paquete de una versión (§B.3): lo que se firma en su parte `/2` y lo
     * que enseña la vista previa.
     *
     *  - `governed`: los valores validados (los cuatro y las frases), por
     *    orden de bytes.
     *  - `prompts`: `{base, bundleHash, compiler}`.
     *  - `bytes`: el tamaño del paquete compilado (UTF-8).
     *  - `baseline`: true si es exactamente la línea base (paquete = Legacy).
     *  - `changes`: por parámetro que se aparta de la línea base, en el orden de
     *    aplicación: `{param, value, roles, patches: [{role, operation, anchor,
     *    text}]}`, con los roles en su orden canónico y los parches en el de
     *    aplicación. Las frases, si las hay, son el último, con `param` =
     *    {@see phrasesParameter()} y `value` = la lista.
     *
     * @param  array<string,mixed>  $governed
     * @return array{governed:array<string,bool|int|string|list<string>>,prompts:array{base:string,bundleHash:string,compiler:int},bytes:int,baseline:bool,changes:list<array{param:string,value:bool|int|string|list<string>,roles:list<string>,patches:list<array{role:string,operation:string,anchor:string,text:string}>}>}
     *
     * @throws InvalidArgumentException si no compila (el motivo del compilador va en el mensaje)
     */
    public static function compile(array $governed): array
    {
        try {
            $c = UltronPromptCompiler::compile($governed);
        } catch (UltronPromptException $e) {
            throw new InvalidArgumentException("El paquete de prompts no compila ({$e->reason}).", 0, $e);
        }

        return [
            'governed' => $c['governed'],
            'prompts' => ['base' => $c['base'], 'bundleHash' => $c['bundleHash'], 'compiler' => $c['compiler']],
            'bytes' => $c['bytes'],
            'baseline' => $c['patches'] === [] && hash_equals(UltronPromptBase::LEGACY_BUNDLE_HASH, $c['bundleHash']),
            'changes' => self::changes($c['patches']),
        ];
    }

    /**
     * El motivo cerrado del compilador detrás de una excepción de
     * {@see compile()}, o `error` si no lo hay. Para el log: nunca un texto.
     */
    public static function failureReason(Throwable $e): string
    {
        $origen = $e->getPrevious();

        return $origen instanceof UltronPromptException ? $origen->reason : self::ERROR;
    }

    /**
     * La oferta que haría `decide` ahora a la conversación canaria (§B.5),
     * sobre la cabeza que verificó el Studio. Puro: las llaves y la cabeza
     * llegan como argumentos.
     *
     * `$head['state']`: `none` (sin entradas), `invalid` (no verifica; `reason`
     * es el del lector: contract_unknown, hash_mismatch, signature_invalid o
     * malformed) u `ok` (`part` es la que rige en la canaria: la canaria o, si
     * es null, la del resto; `contract`, el de la entrada).
     *
     * @param  array{state:string,reason:?string,contract:?string,part:?array<string,mixed>}  $head
     * @return array{mode:string,version:?string,bundleHash:?string,reason:?string}
     */
    public static function offer(string $promptsKey, string $engineScope, bool $canaryConfigured, array $head): array
    {
        $legacy = static fn (string $motivo): array => ['mode' => self::MODE_LEGACY, 'version' => null, 'bundleHash' => null, 'reason' => $motivo];

        // 1-3.
        if ($promptsKey !== StudioEngineReadings::PROMPTS_CANARY) {
            return $legacy(self::PROMPTS_OFF);
        }
        if ($engineScope === StudioPublishing::SCOPE_OFF) {
            return $legacy(self::STUDIO_CONFIG_OFF);
        }
        if (! $canaryConfigured) {
            return $legacy(self::OUT_OF_SCOPE);
        }

        // 4: la cabeza y la parte de la canaria.
        if ($head['state'] === 'none') {
            return $legacy(self::NO_PUBLICATION);
        }
        if ($head['state'] !== 'ok') {
            return $legacy(self::PUBLICATION_PREFIX.(is_string($head['reason']) ? $head['reason'] : 'read_failed'));
        }
        $parte = $head['part'];
        if ($parte === null) {
            return $legacy(self::PART_LEGACY);
        }
        if ($head['contract'] !== D::CONTRACT_V2 || ! is_array($parte['governed'] ?? null) || ! is_array($parte['prompts'] ?? null)) {
            return $legacy(self::DECLARATION_V1);
        }

        // 5.
        if (($parte['prompts']['base'] ?? null) !== self::base()) {
            return $legacy(self::BASE_CHANGED);
        }
        if (($parte['prompts']['compiler'] ?? null) !== self::compilerVersion()) {
            return $legacy(self::COMPILER_CHANGED);
        }

        // 6.
        try {
            $paquete = UltronPromptCompiler::compile($parte['governed']);
        } catch (UltronPromptException $e) {
            return $legacy($e->reason === UltronPromptException::TOO_LARGE ? self::TOO_LARGE : self::ERROR);
        }

        // 7.
        $firmada = $parte['prompts']['bundleHash'] ?? null;
        if (! is_string($firmada) || ! hash_equals($paquete['bundleHash'], $firmada)) {
            return $legacy(self::BUNDLE_MISMATCH);
        }

        // 8.
        return ['mode' => self::MODE_STUDIO, 'version' => is_string($parte['version'] ?? null) ? $parte['version'] : null, 'bundleHash' => $paquete['bundleHash'], 'reason' => null];
    }

    /**
     * Los parches agrupados por parámetro, en el orden de aplicación.
     *
     * @param  list<array{parameter:string,value:mixed,role:string,operation:string,anchor:string,text:string}>  $parches
     * @return list<array{param:string,value:bool|int|string|list<string>,roles:list<string>,patches:list<array{role:string,operation:string,anchor:string,text:string}>}>
     */
    private static function changes(array $parches): array
    {
        $porParametro = [];
        foreach ($parches as $p) {
            $id = $p['parameter'];
            $porParametro[$id] ??= ['param' => $id, 'value' => $p['value'], 'roles' => [], 'patches' => []];
            $porParametro[$id]['roles'][] = $p['role'];
            $porParametro[$id]['patches'][] = ['role' => $p['role'], 'operation' => $p['operation'], 'anchor' => $p['anchor'], 'text' => $p['text']];
        }

        $out = [];
        foreach (UltronPromptCompiler::PARAMETERS as $id) {
            if (! isset($porParametro[$id])) {
                continue;
            }
            $cambio = $porParametro[$id];
            $cambio['roles'] = array_values(array_intersect(UltronPromptBase::ROLES, $cambio['roles']));
            $out[] = $cambio;
        }

        return $out;
    }
}
