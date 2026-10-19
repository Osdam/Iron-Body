<?php

namespace App\Services\UltronStudio;

use App\Support\Caja\UntrustedText;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * La coherencia de las reglas del Studio (contrato G §A3): funciones PURAS
 * sobre un catálogo con la forma de {@see StudioCatalog::toArray()}. Sin base
 * de datos, sin HTTP, sin configuración y sin reloj.
 *
 * Es la MISMA semántica que `studio-rules-coherence.ts` del CRM: el CRM avisa
 * en vivo mientras se edita y el servidor repite el cálculo y manda él.
 * `tests/fixtures/ultron-studio/coherence-cases.json` (lo escribe el tester
 * desde el contrato, no lo genera ninguna de las dos implementaciones) fija la
 * salida caso a caso.
 *
 * MODELO. Cada predicado es una VARIABLE con un CONJUNTO PERMITIDO dentro de su
 * dominio:
 *
 *   config:capa.clave           toggle [true,false] · select, sus opciones en orden · range, la rejilla min+k·step
 *   config:capa.clave#etiqueta  tags [true,false]: contains → {true}, not_contains → {false}
 *   edge:Exx                    [present,absent]; si es obligatoria, solo [present]
 *   text#frase                  [true,false]: require → {true}, forbid → {false}; cada frase es una variable
 *
 * `require P` = el conjunto de P; `forbid P` = el dominio menos ese conjunto;
 * `limit` equivale a `require`. Los conjuntos son listas en el orden del
 * dominio, así que toda salida es determinista.
 *
 * CONTROLES DEL MOTOR (pseudorreglas que salen del catálogo, nunca de la base):
 *  1. las restricciones `blocking`, traducidas tal cual: `forbidden v` →
 *     `forbid eq v`; `when w` + `requires` → `when eq w` ⇒ `require E present`;
 *     `when w` + `forbids` → `when eq w` ⇒ `forbid E present`;
 *  2. las aristas obligatorias, como restricción del dominio (solo `present`);
 *  3. `legacyConditions`, las condiciones de las reglas del código.
 *
 * CONTRADICCIÓN entre X e Y, por pares: las variables comunes a los dos `when`
 * tienen intersección no vacía y alguna afirmación de X y otra de Y sobre la
 * misma variable tienen intersección vacía. LIMITACIÓN DECLARADA: no encadena
 * reglas (si X implica v, v implica w y w implica no-X, no se detecta) ni cruza
 * el `when` de una con el `then` de otra. Una regla que nunca se aplicaría no
 * contradice a nadie, y una afirmación imposible ya tiene su propio aviso.
 *
 * ORDEN. Las reglas se recorren en el orden en que llegan (de la base y de las
 * versiones llegan por código); los controles, en este: restricciones
 * `blocking` en el orden del catálogo, aristas obligatorias en el orden del
 * catálogo y reglas del código por código; los problemas de una regla, por id
 * (en el orden de {@see ISSUE_LEVELS}) y, dentro de cada id, por la aparición
 * de su objetivo (`when`, `then`, `refs.configs`, `refs.edges`). Una regla del
 * código no recibe contradicciones: sus condiciones son controles.
 *
 * Normalización de texto mínima y explícita, igual en los dos lados y sin
 * `ext-intl` (producción no la tiene): recorte con el conjunto de
 * `String.prototype.trim()`, frases con los espacios internos colapsados a uno
 * y minúsculas solo en A–Z y ÁÉÍÓÚÜÑ. Longitudes en puntos de código. Ninguna
 * expresión regular sale del usuario.
 */
final class StudioRuleCoherence
{
    public const MAX_WHEN = 3;

    public const MAX_THEN = 3;

    /** Tamaño máximo de `conditions` codificado en JSON (lo que llega). */
    public const MAX_BYTES = 4096;

    public const MAX_PHRASES = 10;

    public const MIN_PHRASE_LENGTH = 2;

    public const MAX_PHRASE_LENGTH = 60;

    /** El mismo tope que una etiqueta del borrador ({@see StudioDraftNormalizer::MAX_TAG_LENGTH}). */
    public const MAX_TAG_LENGTH = 60;

    /** `StudioRule::SOURCE_STUDIO`: sin importar el modelo, para seguir siendo puro (una prueba los ata). */
    public const SOURCE_STUDIO = 'studio';

    /** `StudioRule::STATUS_ARCHIVED`. */
    public const STATUS_ARCHIVED = 'archived';

    public const ISSUE_INCOMPATIBLE = 'regla-incompatible';

    public const ISSUE_CONTRADICTS_CONTROL = 'regla-contradice-control';

    public const ISSUE_CONTRADICTS_RULE = 'regla-contradice-regla';

    public const ISSUE_INAPPLICABLE = 'regla-inaplicable';

    public const ISSUE_EMPTY = 'regla-vacia';

    /** Los ids de `analysis.issues`, en el orden en que salen, con su nivel. */
    public const ISSUE_LEVELS = [
        self::ISSUE_INCOMPATIBLE => 'blocking',
        self::ISSUE_CONTRADICTS_CONTROL => 'blocking',
        self::ISSUE_CONTRADICTS_RULE => 'blocking',
        self::ISSUE_INAPPLICABLE => 'warning',
        self::ISSUE_EMPTY => 'warning',
    ];

    /** Codificación con la que se mide el tamaño: la de `JSON.stringify` en bytes UTF-8. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * Los espacios de `String.prototype.trim()` (y de `\s` en JavaScript):
     * blancos y terminadores de línea de ECMAScript. Es el mismo conjunto que
     * `StudioDraftNormalizer::JS_TRIM`; una prueba lo comprueba.
     */
    private const JS_TRIM = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Las únicas mayúsculas que pasan a minúsculas en una frase. */
    private const LOWER = [
        'A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e', 'F' => 'f', 'G' => 'g', 'H' => 'h', 'I' => 'i',
        'J' => 'j', 'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n', 'O' => 'o', 'P' => 'p', 'Q' => 'q', 'R' => 'r',
        'S' => 's', 'T' => 't', 'U' => 'u', 'V' => 'v', 'W' => 'w', 'X' => 'x', 'Y' => 'y', 'Z' => 'z',
        'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ñ' => 'ñ',
    ];

    /** Operadores por objetivo. */
    private const OPS = [
        'config' => ['eq', 'in', 'not_in', 'lte', 'gte', 'contains', 'not_contains'],
        'edge' => ['present', 'absent'],
        'text' => ['contains'],
    ];

    /** Operadores por tipo de parámetro; `text` y `textarea` no admiten ninguno. */
    private const TYPE_OPS = [
        'toggle' => ['eq'],
        'select' => ['eq', 'in', 'not_in'],
        'range' => ['eq', 'lte', 'gte'],
        'tags' => ['contains', 'not_contains'],
    ];

    private const KINDS = ['require', 'forbid', 'limit'];

    /** Una rejilla de rango más larga que esto es un catálogo roto, no una condición. */
    private const MAX_GRID = 10000;

    // ── API ─────────────────────────────────────────────────────────────────

    /**
     * Valida `conditions` contra el catálogo y la devuelve en su forma
     * canónica (null = regla documental). Lo que no vale es un 422 estándar
     * con TODAS las claves a la vez (`conditions.*` y, si se pasan las capas
     * de la regla, `layers`), como el normalizador del borrador.
     *
     * Cubre la forma, los valores, las repeticiones, lo imposible, lo que nunca
     * se aplicaría, la autocontradicción y la contradicción con un control del
     * motor. La contradicción con otra regla del Studio NO: es un 409 que
     * necesita las reglas vivas ({@see contradictions()}).
     *
     * @param  array<string,mixed>  $catalog  la forma de {@see StudioCatalog::toArray()}
     * @param  list<string>|null  $layers  las capas de la regla: incluyen la de cada objetivo de configuración y una de las dos de cada arista
     * @return array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}|null
     *
     * @throws ValidationException
     */
    public static function canonicalize(mixed $conditions, array $catalog, ?array $layers = null): ?array
    {
        $resultado = self::check($conditions, $catalog, $layers);
        if ($resultado['errors'] !== []) {
            throw ValidationException::withMessages($resultado['errors']);
        }

        return $resultado['conditions'];
    }

    /**
     * Lo mismo que {@see canonicalize()} sin lanzar: la forma canónica (o null
     * si hay errores o no hay condiciones) y los errores por clave.
     *
     * @param  array<string,mixed>  $catalog
     * @param  list<string>|null  $layers
     * @return array{conditions:array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}|null,errors:array<string,list<string>>}
     */
    public static function check(mixed $conditions, array $catalog, ?array $layers = null): array
    {
        $v = self::view($catalog);
        $p = self::parse($conditions, $v);
        $errores = $p['errors'];

        // Con el «si» entero se comprueban contra los controles las afirmaciones
        // que se leyeron, aunque otra falle: el 422 lleva todas las claves.
        if ($p['present'] && $p['whenComplete'] && ! self::neverApplies($p)) {
            foreach (self::controls($v) as $control) {
                foreach (self::clash($p, $control['rule']) as $entrada) {
                    self::add($errores, $entrada['path'], self::controlMessage($control, $entrada, $v));
                }
            }
        }

        if ($layers !== null) {
            foreach (self::missingLayers([...$p['when'], ...$p['then']], $layers, $v) as $mensaje) {
                self::add($errores, 'layers', $mensaje);
            }
        }

        return ['conditions' => $errores === [] ? $p['canonical'] : null, 'errors' => $errores];
    }

