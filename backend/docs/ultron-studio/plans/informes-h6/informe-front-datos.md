# Hito 6 · paso 1e · informe del frontend: la capa de datos de integraciones del CRM

**Fecha:** 31-10-2026 (Bogotá).
**Árbol:** `_worktrees/ultron-h6/Iron-Body_Front`, rama `feat/ultron-studio-h6`.
- El lead confirmó como WIP la primera mitad en `0a83d74` (tipos, lectores, textos y servicio).
- El resto queda **sin commit** en el árbol (§1).
- `e2e/ultron-studio/**` es del tester (`037a2cd`) y no lo he tocado.

**Contrato:** `ULTRON_STUDIO_H6_CONTRACT.md` §I2–§I8, §I10, §I11 (fila CRM) e §I13, más la **Enmienda 1** que dictó el lead por mensaje (todavía no está escrita en el contrato):
- las etiquetas de versión son las de `StudioVersionStatus::label()` («Canario»);
- `replayed` puede llegar dentro o fuera de `data`;
- los importes de `facts` llegan como texto con dos decimales (`"6.00"`);
- el camelCase de I5.2 lo convierte S.

**Producción:** nada tocado. Sin despliegue. No he tocado `ultron-studio.scss`, `studio-inspector.scss` ni `package-lock.json`. En este paso no hay UI.

## 1. Ficheros

| Fichero (`‹F›` = `src/app/modules/ultron-studio`) | Líneas | Estado | Qué |
|---|---|---|---|
| `‹F›/studio-integrations-api.types.ts` | 1103 | `0a83d74` | Conjuntos cerrados de I2 (listas en orden y `isX()`), DTO de I3–I7, cuerpos, códigos de I7.3, `StudioIntegrationsApiError` |
| `‹F›/studio-integrations-parse.ts` | 1423 | `0a83d74` + 10 líneas sin commit | Lectores estrictos o tolerantes, lectores de la carga de los errores y comprobaciones de entrada (teléfono, alias, motivo) |
| `‹F›/studio-integrations-text.ts` | 640 | `0a83d74` | Textos obligatorios literales, los 4 permisos, vistas de cada conjunto y descriptores (insignias, filas de evidencia, acciones) |
| `‹F›/studio-integrations-api.service.ts` | 415 | `0a83d74` | `UltronStudioIntegrationsApi` (18 rutas), `toStudioIntegrationsApiError`, `studioIntegrationsResult` |
| `‹F›/studio-api.types.ts` / `‹F›/studio-parse.ts` | +3 / +2 | `0a83d74` | `AuditSubjectType` y su lista: 4 → 7 sujetos (§5.3) |
| `‹F›/testing/backend-integrations.testing.ts` | 1425 | **nuevo** | Fixture con el formato exacto de `export-fixtures` (§6) |
| `‹F›/studio-integrations-fixtures.testing.ts` | 42 | **nuevo** | Ayudas de prueba: copias de los ejemplos, `okH6`, uuids y `conLlavesNuevasH6` |
| `‹F›/studio-integrations-parse.spec.ts` | 553 | **nuevo** | 47 pruebas |
| `‹F›/studio-integrations-api.service.spec.ts` | 343 | **nuevo** | 24 pruebas |
| `‹F›/studio-integrations-text.spec.ts` | 234 | **nuevo** | 13 pruebas |
| `‹F›/studio-integrations-compat.spec.ts` | 182 | **nuevo** | 7 pruebas (la obligatoria) |
| `‹F›/studio-integrations-parity.spec.ts` | 232 | **nuevo** | 10 pruebas |
| `src/app/models/permissions.enum.ts` | +30 | sin commit | 4 llaves, con etiqueta y ayuda literales en el JSDoc |
| `src/app/models/backend-permission-catalog.ts` | +10 | sin commit | 4 en la lista y en Super Admin; 2 en Administrador; orden de bytes, como `permissions:export` |
| `src/app/services/access-control.service.ts` | +5 | sin commit | El Administrador del CRM excluye `canary_authorize` e `integrations_manage` (patrón `7da6f08`, aprobado) |
| `src/app/models/permission-contract.spec.ts` | +21 −6 | sin commit | De 8 a 12 llaves `ultron.*`; el espejo del Administrador |

## 2. Formas TS finales (lo que la página puede usar)

