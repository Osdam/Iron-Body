<?php

namespace App\Services\Outreach\Core;

/**
 * La GRAMÁTICA de condiciones del H9, `outreach-conditions/1` (contrato H9,
 * D9-5; fixture `conditions-cases.json`, secciones `canonicalize`, `reject` y
 * `equivalence`): valida un árbol (lo que da `json_decode(…, true)`) contra el
 * catálogo ({@see OutreachCatalog}) y lo devuelve en su forma CANÓNICA. PURA.
 *
 * NODOS:
 *  - lógicos: `{"all": [...]}` (Y), `{"any": [...]}` (O), `{"not": nodo}`;
 *  - comparación: `{"fact", "args"?, "op", "value"?}`;
 *  - eventos: `{"event": clave del vocabulario (27), "op": exists|absent, "within", "where"?}`;
 *  - recuentos: `{"count": {"of": contact|event:<clave>, "filter"?, "within"}, "op", "value"}`;
 *  - transición: `{"transition": {"entity", "from", "to", "within"}}` (sin operador).
 *
 * NADA SIN TIPAR (T11): toda cadena pertenece a un dominio cerrado; no hay
 * texto libre, expresiones regulares, SQL, nombres de columna, aritmética ni
 * referencias a otro hecho. Los errores son pares `{key, code}` con TODAS las
 * claves a la vez (`key` = la ruta del árbol RECIBIDO desde la raíz, con
 * puntos), de los códigos de {@see CODES}; {@see messages()} los convierte en
 * el 422 de Laravel (clave → mensajes) y nunca repite lo que mandó el usuario.
 *
 * DEPENDENCIAS ENTRE COMPROBACIONES (DF-G3): un hecho o un evento desconocido
 * no deja comprobar su operador ni su valor (sí sus claves de más y, en los
 * eventos, la ventana); un operador inválido no deja comprobar el valor; un
 * nodo más profundo que el límite no se recorre. Lo demás se acumula.
 *
 * LÍMITES (DF-G4): profundidad ≤ 5 y ≤ 50 nodos medidos sobre el árbol
 * RECIBIDO (raíz a profundidad 1; se informan aunque haya otros errores);
 * JSON canónico ≤ 8 192 bytes (solo cuando no hay otro error); ventanas de 1 a
 * 730 días (17 520 h); recuentos de 0 a 100; listas de 1 a 50 valores.
 *
 * CANÓNICA (DF-G5): claves ordenadas; números enteros (`3.0` → 3, `1e1` → 10,
 * `-0.0` → 0); `all`/`any` de un solo hijo colapsados; los hijos NO se
 * reordenan; `not(not(X))` no se simplifica; los valores de `in`/`not_in` en
 * el orden del dominio; las duraciones conservan la unidad escrita; `args`,
 * `where` y `filter` vacíos desaparecen. La huella es el sha256 del JSON
 * canónico ({@see CanonicalJson}, los bytes de `StudioContentHasher`).
 */
final class ConditionCanonicalizer
{
    public const ROOT = 'conditions';

    /** Los códigos de error (cerrados; `errorCodes` del fixture). */
    public const CODES = [
        'ambiguous_node', 'depth_exceeded', 'duplicate_value', 'empty_group', 'empty_values', 'invalid_between', 'invalid_transition',
        'invalid_type', 'missing_key', 'not_an_object', 'operator_not_allowed', 'too_large', 'too_many_nodes', 'too_many_values',
        'unexpected_key', 'unit_not_allowed', 'unknown_event', 'unknown_fact', 'unknown_node', 'unknown_operator', 'value_not_finite',
        'value_not_in_domain', 'value_not_integer', 'value_out_of_range', 'window_out_of_range',
    ];