    /**
     * Los controles del motor traducidos a condiciones (restricciones
     * `blocking` y reglas del código), en su orden. Las aristas obligatorias no
     * están: son una restricción del dominio, no una regla.
     *
     * @param  array<string,mixed>  $catalog
     * @return list<array{with:string,title:string,conditions:array<string,mixed>}>
     */
    public static function controlRules(array $catalog): array
    {
        return array_map(static fn (array $c): array => [
            'with' => $c['with'],
            'title' => $c['title'],
            'conditions' => $c['conditions'],
        ], self::controls(self::view($catalog)));
    }

    /**
     * El conjunto permitido de cada afirmación de unas condiciones, por
     * variable y en orden (las de texto, una por frase). Sirve para comparar
     * una regla del código con su restricción y para enseñar condiciones.
     *
     * @param  array<string,mixed>  $catalog
     * @return list<array{target:string,allowed:list<bool|int|string>,domain:list<bool|int|string>}>
     *
     * @throws InvalidArgumentException si las condiciones no son válidas con este catálogo
     */
    public static function allowedSets(mixed $conditions, array $catalog): array
    {
        $p = self::parse($conditions, self::view($catalog));
        if (! $p['complete'] || $p['errors'] !== []) {
            throw new InvalidArgumentException('Las condiciones no son válidas con este catálogo.');
        }

        return array_map(static fn (array $e): array => ['target' => $e['var'], 'allowed' => $e['set'], 'domain' => $e['domain']], $p['then']);
    }

    /**
     * `analysis` de una regla (RuleDto): verificación y problemas, calculados
     * contra los controles del motor y contra las demás reglas del Studio de
     * `$rules` (las vivas). Paridad con el CRM sobre `id`, `level`, `with` y
     * `target`; los textos los pone el CRM.
     *
     * @param  array<string,mixed>  $rule  RuleInput: `{code, source, status, conditions, refs, …}`
     * @param  list<array<string,mixed>>  $rules
     * @param  array<string,mixed>  $catalog
     * @return array{verification:string,issues:list<array{id:string,level:string,with:list<string>,target:string}>}
     */
    public static function analyze(array $rule, array $rules, array $catalog): array
    {
        $v = self::view($catalog);

        return self::analysis($rule, self::parse($rule['conditions'] ?? null, $v), self::peers($rule, $rules, $v), self::controls($v), $v);
    }

    /**
     * {@see analyze()} de cada regla de `$rules` contra las demás, con la
     * clave de su código y en el orden de `$rules`.
     *
     * @param  list<array<string,mixed>>  $rules
     * @param  array<string,mixed>  $catalog
     * @return array<string,array{verification:string,issues:list<array{id:string,level:string,with:list<string>,target:string}>}>
     */
    public static function analyzeAll(array $rules, array $catalog): array
    {
        $v = self::view($catalog);
        $controles = self::controls($v);
        $analizadas = array_map(static fn (array $r): array => ['rule' => $r, 'parsed' => self::parse($r['conditions'] ?? null, $v)], array_values($rules));

        $out = [];
        foreach ($analizadas as $a) {
            $pares = [];
            if (self::isStudio($a['rule'])) {
                foreach ($analizadas as $b) {
                    if (self::isLiveStudio($b['rule']) && (string) ($b['rule']['code'] ?? '') !== (string) ($a['rule']['code'] ?? '')) {
                        $pares[] = ['code' => (string) $b['rule']['code'], 'parsed' => $b['parsed']];
                    }
                }
            }
            $out[(string) ($a['rule']['code'] ?? '')] = self::analysis($a['rule'], $a['parsed'], $pares, $controles, $v);
        }

        return $out;
    }

    /**
     * Con qué choca una regla candidata (al crearla o editarla): los controles
     * del motor (`control:<id>`, `edge:<id>` o el código de una regla del
     * código), en el orden de los controles (restricciones, aristas
     * obligatorias, reglas del código), y las reglas del Studio vivas de
     * `$live` (sin ella misma, las archivadas ni las del código), en el orden
     * de `$live`. Cada una, una vez.
     *
     * @param  array<string,mixed>  $candidate  RuleInput
     * @param  list<array<string,mixed>>  $live
     * @param  array<string,mixed>  $catalog
     * @return array{controls:list<string>,rules:list<string>}
     */
    public static function conflicts(array $candidate, array $live, array $catalog): array
    {
        $v = self::view($catalog);
        $orden = self::controlOrder(self::controls($v), $v);
        $vivas = [];
        foreach (array_values($live) as $i => $r) {
            $vivas[(string) ($r['code'] ?? '')] ??= $i;
        }

        $out = ['controls' => [], 'rules' => []];
        foreach (self::contradictions($candidate, $live, $catalog) as $c) {
            $lista = $c['kind'] === 'control' ? 'controls' : 'rules';
            if (! in_array($c['with'], $out[$lista], true)) {
                $out[$lista][] = $c['with'];
            }
        }
        usort($out['controls'], static fn (string $a, string $b): int => ($orden[$a] ?? PHP_INT_MAX) <=> ($orden[$b] ?? PHP_INT_MAX));
        usort($out['rules'], static fn (string $a, string $b): int => ($vivas[$a] ?? PHP_INT_MAX) <=> ($vivas[$b] ?? PHP_INT_MAX));

        return $out;
    }

    /**
     * El detalle de {@see conflicts()}: una entrada por contraparte y objetivo,
     * primero los controles y luego las reglas. Con las de tipo `rule` se arma
     * el 409 `rule_contradiction` (`{code, uuid, revision, target, label}`).
     *
     * @param  array<string,mixed>  $candidate
     * @param  list<array<string,mixed>>  $live
     * @param  array<string,mixed>  $catalog
     * @return list<array{kind:string,with:string,target:string,label:string}>
     */
    public static function contradictions(array $candidate, array $live, array $catalog): array
    {
        $v = self::view($catalog);
        $p = self::parse($candidate['conditions'] ?? null, $v);
        if (! $p['complete'] || ! $p['present']) {
            return [];
        }

        // contradictionGroups ya devuelve primero los controles y luego las reglas.
        $out = [];
        foreach (self::contradictionGroups($candidate, $p, self::peers($candidate, $live, $v), self::controls($v)) as $g) {
            foreach ($g['with'] as $con) {
                $out[] = ['kind' => $g['kind'], 'with' => $con, 'target' => $g['entry']['var'], 'label' => self::plainLabel($g['entry'], $v)];
            }
        }

        return $out;
    }

