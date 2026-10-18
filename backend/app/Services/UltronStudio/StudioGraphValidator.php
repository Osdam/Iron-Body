<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * Las reglas del grafo de ULTRON STUDIO, del lado del servidor: funciones
 * PURAS sobre un catálogo (el de {@see StudioCatalog::graph()} o cualquiera con
 * su forma) y una definición de borrador.
 *
 * Es la MISMA semántica que `studio-graph.ts` del CRM (commit 4f7d774), hasta
 * en el orden de los hallazgos y en cada separador de los textos: el CRM valida
 * en vivo mientras se edita y el servidor repite la validación y manda él. Si
 * los dos lados discrepan, el usuario vería un borrador «publicable» que el
 * servidor rechaza. `tests/fixtures/ultron-studio/validation-cases.json` fija
 * la salida de la referencia TS caso a caso y una prueba exige que esto la
 * reproduzca exactamente.
 *
 * Detalles que importan para esa paridad:
 *  - Las aristas presentes se recorren en el orden del CATÁLOGO, no en el del
 *    borrador.
 *  - El recorrido en profundidad de {@see findCycle()} parte de las capas en el
 *    orden del catálogo y no cuenta la realimentación entre turnos ni la arista
 *    que abre el turno.
 *  - La igualdad de un valor de configuración es la de `JSON.stringify` (no la
 *    de PHP): `"1"` no es `1`, `true` no es `1`, y en una lista cuenta el orden.
 *  - Un hallazgo solo lleva `layer` donde la referencia la pone.
 */
final class StudioGraphValidator
{
    /**
     * Valida un borrador completo: el grafo y, si se pasa, la configuración.
     * Lo que es `blocking` impide aprobar o publicar.
     *
     * @param  array<string,mixed>  $catalog
     * @param  array<string,mixed>  $definition  `{layers, edgeIds}`
     * @param  array<array-key,mixed>|null  $configs  null = validar solo el grafo
     * @return list<array{id:string,level:string,title:string,detail:string,layer?:string}>
     */
    public static function validate(array $catalog, array $definition, ?array $configs): array
    {
        $capas = self::ids($definition, 'layers');
        $aristas = self::ids($definition, 'edgeIds');
        $presentes = self::presentEdges($catalog, $definition);
        $puertos = self::portLayers($catalog);
        $out = [];

        $faltan = array_values(array_filter(
            $catalog['layers'],
            static fn (array $l): bool => self::flag($l, 'critical') && ! in_array($l['id'], $capas, true),
        ));
        $out[] = $faltan
            ? ['id' => 'capas-criticas', 'level' => 'blocking', 'title' => 'Faltan capas críticas', 'detail' => implode(', ', array_column($faltan, 'name'))]
            : ['id' => 'capas-criticas', 'level' => 'ok', 'title' => 'Las diez capas están presentes', 'detail' => 'Ninguna capa crítica falta.'];

        $obligatorias = array_values(array_filter($catalog['edges'], static fn (array $e): bool => self::flag($e, 'mandatory')));
        $obligatoriasFuera = array_values(array_filter($obligatorias, static fn (array $e): bool => ! in_array($e['id'], $aristas, true)));
        $out[] = $obligatoriasFuera
            ? ['id' => 'aristas-obligatorias', 'level' => 'blocking', 'title' => 'Faltan conexiones obligatorias', 'detail' => implode(' · ', array_column($obligatoriasFuera, 'label'))]
            : ['id' => 'aristas-obligatorias', 'level' => 'ok', 'title' => 'Controles obligatorios conectados', 'detail' => count($obligatorias).' conexiones obligatorias presentes.'];

        // En el orden del borrador y con repetidos, como la referencia.
        $conocidas = array_column($catalog['edges'], 'id');
        $desconocidas = array_values(array_filter($aristas, static fn (string $id): bool => ! in_array($id, $conocidas, true)));
        if ($desconocidas) {
            $out[] = ['id' => 'aristas-desconocidas', 'level' => 'blocking', 'title' => 'Conexiones que el motor real no tiene', 'detail' => implode(', ', $desconocidas)];
        }

        $ciclo = self::findCycle($catalog, $presentes);
        $out[] = $ciclo !== null
            ? ['id' => 'ciclos', 'level' => 'blocking', 'title' => 'Ciclo dentro de un turno', 'detail' => implode(' → ', array_map(static fn (string $l): string => self::layerName($catalog, $l), $ciclo))]
            : ['id' => 'ciclos', 'level' => 'ok', 'title' => 'Sin ciclos dentro de un turno', 'detail' => 'La memoria entre turnos no cuenta como ciclo.'];

        foreach ($catalog['requiredPaths'] as $p) {
            $ok = self::hasPath($catalog, $presentes, $p['from'], $p['to']);
            $out[] = ['id' => 'camino-'.$p['id'], 'level' => $ok ? 'ok' : 'blocking', 'title' => $p['title'], 'detail' => $p['detail'], 'layer' => $p['to']];
        }

        foreach ($catalog['edges'] as $e) {
            if (! self::flag($e, 'mandatory') && ! in_array($e['id'], $aristas, true)) {
                $out[] = ['id' => 'opcional-'.$e['id'], 'level' => 'warning', 'title' => 'Sin «'.$e['label'].'»', 'detail' => $e['consequence']];
            }
        }

        foreach ($catalog['layers'] as $l) {
            $conectada = false;
            foreach ($presentes as $e) {
                if (($puertos[$e['source']] ?? null) === $l['id'] || ($puertos[$e['target']] ?? null) === $l['id']) {
                    $conectada = true;
                    break;
                }
            }
            if (self::flag($l, 'critical') && ! $conectada) {
                $out[] = ['id' => 'aislada-'.$l['id'], 'level' => 'blocking', 'title' => $l['name'].' quedó aislada', 'detail' => 'Una capa crítica sin conexiones no participa del turno.', 'layer' => $l['id']];
            }
        }

        return [...$out, ...self::checkConfigs($catalog, $definition, $configs)];
    }

