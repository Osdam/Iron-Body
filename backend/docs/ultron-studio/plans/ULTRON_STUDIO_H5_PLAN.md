# ULTRON STUDIO · Hito 5 (gobierno y versionado): plan del architect

**Fecha:** 24-10-2026. **Autor:** `architect` (no implementa). **Base verificada:** backend `feat/ultron-studio-h4` = `origin/main` (`eeee85c`, código `18dae99`, árbol limpio); CRM `feat/ultron-studio-h4` = `origin/main` (`5c1dbd7`). Ramas propuestas: `feat/ultron-studio-h5` desde `origin/main` en los dos repos.

**Rutas.** `‹B›` = `Iron-Body/backend`; `‹U›` = `‹B›/app/Services/Marketing/Ultron`; `‹S›` = `‹B›/app/Services/UltronStudio`; `‹F›` = `Iron-Body_Front/src/app/modules/ultron-studio`. Todo lo marcado «verificado» se leyó en el código con su línea; lo marcado **[suposición]** no se pudo comprobar desde el repo.

> Este fichero vive en el repo a propósito (el scratchpad de `/tmp` se purga a los tres días). El lead lo convierte en `ULTRON_STUDIO_H5_CONTRACT.md` tras decidir las preguntas del final.

---

## 0. Resumen para el lead (una pantalla)

1. **«Publicar» con el motor real casi no tiene nada que aplicar sin romper reglas ya decididas.** De los 21 parámetros del catálogo, solo 4 tienen mando real y los 4 son banderas de entorno del propietario (`StudioCatalog.php:671-727`): encender ULTRON, el canario, los enlaces de pago y el driver del cerebro legado. La auditoría ya fijó que una interfaz **nunca** enciende ULTRON, **nunca** mueve el canario y **nunca** toca el dinero (`ULTRON_STUDIO_ARCHITECTURE_AUDIT.md` §12). Aplicados «solo en el sentido de restringir», el canario y el cobro no hacen nada hoy (según la auditoría y la certificación vigente, el canario está puesto y el cobro apagado; **[suposición sobre el estado de hoy: lo confirma `GET engine/readings` en el Studio]**) y «apagar» duplica la pausa de Mercadeo y el freno. Los prompts viven en n8n (fuera por orden del usuario) y los planes y la base de conocimiento son datos de otros módulos.
2. **Recomendación (opción A): publicación como DECLARACIÓN VERIFICADA + SELLO POR TURNO.** La versión aprobada pasa a ser la descripción oficial y firmada de cómo está configurado ULTRON. El motor la lee con **una sola clase de solo lectura** (`UltronPublishedConfig`, detrás de su propia llave de entorno `off|canary|all`) y **sella cada turno** con la publicación vigente en `marketing_ai_actions.metadata.studio`, en el único punto donde el commit crea filas (`UltronCommitService.php:2412-2463`, que ya sella `knowledge_version` en `:2436`). **Ningún parámetro cambia lo que ULTRON dice o hace**; el Studio detecta la **deriva** entre lo publicado y lo que el motor tiene de verdad.
3. **Por qué no aplicar ni siquiera «apagar» (opción B):** un 403 nuevo en el commit para un turno en vuelo no deja fila ni saliente, el vigía lo cuenta como huérfano porque usa `UltronDecideService::ineligibleReason` (`UltronTurnWatchdog.php:350-363`), que no mira interruptor, freno ni canario (`UltronDecideService.php:96-148`), y en la conversación canaria **acciona el freno** (`UltronTurnWatchdog.php:181-196`). Se puede evitar aplicándolo solo en el emisor y el job, pero sigue duplicando la pausa. Queda como opción si el propietario quiere un «apagado a cuatro ojos».
4. **Ciclo completo:** `APPROVED → CANARY → PUBLISHED`, con `ROLLED_BACK` y `RETIRED` (se reutiliza `RETIRED` como «sustituida»: ya existe en el enum, el tipo TS y la tubería del CRM). Cadena de publicaciones de **solo inserción** con `seq` único (una sola publicación vigente; la carrera la gana una), firma HMAC del servidor sobre el JSON exacto que lee el motor, cuatro ojos (quien publica ≠ autor), idempotencia por `clientRequestId`, ventana de publicación y **revertir instantáneo** (una fila nueva; el lector no tiene caché).
5. **Canario:** reutiliza el canario del motor (`config/marketing.php:83-86`): primero solo la conversación canaria, después todas. Criterios de salida medibles calculados en el servidor (turnos sellados al 100 %, cero frenos desde la publicación, acta `ultron:canary-report --since` a cero, sin deriva, ventana mínima). El freno automático sigue siendo el del vigía; el Studio **no** frena por deriva porque en A la deriva no daña a nadie. **Con el canario del motor puesto (lo que certifica la auditoría; confirmarlo en `engine/readings`), `scope: canary` y `scope: all` alcanzan exactamente la misma conversación.**
6. **El 409 de publicar se mantiene en la fase 1** (código desplegado, llave del motor `off`, `ULTRON_STUDIO_PUBLISHING_ENABLED=false`). Solo se levanta en la fase 2, con autorización expresa del propietario, cuando el motor ya sella turnos y eso se ha comprobado.
7. **Dependencias externas que deciden si el hito se cierra:** (a) autorización del propietario para las fases 2 y 3; (b) tráfico real en la conversación canaria para acumular turnos sellados **[suposición: no se puede saber desde el repo si ULTRON está encendido hoy en el canario; lo dice `GET engine/readings` en el Studio]**; (c) si se exige veredicto del laboratorio para publicar, la fase 3 del Hito 4 (clave de OpenAI del propietario), porque una ejecución `fake` no admite veredicto (`StudioLabRuns.php:72-76`, `:606-614`).
8. **Heredados absorbidos:** `integrityOk` por lado en `compare` (I-2 de G), paginación de **reglas** archivadas (I-5 de G; corrección al encargo: no son escenarios, que ya tienen tope de 200 y `meta.truncated` desde la Enmienda 4.3 del H4), carrera de «Validar en el servidor» (verificada en `studio-session.ts:583-596`), umbral de desatención por carril (m-3 de H4, admisible con un cambio aditivo) y el arnés E2E como activo del repo en `Iron-Body_Front/e2e/ultron-studio/`, con simulador de n8n nuevo para probar el sello con la pila real.

**Decisiones que necesito del lead:** ver §11 (diez preguntas, cada una con mi recomendación). Las que cambian el plan: A frente a B, la puerta del laboratorio y el criterio de cierre del hito.

---

## 1. Delta honesto

