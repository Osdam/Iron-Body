<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingMessageAttachment;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use App\Services\Observability\ChannelLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega de los archivos que llegaron por WhatsApp.
 *
 * El disco es privado y no tiene URL pública: la única forma de ver una foto o
 * escuchar una nota de voz es pasar por aquí. Son dos pasos a propósito:
 *
 *  1. `link` — con sesión de administrador y permiso de Inbox, devuelve una URL
 *     FIRMADA y de vida corta (10 minutos por defecto).
 *  2. `download` — sirve el binario si la firma es válida y no ha caducado.
 *
 * El segundo paso no exige sesión porque la firma ya es la autorización: así el
 * `<img src>` y el `<audio src>` del navegador funcionan sin inventar cabeceras.
 * A cambio, la URL caduca sola: si alguien la copia y la pega fuera del CRM,
 * dentro de un rato no sirve para nada.
 */
class MarketingAttachmentController extends Controller
{
    public function __construct(private readonly MarketingInboxAuthorizationService $authz) {}

    /**
     * Paso 1: URL firmada para un adjunto concreto.
     * GET /api/admin/marketing/inbox/attachments/{id}/link
     */
    public function link(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);

        if ($denial = $this->authz->deny($admin, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return response()->json(
                ['ok' => false, 'code' => $denial['code'], 'message' => $denial['message']],
                $denial['status'],
            );
        }

        $attachment = MarketingMessageAttachment::find($id);

        if ($attachment === null) {
            return response()->json(['ok' => false, 'code' => 'attachment_not_found'], 404);
        }

        // Un borrador (subido, aún sin mensaje) solo lo ve quien lo subió. Sin
        // esto, probar ids consecutivos enseñaría lo que otro asesor tiene a
        // medio escribir en otra conversación.
        if ($attachment->message_id === null && $attachment->uploaded_by_admin_id !== $admin?->id) {
            return response()->json(['ok' => false, 'code' => 'attachment_not_found'], 404);
        }

        if (! $attachment->isServable()) {
            // No es un 404: el adjunto EXISTE y el inbox necesita poder decir
            // por qué no hay archivo (aún descargando, rechazado, caducado).
            return response()->json([
                'ok' => false,
                'code' => 'attachment_not_available',
                'status' => $attachment->status,
                'reason' => $attachment->failure_reason,
            ], 409);
        }

        $minutes = max(1, (int) config('marketing.media.signed_url_minutes', 10));

        ChannelLog::info('media.link.issued', [
            'attachment_id' => $attachment->id,
            'admin_id' => $admin?->id,
            'expires_in_minutes' => $minutes,
        ]);

        return response()->json([
            'ok' => true,
            'url' => URL::temporarySignedRoute(
                'marketing.attachment.download',
                now()->addMinutes($minutes),
                ['id' => $attachment->id],
            ),
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
            'kind' => $attachment->kind,
            'mime_type' => $attachment->detected_mime_type,
            'size_bytes' => $attachment->size_bytes,
            'filename' => $attachment->original_filename,
            'duration_seconds' => $attachment->duration_seconds,
            'width' => $attachment->width,
            'height' => $attachment->height,
        ]);
    }

    /**
     * Paso 2: el binario. La firma temporal ES la autorización (el middleware
     * `signed` la valida antes de llegar aquí).
     * GET /api/marketing/attachments/{id}/download
     */
    public function download(int $id): StreamedResponse|JsonResponse
    {
        $attachment = MarketingMessageAttachment::find($id);

        if ($attachment === null || ! $attachment->isServable()) {
            return response()->json(['ok' => false, 'code' => 'attachment_not_available'], 404);
        }

        $disk = Storage::disk((string) $attachment->disk);

        if (! $disk->exists((string) $attachment->path)) {
            // La ficha dice que está y el archivo no: retención mal ejecutada o
            // borrado manual. Se registra porque IRON GUARD debe verlo.
            ChannelLog::error('media.missing_on_disk', [
                'attachment_id' => $attachment->id,
                'disk' => $attachment->disk,
            ]);

            return response()->json(['ok' => false, 'code' => 'attachment_missing'], 404);
        }

        // El MIME que se declara al navegador es el DETECTADO, nunca el que
        // mandó el cliente: es lo que decide si el navegador ejecuta o descarga.
        $mime = (string) ($attachment->detected_mime_type ?: 'application/octet-stream');

        // Imágenes, audio y video se muestran dentro del inbox; todo lo demás
        // se descarga. Un PDF o un .docx abierto en línea es superficie que no
        // hace falta abrir.
        $inline = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/');

        $filename = $attachment->original_filename ?: basename((string) $attachment->path);

        ChannelLog::info('media.served', [
            'attachment_id' => $attachment->id,
            'kind' => $attachment->kind,
            'inline' => $inline,
        ]);

        return $disk->response((string) $attachment->path, $filename, [
            'Content-Type' => $mime,
            // Sin sniffing: si decimos que es una imagen, el navegador no puede
            // decidir por su cuenta que en realidad es HTML y ejecutarlo.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=300, no-transform',
            'Referrer-Policy' => 'no-referrer',
        ], $inline ? 'inline' : 'attachment');
    }

    /** El admin que dejó el blindaje de /api/admin/* en el request. */
    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }
}
