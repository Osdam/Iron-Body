# C9-1 · El libro de autorizaciones de contacto (`outreach_consents`) — informe de database-specialist

Rama `cierre/consent` · 22-11-2026 · Plan §2.3, fila C9-1 · Migración `2026_11_22_100001_create_outreach_consents_table`, aditiva e inerte.
**Motor:** PostgreSQL 14 (producción; probado en 14.24 local) y SQLite 3.54 (la batería). Laravel 12.58, PHP 8.3.

## 1 · Esquema final

**Libro puro, solo inserción:** `requested` (una cuenta registra) → `granted` (OTRA cuenta confirma) o `rejected`; `revoked` sin petición ni segunda cuenta.

| Columnas | Reglas |
|---|---|
| `id`, `uuid` UNIQUE, `client_request_id` NOT NULL UNIQUE, `redacted_at`, `created_at` | idempotencia de la API; sin `updated_at` |
| `subject_key` (24), `member_id`, `lead_id` (sin FK) | `m:<socio>` o `l:<prospecto>` igual a su referencia (socio manda); suprimida: `x:<id>` |
| `purpose` ∈ commercial/collections/service · `channel` ∈ whatsapp · `kind` ∈ requested/granted/rejected/revoked · `source` ∈ contract_checkbox/reception_form/member_request | conjuntos cerrados (`source`, el de por defecto: D7 sin decidir) |
| `request_uuid` → `uuid` (FK RESTRICT a sí misma), UNIQUE | solo en granted/rejected; una resolución por petición |
| `evidence` (≤ 300) | 1–300, sin controles, sin 7 dígitos seguidos, sin la serie de 8 con separadores ni correos |
| `legal_version` (48), `legal_hash` (sha256) | obligatorios en requested y granted; prohibidos en rejected/revoked |
| `phone_ref` (64 hex, HMAC `outreach-phone/1`) | obligatorio en requested y granted; NULL en rejected/revoked y al suprimir |
| `actor_type` ∈ admin/system · `recorded_by_*` · `confirmed_by_*` (id, nombre, rol) | el sistema solo retira; quien resuelve, solo en granted/rejected; **cuatro ojos por cuenta** |

**13 CHECK en PG** (`ocon_kind/purpose/channel/source/actor_type/subject/request/actor/confirmed/four_eyes/legal/phone/evidence_chk`),
redactados para que cada fila mal formada viole **una sola** regla. En SQLite, 13 disparadores de INSERT equivalentes con el
mismo nombre sin `_chk` (lo que GLOB no expresa —controles, series con separadores, correos— solo en PG).
**8 disparadores en PG** (7 en SQLite) con su propia función `outreach_consent_forbid()`: `ocon_forbid_delete`,
`ocon_forbid_immutable_update` (16 columnas), `ocon_redaction_only`, `ocon_staff_names_redaction_only`, `ocon_starts_unredacted`,
`ocon_forbid_truncate` (PG) y dos guardas sobre la propia tabla (funciones `outreach_consent_chk_*`, plpgsql, INVOKER, `public.`):
`ocon_resolves_request` (la resolución copia de su petición viva persona, finalidad, canal, fuente, quién la registró y, si
confirma, número y texto legal) y `ocon_grant_not_superseded` (una petición anterior a una retirada de lo mismo ya no se confirma).
**Índices:** pkey, 3 únicos y `ocon_subject_idx (subject_key, purpose, channel, id) INCLUDE (kind, request_uuid, phone_ref)` (PG; en
SQLite sin INCLUDE); ninguno parcial. **Rol de la aplicación:** REVOKE UPDATE, DELETE, TRUNCATE comprobado después; en producción
sin el rol, falla sin registrarse. `down()` se niega con filas.

**La única mutación:** la supresión 1581 de la persona, una vez y entera (`subject_key = 'x:'||id`, sin socio ni lead,
`phone_ref` NULL, `evidence = '[suprimido]'`, `redacted_at`), y el nombre del personal a `[suprimido]`. La guarda **deja pasar
esas dos formas y nada más**, así que la supresión no apaga ningún disparador de esta tabla (más estricto que `outreach_*`).

