# Revisión de seguridad de API · ola del 11-11 (Plantillas de Meta desde el CRM · Enmienda M-1)

Autor: `api-security-specialist`. Fecha: 2026-11-11.
Árbol revisado: `_worktrees/ultron-h8/Iron-Body`, rama `feat/ultron-studio-h9`, HEAD `120a8b23`, cambios **sin commit**.

Ha sido una revisión de **solo lectura**:
- no he editado código ni pruebas;
- no he usado red real ni producción;
- he ejecutado 4 ficheros de prueba (uno a la vez) y una prueba de concepto sin red con datos sintéticos (§6).

---

## 0. Resumen para el lead

### Veredicto por paquete

| Paquete | ¿Se despliega con todo apagado? | Bloqueantes | Altos | Medios |
|---|---|---|---|---|
| **1 · Plantillas de WhatsApp de Meta desde el CRM** (L9-96) | **SÍ** | 0 | 0 | 0 |
| **2 · Enmienda M-1** (lado del motor: oferta, `decide`, `commit`, sello, selector de n8n y script de llaves) | **SÍ**, con `MARKETING_ULTRON_STUDIO_PROMPTS=off`. El paquete del Studio que escribe `/2` (bs-studio) **queda pendiente**: ver §4 | 0 | 0 | 0 |

**No hay bloqueantes ni altos.** Lo que encontré son hallazgos BAJO e INFO, todos con su arreglo mínimo. Ninguno impide desplegar con las banderas apagadas.

### Lo que el lead tiene que saber antes de encender

1. **M1-A (BAJO, confirmado con prueba de concepto).** La respuesta a la pregunta «¿puede una respuesta de `decide` manipulada hacer algo más que elegir Legacy?» es **sí**.
   - El selector de n8n acepta **cualquier** paquete cuya huella cuadre consigo mismo (`n8n/flujos/ultron-studio-s1/elegir-prompts.js:91-98`).
   - Quien pudiera alterar la respuesta de `decide` impondría prompts de sistema arbitrarios en los cinco nodos de OpenAI.
   - Laravel lo detecta después (sello `mismatch`), pero **no lo frena**; es así por diseño (E15).
   - El riesgo real es bajo: `decide` va por HTTPS con autenticación por cabecera, y quien manipula esa respuesta ya controla el contenido de usuario de esos nodos.
   - **Lo pido:**
     - no describirlo como «solo puede elegir Legacy»;
     - **alertar** de los estados `mismatch` y `legacy_drift`.
2. **PM-1 (BAJO).** Fuera de producción, el token real de Meta se manda en `Authorization` a la base del simulador (`ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE`), que admite `http://` y cualquier host que no sea de Meta.
   - Hoy no hay simulador de plantillas en el repositorio, así que es una exposición latente.
   - Arreglo de una línea en `GraphWhatsappTemplateClient::request()`.
3. **M1-B (BAJO).** `motores-prompts-scope.sh`:
   - no guarda el modo original del fichero de entorno: las órdenes de vuelta imponen `600`;
   - si aborta tras escribir, no restaura solo;
   - deja copias completas del fichero de entorno (con todos los secretos) en `/root/env-pre-prompts-*`, sin caducidad.
   - Es el mismo patrón que `h5-engine-scope.sh`, que ya existía.
4. **Pendiente de revisión (no lo he revisado, por instrucción del lead).** El escritor de `/2` del Studio (bs-studio).
   - La garantía «lo que aprobaron cuatro ojos es lo que se envía» descansa en ese paquete: el motor solo comprueba la firma y que la huella cuadre.
   - Hay que revisarlo antes de la etapa E2.

### Bloqueantes y altos (fichero:línea)

Ninguno.

---

## 1. Alcance

### Revisado (código real, no los informes)

**Paquete 1:**
- el cliente neutro (`app/Services/Meta/Templates/**`: Graph, guardas, errores, resultado, plantilla leída, fábrica, sonda; el doble solo en lo que impide construirlo en producción);
- `StudioMetaTemplates.php` (entero);
- el diff de `StudioMessageTemplates.php`, `StudioTemplateMetaChecks.php`, `TemplateMetaCheckCommand.php`, `OutreachPublishedStrategies.php` (el puente), `StudioAccess.php`, `AuthorizationMap.php`, `CrmPermission.php`, `PermissionCatalog.php`, `AppServiceProvider.php` (limitador), `config/ultron_studio.php`, `routes/ultron-studio.php` y `routes/console.php`;
- `UltronStudioMetaTemplateController.php`, `MetaTemplatesSyncCommand.php` y el modelo `StudioMetaTemplateSubmission.php`;
- las restricciones de la migración `2026_11_15_100001`;
- `.env.example`.

**Paquete 2:**
- `app/Services/Marketing/Ultron/Prompts/**` (oferta, sello, compilador, base; el generador solo en lo que lee y escribe);
- el diff de `UltronPublishedConfig.php`, `UltronDecideService.php`, `UltronCommitService.php`, `UltronController.php` y `config/marketing.php`;
- `UltronDecideToken.php`, sin cambios (firma);
- `UltronPromptTrace.php` y `UltronPromptsN8n.php`;
- `n8n/flujos/ultron-studio-s1/{elegir-prompts.js, preparar-commit.js, flujo-s1.json, fixtures/}`;
- los nodos `Preparar Rendicion` y `Rendicion Minima` del flujo generado;
- `docs/ultron-studio/ops/motores/motores-prompts-scope.sh`.

