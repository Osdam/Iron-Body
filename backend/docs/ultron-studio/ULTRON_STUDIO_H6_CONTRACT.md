# ULTRON STUDIO · Hito 6 (integraciones comerciales): contrato congelado

**Fecha:** 31-10-2026. **Base:** backend `feat/ultron-studio-h5` en `1bd0537` (Hito 5 implementado en rama, sin desplegar; el código es el de `cf59cef`: los commits posteriores son solo documentación y operación) y CRM `feat/ultron-studio-h5` en `9fe1817`. Producción = Hito 4: API `7318122`, CRM `4105016`. **Rama de trabajo:** `feat/ultron-studio-h6` en los dos repos, desde la rama del H5 (o desde `origin/main` si el H5 ya se fusionó). **Origen:** plan del `architect` `plans/ULTRON_STUDIO_H6_PLAN.md`. El diseño I1–I13 de abajo es el que se implementa y **manda sobre el plan** donde difieran. Está escrito para que cada paquete lo implemente **sin leer el plan**.

**Rutas.** `‹B›` = `Iron-Body/backend`; `‹U›` = `‹B›/app/Services/Marketing/Ultron`; `‹S›` = `‹B›/app/Services/UltronStudio`; `‹F›` = `Iron-Body_Front/src/app/modules/ultron-studio`.

## Decisiones del lead (preguntas §11 del plan)

| # | Pregunta | Decisión |
|---|---|---|
| 1 | Sonda de Twilio | **B2.** Bandera apagada en la fase 1. Credencial: API key restringida si el propietario la crea (`ULTRON_STUDIO_TWILIO_PROBE_KEY_SID/_SECRET`); si no, la del OTP. Solo GET, hosts fijos en `production`, primera página, enfriamiento de 5 min, cupo propio, permiso `ultron.integrations_manage` y evento en el libro |
| 2 | «Asociar una versión» a un número canario | **A:** expectativa auditada, comparada con la cadena del H5; **nunca** autoridad |
| 3 | Transición del canario | H6 implementa **T0 y T1**. T2 y T3 quedan descritas como fases futuras, cada una con su autorización |
| 4 | Permisos | 4 llaves. El Administrador recibe `ultron.campaigns_manage` y `ultron.canary_manage`; `ultron.canary_authorize` y `ultron.integrations_manage`, solo Super Admin por defecto |
| 5 | Activar un número autorizado | Basta `ultron.canary_manage`. **Además, activar exige que la persona sea distinta de quien registró**: es trivial, con un CHECK gemelo del de autorizar (`uscn_activation_four_eyes_chk`, §I4.2) y la misma comprobación por identidad en el servicio |
| 6 | Minimización | WhatsApp: solo huella HMAC y 4 dígitos. Voz: cifrado y borrado al revocar. Consentimiento declarado |
| 7 | Destino de la asociación de campaña | Línea base o versión `APPROVED`, `CANARY` o `PUBLISHED`; sin texto libre ni planes |
| 8 | Campañas sin gasto en el espejo | **No** en H6 |
| 9 | Webhook post-llamada de ElevenLabs | Verificador puro de la firma, **sin ruta pública** |
| 10 | Criterio de cierre | `HITO_6_COMPLETED` con las fases 1 y 2 hechas y D en `ELEVENLABS_NOT_CONFIGURED`. Si falta la segunda persona o el despliegue del H5: `HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` |
| 11 | Bandera de escrituras | Una sola para todo el hito. Reconocer la configuración queda fuera de ella |
| 12 | Persistir los avisos de `usage-trigger` | **No** en H6 |
| 13 | Comprobaciones de producción | Hechas por el lead (abajo, «Hechos de producción al congelar») |
| 14 | Orden respecto al H5 | El H6 nace de la rama del H5 y su fase 1 va **después** de la fase 1 del H5 |

**Hechos de producción al congelar** (31-10-2026; el lead, solo lectura y sin imprimir valores; más el informe `plans/informes-h5/informe-recuperacion-2026-10-31.md` §3):
- **Studio y despliegue:**
  - Studio = Hito 4 (33 rutas), PostgreSQL 14.24, 1 borrador y **0 versiones**; el H5 no está desplegado.
  - ULTRON encendido, cobro apagado y canario de conversación configurado.
  - El árbol de `/var/www/api` y su `.git` son de **root** desde el 25-10 (I-3 del reviewer del H5).
- **Twilio:**
  - `OTP_DRIVER=twilio`.
  - `CACHE_STORE=database`: la idempotencia del webhook `usage-trigger` vive en la base, no en memoria.
  - `TWILIO_SID` = `TWILIO_ACCOUNT_SID` y `TWILIO_TOKEN` = `TWILIO_AUTH_TOKEN` (huellas iguales): es la **misma credencial con dos nombres**.
  - Ninguna variable `TWILIO_VOICE_*`. `marketing_calls` tiene **0 filas**.
- **Meta Ads:** `META_AD_ACCOUNT_IDS` con **3 cuentas** y `META_ADS_SYNC_ENABLED=true`. `meta_ad_entities` tiene **114 filas** y la última pasada de `meta_sync_runs` fue hoy a las 21:15 UTC.
- **ElevenLabs:** ninguna referencia en el entorno.

**Clasificación al congelar** (lo que el Studio tendrá que afirmar, y con evidencia, en la fase 1):

| Área | Clasificación | Evidencia |
|---|---|---|
| Meta Ads | **conectada en lectura** | Cliente solo GET (`MetaAdsApiClient.php:559-593`, una sola llamada `Http::withToken(...)->get(...)` en `:568-571`); 3 cuentas; pasada horaria de hoy |
| Twilio (OTP) | **conectada solo para OTP** | Verify por SMS (`TwilioVerifyService.php:22-28`, `:90-110`) y Messages solo para el código de vínculo socio↔personal (`TwilioSmsSender.php:67-70`) |
| Twilio Voice | **no configurada** | Sin variables, número, webhook ni código |
| Números canarios | **no configurada** como registro | El canario es un id de conversación (`config/marketing.php:83-86`), que sí está configurado |
| ElevenLabs | **no configurada** (`ELEVENLABS_NOT_CONFIGURED`) | Cero código, cero credenciales |

**Autorizaciones.** El 25-10-2026 el propietario concedió **autorización completa para ULTRON STUDIO** (commits, push, despliegues por fases y cierre de agentes sin preguntar), con **límites absolutos**: sin llamadas reales, sin escribir en Meta, sin activar ElevenLabs, sin gasto sin control, sin sustituir ni tocar el motor, y sin `down()` en producción. Bajo esa autorización, **las fases 1 y 2 del H6 y la sonda GET (coste cero) se ejecutan cuando cumplan sus criterios** (§«Despliegue»), documentando cada paso. **Siguen exigiendo autorización expresa aparte:** T2 y T3 (que el motor lea el registro), cualquier llamada, cualquier escritura en Meta y encender ElevenLabs.

**Protecciones intocables:**
- **Cero líneas en el motor** (`app/Services/Marketing/**`, `app/Jobs/**`, `app/Http/Controllers/Api/Internal/**`, `routes/marketing.php`, `config/marketing.php`) **ni en las integraciones existentes** (`app/Services/Sms/**`, `app/Services/Otp/**`, `TwilioUsageTriggerController`, `routes/api.php`, `routes/console.php`, `config/meta.php`, `config/otp.php`).
- El motor no lee nada nuevo. El Studio no escribe fuera de `ultron_studio_*`.
- **Cero red por defecto.**
- **Catálogo congelado** (`StudioCatalog::VERSION = '2026-10-19.1'`).
- **Sin dependencias nuevas** de composer ni de npm.
- **Migraciones aditivas:** `down()` se niega con filas y nunca se ejecuta en producción.
- **Libro e instantáneas de solo inserción.** Ningún dato personal en claro en los libros (Ley 1581).
- La puerta de PostgreSQL (`docs/ultron-studio/ops/pgunit.sh`) es obligatoria antes de desplegar.

**Ajustes del lead al plan:**
1. Activar exige además ≠ quien registró (decisión 5).
2. Nombres de las migraciones: `2026_11_01_100001_create_ultron_studio_integration_tables.php` y `2026_11_01_100002_add_ultron_studio_integration_guards.php`. Si se implementa otro día, conservan esta fecha: lo que importa es que ordenen después de `2026_10_25_100002`.
3. Regiones de ElevenLabs: lista cerrada **solo** con `default` y `eu`, las dos únicas URL confirmadas hoy en la documentación oficial; otra región exige una enmienda con su URL verificada ese día.
4. Despliegue: `git` **como root con `umask 022`**, y tras el merge se comprueba que `www-data` lee cada fichero cambiado. Sustituye a «git como www-data (M-3)».
5. Simuladores del E2E `mock-twilio.cjs` (:8095) y `mock-elevenlabs.cjs` (:8096) dentro de `e2e/ultron-studio/run.sh`; la valla `fence.cjs` sigue vacía.
6. Las fases 1 y 2 y la sonda se rigen por la autorización del 25-10 (arriba).

---

## Diseño a congelar (I1–I13)

### I1. Principios y frontera

| # | Principio | Prueba |
|---|---|---|
| P1 | **Cero motor** e integraciones existentes intactas (lista de arriba) | Puerta de `git diff --stat` (§«Despliegue») y `UltronEngineIsolationTest` |
| P2 | **Cero red por defecto.** Con las llaves por defecto, ninguna ruta del hito hace una petición saliente. Solo pueden salir: la sonda GET de Twilio (con su bandera) y la verificación GET de ElevenLabs por consola (`voice:doctor --verify` e `ultron-studio:integrations-snapshot --verify-voice`), que en H6 no se ejecutan en producción | `Http::preventStrayRequests()` sobre las 18 rutas; valla vacía en el E2E |
| P3 | **Espejo local, solo lectura:** el Studio lee `meta_ad_entities`, `meta_sync_runs`, los retos del OTP, `marketing_calls`, la conversación canaria y su lead, y la configuración; no los escribe | `UltronStudioEngineReadOnlyTest` |
| P4 | **Nada cambia lo que ULTRON dice o hace:** asociaciones, números canarios y configuración autorizada son registro | Textos obligatorios (§I10); suites de ULTRON idénticas |
| P5 | **Sin secretos ni teléfonos en claro** en DTOs, libro, instantáneas, logs ni cliente | Prueba centinela (§I9.6) |
| P6 | Aditivo y sin borrar | Prueba de migración en SQLite y PG |
| P7 | Sin dependencias nuevas | `composer.lock` y `package-lock.json` sin cambios |
| P8 | Catálogo congelado | `StudioCatalogTest` sin huella nueva |

**La frontera, en una frase verificable:** el motor no lee nada nuevo y el Studio no escribe fuera de `ultron_studio_*`. Las clases neutras (`App\Services\Voice\**`, `App\Services\Twilio\**` y `App\Support\Privacy\PhoneFingerprint`) no nombran el Studio, y en H6 el motor no las nombra.

### I2. Conjuntos cerrados y formas comunes

**Respuestas.** Éxito: `{ "ok": true, "data": … }`. Error: `{ "ok": false, "code": "<código>", "message": "<texto para la persona>", …carga }`, que es `UltronStudioException::render()`. Fechas ISO 8601 con zona (`toIso8601String()`). Uuids en minúsculas. **Listas paginadas:** `page` y `per_page` enteros ≥ 1 (si no, 422); por encima de 50 → 50; 20 por defecto. Forma del paginador de Laravel (`data`, `current_page`, `last_page`, `per_page`, `total`), como `GET publications` del H5 (AS-8).

**Identificadores públicos de Meta:** `MetaAdEntity::opaqueId($nivel, $id)` = `"<nivel>:" + 16 hex` (HMAC con la `APP_KEY`). La API acepta y devuelve **solo** estos; el id de Meta no sale del servidor. Referencia legible: `LeadSourceClassifier::maskId()` (`***` + 4 dígitos).

**Conjuntos cerrados (los usan PHP, TS y el fixture):**

| Conjunto | Valores |
|---|---|
| `classification` | `connected_read`, `connected_otp_only`, `degraded`, `disabled`, `not_configured`, `registry` |
| Meta `connection.state` | `ok`, `running`, `stale`, `failed`, `not_configured` (los de `MetaAdsSync::statusAll()`) |
| Meta `connection.reason` | `null`, `missing_access_token`, `missing_ad_account_id`, `invalid_ad_account_id`, `invalid_timezone`, `sync_disabled`, `stale`, `failed` |
| Meta `effectiveStatus` (filtro) | `ACTIVE`, `PAUSED`, `ARCHIVED`, `IN_PROCESS`, `WITH_ISSUES`, `DELETED`, `unknown` (= nulo). Un valor que Meta devuelva fuera de la lista se enseña tal cual y no se filtra |
| Asociación `status` | `active`, `inactive` |
| Asociación `target.kind` | `baseline`, `version` |
| Versiones asociables | `APPROVED`, `CANARY`, `PUBLISHED` |
| Canario `channel` | `whatsapp`, `voice` |
| Canario `status` | `pending`, `authorized`, `active`, `revoked` |
| Coherencia `verdict` | `in_sync`, `engine_canary_not_registered`, `engine_canary_registered_inactive`, `registry_has_extra_active`, `engine_canary_phone_unusable`, `no_engine_canary`, `unreadable` |
| `effectiveVersion.source` | `publication_chain` (con `state` ∈ `applied`, `legacy`, `off`, `fallback`, los de `StudioPublications::state()`) o `none` (con `reason` ∈ `not_engine_canary`, `no_voice_engine`, `coherence_unreadable`, `chain_unreadable`) |
| `expectation` | `none`, `match`, `mismatch`, `not_applicable` |
| Motivos de `actions` | `forbidden`, `writes_disabled`, `invalid_transition`, `four_eyes_required`, `active_limit` |
| Instantánea `provider` / `kind` / `actor_type` | `meta_ads`, `twilio`, `voice` / `config`, `probe` / `admin`, `system` |
| Sonda `outcome` | `ok`, `degraded`, `auth_failed`, `unreachable`, `error` |
| Configuración `state` | `authorized`, `drift`, `never_authorized` |
| Voz `state` | `ELEVENLABS_NOT_CONFIGURED`, `ELEVENLABS_DISABLED`, `ELEVENLABS_UNVERIFIED`, `ELEVENLABS_VERIFIED` (este último solo lo calcula el Studio) |
| Voz `missing` | `api_key`, `agent_id`, `webhook_secret`, `region` (en este orden; «falta o no tiene forma válida») |
| Verificación de voz `outcome` | `credentials_ok`, `invalid_credentials`, `agent_not_found`, `not_configured`, `unreachable`, `error` |
| Firma de ElevenLabs | `ok`, `missing_signature`, `malformed_signature`, `expired`, `from_future`, `mismatch` |
| Motivos de `VoiceCallPolicy` (en este orden) | `calls_disabled_in_code`, `twilio_voice_disabled`, `no_voice_number`, `elevenlabs_not_configured`, `elevenlabs_disabled`, `elevenlabs_unverified`, `not_an_active_voice_canary`, `source_not_allowed`, `daily_cap_zero` |

### I3. Meta Ads: campañas y asociación campaña ↔ ULTRON

**I3.1 Tabla `ultron_studio_campaign_links`** (migración 1). Una fila es **un periodo** de asociación: el historial son filas, nunca ediciones.

| Columna | Tipo (Blueprint) | Notas |
|---|---|---|
| `id` | `id()` | |
| `uuid` | `uuid()->unique()` | |
| `client_request_id` | `uuid()->unique()` | Idempotencia de la creación |
| `ad_account_id` | `string(64)` | Id de Meta sin `act_`, como `meta_ad_entities` |
| `campaign_id` | `string(64)` | Id de Meta de la campaña; nunca sale del servidor |
| `campaign_name_at_link` | `string(255)->nullable()` | Nombre al asociar, con `LeadSourceClassifier::maskDigits()` |
| `target_kind` | `string(10)` | `baseline` o `version` |
| `version_id` | `foreignId()->nullable()->constrained('ultron_studio_versions')->restrictOnDelete()` | NOT NULL si y solo si `target_kind = version` |
| `version_content_hash` | `char(64)->nullable()` | La huella de la versión al asociar; igual condición que `version_id` |
| `status` | `string(10)` | `active` o `inactive` |
| `created_by_admin_id` | `unsignedBigInteger()` | Instantánea **sin FK**, como en F |
| `created_by_name` | `string(120)` | |
| `created_by_role` | `string(60)->nullable()` | |
| `created_at` | `timestamp()` | Sin `updated_at` |
| `deactivated_at` | `timestamp()->nullable()` | |
| `deactivated_by_admin_id` | `unsignedBigInteger()->nullable()` | |
| `deactivated_by_name` | `string(120)->nullable()` | |
| `deactivated_by_role` | `string(60)->nullable()` | |
| `deactivation_reason` | `string(500)->nullable()` | |
| `replaced_by_link_id` | `foreignId()->nullable()->constrained('ultron_studio_campaign_links')->restrictOnDelete()` | |