    public const MESSAGES = [
        'ambiguous_node' => 'Un nodo tiene exactamente un tipo (all, any, not, fact, event, count o transition).',
        'depth_exceeded' => 'La condición pasa de 5 niveles.',
        'duplicate_value' => 'Valor repetido.',
        'empty_group' => 'Un grupo necesita al menos una condición.',
        'empty_values' => 'La lista necesita al menos un valor.',
        'invalid_between' => 'El intervalo no contiene ningún valor.',
        'invalid_transition' => 'Una transición necesita dos estados distintos.',
        'invalid_type' => 'Tipo de valor incorrecto.',
        'missing_key' => 'Falta este campo.',
        'not_an_object' => 'Tiene que ser un objeto.',
        'operator_not_allowed' => 'Ese operador no corresponde a este hecho.',
        'too_large' => 'La condición canónica pasa de 8 192 bytes.',
        'too_many_nodes' => 'La condición pasa de 50 nodos.',
        'too_many_values' => 'La lista pasa de 50 valores.',
        'unexpected_key' => 'Clave no admitida.',
        'unit_not_allowed' => 'Esa unidad es más fina que la del hecho.',
        'unknown_event' => 'El evento no está en el vocabulario de condiciones.',
        'unknown_fact' => 'El hecho no está en el catálogo.',
        'unknown_node' => 'No es un nodo de la gramática.',
        'unknown_operator' => 'Operador desconocido.',
        'value_not_finite' => 'El número no es finito.',
        'value_not_in_domain' => 'El valor no está en el dominio cerrado.',
        'value_not_integer' => 'Tiene que ser un número entero.',
        'value_out_of_range' => 'Fuera del rango permitido.',
        'window_out_of_range' => 'La ventana pasa de 730 días.',
    ];

    /** Los discriminantes de un nodo: exactamente uno por nodo. */
    public const NODE_KINDS = ['all', 'any', 'not', 'fact', 'event', 'count', 'transition'];

    /** Las claves admitidas de cada tipo de nodo. */
    private const NODE_KEYS = [
        'all' => ['all'],
        'any' => ['any'],
        'not' => ['not'],
        'fact' => ['fact', 'args', 'op', 'value'],
        'event' => ['event', 'op', 'within', 'where'],
        'count' => ['count', 'op', 'value'],
        'transition' => ['transition'],
    ];

    /** Un tope de recorrido muy por encima de MAX_NODES: un árbol absurdo no se recorre entero (el 422 ya lo lleva). */
    private const MAX_VISITS = 1000;

    /** Una clave que se puede enseñar en la ruta de un error; si no, `_invalid_key`. */
    private const SAFE_KEY = '/^[A-Za-z0-9_:-]{1,64}$/D';

    /** @var list<array{key: string, code: string}> */
    private array $errors = [];

    private int $nodes = 0;

    /**
     * @param  array<string, list<int|string>>  $dynamic  la instantánea de los dominios dinámicos (`plans`: enteros, `class_types`: textos)
     */
    private function __construct(private readonly array $dynamic) {}

    /**
     * Valida y canonicaliza sin lanzar.
     *
     * @param  array<string, list<int|string>>  $dynamicDomains
     * @return array{canonical: array<string,mixed>|null, json: string|null, hash: string|null, errors: list<array{key: string, code: string}>}
     */
    public static function check(mixed $tree, array $dynamicDomains = [], string $root = self::ROOT): array
    {
        $yo = new self($dynamicDomains);
        $canonica = $yo->node($tree, $root, 1);
        if ($yo->nodes > OutreachCatalog::MAX_NODES) {
            $yo->fail($root, 'too_many_nodes');
        }
        $json = null;
        if ($yo->errors === [] && $canonica !== null) {
            $json = CanonicalJson::encode($canonica);
            if (strlen($json) > OutreachCatalog::MAX_BYTES) {
                $yo->fail($root, 'too_large');
            }
        }
        if ($yo->errors !== [] || $canonica === null) {
            return ['canonical' => null, 'json' => null, 'hash' => null, 'errors' => self::sortErrors($yo->errors)];
        }

        return ['canonical' => $canonica, 'json' => $json, 'hash' => hash('sha256', $json), 'errors' => []];
    }

