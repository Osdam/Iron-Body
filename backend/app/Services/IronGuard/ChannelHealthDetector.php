<?php

namespace App\Services\IronGuard;

use App\Models\Incident;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Models\MetaWebhookEvent;
use App\Services\Marketing\HermesCircuitBreaker;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detección determinista de problemas del canal.
 *
 * Deliberadamente NO analiza logs con un modelo. Los logs son texto libre: una
 * expresión regular sobre prosa se rompe en cuanto alguien cambia una frase, y
 * mandar cada línea a un LLM cuesta dinero y produce alucinaciones justo cuando
 * más falta hace la exactitud.
 *
 * En su lugar se consulta el ESTADO que ya guardamos en tablas propias: eventos
 * de Meta sin procesar, mensajes muertos en el outbox, adjuntos rechazados,
 * códigos de error de Meta, jobs fallidos. Son hechos, no interpretaciones, y
 * cada uno trae su propia evidencia consultable.
 *
 * La IA entra después y solo si se pide: sobre un incidente ya agrupado, para
 * proponer una causa raíz. Nunca en el camino de detección.
 */
class ChannelHealthDetector
{
    public function __construct(private readonly IncidentRecorder $recorder) {}

    /**
     * Pasa todas las comprobaciones. Devuelve los incidentes vivos detectados.
     *
     * Ninguna comprobación puede tumbar a las demás: si una consulta falla, se
     * registra y se sigue. Un detector que muere en la primera piedra es peor
     * que no tener detector, porque da falsa sensación de calma.
     *
     * @return array<int, Incident>
     */
    public function scan(): array
    {
        $checks = [
            'stuckWebhookEvents',
            'deadWebhookEvents',
            'deadOutboundMessages',
            'metaErrorCodes',
            'failedMediaDownloads',
            'failedJobs',
            'hermesCircuitOpen',
            'unattendedEscalations',
        ];

        $incidents = [];
        $max = (int) config('observability.incidents.max_per_run', 50);

        foreach ($checks as $check) {
            if (count($incidents) >= $max) {
                break; // anti-avalancha: una corrida no puede abrir el mundo entero
            }

            try {
                $incident = $this->{$check}();
                if ($incident instanceof Incident) {
                    $incidents[] = $incident;
                }
            } catch (\Throwable $e) {
                ChannelLog::error('guard.check_failed', [
                    'check' => $check,
                    'error_class' => class_basename($e),
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return $incidents;
    }

    /**
     * Eventos de Meta que llegaron y nadie procesó.
     *
     * Es el fallo más grave posible del canal: significa que hay prospectos que
     * escribieron y a los que nadie contestó, ni la IA ni una persona, porque
     * su mensaje nunca llegó al inbox.
     */
    private function stuckWebhookEvents(): ?Incident
    {
        $minutes = (int) config('observability.raw_events.stuck_after_minutes', 10);

        $stuck = MetaWebhookEvent::query()
            ->whereIn('status', [MetaWebhookEvent::STATUS_PENDING, MetaWebhookEvent::STATUS_PROCESSING])
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get(['id', 'correlation_id', 'status', 'attempts', 'created_at', 'messages_count']);

        if ($stuck->isEmpty()) {
            return null;
        }

        $withMessages = $stuck->sum('messages_count');

        return $this->recorder->record([
            'source' => 'meta_webhook',
            'kind' => 'events_stuck',
            'fingerprint_keys' => ['pending'],
            'title' => sprintf('%d evento(s) de Meta sin procesar hace más de %d min', $stuck->count(), $minutes),
            // Si hay mensajes de personas dentro, es crítico: alguien está
            // esperando respuesta ahora mismo.
            'severity' => $withMessages > 0 ? Incident::SEVERITY_CRITICAL : Incident::SEVERITY_HIGH,
            'affected_messages' => (int) $withMessages,
            'evidence' => [
                'stuck_count' => $stuck->count(),
                'messages_waiting' => (int) $withMessages,
                'oldest_minutes' => (int) $stuck->min('created_at')?->diffInMinutes(now()),
                'event_ids' => $stuck->take(10)->pluck('id')->all(),
                'probable_cause' => 'El worker de la cola no está corriendo o está atascado.',
                'check_command' => 'supervisorctl status ironbody-queue-worker:*',
                'safe_remediation' => 'marketing:replay-webhooks',
            ],
            'correlation_ids' => $stuck->pluck('correlation_id')->all(),
        ]);
    }

    /** Eventos que agotaron sus reintentos: ya nadie los va a recuperar solo. */
    private function deadWebhookEvents(): ?Incident
    {
        $dead = MetaWebhookEvent::query()
            ->where('status', MetaWebhookEvent::STATUS_DEAD)
            ->where('updated_at', '>=', now()->subDay())
            ->get(['id', 'correlation_id', 'last_error_class', 'last_error', 'messages_count']);

        if ($dead->isEmpty()) {
            return null;
        }

        return $this->recorder->record([
            'source' => 'meta_webhook',
            'kind' => 'events_dead',
            // Se agrupan por clase de error: dos fallos distintos son dos
            // incidentes, aunque ambos acaben en 'dead'.
            'fingerprint_keys' => [$dead->first()->last_error_class ?? 'unknown'],
            'title' => sprintf('%d evento(s) de Meta agotaron sus reintentos', $dead->count()),
            'severity' => Incident::SEVERITY_CRITICAL,
            'affected_messages' => (int) $dead->sum('messages_count'),
            'evidence' => [
                'dead_count' => $dead->count(),
                'error_class' => $dead->first()->last_error_class,
                'error_sample' => mb_substr((string) $dead->first()->last_error, 0, 300),
                'event_ids' => $dead->take(10)->pluck('id')->all(),
                'safe_remediation' => 'marketing:replay-webhooks --include-dead',
            ],
            'correlation_ids' => $dead->pluck('correlation_id')->all(),
        ]);
    }

    /**
     * Respuestas que nunca llegaron al cliente y ya no se van a reintentar.
     * Cada una es una persona que preguntó algo y se quedó sin contestación.
     */
    private function deadOutboundMessages(): ?Incident
    {
        $dead = MarketingMessage::query()
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->where('status', WhatsappOutboxService::STATUS_DEAD)
            ->where('updated_at', '>=', now()->subDay())
            ->get(['id', 'conversation_id', 'last_error_code', 'last_error_message', 'correlation_id']);

        if ($dead->isEmpty()) {
            return null;
        }

        // El código de error de Meta es lo que distingue un problema de otro:
        // "ventana de 24h cerrada" y "número inválido" no se arreglan igual.
        $topCode = $dead->groupBy('last_error_code')->sortByDesc->count()->keys()->first();

        return $this->recorder->record([
            'source' => 'outbox',
            'kind' => 'messages_dead',
            'fingerprint_keys' => [$topCode ?? 'unknown'],
            'title' => sprintf('%d respuesta(s) no llegaron al cliente (código %s)', $dead->count(), $topCode ?: 'desconocido'),
            'severity' => $dead->count() >= 5 ? Incident::SEVERITY_HIGH : Incident::SEVERITY_MEDIUM,
            'affected_conversations' => $dead->pluck('conversation_id')->unique()->count(),
            'affected_messages' => $dead->count(),
            'evidence' => [
                'dead_count' => $dead->count(),
                'meta_error_code' => $topCode,
                'error_sample' => mb_substr((string) $dead->first()->last_error_message, 0, 300),
                'message_ids' => $dead->take(10)->pluck('id')->all(),
                'hint' => $this->hintForMetaCode($topCode),
            ],
            'correlation_ids' => $dead->pluck('correlation_id')->all(),
        ]);
    }

    /**
     * Errores de Meta agrupados por código en la última hora. Detecta problemas
     * de cuenta (número restringido, límite de calidad) antes de que alguien se
     * dé cuenta por el silencio.
     */
    private function metaErrorCodes(): ?Incident
    {
        if (! Schema::hasTable('marketing_message_statuses')) {
            return null;
        }

        $row = DB::table('marketing_message_statuses')
            ->select('error_code', DB::raw('count(*) as total'))
            ->whereNotNull('error_code')
            ->where('created_at', '>=', now()->subHour())
            ->groupBy('error_code')
            ->orderByDesc('total')
            ->first();

        // Un fallo suelto es ruido normal del canal; un patrón no lo es.
        if ($row === null || (int) $row->total < 5) {
            return null;
        }

        return $this->recorder->record([
            'source' => 'meta_api',
            'kind' => 'error_code_spike',
            'fingerprint_keys' => [$row->error_code],
            'title' => sprintf('Meta rechazó %d envíos con el código %s en la última hora', $row->total, $row->error_code),
            'severity' => (int) $row->total >= 20 ? Incident::SEVERITY_HIGH : Incident::SEVERITY_MEDIUM,
            'affected_messages' => (int) $row->total,
            'evidence' => [
                'meta_error_code' => (int) $row->error_code,
                'occurrences_last_hour' => (int) $row->total,
                'hint' => $this->hintForMetaCode($row->error_code),
            ],
        ]);
    }

    /** Archivos que llegaron y no se pudieron guardar: el inbox los muestra rotos. */
    private function failedMediaDownloads(): ?Incident
    {
        $failed = MarketingMessageAttachment::query()
            ->whereIn('status', [MarketingMessageAttachment::STATUS_FAILED, MarketingMessageAttachment::STATUS_REJECTED])
            ->where('updated_at', '>=', now()->subHours(6))
            ->get(['id', 'kind', 'failure_reason', 'message_id']);

        if ($failed->count() < 3) {
            return null; // un archivo suelto puede ser el cliente, no nosotros
        }

        $topReason = $failed->groupBy('failure_reason')->sortByDesc->count()->keys()->first();

        // Un MIME falso es el sistema defendiéndose y funcionando bien; que no
        // se pueda descargar nada es una avería nuestra.
        $isDefensive = in_array($topReason, ['mime_mismatch', 'too_large', 'too_large_declared'], true)
            || str_starts_with((string) $topReason, 'disallowed_mime');

        return $this->recorder->record([
            'source' => 'media',
            'kind' => 'downloads_failing',
            'fingerprint_keys' => [$topReason ?? 'unknown'],
            'title' => sprintf('%d adjunto(s) no se pudieron guardar (%s)', $failed->count(), $topReason ?: 'motivo desconocido'),
            'severity' => $isDefensive ? Incident::SEVERITY_LOW : Incident::SEVERITY_HIGH,
            'affected_messages' => $failed->pluck('message_id')->unique()->count(),
            'evidence' => [
                'failed_count' => $failed->count(),
                'reason' => $topReason,
                'defensive' => $isDefensive,
                'note' => $isDefensive
                    ? 'El sistema rechazó archivos por política. Es el comportamiento correcto, no una avería.'
                    : 'Fallo al descargar de Meta: revisar credenciales, red o caducidad de las URLs.',
                'attachment_ids' => $failed->take(10)->pluck('id')->all(),
                'safe_remediation' => $isDefensive ? null : 'retry_media_download',
            ],
        ]);
    }

    /** La cola de Laravel: jobs que se rindieron. */
    private function failedJobs(): ?Incident
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $rows = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->get(['id', 'queue', 'exception']);

        if ($rows->isEmpty()) {
            return null;
        }

        // Se agrupa por la PRIMERA línea de la excepción: es lo que identifica
        // la avería. El stack trace completo cambia entre ejecuciones.
        $firstLine = trim(strtok((string) $rows->first()->exception, "\n") ?: 'unknown');

        return $this->recorder->record([
            'source' => 'queue',
            'kind' => 'failed_jobs',
            'fingerprint_keys' => [mb_substr($firstLine, 0, 120)],
            'title' => sprintf('%d job(s) fallaron en las últimas 24 h', $rows->count()),
            'severity' => $rows->count() >= 20 ? Incident::SEVERITY_HIGH : Incident::SEVERITY_MEDIUM,
            'evidence' => [
                'failed_count' => $rows->count(),
                'queues' => $rows->pluck('queue')->unique()->values()->all(),
                'exception' => mb_substr($firstLine, 0, 400),
                'check_command' => 'php artisan queue:failed',
                'safe_remediation' => 'retry_failed_job',
            ],
        ]);
    }

    /** Hermes lleva un rato sin responder y el circuito está abierto. */
    private function hermesCircuitOpen(): ?Incident
    {
        $state = app(HermesCircuitBreaker::class)->state();

        if (($state['state'] ?? 'closed') !== 'open') {
            return null;
        }

        return $this->recorder->record([
            'source' => 'hermes',
            'kind' => 'circuit_open',
            'fingerprint_keys' => ['open'],
            'title' => 'El cortacircuitos de Hermes está abierto',
            // No es grave: el canal sigue funcionando con OpenAI. Es informativo.
            'severity' => Incident::SEVERITY_LOW,
            'evidence' => [
                'reopens_in_seconds' => $state['reopens_in_seconds'] ?? null,
                'note' => 'El canal sigue atendiendo: las decisiones se están tomando con OpenAI directo.',
                'check_command' => 'cd /opt/hermes && docker compose ps',
            ],
        ]);
    }

    /**
     * Conversaciones esperando a una persona desde hace demasiado. No es un
     * fallo técnico, pero es exactamente lo que el negocio necesita ver: gente
     * a la que la IA escaló y que sigue sin respuesta.
     */
    private function unattendedEscalations(): ?Incident
    {
        if (! Schema::hasTable('marketing_conversations')) {
            return null;
        }

        $hours = 4;

        $rows = DB::table('marketing_conversations')
            ->where('staff_review_pending', true)
            ->where('status', '!=', 'closed')
            ->where('last_inbound_at', '<=', now()->subHours($hours))
            ->get(['id', 'last_inbound_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        return $this->recorder->record([
            'source' => 'inbox',
            'kind' => 'unattended_escalations',
            'fingerprint_keys' => ['pending'],
            'title' => sprintf('%d conversación(es) llevan más de %d h esperando a una persona', $rows->count(), $hours),
            'severity' => $rows->count() >= 5 ? Incident::SEVERITY_HIGH : Incident::SEVERITY_MEDIUM,
            'affected_conversations' => $rows->count(),
            'evidence' => [
                'pending_count' => $rows->count(),
                'conversation_ids' => $rows->take(10)->pluck('id')->all(),
                'note' => 'No es una avería técnica: son prospectos reales esperando atención humana.',
            ],
        ]);
    }

    /** Traducción de los códigos de Meta que de verdad aparecen en un gimnasio. */
    private function hintForMetaCode(mixed $code): ?string
    {
        return match ((int) $code) {
            131047 => 'Pasaron más de 24 h desde el último mensaje del cliente. Para retomar hace falta una plantilla aprobada.',
            131026 => 'El número no tiene WhatsApp o no puede recibir mensajes.',
            131031 => 'La cuenta de WhatsApp está restringida por Meta. Requiere revisión en el Business Manager.',
            130429, 131048 => 'Se superó el límite de envíos. Hay que espaciar los mensajes.',
            132000, 132001, 132005, 132007, 132012 => 'La plantilla no coincide con lo aprobado o no existe en ese idioma.',
            131000, 131016 => 'Avería temporal de Meta. El outbox lo reintenta solo.',
            default => null,
        };
    }
}
