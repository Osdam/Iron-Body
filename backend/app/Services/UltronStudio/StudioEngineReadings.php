<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAiConfig;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lo que el motor de ULTRON lee HOY, para `GET engine/readings` (contrato G
 * §A2): el valor real de los parámetros que tienen mando (`valueSource: live`
 * en el catálogo) y las lecturas que no son parámetros (`readings`).
 *
 * SOLO LECTURA, Y ES LA FRONTERA. Es la única clase nueva del Studio que nombra
 * clases del motor; además de ella solo lo hace `StudioRules`, que ya leía la
 * base de conocimiento. Aquí solo se llaman métodos de LECTURA (`current`,
 * `engaged`, `effectiveDriver`, `version`, scopes de consulta y `config()` con
 * una clave): el Studio no escribe en tablas, configuración ni ficheros del
 * motor. Lo vigilan una prueba por tokens y otra de comportamiento
 * (`UltronStudioEngineReadOnlyTest`).
 *
 * QUÉ NUNCA SALE: la URL del webhook, secretos ni claves, ids de conversación
 * (ni el del canario ni el del canario del cobro: solo si existen), teléfonos,
 * precios, ids de planes ni el contenido de la base de conocimiento.
 *
 * FALLO SUAVE. Cada lectura va por su cuenta: si una fuente falla (una tabla que
 * no responde, un método del motor que cambió de nombre), esa entrada sale
 * `{available:false}`, sin mensaje técnico, deja una línea en el log sin datos
 * y las demás siguen. Nunca hay un 500 por una lectura parcial.
 *
 * MATICES (contrato G §A2):
 *  - `UltronAbortLatch::engaged()` devuelve `false` si su tabla no existe (y lo
 *    anota él mismo). En producción la tabla existe.
 *  - Las banderas son las del PROCESO WEB (con su caché de configuración): un
 *    worker sin reiniciar puede ir por detrás. Lo dice `note`.
 */
final class StudioEngineReadings
{
    /** Los parámetros del borrador que se leen en vivo (`valueSource: live` en el catálogo), en el orden del contrato. */
    public const PARAMS = ['intent.driver', 'rules.paymentLinksEnabled', 'governance.enabled', 'governance.canaryOnly', 'strategy.offerPriority'];

    /** Las lecturas que no son parámetros ({@see StudioCatalog::readings()}), en su orden. */
    public const EXTRAS = ['governance.paused', 'governance.brake', 'rules.paymentCanary', 'knowledge.base', 'strategy.plans'];

    /** Planes como mucho en cada lectura de planes: los nombres, nada más. */
    public const MAX_PLANS = 20;

    public const NOTE = 'Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician.';

    public function __construct(
        private readonly MarketingAgentSwitch $switch,
        private readonly UltronAbortLatch $latch,
        private readonly MarketingKnowledgeBaseService $knowledge,
    ) {}

    /**
     * La forma de `GET engine/readings`.
     *
     * @return array{readAt:string,params:array<string,array{available:bool,value?:mixed}>,extras:array<string,array{available:bool,value?:mixed}>,note:string}
     */
    public function read(): array
    {
        $lectores = $this->readers();

        $params = [];
        foreach (self::PARAMS as $id) {
            $params[$id] = $this->reading($id, $lectores[$id]);
        }
        $extras = [];
        foreach (self::EXTRAS as $id) {
            $extras[$id] = $this->reading($id, $lectores[$id]);
        }

        return [
            'readAt' => now()->toIso8601String(),
            'params' => $params,
            'extras' => $extras,
            'note' => self::NOTE,
        ];
    }

    /**
     * Una lectura por id. Cada una replica el criterio con el que el motor lee
     * ese dato (las mismas claves, los mismos scopes y los mismos cortes).
     *
     * @return array<string, Closure(): mixed>
     */
    private function readers(): array
    {
        return [
            // `fake` son las reglas deterministas: el borrador lo llama `rules`.
            'intent.driver' => static function (): string {
                $driver = SalesAiConfig::effectiveDriver();

                return $driver === 'fake' ? 'rules' : $driver;
            },
            'rules.paymentLinksEnabled' => static fn (): bool => (bool) config('marketing.ultron.payment_links_enabled', false),
            'governance.enabled' => static fn (): bool => (bool) config('marketing.ultron.enabled', false),
            // Si hay canario, nunca cuál: el mismo criterio que el emisor y Mercadeo.
            'governance.canaryOnly' => static fn (): bool => config('marketing.ultron.canary_conversation_id') !== null,
            // El orden con el que el motor ofrece: la insignia primero y luego `sort_order`.
            'strategy.offerPriority' => static fn (): array => Plan::sellable()
                ->orderByDesc('is_recommended')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(self::MAX_PLANS)
                ->pluck('name')
                ->map(static fn (mixed $nombre): string => (string) $nombre)
                ->all(),
            'governance.paused' => fn (): bool => (bool) $this->switch->current()?->paused,
            'governance.brake' => fn (): bool => $this->latch->engaged(),
            // 0 (o vacío) es «sin canario del cobro»; el id no sale.
            'rules.paymentCanary' => static fn (): bool => (int) config('marketing.ultron.payment_canary_conversation_id', 0) > 0,
            'knowledge.base' => fn (): array => $this->knowledgeBase(),
            'strategy.plans' => static fn (): array => Plan::sellable()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(self::MAX_PLANS)
                ->get(['name', 'is_recommended'])
                ->map(static fn (Plan $plan): array => ['name' => (string) $plan->name, 'recommended' => (bool) $plan->is_recommended])
                ->all(),
        ];
    }

    /**
     * Versión del conocimiento (el sello del motor), ítems activos por cada una
     * de las 14 categorías, también las que están a cero, y pendientes de
     * revisión. Sin contenido.
     *
     * @return array{version:string,activeByCategory:array<string,int>,pendingReview:int}
     */
    private function knowledgeBase(): array
    {
        // `reorder()`: el scope ordena por prioridad, y en PostgreSQL un ORDER BY
        // de columnas que no están en el GROUP BY es un error.
        $activos = MarketingKnowledgeItem::query()
            ->activeNow()
            ->reorder()
            ->toBase()
            ->select('category')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $porCategoria = [];
        foreach (MarketingKnowledgeItem::CATEGORIES as $categoria) {
            $porCategoria[$categoria] = (int) ($activos[$categoria] ?? 0);
        }

        return [
            'version' => $this->knowledge->version(),
            'activeByCategory' => $porCategoria,
            'pendingReview' => MarketingKnowledgeItem::query()->pendingReview()->count(),
        ];
    }

    /**
     * `{available:true, value}`, o `{available:false}` si la fuente falló.
     *
     * @param  Closure(): mixed  $leer
     * @return array{available:bool,value?:mixed}
     */
    private function reading(string $id, Closure $leer): array
    {
        try {
            return ['available' => true, 'value' => $leer()];
        } catch (Throwable $e) {
            // A propósito, cualquier cosa: una tabla que no responde, pero
            // también un método del motor que cambió de nombre (Error). El
            // contrato pide que una lectura parcial nunca tumbe la respuesta.
            // Al log, el id y la clase del error; nunca el mensaje, que puede
            // llevar SQL o valores.
            Log::warning('ultron_studio.engine_reading_unavailable', ['reading' => $id, 'error_class' => class_basename($e)]);

            return ['available' => false];
        }
    }
}
