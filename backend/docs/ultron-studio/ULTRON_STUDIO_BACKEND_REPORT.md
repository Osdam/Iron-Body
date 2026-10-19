# ULTRON STUDIO: backend (Hito F, persistencia)

**Fecha:** 18-10-2026. **Estado: IMPLEMENTADO EN RAMA, NO DESPLEGADO.** Rama `feat/ultron-studio` del backend (commit `b4e07af` y siguientes). Las migraciones **no se han ejecutado en producción** y nada de esto está publicado. El contrato que se implementó está en `ULTRON_STUDIO_F_CONTRACT.md`; este informe describe lo que hay.

> **Actualización (19-10-2026).** Todo lo que va hasta «Despliegue (pendiente de autorización)» es el Hito F tal como se escribió antes de desplegarlo. **F está desplegado y verificado desde el 18-10-2026 (15:24–15:28 UTC, backend `9acddc3`)**: ver `ULTRON_STUDIO_FINAL_REPORT.md`. El **Hito G** está en la última sección de este informe: implementado en la rama `feat/ultron-studio-g`, **no desplegado**.

## Principios que se cumplen

- **Laravel conserva la autoridad.** El Studio es un cliente de administración del CRM: no reutiliza las rutas internas de n8n (`internal/marketing/*`) y ninguna ruta nueva cuelga de ellas.
- **El motor no cambia y no lee nada del Studio.** `git diff origin/main` sobre `app/Services/Marketing`, `app/Jobs`, `app/Http/Controllers/Api/Internal`, `routes/marketing.php`, `routes/api.php` y `config/marketing.php` sale vacío; una prueba de aislamiento (`UltronEngineIsolationTest`) vigila que `UltronStudio`, `ultron_studio_` y `config('ultron_studio` solo aparezcan en los ficheros del Studio.
- **Publicar no existe todavía.** `StudioPublishing::ENGINE_READS_VERSIONS = false` y la bandera `ULTRON_STUDIO_PUBLISHING_ENABLED` (apagada): `publish` y `rollback` responden 409 siempre (`publishing_disabled`, o `engine_not_reading_versions` si alguien enciende la bandera). Una versión `APPROVED` en el Studio no cambia al agente, y el `GET status` lo dice con esas palabras.
- **Sin Python.** El razonamiento sigue en n8n con su contrato firmado.

## Modelo de datos (`database/migrations/2026_10_18_1000{01,02}`)

| Tabla | Para qué | Protección |
|---|---|---|
| `ultron_studio_drafts` | Un borrador por persona (`UNIQUE admin_id`, FK a `admins` con borrado en cascada), con `revision`, `catalog_version`, `content` json y `content_hash` | Concurrencia optimista: `UPDATE … WHERE admin_id = ? AND revision = ?`; el primer guardado va en un SAVEPOINT |
| `ultron_studio_versions` | Copias inmutables del borrador (`definition`, `configs`, `layout`, `rules[]`) con huella, catálogo, creador como instantánea sin FK, `last_validation`, estado | Modelo (`updating` lanza si cambia un campo inmutable, `deleting` lanza) + disparadores `usv_forbid_immutable_update` (`BEFORE UPDATE OF` las columnas de contenido), `usv_forbid_delete`, `usv_forbid_truncate` + CHECK `usv_status_chk` (solo `DRAFT, VALIDATED, REVIEW_REQUIRED, APPROVED, REJECTED`) |
| `ultron_studio_version_reviews` | La decisión de cuatro ojos (`approved`/`rejected`, una por versión, revisor como instantánea, avisos reconocidos, validación y huella de lo revisado) | Solo inserción (disparadores de UPDATE/DELETE/TRUNCATE) |
| `ultron_studio_rules` | Reglas con estructura verificable: `code` (R-NN del código, S-NNN del Studio), `source`, `enforcement`, `status`, `layers`, `refs`, `revision` | CHECK: las del Studio nunca `active` ni con `enforcement = code`; las del código siempre `code`; disparadores de borrado (se archivan, no se borran) |
| `ultron_studio_audit_events` | Libro de solo inserción: quién, qué, cuándo, estados, huella, `diff` | Disparadores de UPDATE/DELETE/TRUNCATE; sin IP ni user agent (quedan NULL: libro sin política de retención, Ley 1581) |

Compatibles con PostgreSQL 14 y SQLite (la suite): tipo `json`, sin índices parciales, FKs creadas con la tabla. En SQLite los disparadores usan `RAISE(ABORT)`; los CHECK solo existen en PG (la prueba correspondiente se marca omitida en SQLite). Verificadas con sondas en los dos motores (`P0001`/`23514` en PG), `migrate:rollback` + `migrate`, `migrate:fresh` repetible, con un rol sin superusuario y con EXPLAIN ANALYZE bajo volumen de estrés (20.000 borradores, 50.000 versiones, 490.000 eventos de auditoría): borrador por persona y auditoría por sujeto por sus índices (< 1 ms); versiones por estado por `usv_status_idx` salvo cuando el estado es muy frecuente en la primera página, que PostgreSQL resuelve por la clave primaria (0,4 ms); el recuento del paginador de la auditoría, 19 ms. Con el volumen real (decenas de filas) se esperan escaneos secuenciales.

## Permisos (`ultron.*`, validados en Laravel ruta a ruta)

| Llave | Super Admin | Administrador | Recepción, Entrenador, Administrativo | Token compartido |
|---|---|---|---|---|
| `ultron.view` | ✓ | ✓ | — | — (dominio excluido) |
| `ultron.edit_draft`, `ultron.manage_rules`, `ultron.approve` | ✓ | ✓ | — | — |
| `ultron.publish`, `ultron.rollback` | ✓ | — (se conceden a mano desde Roles) | — | — |

