<?php

namespace App\Services\Meta;

use App\Models\MarketingMessageAttachment;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Descarga y custodia de los archivos que llegan por WhatsApp.
 *
 * Todo lo que entra por aquí es CONTENIDO NO CONFIABLE. Un archivo puede venir
 * con un MIME mentido, pesar mucho más de lo declarado, llamarse
 * `../../.env` o ser un HTML que se ejecutaría en nuestro dominio si alguna vez
 * se sirviera directo. Este servicio asume lo peor en cada paso:
 *
 *  1. Meta da una URL temporal (minutos) → se pide justo antes de descargar.
 *  2. Se descarga con el token, comprobando el tamaño ANTES de escribir.
 *  3. Se detecta el MIME real por los bytes, no por la extensión ni por Meta.
 *  4. Si el tipo real no está en la allowlist, se rechaza y no se guarda.
 *  5. Se calcula SHA-256 propio: deduplica y prueba integridad.
 *  6. Se guarda con nombre ALEATORIO. El nombre original solo se muestra.
 *
 * El binario nunca toca PostgreSQL y nunca queda en un directorio público.
 */
class MetaMediaService
{
    public function __construct(private readonly MetaAuthService $auth) {}

    /**
     * Descarga un adjunto pendiente y lo deja almacenado o marcado.
     *
     * No lanza por errores de política (devuelve false y deja el motivo); sí
     * deja escapar los transitorios para que el job los reintente.
     */
    public function download(MarketingMessageAttachment $attachment): bool
    {
        if (! (bool) config('marketing.media.download_enabled', true)) {
            $attachment->forceFill(['failure_reason' => 'download_disabled'])->save();

            return false;
        }

        if ($attachment->status === MarketingMessageAttachment::STATUS_STORED) {
            return true; // idempotente: ya está.
        }

        if (empty($attachment->media_id)) {
            $attachment->reject('missing_media_id');

            return false;
        }

        if (! $this->auth->isConfigured()) {
            // Sin credenciales de Meta no hay descarga posible. No es un fallo
            // del archivo: queda pendiente y se reintentará cuando las haya.
            $attachment->forceFill(['failure_reason' => 'meta_disabled_or_unconfigured'])->save();
            ChannelLog::info('media.download.skipped', [
                'attachment_id' => $attachment->id,
                'reason' => 'meta_disabled_or_unconfigured',
            ]);

            return false;
        }

        $attachment->forceFill([
            'status' => MarketingMessageAttachment::STATUS_DOWNLOADING,
            'attempts' => $attachment->attempts + 1,
        ])->save();

        $url = $this->resolveUrl((string) $attachment->media_id);
        if ($url === null) {
            // La URL caduca en minutos: si no la conseguimos, puede ser que el
            // archivo ya expiró en Meta. Se deja fallado para reintento acotado.
            $this->fail($attachment, 'media_url_unavailable');

            return false;
        }

        $binary = $this->fetch($url, $attachment);
        if ($binary === null) {
            return false; // fail() ya dejó el motivo.
        }

        return $this->store($attachment, $binary);
    }

    /**
     * Paso 1: Meta devuelve una URL firmada de vida corta para el media_id.
     * Nunca se guarda esa URL: caduca y, si se filtrara, daría acceso al archivo.
     */
    private function resolveUrl(string $mediaId): ?string
    {
        try {
            $resp = Http::withToken((string) $this->auth->accessToken())
                ->timeout($this->auth->timeout())
                ->get($this->auth->graphUrl($mediaId));

            if ($resp->failed()) {
                ChannelLog::warning('media.url.failed', [
                    'status' => $resp->status(),
                    'media_id' => $mediaId,
                ]);

                return null;
            }

            $url = $resp->json('url');

            // SSRF: la URL viene de Meta, pero se verifica igual. Si algún día
            // esa respuesta se pudiera influir, no vamos a hacer una petición
            // autenticada con nuestro token contra un host cualquiera.
            return $this->isTrustedMediaUrl($url) ? (string) $url : null;
        } catch (Throwable $e) {
            ChannelLog::warning('media.url.exception', [
                'error_class' => class_basename($e),
                'media_id' => $mediaId,
            ]);

            return null;
        }
    }

