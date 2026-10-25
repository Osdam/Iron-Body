# Hito 5 · paso 1a · informe del `database-specialist`: la cadena de publicaciones y sus guardas

**Fecha:** 24-10-2026 noche (Bogotá) · 25-10 UTC. **Árbol:** `_worktrees/thermal-receipts/Iron-Body/backend`, rama `feat/ultron-studio-h5` (al empezar `0366009`; durante el trabajo el lead añadió `3735b58`, solo docs). **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md` §A3 (A3.1–A3.5) y la memoria «SQLite reconstruye y pierde el WHERE». **Sin commit:** los tres ficheros quedan sin seguimiento en el árbol. **Producción: nada tocado.**

## 0. Resumen

- Dos migraciones y su prueba, verificadas en **SQLite 3.53.4** y **PostgreSQL 14.24** (el mismo menor que producción).
- **Recuentos del Studio:** PG **27 → 30 disparadores** y **24 → 27 CHECK**; SQLite **18 → 20 disparadores**. `ultron_studio_forbid()` queda byte a byte igual (md5 de `pg_get_functiondef` `4ad748b5f17f09b398f7f641c197d7ee` antes y después).
- `usv_status_chk` admite los 9 estados y rechaza el resto (23514). `usv_one_canary` / `usv_one_published` conservan su `WHERE` en los dos motores; una segunda `CANARY` o `PUBLISHED` choca (23505 en PG, 23000 «UNIQUE constraint failed» en SQLite).
- `migrate:rollback --step=2` deja el esquema **idéntico** a la base y `migrate` lo deja idéntico al primero, en los dos motores. `down()` **se niega** con una publicación o una versión en un estado de H5 (código de salida 1, nada deshecho) y acepta sin ellas.
- Rol dueño sin superusuario y rol de aplicación solo-DML **sin ningún GRANT nuevo**: probado.
- `lock_timeout` 5 s: con una transacción que escribió en versiones, cada migración falla limpia a los **5,24 s** (55P03) y no queda registrada; el reintento pasa.
- Suite pedida en SQLite (`tests/Feature/UltronStudio` + `tests/Feature/Access`, 403 pruebas): **un único fallo, `UltronEngineIsolationTest`, solo por mis dos migraciones** (lista blanca; la actualiza B-M en el paso 1c).
- **Tres hallazgos para otros dueños** (§11). El primero **bloquea la puerta PG del Studio**: `UltronStudioImmutabilityTest` sondea `PUBLISHED` como estado prohibido y H5 lo permite.

## 1. Motor, versión y entorno

| | Versión | Dónde |
|---|---|---|
| PostgreSQL | 14.24 (Homebrew), igual que producción (`ULTRON_STUDIO_SECURITY_REPORT.md`, línea 230) | Clúster desechable `~/.ironbody-pg14-disposable`, `127.0.0.1:55432`, usuario `ironbody` (superusuario local) |
| SQLite | 3.53.4 (la del PDO de PHP 8.3.33; la CLI `sqlite3` 3.51.0 solo se usó para leer `sqlite_master`) | `:memory:` en la suite; un fichero en el scratchpad para `migrate`/`rollback` |
| Laravel / PHPUnit | 12.58.0 / 11.5.55 | — |

Bases creadas (todas desechables, prefijo `ultron_studio_`; nunca la del `.env`, que en este árbol no existe):

| Base | Para qué | Estado final |
|---|---|---|
| `ultron_studio_h5_db` | `migrate`/`rollback`, sondas, `lock_timeout` | Con H5; 6 versiones de F sembradas |
| `ultron_studio_h5_unit_db` | PHPUnit con `ops/pgunit.sh` (`UNIT_DB`) | La deja la suite |
| `ultron_studio_h5_refuse_db` | La negativa de `down()` con filas reales | Con 1 publicación y 1 versión que **no se pueden borrar** (para eso está la guarda): desechable entera |
| `ultron_studio_h5_roles_db` | Dueño sin superusuario `h5_owner` y aplicación solo-DML `h5_app` (roles locales, sin contraseña, `trust`) | Con H5 |

## 2. Ficheros (los únicos que toqué)

| Fichero | Qué |
|---|---|
| `database/migrations/2026_10_25_100001_create_ultron_studio_publications_table.php` | A3.1: la tabla, sus tres UNIQUE, `uspub_version_idx` y tres FKs RESTRICT. `down()` se niega con filas de H5 (cerrojo previo en PG) y si no, borra la tabla |
| `database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php` | A3.2: disparadores (2 en los dos motores + TRUNCATE en PG) con `ultron_studio_forbid()` (`CREATE OR REPLACE`, cuerpo de F), 3 CHECK en PG, `usv_status_chk` a 9 estados, los dos índices únicos parciales. `down()` §A3.4 |
| `tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php` | 13 pruebas: columnas y tipos, índices y FKs, versiones sin columnas nuevas, recuentos, solo inserción (P0001/23000), TRUNCATE y CHECK (PG), 9 estados (PG), `WHERE` de los parciales (dos motores), un solo `CANARY`/`PUBLISHED`, `down()` que se niega y que acepta |
| `docs/ultron-studio/plans/informes-h5/informe-db.md` | Este informe |

Pint (`vendor/bin/pint --test` en los tres PHP): `passed`.

## 3. DDL por motor

Salida de `php artisan migrate --pretend` (la vista previa aplana los espacios del cuerpo de la función; el texto real es el de F, ver §4).

### 3.1 PostgreSQL · `up()`

```sql
-- 2026_10_25_100001
create table "ultron_studio_publications" ("id" bigserial not null primary key, "uuid" uuid not null, "seq" integer not null, "client_request_id" uuid not null, "action" varchar(10) not null, "version_id" bigint null, "restores_publication_id" bigint null, "previous_publication_id" bigint null, "engine_contract" varchar(32) not null, "effective_json" text not null, "effective_hash" char(64) not null, "signature" char(64) not null, "catalog_version" varchar(32) not null, "lab_gate" json not null, "acknowledged" json null, "evidence" json null, "reason" varchar(500) null, "actor_admin_id" bigint not null, "actor_name" varchar(120) not null, "actor_role" varchar(60) null, "created_at" timestamp(0) without time zone not null);
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_version_id_foreign" foreign key ("version_id") references "ultron_studio_versions" ("id") on delete restrict;
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_restores_publication_id_foreign" foreign key ("restores_publication_id") references "ultron_studio_publications" ("id") on delete restrict;
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_previous_publication_id_foreign" foreign key ("previous_publication_id") references "ultron_studio_publications" ("id") on delete restrict;
create index "uspub_version_idx" on "ultron_studio_publications" ("version_id", "id");
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_uuid_unique" unique ("uuid");
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_seq_unique" unique ("seq");
alter table "ultron_studio_publications" add constraint "ultron_studio_publications_client_request_id_unique" unique ("client_request_id");

