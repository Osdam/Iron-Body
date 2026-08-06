<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageStatus;
use App\Services\Meta\MetaConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Meta no garantiza el orden de los callbacks de entrega.
 *
 * Es normal recibir 'read' antes que 'delivered', o un 'sent' rezagado minutos
 * después de que el mensaje ya se leyó. Con la actualización ciega anterior
 * (un UPDATE con el último que llegara), un mensaje leído podía volver a
 * figurar como "enviado" y quien atiende no entendía nada.
 *
 * La regla: el estado solo AVANZA. Lo que llega tarde se guarda como evidencia
 * pero no retrocede nada.
 */
class MessageStatusReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    private MarketingMessage $message;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        Http::fake();

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573150536026',
            'name' => 'Prospecto', 'phone' => '573150536026', 'status' => 'new',
        ]);
        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $this->message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => 'outbound',
            'sender_type' => 'ai', 'body' => 'Hola, con gusto te cuento.',
            'meta_message_id' => 'wamid.OUT1', 'status' => 'sent',
        ]);
    }

    private function service(): MetaConversationService
    {
        return app(MetaConversationService::class);
    }

    private function postStatus(string $status, array $extra = []): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [array_merge([
                'id' => 'wamid.OUT1', 'status' => $status,
                'recipient_id' => '573150536026', 'timestamp' => '1700000010',
            ], $extra)],
        ]]]]]];

        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_the_normal_progression_advances_the_message(): void
    {
        $this->assertTrue($this->service()->recordStatus('wamid.OUT1', 'delivered'));
        $this->assertSame('delivered', $this->message->fresh()->status);

        $this->assertTrue($this->service()->recordStatus('wamid.OUT1', 'read'));
        $this->assertSame('read', $this->message->fresh()->status);
    }

    /** El caso que rompía antes: un 'sent' rezagado tras un 'read'. */
    public function test_a_late_callback_never_walks_the_status_backwards(): void
    {
        $this->service()->recordStatus('wamid.OUT1', 'read');

        $this->assertFalse($this->service()->recordStatus('wamid.OUT1', 'sent'));
        $this->assertFalse($this->service()->recordStatus('wamid.OUT1', 'delivered'));

        $this->assertSame('read', $this->message->fresh()->status);
    }

    /** Lo descartado no se pierde: queda en el historial marcado como no aplicado. */
    public function test_the_discarded_callback_is_still_recorded_as_evidence(): void
    {
        $this->service()->recordStatus('wamid.OUT1', 'read');
        $this->service()->recordStatus('wamid.OUT1', 'delivered');

        $history = MarketingMessageStatus::where('message_id', $this->message->id)->get();
        $this->assertCount(2, $history);

        $late = $history->firstWhere('status', 'delivered');
        $this->assertFalse($late->applied);
        $this->assertTrue($history->firstWhere('status', 'read')->applied);
    }

    /**
     * "failed" sin más no le dice nada a quien atiende. El código de Meta es
     * lo que convierte el fallo en algo accionable.
     */
    public function test_a_failure_keeps_metas_error_code_where_the_inbox_can_read_it(): void
    {
        $this->postStatus('failed', ['errors' => [[
            'code' => 131047,
            'title' => 'Re-engagement message',
            'message' => 'More than 24 hours have passed since the recipient last replied',
        ]]])->assertOk();

        $message = $this->message->fresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame(131047, data_get($message->metadata, 'failure.code'));
        $this->assertSame('Re-engagement message', data_get($message->metadata, 'failure.title'));

        $this->assertDatabaseHas('marketing_message_statuses', [
            'message_id' => $message->id,
            'status' => 'failed',
            'error_code' => 131047,
        ]);
    }

    /** Un mensaje ya leído no puede "fallar" después: eso sería una regresión. */
    public function test_a_failure_cannot_undo_a_delivered_message(): void
    {
        $this->service()->recordStatus('wamid.OUT1', 'read');

        $this->assertFalse($this->service()->recordStatus('wamid.OUT1', 'failed', ['code' => 131047]));
        $this->assertSame('read', $this->message->fresh()->status);
    }

    /** Un callback repetido no cambia nada ni ensucia el estado. */
    public function test_the_same_callback_twice_is_harmless(): void
    {
        $this->service()->recordStatus('wamid.OUT1', 'delivered');
        $this->assertFalse($this->service()->recordStatus('wamid.OUT1', 'delivered'));

        $this->assertSame('delivered', $this->message->fresh()->status);
        $this->assertSame(2, MarketingMessageStatus::where('message_id', $this->message->id)->count());
    }

    /** Un callback de un mensaje que no es nuestro no rompe el webhook. */
    public function test_a_callback_for_an_unknown_message_is_ignored_quietly(): void
    {
        $this->assertFalse($this->service()->recordStatus('wamid.NO-ES-NUESTRO', 'delivered'));

        $this->assertDatabaseCount('marketing_message_statuses', 0);
    }

    /** Un estado que Meta invente mañana no puede desplazar a uno conocido. */
    public function test_an_unknown_status_never_outranks_a_known_one(): void
    {
        $this->service()->recordStatus('wamid.OUT1', 'delivered');

        $this->assertFalse($this->service()->recordStatus('wamid.OUT1', 'teletransportado'));
        $this->assertSame('delivered', $this->message->fresh()->status);
    }

    /** El momento que reporta Meta se conserva aparte de cuándo lo recibimos. */
    public function test_metas_own_timestamp_is_preserved(): void
    {
        $this->postStatus('delivered')->assertOk();

        $record = MarketingMessageStatus::where('message_id', $this->message->id)->sole();
        $this->assertSame(1700000010, $record->occurred_at?->timestamp);
    }

    public function test_a_status_arriving_through_the_webhook_updates_the_message(): void
    {
        $this->postStatus('read')->assertOk();

        $this->assertSame('read', $this->message->fresh()->status);
    }
}