| Lo que sugiere el encargo | Lo que hay de verdad | Consecuencia para el Hito 5 |
|---|---|---|
| «Publicar» cambia al agente | El Studio describe; los mandos reales son entorno (propietario), n8n (prompts), datos (planes, conocimiento) y código (guardas) | Publicar en H5 **declara y sella**; no cambia el comportamiento. Hay que decirlo en la interfaz y en la ayuda del permiso |
| Laravel puede aplicar los 4 mandos de G | Encender, mover el canario y el cobro están vetados a una UI (auditoría §12). Restringir: canario y cobro no hacen nada hoy; «apagar» duplica pausa y freno | Opción A: los 4 se **declaran** y deben coincidir con el motor al publicar; después se vigila la deriva |
| Canario → todas | El motor atiende hoy una sola conversación (el canario del entorno) | `canary` y `all` alcanzan lo mismo hasta que el propietario abra el tráfico (fuera de alcance) |
| Freno automático si algo falla | El freno ya lo acciona el vigía ante un turno mudo del canario | Se mantiene; el lector del motor está diseñado para no poder enmudecer un turno (nunca lanza) |
| Exigir veredicto del laboratorio | En producción el laboratorio corre en `fake` y un `fake` no admite veredicto | Exigirlo acopla H5 a la fase 3 del H4; se propone llave con valor estricto por defecto y dispensa explícita del propietario |
| Cuatro ojos | Existe para aprobar (F) y para el veredicto (H4) | Se extiende a publicar y promover (≠ autor). Revertir no exige cuatro ojos: es el sentido seguro |
| Reglas S-NNN publicables | Son propuestas: la base impide que una regla del Studio esté activa (`usr_studio_never_active_chk`) | Siguen sin efecto; la publicación las lista como «sin efecto» y quien publica lo reconoce |
| Rollback instantáneo | Ninguna caché en el lector | Revertir = insertar una entrada; el siguiente commit ya sella el estado nuevo |

**Valor real del hito:** la cadena de gobierno completa y auditada; la primera lectura verificable del motor sobre el Studio (con prueba de que no puede romper un turno); el sello por turno (cierra en parte el hueco «no se registra por turno qué versión regía», auditoría §4 y §9); la deriva entre lo aprobado y lo que hay en el entorno; y el contrato preparado para crecer (cada mando aplicado futuro = commit del motor primero + versión nueva del contrato + su canario).

**Lo que H5 no puede dar, y conviene que el usuario lo sepa:** que el Studio cambie lo que ULTRON dice. Eso exige versionar los prompts de n8n y que el commit registre `workflow_version_id` y `execution_id` por turno (pregunta abierta 1 de la auditoría §14), que el usuario dejó fuera.

---

## 2. Lo que el motor tiene de verdad (verificado)

### 2.1 Los mandos y dónde se leen

| Mando | Definición | Quién lo lee (puertas) | Naturaleza |
|---|---|---|---|
| Interruptor `enabled` | `config/marketing.php:35` | Emisor `UltronEventEmitter.php:47-49` (`ultron_disabled`); job `SendUltronEventToN8n.php:61-72` (evento `skipped`); commit `UltronCommitService.php:237-247` (403, lanza, sin fila); arranque `AppServiceProvider.php:262-272` | Entorno, propietario. Una UI nunca lo enciende (auditoría §12) |
| Canario | `config/marketing.php:83-86` (`null` = sin restricción) | Emisor `:84-87` (`not_canary_conversation`); commit `:290-303` (403, lanza, sin fila); vigía `UltronTurnWatchdog.php:181-196` (solo frena en el canario). **No** en decide (auditoría §13.2) | Entorno. Una UI nunca lo mueve |
| Enlaces de pago | `config/marketing.php:36` | Embudo único `SalesPaymentReadinessService.php:59-63` y `:77-87` (con `PaymentCanaryAuthority`, pura); contexto de decide `UltronDecideService.php:578` | Entorno, absoluto, «exige la frase del propietario». Fuera de toda UI |
| Canario del cobro | `config/marketing.php:49` (0 = no hay) | `SalesPaymentReadinessService.php:77-87` | Entorno |
| Driver del cerebro legado | `SalesAiConfig.php:17-24` (`hermes → openai → fake`) | Binding del contenedor y `marketing:ai-doctor` | Entorno |
| Pausa | `MarketingAgentSwitch` (fila 1, `marketing_agent_settings`) | Emisor `:69-71`, job `:80-92`, decide `UltronDecideService.php:137` (la última), commit vía decide `UltronCommitService.php:305-307`, despachador y outbox | Base de datos, **sin caché** (`MarketingAgentSwitch.php:38-40`); falla cerrado salvo tabla inexistente (`:72-85`); solo apaga |
| Freno | `UltronAbortLatch` (`ultron_aborts`) | Emisor `:59-61`, commit `:261-271` (403) | Base de datos; solo apaga; soltarlo exige firma; `current()` se traga cualquier `QueryException` y falla abierto (`UltronAbortLatch.php:29-49`) |

### 2.2 El catálogo del Studio frente a esos mandos

`StudioCatalog::parameterBindings()` (`StudioCatalog.php:671-727`) clasifica los 21 parámetros: **4 `env_flag` en vivo** (`intent.driver`, `rules.paymentLinksEnabled`, `governance.enabled`, `governance.canaryOnly`), **1 `engine_data`** (`strategy.offerPriority`: lo decide el módulo de Planes), **11 `fixed`** (8 con `declaredValue`: `strictFacts`, `windowTurns`=10, `useLeadProfile`, `discountsAllowed`=false, `handoffOnlyCorroborated`, `channel`, `appointments`, `autoBrake`; 3 sin valor: `sources`, `critic`, `retry`) y **5 `proposal`** sin mando (`tone`, `emojis`, `maxChars`, `fallback`, `goal`). La línea base de configuración (`StudioCatalog.php:553-566`) declara `governance.enabled: true`, `canaryOnly: true`, `paymentLinksEnabled: false`, `driver: 'entorno'`. `StudioEngineReadings` (`StudioEngineReadings.php:59-63`, `:100-133`) ya lee en vivo los 4 `env_flag`, la prioridad de planes, pausa, freno, canario del cobro, base de conocimiento y planes.

### 2.3 El commit: dónde se puede sellar sin cambiar nada

