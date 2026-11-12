<?php

namespace Tests\Feature\Commercial;

use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El Centro de supervisión (`GET /api/admin/marketing/supervision/state`) dice
 * quién le responde DE VERDAD a un cliente.
 *
 * El 12-11-2026 la pantalla decía en producción «Agente apagado» y, a la vez,
 * «Las lleva el agente: 855». Las dos cosas eran falsas: `agent.enabled` leía
 * el interruptor del agente anterior (`marketing.agent_enabled`), y
 * `handled_by_ai` cuenta toda conversación abierta que nadie del equipo ha
 * tomado. ULTRON estaba encendido y atendía UNA: la del canario.
 *
 * Aquí se fija, contra la ruta real, cada estado posible y cuántas
 * conversaciones atiende el agente en cada uno.
 */
class SupervisionStateTruthTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = [];

    private MarketingLead $lead;

    private MarketingConversation $canario;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('commercial.events_enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-12 15:00:00', 'UTC'));

        $this->headers = $this->actingAsAdmin(Admin::create([
            'name' => 'Supervisora', 'email' => 'sup-estado@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]));
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150000088', 'phone' => '3150000088',
            'name' => 'Prospecto Estado', 'status' => MarketingLead::STATUS_NEW,
        ]);

        // Cinco abiertas: la del canario y tres más con la IA permitida, y una que tomó una persona. Y una cerrada.
        $this->canario = $this->conversation();
        $this->conversation();
        $this->conversation();
        $this->conversation();
        $this->conversation(['human_takeover' => true, 'human_takeover_source' => 'manual']);
        $this->conversation(['status' => 'closed']);

        // Como producción el 12-11-2026: ULTRON encendido en canario; el agente anterior, apagado y sin analizar.
        $this->configure(ultron: true, canary: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_como_en_produccion_ultron_en_canario_no_esta_apagado_y_atiende_una_conversacion_no_todas(): void
    {
        $s = $this->state();

        $this->assertSame(
            ['ultron_canary', true, 'ULTRON responde solo en la conversación de prueba (canario)', 'ULTRON · solo canario', false],
            [$s['agent']['status'], $s['agent']['enabled'], $s['agent']['label'], $s['agent']['short_label'], $s['agent']['legacy_enabled']],
            'con el agente anterior apagado, la pantalla ya no dice «Apagado»: responde ULTRON',
        );
        $this->assertSame(
            ['open' => 5, 'attended_by_agent' => 1, 'handled_by_ai' => 4, 'handled_by_human' => 1],
            array_intersect_key($s['conversations'], array_flip(['open', 'attended_by_agent', 'handled_by_ai', 'handled_by_human'])),
            'atiende la del canario; las otras tres con la IA permitida no las lleva nadie',
        );
    }

    public function test_si_una_persona_toma_la_conversacion_del_canario_el_agente_no_atiende_ninguna(): void
    {
        $this->canario->forceFill(['human_takeover' => true, 'human_takeover_source' => 'manual'])->save();

        $s = $this->state();

        $this->assertSame(['ultron_canary', 0, 3, 2], [$s['agent']['status'], $s['conversations']['attended_by_agent'], $s['conversations']['handled_by_ai'], $s['conversations']['handled_by_human']]);
    }

    /**
     * Las barreras del emisor de ULTRON, una a una (m-4 de la revisión de seguridad): el prospecto que pidió que no
     * le escriban no se atiende; una toma que NO es manual (la hizo el propio agente al escalar) no lo deja mudo.
     */
    public function test_el_recuento_del_canario_sigue_las_barreras_del_emisor(): void
    {
        $this->canario->forceFill(['human_takeover' => true, 'human_takeover_source' => 'agent_escalation'])->save();
        $this->assertSame(1, $this->state()['conversations']['attended_by_agent'], 'una toma no manual no para a ULTRON');

        $this->canario->forceFill(['human_takeover' => false, 'human_takeover_source' => null])->save();
        $this->lead->forceFill(['do_not_contact' => true])->save();
        $this->assertFalse($this->lead->fresh()->canReplyReactively());
        $this->assertSame(0, $this->state()['conversations']['attended_by_agent'], 'a quien pidió que no le escriban no se le responde');

        $this->lead->forceFill(['do_not_contact' => false])->save();
        $this->canario->forceFill(['channel' => 'instagram'])->save();
        $this->assertSame(0, $this->state()['conversations']['attended_by_agent'], 'ULTRON solo atiende WhatsApp');
    }

    /** Un canario que no es una conversación no deja pasar a nadie: la pantalla no puede decir que ULTRON responde. */
    public function test_un_canario_que_no_existe_o_sin_conexion_no_dice_que_responde(): void
    {
        foreach ([0, 999_999] as $id) {
            config()->set('marketing.ultron.canary_conversation_id', $id);
            $s = $this->state();
            $this->assertSame(['ultron_canary_missing', false, 'Canario mal configurado', 0], [$s['agent']['status'], $s['agent']['enabled'], $s['agent']['short_label'], $s['conversations']['attended_by_agent']], "canario {$id}");
        }

        $this->configure(ultron: true, canary: true);
        config()->set('marketing.ultron.webhook_url', '');
        $s = $this->state();
        $this->assertSame(['ultron_unconfigured', false, 0], [$s['agent']['status'], $s['agent']['enabled'], $s['conversations']['attended_by_agent']], 'sin la dirección de n8n el turno no sale');

        config()->set('marketing.ultron.webhook_url', 'https://n8n.prueba.test/webhook/ultron');
        config()->set('automation.webhook_secret', '');
        $this->assertSame('ultron_unconfigured', $this->state()['agent']['status'], 'sin el secreto, tampoco');
    }

    public function test_el_canario_cerrado_o_con_la_ia_apagada_tampoco_cuenta(): void
    {
        $this->canario->forceFill(['ai_enabled' => false])->save();
        $this->assertSame(0, $this->state()['conversations']['attended_by_agent'], 'con la IA apagada en esa conversación');

        $this->canario->forceFill(['ai_enabled' => true, 'status' => 'closed'])->save();
        $this->assertSame(0, $this->state()['conversations']['attended_by_agent'], 'cerrada');
    }

    /** @return array<string, array{0: array<string, bool>, 1: string, 2: bool, 3: int, 4: string}> */
    public static function estados(): array
    {
        return [
            'ULTRON abierto a todas' => [['ultron' => true], 'ultron_all', true, 4, 'ULTRON responde en todas las conversaciones'],
            'nada encendido' => [[], 'off', false, 0, 'Ningún agente responde automáticamente'],
            'el agente anterior responde solo' => [['agent' => true, 'analyze' => true, 'execute' => true], 'legacy_auto', true, 4, 'El agente anterior responde por su cuenta'],
            'el agente anterior analiza sin ejecutar' => [['agent' => true, 'analyze' => true], 'legacy_propose', false, 0, 'El agente anterior solo propone respuestas: no envía nada por su cuenta'],
            'solo el análisis, con el agente apagado' => [['analyze' => true], 'legacy_propose', false, 0, 'El agente anterior solo propone respuestas: no envía nada por su cuenta'],
            'el agente anterior encendido pero sin analizar' => [['agent' => true, 'execute' => true], 'off', false, 0, 'Ningún agente responde automáticamente'],
            'ULTRON manda sobre el agente anterior' => [['ultron' => true, 'canary' => true, 'agent' => true, 'analyze' => true, 'execute' => true], 'ultron_canary', true, 1, 'ULTRON responde solo en la conversación de prueba (canario)'],
        ];
    }

    /** @param  array<string, bool>  $llaves */
    #[DataProvider('estados')]
    public function test_cada_combinacion_de_llaves_dice_quien_responde_y_cuantas_atiende(array $llaves, string $estado, bool $responde, int $atiende, string $texto): void
    {
        $this->configure(...$llaves);

        $s = $this->state();

        $this->assertSame([$estado, $responde, $texto, $atiende], [$s['agent']['status'], $s['agent']['enabled'], $s['agent']['label'], $s['conversations']['attended_by_agent']]);
        $this->assertSame(4, $s['conversations']['handled_by_ai'], 'las que nadie ha tomado no dependen de quién responda');
    }

    public function test_en_pausa_o_frenado_no_responde_nadie_aunque_ultron_este_encendido(): void
    {
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now()]);
        $this->assertTrue(app(MarketingAgentSwitch::class)->isPaused(), 'la pausa global, como la pone el CRM');

        $s = $this->state();
        $this->assertSame(['paused', false, 'Agente en pausa: nada automático responde', 0], [$s['agent']['status'], $s['agent']['enabled'], $s['agent']['label'], $s['conversations']['attended_by_agent']]);

        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => false]);
        app(UltronAbortLatch::class)->engage(UltronAbort::REASON_MANUAL, 'prueba');

        $s = $this->state();
        $this->assertSame(['brake', false, 'ULTRON está frenado: no responde hasta que se suelte el freno', 0], [$s['agent']['status'], $s['agent']['enabled'], $s['agent']['label'], $s['conversations']['attended_by_agent']]);
    }

    public function test_la_pausa_no_convierte_en_pausado_lo_que_ya_estaba_apagado(): void
    {
        $this->configure();
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now()]);

        $this->assertSame('off', $this->state()['agent']['status'], 'quitar la pausa no encendería nada: decir «en pausa» sería prometerlo');
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function state(): array
    {
        return $this->getJson('/api/admin/marketing/supervision/state', $this->headers)->assertOk()->json('data');
    }

    private function configure(bool $ultron = false, bool $canary = false, bool $agent = false, bool $analyze = false, bool $execute = false): void
    {
        config()->set('marketing.ultron.enabled', $ultron);
        config()->set('marketing.ultron.webhook_url', 'https://n8n.prueba.test/webhook/ultron');
        config()->set('automation.webhook_secret', 'secreto-de-prueba-no-real');
        config()->set('marketing.ultron.canary_conversation_id', $canary ? (int) $this->canario->id : null);
        config()->set('marketing.agent_enabled', $agent);
        config()->set('marketing.inbound.auto_analyze', $analyze);
        config()->set('marketing.inbound.auto_execute', $execute);
    }

    /** @param  array<string, mixed>  $con */
    private function conversation(array $con = []): MarketingConversation
    {
        $c = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        $c->forceFill($con + ['ai_enabled' => true, 'human_takeover' => false])->save();

        return $c;
    }
}