    /**
     * La configuración del borrador contra las restricciones del catálogo:
     * valores que el motor no admite y coherencia entre un valor y las
     * conexiones presentes. Sin configuración no hay nada que comprobar.
     *
     * @param  array<string,mixed>  $catalog
     * @param  array<string,mixed>  $definition
     * @param  array<array-key,mixed>|null  $configs
     * @return list<array{id:string,level:string,title:string,detail:string,layer:string}>
     */
    public static function checkConfigs(array $catalog, array $definition, ?array $configs): array
    {
        $reglas = $catalog['constraints'] ?? [];
        if ($configs === null || ! $reglas) {
            return [];
        }

        $aristas = self::ids($definition, 'edgeIds');
        $etiquetas = array_column($catalog['edges'], 'label', 'id');
        $nombre = static fn (string $id): string => $etiquetas[$id] ?? $id;
        $out = [];
        $comprobadas = 0;

        foreach ($reglas as $c) {
            // `configs[c.layer]?.[c.key] === undefined` en la referencia: una capa
            // que no es un objeto o una clave ausente no se comprueba; un null sí.
            $capa = $configs[$c['layer']] ?? null;
            if (! is_array($capa) || ! array_key_exists($c['key'], $capa)) {
                continue;
            }
            $valor = $capa[$c['key']];
            $comprobadas++;

            if (array_key_exists('forbidden', $c)) {
                if (self::same($valor, $c['forbidden'])) {
                    $out[] = ['id' => 'config-'.$c['id'], 'level' => $c['level'], 'title' => $c['title'], 'detail' => $c['detail'], 'layer' => $c['layer']];
                }

                continue;
            }

            if (! array_key_exists('when', $c) || ! self::same($valor, $c['when'])) {
                continue;
            }

            $faltan = array_values(array_filter($c['requires'] ?? [], static fn (string $id): bool => ! in_array($id, $aristas, true)));
            $sobran = array_values(array_filter($c['forbids'] ?? [], static fn (string $id): bool => in_array($id, $aristas, true)));
            if (! $faltan && ! $sobran) {
                continue;
            }

            $partes = [];
            if ($faltan) {
                $partes[] = 'falta: '.implode(' · ', array_map($nombre, $faltan));
            }
            if ($sobran) {
                $partes[] = 'sobra: '.implode(' · ', array_map($nombre, $sobran));
            }
            $out[] = ['id' => 'config-'.$c['id'], 'level' => $c['level'], 'title' => $c['title'], 'detail' => $c['detail'].' ('.implode('; ', $partes).')', 'layer' => $c['layer']];
        }

        if (! $out && $comprobadas) {
            $out[] = ['id' => 'configuracion', 'level' => 'ok', 'title' => 'La configuración respeta las reglas del motor', 'detail' => $comprobadas.' comprobaciones sobre los parámetros del borrador.'];
        }

        return $out;
    }

    /**
     * Aristas del catálogo presentes en el borrador, en el orden del catálogo.
     *
     * @param  array<string,mixed>  $catalog
     * @param  array<string,mixed>  $definition
     * @return list<array<string,mixed>>
     */
    public static function presentEdges(array $catalog, array $definition): array
    {
        $aristas = self::ids($definition, 'edgeIds');

        return array_values(array_filter($catalog['edges'], static fn (array $e): bool => in_array($e['id'], $aristas, true)));
    }

    /**
     * Busca un ciclo DENTRO de un turno (sin la realimentación entre turnos ni
     * la arista que abre el turno). Devuelve las capas del ciclo, cerrándolo con
     * la primera repetida (`[intent, strategy, intent]`), o null.
     *
     * @param  array<string,mixed>  $catalog
     * @param  list<array<string,mixed>>  $edges
     * @return list<string>|null
     */
    public static function findCycle(array $catalog, array $edges): ?array
    {
        $adj = self::adjacency($catalog, $edges);
        $estado = [];
        $pila = [];

        $visitar = static function (string $n) use (&$visitar, &$estado, &$pila, $adj): ?array {
            $estado[$n] = 'visitando';
            $pila[] = $n;
            foreach ($adj[$n] ?? [] as $m) {
                if (($estado[$m] ?? null) === 'visitando') {
                    return [...array_slice($pila, (int) array_search($m, $pila, true)), $m];
                }
                if (! isset($estado[$m])) {
                    $ciclo = $visitar($m);
                    if ($ciclo !== null) {
                        return $ciclo;
                    }
                }
            }
            array_pop($pila);
            $estado[$n] = 'hecho';

            return null;
        };

        foreach ($catalog['layers'] as $l) {
            if (! isset($estado[$l['id']])) {
                $ciclo = $visitar($l['id']);
                if ($ciclo !== null) {
                    return $ciclo;
                }
            }
        }

        return null;
    }

