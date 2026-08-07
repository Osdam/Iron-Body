<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cómo sale de verdad un archivo por WhatsApp Cloud API.
 *
 * Aquí META sí está encendido —contra un Graph FALSO— porque es el único modo
 * de probar lo que de verdad puede salir mal en producción: que el archivo se
 * suba dos veces, que salga el pie sin la foto, o que un reintento le mande al
 * cliente la misma imagen otra vez.
 *
 * Ninguna de estas pruebas toca la red: `preventStrayRequests` hace fallar
 * cualquier petición que no esté falseada, así que si algún día se cuela una
 * llamada real, se entera esta suite y no el número del gimnasio.
 */
class OutboundMediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('whatsapp');
        config()->set('marketing.media.disk', 'whatsapp');

        // Graph falso: credenciales de mentira, servidor de mentira.
        config()->set('meta.enabled', true);
        config()->set('meta.whatsapp_phone_number_id', '123456789');
        config()->set('meta.access_token', 'token-de-prueba');
        config()->set('meta.app_secret', 'secreto-de-prueba');

        Http::preventStrayRequests();

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    /** Graph que acepta la subida y el envío. */
    private function fakeGraphOk(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'media-abc-123'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.ENVIADO']]], 200),
        ]);
    }

    private function messageWithFile(string $kind = 'image', string $body = 'Mira el plan'): MarketingMessage
    {
        Storage::disk('whatsapp')->put('outbound/image/x.jpg', 'contenido-binario');

        $message = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_HUMAN,
            'body' => $body,
            'status' => WhatsappOutboxService::STATUS_QUEUED,
        ]);

        MarketingMessageAttachment::create([
            'message_id' => $message->id, 'direction' => 'outbound', 'kind' => $kind,
            'detected_mime_type' => 'image/jpeg', 'disk' => 'whatsapp',
            'path' => 'outbound/image/x.jpg', 'size_bytes' => 17,
            'original_filename' => 'plan.jpg',
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        return $message;
    }

    private function outbox(): WhatsappOutboxService
    {
        return app(WhatsappOutboxService::class);
    }

    // ── El camino normal ────────────────────────────────────────────────────

    /**
     * Dos llamadas y en este orden: primero sube el binario, después manda el
     * mensaje citando el id que devolvió. Cloud API no admite el archivo
     * dentro del mensaje.
     */
    public function test_the_file_is_uploaded_first_and_the_message_cites_it(): void
    {
        $this->fakeGraphOk();
        $message = $this->messageWithFile();

        $result = $this->outbox()->deliver($message, '573150536026');

        $this->assertTrue($result['sent']);
        $this->assertSame('wamid.ENVIADO', $result['provider_message_id']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media'));
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }

            return $r['type'] === 'image'
                && $r['image']['id'] === 'media-abc-123'
                && $r['image']['caption'] === 'Mira el plan';
        });
    }

    /** El id de la subida se guarda: es lo que impide subirlo dos veces. */
    public function test_the_media_id_is_remembered(): void
    {
        $this->fakeGraphOk();
        $message = $this->messageWithFile();

        $this->outbox()->deliver($message, '573150536026');

        $this->assertSame('media-abc-123', $message->attachments()->first()->media_id);
    }

    /**
     * Un documento viaja con su nombre: sin él, al cliente le llega
     * "documento.pdf" y no sabe qué abrió.
     */
    public function test_a_document_keeps_its_filename(): void
    {
        $this->fakeGraphOk();
        $message = $this->messageWithFile('document');

        $this->outbox()->deliver($message, '573150536026');

        Http::assertSent(fn (Request $r) => ! str_ends_with($r->url(), '/media')
            && $r['document']['filename'] === 'plan.jpg');
    }

    /**
     * Audio no admite pie de foto: mandarlo es un 400 de Meta. El texto ya
     * salió como mensaje aparte, así que aquí no puede colarse.
     */
    public function test_audio_never_carries_a_caption(): void
    {
        $this->fakeGraphOk();
        $message = $this->messageWithFile('audio', 'esto no debe viajar');

        $this->outbox()->deliver($message, '573150536026');

        Http::assertSent(fn (Request $r) => ! str_ends_with($r->url(), '/media')
            && ! isset($r['audio']['caption']));
    }

    // ── Lo que puede salir mal ──────────────────────────────────────────────

    /**
     * La regla que evita el mensaje absurdo: si el archivo no se pudo subir,
     * NO se manda el mensaje. El cliente leyendo "mira esto" sin nada que
     * mirar es peor que no recibir nada.
     */
    public function test_when_the_upload_fails_the_message_is_not_sent(): void
    {
        Http::fake([
            '*/media' => Http::response(['error' => ['code' => 500]], 500),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.NO_DEBE_SALIR']]], 200),
        ]);

        $message = $this->messageWithFile();
        $result = $this->outbox()->deliver($message, '573150536026');

        $this->assertFalse($result['sent']);
        $this->assertSame('media_upload_failed', $result['reason']);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messages'));
        $this->assertNull($message->fresh()->meta_message_id);
    }

    /** Un fallo de subida es transitorio: merece otro intento, no rendirse. */
    public function test_a_failed_upload_is_scheduled_for_another_attempt(): void
    {
        Http::fake([
            '*/media' => Http::response(['error' => ['code' => 500]], 500),
            '*/messages' => Http::response([], 200),
        ]);

        $message = $this->messageWithFile();
        $this->outbox()->deliver($message, '573150536026');

        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->next_attempt_at);
    }

    /**
     * El caso que de verdad duele: reintentar un mensaje que YA salió. No se
     * vuelve a subir el archivo ni se vuelve a mandar; el cliente no recibe la
     * foto dos veces.
     */
    public function test_retrying_a_delivered_message_sends_nothing_again(): void
    {
        $this->fakeGraphOk();
        $message = $this->messageWithFile();

        $this->outbox()->deliver($message, '573150536026');
        $callsAfterFirst = count(Http::recorded());

        $second = $this->outbox()->deliver($message->fresh(), '573150536026');

        $this->assertTrue($second['sent']);
        $this->assertSame('already_delivered', $second['reason']);
        $this->assertCount($callsAfterFirst, Http::recorded(), 'Se repitió una llamada a Meta.');
    }

    /**
     * Si el envío falla DESPUÉS de subir, el reintento reutiliza la subida.
     * Sin esto quedarían dos archivos en Meta y dos ids, con el riesgo de que
     * los dos acaben saliendo.
     */
    public function test_a_retry_reuses_the_upload_instead_of_repeating_it(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'media-abc-123'], 200),
            '*/messages' => Http::response(['error' => ['code' => 4]], 429),
        ]);

        $message = $this->messageWithFile();

        $this->outbox()->deliver($message, '573150536026');
        $this->outbox()->deliver($message->fresh(), '573150536026');

        $uploads = collect(Http::recorded())
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/media'))
            ->count();

        $this->assertSame(1, $uploads, 'El archivo se subió a Meta más de una vez.');
    }

    /** Un mensaje sin archivo sigue saliendo como texto, sin pasar por /media. */
    public function test_a_plain_text_message_never_touches_the_media_endpoint(): void
    {
        $this->fakeGraphOk();

        $message = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_HUMAN,
            'body' => 'solo texto',
            'status' => WhatsappOutboxService::STATUS_QUEUED,
        ]);

        $this->outbox()->deliver($message, '573150536026');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media'));
    }
}