    /**
     * Solo HTTPS y solo hosts de Meta. Sin esto, una URL manipulada podría
     * apuntar a la red interna (169.254.169.254, 127.0.0.1, el propio Postgres)
     * y nosotros la pediríamos con el token puesto.
     */
    private function isTrustedMediaUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = mb_strtolower((string) $parts['host']);
        foreach (['.fbcdn.net', '.facebook.com', '.whatsapp.net', '.cdninstagram.com'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        ChannelLog::warning('media.url.untrusted_host', ['host' => $host]);

        return false;
    }

    /**
     * Paso 2: descarga con el token. El límite de tamaño se comprueba con la
     * cabecera Content-Length ANTES de leer el cuerpo, y otra vez sobre los
     * bytes reales: una cabecera mentida no debe poder llenarnos el disco.
     */
    private function fetch(string $url, MarketingMessageAttachment $attachment): ?string
    {
        $maxBytes = (int) config('marketing.media.max_size_bytes');

        try {
            $resp = Http::withToken((string) $this->auth->accessToken())
                ->timeout((int) config('marketing.media.timeout', 60))
                ->get($url);

            if ($resp->failed()) {
                $this->fail($attachment, 'download_http_'.$resp->status());

                return null;
            }

            $declaredLength = (int) $resp->header('Content-Length');
            if ($declaredLength > 0 && $declaredLength > $maxBytes) {
                $attachment->reject('too_large_declared');

                return null;
            }

            $body = $resp->body();

            if (strlen($body) > $maxBytes) {
                // Content-Length mentía o no venía. Se descarta sin escribir.
                $attachment->reject('too_large');

                return null;
            }

            if ($body === '') {
                $this->fail($attachment, 'empty_body');

                return null;
            }

            return $body;
        } catch (Throwable $e) {
            $this->fail($attachment, 'download_exception_'.class_basename($e));

            return null;
        }
    }

    /**
     * Pasos 3-6: verificar qué es de verdad, y solo entonces guardarlo.
     */
    private function store(MarketingMessageAttachment $attachment, string $binary): bool
    {
        $detected = $this->detectMime($binary);
        $allowed = (array) config('marketing.media.allowed_mime_types', []);

        if ($detected === null || ! in_array($detected, $allowed, true)) {
            // Aquí muere el archivo disfrazado: da igual que Meta dijera que
            // era un PDF si los bytes dicen otra cosa.
            $attachment->forceFill(['detected_mime_type' => $detected])->save();
            $attachment->reject('disallowed_mime:'.($detected ?? 'unknown'));

            ChannelLog::warning('media.rejected', [
                'attachment_id' => $attachment->id,
                'declared' => $attachment->declared_mime_type,
                'detected' => $detected,
            ]);

            return false;
        }

        // Incoherencia entre lo declarado y lo real: se registra siempre, y se
        // rechaza cuando ni siquiera coincide la familia (un "image/jpeg" que
        // resulta ser application/pdf no es un descuido de codec).
        $declared = (string) $attachment->declared_mime_type;
        if ($declared !== '' && ! $this->sameFamily($declared, $detected)) {
            $attachment->forceFill(['detected_mime_type' => $detected])->save();
            $attachment->reject('mime_mismatch');

            ChannelLog::warning('media.mime_spoof', [
                'attachment_id' => $attachment->id,
                'declared' => $declared,
                'detected' => $detected,
            ]);

            return false;
        }

        $sha = hash('sha256', $binary);
        $disk = (string) config('marketing.media.disk', 'whatsapp');

        // Deduplicación: el mismo archivo (mismo hash) ya guardado se reutiliza
        // en lugar de ocupar disco otra vez. El folleto que veinte personas
        // reenvían pesa una sola vez.
        $twin = MarketingMessageAttachment::where('sha256', $sha)
            ->where('status', MarketingMessageAttachment::STATUS_STORED)
            ->whereNotNull('path')
            ->where('id', '!=', $attachment->id)
            ->first();

        $path = $twin?->path;

        if ($path === null) {
            // Nombre ALEATORIO. El nombre original del cliente jamás forma
            // parte de la ruta: es la vía directa al path traversal.
            $path = $this->buildPath($attachment, $detected, $sha);
            Storage::disk($disk)->put($path, $binary);
        }

        $retention = (int) config('marketing.media.retention_days', 180);

        $attachment->forceFill([
            'status' => MarketingMessageAttachment::STATUS_STORED,
            'detected_mime_type' => $detected,
            'sha256' => $sha,
            'size_bytes' => strlen($binary),
            'disk' => $disk,
            'path' => $path,
            'downloaded_at' => now(),
            'expires_at' => $retention > 0 ? now()->addDays($retention) : null,
            'failure_reason' => null,
        ])->save();

        $this->enrichDimensions($attachment, $binary, $detected);

        ChannelLog::info('media.stored', [
            'attachment_id' => $attachment->id,
            'kind' => $attachment->kind,
            'detected_mime' => $detected,
            'size_bytes' => strlen($binary),
            'deduplicated' => $twin !== null,
        ]);

        return true;
    }

    /**
     * Ruta de almacenamiento: nada de lo que venga del cliente participa.
     * Se reparte por fecha para que ningún directorio acumule cien mil ficheros.
     */
    private function buildPath(MarketingMessageAttachment $attachment, string $mime, string $sha): string
    {
        $extension = $this->extensionFor($mime);

        return sprintf(
            '%s/%s/%s-%s.%s',
            $attachment->kind,
            now()->format('Y/m'),
            substr($sha, 0, 16),
            Str::random(12),
            $extension,
        );
    }

    /**
     * Tipo real a partir de los bytes. finfo lee los magic numbers del fichero;
     * la extensión y el MIME declarado no participan en esta decisión.
     */
    public function detectMime(string $binary): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_buffer($finfo, $binary);
        finfo_close($finfo);

        if ($mime === false || $mime === '') {
            return null;
        }

        // finfo es conservador con audio de WhatsApp: un .ogg de nota de voz
        // se reporta a menudo como application/octet-stream o video/ogg. Se
        // normaliza SOLO dentro de contenedores conocidos y verificados por
        // firma de cabecera, nunca por lo que dijera Meta.
        if ($mime === 'video/ogg' || $mime === 'application/ogg') {
            return str_starts_with($binary, 'OggS') ? 'audio/ogg' : $mime;
        }

        return $mime;
    }