    /**
     * Los errores como el 422 de Laravel: clave → mensajes (sin repetir).
     *
     * @param  list<array{key: string, code: string}>  $errors
     * @return array<string, list<string>>
     */
    public static function messages(array $errors): array
    {
        $out = [];
        foreach ($errors as $e) {
            $mensaje = self::MESSAGES[$e['code']] ?? 'Valor no admitido.';
            if (! in_array($mensaje, $out[$e['key']] ?? [], true)) {
                $out[$e['key']][] = $mensaje;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Ordena un conjunto de errores por clave y código (bytes), sin repetidos.
     *
     * @param  list<array{key: string, code: string}>  $errors
     * @return list<array{key: string, code: string}>
     */
    public static function sortErrors(array $errors): array
    {
        $unicos = [];
        foreach ($errors as $e) {
            $unicos[$e['key']."\0".$e['code']] = ['key' => $e['key'], 'code' => $e['code']];
        }
        ksort($unicos, SORT_STRING);

        return array_values($unicos);
    }

    // ── Nodos ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function node(mixed $node, string $path, int $depth): ?array
    {
        if ($depth > OutreachCatalog::MAX_DEPTH) {
            $this->fail($path, 'depth_exceeded');

            return null;
        }
        if (++$this->nodes > self::MAX_VISITS) {
            return null;
        }
        if (! is_array($node)) {
            $this->fail($path, 'not_an_object');

            return null;
        }
        if ($node === []) {
            $this->fail($path, 'unknown_node');

            return null;
        }
        if (array_is_list($node)) {
            $this->fail($path, 'not_an_object');

            return null;
        }
        $tipos = array_values(array_intersect(self::NODE_KINDS, array_map('strval', array_keys($node))));
        if ($tipos === []) {
            $this->fail($path, 'unknown_node');

            return null;
        }
        if (count($tipos) > 1) {
            $this->fail($path, 'ambiguous_node');

            return null;
        }
        $tipo = $tipos[0];
        foreach (array_keys($node) as $clave) {
            if (! in_array((string) $clave, self::NODE_KEYS[$tipo], true)) {
                $this->fail($this->child($path, (string) $clave), 'unexpected_key');
            }
        }

        return match ($tipo) {
            'all', 'any' => $this->group($tipo, $node[$tipo], $path, $depth),
            'not' => $this->negation($node['not'], $path, $depth),
            'fact' => $this->fact($node, $path),
            'event' => $this->event($node, $path),
            'count' => $this->count($node, $path),
            'transition' => $this->transition($node['transition'], $path),
        };
    }

    /** @return array<string,mixed>|null */
    private function group(string $tipo, mixed $hijos, string $path, int $depth): ?array
    {
        $ruta = "{$path}.{$tipo}";
        if (! is_array($hijos) || ! array_is_list($hijos)) {
            $this->fail($ruta, 'invalid_type');

            return null;
        }
        if ($hijos === []) {
            $this->fail($ruta, 'empty_group');

            return null;
        }
        $canonicos = [];
        $completo = true;
        foreach ($hijos as $i => $hijo) {
            $c = $this->node($hijo, "{$ruta}.{$i}", $depth + 1);
            $completo = $completo && $c !== null;
            $canonicos[] = $c;
        }
        if (! $completo) {
            return null;
        }

        // Un grupo de un solo hijo es ese hijo (D9-5); como cada nivel colapsa, colapsa recursivamente.
        return count($canonicos) === 1 ? $canonicos[0] : [$tipo => $canonicos];
    }

    /** @return array<string,mixed>|null */
    private function negation(mixed $hijo, string $path, int $depth): ?array
    {
        $c = $this->node($hijo, "{$path}.not", $depth + 1);

        return $c === null ? null : ['not' => $c];
    }

    /** @return array<string,mixed>|null */
    private function fact(array $node, string $path): ?array
    {
        $clave = $node['fact'];
        if (! is_string($clave)) {
            $this->fail("{$path}.fact", 'invalid_type');

            return null;
        }
        $hecho = OutreachCatalog::fact($clave);
        if ($hecho === null) {
            $this->fail("{$path}.fact", 'unknown_fact');

            return null;
        }
        $args = $this->factArgs($hecho, $node, "{$path}.args");

        $tipo = $hecho['type'];
        $op = $this->operator($node, $path, [...OutreachCatalog::OPERATORS[$tipo], ...OutreachCatalog::OPERATORS['any']]);
        if ($op === null) {
            return null;
        }
        if ($op === 'between_days' && $hecho['unit'] !== 'days') {
            // between_days solo en los date_relative en días (DF-G6).
            $this->fail("{$path}.op", 'operator_not_allowed');

            return null;
        }
        $sinValor = in_array($op, OutreachCatalog::OPERATORS['any'], true);
        if ($sinValor && array_key_exists('value', $node)) {
            $this->fail("{$path}.value", 'unexpected_key');

            return null;
        }
        $valor = null;
        if (! $sinValor) {
            if (! array_key_exists('value', $node)) {
                $this->fail("{$path}.value", 'missing_key');

                return null;
            }
            $valor = $this->factValue($hecho, $op, $node['value'], "{$path}.value");
            if ($valor === null) {
                return null;
            }
        }
        if ($args === false) {
            return null;
        }

        $canonico = ['fact' => $clave, 'op' => $op];
        if ($args !== []) {
            $canonico['args'] = $args;
        }
        if (! $sinValor) {
            $canonico['value'] = $valor['v'];
        }

        return $canonico;
    }

    /**
     * Los argumentos de un hecho, canónicos (`[]` si no hay). false si hay errores.
     *
     * @return array<string,mixed>|false
     */
    private function factArgs(array $hecho, array $node, string $path): array|false
    {
        if (! array_key_exists('args', $node)) {
            if ($this->requiredArgs($hecho) !== []) {
                foreach ($this->requiredArgs($hecho) as $nombre) {
                    $this->fail("{$path}.{$nombre}", 'missing_key');
                }

                return false;
            }

            return [];
        }
        $args = $node['args'];
        if ($hecho['args'] === []) {
            if ($args === []) {
                return [];
            }
            $this->fail($path, 'unexpected_key');

            return false;
        }
        if (! is_array($args) || ($args !== [] && array_is_list($args))) {
            $this->fail($path, 'invalid_type');

            return false;
        }
        $esquema = array_column($hecho['args'], null, 'name');
        $bien = true;
        foreach (array_keys($args) as $k) {
            if (! array_key_exists((string) $k, $esquema)) {
                $this->fail($this->child($path, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $canonico = [];
        foreach ($esquema as $nombre => $regla) {
            if (! array_key_exists($nombre, $args)) {
                if ($regla['required']) {
                    $this->fail("{$path}.{$nombre}", 'missing_key');
                    $bien = false;
                }

                continue;
            }
            if ($regla['type'] === 'window') {
                $ventana = $this->window($args[$nombre], "{$path}.{$nombre}", null);
                $bien = $bien && $ventana !== null;
                $canonico[$nombre] = $ventana;

                continue;
            }
            $v = $this->domainValue($args[$nombre], $regla['domain'], "{$path}.{$nombre}");
            if ($v === null) {
                $bien = false;

                continue;
            }
            $canonico[$nombre] = $v['v'];
        }

        return $bien ? $canonico : false;
    }

    /** @return list<string> */
    private function requiredArgs(array $hecho): array
    {
        return array_values(array_map(static fn (array $a): string => $a['name'], array_filter($hecho['args'], static fn (array $a): bool => $a['required'])));
    }

    /**
     * El operador del nodo: falta → `missing_key`; no es texto → `invalid_type`;
     * no es de la gramática → `unknown_operator`; no es de este nodo →
     * `operator_not_allowed`.
     *
     * @param  list<string>  $permitidos
     */
    private function operator(array $node, string $path, array $permitidos): ?string
    {
        if (! array_key_exists('op', $node)) {
            $this->fail("{$path}.op", 'missing_key');

            return null;
        }
        $op = $node['op'];
        if (! is_string($op)) {
            $this->fail("{$path}.op", 'invalid_type');

            return null;
        }
        if (! in_array($op, OutreachCatalog::ALL_OPERATORS, true)) {
            $this->fail("{$path}.op", 'unknown_operator');

            return null;
        }
        if (! in_array($op, $permitidos, true)) {
            $this->fail("{$path}.op", 'operator_not_allowed');

            return null;
        }

        return $op;
    }

    /**
     * El valor de una comparación, canónico y envuelto (`['v' => …]`).
     *
     * @return array{v: mixed}|null
     */
    private function factValue(array $hecho, string $op, mixed $valor, string $path): ?array
    {
        return match ($hecho['type']) {
            OutreachCatalog::TYPE_BOOL => is_bool($valor) ? ['v' => $valor] : $this->failNull($path, 'invalid_type'),
            OutreachCatalog::TYPE_ENUM => $this->enumValue($hecho, $op, $valor, $path),
            OutreachCatalog::TYPE_DATE_RELATIVE => $op === 'between_days' ? $this->betweenDays($valor, $path) : $this->windowValue($valor, $path, $hecho['unit']),
            default => $op === 'between' ? $this->between($valor, $path, $hecho['min'], $hecho['max']) : $this->integer($valor, $path, $hecho['min'], $hecho['max']),
        };
    }

    /** @return array{v: int}|null */
    private function integer(mixed $valor, string $path, ?int $min, ?int $max): ?array
    {
        if (is_bool($valor)) {
            return $this->failNull($path, 'invalid_type');
        }
        [$estado, $n] = CanonicalJson::normalizeNumber($valor);
        $codigo = match ($estado) {
            CanonicalJson::NUMBER_NOT_NUMBER => 'invalid_type',
            CanonicalJson::NUMBER_NOT_FINITE => 'value_not_finite',
            CanonicalJson::NUMBER_NOT_INTEGER => 'value_not_integer',
            default => null,
        };
        if ($codigo !== null) {
            return $this->failNull($path, $codigo);
        }
        if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
            return $this->failNull($path, 'value_out_of_range');
        }

        return ['v' => $n];
    }

    /** @return array{v: array{max: int, maxInclusive: bool, min: int, minInclusive: bool}}|null */
    private function between(mixed $valor, string $path, ?int $min, ?int $max): ?array
    {
        if (! is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            return $this->failNull($path, 'invalid_type');
        }
        $bien = true;
        foreach (array_keys($valor) as $k) {
            if (! in_array((string) $k, ['max', 'maxInclusive', 'min', 'minInclusive'], true)) {
                $this->fail($this->child($path, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $partes = [];
        foreach (['max', 'maxInclusive', 'min', 'minInclusive'] as $k) {
            if (! array_key_exists($k, $valor)) {
                $this->fail("{$path}.{$k}", 'missing_key');
                $bien = false;

                continue;
            }
            if (str_ends_with($k, 'Inclusive')) {
                is_bool($valor[$k]) ? $partes[$k] = $valor[$k] : ($bien = $this->failFalse("{$path}.{$k}", 'invalid_type'));

                continue;
            }
            $n = $this->integer($valor[$k], "{$path}.{$k}", $min, $max);
            $n === null ? $bien = false : $partes[$k] = $n['v'];
        }
        if (! $bien) {
            return null;
        }
        // Vacío: al revés o sin ningún entero entre los extremos (DF-G7).
        $desde = $partes['minInclusive'] ? $partes['min'] : $partes['min'] + 1;
        $hasta = $partes['maxInclusive'] ? $partes['max'] : $partes['max'] - 1;
        if ($desde > $hasta) {
            return $this->failNull($path, 'invalid_between');
        }

        return ['v' => $partes];
    }

    /** @return array{v: array{max: int, min: int}}|null */
    private function betweenDays(mixed $valor, string $path): ?array
    {
        if (! is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            return $this->failNull($path, 'invalid_type');
        }
        $bien = true;
        foreach (array_keys($valor) as $k) {
            if (! in_array((string) $k, ['max', 'min'], true)) {
                $this->fail($this->child($path, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $partes = [];
        foreach (['max', 'min'] as $k) {
            if (! array_key_exists($k, $valor)) {
                $this->fail("{$path}.{$k}", 'missing_key');
                $bien = false;

                continue;
            }
            $n = $this->integer($valor[$k], "{$path}.{$k}", -OutreachCatalog::MAX_BETWEEN_DAYS, OutreachCatalog::MAX_BETWEEN_DAYS);
            $n === null ? $bien = false : $partes[$k] = $n['v'];
        }
        if (! $bien) {
            return null;
        }
        if ($partes['min'] > $partes['max']) {
            return $this->failNull($path, 'invalid_between');
        }

        return ['v' => $partes];
    }

    /** @return array{v: array{n: int, unit: string}}|null */
    private function windowValue(mixed $valor, string $path, string $unidadHecho): ?array
    {
        $v = $this->window($valor, $path, $unidadHecho);

        return $v === null ? null : ['v' => $v];
    }

    /**
     * Una ventana `{n, unit}` (DF-G6): n entero ≥ 1, unidad del conjunto, como
     * mucho 730 días; en un hecho en días no valen las horas.
     *
     * @return array{n: int, unit: string}|null
     */
    private function window(mixed $valor, string $path, ?string $unidadHecho): ?array
    {
        if (! is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            return $this->failNull($path, 'invalid_type');
        }
        $bien = true;
        foreach (array_keys($valor) as $k) {
            if (! in_array((string) $k, ['n', 'unit'], true)) {
                $this->fail($this->child($path, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $n = null;
        if (! array_key_exists('n', $valor)) {
            $bien = $this->failFalse("{$path}.n", 'missing_key');
        } else {
            $r = $this->integer($valor['n'], "{$path}.n", 1, null);
            $r === null ? $bien = false : $n = $r['v'];
        }
        $unidad = null;
        if (! array_key_exists('unit', $valor)) {
            $bien = $this->failFalse("{$path}.unit", 'missing_key');
        } elseif (! is_string($valor['unit'])) {
            $bien = $this->failFalse("{$path}.unit", 'invalid_type');
        } elseif (! in_array($valor['unit'], OutreachCatalog::UNITS, true)) {
            $bien = $this->failFalse("{$path}.unit", 'value_not_in_domain');
        } elseif ($unidadHecho === 'days' && $valor['unit'] === 'hours') {
            $bien = $this->failFalse("{$path}.unit", 'unit_not_allowed');
        } else {
            $unidad = $valor['unit'];
        }
        if (! $bien || $n === null || $unidad === null) {
            return null;
        }
        if ($n * OutreachCatalog::UNIT_HOURS[$unidad] > OutreachCatalog::MAX_WINDOW_DAYS * 24) {
            return $this->failNull($path, 'window_out_of_range');
        }

        return ['n' => $n, 'unit' => $unidad];
    }

    /** @return array{v: mixed}|null */
    private function enumValue(array $hecho, string $op, mixed $valor, string $path): ?array
    {
        $dominio = $hecho['domain'];
        if ($op === 'eq' || $op === 'neq') {
            return $this->domainValue($valor, $dominio, $path);
        }
        if (! is_array($valor) || ($valor !== [] && ! array_is_list($valor))) {
            return $this->failNull($path, 'invalid_type');
        }
        if ($valor === []) {
            return $this->failNull($path, 'empty_values');
        }
        if (count($valor) > OutreachCatalog::MAX_ENUM_VALUES) {
            return $this->failNull($path, 'too_many_values');
        }
        $bien = true;
        $vistos = [];
        foreach ($valor as $i => $v) {
            $r = $this->domainValue($v, $dominio, "{$path}.{$i}");
            if ($r === null) {
                $bien = false;

                continue;
            }
            if (in_array($r['v'], $vistos, true)) {
                $bien = $this->failFalse("{$path}.{$i}", 'duplicate_value');

                continue;
            }
            $vistos[] = $r['v'];
        }
        if (! $bien) {
            return null;
        }

        return ['v' => self::sortInDomain($vistos, $dominio, $this->dynamic)];
    }

    /**
     * Un valor de un dominio (cerrado, una lista; o dinámico, `{dynamic: nombre}`):
     * `plans` es de enteros y `class_types` de textos.
     *
     * @param  list<string>|array{dynamic: string}|null  $dominio
     * @return array{v: int|string}|null
     */
    private function domainValue(mixed $valor, ?array $dominio, string $path): ?array
    {
        $dinamico = is_array($dominio) && isset($dominio['dynamic']) ? $dominio['dynamic'] : null;
        $enteros = $dinamico === OutreachCatalog::DOMAIN_PLANS;
        if ($enteros) {
            if (is_bool($valor) || ! is_int(CanonicalJson::normalizeNumber($valor)[1])) {
                return $this->failNull($path, 'invalid_type');
            }
            $valor = CanonicalJson::normalizeNumber($valor)[1];
        } elseif (! is_string($valor)) {
            return $this->failNull($path, 'invalid_type');
        }
        $lista = $dinamico !== null ? ($this->dynamic[$dinamico] ?? []) : ($dominio ?? []);
        if (! in_array($valor, $lista, true)) {
            return $this->failNull($path, 'value_not_in_domain');
        }

        return ['v' => $valor];
    }

    /**
     * Los valores en el orden del dominio: los cerrados ya van por bytes; los
     * dinámicos, en su orden natural (enteros por valor, textos por bytes), para
     * que la huella no dependa de cómo llegue la instantánea.
     *
     * @param  list<int|string>  $valores
     * @return list<int|string>
     */
    public static function sortInDomain(array $valores, ?array $dominio, array $dynamic = []): array
    {
        if (is_array($dominio) && isset($dominio['dynamic'])) {
            $dominio['dynamic'] === OutreachCatalog::DOMAIN_PLANS ? sort($valores, SORT_NUMERIC) : sort($valores, SORT_STRING);

            return array_values($valores);
        }
        $posicion = array_flip($dominio ?? []);
        usort($valores, static fn ($a, $b): int => ($posicion[$a] ?? PHP_INT_MAX) <=> ($posicion[$b] ?? PHP_INT_MAX));

        return $valores;
    }

    /** @return array<string,mixed>|null */
    private function event(array $node, string $path): ?array
    {
        $clave = $node['event'];
        $evento = is_string($clave) ? OutreachCatalog::event($clave) : null;
        $valido = $evento !== null && $evento['conditionUsable'] === true;
        if (! is_string($clave)) {
            $this->fail("{$path}.event", 'invalid_type');
        } elseif (! $valido) {
            $this->fail("{$path}.event", 'unknown_event');
        }
        $op = $valido ? $this->operator($node, $path, OutreachCatalog::OPERATORS['event']) : null;
        $ventana = array_key_exists('within', $node) ? $this->window($node['within'], "{$path}.within", null) : $this->failNull("{$path}.within", 'missing_key');
        if (! $valido) {
            return null;
        }
        $donde = $this->attrs($node['where'] ?? [], $evento['attrs'], "{$path}.where");
        if ($op === null || $ventana === null || $donde === false) {
            return null;
        }
        $canonico = ['event' => $clave, 'op' => $op, 'within' => $ventana];
        if ($donde !== []) {
            $canonico['where'] = $donde;
        }

        return $canonico;
    }

    /**
     * Un filtro por atributos de un evento (`where`, o el `filter` de un
     * recuento de eventos): solo los atributos que declara el catálogo.
     *
     * @param  list<array{domain: mixed, name: string, type: string}>  $atributos
     * @return array<string, int|string>|false
     */
    private function attrs(mixed $filtro, array $atributos, string $path): array|false
    {
        if ($filtro === [] || $filtro === null) {
            return [];
        }
        if (! is_array($filtro) || array_is_list($filtro)) {
            $this->fail($path, 'invalid_type');

            return false;
        }
        $porNombre = array_column($atributos, null, 'name');
        $bien = true;
        $out = [];
        foreach ($filtro as $k => $v) {
            $k = (string) $k;
            if (! isset($porNombre[$k])) {
                $this->fail($this->child($path, $k), 'unexpected_key');
                $bien = false;

                continue;
            }
            $r = $this->domainValue($v, $porNombre[$k]['domain'], "{$path}.{$k}");
            $r === null ? $bien = false : $out[$k] = $r['v'];
        }

        return $bien ? $out : false;
    }

    /** @return array<string,mixed>|null */
    private function count(array $node, string $path): ?array
    {
        $c = $node['count'];
        $ruta = "{$path}.count";
        $op = $this->operator($node, $path, OutreachCatalog::OPERATORS['count']);
        $valor = null;
        if ($op !== null) {
            $valor = array_key_exists('value', $node)
                ? ($op === 'between' ? $this->between($node['value'], "{$path}.value", 0, OutreachCatalog::MAX_COUNT) : $this->integer($node['value'], "{$path}.value", 0, OutreachCatalog::MAX_COUNT))
                : $this->failNull("{$path}.value", 'missing_key');
        }
        if (! is_array($c) || ($c !== [] && array_is_list($c))) {
            $this->fail($ruta, 'invalid_type');

            return null;
        }
        $bien = true;
        foreach (array_keys($c) as $k) {
            if (! in_array((string) $k, ['of', 'filter', 'within'], true)) {
                $this->fail($this->child($ruta, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $de = $c['of'] ?? null;
        $evento = is_string($de) && str_starts_with($de, 'event:') ? OutreachCatalog::event(substr($de, 6)) : null;
        $esContacto = $de === 'contact';
        $esEvento = $evento !== null && $evento['conditionUsable'] === true;
        if (! array_key_exists('of', $c)) {
            $bien = $this->failFalse("{$ruta}.of", 'missing_key');
        } elseif (! is_string($de)) {
            $bien = $this->failFalse("{$ruta}.of", 'invalid_type');
        } elseif (! $esContacto && ! $esEvento) {
            $bien = $this->failFalse("{$ruta}.of", 'value_not_in_domain');
        }
        $filtro = [];
        if ($esContacto) {
            $filtro = $this->contactFilter($c['filter'] ?? [], "{$ruta}.filter");
        } elseif ($esEvento) {
            $filtro = $this->attrs($c['filter'] ?? [], $evento['attrs'], "{$ruta}.filter");
        }
        $bien = $bien && $filtro !== false;
        $ventana = array_key_exists('within', $c) ? $this->window($c['within'], "{$ruta}.within", null) : $this->failNull("{$ruta}.within", 'missing_key');
        if (! $bien || $op === null || $ventana === null || $valor === null) {
            return null;
        }
        $cuenta = ['of' => $de, 'within' => $ventana];
        if ($filtro !== []) {
            $cuenta['filter'] = $filtro;
        }

        return ['count' => $cuenta, 'op' => $op, 'value' => $valor['v']];
    }

    /** @return array<string,string>|false */
    private function contactFilter(mixed $filtro, string $path): array|false
    {
        if ($filtro === [] || $filtro === null) {
            return [];
        }
        if (! is_array($filtro) || array_is_list($filtro)) {
            $this->fail($path, 'invalid_type');

            return false;
        }
        $bien = true;
        $out = [];
        foreach ($filtro as $k => $v) {
            $k = (string) $k;
            if (! array_key_exists($k, OutreachCatalog::CONTACT_FILTERS)) {
                $this->fail($this->child($path, $k), 'unexpected_key');
                $bien = false;

                continue;
            }
            $r = $this->domainValue($v, OutreachCatalog::CONTACT_FILTERS[$k] ?? OutcomeTable::sortedOutcomes(), "{$path}.{$k}");
            $r === null ? $bien = false : $out[$k] = $r['v'];
        }

        return $bien ? $out : false;
    }

    /** @return array<string,mixed>|null */
    private function transition(mixed $t, string $path): ?array
    {
        $ruta = "{$path}.transition";
        if (! is_array($t) || ($t !== [] && array_is_list($t))) {
            $this->fail($ruta, 'invalid_type');

            return null;
        }
        $bien = true;
        foreach (array_keys($t) as $k) {
            if (! in_array((string) $k, ['entity', 'from', 'to', 'within'], true)) {
                $this->fail($this->child($ruta, (string) $k), 'unexpected_key');
                $bien = false;
            }
        }
        $entidad = $t['entity'] ?? null;
        $estados = null;
        if (! array_key_exists('entity', $t)) {
            $bien = $this->failFalse("{$ruta}.entity", 'missing_key');
        } elseif (! is_string($entidad)) {
            $bien = $this->failFalse("{$ruta}.entity", 'invalid_type');
        } elseif (! array_key_exists($entidad, OutreachCatalog::ENTITIES)) {
            $bien = $this->failFalse("{$ruta}.entity", 'value_not_in_domain');
        } else {
            $estados = OutreachCatalog::ENTITIES[$entidad];
        }
        if ($estados !== null) {
            foreach (['from', 'to'] as $lado) {
                if (! array_key_exists($lado, $t)) {
                    $bien = $this->failFalse("{$ruta}.{$lado}", 'missing_key');
                } elseif ($this->domainValue($t[$lado], $estados, "{$ruta}.{$lado}") === null) {
                    $bien = false;
                }
            }
        }
        $ventana = array_key_exists('within', $t) ? $this->window($t['within'], "{$ruta}.within", null) : $this->failNull("{$ruta}.within", 'missing_key');
        if ($bien && $t['from'] === $t['to']) {
            $bien = $this->failFalse($ruta, 'invalid_transition');
        }
        if (! $bien || $ventana === null) {
            return null;
        }

        return ['transition' => ['entity' => $entidad, 'from' => $t['from'], 'to' => $t['to'], 'within' => $ventana]];
    }

    // ── Errores ─────────────────────────────────────────────────────────────

    private function fail(string $key, string $code): void
    {
        $this->errors[] = ['key' => $key, 'code' => $code];
    }

    /** El error y null: para los `match` y los ternarios. */
    private function failNull(string $key, string $code): null
    {
        $this->fail($key, $code);

        return null;
    }

    /** El error y false. */
    private function failFalse(string $key, string $code): false
    {
        $this->fail($key, $code);

        return false;
    }

    private function child(string $path, string $key): string
    {
        return $path.'.'.(preg_match(self::SAFE_KEY, $key) === 1 ? $key : '_invalid_key');
    }
}
