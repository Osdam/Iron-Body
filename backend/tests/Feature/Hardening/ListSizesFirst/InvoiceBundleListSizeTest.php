<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Services\Billing\ManualInvoiceBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): la factura agrupada
 * (`POST /api/admin/electronic-invoices/bundles` y `.../bundles/preview`, las
 * dos con la misma validación) valida primero el tamaño de `items` (tope
 * `ManualInvoiceBundleService::MAX_ITEMS`, el de siempre) y después cada
 * elemento. Antes, 10 000 elementos tardaban 2,3 s en llegar al mismo 422.
 */
class InvoiceBundleListSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_lista_por_encima_del_tope_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $this->postJson('/api/admin/electronic-invoices/bundles/preview', [], $h)->assertStatus(422);   // en frío, sin medir

        foreach (['/api/admin/electronic-invoices/bundles/preview', '/api/admin/electronic-invoices/bundles'] as $ruta) {
            $inicio = hrtime(true);
            $r = $this->postJson($ruta, ['member_id' => 1, 'items' => array_fill(0, 10_000, 'payment:1')], $h);
            $ms = (hrtime(true) - $inicio) / 1e6;

            $r->assertStatus(422);
            $this->assertSame(['items'], array_keys($r->json('errors')), $ruta);
            $this->assertLessThan(1000, $ms, sprintf('%s: el 422 tardó %.1f ms (antes, 2,3 s).', $ruta, $ms));
        }
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $items = array_fill(0, ManualInvoiceBundleService::MAX_ITEMS - 1, 'payment:1');
        $items[] = 'pago:1';
        $r = $this->postJson('/api/admin/electronic-invoices/bundles/preview', ['member_id' => 999_999, 'items' => $items], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['member_id', 'items.'.(ManualInvoiceBundleService::MAX_ITEMS - 1)], array_keys($r->json('errors')));
    }
}
