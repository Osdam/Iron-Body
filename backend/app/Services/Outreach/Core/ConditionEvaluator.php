<?php

namespace App\Services\Outreach\Core;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * El EVALUADOR de condiciones del H9 (contrato H9, D9-5 «Lógica de tres
 * valores» y «Evaluación»; fixture `conditions-cases.json`, secciones
 * `evaluate` y `bogotaDays`). PURO y DETERMINISTA: misma condición, misma
 * foto y mismo «ahora» → mismo valor, misma traza y misma `traceHash` (T12).
 * El instante entra como argumento: el núcleo nunca lee el reloj.
 *
 * TRES VALORES (DF-C1):
 *  - un hecho null o ausente hace `unknown` cualquier operador salvo
 *    `is_known`/`is_unknown` (que nunca dan `unknown`); `neq` y `not_in` sobre
 *    un desconocido dan `unknown`, nunca `true`;
 *  - `all`: `false` si algún hijo es `false`; si no, `unknown` si alguno lo
 *    es; si no, `true`. `any`: `true` si alguno es `true`; si no, `unknown` si
 *    alguno lo es; si no, `false`. `not unknown` = `unknown`.
 *  - Un valor de la foto que no tiene el tipo del hecho se trata como
 *    desconocido (el lado seguro).
 * Solo `true` inscribe: `unknown` deja a la persona fuera (`insufficient_facts`).
 *
 * VENTANAS SOBRE INSTANTES (DF-C3): en horas, `now − at ≤ n·3600 s`; en días y
 * semanas, días de CALENDARIO de Bogotá (la fecha de Bogotá de `now` menos la
 * de `at` ≤ n, o n·7). Lo posterior a `now` no cuenta. Una lista de la foto que
 * es null (fuente sin datos) da `unknown` (DF-C4).
 *
 * FECHAS RELATIVAS (`date_relative`, DF-C5): el valor es un desplazamiento con
 * signo (futuro > 0) en la unidad del hecho: `within_next {n}` ⇔ 0 ≤ v ≤ n;
 * `within_last {n}` ⇔ −n ≤ v ≤ 0; `more_than_ago {n}` ⇔ v < −n;
 * `between_days` ⇔ min ≤ v ≤ max; n convertido a la unidad del hecho.
 *
 * EL HISTORIAL (DF-C8, DF-C9): `contact.count`, `contact.days_since_last` y
 * `contact.last_outcome` se calculan de `contacts`: canal y finalidad por
 * igualdad; `strategy = self` cuenta solo los de esta estrategia y `other`
 * todos los demás (también los de fuera del H9). El último resultado es el del
 * contacto más reciente que casa (empate: el último de la lista); sin
 * resultado todavía, desconocido.
 *
 * LA TRAZA (`traceShape` del fixture): la lista PRE-ORDEN de nodos con las
 * rutas del árbol CANÓNICO; `observed` siempre DERIVADO (el tramo, en la
 * cartera, DF-T6). `traceHash` = sha256 del JSON canónico de la traza.
 */
final class ConditionEvaluator
{
    /** `outreach_decisions.evaluator_version` (§5): sube si cambia la semántica. */
    public const VERSION = 'outreach-evaluator/1';

    public const TRUE = 'true';

    public const FALSE = 'false';

    public const UNKNOWN = 'unknown';

    /** Los hechos del historial que se calculan de `contacts`. */
    public const CONTACT_FACTS = ['contact.count', 'contact.days_since_last', 'contact.last_outcome'];

    public static function evaluate(CanonicalCondition $condition, FactSnapshot $facts, DateTimeInterface $now, string $root = ConditionCanonicalizer::ROOT): ConditionResult
    {
        $traza = [];
        $valor = self::node($condition->tree, $facts, RelativeTime::utc($now), $root, $traza);

        return new ConditionResult($valor, $traza);
    }

    /** El valor de tres estados como texto (`true`, `false`, `unknown`). */
    public static function label(?bool $value): string
    {
        return $value === null ? self::UNKNOWN : ($value ? self::TRUE : self::FALSE);
    }

