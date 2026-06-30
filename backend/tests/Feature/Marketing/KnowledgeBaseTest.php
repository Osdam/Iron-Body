<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesIntents;
use Database\Seeders\MarketingKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 3.5 — base de conocimiento comercial. El prompt recibe datos reales
 * (knowledge + planes activos) y nunca inventa. Endpoints internos firmados.
 */
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function lead(): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    // ── Seeder ────────────────────────────────────────────────────────────────

    public function test_seeder_is_idempotent(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        $count = MarketingKnowledgeItem::count();
        $this->assertGreaterThan(0, $count);

        (new MarketingKnowledgeSeeder())->run();
        $this->assertSame($count, MarketingKnowledgeItem::count());
        // Sin keys duplicadas.
        $this->assertSame($count, MarketingKnowledgeItem::distinct('key')->count('key'));
    }

    // ── Doctor (sin secretos) ─────────────────────────────────────────────────

    public function test_knowledge_doctor_command_runs_without_secrets(): void
    {
        config()->set('services.openai.api_key', 'SECRET_VALUE_XYZ');
        (new MarketingKnowledgeSeeder())->run();

        $code = Artisan::call('marketing:knowledge-doctor');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('activos', $out);
        $this->assertStringNotContainsString('SECRET_VALUE_XYZ', $out);
    }

    public function test_knowledge_doctor_endpoint_requires_bearer(): void
    {
        $this->getJson('/api/internal/marketing/knowledge/doctor')->assertStatus(401);
    }

    public function test_knowledge_doctor_endpoint_reports_counts(): void
    {
        (new MarketingKnowledgeSeeder())->run();

        $this->getJson('/api/internal/marketing/knowledge/doctor', $this->headers())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.prompt_receives_knowledge', true)
            ->assertJsonStructure(['data' => ['total_items', 'active_items', 'by_category', 'missing_recommended', 'active_plans_count']]);
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    public function test_prompt_builder_includes_active_knowledge_and_plans(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $prompt = app(SalesAgentPromptBuilder::class)->userPrompt($this->lead(), 'hola');

        $this->assertStringContainsString('Iron Body Neiva', $prompt);   // business_identity
        $this->assertStringContainsString('Wompi', $prompt);             // payment_policy
        $this->assertStringContainsString('Plan Mensual', $prompt);      // active_plans (DB)
    }

    public function test_prompt_builder_excludes_inactive_and_expired(): void
    {
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'k.active', 'content' => 'CONTENIDO_ACTIVO', 'is_active' => true]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'k.inactive', 'content' => 'CONTENIDO_INACTIVO', 'is_active' => false]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'k.expired', 'content' => 'CONTENIDO_VENCIDO', 'is_active' => true, 'valid_until' => now()->subDay()]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'k.future', 'content' => 'CONTENIDO_FUTURO', 'is_active' => true, 'valid_from' => now()->addDay()]);

        $prompt = app(SalesAgentPromptBuilder::class)->userPrompt($this->lead(), 'hola');

        $this->assertStringContainsString('CONTENIDO_ACTIVO', $prompt);
        $this->assertStringNotContainsString('CONTENIDO_INACTIVO', $prompt);
        $this->assertStringNotContainsString('CONTENIDO_VENCIDO', $prompt);
        $this->assertStringNotContainsString('CONTENIDO_FUTURO', $prompt);
    }

    public function test_system_prompt_has_conservative_no_invention_rules(): void
    {
        $system = app(SalesAgentPromptBuilder::class)->systemPrompt();

        $this->assertStringContainsString('NO inventes', $system);
        $this->assertStringContainsString('HORARIOS', $system);   // regla horario conservadora
        $this->assertStringContainsString('UBICACIÓN', $system);  // regla ubicación conservadora
    }

    public function test_schedule_absent_is_not_fabricated_in_prompt(): void
    {
        // Sin categoría schedule cargada.
        (new MarketingKnowledgeSeeder())->run();
        MarketingKnowledgeItem::where('category', 'schedule')->delete();

        $grouped = app(MarketingKnowledgeBaseService::class)->groupedForPrompt();
        $this->assertArrayNotHasKey('schedule', $grouped);

        // El prompt de sistema instruye a deferir a una persona si no hay horario.
        $system = app(SalesAgentPromptBuilder::class)->systemPrompt();
        $this->assertStringContainsString('confirma el horario exacto', $system);
    }

    // ── Endpoints CRUD internos ───────────────────────────────────────────────

    public function test_knowledge_upsert_creates_then_updates(): void
    {
        $payload = ['category' => 'location', 'key' => 'location.main', 'title' => 'Sede', 'content' => 'Sede principal en Neiva.'];

        $this->postJson('/api/internal/marketing/knowledge', $payload, $this->headers())
            ->assertOk()->assertJsonPath('created', true);

        $this->postJson('/api/internal/marketing/knowledge', array_merge($payload, ['content' => 'Sede actualizada.']), $this->headers())
            ->assertOk()->assertJsonPath('created', false);

        $this->assertDatabaseHas('marketing_knowledge_items', ['key' => 'location.main', 'content' => 'Sede actualizada.']);
        $this->assertSame(1, MarketingKnowledgeItem::where('key', 'location.main')->count());
    }

    public function test_knowledge_upsert_rejects_invalid_category(): void
    {
        $this->postJson('/api/internal/marketing/knowledge', [
            'category' => 'hacking', 'key' => 'x', 'content' => 'y',
        ], $this->headers())->assertStatus(422);
    }

    public function test_knowledge_index_filters_by_category(): void
    {
        (new MarketingKnowledgeSeeder())->run();

        $res = $this->getJson('/api/internal/marketing/knowledge?category=payment_policy', $this->headers())->assertOk();
        $cats = collect($res->json('data'))->pluck('category')->unique()->all();
        $this->assertSame(['payment_policy'], $cats);
    }

    // ── Integración con la decisión ───────────────────────────────────────────

    public function test_ai_action_metadata_includes_knowledge_count(): void
    {
        (new MarketingKnowledgeSeeder())->run();

        $res = $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $this->lead()->id, 'body' => 'cuánto vale?',
        ], $this->headers())->assertOk();

        $action = \App\Models\MarketingAiAction::find($res->json('ai_action_id'));
        $this->assertGreaterThan(0, $action->metadata['knowledge_items_count'] ?? 0);
        $this->assertArrayHasKey('knowledge_version', $action->metadata);
    }

    public function test_openai_does_not_invent_price_even_with_active_plans(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-test');
        config()->set('services.openai.api_key', 'sk-test');

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'intent' => SalesIntents::PRICING_QUESTION, 'confidence' => 0.9,
                'reply' => 'La mensualidad son $999.999 al mes.', 'tools_requested' => ['reply'],
            ])]]]], 200),
            '*' => Http::response([], 200),
        ]);

        $reply = $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $this->lead()->id, 'body' => 'precio?',
        ], $this->headers())->assertOk()->json('decision.reply');

        // El precio inventado por el modelo se descarta; Laravel cotiza el REAL.
        $this->assertStringNotContainsString('999.999', $reply);
        $this->assertStringContainsString('$80.000 COP', $reply);
    }

    public function test_medical_and_do_not_contact_still_safe_with_knowledge(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        $lead = $this->lead();

        // Médico → escala.
        $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $lead->id, 'body' => 'tengo una lesión', 'auto_execute' => true,
        ], $this->headers())->assertOk()->assertJsonPath('decision.needs_staff_review', true);

        // do_not_contact → bloquea.
        $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $lead->id, 'body' => 'no me escriban', 'auto_execute' => true,
        ], $this->headers())->assertOk()->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_MARK_DNC);

        $this->assertTrue((bool) $lead->fresh()->do_not_contact);
    }
}
