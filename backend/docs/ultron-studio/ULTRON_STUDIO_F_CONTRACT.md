# ULTRON STUDIO · Hito F (persistencia): contrato congelado

**Fecha:** 18-10-2026. **Origen:** plan del `architect` sobre `feat/ultron-studio` (backend `93fdc06`, CRM `4f7d774`). Este documento es el contrato que implementan `database-specialist`, `backend` y `frontend`; cualquier desviación se documenta aquí antes de codificarla.

## Decisiones del lead

| # | Decisión | Elegida | Por qué |
|---|---|---|---|
| A | De dónde sale el catálogo | **A1**: clase PHP `StudioCatalog` + comando que exporta fixtures TS | Convención del repo (`PermissionCatalog`, `permissions:export`); la autoridad queda en el servidor y el chunk público deja de llevar el catálogo (cierra H1 de forma estructural) |
| B | Dónde se audita | **B1**: tabla propia `ultron_studio_audit_events`, solo inserción, con disparadores | `AuditTrail` exige `Request`; `audit_logs` no está protegida en la base |
| C | `localStorage` | **C1**: desaparece; importación única del borrador de la vista previa; aviso `beforeunload` | Una sola fuente de verdad |
| D | Bandera de publicación | **D1**: `ULTRON_STUDIO_PUBLISHING_ENABLED` (apagada) + constante `StudioPublishing::ENGINE_READS_VERSIONS = false` → publicar y revertir responden 409 siempre en F | Marcar «PUBLISHED» algo que el motor no lee sería mentir |
| E | `ultron.manage_settings` | **E1**: se pospone a la fase con ajustes reales | Un permiso sin ruta rompe `AuthorizationCoverageTest`; no hay ajuste legítimo en F |
| F | Historia de las reglas | **F1**: `revision` + auditoría con antes/después + copia dentro de cada versión | Misma trazabilidad con una tabla menos |
| — | Defaults del Administrador | `view`, `edit_draft`, `manage_rules`, `approve`; **sin** `publish` ni `rollback` (se conceden a mano desde Roles) | Separación de quien aprueba y quien publica |

**No se ejecuta nada en producción** (migraciones, importación, despliegue) sin autorización expresa. Orden futuro: migraciones → código y `config:cache` (reaplicar `chown www-data`) → `ultron-studio:import-baseline` → CRM con `rsync` sin `--delete`.

### Correcciones al encargo y a `ULTRON_STUDIO_BACKEND_REPORT.md`

- **FKs a `admins`, no a `users`** (punto 1).
- **Prefijo `ultron_studio_`, no `ultron_`.** `ultron_aborts` pertenece al motor, y la frontera «el motor no lee el Studio» tiene que poder comprobarse con un grep.
- **`ultron.manage_settings` no puede existir en F sin un endpoint real:** el test del punto 3 rompería CI. Lo trato en la opción E.
- **Estados.**
  - `VALIDATING` no se guarda: la validación es pura e inmediata.
  - Hace falta un estado nuevo, `REJECTED`.
  - `CANARY`, `PUBLISHED`, `RETIRED` y `ROLLED_BACK` no se pueden alcanzar en F.
- **No hay tabla `ultron_rule_versions`.** La historia de una regla queda en su `revision`, en la auditoría y en la copia que guarda cada versión.
- **`LEGACY_BASELINE_V1` no es una versión.**
  - Es la línea base del código (va dentro del catálogo) y el nombre del lote de reglas importado.
  - Guardarla como una versión «PUBLISHED» sería mentir: el motor no la lee del Studio.
- **Quedan fuera de F**, aunque el informe previo las listaba: el vínculo conversación↔versión, evaluaciones, escenarios, campañas, canarios y pruebas telefónicas.


### A. Diseño congelado (contrato)

#### A1. Modelo de datos

Dos migraciones, compatibles con PostgreSQL 14 y SQLite:
- Sin `jsonb`: se usa `json`. En PG el tipo `json` no tiene operador de igualdad, y por eso los disparadores comparan por lista de columnas y no por valor.
- Sin índices parciales.
- Todas las FKs se crean junto con la tabla. Nunca con `Schema::table` después, porque SQLite reconstruye la tabla y pierde índices y disparadores.

**`2026_10_18_100001_create_ultron_studio_tables.php`**

