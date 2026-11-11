# Informe de base de datos · Plantillas de WhatsApp de Meta desde el CRM, paquete 1a (L9-96)

database-specialist, 11-11-2026. Trabajo en local, sin commit.

- **Base:** rama `feat/ultron-studio-h9`, HEAD `da80942b`.
- **Plan:** `plans/PLANTILLAS_META_PLAN.md`, secciones §1, §5.3–§5.7, §6 (paso 1a) y §7 (R6).
- **Origen del trabajo:** retomo las dos migraciones que dejó en disco, sin commit, el agente anterior. **Las revisé contra el plan y no cambié ni una línea**: el `git status` las sigue mostrando como ficheros nuevos, con el mismo contenido que encontré.

## 1. Motor y versión

| Entorno | Motor | Notas |
|---|---|---|
| Producción | PostgreSQL 14 | Según el lead, `ultron_studio_template_meta_checks` tiene 0 filas. No lo he comprobado. |
| Puerta obligatoria | PostgreSQL 14.24 desechable (`127.0.0.1:55432`, base `ultron_studio_db_esq`) | Se ejecuta con `ops/pgunit.sh`. |
| Suite | SQLite 3.54.1 | Es la versión que trae PHP. Admite `ALTER TABLE … DROP COLUMN` (desde 3.35). |

## 2. Esquema exacto

### 2.1 Tabla NUEVA `ultron_studio_meta_template_submissions` (migración `2026_11_15_100001`)

Hay una fila por cada versión del Studio enviada a Meta.

**Columnas.** Están en el orden real. Los tipos son los de PG; en SQLite son `integer`, `varchar`, `tinyint` y `datetime`.

| Columna | Tipo (PG) | ¿Nulo? | Clase |
|---|---|---|---|
| `id` | bigserial PK | no | inmutable |
| `uuid` | uuid UNIQUE (`usmsub_uuid_unique`) | no | inmutable |
| `client_request_id` | uuid UNIQUE (`usmsub_client_request_unique`) | no | inmutable |
| `template_id` | bigint, **sin FK** | no | inmutable |
| `template_content_hash` | char(64) | no | inmutable |
| `meta_name` | varchar(64) | no | inmutable |
| `language` | varchar(5) | no | inmutable |
| `category` | varchar(10) | no | inmutable |
| `request_hash` | char(64) | no | inmutable |
| `state` | varchar(10) | no | mutable (solo hacia delante) |
| `meta_template_id` | varchar(32) | sí | mutable (se fija una sola vez) |
| `meta_status`, `meta_category`, `meta_correct_category`, `meta_rejected_reason`, `meta_quality` | varchar(24), varchar(16), varchar(16), varchar(32), varchar(8) | sí | mutables |
| `meta_body_hash`, `meta_components_hash` | char(64) | sí | mutables |
| `matches_studio` | boolean | sí | mutable |
| `mismatch_codes` | varchar(200) | sí | mutable |
| `attester_unavailable` | boolean, DEFAULT false | no | mutable |
| `error_code`, `error_subcode` | integer | sí | mutables |
| `error_user_message` | varchar(300) | sí | mutable |
| `submitted_by_admin_id`, `submitted_by_person_id` | bigint | no | inmutables |
| `submitted_by_name` | varchar(120) | no | inmutable |
| `submitted_by_role` | varchar(60) | sí | inmutable |
| `submitted_at` | timestamp(0) | no | inmutable |
| `last_checked_at`, `last_changed_at` | timestamp(0) | sí | mutables |
| `created_at` | timestamp(0) | no | inmutable |
| `updated_at` | timestamp(0) | sí | mutable |

La tabla no guarda ningún dato personal: ni teléfono, ni documento, ni el texto de la plantilla. De quien envía solo guarda una instantánea del personal, sin FK.

**Índices.** Además de la PK hay dos UNIQUE y dos UNIQUE parciales. El WHERE de los parciales es el mismo texto en los dos motores (en PG: `((state)::text <> 'FAILED'::text)`):

