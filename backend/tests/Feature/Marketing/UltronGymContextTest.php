<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MyClass;
use App\Models\Plan;
use App\Models\Trainer;
use App\Services\Marketing\Ultron\GymFactsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo que el modelo puede afirmar del gimnasio sale del CRM, en `context.gym`:
 * clases (horario, no cupos), entrenadores (cuántos y de qué, nunca quiénes) y
 * horario de apertura solo si existe en la base de conocimiento. Lo que no hay
 * se marca SOURCE_NOT_AVAILABLE para que se diga «lo confirma una persona» en
 * vez de inventarse.
 */
class UltronGymContextTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);

        MyClass::create(['name' => 'IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'requires_active_plan' => true]);
        MyClass::create(['name' => 'Copia de IRON POWERFLOW', 'type' => 'grupal', 'day_of_week' => 'monday', 'start_time' => '06:00:00', 'end_time' => '07:00:00', 'status' => 'active', 'max_capacity' => 20]);
        Trainer::create(['full_name' => 'Carlos Pérez', 'document' => '99887766', 'phone' => '3001112233', 'email' => 'carlos@x.co', 'birth_date' => '1990-01-01', 'main_specialty' => 'Musculación', 'status' => 'active']);
        Trainer::create(['full_name' => 'Ana Gómez', 'document' => '55443322', 'phone' => '3004445566', 'email' => 'ana@x.co', 'main_specialty' => 'Funcional', 'status' => 'active']);
    }

    /** @return array<string,mixed> */
    private function decide(): array
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => 'qué clases tienen y a qué hora abren?', 'meta_message_id' => 'wamid.gym.1', 'status' => 'received',
        ]);

        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], ['Authorization' => 'Bearer '.self::SECRET])
            ->assertOk()->json();
    }

    public function test_decide_hands_the_model_the_gym_facts_the_crm_actually_has(): void
    {
        $gym = $this->decide()['context']['gym'] ?? null;

        $this->assertNotNull($gym, 'context.gym existe');
        $this->assertSame(['IRON POWERFLOW'], array_column($gym['classes'], 'name'), 'la copia sucia no es otra clase');
        $this->assertSame('lunes', $gym['classes'][0]['day']);
        $this->assertSame('06:00', $gym['classes'][0]['start_time']);
        $this->assertArrayNotHasKey('max_capacity', $gym['classes'][0], 'los cupos no se exponen como hecho');
        $this->assertSame(2, $gym['trainers']['active_count']);
        $this->assertEqualsCanonicalizing(['Musculación', 'Funcional'], $gym['trainers']['specialties']);
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $gym['opening_hours'], 'sin ítem schedule en la KB no hay horario de apertura');
        $this->assertSame(GymFactsProvider::SOURCE_NOT_AVAILABLE, $gym['class_availability'], 'las reservas no reflejan la ocupación real: no se afirma cupo');
    }

    public function test_the_model_never_sees_who_the_trainers_are(): void
    {
        $json = json_encode($this->decide());

        foreach (['Carlos Pérez', 'Ana Gómez', '3001112233', '3004445566', 'carlos@x.co', 'ana@x.co', '99887766', '55443322', '1990-01-01'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, "$pii no viaja al modelo");
        }
    }
}