-- 2026_10_25_100002
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_action_chk CHECK (action IN ('publish', 'promote', 'rollback', 'legacy'));
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_engine_contract_chk CHECK (engine_contract IN ('ultron-engine-config/1'));
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_reason_chk CHECK (action NOT IN ('rollback', 'legacy') OR reason IS NOT NULL);
CREATE OR REPLACE FUNCTION ultron_studio_forbid() RETURNS trigger LANGUAGE plpgsql AS $fn$ … $fn$;   -- el cuerpo de F, byte a byte
CREATE TRIGGER uspub_forbid_update BEFORE UPDATE ON ultron_studio_publications FOR EACH ROW EXECUTE FUNCTION ultron_studio_forbid('la cadena de publicaciones es de solo inserción');
CREATE TRIGGER uspub_forbid_delete BEFORE DELETE ON ultron_studio_publications FOR EACH ROW EXECUTE FUNCTION ultron_studio_forbid('la cadena de publicaciones es de solo inserción');
CREATE TRIGGER uspub_forbid_truncate BEFORE TRUNCATE ON ultron_studio_publications FOR EACH STATEMENT EXECUTE FUNCTION ultron_studio_forbid('la cadena de publicaciones es de solo inserción');
ALTER TABLE ultron_studio_versions DROP CONSTRAINT usv_status_chk, ADD CONSTRAINT usv_status_chk CHECK (status IN ('DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'));
CREATE UNIQUE INDEX usv_one_canary ON ultron_studio_versions (status) WHERE status = 'CANARY';
CREATE UNIQUE INDEX usv_one_published ON ultron_studio_versions (status) WHERE status = 'PUBLISHED';
```

Tipos resultantes (catálogo, `Schema::getColumns`): `effective_json` **`text`** (no json/jsonb); `lab_gate`/`acknowledged`/`evidence` `json`; `effective_hash`/`signature` `character(64)`; `seq` `integer`; ids `bigint`; `uuid`/`client_request_id` `uuid`; `created_at` `timestamp(0) without time zone`; sin `updated_at`. NULL-ables: `version_id`, `restores_publication_id`, `previous_publication_id`, `acknowledged`, `evidence`, `reason`, `actor_role`.

### 3.2 SQLite · `up()`

```sql
-- 2026_10_25_100001
create table "ultron_studio_publications" ("id" integer primary key autoincrement not null, "uuid" varchar not null, "seq" integer not null, "client_request_id" varchar not null, "action" varchar not null, "version_id" integer, "restores_publication_id" integer, "previous_publication_id" integer, "engine_contract" varchar not null, "effective_json" text not null, "effective_hash" varchar not null, "signature" varchar not null, "catalog_version" varchar not null, "lab_gate" text not null, "acknowledged" text, "evidence" text, "reason" varchar, "actor_admin_id" integer not null, "actor_name" varchar not null, "actor_role" varchar, "created_at" datetime not null, foreign key("version_id") references "ultron_studio_versions"("id") on delete restrict, foreign key("restores_publication_id") references "ultron_studio_publications"("id") on delete restrict, foreign key("previous_publication_id") references "ultron_studio_publications"("id") on delete restrict);
create index "uspub_version_idx" on "ultron_studio_publications" ("version_id", "id");
create unique index "ultron_studio_publications_uuid_unique" on "ultron_studio_publications" ("uuid");
create unique index "ultron_studio_publications_seq_unique" on "ultron_studio_publications" ("seq");
create unique index "ultron_studio_publications_client_request_id_unique" on "ultron_studio_publications" ("client_request_id");