### Fuera de alcance (no declaro nada seguro de esto)

- **Paquete del Studio que escribe `/2`** (bs-studio, en curso): `StudioPublicationDeclaration`, `StudioPublishing`, `StudioPublications` (incluido `status.engine.prompts`), `StudioEngineReadings`, `StudioCatalog`, y el diff de `StudioCanaryMeasures.php` y `StudioPublications.php` que aparece en el árbol. Lo que hay que mirar está en §4.
- La migración `2026_11_16_100001` (CHECK `/1|/2`) y `ops/motores-schema-check.sql`: son de database-specialist.
- `verificar-flujo.cjs`: solo comprobé que existe; no revisé su lógica.
- El CRM (crm-fe) de los dos paquetes. En particular, cómo pinta `meta.submission.error.message` (texto de Meta, saneado en el servidor) y la vista previa de WhatsApp: el plan exige interpolación y nada de `innerHTML`. No lo he verificado.
- Meta y n8n reales, el banco y producción.

---

## 2. Modelo de amenazas resumido

### Activos, por impacto

1. **El token de Meta** (conexión del CRM o `META_ACCESS_TOKEN`). Escribe en la cuenta de WhatsApp (WABA) del gimnasio.
2. **La cuenta de WhatsApp del gimnasio.** Una plantilla aprobada es irreversible: solo se borra, y su nombre queda bloqueado 30 días. Además, la calidad de la cuenta.
3. **El registro que arma el canal de W9** (`ultron_studio_template_meta_checks`). Una fila `APPROVED` falsa haría que las campañas envíen.
4. **Los prompts de producción de ULTRON** y el comportamiento del motor en la conversación canaria.
5. **El secreto interno** (n8n ↔ Laravel) y la `app.key` (firma del `decide_token` y de `/2`).
6. **El fichero de entorno de producción.**

### Actores

- Personal con `ultron.view`, sin la llave.
- Administrador (sin `ultron.meta_templates_submit` por defecto).
- Super Admin con la llave pero sin persona verificada.
- Autor y revisor de una plantilla.
- Quien tiene el secreto interno (n8n, o quien lo robe).
- Quien pudiera alterar la respuesta de `decide` en tránsito.
- Meta / Graph como tercero (API10).
- El operador en el servidor.

### Fronteras de confianza

| Frontera | Qué la cruza | Validación |
|---|---|---|
| CRM → API del Studio | 4 rutas nuevas | Puerta, persona, banderas, llave y persona verificada |
| API → Graph | Petición saliente | Host fijo en producción, ids solo dígitos, campos de una lista cerrada |
| Graph → API | Respuesta de un tercero | Normalizada en `MetaTemplate::fromGraph`; errores en `TemplateApiErrors` |
| n8n → `decide`/`commit` | Secreto interno | `decide_token` firmado con HMAC |
| `decide` → n8n | Prompts | Solo integridad; ver M1-A |
| Operador → fichero de entorno | Script de llaves | Ver M1-B |

---

## 3. Paquete 1 · Plantillas de WhatsApp de Meta desde el CRM

### 3.1 Comprobado (lo que pidió el lead, punto por punto)

#### Autorización por ruta (BFLA): CONFIRMADO

- Las 4 rutas están en `UltronStudioMetaTemplateController.php:37-42` y en `AuthorizationMap.php` (las dos de enviar y comprobar, en `:861-862`).
- **Enviar y «Comprobar ahora» exigen `ultron.meta_templates_submit` en dos sitios:**
  - en la puerta;
  - **otra vez en el servidor**: `StudioMetaTemplates.php:378` y `:478`, con `StudioAccess::require()`, que además exige `ultron.view` (`StudioAccess.php:121-131`).
- La llave es solo de Super Admin: excluida del Administrador en `CrmPermission.php:336`.
- La sonda exige `ultron.integrations_manage` (`:317`).
- Las tres exigen persona **verificada** (`StudioPersonBindings.php:81-124`, `verified_at` no nulo y `revoked_at` nulo): `:318`, `:390` y `:480`.
- No depende del CRM: el bloque `actions.*` del DTO es solo informativo (`:763-791`).
- La prueba `MetaTemplateRoutesTest` (401 sin sesión, 403 con el token compartido y con un Administrador) está **en verde (10/10)**.

#### BOLA/IDOR por uuid de plantilla: CONFIRMADO sin hallazgo

- Hay un solo inquilino y no hay objetos de otros usuarios: cualquier titular de la llave actúa sobre cualquier versión. Es el modelo del Studio y está documentado (§5.14).
- `{uuid}` va con `whereUuid` (`Controller:61-62`).
- `findByUuid` responde 404 si no existe (`StudioMessageTemplates.php:786-790`).
- El uuid de **versión** de Meta no entra nunca por la API: se lee de la base.
- El repetido con la misma `clientRequestId` exige la misma cuenta y la misma versión (`:799-807`). Si no, 409 `client_request_reused`.

