# Carreras de TRANSFERIR MEMBRESÍA — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una conexión, una transacción, SQLite compila
`lockForUpdate()` a nada): peticiones simultáneas en **procesos separados** contra PostgreSQL 14, cada una por
el kernel HTTP real (`worker.php`, copia del de `CorreccionesAdministrativas`). No es parte de la suite de
PHPUnit. Contrato: `docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md`.

## Cómo garantiza el solapamiento

`setup.php` crea a la titular ANTES que a los beneficiarios (id más bajo): el traspaso bloquea a los socios por
id ascendente y anular un cobro bloquea al socio, así que todas las peticiones chocan en la fila de la titular.
`race.php` retiene esa fila con `FOR UPDATE` y la suelta cuando `pg_blocking_pids` ve bloqueadas TODAS las
peticiones (o vence `RACE_DEADLINE`, 8 s). Peticiones escalonadas 40 ms. Salida: 0 invariantes cumplidas,
1 violación, 3 sin solapamiento y sin violación.

| Escenario | Qué compite | Invariante |
|---|---|---|
| `traspaso_doble_mismo_cobro` | dos `POST …/membership-transfer` del mismo cobro a B1 y B2, llaves distintas | un 201 y un 409 `transfer_blocked`; UN traspaso vivo; exactamente un beneficiario con la membresía; 2 trazas; A termina ayer |
| `traspaso_misma_llave` | la misma petición dos veces | un 201 y un 200 `idempotent_replay`; un traspaso y 2 trazas |
| `traspaso_vs_anular` | transferir y anular (`PATCH /api/payments/{id}`) el cobro de origen | nunca cobro anulado con traspaso vivo; si gana el traspaso, la anulación es 409 `membership_transferred` |
| `traspaso_vs_congelar` | transferir y congelar (`POST /api/users/{id}/freeze`) a la titular | nunca traspaso vivo con la titular congelada (sus días guardados le devolverían lo cedido); si gana el traspaso, congelar es 422 |
| `traspaso_vs_ajustar` | transferir y sumar 3 días (`POST /api/users/{id}/adjustments`) a la titular | nunca traspaso vivo con la titular terminando en o después del fin cedido; si gana el traspaso, el ajuste parte de ayer |

El patrón de «bloqueada» es `"users"`: cubre el `SELECT … FOR UPDATE` y también el `UPDATE` de una versión sin
bloqueo, que es la que hay que poder detectar.

## Uso

```bash
createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_transferencia_race
tests/Concurrency/TransferenciaDeMembresias/run.sh all        # o un escenario
```

## Resultado medido (27-10-2026)

Los cinco escenarios cumplen sus invariantes con `max_blocked_seen = 2`.

`traspaso_vs_congelar` y `traspaso_vs_ajustar` fallan (salida 1) si congelar y ajustar vuelven a calcular con el
socio que cargó la ruta en vez de bloquearlo y releerlo: la titular queda congelada con 365 días guardados, o
con el fin cedido + 3 días, y el traspaso vivo (B también los tiene).

No hay versión anterior que comparar (el endpoint es nuevo). Se comprobó que el arnés detecta la regresión
mutando temporalmente el servicio para que diera por buena la vista previa (evaluar ANTES de bloquear):
- solo esa mutación: el índice único parcial para al segundo (201 + 409) — la red funciona;
- esa mutación y sin el índice parcial: 201 + 201, dos traspasos vivos del mismo cobro, los dos beneficiarios
  con la membresía y 4 trazas → el arnés sale con 1.
