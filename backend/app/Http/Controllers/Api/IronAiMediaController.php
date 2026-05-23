<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IronAiMessageAttachment;
use App\Models\IronAiUsageLog;
use App\Services\IronAiMediaService;
use App\Services\IronAiMembershipAccessService;
use App\Services\IronAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * IRON IA multimodal — chat por voz (audio) y análisis de imágenes.
 *
 * Arquitectura: Flutter → Laravel → OpenAI. Flutter sube el archivo a estos
 * endpoints; la transcripción/visión las hace el backend con la key de OpenAI
 * (que NUNCA sale del servidor).
 *
 * Gating: la voz y la imagen dependen del plan/membresía
 * (IronAiMembershipAccessService). Si la función está bloqueada o se agotó la
 * cuota, NO se llama a OpenAI (control de costos) y se devuelve un CTA premium.
 * Solo el procesamiento real (transcribir/analizar) consume cuota.
 */
class IronAiMediaController extends Controller
{
    public function __construct(
        private readonly IronAiService $service,
        private readonly IronAiMembershipAccessService $access,
        private readonly IronAiMediaService $media,
    ) {
    }

    /** POST /api/iron-ai/audio-chat (multipart: document?, conversation_uuid?, audio, duration?) */
    public function audioChat(Request $request): JsonResponse
    {
        if (! $request->hasFile('audio')) {
            return $this->invalid('Adjunta un audio para enviar a IRON IA.');
        }

        try {
            $access = $this->access->resolveAccess($request);

            // 1) Gating de voz (capacidad + cuota general + cuota de audio).
            $decision = $this->access->decideAudio($access);
            if (! $decision['can']) {
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_BLOCKED, [
                    'kind'         => IronAiUsageLog::KIND_AUDIO,
                    'block_reason' => $decision['block']['code'] ?? 'BLOCKED',
                ]);

                return $this->blockResponse($access, $decision['block']);
            }

            // 2) Validación de formato/tamaño/duración.
            $file = $request->file('audio');
            $caps = $access['capabilities'];
            $maxSeconds = (int) ($caps['ai_max_audio_seconds'] ?? config('iron_ai.media.max_audio_seconds', 60));
            if ($err = $this->validateAudio($file, $request, $maxSeconds)) {
                return $this->invalid($err);
            }

            // 3) Conversación (sin uuid → nueva; con uuid → debe ser del usuario).
            $uuid = $request->input('conversation_uuid');
            $conversation = $uuid
                ? $this->service->findOwnedConversation($access, $uuid)
                : $this->service->createConversation($access, null, 'general');
            if ($uuid && ! $conversation) {
                return $this->forbiddenConversation();
            }

            // 4) Guarda el audio (privado) y procesa (transcribe + chat).
            $attachment = $this->media->store($file, IronAiMessageAttachment::TYPE_AUDIO, $access, $conversation, [
                'duration_seconds' => $request->input('duration'),
            ]);

            $result = $this->service->audioChat($conversation, $access['member'], $access['user'], $attachment, $caps);

            // 5) Si no se pudo transcribir → no consume cuota; mensaje amable.
            if (! empty($result['transcription_failed'])) {
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_ERROR, [
                    'kind'         => IronAiUsageLog::KIND_AUDIO,
                    'block_reason' => 'TRANSCRIPTION_FAILED',
                ]);
                $this->media->deleteFile($attachment);

                return response()->json([
                    'ok'                => false,
                    'code'              => 'TRANSCRIPTION_FAILED',
                    'reply'             => $result['reply'],
                    'transcript'        => null,
                    'conversation_uuid' => $conversation->uuid,
                    'conversation_id'   => $conversation->uuid,
                    'quota'             => $this->access->quotaSnapshot($access),
                ], 200);
            }

            // 6) Consumo OK (audio).
            $this->access->registerUsage(
                $access,
                $result['is_fallback'] ? IronAiUsageLog::STATUS_FALLBACK : IronAiUsageLog::STATUS_SUCCESS,
                [
                    'kind'          => IronAiUsageLog::KIND_AUDIO,
                    'model'         => $result['model'] ?? null,
                    'input_tokens'  => $result['input_tokens'] ?? null,
                    'output_tokens' => $result['output_tokens'] ?? null,
                    'message_id'    => $result['message_id'] ?? null,
                ],
            );

