<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Calidad humana del asesor: tono cuidador, breve, sin presión, sin links
 * sandbox, escalando lo sensible. Responder determinista (fake), Wompi sandbox,
 * META off. Cubre los casos del brief (precio, ubicación, objetivos, pena,
 * inseguridad, objeciones, despedida, pago, bot, humano, lesión, factura).
 */
class SalesAgentPersonaTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;
    private const SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'public_key' => 'pub_test', 'integrity_secret' => 'int_test',
            'checkout' => ['base_url' => 'https://checkout.wompi.co/p/'],
        ]));
        Http::fake();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    /** @return array{intent:?string, reply:?string, decision:array} */
    private function reply(string $body): array
    {
        $res = $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $this->lead->id, 'body' => $body,
        ], ['Authorization' => 'Bearer '.self::SECRET])->assertOk();

        return [
            'intent'   => $res->json('decision.intent'),
            'reply'    => (string) $res->json('decision.reply'),
            'decision' => $res->json('decision'),
        ];
    }

    private function assertNoPressure(string $reply): void
    {
        foreach (['última oportunidad', 'no lo pienses', 'si no empiezas hoy', 'sin excusas', 'transformar tu cuerpo'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $reply);
        }
    }

    private function assertNoLinkNoLie(string $reply): void
    {
        $this->assertStringNotContainsStringIgnoringCase('http', $reply);
        $this->assertStringNotContainsStringIgnoringCase('link', $reply);
    }

    public function test_greeting_welcomes_and_asks_what_they_need(): void
    {
        $r = $this->reply('hola');
        $this->assertSame(SalesIntents::GREETING, $r['intent']);
        $this->assertStringContainsStringIgnoringCase('bienvenido', $r['reply']);
        $this->assertStringContainsString('?', $r['reply']);
    }

    public function test_pricing_is_direct_natural_and_not_an_objection(): void
    {
        $r = $this->reply('precio');
        $this->assertSame(SalesIntents::PRICING_QUESTION, $r['intent']);
        $this->assertStringContainsString('$80.000', $r['reply']);
        $this->assertStringContainsString('?', $r['reply']);
        $this->assertStringNotContainsStringIgnoringCase('caro', $r['reply']);
        $this->assertNoLinkNoLie($r['reply']);
        $this->assertNoPressure($r['reply']);
    }

    public function test_location_gives_real_address_without_selling_payment(): void
    {
        $r = $this->reply('dónde quedan');
        $this->assertSame(SalesIntents::LOCATION_QUESTION, $r['intent']);
        $this->assertStringContainsString(SalesConversationReplyService::ADDRESS, $r['reply']);
        $this->assertStringNotContainsStringIgnoringCase('pago', $r['reply']);
    }

    public function test_goal_muscle_gain_orients_and_asks_experience(): void
    {
        $r = $this->reply('quiero ganar masa');
        $this->assertSame(SalesIntents::GOAL_MUSCLE_GAIN, $r['intent']);
        $this->assertStringContainsString('?', $r['reply']);
        $this->assertStringNotContainsString('$', $r['reply']);
    }

    public function test_goal_fat_loss_orients_sustainably(): void
    {
        $r = $this->reply('quiero bajar grasa');
        $this->assertSame(SalesIntents::GOAL_FAT_LOSS, $r['intent']);
        $this->assertStringContainsString('?', $r['reply']);
        $this->assertNoPressure($r['reply']);
    }

    public function test_beginner_fear_validates_emotion_and_asks_barrier(): void
    {
        $r = $this->reply('me da pena ir');
        $this->assertSame(SalesIntents::BEGINNER_FEAR, $r['intent']);
        $this->assertStringContainsStringIgnoringCase('pena', $r['reply']);
        $this->assertStringContainsString('?', $r['reply']);
        $this->assertStringNotContainsString('$', $r['reply']);
    }

    public function test_body_insecurity_does_not_judge_nor_sell_hard(): void
    {
        $r = $this->reply('estoy gordo y me da pena');
        $this->assertSame(SalesIntents::INSECURITY_BODY, $r['intent']);
        $this->assertStringContainsStringIgnoringCase('juzgado', $r['reply']);
        $this->assertStringNotContainsString('$', $r['reply']);
        $this->assertNoPressure($r['reply']);
    }

    public function test_price_objection_validates_and_reframes(): void
    {
        $r = $this->reply('está caro');
        $this->assertSame(SalesIntents::PRICE_OBJECTION, $r['intent']);
        $this->assertSame(SalesIntents::ACTION_REGISTER_OBJECTION, $r['decision']['recommended_action']);
        $this->assertStringContainsStringIgnoringCase('entiendo', $r['reply']);
        $this->assertStringContainsString('?', $r['reply']);
    }

    public function test_no_time_objection_validates_and_asks_days(): void
    {
        $r = $this->reply('no tengo tiempo');
        $this->assertSame(SalesIntents::TIME_OBJECTION, $r['intent']);
        $this->assertStringContainsStringIgnoringCase('días', $r['reply']);
        $this->assertStringContainsString('?', $r['reply']);
    }

    public function test_thanks_closes_soft_without_hard_sell(): void
    {
        $r = $this->reply('vale gracias');
        $this->assertSame(SalesIntents::THANKS, $r['intent']);
        $this->assertFalse($r['decision']['should_schedule_followup']);
        $this->assertNoPressure($r['reply']);
    }

    public function test_goodbye_soft_close_with_value_and_open_door(): void
    {
        $r = $this->reply('chao');
        $this->assertSame(SalesIntents::GOODBYE, $r['intent']);
        $this->assertSame(SalesIntents::LEAD_STAGE_LOST, $r['decision']['lead_stage']);
        // Deja valor (precio + dirección) y NO programa follow-up agresivo.
        $this->assertStringContainsString('$80.000', $r['reply']);
        $this->assertStringContainsString(SalesConversationReplyService::ADDRESS, $r['reply']);
        $this->assertFalse($r['decision']['should_schedule_followup']);
        $this->assertNoPressure($r['reply']);
    }

    public function test_not_interested_respects_decision(): void
    {
        $r = $this->reply('ya no quiero');
        $this->assertSame(SalesIntents::NOT_INTERESTED, $r['intent']);
        $this->assertSame(SalesIntents::LEAD_STAGE_LOST, $r['decision']['lead_stage']);
        $this->assertFalse($r['decision']['should_schedule_followup']);
        $this->assertStringContainsStringIgnoringCase('tranquilo', $r['reply']);
    }

    public function test_high_intent_pay_escalates_to_human_no_sandbox_link(): void
    {
        $r = $this->reply('quiero pagar');
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertFalse($r['decision']['should_generate_payment_link']);
        $this->assertSame(SalesIntents::ACTION_REPLY, $r['decision']['recommended_action']);
        // La IA no se apaga: deja la solicitud marcada para el equipo y sigue.
        $this->assertStringContainsStringIgnoringCase('equipo', $r['reply']);
        $this->assertNoLinkNoLie($r['reply']);
    }

    public function test_bot_question_is_transparent_and_offers_human(): void
    {
        $r = $this->reply('eres un bot?');
        $this->assertSame(SalesIntents::BOT_QUESTION, $r['intent']);
        $this->assertStringContainsStringIgnoringCase('asistente de Iron Body', $r['reply']);
        $this->assertStringContainsStringIgnoringCase('persona', $r['reply']);
        // Transparencia NO escala por sí sola.
        $this->assertFalse($r['decision']['needs_staff_review']);
    }

    public function test_human_request_escalates(): void
    {
        $r = $this->reply('quiero hablar con alguien');
        $this->assertSame(SalesIntents::HUMAN_REQUEST, $r['intent']);
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertStringContainsStringIgnoringCase('equipo', $r['reply']);
    }

    public function test_injury_escalates_and_does_not_recommend_training(): void
    {
        $r = $this->reply('tengo lesión de rodilla');
        $this->assertSame(SalesIntents::MEDICAL_RISK_ESCALATION, $r['intent']);
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertStringContainsStringIgnoringCase('equipo', $r['reply']);
        $this->assertStringNotContainsString('$', $r['reply']);
    }

    public function test_invoice_escalates_to_human(): void
    {
        $r = $this->reply('necesito factura');
        $this->assertSame(SalesIntents::INVOICE_REQUEST, $r['intent']);
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertStringContainsStringIgnoringCase('facturación', $r['reply']);
    }

    // ── Invariante crítica: LA IA NO SE APAGA SOLA NUNCA ──────────────────────
    //
    // En todo caso (normal o sensible) la decisión debe: should_escalate=false,
    // recommended_action=reply, con un reply seguro listo para enviar. Lo
    // sensible se marca en needs_staff_review (alerta), sin pausar la IA.

    private function assertAiStaysOn(array $decision): void
    {
        $this->assertFalse($decision['should_escalate'], 'La IA no debe escalar/apagarse sola.');
        $this->assertTrue($decision['should_reply'], 'Siempre debe responder algo seguro.');
        $this->assertTrue($decision['should_send_message'], 'Siempre debe quedar un mensaje para enviar.');
        $this->assertSame(SalesIntents::ACTION_REPLY, $decision['recommended_action']);
        $this->assertNotContains(SalesIntents::TOOL_HUMAN_TAKEOVER, $decision['tools_requested']);
    }

    /** A — recomposición no escala. */
    public function test_recomposition_does_not_escalate(): void
    {
        $r = $this->reply('quiero ganar masa muscular e ir perdiendo grasa');
        $this->assertSame(SalesIntents::GOAL_RECOMPOSITION, $r['intent']);
        $this->assertFalse($r['decision']['needs_staff_review']);
        $this->assertAiStaysOn($r['decision']);
        $this->assertStringContainsString('?', $r['reply']);
        $this->assertStringNotContainsStringIgnoringCase('equipo', $r['reply']);
    }

    /** B/C/D/E — objetivos e inseguridad NO escalan ni apagan la IA. */
    public function test_normal_goals_and_insecurity_never_escalate(): void
    {
        foreach (['soy principiante', 'estoy gordo y me da pena', 'quiero bajar grasa', 'quiero ganar masa'] as $body) {
            $r = $this->reply($body);
            $this->assertFalse($r['decision']['needs_staff_review'], "No debe escalar: {$body}");
            $this->assertAiStaysOn($r['decision']);
        }
    }

    /** F — pago no apaga la IA (sandbox: sin link, marcado para el equipo). */
    public function test_payment_does_not_disable_ai(): void
    {
        $r = $this->reply('quiero pagar el mensual');
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertFalse($r['decision']['should_generate_payment_link']);
        $this->assertAiStaysOn($r['decision']);
        $this->assertNoLinkNoLie($r['reply']);
    }

    /** G — factura no apaga la IA. */
    public function test_invoice_does_not_disable_ai(): void
    {
        $r = $this->reply('necesito una factura electrónica');
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertAiStaysOn($r['decision']);
    }

    /** H — lesión no apaga la IA. */
    public function test_injury_does_not_disable_ai(): void
    {
        $r = $this->reply('tengo una lesión en la rodilla');
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertAiStaysOn($r['decision']);
    }

    /** I — pedir humano no apaga la IA. */
    public function test_human_request_does_not_disable_ai(): void
    {
        $r = $this->reply('quiero hablar con una persona del equipo');
        $this->assertTrue($r['decision']['needs_staff_review']);
        $this->assertAiStaysOn($r['decision']);
    }

    /** J — tras despedirse, un "hola" posterior vuelve a ser atendido. */
    public function test_goodbye_then_hola_still_replies(): void
    {
        $bye = $this->reply('chao');
        $this->assertSame(SalesIntents::GOODBYE, $bye['intent']);
        $this->assertAiStaysOn($bye['decision']);

        // La IA sigue viva: el siguiente mensaje se atiende con normalidad.
        $hi = $this->reply('hola');
        $this->assertSame(SalesIntents::GREETING, $hi['intent']);
        $this->assertAiStaysOn($hi['decision']);
        $this->assertStringContainsStringIgnoringCase('bienvenido', $hi['reply']);
    }
}
