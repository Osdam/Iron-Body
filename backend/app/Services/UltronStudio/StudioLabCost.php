<?php

namespace App\Services\UltronStudio;

/**
 * El coste del laboratorio en micro-US$ y SOLO CON ENTEROS (contrato H4 §A3).
 * Puro: ni base, ni configuración, ni reloj.
 *
 *  - Precio: micro-US$ por millón de tokens, `P = precio_usd × 10⁶`
 *    (0,40 → 400 000), leído del texto decimal sin pasar por un float.
 *  - El coste de `T` tokens es `ceil(T × P / 10⁶)`, para cada par
 *    (tokens, precio) por separado.
 *  - Tokens de entrada estimados por llamada:
 *    `ceil(bytes(instrucciones + entrada + esquema) / 3) + 50`. Para el juez,
 *    la respuesta del actor cuenta con su máximo: `actorMaxOutputTokens × 4`
 *    bytes ({@see StudioLabPrompts::judgeEstimateInput()}).
 *  - `maxMicros` = Σ de las llamadas de (entrada estimada × Pin +
 *    `max_output_tokens` × Pout). `expectedMicros` igual con la MITAD de la
 *    salida (exacta: `ceil(max × Pout / 2·10⁶)`), y es solo informativo.
 *  - Coste real: `usage.input_tokens × Pin + usage.output_tokens × Pout`. La
 *    salida incluye el razonamiento y los tokens en caché se cobran como
 *    entrada normal: sobreestima a propósito.
 *  - Lo que el real supere a la reserva queda en `overrunMicros`.
 */
final class StudioLabCost
{
    public const MICROS_PER_USD = 1_000_000;

    /** Los precios van por millón de tokens. */
    public const TOKENS_PER_PRICE = 1_000_000;

    /** La estimación cuenta 3 bytes por token (constante del contrato, §A1). */
    public const BYTES_PER_TOKEN = 3;

    /** Lo que se suma a cada llamada por el envoltorio de la petición. */
    public const CALL_OVERHEAD_TOKENS = 50;

    /** La respuesta del actor, al estimar el juez, cuenta 4 bytes por cada token de su máximo. */
    public const REPLY_BYTES_PER_TOKEN = 4;

    /** Un decimal con hasta 6 cifras (`^\d+(\.\d{1,6})?$`). */
    public const USD_PATTERN = '/^\d+(\.\d{1,6})?$/';

    /**
     * Cifras enteras como mucho (hasta 999 999 US$): más no es un precio ni un
     * presupuesto, es un error de escritura. Con este tope el producto de
     * {@see tokensMicros()} cabe siempre en un entero de 64 bits.
     */
    private const MAX_USD_DIGITS = 6;

    /** Cómo se mide el esquema: la misma codificación con la que se envía. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Un importe en US$ escrito como decimal (`"0.40"`, `"2"`, `2`) en
     * micro-US$, sin pasar por un float. null si no es un decimal válido.
     */
    public static function microsFromUsd(mixed $usd): ?int
    {
        if (is_int($usd)) {
            $usd = (string) $usd;
        }
        if (! is_string($usd)) {
            return null;
        }
        $usd = trim($usd);
        if (preg_match(self::USD_PATTERN, $usd) !== 1) {
            return null;
        }
        [$entera, $decimales] = array_pad(explode('.', $usd, 2), 2, '');
        $entera = ltrim($entera, '0');
        if (strlen($entera) > self::MAX_USD_DIGITS) {
            return null;
        }

        return (int) ($entera === '' ? '0' : $entera) * self::MICROS_PER_USD + (int) str_pad($decimales, 6, '0');
    }

    /**
     * `ceil(T × P / 10⁶)`: lo que cuestan `$tokens` a `$pricePerMillion`
     * micro-US$ por millón. Se parte `T` en millones y resto para que el
     * producto no desborde aunque el proveedor informe una cifra absurda.
     */
    public static function tokensMicros(int $tokens, int $pricePerMillion): int
    {
        $t = max(0, $tokens);
        $p = max(0, $pricePerMillion);

        return intdiv($t, self::TOKENS_PER_PRICE) * $p + self::ceilDiv(($t % self::TOKENS_PER_PRICE) * $p, self::TOKENS_PER_PRICE);
    }

    /** Los bytes de una llamada: instrucciones, entrada y esquema, en UTF-8. */
    public static function callBytes(string $instructions, string $input, array $schema): int
    {
        return strlen($instructions) + strlen($input) + strlen((string) json_encode($schema, self::JSON_FLAGS));
    }

    /** `ceil(bytes / 3) + 50`. */
    public static function estimatedInputTokens(int $bytes): int
    {
        return self::ceilDiv(max(0, $bytes), self::BYTES_PER_TOKEN) + self::CALL_OVERHEAD_TOKENS;
    }

    /**
     * Los tokens de entrada estimados del JUEZ: los bytes de su entrada sin la
     * respuesta del actor, más esa respuesta contada con su máximo
     * (`actorMaxOutputTokens × 4` bytes).
     */
    public static function judgeInputTokens(int $bytesWithoutReply, int $actorMaxOutputTokens): int
    {
        return self::estimatedInputTokens(max(0, $bytesWithoutReply) + max(0, $actorMaxOutputTokens) * self::REPLY_BYTES_PER_TOKEN);
    }

