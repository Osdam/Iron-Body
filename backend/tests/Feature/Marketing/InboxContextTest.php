<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\CommercialOpportunity;
use App\Models\CommercialToolInvocation;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El contexto que alimenta el panel derecho del Inbox V2.
 *
 * Dos cosas se prueban aquí y las dos importan por razones distintas:
 *
 *  · **Que traiga todo de una vez.** El panel cruza datos de siete sitios; con
 *    siete peticiones se rellenaría a trozos delante de alguien que está
 *    atendiendo a un cliente.
 *
 *  · **Que no traiga de más.** El diagnóstico técnico solo viaja a roles con
 *    visión completa, y ninguna sección puede devolver datos de otra persona.
 */
class InboxContextTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        Http::fake();

        $superAdmin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-ctx@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($superAdmin);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_INTERESTED,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp',
            'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    private function context(?int $id = null)
    {
        return $this->getJson(
            "/api/admin/marketing/inbox/conversations/".($id ?? $this->conversation->id)."/context",
            $this->adminHeaders(),
        );
    }

    private function member(string $document = '1010101010', ?int $userId = null): Member
    {
        return Member::create([
            'full_name' => 'Ana Prueba', 'document_number' => $document,
            'phone' => '3150536026', 'user_id' => $userId,
            'access_hash' => Str::random(40), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    // ── Forma de la respuesta ────────────────────────────────────────────────

    public function test_the_panel_gets_every_section_in_one_call(): void
    {
        $response = $this->context()->assertOk();

        foreach ([
            'customer', 'commercial', 'opportunity', 'payments', 'membership',
            'agenda', 'invoicing', 'app', 'activity',
        ] as $section) {
            $response->assertJsonPath("data.{$section}", fn ($v) => true, "Falta la sección {$section}.");
            $this->assertArrayHasKey($section, $response->json('data'));
        }
    }

    /** Un prospecto sin nada todavía no puede romper el panel. */
    public function test_an_empty_prospect_still_renders(): void
    {
        $data = $this->context()->assertOk()->json('data');

        $this->assertFalse($data['customer']['is_member']);
        $this->assertNull($data['opportunity']);
        $this->assertSame([], $data['payments']['transactions']);
        $this->assertFalse($data['membership']['active']);
    }

    // ── Cliente ──────────────────────────────────────────────────────────────

    public function test_a_member_shows_their_real_data(): void
    {
        $member = $this->member();
        $this->lead->forceFill(['member_id' => $member->id])->save();

        $data = $this->context()->assertOk()->json('data');

        $this->assertTrue($data['customer']['is_member']);
        $this->assertSame('1010101010', $data['customer']['document_number']);
        $this->assertSame($member->id, $data['customer']['member_id']);
    }

    /**
     * Fichas que se parecen pero no están enlazadas. Se enseñan para que una
     * persona decida: el sistema no fusiona por su cuenta.
     */
    public function test_lookalike_records_are_surfaced_not_merged(): void
    {
        $this->member('7070707070');

        $data = $this->context()->assertOk()->json('data');

        $this->assertFalse($data['customer']['is_member']);
        $this->assertCount(1, $data['customer']['ambiguous_matches']);
        $this->assertSame('phone', $data['customer']['ambiguous_matches'][0]['matched_by']);
    }

    // ── Oportunidad ──────────────────────────────────────────────────────────

    /** La evidencia y las exclusiones son lo que hace auditable una decisión. */
    public function test_the_opportunity_carries_its_reasoning(): void
    {
        CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'goal' => V::GOAL_CLOSE_PLAN, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_RECOMMEND_PLAN,
            'reason' => 'preguntó por precios dos veces',
            'exclusions' => ['no ofrecer anual: aún no ha venido'],
            'evidence' => ['attendances_last_30_days' => 0],
            'confidence' => 0.8, 'priority' => 60, 'max_attempts' => 3,
            'estimated_value' => 120000,
        ]);

        $opportunity = $this->context()->assertOk()->json('data.opportunity');

        $this->assertSame(V::GOAL_CLOSE_PLAN, $opportunity['goal']);
        $this->assertSame('preguntó por precios dos veces', $opportunity['reason']);
        $this->assertNotEmpty($opportunity['exclusions']);
        $this->assertNotEmpty($opportunity['evidence']);
        // JSON no distingue 120000 de 120000.0: se compara el valor, no el tipo.
        $this->assertEquals(120000, $opportunity['estimated_value']);
    }

    /** Una oportunidad cerrada no debe seguir mostrándose como activa. */
    public function test_a_closed_opportunity_is_not_shown(): void
    {
        CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'goal' => V::GOAL_CLOSE_PLAN, 'status' => V::STATUS_WON,
            'next_action' => V::ACTION_RECOMMEND_PLAN, 'reason' => 'ya cerrada', 'max_attempts' => 3,
        ]);

        $this->assertNull($this->context()->assertOk()->json('data.opportunity'));
    }

    // ── Pagos ────────────────────────────────────────────────────────────────

    public function test_payments_report_confirmation_explicitly(): void
    {
        $member = $this->member();
        $this->lead->forceFill(['member_id' => $member->id])->save();

        PaymentTransaction::create([
            'reference' => 'REF-1', 'idempotency_key' => 'i-1', 'member_id' => $member->id,
            'amount' => 120000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi',
            'checkout_url' => 'https://checkout.wompi.co/l/abc',
        ]);

        $payments = $this->context()->assertOk()->json('data.payments');

        $this->assertCount(1, $payments['transactions']);
        // Explícito, para que la interfaz no tenga que interpretar la cadena.
        $this->assertFalse($payments['transactions'][0]['confirmed']);
        $this->assertTrue($payments['has_pending_link']);
    }

    /** Ninguna sección puede devolver datos de otra persona. */
    public function test_another_persons_payments_never_leak(): void
    {
        $mine = $this->member('1111111111');
        $this->lead->forceFill(['member_id' => $mine->id])->save();

        $other = Member::create([
            'full_name' => 'Ajeno', 'document_number' => '9999999999', 'phone' => '3009998877',
            'access_hash' => Str::random(40), 'status' => Member::STATUS_ACTIVE,
        ]);
        PaymentTransaction::create([
            'reference' => 'REF-AJENO', 'idempotency_key' => 'i-2', 'member_id' => $other->id,
            'amount' => 500000, 'currency' => 'COP', 'status' => 'approved', 'provider' => 'wompi',
        ]);

        $payments = $this->context()->assertOk()->json('data.payments');

        $this->assertSame([], $payments['transactions']);
    }

    // ── Facturación y agenda: lo que NO se puede hacer ───────────────────────

    /** Emitir es acción fiscal sensible: la bandera obliga a pedir aprobación. */
    public function test_invoicing_declares_that_it_needs_human_approval(): void
    {
        $this->assertTrue($this->context()->assertOk()->json('data.invoicing.requires_human_approval'));
    }

    /**
     * La agenda dice qué acciones NO están autorizadas todavía. Sin esta lista,
     * la interfaz acabaría pintando botones que el backend no soporta.
     */
    public function test_the_agenda_declares_what_is_not_authorised_yet(): void
    {
        $pending = $this->context()->assertOk()->json('data.agenda.pending_authorization');

        $this->assertContains('reschedule', $pending);
        $this->assertContains('cancel', $pending);
    }

    // ── Aplicación ───────────────────────────────────────────────────────────

    /**
     * El caso que se resuelve mal por defecto: tiene cuenta y no ve su
     * membresía. Pedirle registrarse otra vez le crearía un segundo usuario.
     */
    public function test_an_account_without_visible_membership_is_flagged(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana-ctx@iron.test', 'password' => bcrypt('x')]);
        $member = $this->member('2222222222', $user->id);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        $app = $this->context()->assertOk()->json('data.app');

        $this->assertTrue($app['has_account']);
        $this->assertFalse($app['membership_synced']);
        $this->assertSame('membership_not_reflected', $app['issue']);
    }

    // ── Actividad ────────────────────────────────────────────────────────────

    public function test_the_timeline_mixes_facts_and_tools(): void
    {
        CommercialToolInvocation::create([
            'uuid' => Str::uuid()->toString(), 'tool' => 'create_payment_link',
            'idempotency_key' => 'k-'.Str::random(8),
            'marketing_lead_id' => $this->lead->id,
            'status' => CommercialToolInvocation::STATUS_SUCCEEDED,
            'reason' => 'prospecto pidió el enlace',
        ]);

        $items = $this->context()->assertOk()->json('data.activity.items');

        $this->assertNotEmpty($items);
        $this->assertSame('tool', $items[0]['kind']);
        $this->assertSame('create_payment_link', $items[0]['label']);
    }

    // ── Diagnóstico: permisos ────────────────────────────────────────────────

    public function test_a_full_role_sees_diagnostics(): void
    {
        $data = $this->context()->assertOk()->json('data');

        $this->assertArrayHasKey('diagnostics', $data);
        $this->assertArrayHasKey('flags', $data['diagnostics']);
    }

    /**
     * Recepción NO ve el diagnóstico técnico. No es un secreto: es ruido en una
     * pantalla que se usa con un cliente esperando al otro lado.
     */
    public function test_a_reception_role_does_not_see_diagnostics(): void
    {
        $reception = Admin::create([
            'name' => 'Recepción', 'email' => 'recepcion-ctx@ironbody.test',
            'password' => 'x', 'role' => 'recepcion', 'status' => 'active',
        ]);

        $response = $this->getJson(
            "/api/admin/marketing/inbox/conversations/{$this->conversation->id}/context",
            $this->actingAsAdmin($reception),
        )->assertOk();

        $this->assertArrayNotHasKey('diagnostics', $response->json('data'));
        // Pero sí ve todo lo que necesita para atender.
        $this->assertArrayHasKey('customer', $response->json('data'));
    }

    public function test_an_unknown_conversation_is_a_404(): void
    {
        $this->context(999999)->assertNotFound();
    }
}
