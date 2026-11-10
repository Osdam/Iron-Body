<?php

namespace App\Services\Outreach\Core;

use InvalidArgumentException;

/**
 * La FOTO DE HECHOS de una persona (contrato H9, D9-5 «Evaluación», D9-12
 * «Reproducibilidad», §5 capa `context`; fixture `conditions-cases.json`,
 * `interface.snapshot`). Inmutable. Es lo que se guarda en
 * `outreach_decisions.facts_json` y lo que `outreach:replay` vuelve a evaluar.
 *
 *     {facts: {clave: valor derivado | null},
 *      contacts:    [{at, channel, purpose, strategy, outcome}] | null,
 *      events:      [{key, at, attrs}] | null,
 *      transitions: [{entity, from, to, at}] | null}
 *
 *  - `facts`: valores DERIVADOS (protección 10): enteros (días con signo,
 *    recuentos, centésimas), booleanos y valores de dominio; nunca fechas de
 *    nacimiento, importes, teléfonos, nombres ni texto libre. null o ausente =
 *    DESCONOCIDO.
 *  - Una lista null es una FUENTE SIN DATOS: los nodos que la leen dan
 *    `unknown` (DF-C4: ausencia no probada no es ausencia). Una lista vacía es
 *    una fuente al día sin filas.
 *  - Los contactos llevan el MEDIO (`channel`: whatsapp|voice), la finalidad,
 *    `strategy` (`self` = esta estrategia, `other` = otra del H9, null = de
 *    fuera del H9) y su resultado (null = aún sin resultado). Los eventos, sus
 *    atributos filtrables (`plan_id`). Los instantes, en UTC con `Z`.
 *
 * Una foto mal formada es un error de quien la construye (se lanza): no se
 * evalúa «a ojo».
 */
final class FactSnapshot
{
    public const MAX_FACTS = 128;

    public const MAX_ROWS = 500;

    /** Un valor de texto: un valor de un dominio cerrado (empieza por letra; sin espacios ni signos). */
    public const VALUE_PATTERN = '/^[A-Za-z][A-Za-z0-9_:.-]{0,63}$/D';

    private const LISTS = ['contacts', 'events', 'transitions'];

    /**
     * @param  array<string, bool|int|string|null>  $facts
     * @param  list<array<string,mixed>>|null  $contacts
     * @param  list<array<string,mixed>>|null  $events
     * @param  list<array<string,mixed>>|null  $transitions
     */
    private function __construct(
        public readonly array $facts,
        public readonly ?array $contacts,
        public readonly ?array $events,
        public readonly ?array $transitions,
    ) {}

    /**
     * @param  array{facts?: array<string,mixed>, contacts?: list<array<string,mixed>>|null, events?: list<array<string,mixed>>|null, transitions?: list<array<string,mixed>>|null}  $data
     *
     * @throws InvalidArgumentException si algo no tiene la forma de un valor derivado
     */
    public static function fromArray(array $data): self
    {
        foreach (array_keys($data) as $k) {
            if (! in_array($k, ['facts', ...self::LISTS], true)) {
                throw new InvalidArgumentException('La foto solo lleva facts, contacts, events y transitions.');
            }
        }
        $hechos = $data['facts'] ?? [];
        if (! is_array($hechos) || ($hechos !== [] && array_is_list($hechos)) || count($hechos) > self::MAX_FACTS) {
            throw new InvalidArgumentException('Los hechos de la foto son un objeto clave → valor.');
        }
        $limpios = [];
        foreach ($hechos as $clave => $valor) {
            if (! is_string($clave) || $clave === '' || strlen($clave) > 96) {
                throw new InvalidArgumentException('Una clave de hecho de la foto no tiene forma.');
            }
            if (! ($valor === null || is_bool($valor) || is_int($valor) || (is_string($valor) && preg_match(self::VALUE_PATTERN, $valor) === 1))) {
                throw new InvalidArgumentException("El hecho {$clave} no es un valor derivado (entero, booleano o valor de dominio).");
            }
            $limpios[$clave] = $valor;
        }
        ksort($limpios, SORT_STRING);

        return new self(
            $limpios,
            self::rows($data, 'contacts', self::contact(...)),
            self::rows($data, 'events', self::event(...)),
            self::rows($data, 'transitions', self::transition(...)),
        );
    }

