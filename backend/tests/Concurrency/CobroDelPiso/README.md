# Carrera del cobro del piso — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una sola conexión, una
transacción, SQLite compila `lockForUpdate()` a nada): dos «pagó noviembre» del
mismo acuerdo en **procesos separados** contra PostgreSQL 14, cada uno por el
kernel HTTP real de Laravel (`worker.php`, el mismo de
`CorreccionesAdministrativas`). No es parte de la suite de PHPUnit. Contrato:
`docs/ops/VENCIMIENTOS-PERSONALIZADOS.md` §5.

## El fallo que cierra

La migración `2026_10_09_000005` quitó el índice único `(agreement_id, period)`
para poder volver a cobrar un mes anulado, y la invariante «un cobro vigente por
acuerdo y mes» quedó solo en `TrainerCommissions::charge()`, que miraba el mes
**sin cerrojo**. Dos peticiones a la vez pasaban las dos la comprobación y
dejaban dos cobros vigentes del mismo mes y, con medio de pago, **dos abonos**:
el mismo mes cobrado dos veces.

Ahora `charge()` bloquea la fila del acuerdo (`FOR UPDATE`) dentro de la
transacción y vuelve a mirar el mes ya bloqueado: la segunda petición espera,
lo ve cobrado y responde 422.

## Cómo garantiza el solapamiento

`race.php` retiene `FOR UPDATE` sobre la fila del acuerdo y solo la suelta
cuando `pg_blocking_pids` ve bloqueadas LAS DOS peticiones (o vence
`RACE_DEADLINE`, 8 s por defecto). Las peticiones salen escalonadas 40 ms.

- Con la corrección se bloquean en el `SELECT … FOR UPDATE` del acuerdo, antes
  de mirar el mes.
- Sin ella, ya habiendo visto el mes libre: en el `INSERT` del cobro, cuya llave
  foránea pide `FOR KEY SHARE` sobre la misma fila.

Código de salida: 0 invariantes cumplidas, 1 violación, 3 sin solapamiento y sin
violación (la carrera no cuenta).

| Escenario | Qué compite | Invariante |
|---|---|---|
| `cobro_doble_del_mes` | dos `POST /api/admin/commissions/agreements/{id}/charge` del mismo mes con `method=cash` | un 201 y un 422; UN cobro vigente del mes, UN abono aplicado por el importe del mes |

## Uso

```bash
createdb -h 127.0.0.1 -p 55432 -U ironbody subs_bea_race   # o ultron_studio_*
RACE_DB=subs_bea_race tests/Concurrency/CobroDelPiso/run.sh cobro_doble_del_mes
```

`run.sh` hace `migrate:fresh` en `RACE_DB` (debe empezar por `ultron_studio_` o
`subs_bea_`) y prepara el escenario con los endpoints reales (`setup.php`).

## Demostrar el fallo en una versión anterior

```bash
git worktree add --detach /ruta/base 7f9219d5
cp -cR backend/vendor /ruta/base/backend/vendor
# .env con solo APP_KEY (sin secretos): Write + `php artisan key:generate`
RACE_APP=/ruta/base/backend RACE_DB=subs_bea_race tests/Concurrency/CobroDelPiso/run.sh cobro_doble_del_mes
```

Resultado medido el 2026-11-17:

- base `7f9219d5`: `max_blocked_seen = 2`, las dos peticiones 201, 2 cobros
  vigentes del mes, 2 abonos aplicados, 100.000 cobrados por un mes de 50.000
  (salida 1);
- con la corrección: `max_blocked_seen = 2`, un 201 y un 422 «Ese mes ya está
  cobrado…», 1 cobro, 1 abono, 50.000 (salida 0, cuatro de cuatro corridas).
