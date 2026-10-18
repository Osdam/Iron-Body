<?php

namespace App\Services\UltronStudio;

/**
 * La comparación de dos contenidos del Studio (contrato G §A4, `GET compare`):
 * función PURA sobre dos lados y el catálogo ACTUAL.
 *
 * Cada lado tiene la forma del contenido de una versión:
 * `{catalogVersion, format?, definition, configs, layout?, rules}`. Quien
 * llama arma los lados (y añade `from` y `to` a la respuesta):
 *
 *   baseline  el contenido de `LEGACY_BASELINE_V1` y las reglas de StudioCatalog::legacyRules()
 *   draft     el borrador de quien pide (o la línea base) y las reglas vivas, con `format` 2
 *   uuid      el contenido de la versión tal cual, con SUS reglas copiadas
 *
 * Lo que sale:
 *
 *   same            sin diferencias estructurales (aristas, configuración, reglas);
 *                   no compara huellas, que entre catálogos distintos no dicen nada
 *   edges           aristas añadidas y quitadas, con su etiqueta del catálogo
 *   configs         parámetros que cambian, por capa, con antes y después
 *   rules           reglas añadidas, quitadas y cambiadas (revisión y campos)
 *   warnings        avisos de cada lado validado contra el catálogo ACTUAL, cada
 *                   versión según su formato (las de F, sin hallazgos de reglas)
 *   catalogChanged  los dos lados se guardaron con versiones distintas del catálogo
 *
 * Lo que el catálogo actual no conoce (una arista, una capa o un parámetro de
 * una versión vieja) se enseña en crudo: su id hace de etiqueta. La
 * disposición del lienzo no cuenta: no cambia lo que hace el agente.
 */
final class StudioContentDiff
{
    /**
     * Los campos de una regla que se comparan, en el orden de
     * {@see StudioContentHasher::VERSION_RULE_FIELDS_FORMAT_2} (sin el código,
     * que es la llave, ni la revisión, que va aparte). Una regla de formato 1
     * no tiene `conditions`: cuenta como null.
     */
    public const RULE_FIELDS = ['source', 'enforcement', 'status', 'description', 'type', 'layers', 'priority', 'refs', 'conditions'];

    /**
     * @param  array<string,mixed>  $a  el lado «desde»
     * @param  array<string,mixed>  $b  el lado «hasta»
     * @param  array<string,mixed>  $catalog  la forma de {@see StudioCatalog::toArray()}
     * @return array{same:bool,edges:array{added:list<array{id:string,label:string}>,removed:list<array{id:string,label:string}>},configs:list<array{layer:string,key:string,label:string,from:mixed,to:mixed}>,rules:array{added:list<array{code:string,description:string}>,removed:list<array{code:string,description:string}>,changed:list<array{code:string,fromRevision:int|null,toRevision:int|null,fields:list<string>}>},warnings:array{added:list<string>,removed:list<string>},catalogChanged:bool}
     */
    public static function compare(array $a, array $b, array $catalog): array
    {
        $edges = self::edges($a, $b, $catalog);
        $configs = self::configs($a, $b, $catalog);
        $rules = self::rules($a, $b);

        $avisosA = self::warnings($a, $catalog);
        $avisosB = self::warnings($b, $catalog);

        return [
            'same' => $edges['added'] === [] && $edges['removed'] === [] && $configs === []
                && $rules['added'] === [] && $rules['removed'] === [] && $rules['changed'] === [],
            'edges' => $edges,
            'configs' => $configs,
            'rules' => $rules,
            'warnings' => [
                'added' => array_values(array_filter($avisosB, static fn (string $id): bool => ! in_array($id, $avisosA, true))),
                'removed' => array_values(array_filter($avisosA, static fn (string $id): bool => ! in_array($id, $avisosB, true))),
            ],
            'catalogChanged' => ($a['catalogVersion'] ?? null) !== ($b['catalogVersion'] ?? null),
        ];
    }