#### Cuatro ojos: CONFIRMADO

- Solo se envía una versión `APPROVED` (`:735`), íntegra (`:738`) y con autor ≠ revisor por cuenta **o** por persona (`:741`, `:1345-1353`; sin revisor, se niega).
- Q3 (sin cuatro ojos adicionales para enviar) se cumple: puede enviar la autora, y es una decisión aceptada.
- **Fila positiva `APPROVED`:**
  - la firma quien envió, si **en ese momento** puede dar fe: activo, con `ultron.view` y la llave, y con su persona verificada (`:1333-1342`);
  - o quien pulsa «Comprobar ahora», que ya pasó esas mismas cerraduras;
  - si no hay nadie, no se escribe (`:1053-1066`).

#### Idempotencia y doble envío: CONFIRMADO

- **Clave de idempotencia:** `clientRequestId` único en la base (`:386-389` y el UNIQUE de la migración).
- **Cerrojo por versión** sin espera (`:398-406`), con un segundo `submitBlocker` ya dentro del cerrojo (`:410-412`).
- **Índice único parcial** `UNIQUE(template_id) WHERE state <> 'FAILED'`: es la red de seguridad sin caché compartida (`:439-446`).
- **El POST nunca se repite:**
  - sin `retry()` y sin redirecciones (`GraphWhatsappTemplateClient.php:309-310`);
  - un fallo de transporte después de salir queda `UNKNOWN` (`:315-331`), y un 5xx de Meta en un POST también (`:371`);
  - un `UNKNOWN` se resuelve por nombre e idioma, o pasa a `FAILED` a los 15 minutos (`StudioMetaTemplates.php:996-1012`);
  - un `UNKNOWN` bloquea reenviar (`:747`).
- Las pruebas de dos envíos a la vez y de tiempo agotado están en el informe del autor; no las volví a ejecutar.

#### Limitadores: CONFIRMADO

- `ultron-studio-meta` (`AppServiceProvider.php:676-679`): 20 por hora por persona y 60 por hora por red, además del cupo de escritura.
- La sonda tiene enfriamiento de 300 s y su limitador. Ese limitador lo comparte con Twilio: ver PM-2.

#### SSRF e inyección en la ruta o los parámetros de Graph: CONFIRMADO sin hallazgo en producción

- Base fija `https://graph.facebook.com` con `app()->isProduction()` (`GraphWhatsappTemplateClient.php:283-286`).
- Fuera de producción, solo `graph_base`, sin usuario, consulta ni fragmento, y que no sea un dominio de Meta (`:502-535`).
- La WABA sale de `MetaAuthService` y se exige que sea solo dígitos (`StudioMetaTemplates.php:241-249` y `TemplateRequestGuard.php:56-72`).
- El id de la plantilla, solo dígitos, con la marca `D` (`TemplateRequestGuard.php:24`). También está en el CHECK de la base.
- Los campos, de una lista cerrada (`:99-119`).
- El cursor, con una expresión cerrada (`:36`, `:130-137`).
- **La URL `paging.next` de Meta nunca se pide** (`GraphWhatsappTemplateClient.php:422-429`).
- La consulta va por `Http::get($url, $query)`, que codifica los parámetros.
- **Ningún dato tecleado en el CRM llega a la URL:** el cuerpo de enviar es solo `{clientRequestId}`, y lo que sale se reconstruye desde la versión inmutable (`StudioMessageTemplates.php:388`).

#### Validación del contenido: CONFIRMADO

- **Botón de URL:**
  - patrón `^https://` sin `{` (así, imposible una URL dinámica) (`StudioMessageTemplates.php:234`);
  - sin usuario, contraseña ni puerto;
  - **host exacto** de la lista del servidor (`:1190-1222`, `:1328`);
  - lista vacía, sin botones de URL.
- **Botón de llamada:**
  - siempre el número de `legal.privacy_phone`;
  - uno tecleado solo vale si sus dígitos son esos mismos (`:1226-1240`, `:1346`).
- Encabezado, pie, botones y ejemplos pasan por `PlainText` estricto, la guarda de datos de contacto y R-01/02/03/06.
- La pulsación del botón de baja llega al normalizador como texto (`MetaWebhookService.php:194-200`, sin cambios).

#### El token, nunca fuera: CONFIRMADO en producción (fuera de producción, ver PM-1)

- Se lee en el momento de llamar y va solo en `withToken` (`GraphWhatsappTemplateClient.php:304-310`).
- **Resultados:**
  - solo códigos, `error_user_title` y `error_user_msg` saneados (el token exacto, `Bearer …`, `access_token=…`, `EAA…`, las cadenas opacas largas y los números de 8 o más cifras, enmascarados);
  - y `fbtrace_id` (`TemplateApiErrors.php:101-118`, `:158-182`).
- **Registros:** códigos y tiempos (`GraphWhatsappTemplateClient.php:442-465`; `StudioMetaTemplates.php:578`, `:819`, `:826`).
- **Excepciones:** `TemplateRequestGuard` nunca repite el valor recibido; de un fallo inesperado del cliente HTTP solo se guarda la clase.
- No hay intermediarios globales de `Http` ni Telescope que registren cabeceras (comprobado con grep).
- La prueba centinela (token de la conexión y del fichero de entorno en respuestas, tablas, libro, registros y URL) está **en verde**.

