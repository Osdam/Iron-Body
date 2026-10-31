# ULTRON STUDIO · Hito 6 (integraciones comerciales): plan del architect

**Fecha:** 31-10-2026. **Autor:** `architect` (no implementa). **Base verificada:** backend `feat/ultron-studio-h5` en `cf59cef` (= `cdb9dc8`, que ya incluye `origin/main` `7318122`, más el arreglo m-2 del lead); CRM `feat/ultron-studio-h5` en `9fe1817`. **Producción hoy** (informe del lead `plans/informes-h5/informe-recuperacion-2026-10-31.md` §3): API `7318122`, CRM `4105016`, Studio = Hito 4 (33 rutas), PostgreSQL 14.24, 1 borrador y **0 versiones**; el Hito 5 **no** está desplegado. Rama propuesta: `feat/ultron-studio-h6`, que nace de la del Hito 5 (o de `origin/main` si el H5 ya está fusionado).

**Rutas.** `‹B›` = `Iron-Body/backend`; `‹U›` = `‹B›/app/Services/Marketing/Ultron`; `‹S›` = `‹B›/app/Services/UltronStudio`; `‹F›` = `Iron-Body_Front/src/app/modules/ultron-studio`. Lo marcado como verificado se leyó en el código, con su línea. Lo marcado **[suposición]** no se pudo comprobar desde el repo. Lo marcado **[lead]** o **[memoria]** viene del encargo o de la memoria del usuario, y el código no lo contradice.

> Este fichero vive en el repo a propósito: el scratchpad de `/tmp` se purga. El lead lo convierte en `ULTRON_STUDIO_H6_CONTRACT.md` cuando decida las preguntas del §11.

---

## 0. Resumen para el lead (una pantalla)

1. **Inventario real.** **Meta Ads: conectada en lectura.** Hay token propio, cuentas, sincronización horaria y un cliente que solo hace GET (`MetaAdsApiClient.php:568-571`); no se escribe nada en Meta. **Twilio: conectada solo para OTP.** Verify por SMS, más Messages para el código de vínculo socio↔personal. No hay Voice, TwiML ni webhook de voz, y el único webhook (`usage-trigger`) solo escribe en el log. **Números canarios: no existe registro.** El canario es **un id de conversación** del entorno (`config/marketing.php:83-86`) y lo comprueban el emisor, el commit y el vigía. **ElevenLabs: no configurada.** No hay ninguna referencia en el código de los dos repos (grep) ni credenciales en producción. El estado es `ELEVENLABS_NOT_CONFIGURED`.
2. **Recomendación general.** El Hito 6 es **registro, lectura y preparación, con cero motor y cero red por defecto**. No toca ninguna línea de `‹U›` ni de las integraciones existentes (sincronización de Meta, OTP, SMS, webhook de Twilio, `routes/api.php`, `config/marketing.php`, `config/meta.php`, `config/otp.php`). El Studio lee **espejos locales**: `meta_ad_entities` y `meta_sync_runs`, los contadores del OTP y `marketing_calls`. Con las llaves por defecto, **ninguna** ruta nueva sale a la red. La única salida posible en el hito es una sonda **GET** de Twilio, apagada por defecto y lanzada con un botón explícito.
3. **Números canarios (C).** Un **registro declarativo administrado desde el CRM**: E.164 estricto, alias, canal `whatsapp` o `voice`, consentimiento declarado, **cuatro ojos por identidad** (también como CHECK en PostgreSQL), y activación y revocación auditadas. Del teléfono se guarda la **huella HMAC** más los 4 últimos dígitos; solo el canal de voz guarda además el número cifrado, que **se borra al revocar**. **El motor no lee el registro en este hito.** El canario del entorno sigue mandando. El Studio calcula, en solo lectura, si el teléfono de la conversación canaria del motor está registrado y activo (`in_sync`). Esa coherencia es la condición medible de cualquier transición futura (T2/T3, §I4.6), que necesitará su propia autorización.
4. **«Asociar una versión» a un número es una expectativa auditada, no una autoridad.** Lo que rige en canario sigue siendo la parte `canary` de la cadena del H5. El CRM enseña las dos y si coinciden; así no hay dos fuentes que se contradigan.
5. **Meta (A).** Tabla propia `ultron_studio_campaign_links` que asocia una campaña (cuenta + id de Meta, resuelta contra el espejo) con la **línea base** o con una **versión** `APPROVED`, `CANARY` o `PUBLISHED`. Como mucho **una asociación activa por campaña** (índice único parcial) y el historial se guarda en filas. La API separa siempre «estado en Meta (con antigüedad y *posiblemente eliminada*)» de «asociación en ULTRON Studio (sin efecto en el motor)». Cero llamadas a Graph. Sin token o sin cuentas responde `meta_not_configured`, sin datos fingidos.
6. **Twilio (B).** **No** se edita el `.env` desde el CRM (§I5.3). «Gestionar la configuración autorizada» significa **huellas no reversibles de la configuración efectiva**, la **deriva** frente a la última configuración reconocida y un **reconocimiento auditado** por una persona con permiso. El webhook no se toca y `TwilioSignatureValidator` **no se extrae** porque no hay un segundo consumidor. Voice queda tras bandera y sin ningún código capaz de llamar.
7. **ElevenLabs (D).** Puerto `VoiceAgentProvider`, `NullVoiceProvider` por defecto y `ElevenLabsVoiceProvider` tras `ELEVENLABS_ENABLED=false`, en un espacio de nombres neutro (`App\Services\Voice`, ni Studio ni motor). Se añaden un `doctor` que responde `ELEVENLABS_NOT_CONFIGURED`, el verificador puro de la firma post-llamada (`t=…,v0=…`, HMAC-SHA256, 30 min; comprobado hoy en la documentación oficial), una política de llamadas cerrada por constante de código (`CALLS_ENABLED_IN_CODE = false`), fixtures y el procedimiento de activación. **No se registra ninguna ruta pública** de webhook de voz en H6.
8. **Tamaño.** Tres tablas nuevas y la ampliación de un CHECK del libro (dos migraciones). Son 18 rutas nuevas (38 → 56) y 4 permisos nuevos. El CRM gana una página hija `marketing/ultron-studio/integraciones`, como el laboratorio. **El catálogo del Studio no cambia** (`StudioCatalog::VERSION` sigue en `2026-10-19.1`, fijado por los fixtures de F, G, H4 y H5).
9. **Dependencias externas.** Ninguna bloquea la implementación. El cierre necesita: el despliegue previo del H5 (este hito se apila sobre su rama); una **segunda persona** para los cuatro ojos del canario en producción; y la autorización del propietario para la fase 2 (y, aparte, para la sonda de Twilio). ElevenLabs queda en `ELEVENLABS_NOT_CONFIGURED` y eso **no** impide cerrar D, porque el alcance de D es preparar.

**Decisiones que necesito del lead:** §11. Las que cambian el plan son la **1** (sonda de Twilio), la **2** (semántica de la versión en el canario), la **4** (permisos y matriz) y la **10** (criterio de cierre).

---

## 1. Delta honesto

| Lo que sugiere el encargo | Lo que hay de verdad | Consecuencia para el Hito 6 |
|---|---|---|
| «Consultar campañas autorizadas» | El espejo local solo tiene campañas que **gastaron** en algún día sincronizado: la dimensión sale de las filas de gasto (`MetaAdsSync.php:1141-1146`). No existe «campaña autorizada para ULTRON» | La lista sale de `meta_ad_entities` (nivel `campaign`, cuentas conectadas). «Autorizada para ULTRON» = con asociación activa. Una campaña nueva sin gasto no aparece hasta que gaste (limitación documentada; pregunta 8) |
| «Consultar estados» | `effective_status` y `synced_at` se refrescan en cada pasada horaria para todo lo que está en la dimensión (`MetaAdsSync.php:1220-1244`), salvo las eliminadas: el borde rechaza `DELETED` y se quedan con su último estado (`MetaAdsApiClient.php:64-71`) | Cada campaña va con su antigüedad. Si `synced_at` es anterior a la última pasada con éxito de su cuenta, se marca `possiblyDeleted` |
| «Seleccionar campañas y asociar estrategias/versiones» | El motor no lee nada del Studio salvo la cadena del H5; en producción hay **0 versiones** | El destino es la **línea base** o una **versión** aprobada, en canario o publicada. Sin efecto en el motor ni en Meta (como «publicar» en el H5) |
| «Inicialmente solo lectura» en Meta | El Studio no necesita llamar a Graph: el espejo ya está | **Cero** llamadas a Graph desde el Studio, sin botón de sincronizar. La del panel de Mercadeo existe con `marketing.manage` y no se toca |
| Twilio: «consultar llamadas» | No hay Voice. `marketing_calls` no está vacía por diseño: `marketing:dispatch-followups` puede crear filas `pending` con el teléfono del prospecto en claro (`DispatchMarketingFollowups.php:122-142`), sin marcador que las llame | Historial local por recuentos y sin teléfonos (0 llamadas reales). Llamadas del proveedor solo con la sonda GET opcional |
| Twilio: «errores» | Ningún error de Twilio se guarda en la base: el webhook y el OTP escriben en el log | Errores del proveedor solo con la sonda (alertas de Monitor, en recuentos); sin ella, «solo en el log del servidor» |
| Twilio: «gestionar configuración autorizada» | Vive en `.env` con caché de configuración (`config/otp.php:140-150`), con dos pares de alias (`TWILIO_SID`/`TWILIO_ACCOUNT_SID`, `TWILIO_TOKEN`/`TWILIO_AUTH_TOKEN`) | Lectura enmascarada + deriva + reconocimiento auditado. **Nunca** edición desde el CRM (§I5.3) |
| «Conservar webhooks» | `TwilioUsageTriggerController`: firma HMAC-SHA1, idempotencia en caché, solo escribe en el log | No se toca. El tester añade el vector oficial de Twilio como fixture para el día que se extraiga el validador |
| Números canarios «administrados desde el CRM» | El canario es un id de conversación del entorno, sin auditoría. Lo leen el emisor (`UltronEventEmitter.php:84-87`), el commit (`UltronCommitService.php:291-303`) y el vigía (`UltronTurnWatchdog.php:166`). **Corrección al encargo:** `UltronController` no lo lee | Registro declarativo y coherencia de solo lectura. Que el motor lea el registro es una fase futura (T2) con su propio canario y autorización |
| «Asociar versiones» a un número | La cadena del H5 ya decide qué versión rige en la conversación canaria (parte `canary`) | La asociación es una **expectativa** que se compara con la cadena. No gobierna nada |
| «Preservar el número canario actual» | — | H6 no toca el entorno ni el motor. En la fase 2, T1 registra el teléfono del canario actual (lo hace quien lo tiene), lo autoriza otra persona, y el Studio comprueba `in_sync` |
| ElevenLabs: «preparar adaptador…» | Cero código y cero credenciales | Puerto, proveedor nulo, adaptador tras bandera, `doctor`, firma y procedimiento. **Ningún código que pueda iniciar una llamada** |

**Valor real del hito.** Por primera vez el sistema dice con evidencia qué integración está conectada y cuál no, en vivo y sin fingir. Existe el concepto «campaña autorizada para ULTRON» con historial. Los números de prueba tienen gobierno: E.164, cuatro ojos, consentimiento, auditoría y minimización. Hay una prueba medible de que el registro y el canario real coinciden, que es la condición para cualquier transición. La configuración tiene trazabilidad sin exponer secretos. Y la pieza de voz queda preparada y blindada para el día en que se autorice.

**Lo que H6 no puede dar, y conviene que el usuario lo sepa:** que ULTRON atienda por número en vez de por conversación, que haga llamadas o que use ElevenLabs. Las tres cosas exigen tocar el motor o gastar dinero, y el encargo lo prohíbe en este hito.

---

## 2. Inventario verificado por área

### 2.0 Clasificación (lo que el Studio afirmará, y con qué evidencia)

| Área | Clasificación | Evidencia en el código | Evidencia en producción |
|---|---|---|---|
| **A · Meta Ads** | **conectada en lectura** | Cliente solo GET (`MetaAdsApiClient.php:559-593`, un único `Http::withToken(...)->get(...)` en `:568-571`). Sincronización horaria solo si `META_ADS_SYNC_ENABLED` (`routes/console.php:377-383`). El comando `marketing:meta-ads-check` falla si el token trae algún permiso distinto de `ads_read`/`public_profile` (`MetaAdsCheckCommand.php:46-51`) | **[lead]** existen `META_ADS_ACCESS_TOKEN`, `META_ADS_GRAPH_VERSION` y `META_ADS_SYNC_ENABLED`. **[memoria, 07-10]** `META_AD_ACCOUNT_IDS` con 3 cuentas, pasada horaria encendida desde el 07-10 03:35 UTC, conciliación exacta y una cuarta cuenta (…0062) que el token no ve. El Studio lo confirmará en vivo con `meta_sync_runs` |
| **B · Twilio** | **conectada solo para OTP** | Verify por SMS (`TwilioVerifyService.php:22-28`, `:90-110`). Messages solo para el código de vínculo socio↔personal (`TwilioSmsSender.php:67-70`; `config/otp.php:160-177`). Sin Voice, TwiML ni ruta de voz (grep vacío en `app`, `config` y `routes`) | **[lead]** existen `TWILIO_ACCOUNT_SID/AUTH_TOKEN/VERIFY_SERVICE_SID/FROM/BASE/VERIFY_BASE/VERIFY_RATE_LIMIT_NAME` y `TWILIO_SID/TOKEN`; no hay Twilio Voice. `OTP_DRIVER` no se listó: **[suposición]** vale `twilio` |
| **B' · Twilio Voice** | **no configurada** | Ninguna bandera, número, webhook ni código | — |
| **C · Números canarios** | **no configurada** como registro (hoy el canario es un id de conversación del entorno, que sí está configurado) | `config/marketing.php:83-86`; ningún registro por número; ninguna pantalla | **[lead + informe del 31-10]** canario configurado. **[memoria]** conversación 273, la misma que el canario del cobro |
| **D · ElevenLabs** | **no configurada** (`ELEVENLABS_NOT_CONFIGURED`) | Ninguna referencia en el código de los dos repos. `grep -ril elevenlabs` solo encuentra documentos del Studio | **[lead + informe del 31-10]** nada de ElevenLabs en el entorno |

### 2.1 A · Meta Ads (verificado)

- **Configuración** (`config/meta.php:144-196`, bloque `meta.ads`): `access_token` (`META_ADS_ACCESS_TOKEN`); `ad_account_ids` (`META_AD_ACCOUNT_IDS`, con respaldo `META_AD_ACCOUNT_ID`, solo dígitos y sin duplicados, regla en `MetaAdsApiClient::parseAccountIds`); `graph_version` (`META_ADS_GRAPH_VERSION`, si no `META_GRAPH_VERSION`, si no `v21.0`); `sync_enabled` (apagada por defecto); `sync_days` (7); `stale_after_minutes` (180); `timezone`. **La URL de Graph es fija** (`config/meta.php:110`, `graph_base`): no viene del entorno.
- **Cliente** (`app/Services/Marketing/Meta/MetaAdsApiClient.php`): token en cabecera, nunca en la URL (`:24-26`). Pagina con el cursor `after` y nunca pide `paging.next` (`:488-527`). Un fallo es una excepción clasificada, nunca una lista vacía (`:30-32`). `sanitize()` quita tokens y enmascara ids largos (`:453-478`). Los bordes `campaigns()`/`adsets()`/`ads()` piden `id,name,effective_status` (`:403-444`). `configurationProblem()` mira solo la configuración, sin red (`:114-130`).
- **Sincronización** (`MetaAdsSync.php`, 1471 líneas): cerrojo por cuenta `meta-ads-sync:{cuenta}` (`:41`, `:120`). La dimensión de entidades sale de las filas de gasto (`upsertEntities`, `:1105-1147`). Los estados de campaña se aplican solo a las que ya están en la dimensión (`:1141-1146`) y actualizan `effective_status` y `synced_at` (`applyStatuses`, `:1220-1244`). `status()` y `statusAll()` (`:312-464`) son **lecturas** y devuelven `not_configured | running | failed | stale | ok`, con «vieja» a los 180 min y el detalle por cuenta enmascarado.
- **Tablas:** `meta_ad_entities` (`2026_10_05_100002`: clave única `(ad_account_id, level, entity_id)`, `effective_status`, `synced_at`, sin marcas de tiempo propias), `meta_ad_insights_daily` (`2026_10_05_100001`), `meta_sync_runs` (`2026_10_05_100003`: `status`, `error_code`, `error_message` saneado) y `meta_ad_reach_snapshots` (`2026_10_06_100001`).
- **Modelo:** `MetaAdEntity::scopeForConfiguredAccount()` lee las cuentas de la configuración, sin red (`MetaAdEntity.php:65-72`). `opaqueId()` es un HMAC con la `APP_KEY` (`:82-85`) y `publicAccount()` enmascara la cuenta (`:94-101`). Es la forma pública de una entidad que ya usa el panel.
- **Panel de Mercadeo:** `admin/marketing/meta-dashboard` (`routes/marketing.php:211-228`) con `marketing.view`; pedir la sincronización exige `marketing.manage`. El dinero solo sale con visión completa (`MetaDashboardController.php:198-203`). CRM: `src/app/modules/components/marketing-digital-panel.ts`.
- **Atribución:** `marketing_lead_attributions` (`2026_08_07_000001`) guarda el `referral` de Click-to-WhatsApp: `ad_id`, `click_id` (= `ctwa_clid`) y primer y último toque. Los ids de campaña quedan nulos porque WhatsApp no los entrega (cabecera de la migración, líneas 27-33). La campaña se resuelve con `meta_ad_entities`.
- **Huecos:** no existe «campaña autorizada para ULTRON» ni la tabla de vínculo. Las campañas sin gasto no están en el espejo. `.env.example` solo documenta `META_AD_ACCOUNT_ID` (`:463`) y le faltan `META_ADS_*` y `META_AD_ACCOUNT_IDS`.

