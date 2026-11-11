# ULTRON STUDIO · Hito 5 (gobierno y versionado): contrato congelado

**Fecha:** 24-10-2026 (noche, hora de Bogotá; 25-10 UTC). **Base:** backend `eeee85c` (código `18dae99`) y CRM `5c1dbd7`, ambos = `origin/main` = producción (Hito 4 desplegado en fases 1–2). **Rama de trabajo:** `feat/ultron-studio-h5` en los dos repos. **Origen:** plan del `architect` `plans/ULTRON_STUDIO_H5_PLAN.md` (§0–§4, §6–§12); el diseño A1–A13 se copia abajo tal cual y es lo que se implementa.

## Decisiones del lead (preguntas §11 del plan)

| # | Pregunta | Decisión |
|---|---|---|
| 1 | ¿A (declaración verificada + sello por turno) o B (además un «apagado» aplicado)? | **A.** El motor lee UNA tabla del Studio desde UNA clase de solo lectura (`UltronPublishedConfig`), con su llave de entorno `MARKETING_ULTRON_STUDIO_CONFIG = off\|canary\|all` (`off` por defecto), verifica firma y contrato y **sella cada turno** en `marketing_ai_actions.metadata.studio`. Ningún parámetro cambia lo que ULTRON dice o hace; el Studio vigila la deriva. B queda como contrato futuro `ultron-engine-config/2` solo si el propietario pide un apagado a cuatro ojos |
| 2 | Puerta del laboratorio para publicar | **`required` por defecto en el código** (veredicto `approved` sobre la misma huella y la versión de prompts vigente); el propietario puede poner `waived` en el entorno de forma explícita y queda escrito en cada publicación. Camino recomendado: cerrar la fase 3 del Hito 4 antes de la fase 2 de este hito |
| 3 | Canario obligatorio y umbrales | **Sí:** 24 h y 3 turnos sellados por defecto, configurables con mínimos duros (1 h, 1 turno) |
| 4 | Criterio de cierre | `HITO_5_COMPLETED` solo con las fases 1–3 ejecutadas y su evidencia; si una dependencia externa (autorización, tráfico del canario, puerta del laboratorio) lo impide, `HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` |
| 5 | ¿Reutilizar `RETIRED` («Sustituida»)? | **Sí**; no se añade `SUPERSEDED` |
| 6 | ¿Quien publica ≠ también de quien aprobó? | **No:** basta ≠ autor (dos personas) |
| 7 | Ventana de publicación 07:00–19:00 Bogotá | **Sí** para publicar y promover; revertir y Legacy, siempre |
| 8 | ¿Revertir sin `ULTRON_STUDIO_PUBLISHING_ENABLED`? | **Sí:** deshacer no depende de la llave que permite cambiar |
| 9 | ¿Absorber el `READ COMMITTED` de `StudioAudit::recordDenied`? | **Sí** (A10.6), con su prueba en PG |
| 10 | Estado real del motor hoy (verificado por el lead en producción, solo lectura, 25-10 02:05 UTC) | `ultron.enabled = true`, `payment_links_enabled = false`, canario de conversación **configurado**, canario del cobro configurado, driver efectivo `openai`; **3 turnos de ULTRON en los últimos 7 días** (poco tráfico: la evidencia del canario se acumulará despacio). La primera versión publicable declarará `governance.enabled: true`, `governance.canaryOnly: true`, `rules.paymentLinksEnabled: false`, `intent.driver: entorno` |

**Protecciones intocables:** publicar sigue en 409 hasta la fase 2 y nunca se levanta sin autorización expresa del propietario; el motor jamás escribe en `ultron_studio_*` ni importa clases del Studio; el Studio jamás escribe en tablas, configuración ni ficheros del motor ni toca n8n; nada enciende ni apaga ULTRON, mueve el canario ni toca el cobro; las reglas S-NNN siguen sin efecto; sin llamadas, Meta, ElevenLabs ni gasto; migraciones aditivas (`down()` se niega con filas de H5); el libro y la cadena de publicaciones son de solo inserción; sin ids de conversación, textos ni datos personales en la evidencia; la puerta de PostgreSQL (`docs/ultron-studio/ops/pgunit.sh`) es obligatoria antes de desplegar; cada fase de despliegue exige su orden expresa.

**Pérdida registrada que este hito repara:** el arnés E2E de F/G/H4 se perdió con `/tmp`; A10.5 lo reconstruye como activo del repo en `Iron-Body_Front/e2e/ultron-studio/` (con simulador de n8n nuevo), más la especificación de H5 y un subconjunto de regresión por flujos.

---

## 5. Diseño a congelar (A1–A13)

### A1. Qué significa «publicar» (opción A)

Tres planos, y la publicación solo toca el primero:

| Plano | Qué es | Quién lo cambia | En H5 |
|---|---|---|---|
| **Declaración** | La versión aprobada como descripción oficial y firmada de la configuración de ULTRON | El Studio: publicar, promover, revertir, Legacy | Es lo que se publica y lo que lee el motor |
| **Aplicación** | Lo que el motor hace distinto por leer la declaración | Nadie en H5 | Vacío. El motor solo sella |
| **Ejecución real** | Entorno, pausa, freno, planes, conocimiento, código, n8n | Propietario, Mercadeo, consola, otros módulos | Sin cambios; el Studio lo compara (deriva) |

**Clasificación de cada parámetro al publicar** (la calcula `StudioPublicationDeclaration`, núcleo puro, con fixture):

| Clase | Parámetros | Regla | Si no se cumple |
|---|---|---|---|
| `must_match` | Los 4 `env_flag`: `governance.enabled`, `governance.canaryOnly`, `rules.paymentLinksEnabled`, `intent.driver` | El valor de la versión tiene que ser igual al que lee hoy el motor (`StudioEngineReadings`); en `driver`, `entorno` coincide siempre y si no se compara con el efectivo (`fake` → `rules`) | 409 `engine_value_mismatch` con la lista `{param, versionValue, engineValue}`. Una declaración que contradice al motor sería falsa. Después de publicar, estos 4 se vigilan como **deriva** |
| `matches` | `fixed` con `declaredValue` y el mismo valor | — | — |
| `no_effect` | `fixed` con otro valor; `fixed` sin valor (`sources`, `critic`, `retry`), `proposal` (`tone`, `emojis`, `maxChars`, `fallback`, `goal`) y `engine_data` (`offerPriority`) **solo si difieren de la línea base**; y **cada regla S-NNN** copiada en la versión (`rule:S-NNN`) | Quien publica reconoce el conjunto EXACTO (`acknowledgedNoEffect`), igual que los avisos al aprobar | 409 `no_effect_not_acknowledged` con el conjunto esperado |
| (sin clase) | Reglas R-xx (describen el motor), aristas y disposición | — | — |

**Lo que entra en la declaración firmada:** identidades (versión, huella de contenido, versión del catálogo, ids de quien aprobó y de quien publica) y los 4 valores `must_match`. **Nunca:** etiquetas, notas, motivos (texto libre del personal: riesgo B-1 de Ley 1581), ids de conversación, secretos.

**Reglas S-NNN:** sin efecto en H5 (respuesta a la pregunta 5 del encargo). Las R-xx con `conditions` que transcriben mandos reales (R-12 `canaryOnly`, R-01, R-02, R-07) ya los aplica el código; su presencia en la versión no añade nada.

**La primera publicación** tiene que ser una versión que **refleje el motor tal como está** (la línea base con los 4 valores que enseñe `GET engine/readings` en ese momento). Si en producción `governance.enabled` resultara `false`, la versión publicable dice `false`; encender ULTRON sigue siendo del entorno.

### A2. Máquina de estados (F + H5)

