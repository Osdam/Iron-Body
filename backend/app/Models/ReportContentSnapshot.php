<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidencia congelada de un reporte.
 *
 * Nunca expone una URL pública permanente: `media_storage_path` es la
 * referencia al objeto y el CRM pide una URL firmada temporal cuando el
 * moderador abre el caso.
 *
 * @property int $report_id
 * @property int $original_story_id
 * @property string|null $media_storage_path
 */
class ReportContentSnapshot extends Model
{
    protected $fillable = [
        'report_id',
        'original_story_id',
        'author_type',
        'author_member_id',
        'media_type',
        'media_storage_path',
        'media_disk',
        'media_url_snapshot',
        'caption_snapshot',
        'published_at',
        'expires_at',
        'checksum',
        'metadata',
        'captured_at',
        'purge_after',
        'media_purged_at',
    ];

    protected $casts = [
        'original_story_id' => 'integer',
        'author_member_id' => 'integer',
        'metadata' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'captured_at' => 'datetime',
        'purge_after' => 'datetime',
        'media_purged_at' => 'datetime',
    ];

    /**
     * Estos campos NUNCA deben viajar en una respuesta JSON tal cual: la ruta
     * de almacenamiento es interna y la URL legacy podría ser pública.
     */
    protected $hidden = [
        'media_storage_path',
        'media_url_snapshot',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ContentReport::class, 'report_id');
    }

    /** ¿El binario ya se purgó por retención? */
    public function isMediaPurged(): bool
    {
        return $this->media_purged_at !== null;
    }

    /** ¿Sigue habiendo un binario que un moderador pueda revisar? */
    public function hasReviewableMedia(): bool
    {
        return ! $this->isMediaPurged()
            && ($this->media_storage_path !== null || $this->media_url_snapshot !== null);
    }
}