    /**
     * Los avisos de un lado contra el catálogo actual: los de F y, desde el
     * formato 2, los de sus reglas. Son lo que una segunda persona tendría que
     * reconocer al aprobar.
     *
     * @param  array<string,mixed>  $side
     * @param  array<string,mixed>  $catalog
     * @return list<string>
     */
    public static function warnings(array $side, array $catalog): array
    {
        $definition = is_array($side['definition'] ?? null) ? $side['definition'] : [];
        $configs = is_array($side['configs'] ?? null) ? $side['configs'] : null;

        $rules = is_array($side['rules'] ?? null) ? array_values(array_filter($side['rules'], 'is_array')) : [];
        $findings = [
            ...StudioGraphValidator::validate($catalog, $definition, $configs),
            ...StudioRuleCoherence::evaluate($rules, $definition, $configs, $catalog, StudioContentHasher::format($side)),
        ];

        return array_values(array_unique(StudioGraphValidator::warnings($findings)));
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>  $catalog
     * @return array{added:list<array{id:string,label:string}>,removed:list<array{id:string,label:string}>}
     */
    private static function edges(array $a, array $b, array $catalog): array
    {
        $etiquetas = [];
        foreach ($catalog['edges'] ?? [] as $e) {
            $etiquetas[$e['id']] ??= (string) $e['label'];
        }
        $de = self::edgeIds($a);
        $a2 = self::edgeIds($b);
        $con = static fn (string $id): array => ['id' => $id, 'label' => $etiquetas[$id] ?? $id];

        return [
            'added' => array_map($con, array_values(array_filter($a2, static fn (string $id): bool => ! in_array($id, $de, true)))),
            'removed' => array_map($con, array_values(array_filter($de, static fn (string $id): bool => ! in_array($id, $a2, true)))),
        ];
    }

    /**
     * Los parámetros que cambian: primero en el orden del catálogo actual y
     * después lo que este no conoce, por orden de aparición.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>  $catalog
     * @return list<array{layer:string,key:string,label:string,from:mixed,to:mixed}>
     */
    private static function configs(array $a, array $b, array $catalog): array
    {
        $etiquetas = [];
        $orden = [];
        foreach ($catalog['layers'] ?? [] as $l) {
            foreach ($catalog['parameters'][$l['id']] ?? [] as $p) {
                $etiquetas[$l['id']][$p['key']] = (string) $p['label'];
                $orden["{$l['id']}\n{$p['key']}"] = [(string) $l['id'], (string) $p['key']];
            }
        }
        $ca = is_array($a['configs'] ?? null) ? $a['configs'] : [];
        $cb = is_array($b['configs'] ?? null) ? $b['configs'] : [];
        foreach ([$ca, $cb] as $configs) {
            foreach ($configs as $capa => $valores) {
                foreach (is_array($valores) ? $valores : [] as $clave => $_) {
                    $orden["{$capa}\n{$clave}"] ??= [(string) $capa, (string) $clave];
                }
            }
        }

        $out = [];
        foreach ($orden as [$capa, $clave]) {
            $tieneA = is_array($ca[$capa] ?? null) && array_key_exists($clave, $ca[$capa]);
            $tieneB = is_array($cb[$capa] ?? null) && array_key_exists($clave, $cb[$capa]);
            if (! $tieneA && ! $tieneB) {
                continue;
            }
            $de = $tieneA ? $ca[$capa][$clave] : null;
            $a2 = $tieneB ? $cb[$capa][$clave] : null;
            if ($tieneA === $tieneB && self::same($de, $a2)) {
                continue;
            }
            $out[] = ['layer' => $capa, 'key' => $clave, 'label' => $etiquetas[$capa][$clave] ?? $clave, 'from' => $de, 'to' => $a2];
        }

        return $out;
    }

    /**
     * Reglas por código: añadidas, quitadas y cambiadas, todas por código.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return array{added:list<array{code:string,description:string}>,removed:list<array{code:string,description:string}>,changed:list<array{code:string,fromRevision:int|null,toRevision:int|null,fields:list<string>}>}
     */
    private static function rules(array $a, array $b): array
    {
        $ra = self::rulesByCode($a);
        $rb = self::rulesByCode($b);
        $resumen = static fn (array $r): array => ['code' => (string) $r['code'], 'description' => (string) ($r['description'] ?? '')];

        $cambiadas = [];
        foreach ($rb as $codigo => $nueva) {
            $vieja = $ra[$codigo] ?? null;
            if ($vieja === null) {
                continue;
            }
            $campos = array_values(array_filter(self::RULE_FIELDS, static fn (string $f): bool => ! self::same($vieja[$f] ?? null, $nueva[$f] ?? null)));
            $desde = self::revision($vieja);
            $hasta = self::revision($nueva);
            if ($campos !== [] || $desde !== $hasta) {
                $cambiadas[] = ['code' => (string) $codigo, 'fromRevision' => $desde, 'toRevision' => $hasta, 'fields' => $campos];
            }
        }

        return [
            'added' => array_values(array_map($resumen, array_diff_key($rb, $ra))),
            'removed' => array_values(array_map($resumen, array_diff_key($ra, $rb))),
            'changed' => $cambiadas,
        ];
    }

    /**
     * @param  array<string,mixed>  $side
     * @return array<string,array<string,mixed>>
     */
    private static function rulesByCode(array $side): array
    {
        $out = [];
        foreach (is_array($side['rules'] ?? null) ? $side['rules'] : [] as $r) {
            if (is_array($r) && is_string($r['code'] ?? null)) {
                $out[$r['code']] ??= $r;
            }
        }
        uksort($out, static fn (int|string $x, int|string $y): int => strcmp((string) $x, (string) $y));

        return $out;
    }

    /** @param  array<string,mixed>  $r */
    private static function revision(array $r): ?int
    {
        return is_int($r['revision'] ?? null) ? $r['revision'] : null;
    }

    /**
     * @param  array<string,mixed>  $side
     * @return list<string>
     */
    private static function edgeIds(array $side): array
    {
        $ids = $side['definition']['edgeIds'] ?? [];

        return array_values(array_unique(array_filter(is_array($ids) ? $ids : [], 'is_string')));
    }

    /**
     * Igualdad de datos: las claves de un objeto no cuentan por su orden y las
     * listas sí; `320.0` es `320`, como en JSON.
     */
    private static function same(mixed $x, mixed $y): bool
    {
        return self::canonical($x) === self::canonical($y);
    }

    private static function canonical(mixed $v): string
    {
        return (string) json_encode(self::sortKeys($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) <= 9007199254740991) {
            return (int) $v;
        }
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::sortKeys(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }
}
