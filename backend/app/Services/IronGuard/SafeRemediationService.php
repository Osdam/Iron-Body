<?php

namespace App\Services\IronGuard;

use App\Jobs\DownloadWhatsappMedia;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MarketingMessageAttachment;
use App\Models\MetaWebhookEvent;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Artisan;

/**
 * Las únicas acciones que IRON GUARD puede ejecutar.
 *
 * La lista está en config y esta clase solo sabe hacer lo que hay en ella. Es
 * deliberadamente corta y toda ella cumple tres condiciones: es reversible, es
 * idempotente, y no puede empeorar la situación si se ejecuta cuando no hacía
 * falta.
 *
 * Lo que NO puede hacer, y no por olvido: migrar, borrar datos, reiniciar
 * PostgreSQL, tocar el firewall o el DNS, cambiar configuración de Meta,
 * desplegar código, enviar mensajes o emitir facturas. Un agente que interpreta
 * texto de desconocidos no debe tener ninguna de esas capacidades ni a través
 * de tres capas de indirección.
 *
 * Cada ejecución queda auditada en `incident_events` con quién la pidió.
 */
class SafeRemediationService
{
    public function __construct(private readonly IncidentRecorder $recorder) {}

    /**
     * Ejecuta una acción de la allowlist sobre un incidente.
     *
     * @return array{ok:bool,action:string,result:?string,reason:?string}
     */
    public function run(Incident $incident, string $action, string $actor): array
    {
        $allowlist = (array) config('observability.remediation.allowlist', []);

        if (! in_array($action, $allowlist, true)) {
            // Ni siquiera se intenta. Que una acción no esté en la lista no es
            // un caso a resolver: es la respuesta.
            ChannelLog::warning('guard.remediation.refused', [
                'incident_id' => $incident->id,
                'action' => $action,
                'reason' => 'not_in_allowlist',
            ]);

            return ['ok' => false, 'action' => $action, 'result' => null, 'reason' => 'not_in_allowlist'];
        }

        // Un mismo incidente no puede pedir la misma acción indefinidamente: si
        // tres intentos no lo arreglaron, el cuarto tampoco y hace falta una
        // persona. Esto es lo que evita el bucle "reintenta lo que nunca va a ir".
        $attempts = IncidentEvent::where('incident_id', $incident->id)
            ->where('kind', IncidentEvent::KIND_REMEDIATION)
            ->where('payload->action', $action)
            ->count();

        $max = (int) config('observability.remediation.max_attempts_per_incident', 3);

        if ($attempts >= $max) {
            return ['ok' => false, 'action' => $action, 'result' => null, 'reason' => 'max_attempts_reached'];
        }

        $result = match ($action) {
            'replay_webhook_event' => $this->replayWebhookEvents($incident),
            'retry_media_download' => $this->retryMediaDownloads($incident),
            'retry_failed_job' => $this->retryFailedJobs(),
            'clear_config_cache' => $this->clearConfigCache(),
            default => null,
        };

        $this->recorder->addEvent(
            $incident,
            IncidentEvent::KIND_REMEDIATION,
            $result ?? 'Sin efecto.',
            ['action' => $action, 'attempt' => $attempts + 1],
            $actor,
        );

        ChannelLog::info('guard.remediation.executed', [
            'incident_id' => $incident->id,
            'action' => $action,
            'actor' => $actor,
            'attempt' => $attempts + 1,
        ]);

        return ['ok' => true, 'action' => $action, 'result' => $result, 'reason' => null];
    }

    /**
     * Reencola los eventos concretos de este incidente. Reprocesar no duplica
     * nada: la idempotencia por meta_message_id sigue aguas abajo.
     */
    private function replayWebhookEvents(Incident $incident): string
    {
        $ids = (array) data_get($incident->evidence, 'event_ids', []);

        if ($ids === []) {
            Artisan::call('marketing:replay-webhooks', ['--include-dead' => true]);

            return 'Reencolados todos los eventos atascados.';
        }

        $count = 0;
        foreach ($ids as $id) {
            $event = MetaWebhookEvent::find($id);
            if ($event === null) {
                continue;
            }
            if ($event->status === MetaWebhookEvent::STATUS_PROCESSED) {
                continue; // ya se recuperó solo
            }
            \App\Jobs\ProcessMetaWebhookEvent::dispatch($event->id);
            $count++;
        }

        return sprintf('Reencolados %d evento(s).', $count);
    }

    private function retryMediaDownloads(Incident $incident): string
    {
        $ids = (array) data_get($incident->evidence, 'attachment_ids', []);
        $count = 0;

        foreach ($ids as $id) {
            $attachment = MarketingMessageAttachment::find($id);
            // Lo RECHAZADO por política no se reintenta: repetirlo solo
            // repetiría el rechazo. Solo vuelven los fallos transitorios.
            if ($attachment === null || $attachment->status !== MarketingMessageAttachment::STATUS_FAILED) {
                continue;
            }
            $attachment->forceFill(['status' => MarketingMessageAttachment::STATUS_PENDING])->save();
            DownloadWhatsappMedia::dispatch($attachment->id);
            $count++;
        }

        return sprintf('Reintentadas %d descarga(s).', $count);
    }

    private function retryFailedJobs(): string
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return 'Jobs fallidos devueltos a la cola.';
    }

    /**
     * Limpiar la caché de configuración es reversible y no destruye nada: se
     * regenera en el siguiente `config:cache`. Es la única acción sobre estado
     * del servidor que entra en la lista.
     */
    private function clearConfigCache(): string
    {
        Artisan::call('config:clear');

        return 'Caché de configuración limpiada.';
    }
}