    /**
     * Los hallazgos de las reglas sobre un contenido (borrador o versión de
     * formato 2), que se AÑADEN al final de los de F, en el orden del contrato:
     * `regla-incompatible-<code>`, `reglas-contradictorias`, `regla-<code>`,
     * `reglas` y `reglas-sin-aplicacion`. Solo cuentan las reglas del Studio no
     * archivadas; las del código ya se comprueban con las restricciones
     * `config-*` del catálogo. Incumplir una regla del Studio es un AVISO
     * (decisión 1 del lead): son propuestas que el motor no aplica.
     *
     * Las reglas se recorren en el orden de `$rules` (las vivas y las copiadas
     * llegan por código). Una versión de formato 1 (F) se valida como en F: sin
     * hallazgos de reglas.
     *
     * @param  list<array<string,mixed>>  $rules  las vivas (borrador) o las copiadas (versión)
     * @param  array<string,mixed>  $definition  `{layers, edgeIds}`
     * @param  array<array-key,mixed>|null  $configs
     * @param  array<string,mixed>  $catalog
     * @param  int  $format  el del contenido ({@see StudioContentHasher::format()}); un borrador es del último
     * @return list<array{id:string,level:string,title:string,detail:string,layer?:string}>
     *
     * @throws InvalidArgumentException con un formato desconocido o una definición sin forma
     */
    public static function evaluate(array $rules, array $definition, ?array $configs, array $catalog, int $format = StudioContentHasher::LATEST_FORMAT): array
    {
        StudioContentHasher::versionRuleFields($format);
        if ($format === StudioContentHasher::FORMAT_1) {
            return [];
        }

        $v = self::view($catalog);
        $aristas = self::edgeIds($definition);
        $configs ??= [];

        $studio = array_values(array_filter($rules, static fn (mixed $r): bool => is_array($r) && self::isLiveStudio($r)));
        if ($studio === []) {
            return [];
        }

        $controles = self::controls($v);
        $analizadas = array_map(static fn (array $r): array => ['rule' => $r, 'code' => (string) ($r['code'] ?? ''), 'parsed' => self::parse($r['conditions'] ?? null, $v)], $studio);
        $posicion = [];
        foreach ($analizadas as $i => $a) {
            $posicion[$a['code']] ??= $i;
        }

        $incompatibles = [];
        $pares = [];
        $porCodigo = [];
        foreach ($analizadas as $i => $a) {
            $otras = [];
            foreach ($analizadas as $b) {
                if ($b['code'] !== $a['code']) {
                    $otras[] = ['code' => $b['code'], 'parsed' => $b['parsed']];
                }
            }
            $grupos = self::issueGroups($a['rule'], $a['parsed'], $otras, $controles, $v);
            $porCodigo[$a['code']] = $grupos;

            $citas = [];
            foreach ($grupos[self::ISSUE_INCOMPATIBLE] as $g) {
                $cita = $g['item'];
                if (! in_array($cita, $citas, true)) {
                    $citas[] = $cita;
                }
            }
            if ($citas !== []) {
                $incompatibles[] = ['id' => "regla-incompatible-{$a['code']}", 'level' => 'blocking', 'title' => "La regla {$a['code']} no encaja con el catálogo", 'detail' => "Cita lo que el catálogo {$v['version']} no tiene: ".implode(', ', $citas).'.'];
            }

            foreach ([self::ISSUE_CONTRADICTS_CONTROL, self::ISSUE_CONTRADICTS_RULE] as $id) {
                foreach ($grupos[$id] as $g) {
                    foreach ($g['with'] as $con) {
                        // Entre dos reglas del Studio el par sale una vez, desde la primera.
                        if ($id === self::ISSUE_CONTRADICTS_RULE && ($posicion[$con] ?? PHP_INT_MAX) < $i) {
                            continue;
                        }
                        $pares[] = "{$a['code']} ↔ ".self::counterpart($con, $controles, $v).' ('.self::etiqueta($g['entry'], $v).')';
                    }
                }
            }
        }

        $out = $incompatibles;
        if ($pares !== []) {
            $out[] = ['id' => 'reglas-contradictorias', 'level' => 'blocking', 'title' => 'Reglas que se contradicen', 'detail' => implode(' · ', $pares)];
        }

        // Una regla incompatible no se evalúa; una contradictoria, sí.
        $incumplidas = [];
        $comprobadas = [];
        foreach ($analizadas as $a) {
            if ($porCodigo[$a['code']][self::ISSUE_INCOMPATIBLE] !== [] || ! $a['parsed']['present']) {
                continue;
            }
            $fallos = self::compliance($a['parsed'], $aristas, $configs, $v);
            if ($fallos === null) {
                continue;
            }
            if ($fallos === []) {
                $comprobadas[] = $a['code'];

                continue;
            }
            $partes = array_map(static fn (array $f): string => self::etiqueta($f['entry'], $v).': '.$f['value'], $fallos);
            $incumplidas[] = [
                'id' => "regla-{$a['code']}",
                'level' => 'warning',
                'title' => "{$a['code']} no se cumple",
                'detail' => (string) ($a['rule']['description'] ?? '').' ('.implode('; ', $partes).')',
                'layer' => self::entryLayer($fallos[0]['entry'], $v),
            ];
        }
        $out = [...$out, ...$incumplidas];

        if ($incumplidas === [] && $incompatibles === [] && $pares === [] && $comprobadas !== []) {
            $out[] = ['id' => 'reglas', 'level' => 'ok', 'title' => 'Las reglas del Studio se cumplen', 'detail' => 'Comprobadas: '.implode(', ', $comprobadas).'.'];
        }

        $codigos = array_map(static fn (array $a): string => $a['code'], $analizadas);
        $out[] = ['id' => 'reglas-sin-aplicacion', 'level' => 'warning', 'title' => 'Reglas que el motor no aplica', 'detail' => implode(', ', $codigos).': son propuestas del Studio; aprobar no las hace cumplir.'];

        return $out;
    }

    // ── Lectura de las condiciones ──────────────────────────────────────────

    /**
     * Lee unas condiciones contra el catálogo sin los controles ni las capas.
     *
     * `complete`: todos los elementos se resolvieron (sin errores de forma, de
     * catálogo ni repeticiones). `whenComplete`: lo mismo, solo del «si» (con
     * él se pueden buscar contradicciones de las afirmaciones que sí se
     * leyeron). `flags`: lo que el análisis necesita saber (incompatibilidades,
     * imposibles, autocontradicciones, lo que nunca se aplica), con su objetivo.
     *
     * @param  array<string,mixed>  $v
     * @return array{present:bool,complete:bool,whenComplete:bool,errors:array<string,list<string>>,when:list<array<string,mixed>>,then:list<array<string,mixed>>,canonical:array<string,mixed>|null,flags:list<array<string,mixed>>}
     */
    private static function parse(mixed $conditions, array $v): array
    {
        $r = ['present' => $conditions !== null, 'complete' => true, 'whenComplete' => true, 'errors' => [], 'when' => [], 'then' => [], 'canonical' => null, 'flags' => []];
        if ($conditions === null) {
            return $r;
        }

        $forma = static function (array &$r, string $clave, string $mensaje): void {
            self::add($r['errors'], $clave, $mensaje);
            $r['complete'] = false;
            if ($clave !== 'conditions.then') {
                $r['whenComplete'] = false;
            }
            $r['flags'][] = ['type' => 'incompatible', 'target' => $clave, 'item' => $clave, 'entry' => null];
        };

        if (! is_array($conditions)) {
            $forma($r, 'conditions', 'Las condiciones tienen que ser un objeto con «when» y «then».');

            return $r;
        }
        $json = json_encode($conditions, self::JSON_FLAGS);
        if ($json === false) {
            $forma($r, 'conditions', 'Las condiciones no se pueden leer.');

            return $r;
        }
        if (strlen($json) > self::MAX_BYTES) {
            $forma($r, 'conditions', 'Las condiciones superan el tamaño máximo (4 KB).');

            return $r;
        }
        if ($conditions === [] || array_is_list($conditions)) {
            $forma($r, 'conditions', 'Las condiciones tienen que ser un objeto con «when» y «then».');

            return $r;
        }

        // Una clave de más se señala en el objeto; una que falta, en su propia clave.
        if (array_diff(array_map('strval', array_keys($conditions)), ['when', 'then']) !== []) {
            $forma($r, 'conditions', 'Las condiciones solo llevan «when» y «then».');
        }

        $listas = [];
        foreach (['when' => [0, self::MAX_WHEN, 'El «si» tiene que ser una lista de 0 a '.self::MAX_WHEN.' condiciones.'],
            'then' => [1, self::MAX_THEN, 'El «entonces» tiene que ser una lista de 1 a '.self::MAX_THEN.' afirmaciones.']] as $lado => [$min, $max, $mensaje]) {
            if (! array_key_exists($lado, $conditions)) {
                // Obligatoria aunque esté vacía.
                $forma($r, "conditions.{$lado}", $mensaje);
                $listas[$lado] = [];

                continue;
            }
            $lista = $conditions[$lado];
            if (! is_array($lista) || ! array_is_list($lista)) {
                $forma($r, "conditions.{$lado}", $mensaje);
                $listas[$lado] = [];

                continue;
            }
            if (count($lista) < $min || count($lista) > $max) {
                $forma($r, "conditions.{$lado}", $mensaje);
            }
            $listas[$lado] = $lista;
        }

        $canonico = ['when' => [], 'then' => []];
        foreach (['when' => false, 'then' => true] as $lado => $esThen) {
            $vistas = [];
            foreach ($listas[$lado] as $i => $el) {
                $ruta = "conditions.{$lado}.{$i}";
                $e = self::element($el, $ruta, $esThen, $v);
                foreach ($e['errors'] as $clave => $mensajes) {
                    foreach ($mensajes as $mensaje) {
                        self::add($r['errors'], $clave, $mensaje);
                    }
                }
                if ($e['entries'] === null) {
                    $r['complete'] = false;
                    if (! $esThen) {
                        $r['whenComplete'] = false;
                    }
                    $r['flags'][] = ['type' => 'incompatible', 'target' => $e['target'] ?? $ruta, 'item' => $e['item'] ?? $ruta, 'entry' => null];

                    continue;
                }

                $repetida = false;
                foreach ($e['entries'] as $entrada) {
                    if (isset($vistas[$entrada['var']])) {
                        $repetida = true;
                    }
                }
                if ($repetida) {
                    self::add($r['errors'], $ruta, $esThen ? 'Repite una variable de otra afirmación del «entonces».' : 'Repite una variable de otra condición del «si».');
                    $r['complete'] = false;
                    if (! $esThen) {
                        $r['whenComplete'] = false;
                    }
                    $r['flags'][] = ['type' => 'incompatible', 'target' => $e['entries'][0]['var'], 'item' => self::item($e['entries'][0]), 'entry' => $e['entries'][0]];

                    continue;
                }
                foreach ($e['entries'] as $entrada) {
                    $vistas[$entrada['var']] = true;
                    $r[$lado][] = $entrada + ['path' => $ruta];
                }
                $canonico[$lado][] = $e['canonical'];
            }
        }

        // Una condición del «si» que ningún valor cumple: la regla nunca se aplicaría.
        foreach ($r['when'] as $w) {
            if ($w['set'] === []) {
                self::add($r['errors'], $w['path'], self::isMandatoryEdge($w)
                    ? 'Nunca se aplicaría: «'.self::plainLabel($w, $v).'» es obligatoria en el motor.'
                    : 'Nunca se aplicaría: ningún valor cumple esta condición.');
                $r['flags'][] = ['type' => 'never', 'target' => $w['var'], 'item' => self::item($w), 'entry' => $w];
            }
        }

        $cuando = [];
        foreach ($r['when'] as $w) {
            $cuando[$w['var']] = $w;
        }
        foreach ($r['then'] as $t) {
            if ($t['set'] === []) {
                // Imposible. Si es por una arista obligatoria, es el control del motor.
                if (self::isMandatoryEdge($t)) {
                    self::add($r['errors'], $t['path'], '«'.self::plainLabel($t, $v).'» es obligatoria en el motor: no se puede exigir que falte.');
                    $r['flags'][] = ['type' => 'mandatory', 'target' => $t['var'], 'item' => self::item($t), 'entry' => $t];
                } else {
                    self::add($r['errors'], $t['path'], 'Nunca se puede cumplir: no queda ningún valor posible.');
                    $r['flags'][] = ['type' => 'incompatible', 'target' => $t['var'], 'item' => self::item($t), 'entry' => $t];
                }

                continue;
            }
            $w = $cuando[$t['var']] ?? null;
            if ($w !== null && $w['set'] !== [] && self::intersect($t['set'], $w['set']) === []) {
                self::add($r['errors'], $t['path'], 'Se contradice con su propio «si».');
                $r['flags'][] = ['type' => 'incompatible', 'target' => $t['var'], 'item' => self::item($t), 'entry' => $t];
            }
        }

        if ($r['errors'] === []) {
            $r['canonical'] = $canonico;
        }

        return $r;
    }

