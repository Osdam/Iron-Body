# Carreras del segundo factor biométrico (contrato §10) — PostgreSQL real

Lo que PHPUnit con `RefreshDatabase` no puede ver (una sola conexión, una
transacción): peticiones simultáneas en **procesos separados** contra
PostgreSQL 14. No es parte de la suite de PHPUnit (no hay `*Test.php`).

## Cómo garantiza el solapamiento

`artisan serve --no-reload` con `PHP_CLI_SERVER_WORKERS=16` (sin `--no-reload`
sirve en serie). Aun así dos conexiones pueden caer en el mismo worker, así que
`race.php` abre una conexión propia que **retiene el cerrojo de la sentencia
crítica** (barrera) y solo lo suelta cuando `pg_blocking_pids` ve bloqueadas
TODAS las peticiones de la carrera (contadas por el texto de la sentencia).
Las peticiones salen escalonadas 40 ms. Si no se alcanza el solapamiento, sale
con código 3 (la carrera no cuenta).

| Escenario | Qué compite | Barrera | Invariante |
|---|---|---|---|
| `pin` | 10 PIN equivocados de la misma persona: 5 por `kiosk/verify`, 5 por `trainer/classes/{id}/start` (el contador del PIN es uno por persona) | `SELECT … FROM workforce_pins … FOR UPDATE` | exactamente 4 `pin_invalid` + 6 `pin_locked`; traza 4 `pin_failed` + 1 `pin_locked`; contador a 0 y bloqueado |
| `pair` | dos rechazos simultáneos (dos tablets) del mismo rostro contra la misma persona | `INSERT` sin confirmar del mismo par (se suelta con `ROLLBACK`) | una fila en `face_lookalikes`, 2 trazas `lookalike_detected`, los dos 409 |
| `exc_renewal` | dos renovaciones de la misma persona con la MISMA excepción | `SELECT … FROM face_lookalike_exceptions … FOR UPDATE` | un 201 y un 409; la excepción se usa una vez |
| `exc_first` | igual, pero primer registro (sin plantilla previa) | ídem | ídem; el perdedor recibe 409 `face_already_enrolled` (antes 500 por `trainer_face_references_identity_id_unique`; corregido 2026-10-27, D-3) |
| `twin` | dos personas distintas a 0,91 que se registran a la vez | `LOCK TABLE trainer_face_references IN SHARE MODE` | documenta que la comprobación 1:N dentro de `enroll()` no ve lo no confirmado (sin par); mitigado por la regla `coincidence` |

## Uso

```bash
# 1. Copia AISLADA del backend (vendor real incluido; nunca el árbol de trabajo):
rsync -a --exclude .env --exclude 'storage/logs/*' backend/ "$RACE_APP/"
# 2. .env de la copia: APP_ENV=local, DB_CONNECTION=pgsql, DB_PORT=55432, DB_USERNAME=ironbody,
#    CACHE_STORE=database, QUEUE_CONNECTION=sync, TRAINER_AUTH_ENABLED=true,
#    TRAINER_CLASSES_ENABLED=true; luego `php artisan key:generate`.
createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_biometria_race
export RACE_APP=/ruta/a/la/copia
tests/Concurrency/BiometricSecondFactor/run.sh serve-start
tests/Concurrency/BiometricSecondFactor/run.sh pin      # pin | pair | exc_renewal | exc_first | twin
tests/Concurrency/BiometricSecondFactor/run.sh serve-stop
dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_biometria_race
```

Para comprobar que el arnés detecta la regresión, mutar EN LA COPIA y repetir
(reiniciar el servidor entre versiones): quitar `->lockForUpdate()` de
`WorkforcePins::check` (escenario `pin`), quitar `->whereNull('consumed_at')`
de `LookalikeRegistry::consumeExceptions` (`exc_renewal`) o cambiar
`insertOrIgnore` por `insert` en `LookalikeRegistry::record` (`pair`). Las tres
se detectaron el 2026-10-27.

`mutaciones_casos_limite.py` hace lo mismo para
`tests/Feature/Workforce/FaceSecondFactorEdgeCasesTest.php`: 29 mutaciones,
cada una debe hacer fallar su prueba (`python3 -I mutaciones_casos_limite.py "$RACE_APP" tests/Feature/Workforce/FaceSecondFactorEdgeCasesTest.php`).
