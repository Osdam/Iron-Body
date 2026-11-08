# Hito 8 · S8-a (paso 1) · informe del Studio: la plantilla y el compilador puro

**Fecha:** 03-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `57bab8a`. **Sin commit.** Producción y red: nada.

## 1. Qué entrego

| Fichero | Qué es |
|---|---|
| `app/Services/UltronStudio/StudioVoiceTemplate.php` | Plantilla en código (P-6): aviso P-9 provisional literal, «línea no disponible», tono/objetivo/«si no entiende» por opción CERRADA del catálogo, huecos `presentation` y `closing`, catálogo cerrado de herramientas (D8-10) con su descripción, P-3 (`PRICES_FROM_CATALOG_TOOL`), variables dinámicas con defectos fallo-cerrado, comprobación de textos y huella fijada por versión (`ultron-voice-template/1`). |
| `app/Services/UltronStudio/StudioVoiceCompiler.php` | `compile(version, profile, maxCallSeconds, purpose): {spec, specHash}` puro, sin teléfonos ni secretos; `profileHash()`; `assertDestinationsMatch(spec, ref→E.164, huella)` (R-07 al desplegar); 422 `voice_compile_refused` con `{reason, field}` (38 motivos cerrados, nunca repite el texto). |
| `tests/Unit/UltronStudio/StudioVoiceTemplateTest.php` · `StudioVoiceCompilerTest.php` · `StudioVoiceCompilerParityTest.php` | Unitarias, paridad con `tests/fixtures/voice/compiler-cases.json` (TEST8-a). |
| `tests/Feature/UltronStudio/UltronStudioVoiceCompilerTest.php` | Versiones reales aprobadas por la API (dos personas) y reglas S-NNN por la API; 0 consultas a la base al compilar; el 422 tal como lo pinta Laravel. |

La salida la lee tal cual `VoiceAgentSpec::fromCompiled()` de B8-a (misma `specHash`, mismos bytes canónicos: prueba de paridad).

## 2. Verificado (comandos ejecutados)

- `phpunit` de mis 4 ficheros: **354 pruebas, 2 326 aserciones, OK**.
- Guardas del Studio que rastrean mis ficheros: `UltronStudioEngineReadOnlyTest` (3 estáticas) OK; `UltronEngineIsolationTest` OK (8); `StudioPlainTextInvisiblesTest`, `StudioLabIsolationTest`, `StudioCatalogTest`, `StudioContentHasherTest`, `StudioLabChecksTest`, `StudioLabPersonalDataTest`, `InvisibleCharactersTest`: **202 pruebas OK**.
- Paridad con el fixture: **107 casos** (73 de compilación, 14 relaciones de huella, 9 vectores canónicos, 11 de recorte) más la de forma: OK. Cazó un fallo mío (un emoji con ZWJ salía como «teléfono»): corregido con su regresión.
- Mutaciones en copia aislada (scratchpad, `app/` de la copia antepuesto al autoload): **78 mutantes, 77 cazados, 0 supervivientes**, 1 ya no aplica (quité el código redundante que mutaba).
- Pint `--test` en los 6 ficheros: OK.

## 3. Decisiones que tiene que confirmar el lead

1. **`call_direction`** (`entrante`/`saliente`, defecto `entrante`) es una variable dinámica nueva: D8-11 y D8-12 no la listan. B8-b tiene que mandarla en la iniciación y en la saliente. B8-a ya la recoge en el DTO.
2. **Primer mensaje = solo el aviso**; el saludo de cada sentido va en el prompt, en su rama. Alternativa (el primer DTO de B8-a): aviso + `{{greeting}}` puesto por Laravel, con defecto «no disponible». Así se oyen el aviso y el saludo en el mismo turno, pero cambia el DTO y B8-b.
3. Versión aprobada = APPROVED, CANARY o PUBLISHED (como `StudioCampaignLinks::LINKABLE`). El laboratorio admite además VALIDATED y REVIEW_REQUIRED, y el perfil `submitted` (D8-23).
4. Segundo cerrojo: se niega si la versión tiene `strictFacts≠true`, `discountsAllowed≠false`, `paymentLinksEnabled≠false` o `handoffOnlyCorroborated≠true`.
5. `no_volver_a_llamar` es obligatoria (Ley 2300) y `transfer_to_number` exige `solicitar_humano` (D8-14). Buzón: solo `hang_up`; `leave_message` [P-10] necesita un texto aprobado.
6. Textos del perfil con lista de caracteres admitidos (sin llaves, sin `«»`, sin emojis), además de PlainText estricto. Las llaves permitirían inyectar `{{system__caller_id}}`; las comillas angulares, romper la cita del prompt.
7. **Aviso P-9:** el texto provisional no dice cómo pedir que no llamen más, que D8-17 sí exige. Es solo texto en la plantilla y lo aprueba el propietario. *(Resuelto por E1-3: ver §6.)*

