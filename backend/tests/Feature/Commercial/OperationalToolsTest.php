<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las reglas de negocio que ninguna herramienta puede saltarse.
 *
 * Cada una de estas pruebas corresponde a una forma concreta de hacer daño
 * real: cobrar dos veces, activar una membresía que nadie pagó, partir el
 * historial de una persona en dos fichas, o seguir vendiendo a quien pidió que
 * lo dejaran en paz.
 */
class OperationalToolsTest extends TestCase
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

    private function tool(string $tool, array $args = [], array $ctx = [])
    {
        return app(ToolExecutor::class)->execute($tool, $args, new ToolContext(
            lead: $ctx['lead'] ?? $this->lead,
            member: $ctx['member'] ?? null,
            conversation: $this->conversation,
            requestedBy: 'engine',
            correlationId: 'test',
            idempotencyKey: $ctx['key'] ?? uniqid('k', true),
        ));
    }

    private function transaction(string $status, array $extra = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'amount' => 120000, 'currency' => 'COP',
            'status' => $status, 'provider' => 'wompi',
            'plan_id' => $this->plan->id,
        ], $extra));
    }

    // ── Catálogo: el precio nunca se inventa ─────────────────────────────────

    public function test_the_catalog_is_the_only_source_of_prices(): void
    {
        $result = $this->tool('list_plans');

        $this->assertTrue($result->successful());
        $this->assertSame(120000.0, $result->data['plans'][0]['price']);
    }

    /** Sin catálogo no se ofrece nada: es mejor consultar que inventar. */
    public function test_without_an_active_catalog_no_price_is_invented(): void
    {
        $this->plan->update(['active' => false]);

        $result = $this->tool('list_plans');

        $this->assertFalse($result->successful());
        $this->assertSame('no_active_plans', $result->errorCode);
    }

    public function test_a_plan_retired_between_lookup_and_payment_is_caught(): void
    {
        // El caso real: el asesor retira el plan del CRM mientras la
        // conversación sigue abierta.
        $this->plan->update(['active' => false]);

        $result = $this->tool('create_payment_link', ['plan_id' => $this->plan->id]);

        $this->assertSame('plan_not_available', $result->errorCode);
    }

    // ── Membresía: sin pago confirmado no hay socio ──────────────────────────

    /** La regla que impide regalar membresías. */
    public function test_no_member_is_created_without_a_confirmed_payment(): void
    {
        $pending = $this->transaction('pending');

        $result = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $pending->reference,
        ]);

        $this->assertFalse($result->successful());
        $this->assertSame('payment_not_confirmed', $result->errorCode);
        // Reintentable: el pago puede confirmarse en unos minutos.
        $this->assertTrue($result->retryable);
        $this->assertSame(0, Member::query()->count());
    }

    public function test_a_declined_payment_does_not_create_a_member_either(): void
    {
        $declined = $this->transaction('declined');

        $result = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $declined->reference,
        ]);

        $this->assertSame('payment_not_confirmed', $result->errorCode);
        $this->assertSame(0, Member::query()->count());
    }

    public function test_an_approved_payment_does_create_the_member(): void
    {
        $approved = $this->transaction('approved');

        $result = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $approved->reference,
        ]);

        $this->assertTrue($result->successful());
        $this->assertSame(1, Member::query()->count());
        $this->assertSame(MarketingLead::STATUS_CONVERTED, $this->lead->fresh()->status);
    }

    /**
     * Un socio duplicado parte el historial en dos: las asistencias quedan en
     * una ficha y los pagos en otra, y a partir de ahí ninguna decisión sobre
     * esa persona es correcta.
     */
    public function test_an_existing_person_is_linked_not_duplicated(): void
    {
        $existing = Member::create([
            'full_name' => 'Ana Prueba', 'document_number' => '1010101010',
            'phone' => '3150536026', 'access_hash' => 'x'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
        $approved = $this->transaction('approved');

        $result = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $approved->reference,
        ]);

        $this->assertSame('skipped', $result->status);
        $this->assertSame(1, Member::query()->count());
        $this->assertSame($existing->id, $this->lead->fresh()->member_id);
    }

    /**
     * Identidad ambigua: no se elige ni se fusiona. Fusionar mal dos personas
     * es mucho más caro de reparar que esperar a que lo mire alguien.
     */
    public function test_an_ambiguous_identity_goes_to_a_human(): void
    {
        Member::create([
            'full_name' => 'Ana Documento', 'document_number' => '1010101010',
            'phone' => '3009990000', 'access_hash' => 'a'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        Member::create([
            'full_name' => 'Otra Persona', 'document_number' => '2020202020',
            'phone' => '3150536026', 'access_hash' => 'b'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $approved = $this->transaction('approved');

        $result = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Documento',
            'payment_reference' => $approved->reference,
        ]);

        $this->assertSame('ambiguous_identity', $result->errorCode);
        $this->assertFalse($result->retryable, 'Reintentar no resuelve una ambigüedad de identidad.');
        $this->assertSame(2, Member::query()->count());
        $this->assertNull($this->lead->fresh()->member_id);
    }

    // ── Pago: solo la pasarela confirma ──────────────────────────────────────

    public function test_a_pending_payment_is_reported_as_not_confirmed(): void
    {
        $member = Member::create([
            'full_name' => 'Ana', 'document_number' => '3030303030', 'phone' => '3001112233',
            'access_hash' => 'c'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->transaction('pending', ['member_id' => $member->id]);

        $result = $this->tool('get_payment_status', [], ['member' => $member]);

        $this->assertTrue($result->successful());
        $this->assertFalse($result->data['confirmed']);
        $this->assertStringContainsString('NO está confirmado', (string) $result->message);
    }

    /** No se puede consultar el pago de otra persona con una referencia suelta. */
    public function test_a_foreign_reference_reveals_nothing(): void
    {
        $mine = Member::create([
            'full_name' => 'Mío', 'document_number' => '4040404040', 'phone' => '3004445566',
            'access_hash' => 'd'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $other = Member::create([
            'full_name' => 'Ajeno', 'document_number' => '5050505050', 'phone' => '3007778899',
            'access_hash' => 'e'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $foreign = $this->transaction('approved', ['member_id' => $other->id]);

        $result = $this->tool('get_payment_status', ['reference' => $foreign->reference], ['member' => $mine]);

        $this->assertFalse($result->data['found']);
        $this->assertFalse($result->data['confirmed']);
    }

    // ── Agenda ───────────────────────────────────────────────────────────────

    public function test_an_appointment_in_the_past_is_refused(): void
    {
        $result = $this->tool('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->subDay()->format('Y-m-d H:i'),
        ]);

        $this->assertSame('scheduled_in_the_past', $result->errorCode);
    }

    /** Casi siempre es un error de año, y llena la agenda de basura. */
    public function test_an_appointment_a_year_away_is_refused(): void
    {
        $result = $this->tool('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->addYear()->format('Y-m-d H:i'),
        ]);

        $this->assertSame('scheduled_too_far', $result->errorCode);
    }

    /** Decir dos veces «sí, el martes» no puede producir dos reservas. */
    public function test_a_second_appointment_is_not_created(): void
    {
        $this->tool('book_appointment', [
            'type' => 'visit', 'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ]);
        $second = $this->tool('book_appointment', [
            'type' => 'call', 'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i'),
        ]);

        $this->assertSame('skipped', $second->status);
        $this->assertSame(1, MarketingAppointment::query()->count());
    }

    /** La hora se interpreta en Neiva; el servidor corre en UTC. */
    public function test_the_hour_is_read_in_neiva_not_in_utc(): void
    {
        $localDate = now(config('commercial.contact_limits.timezone'))->addDays(2)->format('Y-m-d');

        $result = $this->tool('book_appointment', [
            'type' => 'visit', 'scheduled_at' => "{$localDate} 09:00",
        ]);

        $this->assertTrue($result->successful());
        $this->assertSame("{$localDate} 09:00", $result->data['scheduled_at_local']);
    }

    // ── Facturación ──────────────────────────────────────────────────────────

    public function test_incomplete_invoice_data_says_exactly_what_is_missing(): void
    {
        $result = $this->tool('validate_invoice_data', [
            'document_type' => 'CC',
            'document_number' => '1010101010',
        ]);

        $this->assertTrue($result->successful());
        $this->assertFalse($result->data['complete']);
        $this->assertContains('name', $result->data['missing_fields']);
        $this->assertContains('email', $result->data['missing_fields']);
        // Y en lenguaje que se le pueda decir a una persona.
        $this->assertNotEmpty($result->data['ask_for']);
    }

    public function test_complete_invoice_data_passes(): void
    {
        $result = $this->tool('validate_invoice_data', [
            'document_type' => 'CC', 'document_number' => '1010101010',
            'name' => 'Ana Prueba', 'email' => 'ana@example.com',
        ]);

        $this->assertTrue($result->data['complete']);
    }

    public function test_a_nit_without_check_digit_is_flagged_not_fixed(): void
    {
        $result = $this->tool('validate_invoice_data', [
            'document_type' => 'NIT', 'document_number' => '900123456',
            'name' => 'Empresa SAS', 'email' => 'facturas@empresa.com',
        ]);

        $this->assertTrue($result->data['complete']);
        $this->assertNotEmpty($result->data['warnings']);
    }

    // ── App ──────────────────────────────────────────────────────────────────

    /**
     * El caso que se resuelve mal por defecto: tiene cuenta pero no ve su
     * membresía. Decirle que se registre otra vez le crea un segundo usuario.
     */
    public function test_an_account_without_visible_membership_escalates(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@iron.test', 'password' => bcrypt('x')]);
        $member = Member::create([
            'full_name' => 'Ana', 'document_number' => '6060606060', 'phone' => '3001234567',
            'user_id' => $user->id, 'access_hash' => 'f'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);

        $result = $this->tool('get_app_account_status', [], ['member' => $member]);

        $this->assertTrue($result->data['has_account']);
        $this->assertFalse($result->data['membership_visible_in_app']);
        $this->assertSame('escalate_membership_not_reflected', $result->data['next_step']);
    }

    // ── Escalado ─────────────────────────────────────────────────────────────

    /** Ceder la conversación bloquea las oportunidades, no las pierde. */
    public function test_escalating_blocks_open_opportunities(): void
    {
        $opportunity = CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'goal' => V::GOAL_CLOSE_PLAN, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_RECOMMEND_PLAN, 'reason' => 'prueba', 'max_attempts' => 3,
        ]);

        $this->tool('escalate_to_human', ['reason' => 'frustration']);

        $opportunity->refresh();
        $this->assertSame(V::STATUS_BLOCKED, $opportunity->status);
        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }

    public function test_escalating_twice_is_harmless(): void
    {
        $this->tool('escalate_to_human', ['reason' => 'customer_request']);
        $second = $this->tool('escalate_to_human', ['reason' => 'customer_request']);

        $this->assertSame('skipped', $second->status);
    }
}
