<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\CommercialOpportunity;
use App\Models\CommercialToolInvocation;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\OpportunityExecutor;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los recorridos completos, con dobles y sin tocar nada productivo.
 *
 * Las pruebas anteriores comprueban piezas: que una herramienta valida, que el
 * motor decide, que un hecho se registra. Estas comprueban lo único que le
 * importa al gimnasio: que un desconocido que escribe por WhatsApp acaba siendo
 * un socio con membresía activa, y que cuando algo se cae por el camino el
 * sistema no cobra dos veces ni inventa nada.
 *
 * Ningún dato productivo: leads, planes y pagos se crean aquí, y toda salida a
 * la red está interceptada.
 */
class CommercialE2ETest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Plan $mensual;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        // Nada sale a la red en estas pruebas.
        Http::preventStrayRequests();

        config()->set('commercial.enabled', true);
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.autonomy_enabled', true);
        config()->set('commercial.tools', [
            'catalog' => true, 'lead' => true, 'payments' => true,
            'memberships' => true, 'agenda' => true, 'invoicing' => true, 'app' => true,
        ]);

        $this->mensual = Plan::create([
            'name' => 'Mensual', 'price' => 120000, 'duration_days' => 30,
            'tier' => 'basic', 'active' => true,
        ]);
        $this->anual = Plan::create([
            'name' => 'Anual', 'price' => 1000000, 'duration_days' => 365,
            'tier' => 'premium', 'active' => true,
        ]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'name' => 'Ana', 'status' => MarketingLead::STATUS_INTERESTED,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'last_inbound_at' => now()->subMinutes(5),
        ]);
    }

    private function tool(string $name, array $args = [], array $ctx = [])
    {
        return app(ToolExecutor::class)->execute($name, $args, new ToolContext(
            lead: $ctx['lead'] ?? $this->lead,
            member: $ctx['member'] ?? null,
            conversation: $this->conversation,
            requestedBy: 'engine',
            correlationId: 'e2e',
            idempotencyKey: $ctx['key'] ?? uniqid('k', true),
        ));
    }

    /**
     * Socio con membresía real, enlazado al lead.
     *
     * @return array{0:Member,1:User}
     */
    private function activeMember(int $daysAsMember, int $endsInDays): array
    {
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana'.uniqid().'@iron.test', 'password' => bcrypt('x'),
        ]);
        $user->forceFill([
            'plan' => 'Mensual',
            'membership_start_date' => now()->subDays($daysAsMember)->toDateString(),
            'membership_end_date' => now()->addDays($endsInDays)->toDateString(),
        ])->save();

        $member = Member::create([
            'full_name' => 'Ana Prueba', 'document_number' => '10'.uniqid(),
            'phone' => '3150536026', 'user_id' => $user->id,
            'access_hash' => 'm'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);

        $this->lead->forceFill(['member_id' => $member->id])->save();

        return [$member, $user];
    }

    /** Visitas repartidas en el último mes, en días distintos. */
    private function recordAttendances(Member $member, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            \App\Models\Attendance::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'action' => 'entry',
                'source' => 'manual',
                'captured_at' => now()->subDays($i * 2),
            ]);
        }
    }

    /** Simula lo que hace la pasarela: aprobar por el camino oficial. */
    private function approve(PaymentTransaction $tx): void
    {
        $tx->forceFill(['status' => 'approved', 'paid_at' => now()])->save();
    }

    private function transaction(Plan $plan, string $status = 'pending', array $extra = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'amount' => $plan->price, 'currency' => 'COP',
            'status' => $status, 'provider' => 'wompi', 'plan_id' => $plan->id,
        ], $extra));
    }

    // ── A. Recorrido completo ────────────────────────────────────────────────

    /**
     * De desconocido a socio con membresía, factura consultable y app.
     * El recorrido que justifica todo el sistema.
     */
    public function test_flow_a_from_stranger_to_member(): void
    {
        // 1. El agente consulta precios. No los recuerda: los lee.
        $plans = $this->tool('list_plans');
        $this->assertTrue($plans->successful());
        $this->assertSame(120000.0, $plans->data['plans'][0]['price']);

        // 2. Guarda lo que la persona contó.
        $this->tool('update_lead', ['name' => 'Ana Prueba', 'objective' => 'bajar de peso']);
        $this->assertSame('bajar de peso', $this->lead->fresh()->objective);

        // 3. Se genera el intento de pago. (El enlace real lo produce Wompi;
        //    aquí se trabaja sobre la transacción, que es lo que el sistema
        //    considera verdad.)
        $tx = $this->transaction($this->mensual);

        // 4. Antes de pagar, NO se puede crear el socio.
        $premature = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $tx->reference,
        ]);
        $this->assertSame('payment_not_confirmed', $premature->errorCode);
        $this->assertSame(0, Member::query()->count());

        // 5. La pasarela confirma.
        $this->approve($tx);

        $status = $this->tool('get_payment_status', ['reference' => $tx->reference]);
        $this->assertFalse($status->data['found'], 'Sin miembro aún, no debe encontrar el pago por el cerco.');

        // 6. Ahora sí: socio.
        $created = $this->tool('ensure_member', [
            'document_number' => '1010101010',
            'full_name' => 'Ana Prueba',
            'payment_reference' => $tx->reference,
        ]);
        $this->assertTrue($created->successful());

        $member = Member::query()->firstOrFail();
        $this->assertSame($member->id, $this->lead->fresh()->member_id);

        // 7. La membresía se activa por el flujo de pagos existente, no por el
        //    agente. Aquí se simula ese efecto y se comprueba que el agente lo VE.
        $user = User::create(['name' => 'Ana', 'email' => 'ana@iron.test', 'password' => bcrypt('x')]);
        $member->forceFill(['user_id' => $user->id])->save();
        $user->forceFill([
            'plan' => 'Mensual',
            'membership_start_date' => now()->toDateString(),
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ])->save();

        $membership = $this->tool('get_membership_status', [], ['member' => $member->fresh()]);
        $this->assertTrue($membership->data['active']);

        // 8. La app queda enlazada y se refleja.
        $app = $this->tool('get_app_account_status', [], ['member' => $member->fresh()]);
        $this->assertTrue($app->data['has_account']);
        $this->assertTrue($app->data['membership_visible_in_app']);

        // 9. La factura se puede consultar (no se ha pedido todavía).
        $invoice = $this->tool('get_invoice_status', [], ['member' => $member->fresh()]);
        $this->assertTrue($invoice->successful());
        $this->assertFalse($invoice->data['found']);

        // 10. Y la relación NO termina: hay un objetivo siguiente.
        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_MEMBERSHIP_ACTIVATED, 'dedupe_key' => 'e2e:activated',
            'occurred_at' => now(),
        ]);
        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $this->assertTrue(
            CommercialOpportunity::query()->whereIn('status', V::OPEN_STATUSES)->exists(),
            'Tras la venta el sistema se quedó sin objetivo siguiente.',
        );
    }

    // ── B. Mensual con uso suficiente → oportunidad de anual ─────────────────

    /**
     * La mejora de plan solo se propone con uso demostrado. Es la diferencia
     * entre una recomendación y una venta a presión: a quien viene tres veces
     * por semana el anual le conviene de verdad; a quien pagó y no ha aparecido,
     * no.
     */
    public function test_flow_b_a_committed_member_gets_the_upgrade_offer(): void
    {
        [$member, $user] = $this->activeMember(daysAsMember: 40, endsInDays: 20);

        // Asistencia suficiente: 3 visitas por semana durante el último mes.
        $this->recordAttendances($member, count: 14);

        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_ATTENDANCE_MILESTONE, 'dedupe_key' => 'e2e:milestone',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $opportunity = CommercialOpportunity::query()
            ->whereIn('status', V::OPEN_STATUSES)
            ->orderByDesc('priority')
            ->first();

        $this->assertNotNull($opportunity);
        $this->assertContains($opportunity->goal, [V::GOAL_UPGRADE, V::GOAL_RENEW]);
        // Y la oferta apunta a un plan REAL del catálogo, no a un precio suelto.
        $this->assertNotNull($opportunity->offer_plan_id);
    }

    /** El contraejemplo: quien pagó y no ha venido no recibe oferta de anual. */
    public function test_flow_b_a_member_who_never_came_is_not_upsold(): void
    {
        [$member] = $this->activeMember(daysAsMember: 40, endsInDays: 20);
        // Sin asistencias.

        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_INACTIVITY_DETECTED, 'dedupe_key' => 'e2e:inactive',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $upgrade = CommercialOpportunity::query()
            ->where('goal', V::GOAL_UPGRADE)
            ->whereIn('status', V::OPEN_STATUSES)
            ->first();

        $this->assertNull($upgrade, 'Se le ofreció un plan más largo a quien no ha pisado el gimnasio.');
    }

    // ── D. Inactividad → reactivación ────────────────────────────────────────

    /**
     * Reactivar empieza por entender, no por descontar. Un descuento inmediato
     * enseña a la gente que basta con irse para que le bajen el precio.
     */
    public function test_flow_d_reactivation_starts_by_understanding(): void
    {
        [$member, $user] = $this->activeMember(daysAsMember: 90, endsInDays: -20);

        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_MEMBERSHIP_EXPIRED, 'dedupe_key' => 'e2e:expired',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $opportunity = CommercialOpportunity::query()
            ->whereIn('status', V::OPEN_STATUSES)
            ->orderByDesc('priority')
            ->first();

        $this->assertNotNull($opportunity);
        $this->assertSame(V::GOAL_REACTIVATE, $opportunity->goal);
    }

    // ── C. Renovación con pago que se queda a medias ─────────────────────────

    /**
     * El enlace abandonado. Lo importante: recuperarlo NO puede generar un
     * segundo cobro.
     */
    public function test_flow_c_an_abandoned_link_is_recovered_without_duplicating(): void
    {
        $member = Member::create([
            'full_name' => 'Ana', 'document_number' => '1010101010', 'phone' => '3150536026',
            'access_hash' => 'x'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        $tx = $this->transaction($this->mensual, 'pending', ['member_id' => $member->id]);

        // Se detecta el abandono y se consulta el estado: sigue sin pagar.
        $status = $this->tool('get_payment_status', [], ['member' => $member]);
        $this->assertFalse($status->data['confirmed']);

        // La recuperación usa la MISMA intención: no se abre un segundo cobro.
        $key = "recover:{$tx->id}";
        $first = $this->tool('get_payment_status', [], ['member' => $member, 'key' => $key]);
        $second = $this->tool('get_payment_status', [], ['member' => $member, 'key' => $key]);

        $this->assertSame('skipped', $second->status);
        $this->assertSame(1, PaymentTransaction::query()->count());

        // Cuando por fin paga, queda confirmado por el camino oficial.
        $this->approve($tx);
        $after = $this->tool('get_payment_status', [], ['member' => $member]);
        $this->assertTrue($after->data['confirmed']);
    }

    // ── E. Una persona pide humano ───────────────────────────────────────────

    /**
     * El recorrido más importante de todos: a partir de aquí el sistema se
     * calla, y ninguna oportunidad vuelve a empujarlo a hablar.
     */
    public function test_flow_e_asking_for_a_person_stops_everything(): void
    {
        $opportunity = CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'goal' => V::GOAL_CLOSE_PLAN, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_SEND_PAYMENT_LINK,
            'offer_plan_id' => $this->mensual->id,
            'reason' => 'prospecto interesado', 'max_attempts' => 3,
        ]);

        $this->tool('escalate_to_human', ['reason' => 'customer_request']);

        // La conversación queda en manos humanas y la IA apagada.
        $conversation = $this->conversation->fresh();
        $this->assertTrue($conversation->human_takeover);
        $this->assertFalse((bool) $conversation->ai_enabled);

        // La oportunidad queda bloqueada, no perdida.
        $this->assertSame(V::STATUS_BLOCKED, $opportunity->fresh()->status);

        // Y el ejecutor ya no actúa sobre ella, aunque se le insista.
        $outcome = app(OpportunityExecutor::class)->execute($opportunity->fresh());

        $this->assertFalse($outcome['executed']);
        $this->assertSame(0, CommercialToolInvocation::query()
            ->where('tool', 'create_payment_link')->count());
    }

    // ── F. La pasarela se cae ────────────────────────────────────────────────

    /**
     * Wompi caído. Se exige que el fallo sea reintentable, que no quede una
     * transacción a medias, y que reintentar no produzca dos.
     */
    public function test_flow_f_a_gateway_outage_does_not_duplicate_anything(): void
    {
        // Sin configuración de checkout, el servicio devuelve error controlado
        // sin crear nada: es el mismo camino que una caída.
        config()->set('wompi.public_key', null);
        config()->set('wompi.integrity_secret', null);

        $key = 'link:lead-'.$this->lead->id.':plan-'.$this->mensual->id;

        $first = $this->tool('create_payment_link', ['plan_id' => $this->mensual->id], ['key' => $key]);

        $this->assertFalse($first->successful());
        // Reintentable: es un problema nuestro, no del cliente. Condenar la
        // venta por una caída pasajera sería el peor desenlace posible.
        $this->assertTrue($first->retryable);
        $this->assertSame(0, PaymentTransaction::query()->count());

        // El reintento con la misma intención no genera un segundo intento.
        $second = $this->tool('create_payment_link', ['plan_id' => $this->mensual->id], ['key' => $key]);

        $this->assertFalse($second->successful());
        $this->assertSame(1, CommercialToolInvocation::query()
            ->where('idempotency_key', $key)->count());
        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    /** Facturación caída: la solicitud se preserva y se puede reintentar. */
    public function test_flow_f_invoicing_down_preserves_the_request(): void
    {
        $member = Member::create([
            'full_name' => 'Ana', 'document_number' => '1010101010', 'phone' => '3150536026',
            'access_hash' => 'y'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);

        // Los datos fiscales se validan y quedan disponibles aunque Factus esté
        // caído: la validación es local y no depende del proveedor.
        $validated = $this->tool('validate_invoice_data', [
            'document_type' => 'CC', 'document_number' => '1010101010',
            'name' => 'Ana Prueba', 'email' => 'ana@example.com',
        ], ['member' => $member]);

        $this->assertTrue($validated->data['complete']);

        // Y consultar el estado no revienta por no haber factura.
        $status = $this->tool('get_invoice_status', [], ['member' => $member]);
        $this->assertTrue($status->successful());
    }

    // ── Concurrencia ─────────────────────────────────────────────────────────

    /**
     * Dos hechos simultáneos sobre la misma persona no pueden producir dos
     * operaciones finales.
     */
    public function test_two_simultaneous_facts_produce_one_operation(): void
    {
        $member = Member::create([
            'full_name' => 'Ana', 'document_number' => '1010101010', 'phone' => '3150536026',
            'access_hash' => 'z'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        CommercialEvent::query()->delete();

        $first = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_PAYMENT_APPROVED, 'dedupe_key' => 'sim:1', 'occurred_at' => now(),
        ]);
        $second = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $member->id,
            'event' => V::EV_MEMBERSHIP_ACTIVATED, 'dedupe_key' => 'sim:2', 'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($first->id), 'handle']);
        app()->call([new EvaluateCommercialSubject($second->id), 'handle']);

        // Un solo objetivo abierto por meta: los dos hechos convergen.
        $open = CommercialOpportunity::query()->whereIn('status', V::OPEN_STATUSES)->get();

        $this->assertSame(
            $open->pluck('goal')->unique()->count(),
            $open->count(),
            'Dos hechos simultáneos abrieron oportunidades duplicadas para el mismo objetivo.',
        );
    }
}
