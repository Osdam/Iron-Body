<?php

namespace App\Services\Marketing\Ultron\Prompts;

/**
 * EL SELLO DE LOS PROMPTS DE UN TURNO: de «declarada» a «aplicada» (Enmienda
 * M-1 del contrato H5; plan de motores §B.6).
 *
 * Cruza dos cosas que llegan al commit por caminos distintos:
 *
 *  - lo OFRECIDO: lo que `decide` le dio a n8n, firmado en el `decide_token`
 *    (`x.sp`, claves cortas: `m` modo, `r` motivo, `h` huella del paquete,
 *    `v` versión, `c` huella de contenido, `e` entrada, `s` seq);
 *  - lo OBSERVADO: el bloque `prompts` del cuerpo del commit, con la huella
 *    que n8n CALCULA sobre los cinco textos que acaba de pasar a OpenAI (no un
 *    eco de lo que le mandaron).
 *
 * y lo resume en `marketing_ai_actions.metadata.studio_prompts`:
 *
 *   {"v":1,"state":"applied",
 *    "offered":{"mode","reason","version","contentHash","entry","seq","bundleHash"} | null,
 *    "observed":{"mode","bundleHash","reason","flowRev","execution"} | null}
 *
 * Los estados (§B.6), en el orden en que se deciden:
 *
 *   sin bloque                                   → not_reported (n8n viejo o rendición)
 *   huella observada null                        → unverified
 *   observado studio, ofrecido studio, = huella  → applied
 *   observado studio, cualquier otra cosa        → mismatch
 *   observado legacy, huella ≠ Legacy            → legacy_drift (constantes Legacy editadas a mano en n8n)
 *   observado legacy, ofrecido studio            → engine_fallback (n8n rechazó el paquete; `reason` dice por qué)
 *   observado legacy, ofrecido legacy o nada     → legacy
 *
 * `stamp_error` no lo calcula esta clase: es lo que escribe el commit si
 * calcular el sello fallara ({@see self::failed()}).
 *
 * NADA DEL COMMIT SE RAMIFICA POR ESTO: es traza. Pura: sin base de datos, sin
 * `config()`, sin reloj y sin log (los avisos los escribe el commit). Nunca
 * lleva textos de prompts, ni ids de conversación o mensaje.
 */
final class UltronPromptStamp
{
    /** Versión de la forma del sello (`metadata.studio_prompts.v`). */
    public const VERSION = 1;

    public const APPLIED = 'applied';

    public const MISMATCH = 'mismatch';

    public const ENGINE_FALLBACK = 'engine_fallback';

    public const LEGACY = 'legacy';

    public const LEGACY_DRIFT = 'legacy_drift';

    public const NOT_REPORTED = 'not_reported';

    public const UNVERIFIED = 'unverified';

    public const STAMP_ERROR = 'stamp_error';

    /** Los dos modos, de lo ofrecido y de lo observado. */
    public const MODE_STUDIO = 'studio';

    public const MODE_LEGACY = 'legacy';

    /**
     * El sello del turno, o null si no hay nada que sellar (ni `sp` en el token
     * ni bloque en el cuerpo): entonces la fila queda exactamente como hoy.
     *
     * @param  mixed  $sp  `x.sp` del token verificado
     * @param  mixed  $block  el bloque `prompts` del cuerpo, ya validado en su forma
     * @return array{v:int,state:string,offered:?array<string,mixed>,observed:?array<string,mixed>}|null
     */
    public static function build(mixed $sp, mixed $block): ?array
    {
        $ofrecido = self::offered($sp);
        $observado = self::observed($block);

        if ($ofrecido === null && $observado === null) {
            return null;
        }

        return [
            'v' => self::VERSION,
            'state' => self::state($ofrecido, $observado),
            'offered' => $ofrecido,
            'observed' => $observado,
        ];
    }

    /**
     * La tabla de estados de §B.6 sobre las formas largas de {@see self::offered()}
     * y {@see self::observed()}.
     *
     * @param  array<string,mixed>|null  $offered
     * @param  array<string,mixed>|null  $observed
     */
    public static function state(?array $offered, ?array $observed): string
    {
        if ($observed === null) {
            return self::NOT_REPORTED;
        }

        $huella = $observed['bundleHash'] ?? null;
        $modo = $observed['mode'] ?? null;
        if (! is_string($huella) || ! in_array($modo, [self::MODE_STUDIO, self::MODE_LEGACY], true)) {
            return self::UNVERIFIED;
        }

        $ofrecidoStudio = is_array($offered) && ($offered['mode'] ?? null) === self::MODE_STUDIO;

        if ($modo === self::MODE_STUDIO) {
            $ofrecida = $offered['bundleHash'] ?? null;

            return $ofrecidoStudio && is_string($ofrecida) && hash_equals($ofrecida, $huella) ? self::APPLIED : self::MISMATCH;
        }

        if (! hash_equals(UltronPromptBase::LEGACY_BUNDLE_HASH, $huella)) {
            return self::LEGACY_DRIFT;
        }

        return $ofrecidoStudio ? self::ENGINE_FALLBACK : self::LEGACY;
    }

    /**
     * `x.sp` del token, en forma larga, o null si no viene.
     *
     * @return array{mode:?string,reason:?string,version:?string,contentHash:?string,entry:?string,seq:?int,bundleHash:?string}|null
     */
    public static function offered(mixed $sp): ?array
    {
        if (! is_array($sp) || $sp === []) {
            return null;
        }

        return [
            'mode' => self::texto($sp['m'] ?? null),
            'reason' => self::texto($sp['r'] ?? null),
            'version' => self::texto($sp['v'] ?? null),
            'contentHash' => self::texto($sp['c'] ?? null),
            'entry' => self::texto($sp['e'] ?? null),
            'seq' => is_int($sp['s'] ?? null) ? $sp['s'] : null,
            'bundleHash' => self::texto($sp['h'] ?? null),
        ];
    }

    /**
     * El bloque `prompts` del commit, en forma larga, o null si no viene (o
     * viene vacío: un objeto `{}` no informa de nada).
     *
     * @return array{mode:?string,bundleHash:?string,reason:?string,flowRev:?string,execution:?string}|null
     */
    public static function observed(mixed $block): ?array
    {
        if (! is_array($block) || $block === []) {
            return null;
        }

        return [
            'mode' => self::texto($block['mode'] ?? null),
            'bundleHash' => self::texto($block['bundle_hash'] ?? null),
            'reason' => self::texto($block['reason'] ?? null),
            'flowRev' => self::texto($block['flow_rev'] ?? null),
            'execution' => self::texto($block['execution_id'] ?? null),
        ];
    }

    /**
     * Lo que escribe el commit si calcular el sello lanzara: dice que hubo
     * algo que sellar y que no se pudo, sin inventar nada de lo que no se leyó.
     *
     * @return array{v:int,state:string,offered:null,observed:null}
     */
    public static function failed(): array
    {
        return ['v' => self::VERSION, 'state' => self::STAMP_ERROR, 'offered' => null, 'observed' => null];
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : null;
    }
}
