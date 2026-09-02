<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Imagen del producto para la tienda de la app.
 *
 * La auditoría encontró 31 productos de 31 sin imagen: aunque se publiquen, la
 * tienda serían tarjetas grises. Estas pruebas fijan que se puede subir,
 * reemplazar y retirar sin dejar basura en disco, y que no entra cualquier cosa.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(string $role = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create([
            'name' => 'Test', 'email' => 'img-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => $role, 'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Creatina', 'category' => 'Cafetería',
            'sale_price' => 89000, 'cost_price' => 61000,
            'stock' => 5, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
        ]);
    }

    private function jpg(int $w = 800, int $h = 800): UploadedFile
    {
        return UploadedFile::fake()->image('foto.jpg', $w, $h);
    }

    // ── Subir ─────────────────────────────────────────────────────────────

    public function test_se_sube_la_imagen_y_queda_servible(): void
    {
        $product = $this->product();

        $res = $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => $this->jpg()], $this->actingAsAdmin($this->admin()))->assertOk();

        Storage::disk('public')->assertExists("products/{$product->uuid}.jpg");

        $url = $res->json('data.image_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString("products/{$product->uuid}.jpg", $url);
        $this->assertStringStartsWith('http', $url, 'la app necesita una URL absoluta');
    }

    public function test_reemplazar_no_deja_el_fichero_anterior(): void
    {
        // Sin esto, cada edición dejaría un huérfano en disco para siempre.
        $product = $this->product();
        $headers = $this->actingAsAdmin($this->admin());

        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => UploadedFile::fake()->image('vieja.png', 600, 600)], $headers)->assertOk();
        Storage::disk('public')->assertExists("products/{$product->uuid}.png");

        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => $this->jpg()], $headers)->assertOk();

        Storage::disk('public')->assertExists("products/{$product->uuid}.jpg");
        Storage::disk('public')->assertMissing("products/{$product->uuid}.png");
    }

    public function test_el_nombre_del_fichero_no_lo_decide_quien_sube(): void
    {
        // Un nombre controlado por el cliente es una vía de recorrido de rutas
        // y de extensiones dobles. El del disco sale del uuid del producto.
        $product = $this->product();

        $this->postJson("/api/admin/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('../../evil.php.jpg', 400, 400),
        ], $this->actingAsAdmin($this->admin()))->assertOk();

        Storage::disk('public')->assertExists("products/{$product->uuid}.jpg");
        $this->assertCount(1, Storage::disk('public')->files('products'));
    }

    // ── Lo que no entra ───────────────────────────────────────────────────

    public function test_no_se_admite_un_ejecutable_disfrazado(): void
    {
        $product = $this->product();

        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => UploadedFile::fake()->create('script.php', 10, 'application/x-php')],
            $this->actingAsAdmin($this->admin()))
            ->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertNull($product->fresh()->image_url);
    }

    public function test_no_se_admite_una_imagen_diminuta(): void
    {
        // 32x32 se vería como un sello borroso en una tarjeta de tienda.
        $this->postJson("/api/admin/products/{$this->product()->id}/image",
            ['image' => $this->jpg(32, 32)], $this->actingAsAdmin($this->admin()))
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_hay_que_mandar_algo(): void
    {
        $this->postJson("/api/admin/products/{$this->product()->id}/image",
            [], $this->actingAsAdmin($this->admin()))
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    // ── Retirar ───────────────────────────────────────────────────────────

    public function test_retirar_borra_el_fichero_y_limpia_la_columna(): void
    {
        $product = $this->product();
        $headers = $this->actingAsAdmin($this->admin());
        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => $this->jpg()], $headers)->assertOk();

        $this->deleteJson("/api/admin/products/{$product->id}/image", [], $headers)
            ->assertOk()->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing("products/{$product->uuid}.jpg");
    }

    public function test_retirar_una_imagen_externa_no_intenta_borrar_del_disco(): void
    {
        // `image_url` puede apuntar fuera (una URL pegada a mano). Sólo se borra
        // del disco lo que vive en el disco.
        $product = $this->product();
        $product->update(['image_url' => 'https://cdn.ajeno.com/foto.jpg']);

        $this->deleteJson("/api/admin/products/{$product->id}/image", [],
            $this->actingAsAdmin($this->admin()))->assertOk();

        $this->assertNull($product->fresh()->image_url);
    }

    // ── Permisos ──────────────────────────────────────────────────────────

    public function test_sin_inventory_edit_no_se_toca_la_imagen(): void
    {
        $product = $this->product();
        $recepcion = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => $this->jpg()], $recepcion)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.edit');

        $this->deleteJson("/api/admin/products/{$product->id}/image", [], $recepcion)
            ->assertStatus(403);
    }

    public function test_sin_autenticar_tampoco(): void
    {
        $this->postJson("/api/admin/products/{$this->product()->id}/image",
            ['image' => $this->jpg()])->assertStatus(401);
    }

    // ── El DTO de la tienda ───────────────────────────────────────────────

    public function test_la_imagen_llega_a_la_tienda_y_el_coste_no(): void
    {
        $product = $this->product();
        $this->postJson("/api/admin/products/{$product->id}/image",
            ['image' => $this->jpg()], $this->actingAsAdmin($this->admin()))->assertOk();

        $dto = $product->fresh()->toStoreArray();

        $this->assertStringContainsString('products/', (string) $dto['image_url']);
        foreach (['cost_price', 'supplier', 'min_stock'] as $interno) {
            $this->assertArrayNotHasKey($interno, $dto, "$interno es interno y no puede viajar");
        }
    }

    public function test_el_dto_trae_sku_disponibilidad_y_fecha(): void
    {
        $product = $this->product();                       // stock 5, min 2 → in_stock
        $product->update(['sku' => 'LEG-99']);

        $dto = $product->fresh()->toStoreArray();
        $this->assertSame('LEG-99', $dto['sku']);
        $this->assertSame('in_stock', $dto['availability']);
        $this->assertNotNull($dto['updated_at']);

        $product->update(['stock' => 2]);                  // stock <= min → low
        $this->assertSame('low', $product->fresh()->toStoreArray()['availability']);

        $product->update(['stock' => 0]);
        $this->assertSame('out_of_stock', $product->fresh()->toStoreArray()['availability']);
    }
}