```
ultron_studio_drafts                         — un borrador por persona, editable, con revisión
  id bigIncrements · uuid uuid UNIQUE
  admin_id foreignId→admins cascadeOnDelete UNIQUE     ← unicidad del borrador por persona
  revision unsignedInteger NOT NULL                     (1 en el primer guardado, +1 por guardado)
  catalog_version string(32) NOT NULL
  content json NOT NULL      {definition:{layers,edgeIds}, configs, layout:{positions, viewport|null}}
  content_hash char(64) NOT NULL · timestamps

ultron_studio_versions                       — copias inmutables
  id · uuid UNIQUE · client_request_id uuid UNIQUE NOT NULL
  label string(80) NOT NULL · note string(500) NULL
  status string(20) NOT NULL default 'DRAFT'
  content json NOT NULL      {catalogVersion, definition, configs, layout, rules[]}
  content_hash char(64) NOT NULL INDEX · catalog_version string(32) · catalog_hash char(64)
  source_draft_revision unsignedInteger NOT NULL
  created_by_admin_id unsignedBigInteger NOT NULL (instantánea, SIN FK) · created_by_name string(120) NOT NULL · created_by_role string(60) NULL
  last_validation json NULL  {catalogVersion, validatedAt, publishable, warnings[], findings[]}
  submitted_at · status_changed_at timestamp NULL · timestamps
  INDEX (status,id) usv_status_idx · INDEX (created_by_admin_id,id) usv_creator_idx
  Solo cambian: status, last_validation, submitted_at, status_changed_at, updated_at.

ultron_studio_version_reviews                — decisiones de cuatro ojos (solo inserción)
  id · version_id foreignId→ultron_studio_versions restrictOnDelete UNIQUE (una decisión por versión en F)
  decision string(16) approved|rejected
  reviewer_admin_id unsignedBigInteger NOT NULL (sin FK) · reviewer_name string(120) · reviewer_role string(60) NULL
  note string(500) NULL (motivo obligatorio si es rechazo) · acknowledged_warnings json NULL
  validation json NOT NULL · content_hash char(64) NOT NULL (lo que se revisó) · created_at timestamp NOT NULL

ultron_studio_rules
  id · uuid UNIQUE · code string(16) UNIQUE        R-01…R-12 (del código) · S-001… (del Studio)
  source string(20) legacy_baseline|studio · enforcement string(10) code|none · status string(10) active|draft|archived
  description string(500) · type string(16) restriction|permission|condition|style · priority string(10) critical|high|normal
  layers json · refs json {configs:[{layer,key}], edges:[], knowledgeKeys:[], knowledgeCategories:[]}
  origin string(200) NULL (clases que la aplican, solo en las del código) · revision unsignedInteger default 1
  content_hash char(64) · client_request_id uuid NULL UNIQUE
  created_by_admin_id / updated_by_admin_id unsignedBigInteger NULL (NULL = comando) · created_by_name / updated_by_name string(120) NULL
  archived_at timestamp NULL · archive_reason string(500) NULL · timestamps · INDEX (status, code)

ultron_studio_audit_events                   — libro de solo inserción
  id · event string(48) · subject_type string(16) draft|version|rule|system · subject_uuid uuid NULL
  actor_type string(10) admin|system · actor_admin_id unsignedBigInteger NULL (sin FK) · actor_name string(120) · actor_role string(60) NULL
  from_status / to_status string(20) NULL · content_hash char(64) NULL · summary string(255)
  diff json NULL · metadata json NULL · ip_address string(64) NULL · user_agent string(255) NULL · created_at NOT NULL
  INDEX (subject_type,subject_uuid,id) usae_subject_idx · (actor_admin_id,id) usae_actor_idx · (event,id) usae_event_idx
```

**`2026_10_18_100002_add_ultron_studio_guards.php`**

En PostgreSQL:
- **CHECK de dominio** para todos los enumerados, más:
  - `usv_status_chk`: `status IN ('DRAFT','VALIDATED','REVIEW_REQUIRED','APPROVED','REJECTED')`. Un estado que el motor no respalda no puede existir en la base.
  - `usr_studio_never_active_chk`: `source <> 'studio' OR (status IN ('draft','archived') AND enforcement = 'none')`.
  - `usr_legacy_is_code_chk`: `source <> 'legacy_baseline' OR enforcement = 'code'`.
- **Función** `ultron_studio_forbid()` en plpgsql, que lanza `RAISE … ERRCODE 'P0001'`.
- **Disparadores:**
  - En auditoría y reseñas: `BEFORE UPDATE OR DELETE FOR EACH ROW` y `BEFORE TRUNCATE FOR EACH STATEMENT`.
  - En versiones: `BEFORE DELETE`, `BEFORE TRUNCATE` y `BEFORE UPDATE OF uuid, client_request_id, label, note, content, content_hash, catalog_version, catalog_hash, source_draft_revision, created_by_admin_id, created_by_name, created_by_role, created_at`.
- **Sin tolerar errores**, a diferencia de `btree_gist`: aquí la protección es un requisito, así que si algo falla, la migración falla.

En SQLite: los mismos disparadores con `RAISE(ABORT,'…')` (SQLite no tiene TRUNCATE). No hay CHECK porque SQLite no admite añadir restricciones.

`down()` quita disparadores, funciones y restricciones con `IF EXISTS`. La primera migración borra las tablas en orden inverso.

**Inmutabilidad en tres capas:**
1. No existe ninguna ruta ni método de servicio que edite contenido.
2. En los modelos, `updating` lanza excepción si `isDirty(self::IMMUTABLE)`, `deleting` lanza siempre, `$guarded = ['*']`, y los servicios escriben solo con `forceFill`.
3. En la base, los disparadores.

Además, al leer una versión se recalcula la huella y se devuelve `integrityOk`.

**Unicidad del borrador:** `UNIQUE(admin_id)`. El primer guardado (`baseRevision = 0`) inserta dentro de un SAVEPOINT (`DB::transaction` anidada, como `installExtension` en `2026_10_17_200002`). Si choca con la unicidad, la respuesta es 409 `draft_conflict`. Sin el savepoint, en PostgreSQL la transacción quedaría abortada (25P02).

#### A2. Máquina de estados de las versiones

En F no hay ninguna transición hacia atrás. `REJECTED` es terminal.

| Desde | Acción | Hacia | Permiso | Quién | Qué revalida el servidor |
|---|---|---|---|---|---|
| — | crear desde el borrador | `DRAFT` | `ultron.edit_draft` | Cualquiera con el permiso, desde su propio borrador | El borrador existe y `draftRevision` coincide. Se valida el grafo y la configuración contra el catálogo actual; si hay bloqueos, responde 422 `validation_blocked` y no crea nada. Copia las reglas no archivadas, calcula la huella y es idempotente por `clientRequestId`. |
| cualquiera | validar (simulación) | sin cambio | `ultron.view` | Cualquiera | Valida contra el catálogo actual. No escribe nada ni deja auditoría. |
| `DRAFT` | pedir revisión | `VALIDATED` sin avisos, o `REVIEW_REQUIRED` con avisos | `ultron.edit_draft` | Solo quien la creó (si no, 403 `not_version_owner`) | Comprueba la integridad y revalida. Con bloqueos (posibles si cambió el catálogo) responde 422 y sigue en `DRAFT`. La transición es una actualización condicionada al estado esperado. |
| `VALIDATED` o `REVIEW_REQUIRED` | aprobar | `APPROVED` | `ultron.approve` | **Distinto del creador** (si no, 403 `four_eyes_required` y queda auditado) | Revalida. Con bloqueos responde 409 `revalidation_blocked`. El conjunto `acknowledgedWarnings` tiene que ser igual a los avisos actuales (si no, 409 `warnings_not_acknowledged`). Actualización condicionada y fila en `version_reviews`. |
| `VALIDATED` o `REVIEW_REQUIRED` | rechazar | `REJECTED` | `ultron.approve` | Distinto del creador | Exige un motivo de 5 a 500 caracteres. |
| cualquiera | publicar o revertir | sin cambio, 409 | `ultron.publish` / `ultron.rollback` | Cuando exista: quien publica también será distinto del creador | `StudioPublishing::refusal()` responde 409 `publishing_disabled` (bandera apagada) o `engine_not_reading_versions` (bandera encendida). Queda auditado. |

