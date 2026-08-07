<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingTag;
use App\Services\Marketing\MarketingInboxService;
use App\Services\Marketing\TagCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo que sostiene el rendimiento del Inbox, y lo que podría romper por ello.
 *
 * Optimizar aquí ha significado guardar datos por duplicado —la
 * previsualización del último mensaje vive ahora también en la conversación— y
 * recordar cosas dentro de la petición. Las dos son maneras conocidas de
 * enseñar información vieja, así que lo que se prueba no es que vaya rápido
 * (para eso está `marketing:inbox-bench`), sino que **lo que se ve sigue
 * siendo verdad**.
 *
 * Una bandeja rápida que enseña el mensaje de ayer como si fuera el último es
 * peor que una lenta: quien atiende decide a quién contestar mirando eso.
 */
class InboxPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();

        $admin = Admin::create([
            'name' => 'Perf QA', 'email' => 'perf@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($admin);

        $this->conversation = $this->makeConversation('3150536026', 'Ana Ramirez');
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    private function makeConversation(string $phone, string $name): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $phone,
            'name' => $name, 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now(),
        ]);
    }

    private function say(MarketingConversation $conversation, string $body): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound', 'sender_type' => 'lead', 'body' => $body,
        ]);
    }

    private function previewOf(MarketingConversation $conversation): ?string
    {
        return DB::table('marketing_conversations')
            ->where('id', $conversation->id)
            ->value('last_message_preview');
    }

    private function list(array $query = [])
    {
        return $this->getJson(
            '/api/admin/marketing/inbox/conversations?'.http_build_query($query),
            $this->adminHeaders(),
        );
    }

    // ── La previsualización no se queda vieja ───────────────────────────────

    public function test_a_new_message_updates_the_preview(): void
    {
        $this->say($this->conversation, 'Hola, quiero precios');

        $this->assertSame('Hola, quiero precios', $this->previewOf($this->conversation));
    }

    /**
     * El caso que justifica un observador y no una linea en el servicio de
     * envio: los mensajes nacen por varios caminos. Este entra por el
     * despachador, que es otro distinto del que escribe el webhook.
     */
    public function test_an_outbound_reply_also_updates_the_preview(): void
    {
        $this->say($this->conversation, 'Hola');

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/messages",
            ['body' => 'Buenas, con gusto te cuento'],
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame('Buenas, con gusto te cuento', $this->previewOf($this->conversation));
    }

    /**
     * Un mensaje con fecha ANTERIOR no puede pisar la vista previa.
     *
     * Hoy ningun camino de produccion inserta con fecha pasada -`created_at` no
     * es asignable en masa, asi que todos los mensajes nacen con la hora de
     * llegada-, y por eso mismo la proteccion tiene que estar probada: el dia
     * que alguien guarde la marca de tiempo que manda Meta en vez de la de
     * recepcion, la bandeja no puede empezar a enseniar mensajes viejos como si
     * fueran los ultimos.
     *
     * La fecha se pone ANTES de guardar, que es cuando se disparara el
     * observador; cambiarla despues probaria otra cosa.
     */
    public function test_a_backdated_message_does_not_overwrite_the_preview(): void
    {
        $this->say($this->conversation, 'el mas reciente');

        $old = new MarketingMessage;
        $old->forceFill([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound', 'sender_type' => 'lead',
            'body' => 'llegue tarde y soy viejo',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();

        $this->assertSame('el mas reciente', $this->previewOf($this->conversation));
    }

    public function test_deleting_the_last_message_recomputes_the_preview(): void
    {
        $this->say($this->conversation, 'primero');
        $last = $this->say($this->conversation, 'segundo');

        $last->delete();

        $this->assertSame('primero', $this->previewOf($this->conversation));
    }

    public function test_editing_the_last_message_updates_the_preview(): void
    {
        $message = $this->say($this->conversation, 'con errata');

        $message->forceFill(['body' => 'corregido'])->save();

        $this->assertSame('corregido', $this->previewOf($this->conversation));
    }

    /** Un mensaje larguisimo no puede reventar la columna ni la fila. */
    public function test_a_very_long_message_is_trimmed(): void
    {
        $this->say($this->conversation, str_repeat('a', 500));

        $this->assertSame(160, mb_strlen((string) $this->previewOf($this->conversation)));
    }

    /**
     * Lo que de verdad importa: lo que llega al navegador. La lista sirve para
     * decidir a quien atender, y decide mirando esta linea.
     */
    public function test_the_list_shows_the_newest_message_not_a_stale_one(): void
    {
        $this->say($this->conversation, 'mensaje viejo');
        $this->say($this->conversation, 'ULTIMO de verdad');

        $row = collect($this->list()->assertOk()->json('data'))
            ->firstWhere('id', $this->conversation->id);

        $this->assertSame('ULTIMO de verdad', $row['last_message_preview']);
    }

    public function test_a_conversation_without_messages_has_no_preview(): void
    {
        $row = collect($this->list()->assertOk()->json('data'))
            ->firstWhere('id', $this->conversation->id);

        $this->assertNull($row['last_message_preview']);
    }

    // ── El catálogo memorizado no sirve datos viejos ────────────────────────

    /**
     * El catalogo se lee una sola vez por peticion. Eso es correcto DENTRO de
     * una peticion y seria un fallo entre peticiones: crear una etiqueta y no
     * verla nunca mas.
     */
    public function test_a_new_tag_is_visible_on_the_next_request(): void
    {
        TagCatalog::sync();

        $this->say($this->conversation, 'hola');
        app(\App\Services\Marketing\MarketingConversationTagService::class)
            ->apply($this->conversation, ['recien-inventada'], [], 1);

        MarketingTag::create([
            'slug' => 'recien-inventada', 'name' => 'Recién inventada',
            'category' => MarketingTag::CATEGORY_OPERATIONAL,
            'kind' => MarketingTag::KIND_MANUAL, 'color' => 'amber', 'locked' => false,
        ]);

        // Petición nueva: el memo de la anterior no puede sobrevivir.
        $this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}",
            $this->adminHeaders(),
        )->assertOk();

        $names = collect($this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}",
            $this->adminHeaders(),
        )->json('data.tags_detailed'))->pluck('name');

        $this->assertContains('Recién inventada', $names);
    }

    // ── La búsqueda sigue encontrando lo que debe ───────────────────────────

    public function test_searching_finds_a_conversation_by_message_text(): void
    {
        $this->say($this->conversation, 'me interesa el plan trimestral');

        $ids = collect($this->list(['q' => 'trimestral'])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->conversation->id, $ids);
    }

    public function test_searching_finds_a_conversation_by_lead_name(): void
    {
        $ids = collect($this->list(['q' => 'Ramirez'])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->conversation->id, $ids);
    }

    /**
     * La gente escribe el telefono como le sale: con espacios, con guiones, con
     * indicativo. Comparar tal cual no encontraba nada y parecia que el
     * buscador estaba roto.
     */
    public function test_a_phone_written_with_spaces_still_finds_the_lead(): void
    {
        $ids = collect($this->list(['q' => '315 053 6026'])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->conversation->id, $ids);
    }

    /**
     * Con menos de tres caracteres NO se busca en los mensajes: por debajo de
     * un trigrama el indice no se puede usar y la consulta degenera en
     * recorrer la tabla entera. Dos letras tampoco identifican a nadie.
     */
    public function test_two_letters_do_not_search_inside_messages(): void
    {
        $other = $this->makeConversation('3009998877', 'Zoe Torres');
        $this->say($other, 'quiero el plan');

        $ids = collect($this->list(['q' => 'pl'])->assertOk()->json('data'))->pluck('id');

        $this->assertNotContains($other->id, $ids, 'Una busqueda de dos letras escaneo los mensajes.');
    }

    /** Pero sí busca en el nombre: ahí sí sirve, y la tabla es pequeña. */
    public function test_two_letters_still_search_the_lead_name(): void
    {
        $other = $this->makeConversation('3009998877', 'Zoe Torres');

        $ids = collect($this->list(['q' => 'Zo'])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($other->id, $ids);
    }

    /** Los comodines de SQL escritos por una persona son texto, no comodines. */
    public function test_a_percent_sign_is_searched_literally(): void
    {
        $other = $this->makeConversation('3001112233', 'Beto Silva');
        $this->say($other, 'tienen 50% de descuento?');
        $this->say($this->conversation, 'sin descuento aqui');

        $ids = collect($this->list(['q' => '50%'])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($other->id, $ids);
        $this->assertNotContains($this->conversation->id, $ids, 'El % actuo como comodin.');
    }

    // ── El coste no puede volver a subir ────────────────────────────────────

    /**
     * Prueba de regresión con dientes.
     *
     * La lista costaba 36 consultas para veinte filas: veinte de ellas eran
     * «dame el ultimo mensaje de esta conversacion», una por fila. Este limite
     * existe para que ese N+1 no vuelva a colarse sin que nadie se entere.
     */
    public function test_the_list_does_not_grow_one_query_per_conversation(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $conversation = $this->makeConversation('30012233'.str_pad((string) $i, 2, '0'), 'Lead '.$i);
            $this->say($conversation, 'mensaje de prueba '.$i);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->list()->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            12,
            $queries,
            "La lista hizo {$queries} consultas: parece que volvio un N+1.",
        );
    }

    public function test_opening_a_conversation_keeps_its_query_count_flat(): void
    {
        $service = app(MarketingInboxService::class);

        for ($i = 0; $i < 60; $i++) {
            $this->say($this->conversation, 'mensaje '.$i);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->detail($this->conversation->fresh());

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "Abrir la conversacion costo {$queries} consultas.");
    }
}
