<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * Que cada respuesta aporte algo.
 *
 * Dos trabajos. El primero es determinista y duro: una respuesta casi idéntica
 * a una que ya salió no vuelve a salir (así se mandó seis veces el mismo
 * precio). El segundo es orientación: a partir de la memoria, la lista de lo
 * que NO hay que repetir y de lo que todavía queda por contar, para que el
 * redactor tenga con qué responder a «cuéntame más» sin volver a empezar.
 */
final class NoveltyGuard
{
    /** Por encima de esto, dos mensajes son el mismo mensaje. */
    public const NEAR_DUPLICATE = 0.85;

    /** Quien pide que se lo repitan, quiere que se lo repitan. */
    public function isExplicitRepeatRequest(string $inbound): bool
    {
        $t = SalesAgentDecisionSchema::normalize($inbound);

        return preg_match('/\b(repite|repiteme|repitelo|otra vez|de nuevo|nuevamente|no me llego|se me borro|lo borre|la borre|me (la|lo) (mandas|pasas|repites|envias) (otra vez|de nuevo)|puedes volver a)\b/u', $t) === 1;
    }

    /**
     * Índice del mensaje previo del que el borrador es casi copia, o null.
     *
     * @param  string[]  $previous
     */
    public function nearDuplicateOf(string $draft, array $previous, float $threshold = self::NEAR_DUPLICATE): ?int
    {
        foreach ($previous as $i => $body) {
            if ($this->similarity($draft, (string) $body) >= $threshold) {
                return $i;
            }
        }

        return null;
    }

    /** Jaccard sobre bigramas de palabras normalizadas: barato y suficiente. */
    public function similarity(string $a, string $b): float
    {
        $sa = $this->shingles($a);
        $sb = $this->shingles($b);
        if ($sa === [] || $sb === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($sa, $sb));
        $union = count($sa + $sb);

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /**
     * Orientación para el redactor: lo entregado (no repetir) y lo que queda.
     *
     * @param  array<int, array{id:int, name:string, benefits:string[]}>  $plans  planes vendibles con beneficios
     * @return array{must_not_repeat: array<string,mixed>, fresh_candidates: string[]}
     */
    public function guidance(ConversationMemory $memory, array $plans, array $resolved): array
    {
        $pricePlans = array_values(array_unique(array_map(fn ($p) => (int) $p['plan_id'], $memory->get('prices_delivered'))));
        $benefits = array_values(array_unique(array_map(fn ($b) => (string) $b['benefit'], $memory->get('benefits_delivered'))));
        $facts = array_values(array_unique(array_map(fn ($f) => (string) $f['key'], $memory->get('facts_delivered'))));
        $lastQ = $memory->get('last_agent_question')['text'] ?? null;

        $fresh = [];
        $focus = $resolved['plan_id'] ?? $memory->pendingPlanId();
        foreach ($plans as $p) {
            if ($focus !== null && (int) $p['id'] !== (int) $focus) {
                continue;
            }
            foreach ($p['benefits'] ?? [] as $b) {
                if (! in_array($b, $memory->benefitsDeliveredFor((int) $p['id']), true)) {
                    $fresh[] = 'beneficio no contado del plan '.$p['id'].': '.$b;
                }
            }
        }
        if (! $memory->factDelivered('how_to_start')) {
            $fresh[] = 'como empezar (pasos concretos)';
        }
        if (! $memory->factDelivered('location')) {
            $fresh[] = 'ubicacion';
        }
        if (! $memory->factDelivered('trainers_count')) {
            $fresh[] = 'cuantos entrenadores hay';
        }

        return [
            'must_not_repeat' => [
                'price_of_plans' => $pricePlans,
                'benefits' => $benefits,
                'facts' => $facts,
                'last_agent_question' => $lastQ,
            ],
            'fresh_candidates' => array_slice($fresh, 0, 8),
        ];
    }

    /** @return array<string,true> */
    private function shingles(string $s): array
    {
        $t = SalesAgentDecisionSchema::normalize($s);
        $t = preg_replace('/[^a-z0-9\s]+/u', ' ', $t) ?? $t;
        $w = preg_split('/\s+/u', trim($t)) ?: [];
        $w = array_values(array_filter($w, fn ($x) => $x !== ''));
        $out = [];
        if (count($w) === 1) {
            $out[$w[0]] = true;
        }
        for ($i = 0; $i + 1 < count($w); $i++) {
            $out[$w[$i].' '.$w[$i + 1]] = true;
        }

        return $out;
    }
}
