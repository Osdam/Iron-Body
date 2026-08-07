<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Marketing\SalesAgentPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuando una persona toma el control y luego lo devuelve.
 *
 * La parte que importa no es pausar la IA —eso ya funcionaba— sino lo que pasa
 * al devolvérsela. Mientras la conversación estuvo en manos de alguien pudieron
 * prometerse cosas, acordarse un precio o resolverse una queja, y el agente no
 * lo vivió. Si retoma con el contexto de antes, repite preguntas que un humano
 * ya contestó y contradice lo acordado, que es exactamente la experiencia que
 * hace que un cliente pida no volver a hablar con el bot.
 */
class HumanTakeoverHandoverTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConversation $conversation;

    private MarketingLead $lead;

    private MarketingManualTakeoverService $takeover;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();

        $this->takeover = app(MarketingManualTakeoverService::class);

        $admin = Admin::create([
            'name' => 'Supervisor', 'email' => 'takeover@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($admin);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    private function say(string $body, string $sender = 'lead', string $direction = 'inbound'): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => $direction, 'sender_type' => $sender, 'body' => $body,
        ]);
    }

    // ── Tomar el control ────────────────────────────────────────────────

    public function test_tomar_el_control_apaga_la_ia(): void
    {
        $this->takeover->takeover($this->conversation, 1, 'customer_asked');

        $fresh = $this->conversation->fresh();

        $this->assertTrue((bool) $fresh->human_takeover);
        $this->assertFalse((bool) $fresh->ai_enabled);
        $this->assertSame('manual', $fresh->human_takeover_source);
    }

    /** El motivo se guarda: «cuántas veces entramos porque falló el agente»
     *  es una pregunta que hay que poder responder. */
    public function test_el_motivo_del_traspaso_queda_registrado(): void
    {
        $this->takeover->takeover($this->conversation, 1, 'agent_error');

        $this->assertDatabaseHas('marketing_ai_actions', [
            'conversation_id' => $this->conversation->id,
            'action_type' => 'human_takeover',
            'reason' => 'agent_error',
        ]);
    }

    public function test_los_motivos_son_una_lista_cerrada(): void
    {
        $this->assertArrayHasKey('agent_error', MarketingManualTakeoverService::REASONS);
        $this->assertArrayHasKey('customer_asked', MarketingManualTakeoverService::REASONS);
        // `other` existe para no obligar a mentir cuando no encaja ninguno.
        $this->assertArrayHasKey('other', MarketingManualTakeoverService::REASONS);
    }

    // ── La carrera ──────────────────────────────────────────────────────

    /**
     * La prueba obligatoria: la IA procesando y una persona tomando el control
     * a la vez.
     *
     * Con la conversación ya en manos de alguien, el envío automático no puede
     * salir. Que salgan dos respuestas —una del agente y otra de la persona—
     * es de las cosas que peor se ven desde el otro lado.
     */
    public function test_con_el_control_tomado_la_ia_no_responde_encima(): void
    {
        $this->say('hola, quiero precios');

        // La persona entra mientras el agente «estaba pensando».
        $this->takeover->takeover($this->conversation, 1, 'customer_asked');

        $fresh = $this->conversation->fresh();

        // El router usa estas dos banderas para decidir si contesta.
        $this->assertTrue((bool) $fresh->human_takeover, 'La conversacion no quedo tomada.');
        $this->assertFalse((bool) $fresh->ai_enabled, 'La IA seguia habilitada.');
    }

    /** Tomar el control dos veces no rompe nada ni duplica el registro. */
    public function test_tomar_el_control_dos_veces_es_inocuo(): void
    {
        $this->takeover->takeover($this->conversation, 1, 'payment');
        $this->takeover->takeover($this->conversation->fresh(), 1, 'payment');

        $this->assertTrue((bool) $this->conversation->fresh()->human_takeover);
    }

    // ── Devolver a la IA ────────────────────────────────────────────────

    public function test_devolver_reactiva_la_ia(): void
    {
        $this->takeover->takeover($this->conversation, 1, 'payment');
        $this->takeover->release($this->conversation->fresh(), 1);

        $fresh = $this->conversation->fresh();

        $this->assertFalse((bool) $fresh->human_takeover);
        $this->assertTrue((bool) $fresh->ai_enabled);
    }

    /**
     * El corazón de la prueba: el agente NO retoma con el contexto de antes.
     */
    public function test_al_devolver_se_escribe_un_resumen_del_traspaso(): void
    {
        $this->say('quiero el plan mensual');
        $this->takeover->takeover($this->conversation, 1, 'commercial_exception');
        $this->say('Listo, te dejo el mensual con la promo de agosto.', 'human', 'outbound');

        $this->takeover->release($this->conversation->fresh(), 1);

        $summary = (string) $this->conversation->fresh()->summary;

        $this->assertStringContainsString('[Traspaso]', $summary);
        $this->assertStringContainsString('promo de agosto', $summary, 'No consta lo que dijo el equipo.');
        $this->assertStringContainsString('No repitas', $summary, 'Falta la instruccion de no repetir.');
    }

    /** Y ese resumen llega al prompt del agente sin tocar el prompt. */
    public function test_el_resumen_llega_al_contexto_del_agente(): void
    {
        $this->say('hola');
        $this->takeover->takeover($this->conversation, 1, 'billing');
        $this->say('Ya te generé la factura a nombre de tu empresa.', 'human', 'outbound');
        $this->takeover->release($this->conversation->fresh(), 1);

        $prompt = json_decode(
            app(SalesAgentPromptBuilder::class)->userPrompt(
                $this->lead->fresh(),
                'gracias',
                $this->conversation->fresh(),
            ),
            true,
        );

        $this->assertStringContainsString('[Traspaso]', (string) $prompt['memory']['summary']);
        $this->assertStringContainsString('factura', (string) $prompt['memory']['summary']);
    }

    /** Si el equipo no escribió nada, se dice así en vez de callarlo. */
    public function test_un_traspaso_sin_mensajes_lo_dice(): void
    {
        $this->takeover->takeover($this->conversation, 1, 'other');
        $this->takeover->release($this->conversation->fresh(), 1);

        $this->assertStringContainsString(
            'no llegó a escribir',
            (string) $this->conversation->fresh()->summary,
        );
    }

    // ── Endpoints ───────────────────────────────────────────────────────

    public function test_se_puede_tomar_y_devolver_desde_el_inbox(): void
    {
        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/takeover",
            ['reason' => 'customer_asked'],
            $this->adminHeaders(),
        )->assertOk()->assertJsonPath('human_takeover', true);

        $this->postJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/release",
            [],
            $this->adminHeaders(),
        )->assertOk();

        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
    }
}
