<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabScenario;
use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioLabRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Datos y peticiones del LABORATORIO de ULTRON STUDIO (Hito 4) para las
 * pruebas Feature. Se usa junto a {@see StudioFixtures}: las peticiones van con
 * la sesión REAL de cada persona.
 *
 * La configuración del laboratorio se fija con `config()` en cada prueba: la
 * suite no depende del `.env`. La clave del laboratorio es una CENTINELA que
 * no puede aparecer en ninguna respuesta, evento ni línea de log.
 */
trait LabFixtures
{
    /** La clave del laboratorio en las pruebas. Si sale en algún sitio, es una fuga. */
    protected const SENTINEL_KEY = 'sk-lab-CENTINELA-9f8e7d6c5b4a3210zyxwvutsrqponm';

    /** La clave del motor (la de IRON IA): el laboratorio no puede usarla nunca. */
    protected const ENGINE_KEY = 'sk-motor-NO-USAR-1234567890abcdefghijklmnopq';

    protected function labOff(): void
    {
        config(['ultron_studio.lab.enabled' => false]);
    }

    /** Encendido en modo `fake`: sin red y coste 0. */
    protected function labFake(array $extra = []): void
    {
        // Lo que llega en `$extra` manda sobre lo de por defecto.
        config($extra + ['ultron_studio.lab.enabled' => true, 'ultron_studio.lab.driver' => 'fake']);
    }

    /**
     * Encendido en modo `openai` y configurado: clave propia (la centinela),
     * modelo, precios (0,40 / 1,60 US$ por millón) y presupuesto (2 US$/día,
     * 0,50 por ejecución).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function labOpenAi(array $extra = []): void
    {
        // Lo que llega en `$extra` manda sobre lo de por defecto.
        config($extra + [
            'services.openai.api_key' => self::ENGINE_KEY,
            'ultron_studio.lab.enabled' => true,
            'ultron_studio.lab.driver' => 'openai',
            'ultron_studio.lab.openai.api_key' => self::SENTINEL_KEY,
            'ultron_studio.lab.openai.forbidden_key_sha256' => hash('sha256', self::ENGINE_KEY),
            'ultron_studio.lab.openai.model' => 'gpt-lab-pruebas',
            'ultron_studio.lab.openai.base_url' => null,
            'ultron_studio.lab.openai.temperature' => null,
            'ultron_studio.lab.openai.reasoning_effort' => null,
            'ultron_studio.lab.price_input_usd_per_mtok' => '0.40',
            'ultron_studio.lab.price_output_usd_per_mtok' => '1.60',
            'ultron_studio.lab.daily_budget_usd' => '2',
            'ultron_studio.lab.run_budget_usd' => '0.50',
            'ultron_studio.lab.max_runs_per_day' => 20,
            'ultron_studio.lab.max_cases_per_run' => 12,
            'ultron_studio.lab.actor_max_output_tokens' => 400,
            'ultron_studio.lab.judge_max_output_tokens' => 900,
            'ultron_studio.lab.timeout_seconds' => 60,
        ]);
    }

    /**
     * Registra un proveedor guionizado SOLO en el contenedor de esta prueba.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    protected function script(array $steps): ScriptedLabClient
    {
        $cliente = new ScriptedLabClient($steps);
        $this->app->instance(StudioLabModelClient::class, $cliente);

        return $cliente;
    }

    protected function importLabScenarios(): void
    {
        $this->assertSame(0, Artisan::call('ultron-studio:lab-import-scenarios'));
    }

    /** @param  array<string, mixed>  $body */
    protected function lab(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->studio($method, 'lab/'.ltrim($path, '/'), $as, $body);
    }

    protected function scenarioUuid(string $code): string
    {
        $uuid = StudioLabScenario::query()->where('code', $code)->value('uuid');
        $this->assertNotNull($uuid, "No existe el escenario {$code}.");

        return (string) $uuid;
    }

    /**
     * Un escenario del personal válido, con cambios.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function scenarioBody(array $overrides = []): array
    {
        return array_replace([
            'category' => 'pago',
            'title' => 'Pregunta si llegó la transferencia',
            'description' => 'La persona dice que pagó y el CRM no tiene el pago.',
            'turns' => [
                ['role' => 'prospect', 'text' => 'Hola, ya pagué la mensualidad'],
                ['role' => 'agent', 'text' => 'Gracias por escribir. ¿Por qué medio pagaste?'],
                ['role' => 'prospect', 'text' => 'Por transferencia, ¿ya te llegó?'],
            ],
            'facts' => ['paymentState' => 'none', 'handoffRequested' => false],
            'criteria' => [
                ['kind' => 'must_not', 'text' => 'Afirmar que el pago llegó', 'rules' => ['R-04']],
                ['kind' => 'must', 'text' => 'Decir que el pago se verifica antes de confirmar', 'rules' => []],
            ],
            'clientRequestId' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * Estima y lanza con el máximo que dio la estimación.
     *
     * @param  array<string, mixed>  $target
     * @param  list<string>  $codes  códigos de escenario
     */
    protected function launch(Admin $as, array $target, array $codes, ?string $clientRequestId = null): TestResponse
    {
        $uuids = array_map(fn (string $c): string => $this->scenarioUuid($c), $codes);
        $estimacion = $this->lab('POST', 'estimate', $as, ['target' => $target, 'scenarioUuids' => $uuids])->assertOk()->json('data');

        return $this->lab('POST', 'runs', $as, [
            'target' => $target,
            'scenarioUuids' => $uuids,
            'acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'],
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Los pasos de una ejecución, EN LÍNEA (con la cola falsa), hasta que
     * termina o hasta `$max` pasos.
     */
    protected function drain(string $uuid, int $max = 60): StudioLabRun
    {
        $run = StudioLabRun::query()->where('uuid', $uuid)->firstOrFail();
        $runner = app(StudioLabRunner::class);
        for ($i = 0; $i < $max && ! $run->refresh()->isTerminal(); $i++) {
            $runner->step($run->id);
        }

        return $run->refresh();
    }

    /**
     * Un paso que FALLA ANTES DE RECLAMAR (H-P2-1 del tester: la conexión con
     * la base se corta mientras el paso espera el cerrojo de los reclamos). La
     * primera consulta del paso tras cargar la ejecución (la de sus casos en
     * curso) lanza, como lanzaría PDO: el paso no liquida nada ni encola el
     * siguiente. Devuelve la excepción, la que el worker pasa a `failed()`.
     */
    protected function stepFailsBeforeClaiming(int $runId): RuntimeException
    {
        $armada = true;
        DB::listen(function (QueryExecuted $q) use (&$armada, $runId): void {
            if ($armada && str_contains($q->sql, '"ultron_studio_lab_run_cases"') && in_array('running', $q->bindings, true) && in_array($runId, $q->bindings, true)) {
                $armada = false;
                throw new RuntimeException('SQLSTATE[08006]: no connection to the server (simulada)');
            }
        });

        try {
            $resultado = app(StudioLabRunner::class)->step($runId);
        } catch (RuntimeException $e) {
            $this->assertFalse($armada);

            return $e;
        }
        $armada = false;
        $this->fail("El paso no falló antes de reclamar: devolvió «{$resultado}».");
    }

    /** @return array<string, mixed> el objetivo «versión» de un uuid */
    protected static function versionTarget(string $uuid): array
    {
        return ['kind' => 'version', 'uuid' => $uuid];
    }
}