### 2.2 B · Twilio (verificado)

- **Configuración, en `config/otp.php` y no en `services.php`** (`:140-150`): `sid` = `TWILIO_SID` ?? `TWILIO_ACCOUNT_SID`; `token` = `TWILIO_TOKEN` ?? `TWILIO_AUTH_TOKEN`; `from`; `base` (`TWILIO_BASE`, por defecto `https://api.twilio.com`); `verify_service_sid`; `verify_base`; `rate_limit_unique_name`. Interruptor `TWILIO_SEND_ENABLED` (`:186`). Techos de coste blando y duro, 6 y 12 USD/día (`:241-246`). Emisor heredado cerrado (`:160-177`). Límites por teléfono, cuenta e IP (`:197-224`). **Consecuencia de los alias:** si en producción `TWILIO_SID` ≠ `TWILIO_ACCOUNT_SID`, la credencial efectiva es la primera, y la consola de Twilio podría estar diciendo otra cosa. El panel lo detecta comparando huellas, nunca valores (§I5.2).
- **Verify** (`app/Services/Sms/TwilioVerifyService.php`): sin SDK; `Http::asForm()->post()` a `{verify_base}/v2/Services/{sid}/Verifications|VerificationCheck` (`:90-110`). `toE164()` es permisivo, solo antepone el país (`:126-145`): **no sirve** para validar canarios.
- **Coste** (`app/Services/Otp/OtpCostGuard.php`): contadores en la caché compartida, `database` en producción según su comentario (`:13-15`). `snapshot()` es una lectura pura: fecha, gasto estimado de hoy, techos e interruptor (`:80-91`).
- **Webhook `usage-trigger`** (`routes/api.php:631-636`, throttle 60/min → `TwilioUsageTriggerController.php`): firma `X-Twilio-Signature` HMAC-SHA1 sobre `fullUrl()` más los parámetros POST en orden (`:66-86`), comparación en tiempo constante; sin token falla cerrado (`:71-73`). Idempotencia con `Cache::add` por `IdempotencyToken` durante 24 h (`:41-44`). **Solo escribe en el log** (`:46-54`). Pruebas: `tests/Feature/Otp/TwilioUsageTriggerWebhookTest.php` (7). Sin `trustProxies` en `bootstrap/app.php` (grep vacío): el hallazgo 12 de la auditoría sigue en pie **[no verificado en el servidor]**.
- **`marketing_calls`** (`2026_06_27_000002`): `to_phone` en claro, `transcript`, `summary`, `metadata`. **Corrección al encargo («sin uso»):** `marketing:dispatch-followups` crea filas `pending` con `to_phone` = teléfono del prospecto (`DispatchMarketingFollowups.php:122-142`) cuando sus banderas lo permiten (`:41-45`); solo se agenda con `marketing.followups.scheduler_enabled` (`routes/console.php:244`). **No existe marcador.** Filas en producción: **[suposición]** 0.
- **Evidencia local del OTP:** `member_auth_challenges` y `trainer_auth_challenges` (estados `pending|verified|completed|expired|blocked`, `MemberAuthChallenge.php:15-23`). Un reto verificado recientemente prueba que Verify funciona, sin preguntarle a Twilio.

### 2.3 C · Canario (verificado)

- `MARKETING_ULTRON_CANARY_CONVERSATION_ID` → `int|null` (`config/marketing.php:83-86`). `null` = sin restricción.
- **Lectores:** emisor `UltronEventEmitter.php:84-87` (`not_canary_conversation`, antes de crear el evento); commit `UltronCommitService.php:291-303` (403, lanza, sin fila); vigía `UltronTurnWatchdog.php:166` (solo frena en el canario); `MarketingAgentSwitch.php:229` (alcance de la pausa); `MarketingAiDoctor.php:77`; el lector del H5 `UltronPublishedConfig.php:221` (`canaryConfigured`) y `:393` (parte). Del Studio: `StudioCanaryMeasures.php:94` y `StudioEngineReadings.php:166`, que ya **leen** esa clave: es la dirección permitida.
- **Canario del cobro**, independiente: `config/marketing.php:49`. Fuera de H6.
- En el H5, `governance.canaryOnly` es `must_match` y su lectura es «canario configurado». H6 no lo cambia.

### 2.4 D · ElevenLabs y voz (verificado)

- Sin código, sin credenciales y sin dependencias (`composer.json` no trae SDK de Twilio, ElevenLabs ni libphonenumber).
- Documentación oficial consultada hoy: cabecera `ElevenLabs-Signature: t=<unix>,v0=<hex>`, con `v0` = HMAC-SHA256 en hexadecimal de `"{t}.{cuerpo crudo}"`. El ejemplo oficial tolera 30 min y puede haber varios `v0`. `POST /v1/convai/twilio/register-call` devuelve TwiML y exige el agente en μ-law 8000. Hay **URL base por región** (lista cerrada en la documentación). Fuentes al final.

### 2.5 Studio y CRM: lo que condiciona el diseño (verificado)

- **Rutas:** 38 en la rama (`routes/ultron-studio.php`), todas bajo `admin/ultron-studio`, con tope de cuerpo de 64 KB (`:73`) y cupos `ultron-studio-read` (120/min) y `-write` (30/min) en `AppServiceProvider::limitadoresDelStudio()` (`:585`, `:604-612`). En producción hay 33.
- **Frontera:** `UltronEngineIsolationTest` rastrea por tokens `app`, `routes`, `config`, `database` y `bootstrap` (`:82`). Fuera de la lista blanca (`:41-58`) nadie puede nombrar `UltronStudio`, `ultron_studio_` ni `config('ultron_studio…')`. Las zonas del motor (`:85`) son `app/Services/Marketing`, `app/Jobs`, `Api/Internal`, `routes/marketing.php`, `routes/api.php` y `config/marketing.php`. Toda ruta atendida por un controlador del Studio cuelga de `api/admin/ultron-studio/` y se cuentan (38, `:213`). `UltronStudioEngineReadOnlyTest` fija los ficheros del Studio que pueden nombrar clases «del motor» (`:96`). Su patrón incluye `App\Services\Marketing\*` y `App\Models\Marketing*` (`:671`): `MetaAdsSync`, `MarketingCall`, `MarketingConversation` y `LeadSourceClassifier` cuentan como motor. `MetaAdEntity`, `OtpCostGuard` y `MemberAuthChallenge`, no.
- **El catálogo no puede cambiar:** `StudioCatalog::VERSION = '2026-10-19.1'` (`StudioCatalog.php:37`) lo fijan `StudioCatalogTest.php:45`, `publication-cases.json` (91 apariciones), `coherence-cases.json:3` y `UltronPublishedConfigTest.php:69`. Precedente: D-5 del H5 dejó su lectura nueva **fuera** del catálogo.
- **Libro:** `StudioAudit` (`StudioAudit.php:54-78` eventos; `:92-122` denegados con cerrojo en `READ COMMITTED`; `:132-168` escritura). Valores de hasta 120 caracteres, IP y agente de usuario siempre NULL. Sujetos `draft|version|rule|system` (`StudioAuditEvent.php:41-49`), con CHECK `usae_subject_type_chk` en PG (`2026_10_18_100002…:129`) y `subject_type varchar(16)` (`2026_10_18_100001…:166`). El evento tiene que casar con `^[a-z_]+\.[a-z_]+$` y medir como mucho 48 caracteres (`UltronStudioController.php:138`).
- **Permisos:** `ultron.*` en `PermissionCatalog.php:149-156` (etiquetas) y `:211-218` (ayuda). El Administrador recibe todo salvo una lista (`CrmPermission.php:255-302`: `integrations.manage`, `audit.view`, `ultron.publish`, `ultron.rollback`, `ultron.lab_run`…). El token compartido no recibe nada de `ultron` (`:237`). Una llave solo existe si una ruta real la exige (regla de admisión de `PermissionCatalog`). Cuatro ojos por identidad: `StudioAccess::isCreator()`.
- **Patrones a calcar:** URL del proveedor fija en `production` (`StudioLabOpenAiClient.php:45`, `:201-209`); booleanos con `filter_var(…, FILTER_VALIDATE_BOOLEAN)` (M-1 del H5, `config/ultron_studio.php:65`); tablas de solo inserción con disparadores y CHECK, y columnas inmutables con `UPDATE OF` (`2026_10_18_100002…:75-110`); `down()` que se niega con filas (`2026_10_25_100002…:300-311`).
- **CRM:** ruta tras `environment.ultronStudioPreviewEnabled` y `ULTRON_VIEW` (`app.routes.ts:237-253`). El laboratorio es una **página hija** con su sesión, su servicio y sus hojas (`studio-lab-page.ts`). `studio-inspector.scss` ocupa **8 012 B** (no cabe nada más) y `ultron-studio.scss` 32 897 B (no se toca nunca). Diálogos compartidos: `src/app/shared/components/ib-dialog/ib-dialog.ts` y `reason-dialog/reason-dialog.ts`. El aviso de datos personales `AVISO_DATOS_MOTIVO` está en `studio-data-notice.ts`. El catálogo de permisos lo genera `php artisan permissions:export` (`backend-permission-catalog.ts:3`).
- **Arnés E2E:** `Iron-Body_Front/e2e/ultron-studio/run.sh`: PG 14 en :55432, serve en :8092, simuladores en :8093/:8094 y valla en :8099. `fence.cjs` rechaza toda salida y anota solo el host; el cierre exige el registro vacío.

---

## 3. Impacto (con las opciones recomendadas del §4)

### 3.1 Archivos a tocar (cada uno con UN dueño; ver §7)

**Esquema (paquete DB, `database-specialist`):**
- NUEVA `‹B›/database/migrations/2026_11_XX_100001_create_ultron_studio_integration_tables.php`: `ultron_studio_campaign_links`, `ultron_studio_canary_numbers` y `ultron_studio_integration_snapshots` (§I3.1, §I4.1, §I5.4).
- NUEVA `‹B›/database/migrations/2026_11_XX_100002_add_ultron_studio_integration_guards.php`: disparadores (solo inserción o columnas inmutables, `DELETE` y `TRUNCATE`), CHECK de dominio y entre columnas (PG), índices únicos parciales en los dos motores y **ampliación** de `usae_subject_type_chk` (DROP + ADD en una sola sentencia) con `campaign_link`, `canary_number` e `integration`.
- Su prueba de migración (recuentos, `WHERE` de los parciales en los dos motores, `down()` que se niega).

**Voz (paquete V, `backend`; espacio neutro, sin ficheros del Studio ni del motor):**
- NUEVOS en `‹B›/app/Services/Voice/`: `VoiceAgentProvider.php` (puerto), `NullVoiceProvider.php`, `VoiceProviders.php` (fábrica, sin tocar `bootstrap/` ni `AppServiceProvider`), `VoiceProviderStatus.php` y `VoiceCheckResult.php` (valores), `VoiceCallPolicy.php` (tabla de decisión pura, con `CALLS_ENABLED_IN_CODE = false`), `ElevenLabs/ElevenLabsConfig.php` (lee `config('voice.elevenlabs')`, regiones cerradas, piezas que faltan; nunca devuelve un secreto), `ElevenLabs/ElevenLabsVoiceProvider.php` (estado y `verifyCredentials()` por GET) y `ElevenLabs/ElevenLabsWebhookSignature.php` (puro).
- NUEVO `‹B›/config/voice.php` (ElevenLabs y Twilio Voice, todo apagado).
- NUEVO `‹B›/app/Console/Commands/Voice/VoiceDoctorCommand.php` (`voice:doctor [--json] [--verify]`).
- Pruebas: `tests/Unit/Voice/**` y `tests/Feature/Voice/VoiceDoctorCommandTest.php`.

**Lectura de Twilio (paquete TW, `backend`; espacio neutro):**
- NUEVOS `‹B›/app/Services/Twilio/TwilioReadOnlyClient.php` (solo GET, cinco rutas fijas y hosts oficiales en `production`) y `TwilioProbeSanitizer.php` (puro: de la respuesta cruda a recuentos sin teléfonos ni textos).
- Pruebas: `tests/Unit/Twilio/**`.

**Studio (paquete S, `backend`; después de DB, consumiendo las interfaces de V y TW):**
- NUEVOS en `‹S›/`: `StudioIntegrations.php` (estado y clasificación comunes), `StudioMetaCampaigns.php` (lecturas del espejo de Meta), `StudioCampaignLinks.php` (asociaciones), `StudioCanaryNumbers.php` (registro y máquina de estados), `StudioCanaryPhone.php` (E.164 estricto y reglas por canal; puro), `StudioCanaryCoherence.php` (lectura del canario del motor), `StudioTwilioPanel.php` (lecturas locales de Twilio y `marketing_calls`), `StudioTwilioProbe.php` (sonda con `TwilioReadOnlyClient`), `StudioIntegrationConfig.php` (huellas, deriva y reconocimiento) y `StudioVoicePanel.php` (lee el `doctor` y la política de voz).
- NUEVO `‹B›/app/Support/Privacy/PhoneFingerprint.php`: HMAC del E.164, neutro, sin nombrar el Studio, para que un lector futuro del motor (T2) pueda usar **la misma** implementación. Mismo criterio que la firma única del H5.
- Modelos NUEVOS: `app/Models/UltronStudio/StudioCampaignLink.php`, `StudioCanaryNumber.php` y `StudioIntegrationSnapshot.php`. Cambia `StudioAuditEvent.php` (`SUBJECT_TYPES` + 3).
- Controladores NUEVOS en `app/Http/Controllers/Crm/UltronStudio/`: `UltronStudioIntegrationController.php` (status, twilio, twilio/calls, voice, config, acknowledge y probe), `UltronStudioCampaignLinkController.php` (campañas, historial, crear y desactivar) y `UltronStudioCanaryController.php` (lista y seis transiciones).
- Cambian: `routes/ultron-studio.php` (+18 → 56), `app/Support/Access/AuthorizationMap.php` (+18), `PermissionCatalog.php` (+4 llaves con etiqueta y ayuda), `CrmPermission.php` (dos exclusiones nuevas del Administrador), `‹S›/StudioAccess.php` (constantes y capacidades del hito), `‹S›/StudioAudit.php` (eventos), `config/ultron_studio.php` (bloque `integrations`), `app/Exceptions/UltronStudioException.php` (códigos), `app/Providers/AppServiceProvider.php` (**solo** `limitadoresDelStudio()`: cupo `ultron-studio-probe`), `app/Console/Commands/UltronStudio/ExportStudioFixturesCommand.php` (un fixture más) y `.env.example`.
- Comandos NUEVOS: `app/Console/Commands/UltronStudio/IntegrationsDoctorCommand.php` (`ultron-studio:integrations-doctor [--json] [--expect=…]`) e `IntegrationsSnapshotCommand.php` (`ultron-studio:integrations-snapshot [--verify-voice]`).
- Pruebas: `tests/Feature/UltronStudio/UltronStudioIntegration{Status,MetaLinks,Canary,CanaryCoherence,Twilio,Probe,Config,Voice,Access}Test.php` y `tests/Unit/UltronStudio/Studio{CanaryPhone,ConfigFingerprint,MetaCampaigns,IntegrationsParity}Test.php`. Pasan a ser suyas, porque en H6 no hay paquete del motor: `UltronEngineIsolationTest.php` (lista blanca + 2 migraciones, 38 → 56 rutas, aserciones sobre `Voice` y `Twilio`) y `UltronStudioEngineReadOnlyTest.php` (`ENGINE_READERS` con los ficheros que nombren el motor según §I9.2, `STUDIO_TABLES` + 3, recorrido de 56 rutas sin red).

**Fixtures y adversarial (paquete T, `tester`):** NUEVO `‹B›/tests/fixtures/ultron-studio/integrations-cases.json` + `.README.md` (escritos a mano desde el contrato; derivación independiente con `openssl`), pruebas `*Race*` y `*Mutation*` del hito, y el arnés: `Iron-Body_Front/e2e/ultron-studio/{mock-twilio.cjs, mock-elevenlabs.cjs, specs/h6-integrations.cjs, specs/h6-seed.php}` más los ajustes de `run.sh`.

**CRM (paquete FE, `frontend`), todo en `‹F›/` salvo lo indicado:** NUEVOS `studio-integrations-page.{ts,scss,spec.ts}`, `studio-integrations-session.ts`, `studio-integrations-api.{service,types}.ts`, `studio-integrations-parse.ts`, `studio-integrations-text.ts`, `studio-integrations-kit.scss`, `studio-integrations-status.*`, `studio-meta-campaigns.*`, `studio-campaign-link-dialog.*`, `studio-canary-numbers.*`, `studio-canary-register-dialog.*`, `studio-twilio-panel.*`, `studio-voice-panel.*` y `studio-config-drift.*`. Cambian: `ultron-studio-page.ts` (solo el enlace «Integraciones» de la barra), `studio-audit-panel.ts` (etiquetas de los tres sujetos nuevos), `testing/backend-integrations.testing.ts` (regenerado), `src/app/app.routes.ts` (+1 ruta tras la misma bandera), `src/app/models/permissions.enum.ts` (+4) y `backend-permission-catalog.ts` (regenerado). **Nunca** `ultron-studio.scss` ni `studio-inspector.scss`.

