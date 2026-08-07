<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Historial paginado por cursor.
 *
 * Lo que se prueba no es que devuelva una lista, sino las tres cosas que hacen
 * que una paginación sirva para un chat:
 *
 *  · que **no repita ni se salte** mensajes al recorrer todo hacia atrás;
 *  · que aguante mensajes con la **misma marca de tiempo**, que WhatsApp
 *    entrega en lotes y son el caso donde una paginación mal hecha falla;
 *  · que un mensaje **nuevo** durante la lectura no desordene las páginas, que
 *    es exactamente lo que pasa con offset.
 */
class MessagePaginationTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'UTC'));

        $admin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-page@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($admin);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    /** Crea $n mensajes, uno por minuto. */
    private function seedMessages(int $n, ?Carbon $sameInstant = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $at = $sameInstant ?? now()->copy()->subMinutes($n - $i);

            MarketingMessage::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'inbound', 'sender_type' => 'lead',
                'body' => 'mensaje '.$i,
            ])->forceFill(['created_at' => $at])->save();
        }
    }

    private function page(?string $before = null, ?int $limit = null)
    {
        $q = array_filter(['before' => $before, 'limit' => $limit]);

        return $this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/messages?".http_build_query($q),
            $this->adminHeaders(),
        );
    }

    // ── Forma de la respuesta ───────────────────────────────────────────────

    public function test_the_page_reports_everything_the_client_needs(): void
    {
        $this->seedMessages(10);

        $this->page(limit: 4)->assertOk()
            ->assertJsonStructure([
                'items', 'next_cursor', 'has_more', 'oldest_id', 'newest_id', 'server_time',
            ]);
    }

    public function test_messages_arrive_in_chronological_order(): void
    {
        $this->seedMessages(5);

        $bodies = array_column($this->page()->json('items'), 'body');

        $this->assertSame('mensaje 0', $bodies[0]);
        $this->assertSame('mensaje 4', $bodies[4]);
    }

    /** Al abrir se trae lo ÚLTIMO, que es lo que se quiere leer. */
    public function test_the_first_page_holds_the_newest_messages(): void
    {
        $this->seedMessages(50);

        $bodies = array_column($this->page(limit: 5)->json('items'), 'body');

        $this->assertSame('mensaje 49', end($bodies));
        $this->assertTrue($this->page(limit: 5)->json('has_more'));
    }

    // ── Recorrido completo ──────────────────────────────────────────────────

    /**
     * La prueba que de verdad importa: recorrer todo hacia atrás tiene que
     * devolver cada mensaje **exactamente una vez**.
     */
    public function test_walking_back_returns_every_message_exactly_once(): void
    {
        $this->seedMessages(47);

        $seen = [];
        $cursor = null;

        do {
            $response = $this->page($cursor, limit: 10)->assertOk();
            foreach ($response->json('items') as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null);

        $this->assertCount(47, $seen, 'Se perdieron o repitieron mensajes al paginar.');
        $this->assertSame(47, count(array_unique($seen)), 'Hay identificadores duplicados.');
    }

    /**
     * El caso donde falla una paginación mal hecha: WhatsApp entrega lotes y
     * varios mensajes comparten marca de tiempo. Sin desempate por
     * identificador, se repiten o desaparecen.
     */
    public function test_messages_sharing_a_timestamp_are_not_duplicated(): void
    {
        $this->seedMessages(25, sameInstant: now()->copy()->subHour());

        $seen = [];
        $cursor = null;

        do {
            $response = $this->page($cursor, limit: 7);
            foreach ($response->json('items') as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null);

        $this->assertSame(25, count(array_unique($seen)));
        $this->assertCount(25, $seen);
    }

    /**
     * Un mensaje nuevo mientras se lee hacia atrás no desordena nada. Con
     * offset, cada mensaje nuevo desplaza todas las páginas.
     */
    public function test_a_new_message_while_reading_back_does_not_shift_pages(): void
    {
        $this->seedMessages(20);

        $first = $this->page(limit: 5);
        $cursor = $first->json('next_cursor');
        $firstIds = array_column($first->json('items'), 'id');

        // Llega uno nuevo justo ahora.
        MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound', 'sender_type' => 'lead', 'body' => 'recien llegado',
        ]);

        $second = $this->page($cursor, limit: 5);
        $secondIds = array_column($second->json('items'), 'id');

        $this->assertEmpty(
            array_intersect($firstIds, $secondIds),
            'El mensaje nuevo desplazó la paginación y se repitieron mensajes.',
        );
    }

    // ── Casos límite ────────────────────────────────────────────────────────

    public function test_the_last_page_says_there_is_no_more(): void
    {
        $this->seedMessages(3);

        $response = $this->page(limit: 10);

        $this->assertFalse($response->json('has_more'));
        $this->assertNull($response->json('next_cursor'));
    }

    public function test_an_empty_conversation_does_not_break(): void
    {
        $response = $this->page()->assertOk();

        $this->assertSame([], $response->json('items'));
        $this->assertNull($response->json('oldest_id'));
    }

    /** Un cursor corrupto sirve la primera página en vez de reventar. */
    public function test_a_broken_cursor_serves_the_first_page(): void
    {
        $this->seedMessages(5);

        $response = $this->page('esto-no-es-un-cursor')->assertOk();

        $this->assertCount(5, $response->json('items'));
    }

    // ── El detalle ya no carga la conversación entera ───────────────────────

    /**
     * La razón de existir de todo esto: abrir una conversación de miles de
     * mensajes no puede traerlos todos.
     */
    public function test_opening_a_conversation_no_longer_loads_everything(): void
    {
        config()->set('marketing.inbox.message_page_size', 40);
        $this->seedMessages(300);

        $detail = $this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}",
            $this->adminHeaders(),
        )->assertOk();

        $this->assertCount(40, $detail->json('data.messages'));
        $this->assertTrue($detail->json('data.messages_page.has_more'));
    }

    public function test_the_detail_still_shows_the_newest_message(): void
    {
        $this->seedMessages(120);

        $messages = $this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}",
            $this->adminHeaders(),
        )->json('data.messages');

        $this->assertSame('mensaje 119', end($messages)['body']);
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_an_unknown_conversation_is_a_404(): void
    {
        $this->getJson(
            '/api/admin/marketing/inbox/conversations/999999/messages',
            $this->adminHeaders(),
        )->assertNotFound();
    }
}