    /**
     * Un predicado del «si» o una afirmación del «entonces». Devuelve sus
     * entradas resueltas (una por variable; el texto, una por frase), su forma
     * canónica y los errores por clave. Si falla, `target` e `item` dicen, si
     * se puede saber, sobre qué era.
     *
     * @param  array<string,mixed>  $v
     * @return array{entries:list<array<string,mixed>>|null,canonical:array<string,mixed>|null,errors:array<string,list<string>>,target:string|null,item:string|null}
     */
    private static function element(mixed $el, string $ruta, bool $esThen, array $v): array
    {
        $out = ['entries' => null, 'canonical' => null, 'errors' => [], 'target' => null, 'item' => null];

        if (! is_array($el) || $el === [] || array_is_list($el)) {
            $out['errors'][$ruta][] = 'Cada condición tiene que ser un objeto.';

            return $out;
        }

        $objetivo = $el['target'] ?? null;
        if (! is_string($objetivo) || ! isset(self::OPS[$objetivo])) {
            $out['errors']["{$ruta}.target"][] = 'El objetivo tiene que ser config, edge o text.';

            return $out;
        }
        if ($objetivo === 'text' && ! $esThen) {
            $out['errors']["{$ruta}.target"][] = 'Las frases solo pueden ir en «entonces».';

            return $out;
        }

        if ($objetivo === 'config' && is_string($el['layer'] ?? null) && is_string($el['key'] ?? null)) {
            $out['target'] = "config:{$el['layer']}.{$el['key']}";
            $out['item'] = "{$el['layer']}.{$el['key']}";
        } elseif ($objetivo === 'edge' && is_string($el['edge'] ?? null)) {
            $out['target'] = "edge:{$el['edge']}";
            $out['item'] = $el['edge'];
        }

        $kind = null;
        $kindBien = true;
        if ($esThen) {
            $kind = $el['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
                $out['errors']["{$ruta}.kind"][] = 'La afirmación tiene que ser require, forbid o limit.';
                $kindBien = false;
            } elseif ($kind === 'limit' && $objetivo !== 'config') {
                $out['errors']["{$ruta}.kind"][] = '«limit» solo vale con lte o gte sobre un parámetro de rango.';
                $kindBien = false;
            }
        }

        $op = $el['op'] ?? null;
        $opBien = is_string($op) && in_array($op, self::OPS[$objetivo], true);
        if (! $opBien) {
            $out['errors']["{$ruta}.op"][] = 'Operador no admitido para este objetivo.';
        }

        // Claves exactas. Una que sobra se señala en el objeto, sin su nombre;
        // una que falta, en su propia clave (la valida lo que viene después).
        // Sin un `op` válido, la combinación es cualquiera de las del objetivo.
        $admitidas = $opBien ? self::expectedKeys($objetivo, $op, $esThen) : self::expectedKeys($objetivo, null, $esThen);
        if (array_diff(array_map('strval', array_keys($el)), $admitidas) !== []) {
            $out['errors'][$ruta][] = 'Tiene claves que esta combinación no admite.';
        }

        $resuelto = match ($objetivo) {
            'config' => self::configElement($el, $ruta, $op, $opBien, $kind, $out['errors'], $v),
            'edge' => self::edgeElement($el, $ruta, $op, $opBien, $out['errors'], $v),
            default => self::textElement($el, $ruta, $out['errors']),
        };

        if ($resuelto === null || ! $kindBien || ! $opBien || $out['errors'] !== []) {
            return $out;
        }

        [$entradas, $canonico] = $resuelto;
        if ($esThen) {
            foreach ($entradas as &$entrada) {
                if ($kind === 'forbid') {
                    $entrada['set'] = self::minus($entrada['domain'], $entrada['set']);
                }
            }
            unset($entrada);
            $canonico = ['kind' => $kind] + $canonico;
        }

        $out['entries'] = $entradas;
        $out['canonical'] = $canonico;

        return $out;
    }

    /**
     * @param  array<string,mixed>  $el
     * @param  array<string,list<string>>  $errores
     * @param  array<string,mixed>  $v
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}|null
     */
    private static function configElement(array $el, string $ruta, mixed $op, bool $opBien, mixed $kind, array &$errores, array $v): ?array
    {
        $capa = $el['layer'] ?? null;
        $clave = $el['key'] ?? null;
        if (! is_string($capa) || ! isset($v['params'][$capa])) {
            $errores["{$ruta}.layer"][] = 'Esa capa no existe en el catálogo.';

            return null;
        }
        if (! is_string($clave) || ! isset($v['params'][$capa][$clave])) {
            $errores["{$ruta}.key"][] = 'Ese parámetro no existe en esa capa del catálogo.';

            return null;
        }
        $param = $v['params'][$capa][$clave];
        $tipo = $param['type'] ?? null;
        $dominio = is_string($tipo) && isset(self::TYPE_OPS[$tipo]) ? self::domain($param) : null;
        if ($dominio === null) {
            $errores["{$ruta}.key"][] = 'Ese parámetro no admite condiciones.';

            return null;
        }
        if (! $opBien) {
            return null;
        }
        if (! in_array($op, self::TYPE_OPS[$tipo], true)) {
            $errores["{$ruta}.op"][] = 'Ese operador no vale para este tipo de parámetro.';

            return null;
        }
        if ($kind === 'limit' && ! ($tipo === 'range' && ($op === 'lte' || $op === 'gte'))) {
            $errores["{$ruta}.kind"][] = '«limit» solo vale con lte o gte sobre un parámetro de rango.';
        }

        $base = ['target' => 'config', 'layer' => $capa, 'key' => $clave, 'op' => $op];
        $entrada = ['var' => "config:{$capa}.{$clave}", 'type' => $tipo, 'layer' => $capa, 'key' => $clave, 'edge' => null, 'tag' => null, 'phrase' => null, 'domain' => $dominio];

        if ($op === 'in' || $op === 'not_in') {
            $valores = $el['values'] ?? null;
            if (! is_array($valores) || ! array_is_list($valores) || $valores === []) {
                $errores["{$ruta}.values"][] = 'Tiene que ser una lista de 1 a '.count($dominio).' opciones.';

                return null;
            }
            $elegidas = [];
            $bien = true;
            foreach ($valores as $i => $valor) {
                if (! is_string($valor) || ! in_array($valor, $dominio, true)) {
                    $errores["{$ruta}.values.{$i}"][] = 'No es una de las opciones permitidas.';
                    $bien = false;

                    continue;
                }
                $elegidas[$valor] = true;
            }
            if (! $bien) {
                return null;
            }
            // En el orden de las opciones del catálogo y sin repetidos.
            $conjunto = array_values(array_filter($dominio, static fn (mixed $o): bool => isset($elegidas[$o])));

            return [[$entrada + ['set' => $op === 'in' ? $conjunto : self::minus($dominio, $conjunto)]], $base + ['values' => $conjunto]];
        }

        $valor = $el['value'] ?? null;
        switch ($tipo) {
            case 'toggle':
                if (! is_bool($valor)) {
                    $errores["{$ruta}.value"][] = 'Tiene que ser verdadero o falso.';

                    return null;
                }

                return [[$entrada + ['set' => [$valor]]], $base + ['value' => $valor]];

            case 'select':
                if (! is_string($valor) || ! in_array($valor, $dominio, true)) {
                    $errores["{$ruta}.value"][] = 'No es una de las opciones permitidas.';

                    return null;
                }

                return [[$entrada + ['set' => [$valor]]], $base + ['value' => $valor]];

            case 'range':
                $min = $param['min'];
                $max = $param['max'];
                $paso = $param['step'] ?? 1;
                if ($op === 'eq') {
                    if (! is_int($valor) || $valor < $min || $valor > $max || ($valor - $min) % $paso !== 0) {
                        $errores["{$ruta}.value"][] = "Tiene que ser un número entero de la rejilla: de {$min} a {$max}, de {$paso} en {$paso}.";

                        return null;
                    }
                    $conjunto = [$valor];
                } else {
                    if (! is_int($valor) || $valor < $min || $valor > $max) {
                        $errores["{$ruta}.value"][] = "Tiene que ser un número entero entre {$min} y {$max}.";

                        return null;
                    }
                    $conjunto = array_values(array_filter($dominio, static fn (int $x): bool => $op === 'lte' ? $x <= $valor : $x >= $valor));
                }

                return [[$entrada + ['set' => $conjunto]], $base + ['value' => $valor]];

            default: // tags
                $etiqueta = self::tag($valor);
                if ($etiqueta === null) {
                    $errores["{$ruta}.value"][] = 'Tiene que ser un texto plano de 1 a '.self::MAX_TAG_LENGTH.' caracteres.';

                    return null;
                }
                $entrada = ['var' => "config:{$capa}.{$clave}#{$etiqueta}", 'tag' => $etiqueta, 'domain' => [true, false]] + $entrada;

                return [[$entrada + ['set' => [$op === 'contains']]], $base + ['value' => $etiqueta]];
        }
    }