**Operación y documentos:** `devops` → NUEVOS `‹B›/docs/ultron-studio/ops/{deploy-h6-backend-fase1.sh, h6-integrations-scope.sh, smoke-h6.py, smoke-run-h6.sh, h6-schema-check.sql}`. `documentation-specialist` → contrato H6 y los informes (`DEPLOYMENT_PLAN`, `ROLLBACK`, `MASTER_ROADMAP`, `META_REPORT`, `TWILIO_REPORT`, `ELEVENLABS_READY` con el procedimiento de activación, `FINAL`, `BACKEND`, `SECURITY`, `VISUAL`, `ops/README.md`).

**Lo que NO se toca (puerta de diff en §8):** `app/Services/Marketing/**`, `app/Jobs/**`, `app/Http/Controllers/Api/**` (incluidos `Internal` y `TwilioUsageTriggerController`), `app/Services/Sms/**`, `app/Services/Otp/**`, `routes/api.php`, `routes/marketing.php`, `routes/console.php`, `config/marketing.php`, `config/meta.php`, `config/otp.php`, `‹S›/StudioCatalog.php` y los ficheros del H5 que no sean los de permisos y rutas.

### 3.2 Contratos que cambian

| Contrato | Cambio | Compatibilidad |
|---|---|---|
| API del Studio | +18 rutas bajo `admin/ultron-studio/integrations/*` (§I7). `GET status` **no cambia**: el hito tiene su propio `GET integrations/status` con su bloque `can` | El CRM actual no las llama. La página nueva tolera un backend H5 (404 → «El servidor todavía no tiene integraciones»). El backend puede ir antes que el CRM |
| Libro (`ultron_studio_audit_events`) | 3 sujetos nuevos y sus eventos (§I8) | Aditivo. El panel de auditoría del CRM pinta el sujeto desconocido en genérico y FE le pone etiqueta |
| Esquema | 3 tablas nuevas + CHECK ampliado | Aditivo. `down()` se niega con filas del H6 |
| Permisos | +4 llaves `ultron.*` | Catálogo del CRM regenerado; el token compartido no las recibe (dominio `ultron`) |
| Configuración | `config/voice.php` nuevo y bloque `ultron_studio.integrations` | Todo apagado por defecto (§I12) |
| Motor ↔ Studio | **Ninguno** | `git diff` del motor vacío |
| n8n, Meta, Twilio, ElevenLabs | **Ninguno** (la sonda GET de Twilio, solo si se enciende) | — |
| Fixtures del CRM | +1 fichero exportado (`backend-integrations.testing.ts`) | `ultron-studio:export-fixtures` pasa de 5 a 6 ficheros |

### 3.3 Consumidores afectados

- **Dentro del repo:** el CRM del Studio (página nueva, enlace y auditoría). Nada más: el panel de Mercadeo, el OTP, el webhook de Twilio y la sincronización de Meta siguen idénticos.
- **Fuera del repo:** ninguno. n8n no ve nada, la app móvil no lee estas tablas e IRON GUARD no recibe incidentes. Twilio solo recibe GET si el propietario enciende la sonda.

### 3.4 Migración de datos

**No hay transformación.** Son tablas nuevas y vacías. El CHECK ampliado del libro valida las filas actuales, que ya cumplen los 4 sujetos de hoy. El `ALTER TABLE … DROP/ADD CONSTRAINT` toma ACCESS EXCLUSIVE sobre `ultron_studio_audit_events` (tabla pequeña) y lo acota el `lock_timeout` de 5 s de `ironbody-migrate`. Las FK hacia `ultron_studio_versions` nacen con sus tablas y solo la **referencian**: ninguna migración hace `Schema::table` sobre versiones (memoria «SQLite reconstruye y pierde el WHERE»).

---

## 4. Opciones y recomendación

### 4.1 Números canarios frente al canario del motor

| | A · Registro declarativo + coherencia de solo lectura (recomendada) | B · El motor lee el registro ya (restricción adicional) | C · Sustituir el id de conversación por el número |
|---|---|---|---|
| Ficheros del motor | Ninguno | Emisor (y un lector en lista blanca) | Emisor, commit, vigía y acta |
| Riesgo | Mínimo: nadie lee el registro | Medio: un fallo de lectura enmudece el canario | Alto: un registro mal cargado abre ULTRON a quien no debe |
| ¿Cabe en H6? | Sí | **No**: «no tocar el motor» | **No** |
| Valor | Gobierno, minimización y la prueba `in_sync`, que es la condición de B y C | Defensa en profundidad | Canario por número |

**→ A.** B y C quedan escritas como T2 y T3 (§I4.6) para un hito con autorización expresa.

### 4.2 Qué es «asociar una versión» a un número

| | A · Expectativa auditada comparada con la cadena (recomendada) | B · Derivada (no se guarda) | C · Puerta de publicación |
|---|---|---|---|
| Qué guarda | `expected_version_id` (opcional), con su evento en el libro | Nada | La versión esperada, que además condiciona `publish` |
| Contradicción posible | No: la autoridad es una sola (la cadena); la expectativa se compara y se enseña `match/mismatch` | No | No, pero acopla H5 ↔ H6 y toca `StudioPublications` |
| Cumple «asociar versiones» | Sí | No | Sí |

**→ A.**

### 4.3 Estado del proveedor Twilio

| | B1 · Solo lectura local | B2 · Local + sonda GET opcional y apagada (recomendada) | B3 · Sonda programada |
|---|---|---|---|
| Qué responde | Configuración, OTP, coste, `marketing_calls` | Además: estado y tipo de la cuenta, números y si admiten voz, caller IDs, llamadas de 7 días y alertas de 24 h, en recuentos | Lo mismo, cada N minutos |
| Red | Ninguna | Hasta 5 GET por pulsación, con enfriamiento de 5 min | Continua |
| Contesta la pregunta abierta del 17-10 («¿hay número de voz o solo caller ID?») | No | **Sí**, sin llamar a nadie | Sí |
| Superficie | Ninguna nueva | Un cliente GET con la credencial maestra (o una API key restringida si el propietario la crea) | Más; es monitoreo (Hito 7) |

**→ B2**, con la bandera apagada en la fase 1 y encendida solo con autorización del propietario (pregunta 1). Si el lead prefiere B1, se quitan del plan el paquete TW, una ruta y una tabla de `kind`, y el resto no cambia.

### 4.4 «Gestionar la configuración autorizada» de Twilio (y de las demás)

| | A · Huellas + deriva + reconocimiento (recomendada) | B · Solo lectura enmascarada | C · Edición desde el CRM |
|---|---|---|---|
| Secretos | Nunca salen: huella HMAC de 8 hex | Nunca salen | En la base y en el navegador |
| Registro de cambios | Sí: instantáneas de solo inserción (de sistema al desplegar y de persona al reconocer) | No | Sí |
| Riesgo | Bajo | Ninguno | Alto: SSRF (`TWILIO_BASE`/`VERIFY_BASE` son URL), caché de configuración, workers y el OTP, que es el camino crítico del login de socios |

**→ A.** Justificación de no editar: §I5.3.

### 4.5 Dónde vive en el CRM

| | A · Página hija `marketing/ultron-studio/integraciones` (recomendada) | B · Cuarto panel lateral del tablero | C · Pestaña del inspector de la capa «Integraciones» |
|---|---|---|---|
| Chunk | Propio y diferido, como el laboratorio | Engorda el del tablero | Engorda el del tablero |
| Espacio a 390 px | Página entera | Panel estrecho | Inspector estrecho |
| Hojas | Propias (< 8 kB cada una) | Riesgo de tocar `ultron-studio.scss` | `studio-inspector.scss` ya ocupa 8 012 B |

**→ A**, con un enlace «Integraciones» junto a «Laboratorio» en la barra del tablero.

### 4.6 Webhook post-llamada de ElevenLabs

**→ Solo el verificador puro y sus pruebas en H6. La ruta pública llega con el hito de activación**, que necesitará de todos modos código nuevo (crear la llamada, el TwiML) y un despliegue. Registrar ahora una ruta que conteste 404 con la bandera apagada añade superficie pública sin ningún valor.

---

## 5. Diseño a congelar (I1–I13)

### I1. Principios del hito (cada uno con su prueba)

| # | Principio | Cómo se comprueba |
|---|---|---|
| P1 | **Cero motor.** Ninguna línea en `‹U›`, `app/Jobs`, `Api/Internal`, `config/marketing.php` ni `routes/marketing.php`, y ninguna en las integraciones existentes (`app/Services/Marketing/Meta`, `app/Services/Sms`, `app/Services/Otp`, `TwilioUsageTriggerController`, `routes/api.php`, `config/meta.php`, `config/otp.php`) | Puerta de `git diff --stat` (§8). `UltronEngineIsolationTest` con las zonas del motor limpias |
| P2 | **Cero red por defecto.** Con las llaves por defecto, ninguna ruta del hito hace una petición HTTP saliente. Lo único que puede salir es la sonda GET de Twilio, con su bandera, y la verificación GET de ElevenLabs por consola (`voice:doctor --verify` o `ultron-studio:integrations-snapshot --verify-voice`) en el procedimiento de activación | `Http::preventStrayRequests()` recorriendo las 18 rutas. Valla `fence.cjs` vacía en el E2E |
| P3 | **Espejo local, solo lectura.** El Studio lee `meta_ad_entities`, `meta_sync_runs`, los retos del OTP, `marketing_calls` y la configuración; no los escribe | `UltronStudioEngineReadOnlyTest` (estática y escuchando cada sentencia) |
| P4 | **Nada cambia lo que ULTRON dice o hace.** Asociaciones, números canarios y configuración autorizada son registro | Texto obligatorio en el CRM y en la ayuda de cada permiso. Las suites de ULTRON idénticas |
| P5 | **Sin secretos ni teléfonos en claro** en DTOs, libro, logs ni cliente | Prueba **centinela**: se ponen valores reconocibles en cada secreto y teléfono del entorno y de la semilla, y no aparecen en ninguna respuesta, fila del libro ni línea de log del hito |
| P6 | **Aditivo y sin borrar.** Tablas nuevas `ultron_studio_*` con guardas en la base y `down()` que se niega con filas | Prueba de migración en SQLite y PG |
| P7 | **Sin dependencias nuevas** (ni composer ni npm) | `composer.lock` y `package-lock.json` sin cambios |
| P8 | **Catálogo congelado** (`2026-10-19.1`) | `StudioCatalogTest` sin huella nueva |

### I2. Estado común de las integraciones (`GET integrations/status`)

Lo calcula `StudioIntegrations`, **sin red**. Cada bloque va por su cuenta, como `StudioEngineReadings::reading()`: si su fuente falla, sale `{available:false}` y una línea de log con la clase del error, nunca un valor inventado.

```jsonc
{
  "can":   { "campaignsManage": true, "canaryManage": true, "canaryAuthorize": false, "integrationsManage": false },
  "flags": { "writesEnabled": false, "twilioProbeEnabled": false },
  "providers": [
    { "id": "meta_ads", "classification": "connected_read", "state": "ok", "reason": null,
      "evidence": { "accounts": 3, "lastSuccessAt": "…", "minutesSinceSuccess": 12, "staleAfterMinutes": 180,
                    "coveredTo": "2026-10-31", "syncEnabled": true, "graphVersion": "v26.0" } },
    { "id": "twilio", "classification": "connected_otp_only", "state": "ok", "reason": null,
      "evidence": { "otpDriver": "twilio", "verifyActive": true, "sendEnabled": true,
                    "otpVerified": { "last24h": 14, "last7d": 96, "lastAt": "…" }, "estimatedSpentTodayUsd": 0.41 } },
    { "id": "twilio_voice", "classification": "not_configured", "evidence": { "enabled": false, "fromConfigured": false } },
    { "id": "canaries", "classification": "registry",
      "evidence": { "active": { "whatsapp": 0, "voice": 0 }, "pending": 0, "engineCoherence": "engine_canary_not_registered" } },
    { "id": "elevenlabs", "classification": "not_configured", "code": "ELEVENLABS_NOT_CONFIGURED",
      "evidence": { "enabled": false, "missing": ["api_key", "agent_id", "webhook_secret"] } }
  ],
  "note": "Las banderas son las del proceso web, con su caché de configuración."
}
```

**Clasificación (conjunto cerrado):** `connected_read` («Conectada en lectura»), `connected_otp_only` («Conectada solo para OTP»), `degraded` («Con problemas»), `disabled` («Apagada»), `not_configured` («No configurada») y `registry` (solo canarios).

- **Meta:** si `MetaAdsApiClient::configurationProblem()` devuelve algo, `not_configured` con su motivo (`missing_access_token`, `missing_ad_account_id`, `invalid_ad_account_id`, `invalid_timezone`). Si no, se mira `MetaAdsSync::statusAll()`: `ok` o `running` → `connected_read`; `stale` o `failed` → `degraded` con el motivo y el `last_error.code` saneado. Con `sync_enabled=false` y la configuración correcta → `degraded` con `sync_disabled`, porque el espejo envejece.
- **Twilio:** `otp.driver ≠ twilio` → `not_configured` (`otp_driver_not_twilio`); Verify incompleto (`TwilioVerifyService::isActive()` falso) → `not_configured` (`verify_incomplete`); interruptor bajado → `degraded` (`kill_switch`); techo duro alcanzado → `degraded` (`hard_limit_reached`); si no, `connected_otp_only`. Los recuentos de retos verificados (de socios y de entrenadores, en 24 h y 7 días) son **evidencia** y nunca degradan por sí solos: de noche no hay tráfico.
- **ElevenLabs:** el `doctor` del §I6.3.
- **Consola:** `ultron-studio:integrations-doctor [--json] [--expect=meta_ads:connected_read,twilio:connected_otp_only,elevenlabs:ELEVENLABS_NOT_CONFIGURED]` imprime lo mismo y sale con 1 si no coincide lo esperado. Lo usa el smoke.

### I3. Meta Ads: asociación campaña ↔ ULTRON

**I3.1 `ultron_studio_campaign_links`.** Una fila es **un periodo** de asociación: el historial son filas, nunca ediciones.

```
id                        bigint PK
uuid                      uuid UNIQUE
client_request_id         uuid UNIQUE                 -- idempotencia de la creación
ad_account_id             varchar(64)                 -- id de Meta sin act_, como meta_ad_entities
campaign_id               varchar(64)                 -- id de Meta de la campaña (nunca sale del servidor en claro)
campaign_name_at_link     varchar(255) NULL           -- instantánea con las series de dígitos enmascaradas (LeadSourceClassifier::maskDigits)
target_kind               varchar(10)                 -- baseline | version   (CHECK en PG)
version_id                FK NULL → ultron_studio_versions (RESTRICT)   -- NOT NULL si y solo si target_kind = version
version_content_hash      char(64) NULL               -- huella de la versión al asociar
status                    varchar(10)                 -- active | inactive   (CHECK en PG)
created_by_admin_id       bigint unsigned             -- instantánea SIN FK, como en F
created_by_name           varchar(120)
created_by_role           varchar(60) NULL
created_at                timestamp
deactivated_at            timestamp NULL
deactivated_by_admin_id   bigint unsigned NULL
deactivated_by_name       varchar(120) NULL
deactivated_by_role       varchar(60) NULL
deactivation_reason       varchar(500) NULL
replaced_by_link_id       bigint NULL (FK propia, RESTRICT)
INDEX uscl_campaign_idx (ad_account_id, campaign_id, id)
UNIQUE PARCIAL uscl_one_active (ad_account_id, campaign_id) WHERE status = 'active'   -- en los dos motores
```

**I3.2 Guardas (migración 2):** `uscl_forbid_delete` y `uscl_forbid_truncate` (este en PG); `uscl_forbid_immutable_update` (`UPDATE OF` todas las columnas salvo `status`, `deactivated_*` y `replaced_by_link_id`); `uscl_inactive_is_final` (`BEFORE UPDATE … WHEN OLD.status = 'inactive'`). CHECK en PG: `uscl_status_chk`, `uscl_target_kind_chk`, `uscl_target_chk` (`(target_kind = 'baseline' AND version_id IS NULL) OR (target_kind = 'version' AND version_id IS NOT NULL)`) y `uscl_deactivation_chk` (`status = 'active' OR (deactivated_at IS NOT NULL AND deactivation_reason IS NOT NULL)`). Todo con la función `ultron_studio_forbid()` de F (`CREATE OR REPLACE` con el mismo cuerpo byte a byte, como H5).

**I3.3 Lectura (`StudioMetaCampaigns`, sin red).** Lee `MetaAdEntity::forConfiguredAccount()->where('level', 'campaign')`, los nombres de cuenta con `MetaAdEntity::accountNames()`, el estado por cuenta con `MetaAdsSync::statusAll()` y las asociaciones de su tabla. Lista blanca de llamadas, vigilada por una prueba estática: `configurationProblem`, `accountIds`, `statusAll`, `forConfiguredAccount`, `accountNames`, `opaqueId`, `publicAccount` y `maskDigits`. **Nunca** `campaigns`, `adsets`, `ads`, `insights`, `reach`, `account`, `tokenInfo`, `adOwner`, `accountTotals`, `run`, `runAll`, `refreshReach` ni `refreshStatuses`.