- `usmsub_one_live_per_template (template_id) WHERE state <> 'FAILED'`
- `usmsub_one_live_per_meta_name (meta_name, language) WHERE state <> 'FAILED'`

**Disparadores en PG (9).** Los siete primeros usan la función `ultron_studio_forbid()` de F, que no se redeclara (su md5 sigue siendo `4ad748b5…`):

| Disparador | Momento | Qué impide |
|---|---|---|
| `usmsub_forbid_delete` | BEFORE DELETE | Borrar envíos |
| `usmsub_forbid_truncate` | BEFORE TRUNCATE, por sentencia | Vaciar la tabla |
| `usmsub_forbid_immutable_update` | BEFORE UPDATE OF las 15 columnas inmutables | Cambiarlas, aunque sea al mismo valor |
| `usmsub_failed_is_final` | BEFORE UPDATE, WHEN `OLD.state = 'FAILED'` | Tocar un envío fallido: FAILED es definitivo |
| `usmsub_state_forward` | BEFORE UPDATE OF state | Cualquier transición distinta de SENDING → SUBMITTED, FAILED o UNKNOWN y de UNKNOWN → SUBMITTED o FAILED |
| `usmsub_meta_template_id_once` | BEFORE UPDATE OF meta_template_id | Cambiar el id de Meta una vez fijado |
| `usmsub_starts_sending` | BEFORE INSERT | Que un envío nazca en otro estado que SENDING, con algo observado, con `attester_unavailable`, con error o con `last_*` |
| `usmsub_template_approved_whatsapp` | BEFORE INSERT | Ver «Guardas entre tablas» |
| `usmsub_submitter_is_bound` | BEFORE INSERT | Ver «Guardas entre tablas» |

**Disparadores en SQLite (8).** Son los mismos menos el de TRUNCATE, con `RAISE(ABORT, …)` y el mismo texto que en PG.

**Guardas entre tablas.** Hay dos funciones propias en PG, plpgsql e INVOKER (sin `SECURITY DEFINER`), con las tablas cualificadas `public.`. En SQLite la misma condición va en el WHEN.

- `ultron_studio_chk_usmsub_template_approved_whatsapp`: la plantilla tiene que existir, ser de WhatsApp y estar `APPROVED`, y coincidir en huella (`content_hash`), `category`, `metaName` y `language` de su `content_json`.
- `ultron_studio_chk_usmsub_submitter_is_bound`: tiene que haber un vínculo `(admin_id, identity_id)` con `verified_at` no nulo y `revoked_at` nulo.

**CHECK, solo en PG (10):**

- **De dominio:**
  - `usmsub_state_chk`: SENDING, SUBMITTED, FAILED o UNKNOWN.
  - `usmsub_language_chk`: es o es_CO.
  - `usmsub_category_chk`: marketing o utility.
- **De forma:**
  - `usmsub_hashes_chk`: las huellas son 64 caracteres hex en minúsculas.
  - `usmsub_meta_name_chk`: `^[a-z0-9_]{1,64}$`.
  - `usmsub_observed_chk`: el id de Meta solo lleva dígitos; los demás `meta_*` siguen `[A-Z_]{1,n}`.
  - `usmsub_state_shape_chk`: si el estado es SUBMITTED, tiene `meta_template_id`; si no, no tiene nada observado y `attester_unavailable` es false.
  - `usmsub_match_chk`: casa, no casa con códigos de la lista o todavía no se sabe.
  - `usmsub_error_chk`: códigos mayores que 0, el subcódigo solo con código, y el mensaje en una línea de 1 a 300 caracteres.
  - `usmsub_submitter_chk`: ids mayores que 0 y nombre no vacío.

**Privilegios:**

- Se ejecuta `REVOKE DELETE ON … FROM "iron_app"`, o del rol que diga `ULTRON_STUDIO_DB_APP_ROLE`.
- La aplicación conserva UPDATE, porque la sincronización avanza las filas.
- Después del REVOKE se comprueba `has_table_privilege(rol, 'DELETE') = false`. Esa comprobación incluye lo que llega por PUBLIC o por otros roles. Si sigue pudiendo borrar, la migración falla.
- Si el rol no existe, no hay nada que revocar, salvo con `APP_ENV=production`: ahí es un error.