```ts
// 39 · GET integrations/status
interface IntegrationsStatusDto { can: IntegrationCapabilities; flags: { writesEnabled: boolean; twilioProbeEnabled: boolean };
  providers: IntegrationProviderStatusDto[]; discarded: number; note: string | null }
interface IntegrationCapabilities { campaignsManage: boolean; canaryManage: boolean; canaryAuthorize: boolean; integrationsManage: boolean }
type IntegrationProviderStatusDto =
  | { id: IntegrationProviderId; available: false }                       // su fuente falló: lectura VÁLIDA
  | { id: 'meta_ads'; available: true; classification; state: MetaConnectionState; reason: MetaConnectionReason | null; evidence: MetaAdsStatusEvidence | null }
  | { id: 'twilio'; available: true; classification; state: string; reason: TwilioStatusReasonCode | null; evidence: TwilioStatusEvidence | null }
  | { id: 'twilio_voice'; available: true; classification; evidence: { enabled; fromConfigured } | null }
  | { id: 'canaries'; available: true; classification; evidence: { active: { whatsapp; voice }; pending; authorized; verdict } | null }
  | { id: 'elevenlabs'; available: true; classification; code: VoiceState; evidence: { enabled; missing: VoiceMissingPiece[] } | null };

// 40 / 41 / 47 / 48 · Meta
interface MetaCampaignsDto { connection: MetaConnectionDto; campaigns: StudioPage<MetaCampaignDto> }
interface MetaCampaignDto { id /* campaign:16hex */; ref: string | null /* ***NNNN */; name: string | null; account: MetaAccountRefDto;
  meta: { effectiveStatus: MetaEffectiveStatus | (string & {}) | null; statusKnownAt; ageMinutes; stale; possiblyDeleted };
  ultron: { link: CampaignLinkDto | null } }                              // solo la ACTIVA de esta campaña
interface CampaignLinkDto { uuid; status: 'active' | 'inactive'; campaign: { id; ref; nameAtLink; connected; currentName };
  target: { kind: 'baseline'; label } | { kind: 'version'; versionUuid; label; status: VersionStatus; statusLabel; integrityOk };
  createdBy: StudioActorDto; createdAt; deactivated: { at; by; reason; replacedBy: string | null } | null }
interface CampaignLinkWriteDto { replayed: boolean; link: CampaignLinkDto; replaced: CampaignLinkDto | null }

// 42 / 49–54 · Números canarios
interface CanaryNumbersDto { coherence: { engineCanaryConfigured; verdict: CanaryCoherenceVerdict; activeWhatsapp };
  limits: { maxActivePerChannel; maxLivePerChannel; countryCodes: string[]; writesEnabled }; numbers: StudioPage<CanaryNumberDto> }
interface CanaryNumberDto { uuid; channel: 'whatsapp' | 'voice'; alias; masked /* «+57 ••• ••• 4821» */; status: CanaryStatus; statusLabel;
  registeredBy; registeredAt; authorizedBy | null; authorizedAt | null; activatedBy | null; activatedAt | null;
  revoked: { at; by; reason } | null; expectedVersion: { uuid; label; status: VersionStatus; statusLabel; stale } | null;
  effectiveVersion: { source: 'publication_chain'; state: 'applied'; part: 'canary' | 'rest' | null; versionUuid: string | null; label: string | null }
                  | { source: 'publication_chain'; state: 'legacy' | 'off' | 'fallback' } | { source: 'none'; reason: EffectiveVersionNoneReason };
  expectation: CanaryExpectation; isEngineCanary: boolean | null;
  actions: Record<'authorize' | 'activate' | 'deactivate' | 'revoke' | 'expectation', { allowed: boolean; reason: CanaryActionReason | null }>;
  statusChangedAt }
interface CanaryWriteDto { replayed: boolean; number: CanaryNumberDto }

// 43 / 44 / 56 · Twilio
interface TwilioPanelDto { classification; state: string; reason: string | null; otp: {...}; cost: { date; estimatedSpentUsd; dailySoftLimitUsd; dailyHardLimitUsd } | null;
  credentials: { accountSid: { present; source; ref }; authToken: { present; source; fp }; aliasesConsistent: { sid: boolean | null; token: boolean | null };
                 verifyServiceSid: { present; ref }; apiBase: { official; host: string | null }; verifyBase: {...} };
  webhooks: TwilioWebhookDto[]; voice: { enabled; fromConfigured; state }; calls: { total; withProviderSid; byStatus; byDirection; lastCreatedAt };
  probe: { enabled; credential: 'otp' | 'restricted_key'; cooldownSeconds; last: TwilioProbeSnapshotDto | null } }
interface TwilioProbeSnapshotDto { uuid; at; outcome: TwilioProbeOutcome; facts: TwilioProbeFactsDto | null /* suave */; by: StudioActorDto | null }
interface TwilioProbeResultDto { replayed: boolean; reused: boolean; snapshot: TwilioProbeSnapshotDto }

// 46 / 55 · Configuración autorizada
interface IntegrationConfigDto { providers: IntegrationConfigProviderDto[]; discarded: number }
interface IntegrationConfigProviderDto { provider: 'meta_ads' | 'twilio' | 'voice'; state: IntegrationConfigState; effective: IntegrationFacts;
  effectiveFingerprint; authorized: { uuid; fingerprint; at; by; note } | null; observed: { uuid; fingerprint; at } | null;
  drift: { key; authorized: IntegrationFactValue; effective: IntegrationFactValue }[] }

// 45 · Voz
interface VoicePanelDto { elevenlabs: { state: VoiceState; enabled; missing; region: string | null; lastVerification: { at; outcome: VoiceVerificationOutcome; agentAudioUlaw8000: boolean | null } | null };
  twilioVoice: { enabled; fromConfigured; dailyCallCap }; policy: { allowed; reasons: VoicePolicyReason[] }; levels: VoiceLevelDto[] }

// Errores
class StudioIntegrationsApiError { kind: 'http' | 'network' | 'unreadable' | 'local'; status; code: StudioIntegrationsErrorCode | StudioIntegrationsClientErrorCode | null;
  message; payload; requiredPermission; retryAfter; fieldErrors; fieldMessages }
// del servidor: los 15 nuevos de I7.3 + four_eyes_required, invalid_transition, client_request_reused, integrity_mismatch,
// version_not_found, forbidden, person_required, payload_too_large, too_many_requests, admin_token_*
// del navegador: unreadable_response, not_ready, not_allowed, writes_disabled, busy, cancelled, invalid_input, integrations_unavailable
```

