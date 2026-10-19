<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Validación en DOS FASES para las reglas con comodín (`lista.*`).
 *
 * Laravel expande cada comodín sobre TODOS los elementos que llegan antes de
 * evaluar ninguna regla, y esa expansión es superlineal: cada elemento copia la
 * tabla de reglas acumulada (`ValidationRuleParser::mergeRulesForAttribute`) y
 * `distinct` compara cada elemento con todos los demás. El `max:N` de la lista
 * no corta nada, porque se evalúa después. Una sola petición de 150 KB con
 * 50 000 elementos costaba 12 s de CPU (revisión de seguridad de la API del
 * Hito G de ULTRON STUDIO, M-2).
 *
 *  1. Primero, solo las reglas SIN comodín de cada lista que tiene uno y de sus
 *     padres (`refs.configs.*.key` → `refs` y `refs.configs`): `array`,
 *     `list`, `max:N`… Son lineales, y si fallan el 422 sale aquí, sin haber
 *     expandido nada.
 *  2. Después, TODAS las reglas, como siempre. Para entonces cada lista cabe en
 *     su tope, así que la expansión está acotada.
 *
 * Una petición cuyas listas caben en su tope responde exactamente igual que con
 * una sola validación: la fase 2 ES esa validación. Lo único que cambia es el
 * 422 de una lista que se pasa del tope: trae los errores de la fase 1, no
 * además los de cada elemento de una lista que de todos modos sobra.
 *
 * Cada lista con comodín TIENE que llevar su tope (`max`, `size` o `between`):
 * sin él, la fase 2 seguiría expandiendo sin límite. Tampoco vale un segundo
 * nivel de comodín (`a.*.b.*`): la fase 1 solo acota el primero. Las dos
 * cosas son errores de código y se dicen en voz alta.
 */
trait ValidatesListSizesFirst
{
    /**
     * `$request->validate($rules)` en dos fases.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed> lo validado, igual que `$request->validate($rules)`
     *
     * @throws ValidationException
     */
    protected function validateListSizesFirst(Request $request, array $rules): array
    {
        $listas = [];
        foreach (array_keys($rules) as $atributo) {
            $atributo = (string) $atributo;
            $comodin = strpos($atributo, '.*');
            if ($comodin === false) {
                continue;
            }

            $lista = substr($atributo, 0, $comodin);
            if (substr_count($atributo, '*') > 1) {
                throw new LogicException("«{$atributo}» tiene dos niveles de comodín: la primera fase solo acota el primero.");
            }
            if (! self::hasSizeCap($rules[$lista] ?? [])) {
                throw new LogicException("La lista «{$lista}» tiene reglas por elemento y ningún tope de tamaño: sin él, validarla no está acotado.");
            }

            $ruta = '';
            foreach (explode('.', $lista) as $tramo) {
                $ruta = $ruta === '' ? $tramo : "{$ruta}.{$tramo}";
                if (array_key_exists($ruta, $rules)) {
                    $listas[$ruta] = $rules[$ruta];
                }
            }
        }

        if ($listas !== []) {
            $request->validate($listas);
        }

        return $request->validate($rules);
    }

    /** ¿Lleva la lista un tope de tamaño (`max`, `size` o `between`)? */
    private static function hasSizeCap(mixed $reglas): bool
    {
        foreach (is_string($reglas) ? explode('|', $reglas) : (array) $reglas as $regla) {
            if (is_string($regla) && preg_match('/^(max|size|between):/', $regla) === 1) {
                return true;
            }
        }

        return false;
    }
}
