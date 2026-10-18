<?php

namespace App\Services\UltronStudio;

use App\Rules\PlainText;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * El contenido de un borrador tal y como llega del CRM, validado contra el
 * catálogo y devuelto en su forma canónica.
 *
 * Valida en el borde y FALLA CERRADO: lo que el catálogo no conoce (una capa,
 * un parámetro, una conexión) o un valor que el parámetro no admite es un 422
 * con claves `content.*`, nunca un descarte silencioso. Lo que no viene se
 * rellena con la línea base. El cliente adopta lo que devuelve esto.
 *
 * Forma de salida:
 *
 *   definition  {layers: las del catálogo, edgeIds: en el orden del catálogo}
 *   configs     todas las capas y todos sus parámetros, en el orden del catálogo
 *   layout      {positions: todas las capas, viewport: null | {x, y, scale}}
 *
 * `definition.layers` que llegue se ignora (las capas no se quitan ni se
 * añaden desde el lienzo) y cualquier otra clave se descarta.
 *
 * Un borrador puede guardarse con bloqueos de validación (se enseñan al
 * guardar); lo que no puede es contener algo que el catálogo no reconoce.
 *
 * Los mensajes no repiten lo que mandó el cliente: una clave desconocida se
 * señala en su objeto padre, no con su nombre.
 */
final class StudioDraftNormalizer
{
    /** Tamaño máximo del contenido, codificado en JSON. */
    public const MAX_BYTES = 65536;

    public const MAX_TEXT = 160;

    public const MAX_TEXTAREA = 600;

    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 60;

    /** Valor absoluto máximo de una coordenada del lienzo. */
    public const MAX_COORDINATE = 100000;

    public const MIN_SCALE = 0.3;

    public const MAX_SCALE = 2.2;

