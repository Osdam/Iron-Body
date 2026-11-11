<?php

namespace App\Services\UltronStudio;

/**
 * LAS FRASES QUE PROHÍBEN LAS REGLAS S-NNN DE UNA VERSIÓN: una sola semántica
 * para los dos motores que las compilan. Extraída tal cual de
 * `StudioVoiceCompiler::forbiddenPhrases()` (contrato H8; la voz la llama y su
 * `specHash` no cambia) para que los prompts de WhatsApp (compilador de prompts
 * 2, opción A de la auditoría de cierre §3.2) usen exactamente la misma.
 *
 * Una regla aporta frases si:
 *  - su código empieza por `S-` (las R-xx describen el código: no entran);
 *  - está viva (cualquier estado que no sea `archived`; una S-NNN nunca está
 *    activa, CHECK `usr_studio_never_active_chk`);
 *  - tiene al menos una afirmación `forbid text contains` con frases
 *    (`require text` no se aplica, como en el laboratorio); y
 *  - su «si» se cumple en la versión. El «si» se le pregunta a la comprobación
 *    del laboratorio (`lab-regla-<código>` de {@see StudioLabChecks}), que es LA
 *    semántica de las frases del Studio desde G: con una copia de la regla
 *    cuya única frase es una sonda, la prueba sale si y solo si su «si» se
 *    cumple. Una copia del evaluador aquí podría derivar.
 *
 * Cada frase sale en su forma canónica ({@see StudioLabChecks::canonical()}:
 * recortada, espacios colapsados, minúsculas en A–Z y ÁÉÍÓÚÜÑ), tal cual: AQUÍ
 * NO SE VALIDA NI SE DESCARTA NADA (ni una vacía). Cada motor la valida con su
 * propia regla y se niega a compilar si no cabe: la voz con
 * `rule_phrase_invalid`; WhatsApp con el juego cerrado del compilador de
 * prompts. Así nada se pierde en silencio.
 *
 * PURA: sin base de datos, sin configuración, sin reloj, sin log y sin nombrar
 * el motor.
 */
final class StudioRulePhrases
{
    /** El «si» de una regla se pregunta con esta frase de sonda (la misma desde H8). */
    public const WHEN_PROBE = 'sonda del compilador de voz';

    /**
     * Las reglas que aportan frases, en el orden del contenido, cada una con
     * sus frases canónicas en su orden (repetidas incluidas: quien llama decide).
     *
     * @param  array<string,mixed>  $content  el `content` de una versión (`configs`, `definition.edgeIds`, `rules`)
     * @return list<array{code:string,phrases:list<string>}>
     */
    public static function byRule(array $content): array
    {
        $configs = is_array($content['configs'] ?? null) ? $content['configs'] : [];
        $aristas = is_array($content['definition']['edgeIds'] ?? null) ? $content['definition']['edgeIds'] : [];

        $out = [];
        foreach (is_array($content['rules'] ?? null) ? $content['rules'] : [] as $regla) {
            if (! is_array($regla) || ! is_string($regla['code'] ?? null) || ! str_starts_with($regla['code'], 'S-')
                || ($regla['status'] ?? null) === StudioRuleCoherence::STATUS_ARCHIVED || ! is_array($regla['conditions'] ?? null)) {
                continue;
            }
            $propias = [];
            foreach (is_array($regla['conditions']['then'] ?? null) ? $regla['conditions']['then'] : [] as $a) {
                if (! is_array($a) || ($a['kind'] ?? null) !== 'forbid' || ($a['target'] ?? null) !== 'text' || ($a['op'] ?? null) !== 'contains') {
                    continue;
                }
                foreach (is_array($a['phrases'] ?? null) ? $a['phrases'] : [] as $f) {
                    if (is_string($f)) {
                        $propias[] = $f;
                    }
                }
            }
            if ($propias === []) {
                continue;
            }

            $sonda = $regla;
            $sonda['conditions'] = [
                'when' => $regla['conditions']['when'] ?? [],
                'then' => [['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => [self::WHEN_PROBE]]],
            ];
            $hallazgos = StudioLabChecks::check(self::WHEN_PROBE, ['configs' => $configs, 'edgeIds' => $aristas, 'rules' => [$sonda]]);
            if (! in_array(StudioLabChecks::ID_RULE_PREFIX.$regla['code'], array_column($hallazgos, 'id'), true)) {
                continue;
            }

            $out[] = [
                'code' => $regla['code'],
                'phrases' => array_map(static fn (string $f): string => StudioLabChecks::canonical($f), $propias),
            ];
        }

        return $out;
    }

    /**
     * Las frases de {@see byRule()} sin repetir y ordenadas por bytes (el
     * conjunto que compilan la voz y WhatsApp).
     *
     * @param  list<array{code:string,phrases:list<string>}>  $byRule
     * @return list<string>
     */
    public static function sorted(array $byRule): array
    {
        $frases = [];
        foreach ($byRule as $regla) {
            foreach ($regla['phrases'] as $f) {
                $frases[$f] = true;
            }
        }
        $lista = array_map('strval', array_keys($frases));
        sort($lista, SORT_STRING);

        return $lista;
    }

    /**
     * Los códigos de las reglas que aportan frases, por orden de bytes (los
     * que pasan a la clase `prompt` al publicar con la llave de prompts).
     *
     * @param  list<array{code:string,phrases:list<string>}>  $byRule
     * @return list<string>
     */
    public static function codes(array $byRule): array
    {
        $codigos = array_values(array_unique(array_column($byRule, 'code')));
        sort($codigos, SORT_STRING);

        return $codigos;
    }
}