## 4. Conflictos con el fixture de TEST8-a (fijados por nombre en la prueba de paridad)

- **Emoji válido:** el fixture lo da por bueno. Mi compilador lo niega (`text_characters`) y el DTO de B8 tampoco admite ZWJ.
- **`leave_message`:** ver la decisión 5.
- **«solo dos herramientas»:** el fixture transfiere sin `solicitar_humano`; mi compilador lo niega.
- **«una herramienta menos»:** el caso quita `no_volver_a_llamar`, que para mí es obligatoria.
- **Orden entre campos:** el fixture da el primer motivo de su orden; el mío, el primer campo.
- **uuids en `specHash`:** DF-C7 los excluye; el DTO de B8 los incluye en `source`. Recomiendo mantenerlos (procedencia por artefacto).
- **No aplican:** `handoffAliases` (ningún parámetro del catálogo nombra destinos: R-07 se aplica al desplegar) y la versión del compilador como entrada (es una constante).

## 5. Para S8-b, B8 y TEST8

- **S8-b:** el perfil guarda `{alias, phoneHash}` (huella `voice-phone`) y el número cifrado aparte. Antes del mapper hay que llamar a `assertDestinationsMatch`.
- **B8-b:** la guarda posterior tiene `forbiddenPhrases` y `prices` en la especificación.
- **TEST8:** cerrar los conflictos de §4.

## 6. Enmienda 1 (03-11-2026): el aviso de E1-3

Las decisiones de §3 y §4 quedan aceptadas en E1-5 a E1-9. Esta ronda solo aplica **E1-3**. Sin commit.

**Qué cambia:**
- `StudioVoiceTemplate::AI_NOTICE` pasa al texto de E1-3, que sigue siendo provisional hasta que lo apruebe el propietario antes de la fase 3. Dice quién atiende, la finalidad y cómo pedir que no llamen más.
- La prueba lo lee de la fila E1-3 del contrato y fija esas tres partes. También comprueba que el texto de P-9 ya no es el aviso.
- La plantilla sigue en `ultron-voice-template/1`. Su huella se vuelve a fijar: `276cb258…` → `6914e2e1…`.
  - Por qué no subo de versión: ningún agente se ha compilado con la v1 fuera de las pruebas, y el fixture fija `ultron-voice-compiler/1`.
  - Si el lead prefiere versionado estricto: subir a `/2` la plantilla y el compilador, y TEST8-a actualiza el fixture.

**Paridad:**
- `compiler-cases.json` aún trae el aviso de P-9 (TEST8-a no ha reexportado según E1-9).
- La prueba traduce el aviso aprobado del fixture al de la plantilla, en el perfil y en las invariantes del primer mensaje. Solo lo hace si es exactamente el texto de P-9 que se lee del contrato, y lo fija.
- Cualquier otro texto llega tal cual: vacío, ausente o con una palabra cambiada siguen dando `notice_missing`.
- Cuando el fixture traiga E1-3, la traducción es la identidad.

**Verificado:**
- Mis cuatro ficheros: **354 pruebas y 2 333 aserciones, OK**. Por fichero: plantilla 91, compilador 151, paridad 108 y Feature 4.
- Guardas del Studio: 3 + 8 + 202 OK.
- Mutaciones en copia aislada: **79, cazadas 78, sobreviven 0**. La que no aplica es el código redundante que se quitó. Dos de las cazadas son nuevas y estropean el aviso de E1-3.
- Pint: OK.

**Pendiente de otros paquetes:**
- **TEST8-a (E1-9).** Al reexportar, los 6 casos de `StudioVoiceCompilerParityTest::CONFLICTS` fallarán a propósito. Hay que vaciar o reclasificar la lista.
- **TEST8-a y OPS8.** El texto viejo del aviso sigue en:
  - `tests/fixtures/voice/elevenlabs-webhook-bodies.json`;
  - `tests/fixtures/voice/elevenlabs-webhook-vectors.json`;
  - `tests/fixtures/voice/tools/build/sec_eleven_bodies.py`;
  - `docs/ultron-studio/ops/banco-h8/sim-elevenlabs.cjs`.

  Importará cuando B8-b compruebe el aviso en el primer turno (D8-10, capa 4).

