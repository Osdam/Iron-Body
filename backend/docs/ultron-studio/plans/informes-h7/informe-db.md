# Hito 7 · paquete DB7 · informe del `database-specialist`

**Fecha:** 01-11-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h7/Iron-Body/backend`, rama `feat/ultron-studio-h7`.

**Commits:**
- La primera parte la confirmó el lead como WIP en `982d247`.
- La segunda parte queda **sin commit** en el árbol: el parche de las pruebas del lector del motor, la lista blanca de aislamiento, la corrección de mi prueba que destapó una mutación y este informe.

**Producción:** no he tocado nada, ni siquiera para leer.

**Contrato:** `ULTRON_STUDIO_H7_CONTRACT.md`, paquete DB7 (a)–(d). Origen:
- AS-3 / N-3 y la Enmienda 2 §E2.5 del H5;
- H-2 del H6.

## 0. Resumen

- **Qué hace la migración `2026_11_02_100001`:**
  - **En la cadena de publicaciones:** el disparador `uspub_chain_next` solo admite la entrada siguiente a la cabeza y apuntando a ella. En PG lo acompaña `CHECK (seq >= 1)`; en SQLite, el disparador `uspub_seq_min`.
  - **En la cadena, el libro y las instantáneas:** `REVOKE UPDATE, DELETE … FROM iron_app`, y después se comprueba que el rol de verdad ya no puede.
  - **`down()`:** se niega con filas y nunca borra.
  - **`ultron_studio_forbid()`:** intacta (md5 `4ad748b5f17f09b398f7f641c197d7ee`).
- **Decisión clave, aceptada por el lead (D-1):** un `seq` repetido o menor que 1 no lo para el disparador, sino el UNIQUE (23505) o el CHECK (23514). Así la carrera perdida sigue siendo el 409 `publication_moved`. La mutación M04 demuestra que la prueba lo vigila.
- **H-2 del H6:** el canario duplicado se contesta con una comprobación previa, bajo el cerrojo consultivo y sin llegar al INSERT. El 23505 queda solo para la carrera real (`UltronStudioIntegrationsRaceTest`, en verde en PG).
- **Recuentos para OPS7:**
  - PG pasa de **43 a 44 disparadores** y de **49 a 50 CHECK**.
  - SQLite pasa de **30 a 32 disparadores**.
  - `iron_app` pasa de `arwd` a **`ar`** en las 3 tablas.
  - La comprobación `ops/h7-schema-check.sql` de OPS7 se ejecutó contra el modelo de roles y da todo eso.
- **Verificación final:**
  - **Grupo PG** (20 ficheros): **597 pruebas, 11 500 aserciones, 0 fallos**. Incluye las 38 carreras del H5 y las 37 del H6.
  - **SQLite**, Studio entero más el lector del motor: **2750 pruebas, 27 275 aserciones, 0 fallos y 106 omitidas** (las solo-PG, que lo dicen).
- **Mutaciones:** **14 de 14 mueren**, cada una en el motor donde existe su camino; el control pasa en los dos.
- **Para el propietario:** `log_error_verbosity = terse` (§9).

## 1. Motor, versión y entorno

| | Versión | Dónde |
|---|---|---|
| PostgreSQL | 14.24 (Homebrew). Producción, 14.24 según los informes H5/H6 (no lo he comprobado) | Clúster desechable `127.0.0.1:55432`. Bases mías: `ultron_studio_h7_db` (pgunit), `ultron_studio_h7_db_roles` (modelo dueño/app) y `ultron_studio_h7_db_mut` (mutaciones) |
| SQLite | 3.54.1 (PDO de PHP 8.3.33) | `:memory:` en la suite; ficheros en el scratchpad de la sesión para `migrate`/`rollback`/`fresh` |
| Laravel / PHPUnit | 12 / 11.5.55 | Sin `.env`: mandan las variables `DB_*` del entorno |

Estado final de las bases:
- `ultron_studio_h7_db_roles`: H7 aplicado, con `h5_app` en `ar`.
- Las otras dos quedan como las deja pgunit.

## 2. Ficheros

| Fichero | Líneas | Qué |
|---|---|---|
| `database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php` (nuevo) | 442 | Cabecera (1–119); constantes (120–156); `up()` 158; `down()` 179; `message()` 198; `appRole()` 209; `upPostgres()` 220 (función 231, disparador 252); `downPostgres()` 258; `upSqlite()` 280 (disparadores 286 y 289); `downSqlite()` 295; `revokeAppWrites()` 310; `restoreAppWrites()` 356; `refuseBrokenChain()` 389; `refuseWithRows()` 419 |
| `app/Services/UltronStudio/StudioCanaryNumbers.php` | +10 (201–210) | Comprobación previa en `registerGated()`, tras el tope de vivos y bajo `pg_advisory_xact_lock` |
| `tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php` (nuevo) | 790 | 16 métodos, 25 casos (§6.2) |
| `tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php` | +30/−15 | Recuentos 44/50/32; listas de la cadena con las guardas H7; `H7_CHAIN_GUARDS`; `assertH5SchemaPresent()` mira solo las del H5 (el `down()/up()` del H5 recrea la tabla sin las del H7); `publicationRow()` encadena con la cabeza |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php` | +20/−13 | Constantes con el H7 (31/44, 28/50, 22/32) y sus mensajes |
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | +8 | **Por decisión del lead:** entran en `ALLOWED` mi migración y `routes/console.php` (OPS7: bandera `ultron_studio.monitor.enabled`), con su párrafo en la cabecera |
| `tests/Unit/Marketing/UltronPublishedConfigTest.php` | +26/−5 | **Por decisión del lead:** el ayudante apunta a la cabeza y rellena las n−1 anteriores (`rellenaHasta()`). La prueba «1, 3, 2» pasa a ser `…_aunque_no_tenga_el_mayor_id`, con ids explícitos |
| `tests/Feature/Marketing/UltronCommitStudioStampTest.php` | +2/−1 | **Por decisión del lead:** `previous_publication_id` = la cabeza |
| `docs/ultron-studio/plans/informes-h7/informe-db.md` | — | Este informe |