    /** @param  list<?bool>  $valores */
    public static function conjunction(array $valores): ?bool
    {
        if (in_array(false, $valores, true)) {
            return false;
        }

        return in_array(null, $valores, true) ? null : true;
    }

    /** @param  list<?bool>  $valores */
    public static function disjunction(array $valores): ?bool
    {
        if (in_array(true, $valores, true)) {
            return true;
        }

        return in_array(null, $valores, true) ? null : false;
    }

    /**
     * ¿Cae `at` en la ventana `{n, unit}` que termina en `now` (DF-C3)?
     */
    public static function inWindow(DateTimeInterface $at, DateTimeInterface $now, array $within): bool
    {
        return RelativeTime::withinLast($at, $now, $within['n'], $within['unit']);
    }

    // ── Nodos ───────────────────────────────────────────────────────────────

    /** @param  list<array<string,mixed>>  $traza */
    private static function node(array $node, FactSnapshot $f, DateTimeImmutable $now, string $path, array &$traza): ?bool
    {
        foreach (['all', 'any'] as $grupo) {
            if (isset($node[$grupo])) {
                $i = self::open($traza, $path, $grupo);
                $valores = [];
                foreach ($node[$grupo] as $n => $hijo) {
                    $valores[] = self::node($hijo, $f, $now, "{$path}.{$grupo}.{$n}", $traza);
                }
                $valor = $grupo === 'all' ? self::conjunction($valores) : self::disjunction($valores);
                $traza[$i]['result'] = self::label($valor);

                return $valor;
            }
        }
        if (isset($node['not'])) {
            $i = self::open($traza, $path, 'not');
            $hijo = self::node($node['not'], $f, $now, "{$path}.not", $traza);
            $valor = $hijo === null ? null : ! $hijo;
            $traza[$i]['result'] = self::label($valor);

            return $valor;
        }

        return match (true) {
            isset($node['fact']) => self::fact($node, $f, $now, $path, $traza),
            isset($node['event']) => self::event($node, $f, $now, $path, $traza),
            isset($node['count']) => self::count($node, $f, $now, $path, $traza),
            default => self::transition($node, $f, $now, $path, $traza),
        };
    }

    /** @param  list<array<string,mixed>>  $traza */
    private static function open(array &$traza, string $path, string $kind): int
    {
        $traza[] = ['path' => $path, 'kind' => $kind, 'observed' => null, 'op' => null, 'expected' => null, 'result' => null];

        return count($traza) - 1;
    }

    /** @param  list<array<string,mixed>>  $traza */
    private static function fact(array $node, FactSnapshot $f, DateTimeImmutable $now, string $path, array &$traza): ?bool
    {
        $hecho = OutreachCatalog::fact($node['fact']);
        $args = $node['args'] ?? [];
        $op = $node['op'];
        $valor = in_array($node['fact'], self::CONTACT_FACTS, true)
            ? self::history($node['fact'], $args, $f, $now)
            : self::typed($hecho, $f->fact($node['fact']));

        $resultado = match (true) {
            $op === 'is_known' => $valor !== null,
            $op === 'is_unknown' => $valor === null,
            $valor === null => null,
            default => self::compare($hecho, $op, $valor, $node['value']),
        };

        $entrada = ['path' => $path, 'kind' => 'fact', 'fact' => $node['fact']];
        if ($args !== []) {
            $entrada['args'] = $args;
        }
        $traza[] = $entrada + [
            'observed' => $valor !== null && $hecho['trace'] === 'bucket' ? OutreachCatalog::receivableBucket($valor) : $valor,
            'op' => $op,
            'expected' => $node['value'] ?? null,
            'result' => self::label($resultado),
        ];

        return $resultado;
    }

