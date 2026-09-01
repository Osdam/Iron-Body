<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El interruptor que decide qué productos ve la tienda de la app.
 *
 * La importación del sistema anterior dejó 23 productos con
 * `visible_in_app = false` y el CRM no ofrecía forma de cambiarlo: el gimnasio
 * administraba un catálogo que la app no mostraba. Estas pruebas fijan que el
 * interruptor funciona, que respeta los permisos, y —lo que más importa— que
 * NO puede tocar ningún otro campo.
 */
class ProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create([
            'name' => 'Test', 'email' => 'v-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => $role, 'status' => 'active',
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Creatina Monohidrato',
            'sku' => 'LEG-001',
            'category' => 'Cafetería',
            'description' => 'Bote de 300 g',
            'sale_price' => 89000,
            'cost_price' => 61000,
            'stock' => 12,
            'min_stock' => 2,
            'supplier' => 'Distribuidora Neiva',
            'visible_in_app' => false,
            'active' => true,
        ], $overrides));
    }

    // ── El interruptor ────────────────────────────────────────────────────

    public function test_se_puede_publicar_un_producto_en_la_tienda(): void
    {
        $product = $this->product();

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $this->actingAsAdmin($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.visible_in_app', true);

        $this->assertTrue((bool) $product->fresh()->visible_in_app);
    }

    public function test_se_puede_retirar_de_la_tienda(): void
    {
        $product = $this->product(['visible_in_app' => true]);

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => false], $this->actingAsAdmin($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.visible_in_app', false);

        $this->assertFalse((bool) $product->fresh()->visible_in_app);
    }

    public function test_hay_que_decir_cual_es_el_valor(): void
    {
        $product = $this->product();

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            [], $this->actingAsAdmin($this->admin()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('visible');
    }

    // ── Lo que NO puede pasar ─────────────────────────────────────────────

    public function test_no_toca_ningun_otro_campo(): void
    {
        // Es la garantía central: este endpoint existe precisamente para que
        // publicar un producto no pueda reescribir su precio ni su stock con
        // los valores que el CRM leyó hace diez minutos.
        $product = $this->product();
        $antes = $product->only([
            'name', 'sku', 'category', 'description', 'sale_price', 'cost_price',
            'stock', 'min_stock', 'supplier', 'active',
        ]);

        $this->patchJson("/api/admin/products/{$product->id}/visibility", [
            'visible' => true,
            // Un cliente malicioso —o descuidado— manda de todo:
            'sale_price' => 1,
            'cost_price' => 1,
            'stock' => 9999,
            'name' => 'Otro nombre',
            'active' => false,
            'sku' => 'HACK',
        ], $this->actingAsAdmin($this->admin()))->assertOk();

        $despues = $product->fresh()->only(array_keys($antes));
        $this->assertEquals($antes, $despues, 'sólo debía cambiar visible_in_app');
        $this->assertTrue((bool) $product->fresh()->visible_in_app);
    }

    public function test_sin_permiso_de_edicion_no_se_cambia(): void
    {
        $product = $this->product();

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'inventory.edit');

        $this->assertFalse((bool) $product->fresh()->visible_in_app);
    }

    public function test_sin_autenticar_tampoco(): void
    {
        $product = $this->product();

        $this->patchJson("/api/admin/products/{$product->id}/visibility", ['visible' => true])
            ->assertStatus(401);

        $this->assertFalse((bool) $product->fresh()->visible_in_app);
    }

    // ── El efecto real: qué ve la tienda ──────────────────────────────────

    public function test_publicar_lo_hace_aparecer_en_la_api_de_la_tienda(): void
    {
        $product = $this->product(['stock' => 5]);
        $headers = $this->actingAsAdmin($this->admin());

        $this->assertSame(0, Product::forStore()->where('id', $product->id)->count());

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $headers)->assertOk();

        $this->assertSame(1, Product::forStore()->where('id', $product->id)->count());
    }

    public function test_retirarlo_lo_hace_desaparecer(): void
    {
        $product = $this->product(['visible_in_app' => true, 'stock' => 5]);
        $this->assertSame(1, Product::forStore()->where('id', $product->id)->count());

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => false], $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertSame(0, Product::forStore()->where('id', $product->id)->count());
    }

    public function test_sin_stock_sigue_sin_aparecer_aunque_este_publicado(): void
    {
        // La regla de negocio actual no cambia: publicar no es lo mismo que
        // tener existencias, y la tienda no ofrece lo que no hay.
        $product = $this->product(['stock' => 0]);

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertTrue((bool) $product->fresh()->visible_in_app);
        $this->assertSame(0, Product::forStore()->where('id', $product->id)->count());
    }

    public function test_un_producto_inactivo_tampoco_aparece(): void
    {
        $product = $this->product(['active' => false, 'stock' => 5]);

        $this->patchJson("/api/admin/products/{$product->id}/visibility",
            ['visible' => true], $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertSame(0, Product::forStore()->where('id', $product->id)->count());
    }

    // ── El alta ───────────────────────────────────────────────────────────

    public function test_al_crear_se_respeta_la_visibilidad_que_se_pida(): void
    {
        $headers = $this->actingAsAdmin($this->admin());

        foreach ([true, false] as $visible) {
            $res = $this->postJson('/api/admin/products', [
                'name' => 'Producto '.($visible ? 'publicado' : 'oculto'),
                'sale_price' => 10000,
                'category' => 'Cafetería',
                'visible_in_app' => $visible,
            ], $headers)->assertSuccessful();

            $this->assertSame($visible, (bool) Product::find($res->json('data.id'))->visible_in_app);
        }
    }
}