Índices: `uscl_campaign_idx` (`ad_account_id`, `campaign_id`, `id`) y **único parcial** `uscl_one_active` (`ad_account_id`, `campaign_id`) `WHERE status = 'active'`, en los dos motores, creado por la migración 2 con SQL crudo (texto idéntico en los dos).

**I3.2 Guardas (migración 2).** Columnas mutables: `status`, `deactivated_at`, `deactivated_by_admin_id`, `deactivated_by_name`, `deactivated_by_role`, `deactivation_reason` y `replaced_by_link_id`. El resto son inmutables, y `up()` comprueba que entre las dos listas están exactamente las columnas de la tabla (como F).

| Guarda | Motor | Definición |
|---|---|---|
| `uscl_forbid_delete` | los dos | `BEFORE DELETE … FOR EACH ROW` → `ultron_studio_forbid('las asociaciones de campaña no se borran')` |
| `uscl_forbid_truncate` | PG | `BEFORE TRUNCATE … FOR EACH STATEMENT` |
| `uscl_forbid_immutable_update` | los dos | `BEFORE UPDATE OF <inmutables>` |
| `uscl_inactive_is_final` | los dos | `BEFORE UPDATE … WHEN (OLD.status = 'inactive')` |
| `uscl_status_chk` | PG | `status IN ('active','inactive')` |
| `uscl_target_kind_chk` | PG | `target_kind IN ('baseline','version')` |
| `uscl_target_chk` | PG | `(target_kind = 'baseline' AND version_id IS NULL AND version_content_hash IS NULL) OR (target_kind = 'version' AND version_id IS NOT NULL AND version_content_hash IS NOT NULL)` |
| `uscl_deactivation_chk` | PG | `status = 'active' OR (deactivated_at IS NOT NULL AND deactivated_by_admin_id IS NOT NULL AND deactivation_reason IS NOT NULL)` |

Mensaje y SQLSTATE como F y H5: `ultron_studio: <motivo> (<op> en <tabla>)`; P0001 en PG; 23000 en SQLite; un CHECK violado da 23514. Se reutiliza `ultron_studio_forbid()` con `CREATE OR REPLACE` y **el mismo cuerpo byte a byte** que F.

**I3.3 Lectura (`‹S›/StudioMetaCampaigns.php`, sin red).** Fuentes: `MetaAdEntity::forConfiguredAccount()->where('level', 'campaign')`; `MetaAdEntity::accountNames()`; `MetaAdsSync::statusAll()` (estado por cuenta); `MetaSyncRun` (inicio de la última pasada con éxito por cuenta); y la tabla de asociaciones. **Lista blanca de llamadas** a clases de Meta, vigilada por una prueba estática: `configurationProblem`, `accountIds`, `statusAll`, `forConfiguredAccount`, `accountNames`, `opaqueId`, `publicAccount`, `maskId` y `maskDigits`. **Prohibidas:** `campaigns`, `adsets`, `ads`, `insights`, `reach`, `account`, `tokenInfo`, `adOwner`, `accountTotals`, `run`, `runAll`, `refreshReach` y `refreshStatuses`.

- `possiblyDeleted` = `synced_at` nulo o anterior al `started_at` de la última fila de `meta_sync_runs` de su cuenta con `kind = insights`, `status = ok` y `trigger ≠ backfill`. Los rellenos no refrescan estados (`MetaAdsSync.php:726-728`). El borde de campañas no devuelve las eliminadas (`MetaAdsApiClient.php:64-71`); su estado es «el último conocido». **Falso positivo aceptado:** si en esa pasada falló el borde de campañas (es accesorio: `MetaAdsSync.php:881-895`), las campañas que no gastaron en la ventana salen como `possiblyDeleted` hasta la pasada siguiente. La etiqueta dice «posiblemente».
- `stale` (por campaña) = el `state` de su cuenta en `statusAll()` es `stale` o `failed`.
- Orden: primero `effective_status = 'ACTIVE'`, después el nombre sin distinguir mayúsculas, después `entity_id`.

**I3.4 Rutas de Meta.**

**40 · `GET integrations/meta/campaigns`**. Consulta: `status` (conjunto de I2, opcional), `linked` (`yes` o `no`, opcional), `account` (`^account:[0-9a-f]{16}$`, opcional), `page` y `per_page`. Un `account` que no es de una cuenta conectada da una lista vacía, no un error. Respuesta:

```jsonc
{ "ok": true, "data": {
  "connection": { "state": "ok", "reason": null, "syncEnabled": true, "staleAfterMinutes": 180,
                  "lastSuccessAt": "2026-10-31T21:15:40+00:00", "minutesSinceSuccess": 12,
                  "accounts": [ { "id": "account:9c1d…", "ref": "***4714", "name": "IronBody2", "state": "ok", "lastSuccessAt": "…" } ] },
  "campaigns": { "data": [ {
      "id": "campaign:1a2b3c4d5e6f7a8b", "ref": "***0387", "name": "Pauta octubre ***4821",
      "account": { "id": "account:9c1d…", "ref": "***4714", "name": "IronBody2" },
      "meta": { "effectiveStatus": "ACTIVE", "statusKnownAt": "…", "ageMinutes": 42, "stale": false, "possiblyDeleted": false },
      "ultron": { "link": null } } ],                      // o el CampaignLinkDto ACTIVO (I3.5)
    "current_page": 1, "last_page": 1, "per_page": 20, "total": 12 } } }
```

Sin configuración (`configurationProblem() ≠ null`): `connection.state = not_configured` con su `reason`, `accounts: []` y `campaigns.data: []`. **Nunca** datos de demostración. Con `meta.ads.sync_enabled = false` y la configuración correcta: `reason = sync_disabled`.

**41 · `GET integrations/meta/links`**. Consulta: `campaign` (`^campaign:[0-9a-f]{16}$`, opcional), `status` (`active` o `inactive`, opcional), `page` y `per_page`. Respuesta: paginador de `CampaignLinkDto` por `id` descendente.

**47 · `POST integrations/meta/links`**. Cuerpo:

| Campo | Regla |
|---|---|
| `clientRequestId` | `required`, `uuid` |
| `campaign` | `required`, `string`, `regex:^campaign:[0-9a-f]{16}$` |
| `target` | `required`, `array` |
| `target.kind` | `required`, `in:baseline,version` |
| `target.versionUuid` | `required_if:target.kind,version`, `prohibited_unless:target.kind,version`, `uuid` |
| `replaces` | `sometimes`, `nullable`, `uuid` |

Orden de comprobación:
1. Puerta (`AuthorizationMap`) y `StudioAccess::requirePerson()` → 403.
2. Forma → 422.
3. `require(ultron.campaigns_manage)` → 403 `forbidden`.
4. Bandera de escrituras → 409 `integrations_writes_disabled`.
5. Reintento: misma `clientRequestId`, misma persona y mismo cuerpo (cuenta y campaña reales, destino y `replaces`) → **200** `{replayed: true, link, replaced}`; si no coincide → 409 `client_request_reused`.
6. Meta sin configurar → 409 `meta_not_configured` `{reason}`.
7. La campaña no se resuelve entre las campañas del espejo de las cuentas conectadas → 404 `campaign_not_found`.
8. Destino `version`: no existe → 404 `version_not_found`; estado fuera de `APPROVED|CANARY|PUBLISHED` → 409 `version_not_linkable` `{currentStatus}`; `StudioVersions::integrityOk()` falso → 409 `integrity_mismatch`.
9. Ya hay una activa: sin `replaces` → 409 `campaign_already_linked` `{current: CampaignLinkDto}`; con un `replaces` que no es la activa → 409 `campaign_link_moved` `{current: CampaignLinkDto|null}`.
10. Activas ≥ `campaign_links.max_active` (sin contar la que se sustituye) → 409 `campaign_links_limit` `{max}`.
11. Transacción:
    - si hay `replaces`, la activa pasa a `inactive` con `deactivation_reason = 'Sustituida por otra asociación'`, `deactivated_by_* = quien actúa` y `replaced_by_link_id = la nueva`;
    - se inserta la nueva;
    - se escriben los eventos del libro (§I8).

    Un 23505 en `uscl_one_active` → 409 `campaign_already_linked`, resuelto **fuera** de la transacción abortada (patrón de `StudioVersions::create`).

Respuesta: **201** `{ok, data: {replayed: false, link: CampaignLinkDto, replaced: CampaignLinkDto|null}}`.

**48 · `POST integrations/meta/links/{uuid}/deactivate`**. Cuerpo: `reason` (`required`, `string`, `min:5`, `max:500`, `PlainText(multiline: true)`). Orden: 403 → 422 → 403 `forbidden` → 409 `integrations_writes_disabled` → 404 `link_not_found` → 409 `invalid_transition` `{currentStatus:'inactive', allowedFrom:['active']}` → UPDATE condicionado (`WHERE status = 'active'`) + evento. Respuesta: 200 `{link: CampaignLinkDto}`.

**I3.5 `CampaignLinkDto`:**

```jsonc
{ "uuid": "…", "status": "active",
  "campaign": { "id": "campaign:1a2b…", "ref": "***0387", "nameAtLink": "Pauta octubre ***4821", "connected": true, "currentName": "…" | null },
  "target": { "kind": "baseline", "label": "LEGACY_BASELINE_V1" }
          | { "kind": "version", "versionUuid": "…", "label": "…", "status": "CANARY", "statusLabel": "En canario", "integrityOk": true },
  "createdBy": { "name": "…", "role": "…" }, "createdAt": "…",
  "deactivated": null | { "at": "…", "by": { "name": "…", "role": "…" }, "reason": "…", "replacedBy": "<uuid>" | null } }
```

`campaign.connected` = la campaña está en el espejo de una cuenta conectada; `currentName` es su nombre de ahora (enmascarado) o `null`.

**Dos estados que nunca se mezclan:** `meta.effectiveStatus` («En Meta: Activa · hace 42 min», «Pausada», «Archivada», «Posiblemente eliminada», «Dato viejo») y `ultron.link` («En ULTRON Studio: asociación activa (sin efecto en el motor)» o «Sin asociación»).

**Sin cuatro ojos:** una asociación no cambia Meta ni el motor, no gasta y no contiene datos personales.

### I4. Números canarios

**I4.1 Tabla `ultron_studio_canary_numbers`** (migración 1):

| Columna | Tipo (Blueprint) | Notas |
|---|---|---|
| `id` | `id()` | |
| `uuid` | `uuid()->unique()` | |
| `client_request_id` | `uuid()->unique()` | Idempotencia del registro |
| `channel` | `string(10)` | `whatsapp` o `voice` |
| `alias` | `string(40)` | Etiqueta por función (§I4.3) |
| `phone_hash` | `char(64)` | `PhoneFingerprint::of(E.164)`, hex en minúsculas |
| `phone_last4` | `char(4)` | |
| `country_code` | `string(4)` | `57` |
| `phone_encrypted` | `text()->nullable()` | **Solo `voice`**: cast `encrypted` del modelo; se pone a NULL al revocar |
| `status` | `string(12)` | `pending`, `authorized`, `active` o `revoked` |
| `expected_version_id` | `foreignId()->nullable()->constrained('ultron_studio_versions')->restrictOnDelete()` | Expectativa (§I4.6) |
| `consent_attested` | `boolean()` | Tiene que ser `true` |
| `registered_by_admin_id` | `unsignedBigInteger()` | Instantánea sin FK |
| `registered_by_name` | `string(120)` | |
| `registered_by_role` | `string(60)->nullable()` | |
| `authorized_by_admin_id` | `unsignedBigInteger()->nullable()` | |
| `authorized_by_name` | `string(120)->nullable()` | |
| `authorized_by_role` | `string(60)->nullable()` | |
| `authorized_at` | `timestamp()->nullable()` | |
| `activated_by_admin_id` | `unsignedBigInteger()->nullable()` | La **última** activación |
| `activated_by_name` | `string(120)->nullable()` | |
| `activated_by_role` | `string(60)->nullable()` | |
| `activated_at` | `timestamp()->nullable()` | |
| `revoked_by_admin_id` | `unsignedBigInteger()->nullable()` | |
| `revoked_by_name` | `string(120)->nullable()` | |
| `revoked_by_role` | `string(60)->nullable()` | |
| `revoked_at` | `timestamp()->nullable()` | |
| `revoke_reason` | `string(500)->nullable()` | |
| `status_changed_at` | `timestamp()` | |
| `created_at`, `updated_at` | `timestamps()` | |

Índices: `uscn_status_idx` (`status`, `channel`) y **único parcial** `uscn_one_live` (`channel`, `phone_hash`) `WHERE status <> 'revoked'`, en los dos motores. **Un mismo teléfono puede tener una fila viva por canal** (WhatsApp y voz son dos registros). El motivo de **desactivar** no tiene columna: va al libro.

**I4.2 Guardas (migración 2).** Inmutables: `id`, `uuid`, `client_request_id`, `channel`, `alias`, `phone_hash`, `phone_last4`, `country_code`, `consent_attested`, `registered_by_admin_id`, `registered_by_name`, `registered_by_role` y `created_at`. Las demás son mutables, y `up()` comprueba que las dos listas cubren la tabla. **El servicio cambia filas solo con UPDATE condicionados del query builder** (como `StudioVersion::transition()`); nunca con `save()` del modelo entero, para que el cast `encrypted` no vuelva a cifrar.

| Guarda | Motor | Definición |
|---|---|---|
| `uscn_forbid_delete` | los dos | `BEFORE DELETE` → `'los números canarios se revocan, no se borran'` |
| `uscn_forbid_truncate` | PG | `BEFORE TRUNCATE` |
| `uscn_forbid_immutable_update` | los dos | `BEFORE UPDATE OF <inmutables>` |
| `uscn_revoked_is_final` | los dos | `BEFORE UPDATE … WHEN (OLD.status = 'revoked')` |
| `uscn_cipher_only_shrinks` | los dos | `BEFORE UPDATE OF phone_encrypted … WHEN (NEW.phone_encrypted IS NOT NULL AND (OLD.phone_encrypted IS NULL OR NEW.phone_encrypted <> OLD.phone_encrypted))` |
| `uscn_channel_chk` | PG | `channel IN ('whatsapp','voice')` |
| `uscn_status_chk` | PG | `status IN ('pending','authorized','active','revoked')` |
| `uscn_consent_chk` | PG | `consent_attested = true` |
| `uscn_hash_chk` / `uscn_last4_chk` | PG | `phone_hash ~ '^[0-9a-f]{64}$'` / `phone_last4 ~ '^[0-9]{4}$'` |
| `uscn_whatsapp_no_cipher_chk` | PG | `channel = 'voice' OR phone_encrypted IS NULL` |
| `uscn_voice_cipher_chk` | PG | `channel = 'whatsapp' OR status = 'revoked' OR phone_encrypted IS NOT NULL` |
| `uscn_four_eyes_chk` | PG | `authorized_by_admin_id IS NULL OR authorized_by_admin_id <> registered_by_admin_id` |
| `uscn_activation_four_eyes_chk` | PG | `activated_by_admin_id IS NULL OR activated_by_admin_id <> registered_by_admin_id` |
| `uscn_authorized_chk` | PG | `status IN ('pending','revoked') OR authorized_by_admin_id IS NOT NULL` |
| `uscn_active_chk` | PG | `status <> 'active' OR activated_by_admin_id IS NOT NULL` |
| `uscn_revoke_chk` | PG | `status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_by_admin_id IS NOT NULL AND revoke_reason IS NOT NULL AND phone_encrypted IS NULL)` |

**I4.3 E.164, canal, alias y huella (interfaces fijas).**

