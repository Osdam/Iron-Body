# Hito 6 · paquete DB · informe del `database-specialist`: tablas de integraciones y sus guardas

**Fecha:** 31-10-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h6/Iron-Body/backend`, rama `feat/ultron-studio-h6`. Mis tres ficheros los confirmó el lead como WIP en `01ad03e`; después de ese commit no los he vuelto a cambiar. **Yo no hago commit.** **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md` §I3.1–I3.2, §I4.1–I4.2, §I5.3, §I8 e I13, y la memoria «SQLite reconstruye y pierde el WHERE». **Producción: no he tocado nada, ni siquiera para leer.**

## 0. Resumen

- Dos migraciones y su prueba, verificadas en **SQLite 3.54.1** (la del PDO de PHP 8.3.33) y en **PostgreSQL 14.24**, la misma versión menor que producción.
- **Recuentos del Studio:**
  - PG pasa de **30 a 43 disparadores** y de **27 a 49 CHECK**.
  - SQLite pasa de **20 a 30 disparadores**.
  - Hay **4 índices parciales** (antes 2) y **14 tablas** `ultron_studio_*` (antes 11).
  - `ultron_studio_forbid()` no cambia ni un byte: el md5 de `pg_get_functiondef` es `4ad748b5f17f09b398f7f641c197d7ee` antes y después, el mismo que midió el H5.
- **El libro.** En PG, `usae_subject_type_chk` pasa a 7 sujetos con una sola sentencia; es la **única línea** del `pg_dump -s` que cambia, y todo lo demás son altas. En SQLite ese CHECK no existe (F solo lo crea en PG), así que **la tabla del libro no se toca**: ni se reconstruye ni pierde nada. Lo demuestran el `diff` de `sqlite_master` y la prueba.
- **Ida y vuelta.** `migrate:rollback --step=2` deja el esquema **idéntico** a la base y `migrate` lo deja idéntico al primero, en los dos motores (`pg_dump -s` en PG, `sqlite_master` completo en SQLite). `down()` **se niega** con una fila en cualquiera de las 3 tablas o con un evento del libro de un sujeto nuevo: sale con código 1, no deshace nada y las dos migraciones siguen `Ran`.
- **Prueba de la migración** `UltronStudioIntegrationsSchemaTest`:
  - SQLite: 22 pruebas, 321 aserciones y 5 omitidas, que son solo de PG y lo dicen.
  - **PG: 22 pruebas, 473 aserciones, todo verde.**
- **Roles.** Con un dueño sin superusuario y una aplicación con solo DML, las migraciones van **sin ningún GRANT**, y la aplicación no puede saltarse ninguna guarda (todo da 42501).
- **`lock_timeout` 5 s.** Con un **lector** del libro con una transacción abierta, la 100002 cae limpia a los 5,36 s (55P03), pero **deja las 3 tablas sin guardas hasta que se reintenta** (§9).
- **Dos decisiones fuera del contrato**, que el lead tiene que confirmar (§6):
  - **D-1:** con las guardas literales de §I3.2, **la sustitución de una asociación (§I3.4, paso 11) es imposible en los dos motores**. Está demostrado, y la corrijo con el cambio mínimo.
  - **D-2:** `usis_outcome_chk` dejaba pasar una sonda con `outcome` NULL.
- **Dos efectos esperados en ficheros de otros**, que no he tocado (§10):
  - `UltronStudioPublicationSchemaTest` (del H5) falla **solo** por sus recuentos globales fijos. Dejo el parche exacto, **validado en los dos motores** sobre una copia.
  - `UltronEngineIsolationTest` falla **solo** porque mis dos migraciones no están en su lista blanca (§I9.1, paquete S).

## 1. Motor, versión y entorno

| | Versión | Dónde |
|---|---|---|
| PostgreSQL | 14.24 (Homebrew) | Clúster desechable `127.0.0.1:55432`. **Solo la base `ultron_studio_h6_db`**, que creé con `createdb` |
| SQLite | 3.54.1 (PDO de PHP 8.3.33) | `:memory:` en la suite; ficheros en mi scratchpad para `migrate`/`rollback`/`fresh` |
| Laravel / PHPUnit | 12.58.0 / 11.5.55 | Sin `.env` ni `bootstrap/cache/config.php` en el árbol: mandan las variables `DB_*` del entorno |

Estado final de `ultron_studio_h6_db`:
- con el H6 aplicado, propiedad del rol local `h5_owner` (sin superusuario);
- `CREATE` en `public` revocado a `PUBLIC` (el modelo de producción, §7.4);
- `pgunit.sh` sigue pasando sobre ella como `ironbody` (22/473, la última ejecución).

## 2. Ficheros (los únicos que he tocado)

