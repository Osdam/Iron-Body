<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Simulador del canal: ejerce el mismo pipeline que Meta (payload Cloud API →
 * ProcessMetaWebhookEvent → router → orquestador → herramientas → memoria →
 * respuesta → seguimiento → auditoría) sin depender de Meta ni de la red.
 *
 * Es la red de seguridad que permite trabajar el agente comercial mientras el
 * número sigue fuera de Cloud API.
 */
class SimulateInboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.whatsapp_phone_number_id', '');
        config()->set('marketing.ai.driver', 'fake');
        Http::fake();

        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
    }

    private function simulate(array $opts = []): void
    {
        $this->artisan('marketing:simulate-inbound', array_merge([
            '--analyze' => true,
            '--execute' => true,
            '--json' => true,
        ], $opts))->assertSuccessful();
    }

    public function test_pipeline_creates_lead_conversation_and_message(): void
    {
        $this->simulate(['--from' => '573001112233', '--text' => 'Hola, cuánto vale?']);

        $lead = MarketingLead::where('meta_user_id', '573001112233')->firstOrFail();
        $this->assertSame('whatsapp', $lead->channel);
        $this->assertNotNull($lead->conversations()->first() ?? $lead->id);
        $this->assertDatabaseHas('marketing_messages', [
            'direction' => 'inbound', 'sender_type' => 'lead', 'body' => 'Hola, cuánto vale?',
        ]);
    }

    public function test_agent_replies_without_sending_anything_outside(): void
    {
        $this->simulate(['--from' => '573001112244', '--text' => 'cuánto cuesta la mensualidad?']);

        $outbound = MarketingMessage::where('direction', 'outbound')->latest('id')->first();
        $this->assertNotNull($outbound, 'El agente debe producir una respuesta.');
        $this->assertSame('dry_run', $outbound->status, 'Con META_ENABLED=false nada puede salir.');
        Http::assertNothingSent();
    }

    public function test_followup_is_created_with_its_conversation(): void
    {
        $this->simulate(['--from' => '573001112255', '--text' => 'está muy caro']);

        $followup = MarketingFollowup::latest('id')->first();
        $this->assertNotNull($followup);
        $this->assertNotNull(
            $followup->marketing_conversation_id,
            'El simulador debe reproducir el pipeline corregido: sin seguimientos huérfanos.',
        );
    }

    public function test_repeating_the_same_message_id_is_idempotent(): void
    {
        $opts = ['--from' => '573001112266', '--text' => 'me interesa', '--message-id' => 'wamid.FIXED.1'];

        $this->simulate($opts);
        $count = MarketingMessage::where('direction', 'inbound')->count();

        $this->simulate($opts);

        $this->assertSame(
            $count,
            MarketingMessage::where('direction', 'inbound')->count(),
            'Un meta_message_id repetido no puede duplicar el mensaje entrante.',
        );
    }

    public function test_medical_message_escalates_and_does_not_diagnose(): void
    {
        $this->simulate(['--from' => '573001112277', '--text' => 'tengo una lesión en la rodilla']);

        $lead = MarketingLead::where('meta_user_id', '573001112277')->firstOrFail();
        $conversation = $lead->conversations()->firstOrFail();

        $this->assertTrue((bool) $conversation->staff_review_pending);
        $this->assertSame('medical_risk_escalation', $conversation->last_intent);
    }

    public function test_memory_is_persisted_for_continuity(): void
    {
        $this->simulate(['--from' => '573001112288', '--text' => 'quiero bajar de peso']);

        $conversation = MarketingLead::where('meta_user_id', '573001112288')
            ->firstOrFail()->conversations()->firstOrFail();

        $this->assertNotNull($conversation->summary);
        $this->assertNotNull($conversation->lead_stage);
        $this->assertNotNull($conversation->primary_intent);
    }

    public function test_denied_consent_lead_is_not_processed_by_the_agent(): void
    {
        MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573001112299', 'source' => 'inbound',
            'phone' => '3001112299', 'name' => 'Denegado', 'status' => MarketingLead::STATUS_NEW,
            'consent_status' => MarketingLead::CONSENT_DENIED,
        ]);

        $this->simulate(['--from' => '573001112299', '--text' => 'hola']);

        $this->assertSame(
            0,
            MarketingMessage::where('direction', 'outbound')->count(),
            'Un lead con consentimiento denegado no puede recibir respuesta.',
        );
    }
}