    /**
     * Los espacios que quita `String.prototype.trim()` en el CRM (blancos y
     * terminadores de línea de ECMAScript), para recortar igual en los dos
     * lados sin depender de las propiedades Unicode de la PCRE instalada.
     */
    private const JS_TRIM = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * @param  array<array-key,mixed>  $content  lo que llega en `content`
     * @param  array<string,mixed>|null  $catalog  la forma de {@see StudioCatalog::toArray()}; por defecto, el catálogo actual
     * @return array{definition:array{layers:list<string>,edgeIds:list<string>},configs:array<string,array<string,mixed>>,layout:array{positions:array<string,array{x:int,y:int}>,viewport:array{x:int|float,y:int|float,scale:int|float}|null}}
     *
     * @throws ValidationException
     */
    public static function normalize(array $content, ?array $catalog = null): array
    {
        $catalog ??= StudioCatalog::toArray();

        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw ValidationException::withMessages(['content' => 'El borrador no se puede leer.']);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['content' => 'El borrador supera el tamaño máximo (64 KB).']);
        }

        $errores = [];
        $definition = self::definition($content['definition'] ?? null, $catalog, $errores);
        $configs = self::configs($content['configs'] ?? [], $catalog, $errores);
        $layout = self::layout($content['layout'] ?? [], $catalog, $errores);

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return ['definition' => $definition, 'configs' => $configs, 'layout' => $layout];
    }

    /**
     * @param  array<string,mixed>  $catalog
     * @param  array<string,list<string>>  $errores
     * @return array{layers:list<string>,edgeIds:list<string>}
     */
    private static function definition(mixed $definition, array $catalog, array &$errores): array
    {
        $capas = array_column($catalog['layers'], 'id');
        $vacia = ['layers' => $capas, 'edgeIds' => []];

        if (! is_array($definition)) {
            $errores['content.definition'][] = 'Falta la definición del grafo.';

            return $vacia;
        }

        $edgeIds = $definition['edgeIds'] ?? null;
        if (! is_array($edgeIds) || ! array_is_list($edgeIds)) {
            $errores['content.definition.edgeIds'][] = 'Las conexiones tienen que ser una lista.';

            return $vacia;
        }

        $conocidas = array_flip(array_column($catalog['edges'], 'id'));
        $vistas = [];
        foreach ($edgeIds as $i => $id) {
            $campo = "content.definition.edgeIds.{$i}";
            if (! is_string($id)) {
                $errores[$campo][] = 'Cada conexión es un identificador de texto.';
            } elseif (! isset($conocidas[$id])) {
                $errores[$campo][] = 'Esa conexión no existe en el motor.';
            } elseif (isset($vistas[$id])) {
                $errores[$campo][] = 'Conexión repetida.';
            } else {
                $vistas[$id] = true;
            }
        }

        $presentes = [];
        foreach ($catalog['edges'] as $e) {
            if (isset($vistas[$e['id']])) {
                $presentes[] = $e['id'];
            }
        }

        return ['layers' => $capas, 'edgeIds' => $presentes];
    }

    /**
     * @param  array<string,mixed>  $catalog
     * @param  array<string,list<string>>  $errores
     * @return array<string,array<string,mixed>>
     */
    private static function configs(mixed $configs, array $catalog, array &$errores): array
    {
        $parametros = $catalog['parameters'];
        $base = $catalog['baseline']['configs'];
        $recibidos = [];

        if (! is_array($configs)) {
            $errores['content.configs'][] = 'La configuración tiene que ser un objeto.';
            $configs = [];
        }

        foreach ($configs as $capa => $valores) {
            $capa = (string) $capa;
            if (! isset($parametros[$capa])) {
                self::once($errores, 'content.configs', 'La configuración tiene capas que el catálogo no conoce.');

                continue;
            }
            if (! is_array($valores)) {
                $errores["content.configs.{$capa}"][] = 'La configuración de la capa tiene que ser un objeto.';

                continue;
            }

            $porClave = array_column($parametros[$capa], null, 'key');
            foreach ($valores as $clave => $valor) {
                $clave = (string) $clave;
                if (! isset($porClave[$clave])) {
                    self::once($errores, "content.configs.{$capa}", 'La capa tiene parámetros que el catálogo no conoce.');

                    continue;
                }
                $campo = "content.configs.{$capa}.{$clave}";
                $limpio = self::value($valor, $porClave[$clave], $campo, $errores);
                if ($limpio !== null) {
                    $recibidos[$capa][$clave] = $limpio;
                }
            }
        }

        // Todas las capas y todos sus parámetros; lo que falte, de la línea base.
        $out = [];
        foreach ($catalog['layers'] as $l) {
            $capa = $l['id'];
            $out[$capa] = [];
            foreach ($parametros[$capa] ?? [] as $p) {
                if (array_key_exists($p['key'], $recibidos[$capa] ?? [])) {
                    $out[$capa][$p['key']] = $recibidos[$capa][$p['key']];
                } elseif (array_key_exists($p['key'], $base[$capa] ?? [])) {
                    $out[$capa][$p['key']] = $base[$capa][$p['key']];
                }
            }
        }

        return $out;
    }

    /**
     * El valor de un parámetro, según su tipo. Devuelve null si no vale (y
     * apunta el error); ningún tipo admite null como valor.
     *
     * @param  array<string,mixed>  $param
     * @param  array<string,list<string>>  $errores
     */
    private static function value(mixed $valor, array $param, string $campo, array &$errores): mixed
    {
        switch ($param['type']) {
            case 'toggle':
                if (is_bool($valor)) {
                    return $valor;
                }
                $errores[$campo][] = 'Tiene que ser verdadero o falso.';

                return null;

            case 'range':
                $min = $param['min'] ?? -1000000;
                $max = $param['max'] ?? 1000000;
                if (is_int($valor) && $valor >= $min && $valor <= $max) {
                    return $valor;
                }
                $errores[$campo][] = "Tiene que ser un número entero entre {$min} y {$max}.";

                return null;

            case 'select':
                $opciones = array_column($param['options'] ?? [], 'value');
                if (is_string($valor) && in_array($valor, $opciones, true)) {
                    return $valor;
                }
                $errores[$campo][] = 'No es una de las opciones permitidas.';

                return null;

            case 'text':
            case 'textarea':
                $multilinea = $param['type'] === 'textarea';
                $tope = $multilinea ? self::MAX_TEXTAREA : self::MAX_TEXT;
                if (! is_string($valor)) {
                    $errores[$campo][] = 'Tiene que ser un texto.';

                    return null;
                }
                if (! self::plain($valor, $multilinea, $campo, $errores)) {
                    return null;
                }
                if (mb_strlen($valor, 'UTF-8') > $tope) {
                    $errores[$campo][] = "Admite como máximo {$tope} caracteres.";

                    return null;
                }

                return $valor;

            case 'tags':
                return self::tags($valor, $campo, $errores);
        }

        // Un tipo que el catálogo declara y este normalizador no conoce es un
        // fallo de código (lo vigila StudioCatalogTest), no del borrador.
        throw new LogicException("Tipo de parámetro sin normalizar: {$param['type']}.");
    }

    /**
     * Una lista de textos cortos: se recortan los espacios de los extremos y
     * se descartan los vacíos; lo que quede tiene que caber en los límites.
     * El orden se conserva (en las ofertas priorizadas es significativo).
     *
     * @param  array<string,list<string>>  $errores
     * @return list<string>|null
     */
    private static function tags(mixed $valor, string $campo, array &$errores): ?array
    {
        if (! is_array($valor) || ! array_is_list($valor)) {
            $errores[$campo][] = 'Tiene que ser una lista de textos.';

            return null;
        }

        $limpias = [];
        $bien = true;
        foreach ($valor as $i => $tag) {
            if (! is_string($tag)) {
                $errores["{$campo}.{$i}"][] = 'Cada elemento tiene que ser un texto.';
                $bien = false;

                continue;
            }
            if (! mb_check_encoding($tag, 'UTF-8')) {
                $errores["{$campo}.{$i}"][] = 'El texto contiene caracteres no permitidos.';
                $bien = false;

                continue;
            }
            $tag = (string) preg_replace('/^['.self::JS_TRIM.']+|['.self::JS_TRIM.']+$/u', '', $tag);
            if ($tag === '') {
                continue;
            }
            if (! self::plain($tag, false, "{$campo}.{$i}", $errores)) {
                $bien = false;

                continue;
            }
            if (mb_strlen($tag, 'UTF-8') > self::MAX_TAG_LENGTH) {
                $errores["{$campo}.{$i}"][] = 'Cada elemento admite como máximo '.self::MAX_TAG_LENGTH.' caracteres.';
                $bien = false;

                continue;
            }
            $limpias[] = $tag;
        }

        if (count($limpias) > self::MAX_TAGS) {
            $errores[$campo][] = 'Admite como máximo '.self::MAX_TAGS.' elementos.';
            $bien = false;
        }

        return $bien ? $limpias : null;
    }

    /**
     * @param  array<string,mixed>  $catalog
     * @param  array<string,list<string>>  $errores
     * @return array{positions:array<string,array{x:int,y:int}>,viewport:array{x:int|float,y:int|float,scale:int|float}|null}
     */
    private static function layout(mixed $layout, array $catalog, array &$errores): array
    {
        if (! is_array($layout)) {
            $errores['content.layout'][] = 'La disposición tiene que ser un objeto.';
            $layout = [];
        }

        $capas = array_column($catalog['layers'], 'id');
        $base = $catalog['baseline']['positions'];
        $recibidas = [];

        $positions = $layout['positions'] ?? [];
        if (! is_array($positions)) {
            $errores['content.layout.positions'][] = 'Las posiciones tienen que ser un objeto.';
            $positions = [];
        }
        foreach ($positions as $capa => $pos) {
            $capa = (string) $capa;
            if (! in_array($capa, $capas, true)) {
                self::once($errores, 'content.layout.positions', 'Las posiciones tienen capas que el catálogo no conoce.');

                continue;
            }
            if (! is_array($pos) || ! self::coordinate($pos['x'] ?? null) || ! self::coordinate($pos['y'] ?? null)) {
                $errores["content.layout.positions.{$capa}"][] = 'La posición necesita x e y enteros, con valor absoluto hasta '.self::MAX_COORDINATE.'.';

                continue;
            }
            $recibidas[$capa] = ['x' => $pos['x'], 'y' => $pos['y']];
        }

        $out = [];
        foreach ($capas as $capa) {
            if (isset($recibidas[$capa])) {
                $out[$capa] = $recibidas[$capa];
            } elseif (isset($base[$capa])) {
                $out[$capa] = $base[$capa];
            }
        }

        return ['positions' => $out, 'viewport' => self::viewport($layout['viewport'] ?? null, $errores)];
    }

    /**
     * @param  array<string,list<string>>  $errores
     * @return array{x:int|float,y:int|float,scale:int|float}|null
     */
    private static function viewport(mixed $viewport, array &$errores): ?array
    {
        if ($viewport === null) {
            return null;
        }
        if (! is_array($viewport)) {
            $errores['content.layout.viewport'][] = 'La vista tiene que ser nula o tener x, y y escala.';

            return null;
        }

        $bien = true;
        foreach (['x', 'y', 'scale'] as $eje) {
            if (! self::finite($viewport[$eje] ?? null)) {
                $errores["content.layout.viewport.{$eje}"][] = 'Tiene que ser un número finito.';
                $bien = false;
            }
        }
        if ($bien && ($viewport['scale'] < self::MIN_SCALE || $viewport['scale'] > self::MAX_SCALE)) {
            $errores['content.layout.viewport.scale'][] = 'La escala tiene que estar entre 0,3 y 2,2.';
            $bien = false;
        }

        return $bien ? ['x' => $viewport['x'], 'y' => $viewport['y'], 'scale' => $viewport['scale']] : null;
    }

    private static function coordinate(mixed $v): bool
    {
        return is_int($v) && abs($v) <= self::MAX_COORDINATE;
    }

    private static function finite(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v));
    }

    /**
     * Texto plano con la misma regla que el resto del CRM ({@see PlainText}):
     * UTF-8 válido, sin controles ni invisibles; en multilínea admite
     * tabulador y saltos de línea.
     *
     * @param  array<string,list<string>>  $errores
     */
    private static function plain(string $texto, bool $multilinea, string $campo, array &$errores): bool
    {
        $bien = true;
        (new PlainText(multiline: $multilinea))->validate($campo, $texto, static function (string $mensaje) use (&$errores, $campo, &$bien): void {
            $errores[$campo][] = $mensaje;
            $bien = false;
        });

        return $bien;
    }

    /** @param  array<string,list<string>>  $errores */
    private static function once(array &$errores, string $campo, string $mensaje): void
    {
        if (! in_array($mensaje, $errores[$campo] ?? [], true)) {
            $errores[$campo][] = $mensaje;
        }
    }
}