- `persist()` (`UltronCommitService.php:2412-2463`) es el **único** punto que crea filas de `marketing_ai_actions` (`MarketingAiAction::create`, `:2440`); `blocked()` (`:3805-3846`) y las rendiciones pasan por él. Ya escribe `knowledge_version` en `metadata` (`:2436`) y filtra nulos (`:2450`).
- Ni `UltronCommitService` ni `Api/Internal/UltronController.php` envuelven el commit en una transacción (verificado: sin `DB::transaction`); solo abren las suyas, propias y posteriores, herramientas como `CourtesyRequestService.php:89`, `:210` y `LeadProfileService.php:371`. Una consulta fallida antes de `persist()` no deja abortada la conexión de PostgreSQL. Aun así, el lector se invoca antes de cualquiera de ellas y nunca lanza (A4).
- La respuesta del commit a n8n no cambia y la entrada tampoco: `unexpectedKeys()` (`UltronController.php:404`) sigue igual, así que **no hay acoplamiento nuevo con n8n** (ver memoria del contrato n8n↔backend).

### 2.4 El vigía, el freno y por qué un «apagado publicado» en el commit es peligroso

- El vigía descarta como «ya no era atendible» solo lo que dice `UltronDecideService::ineligibleReason` (`UltronTurnWatchdog.php:350-363`), que no comprueba interruptor, freno ni canario (`UltronDecideService.php:96-148`).
- Un evento `sent` sin acción ni saliente en la conversación canaria es frenable (`UltronTurnWatchdog.php:181-196`). Un 403 nuevo del commit (que lanza y no deja fila, como `ultron_disabled`) para un turno en vuelo produce exactamente eso.
- Los eventos `skipped` no cuentan (`UltronTurnWatchdog.php:237`, filtro de huérfanos), y lo que corta el emisor no crea evento. **Conclusión:** cualquier mando aplicado en el futuro se aplica en el emisor y el job, nunca como un 403 nuevo del commit.

### 2.5 Firmas y huellas existentes

- `decide_token` se firma con HMAC-SHA256 y la `APP_KEY` (`UltronDecideToken.php:143-145`), 120 s.
- El Studio tiene JSON canónico propio (`StudioContentHasher::canonicalJson`, `StudioContentHasher.php:154-156`), que el motor **no** puede importar sin romper la frontera. Por eso el motor verificará la firma sobre los **bytes exactos** guardados (A4), sin recanonicalizar.

### 2.6 Lo que el Studio tiene hoy para publicar

- `StudioPublishing::ENGINE_READS_VERSIONS = false` (`StudioPublishing.php:23`); `available()` siempre `false` (`:37-40`); `engine()` ya reserva `activeVersion: null` (`:79-86`) y el CRM lo tipa como `string | null` (`‹F›/studio-api.types.ts:73-74`).
- `refuse()` audita y devuelve 409 (`StudioVersions.php:348-361`); `actions()` pone publicar y revertir siempre en «no» (`:482-512`); el CRM pinta «Publicar» siempre deshabilitado (`‹F›/studio-versions-panel.ts:117-120`, `:447-449`).
- Estados: el enum tiene los 10 (`StudioVersionStatus.php:21-30`) pero solo 5 alcanzables (`:35-41`); en PostgreSQL el CHECK `usv_status_chk` admite solo esos 5 (`2026_10_18_100002_add_ultron_studio_guards.php:116`). Las columnas mutables de una versión son `status`, `last_validation`, `submitted_at`, `status_changed_at`, `updated_at` (`:75`). El libro solo admite sujetos `draft|version|rule|system` (`:129`).
- Una reseña por versión (`version_id` único, `2026_10_18_100001_create_ultron_studio_tables.php:102-121`).
- Permisos: `ultron.publish` y `ultron.rollback` existen (`PermissionCatalog.php:142-143`) y no llegan al Administrador (`CrmPermission.php:236-240`); rutas `POST versions/{uuid}/publish|rollback` (`AuthorizationMap.php:677-678`, `routes/ultron-studio.php:85-86`).
- Laboratorio: la ejecución guarda `target_content_hash`, `mode` y `prompt_version` (migración `2026_10_19_100002…:108-160`); el veredicto guarda `version_content_hash` y `decision approved|rejected|needs_changes` (`:207-226`); una ejecución `fake` no admite veredicto (`StudioLabRuns.php:72-76`, `:606-614`); versión de prompts vigente `StudioLabPrompts::PROMPT_VERSION = '2026-10-19.1'` (`StudioLabPrompts.php:41`).
- Frontera: `UltronEngineIsolationTest` prohíbe por tokens que fuera de la lista blanca se nombre `UltronStudio`, `ultron_studio_` o `config('ultron_studio…')` (`UltronEngineIsolationTest.php:28-44`, `:125-145`), exige 33 rutas (`:105`) y que el motor no lea versiones (`:108-113`).

### 2.7 El acta del canario

`ultron:canary-report {conversación} --since --json` (`UltronCanaryReport.php:39`) sale con 1 si hay hallazgos mecánicos (`:91-99`); son 7 contadores (`:421-428`). Su JSON incluye el texto de los turnos: el Studio solo puede extraer **recuentos** y nunca guardarlo ni devolverlo.

---

## 3. Impacto (con la opción A recomendada)

### 3.1 Archivos a tocar (cada uno con UN dueño; ver §7)

**Motor (paquete B-M, `backend`; lo más delicado del hito):**
- NUEVO `‹U›/UltronPublishedConfig.php`: el lector. Única clase del motor que nombra una tabla del Studio (`ultron_studio_publications`), solo `SELECT`, sin importar nada de `App\Services\UltronStudio` ni `App\Models\UltronStudio`. También expone la función pura de firma que usa el Studio (una sola implementación del algoritmo).
- `‹U›/UltronCommitService.php`: leer una vez al principio de `execute()`, después de las puertas de interruptor, freno y canario (`:237-303`), y pasar el sello a `persist()` como clave `studio` del `metadata` (junto a `knowledge_version`, `:2436`). Nada más: ni puertas nuevas, ni 403 nuevos, ni cambios en la respuesta.
- `config/marketing.php`: `ultron.studio_config` ← `MARKETING_ULTRON_STUDIO_CONFIG` (`off` por defecto; cualquier valor que no sea `canary` o `all` = `off`).
- `.env.example`: la variable, documentada y apagada.
- Pruebas de frontera: la lista blanca de `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` cambia en el MISMO commit que el lector, como pide su docblock; la constante `ENGINE_READS_VERSIONS` se gira después, en el commit que implementa publicar (paso 3 de §6), porque girarla antes solo cambiaría `engineReads` sin que nadie pudiera publicar (el docblock se actualiza para decirlo). También `UltronStudioEngineReadOnlyTest.php`. NUEVAS: `tests/Unit/Marketing/UltronPublishedConfigTest.php`, `tests/Feature/Marketing/UltronCommitStudioStampTest.php`.