| Desde | Acción | Hacia | Permiso | Quién | Qué revalida el servidor |
|---|---|---|---|---|---|
| `APPROVED` | publicar (siempre en canario) | `CANARY` | `ultron.publish` | **≠ autor** (403 `four_eyes_required`, auditado) | Puertas A5.1: integridad, revalidación con el catálogo actual, puerta del laboratorio, `must_match`, reconocimiento exacto, `previewHash`, canario del motor configurado, freno suelto, ningún otro `CANARY`, ventana, idempotencia, cadena |
| `CANARY` | promover | `PUBLISHED`; la `PUBLISHED` anterior, si la hay → `RETIRED` | `ultron.publish` | ≠ autor | Evidencia del canario (A6) con `evidenceHash`; llave del motor `all`; ventana |
| `CANARY` | revertir | `ROLLED_BACK` (el canario vuelve a lo que tenga «el resto») | `ultron.rollback` | Cualquiera con la llave (es el sentido seguro) | Motivo 5–500 |
| `PUBLISHED` | revertir | `ROLLED_BACK`; la anterior `RETIRED` → `PUBLISHED` (si es íntegra) o Legacy si no hay | `ultron.rollback` | Cualquiera con la llave | Motivo; integridad de la que vuelve (si falla, 409 `restore_integrity_mismatch`: queda Legacy como salida) |
| `CANARY` y/o `PUBLISHED` | volver a Legacy | `CANARY` → `ROLLED_BACK`; `PUBLISHED` → `RETIRED` | `ultron.rollback` | Cualquiera con la llave | Motivo |

- **`RETIRED` = «sustituida por una publicación posterior»** (se reutiliza el valor que ya existe en el enum, el tipo TS y la tubería; etiqueta nueva «Sustituida»). No se añade `SUPERSEDED`.
- `ROLLED_BACK` y `REJECTED` son terminales. `RETIRED` solo sale por una reversión que la restaura. `VALIDATING` sigue inalcanzable.
- **Invariante:** como mucho una versión `CANARY` y una `PUBLISHED` a la vez (índices únicos parciales en PG y SQLite) y la cabeza de la cadena coincide con ellas (prueba).
- Las transiciones usan `StudioVersion::transition()` (UPDATE condicionado al estado esperado, sin `FOR UPDATE`), en la misma transacción que la fila de la cadena y el evento del libro, como en F.

### A3. Modelo de datos

**A3.1 `ultron_studio_publications` (cadena de solo inserción).**

```
id                       bigint PK
uuid                     uuid UNIQUE
seq                      int unsigned UNIQUE      -- 1, 2, 3…; la cabeza es max(seq). La carrera la gana uno (23505 → 409 publication_moved)
client_request_id        uuid UNIQUE              -- idempotencia
action                   varchar(10)              -- publish | promote | rollback | legacy   (CHECK en PG)
version_id               FK NULL → ultron_studio_versions (RESTRICT)   -- la versión sobre la que se actuó; NULL en legacy
restores_publication_id  bigint NULL (FK propia)  -- en rollback: la entrada cuya «parte resto» vuelve
previous_publication_id  bigint NULL (FK propia)  -- la cabeza anterior (NULL solo en seq = 1)
engine_contract          varchar(32)              -- 'ultron-engine-config/1' (CHECK en PG)
effective_json           text                     -- BYTES EXACTOS que lee y firma el motor. text, nunca json/jsonb (PG reordenaría)
effective_hash           char(64)                 -- sha256(effective_json)
signature                char(64)                 -- A4.1
catalog_version          varchar(32)
lab_gate                 json                     -- {mode:'required'|'waived', runUuid?, verdictId?, promptVersion?}
acknowledged             json NULL                -- {noEffect:[…]} (publish)
evidence                 json NULL                -- recuentos del canario (promote), sin ids ni textos
reason                   varchar(500) NULL        -- obligatorio en rollback y legacy
actor_admin_id           bigint unsigned          -- instantánea SIN FK, como en F
actor_name               varchar(120)
actor_role               varchar(60) NULL
created_at               timestamp                -- solo inserción, sin updated_at
INDEX (version_id, id)
```

**A3.2 Guardas (migración 2):** disparadores `uspub_forbid_update`, `uspub_forbid_delete` (SQLite y PG) y `uspub_forbid_truncate` (PG) con la función `ultron_studio_forbid()` existente (`CREATE OR REPLACE`, idempotente, como en F); CHECK de dominio de `action` y `engine_contract` en PG, y `uspub_reason_chk` (`action NOT IN ('rollback','legacy') OR reason IS NOT NULL`); **ampliación** de `usv_status_chk` a `DRAFT, VALIDATED, REVIEW_REQUIRED, APPROVED, REJECTED, CANARY, PUBLISHED, RETIRED, ROLLED_BACK` (DROP + ADD en la misma transacción); índices únicos parciales `usv_one_canary` (`status` WHERE `status = 'CANARY'`) y `usv_one_published` (`status` WHERE `status = 'PUBLISHED'`). **Cuidado SQLite** (memoria «SQLite reconstruye y pierde el WHERE»): ninguna migración de H5 añade FK a `ultron_studio_versions` ni la reconstruye; la prueba de la migración comprueba que los dos índices conservan su `WHERE` en los dos motores.

**A3.3 Sin columnas nuevas en `ultron_studio_versions`** (la lista de columnas inmutables de F sigue completa) y sin tocar `ultron_studio_version_reviews` (una reseña por versión).

**A3.4 `down()`:** se niega con un mensaje claro si existe alguna publicación o alguna versión en `CANARY|PUBLISHED|RETIRED|ROLLED_BACK`; si no, deshace índices, CHECK (vuelta a 5 estados), disparadores y tabla. «No destruyas»: revertir el código no exige `down()` (A3.5).

**A3.5 Volver al código de H4 con datos de H5:** el enum de F ya conoce los 10 estados, así que el código anterior lee esas versiones sin error (con `reachable:false`) y no puede moverlas. La tabla nueva queda sin lector. Seguro.

### A4. Contrato del motor `ultron-engine-config/1`

**A4.1 Firma.** `signature = hex(HMAC-SHA256(k, "ultron-engine-config/1\n" . effective_json))` con `k = HMAC-SHA256("ultron-studio-publication", APP_KEY)` (subclave separada de la del `decide_token`). **Una sola implementación**, la del motor (`UltronPublishedConfig::signatureFor(string): string`), que el Studio llama al publicar. Protege de una escritura cruda en la base (el rol `iron_app` tiene DML sobre todo) por quien no tenga la `APP_KEY`; no es una firma personal (no hay claves por persona): las identidades de quien aprueba y quien publica van DENTRO del texto firmado.

**A4.2 `effective_json`** (lo genera el Studio con `StudioContentHasher::canonicalJson`; el motor no recanonicaliza: verifica los bytes y después decodifica en estricto):

```json
{"canary":{"approvedBy":12,"catalogVersion":"2026-10-19.1","contentHash":"<64 hex>","declared":{"governance.canaryOnly":true,"governance.enabled":true,"intent.driver":"entorno","rules.paymentLinksEnabled":false},"publishedBy":7,"version":"<uuid>"},
 "contract":"ultron-engine-config/1","entry":"<uuid de la fila>","issuedAt":"2026-11-03T15:04:05Z",
 "rest":null,"seq":1}
```

`canary` y `rest` son una «parte» o `null`. Así una sola fila basta para resolver cualquier conversación: publicar B en canario escribe `{canary: B, rest: <lo que hubiera>}`; promover escribe `{canary: B, rest: B}`; Legacy escribe `{canary: null, rest: null}`. Claves exactas: una clave de más o de menos, o un tipo distinto, es `malformed`.

**A4.3 Algoritmo del lector** (`UltronPublishedConfig::forConversation(int $conversationId): ?array`):

1. `env = config('marketing.ultron.studio_config')`. Si es `off` → devuelve `null`: **ninguna consulta y ningún sello** (Legacy exacto).
2. Una consulta: la fila con `max(seq)` (índice único). Sin caché entre peticiones; el resultado se usa una vez por commit.
3. Sin fila → `legacy`.
4. Verificación, en este orden: `engine_contract` conocido (si no, `fallback/contract_unknown`); `sha256(effective_json) = effective_hash` (`fallback/hash_mismatch`); firma (`fallback/signature_invalid`, con `hash_equals`); decodificación estricta y `entry`/`seq` iguales a los de la fila (`fallback/malformed`).
5. Parte: si la conversación es la del canario del motor (`config('marketing.ultron.canary_conversation_id')`) → `canary` y, si es `null`, `rest`; si no lo es → `rest` solo con `env = all`; con `env = canary` → `out_of_scope`.
6. Parte `null` → `legacy`. Si no → `applied`.
7. **Cualquier `Throwable`** en cualquier paso → `fallback/read_failed` (o `fallback/table_missing` si es la tabla inexistente: SQLSTATE 42P01 o «no such table», el mismo criterio que `MarketingAgentSwitch::tablaInexistente`); al log (`ChannelLog`), el motivo y la clase del error, nunca el mensaje (puede llevar SQL). **El lector nunca lanza.**