---

# §S8-b (paso 2) · el gobierno de la voz: perfiles, publicación, cadena, evidencia y deriva

**Fecha:** 07-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice` (HEAD `80396fc5` + el trabajo en curso de B8-b, LAB8 y FE8 en ficheros disjuntos). **Sin commit.** Producción y red: nada (cero peticiones reales; `Http::preventStrayRequests()` en todas mis pruebas).

## 7. Qué entrego (por fichero)

**Nuevos (servicios, modelos y controlador):**

| Fichero | Qué es |
|---|---|
| `app/Models/UltronStudio/StudioVoiceProfile.php` | El perfil de voz, una fila por revisión. Solo cambia con `transition()` (UPDATE condicionado por estado y lista de columnas mutables de DB8); `save()`/`delete()` lanzan. El número de los destinos va en `transfer_destinations_encrypted` (cast `encrypted:array`, `$hidden`). |
| `app/Models/UltronStudio/StudioVoiceAgent.php` | El agente compilado y desplegado. `transition()` del despliegue, `release()`/`take()` de las partes (`serves_canary`/`serves_rest`: primero se suelta, después se toma). |
| `app/Models/UltronStudio/StudioVoicePublication.php` | La entrada de la cadena de voz: solo inserción (el modelo lanza al actualizar o borrar). |
| `app/Services/UltronStudio/StudioVoiceProfiles.php` | Crear (idempotente por `clientRequestId`), enviar (solo quien lo creó), aprobar y rechazar (otra identidad; rechazar con nota), leer. El contenido lo valida **la función del compilador** (`StudioVoiceCompiler::profileContent()`, mismo motivo y campo que al compilar). El servidor pone formato, idioma, el aviso vigente y la privacidad apagada; `record_calls`/`record_voice` siempre `false` (E3-3). Huella `voice-phone` de los destinos (`phoneHash()`) y descifrado en memoria (`transferNumbers()`). |
| `app/Services/UltronStudio/StudioVoicePublications.php` | La cadena: el mensaje de E1-4 (10 claves, JSON canónico, sin prefijo, E2-7), su huella, la firma por `Closure` (`VoicePublishedConfig::signatureFor(...)`, la única implementación, D8-8), la verificación en el orden de DF-V6 (nunca lanza), el cruce de lo firmado con la fila y su agente (`row_mismatch`), lo vigente de cada parte (E3-2), el estado y el historial verificados. |
| `app/Services/UltronStudio/StudioVoicePublishing.php` | Publicar (compilar → `createAgent` → `describeAgent` → `assignNumber` → entrada firmada, D8-7), promover (evidencia + sin deriva), revertir (al agente anterior de la parte) y apagar (una entrada por parte con agente), más la previsualización sin red. Puertas, idempotencia, 409 `publication_moved` y compensación del número. |
| `app/Services/UltronStudio/StudioVoiceEvidence.php` | La evidencia del canario de voz (evaluación pura + medida de B8) y el freno (estado, lista y soltar). **El único fichero del Studio que nombra servicios de la telefonía** (`VoiceEvidence`, `VoiceBrake`, E2-6). Falla cerrado si la telefonía no está. |
| `app/Services/UltronStudio/StudioVoiceDrift.php` | La deriva: la proyección observada de cada agente vivo frente a la compilada (`verdict()`, puro) y el número → agente de E2-3 (`numberVerdict()`, puro, con la observación de B8). Vigila, no actúa. |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php` | Las 15 rutas de voz, validación en el borde (`PlainText` estricto, sin datos de contacto) y la lista de rutas con su permiso (`ROUTES`, `LAB_ROUTES`, `STUDIO_ROUTE_COUNT = 77`, E2-6). |

**Cambiados:**

