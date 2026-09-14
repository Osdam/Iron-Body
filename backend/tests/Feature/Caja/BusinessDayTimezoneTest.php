<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El día del gimnasio no es el día UTC.
 *
 * LO QUE SE PROTEGE. Los timestamps se guardan en UTC y el día del negocio
 * empieza a medianoche en Bogotá, cinco horas después. Un `whereDate` sobre una
 * columna en UTC comparada con una fecha colombiana hace que «hoy» cambie a las
 * SIETE DE LA TARDE: lo cobrado en la última franja —que es cuando más gente
 * entra al gimnasio— aparece como del día siguiente.
 *
 * El fallo no se ve nunca en una revisión de código, porque las consultas
 * parecen correctas, y solo aparece contando dinero después de las siete.
 *
 * Todo el fichero trabaja sobre las 20:00 de Bogotá, que en UTC ya es el día
 * siguiente: es el momento exacto en que las dos formas de contar difieren.
 */
class BusinessDayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** 13 de septiembre, 20:00 en Bogotá = 14 de septiembre, 01:00 UTC. */
    private const NOCHE_BOGOTA = '2026-09-14 01:00:00';

    /** 13 de septiembre, 06:00 en Bogotá = 11:00 UTC del mismo día. */
    private const MANANA_BOGOTA = '2026-09-13 11:00:00';

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'tz-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        config(['caja.timezone' => 'America/Bogota']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function socio(): Member
    {
        $user = User::create([
            'name' => 'Alejandro Casas',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'plan' => 'Premium',
            'membershipEndDate' => '2026-12-31',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Alejandro Casas',
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function admin(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    // ── El día del negocio ──────────────────────────────────────────────────

    public function test_a_las_ocho_de_la_noche_el_dia_del_negocio_sigue_siendo_hoy(): void
    {
        Carbon::setTestNow(self::NOCHE_BOGOTA);

        // En UTC ya es 14; para el gimnasio todavía es 13.
        $this->assertSame('2026-09-14', now()->toDateString(), 'El reloj del servidor va en UTC.');
        $this->assertSame('2026-09-13', Receivable::businessToday()->toDateString(),
            'El día del gimnasio no puede cambiar a las siete de la tarde.');
    }

    public function test_lo_cobrado_a_las_ocho_cuenta_en_el_dia_de_hoy(): void
    {
        Carbon::setTestNow(self::NOCHE_BOGOTA);

        Product::create(['name' => 'Agua', 'sale_price' => 3000, 'cost_price' => 1000, 'stock' => 10]);
        ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-'.uniqid(), 'channel' => 'pos',
            'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 3000, 'total' => 3000, 'paid_at' => now(),
        ]);

        $stats = $this->getJson('/api/admin/caja/stats', $this->admin())->assertOk()->json();

        $this->assertEqualsWithDelta(3000, $stats['revenue_today'], 0.01,
            'Una venta de las ocho de la noche es de hoy, no de mañana.');
        $this->assertSame(1, $stats['sales_today']);
    }

    public function test_la_lista_de_ventas_de_hoy_dice_lo_mismo_que_la_tarjeta(): void
    {
        Product::create(['name' => 'Agua', 'sale_price' => 3000, 'cost_price' => 1000, 'stock' => 10]);

        // La venta ocurre por la MAÑANA del día del negocio (10:00 en Bogotá =
        // 15:00 UTC del 13). Es lo que distingue las dos formas de contar: a
        // esa hora las dos coinciden, y por eso la venta se hace antes.
        Carbon::setTestNow(self::MANANA_BOGOTA);
        ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-'.uniqid(), 'channel' => 'pos',
            'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 3000, 'total' => 3000, 'paid_at' => now(),
        ]);

        // Y se consulta por la NOCHE, cuando en UTC ya es el día siguiente. Era
        // el fallo: la tarjeta contaba el día del negocio y la lista de abajo el
        // día UTC, así que la venta de la mañana desaparecía de la lista a las
        // siete de la tarde. Dos respuestas distintas en la misma pantalla.
        Carbon::setTestNow(self::NOCHE_BOGOTA);
        $lista = $this->getJson('/api/admin/caja/sales?today=1', $this->admin())->assertOk();
        $stats = $this->getJson('/api/admin/caja/stats', $this->admin())->assertOk();

        $this->assertSame(1, $lista->json('meta.total'),
            'La lista de «hoy» tiene que contar el mismo día que la tarjeta.');
        $this->assertSame(1, $stats->json('sales_today'));
    }

    public function test_una_deuda_abierta_de_noche_sale_en_el_filtro_de_su_dia(): void
    {
        Carbon::setTestNow(self::NOCHE_BOGOTA);
        $socio = $this->socio();
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);

        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: CashShiftType::GYM,
            concept: 'Plan Premium',
            total: Money::fromAmount(80000),
            actor: $this->cajero,
        );

        // Se busca por el día COLOMBIANO en que ocurrió, que es lo que
        // escribiría quien la abrió.
        $r = $this->getJson('/api/admin/receivables?from=2026-09-13&to=2026-09-13', $this->admin())->assertOk();

        $this->assertSame(1, $r->json('meta.total'),
            'Se guardó con fecha UTC del 14; quien la abrió la buscará en el 13.');
    }

    // ── Vencimiento ─────────────────────────────────────────────────────────

    public function test_el_dia_pactado_no_esta_vencido_ni_a_las_ocho_de_la_noche(): void
    {
        Carbon::setTestNow(self::NOCHE_BOGOTA);
        $socio = $this->socio();
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);

        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id, type: CashShiftType::GYM,
            concept: 'Plan Premium', total: Money::fromAmount(80000), actor: $this->cajero,
            dueAt: Carbon::parse('2026-09-13'),
        );

        // El socio dispone del día entero: pagar a las ocho de la noche del día
        // límite es pagar a tiempo. Si el servidor comparara contra la fecha
        // UTC, a esa hora ya lo daría por vencido y lo retendría.
        $this->assertFalse($cuenta->fresh()->isOverdue(),
            'Vencer a las siete de la tarde del día pactado es robarle el plazo.');
        $this->assertFalse(app(MembershipFinancialStanding::class)->isOverdue($socio));
    }

    public function test_al_dia_siguiente_por_la_manana_ya_esta_vencida(): void
    {
        $socio = $this->socio();
        Carbon::setTestNow(self::MANANA_BOGOTA);
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);

        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id, type: CashShiftType::GYM,
            concept: 'Plan Premium', total: Money::fromAmount(80000), actor: $this->cajero,
            dueAt: Carbon::parse('2026-09-12'),
        );

        $this->assertTrue($cuenta->fresh()->isOverdue());
        $this->assertSame(1, $cuenta->fresh()->daysOverdue());
        $this->assertTrue(app(MembershipFinancialStanding::class)->isOverdue($socio));
    }

    public function test_el_aviso_de_vence_hoy_sale_el_dia_colombiano(): void
    {
        Carbon::setTestNow(self::NOCHE_BOGOTA);
        $socio = $this->socio();
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);

        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id, type: CashShiftType::GYM,
            concept: 'Plan Premium', total: Money::fromAmount(80000), actor: $this->cajero,
            dueAt: Carbon::parse('2026-09-13'),
        );

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        // A las ocho de la noche del 13 todavía toca «vence hoy», no «vencida».
        $this->assertDatabaseHas('automation_events', ['event_type' => 'receivable.due_today']);
        $this->assertDatabaseMissing('automation_events', ['event_type' => 'receivable.overdue']);
    }
}
