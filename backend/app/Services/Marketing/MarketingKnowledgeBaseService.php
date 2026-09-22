<?php

namespace App\Services\Marketing;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Acceso a la base de conocimiento comercial (Fase 3.5). Entrega SOLO contenido
 * activo, vigente y APROBADO, ordenado por prioridad, y los planes activos
 * REALES (fuente de precio). Pensado para alimentar el prompt de OpenAI sin
 * inventar datos. No devuelve secretos: es información comercial pública.
 *
 * RC-4 — este servicio es el lado de LECTURA de la frontera de confianza. Quién
 * puede publicar lo decide {@see MarketingKnowledgeItem}; aquí sólo se lee lo ya
 * aprobado (`scopeActiveNow`) y se entrega con forma de DATO, nunca de
 * instrucción: cada ítem viaja en UNA línea, sin saltos ni caracteres de
 * control, para que no pueda fabricar aguas abajo una sección de prompt que
 * parezca nuestra. La defensa de verdad es la aprobación; esto es lo que impide
 * que un texto aprobado a la ligera se disfrace de encabezado del sistema.
 */
class MarketingKnowledgeBaseService
{
    /** Categorías que el prompt del cerebro debe recibir (orden de presentación). */
    public const PROMPT_CATEGORIES = [
        'business_identity', 'location', 'schedule', 'gym_info', 'payment_policy',
        'membership_policy', 'invoice_policy', 'objections', 'restrictions',
        'tone', 'faq', 'human_escalation', 'brand_copy',
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
     * Las frases publicitarias fuertes que el negocio aprobó, tal cual.
     *
     * Salen SIN título y sin aplanar en «Título: contenido» porque quien las
     * consume no es un prompt sino un cerrojo: {@see ComposerStyleGuard}
     * compara el trozo de texto que dispara la alarma contra estas frases, y
     * cualquier adorno alrededor rompería la comparación.
     *
     * Pasan por `activeNow()` como todo lo demás: activo, vigente y APROBADO.
     * Una frase propuesta desde la API interna nace en borrador y no autoriza
     * nada hasta que una persona la aprueba por consola.
     *
     * @return array<int, string>
     */
    public function approvedBrandCopy(): array
    {
        return $this->activeItems('brand_copy')
            ->map(fn (MarketingKnowledgeItem $i) => trim((string) $i->content))
            ->filter(fn (string $c) => $c !== '')
            ->values()
            ->all();
    }

    /**
     * Conocimiento compacto agrupado por categoría para el prompt:
     * category => [ "Título: contenido", ... ] (solo categorías con datos).
     *
     * La forma NO cambia: `context.knowledge_base` es contrato con n8n y con el
     * cerebro local. Lo que cambia es que cada línea es UNA línea (ver
     * {@see asData()}) y que sólo entra lo aprobado.
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
            $title = self::asData($item->title);
            $content = self::asData($item->content);
            if ($content === '') {
                continue;
            }
            $grouped[$item->category][] = $title === '' ? $content : $title.': '.$content;
        }

        return $grouped;
    }

    /**
     * Texto de la base de conocimiento convertido en dato plano.
     *
     * Un ítem con saltos de línea puede escribir «\n\nSYSTEM:» y, allí donde el
     * contexto se interpola en una plantilla de prompt (el workflow de n8n lo
     * hace), pasar por una sección nuestra. Aplanarlo no le quita significado:
     * le quita la capacidad de fingir estructura. No se censura nada por regex
     * —eso sería adivinar—, sólo se normaliza el espacio y se sacan los
     * caracteres de control.
     */
    public static function asData(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? $text;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    /** Planes activos REALES (id/name/price/duration/benefits). Fuente de precio. */
    public function activePlans(): array
    {
        return Plan::sellable()
            /*
             * El plan marcado como recomendado sale PRIMERO del catálogo.
             *
             * Es lo que el negocio entiende por «el plan que ofrecemos», y con
             * esto el estratega y el redactor lo ven arriba sin que haya un id
             * ni un precio escrito en ningún prompt. Si alguien lo desactiva o
             * le pone precio cero, `Plan::sellable()` lo saca del catálogo y la
             * prioridad se evapora sola.
             *
             * Se ordena por `is_recommended` y NO por `sort_order`: `sort_order`
             * decide además cuál es el plan que se cotiza por defecto
             * ({@see defaultMonthlyPlan()}), así que moverlo para «poner uno
             * primero» cambiaría en silencio la respuesta a «¿cuánto vale?».
             */
            ->orderByDesc('is_recommended')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'price', 'duration_days', 'benefits', 'tier',
                'original_price', 'is_recommended', 'badge', 'access_classes', 'restrictions'])
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'duration_days' => $p->duration_days,
                'benefits' => $p->benefitsArray(),
                'tier' => $p->tier,
                // Sólo si hay rebaja de verdad: un descuento inventado es la
                // clase de urgencia falsa que este sistema no puede permitirse.
                'original_price' => $p->original_price !== null && (float) $p->original_price > (float) $p->price
                    ? (float) $p->original_price
                    : null,
                'is_recommended' => (bool) $p->is_recommended,
                'badge' => $p->badge,
                'access_classes' => (bool) $p->access_classes,
                'restrictions' => $p->restrictions,
            ])->all();
    }

    public function activePlansCount(): int
    {
        return Plan::sellable()->count();
    }

    /**
     * Plan mensual / ancla activo (fuente de precio para una pregunta de precio
     * genérica). Por duración (~30 días) o por nombre; si no hay, el primer plan
     * activo por orden. null si no hay ningún plan activo (entonces NO se cotiza).
     */
    public function defaultMonthlyPlan(): ?Plan
    {
        $monthly = Plan::sellable()
            ->where(function ($q) {
                $q->whereBetween('duration_days', [28, 31])
                    ->orWhere('name', 'like', '%ensual%'); // mensual / Mensualidad
            })
            ->orderBy('sort_order')
            ->first();

        return $monthly ?? Plan::sellable()->orderBy('sort_order')->first();
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
            'total_items' => MarketingKnowledgeItem::count(),
            'active_items' => $this->activeItemsCount(),
            'by_category' => $byCategory,
            'missing_recommended' => $missing,
            'active_plans_count' => $this->activePlansCount(),
            'prompt_receives_knowledge' => $this->activeItemsCount() > 0,
            // Propuesto por una vía sin autoridad y esperando a que alguien lo
            // mire. Mientras esté aquí NO está en el prompt.
            'pending_review_items' => MarketingKnowledgeItem::query()->pendingReview()->count(),
            /*
             * La deuda heredada: filas que hoy se presentan como hechos del
             * gimnasio y entraron por el secreto compartido antes de que esta
             * frontera existiera, sin que nadie las revisara nunca. Si esto no
             * es 0, hay que mirarlas una por una: `GET knowledge?review_status=
             * approved&origin=internal_api`.
             */
            'approved_from_untrusted_origin' => MarketingKnowledgeItem::query()->approvedFromUntrustedOrigin()->count(),
            'version' => $this->version(),
        ];
    }
}
