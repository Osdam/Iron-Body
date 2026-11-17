<?php

namespace Tests\Feature\Plans;

use App\Enums\CashShiftType;
use App\Exceptions\SubscriptionException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\Tools\Commercial\ListPlansTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Subscriptions\MembershipSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * CADA CANAL VENDE LO SUYO, Y SOLO LO SUYO.
 *
 * `active` era el interruptor del mostrador y `sellable` solo cubría lo
 * comercial. El mostrador únicamente rechazaba los archivados —un plan inactivo
 * se cobraba por la API— y la app cobraba por Wompi planes que ULTRON y los
 * links ya no vendían. Ahora cada canal pregunta su regla al modelo:
 *
 *   mostrador (cobro, plan a plazos, mejora)  Plan::isSellableAtCounter()
 *   app (catálogo, Wompi, suscripciones)      Plan::isSellableInApp()
 *   comercial (ULTRON, links)                 Plan::isSellable(), sin cambio
 *
 * LA RESTRICCIÓN DURA: el plan 24 de producción («PLAN SEPTIEMBRE», 1 peso,
 * 2 días, activo y NO vendible) se cobra en recepción todas las semanas y no
 * puede dejar de funcionar. Aquí está su réplica exacta.
 *
 * Cada estado del plan se prueba en cada puerta real; cada rechazo es un 422
 * que no escribe nada, y cada aceptación llega hasta el final.
 */
class PlanSalesChannelsTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que cada estado puede hacer en cada canal: [mostrador, app, comercial]. */
    private const MATRIZ = [
        'vendible' => [true, true, true],
        'replica_24' => [true, false, false],
        'inactivo' => [false, false, false],
        'archivado' => [false, false, false],
        'cero_dias' => [true, false, false],
        'sin_mostrador' => [false, true, true],
    ];

    /** Los mensajes del mostrador, literales: son el contrato con quien cobra. */
    private const MOTIVO_MOSTRADOR = [
        'inactivo' => 'El plan «Total Access viejo» está inactivo: no se vende por ninguna vía. Si hay que volver a cobrarlo, actívalo en Planes.',
        'archivado' => 'El plan «TOTAL ACCESS - ELITE» está archivado: ya no se vende. Si hay que volver a venderlo, reactívalo en Planes.',
        'sin_mostrador' => 'El plan «Solo en la app» no se cobra en la Caja de Gimnasio. Si hay que cobrarlo aquí, márcalo en Planes como «Se cobra en recepción».',
    ];

    private const MOTIVO_APP = 'Ese plan no está disponible para comprarlo en la app.';

    private Admin $super;

    private array $h;

    private Plan $mensual;

    /** @var array<string, Plan> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'America/Bogota')->utc());
        $this->super = Admin::create(['name' => 'Dueño', 'email' => 'canales-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->super);
        $this->openCashShift($this->super, CashShiftType::GYM);

        // El plan que ya tiene el socio cuando se prueba una mejora.
        $this->mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $this->p = [
            'vendible' => Plan::create(['name' => 'Élite', 'price' => 150000, 'duration_days' => 30, 'active' => true,
                'sellable' => true, 'visible_in_app' => true]),
            // El 24 tal cual está en producción. `counter_sellable` no se toca:
            // nace en true, que es justo lo que tiene que seguir pasando.
            'replica_24' => Plan::create(['name' => 'PLAN SEPTIEMBRE', 'price' => 1, 'duration_days' => 2, 'active' => true,
                'sellable' => false]),
            'inactivo' => Plan::create(['name' => 'Total Access viejo', 'price' => 150000, 'duration_days' => 30, 'active' => false,
                'sellable' => true, 'visible_in_app' => true]),
            'archivado' => Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 150000, 'duration_days' => 30, 'active' => true,
                'sellable' => true, 'visible_in_app' => true]),
            // Cero días: no da vigencia y se sigue cobrando en el mostrador (ZeroDurationPlanTest).
            'cero_dias' => Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 0, 'active' => true]),
            'sin_mostrador' => Plan::create(['name' => 'Solo en la app', 'price' => 150000, 'duration_days' => 30, 'active' => true,
                'sellable' => true, 'visible_in_app' => true, 'counter_sellable' => false]),
        ];
        $this->postJson("/api/plans/{$this->p['archivado']->id}/archive", ['reason' => 'Plan heredado, ya no se vende'], $this->h)->assertOk();
        foreach ($this->p as $plan) {
            $plan->refresh();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── La regla, en PHP y en SQL ───────────────────────────────────────────

    public function test_cada_estado_vende_por_su_canal_y_el_sql_dice_lo_mismo_que_el_modelo(): void
    {
        $ids = fn ($q) => $q->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mostrador = $ids(Plan::query()->sellableAtCounter());
        $app = $ids(Plan::query()->sellableInApp());
        $comercial = $ids(Plan::query()->sellable());

        foreach (self::MATRIZ as $estado => [$enMostrador, $enApp, $enComercial]) {
            $plan = $this->p[$estado];
            $this->assertSame($enMostrador, $plan->isSellableAtCounter(), "{$estado}: mostrador");
            $this->assertSame($enApp, $plan->isSellableInApp(), "{$estado}: app");
            $this->assertSame($enComercial, $plan->isSellable(), "{$estado}: comercial");
            $this->assertSame($enMostrador, in_array((int) $plan->id, $mostrador, true), "{$estado}: scope del mostrador");
            $this->assertSame($enApp, in_array((int) $plan->id, $app, true), "{$estado}: scope de la app");
            $this->assertSame($enComercial, in_array((int) $plan->id, $comercial, true), "{$estado}: scope comercial");
        }

        $this->assertFalse($this->p['archivado']->counter_sellable, 'archivar apaga también el mostrador');
    }

    // ── Mostrador ───────────────────────────────────────────────────────────

    public function test_cobro_de_mostrador(): void
    {
        $socio = $this->socio();

        foreach (self::MATRIZ as $estado => [$enMostrador]) {
            $plan = $this->p[$estado];
            $antes = $this->huella();
            $r = $this->postJson('/api/payments', $this->cobro($socio, $plan), $this->h);

            if ($enMostrador) {
                $r->assertCreated();
                $this->assertSame((int) $plan->id, (int) $r->json('plan_id'), $estado);

                continue;
            }

            // Un solo motivo: con un archivado habla PlanNotArchived y la regla
            // del mostrador calla, para no dar dos errores por la misma causa.
            $r->assertStatus(422)->assertJsonPath('errors.plan_id', [self::MOTIVO_MOSTRADOR[$estado]]);
            $this->assertSame($antes, $this->huella(), "{$estado}: un 422 no escribe nada");
        }
    }

    public function test_el_plan_24_se_sigue_cobrando_en_recepcion_como_cada_semana(): void
    {
        $socio = $this->socio();

        $r = $this->postJson('/api/payments', $this->cobro($socio, $this->p['replica_24']), $this->h)->assertCreated();
        $this->assertSame(1.0, (float) $r->json('amount'));

        $cobro = Payment::query()->where('plan_id', $this->p['replica_24']->id)->sole();
        $this->assertSame('counter', $cobro->origin);
        $this->assertNotNull($cobro->cash_shift_id, 'entra en el turno abierto, como cualquier cobro de mostrador');
    }

    public function test_plan_a_plazos(): void
    {
        $primerPago = ['vendible' => 50000, 'replica_24' => 0.5, 'cero_dias' => 20000];

        foreach (self::MATRIZ as $estado => [$enMostrador]) {
            $plan = $this->p[$estado];
            $socio = $this->socio();
            $antes = $this->huella();
            $r = $this->postJson('/api/admin/receivables/plan', [
                'user_id' => $socio->id, 'plan_id' => $plan->id, 'first_payment' => $primerPago[$estado] ?? 10000, 'method' => 'cash',
            ], $this->h);

            if ($enMostrador) {
                $r->assertCreated();

                continue;
            }

            $r->assertStatus(422)->assertJsonPath('errors.plan_id', [self::MOTIVO_MOSTRADOR[$estado]]);
            $this->assertSame($antes, $this->huella(), "{$estado}: un 422 no escribe nada");
        }
    }

    public function test_mejora_de_plan(): void
    {
        // Solo los destinos más caros que el Mensual: el 24 (1 peso) y el de
        // cero días no son destino de una mejora por sus propias reglas.
        $motivos = [
            'vendible' => null,
            'inactivo' => 'Ese plan está inactivo.',
            'archivado' => 'Ese plan está archivado: ya no se vende.',
            'sin_mostrador' => self::MOTIVO_MOSTRADOR['sin_mostrador'],
        ];

        foreach ($motivos as $estado => $motivo) {
            $plan = $this->p[$estado];
            $socio = $this->socioConMensual();

            $this->getJson("/api/users/{$socio->id}/plan-upgrade?plan_id={$plan->id}", $this->h)->assertOk()
                ->assertJsonPath('can_upgrade', $motivo === null)
                ->assertJsonPath('reason', $motivo);

            $antes = $this->huella();
            $r = $this->postJson("/api/users/{$socio->id}/plan-upgrade", ['plan_id' => $plan->id, 'method' => 'cash'], $this->h);

            if ($motivo === null) {
                $r->assertCreated();
                $this->assertSame('Élite', $socio->fresh()->plan);

                continue;
            }

            $r->assertStatus(422)->assertJsonPath('message', $motivo);
            $this->assertSame($antes, $this->huella(), "{$estado}: un 422 no escribe nada");
            $this->assertSame('Mensual', $socio->fresh()->plan, "{$estado}: el socio sigue con su plan");
        }
    }

    // ── App ─────────────────────────────────────────────────────────────────

    public function test_catalogo_y_detalle_de_la_app(): void
    {
        $catalogo = collect($this->getJson('/api/membership-plans')->assertOk()->json('data'))->pluck('id')->all();

        foreach (self::MATRIZ as $estado => [, $enApp]) {
            $plan = $this->p[$estado];
            $this->assertSame($enApp, in_array($plan->id, $catalogo, true), "{$estado}: catálogo");
            // 404 y no 403: para la app ese plan no existe.
            $this->getJson("/api/membership-plans/{$plan->id}")->assertStatus($enApp ? 200 : 404);
        }
    }

    public function test_compra_por_wompi_desde_la_app(): void
    {
        $this->pasarelaDePruebas();
        $ficha = Member::query()->where('user_id', $this->socio()->id)->firstOrFail();
        $comprar = fn (Plan $plan) => $this->postJson('/api/payments/wompi/nequi', [
            'plan_id' => $plan->id, 'phone' => '3215542105',
            'accepted_terms' => true, 'accepted_personal_data' => true, 'client_request_id' => 'req-'.uniqid(),
        ], ['Authorization' => 'Bearer '.$ficha->access_hash]);

        // Los rechazos primero, para poder afirmar que no salió nada a Wompi.
        foreach (self::MATRIZ as $estado => [, $enApp]) {
            if ($enApp) {
                continue;
            }
            $antes = $this->huella();
            $comprar($this->p[$estado])->assertStatus(422)->assertJsonPath('errors.plan_id', [
                $estado === 'archivado' ? self::MOTIVO_MOSTRADOR['archivado'] : self::MOTIVO_APP,
            ]);
            $this->assertSame($antes, $this->huella(), "{$estado}: un 422 no escribe nada");
        }
        Http::assertNothingSent();

        foreach (['vendible', 'sin_mostrador'] as $estado) {
            $comprar($this->p[$estado])->assertOk()->assertJsonPath('provider', 'wompi');
        }
        $this->assertSame(2, PaymentTransaction::count());
    }

    public function test_suscripcion_automatica_nueva(): void
    {
        config(['wompi.recurring.enabled' => true]);
        $socio = $this->socio();
        $ficha = Member::query()->where('user_id', $socio->id)->firstOrFail();

        foreach (self::MATRIZ as $estado => [, $enApp]) {
            $codigo = null;
            try {
                MembershipSubscriptionService::make()->subscribeWithFirstCharge([
                    'member_id' => $ficha->id, 'user_id' => $socio->id, 'plan_id' => $this->p[$estado]->id,
                ]);
            } catch (SubscriptionException $e) {
                $codigo = $e->errorCode;
            } catch (Throwable) {
                // Más allá del plan (sin fuente de pago en esta prueba): ya pasó la puerta.
                $codigo = 'paso_el_plan';
            }

            if ($enApp) {
                $this->assertNotSame('invalid_plan', $codigo, "{$estado}: la app lo vende, se puede suscribir");
            } else {
                $this->assertSame('invalid_plan', $codigo, "{$estado}: no se suscribe a lo que la app no vende");
            }
        }
        $this->assertSame(0, DB::table('membership_subscriptions')->count());
    }

    // ── Comercial, sin cambio ───────────────────────────────────────────────

    public function test_lo_comercial_no_cambia_con_la_bandera_del_mostrador(): void
    {
        // El 24: se cobra en recepción (counter_sellable=true) y sigue fuera de
        // todo lo comercial porque no es vendible.
        $plan24 = $this->p['replica_24'];
        $this->assertTrue($plan24->counter_sellable);
        $this->assertNotContains($plan24->id, Plan::sellable()->pluck('id')->all());

        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'Lead']);
        try {
            app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($lead, $plan24);
            $this->fail('Generó un link de pago del plan 24.');
        } catch (SalesGuardrailException $e) {
            $this->assertSame('plan_not_sellable', $e->errorCode);
        }

        $listados = array_column(app(ListPlansTool::class)->execute([], new ToolContext(requestedBy: 'test'))->data['plans'], 'plan_id');
        $this->assertNotContains($plan24->id, $listados);

        // Y al revés: no cobrarse en recepción no saca un plan de lo comercial.
        $this->assertContains($this->p['sin_mostrador']->id, $listados);
        app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink($lead, $this->p['sin_mostrador']);
    }

    // ── El catálogo del CRM ─────────────────────────────────────────────────

    public function test_el_listado_de_planes_dice_por_donde_se_cobra_cada_uno(): void
    {
        $filas = collect($this->getJson('/api/plans?per_page=50', $this->h)->assertOk()->json('data'))->keyBy('id');

        foreach (self::MATRIZ as $estado => [$enMostrador]) {
            $fila = $filas->get($this->p[$estado]->id);
            $this->assertSame($enMostrador, $fila['sellable_at_counter'], "{$estado}: sellable_at_counter");
            $this->assertSame($this->p[$estado]->counter_sellable, $fila['counter_sellable'], "{$estado}: counter_sellable");
        }
        // Inactivo con la bandera puesta: la bandera dice true, el mostrador no.
        $this->assertTrue($filas->get($this->p['inactivo']->id)['counter_sellable']);

        // Un plan recién creado no se ha releído y la respuesta lo dice igual.
        $this->postJson('/api/plans', ['name' => 'Nuevo', 'price' => 90000, 'duration_days' => 30, 'active' => true], $this->h)
            ->assertCreated()
            ->assertJsonPath('counter_sellable', true)
            ->assertJsonPath('sellable_at_counter', true);
    }

    public function test_los_canales_se_editan_con_gestionar_planes_y_quedan_auditados(): void
    {
        $plan = $this->p['vendible'];

        // Recepción ve planes pero no los gestiona: no decide por dónde se venden.
        $this->putJson("/api/plans/{$plan->id}", ['counter_sellable' => false], $this->actingAsAdmin(Admin::create([
            'name' => 'Laura Mostrador', 'email' => 'rec-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ])))->assertForbidden();
        $this->assertTrue($plan->fresh()->counter_sellable);

        $this->putJson("/api/plans/{$plan->id}", ['counter_sellable' => false, 'sellable' => false], $this->h)
            ->assertOk()
            ->assertJsonPath('counter_sellable', false)
            ->assertJsonPath('sellable_at_counter', false)
            ->assertJsonPath('sellable', false);

        $traza = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->latest('id')->firstOrFail();
        $cambios = collect($traza->changes)->keyBy('field');
        // AuditTrail escribe los booleanos como se leen: «sí» y «no».
        $this->assertSame(['field' => 'counter_sellable', 'from' => 'sí', 'to' => 'no'], $cambios->get('counter_sellable'));
        $this->assertSame(['field' => 'sellable', 'from' => 'sí', 'to' => 'no'], $cambios->get('sellable'));

        // Y vuelta atrás, por el mismo camino.
        $this->putJson("/api/plans/{$plan->id}", ['counter_sellable' => true, 'sellable' => true], $this->h)->assertOk();
        $this->assertTrue($plan->fresh()->isSellableAtCounter());
        $this->assertTrue($plan->fresh()->isSellable());
    }

    public function test_un_archivado_no_vuelve_a_la_venta_tocando_sus_canales(): void
    {
        $plan = $this->p['archivado'];

        foreach (['counter_sellable', 'sellable'] as $bandera) {
            $this->putJson("/api/plans/{$plan->id}", [$bandera => true], $this->h)
                ->assertStatus(422)->assertJsonPath('code', 'plan_archived');
        }

        $plan->refresh();
        $this->assertFalse($plan->counter_sellable);
        $this->assertFalse($plan->sellable);
        // Apagarlas sigue permitido: no lo devuelve a ninguna venta.
        $this->putJson("/api/plans/{$plan->id}", ['counter_sellable' => false], $this->h)->assertOk();
    }

    public function test_archivar_apaga_el_mostrador_y_reactivar_lo_enciende(): void
    {
        $plan = $this->p['vendible'];

        $this->postJson("/api/plans/{$plan->id}/archive", ['reason' => 'Se reemplaza por otro'], $this->h)->assertOk()
            ->assertJsonPath('plan.counter_sellable', false)
            ->assertJsonPath('plan.sellable_at_counter', false);
        $archivo = AuditLog::query()->where('entity', 'plan')->where('action', 'status')->latest('id')->firstOrFail();
        $this->assertContains(['field' => 'counter_sellable', 'from' => 'sí', 'to' => 'no'], $archivo->changes);

        $this->postJson("/api/plans/{$plan->id}/reactivate", ['reason' => 'Vuelve a venderse'], $this->h)->assertOk()
            ->assertJsonPath('plan.counter_sellable', true)
            ->assertJsonPath('plan.sellable_at_counter', true);
        $this->assertTrue($plan->fresh()->isSellableAtCounter());
    }

    // ── La migración ────────────────────────────────────────────────────────

    public function test_la_migracion_nace_en_true_y_apaga_solo_los_archivados(): void
    {
        $migracion = require database_path('migrations/2026_11_18_100002_add_counter_sellable_to_plans_table.php');

        // Lo que habría en producción al desplegar: sin la columna.
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('plans', 'counter_sellable'));

        $migracion->up();

        $bandera = DB::table('plans')->pluck('counter_sellable', 'id')->map(fn ($v) => (bool) $v);
        foreach ($this->p as $estado => $plan) {
            $this->assertSame($estado !== 'archivado', $bandera[$plan->id], "{$estado}: nace en true salvo archivado");
        }
        // El 24 y el inactivo, en true: la migración no cambia lo que hoy se cobra.
        $this->assertTrue($bandera[$this->p['replica_24']->id]);
        $this->assertTrue($bandera[$this->mensual->id]);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Lo que un rechazo no puede tocar. */
    private function huella(): array
    {
        $tablas = ['payments', 'payment_splits', 'receivables', 'receivable_payments', 'payment_transactions',
            'membership_subscriptions', 'audit_logs'];

        return array_combine($tablas, array_map(fn (string $t) => DB::table($t)->count(), $tablas));
    }

    private function socio(): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Socio Canal '.$n, 'email' => 'canal-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'document' => (string) (75000000 + $n), 'status' => 'active']);
        Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
            'document_number' => (string) (75000000 + $n), 'phone' => '311'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'access_hash' => 'tok-canal-'.$n.'-'.uniqid(), 'status' => Member::STATUS_ACTIVE]);

        return $user->refresh();
    }

    /** Un socio con el Mensual vigente, cobrado por el mostrador real. */
    private function socioConMensual(): User
    {
        $socio = $this->socio();
        $this->postJson('/api/payments', $this->cobro($socio, $this->mensual), $this->h)->assertCreated();

        return $socio->refresh();
    }

    /** @return array<string, mixed> */
    private function cobro(User $socio, Plan $plan): array
    {
        return ['user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => $plan->price, 'method' => 'cash', 'status' => 'paid'];
    }

    private function pasarelaDePruebas(): void
    {
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'api_url' => 'https://sandbox.wompi.co/v1', 'public_key' => 'pub_test_x',
            'private_key' => 'prv_test_x', 'integrity_secret' => 'test_integrity_xyz', 'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'a_tok', 'permalink' => 'https://w/terms'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'p_tok', 'permalink' => 'https://w/data'],
            ]], 200),
            'sandbox.wompi.co/v1/transactions*' => fn ($req) => Http::response(['data' => [
                'id' => 'wompi-tx-'.uniqid(), 'status' => 'PENDING',
                'reference' => json_decode($req->body(), true)['reference'] ?? 'ref',
                'amount_in_cents' => 15000000, 'currency' => 'COP', 'payment_method' => ['type' => 'NEQUI'],
            ]], 200),
        ]);
    }
}
