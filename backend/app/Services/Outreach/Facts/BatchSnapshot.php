<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use InvalidArgumentException;

/**
 * LA FOTO DE HECHOS DE UN LOTE de personas en un instante (contrato H9, D9-8,
 * D9-12, D9-16). Inmutable, PURA y serializable: misma fecha de referencia y
 * misma base dan los mismos bytes (`canonicalJson()`) y la misma huella
 * (`hash()`).
 *
 * LA UNIÓN CON EL NÚCLEO: el evaluador de B9-core recibe la foto de UNA
 * persona (`Core\FactSnapshot`: referencia → valor derivado, la forma que se
 * guarda en `outreach_decisions.facts_json` y que `replay` reevalúa).
 * {@see CoreProjection::snapshot()} la saca de aquí, de forma pura, para las
 * referencias que pide la condición (`CanonicalCondition::requirements()`), y
 * {@see CoreProjection::policyInput()} saca las entradas de persona de
 * `PolicyLadder`. Esta foto es la del LOTE: lleva además los instantes de los
 * eventos y la lista de contactos con los que se calculan las referencias con
 * argumentos (recuentos, ventanas, último resultado).
 *
 * Forma (`outreach-facts/1`, claves ordenadas por `CanonicalJson`):
 *   {"at":"…Z","day":"Y-m-d","global":{"brakes":{…},"unreadable":[…]}|null,
 *    "schema":"outreach-facts/1","sources":{…},"subjects":{"<clave>":{…}}}
 *
 * `fromArray()` es ESTRICTO (para la simulación, D9-12): rechaza cualquier
 * clave, hecho, tipo o forma que no sea exactamente esta, con todas las rutas
 * a la vez. Nunca acepta un hecho fuera del catálogo de la foto.
 */
final class BatchSnapshot
{
    public const SCHEMA = 'outreach-facts/1';

    /** Personas como mucho en una foto (D9-8: ≤ 500 por estrategia y pasada). */
    public const MAX_SUBJECTS = 500;

    /**
     * @param  array<string, SubjectFacts>  $subjects  por clave pedida
     * @param  array{brakes: array<string, bool>, unreadable: list<string>}|null  $global
     * @param  array<string, bool>  $sources  disponibilidad de las fuentes (`outreach_history`…)
     */
    public function __construct(
        public readonly string $at,
        public readonly string $day,
        public readonly array $subjects,
        public readonly ?array $global = null,
        public readonly array $sources = [],
    ) {}