#### Exposición en los DTO: CONFIRMADO sin hallazgo relevante

- `submissionDto` (`:1364-1391`): huellas no, la WABA no, el token no.
- `status` (`:282-303`): ni el token ni la WABA. Ver PM-3, que es solo informativo.
- La lista del Studio lleva solo `{status, state, campaignReady}`, en tres consultas para toda la página.

#### Errores de Meta: CONFIRMADO

- Un fallo de Meta va dentro del DTO (`state` y `error`), nunca como 5xx (`:833-857`).
- El texto guardado es el saneado, en una línea y con 300 caracteres como mucho (`:866-878`), y lo refuerza el CHECK `[[:cntrl:]]` de la tabla.

#### Banderas apagadas: CONFIRMADO

| Situación | Qué pasa | Dónde |
|---|---|---|
| Maestra del módulo apagada | 409 `meta_templates_disabled` en la sonda, enviar y comprobar; `status` responde 200 | `Controller:98-99`, `:116-117`, `:136-137` |
| Estrategias apagadas | 409 en las cuatro | |
| Bandera de envío apagada o `META_ENABLED=false` | 409 antes de leer nada | `:379-384` |
| Sin cuenta o sin credencial | 409 `meta_not_configured` sin red | `:1404-1412` |
| Driver de prueba en producción | No se construye; en producción siempre `graph` | `WhatsappTemplateClientFactory.php:35-40`, `FakeWhatsappTemplateClient.php:96-99` y `:491-493` |

La prueba «con las banderas por defecto ni las 4 rutas ni la orden salen a la red aunque el driver sea `graph`» está **en verde**.

#### Orden de sincronización: CONFIRMADO

**No escribe sin las banderas:**
- `sync()` sale `disabled` si falta la maestra **o** la de sincronización, también con `--template` y `--dry-run` (`:540-542`);
- el programador solo la agenda con `sync_enabled`, en un bloque propio que vigila `UltronEngineIsolationTest` (en verde, 11/11).

**Qué confía de Meta:**
- el id solo dígitos;
- el nombre con el patrón del Studio;
- el idioma de la lista del Studio;
- la categoría, de una lista cerrada;
- el estado, de una lista cerrada (si no, `UNKNOWN`, que bloquea) (`:1133-1137`).

**Las huellas:**
- se recalculan con la misma normalización;
- las claves desconocidas hacen que no case (`MetaTemplate.php:268-282` y `StudioMessageTemplates::metaComponentsFingerprint`).

**Lo que puede Meta:** una respuesta de Meta solo puede llevar a `APPROVED` si casa byte a byte con la versión aprobada en el Studio, y siempre con alguien que dé fe en ese momento. Una respuesta negativa siempre cierra. Si Meta falla, no se inventa nada (`:577-585`).

#### WhatsApp actual, diff vacío: CONFIRMADO

`git diff 120a8b23 --quiet` sale con 0 sobre:
- `WebhookMetaController.php` y `MetaWebhookService.php`;
- `MetaMessagingService.php`, `MetaAuthService.php` y `WhatsappIntegrationRegistry.php`;
- `WhatsappOutboxService.php`, `RetryWhatsappOutbox.php` y `MarketingMessageDispatcher.php`;
- `routes/api.php` y `routes/marketing.php`.

### 3.2 Hallazgos del paquete 1

#### PM-1 · BAJO · Fuera de producción, el token real viaja al simulador (que puede ser `http://` y cualquier host no-Meta)

- **Dónde:**
  - `GraphWhatsappTemplateClient.php:283-286` (base) y `:304-310` (`withToken` con `MetaAuthService::accessToken()`);
  - `:502-522` (`simulatorBase` admite `http`);
  - `config/ultron_studio.php`, `meta_templates.graph_base`.
- **Qué pasa:** con `APP_ENV` distinto de `production` y `ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE` puesta, cada llamada lleva `Authorization: Bearer <token>`. Es el token de la conexión del CRM o el del fichero de entorno, hacia ese host.
- **Actor e impacto:**
  - en un entorno no productivo con una conexión de WhatsApp real (copia de la base o variables reales), el token de producción saldría hacia el simulador, quizá sin TLS, y a sus registros;
  - con ese token se crean plantillas y se envían mensajes en la cuenta del gimnasio.
- **Estado:**
  - **camino confirmado por código**: la propia prueba centinela lo hace, con la base `http://127.0.0.1:8097` y comprobando que se envía el token de la conexión;
  - la **exposición** es una hipótesis: hoy no hay simulador de plantillas en el repositorio (lo busqué) y no sé si algún entorno no productivo tiene un token real.
- **OWASP:** API8 (configuración) y API10.
- **Arreglo mínimo** (backend, `GraphWhatsappTemplateClient::request()`):
  - fuera de producción, mandar un portador fijo de simulador (por ejemplo, `simulator`) en lugar de `accessToken()`;
  - o, como mínimo, exigir que la base sea `127.0.0.1`/`localhost` o `https`.
  - Hay que adaptar la prueba centinela, que hoy exige que viaje el token de la conexión.
