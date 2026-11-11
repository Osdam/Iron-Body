<?php

namespace App\Services\UltronStudio;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * LA DECLARACIÓN QUE SE PUBLICA (contrato H5 §A1, §A4.2, §A5.1 pasos 8 y 9,
 * §A5.2–A5.4 y Enmienda 1): funciones PURAS. Sin base de datos, sin HTTP, sin
 * configuración y sin reloj: el catálogo, las lecturas del motor, el instante
 * y la firma llegan como argumentos.
 *
 * `tests/fixtures/ultron-studio/publication-cases.json` (lo escribió el tester a
 * mano desde el contrato; su README fija C-1…C-10, E-1…E-5 y P-1…P-5, que la
 * Enmienda 1 congela) manda caso a caso sobre {@see classify()},
 * {@see effectiveJson()}, {@see compose()} y los pasos 8 y 9 de publicar
 * ({@see refusal()}). Si discrepan, se corrige este fichero, nunca el fixture.
 *
 * CLASIFICACIÓN (§A1). Cada parámetro del catálogo, en su orden (las capas de
 * `layers` y, dentro de cada una, sus parámetros: C-2), según su `origin`:
 *
 *   env_flag     must_match: el valor de la versión tiene que ser el que lee hoy
 *                el motor. Su `engineOption` (`entorno` en el driver) coincide
 *                con cualquier lectura DISPONIBLE; la lectura cruda `fake` se
 *                compara como `rules`. Una lectura caída no coincide nunca, ni
 *                con `entorno`: falla cerrado (C-5, C-6). Nunca pasa a otra
 *                lista, aunque difiera de la línea base (C-4)
 *   fixed con `declaredValue`   matches si vale eso; si no, no_effect (C-10)
 *   fixed sin valor, proposal y engine_data   no_effect solo si difieren de la
 *                línea base, con igualdad estricta del valor JSON (en una lista
 *                cuenta el orden, C-7); `engine_data` nunca se compara con el
 *                motor (C-8)
 *
 * y, al final, cada regla S-NNN copiada en la versión (`rule:S-NNN`, por código
 * en orden de bytes, sea cual sea su estado); las R-xx no tienen clase (C-3,
 * C-9).
 *
 * EL TEXTO FIRMADO (§A4.2). {@see effectiveJson()} da los bytes exactos que lee
 * el motor: `StudioContentHasher::canonicalJson` de `{canary, contract, entry,
 * issuedAt, rest, seq}`, con `issuedAt` en UTC y al segundo, truncado (E-1 a
 * E-3). Al escribir exige lo que el lector exige al leer (claves y tipos
 * exactos, fixture R-5) y, además, los valores: uuid en minúscula, huella de 64
 * hexadecimales en minúscula, ids enteros positivos y textos cortos de ASCII
 * visible. Lo que sale de aquí el motor no puede leerlo como `malformed`.
 *
 * LA FIRMA NO VIVE AQUÍ. Hay una sola implementación, la del motor
 * (`UltronPublishedConfig::signatureFor()`, §A4.1 y Enmienda 1, Q-1), y los
 * servicios del Studio no pueden nombrar clases del motor salvo los adaptadores
 * que enumera `UltronStudioEngineReadOnlyTest`. {@see declaration()} recibe la
 * firma como `Closure(string): string`; quien publica pasa
 * `UltronPublishedConfig::signatureFor(...)`.
 *
 * Una forma inesperada es un error del llamador (el contenido llega normalizado
 * y con su integridad comprobada): InvalidArgumentException, nunca un valor por
 * defecto que parezca bueno.
 *
 * ENMIENDA M-1 (plan de motores §B.4): `ultron-engine-config/2`, aditivo. La
 * misma entrada que `/1` con `contract` = `/2` y, en cada parte no nula, dos
 * claves más: `governed` (los valores que gobiernan los prompts del motor:
 * cuatro con el compilador 1 y, desde el 2, además las frases prohibidas de
 * las reglas S-NNN) y `prompts` (`{base, bundleHash, compiler}`, la huella del
 * paquete compilado). La firma, la huella, la decodificación estricta y el tope son los
 * de `/1`. Volver a Legacy (`{canary: null, rest: null}`) se escribe SIEMPRE en
 * `/1` (R10). El paquete lo compila {@see StudioPromptBundle}; aquí solo se
 * comprueban las formas y los tipos, como el lector del motor. Con la llave de
 * prompts del motor en `off`, nada de esto se usa: la clasificación, los bytes
 * y la huella de la vista previa son los de siempre (el fixture congelado
 * `publication-cases.json` sigue valiendo entero).
 */
final class StudioPublicationDeclaration
{
    /** El contrato del motor (`UltronPublishedConfig::CONTRACT`; una prueba los ata). */
    public const CONTRACT = 'ultron-engine-config/1';

    /** Enmienda M-1: `UltronPublishedConfig::CONTRACT_V2` (una prueba los ata). */
    public const CONTRACT_V2 = 'ultron-engine-config/2';

    /** Los contratos que escribe y lee el Studio. */
    public const CONTRACTS = [self::CONTRACT, self::CONTRACT_V2];

    /**
     * Enmienda M-1 (§B.4): los cuatro valores que gobiernan los prompts del
     * motor, con el tipo de su valor en `governed`, por orden de bytes (el de la
     * parte firmada). Son los `PARAMETERS` del compilador (una prueba los ata).
     */
    public const GOVERNED = [
        'persona.emojis' => 'boolean',
        'persona.maxChars' => 'integer',
        'persona.tone' => 'string',
        'strategy.goal' => 'string',
    ];

    /**
     * Compilador de prompts 2 (reglas S-NNN en WhatsApp): la quinta clave de
     * `governed`, una lista de textos con las frases prohibidas de las reglas.
     * No es un parámetro del catálogo. Una parte firmada con el compilador 1
     * lleva las cuatro de {@see GOVERNED}; desde el 2, además esta (la regla
     * del lector del motor; una prueba las ata).
     */
    public const GOVERNED_PHRASES = 'rules.forbiddenPhrases';

    public const PHRASES_SINCE_COMPILER = 2;

    /** Enmienda M-1 (§B.4): las claves de `prompts` en una parte `/2` y su tipo. */
    public const PROMPTS = [
        'base' => 'string',
        'bundleHash' => 'string',
        'compiler' => 'integer',
    ];

    /**
     * Enmienda M-1 (§B.4): la clase de los gobernados que se apartan de la
     * línea base cuando la llave de prompts del motor está encendida. No exige
     * reconocimiento: la vista previa enseña su cambio exacto.
     */
    public const CLASS_PROMPT = 'prompt';

