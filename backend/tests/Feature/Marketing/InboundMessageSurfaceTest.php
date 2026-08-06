<?php

namespace Tests\Feature\Marketing;

use App\Jobs\DownloadWhatsappMedia;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Todo lo que una persona puede mandar por WhatsApp tiene que llegar al inbox.
 *
 * El pipeline anterior solo entendía texto: cualquier otra cosa se guardaba sin
 * cuerpo y se escalaba a ciegas. Aquí se fija que cada tipo se reconoce, se
 * guarda con la información que necesita quien atiende, y que el cerebro
 * comercial solo lee lo que puede leer de verdad. Un tipo que Meta invente
 * mañana no puede hacernos perder el mensaje.
 */
class InboundMessageSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    private const FROM = '573150536026';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.inbound.auto_analyze', true);
        config()->set('marketing.inbound.auto_execute', false);
        config()->set('marketing.agent_enabled', false);
        Http::fake();
    }

    /** Envía un mensaje del tipo indicado por el webhook real, con firma válida. */
    private function send(array $message): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '+573143455483'],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => self::FROM]],
            'messages' => [array_merge(['from' => self::FROM, 'timestamp' => '1700000000'], $message)],
        ]]]]]];

        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function lastInbound(): MarketingMessage
    {
        return MarketingMessage::where('direction', 'inbound')->latest('id')->firstOrFail();
    }

    /**
     * Pulsar un botón ES hablar. El título del botón es lo que la persona
     * quiso decir, así que el agente debe poder leerlo como si lo hubiera
     * escrito.
     */
    public function test_a_button_reply_is_read_as_what_the_person_said(): void
    {
        $this->send([
            'id' => 'wamid.BTN', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'ver_planes', 'title' => 'Ver planes']],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('Ver planes', $message->body);
        $this->assertSame('button_reply', data_get($message->metadata, 'interactive.kind'));
        $this->assertSame('ver_planes', data_get($message->metadata, 'interactive.id'));

        // No se escaló: era legible, así que el agente lo atendió.
        $this->assertDatabaseMissing('marketing_ai_actions', ['action_type' => 'unsupported_message']);
    }

    public function test_a_list_reply_keeps_the_chosen_option(): void
    {
        $this->send([
            'id' => 'wamid.LIST', 'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => [
                'id' => 'plan_mensual', 'title' => 'Plan Mensual', 'description' => 'Acceso completo',
            ]],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('Plan Mensual', $message->body);
        $this->assertSame('Acceso completo', data_get($message->metadata, 'interactive.description'));
    }

    /** El botón de una plantilla llega con otra forma, pero significa lo mismo. */
    public function test_a_template_quick_reply_button_is_also_readable(): void
    {
        $this->send([
            'id' => 'wamid.TPLBTN', 'type' => 'button',
            'button' => ['payload' => 'SI_ME_INTERESA', 'text' => 'Sí, me interesa'],
        ])->assertOk();

        $this->assertSame('Sí, me interesa', $this->lastInbound()->body);
    }

    public function test_an_image_registers_an_attachment_and_queues_its_download(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $this->send([
            'id' => 'wamid.IMG', 'type' => 'image',
            'image' => ['id' => 'media-abc', 'mime_type' => 'image/jpeg', 'sha256' => 'deadbeef'],
        ])->assertOk();

        $attachment = MarketingMessageAttachment::sole();
        $this->assertSame('image', $attachment->kind);
        $this->assertSame('media-abc', $attachment->media_id);
        $this->assertSame('image/jpeg', $attachment->declared_mime_type);
        $this->assertSame(MarketingMessageAttachment::STATUS_PENDING, $attachment->status);

        Queue::assertPushed(DownloadWhatsappMedia::class);
    }

    /**
     * Una foto SIN texto no la interpreta la IA: se escala. Adivinar qué
     * muestra una imagen y responder un precio a partir de eso es justo lo que
     * no debe pasar.
     */
    public function test_an_image_without_a_caption_goes_to_a_human(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $this->send([
            'id' => 'wamid.IMGNC', 'type' => 'image',
            'image' => ['id' => 'media-nc', 'mime_type' => 'image/jpeg'],
        ])->assertOk();

        $this->assertDatabaseHas('marketing_ai_actions', [
            'action_type' => 'unsupported_message',
            'reason' => 'needs_human_review',
        ]);
        $this->assertTrue((bool) MarketingConversation::sole()->staff_review_pending);
    }

    /**
     * Con pie de foto sí hay una pregunta legible ("¿este plan me sirve?" bajo
     * una captura): el texto se atiende y el archivo sigue su camino.
     */
    public function test_an_image_with_a_caption_is_answered_and_still_stored(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $this->send([
            'id' => 'wamid.IMGC', 'type' => 'image',
            'image' => ['id' => 'media-c', 'mime_type' => 'image/jpeg', 'caption' => '¿cuánto vale este plan?'],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('¿cuánto vale este plan?', $message->body);
        $this->assertDatabaseCount('marketing_message_attachments', 1);
        $this->assertDatabaseMissing('marketing_ai_actions', ['reason' => 'needs_human_review']);
    }

    public function test_a_voice_note_is_marked_as_voice_and_escalated(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $this->send([
            'id' => 'wamid.VOICE', 'type' => 'audio',
            'audio' => ['id' => 'media-voice', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true],
        ])->assertOk();

        $attachment = MarketingMessageAttachment::sole();
        $this->assertSame('audio', $attachment->kind);
        $this->assertTrue($attachment->voice);

        $this->assertDatabaseHas('marketing_ai_actions', ['reason' => 'needs_human_review']);
    }

    /**
     * El nombre del archivo lo escribe el cliente. Si llega con rutas dentro,
     * se guarda saneado: nunca puede convertirse en parte de una ruta real.
     */
    public function test_a_malicious_filename_is_stripped_of_any_path(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $this->send([
            'id' => 'wamid.DOC', 'type' => 'document',
            'document' => [
                'id' => 'media-doc', 'mime_type' => 'application/pdf',
                'filename' => '../../../../etc/passwd',
            ],
        ])->assertOk();

        $attachment = MarketingMessageAttachment::sole();
        $this->assertSame('passwd', $attachment->original_filename);
        $this->assertStringNotContainsString('..', (string) $attachment->original_filename);
        $this->assertStringNotContainsString('/', (string) $attachment->original_filename);
    }

    public function test_a_location_is_kept_and_handed_to_a_human(): void
    {
        $this->send([
            'id' => 'wamid.LOC', 'type' => 'location',
            'location' => ['latitude' => 2.9273, 'longitude' => -75.2819, 'name' => 'Neiva'],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('Neiva', data_get($message->metadata, 'location.name'));
        $this->assertDatabaseHas('marketing_ai_actions', ['reason' => 'needs_human_review']);
    }

    /** Un 👍 a un mensaje nuestro no es una consulta y no merece respuesta. */
    public function test_a_reaction_is_recorded_but_never_answered(): void
    {
        $this->send([
            'id' => 'wamid.REACT', 'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.OURS', 'emoji' => '👍'],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('👍', data_get($message->metadata, 'reaction.emoji'));

        // Ni respuesta ni escalamiento: se registra y ya.
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
        $this->assertSame(0, MarketingAiAction::count());
    }

    public function test_a_quoted_reply_remembers_what_it_answered(): void
    {
        $this->send([
            'id' => 'wamid.QUOTE', 'type' => 'text', 'text' => ['body' => 'ese mismo'],
            'context' => ['from' => '573143455483', 'id' => 'wamid.PREVIOUS'],
        ])->assertOk();

        $this->assertSame(
            'wamid.PREVIOUS',
            data_get($this->lastInbound()->metadata, 'context.quoted_meta_message_id'),
        );
    }

    /** De dónde vino el prospecto: el anuncio que tocó para escribirnos. */
    public function test_a_click_to_whatsapp_ad_referral_is_preserved(): void
    {
        $this->send([
            'id' => 'wamid.ADS', 'type' => 'text', 'text' => ['body' => 'hola, vi el anuncio'],
            'referral' => [
                'source_type' => 'ad', 'source_id' => '120210000',
                'headline' => 'Primer mes con acompañamiento',
                'source_url' => 'https://fb.me/anuncio',
            ],
        ])->assertOk();

        $metadata = $this->lastInbound()->metadata;
        $this->assertSame('ad', data_get($metadata, 'referral.source_type'));
        $this->assertSame('120210000', data_get($metadata, 'referral.source_id'));
    }

    /** Meta dice explícitamente que no pudo entregarnos el contenido. */
    public function test_a_type_meta_calls_unsupported_is_escalated_with_its_error(): void
    {
        $this->send([
            'id' => 'wamid.UNSUP', 'type' => 'unsupported',
            'errors' => [['code' => 131051, 'title' => 'Message type is not currently supported']],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame(131051, data_get($message->metadata, 'errors.0.code'));
        $this->assertDatabaseHas('marketing_ai_actions', ['reason' => 'unsupported_message_type']);
    }

    /**
     * El seguro de fondo: un tipo que no existía cuando se escribió este código
     * se guarda y se escala. Perder el mensaje sería el peor desenlace.
     */
    public function test_a_type_invented_after_this_code_was_written_is_still_kept(): void
    {
        $this->send([
            'id' => 'wamid.FUTURE', 'type' => 'holographic_greeting',
            'holographic_greeting' => ['whatever' => true],
        ])->assertOk();

        $message = $this->lastInbound();
        $this->assertSame('holographic_greeting', data_get($message->metadata, 'message_type'));
        $this->assertTrue((bool) data_get($message->metadata, 'unsupported_message'));
        $this->assertDatabaseHas('marketing_ai_actions', ['action_type' => 'unsupported_message']);
    }

    /** Reprocesar el mismo evento no crea un segundo adjunto. */
    public function test_reprocessing_does_not_duplicate_attachments(): void
    {
        // Fake PARCIAL: solo la descarga. Si se falsea toda la cola, el propio
        // job del webhook tampoco corre y no habría nada que comprobar.
        Queue::fake([DownloadWhatsappMedia::class]);

        $message = [
            'id' => 'wamid.IDEM', 'type' => 'image',
            'image' => ['id' => 'media-idem', 'mime_type' => 'image/jpeg', 'caption' => 'hola'],
        ];

        $this->send($message)->assertOk();
        $this->send($message)->assertOk(); // reentrega idéntica

        $this->assertDatabaseCount('marketing_message_attachments', 1);
    }
}
