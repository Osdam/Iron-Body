<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingConversation;
use App\Models\MarketingConversationNote;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Marketing\ApprovedPaymentClaimer;
use App\Services\Marketing\PaymentClaimAcceptanceService;
use App\Services\Wompi\PaymentStateMachine as SM;
use App\Support\Access\RolePermissionPolicy;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La decisión del equipo sobre un pago huérfano del CRM.
 *
 * Contexto: alguien pagó por el link de WhatsApp siendo un prospecto (la
 * transacción queda APROBADA y sin `member_id`) y después se registró en la
 * app. {@see ApprovedPaymentClaimer} solo PROPONE el
 * enlace —el teléfono es una pista, no una prueba, porque el registro no
 * verifica el número— y deja la conversación en revisión. Hasta aquí no había
 * ninguna acción con la que una persona pudiera cerrar esa propuesta: quien
 * pagaba y se registraba se quedaba sin membresía.
 *
 * Esto cubre esa acción de punta a punta: quién puede ejecutarla, qué la frena
 * (fail-closed, una precondición por código de error) y qué queda escrito
 * cuando se ejecuta —el enlace, la membresía, la revisión resuelta y la nota
 * interna con el nombre de quien decidió—.
 *
 * El estado de partida se construye aquí a mano (transacción aprobada sin socio
 * + conversación en revisión) a propósito: cómo se levantó la propuesta es
 * asunto del proponente, y esta prueba debe fallar solo si la ACEPTACIÓN falla.
 */
class PaymentClaimAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** El teléfono compartido por el lead y el socio: la pista que se revisa. */
    private const PHONE = '3150536026';

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Member $member;

    private User $user;

    private array $adminHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);   // nada sale al exterior
        config()->set('marketing.ai.enabled', false);

        $this->adminHeaders = $this->adminAs(Admin::ROLE_ADMINISTRADOR);

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'benefits' => json_encode(['Acceso ilimitado']),
        ]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => self::PHONE,
            'meta_user_id' => '57'.self::PHONE, 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_HOT,
        ]);

        // La conversación en el estado que deja el proponente: revisión pendiente.
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
            'staff_review_pending' => true,
            'staff_review_reason' => 'payment_claim_candidate',
        ]);

        // Quien se registró en la app: socio CON usuario (la membresía vive en
        // el usuario; sin él no hay nada que activar).
        $this->user = User::create([
            'name' => 'Nuevo Socio', 'email' => 'nuevo@ironbody.test',
            'password' => 'secret-password',
        ]);
        $this->member = Member::create([
            'user_id' => $this->user->id, 'full_name' => 'Nuevo Socio',
            'email' => 'nuevo@ironbody.test', 'document_number' => '87654321',
            'phone' => '+57'.self::PHONE, 'access_hash' => 'tok-claim',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Un pago del link del CRM tal y como queda tras aprobarse sin socio: la
     * clave de idempotencia lleva el prefijo que construye WompiPaymentLinkService.
     */
    private function claim(array $override = []): PaymentTransaction
    {
        $n = PaymentTransaction::count() + 1;

        return PaymentTransaction::create(array_merge([
            'reference' => 'IB-MKT-'.$n,
            'idempotency_key' => 'mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-'.$n,
            'plan_id' => $this->plan->id,
            'member_id' => null,
            'user_id' => null,
            'amount' => 80000,
            'currency' => 'COP',
            'status' => SM::APPROVED,
            'provider' => 'wompi',
            'method' => 'web_checkout',
            'paid_at' => now(),
            'metadata' => ['marketing_lead_id' => $this->lead->id, 'source' => 'marketing_agent'],
        ], $override));
    }

    private function accept(PaymentTransaction $tx, array $payload = [], ?array $headers = null): TestResponse
    {
        return $this->postJson(
            '/api/admin/marketing/inbox/payment-claims/'.$tx->id.'/accept',
            array_merge(['member_id' => $this->member->id], $payload),
            $headers ?? $this->adminHeaders,
        );
    }

    private function reject(PaymentTransaction $tx, array $payload = [], ?array $headers = null): TestResponse
    {
        return $this->postJson(
            '/api/admin/marketing/inbox/payment-claims/'.$tx->id.'/reject',
            array_merge(['reason' => 'El socio dice que ese pago no es suyo.'], $payload),
            $headers ?? $this->adminHeaders,
        );
    }

    private function notes(): Collection
    {
        return MarketingConversationNote::where('conversation_id', $this->conversation->id)->orderBy('id')->get();
    }

    // ── El camino feliz ───────────────────────────────────────────────────────

    public function test_accepting_links_the_payment_and_activates_the_membership(): void
    {
        $tx = $this->claim();

        $this->accept($tx, ['note' => 'Verificado por cédula en recepción.'])
            ->assertOk()
            ->assertJsonPath('data.transaction_id', $tx->id)
            ->assertJsonPath('data.member_id', $this->member->id)
            ->assertJsonPath('data.matched_by', PaymentClaimAcceptanceService::MATCH_PHONE)
            ->assertJsonPath('data.forced', false)
            ->assertJsonPath('data.membership.activated', true);

        // 1. La transacción queda enlazada al socio y a su usuario.
        $tx->refresh();
        $this->assertSame($this->member->id, $tx->member_id);
        $this->assertSame($this->user->id, $tx->user_id);

        // 2. Queda escrito QUIÉN lo decidió y CON QUÉ prueba (auditoría).
        $this->assertSame(
            (int) Admin::where('role', Admin::ROLE_ADMINISTRADOR)->value('id'),
            (int) $tx->metadata['claim']['accepted_by'],
        );
        $this->assertSame(PaymentClaimAcceptanceService::MATCH_PHONE, $tx->metadata['claim']['matched_by']);
        $this->assertFalse($tx->metadata['claim']['forced']);
        $this->assertNotEmpty($tx->metadata['claim']['accepted_at']);
        // La metadata previa no se pisa.
        $this->assertSame('marketing_agent', $tx->metadata['source']);

        // 3. El pago legado nace como PASARELA y SIN turno de caja: este dinero
        //    no pasó por el cajón y no puede ensuciar un arqueo.
        $payment = Payment::where('reference', $tx->reference)->sole();
        $this->assertSame(PaymentOrigin::GATEWAY->value, $payment->origin);
        $this->assertNull($payment->cash_shift_id);
        $this->assertSame($this->user->id, $payment->user_id);

        // 4. La membresía existe de verdad: 30 días desde hoy.
        $this->assertSame(
            now(Member::BUSINESS_TZ)->addDays(30)->toDateString(),
            $this->user->fresh()->membership_end_date,
        );
        $this->assertSame(Member::STATUS_ACTIVE, $this->member->fresh()->status);

        // 5. El lead queda enlazado al socio.
        $this->assertSame($this->member->id, $this->lead->fresh()->member_id);

        // 6. La revisión se cierra y queda la nota interna con el actor.
        $this->conversation->refresh();
        $this->assertFalse($this->conversation->staff_review_pending);
        $this->assertNotNull($this->conversation->staff_review_resolved_at);

        $note = $this->notes()->last();
        $this->assertStringContainsString('payment_claim_accepted', (string) $note->body);
        $this->assertStringContainsString('Verificado por cédula en recepción.', (string) $note->body);
        $this->assertNotNull($note->author_admin_id);
    }

    public function test_accepting_twice_does_not_activate_a_second_membership(): void
    {
        $tx = $this->claim();
        $this->accept($tx)->assertOk();

        $end = $this->user->fresh()->membership_end_date;

        $this->accept($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_claimed');

        // Ni un día de más, ni un segundo pago legado.
        $this->assertSame($end, $this->user->fresh()->membership_end_date);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    // ── Lo que frena la aceptación ────────────────────────────────────────────

    public function test_a_payment_that_is_not_approved_cannot_be_claimed(): void
    {
        $tx = $this->claim(['status' => SM::PENDING]);

        $this->accept($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'claim_not_approved');

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /**
     * El endpoint NO es una puerta para enlazar cualquier pago a cualquier
     * socio: solo trabaja sobre el dinero que nació de un link del CRM.
     */
    public function test_a_payment_outside_the_crm_links_cannot_be_claimed(): void
    {
        $tx = $this->claim(['idempotency_key' => 'app-checkout-'.uniqid()]);

        $this->accept($tx)
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_a_marketing_claim');

        $this->assertNull($tx->fresh()->member_id);
    }

    public function test_an_old_payment_no_longer_explains_a_registration(): void
    {
        $tx = $this->claim();
        $tx->forceFill(['created_at' => now()->subDays(PaymentClaimAcceptanceService::CLAIM_WINDOW_DAYS + 1)])->saveQuietly();

        $this->accept($tx)
            ->assertStatus(422)
            ->assertJsonPath('code', 'claim_expired');
    }

    public function test_a_member_without_an_app_user_has_no_membership_to_activate(): void
    {
        $huerfano = Member::create([
            'full_name' => 'Sin Usuario', 'document_number' => '11112222',
            'phone' => '+57'.self::PHONE, 'access_hash' => 'tok-sin-user',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);

        $this->accept($this->claim(), ['member_id' => $huerfano->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'member_without_user');
    }

    /**
     * El número es la única pista del proponente. Si no coincide, no se enlaza
     * sin que alguien lo firme: hacerlo en silencio es justo el agujero que
     * cerró el revisor.
     */
    public function test_a_different_phone_is_refused_and_only_a_forced_decision_passes(): void
    {
        $this->member->forceFill(['phone' => '+573001112233'])->save();
        $tx = $this->claim();

        $this->accept($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'phone_mismatch');
        $this->assertNull($tx->fresh()->member_id);

        $this->accept($tx, ['force' => true, 'note' => 'Vino con el comprobante y la cédula.'])
            ->assertOk()
            ->assertJsonPath('data.forced', true)
            ->assertJsonPath('data.matched_by', PaymentClaimAcceptanceService::MATCH_STAFF_OVERRIDE);

        $tx->refresh();
        $this->assertSame($this->member->id, $tx->member_id);
        // Que fue forzado queda escrito: es la diferencia entre un enlace
        // verificado y uno que alguien decidió bajo su responsabilidad.
        $this->assertTrue($tx->metadata['claim']['forced']);
        $this->assertSame(PaymentClaimAcceptanceService::MATCH_STAFF_OVERRIDE, $tx->metadata['claim']['matched_by']);
        $this->assertStringContainsString('"forced":true', (string) $this->notes()->last()->body);
    }

    /**
     * Dos operadores mirando la misma alerta no pueden regalar dos meses. La
     * segunda transacción del mismo plano se frena aunque sea otra fila.
     */
    public function test_a_second_claim_of_the_same_plan_within_the_week_is_refused(): void
    {
        $this->accept($this->claim())->assertOk();

        // Aceptar cierra la revisión, así que para llegar al freno del duplicado
        // hay que volver a tener una propuesta viva: es lo que pasaría de verdad
        // si otro registro con el mismo número levantara la alerta otra vez.
        $this->reopenProposal();

        $this->accept($this->claim())
            ->assertStatus(409)
            ->assertJsonPath('code', 'recent_duplicate_claim');

        $this->assertSame(1, Payment::count());
    }

    /** Sin propuesta viva no hay nada que aceptar: el endpoint no es una vía manual. */
    public function test_accepting_something_nobody_proposed_is_refused(): void
    {
        $tx = $this->claim();
        $this->conversation->forceFill(['staff_review_pending' => false, 'staff_review_reason' => null])->save();

        $this->accept($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_live_proposal');

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /** Saltarse la prueba de identidad exige responder por ello: rol pleno. */
    public function test_forcing_the_link_needs_a_full_role(): void
    {
        $tx = $this->claim();
        $headers = $this->commercialWithMoneyKey();

        $this->accept($tx, ['force' => true], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'force_requires_full_role');

        $this->assertNull($tx->fresh()->member_id, 'forzar no enlazó nada');
        $this->assertSame(0, Payment::count());

        // Y sin forzar, ese mismo rol sí puede aceptar el reclamo propuesto:
        // lo que se le niega es saltarse la prueba de identidad, no trabajar.
        $this->accept($tx, [], $headers)->assertOk();
        $this->assertSame($this->member->id, $tx->fresh()->member_id);
    }

    /**
     * Descartar deja rastro EN LA TRANSACCIÓN, no solo en una nota.
     *
     * Sin eso, «este pago no es de esta persona» y aceptarlo acto seguido eran
     * compatibles y nada avisaba.
     */
    public function test_rejecting_leaves_a_trace_that_a_later_accept_has_to_face(): void
    {
        $tx = $this->claim();

        $this->reject($tx, ['reason' => 'El socio dice que ese pago no es suyo.'])->assertOk();

        $rechazos = $tx->fresh()->metadata['claim_rejections'] ?? [];
        $this->assertCount(1, $rechazos);
        $this->assertSame('El socio dice que ese pago no es suyo.', $rechazos[0]['reason']);

        $this->reopenProposal();
        $this->accept($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'claim_already_rejected');

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /** Quien responde por ello sí puede revertir un descarte. */
    public function test_a_full_role_can_still_force_a_rejected_claim(): void
    {
        $tx = $this->claim();
        $this->reject($tx, ['reason' => 'Me equivoqué de socio.'])->assertOk();

        $this->accept($tx, ['force' => true])->assertOk();

        $this->assertSame($this->member->id, $tx->fresh()->member_id);
    }

    /** Levantar una suspensión es otra decisión, y no se toma desde un reclamo. */
    public function test_a_suspended_member_is_not_reactivated_through_a_claim(): void
    {
        $this->member->forceFill(['status' => Member::STATUS_SUSPENDED])->save();

        $this->accept($this->claim())
            ->assertStatus(409)
            ->assertJsonPath('code', 'member_suspended');

        $this->assertSame(Member::STATUS_SUSPENDED, $this->member->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    /**
     * Una alerta de OTRO motivo no desaparece como efecto colateral de una
     * decisión de pago.
     */
    public function test_a_review_about_something_else_survives_the_decision(): void
    {
        $tx = $this->claim();
        $this->conversation->forceFill(['staff_review_reason' => 'human_handoff_requested'])->save();

        $this->accept($tx, ['force' => true])->assertOk();

        $c = $this->conversation->fresh();
        $this->assertTrue((bool) $c->staff_review_pending, 'la alerta ajena sigue en pie');
        $this->assertSame('human_handoff_requested', $c->staff_review_reason);
        $this->assertStringContainsString('payment_claim_accepted', (string) $this->notes()->last()->body);
    }

    // ── Rechazo ───────────────────────────────────────────────────────────────

    public function test_rejecting_closes_the_review_without_touching_the_money(): void
    {
        $tx = $this->claim();

        $this->reject($tx, ['reason' => 'El socio dice que ese pago no es suyo.'])
            ->assertOk()
            ->assertJsonPath('data.transaction_id', $tx->id);

        // El dinero se queda exactamente donde estaba.
        $tx->refresh();
        $this->assertNull($tx->member_id);
        $this->assertNull($tx->user_id);
        $this->assertArrayNotHasKey('claim', (array) $tx->metadata);
        $this->assertSame(0, Payment::count());
        $this->assertNull($this->user->fresh()->membership_end_date);

        // Pero la alerta deja de estar abierta, y el motivo queda escrito.
        $this->conversation->refresh();
        $this->assertFalse($this->conversation->staff_review_pending);
        $note = $this->notes()->last();
        $this->assertStringContainsString('payment_claim_rejected', (string) $note->body);
        $this->assertStringContainsString('no es suyo', (string) $note->body);
    }

    public function test_a_claim_already_accepted_cannot_be_rejected_afterwards(): void
    {
        $tx = $this->claim();
        $this->accept($tx)->assertOk();

        $this->reject($tx)
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_claimed');
    }

    // ── Quién puede ejecutarla ────────────────────────────────────────────────

    public function test_without_a_session_nobody_accepts_anything(): void
    {
        $tx = $this->claim();

        $this->postJson('/api/admin/marketing/inbox/payment-claims/'.$tx->id.'/accept', [
            'member_id' => $this->member->id,
        ])->assertStatus(401);

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /**
     * Primera puerta, la misma de todo /api/admin/*: sin la llave del módulo
     * de mercadeo no se llega ni al controlador.
     */
    public function test_a_role_without_the_marketing_key_never_reaches_the_action(): void
    {
        $tx = $this->claim();
        $headers = $this->adminAs('Entrenador');

        $this->accept($tx, [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->reject($tx, [], $headers)->assertStatus(403);

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /**
     * Tener la llave del módulo NO basta, y ahora ni siquiera se llega a la
     * puerta del Inbox: aceptar CREA un cobro y encola una factura ante la
     * DIAN, así que exige la llave de quien cobra. Una revisión de seguridad
     * demostró que un rol con solo `marketing.manage` acuñaba membresía y
     * factura; esto es ese agujero cerrado.
     *
     * Descartar es otra cosa: no mueve dinero, así que sigue bastando con la
     * capacidad del Inbox, y por eso ahí el motivo del rechazo es el del Inbox.
     */
    public function test_the_marketing_key_alone_can_neither_accept_nor_resolve(): void
    {
        $tx = $this->claim();
        $headers = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);

        $this->accept($tx, [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'payments.create');

        $this->reject($tx, [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'inbox_forbidden');

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame(0, Payment::count());
    }

    /** Vuelve a dejar la alerta del reclamo en pie, como si otro registro la levantara. */
    private function reopenProposal(): void
    {
        MarketingConversation::whereKey($this->conversation->id)->update([
            'staff_review_pending' => true,
            'staff_review_reason' => ApprovedPaymentClaimer::REVIEW_REASON,
        ]);
    }

    /**
     * Sesión de un rol COMERCIAL con la llave de cobro.
     *
     * Es el caso que importa para `force`: puede resolver revisiones del Inbox
     * (los roles comerciales tienen todas las capacidades menos asignar) y
     * ahora también cobrar, pero no es un rol pleno. Justo quien no debería
     * poder saltarse la única prueba de identidad del flujo.
     *
     * @return array<string,string>
     */
    private function commercialWithMoneyKey(): array
    {
        AdminRole::firstOrCreate(['name' => 'ventas'], ['is_system' => false]);
        RolePermission::updateOrCreate(
            ['role' => 'ventas', 'permission' => 'payments.create'],
            ['granted' => true],
        );
        app(RolePermissionPolicy::class)->flush();

        return $this->adminAs('ventas');
    }

    public function test_an_unknown_transaction_is_a_404(): void
    {
        $this->postJson('/api/admin/marketing/inbox/payment-claims/999999/accept', [
            'member_id' => $this->member->id,
        ], $this->adminHeaders)->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_an_unknown_member_is_rejected_by_validation(): void
    {
        $this->accept($this->claim(), ['member_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_id');
    }

    // ── Observabilidad ────────────────────────────────────────────────────────

    /**
     * El log del canal es el rastro que se lee en producción, y ahí no entran
     * datos personales: solo identificadores.
     */
    public function test_the_log_of_an_accepted_claim_carries_ids_and_nothing_else(): void
    {
        Log::spy();
        $captured = null;

        $this->accept($this->claim())->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($event, $context = []) use (&$captured) {
            if ($event !== 'marketing.payment_claim.accepted') {
                return false;
            }
            $captured = $context;

            return true;
        });

        $this->assertIsArray($captured);
        $plano = json_encode($captured) ?: '';
        $this->assertStringNotContainsString(self::PHONE, $plano);
        $this->assertStringNotContainsString('Nuevo Socio', $plano);
        $this->assertStringNotContainsString('nuevo@ironbody.test', $plano);
        $this->assertStringNotContainsString('87654321', $plano);
    }
}