    /** @param  list<array<string,mixed>>  $traza */
    private static function event(array $node, FactSnapshot $f, DateTimeImmutable $now, string $path, array &$traza): ?bool
    {
        $donde = $node['where'] ?? [];
        $cuantos = null;
        if ($f->events !== null) {
            $cuantos = 0;
            foreach ($f->events as $e) {
                if ($e['key'] === $node['event'] && self::matchesAttrs($e['attrs'], $donde) && self::inWindow(RelativeTime::parseInstant($e['at']), $now, $node['within'])) {
                    $cuantos++;
                }
            }
        }
        $resultado = $cuantos === null ? null : ($node['op'] === 'exists' ? $cuantos > 0 : $cuantos === 0);

        $entrada = ['path' => $path, 'kind' => 'event', 'event' => $node['event']];
        if ($donde !== []) {
            $entrada['where'] = $donde;
        }
        $traza[] = $entrada + ['observed' => $cuantos, 'op' => $node['op'], 'expected' => $node['within'], 'result' => self::label($resultado)];

        return $resultado;
    }

    /** @param  list<array<string,mixed>>  $traza */
    private static function count(array $node, FactSnapshot $f, DateTimeImmutable $now, string $path, array &$traza): ?bool
    {
        $c = $node['count'];
        $filtro = $c['filter'] ?? [];
        if ($c['of'] === 'contact') {
            $cuantos = self::contactCount($f->contacts, $filtro, $c['within'], $now);
        } else {
            $cuantos = null;
            if ($f->events !== null) {
                $cuantos = 0;
                $clave = substr($c['of'], 6);
                foreach ($f->events as $e) {
                    if ($e['key'] === $clave && self::matchesAttrs($e['attrs'], $filtro) && self::inWindow(RelativeTime::parseInstant($e['at']), $now, $c['within'])) {
                        $cuantos++;
                    }
                }
            }
        }
        $resultado = $cuantos === null ? null : self::numeric($node['op'], $cuantos, $node['value']);
        $traza[] = ['path' => $path, 'kind' => 'count', 'count' => $c, 'observed' => $cuantos, 'op' => $node['op'], 'expected' => $node['value'], 'result' => self::label($resultado)];

        return $resultado;
    }

    /** @param  list<array<string,mixed>>  $traza */
    private static function transition(array $node, FactSnapshot $f, DateTimeImmutable $now, string $path, array &$traza): ?bool
    {
        $t = $node['transition'];
        $cuantos = null;
        if ($f->transitions !== null) {
            $cuantos = 0;
            foreach ($f->transitions as $x) {
                if ($x['entity'] === $t['entity'] && $x['from'] === $t['from'] && $x['to'] === $t['to'] && self::inWindow(RelativeTime::parseInstant($x['at']), $now, $t['within'])) {
                    $cuantos++;
                }
            }
        }
        $resultado = $cuantos === null ? null : $cuantos > 0;
        $traza[] = [
            'path' => $path, 'kind' => 'transition', 'transition' => ['entity' => $t['entity'], 'from' => $t['from'], 'to' => $t['to']],
            'observed' => $cuantos, 'op' => 'exists', 'expected' => $t['within'], 'result' => self::label($resultado),
        ];

        return $resultado;
    }

    // ── El historial (DF-C8, DF-C9) ─────────────────────────────────────────

    private static function history(string $hecho, array $args, FactSnapshot $f, DateTimeImmutable $now): int|string|null
    {
        if ($f->contacts === null) {
            return null;
        }
        if ($hecho === 'contact.count') {
            return self::contactCount($f->contacts, array_diff_key($args, ['within' => true]), $args['within'], $now);
        }
        $ultimo = null;
        foreach ($f->contacts as $c) {
            $en = RelativeTime::parseInstant($c['at']);
            // Empate de instante: gana el último de la lista (DF-C8).
            if ($en <= $now && self::matchesContact($c, $args) && ($ultimo === null || $en >= RelativeTime::parseInstant($ultimo['at']))) {
                $ultimo = $c;
            }
        }
        if ($ultimo === null) {
            return null;
        }

        return $hecho === 'contact.days_since_last' ? RelativeTime::daysAgo(RelativeTime::parseInstant($ultimo['at']), $now) : $ultimo['outcome'];
    }