    public function fact(string $key): bool|int|string|null
    {
        return $this->facts[$key] ?? null;
    }

    /** @return array{facts: array<string, bool|int|string|null>, contacts: list<array<string,mixed>>|null, events: list<array<string,mixed>>|null, transitions: list<array<string,mixed>>|null} */
    public function toArray(): array
    {
        return ['facts' => $this->facts, 'contacts' => $this->contacts, 'events' => $this->events, 'transitions' => $this->transitions];
    }

    /** El JSON canónico (lo que se guarda en `facts_json`). */
    public function json(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /** `facts_hash`: sha256 del JSON canónico. */
    public function hash(): string
    {
        return hash('sha256', $this->json());
    }

    /** @return list<array<string,mixed>>|null */
    private static function rows(array $data, string $lista, callable $fila): ?array
    {
        if (! array_key_exists($lista, $data) || $data[$lista] === null) {
            return null;
        }
        $filas = $data[$lista];
        if (! is_array($filas) || ! array_is_list($filas) || count($filas) > self::MAX_ROWS) {
            throw new InvalidArgumentException("{$lista} es una lista (o null: fuente sin datos).");
        }

        return array_map(static fn (mixed $f): array => $fila($f), $filas);
    }

    /** @return array{at: string, channel: string, outcome: string|null, purpose: string, strategy: string|null} */
    private static function contact(mixed $c): array
    {
        if (! is_array($c) || self::sortedKeys($c) !== ['at', 'channel', 'outcome', 'purpose', 'strategy']
            || ! RelativeTime::isInstant($c['at']) || ! in_array($c['channel'], OutreachCatalog::MEDIUMS, true)
            || ! in_array($c['purpose'], OutreachCatalog::PURPOSES, true) || ! in_array($c['strategy'], ['self', 'other', null], true)
            || ! ($c['outcome'] === null || OutcomeTable::isOutcome($c['outcome']))) {
            throw new InvalidArgumentException('Un contacto de la foto es {at, channel, purpose, strategy, outcome}.');
        }

        return ['at' => $c['at'], 'channel' => $c['channel'], 'outcome' => $c['outcome'], 'purpose' => $c['purpose'], 'strategy' => $c['strategy']];
    }

    /** @return array{at: string, attrs: array<string, int|string>, key: string} */
    private static function event(mixed $e): array
    {
        if (! is_array($e) || self::sortedKeys($e) !== ['at', 'attrs', 'key'] || ! is_string($e['key']) || ! array_key_exists($e['key'], OutreachCatalog::CRM_EVENTS)
            || ! RelativeTime::isInstant($e['at']) || ! is_array($e['attrs']) || ($e['attrs'] !== [] && array_is_list($e['attrs']))) {
            throw new InvalidArgumentException('Un evento de la foto es {key (del vocabulario), at, attrs}.');
        }
        foreach ($e['attrs'] as $k => $v) {
            if (! is_string($k) || ! (is_int($v) || (is_string($v) && preg_match(self::VALUE_PATTERN, $v) === 1))) {
                throw new InvalidArgumentException('Los atributos de un evento son valores derivados.');
            }
        }
        ksort($e['attrs'], SORT_STRING);

        return ['at' => $e['at'], 'attrs' => $e['attrs'], 'key' => $e['key']];
    }

    /** @return array{at: string, entity: string, from: string, to: string} */
    private static function transition(mixed $t): array
    {
        if (! is_array($t) || self::sortedKeys($t) !== ['at', 'entity', 'from', 'to'] || ! is_string($t['entity']) || ! isset(OutreachCatalog::ENTITIES[$t['entity']])
            || ! in_array($t['from'], OutreachCatalog::ENTITIES[$t['entity']], true) || ! in_array($t['to'], OutreachCatalog::ENTITIES[$t['entity']], true)
            || ! RelativeTime::isInstant($t['at'])) {
            throw new InvalidArgumentException('Una transición de la foto es {entity, from, to, at} de una máquina del catálogo.');
        }

        return ['at' => $t['at'], 'entity' => $t['entity'], 'from' => $t['from'], 'to' => $t['to']];
    }

    /** @return list<string> */
    private static function sortedKeys(array $a): array
    {
        $k = array_map('strval', array_keys($a));
        sort($k, SORT_STRING);

        return $k;
    }
}