    /**
     * Lo más largo que lee el motor, en caracteres
     * (`UltronPublishedConfig::MAX_EFFECTIVE_JSON`; una prueba los ata). Con los
     * topes de cada campo, la entrada más larga posible queda muy por debajo
     * (otra prueba lo mide): el control solo protege de un cambio futuro.
     */
    public const MAX_EFFECTIVE_JSON = 16384;

    /**
     * Los cuatro `must_match` (§A1) con el tipo de su valor en `declared`
     * (§A4.2), en el orden del catálogo (C-2). Son exactamente las claves de
     * `declared`: un catálogo cuyos `env_flag` sean otros no casa con el
     * contrato del motor y no se clasifica.
     */
    public const MUST_MATCH = [
        'intent.driver' => 'string',
        'rules.paymentLinksEnabled' => 'boolean',
        'governance.enabled' => 'boolean',
        'governance.canaryOnly' => 'boolean',
    ];

    /** Las acciones de la cadena (columna `action` de `ultron_studio_publications`). */
    public const ACTION_PUBLISH = 'publish';

    public const ACTION_PROMOTE = 'promote';

    public const ACTION_ROLLBACK = 'rollback';

    public const ACTION_LEGACY = 'legacy';

    /** Cómo se nombra una regla en `noEffect` (C-1 y `PublicationPreviewDto`). */
    public const RULE_PREFIX = 'rule:';

    /** Los modos de la puerta del laboratorio (§A13, `publish_lab_gate`). */
    public const LAB_REQUIRED = 'required';

    public const LAB_WAIVED = 'waived';

    /**
     * Los `latest` de la puerta del laboratorio (§A5.1, paso 7): sin veredicto,
     * las tres decisiones de `StudioLabVerdict` (una prueba las ata) y el
     * veredicto sobre prompts viejos.
     */
    public const LAB_LATEST = [null, 'approved', 'rejected', 'needs_changes', 'stale_prompts'];

    /** Los códigos de los pasos 8 y 9 de publicar (§A5.1), en su orden. */
    public const CODE_ENGINE_VALUE_MISMATCH = 'engine_value_mismatch';

    public const CODE_ENGINE_READING_UNAVAILABLE = 'engine_reading_unavailable';

    public const CODE_NO_EFFECT_NOT_ACKNOWLEDGED = 'no_effect_not_acknowledged';

    public const CODE_PREVIEW_STALE = 'preview_stale';

    /** Los `origin` de `StudioCatalog::bindings()`. */
    private const ORIGINS = ['env_flag', 'fixed', 'proposal', 'engine_data'];

    /** Lecturas crudas que se comparan traducidas (C-5; `StudioEngineReadings` ya lo hace y repetirlo no cambia nada). */
    private const ENGINE_ALIASES = ['intent.driver' => ['fake' => 'rules']];

    /** Las claves exactas de una entrada y de una parte (§A4.2; fixture R-5). */
    private const ENTRY_KEYS = ['canary', 'contract', 'entry', 'issuedAt', 'rest', 'seq'];

    private const PART_KEYS = ['approvedBy', 'catalogVersion', 'contentHash', 'declared', 'publishedBy', 'version'];

    /** En `/2`, las de `/1` más los gobernados y la huella del paquete (§B.4). */
    private const PART_KEYS_V2 = [...self::PART_KEYS, 'governed', 'prompts'];

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private const HEX64 = '/\A[0-9a-f]{64}\z/';

    /** Un texto corto de la parte (`catalogVersion`, el driver): de 1 a 32 caracteres ASCII visibles (`catalog_version` es varchar(32)). */
    private const SHORT_TEXT = '/\A[\x21-\x7E]{1,32}\z/';

    /** La de `UltronPublishedConfig`: una entrada tiene profundidad 4. */
    private const JSON_DEPTH = 8;

    // ── Clasificación (§A1) ─────────────────────────────────────────────────

    /**
     * La clasificación de una versión al publicar: las cinco listas del
     * fixture (C-1), cada una en el orden del catálogo.
     *
     *  - `mustMatch`: siempre los cuatro, `{param, versionValue, engineValue, ok}`;
     *    `engineValue` sale traducido (`fake` → `rules`) o null si la lectura cayó.
     *  - `matches` y `noEffect`: ids `capa.clave`; las reglas, `rule:S-NNN`, al final.
     *  - `mismatches`: la lista del 409 `engine_value_mismatch`, `{param, versionValue, engineValue}`.
     *  - `engineReadingUnavailable`: los `must_match` sin lectura (nunca en `mismatches`).
     *
     * Enmienda M-1 (§B.4): con `$promptsGoverned` (la llave de prompts del motor
     * encendida), los cuatro {@see GOVERNED} que se apartan de la línea base van
     * a una sexta lista, `prompt`, en vez de a `noEffect` (no se reconocen: la
     * vista previa enseña su cambio exacto); `intent.fallback` sigue en
     * `noEffect`. Compilador de prompts 2: con la llave, las reglas S-NNN de
     * `$promptRules` (las que aportan frases prohibidas a los prompts,
     * `StudioPromptBundle::phrases()['rules']`) van también a `prompt`, como
     * `rule:S-NNN` y al final; las demás S-NNN siguen en `noEffect`. Sin la
     * llave, las cinco listas de siempre, sin la sexta clave (C-1), y
     * `$promptRules` no cuenta.
     *
     * @param  array<string,array<string,mixed>>  $configs  el `configs` de la versión, completo (el normalizador de F rellena con la línea base)
     * @param  list<array<string,mixed>>  $rules  las reglas copiadas en la versión; solo cuenta `code`
     * @param  array<string,array<string,mixed>>  $baseline  `StudioCatalog::baseline()['configs']`
     * @param  array<string,mixed>  $readings  `StudioEngineReadings::read()['params']`: id → `{available:true, value}` o `{available:false}`
     * @param  array<string,mixed>  $catalog  la forma de {@see StudioCatalog::toArray()}; se leen `layers` y `bindings`
     * @param  list<string>  $promptRules  códigos S-NNN de la versión cuyas frases entran en los prompts
     * @return array{mustMatch:list<array{param:string,versionValue:mixed,engineValue:mixed,ok:bool}>,matches:list<string>,noEffect:list<string>,mismatches:list<array{param:string,versionValue:mixed,engineValue:mixed}>,engineReadingUnavailable:list<string>,prompt?:list<string>}
     *
     * @throws InvalidArgumentException si el catálogo no casa con el contrato del motor, el contenido no tiene su forma o `$promptRules` nombra una regla que la versión no tiene
     */
    public static function classify(array $configs, array $rules, array $baseline, array $readings, array $catalog, bool $promptsGoverned = false, array $promptRules = []): array
    {
        $parametros = self::parameters($catalog);
        self::assertKnownConfigs($configs, array_column($catalog['layers'], 'id'), $parametros);
        $codigos = self::studioRuleCodes($rules);
        foreach ($promptRules as $codigo) {
            if (! is_string($codigo) || ! in_array($codigo, $codigos, true)) {
                throw new InvalidArgumentException('promptRules solo nombra reglas S-NNN de la versión.');
            }
        }

        $mustMatch = [];
        $matches = [];
        $noEffect = [];
        $mismatches = [];
        $caidas = [];
        $prompt = [];

        foreach ($parametros as $id => $p) {
            $valor = self::valueAt($configs, $p['layer'], $p['key'], 'configs');
            $mando = $p['binding'];

            if ($mando['origin'] === 'env_flag') {
                $c = self::against($id, $valor, $mando, $readings);
                $mustMatch[] = ['param' => $id, 'versionValue' => $valor, 'engineValue' => $c['engine'], 'ok' => $c['ok']];
                if (! $c['available']) {
                    $caidas[] = $id;
                } elseif (! $c['ok']) {
                    $mismatches[] = ['param' => $id, 'versionValue' => $valor, 'engineValue' => $c['engine']];
                }

                continue;
            }

            if ($mando['origin'] === 'fixed' && array_key_exists('declaredValue', $mando)) {
                if (self::same($valor, $mando['declaredValue'])) {
                    $matches[] = $id;
                } else {
                    $noEffect[] = $id;
                }

                continue;
            }

            // `fixed` sin valor, `proposal` y `engine_data`: solo lo que se aparta de la línea base.
            if (! self::same($valor, self::valueAt($baseline, $p['layer'], $p['key'], 'baseline'))) {
                if ($promptsGoverned && isset(self::GOVERNED[$id])) {
                    $prompt[] = $id;
                } else {
                    $noEffect[] = $id;
                }
            }
        }

        foreach ($codigos as $codigo) {
            if ($promptsGoverned && in_array($codigo, $promptRules, true)) {
                $prompt[] = self::RULE_PREFIX.$codigo;
            } else {
                $noEffect[] = self::RULE_PREFIX.$codigo;
            }
        }

        $clasificacion = [
            'mustMatch' => $mustMatch,
            'matches' => $matches,
            'noEffect' => $noEffect,
            'mismatches' => $mismatches,
            'engineReadingUnavailable' => $caidas,
        ];

        return $promptsGoverned ? $clasificacion + ['prompt' => $prompt] : $clasificacion;
    }