**A4.4 Estados del sello:** `applied`, `legacy`, `out_of_scope`, `fallback` (con `reason`: `contract_unknown`, `hash_mismatch`, `signature_invalid`, `malformed`, `table_missing`, `read_failed`). En A, ninguno cambia lo que hace el commit.

**A4.5 Sello** en `marketing_ai_actions.metadata.studio`, solo con `env ≠ off`:

```json
{"v":1,"env":"canary","state":"applied","entry":"<uuid>","seq":7,"part":"canary","version":"<uuid>","contentHash":"<64 hex>","reason":null}
```

Sin etiquetas, sin nombres, sin ids de conversación (la fila ya tiene el suyo).

**A4.6 Dónde se invoca:** una vez por commit, en `UltronCommitService::execute()` justo después de la puerta del canario (`:290-303`) y antes de la elegibilidad (`:305`), fuera de toda transacción; el resultado viaja a `persist()` y lo llevan TODAS las filas que crea ese commit (ejecutadas, bloqueadas, crítico fallido y rendiciones). Requisito verificable: dos commits seguidos en el mismo proceso no comparten sello (prueba).

**A4.7 Presupuesto:** una lectura por índice único por commit. El tester mide el p95 del commit con la llave en `off` y en `all` en el E2E; lo esperado es ruido frente a los 7–13 s del turno. No hace falta `performance-specialist`.

**A4.8 Sin caché, a propósito:** revertir tiene que ser instantáneo y es la misma política que la pausa (`MarketingAgentSwitch.php:38-40`). La llave de entorno sí va con la caché de configuración (el proceso web la ve tras `config:cache`; ver despliegue).

**A4.9 Lectura para el Studio:** `UltronPublishedConfig::describe(): array` (sin conversación) devuelve `{env, head:{entry, seq, contract, hashOk, signatureOk, parseOk}, parts:{canary:bool, rest:bool}, canaryConfigured:bool}`. Nunca el id del canario. La consume `StudioEngineReadings` como extra `governance.studioPublication`.

### A5. Puertas: publicar, promover, revertir, Legacy

**Las tres llaves y el registro** (respuesta a «doble llave: entorno + registro»):

| Llave | Dónde | Qué abre | Quién la gira |
|---|---|---|---|
| Código | `StudioPublishing::ENGINE_READS_VERSIONS = true`, girada en el commit que implementa publicar (paso 3), cuando el lector del motor ya existe (paso 1c) | Que exista código que publique y código que lea | El commit del paso 3 |
| Entorno del motor | `MARKETING_ULTRON_STUDIO_CONFIG = off \| canary \| all` (`config/marketing.php`) | Que el motor consulte la tabla y hasta dónde (`all` ⊃ `canary`) | Propietario (fases 2 y 3) |
| Entorno del Studio | `ULTRON_STUDIO_PUBLISHING_ENABLED` (existe) | Las rutas de publicar y promover | Propietario |
| **Registro** | La cabeza firmada de `ultron_studio_publications` | Qué declaración rige y para quién (`canary`/`rest`) | El Studio, con cuatro ojos |

**A5.1 Publicar** — `POST versions/{uuid}/publish` `{clientRequestId, previewHash, acknowledgedNoEffect[≤64], note?≤500}`. Orden de comprobación (cada fallo con su código; los 403 y los 409 de llave quedan auditados como hoy, deduplicados):

1. Persona y `ultron.view` → 403; `ultron.publish` → 403.
2. `ULTRON_STUDIO_PUBLISHING_ENABLED` → 409 `publishing_disabled`. Llave del motor `off` → 409 `engine_not_reading_versions`.
3. Reintento: misma `clientRequestId`, misma persona y mismo cuerpo → 200 `replayed` con la entrada de la primera vez; otra cosa → 409 `client_request_reused`.
4. Versión → 404 `version_not_found`. Cuatro ojos (quien publica ≠ autor) → 403 `four_eyes_required` + `version.four_eyes_denied` `{action: publish}`.
5. Estado `APPROVED` → 409 `invalid_transition`. Integridad → 409 `integrity_mismatch`.
6. Revalidación con el catálogo actual: bloqueos → 409 `revalidation_blocked`; avisos distintos de los que reconoció la reseña → 409 `revalidation_changed` (el catálogo cambió después de aprobar: versión nueva y aprobación nueva).
7. Laboratorio (A13 `publish_lab_gate`): con `required`, el veredicto MÁS RECIENTE sobre una ejecución de esta versión con `version_content_hash = content_hash` y `prompt_version = StudioLabPrompts::PROMPT_VERSION` tiene que ser `approved` → si no, 409 `lab_verdict_required` `{latest: null|rejected|needs_changes|stale_prompts}`. Con `waived` se registra `lab_gate.mode = waived` y el CRM lo enseña en rojo.
8. `must_match` → 409 `engine_value_mismatch`; una lectura del motor `available:false` → 409 `engine_reading_unavailable` (nunca se da por buena).
9. `acknowledgedNoEffect` exacto → 409 `no_effect_not_acknowledged`. `previewHash` igual al recalculado → 409 `preview_stale` (con la vista previa nueva).
10. Canario del motor configurado → 409 `engine_has_no_canary`. Freno suelto → 409 `engine_braked`. Ninguna otra versión en `CANARY` → 409 `canary_in_progress`.
11. Ventana de publicación (hora de Bogotá) → 409 `outside_publication_window`.
12. Transacción: firmar, insertar la entrada (`seq = cabeza + 1`), `APPROVED → CANARY` condicionado, evento del libro. Carrera de la cadena (23505 en `seq`) → 409 `publication_moved`; carrera del estado → 409 `invalid_transition` con el estado de ahora. En PostgreSQL, la resolución del reintento tras un 23505 va FUERA de la transacción abortada (patrón de `StudioVersions::create`).

**A5.2 Promover** — `POST versions/{uuid}/promote` `{clientRequestId, evidenceHash, note?}`: 1–4 como publicar (cuatro ojos ≠ autor); llave del motor `all` → si no, 409 `scope_not_allowed`; estado `CANARY`; integridad; evidencia (A6) suficiente → 409 `canary_evidence_insufficient` con la evidencia; `evidenceHash` igual al recalculado → 409 `evidence_changed`; freno suelto; ventana; transacción (`CANARY → PUBLISHED`, la `PUBLISHED` anterior → `RETIRED`, entrada `{canary: B, rest: B}`).

**A5.3 Revertir** — `POST versions/{uuid}/rollback` `{clientRequestId, reason 5–500}`: `ultron.rollback`; **no** exige `ULTRON_STUDIO_PUBLISHING_ENABLED` ni la llave del motor (deshacer no puede depender de la llave que permite cambiar; con la llave del motor en `off` es solo una fila que nadie lee), ni cuatro ojos, ni ventana. La versión tiene que ser la `CANARY` o la `PUBLISHED` vigente → 409 `not_active_publication`. `CANARY`: entrada `{canary: rest, rest: rest}`. `PUBLISHED`: entrada con la parte resto de la publicación anterior a su promoción (esa versión `RETIRED → PUBLISHED`, si es íntegra; si no, 409 `restore_integrity_mismatch` y la salida es Legacy) o `{null, null}` si no había.

**A5.4 Volver a Legacy** — `POST publications/legacy` `{clientRequestId, reason}`: `ultron.rollback`; cabeza ya en Legacy → 409 `already_legacy`; entrada `{null, null}`; `CANARY → ROLLED_BACK`, `PUBLISHED → RETIRED`. Es el «parar de declarar» del Studio; el «parar de leer» es la llave del motor en `off`.

**A5.5 Lo que ninguna puerta hace:** ni encender ni apagar ULTRON, ni tocar entorno, pausa, freno, planes, conocimiento o n8n. El Studio no escribe en ninguna tabla del motor.

### A6. Canario: evidencia, criterios de salida y freno

`StudioCanaryEvidence` calcula (solo lectura; sin ids de conversación, sin textos):