| Fichero | Líneas | Qué |
|---|---|---|
| `database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php` | 271 | Cabecera, decisiones y cerrojos (1–81). `up()` (90–210): `campaign_links` (92–129), `canary_numbers` (131–177) y `integration_snapshots` (179–209). `down()` (212) con `lockForDown()` (234) y `refuseWithH6Rows()` (255) |
| `database/migrations/2026_11_01_100002_add_ultron_studio_integration_guards.php` | 518 | Cabecera (1–119). Listas de columnas (133–165). Disparadores (167–196). CHECK (199–243). Parciales (246). Sujetos (254–257). `upPostgres()` (311; la función de F en 330 y el libro, **lo último**, en 354). `downPostgres()` (357), `upSqlite()` (386), `downSqlite()` (400). `assertColumnsClassified()` (419), `replaceSubjectCheck()` (454), `createPartialUniqueIndexes()` (462) y `refuseWithH6Rows()` (474) |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php` | 1272 | 22 pruebas (desde la 248). Lo esperado sale del **contrato**, no de las constantes de la migración |
| `docs/ultron-studio/plans/informes-h6/informe-db.md` | — | Este informe |

`vendor/bin/pint --test` sobre los tres PHP: `{"result":"passed"}`. No he añadido dependencias ni tocado nada del motor, de las integraciones existentes ni de `ultron_studio_versions`.

## 3. Las tres tablas (columnas finales)

Las columnas, el orden, los tipos y la nulabilidad son **exactamente** los de §I3.1, §I4.1 y §I5.3. La prueba lo fija columna a columna en los dos motores. Tipos en PG (`Schema::getColumns`):

- **`ultron_studio_campaign_links`** (20 columnas):
  - `id` bigint, `uuid` uuid, `client_request_id` uuid;
  - `ad_account_id` y `campaign_id` varchar(64); `campaign_name_at_link` varchar(255)?; `target_kind` varchar(10);
  - `version_id` bigint? (FK → versiones, RESTRICT); `version_content_hash` char(64)?; `status` varchar(10);
  - `created_by_admin_id` bigint, `created_by_name` varchar(120), `created_by_role` varchar(60)?, `created_at` timestamp(0);
  - `deactivated_at` timestamp?, `deactivated_by_admin_id` bigint?, `deactivated_by_name` varchar(120)?, `deactivated_by_role` varchar(60)?, `deactivation_reason` varchar(500)?;
  - `replaced_by_link_id` bigint? (FK propia, RESTRICT).
  - Sin `updated_at`.
- **`ultron_studio_canary_numbers`** (31 columnas):
  - `id`, `uuid`, `client_request_id`;
  - `channel` varchar(10), `alias` varchar(40), `phone_hash` char(64), `phone_last4` char(4), `country_code` varchar(4);
  - **`phone_encrypted` text?**, `status` varchar(12), `expected_version_id` bigint? (FK → versiones, RESTRICT), `consent_attested` boolean;
  - las instantáneas `registered_by_*`, `authorized_by_*` + `authorized_at`, `activated_by_*` + `activated_at`, `revoked_by_*` + `revoked_at` + `revoke_reason` varchar(500)?;
  - `status_changed_at` timestamp (NOT NULL);
  - `created_at` y `updated_at` NULL-ables, por ser `timestamps()` como dice el contrato.
- **`ultron_studio_integration_snapshots`** (14 columnas):
  - `id`, `uuid`, `client_request_id` uuid? (UNIQUE: varios NULL caben);
  - `provider` varchar(16), `kind` varchar(10), `fingerprint` char(64), `facts` **json**, `outcome` varchar(16)?;
  - `actor_type` varchar(10), `actor_admin_id` bigint?, `actor_name` varchar(120), `actor_role` varchar(60)?, `note` varchar(500)?;
  - `created_at` timestamp (NOT NULL).
  - Sin FK.

En SQLite: `char`/`uuid` → varchar, `boolean` → tinyint(1), `json` → text y `timestamp` → datetime, como en el resto del Studio.

**Índices** (sin contar la clave primaria):
- `campaign_links`: `…_uuid_unique`, `…_client_request_id_unique`, `uscl_campaign_idx` (ad_account_id, campaign_id, id) y **`uscl_one_active`** (ad_account_id, campaign_id) `WHERE status = 'active'`.
- `canary_numbers`: los dos UNIQUE, `uscn_status_idx` (status, channel) y **`uscn_one_live`** (channel, phone_hash) `WHERE status <> 'revoked'`.
- `integration_snapshots`: los dos UNIQUE y `usis_provider_idx` (provider, kind, id).

## 4. Guardas por tabla y motor

En PG, el mensaje de los disparadores es `ultron_studio: <motivo> (<op> en <tabla>)` con P0001. En SQLite es el mismo texto con 23000. Un CHECK violado da 23514.

| Tabla | Disparador (los dos motores salvo TRUNCATE) | Definición |
|---|---|---|
| `campaign_links` | `uscl_forbid_delete` | `BEFORE DELETE` · «las asociaciones de campaña no se borran» (texto del contrato) |
| | `uscl_forbid_truncate` (PG) | `BEFORE TRUNCATE … FOR EACH STATEMENT` |
| | `uscl_forbid_immutable_update` | `BEFORE UPDATE OF` las 13 inmutables de §I3.2 |
| | `uscl_inactive_is_final` | `BEFORE UPDATE OF status, deactivated_at, deactivated_by_admin_id, deactivated_by_name, deactivated_by_role, deactivation_reason WHEN (OLD.status = 'inactive')`. **D-1:** el contrato decía «cualquier UPDATE» |
| | **`uscl_replacement_once`** (nuevo, **D-1**) | `BEFORE UPDATE OF replaced_by_link_id WHEN (OLD.replaced_by_link_id IS NOT NULL OR (NEW.replaced_by_link_id IS NOT NULL AND NEW.status <> 'inactive'))` |
| `canary_numbers` | `uscn_forbid_delete` | «los números canarios se revocan, no se borran» (texto del contrato) |
| | `uscn_forbid_truncate` (PG) | |
| | `uscn_forbid_immutable_update` | `BEFORE UPDATE OF` las 13 inmutables de §I4.2 |
| | `uscn_revoked_is_final` | `BEFORE UPDATE WHEN (OLD.status = 'revoked')` |
| | `uscn_cipher_only_shrinks` | `BEFORE UPDATE OF phone_encrypted WHEN (NEW.phone_encrypted IS NOT NULL AND (OLD.phone_encrypted IS NULL OR NEW.phone_encrypted <> OLD.phone_encrypted))` (texto del contrato) |
| `integration_snapshots` | `usis_forbid_update`, `usis_forbid_delete` y `usis_forbid_truncate` (PG) | Solo inserción |

Los CHECK, solo en PG, son 22:
- `uscl_status_chk`, `uscl_target_kind_chk`, `uscl_target_chk` y `uscl_deactivation_chk`: texto del contrato.
- `uscn_channel_chk`, `uscn_status_chk`, `uscn_consent_chk`, `uscn_hash_chk`, `uscn_last4_chk`, `uscn_whatsapp_no_cipher_chk`, `uscn_voice_cipher_chk`, **`uscn_four_eyes_chk`**, **`uscn_activation_four_eyes_chk`**, `uscn_authorized_chk`, `uscn_active_chk` y `uscn_revoke_chk`: texto del contrato.
- `usis_provider_chk`, `usis_kind_chk`, `usis_actor_type_chk`, `usis_fingerprint_chk`, **`usis_outcome_chk` (D-2)** y `usis_actor_chk`.

Y en el libro: `usae_subject_type_chk` con 7 sujetos.

**La función** `ultron_studio_forbid()` se redeclara con `CREATE OR REPLACE` y el cuerpo de F byte a byte. La prueba extrae el heredoc de F, de H5 y de H6 tal como lo recibe la base, comprueba que son idénticos, lo vuelve a ejecutar en PG y ve que el md5 no cambia. `down()` no la borra: es de F.

## 5. Recuentos antes y después (para `h6-schema-check.sql` de devops)

| | Antes (H5) | Con H6 | Tras `rollback --step=2` |
|---|---|---|---|
| PG · disparadores del Studio (no internos) | **30** | **43** (uscl 5 + uscn 5 + usis 3) | 30 |
| PG · CHECK del Studio | **27** | **49** (uscl 4 + uscn 12 + usis 6; `usae_subject_type_chk` se sustituye, no se suma) | 27 |
| SQLite · disparadores del Studio | **20** | **30** (uscl 4 + uscn 4 + usis 2) | 20 |
| Índices parciales del Studio (los dos motores) | 2 | 4 (+ `uscl_one_active`, `uscn_one_live`) | 2 |
| Tablas `ultron_studio_*` | 11 | 14 | 11 |
| `usae_subject_type_chk` (PG) | 4 sujetos | 7 sujetos | 4 sujetos |
| `ultron_studio_forbid()`: md5 de su definición | `4ad748b5…c197d7ee` | el mismo | el mismo |

He ejecutado esta propuesta de `h6-schema-check.sql` en local, antes y después, como `ironbody` y con el modelo de roles (`-v owner=h5_owner -v app=h5_app`). Ha dado exactamente los valores anotados. El fichero es de devops: aquí va el texto para que lo copie.

```sql
-- h6-schema-check.sql · SOLO LECTURA · Hito 6, antes y después de ironbody-migrate.
-- Producción (como root): sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h6-schema-check.sql
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,   -- 30 antes → 43 después
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,          -- 27 → 49
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscl\_%')       AS disparadores_uscl,     -- 0 → 5
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscn\_%')       AS disparadores_uscn,     -- 0 → 5
  (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'usis\_%')       AS disparadores_usis,     -- 0 → 3
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscl\_%')      AS check_uscl,            -- 0 → 4
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscn\_%')      AS check_uscn,            -- 0 → 12
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'usis\_%')      AS check_usis,            -- 0 → 6
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname IN ('uscl_one_active', 'uscn_one_live') AND i.indisunique AND i.indpred IS NOT NULL) AS parciales_h6,  -- 0 → 2
  (SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
    WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)                      AS parciales_studio,      -- 2 → 4
  (SELECT pg_get_constraintdef(oid) LIKE '%''integration''%' FROM pg_constraint
    WHERE conname = 'usae_subject_type_chk' AND conrelid = 'public.ultron_studio_audit_events'::regclass) AS check_7_sujetos, -- f → t
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,         -- iron (si no: PARAR, la 100002 falla con 42501)
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,       -- igual antes y después
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_audit_events') AS dueno_libro,    -- iron (ALTER TABLE lo exige)
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')     AS dueno_versiones, -- iron (las FKs lo exigen)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,      -- t
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%') AS tablas_studio, -- 11 → 14
  (SELECT count(*) FROM pg_tables WHERE schemaname = 'public'
    AND tablename IN ('ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots')) AS tablas_h6; -- 0 → 3