- **Prueba negativa:** con `APP_ENV=testing` y base de simulador, ninguna petición registrada lleva el token de la conexión ni el del fichero de entorno en `Authorization`.

#### PM-2 · BAJO · La sonda de Meta comparte el limitador con la de Twilio

- **Dónde:** `UltronStudioMetaTemplateController.php:60` (`throttle:ultron-studio-probe`) y `AppServiceProvider.php:666-669` (los mismos cubos por persona y por red).
- **Impacto:**
  - usar una sonda gasta el cupo de la otra (6 por hora por persona y 20 por hora por red, en total);
  - el 429 de la de Meta habla de Twilio.
  - No hay riesgo de datos. Es una pequeña denegación entre módulos para quien tiene `ultron.integrations_manage`, y un mensaje engañoso.
- **Estado:** confirmado (el autor lo deja como pendiente §9.2).
- **OWASP:** API4.
- **Arreglo:** un limitador propio, `ultron-studio-meta-probe`, con los mismos topes y su texto (backend, `AppServiceProvider` y el controlador).
- **Prueba negativa:** 6 sondas de Twilio no hacen que la séptima de Meta dé 429.

#### PM-3 · INFO · `status` enseña a cualquiera con `ultron.view` el estado de la credencial y quién lanzó la sonda

- **Dónde:** `StudioMetaTemplates.php:289-291` (`configurationProblem`: `missing_token`, `malformed_token` o `missing_waba`; y `credentialSource`) y `:348` (`lastProbe.by` con nombre y rol).
- **Impacto:** ninguno de esos datos es secreto. Es información de configuración para personal interno.
- **Estado:** confirmado.
- **Decisión de producto:** si se prefiere, `configurationProblem` y `credentialSource` solo con `ultron.integrations_manage`.

#### PM-4 · INFO · El repetido de enviar se resuelve antes de exigir la persona

- **Dónde:** `StudioMetaTemplates.php:386-390`.
- **Qué pasa:** quien perdió su vínculo verificado puede releer, con la misma `clientRequestId`, el DTO de **su propio** envío anterior.
- **Impacto:** solo lectura de un acto propio, sin efecto en Meta.
- **Estado:** confirmado.
- **Acción:** ninguna, o mover el repetido después de `requirePerson`.

#### PM-5 · INFO · Filas negativas `meta_api` a nombre de quien dio fe la última vez

- **Dónde:** `StudioMetaTemplates.php:1056-1063`.
- **Qué pasa:** es lo decidido en D-5, y `source = meta_api` las distingue. Pero `recorded_by_name` atribuye a una persona una escritura que hizo el sistema.
- **Recomendación** (crm-fe): pintar «registrada por la sincronización (en nombre de X)» cuando `source = meta_api`.

#### PM-6 · INFO operativo · La salida a Graph depende de `APP_ENV=production`

- **Qué pasa:** si producción no tuviera exactamente `production`, el cliente usaría `graph_base`, que está vacía. Entonces todo sería `not_configured`: falla cerrado y no hay riesgo.
- **Antes de encender:** confirmar `APP_ENV=production` y poner `ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS=ironbodyneiva.cloud` (Q1). En `.env.example` está vacía a propósito.

---

## 4. Paquete 2 · Enmienda M-1 (lado del motor)

### 4.1 Comprobado (E15 y lo que pidió el lead)

#### Nada del commit se ramifica por `prompts`: CONFIRMADO

- `$studioPrompts` se calcula una vez tras verificar el token (`UltronCommitService.php:376`).
- Solo se **pasa** hasta `persist()` y desemboca en `metadata.studio_prompts` (`:2541`).
- Grep de todos los usos (`:376`, `:397`, `:414`, `:419`, `:1253`, `:2059`, `:2282`): ningún `if` depende de él.
- El cálculo nunca lanza: si falla, `stamp_error` (`:2472-2499`).

#### `decide` negociado no expone ids, datos personales ni texto libre: CONFIRMADO

- `studio_prompts` lleva:
  - contrato, modo, motivo, base y compilador;
  - huella;
  - uuid de la versión y de la entrada, huella de contenido y `seq`;
  - y los cinco textos (`UltronPromptOffer.php:175-187`, `:212-224`).
- Los textos salen solo de la base del repositorio, verificada con sha256 fijados (`UltronPromptBase.php:92-110`), y de parches **constantes** (`UltronPromptCompiler.php:126-146`).
- Los registros llevan solo el motivo y la clase del error (`UltronPromptOffer.php:199-209`).
- Sin negociar, la respuesta y el token son idénticos a los de hoy (`UltronController.php:142`; `UltronDecideService.php:402`, `:416`, `:610`).
- La prueba E5 (instantánea) está en verde.

#### `decide_token` firmado; `sp` no se puede falsear: CONFIRMADO

- El token no cambia: HMAC-SHA256 con `app.key` y `hash_equals` (`UltronDecideToken.php:96`, `:143-146`).
- `sp` viaja dentro de `x`, que está bajo la firma (`UltronDecideService.php:416`).
- El commit lee lo ofrecido **solo** del token verificado (`UltronCommitService.php:343-376`).
- Sin `app.key` no se puede fabricar un `applied`, porque exige `sp.m = studio` y la huella firmada (`UltronPromptStamp.php:113-117`).
- **Límite que hay que conocer:** lo observado lo declara n8n. Un titular del secreto interno con una oferta `studio` legítima puede declarar `applied` aunque haya usado otros textos. El sello es la palabra de n8n, no una prueba criptográfica de lo aplicado.