| Medida | Cómo | Puerta para promover |
|---|---|---|
| `hours` | Desde la entrada que puso la versión en `CANARY` | ≥ `canary_min_hours` (24 por defecto; 1–168) |
| `turns` | Filas de `marketing_ai_actions` de la conversación canaria del motor con `source_type = UltronSource::INBOUND_MESSAGE` (`'inbound_message'`, `UltronSource.php:16`; el único que acepta el commit, `UltronController.php:151`), desde esa entrada | ≥ `canary_min_turns` (3 por defecto; 1–100) |
| `stamped` | De esas, con `metadata.studio.state = applied` y `metadata.studio.version = <uuid>` | `stamped = turns` (100 %) |
| `byState` | Recuento por estado del sello (`legacy`, `fallback/*`, sin sello) | Informativo (explica un 100 % fallido) |
| `brakes` | `ultron_aborts` accionados desde la entrada; freno echado ahora | 0 y suelto |
| `acta` | `ultron:canary-report <canario> --since=<entrada> --turns=<max(turns,40), tope 200> --json`: código de salida y recuento por cada uno de los 7 contadores | Total 0 |
| `recoveries` | Filas con `metadata.recovery` en la ventana | Informativo |
| `drift` | Los 4 `must_match` frente a las lecturas de ahora | Ninguna deriva |
| `signatureOk` | `UltronPublishedConfig::describe()` sobre la cabeza | `true` |

`evidenceHash` = huella canónica del objeto (recuentos, umbrales y marca de inicio). El acta se invoca con `Artisan::call` dentro del proceso; de su JSON solo se leen el total y los recuentos por clave, y **nunca se guarda, se registra ni se devuelve** el texto. Ruta con su propio limitador (10/min por persona), porque es la lectura más cara.

**Freno.** El automático sigue siendo el del vigía: un turno mudo del canario lo acciona, sea cual sea la causa, incluido cualquier fallo del código nuevo. El lector no puede causar un turno mudo (A4.3 paso 7). El Studio **no** frena ni revierte solo por deriva o por un sello que falta: en A eso no daña a ningún prospecto, y una reversión automática no es una operación «de un solo sentido» (en un contrato futuro con mandos aplicados podría reabrir algo). Lo que hace: enseñar la deriva en `status`, bloquear la promoción y responder 1 en `ultron-studio:publication-check` (para cron o para quien opere). El incidente de IRON GUARD por deriva queda para el Hito 7 (monitoreo).

**Honestidad del canario hoy:** con el canario del motor puesto, `canary` y `all` alcanzan la misma conversación; la promoción no cambia el tráfico. Y sin turnos reales del canario no hay evidencia: la promoción queda bloqueada, que es lo correcto.

### A7. API (`/api/admin/ultron-studio`, 38 rutas)

| # | Ruta | Llave | Grupo | Cambio |
|---|---|---|---|---|
| — | `GET status` | view | lectura | `engine: {readsVersions:true, activeVersion: <uuid de la parte resto>\|null, publication: PublicationStateDto}`; `publishing` con los códigos de A5 |
| — | `GET versions`, `GET versions/{uuid}` | view | lectura | Estados nuevos; `actions.publish/promote/rollback` reales; el detalle gana `publications: PublicationEntryDto[]` (las entradas que la nombran) |
| 13 | `POST versions/{uuid}/publish` | publish | escritura | Real (A5.1) |
| 14 | `POST versions/{uuid}/rollback` | rollback | escritura | Real (A5.3) |
| — | `GET compare` | view | lectura | `from.integrityOk` / `to.integrityOk`: `bool` en versiones, `null` en `baseline` y `draft` |
| — | `GET rules?status=archived` | view | lectura | Paginado con la forma de `GET versions` (`page`, `perPage` ≤ 50) |
| — | `GET engine/readings` | view | lectura | Extra `governance.studioPublication` (A4.9) |
| 34 | `GET versions/{uuid}/publication-preview` | view | lectura | NUEVA. `PublicationPreviewDto` |
| 35 | `GET versions/{uuid}/canary-evidence` | view | lectura + limitador propio | NUEVA. `CanaryEvidenceDto` |
| 36 | `POST versions/{uuid}/promote` | publish | escritura | NUEVA (A5.2) |
| 37 | `GET publications` | view | lectura | NUEVA. Historial paginado de la cadena |
| 38 | `POST publications/legacy` | rollback | escritura | NUEVA (A5.4) |

```jsonc
// PublicationPreviewDto
{ "versionUuid":"…", "contentHash":"…", "previewHash":"<sha256>", "publishable": false,
  "params":[{"layer":"governance","key":"enabled","class":"must_match","versionValue":true,"engineValue":true,"ok":true}, …],
  "noEffect":["persona.tone","rule:S-003"],
  "requirements":{ "status":{"ok":true,"current":"APPROVED"}, "fourEyes":{"ok":true}, "integrity":{"ok":true}, "revalidation":{"ok":true},
                   "labGate":{"mode":"required","ok":false,"latest":null}, "engineCanary":{"ok":true},
                   "brake":{"ok":true}, "canaryFree":{"ok":true}, "window":{"ok":true,"window":"07:00–19:00"},
                   "keys":{"studio":true,"engine":"canary"} },
  "blocking":["lab_verdict_required"] }
// PublicationStateDto (status.engine.publication)
{ "envScope":"canary", "head":{"uuid":"…","seq":3,"action":"publish","at":"…","signatureOk":true},
  "canary":{"versionUuid":"…","label":"…"}|null, "rest":{…}|null,
  "drift":[{"param":"rules.paymentLinksEnabled","declared":false,"engine":true}], "state":"applied|legacy|off|fallback" }
// PublicationEntryDto (GET publications, detalle de versión)
{ "uuid":"…","seq":3,"action":"publish|promote|rollback|legacy","version":{"uuid":"…","label":"…"}|null,
  "effectiveHash":"…","shortHash":"…","labGate":{"mode":"required|waived"},"actor":{"name":"…","role":"…"},
  "reason":"…"|null,"at":"…" }
// CanaryEvidenceDto: la tabla de A6 + {"evidenceHash":"…","passes":false,"failing":["turns","acta"]}
```

Errores nuevos: 403 `four_eyes_required` (reutilizado); 409 `engine_not_reading_versions` (ya existía), `revalidation_changed`, `lab_verdict_required`, `engine_value_mismatch`, `engine_reading_unavailable`, `no_effect_not_acknowledged`, `preview_stale`, `engine_has_no_canary`, `engine_braked`, `canary_in_progress`, `outside_publication_window`, `scope_not_allowed`, `canary_evidence_insufficient`, `evidence_changed`, `not_active_publication`, `restore_integrity_mismatch`, `already_legacy`, `publication_moved`. Validación de forma: 422 estándar, con la validación de listas en dos fases (`ValidatesListSizesFirst`) y el tope de 64 KB del grupo.

### A8. Auditoría (solo inserción, en la misma transacción que el cambio)

| `event` | Sujeto | Desde → hacia | `diff` |
|---|---|---|---|
| `version.published` | version | APPROVED → CANARY | `{entry, seq, effectiveHash, labGate, noEffect}` |
| `version.promoted` | version | CANARY → PUBLISHED | `{entry, seq, evidence}` (recuentos) |
| `version.retired` | version | PUBLISHED → RETIRED | `{entry, by: promote\|legacy}` |
| `version.rolled_back` | version | CANARY\|PUBLISHED → ROLLED_BACK | `{entry, seq, reason}` |
| `version.restored` | version | RETIRED → PUBLISHED | `{entry, seq}` |
| `system.publication_legacy` | system (uuid de la entrada) | — | `{entry, seq, reason}` |
| `version.publish_refused` / `version.promote_refused` / `version.rollback_refused` | version | — | `{code}` (deduplicados como en F) |
| `version.four_eyes_denied` | version | — | `{action: publish\|promote}` |

Sin sujetos nuevos (no se amplía `usae_subject_type_chk`). El motor no escribe auditoría: su traza es el sello.

### A9. Frontera redefinida y pruebas de aislamiento

**La frontera nueva, en una frase verificable:** el motor lee **una** tabla del Studio (`ultron_studio_publications`), desde **una** clase (`UltronPublishedConfig`), con **una** sentencia `SELECT` de la cabeza por commit, **solo** con `MARKETING_ULTRON_STUDIO_CONFIG ≠ off`, y la invoca **solo** `UltronCommitService` para sellar. El motor nunca escribe en `ultron_studio_*`, nunca lee `config('ultron_studio…')` y nunca importa clases del Studio. El Studio nunca escribe en tablas, configuración ni ficheros del motor; lee lo que ya leía (configuración, planes, conocimiento, pausa, freno) y, en H5, recuentos de `marketing_ai_actions` y `ultron_aborts` de la conversación canaria (sin ids ni textos), llama a `UltronPublishedConfig::signatureFor()` (pura) y `describe()` (lectura), y ejecuta `ultron:canary-report --json` (lectura).