`vendor/bin/pint --test` sobre los 8 PHP: `passed`. No he tocado `ops/` (de OPS7) ni ningún fichero del motor.

## 3. Guardas finales

**PostgreSQL:**
- **`uspub_chain_next`:** `BEFORE INSERT ON public.ultron_studio_publications FOR EACH ROW EXECUTE FUNCTION ultron_studio_publication_chain('una entrada nueva de la cadena tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id)')`.
- **La función `ultron_studio_publication_chain()`:** plpgsql, VOLATILE e INVOKER (sin SECURITY DEFINER); su dueño es el rol que migra. Hace lo siguiente:
  - lee la cabeza con `SELECT p.id, p.seq … FROM public.ultron_studio_publications AS p ORDER BY p.seq DESC LIMIT 1`, por el índice único;
  - rechaza con P0001 si `NEW.seq > cabeza` y además `NEW.seq <> cabeza + 1` o `previous_publication_id IS DISTINCT FROM` la cabeza;
  - hace el cálculo en `bigint`, sin desbordamiento.
- **`uspub_seq_chk`:** `CHECK ((seq >= 1))`.
- **Privilegios:** `REVOKE UPDATE, DELETE ON ultron_studio_publications, ultron_studio_audit_events, ultron_studio_integration_snapshots FROM "iron_app"`. Después se comprueba con `has_any_column_privilege(…, 'UPDATE')` y `has_table_privilege(…, 'DELETE')`, que ven también lo heredado de otro rol o de PUBLIC.

**SQLite:**
- `uspub_chain_next`: la misma regla, con `IS NOT` nulo-seguro.
- `uspub_seq_min`: `WHEN (NEW.seq < 1)`.
- El texto del error es el mismo en los dos motores: «ultron_studio: … (INSERT en ultron_studio_publications)»; en SQLite sale con el código 23000.

**Concurrencia, en READ COMMITTED:**
- La función ve la cabeza confirmada en el momento de la inserción.
- Dos escrituras que leyeron la misma cabeza pasan las dos el disparador y chocan en el UNIQUE (23505), igual que antes.
- Una fila por encima de la cabeza confirmada + 1 no entra nunca.
- El disparador no toma cerrojos propios.