Lectores de la carga de los errores (en `studio-integrations-parse.ts`):
- `integrationErrorCurrentLink`: `campaign_already_linked` y `campaign_link_moved`, con `{current: null}` distinto de «ilegible».
- `integrationErrorLimit`: `{max, channel}`.
- `integrationErrorTransition` e `integrationTransitionAlreadyDone(err, destino)`: el «ya estaba hecho» de I4.4.
- `integrationErrorFourEyesAction`, `integrationErrorMetaReason`, `integrationErrorTwilioReason` e `integrationErrorConfigChanged`.

Comprobaciones de entrada, copias puras de I4.3 (el servidor decide):
- `checkCanaryPhone(raw, canal, países)` → `{ok, countryCode, last4, masked}` o `{ok:false, error}`;
- `checkCanaryAlias` y `checkIntegrationReason`;
- `maskedCanaryPhone`, `isLowerUuid`, `isOpaqueCampaignId`, `isOpaqueAccountId` e `isConfigFingerprint`.

## 3. Servicio: las 18 rutas

`UltronStudioIntegrationsApi`, con base `${adminApiBaseUrl}/ultron-studio/integrations`:
- Cada cuerpo se construye campo a campo: lo que no es del contrato no viaja, aunque el objeto lo traiga.
- Cada respuesta pasa por su lector. Una respuesta de escritura que no es la de ESA petición sale como `unreadable` (tabla).

| # | Llamada | Comprobación de la respuesta |
|---|---|---|
| 39–46 | `status()`, `metaCampaigns(q)`, `campaignLinks(q)`, `canaries(q)`, `twilio()`, `twilioCalls(q)`, `voice()`, `config()` | La de su lector. El paginador va en `page`/`per_page`, con 1 y 20 por defecto y como mucho 50. `account` y `campaign`, si no son ids opacos, no se piden: `invalid_input` por campo |
| 47 | `createCampaignLink({clientRequestId, campaign, target, replaces?})` | Misma campaña y mismo destino. Si no es un reintento, la sustituida es exactamente `replaces` (o ninguna). `versionUuid` solo viaja con `kind: version`; `replaces`, solo si se da |
| 48 | `deactivateCampaignLink(uuid, {reason})` | Es esa asociación y está `inactive` |
| 49 | `registerCanary({clientRequestId, channel, phone, alias, consentAttested: true, expectedVersionUuid?})` | Mismo canal. Si no es un reintento, `pending` y con la versión esperada pedida. **El teléfono solo viaja aquí** |
| 50–53 | `authorizeCanary`, `activateCanary`, `deactivateCanary`, `revokeCanary` | Es ese número y está en el estado de destino (`CANARY_ACTION_TARGET`) |
| 54 | `setCanaryExpectation(uuid, {versionUuid})` | La clave viaja siempre, también `null`. La respuesta trae esa versión esperada |
| 55 | `acknowledgeConfig({clientRequestId, provider, fingerprint, note})` | Mismo proveedor y misma huella. `note: null` viaja |
| 56 | `probeTwilio({clientRequestId})` | `replayed` y `reused`, con `false` si faltan |

