# Reclasificación de cobros: dos ejecuciones a la vez — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una sola conexión, una
transacción, SQLite compila `lockForUpdate()` a nada): dos ejecuciones de
`payments:reclassify-to-commission --apply` sobre el MISMO cobro, cada una en su
**proceso** (`worker.php`, kernel de consola real) y con su conexión a
PostgreSQL 14. No es parte de la suite de PHPUnit. Contrato:
`docs/ops/RECLASIFICACION-DE-COBROS.md`.

## Cómo garantiza el solapamiento

`race.php` abre una conexión propia que **retiene el cerrojo de la fila de la
socia** (`users`, la que el procedimiento bloquea igual que
`PaymentController::applyUpdate`) y solo lo suelta cuando `pg_blocking_pids` ve
bloqueadas LAS DOS ejecuciones: una en esa fila y la otra en el cerrojo
consultivo del procedimiento (`pg_advisory_xact_lock`). Salen escalonadas 40 ms.
Código de salida: 0 invariantes cumplidas, 1 violación, 3 sin solapamiento y sin
violación (la carrera no cuenta).

| Escenario | Qué compite | Invariante |
|---|---|---|
| `reclasificacion_doble` | dos `--apply` del mismo cobro | una `applied` y una `already_applied` (las dos con salida 0); UNA constancia, UN abono `reclass:payment:{id}`, UN entrenador, UNA identidad, UN acuerdo, UN cobro del mes, UNA deuda, UNA traza de anulación, UNA devolución de días y la membresía recortada UNA vez |

## Uso

```bash
# 1. Copia AISLADA del backend (vendor real incluido; nunca el árbol de trabajo):
rsync -a --exclude .env --exclude 'storage/logs/*' backend/ "$RACE_APP/"
# 2. .env de la copia, sin secretos (APP_ENV=testing, DB_CONNECTION=sqlite); luego
#    `php artisan key:generate`. run.sh pone la conexión PG por variables.
/opt/homebrew/opt/postgresql@14/bin/createdb -h 127.0.0.1 -p 55432 -U ironbody subs_beb_race
RACE_APP=/ruta/a/la/copia tests/Concurrency/ReclasificacionDeCobros/run.sh
```

`run.sh` hace `migrate:fresh` en `RACE_DB` (debe empezar por `subs_beb_`) y prepara
el caso con los endpoints reales (`setup.php`): mensualidad, el cobro del «plan
comisión» con su recibo y su borrador de factura, la anualidad encadenada detrás
y el turno cerrado.

## Resultado medido (17-11-2026)

```
max_blocked_seen 2 · overlap true
reclasificar-1 → salida 0, applied
reclasificar-2 → salida 0, already_applied
reclassifications 1 · reclass_abonos 1 · trainers 1 · identities 1 · agreements 1
charges 1 · receivables 1 · cancel_audits 1 · membership_audits 1 · violations []
```

Variantes mutando la COPIA (y restaurándola):

- **Sin el cerrojo consultivo**: sigue pasando. Los cerrojos de fila (turno →
  socia → cobro) serializan las dos ejecuciones y la segunda encuentra la
  constancia al releer bajo cerrojo.
- **Sin el cerrojo consultivo ni la comprobación bajo cerrojo**: la segunda
  ejecución sale con 1, `rejected [payment_not_paid]` (el cobro ya está anulado),
  y el arnés lo marca como violación. Tampoco quedan filas dobles: las
  precondiciones lo impiden, y detrás está la clave única de
  `payment_reclassifications.payment_id`.
