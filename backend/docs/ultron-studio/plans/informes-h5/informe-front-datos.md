# Hito 5 · paso 1d · informe del frontend: la capa de datos de publicación del CRM

**Fecha:** 24-10-2026 noche (Bogotá), 25-10 UTC.
**Árbol:** `_worktrees/thermal-receipts/Iron-Body_Front`, rama `feat/ultron-studio-h5`, desde `5c1dbd7` (limpio). Commit local `(SIN COMMIT: la suite del módulo no salió verde bajo carga, ver §6)`, sin push.
**Contrato:** `ULTRON_STUDIO_H5_CONTRACT.md` §A2, §A5, §A6, §A7, §A10.3 y §A11. Alineado además con las decisiones del tester en `tests/fixtures/ultron-studio/publication-cases.README.md` (paso 1b) donde tocan los DTO del CRM (§4.3).
**Producción:** nada tocado. Sin despliegue. Sin tocar `ultron-studio.scss`, la página ni los paneles (son del paso 4).

## 1. API pública (firmas)

Todo en `src/app/modules/ultron-studio/`. Nada de esto pinta: la integración visual (A11) es del paso 4.

### 1.1 Tipos (`studio-api.types.ts`, aditivos)

| Tipo / constante | Qué |
|---|---|
| `PUBLISH_ACK_MAX = 64`, `ARCHIVED_RULES_PER_PAGE_MAX = 50` | Topes de A5.1 y A10.2 |
| `EngineStudioScope = 'off' \| 'canary' \| 'all'` | La llave `MARKETING_ULTRON_STUDIO_CONFIG` |
| `PublicationAction = 'publish' \| 'promote' \| 'rollback' \| 'legacy'` | A3.1 |
| `MustMatchParam` | Los 4 `env_flag` de A1 |
| `PublicationStateDto {envScope, head: PublicationHeadDto \| null, canary/rest: PublicationPartDto \| null, drift: PublicationDriftDto[], state: PublicationSealState}` | `status.engine.publication` |
| `PublicationEntryDto {uuid, seq, action, version: {uuid,label} \| null, effectiveHash, shortHash, labGate: {mode} \| null, actor, reason, at}` | Entradas de la cadena |
| `PublicationPreviewDto {versionUuid, contentHash, previewHash, publishable, params: PublicationParamDto[], noEffect, requirements: PublicationRequirementsDto, blocking}` | Vista previa |
| `CanaryEvidenceDto {versionUuid \| null, since, thresholds{minHours,minTurns}, hours, turns, stamped, byState, brakes{count,engaged}, acta{exitCode,total,counts}, recoveries, drift, signatureOk, evidenceHash, passes, failing}` | Evidencia (A6) |
| `StudioPublicationReading {env, head \| null, parts{canary,rest}, canaryConfigured}` | `extras['governance.studioPublication']` (A4.9) |
| `PublishVersionRequest`, `PromoteVersionRequest`, `PublicationReasonRequest`, `PublicationsQuery`, `ArchivedRulesQuery`, `EngineMismatchDto` | Cuerpos y consultas |
| `StudioStatusDto.engine.publication?` / `publicationUnreadable?` | OPCIONALES (§2) |
| `VersionActionsDto.promote?`, `VersionDetailDto.publications?: StudioList<PublicationEntryDto>`, `CompareSideDto.integrityOk?: boolean \| null` | OPCIONALES (§2) |
| `StudioErrorCode` + 17 códigos 409 de A7 | `revalidation_changed`, `lab_verdict_required`, `engine_value_mismatch`, `engine_reading_unavailable`, `no_effect_not_acknowledged`, `preview_stale`, `engine_has_no_canary`, `engine_braked`, `canary_in_progress`, `outside_publication_window`, `scope_not_allowed`, `canary_evidence_insufficient`, `evidence_changed`, `not_active_publication`, `restore_integrity_mismatch`, `already_legacy`, `publication_moved` (`four_eyes_required` y `engine_not_reading_versions` ya estaban) |
| `StudioClientErrorCode` + `validation_stale`, `invalid_input`, `no_preview`, `not_publishable`, `no_evidence`, `not_promotable`, `rate_limited` | Rechazos locales (la sesión no llega a pedir) |
| `StudioApiError.local(code, message, {fields?, retryAfter?})` | Errores por campo (`fieldErrors`, como un 422) y espera (`retryAfter`, como un 429) también en los locales |