Un uuid de ruta que no sea un uuid en minúsculas **no se pide** (`invalid_input`, sin red). Ver D-F3.

## 4. Qué es estricto, qué es tolerante y por qué

- **Estricto.** La estructura obligatoria, los conjuntos de I2 (`uno(...)`) y los formatos de privacidad. También las invariantes de las que depende lo que se **ofrece** o se **afirma**:
  - **Estado (I7.2):**
    - cada bloque solo con las clasificaciones que puede afirmar en H6;
    - ElevenLabs: la clasificación sale de su `code`;
    - Meta: `connected_read` sin motivo, `degraded` con `stale|failed|sync_disabled` y `not_configured` con un problema de configuración;
    - Twilio: motivo ⇔ no es `connected_otp_only`.
  - **Meta (I3):**
    - `not_configured` nunca trae cuentas ni campañas (no hay datos de demostración);
    - `ultron.link` es la asociación **activa** de **esa** campaña;
    - `inactive` ⇔ `deactivated` (`uscl_deactivation_chk`);
    - una campaña fuera del espejo no tiene `currentName`;
    - la sustituida está inactiva y nombra a la nueva.
  - **Canarios (I4):**
    - `in_sync` ⇒ exactamente un WhatsApp activo; `registry_has_extra_active` ⇒ dos o más; `no_engine_canary` ⇔ sin canario configurado;
    - acción permitida ⇔ sin motivo, y nunca fuera de su estado;
    - `pending` sin autorizar ni activar; `authorized`/`active` con quién autorizó; `active` con quién activó; `revoked` ⇔ `revoked{}`;
    - `isEngineCanary` es `null` en voz y, con la coherencia de la misma respuesta, `null` ⇔ `unreadable`/`no_engine_canary`;
    - `effectiveVersion` sale del canal y de `isEngineCanary` (I4.6);
    - `expectation`: `match` solo si rige la esperada, `none` sin esperada, `mismatch` con la cadena rigiendo otra cosa y `not_applicable` solo con `source: none`;
    - una esperada fuera de `APPROVED|CANARY|PUBLISHED` ⇒ `stale`;
    - topes de 1 a 10 y al menos un país.
  - **Twilio:**
    - credenciales solo como presencia, origen, `ref` (2 + «…» + 4) o `fp` (8 hex);
    - presente ⇔ con marca;
    - un host no oficial, solo como nombre de host.
  - **Voz:**
    - `NOT_CONFIGURED` ⇔ faltan piezas; `DISABLED` con la bandera apagada; `UNVERIFIED` y `VERIFIED` con ella encendida;
    - `VERIFIED` ⇒ verificación `credentials_ok`;
    - la política permite ⇔ no tiene motivos, en orden y sin repetir;
    - `integration_test` nunca cuenta el canario ni el cupo.
  - **Configuración:** el `state` sale de las huellas (I5.3); con `authorized` no hay deriva.
- **Listas.** Un elemento ilegible se descarta y se cuenta: bloques del estado (también los repetidos o de un id desconocido), campañas, asociaciones, números, llamadas y proveedores de la configuración (también los repetidos).
- **Suave** (`null` si no se puede creer; nunca un valor inventado y nunca tumba el panel): la evidencia de cada bloque del estado, los `facts` de la sonda, el `cost` y la descripción del webhook. Un nivel de voz desconocido se ignora.
- **Privacidad (P5, I13):**
  - En `masked`, `ref`, `fp` y `last4` **el motivo del fallo no repite el valor** (podría acabar en un log de soporte).
  - Las claves que no son del contrato nunca se copian, porque cada campo se lee por su nombre: los specs lo comprueban con `phone`, `phoneHash`, `to_phone`, `transcript`…
  - Guarda de los `facts` de la configuración:
    - una clave de credencial (`token`, `apiKey`, `authToken`, `accountSid`, `from`, …) nunca llega como texto, número o lista; sí como objeto, booleano (`aliasesConsistent.token`) o `null`;
    - dentro de una credencial solo `present|source|ref|fp|last4`;
    - ningún texto de más de 120 caracteres ni con una serie de 7 dígitos o más.
  - Si una regla no se cumple, ese proveedor no se enseña (y no se puede reconocer a ciegas).

