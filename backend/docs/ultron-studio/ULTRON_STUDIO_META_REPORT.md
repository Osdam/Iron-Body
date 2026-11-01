# ULTRON STUDIO: campañas Meta (estado)

**Fecha:** 17-10-2026; actualizado el 01-11-2026 con el Hito 6. **Estado: IMPLEMENTADO EN RAMA (Hito 6), PENDIENTE DE DESPLIEGUE.** La rama `feat/ultron-studio-h6` del backend (`e3a0fdd`) lee las campañas de Meta Ads del **espejo local** que ya llena la sincronización existente, y registra en el Studio su asociación con la línea base o con una versión. **No llama a Meta, no escribe en Meta y no cambia lo que ULTRON dice o hace.** No está en `origin/main` ni en producción. Contrato: `ULTRON_STUDIO_H6_CONTRACT.md` (§I3, con I2, I7.2 e I8); runbook: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 6»); detalle técnico: `ULTRON_STUDIO_BACKEND_REPORT.md` («Hito 6»). «Pautas» = campañas de Facebook e Instagram en Meta Ads Manager.

## Clasificación: conectada en lectura

| Área | Clasificación | Evidencia |
|---|---|---|
| Meta Ads | **Conectada en lectura** (`connected_read`) | Contrato, «Clasificación al congelar» (31-10-2026, lead): cliente solo GET (`MetaAdsApiClient.php:559-593`), 3 cuentas y pasada horaria. Matriz de credenciales del 01-11-2026 (`plans/informes-h6/matriz-credenciales-vps.md`, sin imprimir valores): `META_ADS_ACCESS_TOKEN` disponible y validado (504 pasadas `ok` en 7 días; la última, a las 01:15 UTC de ese día), `META_ADS_SYNC_ENABLED=true` y `META_AD_ACCOUNT_IDS` con 3 cuentas |

La clasificación la recalcula el Studio en cada lectura (contrato I7.2): `not_configured` si la configuración de Meta tiene un problema (falta el token o las cuentas, una cuenta o la zona horaria no son válidas); `connected_read` si la sincronización de cada cuenta está `ok` o `running`; `degraded` con `stale` (más de 180 minutos sin una pasada con éxito), `failed` o la sincronización apagada (`sync_disabled`). En el despliegue, `degraded` es un dato, no un fallo.

## Qué hace el Hito 6

- **Lectura sin red** (`StudioMetaCampaigns`). Lee las campañas de las cuentas conectadas (`meta_ad_entities` con `level = campaign`), los nombres de cuenta, el estado de la sincronización por cuenta (`MetaAdsSync::statusAll()`) y la última pasada con éxito (`meta_sync_runs`). **Lista blanca de métodos de Meta**, vigilada por una prueba estática (`UltronStudioEngineReadOnlyTest`): `configurationProblem`, `accountIds`, `statusAll`, `forConfiguredAccount`, `accountNames`, `opaqueId`, `publicAccount`, `maskId` y `maskDigits`. Prohibidos, entre otros, `campaigns`, `insights`, `run`, `runAll`, `refreshReach` y `refreshStatuses` (contrato I3.3). La mutación que llama a `refreshStatuses()` desde la lectura la caza esa prueba (I7 del tester).
- **Ids opacos.** La API acepta y devuelve solo `campaign:<16 hex>` y `account:<16 hex>`, un HMAC con la `APP_KEY`; **el id de Meta no sale del servidor**. La referencia legible es `***` y los 4 últimos dígitos, y las series de dígitos de los nombres salen enmascaradas.
- **Dos estados que nunca se mezclan** (contrato I3.5): «En Meta» (el `effectiveStatus` del último dato conocido, su antigüedad, «Dato viejo» si la cuenta está `stale` o `failed` y «Posiblemente eliminada» si la campaña no se refrescó en la última pasada de insights con éxito) y «En ULTRON Studio» («asociación activa (sin efecto en el motor)» o «Sin asociación»).
- **Asociación campaña ↔ ULTRON** (`ultron_studio_campaign_links`). Una fila es un periodo de asociación: el historial son filas, nunca ediciones.
  - Destino: la línea base o una versión `APPROVED`, `CANARY` o `PUBLISHED`, íntegra. Sin texto libre ni planes.
  - Como mucho una asociación activa por campaña (índice único parcial `uscl_one_active`) y 50 activas en total (`ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE`, de 1 a 200).
  - Sustituir deja la anterior `inactive` apuntando a la nueva (`uscl_replacement_once`, D-1 del informe de datos). Desactivar exige un motivo de 5 a 500 caracteres, **sin teléfonos ni correos** (AS-1: 422).
  - Sin cuatro ojos: una asociación no cambia Meta ni el motor, no gasta y no lleva datos personales.
  - Idempotencia por `clientRequestId` (el mismo cuerpo, 200 `replayed`; otro, 409 `client_request_reused`).
  - Eventos `campaign_link.created` y `campaign_link.deactivated` en el libro, en la misma transacción, sin ids de Meta en claro ni nombres de campaña.