```jsonc
// MetaCampaignDto (GET integrations/meta/campaigns)
{ "id": "campaign:1a2b3c4d5e6f7a8b", "ref": "***0387", "name": "Pauta octubre — ***4821",
  "account": { "id": "account:…", "ref": "***4714", "name": "IronBody2" },
  "meta":  { "effectiveStatus": "ACTIVE", "statusKnownAt": "…", "ageMinutes": 42, "stale": false, "possiblyDeleted": false },
  "ultron": { "link": { "uuid": "…", "status": "active", "target": { "kind": "version", "versionUuid": "…", "label": "…", "statusLabel": "En canario" },
                        "since": "…", "by": { "name": "…", "role": "…" } } | null } }
// Respuesta: { "connection": { "state": "ok|stale|failed|running|not_configured", "reason": null, "lastSuccessAt": "…" },
//              "data": [MetaCampaignDto…], "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 12 } }
```

- `possiblyDeleted` = `synced_at` anterior al **inicio** de la última pasada con éxito de su cuenta. El borde de campañas no devuelve las eliminadas (`MetaAdsApiClient.php:64-71`), así que una campaña que dejó de refrescarse probablemente lo está. Su estado es «el último conocido».
- `stale` = su cuenta está `stale` o `failed` en `statusAll()`.
- Filtros: `status` (lista cerrada de `effective_status`), `linked=yes|no`, `account=<id opaco>`, `page` y `per_page` (≤ 50, convenciones de AS-8 del H5).
- **La campaña se identifica por su id opaco** (`MetaAdEntity::opaqueId('campaign', …)`, el mismo que usa el panel): el id de Meta no sale del servidor. Para resolverlo, el servidor recorre las campañas del espejo de las cuentas conectadas (decenas). Si rota la `APP_KEY` cambian los ids opacos, no las filas, que guardan el id real.

**I3.4 Escritura.**

`POST integrations/meta/links` `{clientRequestId, campaign, target:{kind:'baseline'|'version', versionUuid?}, replaces?}`, en este orden:

1. Persona y `ultron.view` → 403; `ultron.campaigns_manage` → 403.
2. Bandera de escrituras apagada → 409 `integrations_writes_disabled`.
3. Reintento: la misma `clientRequestId` con la misma persona y el mismo cuerpo → 200 `replayed`; si no → 409 `client_request_reused`.
4. Meta sin configurar → 409 `meta_not_configured` con su `reason`.
5. Campaña no resuelta entre las cuentas conectadas → 404 `campaign_not_found`.
6. Destino: versión inexistente → 404 `version_not_found`; estado fuera de `APPROVED|CANARY|PUBLISHED` → 409 `version_not_linkable` con `currentStatus`; huella → 409 `integrity_mismatch`.
7. Ya hay una asociación activa: sin `replaces` → 409 `campaign_already_linked` con la vigente; con un `replaces` que no es la vigente → 409 `campaign_link_moved`.
8. Tope de asociaciones activas (§I12) → 409 `campaign_links_limit`.
9. Transacción: si hay `replaces`, la vigente pasa a `inactive` con el motivo fijo «Sustituida por otra asociación» y `replaced_by_link_id`; se inserta la nueva y se escriben los eventos del libro. La carrera la resuelve el índice parcial: 23505 → 409 `campaign_already_linked`, resuelto **fuera** de la transacción abortada (patrón de `StudioVersions::create`).

`POST integrations/meta/links/{uuid}/deactivate` `{reason 5–500}`: `active → inactive`; si ya está inactiva → 409 `invalid_transition` (la forma de F, con `currentStatus` y `allowedFrom: ["active"]`).

**Sin cuatro ojos:** una asociación no cambia Meta ni el motor, no gasta ni contiene datos personales.

**I3.5 Dos estados que nunca se mezclan.** El CRM pinta dos insignias distintas y con texto: «En Meta: Activa · hace 42 min» (o «Pausada», «Archivada», «Posiblemente eliminada», «Dato viejo») y «En ULTRON Studio: Asociación activa (sin efecto en el motor)» o «Sin asociación». Texto obligatorio en la pestaña y en la ayuda del permiso: **«La asociación queda registrada en el Studio. No cambia nada en Meta ni en lo que ULTRON dice o hace.»**

**I3.6 Sin token o sin cuentas.** Las lecturas devuelven `connection.state = not_configured` con su motivo y la lista vacía; las escrituras, 409 `meta_not_configured`. **No hay datos de demostración en producción**: solo en los fixtures y en el E2E. Una asociación ya creada sigue en su tabla y se enseña con `campaign_name_at_link` y «Cuenta no conectada».

### I4. Números canarios

**I4.1 `ultron_studio_canary_numbers`.**

```
id                        bigint PK
uuid                      uuid UNIQUE
client_request_id         uuid UNIQUE
channel                   varchar(10)       -- whatsapp | voice   (CHECK)
alias                     varchar(40)       -- etiqueta por función («Móvil de pruebas 1»), validada (I4.2)
phone_hash                char(64)          -- PhoneFingerprint::of(E.164): HMAC-SHA256 hex con subclave propia (I4.9)
phone_last4               char(4)
country_code              varchar(4)        -- '57'
phone_encrypted           text NULL         -- SOLO voice (cast `encrypted`); se pone a NULL al revocar
status                    varchar(12)       -- pending | authorized | active | revoked   (CHECK)
expected_version_id       FK NULL → ultron_studio_versions (RESTRICT)   -- expectativa (I4.7), no autoridad
consent_attested          boolean           -- quien registra declara el consentimiento del titular (CHECK = true)
registered_by_admin_id / _name / _role, created_at
authorized_by_admin_id / _name / _role NULL, authorized_at NULL
activated_by_admin_id / _name / _role NULL, activated_at NULL      -- la última activación
revoked_by_admin_id / _name / _role NULL, revoked_at NULL, revoke_reason varchar(500) NULL
status_changed_at         timestamp
updated_at                timestamp
INDEX uscn_status_idx (status, channel)
UNIQUE PARCIAL uscn_one_live (channel, phone_hash) WHERE status <> 'revoked'   -- en los dos motores
```

**Guardas:** `uscn_forbid_delete` y `uscn_forbid_truncate`; `uscn_forbid_immutable_update` (`UPDATE OF` `uuid`, `client_request_id`, `channel`, `phone_hash`, `phone_last4`, `country_code`, `consent_attested`, `registered_by_*` y `created_at`); `uscn_revoked_is_final`; y `uscn_cipher_only_shrinks` (un `phone_encrypted` no nulo no puede cambiar a otro valor no nulo: solo a NULL). CHECK en PG: `uscn_channel_chk`, `uscn_status_chk`, `uscn_consent_chk`, `uscn_voice_cipher_chk` (`channel = 'voice' OR phone_encrypted IS NULL`), `uscn_four_eyes_chk` (`authorized_by_admin_id IS NULL OR authorized_by_admin_id <> registered_by_admin_id`), `uscn_authorized_chk` (`status IN ('pending','revoked') OR authorized_by_admin_id IS NOT NULL`) y `uscn_revoke_chk` (`status <> 'revoked' OR (revoked_at IS NOT NULL AND revoke_reason IS NOT NULL AND phone_encrypted IS NULL)`).

**I4.2 E.164, canal y alias (`StudioCanaryPhone`, puro, con fixture).**
- Entrada `phone` como texto. Se quitan **solo** espacios, guiones, puntos y paréntesis; cualquier otro carácter → 422. El resultado tiene que casar con `^\+[1-9]\d{7,14}$` (E.164: `+` y hasta 15 dígitos).
- País en la lista permitida (`57` por defecto, §I12) → si no, 422 `phone.country_not_allowed`. Las llamadas de Twilio solo están permitidas a Colombia (`ULTRON_STUDIO_TWILIO_REPORT.md`).
- Por canal: `whatsapp` → móvil colombiano `^\+573\d{9}$`; `voice` → `^\+57(3\d{9}|60\d{8})$` (móvil o fijo del plan de 2021). Si no, 422 `phone.not_allowed_for_channel`.
- **El número nunca se devuelve:** solo `masked` (`+57 ••• ••• 4821`). Tampoco va al libro ni al log.
- `alias`: 3–40 caracteres; letras, dígitos, espacios y `.-_()`; sin series de 4 dígitos o más; sin `@`. Aviso visible: «Usa una función, no el nombre de una persona».
- `consentAttested` tiene que ser `true` → si no, 422. Casilla: «La persona titular de este número aceptó recibir mensajes de prueba (y, si es de voz, llamadas de prueba en el futuro)».

**I4.3 Máquina de estados.**

| Desde | Acción | Hacia | Permiso | Quién | Qué revalida el servidor |
|---|---|---|---|---|---|
| — | registrar | `pending` | `ultron.canary_manage` | cualquiera | Bandera de escrituras; E.164 y canal (I4.2); único vivo por canal (23505 → 409 `canary_number_exists`); tope de vivos por canal; idempotencia |
| `pending` | autorizar | `authorized` | `ultron.canary_authorize` | **≠ quien registró** (403 `four_eyes_required` + evento deduplicado; además, CHECK en PG) | Estado esperado (UPDATE condicionado) |
| `authorized` | activar | `active` | `ultron.canary_manage` | cualquiera (la autorización de otra persona ya existe) | Tope de activos por canal, con cerrojo consultivo por canal y `READ COMMITTED` primero (lección B-2 de G y 4.6 de H4) → 409 `canary_active_limit` |
| `active` | desactivar | `authorized` | `ultron.canary_manage` | cualquiera | Motivo 5–500 |
| `pending` · `authorized` · `active` | revocar | `revoked` (terminal) | `ultron.canary_manage` | cualquiera (es el sentido seguro) | Motivo 5–500; en la misma sentencia, `phone_encrypted = NULL` |
| `pending` · `authorized` · `active` | fijar o quitar la versión esperada | (igual) | `ultron.canary_manage` | cualquiera | Versión `APPROVED`, `CANARY` o `PUBLISHED` e íntegra → si no, 409 `version_not_linkable` / `integrity_mismatch`; `null` la quita |

Las transiciones son UPDATE condicionados al estado esperado (como `StudioVersion::transition()`), en la misma transacción que el evento del libro. Una repetición devuelve 409 `invalid_transition` con el estado de ahora, y el CRM lo lee como «ya estaba hecho» cuando ese estado es el de destino.

**I4.4 Cuatro ojos por identidad**, como `StudioAccess::isCreator()`: se compara el id de quien autoriza con `registered_by_admin_id`, nunca el permiso. Tener `ultron.canary_authorize` no deja autorizar lo propio. **La base también lo impide** (`uscn_four_eyes_chk`), aunque alguien escriba sin pasar por el servicio.

**I4.5 Coherencia con el canario del motor (`StudioCanaryCoherence`, solo lectura).**

1. `id = config('marketing.ultron.canary_conversation_id')`, la misma lectura que ya hacen `StudioCanaryMeasures.php:94` y `StudioEngineReadings.php:166`. Si es `null` → `no_engine_canary`.
2. Dos consultas puntuales: `lead_id` de esa conversación y `phone` de ese lead. Se normaliza a E.164 (dígitos; 10 dígitos que empiezan por 3 → se antepone `57`; luego `+`), se calcula `PhoneFingerprint::of()` y **se descarta el teléfono** (`unset`). Nunca se registra, se devuelve ni se guarda.
3. Se compara con las huellas `whatsapp` no revocadas del registro.
4. Veredicto (conjunto cerrado): `in_sync` (el teléfono del canario está registrado y **activo**, y no hay otros activos de WhatsApp); `engine_canary_not_registered`; `engine_canary_registered_inactive` (`pending` o `authorized`); `registry_has_extra_active` (el del canario está activo, pero hay más activos de WhatsApp que el motor **no** atiende); `no_engine_canary`; `unreadable` (cualquier `Throwable`, con la clase al log). **Nunca** `in_sync` por defecto.
5. Lo que sale: el veredicto, recuentos y, por cada número del registro, `isEngineCanary: true|false|null`. **Nunca** el id de la conversación ni su teléfono. Que se vea qué número enmascarado del registro corresponde al canario es el propósito de la coherencia; queda señalado para `api-security-specialist`.

Texto obligatorio en la pestaña y en la ayuda del permiso: **«Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.»** En el CRM, cada estado lleva el sufijo «(registro)» («Activo (registro)»), para que nunca se lea como «activo en ULTRON».

**I4.6 La transición segura (en H6 solo T0 y T1).**

| Fase | Qué cambia | Dónde | Condición para pasar |
|---|---|---|---|
| **T0** (H6, fase 1) | Existe el registro; la coherencia lo compara con el canario del entorno. Nadie lee el registro | Studio | — |
| **T1** (H6, fase 2) | Quien tiene el teléfono de la conversación canaria lo registra (`whatsapp`), otra persona lo autoriza y se activa → `in_sync`. **El entorno no se toca** | Studio | Autorización del propietario y dos personas |
| **T2** (hito futuro) | El motor añade una **restricción**: entra en ULTRON si la conversación es la del entorno **y** su teléfono está activo en el registro (`MARKETING_ULTRON_CANARY_SOURCE = env \| env_and_registry`, `env` por defecto). Se lee **en el emisor**, antes de crear el evento (no como un 403 nuevo del commit: lección §2.4 del plan H5, porque el vigía frenaría el canario), desde una sola clase en la lista blanca, como `UltronPublishedConfig`. Un fallo de lectura en el emisor no crea evento, así que no hay turno mudo ni freno | Motor | `in_sync` sostenido + autorización expresa + su propio canario |
| **T3** (posterior) | El registro sustituye al id de conversación (`registry`); el canal `voice` lo usa como lista de permitidos del marcador | Motor | Evidencia de T2 + decisión del propietario sobre el tráfico |

`MARKETING_ULTRON_ENABLED`, la pausa y el freno siguen mandando por encima en todas las fases. El canario del cobro queda fuera de este registro.

**I4.7 «Asociar una versión» = expectativa.** `expected_version_id` dice qué versión **se espera probar** con ese número. La versión que **rige** sale de una sola autoridad:
- `whatsapp`: si el número es el del canario del motor (`isEngineCanary = true`), la parte `canary` de la cadena del H5 (`StudioPublications::state()`, o la parte `rest` si `canary` es `null`, como resuelve el lector del motor); si no lo es, `none` con el motivo `not_engine_canary`; con la cadena en Legacy o la llave del motor en `off`, `legacy` u `off`.
- `voice`: `none` con el motivo `no_voice_engine`.

El DTO enseña `expectedVersion`, `effectiveVersion` y `expectation: match | mismatch | not_applicable`. **Nada** usa la expectativa para decidir algo: ni el motor, ni publicar, ni la sonda. Si la versión esperada pasa después a `ROLLED_BACK` o `RETIRED`, se marca `stale` al leer y no se cambia sola.

**I4.8 Topes y concurrencia.** Como mucho `ULTRON_STUDIO_CANARY_MAX_ACTIVE` activos por canal (3 por defecto; 1–10) y 10 vivos (no revocados) por canal. El de activos se comprueba con `pg_advisory_xact_lock(crc32('ultron_studio_canary_active:'||canal))` tras `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`. El de únicos lo da el índice parcial.

**I4.9 Minimización y claves.**
- `whatsapp`: **solo** huella y 4 últimos dígitos. Ni con la `APP_KEY` se recupera el número. Al motor futuro (T2) le basta la huella.
- `voice`: además, el número cifrado (`encrypted` de Laravel con la `APP_KEY`), porque un marcador futuro lo necesitará. **Al revocar se pone a NULL** en la misma sentencia (CHECK `uscn_revoke_chk`).
- `PhoneFingerprint::of($e164)` = `hash_hmac('sha256', $e164, $k)` en hexadecimal, con `$k = hash_hmac('sha256', 'ultron-canary-phone/1', <APP_KEY cruda, decodificada si lleva base64:>, true)`. Es la misma lectura de derivación que la Q-1 del H5, con una etiqueta propia y versionada.
- **Rotar la `APP_KEY`** deja inservibles las huellas y los cifrados: los números habría que volver a registrarlos. Al runbook, junto a E2.4 del H5: rotarla es un evento de todo el sistema.

### I5. Twilio

**I5.1 Auditoría de lo existente (se conserva tal cual; los hallazgos van al informe de seguridad).**

| # | Hallazgo | Riesgo | Qué hace H6 |
|---|---|---|---|
| T-1 | La firma se calcula sobre `fullUrl()` y no hay `trustProxies` (hallazgo 12 de la auditoría) | Disponibilidad: si el esquema o el host que ve PHP no son los públicos, la firma falla **cerrada** (403) y se pierde el aviso. No es una puerta abierta | Nada en el código. El tester añade como fixture el vector oficial de Twilio (URL, parámetros y firma esperada) para cuando se extraiga el validador |
| T-2 | El validador es privado del controlador y no admite cuerpos JSON (`bodySHA256`) | Ninguno hoy: los avisos de uso y los webhooks de voz de Twilio van en formulario | **No se extrae** `TwilioSignatureValidator` sin un segundo consumidor (el primero será el webhook de TwiML del hito de voz). Extraerlo ahora cambiaría un webhook que funciona sin ganar nada |
| T-3 | Los avisos solo van al log | No hay historial consultable desde el Studio | Se dice en el panel. Persistirlos sería cambiar el controlador (pregunta 12) |
| T-4 | La idempotencia está en la caché (`Cache::add`, 24 h) y solo con `IdempotencyToken` | Bajo; según el comentario de `OtpCostGuard.php:13-15`, la caché de producción es `database` y sobrevive a reinicios **[no verificado]** | Nada |
| T-5 | El Auth Token maestro firma y valida, y hay dos pares de alias | Si `TWILIO_SID`/`TWILIO_TOKEN` no coinciden con `TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`, la credencial efectiva es la primera (`config/otp.php:141-142`) | El panel enseña qué nombre es el efectivo y si los alias coinciden, comparando huellas (I5.2) |
| T-6 | `TWILIO_BASE` y `TWILIO_VERIFY_BASE` vienen del entorno, y Verify y Messages mandan las credenciales en Basic a ese host | Solo con acceso al `.env`, que ya es comprometerlo todo | Nada en el OTP. La sonda **ignora** esas variables en `production` y usa los hosts oficiales fijos (I5.5) |
| T-7 | Puede haber filas `pending` en `marketing_calls` con el teléfono del prospecto en claro | **Trampa latente:** un marcador futuro que consumiera esas filas llamaría a prospectos, no a canarios | Se documenta como regla del hito de voz: el marcador solo llama a números canarios de voz activos y **nunca** consume `marketing_calls.pending`. `VoiceCallPolicy` lo codifica (I6.5) |
| T-8 | `toE164()` antepone el país sin validar | Bajo en el OTP | Los canarios usan `StudioCanaryPhone`, estricto |