## 4. Recuentos antes y después (para OPS7)

| | Antes (H6) | Con H7 | Tras `rollback --step=1` |
|---|---|---|---|
| PG · disparadores `ultron_studio_%` | **43** | **44** | 43 |
| PG · CHECK `ultron_studio_%` | **49** | **50** | 49 |
| PG · disparadores / CHECK `uspub_%` | 3 / 3 | 4 / 4 | 3 / 3 |
| PG · `ultron_studio_publication_chain()` | 0 | 1 (dueño = el rol que migra) | 0 |
| PG · md5 de `ultron_studio_forbid()` | `4ad748b5…c197d7ee` | el mismo | el mismo |
| SQLite · disparadores `ultron_studio_%` | **30** | **32** | 30 |
| Tablas / índices parciales del Studio | 14 / 4 | 14 / 4 | 14 / 4 |
| `iron_app` en cadena, libro e instantáneas | `arwd` | **`ar`** (y `USAGE` en la secuencia) | `arwd` |
| `iron_app` en canarios y asociaciones | `arwd` | `arwd` (su máquina de estados usa UPDATE) | `arwd` |

**La comprobación de OPS7** (`docs/ultron-studio/ops/h7-schema-check.sql`, suya):
- La ejecuté de solo lectura contra `ultron_studio_h7_db_roles` (H7 aplicado, `-v owner=h5_owner -v app=h5_app`) y salió con exit 0.
- Da, uno a uno, los valores esperados:
  - `disparadores_studio=44`, `check_studio=50`, `disparadores_uspub=4`;
  - `disparadores_cadena=uspub_chain_next:O,…`, `disparadores_apagados=0`;
  - `check_seq_def=CHECK ((seq >= 1))`, `funcion_cadena_definer=f`, `funcion_es_la_de_f=t`;
  - `rotas=0`;
  - privilegios: `f/f` en las 3 tablas y `t/t` en las otras 2.
- Mi propia versión validó también el «antes» (43/49/3, todo `t`).
- La migración **se niega si la cadena existente no es consecutiva y encadenada**. El check de OPS7 lo mide como `rotas`, y tiene que dar 0 antes de migrar.

## 5. Decisiones fuera del contrato

| # | Decisión | Por qué y evidencia |
|---|---|---|
| **D-1 (aceptada por el lead)** | El disparador solo rechaza lo que está por encima de la cabeza y no es la siguiente encadenada. Lo que no pasa de la cabeza lo deciden el UNIQUE (23505) y el CHECK (23514) | Si lo rechazara el disparador, la carrera perdida sería P0001 → 500, en vez de 23505 → 409 `publication_moved` (contrato H5 §A5, paso 12). El mutante M04 («rechazar todo lo que no sea la siguiente») lo cazan las pruebas de 23505 y 23514 |
| D-2 | Función propia con la tabla cualificada (`public.`) | Sin el esquema, una tabla temporal con el mismo nombre (pg_temp se busca antes que public, y el rol de la aplicación tiene TEMP) falsea la cabeza. Probado como `h5_app` real → P0001; el mutante M01 lo caza |
| D-3 | `up()` se niega si la cadena existente está rota, con un recuento y sin tocar filas | El disparador solo mira hacia delante. Repararla es del rol dueño con la autorización del propietario (runbook H5, AS-3/N-3). Probado con 5 cadenas rotas en los dos motores (M09) |
| D-4 | El rol es `iron_app`, salvo que `ULTRON_STUDIO_DB_APP_ROLE` venga en el entorno del proceso que migra (el banco usa `h5_app`). Comprobación posterior y guarda de producción | Sin el rol, local y la suite siguen; con `APP_ENV=production`, la migración falla y no se registra. Si el rol hereda UPDATE/DELETE de otro rol o de PUBLIC, también falla (M06, M07 y M08) |
| D-5 | `down()` se niega con filas en cualquiera de las 3 tablas. Devuelve UPDATE/DELETE solo donde el rol conserva INSERT | Fiel al encargo. El libro tiene filas en cualquier entorno real, así que en la práctica `down()` solo sirve en bases limpias (M10 y M11) |
| D-6 | Una sola migración | El REVOKE no toma cerrojos, así que separarla no aporta nada y abriría un estado a medias (R-1 del H6) |
| D-7 | Comprobación previa del canario, detrás del tope de vivos y con el mismo predicado que `uscn_one_live` | Con el tope lleno y el teléfono duplicado gana `canary_live_limit`, como antes (M14) |
| D-8 | `LOCK TABLE … ACCESS EXCLUSIVE` explícito y al principio | Comprobar la cadena e instalar las guardas sin que nadie inserte en medio, y sin subir de cerrojo a mitad (no hay interbloqueo por subida) |