**Nombre de la clase:** sin la subcadena `UltronStudio`, para que nombrarla desde el commit no dispare el rastreo por tokens de `UltronEngineIsolationTest` (`:125-145`).

**Pruebas:**
1. `UltronEngineIsolationTest`, en dos tiempos y con un solo dueño cada vez. **Paso 1c (B-M, mismo commit que el lector):** la lista blanca gana las dos migraciones de H5 y `app/Services/Marketing/Ultron/UltronPublishedConfig.php` con una aserción propia (sus referencias al Studio son **exactamente** la cadena `'ultron_studio_publications'` y nada más); las zonas del motor siguen limpias salvo ese fichero; `marketing.ultron.studio_config` vale `off` por defecto. **Paso 3 (B-S, mismo commit que publicar):** 38 rutas, y la prueba de «el motor no lee versiones» pasa a «el motor lee solo con su llave y publicar está apagado por defecto» (`ENGINE_READS_VERSIONS` true y `ultron_studio.publishing_enabled` false por defecto).
2. NUEVA prueba de comportamiento (B-M, paso 1c) con `DB::listen` sobre un commit completo: con la llave `off`, **cero** sentencias que nombren `ultron_studio_`; con `canary`/`all`, exactamente una `select` sobre `ultron_studio_publications` y ninguna escritura en `ultron_studio_*`.
3. `UltronStudioEngineReadOnlyTest` (paquete B-S): publicar, promover, revertir y Legacy escriben solo en `ultron_studio_*`; la evidencia del canario y la vista previa no escriben nada.

### A10. Heredados que el hito absorbe

1. **`integrityOk` por lado en `compare` (I-2 de G):** `StudioVersions::side()` añade `integrityOk` (`bool` en versiones con `StudioVersions::integrityOk`, `null` en `baseline` y `draft`); el CRM pinta una insignia «Huella alterada» y no cambia el diff. Hoy `compare` no lo marca (`StudioVersions.php:445-451`).
2. **Paginación de reglas archivadas (I-5 de G):** `GET rules?status=archived` con `page`/`perPage` (≤ 50) y la forma de `GET versions`; las vivas no cambian (tope de 100). Los **escenarios** archivados ya tienen tope de 200 y `meta.truncated` (Enmienda 4.3 de H4): no se tocan en H5.
3. **Carrera de «Validar en el servidor»** (`‹F›/studio-session.ts:583-596`): hoy no mira `_saving()` y, si la respuesta trae una revisión distinta sin cambios locales, `cargarBorrador()` carga la respuesta VIEJA encima de lo recién guardado. Arreglo: (a) rechazar con `busy` mientras se guarda, como `importLegacyDraft` (`:568`); (b) descartar cualquier respuesta con `revision < store.revision()` y avisar «Se guardó mientras validabas: vuelve a validar»; (c) spec con promesas controladas que reproduce el orden GET-enviado → guardado-confirmado → GET-respondido y falla con el código actual.
4. **Umbral de desatención por carril (m-3 de H4):** `QueueHealthService::looksUnattended` usa el global de 120 s (`QueueHealthService.php:129-138`, `config/observability.php:61`); el carril `lab` ya declara su propio `slo_wait_ms` (`config/queue.php:215`). Cambio aditivo: `unattended_after_seconds` opcional por carril (el del laboratorio, 300 s), con el global como defecto. Pruebas: un carril sin la clave sigue en 120 s; `lab` en 300 s.
5. **Arnés E2E como activo del repo** en `Iron-Body_Front/e2e/ultron-studio/` (puppeteer-core ya es dependencia del CRM; el backend se pasa por `BACKEND_DIR`, por defecto `../Iron-Body/backend` respecto al worktree del CRM):
   - `run.sh <backend-ref> <crm-ref>`: instantánea del backend con `git archive` y **vendor copiado** (no enlazado: memoria del worktree con vendor enlazado), `.env` desde `.env.example` entrecomillando valores con espacios, PG desechable `ultron_studio_e2e` en `127.0.0.1:55432`, `artisan serve --no-reload` en :8092 con `PGGSSENCMODE=disable` (SIGSEGV de libpq en macOS), workers reales (carril del laboratorio y el de ULTRON), proxy :8080, `ng serve` :4300; PIDs con `( cd X || exit 1; nohup cmd … < /dev/null & echo $! > pid )` y parada de nietos, hijo y padre.
   - `mock-openai.cjs` (:8093, Responses API para el laboratorio); NUEVO `mock-n8n.cjs` (:8094): recibe el webhook de ULTRON, verifica el HMAC con el secreto de pruebas, llama a `ai/decide` y después a `ai/commit` con una propuesta determinista sin cifras ni enlaces, y responde al final, como n8n; NUEVO `meta-webhook.mjs`: entrante firmado para la conversación canaria de la semilla, con `META_ENABLED=false` (el despachador queda en `dry_run`).
   - `accounts.mjs`: cuentas locales (autora, revisora, publicadora, lectora y un Administrador sin `publish`) con contraseñas aleatorias de cada ejecución; nada de secretos en el repo. Se niega a correr si la API no es `localhost` o si `APP_ENV=production`.
   - Especificaciones: `specs/h5-governance.cjs` (nueva, A12) y un subconjunto de regresión de F, G y H4 por flujos críticos (borrador → versión → aprobar; inspector con lecturas y comparar; ejecución `fake` y veredicto con el simulador). Reconstruir las 280 comprobaciones perdidas una a una queda como trabajo aparte (§10).
   - `README.md` con las trampas ya pagadas: PID de la subshell, `.env.example` inválido, `resultsHash` obligatorio, `CACHE_STORE=array` anula el limitador, reinicios que dan 502 y CORS.
6. **(Si se acepta la pregunta 9)** `StudioAudit::recordDenied` fija `READ COMMITTED` como primera sentencia antes de su cerrojo de deduplicación, igual que el laboratorio (Enmienda 4.6 de H4), con su prueba en PG con la sesión en `REPEATABLE READ`. Dueño: B-S (`‹S›/StudioAudit.php`).

### A11. CRM

- **Panel de versiones:** botones reales según `actions` del servidor. «Publicar en canario» abre un diálogo con la vista previa: tabla de los parámetros por clase, la lista de «sin efecto» con una casilla por elemento, requisitos con su motivo (laboratorio, canario del motor, freno, ventana, llaves) y envío con `previewHash`. «Promover a todas» enseña la evidencia (A6) y envía `evidenceHash`. «Revertir» y «Volver a Legacy» piden motivo (reutilizan `ib-reason-dialog`, con su ranura de aviso).
- **Texto obligatorio** en el diálogo y en la ayuda del permiso: «Publicar registra esta versión como la configuración oficial de ULTRON y el motor la anota en cada turno. En este hito no cambia lo que ULTRON dice ni hace.»
- **Tubería:** `CANARY`, `PUBLISHED`, `RETIRED` («Sustituida») y `ROLLED_BACK` alcanzables, con la etiqueta que manda el servidor.
- **Barra de estado de la página:** publicación vigente (canario / todas / Legacy), llave del motor, sello correcto o deriva.
- **Lecturas del motor:** la nueva `governance.studioPublication`; comparar con la insignia de integridad por lado.
- **Historial de publicaciones** (`GET publications`), paginado.
- **Carrera de validar** (A10.3).
- Restricciones vigentes: nunca `ultron-studio.scss`; hojas nuevas < 8 kB; nunca animar `transform` en el host de `fNode`; accesibilidad (foco atrapado en diálogos, `role=alert`, teclado) y 390 px; tolerar un backend sin `engine.publication` (llaves desconocidas o ausentes no rompen).
- Fixtures desde `ultron-studio:export-fixtures` (paridad); catálogo de permisos regenerado (sigue en 96).

### A12. Pruebas

