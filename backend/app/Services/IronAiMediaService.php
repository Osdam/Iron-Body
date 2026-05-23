<?php

namespace App\Services;

use App\Models\IronAiConversation;
use App\Models\IronAiMessage;
use App\Models\IronAiMessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * IRON IA — almacenamiento y manejo de adjuntos multimedia (audio/imagen).
 *
 * - Imágenes → disco público (preview URL). Audios → disco privado (solo se
 *   expone la transcripción/duración, nunca la ruta).
 * - Crea las filas en `iron_ai_message_attachments` con ownership flexible.
 * - Para visión, genera el data URL base64 (no se expone la ruta a OpenAI).
 *
 * La validación de tamaño/formato se hace en el controlador con las reglas de
 * Laravel (mimes/max), usando los límites de la capacidad del plan.
 */
class IronAiMediaService
{
    /**
     * Guarda un archivo subido y crea su adjunto (sin message_id aún).
     *
     * @param  array  $ctx   contexto del propietario (user/member/document)
     * @param  array  $extra metadata adicional (duration_seconds, etc.)
     */
    public function store(
        UploadedFile $file,
        string $type,
        array $ctx,
        IronAiConversation $conversation,
        array $extra = [],
    ): IronAiMessageAttachment {
        $disk = $type === IronAiMessageAttachment::TYPE_IMAGE
            ? config('iron_ai.media.image_disk', 'public')
            : config('iron_ai.media.audio_disk', 'local');

        $dir = "iron-ai/{$type}s/" . ($conversation->uuid ?: 'misc');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = (string) Str::uuid() . '.' . $ext;

        $storedPath = $file->storeAs($dir, $filename, ['disk' => $disk]);

        return IronAiMessageAttachment::create([
            'message_id'              => null,
            'iron_ai_conversation_id' => $conversation->id,
            'conversation_uuid'       => $conversation->uuid,
            'user_id'                 => $ctx['user']?->id,
            'member_id'               => $ctx['member']?->id,
            'document'                => $ctx['identity_key'] ?? $ctx['document'] ?? null,
            'type'                    => $type,
            'original_name'           => $file->getClientOriginalName(),
            'stored_path'             => $storedPath,
            'disk'                    => $disk,
            'mime_type'               => $file->getClientMimeType() ?: $file->getMimeType(),
            'size_bytes'              => $file->getSize(),
            'duration_seconds'        => isset($extra['duration_seconds']) ? (int) $extra['duration_seconds'] : null,
            'transcript'              => null,
            'metadata'                => $extra['metadata'] ?? null,
        ]);
    }

    /** Asocia un adjunto al mensaje ya persistido. */
    public function attachToMessage(IronAiMessageAttachment $attachment, IronAiMessage $message): void
    {
        $attachment->update(['message_id' => $message->id]);
    }

    /** Guarda la transcripción en el adjunto de audio. */
    public function setTranscript(IronAiMessageAttachment $attachment, string $transcript): void
    {
        $attachment->update(['transcript' => $transcript]);
    }

    /** Ruta absoluta del adjunto (para transcripción local). null si no aplica. */
    public function absolutePath(IronAiMessageAttachment $attachment): ?string
    {
        if (! $attachment->stored_path) {
            return null;
        }
        try {
            return Storage::disk($attachment->disk ?: 'local')->path($attachment->stored_path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Data URL base64 de una imagen para enviarla inline a OpenAI (visión).
     * Evita exponer la ruta pública/privada a OpenAI.
     */
    public function imageDataUrl(IronAiMessageAttachment $attachment): ?string
    {
        if ($attachment->type !== IronAiMessageAttachment::TYPE_IMAGE || ! $attachment->stored_path) {
            return null;
        }
        try {
            $disk = Storage::disk($attachment->disk ?: 'public');
            if (! $disk->exists($attachment->stored_path)) {
                return null;
            }
            $bytes = $disk->get($attachment->stored_path);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $mime = $attachment->mime_type ?: 'image/jpeg';

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (Throwable) {
            return null;
        }
    }

    /** Borra el archivo físico de un adjunto (best-effort). */
    public function deleteFile(IronAiMessageAttachment $attachment): void
    {
        if (! $attachment->stored_path) {
            return;
        }
        try {
            Storage::disk($attachment->disk ?: 'local')->delete($attachment->stored_path);
        } catch (Throwable) {
            // best-effort
        }
    }
}