### 1.2 Lectores (`studio-parse.ts`)

```ts
readStatus(raw): StudioStatusDto                       // engine.publication opcional y tolerante
readPublicationState(raw, ruta?): PublicationStateDto   // estricta
readPublicationEntry(raw, ruta?): PublicationEntryDto   // estricta
readPublicationPage(body): StudioPage<PublicationEntryDto>
readPublicationPreview(raw, ruta?): PublicationPreviewDto  // estricta
readCanaryEvidence(raw, ruta?): CanaryEvidenceDto          // estricta
readArchivedRulePage(body): StudioPage<RuleDto>         // paginador (final) o lista (antes de H5)
readVersionDetail(raw)                                  // + actions.promote?, publications?
readCompare(raw)                                        // + integrityOk? por lado
readEngineReadings(raw)                                 // + governance.studioPublication (falla suave)
errorPublicationPreview(err): PublicationPreviewDto | null      // 409 preview_stale → `preview`
errorCanaryEvidence(err): CanaryEvidenceDto | null              // 409 evidence_changed / canary_evidence_insufficient → `evidence`
errorNoEffect(err): string[]                                    // 409 no_effect_not_acknowledged → `noEffect`
errorEngineMismatches(err): EngineMismatchDto[]                 // 409 engine_value_mismatch → `mismatches`
errorEngineReadingUnavailable(err): string[]                    // 409 engine_reading_unavailable → `engineReadingUnavailable`
errorLabVerdictLatest(err): {latest: 'rejected'|'needs_changes'|'stale_prompts'|null} | null  // 409 lab_verdict_required → `latest`
```

### 1.3 Servicio (`UltronStudioApi`, `studio-api.service.ts`)

```ts
publishVersion(uuid, {clientRequestId, previewHash, acknowledgedNoEffect, note}): Observable<StudioCreated<PublicationEntryDto>>   // 13
rollbackVersion(uuid, {clientRequestId, reason}): Observable<StudioCreated<PublicationEntryDto>>                                   // 14
publicationPreview(uuid): Observable<PublicationPreviewDto>                                                                       // 34
canaryEvidence(uuid): Observable<CanaryEvidenceDto>                                                                               // 35
promoteVersion(uuid, {clientRequestId, evidenceHash, note}): Observable<StudioCreated<PublicationEntryDto>>                        // 36
publications({page?, perPage?}): Observable<StudioPage<PublicationEntryDto>>                                                      // 37
legacyPublication({clientRequestId, reason}): Observable<StudioCreated<PublicationEntryDto>>                                       // 38
archivedRules({page?, perPage?}): Observable<StudioPage<RuleDto>>                                                                 // 15 con status=archived
```

- Cuerpos campo a campo (nada de más aunque el objeto traiga más), en el orden del contrato.
- `publishVersion`/`rollbackVersion` cambian de firma: antes iban sin cuerpo. Nadie las llamaba fuera de su spec (comprobado con `grep`).
- Una respuesta de escritura que no es la entrada de ESA acción sobre ESA versión (o, en Legacy, sin versión) es `unreadable`. Una vista previa de otra versión, también; una evidencia que nombra otra versión, también.

### 1.4 Sesión (`StudioSession`, `studio-session.ts`)

```ts
// señales (solo lectura)
preview: Signal<PublicationPreviewState | null>        // {uuid, value, changed}
previewLoading: Signal<boolean>
evidence: Signal<CanaryEvidenceState | null>           // {uuid, value, changed}
evidenceLoading: Signal<boolean>
evidenceRetryAt: Signal<number | null>                 // Date.now() a partir del cual se puede volver a pedir tras un 429
publicationHistory: Signal<StudioPage<PublicationEntryDto> | null>
acting: Signal<boolean>                                // también durante publicar, promover, revertir y Legacy

// acciones (nunca se rechazan: StudioResult)
refreshStatus(): Promise<StudioResult<StudioStatusDto>>
publicationPreview(uuid): Promise<StudioResult<PublicationPreviewDto>>
clearPreview(): void
publish(uuid, {previewHash, acknowledgedNoEffect, note?}): Promise<StudioResult<StudioCreated<PublicationEntryDto>>>
canaryEvidence(uuid): Promise<StudioResult<CanaryEvidenceDto>>
clearEvidence(): void
promote(uuid, {evidenceHash, note?}): Promise<StudioResult<StudioCreated<PublicationEntryDto>>>
rollback(uuid, reason): Promise<StudioResult<StudioCreated<PublicationEntryDto>>>
legacy(reason): Promise<StudioResult<StudioCreated<PublicationEntryDto>>>
publications(page = 1): Promise<StudioResult<StudioPage<PublicationEntryDto>>>
validateDraftOnServer()                                // + busy y validation_stale (§3)
export const TEXTO_VALIDACION_VIEJA = 'Se guardó mientras validabas: vuelve a validar.'
```

