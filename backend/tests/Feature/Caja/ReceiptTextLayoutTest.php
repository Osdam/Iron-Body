<?php

namespace Tests\Feature\Caja;

use App\Exceptions\ReceiptException;
use App\Services\Caja\ReceiptTextLayout;
use Illuminate\Support\Carbon;
use Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LA TIRILLA QUE MAQUETA EL SERVIDOR para el servicio local de impresión.
 *
 * Es un port de `thermal-receipt.ts` y `receipt-snapshot.ts` del CRM, y el papel
 * tiene que decir LO MISMO venga por el navegador o por el servicio:
 *
 *  · Los casos son los de `thermal-receipt.spec.ts` y `receipt-snapshot.spec.ts`
 *    con sus mismos textos esperados.
 *  · Tres tirillas completas se comparan LÍNEA A LÍNEA con lo que produce el TS
 *    (salida real del TS del commit 55ac7e5 del CRM, generada con esbuild +
 *    node; ver informe-backend-agente.md).
 *  · Diferencias deliberadas (contrato §2): el título se parte a `columns` y no
 *    a 22; estilos solo normal|bold|title; nada de controles; 32 o 42 columnas.
 *  · Invariantes de contrato sobre casos extremos: ≤ columns puntos de código,
 *    NFC, sin controles, 1..400 líneas.
 */
class ReceiptTextLayoutTest extends TestCase
{
    private ReceiptTextLayout $layout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->layout = new ReceiptTextLayout;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Estructuras de los specs del CRM ────────────────────────────────────

    /** `base()` de thermal-receipt.spec.ts. */
    private function base(array $extra = []): array
    {
        return array_merge([
            'business' => ['name' => 'IRON BODY WORKOUT', 'nit' => '900.000.000-1', 'address' => 'Calle 1 # 2-3, Neiva', 'phone' => '3000000000'],
            'number' => 'RC-000123',
            'operationRef' => 'PAG-000123',
            'dateTime' => '16/10/2026 17:30',
            'cashbox' => 'Caja de productos',
            'cashier' => 'Recepción Sintética',
            'customer' => null,
            'items' => [],
            'subtotal' => 0,
            'total' => 0,
            'payments' => [],
        ], $extra);
    }

    /** `recibo()` de receipt-snapshot.spec.ts: la forma de POST /admin/receipts. */
    private function recibo(array $snapshot = [], array $extra = []): array
    {
        return array_merge([
            'uuid' => '6f1c2a90-0000-4000-8000-000000000001',
            'number' => 'RC-000123',
            'cash_type' => 'gym',
            'operation_ref' => 'PAG-77',
            'snapshot' => array_merge([
                'business' => [
                    'name' => 'IRON BODY WORKOUT', 'legal_name' => 'Iron Body Neiva S.A.S.', 'nit' => '900.000.000-1',
                    'address' => 'Calle 1 # 2-3, Neiva', 'phone' => '3000000000',
                ],
                'title' => 'Recibo de caja',
                'cash_label' => 'Caja del gimnasio',
                'operation_ref' => 'PAG-77',
                'cashier' => 'Ana Recepción',
                'customer' => 'Socio Sintético',
                'local_datetime' => '16/10/2026 17:30',
                'lines' => [],
                'totals' => ['subtotal' => 0, 'discount' => 0, 'taxes' => [], 'total' => 0, 'paid_now' => 0, 'balance_after' => null],
                'payments' => [],
                'membership' => null,
                'debt' => null,
                'legend' => 'Documento no válido como factura electrónica',
            ], $snapshot),
            'live' => ['operation_status' => 'paid', 'invoice' => null],
        ], $extra);
    }

    /** @return list<string> */
    private function textoT(array $r, int $cols = 32): array
    {
        return array_column($this->layout->lines($r, $cols), 'text');
    }

    private function textoR(array $recibo, ?string $copia = null, int $cols = 32): string
    {
        return implode("\n", array_column($this->layout->lines($this->layout->thermal($recibo, $copia), $cols), 'text'));
    }

    private function gymSnapshot(): array
    {
        return [
            'lines' => [['description' => 'Plan Mensual', 'quantity' => 1, 'unit_price' => 80000, 'discount' => 0, 'total' => 80000]],
            'totals' => ['subtotal' => 80000, 'discount' => 0, 'taxes' => [], 'total' => 80000, 'paid_now' => 40000, 'balance_after' => 40000],
            'payments' => [['method_label' => 'Nequi', 'amount' => 40000, 'reference' => 'NQ-55120']],
            'membership' => ['plan' => 'Plan Mensual', 'period_from' => '16/10/2026', 'period_to' => '15/11/2026', 'upgraded_from' => null],
        ];
    }

    private function productosSnapshot(): array
    {
        return [
            'cash_label' => 'Caja de productos',
            'operation_ref' => 'V-0012',
            'lines' => [
                ['description' => 'Agua 600 ml', 'quantity' => 3, 'unit_price' => 2500, 'discount' => 0, 'total' => 7500],
                ['description' => 'Barra de proteína', 'quantity' => 2, 'unit_price' => 6000, 'discount' => 1000, 'total' => 11000],
            ],
            'totals' => ['subtotal' => 19500, 'discount' => 1000, 'taxes' => [['label' => 'IVA 19% (incluido)', 'amount' => 2954]], 'total' => 18500, 'paid_now' => 18500, 'balance_after' => null],
            'payments' => [
                ['method_label' => 'Efectivo', 'amount' => 10000, 'reference' => null],
                ['method_label' => 'Tarjeta', 'amount' => 8500, 'reference' => 'AUT-9981'],
            ],
        ];
    }

