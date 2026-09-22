<?php

namespace Tests\Feature;

use App\Jobs\SendAutomationEventToN8n;
use App\Models\AutomationEvent;
use App\Services\AutomationEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * UNA AVALANCHA DE NOTIFICACIONES SE REPARTE EN EL TIEMPO.
 *
 * El detector diario de cumplimiento recorre a todos los socios y emitió 3.758
 * eventos en 28 segundos. n8n los relevó a unas veinte peticiones por segundo
 * y el tope de la puerta rechazó 3.278 de 3.870: el 85% de esas notificaciones
 * no llegó a nadie. La avalancha se ahogó sola.
 *
 * Lo que se fija aquí es la cadencia: una ráfaga se reparte, y un evento suelto
 * no espera nada.
 */
class CadenciaDeAutomatizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
    }

    private function evento(int $i): AutomationEvent
    {
        return AutomationEvent::create([
            'event_type' => 'daily.compliance_missing',
            'member_id' => $i,
            'idempotency_key' => 'k'.$i,
            'status' => AutomationEvent::STATUS_PENDING ?? 'pending',
            'payload' => [],
        ]);
    }

    public function test_a_lone_event_waits_for_nothing(): void
    {
        app(AutomationEventService::class)->dispatch($this->evento(1));

        Queue::assertPushed(SendAutomationEventToN8n::class, function ($job) {
            return ($job->delay ?? 0) === 0 || $job->delay === null;
        });
    }

    public function test_a_burst_is_spread_instead_of_drowning_itself(): void
    {
        config()->set('automation.dispatch_per_minute', 60); // un evento por segundo

        $servicio = app(AutomationEventService::class);
        foreach (range(1, 30) as $i) {
            $servicio->dispatch($this->evento($i));
        }

        $retrasos = [];
        Queue::assertPushed(SendAutomationEventToN8n::class, function ($job) use (&$retrasos) {
            $retrasos[] = (int) ($job->delay ?? 0);

            return true;
        });

        sort($retrasos);

        $this->assertCount(30, $retrasos);
        $this->assertSame(0, $retrasos[0], 'el primero no tiene por qué esperar');
        $this->assertGreaterThanOrEqual(
            20,
            end($retrasos),
            'treinta eventos a uno por segundo tienen que repartirse, no salir todos de golpe',
        );
    }
}
