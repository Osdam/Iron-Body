<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha de un archivo que entró o salió por WhatsApp. El binario vive en el
 * disco privado; aquí está todo lo necesario para encontrarlo, validarlo,
 * servirlo con una URL firmada y borrarlo cuando cumpla su retención.
 */
class MarketingMessageAttachment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_STORED = 'stored';

    /** Falló por algo transitorio (red, 5xx de Meta): se puede reintentar. */
    public const STATUS_FAILED = 'failed';

    /** Rechazado por política (MIME falso, demasiado grande): NO se reintenta. */
    public const STATUS_REJECTED = 'rejected';

    /** Cumplió su retención: el binario ya no existe, la ficha sí. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'message_id', 'direction', 'uploaded_by_admin_id', 'kind', 'media_id',
        'declared_mime_type', 'detected_mime_type', 'meta_sha256', 'sha256',
        'size_bytes', 'original_filename', 'disk', 'path', 'thumbnail_path',
        'width', 'height', 'duration_seconds', 'voice',
        'status', 'failure_reason', 'attempts', 'downloaded_at', 'expires_at',
        'transcript', 'transcript_status', 'transcript_confidence', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'voice' => 'boolean',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_seconds' => 'integer',
        'attempts' => 'integer',
        'downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
        'transcript_confidence' => 'float',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MarketingMessage::class, 'message_id');
    }

    /** ¿Hay un binario servible detrás de esta ficha ahora mismo? */
    public function isServable(): bool
    {
        return $this->status === self::STATUS_STORED
            && ! empty($this->path)
            && ! empty($this->disk);
    }

    /** Rechazo definitivo: no se vuelve a intentar y queda dicho por qué. */
    public function reject(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'failure_reason' => $reason,
        ])->save();
    }
}
