<?php

namespace App\Providers;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\Factus\FactusTokenManager;
use App\Services\Exercises\ExerciseCatalogResolver;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\OpenAiSalesResponder;
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

        // Cerebro comercial IA. Por defecto el responder DETERMINISTA (fake, sin
        // OpenAI). Solo usa OpenAI si TODO está listo (driver=openai + flag +
        // OPENAI_API_KEY + modelo); si falta algo, cae a fake (nunca rompe prod).
        // El responder efectivo (fake/openai/fallback) se registra en metadata.
        $this->app->bind(AiSalesResponderInterface::class, function ($app) {
            if (SalesAiConfig::effectiveDriver() === 'openai') {
                return new OpenAiSalesResponder(
                    new FakeAiSalesResponder(),
                    $app->make(\App\Services\Marketing\SalesAgentPromptBuilder::class),
                    $app->make(\App\Services\Marketing\SalesAgentDecisionValidator::class),
                );
            }
            return new FakeAiSalesResponder();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardWompiConfig();
        $this->guardFactusConfig();
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