    /**
     * ¿Pertenecen al mismo tipo general? Un audio que finfo reporta como
     * `video/mp4` (mismo contenedor ISOBMFF) es normal; un `image/*` que
     * resulta `application/x-dosexec` no lo es.
     */
    private function sameFamily(string $declared, string $detected): bool
    {
        if ($declared === $detected) {
            return true;
        }

        $declaredFamily = strtok($declared, '/');
        $detectedFamily = strtok($detected, '/');

        if ($declaredFamily === $detectedFamily) {
            return true;
        }

        // Audio y video comparten contenedores (mp4/3gp/ogg): finfo no siempre
        // los distingue y confundirlos no tiene consecuencias de seguridad.
        $av = ['audio', 'video'];

        return in_array($declaredFamily, $av, true) && in_array($detectedFamily, $av, true);
    }

    /** Dimensiones de imagen: lo justo para que el inbox reserve el espacio. */
    private function enrichDimensions(MarketingMessageAttachment $attachment, string $binary, string $mime): void
    {
        if (! str_starts_with($mime, 'image/')) {
            return;
        }

        try {
            $info = @getimagesizefromstring($binary);
            if ($info === false) {
                return;
            }
            $attachment->forceFill([
                'width' => (int) $info[0],
                'height' => (int) $info[1],
            ])->save();
        } catch (Throwable) {
            // Una miniatura no vale una excepción: el archivo ya está a salvo.
        }
    }