Comportamiento:
- **Rechazos locales, sin pedir nada:** `read_only` sin `can.publish` (publicar/promover) o sin `can.rollback` (revertir/Legacy); `busy` con otra operación en curso; `no_preview` si `previewHash` no es el de la vista previa abierta de esa versión; `not_publishable` si la vista previa dice que no; `invalid_input` por campo si `acknowledgedNoEffect` no es EXACTAMENTE su `noEffect` (como conjunto) o pasa de 64, si `note` pasa de 500 o si el motivo no está en 5–500 (caracteres = puntos de código, como el servidor). En promover, `no_evidence` y `not_promotable`.
- **`clientRequestId`** con `newStudioRequestId()` (`crypto.randomUUID()`, con el respaldo de `getRandomValues` fuera de contexto seguro, la misma receta de F). Uno por acción, ligado a la firma del cuerpo: el mismo en un reintento con el mismo cuerpo (el servidor devuelve la entrada de la primera vez, `replayed`), otro si cambia algo; tras `client_request_reused`, se descarta y el siguiente intento lleva otro.
- **Cuerpo canónico:** `acknowledgedNoEffect` sin repetidos y ordenado; nota y motivo recortados (nota vacía = `null`).
- **Tras responder:** con éxito, se cierran vista previa y evidencia (la cadena se movió) y se releen versiones y `status`, la versión abierta, la página pedida del historial y el motor si ya se había leído. Lo mismo con un 409, un 403 (no de token), un 404 o un 2xx ilegible (la escritura pudo hacerse). Sin red, un 5xx o un 429 no cambian nada: el reintento es idempotente.
- **Al publicar, un 409/403/404** reabre la vista previa con `changed: true`: la de `preview_stale` sale del propio error si es legible y de esa versión; si no, se pide (y mientras llega no hay vista previa con la que publicar).
- **Al promover,** `evidence_changed`, `canary_evidence_insufficient` y `engine_braked` renuevan la evidencia con `changed: true` (la del error si viene; si no, se pide respetando el limitador).
- **Evidencia (limitador propio):** nunca dos a la vez (`busy`); un 429 fija `evidenceRetryAt` con su `retry_after` (60 s si no lo dice) y, hasta entonces, `canaryEvidence()` responde `rate_limited` con los segundos que faltan en `retryAfter`, sin pedir. Si falla, se conserva la evidencia que hubiera de ESA versión.
## 2. Compatibilidad: el backend de hoy y uno ampliado

Lo que no existe en el backend de hoy es **opcional** en los tipos y **no se inventa** al leer: sin la clave, no está (ni `false` ni `null`, que se podrían pintar como un dato).

| Pieza | Backend de hoy (H4) | Con H5 | Si llega mal |
|---|---|---|---|
| `status.engine.publication` | No está; `readStatus(estadoDto())` sale **idéntico** a antes | `PublicationStateDto` | **Tolerante:** se omite y `publicationUnreadable` dice por qué; el Studio arranca igual |
| `actions.promote` | No está | `VersionActionDto` | Estricto, como las otras acciones |
| `version.publications` | No está | `StudioList<PublicationEntryDto>` | Estricto el contenedor; cada entrada ilegible se descarta y se cuenta |
| `compare.from/to.integrityOk` | No está | `boolean` (versión) / `null` (línea base, borrador) | Estricto |
| `extras['governance.studioPublication']` | No está | `StudioPublicationReading` | Falla suave (se cuenta en `discarded`) |
| Estados `CANARY`, `PUBLISHED`, `RETIRED`, `ROLLED_BACK` | Ya se leían (el enum de F tenía los 10), inalcanzables | Alcanzables, con la etiqueta del servidor (`RETIRED` = «Sustituida») | — |
| Llaves que este CRM no conoce, en cualquier nivel | — | Se ignoran: no se cuelan en lo leído | — |

