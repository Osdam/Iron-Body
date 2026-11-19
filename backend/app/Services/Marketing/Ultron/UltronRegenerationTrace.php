<?php

namespace App\Services\Marketing\Ultron;

/**
 * LA TRAZA DE LAS REESCRITURAS DE UN TURNO (cierre V2, apartado 4,
 * «regenerar antes de sustituir»; plan del arquitecto
 * `plan-arquitecto-a4-regenerar.md` §3 P-7, §4.3 y §6 «Ola 4»).
 *
 * El bloque `regeneration` que manda n8n en el commit —solo si `decide` lo
 * ofreció, con versión del Studio— pasa a `metadata.regeneration` con una
 * forma cerrada:
 *
 *   {drafts, critics, checks, check_errors, rewrites: [{by, codes, issues}], tokens|null, consistent}
 *
 * - `drafts`: cuántas redacciones hubo (1 a 3). Sin ella, o con otro contrato,
 *   no hay traza: un bloque que no se entiende no se guarda a medias.
 * - `critics`, `checks`, `check_errors`: cuántos revisores corrieron (0 a 3),
 *   cuántas comprobaciones del servidor y cuántas fallaron (0 a 2); null si no
 *   vienen o no son de su rango.
 * - `rewrites`: quién pidió cada reescritura (`server` o `critic`), con los
 *   códigos corregibles ({@see UltronRegenerationNotes::CODES}) y los `issues`
 *   del revisor ({@see CriticContract::DIMENSIONS}); como mucho dos.
 * - `tokens`: `{input, output}` si n8n los entrega; null si no.
 * - `consistent`: `drafts` es el intento que el revisor de ESTA fila dice
 *   haber juzgado. Sin revisor en la fila (rendición, relevo, la hora de la
 *   visita), null: no hay con qué cuadrar.
 *
 * ES TRAZA, como el bloque `prompts`: nada del commit se ramifica por ella, y
 * un dato que no cuadra SOLO SE MARCA (`consistent: false`); el texto, la fase
 * y los efectos son los mismos. Sin bloque, null: la fila queda como hoy.
 *
 * Por HTTP el controlador ya rechaza la forma mala (422); esto es la defensa en
 * profundidad para quien llame al servicio sin pasar por él: lo que no es del
 * contrato no entra, y NINGÚN TEXTO viaja a la fila (ni notas, ni borradores,
 * ni nada de la persona): solo códigos y números.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log.
 */
final class UltronRegenerationTrace
{
    /** Quién puede pedir una reescritura: la comprobación del servidor o el revisor de n8n. */
    public const BY = ['server', 'critic'];

    /** Como mucho dos reescrituras (tres redacciones). */
    public const MAX_REWRITES = 2;

    /** Cuántas comprobaciones pide un turno como mucho: una por cada borrador que todavía puede reescribirse. */
    public const MAX_CHECKS = 2;

    /** El techo de tokens por campo (el del contrato, §4.3). */
    public const MAX_TOKENS = 2000000;

    /**
     * @param  mixed  $block  el bloque `regeneration` del commit
     * @param  mixed  $critic  el veredicto tal como queda en la fila (`metadata.critic`), o null si nadie juzgó
     * @return array{drafts:int,critics:?int,checks:?int,check_errors:?int,rewrites:list<array{by:string,codes:list<string>,issues:list<string>}>,tokens:?array{input:?int,output:?int},consistent:?bool}|null
     */
    public static function forMetadata(mixed $block, mixed $critic): ?array
    {
        if (! is_array($block) || ($block['contract'] ?? null) !== UltronDraftCheck::CONTRACT) {
            return null;
        }
        $drafts = self::entero($block['drafts'] ?? null, 1, UltronDraftCheck::MAX_DRAFTS);
        if ($drafts === null) {
            return null;
        }
        $intento = is_array($critic) && is_int($critic['attempt'] ?? null) ? $critic['attempt'] : null;

        return [
            'drafts' => $drafts,
            'critics' => self::entero($block['critics'] ?? null, 0, CriticContract::MAX_ATTEMPTS),
            'checks' => self::entero($block['checks'] ?? null, 0, self::MAX_CHECKS),
            'check_errors' => self::entero($block['check_errors'] ?? null, 0, self::MAX_CHECKS),
            'rewrites' => self::reescrituras($block['rewrites'] ?? null),
            'tokens' => self::tokens($block['tokens'] ?? null),
            'consistent' => $intento === null ? null : $drafts === $intento,
        ];
    }

    /** @return list<array{by:string,codes:list<string>,issues:list<string>}> */
    private static function reescrituras(mixed $valor): array
    {
        $out = [];
        foreach (is_array($valor) && array_is_list($valor) ? $valor : [] as $r) {
            if (! is_array($r) || ! in_array($r['by'] ?? null, self::BY, true)) {
                continue;
            }
            $codigos = [];
            foreach (is_array($r['codes'] ?? null) ? $r['codes'] : [] as $c) {
                if (is_string($c) && in_array($c, UltronRegenerationNotes::CODES, true) && ! in_array($c, $codigos, true)) {
                    $codigos[] = $c;
                }
            }
            $out[] = ['by' => $r['by'], 'codes' => $codigos, 'issues' => CriticContract::issues($r['issues'] ?? null)];
        }

        return array_slice($out, 0, self::MAX_REWRITES);
    }

    /** @return array{input:?int,output:?int}|null */
    private static function tokens(mixed $valor): ?array
    {
        if (! is_array($valor)) {
            return null;
        }

        return [
            'input' => self::entero($valor['input'] ?? null, 0, self::MAX_TOKENS),
            'output' => self::entero($valor['output'] ?? null, 0, self::MAX_TOKENS),
        ];
    }

    private static function entero(mixed $valor, int $min, int $max): ?int
    {
        return is_int($valor) && $valor >= $min && $valor <= $max ? $valor : null;
    }
}