| Capa | Qué | Dónde y quién |
|---|---|---|
| Núcleo | Clasificación de los 21 parámetros y de las reglas, bytes exactos del JSON, `previewHash` estable; firma, contrato, `malformed`; matriz de alcance `env {off, canary, all}` × partes × conversación {canaria, otra, sin canario configurado} | Unit, con `publication-cases.json` (tester, desde el contrato) |
| Motor | Sello presente o ausente según la llave; lo llevan todas las filas de un commit; **nunca lanza** (tabla inexistente, firma mala, JSON roto, error de consulta en PG); dos commits no comparten sello; la respuesta del commit es idéntica (instantánea); las suites de ULTRON en verde **dos veces**, con la llave en `off` y en `all`; vigía, acta y `InternalSecretAuthoritySurfaceTest` sin cambios | B-M |
| API | Cada fila de A2 y cada código de A5; idempotencia; evento del libro en la misma transacción; matriz de 38 rutas × {sin sesión 401, token 403, Recepción/Entrenador/Administrativo 403, Administrador 403 en publish/promote/rollback/legacy, Super Admin permitido}; vista previa y evidencia no escriben | B-S |
| PostgreSQL (puerta obligatoria `ops/pgunit.sh`) | Disparadores de la cadena (P0001), CHECK ampliado, índices parciales (segundo `CANARY` → 23505), `down()` que se niega; carreras: 20 publicaciones simultáneas de versiones distintas (gana una), publicar contra Legacy, promover contra revertir, doble promoción, en natural y forzadas con barrera (`pg_blocking_pids` + escalón de 40 ms) | Tester |
| Mutaciones | Quitar la firma, los cuatro ojos, el reconocimiento exacto, el `try` del lector, el sello de `blocked()`: cada una tumba su prueba | Tester |
| Aislamiento | A9 | B-M y B-S |
| Heredados | A10.1–A10.4 | B-S y FE |
| CRM | Specs del módulo y de los componentes nuevos, paridad con fixtures, texto hostil, permiso desconocido, build y presupuestos de chunk, `check:literals` | FE |
| E2E con pila real | Publicar en canario → el siguiente turno (webhook firmado + simulador de n8n) sale sellado `applied` → evidencia → promover → revertir → el turno siguiente sale con la parte anterior → Legacy → `legacy`; llave `off` → sin sello; deriva al cambiar una bandera del entorno de la instantánea; cuatro ojos y Administrador sin botón; p95 del commit con la llave en `off` y en `all` | Tester (arnés A10.5) |
| Seguridad | `api-security-specialist` (autorización por ruta, cuatro ojos por identidad, reutilización de `clientRequestId`, fugas del id del canario, forma de la evidencia, diseño de la firma) y `security` (uso y rotación de la `APP_KEY`, frontera, registros, datos personales, el texto del acta en memoria) | Tras el paso 3 |
| Línea base | Suite completa contra `origin/main` **el mismo día** (un test de Reports depende del calendario) y las 11 de PG que ya fallan en la base, documentadas | Tester |

### A13. Configuración

| Variable | Clave | Defecto | Valores | Valor inválido |
|---|---|---|---|---|
| `MARKETING_ULTRON_STUDIO_CONFIG` | `marketing.ultron.studio_config` | `off` | `off`, `canary`, `all` | `off` |
| `ULTRON_STUDIO_PUBLISHING_ENABLED` | `ultron_studio.publishing_enabled` (existe) | `false` | booleano | `false` |
| `ULTRON_STUDIO_PUBLISH_LAB_GATE` | `ultron_studio.publishing.lab_gate` | `required` | `required`, `waived` | `required` |
| `ULTRON_STUDIO_CANARY_MIN_HOURS` | `ultron_studio.publishing.canary_min_hours` | 24 | 1–168 (recorte) | 24 |
| `ULTRON_STUDIO_CANARY_MIN_TURNS` | `ultron_studio.publishing.canary_min_turns` | 3 | 1–100 (recorte) | 3 |
| `ULTRON_STUDIO_PUBLISH_WINDOW` | `ultron_studio.publishing.window` | `07:00-19:00` (Bogotá) | `HH:MM-HH:MM`; vacío = sin ventana | defecto |
| `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS` | `queue.lanes.lab.unattended_after_seconds` | 300 | 60–3600 | 300 |

Mismo patrón que `config/ultron_studio.php` (`$entero`): una línea vacía nunca deja un tope en cero sin que nadie lo decida.

---

## Enmienda 1 (25-10-2026): lo que fija el fixture de publicación y decisiones del lead

`tests/fixtures/ultron-studio/publication-cases.json` (231 casos escritos a mano desde este contrato: `classify` 34, `effectiveJson` 21, `signature` 24, `scope` 63, `gates` 71, `evidence` 18; ruta en minúsculas junto a los fixtures de F, G y H4 porque el disco de macOS no distingue mayúsculas) y su `publication-cases.README.md` forman parte del contrato: **todas las decisiones que el README declara que el contrato no fijaba quedan congeladas**. Si PHP o TS discrepan, se corrige la implementación, nunca el fixture. Verificado por el tester con una segunda derivación independiente (openssl) y contra `StudioContentHasher::canonicalJson`, `StudioCatalog::bindings()` y `hash_hmac` reales: 0 discrepancias; 29 implementaciones erróneas lo tumban.

**Decisiones del lead sobre las preguntas del tester:**
- **Q-1 Subclave:** `k = hash_hmac('sha256', 'ultron-studio-publication', <APP_KEY decodificada de base64 cuando lleva el prefijo `base64:`>, true)`: la `APP_KEY` es la **clave** y la etiqueta el **dato** (lectura de derivación de clave); subclave en bytes crudos. `signature = hash_hmac('sha256', "ultron-engine-config/1\n" . effective_json, k)` en hexadecimal. Una sola implementación: `UltronPublishedConfig::signatureFor()`.
- **Q-2 Revertir la `PUBLISHED` con otra versión en `CANARY`:** opción (a): se **conserva la parte canaria** (`{canary: B, rest: R}`); deshacer no depende de otro estado.
- **Q-3 Identidades al promover:** la parte es el mismo objeto copiado: `publishedBy` y `approvedBy` siguen siendo los de la publicación en canario; quien promueve queda en la fila de la cadena (`actor_*`) y en el evento del libro.
- **Q-4 `previewHash`:** `StudioContentHasher::hash({versionUuid, contentHash, catalogVersion, mustMatch, matches, noEffect, labGate: {mode, latest}})`, sin ventana ni hora (tienen su propia puerta). `evidenceHash` como en A6.
- **Q-5 Lectura caída en la deriva:** cuenta como deriva con `engine: null` (falla cerrado y bloquea la promoción), igual que al publicar (`engine_reading_unavailable`).

---

## Enmienda 2 (25-10-2026): desviaciones aceptadas durante la implementación y lo diferido al Hito 7

**Fuentes:** `plans/informes-h5/decisiones-lead-h5.md` (decisiones del lead), `informe-api.md` §5 (D-1…D-18), `informe-front-integracion.md` §3 (D-F1…D-F9), `informe-security.md` y `informe-api-security.md`. Cada punto se contrastó con el código de la rama `fix/h5-lead` (HEAD `5772bdf`). No se reescribe nada de lo anterior: donde esta enmienda contradice A1–A13 o la Enmienda 1, manda esta.

### E2.1 API, cadena y libro

