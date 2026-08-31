# Inventario · Tienda · Caja (Productos y Ventas)

Una sola fuente de datos (`products`) para el **Inventario del CRM** y la **Tienda
de la app**, y un registro unificado de ventas (`product_sales`) para la **Caja
del CRM** (POS) y los **pedidos de la app**.

## Frontera de dominios

| Dominio | Qué vende | Dónde vive | ¿Mueve inventario? |
|---|---|---|---|
| **Inventario** | nada | `products` + `inventory_movements` | escribe y consulta existencias |
| **Caja** | producto físico (cafetería, suplementos, mercancía) | `product_sales` + `product_sale_items` | **sí**, al pasar a `paid` |
| **Pagos / Membresías** | plan o membresía (servicio) | `payments` (+ `users.membership_end_date`) | **no**, nunca |

Reglas duras:

- **Inventario no vende.** No hay carrito ni cobro en `/inventory`; el punto de
  venta vive únicamente en `/caja`. Las salidas que se registran a mano son
  administrativas (daño, pérdida, vencimiento, consumo interno, ajuste) y exigen
  motivo.
- **La venta de un plan no toca existencias.** `PaymentController` no llama nunca
  a `InventoryService`.
- **La venta de producto físico siempre deja movimiento** con origen
  `sale_cafeteria` y referencia al comprobante de la venta.
- **No hay stock negativo.** La validación es de backend
  (`InventoryService::assertAvailability()` + bloqueo de fila al descontar); el
  frontend solo la refleja.
- **No existen operaciones mixtas** (plan + producto en un mismo cobro), ni el
  negocio las ha necesitado.

## Tablas

- **`products`** — catálogo. `visible_in_app=true` + `active=true` + `stock>0` → aparece en la tienda (scope `forStore`). Campos: sku, name, category, description, image_url, sale_price, cost_price, stock, min_stock, supplier, visible_in_app, active.
- **`product_sales`** — venta/pedido. `channel` = `pos` (mostrador) | `app` (pedido). Estados: `pending → paid → delivered` (`cancelled`). `code` = comprobante legible (`V-000123`).
- **`product_sale_items`** — líneas con snapshot de nombre/precio.
- **`inventory_movements`** — libro de existencias (append-only). Toda variación de
  `products.stock` escribe una fila con `type` (in|out), `origin`, `quantity`,
  `stock_before`, `stock_after`, autor y —en las bajas administrativas— motivo.
  Las salidas por venta llevan `reference` al `ProductSale`.

El stock se descuenta al pasar a `paid` (`ProductSale::markPaid()`, transaccional e
idempotente). **El único punto que escribe existencias es
`App\Services\Inventory\InventoryService`**: bloquea la fila del producto, valida
y deja traza. Si una línea no alcanza lanza `InsufficientStockException`, la
transacción se deshace y **la venta no queda cobrada** — antes el fallo se
descartaba en silencio y quedaba una venta pagada con el stock intacto.

## API — CRM (admin, patrón `/admin/*`)

Inventario:
| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/admin/products` | Listar (filtros: `category`, `status`=ok\|low\|out, `search`) |
| GET | `/api/admin/products/stats` | KPIs (valor inventario, bajo stock, en app…) |
| GET/POST | `/api/admin/products` `/{id}` | CRUD |
| PUT/PATCH/DELETE | `/api/admin/products/{id}` | CRUD |
| POST | `/api/admin/products/{id}/entry` | Entrada `{ quantity, origin?, reason?, unit_amount?, notes? }` |
| POST | `/api/admin/products/{id}/exit` | Salida administrativa `{ quantity, origin, reason, notes? }` (`reason` obligatorio; `origin` no admite `sale_cafeteria`) |
| GET | `/api/admin/products/{id}/movements` | Historial del producto |
| GET | `/api/admin/inventory/movements` | Historial global (`product_id`, `type`, `origin`, `from`, `to`) |
| GET | `/api/admin/inventory/movement-options` | Orígenes válidos para entradas y salidas |
| POST | `/api/admin/products/{id}/stock` | Ajuste heredado `{ delta:+/-, reason? }` — ahora traza y ya no recorta en silencio |

Caja / POS:
| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/admin/caja/stats` | Ventas/ingresos de hoy, pedidos app pendientes |
| GET | `/api/admin/caja/sales` | Listar (filtros: `channel`, `status`, `today`) |
| POST | `/api/admin/caja/sales` | Venta en mostrador `{ items:[{product_id,quantity}], payment_method, paid? }` — **422 `insufficient_stock`** si no alcanza |
| GET | `/api/admin/caja/sales/{id}` | Comprobante/detalle |
| POST | `/api/admin/caja/sales/{id}/pay` | Confirmar pago (descuenta stock) |
| POST | `/api/admin/caja/sales/{id}/deliver` | Marcar entregado |
| POST | `/api/admin/caja/sales/{id}/cancel` | Cancelar |

## API — App (miembro autenticado, `auth.member`)

| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/app/store/products` | Catálogo de tienda `{ data:[{id,name,price,stock,...}], categories }` |
| POST | `/api/app/store/orders` | Checkout `{ items:[{product_id,quantity}], payment_method, receipt_url?, notes? }` |
| GET | `/api/app/store/orders` | Mis pedidos |
| GET | `/api/app/store/orders/{uuid}` | Comprobante de un pedido |
| POST | `/api/app/store/orders/{uuid}/receipt` | Adjuntar comprobante `{ receipt_url }` |

## Flujos de pago (app)

- **`cash`** — *reservar y pagar en caja*: pedido `pending`; el miembro paga en el mostrador y la **Caja** lo confirma (`/pay`), que descuenta stock.
- **`online` / `nequi` / `transfer`** — el miembro adjunta un **comprobante** (`receipt_url`); la Caja lo verifica y confirma. La estructura admite además pasarela automática vía `payment_reference` cuando se integre el cobro.

## Sincronización

No hay duplicación: la Tienda de la app y el Inventario del CRM leen la **misma**
tabla `products`. Publicar/ocultar un producto en la app = `visible_in_app`. Al
vender (POS o app confirmada) el stock baja una sola vez y se refleja en ambos.

## Datos iniciales

```bash
php artisan migrate
php artisan db:seed --class='Database\Seeders\ProductSeeder'
```


## Movimientos de inventario

`inventory_movements` empieza a llenarse desde el despliegue de esta separación.
Los movimientos anteriores **no se rellenan hacia atrás**: fabricar historial que
nadie registró sería inventar auditoría. El saldo actual de `products.stock` es
el punto de partida, y a partir de ahí toda variación queda explicada.

Orígenes (`App\Enums\InventoryMovementOrigin`):

| Origen | Dirección | Quién lo registra |
|---|---|---|
| `purchase` | entrada | operador (reposición) |
| `return` | entrada | operador (devolución de cliente) |
| `initial_stock` | entrada | sistema, al crear el producto con stock |
| `damage` · `loss` · `expiration` · `internal_use` | salida | operador, con motivo |
| `adjustment` | ambas | operador (corrección de conteo) |
| `sale_cafeteria` | salida | **sistema**, al cobrar la venta — nunca a mano |

## Pruebas

`tests/Feature/Inventory/` fija el contrato: la venta de producto descuenta y
traza, la venta de plan no toca inventario, cobrar sin stock falla sin dejar la
venta pagada, la salida administrativa exige motivo y no admite origen de venta.
