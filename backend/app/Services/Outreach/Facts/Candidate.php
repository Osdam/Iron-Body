<?php

namespace App\Services\Outreach\Facts;

/**
 * Una persona que un PREFILTRO indexado encontró para un disparador periódico
 * (contrato H9, D9-3 y D9-5 «Prefiltros»). No decide nada: el veredicto lo da
 * siempre el evaluador puro sobre la foto de hechos. Lleva las PIEZAS de la
 * clave de ciclo (`OutreachCatalog::cycleKey($event, $cycleParts)` la arma el
 * núcleo) y el cursor de la fila de origen para paginar (`> cursor`).
 */
final class Candidate
{
    /**
     * @param  array<string, int|string|null>  $cycleParts
     */
    public function __construct(
        public readonly string $subjectKey,
        public readonly string $event,
        public readonly array $cycleParts,
        public readonly int $cursor,
    ) {}

    /** @return array{subjectKey: string, event: string, cycleParts: array<string, int|string|null>, cursor: int} */
    public function toArray(): array
    {
        $partes = $this->cycleParts;
        ksort($partes, SORT_STRING);

        return ['subjectKey' => $this->subjectKey, 'event' => $this->event, 'cycleParts' => $partes, 'cursor' => $this->cursor];
    }
}