```php
namespace App\Services\UltronStudio;

final class StudioCanaryPhone                      // puro (paquete S)
{
    public const CHANNELS = ['whatsapp', 'voice'];
    /**
     * Quita SOLO espacios (U+0020), '-', '.', '(' y ')'. Cualquier otro carácter → invalid_format.
     * Después: ^\+[1-9]\d{7,14}$ → si no, invalid_format.
     * País: el prefijo más largo de $allowedCountryCodes con el que empieza → si ninguno, country_not_allowed.
     * Canal, con país 57: whatsapp ^\+573\d{9}$; voice ^\+57(?:3\d{9}|60\d{8})$ → si no, not_allowed_for_channel.
     * Con otro país permitido no hay regla de canal: basta E.164.
     *
     * @param  list<string>  $allowedCountryCodes
     * @return array{ok:true, e164:string, countryCode:string, last4:string, masked:string}
     *       | array{ok:false, error:'invalid_format'|'country_not_allowed'|'not_allowed_for_channel'}
     */
    public static function parse(string $raw, string $channel, array $allowedCountryCodes): array;

    /** "+57 ••• ••• 4821" (U+2022). */
    public static function masked(string $countryCode, string $last4): string;

    /** null si vale. 'invalid_alias' si no cumple 3–40 caracteres de ^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ .\-_()]+$ (flag u), si tiene una serie de 4 dígitos o más (\d{4,}) o si tiene '@'. Sin \p{…} (PCRE 10.39 en producción). */
    public static function aliasError(string $alias): ?string;
}
```

```php
namespace App\Support\Privacy;

final class PhoneFingerprint                       // neutro: no nombra el Studio (paquete S)
{
    public const LABEL = 'ultron-canary-phone/1';
    /** Huella de un E.164 con la APP_KEY de la aplicación. InvalidArgumentException si $e164 no casa con ^\+[1-9]\d{7,14}$. */
    public static function of(string $e164): string;
    /**
     * Lo mismo con la clave explícita (pruebas y fixture). $appKeyRaw = bytes de la APP_KEY (sin 'base64:', decodificada).
     * k  = hash_hmac('sha256', self::LABEL, $appKeyRaw, true)
     * fp = hash_hmac('sha256', $e164, k)   // 64 hex en minúsculas
     */
    public static function withKey(string $e164, string $appKeyRaw): string;
}
```

`of()` lee `config('app.key')`: si empieza por `base64:`, decodifica el resto; si queda vacía, lanza `RuntimeException`. Es la lectura de derivación de la Q-1 del H5 (la `APP_KEY` es la **clave** y la etiqueta, el **dato**), con etiqueta propia y versionada.

**I4.4 Máquina de estados** (permiso de la ruta en `AuthorizationMap`; además `StudioAccess::require()` en el servicio):

| Desde | Acción (ruta) | Hacia | Permiso | Identidad | Lo que revalida el servidor, en orden |
|---|---|---|---|---|---|
| — | registrar (49) | `pending` | `ultron.canary_manage` | cualquiera | Bandera → reintento → versión esperada (si viene) → vivos por canal < `canary.max_live` (409 `canary_live_limit`) → INSERT (23505 en `uscn_one_live` → 409 `canary_number_exists`, resuelto fuera de la transacción) |
| `pending` | autorizar (50) | `authorized` | `ultron.canary_authorize` | **≠ quien registró** (403 `four_eyes_required` `{action:'authorize'}` + evento deduplicado) | Bandera → existe → identidad → estado |
| `authorized` | activar (51) | `active` | `ultron.canary_manage` | **≠ quien registró** (403 `four_eyes_required` `{action:'activate'}` + evento deduplicado) | Bandera → existe → identidad → estado → activos del canal < `canary.max_active` con cerrojo (§I4.8) → 409 `canary_active_limit` |
| `active` | desactivar (52) | `authorized` | `ultron.canary_manage` | cualquiera | Bandera → existe → estado; `reason` 5–500 |
| `pending`, `authorized`, `active` | revocar (53) | `revoked` (terminal) | `ultron.canary_manage` | cualquiera (es el sentido seguro) | Bandera → existe → estado; `reason` 5–500; en la **misma** sentencia, `phone_encrypted = NULL` |
| `pending`, `authorized`, `active` | versión esperada (54) | (igual) | `ultron.canary_manage` | cualquiera | Bandera → existe → estado ≠ `revoked` → versión (404 / 409 `version_not_linkable` / 409 `integrity_mismatch`); `null` la quita |

Cada transición es un UPDATE condicionado al estado esperado, en la misma transacción que su evento del libro. Si no afecta a ninguna fila, se relee y se responde 409 `invalid_transition` `{currentStatus, allowedFrom}`; el CRM lo lee como «ya estaba hecho» cuando `currentStatus` es el de destino. Cuatro ojos **por identidad** (`StudioAccess::isCanaryRegistrant()` compara ids, nunca permisos) y, además, en la base (`uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk`).

**I4.5 Coherencia con el canario del motor (`‹S›/StudioCanaryCoherence.php`, solo lectura).**

```php
final class StudioCanaryCoherence
{
    /** Nunca lanza: cualquier Throwable → verdict 'unreadable' (al log, solo la clase). */
    public function read(): array;  // {verdict, engineCanaryConfigured, engineHash: ?string (SOLO uso interno; nunca sale en un DTO), activeWhatsapp:int}
}
```

1. `$id = config('marketing.ultron.canary_conversation_id')`, la misma lectura que `StudioCanaryMeasures.php:94`. Si es `null` → `no_engine_canary`.
2. Dos consultas puntuales: `marketing_conversations.lead_id` de `$id` y `marketing_leads.phone` de ese lead. Sin teléfono → `engine_canary_phone_unusable`.
3. Normalización: solo los dígitos; con 10 dígitos que empiezan por `3` se antepone `57`; se antepone `+`. Si no casa con E.164 → `engine_canary_phone_unusable`. Si casa → `PhoneFingerprint::of()`, y se hace `unset` del teléfono. **Nunca** se registra, se devuelve ni se guarda.
4. Veredicto (precedencia en este orden):
   - `no_engine_canary`;
   - `unreadable`;
   - `engine_canary_phone_unusable`;
   - `engine_canary_not_registered` (ninguna fila `whatsapp` viva con esa huella);
   - `engine_canary_registered_inactive` (la fila está en `pending` o `authorized`);
   - `registry_has_extra_active` (la fila está activa y hay **otras** `whatsapp` activas);
   - `in_sync`.

   **Nunca** `in_sync` por defecto.
5. Por cada fila: `isEngineCanary = true|false` en `whatsapp`; `null` en `voice` y con `unreadable` o `no_engine_canary`.

**I4.6 «Asociar una versión» = expectativa.** `expected_version_id` dice qué versión **se espera probar** con ese número. La que **rige** sale de una sola autoridad:
- `whatsapp` con `isEngineCanary = true`: `StudioPublications::state()` (Studio, H5).
  - `state` `off`, `legacy` o `fallback` → `{source:'publication_chain', state}`.
  - `applied`: parte = `canary` y, si es `null`, `rest`, como resuelve el lector del motor (H5 §A4.3, paso 5); si las dos son `null`, `legacy`. Forma: `{source:'publication_chain', state:'applied', part, versionUuid, label}`.
  - Si `state()` lanza → `{source:'none', reason:'chain_unreadable'}`.
- `whatsapp` con `isEngineCanary = false` → `{source:'none', reason:'not_engine_canary'}`; con `null` → `{source:'none', reason:'coherence_unreadable'}`.
- `voice` → `{source:'none', reason:'no_voice_engine'}`.

`expectation`:
- `none`: sin versión esperada.
- `match`: `applied` con el mismo uuid.
- `mismatch`: `applied` con otro uuid, o `legacy`/`off`/`fallback`.
- `not_applicable`: `source = none`.

`expectedVersion.stale = true` si la versión esperada ya no está en `APPROVED|CANARY|PUBLISHED` o no es íntegra. Se marca al leer; **nada** cambia solo. **Nadie** usa la expectativa para decidir: ni el motor, ni publicar, ni la política de voz.

**I4.7 La transición (H6 = T0 y T1).**
- **T0** (fase 1): el registro existe y la coherencia lo compara con el canario del entorno. Nadie lee el registro.
- **T1** (fase 2): quien tiene el teléfono de la conversación canaria lo registra (`whatsapp`), **otra** persona lo autoriza y una persona ≠ quien registró lo activa → `in_sync`. **El entorno no se toca.**
- **T2** (futuro, autorización expresa): el motor añade una **restricción**, leída **en el emisor** antes de crear el evento: la conversación es la del entorno **y** su teléfono está activo en el registro. Llave `MARKETING_ULTRON_CANARY_SOURCE=env|env_and_registry`, `env` por defecto, desde una sola clase en la lista blanca, como `UltronPublishedConfig`. Nunca como un 403 nuevo del commit: el vigía frenaría el canario (lección §2.4 del plan H5).
- **T3** (futuro): el registro sustituye al id de conversación; el canal `voice` lo usa como lista de permitidos del marcador.

`MARKETING_ULTRON_ENABLED`, la pausa y el freno mandan por encima en todas las fases. El canario del cobro queda fuera del registro.

**I4.8 Topes y concurrencia.** Activos por canal ≤ `canary.max_active` (3 por defecto; 1–10). Vivos (no revocados) por canal ≤ `canary.max_live` (10). Activar toma `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` y después `SELECT pg_advisory_xact_lock(crc32('ultron_studio_canary_active:' || canal))` antes de contar (lección B-2 de G, 4.6 de H4 y A10.6 de H5); SQLite escribe en serie. El tope de vivos se cuenta igual en el registro, con su propia clave de cerrojo (`ultron_studio_canary_live:` + canal).

**I4.9 Rutas de canarios.**

**42 · `GET integrations/canaries`**. Consulta: `status` (`pending|authorized|active|revoked|all`; por defecto, todos menos `revoked`), `channel` (opcional), `page` y `per_page`. Respuesta:

```jsonc
{ "ok": true, "data": {
  "coherence": { "engineCanaryConfigured": true, "verdict": "in_sync", "activeWhatsapp": 1 },
  "limits": { "maxActivePerChannel": 3, "maxLivePerChannel": 10, "countryCodes": ["57"], "writesEnabled": true },
  "numbers": { "data": [ CanaryNumberDto, … ], "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 } } }
```

```jsonc
// CanaryNumberDto
{ "uuid": "…", "channel": "whatsapp", "alias": "Móvil de pruebas 1", "masked": "+57 ••• ••• 4821",
  "status": "active", "statusLabel": "Activo (registro)",
  "registeredBy": { "name": "…", "role": "…" }, "registeredAt": "…",
  "authorizedBy": { "name": "…", "role": "…" } | null, "authorizedAt": "…" | null,
  "activatedBy": { "name": "…", "role": "…" } | null, "activatedAt": "…" | null,
  "revoked": null | { "at": "…", "by": { "name": "…", "role": "…" }, "reason": "…" },
  "expectedVersion": null | { "uuid": "…", "label": "…", "status": "CANARY", "statusLabel": "En canario", "stale": false },
  "effectiveVersion": { "source": "publication_chain", "state": "applied", "part": "canary", "versionUuid": "…", "label": "…" }
                    | { "source": "publication_chain", "state": "legacy" } | { "source": "none", "reason": "not_engine_canary" },
  "expectation": "match", "isEngineCanary": true,
  "actions": { "authorize":   { "allowed": false, "reason": "invalid_transition" },
               "activate":    { "allowed": false, "reason": "invalid_transition" },
               "deactivate":  { "allowed": true,  "reason": null },
               "revoke":      { "allowed": true,  "reason": null },
               "expectation": { "allowed": true,  "reason": null } },
  "statusChangedAt": "…" }
```

**Etiquetas de estado** (las da el servidor; el CRM las pinta tal cual): `pending` «Pendiente de autorizar (registro)», `authorized` «Autorizado (registro)», `active` «Activo (registro)», `revoked` «Revocado».

**`actions`**, calculadas para quien mira, con la precedencia `forbidden` → `writes_disabled` → `invalid_transition` → `four_eyes_required` → `active_limit`:
- `authorize`: `canary_authorize`, la bandera, `pending` y ≠ quien registró.
- `activate`: `canary_manage`, la bandera, `authorized`, ≠ quien registró y activos < tope.
- `deactivate`: `canary_manage`, la bandera y `active`.
- `revoke`: `canary_manage`, la bandera y no `revoked`.
- `expectation`: igual que `revoke`.

**El número completo nunca sale**, ni tras registrarlo.

**49 · `POST integrations/canaries`**. Cuerpo:

| Campo | Regla |
|---|---|
| `clientRequestId` | `required`, `uuid` |
| `channel` | `required`, `in:whatsapp,voice` |
| `phone` | `required`, `string`, `max:32`, y `StudioCanaryPhone::parse()` → si falla, 422 en `phone` con uno de estos textos fijos: «El teléfono no tiene formato internacional (+57…).», «Ese país no está permitido para números canarios.» o «Ese número no sirve para este canal (WhatsApp exige un móvil).» |
| `alias` | `required`, `string`, `PlainText`, y `StudioCanaryPhone::aliasError()` → 422 «Usa una función de 3 a 40 caracteres, sin teléfonos ni correos.» |
| `consentAttested` | `required`, `boolean:strict`, `accepted` |
| `expectedVersionUuid` | `sometimes`, `nullable`, `uuid` |

Orden: 403 → 422 → 403 `forbidden` → 409 `integrations_writes_disabled` → reintento (misma persona, canal, huella, alias y versión esperada → **200** `{replayed:true, number}`; si no coincide, 409 `client_request_reused`) → versión esperada → 409 `canary_live_limit` `{max, channel}` → INSERT + evento. 23505 → 409 `canary_number_exists`. Respuesta: **201** `{replayed:false, number: CanaryNumberDto}`.

**50 · `POST …/{uuid}/authorize`** (cuerpo vacío), **51 · `…/activate`** (cuerpo vacío), **52 · `…/deactivate`** (`reason` 5–500, `PlainText(multiline: true)`), **53 · `…/revoke`** (`reason` 5–500) y **54 · `…/expectation`** (`versionUuid`: `present`, `nullable`, `uuid`). Orden común: 403 → 422 → 403 `forbidden` → 409 `integrations_writes_disabled` → 404 `canary_not_found` → (50 y 51) 403 `four_eyes_required` → 409 `invalid_transition` → (51) 409 `canary_active_limit` → (54) versión → UPDATE + evento. Respuesta: 200 `{number: CanaryNumberDto}`.

### I5. Twilio

**I5.1 Lo existente se conserva tal cual.** Hallazgos de la auditoría, para el informe de seguridad:

| # | Hallazgo | Qué hace H6 |
|---|---|---|
| T-1 | La firma de `usage-trigger` usa `fullUrl()` y no hay `trustProxies` (hallazgo 12 de la auditoría). Si falla, falla cerrada (403): pierde el aviso, no abre nada | Nada en el código. El tester añade al fixture el vector oficial de Twilio (URL, parámetros y firma) para el día que se extraiga el validador |
| T-2 | El validador es privado del controlador y no admite JSON (`bodySHA256`) | **No se extrae** `TwilioSignatureValidator` en H6: no hay segundo consumidor |
| T-3 | Los avisos solo van al log | Se dice en el panel (`persistence: "solo log"`); no se persisten (decisión 12) |
| T-4 | Idempotencia con `Cache::add` (24 h) | En producción, la caché es `database` (hecho al congelar): sobrevive a reinicios |
| T-5 | Dos pares de alias | En producción son la misma credencial (hecho al congelar). El panel lo vigila sin leer valores (I5.3) |
| T-6 | `TWILIO_BASE` y `TWILIO_VERIFY_BASE` vienen del entorno, y el OTP manda las credenciales en Basic a ese host | Nada en el OTP. La sonda **ignora** esas variables en `production` |
| T-7 | `marketing:dispatch-followups` puede crear filas `pending` en `marketing_calls` con el teléfono en claro (hoy 0) | Regla del hito de voz, codificada en `VoiceCallPolicy` (`source_not_allowed`): un marcador nunca consume `marketing_calls` |
| T-8 | `TwilioVerifyService::toE164()` es permisivo | Los canarios usan `StudioCanaryPhone` (estricto) |

**I5.2 Rutas 43 y 44 (sin red).**

**43 · `GET integrations/twilio`:**

```jsonc
{ "ok": true, "data": {
  "classification": "connected_otp_only", "state": "ok", "reason": null,
  "otp": { "driver": "twilio", "verifyActive": true, "sendEnabled": true, "rateLimitConfigured": true,
           "legacySenderEnabled": false, "memberLinkSenderConfigured": true,
           "verified": { "last24h": 14, "last7d": 96, "lastAt": "…" | null } },
  "cost": { "date": "2026-10-31", "estimatedSpentUsd": 0.41, "dailySoftLimitUsd": 6.0, "dailyHardLimitUsd": 12.0 },
  "credentials": { "accountSid": { "present": true, "source": "TWILIO_SID", "ref": "AC…1234" },
                   "authToken":  { "present": true, "source": "TWILIO_TOKEN", "fp": "a1b2c3d4" },
                   "aliasesConsistent": { "sid": true, "token": true },
                   "verifyServiceSid": { "present": true, "ref": "VA…9f0e" },
                   "apiBase": { "official": true }, "verifyBase": { "official": true } },
  "webhooks": [ { "id": "usage_trigger", "path": "/api/webhooks/twilio/usage-trigger", "signature": "HMAC-SHA1 (X-Twilio-Signature)",
                  "idempotency": "IdempotencyToken, caché 24 h", "throttle": "60/min", "persistence": "solo log" } ],
  "voice": { "enabled": false, "fromConfigured": false, "state": "not_configured" },
  "calls": { "total": 0, "withProviderSid": 0, "byStatus": {}, "byDirection": {}, "lastCreatedAt": null },
  "probe": { "enabled": false, "credential": "otp", "cooldownSeconds": 300, "last": null } } }
```