**En qué me aparto de la especificación, y por qué:**
- `legal_text_version/hash` → **`legal_version/hash`**: `OutreachPersonalDataGuardTest` rechaza toda columna con `text` en el nombre; relajar la guarda no.
- **Modelo en `app/Models/OutreachConsent.php`**: `OutreachStaticGuardsTest` exige que no exista `app/Models/Outreach` (D9-8).
- **No reutilizo `outreach_forbid()`**: el `down()` de DB9 la borra (fallaría con disparadores ajenos) y la supresión apaga los de esa función.
- `request_uuid` **UNIQUE total** en vez de parcial: los NULL no chocan en ningún motor y SQLite no lo pierde al reconstruir.
- Añadidos: `actor_type` (el sistema solo retira), `client_request_id` NOT NULL, TRUNCATE en el REVOKE, la copia comprobada de la petición
  (sin ella el CHECK de cuatro ojos se burlaría copiando otro registrador), `ocon_grant_not_superseded`, rejected/revoked sin número ni
  texto legal (minimización: lo negativo vale para la persona), correo y series con separadores en la evidencia (las reglas del servicio).

## 2 · Cada invariante con su prueba negativa (`OutreachConsentsSchemaTest`, los dos motores salvo donde se dice)

| Invariante | Prueba negativa (en PG, además, «viola solo ese CHECK») |
|---|---|
| Conjuntos cerrados | `test_conjuntos_cerrados_…`: tipo `accepted`, finalidad `marketing`, canal `voice_call`, fuente `phone_call`, actor `robot` |
| La persona | `test_la_persona_…`: `x:`, clave de otro socio, `m:0`, prospecto con clave de socio, sin referencia |
| Cuatro ojos por cuenta | `test_cuatro_ojos_por_cuenta`: confirma quien registró; copia falsa del registrador; resolución sin quien resuelve |
| Una resolución por petición | `test_una_resolucion_…`: segunda confirmación, rechazo después (23505); petición inexistente, no-petición, otra persona, finalidad, fuente, número, versión, texto o rol |
| Petición solo en la resolución · idempotencia | `test_la_peticion_va_solo_…` · `test_idempotencia_…`: `client_request_id` y `uuid` repetidos (23505), `insertOrIgnore` = 0 |
| Evidencia sin teléfonos | `test_la_evidencia_…`: 7 seguidos, celular, vacía, > 300; solo PG: con espacios, con puntos y guiones, correo, salto de línea |
| Textos legales obligatorios | `test_los_textos_legales_…`: sin versión, sin huella, versión con espacios, huella en mayúsculas, rechazo o retirada con texto |
| El número, seudónimo · el sistema solo retira | `test_el_numero_…`: sin número, en claro, mayúsculas, retirada con número · `test_quien_registra_…` |
| La retirada gana | `test_una_peticion_anterior_a_una_retirada_ya_no_se_confirma` |
| Solo inserción | `test_solo_insercion_…`: UPDATE de las 16 inmutables, DELETE, TRUNCATE (PG), INSERT redactado |
| La única mutación | `test_la_unica_mutacion_…`: 7 redacciones parciales o falsas, otra vez, deshacerla, nombres a otro valor o donde no hay |
| Modelo sin modificaciones | `test_el_modelo_…`: save, update, delete, destroy, increment y, en bloque, update, delete, forceDelete, upsert, increment, updateOrInsert, truncate |
| `down()` y rol no dueño (PG) | `test_down_…`; `test_solo_en_postgres_el_rol_de_la_aplicacion_…`; `…up_falla…`; `…sin_el_rol…produccion` |

## 3 · Lo que se ejecutó de verdad (salidas finales)

- `OutreachConsentsSchemaTest`: **SQLite** `Tests: 21, Assertions: 236, Skipped: 4` (las 4 solo PG) · **PG 14** `OK (21 tests, 356 assertions)`.
  No son vacías: quitar los cuatro ojos o aflojar la redacción en la migración pone sus dos pruebas en rojo (restaurada, mismo hash).