    /**
     * La estimación de UNA llamada: el máximo con toda la salida y lo esperado
     * con la mitad.
     *
     * @param  array{input:int,output:int}  $prices
     * @return array{expectedMicros:int,maxMicros:int}
     */
    public static function callEstimate(int $inputTokens, int $maxOutputTokens, array $prices): array
    {
        $entrada = self::tokensMicros($inputTokens, $prices['input']);

        return [
            'expectedMicros' => $entrada + self::ceilDiv(max(0, $maxOutputTokens) * max(0, $prices['output']), 2 * self::TOKENS_PER_PRICE),
            'maxMicros' => $entrada + self::tokensMicros($maxOutputTokens, $prices['output']),
        ];
    }

    /**
     * La suma de varias estimaciones (las llamadas de un caso, o los casos de
     * una ejecución).
     *
     * @param  list<array{expectedMicros:int,maxMicros:int}>  $estimates
     * @return array{expectedMicros:int,maxMicros:int}
     */
    public static function sum(array $estimates): array
    {
        $out = ['expectedMicros' => 0, 'maxMicros' => 0];
        foreach ($estimates as $e) {
            $out['expectedMicros'] += $e['expectedMicros'];
            $out['maxMicros'] += $e['maxMicros'];
        }

        return $out;
    }

    /**
     * La estimación de un caso: la llamada del actor y la del juez, con los
     * tokens de entrada ya estimados.
     *
     * @param  array{input:int,output:int}  $prices
     * @return array{expectedMicros:int,maxMicros:int}
     */
    public static function caseEstimate(int $actorInputTokens, int $actorMaxOutputTokens, int $judgeInputTokens, int $judgeMaxOutputTokens, array $prices): array
    {
        return self::sum([
            self::callEstimate($actorInputTokens, $actorMaxOutputTokens, $prices),
            self::callEstimate($judgeInputTokens, $judgeMaxOutputTokens, $prices),
        ]);
    }

    /**
     * La estimación de un caso con los prompts REALES: construye la entrada
     * del actor y la del juez (sin la respuesta) con {@see StudioLabPrompts},
     * cuenta sus bytes UTF-8 con instrucciones y esquema, y suma las dos
     * llamadas a su máximo. `maxMicros` es el `reserved_micros` del caso.
     *
     * @param  array<string,mixed>  $target  la instantánea del objetivo (`{configs, rules}`)
     * @param  array<string,mixed>  $scenario
     * @param  array{input:int,output:int}  $prices
     * @param  array<string,mixed>|null  $catalog
     * @return array{expectedMicros:int,maxMicros:int,actorInputTokens:int,judgeInputTokens:int}
     *
     * @throws \InvalidArgumentException si algo del personal no pasa el filtro de datos personales
     */
    public static function estimateScenario(array $target, array $scenario, int $actorMaxOutputTokens, int $judgeMaxOutputTokens, array $prices, ?array $catalog = null): array
    {
        $actor = self::estimatedInputTokens(self::callBytes(StudioLabPrompts::ACTOR_INSTRUCTIONS, StudioLabPrompts::actorInput($target, $scenario, $catalog), StudioLabPrompts::actorSchema()));
        $juez = self::judgeInputTokens(self::callBytes(StudioLabPrompts::JUDGE_INSTRUCTIONS, StudioLabPrompts::judgeEstimateInput($target, $scenario, $catalog), StudioLabPrompts::judgeSchema()), $actorMaxOutputTokens);

        return self::caseEstimate($actor, $actorMaxOutputTokens, $juez, $judgeMaxOutputTokens, $prices) + ['actorInputTokens' => $actor, 'judgeInputTokens' => $juez];
    }

    /**
     * El coste real de una llamada con el `usage` que devolvió el proveedor.
     *
     * @param  array{inputTokens:int,outputTokens:int}  $usage
     * @param  array{input:int,output:int}  $prices
     */
    public static function actualMicros(array $usage, array $prices): int
    {
        return self::tokensMicros($usage['inputTokens'], $prices['input']) + self::tokensMicros($usage['outputTokens'], $prices['output']);
    }

    /**
     * Lo que se carga por UNA llamada según cómo terminó (tabla de A5,
     * {@see StudioLabModelClient::POLICY}): con éxito o con una clase que cobra
     * por `usage`, lo que diga el `usage`; un timeout, el máximo de la llamada
     * (no se sabe si se cobró); lo demás, 0. Sin `usage` donde haría falta
     * (un 200 sin él), también el máximo: nunca se subestima.
     *
     * @param  ?string  $class  null = la llamada fue bien
     * @param  array{inputTokens:int,outputTokens:int}|null  $usage
     * @param  int  $callMaxMicros  el máximo estimado de esa llamada
     * @param  array{input:int,output:int}  $prices
     */
    public static function callMicros(?string $class, ?array $usage, int $callMaxMicros, array $prices): int
    {
        $regla = $class === null
            ? StudioLabModelClient::COST_USAGE
            : (StudioLabModelClient::POLICY[$class]['cost'] ?? StudioLabModelClient::COST_RESERVATION);

        return match ($regla) {
            StudioLabModelClient::COST_ZERO => 0,
            StudioLabModelClient::COST_USAGE => $usage !== null ? self::actualMicros($usage, $prices) : max(0, $callMaxMicros),
            default => max(0, $callMaxMicros),
        };
    }

    /** Lo que el real superó a la reserva (0 si no la superó). */
    public static function overrunMicros(int $actualMicros, int $reservedMicros): int
    {
        return max(0, $actualMicros - $reservedMicros);
    }

    /** `ceil(a / b)` con enteros no negativos. */
    private static function ceilDiv(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }
}
