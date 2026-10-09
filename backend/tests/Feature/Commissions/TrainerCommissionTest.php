<?php

namespace Tests\Feature\Commissions;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL PISO DE LOS ENTRENADORES.
 *
 * El gimnasio cobra por cada cliente personalizado que se atiende dentro: unos
 * 50.000 al mes, aunque el valor se pacta trato por trato, y quién lo asume —el
 * entrenador o el cliente— lo deciden entre ellos.
 *
 * CÓMO ESTABA ESCRITO. Como un PLAN llamado «Comisión de personalizados», con
 * treinta días de duración. Y un plan define la membresía de quien lo tiene:
 * por eso aparecieron socios cuya vigencia la fijaba una comisión, con
 * membresías vencidas en 2027. Un cobro que no da acceso a nada estaba escrito
 * como si fuera una venta de acceso.
 *
 * LO QUE ESTOS TESTS FIJAN
 *   · el valor es del acuerdo, no del sistema, y lo que se cobró queda
 *     congelado aunque el trato cambie después;
 *   · se le reclama a quien lo asumió, que puede ser el cliente;
 *   · el dinero va a la caja del GIMNASIO y usa las cuentas por cobrar que ya
 *     existen, así que aparece en Pagos diciendo lo que es;
 *   · no se puede cobrar dos veces el mismo mes;
 *   · nada se genera solo: el panel avisa de a quién le falta.
 */
class TrainerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'comision-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);
    }

    private function trainer(string $nombre = 'Carlos Entrenador'): Trainer
    {
        return Trainer::create([
            'full_name' => $nombre,
            'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@ironbody.test',
            'status' => 'active',
        ]);
    }

    private function member(string $nombre = 'Ana Ruiz'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);

        return Member::create([
            'full_name' => $nombre,
            'email' => $user->email,
            'document_number' => (string) random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
            'user_id' => $user->id,
        ]);
    }

    private function abrirCajaDelGimnasio(): void
    {
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    /** @return array{0: TrainerCommissionAgreement, 1: Trainer, 2: Member} */
    private function acuerdo(float $valor = 50000, string $payer = 'trainer'): array
    {
        $entrenador = $this->trainer();
        $cliente = $this->member();

        $res = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id,
            'member_id' => $cliente->id,
            'amount' => $valor,
            'payer' => $payer,
        ], $this->h)->assertStatus(201)->json();

        return [TrainerCommissionAgreement::findOrFail($res['id']), $entrenador, $cliente];
    }

    private function mesActual(): string
    {
        return CarbonImmutable::now()->format('Y-m');
    }

    // ── El trato ────────────────────────────────────────────────────────────

    public function test_el_valor_lo_pone_el_acuerdo_y_no_el_sistema(): void
    {
        // 50.000 es lo habitual, no una regla: cada trato se pacta aparte.
        [$barato] = $this->acuerdo(45000);
        $otroEntrenador = $this->trainer('Marta Entrenadora');
        $otroCliente = $this->member('Sara Gómez');

        $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $otroEntrenador->id,
            'member_id' => $otroCliente->id,
            'amount' => 80000,
            'payer' => 'member',
        ], $this->h)->assertStatus(201)->assertJsonPath('amount', 80000);

        $this->assertSame('45000.00', $barato->refresh()->amount);
    }

    public function test_no_se_pactan_dos_pisos_a_la_vez_por_el_mismo_cliente(): void
    {
        [$acuerdo, $entrenador, $cliente] = $this->acuerdo();

        $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id,
            'member_id' => $cliente->id,
            'amount' => 70000,
            'payer' => 'trainer',
        ], $this->h)->assertStatus(422);

        $this->assertSame(1, TrainerCommissionAgreement::count());
        $this->assertSame('50000.00', $acuerdo->refresh()->amount);
    }

    public function test_terminar_el_trato_no_borra_lo_cobrado(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo();
        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201);

        $this->deleteJson("/api/admin/commissions/agreements/{$acuerdo->id}", [], $this->h)->assertOk();

        $this->assertFalse($acuerdo->refresh()->active);
        $this->assertNotNull($acuerdo->ended_at);
        $this->assertSame('Dueño Iron Body', $acuerdo->ended_by_name);
        // El mes cobrado sigue siendo cierto.
        $this->assertSame(1, TrainerCommissionCharge::where('agreement_id', $acuerdo->id)->count());
    }

    // ── El cobro ────────────────────────────────────────────────────────────

    private function cobrar(TrainerCommissionAgreement $a, array $cuerpo = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/admin/commissions/agreements/{$a->id}/charge",
            array_merge(['period' => $this->mesActual()], $cuerpo),
            $this->h,
        );
    }

    public function test_cobrar_el_mes_abre_una_deuda_del_gimnasio_que_dice_lo_que_es(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo, $entrenador, $cliente] = $this->acuerdo();

        $this->cobrar($acuerdo, ['method' => 'cash'])
            ->assertStatus(201)
            ->assertJsonPath('charge.state', 'paid');

        $deuda = Receivable::firstOrFail();

        // Caja del GIMNASIO, no la de productos: esto es ingreso del gimnasio.
        $this->assertSame(CashShiftType::GYM, $deuda->type);
        // Y el concepto se lee solo meses después, que es de lo que se trata.
        $this->assertStringContainsString('Piso de entrenador', $deuda->concept);
        $this->assertStringContainsString($entrenador->full_name, $deuda->concept);
        $this->assertStringContainsString($cliente->full_name, $deuda->concept);
        $this->assertTrue($deuda->balance()->isZero(), 'pagado en el acto, sin saldo');
    }

    public function test_sin_metodo_se_queda_debiendo_el_mes(): void
    {
        [$acuerdo] = $this->acuerdo();
        $vence = CarbonImmutable::now()->addDays(10)->toDateString();

        $res = $this->cobrar($acuerdo, ['due_at' => $vence])
            ->assertStatus(201)
            ->assertJsonPath('charge.state', 'open')
            ->assertJsonPath('charge.due_at', $vence);

        $this->assertSame(50000.0, (float) $res->json('charge.balance'));

        // Abrir la deuda NO exige caja abierta: no entró dinero todavía.
        $this->assertSame(1, Receivable::count());
    }

    public function test_el_mismo_mes_no_se_cobra_dos_veces(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo();

        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201);
        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(422);

        $this->assertSame(1, TrainerCommissionCharge::count());
        $this->assertSame(1, Receivable::count());
    }

    public function test_se_le_reclama_a_quien_lo_asume(): void
    {
        // Lo paga la clienta: la deuda es suya, no del entrenador.
        [$acuerdo, $entrenador, $cliente] = $this->acuerdo(payer: 'member');

        $this->cobrar($acuerdo)->assertStatus(201);

        $deuda = Receivable::firstOrFail();
        // Lo que decide a quien se le reclama es el TIPO de deudor: los ids de
        // `trainers` y `members` son tablas distintas y pueden coincidir.
        $this->assertSame(DebtorType::MEMBER, $deuda->debtor_type);
        $this->assertSame($cliente->id, $deuda->debtor_id);
        $this->assertSame($cliente->id, $deuda->member_id, 'y queda colgada de la ficha del socio');
        $this->assertNotNull($entrenador->id);
    }

    public function test_lo_cobrado_queda_congelado_aunque_el_trato_cambie(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo(50000);
        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201);

        // Se renegocia a 70.000 y pasa a asumirlo el cliente.
        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", [
            'amount' => 70000,
            'payer' => 'member',
        ], $this->h)->assertOk();

        $cobro = TrainerCommissionCharge::firstOrFail();
        $this->assertSame('50000.00', $cobro->amount, 'el mes ya cobrado no se reescribe');
        $this->assertSame('trainer', $cobro->payer);
        $this->assertSame('70000.00', $acuerdo->refresh()->amount);
    }

    public function test_un_mes_se_puede_cobrar_por_menos_sin_tocar_el_trato(): void
    {
        // Entró a mitad de mes: se le cobra la mitad, y el acuerdo no cambia.
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo(50000);

        $res = $this->cobrar($acuerdo, ['method' => 'cash', 'amount' => 25000])->assertStatus(201);
        $this->assertSame(25000.0, (float) $res->json('charge.amount'));

        $this->assertSame('50000.00', $acuerdo->refresh()->amount);
    }

    public function test_cobrar_en_el_acto_exige_la_caja_del_gimnasio_abierta(): void
    {
        // Sin turno no se puede meter dinero en ningún cajón. Se dice qué hacer.
        [$acuerdo] = $this->acuerdo();

        $res = $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(422);
        $this->assertStringContainsString('caja del gimnasio', $res->json('message'));

        // Y no queda un cobro a medias: ni deuda, ni mes registrado.
        $this->assertSame(0, TrainerCommissionCharge::count());
        $this->assertSame(0, Receivable::count());
    }

    // ── El panel del mes ────────────────────────────────────────────────────

    public function test_el_panel_avisa_de_a_quien_le_falta(): void
    {
        $this->abrirCajaDelGimnasio();
        [$pagado] = $this->acuerdo(50000);
        $this->cobrar($pagado, ['method' => 'cash'])->assertStatus(201);

        // Un segundo acuerdo sin tocar: es el que tiene que saltar.
        $otro = $this->trainer('Marta Entrenadora');
        $suCliente = $this->member('Sara Gómez');
        $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $otro->id, 'member_id' => $suCliente->id,
            'amount' => 60000, 'payer' => 'member',
        ], $this->h)->assertStatus(201);

        $panel = $this->getJson('/api/admin/commissions/board?period='.$this->mesActual(), $this->h)
            ->assertOk()
            ->json();

        $this->assertCount(2, $panel['rows']);
        $this->assertSame(110000.0, (float) $panel['totals']['agreed']);
        $this->assertSame(50000.0, (float) $panel['totals']['collected']);
        $this->assertSame(1, $panel['totals']['unregistered'], 'el que nadie registró');

        $estados = array_column($panel['rows'], 'state');
        sort($estados);
        $this->assertSame(['paid', 'pending'], $estados);
    }

    public function test_una_deuda_pasada_de_fecha_sale_como_vencida(): void
    {
        [$acuerdo] = $this->acuerdo();
        $this->cobrar($acuerdo, ['due_at' => CarbonImmutable::now()->subDay()->toDateString()])
            ->assertStatus(201);

        $panel = $this->getJson('/api/admin/commissions/board', $this->h)->assertOk()->json();

        $this->assertSame('overdue', $panel['rows'][0]['state']);
        $this->assertSame(1, $panel['totals']['overdue']);
        $this->assertSame(50000.0, (float) $panel['totals']['outstanding']);
    }

    public function test_el_mes_anterior_se_consulta_sin_mezclarse_con_el_actual(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo();
        $anterior = CarbonImmutable::now()->subMonth()->format('Y-m');

        $this->cobrar($acuerdo, ['period' => $anterior, 'method' => 'cash'])->assertStatus(201);

        $this->assertSame('paid', $this->getJson('/api/admin/commissions/board?period='.$anterior, $this->h)
            ->json('rows.0.state'));
        // Este mes sigue sin registrar: cobrar septiembre no paga octubre.
        $this->assertSame('pending', $this->getJson('/api/admin/commissions/board?period='.$this->mesActual(), $this->h)
            ->json('rows.0.state'));
    }

    public function test_el_historico_contesta_desde_cuando_viene_pagando(): void
    {
        $this->abrirCajaDelGimnasio();
        [$acuerdo] = $this->acuerdo();

        foreach ([2, 1, 0] as $atras) {
            $this->cobrar($acuerdo, [
                'period' => CarbonImmutable::now()->subMonths($atras)->format('Y-m'),
                'method' => 'cash',
            ])->assertStatus(201);
        }

        $historial = $this->getJson("/api/admin/commissions/agreements/{$acuerdo->id}/history", $this->h)
            ->assertOk()
            ->json('charges');

        $this->assertCount(3, $historial);
        // Del más reciente al más antiguo.
        $this->assertTrue($historial[0]['period'] > $historial[2]['period']);
        $this->assertSame(['paid', 'paid', 'paid'], array_column($historial, 'state'));
    }

    // ── Quién puede hacer qué ───────────────────────────────────────────────

    public function test_recepcion_cobra_pero_no_decide_el_precio(): void
    {
        [$acuerdo, $entrenador, $cliente] = $this->acuerdo();

        $this->h = $this->actingAsAdmin(Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'recepcion-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]));

        // Cobrar sí: es su trabajo cuando el entrenador se acerca.
        $this->cobrar($acuerdo, ['due_at' => CarbonImmutable::now()->addDays(5)->toDateString()])
            ->assertStatus(201);

        // Cambiar cuánto cuesta, no.
        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['amount' => 1000], $this->h)
            ->assertStatus(403);
        $this->assertSame('50000.00', $acuerdo->refresh()->amount);

        // Ni pactar uno nuevo.
        $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id, 'member_id' => $cliente->id,
            'amount' => 1000, 'payer' => 'trainer',
        ], $this->h)->assertStatus(403);
    }

    // ── ¿Le quita la app al cliente? ────────────────────────────────────────
    //
    // Antes la categoria decidia: cualquier deuda de gimnasio vencida retiraba
    // los beneficios de la app. Valia mientras todas fueran membresias. El
    // piso tambien es del gimnasio y NO es lo que el socio compro para usar la
    // app: perder las rutinas por una comision pactada entre su entrenador y
    // el gimnasio es una consecuencia que sorprende, asi que se marca.

    /** @return array{0: TrainerCommissionAgreement, 1: Member} */
    private function acuerdoDelCliente(bool $bloquea): array
    {
        $entrenador = $this->trainer();
        $cliente = $this->member();

        $res = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id,
            'member_id' => $cliente->id,
            'amount' => 50000,
            'payer' => 'member',
            'blocks_app' => $bloquea,
        ], $this->h)->assertStatus(201)->json();

        return [TrainerCommissionAgreement::findOrFail($res['id']), $cliente];
    }

    private function estaBloqueado(Member $cliente): bool
    {
        return app(\App\Services\Caja\MembershipFinancialStanding::class)->isOverdue($cliente->fresh());
    }

    public function test_por_defecto_el_piso_vencido_no_le_quita_la_app_al_cliente(): void
    {
        [$acuerdo, $cliente] = $this->acuerdoDelCliente(bloquea: false);

        $this->cobrar($acuerdo, ['due_at' => CarbonImmutable::now()->subDays(5)->toDateString()])
            ->assertStatus(201);

        // La deuda existe, esta vencida y se le reclama...
        $this->assertSame('overdue', $this->getJson('/api/admin/commissions/board', $this->h)
            ->json('rows.0.state'));
        // ...pero no le quita las rutinas ni la nutricion.
        $this->assertFalse($this->estaBloqueado($cliente));
    }

    public function test_si_se_marca_la_casilla_el_piso_vencido_si_le_quita_la_app(): void
    {
        [$acuerdo, $cliente] = $this->acuerdoDelCliente(bloquea: true);

        $this->cobrar($acuerdo, ['due_at' => CarbonImmutable::now()->subDays(5)->toDateString()])
            ->assertStatus(201);

        $this->assertTrue($this->estaBloqueado($cliente));
    }

    public function test_pagarlo_le_devuelve_la_app_en_el_acto(): void
    {
        // La mora se deriva del saldo, no se guarda: no hay proceso que correr.
        $this->abrirCajaDelGimnasio();
        [$acuerdo, $cliente] = $this->acuerdoDelCliente(bloquea: true);
        $this->cobrar($acuerdo, ['due_at' => CarbonImmutable::now()->subDays(5)->toDateString()])
            ->assertStatus(201);
        $this->assertTrue($this->estaBloqueado($cliente));

        $deuda = Receivable::firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 50000, 'method' => 'cash',
        ], $this->h)->assertSuccessful();

        $this->assertFalse($this->estaBloqueado($cliente));
    }

    public function test_una_membresia_impagada_sigue_bloqueando_como_siempre(): void
    {
        // Lo que no diga nada, bloquea: es lo que habia y no se toca.
        $cliente = $this->member();

        app(\App\Services\Caja\ReceivableService::class)->create(
            \App\Enums\DebtorType::MEMBER,
            $cliente->id,
            CashShiftType::GYM,
            'Plan mensual a plazos',
            \App\Services\Billing\Money::fromAmount(80000),
            $this->admin,
            null,
            null,
            CarbonImmutable::now()->subDays(3),
        );

        $this->assertTrue($this->estaBloqueado($cliente));
    }

    // ── Elegir a quien se le pacta ──────────────────────────────────────────

    public function test_los_socios_se_buscan_y_no_se_descargan_enteros(): void
    {
        // Son mas de tres mil. Una lista completa no se manda, y un
        // desplegable de tres mil opciones no resuelve nada.
        $this->member('Ana Ruiz');
        $this->member('Anabel Torres');
        $this->member('Pedro Nel');

        $this->assertArrayNotHasKey('members', $this->getJson('/api/admin/commissions/people', $this->h)
            ->assertOk()->json());

        $nombres = array_column(
            $this->getJson('/api/admin/commissions/members?q=ana', $this->h)->assertOk()->json('data'),
            'name',
        );
        sort($nombres);
        $this->assertSame(['Ana Ruiz', 'Anabel Torres'], $nombres);
    }

    public function test_al_cliente_tambien_se_le_busca_por_documento(): void
    {
        // Es como se busca a quien esta delante: con la cedula en la mano.
        $socio = $this->member('Ana Ruiz');

        $this->assertSame(
            $socio->id,
            $this->getJson('/api/admin/commissions/members?q='.$socio->document_number, $this->h)
                ->assertOk()
                ->json('data.0.id'),
        );
    }

    public function test_sin_termino_no_se_devuelve_una_lista_de_muestra(): void
    {
        // Una muestra invita a elegir de ella, y en tres mil socios casi nunca
        // contiene a quien se busca.
        $this->member('Ana Ruiz');

        $this->assertSame([], $this->getJson('/api/admin/commissions/members?q=a', $this->h)
            ->assertOk()->json('data'));
    }

    public function test_un_trato_se_puede_pactar_en_cero(): void
    {
        // Para dejar el acuerdo hecho mientras se decide el precio, y para
        // probar. El trato existe; lo que no existe todavia es el cobro.
        $entrenador = $this->trainer();
        $cliente = $this->member();

        $res = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id,
            'member_id' => $cliente->id,
            'amount' => 0,
            'payer' => 'trainer',
        ], $this->h)->assertStatus(201)->json();

        $this->assertSame(0.0, (float) $res['amount']);
    }

    public function test_pero_un_mes_no_se_cobra_en_cero(): void
    {
        // Una deuda de cero pesos no es una deuda: la rechaza la cuenta por
        // cobrar, que es la que manda sobre el dinero.
        $this->abrirCajaDelGimnasio();
        $acuerdo = TrainerCommissionAgreement::findOrFail(
            $this->postJson('/api/admin/commissions/agreements', [
                'trainer_id' => $this->trainer()->id,
                'member_id' => $this->member()->id,
                'amount' => 0,
                'payer' => 'trainer',
            ], $this->h)->assertStatus(201)->json('id'),
        );

        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(422);
        $this->assertSame(0, TrainerCommissionCharge::count());
        $this->assertSame(0, Receivable::count());
    }

    // ── Lo que se le dice a quien se equivoca ───────────────────────────────

    public function test_un_valor_invalido_se_explica_en_castellano(): void
    {
        // Al usuario le llegaba literalmente «validation.min.numeric». No es un
        // mensaje feo: es un formulario que no se puede corregir porque no dice
        // que le pasa. La app corre con APP_LOCALE=es y no habia traducciones.
        $this->app->setLocale('es');
        $entrenador = $this->trainer();
        $cliente = $this->member();

        $res = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id,
            'member_id' => $cliente->id,
            // Negativo: cero ya es válido, pero un piso en contra no.
            'amount' => -5,
            'payer' => 'trainer',
        ], $this->h)->assertStatus(422);

        $mensaje = $res->json('errors.amount.0');

        $this->assertStringNotContainsString('validation.', (string) $mensaje);
        $this->assertStringContainsString('valor', mb_strtolower((string) $mensaje));
    }

    public function test_el_campo_se_nombra_como_lo_llama_el_mostrador(): void
    {
        // «amount no puede ser menor que 1» no le sirve a nadie: quien esta en
        // el mostrador no sabe que es `amount`.
        $this->app->setLocale('es');

        $res = $this->postJson('/api/admin/commissions/agreements', [
            'member_id' => $this->member()->id,
            'amount' => 50000,
            'payer' => 'trainer',
        ], $this->h)->assertStatus(422);

        $this->assertStringContainsString(
            'entrenador',
            mb_strtolower((string) $res->json('errors.trainer_id.0')),
        );
    }

    public function test_los_tres_permisos_aparecen_en_la_pantalla_de_roles(): void
    {
        $catalogo = collect(\App\Support\Access\PermissionCatalog::rows());

        foreach (['commissions.view', 'commissions.charge', 'commissions.manage'] as $llave) {
            $fila = $catalogo->firstWhere('key', $llave);
            $this->assertNotNull($fila, $llave.' no se puede conceder desde Roles');
            $this->assertSame('commissions', $fila['domain']);
            $this->assertNotNull($fila['help']);
        }
    }
}