**I5.2 Panel de Twilio (`GET integrations/twilio`, sin red).**

```jsonc
{ "classification": "connected_otp_only",
  "otp": { "driver": "twilio", "verifyActive": true, "sendEnabled": true, "rateLimitConfigured": true, "legacySenderEnabled": false,
           "memberLinkSenderConfigured": true, "verified": { "last24h": 14, "last7d": 96, "lastAt": "…" } },
  "cost": { "date": "2026-10-31", "estimatedSpentUsd": 0.41, "dailySoftLimitUsd": 6.0, "dailyHardLimitUsd": 12.0, "note": "Estimación local; la cifra facturada es la de Twilio" },
  "credentials": { "accountSid": { "present": true, "source": "TWILIO_SID", "ref": "AC…1234" },
                   "authToken": { "present": true, "source": "TWILIO_TOKEN", "fp": "a1b2c3d4" },
                   "aliasesConsistent": { "sid": true, "token": true },
                   "verifyServiceSid": { "present": true, "ref": "VA…9f0e" },
                   "apiBase": { "official": true }, "verifyBase": { "official": true } },
  "webhooks": [ { "id": "usage_trigger", "path": "/api/webhooks/twilio/usage-trigger", "signature": "HMAC-SHA1 (X-Twilio-Signature)",
                  "idempotency": "IdempotencyToken, caché 24 h", "throttle": "60/min", "persistence": "solo log" } ],
  "voice": { "enabled": false, "fromConfigured": false, "state": "not_configured" },
  "calls": { "total": 0, "withProviderSid": 0, "byStatus": {}, "byDirection": {}, "lastCreatedAt": null },
  "probe": { "enabled": false, "last": null } }
```

- `otp` sale de `config('otp.*')`, `TwilioVerifyService::isActive()` y recuentos de `member_auth_challenges` y `trainer_auth_challenges` con estado `verified` o `completed` (el backend confirma la semántica exacta de cada estado). `cost` sale de `OtpCostGuard::snapshot()`.
- `credentials`: el SID se enseña como prefijo y 4 últimos caracteres (no es secreto); el token, **solo** como huella `fp` (§I5.4). `aliasesConsistent` es `null` si solo existe un nombre, y la comparación se hace con `hash_equals` sobre los valores, sin devolverlos. Un host no oficial se enseña como `{official:false, host:"…"}`, sin ruta ni credenciales.
- `webhooks` es una descripción fija tomada del código: no se llama a nada.
- `calls`: recuentos de `marketing_calls` por estado y dirección, y cuántas tienen `provider_call_sid`, que serían llamadas reales (hoy, **[suposición]** 0). **Nunca** `to_phone`, `from_phone`, `transcript`, `summary`, `metadata` ni ids de lead.

`GET integrations/twilio/calls` (paginado, ≤ 50): `{status, direction, reason, scheduledAt, startedAt, endedAt, durationSeconds, hasProviderSid, createdAt}` por fila, sin ningún dato del prospecto. Responde a «consultar llamadas e historial» con la verdad: hoy son intenciones sin llamada.

**I5.3 Por qué NO se edita el `.env` desde el CRM.**
1. **Secretos fuera del servidor:** el Auth Token tendría que viajar desde el navegador (XSS, extensiones, puestos compartidos) y guardarse en la base, donde el rol `iron_app` tiene DML sobre todo.
2. **SSRF y robo de credenciales:** `TWILIO_BASE` y `TWILIO_VERIFY_BASE` son URL. Editarlas desde una pantalla permitiría mandar la credencial en Basic a un host ajeno.
3. **Caché de configuración y workers:** un cambio de entorno exige `config:cache` como `www-data`, `chmod 600` y reiniciar workers. La memoria «config:cache y los workers» recoge doce workers en BACKOFF por saltarse el `chown`. Una capa en la base que sobrescriba el entorno sería un mecanismo nuevo en el camino crítico del login de socios (el OTP).
4. **Responsabilidad:** las credenciales las pone el propietario en el servidor, nunca por chat ni por pantalla (regla vigente del H4).

Lo que sí se gestiona desde el CRM es **la configuración autorizada**: qué configuración efectiva reconoció una persona con permiso, cuándo cambió y qué difiere ahora (I5.4).

**I5.4 Configuración autorizada (Twilio, Meta Ads y voz): `ultron_studio_integration_snapshots`.**

```
id                    bigint PK
uuid                  uuid UNIQUE
client_request_id     uuid UNIQUE NULL          -- en los reconocimientos de una persona
provider              varchar(16)               -- meta_ads | twilio | voice   (CHECK)
kind                  varchar(10)               -- config | probe   (CHECK)
fingerprint           char(64)                  -- sha256 del JSON canónico de `facts`
facts                 json                      -- I5.4.1 (config) o I5.5 (probe); jamás un secreto ni un teléfono
outcome               varchar(16) NULL          -- probe: ok | degraded | auth_failed | unreachable | error
actor_type            varchar(10)               -- admin | system   (CHECK)
actor_admin_id / actor_name / actor_role        -- instantánea sin FK; system = el comando
note                  varchar(500) NULL         -- nota del reconocimiento (aviso de datos personales)
created_at            timestamp                 -- solo inserción
INDEX usis_provider_idx (provider, kind, id)
```

Guardas: `usis_forbid_update`, `usis_forbid_delete`, `usis_forbid_truncate`; CHECK de dominio en PG.

**I5.4.1 `facts` de configuración**, por clave y sin valores: `{present: bool}` en todas; `ref` en los identificadores no secretos (SID `AC…1234`, cuentas de Meta `***4714`); `fp` en los secretos, que son los 8 primeros hex de `hash_hmac('sha256', valor, k_cfg)` con `k_cfg = hash_hmac('sha256', 'ultron-studio-config-fingerprint/1', APP_KEY, true)`, no reversible para un secreto de alta entropía; y el valor tal cual solo en lo que no es secreto ni dato personal (versión de Graph, `sync_enabled`, región de ElevenLabs, techos de coste, banderas). Twilio: los de I5.2 más `from` (`{present, last4}`). Meta Ads: `token`, `accounts[]` con `ref`, `graphVersion`, `syncEnabled`, `syncDays`, `staleMinutes` y `timezone`. Voz: lo de §I6.2 con el mismo criterio.

**I5.4.2 Deriva y reconocimiento.** `GET integrations/config` devuelve, por proveedor: `effective` (facts de ahora) con `effectiveFingerprint`; `authorized` (la última instantánea `config` de una **persona**) u `null`; `observed` (la última de **sistema**); `drift` como lista `{key, authorized, effective}` de claves distintas; y `state: authorized | drift | never_authorized`.
- `POST integrations/config/acknowledge` `{clientRequestId, provider, fingerprint, note?}`: `ultron.integrations_manage`. Si `fingerprint` ≠ el efectivo de ahora → 409 `config_changed` con el nuevo (como `previewHash` en el H5). Inserta `kind=config, actor_type=admin` y el evento `integration.config_acknowledged {provider, fingerprint, driftKeys}`. **No** depende de la bandera de escrituras: registra un hecho sin tocar nada y es la forma de dejar constancia de la configuración desde la fase 1. Pregunta 11.
- `ultron-studio:integrations-snapshot` (lo ejecuta `devops` en cada despliegue y, en el Hito 7, una tarea programada) inserta `kind=config, actor_type=system` **solo** para los proveedores cuya huella cambió desde la última instantánea. Es el **registro de cambios** aunque nadie reconozca nada.
- Un GET **nunca** escribe: la deriva se calcula al leer.

**I5.5 Sonda de solo lectura (opción B2; bandera `ULTRON_STUDIO_TWILIO_PROBE_ENABLED`, apagada).**
- `POST integrations/twilio/probe` `{clientRequestId}`: `ultron.integrations_manage`. Bandera apagada → 409 `probe_disabled`. Si la última sonda tiene menos de 5 min, 200 con esa misma y `reused: true`, sin red. Cupo propio `ultron-studio-probe`: 6/h por persona y 20/h por red.
- `TwilioReadOnlyClient` (espacio neutro `App\Services\Twilio`): **solo** `Http::withBasicAuth($sid, $token)->acceptJson()->timeout(10)->get(...)`. Cinco rutas fijas: `GET /2010-04-01/Accounts/{sid}.json` (estado y tipo), `…/IncomingPhoneNumbers.json?PageSize=20` (capacidades), `…/OutgoingCallerIds.json?PageSize=20` (recuento), `…/Calls.json?PageSize=50&StartTime>=<hoy-7d>` (recuento por estado) y `https://monitor.twilio.com/v1/Alerts?PageSize=50&StartDate=<ahora-24h>` (recuento por código y nivel). **Solo la primera página**: nunca se sigue `next_page_uri`, igual que el cliente de Meta, que nunca sigue `paging.next`. Presupuesto total de 30 s; se para en el primer 401/403 (`auth_failed`). En `production` los hosts son **siempre** `https://api.twilio.com` y `https://monitor.twilio.com` (se ignoran `TWILIO_BASE` y `ULTRON_STUDIO_TWILIO_PROBE_BASE`, que solo vale fuera de producción para el simulador del E2E, como `StudioLabOpenAiClient.php:201-209`).
- `TwilioProbeSanitizer` (puro, con fixture): de cada respuesta se queda solo con `{account:{status, type}, numbers:{count, voiceCapable, smsCapable, items:[{last4, voice, sms}]}, callerIds:{count}, calls7d:{total, truncated, byStatus}, alerts24h:{total, truncated, byCode, byLevel}}`. Se **descartan** `friendly_name` (lleva el número formateado), `to`, `from`, `alert_text`, `request_url`, `request_variables`, `response_body` y cualquier texto.
- Guarda `kind=probe` en las instantáneas con su `outcome` y escribe `integration.probe_run {provider:'twilio', outcome}`. Un fallo del proveedor **no** es un 5xx: es 200 con `outcome` `unreachable`, `auth_failed` o `error`.
- **Credencial:** la del OTP (`otp.twilio.sid/token`). Si el propietario crea una API key restringida de solo lectura, el contrato admite `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID/SECRET`, que tienen preferencia. Recomendación en la pregunta 1.

**I5.6 Twilio Voice tras bandera.** `TWILIO_VOICE_ENABLED=false` y `TWILIO_VOICE_FROM` (E.164) en `config/voice.php`. **Nada las lee para ejecutar**: solo el panel y la política (I6.5). No hay TwiML, webhook de voz, subcuenta ni marcador en este hito.

### I6. ElevenLabs y voz (preparación sin activar)

**I6.1 Puerto e implementaciones (`App\Services\Voice`, neutro).** Ni el Studio ni el motor; ninguno de los dos lo nombra en el sentido contrario: lo vigila `VoiceIsolationTest` (§I9).
- `VoiceAgentProvider` (interfaz): `id(): string`; `status(): VoiceProviderStatus`, sin red (configuración y piezas que faltan); `verifyCredentials(): VoiceCheckResult`, con red (GET, solo consola). **No hay ningún método que inicie, registre ni transfiera una llamada.** Esa parte del puerto se diseña y se añade en el hito de voz.
- `NullVoiceProvider`, por defecto: `status()` → `ELEVENLABS_NOT_CONFIGURED` (o `VOICE_PROVIDER_NONE` si se elige expresamente ningún proveedor); `verifyCredentials()` → `not_configured`, sin red.
- `ElevenLabsVoiceProvider`, solo si `voice.elevenlabs.enabled === true` **y** `ElevenLabsConfig::missing() === []`. `verifyCredentials()` = `GET {base}/v1/convai/agents/{agent_id}` con la cabecera `xi-api-key`: 200 → `credentials_ok` (y, si la respuesta lo trae, si el agente está en μ-law 8000, «a confirmar con la documentación el día de activar»); 401/403 → `invalid_credentials`; 404 → `agent_not_found`; otra cosa → `error`. Nunca registra ni devuelve la clave ni el cuerpo.
- `VoiceProviders::current()` elige la implementación sin registrar nada en el contenedor, así que no toca `bootstrap/providers.php` ni `AppServiceProvider`.

**I6.2 Configuración (`config/voice.php`, todo apagado).**

