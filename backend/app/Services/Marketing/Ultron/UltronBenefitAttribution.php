<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * EL BENEFICIO DE OTRO PLAN (cierre V2, apartado 4, «regenerar antes de
 * sustituir»; plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-4;
 * decisión L-5 del lead).
 *
 * Cuando el modelo redacta la lista de planes, dice para quién es cada uno con
 * los `benefits` de su plan en `active_plans`. Esto comprueba, de forma
 * literal y por frase, que no le cuelga a un plan el beneficio de otro.
 *
 * LA REGLA (literal, del plan): en cada frase del texto EXPANDIDO
 * ({@see UltronReplyFitter::sentences()}) que nombra EXACTAMENTE UN plan (por
 * su nombre; la expansión de su marcador empieza por su nombre), si aparece,
 * por palabras completas, sin tildes y en minúsculas, un beneficio del
 * catálogo de OTRO plan de al menos dos palabras, y ningún beneficio del plan
 * nombrado lo contiene ni está contenido en él, es un beneficio mal atribuido.
 * Las frases con negación («no», «sin», «ni», «excepto», «salvo») no se miran:
 * «El Plan Valera no incluye clases grupales» es una afirmación correcta.
 *
 * LÍMITES DECLARADOS (no se rebajan en silencio; la condición 3 del
 * propietario se cumple SOLO EN PARTE en los beneficios):
 *  - no ve paráfrasis («acceso a todas las máquinas» frente a «Acceso
 *    ilimitado al gimnasio»);
 *  - no ve un beneficio atribuido con un pronombre o una elipsis en la frase
 *    siguiente («Además incluye…»);
 *  - no mira las frases que nombran dos planes o más;
 *  - no ve beneficios inventados que no son de ningún plan (eso es de
 *    `sinServiciosInventados()` del commit, que no cambia);
 *  - un beneficio de una sola palabra no se mira (demasiado genérico).
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. El catálogo
 * (`activePlans()`: `id`, `name`, `benefits`) lo pasa quien llama. Sin
 * `\p{…}`: clases explícitas sobre el texto ya normalizado.
 */
final class UltronBenefitAttribution
{
    /** Una frase con cualquiera de estas palabras no se mira. */
    public const NEGACIONES = ['no', 'sin', 'ni', 'excepto', 'salvo'];

    /** Un beneficio de otro plan cuenta solo si tiene al menos estas palabras. */
    public const MIN_PALABRAS = 2;

    /**
     * Los beneficios mal atribuidos, en el orden de las frases y sin repetir.
     *
     * - `plan_id`: el plan que la frase nombra (al que se le atribuye);
     * - `benefit_plan_id` y `benefit_index`: de quién es el beneficio y su
     *   índice en `benefits` (para la traza: el índice, no el texto);
     * - `benefit`: su texto del catálogo (para la nota al modelo).
     *
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return list<array{plan_id:int,benefit_plan_id:int,benefit_index:int,benefit:string}>
     */
    public static function misattributed(string $texto, array $activePlans): array
    {
        $beneficios = self::beneficiosDelCatalogo($activePlans);
        $hallazgos = [];
        foreach (UltronReplyFitter::sentences($texto) as [$frase]) {
            $palabras = self::palabras($frase);
            if (array_intersect($palabras, self::NEGACIONES) !== []) {
                continue;
            }
            $nombrados = array_values(array_unique(CommercialTurnPolicy::plansNamedIn($frase, $activePlans)));
            if (count($nombrados) !== 1) {
                continue;
            }
            $plan = $nombrados[0];
            $propios = array_column(array_filter($beneficios, fn (array $b) => $b['plan_id'] === $plan), 'palabras');

            foreach ($beneficios as $b) {
                if ($b['plan_id'] === $plan || count($b['palabras']) < self::MIN_PALABRAS || ! self::contiene($palabras, $b['palabras'])) {
                    continue;
                }
                foreach ($propios as $propio) {
                    if (self::contiene($propio, $b['palabras']) || self::contiene($b['palabras'], $propio)) {
                        continue 2;
                    }
                }
                $hallazgo = ['plan_id' => $plan, 'benefit_plan_id' => $b['plan_id'], 'benefit_index' => $b['index'], 'benefit' => $b['texto']];
                if (! in_array($hallazgo, $hallazgos, true)) {
                    $hallazgos[] = $hallazgo;
                }
            }
        }

        return $hallazgos;
    }

    /**
     * Cada beneficio del catálogo con su plan, su índice en `benefits` y sus
     * palabras. Lo que no es un texto con palabras no cuenta (el catálogo lo
     * limpia `benefitsArray()`, pero esto no lo supone).
     *
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return list<array{plan_id:int,index:int,texto:string,palabras:list<string>}>
     */
    private static function beneficiosDelCatalogo(array $activePlans): array
    {
        $out = [];
        foreach ($activePlans as $p) {
            if (! is_array($p['benefits'] ?? null)) {
                continue;
            }
            foreach ($p['benefits'] as $i => $texto) {
                if (! is_string($texto) || ! is_int($i)) {
                    continue;
                }
                $palabras = self::palabras($texto);
                if ($palabras !== []) {
                    $out[] = ['plan_id' => (int) $p['id'], 'index' => $i, 'texto' => trim($texto), 'palabras' => $palabras];
                }
            }
        }

        return $out;
    }

    /**
     * Las palabras del texto, en minúsculas y sin tildes (la normalización de
     * siempre, más la diéresis). Sin `\p{L}`: tras normalizar, letras y cifras
     * ASCII; cualquier otra cosa separa.
     *
     * @return list<string>
     */
    private static function palabras(string $texto): array
    {
        preg_match_all('/[a-z0-9]+/', strtr(SalesAgentDecisionSchema::normalize($texto), ['ü' => 'u']), $m);

        return $m[0];
    }

    /**
     * ¿`$aguja` aparece en `$pajar` como secuencia de palabras completas y
     * seguidas?
     *
     * @param  list<string>  $pajar
     * @param  list<string>  $aguja
     */
    private static function contiene(array $pajar, array $aguja): bool
    {
        $n = count($aguja);
        if ($n === 0 || $n > count($pajar)) {
            return false;
        }
        for ($i = 0; $i + $n <= count($pajar); $i++) {
            if (array_slice($pajar, $i, $n) === $aguja) {
                return true;
            }
        }

        return false;
    }
}
