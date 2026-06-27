<?php

namespace App\Services\Marketing;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Acceso a la base de conocimiento comercial (Fase 3.5). Entrega SOLO contenido
 * activo y vigente, ordenado por prioridad, y los planes activos REALES (fuente
 * de precio). Pensado para alimentar el prompt de OpenAI sin inventar datos.
 * No devuelve secretos: es información comercial pública.
 */
class MarketingKnowledgeBaseService
{
    /** Categorías que el prompt del cerebro debe recibir (orden de presentación). */
    public const PROMPT_CATEGORIES = [
        'business_identity', 'location', 'schedule', 'payment_policy',
        'membership_policy', 'invoice_policy', 'objections', 'restrictions',
        'tone', 'faq', 'human_escalation',
    ];

    /** Categorías recomendadas para una cobertura mínima (doctor). */
    public const RECOMMENDED_CATEGORIES = [
        'business_identity', 'payment_policy', 'membership_policy',
        'invoice_policy', 'restrictions', 'tone', 'objections', 'faq',
    ];

    /** @return Collection<int, MarketingKnowledgeItem> */
    public function activeItems(?string $category = null): Collection
    {
        return MarketingKnowledgeItem::query()
            ->activeNow()
            ->when($category !== null, fn ($q) => $q->category($category))
            ->get();
    }

    public function activeItemsCount(): int
    {
        return MarketingKnowledgeItem::query()->activeNow()->count();
    }

    /**
     * Conocimiento compacto agrupado por categoría para el prompt:
     * category => [ "Título: contenido", ... ] (solo categorías con datos).
     *
     * @return array<string, array<int, string>>
     */
    public function groupedForPrompt(): array
    {
        $grouped = [];
        foreach ($this->activeItems() as $item) {
            if (! in_array($item->category, self::PROMPT_CATEGORIES, true)) {
                continue;
            }
            $line = $item->title ? trim($item->title).': '.trim($item->content) : trim($item->content);
            $grouped[$item->category][] = $line;
        }
        return $grouped;
    }

    /** Planes activos REALES (id/name/price/duration/benefits). Fuente de precio. */
    public function activePlans(): array
    {
        return Plan::where('active', true)
            ->orderBy('sort_order')->get(['id', 'name', 'price', 'duration_days', 'benefits'])
            ->map(fn (Plan $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'price'         => (float) $p->price,
                'duration_days' => $p->duration_days,
                'benefits'      => $p->benefitsArray(),
            ])->all();
    }

    public function activePlansCount(): int
    {
        return Plan::where('active', true)->count();
    }

    /**
     * Identificador de versión del conocimiento (para auditoría en
     * marketing_ai_actions). Cambia si cambia el conjunto activo o su contenido.
     */
    public function version(): string
    {
        $items = $this->activeItems();
        $stamp = $items->max('updated_at');
        return $items->count().':'.($stamp ? $stamp->timestamp : '0');
    }

    /** Resumen para el doctor (sin secretos). */
    public function summary(): array
    {
        $byCategory = MarketingKnowledgeItem::query()->activeNow()
            ->get(['category'])
            ->countBy('category')->toArray();

        $present = array_keys($byCategory);
        $missing = array_values(array_diff(self::RECOMMENDED_CATEGORIES, $present));

        return [
            'total_items'           => MarketingKnowledgeItem::count(),
            'active_items'          => $this->activeItemsCount(),
            'by_category'           => $byCategory,
            'missing_recommended'   => $missing,
            'active_plans_count'    => $this->activePlansCount(),
            'prompt_receives_knowledge' => $this->activeItemsCount() > 0,
            'version'               => $this->version(),
        ];
    }
}