## 6. Qué verifiqué ejecutando

### 6.1 Línea base, antes de tocar nada

| Comando | Resultado |
|---|---|
| SQLite: `tests/Feature/UltronStudio tests/Unit/UltronStudio` + lector del motor | 2702 pruebas, 26 776 aserciones, 102 omitidas, verde |
| `pgunit.sh` sobre los 18 ficheros del grupo | 565 pruebas, 10 135 aserciones, verde |

### 6.2 Prueba H7

`UltronStudioHardeningH7Test`: **PG 25 pruebas / 250 aserciones**; SQLite 25 / 126, con 4 omitidas (solo PG).

Qué cubre:
- recuentos y nombres;
- la siguiente encadenada pasa;
- 7 casos de `seq` saltado o sin encadenar → P0001 (23000 en SQLite), incluido `seq = 2147483647`;
- `seq` 0 y −1 → 23514 (`uspub_seq_chk`);
- `seq` repetido → 23505 `…_seq_unique` (23000 «UNIQUE constraint failed» en SQLite);
- la tabla temporal suplantadora → P0001;
- en PG, con un rol de prueba que se crea y se deshace dentro de la transacción:
  - inserta;
  - UPDATE, DELETE, `FOR UPDATE`, `FOR SHARE` y TRUNCATE en las 3 tablas → 42501;
  - tocar el disparador, el CHECK o la función → 42501;
  - canarios y asociaciones siguen admitiendo UPDATE;
  - `down()` devuelve UPDATE/DELETE y `up()` los vuelve a quitar;
- la comprobación posterior (UPDATE concedido a PUBLIC → falla sin dejar nada);
- la guarda de producción;
- `up()` se niega con 5 cadenas rotas sin crear nada;
- `down()` se niega con una fila en cada tabla;
- la ida y vuelta deja la tabla y las guardas iguales;
- el canario duplicado, sin INSERT y con la comprobación detrás del cerrojo;
- con el tope de vivos lleno, gana el tope.

Las 3 pruebas de esquema juntas (H5, H6 y H7): **PG 60 / 885**; SQLite 60 / 545, con 12 omitidas.

### 6.3 Grupo PG final

```text
UNIT_DB=ultron_studio_h7_db bash docs/ultron-studio/ops/pgunit.sh <los 20 ficheros>   →  OK (597 tests, 11500 assertions)
```

Por clase, todas en verde:

| Clase | Pruebas | Aserciones |
|---|---|---|
| H7 | 25 | 250 |
| PublicationSchema | 13 | 162 |
| IntegrationsSchema | 22 | 473 |
| PublicationLifecycle | 74 | 732 |
| **IntegrationsRace** | 37 | 1079 |
| IntegrationsCanary | 16 | 292 |
| **PublicationRace (las 38 carreras del H5)** | 38 | 2763 |
| PublicationRollback | 23 | 369 |
| PublicationCanary | 12 | 227 |
| PublicationConfig | 18 | 92 |
| PublicationAccess | 6 | 225 |
| EngineReadOnly | 6 | 2316 |
| EngineReadings | 9 | 94 |
| VersionLifecycle | 22 | 338 |
| GuardsAdversarial | 4 | 243 |
| Immutability | 9 | 98 |
| Audit | 9 | 84 |
| EngineIsolation | 7 (8 desde m-3, `601398c`) | 1106 (1123 desde m-3) |
| **UltronPublishedConfigTest** | 217 | 331 |
| **UltronCommitStudioStampTest** | 30 | 226 |

