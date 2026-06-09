# Inventario · Tienda · Caja (Productos y Ventas)

Una sola fuente de datos (`products`) para el **Inventario del CRM** y la **Tienda
de la app**, y un registro unificado de ventas (`product_sales`) para la **Caja
del CRM** (POS) y los **pedidos de la app**.

## Tablas

- **`products`** — catálogo. `visible_in_app=true` + `active=true` + `stock>0` → aparece en la tienda (scope `forStore`). Campos: sku, name, category, description, image_url, sale_price, cost_price, stock, min_stock, supplier, visible_in_app, active.
- **`product_sales`** — venta/pedido. `channel` = `pos` (mostrador) | `app` (pedido). Estados: `pending → paid → delivered` (`cancelled`). `code` = comprobante legible (`V-000123`).
- **`product_sale_items`** — líneas con snapshot de nombre/precio.

El stock se descuenta al pasar a `paid` (`ProductSale::markPaid()`, transaccional e idempotente).

## API — CRM (admin, patrón `/admin/*`)

Inventario:
| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/admin/products` | Listar (filtros: `category`, `status`=ok\|low\|out, `search`) |
| GET | `/api/admin/products/stats` | KPIs (valor inventario, bajo stock, en app…) |
| GET/POST | `/api/admin/products` `/{id}` | CRUD |
| PUT/PATCH/DELETE | `/api/admin/products/{id}` | CRUD |
| POST | `/api/admin/products/{id}/stock` | Ajuste manual `{ delta:+/- }` |

Caja / POS:
| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/admin/caja/stats` | Ventas/ingresos de hoy, pedidos app pendientes |
| GET | `/api/admin/caja/sales` | Listar (filtros: `channel`, `status`, `today`) |
| POST | `/api/admin/caja/sales` | Venta en mostrador `{ items:[{product_id,quantity}], payment_method, paid? }` |
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