    /** Extensión derivada del tipo REAL, nunca del nombre que mandó el cliente. */
    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/ogg', 'audio/opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/amr' => 'amr',
            'audio/wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    /** Fallo transitorio: agotados los intentos, deja de reintentarse. */
    private function fail(MarketingMessageAttachment $attachment, string $reason): void
    {
        $max = (int) config('marketing.media.max_attempts', 3);
        $exhausted = $attachment->attempts >= $max;

        $attachment->forceFill([
            'status' => $exhausted
                ? MarketingMessageAttachment::STATUS_REJECTED
                : MarketingMessageAttachment::STATUS_FAILED,
            'failure_reason' => $reason,
        ])->save();

        ChannelLog::warning('media.download.failed', [
            'attachment_id' => $attachment->id,
            'reason' => $reason,
            'attempts' => $attachment->attempts,
            'exhausted' => $exhausted,
        ]);
    }

    /**
     * Sube a Meta un adjunto ya guardado y devuelve su `media_id`.
     *
     * Cloud API no acepta el binario dentro del mensaje: primero se sube el
     * archivo y después se manda el mensaje citando el id que devuelve. Ese id
     * se guarda en la ficha porque un reintento del envío NO debe volver a
     * subir el archivo: sería otro id, otro archivo en Meta y el riesgo de que
     * los dos acaben saliendo.
     *
     * El MIME que se declara es el DETECTADO, nunca el que trajo el navegador.
     */
    public function upload(MarketingMessageAttachment $attachment): ?string
    {
        if (! empty($attachment->media_id)) {
            return (string) $attachment->media_id; // ya subido: idempotente.
        }

        if (! $this->auth->isConfigured() || ! $attachment->isServable()) {
            return null;
        }

        $phoneNumberId = (string) config('meta.whatsapp_phone_number_id');
        if ($phoneNumberId === '') {
            return null;
        }

        try {
            $binary = Storage::disk((string) $attachment->disk)->get((string) $attachment->path);
        } catch (Throwable $e) {
            ChannelLog::error('media.upload.unreadable', [
                'attachment_id' => $attachment->id,
                'error_class' => class_basename($e),
            ]);

            return null;
        }

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $mime = (string) ($attachment->detected_mime_type ?: 'application/octet-stream');
        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) $this->auth->accessToken())
                ->timeout((int) config('marketing.media.timeout', 60))
                ->attach('file', $binary, basename((string) $attachment->path), ['Content-Type' => $mime])
                ->post($this->auth->graphUrl("{$phoneNumberId}/media"), [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime,
                ]);
        } catch (Throwable $e) {
            ChannelLog::warning('media.upload.exception', [
                'attachment_id' => $attachment->id,
                'error_class' => class_basename($e),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return null;
        }

        if ($response->failed()) {
            ChannelLog::warning('media.upload.failed', [
                'attachment_id' => $attachment->id,
                'http_status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            return null;
        }

        $mediaId = $response->json('id');
        if (! is_string($mediaId) || $mediaId === '') {
            return null;
        }

        $attachment->forceFill(['media_id' => $mediaId])->save();

        ChannelLog::info('media.upload.ok', [
            'attachment_id' => $attachment->id,
            'kind' => $attachment->kind,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);

        return $mediaId;
    }

    /**
     * Nombre original saneado: solo para MOSTRARLO y para la cabecera de
     * descarga. Se le quita cualquier rastro de ruta y se acota.
     */
    public static function sanitizeFilename(?string $name, string $fallbackExtension = 'bin'): string
    {
        $base = basename((string) $name);                          // corta ../ y rutas
        $base = preg_replace('/[^\pL\pN._\- ]+/u', '_', $base) ?? '';
        $base = preg_replace('/\.{2,}/', '.', $base) ?? '';
        // Solo por la derecha: un punto final rompe en Windows, pero uno
        // inicial es un nombre legítimo (`.env` se muestra tal cual; no puede
        // hacer daño porque este valor jamás forma parte de una ruta).
        $base = rtrim(ltrim($base, " \t\n"), ". \t\n");

        if ($base === '') {
            return 'archivo.'.$fallbackExtension;
        }

        return mb_substr($base, 0, 120);
    }
}