- `VALIDATING`, `CANARY`, `PUBLISHED`, `RETIRED` y `ROLLED_BACK` siguen en el enum `StudioVersionStatus` y en el tipo TS, pero con `reachable: false` y su motivo. `GET status` los devuelve con etiqueta para que el CRM pinte la tubería completa en gris sin inventarse textos.
- La confirmación de los parámetros importantes sigue en el navegador, pero ya no basta: los valores prohibidos bloquean y los avisos exigen que una segunda persona los reconozca al aprobar.

#### A3. API (`/api/admin/ultron-studio`)

- Todo en camelCase, salvo las claves del paginador de Laravel.
- Envoltura `{ok:true, data}`.
- Cada controlador exige además una persona (403 `person_required`).

| # | Método y ruta | Permiso | Cupo |
|---|---|---|---|
| 1 | `GET status` | view | lectura |
| 2 | `GET catalog` | view | lectura |
| 3 | `GET draft` | view | lectura |
| 4 | `PUT draft` | edit_draft | escritura |
| 5 | `POST draft/discard` | edit_draft | escritura |
| 6 | `GET versions` | view | lectura |
| 7 | `POST versions` | edit_draft | escritura |
| 8 | `GET versions/{uuid}` | view | lectura |
| 9 | `POST versions/{uuid}/validate` | view | lectura |
| 10 | `POST versions/{uuid}/submit` | edit_draft | escritura |
| 11 | `POST versions/{uuid}/approve` | approve | escritura |
| 12 | `POST versions/{uuid}/reject` | approve | escritura |
| 13 | `POST versions/{uuid}/publish` | publish | escritura |
| 14 | `POST versions/{uuid}/rollback` | rollback | escritura |
| 15 | `GET rules` | view | lectura |
| 16 | `POST rules` | manage_rules | escritura |
| 17 | `PATCH rules/{uuid}` | manage_rules | escritura |
| 18 | `POST rules/{uuid}/archive` | manage_rules | escritura |
| 19 | `GET audit` | view | lectura |

**Ejemplos de cuerpo y respuesta:**

