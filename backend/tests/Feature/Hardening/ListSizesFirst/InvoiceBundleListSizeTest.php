<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Services\Billing\ManualInvoiceBundleService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
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
            [$r, $atributos] = $this->conAtributosValidados(fn () => $this->postJson($ruta, ['member_id' => 1, 'items' => array_fill(0, 10_000, 'payment:1')], $h));

            $r->assertStatus(422);
            $this->assertSame(['items'], array_keys($r->json('errors')), $ruta);
            $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
        }
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $items = array_fill(0, ManualInvoiceBundleService::MAX_ITEMS - 1, 'payment:1');
        $items[] = 'pago:1';
        $r = $this->postJson('/api/admin/electronic-invoices/bundles/preview', ['member_id' => 999_999, 'items' => $items], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['member_id', 'items.'.(ManualInvoiceBundleService::MAX_ITEMS - 1)], array_keys($r->json('errors')));
    }

    /**
     * La respuesta y el mayor número de atributos que llegó a validar un
     * validador de la petición. Con el comodín expandido sobre la lista, uno
     * (o más) por elemento; validando primero el tamaño, uno por lista. No
     * depende de la carga de la máquina, a diferencia del tiempo.
     *
     * @return array{0: TestResponse, 1: int}
     */
    private function conAtributosValidados(Closure $peticion): array
    {
        $maximo = 0;
        Validator::resolver(static function ($traductor, array $datos, array $reglas, array $mensajes, array $atributos) use (&$maximo): LaravelValidator {
            $validador = new LaravelValidator($traductor, $datos, $reglas, $mensajes, $atributos);
            $maximo = max($maximo, count($validador->getRules()));

            return $validador;
        });

        return [$peticion(), $maximo];
    }
}
