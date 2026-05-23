<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * IRON IA multimodal — adjunto de un mensaje (audio | imagen | archivo).
 *
 * Las imágenes viven en disco público (preview URL); los audios en disco
 * privado (no se expone la ruta, solo la transcripción/duración). toPublicArray()
 * devuelve únicamente lo seguro para Flutter.
 */
class IronAiMessageAttachment extends Model
{
    public const TYPE_AUDIO = 'audio';
    public const TYPE_IMAGE = 'image';
    public const TYPE_FILE = 'file';

    protected $fillable = [
        'message_id',
        'iron_ai_conversation_id',
        'conversation_uuid',
        'user_id',
        'member_id',
        'document',
        'type',
        'original_name',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'transcript',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes'       => 'integer',
            'duration_seconds' => 'integer',
            'metadata'         => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(IronAiMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(IronAiConversation::class, 'iron_ai_conversation_id');
    }

    /**
     * URL de preview solo para adjuntos en disco público (imágenes). Para
     * audio/privado devuelve null: nunca se expone la ruta privada.
     */
    public function previewUrl(): ?string
    {
        if ($this->type !== self::TYPE_IMAGE || ! $this->stored_path) {
            return null;
        }
        $disk = $this->disk ?: config('iron_ai.media.image_disk', 'public');
        if ($disk !== 'public') {
            return null;
        }
        try {
            return Storage::disk($disk)->url($this->stored_path);
        } catch (Throwable) {
            return null;
        }
    }

    /** Representación segura para Flutter (sin rutas privadas). */
    public function toPublicArray(): array
    {
        return [
            'type'             => $this->type,
            'original_name'    => $this->original_name,
            'mime_type'        => $this->mime_type,
            'size_bytes'       => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'transcript'       => $this->transcript,
            'preview_url'      => $this->previewUrl(),
        ];
    }
}
