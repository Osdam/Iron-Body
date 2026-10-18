<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * Huellas del Studio: sha256 de un JSON CANÓNICO.
 *
 * Canónico quiere decir que el mismo contenido da siempre los mismos bytes:
 * las claves de cada objeto se ordenan (recursivamente), las listas conservan
 * su orden y se codifica sin escapar tildes ni barras. Los decimales se
 * rechazan: `0.1` no tiene una única escritura y una huella que dependa de
 * cómo se imprime un float no prueba nada. El contenido que se firma solo
 * lleva enteros (el normalizador del borrador lo garantiza).
 *
 * Lo que entra en cada huella es lo que decide el COMPORTAMIENTO:
 *
 *  - Borrador: `{catalogVersion, definition, configs}`.
 *  - Versión: lo mismo más las reglas, ordenadas por código.
 *  - Regla: `{description, type, layers, priority, refs, origin}`.
 *
 * La disposición del lienzo (posiciones y vista) NO cuenta: mover un nodo no
 * cambia lo que hace el agente, así que dos versiones con el mismo
 * comportamiento comparten huella. Por lo mismo, en la definición las capas y
 * las aristas son CONJUNTOS (su orden no cuenta); las listas de los parámetros
 * sí son listas (el orden de las ofertas priorizadas es significativo).
 */
final class StudioContentHasher
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Lo que se copia de cada regla dentro de una versión. */
    public const VERSION_RULE_FIELDS = ['code', 'revision', 'source', 'enforcement', 'status', 'description', 'type', 'layers', 'priority', 'refs'];

    /** Lo que identifica el contenido de una regla (sin estado ni revisión). */
    public const RULE_FIELDS = ['description', 'type', 'layers', 'priority', 'refs', 'origin'];

    /**
     * Huella de un borrador. `$content` es el contenido normalizado
     * (`{definition, configs, layout}`); la disposición se ignora.
     *
     * @param  array<string,mixed>  $content
     */
    public static function draftHash(string $catalogVersion, array $content): string
    {
        return self::hash(self::behaviour($catalogVersion, $content));
    }

    /**
     * Huella de una versión: la del borrador más sus reglas, ordenadas por
     * código y reducidas a {@see VERSION_RULE_FIELDS}. Lo demás de cada regla
     * (uuid, fechas, autor) no cambia el comportamiento.
     *
     * @param  array<string,mixed>  $content
     * @param  list<array<string,mixed>>  $rules
     */
    public static function versionHash(string $catalogVersion, array $content, array $rules): string
    {
        $copia = array_map(static fn (array $rule): array => self::pick($rule, self::VERSION_RULE_FIELDS), $rules);
        usort($copia, static fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));

        return self::hash(self::behaviour($catalogVersion, $content) + ['rules' => $copia]);
    }

    /**
     * Huella del contenido de una regla.
     *
     * @param  array<string,mixed>  $rule
     */
    public static function ruleHash(array $rule): string
    {
        return self::hash(self::pick($rule, self::RULE_FIELDS));
    }

    /** sha256 (hex, 64 caracteres) del JSON canónico de `$value`. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), self::JSON_FLAGS);
    }

    /**
     * Ordena las claves de los objetos, recursivamente; las listas quedan en
     * su orden. Solo admite null, booleanos, enteros, cadenas y arrays.
     */
    public static function canonicalize(mixed $value, string $path = '$'): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("La huella no admite decimales ({$path}).");
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('La huella no admite valores de tipo '.get_debug_type($value)." ({$path}).");
        }

        if (array_is_list($value)) {
            $lista = [];
            foreach ($value as $i => $item) {
                $lista[] = self::canonicalize($item, "{$path}[{$i}]");
            }

            return $lista;
        }

        $objeto = [];
        foreach ($value as $clave => $item) {
            $objeto[$clave] = self::canonicalize($item, "{$path}.{$clave}");
        }
        ksort($objeto, SORT_STRING);

        return $objeto;
    }

    /**
     * Lo que decide el comportamiento de un borrador. Capas y aristas como
     * conjuntos: sin repetidos y en orden alfabético.
     *
     * @param  array<string,mixed>  $content
     * @return array{catalogVersion:string,definition:array{layers:list<string>,edgeIds:list<string>},configs:mixed}
     */
    private static function behaviour(string $catalogVersion, array $content): array
    {
        $definition = $content['definition'] ?? null;
        if (! is_array($definition) || ! array_key_exists('configs', $content)) {
            throw new InvalidArgumentException('El contenido a firmar necesita definition y configs.');
        }

        return [
            'catalogVersion' => $catalogVersion,
            'definition' => [
                'layers' => self::asSet($definition['layers'] ?? null, 'definition.layers'),
                'edgeIds' => self::asSet($definition['edgeIds'] ?? null, 'definition.edgeIds'),
            ],
            'configs' => $content['configs'],
        ];
    }

    /** @return list<string> */
    private static function asSet(mixed $ids, string $campo): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException("{$campo} tiene que ser una lista.");
        }

        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException("{$campo} solo admite identificadores de texto.");
            }
        }

        $conjunto = array_values(array_unique($ids));
        sort($conjunto, SORT_STRING);

        return $conjunto;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private static function pick(array $source, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                throw new InvalidArgumentException("Falta el campo {$field} para calcular la huella.");
            }
            $out[$field] = $source[$field];
        }

        return $out;
    }
}
