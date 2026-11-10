<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\OutreachCatalog;
use InvalidArgumentException;

/**
 * QUÉ se lee en una foto (contrato H9, D9-8 etapa 2: «hechos, solo los que usa
 * la estrategia»). Un adaptador cuyos hechos no se piden no hace ninguna
 * consulta. PURO. {@see CoreProjection::selectionFor()} la saca de los
 * requisitos de una condición.
 */
final class FactSelection
{
    /**
     * @param  list<string>  $facts  hechos escalares ({@see FactNames::FACT_TYPES})
     * @param  list<string>  $events  claves de `OutreachCatalog::CRM_EVENTS`
     * @param  bool  $contacts  la lista de contactos (`contact.*`, recuentos y el presupuesto)
     * @param  bool  $policy  las entradas de N1–N4 por persona
     * @param  bool  $global  los frenos ajenos de N2
     * @param  bool  $transitions  la lista de transiciones (estado actual + eventos)
     *
     * @throws InvalidArgumentException con un hecho o un evento fuera del catálogo
     */
    public function __construct(
        public readonly array $facts,
        public readonly array $events = [],
        public readonly bool $contacts = false,
        public readonly bool $policy = false,
        public readonly bool $global = false,
        public readonly bool $transitions = false,
    ) {
        foreach ($facts as $f) {
            if (! is_string($f) || ! array_key_exists($f, FactNames::FACT_TYPES)) {
                throw new InvalidArgumentException('Hecho fuera del catálogo de la foto.');
            }
        }
        foreach ($events as $e) {
            if (! is_string($e) || ! array_key_exists($e, OutreachCatalog::CRM_EVENTS)) {
                throw new InvalidArgumentException('Evento fuera del catálogo.');
            }
        }
    }

    /** Todo: los hechos, la política, los frenos, los contactos, las transiciones y todos los eventos. */
    public static function all(): self
    {
        return new self(array_keys(FactNames::FACT_TYPES), array_keys(OutreachCatalog::CRM_EVENTS), true, true, true, true);
    }

    /** @param  list<string>  $adapterFacts */
    public function wants(array $adapterFacts): bool
    {
        return array_intersect($adapterFacts, $this->facts) !== [];
    }
}
