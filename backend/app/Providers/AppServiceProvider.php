<?php

namespace App\Providers;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\Factus\FactusTokenManager;
use App\Services\Exercises\ExerciseCatalogResolver;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\HermesCircuitBreaker;
use App\Services\Marketing\HermesSalesResponder;
use App\Services\Marketing\OpenAiSalesResponder;
use App\Services\Marketing\SalesAgentDecisionValidator;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesAiConfig;
use App\Services\Wompi\WompiConfigValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolver de catálogo de ejercicios: una sola carga de catálogo+aliases
        // por request (evita N+1 al serializar muchas rutinas).
        $this->app->singleton(ExerciseCatalogResolver::class);

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
        \App\Models\MarketingMessage::observe(
            \App\Observers\Marketing\ConversationPreviewObserver::class,
        );

        // Cuando alguien mapea un anuncio a un plan, hay que volver a mirar si
        // lo que promete la pauta sigue existiendo. Meta no manda ese dato, asi
        // que ese mapeo llega SIEMPRE despues del contacto.
        \App\Models\MarketingLeadAttribution::observe(
            \App\Observers\Marketing\AttributionOfferObserver::class,
        );
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
