<?php

namespace App\Console\Commands;

use App\Services\AutomationEventService;
use Illuminate\Console\Command;

/**
 * Emite un evento seguro de prueba (system.test) hacia n8n para validar la
 * conexión end-to-end (capa de eventos + webhook).
 *
 *   php artisan ironbody:n8n-test-event
 */
class N8nTestEvent extends Command
{
    protected $signature = 'ironbody:n8n-test-event';

    protected $description = 'Emite un evento de prueba system.test hacia n8n (valida la capa de automatización).';

    public function handle(AutomationEventService $service): int
    {
        $event = $service->emit('system.test', null, [
            'message' => 'Prueba segura Laravel a n8n',
            'source' => 'iron_body_backend',
        ]);

        $this->info('Evento emitido:');
        $this->line('  id              = ' . $event->id);
        $this->line('  event_type      = ' . $event->event_type);
        $this->line('  status          = ' . $event->status);
        $this->line('  idempotency_key = ' . $event->idempotency_key);

        if ($event->status === 'skipped') {
            $this->warn('n8n está deshabilitado (N8N_ENABLED=false): el evento quedó en "skipped" sin enviarse.');
            $this->line('Para enviarlo: configura N8N_ENABLED=true, N8N_WEBHOOK_URL y N8N_WEBHOOK_SECRET en .env.');
        } else {
            $this->info('Job despachado a la cola. Procesa con: php artisan queue:work');
        }

        return self::SUCCESS;
    }
}
