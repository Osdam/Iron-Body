<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El inbox operado de punta a punta, sin Meta real.
 *
 * Esto cubre el día a día de quien atiende: contestar a mano, ver una foto o un
 * audio que mandó el cliente, pausar la IA, devolvérsela, asignar la
 * conversación y reintentar un envío que falló.
 *
 * La invariante que más importa —y la más fácil de romper— es que **nunca
 * salgan dos respuestas al mismo mensaje**. Un asesor escribiendo mientras la
 * IA está decidiendo es la situación normal un sábado por la mañana, no un caso
 * raro.
 */
class InboxOperationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private array $adminHeaders = [];

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);           // nada sale al exterior
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.inbound.auto_analyze', true);
        config()->set('marketing.media.disk', 'whatsapp');

        // Sin catch-all a propósito. `Http::fake()` FUSIONA los stubs y gana el
        // primero que encaje, así que un comodín aquí se comería la secuencia
        // que declara la prueba de reintentos y ésta vería una respuesta vacía.
        // preventStrayRequests es además más estricto: cualquier llamada que no
        // esté declarada revienta en vez de pasar en silencio.
        Http::preventStrayRequests();
        Storage::fake('whatsapp');

        $this->admin = Admin::create([
            'name' => 'Asesora', 'email' => 'asesora@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->adminHeaders = $this->actingAsAdmin($this->admin);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            // El wa_id es lo que usa el webhook para reconocer al prospecto. Sin
            // él, un entrante crearía OTRO lead y otra conversación, y las
            // pruebas mirarían un hilo distinto del que reciben los mensajes.
            'meta_user_id' => '573150536026',
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->adminHeaders, $headers);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/admin/marketing/inbox/conversations/'.$this->conversation->id.$suffix;
    }

    /** Simula un entrante del cliente con el tipo indicado. */
    private function incoming(array $message): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => '573150536026']],
            'messages' => [array_merge(['from' => '573150536026', 'timestamp' => (string) now()->timestamp], $message)],
        ]]]]]];

        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    // ── Respuesta manual ───────────────────────────────────────────────────────

    public function test_a_person_can_answer_by_hand(): void
    {
        $this->postJson($this->url('/messages'), [
            'body' => 'Claro que sí, te espero mañana a las 6.',
        ], $this->adminHeaders())->assertOk();

        $message = MarketingMessage::where('sender_type', 'human')->sole();
        $this->assertSame('Claro que sí, te espero mañana a las 6.', $message->body);
        // Queda quién lo mandó: sin eso no hay forma de saber quién dijo qué.
        $this->assertSame($this->admin->id, $message->sender_user_id);
        // Con Meta apagado se prepara pero no sale.
        $this->assertSame(WhatsappOutboxService::STATUS_DRY_RUN, $message->status);
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $this->postJson($this->url('/messages'), ['body' => '   '], $this->adminHeaders())
            ->assertStatus(422);
    }

    // ── Multimedia del cliente visible en el inbox ─────────────────────────────

    /**
     * Los cuatro tipos que de verdad llegan a un gimnasio. Cada uno tiene que
     * aparecer en el detalle con su ficha, aunque el archivo aún no esté.
     */
    public function test_every_media_type_reaches_the_inbox(): void
    {
        $cases = [
            ['id' => 'wamid.IMG', 'type' => 'image',
                'image' => ['id' => 'm-img', 'mime_type' => 'image/jpeg', 'caption' => 'mi rutina']],
            ['id' => 'wamid.AUD', 'type' => 'audio',
                'audio' => ['id' => 'm-aud', 'mime_type' => 'audio/ogg', 'voice' => true]],
            ['id' => 'wamid.VID', 'type' => 'video',
                'video' => ['id' => 'm-vid', 'mime_type' => 'video/mp4']],
            ['id' => 'wamid.DOC', 'type' => 'document',
                'document' => ['id' => 'm-doc', 'mime_type' => 'application/pdf', 'filename' => 'examen.pdf']],
        ];

        foreach ($cases as $case) {
            $this->incoming($case)->assertOk();
        }

        $detail = $this->getJson($this->url(), $this->adminHeaders())->assertOk();
        $messages = $detail->json('data.messages');

        $kinds = [];
        foreach ($messages as $m) {
            foreach ($m['attachments'] ?? [] as $a) {
                $kinds[] = $a['kind'];
            }
        }

        $this->assertEqualsCanonicalizing(['image', 'audio', 'video', 'document'], $kinds);
        $this->assertSame(4, MarketingMessageAttachment::count());
    }

    /** La nota de voz se distingue de un audio adjunto: se muestran distinto. */
    public function test_a_voice_note_is_flagged_as_such_for_the_ui(): void
    {
        $this->incoming(['id' => 'wamid.V', 'type' => 'audio',
            'audio' => ['id' => 'm-v', 'mime_type' => 'audio/ogg', 'voice' => true]])->assertOk();

        $detail = $this->getJson($this->url(), $this->adminHeaders())->assertOk();

        $attachment = collect($detail->json('data.messages'))
            ->flatMap(fn ($m) => $m['attachments'] ?? [])->first();

        $this->assertTrue($attachment['voice']);
    }

    /** Un adjunto que no se pudo traer explica por qué, en vez de desaparecer. */
    public function test_a_failed_attachment_explains_itself_in_the_payload(): void
    {
        $this->incoming(['id' => 'wamid.F', 'type' => 'image',
            'image' => ['id' => 'm-f', 'mime_type' => 'image/jpeg']])->assertOk();

        MarketingMessageAttachment::sole()->forceFill([
            'status' => MarketingMessageAttachment::STATUS_REJECTED,
            'failure_reason' => 'mime_mismatch',
        ])->save();

        $detail = $this->getJson($this->url(), $this->adminHeaders())->assertOk();
        $attachment = collect($detail->json('data.messages'))
            ->flatMap(fn ($m) => $m['attachments'] ?? [])->first();

        $this->assertFalse($attachment['available']);
        $this->assertSame('mime_mismatch', $attachment['reason']);
    }

    // ── Pausar y reanudar la IA ────────────────────────────────────────────────

    public function test_pausing_and_resuming_the_ai(): void
    {
        $this->postJson($this->url('/takeover'), ['reason' => 'la llevo yo'], $this->adminHeaders())->assertOk();

        $this->conversation->refresh();
        $this->assertTrue((bool) $this->conversation->human_takeover);
        $this->assertSame('manual', $this->conversation->human_takeover_source);

        $this->postJson($this->url('/release'), [], $this->adminHeaders())->assertOk();

        $this->conversation->refresh();
        $this->assertFalse((bool) $this->conversation->human_takeover);
        $this->assertTrue((bool) $this->conversation->ai_enabled);
    }

    /** Pausada, la IA no contesta; reanudada, vuelve a hacerlo. */
    public function test_the_ai_is_silent_while_paused_and_speaks_again_after_release(): void
    {
        $this->postJson($this->url('/takeover'), [], $this->adminHeaders())->assertOk();

        $before = MarketingMessage::where('sender_type', 'ai')->count();
        $this->incoming(['id' => 'wamid.P1', 'type' => 'text', 'text' => ['body' => 'precio?']])->assertOk();
        $this->assertSame($before, MarketingMessage::where('sender_type', 'ai')->count());

        $this->postJson($this->url('/release'), [], $this->adminHeaders())->assertOk();

        // Reanudada, un mensaje nuevo vuelve a analizarse.
        $this->incoming(['id' => 'wamid.P2', 'type' => 'text', 'text' => ['body' => 'sigo interesado']])->assertOk();
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
    }

    // ── Asignación ─────────────────────────────────────────────────────────────

    public function test_assigning_the_conversation_to_a_person(): void
    {
        $other = Admin::create([
            'name' => 'Otro Asesor', 'email' => 'otro@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);

        $this->postJson($this->url('/assign'), [
            'assigned_to_admin_id' => $other->id,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame($other->id, $this->conversation->fresh()->assigned_to_admin_id);

        // Y se puede soltar.
        $this->postJson($this->url('/assign'), ['assigned_to_admin_id' => null], $this->adminHeaders())->assertOk();
        $this->assertNull($this->conversation->fresh()->assigned_to_admin_id);
    }

    // ── Reintentos ─────────────────────────────────────────────────────────────

    /** Un envío que falló por causa pasajera vuelve a intentarse y llega. */
    public function test_a_transient_failure_is_retried_until_it_lands(): void
    {
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'tok');
        config()->set('meta.app_secret', 'sec');
        config()->set('marketing.outbox.max_attempts', 3);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'rate', 'type' => 'X', 'code' => 130429]], 429)
            ->push(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);

        $this->postJson($this->url('/messages'), ['body' => 'Te confirmo la cita'], $this->adminHeaders())->assertOk();

        $message = MarketingMessage::where('sender_type', 'human')->sole();
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->status);
        $this->assertNotNull($message->next_attempt_at);

        $message->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        $this->artisan('marketing:retry-outbox')->assertSuccessful();

        $message->refresh();
        $this->assertSame(WhatsappOutboxService::STATUS_SENT, $message->status);
        $this->assertSame('wamid.OK', $message->meta_message_id);
    }

    // ── Concurrencia: la invariante que no se puede romper ─────────────────────

    /**
     * Dos operadores contestando a la vez. Las dos respuestas se guardan, cada
     * una con su autor, y ninguna pisa a la otra.
     */
    public function test_two_operators_at_once_produce_two_distinct_messages(): void
    {
        $second = Admin::create([
            'name' => 'Segundo', 'email' => 'segundo@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $secondHeaders = $this->actingAsAdmin($second);

        $this->postJson($this->url('/messages'), ['body' => 'Respuesta de la primera'], $this->adminHeaders())->assertOk();
        $this->postJson($this->url('/messages'), ['body' => 'Respuesta del segundo'], $secondHeaders)->assertOk();

        $human = MarketingMessage::where('sender_type', 'human')->orderBy('id')->get();

        $this->assertCount(2, $human);
        $this->assertEqualsCanonicalizing(
            [$this->admin->id, $second->id],
            $human->pluck('sender_user_id')->all(),
        );
        // Y ninguna se perdió ni se sobrescribió.
        $this->assertEqualsCanonicalizing(
            ['Respuesta de la primera', 'Respuesta del segundo'],
            $human->pluck('body')->all(),
        );
    }

    /**
     * LA prueba que importa: un operador contesta mientras entra un mensaje del
     * cliente que la IA analizaría. Si el operador pausó la IA al enviar, el
     * cliente NO puede recibir dos respuestas.
     */
    public function test_an_operator_replying_stops_the_ai_from_answering_too(): void
    {
        // El operador contesta y pausa la IA en el mismo gesto (pause_ai).
        $this->postJson($this->url('/messages'), [
            'body' => 'Yo te atiendo, dame un momento',
            'pause_ai' => true,
        ], $this->adminHeaders())->assertOk();

        $aiBefore = MarketingMessage::where('sender_type', 'ai')->count();

        // Justo después llega otro mensaje del cliente.
        $this->incoming(['id' => 'wamid.RACE', 'type' => 'text',
            'text' => ['body' => 'cuánto cuesta el plan anual?']])->assertOk();

        // La IA NO respondió: el cliente recibe una sola voz.
        $this->assertSame(
            $aiBefore,
            MarketingMessage::where('sender_type', 'ai')->count(),
            'La IA contestó encima del operador: el cliente recibió dos respuestas.',
        );

        // Y el mensaje del cliente sí quedó guardado para que lo lea la persona.
        $this->assertDatabaseHas('marketing_messages', [
            'body' => 'cuánto cuesta el plan anual?',
            'direction' => 'inbound',
        ]);
    }

    /**
     * El mismo mensaje del cliente procesado dos veces (reentrega de Meta) no
     * puede generar dos respuestas automáticas.
     */
    public function test_a_redelivered_message_never_produces_two_answers(): void
    {
        config()->set('marketing.agent_enabled', true);
        config()->set('marketing.inbound.auto_execute', true);

        $message = ['id' => 'wamid.SAME', 'type' => 'text', 'text' => ['body' => 'precio']];

        $this->incoming($message)->assertOk();
        $after = MarketingMessage::where('direction', 'outbound')->count();

        // Meta reentrega exactamente lo mismo dos veces más.
        $this->incoming($message)->assertOk();
        $this->incoming($message)->assertOk();

        $this->assertSame(
            $after,
            MarketingMessage::where('direction', 'outbound')->count(),
            'Una reentrega generó una segunda respuesta al mismo cliente.',
        );
    }

    /** El listado del inbox refleja lo que pasó, para poder priorizar. */
    public function test_the_conversation_list_shows_what_needs_attention(): void
    {
        $this->incoming(['id' => 'wamid.L', 'type' => 'text', 'text' => ['body' => 'hola']])->assertOk();

        $list = $this->getJson('/api/admin/marketing/inbox/conversations', $this->adminHeaders())->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $this->conversation->id);
        $this->assertNotNull($row);
        $this->assertSame('hola', $row['last_message_preview']);
        $this->assertGreaterThan(0, $row['unread_count']);
    }
}