-- 2026_10_25_100002 (en una transacción que abre la migración)
CREATE TRIGGER uspub_forbid_update BEFORE UPDATE ON ultron_studio_publications FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'ultron_studio: la cadena de publicaciones es de solo inserción (UPDATE en ultron_studio_publications)'); END;
CREATE TRIGGER uspub_forbid_delete BEFORE DELETE ON ultron_studio_publications FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'ultron_studio: la cadena de publicaciones es de solo inserción (DELETE en ultron_studio_publications)'); END;
CREATE UNIQUE INDEX usv_one_canary ON ultron_studio_versions (status) WHERE status = 'CANARY';
CREATE UNIQUE INDEX usv_one_published ON ultron_studio_versions (status) WHERE status = 'PUBLISHED';
```

`ultron_studio_versions` **no se reconstruye**: el `diff` de `sqlite_master` base → H5 solo tiene altas (la tabla nueva, sus 4 índices, sus 2 disparadores y los 2 índices parciales); el `CREATE TABLE` de versiones y sus 2 disparadores de F siguen iguales. `PRAGMA index_list('ultron_studio_versions')`: `usv_one_canary` y `usv_one_published` con `unique = 1, partial = 1`.

### 3.3 `down()` (vista previa de `migrate:rollback --step=2 --pretend`)

```sql
-- PostgreSQL · 2026_10_25_100002 (dentro de una transacción; la negativa va entre el LOCK y el primer DROP)
LOCK TABLE ultron_studio_publications, ultron_studio_versions IN ACCESS EXCLUSIVE MODE;
DROP INDEX IF EXISTS usv_one_canary;
DROP INDEX IF EXISTS usv_one_published;
ALTER TABLE ultron_studio_versions DROP CONSTRAINT IF EXISTS usv_status_chk, ADD CONSTRAINT usv_status_chk CHECK (status IN ('DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'));
DROP TRIGGER IF EXISTS uspub_forbid_update ON ultron_studio_publications;   -- y _delete, _truncate
ALTER TABLE IF EXISTS ultron_studio_publications DROP CONSTRAINT IF EXISTS uspub_reason_chk;   -- y _action_chk, _engine_contract_chk
-- (ultron_studio_forbid() es de F: se queda)
-- PostgreSQL · 2026_10_25_100001
LOCK TABLE ultron_studio_publications, ultron_studio_versions IN ACCESS EXCLUSIVE MODE;
drop table if exists "ultron_studio_publications";

-- SQLite: la misma negativa, después DROP INDEX IF EXISTS (×2), DROP TRIGGER IF EXISTS (×2) y drop table if exists.
```

**Ninguna sentencia nombra tablas del motor** (`marketing_*`, `ultron_aborts`): en las cuatro vistas previas solo aparecen `ultron_studio_publications`, `ultron_studio_versions` y la función `ultron_studio_forbid`.

## 4. Recuentos antes y después

| | Base (H4 = producción, 27/24 del despliegue del 25-10) | Con H5 | Tras `rollback --step=2` |
|---|---|---|---|
| PG · disparadores del Studio (no internos) | **27** (F 11 + laboratorio 16) | **30** (+ `uspub_forbid_update`, `_delete`, `_truncate`) | 27 |
| PG · CHECK del Studio | **24** (F 11 + laboratorio 13) | **27** (+ `uspub_action_chk`, `uspub_engine_contract_chk`, `uspub_reason_chk`; `usv_status_chk` se sustituye, no se suma) | 24 |
| SQLite · disparadores del Studio | **18** (F 7 + laboratorio 11) | **20** (+ `uspub_forbid_update`, `_delete`) | 18 |
| Tablas `ultron_studio_*` | 10 | 11 | 10 |
| Índices de `ultron_studio_versions` | PG 6 · SQLite 5 | PG 8 · SQLite 7 (+ los dos parciales) | 6 · 5 |
| FKs que apuntan a versiones (PG) | 3 | 4 | 3 |
| `ultron_studio_forbid()`: dueño · md5 de la definición | dueño de F · `4ad748b5f17f09b398f7f641c197d7ee` | el mismo · **el mismo** | el mismo |

## 5. Sondas

### 5.1 La prueba de la migración (`UltronStudioPublicationSchemaTest`)

```text
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
  → OK, but some tests were skipped! Tests: 13, Assertions: 98, Skipped: 3     (SQLite: las 3 omitidas son solo-PG y lo dicen)