- El lector del motor da las mismas 247 pruebas y 557 aserciones que la línea base.
- Esa corrida arrancó antes de que corrigiera mi prueba (§7). La versión corregida se volvió a pasar sobre la misma base: **OK (25 tests, 250 assertions)**.
- Antes del parche, la corrida intermedia daba 590 / 10 281 con **35 errores**, todos del lector del motor (29 + 6) y todos por el disparador.

### 6.4 SQLite final

```text
php vendor/bin/phpunit tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Unit/Marketing/UltronPublishedConfigTest.php tests/Feature/Marketing/UltronCommitStudioStampTest.php
  →  OK, but some tests were skipped! Tests: 2750, Assertions: 27275, Skipped: 106.
```

La corrida intermedia, antes del parche, daba 2749 con 36 rojos (los 35 del lector y el de aislamiento). La prueba de más es de otro paquete que trabaja en el mismo árbol.

### 6.5 SQLite con base de fichero

| Paso | Resultado |
|---|---|
| `migrate` sobre la base H6 | Solo +2 líneas en `sqlite_master` (los dos disparadores). **La tabla no se reconstruye** |
| `migrate:rollback --step=1` (11,67 ms) → `migrate` (2,99 ms) | `sqlite_master` **IDÉNTICO** |
| `migrate:fresh`, dos veces | 326 migraciones, **IDÉNTICO** al incremental |
| `down()` con 1 fila en cada tabla | exit 1, el mensaje con el recuento, esquema idéntico, la migración sigue `Ran` |

### 6.6 PostgreSQL

- `pg_dump -s` de la base al H7 (quitando la clave aleatoria `\restrict` de pg_dump): **37 líneas añadidas y 1 «cambiada»**, que es solo la coma de `uspub_reason_chk`. `rollback` → `migrate` deja el volcado **IDÉNTICO**.
- `--pretend` del `up` (5 sentencias): `LOCK`, el CHECK, la función, el disparador y `REVOKE … FROM "iron_app"`.
- `--pretend` del `down`: `LOCK` de las 3 tablas, los GRANT de vuelta y los DROP. No aplica nada.
- En ninguno de los dos sentidos se nombra ninguna tabla del motor.

### 6.7 Modelo dueño/app (`ultron_studio_h7_db_roles`)

- **Montaje:** `REVOKE CREATE ON SCHEMA public FROM PUBLIC`. `h5_owner` sin superusuario. `h5_app` con privilegios por defecto `arwd` (tablas) y `rwU` (secuencias), como el banco del H6.
- **Migraciones:** `h5_owner` migró el H6 completo (325 migraciones, 0 FAIL) y después el H7 con `ULTRON_STUDIO_DB_APP_ROLE=h5_app`, **sin ningún GRANT**.
- **Vuelta atrás:** `rollback` deja el `pg_dump -s` **IDÉNTICO a la base H6, con las ACL**. Un nuevo `migrate` lo deja idéntico al primer H7. El diff de ACL son 3 líneas que pasan de `SELECT,INSERT,DELETE,UPDATE` a `SELECT,INSERT`.
- **Conectado de verdad como `h5_app`** (`rolsuper = f`), todo dentro de una transacción que se revierte:

  | Intento | Resultado |
  |---|---|
  | Insertar la cadena encadenada (su FK propia la comprueba el dueño), el libro y la instantánea | Pasa |
  | `seq` 2147483647 | P0001 |
  | Tabla temporal suplantadora | P0001 |
  | UPDATE, DELETE, `FOR UPDATE` y TRUNCATE en las 3 tablas | 42501 |
  | `DISABLE TRIGGER`, `DROP TRIGGER`, `DROP CONSTRAINT`, `CREATE OR REPLACE FUNCTION`, `SET session_replication_role` | 42501 |
  | `GRANT UPDATE` a sí mismo | WARNING 01007, sin efecto |
  | UPDATE en canarios | Pasa |

### 6.8 `lock_timeout` de 5 s (`PGOPTIONS`, como `ironbody-migrate`)

