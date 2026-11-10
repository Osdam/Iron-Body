<?php

namespace App\Services\Outreach\Core;

use Illuminate\Validation\ValidationException;

/**
 * Una condición YA VALIDADA y canónica (contrato H9, D9-5). Inmutable.
 *
 * Solo nace de {@see ConditionCanonicalizer}: el constructor es privado y las
 * dos puertas ({@see parse()}, {@see tryParse()}) validan antes. Por eso el
 * evaluador solo acepta esta clase: «nunca se evalúa nada que no haya pasado
 * la validación» (T11).
 *
 * Guarda la versión del catálogo con la que se validó: una versión guardada
 * que el catálogo vigente ya no admite no se reinterpreta
 * (`catalog_incompatible`, D9-3): quien la relee la vuelve a pasar por
 * {@see tryParse()} y, si falla, la niega.
 */
final class CanonicalCondition
{
    /**
     * @param  array<string,mixed>  $tree  el árbol canónico
     */
    private function __construct(
        public readonly array $tree,
        public readonly string $json,
        public readonly string $hash,
        public readonly string $catalogVersion,
    ) {}

    /**
     * Valida y canonicaliza; lo que no vale es un 422 con todas las claves.
     *
     * @param  array<string, list<int|string>>  $dynamicDomains
     *
     * @throws ValidationException
     */
    public static function parse(mixed $tree, array $dynamicDomains = [], string $root = ConditionCanonicalizer::ROOT): self
    {
        $r = self::tryParse($tree, $dynamicDomains, $root);
        if ($r['condition'] === null) {
            throw ValidationException::withMessages(ConditionCanonicalizer::messages($r['errors']));
        }

        return $r['condition'];
    }

    /**
     * Lo mismo sin lanzar.
     *
     * @param  array<string, list<int|string>>  $dynamicDomains
     * @return array{condition: self|null, errors: list<array{key: string, code: string}>}
     */
    public static function tryParse(mixed $tree, array $dynamicDomains = [], string $root = ConditionCanonicalizer::ROOT): array
    {
        $r = ConditionCanonicalizer::check($tree, $dynamicDomains, $root);
        if ($r['errors'] !== [] || $r['canonical'] === null) {
            return ['condition' => null, 'errors' => $r['errors']];
        }

        return ['condition' => new self($r['canonical'], $r['json'], $r['hash'], OutreachCatalog::VERSION), 'errors' => []];
    }

    /**
     * Los hechos y los eventos que usa (para la compatibilidad, DF-K6, y para
     * que los adaptadores carguen solo lo necesario): claves de hecho y claves
     * de evento del vocabulario, sin repetir y ordenadas.
     *
     * @return array{facts: list<string>, events: list<string>, contacts: bool, transitions: bool}
     */
    public function uses(): array
    {
        $uso = ['facts' => [], 'events' => [], 'contacts' => false, 'transitions' => false];
        self::collect($this->tree, $uso);
        foreach (['facts', 'events'] as $k) {
            $uso[$k] = array_values(array_unique($uso[$k]));
            sort($uso[$k], SORT_STRING);
        }

        return $uso;
    }

    /**
     * Los nodos de la conjunción RAÍZ (los únicos que pueden alimentar un
     * prefiltro indexado, D9-5 «Prefiltros»): la raíz si es una hoja, o los
     * hijos hoja de un `all` raíz. El veredicto siempre lo da el evaluador.
     *
     * @return list<array<string,mixed>>
     */
    public function rootConjunction(): array
    {
        $candidatos = isset($this->tree['all']) ? $this->tree['all'] : [$this->tree];

        return array_values(array_filter($candidatos, static fn (array $n): bool => ! isset($n['all']) && ! isset($n['any']) && ! isset($n['not'])));
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array{facts: list<string>, events: list<string>, contacts: bool, transitions: bool}  $uso
     */
    private static function collect(array $node, array &$uso): void
    {
        foreach (['all', 'any'] as $grupo) {
            if (isset($node[$grupo])) {
                foreach ($node[$grupo] as $hijo) {
                    self::collect($hijo, $uso);
                }

                return;
            }
        }
        if (isset($node['not'])) {
            self::collect($node['not'], $uso);

            return;
        }
        if (isset($node['fact'])) {
            $uso['facts'][] = $node['fact'];
            $uso['contacts'] = $uso['contacts'] || str_starts_with($node['fact'], 'contact.');
        } elseif (isset($node['event'])) {
            $uso['events'][] = $node['event'];
        } elseif (isset($node['count'])) {
            $de = $node['count']['of'];
            $de === 'contact' ? $uso['contacts'] = true : $uso['events'][] = substr($de, 6);
        } else {
            $uso['transitions'] = true;
        }
    }
}