UNIT_DB=ultron_studio_h5_unit_db docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
  → OK (13 tests, 162 assertions)                                               (PostgreSQL 14.24)
```

### 5.2 PostgreSQL a mano (transacción revertida; `\set VERBOSITY verbose`)

| Sonda | Resultado |
|---|---|
| `UPDATE` de la cadena (mismo valor) | `P0001: ultron_studio: la cadena de publicaciones es de solo inserción (UPDATE en ultron_studio_publications)` |
| `DELETE FROM` la cadena | `P0001: … (DELETE en ultron_studio_publications)` |
| `TRUNCATE` la cadena | `P0001: … (TRUNCATE en ultron_studio_publications)` |
| `INSERT … ON CONFLICT (uuid) DO UPDATE` | `P0001: … (UPDATE en …)` |
| `UPDATE … WHERE id = -1` | `UPDATE 0`, sin error |
| `action = 'otra'` · `engine_contract = 'ultron-engine-config/2'` | `23514` `uspub_action_chk` · `23514` `uspub_engine_contract_chk` |
| `rollback` sin motivo · `legacy` sin motivo | `23514` `uspub_reason_chk` (las dos) |
| `seq` repetido | `23505` `ultron_studio_publications_seq_unique` (la carrera por la cabeza la gana uno) |
| Una versión por los 9 estados | pasa (`NOTICE: … los 9 estados pasaron por usv_status_chk`) |
| `VALIDATING` · `INVENTADO` | `23514` `usv_status_chk` (las dos) |
| Una `CANARY` y una `PUBLISHED` | pasan |
| Segunda `CANARY` · segunda `PUBLISHED` | `23505` `usv_one_canary` · `23505` `usv_one_published` |

Plan observado (tablas casi vacías, solo orientativo): «¿hay otra `CANARY`?» → `Index Only Scan using usv_one_canary`; la cabeza (`ORDER BY seq DESC LIMIT 1`, la lectura del motor) → `Index Scan Backward using ultron_studio_publications_seq_unique`.

### 5.3 SQLite a mano (PDO, el driver de la suite; transacción revertida)

| Sonda | Resultado |
|---|---|
| `UPDATE` · `DELETE` por id · `DELETE FROM` sin WHERE (camino de TRUNCATE) · upsert | `SQLSTATE 23000 / código 19 / ultron_studio: la cadena de publicaciones es de solo inserción (UPDATE\|DELETE en ultron_studio_publications)` |
| `UPDATE … WHERE id = -1` | 0 filas, sin error |
| `seq` repetido | `23000 UNIQUE constraint failed: ultron_studio_publications.seq` |
| FK a una versión inexistente | `23000 FOREIGN KEY constraint failed` |
| Segunda `CANARY`, segunda `PUBLISHED`, `APPROVED → CANARY` con otra `CANARY` | `23000 UNIQUE constraint failed: ultron_studio_versions.status` |
| Dos `RETIRED` y dos `ROLLED_BACK` | pasan (sin tope) |

### 5.4 El orden de las transiciones importa (dos motores) — nota para B-S

Los índices únicos se comprueban **fila a fila, no al final** (en PG solo una restricción `DEFERRABLE` se difiere, y un índice parcial no puede serlo). Medido:

- Promover con la `PUBLISHED` anterior aún en su sitio: `CANARY → PUBLISHED` **primero** → `23505 usv_one_published` (PG) / `23000` (SQLite). En el orden «la que sale, después la que entra» (`PUBLISHED → RETIRED`, después `CANARY → PUBLISHED`) pasa.
- Revertir una `PUBLISHED` restaurando la `RETIRED` **en una sola sentencia** (`UPDATE … SET status = CASE …`) → `23505` en PG y `23000` en SQLite: el orden de las filas no está garantizado. En dos sentencias, primero `PUBLISHED → ROLLED_BACK` y después `RETIRED → PUBLISHED`, pasa.

**Regla para `StudioPublications`:** dentro de la transacción, siempre primero la transición que **saca** a una versión de `CANARY`/`PUBLISHED` y después la que **mete** a otra; nunca las dos en una sentencia. Y en `publish`, un `23505` sobre `usv_one_canary` es la carrera de dos versiones distintas hacia `CANARY` (409 `canary_in_progress`), distinto del de `seq` (`publication_moved`) y del de `client_request_id` (reintento): en PG los tres abortan la transacción y se resuelven fuera, como dice A5.1 paso 12.

## 6. Rollback y re-aplicación

| Prueba | PostgreSQL | SQLite |
|---|---|---|
| `migrate` (H5) | 100001 en 15,61 ms, 100002 en 6,36 ms | 4,13 ms, 1,06 ms |
| `migrate:rollback --step=2` → esquema frente a la base | **idéntico** (`pg_dump -s`, sin las líneas `\restrict` aleatorias) | **idéntico** (`sqlite_master` completo) |
| `migrate` otra vez → frente al primero | **idéntico** | **idéntico** |
| `rollback --step=2` con 6 versiones de F (los 5 estados) | pasa (9,73 / 5,99 ms); el CHECK de 5 estados las revalida | — |
| `rollback --step=2` con **una versión `CANARY`** (sin publicaciones) | `FAIL`, **código de salida 1**: «ultron_studio_publications: down() se niega a revertir el Hito 5 con datos del Hito 5 (0 publicaciones, 1 versiones en CANARY, PUBLISHED, RETIRED, ROLLED_BACK). La cadena es de solo inserción y revertir la destruiría (contrato H5 §A3.4). Para volver al código de H4 no hace falta revertir el esquema (§A3.5).» Esquema idéntico al de antes; las dos migraciones siguen `Ran` | Con una `ROLLED_BACK`: la misma negativa, código 1, `sqlite_master` idéntico |
| `rollback --step=2` con **una publicación** (la versión ya en `APPROVED`) | `FAIL`, código 1, «(1 publicaciones, 0 versiones …)»; esquema idéntico; la fila sigue | — |
| Sin filas de H5 | pasa | pasa (3,10 / 2,43 ms) y se vuelve a aplicar |

En la suite, la prueba recorre además los cuatro estados de H5 contra las **dos** `down()` (las dos se niegan, también la de la tabla llamada sola) y el ciclo completo `down` → esquema de H4 (27/24 en PG, 18 en SQLite, `CANARY` → 23514) → `up` → 30/27/20.

## 7. Roles (patrón del Hito 4)

Reproducción local del modelo de producción en `ultron_studio_h5_roles_db`: `REVOKE CREATE ON SCHEMA public FROM PUBLIC`; `h5_owner` (LOGIN, sin superusuario, sin CREATEDB ni CREATEROLE) con `USAGE, CREATE` en `public`; `h5_app` (LOGIN, sin superusuario) con `USAGE`; privilegios por defecto de `h5_owner` hacia `h5_app` iguales a los leídos en producción: tablas `arwd`, secuencias `rwU`, funciones `X`.

- `h5_owner` migró las **315** migraciones desde cero (código 0), revirtió H5 (`--step=2`) y la volvió a aplicar con `lock_timeout` 5 s (7,49 / 5,67 ms). Dueño de la tabla, su secuencia, los índices y la función: `h5_owner`. ACL heredada: `ultron_studio_publications {h5_owner=arwdDxt, h5_app=arwd}`, secuencia `{h5_app=rwU}`. **Las migraciones de H5 no contienen ningún GRANT** (`grep -i grant` solo encuentra el comentario que lo dice).
- Como `h5_app` (sin superusuario): `has_table_privilege` S/I/U/D `t`, TRUNCATE `f`, TRIGGER `f`; secuencia `USAGE` `t`; `EXECUTE` de la función `t`. Inserta una versión y una publicación y mueve la versión a `CANARY` **sin ningún GRANT nuevo**. `UPDATE`/`DELETE` de la cadena → `P0001`; `TRUNCATE` → `42501 permission denied`; `DISABLE TRIGGER`, `DROP TRIGGER`, `DROP CONSTRAINT uspub_reason_chk`, `DROP CONSTRAINT usv_status_chk`, `DROP INDEX usv_one_canary` → `42501 must be owner`; redefinir la función → `42501 permission denied for schema public`; `SET session_replication_role = replica` → `42501`; segunda `CANARY` → `23505 usv_one_canary`.
- **Precondición comprobada:** con la función de F en manos de otro rol (`ALTER FUNCTION … OWNER TO ironbody`), la 100002 de `h5_owner` falla `42501 must be owner of function ultron_studio_forbid` y no deja ningún disparador `uspub_`; devuelta la propiedad, pasa. `CREATE OR REPLACE` también exige `CREATE` en `public` (lo que le falta a `h5_app`).

## 8. `lock_timeout` 5 s (`PGOPTIONS='-c lock_timeout=5s'`, como `ironbody-migrate`)

Un segundo `psql` abre una transacción, toca `ultron_studio_versions` y espera (`pg_sleep(12)`, después `ROLLBACK`); la migración se lanza cuando el cerrojo consta concedido en `pg_locks`.

| Bloqueador | Migración | Resultado | Lo que queda |
|---|---|---|---|
| `UPDATE ultron_studio_versions SET updated_at = now() …` (RowExclusive) | 100001 | `FAIL` **5,24 s**, `55P03 canceling statement due to lock timeout` en el `add constraint … foreign key … references "ultron_studio_versions"` | Ninguna migración de H5 registrada; la tabla no existe |
| El mismo `UPDATE` | 100002 (con la 100001 ya aplicada) | `FAIL` **5,24 s**, `55P03` en el `ALTER TABLE ultron_studio_versions DROP CONSTRAINT …` | 0 disparadores, 0 CHECK y 0 índices de H5; `usv_status_chk` con sus 5 estados |
| `SELECT count(*) FROM ultron_studio_versions` (AccessShare) | 100002 | `FAIL` **5,24 s**, `55P03` | Igual que la fila anterior |
| El mismo `SELECT` | 100001 + 100002 | La **100001 pasa** (8,54 ms: la FK pide ShareRowExclusive, que no choca con lectores) y la **100002 falla** a los 5 s | **La tabla sin sus guardas** hasta el reintento |
| (sin bloqueador) | reintento | `DONE` (5–11 ms) | Esquema idéntico al del primer `migrate` |

Cerrojos medidos en `pg_locks` sobre `ultron_studio_versions`: la FK de la 100001 → `ShareRowExclusiveLock`; el `ALTER` de la 100002 → `AccessExclusiveLock`; `CREATE INDEX` → `ShareLock` (cubierto por el anterior); `DROP TABLE` de la cadena en `down()` → `AccessExclusiveLock` (por la FK).

## 9. Impacto

- **Mejora:** la invariante «como mucho una `CANARY` y una `PUBLISHED`» y la cadena de solo inserción dejan de depender del código: valen contra SQL a mano y contra el rol de la aplicación. La cabeza de la cadena se lee por el índice único de `seq` (una lectura por commit, A4.7).
- **Escrituras:** los dos índices parciales tienen como mucho una entrada cada uno; solo los mantienen los `UPDATE` que cambian `status` (que ya mantenían `usv_status_idx`). La cadena crece una fila por publicar/promover/revertir/Legacy: decenas al año, < 2 KB por fila. Coste de espacio y de escritura despreciable.
- **Cerrojos:** ver §8. El motor no lee versiones, así que la cola que pueda formar la 100002 (hasta 5 s) es solo del Studio.
- **Otras consultas:** ninguna del motor cambia. Las del Studio sobre versiones pueden usar los parciales; nada empeora. El código de H4 sigue funcionando sobre este esquema: solo escribe los 5 estados de F (superconjunto en el CHECK) y nunca `CANARY`/`PUBLISHED`.

## 10. Desviaciones y decisiones (con motivo)

| # | Qué | Por qué |
|---|---|---|
| D-1 | Nombres `uspub_action_chk`, `uspub_engine_contract_chk` y `uspub_version_idx` | El contrato no los nombra; prefijo `uspub_` como los disparadores y el patrón de F/H4 |
| D-2 | Las dos FKs propias, `ON DELETE RESTRICT`, sin índice | El contrato dice «FK propia» sin acción; RESTRICT como todas las de H4/H5. Sin índice: las filas no se borran ni cambian de id (PG nunca busca quién las referencia) y nadie filtra por ellas |
| D-3 | `usv_status_chk`: DROP + ADD en **una** sentencia `ALTER TABLE` | Cumple «en la misma transacción» y además: un solo cerrojo y ningún instante sin CHECK. En `up()` sin `IF EXISTS` (si el CHECK de F no está, el esquema no es el esperado y debe fallar); en `down()` con `IF EXISTS` |
| D-4 | Las **dos** `down()` se niegan (no solo la de las guardas) | Defensa en profundidad: revertir solo la 100001 tampoco puede destruir la cadena |
| D-5 | `down()` en PG toma `LOCK TABLE ultron_studio_publications, ultron_studio_versions IN ACCESS EXCLUSIVE MODE` antes de contar, dentro de `DB::transaction` | Comprobar y deshacer tiene que ser atómico (sin él, una inserción confirmada entre el recuento y el `DROP` se perdería con la tabla). Son los cerrojos que el `DROP` pediría igual; el orden (la cadena, después versiones) es el de `up()` y el de publicar. Con `--pretend` se escribe igualmente (`pretending()`), para que la vista previa lo enseñe |
| D-6 | Índices parciales sin `CONCURRENTLY` | No cabe en la transacción de la migración y la tabla tiene decenas de filas; su `ShareLock` queda cubierto por el `AccessExclusive` del `ALTER` |
| D-7 | Sin CHECK en SQLite, tampoco en la tabla nueva | Patrón de F/H4 (DDL igual entre motores vía el constructor de esquemas); lo vigila el servicio y la puerta PG |
| D-8 | `ultron_studio_forbid()` redeclarada con el cuerpo de F | Lo pide el contrato; md5 idéntico antes/después. `down()` no la toca (es de F) |

## 11. Hallazgos para otros dueños (no los he tocado)

- **H-1 · bloquea la puerta PG del Studio.** `tests/Feature/UltronStudio/UltronStudioImmutabilityTest.php:178-181` (`test_solo_en_postgres_truncate_y_los_check`) espera `23514` al poner una versión en `'PUBLISHED'`; con A3.2 ese estado es legal y la prueba falla en PG («La base aceptó una escritura que tenía que rechazar»). En SQLite se omite, por eso la suite SQLite no lo ve. Arreglo propuesto, para su dueño: sondear `'VALIDATING'` (sigue fuera del CHECK) o un estado inventado. Grupo en PG con mis migraciones: `Tests: 403, Errors: 1, Failures: 2` = H-1 + H-2 + H-3.
- **H-2 · esperado.** `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra…` falla en SQLite y PG **solo** por las dos migraciones de H5 (sus 8 referencias a `ultron_studio_*`; ningún otro fichero). Las añade a la lista blanca B-M en el paso 1c (A9.1).
- **H-3 · preexistente, independiente de H5.** `Tests\Feature\Access\AdminUserPasswordTest::test_si_el_motor_falla_al_guardar_ni_el_hash_ni_la_clave_llegan_al_log` da `42601` en PG porque la propia prueba ejecuta SQL de SQLite (`CREATE TRIGGER … BEGIN SELECT RAISE(ABORT …); END`). Está entre las 11 que ya fallaban en PG en la base (memoria `postgres-local-para-pruebas`). No lo reproduje en un HEAD limpio.
- **H-4 · informativo (B-S).** `app/Services/UltronStudio/StudioVersionStatus.php:9-13` dice que, en PostgreSQL, el CHECK `usv_status_chk` impide guardar los estados de H5; con esta migración ya no. Quien los haga alcanzables tiene que corregir ese comentario.
- **H-5 · Hito 7.** La cadena guarda nombre y rol del personal: el procedimiento de redacción con el rol dueño tiene que cubrir `ultron_studio_publications` (ya está en el plan, §12).

## 12. Propuestas que no implementé (decisión del lead)

El contrato congela tres CHECK. Estas invariantes del propio contrato son baratas de llevar a la base en PG (la tabla es diminuta) y hoy solo las garantiza el servicio:

```sql
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_seq_positive_chk CHECK (seq >= 1);
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_previous_chk CHECK ((seq = 1) = (previous_publication_id IS NULL));      -- «NULL solo en seq = 1»
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_version_chk CHECK ((action = 'legacy') = (version_id IS NULL));         -- «NULL en legacy»
ALTER TABLE ultron_studio_publications ADD CONSTRAINT uspub_restores_chk CHECK (restores_publication_id IS NULL OR action = 'rollback');
CREATE UNIQUE INDEX uspub_one_successor ON ultron_studio_publications (previous_publication_id);                                    -- la cadena no se bifurca
```

Si se aceptan: el recuento pasa a 31 CHECK y la prueba de la migración cambia (las añado yo). `uspub_reason_chk` acepta `''`; el mínimo de 5 caracteres lo pone el servicio (podría ser `char_length(btrim(reason)) >= 5`). Un `ultron-engine-config/2` futuro exigirá ampliar `uspub_engine_contract_chk`: es a propósito.

## 13. Notas para `ironbody-migrate` (fase 1; no ejecutado, requiere orden expresa)

No he leído el envoltorio (vive en el servidor); me baso en el runbook: rol dueño `iron`, `lock_timeout` 5 s, regenera la caché.

1. **Antes (solo lectura, con el rol dueño):** el SQL de abajo. Esperado: 27 / 24 / 0 / 0 / 0 / `f`, `dueno_funcion = iron`, `create_en_public = t`, `existe_cadena = f`, 0 versiones fuera de los 5 estados. Si la función no es de `iron`, la 100002 fallará con 42501 (§7): **parar**, no forzar.
2. `sudo /usr/local/bin/ironbody-migrate` → las dos migraciones (en local, 5–16 ms cada una).
3. **Si falla con `55P03`** (§8): alguien tenía `ultron_studio_versions` abierta. Si cayó la **100001**, no queda nada. Si cayó la **100002** (pasa con un lector abierto), queda la tabla **sin guardas**: es seguro mientras no se despliegue el código (nadie la usa), pero **hay que reintentar `ironbody-migrate` hasta que la 100002 quede `Ran` antes de publicar el código**. Mientras espera (hasta 5 s), las lecturas del Studio sobre versiones hacen cola; el motor no lee versiones.
4. **Después:** el mismo SQL. Esperado: 30 / 27 / 3 / 3 / 2 / `t`, la misma huella de la función, `existe_cadena = t`, `app_dml = t`, `app_truncate = f`, `app_secuencia = t`, `publicaciones = 0`.
5. **Revertir:** el código no lo necesita (A3.5). `down()` solo con autorización expresa, con `lock_timeout`, y se niega en cuanto exista una publicación o una versión en un estado de H5.

```sql
-- h5-schema-check.sql · SOLO LECTURA · psql -X -v ON_ERROR_STOP=1 -x -f h5-schema-check.sql
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,  -- 27 → 30
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,         -- 24 → 27
  (SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'uspub_%')                            AS disparadores_uspub,   -- 0 → 3
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uspub_%')      AS check_uspub,          -- 0 → 3
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname IN ('usv_one_canary', 'usv_one_published') AND i.indisunique AND i.indpred IS NOT NULL) AS indices_parciales, -- 0 → 2
  (SELECT pg_get_constraintdef(oid) LIKE '%ROLLED_BACK%' FROM pg_constraint WHERE conname = 'usv_status_chk') AS check_9_estados, -- f → t
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,        -- iron
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,      -- igual antes y después
  has_schema_privilege(current_user, 'public', 'CREATE')                                   AS create_en_public,     -- t para el rol que migra
  to_regclass('public.ultron_studio_publications') IS NOT NULL                             AS existe_cadena;        -- f → t