SELECT count(*) AS eventos_libro,                                                                      -- informativo: lo que valida el ADD CONSTRAINT bajo ACCESS EXCLUSIVE
       count(*) FILTER (WHERE subject_type NOT IN ('draft', 'version', 'rule', 'system')) AS eventos_fuera_de_los_4_sujetos -- 0 antes
  FROM ultron_studio_audit_events;
-- La 100002 pide ACCESS EXCLUSIVE sobre el libro (choca TAMBIÉN con un SELECT en una transacción abierta) y la 100001
-- SHARE ROW EXCLUSIVE sobre versiones (choca con quien escribió). Esperado: 0 filas.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, c.relname, l.mode
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname IN ('ultron_studio_audit_events', 'ultron_studio_versions') AND a.pid <> pg_backend_pid();
SELECT to_regclass('public.ultron_studio_canary_numbers') IS NOT NULL AS hay_h6 \gset
\if :hay_h6
SELECT t AS tabla,
       has_table_privilege(:'app', 'public.' || t, 'SELECT')   AS app_select,   -- t
       has_table_privilege(:'app', 'public.' || t, 'INSERT')   AS app_insert,   -- t
       has_table_privilege(:'app', 'public.' || t, 'UPDATE')   AS app_update,   -- t (lo prohibido lo paran los disparadores)
       has_table_privilege(:'app', 'public.' || t, 'DELETE')   AS app_delete,   -- informativo: t por los privilegios por defecto; borrar lo impide el disparador
       has_table_privilege(:'app', 'public.' || t, 'TRUNCATE') AS app_truncate, -- f
       has_sequence_privilege(:'app', 'public.' || t || '_id_seq', 'USAGE') AS app_secuencia, -- t
       (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = t) AS dueno, -- iron
       (xpath('/row/n/text()', query_to_xml('SELECT count(*) AS n FROM public.' || t, false, true, '')))[1]::text::int AS filas -- 0
  FROM unnest(ARRAY['ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots']) AS t;
