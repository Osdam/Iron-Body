# Hito 6 · paquete S (Studio: servicios, modelos, API, permisos, libro, comandos y pruebas): informe del backend

**Fecha:** 31-10-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h6/Iron-Body/backend`, rama `feat/ultron-studio-h6`, sobre `df1241c`. **Sin commit** (lo confirma el lead). **Producción: nada.** Sin `composer`, sin dependencias nuevas, sin tocar motor, migraciones, `routes/console.php`, `StudioCatalog` ni `tests/fixtures/`.

**Aviso para el lead:** el fichero `ULTRON_STUDIO_H6_CONTRACT.md` del worktree **no contiene la Enmienda 1** (termina en la línea 1357). La apliqué tal como la describen el README del fixture del tester (E1.1–E1.5) y los informes DB/V/TW: importes de `facts` como texto con dos decimales, mapas vacíos `[]` en lo hasheado, etiquetas de `StudioVersionStatus::label()`, PCRE con `D` y `[0-9]` sin `/u`, `secretFingerprints()` como única vía a las huellas de voz, D-1/D-2 del esquema y D-10 del saneador.

## 1. Ficheros

**Nuevos (app):**
| Fichero | Líneas | Qué |
|---|---|---|
| `app/Support/Privacy/PhoneFingerprint.php` | 88 | Huella HMAC (subclave `ultron-canary-phone/1` de la `APP_KEY`), E.164 estricto con `D`; neutro |
| `app/Services/UltronStudio/StudioCanaryPhone.php` | 158 | `parse()`, `masked()`, `aliasError()` (puro) y los textos fijos de los 422 |
| `…/StudioIntegrationConfig.php` | ~640 | `facts` por proveedor (orden de §I5.3), `valueFingerprint`, deriva, estado, ruta 46, reconocimiento (55), instantáneas del comando y de la voz |
| `…/StudioMetaCampaigns.php` | 359 | Lector del espejo de Meta (lista blanca §I3.3), `classify`, `possiblyDeleted`, orden, `resolve` |
| `…/StudioCampaignLinks.php` | 504 | Rutas 40, 41, 47 y 48; sustitución en 3 sentencias (H-4 DB); `CampaignLinkDto` |
| `…/StudioCanaryCoherence.php` | 185 | Coherencia §I4.5 (nunca lanza; `unset` del teléfono) |
| `…/StudioCanaryNumbers.php` | 689 | Rutas 42 y 49–54; cuatro ojos por identidad; cerrojos de topes; `CanaryNumberDto`, `actions`, expectativa |
| `…/StudioIntegrations.php` | ~560 | Banderas y topes, ruta 39 (bloques independientes), ruta 45, reglas de clasificación, `lock()` |
| `…/StudioTwilioPanel.php` | 254 | Rutas 43 y 44 (sin columnas personales de `marketing_calls`) |
| `…/StudioTwilioProbe.php` | 234 | Ruta 56: bandera, reintento, configuración, enfriamiento con cerrojo de caché, 5 GET con presupuesto de 30 s |
| `…/StudioIntegrationsExport.php` | ~420 | Formas para `backend-integrations.testing.ts` con los serializadores reales y una `APP_KEY` sintética |
| `app/Models/UltronStudio/{StudioCampaignLink,StudioCanaryNumber,StudioIntegrationSnapshot}.php` | 155/161/91 | Inmutables por modelo; solo UPDATE condicionados; `encrypted` en voz |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudio{Integration,CampaignLink,Canary}Controller.php` | 130/120/~150 | Finos, forma (422) antes que llaves (409) |
| `app/Console/Commands/UltronStudio/Integrations{Doctor,Snapshot}Command.php` | ~100/87 | `integrations-doctor [--json] [--expect]`, `integrations-snapshot [--verify-voice] [--json]` |

**Modificados (app/config/routes):** `UltronStudioException.php` (+15 fábricas de I7.3), `StudioAuditEvent.php` (7 sujetos), `StudioAudit.php` (+11 eventos), `StudioAccess.php` (4 constantes, `integrationCapabilities()`, `isCanaryRegistrant()`), `AuthorizationMap.php` (+18), `PermissionCatalog.php` (etiquetas/ayudas literales y orden), `CrmPermission.php` (exclusiones del Administrador), `AppServiceProvider.php` (solo `limitadoresDelStudio()`: `ultron-studio-probe` 6/h persona, 20/h red), `ExportStudioFixturesCommand.php` (6 ficheros), `config/ultron_studio.php` (bloque `integrations`), `routes/ultron-studio.php` (+18 → 56), `.env.example` (I12, V, Meta Ads y alias de Twilio comentados; `FACTUS_CONSUMER_FINAL_NAME` intacta).

