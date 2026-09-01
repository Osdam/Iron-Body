<?php

namespace App\Console\Commands;

use App\Enums\InventoryMovementOrigin;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Importa el «Listado de productos» del sistema anterior al módulo Inventario.
 *
 * El stock NO se escribe a mano en ningún momento: la carga inicial entra por
 * {@see InventoryService::registerEntry()} con origen `initial_stock`, que es el
 * único camino que mueve `products.stock` y deja la fila correspondiente en
 * `inventory_movements`. Así el inventario nace con historia en vez de con un
 * saldo suelto que nadie puede auditar.
 *
 * Idempotente por SKU `LEG-<id del producto>`: el id lo asigna el sistema viejo.
 * Volver a correrlo actualiza precios y datos del catálogo, pero NO vuelve a
 * cargar existencias — duplicar la entrada inicial inflaría el stock. Para
 * cuadrar un saldo que ya se movió está el ajuste de inventario del CRM, que
 * deja constancia de quién lo hizo y por qué.
 *
 *   php artisan iron:import-legacy-products --archivo=... --dry-run
 *   php artisan iron:import-legacy-products --archivo=...
 */
class ImportLegacyProductsCommand extends Command
{
    protected $signature = 'iron:import-legacy-products
        {--archivo= : Export «Listado de productos» (CSV delimitado por ;)}
        {--categoria=Cafetería : Categoría para los productos nuevos}
        {--visible-en-app : Publica también los productos nuevos en la tienda de la app}
        {--dry-run : Simula todo en una transacción y hace rollback}';

    protected $description = 'Importa el catálogo de productos del sistema anterior con su carga inicial de inventario.';

    /** Prefijo de SKU que identifica un producto traído del sistema anterior. */
    private const PREFIJO_SKU = 'LEG-';

    public function __construct(private readonly InventoryService $inventario)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ruta = (string) $this->option('archivo');
        if ($ruta === '' || ! is_file($ruta)) {
            $this->error('Falta o no existe --archivo: '.($ruta !== '' ? $ruta : '(vacío)'));

            return self::FAILURE;
        }

        $filas = $this->leerCsv($ruta);
        if ($filas === []) {
            $this->error('El export de productos no tiene filas.');

            return self::FAILURE;
        }

        $this->info('Productos leídos: '.count($filas));

        // Autor de la carga inicial: el movimiento guarda un nombre aunque la
        // cuenta desaparezca, pero conviene que apunte a alguien real si existe.
        $autor = User::query()->orderBy('id')->first();

        $c = ['nuevos' => 0, 'actualizados' => 0, 'stock_cargado' => 0, 'unidades' => 0, 'omitidos' => 0, 'errores' => 0];
        $seco = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            foreach ($filas as $i => $fila) {
                try {
                    // Punto de guardado por producto: en PostgreSQL un error de
                    // clave deja abortada la transacción entera, y una fila mala
                    // del export se llevaría por delante todo el catálogo.
                    DB::transaction(fn () => $this->importarProducto($fila, $autor, $c));
                } catch (Throwable $e) {
                    $c['errores']++;
                    $this->warn('Fila '.($i + 2).": {$e->getMessage()}");
                }
            }

            if ($seco) {
                DB::rollBack();
                $this->warn('DRY-RUN: todo revertido, no se escribió nada en la base de datos.');
            } else {
                DB::commit();
                $this->info('Importación confirmada.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Abortado (rollback total): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Métrica', 'Total'], collect($c)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    private function importarProducto(array $fila, ?User $autor, array &$c): void
    {
        $idLegacy = trim((string) ($fila['ID del producto'] ?? ''));
        $nombre = trim((string) ($fila['Nombre del producto'] ?? ''));
        if ($idLegacy === '' || $nombre === '') {
            $c['omitidos']++;

            return;
        }

        $sku = self::PREFIJO_SKU.$idLegacy;
        $producto = Product::withTrashed()->where('sku', $sku)->first();

        // Un producto que alguien retiró del catálogo en Iron Body no vuelve por
        // seguir apareciendo en el export: el borrado fue una decisión, y
        // deshacerla en silencio la anularía. Se restaura desde Inventario.
        if ($producto !== null && $producto->trashed()) {
            $c['omitidos']++;

            return;
        }

        $esNuevo = $producto === null;
        if ($esNuevo) {
            $producto = new Product;
            $producto->sku = $sku;
            $producto->category = (string) $this->option('categoria');
            // El catálogo de cafetería no se publica en la tienda de la app por
            // defecto: eso es una decisión comercial, no un efecto de importar.
            $producto->visible_in_app = (bool) $this->option('visible-en-app');
            // Nace en cero: las existencias las pone el servicio de inventario.
            $producto->stock = 0;
            $producto->active = Str::lower(trim((string) ($fila['Estado'] ?? 'Activo'))) === 'activo';
        }

        // Ficha comercial: el sistema anterior es donde recepción la mantiene al
        // día, así que sus precios y textos mandan. `active` y `visible_in_app`
        // NO se tocan al actualizar — son decisiones que se toman aquí.
        $producto->name = $nombre;
        $producto->description = $this->texto($fila['Descripción del producto'] ?? null);
        $producto->supplier = $this->texto($fila['Proveedor(es)'] ?? null);
        // El export declara IVA 0% en todo el catálogo, así que el precio con y
        // sin IVA coinciden; se toma el precio CON IVA, que es lo que se cobra.
        $producto->sale_price = $this->dinero($fila['Precio de venta con IVA del producto'] ?? null);
        $costo = $this->dinero($fila['Precio de compra promedio del producto'] ?? null);
        if ($costo > 0) {
            $producto->cost_price = $costo;
        }
        $producto->save();
        $esNuevo ? $c['nuevos']++ : $c['actualizados']++;

        if (! $esNuevo) {
            return; // el saldo ya vive en Iron Body; recargarlo lo duplicaría
        }

        $existencias = $this->unidades($fila['Stock actual del producto'] ?? null);
        if ($existencias <= 0) {
            return;
        }

        $this->inventario->registerEntry(
            product: $producto,
            quantity: $existencias,
            origin: InventoryMovementOrigin::INITIAL_STOCK,
            reason: 'Carga inicial desde el sistema anterior',
            user: $autor,
            unitAmount: $costo > 0 ? $costo : null,
            notes: "Importado del export de productos (id anterior {$idLegacy}).",
        );
        $c['stock_cargado']++;
        $c['unidades'] += $existencias;
    }

    /** «329 Unds» → 329. */
    private function unidades(?string $valor): int
    {
        return (int) preg_replace('/[^\d]/', '', (string) $valor);
    }

    /** «$ 3.000» / «3000» → 3000.0 (el export usa el punto como millar). */
    private function dinero(?string $valor): float
    {
        return (float) preg_replace('/[^\d]/', '', (string) $valor);
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /** Lee un CSV `;` en UTF-8 (con o sin BOM) como lista de mapas cabecera→valor. */
    private function leerCsv(string $ruta): array
    {
        $fh = fopen($ruta, 'r');
        if ($fh === false) {
            return [];
        }

        $filas = [];
        $cabecera = null;
        while (($cols = fgetcsv($fh, 0, ';')) !== false) {
            if ($cols === [null]) {
                continue;
            }
            if ($cabecera === null) {
                $cols[0] = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cols[0]), '"');
                $cabecera = array_map(fn ($h) => trim((string) $h), $cols);

                continue;
            }
            $fila = [];
            foreach ($cabecera as $j => $clave) {
                $fila[$clave] = $cols[$j] ?? '';
            }
            $filas[] = $fila;
        }
        fclose($fh);

        return $filas;
    }
}
