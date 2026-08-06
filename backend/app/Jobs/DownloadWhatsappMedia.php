<?php

namespace App\Jobs;

use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaMediaService;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Descarga un adjunto de WhatsApp fuera del camino del webhook.
 *
 * Va en su propio job porque la URL de Meta caduca en minutos y porque un
 * archivo de 20 MB no puede retrasar la respuesta al prospecto. El mensaje ya
 * está en el inbox antes de que esto corra: si la descarga falla, quien atiende
 * ve que llegó un audio y por qué no se pudo traer, en vez de no ver nada.
 */
class DownloadWhatsappMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** La URL de Meta caduca pronto: reintentar tarde no sirve de nada. */
    public array $backoff = [10, 30];

    public function __construct(
        public int $attachmentId,
        public ?string $correlationId = null,
    ) {}

    /** Un adjunto no se descarga dos veces en paralelo. */
    public function uniqueId(): string
    {
        return 'whatsapp-media:'.$this->attachmentId;
    }

    public function handle(MetaMediaService $media): void
    {
        if ($this->correlationId !== null) {
            Context::add('correlation_id', $this->correlationId);
        }

        $attachment = MarketingMessageAttachment::find($this->attachmentId);

        if ($attachment === null) {
            ChannelLog::warning('media.attachment.missing', ['attachment_id' => $this->attachmentId]);

            return;
        }

        // Rechazado por política (MIME falso, tamaño): NO se reintenta nunca.
        // Reintentar un archivo prohibido solo repetiría el rechazo.
        if ($attachment->status === MarketingMessageAttachment::STATUS_REJECTED) {
            ChannelLog::info('media.download.skipped', [
                'attachment_id' => $attachment->id,
                'reason' => 'already_rejected',
            ]);

            return;
        }

        $media->download($attachment);
    }

    /**
     * Agotados los reintentos, el adjunto queda marcado y visible en el inbox.
     * Nada se pierde en silencio.
     */
    public function failed(?\Throwable $e): void
    {
        $attachment = MarketingMessageAttachment::find($this->attachmentId);

        $attachment?->forceFill([
            'status' => MarketingMessageAttachment::STATUS_FAILED,
            'failure_reason' => 'job_failed:'.($e !== null ? class_basename($e) : 'unknown'),
        ])->save();

        ChannelLog::error('media.download.job_failed', [
            'attachment_id' => $this->attachmentId,
            'error_class' => $e !== null ? class_basename($e) : null,
        ]);
    }
}
