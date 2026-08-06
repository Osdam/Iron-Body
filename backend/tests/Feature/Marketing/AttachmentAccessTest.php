<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Quién puede ver la foto que mandó un prospecto, y durante cuánto tiempo.
 *
 * El disco es privado y no tiene URL pública, así que la única vía es este par
 * de endpoints. El primero exige sesión de administrador con permiso de Inbox;
 * el segundo se apoya en una firma temporal, para que el navegador pueda pintar
 * un `<img>` sin cabeceras. Si esa URL sale del CRM, caduca sola.
 */
class AttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private array $saHeaders = [];

    private MarketingMessageAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('marketing.media.disk', 'whatsapp');
        config()->set('marketing.media.signed_url_minutes', 10);
        Http::fake();
        Storage::fake('whatsapp');

        $superAdmin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-qa@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($superAdmin);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp',
            'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'meta_message_id' => 'wamid.ATT',
        ]);

        Storage::disk('whatsapp')->put('image/2026/08/abc.jpg', self::JPEG);

        $this->attachment = MarketingMessageAttachment::create([
            'message_id' => $message->id, 'direction' => 'inbound', 'kind' => 'image',
            'media_id' => 'media-1', 'declared_mime_type' => 'image/jpeg',
            'detected_mime_type' => 'image/jpeg', 'sha256' => hash('sha256', self::JPEG),
            'size_bytes' => strlen(self::JPEG), 'disk' => 'whatsapp',
            'path' => 'image/2026/08/abc.jpg', 'original_filename' => 'foto.jpg',
            'status' => MarketingMessageAttachment::STATUS_STORED,
        ]);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    private function linkUrl(?int $id = null): string
    {
        return '/api/admin/marketing/inbox/attachments/'.($id ?? $this->attachment->id).'/link';
    }

    public function test_an_admin_gets_a_short_lived_signed_url(): void
    {
        $response = $this->getJson($this->linkUrl(), $this->adminHeaders())->assertOk();

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('mime_type', 'image/jpeg');
        $this->assertStringContainsString('signature=', (string) $response->json('url'));
        $this->assertStringContainsString('expires=', (string) $response->json('url'));
    }

    public function test_the_signed_url_actually_serves_the_file(): void
    {
        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::JPEG, $response->streamedContent());
    }

    /** Sin sesión no hay ni siquiera una URL que copiar. */
    public function test_without_an_admin_session_there_is_no_link(): void
    {
        $this->getJson($this->linkUrl())->assertStatus(401);
    }

    /** La firma es la autorización: sin ella, el binario no sale. */
    public function test_the_download_endpoint_refuses_an_unsigned_request(): void
    {
        $this->get('/api/marketing/attachments/'.$this->attachment->id.'/download')
            ->assertStatus(403);
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');

        // Se cambia el id del adjunto conservando la firma del original.
        $tampered = preg_replace('#/attachments/\d+/#', '/attachments/999/', $url) ?? '';

        $this->get($tampered)->assertStatus(403);
    }

    /** Copiar la URL y usarla mañana no debe funcionar. */
    public function test_the_url_stops_working_once_it_expires(): void
    {
        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');

        Carbon::setTestNow(now()->addMinutes(11));

        $this->get($url)->assertStatus(403);

        Carbon::setTestNow();
    }

    /**
     * Un adjunto que aún se está descargando no es un 404: el inbox necesita
     * poder explicar por qué todavía no hay archivo.
     */
    public function test_a_pending_attachment_explains_itself_instead_of_pretending_to_be_missing(): void
    {
        $this->attachment->forceFill([
            'status' => MarketingMessageAttachment::STATUS_PENDING,
            'path' => null,
        ])->save();

        $this->getJson($this->linkUrl(), $this->adminHeaders())
            ->assertStatus(409)
            ->assertJsonPath('code', 'attachment_not_available')
            ->assertJsonPath('status', 'pending');
    }

    public function test_a_rejected_attachment_reports_why(): void
    {
        $this->attachment->forceFill([
            'status' => MarketingMessageAttachment::STATUS_REJECTED,
            'failure_reason' => 'mime_mismatch',
            'path' => null,
        ])->save();

        $this->getJson($this->linkUrl(), $this->adminHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'mime_mismatch');
    }

    public function test_an_unknown_attachment_is_a_plain_404(): void
    {
        $this->getJson($this->linkUrl(999999), $this->adminHeaders())->assertStatus(404);
    }

    /**
     * El tipo que se le declara al navegador es el DETECTADO. Si algún día algo
     * se colara con un MIME peligroso, `nosniff` y la CSP impiden que se
     * ejecute en nuestro dominio.
     */
    public function test_documents_are_sent_as_downloads_not_rendered_inline(): void
    {
        $this->attachment->forceFill([
            'kind' => 'document',
            'detected_mime_type' => 'application/pdf',
            'original_filename' => 'plan.pdf',
        ])->save();

        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');

        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_images_are_shown_inline_so_the_inbox_can_paint_them(): void
    {
        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');

        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /** La ficha dice que está y el disco dice que no: eso es un incidente. */
    public function test_a_file_missing_from_disk_is_reported_not_streamed_as_empty(): void
    {
        $url = (string) $this->getJson($this->linkUrl(), $this->adminHeaders())->json('url');
        Storage::disk('whatsapp')->delete('image/2026/08/abc.jpg');

        $this->get($url)->assertStatus(404);
    }

    /** El inbox nunca expone la ruta ni el disco: solo el id y el estado. */
    public function test_the_conversation_payload_never_leaks_the_storage_path(): void
    {
        $conversationId = $this->attachment->message->conversation_id;

        $response = $this->getJson(
            '/api/admin/marketing/inbox/conversations/'.$conversationId,
            $this->adminHeaders(),
        )->assertOk();

        $attachment = $response->json('data.messages.0.attachments.0');

        $this->assertSame($this->attachment->id, $attachment['id']);
        $this->assertTrue($attachment['available']);
        $this->assertSame('image/jpeg', $attachment['mime_type']);
        $this->assertArrayNotHasKey('path', $attachment);
        $this->assertArrayNotHasKey('disk', $attachment);
        $this->assertStringNotContainsString('image/2026', json_encode($response->json()) ?: '');
    }
}