#### Un `prompt_contract` o un bloque `prompts` hostil: CONFIRMADO sin 500, sin turno mudo, sin escrituras grandes y sin contenido en los registros

- **`prompt_contract`:** se compara con `===` y nunca da 422 (`UltronController.php:142`).
- **Bloque `prompts`:**
  - tiene forma cerrada: modo de una lista, huella de 64 caracteres hexadecimales, y textos de 64/32/64 con `^[A-Za-z0-9_.:-]+$` (`:296-301`);
  - una clave de más dentro de `prompts` da 422 (`:435`).
- **Si ese 422 llegara de un n8n legítimo, la rendición no reenvía el bloque.** `Preparar Rendicion` y `Rendicion Minima` construyen sus claves una a una (lo comprobé en `flujo-s1.json`), así que el turno no se queda mudo.
- El motivo que emite el selector se sanea a ese mismo patrón y a 56 caracteres como mucho (`elegir-prompts.js:92`).
- **Sello acotado:** lo ofrecido sale del token (2 048 como mucho) y lo observado son 5 cadenas cortas. El aviso del registro lleva el estado, los modos y `flowRev` (`UltronCommitService.php:2482-2496`).
- **Tamaño del token:** la prueba exige menos de 1 500 caracteres con `sp`, frente a un tope de 2 048 (`UltronDecidePromptsTest`, en verde).
- **Volumen:** solo la conversación canaria recibe unos 115 KB de roles. Para cualquier otra, la oferta es `legacy out_of_scope` sin leer la base (pasos 1–3 de `UltronPromptOffer.php:121-130`).

#### Ningún texto del personal entra en un prompt: CONFIRMADO

- `UltronPromptCompiler::validate()` acepta exactamente cuatro claves de tipo y valor cerrados (`:236-270`): tono de 3, objetivo de 3, emojis booleano, y `maxChars` entero de 160 a 480 de 20 en 20.
- El único valor interpolado es ese entero (`:340`).
- En el motor, `/2` también se valida por tipos (`UltronPublishedConfig.php:454-470`, `:581-594`) y después por valores al compilar.
- No hay inyección de prompt desde el Studio en esta entrega.

#### Ante cualquier fallo, siempre Legacy: CONFIRMADO

- `forConversation` envuelve todo en `try/catch(Throwable)` y responde `legacy error` (`UltronPromptOffer.php:100-107`).
- `partFor` y `lookup` nunca lanzan (`UltronPublishedConfig.php:203-225`).
- Si la base es ilegible o está alterada, se lanza `UltronPromptException` y la respuesta es Legacy.
- La huella firmada tiene que coincidir con la recompilada (`:169-172`).
- En n8n, cualquier excepción del selector lleva a `LEGACY` (`elegir-prompts.js:102`), y `Preparar Commit` omite el bloque si algo falla.

#### Mando de llaves: ver M1-B

- No imprime valores: solo un JSON con 4 claves no sensibles (`:28-32`), estados y la traza, que no lleva ids ni textos.
- En el camino feliz conserva dueño y modo: `sed -i` de GNU como root los preserva. El script los imprime pero no los comprueba.

#### Guarda de aislamiento del motor (A9 enmendada en M1-6)

`UltronEngineIsolationTest`, ejecutada **en verde (11/11, 1 609 aserciones)**. El informe del autor decía que caía; ya está enmendada.

#### El selector de n8n

Ver M1-A: hace **más** que elegir Legacy.

### 4.2 Hallazgos del paquete 2

#### M1-A · BAJO · El selector solo comprueba integridad, no autenticidad: una respuesta de `decide` manipulada impone prompts de sistema arbitrarios

- **Dónde:** `n8n/flujos/ultron-studio-s1/elegir-prompts.js:91-98`. Compara `sha256(material(sp.roles))` con `sp.bundle_hash`, y **los dos vienen de la misma respuesta**.
- **Cómo se demuestra:** prueba sin red en `vm` con un paquete sintético de cinco textos hostiles y su sha256. Resultado: `{"mode":"studio","reason":null,"observedEqualsForged":true}`. El composer recibe el texto hostil (§6).
- **Actor:** quien pueda alterar la respuesta de `decide`:
  - un intermediario en `https://api.ironbodyneiva.cloud/api/internal/marketing/ai/decide`, que exige romper TLS;
  - o quien controle Laravel o el flujo de n8n, que ya puede todo.
- **Impacto:** prompts de sistema arbitrarios en los cinco nodos de OpenAI durante ese turno.
  - El borrador sigue pasando por las guardas deterministas del commit.
  - El mismo actor ya podía alterar el contenido de usuario de esos nodos (`values[1]` sale de la respuesta de `decide`). Lo que gana de más es el prompt de sistema.
  - Laravel lo **detecta** después: el sello queda `mismatch`, porque la huella observada no es la firmada en `sp`, y se escribe un aviso en el registro. **No lo frena**, por diseño (E15: nada se ramifica por `prompts`).