**Esquema (paquete DB, `database-specialist`):**
- NUEVA `‹B›/database/migrations/2026_10_2X_100001_create_ultron_studio_publications_table.php` (la cadena).
- NUEVA `‹B›/database/migrations/2026_10_2X_100002_add_ultron_studio_publication_guards.php` (disparadores de solo inserción en SQLite y PG; TRUNCATE en PG; CHECK de dominio; **ampliación** de `usv_status_chk` a 9 estados; índices únicos parciales «un solo CANARY» y «un solo PUBLISHED» en `ultron_studio_versions`).

**Studio (paquete B-S, `backend`, segunda instancia o en serie; sin ficheros del motor):**
- NUEVOS en `‹S›/`: `StudioPublications.php` (publicar, promover, revertir, Legacy, cabeza, historial), `StudioPublicationDeclaration.php` (núcleo puro: clasifica los 21 parámetros y las reglas, construye el JSON exacto que leerá el motor, `previewHash`), `StudioCanaryEvidence.php` (recuentos del canario, solo lectura).
- Cambian en `‹S›/`: `StudioPublishing.php` (puertas y códigos), `StudioVersionStatus.php` (alcanzables), `StudioVersions.php` (`actions` con `promote`, `summary.engineReads`, `compare` con `integrityOk` por lado), `StudioEngineReadings.php` (lectura extra `governance.studioPublication`), `StudioRules.php` (archivadas paginadas).
- `app/Models/UltronStudio/StudioPublication.php` (NUEVO) y `StudioVersion.php` (admite los estados de H5).
- Controladores: `UltronStudioVersionController.php` (publish, promote, rollback, preview, evidence), `UltronStudioController.php` (bloque `engine` de `status`), NUEVO `UltronStudioPublicationController.php` (historial y Legacy).
- `routes/ultron-studio.php` (+5 rutas → 38), `app/Support/Access/AuthorizationMap.php` (+5 entradas), `app/Support/Access/PermissionCatalog.php` (ayuda de `publish`/`rollback`, sin llaves nuevas), `config/ultron_studio.php` (llaves nuevas, A13), `app/Exceptions/UltronStudioException.php` (códigos nuevos), `app/Console/Commands/UltronStudio/ExportStudioFixturesCommand.php` (fixtures nuevos), NUEVO `app/Console/Commands/UltronStudio/PublicationCheckCommand.php`.
- Observabilidad (m-3, commit aparte del mismo paquete): `app/Services/Observability/QueueHealthService.php` y `config/queue.php` (umbral opcional por carril; el resto de carriles no cambia).
- Pruebas: `tests/Unit/UltronStudio/StudioPublicationDeclarationTest.php`, `tests/Feature/UltronStudio/UltronStudioPublication{Lifecycle,Access,Canary,Rollback}Test.php`, más las de los heredados.

**Fixtures del contrato (paquete T, `tester`):** NUEVO `‹B›/tests/Fixtures/UltronStudio/publication-cases.json` (clasificación de parámetros y reglas, resolución de alcance por conversación, firmas válidas e inválidas), escrito a mano desde el contrato, como `coherence-cases.json` en G.

**CRM (paquete FE, `frontend`), todo en `‹F›/`:** `studio-api.types.ts`, `studio-api.service.ts`, `studio-session.ts` (acciones nuevas y la carrera de «Validar en el servidor»), `studio-versions-panel.{ts,scss,spec.ts}`, NUEVOS `studio-publication-dialog.*`, `studio-canary-evidence.*`, `studio-publication-history.*`, `studio-compare-view.ts` (insignia de integridad por lado), `studio-engine-text.ts` (texto de la lectura nueva), `ultron-studio-page.ts` (barra de estado), fixtures regenerados (`studio-api-fixtures.testing.ts` y el catálogo de permisos exportado). **Nunca** `ultron-studio.scss`.

**Arnés E2E (paquete T, `tester`):** NUEVO `Iron-Body_Front/e2e/ultron-studio/**` (A10.5).

**Operación y documentos:** `devops` → NUEVOS `‹B›/docs/ultron-studio/ops/deploy-h5-backend-fase1.sh`, `h5-engine-scope.sh`, `smoke-h5.py`, `smoke-run-h5.sh` y `ops/README.md`; `documentation-specialist` → contrato H5 y los informes (`DEPLOYMENT_PLAN`, `ROLLBACK`, `MASTER_ROADMAP`, `FINAL`, `BACKEND`, `SECURITY`, `VISUAL`).

### 3.2 Contratos que cambian

| Contrato | Cambio | Compatibilidad |
|---|---|---|
| API del Studio | +5 rutas (38); `GET status.engine` gana `publication` (objeto) y conserva `activeVersion` como `string\|null`; `VersionSummary.engineReads` pasa a `true`; `actions.promote`; estados nuevos alcanzables; `CompareSideDto.integrityOk`; `GET rules?status=archived` paginado; `engine/readings` gana `governance.studioPublication` | El CRM actual pinta «Publicar» deshabilitado siempre y no lee llaves que no conoce (en H4 se comprobó con llaves de permisos desconocidas; para los DTO nuevos lo confirma el spec de compatibilidad del paso 1d): el backend puede ir antes. El único cambio de forma es `rules?status=archived`, que el CRM no pide (SECURITY_REPORT, I-5); el tester lo confirma con un grep |
| Motor ↔ Studio | El motor lee `ultron_studio_publications` (contrato `ultron-engine-config/1`) | Con `MARKETING_ULTRON_STUDIO_CONFIG=off`, cero consultas y `metadata` idéntico al de hoy |
| `marketing_ai_actions.metadata` | Clave nueva `studio` (A4.5) | Aditiva. Consumidores conocidos: vigía (`recovery`, `outbound.message_id`), acta (crítico y textos), analítica. El tester enumera todos los lectores de `metadata` antes del paso 3 |
| n8n | Ninguno | Entrada y respuesta del commit sin cambios; `unexpected_fields` igual |
| Esquema | Tabla nueva; CHECK ampliado; 2 índices únicos parciales | Aditivo. `down()` se niega si hay filas en estados de H5 o publicaciones (A3.4) |
| Permisos | Mismas llaves; cambia la ayuda | Catálogo de permisos del CRM regenerado (sigue con 96) |

### 3.3 Consumidores afectados

