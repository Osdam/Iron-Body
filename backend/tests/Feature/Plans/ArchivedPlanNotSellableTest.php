<?php

namespace Tests\Feature\Plans;

use App\Enums\CashShiftType;
use App\Exceptions\SubscriptionException;
use App\Models\Admin;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Subscriptions\MembershipSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UN PLAN ARCHIVADO NO SE VENDE POR NINGUNA VÍA. Cada prueba ataca una puerta
 * con el plan archivado y comprueba que se cierra sin escribir nada; el mismo
 * intento con un plan normal pasa (o llega más lejos), para que el rechazo sea
 * por el archivo y no por otra cosa.
 */
class ArchivedPlanNotSellableTest extends TestCase
{
    use RefreshDatabase;

    private Plan $archivado;

    private Plan $vigente;

    private Plan $barato;

    private Admin $super;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00', 'America/Bogota')->utc());
        $this->super = Admin::create(['name' => 'Caja', 'email' => 'caja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->super);
        $this->openCashShift($this->super, CashShiftType::GYM);
        $this->barato = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->vigente = Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'active' => true,
            'sellable' => true, 'visible_in_app' => true]);
        $this->archivado = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        $this->postJson("/api/plans/{$this->archivado->id}/archive", ['reason' => 'Plan heredado, ya no se vende'], $this->h)->assertOk();
        $this->archivado->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cobro_en_mostrador(): void
    {
        $socio = $this->socio();

        $this->postJson('/api/payments', $this->cobro($socio, $this->archivado), $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->assertSame(0, Payment::count());

        $this->postJson('/api/payments', $this->cobro($socio, $this->vigente), $this->h)->assertCreated();
    }

    public function test_plan_a_plazos(): void
    {
        $socio = $this->socio();
        $cuerpo = fn (Plan $p) => ['user_id' => $socio->id, 'plan_id' => $p->id, 'first_payment' => 50000, 'method' => 'cash'];

        $this->postJson('/api/admin/receivables/plan', $cuerpo($this->archivado), $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->assertSame(0, Payment::count());

        $this->postJson('/api/admin/receivables/plan', $cuerpo($this->vigente), $this->h)->assertCreated();
    }

    public function test_mejora_de_plan(): void
    {
        $socio = $this->socio();
        $this->postJson('/api/payments', $this->cobro($socio, $this->barato), $this->h)->assertCreated();

        $this->getJson("/api/users/{$socio->id}/plan-upgrade?plan_id={$this->archivado->id}", $this->h)->assertOk()
            ->assertJsonPath('can_upgrade', false)
            ->assertJsonPath('reason', 'Ese plan está archivado: ya no se vende.');
        $this->postJson("/api/users/{$socio->id}/plan-upgrade", ['plan_id' => $this->archivado->id, 'method' => 'cash'], $this->h)
            ->assertStatus(422);
        $this->assertSame(1, Payment::count());

        $this->getJson("/api/users/{$socio->id}/plan-upgrade?plan_id={$this->vigente->id}", $this->h)->assertOk()
            ->assertJsonPath('can_upgrade', true);
    }

    public function test_compra_desde_la_app_por_la_pasarela(): void
    {
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'api_url' => 'https://sandbox.wompi.co/v1', 'public_key' => 'pub_test_x',
            'private_key' => 'prv_test_x', 'integrity_secret' => 'test_integrity_xyz', 'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $ficha = Member::where('user_id', $this->socio()->id)->firstOrFail();

        $this->postJson('/api/payments/wompi/pse', [
            'plan_id' => $this->archivado->id, 'financial_institution_code' => '1809', 'user_type' => 'natural',
            'accepted_terms' => true, 'accepted_personal_data' => true, 'client_request_id' => 'req-'.uniqid(),
        ], ['Authorization' => 'Bearer '.$ficha->access_hash])
            ->assertStatus(422)
            ->assertJsonPath('errors.plan_id.0', 'El plan «TOTAL ACCESS - ELITE» está archivado: ya no se vende. Si hay que volver a venderlo, reactívalo en Planes.');

        $this->assertSame(0, PaymentTransaction::count());
        Http::assertNothingSent();
    }

    public function test_suscripcion_automatica_nueva(): void
    {
        config(['wompi.recurring.enabled' => true]);
        $socio = $this->socio();
        $ficha = Member::where('user_id', $socio->id)->firstOrFail();

        try {
            MembershipSubscriptionService::make()->subscribeWithFirstCharge([
                'member_id' => $ficha->id, 'user_id' => $socio->id, 'plan_id' => $this->archivado->id,
            ]);
            $this->fail('Se suscribió a un plan archivado.');
        } catch (SubscriptionException $e) {
            $this->assertSame('invalid_plan', $e->errorCode);
        }
    }

    public function test_asesor_comercial_e_ia_no_lo_cotizan_ni_le_generan_link(): void
    {
        $this->assertNotContains($this->archivado->id, Plan::sellable()->pluck('id')->all());
        $this->assertFalse($this->archivado->isSellable());
        $this->assertNotContains($this->archivado->id, array_column(app(MarketingKnowledgeBaseService::class)->activePlans(), 'id'));

        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'Lead']);
        try {
            app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($lead, $this->archivado);
            $this->fail('Generó un link de pago de un plan archivado.');
        } catch (SalesGuardrailException $e) {
            $this->assertSame('plan_archived', $e->errorCode);
        }
        // El vigente sí pasa la guarda.
        app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($lead, $this->vigente);
    }

    public function test_catalogo_de_la_app(): void
    {
        $ids = collect($this->getJson('/api/membership-plans')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertNotContains($this->archivado->id, $ids);
        $this->assertContains($this->vigente->id, $ids);
        $this->getJson("/api/membership-plans/{$this->archivado->id}")->assertNotFound();
    }

    public function test_traslados_hacia_un_archivado_no_desde_uno_si(): void
    {
        $this->postJson('/api/admin/plan-transfers/dry-run', [
            'from_plan_id' => $this->vigente->id, 'to_plan_id' => $this->archivado->id, 'user_ids' => [$this->socio()->id],
        ], $this->h)->assertStatus(422)->assertJsonPath('code', 'to_plan_archived');

        $socio = $this->socio($this->archivado->name);
        $this->postJson('/api/admin/plan-transfers/dry-run', [
            'from_plan_id' => $this->archivado->id, 'to_plan_id' => $this->vigente->id, 'user_ids' => [$socio->id],
        ], $this->h)->assertOk();
    }

    // ────────────────────────────────────────────────────────────────────────

    private function socio(?string $plan = null): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Socio Venta '.$n, 'email' => 'venta-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'document' => (string) (74000000 + $n), 'status' => 'active', 'plan' => $plan,
            'membership_start_date' => $plan ? '2026-10-20' : null, 'membership_end_date' => $plan ? '2026-11-19' : null]);
        Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
            'document_number' => (string) (74000000 + $n), 'phone' => '310'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'access_hash' => 'tok-venta-'.$n.'-'.uniqid(), 'status' => Member::STATUS_ACTIVE]);

        return $user->refresh();
    }

    /** @return array<string, mixed> */
    private function cobro(User $socio, Plan $plan): array
    {
        return ['user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => $plan->price, 'method' => 'cash', 'status' => 'paid'];
    }
}