            return response()->json([
                'ok'                => true,
                'transcript'        => $result['transcript'] ?? null,
                'reply'             => $result['reply'],
                'duration_seconds'  => $result['duration_seconds'] ?? null,
                'conversation_uuid' => $conversation->uuid,
                'conversation_id'   => $conversation->uuid,
                'quota'             => $this->access->quotaSnapshot($access),
                'suggestions'       => $result['suggestions'] ?? [],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'      => false,
                'code'    => 'AUDIO_ERROR',
                'reply'   => IronAiService::AUDIO_ERROR,
                'message' => IronAiService::AUDIO_ERROR,
            ], 200);
        }
    }

    /** POST /api/iron-ai/image-chat (multipart: document?, conversation_uuid?, image, message?) */
    public function imageChat(Request $request): JsonResponse
    {
        if (! $request->hasFile('image')) {
            return $this->invalid('Adjunta una imagen para que IRON IA la analice.');
        }

        try {
            $access = $this->access->resolveAccess($request);

            // 1) Gating de imagen (capacidad + cuota general + cuota de imagen).
            $decision = $this->access->decideImage($access);
            if (! $decision['can']) {
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_BLOCKED, [
                    'kind'         => IronAiUsageLog::KIND_IMAGE,
                    'block_reason' => $decision['block']['code'] ?? 'BLOCKED',
                ]);

                return $this->blockResponse($access, $decision['block']);
            }

            // 2) Validación de formato/tamaño.
            $file = $request->file('image');
            $caps = $access['capabilities'];
            $maxMb = (int) ($caps['ai_max_image_size_mb'] ?? config('iron_ai.media.max_image_size_mb', 5));
            if ($err = $this->validateImage($file, $maxMb)) {
                return $this->invalid($err);
            }

            $message = $request->input('message');

            // 3) Conversación.
            $uuid = $request->input('conversation_uuid');
            $conversation = $uuid
                ? $this->service->findOwnedConversation($access, $uuid)
                : $this->service->createConversation($access, null, 'general');
            if ($uuid && ! $conversation) {
                return $this->forbiddenConversation();
            }

            // 4) Guarda la imagen (pública → preview URL) y analiza (visión).
            $attachment = $this->media->store($file, IronAiMessageAttachment::TYPE_IMAGE, $access, $conversation);
            $result = $this->service->imageChat($conversation, $access['member'], $access['user'], $attachment, $message, $caps);

            // 5) Consumo OK (imagen).
            $this->access->registerUsage(
                $access,
                $result['is_fallback'] ? IronAiUsageLog::STATUS_FALLBACK : IronAiUsageLog::STATUS_SUCCESS,
                [
                    'kind'          => IronAiUsageLog::KIND_IMAGE,
                    'model'         => $result['model'] ?? null,
                    'input_tokens'  => $result['input_tokens'] ?? null,
                    'output_tokens' => $result['output_tokens'] ?? null,
                    'message_id'    => $result['message_id'] ?? null,
                ],
            );

            return response()->json([
                'ok'                => true,
                'reply'             => $result['reply'],
                'conversation_uuid' => $conversation->uuid,
                'conversation_id'   => $conversation->uuid,
                'image_preview_url' => $attachment->previewUrl(),
                'quota'             => $this->access->quotaSnapshot($access),
                'suggestions'       => $result['suggestions'] ?? [],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'      => false,
                'code'    => 'IMAGE_ERROR',
                'reply'   => IronAiService::IMAGE_ERROR,
                'message' => IronAiService::IMAGE_ERROR,
            ], 200);
        }
    }

    // ── Validaciones (robustas para uploads móviles con mime variable) ──────────

    private function validateAudio(UploadedFile $file, Request $request, int $maxSeconds): ?string
    {
        if (! $file->isValid()) {
            return 'El audio no se subió correctamente. Intenta nuevamente.';
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $allowed = (array) config('iron_ai.media.audio_exts', ['m4a', 'mp3', 'wav', 'webm', 'aac', 'ogg', 'mp4']);
        if ($ext !== '' && ! in_array($ext, $allowed, true)) {
            return 'Formato de audio no soportado. Usa m4a, mp3, wav, aac u ogg.';
        }
        $maxBytes = (int) config('iron_ai.media.max_audio_size_mb', 25) * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return 'El audio es demasiado grande. Graba un mensaje más corto.';
        }
        $duration = $request->input('duration');
        if ($duration !== null && (int) $duration > $maxSeconds) {
            return "El audio supera el máximo de {$maxSeconds} segundos. Graba un mensaje más corto.";
        }

        return null;
    }

    private function validateImage(UploadedFile $file, int $maxMb): ?string
    {
        if (! $file->isValid()) {
            return 'La imagen no se subió correctamente. Intenta nuevamente.';
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $allowed = (array) config('iron_ai.media.image_exts', ['jpg', 'jpeg', 'png', 'webp']);
        if ($ext !== '' && ! in_array($ext, $allowed, true)) {
            return 'Formato de imagen no soportado. Usa JPG, PNG o WEBP.';
        }
        $mime = (string) ($file->getClientMimeType() ?: $file->getMimeType());
        if ($mime !== '' && ! str_starts_with($mime, 'image/')) {
            return 'El archivo no parece una imagen válida.';
        }
        $maxBytes = $maxMb * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return "La imagen supera el máximo de {$maxMb} MB. Usa una imagen más liviana.";
        }

        return null;
    }

    // ── Respuestas comunes ──────────────────────────────────────────────────────

    private function blockResponse(array $access, array $block): JsonResponse
    {
        return response()->json([
            'ok'               => false,
            'code'             => $block['code'],
            'reply'            => $block['reply'],
            'upgrade_required' => $block['upgrade_required'] ?? true,
            'access_type'      => $access['access_type'] ?? null,
            'quota'            => $this->access->quotaSnapshot($access),
            'cta'              => $block['cta'] ?? null,
            'suggestions'      => $block['suggestions'] ?? ['Ver membresías'],
        ], 200);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'INVALID_FILE', 'message' => $message, 'reply' => $message], 422);
    }

    private function forbiddenConversation(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => 'CONVERSATION_NOT_FOUND',
            'message' => 'La conversación no existe o no te pertenece.',
        ], 403);
    }
}
