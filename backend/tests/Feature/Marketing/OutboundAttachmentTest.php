<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Marketing\OutboundAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Archivos que SALEN del CRM hacia el cliente.
 *
 * Lo que se prueba aquí no es que se pueda subir una foto, sino las cuatro
 * cosas que separan esto de un formulario de subida cualquiera:
 *
 *  · el tipo se decide por los BYTES, así que un ejecutable renombrado a .jpg
 *    no sale del gimnasio;
 *  · un borrador es de quien lo subió, así que un id al azar no adjunta el
 *    archivo de otro asesor a la conversación propia;
 *  · un borrador se reclama UNA vez, así que dos envíos simultáneos no le
 *    mandan la misma foto dos veces al cliente;
 *  · con META apagado no sale nada a la red, que es la condición bajo la que
 *    todo esto se está construyendo.
 */
class OutboundAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private Admin $admin;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('marketing.media.disk', 'whatsapp');
        Storage::fake('whatsapp');
        Http::preventStrayRequests();
        Http::fake();

        $this->admin = Admin::create([
            'name' => 'Asesor QA', 'email' => 'outbound@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($this->admin);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    /**
     * Un JPEG de verdad. No vale `UploadedFile::fake()->image()`: eso genera un
     * fichero con extensión .jpg y bytes que no son un JPEG, y lo que se está
     * probando es justamente que aquí se miran los bytes.
     *
     * El relleno va DESPUÉS del marcador de fin: el tipo se sigue detectando
     * por la cabecera, así que sirve para probar los límites de tamaño.
     */
    private function jpeg(int $padBytes = 0): UploadedFile
    {
        $binary = (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
            .'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAAB'
            .'AAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
        );

        if ($padBytes > 0) {
            $binary .= str_repeat("\x00", $padBytes);
        }

        return UploadedFile::fake()->createWithContent('foto.jpg', $binary);
    }

    private function upload(?UploadedFile $file = null, array $extra = [])
    {
        return $this->post(
            '/api/admin/marketing/inbox/attachments',
            array_merge(['file' => $file ?? $this->jpeg()], $extra),
            $this->adminHeaders(['Accept' => 'application/json']),
        );
    }

    private function send(array $payload)
    {
        return $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/messages",
            $payload,
            $this->adminHeaders(),
        );
    }

    // ── Subida ──────────────────────────────────────────────────────────────

    public function test_an_image_becomes_a_draft_attachment(): void
    {
        $response = $this->upload()->assertStatus(201);

        $this->assertSame('image', $response->json('data.kind'));
        $this->assertSame('image/jpeg', $response->json('data.mime_type'));

        $draft = MarketingMessageAttachment::find($response->json('data.id'));

        $this->assertNull($draft->message_id, 'Un archivo recién subido no debe tener mensaje todavía.');
        $this->assertSame('outbound', $draft->direction);
        $this->assertSame($this->admin->id, $draft->uploaded_by_admin_id);
        Storage::disk('whatsapp')->assertExists($draft->path);
    }

    /**
     * El caso que justifica detectar el tipo por los bytes: un ejecutable con
     * nombre y MIME de foto. Confiar en lo que declara el navegador es confiar
     * en el navegador de quien sube.
     */
    public function test_an_executable_disguised_as_an_image_is_refused(): void
    {
        $disguised = UploadedFile::fake()->createWithContent(
            'foto.jpg',
            "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 200),
        );

        $this->upload($disguised)
            ->assertStatus(422)
            ->assertJsonPath('code', 'disallowed_type');

        $this->assertSame(0, MarketingMessageAttachment::query()->count());
    }

    /** El nombre no participa en la ruta: `../../.env` no escapa del disco. */
    public function test_the_stored_path_never_contains_the_users_filename(): void
    {
        $file = UploadedFile::fake()->createWithContent('../../.env', $this->jpeg()->get());

        $id = $this->upload($file)->assertStatus(201)->json('data.id');
        $draft = MarketingMessageAttachment::find($id);

        $this->assertStringNotContainsString('..', $draft->path);
        $this->assertStringStartsWith('outbound/image/', $draft->path);
    }

    /**
     * Un GIF es una imagen para cualquiera menos para Cloud API, que solo
     * admite JPEG y PNG. Va como documento: llega igual y no es un 400.
     */
    public function test_a_gif_travels_as_a_document_because_meta_refuses_it_as_an_image(): void
    {
        $gif = UploadedFile::fake()->createWithContent(
            'animado.gif',
            base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
        );

        $this->upload($gif)->assertStatus(201)->assertJsonPath('data.kind', 'document');
    }

    public function test_a_file_over_the_meta_limit_is_refused_before_touching_disk(): void
    {
        config()->set('marketing.media.outbound.max_size_bytes.image', 1024);

        $this->upload($this->jpeg(padBytes: 4096))
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_large');

        $this->assertSame(0, MarketingMessageAttachment::query()->count());
    }

    public function test_uploads_can_be_switched_off_without_breaking_text(): void
    {
        config()->set('marketing.media.outbound.enabled', false);

        $this->upload()->assertStatus(422)->assertJsonPath('code', 'outbound_media_disabled');

        $this->send(['body' => 'texto de siempre'])->assertOk();
    }

    // ── Envío ───────────────────────────────────────────────────────────────

    public function test_sending_an_attachment_binds_it_to_a_real_message(): void
    {
        $id = $this->upload()->json('data.id');

        $this->send(['body' => 'Mira el plan', 'attachment_ids' => [$id]])
            ->assertOk()
            ->assertJsonPath('attachments_sent', 1);

        $draft = MarketingMessageAttachment::find($id);

        $this->assertNotNull($draft->message_id, 'El archivo se quedó suelto tras enviarlo.');
        $this->assertSame('Mira el plan', MarketingMessage::find($draft->message_id)->body);
    }

    /**
     * WhatsApp entrega un archivo por mensaje. Tres fotos son tres mensajes, y
     * el inbox tiene que enseñar eso mismo: si agrupara, el asesor vería una
     * cosa y el cliente otra.
     */
    public function test_three_files_become_three_messages(): void
    {
        $ids = [
            $this->upload()->json('data.id'),
            $this->upload()->json('data.id'),
            $this->upload()->json('data.id'),
        ];

        $response = $this->send(['body' => 'Los tres planes', 'attachment_ids' => $ids])->assertOk();

        $this->assertCount(3, $response->json('message_ids'));
        $this->assertSame(3, MarketingMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->count());
    }

    /** El pie va en el primero: repetirlo en cada foto sería spam. */
    public function test_the_caption_travels_only_with_the_first_file(): void
    {
        $ids = [$this->upload()->json('data.id'), $this->upload()->json('data.id')];

        $this->send(['body' => 'Promoción de agosto', 'attachment_ids' => $ids]);

        $bodies = MarketingMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->orderBy('id')->pluck('body')->all();

        $this->assertSame('Promoción de agosto', $bodies[0]);
        $this->assertSame('', $bodies[1]);
    }

    /**
     * Audio y sticker no admiten pie en Cloud API. El texto NO se pierde: sale
     * como mensaje propio y antes, para que el cliente entienda qué va a oír.
     */
    public function test_text_sent_with_a_voice_note_becomes_its_own_message(): void
    {
        $draft = MarketingMessageAttachment::create([
            'message_id' => null, 'direction' => 'outbound', 'kind' => 'audio',
            'uploaded_by_admin_id' => $this->admin->id, 'voice' => true,
            'detected_mime_type' => 'audio/ogg', 'disk' => 'whatsapp',
            'path' => 'outbound/audio/x.ogg', 'size_bytes' => 10,
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        $this->send(['body' => 'Te lo explico aquí', 'attachment_ids' => [$draft->id]])->assertOk();

        $messages = MarketingMessage::query()
            ->where('conversation_id', $this->conversation->id)->orderBy('id')->get();

        $this->assertCount(2, $messages, 'El texto que acompaña a un audio se perdió.');
        $this->assertSame('Te lo explico aquí', $messages[0]->body);
        $this->assertSame($messages[1]->id, $draft->fresh()->message_id);
    }

    public function test_a_message_with_a_file_does_not_need_text(): void
    {
        $id = $this->upload()->json('data.id');

        $this->send(['attachment_ids' => [$id]])->assertOk();
    }

    public function test_an_empty_message_without_files_is_still_refused(): void
    {
        $this->send([])->assertStatus(422);
    }

    // ── Propiedad e idempotencia ────────────────────────────────────────────

    /**
     * El caso que impide filtrar archivos entre conversaciones: adjuntar por id
     * el borrador de otro asesor.
     */
    public function test_a_draft_uploaded_by_someone_else_cannot_be_attached(): void
    {
        $other = MarketingMessageAttachment::create([
            'message_id' => null, 'direction' => 'outbound', 'kind' => 'image',
            'uploaded_by_admin_id' => $this->admin->id + 999,
            'detected_mime_type' => 'image/jpeg', 'disk' => 'whatsapp',
            'path' => 'outbound/image/ajeno.jpg', 'size_bytes' => 10,
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        $this->send(['body' => 'hola', 'attachment_ids' => [$other->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'attachment_unavailable');

        $this->assertNull($other->fresh()->message_id);
    }

    /** Y tampoco se puede mirar: probar ids consecutivos no enseña nada ajeno. */
    public function test_a_draft_of_someone_else_cannot_even_be_previewed(): void
    {
        $other = MarketingMessageAttachment::create([
            'message_id' => null, 'direction' => 'outbound', 'kind' => 'image',
            'uploaded_by_admin_id' => $this->admin->id + 999,
            'detected_mime_type' => 'image/jpeg', 'disk' => 'whatsapp',
            'path' => 'outbound/image/ajeno.jpg', 'size_bytes' => 10,
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);

        $this->getJson(
            "/api/admin/marketing/inbox/attachments/{$other->id}/link",
            $this->adminHeaders(),
        )->assertNotFound();
    }

    /**
     * Un borrador se reclama una sola vez. Si no, dos pulsaciones seguidas de
     * enviar le mandarían la misma foto dos veces al cliente.
     */
    public function test_the_same_draft_cannot_be_sent_twice(): void
    {
        $id = $this->upload()->json('data.id');

        $this->send(['body' => 'primera', 'attachment_ids' => [$id]])->assertOk();

        $this->send(['body' => 'segunda', 'attachment_ids' => [$id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'attachment_unavailable');
    }

    public function test_more_files_than_allowed_are_refused(): void
    {
        config()->set('marketing.media.outbound.max_per_send', 2);

        $ids = [
            $this->upload()->json('data.id'),
            $this->upload()->json('data.id'),
            $this->upload()->json('data.id'),
        ];

        $this->send(['body' => 'demasiados', 'attachment_ids' => $ids])->assertStatus(422);
    }

    // ── Citas ───────────────────────────────────────────────────────────────

    /** Citar un mensaje de OTRA conversación ante el cliente no puede pasar. */
    public function test_a_message_from_another_conversation_cannot_be_quoted(): void
    {
        $otherLead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3009998877',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $otherConversation = MarketingConversation::create([
            'lead_id' => $otherLead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $foreign = MarketingMessage::create([
            'conversation_id' => $otherConversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'body' => 'secreto', 'meta_message_id' => 'wamid.AJENO',
        ]);

        $this->send(['body' => 'respondo', 'reply_to_message_id' => $foreign->id])->assertOk();

        $sent = MarketingMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->first();

        $this->assertNull(data_get($sent->metadata, 'reply_to_meta_message_id'));
    }

    public function test_quoting_a_message_of_this_conversation_works(): void
    {
        $inbound = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'body' => '¿cuánto cuesta?', 'meta_message_id' => 'wamid.PROPIO',
        ]);

        $this->send(['body' => 'Son 90.000', 'reply_to_message_id' => $inbound->id])->assertOk();

        $sent = MarketingMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->first();

        $this->assertSame('wamid.PROPIO', data_get($sent->metadata, 'reply_to_meta_message_id'));
    }

    // ── La barrera de META ──────────────────────────────────────────────────

    /**
     * Con META apagado no puede salir un solo byte a la red. Es la condición
     * bajo la que se está construyendo todo esto.
     */
    public function test_with_meta_off_nothing_reaches_the_network(): void
    {
        $id = $this->upload()->json('data.id');

        $this->send(['body' => 'Mira', 'attachment_ids' => [$id]])
            ->assertOk()
            ->assertJsonPath('dry_run', true);

        Http::assertNothingSent();
    }

    // ── Limpieza ────────────────────────────────────────────────────────────

    public function test_abandoned_drafts_are_swept_and_sent_ones_are_not(): void
    {
        $abandoned = MarketingMessageAttachment::find($this->upload()->json('data.id'));
        $abandoned->forceFill(['created_at' => now()->subDays(3)])->save();

        $sentId = $this->upload()->json('data.id');
        $this->send(['body' => 'va', 'attachment_ids' => [$sentId]]);
        MarketingMessageAttachment::where('id', $sentId)
            ->update(['created_at' => now()->subDays(3)]);

        $removed = app(OutboundAttachmentService::class)->pruneDrafts();

        $this->assertSame(1, $removed);
        $this->assertNull(MarketingMessageAttachment::find($abandoned->id));
        $this->assertNotNull(MarketingMessageAttachment::find($sentId), 'Se borró un archivo ya enviado.');
        Storage::disk('whatsapp')->assertMissing($abandoned->path);
    }

    public function test_a_recent_draft_survives_the_sweep(): void
    {
        $id = $this->upload()->json('data.id');

        app(OutboundAttachmentService::class)->pruneDrafts();

        $this->assertNotNull(MarketingMessageAttachment::find($id));
    }

    // ── Capacidades ─────────────────────────────────────────────────────────

    public function test_the_inbox_is_told_whether_voice_notes_are_possible(): void
    {
        $capabilities = $this->getJson(
            '/api/admin/marketing/inbox/capabilities',
            $this->adminHeaders(),
        )->assertOk()->json('data.attachments');

        $this->assertTrue($capabilities['enabled']);
        $this->assertIsBool($capabilities['voice_notes']);
        $this->assertSame(5, $capabilities['max_per_send']);
    }

    /** Si el servidor no puede convertir el audio, el micrófono no se ofrece. */
    public function test_voice_notes_are_not_offered_when_they_cannot_work(): void
    {
        config()->set('marketing.media.outbound.voice.enabled', false);

        $this->assertFalse(app(OutboundAttachmentService::class)->voiceNotesAvailable());
    }
}