    /**
     * ¿Hay camino de `$from` a `$to` usando solo aristas presentes y de un mismo
     * turno? En anchura, como la referencia.
     *
     * @param  array<string,mixed>  $catalog
     * @param  list<array<string,mixed>>  $edges
     */
    public static function hasPath(array $catalog, array $edges, string $from, string $to): bool
    {
        $adj = self::adjacency($catalog, $edges);
        $vistos = [$from => true];
        $cola = [$from];

        while ($cola) {
            $n = array_shift($cola);
            if ($n === $to) {
                return true;
            }
            foreach ($adj[$n] ?? [] as $m) {
                if (! isset($vistos[$m])) {
                    $vistos[$m] = true;
                    $cola[] = $m;
                }
            }
        }

        return false;
    }

    /** @param  list<array<string,mixed>>  $findings */
    public static function isPublishable(array $findings): bool
    {
        foreach ($findings as $f) {
            if ($f['level'] === 'blocking') {
                return false;
            }
        }

        return true;
    }

    /**
     * Los ids de los avisos, en orden: lo que una segunda persona tiene que
     * reconocer al aprobar.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<string>
     */
    public static function warnings(array $findings): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['id'],
            array_filter($findings, static fn (array $f): bool => $f['level'] === 'warning'),
        ));
    }

    /**
     * Capa de cada puerto. Como `catalog.ports.find()`: si un id se repitiera,
     * manda el primero.
     *
     * @param  array<string,mixed>  $catalog
     * @return array<string,string>
     */
    private static function portLayers(array $catalog): array
    {
        $capas = [];
        foreach ($catalog['ports'] as $p) {
            $capas[$p['id']] ??= $p['layer'];
        }

        return $capas;
    }

    /**
     * Capa → capas a las que alimenta, en el orden de `$edges`, sin la
     * realimentación entre turnos ni la arista que abre el turno.
     *
     * @param  array<string,mixed>  $catalog
     * @param  list<array<string,mixed>>  $edges
     * @return array<string,list<string>>
     */
    private static function adjacency(array $catalog, array $edges): array
    {
        $puertos = self::portLayers($catalog);
        $adj = [];
        foreach ($edges as $e) {
            if (self::flag($e, 'feedback') || self::flag($e, 'opensTurn')) {
                continue;
            }
            $s = $puertos[$e['source']] ?? null;
            $t = $puertos[$e['target']] ?? null;
            if ($s === null || $t === null) {
                continue;
            }
            $adj[$s][] = $t;
        }

        return $adj;
    }

    /** @param  array<string,mixed>  $catalog */
    private static function layerName(array $catalog, string $id): string
    {
        foreach ($catalog['layers'] as $l) {
            if ($l['id'] === $id) {
                return $l['name'];
            }
        }

        return $id;
    }

    /** @param  array<string,mixed>  $item */
    private static function flag(array $item, string $key): bool
    {
        return ($item[$key] ?? false) === true;
    }

    /**
     * `definition.layers` o `definition.edgeIds`: una lista de textos. La
     * referencia TS los recibe tipados; aquí una forma inesperada es un error
     * del llamador (el contenido llega normalizado), no un borrador inválido.
     *
     * @param  array<string,mixed>  $definition
     * @return list<string>
     */
    private static function ids(array $definition, string $key): array
    {
        $ids = $definition[$key] ?? null;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException("definition.{$key} tiene que ser una lista.");
        }
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException("definition.{$key} solo admite identificadores de texto.");
            }
        }

        return $ids;
    }

    /**
     * La igualdad de `JSON.stringify(a) === JSON.stringify(b)`.
     *
     * No vale `===`: en JavaScript `320` y `320.0` son el mismo número y en PHP
     * no. Tampoco `==`: ahí `"1"` sería `1` y `true` sería `1`.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return self::jsJson($a) === self::jsJson($b);
    }

    private static function jsJson(mixed $value): string
    {
        return json_encode(self::asJsValue($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Un float con valor entero se escribe como en JavaScript (`320`, no
     * `320.0`; `-0` como `0`) y los no finitos como `null`, igual que
     * `JSON.stringify`.
     */
    private static function asJsValue(mixed $value): mixed
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            if (floor($value) === $value && abs($value) <= 9007199254740991) {
                return (int) $value;
            }

            return $value;
        }

        if (is_array($value)) {
            return array_map(self::asJsValue(...), $value);
        }

        return $value;
    }
}