- **Dentro del repo:** el commit de ULTRON (solo el sello), `StudioEngineReadings`, el CRM del Studio.
- **Fuera del repo:** ninguno. n8n no ve nada nuevo; la app móvil no lee estas tablas; IRON GUARD no recibe incidentes nuevos en H5; el panel de Mercadeo (`MarketingAgentSwitch::present`) no cambia.

### 3.4 Migración de datos

**No hay transformación de datos.** Tabla nueva vacía; el CHECK ampliado valida las filas actuales (todas en los 5 estados de hoy); los índices parciales nacen vacíos. `ALTER TABLE … ADD CONSTRAINT` toma un bloqueo exclusivo breve sobre una tabla pequeña; `ironbody-migrate` ya fija `lock_timeout` de 5 s.

---

## 4. Opciones y recomendación

| | A · Declaración verificada + sello (recomendada) | B · A + «apagado» aplicado | C · Control real de parámetros |
|---|---|---|---|
| Qué aplica el motor | Nada del comportamiento. Lee la entrada vigente, verifica firma y contrato, y sella el turno | Además `governance.enabled=false` → el agente deja de atender turnos **nuevos**: emisor (sin evento) y job (`skipped`), nunca en el commit | Driver forzado a reglas, restricción del cobro, reglas S-NNN como filtros del commit, prompts |
| Ficheros del motor | 1 nuevo + `UltronCommitService` (sello) + 1 clave de config | Los de A + `UltronEventEmitter` + `SendUltronEventToN8n` | Cadena sellada del commit, embudo del cobro, binding del cerebro, n8n |
| Riesgo de romper ULTRON | Mínimo: el lector nunca lanza y no hay puertas nuevas | Bajo-medio: duplica la pausa (dos «apagados» con autoridades distintas); hay que tratar el vigía con el mismo rigor que con la pausa | Alto: contradice la auditoría §6 y §12 y la orden de no tocar n8n ni dinero |
| Valor | Gobierno, trazabilidad por turno, deriva, revertir instantáneo, contrato listo para crecer | Un apagado a cuatro ojos y con auditoría del Studio | Que el Studio cambie al agente |
| Reversibilidad | Total: llave `off` = Legacy exacto | Total, pero un apagado publicado y olvidado confunde a quien opere (mira entorno, pausa y freno y no lo encuentra) | Baja |
| Prueba de comportamiento | El sello (y su ausencia con la llave `off`) | Además, un turno nuevo no nace con la publicación apagada | — |

**→ Recomendación: A.** Es la única que cumple a la vez «integración funcional, segura y comprobada», «no rompas ULTRON Legacy» y «no publiques reglas comerciales sin validaciones y aprobación», sin inventar mandos que la auditoría ya vetó. B solo si el propietario pide expresamente un apagado de cuatro ojos; en ese caso, aplicado únicamente en emisor y job (§2.4) y como contrato `ultron-engine-config/2`, con su propio canario. C no es de este hito.

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

## 6. Plan secuenciado por agentes

Etiquetas: **[‖]** puede ir en paralelo con los demás del mismo bloque; **[SEC]** pasa por `security`/`api-security-specialist`; **[TEST]** casos límite para `tester`.

**Paso 0 · [lead]** Decide §11, congela `ULTRON_STUDIO_H5_CONTRACT.md` a partir de §5 y crea `feat/ultron-studio-h5` desde `origin/main` en los dos repos. *Verificación:* contrato en commit; ramas creadas desde el `origin/main` del día (fusionar lo ajeno antes de empezar, como en H4).

**Bloque 1 (en paralelo):**
- **1a · [database-specialist] [‖]** Las dos migraciones de A3. *Verificación:* `migrate` y `migrate:rollback --step=2` limpios en SQLite y en el PG desechable; `down()` se niega con filas de H5; recuento de disparadores y CHECK en PG documentado (hoy 27/24; lo esperado se fija en el informe); los dos índices parciales conservan su `WHERE` en los dos motores; ningún `ALTER` sobre tablas del motor.
- **1b · [tester] [‖]** `publication-cases.json` escrito a mano desde el contrato (clasificación de parámetros y reglas, matriz de alcance, vectores de firma con una `APP_KEY` de prueba, casos `malformed`). *Verificación:* lo revisa el lead contra el contrato antes del paso 2 (Enmienda 1 si hay que precisar algo, como en G).
- **1c · [backend · paquete B-M] [‖] [SEC] [TEST]** Lector `UltronPublishedConfig` (A4), clave de configuración, sello en `persist()` y pruebas de frontera (A9 puntos 1 y 2). **No** gira la constante del Studio. *Verificación:* las suites de ULTRON verdes con la llave en `off` y en `all`; `git diff origin/main --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api/Internal routes config/marketing.php` enseña solo `UltronPublishedConfig.php` (nuevo), `UltronCommitService.php` y `config/marketing.php`; prueba de «nunca lanza» en PG (tabla inexistente, firma mala, JSON roto); respuesta del commit idéntica. *Casos límite para el tester:* commits seguidos en el mismo proceso; conversación sin canario configurado; llave con un valor basura (= `off`).
- **1d · [frontend] [‖]** Capa de datos del CRM (tipos, servicio, parseo) con fixtures escritos desde el contrato, un spec de compatibilidad (backend de hoy, sin `engine.publication`, y backend con llaves desconocidas) y el arreglo de la carrera de validar (A10.3) con su spec. *Verificación:* specs del módulo verdes; el spec de la carrera falla sin el arreglo.
- **1e · [tester] [‖]** Infraestructura del arnés E2E (A10.5: `run.sh`, simuladores de OpenAI y n8n, entrante firmado, cuentas, subconjunto de regresión de F/G/H4) sobre el código de `origin/main`. *Verificación:* el subconjunto de regresión pasa contra `origin/main` y el turno simulado llega al commit en `dry_run` sin salir a la red.

**Paso 2 · [backend · paquete B-S] [TEST]** (necesita 1b; en paralelo con 1a y 1c, sin ficheros compartidos) Núcleo puro: `StudioPublicationDeclaration` y la parte pura de `StudioCanaryEvidence`, contra el fixture. *Verificación:* Unit verde; huellas estables.

