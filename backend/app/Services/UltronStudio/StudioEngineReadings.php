<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingAgentSetting;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAiConfig;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Lo que el motor de ULTRON lee HOY, para `GET engine/readings` (contrato G
 * §A2): el valor real de los parámetros que tienen mando (`valueSource: live`
 * en el catálogo) y las lecturas que no son parámetros (`readings`).
 *
 * SOLO LECTURA, Y ES LA FRONTERA. Es uno de los pocos ficheros del Studio que
 * nombran clases del motor (los enumera `UltronStudioEngineReadOnlyTest`: con
 * él, `StudioRules`, que ya leía la base de conocimiento, las guardas del
 * laboratorio y, desde el Hito 5, `StudioPublications` y
 * `StudioCanaryMeasures`). Aquí solo se llaman métodos de LECTURA
 * (`effectiveDriver`, `version`, `describe`, consultas de modelo con sus
 * scopes y `config()` con una clave): el Studio no escribe en tablas,
 * configuración ni ficheros del motor. Lo vigilan una prueba por tokens y otra
 * que escucha cada sentencia (`UltronStudioEngineReadOnlyTest`).
 *
 * HITO 5 (contrato H5 §A4.9): la lectura extra `governance.studioPublication`
 * es `UltronPublishedConfig::describe()`: la llave del motor, la cabeza de la
 * cadena con cada comprobación por su lado (contrato, huella, firma, forma),
 * qué partes aplicaría y si hay canario configurado (nunca su id). `describe()`
 * propaga a propósito un fallo de lectura: aquí va dentro de {@see reading()}
 * y sale `{available:false}`, nunca «sin publicaciones». No está en el
 * catálogo (`StudioCatalog::readings()`): añadirla obligaría a subir su versión
 * y a enmendar el fixture de publicación, que la fija.
 *
 * QUÉ NUNCA SALE: la URL del webhook, secretos ni claves, ids de conversación
 * (ni el del canario ni el del canario del cobro: solo si existen), teléfonos,
 * precios, ids de planes ni el contenido de la base de conocimiento.
 *
 * FALLO SUAVE. Cada lectura va por su cuenta: si una fuente falla (una tabla que
 * no responde o no existe, un método del motor que cambió de nombre), esa
 * entrada sale `{available:false}`, sin mensaje técnico, deja una línea en el
 * log sin datos y las demás siguen. Nunca hay un 500 por una lectura parcial,
 * y nunca un valor por defecto que parezca leído.
 *
 * MATICES (contrato G §A2):
 *  - La pausa y el freno se leen con la MISMA consulta que el motor
 *    ({@see MarketingAgentSwitch::current()}, {@see UltronAbortLatch::current()})
 *    pero sin pasar por ellos: los dos se tragan un fallo de la consulta (el
 *    freno, cualquiera; la pausa, una tabla que no existe) y devuelven null,
 *    que es «sin pausa» y «freno suelto». Para el motor es la forma de seguir
 *    funcionando; aquí sería enseñar como dato real algo que no se pudo leer
 *    (revisión de seguridad del Hito G, I-1).
 *  - Las banderas son las del PROCESO WEB (con su caché de configuración): un
 *    worker sin reiniciar puede ir por detrás. Lo dice `note`.
 */
final class StudioEngineReadings
{
    /** Los parámetros del borrador que se leen en vivo (`valueSource: live` en el catálogo), en el orden del contrato. */
    public const PARAMS = ['intent.driver', 'rules.paymentLinksEnabled', 'governance.enabled', 'governance.canaryOnly', 'strategy.offerPriority'];

    /** Las lecturas que no son parámetros ({@see StudioCatalog::readings()}), en su orden. */
    public const EXTRAS = ['governance.paused', 'governance.brake', 'rules.paymentCanary', 'knowledge.base', 'strategy.plans'];

    /** Hito 5 (contrato H5 §A4.9): la lectura extra de la cadena de publicaciones, al final de `extras`. */
    public const PUBLICATION_EXTRA = 'governance.studioPublication';

    /** Los cuatro interruptores que la declaración tiene que decir igual que el motor (§A1), en el orden de PARAMS. */
    public const MUST_MATCH = ['intent.driver', 'rules.paymentLinksEnabled', 'governance.enabled', 'governance.canaryOnly'];

    /** Planes como mucho en cada lectura de planes: los nombres, nada más. */
    public const MAX_PLANS = 20;

    public const NOTE = 'Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician.';

    public function __construct(
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly UltronPublishedConfig $publishedConfig,
    ) {}

    /**
     * La forma de `GET engine/readings`.
     *
     * @return array{readAt:string,params:array<string,array{available:bool,value?:mixed}>,extras:array<string,array{available:bool,value?:mixed}>,note:string}
     */
    public function read(): array
    {
        $lecturas = $this->only(self::PARAMS, [...self::EXTRAS, self::PUBLICATION_EXTRA]);

        return [
            'readAt' => now()->toIso8601String(),
            'params' => $lecturas['params'],
            'extras' => $lecturas['extras'],
            'note' => self::NOTE,
        ];
    }

    /**
     * Solo algunas lecturas, con el mismo criterio y el mismo fallo suave que
     * {@see read()}. Publicar, la vista previa, la deriva y la evidencia solo
     * necesitan los cuatro `must_match` y el freno: así no leen los planes ni
     * la base de conocimiento en cada petición.
     *
     * @param  list<string>  $params  ids de {@see PARAMS}
     * @param  list<string>  $extras  ids de {@see EXTRAS} o {@see PUBLICATION_EXTRA}
     * @return array{params:array<string,array{available:bool,value?:mixed}>,extras:array<string,array{available:bool,value?:mixed}>}
     */
    public function only(array $params, array $extras = []): array
    {
        $lectores = $this->readers();
        $leer = function (array $ids, array $validos) use ($lectores): array {
            $out = [];
            foreach ($ids as $id) {
                if (! in_array($id, $validos, true)) {
                    throw new InvalidArgumentException("No hay ninguna lectura del motor llamada {$id}.");
                }
                $out[$id] = $this->reading($id, $lectores[$id]);
            }

            return $out;
        };

        return [
            'params' => $leer($params, self::PARAMS),
            'extras' => $leer($extras, [...self::EXTRAS, self::PUBLICATION_EXTRA]),
        ];
    }

    /**
     * `UltronPublishedConfig::describe()` tal cual (contrato H5 §A4.9). PROPAGA
     * un fallo de lectura: quien llama decide qué hacer (la evidencia del
     * canario lo cuenta como firma no verificada; nunca como «todo bien»).
     *
     * @return array{env:string,head:array{entry:?string,seq:?int,contract:?string,hashOk:bool,signatureOk:bool,parseOk:bool}|null,parts:array{canary:bool,rest:bool},canaryConfigured:bool}
     */
    public function publication(): array
    {
        return $this->publishedConfig->describe();
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
            // La fila del interruptor (sin fila, el motor no pausa) y la parada
            // abierta del freno: las consultas del motor, sin su red (ver arriba).
            'governance.paused' => static fn (): bool => (bool) MarketingAgentSetting::query()->find(MarketingAgentSwitch::ROW_ID)?->paused,
            'governance.brake' => static fn (): bool => UltronAbort::query()->open()->exists(),
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
            // Hito 5 (§A4.9): sin el id del canario, solo si existe.
            self::PUBLICATION_EXTRA => fn (): array => $this->publishedConfig->describe(),
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