| Fichero | Cambio |
|---|---|
| `app/Services/UltronStudio/StudioVoiceCompiler.php` (S8-a, +16) | Refactor puro: el contenido del perfil sale a `profileContent()` público; `checkProfile()` lo llama tras el estado, los cuatro ojos y la huella (el mismo orden). Las 361 pruebas de S8-a siguen en verde. |
| `app/Services/UltronStudio/StudioIntegrations.php` (+86) | `twilio_voice` gana `connected_voice` (número + bandera + la subcuenta completa, `TwilioVoiceCarrier::configured()`); `classifyTwilioVoice()` con un tercer argumento opcional (sin él, la regla del H6). La ruta 45 suma `telephonyState`, `phase`, `brake`, `publishing` y `publication` sin tocar `elevenlabs`, `twilioVoice`, `policy` ni `levels`. |
| `app/Services/UltronStudio/StudioIntegrationConfig.php` (+96) | Los hechos de voz del H8: la subcuenta (refs de la cuenta y de la clave), las referencias del gestor de secretos del proveedor y las huellas nuevas (iniciación **de `ElevenLabsVoiceProvider::secretFingerprints()`, sin duplicarla**, la clave de Laravel de `TwilioVoiceCarrier` y el token de firma de `TwilioRequestSignature`). **Solo entran si hay alguna pieza del H8 configurada** (ver §10, decisión 9). |
| `config/ultron_studio.php` (+34, solo el bloque `voice`) | `publishing_enabled` (`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`, apagada), `lab_gate` (`ULTRON_STUDIO_VOICE_LAB_GATE`, `required`), `canary_min_hours` (24, 1–168) y `canary_min_calls` (3, 1–100). |
| `routes/ultron-studio.php` (+49) | Las 15 de voz y las 6 de LAB8 (idénticas a su informe §2.1), con el tope de 64 KB, su cupo y sus limitadores. |

**Pruebas nuevas (11 ficheros):** `tests/Feature/UltronStudio/{FakeVoiceDeployer,StudioVoiceFixtures}.php` (ayudantes), `UltronStudioVoice{Profiles,Publishing,Chain,Integrations,NoNetwork,Routes,Race}Test.php` y `tests/Unit/UltronStudio/StudioVoice{PublicationsParity,EvidenceDrift}Test.php`.

## 8. Contratos

### 8.1 Las rutas nuevas (todas bajo `/api/admin/ultron-studio`, 64 KB, persona obligatoria)

| Método y ruta | Permiso | Cupo | Cuerpo → respuesta |
|---|---|---|---|
| `GET voice/profiles?status&page&per_page≤50` | `ultron.view` | lectura | Paginador de `VoiceProfileSummaryDto` (sin contenido) con `actions` |
| `GET voice/profiles/{uuid}` | `ultron.view` | lectura | `VoiceProfileDto` (destinos como `{alias, last4}`) |
| `POST voice/profiles` | `ultron.voice_manage` | escritura | `{clientRequestId, label 3–80, basedOn?, content}` → 201 / 200 `replayed` |
| `POST voice/profiles/{uuid}/submit` | `ultron.voice_manage` | escritura | — → 200 (solo quien lo creó: 403 `not_profile_owner`) |
| `POST voice/profiles/{uuid}/approve` | `ultron.approve` | escritura | `{note?}` → 200 (403 `four_eyes_required`) |
| `POST voice/profiles/{uuid}/reject` | `ultron.approve` | escritura | `{note 5–500}` → 200 |
| `POST voice/compile-preview` | `ultron.voice_manage` | **lectura** | `{versionUuid, profileUuid, purpose?}` → `{specHash, spec, labVerdict, deployable.missing}`; sin red ni escritura |
| `GET voice/publications?page&per_page≤50` | `ultron.view` | lectura | `{state, publishing, history}` (cada entrada con `verified`) |
| `POST voice/publish` | `ultron.voice_publish` | escritura + `ultron-studio-evidence` | `{clientRequestId, versionUuid, profileUuid, note?}` → 201 `{entries, agent, number}` |
| `POST voice/promote` | `ultron.voice_publish` | escritura + `ultron-studio-evidence` | `{clientRequestId, evidenceHash, note?}` → 201 |
| `POST voice/rollback` | `ultron.voice_publish` | escritura | `{clientRequestId, scope: canary\|rest, reason 5–500}` → 201 |
| `POST voice/off` | `ultron.voice_publish` | escritura | `{clientRequestId, reason 5–500}` → 201 (una entrada por parte con agente) |
| `GET voice/canary-evidence` | `ultron.view` | lectura + `ultron-studio-evidence` | `VoiceCanaryEvidenceDto` |
| `GET voice/brakes?limit≤50` | `ultron.view` | lectura | `{state, brakes}` (de `VoiceBrake::list`) |
| `POST voice/brakes/{uuid}/release` | `ultron.voice_publish` | escritura | `{clientRequestId, note 5–300}` → `{brakeUuid, releaseUuid, releasedAt, state}` |
| `GET voice-lab/status`, `GET voice-lab/sessions`, `GET voice-lab/sessions/{uuid}` | `ultron.view` | lectura | LAB8 |
| `POST voice-lab/sessions` | `ultron.voice_lab_run` | escritura + `ultron-studio-lab-run` | LAB8 |
| `POST voice-lab/sessions/{uuid}/end` | `ultron.voice_lab_run` | escritura | LAB8 |
| `POST voice-lab/sessions/{uuid}/verdict` | `ultron.approve` | escritura | LAB8 |