`studio-publication-compat.spec.ts` lo demuestra en dos planos:
- el lector, con los DTO de hoy (`estadoDto()`, `versionDetalle()`, `comparacionDto()`, `lecturasDto()`) y con los de H5 con dos llaves inventadas en cada nivel;
- la sesión entera arrancando (`init` → `ready`) con el `status` de hoy, con el ampliado y con uno cuyo `engine.publication` llega roto.

Con el backend de hoy, pedir la vista previa da su 404 sin tumbar nada, y publicar no se pide (`can.publish` es `false`).

## 3. La carrera de «Validar en el servidor» (A10.3)

**Antes** (`studio-session.ts:583-596` en `5c1dbd7`): `validateDraftOnServer()` no miraba `_saving()`, y si la respuesta traía otra revisión y no había cambios locales, `cargarBorrador()` cargaba esa respuesta. Con el orden GET enviado → guardado confirmado (revisión 8) → GET respondido (revisión 7), cargaba la 7 encima de lo recién guardado.

**Ahora:**
- (a) con un guardado en curso, `busy` sin pedir nada (el mismo texto que `importLegacyDraft`);
- (b) una respuesta con `revision < store.revision()` no se aplica: `validation_stale`, «Se guardó mientras validabas: vuelve a validar.»;
- (b′) **añadido, mismo arreglo:** tampoco se aplica la que llega con un guardado EN VUELO. Lo decide ese guardado: su confirmación o su 409. Sin esto, si el servidor atendía el PUT antes que el GET, el GET traía la revisión 8 con el borrador aún «sucio» aquí, y se abría un conflicto falso con el propio guardado (se cerraba solo al confirmar).
- Descartar también sube la revisión, así que (b) lo cubre.

**Spec** (`studio-session.spec.ts`, cuatro casos «A10.3 · …»): las respuestas se sueltan a mano con `HttpTestingController` en el orden de la carrera.

**Demostración:** se restauró SOLO el cuerpo de `validateDraftOnServer()` de `5c1dbd7` (el resto del fichero, igual), se corrió el spec y se devolvió el fichero (`cmp` idéntico). Salida real:

```
@@MUTANTE_CARRERA@@
```

Con el arreglo: `@@SESION_VERDE@@`.
## 4. Interpretaciones del contrato que el backend del paso 3 tiene que producir igual

El CRM lee esto **en estricto** (lo que no cuadra es `unreadable` y no se usa) salvo donde se dice «tolerante». Si el paso 3 elige otra cosa, hay que cambiarlo en los dos lados y en una enmienda; el paso 4 lo verá en la paridad con los fixtures exportados.

### 4.1 Respuestas de las cuatro escrituras

| Ruta | Éxito | Reintento idéntico |
|---|---|---|
| `POST versions/{uuid}/publish` | `201 {ok:true, data: PublicationEntryDto}` (la entrada creada, `action:'publish'`, `version.uuid` = la de la ruta) | `200 {ok:true, replayed:true, data: <la entrada de la primera vez>}` |
| `POST versions/{uuid}/promote` | `201`, `action:'promote'` | ídem |
| `POST versions/{uuid}/rollback` | `201`, `action:'rollback'`, `version.uuid` = la de la ruta | ídem |
| `POST publications/legacy` | `201`, `action:'legacy'`, `version:null` | ídem |

El contrato solo dice «200 `replayed` con la entrada de la primera vez» (A5.1, paso 3); que el éxito devuelva también la entrada (y no el `VersionDetailDto`, como las transiciones de F) es interpretación. El CRM no depende del cuerpo para lo que enseña: relee versiones y estado. El código HTTP (200/201) no se mira; `replayed` sí.

### 4.2 Lo que viaja en los errores (claves de la carga, al nivel de `code` y `message`, como hoy)

| Código | Carga que lee el CRM | Si no viene o no se puede leer |
|---|---|---|
| `preview_stale` | `preview: PublicationPreviewDto` (la nueva, de esa versión) | Pide `GET publication-preview` |
| `evidence_changed`, `canary_evidence_insufficient` | `evidence: CanaryEvidenceDto` (la de ahora) | Pide `GET canary-evidence` (respetando el 429) |
| `no_effect_not_acknowledged` | `noEffect: string[]` (el conjunto bueno, orden C-3) | Lista vacía + mensaje del servidor |
| `engine_value_mismatch` | `mismatches: [{param, versionValue, engineValue}]` (P-3 del tester) | Se omite lo ilegible |
| `engine_reading_unavailable` | `engineReadingUnavailable: string[]` (`capa.clave`, P-3 del tester) | Lista vacía |
| `lab_verdict_required` | `latest: null \| 'rejected' \| 'needs_changes' \| 'stale_prompts'` | `null` (no se inventa el motivo) |
| `invalid_transition` | `currentStatus`, `allowedFrom` (como en F) | — |