```jsonc
// GET status → 200
{"ok":true,"data":{
  "engine":{"readsVersions":false,"activeVersion":null,"message":"El motor de ULTRON no lee versiones del Studio: aprobar aquí no cambia al agente."},
  "publishing":{"available":false,"code":"publishing_disabled","message":"Publicar está desactivado: el motor de ULTRON aún no lee versiones del Studio."},
  "can":{"view":true,"editDraft":true,"manageRules":true,"approve":true,"publish":false,"rollback":false},
  "catalog":{"version":"2026-10-18.1","hash":"9f…64"},
  "lifecycle":[{"status":"DRAFT","label":"Creada, sin enviar","reachable":true},
               {"status":"VALIDATING","label":"Validando","reachable":false,"reason":"La validación es inmediata: ninguna versión queda en este estado."},
               {"status":"CANARY","label":"Canario","reachable":false,"reason":"El motor aún no lee versiones."} /* … los 10 */],
  "legacyBaseline":{"label":"LEGACY_BASELINE_V1","rulesImported":12}}}

// GET catalog → 200: forma EXACTA de StudioCatalog/LayerParameter/StudioDefaults del TS (claves opcionales omitidas, no false)
{"ok":true,"data":{"version":"2026-10-18.1","hash":"…",
  "layers":[{"id":"knowledge","order":1,"name":"Conocimiento","responsibility":"…","icon":"menu_book","accent":"violet","critical":true,
             "realComponents":[{"label":"…","kind":"table","ref":"marketing_knowledge_items · MarketingKnowledgeItem"}],"configurableToday":["…"],"fixedInCode":["…"]}],
  "ports":[{"id":"knowledge:out:facts","layer":"knowledge","direction":"out","dataType":"facts","label":"Hechos autorizados"}],
  "edges":[{"id":"E14","source":"rules:out:payment","target":"integrations:in:payment","label":"…","mandatory":false,"consequence":"…"}],
  "requiredPaths":[{"id":"entrada-a-envio","from":"context","to":"integrations","title":"…","detail":"…"}],
  "constraints":[{"id":"cobro-sin-camino","layer":"rules","key":"paymentLinksEnabled","when":true,"requires":["E14","E16"],"level":"blocking","title":"…","detail":"…"}],
  "parameters":{"persona":[{"key":"maxChars","label":"Longitud máxima","hint":"…","type":"range","min":160,"max":480,"step":20}]},
  "baseline":{"label":"LEGACY_BASELINE_V1","description":"Lo que hace hoy el motor según el código. No es una versión: el motor no la lee del Studio.",
              "positions":{"knowledge":{"x":672,"y":0}},"edgeIds":["E01","…"],"configs":{"rules":{"handoffOnlyCorroborated":true,"paymentLinksEnabled":false}}}}}

// GET draft → 200 (sin fila: exists:false, revision:0, content = baseline, isBaseline:true)
{"ok":true,"data":{"exists":true,"isBaseline":false,"revision":7,"savedAt":"2026-10-18T15:04:05+00:00","catalogVersion":"2026-10-18.1","contentHash":"…",
  "content":{"definition":{"layers":["knowledge","persona","intent","context","memory","strategy","rules","composer","integrations","governance"],"edgeIds":["E01","…"]},
             "configs":{"persona":{"tone":"cercano","emojis":true,"maxChars":320}},
             "layout":{"positions":{"knowledge":{"x":672,"y":0}},"viewport":{"x":-120.5,"y":40,"scale":0.8}}},
  "validation":{"catalogVersion":"2026-10-18.1","publishable":true,"warnings":["opcional-E14","opcional-E16"],
                "findings":[{"id":"capas-criticas","level":"ok","title":"Las diez capas están presentes","detail":"Ninguna capa crítica falta."}]}}}

// PUT draft  {"baseRevision":7,"content":{"definition":{"edgeIds":[…]},"configs":{…},"layout":{"positions":{…},"viewport":null}}}
//   → 200 con la forma de GET y revision 8  ·  409 {"ok":false,"code":"draft_conflict","message":"…","current":{…GET…}}  ·  422 {message, errors:{"content.configs.persona.maxChars":["…"]}}
// POST draft/discard  {"baseRevision":8} → 200 con la forma de GET (isBaseline:true, revision 9). NO borra la fila: nada se borra en el Studio.

// POST versions  {"label":"Cobro con enlaces (propuesta)","note":null,"draftRevision":8,"clientRequestId":"6f1c…"}
//   → 201 {"ok":true,"data":VersionDetail} · 200 {"ok":true,"replayed":true,"data":…} · 404 draft_not_found · 409 draft_conflict · 422 validation_blocked {findings}
// VersionSummary (lista; GET versions?status=VALIDATED,REVIEW_REQUIRED&scope=all|mine|reviewable&page&per_page → paginador)
{"uuid":"…","label":"…","status":"REVIEW_REQUIRED","statusLabel":"Con avisos · espera revisión","contentHash":"…","shortHash":"a1b2c3d4",
 "catalogVersion":"2026-10-18.1","createdBy":{"name":"Ana Pérez","role":"Administrador"},"mine":false,"createdAt":"…","submittedAt":"…",
 "warnings":["opcional-E16","config-cobro-aprobacion"],"blocking":0,"review":null,"engineReads":false}
// VersionDetail = Summary + note, content{definition,configs,layout,rules[]}, lastValidation, integrityOk,
//   review{decision,by{name,role},at,note,acknowledgedWarnings},
//   actions{submit,approve,reject,publish,rollback: {allowed:bool, code?, message?}}   ← p. ej. approve {allowed:false, code:"four_eyes"}

// POST versions/{uuid}/validate → 200 {"ok":true,"data":{"catalogVersion","publishable","warnings","findings","wouldBecome":"REVIEW_REQUIRED"|"VALIDATED"|null}}
// POST versions/{uuid}/submit (sin cuerpo) → 200 VersionDetail · 403 not_version_owner · 409 invalid_transition {currentStatus,allowedFrom} · 422 validation_blocked
// POST versions/{uuid}/approve {"acknowledgedWarnings":["opcional-E16","config-cobro-aprobacion"],"note":"…"}
//   → 200 · 403 four_eyes_required · 409 invalid_transition | warnings_not_acknowledged {warnings} | revalidation_blocked {findings} | integrity_mismatch
// POST versions/{uuid}/reject {"reason":"Falta el resultado de Wompi"} → 200 · 403 four_eyes_required · 409 invalid_transition · 422
// POST versions/{uuid}/publish|rollback → 409 {"ok":false,"code":"publishing_disabled","message":"Publicar está desactivado: el motor de ULTRON aún no lee versiones del Studio."}

// GET rules?status=active,draft (por defecto, sin archivadas) → {"ok":true,"data":[RuleDto]}
{"uuid":"…","code":"R-02","description":"Ningún descuento: …","type":"restriction","priority":"critical","layers":["strategy","rules"],
 "refs":{"configs":[{"layer":"strategy","key":"discountsAllowed"}],"edges":[],"knowledgeKeys":[],"knowledgeCategories":[]},
 "source":"legacy_baseline","enforcement":"code","status":"active","origin":"SalesAgentDecisionSchema (patrones de descuento)","revision":1,
 "knowledgeRefs":[{"key":"brand_copy.experiencia","exists":true,"reviewStatus":"approved","isActive":true}],
 "createdBy":null,"updatedBy":null,"createdAt":"…","updatedAt":"…","archivedAt":null,"archiveReason":null}
// POST rules {description,type,priority,layers,refs?,clientRequestId} → 201 (code S-NNN, status draft, enforcement none) · 200 replayed
// PATCH rules/{uuid} {baseRevision, …campos} → 200 · 409 rule_conflict {current} | legacy_rule_read_only | rule_archived · 422 (refs desconocidas, knowledgeKeys inexistentes)
// POST rules/{uuid}/archive {baseRevision, reason} → 200 · mismos 409

// GET audit?subjectType&subjectUuid&event&page&per_page → paginador de
{"id":812,"event":"version.approved","summary":"Aprobó «Cobro con enlaces (propuesta)»","actor":{"type":"admin","name":"Root","role":"Super Admin"},
 "subjectType":"version","subjectUuid":"…","fromStatus":"REVIEW_REQUIRED","toStatus":"APPROVED","contentHash":"…","diff":{…},"createdAt":"…"}
//   Sin ip ni user agent. Los eventos de borrador solo los ve su dueño, o quien tenga audit.view.
```