    /**
     * @param  array<string,mixed>  $el
     * @param  array<string,list<string>>  $errores
     * @param  array<string,mixed>  $v
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}|null
     */
    private static function edgeElement(array $el, string $ruta, mixed $op, bool $opBien, array &$errores, array $v): ?array
    {
        $id = $el['edge'] ?? null;
        if (! is_string($id) || ! isset($v['edges'][$id])) {
            $errores["{$ruta}.edge"][] = 'Esa conexión no existe en el motor.';

            return null;
        }
        if (! $opBien) {
            return null;
        }

        $obligatoria = ($v['edges'][$id]['mandatory'] ?? false) === true;
        $dominio = $obligatoria ? ['present'] : ['present', 'absent'];
        $entrada = ['var' => "edge:{$id}", 'type' => 'edge', 'layer' => null, 'key' => null, 'edge' => $id, 'tag' => null, 'phrase' => null, 'domain' => $dominio, 'mandatory' => $obligatoria];

        return [[$entrada + ['set' => self::intersect($dominio, [$op === 'present' ? 'present' : 'absent'])]], ['target' => 'edge', 'edge' => $id, 'op' => $op]];
    }

    /**
     * @param  array<string,mixed>  $el
     * @param  array<string,list<string>>  $errores
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}|null
     */
    private static function textElement(array $el, string $ruta, array &$errores): ?array
    {
        $frases = $el['phrases'] ?? null;
        $mensaje = 'Tiene que ser una lista de 1 a '.self::MAX_PHRASES.' frases.';
        if (! is_array($frases) || ! array_is_list($frases) || $frases === []) {
            $errores["{$ruta}.phrases"][] = $mensaje;

            return null;
        }

        $limpias = [];
        $bien = true;
        foreach ($frases as $i => $frase) {
            $limpia = self::phrase($frase);
            if ($limpia === null) {
                $errores["{$ruta}.phrases.{$i}"][] = 'Cada frase es un texto plano de una línea, de '.self::MIN_PHRASE_LENGTH.' a '.self::MAX_PHRASE_LENGTH.' caracteres.';
                $bien = false;

                continue;
            }
            // Las repetidas se descartan: se conserva la primera.
            if (! in_array($limpia, $limpias, true)) {
                $limpias[] = $limpia;
            }
        }
        if (! $bien) {
            return null;
        }
        if (count($limpias) > self::MAX_PHRASES) {
            $errores["{$ruta}.phrases"][] = $mensaje;

            return null;
        }

        $entradas = array_map(static fn (string $f): array => [
            'var' => "text#{$f}", 'type' => 'text', 'layer' => null, 'key' => null, 'edge' => null, 'tag' => null, 'phrase' => $f,
            'domain' => [true, false], 'set' => [true],
        ], $limpias);

        return [$entradas, ['target' => 'text', 'op' => 'contains', 'phrases' => $limpias]];
    }

    /**
     * Las claves que admite una combinación. Con `$op` null (un operador que
     * no vale), las de cualquier combinación del objetivo.
     *
     * @return list<string>
     */
    private static function expectedKeys(string $objetivo, ?string $op, bool $esThen): array
    {
        $llaves = match ($objetivo) {
            'config' => ['target', 'layer', 'key', 'op', ...match ($op) {
                null => ['value', 'values'],
                'in', 'not_in' => ['values'],
                default => ['value'],
            }],
            'edge' => ['target', 'edge', 'op'],
            default => ['target', 'op', 'phrases'],
        };
        if ($esThen) {
            $llaves[] = 'kind';
        }

        return $llaves;
    }

    /**
     * El dominio de un parámetro, en orden; null si el catálogo no lo deja
     * enumerar.
     *
     * @param  array<string,mixed>  $param
     * @return list<bool|int|string>|null
     */
    private static function domain(array $param): ?array
    {
        switch ($param['type']) {
            case 'toggle':
            case 'tags':
                return [true, false];

            case 'select':
                $opciones = [];
                foreach ($param['options'] ?? [] as $o) {
                    if (is_array($o) && is_string($o['value'] ?? null) && ! in_array($o['value'], $opciones, true)) {
                        $opciones[] = $o['value'];
                    }
                }

                return $opciones !== [] ? $opciones : null;

            case 'range':
                $min = $param['min'] ?? null;
                $max = $param['max'] ?? null;
                $paso = $param['step'] ?? 1;
                if (! is_int($min) || ! is_int($max) || ! is_int($paso) || $paso <= 0 || $max < $min || intdiv($max - $min, $paso) > self::MAX_GRID) {
                    return null;
                }

                return range($min, $min + intdiv($max - $min, $paso) * $paso, $paso);
        }

        return null;
    }

