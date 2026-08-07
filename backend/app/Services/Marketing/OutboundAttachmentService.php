<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaMediaService;
use App\Services\Observability\ChannelLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Archivos que salen del CRM hacia el cliente.
 *
 * Un adjunto saliente se sube ANTES de existir el mensaje: quien atiende
 * suelta la foto en el compositor, la ve, escribe el pie y puede arrepentirse.
 * Hasta que un envío lo reclama es un BORRADOR —un expediente en la base y un
 * binario en el disco privado, sin mensaje detrás—.
 *
 * Todo lo que sube pasa por los mismos controles que lo que entra, y no por
 * simetría: el archivo va a acabar en el teléfono de un cliente con el nombre
 * del gimnasio encima. Que lo mande un asesor de confianza no impide que su
 * navegador esté comprometido ni que un despiste mande el archivo equivocado.
 *
 *  1. El tipo se decide por los BYTES, nunca por la extensión ni por lo que
 *     declare el navegador.
 *  2. Solo pasa lo que está en la allowlist —y, además, solo lo que WhatsApp
 *     acepta de verdad: mandar un GIF como imagen es un 400 garantizado—.
 *  3. El tamaño se corta contra el techo REAL de Meta por familia, antes de
 *     ocupar disco. Un archivo que Meta va a rechazar no es un fallo que
 *     merezca reintentos: es uno que no debe llegar a salir.
 *  4. Se guarda con nombre aleatorio. El nombre que puso el usuario solo se
 *     muestra y viaja como `filename` del documento.
 */