B8-b ya las clasificó en `AuthorizationMap` con exactamente estos permisos (lo fija `UltronStudioVoiceRoutesTest`). `php artisan auth:coverage --unmapped` sale vacío.

### 8.2 Errores (código estable, mensaje sin internals)

403: `forbidden {required_permission}`, `person_required`, `four_eyes_required`, `not_profile_owner`. 404: `voice_profile_not_found`, `version_not_found`. 409: `voice_publishing_disabled`, `outside_publication_window`, `voice_braked {brake}`, `lab_verdict_required {latest, specHash}`, `voice_not_deployable {missing}`, `voice_deploy_failed {failure, agent}`, `voice_agent_rejected {agent}`, `voice_number_not_assigned {outcome, reason, agent}`, `publication_moved`, `client_request_reused`, `invalid_transition {currentStatus, allowedFrom}`, `no_canary_publication`, `canary_evidence_insufficient {evidence}`, `evidence_changed {evidence}`, `voice_agent_drift {drift}`, `nothing_to_rollback {scope}`, `restore_unavailable`, `voice_already_off`, `voice_destinations_unreadable`, `voice_brake_unavailable`; y los de la telefonía al soltar el freno (`brake_already_released`, 404, 422), tal cual. 422: `voice_compile_refused {reason, field}` (los 38 motivos del compilador) y la validación de Laravel.

### 8.3 La cadena (lo que firma el Studio y lee la telefonía)

- **Mensaje:** E1-4 tal cual; `agent_config_hash` = `payload_hash` del agente (igual que el lector de B8-b); `published_at` UTC `Y-m-d\TH:i:s\Z`.
- **Apagar:** el mensaje firma `scope = off` con las cinco claves del agente a null (DF-V3) y la columna `scope` de la fila dice qué parte se apaga. El lector de B8-b lo lee así (`off`), y mi verificación lo cruza (`row_mismatch` si no casa).
- **Una petición, varias entradas (apagar):** la primera lleva la `clientRequestId`; la segunda, un UUID v5 derivado de ella (`derivedRequestId`), porque `client_request_id` es UNIQUE. El reintento devuelve las dos.
- **Firma:** `VoicePublishedConfig::signatureFor(...)` como `Closure` (D8-8). La prueba de paridad la ata a los vectores del fixture.

### 8.4 DTO de la ruta 45 (`GET integrations/voice`), solo se añade

`telephonyState {providerState, subaccountReady, numberImported, inbound{enabled, dailyCap}, outbound{enabled, scope, dailyCap}}`, `phase {id: 1|2|3|4a|4b, basis: config}` (se deduce de la configuración; no autoriza nada), `brake {state}`, `publishing {enabled, code, window{label, open}, labGate}` y `publication {head, canary, rest, verified}`. Cada bloque, por su cuenta (`available: false` si su fuente falla). Lo del H6 (`elevenlabs`, `twilioVoice`, `policy`, `levels`), idéntico.

### 8.5 Configuración nueva (para B8: el fichero de ejemplo de entorno es suyo)

`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false`, `ULTRON_STUDIO_VOICE_LAB_GATE=required`, `ULTRON_STUDIO_VOICE_CANARY_MIN_HOURS=24`, `ULTRON_STUDIO_VOICE_CANARY_MIN_CALLS=3`. **Migraciones:** ninguna (uso el esquema de DB8 tal cual).

## 9. Verificado (comandos ejecutados)

| Pasada | Resultado |
|---|---|
| Mis 9 ficheros de prueba, **SQLite** | **76 pruebas, 990 aserciones, OK** (1 omitida: la carrera, solo PG) |
| Mis 9 ficheros de prueba, **PG** (`ultron_studio_h8_s`) | **76 pruebas, 1 017 aserciones, OK** |
| S8-a (compilador, plantilla, paridad y su Feature) tras el refactor | **365 pruebas, 2 890 aserciones, OK** |
| Vecinas del H6/H7 que tocan lo que cambié (12 ficheros: paridad de integraciones, estado, configuración, centinelas, cero red, guardas adversarias, paridad HTTP, monitor, canarios) | 937 pruebas, **1 fallo**: la matriz de rutas de `UltronStudioIntegrationsParityHttpTest` (56 en el fixture; el Anexo B la arregla). Con la copia parcheada, 466 de 466 |
| `tests/Unit/UltronStudio` + `tests/Feature/UltronStudio` enteros, SQLite | 3 085 pruebas: **10 fallos, todos clasificados** (9 los arregla el Anexo B y 1 es del lector de B8-b, §12) |
| Paridad con `voice-chain-vectors.json` | 37 pruebas: 5 entradas + 27 verificaciones + subclaves + firma de producción |
| Mutaciones por muestreo | **46 mutantes, 44 cazados, 2 equivalentes** |
| `vendor/bin/pint --test` (todos mis ficheros) | `passed` |
| `php artisan auth:coverage --unmapped` | vacío |

