<?php

namespace App\Services\Outreach\Facts;

/**
 * Los hechos DERIVADOS de una persona en la foto de un lote (contrato H9,
 * D9-4, D9-12; §3.10). Inmutable y PURO. Solo valores derivados (días, horas,
 * recuentos, centésimas, enumerados y booleanos) e instantes de sus eventos,
 * transiciones y contactos; nunca teléfonos, nombres, documentos, fechas de
 * nacimiento, importes ni textos.
 *
 *  - `key`: la clave PEDIDA; `canonicalKey`: la persona hoy (null =
 *    `unknown_subject`); `ref`: el seudónimo de la canónica (o de la pedida).
 *  - `facts`: solo los hechos pedidos; null = DESCONOCIDO, ausente = no se pidió.
 *  - `policy`: las entradas de N1–N4 (null si no se pidieron).
 *  - `events`, `transitions`, `contacts`: listas con la forma de
 *    `Core\FactSnapshot` (salvo los campos de más de los contactos, que
 *    {@see CoreProjection} recorta); null = no se pidió o FUENTE SIN DATOS.
 *  - `phoneRef` / `phoneRefs` (L9-29): el seudónimo ({@see PhoneRef}) de su
 *    teléfono PRINCIPAL ({@see FactPass::primaryPhoneOf()}) y los de TODOS sus
 *    números, ordenados. Se leen con la política (el tope por teléfono es del
 *    presupuesto): `phoneRefs` null = no se pidió; `[]` y `phoneRef` null = no
 *    tiene teléfono (desconocido).
 */
final class SubjectFacts
{
    /**
     * @param  array<string, bool|int|string|null>  $facts
     * @param  array<string, bool>|null  $policy
     * @param  list<array{at: string, attrs: array<string, int>, key: string}>|null  $events
     * @param  list<array<string, ?string>>|null  $contacts
     * @param  list<array{at: string, entity: string, from: string, to: string}>|null  $transitions
     * @param  list<string>|null  $phoneRefs
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $canonicalKey,
        public readonly ?string $ref,
        public readonly array $facts,
        public readonly ?array $policy = null,
        public readonly ?array $events = null,
        public readonly ?array $contacts = null,
        public readonly ?array $transitions = null,
        public readonly ?string $phoneRef = null,
        public readonly ?array $phoneRefs = null,
    ) {}

    public function resolved(): bool
    {
        return $this->canonicalKey !== null;
    }

    public function has(string $fact): bool
    {
        return array_key_exists($fact, $this->facts);
    }

    public function fact(string $fact): bool|int|string|null
    {
        return $this->facts[$fact] ?? null;
    }

    public function policy(string $key): ?bool
    {
        return $this->policy[$key] ?? null;
    }

    /** @return array<string, mixed> la forma serializada (claves ordenadas por `CanonicalJson`) */
    public function toArray(): array
    {
        $hechos = $this->facts;
        ksort($hechos, SORT_STRING);
        $politica = $this->policy;
        if ($politica !== null) {
            ksort($politica, SORT_STRING);
        }

        return [
            'canonical' => $this->canonicalKey,
            'contacts' => $this->contacts,
            'events' => $this->events,
            'facts' => $hechos,
            'phone_ref' => $this->phoneRef,
            'phone_refs' => $this->phoneRefs,
            'policy' => $politica,
            'ref' => $this->ref,
            'transitions' => $this->transitions,
        ];
    }
}
