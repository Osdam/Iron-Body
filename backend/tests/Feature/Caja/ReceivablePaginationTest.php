<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El listado de cuentas por cobrar, paginado en el servidor.
 *
 * LO QUE SE PROTEGE. Antes el endpoint devolvía `limit(300)` sin paginar y el
 * navegador filtraba lo que le llegaba. Con trescientas deudas eso ya era
 * descargarse doce veces lo que se enseña; a partir de la 301 pasaba algo peor:
 * la lista MENTÍA en silencio, porque el total pendiente se sumaba sobre lo que
 * había cabido. Un contador que depende de cuántas filas pidió el navegador no
 * es un contador.
 *
 * Y la semántica de las pestañas: SALDOS y ABONOS se solapan A PROPÓSITO. Un
 * plan de 80.000 con 70.000 abonados salía en «Abonos» y no en «Saldos», así
 * que quien preguntaba «¿quién me debe?» no veía los 10.000 que faltaban.
 */
class ReceivablePaginationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'pag-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function admin(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    private function socio(string $nombre = 'Alejandro Casas', ?string $documento = null): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'plan' => 'Premium',
            'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $nombre,
            'document_number' => $documento ?? (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function turno(CashShiftType $tipo = CashShiftType::GYM): CashShift
    {
        return CashShift::where('type', $tipo->value)->open()->first()
            ?? app(CashShiftService::class)->open($this->cajero, $tipo);
    }

    private function deuda(
        Member $socio,
        float $total = 100000,
        float $abonado = 0,
        CashShiftType $tipo = CashShiftType::GYM,
        string $concepto = 'Plan Premium',
    ): Receivable {
        $this->turno($tipo);

        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: $tipo,
            concept: $concepto,
            total: Money::fromAmount($total),
            actor: $this->cajero,
        );

        if ($abonado > 0) {
            app(ReceivableService::class)->settle(
                receivable: $cuenta,
                amount: Money::fromAmount($abonado),
                method: 'cash',
                actor: $this->cajero,
            );
        }

        return $cuenta->fresh();
    }

    /**
     * Deudas en bloque, sin pasar por el servicio.
     *
     * Para los casos de volumen: crear mil deudas por la vía normal abriría mil
     * transacciones y el test tardaría minutos en probar algo que es de SQL.
     *
     * @return list<int>
     */
    private function enBloque(Member $socio, int $cuantas, CashShiftType $tipo = CashShiftType::GYM): array
    {
        $ahora = now();
        $filas = [];
        for ($i = 0; $i < $cuantas; $i++) {
            $filas[] = [
                'member_id' => $socio->id, 'debtor_type' => DebtorType::MEMBER->value,
                'debtor_id' => $socio->id, 'type' => $tipo->value,
                'concept' => 'Volumen '.$i, 'total_amount' => 1000 + $i,
                'status' => Receivable::STATUS_PENDING,
                'created_at' => $ahora->copy()->subMinutes($i), 'updated_at' => $ahora,
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('receivables')->insert($lote);
        }

        return Receivable::where('concept', 'like', 'Volumen %')->pluck('id')->all();
    }

    private function pagina(string $query = ''): TestResponse
    {
        return $this->getJson('/api/admin/receivables'.($query ? '?'.$query : ''), $this->admin());
    }

    // ── 1-7 · El contrato de paginación ─────────────────────────────────────

    public function test_1_la_pagina_trae_25_filas_por_defecto(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 40);

        $r = $this->pagina()->assertOk();

        $this->assertCount(25, $r->json('data'));
        $this->assertSame(25, $r->json('meta.per_page'));
        $this->assertSame(1, $r->json('meta.current_page'));
        $this->assertSame(40, $r->json('meta.total'));
        $this->assertSame(2, $r->json('meta.last_page'));
    }

    public function test_2_el_tamano_de_pagina_esta_topado_en_100(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 150);

        // 100 es el techo y se sirve entero.
        $r = $this->pagina('per_page=100')->assertOk();
        $this->assertCount(100, $r->json('data'));
        $this->assertSame(100, $r->json('meta.per_page'));

        // Pedir más se RECHAZA, no se recorta en silencio: es la convención que
        // ya usan turnos y ventas, y un recorte callado hace creer a quien
        // integra que recibió todo lo que pidió.
        $this->pagina('per_page=5000')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_3_la_segunda_pagina_trae_el_resto(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 40);

        $r = $this->pagina('page=2')->assertOk();

        $this->assertCount(15, $r->json('data'));
        $this->assertSame(2, $r->json('meta.current_page'));
        $this->assertSame(26, $r->json('meta.from'));
        $this->assertSame(40, $r->json('meta.to'));
    }

    public function test_4_el_orden_es_estable_entre_consultas(): void
    {
        $socio = $this->socio();
        // Todas creadas en el mismo instante: sin desempate por id, el motor
        // puede devolverlas en cualquier orden y cada consulta daría otro.
        $ahora = now();
        $filas = [];
        for ($i = 0; $i < 30; $i++) {
            $filas[] = [
                'member_id' => $socio->id, 'debtor_type' => 'member', 'debtor_id' => $socio->id,
                'type' => 'gym', 'concept' => 'Empate '.$i, 'total_amount' => 1000,
                'status' => Receivable::STATUS_PENDING, 'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }
        DB::table('receivables')->insert($filas);

        $primera = array_column($this->pagina()->json('data'), 'id');
        $otraVez = array_column($this->pagina()->json('data'), 'id');

        $this->assertSame($primera, $otraVez, 'Dos consultas iguales deben devolver el mismo orden.');

        // Y se comprueba la cláusula que lo garantiza, porque el efecto NO es
        // observable aquí: SQLite devuelve las filas empatadas en orden de
        // rowid pase lo que pase. En PostgreSQL —que es lo que corre en
        // producción— sin el desempate por id el motor puede devolverlas en
        // cualquier orden, y entonces una misma deuda sale en la página 2 y en
        // la 3 mientras otra no sale en ninguna.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->pagina();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $listado = collect($consultas)->first(
            fn ($c) => str_contains($c['query'], 'from "receivables"')
                && str_contains($c['query'], 'order by'),
        );
        $this->assertNotNull($listado, 'No se encontró la consulta del listado.');
        $this->assertMatchesRegularExpression(
            '/order by .*created_at.* desc, .*"id" desc/i',
            $listado['query'],
            'El orden tiene que desempatar por id.',
        );
    }

    public function test_5_ninguna_fila_sale_en_dos_paginas_ni_se_pierde(): void
    {
        $socio = $this->socio();
        $creadas = $this->enBloque($socio, 60);

        $vistas = [];
        foreach ([1, 2, 3] as $p) {
            $vistas = array_merge($vistas, array_column($this->pagina("page={$p}")->json('data'), 'id'));
        }

        $this->assertSame(60, count($vistas), 'Se pierden o se repiten filas entre páginas.');
        $this->assertSame(count($vistas), count(array_unique($vistas)));
        sort($creadas);
        $ordenadas = $vistas;
        sort($ordenadas);
        $this->assertSame($creadas, $ordenadas);
    }

    public function test_6_la_busqueda_se_resuelve_en_el_servidor(): void
    {
        $buscado = $this->socio('Zoraida Perdomo', '1099887766');
        $otro = $this->socio('Alejandro Casas', '1003812287');
        $this->deuda($buscado, 50000);
        $this->deuda($otro, 70000);

        foreach (['Zoraida', '1099887766'] as $texto) {
            $r = $this->pagina('search='.urlencode($texto))->assertOk();
            $this->assertSame(1, $r->json('meta.total'), "Buscando «{$texto}»");
            $this->assertSame('Zoraida Perdomo', $r->json('data.0.debtor_name'));
        }

        // Y el total pendiente responde a la BÚSQUEDA, no a toda la base.
        $r = $this->pagina('search=Zoraida')->assertOk();
        $this->assertEqualsWithDelta(50000, $r->json('summary.outstanding_total'), 0.01);
    }

    public function test_7_el_filtro_de_caja_se_resuelve_en_el_servidor(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, 50000, tipo: CashShiftType::GYM);
        $this->deuda($socio, 30000, tipo: CashShiftType::PRODUCTS, concepto: 'Agua');

        $r = $this->pagina('type=products')->assertOk();
        $this->assertSame(1, $r->json('meta.total'));
        $this->assertSame('Agua', $r->json('data.0.concept'));
        $this->assertEqualsWithDelta(30000, $r->json('summary.outstanding_total'), 0.01);
    }

    // ── 8-14 · Pestañas, resumen y contadores ───────────────────────────────

    public function test_8_el_caso_que_estaba_mal_80_con_70_abonados(): void
    {
        // El caso exacto del QA: plan de 80.000, inicial de 60.000 y un abono de
        // 10.000. Faltan 10.000 y la deuda tiene que salir en SALDOS.
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, 80000, 60000);
        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(10000), method: 'cash', actor: $this->cajero,
        );

        $this->assertEqualsWithDelta(70000, $cuenta->fresh()->paidAmount()->toFloat(), 0.01);
        $this->assertEqualsWithDelta(10000, $cuenta->fresh()->balance()->toFloat(), 0.01);

        $ids = fn (string $scope) => array_column($this->pagina('scope='.$scope)->json('data'), 'id');

        $this->assertContains($cuenta->id, $ids('outstanding'), 'SALDOS: todavía deben 10.000');
        $this->assertContains($cuenta->id, $ids('installments'), 'ABONOS: ya recibió pagos');
        $this->assertNotContains($cuenta->id, $ids('paid'), 'PAGADAS: no está pagada');
        $this->assertContains($cuenta->id, $ids('all'), 'TODAS');
    }

    public function test_9_al_pagar_los_ultimos_10_cambia_de_pestana(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, 80000, 70000);

        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(10000), method: 'cash', actor: $this->cajero,
        );

        $ids = fn (string $scope) => array_column($this->pagina('scope='.$scope)->json('data'), 'id');

        $this->assertNotContains($cuenta->id, $ids('outstanding'), 'SALDOS: ya no debe nada');
        $this->assertNotContains($cuenta->id, $ids('installments'), 'ABONOS: solo cuentas abiertas');
        $this->assertContains($cuenta->id, $ids('paid'), 'PAGADAS');
    }

    public function test_10_saldos_incluye_deudas_sin_ningun_abono(): void
    {
        $socio = $this->socio();
        $virgen = $this->deuda($socio, 40000, 0);

        $ids = fn (string $scope) => array_column($this->pagina('scope='.$scope)->json('data'), 'id');

        $this->assertContains($virgen->id, $ids('outstanding'), 'Debe 40.000 y nadie ha abonado');
        $this->assertNotContains($virgen->id, $ids('installments'), 'No ha recibido abonos');
    }

    public function test_11_anuladas_tienen_su_pestana_y_salen_de_saldos(): void
    {
        $socio = $this->socio();
        $anulada = $this->deuda($socio, 40000, 10000);
        app(ReceivableService::class)->cancel($anulada, 'Error de mostrador en la venta.', $this->cajero);

        $ids = fn (string $scope) => array_column($this->pagina('scope='.$scope)->json('data'), 'id');

        $this->assertContains($anulada->id, $ids('cancelled'));
        $this->assertNotContains($anulada->id, $ids('outstanding'));
        $this->assertNotContains($anulada->id, $ids('installments'));
        $this->assertContains($anulada->id, $ids('all'), 'TODAS es el libro completo');
    }

    public function test_12_el_pendiente_no_cuenta_lo_anulado(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, 100000, 0);
        $anulada = $this->deuda($socio, 60000, 0);
        app(ReceivableService::class)->cancel($anulada, 'Se anula por acuerdo con el socio.', $this->cajero);

        // La fila anulada conserva su saldo —el total no se reescribe— pero ya
        // no se exige: contarla diría que hay 60.000 por recuperar.
        $r = $this->pagina('scope=all')->assertOk();
        $this->assertEqualsWithDelta(100000, $r->json('summary.outstanding_total'), 0.01);
        $this->assertSame(2, $r->json('meta.total'));
    }

    public function test_13_el_resumen_habla_del_universo_no_de_la_pagina(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 60);

        $esperado = (float) Receivable::sum('total_amount');

        foreach ([1, 2, 3] as $p) {
            $r = $this->pagina("page={$p}")->assertOk();
            $this->assertSame(60, $r->json('meta.total'), "página {$p}");
            $this->assertEqualsWithDelta($esperado, $r->json('summary.outstanding_total'), 0.01,
                "El pendiente no puede depender de en qué página esté mirando quien pregunta (página {$p}).");
            $this->assertSame(60, $r->json('summary.count'), "página {$p}");
        }
    }

    public function test_14_los_contadores_de_pestanas_los_calcula_sql(): void
    {
        $socio = $this->socio();
        $conAbonos = $this->deuda($socio, 80000, 70000);
        $virgen = $this->deuda($socio, 40000, 0);
        $pagada = $this->deuda($socio, 20000, 20000);
        $anulada = $this->deuda($socio, 30000, 5000);
        app(ReceivableService::class)->cancel($anulada, 'Anulada para el recuento de pestañas.', $this->cajero);

        $tabs = $this->pagina()->assertOk()->json('tabs');

        $this->assertSame(4, $tabs['all']);
        $this->assertSame(2, $tabs['outstanding'], 'la de 80/70 y la virgen');
        $this->assertSame(1, $tabs['installments'], 'solo la de 80/70: abierta y con abonos');
        $this->assertSame(1, $tabs['paid']);
        $this->assertSame(1, $tabs['cancelled']);
        $this->assertNotNull($conAbonos->id.$virgen->id.$pagada->id);
    }

    public function test_15_los_contadores_respetan_el_filtro_pero_no_la_pestana(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, 50000, 0, CashShiftType::GYM);
        $this->deuda($socio, 30000, 0, CashShiftType::PRODUCTS, 'Agua');

        // Filtrando por cafetería, las pestañas cuentan solo cafetería…
        $tabs = $this->pagina('type=products&scope=outstanding')->assertOk()->json('tabs');
        $this->assertSame(1, $tabs['all']);
        $this->assertSame(1, $tabs['outstanding']);

        // …pero el número de cada pestaña no cambia por estar mirando otra.
        $otra = $this->pagina('type=products&scope=paid')->assertOk()->json('tabs');
        $this->assertSame($tabs, $otra, 'Los contadores no pueden depender de la pestaña abierta.');
    }

    // ── 16-19 · Volumen ─────────────────────────────────────────────────────

    public function test_16_con_mil_deudas_la_pagina_sigue_siendo_de_25(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 1000);

        $r = $this->pagina()->assertOk();

        $this->assertCount(25, $r->json('data'));
        $this->assertSame(1000, $r->json('meta.total'));
        $this->assertSame(40, $r->json('meta.last_page'));
    }

    public function test_17_con_mil_deudas_el_numero_de_consultas_no_crece(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 40);
        $this->pagina();                       // calienta caches de sesión/permiso

        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->pagina()->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $conPocas = $contar();

        $this->enBloque($this->socio('Segundo Socio'), 1000);
        $conMuchas = $contar();

        // El coste de pintar la página no puede depender de cuántas deudas
        // existan: si crece, es que alguien volvió a consultar por fila.
        $this->assertSame($conPocas, $conMuchas,
            "Con 40 deudas: {$conPocas} consultas. Con 1.040: {$conMuchas}.");
        $this->assertLessThan(15, $conMuchas, 'Demasiadas consultas para una sola página.');
    }

    public function test_18_el_peso_de_la_respuesta_no_crece_con_la_base(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 30);
        $pequena = strlen($this->pagina()->getContent());

        $this->enBloque($this->socio('Otro Socio'), 1000);
        $grande = strlen($this->pagina()->getContent());

        // Margen amplio: los metadatos crecen unos bytes al pasar de 2 a 41
        // páginas. Lo que no puede pasar es que se multiplique.
        $this->assertLessThan($pequena * 1.2, $grande,
            "Respuesta con 30 deudas: {$pequena} bytes. Con 1.030: {$grande}.");
    }

    public function test_19_la_busqueda_sobre_volumen_sigue_acotada(): void
    {
        $socio = $this->socio();
        $this->enBloque($socio, 1000);
        $aguja = $this->socio('Zoraida Perdomo', '1099887766');
        $this->deuda($aguja, 50000);

        $r = $this->pagina('search=Zoraida')->assertOk();

        $this->assertSame(1, $r->json('meta.total'));
        $this->assertCount(1, $r->json('data'));
    }

    public function test_20_el_listado_no_dispara_una_consulta_por_fila(): void
    {
        $socio = $this->socio();
        for ($i = 0; $i < 10; $i++) {
            $this->deuda($this->socio("Socio {$i}"), 50000, 10000);
        }
        $this->pagina();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->pagina()->assertOk();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(10, $r->json('meta.total'));

        // Ni el saldo ni el deudor pueden costar una consulta por fila: el
        // saldo viaja en el propio SELECT y los deudores se resuelven juntos.
        $porFila = array_filter(
            $consultas,
            fn ($c) => str_contains($c['query'], 'receivable_payments')
                && str_contains($c['query'], 'receivable_id = '),
        );
        $this->assertCount(0, $porFila, 'Hay consultas de abonos fila a fila.');
        $this->assertLessThan(15, count($consultas));
        $this->assertNotNull($socio->id);
    }

    public function test_21_una_cuenta_sin_abonos_sigue_diciendo_cero_pagado(): void
    {
        // El `LEFT JOIN` agregado deja `applied_payments_sum_amount` en NULL
        // para quien no tiene abonos; si eso no se normaliza a cero, el saldo
        // sale mal justo en la deuda recién abierta.
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, 40000, 0);

        $fila = collect($this->pagina()->json('data'))->firstWhere('id', $cuenta->id);

        $this->assertEqualsWithDelta(0, $fila['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(40000, $fila['balance'], 0.01);
    }

    public function test_22_el_abono_revertido_no_cuenta_como_pagado(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, 80000, 30000);
        $abono = ReceivablePayment::where('receivable_id', $cuenta->id)->firstOrFail();

        app(ReceivableService::class)->reverse($abono, 'Se digitó el importe equivocado.', $this->cajero);

        $fila = collect($this->pagina()->json('data'))->firstWhere('id', $cuenta->id);
        $this->assertEqualsWithDelta(0, $fila['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(80000, $fila['balance'], 0.01);

        // Y vuelve a ser una deuda sin abonos: sale de ABONOS y sigue en SALDOS.
        $ids = fn (string $scope) => array_column($this->pagina('scope='.$scope)->json('data'), 'id');
        $this->assertNotContains($cuenta->id, $ids('installments'));
        $this->assertContains($cuenta->id, $ids('outstanding'));
    }
}