| # | Qué queda fijado | Artículo | Motivo | Dónde |
|---|---|---|---|---|
| D-1 | **409 nuevo `publication_head_invalid`**: la cabeza de la cadena no verifica (contrato, huella, firma o decodificación atada a `uuid`/`seq`) y publicar, promover o revertir tendrían que componer encima. La salida es volver a Legacy, que no verifica la cabeza (A5.4) | A7 (errores) | `publication_moved` invita a recargar y repetir, y eso no arregla una cabeza rota; `integrity_mismatch` habla de la versión | `UltronStudioException::publicationHeadInvalid()`; el CRM lo conoce desde `24a691d` |
| D-2 | `acknowledged` = `{noEffect, previewHash}`. `noEffect` es el conjunto EXIGIDO (el que se reconoció exacto) | A3.1 | Sin `previewHash`, el reintento no puede comparar el cuerpo sin guardar la petición | `StudioPublications::publishGated()` |
| D-3 | `lab_gate` = `{"mode": null}` en promover, revertir y Legacy; en el DTO, `labGate: null` | A3.1, A7 | La columna es NOT NULL y la puerta del laboratorio solo existe al publicar | `StudioPublications::notApplicableLabGate()` |
| D-4 | La nota opcional de publicar y promover se guarda en `reason` y el DTO la devuelve en `reason`. `uspub_reason_chk` sigue exigiéndolo en revertir y Legacy | A3.1 | A3 no tiene columna `note` | `StudioPublications::insertEntry()` |
| D-5 | `governance.studioPublication` es un extra de `GET engine/readings` y **no** entra en `catalog.readings`; su descriptor (etiqueta, origen, efecto) vive en el CRM (D-F6) | A4.9, A7 | Meterla en el catálogo obliga a subir `StudioCatalog::VERSION`, y el fixture fija `2026-10-19.1` | `StudioEngineReadings`; CRM `studio-engine-text.ts` |
| D-6 | **Rechazos auditados:** `version.{publish\|promote\|rollback}_refused {code}` solo para `publishing_disabled`, `engine_not_reading_versions`, `scope_not_allowed` y `restore_integrity_mismatch`, deduplicados, y solo si la versión existe (un uuid inventado no escribe en el libro). El 403 `forbidden` no se audita, como en F; los cuatro ojos van como `version.four_eyes_denied {action}`. Las puertas que dependen de hechos (ventana, freno, evidencia…) no se auditan | A5.1, A8 | El libro no se puede vaciar: una fila por cada clic fuera de hora lo llenaría | `StudioPublications::assertKeys()`, `recordRefused()` |
| D-7 | `blocking` de la vista previa incluye `forbidden` y `four_eyes_required` (dependen de quien mira, igual que `requirements.fourEyes`) y los códigos de las llaves (`publishing_disabled`, `engine_not_reading_versions`). `previewHash` **no** depende de quien mira (Q-4) | A7 | La vista previa tiene que explicar por qué esa persona no puede publicar | `StudioPublications`, armado de `blocking` en la vista previa |
| D-9 | `GET versions/{uuid}/canary-evidence` de una versión que no está en `CANARY` → 409 `invalid_transition` con `currentStatus` y `allowedFrom: ["CANARY"]` | A6, A7 | El mismo cuerpo que las transiciones | `StudioPublications::evidence()` |
| D-10 | `engine_has_no_canary` no se alcanza por HTTP con el catálogo actual: `governance.canaryOnly` es `must_match` y su lectura es «canario configurado», así que sin canario falla antes `engine_value_mismatch`. La puerta existe y la prueban la paridad unitaria (`StudioPublicationGatesParityTest`) y la mutación M13; el E2E con la pila real lo confirmó | A5.1 paso 10 | Orden de A5.1 | `UltronStudioPublicationLifecycleTest::httpExpectation()` |
| D-13 | **422 antes que las llaves:** la forma del cuerpo se valida en el controlador, antes del servicio. `POST publish` con el cuerpo vacío responde 422, no 409 `publishing_disabled` | A5.1 (orden) | Validar la forma no revela nada ni escribe nada | `UltronStudioVersionController::publish()` |
| D-18 | **Ventana:** la etiqueta lleva raya (`07:00–19:00`: `requirements.window.window` y el mensaje de `outside_publication_window`) y la variable de entorno, guion (`07:00-19:00`). Mal escrita, con inicio ≥ fin o cruzando la medianoche → `07:00-19:00`; vacía (`ULTRON_STUDIO_PUBLISH_WINDOW=`) → sin ventana. Intervalo `[inicio, fin)` por minutos, hora de Bogotá | A13 | Un error de escritura no abre la puerta sin que nadie lo decida | `config/ultron_studio.php` (`$ventana`), `StudioPublishing::windowFor()` |
| AS-8 | **Paginación** de `GET publications` y de `GET rules?status=archived`: la API lee `per_page` (como `GET versions`), no `perPage` como escribe A7; `perPage` se ignora. `page` y `per_page` se validan enteros ≥ 1: `0`, negativos o no enteros → **422** (no se convierten en 20, como decía `informe-api.md` §1). Por encima de 50 → 50; por defecto, 20 | A7, A10.2 | Coherencia con el resto del Studio | `UltronStudioPublicationController::index()`, `UltronStudioRuleController`, `ResolvesPagination::resolvePerPage()` |

D-8 (ayuda de `ultron.rollback`), D-11, D-12, D-14, D-15, D-16 y D-17 son de implementación dentro del contrato y están descritas en `informe-api.md` §5.

### E2.2 Configuración y limitadores

- **M-1 (= AS-1) · A13.** `ULTRON_STUDIO_PUBLISHING_ENABLED` se lee con `filter_var(…, FILTER_VALIDATE_BOOLEAN)` y `StudioPublishing::flagEnabled()` exige `=== true`. **Solo `true` (y `1`, `on`, `yes`) la enciende**; `false`, `off`, `no`, vacío o cualquier otro texto la dejan apagada. Con `2bc053b`, `(bool) env()` daba por encendidos `off`, `no` o `disabled`; corregido en `d24c512`, con prueba negativa. El mismo arreglo se aplicó a `ULTRON_STUDIO_LAB_ENABLED` (Hito 4). Con esto, la fila «Valor inválido: `false`» de A13 es cierta para esta llave.
- **AS-2 · A6.** `POST versions/{uuid}/promote` lleva también `throttle:ultron-studio-evidence`: promover calcula la evidencia entera (acta incluida) y comparte el cubo de `GET canary-evidence`, **10/min por persona y 30/min por red** (el de red es D-16). Antes, una persona con dos sesiones llegaba a 68 actas por minuto. Corregido en `602c4b9`, con prueba negativa.

### E2.3 Decisiones del CRM fuera del contrato (A11)

Detalle y motivos en `informe-front-integracion.md` §3:
- **D-F1.** Sin la llave o con el servidor negando, no hay botón y el motivo se lee en texto visible, solo en el estado en que la acción aplicaría.
- **D-F2.** Con backend H5, «Publicar» sale de cada versión y no de la cabecera; con backend F sigue el de la cabecera, deshabilitado.
- **D-F3.** «Volver a Legacy» y el historial viven en la barra de la publicación (son de la cadena, no de una versión), solo en la fase `ready`.
- **D-F4.** El detalle abierto se relee cuando se mueve la cabeza de la cadena.
- **D-F5.** Lo reconocido se borra con cualquier `previewHash` nuevo; tras `no_effect_not_acknowledged` se marca lo que faltaba.
- **D-F6.** Descriptor del CRM para `governance.studioPublication` (consecuencia de D-5).
- **D-F7.** Aviso de datos personales en la nota de publicar y de promover: va a `reason`, en una cadena que no se borra.
- **D-F8.** Espacios entre elementos en línea con `&ngsp;`.
- **D-F9.** Margen en el pie de los dos diálogos nuevos a 390 px por el asistente flotante del shell; `ib-reason-dialog` (compartido) no se tocó.

### E2.4 Corrección de la documentación de apoyo (B-3)

El riesgo R6 del plan (`plans/ULTRON_STUDIO_H5_PLAN.md` §9) dice que, tras rotar la `APP_KEY`, basta con «volver a publicar». **No es posible:** con la cabeza firmada con la clave vieja, publicar, promover y revertir responden 409 `publication_head_invalid`; la única escritura posible es volver a Legacy, y recuperar la declaración exige una versión nueva con su ciclo completo (aprobación de otra persona, laboratorio, ventana, canario y promoción). Las versiones que pasan a `ROLLED_BACK` o `RETIRED` no vuelven. El procedimiento está en `ULTRON_STUDIO_ROLLBACK.md` («Hito 5»). La `APP_KEY` alimenta otros mecanismos (por ejemplo, el `decide_token`): rotarla es un evento de todo el sistema y H5 no lo justifica por sí solo.

### E2.5 Diferido al Hito 7 (no implementado en H5)