- **Mis pruebas, SQLite:** `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioVoice{Profiles,Publishing,Chain,Integrations,NoNetwork,Routes,Race}Test.php tests/Unit/UltronStudio/StudioVoice{PublicationsParity,EvidenceDrift}Test.php` (la de carreras se omite: solo PG).
- **Mis pruebas, PG** (`PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_s bash docs/ultron-studio/ops/pgunit.sh …`, base creada en el clúster desechable `127.0.0.1:55432`): incluida la **carrera de verdad con dos sesiones** (la rival confirma la entrada siguiente entre las puertas y la inserción → 23505 → 409 `publication_moved` fuera de la transacción, nada escrito, el número devuelto al agente de la cadena y la cadena verificando).
- **Paridad con `voice-chain-vectors.json`:** los 5 mensajes, huellas y firmas byte a byte, `prev_hash` = huella anterior (nunca la firma), las 3 subclaves, los **27 casos de verificación** con su resultado, y la firma de producción de B8-b (la que recibe el Studio) contra los vectores. La huella `voice-phone` contra `policy-tables.json` y contra `Telephony\VoicePhone`.
- **Cruce con el lector de la telefonía:** lo que publica el Studio lo acepta `VoicePublishedConfig::read()` (`ok`, con el sello completo desde el agente), y las partes apagadas las lee `off`.
- **Cero red:** con las banderas por defecto y con la configuración de producción de E1-12 (ElevenLabs encendido con credenciales, la fábrica devolvería los adaptadores reales), recorrer las 15 rutas de voz y la 45 no saca ni una petición. Con el adaptador real contra `Http::fake`, publicar despliega y verifica, pero con `CALLS_ENABLED_IN_CODE = false` no asigna el número (`not_attempted`) y no publica: ninguna petición a `phone-numbers`.
- **S8-a sin cambios de comportamiento:** las 361 pruebas del compilador y la plantilla + su Feature.
- **Vecinas del H6/H7** que tocan lo que cambié (`StudioIntegrationsParityTest`, `UltronStudioIntegrations{Status,Config,Sentinel,SentinelAdversarial,EnvSentinelAdversarial,NoNetwork,GuardsAdversarial,ParityHttp,Canary}Test`, `UltronStudioMonitor*Test`): en verde salvo la matriz de rutas de la paridad HTTP (Anexo B). La paridad de `configFacts` de voz llegó a caer con mis hechos del H8; la arreglé en el código (§10, decisión 9), no en el fixture.
- **Pint:** `vendor/bin/pint --test` sobre todos mis ficheros → `passed`.
- **`auth:coverage --unmapped`:** vacío.
- **Mutaciones por muestreo** (copia del fichero en el scratchpad, cargada antes que el autoload de Composer; el árbol compartido no se toca): **46 mutantes en 8 ficheros, 44 cazados, 2 supervivientes equivalentes** (P08: el patrón de `published_at` es redundante con la vuelta por `createFromFormat`; P13: un hueco de `seq` daría `chain_broken` en vez de `seq_gap`, sigue denegando, y no se puede crear con el disparador y el UNIQUE). Dos supervivientes de la primera pasada (G09, G12) destaparon huecos reales de mis pruebas, que cerré: dos promociones del mismo agente y la compensación del número.

## 10. Decisiones que tiene que confirmar el lead