class OutboundAttachmentService
{
    /**
     * Lo que WhatsApp Cloud API acepta de verdad, por tipo de nodo.
     *
     * No es la allowlist de seguridad —esa ya se aplicó— sino la lista del
     * proveedor. Están separadas a propósito: un `image/gif` es un archivo
     * perfectamente seguro que Meta rechaza como imagen, y confundir ambas
     * cosas lleva a rechazar archivos legítimos o a mandar envíos que fallan.
     */
    private const META_ACCEPTS = [
        'image' => ['image/jpeg', 'image/png'],
        'sticker' => ['image/webp'],
        'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus'],
        'video' => ['video/mp4', 'video/3gpp'],
    ];

    /** Un sticker por encima de esto lo rechaza Meta; se manda como documento. */
    private const STICKER_MAX_BYTES = 500 * 1024;

    /**
     * Formatos que un navegador puede grabar pero WhatsApp no reproduce. Se
     * aceptan SOLO como origen de una nota de voz y se convierten antes de
     * salir; nunca se guardan ni se envían tal cual.
     */
    private const TRANSCODABLE_VOICE = ['audio/webm', 'video/webm', 'audio/x-matroska', 'video/x-matroska'];

    public function __construct(private readonly MetaMediaService $media) {}

    /**
     * Guarda un archivo subido desde el CRM como borrador.
     *
     * Devuelve un resultado en vez de lanzar: cada rechazo tiene un motivo que
     * el compositor debe poder enseñar tal cual ("pesa demasiado", "ese tipo no
     * se puede mandar"), y una excepción genérica no lo permite.
     *
     * @return array{ok:bool,attachment:?MarketingMessageAttachment,code:?string,message:?string}
     */
    public function store(UploadedFile $file, ?int $adminId, bool $voice = false): array
    {
        if (! (bool) config('marketing.media.outbound.enabled', true)) {
            return $this->reject('outbound_media_disabled', 'El envío de archivos está desactivado.');
        }

        if ($voice && ! (bool) config('marketing.media.outbound.voice.enabled', true)) {
            return $this->reject('voice_notes_disabled', 'Las notas de voz están desactivadas.');
        }

        // El contenido se lee una sola vez y se trabaja en memoria: el techo por
        // archivo ya lo garantiza el límite de subida, y así el binario nunca
        // queda a medias en el disco definitivo si algo falla después.
        try {
            $binary = (string) file_get_contents($file->getRealPath());
        } catch (Throwable) {
            return $this->reject('upload_unreadable', 'No se pudo leer el archivo subido.');
        }

        if ($binary === '') {
            return $this->reject('empty_file', 'El archivo está vacío.');
        }

        $detected = $this->media->detectMime($binary);
        if ($detected === null) {
            return $this->reject('unknown_type', 'No se pudo determinar el tipo del archivo.');
        }

        // Nota de voz grabada en un formato que WhatsApp no reproduce: se
        // convierte aquí o no sale. Chrome solo graba WebM, así que sin esto la
        // función andaría en Firefox y fallaría en el navegador de casi todos.
        if ($voice && in_array($detected, self::TRANSCODABLE_VOICE, true)) {
            $converted = $this->transcodeToOpus($binary);
            if ($converted === null) {
                return $this->reject(
                    'voice_transcode_failed',
                    'No se pudo convertir la nota de voz al formato de WhatsApp.',
                );
            }
            $binary = $converted;
            $detected = $this->media->detectMime($binary) ?? 'audio/ogg';
        }

        $allowed = (array) config('marketing.media.allowed_mime_types', []);
        if (! in_array($detected, $allowed, true)) {
            ChannelLog::warning('media.outbound.rejected', [
                'admin_id' => $adminId,
                'detected' => $detected,
                'declared' => $file->getClientMimeType(),
            ]);

            return $this->reject('disallowed_type', 'Ese tipo de archivo no se puede enviar.');
        }

        $kind = $this->kindFor($detected, strlen($binary), $voice);

        if ($voice && $kind !== 'audio') {
            return $this->reject('not_audio', 'Una nota de voz tiene que ser un audio.');
        }

        if ($limit = $this->sizeLimitFor($kind)) {
            if (strlen($binary) > $limit) {
                return $this->reject(
                    'too_large',
                    sprintf('El archivo pesa más de %d MB, el máximo para este tipo.', (int) round($limit / 1048576)),
                );
            }
        }

        $sha = hash('sha256', $binary);
        $disk = (string) config('marketing.media.disk', 'whatsapp');
        $path = sprintf(
            'outbound/%s/%s/%s-%s.%s',
            $kind,
            now()->format('Y/m'),
            substr($sha, 0, 16),
            Str::random(12),
            $this->extensionFor($detected),
        );

        Storage::disk($disk)->put($path, $binary);

        $attachment = MarketingMessageAttachment::create([
            'message_id' => null,                    // borrador: aún no hay mensaje.
            'direction' => 'outbound',
            'uploaded_by_admin_id' => $adminId,
            'kind' => $kind,
            'declared_mime_type' => $file->getClientMimeType(),
            'detected_mime_type' => $detected,
            'sha256' => $sha,
            'size_bytes' => strlen($binary),
            'original_filename' => MetaMediaService::sanitizeFilename(
                $file->getClientOriginalName(),
                $this->extensionFor($detected),
            ),
            'disk' => $disk,
            'path' => $path,
            'voice' => $voice,
            'status' => MarketingMessageAttachment::STATUS_STORED,
            'downloaded_at' => now(),
        ]);

        $this->enrichDimensions($attachment, $binary, $detected);

        ChannelLog::info('media.outbound.stored', [
            'attachment_id' => $attachment->id,
            'admin_id' => $adminId,
            'kind' => $kind,
            'detected_mime' => $detected,
            'size_bytes' => strlen($binary),
            'voice' => $voice,
        ]);

        return ['ok' => true, 'attachment' => $attachment->fresh(), 'code' => null, 'message' => null];
    }

    /**
     * Toma los borradores que un envío quiere usar, en el orden pedido.
     *
     * Solo devuelve los que siguen sin mensaje y los que subió QUIEN ENVÍA. Sin
     * esa segunda condición, pasar un id al azar dejaría adjuntar el archivo de
     * otra persona a la conversación propia, y por ahí se filtra la foto de un
     * cliente a otro.
     *
     * @param  array<int,int>  $ids
     * @return Collection<int, MarketingMessageAttachment>
     */
    public function claim(array $ids, ?int $adminId): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        $found = MarketingMessageAttachment::query()
            ->whereIn('id', $ids)
            ->where('direction', 'outbound')
            ->whereNull('message_id')
            ->where('status', MarketingMessageAttachment::STATUS_STORED)
            ->when($adminId !== null, fn ($q) => $q->where('uploaded_by_admin_id', $adminId))
            ->get()
            ->keyBy('id');

        // El orden lo manda quien envía, no la base: si adjuntó la portada y
        // después el detalle, así tienen que llegar.
        return collect($ids)
            ->map(fn (int $id) => $found->get($id))
            ->filter()
            ->values();
    }

    /**
     * Borra los borradores que nadie llegó a enviar.
     *
     * Son archivos que alguien soltó en el compositor y abandonó. No tienen
     * mensaje, no se ven en ningún sitio y ocupan disco para siempre.
     *
     * @return int Borradores eliminados.
     */
    public function pruneDrafts(): int
    {
        $hours = max(1, (int) config('marketing.media.outbound.draft_ttl_hours', 24));
        $cutoff = now()->subHours($hours);

        $stale = MarketingMessageAttachment::query()
            ->where('direction', 'outbound')
            ->whereNull('message_id')
            ->where('created_at', '<', $cutoff)
            ->get();

        $removed = 0;

        foreach ($stale as $draft) {
            // El binario primero: si el borrado del expediente fallara después,
            // queda una ficha huérfana (inocua) y no un archivo sin dueño.
            if ($draft->path && $draft->disk) {
                try {
                    Storage::disk((string) $draft->disk)->delete((string) $draft->path);
                } catch (Throwable $e) {
                    ChannelLog::warning('media.outbound.prune_failed', [
                        'attachment_id' => $draft->id,
                        'error_class' => class_basename($e),
                    ]);

                    continue;
                }
            }

            $draft->delete();
            $removed++;
        }

        if ($removed > 0) {
            ChannelLog::info('media.outbound.pruned', ['removed' => $removed, 'older_than_hours' => $hours]);
        }

        return $removed;
    }

    /**
     * ¿Se pueden grabar notas de voz en este servidor?
     *
     * La respuesta depende de que exista ffmpeg, porque Chrome graba WebM y
     * WhatsApp no lo reproduce. El inbox pregunta esto para decidir si enseña
     * el botón del micrófono: ofrecer un botón que va a fallar es peor que no
     * ofrecerlo.
     */
    public function voiceNotesAvailable(): bool
    {
        return (bool) config('marketing.media.outbound.enabled', true)
            && (bool) config('marketing.media.outbound.voice.enabled', true)
            && $this->ffmpegAvailable();
    }

    /**
     * Nodo de WhatsApp con el que viaja este archivo.
     *
     * No es el tipo general del MIME. Un `image/gif` es una imagen para
     * cualquiera menos para Cloud API, que solo admite JPEG y PNG como imagen;
     * mandarlo como tal es un 400. Va como documento, que llega igual y se ve.
     */
    private function kindFor(string $mime, int $bytes, bool $voice): string
    {
        if ($voice) {
            return 'audio';
        }

        foreach (['image', 'audio', 'video'] as $kind) {
            if (in_array($mime, self::META_ACCEPTS[$kind], true)) {
                return $kind;
            }
        }

        // Un webp pequeño es un sticker; uno grande lo rechaza Meta, así que va
        // como documento en lugar de fallar en el envío.
        if ($mime === 'image/webp' && $bytes <= self::STICKER_MAX_BYTES) {
            return 'sticker';
        }

        return 'document';
    }

    private function sizeLimitFor(string $kind): ?int
    {
        $limits = (array) config('marketing.media.outbound.max_size_bytes', []);
        $family = $kind === 'sticker' ? 'image' : $kind;

        $limit = $limits[$family] ?? null;

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    /**
     * WebM → OGG/Opus, que es lo único que WhatsApp reproduce como nota de voz.
     *
     * Se hace sobre ficheros temporales y no por tubería porque ffmpeg necesita
     * poder retroceder en el contenedor de entrada para leer sus índices, y por
     * stdin no puede. El proceso lleva tiempo máximo: un archivo manipulado que
     * hiciera girar a ffmpeg no puede quedarse con un worker para siempre.
     */
    private function transcodeToOpus(string $binary): ?string
    {
        if (! $this->ffmpegAvailable()) {
            ChannelLog::warning('media.outbound.transcode_unavailable', []);

            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'wa_in_');
        $out = tempnam(sys_get_temp_dir(), 'wa_out_').'.ogg';

        if ($in === false) {
            return null;
        }

        try {
            file_put_contents($in, $binary);

            $process = new Process([
                (string) config('marketing.media.outbound.voice.ffmpeg', 'ffmpeg'),
                '-hide_banner', '-loglevel', 'error',
                '-i', $in,
                '-vn',                      // nada de video: es una nota de voz.
                '-c:a', 'libopus',
                '-b:a', '32k',              // de sobra para voz; pesa poco en datos.
                '-ar', '48000',
                '-ac', '1',                 // mono: es una persona hablando.
                '-f', 'ogg',
                '-y', $out,
            ]);
            $process->setTimeout((float) config('marketing.media.outbound.voice.transcode_timeout', 30));
            $process->run();

            if (! $process->isSuccessful()) {
                ChannelLog::warning('media.outbound.transcode_failed', [
                    'exit_code' => $process->getExitCode(),
                ]);

                return null;
            }

            $result = @file_get_contents($out);

            return is_string($result) && $result !== '' ? $result : null;
        } catch (ProcessTimedOutException) {
            ChannelLog::warning('media.outbound.transcode_timeout', []);

            return null;
        } catch (Throwable $e) {
            ChannelLog::warning('media.outbound.transcode_exception', [
                'error_class' => class_basename($e),
            ]);

            return null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private function ffmpegAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        try {
            $process = new Process([
                (string) config('marketing.media.outbound.voice.ffmpeg', 'ffmpeg'),
                '-version',
            ]);
            $process->setTimeout(5.0);
            $process->run();

            return $available = $process->isSuccessful();
        } catch (Throwable) {
            return $available = false;
        }
    }

    /** Ancho y alto de una imagen: el inbox reserva el hueco y no da saltos. */
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
            $attachment->forceFill(['width' => (int) $info[0], 'height' => (int) $info[1]])->save();
        } catch (Throwable) {
            // Una miniatura no vale una excepción: el archivo ya está guardado.
        }
    }

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

    /** @return array{ok:bool,attachment:null,code:string,message:string} */
    private function reject(string $code, string $message): array
    {
        return ['ok' => false, 'attachment' => null, 'code' => $code, 'message' => $message];
    }
}
