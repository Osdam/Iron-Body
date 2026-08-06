<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Models\MetaWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Las fotos y notas de voz de los prospectos no viven en el servidor para
 * siempre. Al vencer el plazo se borra el ARCHIVO y la ficha se queda: el inbox
 * sigue pudiendo decir "aquí llegó una nota de voz el 3 de marzo" sin conservar
 * el contenido. Y como los archivos idénticos se deduplican, un binario
 * compartido solo desaparece cuando ya no lo usa nadie.
 */
class MediaRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('whatsapp');
        config()->set('marketing.media.disk', 'whatsapp');

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function storedAttachment(string $path, ?string $expiresAt): MarketingMessageAttachment
    {
        Storage::disk('whatsapp')->put($path, self::JPEG);

        $message = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'meta_message_id' => 'wamid.'.uniqid(),
        ]);

        return MarketingMessageAttachment::create([
            'message_id' => $message->id, 'direction' => 'inbound', 'kind' => 'image',
            'detected_mime_type' => 'image/jpeg', 'sha256' => hash('sha256', self::JPEG),
            'disk' => 'whatsapp', 'path' => $path,
            'status' => MarketingMessageAttachment::STATUS_STORED,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_an_expired_file_is_deleted_but_its_record_survives(): void
    {
        $attachment = $this->storedAttachment('image/2026/01/viejo.jpg', now()->subDay()->toDateTimeString());

        $this->artisan('marketing:prune-media')->assertSuccessful();

        Storage::disk('whatsapp')->assertMissing('image/2026/01/viejo.jpg');

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_EXPIRED, $attachment->status);
        $this->assertNull($attachment->path);
        // La ficha conserva de qué era y cuándo: el historial no se agujerea.
        $this->assertSame('image', $attachment->kind);
        $this->assertNotNull($attachment->created_at);
    }

    public function test_a_file_still_within_its_retention_is_left_alone(): void
    {
        $this->storedAttachment('image/2026/08/reciente.jpg', now()->addMonths(3)->toDateTimeString());

        $this->artisan('marketing:prune-media')->assertSuccessful();

        Storage::disk('whatsapp')->assertExists('image/2026/08/reciente.jpg');
    }

    /**
     * Deduplicación: dos fichas, un solo binario. Borrarlo al vencer la primera
     * dejaría rota la segunda, que aún está en plazo.
     */
    public function test_a_shared_binary_is_kept_while_someone_still_needs_it(): void
    {
        $path = 'image/2026/01/compartido.jpg';

        $old = $this->storedAttachment($path, now()->subDay()->toDateTimeString());
        // La segunda ficha apunta al MISMO archivo y todavía está en plazo.
        $fresh = $this->storedAttachment($path, now()->addYear()->toDateTimeString());

        $this->artisan('marketing:prune-media')->assertSuccessful();

        Storage::disk('whatsapp')->assertExists($path);
        $this->assertSame(MarketingMessageAttachment::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertSame(MarketingMessageAttachment::STATUS_STORED, $fresh->fresh()->status);
    }

    public function test_a_dry_run_touches_nothing(): void
    {
        $attachment = $this->storedAttachment('image/2026/01/simulacro.jpg', now()->subDay()->toDateTimeString());

        $this->artisan('marketing:prune-media --dry-run')->assertSuccessful();

        Storage::disk('whatsapp')->assertExists('image/2026/01/simulacro.jpg');
        $this->assertSame(MarketingMessageAttachment::STATUS_STORED, $attachment->fresh()->status);
    }

    /** Los eventos crudos guardan texto de prospectos: también caducan. */
    public function test_old_processed_raw_events_are_purged(): void
    {
        config()->set('observability.raw_events.retention_days', 30);

        $old = MetaWebhookEvent::create([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'viejo'), 'payload' => ['object' => 'x'],
            'status' => MetaWebhookEvent::STATUS_PROCESSED,
        ]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        $recent = MetaWebhookEvent::create([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'reciente'), 'payload' => ['object' => 'x'],
            'status' => MetaWebhookEvent::STATUS_PROCESSED,
        ]);

        $this->artisan('marketing:prune-media')->assertSuccessful();

        $this->assertNull(MetaWebhookEvent::find($old->id));
        $this->assertNotNull(MetaWebhookEvent::find($recent->id));
    }

    /**
     * Un evento que aún no se ha procesado NO se purga aunque sea viejo: es
     * precisamente el que hay que rescatar.
     */
    public function test_an_unprocessed_event_is_never_purged_however_old(): void
    {
        config()->set('observability.raw_events.retention_days', 1);

        $stuck = MetaWebhookEvent::create([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'atascado'), 'payload' => ['object' => 'x'],
            'status' => MetaWebhookEvent::STATUS_FAILED,
        ]);
        $stuck->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->artisan('marketing:prune-media')->assertSuccessful();

        $this->assertNotNull(MetaWebhookEvent::find($stuck->id));
    }
}
