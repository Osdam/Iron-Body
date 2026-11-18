# Carreras de «SOLICITAR VALORACIÓN» — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una conexión, una transacción; SQLite compila
`lockForUpdate()` a nada): peticiones simultáneas en **procesos separados** contra PostgreSQL 14, cada una por
el kernel HTTP real (`worker.php`, copia del de `TransferenciaDeMembresias`). No forma parte de la suite de
PHPUnit. Contrato: `docs/agenda-valoraciones/CONTRATO_SOLICITUDES_VALORACION.md` §1–§3.

## Cómo garantiza el solapamiento

`race.php` retiene con `FOR UPDATE`, desde una conexión propia, la fila donde chocan todas las peticiones del
escenario, y la suelta cuando `pg_blocking_pids` ve bloqueadas TODAS (o vence `RACE_DEADLINE`, 8 s). Peticiones
escalonadas 40 ms. Salida: 0 invariantes cumplidas, 1 violación, 3 sin solapamiento y sin violación.

- **POST de la app:** la fila del SOCIO. Con el código bueno chocan en su `FOR NO KEY UPDATE`; sin ese cerrojo,
  en el `FOR KEY SHARE` que toma la FK del INSERT. La barrera las retiene en los dos casos, así que también sirve
  para demostrar qué pasa sin cerrojo.
- **schedule frente a reject:** la fila de la SOLICITUD (`FOR UPDATE` en los dos; sin él, el UPDATE).

| Escenario | Qué compite | Invariante |
|---|---|---|
| `dos_toques_misma_llave` | dos `POST /api/app/valuation-requests` con el mismo `client_request_id` | un 201 y un 200; UNA solicitud; exactamente 2 avisos (`…:created`, `…:received`); las dos respuestas enseñan la misma |
| `dos_toques_llaves_distintas` | dos POST con ids distintos | un 201 y un 409 `valuation_request_pending`; UNA pendiente; 2 avisos |
| `agendar_vs_rechazar` | `…/schedule` y `…/reject` de la misma solicitud, `expected_version` 1 los dos | exactamente un 200 `changed` y un 409 `request_changed`/`request_not_pending`; la fila es la del ganador (agendada con cita y sin motivo, o rechazada sin cita y con motivo), versión 2, UNA traza y aviso de rechazo solo si ganó el rechazo |

## Uso

```bash
/opt/homebrew/opt/postgresql@14/bin/createdb -h 127.0.0.1 -p 55432 -U ironbody vc_c_solicitud_race
tests/Concurrency/SolicitudValoracion/run.sh all            # o un escenario
RACE_REVERSE=1 tests/Concurrency/SolicitudValoracion/run.sh agendar_vs_rechazar   # lanza primero el rechazo
/opt/homebrew/opt/postgresql@14/bin/dropdb -h 127.0.0.1 -p 55432 -U ironbody vc_c_solicitud_race
```

## Resultado medido (18-11-2026)

Los tres escenarios cumplen con `max_blocked_seen = 2`. `agendar_vs_rechazar`, 3 veces en cada orden de
lanzamiento: gana SIEMPRE quien toma primero el cerrojo de la solicitud (6/6) y el otro recibe 409
`request_changed`; nunca una mezcla.

Demostración de que el arnés detecta la regresión, mutando una COPIA del backend (`RACE_APP`):

| Mutación | Resultado |
|---|---|
| M1: sin el cerrojo del socio en `submit` (índice parcial presente) | sigue UNA solicitud: el 23505 del índice se reinterpreta (201 + 409, o 201 + 200 con la misma llave) — la segunda puerta funciona |
| M2b: código real y SIN el índice parcial (`RACE_DROP_PENDING_INDEX=1`) | sigue UNA solicitud: el cerrojo basta por sí solo |
| M2: sin cerrojo Y sin índice | 201 + 201, dos pendientes y cuatro avisos → salida 1 |
| M3: sin `FOR UPDATE` en la solicitud (`bloquear`) | los dos 200: agendada con motivo de rechazo y aviso de rechazo enviado (o rechazada con la cita enlazada), dos trazas → salida 1 |
