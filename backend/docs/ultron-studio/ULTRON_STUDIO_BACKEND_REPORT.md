# ULTRON STUDIO: backend (Hito F, persistencia)

**Fecha:** 18-10-2026. **Estado: IMPLEMENTADO EN RAMA, NO DESPLEGADO.** Rama `feat/ultron-studio` del backend (commit `b4e07af` y siguientes). Las migraciones **no se han ejecutado en producción** y nada de esto está publicado. El contrato que se implementó está en `ULTRON_STUDIO_F_CONTRACT.md`; este informe describe lo que hay.

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