- `classification` y `state` siguen las reglas de §I7.2.
- `otp.verified`: recuentos de `member_auth_challenges` y `trainer_auth_challenges` con `status` ∈ (`verified`, `completed`) por `updated_at`.
- `otp.rateLimitConfigured` = `otp.twilio.rate_limit_unique_name ≠ ''`; `memberLinkSenderConfigured` = `otp.twilio.from` presente.
- `cost` = `OtpCostGuard::snapshot()`.
- `credentials.*.ref` = los 2 primeros caracteres, `…` y los 4 últimos. `fp` según §I5.3.
- `source` y `aliasesConsistent` se calculan **en `config/ultron_studio.php` al cachear** (§I12): fuera de la configuración, `env()` devuelve null con la caché puesta. `aliasesConsistent.*` es `null` si solo existe un nombre.
- `apiBase` y `verifyBase`: `{official:true}` si el host es `api.twilio.com` o `verify.twilio.com`; si no, `{official:false, host}` (solo el host).
- `webhooks` es una descripción **fija** sacada del código.
- `calls`: recuentos de `marketing_calls`. **Nunca** `to_phone`, `from_phone`, `transcript`, `summary`, `metadata` ni ids de lead.
- `probe.last` = la última instantánea `kind=probe, provider=twilio` (forma de I5.4) o `null`.

**44 · `GET integrations/twilio/calls`** (`page`, `per_page`): paginador de `{status, direction, reason, scheduledAt, startedAt, endedAt, durationSeconds, hasProviderSid, createdAt}`, por `id` descendente. Sin ningún dato del prospecto.

**I5.3 Configuración autorizada (Meta Ads, Twilio y voz).**

**Tabla `ultron_studio_integration_snapshots`** (migración 1; **solo inserción**):

| Columna | Tipo (Blueprint) | Notas |
|---|---|---|
| `id` | `id()` | |
| `uuid` | `uuid()->unique()` | Sujeto del libro (`integration`) |
| `client_request_id` | `uuid()->nullable()->unique()` | En reconocimientos y sondas lanzados por una persona |
| `provider` | `string(16)` | `meta_ads`, `twilio` o `voice` |
| `kind` | `string(10)` | `config` o `probe` |
| `fingerprint` | `char(64)` | `sha256(StudioContentHasher::canonicalJson(facts))` |
| `facts` | `json()` | Las formas de abajo; **jamás** un secreto ni un teléfono de una persona |
| `outcome` | `string(16)->nullable()` | Solo en `probe` |
| `actor_type` | `string(10)` | `admin` o `system` |
| `actor_admin_id` | `unsignedBigInteger()->nullable()` | Instantánea sin FK |
| `actor_name` | `string(120)` | `system` → el nombre del comando |
| `actor_role` | `string(60)->nullable()` | |
| `note` | `string(500)->nullable()` | Nota del reconocimiento |
| `created_at` | `timestamp()` | Sin `updated_at` |

Índice: `usis_provider_idx` (`provider`, `kind`, `id`). Guardas: `usis_forbid_update` y `usis_forbid_delete` (los dos motores) y `usis_forbid_truncate` (PG). CHECK en PG:
- `usis_provider_chk`, `usis_kind_chk` y `usis_actor_type_chk` (dominios);
- `usis_fingerprint_chk`: `fingerprint ~ '^[0-9a-f]{64}$'`;
- `usis_outcome_chk`: `(kind = 'probe' AND outcome IN ('ok','degraded','auth_failed','unreachable','error')) OR (kind = 'config' AND outcome IS NULL)`;
- `usis_actor_chk`: `actor_type = 'system' OR actor_admin_id IS NOT NULL`.

**Huellas de valores.** `fp` = `substr(hash_hmac('sha256', $valor, $kCfg), 0, 8)`, con `$kCfg = hash_hmac('sha256', 'ultron-studio-config-fingerprint/1', <APP_KEY cruda>, true)`. Implementación: `‹S›/StudioIntegrationConfig::valueFingerprint(string $valor): string`. Solo para secretos. Lo que no es secreto ni dato personal va tal cual.

**`facts` de `kind=config`**, por proveedor y con las claves en este orden:

```jsonc
// twilio
{ "otpDriver": "twilio",
  "accountSid": { "present": true, "source": "TWILIO_SID", "ref": "AC…1234" },
  "authToken": { "present": true, "source": "TWILIO_TOKEN", "fp": "a1b2c3d4" },
  "aliasesConsistent": { "sid": true, "token": true },
  "verifyServiceSid": { "present": true, "ref": "VA…9f0e" },
  "from": { "present": true, "last4": "5483" },                  // número del gimnasio, no de una persona
  "apiBase": { "official": true }, "verifyBase": { "official": true },
  "rateLimitName": { "present": true },
  "sendEnabled": true, "legacySenderEnabled": false, "costSoftUsd": 6.0, "costHardUsd": 12.0,
  "probe": { "enabled": false, "credential": "otp" } }
// meta_ads
{ "token": { "present": true, "fp": "…" }, "accounts": [ { "ref": "***4714" }, { "ref": "***9023" }, { "ref": "***5856" } ],
  "graphVersion": "v26.0", "syncEnabled": true, "syncDays": 7, "staleMinutes": 180, "timezone": "America/Bogota" }
// voice
{ "elevenlabs": { "enabled": false, "apiKey": { "present": false }, "agentId": { "present": false }, "webhookSecret": { "present": false },
                  "region": "default", "toolSecret": { "present": false }, "phoneNumberId": { "present": false } },
  "twilioVoice": { "enabled": false, "from": { "present": false }, "dailyCallCap": 0 } }
```

Con un valor presente, `apiKey`, `webhookSecret` y `toolSecret` llevan `fp`, `agentId` lleva `ref` (4 últimos caracteres) y `from` lleva `last4`.

**46 · `GET integrations/config`.** Proveedores en el orden `meta_ads`, `twilio`, `voice`:

```jsonc
{ "ok": true, "data": { "providers": [ {
  "provider": "twilio", "state": "drift",
  "effective": { … }, "effectiveFingerprint": "<64 hex>",
  "authorized": { "uuid": "…", "fingerprint": "…", "at": "…", "by": { "name": "…", "role": "…" }, "note": "…" | null } | null,
  "observed":   { "uuid": "…", "fingerprint": "…", "at": "…" } | null,
  "drift": [ { "key": "verifyServiceSid", "authorized": { "present": true, "ref": "VA…9f0e" }, "effective": { "present": true, "ref": "VA…1a2b" } } ] } ] } }
```

- `authorized` = la última instantánea `config` de una **persona**. `observed` = la última `config` de **sistema**.
- `drift` = las claves de primer nivel cuyo valor difiere entre `authorized.facts` y `effective`.
- `state`: `never_authorized` sin `authorized`; `drift` si las huellas difieren; si no, `authorized`.
- **Un GET nunca escribe.**

**55 · `POST integrations/config/acknowledge`.** Cuerpo:
- `clientRequestId`: `required`, `uuid`.
- `provider`: `required`, `in:meta_ads,twilio,voice`.
- `fingerprint`: `required`, `regex:^[0-9a-f]{64}$`.
- `note`: `sometimes`, `nullable`, `string`, `max:500`, `PlainText(multiline: true)`.

Permiso `ultron.integrations_manage`. **No depende de la bandera de escrituras** (decisión 11).

Orden: 403 → 422 → 403 `forbidden` → reintento (misma persona y mismo cuerpo → 200 `{replayed:true, snapshot}`; si no, 409 `client_request_reused`) → si `fingerprint` ≠ la efectiva de ahora → 409 `config_changed` `{provider, effectiveFingerprint, drift}` → INSERT (`kind=config`, `actor_type=admin`) + evento `integration.config_acknowledged`.

Respuesta: **201** `{replayed:false, snapshot: {uuid, provider, fingerprint, at, by, note}}`.

**Comando `ultron-studio:integrations-snapshot [--verify-voice] [--json]`** (lo lanza `devops` en cada despliegue; programarlo es del Hito 7):
- Por cada proveedor, inserta `kind=config, actor_type=system, actor_name='ultron-studio:integrations-snapshot'` **solo si** la huella efectiva ≠ la de la última instantánea `config` de ese proveedor, sea de quien sea. Es el registro de cambios.
- Con `--verify-voice` y el estado de voz `ELEVENLABS_UNVERIFIED`, llama a `VoiceProviders::current()->verifyCredentials()` y guarda `kind=probe, provider=voice`. La correspondencia `outcome` → `snapshot.outcome` es: `credentials_ok` → `ok`, `invalid_credentials` → `auth_failed`, `agent_not_found` → `degraded`, `unreachable` → `unreachable` y `error` → `error`. `facts` = `{outcome, httpStatus, agentAudioUlaw8000, latencyMs}`.
- Con otro estado, no llama a nada y sale con 1 y el motivo.

**I5.4 Sonda de solo lectura (ruta 56).**

**56 · `POST integrations/twilio/probe`** `{clientRequestId: required|uuid}`. Permiso `ultron.integrations_manage`. Cupos `ultron-studio-write` y `ultron-studio-probe` (6/h por persona y 20/h por red).

Orden:
1. 403 → 422 → 403 `forbidden`.
2. 409 `probe_disabled` si `ultron_studio.integrations.twilio_probe.enabled` no es `true`.
3. Reintento por `clientRequestId` → 200 `{replayed:true, snapshot}`.
4. 409 `twilio_not_configured` `{reason: 'missing_account_sid'|'missing_credential'}`.
5. Si la última sonda de Twilio tiene menos de `cooldown_seconds` (300) → 200 `{reused:true, snapshot: <esa>}`, sin red y sin evento.
6. Pasos en este orden: `account`, `numbers`, `caller_ids`, `calls`, `alerts`. Si `account` da `auth_failed` o `unreachable`, se para. Si se pasan 30 s en total, los pasos que faltan se anotan como `skipped`.
7. INSERT `kind=probe` (`client_request_id`, actor `admin`) + evento `integration.probe_run`.

Respuesta: **201** `{replayed:false, reused:false, snapshot: {uuid, at, outcome, facts, by}}`. **Un fallo del proveedor nunca es un 5xx.**

**Credencial:** si `twilio_probe.key_sid` y `key_secret` están los dos, usuario Basic = key SID, secreto = key secret y `credential = 'restricted_key'`. Si no, usuario = `otp.twilio.sid`, secreto = `otp.twilio.token` y `credential = 'otp'`. El SID de la cuenta (`otp.twilio.sid`) va siempre en la ruta.

```php
namespace App\Services\Twilio;                       // neutro (paquete TW)

final class TwilioReadOnlyClient
{
    public const PRODUCTION_API_BASE = 'https://api.twilio.com';
    public const PRODUCTION_MONITOR_BASE = 'https://monitor.twilio.com';
    public const STEPS = ['account', 'numbers', 'caller_ids', 'calls', 'alerts'];

    /** $nonProductionBase se IGNORA si app()->isProduction(): ahí, siempre los dos hosts oficiales (para api y monitor). */
    public function __construct(string $accountSid, string $username, string $secret, ?string $nonProductionBase = null, int $timeoutSeconds = 10);

    /**
     * Un GET, siempre a la primera página. Nunca sigue next_page_uri ni ninguna URL que venga en la respuesta.
     *   account    → {api}/2010-04-01/Accounts/{sid}.json
     *   numbers    → {api}/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json?PageSize=20
     *   caller_ids → {api}/2010-04-01/Accounts/{sid}/OutgoingCallerIds.json?PageSize=20
     *   calls      → {api}/2010-04-01/Accounts/{sid}/Calls.json?PageSize=50 + filtro de inicio ≥ hoy−7 días (UTC)
     *                (parámetro `StartTime>` de la API; se confirma con la documentación oficial al implementar)
     *   alerts     → {monitor}/v1/Alerts?PageSize=50&StartDate=<ahora−24 h, ISO 8601>
     * $sid tiene que casar con ^AC[0-9a-f]{32}$ (si no, InvalidArgumentException: nunca va a la ruta un texto libre).
     * Nunca lanza por la red: 401/403 → auth_failed; sin conexión o tiempo agotado → unreachable; otro estado o JSON no válido → error.
     *
     * @return array{ok: bool, status: ?int, body: ?array<string,mixed>, error: null|'auth_failed'|'unreachable'|'error'}
     */
    public function get(string $step, \DateTimeInterface $now): array;
}

final class TwilioProbeSanitizer                      // puro (paquete TW)
{
    /**
     * @param  array<string, array{ok:bool,status:?int,body:?array,error:?string}|array{skipped:true}>  $responses  por paso
     * @return array{outcome: 'ok'|'degraded'|'auth_failed'|'unreachable'|'error', facts: array<string,mixed>}
     */
    public static function sanitize(array $responses, string $credential): array;
}
```

**`facts` de `kind=probe, provider=twilio`** (lo único que se conserva):

```jsonc
{ "credential": "otp",
  "account":   { "status": "active", "type": "Full" },                    // status ∈ active|suspended|closed|null; type ∈ Trial|Full|null
  "numbers":   { "count": 1, "truncated": false, "voiceCapable": 0, "smsCapable": 1, "items": [ { "last4": "5483", "voice": false, "sms": true } ] },
  "callerIds": { "count": 0, "truncated": false },
  "calls7d":   { "total": 0, "truncated": false, "byStatus": {} },
  "alerts24h": { "total": 3, "truncated": false, "byCode": { "60200": 3 }, "byLevel": { "error": 1, "warning": 2 } },
  "errors":    [ { "step": "alerts", "outcome": "auth_failed", "httpStatus": 403 } ] }   // outcome ∈ auth_failed|unreachable|error|skipped
```

- Se **descartan** `friendly_name` (lleva el número formateado), `phone_number` (salvo sus 4 dígitos), `to`, `from`, `alert_text`, `request_url`, `request_variables`, `response_body` y cualquier otro texto.
- `truncated` = la primera página vino llena: el CRM pinta «N+».
- `outcome`:
  - `ok` si todos los pasos salen bien;
  - `auth_failed` o `unreachable` si falla `account`;
  - `degraded` si `account` sale bien y algún otro paso falla o se salta;
  - `error` en cualquier otro caso.

**I5.5 Twilio Voice tras bandera.** `voice.twilio.enabled` (`TWILIO_VOICE_ENABLED=false`), `voice.twilio.from` (E.164) y `voice.twilio.daily_call_cap` (0). **Nada las usa para ejecutar.** Solo las leen el panel y `VoiceCallPolicy`. En H6 no hay TwiML, webhook de voz, subcuenta ni marcador.

### I6. Voz y ElevenLabs (preparación, sin activar)

**I6.1 Interfaces (`App\Services\Voice`, neutro; paquete V).** Ni el Studio ni el motor; ninguno de los dos lo nombra en el otro sentido (§I9.5). **El puerto no tiene ningún método que inicie, registre ni transfiera una llamada**: esa parte la diseña el hito de voz.

```php
namespace App\Services\Voice;

interface VoiceAgentProvider
{
    /** 'none' | 'elevenlabs' */
    public function id(): string;
    /** Sin red: configuración y piezas que faltan. */
    public function status(): VoiceProviderStatus;
    /** Con red (un GET), SOLO por consola. Nunca lanza: los fallos son resultados. */
    public function verifyCredentials(): VoiceCheckResult;
}

final class VoiceProviderStatus
{
    public function __construct(
        public readonly string $provider,     // 'none' | 'elevenlabs'
        public readonly string $state,        // ELEVENLABS_NOT_CONFIGURED | ELEVENLABS_DISABLED | ELEVENLABS_UNVERIFIED
        public readonly bool $enabled,        // voice.elevenlabs.enabled
        /** @var list<string> */ public readonly array $missing,   // subconjunto ordenado de api_key, agent_id, webhook_secret, region
        public readonly ?string $region,      // clave válida de ElevenLabsConfig::REGIONS o null
    ) {}
    /** @return array{provider:string, state:string, enabled:bool, missing:list<string>, region:?string} */
    public function toArray(): array;
}

final class VoiceCheckResult
{
    public function __construct(
        public readonly string $outcome,              // credentials_ok | invalid_credentials | agent_not_found | not_configured | unreachable | error
        public readonly ?int $httpStatus,
        public readonly ?bool $agentAudioUlaw8000,    // null si la respuesta no lo dice
        public readonly int $latencyMs,
    ) {}
    public function toArray(): array;
}

final class NullVoiceProvider implements VoiceAgentProvider { /* id 'none'; status() = ElevenLabsConfig::fromConfig()->status(); verifyCredentials() → outcome 'not_configured', sin red */ }

final class VoiceProviders
{
    /** Siempre ElevenLabsConfig::fromConfig()->status(). */
    public static function status(): VoiceProviderStatus;
    /** ElevenLabsVoiceProvider solo si enabled === true y missing() === []; si no, NullVoiceProvider. Sin contenedor ni proveedores de servicio. */
    public static function current(): VoiceAgentProvider;
}
```