    /** @param  list<array<string,mixed>>|null  $contactos */
    private static function contactCount(?array $contactos, array $filtro, array $ventana, DateTimeImmutable $now): ?int
    {
        if ($contactos === null) {
            return null;
        }
        $n = 0;
        foreach ($contactos as $c) {
            if (self::matchesContact($c, $filtro) && self::inWindow(RelativeTime::parseInstant($c['at']), $now, $ventana)) {
                $n++;
            }
        }

        return $n;
    }

    private static function matchesContact(array $c, array $filtro): bool
    {
        foreach ($filtro as $k => $v) {
            $casa = match ($k) {
                'strategy' => $v === 'self' ? $c['strategy'] === 'self' : $c['strategy'] !== 'self',
                'channel', 'purpose', 'outcome' => $c[$k] === $v,
                default => false,
            };
            if (! $casa) {
                return false;
            }
        }

        return true;
    }

    private static function matchesAttrs(array $attrs, array $filtro): bool
    {
        foreach ($filtro as $k => $v) {
            if (($attrs[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }

    // ── Comparaciones ───────────────────────────────────────────────────────

    /** El valor de la foto si tiene el tipo del hecho; si no, desconocido. */
    private static function typed(array $hecho, mixed $valor): bool|int|string|null
    {
        if ($valor === null) {
            return null;
        }
        $bien = match ($hecho['type']) {
            OutreachCatalog::TYPE_BOOL => is_bool($valor),
            OutreachCatalog::TYPE_ENUM => self::inDomain($hecho['domain'], $valor),
            default => is_int($valor),
        };

        return $bien ? $valor : null;
    }

    private static function inDomain(mixed $dominio, mixed $valor): bool
    {
        if (is_array($dominio) && isset($dominio['dynamic'])) {
            // La instantánea del dominio se comprobó al validar; aquí basta el tipo.
            return $dominio['dynamic'] === OutreachCatalog::DOMAIN_PLANS ? is_int($valor) : is_string($valor);
        }

        return is_string($valor) && in_array($valor, $dominio ?? [], true);
    }

    private static function compare(array $hecho, string $op, mixed $valor, mixed $esperado): bool
    {
        return match ($hecho['type']) {
            OutreachCatalog::TYPE_BOOL => $valor === $esperado,
            OutreachCatalog::TYPE_ENUM => match ($op) {
                'eq' => $valor === $esperado,
                'neq' => $valor !== $esperado,
                'in' => in_array($valor, $esperado, true),
                'not_in' => ! in_array($valor, $esperado, true),
            },
            OutreachCatalog::TYPE_DATE_RELATIVE => self::relative($hecho['unit'], $op, $valor, $esperado),
            default => self::numeric($op, $valor, $esperado),
        };
    }

    private static function numeric(string $op, int $valor, mixed $esperado): bool
    {
        return match ($op) {
            'eq' => $valor === $esperado,
            'neq' => $valor !== $esperado,
            'lt' => $valor < $esperado,
            'lte' => $valor <= $esperado,
            'gt' => $valor > $esperado,
            'gte' => $valor >= $esperado,
            'between' => ($esperado['minInclusive'] ? $valor >= $esperado['min'] : $valor > $esperado['min'])
                && ($esperado['maxInclusive'] ? $valor <= $esperado['max'] : $valor < $esperado['max']),
        };
    }

    /** El operador de fecha sobre el desplazamiento con signo, en la unidad del hecho (DF-C5). */
    private static function relative(string $unidad, string $op, int $v, array $esperado): bool
    {
        if ($op === 'between_days') {
            return $v >= $esperado['min'] && $v <= $esperado['max'];
        }
        $n = $unidad === 'hours' ? $esperado['n'] * OutreachCatalog::UNIT_HOURS[$esperado['unit']] : $esperado['n'] * OutreachCatalog::UNIT_DAYS[$esperado['unit']];

        return match ($op) {
            'within_next' => $v >= 0 && $v <= $n,
            'within_last' => $v <= 0 && $v >= -$n,
            'more_than_ago' => $v < -$n,
        };
    }
}