**Paso 3 · [backend · paquete B-S] [SEC] [TEST]** (necesita 1a, 1c y 2) Servicios, modelo, controladores, rutas (38), `AuthorizationMap`, ayuda de permisos, configuración, códigos de error, exportación de fixtures, `ultron-studio:publication-check`; **giro de `ENGINE_READS_VERSIONS` a `true` en este commit** (el lector ya existe desde 1c); `compare` con integridad, reglas archivadas paginadas; recibe de B-M la propiedad de `UltronEngineIsolationTest.php` (rutas 33 → 38 y aserción de la constante) y de `UltronStudioEngineReadOnlyTest.php` (A9 punto 3). Commit aparte para m-3 (A10.4) y, si se acepta la pregunta 9, para A10.6. *Verificación:* Studio + Access + Chaos en SQLite; grupo del Studio en PG con `ops/pgunit.sh` (**puerta obligatoria**, la verifica el lead); `auth:coverage` vacío; `route:list` = 38 en el Studio; con las llaves por defecto, publicar sigue en 409 `publishing_disabled`. *Casos límite:* ventana en el borde (reloj de prueba en UTC, día de Bogotá), `previewHash`/`evidenceHash` viejos, reintento con otro cuerpo, freno echado, segundo `CANARY`, restaurar una versión con la huella alterada, Legacy dos veces, revertir con `ULTRON_STUDIO_PUBLISHING_ENABLED=false`.

> **Primer punto verde: al cerrar el paso 3.** El backend completo pasa todas las suites con las llaves por defecto, el motor sigue en Legacy exacto y publicar responde 409 como hoy.

**Paso 4 · [frontend]** (necesita 3 y 1d) Integración (A11) con fixtures regenerados desde `ultron-studio:export-fixtures`. *Verificación:* módulo del CRM verde; suite completa con los 16 fallos de base; build con chunks y hojas dentro de presupuesto; `ultron-studio.scss` intacto; revisión en Chrome a 1440 y 390 px.

**Paso 5 · [tester] [TEST]** (necesita 3 y 4) Carreras PG, mutaciones, enumeración de lectores de `metadata` y E2E `h5-governance` con la pila real (A12). *Verificación:* informe con cifras y comandos; mutaciones cazadas; p95 del commit con la llave en `off` y en `all`.

**Paso 6 · [api-security-specialist] y [security] [‖]** (desde el paso 3; `security` repasa el CRM tras el 4). *Verificación:* hallazgos con ubicación; cada uno vuelve a su dueño; enmienda del contrato si cambian decisiones.

**Paso 7 · [devops] [‖]** (desde el paso 3) `deploy-h5-backend-fase1.sh` (aborta con cambios locales, variables antes de `ironbody-migrate`, conf desde git), `h5-engine-scope.sh off|canary|all` (escribe las dos llaves de entorno, `config:cache` con `chown www-data` y `chmod 600`, reinicio de los workers, comprobación de la configuración efectiva sin imprimir secretos), `smoke-h5.py` y `smoke-run-h5.sh` (cuenta temporal id 32, sin filas positivas en la fase 1). *Verificación:* los cuatro, ensayados contra la pila del E2E.

**Paso 8 · [reviewer]** (tras 5 y 6 cerrados) Revisión final independiente de los dos repos. **El hito no avanza al despliegue con un bloqueante abierto.**

**Paso 9 · [documentation-specialist] [‖]** (borrador desde el paso 3; cierre tras el 8) Enmiendas del contrato, `DEPLOYMENT_PLAN`, `ROLLBACK`, `MASTER_ROADMAP`, informes `FINAL`, `BACKEND`, `SECURITY` y `VISUAL`, `ops/README.md`.

**Paso 10 · [lead + devops]** Despliegue por fases (§8): la fase 1 con la orden expresa de push y despliegue del usuario en la conversación de ese momento; las fases 2 y 3, con autorización expresa del propietario para cada una.

---

## 7. Dueños de ficheros (ninguno con dos dueños a la vez)

| Paquete | Agente | Ficheros (exclusivos) |
|---|---|---|
| DB | `database-specialist` | `‹B›/database/migrations/2026_10_2X_10000{1,2}_*publication*.php` y su prueba de migración |
| B-M (motor) | `backend` | `‹U›/UltronPublishedConfig.php`, `‹U›/UltronCommitService.php`, `‹B›/config/marketing.php`, `‹B›/.env.example`, `tests/Unit/Marketing/UltronPublishedConfigTest.php`, `tests/Feature/Marketing/UltronCommitStudioStampTest.php`; `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` y `UltronStudioEngineReadOnlyTest.php` **hasta cerrar 1c** |
| B-S (Studio) | `backend` (otra instancia o en serie tras B-M) | `‹S›/**` (incluido `StudioPublishing.php`), `app/Providers/AppServiceProvider.php` (solo `limitadoresDelStudio()`), `app/Models/UltronStudio/**`, `app/Http/Controllers/Crm/UltronStudio/**`, `app/Console/Commands/UltronStudio/**`, `app/Exceptions/UltronStudioException.php`, `routes/ultron-studio.php`, `config/ultron_studio.php`, `app/Support/Access/{AuthorizationMap,PermissionCatalog}.php`, `app/Services/Observability/QueueHealthService.php`, `config/queue.php`, `tests/{Unit,Feature}/UltronStudio/**` salvo los fixtures; las dos pruebas de frontera **desde el paso 3** |
| T | `tester` | `tests/Fixtures/UltronStudio/publication-cases.json`, pruebas adversariales nuevas (`tests/Feature/UltronStudio/*Race*`, `*Mutation*`), `Iron-Body_Front/e2e/ultron-studio/**` |
| FE | `frontend` | `‹F›/**` (nunca `ultron-studio.scss`), fixtures del CRM regenerados |
| OPS | `devops` | `‹B›/docs/ultron-studio/ops/{deploy-h5-backend-fase1.sh,h5-engine-scope.sh,smoke-h5.py,smoke-run-h5.sh}` |
| DOC | `documentation-specialist` | `‹B›/docs/ultron-studio/*.md` (salvo este plan y el contrato, del lead) y `ops/README.md` |

**`AppServiceProvider.php`**: el limitador nuevo de la evidencia (`ultron-studio-evidence`, 10/min por persona) va junto a los del Studio en `limitadoresDelStudio()` (`AppServiceProvider.php:585-640`), como hizo H4 con `ultron-studio-lab-run`; lo toca **solo B-S**, solo en ese método, y sin «arreglar» el Pint que ya falla en HEAD. `routes/console.php` no se toca en H5 (sin tareas programadas nuevas).

---

## 8. Despliegue por fases

**Puerta antes de cualquier fase:** Studio + Access + Chaos en SQLite; grupo del Studio en PG con `ops/pgunit.sh`; suites de ULTRON con la llave en `off` y en `all`; `auth:coverage` vacío; 38 rutas; diff del motor = solo los 3 ficheros de B-M; Pint en lo tocado; módulo del CRM y build; E2E `h5-governance` y regresión en verde; `security`, `api-security-specialist`, `tester` y `reviewer` cerrados; respaldo preparado.

