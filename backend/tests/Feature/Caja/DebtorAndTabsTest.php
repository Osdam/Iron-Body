<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\TaxRate;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A quién se le fía, y cómo se encuentra la deuda después.
 *
 * LO QUE ESTO CIERRA
 * ------------------
 * Una venta a crédito se guardaba SIN persona: `product_sales.member_id` y
 * `customer_name` en null, así que en «Ventas y pedidos» el CLIENTE salía como
 * «—» aunque la cuenta por cobrar sí supiera quién debía. La misma obligación
 * se contaba de dos maneras, y una de las dos no servía para cobrar.
 *
 * Y el buscador de deudor mezclaba personas sin decir de qué tipo eran. Fiarle a
 * «Alejandro Casas» sin nada más es lo que hace imposible reclamar tres meses
 * después, cuando hay dos.
 *
 * LO QUE SE VIGILA
 * ----------------
 * Que la identidad del deudor sea una referencia y no un texto: un tipo y un id
 * que se resuelven contra su tabla. Que la persona elegida al fiar sea la misma
 * que aparece en la venta y en la cuenta. Y que las cuatro pestañas de Caja
 * digan exactamente lo que prometen a lo largo de toda la vida de una deuda.
 */
class DebtorAndTabsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Super',
            'email' => 'caja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function headers(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    private function member(string $nombre, string $documento): Member
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
            'document_number' => $documento,
            'phone' => '30'.random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
            'user_id' => $user->id,
        ]);
    }

    private function admin(string $nombre, string $rol): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    private function trainer(string $nombre, string $documento): Trainer
    {
        return Trainer::create([
            'full_name' => $nombre,
            'document' => $documento,
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@ironbody.test',
            'status' => 'active',
        ]);
    }

    private function product(string $nombre, float $precio, int $stock = 50): Product
    {
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => $nombre, 'category' => 'Bebidas',
            'sale_price' => $precio, 'cost_price' => $precio / 2,
            'stock' => $stock, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    private function openShift(CashShiftType $type): CashShift
    {
        return app(CashShiftService::class)->open($this->cajero, $type);
    }

    /** Fía un producto a quien sea y devuelve [venta, cuenta]. */
    private function fiar(DebtorType $tipo, int $id, float $precio = 3000): array
    {
        $agua = $this->product('AGUA '.uniqid(), $precio);

        $res = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true,
            'debtor_type' => $tipo->value,
            'debtor_id' => $id,
        ], $this->headers())->assertStatus(201);

        return [
            ProductSale::findOrFail($res->json('data.id')),
            Receivable::findOrFail($res->json('receivable.id')),
        ];
    }

    private function buscar(string $tipo, string $texto = ''): array
    {
        return $this->getJson(
            '/api/admin/receivables/debtors?type='.$tipo.'&search='.urlencode($texto),
            $this->headers(),
        )->assertOk()->json('data');
    }

    private function pestana(string $scope): array
    {
        return $this->getJson('/api/admin/receivables?scope='.$scope, $this->headers())
            ->assertOk()->json('data');
    }

    // ── 1-6. El buscador de deudor ────────────────────────────────────────

    public function test_buscar_un_miembro_devuelve_su_documento_y_su_tipo(): void
    {
        $this->member('Alejandro Casas', '1034778400');

        $r = $this->buscar('member', 'Alejandro');

        $this->assertCount(1, $r);
        $this->assertSame('Alejandro Casas', $r[0]['name']);
        $this->assertSame('1034778400', $r[0]['document']);
        $this->assertSame('member', $r[0]['type']);
        $this->assertSame('Miembro', $r[0]['type_label']);
    }

    public function test_buscar_un_administrador(): void
    {
        $this->admin('Carlos Gerente', 'Administrador');
        $this->admin('Ana Mostrador', 'Recepción');

        $r = $this->buscar('admin', 'Carlos');

        $this->assertCount(1, $r);
        $this->assertSame('Carlos Gerente', $r[0]['name']);
        $this->assertSame('Administrador', $r[0]['type_label']);
        // `admins` no guarda documento: se dice con el email, que es real, en
        // vez de enseñar un guion o inventar un número.
        $this->assertNull($r[0]['document']);
        $this->assertStringContainsString('@', $r[0]['contact']);
    }

    public function test_buscar_recepcion_no_devuelve_administradores(): void
    {
        $this->admin('Ana Mostrador', 'Recepción');
        $this->admin('Carlos Gerente', 'Administrador');

        $r = $this->buscar('reception');

        $nombres = array_column($r, 'name');
        $this->assertContains('Ana Mostrador', $nombres);
        $this->assertNotContains('Carlos Gerente', $nombres);
        $this->assertSame('Recepción', $r[0]['type_label']);
    }

    public function test_buscar_un_entrenador_lo_busca_en_su_propia_tabla(): void
    {
        $this->trainer('Daniel Moyano', '1075306783');
        $this->member('Daniel Moyano', '9999999');

        $r = $this->buscar('trainer', 'Daniel');

        $this->assertCount(1, $r, 'el socio homónimo no se cuela');
        $this->assertSame('1075306783', $r[0]['document']);
        $this->assertSame('Entrenador', $r[0]['type_label']);
    }

    public function test_cada_grupo_busca_solo_dentro_de_si_mismo(): void
    {
        $this->member('Ana Torres', '111');
        $this->admin('Ana Mostrador', 'Recepción');
        $this->trainer('Ana Entrena', '222');

        // El mismo nombre en tres tablas y ni un solo cruce entre grupos.
        $this->assertSame(['Ana Torres'], array_column($this->buscar('member', 'Ana'), 'name'));
        $this->assertSame(['Ana Mostrador'], array_column($this->buscar('reception', 'Ana'), 'name'));
        $this->assertSame(['Ana Entrena'], array_column($this->buscar('trainer', 'Ana'), 'name'));
    }

    public function test_un_tipo_inventado_se_rechaza(): void
    {
        $this->getJson('/api/admin/receivables/debtors?type=vecino', $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    // ── 7-9. La identidad viaja hasta el final ────────────────────────────

    public function test_fiar_guarda_una_identidad_y_no_un_nombre(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');

        [$venta, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id);

        // La cuenta apunta a la persona, no a un texto.
        $this->assertSame(DebtorType::MEMBER, $cuenta->debtor_type);
        $this->assertSame($socio->id, $cuenta->debtor_id);
        // Y `member_id` sigue en pie para lo que ya colgaba de ella.
        $this->assertSame($socio->id, $cuenta->member_id);

        // EL FALLO QUE ESTO CIERRA: la venta se guardaba sin persona.
        $this->assertSame($socio->id, $venta->member_id);
        $this->assertSame('Alejandro Casas', $venta->customer_name);
    }

    public function test_la_venta_y_la_cuenta_señalan_a_la_misma_persona(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');

        [$venta, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id);

        $ficha = $cuenta->debtor();
        $this->assertSame('Alejandro Casas', $ficha['name']);
        $this->assertSame('1034778400', $ficha['document']);
        $this->assertSame($venta->member_id, $ficha['id']);
        $this->assertSame($venta->id, $cuenta->source_id);
    }

    public function test_se_puede_fiar_a_recepcion_sin_inventarle_una_ficha_de_socio(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $ana = $this->admin('Ana Mostrador', 'Recepción');

        [$venta, $cuenta] = $this->fiar(DebtorType::RECEPTION, $ana->id);

        $this->assertSame(DebtorType::RECEPTION, $cuenta->debtor_type);
        $this->assertSame($ana->id, $cuenta->debtor_id);
        // NO se le fabrica una fila de socio: no lo es, y el histórico no puede
        // decir que la recepcionista se apuntó al gimnasio.
        $this->assertNull($cuenta->member_id);
        $this->assertSame(0, Member::where('full_name', 'Ana Mostrador')->count());

        // La venta no puede apuntarla por FK, pero sí dice de quién fue.
        $this->assertNull($venta->member_id);
        $this->assertSame('Ana Mostrador', $venta->customer_name);
        $this->assertSame('Recepción', $cuenta->debtor()['type_label']);
    }

    public function test_fiar_a_alguien_que_no_existe_se_rechaza(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $agua = $this->product('AGUA', 3000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true,
            'debtor_type' => 'trainer',
            'debtor_id' => 99999,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, Receivable::count());
        $this->assertSame(50, $agua->fresh()->stock, 'ni sale del almacén');
    }

    // ── 10-14. Las cuatro pestañas ────────────────────────────────────────

    public function test_la_matriz_completa_de_las_cuatro_pestanas(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');

        [, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id, 3000);
        $ids = fn (array $filas) => array_column($filas, 'id');

        // CASO 1 — crédito recién abierto: total 3.000, pagado 0.
        $this->assertContains($cuenta->id, $ids($this->pestana('all')), 'TODAS');
        $this->assertContains($cuenta->id, $ids($this->pestana('credits')), 'CRÉDITOS');
        $this->assertNotContains($cuenta->id, $ids($this->pestana('installments')), 'ABONOS');
        $this->assertNotContains($cuenta->id, $ids($this->pestana('paid')), 'PAGADAS');

        // CASO 2 — tras un abono de 1.000: quedan 2.000.
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertContains($cuenta->id, $ids($this->pestana('all')), 'TODAS');
        $this->assertContains($cuenta->id, $ids($this->pestana('credits')), 'CRÉDITOS');
        $this->assertContains($cuenta->id, $ids($this->pestana('installments')), 'ABONOS');
        $this->assertNotContains($cuenta->id, $ids($this->pestana('paid')), 'PAGADAS');

        // CASO 3 — liquidada: sale de todo lo pendiente y queda en el histórico.
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 2000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame(Receivable::STATUS_PAID, $cuenta->fresh()->status);
        $this->assertNotContains($cuenta->id, $ids($this->pestana('all')), 'TODAS');
        $this->assertNotContains($cuenta->id, $ids($this->pestana('credits')), 'CRÉDITOS');
        $this->assertNotContains($cuenta->id, $ids($this->pestana('installments')), 'ABONOS');
        $this->assertContains($cuenta->id, $ids($this->pestana('paid')), 'PAGADAS');
    }

    public function test_creditos_solo_muestra_deuda_nacida_de_una_venta(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');

        [, $deVenta] = $this->fiar(DebtorType::MEMBER, $socio->id);

        // Una deuda pactada en mostrador, sin venta detrás.
        $aMano = $this->postJson('/api/admin/receivables', [
            'debtor_type' => 'member', 'debtor_id' => $socio->id,
            'type' => CashShiftType::PRODUCTS->value,
            'concept' => 'Acuerdo de mostrador', 'total_amount' => 5000,
        ], $this->headers())->assertStatus(201)->json('data.id');

        $creditos = array_column($this->pestana('credits'), 'id');
        $todas = array_column($this->pestana('all'), 'id');

        $this->assertContains($deVenta->id, $creditos);
        $this->assertNotContains($aMano, $creditos, 'no nació de una venta');
        // Pero sigue siendo deuda viva.
        $this->assertContains($aMano, $todas);
    }

    public function test_abonos_no_lista_transacciones_sino_cuentas_a_plazos(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');
        [, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id, 3000);

        foreach ([500, 500] as $importe) {
            $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
                'amount' => $importe, 'method' => 'cash',
            ], $this->headers())->assertStatus(201);
        }

        $abonos = $this->pestana('installments');

        // Dos abonos, UNA cuenta: la pestaña lista a quién le están pagando a
        // plazos, no cada movimiento suelto.
        $this->assertCount(1, $abonos);
        $this->assertSame($cuenta->id, $abonos[0]['id']);
        $this->assertEqualsWithDelta(1000.0, $abonos[0]['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $abonos[0]['balance'], 0.01);
    }

    public function test_una_cuenta_cuyo_unico_abono_se_anula_deja_de_estar_a_plazos(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');
        [, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id, 3000);

        $abono = $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201)->json('data.id');

        $this->assertCount(1, $this->pestana('installments'));

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", [
            'reason' => 'Cobrado por error',
        ], $this->headers())->assertOk();

        // El saldo volvió a estar entero, así que ya no es una cuenta a plazos.
        $this->assertCount(0, $this->pestana('installments'));
        $this->assertContains($cuenta->id, array_column($this->pestana('credits'), 'id'));
    }

    /**
     * Abonos se apoya en los abonos, no en el estado guardado.
     *
     * `status` es un resumen denormalizado: lo mantiene el servicio al abonar y
     * al anular. Si alguna vez se desincroniza —una escritura directa, una
     * corrección a mano en la base de datos— una cuenta con dinero recibido
     * dejaría de aparecer donde recepción la busca. Preguntar por los abonos
     * aplicados no puede desincronizarse porque es el dato original.
     */
    public function test_abonos_encuentra_la_cuenta_aunque_su_estado_este_desincronizado(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');
        [, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id, 3000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        // Se estropea el resumen sin tocar los abonos, que son la verdad.
        Receivable::whereKey($cuenta->id)->update(['status' => Receivable::STATUS_PENDING]);

        $abonos = array_column($this->pestana('installments'), 'id');

        $this->assertContains($cuenta->id, $abonos, 'el dinero recibido sigue ahí');
    }

    /**
     * Una deuda a mano tampoco puede quedar «a nombre de nadie».
     *
     * La pantalla de Caja resuelve al deudor antes de fiar, pero este endpoint
     * es alcanzable directamente: la comprobación tiene que vivir también en el
     * servicio, o bastaría con una petición a mano para crear una cuenta que
     * nadie puede cobrar.
     */
    public function test_una_deuda_a_mano_con_deudor_inexistente_se_rechaza(): void
    {
        $this->postJson('/api/admin/receivables', [
            'debtor_type' => 'reception', 'debtor_id' => 99999,
            'type' => CashShiftType::PRODUCTS->value,
            'concept' => 'Acuerdo de mostrador', 'total_amount' => 5000,
        ], $this->headers())->assertStatus(422)
            ->assertJsonPath('code', 'unknown_debtor');

        $this->assertSame(0, Receivable::count());
    }

    public function test_pagadas_muestra_quien_pago_y_cuanto(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');
        [, $cuenta] = $this->fiar(DebtorType::MEMBER, $socio->id, 3000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 3000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $fila = $this->pestana('paid')[0];

        $this->assertSame('Alejandro Casas', $fila['debtor_name']);
        $this->assertSame('1034778400', $fila['debtor_document']);
        $this->assertSame('Miembro', $fila['debtor_type_label']);
        $this->assertStringContainsString('AGUA', $fila['concept']);
        $this->assertEqualsWithDelta(3000.0, $fila['total_amount'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $fila['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $fila['balance'], 0.01);
    }

    public function test_el_listado_combina_pestana_y_busqueda(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $ale = $this->member('Alejandro Casas', '1034778400');
        $otro = $this->member('Beatriz Ruiz', '2222222');

        [, $deAle] = $this->fiar(DebtorType::MEMBER, $ale->id);
        [, $deBea] = $this->fiar(DebtorType::MEMBER, $otro->id);

        $ids = array_column(
            $this->getJson('/api/admin/receivables?scope=credits&search=Beatriz', $this->headers())
                ->assertOk()->json('data'),
            'id',
        );

        $this->assertContains($deBea->id, $ids);
        $this->assertNotContains($deAle->id, $ids);
    }

    /**
     * La regresión del 422 que dejaba la pestaña Créditos en blanco.
     *
     * El CRM mandaba `outstanding=true`, y ese `true` viajaba como TEXTO. La
     * regla `boolean` de Laravel admite 1, 0, "1" y "0" pero NO "true", así que
     * el listado respondía 422 y en pantalla salía «No se pudo cargar las
     * cuentas por cobrar» — sin decir por qué, y solo en esa pestaña.
     */
    public function test_la_pestana_creditos_ya_no_depende_de_un_booleano_en_texto(): void
    {
        $this->openShift(CashShiftType::PRODUCTS);
        $socio = $this->member('Alejandro Casas', '1034778400');
        $this->fiar(DebtorType::MEMBER, $socio->id);

        // Así fallaba.
        $this->getJson('/api/admin/receivables?outstanding=true', $this->headers())
            ->assertStatus(422);

        // Y así funciona: un parámetro con nombre no se puede escribir mal de
        // esa manera.
        $this->getJson('/api/admin/receivables?scope=credits', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
