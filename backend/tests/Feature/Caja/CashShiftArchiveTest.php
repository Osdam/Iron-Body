<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use App\Enums\DebtorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El corte operativo del historial de cierres.
 *
 * LO QUE ESTO CIERRA
 * ------------------
 * Al entregar la caja, el historial arrastra los cierres de la puesta en
 * marcha. Algunos son pruebas del flujo; otros son días en los que ya se cobró
 * de verdad. La tentación es borrarlos, y ahí estaba el peligro:
 * `payments.cash_shift_id` es ON DELETE SET NULL, así que borrar un turno no
 * habría dado ningún error y habría dejado 16 pagos por 1.739.000 pesos sin el
 * cierre que los cuadra. Sin error, sin aviso, sin vuelta atrás.
 *
 * ARCHIVAR NO ES BORRAR. `archived_at` decide UNA cosa —si el turno sale en el
 * historial de Caja— y ninguna más. La fila sigue, sus totales siguen, y los
 * pagos siguen colgando de ella.
 *
 * EL INVARIANTE: nada que hable de dinero puede mirar `archived_at`.
 */
class CashShiftArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Supervisor',
            'email' => 'archivo-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function headers(): array
    {
        return $this->actingAsAdmin($this->admin);
    }

    /** Un turno cerrado, listo para archivar o no. */
    private function turnoCerrado(CashShiftType $type = CashShiftType::GYM): CashShift
    {
        $svc = app(CashShiftService::class);
        $turno = $svc->open($this->admin, $type);
        $svc->close($this->admin, $type);

        return $turno->fresh();
    }

    private function historial(array $params = []): array
    {
        $qs = $params === [] ? '' : '?'.http_build_query($params);

        return $this->getJson("/api/admin/caja/shifts{$qs}", $this->headers())
            ->assertStatus(200)
            ->json();
    }

    // ── 1-3 · Qué se ve y qué no ────────────────────────────────────────────

    public function test_un_turno_archivado_desaparece_del_historial(): void
    {
        $turno = $this->turnoCerrado();
        $this->assertCount(1, $this->historial()['data']);

        $turno->update(['archived_at' => now()]);

        $this->assertSame([], $this->historial()['data'], 'el archivado no debe listarse');
    }

    public function test_un_turno_de_productos_archivado_tampoco_aparece(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $turno->update(['archived_at' => now()]);

        $this->assertSame([], $this->historial()['data']);
        $this->assertSame([], $this->historial(['type' => 'products'])['data']);
    }

    public function test_un_turno_cerrado_sin_archivar_sigue_visible(): void
    {
        $turno = $this->turnoCerrado();

        $ids = array_column($this->historial()['data'], 'id');
        $this->assertSame([$turno->id], $ids);
    }

    // ── 4-6 · Conteo, paginación y filtros ──────────────────────────────────

    public function test_el_conteo_del_historial_excluye_los_archivados(): void
    {
        $visible = $this->turnoCerrado();
        $this->turnoCerrado(CashShiftType::PRODUCTS)->update(['archived_at' => now()]);

        $r = $this->historial();

        $this->assertCount(1, $r['data']);
        $this->assertSame(1, $r['meta']['total'], 'el total del paginador cuenta lo mismo que la lista');
        $this->assertSame($visible->id, $r['data'][0]['id']);
    }

    public function test_la_paginacion_no_reserva_huecos_para_los_archivados(): void
    {
        // Tres turnos, dos archivados: la primera página debe traer UNO.
        $vivo = $this->turnoCerrado();
        $this->turnoCerrado(CashShiftType::PRODUCTS)->update(['archived_at' => now()]);
        $otro = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $otro->update(['archived_at' => now()]);

        $r = $this->historial(['per_page' => 1]);

        $this->assertCount(1, $r['data']);
        $this->assertSame($vivo->id, $r['data'][0]['id']);
        $this->assertSame(1, $r['meta']['last_page'], 'no hay una segunda página de fantasmas');
    }

    public function test_ningun_filtro_puede_sacar_a_flote_un_archivado(): void
    {
        $turno = $this->turnoCerrado();
        $turno->update(['archived_at' => now()]);

        $combinaciones = [
            [],
            ['type' => 'gym'],
            ['type' => 'products'],
            ['status' => 'closed'],
            ['opened_by' => $this->admin->id],
            ['from' => now()->subYear()->toDateString(), 'to' => now()->addYear()->toDateString()],
            ['status' => 'closed', 'type' => 'gym', 'opened_by' => $this->admin->id],
        ];

        foreach ($combinaciones as $params) {
            $this->assertSame(
                [],
                $this->historial($params)['data'],
                'reapareció con '.json_encode($params),
            );
        }
    }

    // ── 7-9 · El dinero no se entera ────────────────────────────────────────

    public function test_archivar_no_toca_los_totales_del_turno(): void
    {
        $turno = $this->turnoCerrado();
        // Comparado como texto: `closed_at` es un Carbon y dos instancias
        // distintas con la misma fecha no son idénticas.
        $foto = static fn (CashShift $s): array => [
            'sales_total' => (string) $s->sales_total,
            'expected_amount' => (string) $s->expected_amount,
            'operations_count' => (string) $s->operations_count,
            'status' => $s->status->value,
            'closed_at' => (string) $s->closed_at,
        ];
        $antes = $foto($turno);

        $turno->update(['archived_at' => now()]);

        $this->assertSame($antes, $foto($turno->fresh()));
    }

    public function test_el_pago_sigue_colgando_de_su_turno_archivado(): void
    {
        $turno = $this->turnoCerrado();
        $user = User::create(['name' => 'Socio', 'email' => 'p-'.uniqid().'@t.test', 'password' => 'secret-password']);
        $pago = Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(), 'cash_shift_id' => $turno->id,
        ]);

        $turno->update(['archived_at' => now()]);

        $this->assertSame($turno->id, $pago->fresh()->cash_shift_id, 'la conciliación se conserva');
        $this->assertNotNull(CashShift::find($turno->id), 'la fila sigue existiendo');
    }

    public function test_el_abono_sigue_colgando_de_su_turno_archivado(): void
    {
        $user = User::create(['name' => 'Socio', 'email' => 'a-'.uniqid().'@t.test', 'password' => 'secret-password']);
        $socio = Member::create([
            'user_id' => $user->id, 'full_name' => 'Socio', 'is_staff' => false,
            'document_number' => (string) random_int(10000000, 99999999),
        ]);

        $svc = app(CashShiftService::class);
        $turno = $svc->open($this->admin, CashShiftType::GYM);

        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id,
            type: CashShiftType::GYM, concept: 'Plan a plazos',
            total: Money::fromAmount(80000), actor: $this->admin,
        );
        $abono = app(ReceivableService::class)->settle($cuenta, Money::fromAmount(80000), 'cash', $this->admin);
        $svc->close($this->admin, CashShiftType::GYM);

        $turno->fresh()->update(['archived_at' => now()]);

        $this->assertSame($turno->id, $abono->fresh()->cash_shift_id);
        $this->assertSame(0.0, $cuenta->fresh()->balance()->toFloat(), 'la deuda sigue saldada');
    }

    public function test_las_ganancias_siguen_contando_un_turno_archivado(): void
    {
        $turno = $this->turnoCerrado();
        $user = User::create(['name' => 'Socio', 'email' => 'g-'.uniqid().'@t.test', 'password' => 'secret-password']);
        Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(), 'cash_shift_id' => $turno->id,
        ]);

        $antes = $this->getJson('/api/admin/earnings', $this->headers())->json('totals.gym_revenue');
        $turno->update(['archived_at' => now()]);
        $despues = $this->getJson('/api/admin/earnings', $this->headers())->json('totals.gym_revenue');

        $this->assertEquals(80000, $antes);
        $this->assertEquals($antes, $despues, 'un turno archivado sigue siendo contabilidad válida');
    }

    // ── 10-12 · Turno en curso, scope y rescate ─────────────────────────────

    public function test_el_turno_abierto_se_encuentra_aunque_haya_archivados(): void
    {
        $this->turnoCerrado()->update(['archived_at' => now()]);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);

        $r = $this->getJson('/api/admin/caja/shift?type=gym', $this->headers())->assertStatus(200);

        $this->assertNotNull($r->json('data'), 'el turno en curso no depende de archived_at');
        $this->assertSame('open', $r->json('data.status'));
    }

    public function test_no_hay_scope_global_el_archivado_se_resuelve_por_id(): void
    {
        $turno = $this->turnoCerrado();
        $turno->update(['archived_at' => now()]);

        // Sin scope global: la consulta normal del modelo lo encuentra. Esto es
        // lo que permite abrir su informe y explicar de dónde salió un pago.
        $this->assertNotNull(CashShift::find($turno->id));
        $this->assertSame(1, CashShift::where('id', $turno->id)->count());
        $this->assertTrue($turno->fresh()->isArchived());

        // Y el scope explícito sí lo excluye, que es su único trabajo.
        $this->assertSame(0, CashShift::query()->operationalHistory()->where('id', $turno->id)->count());
    }

    public function test_el_informe_de_un_turno_archivado_se_sigue_pudiendo_abrir(): void
    {
        $turno = $this->turnoCerrado();
        $turno->update(['archived_at' => now()]);

        // El informe no va envuelto en `data`: devuelve shift/transactions/
        // consistency. Lo que importa es que el turno archivado siga teniendo
        // informe, que es la razón de no esconderlo con un scope global.
        $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('shift.id', $turno->id);
    }

    // ── El día después de la entrega ────────────────────────────────────────

    public function test_el_primer_turno_nuevo_nace_visible_y_los_viejos_siguen_ocultos(): void
    {
        // Catorce cierres de la puesta en marcha, archivados en el corte.
        $viejos = [];
        for ($i = 0; $i < 3; $i++) {
            $viejos[] = $this->turnoCerrado(CashShiftType::GYM)->id;
            $viejos[] = $this->turnoCerrado(CashShiftType::PRODUCTS)->id;
        }
        CashShift::whereIn('id', $viejos)->update(['archived_at' => now()]);

        $this->assertSame([], $this->historial()['data'], 'el cliente arranca con el historial vacío');

        // El cliente abre y cierra su primera caja.
        $nuevo = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $r = $this->historial();
        $this->assertCount(1, $r['data'], 'su primer cierre, y solo el suyo');
        $this->assertSame($nuevo->id, $r['data'][0]['id']);
        $this->assertNull($r['data'][0]['archived_at']);

        // Y los archivados no reaparecen por haber llegado uno nuevo.
        $this->assertSame(6, CashShift::whereIn('id', $viejos)->whereNotNull('archived_at')->count());
    }
}