`preview` y `evidence` son nombres elegidos aquí por analogía con `estimate_changed {estimate}` de H4; el tester no los fija (P-3: «no se afirman»).

### 4.3 Formas de los DTO (A7) con lo que el contrato no nombra

**`PublicationStateDto`** (`status.engine.publication`): la forma de A7 tal cual. `head`, `canary` y `rest` admiten `null` (cadena vacía, Legacy en esa parte). `drift[].engine`/`declared` admiten `null` (una lectura caída: Q-5 del tester). En `GET status` es **tolerante** (§2).

**`PublicationEntryDto`**, además de la forma de A7:
- Legacy y solo Legacy lleva `version: null`.
- `rollback` y `legacy` llevan `reason` no vacío (el CHECK `uspub_reason_chk`); `publish`/`promote` pueden llevarlo (la nota) o `null`.
- `publish` lleva `labGate: {mode}`; las demás, `{mode}` o `null`.
- `effectiveHash`: 64 hexadecimales en minúsculas. `shortHash`: un prefijo de `effectiveHash` (como `shortHash` de las versiones, 8).
- En `GET versions/{uuid}.publications` solo las entradas cuyo `version.uuid` es esa versión («las que la nombran»). Una que nombra otra se descarta y se cuenta.

**`PublicationPreviewDto`**, además de la forma de A7:
- `params` trae una fila por cada parámetro CON clase (`must_match`, `matches`, `no_effect`); lo que no tiene clase (igual que la línea base, sin nada que reconocer) no viene. Los 4 `must_match` vienen siempre y ningún otro parámetro es `must_match` (C-4).
- Orden de `params`: el del catálogo (C-2). El CRM no lo exige.
- `noEffect` = `capa.clave` de los `no_effect` de `params` (exactamente esos) + `rule:S-NNN` de cada regla del Studio copiada (C-3, C-9). Sin repetidos.
- `publishable` ⇔ `blocking` vacío. Nunca `publishable:true` con un requisito `ok:false`, un `must_match` `ok:false`, `keys.studio:false` o `keys.engine:'off'`.
- `requirements`: las diez claves de A7, todas obligatorias; cada una `{ok, message?}` más sus extras (`status.current`, `labGate.mode/latest`, `window.window` —texto o `null` sin ventana—, `keys.studio/engine` sin `ok`). Las que el CRM no conoce se ignoran.
- `blocking`: códigos de A7; uno desconocido se conserva (bloquea igual).
- `previewHash`, `contentHash`: sha256 en minúsculas. Qué entra en `previewHash` es la Q-4 del tester; el CRM solo lo reenvía.

**`CanaryEvidenceDto`** (A6; el contrato solo dice «la tabla de A6 + `{evidenceHash, passes, failing}`»). Alineado con V-1…V-10 del tester:

```jsonc
{ "versionUuid": "<uuid>",                       // opcional; si viene, la pedida
  "since": "2026-11-03T15:04:05Z",              // V-9
  "thresholds": {"minHours": 24, "minTurns": 3},
  "hours": 26,                                   // entero: floor(segundos/3600) (V-1)
  "turns": 4, "stamped": 4,                      // stamped ≤ turns; stamped ≤ byState.applied (V-5)
  "byState": {"applied":4, "fallback/contract_unknown":0, …, "legacy":0, "out_of_scope":0, "unstamped":0},  // las 10 claves, suman turns (V-5)
  "brakes": {"count": 0, "engaged": false},      // V-3
  "acta": {"exitCode": 0, "total": 0, "counts": {<los 7 contadores>}},  // sin JSON: {exitCode:1,total:null,counts:null} (V-4)
  "recoveries": 0,
  "drift": [],                                   // V-6
  "signatureOk": true,
  "evidenceHash": "<sha256>",                    // V-8 (sin now, hours, passes ni failing)
  "passes": true, "failing": [] }                // V-7: orden hours, turns, stamped, brakes, acta, drift, signatureOk
```

