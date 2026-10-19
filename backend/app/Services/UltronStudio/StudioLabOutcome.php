<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * La agregación del laboratorio (contrato H4 §A4): pura y determinista. LA IA
 * NO DECIDE EL RESULTADO: el juez opina, las pruebas deterministas comprueban
 * y esta clase junta las dos cosas con reglas fijas.
 *
 * Un caso es:
 *  - `fail` si hay un hallazgo `blocking`, un criterio `must` o `must_not` en
 *    `fail`, o una R-xx en `fail`;
 *  - si no, `review` si hay un `warning`, algún `unclear` (criterio o regla),
 *    un criterio `should` en `fail`, una S-NNN en `fail` o el juez falló (sin
 *    opinión de la IA, el caso llega como mucho a `review`);
 *  - si no, `pass`.
 * Un hallazgo bloqueante gana SIEMPRE al juez. Los hallazgos `info` (también
 * `motor-no-disponible`) no cuentan.
 *
 * La propuesta de una ejecución solo existe si está `completed` y en modo
 * `openai`: `reject` si algún caso es `fail`; `review` si alguno es `review`,
 * `error` o `skipped`; `approve` si todos pasan. NO ES UN VEREDICTO: el
 * veredicto lo emite una persona.
 */
final class StudioLabOutcome
{
    public const PASS = 'pass';

    public const REVIEW = 'review';

    public const FAIL = 'fail';

    public const OUTCOMES = [self::PASS, self::REVIEW, self::FAIL];

    public const PROPOSAL_APPROVE = 'approve';

    public const PROPOSAL_REVIEW = 'review';

    public const PROPOSAL_REJECT = 'reject';

    public const PROPOSALS = [self::PROPOSAL_APPROVE, self::PROPOSAL_REVIEW, self::PROPOSAL_REJECT];

    public const PROPOSAL_LABEL = 'Propuesta del laboratorio: no es un veredicto';

    public const CASE_DONE = 'done';

    public const CASE_ERROR = 'error';

    public const CASE_SKIPPED = 'skipped';

    public const RUN_COMPLETED = 'completed';

    public const EFFECT_CONTINUE = 'continue';

    public const EFFECT_DELAY = 'delay';

    /** Prefijo del efecto que para la ejecución: `stop:<stopReason>`. */
    public const EFFECT_STOP = 'stop:';

    public const STOP_PROVIDER_ERRORS = 'provider_errors';

    /**
     * El resultado de un caso `done`.
     *
     * @param  list<array<string,mixed>>  $checks  los hallazgos de las dos fuentes (`{id, source, level, …}`)
     * @param  array<string,mixed>|null  $judge  la opinión normalizada ({@see StudioLabPrompts::parseJudge()}); null = el juez falló
     * @param  list<array<string,mixed>>  $criteria  los criterios del escenario (`{id, kind, …}`)
     */
    public static function forCase(array $checks, ?array $judge, array $criteria): string
    {
        $niveles = array_map(static fn (mixed $h): mixed => is_array($h) ? ($h['level'] ?? null) : null, $checks);
        if (in_array(StudioLabChecks::LEVEL_BLOCKING, $niveles, true)) {
            return self::FAIL;
        }

        $revisar = in_array(StudioLabChecks::LEVEL_WARNING, $niveles, true) || $judge === null;

        if ($judge !== null) {
            $veredictos = [];
            foreach (is_array($judge['criteria'] ?? null) ? $judge['criteria'] : [] as $c) {
                if (is_array($c) && is_string($c['id'] ?? null)) {
                    $veredictos[$c['id']] = $c['verdict'] ?? null;
                }
            }
            foreach ($criteria as $c) {
                $id = is_array($c) ? ($c['id'] ?? null) : null;
                // Un criterio sin opinión cuenta como `unclear`.
                $veredicto = is_string($id) ? ($veredictos[$id] ?? 'unclear') : 'unclear';
                $tipo = is_array($c) ? ($c['kind'] ?? null) : null;
                if ($veredicto === 'fail' && ($tipo === 'must' || $tipo === 'must_not')) {
                    return self::FAIL;
                }
                if ($veredicto === 'unclear' || $veredicto === 'fail') {
                    $revisar = true;
                }
            }

            foreach (is_array($judge['rules'] ?? null) ? $judge['rules'] : [] as $r) {
                $codigo = is_array($r) && is_string($r['code'] ?? null) ? $r['code'] : '';
                $veredicto = is_array($r) ? ($r['verdict'] ?? null) : null;
                if ($veredicto === 'fail' && str_starts_with($codigo, 'R-')) {
                    return self::FAIL;
                }
                if ($veredicto === 'unclear' || $veredicto === 'fail') {
                    $revisar = true;
                }
            }
        }

        return $revisar ? self::REVIEW : self::PASS;
    }

