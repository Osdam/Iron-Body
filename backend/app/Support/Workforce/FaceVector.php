<?php

namespace App\Support\Workforce;

/**
 * Aritmética de los embeddings faciales (MobileFaceNet, 192 valores, norma 1).
 *
 * Sin dependencias de Laravel: es la parte que decide si dos rostros son el
 * mismo, y tiene que poder probarse sola.
 */
final class FaceVector
{
    public const SIZE = 192;

    /**
     * ¿Es esto un rostro que la app pudo haber calculado? Tamaño exacto, todos
     * finitos y norma cercana a 1. La norma NO es un detalle: un vector casi
     * nulo queda a distancia ≈ 1 de cualquier referencia unitaria y, con el
     * umbral en 1.0, coincidía con cualquiera.
     */
    public static function isValid(mixed $vector, float $normMin, float $normMax): bool
    {
        if (! is_array($vector) || count($vector) !== self::SIZE || ! array_is_list($vector)) {
            return false;
        }
        foreach ($vector as $v) {
            if (! is_int($v) && ! is_float($v)) {
                return false;
            }
            if (! is_finite((float) $v)) {
                return false;
            }
        }
        $norm = self::norm($vector);

        return $norm >= $normMin && $norm <= $normMax;
    }

    /** @param list<int|float> $v */
    public static function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += (float) $x * (float) $x;
        }

        return sqrt($sum);
    }

    /**
     * Distancia euclídea; null si no son comparables.
     *
     * @param  list<int|float>  $a
     * @param  list<int|float>  $b
     */
    public static function distance(array $a, array $b): ?float
    {
        if (count($a) !== count($b) || $a === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($a as $i => $av) {
            $d = (float) $av - (float) $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /**
     * Plantilla a partir de varias capturas: la media, renormalizada a norma 1.
     * Más estable que quedarse con una sola foto.
     *
     * @param  list<list<int|float>>  $vectors
     * @return list<float>
     */
    public static function template(array $vectors): array
    {
        $n = count($vectors);
        $mean = array_fill(0, self::SIZE, 0.0);
        foreach ($vectors as $v) {
            foreach ($v as $i => $x) {
                $mean[$i] += (float) $x / $n;
            }
        }
        $norm = self::norm($mean);

        return $norm > 0 ? array_map(fn (float $x): float => $x / $norm, $mean) : $mean;
    }

    /**
     * La mayor distancia entre dos capturas cualesquiera: si es grande, no son
     * la misma cara (o alguna salió mal) y no se enrola.
     *
     * @param  list<list<int|float>>  $vectors
     */
    public static function maxPairwiseDistance(array $vectors): float
    {
        $max = 0.0;
        $count = count($vectors);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $max = max($max, (float) self::distance($vectors[$i], $vectors[$j]));
            }
        }

        return $max;
    }
}