**Errores:**
- 401: sin credencial.
- 403: `forbidden` (con `required_permission`), `person_required`, `not_version_owner`, `four_eyes_required`.
- 404: `version_not_found`, `rule_not_found`, `draft_not_found`.
- 409: `draft_conflict`, `rule_conflict`, `invalid_transition`, `warnings_not_acknowledged`, `revalidation_blocked`, `integrity_mismatch`, `publishing_disabled`, `engine_not_reading_versions`, `legacy_rule_read_only`, `rule_archived`, `client_request_reused`.
- 422: el formato estándar de Laravel o `validation_blocked`.
- 429: `too_many_requests` con `retry_after`.

**Concurrencia:**
- Borrador y reglas: `baseRevision` más una actualización condicionada (`UPDATE … WHERE admin_id=? AND revision=?`; si no cambia ninguna fila, conflicto).
- Versiones: actualización condicionada por estado, seguida de la inserción de la reseña. No hace falta `FOR UPDATE`, así que no hay interbloqueos.

**Rutas:** `routes/ultron-studio.php` con prefijo `admin/ultron-studio`, dos grupos con `throttle:ultron-studio-read` (120/min) y `throttle:ultron-studio-write` (30/min), y `whereUuid('uuid')`. Se monta en el `then:` de `bootstrap/app.php`, igual que `marketing.php`.

**Limitadores:** `AppServiceProvider::limitadoresDelStudio()`, con clave `'ultron-studio:'.hash('sha256',$bearer)` o la IP.

#### A4. Permisos

| Llave | Etiqueta (`LABELS`) | Super Admin | Administrador | Recepción, Entrenador, Administrativo | Token compartido |
|---|---|---|---|---|---|
| `ultron.view` | Ver ULTRON Studio | ✓ | ✓ | — | — (excluido) |
| `ultron.edit_draft` | Editar el borrador y crear versiones | ✓ | ✓ | — | — |
| `ultron.manage_rules` | Administrar reglas del Studio | ✓ | ✓ | — | — |
| `ultron.approve` | Aprobar o rechazar versiones de otra persona | ✓ | ✓ | — | — |
| `ultron.publish` | Publicar versiones | ✓ | — (se concede a mano) | — | — |
| `ultron.rollback` | Revertir versiones | ✓ | — (se concede a mano) | — | — |

**Mecanismo, solo en código:**
- **`AuthorizationMap::OVERRIDES`:** las 19 claves `'MÉTODO api/admin/ultron-studio/…' => 'ultron.x'`, con su comentario. Los tres controladores no se añaden a `CONTROLLERS`.
- **`PermissionCatalog`:**
  - `DOMAINS`: entrada `'ultron'` después de `marketing` (etiqueta «ULTRON Studio», icono `account_tree`, pista «Borradores, versiones, reglas y aprobaciones del agente comercial. El motor no lee nada de esto todavía»).
  - `LABELS` y `HELP` para las seis llaves. La ayuda de `approve` dice «nadie aprueba lo que creó»; la de `publish` y `rollback`, «hoy desactivado en el servidor; no llega por defecto al Administrador».
  - Orden en `sortActions`: `edit_draft` 3, `manage_rules` 6, `approve` 7, `publish` 8, `rollback` 8.2.
- **`CrmPermission`:**
  - `SHARED_TOKEN_EXCLUDED_DOMAINS` gana `'ultron'`.
  - Las exclusiones del Administrador ganan `'ultron.publish'` y `'ultron.rollback'`, con el comentario «llegan nuevas y se reparten a mano», como `valuations.manage_settings`.
- **En el CRM:** se regenera `backend-permission-catalog.ts`, se añaden `ULTRON_VIEW`, `ULTRON_EDIT_DRAFT`, `ULTRON_MANAGE_RULES`, `ULTRON_APPROVE`, `ULTRON_PUBLISH` y `ULTRON_ROLLBACK` al enum, y se reflejan las exclusiones del Administrador en `access-control.service.ts:430-436`.
- **Segunda cerradura:** `requirePerson()` en los tres controladores. `marketing.view` no abre nada del Studio, y una prueba lo comprueba.
- **Cuatro ojos:** se compara la identidad (`actor.id` contra `created_by_admin_id`), no el permiso.

#### A5. Validación en el servidor, huellas y paridad

Todo esto vive en `app/Services/UltronStudio/`, sin Eloquent, sin `config()` y sin `now()`.

- **`StudioCatalog`** (`final`, estático):
  - `VERSION = '2026-10-18.1'`.
  - Métodos `toArray()`, `graph()`, `parameters()`, `baseline()`, `legacyRules()` (R-01…R-12, transcritas de `DEMO_RULES` con `refs` sugeridas: R-01 `knowledge.strictFacts`, R-02 `strategy.discountsAllowed`, R-07 `rules.handoffOnlyCorroborated`, R-10 `knowledgeCategories:[brand_copy, tone]`, R-12 `governance.canaryOnly`) y `hash()`.
  - Es una transcripción literal de `studio-catalog.ts`, con las mismas `ref` podadas.
  - Una prueba fija `[VERSION => hash]`: cambiar datos sin subir la versión rompe CI.
- **`StudioGraphValidator`** (estático):
  - Métodos `validate(array $catalog, array $definition, ?array $configs): list<Finding>`, `checkConfigs`, `findCycle`, `hasPath`, `isPublishable` y `warnings`.
  - Tiene que replicar exactamente la semántica del TS:
    - Las aristas presentes se recorren en el orden del catálogo.
    - El recorrido en profundidad de `findCycle` sigue el orden de `catalog.layers` y salta `feedback` y `opensTurn`.
    - Los hallazgos salen en el mismo orden: `capas-criticas`, `aristas-obligatorias`, `aristas-desconocidas`, `ciclos`, `camino-<id>`, `opcional-<edge>`, `aislada-<layer>`, `config-<id>`, `configuracion`.
    - Los títulos y detalles son literales, con los mismos separadores: `, `, ` · `, ` → ` y `(falta: …; sobra: …)`.
    - La clave `layer` se omite donde el TS no la pone.
    - La igualdad de configuración se compara con `json_encode` (como `JSON.stringify`), nunca con `===`.