- **Rutas:** 40 `GET integrations/meta/campaigns` (filtros por estado, asociada o no y cuenta; `page` hasta 100 000, AS-2), 41 `GET integrations/meta/links`, 47 `POST integrations/meta/links` y 48 `POST integrations/meta/links/{uuid}/deactivate`; y la tarjeta de Meta de la 39, `GET integrations/status`.
- **Permisos:** leer, `ultron.view`; asociar y desactivar, `ultron.campaigns_manage` («Asociar campañas de Meta»; el Administrador la tiene por defecto). Las escrituras, además, tras `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` (apagada en la fase 1: 409 `integrations_writes_disabled`).
- **Sin configuración, nunca datos de demostración:** `connection.state = not_configured` con su motivo, y las listas vacías (contrato I3.4).
- **Texto obligatorio en el CRM:** «La asociación queda registrada en el Studio. No cambia nada en Meta ni en lo que ULTRON dice o hace.»

## Qué no hace

- **No llama a la API Graph** ni lanza la sincronización: la sigue haciendo el planificador existente, y el Studio solo lee lo que ya está en la base.
- **No crea, pausa ni edita** campañas, anuncios, presupuestos ni segmentación. Sin permisos de escritura en Meta (el token sigue con solo `ads_read`, matriz de credenciales).
- **No cambia lo que ULTRON dice o hace:** el motor no lee las asociaciones (frontera del Hito 6; `UltronEngineIsolationTest`).
- **No añade atribución:** el Hito 6 no toca cómo se atribuye un lead a una campaña.
- **No lista campañas sin gasto** que no estén en el espejo (decisión 8 del contrato).
- **Falso positivo aceptado:** si en una pasada falla el borde de campañas, las que no gastaron en la ventana salen «Posiblemente eliminada» hasta la pasada siguiente. La etiqueta dice «posiblemente».

## Evidencia (en la rama)

- Paridad con el fixture del contrato: `metaClassification` 39 casos (22 puros y 17 por HTTP) y `campaignLinkRules` 64 (por HTTP), en verde contra las clases reales (`plans/informes-h6/informe-api.md` §4.1).
- Carreras reales en PostgreSQL (tester de backend): dos asociaciones de la misma campaña, el tope global con su cerrojo, la sustitución concurrente y el doble clic con la misma `clientRequestId` (`informe-tester-backend.md` §3).
- E2E: la semilla del arnés siembra 3 cuentas sintéticas y 12 campañas, con una cuenta vieja y casos de «posiblemente eliminada» (`informe-e2e-arnes.md`). [PENDIENTE: el escenario de campañas del E2E del Hito 6 (asociar a la línea base, sustituir por una versión y desactivar).]
- [PENDIENTE: la lectura en producción tras la fase 1 (smoke: campañas con su antigüedad; `integrations-doctor` con `meta_ads:connected_read`).]

**Rotar la `APP_KEY`** cambia todos los ids opacos: el CRM tiene que recargar, y las asociaciones sobreviven porque guardan el id de Meta (`ULTRON_STUDIO_ROLLBACK.md`, «Hito 6»).

---

## Diseño del 17-10-2026 (histórico)

*Así se escribió el 17-10-2026, con el estado «NO IMPLEMENTADO». Lo que llamaba «Hito K» es la parte de Meta del Hito 6. Cambió una cosa: el contrato del Hito 6 prohíbe los datos de demostración (abajo, el último punto); sin configuración, la lista sale vacía con su motivo.*

**Lo que existe hoy (verificado el 17-10-2026):**
- **Lectura de Meta Ads** con token propio y solo `ads_read` (el comando de comprobación falla si trae otro permiso). Cliente solo GET: insights por anuncio, alcance, campañas, conjuntos y anuncios con `effective_status`. Sincronización horaria a `meta_ad_entities`, `meta_ad_insights_daily`, `meta_ad_reach_snapshots`. El CRM ya tiene el panel «Mercadeo digital».
- **Sin escritura en Meta Ads.** Las únicas escrituras en Graph son de WhatsApp (mensajes, medios, suscripción).
- **Atribución:** solo el `referral` de Click-to-WhatsApp (`ad_id`, `ctwa_clid`), confianza alta con anuncio y clic; lo demás `unknown`. La campaña se resuelve al leer contra las cuentas conectadas.
- **No existe** el concepto «campaña autorizada para ULTRON» ni una tabla que vincule campaña ↔ plan/estrategia/versión. El estado es el de la última pasada (viejo a los 180 min).

**Lo que hará el Hito K:**
- Conector **solo lectura** reutilizando la sincronización existente: consultar campañas, estado real (con su antigüedad), seleccionar una, asociar estrategia y versión, activar o desactivar la **asociación comercial dentro de ULTRON**, trazabilidad del vínculo.
- **Diferenciar siempre** «campaña activa en Meta» de «asociación activa en ULTRON».
- **Nunca** crear, pausar ni editar anuncios, presupuestos ni segmentación. Sin permisos de escritura.
- **Atribución:** usar el referral cuando exista; si no, «origen desconocido». Nunca adivinar la campaña por el teléfono.
- Si no hay permisos o token: datos de demostración claramente marcados y sin afirmar conexión real.