    /** Las garantías de §2 sobre cualquier salida. */
    private function assertContrato(array $lineas, int $cols, string $caso = ''): void
    {
        $this->assertNotEmpty($lineas, $caso);
        $this->assertLessThanOrEqual(ReceiptTextLayout::MAX_LINES, count($lineas), $caso);
        foreach ($lineas as $l) {
            $this->assertContains($l['style'], ['normal', 'bold', 'title'], $caso);
            $this->assertLessThanOrEqual($cols, mb_strlen($l['text']), $caso.': '.$l['text']);
            $this->assertSame(0, preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $l['text']), $caso.': control en '.json_encode($l['text']));
            $this->assertSame(Normalizer::normalize($l['text'], Normalizer::FORM_C), $l['text'], $caso.': no es NFC');
            if ($l['style'] === 'title') {
                $this->assertSame(trim($l['text']), $l['text'], 'el título va sin espacios de relleno: lo centra la impresora');
            }
        }
        // Idempotente para el servicio: la validación del contrato no lanza.
        $this->layout->assertPrintable($lineas, $cols);
    }

    // ── Línea a línea contra el TS ──────────────────────────────────────────

    public function test_membresia_a_plazos_identica_al_crm_linea_a_linea(): void
    {
        $esperado = [
            ['IRON BODY WORKOUT', 'title'],
            ['     Iron Body Neiva S.A.S.', 'normal'],
            ['       NIT 900.000.000-1', 'normal'],
            ['      Calle 1 # 2-3, Neiva', 'normal'],
            ['        Tel. 3000000000', 'normal'],
            ['--------------------------------', 'normal'],
            ['         Recibo de caja', 'bold'],
            ['Recibo                 RC-000123', 'bold'],
            ['Comprobante               PAG-77', 'normal'],
            ['Fecha           16/10/2026 17:30', 'normal'],
            ['Caja           Caja del gimnasio', 'normal'],
            ['Cajero             Ana Recepción', 'normal'],
            ['Cliente          Socio Sintético', 'normal'],
            ['--------------------------------', 'normal'],
            ['Plan Mensual', 'normal'],
            ['1 x $ 80.000            $ 80.000', 'normal'],
            ['--------------------------------', 'normal'],
            ['Plan                Plan Mensual', 'normal'],
            ['Vigencia 16/10/2026 - 15/11/2026', 'normal'],
            ['--------------------------------', 'normal'],
            ['Subtotal                $ 80.000', 'normal'],
            ['TOTAL                   $ 80.000', 'bold'],
            ['Pagado hoy              $ 40.000', 'bold'],
            ['Saldo pendiente         $ 40.000', 'bold'],
            ['Nequi                   $ 40.000', 'normal'],
            ['Ref.                    NQ-55120', 'normal'],
            ['--------------------------------', 'normal'],
            ['Documento no válido como factura', 'normal'],
            ['          electrónica', 'normal'],
            ['   ¡Gracias por entrenar con', 'normal'],
            ['           nosotros!', 'normal'],
        ];

        $lineas = $this->layout->lines($this->layout->thermal($this->recibo($this->gymSnapshot())));

        $this->assertSame($esperado, array_map(fn ($l) => [$l['text'], $l['style']], $lineas));
        $this->assertContrato($lineas, 32);
    }

    public function test_venta_de_productos_identica_al_crm_linea_a_linea(): void
    {
        $esperado = [
            ['IRON BODY WORKOUT', 'title'],
            ['     Iron Body Neiva S.A.S.', 'normal'],
            ['       NIT 900.000.000-1', 'normal'],
            ['      Calle 1 # 2-3, Neiva', 'normal'],
            ['        Tel. 3000000000', 'normal'],
            ['--------------------------------', 'normal'],
            ['         Recibo de caja', 'bold'],
            ['Recibo                 RC-000123', 'bold'],
            ['Comprobante               V-0012', 'normal'],
            ['Fecha           16/10/2026 17:30', 'normal'],
            ['Caja           Caja de productos', 'normal'],
            ['Cajero             Ana Recepción', 'normal'],
            ['Cliente          Socio Sintético', 'normal'],
            ['--------------------------------', 'normal'],
            ['Agua 600 ml', 'normal'],
            ['3 x $ 2.500              $ 7.500', 'normal'],
            ['Barra de proteína', 'normal'],
            ['2 x $ 6.000             $ 11.000', 'normal'],
            ['Descuento               -$ 1.000', 'normal'],
            ['--------------------------------', 'normal'],
            ['Subtotal                $ 19.500', 'normal'],
            ['Descuentos              -$ 1.000', 'normal'],
            ['TOTAL                   $ 18.500', 'bold'],
            ['IVA 19% (incluido)       $ 2.954', 'normal'],
            ['Efectivo                $ 10.000', 'normal'],
            ['Tarjeta                  $ 8.500', 'normal'],
            ['Ref.                    AUT-9981', 'normal'],
            ['--------------------------------', 'normal'],
            ['Documento no válido como factura', 'normal'],
            ['          electrónica', 'normal'],
            ['   ¡Gracias por entrenar con', 'normal'],
            ['           nosotros!', 'normal'],
        ];

        $lineas = $this->layout->lines($this->layout->thermal($this->recibo($this->productosSnapshot(), ['cash_type' => 'products'])));

        $this->assertSame($esperado, array_map(fn ($l) => [$l['text'], $l['style']], $lineas));
        $this->assertContrato($lineas, 32);
    }

    public function test_a_42_columnas_con_reintento_identica_al_crm_linea_a_linea(): void
    {
        $esperado = [
            ['           *** REINTENTO 3 ***', 'bold'],
            ['IRON BODY WORKOUT', 'title'],
            ['          Iron Body Neiva S.A.S.', 'normal'],
            ['            NIT 900.000.000-1', 'normal'],
            ['           Calle 1 # 2-3, Neiva', 'normal'],
            ['             Tel. 3000000000', 'normal'],
            ['------------------------------------------', 'normal'],
            ['              Recibo de caja', 'bold'],
            ['Recibo                           RC-000123', 'bold'],
            ['Comprobante                         PAG-77', 'normal'],
            ['Fecha                     16/10/2026 17:30', 'normal'],
            ['Caja                     Caja del gimnasio', 'normal'],
            ['Cajero                       Ana Recepción', 'normal'],
            ['Cliente                    Socio Sintético', 'normal'],
            ['------------------------------------------', 'normal'],
            ['Plan Mensual', 'normal'],
            ['1 x $ 80.000                      $ 80.000', 'normal'],
            ['------------------------------------------', 'normal'],
            ['Plan                          Plan Mensual', 'normal'],
            ['Vigencia           16/10/2026 - 15/11/2026', 'normal'],
            ['------------------------------------------', 'normal'],
            ['Subtotal                          $ 80.000', 'normal'],
            ['TOTAL                             $ 80.000', 'bold'],
            ['Pagado hoy                        $ 40.000', 'bold'],
            ['Saldo pendiente                   $ 40.000', 'bold'],
            ['Nequi                             $ 40.000', 'normal'],
            ['Ref.                              NQ-55120', 'normal'],
            ['------------------------------------------', 'normal'],
            ['     Documento no válido como factura', 'normal'],
            ['               electrónica', 'normal'],
            ['   ¡Gracias por entrenar con nosotros!', 'normal'],
            ['           *** REINTENTO 3 ***', 'bold'],
        ];

        $lineas = $this->layout->lines($this->layout->thermal($this->recibo($this->gymSnapshot()), 'REINTENTO 3'), 42);

        $this->assertSame($esperado, array_map(fn ($l) => [$l['text'], $l['style']], $lineas));
        $this->assertContrato($lineas, 42);
    }

    // ── thermal-receipt.spec.ts ─────────────────────────────────────────────

    public function test_ninguna_linea_pasa_de_32_columnas_ni_con_textos_larguisimos(): void
    {
        $r = $this->base([
            'customer' => 'María José Peñaloza Ñañez de los Ríos Ospina Gutiérrez',
            'items' => [
                ['description' => 'Proteína Whey Isolate sabor chocolate con avellanas edición limitada 2 libras', 'quantity' => 2, 'unitPrice' => 189900, 'total' => 379800],
                ['description' => 'Supercalifragilisticoespialidosoextralarguísimo', 'quantity' => 1, 'unitPrice' => 5000, 'total' => 5000],
            ],
            'subtotal' => 384800,
            'total' => 384800,
            'payments' => [['method' => 'Transferencia bancaria Bancolombia', 'amount' => 384800]],
        ]);

        $this->assertContrato($this->layout->lines($r), 32);
    }

    public function test_tildes_y_enes_cuentan_como_un_caracter_y_se_conservan(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'customer' => 'Andrés Núñez',
            'items' => [['description' => 'Bebida energética ñame', 'quantity' => 1, 'unitPrice' => 4500, 'total' => 4500]],
            'subtotal' => 4500, 'total' => 4500,
        ])));
        $this->assertStringContainsString('Andrés Núñez', $t);
        $this->assertStringContainsString('Bebida energética ñame', $t);

        // Una «é» descompuesta (e + acento) se normaliza a un solo carácter.
        $nfd = Normalizer::normalize('Café', Normalizer::FORM_D);
        $this->assertSame('Café', $this->layout->wrap($nfd)[0]);
        $this->assertSame(4, mb_strlen($this->layout->wrap($nfd)[0]));
    }

    public function test_el_importe_queda_pegado_al_borde_derecho(): void
    {
        [$l] = $this->layout->pair('Subtotal', $this->layout->money(12000));
        $this->assertSame(32, mb_strlen($l));
        $this->assertStringEndsWith('$ 12.000', $l);
        $this->assertStringStartsWith('Subtotal', $l);
    }

    public function test_si_no_cabe_el_texto_se_parte_y_el_importe_sigue_a_la_derecha(): void
    {
        $lineas = $this->layout->pair('Proteína Whey Isolate sabor chocolate con avellanas', $this->layout->money(189900));
        $this->assertGreaterThan(1, count($lineas));
        $this->assertStringEndsWith('$ 189.900', $lineas[count($lineas) - 1]);
        foreach ($lineas as $l) {
            $this->assertLessThanOrEqual(32, mb_strlen($l));
        }
    }

    public function test_pesos_colombianos_sin_decimales_y_con_punto_de_miles(): void
    {
        $this->assertSame('$ 80.000', $this->layout->money(80000));
        $this->assertSame('$ 1.234.567', $this->layout->money(1234567.4));
        $this->assertSame('$ 0', $this->layout->money(0));
        // Mismos bordes que Intl es-CO + Math.round (comprobados contra node).
        $this->assertSame('$ 1.000', $this->layout->money(1000));
        $this->assertSame('$ 999', $this->layout->money(999));
        $this->assertSame('$ 3', $this->layout->money(2.5));
        $this->assertSame('-$ 2', $this->layout->money(-2.5));
        $this->assertSame('-$ 1.000', $this->layout->money(-1000));
        $this->assertSame('$ 1.000.000', $this->layout->money(999999.5));
    }

    public function test_venta_de_varios_productos(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'items' => [
                ['description' => 'Agua 600 ml', 'quantity' => 3, 'unitPrice' => 2500, 'total' => 7500],
                ['description' => 'Barra de proteína', 'quantity' => 2, 'unitPrice' => 6000, 'discount' => 1000, 'total' => 11000],
            ],
            'subtotal' => 19500,
            'discount' => 1000,
            'taxes' => [['label' => 'IVA 19%', 'amount' => 0]],
            'total' => 18500,
            'payments' => [['method' => 'Efectivo', 'amount' => 18500]],
            'received' => 20000,
            'change' => 1500,
        ])));
        $this->assertStringContainsString('3 x $ 2.500', $t);
        $this->assertMatchesRegularExpression('/2 x \$ 6\.000\s+\$ 11\.000/', $t);
        $this->assertMatchesRegularExpression('/Descuentos\s+-\$ 1\.000/', $t);
        $this->assertMatchesRegularExpression('/IVA 19%\s+\$ 0/', $t);
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 18\.500/', $t);
        $this->assertMatchesRegularExpression('/Efectivo\s+\$ 18\.500/', $t);
        $this->assertMatchesRegularExpression('/Recibido\s+\$ 20\.000/', $t);
        $this->assertMatchesRegularExpression('/Cambio\s+\$ 1\.500/', $t);
    }

    public function test_pago_de_membresia_a_plazos(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'cashbox' => 'Caja del gimnasio',
            'customer' => 'Socio Sintético',
            'items' => [['description' => 'Plan Mensual', 'quantity' => 1, 'unitPrice' => 80000, 'total' => 80000]],
            'membership' => ['plan' => 'Plan Mensual', 'periodFrom' => '16/10/2026', 'periodTo' => '15/11/2026'],
            'subtotal' => 80000, 'total' => 80000, 'paidNow' => 40000, 'balanceAfter' => 40000,
            'payments' => [['method' => 'Nequi', 'amount' => 40000]],
        ])));
        $this->assertMatchesRegularExpression('/Plan\s+Plan Mensual/', $t);
        $this->assertStringContainsString('16/10/2026 - 15/11/2026', $t);
        $this->assertMatchesRegularExpression('/Pagado hoy\s+\$ 40\.000/', $t);
        $this->assertMatchesRegularExpression('/Saldo pendiente\s+\$ 40\.000/', $t);
    }

    public function test_sin_saldo_pendiente_no_se_imprime_la_linea_del_saldo(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'membership' => ['plan' => 'Plan Mensual'], 'subtotal' => 80000, 'total' => 80000, 'paidNow' => 80000, 'balanceAfter' => 0,
        ])));
        $this->assertStringNotContainsString('Saldo pendiente', $t);
        $this->assertStringNotContainsString('Pagado hoy', $t);
    }

    public function test_la_mejora_de_plan_dice_de_que_plan_viene(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'membership' => ['plan' => 'Total Access', 'upgradedFrom' => 'Plan Mensual'], 'subtotal' => 70000, 'total' => 70000,
        ])));
        $this->assertMatchesRegularExpression('/Mejora desde\s+Plan Mensual/', $t);
    }

    public function test_abono_a_una_deuda(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'debt' => ['concept' => 'Piso de entrenador · Carlos → Olga · octubre', 'balanceBefore' => 120000, 'balanceAfter' => 70000, 'dueAt' => '31/10/2026'],
            'subtotal' => 50000, 'total' => 50000, 'balanceAfter' => 70000,
            'payments' => [['method' => 'Transferencia', 'amount' => 50000, 'reference' => 'TRX-99812']],
        ])));
        $this->assertStringContainsString('Piso de entrenador', $t);
        $this->assertMatchesRegularExpression('/Saldo anterior\s+\$ 120\.000/', $t);
        $this->assertMatchesRegularExpression('/Saldo actual\s+\$ 70\.000/', $t);
        $this->assertMatchesRegularExpression('/Fecha límite\s+31\/10\/2026/u', $t);
        $this->assertStringNotContainsString('Saldo pendiente', $t);
        $this->assertMatchesRegularExpression('/Transferencia\s+\$ 50\.000/', $t);
        $this->assertMatchesRegularExpression('/Ref\.\s+TRX-99812/', $t);
    }

    public function test_el_iva_incluido_va_debajo_del_total(): void
    {
        $lineas = $this->textoT($this->base([
            'items' => [['description' => 'Proteína', 'quantity' => 1, 'unitPrice' => 119000, 'total' => 119000]],
            'subtotal' => 119000, 'taxes' => [['label' => 'IVA 19% (incluido)', 'amount' => 19000]], 'total' => 119000,
        ]));
        $total = $this->indice($lineas, 'TOTAL');
        $iva = $this->indice($lineas, 'IVA 19% (incluido)');
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 119\.000$/', $lineas[$total]);
        $this->assertGreaterThan($total, $iva);
        $this->assertMatchesRegularExpression('/\$ 19\.000$/', $lineas[$iva]);
    }

    public function test_vigencia_y_fecha_limite_tal_cual_llegan(): void
    {
        $t = implode("\n", $this->textoT($this->base([
            'membership' => ['plan' => 'Plan Mensual', 'periodFrom' => '01/11/2026', 'periodTo' => '30/11/2026'],
            'debt' => ['concept' => 'Saldo plan', 'balanceBefore' => 0, 'balanceAfter' => 40000, 'dueAt' => '15/11/2026'],
        ])));
        $this->assertStringContainsString('01/11/2026 - 30/11/2026', $t);
        $this->assertMatchesRegularExpression('/Fecha límite\s+15\/11\/2026/u', $t);
    }

    public function test_fecha_numero_y_comprobante(): void
    {
        $this->assertMatchesRegularExpression('/Fecha\s+16\/10\/2026 22:30/', implode("\n", $this->textoT($this->base(['dateTime' => '16/10/2026 22:30']))));
        $t = implode("\n", $this->textoT($this->base()));
        $this->assertMatchesRegularExpression('/Recibo\s+RC-000123/', $t);
        $this->assertMatchesRegularExpression('/Comprobante\s+PAG-000123/', $t);
    }

    public function test_se_distingue_de_la_factura_electronica(): void
    {
        $this->assertStringContainsString('Documento no válido como', implode("\n", $this->textoT($this->base())));
        $this->assertStringContainsString('Leyenda que dicta el servidor', implode("\n", $this->textoT($this->base(['legend' => 'Leyenda que dicta el servidor']))));
        $conFactura = implode("\n", $this->textoT($this->base(['invoiceRef' => 'SETP 990000123'])));
        $this->assertStringContainsString('Documento no válido como', $conFactura);
        $this->assertStringContainsString('Ref. factura electrónica SETP', $conFactura);
    }

    public function test_reimpresion_y_reintento_arriba_y_abajo(): void
    {
        $reimpresa = $this->textoT($this->base(['copyLabel' => 'REIMPRESIÓN']));
        $this->assertStringContainsString('REIMPRESIÓN', $reimpresa[0]);
        $this->assertStringContainsString('REIMPRESIÓN', $reimpresa[count($reimpresa) - 1]);
        $this->assertStringNotContainsString('REIMPRESIÓN', implode("\n", $this->textoT($this->base())));

        $reintento = $this->textoT($this->base(['copyLabel' => 'REINTENTO 2']));
        $this->assertStringContainsString('*** REINTENTO 2 ***', $reintento[0]);
        $this->assertStringContainsString('*** REINTENTO 2 ***', $reintento[count($reintento) - 1]);
    }

    /**
     * DIFERENCIA DELIBERADA con el TS: allí el título se parte a 22 (salía a
     * 1,4× en el navegador); en ESC/POS sale a doble ALTO, que no estrecha la
     * línea, así que se parte a `columns`.
     */
    public function test_el_titulo_se_parte_a_columns_y_no_a_22(): void
    {
        $nombre = 'IRON BODY WORKOUT CENTRO DE ENTRENAMIENTO FUNCIONAL';
        foreach ([32, 42] as $cols) {
            $titulos = array_values(array_filter($this->layout->lines($this->base(['business' => ['name' => $nombre]]), $cols), fn ($l) => $l['style'] === 'title'));
            $this->assertSame($nombre, implode(' ', array_column($titulos, 'text')));
            foreach ($titulos as $l) {
                $this->assertLessThanOrEqual($cols, mb_strlen($l['text']));
            }
            $this->assertGreaterThan(22, mb_strlen($titulos[0]['text']), 'a todo el ancho, no a 22');
        }
        $this->assertSame('IRON BODY WORKOUT', collect($this->layout->lines($this->base()))->firstWhere('style', 'title')['text']);
    }

    // ── receipt-snapshot.spec.ts ────────────────────────────────────────────

    public function test_gimnasio_membresia_con_vigencia_pagado_hoy_y_saldo(): void
    {
        $t = $this->textoR($this->recibo($this->gymSnapshot()));
        foreach ([
            '/Recibo\s+RC-000123/', '/Comprobante\s+PAG-77/', '/Caja\s+Caja del gimnasio/', '/Cajero\s+Ana Recepción/u',
            '/Plan\s+Plan Mensual/', '/TOTAL\s+\$ 80\.000/', '/Pagado hoy\s+\$ 40\.000/', '/Saldo pendiente\s+\$ 40\.000/',
            '/Nequi\s+\$ 40\.000/', '/Ref\.\s+NQ-55120/',
        ] as $re) {
            $this->assertMatchesRegularExpression($re, $t);
        }
        $this->assertStringContainsString('16/10/2026 - 15/11/2026', $t);
        $this->assertStringContainsString('Iron Body Neiva S.A.S.', $t);
        $this->assertStringContainsString('NIT 900.000.000-1', $t);
        $this->assertMatchesRegularExpression('/Fecha\s+16\/10\/2026 17:30/', $this->textoR($this->recibo(['local_datetime' => '16/10/2026 17:30'])));
    }

    public function test_productos_varias_lineas_descuento_iva_y_pago_mixto(): void
    {
        $t = $this->textoR($this->recibo($this->productosSnapshot(), ['cash_type' => 'products']));
        foreach ([
            '/3 x \$ 2\.500\s+\$ 7\.500/', '/2 x \$ 6\.000\s+\$ 11\.000/', '/Descuento\s+-\$ 1\.000/', '/Descuentos\s+-\$ 1\.000/',
            '/IVA 19% \(incluido\)\s+\$ 2\.954/', '/TOTAL\s+\$ 18\.500/', '/Efectivo\s+\$ 10\.000/', '/Tarjeta\s+\$ 8\.500/', '/Ref\.\s+AUT-9981/',
        ] as $re) {
            $this->assertMatchesRegularExpression($re, $t);
        }
        $this->assertStringNotContainsString('21.454', $t);
        $this->assertStringNotContainsString('Pagado hoy', $t);
        $this->assertStringNotContainsString('Saldo pendiente', $t);
    }

    public function test_abono_concepto_saldos_y_fecha_limite(): void
    {
        $t = $this->textoR($this->recibo([
            'title' => 'Recibo de abono',
            'totals' => ['subtotal' => 50000, 'discount' => 0, 'taxes' => [], 'total' => 50000, 'paid_now' => 50000, 'balance_after' => 70000],
            'payments' => [['method_label' => 'Transferencia', 'amount' => 50000, 'reference' => 'TRX-1']],
            'debt' => ['concept' => 'Piso de entrenador · Carlos → Olga · octubre', 'balance_before' => 120000, 'balance_after' => 70000, 'due_at' => '31/10/2026'],
        ]));
        $this->assertStringContainsString('Recibo de abono', $t);
        $this->assertStringContainsString('Piso de entrenador', $t);
        $this->assertMatchesRegularExpression('/Saldo anterior\s+\$ 120\.000/', $t);
        $this->assertMatchesRegularExpression('/Saldo actual\s+\$ 70\.000/', $t);
        $this->assertMatchesRegularExpression('/Fecha límite\s+31\/10\/2026/u', $t);
    }

    public function test_etiqueta_de_copia_del_servidor_y_original_sin_ninguna(): void
    {
        $r = $this->recibo();
        $this->assertStringContainsString('*** REIMPRESIÓN ***', $this->textoR($r, 'REIMPRESIÓN'));
        $this->assertStringContainsString('*** REINTENTO 2 ***', $this->textoR($r, 'REINTENTO 2'));
        $this->assertDoesNotMatchRegularExpression('/\*\*\*/', $this->textoR($r));
    }

    public function test_factura_pending_sin_numero_y_factura_con_numero(): void
    {
        $enTramite = $this->recibo([], ['live' => ['operation_status' => 'paid', 'invoice' => ['status' => 'pending', 'full_number' => null]]]);
        $this->assertStringContainsString('Documento no válido como', $this->textoR($enTramite));
        $this->assertDoesNotMatchRegularExpression('/^\s*Factura electrónica/mu', $this->textoR($enTramite));
        $this->assertStringNotContainsString('Ref. factura', $this->textoR($enTramite));

        $conFactura = $this->recibo([], ['live' => ['operation_status' => 'paid', 'invoice' => ['status' => 'validated', 'full_number' => 'SETP 990000123']]]);
        $this->assertStringContainsString('Ref. factura electrónica SETP', $this->textoR($conFactura));
        $this->assertDoesNotMatchRegularExpression('/^\s*Factura electrónica/mu', $this->textoR($conFactura));
        $this->assertMatchesRegularExpression('/no es una factura|no válido como factura/u', $this->textoR($conFactura));
    }

    public function test_venta_fiada(): void
    {
        $t = $this->textoR($this->recibo([
            'title' => 'Comprobante de entrega',
            'cash_label' => 'Caja de productos',
            'lines' => [['description' => 'Proteína Whey 2 lb', 'quantity' => 1, 'unit_price' => 189900, 'discount' => 0, 'total' => 189900]],
            'totals' => ['subtotal' => 189900, 'discount' => 0, 'taxes' => [], 'total' => 189900, 'paid_now' => 0, 'balance_after' => 189900],
            'payments' => [],
            'debt' => ['concept' => 'Venta a crédito V-0099', 'balance_before' => 0, 'balance_after' => 189900, 'due_at' => '31/10/2026'],
        ], ['cash_type' => 'products']));
        $this->assertStringContainsString('Comprobante de entrega', $t);
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 189\.900/', $t);
        $this->assertMatchesRegularExpression('/Pagado hoy\s+\$ 0/', $t);
        $this->assertMatchesRegularExpression('/Saldo anterior\s+\$ 0/', $t);
        $this->assertMatchesRegularExpression('/Saldo actual\s+\$ 189\.900/', $t);
        $this->assertMatchesRegularExpression('/Fecha límite\s+31\/10\/2026/u', $t);
        $this->assertDoesNotMatchRegularExpression('/Efectivo|Tarjeta|Transferencia/', $t);
    }

    public function test_cobrar_todo_una_linea_por_deuda(): void
    {
        $t = $this->textoR($this->recibo([
            'title' => 'Recibo de cobro',
            'lines' => [
                ['description' => 'Plan Mensual · saldo', 'quantity' => 1, 'unit_price' => 40000, 'discount' => 0, 'total' => 40000],
                ['description' => 'Piso de entrenador · octubre', 'quantity' => 1, 'unit_price' => 30000, 'discount' => 0, 'total' => 30000],
            ],
            'totals' => ['subtotal' => 70000, 'discount' => 0, 'taxes' => [], 'total' => 70000, 'paid_now' => 70000, 'balance_after' => 15000],
            'payments' => [['method_label' => 'Efectivo', 'amount' => 70000, 'reference' => null]],
            'debt' => null,
        ]));
        $this->assertStringContainsString('Plan Mensual · saldo', $t);
        $this->assertStringContainsString('Piso de entrenador · octubre', $t);
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 70\.000/', $t);
        $this->assertMatchesRegularExpression('/Saldo pendiente\s+\$ 15\.000/', $t);
        $this->assertMatchesRegularExpression('/Efectivo\s+\$ 70\.000/', $t);
        $this->assertStringNotContainsString('Saldo anterior', $t);
    }

    public function test_una_operacion_anulada_lo_dice_en_el_papel(): void
    {
        $this->assertStringContainsString('OPERACIÓN ANULADA', $this->textoR($this->recibo([], ['live' => ['operation_status' => 'cancelled', 'invoice' => null]])));
        $this->assertStringNotContainsString('ANULADA', $this->textoR($this->recibo()));
    }

    public function test_importes_como_texto_y_snapshot_roto(): void
    {
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 180\.000/', $this->textoR($this->recibo([
            'totals' => ['subtotal' => '180000.00', 'total' => '180000.00', 'discount' => null, 'taxes' => [], 'paid_now' => null, 'balance_after' => null],
        ])));

        $roto = ['uuid' => 'x', 'number' => 'RC-9', 'cash_type' => 'products', 'snapshot' => ['business' => null, 'lines' => 'nada', 'payments' => null]];
        $t = $this->layout->thermal($roto);
        $this->assertSame('Iron Body', $t['business']['name']);
        $this->assertSame([], $t['items']);
        $this->assertSame([], $t['payments']);
        $this->assertSame('—', $t['dateTime']);
        $this->assertSame('Caja de productos', $t['cashbox']);
        $this->assertContrato($this->layout->lines($t), 32, 'snapshot roto');
    }

    public function test_nombre_larguisimo_con_marcado_no_pasa_de_32(): void
    {
        $lineas = $this->layout->lines($this->layout->thermal($this->recibo([
            'customer' => 'María José Peñaloza <img src=x onerror=alert(1)> Ñañez de los Ríos',
            'lines' => [['description' => 'Proteína Whey Isolate sabor chocolate con avellanas 2 libras', 'quantity' => 1, 'unit_price' => 189900, 'discount' => 0, 'total' => 189900]],
        ]), 'REIMPRESIÓN'));
        $this->assertContrato($lineas, 32);
        // El texto llega tal cual: en ESC/POS no hay HTML que escapar.
        $this->assertStringContainsString('<img', implode(' ', array_column($lineas, 'text')));
    }

    // ── Invariantes de contrato en casos extremos ───────────────────────────

    public function test_invariantes_con_veinte_productos_y_nombres_larguisimos_a_32_y_42(): void
    {
        $veinte = [];
        for ($i = 1; $i <= 20; $i++) {
            $veinte[] = [
                'description' => "Producto {$i} Supercalifragilísticoespialidosamenteinterminable con nombre larguísimo de catálogo",
                'quantity' => ($i % 3) + 1, 'unit_price' => 1000 * $i, 'discount' => $i % 4 === 0 ? 500 : 0,
                'total' => 1000 * $i * (($i % 3) + 1) - ($i % 4 === 0 ? 500 : 0),
            ];
        }
        $recibo = $this->recibo([
            'business' => ['name' => 'IRON BODY WORKOUT CENTRO DE ENTRENAMIENTO FUNCIONAL DEL HUILA', 'legal_name' => str_repeat('Razón Social Larguísima ', 6), 'nit' => '900123456-7', 'address' => str_repeat('Calle 10 # 5-20 ', 6), 'phone' => '3001234567'],
            'customer' => "María\u{00A0}José   Peñaloza\tÑañez\nde los Ríos Ospina Gutiérrez Supercalifragilísticoespialidosamente",
            'cashier' => str_repeat('Cajera ', 12),
            'lines' => $veinte,
            'totals' => ['subtotal' => 500000, 'discount' => 2500, 'taxes' => [['label' => 'IVA 19% (incluido)', 'amount' => 79437.5], ['label' => 'Impuesto al consumo con un nombre larguísimo', 'amount' => 1234]], 'total' => 497500, 'paid_now' => 400000, 'balance_after' => 97500],
            'payments' => [['method_label' => 'Transferencia bancaria a la cuenta corriente número 1234567890', 'amount' => 400000, 'reference' => 'REFERENCIA-MUY-LARGA-0123456789-ABCDEFGHIJKLMNOPQRSTUVWXYZ']],
            'membership' => ['plan' => 'Plan Anual Premium Plus con acceso total a todas las sedes', 'period_from' => '16/10/2026', 'period_to' => '15/10/2027', 'upgraded_from' => 'Plan Mensual Básico de entrada'],
            'debt' => null,
        ], ['live' => ['operation_status' => 'refunded', 'invoice' => ['status' => 'validated', 'full_number' => 'SETP 990000123456789012345678901234567890']]]);

        foreach ([32, 42] as $cols) {
            $lineas = $this->layout->lines($this->layout->thermal($recibo, 'REINTENTO 12'), $cols);
            $this->assertContrato($lineas, $cols, "veinte productos a {$cols}");
            $texto = implode("\n", array_column($lineas, 'text'));
            // Una operación DEVUELTA lo dice así, no «anulada» (el dinero entró y salió).
            $this->assertStringContainsString('OPERACIÓN DEVUELTA', $texto);
            $this->assertStringNotContainsString('ANULADA', $texto);
            $this->assertStringContainsString('Ref. factura', $texto);
            $this->assertStringContainsString('*** REINTENTO 12 ***', $lineas[0]['text']);
            $this->assertSame(20, substr_count($texto, 'Producto '));
        }
    }

    /**
     * DIFERENCIA DELIBERADA con el TS: un control en el texto (ESC, por ejemplo)
     * sería un comando para la impresora. Se sustituye por un espacio, como el
     * TS ya hacía con saltos y tabuladores.
     */
    public function test_ningun_control_llega_al_texto(): void
    {
        $lineas = $this->layout->lines($this->layout->thermal($this->recibo([
            'customer' => "Ana\x1B@\x1Bd\x08Pérez\x7F\u{0085}\u{009B}fin",
            'lines' => [['description' => "Agua\x00 600\x1D!\x11ml", 'quantity' => 1, 'unit_price' => 3000, 'discount' => 0, 'total' => 3000]],
        ])));
        $this->assertContrato($lineas, 32, 'controles');
        $this->assertStringContainsString('Ana @ d Pérez fin', implode("\n", array_column($lineas, 'text')));
    }

    public function test_un_texto_que_no_es_utf8_valido_no_rompe_ni_pasa_tal_cual(): void
    {
        $lineas = $this->layout->lines($this->layout->thermal($this->recibo(['customer' => "Jos\xE9 P\xC3rez"])));
        $this->assertContrato($lineas, 32, 'utf8 inválido');
    }

    public function test_un_recibo_de_mas_de_400_lineas_no_sale_como_trabajo(): void
    {
        $lineas = [];
        for ($i = 1; $i <= 200; $i++) {
            $lineas[] = ['description' => "Producto {$i}", 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0, 'total' => 1000];
        }
        $texto = $this->layout->lines($this->layout->thermal($this->recibo(['lines' => $lineas])));
        $this->assertGreaterThan(400, count($texto));

        // No se recorta en silencio: se rechaza.
        try {
            $this->layout->assertPrintable($texto, 32);
            $this->fail('un trabajo de más de 400 líneas no puede pasar');
        } catch (ReceiptException $e) {
            $this->assertSame('job_too_long', $e->code_);
        }
    }

    public function test_solo_32_o_42_columnas(): void
    {
        $this->expectException(ReceiptException::class);
        $this->layout->assertPrintable([['text' => 'x', 'style' => 'normal']], 48);
    }

    // ── Páginas de prueba (§5.4) ────────────────────────────────────────────

    public function test_pagina_de_prueba_minima(): void
    {
        $job = $this->layout->testJob('minimal', 32, 'cp850', 'Recepción', 4);

        $this->assertSame([
            ['text' => 'IRON BODY', 'style' => 'title'],
            ['text' => '           PRUEBA POS', 'style' => 'normal'],
            ['text' => '               OK', 'style' => 'bold'],
        ], $job['lines']);
        $this->assertSame(['receipt_number' => null, 'copy_label' => null, 'kind' => 'test', 'columns' => 32, 'feed_lines' => 4, 'cut' => false],
            array_diff_key($job, ['lines' => true]));
    }

    public function test_pagina_de_prueba_completa_a_32_y_42(): void
    {
        // 22:30 UTC son las 17:30 en Bogotá.
        $ahora = Carbon::parse('2026-10-16 22:30:00', 'UTC');

        foreach ([32 => 'cp850', 42 => 'ascii'] as $cols => $codepage) {
            $job = $this->layout->testJob('full', $cols, $codepage, 'Recepción principal', 6, $ahora);
            $t = array_column($job['lines'], 'text');

            $this->assertContrato($job['lines'], $cols, "prueba {$cols}");
            $this->assertSame(['text' => 'PRUEBA DE IMPRESORA', 'style' => 'title'], $job['lines'][0]);
            $this->assertContains(mb_substr(str_repeat('1234567890', 5), 0, $cols), $t);
            $this->assertContains('|'.str_repeat('-', $cols - 2).'|', $t);
            $this->assertTrue(collect($t)->contains(fn ($x) => mb_strlen($x) === $cols && str_starts_with($x, 'Izquierda') && str_ends_with($x, 'Derecha')));
            $this->assertContains('á é í ó ú ñ Ñ ¿? ¡!', $t);
            $this->assertContains('Á É Í Ó Ú ü Ü', $t);
            $texto = implode("\n", $t);
            $this->assertStringContainsString('$ 1.234.567', $texto);
            $this->assertMatchesRegularExpression('/Fecha\s+16\/10\/2026 17:30/', $texto);
            $this->assertMatchesRegularExpression('/Estación\s+Recepción principal/u', $texto);
            $this->assertStringContainsString("{$cols} col · {$codepage}", $texto);
            $this->assertStringContainsString('La regla debe caber', $texto);
            $total = collect($job['lines'])->first(fn ($l) => str_starts_with($l['text'], 'TOTAL'));
            $this->assertSame('bold', $total['style']);
            $this->assertSame(6, $job['feed_lines']);
            $this->assertFalse($job['cut']);
        }
    }

    // ── NFC sin ext-intl: lo que corre en PRODUCCIÓN ────────────────────────

    /** Cualquier marca combinante (las mismas familias que quita el respaldo). */
    private const MARCA = '/[\x{0300}-\x{036F}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20FF}\x{FE20}-\x{FE2F}]/u';

    /**
     * Producción corre PHP 8.3 SIN ext-intl (comprobado el 17-10-2026): allí
     * nunca hay Normalizer y TODO pasa por nfcSinIntl(). Estas pruebas no
     * dependen de intl: construyen las cadenas descompuestas a mano.
     */
    public function test_el_respaldo_compone_el_espanol_y_quita_las_marcas_sueltas(): void
    {
        $this->assertSame('Café', ReceiptTextLayout::nfcSinIntl("Cafe\u{0301}"));
        $this->assertSame('Peña', ReceiptTextLayout::nfcSinIntl("Pen\u{0303}a"));
        $this->assertSame('PEÑA ÚLTIMO pingüino', ReceiptTextLayout::nfcSinIntl("PEN\u{0303}A U\u{0301}LTIMO pingu\u{0308}ino"));
        $this->assertSame(4, mb_strlen(ReceiptTextLayout::nfcSinIntl("Cafe\u{0301}")));

        // Lo que no es español no se compone: se queda la letra, sin la marca.
        $this->assertSame('c', ReceiptTextLayout::nfcSinIntl("c\u{0327}"));
        $this->assertSame('Goncalves', ReceiptTextLayout::nfcSinIntl("Gonc\u{0327}alves"));
        // Marcas sueltas de todas las familias, también al principio.
        $this->assertSame('abcde', ReceiptTextLayout::nfcSinIntl("\u{0301}a\u{1AB0}b\u{1DC0}c\u{20D7}d\u{FE20}e\u{036F}"));
        // Lo que ya llega compuesto no se toca, y aplicarlo dos veces no cambia nada.
        $this->assertSame('ç á Ñ', ReceiptTextLayout::nfcSinIntl('ç á Ñ'));
        $una = ReceiptTextLayout::nfcSinIntl("Mari\u{0301}a c\u{0327} x\u{0301}\u{0302}");
        $this->assertSame($una, ReceiptTextLayout::nfcSinIntl($una));
        $this->assertSame('María c x', $una);
    }

    public function test_con_el_respaldo_forzado_un_nombre_largo_descompuesto_respeta_columns(): void
    {
        $sinIntl = new ReceiptTextLayout(useIntl: false);
        $nombre = "Mari\u{0301}a Jose\u{0301} Pen\u{0303}aloza N\u{0303}an\u{0303}ez de los Ri\u{0301}os Ospina "
            ."Gutie\u{0301}rrez Supercalifragili\u{0301}sticoespialidosamente Mun\u{0303}oz c\u{0327}\u{0301}";

        $this->assertSame('María José Peñaloza Ñañez de los Ríos Ospina Gutiérrez Supercalifragilísticoespialidosamente Muñoz c',
            $sinIntl->clean($nombre));

        foreach ([32, 42] as $cols) {
            foreach ([$sinIntl->wrap($nombre, $cols), $sinIntl->pair('Cliente', $nombre, $cols), $sinIntl->center($nombre, $cols)] as $lineas) {
                $this->assertNotEmpty($lineas);
                foreach ($lineas as $l) {
                    $this->assertLessThanOrEqual($cols, mb_strlen($l), $l);
                    $this->assertSame(0, preg_match(self::MARCA, $l), 'marca suelta en '.json_encode($l));
                }
            }
        }
    }

    public function test_un_recibo_completo_maquetado_con_el_respaldo_no_lleva_ninguna_marca(): void
    {
        $sinIntl = new ReceiptTextLayout(useIntl: false);
        $recibo = $this->recibo([
            'business' => ['name' => 'IRON BODY WORKOUT', 'legal_name' => "Iron Body Neiva S.A.S. Compan\u{0303}i\u{0301}a", 'nit' => '900.000.000-1', 'address' => "Calle 1 # 2-3, Neiva, Hui\u{0301}la", 'phone' => '3000000000'],
            'title' => "Recibo de pago \u{00B7} Mejora de plan",
            'cashier' => "Ange\u{0301}lica Recepcio\u{0301}n",
            'customer' => "Franc\u{0327}ois Mu\u{0308}ller Pen\u{0303}a Supercalifragili\u{0301}stico\u{20D7}",
            'lines' => [
                ['description' => "Proteí\u{0301}na Whey sabor cafe\u{0301} con avellanas edicio\u{0301}n limitada", 'quantity' => 1, 'unit_price' => 189900, 'discount' => 900, 'total' => 189000],
                ['description' => "Bebida energe\u{0301}tica n\u{0303}ame\u{1DC0}", 'quantity' => 2, 'unit_price' => 4500, 'discount' => 0, 'total' => 9000],
            ],
            'totals' => ['subtotal' => 198900, 'discount' => 900, 'taxes' => [['label' => "IVA 19% (incluido) \u{0301}", 'amount' => 31739]], 'total' => 198000, 'paid_now' => 100000, 'balance_after' => 98000],
            'payments' => [['method_label' => "Transferencia bancaria a la cuenta corriente nu\u{0301}mero 1234567890", 'amount' => 100000, 'reference' => "TRX-n\u{0303}-99"]],
            'membership' => ['plan' => "Ma\u{0301}s Premium An\u{0303}o", 'period_from' => '16/10/2026', 'period_to' => '15/11/2026', 'upgraded_from' => "Ba\u{0301}sico"],
            'legend' => "Este documento no es una factura electro\u{0301}nica de venta",
        ], ['live' => ['operation_status' => 'cancelled', 'invoice' => ['status' => 'validated', 'full_number' => "SETP 99\u{0301}0000123"]]]);

        foreach ([32, 42] as $cols) {
            $lineas = $sinIntl->lines($sinIntl->thermal($recibo, "REIMPRESIO\u{0301}N"), $cols);
            $texto = implode("\n", array_column($lineas, 'text'));

            $this->assertSame(0, preg_match(self::MARCA, $texto), "a {$cols} columnas quedó una marca suelta");
            $sinIntl->assertPrintable($lineas, $cols);
            $this->assertContrato($lineas, $cols, "respaldo a {$cols}");
            foreach (['*** REIMPRESIÓN ***', 'Angélica Recepción', 'Peña', 'Proteína', 'ñame', 'Más Premium Año', 'Básico', 'electrónica', 'OPERACIÓN ANULADA'] as $esperado) {
                $this->assertStringContainsString($esperado, $texto, "a {$cols}");
            }
            // La cedilla y la diéresis no españolas pierden la marca, no la letra.
            $this->assertStringContainsString('Francois', $texto);
        }
    }

    /** Las muestras con el respaldo forzado: las 20 líneas con tildes, sin una sola marca suelta. */
    public function test_las_muestras_maquetadas_con_el_respaldo_no_llevan_marcas(): void
    {
        $this->empresa();
        $sinIntl = new ReceiptTextLayout(useIntl: false);
        $ahora = Carbon::parse('2026-10-17 01:30:00', 'UTC');

        foreach (['sample_1', 'sample_5', 'sample_20', 'sample_membership'] as $variante) {
            foreach ([32, 42] as $cols) {
                $job = $sinIntl->testJob($variante, $cols, 'cp850', 'Recepción', 4, $ahora, "Ange\u{0301}lica");
                $texto = implode("\n", array_column($job['lines'], 'text'));

                $this->assertSame(0, preg_match(self::MARCA, $texto), "{$variante} a {$cols}");
                $sinIntl->assertPrintable($job['lines'], $cols);
                $this->assertContrato($job['lines'], $cols, "{$variante} a {$cols} sin intl");
                $this->assertStringContainsString('Angélica', $texto);
                // Mismo papel que con intl: el contenido ya llega compuesto.
                $this->assertSame($this->layout->testJob($variante, $cols, 'cp850', 'Recepción', 4, $ahora, 'Angélica')['lines'], $job['lines']);
            }
        }

        $veinte = implode("\n", array_column($sinIntl->testJob('sample_20', 32, 'cp850', null, 4, $ahora)['lines'], 'text'));
        foreach (['Proteína', 'maracuyá', 'cápsulas', 'Muñequeras', 'combinación'] as $palabra) {
            $this->assertStringContainsString($palabra, $veinte);
        }
    }

    /**
     * «…» ocupa una columna aquí pero el servicio lo imprime como «...» (§2.2).
     * Medido sin expandir, una línea JUSTA de 32 se pasaría a 34 en el papel.
     */
    public function test_los_puntos_suspensivos_se_expanden_antes_de_medir(): void
    {
        $this->assertSame('Ana...', $this->layout->clean("Ana\u{2026}"));
        $this->assertSame('Ana...', (new ReceiptTextLayout(useIntl: false))->clean("Ana\u{2026}"));

        // 7 + 1 + 24 = 32: contando «…» como uno, la línea era justa.
        $nombre = "María José Peñaloza Rui\u{2026}";
        $this->assertSame(24, mb_strlen($nombre));
        $lineas = $this->layout->pair('Cliente', $nombre);
        $this->assertGreaterThan(1, count($lineas), 'con «...» ya no cabe en una línea');
        $this->assertStringEndsWith('Rui...', $lineas[count($lineas) - 1]);
        foreach ($lineas as $l) {
            $this->assertLessThanOrEqual(32, mb_strlen($l));
            $this->assertStringNotContainsString("\u{2026}", $l);
        }

        // Una descripción de exactamente 32 con «…» al final se parte.
        $descripcion = str_repeat('x', 31)."\u{2026}";
        $this->assertSame([str_repeat('x', 31).'.', '..'], $this->layout->wrap($descripcion, 32));

        // En un recibo completo, a 32 y 42, ninguna línea lleva «…» ni se pasa.
        foreach ([32, 42] as $cols) {
            $lineas = $this->layout->lines($this->layout->thermal($this->recibo([
                'customer' => $nombre,
                'lines' => [['description' => "Proteína Whey sabor chocolate\u{2026} edición\u{2026}", 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0, 'total' => 1000]],
            ])), $cols);
            $this->assertContrato($lineas, $cols, "puntos suspensivos a {$cols}");
            $this->assertStringNotContainsString("\u{2026}", implode('', array_column($lineas, 'text')));
            $this->assertStringContainsString('Rui...', implode("\n", array_column($lineas, 'text')));
        }

        // Y assertPrintable no deja pasar una línea con «…».
        $this->expectException(ReceiptException::class);
        $this->layout->assertPrintable([['text' => "Ana\u{2026}", 'style' => 'normal']], 32);
    }

    public function test_el_comportamiento_por_defecto_no_cambia(): void
    {
        // Sin costura: Normalizer si está instalado (lo compone todo); si no, el respaldo.
        $porDefecto = new ReceiptTextLayout;
        $this->assertSame(class_exists(Normalizer::class) ? 'ç' : 'c', $porDefecto->clean("c\u{0327}"));
        $this->assertSame('Café Peña', $porDefecto->clean("Cafe\u{0301} Pen\u{0303}a"));
        // Y con cualquiera de los dos, ninguna marca sobrevive aunque no se pueda componer.
        $this->assertSame('x', $porDefecto->clean("x\u{0301}\u{0302}"));
        $this->assertSame('x', (new ReceiptTextLayout(useIntl: false))->clean("x\u{0301}\u{0302}"));

        // El maquetador del contenedor es el de por defecto.
        $this->assertSame($porDefecto->clean("c\u{0327}"), app(ReceiptTextLayout::class)->clean("c\u{0327}"));
    }

    public function test_assert_printable_rechaza_una_marca_suelta_en_cualquier_modo(): void
    {
        foreach ([new ReceiptTextLayout, new ReceiptTextLayout(useIntl: false)] as $layout) {
            try {
                $layout->assertPrintable([['text' => "Cafe\u{0301}", 'style' => 'normal']], 32);
                $this->fail('una línea con marca suelta no puede pasar');
            } catch (ReceiptException $e) {
                $this->assertSame('layout_invalid', $e->code_);
            }
        }
    }

    // ── Recibos de muestra (§5.4 sample_*) ─────────────────────────────────

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function muestras(): iterable
    {
        foreach (['sample_1', 'sample_5', 'sample_20', 'sample_membership'] as $v) {
            foreach ([32, 42] as $cols) {
                yield "{$v} a {$cols}" => [$v, $cols];
            }
        }
    }

    /**
     * Cada muestra es un recibo maquetado por el MISMO código que los reales,
     * cumple el contrato a 32 y a 42 columnas, y lleva la marca de prueba que
     * ningún recibo real puede llevar: arriba, abajo, en el número y en el cliente.
     */
    #[DataProvider('muestras')]
    public function test_cada_muestra_cumple_el_contrato_y_lleva_la_marca_de_prueba(string $variante, int $cols): void
    {
        $this->empresa();
        $ahora = Carbon::parse('2026-10-17 01:30:00', 'UTC');

        $job = $this->layout->testJob($variante, $cols, 'cp850', 'Recepción', 4, $ahora, 'Marta Supervisora');

        $this->assertContrato($job['lines'], $cols, $variante);
        $this->assertSame('PRUEBA', $job['receipt_number']);
        $this->assertSame('PRUEBA · SIN VALOR', $job['copy_label']);
        $this->assertSame('test', $job['kind']);
        $this->assertSame($cols, $job['columns']);
        $this->assertFalse($job['cut']);

        $marca = ['text' => str_repeat(' ', intdiv($cols - 26, 2)).'*** PRUEBA · SIN VALOR ***', 'style' => 'bold'];
        $this->assertSame($marca, $job['lines'][0], 'la marca de prueba va arriba');
        $this->assertSame($marca, $job['lines'][count($job['lines']) - 1], 'y abajo');

        $texto = implode("\n", array_column($job['lines'], 'text'));
        $this->assertMatchesRegularExpression('/^Recibo\s+PRUEBA$/m', $texto);
        $this->assertMatchesRegularExpression('/^Cliente\s+CLIENTE DE PRUEBA$/m', $texto);
        $this->assertMatchesRegularExpression('/^Cajero\s+Marta Supervisora$/m', $texto);
        // El encabezado es el real y la hora, la del gimnasio (01:30 UTC = 20:30 en Neiva).
        $this->assertSame(['text' => 'IRON BODY WORKOUT', 'style' => 'title'], $job['lines'][1]);
        $this->assertStringContainsString('NIT 900123456-7', $texto);
        $this->assertMatchesRegularExpression('/^Fecha\s+16\/10\/2026 20:30$/m', $texto);
        $this->assertStringContainsString('Este documento no es una factura', $texto);

        // Es la misma maquetación que un recibo real con ese snapshot.
        $this->assertSame(
            $this->layout->lines($this->layout->thermal($this->layout->sampleReceipt($variante, $ahora, 'Marta Supervisora'), 'PRUEBA · SIN VALOR'), $cols),
            $job['lines'],
        );
    }

    public function test_el_contenido_de_cada_muestra(): void
    {
        $this->empresa();
        $ahora = Carbon::parse('2026-10-17 01:30:00', 'UTC');
        $texto = fn (string $v) => implode("\n", array_column($this->layout->testJob($v, 32, 'cp850', 'Recepción', 4, $ahora)['lines'], 'text'));
        $snapshot = fn (string $v) => $this->layout->sampleReceipt($v, $ahora)['snapshot'];

        // 1 producto, en efectivo.
        $this->assertCount(1, $snapshot('sample_1')['lines']);
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 3\.000/', $texto('sample_1'));
        $this->assertMatchesRegularExpression('/Efectivo\s+\$ 3\.000/', $texto('sample_1'));

        // 5 productos, un descuento y dos medios que suman el total.
        $cinco = $snapshot('sample_5');
        $this->assertCount(5, $cinco['lines']);
        $this->assertCount(1, array_filter($cinco['lines'], fn ($l) => $l['discount'] > 0));
        $this->assertCount(2, $cinco['payments']);
        $this->assertEquals($cinco['totals']['total'], array_sum(array_column($cinco['payments'], 'amount')));
        $this->assertEquals($cinco['totals']['subtotal'], $cinco['totals']['total'] + $cinco['totals']['discount']);
        $t5 = $texto('sample_5');
        $this->assertMatchesRegularExpression('/Descuento\s+-\$ 500/', $t5);
        $this->assertMatchesRegularExpression('/Descuentos\s+-\$ 500/', $t5);
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 49\.000/', $t5);
        $this->assertMatchesRegularExpression('/Efectivo\s+\$ 20\.000/', $t5);
        $this->assertMatchesRegularExpression('/Nequi\s+\$ 29\.000/', $t5);

        // 20 productos con nombres largos y tildes, partidos sin pasarse.
        $veinte = $snapshot('sample_20');
        $this->assertCount(20, $veinte['lines']);
        $this->assertGreaterThanOrEqual(15, count(array_filter($veinte['lines'], fn ($l) => preg_match('/[áéíóúñ]/u', $l['description']))), 'nombres con tildes y eñes');
        $this->assertTrue(collect($veinte['lines'])->every(fn ($l) => mb_strlen($l['description']) > 32), 'todos más largos que una línea');
        $this->assertEquals(array_sum(array_column($veinte['lines'], 'total')), $veinte['totals']['total']);
        $t20 = $texto('sample_20');
        $this->assertStringContainsString('Proteína Whey Isolate sabor', $t20);
        $this->assertStringContainsString('maracuyá', $t20);
        $this->assertMatchesRegularExpression('/IVA 19% \(incluido\)\s+\$ [\d.]+/', $t20);

        // Membresía: plan, vigencia dd/mm/aaaa y saldo pendiente.
        $tm = $texto('sample_membership');
        $this->assertMatchesRegularExpression('/Plan\s+Mensual Premium/', $tm);
        $this->assertMatchesRegularExpression('/Vigencia 16\/10\/2026 - 15\/11\/2026/', $tm);
        $this->assertMatchesRegularExpression('/Pagado hoy\s+\$ 60\.000/', $tm);
        $this->assertMatchesRegularExpression('/Saldo pendiente\s+\$ 60\.000/', $tm);
        $this->assertStringContainsString('Caja de pagos', $tm);
    }

    public function test_una_variante_desconocida_es_la_minima_y_una_muestra_inventada_no_existe(): void
    {
        $this->assertSame($this->layout->minimalTestPage(32), $this->layout->testJob('otra', 32, 'cp850', null, 4)['lines']);

        $this->expectException(ReceiptException::class);
        $this->layout->sampleReceipt('sample_2', Carbon::now());
    }

    private function empresa(): void
    {
        config([
            'billing.company.name' => 'IRON BODY NEIVA S.A.S.',
            'billing.company.nit' => '900123456',
            'billing.company.dv' => '7',
            'billing.company.address' => 'Calle 10 # 5-20, Neiva',
            'billing.company.phone' => '3001234567',
        ]);
    }

    private function indice(array $lineas, string $prefijo): int
    {
        foreach ($lineas as $i => $l) {
            if (str_starts_with($l, $prefijo)) {
                return $i;
            }
        }
        $this->fail("No hay línea que empiece por «{$prefijo}»");
    }
}
