<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un archivo que llega por WhatsApp es contenido no confiable.
 *
 * Puede venir con el tipo mentido, pesar mucho más de lo que declara, o ser un
 * HTML que se ejecutaría en nuestro dominio si alguna vez se sirviera directo.
 * Estas pruebas fijan que la capa de medios asuma lo peor: comprueba los bytes
 * y no la etiqueta, corta por tamaño antes de escribir, no sigue URLs que no
 * sean de Meta y nunca deja que el nombre del cliente forme parte de una ruta.
 */
class MediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** JPEG mínimo real: empieza por los magic bytes que finfo reconoce. */
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    protected function setUp(): void
    {
        parent::setUp();

        // Meta configurado: aquí sí queremos ejercitar la descarga.
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'token-de-prueba');
        config()->set('meta.app_secret', 'secreto-de-prueba');
        config()->set('meta.whatsapp_phone_number_id', '123456');
        config()->set('marketing.media.disk', 'whatsapp');
        config()->set('marketing.media.download_enabled', true);

        Storage::fake('whatsapp');
    }

    private function attachment(array $overrides = []): MarketingMessageAttachment
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573150536026',
            'name' => 'Prospecto', 'phone' => '573150536026', 'status' => 'new',
        ]);
        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'meta_message_id' => 'wamid.'.uniqid(),
        ]);

        return MarketingMessageAttachment::create(array_merge([
            'message_id' => $message->id,
            'direction' => 'inbound',
            'kind' => 'image',
            'media_id' => 'media-1',
            'declared_mime_type' => 'image/jpeg',
            'status' => MarketingMessageAttachment::STATUS_PENDING,
        ], $overrides));
    }

    /** Meta responde primero con la URL temporal y después con el binario. */
    private function fakeMeta(string $binary, string $url = 'https://lookaside.fbcdn.net/media/abc', array $headers = []): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => $url, 'mime_type' => 'image/jpeg'], 200),
            '*fbcdn.net*' => Http::response($binary, 200, $headers),
        ]);
    }

    private function service(): MetaMediaService
    {
        return app(MetaMediaService::class);
    }

    public function test_a_genuine_image_is_downloaded_hashed_and_stored_privately(): void
    {
        $this->fakeMeta(self::JPEG);
        $attachment = $this->attachment();

        $this->assertTrue($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertSame('image/jpeg', $attachment->detected_mime_type);
        $this->assertSame(hash('sha256', self::JPEG), $attachment->sha256);
        $this->assertSame(strlen(self::JPEG), $attachment->size_bytes);
        $this->assertNotNull($attachment->downloaded_at);
        $this->assertNotNull($attachment->expires_at, 'La retención debe quedar fijada al guardar.');

        Storage::disk('whatsapp')->assertExists($attachment->path);
    }

    /**
     * El corazón de la defensa: Meta dice "PDF", los bytes dicen PNG. Un
     * archivo disfrazado no se guarda, y no se reintenta.
     */
    public function test_a_file_lying_about_its_type_is_rejected_and_never_stored(): void
    {
        $this->fakeMeta(self::PNG);
        $attachment = $this->attachment([
            'kind' => 'document',
            'declared_mime_type' => 'application/pdf',
        ]);

        $this->assertFalse($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        $this->assertSame('mime_mismatch', $attachment->failure_reason);
        $this->assertNull($attachment->path);
        $this->assertSame(['/'], Storage::disk('whatsapp')->allFiles() ?: ['/']);
    }

    /**
     * HTML no está en la allowlist por una razón concreta: servido desde
     * nuestro dominio sería XSS con sesión de administrador.
     */
    public function test_html_disguised_as_an_image_never_reaches_the_disk(): void
    {
        $this->fakeMeta('<html><script>fetch("/api/admin/users")</script></html>');
        $attachment = $this->attachment(['declared_mime_type' => 'image/png']);

        $this->assertFalse($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        $this->assertStringContainsString('mime', (string) $attachment->failure_reason);
        $this->assertEmpty(Storage::disk('whatsapp')->allFiles());
    }

    public function test_a_file_over_the_size_limit_is_refused_before_being_written(): void
    {
        config()->set('marketing.media.max_size_bytes', 1024);
        // 4 KB de JPEG válido: el tipo está bien, el tamaño no.
        $this->fakeMeta(self::JPEG.str_repeat("\x00", 4096));
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        $this->assertSame('too_large', $attachment->failure_reason);
        $this->assertEmpty(Storage::disk('whatsapp')->allFiles());
    }

    /** Una cabecera Content-Length enorme se corta antes incluso de leer. */
    public function test_a_declared_huge_size_is_refused_up_front(): void
    {
        config()->set('marketing.media.max_size_bytes', 1024);
        $this->fakeMeta(self::JPEG, headers: ['Content-Length' => (string) (50 * 1024 * 1024)]);
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));

        $this->assertSame('too_large_declared', $attachment->fresh()->failure_reason);
    }

    /**
     * SSRF: si la URL que devuelve Graph apuntara a la red interna, la
     * pediríamos con nuestro token puesto. Solo se siguen hosts de Meta.
     */
    public function test_a_media_url_outside_meta_is_never_fetched(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'http://169.254.169.254/latest/meta-data/'], 200),
            '*' => Http::response('secretos de la nube', 200),
        ]);
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));

        // Jamás se hizo la segunda petición.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
        $this->assertSame('media_url_unavailable', $attachment->fresh()->failure_reason);
    }

    public function test_a_plain_http_media_url_is_refused(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'http://lookaside.fbcdn.net/media/abc'], 200),
            '*' => Http::response(self::JPEG, 200),
        ]);
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));
        $this->assertSame('media_url_unavailable', $attachment->fresh()->failure_reason);
    }

    /** El mismo archivo reenviado por veinte personas ocupa disco una vez. */
    public function test_the_same_file_twice_is_stored_only_once(): void
    {
        $this->fakeMeta(self::JPEG);

        $first = $this->attachment();
        $this->service()->download($first);

        $second = $this->attachment(['media_id' => 'media-2']);
        $this->service()->download($second);

        $this->assertSame($first->fresh()->path, $second->fresh()->path);
        $this->assertCount(1, Storage::disk('whatsapp')->allFiles());
        // Las dos fichas existen y las dos son servibles.
        $this->assertTrue($second->fresh()->isServable());
    }

    /** Un 5xx de Meta es transitorio: se reintenta, no se descarta. */
    public function test_a_transient_meta_failure_stays_retryable(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbcdn.net/media/abc'], 200),
            '*fbcdn.net*' => Http::response('', 503),
        ]);
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_FAILED, $attachment->status);
        $this->assertSame('download_http_503', $attachment->failure_reason);
    }

    /** Agotados los intentos deja de reintentarse: insistir no lo va a arreglar. */
    public function test_after_exhausting_attempts_it_stops_retrying(): void
    {
        config()->set('marketing.media.max_attempts', 2);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbcdn.net/media/abc'], 200),
            '*fbcdn.net*' => Http::response('', 503),
        ]);
        $attachment = $this->attachment();

        $this->service()->download($attachment);
        $this->service()->download($attachment->fresh());

        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->fresh()->status);
    }

    /** Sin credenciales de Meta no es culpa del archivo: queda pendiente. */
    public function test_without_meta_credentials_the_attachment_waits_instead_of_dying(): void
    {
        config()->set('meta.enabled', false);
        $attachment = $this->attachment();

        $this->assertFalse($this->service()->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_PENDING, $attachment->status);
        $this->assertSame('meta_disabled_or_unconfigured', $attachment->failure_reason);
    }

    public function test_downloading_an_already_stored_attachment_does_nothing(): void
    {
        $this->fakeMeta(self::JPEG);
        $attachment = $this->attachment();
        $this->service()->download($attachment);

        $pathBefore = $attachment->fresh()->path;
        $this->assertTrue($this->service()->download($attachment->fresh()));

        $this->assertSame($pathBefore, $attachment->fresh()->path);
        $this->assertCount(1, Storage::disk('whatsapp')->allFiles());
    }

    /**
     * La ruta se construye con azar y con el tipo REAL. Nada de lo que escriba
     * el cliente participa en ella.
     */
    public function test_the_stored_path_owes_nothing_to_the_client_filename(): void
    {
        $this->fakeMeta(self::JPEG);
        $attachment = $this->attachment([
            'original_filename' => 'factura ../../secreto.jpg',
        ]);

        $this->service()->download($attachment);

        $path = (string) $attachment->fresh()->path;
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('secreto', $path);
        $this->assertStringEndsWith('.jpg', $path);
    }

    #[DataProvider('malignFilenames')]
    public function test_filenames_are_sanitized(string $input, string $expectation): void
    {
        $this->assertSame($expectation, MetaMediaService::sanitizeFilename($input));
    }

    public static function malignFilenames(): array
    {
        return [
            'ruta relativa' => ['../../../etc/passwd', 'passwd'],
            'ruta absoluta' => ['/var/www/.env', '.env'],
            'punto y punto' => ['....//....//etc', 'etc'],
            'nulo dentro' => ["factura\x00.php", 'factura_.php'],
            'vacío' => ['', 'archivo.bin'],
            'normal se respeta' => ['Plan Mensual 2026.pdf', 'Plan Mensual 2026.pdf'],
        ];
    }
}