Mecanismo: las 19 rutas están en `AuthorizationMap::OVERRIDES` (lo no mapeado se deniega), el catálogo deriva las llaves de las rutas (`PermissionCatalog`, dominio «ULTRON Studio»), y `CrmPermission::byRole()` reparte por rol en código (sin seed ni migración; `role_permissions` guarda solo excepciones). Toda llave de escritura exige además `ultron.view`; cada acción exige una persona (`requirePerson()`); los cuatro ojos comparan la identidad de la cuenta, no el rol. `ultron.manage_settings` se pospuso: una llave sin ruta rompe la prueba de cobertura. El CRM regenera su catálogo con `php artisan permissions:export`.

## API (`/api/admin/ultron-studio`, 19 rutas, camelCase, envoltura `{ok:true, data}`)

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET status` | view | Motor (`readsVersions:false`), publicación (bloqueada y por qué), permisos efectivos, versión y huella del catálogo, ciclo de vida con los estados alcanzables, reglas importadas |
| `GET catalog` | view | El catálogo autoritativo (`StudioCatalog`, versión `2026-10-18.1`): capas, puertos, aristas, caminos, restricciones, parámetros y la línea base `LEGACY_BASELINE_V1` |
| `GET draft` · `PUT draft` · `POST draft/discard` | view · edit_draft · edit_draft | El borrador de la persona con su validación en servidor; `baseRevision` y 409 `draft_conflict` con `current`; normalización estricta (422 con todos los errores) |
| `GET versions` · `POST versions` · `GET versions/{uuid}` | view · edit_draft · view | Lista paginada (`scope=all|mine|reviewable`), creación desde el borrador guardado (idempotente por `clientRequestId`; 422 `validation_blocked` con bloqueos), detalle con `integrityOk` y `actions` |
| `POST versions/{uuid}/validate` · `/submit` · `/approve` · `/reject` | view · edit_draft · approve · approve | Simulación sin escribir; envío por quien la creó (→ `VALIDATED` o `REVIEW_REQUIRED`); aprobación por otra persona reconociendo exactamente los avisos vigentes; rechazo con motivo |
| `POST versions/{uuid}/publish` · `/rollback` | publish · rollback | 409 siempre en esta fase (auditado, deduplicado) |
| `GET rules` · `POST rules` · `PATCH rules/{uuid}` · `POST rules/{uuid}/archive` | view · manage_rules | Reglas del código (solo lectura, 409 `legacy_rule_read_only`) y del Studio (nacen `draft` sin aplicación; tope de 100 vivas → 409 `rule_limit_reached`); `knowledgeKeys` validadas contra `marketing_knowledge_items` |
| `GET audit` | view | Libro paginado; los eventos de borrador solo los ve su dueño o quien tiene `audit.view` |

Errores: `{ok:false, code, message}` (403 `forbidden` con `required_permission`, `person_required`, `not_version_owner`, `four_eyes_required`; 404; 409 `draft_conflict`, `rule_conflict`, `invalid_transition`, `warnings_not_acknowledged`, `revalidation_blocked`, `integrity_mismatch`, `publishing_disabled`, `engine_not_reading_versions`, `legacy_rule_read_only`, `rule_archived`, `rule_limit_reached`, `client_request_reused`; 422; 429 `too_many_requests` con `retry_after`). Cupos: 120/min lectura y 30/min escritura por credencial, más 600/120 por red.

## Validación en el servidor y paridad con el CRM

`StudioGraphValidator` repite la semántica de `studio-graph.ts` (mismos ids de hallazgo, mismo orden, mismos textos). La paridad la garantiza `tests/fixtures/ultron-studio/validation-cases.json` (51 casos cuyo `expected` se generó ejecutando la implementación TS de referencia) con `StudioValidationParityTest` en PHP y el spec de paridad en el CRM; el catálogo PHP se compara dato a dato con el volcado del TS (`catalog-real.json`). `ultron-studio:export-fixtures` exporta al CRM el catálogo y los casos como fixtures de pruebas.

## Comandos

- `ultron-studio:import-baseline {--dry-run}`: importa las 12 reglas del código como `LEGACY_BASELINE_V1` (idempotente; sincroniza y audita cambios; nunca toca las reglas del Studio).
- `ultron-studio:export-fixtures {--path=}`: fixtures TS para el CRM.

## Pruebas

Unit 144 (catálogo, validador, paridad, normalizador, huellas, exportación) · Feature 93 (permisos por matriz de 19 rutas × roles, borrador, ciclo de vida, inmutabilidad, reglas, importación, auditoría, status/catalog, aislamiento del motor, endurecimientos de las revisiones: `UltronStudioHardeningTest`) · Access 150. En SQLite y en PostgreSQL 14 desechable. `auth:coverage --unmapped` vacío. Suite completa con los mismos fallos preexistentes de la línea base del día.

## Lo que el backend no deja cambiar desde el Studio

Dinero (precio, vendibilidad, descuentos, `PaymentFactGuard`, bandera de cobro: la validación bloquea `discountsAllowed`, `strictFacts=false`, el cobro sin su camino…), consentimiento, derivación humana, el orden de los 28 controles del commit, encender ULTRON, soltar el freno o mover el canario, y cualquier secreto. Los parámetros importantes piden confirmación en el navegador, pero el servidor revalida todo lo publicable sin fiarse del borrador.

## Despliegue (pendiente de autorización)

Orden: migraciones (aditivas; punto de no retorno: una vez usado el Studio, revertirlas destruiría la auditoría; para retirar el Studio sin perder historia se apaga la bandera del CRM) → código y `config:cache` (reaplicar `chown www-data`) → `ultron-studio:import-baseline` → CRM. Antes del uso real: decidir la separación del rol de base que migra del que ejecuta (hallazgo S1) y verificar `TrustProxies` para el cupo por red.

## Hito G: control funcional de las diez capas

**Fecha:** 18-10-2026 (hora de Bogotá; las correcciones `dfb30f5` y la Enmienda 2 son del 19-10-2026 en UTC). **Estado: IMPLEMENTADO EN RAMA, NO DESPLEGADO.** Rama `feat/ultron-studio-g` del backend, sobre `9acddc3` (= producción): `a3410d9` contrato · `7e6f11a` migración y fixture de coherencia · `f1a8682` núcleo puro · `5074f82` persistencia, API y aislamiento · `c8d9cf4` pruebas del tester · `dfb30f5` correcciones de las revisiones · `391cffb` Enmienda 2. Contrato implementado: `ULTRON_STUDIO_G_CONTRACT.md` (A1–A9 y Enmiendas 1 y 2). Sin dependencias nuevas: `composer.json`, `composer.lock`, `config/`, `bootstrap/` y `.env.example` no cambian.

**Qué añade.** El inspector dice, parámetro a parámetro, de dónde sale el valor real y quién lo cambia de verdad. El Studio **lee** (nunca escribe) el estado del motor. Las reglas del Studio ganan condiciones verificables, con detección de contradicciones antes de guardar. El historial se lee en llano y se pueden comparar versiones. **Nada de esto cambia al agente:** el motor sigue sin leer el Studio (`StudioPublishing::ENGINE_READS_VERSIONS = false`) y publicar y revertir siguen respondiendo 409.

### Migración `2026_10_19_100001_add_conditions_to_ultron_studio_rules`

- **Una sola columna:** `ultron_studio_rules.conditions json NULL`. `NULL` es una regla documental: todas las filas de F quedan así y conservan su huella. Sin CHECK (la forma y el tope de 4 KB los garantiza el servicio), sin `jsonb` y sin índice.
- **PostgreSQL 14:** `alter table "ultron_studio_rules" add column "conditions" json null`. Solo escribe en el catálogo (no reescribe ni lee filas) y pide un ACCESS EXCLUSIVE de milisegundos. Con el `lock_timeout` de 5 s de `ironbody-migrate`, si una transacción larga tiene la tabla, la migración falla limpia y se reintenta. Probado con un lector concurrente: falló a los 5,35 s con `55P03`, no quedó registrada y el reintento tardó 3,76 ms.
- **SQLite (la suite):** `ADD COLUMN` y, desde 3.35, `DROP COLUMN` son nativos y no reconstruyen la tabla (su `rootpage` no cambia en F → G → rollback → G). Por debajo de 3.35, Laravel reconstruye la tabla al quitar la columna, y la reconstrucción se lleva `usr_forbid_delete`. Por eso la migración lee de `sqlite_master` los `CREATE TRIGGER` de la tabla antes del cambio y **vuelve a ejecutar los que falten**, todo en una transacción. Probado forzando la versión 3.34: sin esa guarda quedaban 6 disparadores y un DELETE pasaba; con ella quedan 7, con el texto idéntico al de F, y el DELETE se rechaza (23000).
- **Recuentos verificados** por el database-specialist en cuatro estados (F, tras G, tras `migrate:rollback --step=1` y tras G otra vez): SQLite, **7** disparadores; PostgreSQL, **11** disparadores y **11** CHECK. `pg_dump -s` cambia en una sola línea (`conditions json,`).
- **Rol solo-DML.** Se reprodujo en local el modelo de `iron_app`, con un dueño sin superusuario (no se probó en producción). La aplicación lee y escribe la columna sin ningún GRANT nuevo y sigue sin poder hacer TRUNCATE ni ALTER (42501). A la migración le basta con ser dueña de la tabla: funciona sin `CREATE` en `public`.
- **`down()` quita la columna y su contenido** (las condiciones de las S-NNN y de las R-xx sincronizadas). Volver a F no lo necesita: la columna se queda y F la ignora. En producción no se revierte sin autorización explícita.

### Catálogo `2026-10-19.1`

- `StudioCatalog::VERSION = '2026-10-19.1'`. Su huella (`0c8a26bc…`) queda fijada en `StudioCatalogTest::HUELLAS` junto a la de F (`704dd094…`).
- **Los datos del grafo no cambian:** los 51 `expected` de `validation-cases.json` son idénticos; solo cambia su `catalogVersion`.
- **Lo nuevo de `GET catalog`:**
  - `bindings`: un `ParameterBinding` por cada uno de los 21 parámetros (`origin`, `valueSource`, `declaredValue` o `engineOption`, `comparable`, `effect`, `changeVia`);
  - `readings`: los 5 descriptores de las lecturas extra;
  - `legacyConditions`: un objeto por código (R-01, R-02, R-07 y R-12);
  - la clave `rule` en cuatro restricciones (`hechos` → R-01, `descuentos` → R-02, `derivacion` → R-07, `canario` → R-12), que el validador ignora.
- **Único cambio de textos de F:** `intent.driver` ya no dice «no verificado», y la opción `entorno` pasa a llamarse «Según el entorno».
- **`StudioCatalogTest` vigila:**
  - un `binding` por parámetro, y ninguno sin parámetro;
  - `live` solo en los parámetros que lee `StudioEngineReadings::PARAMS`;
  - ningún texto con rutas, nombres de variables de entorno ni comandos (la lista de F más `MARKETING_`, `ULTRON_STUDIO_`, `.env` y `config(`);
  - cada R-xx equivale a su restricción, y las 12 son coherentes entre sí;
  - la transcripción de F solo cambia donde G lo declara.

**El hecho incómodo: qué controla de verdad cada parámetro del inspector** (`StudioCatalog::parameterBindings()`):

| Origen | Cuántos | Parámetros | Qué significa |
|---|---|---|---|
| `env_flag`: mando real del motor | 4 | `intent.driver`, `rules.paymentLinksEnabled`, `governance.enabled`, `governance.canaryOnly` | Existen en el motor, pero **se cambian en el entorno del servidor (despliegue), no en el Studio**. El Studio lee su valor en vivo |
| `engine_data`: dato del motor | 1 | `strategy.offerPriority` | Sale de los planes vendibles (módulo de Planes). Se lee en vivo, solo los nombres, y no se compara con el borrador |
| `fixed`: fijo | 11 | 8 con valor declarado (`knowledge.strictFacts`, `context.windowTurns` = 10, `memory.useLeadProfile`, `strategy.discountsAllowed` = no, `rules.handoffOnlyCorroborated`, `integrations.channel` = WhatsApp, `integrations.appointments` y `governance.autoBrake`) y 3 sin valor único (`knowledge.sources`, y `composer.critic` y `composer.retry`, que viven en el workflow de n8n) | Fijos en el código del motor o en n8n: ningún borrador los cambia |
| `proposal`: sin equivalente en el motor | 5 | `persona.tone`, `persona.emojis`, `persona.maxChars`, `intent.fallback`, `strategy.goal` | No tienen mando: salen de los prompts de n8n (el tono, también de los ítems `tone` y `brand_copy` de la base de conocimiento). En el Studio son propuestas |

Es decir: **ninguno de los 21 parámetros se cambia desde el Studio.** De ellos, 5 se leen del motor en vivo (`valueSource: live`), 8 tienen un valor declarado que repite lo que hace el código y 8 no tienen un valor único que enseñar.

### `StudioRuleCoherence`: condiciones verificables (núcleo puro)

**Modelo.** `conditions` es `null` o `{when, then}`:
- `when`: de 0 a 3 predicados, en conjunción (Y).
- `then`: de 1 a 3 afirmaciones, y todas deben cumplirse. Cada una lleva `kind`: `require`, `forbid` o `limit` (equivale a `require`; solo con `lte`/`gte` sobre un `range`).
- Tipos de predicado:
  - `config`: capa, clave, `op` y valor;
  - `edge`: `present` o `absent`;
  - `text`: solo en `then`, `contains` con 1 a 10 frases de 2 a 60 caracteres. Se guarda y **no se evalúa**, porque en el Studio no hay texto.
- Operadores por tipo de parámetro: toggle `eq`; select `eq`, `in` y `not_in`; range `eq`, `lte` y `gte`; tags `contains` y `not_contains`; `text` y `textarea`, ninguno (422).
- Cada predicado es una variable con un conjunto permitido dentro de su dominio. El dominio de una arista obligatoria solo admite `present`.

**Canonicalización (lo que se guarda):**
- Claves exactas, y ninguna repetición sobre la misma variable.
- `layers` tiene que incluir las capas de todos los objetivos, también los de `when`. A una arista le basta con una de sus dos capas.
- `conditions` cabe en **4096 bytes** UTF-8 de JSON compacto. Las longitudes se miden en puntos de código.
- `values` va en el orden del catálogo. Etiquetas y frases se recortan con el mismo conjunto de espacios que `String.prototype.trim()`.
- Las frases colapsan los espacios y pasan a minúsculas solo en A–Z y ÁÉÍÓÚÜÑ. Sin `ext-intl`, que producción no tiene.
- Ninguna expresión regular de usuario.

**Controles del motor.** Son pseudorreglas que salen del código, haya corrido o no la importación:
- las restricciones de nivel `blocking` del catálogo;
- las aristas obligatorias (`edge:<id>`);
- las `legacyConditions`.

Los avisos `apagado`, `cobro-aprobacion` y `cobro-sin-bandera` no son controles.

**Al escribir** (`POST rules` y `PATCH rules/{uuid}` de una S-NNN):
- **422** estándar de Laravel, con todas las claves a la vez (`conditions.*`, `layers` y `refs.*`). Cubre la forma, la afirmación imposible, el `when` que nunca se cumpliría, la autocontradicción y **la contradicción con un control**. Los mensajes son los literales del contrato, por ejemplo `Contradice R-02 (la aplica el código): «Puede ofrecer descuentos» tiene que ser «no».` Si chocan a la vez una restricción y su R-xx, salen los dos mensajes.
- **409 `rule_contradiction`** si contradice otra S-NNN viva: `{ok:false, code, message, conflicts:[{code, uuid, revision, target, label}]}`. No se audita.
- Las R-xx siguen respondiendo 409 `legacy_rule_read_only`.
- En `PATCH`, sin la clave `conditions` no cambian, y con `null` se quitan. Un `PATCH` que solo cambia `layers` comprueba únicamente la cobertura de capas.

**Limitación declarada: contradicción por pares.**
- Dos reglas se contradicen si se cumplen las dos condiciones:
  - toda variable común a sus `when` tiene intersección;
  - hay dos afirmaciones sobre la misma variable cuya intersección es vacía.
- **No encadena reglas.** Si X implica v, v implica w y w implica no-X, no se detecta. Hay una prueba explícita de esa limitación en PHP y otra en TS.
- La Enmienda 1 congela dos consecuencias:
  - dos reglas cuyos `when` no comparten variables se contradicen aunque nunca coincidan;
  - exigir que falte una arista de cobro sin `when` choca con `cobro-sin-camino`. Para proponerlo hay que escribir `when paymentLinksEnabled = no`.

**Análisis** (`analysis` en cada regla):
- `verification`:
  - `studio`: alguna afirmación de configuración o de arista;
  - `lab`: solo texto, comprobable en el laboratorio del Hito 4;
  - `none`: sin condiciones.
- `issues`: `regla-incompatible`, `regla-contradice-control` y `regla-contradice-regla` son de bloqueo; `regla-inaplicable` y `regla-vacia`, de aviso.
- Las R-xx nunca reciben contradicciones.
- Una regla **archivada** tiene un análisis neutro, `{verification, issues: []}` (Enmienda 2).

**Evaluación contra un contenido.** Los hallazgos de reglas se añaden al final de los de F:
- `regla-incompatible-<code>` y `reglas-contradictorias`, de bloqueo;
- `regla-<code>`, de **aviso**: una S-NNN es una propuesta que el motor no aplica;
- `reglas`, ok;
- `reglas-sin-aplicacion`, de aviso.

Con qué reglas se evalúa:
- el borrador, con las reglas vivas;
- una versión de formato 2, con **sus** reglas copiadas;
- una de formato 1, sin hallazgos de reglas.

Consecuencia de producto: toda versión con alguna S-NNN queda en `REVIEW_REQUIRED` por `reglas-sin-aplicacion`.

**Paridad con el CRM.**
- **El fixture.** `tests/fixtures/ultron-studio/coherence-cases.json` (versión 1) son **123 casos escritos a mano por el tester desde el contrato**; ninguna implementación los generó. Se reparten en 55 `canonicalize`, 16 `analyze`, 33 `conflicts` y 19 `evaluate`, y los acompaña `coherence-cases.README.md`.
- **Quién lo comprueba.** `StudioCoherenceParityTest` (PHP) y `studio-rules-coherence.parity.spec.ts` (TS) exigen el `expected` exacto. En TS son 124 pruebas: los 123 casos más una sobre el propio fichero. Si una implementación discrepa, se corrige ella.
- **Exportación.** `ultron-studio:export-fixtures` exporta ahora tres ficheros: el catálogo, los 51 casos de validación y los 123 de coherencia.

### `StudioContentDiff` y `GET compare`

`StudioContentDiff::compare(a, b, catálogo)` es una función pura sobre dos contenidos con la forma de una versión. Devuelve:
- `same`: sin diferencias estructurales en aristas, configuración ni reglas. No compara huellas entre catálogos distintos, y la disposición no cuenta;
- `edges`: las aristas añadidas y quitadas, con su etiqueta;
- `configs`: en el orden del catálogo, y después lo desconocido, que se enseña en crudo;
- `rules`: `added`, `removed` y `changed`, con las revisiones y los `fields` en el orden `source, enforcement, status, description, type, layers, priority, refs, conditions`;
- `warnings`: los avisos de cada lado, validados con el catálogo **actual** y cada versión según su formato;
- `catalogChanged`.

No tiene paridad: lo calcula solo el servidor y el CRM lo pinta. Prueba: `StudioContentDiffTest` (8).

### Huellas por formato (`StudioContentHasher`)

- **`ruleHash`** incluye `conditions` **solo cuando no es null**. Así, las reglas de F conservan su huella, y la de las R-xx con condiciones cambia al sincronizarlas, que es lo esperado.
- **Importación sobre las filas de F.** `import-baseline` sincroniza **8 de las 12** R-xx, las que ganan `conditions` o aristas en `refs.edges`: R-01, R-02, R-04, R-05, R-07, R-09, R-10 y R-12 pasan a la revisión 2, con 8 eventos `rule.legacy_synced`. R-03, R-06, R-08 y R-11 no cambian: siguen en la revisión 1. Una segunda importación no hace nada. Lo fija `ImportLegacyBaselineCommandTest::test_las_filas_de_f_se_sincronizan_con_las_condiciones_y_aristas_de_g`, y el tester lo vio en la simulación F → G: «0 nuevas, 8 sincronizadas, 4 sin cambios».
- **`versionHash`** depende del formato:
  - **formato 1:** exactamente el de F (`VERSION_RULE_FIELDS`);
  - **formato 2:** además, `conditions` en cada regla (null incluido) y `format` dentro del comportamiento.
- **Formato desconocido.** `format()` lee `content.format ?? 1` y rechaza cualquier otro valor. En la API, el detalle da `integrityOk:false`, y `validate`, `submit`, `approve`, `reject` y `compare` dan 409 `integrity_mismatch`, nunca un 500.
- **Vectores.** El de formato 1 es `667c9168…`, y el tester obtuvo **esa misma huella** con una versión creada por la API real de F. Los de formato 2 se calcularon con Node, sin depender de PHP.

### `StudioEngineReadings`: lecturas del motor, solo lectura

`GET engine/readings` devuelve `{readAt, params, extras, note}`.

`params` son los cinco parámetros `live` (`StudioEngineReadings::PARAMS`):
- `intent.driver`: `hermes`, `openai` o `rules`. El `fake` del motor se lee como `rules`;
- `rules.paymentLinksEnabled`;
- `governance.enabled`;
- `governance.canaryOnly`: si hay canario, **nunca cuál**;
- `strategy.offerPriority`: los nombres de los planes vendibles, la insignia primero, 20 como mucho.

`extras` son las lecturas que no son parámetros (`StudioEngineReadings::EXTRAS`):
- `governance.paused` y `governance.brake`;
- `rules.paymentCanary`: si está configurado, nunca el id;
- `knowledge.base`: `{version, activeByCategory, pendingReview}`, con las 14 categorías aunque estén a 0 y sin contenido;
- `strategy.plans`: `[{name, recommended}]`, 20 como mucho.

`note` dice siempre «Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician.»: las banderas son las de la caché de configuración del proceso web.

**Cómo lee:**
- Con los mismos cortes que el motor: `(bool)` en las banderas, `!== null` en el canario y `(int) > 0` en el canario del cobro.
- Solo con métodos de lectura: `effectiveDriver`, `version`, consultas de modelo con sus scopes y `config()` con una clave.
- La pausa y el freno, con la misma consulta que el motor pero **sin su red de seguridad**: `MarketingAgentSetting::find(MarketingAgentSwitch::ROW_ID)` y `UltronAbort::open()->exists()`. El motor usa `UltronAbortLatch::current()`, que se traga cualquier `QueryException`: el Studio habría enseñado «freno suelto» como un dato leído (I-1 de security, corregido en `dfb30f5`).

**Fallo suave.** Cada lectura va en su propio `try`. Si falla:
- esa entrada sale `{available:false}`, sin mensaje;
- el log recibe una línea `ultron_studio.engine_reading_unavailable` con el id y la clase del error, nunca el mensaje;
- las demás lecturas siguen.

Nunca hay un 500 por una lectura parcial.

**Nunca sale:** la URL del webhook, secretos ni claves, ids de conversación (ni el del canario ni el del canario del cobro), teléfonos, precios, ids de planes ni el contenido de la base de conocimiento. `UltronStudioEngineReadingsTest` siembra valores reconocibles y comprueba que no aparecen.

**Frontera del motor.** `StudioEngineReadings` es la única clase nueva de `app/Services/UltronStudio/` que nombra clases del motor; aparte de ella, solo lo hace `StudioRules`, que ya leía la base de conocimiento. Lo vigila `UltronStudioEngineReadOnlyTest` (ver «Aislamiento del motor»).

### API: 21 rutas

Las 19 de F conservan su permiso y su cupo. Desde G, las 21 llevan el tope de cuerpo de 64 KB (`LimitRequestBodySize:65536`; ver «Correcciones de las revisiones»).

Las dos rutas nuevas:
- van en `throttle:ultron-studio-read`;
- exigen `ultron.view` en `AuthorizationMap::OVERRIDES`;
- no añaden llaves: `PermissionCatalog` no cambia, y `permissions:export` sale idéntico al catálogo del CRM (92 permisos).

| # | Ruta | Permiso | Respuestas |
|---|---|---|---|
| 20 | `GET engine/readings` | view | 200 en cuanto pasa la puerta (una fuente caída es `{available:false}`); 401/403 de la puerta |
| 21 | `GET compare?from=&to=` | view | 200; 422 si falta un lado, si un lado no es `baseline`, `draft` ni un uuid, si los dos son iguales (sin distinguir mayúsculas) o si llega un array; 404 `version_not_found`; 409 `integrity_mismatch` solo con una versión de formato desconocido |

Los lados de `compare`:
- cada uno es `{kind, uuid, label, catalogVersion, format}`, con `uuid` siempre presente y con valor solo en una versión;
- `baseline` es la línea base del código con las reglas de `legacyRules()`, en la revisión que dejaría `import-baseline`;
- `draft` es **siempre el borrador de quien pide**, o la línea base si no tiene;
- un uuid solo se busca entre las versiones: el de un borrador ajeno da 404.

Cambios en las rutas de F:

| Ruta | Cambio |
|---|---|
| `GET catalog` | Versión `2026-10-19.1`; `bindings`, `readings`, `legacyConditions` y `rule` en las restricciones |
| `GET draft`, `PUT draft`, `POST draft/discard` | `uuid` del borrador (null sin fila); `validation.findings` añade al final los hallazgos de reglas, con las reglas vivas |
| `POST versions` | Crea `content.format = 2` con `content.rules[].conditions`; 422 `validation_blocked` también con hallazgos de reglas de bloqueo |
| `GET versions/{uuid}`, `validate`, `submit`, `approve`, `reject` | Hallazgos según el formato; el detalle trae `content.format` (1 en las de F) |
| `GET rules`, `POST rules`, `PATCH rules/{uuid}`, `POST rules/{uuid}/archive` | `conditions` (canónica o null) y `analysis` en cada regla; 422 nuevos y 409 `rule_contradiction` al escribir |

Errores nuevos:
- 409 `rule_contradiction`;
- desde `dfb30f5`, **413 `payload_too_large`** (`{ok:false, code, message}`) en las 21 rutas. `PUT draft` de más de 64 KB pasa de 422 a 413.

**Formato 2 y compatibilidad con F**

| Situación | Resultado |
|---|---|
| Versiones creadas en F (formato 1) | Se leen, validan, envían y aprueban como en F: sin hallazgos de reglas y con la integridad de F. El tester lo comprobó con tres versiones creadas por la API real de F (V1 sin enviar, V2 enviada y V3 aprobada). Tras migrar e importar, la API de G validó V1 igual que F, la envió y la aprobó; aprobó también V2, con los mismos avisos de F (`opcional-E14`, `opcional-E16`), y V3 siguió íntegra. Las huellas quedaron intactas |
| Backend de G con el CRM de F (ventana del despliegue) | Funciona: el parser de F ignora las claves nuevas y los hallazgos del servidor mandan |
| CRM de G con el backend de F | Lo impide el orden de despliegue: el CRM exige `bindings` y queda en fase `error` (falla cerrado) |
| Volver el backend a F después de usar G | Las versiones de formato 2 darían `integrityOk = false` (se leen, pero no se envían ni se aprueban). La columna se queda. Las R-xx se resincronizan con el `import-baseline` de F. **No se ha ejecutado:** ver `ULTRON_STUDIO_ROLLBACK.md` |

### Auditoría

- **No hay eventos nuevos:**
  - `rule.created` lleva `conditions` en su `diff`;
  - `rule.updated` la lista como un campo más;
  - `rule.legacy_synced` enseña el cambio de `conditions` y `refs` de las R-xx al importar.
- **Cota por evento.** `StudioAudit::record()` gana un parámetro de cota: **500** caracteres en `rule.*` (`MAX_RULE_VALUE_LENGTH`, la longitud de `description` y de `archive_reason`) y 120 en el resto, como en F. Los eventos anteriores a G quedan recortados a 120, y la vista del CRM lo dice.
- **No se auditan** `GET engine/readings`, `GET compare`, el análisis, los 422 ni el 409 `rule_contradiction`: en ninguno cambia nada.
- Sigue sin IP ni user agent, y sin datos de prospectos.

### Cerrojos y concurrencia

- **Un solo cerrojo para las tres escrituras.** `create`, `update` e `importLegacyBaseline` toman el mismo cerrojo de transacción antes de leer las reglas vivas: `pg_advisory_xact_lock(crc32('ultron_studio_rules:code'))`, en `serializeRuleWrites()`. Lo precede `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` (B-2).
  - Solo en PostgreSQL: SQLite ya escribe en serie.
  - Archivar no lo toma: quitar una regla no crea contradicciones.
- **Importación.** Lee la tabla dentro de la transacción y tras el cerrojo, así que dos importaciones a la vez ya no chocan con el UNIQUE.
- **Orden de `create`:**
  1. repetición por `clientRequestId`;
  2. transacción y cerrojo;
  3. repetición otra vez (H-3);
  4. tope de 100 (409 `rule_limit_reached`);
  5. contradicción (409 `rule_contradiction`);
  6. inserción.

  La validación (422) va antes de todo.
- **Carreras reales.** El tester las corrió sobre `5074f82` con la pila real (`artisan serve --no-reload` con 16 workers y PostgreSQL 14), con una barrera que solo se suelta cuando `pg_blocking_pids` ve las dos peticiones bloqueadas y un escalón de 40 ms. Fueron 17 carreras, cada una con 20 repeticiones naturales y 20 forzadas válidas, sin un solo resultado incorrecto salvo RG8b (H-3). Escenarios:
  - dos `POST` contradictorias, `POST` contra `PATCH` y dos `PATCH`;
  - `POST` y `PATCH` contra `import-baseline`, y dos importaciones a la vez;
  - la misma `baseRevision` y el primer guardado del borrador;
  - doble `POST versions`, y archivar contra editar;
  - el tope de 100: nunca hubo 101 vivas.
- **Solo PostgreSQL caza la falta del cerrojo.** Si se quita (en `update`, en `create` o en la importación), la suite SQLite sale verde (H-2). Por eso el grupo del Studio en PG es **puerta obligatoria** del despliegue.

### Correcciones de las revisiones (`dfb30f5`)

| Hallazgo | Corrección | Prueba que la fija |
|---|---|---|
| M-1 (api-security) | Una regla archivada recibe `analysis` neutro, y `GET rules?status=archived` no lee ni analiza las vivas. Con 100 vivas pesadas y 300 archivadas, la petición pasa de 2371 a 27 ms (SQLite local) | `UltronStudioRuleConditionsTest::test_listar_las_archivadas_no_relee_ni_reanaliza_las_vivas` |
| M-2 (api-security) | Validación en dos fases (`App\Http\Controllers\Concerns\ValidatesListSizesFirst`) en `POST rules`, `PATCH rules/{uuid}` y `approve`, también en las vías de F (`layers.*`, `refs.*` y `acknowledgedWarnings.*`): primero el tamaño de cada lista y después cada elemento; una lista por encima de su tope da un 422 solo con su clave. Además, tope de cuerpo de 64 KB (`App\Http\Middleware\LimitRequestBodySize`) en las 21 rutas, que responde 413 | `UltronStudioHardeningTest`: 413 sin crear ningún validador, 422 de cada lista en menos de 50 ms y el borrador más grande que admite el normalizador cabe |
| B-2 (api-security) | `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` como primera sentencia de las escrituras de reglas en PostgreSQL | `test_crear_editar_e_importar_fijan_read_committed_aunque_la_sesion_vaya_en_repeatable_read` (solo PG) |
| H-3 (tester; preexistente en F) | `replay()` también dentro de la transacción, tras el cerrojo y antes del tope: un doble clic con 99 vivas da 201 + 200 `replayed` | `UltronStudioHardeningTest::test_el_doble_clic_simultaneo_con_99_vivas_es_replay_y_no_el_tope` |
| H-1 (tester) | `UltronStudioEngineReadOnlyTest` escucha cada sentencia (`DB::listen`) y falla ante cualquier escritura fuera de `ultron_studio_*` y `admin_sessions`, o ante cualquier DDL, aunque su efecto se deshaga. La prueba del tester `UltronStudioGEngineWritesTest` se retira por redundante | `test_recorrer_todas_las_rutas…` |
| I-1 (security) | Pausa y freno con la consulta directa: si falla, `{available:false}` | `UltronStudioEngineReadingsTest` (2 pruebas) |
| I-2 (security) | La prueba estática cubre también controladores, modelos y comandos del Studio | `test_controladores_modelos_y_comandos_del_studio_no_escriben_el_motor` |

Cada corrección se comprobó con su mutación: volver al código anterior hace fallar su prueba. El detalle de las revisiones está en `ULTRON_STUDIO_SECURITY_REPORT.md`.

### Aislamiento del motor

- **Sin cambios en el motor.** `git diff origin/main` sobre `app/Services/Marketing`, `app/Jobs`, `app/Http/Controllers/Api/Internal`, `routes/marketing.php`, `routes/api.php`, `config/`, `bootstrap/`, `app/Models/Plan.php`, `MarketingKnowledgeItem.php`, `MarketingAgentSetting.php` y `UltronAbort.php` da **0 líneas** (comprobado en `391cffb`).
- **`UltronStudioEngineReadOnlyTest`, parte estática.** Recorre por tokens los servicios, controladores, modelos y comandos del Studio, y exige:
  - que entre los servicios solo `StudioEngineReadings` y `StudioRules` nombren el motor, y que el controlador de reglas solo use la constante de categorías del conocimiento;
  - que no haya ninguna llamada de escritura sobre clases del motor.
- **`UltronStudioEngineReadOnlyTest`, parte de comportamiento.**
  - Siembra la pausa, un freno, conocimiento y planes.
  - Con `Queue`, `Http`, `Mail` y `Notification` falseados, recorre las 21 rutas con escrituras reales: cada parámetro distinto de la línea base, reglas con condiciones, versión, envío, aprobación por otra persona, rechazo, publicar y revertir (409), lecturas y comparación.
  - Exige la misma huella de todas las tablas ajenas al Studio y de `config()`, ninguna escritura SQL fuera del Studio, nada encolado y ninguna petición saliente.
- **`UltronEngineIsolationTest`** (el motor no nombra el Studio) sigue en verde, con la migración nueva en `ALLOWED` y 21 rutas.

### Pruebas

| Ejecución | Resultado |
|---|---|
| `tests/Unit/UltronStudio` en el núcleo (`f1a8682`) | 311 pruebas, 3473 aserciones (incluye los 123 casos de coherencia y los 51 de validación de F) |
| Studio + Unit + Access en SQLite, tras la API (`5074f82`) | 601 pruebas, 2 omitidas (solo PG) |
| Studio + Unit en PostgreSQL 14, tras las pruebas del tester (`c8d9cf4`) | 460/460, 0 omitidas |
| **Studio + Unit + Access en SQLite, final (`dfb30f5`)** | **618 pruebas, 7836 aserciones, OK, 5 omitidas** (las que solo corren en PG). Repetido sobre `391cffb` el 18-10-2026 (hora de Bogotá) al escribir este informe: el mismo resultado |
| **Studio + Unit en PostgreSQL 14, final (`dfb30f5`)** | **468/468, 7297 aserciones, 0 omitidas**. Incluye las dos pruebas de cerrojo con dos sesiones, la del orden del SQL y la de READ COMMITTED |
| Suite completa en SQLite (`dfb30f5`) | 9589 pruebas: 55 errores y 3 fallos, **los mismos 58 de la línea base** comparados nombre a nombre (0 nuevos, 0 que dejan de fallar, ninguno del Studio). La línea base es del 17-10-2026 a las 22:15, no del mismo día: los días 18 y 19 no caen en la ventana de calendario de Reports (días 1–10) |
| `auth:coverage --unmapped` | Vacío (repetido en `391cffb`) |
| `route:list --path=admin/ultron-studio` | 21 rutas, las 21 con `LimitRequestBodySize:65536` (repetido en `391cffb`) |
| E2E con la pila real (tester; backend `391cffb` con `artisan serve` y PostgreSQL 14, CRM `7d3ad2a`, Chrome) | 124/126. Los 2 fallos son del CRM (H-1 del E2E). La parte del backend pasa entera: lecturas con fuente caída, freno sin tabla como no disponible, 201/409/422 de reglas, formato 2, cuatro ojos, publicar en 409, 403 por permiso y la huella de las tablas del motor y de las colas igual al terminar |

**Mutaciones:**
- del paso de la API, 8: escrituras con métodos dinámicos, `forceFill`, `config([...])`, `Queue::push`, `Http::get`, escritura en el conocimiento, un tercer fichero que nombra el motor y quitar el cerrojo de `update`;
- del tester, 19 variantes, de M1a a M11: 18 cazadas por al menos una prueba. Las tres del cerrojo (M1a–M1c) solo las caza PostgreSQL (H-2). M10 es un mutante equivalente: la exclusión de las R-xx se defiende en tres sitios, y quitar las tres guardas (M10b) sí se caza;
- de las correcciones, 11.

Ninguna quedó aplicada: en `391cffb` el árbol está limpio y los tres puntos de llamada de `serializeRuleWrites()` están en su sitio.

**Lo que no se verificó:**
- la carrera RG8b (H-3) con la pila real **después** de la corrección (la cubre una prueba determinista en una conexión);
- la vuelta del backend a F después de usar G;
- la migración y los tiempos en producción;
- la suite completa en PostgreSQL (solo los grupos del Studio).