- **`StudioDraftNormalizer::normalize(array $content): array`.** Lanza `ValidationException` con claves `content.*`.
  - Tamaño máximo de 64 KB codificado.
  - `edgeIds`: lista de cadenas `distinct` de aristas conocidas, devuelta en el orden del catálogo.
  - `layers`: se ignora lo que llegue y se usa el catálogo.
  - En `configs`, una capa o clave desconocida da 422. Los valores se comprueban por tipo de parámetro:
    - toggle: booleano estricto.
    - range: entero dentro de [min, max].
    - select: debe estar entre las opciones.
    - text: hasta 160 caracteres; textarea: hasta 600; ambos con `PlainText`.
    - tags: hasta 20 elementos de hasta 60 caracteres; se recortan y se descartan los vacíos.
    - Lo que falte se rellena con la línea base.
  - `positions`: enteros con valor absoluto hasta 100 000.
  - `viewport`: null o números finitos, con `scale` entre 0,3 y 2,2.
  - Cualquier otra clave se descarta.
  - El cliente adopta el contenido normalizado que devuelve el servidor.
- **`StudioContentHasher`:** JSON canónico (claves de objeto ordenadas recursivamente, listas en su orden, `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`, decimales rechazados) más sha256.
  - Borrador: `{catalogVersion, definition, configs}`.
  - Versión: lo mismo más `rules`, ordenadas por código, con `{code, revision, source, enforcement, status, description, type, layers, priority, refs}`.
  - La disposición **no cuenta**: dos versiones con el mismo comportamiento comparten huella.
  - Regla: `{description, type, layers, priority, refs, origin}`.
- **Paridad:**
  - `tests/fixtures/ultron-studio/validation-cases.json` contiene `{version:1, catalogs:{mini:{…MINI_CATALOG transcrito…}}, cases:[{name, catalog:"real"|"mini", definition, configs|null, expected:[…]}]}`, con entradas explícitas y sin marcadores.
  - Mínimo 24 casos:
    - Línea base con y sin configuración, todas las aristas, cobro encendido con y sin su camino, cada bloqueo de configuración, `apagado`, falta E10, `E99` desconocida, persona aislada y falta una capa crítica.
    - `configs: {}`.
    - Del catálogo mini: ciclo («Intención → Estrategia → Intención»), realimentación, `when/requires`, `forbidden`, camino ausente y `requires` + `forbids` juntos.
  - `StudioValidationParityTest` (PHP) y `studio-graph.parity.spec.ts` (TS) tienen que dar exactamente `expected`.
  - Si discrepan, se arregla el lado que se aparte de `studio-graph.ts@4f7d774`; nunca se edita `expected` para que pase.
  - Exportación: `ultron-studio:export-fixtures {--path=}` (por defecto `‹F›/src/app/modules/ultron-studio/testing`) escribe `backend-studio-catalog.testing.ts` (`export const BACKEND_STUDIO_CATALOG: StudioCatalogDto = {…}`) y `backend-validation-cases.testing.ts` (`BACKEND_VALIDATION_CASES: ValidationCasesFixture`).

#### A6. Auditoría (`StudioAudit::record(?Request, ?Admin, …)` y `page(Admin $viewer, filtros)`)

Los eventos de éxito se escriben en la misma transacción que el cambio. Los intentos denegados se escriben aparte, porque la operación falla.

| `event` | Desde → hacia | `diff` / `metadata` |
|---|---|---|
| `draft.saved` | — | `{fromRevision, toRevision, edgesAdded, edgesRemoved, configChanges:[{layer, key, from, to}], positionsChanged, viewportChanged}` |
| `draft.discarded` | — | Lo descartado respecto a la línea base (con eso se puede reconstruir) |
| `version.created` | → DRAFT | `{draftRevision, catalogVersion, warnings, rulesCount}` |
| `version.submitted` | DRAFT → VALIDATED / REVIEW_REQUIRED | `{warnings, findingsCount}` |
| `version.approved` / `version.rejected` | → APPROVED / REJECTED | `{acknowledgedWarnings, note}` / `{reason}` |
| `version.four_eyes_denied` | — | `{action}` |
| `version.publish_refused` / `version.rollback_refused` | — | `{code}` |
| `rule.created` / `rule.updated` / `rule.archived` | — | Campos / `{fromRevision, toRevision, changes:[{field, from, to}]}` / `{reason}` |
| `rule.legacy_imported` / `rule.legacy_synced` / `system.baseline_imported` | — | El actor es `system` (`ultron-studio:import-baseline`): `{created, updated, unchanged}` |

**No se auditan:** las lecturas, la simulación de validación, los 4xx de formato ni los conflictos (en ninguno cambió nada). Los valores en el diff son escalares de hasta 120 caracteres. El Studio no maneja datos de prospectos.

#### A7. Frontend (‹F›/`src/app/modules/ultron-studio/`)