- **B-2 · `verified` por entrada del historial.** `GET publications` y el detalle de la versión enseñan cada entrada sin verificar su huella ni su firma; solo se verifica la cabeza. Explotarlo exige DML directo en la base de producción y solo falsea lo que se enseña: el motor y el Studio verifican la cabeza, y la restauración verifica su entrada.
- **AS-3 / N-3 · `seq` envenenado con DML.** Una fila insertada con `seq = 2147483647` se convierte en la cabeza y no verifica: volver a Legacy responde 500 (22003), revertir 409, y la fila no se puede borrar con el rol de la aplicación. Corrección propuesta: un disparador que obligue a `seq` = siguiente (y a encadenar con la cabeza), en una migración aditiva con su recuento. **Mientras tanto** (runbook): salida inmediata con `h5-engine-scope.sh off <alcance actual>` y reparación solo con el rol dueño y la autorización del propietario.
- **`CHECK (seq >= 1)`**, aditivo, junto con lo anterior.
- **El envoltorio de migración como root.** `/usr/local/bin/ironbody-migrate` ejecuta `php artisan migrate` como root sobre un árbol de `www-data` (la clase de riesgo de M-3), y su `config:cache` nace con la umask de `www-data` antes del `chmod 600` (N-7). Pendiente del propietario.
- **Caracteres invisibles (AS-5).** `PlainText` deja pasar invisibles que no están en su lista (U+00AD, U+061C, U+180E, U+2028, U+2060 y las etiquetas U+E0000–E007F, entre otros). Desde H5 acaban en `ultron_studio_publications.reason`, que no se puede editar ni borrar. Decisión del propietario; mejor antes de la fase 2.

**AS-4 / H-1 (doble envío intercalado con la misma `clientRequestId`): corregido en `96397ea`** (`StudioPublications::orReplayed()`: el reintento que cruza la confirmación de su gemela recibe 200 `replayed`, §A5.1 paso 3, en las cuatro escrituras; 38 carreras reales en PostgreSQL, `UltronStudioPublicationRaceTest`). Una llave reutilizada con otro cuerpo o por otra persona sigue siendo `client_request_reused`.

## Enmienda M-1 (lead, 11-11-2026 05:45 UTC): la versión publicada GOBIERNA los prompts de ULTRON por WhatsApp (de la opción A a la aplicación real)

Encargo del propietario del 11-11 («un cambio aprobado y publicado en ULTRON Studio debe reflejarse en una ejecución canaria real del motor; si todavía requiere editar un prompt independiente en n8n, la integración no puede declararse terminada»). Responde a B-A1 y a Q6 de la auditoría. **El texto normativo de esta enmienda son las secciones B.3 a B.8 y D de `plans/STUDIO_MOTORES_PLAN.md` (arquitecto, 11-11), que el lead congela aquí como contrato**, con estas decisiones:

| Id | Qué | Decisión del lead |
|---|---|---|
| M1-1 | Opción | La (1) del plan: Laravel compila los cinco prompts (base del repositorio idéntica byte a byte al flujo vivo de n8n `29de5119`, más parches anclados por cada valor gobernado) y los entrega en `decide` solo si la huella recalculada es la firmada al publicar; n8n los usa y, si no los recibe o no cuadran, usa los de siempre. Los prompts dejan de editarse a mano en n8n (G1, G10) |
| M1-2 | Alcance de esta entrega (G2) | Gobiernan `persona.tone`, `persona.emojis`, `persona.maxChars` y `strategy.goal`. `intent.fallback` y las reglas S-NNN **siguen sin efecto en WhatsApp** (texto libre del personal en un prompt de producción es un paquete aparte, con un laboratorio que use los prompts reales): se dice así en el CRM y en el informe |
| M1-3 | Textos de los parches (G3) | Aprobados los borradores de B.3 tal cual (`UltronPromptCompiler::VERSION = 1`). El propietario puede cambiarlos después: versión nueva del compilador y nueva publicación |
| M1-4 | Línea base (G4) | Aceptada: el valor de la línea base significa «como hoy» (`compile(línea base) === base` byte a byte) |
| M1-5 | Contratos | Publicación `ultron-engine-config/2` (aditiva; Legacy siempre en `/1`); `decide` con `prompt_contract` negociado y `studio_prompts`; `commit` con el bloque opcional `prompts` y el sello `metadata.studio_prompts` (estados `applied`, `legacy`, `engine_fallback`, `mismatch`, `legacy_drift`, `not_reported`, `unverified`); llave `MARKETING_ULTRON_STUDIO_PROMPTS` (`off` por defecto, `canary`); todo falla hacia Legacy y nunca deja un turno mudo |
| M1-6 | Frontera A9 enmendada | El motor lee una tabla del Studio desde una clase; la invocan el commit (sello) y `UltronPromptOffer` (oferta de prompts en `decide`). El Studio importa del motor solo `UltronPromptBase` y `UltronPromptCompiler`, puros |
| M1-7 | Puerta del laboratorio (G6) | Se mantiene `required` (no se renuncia a un control): antes de publicar V2, una ejecución real del laboratorio del H4 sobre esa versión y el veredicto de otra persona; así se cierra además la validación 2 del H4. Si el propietario prefiere `waived` para las publicaciones de aceptación, lo decide él y queda escrito en cada publicación |
| M1-8 | Alcance del cambio en n8n | Hoy el flujo «Commercial Advisor» solo lo recorre la conversación canaria (`UltronEventEmitter.php:84-87`): el cambio no toca a ningún cliente. Vuelta atrás por el historial de versiones de n8n, nunca reimportando el respaldo |
| M1-9 | Calendario | Se adelanta un día: construir el 11-11 y el 12-11, activar el 12-11 y el 13-11, con las etapas E0–E5 del plan, solo en la conversación canaria |
| M1-10 | El catálogo del Studio (lead, 11-11 12:15 UTC, a propuesta de B-S) | **NO se toca en esta entrega.** Subir `StudioCatalog::VERSION` o cambiar los textos de los bindings rompe la congelación de `2026-10-19.1` (protección 11 del H8 y 15 del H9): toda versión aprobada dejaría de compilar a voz (`version_catalog_mismatch`) y las estrategias del H9 caerían en `baseline_mismatch`. Lo que gobierna y lo que no se dice donde se decide: en la vista previa y en el diálogo de publicar (`effect`, tabla de cambios, clase `prompt`; `intent.fallback` y las S-NNN como «sin efecto»). El diff queda sin aplicar en `plans/informes-motores/propuesta-catalogo-bs.diff` para un cambio de catálogo planificado |
| M1-11 | `persona.maxChars` fuera de paso | El normalizador admite cualquier entero de 160 a 480 y el compilador solo múltiplos de 20 (el control del CRM solo produce múltiplos de 20). Una versión con otro valor se aprueba pero, con la llave encendida, no se publica: 409 `prompts_compile_failed`, antes de firmar nada (falla cerrado). No se toca la validación congelada |
| M1-12 | Partes arrastradas (decisión de B-S, confirmada por el lead) | Publicar en `/2` compila la parte del resto desde su versión si era `/1`, la enseña en la vista previa (`prompts.carried`) y la mete en `previewHash`; promover y revertir no compilan nada: escriben `/2` solo si todas las partes ya lo eran y, si no, `/1` (la canaria vuelve a Legacy). Ninguna escritura da efecto a un paquete que nadie vio |
| M1-13 | Revisión de seguridad de la ola (api-security, 11-11): sin bloqueantes, altos ni medios | **M1-A (bajo):** el selector de n8n comprueba la integridad del paquete, no su autenticidad (la da el canal autenticado de `decide`); una respuesta de `decide` alterada impondría prompts y Laravel lo detecta después (`mismatch`) sin frenarlo (E15). No se documenta como «solo elige Legacy»; `mismatch` y `legacy_drift` se pintan en ROJO en el CRM y se miran con `ultron:prompt-trace` tras cada prueba canaria; la alerta automática (incidente) queda pendiente. **M1-B (bajo):** los mandos de llaves conservan el modo del fichero de entorno, se restauran solos si fallan a medias y borran su copia al aplicar sin desvíos (hecho en `h5-engine-scope.sh` y `motores/motores-prompts-scope.sh`). Pendiente antes de E2: la revisión del paquete del Studio que escribe `/2` |
| M1-14 | n8n: copia en vez de borrador (lead, 11-11) | El cambio S1 va en un flujo NUEVO e inactivo (`QSklqHlsDIxucRfk`), importado de máquina a máquina con el importador oficial; el flujo vivo (`YspRwsXnqt33NouP`, `29de5119`) queda intacto como reserva. E1 = despublicar el vivo y publicar la copia; la vuelta atrás, al revés (no depende del historial de versiones de n8n). Ensayada dentro de n8n con datos fijados: la huella Legacy calculada por el selector es la del repositorio. Verificado además que n8n no reevalúa los marcadores `{{…}}` de una cadena devuelta por una expresión. Detalle: `ops/motores/n8n-copia-s1/LEEME.md` |

