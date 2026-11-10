<?php

namespace App\Services\Outreach\Core;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * La TRAZA de una decisión y su huella reproducible (contrato H9, D9-5,
 * D9-12 «Reproducibilidad», §5, §6.2 `trace_json` ≤ 16 KB; T12). PURA.
 *
 * Reúne lo que explica una decisión —las trazas de las condiciones, la
 * escalera, la selección de canal, la prioridad y el seguimiento— con lo que la
 * produjo: `evaluatorVersion`, `catalogVersion`, la huella del catálogo, la de
 * la foto de hechos (`factsHash`) y el instante (`evaluatedAt`). La
 * `traceHash` es el sha256 de su JSON canónico: la misma versión, la misma
 * foto y el mismo instante dan la misma huella, también con `outreach:replay`.
 *
 * SIN DATOS PERSONALES (protección 10; T5): todo texto de la traza tiene que
 * ser un código o un valor de dominio (empieza por letra o guion bajo, sin
 * espacios ni signos), una ruta, un instante UTC, una huella hexadecimal, un
 * uuid o un tramo de cartera. Un teléfono, un correo o una frase hacen lanzar:
 * es un fallo de quien la construye, nunca algo que se guarde.
 *
 * TOPE: si el JSON canónico pasa de 16 KB se recorta de forma DETERMINISTA (las
 * trazas de las condiciones se quedan en su raíz y se marca `truncated`); la
 * huella es la de lo que se guarda, así que `replay` llega a lo mismo.
 */
final class DecisionTrace
{
    public const FORMAT = 'outreach-trace/1';

    public const MAX_BYTES = 16384;

    /** `outreach_decisions.facts_json` (§6.2). */
    public const MAX_FACTS_BYTES = 4096;

    /** `outreach_decisions.kind` (§6.2). */
    public const KINDS = ['enroll', 'exclude', 'deny', 'defer', 'plan', 'cancel', 'exit', 'handoff'];

    public const PARTS = ['policy', 'channel', 'priority', 'followUp', 'gate'];

    private const TOKEN = '/^[A-Za-z_][A-Za-z0-9_.:\-\/]{0,127}$/D';

    private const HEX = '/^[0-9a-f]{64}$/D';

    private const PATH = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_:\-]+){0,32}$/D';

    /**
     * Monta la traza canónica de una decisión.
     *
     * @param  array{kind: string, level?: string|null, reasons?: list<string>, retryAt?: string|null}  $decision
     * @param  array<string, ConditionResult|null>  $conditions  `audience`, `exclusions`, `success`, `guard`…
     * @param  array<string,mixed>  $extra  partes de {@see PARTS} (la salida de la escalera, del selector…)
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException si algo no es derivado o la decisión no es del conjunto cerrado
     */
    public static function build(array $decision, FactSnapshot $facts, DateTimeInterface $now, array $conditions = [], array $extra = []): array
    {
        if (! in_array($decision['kind'] ?? null, self::KINDS, true)) {
            throw new InvalidArgumentException('Tipo de decisión fuera del conjunto cerrado.');
        }
        $traza = [
            'format' => self::FORMAT,
            'evaluatorVersion' => ConditionEvaluator::VERSION,
            'catalogVersion' => OutreachCatalog::VERSION,
            'catalogHash' => OutreachCatalog::hash(),
            'factsHash' => $facts->hash(),
            'evaluatedAt' => RelativeTime::format($now),
            'decision' => [
                'kind' => $decision['kind'],
                'level' => $decision['level'] ?? null,
                'reasons' => $decision['reasons'] ?? [],
                'retryAt' => $decision['retryAt'] ?? null,
            ],
        ];
        foreach ($conditions as $nombre => $resultado) {
            if ($resultado !== null) {
                $traza['conditions'][$nombre] = ['result' => $resultado->label(), 'trace' => $resultado->trace];
            }
        }
        foreach ($extra as $clave => $valor) {
            if (! in_array($clave, self::PARTS, true)) {
                throw new InvalidArgumentException('Parte de la traza desconocida.');
            }
            $traza[$clave] = $valor;
        }
        self::assertDerived($traza);

        return self::fit($traza);
    }

    /** El JSON canónico (lo que se guarda en `trace_json`). */
    public static function json(array $trace): string
    {
        return CanonicalJson::encode($trace);
    }

    /** `trace_hash`. */
    public static function hash(array $trace): string
    {
        return CanonicalJson::hash($trace);
    }

    /**
     * Comprueba que todo texto (valor o clave) de la traza sea derivado.
     *
     * @throws InvalidArgumentException
     */
    public static function assertDerived(mixed $value, string $path = '$'): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_string($value)) {
            if (self::isDerivedString($value)) {
                return;
            }

            throw new InvalidArgumentException("La traza lleva un texto que no es un código ni un valor derivado ({$path}).");
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('La traza lleva un valor de tipo '.get_debug_type($value)." ({$path}).");
        }
        foreach ($value as $clave => $item) {
            if (is_string($clave) && ! self::isDerivedString($clave)) {
                throw new InvalidArgumentException("La traza lleva una clave que no es un código ({$path}).");
            }
            self::assertDerived($item, "{$path}.{$clave}");
        }
    }

    private static function isDerivedString(string $v): bool
    {
        return preg_match(self::TOKEN, $v) === 1 || preg_match(self::PATH, $v) === 1 || preg_match(self::HEX, $v) === 1
            || preg_match(OutreachCatalog::UUID_PATTERN, $v) === 1 || RelativeTime::isInstant($v)
            || in_array($v, array_column(OutreachCatalog::RECEIVABLE_BUCKETS, 'label'), true);
    }

    /** @return array<string,mixed> */
    private static function fit(array $traza): array
    {
        if (strlen(self::json($traza)) <= self::MAX_BYTES) {
            return $traza;
        }
        foreach ($traza['conditions'] ?? [] as $nombre => $c) {
            $traza['conditions'][$nombre]['trace'] = array_slice($c['trace'], 0, 1);
        }
        $traza['truncated'] = true;
        if (strlen(self::json($traza)) <= self::MAX_BYTES) {
            return $traza;
        }
        unset($traza['conditions']);
        foreach (self::PARTS as $parte) {
            if (isset($traza[$parte]) && strlen(self::json($traza)) > self::MAX_BYTES) {
                $traza[$parte] = ['truncated' => true];
            }
        }

        return $traza;
    }
}
