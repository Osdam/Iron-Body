<?php

namespace App\Services\IronGuard;

use App\Models\Incident;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Models\MetaWebhookEvent;
use App\Services\Marketing\HermesCircuitBreaker;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las cifras del canal en las últimas 24 horas.
 *
 * Se calculan desde las tablas propias, no desde logs: son exactas y baratas.
 * Cada bloque responde a una pregunta que alguien se hace de verdad cuando algo
 * va mal, no a "qué métrica podríamos sacar".
 */
class ChannelMetricsService
{
    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'window_hours' => 24,
            'ingest' => $this->ingest(),
            'outbound' => $this->outbound(),
            'media' => $this->media(),
            'queue' => $this->queue(),
            'incidents' => $this->incidents(),
            'brain' => $this->brain(),
        ];
    }

    /** ¿Está entrando lo que Meta nos manda, y se está procesando? */
    private function ingest(): array
    {
        $since = now()->subDay();

        $byStatus = MetaWebhookEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $oldestPending = MetaWebhookEvent::query()
            ->whereIn('status', [MetaWebhookEvent::STATUS_PENDING, MetaWebhookEvent::STATUS_PROCESSING])
            ->min('created_at');

        return [
            'events_received' => array_sum($byStatus),
            'by_status' => $byStatus,
            'inbound_messages' => MarketingMessage::where('direction', 'inbound')->where('created_at', '>=', $since)->count(),
            // La cifra que de verdad importa: si esto sube, hay gente esperando.
            'oldest_unprocessed_minutes' => $oldestPending !== null
                ? (int) now()->diffInMinutes($oldestPending)
                : null,
        ];
    }

    /** ¿Está saliendo lo que respondemos, y llegando? */
    private function outbound(): array
    {
        $since = now()->subDay();

        $byStatus = MarketingMessage::query()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $bySender = MarketingMessage::query()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->selectRaw('sender_type, count(*) as total')
            ->groupBy('sender_type')
            ->pluck('total', 'sender_type')
            ->all();

        $errorCodes = Schema::hasTable('marketing_message_statuses')
            ? DB::table('marketing_message_statuses')
                ->whereNotNull('error_code')
                ->where('created_at', '>=', $since)
                ->selectRaw('error_code, count(*) as total')
                ->groupBy('error_code')
                ->orderByDesc('total')
                ->limit(5)
                ->pluck('total', 'error_code')
                ->all()
            : [];

        return [
            'sent' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_sender' => $bySender,
            'awaiting_retry' => MarketingMessage::where('status', WhatsappOutboxService::STATUS_FAILED)
                ->whereNotNull('next_attempt_at')->count(),
            // Cada uno es una persona que preguntó y se quedó sin respuesta.
            'never_delivered' => MarketingMessage::where('status', WhatsappOutboxService::STATUS_DEAD)->count(),
            'top_meta_error_codes' => $errorCodes,
        ];
    }

    private function media(): array
    {
        $byStatus = MarketingMessageAttachment::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'received' => array_sum($byStatus),
            'by_status' => $byStatus,
            'stored_bytes' => (int) MarketingMessageAttachment::where('status', MarketingMessageAttachment::STATUS_STORED)
                ->sum('size_bytes'),
        ];
    }

    private function queue(): array
    {
        return [
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed_jobs_24h' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
                : null,
        ];
    }

    private function incidents(): array
    {
        $open = Incident::whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_ACKNOWLEDGED]);

        return [
            'open' => (clone $open)->count(),
            'by_severity' => (clone $open)
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity')
                ->all(),
            'resolved_24h' => Incident::where('status', Incident::STATUS_RESOLVED)
                ->where('resolved_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /** Qué cerebro está decidiendo de verdad, y si Hermes está disponible. */
    private function brain(): array
    {
        return [
            'effective_driver' => \App\Services\Marketing\SalesAiConfig::effectiveDriver(),
            'hermes_enabled' => (bool) config('marketing.ai.hermes.enabled', false),
            'hermes_circuit' => app(HermesCircuitBreaker::class)->state(),
            'agent_enabled' => (bool) config('marketing.agent_enabled', false),
            'auto_execute' => (bool) config('marketing.inbound.auto_execute', false),
            'meta_enabled' => (bool) config('meta.enabled', false),
        ];
    }
}
