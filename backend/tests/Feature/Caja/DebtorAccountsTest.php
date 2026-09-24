<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LA DEUDA POR PERSONA, y cobrarla toda de una vez.
 *
 * El caso que lo motiva, tal cual: alguien se lleva fiada un agua el lunes, otra
 * el martes y otra el jueves. Antes había que abrir tres fichas y registrar tres
 * abonos; ahora tiene que salir una tarjeta con las tres líneas y un botón que
 * las salde juntas.
 *
 * Lo que estos tests fijan es lo que NO puede pasar al juntar cobros:
 *
 *  · que se pierda el detalle de qué se llevó cada día;
 *  · que un barrido a medias deje cobrada la mitad de la cuenta;
 *  · que el dinero de la cafetería acabe en el arqueo del gimnasio;
 *  · que un doble clic cobre dos veces;
 *  · que un sobrepago se convierta en saldo a favor inventado.
 */
class DebtorAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajera;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajera = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'deuda-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->cajera);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function socio(string $nombre = 'Oscar Mancipe'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $nombre,
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);
    }

    /** Una deuda ya registrada, con su fecha y su caja. */
    private function fiado(
        Member $socio,
        string $concepto,
        float $total,
        int $diasAtras = 0,
        CashShiftType $caja = CashShiftType::PRODUCTS,
        ?string $vence = null,
    ): Receivable {
        $cuenta = Receivable::create([
            'member_id' => $socio->id,
            'debtor_type' => DebtorType::MEMBER,
            'debtor_id' => $socio->id,
            'type' => $caja,
            'concept' => $concepto,
            'total_amount' => $total,
            'status' => Receivable::STATUS_PENDING,
            'due_at' => $vence,
            'created_by' => $this->cajera->id,
            'created_by_name' => $this->cajera->name,
        ]);

        // La fecha de la deuda importa: el orden de cobro es el de antigüedad.
        $cuenta->forceFill(['created_at' => CarbonImmutable::now()->subDays($diasAtras)])->save();

        return $cuenta->fresh();
    }

    private function abrirCaja(string $tipo): void
    {
        $this->postJson('/api/admin/caja/shift/open', ['type' => $tipo], $this->h)->assertStatus(201);
    }

    private function tarjetas(array $query = []): array
    {
        return $this->getJson('/api/admin/receivables/people?'.http_build_query($query), $this->h)
            ->assertOk()->json();
    }

    private function saldarTodo(Member $socio, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            '/api/admin/receivables/people/member/'.$socio->id.'/settle',
            array_merge(['method' => 'cash'], $payload),
            $this->h,
        );
    }

    // ── La tarjeta ──────────────────────────────────────────────────────────

    public function test_las_tres_aguas_salen_en_una_sola_tarjeta_con_su_lista(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua 600ml', 2000, diasAtras: 6);
        $this->fiado($oscar, 'Agua 600ml', 2000, diasAtras: 4);
        $this->fiado($oscar, 'Gatorade', 4500, diasAtras: 1);

        $r = $this->tarjetas();

        $this->assertCount(1, $r['data'], 'Una persona, una tarjeta.');
        $tarjeta = $r['data'][0];

        $this->assertSame('Oscar Mancipe', $tarjeta['debtor']['name']);
        $this->assertEquals(8500, $tarjeta['outstanding']);
        $this->assertSame(3, $tarjeta['debts_count']);

        // Y el detalle de qué se llevó cada día, de lo más viejo a lo más nuevo.
        $this->assertCount(3, $tarjeta['items']);
        $this->assertSame('Agua 600ml', $tarjeta['items'][0]['concept']);
        $this->assertSame('Gatorade', $tarjeta['items'][2]['concept']);
        $this->assertEquals(8500, collect($tarjeta['items'])->sum('balance'));

        // El resumen de arriba responde «cuánto me deben en total».
        $this->assertSame(1, $r['summary']['people']);
        $this->assertSame(3, $r['summary']['debts']);
        $this->assertEquals(8500, $r['summary']['outstanding_total']);
    }

    public function test_cada_persona_tiene_su_tarjeta_y_no_se_mezclan(): void
    {
        $oscar = $this->socio('Oscar Mancipe');
        $ana = $this->socio('Ana Pérez');
        $this->fiado($oscar, 'Agua', 2000);
        $this->fiado($ana, 'Agua', 2000);
        $this->fiado($ana, 'Barra de proteína', 8000);

        $tarjetas = collect($this->tarjetas()['data'])->keyBy(fn (array $t) => $t['debtor']['name']);

        $this->assertEquals(2000, $tarjetas['Oscar Mancipe']['outstanding']);
        $this->assertEquals(10000, $tarjetas['Ana Pérez']['outstanding']);
        $this->assertSame(2, $tarjetas['Ana Pérez']['debts_count']);
    }

    public function test_la_tarjeta_separa_lo_que_debe_en_cada_caja(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua', 2000, caja: CashShiftType::PRODUCTS);
        $this->fiado($oscar, 'Saldo del plan', 50000, caja: CashShiftType::GYM);

        $tarjeta = $this->tarjetas()['data'][0];

        $this->assertEquals(52000, $tarjeta['outstanding']);
        $this->assertEquals(2000, $tarjeta['by_cash']['products']);
        $this->assertEquals(50000, $tarjeta['by_cash']['gym']);
        $this->assertEqualsCanonicalizing(['products', 'gym'], $tarjeta['cash_types']);
    }

    public function test_lo_vencido_se_dice_y_manda_en_el_orden(): void
    {
        $alDia = $this->socio('Al día');
        $this->fiado($alDia, 'Agua', 9000, vence: Receivable::businessToday()->addDays(5)->toDateString());

        $moroso = $this->socio('Moroso');
        // El plazo se cuenta en DÍAS DEL NEGOCIO: a las 20:00 de Neiva ya es
        // otro día en UTC, y una fecha sacada de `now()` diría un día menos.
        $this->fiado($moroso, 'Agua', 2000, diasAtras: 20, vence: Receivable::businessToday()->subDays(8)->toDateString());

        $r = $this->tarjetas();

        // Primero quien tiene deuda vencida, aunque deba menos: es a quien se
        // llama, y para eso sirve la lista.
        $this->assertSame('Moroso', $r['data'][0]['debtor']['name']);
        $this->assertEquals(2000, $r['data'][0]['overdue']['amount']);
        $this->assertSame(8, $r['data'][0]['overdue']['days']);
        $this->assertEquals(0, $r['data'][1]['overdue']['amount']);

        $this->assertSame(1, $r['summary']['overdue_people']);
        $this->assertEquals(2000, $r['summary']['overdue_total']);
    }

    public function test_se_puede_buscar_por_nombre_o_documento(): void
    {
        $oscar = $this->socio('Oscar Mancipe');
        $this->fiado($oscar, 'Agua', 2000);
        $otra = $this->socio('Ana Pérez');
        $this->fiado($otra, 'Agua', 2000);

        $this->assertCount(1, $this->tarjetas(['search' => 'Oscar'])['data']);
        $this->assertSame('Oscar Mancipe', $this->tarjetas(['search' => 'Oscar'])['data'][0]['debtor']['name']);
        $this->assertCount(1, $this->tarjetas(['search' => $oscar->document_number])['data']);
        $this->assertCount(0, $this->tarjetas(['search' => 'Nadie Con Ese Nombre'])['data']);
    }

    public function test_solo_vencidas_deja_fuera_a_quien_esta_al_dia(): void
    {
        $alDia = $this->socio('Al día');
        $this->fiado($alDia, 'Agua', 2000);
        $moroso = $this->socio('Moroso');
        $this->fiado($moroso, 'Agua', 2000, diasAtras: 20, vence: Receivable::businessToday()->subDays(3)->toDateString());

        $r = $this->tarjetas(['only_overdue' => 1]);

        $this->assertCount(1, $r['data']);
        $this->assertSame('Moroso', $r['data'][0]['debtor']['name']);
    }

    public function test_una_deuda_ya_saldada_desaparece_de_la_lista(): void
    {
        $oscar = $this->socio();
        $deuda = $this->fiado($oscar, 'Agua', 2000);

        $this->abrirCaja('products');
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 2000, 'method' => 'cash',
        ], $this->h)->assertStatus(201);

        $this->assertCount(0, $this->tarjetas()['data']);
        $this->getJson('/api/admin/receivables/people/member/'.$oscar->id, $this->h)
            ->assertOk()
            ->assertJsonPath('settled', true)
            ->assertJsonPath('data', null);
    }

    // ── Cobrar todo de una vez ──────────────────────────────────────────────

    public function test_saldar_todo_cierra_las_tres_deudas_con_un_solo_cobro(): void
    {
        $oscar = $this->socio();
        $a = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 6);
        $b = $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 5);
        $c = $this->fiado($oscar, 'Agua jueves', 2000, diasAtras: 3);

        $this->abrirCaja('products');

        $res = $this->saldarTodo($oscar)->assertStatus(201)->json();

        $this->assertEquals(6000, $res['applied']);
        $this->assertSame(3, $res['debts_touched']);
        $this->assertSame(3, $res['settled_debts']);
        $this->assertEquals(0, $res['remaining']);
        $this->assertTrue($res['settled'], 'Ya no debe nada: no hay tarjeta.');

        // Cada deuda conserva SU abono: sigue constando qué se pagó de cada día.
        foreach ([$a, $b, $c] as $deuda) {
            $this->assertSame(Receivable::STATUS_PAID, $deuda->fresh()->status);
            $abono = ReceivablePayment::where('receivable_id', $deuda->id)->sole();
            $this->assertEquals(2000, (float) $abono->amount);
            $this->assertEquals(2000, (float) $abono->balance_before);
            $this->assertEquals(0, (float) $abono->balance_after);
            $this->assertNotNull($abono->cash_shift_id, 'El dinero entra en el turno abierto.');
            $this->assertSame('Laura Mostrador', $abono->created_by_name);
        }
    }

    public function test_un_abono_parcial_se_reparte_de_la_deuda_mas_vieja_a_la_mas_nueva(): void
    {
        $oscar = $this->socio();
        $vieja = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 6);
        $media = $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 5);
        $nueva = $this->fiado($oscar, 'Agua jueves', 2000, diasAtras: 3);

        $this->abrirCaja('products');

        // Entrega 3.000: paga la del lunes y la mitad de la del martes.
        $res = $this->saldarTodo($oscar, ['amount' => 3000])->assertStatus(201)->json();

        $this->assertEquals(3000, $res['applied']);
        $this->assertSame(2, $res['debts_touched']);
        $this->assertSame(1, $res['settled_debts']);
        $this->assertEquals(3000, $res['remaining'], 'Le quedan 3.000 por pagar.');

        $this->assertSame(Receivable::STATUS_PAID, $vieja->fresh()->status);
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $media->fresh()->status);
        $this->assertEquals(1000, $media->fresh()->balance()->toFloat());
        // La más nueva no se toca: no hay dinero que le llegue.
        $this->assertSame(Receivable::STATUS_PENDING, $nueva->fresh()->status);
        $this->assertSame(0, ReceivablePayment::where('receivable_id', $nueva->id)->count());
    }

    public function test_la_previsualizacion_dice_que_cubre_el_dinero_antes_de_cobrarlo(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 6);
        $this->fiado($oscar, 'Gatorade', 4500, diasAtras: 2);

        $r = $this->getJson('/api/admin/receivables/people/member/'.$oscar->id.'/preview?amount=3000', $this->h)
            ->assertOk()->json('data');

        $this->assertEquals(6500, $r['outstanding']);
        $this->assertEquals(3000, $r['amount']);
        $this->assertEquals(3500, $r['remaining']);
        $this->assertTrue($r['lines'][0]['settles'], 'La del lunes se salda entera.');
        $this->assertEquals(1000, $r['lines'][1]['applies']);
        $this->assertEquals(3500, $r['lines'][1]['left']);
        $this->assertFalse($r['lines'][1]['settles']);
    }

    public function test_no_se_puede_pagar_mas_de_lo_que_se_debe(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua', 2000);
        $this->abrirCaja('products');

        $this->saldarTodo($oscar, ['amount' => 5000])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        // Y no se cobró nada: el sobrepago se rechaza antes de tocar la cuenta.
        $this->assertSame(0, ReceivablePayment::count());
    }

    public function test_sin_caja_abierta_no_se_cobra_nada_de_nada(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua', 2000, diasAtras: 3);
        $this->fiado($oscar, 'Gatorade', 4500, diasAtras: 1);

        $res = $this->saldarTodo($oscar)->assertStatus(409)->json();

        $this->assertStringContainsString('abrir la caja', $res['message']);
        $this->assertSame(0, ReceivablePayment::count());
    }

    public function test_si_una_de_las_dos_cajas_esta_cerrada_no_se_cobra_la_mitad(): void
    {
        $oscar = $this->socio();
        $agua = $this->fiado($oscar, 'Agua', 2000, diasAtras: 5, caja: CashShiftType::PRODUCTS);
        $plan = $this->fiado($oscar, 'Saldo del plan', 30000, diasAtras: 3, caja: CashShiftType::GYM);

        // Solo productos abierta: el barrido toca las dos cajas, así que no
        // empieza. Cobrar el agua y reventar en el plan dejaría media cuenta
        // cobrada y el mostrador sin saber por dónde iba.
        $this->abrirCaja('products');

        $this->saldarTodo($oscar)->assertStatus(409);

        $this->assertSame(0, ReceivablePayment::count());
        $this->assertSame(Receivable::STATUS_PENDING, $agua->fresh()->status);
        $this->assertSame(Receivable::STATUS_PENDING, $plan->fresh()->status);
    }

    public function test_se_puede_cobrar_solo_la_caja_que_esta_abierta(): void
    {
        $oscar = $this->socio();
        $agua = $this->fiado($oscar, 'Agua', 2000, caja: CashShiftType::PRODUCTS);
        $plan = $this->fiado($oscar, 'Saldo del plan', 30000, caja: CashShiftType::GYM);

        $this->abrirCaja('products');

        $res = $this->saldarTodo($oscar, ['cash' => 'products'])->assertStatus(201)->json();

        $this->assertEquals(2000, $res['applied']);
        $this->assertSame(Receivable::STATUS_PAID, $agua->fresh()->status);
        $this->assertSame(Receivable::STATUS_PENDING, $plan->fresh()->status);
        // Y la tarjeta sigue viva con lo que falta del gimnasio.
        $this->assertEquals(30000, $res['data']['outstanding']);
    }

    public function test_cobrar_las_dos_cajas_manda_cada_peso_a_su_turno(): void
    {
        $oscar = $this->socio();
        $agua = $this->fiado($oscar, 'Agua', 2000, diasAtras: 5, caja: CashShiftType::PRODUCTS);
        $plan = $this->fiado($oscar, 'Saldo del plan', 30000, diasAtras: 3, caja: CashShiftType::GYM);

        $this->abrirCaja('products');
        $this->abrirCaja('gym');

        $this->saldarTodo($oscar)->assertStatus(201);

        $deProductos = ReceivablePayment::where('receivable_id', $agua->id)->sole();
        $deGimnasio = ReceivablePayment::where('receivable_id', $plan->id)->sole();

        $this->assertNotSame(
            $deProductos->cash_shift_id,
            $deGimnasio->cash_shift_id,
            'El agua se arquea en productos y el plan en gimnasio: nunca en el mismo turno.',
        );
    }

    public function test_repetir_la_peticion_no_cobra_dos_veces(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 4);
        $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 2);
        $this->abrirCaja('products');

        $payload = ['method' => 'cash', 'client_request_id' => 'barrido-unico-1'];

        $primera = $this->saldarTodo($oscar, $payload)->assertStatus(201)->json();
        $segunda = $this->saldarTodo($oscar, $payload)->assertStatus(201)->json();

        $this->assertEquals(4000, $primera['applied']);
        // El reintento devuelve los mismos abonos y NO crea otros.
        $this->assertSame(2, ReceivablePayment::count());
        $this->assertEquals(0, $segunda['remaining']);
    }

    public function test_se_pueden_cobrar_solo_algunas_lineas(): void
    {
        $oscar = $this->socio();
        $lunes = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 5);
        $martes = $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 4);
        $this->abrirCaja('products');

        $res = $this->saldarTodo($oscar, ['only_ids' => [$martes->id]])->assertStatus(201)->json();

        $this->assertEquals(2000, $res['applied']);
        $this->assertSame(Receivable::STATUS_PAID, $martes->fresh()->status);
        $this->assertSame(Receivable::STATUS_PENDING, $lunes->fresh()->status);
    }


    public function test_un_abono_parcial_sobre_lineas_elegidas_no_toca_las_demas(): void
    {
        $oscar = $this->socio();
        $lunes = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 6);
        $martes = $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 5);
        $jueves = $this->fiado($oscar, 'Agua jueves', 2000, diasAtras: 3);

        $this->abrirCaja('products');

        // Marca martes y jueves, y entrega 3.000: se reparte SOLO entre esas
        // dos, de la más antigua a la más nueva. La del lunes ni se toca, que
        // es justo el motivo de poder elegir.
        $res = $this->saldarTodo($oscar, [
            'amount' => 3000,
            'only_ids' => [$martes->id, $jueves->id],
        ])->assertStatus(201)->json();

        $this->assertEquals(3000, $res['applied']);
        $this->assertSame(Receivable::STATUS_PENDING, $lunes->fresh()->status);
        $this->assertSame(Receivable::STATUS_PAID, $martes->fresh()->status);
        $this->assertEquals(1000, $jueves->fresh()->balance()->toFloat());
    }

    public function test_no_se_puede_pagar_mas_que_las_lineas_elegidas(): void
    {
        $oscar = $this->socio();
        $lunes = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 4);
        $martes = $this->fiado($oscar, 'Agua martes', 2000, diasAtras: 2);
        $this->abrirCaja('products');

        // Debe 4.000 en total, pero solo se eligió una deuda de 2.000: 3.000
        // no caben ahí, y el sobrante no se derrama sobre la otra.
        $this->saldarTodo($oscar, ['amount' => 3000, 'only_ids' => [$martes->id]])
            ->assertStatus(422);

        $this->assertSame(0, ReceivablePayment::count());
        $this->assertSame(Receivable::STATUS_PENDING, $lunes->fresh()->status);
    }

    public function test_la_previsualizacion_de_lineas_elegidas_solo_habla_de_esas(): void
    {
        $oscar = $this->socio();
        $lunes = $this->fiado($oscar, 'Agua lunes', 2000, diasAtras: 5);
        $martes = $this->fiado($oscar, 'Gatorade', 4500, diasAtras: 2);

        $r = $this->getJson(
            '/api/admin/receivables/people/member/'.$oscar->id.'/preview?only_ids[]='.$martes->id,
            $this->h,
        )->assertOk()->json('data');

        $this->assertEquals(4500, $r['outstanding'], 'El universo es lo marcado, no toda su deuda.');
        $this->assertCount(1, $r['lines']);
        $this->assertSame($martes->id, $r['lines'][0]['id']);
        $this->assertTrue($r['lines'][0]['settles']);
    }

    public function test_la_deuda_del_personal_se_agrupa_por_su_cuenta_del_crm(): void
    {
        // «El jefe» del caso real no es socio: es una cuenta del CRM.
        $jefe = Admin::create([
            'name' => 'Jefe Iron Body',
            'email' => 'jefe-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_ADMINISTRADOR,
            'status' => 'active',
        ]);

        foreach ([6, 4, 2] as $dias) {
            $cuenta = Receivable::create([
                'member_id' => null,
                'debtor_type' => DebtorType::ADMIN,
                'debtor_id' => $jefe->id,
                'type' => CashShiftType::PRODUCTS,
                'concept' => 'Agua 600ml',
                'total_amount' => 2000,
                'status' => Receivable::STATUS_PENDING,
                'created_by' => $this->cajera->id,
                'created_by_name' => $this->cajera->name,
            ]);
            $cuenta->forceFill(['created_at' => CarbonImmutable::now()->subDays($dias)])->save();
        }

        $tarjeta = $this->tarjetas()['data'][0];
        $this->assertSame('Jefe Iron Body', $tarjeta['debtor']['name']);
        $this->assertSame('admin', $tarjeta['debtor']['type']);
        $this->assertNull($tarjeta['debtor']['member_id'], 'No es socio: no hay ficha de socio a la que ir.');
        $this->assertEquals(6000, $tarjeta['outstanding']);

        $this->abrirCaja('products');
        $res = $this->postJson('/api/admin/receivables/people/admin/'.$jefe->id.'/settle', [
            'method' => 'cash',
        ], $this->h)->assertStatus(201)->json();

        $this->assertEquals(6000, $res['applied']);
        $this->assertSame(3, $res['settled_debts']);
    }

    public function test_quien_no_puede_operar_la_caja_no_puede_barrer_la_deuda(): void
    {
        $oscar = $this->socio();
        $this->fiado($oscar, 'Agua', 2000);

        // Un entrenador no tiene permisos de caja.
        $sinPermiso = Admin::create([
            'name' => 'Sin permisos',
            'email' => 'nada-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);

        // Recepción SÍ puede cobrar: es su trabajo.
        $this->postJson('/api/admin/receivables/people/member/'.$oscar->id.'/settle', [
            'method' => 'cash',
        ], $this->actingAsAdmin($sinPermiso))->assertStatus(409); // falla por la caja, no por permiso

        // Y el token compartido de automatizaciones no cobra deudas.
        $this->postJson('/api/admin/receivables/people/member/'.$oscar->id.'/settle', ['method' => 'cash'])
            ->assertStatus(401);
    }

    public function test_un_tipo_de_deudor_inventado_se_rechaza(): void
    {
        $this->getJson('/api/admin/receivables/people/marciano/7', $this->h)->assertStatus(422);
        $this->postJson('/api/admin/receivables/people/marciano/7/settle', ['method' => 'cash'], $this->h)
            ->assertStatus(422);
    }
}