## 5. Compatibilidad (`studio-integrations-compat.spec.ts`, obligatoria)

### 5.1 Backend del Hito 5

- Las 18 rutas responden 404 `{message:'Not Found'}`. Cada una da `{kind:'http', status:404, code:'integrations_unavailable', message:'El servidor todavía no tiene integraciones (Hito 6)'}`.
- El Studio de hoy (`StudioSession.init`) llega a `ready` y **no pide ninguna** ruta de `integrations/*` (`expectNone`).

### 5.2 Backend posterior

Con dos llaves desconocidas en cada nivel de cada ejemplo, lo leído es **idéntico** y no se cuela nada. En la configuración, lo que se añade dentro de `effective` sí forma parte de los `facts`: es lo que se reconoce.

### 5.3 El libro: hallazgo en el código del H5, ya corregido aquí

- `readAuditPage` validaba `subjectType` contra los 4 sujetos de antes. Con el backend del H6, los eventos `campaign_link`, `canary_number` e `integration` se habrían **descartado como ilegibles** («N eventos llegaron ilegibles y no se enseñan»).
- Ahora el tipo y la lista tienen los 7 sujetos de I8, y `auditEventLines` los pinta en genérico, clave a clave. Un sujeto que todavía no existe se sigue descartando y contando.
- **Consecuencia para `ULTRON_STUDIO_ROLLBACK.md` (dueño: DOC/lead).** La reversión de nivel 3 dice que, con el CRM anterior, «el libro pinta los sujetos nuevos en genérico». Con el CRM del H5 **no es así**: los descarta y lo dice como «ilegibles». No se pierde nada (el libro sigue intacto), pero el texto del runbook es inexacto.

## 6. El fixture `testing/backend-integrations.testing.ts`

- **Formato:** el exacto de `ExportStudioFixturesCommand`:
  - cabecera `//` (la que tendrá el exportador);
  - `export const BACKEND_INTEGRATIONS = <json> as const;\n`;
  - JSON de `json_encode(PRETTY_PRINT | UNESCAPED_UNICODE | UNESCAPED_SLASHES)` con la sangría pasada a 2 espacios.
- **Cómo lo produje:** con un script PHP de usar y tirar que imita al exportador (`…/scratchpad/h6/gen-fixture.php`, fuera del repo).
  - Mismas banderas.
  - `stdClass` para los mapas vacíos (`{}`).
  - Huellas calculadas con el **`StudioContentHasher::canonicalJson()` real** del backend.
  - Sin `JSON_PRESERVE_ZERO_FRACTION`, así que `6.0` sale `6`, como en JS.
  - Los datos los escribí a mano desde el contrato y la Enmienda 1.
- **Qué tiene que hacer S:** exportar este fichero **byte a byte** con sus serializadores reales, con las mismas claves, el mismo orden y los mismos datos sintéticos.
- **Claves de primer nivel, en orden:**
  - `version`, `catalogVersion`;
  - `sets` (los 25 conjuntos de I2, nombrados en camelCase);
  - `providers`, `providerClassifications`, `canaryActions`, `canaryStatusLabels`, `probeSteps`, `probeStepOutcomes`, `voiceLevels`;
  - `auditSubjects` (7), `auditEvents` (11);
  - `errors` (22 códigos con su HTTP: los 15 nuevos en el orden de I7.3 y después los reutilizados);
  - `permissions` (`key`, `label`, `help` y `administrator`, literales);
  - `limits` (`canaryMaxActive: [3, 1, 10]` y `campaignLinksMaxActive: [50, 1, 200]`, como `[defecto, mín, máx]`);
  - `messages` (los 4 textos fijos de los 422);
  - `examples`.