### 2.2 AMPLIACIÓN de `ultron_studio_template_meta_checks` (migración `2026_11_15_100002`)

**Columnas nuevas (3):**

| Columna | PG | SQLite |
|---|---|---|
| `source` | `varchar(10) NOT NULL DEFAULT 'manual'` | `varchar NOT NULL DEFAULT 'manual'` |
| `submission_id` | `bigint NULL`, sin FK | `integer NULL` |
| `meta_components_hash` | `varchar(64) NULL` | `varchar NULL` |

**Columnas que cambian de largo (solo PG):**

- `status`: de varchar(10) a varchar(20). `PENDING_DELETION` tiene 16 caracteres.
- `category`: de varchar(10) a varchar(16). `authentication` tiene 14 caracteres y no cabía; el plan no lo decía.

**CHECK, solo en PG: de 4 a 6.**

- `ustmc_status_chk`: los 4 de W9 (APPROVED, REJECTED, PAUSED, DISABLED), escritos tal cual y en su orden, más ocho:
  - de Meta: PENDING, IN_APPEAL, PENDING_DELETION, DELETED, ARCHIVED y LIMIT_EXCEEDED;
  - locales: NOT_FOUND y UNKNOWN.
- `ustmc_category_chk`: marketing, utility y authentication.
- `ustmc_source_chk` (nuevo): manual o meta_api.
- `ustmc_source_shape_chk` (nuevo):
  - una fila `manual` no lleva ni envío ni huella de componentes;
  - una fila `meta_api` lleva siempre la huella (64 hex) y, si cita un envío, su id es mayor que 0.
- Sin cambios: `ustmc_language_chk` y `ustmc_shape_chk`.

**Disparadores: de 4 a 5.**

- Nuevo: `ustmc_submission_of_template`, BEFORE INSERT, solo cuando `NEW.submission_id IS NOT NULL`. Comprueba que el envío citado es de la misma plantilla y de su huella exacta.
  - En PG usa la función `ultron_studio_chk_ustmc_submission_of_template` (plpgsql, INVOKER, `public.`).
  - En SQLite la condición va en el WHEN.
- Los de W9 no cambian. `ustmc_forbid_update` es un BEFORE UPDATE **sin lista de columnas**, así que también cubre las tres columnas nuevas.

**Privilegios:**

- No se concede ni se quita nada.
- Después del ALTER se vuelve a comprobar el REVOKE de W9: `has_any_column_privilege(rol, 'UPDATE') = false`, que incluye las columnas nuevas, y `has_table_privilege(rol, 'DELETE') = false`. Si el rol puede, la migración falla.

## 3. Qué hace cada migración en cada motor

Las tres empiezan con `SET LOCAL lock_timeout = '5s'` si la sesión no trae ningún límite. Dentro de `ironbody-migrate` manda el de ese proceso, que también es de 5 s.

### 3.1 `2026_11_15_100001` (envíos)

**PostgreSQL:**

- **`up()`**:
  1. Toma la huella de partida y se niega si la tabla ya existe.
  2. Crea la tabla y comprueba que cada columna está clasificada una sola vez (inmutable o mutable).
  3. Crea los 10 CHECK, los 9 disparadores, las 2 funciones y los 2 índices parciales.
  4. Hace el `REVOKE DELETE` y lo comprueba.
  5. Comprueba el recuento en `ultron_studio_%`: exactamente +1 tabla, +9 disparadores, +10 CHECK y +2 parciales, con los nombres declarados. Si no cuadra, falla.
  - No pide cerrojo sobre ninguna tabla existente (no hay FK).
- **`down()`**:
  1. Ejecuta `LOCK TABLE … ACCESS EXCLUSIVE` antes de contar.
  2. Se niega si la ampliación `2026_11_15_100002` sigue aplicada, porque la cita.
  3. Se niega si hay alguna fila.
  4. Si no hay filas: `DROP TABLE` y `DROP FUNCTION` de sus dos funciones. La de F se queda.
  5. Comprueba el recuento inverso.
  - Nunca borra filas.