1. **Nombre de la bandera:** `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`, no `VOICE_PUBLISHING_ENABLED` (E2-4). La guarda de SEC8 (`VoiceIsolationTest::test_nadie_fuera_de_su_fichero…`) solo admite `VOICE_*` en `config/voice.php` y `ULTRON_STUDIO_VOICE_*` en `config/ultron_studio.php`, que es el bloque que me toca. LAB8 leyó la suya de `voice.lab.*` (de B8). Si se quiere `VOICE_PUBLISHING_ENABLED`, B8 la pone en `config/voice.php` y yo leo `voice.publishing.enabled`: una línea.
2. **La ventana de voz es la del H5** (`ULTRON_STUDIO_PUBLISH_WINDOW`), como dice D8-19 («la misma ventana del H5»). Si P-25 decide otra, una variable más.
3. **El número sigue a la parte canaria** (`INBOUND_PART`): en el H8 las entrantes son solo de canarios (E2-8). Promover no toca el número; revertir el canario lo reasigna; apagar lo deja sin agente.
4. **Orden con el número.** Publicar sigue D8-7: asignar **antes** de la entrada; si la transacción falla (carrera), la compensación devuelve el número al agente que diga la cadena. Revertir y apagar escriben la entrada **antes** (sentido seguro: la cadena ya deniega) y el número después; un fallo del proveedor se informa (`number.outcome`) y lo vigila la deriva del número (E2-3). **Riesgo residual inevitable con dos sistemas:** una entrante que llegue entre la asignación y la confirmación (o al revés) se sella con un agente y la atiende otro → `seal_mismatch` → freno. Fallo seguro; conviene publicar con el canario en silencio.
5. **Cuatro ojos al publicar y promover:** nadie publica ni promueve una voz cuya **versión o perfil** creó.
6. **Puerta del laboratorio (P-19):** el último veredicto sobre la **misma huella** tiene que ser `approved`; lo da `StudioVoiceLabVerdicts::latestFor()` de LAB8 (solo agentes reales, no el simulador), como recomendó LAB8 (§2.5 de su informe). `waived` queda escrito en la entrada.
7. **Evidencia del canario (P-16):** ≥ 24 h, ≥ 3 llamadas selladas, 0 en las cinco cuentas de incidentes, cadena verificada, freno suelto y el veredicto. La medida es de B8 (ver §11.1); hasta que exista, promover se niega (`evidence_unavailable`).
8. **Previsualizar** exige `ultron.voice_manage` (D8-22) y devuelve la especificación entera (el prompt compilado, sin teléfonos ni secretos).
9. **Los hechos de voz del H8 solo entran si hay alguna pieza del H8 configurada.** Si entraran siempre, desplegar la fase 1 cambiaría la huella de voz de producción y el monitor del H7 vería la configuración autorizada en deriva. Así, la fase 1 no cambia nada y la primera pieza del H8 (fase 2) obliga a reconocerla otra vez, como dice el contrato. Una prueba lo fija.
10. **Libro del Studio:** los eventos `voice_profile.*`, `voice_publication.*` y `voice_brake.released` van con `subject_type = integration`, porque el CHECK `usae_subject_type_chk` no admite otros sujetos. `StudioAudit::EVENTS` (no es mío) no los lista. Si se quieren sujetos propios, es una migración (DB8).
11. **La huella `voice-phone` existe en dos sitios:** `StudioVoiceProfiles::phoneHash()` (destinos) y `Telephony\VoicePhone::hash()` (llamadas). El Studio no puede nombrar la telefonía salvo la firma (guarda de SEC8). Las dos se fijan contra el mismo fixture y entre sí en una prueba. Un único punto neutro (p. ej. en `PhoneFingerprint`) es decisión del lead.
12. **Las rutas del laboratorio** son 6 (LAB8 añadió `GET voice-lab/sessions/{uuid}`); su limitador es `ultron-studio-lab-run` (el del H4). Un limitador propio iría en `AppServiceProvider`, que no tiene dueño en el H8.
13. **Pruebas sin la puerta.** Las de comportamiento quitan `EnforceAdminAuthorization` y prueban la segunda cerradura (la llave en el servicio). La primera la prueba `UltronStudioVoiceRoutesTest` con la puerta puesta. Desplegador, firmante y medida se inyectan **solo en las pruebas** (constructor); en producción, `VoiceAdapters::deployer()`, `VoicePublishedConfig::signatureFor` y la telefonía.

## 11. Lo que necesito de otros paquetes (sin tocar sus ficheros)

### 11.1 B8-b

- **`Telephony\VoiceEvidence::forPublication(int $seq, string $part, DateTimeInterface $since): array`** que devuelva `{calls, guardViolations, sealMismatches, driftEvents, unknownProviderCalls, budgetBreaches}`, enteros ≥ 0. `calls` son las llamadas selladas con esa entrada y esa parte, sin tramos ni denegadas. Hoy no existe: `StudioVoiceEvidence::measure()` falla cerrado y promover se niega.
- Las cuatro variables de §8.5 en el fichero de ejemplo de entorno.
- Su lector nombra `ultron_studio_voice_agents` y cae en dos guardas (`TelephonyIsolationTest`, `UltronEngineIsolationTest`). Es suyo y de SEC8. Lo apunto porque los tres campos del sello que no van en el mensaje firmado (`studio_content_hash`, `voice_profile_hash`, `compiler_version`) solo están en el agente.