- **Ejemplos:** `status`, `statusDegraded`, `metaCampaigns`, `metaCampaignsNotConfigured`, `links`, `linkWrite`, `linkDeactivated`, `canaries`, `canariesUnreadable`, `canaryWrite`, `twilio`, `twilioCalls`, `probe`, `voice`, `config` y `acknowledgement`.
  - Fechas con `toIso8601String()` (`+00:00`) y uuids en minúsculas.
  - Las acciones de `canaries` son las que ve Root (Super Admin).
  - En `config`, Meta está `never_authorized`, Twilio en `drift` (por `verifyServiceSid`) y voz `authorized`; los importes van como `"6.00"` y `"12.00"`.
- **La paridad** (`studio-integrations-parity.spec.ts`) comprueba:
  - los conjuntos y listas frente a las constantes del CRM (y que no aparezca ninguno nuevo sin decidir);
  - los códigos (todos deciden en el CRM; los únicos 404 son los cuatro con código);
  - los permisos frente al enum, el catálogo y los roles;
  - los límites, los textos, las etiquetas de la Enmienda 1 (`StudioVersionStatus::label()`, tomadas de `BACKEND_PUBLICATION.lifecycle`);
  - que cada huella es `sha256(JSON canónico de los facts)` y que en los `facts` no hay decimales.

## 7. Verificación (salida real)

**Suite.** `node_modules/.bin/ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/models/**/*.spec.ts'`:
- **Test Files 60 passed (60) · Tests 1005 passed (1005)**, en 52 s.
- Son los 59 specs del módulo y `permission-contract.spec.ts`; entre los del módulo, la huella de `ultron-studio.scss` (`studio-h5-sheets.spec.ts`).
- Mis specs nuevos: 5 ficheros y **101 pruebas** (parse 47, servicio 24, textos 13, compatibilidad 7, paridad 10).
- `permission-contract.spec.ts`: 22 pruebas.

**Mutaciones** (cada fichero se restauró después y `cmp` dio idéntico):

| Mutación | Resultado |
|---|---|
| M1: sin la guarda de `masked` | 1 prueba cae («un teléfono en claro en `masked` no se enseña») |
| M2: sin la invariante de `in_sync` | 1 prueba cae |
| M3: sin reconocer el 404 del H5 | 3 pruebas caen (servicio y compatibilidad) |

**Compilación y literales:**
- `node_modules/.bin/tsc -p tsconfig.app.json --noEmit`: salida 0.
- `tsc -p tsconfig.spec.json --noEmit`: 0 errores.
- `npm run check:literals`: «338 ficheros revisados · literales correctos».

**Sin cambios:** `package-lock.json`, `ultron-studio.scss` y `studio-inspector.scss`.

**Prettier:** sin aplicar. Los ficheros del módulo ya existentes (`studio-parse.ts`, `studio-lab-parse.ts`, los servicios) tampoco lo pasan: el módulo usa líneas largas y he seguido su estilo.

**No ejecutado:** `ng build` (el lead lo reserva) ni la suite completa del CRM fuera de `ultron-studio` y `models`. El enum que cambié lo consume también `studio-lab-unknown-permission.spec.ts`, que está en la suite y pasa.

## 8. Decisiones D-F (fuera de lo que fija el contrato)

| # | Decisión | Por qué |
|---|---|---|
| D-F1 | `replayed` se lee de `data.replayed`. Si no viene, del nivel de `data`, como en el H5 | Enmienda 1 |
| D-F2 | Un 404 SIN `code` en `integrations/*` se convierte en `kind:'http', code:'integrations_unavailable'` con el texto de I10 | Es el único 404 sin código posible: los del hito llevan siempre el suyo (lo comprueba la paridad) |
| D-F3 | El servicio no pide ninguna ruta con un uuid que no esté en minúsculas: responde `invalid_input` sin red | `whereUuid` haría un 404 sin código y se leería como «backend sin integraciones» |
| D-F4 | `AuditSubjectType` pasa de 4 a 7 sujetos en `studio-api.types.ts`/`studio-parse.ts` | §5.3. Las etiquetas del filtro (`INTEGRATION_AUDIT_SUBJECT_LABELS`) las usará la integración en `studio-audit-panel.ts` |
| D-F5 | `effectiveVersion` `applied` con las dos partes en Legacy se acepta como `part/versionUuid/label` a `null`, las tres o ninguna. `state:'legacy'` también se acepta | I4.6 no fija la forma (duda Q-2) |
| D-F6 | Expectativa sin versión esperada y sin versión que rija → el fixture dice `none` (la primera regla de I4.6). El lector acepta `none` y `not_applicable` | Duda Q-3 |
| D-F7 | La evidencia, los `facts` de la sonda, el coste y el webhook se leen suaves | Solo informan; la clasificación y el resultado mandan |
| D-F8 | Un paso fallido de la sonda deja su bloque en `null` en los `facts` (`alerts24h: null` en el ejemplo); el lector acepta `null` o la clave ausente | I5.4 no lo fija |
| D-F9 | Comprobaciones de teléfono, alias y motivo en el cliente (recortando antes, como TrimStrings), con los textos fijos del servidor | Avisar junto al campo. El 422 manda. La paridad con `integrations-cases.json#phone/alias` queda pendiente: el tester aún no lo ha exportado |
| D-F10 | Toqué `access-control.service.ts` y `permission-contract.spec.ts` además del enum y el catálogo | Patrón `7da6f08`, aprobado por el lead |
| D-F11 | El orden de los números en el ejemplo `canaries` es por `id` descendente | I4.9 no lo fija; el CRM no depende del orden |