**SQLite:**

- **`up()`**: lo mismo, pero sin CHECK, sin TRUNCATE y sin REVOKE: 8 disparadores, con las guardas entre tablas en el WHEN.
- **`down()`**: las mismas negativas que en PG.
- Todo va en una transacción propia, porque Laravel no la abre en SQLite.

### 3.2 `2026_11_15_100002` (ampliación)

**PostgreSQL:**

- **`up()`**:
  1. Ejecuta `LOCK TABLE … ACCESS EXCLUSIVE` antes de contar.
  2. Comprueba que la tabla está exactamente como la deja W9: sus 15 columnas, sus 4 disparadores, sus 4 CHECK, sin ampliar y con los largos de W9. Por eso **se niega a ampliar dos veces**.
  3. Ejecuta **un único ALTER TABLE**: tres `ADD COLUMN`, dos `ALTER TYPE` y DROP + ADD de los dos CHECK de dominio, más los dos CHECK nuevos. Es un solo cerrojo y no hay ningún instante sin CHECK.
     - Ampliar un varchar no reescribe la tabla.
     - El DEFAULT de `source` queda en el catálogo (PG ≥ 11).
  4. Crea la función y el disparador.
  5. Vuelve a comprobar el REVOKE.
  6. Comprueba el recuento: +3 columnas, +2 CHECK, +1 disparador, y **las mismas filas con la misma huella md5 de sus 15 columnas de W9**.
- **`down()`**:
  1. Toma el cerrojo antes de contar.
  2. Se niega si hay alguna fila `meta_api`, con un estado nuevo, con una categoría nueva, con un envío citado o con huella de componentes. Es el R6 del plan.
  3. Si no las hay: `DROP TRIGGER`, y después un único ALTER que quita los dos CHECK nuevos y las tres columnas, devuelve `status` y `category` a varchar(10) y restaura los dos CHECK de W9 (los lee de la migración de W9, así hay una sola fuente de verdad). Por último, `DROP FUNCTION`.
  4. Comprueba que el resultado es la forma de W9, con las mismas filas.

**SQLite:**

- **`up()`**: tres `ADD COLUMN` nativos, sin recrear la tabla (recrearla se llevaría sus disparadores), y el disparador nuevo. No hay CHECK ni largos que tocar, igual que en W9.
- **`down()`**: primero `DROP TRIGGER` y después tres `DROP COLUMN`, con las mismas negativas.

### 3.3 Cómo se revierte

- **Mientras no haya filas nuevas:** `migrate:rollback` de `100002` y después de `100001`. Ese es el orden natural, y `100001` se niega si se invierte.
- **Con la primera fila de la API, o el primer envío:** el esquema ya no se revierte (R6), y es lo seguro. Para volver al código anterior no hace falta: las columnas y la tabla se quedan sin lector, y las banderas están apagadas.
- **En producción:** no se ejecuta `migrate:rollback`.

## 4. Revisión contra el plan (§5.7) y observaciones

**Cumple:**

- Las columnas de §5.7.1.
- Los dos UNIQUE parciales.
- DELETE y TRUNCATE prohibidos.
- UPDATE solo de las columnas mutables, con `state` solo hacia delante y `meta_template_id` fijado una vez.
- La guarda entre tablas y los CHECK de dominio y de forma.
- `REVOKE DELETE` comprobado.
- `lock_timeout`.
- `down()` que se niega.
- Lo de §5.7.2 completo, incluido el REVOKE vuelto a comprobar por tabla y por columna, y el `down()` del R6.
- No se toca ningún dato existente.

**Desviaciones del plan, todas razonadas en la cabecera de su migración:**