- **Rol no dueño (PG, privilegios por defecto de producción)**: pedir, confirmar (la FK no pide UPDATE), retirar, `insertOrIgnore` y la tanda
  funcionan; UPDATE (también la redacción), DELETE, TRUNCATE, `FOR UPDATE/SHARE`, `ON CONFLICT DO UPDATE`, DISABLE TRIGGER, DROP CONSTRAINT/INDEX, reemplazar la función y `session_replication_role` → **42501**.
- `OutreachConsentSuppression1581Test` (PG, `psql`, dueño sin superusuario): `OK (1 test, 392 assertions)`. En seco 4 filas y 2 contadores;
  real: 4 filas a la forma suprimida, «ningún disparador apagado», 223 disparadores activos antes y después, 1 fila en el libro sin la
  clave ni los seudónimos; repetir no hace nada; por `:admin_id` el disparador de nombres salta y no se apaga.
- Sin regresiones: `CommercialSuppression1581Test` OK (366) y `VoiceSuppression1581Test` OK (1137) en PG; `tests/Feature/Outreach/Schema` +
  `OutreachPersonalDataGuardTest`: SQLite 345 tests/3045 aserciones/136 saltadas, PG 345/4511/1 (`OutreachReadIndexesTest`, CONCURRENTLY,
  previo); guardas estáticas `OK (141 tests, 182988 assertions)`.
- `OutreachSchemaTest` (DB9) contaba con `LIKE 'outreach\_%'`: ahora cuenta sus 8 tablas y cierra la familia en 8 + 1 tablas y 13 + 3 funciones.

## 4 · La consulta de la tanda (para C9-2) y su `EXPLAIN`

Referencia ejecutable: `tests/Feature/Outreach/Schema/OutreachConsentFixtures.php`, `CONSENT_BATCH_SQL` (los dos motores: VALUES y
ventana, sin DISTINCT ON ni unnest). **Un `granted` cuenta en el lugar de SU PETICIÓN**: lo pedido antes de una retirada no revive
por confirmarse después, ni en carrera ni con la retirada en la otra clave de la persona.
```sql
WITH s (person, subject_key) AS (VALUES (?, ?), …),          -- cada persona con TODAS sus claves (m: y sus l:)
ev AS (SELECT s.person, c.purpose, c.channel, c.kind, c.phone_ref, c.id,
              CASE WHEN c.kind = 'granted' THEN r.id ELSE c.id END AS pos
         FROM s JOIN outreach_consents c ON c.subject_key = s.subject_key
         LEFT JOIN outreach_consents r ON c.kind = 'granted' AND r.uuid = c.request_uuid
        WHERE c.channel = 'whatsapp' AND c.kind IN ('granted', 'revoked')),
ranked AS (SELECT person, purpose, channel, kind, phone_ref, id, ROW_NUMBER() OVER (PARTITION BY person, purpose, channel
             ORDER BY pos DESC, CASE WHEN kind = 'revoked' THEN 0 ELSE 1 END) AS n FROM ev)
SELECT person, purpose, channel, kind, phone_ref, id FROM ranked WHERE n = 1 ORDER BY person, purpose, channel
```
**Banco** (`ultron_studio_c9_bench`, borrado): 200 000 filas y 50 000 personas insertadas por las guardas reales; tabla 70 MB.
Tanda de 500 personas (600 claves), en caliente (`EXPLAIN (ANALYZE, BUFFERS)`, tercera ejecución):

| Variante | Plan | Tiempo | Búferes |
|---|---|---|---|
| **Sin índice** | Parallel Seq Scan + Hash Join | 69–147 ms | 12 440 (7 795 leídos) |
| Índice simple (15 MB) | el planificador elige el mismo Seq Scan | 44,0 ms | 12 440 |
| Índice simple, forzado | Nested Loop + Index Scan | 15,6 ms | 7 106 |
| Simple + `IN (…)` redundante | Bitmap Index Scan | 32,0 ms | 7 106 |
| **Cubridor (el elegido, 29 MB)** | Nested Loop + **Index Only Scan, Heap Fetches 0** + Index Scan `ocon_uuid_unique` | **9,6–11,1 ms** | **5 127** |