**Pruebas nuevas:** `IntegrationFixtures.php`; `UltronStudioIntegrations{Canary,CampaignLink,Config,Probe,Status,Permissions,NoNetwork,Sentinel,ParityHttp}Test.php`; `tests/Unit/UltronStudio/StudioIntegrationsParityTest.php`. **Modificadas:** `UltronEngineIsolationTest` (lista blanca + 2 migraciones, 56 rutas, clases neutras), `UltronStudioEngineReadOnlyTest` (10 lectores, lista blanca de Meta, OTP solo `snapshot`/`isActive`, columnas de `marketing_calls`, 3 tablas, recorrido de 56 rutas con la sonda contra `Http::fake`), `UltronStudioPublicationSchemaTest` (parche exacto de §10 H-1 del informe DB), `UltronStudioPermissionsTest` y `UltronStudioLabNoEngineEffectsTest` (las 38/21 de antes sin las integraciones, que tienen su matriz), `ExportStudioFixturesCommandTest` (6 ficheros, determinista).

## 2. Rutas, permisos y orden de errores

Las 18 de §I7.1 con su permiso en `AuthorizationMap::OVERRIDES`; la 56 lleva `throttle:ultron-studio-write` + `throttle:ultron-studio-probe`. Administrador por defecto: `campaigns_manage` y `canary_manage`; `canary_authorize` e `integrations_manage` solo Super Admin; el token compartido, ninguna. Orden: puerta/persona (401/403) → forma (422) → llave (403 `forbidden`, primera que falta de `[ultron.view, llave]`) → bandera (409) → reintento → recurso (404) → identidad (403 `four_eyes_required` + `canary_number.four_eyes_denied` deduplicado) → estado y topes (409). 55 no depende de la bandera; 56 sí de la suya. `replayed` (y `reused` en 56) van dentro de `data`.

## 3. Decisiones fuera del contrato (D-n)

- **D-1** `state` de Twilio (ruta 39/43): `ok`, `degraded` o `not_configured` según la clasificación (el contrato no lo definía; PC-1 del fixture no lo afirma).
- **D-2** La sonda se serializa con `Cache::lock` (espera ≤ 40 s; la segunda vuelve a mirar reintento y enfriamiento). Si el cerrojo quedó colgado por un proceso muerto, se sigue sin él (peor caso: 5 GET repetidos sin coste).
- **D-3** El tope global de asociaciones se cuenta bajo cerrojo consultivo `ultron_studio_campaign_links` (READ COMMITTED primero), como los de canarios.
- **D-4** Mapas vacíos: `[]` en lo hasheado (`facts`), `{}` en DTO no hasheados (`calls.byStatus/byDirection`); el CRM acepta los dos (O-4).
- **D-5** El reintento del registro compara la versión esperada **actual**: si después se cambió con la 54, reintentar la petición original da 409 `client_request_reused`.
- **D-6** Los hechos de voz tienen constructor puro (`voiceFactsFrom`) para que el exportador no nombre los secretos de voz (lo exige `VoiceIsolationTest`) y una clave real de la máquina nunca entre en el fichero exportado.
- **D-7** `StudioIntegrations` no nombra clases del motor: los lectores nuevos son 5 (`StudioCampaignLinks`, `StudioCanaryCoherence`, `StudioIntegrationConfig`, `StudioMetaCampaigns`, `StudioTwilioPanel`).
- **D-8** `voiceFromConfigured` es E.164 estricto sin recortar (PC-2). En HTTP, `TrimStrings` recorta antes de validar: un `\n` final llega ya quitado; las funciones puras lo siguen rechazando.

## 4. Verificación (cifras reales)