### Fase 1 · código desplegado, todo apagado (publicar sigue en 409)

Requiere la orden expresa del usuario de push y despliegue en la conversación de ese momento.

1. Respaldo en `/var/backups/ironbody/ultron-studio-h5-<fecha>/` (tablas `ultron_studio_*` y árbol desplegado, como en H4).
2. **Variables ANTES de `ironbody-migrate`** (lección I-5 de `security` en H4: el envoltorio regenera la caché): `MARKETING_ULTRON_STUDIO_CONFIG=off`, `ULTRON_STUDIO_PUBLISHING_ENABLED=false`, `ULTRON_STUDIO_PUBLISH_LAB_GATE=required`; el resto con su defecto.
3. Código (`deploy-h5-backend-fase1.sh`); `sudo /usr/local/bin/ironbody-migrate` (2 migraciones; recuento de disparadores y CHECK contra el informe de `database-specialist`).
4. `route:cache` como `www-data`, `chown www-data` + `chmod 600` de las cachés (memoria «config:cache y los workers»: sin el `chown`, los doce workers acaban en BACKOFF en el siguiente reinicio); reinicio de workers y `ironbody-lab`: todos RUNNING.
5. CRM con `ops/deploy-crm.sh` (rsync **sin** `--delete`: hay pestañas abiertas en mitad de una venta).
6. `smoke-run-h5.sh` (fase 1, sin filas positivas): `status.engine.readsVersions = true` y `publication.envScope = off`; publicar → 409 `publishing_disabled`; revertir un uuid inexistente → 404 `version_not_found` (y, si hay alguna versión aprobada, 409 `not_active_publication`); Legacy → 409 `already_legacy`; vista previa de una versión existente → 200 con `publishable:false` y su motivo (la vista previa responde en cualquier estado; sin versiones, 404 con un uuid inexistente); historial vacío; `governance.studioPublication.env = off`; `compare` con `integrityOk`; reglas archivadas paginadas.
7. Sonda del contrato n8n↔backend sin efectos (memoria del contrato): `POST ai/commit` con `source_type:"x"` → 422 de validación **sin** `unexpected_fields`: prueba que la entrada del commit no cambió. El bearer interno se lee del entorno del servidor y nunca se imprime.
8. Lectura de control (solo lectura): ninguna fila nueva de `marketing_ai_actions` lleva la clave `studio`.

Estado tras la fase 1, en la hoja de ruta: «implementado y desplegado en fase 1; publicar sigue en 409». Ningún sello final todavía.

### Fase 2 · el motor lee en el canario (autorización expresa del propietario)

1. Decisión del propietario sobre la puerta del laboratorio: `required` (exige la fase 3 del Hito 4) o `waived` (queda escrito en cada publicación).
2. `h5-engine-scope.sh canary`: `MARKETING_ULTRON_STUDIO_CONFIG=canary` y `ULTRON_STUDIO_PUBLISHING_ENABLED=true`, caché de configuración con su `chown`, reinicios; `engine/readings` → `env = canary`.
3. Primera publicación: una versión que **refleje el motor tal como está** (A1), creada por una persona, aprobada por otra y publicada por alguien distinto de la autora. Son filas reales: es el objetivo de la fase.
4. Comprobación: `status.engine.publication.state = applied` para la parte canaria y firma correcta; si ULTRON atiende el canario, el siguiente turno sale con `metadata.studio.state = applied` (lo enseña la evidencia, sin ids); acta `--since` a cero.
5. Simulacro de reversión (recomendado, con la misma autorización): revertir y comprobar que el turno siguiente sale `legacy`; volver a publicar.

### Fase 3 · todas las conversaciones (autorización expresa del propietario)

1. `h5-engine-scope.sh all`.
2. Evidencia suficiente (A6) y promoción por alguien distinto de la autora.
3. `status` con la parte `rest` = la versión; los sellos siguen saliendo.