Con 1 200 parámetros vinculados por PDO (como Laravel): el mismo plan, 3,1 ms. La guarda de cada confirmación: 0,04 ms y 8 búferes con
el índice; **sin él, 69–147 ms** (recorre 150 000 filas por INSERT). Inserción (índice simple): 0,08 ms por petición, 0,17 ms por
confirmación. El cubridor ocupa ~70 B más por fila: despreciable con el volumen real (miles de filas) y escrituras a ritmo humano.

## 5 · Producción (no la he tocado; correrla exige autorización del propietario)

1. Rol **dueño** (`iron`) con `ironbody-migrate --path=<dir de root> --realpath`; nunca como `www-data` (42501). El fichero en
   `/var/lib/ironbody-deploy`, con su hash comparado con git. `ULTRON_STUDIO_DB_APP_ROLE` vacío → `iron_app`, que debe existir.
2. Antes y después: `h9-schema-check.sql` (bloque «C9-1»: `c9_tabla` f→t, 8 disparadores, 0 apagados, 13 CHECK, 1 FK, 4 únicos,
   índice cubridor t, 3 funciones sin DEFINER, `c9_app` `ra---s`, 0 filas). Respaldo fresco antes, como siempre.
3. **Orden:** sin dependencias (no tiene FK fuera de sí misma); cualquier momento tras el merge. **Bloqueo:** solo la tabla nueva y el
   catálogo; ninguna existente; `lock_timeout` 5 s. **Duración:** 11–34 ms en local.
4. **Vuelta atrás:** el código anterior no necesita revertir el esquema (la tabla es inerte). Con 0 filas, `migrate:rollback` de esa
   ruta como dueño borra tabla y funciones; con filas `down()` se niega. Con `--path`, `migrate:status` no la ve: mirar `migrations`.
   El plan (§2.3) recomienda no desplegarla antes de C9-0: lo decide el propietario.

## 6 · Lo que NO pude verificar

- Producción: `random_page_cost`, la herencia real de `iron_app`, el volumen y la migración allí. El lector, las rutas y `CoreProjection`: no son míos.
  En SQLite no se comprueban las series de dígitos con separadores, los correos ni los controles en la evidencia (solo PG).
- Carreras con dos conexiones (dos confirmaciones a la vez → 23505; confirmar y retirar a la vez): razonadas y cubiertas por el UNIQUE y
  la regla de lectura, **no ejecutadas**. Para `tester`, con el patrón de `OutreachConcurrencyTest`.

## 7 · Para los siguientes y hallazgos fuera de alcance

- **backend C9-3:** la resolución copia de la fila de su petición `subject_key`, referencias, finalidad, canal, fuente, `actor_type` y
  `recorded_by_*` exactos (y, al confirmar, número y texto legal); `insertOrIgnore` por `client_request_id`; nada de `FOR UPDATE` (42501).
  Validar la evidencia con `StudioCanaryPhone::CONTACT_DIGIT_RUN`, `CONTACT_EMAIL`, `PlainText(strict)` **y** `/\d{7,}/`: la base
  rechaza 7 seguidos que la regla de 8 del servicio deja pasar (sería un 500 en vez de un 422).
- **backend C9-2:** pasar todas las claves de cada persona (`m:` y sus `l:`: la base no sabe que son la misma); sin fila, ninguna
  autorización; las redactadas no casan (`x:`); comparar el `phone_ref` devuelto con el del número de destino.
- **security:** cuatro ojos por cuenta, no por persona; el sistema solo retira, pero la base no sabe si la aplicación dice la verdad en `actor_type`.
- Fuera de alcance: (a) el aviso «columna sin política» de `supresion-1581.sql` no mira `outreach_%`; (b) el contrato y el plan nombran
  `legal_text_*` y `app/Models/Outreach*`: conviene corregirlos; (c) mis pruebas de supresión dejan, como las existentes, sus roles
  `ultron_studio_1581o_*` en el clúster local; (d) si producción tiene `random_page_cost = 4` en SSD, otras lecturas pueden sufrir el
  mismo mal coste (hipótesis: no lo he medido allí).