    /**
     * Qué le pasa a la ejecución tras el fallo de un caso (tabla de A5,
     * constante de A1 y Enmienda 1, decisión 2), sabiendo cuántos casos
     * inmediatamente anteriores fallaron con una clase de la racha
     * (`$streakBefore`). `$class` es la del actor, o la del juez si el actor
     * respondió (la del juez cuenta igual: Enmienda 1, decisión 3):
     *  - sin fallo, sigue;
     *  - una clase con parada propia (`auth`, `quota`, `bad_request`) para con
     *    ella al instante, aunque venga en racha (un 401 tras un 5xx es
     *    `provider_auth`);
     *  - una de la racha (`timeout`, `connection`, `server`, `refusal`,
     *    `incomplete`, `invalid_output`) que hace la segunda seguida para con
     *    `provider_errors`;
     *  - un 429 de ritmo retrasa el siguiente paso 30 s y no cuenta;
     *  - lo demás sigue.
     *
     * @return string `continue`, `delay` o `stop:<stopReason>`
     */
    public static function callEffect(?string $class, int $streakBefore): string
    {
        if ($class === null) {
            return self::EFFECT_CONTINUE;
        }
        $politica = self::policy($class);
        if ($politica['stop'] !== null) {
            return self::EFFECT_STOP.$politica['stop'];
        }
        if ($politica['consecutive'] && max(0, $streakBefore) + 1 >= StudioLabConfig::MAX_CONSECUTIVE_PROVIDER_ERRORS) {
            return self::EFFECT_STOP.self::STOP_PROVIDER_ERRORS;
        }

        return $politica['delaySeconds'] > 0 ? self::EFFECT_DELAY : self::EFFECT_CONTINUE;
    }

    /**
     * La racha tras un caso (Enmienda 1, decisión 2): un caso sin fallo de
     * proveedor la reinicia; una clase de la racha la hace crecer; las demás
     * (`rate_limited`, que no cuenta, y las que paran la ejecución) la dejan
     * como estaba.
     */
    public static function nextStreak(?string $class, int $streakBefore): int
    {
        if ($class === null) {
            return 0;
        }

        return self::policy($class)['consecutive'] ? max(0, $streakBefore) + 1 : max(0, $streakBefore);
    }

    /**
     * Qué se cobra de una llamada: `usage` si fue bien, y si no, lo que diga
     * la tabla de A5 (`reserve`, `zero` o `usage`).
     */
    public static function costMode(?string $class): string
    {
        return $class === null ? StudioLabModelClient::COST_USAGE : self::policy($class)['cost'];
    }

    /**
     * @return array{cost:string,stop:?string,delaySeconds:int,consecutive:bool}
     */
    private static function policy(string $class): array
    {
        $politica = StudioLabModelClient::POLICY[$class] ?? null;
        if ($politica === null) {
            throw new InvalidArgumentException('Clase de fallo desconocida.');
        }

        return $politica;
    }

    /**
     * La propuesta de una ejecución, o null si no le toca tenerla.
     *
     * @param  list<array{status:string,outcome:?string}>  $cases
     */
    public static function proposal(string $runStatus, ?string $mode, array $cases): ?string
    {
        if ($runStatus !== self::RUN_COMPLETED || $mode !== StudioLabConfig::MODE_OPENAI || $cases === []) {
            return null;
        }

        $revisar = false;
        foreach ($cases as $c) {
            $estado = $c['status'] ?? null;
            $resultado = $c['outcome'] ?? null;
            if ($estado === self::CASE_DONE && $resultado === self::FAIL) {
                return self::PROPOSAL_REJECT;
            }
            if ($estado !== self::CASE_DONE || $resultado !== self::PASS) {
                $revisar = true;
            }
        }

        return $revisar ? self::PROPOSAL_REVIEW : self::PROPOSAL_APPROVE;
    }
}
