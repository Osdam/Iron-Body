<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SendUltronEventToN8n;
use App\Models\Incident;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\UltronAbort;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingInboxService;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\SalesAgentOrchestratorService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;

/**
 * El agente IA PAUSADO: los mensajes se siguen recibiendo y guardando, y nada
 * automático le contesta a nadie.
 *
 * Y la regla de los trabajos YA INICIADOS, que es lo delicado: un turno se
 * juzga por cuándo EMPEZÓ. Si una pausa llegó después —aunque al terminar el
 * agente ya esté reactivado— su respuesta no sale nunca: durante la pausa pudo
 * contestar una persona, y la respuesta tardía de la máquina sería la segunda.
 */
class MarketingAgentPauseTest extends TestCase
{
    use RefreshDatabase;
    use UltronTurnEvents;

    private const PHONE_ID = '123456';

    private const SECRET = 'test-internal-secret';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.enabled', true);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.webhook_url', 'https://n8n.example.test/webhook/iron-body-ultron');
        config()->set('automation.webhook_secret', 'n8n-shared-secret');
        config()->set('automation.internal_secret', self::SECRET);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        $this->admin = $this->actingAsAdmin();
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    private function pausar(): void
    {
        $this->putJson('/api/admin/marketing/agent', ['paused' => true], $this->admin)->assertOk()->assertJsonPath('data.paused', true);
    }

    private function reactivar(): void
    {
        $this->putJson('/api/admin/marketing/agent', ['paused' => false], $this->admin)->assertOk()->assertJsonPath('data.paused', false);
    }

    private function entrante(string $body = 'hola, cuánto vale?', ?string $wamid = null): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid ?? 'wamid.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function webhook(string $body, string $wamid): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => '573150536026']],
            'messages' => [['from' => '573150536026', 'timestamp' => (string) now()->timestamp, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => $body]]],
        ]]]]]];
        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function internas(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->internas());
    }

    private function commit(MarketingMessage $m, string $token): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'collect_data', 'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Claro, te cuento. ¿Qué te gustaría lograr?',
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'ask_discovery',
                'confidence' => 0.82, 'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->internas());
    }

    private function salientes(?string $quien = null): int
    {
        return MarketingMessage::query()
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->when($quien !== null, fn ($q) => $q->where('sender_type', $quien))
            ->count();
    }

    /**
     * n8n recibe el turno; alguien pausa; n8n pregunta al decide (422) y
     * después responde al job con `$respuesta`, o con un timeout si es null.
     */
    private function cortaEnElDecide(MarketingMessage $m, mixed $respuesta): void
    {
        Http::fake(['n8n.example.test/*' => function () use ($m, $respuesta) {
            $this->pausar();
            $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
            if ($respuesta === null) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return $respuesta;
        }]);
    }

    private function noAcusaTrasReactivar(): void
    {
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();
        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'se acusó un turno que cortó la pausa');
        $this->assertSame(0, Incident::count());
    }

    /** @return list<int> los entrantes que el vigía acusó como huérfanos */
    private function acusados(): array
    {
        return array_map('intval', (array) (Incident::query()->where('kind', 'ultron.turn.orphaned')->latest('id')->first()?->evidence['message_ids'] ?? []));
    }

    private function metaEncendido(): void
    {
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'token-de-prueba');
        config()->set('meta.app_secret', 'secreto-de-prueba');
        // Un wamid distinto por envío: Meta nunca repite id y la columna es única.
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT.'.uniqid('', true)]]], 200)]);
    }

    // ── 1 · Entrada: se guarda todo, ULTRON no se entera ─────────────────────

    public function test_en_pausa_el_mensaje_se_guarda_y_ultron_no_se_entera(): void
    {
        $this->pausar();
        // Cualquier llamada saliente —a n8n o a Meta— rompe la prueba.
        Http::preventStrayRequests();

        $this->webhook('hola, cuánto vale?', 'wamid.P1')->assertOk();

        $guardado = MarketingMessage::where('meta_message_id', 'wamid.P1')->sole();
        $this->assertSame('hola, cuánto vale?', $guardado->body);
        $this->assertSame(MarketingMessage::DIRECTION_INBOUND, $guardado->direction);
        $this->assertSame(0, MarketingAutomationEvent::count(), 'nació un turno de ULTRON con el agente pausado');
        $this->assertSame(0, $this->salientes(), 'salió algo automático');

        // Reactivado, el siguiente mensaje vuelve a su camino normal. El reloj
        // avanza entre pasos como en la vida real: con precisión de segundo, un
        // mensaje del MISMO segundo que la última pausa cuenta como afectado.
        $this->travel(2)->seconds();
        $this->reactivar();
        $this->travel(2)->seconds();
        Http::fake(['n8n.example.test/*' => Http::response(['ok' => true], 200)]);
        $this->webhook('sigo interesado', 'wamid.P2')->assertOk();
        $this->assertSame('wamid.P2', MarketingAutomationEvent::sole()->idempotency_key);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'n8n.example.test'));
    }

    // ── 2 · En vuelo: el evento en cola ──────────────────────────────────────

    public function test_un_evento_en_cola_no_sale_hacia_n8n_si_se_pauso_despues(): void
    {
        Http::fake();
        $evento = $this->abreTurno($this->entrante(), MarketingAutomationEvent::STATUS_PENDING);
        $this->travel(2)->seconds();
        $this->pausar();

        SendUltronEventToN8n::dispatchSync($evento->id);

        Http::assertNothingSent();
        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_SKIPPED, $evento->status);
        $this->assertSame('agent_paused', $evento->last_error);
    }

    // ── 3 · En vuelo: decide y commit ────────────────────────────────────────

    public function test_en_pausa_decide_responde_que_no_es_atendible(): void
    {
        $m = $this->entrante();
        $this->pausar();

        $this->decide($m)->assertStatus(422)
            ->assertJsonPath('code', 'not_eligible')
            ->assertJsonPath('reason', 'agent_paused');
    }

    /** El decide solo deja la marca cuando lo rechaza la pausa, no por cualquier otro motivo. */
    public function test_el_decide_solo_marca_el_evento_cuando_lo_corta_la_pausa(): void
    {
        Http::fake();
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $this->conversation->forceFill(['ai_enabled' => false])->save();
        $this->pausar();

        $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'ai_disabled');
        $this->assertNull($evento->refresh()->decide_outcome, 'se anotó como cortado por la pausa un turno que rechazó otra cosa');

        $this->conversation->forceFill(['ai_enabled' => true])->save();
        $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->refresh()->decide_outcome);
    }

    /** La constancia del decide no toca los campos del job: ni el estado ni el motivo de un turno que no llegó a n8n. */
    public function test_la_constancia_del_decide_no_toca_los_campos_del_job(): void
    {
        Http::fake();
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SKIPPED);
        $evento->forceFill(['last_error' => 'ultron deshabilitado o sin configuración'])->save();
        $this->pausar();

        $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_SKIPPED, $evento->status);
        $this->assertSame('ultron deshabilitado o sin configuración', $evento->last_error);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome);
    }

    public function test_un_commit_en_vuelo_no_envia_nada_si_se_pauso(): void
    {
        Http::fake();
        $m = $this->entrante();
        $token = $this->decide($m)->assertOk()->json('decide_token');
        $this->travel(2)->seconds();
        $this->pausar();

        $this->commit($m, $token)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'agent_paused');

        $this->assertSame(0, $this->salientes(), 'el commit en vuelo envió con el agente pausado');
        $accion = MarketingAiAction::where('idempotency_key', $m->meta_message_id)->sole();
        $this->assertSame('skipped', $accion->status);
        // Con fila escrita, un reintento del mismo turno no vuelve a ejecutar nada.
        $this->commit($m, $token)->assertStatus(409);
        Http::assertNothingSent();
    }

    /**
     * La doble respuesta que esta regla evita: pausa corta, una persona contesta
     * durante la pausa, se reactiva, y LUEGO llega la respuesta de la máquina al
     * turno de antes. No sale: el turno empezó antes de la pausa.
     */
    public function test_tras_una_pausa_corta_la_respuesta_tardia_no_sale(): void
    {
        Http::fake();
        $m = $this->entrante();
        $token = $this->decide($m)->assertOk()->json('decide_token');
        $this->travel(2)->seconds();
        $this->pausar();

        app(MarketingMessageDispatcher::class)->dispatchWhatsapp(
            $this->lead, 'whatsapp', 'Hola, te atiendo yo.', [], MarketingMessage::SENDER_HUMAN, null, null, $this->conversation,
        );

        $this->travel(5)->seconds();
        $this->reactivar();

        $this->commit($m, $token)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'agent_paused');

        $this->assertSame(1, $this->salientes(), 'hubo dos respuestas');
        $this->assertSame(1, $this->salientes(MarketingMessage::SENDER_HUMAN));

        // Un mensaje NUEVO, ya reactivado, sí es atendible.
        $this->travel(2)->seconds();
        $this->decide($this->entrante('¿y los horarios?'))->assertOk();
    }

    /**
     * La ventana más estrecha: la pausa llega con el commit ya dentro, después
     * de sus puertas y antes del envío. La respuesta no sale (el despachador
     * también mira la pausa); lo que el turno ya anotó antes de enviar se
     * queda, y el turno cierra como no enviado.
     */
    public function test_si_la_pausa_llega_en_mitad_del_commit_la_respuesta_no_sale(): void
    {
        Http::fake();
        $m = $this->entrante();
        $token = $this->decide($m)->assertOk()->json('decide_token');

        MarketingAiAction::created(function (): void {
            DB::table('marketing_agent_settings')->where('id', 1)->update([
                'paused' => true, 'paused_at' => now(), 'version' => DB::raw('version + 1'),
            ]);
        });

        $this->commit($m, $token)->assertOk();

        $this->assertSame(0, $this->salientes(), 'salió una respuesta con el agente ya pausado');
        Http::assertNothingSent();
        $this->assertTrue(MarketingAgentSetting::query()->findOrFail(1)->paused);
    }

    // ── 4 · Salida: el embudo hacia Meta ─────────────────────────────────────

    public function test_en_pausa_lo_que_redacta_la_ia_no_sale_ni_deja_fila(): void
    {
        $this->metaEncendido();
        $this->pausar();
        $antes = $this->conversation->fresh()->last_outbound_at;

        $res = app(MarketingMessageDispatcher::class)->dispatchWhatsapp(
            $this->lead, 'whatsapp', 'Respuesta automática', ['kind' => 'reply', 'origin' => 'ultron'], MarketingMessage::SENDER_AI,
            conversation: $this->conversation,
        );

        $this->assertFalse($res['sent']);
        $this->assertSame('agent_paused', $res['reason']);
        $this->assertSame(0, $this->salientes());
        $this->assertEquals($antes, $this->conversation->fresh()->last_outbound_at);
        Http::assertNothingSent();
    }

    public function test_lo_que_escribe_una_persona_sale_aunque_este_pausado(): void
    {
        $this->metaEncendido();
        $this->pausar();

        $res = app(MarketingMessageDispatcher::class)->dispatchWhatsapp(
            $this->lead, 'whatsapp', 'Hola, te atiendo yo.', [], MarketingMessage::SENDER_HUMAN, null, null, $this->conversation,
        );

        $this->assertTrue($res['sent']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_los_avisos_de_pago_siguen_saliendo(): void
    {
        $this->metaEncendido();
        $this->pausar();

        foreach (['onboarding', 'claim_notice'] as $kind) {
            $res = app(MarketingMessageDispatcher::class)->dispatchWhatsapp(
                $this->lead, 'whatsapp', 'Tu pago quedó registrado.', ['kind' => $kind, 'origin' => 'system'], MarketingMessage::SENDER_AI,
                conversation: $this->conversation,
            );
            $this->assertTrue($res['sent'], "el aviso {$kind} no salió con el agente pausado");
        }
    }

    /**
     * Un saliente de la IA que esperaba su reintento, redactado antes de la
     * pausa: no sale durante la pausa ni al reactivar. Queda muerto y visible.
     */
    public function test_un_reintento_de_la_ia_de_antes_de_la_pausa_muere_y_no_sale_al_reanudar(): void
    {
        $this->metaEncendido();
        $pendiente = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI,
            'body' => 'Respuesta que esperaba reintento', 'status' => WhatsappOutboxService::STATUS_FAILED,
            'send_attempts' => 1, 'next_attempt_at' => now()->subMinute(), 'last_error_code' => '130429',
            // Tal como lo deja recordFailure() tras un 429 de Meta.
            'metadata' => ['kind' => 'reply', 'origin' => 'ultron',
                'failure' => ['code' => 130429, 'title' => 'Rate limit hit', 'http_status' => 429]],
        ]);
        $this->travel(2)->seconds();
        $this->pausar();
        $this->travel(2)->seconds();
        $this->reactivar();

        $res = app(WhatsappOutboxService::class)->deliver($pendiente->fresh(), '573150536026');

        $this->assertFalse($res['sent']);
        $this->assertSame('agent_paused', $res['reason']);
        $pendiente->refresh();
        $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $pendiente->status);
        $this->assertSame('agent_paused', $pendiente->last_error_message);
        // Sin el código de Meta del intento anterior: IRON GUARD titula con él y
        // esto no lo paró Meta, lo paró la pausa.
        $this->assertNull($pendiente->last_error_code);
        // Y el asesor ve en el Inbox el motivo real, no aquel 429.
        $burbuja = collect(app(MarketingInboxService::class)->messagePage($this->conversation)['items'])->firstWhere('id', $pendiente->id);
        $this->assertSame('agent_paused', $burbuja['failure']['code']);
        $this->assertStringContainsString('pausa', (string) $burbuja['failure']['title']);
        Http::assertNothingSent();
    }

    // ── 5 · El cerebro local ─────────────────────────────────────────────────

    public function test_en_pausa_el_cerebro_local_analiza_pero_no_ejecuta_nada(): void
    {
        Http::fake();
        $this->pausar();
        $m = $this->entrante('quiero inscribirme, cuánto vale?');

        $r = app(SalesAgentOrchestratorService::class)->handle(
            $this->lead, $this->conversation, $m->id, (string) $m->body, null, true,
        );

        $this->assertFalse($r['auto_execute']);
        $this->assertSame([], $r['executed']);
        $this->assertSame(0, $this->salientes());
    }

    /**
     * Un job atrasado del cerebro local cuyo mensaje llegó EN PAUSA no contesta
     * al reactivar: esa conversación pudo atenderla una persona.
     */
    public function test_un_turno_del_cerebro_local_que_empezo_en_pausa_no_contesta_al_reactivar(): void
    {
        Http::fake();
        $this->pausar();
        $this->travel(2)->seconds();
        $m = $this->entrante('quiero inscribirme, cuánto vale?');
        $this->travel(2)->seconds();
        $this->reactivar();
        $this->travel(2)->seconds();

        $r = app(SalesAgentOrchestratorService::class)->handle(
            $this->lead, $this->conversation, $m->id, (string) $m->body, null, true,
        );

        $this->assertFalse($r['auto_execute']);
        $this->assertSame(0, $this->salientes());
    }

    /** Contraprueba: un mensaje que llega YA reactivado sigue su camino. */
    public function test_un_turno_del_cerebro_local_posterior_a_reactivar_si_ejecuta(): void
    {
        Http::fake();
        $this->pausar();
        $this->travel(2)->seconds();
        $this->reactivar();
        $this->travel(2)->seconds();
        $m = $this->entrante('quiero inscribirme, cuánto vale?');

        $r = app(SalesAgentOrchestratorService::class)->handle(
            $this->lead, $this->conversation, $m->id, (string) $m->body, null, true,
        );

        $this->assertTrue($r['auto_execute']);
    }

    // ── 6 · El vigía ─────────────────────────────────────────────────────────

    /**
     * Un turno del canario que la pausa CORTÓ en el decide —n8n ya lo tenía y
     * preguntó con el agente pausado— no es un silencio: ni incidente ni freno,
     * tampoco después de reactivar. El decide no deja fila, así que la
     * constancia es la marca que deja en el evento, sin tocar su estado.
     */
    public function test_el_vigia_no_acusa_ni_frena_por_un_turno_que_corto_la_pausa(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);   // en n8n
        $this->travel(1)->seconds();
        $this->pausar();
        $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();                                           // ya pasó la gracia

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, $evento->status, 'la constancia cambió el estado del evento');
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome);
        $this->assertNull($evento->last_error, 'la constancia tocó un campo del job');

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'la pausa accionó el freno del canario');
        $this->assertSame(0, Incident::count(), 'la pausa abrió un incidente de silencio');
    }

    /**
     * Y esa absolución es ESTABLE: otro ciclo de pausar y reactivar media hora
     * después no convierte el mismo turno en huérfano (ni en un freno falso).
     */
    public function test_la_absolucion_de_un_turno_cortado_por_la_pausa_no_cambia_con_otra_pausa(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $this->travel(1)->seconds();
        $this->pausar();
        $this->decide($m)->assertStatus(422);
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();
        $this->artisan('ultron:turn-watchdog')->run();
        $this->assertSame(0, UltronAbort::open()->count());

        $this->travel(30)->minutes();
        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(1)->minutes();
        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'otro ciclo de pausa convirtió en freno un turno ya absuelto');
        $this->assertSame(0, Incident::count());
    }

    /**
     * La carrera de la marca: n8n llama al decide ANTES de que el job haya
     * marcado el evento como `sent`. La marca tiene que sobrevivir a esa
     * escritura del job.
     */
    public function test_la_marca_del_decide_sobrevive_al_job_que_marca_el_evento_enviado(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_PENDING);

        Http::fake(['n8n.example.test/*' => function () use ($m) {
            // n8n recibe el turno, alguien pausa y n8n pregunta: todo antes de
            // que el job reciba la respuesta y escriba `sent`.
            $this->pausar();
            $this->decide($m)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');

            return Http::response(['ok' => true], 200);
        }]);

        SendUltronEventToN8n::dispatchSync($evento->id);

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, $evento->status);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome, 'el job borró la constancia del decide');

        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();
        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count());
    }

    /**
     * n8n responde al job DESPUÉS del decide, al final del turno. La constancia
     * tiene que sobrevivir a las tres escrituras del job: un reintento que
     * limpia el error anterior, un no-2xx de n8n tras el 422, y el fallo
     * definitivo con failed().
     */
    public function test_la_constancia_sobrevive_al_reintento_que_limpia_el_error_anterior(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_FAILED);
        $evento->forceFill(['last_error' => 'ConnectionException', 'attempts' => 1])->save();
        $this->cortaEnElDecide($m, Http::response(['ok' => true], 200));

        SendUltronEventToN8n::dispatchSync($evento->id);

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, $evento->status);
        $this->assertNull($evento->last_error);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome);
        $this->noAcusaTrasReactivar();
    }

    public function test_la_constancia_sobrevive_a_un_error_de_n8n_despues_del_decide(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_PENDING);
        $this->cortaEnElDecide($m, Http::response(['error' => 'sin contexto'], 503));

        SendUltronEventToN8n::dispatchSync($evento->id);

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_FAILED, $evento->status);
        $this->assertSame('http_503', $evento->last_error);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome);
        $this->noAcusaTrasReactivar();
    }

    public function test_la_constancia_sobrevive_al_fallo_definitivo_del_job(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $m = $this->entrante();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_PENDING);
        $this->cortaEnElDecide($m, null);

        try {
            SendUltronEventToN8n::dispatchSync($evento->id);
            $this->fail('el job no falló');
        } catch (ConnectionException) {
            // el timeout de n8n tras el decide: el job lo marca y failed() lo remata
        }

        $evento->refresh();
        $this->assertSame(MarketingAutomationEvent::STATUS_FAILED, $evento->status);
        $this->assertStringStartsWith('job_failed:', (string) $evento->last_error);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_AGENT_PAUSED, $evento->decide_outcome);
        $this->noAcusaTrasReactivar();
    }

    /**
     * El contrato nuevo del decide, también con el agente ACTIVO: cuando releva
     * un turno, su única escritura es la constancia en el evento. Ni el estado
     * ni el motivo del job, ni filas nuevas.
     */
    public function test_el_relevo_en_el_decide_solo_escribe_la_constancia(): void
    {
        Http::fake();
        $m1 = $this->entrante('hola');
        $e1 = $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);
        $antes = [MarketingMessage::count(), MarketingAiAction::count(), MarketingAutomationEvent::count()];

        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true)->assertJsonPath('superseded_by', $m2->id);

        $e1->refresh();
        $this->assertSame(MarketingAutomationEvent::OUTCOME_SUPERSEDED, $e1->decide_outcome);
        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, $e1->status);
        $this->assertNull($e1->last_error);
        $this->assertSame($antes, [MarketingMessage::count(), MarketingAiAction::count(), MarketingAutomationEvent::count()]);
    }

    /**
     * La invariante que sostiene todo lo anterior: el job NUNCA escribe la
     * constancia del decide, ni al terminar ni al fallar, aunque la traiga ya
     * cargada (un reintento después de un decide anterior).
     */
    public function test_el_job_nunca_toca_la_constancia_del_decide(): void
    {
        $m = $this->entrante();
        $ok = $this->abreTurno($m, MarketingAutomationEvent::STATUS_FAILED);
        $ok->forceFill(['decide_outcome' => MarketingAutomationEvent::OUTCOME_SUPERSEDED, 'last_error' => 'ConnectionException'])->save();
        // Una sola secuencia: dos Http::fake a la misma URL se acumulan y gana el primero.
        Http::fakeSequence('n8n.example.test/*')
            ->push(['ok' => true], 200)
            ->push(['error' => 'x'], 503);
        SendUltronEventToN8n::dispatchSync($ok->id);
        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, $ok->refresh()->status);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_SUPERSEDED, $ok->decide_outcome, 'el job borró la constancia al terminar');

        $m2 = $this->entrante('otra');
        $mal = $this->abreTurno($m2, MarketingAutomationEvent::STATUS_PENDING);
        $mal->forceFill(['decide_outcome' => MarketingAutomationEvent::OUTCOME_SUPERSEDED])->save();
        SendUltronEventToN8n::dispatchSync($mal->id);
        $this->assertSame(MarketingAutomationEvent::STATUS_FAILED, $mal->refresh()->status);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_SUPERSEDED, $mal->decide_outcome, 'el job borró la constancia al fallar');
    }

    /**
     * El relevo: «hola» y «quiero información» seguidos. El decide del primero
     * lo da por relevado y el turno del segundo lo corta la pausa a mitad. Nadie
     * contesta, y la causa es la pausa: ni el relevado es un silencio.
     */
    public function test_el_vigia_no_acusa_un_turno_relevado_si_la_pausa_corto_el_que_lo_relevo(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $e1 = $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);

        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);
        $this->assertSame(MarketingAutomationEvent::OUTCOME_SUPERSEDED, $e1->refresh()->decide_outcome);
        $token = $this->decide($m2)->assertOk()->json('decide_token');
        $this->travel(6)->seconds();
        $this->pausar();
        $this->commit($m2, $token)->assertOk()->assertJsonPath('blocked_reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'el turno relevado se tomó por un silencio');
        $this->assertSame(0, Incident::count());
    }

    /** Lo mismo cuando el relevo lo decide el commit del primero (acción bloqueada por relevo). */
    public function test_el_vigia_no_acusa_un_turno_que_relevo_el_commit_si_la_pausa_corto_el_que_lo_relevo(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $token1 = $this->decide($m1)->assertOk()->json('decide_token');      // aún no hay relevo
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);
        $this->commit($m1, $token1)->assertOk()->assertJsonPath('blocked_reason', 'superseded');
        $token2 = $this->decide($m2)->assertOk()->json('decide_token');
        $this->travel(6)->seconds();
        $this->pausar();
        $this->commit($m2, $token2)->assertOk()->assertJsonPath('blocked_reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count());
        $this->assertSame(0, Incident::count());
    }

    /** El relevo, con el turno que relevó cortado por la pausa en su DECIDE. */
    public function test_el_vigia_no_acusa_un_turno_relevado_si_la_pausa_corto_en_el_decide_el_que_lo_relevo(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);
        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);
        $this->travel(2)->seconds();
        $this->pausar();
        $this->decide($m2)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count());
        $this->assertSame(0, Incident::count());
    }

    /** El relevo, con el evento del turno que relevó retenido por la pausa en el JOB (skipped). */
    public function test_el_vigia_no_acusa_un_turno_relevado_si_la_pausa_retuvo_en_el_job_el_que_lo_relevo(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $e2 = $this->abreTurno($m2, MarketingAutomationEvent::STATUS_PENDING);  // aún en la cola
        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);
        $this->travel(2)->seconds();
        $this->pausar();
        SendUltronEventToN8n::dispatchSync($e2->id);
        $this->assertSame(MarketingAutomationEvent::STATUS_SKIPPED, $e2->refresh()->status);
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count());
        $this->assertSame(0, Incident::count());
    }

    /** El relevo, con la respuesta del turno que relevó retenida por la pausa en el DESPACHADOR. */
    public function test_el_vigia_no_acusa_un_turno_relevado_si_la_pausa_retuvo_el_envio_del_que_lo_relevo(): void
    {
        $this->metaEncendido();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);
        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);
        $token = $this->decide($m2)->assertOk()->json('decide_token');

        // La pausa llega con el commit de M2 ya en marcha: la acción existe y el envío se retiene.
        MarketingAiAction::created(function (): void {
            DB::table('marketing_agent_settings')->where('id', 1)->update([
                'paused' => true, 'paused_at' => now(), 'version' => DB::raw('version + 1'),
            ]);
        });
        $this->commit($m2, $token)->assertOk();
        $accion = MarketingAiAction::query()->where('source_event_id', $m2->id)->sole();
        $this->assertSame('agent_paused', data_get($accion->metadata, 'outbound.reason'), 'el envío no lo retuvo la pausa');
        $this->assertSame(0, $this->salientes());

        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();
        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count());
        $this->assertSame(0, Incident::count());
    }

    /** Solo cuenta un corte de la MISMA conversación: el de otra no absuelve a nadie aquí. */
    public function test_un_corte_de_otra_conversacion_no_absuelve_un_turno_relevado(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);         // murió en n8n
        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);

        $otroLead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150000001',
            'meta_user_id' => '573150000001', 'name' => 'Otro', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $otra = MarketingConversation::create([
            'lead_id' => $otroLead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $m3 = MarketingMessage::create([
            'conversation_id' => $otra->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola', 'meta_message_id' => 'wamid.'.uniqid(), 'status' => 'received',
        ]);
        $this->abreTurno($m3, MarketingAutomationEvent::STATUS_SENT);
        $this->travel(2)->seconds();
        $this->pausar();
        $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $otra->id, 'message_id' => $m3->id], $this->internas())
            ->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count());
        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $this->acusados());
    }

    /**
     * Contrafactual: el primer turno no fue relevado, murió en n8n sin llegar
     * al decide. Que la pausa cortara el siguiente no lo absuelve.
     */
    public function test_un_turno_que_murio_en_n8n_se_acusa_aunque_la_pausa_cortara_el_siguiente(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);         // n8n lo aceptó y murió
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);
        $this->travel(1)->seconds();
        $this->pausar();
        $this->decide($m2)->assertStatus(422)->assertJsonPath('reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(13)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count(), 'la pausa del turno siguiente tapó un turno que murió en n8n');
        $this->assertSame([$m1->id], $this->acusados());
    }

    /**
     * Y el relevo solo no basta: si el turno que relevó murió en n8n sin que lo
     * cortara la pausa, el silencio es una avería y se acusan los dos.
     */
    public function test_un_turno_relevado_se_acusa_si_el_que_lo_relevo_murio_sin_que_lo_cortara_la_pausa(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m1 = $this->entrante('hola');
        $this->abreTurno($m1, MarketingAutomationEvent::STATUS_SENT);
        $m2 = $this->entrante('quiero información');
        $this->abreTurno($m2, MarketingAutomationEvent::STATUS_SENT);         // y su turno murió en n8n
        $this->decide($m1)->assertOk()->assertJsonPath('superseded', true);
        $this->travel(2)->minutes();
        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(11)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count());
        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $this->acusados());
    }

    /**
     * Una pausa no absuelve un turno que murió en n8n sin llegar a preguntar:
     * n8n lo aceptó y se cayó; alguien lo nota, pausa y reactiva DENTRO de la
     * gracia. Sin constancia de que la pausa lo cortara, es una avería.
     */
    public function test_la_pausa_no_absuelve_un_turno_que_murio_en_n8n(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $this->travel(2)->minutes();
        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(10)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count(), 'la pausa tapó un turno que murió en n8n');
        $this->assertGreaterThan(0, Incident::count());
    }

    /**
     * Ni siquiera mientras sigue pausado: un turno que ya había fallado antes
     * de la pausa se acusa en la corrida siguiente. Esperar a que reactiven
     * podía dejarlo fuera de la ventana del vigía para siempre.
     */
    public function test_un_turno_que_fallo_antes_de_la_pausa_se_acusa_aunque_siga_pausado(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->abreTurno($m, MarketingAutomationEvent::STATUS_FAILED);
        $this->travel(3)->minutes();
        $this->pausar();
        $this->travel(10)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count(), 'la pausa en curso tapó una avería anterior');
        $this->assertGreaterThan(0, Incident::count());
    }

    /**
     * Pero una pausa no absuelve lo que ya estaba muerto: el turno falló al
     * entregarse ANTES de que nadie pausara (n8n caído); alguien lo nota, pausa
     * y reactiva. Ese silencio es una avería y tiene que llegar al incidente y
     * al freno del canario.
     */
    public function test_la_pausa_no_absuelve_un_turno_que_ya_habia_fallado_antes(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->abreTurno($m, MarketingAutomationEvent::STATUS_FAILED);  // murió sin llegar a n8n
        $this->travel(5)->minutes();
        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(10)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count(), 'la pausa tapó una avería anterior');
        $this->assertGreaterThan(0, Incident::count());
    }

    /** Y un turno que la pausa no pudo cortar —pausa muy posterior— tampoco se absuelve. */
    public function test_una_pausa_muy_posterior_no_absuelve_un_turno_mudo(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $m->forceFill(['created_at' => now()->subMinutes(60)])->save();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $evento->forceFill(['created_at' => now()->subMinutes(60)])->save();

        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count());
    }

    /**
     * Un turno lento —n8n tardó más que la gracia— al que la pausa le bloqueó
     * el commit: aunque la pausa llegó fuera de la ventana, el commit dejó
     * constancia de que fue ella quien lo cortó, y eso basta para no acusarlo.
     */
    public function test_el_vigia_no_acusa_un_turno_lento_cuyo_commit_bloqueo_la_pausa(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $token = $this->decide($m)->assertOk()->json('decide_token');
        $this->travel(12)->minutes();                                 // más que la gracia
        $this->pausar();
        $this->commit($m, $token)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'agent_paused');
        $this->travel(1)->minutes();
        $this->reactivar();
        $this->travel(11)->minutes();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'se acusó un turno que la pausa bloqueó con constancia');
        $this->assertSame(0, Incident::count());
    }

    /**
     * Si además de la pausa hay otro motivo —una persona tomó la conversación—,
     * manda ese otro motivo: la pausa va la ÚLTIMA en ineligibleReason, y un
     * turno tomado por un humano no es un silencio aunque la pausa no lo cortara.
     */
    public function test_con_otro_motivo_ademas_de_la_pausa_manda_el_otro(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $m->forceFill(['created_at' => now()->subMinutes(60)])->save();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $evento->forceFill(['created_at' => now()->subMinutes(60)])->save();
        $this->conversation->forceFill(['human_takeover' => true, 'human_takeover_source' => 'manual'])->save();

        $this->pausar();
        $this->travel(1)->minutes();
        $this->reactivar();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(0, UltronAbort::open()->count(), 'un turno en manos de una persona se tomó por silencio');
    }

    /** Contraprueba: sin pausa, el mismo turno mudo SÍ es un huérfano. */
    public function test_sin_pausa_el_mismo_turno_mudo_si_es_un_huerfano(): void
    {
        Http::fake();
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $m = $this->entrante();
        $m->forceFill(['created_at' => now()->subMinutes(60)])->save();
        $evento = $this->abreTurno($m, MarketingAutomationEvent::STATUS_SENT);
        $evento->forceFill(['created_at' => now()->subMinutes(60)])->save();

        $this->artisan('ultron:turn-watchdog')->run();

        $this->assertSame(1, UltronAbort::open()->count());
        $this->assertGreaterThan(0, Incident::count());
    }
}