**Ficheros nuevos:**
- **`studio-api.types.ts`:** todos los DTO de A3, más `StudioErrorCode`, `StudioApiError`, `StudioCatalogDto` (es `StudioCatalog` + `version`, `hash`, `parameters` y `baseline`) y `ValidationCasesFixture`.
- **`studio-api.service.ts`** (`UltronStudioApi`, `providedIn: 'root'`, base `${environment.adminApiBaseUrl}/ultron-studio`): un método por ruta; los errores se convierten en `StudioApiError {status, code, message, payload}`.
- **`studio-parse.ts`:** `readCatalog`, `readDraft`, `readVersion*`, `readRule`, `readStatus` y `readAuditPage`. Fallan cerrado: un catálogo ilegible lleva a `phase = 'error'`, **nunca** a un catálogo empaquetado de reserva.
- **`studio-session.ts`** (`@Injectable()`, provisto en la página):
  - Señales: `phase` (loading, ready, forbidden o error), `status`, `catalog`, `draftStore`, `viewStore`, `selection`, `versions`, `rules`, `saving` y `conflict`.
  - Métodos: `init()` (pide status, catalog, draft, rules y versions en paralelo), `save()` (PUT con `baseRevision`; un 409 rellena `conflict`), `resolveConflict('reload'|'overwrite')`, `discard()`, `createVersion(label, note)` (exige el borrador guardado y sin bloqueos), `open(selection)`, `submit`, `approve(uuid, ack, note)`, `reject(uuid, reason)`, `loadAudit(page)` y `refresh*`.
- **`studio-a11y.ts`:** registro de nombres de puerto, rellenado tras cargar el catálogo y vaciado al salir. `nombrePuerto` deja de leer `STUDIO_CATALOG` (`ultron-studio-page.ts:40-45`).
- **Paneles**, cada uno un componente standalone OnPush con su propia hoja de estilos (límite de 8 kB cada una) para no reventar los 32 kB de la página:
  - `studio-versions-panel.ts`: lista con filtros (todas, mías, por revisar); detalle; enviar; aprobar con lista de avisos a reconocer; rechazar con motivo; publicar deshabilitado con `status.publishing.message`; abrir en el tablero en solo lectura.
  - `studio-rules-panel.ts`: crear, editar y archivar; las del código, de solo lectura con la insignia «Código».
  - `studio-audit-panel.ts`: paginado, 20 por página.
- **Generados** (no se editan; solo los importan los specs): `testing/backend-studio-catalog.testing.ts` y `testing/backend-validation-cases.testing.ts`.

**Ficheros modificados:**
- **`studio-store.ts`:**
  - Se mantienen el constructor `(catalog, defaults, params, readOnly)` y todos los gestos.
  - Nuevos: `loadSnapshot(s, {revision, savedAt})`, que pasa por `sanitize` y limpia el historial; `markSaved(serverContent, {revision, savedAt})`, que adopta el contenido normalizado sin entrada en el historial; la señal `revision`; y `toDraftContent()`.
  - Se eliminan `STORAGE_PREFIX`, `load(userId)`, `saveDraft()` y `readPersisted()`, pero **solo en el paso 5**, cuando la página cambie, para no romperla antes.
- **`studio-model.ts`:**
  - `VersionStatus` gana `'REJECTED'`.
  - `StudioRule` se amplía con `uuid`, `code`, `source`, `enforcement`, `status` (que ya incluye `'archived'`), `revision` y `refs`.
  - `PublishedAgentVersion` se sustituye por los DTO de versión.
- **`ultron-studio-page.ts`:**
  - Estados de carga, prohibido y error con reintento.
  - El selector ofrece «Mi borrador», «LEGACY_BASELINE_V1 · línea base del código (solo lectura)» y las versiones de la API.
  - La insignia de estado usa `statusLabel`. La de conexión dice «Motor: no lee versiones».
  - Botones: Guardar (Ctrl+S) hace PUT; Descartar pide confirmación; Crear versión abre un diálogo.
  - Botones de paneles: Versiones, Reglas y Auditoría.
  - Publicar sigue siempre deshabilitado en F, con el `title` y la descripción accesible que manda el servidor.
  - El aviso de demo pasa a «El motor de ULTRON no lee nada de esto: guardar, crear o aprobar versiones no cambia al agente».
  - Diálogo de conflicto (recargar o sobrescribir).
  - Aviso `beforeunload` si hay cambios sin guardar.
  - Sin `edit_draft`, el lienzo queda en solo lectura.
  - Panel de validación: los hallazgos del cliente en vivo, más «Servidor (al guardar): …», que manda si discrepan.
- **`studio-inspector.ts`:** las reglas y los parámetros salen de la sesión.
- **`studio-catalog.ts`:** se elimina como datos de ejecución, en el commit B del paso 5.
- **`app.routes.ts:244`** y **`app.html:126`:** pasan a `Permission.ULTRON_VIEW`; la bandera `ultronStudioPreviewEnabled` se mantiene.
- **`permissions.enum.ts`**, **`backend-permission-catalog.ts`** (regenerado) y **`access-control.service.ts`** (exclusiones del Administrador).

**`localStorage`:** en `init()`, si existe `ironbody.ultronStudio.preview.v1.<id>`:
- Si el servidor no tiene borrador y la persona puede editar, se ofrece una vez «Importar el borrador de la vista previa». El borrador pasa por `sanitize` y se envía con PUT `baseRevision:0`, de modo que el servidor lo revalida.
- En cualquier caso, la clave se borra.

#### A8. Pruebas

**Backend (PHPUnit), en `tests/Unit/UltronStudio/` y `tests/Feature/UltronStudio/`:**
- **`StudioCatalogTest`:** coherencia (las 10 capas en orden, ids de puerto, tipos de cada arista, consecuencias de las opcionales, segmento sellado composer→rules→integrations), la línea base es publicable y solo avisa de `opcional-E14/E16`, parámetros ↔ configuración ↔ restricciones, sin cadenas que cartografíen el servidor (`app/Services`, `php artisan`, `HMAC`, `wamid`, `NO-GO`…), la versión fijada a su huella y las 12 reglas del código con referencias válidas.
- **`StudioGraphValidatorTest`**, **`StudioValidationParityTest`**, **`StudioDraftNormalizerTest`** (cada rechazo de A5, relleno con la línea base, recorte, claves ignoradas) y **`StudioContentHasherTest`** (orden de claves y de aristas indiferente, disposición excluida, orden de las etiquetas significativo, vector conocido).
- **`UltronStudioPermissionsTest`:**
  - Matriz de 19 rutas × {sin sesión → 401, token → 403, Recepción, Entrenador y Administrativo → 403, rol con solo `marketing.view` + `marketing.agent.manage` → 403, Administrador permitido salvo publish/rollback → 403, Super Admin permitido con publish/rollback → 409}.
  - `ultron.*` no está en `readOnly()`.
  - Con `adminWithPermissions(['ultron.view'])` se lee pero no se escribe.
