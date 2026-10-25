<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL AVISO DE QUE QUEDAN POCAS.
 *
 * El candado de «sin unidades» llega tarde: sale con el cliente delante
 * pidiendo algo que ya no hay. Y el aviso previo existía pero casi nunca se
 * disparaba, porque dependía solo de `min_stock` y ese campo nace en 0 si
 * nadie lo llena: con 0, la condición `min_stock > 0` no se cumplía nunca y el
 * producto pasaba de «ok» a AGOTADO sin paso intermedio.
 *
 * Ahora hay un suelo de tres unidades para todos, y quien tenga un mínimo
 * mayor conserva el suyo: de una proteína que se vende a diario, tres ya es
 * tarde. Manda el más alto de los dos.
 */
class LowStockWarningTest extends TestCase
{
    use RefreshDatabase;

    private function producto(int $stock, int $minimo = 0): Product
    {
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Proteína '.uniqid(), 'category' => 'Suplementos',
            'sale_price' => 50000, 'cost_price' => 25000,
            'stock' => $stock, 'min_stock' => $minimo,
            'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    public function test_con_tres_o_menos_avisa_aunque_nadie_pusiera_minimo(): void
    {
        // El caso de casi todo el catálogo: sin mínimo, antes no avisaba nunca.
        $this->assertSame('low', $this->producto(3)->stock_status);
        $this->assertSame('low', $this->producto(1)->stock_status);
    }

    public function test_con_cuatro_todavia_no_avisa(): void
    {
        $this->assertSame('ok', $this->producto(4)->stock_status);
        $this->assertSame('ok', $this->producto(214)->stock_status);
    }

    public function test_un_minimo_mayor_manda_sobre_el_suelo(): void
    {
        // Lo que se vende a diario necesita avisar antes de llegar a tres.
        $this->assertSame('low', $this->producto(8, minimo: 10)->stock_status);
        $this->assertSame('ok', $this->producto(11, minimo: 10)->stock_status);
    }

    public function test_un_minimo_menor_no_baja_el_suelo(): void
    {
        // Poner el mínimo en 1 no debería apagar el aviso: el suelo manda.
        $this->assertSame('low', $this->producto(2, minimo: 1)->stock_status);
    }

    public function test_agotado_sigue_siendo_agotado(): void
    {
        $this->assertSame('out', $this->producto(0)->stock_status);
    }

    public function test_vendido_de_mas_tambien_cuenta_como_agotado(): void
    {
        // Los negativos existen a propósito: un ajuste manual puede dejarlo por
        // debajo de cero, y eso tiene que verse en vez de recortarse en
        // silencio. La puerta del mostrador lo bloquea igual.
        $this->assertSame('out', $this->producto(-3)->stock_status);
    }

    public function test_la_app_lo_traduce_a_su_vocabulario(): void
    {
        $this->assertSame('low', $this->producto(2)->availability);
        $this->assertSame('out_of_stock', $this->producto(0)->availability);
        $this->assertSame('in_stock', $this->producto(50)->availability);
    }
}