1. `ustmc_template_meta_checks.meta_components_hash` es `varchar(64)` y no `char(64)`. Es el mismo tipo que las otras dos huellas de W9 y se comporta igual con el CHECK.
2. `category` pasa a varchar(16): `authentication` no cabía en varchar(10).
3. `ustmc_source_shape_chk` es más estricto que «`meta_api` ⇒ huella»: además, una fila `manual` no puede llevar ni envío ni huella. Coincide con §5.3 (las filas manuales solo valen para plantillas de solo cuerpo) y con §5.6.

**Observaciones (ninguna bloquea):**

- **El índice `usmsub_one_live_per_template` es una segunda barrera.** La guarda entre tablas obliga a que el nombre y el idioma sean los de la plantilla, así que dos envíos vivos de la misma versión chocan siempre también con `usmsub_one_live_per_meta_name`. SQLite informa de ese; PG puede informar de cualquiera de los dos. La prueba acepta los dos y fija aparte la definición de cada índice.
- **Hay CHECK que un disparador tapa:**
  - `usmsub_state_chk` queda tapado por los disparadores de estado;
  - `usmsub_language_chk`, `usmsub_category_chk` y `usmsub_meta_name_chk` quedan tapados por la guarda entre tablas.

  Son defensa en profundidad para cuando alguien desactive los disparadores. La prueba los verifica evaluando su expresión sobre una tabla temporal (`violatedChecks`), sin apagar nada.
- **Para plantillas-be (código):**
  - Un UPDATE sobre la tabla de envíos solo puede nombrar columnas mutables. Nombrar una inmutable falla aunque sea con el mismo valor.
  - Una fila FAILED no admite ningún UPDATE, tampoco `updated_at`.
  - Se nace SENDING y lo observado solo se escribe con SUBMITTED, junto con `meta_template_id` en la misma sentencia.
  - La orden manual nunca puede escribir `meta_components_hash` ni `submission_id`.
- **Para el lead o el arquitecto (del plan, no del esquema):**
  - Un envío SUBMITTED cuya plantilla desaparece de Meta (`meta_status = NOT_FOUND`) sigue «vivo» para los dos índices.
  - Esa versión y su nombre no se pueden volver a enviar: hace falta una versión nueva con otro `metaName`.
  - Encaja con R1 (Meta bloquea el nombre 30 días), pero el plan no lo dice explícitamente.

## 5. Recuentos que cambiaron (antes → después), por fichero

**Deltas por motor:**

| Motor | Tablas | Disparadores | CHECK | Parciales | Funciones |
|---|---|---|---|---|---|
| PG | +1 | +10 (9 + 1) | +12 (10 + 2) | +2 | +3 |
| SQLite | +1 | +9 (8 + 1) | — | +2 | — |

**Cambios por fichero:**