- **`UltronStudioDraftTest`:** sin borrador devuelve la línea base con revisión 0; primer guardado; revisión vieja → 409 con `current`; guardado concurrente simulado; normalización; descartar sin borrar la fila; aislamiento por persona; auditoría con diff.
- **`UltronStudioVersionLifecycleTest`:** cada fila de A2; idempotencia; 404, 409 y 422; la versión copia las reglas; `integrityOk`; propia → 403 y evento `four_eyes_denied`; reconocimiento de avisos exacto; segunda aprobación → 409; `publish` → 409 con la bandera apagada y `engine_not_reading_versions` con ella encendida; ningún estado inalcanzable aparece nunca; `validate` no escribe nada.
- **`UltronStudioImmutabilityTest`:** Eloquent lanza excepción; el query builder crudo da `QueryException` por el disparador (también en SQLite); actualizar `status` en crudo sí funciona; auditoría y reseñas no aceptan UPDATE ni DELETE. En PG, cada sentencia que debe fallar va en una `DB::transaction` anidada para que la transacción de la prueba siga viva.
- **`UltronStudioRulesTest`:** las del código devuelven 409; S-001 nace como `draft` con `none`; referencias desconocidas → 422; `knowledgeKeys` que no existen → 422; conflicto de revisión; archivar; las archivadas no entran en la copia de una versión.
- **`ImportLegacyBaselineCommandTest`:** la primera ejecución crea 12; la segunda no cambia nada; tras alterar una fila a mano, la siguiente la sincroniza y lo audita; `--dry-run` no escribe; nunca toca las reglas del Studio.
- **`UltronStudioAuditTest`**, **`UltronStudioStatusCatalogTest`** (formas; la respuesta no contiene teléfonos, `Bearer`, `sk-`, `wamid` ni `HMAC`) y **`ExportStudioFixturesCommandTest`**.
- **`UltronEngineIsolationTest`:** con tokens, como `PaymentMintAuthorityTest`, comprueba que `UltronStudio`, `ultron_studio_` y `config('ultron_studio` solo aparecen en los ficheros permitidos del Studio, y que ninguna ruta del Studio cuelga de `internal/*`.
- Se mantienen verdes `AuthorizationCoverageTest` y `RolePolicyTest`, este último sin cambios.

**CRM (vitest):**
- `studio-api.service.spec.ts`: URLs, métodos y cuerpos en camelCase.
- `studio-parse.spec.ts`.
- `studio-session.spec.ts`: carga, prohibido, catálogo ilegible → error sin catálogo de reserva, guardar, 409, descartar, crear y los botones según permisos y cuatro ojos.
- `studio-graph.parity.spec.ts`.
- `studio-catalog.spec.ts`, redirigido al fixture.
- `studio-catalog.transcription.spec.ts`, temporal.
- `studio-store.spec.ts` actualizado.
- `ultron-studio-page.spec.ts` con `HttpTestingController`.
- Specs de los tres paneles.
- `permission-contract.spec.ts`, más una prueba del espejo del Administrador para `ultron.*` contra `BACKEND_ROLE_DEFAULTS`.

## Enmiendas tras las revisiones (18-10-2026)

Cambios compatibles con el contrato, aplicados y probados (detalle en `ULTRON_STUDIO_SECURITY_REPORT.md`):

| Origen | Enmienda |
|---|---|
| Seguridad H1 | Toda llave `ultron.*` presupone `ultron.view`; `requirePerson()` lo exige antes de buscar ningún recurso (sin oráculo de existencia: siempre 403 `forbidden` con `required_permission: ultron.view`) |
| Seguridad H2 | Cupos: además de 120/30 por credencial, 600/120 por red (`ClientNetwork::of`, IPv6 por /64) |
| Seguridad H3 + tester H-1 | Intentos denegados (`version.four_eyes_denied`, `version.publish_refused`, `version.rollback_refused`): una fila por persona, sujeto, evento y resumen cada 10 minutos, con cerrojo consultivo en PostgreSQL |
| Seguridad H4 | `POST rules` → 409 **`rule_limit_reached`** (código nuevo) con 100 reglas del Studio sin archivar |
| Seguridad H6 | `conditionalWrite()` rechaza por código que una regla del Studio cambie de identidad o se active; la prueba de CHECK/TRUNCATE se omite visiblemente en SQLite |
| Datos S2 | `ip_address` y `user_agent` de la auditoría quedan siempre NULL |
| Datos P5 | Disparadores `usr_forbid_delete` y `usr_forbid_truncate` en `ultron_studio_rules` (11 disparadores en PG, 7 en SQLite) |
| Tester (carrera S-NNN) | `POST rules` toma `pg_advisory_xact_lock` antes de calcular el código |
| Revisión final M7 | El replay de `POST versions` compara también `note` (otra nota → 409 `client_request_reused`) |
| Revisión final I1 | El CRM enseña, al abrir una versión, las reglas copiadas en ella (`content.rules`), y en la línea base solo las del código |
| Revisión final M5/M6 | Errores anunciados con `role="alert"`; los puntos rojos de la biblioteca siguen al servidor cuando su validación discrepa |
| Revisión final M8 | El inspector tiene hoja de estilos propia (misma apariencia); la de la página baja de 31 kB |