El CRM exige `passes ⇔ failing vacío` y nunca `passes:true` con una puerta de A6 que sus propias cifras no cumplen (incluido `acta.exitCode ≠ 0` o `acta.total` nulo). Un criterio de `failing` que no conoce se conserva.

**`governance.studioPublication`** (A4.9, la `describe()` de B-M tal como está hoy en el árbol): `head.entry`/`head.seq` pueden ser `null` (fila degenerada) y `hashOk`/`signatureOk`/`parseOk` se aceptan `null` («no se comprobó»). Falla suave: ilegible se descarta y se cuenta en `discarded`.

### 4.4 Reglas archivadas (A10.2)

- Petición: `GET rules?status=archived&page=N&per_page=M`, `M` ≤ 50 (el CRM recorta a 50; 20 por defecto).
- **Forma final:** el paginador de Laravel dentro de `{ok:true, data}`, como `GET versions`.
- Durante la transición el CRM acepta también la de antes de H5 (`{ok:true, data:[…]}`) y la lee como una página única.
- Una regla no archivada en esta lista se descarta y se cuenta.
- Las vivas siguen en `GET rules` como lista; el CRM no pide `status=archived` en ningún sitio hoy (el panel no las enseña), así que este cambio de forma no rompe nada desplegado.

### 4.5 `compare` (A10.1)

`from.integrityOk`/`to.integrityOk`: booleano en `kind:'version'` (nunca `null`), `null` en `baseline` y `draft` (nunca booleano). Sin la clave: el CRM no la inventa.

### 4.6 Códigos y textos

- El CRM decide por `code`; el `message` del servidor se enseña tal cual (en español, concreto). Un código que no conoce queda en `payload.code` y no decide nada.
- 422 por campo con las claves de los cuerpos: `previewHash`, `acknowledgedNoEffect`, `note`, `evidenceHash`, `reason`, `clientRequestId`. Las locales del CRM usan las mismas.
- El 429 de `canary-evidence` lleva `retry_after` en segundos (como el resto de limitadores).
## 5. Lo que tiene que hacer el paso 4 (A11) con esta capa

1. **Regenerar los fixtures desde el backend** (`php artisan ultron-studio:export-fixtures`) en cuanto el paso 3 exporte los suyos, y comprobar la paridad con `estadoH5Dto`, `publicacionDto`, `entradaDto`, `vistaPreviaDto`, `evidenciaDto` y `lecturaDePublicacionDto` de `studio-api-fixtures.testing.ts`. Esos seis los escribí a mano desde el contrato y el README del tester: si el backend difiere, **o se corrige el backend o se enmienda el contrato**, pero el lector no se afloja a ciegas.
2. **Panel de versiones:**
   - botones según `actions.publish/promote/rollback` del servidor; `promote` es OPCIONAL: no hay botón si falta (`d.actions.promote?.allowed === true`);
   - «Publicar en canario»: `session.publicationPreview(uuid)` al abrir el diálogo; tabla por clase con `preview().value.params`; una casilla por elemento de `noEffect`; requisitos con `requirements.*.ok/message` y `blocking`; texto obligatorio de A11; envío con `session.publish(uuid, {previewHash: preview().value.previewHash, acknowledgedNoEffect, note})`. Con `preview().changed`, avisar de que cambió y pedir que se revise y se vuelva a reconocer;
   - «Promover a todas»: `session.canaryEvidence(uuid)`; enseñar la evidencia (umbrales, recuentos, `failing`); deshabilitar «Actualizar» mientras `evidenceRetryAt()` esté en el futuro; envío con `session.promote(uuid, {evidenceHash: evidence().value.evidenceHash, note})`;
   - «Revertir» y «Volver a Legacy» con `ib-reason-dialog` → `session.rollback(uuid, motivo)` / `session.legacy(motivo)`.
