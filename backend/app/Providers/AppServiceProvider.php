<?php

namespace App\Providers;

use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Observers\Marketing\AttributionOfferObserver;
use App\Observers\Marketing\ConversationPreviewObserver;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\Factus\FactusTokenManager;
use App\Services\Exercises\ExerciseCatalogResolver;
use App\Services\Marketing\Analytics\AdvertisingSpendProvider;
use App\Services\Marketing\Analytics\UnavailableSpendProvider;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\HermesCircuitBreaker;
use App\Services\Marketing\HermesSalesResponder;
use App\Services\Marketing\OpenAiSalesResponder;
use App\Services\Marketing\SalesAgentDecisionValidator;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesAiConfig;
use App\Services\Meta\WhatsappIntegrationRegistry;
use App\Services\Observability\QueueHealthService;
use App\Services\Wompi\WompiConfigValidator;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Hoy NO hay fuente de gasto publicitario. Se enlaza la implementacion
        // que lo dice en vez de una que devuelva cero: un ROAS calculado sobre
        // gasto cero es una mentira con formato de numero, y alguien tomaria
        // una decision de presupuesto con ella.
        $this->app->bind(
            AdvertisingSpendProvider::class,
            UnavailableSpendProvider::class,
        );

        // Resolver de catálogo de ejercicios: una sola carga de catálogo+aliases
        // por request (evita N+1 al serializar muchas rutinas).
        $this->app->singleton(ExerciseCatalogResolver::class);

        // De dónde salen las credenciales de WhatsApp Cloud API: la conexión
        // hecha desde el CRM si existe, y si no el .env de siempre. Singleton
        // porque la respuesta no cambia a mitad de petición y sin memoria
        // enviar diez mensajes serían diez consultas idénticas.
        $this->app->singleton(WhatsappIntegrationRegistry::class);

        // Facturación electrónica (Factus): el token manager y el cliente HTTP
        // se construyen desde config(billing) (constructores con array $cfg),
        // por eso se registran explícitamente para que el contenedor los inyecte.
        $this->app->bind(FactusTokenManager::class, fn () => FactusTokenManager::fromConfig());
        $this->app->bind(
            FactusClient::class,
            fn ($app) => new FactusClient($app->make(FactusTokenManager::class), (array) config('billing')),
        );

        // Cerebro comercial IA. Cadena de degradación hermes → openai → fake:
        // cada eslabón solo se usa si TODO lo suyo está listo, y si falta algo
        // cae al siguiente. Por defecto queda el responder DETERMINISTA (fake),
        // así que ningún fallo de proveedor rompe producción. El responder
        // efectivo se registra en metadata para auditoría.
        $this->app->bind(AiSalesResponderInterface::class, function ($app) {
            $openAi = fn () => new OpenAiSalesResponder(
                new FakeAiSalesResponder,
                $app->make(SalesAgentPromptBuilder::class),
                $app->make(SalesAgentDecisionValidator::class),
            );

            return match (SalesAiConfig::effectiveDriver()) {
                'hermes' => new HermesSalesResponder(
                    $openAi(),
                    $app->make(SalesAgentPromptBuilder::class),
                    $app->make(SalesAgentDecisionValidator::class),
                    $app->make(HermesCircuitBreaker::class),
                ),
                'openai' => $openAi(),
                default => new FakeAiSalesResponder,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardWompiConfig();
        $this->guardFactusConfig();

        // La previsualizacion de la lista se mantiene desde la TABLA de
        // mensajes, no desde cada servicio que envia. Los mensajes nacen por
        // seis caminos distintos y basta que uno se olvide para que la bandeja
        // muestre texto viejo; un observador no se lo puede saltar ninguno.
        MarketingMessage::observe(
            ConversationPreviewObserver::class,
        );

        // Cuando alguien mapea un anuncio a un plan, hay que volver a mirar si
        // lo que promete la pauta sigue existiendo. Meta no manda ese dato, asi
        // que ese mapeo llega SIEMPRE despues del contacto.
        MarketingLeadAttribution::observe(
            AttributionOfferObserver::class,
        );

        /*
         * Los workers viven horas. Sin esto, el primero que resolviera «no hay
         * conexión de WhatsApp» se quedaría con esa respuesta en memoria y
         * seguiría usando el `.env` para siempre, aunque alguien conectara la
         * cuenta desde el CRM cinco minutos después. El síntoma sería de los
         * malos: el canal funciona en la web y no en las colas, y sólo se
         * arregla reiniciando el worker sin que nadie sepa por qué.
         *
         * Una consulta por trabajo es un precio que no se nota; el trabajo ya
         * abrió la base de datos para todo lo demás.
         */
        Queue::before(function (): void {
            app(WhatsappIntegrationRegistry::class)->forget();
        });

        /*
         * El latido de los carriles de cola.
         *
         * Cada trabajo terminado deja constancia de que SU carril tiene a
         * alguien atendiéndolo. Es la única señal que no se puede falsear: un
         * worker puede estar arrancado y bloqueado, o vivo pero escuchando la
         * cola equivocada por un error de configuración, y en los dos casos
         * Supervisor diría que todo va bien. Lo que importa es si el trabajo
         * avanza, y esto lo registra.
         *
         * Es best-effort a propósito: si el cache no está disponible, se pierde
         * visibilidad, pero ni un solo trabajo falla por ello.
         */
        Queue::after(function (JobProcessed $event): void {
            try {
                app(QueueHealthService::class)
                    ->heartbeat($event->job->getQueue() ?: 'default');
            } catch (\Throwable) {
                // La vigilancia no puede ser el motivo de que algo se rompa.
            }
        });
    }

    /**
     * Impide arrancar con una configuración Wompi que mezcle ambientes
     * (sandbox/producción). No corre en consola de pruebas ni rompe el arranque
     * cuando Wompi aún no está configurado (placeholders vacíos en dev). Un
     * MISMATCH real (llave del ambiente equivocado) sí aborta: es lo correcto.
     */
    private function guardWompiConfig(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        try {
            $validator = WompiConfigValidator::fromConfig();
            $hard = $validator->hardIssues();
            if ($hard !== []) {
                // En producción es fatal (no procesar pagos mal configurados);
                // en local solo se advierte para no bloquear el desarrollo.
                if (app()->environment('production')) {
                    throw new \RuntimeException(implode(' | ', $hard));
                }
                Log::warning('Wompi config con advertencias', ['issues' => $hard]);
            }
        } catch (\RuntimeException $e) {
            if (app()->environment('production')) {
                throw $e;
            }
            Log::warning('Wompi config inválida (no fatal fuera de producción)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Impide arrancar producción con facturación electrónica mal configurada
     * (credenciales/URL de sandbox, sin datos del emisor o sin rango DIAN). Con
     * FACTUS_ENABLED=false el módulo está inerte y no se valida nada. No corre
     * en pruebas. Fuera de producción solo advierte para no bloquear el dev.
     */
    private function guardFactusConfig(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        try {
            $hard = FactusConfigValidator::fromConfig()->hardIssues();
            if ($hard !== []) {
                if (app()->environment('production')) {
                    throw new \RuntimeException(implode(' | ', $hard));
                }
                Log::warning('Factus config con advertencias', ['issues' => $hard]);
            }
        } catch (\RuntimeException $e) {
            if (app()->environment('production')) {
                throw $e;
            }
            Log::warning('Factus config inválida (no fatal fuera de producción)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