    /** Una etiqueta de `tags`: recortada como en el borrador; null si no vale. */
    private static function tag(mixed $valor): ?string
    {
        if (! is_string($valor) || ! mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        $limpia = self::trim($valor);
        if ($limpia === '' || ! UntrustedText::isPlain($limpia) || mb_strlen($limpia, 'UTF-8') > self::MAX_TAG_LENGTH) {
            return null;
        }

        return $limpia;
    }

    /**
     * Una frase: recortada, texto plano de una línea, con los espacios
     * internos colapsados a uno y en minúsculas (solo A–Z y ÁÉÍÓÚÜÑ); null si
     * no vale.
     */
    private static function phrase(mixed $valor): ?string
    {
        if (! is_string($valor) || ! mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        $limpia = self::trim($valor);
        if (! UntrustedText::isPlain($limpia)) {
            return null;
        }
        $limpia = strtr((string) preg_replace('/['.self::JS_TRIM.']+/u', ' ', $limpia), self::LOWER);
        $largo = mb_strlen($limpia, 'UTF-8');

        return $largo >= self::MIN_PHRASE_LENGTH && $largo <= self::MAX_PHRASE_LENGTH ? $limpia : null;
    }

    private static function trim(string $texto): string
    {
        return (string) preg_replace('/^['.self::JS_TRIM.']+|['.self::JS_TRIM.']+$/u', '', $texto);
    }

    // ── Controles, contradicciones y análisis ───────────────────────────────

    /**
     * El orden de los controles: las restricciones `blocking` en el orden del
     * catálogo, las aristas obligatorias en el orden del catálogo y las reglas
     * del código por código.
     *
     * @param  list<array<string,mixed>>  $controles
     * @param  array<string,mixed>  $v
     * @return array<string,int>
     */
    private static function controlOrder(array $controles, array $v): array
    {
        $fichas = [];
        foreach ($controles as $c) {
            if ($c['kind'] === 'constraint') {
                $fichas[] = $c['with'];
            }
        }
        foreach ($v['edges'] as $id => $e) {
            if (($e['mandatory'] ?? false) === true) {
                $fichas[] = 'edge:'.$id;
            }
        }
        foreach ($controles as $c) {
            if ($c['kind'] === 'legacy') {
                $fichas[] = $c['with'];
            }
        }

        return array_flip(array_values(array_unique($fichas)));
    }

    /**
     * Los controles del motor como reglas ya leídas: las restricciones
     * `blocking` del catálogo, en su orden, y luego las reglas del código, por
     * código. Un control que el catálogo no deja leer se omite (StudioCatalogTest
     * exige que todos se lean).
     *
     * @param  array<string,mixed>  $v
     * @return list<array{with:string,kind:string,title:string,code:string|null,conditions:array<string,mixed>,rule:array<string,mixed>}>
     */
    private static function controls(array $v): array
    {
        $out = [];
        foreach ($v['constraints'] as $c) {
            if (($c['level'] ?? null) !== 'blocking' || ! is_string($c['layer'] ?? null) || ! is_string($c['key'] ?? null)) {
                continue;
            }
            $objetivo = ['target' => 'config', 'layer' => $c['layer'], 'key' => $c['key'], 'op' => 'eq'];
            if (array_key_exists('forbidden', $c)) {
                $condiciones = ['when' => [], 'then' => [['kind' => 'forbid'] + $objetivo + ['value' => $c['forbidden']]]];
            } elseif (array_key_exists('when', $c)) {
                $entonces = [];
                foreach ($c['requires'] ?? [] as $arista) {
                    $entonces[] = ['kind' => 'require', 'target' => 'edge', 'edge' => $arista, 'op' => 'present'];
                }
                foreach ($c['forbids'] ?? [] as $arista) {
                    $entonces[] = ['kind' => 'forbid', 'target' => 'edge', 'edge' => $arista, 'op' => 'present'];
                }
                $condiciones = ['when' => [$objetivo + ['value' => $c['when']]], 'then' => $entonces];
            } else {
                continue;
            }

            $leida = self::parseControl($condiciones, $v);
            if ($leida !== null) {
                $out[] = ['with' => 'control:'.$c['id'], 'kind' => 'constraint', 'title' => (string) ($c['title'] ?? $c['id']), 'code' => null, 'conditions' => $condiciones, 'rule' => $leida];
            }
        }

        $codigos = array_map('strval', array_keys($v['legacy']));
        sort($codigos, SORT_STRING);
        foreach ($codigos as $codigo) {
            $leida = self::parseControl($v['legacy'][$codigo], $v);
            if ($leida !== null) {
                $out[] = ['with' => $codigo, 'kind' => 'legacy', 'title' => $codigo, 'code' => $codigo, 'conditions' => $v['legacy'][$codigo], 'rule' => $leida];
            }
        }

        return $out;
    }

    /**
     * Un control leído sin el tope de tamaño ni el de afirmaciones (una
     * restricción con muchas aristas sigue siendo un control).
     *
     * @param  array<string,mixed>  $condiciones
     * @param  array<string,mixed>  $v
     * @return array<string,mixed>|null
     */
    private static function parseControl(array $condiciones, array $v): ?array
    {
        $r = ['present' => true, 'complete' => true, 'when' => [], 'then' => []];
        foreach (['when' => false, 'then' => true] as $lado => $esThen) {
            foreach ($condiciones[$lado] ?? [] as $i => $el) {
                $e = self::element($el, "control.{$lado}.{$i}", $esThen, $v);
                if ($e['entries'] === null) {
                    return null;
                }
                foreach ($e['entries'] as $entrada) {
                    $r[$lado][] = $entrada + ['path' => "control.{$lado}.{$i}"];
                }
            }
        }

        return $r;
    }

    /**
     * Las afirmaciones de X que chocan con alguna de Y (en el orden de X).
     *
     * @param  array<string,mixed>  $x
     * @param  array<string,mixed>  $y
     * @return list<array<string,mixed>>
     */
    private static function clash(array $x, array $y): array
    {
        if (self::neverApplies($x) || self::neverApplies($y)) {
            return [];
        }

        $cuandoY = [];
        foreach ($y['when'] as $w) {
            $cuandoY[$w['var']] = $w['set'];
        }
        foreach ($x['when'] as $w) {
            if (isset($cuandoY[$w['var']]) && self::intersect($w['set'], $cuandoY[$w['var']]) === []) {
                return [];
            }
        }

        $entoncesY = [];
        foreach ($y['then'] as $t) {
            $entoncesY[$t['var']] = $t['set'];
        }

        $out = [];
        foreach ($x['then'] as $t) {
            $otro = $entoncesY[$t['var']] ?? null;
            if ($otro !== null && $t['set'] !== [] && $otro !== [] && self::intersect($t['set'], $otro) === []) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @param  array<string,mixed>  $p */
    private static function neverApplies(array $p): bool
    {
        foreach ($p['when'] as $w) {
            if ($w['set'] === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las contradicciones de una regla del Studio ya leída, agrupadas por
     * objetivo: con los controles (las aristas obligatorias incluidas) y con
     * las reglas del Studio de `$peers`. Una regla del código no recibe
     * contradicciones: sus condiciones SON controles, y la regla del Studio que
     * las contradice es la que lleva el problema.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $p
     * @param  list<array{code:string,parsed:array<string,mixed>}>  $peers
     * @param  list<array<string,mixed>>  $controles
     * @return list<array{kind:string,entry:array<string,mixed>,with:list<string>}>
     */
    private static function contradictionGroups(array $rule, array $p, array $peers, array $controles): array
    {
        if (! self::isStudio($rule)) {
            return [];
        }

        $porControl = [];
        foreach ($controles as $control) {
            foreach (self::clash($p, $control['rule']) as $t) {
                $porControl[$t['var']][] = $control['with'];
            }
        }
        $porRegla = [];
        foreach ($peers as $par) {
            if (! $par['parsed']['complete'] || ! $par['parsed']['present']) {
                continue;
            }
            foreach (self::clash($p, $par['parsed']) as $t) {
                $porRegla[$t['var']][] = $par['code'];
            }
        }

        $controlesG = [];
        $reglasG = [];
        $vistas = [];
        foreach ($p['then'] as $t) {
            if (isset($vistas[$t['var']])) {
                continue;
            }
            $vistas[$t['var']] = true;
            if ($t['set'] === [] && self::isMandatoryEdge($t)) {
                $controlesG[] = ['kind' => 'control', 'entry' => $t, 'with' => ['edge:'.$t['edge']]];
            } elseif (isset($porControl[$t['var']])) {
                $controlesG[] = ['kind' => 'control', 'entry' => $t, 'with' => array_values(array_unique($porControl[$t['var']]))];
            }
            if (isset($porRegla[$t['var']])) {
                $reglasG[] = ['kind' => 'rule', 'entry' => $t, 'with' => array_values(array_unique($porRegla[$t['var']]))];
            }
        }

        return [...$controlesG, ...$reglasG];
    }

    /**
     * Los problemas de una regla por id, en grupos por objetivo.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $p
     * @param  list<array{code:string,parsed:array<string,mixed>}>  $peers
     * @param  list<array<string,mixed>>  $controles
     * @param  array<string,mixed>  $v
     * @return array<string,list<array{target:string,item:string,entry:array<string,mixed>|null,with:list<string>}>>
     */
    private static function issueGroups(array $rule, array $p, array $peers, array $controles, array $v): array
    {
        $grupos = array_fill_keys(array_keys(self::ISSUE_LEVELS), []);
        $poner = static function (array &$grupos, string $id, string $target, string $item, ?array $entry, array $with) {
            foreach ($grupos[$id] as &$g) {
                if ($g['target'] === $target) {
                    foreach ($with as $w) {
                        if (! in_array($w, $g['with'], true)) {
                            $g['with'][] = $w;
                        }
                    }

                    return;
                }
            }
            unset($g);
            $grupos[$id][] = ['target' => $target, 'item' => $item, 'entry' => $entry, 'with' => $with];
        };

        foreach ($p['flags'] as $f) {
            if ($f['type'] === 'incompatible') {
                $poner($grupos, self::ISSUE_INCOMPATIBLE, $f['target'], $f['item'], $f['entry'], []);
            }
        }
        $refs = is_array($rule['refs'] ?? null) ? $rule['refs'] : [];
        foreach (is_array($refs['configs'] ?? null) ? $refs['configs'] : [] as $ref) {
            $capa = is_array($ref) && is_string($ref['layer'] ?? null) ? $ref['layer'] : '';
            $clave = is_array($ref) && is_string($ref['key'] ?? null) ? $ref['key'] : '';
            if (! isset($v['params'][$capa][$clave])) {
                $poner($grupos, self::ISSUE_INCOMPATIBLE, "config:{$capa}.{$clave}", "{$capa}.{$clave}", null, []);
            }
        }
        foreach (is_array($refs['edges'] ?? null) ? $refs['edges'] : [] as $arista) {
            if (! is_string($arista) || ! isset($v['edges'][$arista])) {
                $id = is_scalar($arista) ? (string) $arista : '';
                $poner($grupos, self::ISSUE_INCOMPATIBLE, "edge:{$id}", $id, null, []);
            }
        }

        // Una regla del código solo puede salir como incompatible: lo demás es el código.
        if ($p['present'] && $p['complete'] && self::isStudio($rule)) {
            foreach (self::contradictionGroups($rule, $p, $peers, $controles) as $g) {
                $id = $g['kind'] === 'control' ? self::ISSUE_CONTRADICTS_CONTROL : self::ISSUE_CONTRADICTS_RULE;
                $poner($grupos, $id, $g['entry']['var'], self::item($g['entry']), $g['entry'], $g['with']);
            }
            foreach ($p['flags'] as $f) {
                if ($f['type'] === 'never') {
                    $poner($grupos, self::ISSUE_INAPPLICABLE, $f['target'], $f['item'], $f['entry'], []);
                }
            }
            foreach ($p['then'] as $t) {
                if ($t['domain'] !== [] && $t['set'] === $t['domain']) {
                    $poner($grupos, self::ISSUE_EMPTY, $t['var'], self::item($t), $t, []);
                }
            }
        }

        return $grupos;
    }

    /**
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $p
     * @param  list<array{code:string,parsed:array<string,mixed>}>  $peers
     * @param  list<array<string,mixed>>  $controles
     * @param  array<string,mixed>  $v
     * @return array{verification:string,issues:list<array{id:string,level:string,with:list<string>,target:string}>}
     */
    private static function analysis(array $rule, array $p, array $peers, array $controles, array $v): array
    {
        $issues = [];
        foreach (self::issueGroups($rule, $p, $peers, $controles, $v) as $id => $grupos) {
            foreach ($grupos as $g) {
                $issues[] = ['id' => $id, 'level' => self::ISSUE_LEVELS[$id], 'with' => $g['with'], 'target' => $g['target']];
            }
        }

        return ['verification' => self::verification($rule['conditions'] ?? null), 'issues' => $issues];
    }

    /**
     * `studio` con alguna afirmación de configuración o de arista; `lab` si solo hay texto; `none` sin condiciones.
     *
     * Pública para el análisis de una regla archivada ({@see StudioRules}),
     * que es solo esto, sin problemas (correcciones del Hito G, M-1).
     */
    public static function verification(mixed $conditions): string
    {
        if ($conditions === null) {
            return 'none';
        }
        foreach (is_array($conditions) && is_array($conditions['then'] ?? null) ? $conditions['then'] : [] as $t) {
            if (is_array($t) && in_array($t['target'] ?? null, ['config', 'edge'], true)) {
                return 'studio';
            }
        }

        return 'lab';
    }

    /**
     * Las demás reglas del Studio vivas con las que se compara una del Studio
     * (sin ella misma), en el orden de `$rules`. Una regla del código no se
     * compara con las del Studio: es el código.
     *
     * @param  array<string,mixed>  $rule
     * @param  list<array<string,mixed>>  $rules
     * @param  array<string,mixed>  $v
     * @return list<array{code:string,parsed:array<string,mixed>}>
     */
    private static function peers(array $rule, array $rules, array $v): array
    {
        if (! self::isStudio($rule)) {
            return [];
        }
        $codigo = (string) ($rule['code'] ?? '');
        $out = [];
        foreach ($rules as $r) {
            if (is_array($r) && self::isLiveStudio($r) && (string) ($r['code'] ?? '') !== $codigo) {
                $out[] = ['code' => (string) $r['code'], 'parsed' => self::parse($r['conditions'] ?? null, $v)];
            }
        }

        return $out;
    }

    /**
     * Las afirmaciones evaluables que no se cumplen ([] = se cumple), o null si
     * no hay nada que comprobar: una variable de su «si» no está en el
     * contenido, el «si» no se da, o ninguna afirmación es evaluable (el texto
     * nunca lo es). Lo que da null ni avisa ni cuenta como «comprobada».
     *
     * @param  array<string,mixed>  $p
     * @param  list<string>  $aristas
     * @param  array<array-key,mixed>  $configs
     * @param  array<string,mixed>  $v
     * @return list<array{entry:array<string,mixed>,value:string}>|null
     */
    private static function compliance(array $p, array $aristas, array $configs, array $v): ?array
    {
        foreach ($p['when'] as $w) {
            $actual = self::current($w, $aristas, $configs);
            if ($actual === null || ! self::belongs($actual['value'], $w['set'])) {
                return null;
            }
        }

        $evaluables = [];
        foreach ($p['then'] as $t) {
            if ($t['type'] === 'text') {
                continue;
            }
            $actual = self::current($t, $aristas, $configs);
            if ($actual !== null) {
                $evaluables[] = [$t, $actual];
            }
        }
        if ($evaluables === []) {
            return null;
        }

        $fallos = [];
        foreach ($evaluables as [$t, $actual]) {
            if (! self::belongs($actual['value'], $t['set'])) {
                $fallos[] = ['entry' => $t, 'value' => self::formatCurrent($t, $actual['raw'], $v)];
            }
        }

        return $fallos;
    }

    /**
     * El valor de una variable en el contenido, o null si el contenido no la
     * tiene (`configs[capa][clave]` ausente, como `=== undefined`).
     *
     * @param  array<string,mixed>  $e
     * @param  list<string>  $aristas
     * @param  array<array-key,mixed>  $configs
     * @return array{value:mixed,raw:mixed}|null
     */
    private static function current(array $e, array $aristas, array $configs): ?array
    {
        if ($e['type'] === 'edge') {
            $presente = in_array($e['edge'], $aristas, true) ? 'present' : 'absent';

            return ['value' => $presente, 'raw' => $presente];
        }

        $capa = $configs[$e['layer']] ?? null;
        if (! is_array($capa) || ! array_key_exists($e['key'], $capa)) {
            return null;
        }
        $valor = $capa[$e['key']];
        if ($e['type'] === 'tags') {
            return ['value' => is_array($valor) && in_array($e['tag'], $valor, true), 'raw' => $valor];
        }

        return ['value' => self::asJsValue($valor), 'raw' => $valor];
    }

    /**
     * ¿El valor está en el conjunto? Igualdad estricta sobre el valor tal como
     * lo vería JavaScript (`320.0` es `320`).
     *
     * @param  list<bool|int|string>  $conjunto
     */
    private static function belongs(mixed $valor, array $conjunto): bool
    {
        return in_array($valor, $conjunto, true);
    }

    private static function asJsValue(mixed $valor): mixed
    {
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && abs($valor) <= 9007199254740991) {
            return (int) $valor;
        }

        return $valor;
    }

    // ── Textos ──────────────────────────────────────────────────────────────

    /**
     * El valor actual de un objetivo incumplido, como lo enseña el detalle de
     * `regla-<code>`: sí/no, la etiqueta de la opción, el entero, las
     * etiquetas unidas por «, » (o «(vacío)») y presente/ausente.
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function formatCurrent(array $e, mixed $raw, array $v): string
    {
        return match ($e['type']) {
            'edge' => $raw === 'present' ? 'presente' : 'ausente',
            'tags' => is_array($raw) && $raw !== [] ? implode(', ', array_map(self::scalarText(...), $raw)) : '(vacío)',
            default => self::formatValue($e, self::asJsValue($raw), $v),
        };
    }

    /**
     * Un valor de un parámetro en llano.
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function formatValue(array $e, mixed $valor, array $v): string
    {
        switch ($e['type']) {
            case 'toggle':
                if (is_bool($valor)) {
                    return $valor ? 'sí' : 'no';
                }
                break;

            case 'select':
                foreach ($v['params'][$e['layer']][$e['key']]['options'] ?? [] as $o) {
                    if (($o['value'] ?? null) === $valor) {
                        return (string) $o['label'];
                    }
                }
                break;

            case 'edge':
                return $valor === 'present' ? 'presente' : 'ausente';
        }

        return self::scalarText($valor);
    }

    /** Como `String(valor)` en JavaScript para lo que puede llegar en un valor. */
    private static function scalarText(mixed $valor): string
    {
        return match (true) {
            is_string($valor) => $valor,
            is_bool($valor) => $valor ? 'true' : 'false',
            $valor === null => 'null',
            is_int($valor), is_float($valor) => (string) self::asJsValue($valor),
            default => (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * La etiqueta de un objetivo en los hallazgos: la del parámetro, o
     * «<etiqueta de la arista>»; una frase, «<frase>».
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function etiqueta(array $e, array $v): string
    {
        return match ($e['type']) {
            'edge', 'text' => '«'.self::plainLabel($e, $v).'»',
            default => self::plainLabel($e, $v),
        };
    }

    /**
     * La etiqueta sin comillas: la del parámetro, la de la arista o la frase.
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function plainLabel(array $e, array $v): string
    {
        return match ($e['type']) {
            'edge' => (string) ($v['edges'][$e['edge']]['label'] ?? $e['edge']),
            'text' => (string) $e['phrase'],
            default => (string) ($v['params'][$e['layer']][$e['key']]['label'] ?? $e['key']),
        };
    }

    /**
     * Quién es la contraparte `<B>` en `reglas-contradictorias`: el código de
     * una regla, o `control «<título>»` (una arista obligatoria, por su
     * etiqueta).
     *
     * @param  list<array<string,mixed>>  $controles
     * @param  array<string,mixed>  $v
     */
    private static function counterpart(string $con, array $controles, array $v): string
    {
        if (str_starts_with($con, 'edge:')) {
            $id = substr($con, 5);

            return 'control «'.($v['edges'][$id]['label'] ?? $id).'»';
        }
        if (str_starts_with($con, 'control:')) {
            foreach ($controles as $c) {
                if ($c['with'] === $con) {
                    return 'control «'.$c['title'].'»';
                }
            }

            return 'control «'.substr($con, 8).'»';
        }

        return $con;
    }

    /**
     * El mensaje del 422 cuando una afirmación contradice un control.
     *
     * @param  array<string,mixed>  $control
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function controlMessage(array $control, array $e, array $v): string
    {
        if ($control['kind'] === 'constraint') {
            return 'Contradice un control del motor («'.$control['title'].'»).';
        }

        $exigido = null;
        foreach ($control['rule']['then'] as $t) {
            if ($t['var'] === $e['var']) {
                $exigido = $t['set'];
            }
        }
        $codigo = $control['code'];
        $etiqueta = self::plainLabel($e, $v);

        return match ($e['type']) {
            'text' => "Contradice {$codigo} (la aplica el código): la frase «{$etiqueta}» ".($exigido === [true] ? 'tiene que aparecer.' : 'no puede aparecer.'),
            'tags' => "Contradice {$codigo} (la aplica el código): «{$etiqueta}» ".($exigido === [true] ? 'tiene que incluir' : 'no puede incluir')." «{$e['tag']}».",
            default => "Contradice {$codigo} (la aplica el código): «{$etiqueta}» tiene que ser ".self::orList(array_map(static fn (mixed $x): string => '«'.self::formatValue($e, $x, $v).'»', $exigido ?? [])).'.',
        };
    }

    /** @param  list<string>  $partes  «a», «b» o «c» */
    private static function orList(array $partes): string
    {
        if (count($partes) <= 1) {
            return implode('', $partes);
        }
        $ultima = array_pop($partes);

        return implode(', ', $partes).' o '.$ultima;
    }

    /**
     * Lo que falta en las capas de la regla: la capa de cada objetivo de
     * configuración y, de cada arista, una de sus dos capas.
     *
     * @param  list<array<string,mixed>>  $entradas
     * @param  list<string>  $capas
     * @param  array<string,mixed>  $v
     * @return list<string>
     */
    private static function missingLayers(array $entradas, array $capas, array $v): array
    {
        $out = [];
        foreach ($entradas as $e) {
            if ($e['type'] === 'text') {
                continue;
            }
            if ($e['type'] === 'edge') {
                $arista = $v['edges'][$e['edge']];
                $extremos = array_values(array_filter([$v['portLayer'][$arista['source']] ?? null, $v['portLayer'][$arista['target']] ?? null]));
                if (array_intersect($extremos, $capas) === []) {
                    $nombres = array_map(static fn (string $l): string => $v['layerNames'][$l] ?? $l, $extremos);
                    $mensaje = 'Las capas de la regla tienen que incluir '.self::orList(array_map(static fn (string $n): string => "«{$n}»", $nombres)).', por «'.self::plainLabel($e, $v).'».';
                    if (! in_array($mensaje, $out, true)) {
                        $out[] = $mensaje;
                    }
                }

                continue;
            }
            if (! in_array($e['layer'], $capas, true)) {
                $mensaje = 'Las capas de la regla tienen que incluir «'.($v['layerNames'][$e['layer']] ?? $e['layer']).'», por «'.self::plainLabel($e, $v).'».';
                if (! in_array($mensaje, $out, true)) {
                    $out[] = $mensaje;
                }
            }
        }

        return $out;
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * Lo que se usa del catálogo, indexado una vez por llamada.
     *
     * @param  array<string,mixed>  $catalog
     * @return array{version:string,params:array<string,array<string,array<string,mixed>>>,edges:array<string,array<string,mixed>>,portLayer:array<string,string>,layerNames:array<string,string>,constraints:list<array<string,mixed>>,legacy:array<string,array<string,mixed>>}
     */
    private static function view(array $catalog): array
    {
        $params = [];
        foreach (is_array($catalog['parameters'] ?? null) ? $catalog['parameters'] : [] as $capa => $lista) {
            foreach (is_array($lista) ? $lista : [] as $p) {
                if (is_array($p) && is_string($p['key'] ?? null)) {
                    $params[(string) $capa][$p['key']] ??= $p;
                }
            }
        }
        $aristas = [];
        foreach (is_array($catalog['edges'] ?? null) ? $catalog['edges'] : [] as $e) {
            if (is_array($e) && is_string($e['id'] ?? null)) {
                $aristas[$e['id']] ??= $e;
            }
        }
        $puertos = [];
        foreach (is_array($catalog['ports'] ?? null) ? $catalog['ports'] : [] as $p) {
            if (is_array($p) && is_string($p['id'] ?? null)) {
                $puertos[$p['id']] ??= (string) ($p['layer'] ?? '');
            }
        }
        $nombres = [];
        foreach (is_array($catalog['layers'] ?? null) ? $catalog['layers'] : [] as $l) {
            if (is_array($l) && is_string($l['id'] ?? null)) {
                $nombres[$l['id']] ??= (string) ($l['name'] ?? $l['id']);
            }
        }

        return [
            'version' => (string) ($catalog['version'] ?? ''),
            'params' => $params,
            'edges' => $aristas,
            'portLayer' => $puertos,
            'layerNames' => $nombres,
            'constraints' => array_values(array_filter(is_array($catalog['constraints'] ?? null) ? $catalog['constraints'] : [], 'is_array')),
            'legacy' => array_filter(is_array($catalog['legacyConditions'] ?? null) ? $catalog['legacyConditions'] : [], 'is_array'),
        ];
    }

    /**
     * `definition.edgeIds`: una lista de textos. Una forma inesperada es un
     * error del llamador (el contenido llega normalizado), como en el validador.
     *
     * @param  array<string,mixed>  $definition
     * @return list<string>
     */
    private static function edgeIds(array $definition): array
    {
        $ids = $definition['edgeIds'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException('definition.edgeIds tiene que ser una lista.');
        }
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('definition.edgeIds solo admite identificadores de texto.');
            }
        }

        return $ids;
    }

    /** @param  array<string,mixed>  $rule */
    private static function isStudio(array $rule): bool
    {
        return ($rule['source'] ?? null) === self::SOURCE_STUDIO;
    }

    /** @param  array<string,mixed>  $rule */
    private static function isLiveStudio(array $rule): bool
    {
        return self::isStudio($rule) && ($rule['status'] ?? null) !== self::STATUS_ARCHIVED;
    }

    /** @param  array<string,mixed>  $e */
    private static function isMandatoryEdge(array $e): bool
    {
        return $e['type'] === 'edge' && ($e['mandatory'] ?? false) === true;
    }

    /**
     * Lo que cita un objetivo en `regla-incompatible-<code>`: `capa.clave` o
     * `Exx` (una frase, ella misma).
     *
     * @param  array<string,mixed>  $e
     */
    private static function item(array $e): string
    {
        return match ($e['type']) {
            'edge' => (string) $e['edge'],
            'text' => (string) $e['phrase'],
            default => "{$e['layer']}.{$e['key']}",
        };
    }

    /**
     * La capa de un objetivo incumplido: la del parámetro o, en una arista, la
     * de su puerto destino.
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $v
     */
    private static function entryLayer(array $e, array $v): string
    {
        if ($e['type'] === 'edge') {
            return (string) ($v['portLayer'][$v['edges'][$e['edge']]['target'] ?? ''] ?? '');
        }

        return (string) $e['layer'];
    }

    /**
     * Intersección en el orden de `$a`.
     *
     * @param  list<bool|int|string>  $a
     * @param  list<bool|int|string>  $b
     * @return list<bool|int|string>
     */
    private static function intersect(array $a, array $b): array
    {
        return array_values(array_filter($a, static fn (mixed $x): bool => in_array($x, $b, true)));
    }

    /**
     * `$a` menos `$b`, en el orden de `$a`.
     *
     * @param  list<bool|int|string>  $a
     * @param  list<bool|int|string>  $b
     * @return list<bool|int|string>
     */
    private static function minus(array $a, array $b): array
    {
        return array_values(array_filter($a, static fn (mixed $x): bool => ! in_array($x, $b, true)));
    }

    /** @param  array<string,list<string>>  $errores */
    private static function add(array &$errores, string $clave, string $mensaje): void
    {
        if (! in_array($mensaje, $errores[$clave] ?? [], true)) {
            $errores[$clave][] = $mensaje;
        }
    }
}