    public function subject(string $key): ?SubjectFacts
    {
        return $this->subjects[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $personas = [];
        foreach ($this->subjects as $clave => $s) {
            $personas[$clave] = $s->toArray();
        }
        ksort($personas, SORT_STRING);
        $fuentes = $this->sources;
        ksort($fuentes, SORT_STRING);

        return [
            'at' => $this->at,
            'day' => $this->day,
            'global' => $this->global,
            'schema' => self::SCHEMA,
            'sources' => $fuentes,
            'subjects' => $personas,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /** sha256 del JSON canónico: la huella de la foto (`facts_hash`). */
    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException con todas las rutas que fallan, a la vez
     */
    public static function fromArray(array $data): self
    {
        $errores = [];
        $exactas = ['at', 'day', 'global', 'schema', 'sources', 'subjects'];
        $claves = array_keys($data);
        sort($claves, SORT_STRING);
        if ($claves !== $exactas) {
            throw new InvalidArgumentException('$: la foto tiene que tener exactamente '.implode(', ', $exactas).'.');
        }
        if ($data['schema'] !== self::SCHEMA) {
            $errores[] = '$.schema';
        }
        if (! RelativeTime::isInstant($data['at'])) {
            $errores[] = '$.at';
        } elseif ($data['day'] !== RelativeTime::bogotaDate(RelativeTime::parseInstant($data['at']))) {
            $errores[] = '$.day';
        }
        $global = $data['global'];
        if ($global !== null && ! self::validGlobal($global)) {
            $errores[] = '$.global';
        }
        $fuentes = $data['sources'];
        if (! is_array($fuentes) || ($fuentes !== [] && array_is_list($fuentes))) {
            $errores[] = '$.sources';
            $fuentes = [];
        } else {
            foreach ($fuentes as $k => $v) {
                if (! is_string($k) || preg_match('/^[a-z_]{1,40}$/D', $k) !== 1 || ! is_bool($v)) {
                    $errores[] = '$.sources.'.$k;
                }
            }
        }
        $personas = $data['subjects'];
        $sujetos = [];
        if (! is_array($personas) || ($personas !== [] && array_is_list($personas)) || count($personas) > self::MAX_SUBJECTS) {
            $errores[] = '$.subjects';
        } else {
            foreach ($personas as $clave => $p) {
                $ruta = '$.subjects.'.$clave;
                $s = is_array($p) ? self::subjectFromArray((string) $clave, $p, $ruta, $errores) : null;
                if ($s === null) {
                    $errores[] = $ruta;
                } else {
                    $sujetos[(string) $clave] = $s;
                }
            }
        }
        if ($errores !== []) {
            $errores = array_values(array_unique($errores));
            sort($errores, SORT_STRING);

            throw new InvalidArgumentException('Foto de hechos inválida: '.implode(', ', $errores));
        }

        return new self($data['at'], $data['day'], $sujetos, $global, $fuentes);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<string>  $errores
     */
    private static function subjectFromArray(string $clave, array $p, string $ruta, array &$errores): ?SubjectFacts
    {
        $exactas = ['canonical', 'contacts', 'events', 'facts', 'phone_ref', 'phone_refs', 'policy', 'ref', 'transitions'];
        $claves = array_keys($p);
        sort($claves, SORT_STRING);
        if ($claves !== $exactas || ! SubjectKey::isValid($clave)) {
            return null;
        }
        $antes = count($errores);
        if ($p['canonical'] !== null && ! SubjectKey::isValid($p['canonical'])) {
            $errores[] = $ruta.'.canonical';
        }
        if ($p['ref'] !== null && ! SubjectRef::isValid($p['ref'])) {
            $errores[] = $ruta.'.ref';
        }
        if (! is_array($p['facts']) || ($p['facts'] !== [] && array_is_list($p['facts']))) {
            $errores[] = $ruta.'.facts';
        } else {
            foreach ($p['facts'] as $f => $v) {
                if (! FactNames::validValue((string) $f, $v)) {
                    $errores[] = $ruta.'.facts.'.$f;
                }
            }
        }
        if ($p['policy'] !== null) {
            if (! is_array($p['policy']) || ($p['policy'] !== [] && array_is_list($p['policy']))) {
                $errores[] = $ruta.'.policy';
            } else {
                foreach ($p['policy'] as $k => $v) {
                    if (! in_array($k, FactNames::POLICY, true) || ! is_bool($v)) {
                        $errores[] = $ruta.'.policy.'.$k;
                    }
                }
            }
        }
        if ($p['events'] !== null && ! self::validEvents($p['events'])) {
            $errores[] = $ruta.'.events';
        }
        if ($p['contacts'] !== null && ! self::validContacts($p['contacts'])) {
            $errores[] = $ruta.'.contacts';
        }
        if ($p['transitions'] !== null && ! self::validTransitions($p['transitions'])) {
            $errores[] = $ruta.'.transitions';
        }
        if (! self::validPhoneRefs($p['phone_ref'], $p['phone_refs'])) {
            $errores[] = $ruta.'.phone_ref';
        }
        if (count($errores) !== $antes) {
            return null;
        }

        return new SubjectFacts($clave, $p['canonical'], $p['ref'], $p['facts'], $p['policy'], $p['events'], $p['contacts'], $p['transitions'],
            $p['phone_ref'], $p['phone_refs']);
    }

    private static function validGlobal(mixed $g): bool
    {
        if (! is_array($g) || array_keys($g) !== ['brakes', 'unreadable'] && array_keys($g) !== ['unreadable', 'brakes']) {
            return false;
        }
        if (! is_array($g['brakes']) || ! is_array($g['unreadable']) || ! array_is_list($g['unreadable'])) {
            return false;
        }
        $claves = array_keys($g['brakes']);
        sort($claves, SORT_STRING);
        if ($claves !== FactNames::GLOBAL_BRAKES) {
            return false;
        }
        foreach ($g['brakes'] as $v) {
            if (! is_bool($v)) {
                return false;
            }
        }
        foreach ($g['unreadable'] as $u) {
            if (! in_array($u, FactNames::GLOBAL_BRAKES, true)) {
                return false;
            }
        }

        return true;
    }

    private static function validEvents(mixed $e): bool
    {
        if (! is_array($e) || ! array_is_list($e) || count($e) > EngineFacts::MAX_EVENTS) {
            return false;
        }
        foreach ($e as $x) {
            if (! is_array($x) || self::sortedKeys($x) !== ['at', 'attrs', 'key'] || ! is_string($x['key'])
                || ! array_key_exists($x['key'], OutreachCatalog::CRM_EVENTS) || ! RelativeTime::isInstant($x['at'])
                || ! is_array($x['attrs']) || ($x['attrs'] !== [] && array_is_list($x['attrs']))) {
                return false;
            }
            foreach ($x['attrs'] as $k => $v) {
                if ($k !== 'plan_id' || ! is_int($v) || $v < 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * `phone_refs` null (no se pidió) solo con `phone_ref` null; si no, una lista
     * ordenada y sin repetir de seudónimos, y `phone_ref` uno de ellos (o null si
     * la lista está vacía).
     */
    private static function validPhoneRefs(mixed $ref, mixed $refs): bool
    {
        if ($refs === null) {
            return $ref === null;
        }
        if (! is_array($refs) || ! array_is_list($refs) || count($refs) > 32) {
            return false;
        }
        $ordenada = $refs;
        sort($ordenada, SORT_STRING);
        if ($ordenada !== $refs || count(array_unique($refs)) !== count($refs)) {
            return false;
        }
        foreach ($refs as $r) {
            if (! PhoneRef::isValid($r)) {
                return false;
            }
        }

        return $refs === [] ? $ref === null : (PhoneRef::isValid($ref) && in_array($ref, $refs, true));
    }

    private static function validTransitions(mixed $t): bool
    {
        if (! is_array($t) || ! array_is_list($t) || count($t) > EngineFacts::MAX_EVENTS + 2) {
            return false;
        }
        foreach ($t as $x) {
            if (! is_array($x) || self::sortedKeys($x) !== ['at', 'entity', 'from', 'to'] || ! is_string($x['entity'])
                || ! isset(OutreachCatalog::ENTITIES[$x['entity']]) || ! in_array($x['from'], OutreachCatalog::ENTITIES[$x['entity']], true)
                || ! in_array($x['to'], OutreachCatalog::ENTITIES[$x['entity']], true) || ! RelativeTime::isInstant($x['at'])) {
                return false;
            }
        }

        return true;
    }

    private static function validContacts(mixed $c): bool
    {
        if (! is_array($c) || ! array_is_list($c) || count($c) > ContactHistoryFacts::MAX_CONTACTS) {
            return false;
        }
        $anterior = null;
        foreach ($c as $x) {
            if (! is_array($x) || self::sortedKeys($x) !== ['at', 'medium', 'outcome', 'purpose', 'source', 'status', 'strategy']
                || ! RelativeTime::isInstant($x['at']) || ($anterior !== null && strcmp($x['at'], $anterior) > 0)) {
                return false;
            }
            $anterior = $x['at'];
            if (! in_array($x['medium'], OutreachCatalog::MEDIUMS, true)
                || ! in_array($x['purpose'], OutreachCatalog::PURPOSES, true)
                || ($x['strategy'] !== null && (! is_string($x['strategy']) || preg_match(OutreachCatalog::UUID_PATTERN, $x['strategy']) !== 1))
                || ($x['outcome'] !== null && (! is_string($x['outcome']) || preg_match('/^[a-z_]{1,32}$/D', $x['outcome']) !== 1))
                || ($x['status'] !== null && (! is_string($x['status']) || preg_match('/^[a-z_]{1,16}$/D', $x['status']) !== 1))
                || ! in_array($x['source'], ContactHistoryFacts::SOURCES, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function sortedKeys(array $a): array
    {
        $k = array_map('strval', array_keys($a));
        sort($k, SORT_STRING);

        return $k;
    }
}