```php
namespace App\Services\Voice\ElevenLabs;

final class ElevenLabsConfig
{
    /** Lista CERRADA (solo las confirmadas en la documentación oficial al congelar). Otra región exige una enmienda. */
    public const REGIONS = ['default' => 'https://api.elevenlabs.io', 'eu' => 'https://api.eu.residency.elevenlabs.io'];
    public const AGENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    public static function fromConfig(): self;
    public function enabled(): bool;
    /** @return list<string> piezas que faltan o no tienen forma válida, en el orden api_key, agent_id, webhook_secret, region */
    public function missing(): array;
    public function region(): ?string;
    /** production → REGIONS[region]; fuera de production → voice.elevenlabs.base_url si está, si no REGIONS[region]. null sin región válida. */
    public function baseUrl(): ?string;
    /** missing ≠ [] → NOT_CONFIGURED; si no, !enabled → DISABLED; si no, UNVERIFIED. */
    public function status(): \App\Services\Voice\VoiceProviderStatus;
}

final class ElevenLabsVoiceProvider implements \App\Services\Voice\VoiceAgentProvider
{
    /**
     * GET {baseUrl}/v1/convai/agents/{agent_id} con las cabeceras xi-api-key y Accept: application/json, y timeout voice.elevenlabs.timeout_seconds.
     * 200 → credentials_ok (agentAudioUlaw8000 = true/false si la respuesta trae conversation_config.tts.agent_output_audio_format
     *       y conversation_config.asr.user_input_audio_format —nombres a confirmar con la documentación oficial al activar—; si no, null);
     * 401/403 → invalid_credentials; 404 → agent_not_found; sin conexión o tiempo agotado → unreachable; otro → error.
     * Nunca registra ni devuelve la clave, ni el cuerpo de la respuesta.
     */
    public function verifyCredentials(): \App\Services\Voice\VoiceCheckResult;
}
```

La clave y los secretos solo los lee `ElevenLabsVoiceProvider` en el momento de la petición. Una prueba estática comprueba que ningún otro fichero los toma de la configuración.

**I6.2 `config/voice.php`** (nuevo; todo apagado):

| Variable | Clave | Defecto | Regla |
|---|---|---|---|
| `ELEVENLABS_ENABLED` | `voice.elevenlabs.enabled` | `false` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)`: solo `true`, `1`, `on` o `yes` la encienden |
| `ELEVENLABS_API_KEY` | `voice.elevenlabs.api_key` | `null` | Secreto |
| `ELEVENLABS_AGENT_ID` | `voice.elevenlabs.agent_id` | `null` | Tiene que casar con `AGENT_ID_PATTERN` |
| `ELEVENLABS_WEBHOOK_SECRET` | `voice.elevenlabs.webhook_secret` | `null` | Secreto |
| `ELEVENLABS_REGION` | `voice.elevenlabs.region` | `default` | En minúsculas y sin espacios; fuera de `REGIONS` → `missing` incluye `region` |
| `ELEVENLABS_AGENT_PHONE_NUMBER_ID` | `voice.elevenlabs.agent_phone_number_id` | `null` | Solo integración nativa (descartada); no se exige |
| `ELEVENLABS_TOOL_SECRET` | `voice.elevenlabs.tool_secret` | `null` | Server tools futuras; no se exige |
| `ELEVENLABS_BASE_URL` | `voice.elevenlabs.base_url` | `null` | **Se ignora en `production`**; fuera, el simulador del E2E |
| `ELEVENLABS_TIMEOUT_SECONDS` | `voice.elevenlabs.timeout_seconds` | 10 | Entero; recorte a 3–30 |
| `TWILIO_VOICE_ENABLED` | `voice.twilio.enabled` | `false` | Como `ELEVENLABS_ENABLED` |
| `TWILIO_VOICE_FROM` | `voice.twilio.from` | `null` | E.164 (número de voz o caller ID verificado) |
| `TWILIO_VOICE_DAILY_CALL_CAP` | `voice.twilio.daily_call_cap` | 0 | Entero; recorte a 0–20 |

**I6.3 Estados y comandos.**
- `ELEVENLABS_NOT_CONFIGURED`: `missing ≠ []`. **Es el estado de producción en H6.**
- `ELEVENLABS_DISABLED`: piezas completas y bandera apagada.
- `ELEVENLABS_UNVERIFIED`: bandera encendida y piezas completas.
- `ELEVENLABS_VERIFIED`: **lo calcula el Studio**, no el `doctor`. Es `UNVERIFIED` más una instantánea `kind=probe, provider=voice, outcome=ok` de menos de 30 días.

Comandos:
- **`voice:doctor [--json]`** (paquete V) imprime `VoiceProviders::status()->toArray()` y las piezas que faltan **por nombre**, nunca valores. Sale con 0.
- **`voice:doctor --verify`** solo actúa con el estado `UNVERIFIED`. Llama a `verifyCredentials()`, imprime el resultado **sin guardarlo** (el espacio neutro no puede nombrar tablas del Studio) y sale con 0 si `credentials_ok` y con 1 en otro caso. Con otro estado, no llama a nada y sale con 1.
- **`ultron-studio:integrations-snapshot --verify-voice`** (paquete S) hace lo mismo y deja la evidencia (§I5.3).

**En H6 no se ejecuta ninguna verificación en producción**: son pasos del procedimiento de activación (I6.7).

**I6.4 Firma del webhook post-llamada (pura, sin ruta pública).**

```php
namespace App\Services\Voice\ElevenLabs;

final class ElevenLabsWebhookSignature
{
    public const TOLERANCE_SECONDS = 1800;
    public const FUTURE_SKEW_SECONDS = 300;
    /**
     * @return 'ok'|'missing_signature'|'malformed_signature'|'expired'|'from_future'|'mismatch'
     * @throws \InvalidArgumentException si $secret === ''  (un secreto vacío es un fallo de configuración, no una firma mala)
     */
    public static function verify(string $rawBody, ?string $header, string $secret, int $now): string;
}

final class ElevenLabsWebhookEnvelope
{
    public const TYPES = ['post_call_transcription', 'post_call_audio', 'call_initiation_failure'];
    /** El `type` si es uno de TYPES; null con JSON no válido, sin `type` o con otro valor. No conserva NADA más (ni la transcripción). */
    public static function type(string $rawBody): ?string;
}
```

Algoritmo de `verify`:
1. Cabecera `null` o vacía tras `trim` → `missing_signature`.
2. Se parte por `,`, se recortan las partes y se ignoran las vacías. Cada parte se parte por el **primer** `=`; una parte sin `=` → `malformed_signature`.
3. Tiene que haber exactamente un `t` que case con `^\d{1,12}$` y uno o más `v0` que casen con `^[0-9a-fA-F]{64}$` (se comparan en minúsculas). Si no, `malformed_signature`. Las claves desconocidas se ignoran.
4. `t < now − 1800` → `expired`; `t > now + 300` → `from_future`. Esta segunda es una decisión defensiva: el ejemplo oficial solo mira el pasado.
5. `esperado = hash_hmac('sha256', t . '.' . $rawBody, $secret)` en hexadecimal. Vale (`ok`) si **algún** `v0` coincide con `hash_equals`; si no, `mismatch`.
6. Siempre sobre el **cuerpo crudo**, nunca sobre JSON vuelto a serializar.

**I6.5 Política de llamadas (pura).**

```php
namespace App\Services\Voice;

final class VoiceCallPolicy
{
    /** Se gira SOLO en el commit que implemente las llamadas, con autorización expresa (como ENGINE_READS_VERSIONS en el H5). */
    public const CALLS_ENABLED_IN_CODE = false;
    public const REASONS = ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured',
        'elevenlabs_disabled', 'elevenlabs_unverified', 'not_an_active_voice_canary', 'source_not_allowed', 'daily_cap_zero'];
    public static function decide(VoiceCallContext $ctx): VoiceCallDecision;
}

final class VoiceCallContext
{
    public function __construct(
        public readonly bool $twilioVoiceEnabled,
        public readonly bool $twilioVoiceFromConfigured,
        public readonly string $providerState,   // ELEVENLABS_NOT_CONFIGURED | _DISABLED | _UNVERIFIED | _VERIFIED
        public readonly string $canary,          // active_voice | not_active | not_registered
        public readonly string $source,          // canary_registry | marketing_calls | other
        public readonly int $dailyCap,
    ) {}
}

final class VoiceCallDecision
{
    /** @param list<string> $reasons en el orden de VoiceCallPolicy::REASONS */
    public function __construct(public readonly bool $allowed, public readonly array $reasons) {}
}
```

`decide()` acumula los motivos que apliquen, en el orden de `REASONS`:
- `calls_disabled_in_code` si la constante es `false`;
- `twilio_voice_disabled` y `no_voice_number`;
- según `providerState`: `elevenlabs_not_configured`, `elevenlabs_disabled` o `elevenlabs_unverified` (`VERIFIED` no añade nada);
- `not_an_active_voice_canary` si `canary ≠ active_voice`;
- `source_not_allowed` si `source ≠ canary_registry`;
- `daily_cap_zero` si `dailyCap ≤ 0`.

`allowed` = sin motivos. **En H6, siempre `false`**, con `calls_disabled_in_code` el primero.

**I6.6 Ruta 45 · `GET integrations/voice` (sin red).**

```jsonc
{ "ok": true, "data": {
  "elevenlabs": { "state": "ELEVENLABS_NOT_CONFIGURED", "enabled": false, "missing": ["api_key", "agent_id", "webhook_secret"],
                  "region": "default", "lastVerification": null },      // o {at, outcome, agentAudioUlaw8000}
  "twilioVoice": { "enabled": false, "fromConfigured": false, "dailyCallCap": 0 },
  "policy": { "allowed": false, "reasons": ["calls_disabled_in_code", "twilio_voice_disabled", "no_voice_number",
                                            "elevenlabs_not_configured", "not_an_active_voice_canary", "daily_cap_zero"] },
  "levels": [ { "id": "text_simulation",  "available": true,  "cost": "none", "where": "laboratorio" },
              { "id": "integration_test", "available": false, "reasons": [ … ] },
              { "id": "real_call",        "available": false, "reasons": [ … ] } ] } }