\endif
```

## 6. Decisiones mías fuera del contrato (las tiene que confirmar el lead)

| # | Qué | Por qué y evidencia |
|---|---|---|
| **D-1** | `uscl_inactive_is_final` cubre solo las 6 columnas del cierre (`status`, `deactivated_*` y `deactivation_reason`), y **`uscl_replacement_once`** (nuevo) guarda `replaced_by_link_id`. Esa columna se anota una vez, de NULL a un valor y solo en una fila que queda inactiva; una vez anotada, no se vuelve a escribir. Escribir NULL sobre NULL pasa, porque es un no-op | Con la guarda **literal** (cualquier UPDATE de una inactiva), el paso 11 de §I3.4 («la activa pasa a inactive … y `replaced_by_link_id` = la nueva; se inserta la nueva») **no se puede ejecutar en ningún orden ni en ningún motor**. Lo comprobé con dos sondas (PG con transacción revertida; SQLite con PDO), detalladas debajo de esta tabla. **Columnas y DTO no cambian**; solo cambia la guarda, y hay un disparador más en cada motor. Si el lead prefiere otra enmienda, la alternativa es poner el puntero en la nueva fila (`replaces_link_id`, inmutable y UNIQUE), pero eso cambia columnas y DTO. Volver al literal es cambiar 2 entradas de `ROW_TRIGGERS` y sus 2 pruebas |
| **D-2** | `usis_outcome_chk` = `(kind = 'probe' AND outcome IS NOT NULL AND outcome IN (…)) OR (kind = 'config' AND outcome IS NULL)` | El texto del contrato **aceptaba una sonda con `outcome` NULL**: `NULL OR FALSE` da NULL, y un CHECK solo rechaza FALSE. Mi versión rechaza exactamente lo mismo que la literal más ese caso. Lo cubre la prueba («sonda sin resultado» → 23514). Revisé los otros 21 CHECK: todas sus columnas sin `IS [NOT] NULL` son NOT NULL, así que ninguno tiene ese hueco |
| D-3 | Los motivos de los disparadores que el contrato no da (inmutables, definitivos, cifrado, sustituta e instantáneas) | Redactados como los de F y H5. Los del contrato van literales |
| D-4 | `uscn_one_live` también se crea en la 100002 con SQL crudo, igual que `uscl_one_active` | El contrato lo dice de `uscl_one_active` y no especifica el otro; así van los dos parciales en las guardas, como en H5 |
| D-5 | El libro es **lo último** de la 100002, y `down()` toma los cerrojos antes de contar: la 100002 las 3 tablas y el libro; la 100001 las 3 tablas y versiones | Su ACCESS EXCLUSIVE dura lo menos posible. Comprobar y deshacer es atómico (patrón del H5) |
| D-6 | **Las dos** `down()` se niegan, y también ante eventos del libro con sujetos nuevos aunque las tablas estén vacías | Lo pide «Reversión», nivel 4 («ni eventos con los sujetos nuevos»). Además es defensa en profundidad (D-4 del H5) |
| D-7 | Sin índice para las 3 FKs | Versiones y asociaciones no se borran ni cambian de id (lo impiden sus disparadores), así que PG nunca busca quién las referencia, y ninguna consulta del contrato filtra por ellas (D-2 del H5). Añadirlo después es barato si hiciera falta |
| D-8 | `up()` de la 100002 no comprueba la clasificación con `--pretend`, y `down()` escribe el `LOCK` igualmente | Así la vista previa del despliegue funciona y enseña los cerrojos (patrón del laboratorio y del H5) |

Evidencia de D-1, con las guardas literales:
- **A** (desactivar, insertar la nueva y anotarla): `P0001 una asociación inactiva es definitiva`.
- **B** (insertar primero): `23505 l_one_active` en PG y `23000 UNIQUE constraint failed` en SQLite.
- **C** (apuntar a un id reservado con `nextval`): `23503` / `23000 FOREIGN KEY`. La FK no es diferible, y en SQLite Laravel ni siquiera sabe declararla así.
- **D** (CTE que modifica datos): `UPDATE 0`, no enlaza, y en SQLite no existe.

Con la corrección:
- A pasa;
- siguen prohibidos anotar en una activa, cambiar o borrar la sustituta, reactivar una inactiva y tocar su cierre.

## 7. Qué verifiqué ejecutando (comando + resultado)

### 7.1 SQLite, base de fichero (en mi scratchpad, no en `/tmp`)

| Paso | Resultado |
|---|---|
| Base = H5 (`migrate` con `--path` de las 323 migraciones anteriores) | `exit=0`. Mías `Pending` |
| `migrate` (H6) | 100001 en 10,47 ms y 100002 en 4,05 ms. El `diff` de `sqlite_master` base → H6 **solo tiene altas**: 3 tablas, 11 índices (2 parciales con su `WHERE`) y 10 disparadores. **0 líneas cambiadas o quitadas**: el libro y versiones intactos |
| `migrate:rollback --step=2` | `exit=0` (6,47 / 4,28 ms). Esquema **IDÉNTICO** a la base |
| `migrate` otra vez | `exit=0`. **IDÉNTICO** al primer H6 |
| Negativa con **1 número canario** sembrado | `FAIL`, `exit=1`: «… (0 asociaciones de campaña, 1 números canarios, 0 instantáneas de integración y 0 eventos del libro …) …». Esquema idéntico; las dos siguen `Ran` |
| Negativa con **solo 1 evento** `canary_number` (tablas vacías) | `FAIL`, `exit=1`, «… y 1 eventos del libro …». Esquema idéntico |
| `migrate:fresh` desde cero | 325 migraciones, 0 FAIL. Esquema **idéntico** al incremental (base + 2) |

### 7.2 PostgreSQL 14.24 (`ultron_studio_h6_db`, `PGOPTIONS='-c lock_timeout=5s'`)

| Paso | Resultado |
|---|---|
| Base = H5 | `exit=0`. `h6-schema-check.sql`: 30 / 27 / 0… / 2 parciales / `f` / 11 tablas |
| `migrate` (H6) | 26,83 / 20,47 ms. Check: 43 / 49 / 5-5-3 / 4-12-6 / 2 y 4 parciales / `t` / 14 tablas / misma huella |
| `diff` de `pg_dump -s` base → H6 | **1 línea cambiada** (`usae_subject_type_chk`) y 424 añadidas (3 tablas, secuencias, UNIQUE, 5 índices, 13 disparadores y 3 FKs) |
| `migrate:rollback --step=2` → `migrate` | `pg_dump -s` **IDÉNTICO** a la base y después **IDÉNTICO** al primer H6. El CHECK vuelve a 4 sujetos y después otra vez a 7 |
| `--pretend` en los dos sentidos | 54 sentencias de subida y 51 de bajada (SQLite: 24 / 23). Solo nombran las 3 tablas nuevas, el libro, versiones (FK y cerrojo de `down()`) y la función de F. **Ninguna tabla del motor.** La única coincidencia de `meta_` es el valor `'meta_ads'` de `usis_provider_chk` |
| Negativa con 1 asociación **confirmada** | `FAIL`, `exit=1`; esquema idéntico; la fila sigue |
| Negativa con solo 1 evento `integration` | `FAIL`, `exit=1`; esquema idéntico |

### 7.3 PHPUnit

```text
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
  → OK, but some tests were skipped! Tests: 22, Assertions: 321, Skipped: 5          (SQLite; las 5 son solo-PG y lo dicen)