- **Con un lector de la cadena en transacción abierta:** la migración cae a los **5,58 s** con 55P03. Queda `Pending` y sin nada aplicado (43/49, sin función, privilegios intactos); al ser una sola migración no hay estado a medias. El reintento pasa.
- **Con escritores abiertos en el libro y las instantáneas** (RowExclusive + AccessShare): migra en **16 ms** sin esperarlos. `pg_locks` confirma que el REVOKE no toma cerrojo de tabla; solo el `LOCK` explícito lo toma, y solo sobre la cadena.

### 6.9 Guarda de producción

Con `APP_ENV=production` y sin `iron_app`: exit 1 con «el rol de la aplicación «iron_app» no existe en este clúster y APP_ENV es production…», `Pending` y nada aplicado. En local sin el rol pasa sin REVOKE.

### 6.10 H-2 en el log real del servidor (clúster local)

- Con `log_error_verbosity` por defecto, un 23505 en `uscn_one_live` deja `DETAIL: Key (channel, phone_hash)=(whatsapp, <huella de 64 hex>) already exists.`.
- Un 23514 en canarios deja `DETAIL: Failing row contains (…)` con la fila entera: alias, huella, últimos 4 dígitos y, en voz, el cifrado.
- Con `terse` no hay `DETAIL`.
- Con Laravel, la línea `STATEMENT` lleva `$1, $2…` y no los valores (comprobado en el log), y `log_parameter_max_length_on_error = 0`.

## 7. Mutaciones

Todos los mutantes son copias en el scratchpad que la prueba carga en su propio proceso: la migración, por ruta; el servicio, con un `require_once` antes del autoload. Corren contra una base PG aparte (`ultron_studio_h7_db_mut`). **No muté nada del árbol compartido.**

| Mutante | SQLite | PG | Lo caza |
|---|---|---|---|
| M00 control (migración real) | OK 25/126 (4 omitidas) | OK 25/250 | — |
| M01 función sin `public.` | sobrevive (no existe allí) | **muere** (2) | Recuentos (`prosrc`) y la tabla temporal |
| M02 PG sin encadenar | sobrevive (no existe) | **muere** (4) | Casos sin encadenar |
| M03 SQLite sin encadenar | **muere** (4) | sobrevive (no existe) | Casos sin encadenar |
| M04 PG rechaza todo lo que no sea la siguiente | sobrevive (no existe) | **muere** (2) | 23505 y 23514 (**D-1**) |
| M05 SQLite rechaza todo lo que no sea la siguiente | **muere** (1) | sobrevive (no existe) | 23000 UNIQUE |
| M06 sin REVOKE | sobrevive (no existe) | **muere** (2) | Rol y comprobación posterior |
| M07 sin comprobación posterior | sobrevive (no existe) | **muere** (1) | PUBLIC con UPDATE |
| M08 sin guarda de producción | sobrevive (no existe) | **muere** (1) | `APP_ENV=production` |
| M09 no se niega con la cadena rota | **muere** (5) | **muere** (5) | Las 5 cadenas rotas |
| M10 `down()` no se niega | **muere** (1) | **muere** (1) | Fila en cada tabla |
| M11 `down()` no devuelve privilegios | sobrevive (no existe) | **muere** (1) | Ida y vuelta del rol |
| M12 SQLite sin `uspub_seq_min` | **muere** (1) | sobrevive (no existe) | `seq` 0 / −1 |
| M13 sin comprobación previa | **muere** (1) | **muere** (1) | INSERT intentado |
| M14 comprobación antes del tope | **muere** (1) | **muere** (1) | Gana el tope |

**14 de 14 mueren** en el motor donde existe su camino.

**Lo que destapó la primera pasada:** M13 sobrevivía en SQLite, y era una debilidad de mi prueba. `DB::listen` solo registra las sentencias que terminan bien, y el INSERT duplicado falla en la base, así que «sin INSERT» no podía fallar nunca. Lo cambié a `beforeExecuting`, que registra también los intentos (como la prueba de canarios del H6). Ahora M13 muere en los dos motores.

## 8. Lo aplicado por decisión del lead, fuera de mis ficheros exclusivos