- **Estado:** confirmado (prueba de concepto). El plan lo asume en §B.7 («la autenticidad la da el canal autenticado de `decide`»).
- **OWASP:** API10 (confianza en la respuesta de otro servicio) e integridad del canal.
- **Arreglo mínimo, sin tocar el contrato:**
  1. Que nadie lo documente como «solo puede elegir Legacy».
  2. **Alertar** (vigía o monitor, con su incidente) ante los estados `mismatch` y `legacy_drift`.
  3. Pintarlos en rojo en `status.engine.prompts` (bs-studio y crm-fe).
- **Alternativa estructural (architect y propietario):** que un `mismatch` fuerce la salida curada. Contradice E15 y abre un camino de fallo nuevo, así que hoy no la recomiendo.
- **Prueba negativa** (tester, E14): rol alterado en tránsito **con su huella recalculada** → el sello es `mismatch`, se escribe el aviso (y salta la alerta, si se añade) y el turno sigue saliendo.

#### M1-B · BAJO · `motores-prompts-scope.sh`: permisos de la vuelta atrás, fallo a medias y copias del fichero de entorno

- **Dónde:** `docs/ultron-studio/ops/motores/motores-prompts-scope.sh`.

**Problemas:**

1. **No guarda el modo original del fichero de entorno.** `DUENO` solo guarda dueño y grupo (`:51`), y las tres órdenes de vuelta imponen `install -m 600` (`:61`, `:64`, `:71`). Si en producción el fichero no es `600`, volver atrás le cambia los permisos.
2. **No restaura solo si falla a medias.**
   - Si aborta en `:61`, el fichero queda modificado: el valor nuevo no está activo, porque la caché sigue siendo la anterior.
   - Si aborta en `:64` o `:70-72`, la caché ya se regeneró y **el valor nuevo ya está activo en FPM**, sin reinicio de colas.
   - Con `set -e`, un fallo de `queue:restart` o de `supervisorctl` corta el script sin resumen.
3. **Copias de todos los secretos.** Cada ejecución deja en `/root/env-pre-prompts-<llave>-<ts>` una copia completa del fichero de entorno (`600`, root), con todos los secretos y sin caducidad. Tras una rotación, los secretos viejos siguen ahí.

**Lo que está bien:**
- no imprime ningún valor sensible;
- acepta solo `off`/`canary`, así que no hay inyección en `sed`;
- comprueba la llave actual antes de tocar nada;
- exige que no haya claves repetidas;
- comprueba que el fichero se puede leer **antes** de `config:cache`.

- **Estado:** confirmado (lectura del script). El punto 1 solo tendría impacto si el modo de producción no es `600`, y eso no lo he verificado.
- **Arreglo** (backend/ops; el mismo patrón vale para `h5-engine-scope.sh`):
  - `MODO=$(stat -c %a "$ENVF")`, y las vueltas con `install -m "$MODO"`;
  - comprobar después de `sed` que dueño y modo no cambiaron;
  - un `trap` en error que restaure la copia **antes** de `config:cache`;
  - documentar la retención y el borrado de `/root/env-pre-*` (o borrarlas al terminar bien, tras mostrar el resumen).
- **Escalado a `security`:** la retención de copias del fichero de entorno es transversal.

#### M1-C · INFO · La huella del paquete no es inyectiva entre roles

- **Dónde:** `UltronPromptBase.php:129-149`, replicado en `elegir-prompts.js:83`.
- **Qué pasa:** concatena con `"\n"` sin prefijo de longitud. Dos repartos distintos del mismo texto entre roles darían la misma huella si algún rol contuviera una línea igual a un nombre de rol.
- **Comprobado:** ninguna línea de los 5 ficheros base es `strategist`, `composer`, `critic`, `composer_retry`, `critic_retry` ni `ultron-prompts/1`. Hoy no existe un reparto alternativo, y los parches son de una línea y no contienen esos nombres.
- **Acción:** ninguna ahora. Si un día cambia la base o el compilador, mantener esa comprobación en E2 o pasar a un formato con longitud en una versión nueva del contrato.

#### M1-D · INFO · Los motivos de Legacy delatan cuál es la conversación canaria

- **Qué pasa:** `out_of_scope` frente a `no_publication`, `part_legacy` u otros (`UltronPromptOffer.php:127-130`).
- **Por qué no es grave:** solo lo ve quien tiene el secreto interno, que con `decide` ya lee el contexto completo de cualquier conversación. El plan dice que «el Studio nunca la conoce ni la enseña», y eso se sigue cumpliendo.
- **Acción:** ninguna.

#### M1-E · INFO · Ya existía, es transversal y no lo introduce esta ola: las ejecuciones de n8n enseñan el portador interno

- **Qué pasa:** R4 del plan y la memoria del proyecto. El sello guarda ahora `execution_id` (`UltronPromptStamp.php:165`), que no es secreto: sirve para correlacionar, no para abrir la ejecución.
- **Recomendación:** escalar a `security`:
  - que n8n no guarde los datos de las ejecuciones con éxito del flujo, o que oculte las cabeceras;
  - y rotar el secreto interno si alguna ejecución con él se compartió fuera.

### 4.3 Pendiente: paquete del Studio que escribe `/2` (bs-studio), NO revisado