UNIT_DB=ultron_studio_h6_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
  → OK (22 tests, 473 assertions)                                                      (PG; repetido al final sobre la base con roles: igual)
UNIT_DB=ultron_studio_h6_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php \
    tests/Feature/UltronStudio/UltronStudioImmutabilityTest.php tests/Feature/UltronStudio/UltronStudioAuditTest.php tests/Feature/UltronStudio/UltronStudioLabImmutabilityTest.php
  → Tests: 36, Assertions: 387, Failures: 2   (los 2 = recuentos fijos del H5: «43 is identical to 30» y, tras su down(), «40 … 27»; §10 H-1)
SQLite, las mismas cuatro + la mía → Tests: 58, Assertions: 634, Failures: 2, Skipped: 11   (los mismos 2: «30 … 20» y «28 … 18»)
Copia parcheada de UltronStudioPublicationSchemaTest (scratchpad, clase renombrada) → SQLite OK (13 tests, 98 assertions, 3 skipped) · PG OK (13 tests, 162 assertions)
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronEngineIsolationTest.php
  → Tests: 6, Assertions: 321, Failures: 1   (solo nombra mis dos migraciones; §10 H-2)
```

**Lo que cubren las 22 pruebas:**
- columnas y tipos; índices y FKs (y que se cumplen);
- que versiones y el libro no ganan columnas ni pierden guardas (en SQLite, el libro sin CHECK y su `sqlite_master` idéntico tras `down`/`up`);
- recuentos y nombres por tabla, y que la función es la de F;
- cada inmutable con «el mismo valor», `upsert`, DELETE y TRUNCATE (PG);
- la sustitución (D-1) y lo que sigue prohibido;
- cada CHECK con 23514, incluidos **los dos cuatro ojos con un UPDATE crudo** (al autorizar y al activar, y al reescribir quién lo hizo);
- `uscn_cipher_only_shrinks` (cambiar → P0001, mismo valor → pasa, borrar sin revocar → 23514 en PG y aceptado en SQLite: lo vigila el servicio);
- la máquina de estados completa por la base;
- los parciales (23505 / 23000) y su **`WHERE` en los dos motores** (`pg_get_indexdef` + `indpred`; `sqlite_master` + `PRAGMA index_list` con `partial = 1`), también los del H5 sobre versiones;
- `down()`, que se niega con cada uno de los 6 tipos de dato (en SAVEPOINT) y acepta sin ellos;
- `up()`, que se niega si hay una columna sin clasificar.

### 7.4 Roles (modelo de producción, dentro de `ultron_studio_h6_db`)

Preparación:
- reinicié `public` y quité `CREATE` a `PUBLIC`;
- `h5_owner`: `USAGE, CREATE` en `public`;
- `h5_app`: `USAGE`, con privilegios por defecto del dueño de tablas `SELECT, INSERT, UPDATE, DELETE` y de secuencias `SELECT, UPDATE, USAGE`.

Son roles locales del H5, sin superusuario y sin contraseña (`trust`).

Resultado:
- `h5_owner` migró H5 completo (323 migraciones, 0 FAIL) y después el H6 (19,64 / 26,33 ms). Las 3 tablas, sus secuencias y la función son de `h5_owner`, y la huella de la función es la misma.
- `h5_app` hereda `SELECT/INSERT/UPDATE/DELETE` y `USAGE` de la secuencia, con TRUNCATE `f`. **Mis migraciones no tienen ningún GRANT.**

Conectado como `h5_app` (`rolsuper = f`), todo en una transacción revertida:
- Inserta en las 3 tablas y en el libro con el sujeto `canary_number`.
- Inmutable, DELETE y UPDATE de instantánea → `P0001`.
- Autorizar con el mismo id que registró → `23514 uscn_four_eyes_chk`. Teléfono vivo duplicado → `23505 uscn_one_live`.
- `TRUNCATE` → `42501 permission denied`.
- `DISABLE TRIGGER`, `DROP TRIGGER`, `DROP CONSTRAINT uscn_four_eyes_chk`, `DROP INDEX uscn_one_live` y `DROP CONSTRAINT usae_subject_type_chk` → `42501 must be owner`.
- Redefinir la función → `42501 permission denied for schema public`. `SET session_replication_role` → `42501`.

### 7.5 `lock_timeout` 5 s (`PGOPTIONS`, como `ironbody-migrate`, migrando como `h5_owner`)

| Bloqueador (otra sesión de `h5_app`, con el cerrojo concedido en `pg_locks`) | Resultado | Lo que queda |
|---|---|---|
| `BEGIN; SELECT count(*) FROM ultron_studio_audit_events; pg_sleep(12)` (AccessShare) | 100001 `DONE` (24,52 ms: su FK no choca con un lector del libro). **100002 `FAIL` a los 5 s** (`55P03` en el `ALTER TABLE ultron_studio_audit_events`); total 5,36 s | **Las 3 tablas sin guardas** (0 disparadores, 0 CHECK, libro con 4 sujetos), 100002 `Pending`. El reintento sin bloqueador pasa (23,38 ms) |
| `BEGIN; UPDATE ultron_studio_versions … WHERE false; pg_sleep(12)` (RowExclusive) | **100001 `FAIL` a los 5 s** (`55P03` en la FK a versiones); total 5,31 s | Nada (0 tablas, ninguna registrada). El reintento aplica las dos |

## 8. Impacto

- **Mejora.** La inmutabilidad, el «definitivo», los cuatro ojos (por identidad, también contra SQL a mano), el borrado del cifrado al revocar y «una activa por campaña» / «una viva por canal y teléfono» dejan de depender solo del código. Valen contra SQL a mano y contra el rol de la aplicación.
- **Escrituras y espacio:** despreciables.
  - Volumen previsto: decenas de filas (≤ 50 asociaciones activas, ≤ 10 números vivos por canal y una instantánea por proveedor y despliegue).
  - Cada INSERT mantiene 4 o 5 índices pequeños, y los parciales solo contienen las filas activas o vivas.
  - **No he medido planes**, porque con tablas vacías no demuestran nada (§13). Con este tamaño, cualquier consulta del contrato es de unas pocas páginas.
- **Otras consultas:**
  - Del motor, ninguna cambia: no hay ni un byte en sus zonas, y ninguna sentencia nombra sus tablas.
  - El libro solo cambia su CHECK: las inserciones del H5 (4 sujetos) siguen pasando y las lecturas no cambian.
  - Versiones no se toca: solo se referencia.
- **Cerrojos en el despliegue.** Ver §7.5. En el libro, la cola (hasta 5 s) afecta solo al Studio: el motor no lo lee ni lo escribe.

## 9. Riesgos y reversibilidad

- **R-1 · La 100002 puede caer sola.** Basta un lector con una transacción abierta en el libro (por ejemplo, una pestaña del Studio en medio de una petición). Entonces queda la 100001 aplicada **sin guardas**. Es seguro mientras el código del H6 no esté desplegado, porque nadie usa las tablas, pero **hay que reintentar `ironbody-migrate` hasta que la 100002 quede `Ran` y `h6-schema-check.sql` dé 43/49 antes de publicar el código**. La prueba de humo de la fase 1 debería exigirlo. Es el mismo patrón que la 100002 del H5.
- **R-2 · Duración.** Unos 20–30 ms por migración sin bloqueo. La validación del nuevo CHECK recorre el libro entero bajo ACCESS EXCLUSIVE; en producción tiene pocas filas (el Studio está en el H4 con 0 versiones), pero no las he contado, y el check lo informa (`eventos_libro`).
- **R-3 · Vuelta atrás.**
  - Nivel 3 (volver al código del H5) **no exige** revertir el esquema: las tablas se quedan sin lector y el CHECK ampliado es un superconjunto.
  - Nivel 4 (`down()`) **nunca en producción** (límite del 25-10). En otros entornos se niega con datos del H6, y sin ellos deja el esquema idéntico a la base.
- **R-4 · Interbloqueo en `down()` (solo fuera de producción).** Una transacción del Studio que lea versiones y después escriba en una tabla nueva puede formar un ciclo con el cerrojo de `down()`. PG lo detecta y aborta una de las dos (`deadlock_timeout`). No se corrompe nada y se reintenta.

## 10. Hallazgos para otros dueños (no los he tocado)

- **H-1 · `UltronStudioPublicationSchemaTest` (H5; paquete S o el dueño que diga el lead).** Falla solo porque fija los recuentos globales del Studio. **Parche exacto, validado** sobre una copia en los dos motores (SQLite 13/98 con 3 omitidas; PG 13/162, las mismas aserciones que en el informe del H5):

  ```diff
  - *  · Recuentos del Studio: PG 30 disparadores y 27 CHECK (antes 27 y 24);
  - *    SQLite 20 disparadores (antes 18). Si un hito posterior añade guardas,
  + *  · Recuentos del Studio: PG 43 disparadores y 49 CHECK con el Hito 6 (H5 solo: 30 y 27);
  + *    SQLite 30 disparadores (H5 solo: 20). Si un hito posterior añade guardas,
  @@ test_recuentos_de_disparadores_y_check_del_studio
  -            $this->assertSame(30, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3');
  +            $this->assertSame(43, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13');
  -            $this->assertSame(27, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 (usv_status_chk se sustituye, no se suma)');
  +            $this->assertSame(49, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
  -        $this->assertSame(20, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2');
  +        $this->assertSame(30, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10');
  @@ test_down_sin_filas_de_h5_deja_el_esquema_de_h4_y_se_puede_volver_a_aplicar
  -            $this->assertSame(27, $this->studioTriggerCount());
  -            $this->assertSame(24, $this->studioCheckCount());
  +            $this->assertSame(40, $this->studioTriggerCount());
  +            $this->assertSame(46, $this->studioCheckCount());
  -            $this->assertSame(18, $this->studioTriggerCount());
  +            $this->assertSame(28, $this->studioTriggerCount());
  -        $this->assertSame($this->isPostgres() ? 30 : 20, $this->studioTriggerCount());
  +        $this->assertSame($this->isPostgres() ? 43 : 30, $this->studioTriggerCount());
  -            $this->assertSame(27, $this->studioCheckCount());
  +            $this->assertSame(49, $this->studioCheckCount());
  ```

  Las líneas son 25–26, 170, 172, 182, 359–360, 365, 373 y 375. Una propuesta para no repetir esto en cada hito: que cada prueba de esquema compruebe solo **sus** guardas por nombre, y que el total global lo fije únicamente la prueba del último hito.
- **H-2 · `UltronEngineIsolationTest` (paquete S, §I9.1).** Falla solo por `2026_11_01_100001` y `100002`; las tiene que añadir a `ALLOWED`. No aparece ningún otro fichero.
- **H-3 · El comando de verificación del lead** nombra `tests/Feature/UltronStudio/UltronStudioSchemaTest.php`, que **no existe**. Usé `UltronStudioPublicationSchemaTest.php` y añadí `UltronStudioLabImmutabilityTest.php`.
- **H-4 · Para S (servicio de asociaciones y canarios).**
  - Sustitución: tres sentencias en este orden dentro de la transacción. Primero `UPDATE … SET <cierre> WHERE id = :vieja AND status = 'active'`; después el INSERT de la nueva; por último `UPDATE … SET replaced_by_link_id = :nueva WHERE id = :vieja AND replaced_by_link_id IS NULL`. Nunca en una sola sentencia.
  - Si llega un 23505 en `uscl_one_active` o `uscn_one_live`, la transacción queda abortada en PG (25P02): se resuelve **fuera**, como pide el contrato.
  - Nunca `save()` del modelo entero: un inmutable en el `SET`, aunque lleve el mismo valor, es P0001, y en una fila revocada **cualquier** UPDATE lo es (también el `updated_at` que pone Eloquent).
  - Revocar tiene que llevar `phone_encrypted = NULL` en la **misma** sentencia.
- **H-5 · Para el tester.** Casos que solo se ven desde la base:
  - las carreras de §I11 (dos registros del mismo teléfono → un 23505; dos asociaciones de la misma campaña → un 23505);
  - autorizar frente a revocar (gana una; la otra afecta 0 filas);
  - que la base sola no impide 4 activaciones contra un tope de 3: el cerrojo consultivo es del servicio;
  - la 100002 aplicada a medias por `lock_timeout` (R-1) como estado real del despliegue;
  - `uscn_voice_cipher_chk`, que en SQLite no existe: la mutación «borrar el cifrado sin revocar» solo la caza PG.
- **H-6 · Hito 7.** Las 3 tablas guardan nombre y rol del personal. El procedimiento de redacción con el rol dueño tiene que cubrirlas (§I13).

## 11. Propuestas que no implementé (decisión del lead)

Son baratas, porque las tablas son diminutas, y hoy solo las garantiza el servicio. Si se aceptan, los recuentos y la prueba cambian, y los cambios los hago yo.

```sql
CREATE UNIQUE INDEX uscl_replaced_by_unique ON ultron_studio_campaign_links (replaced_by_link_id);       -- una asociación sustituye a una sola (los NULL no chocan); sirve además a la FK
ALTER TABLE ultron_studio_campaign_links ADD CONSTRAINT uscl_version_hash_chk CHECK (version_content_hash IS NULL OR version_content_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE ultron_studio_canary_numbers ADD CONSTRAINT uscn_country_code_chk CHECK (country_code ~ '^[1-9][0-9]{0,2}$');   -- la forma de I12
-- «Quien autorizó» no se reescribe: hoy un UPDATE crudo puede cambiar authorized_by_admin_id por otro id distinto del de quien registró.
CREATE TRIGGER uscn_authorizer_once BEFORE UPDATE OF authorized_by_admin_id, authorized_by_name, authorized_by_role, authorized_at
  ON ultron_studio_canary_numbers FOR EACH ROW WHEN (OLD.authorized_by_admin_id IS NOT NULL)
  EXECUTE FUNCTION ultron_studio_forbid('quien autorizó un número canario no se reescribe');   -- y su gemelo en SQLite
```

## 12. Operaciones pendientes de autorización

**Yo no he ejecutado ni ejecutaré nada en producción.** La fase 1 la ejecuta devops bajo la autorización del 25-10:
1. `h6-schema-check.sql` (antes);
2. `sudo /usr/local/bin/ironbody-migrate` (las 2 migraciones);
3. el mismo check (después): 43/49, 5-5-3, 4-12-6, 2/4, `t`, la misma huella y 14 tablas.

Si la 100002 cae por 55P03: **reintentar** hasta que quede `Ran` (R-1). `down()` no se ejecuta nunca en producción.

## 13. Qué no verifiqué, y por qué

- **Producción:** nada, ni lectura. Lo que cito de producción (dueño `iron`, privilegios por defecto, PG 14.24) viene del contrato y de los informes del H5, no de una comprobación mía.
- **Los roles reales `iron`/`iron_app`:** los reproduje con `h5_owner`/`h5_app` en local.
- **`ironbody-migrate`:** no lo he leído ni ejecutado. Simulé su `lock_timeout` con `PGOPTIONS`.
- **Planes de ejecución:** no los he medido. Las tablas no existen en producción y tendrán decenas de filas; un `EXPLAIN` sobre tablas vacías no demuestra nada. Lo que sí he medido es la integridad y los cerrojos.
- **El número de filas del libro en producción**, que determina lo que tarda la validación bajo cerrojo: lo informará `h6-schema-check.sql`.
- **La suite completa del backend:** fuera de lo pedido; el cambio solo toca tablas del Studio. Ejecuté el grupo de esquema, inmutabilidad, libro, laboratorio y aislamiento en los dos motores.
- **El servicio (paquete S)** aún no existe: las carreras reales con la aplicación son del tester.

## 14. Reproducir

```bash
cd Iron-Body/backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php                                 # SQLite
UNIT_DB=ultron_studio_h6_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php        # PG
# migrate / rollback contra una base desechable (nunca la del .env):
env APP_ENV=local DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_h6_db DB_USERNAME=ironbody DB_PASSWORD= DB_URL= \
    PGGSSENCMODE=disable PGOPTIONS='-c lock_timeout=5s' php artisan migrate:rollback --step=2 --force
env APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=/ruta/a/un/fichero.sqlite DB_URL= php artisan migrate:fresh --force
# bloqueador de §7.5 (en otra terminal, antes de migrar):
psql -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_h6_db -c "BEGIN; SELECT count(*) FROM ultron_studio_audit_events; SELECT pg_sleep(12); ROLLBACK;"
```
