<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialToolInvocation;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use App\Services\Commercial\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las barreras que separan «el agente sugiere» de «el agente hace».
 *
 * Lo que se prueba aquí no es que las herramientas funcionen —eso está en las
 * pruebas de cada dominio— sino que el ejecutor no deja pasar nada que no deba:
 * un nombre inventado, un flag apagado, un campo de más, un reintento que
 * duplicaría un cobro.
 *
 * La prueba central es la del `amount`: un modelo de lenguaje que decide el
 * precio es la forma más rápida de regalar membresías, y la defensa no puede
 * ser que a alguien se le ocurra ignorar el campo.
 */
class ToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commercial.autonomy_enabled', true);
        config()->set('commercial.tools', [
            'catalog' => true, 'lead' => true, 'payments' => true,
            'memberships' => true, 'agenda' => true, 'invoicing' => true, 'app' => true,
        ]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 120000, 'duration_days' => 30,
            'tier' => 'basic', 'active' => true,
        ]);
    }

    private function executor(): ToolExecutor
    {
        return app(ToolExecutor::class);
    }

    private function context(array $overrides = []): ToolContext
    {
        return new ToolContext(
            lead: $overrides['lead'] ?? $this->lead,
            conversation: $overrides['conversation'] ?? $this->conversation,
            requestedBy: $overrides['requestedBy'] ?? 'engine',
            correlationId: 'test-correlation',
            idempotencyKey: $overrides['idempotencyKey'] ?? null,
        );
    }

    // ── El nombre no es texto libre ──────────────────────────────────────────

    public function test_an_unknown_tool_name_does_nothing(): void
    {
        $result = $this->executor()->execute('drop_database', [], $this->context());

        $this->assertFalse($result->successful());
        $this->assertSame('unknown_tool', $result->errorCode);
    }

    // ── El dinero no lo decide el modelo ─────────────────────────────────────

    /**
     * La prueba que justifica todo el diseño: no hay forma de que un importe
     * propuesto desde la conversación llegue a la pasarela.
     */
    public function test_an_invented_amount_is_refused_not_ignored(): void
    {
        $result = $this->executor()->execute('create_payment_link', [
            'plan_id' => $this->plan->id,
            'amount' => 1000, // «el cliente pidió descuento»
        ], $this->context());

        $this->assertFalse($result->successful());
        $this->assertSame('unexpected_arguments', $result->errorCode);
        // Se rechaza en vez de ignorarse en silencio: que el modelo lo intente
        // es información que hay que poder ver.
        $this->assertStringContainsString('amount', (string) $result->message);
    }

    public function test_other_invented_fields_are_refused_too(): void
    {
        foreach (['price', 'discount', 'status', 'member_id'] as $field) {
            $result = $this->executor()->execute('create_payment_link', [
                'plan_id' => $this->plan->id,
                $field => 'lo que sea',
            ], $this->context());

            $this->assertSame('unexpected_arguments', $result->errorCode, "El campo {$field} se coló.");
        }
    }

    public function test_invalid_arguments_are_rejected(): void
    {
        $result = $this->executor()->execute('create_payment_link', [
            'plan_id' => 999999, // no existe
        ], $this->context());

        $this->assertSame('invalid_arguments', $result->errorCode);
    }

    // ── Flags ────────────────────────────────────────────────────────────────

    public function test_a_disabled_tool_does_not_run(): void
    {
        config()->set('commercial.tools.payments', false);

        $result = $this->executor()->execute('create_payment_link', [
            'plan_id' => $this->plan->id,
        ], $this->context());

        $this->assertSame('tool_disabled', $result->errorCode);
    }

    /**
     * Con la autonomía apagada el motor puede LEER pero no escribir. Es la
     * configuración con la que esto se despliega.
     */
    public function test_without_autonomy_the_engine_can_read_but_not_write(): void
    {
        config()->set('commercial.autonomy_enabled', false);

        $read = $this->executor()->execute('list_plans', [], $this->context());
        $this->assertTrue($read->successful());

        $write = $this->executor()->execute('create_payment_link', [
            'plan_id' => $this->plan->id,
        ], $this->context());
        $this->assertSame('autonomy_disabled', $write->errorCode);
    }

    /** Ceder la conversación a una persona nunca depende de un flag. */
    public function test_escalating_works_even_with_everything_disabled(): void
    {
        config()->set('commercial.autonomy_enabled', false);
        config()->set('commercial.tools', []);

        $result = $this->executor()->execute('escalate_to_human', [
            'reason' => 'customer_request',
        ], $this->context());

        $this->assertTrue($result->successful(), 'Un cliente que pide una persona se quedó sin ella.');
        $this->assertTrue($this->conversation->fresh()->human_takeover);
        $this->assertFalse((bool) $this->conversation->fresh()->ai_enabled);
    }

    // ── Idempotencia ─────────────────────────────────────────────────────────

    /**
     * El caso que evita el cobro duplicado: dos intentos con la misma intención
     * producen un solo efecto.
     */
    public function test_the_same_intent_twice_executes_once(): void
    {
        $context = $this->context(['idempotencyKey' => 'link:lead-1:plan-1']);

        $first = $this->executor()->execute('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ], $context);

        $second = $this->executor()->execute('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ], $context);

        $this->assertTrue($first->successful());
        $this->assertTrue($second->successful());
        // El segundo no ejecutó: devolvió lo que consiguió el primero.
        $this->assertSame('skipped', $second->status);

        $this->assertSame(1, CommercialToolInvocation::query()
            ->where('idempotency_key', 'link:lead-1:plan-1')
            ->count());
    }

    // ── Auditoría ────────────────────────────────────────────────────────────

    public function test_every_execution_leaves_an_auditable_row(): void
    {
        $this->executor()->execute('list_plans', [], $this->context());

        $row = CommercialToolInvocation::query()->where('tool', 'list_plans')->first();

        $this->assertNotNull($row);
        $this->assertSame('succeeded', $row->status);
        $this->assertSame($this->lead->id, $row->marketing_lead_id);
        $this->assertSame('test-correlation', $row->correlation_id);
        $this->assertNotNull($row->duration_ms);
    }

    /** También lo rechazado deja acta: un intento denegado es información. */
    public function test_a_rejection_is_audited(): void
    {
        $this->executor()->execute('create_payment_link', [
            'plan_id' => $this->plan->id,
            'amount' => 1,
        ], $this->context());

        $row = CommercialToolInvocation::query()
            ->where('status', CommercialToolInvocation::STATUS_REJECTED)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('unexpected_arguments', $row->error_code);
    }

    /** Un rechazo no consume la intención: hay que poder corregir y reintentar. */
    public function test_a_rejection_does_not_burn_the_idempotency_key(): void
    {
        $context = $this->context(['idempotencyKey' => 'agenda:lead-1']);

        $this->executor()->execute('book_appointment', ['type' => 'visit'], $context); // falta la fecha

        $retry = $this->executor()->execute('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i'),
        ], $context);

        $this->assertTrue($retry->successful());
        $this->assertSame('succeeded', $retry->status);
    }

    // ── Registro ─────────────────────────────────────────────────────────────

    public function test_disabled_tools_are_not_offered_to_the_model(): void
    {
        config()->set('commercial.tools.payments', false);

        $names = array_column(
            array_column(app(ToolRegistry::class)->openAiSchemas(), 'function'),
            'name',
        );

        // Enseñarle una capacidad que luego se le deniega produce agentes que
        // prometen lo que el sistema no va a hacer.
        $this->assertNotContains('create_payment_link', $names);
        $this->assertContains('list_plans', $names);
    }

    public function test_every_schema_forbids_extra_properties(): void
    {
        foreach (app(ToolRegistry::class)->all() as $name => $tool) {
            $this->assertArrayHasKey('additionalProperties', $tool->schema(), "{$name} no declara additionalProperties.");
            $this->assertFalse($tool->schema()['additionalProperties'], "{$name} acepta campos inventados.");
        }
    }
}