### 11.2 SEC8: guardas que caen a propósito con mi código

- **Anexo C** (`anexo-c-sec8-e2-6.diff`, 14 líneas, validado en una copia): `TelephonyIsolationTest::test_el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` admite `Telephony\VoiceEvidence` y `Telephony\VoiceBrake` **solo** en `app/Services/UltronStudio/StudioVoiceEvidence.php` (E2-6). Con eso pasa para todos mis ficheros (comprobado).
- `UltronEngineIsolationTest::test_ninguna_ruta_del_studio…`: 56 → `UltronStudioVoiceController::STUDIO_ROUTE_COUNT`. Va en el Anexo B (una línea).

### 11.3 El lead (o TEST8): Anexo B, roturas previstas por las rutas y las llaves nuevas (E2-6)

`anexo-b-recuentos-h8.diff`, 201 líneas, solo pruebas, `git apply --check` limpio. Validado ejecutando las copias parcheadas, que pasan:
- `UltronStudioPermissionsTest`: la matriz de 38 excluye `voice/` y `voice-lab/`; el dominio `ultron` tiene 15 llaves (12 + 3).
- `UltronStudioIntegrationsPermissionsTest`: 56 → la constante; las 4 llaves del H6 son `slice(8, 4)`.
- `UltronStudioEarlyBodyCapTest`: 56 → la constante.
- `UltronStudioIntegrationsParityHttpTest`: la matriz del H6 excluye las rutas de voz (el fixture no cambia).
- `UltronStudioLabNoEngineEffectsTest`: excluye `voice/` y `voice-lab/` (su propia matriz; la sesión del laboratorio hablado lleva a propósito el limitador del H4).
- `UltronEngineIsolationTest`: la línea del recuento (de SEC8; ver §11.2).
- `UltronStudioEngineReadOnlyTest`: un `walkVoice()` recorre las 21 rutas (las 15 mías con cuerpos reales y las 6 de LAB8 con la bandera apagada). La cuenta pasa a la constante. Con él, el recorrido entero sigue sin escribir fuera de `ultron_studio_*` y sin más red que los 5 GET de la sonda.

Los dos parches están en el scratchpad de esta sesión: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/5a9c1a1c-0c3d-4d24-b9ec-44d86466439a/scratchpad/anexo/`.

### 11.4 OPS8, LAB8, FE8

- **OPS8:** `StudioVoiceDrift::published()` (una GET por agente vivo) y `numberVerdict()` no tienen comando que los programe (D8-21). Abrir el freno por deriva es de B8 (`VoiceBrake::open('agent_drift', …)`).
- **LAB8:** con `transferNumbers()` y `phoneHash()` ya en el árbol, su `prepare()` puede admitir perfiles con destinos (su informe §6.4).
- **FE8:** los DTO de §8.

## 12. Pendiente, riesgos y qué no verifiqué

- **Guardas de SEC8 con el árbol de hoy** (`TelephonyIsolationTest`, `VoiceIsolationTest`, `VoiceBoundaryTest`, `UltronEngineIsolationTest`, `OtpUntouchedByVoiceTest`: 41 pruebas, 9 fallos):
  - **míos, previstos por E2-6:** `test_el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` (`StudioVoiceEvidence` nombra `VoiceBrake`/`VoiceEvidence`; Anexo C) y el recuento de rutas de `UltronEngineIsolationTest` (Anexo B);
  - **de ficheros de B8-b en curso**, no míos: el lector nombra `ultron_studio_voice_agents` (dos guardas), `VoiceDoctorCommand` (tres), el controlador de iniciación registra `$request` y el dominio `telephony` en el catálogo;
  - dos que eran míos los corregí en el código: el literal `'telephony'` en `StudioIntegrations` (la clave pasó a `telephonyState`) y dos ficheros de prueba sin `Http::preventStrayRequests()`.
- **El único fallo de la suite del Studio que no arregla el Anexo B:** `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra…`, por el lector de B8-b.
- **No ejecuté** la suite completa del backend ni el banco de OPS8 (máquina compartida; es de TEST8). La pasada amplia fue `tests/Unit/UltronStudio` + `tests/Feature/UltronStudio`, en SQLite.
- **No probado contra el proveedor real:** la asignación del número y la vigencia de la URL firmada están [por confirmar] (informe de B8-a §5).
