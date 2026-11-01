# Hito 7 · paquete DB7 · informe del `database-specialist`

**Fecha:** 01-11-2026. **Árbol:** `_worktrees/ultron-h7/Iron-Body/backend`, rama `feat/ultron-studio-h7` (sobre `f78adfc`). **Sin commit** (lo confirma el lead). **Producción: no he tocado nada, ni para leer.** El árbol es compartido: durante mis corridas B7, SEC7 y OPS7 tenían cambios sin confirmar en sus ficheros (`StudioPublications.php`, `PlainText`, `routes/console.php`…); las cifras de abajo incluyen ese estado.

**Estado: INCOMPLETO por límite de uso.** Faltan: la corrida PG del grupo completo con mis cambios (lanzada, no concluida; salida en el scratchpad de la sesión, `after/pg-group.txt`) y las 14 mutaciones de mi prueba (generadas y aisladas, no ejecutadas). Ver §8.

## 1. Motor y versión
PostgreSQL 14.24 (clúster desechable `127.0.0.1:55432`; producción 14.24 según los informes H5/H6, no comprobado por mí) y SQLite 3.54.1 (PDO de PHP 8.3.33). Laravel 12, PHPUnit 11.5.55.

## 2. Ficheros
| Fichero | Qué |
|---|---|
| `database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php` (nuevo) | Cabecera con decisiones (1–108). `up()`/`down()` por motor. `upPostgres()`: `LOCK TABLE … ACCESS EXCLUSIVE`, `refuseBrokenChain()`, `uspub_seq_chk`, función `ultron_studio_publication_chain()`, disparador `uspub_chain_next`, `revokeAppWrites()`. `upSqlite()`: `uspub_chain_next` + `uspub_seq_min`. `down*()` con `refuseWithRows()` y `restoreAppWrites()` |
| `app/Services/UltronStudio/StudioCanaryNumbers.php` | +10 líneas en `registerGated()`: comprobación previa del duplicado, tras el tope de vivos y bajo el cerrojo consultivo |
| `tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php` (nuevo) | 16 métodos / 25 casos |
| `tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php` | Recuentos 44/50/32, listas de la cadena con las guardas H7, `assertH5SchemaPresent()` mira solo las del H5, `publicationRow()` encadena con la cabeza |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php` | Constantes de recuento con el H7 (31/44, 28/50, 22/32) y mensajes |
| `docs/ultron-studio/plans/informes-h7/informe-db.md` | Este informe |

`vendor/bin/pint --test` sobre los 5 PHP: `passed`.

## 3. Guardas finales
- **PG:** `uspub_chain_next` (BEFORE INSERT, por fila, función `ultron_studio_publication_chain()` plpgsql, VOLATILE, INVOKER, dueño = rol que migra, tabla cualificada `public.`); `uspub_seq_chk` = `CHECK (seq >= 1)`; `REVOKE UPDATE, DELETE ON ultron_studio_publications, ultron_studio_audit_events, ultron_studio_integration_snapshots FROM iron_app` + comprobación posterior (`has_any_column_privilege`/`has_table_privilege`). `ultron_studio_forbid()` no se redeclara: md5 `4ad748b5f17f09b398f7f641c197d7ee` antes y después.
- **SQLite:** `uspub_chain_next` (misma regla, `IS NOT` nulo-seguro) y `uspub_seq_min` (equivalente del CHECK).
- Regla: por encima de la cabeza solo la siguiente y apuntando a ella → P0001 (23000 en SQLite). Lo que no pasa de la cabeza lo deciden el UNIQUE de `seq` (23505, la carrera que el servicio traduce a 409 `publication_moved`) y el CHECK (23514).

## 4. Recuentos (para OPS7)
| | Antes (H6) | Con H7 | Tras `rollback --step=1` |
|---|---|---|---|
| PG disparadores `ultron_studio_%` | 43 | 44 | 43 |
| PG CHECK `ultron_studio_%` | 49 | 50 | 49 |
| PG `uspub_%` disparadores / CHECK | 3 / 3 | 4 / 4 | 3 / 3 |
| PG función `ultron_studio_publication_chain` | 0 | 1 | 0 |
| SQLite disparadores `ultron_studio_%` | 30 | 32 | 30 |
| `iron_app` en cadena, libro e instantáneas | `arwd` | `ar` | `arwd` |
| `iron_app` en canarios y asociaciones | `arwd` | `arwd` | `arwd` |

Propuesta de `h7-schema-check.sql` (de OPS7; validada antes y después en `ultron_studio_h7_db_roles` con `-v owner=h5_owner -v app=h5_app`, valores exactos a los anotados): copia en el scratchpad de la sesión (`h7-schema-check.sql`); incluye recuentos, huella de F, dueños, `existe_app`, **integridad de la cadena existente (`rotas` = 0, si no la migración se niega)**, cerrojos sobre la cadena y privilegios uno a uno.

## 5. Decisiones fuera del contrato (las confirma el lead)
- **D-1** El disparador no rechaza `seq` ≤ cabeza: si lo hiciera, la carrera perdida dejaría de ser 23505 → 409 y pasaría a 500. Probado: repetido → 23505 (`…_seq_unique`), 0/−1 → 23514.
- **D-2** Función propia y tabla cualificada: sin `public.`, una tabla temporal (el rol de la app tiene TEMP por defecto) falsea la cabeza. Probado como `h5_app` real: P0001.
- **D-3** `up()` se niega si la cadena existente no es consecutiva/encadenada (no repara datos).
- **D-4** Rol por defecto `iron_app`; `ULTRON_STUDIO_DB_APP_ROLE` en el entorno para el banco (`h5_app`). Sin el rol: se sigue, salvo `APP_ENV=production` (falla). Tras el REVOKE se comprueba que ya no puede; si lo hereda (PUBLIC u otro rol), falla.
- **D-5** `down()` se niega con filas en cualquiera de las 3 tablas (el libro siempre tiene en un entorno real) y devuelve UPDATE/DELETE solo donde el rol conserva INSERT.
- **D-6** Una sola migración: todo o nada (el REVOKE no toma cerrojos; separarla no aportaba y abría el estado a medias de R-1 del H6).
- **D-7** Comprobación previa del canario detrás del tope de vivos: con tope lleno y duplicado gana `canary_live_limit`, como antes.

## 6. Verificado ejecutando (comando → resultado)
- SQLite, línea base antes de tocar: Studio + Unit/Studio + lector del motor → 2702 / 26 776 / 102 omitidas, verde. PG grupo (18 ficheros) → 565 / 10 135, verde.
- `UltronStudioHardeningH7Test`: SQLite 25 pruebas / 126 aserciones / 4 omitidas (solo PG); **PG 25 / 250, verde**. La prueba cazó un fallo mío (comparación con NULL en la comprobación de la cadena), corregido.
- Esquema H5 + H6 + H7 juntos: SQLite 60 / 545 / 12 omitidas; **PG 60 / 885**, verde.
- SQLite tras los cambios (Studio entero + lector del motor): 2749 pruebas; **todo el Studio en verde** (publicar, promover, revertir, Legacy, H6). Rompen: 29 casos de `UltronPublishedConfigTest`, 6 de `UltronCommitStudioStampTest` (H-2) y 1 de `UltronEngineIsolationTest` (H-1).
- SQLite fichero: base→H7 solo +2 disparadores (la tabla no se reconstruye); `rollback --step=1` (11,67 ms) y `migrate` (2,99 ms) → `sqlite_master` IDÉNTICO; `migrate:fresh` (dos veces) IDÉNTICO al incremental; `down()` con 1 fila en cada tabla → exit 1, esquema idéntico, `Ran`.
- PG: `pg_dump -s` base→H7 = 37 líneas añadidas y 1 «cambiada» (coma de `uspub_reason_chk`); `rollback`/`migrate` IDÉNTICO. `--pretend` en los dos sentidos: solo cadena, libro, instantáneas y la función nueva.
- Modelo dueño/app (`h5_owner` sin superusuario, `h5_app` con privilegios por defecto) en `ultron_studio_h7_db_roles`: migrado sin ningún GRANT; `rollback` → `pg_dump -s` IDÉNTICO a la base H6 **con las ACL**; como `h5_app` real: inserta cadena encadenada, libro e instantánea; `seq` 2147483647 y tabla temporal → P0001; UPDATE/DELETE/`FOR UPDATE`/TRUNCATE en las 3 → 42501; tocar disparador/CHECK/función, `session_replication_role` → 42501; canarios siguen con UPDATE.
- `lock_timeout` 5 s: lector abierto de la cadena → falla limpia a los 5,58 s (55P03), `Pending`, nada aplicado; reintento OK. Escritores abiertos en libro e instantáneas → migra en 16 ms sin esperar (REVOKE sin cerrojo de tabla, comprobado en `pg_locks`).
- `APP_ENV=production` sin `iron_app` → exit 1 con el mensaje, `Pending`, nada aplicado.
- H-2 en el log real del servidor: con verbosidad por defecto, un 23505 en `uscn_one_live` deja `DETAIL: Key (channel, phone_hash)=(…, <huella>)`; un 23514 en canarios deja `Failing row contains (…)` con alias, huella y últimos 4 (y el cifrado en voz); con `terse` no hay DETAIL. Laravel registra `STATEMENT` con `$1, $2…` (sin valores) y `log_parameter_max_length_on_error = 0`.

## 7. Hallazgos para otros dueños (no los he tocado)
- **H-1 · `UltronEngineIsolationTest`:** añadir a `ALLOWED` `'database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php'`. También falla por `routes/console.php` (OPS7). B7 puede tocar ese fichero: no lo edité.
- **H-2 · pruebas del lector del motor** (`tests/Unit/Marketing/UltronPublishedConfigTest.php`, `tests/Feature/Marketing/UltronCommitStudioStampTest.php`): siembran la cadena sin encadenar (y una con `seq` 1, 3, 2). Parche propuesto (33 líneas): el ayudante apunta a la cabeza, rellena las n−1 anteriores para la fixture del contrato (sin cambiarla) y la prueba «1, 3, 2» pasa a ids explícitos (`…aunque_no_tenga_el_mayor_id`, sigue cazando un lector que ordene por id). **Validado sobre copias: SQLite y PG 247 pruebas / 557 aserciones, igual que la línea base.** Diff en el scratchpad (`patch/motor-tests-h7.diff`). Decide el lead quién lo aplica.
- **H-3 · OPS7:** nuevas expectativas de privilegios (arriba) y la precondición `rotas = 0`. Una restauración **solo de datos** sobre el esquema creado pasa por el disparador en orden físico; la completa no.
- **H-4 · Propietario (dependencia externa):** `log_error_verbosity = terse` en `postgresql.conf` + recarga (no reinicio) y mantener `log_parameter_max_length_on_error = 0`. Refuerzo opcional para `security`: `REVOKE TEMPORARY ON DATABASE ironbody FROM PUBLIC` si nada lo usa.
- **H-5 · Tester:** carreras de dos publicaciones (siguen 23505), la carrera real de H-2 (sigue 23505 por diseño), la migración sobre una cadena existente y el 55P03.

## 8. Pendiente y no verificado
- **Corrida PG del grupo completo con mis cambios** (lista del lead + carreras H5/H6 + lector): lanzada, no concluida al cortar. Hay que relanzarla: `UNIT_DB=ultron_studio_h7_db bash docs/ultron-studio/ops/pgunit.sh` con los 19 ficheros.
- **Mutaciones** (14 mutantes + control, aislados en copias: no tocan el árbol compartido): `mut/corre.sh` en el scratchpad; sin ejecutar.
- Producción, `iron`/`iron_app` reales e `ironbody-migrate`: nada; el número de entradas de la cadena de producción y su integridad los dirá el check de OPS7 antes de migrar.

## 9. Operaciones pendientes de autorización
Ninguna ejecutada. En producción, con autorización del propietario y por devops: `h7-schema-check.sql` (antes) → `sudo /usr/local/bin/ironbody-migrate` → el mismo check (después: 44/50/4/1/1/1, `ar` en las 3 tablas). Si cae por 55P03, reintentar. `down()` nunca en producción. Y el cambio de `log_error_verbosity` (propietario).