**`UltronEngineIsolationTest`:**
- Entran en `ALLOWED` `database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php` y `routes/console.php`. Eran los dos únicos ficheros que marcaba; el segundo, solo por `'ultron_studio.monitor.enabled'`, en la línea 390.
- Ninguno de los dos es zona del motor.
- Resultado: 7 pruebas, 1106 aserciones, en verde en los dos motores. *(Desde el m-3 del reviewer del H7, `601398c` de `security`: 8 pruebas y 1123 aserciones, con la excepción por cadena de `ultron_studio.monitor.enabled` y una prueba del bloque del monitor.)*
- **Propuesta opcional:** una excepción más estrecha para `routes/console.php`, que solo admita esa cadena (como el lector del motor), sería más estricta que permitir todo el fichero.

**Pruebas del lector del motor:**
- Es el parche validado antes sobre copias: tras aplicarlo, los ficheros del árbol son idénticos byte a byte a esas copias.
- Lo que cambia:
  - el ayudante apunta a la cabeza;
  - para sembrar una cabeza con `seq` n, rellena antes las n−1 entradas con Legacy de relleno (el lector solo lee la cabeza, y la fixture del contrato no cambia);
  - «1, 3, 2» pasa a `test_la_cabeza_es_la_de_mayor_seq_aunque_no_tenga_el_mayor_id` (ids 900/800/100), que sigue cazando un lector que ordene por id.
- Mismas 247 pruebas y 557 aserciones que la línea base, en los dos motores.
- No se toca ninguna aserción sobre el comportamiento del motor ni ningún fichero de `app/Services/Marketing`.

## 9. Nota para el propietario: `log_error_verbosity = terse`

- **Qué y por qué:** con el valor por defecto, PostgreSQL escribe en su log el `DETAIL` de cada error. Con las tablas del H6 eso incluye la huella del teléfono, en el 23505 de `uscn_one_live`, y la fila entera, en cualquier 23514 o 23502 de canarios: alias, últimos 4 dígitos y el cifrado de voz. Lo demostré en §6.10. Desde el H7 la aplicación ya no provoca el 23505 en el alta normal, pero una carrera real, un CHECK o una consulta a mano sí pueden provocarlo. `terse` quita `DETAIL`, `HINT`, `QUERY` y `CONTEXT` del log del servidor.
- **Cómo** (como `postgres`, **sin reinicio**; el parámetro es de contexto `superuser`):

  ```sql
  ALTER SYSTEM SET log_error_verbosity = 'terse';
  SELECT pg_reload_conf();
  SHOW log_error_verbosity;               -- terse
  SHOW log_parameter_max_length_on_error; -- 0 (el valor por defecto: los parámetros no se registran al fallar)
  ```

  Equivale a poner `log_error_verbosity = terse` en `postgresql.conf` y recargar.
- **Vuelta atrás:** `ALTER SYSTEM RESET log_error_verbosity; SELECT pg_reload_conf();`.
- **Coste:** se pierde el `DETAIL` al diagnosticar errores en el log del servidor. Laravel sigue registrando la clase y el SQLSTATE.
- **Cuándo:** el H6 no está desplegado, así que todavía no hay filas canarias en producción: lo mejor es aplicarlo **antes** de las fases del H6 y del H7. Queda como dependencia externa.
- **Variante más agresiva:** `log_min_error_statement = panic`, que quita también la línea `STATEMENT`. No hace falta, porque con Laravel ya lleva solo `$1, $2…`.

## 10. Impacto, riesgos y reversibilidad

**Impacto:**
- **Mejora:** la cadena ya no se puede envenenar con DML del rol de la aplicación (AS-3/N-3 cerrado).
- **Cadena, libro e instantáneas:** la aplicación pierde UPDATE y DELETE en ellas.
- **El duplicado del canario** ya no llega al log del servidor.
- **Coste:** una lectura de una fila por índice en cada inserción de la cadena (decenas de filas). No he medido planes; sobre una tabla de este tamaño no demuestran nada.
- **Otras consultas:** ninguna del motor cambia. El lector sigue con su SELECT.