El motor **confía en la firma**. Lo que el Studio firme en `governed` es lo que llega a producción. Antes de E2 hay que revisar:

1. **La vista previa ata la huella exacta del paquete a los cuatro ojos.** Quien aprueba ve los parches y la huella, y publicar exige la misma `previewHash` (E15).
2. **Solo se escribe `/2` con la llave del motor distinta de `off`.** «Volver a Legacy» siempre en `/1` (R10).
3. **Se responde 409 `prompts_compile_failed`** sin firmar nada.
4. **`status.engine.prompts`:**
   - sin ids de conversación ni de mensaje y sin textos;
   - solo turnos de la canaria;
   - 401/403 por rol;
   - y, por M1-A, los estados `mismatch` y `legacy_drift` bien visibles.
5. **`StudioEngineReadings`:** la lectura de la llave de prompts no expone otros valores del entorno.
6. **El diff de `StudioCanaryMeasures.php` y `StudioPublications.php`** que ya aparece en el árbol.

---

## 5. Pruebas negativas propuestas (para `tester`)

| Hallazgo | Prueba |
|---|---|
| PM-1 | `APP_ENV=testing` con base de simulador: ninguna petición lleva en `Authorization` el token de la conexión ni el de `META_ACCESS_TOKEN`; con base `http://` a un host que no es local, `not_configured` (si se elige esa variante) |
| PM-2 | Agotar la sonda de Twilio (6 por hora) no da 429 en `POST …/meta/probe` |
| M1-A | E14 ampliada: rol alterado **con la huella recalculada** → `mismatch` + aviso en el registro (+ alerta) y turno entregado; mutación: quitar `hash_equals($ofrecida, $huella)` de `UltronPromptStamp::state()` (`:116`) hace caer la prueba |
| M1-B | Prueba del script en un contenedor desechable: un fichero de entorno con modo `640` sigue en `640` tras la ejecución y tras la vuelta; un fallo forzado en el paso de lectura deja el fichero idéntico (por el `trap`) |
| Regresión ya cubierta (mantener) | Sin sesión 401, token compartido 403 y Administrador 403 en enviar y comprobar; banderas apagadas → 409 y cero red; centinela del token; `decide` sin negociar idéntico; `commit` con clave de más en `prompts` → 422 `unexpected_fields`; token con `sp` < 1 500 |

---

## 6. Qué verifiqué ejecutando de verdad

Una ejecución cada vez, con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging`, en SQLite:

| Fichero | Resultado |
|---|---|
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **OK, 11/11 (1 609 aserciones)** |
| `tests/Feature/Marketing/UltronCommitPromptsTest.php` | **OK, 35/35 (232)** |
| `tests/Feature/Marketing/UltronDecidePromptsTest.php` | **OK, 138/138 (2 358)**: matriz de llaves, token < 1 500, 0 o 1 sentencias sobre la cadena |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateRoutesTest.php` | **OK, 10/10 (208)**: cerraduras, banderas, cero red y centinela |

**Prueba de concepto sin red de M1-A** (script en el scratchpad de esta sesión, ejecutado con `node` y `vm` sobre `elegir-prompts.js`):
- **Entrada:** `studio_prompts` sintético con cinco textos «PROMPT HOSTIL DE PRUEBA…» y su sha256.
- **Salida:** `{"mode":"studio","reason":null,"observedEqualsForged":true,"composerStartsWith":"PROMPT HOSTIL DE PRUEB"}`.
- Sin secretos y sin datos reales.

**Comprobaciones estáticas:**
- diff vacío del WhatsApp actual (§3.1);
- los JSON de n8n (`flujo-s1.json` y el respaldo) solo llevan **referencias** de credenciales (id y nombre), ningún valor ni `pinData`;
- los fixtures `decide-*.json` son sintéticos;
- ninguna línea de la base de prompts es igual a un separador de rol.

**Lo que NO pude verificar, y por qué:**
- Meta y n8n reales (prohibido en esta tarea).
- La regresión completa y PostgreSQL (memoria limitada y otros agentes trabajando).
- Las carreras reales entre procesos al enviar (el autor las probó con el cerrojo tomado a mano).
- El modo real del fichero de entorno de producción (M1-B.1).
- El CRM.

---

## 7. Riesgos residuales y decisiones del usuario

1. **Aceptar M1-A como riesgo residual documentado** (con la alerta propuesta), o pedir al architect el endurecimiento estructural.
2. **PM-1:** corregirlo antes de que exista un simulador de plantillas o de usar `graph` en cualquier entorno no productivo con una conexión real.
3. **Copias del fichero de entorno en `/root`** (M1-B.3): decidir la retención. Si alguna copia salió del servidor, los secretos que contiene se consideran expuestos y hay que **rotarlos**.
4. **Botones de URL:** la lista de hosts es exacta (`ironbodyneiva.cloud`). No evalué si ese dominio tiene redirecciones abiertas que una plantilla aprobada pudiera aprovechar. Si las hubiera, son de `security` y del dominio, no de esta API.
5. **Secretos que rotar por esta revisión:** ninguno. No vi ningún valor secreto en el código, las pruebas, los JSON de n8n ni los registros revisados.