| Fichero | Cambios |
|---|---|
| `tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php` | `PG_TRIGGERS_H6` 126 → 136, `PG_TRIGGERS_H7` 127 → 137, `PG_CHECKS_H6` 140 → 152, `PG_CHECKS_H7` 141 → 153, `SQLITE_TRIGGERS_H6` 109 → 118, `SQLITE_TRIGGERS_H7` 111 → 120 |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php` | `PG_TRIGGERS_H5` 114 → 124, `PG_TRIGGERS_H6` 127 → 137, `PG_CHECKS_H5` 119 → 131, `PG_CHECKS_H6` 141 → 153, `SQLITE_TRIGGERS_H5` 101 → 110, `SQLITE_TRIGGERS_H6` 111 → 120. Las 3 listas de parciales del Studio ganan `usmsub_one_live_per_meta_name` y `usmsub_one_live_per_template` |
| `tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php` | Recuentos: 127 → 137, 141 → 153, 111 → 120, 123 → 133, 137 → 149, 107 → 116, 126/109 → 136/118, 140 → 152. Además, del encargo A: la aserción que rechazaba `ultron-engine-config/2` pasa a rechazar `/3` |
| `tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php` | `PG_STUDIO_TRIGGERS` 127 → 137, `PG_STUDIO_CHECKS` 141 → 153, `SQLITE_STUDIO_TRIGGERS` 111 → 120, `STUDIO_PARTIAL_INDEXES` +2, tablas `ultron_studio_%` 26 → 27 |
| `tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php` | 127 → 137, 141 → 153, 111 → 120, `STUDIO_PARTIAL_INDEXES` +2 |
| `tests/Feature/Outreach/W9/TemplateMetaChecksSchemaTest.php` | Columnas 15 → 18 (lo que la enmienda §6 del plan permite). **Dos aserciones más, por consecuencia directa de la enmienda:** el estado que se usa como inválido pasa de `PENDING`, que ahora es válido, a `INVENTADO`; y los disparadores de la tabla, de 4 a 5 |
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **No es un número.** La lista blanca de migraciones que pueden nombrar el Studio gana las tres nuevas: `2026_11_16_100001` en `ALLOWED`, junto a las del H5, y `2026_11_15_100001` y `2026_11_15_100002` en `ALLOWED_H9_MIGRATIONS`. Sin eso, `test_fuera_del_studio_nadie_nombra_…` fallaba solo por ellas. No toqué nada más del fichero |

Ningún otro valor cambia: los recuentos por subsistema (voz 24/34, gobierno del H9 55/53, outreach 51/75, funciones del gobierno 20…) siguen igual.

## 6. Cifras por motor

**Medido en la base desechable con las tres migraciones (la de M-1 incluida):**

| Indicador | Antes | Después |
|---|---|---|
| Tablas `ultron_studio_%` | 26 | 27 |
| Disparadores | 127 | 137 |
| CHECK | 141 | 153 |
| Índices parciales | 9 | 11 |
| Funciones `ultron_studio_%` | 26 | 29, con 0 `SECURITY DEFINER` |

El md5 de `ultron_studio_forbid()` sigue siendo `4ad748b5f17f09b398f7f641c197d7ee`. Por tabla:

- **Envíos:** 9 disparadores y 10 CHECK.
- **Comprobaciones:** 5 disparadores, 6 CHECK y 18 columnas.

**SQLite:** los disparadores `ultron_studio_%` pasan de 111 a 120.

**Duración en local (con 0 filas):**

| Migración | `up` | `down` |
|---|---|---|
| `100001` | 39,6 ms | 12,6 ms |
| `100002` | 9,2 ms | 21,1 ms |

**Cerrojos en producción:**

- `100001` no toca ninguna tabla existente.
- `100002` toma ACCESS EXCLUSIVE sobre `ultron_studio_template_meta_checks`, que el canal de W9 lee. Con una lectura abierta, la migración espera como mucho 5 s, cae con 55P03 y se reintenta.

## 7. Pruebas nuevas

| Fichero | Casos | Qué cubre |
|---|---|---|
| `UltronStudioMetaTemplateSubmissionsSchemaTest.php` | 16 | Columnas y tipos; índices y su WHERE; nombres de las guardas; nacer SENDING (12 variantes); avanzar, FAILED definitivo y libre para un envío nuevo, id una vez; las 15 inmutables con el mismo valor; DELETE y TRUNCATE; 10 casos de las guardas entre tablas; los dos únicos; PG: los 10 CHECK (4 tapados, evaluados con `violatedChecks`) y 17 rechazos reales con 23514; funciones INVOKER y la de F intacta; rol de prueba (S/I/U sí, D no; la aplicación envía y avanza, pero borrar da 42501); `up()` falla si DELETE llega por PUBLIC; `down()` negado mientras le cita la ampliación o si hay filas; ida y vuelta idéntica con sus recuentos; 55P03 y 5 s |
| `UltronStudioTemplateMetaChecksMetaApiSchemaTest.php` | 12 | Columnas, largos y valores por defecto; una fila manual de hoy entra y es `manual`; el envío citado es de la misma plantilla y huella; solo inserción también en las columnas nuevas; PG: los 12 estados, `authentication`, `INVENTADO`/`promo` rechazados y 5 casos de `ustmc_source*`; función INVOKER y WHEN del disparador; REVOKE vuelto a comprobar con el rol de prueba (UPDATE y DELETE dan 42501) y `up()` falla si alguien concedió `UPDATE (meta_name)`; `down()` negado con cada tipo de fila nueva; ida y vuelta igual que W9 recién creada, con un solo ALTER en cada sentido; las filas de W9 intactas (huella md5); `up()` no amplía dos veces; 55P03 en `up` y en `down`, y 5 s |

**Mutaciones, comprobadas en PG y con los ficheros restaurados byte a byte:**

- Quitar la comprobación del REVOKE en `100002` hace fallar la prueba del rol.
- Quitar la negativa con filas en `100001` hace fallar `down_se_niega_con_filas`.

## 8. Qué verifiqué ejecutando de verdad

Ejecuté un solo proceso cada vez, con `nice -n 10`, y nunca la suite completa. La lista de 12 ficheros es: las 3 pruebas nuevas (M-1, envíos y ampliación), las 6 que fijan recuentos (H7, Integrations, IntegrationsGuardsAdversarial, Publication, Strategy y Voice), `UltronEngineIsolationTest`, `TemplateMetaChecksSchemaTest` (W9) y `OutreachSchemaTest`.

**SQLite** (`php -d memory_limit=2G vendor/bin/phpunit --no-logging <los 12 ficheros>`): 434 pruebas y 3 959 aserciones, con **1 fallo** y 182 omitidas (las que solo valen para PG).

**PG** (`UNIT_DB=ultron_studio_db_esq bash docs/ultron-studio/ops/pgunit.sh --no-logging <los mismos 12>`): 434 pruebas y 5 686 aserciones, con **1 fallo** y 2 omitidas (las que solo valen para SQLite).

**El único fallo en los dos motores no es de este paquete:** es `UltronEngineIsolationTest::test_la_unica_lectura_del_motor_es_de_solo_lectura_y_solo_la_usa_el_commit`. Lo provoca `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php`, de bm-motor, que invoca al lector. Ya fallaba antes de mis cambios, con el mismo diff, y lo arregla el cambio de bm-motor en ese fichero, que aplicará el lead.

**Además:**

- `migrate --pretend` sobre la base desechable: el SQL de §3 es el que se ejecuta.
- `migrate:rollback --step=3` y `migrate` sobre la base desechable: ida y vuelta limpia, con los tiempos de §6.

## 9. Qué NO verifiqué

- **Producción:** ni las 0 filas, ni los recuentos reales de partida (asumo 127/141/26/9 porque HEAD = producción), ni la existencia de `iron_app` ni sus privilegios por defecto.
  - En producción, las guardas INVOKER necesitan que `iron_app` pueda leer `ultron_studio_message_templates`, `ultron_studio_person_bindings` y la tabla nueva. Lo dan los privilegios por defecto del dueño (`arwd`), según el H7, pero aquí no lo he comprobado.
- **El ensayo en el banco por el camino de producción (R6):** con el modelo de roles real (`h5_owner`/`h5_app`) y `ironbody-migrate`. El clúster local no tiene `iron_app`: el REVOKE solo se probó con el rol de prueba `ultron_studio_h9_db_app`.
- **Cerrojos con tráfico real:** la espera sobre `ultron_studio_template_meta_checks` con el canal de W9 leyendo.
- **El código que escribe estas tablas** (plantillas-be): que respete las reglas de §4 se verá en sus pruebas.
- **La suite completa:** no la ejecuté, por orden del lead.

## 10. Pendiente de autorización y avisos

- **Producción:** aplicar `2026_11_15_100001` y `2026_11_15_100002` con `ironbody-migrate` **antes** del código que las lee (§5.7.2). Lo decide el lead con orden del propietario. Yo no he ejecutado nada fuera de la base desechable.
- **security:** el plan pide revisar el REVOKE y el rol de la aplicación:
  - `REVOKE DELETE` (no UPDATE) sobre los envíos;
  - el REVOKE UPDATE, DELETE de W9, vuelto a comprobar;
  - las funciones INVOKER, que dependen de que la aplicación pueda leer las tablas citadas;
  - el dueño puede desactivar los disparadores: no lo cubre ninguna migración.