| Variable | Clave | Defecto | Nota |
|---|---|---|---|
| `ELEVENLABS_ENABLED` | `voice.elevenlabs.enabled` | `false` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)`: solo `true/1/on/yes` la enciende (M-1 del H5) |
| `ELEVENLABS_API_KEY` | `voice.elevenlabs.api_key` | — | Secreto. Solo lo lee el proveedor, en el momento de llamar |
| `ELEVENLABS_AGENT_ID` | `voice.elevenlabs.agent_id` | — | |
| `ELEVENLABS_WEBHOOK_SECRET` | `voice.elevenlabs.webhook_secret` | — | Secreto (firma post-llamada) |
| `ELEVENLABS_REGION` | `voice.elevenlabs.region` | `default` | **Lista cerrada** de URL base oficiales por región, tomada de la documentación el día de implementar. Un valor fuera de la lista → `NOT_CONFIGURED` con `invalid_region`. Nunca una URL libre |
| `ELEVENLABS_AGENT_PHONE_NUMBER_ID` | `voice.elevenlabs.agent_phone_number_id` | — | Solo para la integración nativa (opción descartada en el diseño del 17-10); no se exige |
| `ELEVENLABS_TOOL_SECRET` | `voice.elevenlabs.tool_secret` | — | Para las server tools del futuro; no se exige |
| `ELEVENLABS_BASE_URL` | `voice.elevenlabs.base_url` | — | **Se ignora en `production`**; fuera, el simulador del E2E |
| `ELEVENLABS_TIMEOUT_SECONDS` | `voice.elevenlabs.timeout_seconds` | 10 | 3–30, con recorte |
| `TWILIO_VOICE_ENABLED` | `voice.twilio.enabled` | `false` | Igual que arriba |
| `TWILIO_VOICE_FROM` | `voice.twilio.from` | — | E.164 de un número de voz o caller ID verificado |

**I6.3 `doctor`.** Estados (conjunto cerrado):
- `ELEVENLABS_NOT_CONFIGURED`: falta alguna pieza obligatoria (`api_key`, `agent_id`, `webhook_secret`) o la región no es válida. **Es el estado de producción en H6.**
- `ELEVENLABS_DISABLED`: están todas las piezas y la bandera está apagada.
- `ELEVENLABS_UNVERIFIED`: bandera encendida y piezas completas, sin una verificación con éxito registrada.
- `ELEVENLABS_VERIFIED`: **lo calcula el Studio**, no el `doctor`. Es `UNVERIFIED` más una instantánea `kind=probe, provider=voice, outcome=ok` de menos de 30 días.

`voice:doctor [--json]` imprime el estado y las piezas que faltan **por nombre**, nunca valores. `voice:doctor --verify` llama a `verifyCredentials()` solo con la bandera encendida y las piezas completas, e imprime el resultado **sin guardarlo**: el espacio neutro no puede nombrar tablas del Studio (`UltronEngineIsolationTest`). La evidencia la deja `ultron-studio:integrations-snapshot --verify-voice`, que hace la misma verificación desde el Studio y guarda la instantánea con un actor de sistema. **En H6 ninguna de las dos se ejecuta en producción**: son pasos del procedimiento de activación (I6.6). En el CRM, `GET integrations/voice` enseña el estado y **no** tiene botón de verificar.

**I6.4 Firma del webhook post-llamada (`ElevenLabsWebhookSignature::verify(string $rawBody, ?string $header, string $secret, int $now): Verdict`, pura, con fixture).**
1. Cabecera ausente o vacía → `missing_signature`.
2. Se parte por `,` y cada parte por el primer `=`: hace falta exactamente un `t` (entero decimal) y uno o más `v0` (64 hex). Cualquier otra forma → `malformed_signature`. Las claves desconocidas se ignoran (lo dice el fixture).
3. `t < now − 1800` → `expired`; `t > now + 300` → `from_future` (decisión defensiva: el ejemplo oficial solo mira el pasado; 5 min de margen de reloj).
4. `esperado = hash_hmac('sha256', t . '.' . $rawBody, $secret)` en hexadecimal; vale si **algún** `v0` coincide con `hash_equals`. Si no → `mismatch`.
5. Siempre sobre el **cuerpo crudo** (`$request->getContent()`), nunca sobre JSON vuelto a serializar.

Además, `ElevenLabsWebhookEnvelope::type(string $rawBody)` (pura) reconoce `post_call_transcription`, `post_call_audio` y `call_initiation_failure` sin conservar la transcripción. **No hay ruta pública en H6** (§4.6).

**I6.5 Política de llamadas (`VoiceCallPolicy::decide(VoiceCallContext): VoiceCallDecision`, pura, con fixture).** Entrada: `twilioVoiceEnabled`, `twilioVoiceFromConfigured`, `providerState`, `canary` (`active_voice | not_active | not_registered`, lo calcula quien la usa), `dailyCap` y `source` (`canary_registry | marketing_calls | other`). Salida: `{allowed: bool, reasons: [...]}` en un orden fijo. **El primer motivo, siempre en H6:** `calls_disabled_in_code` (`CALLS_ENABLED_IN_CODE = false`, una constante, como `ENGINE_READS_VERSIONS` en el H5). Después vienen los demás (`twilio_voice_disabled`, `no_voice_number`, `elevenlabs_not_configured|disabled|unverified`, `not_an_active_voice_canary`, `source_not_allowed` cuando viene de `marketing_calls`, `daily_cap_zero`), para que el panel diga **todo** lo que falta y el día de mañana no se abra una puerta sin las otras. La constante solo se gira en el commit que implemente las llamadas, con autorización expresa.

**I6.6 Procedimiento de activación** (lo redacta `documentation-specialist` en `ULTRON_STUDIO_ELEVENLABS_READY.md`; en H6 **no se ejecuta**):
1. **Decisiones del propietario antes de nada:** grabación y retención (Ley 1581: consentimiento y plazo); si en voz se pueden decir precios (pregunta 4 de la auditoría); número de voz colombiano en Twilio o caller ID verificado (la sonda lo responde); subcuenta de voz y su presupuesto; nunca importar el número productivo de WhatsApp (está en coexistencia).
2. **Credenciales** (las pone el propietario en el servidor, nunca por chat): clave de API de ElevenLabs **acotada** (por endpoint, crédito e IP si la consola lo permite), `agent_id`, secreto del webhook, región y, para Twilio Voice, número o caller ID y subcuenta.
3. **Pasos:** variables antes de cualquier `ironbody-migrate` (lección del H4) → `config:cache` como `www-data` con `chmod 600` → `voice:doctor` (`DISABLED`) → encender `ELEVENLABS_ENABLED` → `voice:doctor --verify` (resultado en pantalla) → `ultron-studio:integrations-snapshot --verify-voice` (`VERIFIED` en el Studio) → evidencia en el informe.
4. **Lo que sigue cerrado aunque todo esté verde:** las llamadas (`CALLS_ENABLED_IN_CODE = false`) hasta el hito de voz, con su ruta de TwiML, el validador de Twilio extraído, el webhook post-llamada, la lista de permitidos de canarios de voz, `TimeLimit`, el cupo diario y su propio canario.
5. **Vuelta atrás:** `ELEVENLABS_ENABLED=false` y caché de configuración; el estado vuelve a `DISABLED` y no se ha llamado a nadie.

**I6.7 Panel de voz (`GET integrations/voice`, sin red).** `{elevenlabs:{state, enabled, missing[], region, lastVerification|null}, twilioVoice:{enabled, fromConfigured}, policy:{allowed:false, reasons[]}, levels:[{id:'text_simulation', available:true, cost:'none', where:'Laboratorio'}, {id:'integration_test', available:false, reasons[]}, {id:'real_call', available:false, reasons[]}]}`. Los tres niveles son los del informe de Twilio del 17-10: «simulación de texto (no consume), prueba de integración y llamada real (cuesta dinero y exige autorización)». Texto obligatorio: **«En este hito no se hace ninguna llamada.»**

### I7. API (`/api/admin/ultron-studio/integrations/*`, 18 rutas nuevas → 56)

Todas en el mismo grupo que las 38 de hoy: prefijo `admin/ultron-studio`, tope de cuerpo de 64 KB (413 `payload_too_large`), controladores en `App\Http\Controllers\Crm\UltronStudio` (lo exige `UltronEngineIsolationTest`), una entrada por ruta en `AuthorizationMap::OVERRIDES`, y `StudioAccess::requirePerson()` más el permiso de la ruta dentro del dominio. Sin route-model binding: el uuid es texto (`whereUuid`).

| # | Ruta | Permiso | Grupo | Escribe en |
|---|---|---|---|---|
| 39 | `GET integrations/status` | `ultron.view` | lectura | — |
| 40 | `GET integrations/meta/campaigns` | `ultron.view` | lectura | — |
| 41 | `GET integrations/meta/links` | `ultron.view` | lectura | — |
| 42 | `GET integrations/canaries` | `ultron.view` | lectura | — |
| 43 | `GET integrations/twilio` | `ultron.view` | lectura | — |
| 44 | `GET integrations/twilio/calls` | `ultron.view` | lectura | — |
| 45 | `GET integrations/voice` | `ultron.view` | lectura | — |
| 46 | `GET integrations/config` | `ultron.view` | lectura | — |
| 47 | `POST integrations/meta/links` | `ultron.campaigns_manage` | escritura | links + libro |
| 48 | `POST integrations/meta/links/{uuid}/deactivate` | `ultron.campaigns_manage` | escritura | links + libro |
| 49 | `POST integrations/canaries` | `ultron.canary_manage` | escritura | canarios + libro |
| 50 | `POST integrations/canaries/{uuid}/authorize` | `ultron.canary_authorize` | escritura | canarios + libro |
| 51 | `POST integrations/canaries/{uuid}/activate` | `ultron.canary_manage` | escritura | canarios + libro |
| 52 | `POST integrations/canaries/{uuid}/deactivate` | `ultron.canary_manage` | escritura | canarios + libro |
| 53 | `POST integrations/canaries/{uuid}/revoke` | `ultron.canary_manage` | escritura | canarios + libro |
| 54 | `POST integrations/canaries/{uuid}/expectation` | `ultron.canary_manage` | escritura | canarios + libro |
| 55 | `POST integrations/config/acknowledge` | `ultron.integrations_manage` | escritura | instantáneas + libro |
| 56 | `POST integrations/twilio/probe` | `ultron.integrations_manage` | escritura + `throttle:ultron-studio-probe` | instantáneas + libro (y GET a Twilio) |

**Permisos nuevos (4)**, con su etiqueta y su ayuda en `PermissionCatalog`:

| Llave | Etiqueta | Ayuda (resumen) | Administrador por defecto |
|---|---|---|---|
| `ultron.campaigns_manage` | Asociar campañas de Meta | Asociar campañas a la línea base o a una versión y desactivar asociaciones. Solo queda registrado en el Studio: no cambia nada en Meta ni en lo que ULTRON dice o hace | **Sí** |
| `ultron.canary_manage` | Administrar números canarios | Registrar números de prueba con el consentimiento de su titular, activarlos cuando otra persona los autorizó, desactivarlos y revocarlos. Son datos personales: se guardan como huella (y cifrados si son de voz). El motor no los lee en este hito | **Sí** |
| `ultron.canary_authorize` | Autorizar números canarios de otra persona | Cuatro ojos: nadie autoriza lo que registró | **No** (se concede a mano, como `ultron.publish`) |
| `ultron.integrations_manage` | Configuración autorizada y comprobación de Twilio | Reconocer como autorizada la configuración vigente de Meta, Twilio y voz (sin ver ni editar secretos) y lanzar la comprobación de solo lectura de Twilio | **No** (igual que `integrations.manage`) |

**Matriz para las pruebas** (56 rutas): sin sesión → 401; token compartido → 403 (el dominio `ultron` está excluido); Recepción, Entrenador y Administrativo → 403; **Administrador** → 200 en las 8 lecturas, permitido en 47–49 y 51–54, y 403 en 50, 55 y 56; **Super Admin** → permitido en todas. Un rol con una llave de escritura y sin `ultron.view` → 403 antes de buscar el recurso (`StudioAccess::requirePerson`, H-2 de F).

**DTOs que faltan por fijar** (los de Meta, Twilio y voz están arriba):

```jsonc
// CanaryNumberDto (GET integrations/canaries → {data:[…], coherence:{…}, limits:{…}})
{ "uuid": "…", "channel": "whatsapp", "alias": "Móvil de pruebas 1", "masked": "+57 ••• ••• 4821",
  "status": "authorized", "statusLabel": "Autorizado (registro)",
  "registeredBy": { "name": "…", "role": "…" }, "authorizedBy": { "name": "…", "role": "…" } | null,
  "expectedVersion": { "uuid": "…", "label": "…", "statusLabel": "En canario", "stale": false } | null,
  "effectiveVersion": { "source": "publication_chain", "part": "canary", "uuid": "…", "label": "…" } | { "source": "none", "reason": "not_engine_canary" },
  "expectation": "match", "isEngineCanary": true,
  "actions": { "authorize": { "allowed": false, "reason": "four_eyes_required" }, "activate": { "allowed": false, "reason": "invalid_transition" },
               "deactivate": { … }, "revoke": { "allowed": true }, "expectation": { "allowed": true } },
  "createdAt": "…", "statusChangedAt": "…" }
// coherence
{ "engineCanaryConfigured": true, "verdict": "in_sync", "activeWhatsapp": 1, "note": "Este registro no cambia a quién responde ULTRON…" }
// limits
{ "maxActivePerChannel": 3, "maxLivePerChannel": 10, "countryCodes": ["57"], "writesEnabled": true }
// CampaignLinkDto (GET integrations/meta/links; respuesta de 47 y 48)
{ "uuid": "…", "campaign": { "id": "campaign:…", "ref": "***0387", "nameAtLink": "…", "connected": true },
  "target": { "kind": "baseline" } | { "kind": "version", "versionUuid": "…", "label": "…", "statusLabel": "…" },
  "status": "active", "createdBy": { "name": "…", "role": "…" }, "createdAt": "…",
  "deactivated": { "at": "…", "by": { … }, "reason": "…", "replacedBy": "<uuid>" | null } | null }
// ConfigProviderDto (GET integrations/config → {providers:[…]})
{ "provider": "twilio", "state": "drift", "effectiveFingerprint": "…", "effective": { … },
  "authorized": { "uuid": "…", "fingerprint": "…", "at": "…", "by": { "name": "…" } } | null,
  "observed": { "uuid": "…", "at": "…" } | null, "drift": [ { "key": "verifyServiceSid", "authorized": { "ref": "VA…9f0e" }, "effective": { "ref": "VA…1a2b" } } ] }
```

**Errores nuevos:** 409 `integrations_writes_disabled`, `meta_not_configured`, `campaign_already_linked`, `campaign_link_moved`, `campaign_links_limit`, `version_not_linkable`, `canary_number_exists`, `canary_live_limit`, `canary_active_limit`, `config_changed` y `probe_disabled`; 404 `campaign_not_found`, `link_not_found` y `canary_not_found`. Se reutilizan 403 `forbidden`, `person_required` y `four_eyes_required`; 409 `invalid_transition` (con `currentStatus` y `allowedFrom`), `client_request_reused` e `integrity_mismatch`; 404 `version_not_found`; y 422 estándar de Laravel con los campos `phone`, `alias`, `consentAttested`, `reason` y `target`. **Orden:** forma (422) antes que llaves (409), como D-13 del H5.

### I8. Auditoría (`StudioAudit`, solo inserción, en la misma transacción que el cambio)

**Sujetos nuevos:** `campaign_link`, `canary_number` e `integration` (el uuid de la instantánea). Caben en `varchar(16)` y amplían `usae_subject_type_chk` y `StudioAuditEvent::SUBJECT_TYPES`. La alternativa sin ampliar el CHECK (todo como `system`, como hizo el H5 con Legacy) impide filtrar la auditoría por sujeto, que es justo lo que pide «auditoría de números canarios».

| `event` | Sujeto | Desde → hacia | `diff` |
|---|---|---|---|
| `campaign_link.created` | campaign_link | — → active | `{campaign: <id opaco>, accountRef, target:{kind, versionUuid?}, replaces?}` |
| `campaign_link.deactivated` | campaign_link | active → inactive | `{reason, replacedBy?}` |
| `canary_number.registered` | canary_number | — → pending | `{channel}` |
| `canary_number.authorized` | canary_number | pending → authorized | `{}` |
| `canary_number.four_eyes_denied` | canary_number | — | `{}` (deduplicado con `recordDenied`) |
| `canary_number.activated` | canary_number | authorized → active | `{}` |
| `canary_number.deactivated` | canary_number | active → authorized | `{reason}` |
| `canary_number.revoked` | canary_number | * → revoked | `{reason, cipherErased: bool}` |
| `canary_number.expectation_set` | canary_number | — | `{versionUuid | null}` |
| `integration.config_acknowledged` | integration | — | `{provider, fingerprint, driftKeys}` |
| `integration.probe_run` | integration | — | `{provider, outcome}` |

**Nunca en el libro:** teléfonos, sus 4 últimos dígitos, huellas, alias, ids de Meta en claro, nombres de campaña, valores o huellas de configuración más allá del `fingerprint` del conjunto, textos del proveedor ni ids de conversación. El `summary` es genérico («Número canario registrado (WhatsApp)»). Los motivos (`reason`) son texto del personal, igual que en el H5, y llevan el aviso `AVISO_DATOS_MOTIVO` en el CRM. Los rechazos por bandera (`integrations_writes_disabled`, `probe_disabled`) **no** se auditan: no cambian nada y llenarían un libro que no se puede vaciar (criterio D-6 del H5).

### I9. Frontera y pruebas de aislamiento

**La frontera del hito, en una frase verificable:** el motor no lee nada nuevo y el Studio no escribe fuera de `ultron_studio_*`. El Studio lee además el espejo de Meta, los retos del OTP, `marketing_calls` y, para la coherencia, la conversación canaria del entorno y el teléfono de su lead, sin guardarlo. Las clases neutras (`App\Services\Voice`, `App\Services\Twilio` y `App\Support\Privacy\PhoneFingerprint`) no nombran el Studio y el motor no las nombra en H6.

1. **`UltronEngineIsolationTest`** (pasa a ser del paquete S): la lista blanca gana las 2 migraciones del hito; las rutas suben de 38 a 56; nuevas aserciones: ninguna zona del motor nombra `App\Services\Voice`, `App\Services\Twilio` ni `PhoneFingerprint`, y ningún fichero de `app/Services/Voice/` ni de `app/Services/Twilio/` nombra el Studio. El rastreo global ya lo cubre; se añade para que el motivo se lea.
2. **`UltronStudioEngineReadOnlyTest`**: `ENGINE_READERS` gana todo fichero del Studio que nombre `App\Services\Marketing\*` o `App\Models\Marketing*`, cada uno con su aserción de solo lectura. Previsiblemente: `StudioIntegrations.php` y `StudioMetaCampaigns.php` (`MetaAdsSync`, `MetaAdsApiClient`), `StudioCampaignLinks.php` (`LeadSourceClassifier`), `StudioCanaryCoherence.php` (`MarketingConversation`, `MarketingLead`), `StudioTwilioPanel.php` (`MarketingCall`) y `StudioIntegrationConfig.php` si lee las cuentas con el cliente; `StudioMetaCampaigns` con la lista blanca de métodos de I3.3. `STUDIO_TABLES` gana las 3 tablas. El recorrido de comportamiento pasa por las **56** rutas (las escrituras del H6 con la bandera encendida y la sonda contra `Http::fake`) y al final las huellas de todas las tablas que no son del Studio son idénticas, no se encoló nada y no salió **ninguna** petición salvo los GET de la sonda al simulador.
3. **NUEVA `UltronStudioIntegrationsNoNetworkTest`**: con las llaves por defecto, `Http::preventStrayRequests()` y las 18 rutas → cero peticiones; con la sonda encendida, solo las 5 rutas permitidas y solo con GET.
4. **NUEVA `TwilioReadOnlyClientTest`** (estática + `Http::fake`): en el fichero no aparece `post|put|patch|delete|asForm|send`; hosts fijos en `production` (se prueba con `app()->detectEnvironment`); solo las 5 rutas; nunca sigue `next_page_uri`.
5. **NUEVA `VoiceIsolationTest`** (estática): `VoiceCallPolicy::CALLS_ENABLED_IN_CODE === false`; en `app/Services/Voice/` no hay ninguna llamada `Http::` que no sea el GET de `verifyCredentials()`; no aparecen las cadenas `outbound-call` ni `register-call` en una petición; ninguna ruta de `routes/` apunta a un controlador de voz.
6. **Prueba centinela (P5)**: valores reconocibles en `TWILIO_TOKEN`, `TWILIO_AUTH_TOKEN`, `META_ADS_ACCESS_TOKEN`, `ELEVENLABS_API_KEY`, `ELEVENLABS_WEBHOOK_SECRET`, en el teléfono del lead canario de la semilla y en los números registrados. No aparecen en ninguna respuesta de las 18 rutas, en el libro, en `facts` ni en el log capturado.

### I10. CRM (paquete FE)

- **Página hija** `marketing/ultron-studio/integraciones` (diferida, `ULTRON_VIEW`, tras `environment.ultronStudioPreviewEnabled`, como el laboratorio), con su `StudioIntegrationsSession` provista en la página. **Enlace** «Integraciones» junto a «Laboratorio» en la barra del tablero (solo la plantilla de `ultron-studio-page.ts`).
- **Pestañas:**
  - «Estado»: una tarjeta por proveedor con la clasificación en texto, la evidencia y la antigüedad, y la coherencia del canario.
  - «Campañas de Meta»: lista, filtros, las dos insignias de I3.5, asociar (diálogo con la línea base o las versiones asociables) y desactivar (`ib-reason-dialog` con `AVISO_DATOS_MOTIVO`).
  - «Números canarios»: lista enmascarada; registrar (diálogo con E.164, canal, alias y casilla de consentimiento, con el aviso de datos personales); las acciones que mande el servidor en `actions`; la versión esperada frente a la que rige; el banner de coherencia con su texto obligatorio.
  - «Twilio»: OTP, coste, credenciales enmascaradas, webhooks, llamadas, la configuración autorizada y el botón de la sonda solo con `can.integrationsManage` y la bandera, con el texto «Solo lee: no envía mensajes ni hace llamadas».
  - «Voz»: el estado `ELEVENLABS_NOT_CONFIGURED` en grande, las piezas que faltan, los tres niveles y el texto obligatorio.
- **Botones según el servidor:** sin permiso o con la bandera apagada no hay botón, y el motivo se lee en texto visible (D-F1 del H5). Los números y las fechas en formato es-CO.
- **Restricciones:** nunca `ultron-studio.scss` ni `studio-inspector.scss`; cada hoja nueva < 8 kB (kit común `studio-integrations-kit.scss`); 390 px con tarjetas en lugar de tablas; accesibilidad: foco atrapado en los diálogos, `role=alert` en los errores, teclado en las pestañas (patrón del inspector), insignias con texto y no solo color, y margen inferior por el asistente flotante (D-F9 del H5). Tolerar claves desconocidas y un backend sin estas rutas.
- **Fixtures:** `ultron-studio:export-fixtures` gana `backend-integrations.testing.ts` (DTOs de ejemplo, códigos de error, estados de Meta con su etiqueta, estados del canario y veredictos), con paridad en los specs. El catálogo de permisos se regenera con `permissions:export` (+4).

### I11. Pruebas

| Capa | Qué | Dónde y quién |
|---|---|---|
| Núcleo puro | E.164 y reglas por canal (unos 60 casos); vectores de `PhoneFingerprint` con una `APP_KEY` de prueba; máquina de estados del canario (transición × permiso × identidad); reglas de asociación; clasificación de Meta (`ok/stale/failed/not_configured`, `possiblyDeleted`); redacción de la configuración (ninguna fuga); firma de ElevenLabs (válida, caducada, futura, cabecera mal formada, varios `v0`, secreto ajeno, cuerpo manipulado, espacios); estados del `doctor`; tabla de `VoiceCallPolicy`; saneado de la sonda (de la entrada cruda a una salida sin números ni textos) | Unit, contra `integrations-cases.json` (tester, escrito a mano; firmas y huellas con una segunda derivación independiente con `openssl`, como el H5) |
| API | Cada fila de I4.3 y cada código de I7; idempotencia; evento del libro en la misma transacción; bandera apagada → 409 en las escrituras y 200 en las lecturas; matriz de 56 rutas × roles | Paquete S |
| PostgreSQL (puerta `ops/pgunit.sh`) | Disparadores (P0001) y CHECK (23514, incluido `uscn_four_eyes_chk` con un UPDATE crudo); índices parciales (23505); `uscn_cipher_only_shrinks`; `down()` que se niega. Carreras con barrera (`pg_blocking_pids` + escalón de 40 ms): dos registros del mismo número, dos asociaciones de la misma campaña, autorizar frente a revocar, cuatro activaciones contra un tope de 3 y reconocer frente a cambio de configuración | Tester |
| Mutaciones | Quitar: los cuatro ojos del servicio (el CHECK tiene que cazarla aparte), la regla de canal, el borrado del cifrado, el saneado de la sonda, la fijación de hosts, la constante de llamadas, el `try` de la coherencia (tiene que salir `unreadable`, nunca `in_sync`) y la lista blanca de métodos de Meta | Tester |
| Aislamiento | I9.1–I9.6 | Paquete S (1–3, 6), TW (4), V (5) |
| CRM | Specs del módulo y de cada componente, paridad con fixtures, texto hostil, permiso desconocido, compatibilidad con un backend H5, build y presupuesto de chunks, `check:literals` | FE |
| E2E con pila real | NUEVOS `specs/h6-integrations.cjs` y `specs/h6-seed.php` (cuentas de Meta, campañas y pasadas sembradas; la conversación canaria con un lead y su teléfono; sin ElevenLabs) más `mock-twilio.cjs` (:8095) y `mock-elevenlabs.cjs` (:8096). Flujos: estado con sus clasificaciones; campañas, asociar y desactivar; registrar (Administrador) → autorizar (Super Admin; el Administrador no ve el botón) → activar → `in_sync` cuando el número registrado es el del lead canario → revocar; Twilio con la sonda contra el simulador (saneada); voz en `ELEVENLABS_NOT_CONFIGURED`; capturas a 1440 y 390 px. **La valla vacía al cerrar** | Tester (arnés A10.5 del H5) |
| Seguridad | `api-security-specialist`: autorización por ruta, cuatro ojos por identidad, IDOR por uuid, oráculo de existencia de números, `isEngineCanary`, forma de las respuestas y de la sonda. `security`: datos personales (Ley 1581), cifrado y huella, rotación de la `APP_KEY`, SSRF, secretos (centinela) y que ningún middleware registre cuerpos con teléfonos | Tras el paso 2 (§6) |
| Línea base | Suite completa contra la base **el mismo día** y fallos de base documentados (memoria «56 fallos tolerados»; las de PG que ya fallaban) | Tester |

### I12. Configuración

| Variable | Clave | Defecto | Valores | Inválido |
|---|---|---|---|---|
| `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` | `ultron_studio.integrations.writes_enabled` | `false` | booleano (`filter_var`) | `false` |
| `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` | `ultron_studio.integrations.twilio_probe.enabled` | `false` | booleano | `false` |
| `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` / `_SECRET` | `…twilio_probe.key_sid` / `key_secret` | — | API key restringida (opcional; si están, mandan) | se ignoran |
| `ULTRON_STUDIO_TWILIO_PROBE_BASE` | `…twilio_probe.base` | — | URL; **se ignora en `production`** | se ignora |
| `ULTRON_STUDIO_CANARY_MAX_ACTIVE` | `ultron_studio.integrations.canary.max_active` | 3 | 1–10 (recorte) | 3 |
| `ULTRON_STUDIO_CANARY_COUNTRY_CODES` | `…canary.country_codes` | `57` | lista de prefijos numéricos | `57` |
| `ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE` | `…campaign_links.max_active` | 50 | 1–200 (recorte) | 50 |
| (las de voz) | `voice.*` | apagadas | §I6.2 | apagadas |

Mismo patrón que `config/ultron_studio.php` (`$entero`; una línea vacía nunca deja un tope a cero). `.env.example` gana estas variables y, comentadas y sin valores, las de Meta Ads y los alias de Twilio que hoy faltan (`META_ADS_ACCESS_TOKEN`, `META_ADS_GRAPH_VERSION`, `META_ADS_SYNC_ENABLED`, `META_AD_ACCOUNT_IDS`, `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_SEND_ENABLED`, `TWILIO_VERIFY_RATE_LIMIT_NAME`). La línea ajena `FACTUS_CONSUMER_FINAL_NAME` no se toca.

### I13. Datos personales (Ley 1581)

| Dato | Dónde | Protección |
|---|---|---|
| Teléfono de un canario de WhatsApp | `ultron_studio_canary_numbers` | Solo huella HMAC y 4 dígitos; no recuperable |
| Teléfono de un canario de voz | Ídem | Cifrado con la `APP_KEY`; **borrado al revocar** (lo exige un CHECK) |
| Alias | Ídem | Validado (sin series de dígitos ni `@`) y con aviso; **no** va al libro |
| Consentimiento | Ídem | Declaración de quien registra, con su identidad y la hora. El soporte del consentimiento lo custodia el propietario (pregunta 6) |
| Teléfono del lead canario | Memoria del proceso, en la coherencia | Solo para calcular la huella; nunca se guarda, se devuelve ni se registra |
| Llamadas (`marketing_calls`) | Tabla existente | El Studio no lee `to_phone`, `from_phone`, `transcript`, `summary` ni `metadata` |
| Datos de Twilio (sonda) | `facts` de la instantánea | Solo recuentos y los 4 dígitos de **los números del gimnasio** |
| Nombres del personal | Instantáneas de actor en las tres tablas y en el libro | Como F y H5. El procedimiento de redacción con el rol dueño (pendiente del Hito 7) tiene que cubrir también las 3 tablas nuevas |

---

## 6. Plan secuenciado por agentes

Etiquetas: **[‖]** puede ir en paralelo con los demás de su bloque; **[SEC]** pasa por `security`/`api-security-specialist`; **[TEST]** casos límite para `tester`.

**Contratos que se fijan ANTES de escribir código (paso 0):** los nombres de las 18 rutas y sus permisos (I7); las 4 llaves y quién las recibe; las formas JSON de I2, I3.3, I5.2, I6.7 e I7; el conjunto cerrado de clasificaciones, estados, veredictos y códigos de error; las firmas de `VoiceAgentProvider`, `VoiceCallPolicy`, `ElevenLabsWebhookSignature`, `TwilioReadOnlyClient`, `TwilioProbeSanitizer` y `PhoneFingerprint`; las columnas de las 3 tablas. Quien consume una interfaz la conoce antes de escribir.

**Paso 0 · [lead]** Decide el §11, congela `ULTRON_STUDIO_H6_CONTRACT.md` a partir del §5 y crea `feat/ultron-studio-h6` en los dos repos desde la rama del H5 (o desde `origin/main` si el H5 ya se fusionó). *Verificación:* contrato en un commit; ramas creadas desde el HEAD del día.

**Bloque 1 (en paralelo):**
- **1a · [database-specialist] [‖]** Las dos migraciones (I3.1, I3.2, I4.1, I5.4 y la ampliación de `usae_subject_type_chk`). *Verificación:* `migrate` y `migrate:rollback --step=2` limpios en SQLite y en el PG desechable; `down()` se niega con una fila de cualquiera de las tres tablas; recuentos de disparadores y CHECK en PG documentados frente a los de hoy (H5: 30 y 27); los tres parciales conservan su `WHERE` en los dos motores; ningún `ALTER` sobre tablas del motor ni `Schema::table` sobre `ultron_studio_versions`.
- **1b · [tester] [‖]** `integrations-cases.json` + README, escritos a mano desde el contrato (§I11, fila «Núcleo puro»). *Verificación:* el lead lo revisa contra el contrato antes del paso 2 (Enmienda 1 si hay que precisar algo, como en el H5).
- **1c · [backend · paquete V] [‖] [TEST]** `App\Services\Voice\**`, `config/voice.php`, `voice:doctor` y sus pruebas (I6). *Verificación:* Unit verde contra los casos de 1b en cuanto existan (antes, contra los suyos); `VoiceIsolationTest` verde; cero ficheros fuera de su lista (§7).
- **1d · [backend · paquete TW] [‖] [SEC] [TEST]** `TwilioReadOnlyClient`, `TwilioProbeSanitizer` y `TwilioReadOnlyClientTest` (I5.5, I9.4). *Verificación:* solo GET, hosts fijos en `production`, primera página, saneado contra el fixture.
- **1e · [frontend] [‖]** Capa de datos del CRM (tipos, servicio, lectores, textos y descriptores) con fixtures escritos desde el contrato y un spec de compatibilidad con un backend H5 (404 en `integrations/*`). *Verificación:* specs del módulo verdes.
- **1f · [tester] [‖]** Infraestructura del E2E: `mock-twilio.cjs`, `mock-elevenlabs.cjs`, `h6-seed.php` y los ajustes de `run.sh` (puertos 8095/8096; la valla sigue anotando todo lo que no sea `127.0.0.1`). *Verificación:* la pila arranca, la regresión del H5 sigue en verde y la valla está vacía.

**Paso 2 · [backend · paquete S] [SEC] [TEST]** (necesita 1a; consume las interfaces de 1c y 1d) Servicios, modelos, controladores, rutas (56), `AuthorizationMap`, permisos (`PermissionCatalog`, `CrmPermission`), `StudioAccess`, `StudioAudit`, configuración, códigos de error, el limitador `ultron-studio-probe` (solo en `limitadoresDelStudio()`, sin «arreglar» el Pint que ya falla en HEAD), exportación de fixtures, `ultron-studio:integrations-doctor`, `ultron-studio:integrations-snapshot`, `.env.example` y las pruebas de frontera (I9.1–I9.3 e I9.6). *Verificación:* Studio + Access + Chaos en SQLite; grupo del Studio en PG con `ops/pgunit.sh` (**puerta obligatoria**, la verifica el lead); `auth:coverage --unmapped` vacío; `route:list` = 56 en el Studio; con las llaves por defecto, toda escritura del hito responde 409 `integrations_writes_disabled` salvo `config/acknowledge`, y la sonda responde 409 `probe_disabled`; las suites de ULTRON y de Marketing idénticas a la base; puerta de diff del §8 vacía. *Casos límite:* activar contra el tope con dos sesiones; registrar el mismo número en los dos canales (se admite: son dos filas); revocar un número de voz (cifrado a NULL); una versión que pasa a `ROLLED_BACK` después de ser la esperada; la cuenta de Meta que deja de estar conectada con una asociación activa; la sonda con 401, con un tiempo agotado y con 5 min de enfriamiento; la coherencia con la tabla de leads sin la fila.

> **Primer punto verde: al cerrar el paso 2.** El backend completo pasa todas las suites con las llaves por defecto, el motor no ha cambiado ni un byte, ninguna ruta sale a la red y las escrituras del hito están cerradas.

**Paso 3 · [frontend]** (necesita 2 y 1e) Página, pestañas y diálogos (I10), con fixtures regenerados desde `ultron-studio:export-fixtures` y el catálogo de permisos desde `permissions:export`. *Verificación:* módulo del CRM verde; suite completa con sus fallos de base; build con chunks y hojas dentro de presupuesto; `ultron-studio.scss` y `studio-inspector.scss` intactos; revisión en Chrome a 1440 y 390 px.

**Paso 4 · [tester] [TEST]** (necesita 2 y 3) Carreras en PG, mutaciones, matriz completa y E2E `h6-integrations` con la pila real (I11). *Verificación:* informe con cifras y comandos; mutaciones cazadas; valla vacía.

**Paso 5 · [api-security-specialist] y [security] [‖]** (desde el paso 2; `security` repasa el CRM tras el 3). *Verificación:* hallazgos con ubicación; cada uno vuelve a su dueño; enmienda del contrato si cambian decisiones.

**Paso 6 · [devops] [‖]** (desde el paso 2):
- `deploy-h6-backend-fase1.sh`: aborta con cambios locales; `git` como `www-data` (M-3 del H5); copia del `.env` y Dotenv parseado antes de tocar cachés (B-1 del H5); variables **antes** de `ironbody-migrate`; `integrations-snapshot` al final.
- `h6-integrations-scope.sh writes on|off` y `probe on|off`: escribe la llave, hace `config:cache` con `umask 077`, `chown www-data` y `chmod 600`, reinicia los workers y compara la configuración efectiva sin imprimir secretos.
- `smoke-h6.py` y `smoke-run-h6.sh`: cuenta temporal, sin filas positivas en la fase 1.
- `h6-schema-check.sql`: recuentos de disparadores, CHECK e índices.

*Verificación:* los cuatro, ensayados contra la pila del E2E.

**Paso 7 · [reviewer]** (tras 4 y 5 cerrados) Revisión final independiente de los dos repos. **El hito no avanza al despliegue con un bloqueante abierto.**

**Paso 8 · [documentation-specialist] [‖]** (borrador desde el paso 2; cierre tras el 7) Enmiendas del contrato; `DEPLOYMENT_PLAN`, `ROLLBACK`, `MASTER_ROADMAP`; `META_REPORT`, `TWILIO_REPORT` (con la auditoría de I5.1) y `ELEVENLABS_READY` (con el procedimiento de I6.6); `FINAL`, `BACKEND`, `SECURITY` y `VISUAL`; `ops/README.md`.

**Paso 9 · [lead + devops]** Despliegue por fases (§8): la fase 1, con la orden expresa del usuario en la conversación de ese momento; la fase 2 y la sonda, cada una con su autorización del propietario.

---

## 7. Dueños de ficheros (ninguno con dos dueños a la vez)

| Paquete | Agente | Ficheros (exclusivos) |
|---|---|---|
| DB | `database-specialist` | `‹B›/database/migrations/2026_11_XX_10000{1,2}_*integration*.php` y su prueba de migración |
| V | `backend` (instancia 1) | `app/Services/Voice/**`, `config/voice.php`, `app/Console/Commands/Voice/**`, `tests/Unit/Voice/**`, `tests/Feature/Voice/**` |
| TW | `backend` (instancia 1, después de V, o instancia 2) | `app/Services/Twilio/**`, `tests/Unit/Twilio/**` |
| S | `backend` (instancia 2, o en serie tras V y TW) | `‹S›/**`, `app/Support/Privacy/PhoneFingerprint.php`, `app/Models/UltronStudio/**`, `app/Http/Controllers/Crm/UltronStudio/**`, `app/Console/Commands/UltronStudio/**`, `app/Exceptions/UltronStudioException.php`, `routes/ultron-studio.php`, `config/ultron_studio.php`, `app/Support/Access/{AuthorizationMap,PermissionCatalog,CrmPermission}.php`, `app/Providers/AppServiceProvider.php` (**solo** `limitadoresDelStudio()`), `.env.example`, `tests/{Unit,Feature}/UltronStudio/**` salvo los fixtures |
| T | `tester` | `tests/fixtures/ultron-studio/integrations-cases*`, pruebas adversariales nuevas (`*Race*`, `*Mutation*`), `Iron-Body_Front/e2e/ultron-studio/**` |
| FE | `frontend` | `‹F›/**` (nunca `ultron-studio.scss` ni `studio-inspector.scss`), `src/app/app.routes.ts` (una entrada), `src/app/models/permissions.enum.ts`, catálogo y fixtures regenerados |
| OPS | `devops` | `‹B›/docs/ultron-studio/ops/{deploy-h6-backend-fase1.sh,h6-integrations-scope.sh,smoke-h6.py,smoke-run-h6.sh,h6-schema-check.sql}` |
| DOC | `documentation-specialist` | `‹B›/docs/ultron-studio/*.md` (salvo este plan y el contrato, que son del lead) y `ops/README.md` |

**Traspasos:** V y TW **no** tocan `.env.example`: pasan a S la lista de sus variables. S es el único que toca `AppServiceProvider.php`, y solo en ese método. `routes/console.php` **no** se toca en H6: la instantánea programada es del Hito 7. Si una sola instancia de `backend` hace V, TW y S, va en ese orden y con un commit por paquete.

---

## 8. Despliegue por fases

**Requisito previo:** el Hito 5 desplegado al menos en su fase 1, porque esta rama lo contiene. Si el H5 no puede desplegarse, el H6 espera.

**Puerta antes de cualquier fase:** Studio + Access + Chaos en SQLite; grupo del Studio en PG con `ops/pgunit.sh`; suites de ULTRON, Marketing y OTP iguales a la base; `auth:coverage` vacío; 56 rutas; Pint en lo tocado; módulo del CRM y build; E2E `h6-integrations` y regresión del H5 en verde con la valla vacía; `security`, `api-security-specialist`, `tester` y `reviewer` cerrados; respaldo preparado. Y la **puerta de diff**:

```
git diff <base> --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api app/Services/Sms app/Services/Otp \
  routes/api.php routes/marketing.php routes/console.php config/marketing.php config/meta.php config/otp.php \
  app/Services/UltronStudio/StudioCatalog.php        # → vacío
```

### Fase 1 · código desplegado, escrituras y sonda apagadas (solo lectura)

Requiere la orden expresa del usuario de push y despliegue en la conversación de ese momento.

1. Respaldo en `/var/backups/ironbody/ultron-studio-h6-<fecha>/` (tablas `ultron_studio_*`, el árbol desplegado y el `.env`, en 700 como pide el informe del 31-10 §3c).
2. **Variables ANTES de `ironbody-migrate`:** `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false`, `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`, `ULTRON_STUDIO_CANARY_MAX_ACTIVE=3`, `ELEVENLABS_ENABLED=false` y `TWILIO_VOICE_ENABLED=false`, escritas explícitas aunque coincidan con el defecto, como en el H5.
3. Código; `sudo /usr/local/bin/ironbody-migrate` (2 migraciones; recuentos contra el informe de `database-specialist` con `h6-schema-check.sql`).
4. `route:cache` como `www-data`, `chown` y `chmod 600` de las cachés, y reinicio de los workers: todos RUNNING.
5. CRM con `ops/deploy-crm.sh` (rsync **sin** `--delete`).
6. `php artisan ultron-studio:integrations-snapshot`: la primera instantánea de sistema de cada proveedor (el «observado» inicial).
7. `smoke-run-h6.sh` (fase 1, sin filas positivas):
   - 56 rutas.
   - `integrations-doctor --expect=meta_ads:connected_read,twilio:connected_otp_only,elevenlabs:ELEVENLABS_NOT_CONFIGURED` → 0. Si Meta sale `degraded`, se informa tal cual: es un dato, no un fallo del despliegue.
   - Las escrituras → 409 `integrations_writes_disabled` (salvo reconocer la configuración, que no depende de la bandera y el smoke no llama); la sonda → 409 `probe_disabled`.
   - `GET integrations/canaries` → lista vacía y coherencia `engine_canary_not_registered`.
   - `GET integrations/meta/campaigns` → campañas con su antigüedad.
   - `GET integrations/voice` → `ELEVENLABS_NOT_CONFIGURED` y `policy.allowed=false`.
   - `GET integrations/config` → `never_authorized` con un `observed`.
8. **Controles de no regresión** (solo lectura):
   - Sonda del contrato n8n↔backend sin efectos, como en el H5: el bearer se lee del entorno y no se imprime.
   - `meta_sync_runs` sigue sumando pasadas a su hora.
   - El webhook `usage-trigger` responde 403 a un POST sin firma, como antes.
   - El arranque de un OTP de prueba **no** se hace: no se gasta SMS para comprobar algo que no se tocó.

Estado en la hoja de ruta: «implementado y desplegado en fase 1 (solo lectura)».

### Fase 2 · escrituras encendidas: T1 y configuración autorizada (autorización del propietario)

1. `h6-integrations-scope.sh writes on`.
2. **T1:** la persona que tiene el teléfono de la conversación canaria lo registra (`whatsapp`); otra persona con `ultron.canary_authorize` lo autoriza; se activa. Comprobación: veredicto `in_sync` y libro con `registered`, `authorized` y `activated`. Con un mismo autor para las dos cosas, la API y la base lo impiden (es el objetivo).
3. Un Super Admin reconoce la configuración de `meta_ads`, `twilio` y `voice` → `state: authorized`. Puede hacerse ya en la fase 1, porque no depende de la bandera (pregunta 11).
4. **Opcional, con su propia autorización:** `h6-integrations-scope.sh probe on` → una pulsación de la sonda → la evidencia responde a «¿hay número de voz o solo caller ID?». La bandera se deja como decida el propietario.
5. Asociar campañas no es condición de la fase: el E2E ya probó el flujo. Si el propietario lo quiere, son filas reales.

**`HITO_6_COMPLETED`** (pregunta 10) con las fases 1 y 2 hechas y la evidencia en los informes, y D preparado en `ELEVENLABS_NOT_CONFIGURED`. Si falta la segunda persona, la autorización o el despliegue previo del H5: **`HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**.

### Reversión (para `ULTRON_STUDIO_ROLLBACK.md`)

| Nivel | Cómo | Efecto |
|---|---|---|
| 1 · Studio | Desactivar asociaciones y revocar números (`ultron.*`) | El registro deja de declarar. El motor no notaba nada antes ni lo nota después |
| 2 · Banderas | `h6-integrations-scope.sh writes off` y `probe off` | Escrituras y sonda cerradas al instante, tras la caché de configuración |
| 3 · Código | Volver al backend del H5 y al CRM anterior | Seguro con filas del H6: las tablas se quedan sin lector y el libro con sujetos que el H5 no filtra (los pinta en genérico) |
| 4 · Esquema | `down()` | Solo sin filas del H6; con filas, **prohibido** (no se destruyen el registro, las instantáneas ni el libro) |

---

## 9. Riesgos y puntos de no retorno

| # | Riesgo | Probabilidad / impacto | Mitigación |
|---|---|---|---|
| R1 | **Confusión entre canarios:** se cree que activar un número en el registro hace que ULTRON lo atienda | Media / medio | Texto obligatorio; sufijo «(registro)»; veredicto de coherencia siempre visible; el motor no lee la tabla (prueba de frontera) |
| R2 | **Datos personales** de los números canarios | Media / alto (Ley 1581) | Huella y 4 dígitos; cifrado solo en voz y borrado al revocar; consentimiento declarado; nada en el libro; prueba centinela |
| R3 | **Coste de voz** | Baja / alto | No existe código que llame (`CALLS_ENABLED_IN_CODE=false`, sin método en el puerto, sin ruta); banderas apagadas; trampa de `marketing_calls.pending` documentada en `VoiceCallPolicy` |
| R4 | **SSRF en URL configurables** | Baja / alto | El CRM no acepta ninguna URL; la sonda fija hosts en `production`; región de ElevenLabs en lista cerrada; Graph fijo. La exposición previa de `TWILIO_BASE`/`VERIFY_BASE` queda anotada para `security` (T-6) |
| R5 | **Secretos en el CRM** | Baja / alto | Solo huellas de 8 hex y referencias; no hay edición; prueba centinela sobre respuestas, libro, instantáneas y log |
| R6 | **Límites de Meta** | Nula | El Studio no llama a Graph |
| R7 | **Abuso de la sonda** contra Twilio | Baja / bajo | Bandera, permiso de Super Admin, cupo propio, enfriamiento de 5 min, solo GET y primera página, y libro |
| R8 | **Rotación de la `APP_KEY`** | Baja / medio | Huellas, cifrados e ids opacos dejan de valer; se vuelven a registrar los números. Al runbook junto a E2.4 del H5 |
| R9 | **Dato viejo de Meta** tomado por actual | Media / bajo | Antigüedad por campaña, `stale` por cuenta y `possiblyDeleted` |
| R10 | Que una asociación de campaña se lea como un cambio en Meta o en ULTRON | Media / bajo | Dos insignias separadas y el texto obligatorio |
| R11 | **El H5 no se despliega** y el H6 se queda detrás | Media / bloquea el cierre | Es la dependencia escrita en el §8; el trabajo del H6 avanza igual hasta el paso 7 |
| R12 | Sin segunda persona en producción para los cuatro ojos | Media / bloquea T1 | Dependencia externa; con la cuenta de smoke solo para el ensayo, nunca para T1 real |
| R13 | Tentación de añadir las integraciones al catálogo | Baja / medio | Prohibido (P8); el catálogo está fijado por cuatro fixtures; precedente D-5 |
| R14 | El CHECK ampliado del libro bloquea un instante `ultron_studio_audit_events` | Baja / bajo | Una sentencia; `lock_timeout` de 5 s; si falla, se reintenta |
| R15 | SQLite reconstruye una tabla nueva y pierde el `WHERE` de un parcial | Baja / medio | Ninguna migración posterior con `Schema::table` sobre estas tablas; la prueba lo comprueba en los dos motores |
| R16 | La coherencia carga el teléfono del lead canario en el proceso web | Baja / medio | Dos consultas puntuales; `unset` tras la huella; nunca a log ni a respuesta; revisión de `security` |
| R17 | Carrera en el tope de activos | Baja / bajo | Cerrojo consultivo por canal con `READ COMMITTED` primero; carrera forzada en PG |
| R18 | Dos instancias de `backend` | Media / medio | Tabla de dueños (§7) con traspasos en serie; el reviewer comprueba la puerta de diff |
| R19 | La sonda con la credencial maestra | Baja / medio | GET, hosts fijos y primera página; preferencia por una API key restringida (pregunta 1) |

**Puntos de no retorno:**
- **El primer número canario registrado** deja datos personales en una tabla cuyas filas no se borran. Mitigación: la huella no es reversible, el cifrado se borra al revocar y el procedimiento de redacción con el rol dueño (Hito 7) tiene que cubrir esta tabla.
- **Las primeras instantáneas y asociaciones** dejan filas de solo inserción con nombre y rol del personal, como F y H5.
- **Con filas del H6, `down()` deja de ser una opción.** Volver atrás el código sigue siendo seguro.
- **En el motor no hay ninguno:** no se ha tocado.

---

## 10. Fuera de alcance

- **Que el motor lea el registro de canarios** (T2/T3), cualquier cambio de `MARKETING_ULTRON_CANARY_CONVERSATION_ID` o del canario del cobro, y abrir el tráfico.
- **Llamadas de voz de cualquier tipo**, el webhook de TwiML, el post-llamada como ruta pública, la extracción de `TwilioSignatureValidator`, la subcuenta de voz, el Custom LLM y las server tools.
- **Escrituras en Meta** (presupuestos, campañas, anuncios, segmentación), un botón de sincronizar en el Studio y ampliar la sincronización a campañas sin gasto (pregunta 8).
- **Que el motor lea las asociaciones de campaña** (p. ej., sellar en cada turno la campaña asociada por la atribución): sería un contrato `ultron-engine-config/2` o equivalente, con su propio canario.
- **Persistir los avisos de `usage-trigger`** (pregunta 12) y una tarea programada de instantáneas o de sonda (monitoreo del Hito 7).
- **WhatsApp Cloud API** (estado del número y `MetaDoctorService`): ya lo enseña su pantalla; no es parte del alcance A–D.
- **Recuentos de leads por campaña** en el Studio: ya los da el panel de Mercadeo con su permiso; traerlos aquí duplicaría la puerta del dinero.
- **Pendientes del propietario del Hito 7** y los diferidos de la Enmienda 2 del H5 (`no-store`, invisibles, `seq` envenenado, `ironbody-migrate` como root…): no se absorben.

---

## 11. Preguntas abiertas (cada una con mi recomendación)

1. **Sonda de Twilio en H6 (B2) o solo lectura local (B1)?** → **B2, con la bandera apagada en la fase 1** y encendida en la fase 2 solo con autorización del propietario. Es la única forma de contestar sin llamar a nadie si hay número de voz o solo caller ID, y de ver el estado real de la cuenta y sus alertas. Recomiendo que el propietario cree una API key restringida de solo lectura para la sonda; si no la crea, se usa la credencial del OTP.
2. **¿«Asociar una versión» a un número es una expectativa auditada (A), una versión derivada sin almacenar (B) o una puerta de publicación (C)?** → **A.** Una sola autoridad (la cadena del H5) y una expectativa que se compara; C acopla H5 ↔ H6 y B no cumple el encargo.
3. **La hoja de ruta de la transición: ¿T2 como restricción adicional (`env_and_registry`) antes de T3 (sustitución)?** → **Sí.** H6 solo implementa T0 y T1; T2 y T3 necesitan cada una su autorización y su canario.
4. **Permisos:** 4 llaves nuevas; el Administrador recibe `campaigns_manage` y `canary_manage`, pero **no** `canary_authorize` ni `integrations_manage`. → **Sí.** Mismo criterio que `ultron.publish` y que `integrations.manage`.
5. **Activar un número ya autorizado: ¿basta `canary_manage` o exige `canary_authorize`?** → **`canary_manage`.** Los cuatro ojos ya se aplicaron al autorizar y en H6 activar no tiene efecto en el motor. Si se quiere más rigor, que activar también exija ≠ quien registró.
6. **Minimización:** WhatsApp sin cifrado (solo huella y 4 dígitos); voz cifrada y borrada al revocar; consentimiento como declaración de quien registra, con el soporte custodiado por el propietario. → **Sí.** El procedimiento de supresión del Hito 7 cubre la tabla.
7. **Destino de la asociación de campaña:** línea base o versión `APPROVED|CANARY|PUBLISHED`, sin «estrategia» en texto libre ni planes. → **Sí.** La estrategia ya va dentro de la versión (capa `strategy`); un texto libre sería un riesgo de datos personales en una tabla que no se borra, y los ids de planes no salen del Studio por diseño.
8. **¿Ampliar la sincronización de Meta para que entren las campañas sin gasto?** → **No en H6.** Toca `MetaAdsSync` y la semántica de la dimensión que usan el panel y la conciliación. Si el propietario necesita asociar antes de gastar, es un cambio propio con su conciliación.
9. **Webhook post-llamada de ElevenLabs:** ¿solo el verificador puro, sin ruta, en H6? → **Sí** (§4.6).
10. **Criterio de cierre:** `HITO_6_COMPLETED` con las fases 1 y 2 (T1 `in_sync`, configuración reconocida y panel en vivo) y D preparado en `ELEVENLABS_NOT_CONFIGURED`; si falta la segunda persona, la autorización o el despliegue del H5, `HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`. → **Sí.** El encargo dice que se mantenga `ELEVENLABS_NOT_CONFIGURED` sin credenciales verificadas, así que eso no puede impedir el cierre.
11. **Una sola bandera de escrituras para todo el hito, con el reconocimiento de la configuración fuera de ella.** → **Sí.** Reconocer no cambia nada y deja constancia desde la fase 1; las demás escrituras crean datos de personas o de gobierno y esperan a la fase 2.
12. **¿Persistir los avisos de `usage-trigger`?** → **No en H6.** Cambiaría un webhook que funciona; la sonda ya ve las alertas de Twilio. Si se quiere, en el Hito 7 y envuelto para que un fallo de la base no cambie la respuesta a Twilio.
13. **Por verificar en el servidor antes de congelar (solo lectura, sin imprimir valores):**
    - `OTP_DRIVER`;
    - si `TWILIO_SID` = `TWILIO_ACCOUNT_SID` y `TWILIO_TOKEN` = `TWILIO_AUTH_TOKEN`, comparando huellas;
    - `META_AD_ACCOUNT_IDS` vigente y la última pasada con éxito;
    - filas en `marketing_calls`;
    - el almacén de la caché (idempotencia del webhook).

    → Lo hace el lead o `devops`. Ninguna respuesta cambia el diseño, pero sí lo que dirá el smoke.
14. **Orden respecto al H5:** el H6 nace de la rama del H5 y su fase 1 va después de la fase 1 del H5. → **Sí.**

---

## 12. Decisiones que necesito del lead

Las que cambian el plan son la **1** (B1 quita el paquete TW, una ruta y el `kind=probe`), la **2** (semántica de la versión del canario), la **4** (permisos, que fijan la matriz de pruebas) y la **10** (criterio de cierre). El resto tiene recomendación por defecto y se puede aceptar en bloque: 3, 5, 6, 7, 8, 9, 11, 12 y 14. La 13 son comprobaciones de solo lectura que conviene hacer antes de congelar el contrato.

Recordatorios que no son decisiones: este plan no autoriza ningún push ni despliegue. La fase 1 necesita la orden expresa del usuario; la fase 2 y la sonda, además, la autorización del propietario para cada una. Nada de esto hace llamadas, escribe en Meta, activa ElevenLabs, gasta dinero ni toca el motor de ULTRON.

**Fuentes externas consultadas hoy (31-10-2026):**
- Post-call webhooks: <https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks>
- Webhooks: <https://elevenlabs.io/docs/product-guides/administration/webhooks>
- Register Twilio calls: <https://elevenlabs.io/docs/agents-platform/phone-numbers/twilio-integration/register-call>
- API register-call: <https://elevenlabs.io/docs/api-reference/integrations/twilio/register-call.md>