## 9. Dudas para S y el lead

- **Q-1 (S).** `cost` = `OtpCostGuard::snapshot()` devuelve snake_case y `send_enabled`. El CRM lee el DTO de I5.2 en camelCase y sin `sendEnabled`. El lead ya dijo que lo convierte S.
- **Q-2 (S).** I4.6, `applied` con canario y resto en `null`: ¿`{state:'legacy'}` o `{state:'applied', part:null, versionUuid:null, label:null}`? El CRM acepta las dos (D-F5).
- **Q-3 (S/lead).** Sin versión esperada y con `source: none`: ¿`none` o `not_applicable`? El fixture fija `none` (D-F6).
- **Q-4 (S).** `lastVerification.outcome` de I6.6: el CRM lo lee con el conjunto «verificación de voz» (`credentials_ok`…), no con el de la sonda (`ok`…), y exige `credentials_ok` para `VERIFIED`.
- **Q-5 (S).** Mapas vacíos (`calls.byStatus`, `byDirection`, `facts.calls7d.byStatus`): el fixture fija `{}` (con `stdClass`). El CRM acepta además `[]`.
- **Q-6 (S).** El `state` de Twilio (I5.2 e I7.2) no está en I2. El CRM lo lee como texto corto y los motivos de Twilio como códigos tolerantes. Si S fija un conjunto, se puede volver estricto.
- **Q-7 (lead/DOC).** El runbook de reversión, nivel 3 (§5.3).
- **Q-8 (S).** Los ejemplos del fixture usan datos sintéticos concretos (uuids, ids opacos, nombres, instantes). Para la comparación byte a byte, el exportador tiene que reproducirlos tal cual, como hizo `StudioPublicationExport` en el H5. Si S prefiere otros, se regenera este fichero y la paridad tiene que seguir en verde.

## 10. Para la integración (paso 3 del FE)

1. **Sesión:**
   - `StudioIntegrationsSession` sobre `UltronStudioIntegrationsApi` y `studioIntegrationsResult`;
   - `clientRequestId` con `newStudioRequestId()`, ligado a la firma del cuerpo, como en el H5;
   - el teléfono **nunca** se guarda (ni en `localStorage` ni en señales tras el éxito);
   - `integrations_unavailable` se convierte en un estado de página con `TEXTO_SIN_INTEGRACIONES`.
2. **Botones:** solo con `can.*` y `flags.*`. Sin ellos, el motivo en texto: `missingCapabilityText`, `TEXTO_ESCRITURAS_APAGADAS` o `TEXTO_SONDA_APAGADA`. En canarios, por `actions` (`canaryActionRows`).
3. **Textos obligatorios:** las constantes `TEXTO_*` de `studio-integrations-text.ts`, nunca copiadas en plantillas.
4. **Libro:** `INTEGRATION_AUDIT_SUBJECT_LABELS` en el filtro de `studio-audit-panel.ts` (I10).
5. **Formularios:** `checkCanaryPhone`, `checkCanaryAlias` y `checkIntegrationReason` junto al campo; después, el 422 del servidor (`fieldErrors`).
6. **Regenerar los fixtures** cuando S exporte. Si no cuadran byte a byte, se corrige un lado o se enmienda el contrato.
