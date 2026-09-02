<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Models\Admin;
use App\Models\CatalogEvent;
use App\Models\Product;
use App\Services\CatalogEvents;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Canal GLOBAL de cambios de catálogo.
 *
 * El canal existente es por socio, y emitir por él un cambio de catálogo
 * significaría escribir 3.739 filas en cada venta de Caja. Éste guarda el hecho
 * una vez.
 *
 * Lo que estas pruebas protegen, por orden de importancia:
 *   1. que NO se avise de algo que después se deshace;
 *   2. que el aviso sea una invalidación y no transporte estado;
 *   3. que todo camino que cambia el catálogo avise, no sólo el controlador.
 */
class CatalogEventsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test', 'email' => 'cat-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
    }

    private function product(array $o = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Creatina', 'category' => 'Cafetería', 'sale_price' => 89000,
            'cost_price' => 61000, 'stock' => 10, 'min_stock' => 2,
            'active' => true, 'visible_in_app' => false,
        ], $o));
    }

    // ── La garantía que más importa ───────────────────────────────────────

    public function test_no_se_avisa_de_lo_que_se_deshace(): void
    {
        // Avisar de un stock que luego revierte es peor que no avisar: el
        // cliente pediría el estado canónico y vería lo de antes, sin entender
        // por qué le avisamos.
        $product = $this->product();

        try {
            DB::transaction(function () use ($product) {
                app(InventoryService::class)->registerExit(
                    product: $product, quantity: 2,
                    origin: InventoryMovementOrigin::DAMAGE, reason: 'rota',
                );
                throw new \RuntimeException('algo falló después');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(0, CatalogEvent::count(), 'la transacción se deshizo: no hay nada que avisar');
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_una_operacion_que_si_commitea_avisa_una_vez(): void
    {
        $product = $this->product();

        DB::transaction(function () use ($product) {
            app(InventoryService::class)->registerExit(
                product: $product, quantity: 2,
                origin: InventoryMovementOrigin::DAMAGE, reason: 'rota',
            );
        });

        $this->assertSame(1, CatalogEvent::count());
        $e = CatalogEvent::first();
        $this->assertSame(CatalogEvents::PRODUCT_CHANGED, $e->type);
        $this->assertSame($product->id, $e->product_id);
        $this->assertSame(['stock'], $e->changed);
    }

    // ── Es una invalidación, no un transporte de estado ────────────────────

    public function test_el_evento_no_lleva_precio_ni_stock(): void
    {
        // Si llevara el valor, el SSE sería una segunda fuente de verdad que
        // puede quedar desincronizada. Sólo dice QUÉ cambió.
        $product = $this->product();
        app(InventoryService::class)->registerEntry(
            product: $product, quantity: 5,
            origin: InventoryMovementOrigin::PURCHASE, reason: 'compra',
        );

        $payload = CatalogEvent::first()->toArray();
        $json = json_encode($payload);

        foreach (['price', 'sale_price', 'cost_price', '89000', 'supplier'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, (string) $json,
                "el evento no puede transportar $prohibido");
        }
        $this->assertSame(['stock'], $payload['changed']);
    }

    // ── Todos los caminos avisan ──────────────────────────────────────────

    public function test_el_stock_avisa_venga_de_donde_venga(): void
    {
        // Entrada, salida y ajuste pasan por el mismo sitio; si sólo avisara el
        // controlador, una venta de Caja no llegaría nunca a la app.
        $product = $this->product();
        $svc = app(InventoryService::class);

        $svc->registerEntry(product: $product, quantity: 3,
            origin: InventoryMovementOrigin::PURCHASE, reason: 'compra');
        $svc->registerExit(product: $product->fresh(), quantity: 1,
            origin: InventoryMovementOrigin::LOSS, reason: 'faltante');

        $this->assertSame(2, CatalogEvent::where('product_id', $product->id)->count());
    }

    public function test_publicar_o_retirar_avisa(): void
    {
        $product = $this->product();

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $this->actingAsAdmin($this->admin()))->assertOk();

        $e = CatalogEvent::latest('id')->first();
        $this->assertSame(['visibility'], $e->changed);
        $this->assertSame($product->id, $e->product_id);
    }

    public function test_editar_avisa_solo_de_lo_que_cambio(): void
    {
        $product = $this->product();

        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Creatina Monohidrato',
            'sale_price' => 95000,
            'category' => $product->category,
        ], $this->actingAsAdmin($this->admin()))->assertOk();

        $changed = CatalogEvent::latest('id')->first()->changed;
        sort($changed);
        $this->assertSame(['name', 'sale_price'], $changed);
    }

    public function test_una_edicion_que_no_cambia_nada_no_avisa(): void
    {
        // Guardar sin tocar nada no puede hacer que 3.739 teléfonos recarguen.
        $product = $this->product();

        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => $product->name,
            'sale_price' => $product->sale_price,
            'category' => $product->category,
        ], $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertSame(0, CatalogEvent::count());
    }

    // ── Invalidación masiva ───────────────────────────────────────────────

    public function test_la_invalidacion_masiva_no_apunta_a_ningun_producto(): void
    {
        CatalogEvents::invalidate('importacion');

        $e = CatalogEvent::firstOrFail();
        $this->assertSame(CatalogEvents::INVALIDATE, $e->type);
        $this->assertNull($e->product_id);
        $this->assertSame(['reason' => 'importacion'], $e->changed);
    }

    // ── El stream ─────────────────────────────────────────────────────────

    public function test_el_evento_sobrevive_al_producto(): void
    {
        // Si el aviso tuviera clave foránea en cascada, archivar un producto
        // borraría el aviso de que se archivó. Justo el que hay que entregar.
        $product = $this->product();
        CatalogEvents::productChanged($product->id, ['archived']);
        $this->assertSame(1, CatalogEvent::count());

        $product->forceDelete();

        $this->assertSame(1, CatalogEvent::count(), 'el aviso tiene que seguir ahí');
        $this->assertSame($product->id, CatalogEvent::first()->product_id);
    }

    public function test_los_eventos_llevan_version_monotonica(): void
    {
        // Permite al cliente descartar duplicados tras una reconexión.
        CatalogEvents::invalidate('a');
        usleep(2000);
        CatalogEvents::invalidate('b');

        $vs = CatalogEvent::orderBy('id')->pluck('version')->all();
        $this->assertCount(2, $vs);
        $this->assertGreaterThan($vs[0], $vs[1]);
    }
}
