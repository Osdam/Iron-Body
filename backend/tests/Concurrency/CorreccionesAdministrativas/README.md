# Carreras de las correcciones administrativas — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una sola conexión, una
transacción, SQLite compila `lockForUpdate()` a nada): dos peticiones
simultáneas en **procesos separados** contra PostgreSQL 14, cada una por el
kernel HTTP real de Laravel (`worker.php`, sin `artisan serve`). No es parte de
la suite de PHPUnit. Contrato: `docs/ops/CORRECCIONES-ADMINISTRATIVAS.md`.

## Cómo garantiza el solapamiento

`race.php` abre una conexión propia que **retiene el cerrojo de la fila que todas
las versiones del código bloquean en ese camino** (el socio al anular un cobro;
el producto al descontar stock) y solo lo suelta cuando `pg_blocking_pids` ve
bloqueadas TODAS las peticiones (o vence `RACE_DEADLINE`, 8 s por defecto). Las
peticiones salen escalonadas 40 ms. Código de salida: 0 invariantes cumplidas,
1 violación, 3 sin solapamiento y sin violación (la carrera no cuenta).

| Escenario | Qué compite | Invariante |
|---|---|---|
| `pago_doble_anulacion` | dos `PATCH /api/payments/{id}` `status=cancelled` del mismo cobro | cobro anulado, UNA traza de anulación, UNA devolución de días, membresía recortada una vez |
| `venta_doble_cobro` | dos `POST /api/admin/caja/sales/{id}/pay` del mismo pedido | pagada, UNA unidad descontada, stock = movimientos |
| `venta_devoluciones_simultaneas` | dos `POST /api/admin/caja/sales/{id}/refund` de 2 unidades sobre una venta de 3 | una 201 y otra 422 `refund_exceeds_sold`; 2 unidades devueltas, UNA salida de caja, 2 unidades reingresadas |
| `venta_anular_vs_cobrar` | `pay` y, 40 ms después, `cancel` del mismo pedido | estado final coherente: si se respondió «anulada» queda anulada y el producto vuelve; si queda cobrada, sin marca de anulación |

## Uso

```bash
createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_correcciones_race
tests/Concurrency/CorreccionesAdministrativas/run.sh all        # o un escenario
```

`run.sh` hace `migrate:fresh` en `RACE_DB` (debe empezar por `ultron_studio_`) y
prepara cada escenario con los endpoints reales (`setup.php`).

## Demostrar el fallo en una versión anterior

```bash
git worktree add --detach /ruta/base 2425eef
cp -R backend/vendor /ruta/base/backend/vendor
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > /ruta/base/backend/.env
RACE_APP=/ruta/base/backend tests/Concurrency/CorreccionesAdministrativas/run.sh all
```

Resultado medido el 2026-10-27 sobre 2425eef (las tres violan; con las
correcciones, las tres pasan con `max_blocked_seen = 2`):

- `pago_doble_anulacion`: 2 trazas de anulación para una anulación (la segunda
  petición leía el estado sin bloquear y escribía otra).
- `venta_doble_cobro`: un pedido de 1 unidad cobrado dos veces sacó 2 unidades.
- `venta_anular_vs_cobrar`: se respondió «anulada» y la venta quedó «paid» con
  `cancelled_at` puesto (`cancel()` no bloqueaba y `markPaid()` miraba memoria).

`venta_devoluciones_simultaneas` no existe en 2425eef (no había devoluciones). Se comprobó que detecta
la regresión mutando una COPIA: sin el bloqueo de la venta en `SaleCorrections::refundLocked`, las dos
devoluciones se aceptan (2 salidas de caja, 4 unidades reingresadas de 3 vendidas).