```

- El contexto de la política lo arma el Studio: `canary` = `active_voice` si hay algún canario de voz `active`; si no, `not_active` si hay alguno vivo; si no, `not_registered`. `source` = `canary_registry`.
- `integration_test` y `real_call` reutilizan los motivos de la política. `integration_test` excluye `not_an_active_voice_canary` y `daily_cap_zero`, porque una prueba de integración futura no llamará a nadie.

**I6.7 Procedimiento de activación** (lo documenta `documentation-specialist` en `ULTRON_STUDIO_ELEVENLABS_READY.md`; **en H6 no se ejecuta**):
1. **Decisiones del propietario:**
   - grabación y retención (Ley 1581: consentimiento y plazo);
   - si en voz se pueden decir precios;
   - número de voz colombiano en Twilio o caller ID verificado (la sonda lo responde);
   - subcuenta de voz y su presupuesto;
   - nunca importar el número productivo de WhatsApp.
2. **Credenciales** (las pone el propietario en el servidor, nunca por chat): clave de API **acotada** (por endpoint, crédito e IP si la consola lo permite), `agent_id`, secreto del webhook y región; y, para Twilio Voice, el número o caller ID y la subcuenta.
3. **Pasos:**
   1. variables antes de cualquier `ironbody-migrate`;
   2. `config:cache` como `www-data` con `umask 077` y `chmod 600`;
   3. `voice:doctor` (debe salir `DISABLED`);
   4. `ELEVENLABS_ENABLED=true` y caché;
   5. `voice:doctor --verify`;
   6. `ultron-studio:integrations-snapshot --verify-voice` (`VERIFIED` en el Studio);
   7. evidencia en el informe.
4. **Lo que sigue cerrado aunque todo esté verde:** las llamadas (`CALLS_ENABLED_IN_CODE = false`) hasta el hito de voz. Ese hito trae su TwiML, el validador de Twilio extraído, la ruta del webhook post-llamada con este verificador, la lista de permitidos de canarios de voz, `TimeLimit`, el cupo diario y su propio canario.
5. **Vuelta atrás:** `ELEVENLABS_ENABLED=false` y caché; vuelve a `DISABLED` y no se ha llamado a nadie.

### I7. API: rutas, permisos, matriz, estado y errores

**I7.1 Rutas** (`/api/admin/ultron-studio/integrations/*`; 18 nuevas, 56 en total). Mismo grupo que las 38 de hoy:
- tope de cuerpo de 64 KB (413 `payload_too_large`);
- controladores en `App\Http\Controllers\Crm\UltronStudio`: `UltronStudioIntegrationController` (39, 43–46, 55, 56), `UltronStudioCampaignLinkController` (40, 41, 47, 48) y `UltronStudioCanaryController` (42, 49–54);
- una entrada por ruta en `AuthorizationMap::OVERRIDES`;
- `whereUuid('uuid')` y sin route-model binding.

| # | Método y ruta | Permiso | Cupos |
|---|---|---|---|
| 39 | `GET integrations/status` | `ultron.view` | `ultron-studio-read` |
| 40 | `GET integrations/meta/campaigns` | `ultron.view` | `ultron-studio-read` |
| 41 | `GET integrations/meta/links` | `ultron.view` | `ultron-studio-read` |
| 42 | `GET integrations/canaries` | `ultron.view` | `ultron-studio-read` |
| 43 | `GET integrations/twilio` | `ultron.view` | `ultron-studio-read` |
| 44 | `GET integrations/twilio/calls` | `ultron.view` | `ultron-studio-read` |
| 45 | `GET integrations/voice` | `ultron.view` | `ultron-studio-read` |
| 46 | `GET integrations/config` | `ultron.view` | `ultron-studio-read` |
| 47 | `POST integrations/meta/links` | `ultron.campaigns_manage` | `ultron-studio-write` |
| 48 | `POST integrations/meta/links/{uuid}/deactivate` | `ultron.campaigns_manage` | `ultron-studio-write` |
| 49 | `POST integrations/canaries` | `ultron.canary_manage` | `ultron-studio-write` |
| 50 | `POST integrations/canaries/{uuid}/authorize` | `ultron.canary_authorize` | `ultron-studio-write` |
| 51 | `POST integrations/canaries/{uuid}/activate` | `ultron.canary_manage` | `ultron-studio-write` |
| 52 | `POST integrations/canaries/{uuid}/deactivate` | `ultron.canary_manage` | `ultron-studio-write` |
| 53 | `POST integrations/canaries/{uuid}/revoke` | `ultron.canary_manage` | `ultron-studio-write` |
| 54 | `POST integrations/canaries/{uuid}/expectation` | `ultron.canary_manage` | `ultron-studio-write` |
| 55 | `POST integrations/config/acknowledge` | `ultron.integrations_manage` | `ultron-studio-write` |
| 56 | `POST integrations/twilio/probe` | `ultron.integrations_manage` | `ultron-studio-write` + `ultron-studio-probe` |

El cupo nuevo `ultron-studio-probe` va en `AppServiceProvider::limitadoresDelStudio()` y **solo ahí**: `Limit::perHour(6)` por persona y `Limit::perHour(20)` por red (`ClientNetwork`), con la misma respuesta 429 que los demás cupos del Studio. No se toca el Pint que ya falla en HEAD.

**Permisos nuevos** (`PermissionCatalog`: dominio `ultron`, etiqueta y ayuda literales):

| Llave | Etiqueta | Ayuda | Administrador por defecto |
|---|---|---|---|
| `ultron.campaigns_manage` | Asociar campañas de Meta | «Asociar campañas de Meta a la línea base o a una versión del Studio, y desactivar esas asociaciones. Solo queda registrado en el Studio: no cambia nada en Meta ni en lo que ULTRON dice o hace.» | Sí |
| `ultron.canary_manage` | Administrar números canarios | «Registrar números de prueba con el consentimiento de su titular, activarlos cuando otra persona los autorizó, desactivarlos y revocarlos. Son datos personales: se guardan como huella, y cifrados solo si son de voz. Nadie activa un número que registró. El motor no los lee en este hito: ULTRON sigue atendiendo solo la conversación canaria configurada en el servidor.» | Sí |
| `ultron.canary_authorize` | Autorizar números canarios de otra persona | «Autorizar números canarios registrados por otra persona (cuatro ojos): nadie autoriza lo que registró. No llega por defecto al Administrador.» | **No** |
| `ultron.integrations_manage` | Configuración autorizada y comprobación de Twilio | «Reconocer como autorizada la configuración vigente de Meta Ads, Twilio y voz, sin ver ni editar secretos (solo huellas), y lanzar la comprobación de solo lectura de Twilio. No llega por defecto al Administrador.» | **No** |

En `CrmPermission::byRole()`, `ultron.canary_authorize` y `ultron.integrations_manage` se suman a las exclusiones del Administrador. El token compartido no recibe ninguna: el dominio `ultron` está excluido. `StudioAccess` gana:
- las constantes `CAMPAIGNS_MANAGE`, `CANARY_MANAGE`, `CANARY_AUTHORIZE` e `INTEGRATIONS_MANAGE`;
- `integrationCapabilities(): array{campaignsManage:bool, canaryManage:bool, canaryAuthorize:bool, integrationsManage:bool}`;
- `isCanaryRegistrant(StudioCanaryNumber $n): bool`.

**Matriz de las pruebas** (las 56 rutas):

| Rol | 39–46 (lecturas) | 47–49, 51–54 | 50 | 55, 56 | Las 38 de antes |
|---|---|---|---|---|---|
| Sin sesión | 401 | 401 | 401 | 401 | 401 |
| Token compartido | 403 | 403 | 403 | 403 | 403 |
| Recepción, Entrenador, Administrativo | 403 | 403 | 403 | 403 | 403 |
| Administrador | 200 | Permitido (2xx o el 409 de su estado) | **403** | **403** | Sin cambios |
| Super Admin | 200 | Permitido | Permitido | Permitido | Sin cambios |

Un rol con una llave de escritura y sin `ultron.view` → 403 **antes** de buscar el recurso (`StudioAccess::requirePerson`, H-2 de F).

**I7.2 Ruta 39 · `GET integrations/status` (sin red).** Cada bloque se calcula por su cuenta: si su fuente falla, sale `{"id": …, "available": false}` y una línea de log con la clase del error, nunca un valor inventado.

```jsonc
{ "ok": true, "data": {
  "can":   { "campaignsManage": true, "canaryManage": true, "canaryAuthorize": false, "integrationsManage": false },
  "flags": { "writesEnabled": false, "twilioProbeEnabled": false },
  "providers": [
    { "id": "meta_ads", "available": true, "classification": "connected_read", "state": "ok", "reason": null,
      "evidence": { "accounts": 3, "lastSuccessAt": "…", "minutesSinceSuccess": 12, "staleAfterMinutes": 180, "coveredTo": "2026-10-31",
                    "syncEnabled": true, "graphVersion": "v26.0" } },
    { "id": "twilio", "available": true, "classification": "connected_otp_only", "state": "ok", "reason": null,
      "evidence": { "otpDriver": "twilio", "verifyActive": true, "sendEnabled": true,
                    "otpVerified": { "last24h": 14, "last7d": 96, "lastAt": "…" }, "estimatedSpentTodayUsd": 0.41 } },
    { "id": "twilio_voice", "available": true, "classification": "not_configured", "evidence": { "enabled": false, "fromConfigured": false } },
    { "id": "canaries", "available": true, "classification": "registry",
      "evidence": { "active": { "whatsapp": 0, "voice": 0 }, "pending": 0, "authorized": 0, "verdict": "engine_canary_not_registered" } },
    { "id": "elevenlabs", "available": true, "classification": "not_configured", "code": "ELEVENLABS_NOT_CONFIGURED",
      "evidence": { "enabled": false, "missing": ["api_key", "agent_id", "webhook_secret"] } } ],
  "note": "Las banderas son las del proceso web, con su caché de configuración." } }
```

**Reglas de clasificación:**
- **`meta_ads`:**
  - con `configurationProblem() ≠ null` → `not_configured`, con `reason` = el problema;
  - si no, según `statusAll()`: `ok` o `running` → `connected_read`; `stale` o `failed` → `degraded`, con `reason` = `stale` o `failed`;
  - con `meta.ads.sync_enabled = false` y la configuración correcta → `degraded` con `reason = sync_disabled`, sea cual sea el estado.
- **`twilio`:**
  - `otp.driver ≠ 'twilio'` → `not_configured` (`reason = otp_driver_not_twilio`);
  - `TwilioVerifyService::isActive()` falso → `not_configured` (`verify_incomplete`);
  - interruptor bajado → `degraded` (`kill_switch`);
  - gasto estimado ≥ techo duro → `degraded` (`hard_limit_reached`);
  - si no, `connected_otp_only`.

  Los recuentos de retos verificados son evidencia y **nunca** degradan por sí solos.
- **`twilio_voice`:** `not_configured` si falta `voice.twilio.from` o no tiene forma E.164; si no, `disabled` con la bandera apagada y `degraded` con ella encendida. En H6 no puede ser otra cosa: nada la usa.
- **`canaries`:** `registry`, con recuentos y el veredicto de §I4.5.
- **`elevenlabs`:** `not_configured` con `code = ELEVENLABS_NOT_CONFIGURED`; `disabled` con `ELEVENLABS_DISABLED`; `degraded` con `ELEVENLABS_UNVERIFIED`; `connected_read` con `ELEVENLABS_VERIFIED`. Nunca otra cosa en H6.

**Consola:** `ultron-studio:integrations-doctor [--json] [--expect=<id>:<valor>,…]`. Imprime lo mismo. `<valor>` es la clasificación; en `elevenlabs` vale también su `code`. Sale con 1 si algo esperado no coincide; lo usa el smoke.

**I7.3 Errores.** Los nuevos tienen su fábrica en `UltronStudioException`:

| Código | HTTP | Carga | Rutas |
|---|---|---|---|
| `integrations_writes_disabled` | 409 | — | 47–54 |
| `meta_not_configured` | 409 | `{reason}` | 47 |
| `campaign_not_found` | 404 | — | 47 |
| `link_not_found` | 404 | — | 48 |
| `version_not_linkable` | 409 | `{currentStatus}` | 47, 49, 54 |
| `campaign_already_linked` | 409 | `{current: CampaignLinkDto}` | 47 |
| `campaign_link_moved` | 409 | `{current: CampaignLinkDto\|null}` | 47 |
| `campaign_links_limit` | 409 | `{max}` | 47 |
| `canary_not_found` | 404 | — | 50–54 |
| `canary_number_exists` | 409 | — | 49 |
| `canary_live_limit` | 409 | `{max, channel}` | 49 |
| `canary_active_limit` | 409 | `{max, channel}` | 51 |
| `config_changed` | 409 | `{provider, effectiveFingerprint, drift}` | 55 |
| `probe_disabled` | 409 | — | 56 |
| `twilio_not_configured` | 409 | `{reason}` | 56 |
| `four_eyes_required` (reutilizado; texto propio) | 403 | `{action: 'authorize'\|'activate'}` | 50, 51 |
| `invalid_transition` (reutilizado; texto propio del sujeto) | 409 | `{currentStatus, allowedFrom}` | 48, 50–54 |
| `client_request_reused`, `integrity_mismatch`, `version_not_found`, `forbidden` `{required_permission}`, `person_required` | los suyos | los suyos | donde aplique |

**Orden general:**
1. Puerta y persona (401/403).
2. Forma (422 estándar de Laravel).
3. Permiso dentro del dominio (403).
4. Llaves (409).
5. Reintento.
6. Recurso (404).
7. Identidad (403).
8. Estado y topes (409).

Es el mismo criterio que D-13 del H5: la forma va antes que las llaves.

### I8. Auditoría (`StudioAudit`, solo inserción, en la misma transacción que el cambio)

**Sujetos nuevos:** `campaign_link`, `canary_number` e `integration` (el uuid de la instantánea).
- `StudioAuditEvent::SUBJECT_TYPES` pasa a 7.
- La migración 2 amplía `usae_subject_type_chk` en una sola sentencia: `ALTER TABLE ultron_studio_audit_events DROP CONSTRAINT usae_subject_type_chk, ADD CONSTRAINT usae_subject_type_chk CHECK (subject_type IN ('draft','version','rule','system','campaign_link','canary_number','integration'))`.
- `down()` la devuelve a los 4 sujetos, y solo cuando no hay filas con los sujetos nuevos (se niega antes, ver §«Despliegue»).
- `GET audit?subjectType=` admite los 3 nuevos sin tocar el controlador: valida contra `SUBJECT_TYPES`.

| `event` | Sujeto | `from → to` | `diff` |
|---|---|---|---|
| `campaign_link.created` | campaign_link | — → `active` | `{campaign: <id opaco>, accountRef, target: {kind, versionUuid?}, replaces: <uuid>\|null}` |
| `campaign_link.deactivated` | campaign_link | `active` → `inactive` | `{reason, replacedBy: <uuid>\|null}` |
| `canary_number.registered` | canary_number | — → `pending` | `{channel, expectedVersionUuid: <uuid>\|null}` |
| `canary_number.authorized` | canary_number | `pending` → `authorized` | `{}` |
| `canary_number.four_eyes_denied` | canary_number | — | `{action: 'authorize'\|'activate'}` (con `recordDenied`: deduplicado 10 min) |
| `canary_number.activated` | canary_number | `authorized` → `active` | `{}` |
| `canary_number.deactivated` | canary_number | `active` → `authorized` | `{reason}` |
| `canary_number.revoked` | canary_number | `*` → `revoked` | `{reason, cipherErased: bool}` |
| `canary_number.expectation_set` | canary_number | — | `{versionUuid: <uuid>\|null}` |
| `integration.config_acknowledged` | integration | — | `{provider, fingerprint, driftKeys: [..]}` |
| `integration.probe_run` | integration | — | `{provider: 'twilio', outcome}` |

- Los eventos con `reason` se escriben con `maxValueLength = StudioAudit::MAX_RULE_VALUE_LENGTH` (500), como `rule.*`, para no recortar el motivo.
- `summary` es genérico y fijo («Asociación de campaña creada», «Número canario registrado (WhatsApp)», «Configuración de Twilio reconocida»…).
- **Nunca en el libro:** teléfonos, sus 4 dígitos, huellas de teléfono, alias, ids de Meta en claro, nombres de campaña, valores o huellas de valores de configuración (solo la `fingerprint` del conjunto), textos del proveedor ni ids de conversación.
- Los rechazos por bandera (`integrations_writes_disabled`, `probe_disabled`) **no** se auditan (criterio D-6 del H5).

### I9. Frontera y pruebas de aislamiento

1. **`UltronEngineIsolationTest`** (dueño: paquete S). La lista blanca gana `database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php` y `…100002_add_ultron_studio_integration_guards.php`. Las rutas pasan de 38 a **56**. Aserciones nuevas:
   - ningún fichero de `ENGINE_ZONES` nombra `App\Services\Voice`, `App\Services\Twilio` ni `PhoneFingerprint`;
   - ningún fichero de `app/Services/Voice/` ni de `app/Services/Twilio/` nombra el Studio (ya lo cubre el rastreo global; se escribe para que el motivo se lea).
2. **`UltronStudioEngineReadOnlyTest`** (paquete S).
   - `ENGINE_READERS` gana **todo** fichero de `‹S›` que nombre `App\Services\Marketing\*` o `App\Models\Marketing*`, cada uno con su aserción de solo lectura. Previsiblemente: `StudioIntegrations.php`, `StudioMetaCampaigns.php`, `StudioCampaignLinks.php`, `StudioCanaryCoherence.php`, `StudioTwilioPanel.php` y `StudioIntegrationConfig.php`.
   - `StudioMetaCampaigns` lleva además la lista blanca de métodos de I3.3.
   - `STUDIO_TABLES` gana las 3 tablas.
   - El recorrido de comportamiento pasa por las **56** rutas, con las escrituras del H6 encendidas y la sonda contra `Http::fake`. Al final: huellas idénticas de toda tabla que no es del Studio, nada encolado y **ninguna** petición salvo los 5 GET de la sonda al simulador.
3. **`UltronStudioIntegrationsNoNetworkTest`** (S): con las llaves por defecto, `Http::preventStrayRequests()` y las 18 rutas → cero peticiones. Con la sonda encendida → solo los 5 pasos, solo GET y solo a los hosts esperados.
4. **`TwilioReadOnlyClientTest`** (TW; estática + `Http::fake`):
   - en el fichero no aparecen `post`, `put`, `patch`, `delete`, `asForm` ni `send`;
   - hosts oficiales en `production`, aunque haya base o `TWILIO_BASE`;
   - solo las 5 rutas y la primera página;
   - un `next_page_uri` en la respuesta no se pide;
   - un SID mal formado no llega a la ruta.
5. **`VoiceIsolationTest`** (V; estática):
   - `VoiceCallPolicy::CALLS_ENABLED_IN_CODE === false`;
   - en `app/Services/Voice/` la única llamada `Http::` es el GET de `ElevenLabsVoiceProvider::verifyCredentials()`;
   - no aparecen `outbound-call` ni `register-call`;
   - ninguna ruta de `routes/` apunta a `App\Services\Voice` ni a un controlador de voz;
   - solo `ElevenLabsVoiceProvider` lee `voice.elevenlabs.api_key`, `webhook_secret` y `tool_secret`.
6. **Centinela** (S): valores reconocibles en `TWILIO_TOKEN`, `TWILIO_AUTH_TOKEN`, `META_ADS_ACCESS_TOKEN`, `ELEVENLABS_API_KEY` y `ELEVENLABS_WEBHOOK_SECRET`, en el teléfono del lead canario de la semilla y en los números registrados. **No aparecen** en ninguna respuesta de las 18 rutas, en el libro, en `facts` ni en el log capturado.

### I10. CRM (paquete FE)

- **Página hija** `marketing/ultron-studio/integraciones`: diferida, `canActivate: [AuthGuard]` con `Permission.ULTRON_VIEW`, tras `environment.ultronStudioPreviewEnabled`, como el laboratorio (`app.routes.ts`). Su `StudioIntegrationsSession` se provee en la página.
- **Enlace** «Integraciones» junto a «Laboratorio» en la barra del tablero (solo la plantilla de `ultron-studio-page.ts`).
- **Ficheros** (en `‹F›`):
  - `studio-integrations-page.*`, `studio-integrations-session.ts`, `studio-integrations-api.{service,types}.ts`, `studio-integrations-parse.ts` y `studio-integrations-text.ts`;
  - `studio-integrations-kit.scss`;
  - `studio-integrations-status.*`, `studio-meta-campaigns.*`, `studio-campaign-link-dialog.*`, `studio-canary-numbers.*`, `studio-canary-register-dialog.*`, `studio-twilio-panel.*`, `studio-voice-panel.*` y `studio-config-drift.*`;
  - se tocan `studio-audit-panel.ts` (etiquetas de los 3 sujetos), `permissions.enum.ts` (+4) y, regenerados, `backend-permission-catalog.ts` y `testing/backend-integrations.testing.ts`.
- **Pestañas:**
  - «Estado»: las tarjetas de §I7.2 con la clasificación en texto.
  - «Campañas de Meta»: lista con filtros y las dos insignias; asociar (diálogo: línea base o versiones asociables); desactivar (`ib-reason-dialog` con `AVISO_DATOS_MOTIVO`).
  - «Números canarios»: lista enmascarada; registrar (E.164, canal, alias, casilla de consentimiento, aviso); acciones según `actions`; versión esperada frente a la que rige; banner de coherencia.
  - «Twilio»: OTP, coste, credenciales enmascaradas, webhook, llamadas, configuración autorizada y botón de la sonda (solo con `can.integrationsManage` y `flags.twilioProbeEnabled`).
  - «Voz»: estado en grande, piezas que faltan, los tres niveles y la política.
- **Textos obligatorios (literales):**
  - Campañas: «La asociación queda registrada en el Studio. No cambia nada en Meta ni en lo que ULTRON dice o hace.»
  - Canarios: «Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.»
  - Casilla del registro: «La persona titular de este número aceptó recibir mensajes de prueba (y, si es de voz, llamadas de prueba en el futuro).»
  - Alias: «Usa una función, no el nombre de una persona (p. ej., «Móvil de pruebas 1»).»
  - Voz: «En este hito no se hace ninguna llamada.»
  - Sonda: «Solo lee: no envía mensajes ni hace llamadas. Puede tardar unos segundos.»
  - Configuración: «Aquí no se ven ni se editan secretos: solo huellas. Las credenciales se cambian en el servidor.»
- **Reglas:**
  - sin permiso o con la bandera apagada no hay botón, y el motivo se lee en texto visible (D-F1 del H5);
  - el número completo nunca se pinta: no llega;
  - nunca `ultron-studio.scss` ni `studio-inspector.scss`; cada hoja nueva < 8 kB;
  - 390 px con tarjetas en lugar de tablas;
  - foco atrapado en los diálogos, `role=alert` en los errores, pestañas con teclado, insignias con texto (no solo color) y margen inferior por el asistente flotante (D-F9);
  - tolerar claves desconocidas y un backend H5 (404 en `integrations/*` → «El servidor todavía no tiene integraciones (Hito 6)»).
- **Fixtures:** `ultron-studio:export-fixtures` pasa a 6 ficheros. Gana `backend-integrations.testing.ts`, con los conjuntos cerrados de §I2, los códigos de §I7.3, las 4 llaves y DTOs de ejemplo producidos por los serializadores reales con datos fijos. Paridad en los specs.

### I11. Pruebas

**Fixture del contrato:** `tests/fixtures/ultron-studio/integrations-cases.json` + `integrations-cases.README.md`. Lo escribe a mano el tester desde este contrato, como `publication-cases.json` en el H5. Las firmas y las huellas se derivan además por una segunda vía independiente (`openssl`). Ruta en minúsculas junto a los demás. Secciones:

| Sección | Qué fija | Mínimo |
|---|---|---|
| `phone` | `StudioCanaryPhone::parse()` por canal: válidos, separadores admitidos, caracteres no admitidos, longitudes, país, móvil y fijo (60X), el `masked` | 60 casos |
| `alias` | `aliasError()` | 15 |
| `fingerprint` | `PhoneFingerprint::withKey()` con una `APP_KEY` de prueba (`base64:` y en crudo) | 8 vectores |
| `canaryTransitions` | Acción × estado × permiso × identidad → resultado o código | Todas las filas de I4.4 |
| `coherence` | Combinaciones de canario del entorno, teléfono y filas → `verdict` e `isEngineCanary` | Los 7 veredictos |
| `expectation` | Versión esperada × `effectiveVersion` → `expectation` y `stale` | 12 |
| `campaignLinkRules` | Destino × estado de la versión × activa existente × `replaces` → resultado o código | 20 |
| `metaClassification` | Problema de configuración × `statusAll()` × `sync_enabled` → `classification`, `state` y `reason`; `possiblyDeleted` | 15 |
| `configFacts` | Entorno de prueba → `facts` y `fingerprint` exactos; que ningún valor secreto aparezca | 6 |
| `elevenlabsSignature` | Cuerpo, cabecera, secreto y `now` → resultado (válida, caducada, futura, mal formada, sin `t`, dos `t`, varios `v0`, mayúsculas, partes vacías, secreto ajeno, cuerpo alterado) | 20 |
| `voiceDoctor` | Configuración → `state`, `missing` y `region` | 12 |
| `voiceCallPolicy` | Contexto → `reasons` en orden | 16 |
| `twilioProbeSanitize` | Respuestas crudas con contenido hostil (números en `friendly_name`, URLs y teléfonos en `alert_text`) → `outcome` y `facts` | 12 |
| `twilioSignatureOfficial` | El vector publicado por Twilio para `X-Twilio-Signature`, para la extracción futura del validador | 1 |

Si PHP o TS discrepan del fixture, **se corrige la implementación, nunca el fixture**. Lo que el README declare que el contrato no fijaba lo congela el lead en una enmienda.

| Capa | Qué | Dueño |
|---|---|---|
| Núcleo puro | Las secciones del fixture, contra las clases reales (`StudioIntegrationsParityTest` y los Unit de V y TW) | V, TW y S |
| API | Cada fila de I4.4 y cada código de I7.3, en su orden; idempotencia (reintento igual → 200, distinto → 409); evento del libro en la misma transacción; bandera apagada → 409 en 47–54 y 2xx en lecturas, 55 y 56 según su bandera; la matriz de I7.1 entera | S |
| PostgreSQL (`ops/pgunit.sh`, **puerta obligatoria**) | Disparadores (P0001); CHECK (23514), incluidos los dos de cuatro ojos con un UPDATE crudo; `uscn_cipher_only_shrinks`; parciales (23505); el CHECK ampliado del libro; `down()` que se niega. Carreras con barrera (`pg_blocking_pids` + escalón de 40 ms): dos registros del mismo número; dos asociaciones de la misma campaña; autorizar frente a revocar; 4 activaciones contra un tope de 3; reconocer frente a un cambio de configuración | Tester |
| Mutaciones | Quitar: los cuatro ojos del servicio (el CHECK tiene que cazarlo aparte), la regla de canal, el borrado del cifrado, el saneado de la sonda, la fijación de hosts, la constante de llamadas, el `try` de la coherencia (tiene que salir `unreadable`, nunca `in_sync`), la lista blanca de Meta y el cálculo de alias en la configuración. Cada mutación tumba su prueba | Tester |
| Aislamiento | §I9 | S, TW y V |
| CRM | Specs del módulo y de cada componente; paridad con el fixture exportado; texto hostil; permiso desconocido; backend H5 (404); build con el presupuesto de chunks; `check:literals` | FE |
| E2E con pila real | Ver abajo | Tester |
| Línea base | Suite completa contra la base **el mismo día** y fallos de base documentados (memoria «56 fallos tolerados», más los de PG que ya fallaban) | Tester |

**E2E** (dentro del arnés existente `Iron-Body_Front/e2e/ultron-studio/run.sh`):
- **Simuladores:** `mock-twilio.cjs` en **:8095** (responde a los 5 pasos de la sonda; un modo devuelve 401 y otro, alertas hostiles) y `mock-elevenlabs.cjs` en **:8096** (`GET /v1/convai/agents/{id}`).
- **Entorno de la instantánea:** `ULTRON_STUDIO_TWILIO_PROBE_BASE=http://127.0.0.1:8095` y `ELEVENLABS_BASE_URL=http://127.0.0.1:8096`. `APP_ENV` ≠ `production`, así que las bases se respetan.
- **Semilla** `specs/h6-seed.php`: 3 cuentas, campañas en `meta_ad_entities` y pasadas en `meta_sync_runs` (una cuenta vieja); la conversación canaria con un lead y su teléfono; ninguna variable de ElevenLabs.
- **Especificación** `specs/h6-integrations.cjs`:
  1. estado con sus clasificaciones;
  2. campañas, asociar a la línea base, sustituir por una versión y desactivar;
  3. registrar como Administrador → el Administrador no ve «Autorizar» → autorizar como Super Admin → activar como Super Admin → `in_sync` cuando el número registrado es el del lead canario;
  4. un segundo número activo → `registry_has_extra_active`;
  5. revocar;
  6. Twilio con la sonda encendida (saneada; el modo 401 da `auth_failed`; el enfriamiento da `reused`);
  7. voz en `ELEVENLABS_NOT_CONFIGURED`;
  8. `voice:doctor --verify` con piezas de prueba y bandera encendida, contra el simulador;
  9. capturas a 1440 y 390 px.
- **La valla `fence.cjs` vacía al cerrar.** Los simuladores están en `127.0.0.1`, dentro de `no_proxy`.

### I12. Configuración

**`config/ultron_studio.php`, bloque nuevo `integrations`** (mismo patrón que el resto: `filter_var` para booleanos, `$entero` y recorte para los topes):

| Variable | Clave | Defecto | Valores | Inválido |
|---|---|---|---|---|
| `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` | `ultron_studio.integrations.writes_enabled` | `false` | booleano | `false` |
| `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` | `…integrations.twilio_probe.enabled` | `false` | booleano | `false` |
| `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` | `…twilio_probe.key_sid` | `null` | SID de API key | — |
| `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET` | `…twilio_probe.key_secret` | `null` | secreto | — |
| `ULTRON_STUDIO_TWILIO_PROBE_BASE` | `…twilio_probe.base` | `null` | URL; **se ignora en `production`** | se ignora |
| — | `…twilio_probe.cooldown_seconds` | 300 | fijo en el código | — |
| `ULTRON_STUDIO_CANARY_MAX_ACTIVE` | `…integrations.canary.max_active` | 3 | 1–10 (recorte) | 3 |
| — | `…integrations.canary.max_live` | 10 | fijo | — |
| `ULTRON_STUDIO_CANARY_COUNTRY_CODES` | `…integrations.canary.country_codes` | `['57']` | lista separada por comas de prefijos `^[1-9]\d{0,2}$` | `['57']` si queda vacía |
| `ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE` | `…integrations.campaign_links.max_active` | 50 | 1–200 (recorte) | 50 |

Además, **calculado al cachear** (`…integrations.twilio_aliases`, como `forbidden_key_sha256` del H4; solo nombres y booleanos, nunca valores):

```php
'twilio_aliases' => [
    'sid_source'       => filled(env('TWILIO_SID')) ? 'TWILIO_SID' : (filled(env('TWILIO_ACCOUNT_SID')) ? 'TWILIO_ACCOUNT_SID' : null),
    'token_source'     => filled(env('TWILIO_TOKEN')) ? 'TWILIO_TOKEN' : (filled(env('TWILIO_AUTH_TOKEN')) ? 'TWILIO_AUTH_TOKEN' : null),
    'sid_consistent'   => filled(env('TWILIO_SID')) && filled(env('TWILIO_ACCOUNT_SID')) ? hash_equals((string) env('TWILIO_SID'), (string) env('TWILIO_ACCOUNT_SID')) : null,
    'token_consistent' => filled(env('TWILIO_TOKEN')) && filled(env('TWILIO_AUTH_TOKEN')) ? hash_equals((string) env('TWILIO_TOKEN'), (string) env('TWILIO_AUTH_TOKEN')) : null,
],
```

`config/voice.php`: §I6.2. **`.env.example`** gana estas variables y las de voz con su defecto seguro. Gana también, comentadas y sin valores, las de Meta Ads y los alias de Twilio que hoy faltan: `META_ADS_ACCESS_TOKEN`, `META_ADS_GRAPH_VERSION`, `META_ADS_SYNC_ENABLED`, `META_AD_ACCOUNT_IDS`, `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_SEND_ENABLED` y `TWILIO_VERIFY_RATE_LIMIT_NAME`. La línea ajena `FACTUS_CONSUMER_FINAL_NAME` no se toca.

### I13. Datos personales (Ley 1581)

| Dato | Dónde | Protección |
|---|---|---|
| Teléfono de un canario de WhatsApp | `ultron_studio_canary_numbers` | Solo huella HMAC y 4 dígitos; no se puede recuperar ni con la `APP_KEY` |
| Teléfono de un canario de voz | Ídem | Cifrado (`encrypted`, `APP_KEY`); **borrado al revocar** (lo exige `uscn_revoke_chk`) |
| Alias | Ídem | Validado (sin series de dígitos ni `@`) y con aviso; **no** va al libro |
| Consentimiento | Ídem | Declaración de quien registra (identidad y hora). El soporte lo custodia el propietario |
| Teléfono del lead canario | Memoria del proceso, en la coherencia | Solo para la huella; `unset` después; nunca a log, respuesta ni base |
| Llamadas (`marketing_calls`) | Tabla existente | El Studio no lee `to_phone`, `from_phone`, `transcript`, `summary` ni `metadata` |
| Datos de Twilio de la sonda | `facts` | Recuentos y los 4 dígitos de **los números del gimnasio** |
| Nombres del personal | Instantáneas de actor en las 3 tablas y en el libro | Como F y H5. El procedimiento de redacción con el rol dueño (pendiente del Hito 7) cubre también las 3 tablas nuevas |

**Rotar la `APP_KEY`** deja inservibles las huellas de teléfono, los cifrados de voz, los ids opacos de Meta y las huellas de configuración. Los números se vuelven a registrar y la configuración se vuelve a reconocer. Rotarla es un evento de todo el sistema (E2.4 del H5); al runbook.

---

## Reparto, orden y dueños

**Interfaces y formas que este contrato ya fija** (nadie las cambia sin enmienda):
- las firmas PHP de I4.3, I5.4, I6.1, I6.4 e I6.5;
- las columnas de I3.1, I4.1 e I5.3;
- los DTO de I3.4–I3.5, I4.9, I5.2–I5.4, I6.6 e I7.2;
- los conjuntos de I2 y los códigos de I7.3.

| Paquete | Agente | Ficheros (exclusivos) | Necesita |
|---|---|---|---|
| DB | `database-specialist` | Las 2 migraciones y su prueba de migración | — |
| V | `backend` | `app/Services/Voice/**`, `config/voice.php`, `app/Console/Commands/Voice/**`, `tests/Unit/Voice/**`, `tests/Feature/Voice/**` | — (el fixture, cuando exista) |
| TW | `backend` | `app/Services/Twilio/**`, `tests/Unit/Twilio/**` | — |
| S | `backend` | `‹S›/**`, `app/Support/Privacy/PhoneFingerprint.php`, `app/Models/UltronStudio/**`, `app/Http/Controllers/Crm/UltronStudio/**`, `app/Console/Commands/UltronStudio/**`, `app/Exceptions/UltronStudioException.php`, `routes/ultron-studio.php`, `config/ultron_studio.php`, `app/Support/Access/{AuthorizationMap,PermissionCatalog,CrmPermission}.php`, `app/Providers/AppServiceProvider.php` (**solo** `limitadoresDelStudio()`), `.env.example`, `tests/{Unit,Feature}/UltronStudio/**` salvo los fixtures | DB; interfaces de V y TW |
| T | `tester` | `tests/fixtures/ultron-studio/integrations-cases*`, pruebas adversariales nuevas (`*Race*`, `*Mutation*`), `Iron-Body_Front/e2e/ultron-studio/**` | — (el E2E completo, tras S y FE) |
| FE | `frontend` | `‹F›/**` (nunca `ultron-studio.scss` ni `studio-inspector.scss`), una entrada en `src/app/app.routes.ts`, `src/app/models/permissions.enum.ts`, catálogo y fixtures regenerados | Este contrato; integración tras S |
| OPS | `devops` | `‹B›/docs/ultron-studio/ops/{deploy-h6-backend-fase1.sh,h6-integrations-scope.sh,smoke-h6.py,smoke-run-h6.sh,h6-schema-check.sql}` | S |
| DOC | `documentation-specialist` | `‹B›/docs/ultron-studio/*.md` (salvo este contrato y el plan) y `ops/README.md` | — |

**Traspasos:** V y TW **no** tocan `.env.example`: dan a S sus variables. Solo S toca `AppServiceProvider.php`, y solo en ese método. `routes/console.php` no se toca.

**Orden:**
1. DB, V, TW, T (fixture e infraestructura del E2E) y FE (capa de datos) **en paralelo**.
2. S.
3. FE (integración).
4. T (carreras, mutaciones y E2E).
5. `api-security-specialist` y `security`: desde el paso 2; `security` repasa el CRM tras el 3.
6. OPS, desde el paso 2.
7. `reviewer`, tras 4 y 5 cerrados.
8. DOC.

**Primer punto verde: al cerrar S.** Todas las suites pasan con las llaves por defecto, el motor no ha cambiado ni un byte, ninguna ruta sale a la red y las escrituras del hito están cerradas. **El hito no se despliega con un bloqueante del `reviewer` abierto.**

---

## Despliegue por fases, reversión y criterio de cierre

**Requisito previo:** el Hito 5 desplegado al menos en su fase 1 (esta rama lo contiene).

**Puerta antes de cada fase:**
- Studio + Access + Chaos en SQLite;
- grupo del Studio en PG con `ops/pgunit.sh`;
- suites de ULTRON, Marketing y OTP iguales a la base;
- `auth:coverage --unmapped` vacío; 56 rutas del Studio; Pint en lo tocado;
- módulo del CRM y build;
- E2E `h6-integrations` y regresión del H5 en verde, con la valla vacía;
- `security`, `api-security-specialist`, `tester` y `reviewer` cerrados;
- respaldo preparado;
- y la **puerta de diff** vacía:

```
git diff <base> --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api app/Services/Sms app/Services/Otp \
  routes/api.php routes/marketing.php routes/console.php config/marketing.php config/meta.php config/otp.php \
  app/Services/UltronStudio/StudioCatalog.php
```

**Scripts de `devops`:**
- `deploy-h6-backend-fase1.sh`:
  - aborta con cambios locales;
  - ejecuta `git` **como root con `umask 022`**, porque el árbol de `/var/www/api` y su `.git` son de root desde el 25-10 (I-3 del reviewer del H5);
  - tras el merge comprueba, para **cada** fichero de `git diff --name-only <PREVIO>..HEAD`, que `www-data` lo lee (`sudo -u www-data test -r`), y aborta si alguno no;
  - copia el `.env` (root, 600) y comprueba que Dotenv lo parsea antes de tocar cachés (B-1 del H5);
  - escribe las variables **antes** de `ironbody-migrate`;
  - hace las cachés con `umask 077`, `chown www-data` y `chmod 600`, y reinicia los workers;
  - termina con `ultron-studio:integrations-snapshot`.
- `h6-integrations-scope.sh writes on|off` y `probe on|off`: escribe la llave, rehace la caché igual, reinicia y compara la configuración efectiva sin imprimir secretos.
- `smoke-h6.py` y `smoke-run-h6.sh`: cuenta temporal, sin filas positivas en la fase 1.
- `h6-schema-check.sql`: recuentos de disparadores, CHECK e índices frente al informe de `database-specialist`.

### Fase 1 · código desplegado, escrituras y sonda apagadas

**Cuándo** (autorización del 25-10): con la puerta en verde y el H5 en su fase 1.

1. Respaldo en `/var/backups/ironbody/ultron-studio-h6-<fecha>/` (tablas `ultron_studio_*`, árbol desplegado y `.env`), en 700.
2. Variables **antes de migrar**, explícitas aunque coincidan con el defecto: `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false`, `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`, `ULTRON_STUDIO_CANARY_MAX_ACTIVE=3`, `ELEVENLABS_ENABLED=false`, `TWILIO_VOICE_ENABLED=false` y `TWILIO_VOICE_DAILY_CALL_CAP=0`.
3. Código; `sudo /usr/local/bin/ironbody-migrate` (2 migraciones); `h6-schema-check.sql`.
4. `route:cache` como `www-data`, cachés con su `chown` y `chmod 600`, y reinicio de los workers: todos RUNNING.
5. CRM con `ops/deploy-crm.sh` (rsync **sin** `--delete`).
6. `ultron-studio:integrations-snapshot`: el primer «observado» de los 3 proveedores.
7. `smoke-run-h6.sh`:
   - 56 rutas;
   - `integrations-doctor --expect=meta_ads:connected_read,twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED` → 0 (Meta `degraded` se informa como dato, no como fallo del despliegue);
   - `GET integrations/twilio` con `aliasesConsistent = {sid:true, token:true}` y `calls.total = 0`;
   - escrituras 47–54 → 409 `integrations_writes_disabled`; sonda → 409 `probe_disabled`;
   - canarios vacíos con `engine_canary_not_registered`;
   - campañas con su antigüedad;
   - voz `ELEVENLABS_NOT_CONFIGURED` con `policy.allowed=false`;
   - configuración `never_authorized` con su `observed`.
8. Controles de no regresión, de solo lectura:
   - sonda del contrato n8n↔backend sin efectos, como en el H5;
   - `meta_sync_runs` sigue sumando pasadas;
   - `usage-trigger` responde 403 sin firma.

   **No** se lanza un OTP de prueba.
9. Verificación a los 15 min y a las 24 h: 0 respuestas 5xx nuevas y workers sin BACKOFF.

### Fase 2 · escrituras encendidas: T1, configuración autorizada y sonda

**Cuándo** (autorización del 25-10): con la fase 1 verificada y **dos personas** disponibles.

1. `h6-integrations-scope.sh writes on`.
2. **T1:** quien tiene el teléfono de la conversación canaria lo registra (`whatsapp`); otra persona con `ultron.canary_authorize` lo autoriza y lo activa (≠ quien registró) → **`in_sync`**. En el libro quedan `registered`, `authorized` y `activated` con dos identidades. La cuenta temporal de smoke solo vale para ensayar, **nunca** para el T1 real.
3. Un Super Admin reconoce la configuración de `meta_ads`, `twilio` y `voice` → `authorized`. Puede adelantarse a la fase 1: no depende de la bandera.
4. `h6-integrations-scope.sh probe on` → una pulsación de la sonda. Su instantánea (cualquier `outcome`) es la evidencia: estado de la cuenta y respuesta a «¿hay número de voz o solo caller ID?». La bandera puede quedarse encendida: GET sin coste, con enfriamiento y cupo.
5. Asociar campañas en producción **no** es condición (el E2E probó el flujo).

### Reversión (para `ULTRON_STUDIO_ROLLBACK.md`)

| Nivel | Cómo | Efecto |
|---|---|---|
| 1 · Studio | Desactivar asociaciones y revocar números | El registro deja de declarar. El motor no notaba nada antes ni lo nota después |
| 2 · Banderas | `h6-integrations-scope.sh writes off` y `probe off` | Escrituras y sonda cerradas tras la caché de configuración |
| 3 · Código | Volver al backend del H5 y al CRM anterior | Seguro con filas del H6: las tablas se quedan sin lector y el libro pinta los sujetos nuevos en genérico |
| 4 · Esquema | `down()` | **Nunca en producción** (límite del 25-10). En otros entornos, solo sin filas en las 3 tablas ni eventos con los sujetos nuevos: si los hay, `down()` se niega con un mensaje claro antes de tocar nada, con los cerrojos ya tomados (patrón del H5) |

### Criterio de cierre

**`HITO_6_COMPLETED`**, con la evidencia en los informes, cuando se cumple todo esto:
1. Las fases 1 y 2 están ejecutadas.
2. El T1 está en `in_sync` en producción, con dos identidades en el libro.
3. La configuración de los 3 proveedores está reconocida.
4. Hay una sonda ejecutada con su instantánea.
5. El panel en vivo coincide con la clasificación al congelar (o explica la diferencia).
6. D sigue en **`ELEVENLABS_NOT_CONFIGURED`**, con el adaptador, el `doctor`, la firma, la política y el procedimiento entregados.

**`HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`** si falta la segunda persona para el T1 o el despliegue previo del H5.

**Fuera de este hito, con autorización expresa aparte:** T2 y T3, cualquier llamada, cualquier escritura en Meta y encender ElevenLabs.

**Fuentes externas consultadas al congelar (31-10-2026):**
- Post-call webhooks: <https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks>
- Register Twilio calls: <https://elevenlabs.io/docs/agents-platform/phone-numbers/twilio-integration/register-call>
- Data residency: <https://elevenlabs.io/docs/overview/administration/data-residency.md>

---

## Enmienda 1 (01-11-2026): decisiones del lead durante el bloque 1 (DB, V, TW, fixture y capa de datos del CRM)

Fuentes: `plans/informes-h6/informe-db.md` (§6, §10), `informe-voz.md`, `informe-twilio.md` («Decisiones fuera del contrato», «Para S», «Para el tester»), mensajes del tester del fixture y de la capa de datos del CRM. Donde esta enmienda contradice I1–I13, manda esta.

### E1.1 Fixture y formas comunes
- **Q-1 (importes en `facts`):** `costSoftUsd`, `costHardUsd` y cualquier otro importe de `facts` viajan como **texto con dos decimales** (`"6.00"`, `"12.00"`): `StudioContentHasher::canonicalize` rechaza decimales. Los recuentos vacíos se serializan como `[]` (el canonizador no admite objetos vacíos).
- **Q-2 (etiquetas de estado):** `statusLabel` de una versión es exactamente `StudioVersionStatus::label()` («Canario», «Publicada», «Sustituida»…); los ejemplos «En canario» del contrato eran ilustrativos.
- **PCRE (fijado por el fixture):** toda expresión que termine en `$` lleva el modificador `D` (sin él PHP acepta un salto de línea final), y los dígitos se escriben como `[0-9]` sin `/u` (con `/u` entran dígitos de ancho completo y arábigo-índicos). Afecta a E.164, al alias, al `agent_id` de ElevenLabs y al SID de Twilio.
- **`replayed`:** puede llegar dentro de `data` (contrato) o fuera (como en el H5); el CRM acepta las dos formas.

### E1.2 Voz (paquete V)
- **`ElevenLabsVoiceProvider::secretFingerprints(array $kCfg)`** es la única vía para las huellas de 8 hex de la clave de API, el secreto del webhook y el secreto de las herramientas (cierra el hueco entre I5.3 e I9.5): S la consume y **no lee** esas claves de `voice.elevenlabs.*` ni el bloque entero; `VoiceIsolationTest` lo vigila.
- Aceptadas las seis decisiones de `informe-voz.md`, entre ellas: un 200 cuyo cuerpo no es un objeto JSON cuenta como `error` (nunca `credentials_ok`); un `v0` mal formado junto a otro válido da `malformed_signature`; regiones solo `default` y `eu`; en `production` se ignora la URL base configurable; la verificación es un GET sin redirecciones ni reintentos.
- `voice:doctor` es el único comando `voice:*`, no está programado y no existe ninguna ruta de voz (`VoiceBoundaryTest`).

### E1.3 Esquema (paquete DB)
- **D-1:** `uscl_inactive_is_final` cubre solo las seis columnas del cierre (`status`, `deactivated_*`, `deactivation_reason`) y la nueva guarda **`uscl_replacement_once`** deja anotar `replaced_by_link_id` una sola vez (de NULL a un valor, solo en una fila que queda `inactive`). Con la guarda literal de I3.2, la sustitución del paso 11 de I3.4 era imposible en los dos motores. Columnas y DTO no cambian.
- **D-2:** `usis_outcome_chk` = `(kind = 'probe' AND outcome IS NOT NULL AND outcome IN (…)) OR (kind = 'config' AND outcome IS NULL)`.
- **Recuentos tras migrar** (para `h6-schema-check.sql` y el script de la fase 1): PostgreSQL **43 disparadores** (antes 30) y **49 CHECK** (antes 27) en `ultron_studio_%`; SQLite 30 disparadores (antes 20); 4 índices parciales (antes 2) con su `WHERE` en los dos motores; 14 tablas `ultron_studio_*` (antes 11); `ultron_studio_forbid()` intacta (`4ad748b5…`). En PG el libro solo cambia el CHECK de sujetos (4 → 7); en SQLite ese CHECK no existe y la tabla no se reconstruye.
- **Operación:** si la segunda migración cae por `lock_timeout` (55P03), deja las tres tablas sin guardas hasta el reintento: devops reintenta hasta que el recuento dé 43/49 **antes** de servir el código nuevo.
- S añade las dos migraciones a la lista blanca de `UltronEngineIsolationTest` (I9.1) y ajusta los recuentos globales de `UltronStudioPublicationSchemaTest` con el parche de `informe-db.md` §10.

### E1.4 Twilio (paquete TW)
- **Convenciones del saneador** (`TwilioProbeSanitizer`, fijadas para el fixture y para S): un paso fallido o saltado deja su bloque en `null`; un paso ausente cuenta como `skipped`; lo que no está en su conjunto cerrado cuenta como `other`; `last4` solo sale de un E.164 estricto; los códigos de alerta de Monitor solo de 1 a 6 cifras; los recuentos vacíos salen `[]`.
- `TwilioReadOnlyClient`: solo `__construct` y `get`; en `production` hosts oficiales siempre (ignora la base configurable y la del simulador); primera página siempre; 10 s por consulta y 5 s de conexión; sin reintentos ni redirecciones; 401/403 → `auth_failed`, 429/5xx/3xx → `error`, tiempo agotado o sin conexión → `unreachable`, cuerpo inesperado → `error`; el token que devuelve la ficha de la cuenta se elimina del cuerpo; la credencial va en `SensitiveParameterValue` (no sale en trazas ni volcados; el cliente no se serializa); el SID se valida con `ACCOUNT_SID_PATTERN` (`^AC[0-9a-f]{32}$` con `D`) **antes** de construir el cliente (si no, 500).
- S reparte el presupuesto de 30 s de la sonda entre los pasos (cada cliente con el tiempo que quede); el enfriamiento de 300 s es fijo en el código. Variables: `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`, `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID`, `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET` y `ULTRON_STUDIO_TWILIO_PROBE_BASE` (ignorada en `production`).
- Nota para `security` (sin cambio): fuera de `production` la base del simulador no se limita a `127.0.0.1`, igual que el cliente del laboratorio.

### E1.5 Para el paquete S (de la capa de datos del CRM)
- `OtpCostGuard::snapshot()` devuelve claves en `snake_case` (y `send_enabled`): S las convierte a la forma `camelCase` de I5.2.
- `lastVerification.outcome` de voz usa el conjunto de verificación de voz (`credentials_ok`…), no el de la sonda.
- Para `applied` con las dos partes vacías el CRM acepta `part: null` y `state: 'legacy'`.

---

## Enmienda 2 (01-11-2026): lo que fija el fixture `integrations-cases.json` (704 casos, 22 secciones) y decisiones del lead sobre sus preguntas

`backend/tests/fixtures/ultron-studio/integrations-cases.json` y su `integrations-cases.README.md` forman parte del contrato, como el fixture del Hito 5: **todas las decisiones que el README declara que el contrato no fijaba quedan congeladas; si PHP o TS discrepan, se corrige la implementación, nunca el fixture.** Verificado por el tester con una derivación independiente (openssl, 686 casos sin discrepancias), 106 implementaciones equivocadas simuladas (todas caen), cruce con `StudioContentHasher::canonicalJson`, `hash_hmac` y `number_format` de PHP 8.3, y los 6 vectores oficiales de firma de Twilio. Repetir: `python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py --php`.

**Decisiones del lead (todas las recomendaciones del fixture se aceptan):**
- **Q-4** `ELEVENLABS_VERIFIED` exige que la **última** sonda de verificación sea `ok` y de menos de 30 días (una clave revocada deja de estar «verificada» en la siguiente sonda).
- **Q-5** Firma de ElevenLabs en estricto: `trim` de PHP sobre la cabecera entera, sin recortar clave ni valor; claves en minúsculas; `v0` mal formado junto a otro válido → `malformed_signature` (E1.2).
- **Q-6** `isEngineCanary` es `false` en una fila revocada aunque su huella coincida con la del motor; `activeWhatsapp` con el teléfono del lead canario ilegible (`unreadable`) lo decide S y lo documenta en `informe-api.md`.
- **Q-7** Sin versión esperada y con `effectiveVersion.source = none`, `expectation` = `none` (no `not_applicable`).
- **Q-8** `baseUrl()` con una región inválida, o `base_url` fuera de `production` sin valor, → `null`.
- **Q-9** `aliasError()` no recorta (en HTTP recorta `TrimStrings`); el alias con tildes descompuestas (NFD) se rechaza: el CRM normaliza a NFC antes de enviar (O-3).
- **Q-10** En `facts`: un valor ausente es solo `{present:false}`; el host se compara exacto; la región de voz va tal cual.
- **Q-11** Ruta 56: el SID mal formado responde `missing_account_sid`; el reintento de otra persona con la misma `clientRequestId` → `client_request_reused`.
- **Q-12** Convenciones D-10 del saneador confirmadas: ficha sin `sid` válido, recorte de la página y sobres raros, tal como las cumplen el fixture, los casos de TW y la clase (E1.4).
- **Q-13** `last4` usa el E.164 del contrato (**8 a 15 cifras**); el paquete TW (`TwilioProbeSanitizer`, hoy 7 a 15) se alinea al fixture.
- **O-2** (S y FE): `not_allowed_for_channel` también sale con un número colombiano de longitud equivocada y en voz; el texto del CRM se generaliza («Ese número no sirve para este canal.») con la pista de WhatsApp solo cuando el canal es `whatsapp` y el número no es móvil.
- **O-4** (FE): PHP escribe `[]` para un mapa vacío; el CRM acepta `[]` y `{}` en `byStatus`, `byCode`, `byLevel` y en `calls.byStatus` / `calls.byDirection` (ruta 43).
- **O-1** (security, nota): `\d{4,}` no detecta un teléfono escrito en grupos de 2 o 3 dígitos; es la regla literal y se revisa en el Hito 7.
- **O-5** (nota): el validador privado de `usage-trigger` no reproduciría el vector `duplicateKeys` de Twilio; solo importa el día que se extraiga (fuera de alcance).
- **O-6**: los fixtures propios de V y TW no sustituyen al oráculo: la paridad de `StudioIntegrationsParityTest` (paquete S) recorre las 22 secciones, incluidas `voiceDoctor`, `voiceVerify`, `voiceCallPolicy`, `elevenlabsSignature`, `elevenlabsEnvelope`, `twilioProbeSanitize` y `twilioSignatureOfficial`, llamando a las clases reales de V y TW.