**`HITO_5_COMPLETED`** solo con las fases 1–3 hechas y la evidencia en los informes. Si la fase 2 o la 3 se bloquean por una dependencia externa (autorización, tráfico del canario, puerta del laboratorio): **`HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**, como el Hito 4.

### Reversión (para `ULTRON_STUDIO_ROLLBACK.md`)

| Nivel | Cómo | Tiempo | Efecto |
|---|---|---|---|
| 1 · Studio | «Revertir» o «Volver a Legacy» (`ultron.rollback`) | El siguiente commit | Declaración anterior o ninguna. No necesita la llave del Studio |
| 2 · Motor | `h5-engine-scope.sh off` | Tras la caché de configuración | El motor deja de consultar la tabla: Legacy exacto, sin sello |
| 3 · Código | Volver al backend anterior y al CRM `5c1dbd7` | Un despliegue | Seguro con filas de H5 (A3.5) |
| 4 · Esquema | `down()` | — | Solo si no hay filas de H5; con filas, **prohibido** (no se destruye la cadena ni el libro) |

---

## 9. Riesgos y puntos de no retorno

| # | Riesgo | Probabilidad / impacto | Mitigación |
|---|---|---|---|
| R1 | El lector enmudece un turno (excepción o transacción abortada en PG) y el vigía frena el canario | Baja / alto | Nunca lanza (A4.3.7); se invoca fuera de transacciones; prueba en PG con la tabla inexistente, firma mala y JSON roto; E2E con turno real. Si alguien envuelve algún día el commit en una transacción, la prueba de PG lo detecta |
| R2 | Se entiende que «publicar» cambia al agente | Media / medio | Texto obligatorio en el diálogo y en la ayuda del permiso; vista previa con la clase de cada parámetro; reconocimiento exacto de lo «sin efecto» |
| R3 | Sin turnos reales en el canario no hay evidencia | Media / bloquea el cierre | Es lo correcto; queda como dependencia externa (§8) |
| R4 | La puerta del laboratorio acopla H5 a la clave de OpenAI del propietario | Alta si `required` / bloquea fase 2 | Llave con defecto estricto y dispensa explícita registrada (pregunta 2) |
| R5 | Deriva «legítima»: el propietario cambia una bandera del entorno | Media / bajo | Es información, no fallo: se enseña, bloquea la promoción y se resuelve publicando una versión que coincida o devolviendo el entorno |
| R6 | Rotación de la `APP_KEY`: todas las firmas dejan de valer | Baja / bajo en A | El motor sella `fallback/signature_invalid` y no cambia nada más; procedimiento: volver a publicar (entrada nueva firmada con la clave nueva). Al runbook |
| R7 | La llave del motor va con la caché de configuración | Media / bajo | `h5-engine-scope.sh` hace `config:cache`, `chown` y reinicios, y comprueba la configuración efectiva; la publicación en sí no tiene caché |
| R8 | El acta carga textos de la conversación en el proceso web | Baja / medio (datos personales) | Solo recuentos; nada se guarda, se registra ni se devuelve; revisión de `security`; limitador propio |
| R9 | SQLite pierde el `WHERE` de los índices parciales al reconstruir una tabla | Baja / medio | Ninguna migración reconstruye `ultron_studio_versions`; prueba que lo comprueba en los dos motores |
| R10 | Lectores de `marketing_ai_actions.metadata` sorprendidos por la clave nueva | Baja / bajo | Aditiva; el tester enumera los lectores antes del paso 3 |
| R11 | Dos instancias de `backend` | Media / medio | Tabla de dueños (§7) con traspasos en serie; el reviewer comprueba el diff del motor |
| R12 | Que B o C se cuelen «de paso» | Baja / alto | El contrato lo prohíbe y la prueba de frontera solo admite una tabla, una clase y una `select` |
| R13 | Horas en el borde de la ventana (Bogotá frente a UTC) | Media / bajo | Relojes de prueba en UTC (memoria) y casos en los dos bordes |

**Puntos de no retorno:**
- **La primera publicación real** deja filas de solo inserción para siempre (es el diseño). Llevan nombre y rol del personal (como el libro de F): **el procedimiento de redacción con el rol dueño pendiente del Hito 7 tiene que cubrir también `ultron_studio_publications`** (se añade a la lista del propietario).
- **Con filas de H5, `down()` deja de ser una opción.** Revertir el código sigue siendo seguro.
- **En el motor no hay ninguno:** la llave en `off` devuelve Legacy exacto.

---

## 10. Fuera de alcance

- **Prompts de n8n**, su edición desde el Studio y el registro por turno de `workflow_version_id`/`execution_id` (pregunta 1 de la auditoría §14). Es el camino para que el Studio cambie lo que ULTRON dice; necesita decisión del propietario y un hito propio, con el acoplamiento de contrato n8n↔backend (`unexpected_fields`) como primer riesgo.
- **Mandos aplicados** (opciones B y C), reglas S-NNN con efecto, cualquier cambio de interruptor, canario, cobro o canario del cobro, **abrir el tráfico** (quitar el canario del motor), planes y base de conocimiento desde el Studio.
- **ElevenLabs, Meta, llamadas, Twilio** (Hito 6).
- **Incidente de IRON GUARD por deriva y tarea programada** de `ultron-studio:publication-check` (Hito 7, monitoreo).
- **Reconstruir una a una las 280 comprobaciones E2E perdidas** de F, G y H4 (32 + 126 + 122): H5 reconstruye el arnés, la especificación de H5 y un subconjunto de regresión por flujos.
- **Paginación real de escenarios archivados** (ya tienen tope de 200 y `meta.truncated`).
- **Parada por tiempo total en `rate_limited`** del laboratorio (nota de la Enmienda 4 de H4): no se absorbe.
- **Pendientes del propietario del Hito 7** (`no-store`, la clase de M-2 fuera del Studio, `TrimStrings`, caracteres invisibles, procedimiento de redacción): no se absorben, salvo lo que decida la pregunta 9.
- **Fase 3 del Hito 4** (clave de OpenAI): dependencia, no trabajo de H5.

---

## 11. Preguntas abiertas (cada una con mi recomendación)

1. **¿A (declaración + sello) o B (además, «apagado» aplicado en emisor y job)?** → **A.** B duplica la pausa con otra autoridad y añade ruido operativo; si el propietario quiere un apagado de cuatro ojos, que sea `ultron-engine-config/2` con su propio canario.
2. **Puerta del laboratorio para publicar.** → **Defecto `required` en el código** (veredicto `approved` sobre la misma huella y la versión de prompts vigente). Recomiendo cerrar la fase 3 del Hito 4 antes de la fase 2 de H5; si el propietario no quiere esperar, `waived` explícito en el entorno y escrito en cada publicación.
3. **Canario obligatorio y umbrales (24 h y 3 turnos sellados).** → **Sí**, configurables con mínimos duros (1 h, 1 turno).
4. **Criterio de cierre.** → `HITO_5_COMPLETED` solo con las fases 1–3 y evidencia; si no, `HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`.
5. **¿Reutilizar `RETIRED` («Sustituida») en lugar de añadir `SUPERSEDED`?** → **Sí**: ya existe en el enum, el tipo TS y la tubería; añadir un valor obliga a tocar CHECK, tipo, fixtures y textos sin ganar nada.
6. **¿Quien publica tiene que ser distinto también de quien aprobó (tres personas)?** → **No**: basta con ≠ autor (dos personas). El gimnasio tiene pocas cuentas con estas llaves.
7. **Ventana de publicación 07:00–19:00 (Bogotá).** → **Sí** para publicar y promover; revertir y Legacy, siempre.
8. **¿Revertir sin `ULTRON_STUDIO_PUBLISHING_ENABLED`?** → **Sí**: deshacer no puede depender de la llave que permite cambiar.
9. **¿Absorber el `READ COMMITTED` de `StudioAudit::recordDenied` (pendiente F, Hito 7)?** → **Sí**: H5 añade intentos denegados nuevos por esa misma función; es una línea con su prueba en PG, igual que la Enmienda 4.6 del laboratorio.
10. **Por verificar, no por decidir: ¿ULTRON está encendido hoy en el canario?** → El lead lo mira en `GET engine/readings` desde el Studio (solo lectura). Si está apagado, la fase 2 no acumulará turnos y el cierre quedará en «dependencia externa»; y la primera versión publicable tendrá que declarar `governance.enabled: false`.

---

## 12. Decisiones que necesito del lead

Las que cambian el plan: **1** (A o B), **2** (puerta del laboratorio y camino hacia la fase 2) y **4** (criterio de cierre). El resto tiene recomendación por defecto y se puede aceptar en bloque (3, 5, 6, 7, 8, 9). La 10 es una comprobación de solo lectura que conviene hacer antes de congelar el contrato.

Recordatorios que no son decisiones: este plan no autoriza ningún push ni despliegue; la fase 1 necesita la orden expresa del usuario y las fases 2 y 3, además, la autorización del propietario para cada una. Nada de esto activa llamadas, Meta, ElevenLabs ni gasto.