| Qué | Resultado |
|---|---|
| Línea base HEAD (Studio Feature+Unit) | 1469 pruebas, 3 fallos esperados (lista blanca y 2 recuentos), 61 omitidas |
| Suite 1 completa (Studio+Access+Chaos+Voice+Twilio), 1.ª pasada | 2436 pruebas, 36225 aserciones, **3 fallos** (palabra `verdict` en el doctor, contrato de 21 rutas del laboratorio, el exportador nombraba secretos de voz) → corregidos; las 5 clases afectadas: **287 pruebas, 4909 aserciones, OK** |
| Paridad pura con `integrations-cases.json` | **262 pruebas, 442 aserciones, OK** (phone 88, alias 36, fingerprint 18, expectation 24, meta 22, providerClassification 21, configFacts 11 byte a byte, configDrift 8, studioState 8, voiceCallPolicy 24) |
| Paridad HTTP | **296 pruebas, 1133 aserciones, OK** (canaryTransitions 121, campaignLinkRules 64, probeRules 22, acknowledgeRules 9, coherence 34, possiblyDeleted 17, routeMatrix 29) |
| Mutaciones rápidas (cuatro ojos, `in_sync` por defecto, cifrado sin borrar, Meta fuera de lista, bandera ignorada, regla de canal, `agentId` entero, sin enfriamiento, dígitos al libro) | **9/9 cazadas**, ficheros restaurados (`cmp`) |
| PG 1.ª pasada (`pgunit.sh`, `ultron_studio_h6_s`) | 2083 pruebas, 27619 aserciones; 2 errores + 2 fallos **causados por editar a mitad de la corrida** (las mismas clases de arriba); el resto en verde |
| Puerta de diff del motor desde `e1b1d26` | **vacía**; `composer.json/lock` sin cambios |
| `route:list --path=admin/ultron-studio` | **56** |
| `auth:coverage --unmapped` | vacío, exit 0 |
| `permissions:export` | 105 permisos; **idéntico byte a byte** al `backend-permission-catalog.ts` del CRM |
| `export-fixtures --path=<scratch>` | 6 ficheros; `backend-integrations.testing.ts` con la misma estructura que el provisional del CRM |
| `integrations-doctor` / `--json --expect=…` (llaves por defecto, SQLite migrada) | clasificaciones `not_configured`/`registry`; exit 0 / 1 con la expectativa de producción (aquí no hay Meta ni Twilio) |
| Pint `--test` sobre los 46 PHP tocados (sin `AppServiceProvider.php`) | passed |

**Corridas finales limpias:**
- Suite 1 (SQLite): **2436 pruebas, 36243 aserciones, OK** (61 omitidas, las de solo PG).
- OTP (`tests/Feature/Otp`, `OtpProductionGuardTest`, `TwilioVerifyOtpTest`): **82 pruebas, 208 aserciones, OK**.
- Puerta PG: **2083 pruebas, 27682 aserciones, 1 fallo**. Era de tiempo: la corrida duró 51 min por contención, y el caso «la última sonda fue hace 299 s» pasó de 300 s con el reloj real. Lo corregí congelando el reloj en ese arnés (`freezeSecond()`). `probeRules` vuelve a estar en verde: **22/75 en SQLite y en PG**.
- Marketing: aún corriendo cuando cerré (`final-mkt.txt`); **sin resultado afirmado**.
- Sin hacer todavía: adaptar la paridad y el código a las Enmiendas 1 y 2 confirmadas después (fixture de 704 casos; paridad de las 22 secciones con las clases de V y TW; Q-6 `activeWhatsapp` con el teléfono ilegible; O-2, generalizar el texto de `not_allowed_for_channel`; Q-13).

**Rutas de los ficheros (lanzadas limpias al final):** suite 1 + OTP + Marketing en `…/scratchpad/final-v1.txt`, `final-otp.txt`, `final-mkt.txt`, y la puerta PG en `…/scratchpad/final-pg.txt` (scratchpad de esta sesión: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body-backend-docs-ultron-studio/b88adfed-f0f5-48b9-8084-b1469efa60fe/scratchpad/`). **No afirmo** su resultado: se corta mi sesión antes de que terminen.

## 5. Listo para confirmar

Todo el paquete S como un solo commit, **cuando `final-v1`, `final-pg` y `final-mkt` estén en verde** (esperado: suite 1 sin fallos; Marketing 4977 OK como el 31-10; PG sin los 4 de la pasada mezclada).

## 6. Sin verificar y dudas para el lead

- Las corridas finales del punto 4 (ver arriba). Producción, `iron`/`iron_app` y `ironbody-migrate`: nada.
- **Irrepresentables en la base:** `coherence · «lead_id nulo»` (`lead_id` es NOT NULL con FK: se prueba como «el lead no existe», mismo resultado) y `campaignLinkRules · versión VALIDATING` (no se guarda: se comprueba la regla pura).
- **DT-1 del tester** (`TwilioProbeSanitizer` acepta E.164 de 7 cifras): es de TW, no lo toqué.
- **O-2 del tester:** el texto «WhatsApp exige un móvil» también sale con un colombiano de longitud equivocada y en voz.
- Congelar en la Enmienda 1 escrita: D-1 a D-8 de arriba.