SELECT count(*) AS versiones_fuera_de_los_5_estados_de_f
  FROM ultron_studio_versions WHERE status NOT IN ('DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED');  -- 0 antes
SELECT to_regclass('public.ultron_studio_publications') IS NOT NULL AS hay_cadena \gset
\if :hay_cadena
SELECT has_table_privilege('iron_app', 'public.ultron_studio_publications', 'SELECT,INSERT,UPDATE,DELETE') AS app_dml,       -- t
       has_table_privilege('iron_app', 'public.ultron_studio_publications', 'TRUNCATE')                    AS app_truncate,  -- f
       has_sequence_privilege('iron_app', 'public.ultron_studio_publications_id_seq', 'USAGE')             AS app_secuencia, -- t
       (SELECT count(*) FROM ultron_studio_publications)                                                    AS publicaciones; -- 0
\endif
```

Validado en local (con `h5_app` en lugar de `iron_app`) antes y después de H5: da exactamente los valores anotados.

## 14. Para el tester (lo que solo se ve desde la base)

- Carreras con 23505 de tres orígenes en `publish` (`seq` → `publication_moved`, `client_request_id` → reintento, `usv_one_canary` → `canary_in_progress`) y de `usv_one_published` en `promote`/`rollback` si el servicio invierte el orden (§5.4). En PG, cada uno aborta la transacción: comprobar que la respuesta se resuelve fuera y no da 500 (`25P02`).
- `down()` que se niega con filas de H5 y su mensaje (ya cubierto en la suite; en PG también por la CLI, §6).
- La 100002 aplicada a medias por `lock_timeout` (§8) es un estado real del despliegue: la prueba de humo de la fase 1 debería exigir 30/27 antes de seguir.

## 15. Qué no verifiqué, y por qué

- **Producción:** nada (ni lectura). Los valores de producción citados (27/24, dueño `iron`, privilegios por defecto) son del despliegue del 25-10 y de la lectura del lead del 19-10, no míos.
- **El rol real `iron`/`iron_app`:** reproducido con `h5_owner`/`h5_app` en local, no con los de producción.
- **`ironbody-migrate`:** no lo he leído ni ejecutado; simulé su `lock_timeout` con `PGOPTIONS`.
- **La suite completa del backend:** no la corrí (fuera de lo pedido; el cambio solo toca tablas del Studio). Sí `tests/Feature/UltronStudio` + `tests/Feature/Access` en los dos motores.
- **H-1/H-3 en un HEAD limpio:** no. H-1 es consecuencia directa y buscada de A3.2 (se ve en la aserción); H-3 falla por SQL de SQLite en la propia prueba.
- **El servicio de publicación** (paso 3) aún no existe: las carreras reales con la aplicación son del tester.
- Mientras trabajaba, B-M empezó a escribir en el mismo árbol (`UltronCommitService.php`, `config/marketing.php`, `.env.example`, `UltronPublishedConfig.php`, `UltronPublishedConfigTest.php`, `UltronCommitStudioStampTest.php`): mis ejecuciones de la suite completa del grupo son **anteriores** a esos cambios. Re-ejecutado al final con su trabajo en curso: mi prueba sigue en verde (13, 98 aserciones, 3 omitidas) y `UltronEngineIsolationTest` enseña mis mismas 8 referencias más la de `app/Services/Marketing/Ultron/UltronPublishedConfig.php:102`, y falla también `test_las_zonas_del_motor_estan_limpias…`: las dos cosas son de B-M y se resuelven con su lista blanca del paso 1c.

## 16. Reproducir

```bash
cd Iron-Body/backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php            # SQLite
UNIT_DB=ultron_studio_h5_unit_db docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php   # PG
# migrate / rollback contra una base desechable (nunca la del .env):
env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_h5_db DB_USERNAME=ironbody DB_PASSWORD= DB_URL= PGGSSENCMODE=disable \
    PGOPTIONS='-c lock_timeout=5s' php artisan migrate:rollback --step=2 --force
# bloqueador de la §8 (en otra terminal, antes de migrar):
psql -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_h5_db -c "BEGIN; UPDATE ultron_studio_versions SET updated_at = now() WHERE id = (SELECT min(id) FROM ultron_studio_versions); SELECT pg_sleep(12); ROLLBACK;"
```