**Riesgos:**
- **R-1 · Cerrojo:** ACCESS EXCLUSIVE sobre la cadena durante unos milisegundos. Con un lector abierto, la migración cae limpia con 55P03 y se reintenta (§6.8). Si la llave del motor no está en `off`, sus lecturas de la cabeza hacen cola mientras tanto, hasta 5 s como mucho.
- **R-2 · Cadena rota en producción:** la migración se niega. Mirar `rotas` en el check de OPS7 antes de migrar.
- **R-3 · Sin `iron_app` en producción:** la migración se niega (D-4).
- **R-4 · Restauración solo de datos** (`pg_restore --data-only`) sobre el esquema ya creado: pasa por el disparador en el orden físico del volcado, que podría no ser el de `seq` si VACUUM reutilizó huecos. La restauración completa (esquema + datos) crea los disparadores después de los datos y no le afecta. Es una nota para OPS7 (respaldos).
- **R-5 · Usos futuros:** cualquier `UPDATE`, `DELETE`, `SELECT … FOR UPDATE/SHARE` u `ON CONFLICT DO UPDATE` sobre esas 3 tablas desde la aplicación será 42501. Es a propósito.

**Vuelta atrás:**
- **Nivel 3** (código del H6): no exige revertir el esquema. El disparador solo rechaza lo que el código nunca hace, y el REVOKE solo quita privilegios que ningún código usa.
- **Nivel 4 (`down()`): nunca en producción.** Fuera de ella se niega con filas.
- **Duración:** de 5 a 60 ms en local, con la máquina descargada.

## 11. Hallazgos para otros dueños

- **OPS7:**
  - su `h7-schema-check.sql` está validado (§4);
  - en el banco, `ULTRON_STUDIO_DB_APP_ROLE=h5_app` tiene que ir en el entorno del `migrate`: sin él no se revoca, porque `iron_app` no existe allí y el banco usa `APP_ENV=local`;
  - R-4 para la política de respaldos.
- **TEST7**, casos que solo se ven desde la base:
  - dos publicaciones a la vez (siguen dando 23505 → 409);
  - el envenenamiento de `seq` con DML, incluso en carrera;
  - la carrera real de H-2 (sigue dando 23505, por diseño);
  - la migración sobre una cadena existente;
  - el 55P03 del despliegue;
  - la ida y vuelta con ACL.
- **`security`:**
  - lección general: una función INVOKER que lee tablas sin cualificar es vulnerable a tablas temporales con el mismo nombre;
  - refuerzo opcional de defensa en profundidad: `REVOKE TEMPORARY ON DATABASE ironbody FROM PUBLIC`, si nada lo usa (decide el propietario).

## 12. Operaciones pendientes de autorización

No he ejecutado ninguna. Por orden, todas en producción y por devops/propietario con autorización:

1. `h7-schema-check.sql` (antes). Esperado: 43/49/3, `rotas` 0, `existe_rol_app` 1 y 0 cerrojos sobre la cadena.
2. `sudo /usr/local/bin/ironbody-migrate`: la migración `2026_11_02_100001`. Si cae por 55P03, reintentar.
3. El mismo check (después). Esperado: 44/50/4, `ar` en las 3 tablas y la huella de F igual.
4. Solo el propietario: `ALTER SYSTEM SET log_error_verbosity = 'terse'; SELECT pg_reload_conf();`.

`down()` nunca se ejecuta en producción.

## 13. Qué no verifiqué, y por qué

- **Producción:** nada. Los roles reales `iron`/`iron_app`, `ironbody-migrate` y su `PGOPTIONS`, y el número real de entradas de la cadena: lo dirá el check antes de migrar.
- **Los planes de ejecución:** tablas diminutas; un EXPLAIN aquí no demuestra nada.
- **La suite completa del backend:** fuera de lo pedido; es de TEST7. Ejecuté el Studio entero y el lector del motor en SQLite, y el grupo de 20 ficheros en PG.
- **El banco de OPS7:** no lo he ejecutado.

## 14. Reproducir

```bash
cd Iron-Body/backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php                      # SQLite
UNIT_DB=ultron_studio_h7_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php  # PG
# Modelo de roles (como el banco): migrar como h5_owner revocando a h5_app
env ULTRON_STUDIO_DB_APP_ROLE=h5_app APP_ENV=local DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_h7_db_roles \
    DB_USERNAME=h5_owner DB_PASSWORD= DB_URL= PGOPTIONS='-c lock_timeout=5s' php artisan migrate --force
```
