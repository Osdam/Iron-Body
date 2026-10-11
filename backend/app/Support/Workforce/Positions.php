<?php

namespace App\Support\Workforce;

/**
 * Catálogo de cargos (config/workforce.php) y las preguntas que se le hacen.
 *
 * Una persona tiene los cargos de sus roles de entrenador activos MÁS los de su
 * perfil de personal activo. Que registre jornada lo decide el cargo, no la
 * pantalla que eligió en la tablet.
 */
final class Positions
{
    public const SOURCE_TRAINER = 'trainer';

    public const SOURCE_STAFF = 'staff';

    /** @return array<string, array{label:string, source:string, workday:bool}> */
    public static function catalog(): array
    {
        return (array) config('workforce.positions', []);
    }

    public static function exists(string $key): bool
    {
        return isset(self::catalog()[$key]);
    }

    public static function label(string $key): string
    {
        return (string) (self::catalog()[$key]['label'] ?? $key);
    }

    public static function source(string $key): ?string
    {
        return self::catalog()[$key]['source'] ?? null;
    }

    public static function registersWorkday(string $key): bool
    {
        return (bool) (self::catalog()[$key]['workday'] ?? false);
    }

    /** @return list<string> */
    public static function staffKeys(): array
    {
        return array_keys(array_filter(self::catalog(), fn (array $p) => ($p['source'] ?? null) === self::SOURCE_STAFF));
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key:string, label:string, source:?string}>
     */
    public static function describe(array $keys): array
    {
        return array_values(array_map(fn (string $k): array => [
            'key' => $k,
            'label' => self::label($k),
            'source' => self::source($k),
        ], $keys));
    }

    /**
     * El cargo con el que se abre una jornada: el primero del catálogo que
     * registra jornada (planta antes que recepción, por el orden del catálogo).
     *
     * @param  list<string>  $keys
     */
    public static function workdayPosition(array $keys): ?string
    {
        foreach (array_keys(self::catalog()) as $k) {
            if (in_array($k, $keys, true) && self::registersWorkday($k)) {
                return $k;
            }
        }

        return null;
    }

    /** @return list<array{key:string, label:string, source:string, workday:bool}> */
    public static function toArray(): array
    {
        $out = [];
        foreach (self::catalog() as $key => $p) {
            $out[] = ['key' => $key, 'label' => $p['label'], 'source' => $p['source'], 'workday' => (bool) $p['workday']];
        }

        return $out;
    }
}