    /**
     * Lo que quien publica tiene que reconocer (§A1): el `noEffect` de la
     * clasificación, en su orden (C-3). Es el payload del 409
     * `no_effect_not_acknowledged`.
     *
     * @param  array<string,mixed>  $classification  la salida de {@see classify()}
     * @return list<string>
     */
    public static function requiredAcknowledgements(array $classification): array
    {
        $lista = self::listOf($classification, 'noEffect');
        foreach ($lista as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('noEffect solo lleva ids de texto.');
            }
        }

        return $lista;
    }

    /**
     * ¿Reconoce quien publica EXACTAMENTE lo que no tiene efecto (§A1)? Es un
     * conjunto, como `acknowledgedWarnings` al aprobar: el orden no cuenta
     * (fixture P-4) y los repetidos ya son un 422 antes de llegar aquí. Un
     * elemento que no es texto no reconoce nada.
     *
     * @param  array<string,mixed>  $classification
     * @param  list<mixed>  $acknowledgedNoEffect
     */
    public static function acknowledges(array $classification, array $acknowledgedNoEffect): bool
    {
        $esperado = self::requiredAcknowledgements($classification);
        foreach ($acknowledgedNoEffect as $id) {
            if (! is_string($id)) {
                return false;
            }
        }

        return self::asSet($acknowledgedNoEffect) === self::asSet($esperado);
    }

    /**
     * Los pasos 8 y 9 de publicar (§A5.1), en el orden del texto (fixture P-1):
     * `engine_value_mismatch` → `engine_reading_unavailable` →
     * `no_effect_not_acknowledged` → `preview_stale`. Devuelve el primero que
     * rechaza con su payload (P-3), o null si los dos pasos pasan. El de
     * `preview_stale` es null: lo pone quien llama (la vista previa nueva).
     *
     * @param  array<string,mixed>  $classification  basta con `mismatches`, `engineReadingUnavailable` y `noEffect`
     * @param  list<mixed>  $acknowledgedNoEffect
     * @return array{code:string,payload:array<string,list<mixed>>|null}|null
     */
    public static function refusal(array $classification, array $acknowledgedNoEffect, string $sentPreviewHash, string $currentPreviewHash): ?array
    {
        $mismatches = self::listOf($classification, 'mismatches');
        if ($mismatches !== []) {
            return ['code' => self::CODE_ENGINE_VALUE_MISMATCH, 'payload' => ['mismatches' => $mismatches]];
        }

        $caidas = self::listOf($classification, 'engineReadingUnavailable');
        if ($caidas !== []) {
            return ['code' => self::CODE_ENGINE_READING_UNAVAILABLE, 'payload' => ['engineReadingUnavailable' => $caidas]];
        }

        if (! self::acknowledges($classification, $acknowledgedNoEffect)) {
            return ['code' => self::CODE_NO_EFFECT_NOT_ACKNOWLEDGED, 'payload' => ['noEffect' => self::requiredAcknowledgements($classification)]];
        }

        if (! hash_equals($currentPreviewHash, $sentPreviewHash)) {
            return ['code' => self::CODE_PREVIEW_STALE, 'payload' => null];
        }

        return null;
    }

    /**
     * La huella de la vista previa (Enmienda 1, Q-4): `StudioContentHasher::hash`
     * de `{versionUuid, contentHash, catalogVersion, mustMatch, matches, noEffect,
     * labGate: {mode, latest}}`. Sin la ventana ni la hora, que tienen su propia
     * puerta, y sin `mismatches` ni `engineReadingUnavailable`, que se deducen de
     * `mustMatch`. Cualquier cambio en lo que enseña la vista previa (una lectura
     * del motor, un parámetro, una regla, el laboratorio) cambia la huella.
     *
     * `catalogVersion` es el de la versión (su `catalog_version`, el mismo que
     * lleva su parte), no el del catálogo con el que se clasifica: ese ya está
     * dentro, en las listas.
     *
     * Enmienda M-1 (§B.4 y E11): con la llave de prompts encendida, `$prompts`
     * es lo que se firmaría (`{base, bundleHash, compiler}` de la parte nueva
     * y, si la entrada arrastra una parte del resto, la suya en `carried`) y
     * entra en la huella junto con la lista `prompt`: cambiar el paquete cambia
     * la huella, así que quien publica firma exactamente el que vio. Sin él
     * (llave en `off`), el objeto de siempre (Q-4).
     *
     * @param  array<string,mixed>  $classification  la salida de {@see classify()}
     * @param  array{mode:string,latest:string|null}  $labGate
     * @param  array<string,mixed>|null  $prompts  `{base, bundleHash, compiler, carried}`, o null con la llave en `off`
     */
    public static function previewHash(string $versionUuid, string $contentHash, string $catalogVersion, array $classification, array $labGate, ?array $prompts = null): string
    {
        self::assertUuid($versionUuid, 'versionUuid');
        self::assertHex64($contentHash, 'contentHash');
        self::assertShortText($catalogVersion, 'catalogVersion');
        if (! self::hasExactKeys($labGate, ['latest', 'mode'])
            || ! in_array($labGate['mode'], [self::LAB_REQUIRED, self::LAB_WAIVED], true)
            || ! in_array($labGate['latest'], self::LAB_LATEST, true)) {
            throw new InvalidArgumentException('labGate es exactamente {mode: required|waived, latest: null|approved|rejected|needs_changes|stale_prompts}.');
        }

        $objeto = [
            'versionUuid' => $versionUuid,
            'contentHash' => $contentHash,
            'catalogVersion' => $catalogVersion,
            'mustMatch' => self::listOf($classification, 'mustMatch'),
            'matches' => self::listOf($classification, 'matches'),
            'noEffect' => self::listOf($classification, 'noEffect'),
            'labGate' => ['mode' => $labGate['mode'], 'latest' => $labGate['latest']],
        ];
        if ($prompts !== null) {
            if (! self::hasExactKeys($prompts, ['base', 'bundleHash', 'carried', 'compiler'])) {
                throw new InvalidArgumentException('prompts es exactamente {base, bundleHash, carried, compiler}.');
            }
            $objeto['prompt'] = self::listOf($classification, 'prompt');
            $objeto['prompts'] = $prompts;
        }

        return StudioContentHasher::hash($objeto);
    }

    /**
     * La deriva de una parte publicada (§A6 y `PublicationStateDto`; Enmienda 1,
     * Q-5): sus cuatro valores declarados frente a las lecturas de ahora, con la
     * comparación de `must_match` (C-5, C-6). Una lectura caída CUENTA como
     * deriva, con `engine: null`: falla cerrado y bloquea la promoción, igual
     * que `engine_reading_unavailable` al publicar. En el orden del catálogo.
     *
     * @param  array<string,mixed>  $declared  el `declared` de la parte (§A4.2)
     * @param  array<string,mixed>  $readings  `StudioEngineReadings::read()['params']`
     * @param  array<string,mixed>  $catalog
     * @return list<array{param:string,declared:bool|string,engine:mixed}>
     */
    public static function drift(array $declared, array $readings, array $catalog): array
    {
        self::assertDeclared($declared, 'declared');
        $parametros = self::parameters($catalog);

        $out = [];
        foreach (array_keys(self::MUST_MATCH) as $id) {
            $c = self::against($id, $declared[$id], $parametros[$id]['binding'], $readings);
            if (! $c['ok']) {
                $out[] = ['param' => $id, 'declared' => $declared[$id], 'engine' => $c['engine']];
            }
        }

        return $out;
    }

    // ── La entrada que lee el motor (§A4.2) ─────────────────────────────────

    /**
     * Los cuatro valores `must_match` de una versión, por id: lo único de su
     * configuración que entra en la declaración firmada (§A1).
     *
     * @param  array<string,array<string,mixed>>  $configs
     * @return array<string,bool|string>
     */
    public static function declared(array $configs): array
    {
        $out = [];
        foreach (array_keys(self::MUST_MATCH) as $id) {
            [$capa, $clave] = explode('.', $id, 2);
            $out[$id] = self::valueAt($configs, $capa, $clave, 'configs');
        }
        self::assertDeclared($out, 'declared');

        return $out;
    }

    /**
     * Una parte (§A4.2): la versión, sus identidades (huella de contenido,
     * versión del catálogo, quien aprobó y quien publica) y sus cuatro valores
     * `must_match`. Nunca etiquetas, notas ni motivos (§A1). Las claves salen
     * ordenadas por bytes en los dos niveles.
     *
     * @param  array<string,mixed>  $declared  {@see declared()}
     * @return array{approvedBy:int,catalogVersion:string,contentHash:string,declared:array<string,bool|string>,publishedBy:int,version:string}
     */
    public static function part(string $version, string $contentHash, string $catalogVersion, int $approvedBy, int $publishedBy, array $declared): array
    {
        ksort($declared, SORT_STRING);
        $parte = [
            'approvedBy' => $approvedBy,
            'catalogVersion' => $catalogVersion,
            'contentHash' => $contentHash,
            'declared' => $declared,
            'publishedBy' => $publishedBy,
            'version' => $version,
        ];
        self::assertPart($parte, 'part', self::CONTRACT);

        return $parte;
    }

    /**
     * Enmienda M-1 (§B.4): la parte `/2` de una parte `/1`, con los cuatro
     * valores gobernados y la huella del paquete que compiló
     * {@see StudioPromptBundle::compile()} con ellos. Las claves salen ordenadas
     * por bytes en todos los niveles.
     *
     * @param  array<string,mixed>  $part  una parte `/1` ({@see part()}) o `/2` (se sustituyen sus dos claves)
     * @param  array<string,mixed>  $governed
     * @param  array<string,mixed>  $prompts  `{base, bundleHash, compiler}`
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException si algo no tiene la forma que lee el motor
     */
    public static function partV2(array $part, array $governed, array $prompts): array
    {
        $parte = self::partV1($part);
        ksort($governed, SORT_STRING);
        ksort($prompts, SORT_STRING);
        $parte['governed'] = $governed;
        $parte['prompts'] = $prompts;
        ksort($parte, SORT_STRING);
        self::assertPart($parte, 'part', self::CONTRACT_V2);

        return $parte;
    }

    /**
     * La parte `/1` de una parte de cualquiera de los dos contratos: sin
     * `governed` ni `prompts`. Es lo que se escribe con la llave de prompts en
     * `off` (lo que declara la parte no cambia).
     *
     * @param  array<string,mixed>  $part
     * @return array<string,mixed>
     */
    public static function partV1(array $part): array
    {
        unset($part['governed'], $part['prompts']);
        self::assertPart($part, 'part', self::CONTRACT);

        return $part;
    }

    /** ¿Es una parte `/2` (lleva `governed` y `prompts`)? */
    public static function isPartV2(array $part): bool
    {
        return array_key_exists('governed', $part) || array_key_exists('prompts', $part);
    }

    /**
     * Qué parte va en cada lado de la entrada nueva (§A4.2, §A5.2–A5.4; fixture
     * E-4; Enmienda 1, Q-2 y Q-3). Las partes se COPIAN tal cual, con sus
     * identidades: al promover, la parte canaria pasa también al resto con el
     * `publishedBy` y el `approvedBy` de su publicación (Q-3; quien promueve
     * queda en la fila de la cadena y en el libro).
     *
     *   publish             {canary: $part, rest: el resto de la cabeza, o null}
     *   promote             {canary: la canaria de la cabeza, rest: la misma}
     *   rollback CANARY     {canary: el resto de la cabeza, rest: el mismo}
     *   rollback PUBLISHED  {canary: $restores, rest: $restores}; con OTRA versión
     *                       en canario se conserva su parte: {canary: esa,
     *                       rest: $restores} (Q-2, opción a)
     *   legacy              {canary: null, rest: null}
     *
     * Comprueba que la cabeza diga lo que el estado de la versión implica (la
     * invariante de §A2): no se compone una entrada que borre la parte de una
     * versión que sigue en CANARY, que promueva o revierta otra versión que la
     * de la cabeza, o que restaure la misma que se revierte.
     *
     * @param  string|null  $version  el uuid de la versión sobre la que se actúa (null en legacy)
     * @param  string|null  $versionStatus  su estado ahora (`StudioVersionStatus`; null en legacy)
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}|null  $head  las partes de la cabeza ({@see parts()}), o null si no hay ninguna fila
     * @param  array<string,mixed>|null  $part  solo al publicar: la parte nueva ({@see part()})
     * @param  array<string,mixed>|null  $restores  solo al revertir la PUBLISHED: la parte resto de la entrada anterior a su promoción, o null si no había
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     *
     * @throws InvalidArgumentException
     */
    public static function compose(string $action, ?string $version, ?string $versionStatus, ?array $head, ?array $part = null, ?array $restores = null): array
    {
        $cabeza = self::headOf($head);

        if ($action === self::ACTION_LEGACY) {
            if ($version !== null || $versionStatus !== null || $part !== null || $restores !== null) {
                throw new InvalidArgumentException('Volver a Legacy no actúa sobre ninguna versión.');
            }

            return ['canary' => null, 'rest' => null];
        }

        self::assertUuid($version, 'version');
        $estado = StudioVersionStatus::tryFrom((string) $versionStatus);
        if ($part !== null && $action !== self::ACTION_PUBLISH) {
            throw new InvalidArgumentException('Solo publicar lleva una parte nueva.');
        }
        if ($restores !== null && ! ($action === self::ACTION_ROLLBACK && $estado === StudioVersionStatus::Published)) {
            throw new InvalidArgumentException('Solo revertir la versión publicada restaura una parte.');
        }

        return match ($action) {
            self::ACTION_PUBLISH => self::forPublish($version, $estado, $cabeza, $part),
            self::ACTION_PROMOTE => self::forPromote($version, $estado, $cabeza),
            self::ACTION_ROLLBACK => self::forRollback($version, $estado, $cabeza, $restores),
            default => throw new InvalidArgumentException("Acción desconocida: {$action}."),
        };
    }

    /**
     * Los BYTES exactos de una entrada (§A4.2; fixture E-1 a E-3): el JSON
     * canónico de `{canary, contract, entry, issuedAt, rest, seq}`, con las
     * claves ordenadas por bytes en los tres niveles, sin espacios y sin escapar
     * la barra del contrato. `issuedAt` se escribe en UTC al segundo: cualquier
     * desfase se convierte y las fracciones se truncan.
     *
     * Enmienda M-1: con `$contract` = `/2`, toda parte no nula es `/2`
     * ({@see partV2()}) y la entrada no puede ser la de Legacy (`{null, null}`
     * se escribe siempre en `/1`, R10); con `/1`, toda parte es `/1`.
     *
     * @param  array<string,mixed>|null  $canary  una parte ({@see part()}) o null
     * @param  array<string,mixed>|null  $rest  una parte o null
     *
     * @throws InvalidArgumentException si algo no es lo que el motor lee
     */
    public static function effectiveJson(string $entry, int $seq, DateTimeInterface $issuedAt, ?array $canary, ?array $rest, string $contract = self::CONTRACT): string
    {
        self::assertUuid($entry, 'entry');
        if ($seq < 1) {
            throw new InvalidArgumentException('seq empieza en 1.');
        }
        if (! in_array($contract, self::CONTRACTS, true)) {
            throw new InvalidArgumentException("Contrato desconocido: {$contract}.");
        }
        if ($contract === self::CONTRACT_V2 && $canary === null && $rest === null) {
            throw new InvalidArgumentException('Volver a Legacy se escribe siempre en '.self::CONTRACT.' (R10).');
        }
        foreach (['canary' => $canary, 'rest' => $rest] as $lado => $parte) {
            if ($parte !== null) {
                self::assertPart($parte, $lado, $contract);
            }
        }

        $json = StudioContentHasher::canonicalJson([
            'canary' => $canary,
            'contract' => $contract,
            'entry' => $entry,
            'issuedAt' => self::utc($issuedAt),
            'rest' => $rest,
            'seq' => $seq,
        ]);
        if (mb_strlen($json, 'UTF-8') > self::MAX_EFFECTIVE_JSON) {
            throw new InvalidArgumentException('La entrada supera lo que lee el motor ('.self::MAX_EFFECTIVE_JSON.' caracteres).');
        }

        return $json;
    }

    /** La columna `effective_hash`: sha256 de los bytes, en hexadecimal en minúscula (§A3.1). */
    public static function effectiveHash(string $effectiveJson): string
    {
        return hash('sha256', $effectiveJson);
    }

    /**
     * Las columnas que el motor verifica (§A3.1, §A4.1): el contrato, los bytes
     * de {@see effectiveJson()}, su huella y su firma. LA FIRMA ES UN PUNTO DE
     * INYECCIÓN: `$sign` recibe los bytes exactos y devuelve la firma; quien
     * publica pasa `UltronPublishedConfig::signatureFor(...)`, la única
     * implementación. Una firma que no son 64 hexadecimales en minúscula (S-4)
     * no se escribe: el motor la leería como `signature_invalid`.
     *
     * Enmienda M-1: `$contract` es el de la entrada ({@see effectiveJson()}).
     * La firma es la misma para los dos (§B.4: «firma … los de `/1`»).
     *
     * @param  array<string,mixed>|null  $canary
     * @param  array<string,mixed>|null  $rest
     * @param  Closure(string): string  $sign
     * @return array{contract:string,effectiveJson:string,effectiveHash:string,signature:string}
     *
     * @throws InvalidArgumentException si la entrada no es válida
     * @throws UnexpectedValueException si la firma no tiene la forma del contrato
     */
    public static function declaration(string $entry, int $seq, DateTimeInterface $issuedAt, ?array $canary, ?array $rest, Closure $sign, string $contract = self::CONTRACT): array
    {
        $json = self::effectiveJson($entry, $seq, $issuedAt, $canary, $rest, $contract);
        $firma = $sign($json);
        if (! is_string($firma) || preg_match(self::HEX64, $firma) !== 1) {
            throw new UnexpectedValueException('La firma no tiene la forma del contrato: 64 hexadecimales en minúscula.');
        }

        return [
            'contract' => $contract,
            'effectiveJson' => $json,
            'effectiveHash' => self::effectiveHash($json),
            'signature' => $firma,
        ];
    }

    /**
     * Las partes de una entrada ya escrita (la cabeza), para componer la
     * siguiente con {@see compose()}. Decodificación estricta, con las reglas de
     * {@see effectiveJson()} para las partes y comparando la entrada con su fila
     * como el lector (§A4.3, paso 4): `entry` sin distinguir mayúsculas y `seq`
     * exacto. No mira la huella ni la firma: eso lo comprueba quien lee la fila,
     * antes de llamar aquí; aquí se asegura que lo que el Studio va a copiar
     * tiene la forma del contrato.
     *
     * Enmienda M-1: lee `/1` y `/2` (con las partes de su contrato); el
     * contrato lo dice {@see entry()}.
     *
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     *
     * @throws InvalidArgumentException si no es una entrada de estos contratos o no es la de esa fila
     */
    public static function parts(string $effectiveJson, string $entry, int $seq): array
    {
        $e = self::entry($effectiveJson, $entry, $seq);

        return ['canary' => $e['canary'], 'rest' => $e['rest']];
    }

    /**
     * {@see parts()} con el contrato de la entrada.
     *
     * @return array{contract:string,canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     *
     * @throws InvalidArgumentException si no es una entrada de estos contratos o no es la de esa fila
     */
    public static function entry(string $effectiveJson, string $entry, int $seq): array
    {
        try {
            $e = json_decode($effectiveJson, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('La entrada no es JSON.', 0, $error);
        }

        if (! self::hasExactKeys($e, self::ENTRY_KEYS) || ! in_array($e['contract'], self::CONTRACTS, true) || ! is_string($e['issuedAt'])) {
            throw new InvalidArgumentException('La entrada no tiene la forma de los contratos '.implode(' o ', self::CONTRACTS).'.');
        }
        if (! is_string($e['entry']) || strtolower($e['entry']) !== strtolower($entry) || ! is_int($e['seq']) || $e['seq'] !== $seq) {
            throw new InvalidArgumentException('La entrada no es la de esa fila (entry o seq).');
        }
        foreach (['canary', 'rest'] as $lado) {
            if ($e[$lado] !== null) {
                self::assertPart($e[$lado], $lado, $e['contract']);
            }
        }

        return ['contract' => $e['contract'], 'canary' => $e['canary'], 'rest' => $e['rest']];
    }

    /** Un instante en UTC al segundo, `AAAA-MM-DDTHH:MM:SSZ` (fixture E-2): las fracciones se truncan, nunca se redondean. */
    public static function utc(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    // ── Composición, por acción ─────────────────────────────────────────────

    /**
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $cabeza
     * @param  array<string,mixed>|null  $parte
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     */
    private static function forPublish(string $version, ?StudioVersionStatus $estado, array $cabeza, ?array $parte): array
    {
        if ($estado !== StudioVersionStatus::Approved) {
            throw new InvalidArgumentException('Solo se publica una versión APPROVED.');
        }
        if ($parte === null) {
            throw new InvalidArgumentException('Publicar necesita la parte nueva de la versión.');
        }
        self::assertPart($parte, 'part');
        if ($parte['version'] !== $version) {
            throw new InvalidArgumentException('La parte nueva es de otra versión.');
        }
        if (self::canaryInProgress($cabeza)) {
            throw new InvalidArgumentException('La cabeza tiene otra versión en canario: publicar borraría su parte.');
        }

        return ['canary' => $parte, 'rest' => $cabeza['rest']];
    }

    /**
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $cabeza
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     */
    private static function forPromote(string $version, ?StudioVersionStatus $estado, array $cabeza): array
    {
        if ($estado !== StudioVersionStatus::Canary) {
            throw new InvalidArgumentException('Solo se promueve la versión en CANARY.');
        }
        if (! self::canaryInProgress($cabeza) || $cabeza['canary']['version'] !== $version) {
            throw new InvalidArgumentException('La cabeza no tiene esta versión en canario.');
        }

        return ['canary' => $cabeza['canary'], 'rest' => $cabeza['canary']];
    }

    /**
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $cabeza
     * @param  array<string,mixed>|null  $restaurada
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     */
    private static function forRollback(string $version, ?StudioVersionStatus $estado, array $cabeza, ?array $restaurada): array
    {
        if ($estado === StudioVersionStatus::Canary) {
            if (! self::canaryInProgress($cabeza) || $cabeza['canary']['version'] !== $version) {
                throw new InvalidArgumentException('La cabeza no tiene esta versión en canario.');
            }

            return ['canary' => $cabeza['rest'], 'rest' => $cabeza['rest']];
        }

        if ($estado !== StudioVersionStatus::Published) {
            throw new InvalidArgumentException('Solo se revierte la versión en CANARY o la PUBLISHED.');
        }
        if (($cabeza['rest']['version'] ?? null) !== $version) {
            throw new InvalidArgumentException('La cabeza no tiene esta versión publicada en el resto.');
        }
        if ($restaurada !== null) {
            self::assertPart($restaurada, 'restores');
            if ($restaurada['version'] === $version) {
                throw new InvalidArgumentException('Revertir no puede restaurar la misma versión.');
            }
        }

        // Q-2 (a): deshacer no depende de otro estado; la versión en canario conserva su parte.
        return ['canary' => self::canaryInProgress($cabeza) ? $cabeza['canary'] : $restaurada, 'rest' => $restaurada];
    }

    /**
     * ¿Declara la cabeza una parte canaria distinta de la del resto? Esa es la
     * versión en CANARY (la cabeza coincide con los estados: invariante de §A2).
     *
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $cabeza
     */
    private static function canaryInProgress(array $cabeza): bool
    {
        return $cabeza['canary'] !== null && $cabeza['canary']['version'] !== ($cabeza['rest']['version'] ?? null);
    }

    /**
     * @param  array<string,mixed>|null  $head
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     */
    private static function headOf(?array $head): array
    {
        if ($head === null) {
            return ['canary' => null, 'rest' => null];
        }
        if (! self::hasExactKeys($head, ['canary', 'rest'])) {
            throw new InvalidArgumentException('La cabeza son sus dos partes: canary y rest.');
        }
        foreach (['canary', 'rest'] as $lado) {
            if ($head[$lado] !== null) {
                self::assertPart($head[$lado], "head.{$lado}");
            }
        }

        return ['canary' => $head['canary'], 'rest' => $head['rest']];
    }

    // ── Catálogo y comparación ──────────────────────────────────────────────

    /**
     * Los parámetros del catálogo con su mando, por id `capa.clave` y en el
     * orden del catálogo (C-2): las capas de `layers` y, dentro de cada una, el
     * orden de sus `bindings`. Sus `env_flag` tienen que ser exactamente los
     * cuatro `must_match` del contrato del motor, en ese orden.
     *
     * @param  array<string,mixed>  $catalog
     * @return array<string,array{layer:string,key:string,binding:array<string,mixed>}>
     */
    private static function parameters(array $catalog): array
    {
        $capas = $catalog['layers'] ?? null;
        $mandos = $catalog['bindings'] ?? null;
        if (! is_array($capas) || ! array_is_list($capas) || ! is_array($mandos)) {
            throw new InvalidArgumentException('El catálogo necesita layers y bindings.');
        }

        $ids = [];
        foreach ($capas as $capa) {
            if (! is_array($capa) || ! is_string($capa['id'] ?? null)) {
                throw new InvalidArgumentException('Cada capa del catálogo necesita su id.');
            }
            $ids[] = $capa['id'];
        }
        foreach (array_keys($mandos) as $capa) {
            if (! in_array($capa, $ids, true)) {
                throw new InvalidArgumentException("El catálogo tiene mandos de una capa que no existe: {$capa}.");
            }
        }

        $out = [];
        $banderas = [];
        foreach ($ids as $capa) {
            $porClave = $mandos[$capa] ?? [];
            if (! is_array($porClave)) {
                throw new InvalidArgumentException("Los mandos de {$capa} tienen que ser un objeto.");
            }
            foreach ($porClave as $clave => $mando) {
                $id = "{$capa}.{$clave}";
                if (! is_array($mando) || ! in_array($mando['origin'] ?? null, self::ORIGINS, true)) {
                    throw new InvalidArgumentException("El mando de {$id} no tiene un origen conocido.");
                }
                $out[$id] = ['layer' => $capa, 'key' => (string) $clave, 'binding' => $mando];
                if ($mando['origin'] === 'env_flag') {
                    $banderas[] = $id;
                }
            }
        }

        if ($banderas !== array_keys(self::MUST_MATCH)) {
            throw new InvalidArgumentException('El catálogo no casa con el contrato '.self::CONTRACT.': sus env_flag no son los cuatro must_match, en su orden.');
        }

        return $out;
    }

    /**
     * Un valor de la versión (o de la parte) frente a la lectura del motor
     * (C-5, C-6). Una lectura sin la forma de `StudioEngineReadings` (sin
     * `available: true` o sin `value`) es una lectura caída: no coincide nunca.
     *
     * @param  array<string,mixed>  $mando
     * @param  array<string,mixed>  $readings
     * @return array{available:bool,engine:mixed,ok:bool}
     */
    private static function against(string $id, mixed $valor, array $mando, array $readings): array
    {
        $lectura = $readings[$id] ?? null;
        if (! is_array($lectura) || ($lectura['available'] ?? null) !== true || ! array_key_exists('value', $lectura)) {
            return ['available' => false, 'engine' => null, 'ok' => false];
        }

        $motor = $lectura['value'];
        if (is_string($motor) && isset(self::ENGINE_ALIASES[$id][$motor])) {
            $motor = self::ENGINE_ALIASES[$id][$motor];
        }
        $loQueTengaElMotor = array_key_exists('engineOption', $mando) && self::same($valor, $mando['engineOption']);

        return ['available' => true, 'engine' => $motor, 'ok' => $loQueTengaElMotor || self::same($valor, $motor)];
    }

    /**
     * Igualdad estricta del valor JSON (C-7): en tipo y en valor, y en una lista
     * cuenta el orden. Es la misma escritura que firma la huella de contenido.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return StudioContentHasher::canonicalJson($a) === StudioContentHasher::canonicalJson($b);
    }

    /**
     * Los códigos S-NNN de la versión, por orden de bytes (C-3, C-9; el de
     * `StudioContentHasher::versionHash`). Una R-xx no tiene clase; cualquier
     * otro código, o uno repetido, no es una versión del Studio.
     *
     * @param  list<mixed>  $rules
     * @return list<string>
     */
    private static function studioRuleCodes(array $rules): array
    {
        $codigos = [];
        foreach ($rules as $i => $regla) {
            $codigo = is_array($regla) ? ($regla['code'] ?? null) : null;
            if (! is_string($codigo)) {
                throw new InvalidArgumentException("La regla {$i} de la versión no tiene código.");
            }
            if (str_starts_with($codigo, 'R-')) {
                continue;
            }
            if (! str_starts_with($codigo, 'S-')) {
                throw new InvalidArgumentException("La regla {$i} de la versión no es ni S-NNN ni R-xx.");
            }
            if (in_array($codigo, $codigos, true)) {
                throw new InvalidArgumentException("La versión repite la regla {$codigo}.");
            }
            $codigos[] = $codigo;
        }
        sort($codigos, SORT_STRING);

        return $codigos;
    }

    /**
     * Nada fuera del catálogo: cada capa es una de `layers` (el normalizador las
     * emite todas, también una sin parámetros) y cada clave, uno de sus
     * parámetros.
     *
     * @param  array<string,mixed>  $configs
     * @param  list<string>  $capas  los ids de `layers` (ya validados por {@see parameters()})
     * @param  array<string,array{layer:string,key:string,binding:array<string,mixed>}>  $parametros
     */
    private static function assertKnownConfigs(array $configs, array $capas, array $parametros): void
    {
        foreach ($configs as $capa => $valores) {
            if (! in_array($capa, $capas, true)) {
                throw new InvalidArgumentException("configs.{$capa} no es una capa del catálogo.");
            }
            if (! is_array($valores)) {
                throw new InvalidArgumentException("configs.{$capa} tiene que ser un objeto.");
            }
            foreach (array_keys($valores) as $clave) {
                if (! isset($parametros["{$capa}.{$clave}"])) {
                    throw new InvalidArgumentException("configs.{$capa}.{$clave} no es un parámetro del catálogo.");
                }
            }
        }
    }

    /** @param  array<array-key,mixed>  $configs */
    private static function valueAt(array $configs, string $capa, string $clave, string $donde): mixed
    {
        if (! is_array($configs[$capa] ?? null) || ! array_key_exists($clave, $configs[$capa])) {
            throw new InvalidArgumentException("Falta {$donde}.{$capa}.{$clave}: el contenido llega normalizado y completo.");
        }

        return $configs[$capa][$clave];
    }

    // ── Validación ──────────────────────────────────────────────────────────

    /**
     * Una parte con la forma de su contrato: `/1` (6 claves), `/2` (8 claves,
     * {@see assertPrompted()}) o, sin contrato, cualquiera de las dos (al
     * componer: la cabeza puede ser de cualquiera, y quien escribe pasa todas
     * las partes al contrato de la entrada nueva).
     */
    private static function assertPart(mixed $parte, string $campo, ?string $contrato = null): void
    {
        $v2 = $contrato === self::CONTRACT_V2 || ($contrato === null && is_array($parte) && self::isPartV2($parte));
        if (! self::hasExactKeys($parte, $v2 ? self::PART_KEYS_V2 : self::PART_KEYS)) {
            throw new InvalidArgumentException("{$campo}: una parte de ".($v2 ? self::CONTRACT_V2 : self::CONTRACT).' lleva exactamente '.implode(', ', $v2 ? self::PART_KEYS_V2 : self::PART_KEYS).'.');
        }
        if ($v2) {
            self::assertPrompted($parte, $campo);
        }
        foreach (['approvedBy', 'publishedBy'] as $clave) {
            if (! is_int($parte[$clave]) || $parte[$clave] < 1) {
                throw new InvalidArgumentException("{$campo}.{$clave} tiene que ser un id entero positivo.");
            }
        }
        self::assertShortText($parte['catalogVersion'], "{$campo}.catalogVersion");
        self::assertHex64($parte['contentHash'], "{$campo}.contentHash");
        self::assertUuid($parte['version'], "{$campo}.version");
        self::assertDeclared($parte['declared'], "{$campo}.declared");
    }

    /**
     * Las dos claves de `/2` con sus claves y tipos exactos (los del lector del
     * motor) y, además, sus valores: los textos, cortos y de ASCII visible; la
     * huella, 64 hexadecimales en minúscula; el compilador, un entero positivo.
     * Las claves de `governed` dependen del compilador firmado: con el 1, las
     * cuatro de {@see GOVERNED}; desde el 2, además {@see GOVERNED_PHRASES},
     * una lista de textos. Que los valores gobernados compilen (también las
     * frases: cuántas y con qué caracteres) lo garantiza quien los compila
     * ({@see StudioPromptBundle}); el motor vuelve a compilarlos en `decide`.
     *
     * @param  array<string,mixed>  $parte
     */
    private static function assertPrompted(array $parte, string $campo): void
    {
        self::assertTypedObject($parte['prompts'], self::PROMPTS, "{$campo}.prompts");
        self::assertHex64($parte['prompts']['bundleHash'], "{$campo}.prompts.bundleHash");
        if ($parte['prompts']['compiler'] < 1) {
            throw new InvalidArgumentException("{$campo}.prompts.compiler tiene que ser un entero positivo.");
        }

        $conFrases = $parte['prompts']['compiler'] >= self::PHRASES_SINCE_COMPILER;
        self::assertTypedObject($parte['governed'], $conFrases ? self::GOVERNED + [self::GOVERNED_PHRASES => 'array'] : self::GOVERNED, "{$campo}.governed");
        if ($conFrases) {
            $frases = $parte['governed'][self::GOVERNED_PHRASES];
            if (! array_is_list($frases) || array_filter($frases, static fn (mixed $f): bool => ! is_string($f)) !== []) {
                throw new InvalidArgumentException("{$campo}.governed.".self::GOVERNED_PHRASES.' tiene que ser una lista de textos.');
            }
        }
    }

    /**
     * Un objeto con exactamente esas claves y esos tipos; sus textos (salvo
     * la huella), cortos y de ASCII visible.
     *
     * @param  array<string,string>  $tipos  clave → tipo (`gettype`)
     */
    private static function assertTypedObject(mixed $valor, array $tipos, string $campo): void
    {
        if (! self::hasExactKeys($valor, array_keys($tipos))) {
            throw new InvalidArgumentException("{$campo} lleva exactamente ".implode(', ', array_keys($tipos)).'.');
        }
        foreach ($tipos as $id => $tipo) {
            if (gettype($valor[$id]) !== $tipo) {
                throw new InvalidArgumentException("{$campo}.{$id} tiene que ser de tipo {$tipo}.");
            }
            if ($tipo === 'string' && $id !== 'bundleHash') {
                self::assertShortText($valor[$id], "{$campo}.{$id}");
            }
        }
    }

    private static function assertDeclared(mixed $declarado, string $campo): void
    {
        if (! self::hasExactKeys($declarado, array_keys(self::MUST_MATCH))) {
            throw new InvalidArgumentException("{$campo} lleva exactamente los cuatro must_match.");
        }
        foreach (self::MUST_MATCH as $id => $tipo) {
            if (gettype($declarado[$id]) !== $tipo) {
                throw new InvalidArgumentException("{$campo}.{$id} tiene que ser de tipo {$tipo}.");
            }
            if ($tipo === 'string') {
                self::assertShortText($declarado[$id], "{$campo}.{$id}");
            }
        }
    }

    private static function assertUuid(mixed $valor, string $campo): void
    {
        if (! is_string($valor) || preg_match(self::UUID, $valor) !== 1) {
            throw new InvalidArgumentException("{$campo} tiene que ser un uuid en minúscula.");
        }
    }

    private static function assertHex64(mixed $valor, string $campo): void
    {
        if (! is_string($valor) || preg_match(self::HEX64, $valor) !== 1) {
            throw new InvalidArgumentException("{$campo} tiene que ser una huella de 64 hexadecimales en minúscula.");
        }
    }

    private static function assertShortText(mixed $valor, string $campo): void
    {
        if (! is_string($valor) || preg_match(self::SHORT_TEXT, $valor) !== 1) {
            throw new InvalidArgumentException("{$campo} tiene que ser un texto de 1 a 32 caracteres ASCII visibles.");
        }
    }

    /** @param  list<string>  $claves */
    private static function hasExactKeys(mixed $valor, array $claves): bool
    {
        if (! is_array($valor)) {
            return false;
        }
        $tiene = array_keys($valor);
        sort($tiene, SORT_STRING);
        sort($claves, SORT_STRING);

        return $tiene === $claves;
    }

    /**
     * @param  array<string,mixed>  $classification
     * @return list<mixed>
     */
    private static function listOf(array $classification, string $clave): array
    {
        $lista = $classification[$clave] ?? null;
        if (! is_array($lista) || ! array_is_list($lista)) {
            throw new InvalidArgumentException("La clasificación necesita {$clave} como lista.");
        }

        return $lista;
    }

    /**
     * Como `StudioVersions::asSet`: sin repetidos y por orden de bytes.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function asSet(array $ids): array
    {
        $conjunto = array_values(array_unique($ids));
        sort($conjunto, SORT_STRING);

        return $conjunto;
    }
}