3. **Errores:** decidir por `error.code`. Locales nuevos: `no_preview`, `not_publishable`, `no_evidence`, `not_promotable`, `invalid_input` (por campo: `error.fieldErrors.acknowledgedNoEffect | note | reason`), `rate_limited` (`error.retryAfter`). Del servidor, los 17 de A7 (§1.1) con su carga (§1.2). El `message` del servidor se enseña tal cual.
4. **«Validar en el servidor»** (`ultron-studio-page.ts`, `releerServidor()`): hoy enseña `No se pudo preguntar al servidor: ${message}` para cualquier fallo. Con `validation_stale` conviene un aviso informativo (no de error) con `TEXTO_VALIDACION_VIEJA`, y con `busy` deshabilitar el botón mientras `session.saving()`.
5. **Barra de estado:** `status().engine.publication` (OPCIONAL) → publicación vigente (`canary`/`rest`/Legacy), llave del motor (`envScope`), sello (`state`) y deriva (`drift`). Si falta, no enseñar nada de publicación (backend anterior); si `publicationUnreadable` está, decir que no se pudo leer (sin el motivo técnico en la cara de la persona).
6. **Lecturas del motor:** `studio-engine-text.ts` (`textoDeExtra`) cae en su rama `default` (`value ? 'sí' : 'no'`) con la lectura nueva `governance.studioPublication`, que es un objeto: diría «sí». Hay que darle su texto (env, cabeza, firma, partes) **en cuanto el catálogo del backend traiga su descriptor** en `catalog.readings`. Hoy el catálogo exportado no lo trae y nada lo enseña.
7. **Comparar:** insignia «Huella alterada» por lado con `from/to.integrityOk === false` (el diff no cambia); con `undefined` (backend anterior), nada.
8. **Historial de publicaciones:** `session.publications(page)` y `publicationHistory()` (se relee solo tras cada escritura, en la misma página).
9. **Reglas archivadas** (si el paso 4 las enseña): `api.archivedRules({page, perPage ≤ 50})`.
10. Restricciones que siguen: nunca `ultron-studio.scss`; hojas nuevas < 8 kB; nunca animar `transform` en el host de `fNode`; foco atrapado en los diálogos, `role=alert`, teclado y 390 px.

## 6. Verificación (salida real)

- `tsc -p tsconfig.app.json` y `tsconfig.spec.json`: sin errores (tras el parche final).
- `check:literals`: 315 ficheros, literales correctos.
- Specs sueltos, antes del parche de alineación con el README del tester: servicio 48/48, sesión 47/47, parseo 64/64, compatibilidad 8/8, publicación 18/18.
- Carrera A10.3 contra el cuerpo de `5c1dbd7`: 4 fallan (`expected 7 to be 8`, GET enviado durante el guardado, conflicto falso, `expected 7 to be 8` tras descartar); con el arreglo, 47/47. Salida en el scratchpad `mutacion/salida-mutante-2.txt`.
- Mutaciones de la sesión de publicación: M1 (id siempre nuevo) 1 cae, M2 (sin relectura) 6 caen, M3 (sin limitador del 429) 1 cae. M4 y M5 NO se ejecutaron (la máquina, con carga 20–27 por PHPUnit de otros agentes, tardaba más de 25 min por compilación).
- **Suite completa del módulo tras el parche: NO verde.** Fallos casi todos `Test timed out in 5000ms` en specs que no toqué (laboratorio, panel de reglas, texto hostil, inspector, página) bajo esa carga; uno no es timeout (`studio-rules-panel` «editar: solo viajan las condiciones…»: no encontró la petición). Sin repetir con la máquina libre no se puede afirmar que sean solo carga. **Pendiente: repetir la suite con la máquina libre antes del commit.**

## 7. Observaciones (fuera de mi paquete; no las toqué)

- **Q-1 sigue abierta y ya hay divergencia en el árbol.** La subclave que implementa B-M en `UltronPublishedConfig.php` (sin commit, `:455`) usa la etiqueta como clave y la `APP_KEY` como mensaje (su informe §3.1). El fixture del tester (S-2) fija lo contrario. Los dos lo elevaron al lead. Al CRM no le afecta (nunca ve firmas), pero el paso 3 no debería empezar a firmar sin la decisión.
- **`describe()` propaga los fallos** (informe del motor §3.5): `StudioEngineReadings` tiene que envolverla en `reading()` para que salga `{available:false}`. El CRM ya lee `{available:false}` como lectura válida, sin contarla como descartada.
- **El árbol del backend tiene trabajo sin commit de otros paquetes** (B-M, el tester). Por eso este informe queda **sin commit** en el backend: un `git commit` allí arrastraría lo que otro hubiera preparado. Lo decide el lead.
- **`e2e/` sin seguimiento en el CRM** es del tester (arnés A10.5). No entra en mi commit.
