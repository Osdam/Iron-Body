# Informe de `backend` · B8-a (paso 1 del Hito 8)

**Fecha:** 03-11-2026. **Rama:** `feat/ultron-studio-h8-voice` (worktree `ultron-h8`); el trabajo parcial está en el WIP `4ae91fda` y el resto, en el árbol, **sin commit** (lo confirma el lead). **Alcance:** el del paso 1 del contrato: adaptadores puros, DTO, validador de Twilio, festivos, mapper y nulos. **No** se empezaron políticas, la puerta de salientes, el job, los webhooks, las herramientas, el coste, la conciliación, el freno, las rutas, los permisos, `bootstrap/app.php` ni `config/queue.php` (B8-b, después de la puerta 1). **Cero red:** ninguna petición a un proveedor; la documentación oficial se consultó con WebFetch el 03-11-2026.

## 1. Resultado (cifras reales)

- **274 pruebas nuevas y 1 011 aserciones** en 13 ficheros, todas en verde, más 2 pruebas del H6 ajustadas al cambio deliberado del contrato (`ElevenLabsVoiceProviderTest`, 14 pruebas; `ElevenLabsConfigTest`, 11).
- **Corrida conjunta: 1 071 pruebas y 107 171 aserciones en verde.** Incluye todo `tests/Unit/Voice`, `tests/Unit/Twilio`, `tests/Unit/Telephony` y `tests/Feature/Voice`, las guardas de SEC8 (`TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`, `UltronEngineIsolationTest`), el compilador y la plantilla de S8, y las pruebas de la configuración y la instantánea de integraciones.
- **Fixtures confirmados de TEST8:** los 45 vectores de `twilio-signature-vectors.json` (15 de cálculo, con la cadena a firmar exacta; 25 de validación; 5 de URL) y los 27 de `elevenlabs-webhook-vectors.json` (resultado de la firma, si se procesa, el tipo y el HTTP de DF-E1). Además, paridad con los 7 casos `twilioSignatureOfficial` del fixture del H6.
- **Paridad real con S8:** `VoiceAgentSpec::fromCompiled()` acepta la salida de `StudioVoiceCompiler::compile()` en su estado actual del árbol, y el `specHash` coincide byte a byte (`9492a8c0…`). El JSON canónico es idéntico al de `StudioContentHasher`. Es una comprobación puntual, no una prueba del repo: ver §7.
- `php -l` sin errores en los 48 ficheros de `app/Services/{Voice,Twilio,Telephony}` y `config/voice.php`. Pint `passed` en todo lo tocado. `outbound-call` aparece solo en `ElevenLabsCallStarter.php` y `register-call` en ninguno.
- No toca la base de datos. Ninguna prueba mía usa la base, así que la puerta PG no aplica a este paquete.

**Cómo se ejecutó** (desde `backend/`):

```
php -d memory_limit=2G vendor/bin/phpunit --log-junit <scratchpad>/junit-final.xml tests/Unit/Voice tests/Unit/Twilio tests/Unit/Telephony tests/Feature/Voice tests/Feature/Telephony/TelephonyIsolationTest.php tests/Feature/Telephony/OtpUntouchedByVoiceTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php tests/Unit/UltronStudio/StudioVoiceCompilerTest.php tests/Unit/UltronStudio/StudioVoiceTemplateTest.php tests/Feature/UltronStudio/UltronStudioIntegrationsConfigTest.php tests/Feature/UltronStudio/UltronStudioIntegrationsSentinelAdversarialTest.php tests/Unit/UltronStudio/StudioIntegrationsParityTest.php
→ OK (1071 tests, 107171 assertions)
```

El JUnit va al scratchpad para no pisar `storage/test-results/junit.xml` de los demás agentes. Con 128 MB de memoria, las Feature no caben (como dice `bin/suite`).

## 2. Ficheros

**Nuevos, `app/Services/Voice/` (interfaces de D8-3, nulos y fábrica):**
- `VoiceAgentDeployer.php`, `VoiceCallStarter.php`, `VoiceEventVerifier.php`, `VoiceLabSessionIssuer.php`, `TelephonyCarrier.php`
- `NullVoiceAgentDeployer.php`, `NullVoiceCallStarter.php`, `NullVoiceEventVerifier.php`, `NullVoiceLabSessionIssuer.php`, `NullTelephonyCarrier.php`
- `VoiceAdapters.php` (fábrica sin contenedor, como `VoiceProviders`)

**Nuevos, `app/Services/Voice/Dto/` (14):**
- `VoiceAgentSpec`, `VoiceAgentTarget`, `VoiceAgentPayload`, `VoiceDeployResult`, `VoiceAgentObservation`
- `OutboundCallRequest`, `OutboundCallResult`, `VoiceWebhookVerdict`, `VoiceLabSession`
- `CarrierResult`, `CarrierCall`, `CarrierCallsPage`
- `VoiceIds` (patrones), `VoiceCanonicalJson` (huella canónica)

**Nuevos, `app/Services/Voice/ElevenLabs/`:** `ElevenLabsAgentMapper.php`, `ElevenLabsAgentDeployer.php`, `ElevenLabsCallStarter.php`, `ElevenLabsEventVerifier.php`, `ElevenLabsLabSessionIssuer.php`.

**Nuevos, fuera de `Voice`:** `app/Services/Twilio/TwilioVoiceCarrier.php`, `app/Services/Twilio/TwilioRequestSignature.php` y `app/Services/Telephony/ColombianHolidays.php`.

**Cambiados del H6:**
- `app/Services/Voice/VoiceCallContext.php`: `SOURCE_VOICE_CONTACTS = 'voice_contacts'` (`:50`) en `SOURCES`. `VoiceCallPolicy` no cambia (ver §4).
- `app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php`: `secretFingerprints()` gana `initiation_secret` (`:149`), al final del array, y el docblock pasa a «lectores enumerados».
- `config/voice.php`: solo claves nuevas, con su defecto apagado, y el comentario «integración nativa (descartada)» corregido (D8-5).
- El fichero de ejemplo de entorno: el bloque «Voz y telefonía (Hito 8)» y un comentario en `ELEVENLABS_AGENT_PHONE_NUMBER_ID`.

**Pruebas nuevas:**
- En `tests/Unit/Voice/`: `VoiceAgentSpecTest`, `VoiceDtoTest`, `VoiceNullAdaptersTest`, `ElevenLabsAgentMapperTest`, `ElevenLabsAgentDeployerTest`, `ElevenLabsCallStarterTest`, `ElevenLabsEventVerifierTest`, `ElevenLabsLabSessionIssuerTest` y `ElevenLabsWebhookVectorsTest`, más los ayudantes `Support/VoiceSpecSamples.php` y `Support/FreshHttpFake.php`.
- En `tests/Unit/Twilio/`: `TwilioRequestSignatureTest`, `TwilioRequestSignatureFixtureTest` y `TwilioVoiceCarrierTest`.
- En `tests/Unit/Telephony/`: `ColombianHolidaysTest`.

**Pruebas del H6 ajustadas** (solo por el cambio deliberado del contrato):
- `ElevenLabsVoiceProviderTest`: las dos expectativas del array de huellas ganan `'initiation_secret' => null`.
- `ElevenLabsConfigTest::test_config_voice_aplica_las_reglas_al_leer_el_entorno`: el defecto esperado gana las claves del H8 apagadas, se añaden sus recortes y normalizaciones, y las variables nuevas entran en la lista que se limpia del entorno. Las aserciones del H6 siguen intactas.

## 3. Contratos que fija B8-a (D8-3: «B8 las fija»)

### 3.1 Interfaces (firmas definitivas del paso 1)

```php
VoiceAgentDeployer::createAgent(VoiceAgentPayload): VoiceDeployResult
VoiceAgentDeployer::describeAgent(string $agentId): VoiceAgentObservation
VoiceAgentDeployer::assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
VoiceCallStarter::startOutbound(OutboundCallRequest): OutboundCallResult
VoiceEventVerifier::verifyPostCall(string $rawBody, ?string $signatureHeader, int $now): VoiceWebhookVerdict
VoiceLabSessionIssuer::signedSessionUrl(string $agentId): VoiceLabSession
TelephonyCarrier::hangup(string $callSid): CarrierResult
TelephonyCarrier::listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
```

**Fábrica `VoiceAdapters`:**
- `deployer()`, `callStarter()` y `labSessionIssuer()` dan ElevenLabs solo en `ELEVENLABS_UNVERIFIED`.
- `eventVerifier()` da ElevenLabs si hay secreto del webhook, aunque la bandera esté apagada: comprobar un evento no necesita red.
- `carrier()` da Twilio con la credencial de la subcuenta completa, sin mirar `TWILIO_VOICE_ENABLED`: cortar y conciliar tienen que poder hacerse con la voz apagada (D8-20 nivel 0b).
- En cualquier otro caso, el nulo.

### 3.2 Diferencias deliberadas respecto a las firmas indicativas de D8-3

| Dónde | D8-3 decía | Queda | Por qué |
|---|---|---|---|
| `OutboundCallResult` | `started`, `rejected`, `ambiguous`, `error` | `started`, `rejected`, `ambiguous` y `not_attempted` | Un fallo que no prueba que no se marcó no puede ser un «no se marcó»: es `ambiguous` (nunca se reintenta). Y uno que lo prueba, sin red, es `not_attempted` (`calls_disabled_in_code` o `not_configured`). Motivos cerrados en `REASONS_BY_OUTCOME` |
| `VoiceDeployResult` | agentId, observedProjectionHash, outcome | outcome, agentId, toolIds, httpStatus, reason | La creación no devuelve la configuración: la proyección observada la da `describeAgent()`. `toolIds` y `reason` (`tool_step` o `agent_step`) dicen qué quedó si falla a mitad |
| DTO nuevas | — | `VoiceAgentTarget`, `CarrierCall`, `VoiceIds`, `VoiceCanonicalJson`; la fábrica `VoiceAdapters` | Parámetros de despliegue que no salen de la compilación, filas de la conciliación, patrones compartidos y huella canónica sin nombrar el Studio. SEC8-a ya las incluye en sus guardas |

### 3.3 `VoiceAgentSpec`: el lector estricto del JSON de S8

S8-a empezó antes de que existiera la clase y construye un array. Su forma es mejor en privacidad: los destinos van como alias y huella, sin teléfono. Por eso `VoiceAgentSpec` pasó a ser el **lector estricto de exactamente ese JSON**:
- `fromArray()` y `fromCompiled()`; esta última exige además que el `specHash` cuadre.
- Las claves exactas a cada nivel.
- `specHash` = `VoiceCanonicalJson::hash()`, los mismos bytes que `StudioContentHasher`.

**Invariantes propias** (protegen al proveedor aunque el compilador fallara):
- `caller_authorized = no` por defecto, y `call_ref` por defecto que no es un uuid.
- `overridesAllowed = false`.
- `recording` y `storeAudio` en `false`.
- `end_call` siempre; `transfer_to_number` si y solo si hay destinos; transferencia solo en conferencia.
- El prompt solo nombra las 4 variables o `system__…`, nunca `secret__…`.
- Textos sin invisibles ni llaves.
- Buzón solo `hang_up` (E1-8 y E1-9).

Los teléfonos de los destinos van en `VoiceAgentTarget::transferNumbers()`, indexados por `ref`. Quien despliega los descifra en memoria (D8-14: «en claro solo dentro de la carga compilada»).

### 3.4 El mapper y la proyección de deriva

**Lo que fija `ElevenLabsAgentMapper`.** Los valores por defecto oficiales del 03-11-2026 son abiertos, así que va todo explícito:
- `call_limits`: concurrencia y diario del target, y `bursting_enabled = false` (por defecto: −1, 100 000 y ráfaga encendida).
- `privacy`: `record_voice = false`, la retención y `delete_audio`.
- Las sobrescrituras cerradas.
- El webhook de iniciación con su URL fija y la cabecera `X-IronBody-Voice-Initiation` (`:75`) por `secret_id`.
- Los eventos post-llamada `call_initiation_failure` y `transcript`, sin `audio`.
- `auth.enable_auth = true`.
- μ-law 8000, `TTS_MODEL = eleven_flash_v2_5` (`:57`), `LLM = gemini-2.5-flash` (`:54`) y temperatura 0.

**Herramientas:** son recursos aparte (`POST /v1/convai/tools`; el campo `tools` en línea está obsoleto). Sus ids de llamada salen de variables (`system__conversation_id`, `system__call_sid` y `call_ref`), nunca del modelo [C-A3].

**La proyección** sale de la MISMA función para lo compilado y lo observado:
- Ignora los valores por defecto que añade ElevenLabs.
- Detecta cualquier cambio en un campo controlado: una sobrescritura abierta, una herramienta de más o de menos, una base de conocimiento, un flujo, otro idioma, una herramienta de sistema activa o un literal en lugar del secreto.
- No lleva secretos: de una cabecera solo cuenta si es una referencia, o un literal sin su valor.
- Laboratorio y publicación comparten proyección y difieren en nombre y etiquetas (D8-7: «misma compilación»).

### 3.5 Cerrojos del código

- `startOutbound()` (`ElevenLabsCallStarter.php:58`) devuelve `not_attempted` sin red con `CALLS_ENABLED_IN_CODE = false`, aunque la configuración esté completa y la clase se instancie a mano.
- `assignNumber()` con un agente (`ElevenLabsAgentDeployer.php:209`) también, porque asignar el número abre las entrantes. Dejarlo sin agente (null) siempre se puede.
- El camino con red de la saliente solo se prueba en la copia del banco con la constante girada (TEST8); aquí se prueban el cuerpo y la tabla de resultados, que son puros.

## 4. Decisiones que tiene que confirmar el lead

1. **Ubicaciones.**
   - `TwilioRequestSignature` va en `app/Services/Twilio/`, por la orden del lead; D8-4 decía `Telephony\`. Las guardas de SEC8 admiten los dos sitios.
   - `ColombianHolidays` va en `app/Services/Telephony/`, como dice D8-15.
2. **Huellas de los secretos de Twilio.**
   - D8-5 dice «salen de un único punto». La del secreto de iniciación está en `ElevenLabsVoiceProvider::secretFingerprints()`, como pidió el lead.
   - Las de la key de Laravel y del token de firma las calcula su único lector: `TwilioVoiceCarrier::secretFingerprints()` (`:202`) y `TwilioRequestSignature::secretFingerprints()` (`:252`). Mismo algoritmo, 8 hex de HMAC-SHA256 con la clave de quien llama.
   - Así cada secreto lo lee un único fichero. Si se quiere un único punto literal, son dos líneas, y la guarda de SEC8 tiene que admitirlo.
3. **E1-12, ya comprobado.** La huella nueva no entra en `StudioIntegrationConfig::voiceFactsFrom()`, así que los hechos de voz y la **huella autorizada de producción no cambian**. Si S8 la añade a los hechos en S8-b, habrá que reconocer la configuración otra vez. Están en verde `VoiceDoctorCommandTest`, `UltronStudioIntegrationsConfigTest`, `UltronStudioIntegrationsSentinelAdversarialTest` y `StudioIntegrationsParityTest` (456 pruebas).
4. **Consecuencia de E1-12 que hay que tener presente.** En producción, ElevenLabs está en `ELEVENLABS_UNVERIFIED` o `ELEVENLABS_VERIFIED` (bandera encendida y piezas completas). Con el código del H8 desplegado, `VoiceAdapters::deployer()` y `labSessionIssuer()` darían los adaptadores reales:
   - el código del paso 1 no los llama nadie (no hay rutas ni jobs);
   - S8-b, LAB8 y B8-b tienen que poner sus propias puertas (bandera de publicación, los cuatro cerrojos del laboratorio, el presupuesto) **antes** de llamarlos;
   - las llamadas y la asignación del número siguen cerradas por la constante;
   - con el plan **free**, crear agentes puede fallar o gastar cupo.
5. **`VoiceCallContext::SOURCES` gana `voice_contacts`**, pero `VoiceCallPolicy::decide()` no se toca, porque no estaba en mi lista de ficheros: sigue diciendo `source_not_allowed`. `OutboundPolicy` (B8-b) tiene que componer de la política del H6 solo las puertas del código y del proveedor, como dice D8-12. Otra opción es que el lead autorice esa línea en B8-b.
6. **`VOICE_INBOUND_SCOPE` no existe.** En el H8 solo hay `canary` y la configuración lo fija: una variable sin otro valor posible sería una palanca muerta. Por simetría sería una línea.
7. **Techos no nulos por defecto:** `VOICE_MAX_CONCURRENT_CALLS=1` y `VOICE_PER_CALL_MAX_SECONDS=300`, como dice D8-15. La protección 1 dice «false o 0», pero estos son techos que no encienden nada; lo que apaga son las banderas, los cupos de 0 y el presupuesto de 0.
8. **Saludo por sentido:** se usa `call_direction` (E1-5 y E1-6), así que el primer mensaje es solo el aviso.

## 5. Documentación oficial comprobada (03-11-2026)

**ElevenLabs:**
- `POST /v1/convai/agents/create` → `{agent_id}`. La página dice que `enable_versioning` está obsoleto «porque todos los agentes están versionados»: no cambia la decisión de un agente por publicación.
- `GET /v1/convai/agents/{id}` (con `version_id`, `branch_id` y `tool_ids`).
- `POST /v1/convai/tools` y `GET /v1/convai/tools/{id}`.
- `PATCH /v1/convai/phone-numbers/{id}` `{agent_id}`: el esquema admite null; **[por confirmar]** que null desasigne.
- `GET /v1/convai/conversation/get-signed-url?agent_id=` → `{signed_url}`. **[Por confirmar]** la vigencia: la página no da los 15 minutos.
- `POST /v1/convai/twilio/outbound-call`, con `conversation_id` y `callSid` que pueden venir null.
- Webhook post-llamada: `{type, event_timestamp, data}`, y `call_initiation_failure` con `failure_reason` `busy`, `no-answer` o `unknown`.

**Twilio:**
- `POST …/Calls/{CA}.json` con `Status=completed`.
- `GET …/Calls.json` con `PageSize` (como mucho 1000), `PageToken` y `StartTime>`.

**[Por confirmar con la cuenta real (fases 2 y 3)]:**
- `metadata.phone_call.call_sid` de la transcripción;
- el formato de `tool_calls`;
- que las variables del cuerpo de las herramientas no las rellene el modelo (C-A3);
- que el webhook de iniciación no se dispare en las sesiones web del laboratorio: si se dispara, las sesiones de LAB8 recibirían `caller_authorized = no`;
- que el GET devuelva lo enviado sin normalizarlo (`workflow`, `transfer_type`).

## 6. Variables nuevas en el fichero de ejemplo de entorno (todas apagadas o vacías)

- **ElevenLabs:** `ELEVENLABS_INITIATION_SECRET`, `ELEVENLABS_TOOL_SECRET_REF`, `ELEVENLABS_INITIATION_SECRET_REF`, `ELEVENLABS_POST_CALL_WEBHOOK_REF`.
- **Twilio:** `TWILIO_VOICE_ACCOUNT_SID`, `TWILIO_VOICE_API_KEY_SID`, `TWILIO_VOICE_API_KEY_SECRET`, `TWILIO_VOICE_SIGNING_TOKEN`, `TWILIO_VOICE_BASE_URL`.
- **Voz:** `VOICE_WEBHOOK_BASE_URL`, `VOICE_INBOUND_ENABLED=false`, `VOICE_INBOUND_DAILY_CAP=0`, `VOICE_OUTBOUND_ENABLED=false`, `VOICE_OUTBOUND_SCOPE=canary`, `VOICE_MAX_CONCURRENT_CALLS=1`, `VOICE_PER_CALL_MAX_SECONDS=300`, `VOICE_DAILY_BUDGET_USD=0`.

Las `ULTRON_STUDIO_VOICE_LAB_*` de D8-23 **no** se añadieron: las lee `config/ultron_studio.php`, que es de S8 y OPS8, y nadie me las ha pasado aún.

## 7. Hallazgos para otros paquetes

1. **TEST8 — festivos.** `holidays-co-2026-2028.json` se generó sin red y **no incluye la Ley 2578 del 1 de junio de 2026**: el 9 de julio, Nuestra Señora del Rosario de Chiquinquirá, con la Ley 51 de 1983, en vigor desde su promulgación.
   - Fuentes: el Ministerio del Interior, 05-06-2026, y el Centro de Estudios Regulatorios.
   - Faltan **2026-07-13, 2027-07-12 y 2028-07-10**: 19 festivos por año, no 18.
   - Una nota de prensa dice que la ley está demandada ante la Corte Constitucional [por confirmar el estado]; si cae, se quita en `ColombianHolidays::CHIQUINQUIRA_SINCE` (`:54`).
   - Mi prueba también fija el caso real de 2025 (San Pedro y Sagrado Corazón el lunes 30-06: 18 festivos en 17 fechas) y el de 2038.
   - Por eso **no probé contra ese fixture**: fallaría en esas tres fechas.
2. **SEC8 — guarda de `TwilioReadOnlyClientTest`.** `test_el_espacio_twilio_es_neutro_no_lee_configuracion_ni_nombra_el_resto_de_la_aplicacion` (`:497-510`) cerraba `app/Services/Twilio` a sus 2 ficheros del H6, sin lecturas de configuración. Eso choca con D8-3 y D8-5: `TwilioVoiceCarrier` vive allí y es el lector enumerado de la key. Alguien la amplió en el árbol (+12 líneas, sin commit) y hoy pasa. No la toqué; que el lead confirme quién es su dueño.
3. **S8 — paridad.** Conviene una prueba de paridad en el repo, `VoiceAgentSpec::fromCompiled(StudioVoiceCompiler::compile(...))`, dentro de las de S8 o TEST8. Yo la comprobé de forma puntual (§1) para no acoplar mis pruebas a un compilador en curso.
4. **PERF8.** `describeAgent()` hace 1 GET y uno más por herramienta (hasta 4). La deriva por saliente lo cachea D8-21 (≤ 5 min).
5. **B8-b** (yo, después de la puerta 1):
   - leer `voice.*` en las políticas, con la cabecera de iniciación `ElevenLabsAgentMapper::INITIATION_AUTH_HEADER`;
   - el cuerpo de las herramientas: `conversation_id`, `call_sid` y `call_ref`;
   - el secreto de las herramientas en el gestor de ElevenLabs tiene que valer `Bearer <ELEVENLABS_TOOL_SECRET>` (lo apunta DOC8);
   - el mapeo de los veredictos a HTTP (aceptado → 200, rechazado → 401, `not_configured` → 5xx);
   - `config/queue.php` (E1-10).

Sin commit: el lead confirma por paquete.

---

# §B8-b · paso 2 del Hito 8 (telefonía sobre los adaptadores de B8-a)

**Fecha:** 07-11-2026. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h8-voice`, **sin commit**. Cero red: ninguna petición a un proveedor; toda prueba con `Http::preventStrayRequests()`.

## 1. Cifras reales (comandos ejecutados)

- SQLite: `phpunit tests/Unit/Voice tests/Unit/Twilio tests/Unit/Telephony tests/Feature/Voice tests/Feature/Telephony tests/Feature/Access tests/Feature/Chaos/ChaosLaneIsolationTest.php` → **834 pruebas, 178 640 aserciones, 4 fallos** (las 4 guardas de §4; 131 omitidas: las de esquema solo-PG).
- Puerta PG (`UNIT_DB=ultron_studio_h8_b bash docs/ultron-studio/ops/pgunit.sh … tests/Feature/Telephony tests/Unit/Telephony tests/Feature/Voice tests/Feature/Access tests/Unit/Voice tests/Unit/Twilio`) → **825 pruebas, 179 329 aserciones, 4 fallos (las guardas de §4) y 1 error ajeno** (`AdminUserPasswordTest`, usa sintaxis de disparador de SQLite; fichero no tocado). La puerta PG cazó un fallo real mío (`outcome = 'no-answer'` contra `vce_codes_chk`): corregido con `VoiceEvents::code()`.
- Mis pruebas nuevas: **12 ficheros, 108 pruebas** (paridad 15; iniciación 8; post-llamada 19; herramientas 26; aviso de Twilio 9; conciliación 4; CRM 8; job 6; freno 3; rutas 2; cero red E2-4 1; comandos 2), todas en verde en SQLite y PG.
- Paridad con TEST8-a, caso a caso: `inboundPolicy` 15, `decide` 43, `whatsappGuard` 7, `scheduleLegal` 22 y `scheduleOwn` 11, `frequency` 19, `consent` 13, `exclusions` 16, `costReservation` 17, `brake` 10, `phoneHash` 7, cadena (`chain` 5, `subkey` 3, `verify` 27), `guard` 9 (por el verificador real), `postCall` 16, `tools` 21, `usageTrigger` 7 (firmas reales), `callsPage` 1 (9 llamadas, por el transportista real con `Http::fake`).
- `php artisan auth:coverage --unmapped` → vacío (exit 0). Pint `--test` en mis 78 ficheros → `passed`.
- **No hecho:** mutaciones por muestreo (sin tiempo en esta tanda). PERF8 no medido.

## 2. Ficheros

Nuevos: `app/Services/Telephony/{InboundPolicy,OutboundPolicy,InboundGate,OutboundGate,OutboundSchedule,VoiceFrequency,VoiceConsentRule,VoiceExclusionRule,VoiceCostGuard,VoiceNumberGuard,VoicePhone,VoicePublishedConfig,VoicePublishedPart,VoiceTranscriptGuard,VoiceCanaryDirectory,VoiceLeadSignals,VoiceToolAuth,VoiceInitiationAuth,VoiceTools,VoicePostCall,TwilioUsageAlert,VoiceReconciler,VoiceHangup,VoiceBrake,VoiceEvidence,VoiceEvents,VoiceLocks,VoiceCallFacts,VoiceCallsQuery,VoiceTelephonyStatus,VoiceContacts,VoiceActor,VoiceProviderState,VoiceTransferDirectory,TelephonyException}.php`; `app/Models/Voice/{VoiceCall,VoiceCallEvent,VoiceContact,VoiceConsent,VoiceExclusion,VoiceRneCheck,VoiceBrakeEntry}.php`; `app/Jobs/Voice/PlaceOutboundVoiceCall.php`; `app/Http/Controllers/Api/Voice/{ElevenLabsPostCallController,ElevenLabsInitiationController,VoiceToolController,TwilioVoiceUsageTriggerController}.php`; `app/Http/Controllers/Crm/Voice/{VoiceCallController,VoiceContactController}.php`; `app/Console/Commands/Voice/{VoiceHangupCommand,VoiceReconcileCommand}.php`; `routes/voice.php`; pruebas en `tests/Unit/Telephony/` y `tests/Feature/Telephony/` (+ `Support/VoiceFixtures.php`).

Cambiados: `config/voice.php` (E2-4 y las piezas de abajo), `config/queue.php` (conexión y carril `voice`, E1-10), `config/logging.php` (canal `voice`, D8-4; no tenía dueño), `bootstrap/app.php` (monta `routes/voice.php`), `.env.example` (bloque B8-b), `app/Support/Access/{AuthorizationMap,CrmPermission,PermissionCatalog}.php`, `app/Console/Commands/Voice/VoiceDoctorCommand.php` (`--telefonia`; salida por defecto intacta), y de B8-a: `ElevenLabsAgentMapper` (cabecera `X-Voice-Initiation-Secret`, la de fixtures, banco y lead), `ElevenLabsEventVerifier` + `VoiceWebhookVerdict` (turnos de la guarda con su índice y lo que devolvió `consultar_planes`, solo en memoria; `call_ref` desde `user_id` en `call_initiation_failure`, DF-S2) y sus pruebas `ElevenLabsAgentMapperTest`/`ElevenLabsConfigTest`.

## 3. Contratos

**Rutas nuevas (17).** Públicas (en `AuthorizationMap::PUBLIC_VOICE_ROUTES` con su razón): `POST api/webhooks/voice/elevenlabs/post-call` (1 MB, 240/min), `POST api/webhooks/voice/elevenlabs/initiation` (8 KB, 120/min), `POST api/voice/tools/{tool}` (8 KB, 600/min), `POST api/webhooks/voice/twilio/usage-trigger` (64 KB, 60/min). CRM (16 KB; 120/min lectura, 30/min escritura): `GET status|calls|calls/{uuid}|metrics` (`telephony.view`), `POST calls`, `POST calls/{uuid}/hangup` (`telephony.call_place`), `GET|POST contacts`, `POST contacts/{uuid}/consents`, **`POST contacts/{uuid}/consents/confirm` (nueva, E3-4: la segunda persona)**, `POST contacts/{uuid}/revoke`, `POST exclusions`, `POST rne-checks` (`telephony.contacts_manage`). El campo del teléfono en las peticiones se llama `number`. Mapeadas también las 22 rutas de voz del Studio de S8/LAB8.

**Permisos (D8-22):** `ultron.voice_manage`, `ultron.voice_publish`, `ultron.voice_lab_run`, `telephony.view`, `telephony.call_place`, `telephony.contacts_manage` (+ `ultron.approve`). Super Admin todas; Administrador solo `ultron.voice_manage` y `telephony.view` (tabla de D8-22). `telephony` en `SHARED_TOKEN_EXCLUDED_DOMAINS`.

**Variables nuevas (todas apagadas o vacías):** `VOICE_PUBLISHING_ENABLED`, `VOICE_LAB_ENABLED`, `VOICE_MONITOR_ENABLED`, `VOICE_OUTBOUND_RINGING_SECONDS=30`, `VOICE_OUTBOUND_HOURS_WEEKDAYS`, `VOICE_OUTBOUND_HOURS_SATURDAY`, `VOICE_FREQUENCY_PER_WEEK=2`, `VOICE_FREQUENCY_WEEK=rolling`, `VOICE_TRANSFER_HOURS_WEEKDAYS`, `VOICE_TRANSFER_HOURS_SATURDAY`, `VOICE_FACTS_SITE`, `VOICE_FACTS_HOURS`, `VOICE_RNE_VALIDITY_DAYS=0`, `QUEUE_VOICE_RETRY_AFTER=120`. Precios oficiales con fecha en `voice.prices` (sin variable). Migraciones: ninguna.

## 4. Decisiones pendientes del lead / SEC8 (las 4 guardas que caen)

1. **Sello incompleto:** `vc_stamp_chk` exige `studio_content_hash`, `voice_profile_hash` y `compiler_version`, que el mensaje de E1-4 no lleva. `VoicePublishedConfig` los lee en UNA sentencia con JOIN al agente de cada entrada y cruza agente, `spec_hash` y `agent_config_hash` = `payload_hash` (S8 debe confirmarlo). Cae `TelephonyIsolationTest::…canarios_y_su_cadena` y `UltronEngineIsolationTest::fuera_del_studio…`: SEC8 tiene que admitir el literal de la tabla de agentes.
2. **Falso positivo:** el motivo del contrato `voice_number_is_whatsapp` es un literal con «whatsapp» → `TelephonyIsolationTest::…dos_adaptadores`.
3. **Falso positivo:** el dominio `telephony` (D8-22) → `VoiceIsolationTest::…lectores_enumerados` ($bloques).
4. **E2-6:** `StudioVoiceEvidence` (S8) nombra `Telephony\VoiceBrake` → `TelephonyIsolationTest::…adaptadores_enumerados`. (Y el recuento 77≠56 de rutas del Studio es de S8.)
5. Fallo cerrado mientras se decide: `ELEVENLABS_VERIFIED` (la evidencia está en la tabla de instantáneas del Studio) → siempre `elevenlabs_unverified`; destinos de transferencia (perfil del Studio) → `solicitar_humano` no disponible y todo `transfer_to_number` es violación; deriva del NÚMERO en la conciliación (E2-3) necesita un GET que las interfaces cerradas no tienen (la iniciación ya la vigila por llamada, DF-E12); `usage_alert` no está en D8-13 (se guarda como `cost_recorded`, DF-W1); E3-4 supera 3 casos de `consent` del fixture (TEST8 debe regenerar).

## 5. Para tester (casos límite desde dentro)

Carrera de dos iniciaciones con el mismo `call_sid` (cerrojo + UNIQUE); webhook antes que el job (DF-S2, solo PG muestra los CHECK); `ambiguous` sin ids en la fila (el SID vive en su evento); cupo de herramientas 20/21; una saliente viva por número (`vc_one_live_outbound`); PG obligatorio (los CHECK de forma solo existen allí).

---

# §B8-b · tanda E4 (Enmienda 4: E4-1, E4-2, E4-7, E4-8, y lo pedido por LAB8 y S8-b)

**Fecha:** 08-11-2026. **Árbol:** el mismo, sobre `61513a1`, **sin commit**. Cero red: toda prueba con `Http::preventStrayRequests()`. No toqué las guardas de SEC8 ni ficheros de S8-b, LAB8 o pruebas del Studio (los cambios en `tests/Unit/Voice/VoiceIsolationTest.php`, `tests/Feature/Telephony/TelephonyIsolationTest.php` y `tests/Feature/UltronStudio/*` del árbol son de SEC8-b y del lead).

## 1. Cifras reales (comandos ejecutados)

- **SQLite**, todo el alcance (`tests/Unit/Voice tests/Unit/Twilio tests/Unit/Telephony tests/Feature/Voice tests/Feature/Telephony tests/Feature/Access tests/Feature/Chaos/ChaosLaneIsolationTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` + las 15 `tests/{Feature,Unit}/UltronStudio/*Voice*Test.php`) → **1 442 pruebas, 195 385 aserciones, 4 fallos** (las 4 guardas de §4; 204 omitidas: las de esquema solo-PG). Las del Studio de voz solas: 574, sin fallos.
- **Puerta PG** (`PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_b bash docs/ultron-studio/ops/pgunit.sh …`): primera pasada con telefonía, voz, Twilio, acceso y Studio de voz → **1 420 pruebas, 3 fallos** (las 3 guardas de §4 que están en esas carpetas) **y 1 error ajeno** (`AdminUserPasswordTest`, disparador con sintaxis de SQLite; no lo toqué). La final, tras el último cambio, en el §6.
- **Pruebas nuevas: 5 ficheros, 26 pruebas** (`VoiceEvidenceTest` 3, `VoiceTransferDestinationsTest` 11, `VoiceNumberDriftTest` 4, `ElevenLabsNumberObserverTest` 4, `VoiceLabPostCallTest` 4). Además, el caso del fixture `tools · solicitar_humano en horario del personal` ya se cumple tal cual (antes iba traducido como «pendiente»): **la paridad de `tools` es 21/21 sin traducciones de destinos**.
- **Mutaciones por muestreo** (copia aislada en el scratchpad con `vendor` clonado, una a la vez, sus pruebas en SQLite): **51 aplicadas, 47 muertas, 3 equivalentes y 1 hallazgo real corregido**. Equivalentes: E7 (filtro de fecha de los eventos de filas selladas: un evento no precede a su llamada), E14 (filtro `kind = open` del freno: una suelta lleva `reason` nulo por `vbr_shape_chk`) y D5 (sin `stamp_state = applied`: `vc_stamp_chk` deja sin parte ni agente toda fila no sellada). **D1 (real):** exigir además la misma entrada (`seq`) en el directorio de destinos sobraba y, en una vuelta atrás al mismo agente, marcaba como violación una transferencia legítima → ahora compara parte y agente (los destinos los fija el agente), con su prueba de vuelta atrás.
- `php artisan auth:coverage --unmapped` → vacío (exit 0; ninguna ruta nueva). Pint `--test` en mis 28 ficheros de esta tanda → `passed`.

## 2. Qué cambió y por qué (por fichero)

**E4-2, la evidencia.** `app/Services/Telephony/VoiceEvidence.php`: `forPublication(int $seq, string $part, DateTimeInterface $since): array` con exactamente `KEYS` = `calls, guardViolations, sealMismatches, driftEvents, unknownProviderCalls, budgetBreaches` (enteros ≥ 0). `calls` = selladas (`applied`) con esa parte y esa entrada desde `$since`, sin tramos, sin denegadas **y sin las salientes que nunca llegaron al agente** (`requested`, `canceled`, `failed_to_start`; §3.3). `guardViolations` y `sealMismatches` = eventos de TODAS las filas selladas con la entrada (también la denegada: un `seal_mismatch` en la iniciación la deja `denied`). `driftEvents` = aperturas del freno por `agent_drift` (incluye la deriva del número, §3.2); `unknownProviderCalls` = eventos `unknown_provider_call`; `budgetBreaches` = aperturas por `cost_cap` y `subaccount_alert`; los tres, de todo el sistema desde `$since`. Los recuentos ricos de antes pasan a `summary(string $part, int $seq)`; `app/Services/Telephony/VoiceCallsQuery.php` los publica en `GET admin/voice/metrics` (`publications`). Probado de punta a punta: `StudioVoiceEvidence::measure()` (S8-b) devuelve el array, no null.

**E4-1, una sola bandera de publicar.** `config/voice.php`: fuera el bloque `publishing`. `.env.example`: fuera `VOICE_PUBLISHING_ENABLED`. `app/Services/Telephony/VoiceTelephonyStatus.php` y `app/Console/Commands/Voice/VoiceDoctorCommand.php` ya no informan `publishing`. `VoiceProductionNoNetworkTest` afirma `config('ultron_studio.voice.publishing_enabled') === false` y que `voice.publishing` no existe.

**LAB8 (§2.3) y S8-b (§8.5).** `config/voice.php`, bloque `lab`: `driver` (`fake`), `daily_budget_usd` y `session_budget_usd` (`'0'`), `max_session_seconds` (60–300, 300) y `max_sessions_per_day` (0–50, 5), exactamente como los propone LAB8; `voice.prices.elevenlabs` (80 000 µUS$/min) ya existía. `.env.example`: las 5 `VOICE_LAB_*` y las 4 `ULTRON_STUDIO_VOICE_*` de S8-b. La llave `ultron.voice_lab_run` y las 6 rutas `voice-lab/*` ya estaban en `61513a1` con las llaves que pidió el lead (comprobado en `AuthorizationMap`).

**E4-7, los destinos de transferencia.** `app/Services/Telephony/VoicePublishedConfig.php`: la misma sentencia une el perfil del agente (`ultron_studio_voice_profiles`), cruza que es el perfil FIRMADO en la entrada y el que sella el agente (`content_hash` = `voice_profile_hash`; si no, la parte es ilegible, `agent_mismatch`), descifra `transfer_destinations_encrypted` con la clave de la aplicación (`Crypt::decryptString`, el cast `encrypted:array` del Studio) y deja SOLO alias y huella; un perfil sin aprobar, un cifrado que no abre, una lista que no es la que dice `transfer_count` o un destino sin forma → destinos null (ninguno vale; la parte sigue vigente). `VoicePublishedPart.php`: `transferDestinations` (`list<{alias, hash}>|null`), `transferHashes()`, `transferAliases()`. `VoiceTransferDirectory.php`: por LLAMADA, los de la parte de su sello si la parte apunta al mismo agente (`approvedHashesFor`, `aliasesFor`, `countsByPart`). `VoicePostCall.php` (la guarda) y `VoiceTools.php` (`solicitar_humano`) los usan. Probado: aprobado = sin violación ni freno; ajeno = `voice_transfer_not_approved` y freno.

**E4-8, la deriva del número.** Nuevos `app/Services/Voice/Dto/VoiceNumberAssignment.php` (resultado, id del número, agente asignado, estado `assigned | unassigned | unknown`; nunca el teléfono), `app/Services/Voice/VoiceNumberObserver.php` (`describeNumber(string $phoneNumberId): VoiceNumberAssignment`) y `app/Services/Voice/NullVoiceNumberObserver.php` («desconocido»). `ElevenLabsAgentDeployer.php` la implementa con UN `GET /v1/convai/phone-numbers/{id}` (mismas reglas que `describeAgent`: sin red si no está configurado, sin redirecciones ni reintentos, la clave solo en `xi-api-key`, fallos como resultados). `VoiceAdapters::numberObserver()`. `VoiceReconciler::numbers()`: el número frente al agente de la parte `canary` (sin entrada o apagada, ninguno); si difieren, `VoiceBrake::open('number_drift', 'number:{id}:{agente|none}', …)` e incidente. `voice:reconcile --numbers` (exit 1 con deriva). `VoiceBrake.php`: `number_drift` → `agent_drift` (§3.2).

**LAB8 §2.6, el informe post-llamada del laboratorio.** `VoicePostCall.php`: una transcripción aceptada, con conversación, SIN llamada de Twilio y de un agente que no es el de ninguna parte publicada se entrega con el evento con nombre `voice.lab.post_call` (`[VoiceWebhookVerdict, list<{turn, code}>]`, la guarda posterior sin destinos). Solo si el laboratorio responde `recorded | duplicate | ignored` deja de ser `unknown_provider_call`; sin oyente, con `not_a_lab_session`, con `call_sid` o con el agente publicado, evento y freno como siempre. La telefonía no nombra el Studio y el laboratorio no escribe en `voice_*`. Probado con el oyente real (`[StudioVoiceLabSessions::class, 'recordProviderReport']`): la firma casa con la carga.

**Pruebas cambiadas:** `tests/Feature/Telephony/Support/VoiceFixtures.php` (`publishVoice` con destinos cifrados como el Studio, estado del perfil, columnas forzadas, perfil firmado distinto y vuelta atrás a un agente existente), `VoiceToolsTest.php` (destinos reales y un miércoles de Bogotá fijo: el personal no atiende en domingo), `TelephonyFixtureParityTest.php` (los seis motivos únicos + `number_drift`), `ElevenLabsConfigTest.php` (sin `publishing`; los `lab.*` y sus recortes), `VoiceProductionNoNetworkTest.php`.

## 3. Decisiones tomadas en esta tanda (para confirmar)

1. **`describeNumber` en una interfaz SEGREGADA (`VoiceNumberObserver`), no en `VoiceAgentDeployer`.** E4-8 dice «la interfaz de observación gana el método»; añadirlo a `VoiceAgentDeployer` deja a `tests/Feature/UltronStudio/FakeVoiceDeployer.php` (de S8, fuera de mi alcance) con un método abstracto sin implementar: error fatal que tumba la suite. Es el patrón que B8-a ya usó con el desplegador frente al puerto del H6. Si el lead lo quiere en `VoiceAgentDeployer`: una línea aquí y tres en `FakeVoiceDeployer` (S8).
2. **`number_drift` se guarda como `agent_drift`.** `vbr_reason_chk` (DB8) solo admite los seis motivos de D8-19; la deriva del número es la del agente que atiende. La evidencia lleva `trigger: number_drift` y la clave `number:{id}:{agente}`. Un motivo propio sería una migración aditiva de DB8.
3. **`calls` también excluye `requested`, `canceled` y `failed_to_start`** (nunca llegaron al agente; DB8 les prohíbe ids del proveedor). Es más estricto que la letra de E4-2 («sin tramos ni denegadas»), en el sentido seguro; en las fases 1 y 2 no hay salientes, así que hoy no cambia nada.
4. **El alias del destino sale junto a la huella.** E4-7 dice «solo las huellas»; el fixture `tools · solicitar_humano` (D8-14) exige además el alias («disponible y el alias, nunca el número»). El número se descifra y se descarta en memoria; el alias ya está en claro en el `content` del perfil.
5. **Los destinos los fija el agente**, no la entrada (hallazgo D1): una llamada conserva los del agente de su sello mientras su parte apunte a él.
6. **`--numbers` es aditivo** (la conciliación de llamadas sigue corriendo). Si OPS8 lo entendiera como «solo números» y programara solo eso, no se perdería la detección de llamadas desconocidas.
7. **El oyente del laboratorio no está registrado.** Dónde registrar `Event::listen('voice.lab.post_call', [StudioVoiceLabSessions::class, 'recordProviderReport'])` lo deciden el lead y SEC8 (`UltronEngineIsolationTest::ALLOWED` o un fichero del Studio que cargue al arrancar). Hasta entonces, con el laboratorio en `elevenlabs`, **cada sesión real abre el freno** al llegar su informe (el riesgo que ya señaló LAB8 §6.1).
8. **Persistencia de una deriva tras soltar el freno:** la misma deriva no lo reabre (misma `open_key`, como la del agente); reasignar el número a otro agente sí.

## 4. Guardas que caen a propósito (SEC8-b debe enumerarlas)

1. `VoiceIsolationTest::test_estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato`: `VoiceNumberObserver.php` en `PAQUETE_H8`, y `'VoiceNumberObserver' => ['describeNumber']` en `INTERFACES` (E4-8).
2. `VoiceIsolationTest::test_la_red_solo_sale_de_los_metodos_enumerados`: `self::DESPLEGADOR => [… 'describeNumber' => ['get']]` en `RED` y `DESPLEGADOR::describeNumber` en `UNA_PETICION` (E4-8).
3. `TelephonyIsolationTest::test_del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`: el literal `'ultron_studio_voice_profiles'` en `VoicePublishedConfig` (E4-7: «JOIN al perfil»). **Ojo:** la guarda que SEC8-b tiene ahora en el árbol dice «ninguna otra tabla del Studio (tampoco los perfiles)»; E4-7 la contradice.
4. `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion`: el mismo tercer literal en `VOICE_READER_REFERENCES`.

Ya pasan con los cambios de SEC8-b en el árbol: la tabla de agentes (E4-3), `voice_number_is_whatsapp` y el dominio `telephony` (E4-4), y `StudioVoiceEvidence` nombrando la telefonía (Anexo C).

## 5. Contratos nuevos o modificados (para FE8, S8-b, LAB8, OPS8)

- `GET api/admin/voice/status`: sin `flags.publishing` (E4-1); **`transferDirectory` pasa de cadena (`available | unavailable`) a `{canary: int|null, rest: int|null}`** (destinos aprobados de la entrada vigente; null = ilegibles o sin entrada). El CRM no lo usa hoy (búsqueda en `frontend/src`).
- `GET api/admin/voice/metrics`: añade `publications: {canary, rest}` (el `summary()` de la entrada vigente, o `{part, status}`).
- `voice:reconcile --numbers` (OPS8-b lo programa): añade `number: {result: ok | drift | not_configured | expected_unknown | unobserved_<resultado>, observed, expected, seq, outcome, brakeOpened}`.
- `voice:doctor --telefonia`: los destinos, por parte.
- Evento `voice.lab.post_call` (§2).
- Variables: **fuera** `VOICE_PUBLISHING_ENABLED`; **nuevas** `VOICE_LAB_DRIVER=fake`, `VOICE_LAB_DAILY_BUDGET_USD=0`, `VOICE_LAB_SESSION_BUDGET_USD=0`, `VOICE_LAB_MAX_SESSION_SECONDS=300`, `VOICE_LAB_MAX_SESSIONS_PER_DAY=5`, y las de S8-b `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false`, `ULTRON_STUDIO_VOICE_LAB_GATE=required`, `ULTRON_STUDIO_VOICE_CANARY_MIN_HOURS=24`, `ULTRON_STUDIO_VOICE_CANARY_MIN_CALLS=3`. Migraciones: ninguna.

## 6. Puerta PG final y lo que queda

- PG final, tras la corrección D1 (`tests/Feature/Telephony tests/Unit/Telephony tests/Unit/Voice tests/Feature/Voice` + `tests/Feature/UltronStudio/*Voice*Test.php`) → **778 pruebas, 185 648 aserciones, 3 fallos** (las guardas 1–3 de §4; la 4 no está en esas carpetas) y ningún error.
- Sigue fallando cerrado (E4-6, antes de la fase 3): `ELEVENLABS_VERIFIED` → `elevenlabs_unverified`.
- **Para tester:** una vuelta atrás con una llamada en curso que transfiere (ahora vale); una deriva del número que persiste tras soltar el freno (no reabre); una conversación web de un agente publicado (abre el freno); `ignored` del laboratorio solo es seguro con las precondiciones de `labReport()`; un perfil con 3 destinos y uno ilegible (ninguno vale).

---

# §B8-b · punto 6: el job repite el destino y el calendario (revisión de seguridad, MEDIO, TOCTOU)

**Hallazgo** (revisión automática sobre `61513a1`): `PlaceOutboundVoiceCall::recheck()` volvía a mirar banderas, freno, `publication_moved` y deriva, pero no lo que puede cambiar entre encolar y marcar. No era explotable (salientes cerradas por la constante), pero se cierra ahora.

**Qué cambió:**
- `app/Services/Telephony/OutboundPolicy.php`: los bloques 3 (destino) y 4 (calendario) de `decide()` pasan a `targetReasons()` (pura, con `TARGET_REASONS`), y `decide()` la llama. La puerta y el job aplican el MISMO código; la paridad con los 43 casos de `decide` sigue en verde.
- `app/Services/Telephony/OutboundGate.php`: `targetFacts()` (exclusión del número —y, para el job, también de la huella SELLADA—, oral, del lead y RNE marcado; consentimiento; vigencia del RNE; ventana legal y propia con festivos, a `$now`), `targetOf(VoiceCall)` (el destino sellado de una fila; si ya no existe, un destino sin autorizar) y `currentScope()`. La puerta usa los mismos `targetFacts()`.
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`: paso 5 nuevo de DF-G9, justo antes de construir la petición (tras la deriva): si `targetReasons()` da algún motivo, cancela con el primero (orden de `REASONS`), sin petición, sin evento (como las demás cancelaciones del job, fixture §job) y con la reserva liberada. Los pasos 1–5 tras la constante quedan en `recheckAfterCode()`, que solo llama `recheck()`, para poder recorrerlos en el árbol compartido.
- Además de lo pedido, y por la misma regla: un canario que dejó de estar activo da ahora `not_an_active_voice_canary` (antes, `error` al construir la petición) y un alcance estrechado a `canary` con un contacto en la cola, también. Lo uno y lo otro ya cancelaba; ahora con el motivo de la puerta.

**Pruebas:** `tests/Feature/Telephony/PlaceOutboundVoiceCallRecheckTest.php`, **7 pruebas**: todo en regla (sin motivo, y `run()` sigue cancelando primero por la constante), consentimiento revocado tras encolar (`no_voice_consent`), exclusión añadida tras encolar por número de canario y de contacto y por la huella sellada (`excluded`), RNE que caduca (`rne_check_missing`, también con vigencia 0), fuera de la ventana legal, fuera de la propia y festivo (`outside_hours`, `holiday`), alcance estrechado (`not_an_active_voice_canary`) y la cancelación (`canceled`, su motivo, `ended_at`, sin evento, `committedMicros` a 0, sin red). `VoiceFixtures`: una llamada a contacto lleva `canary_number_uuid` nulo y una entrada `rest` se siembra como `promote` (PG cazó las dos con `vc_target_chk` y `usvpub_shape_chk`).

**Cifras:** SQLite (voz, Twilio, telefonía, acceso, carriles y guardas) 875 pruebas, solo las 4 guardas de E4; PG `tests/Feature/Telephony tests/Unit/Telephony` 326 pruebas, solo la guarda 3 de E4 (`TelephonyIsolationTest`); mutantes del cambio 12/12 muertos (quitar el paso 5, ignorar la huella sellada, evaluar a la hora de encolar, fijar el alcance, devolver null, el destino del canario, consentimiento, RNE, horario propio, la regla de festivo y la llamada desde `decide()`); Pint `passed`.

---

# §B8-b · Enmienda 5 (E5-1, E5-2, E5-4) y cierre del conjunto

**Fecha:** 08-11-2026. **Árbol:** el mismo, sobre `589e6c6`, **sin commit**. Lo que sigue manda sobre lo dicho de E4-7 en las secciones anteriores.

**Decisiones confirmadas por el lead:** (1) `VoiceNumberObserver` como interfaz propia (E5-2); (2) la deriva del número se guarda como `agent_drift` con `trigger: number_drift`; (3) `calls` excluye también `requested`, `canceled` y `failed_to_start`; (4) cada destino es `{alias, hash}`. La decisión sobre `--numbers` está completa en la §3.6 de la tanda E4: es aditivo (la conciliación de llamadas sigue corriendo).

## 1. E5-1: los destinos aprobados son los compilados en el agente

- `app/Services/Telephony/VoicePublishedConfig.php`: **fuera** el JOIN a `ultron_studio_voice_profiles`, el descifrado (`Crypt`) y los cruces del perfil (`vp_uuid`, `content_hash`). Lee `a.transfer_destination_hashes` de la fila del agente que ya une (E4-3: dos tablas). Forma EXACTA: lista JSON de como mucho 3 `{alias, hash}` (alias 1–60 caracteres; `hash` hex de 64; las claves en cualquier orden, como las devuelve un `jsonb`). Sin escribir (null) o con otra forma → ningún destino aprobado (toda transferencia es violación; `solicitar_humano` no ofrece a nadie), sin tocar el resto de la parte.
- `VoicePublishedPart.php`: `transferDestinationHashes()` (antes `transferHashes()`), `transferAliases()` y la propiedad `transferDestinations`. `VoiceTransferDirectory.php` compara huellas igual que antes (por llamada, con el agente de su sello).
- Ya pasan las guardas `TelephonyIsolationTest::…canarios_y_su_cadena` y `UltronEngineIsolationTest::…nadie_nombra…` (las 3 y 4 de la §4 de E4 ya no aplican).
- **Dependencia de orden con DB8 y S8-c.** La columna todavía no está en la migración `2026_11_10_100001`. Hasta que entre:
  - en el árbol compartido caen **3 pruebas de S8** que leen con `VoicePublishedConfig` (`UltronStudioVoicePublishingTest`: publicar, revertir, apagar): la consulta falla y el lector da la cadena por ilegible (falla cerrado);
  - mis pruebas crean la columna si falta, dentro de la transacción de cada prueba (`VoiceFixtures::ensureCompiledDestinationsColumn()`); con la migración dentro no hace nada y se quita.
  - **Verificado en copia aislada** con la columna simulada (`json` nulo, en `AGENT_IMMUTABLE_COLUMNS`): SQLite 446 pruebas (publicación de S8 + telefonía + esquema), solo cae el inventario de columnas de DB8 (`UltronStudioVoiceSchemaTest::test_columnas_tipos_y_nulos_de_las_cuatro_tablas`, que DB8 actualiza con la columna); PG 59 pruebas (publicación de S8, destinos, herramientas y límites), en verde.
  - No puse ninguna comprobación del esquema en tiempo de ejecución: esa migración no ha corrido en ningún entorno y entrará junto con este lector.
- **Para DB8:** `$table->json('transfer_destination_hashes')->nullable()` en `ultron_studio_voice_agents`, en `AGENT_IMMUTABLE_COLUMNS` (se compila al crear el agente y no cambia) y en sus inventarios. **Para S8-c:** escribirla al INSERTAR el agente, exactamente `[{"alias": …, "hash": …}]` (el alias del destino y la huella `voice-phone` de su E.164); `[]` si no hay destinos.

## 2. E5-2: lo que SEC8 tiene que enumerar

- `app/Services/Voice/VoiceNumberObserver.php`: interfaz, un método `describeNumber(string $phoneNumberId): VoiceNumberAssignment`.
- `app/Services/Voice/NullVoiceNumberObserver.php`: el nulo («desconocido», `not_configured`, sin red).
- `app/Services/Voice/Dto/VoiceNumberAssignment.php`: el DTO (resultado, id del número, agente asignado, estado `assigned | unassigned | unknown`; nunca el teléfono).
- `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php::describeNumber`: **UNA** petición `GET {base}/v1/convai/phone-numbers/{phone_number_id}` con `Http::withHeaders(['xi-api-key' => …])->acceptJson()->withoutRedirecting()->connectTimeout(min(5, t))->timeout(t)->get(…)`; sin reintentos; base de `ElevenLabsConfig::baseUrl()` (la oficial en producción); sin configurar, sin red.
- La fábrica `VoiceAdapters::numberObserver()`. **Único que la llama:** `VoiceReconciler::numbers()` (desde `voice:reconcile --numbers`).
- Líneas para `VoiceIsolationTest`: `PAQUETE_H8` += `VoiceNumberObserver.php`; `INTERFACES` += `'VoiceNumberObserver' => ['describeNumber']`; `RED[DESPLEGADOR]` += `'describeNumber' => ['get']`; `UNA_PETICION` += `DESPLEGADOR.'::describeNumber'`.

## 3. E5-4 (M-1): límites después de autenticar

- `routes/voice.php`: fuera `throttle:240,1`, `throttle:120,1`, `throttle:600,1` y `throttle:60,1` de las cuatro rutas públicas, que compartían el cubo `sha1(dominio|IP)`. Siguen sus topes de cuerpo. Las del CRM conservan sus limitadores (van por sesión).
- `app/Services/Telephony/VoiceRateLimits.php` (nuevo), con el limitador de Laravel (la caché de la aplicación, como `throttle`):
  - **Autenticada**, por ruta y por llamada: iniciación por `call_sid` (10/min); post-llamada por evento, tipo + conversación (10/min); herramientas por llamada, `conversation_id`, `call_sid` o `call_ref` (60/min, además del tope de 20 de la base); aviso de Twilio por `IdempotencyToken` (10/min). Sin llamada que la identifique, el cubo «sin llamada» de su ruta. La clave guarda la huella de la llamada, nunca el valor.
  - **Fallo de autenticación**: cubo propio `ruta|IP|fallo`, 30/min; pasado, 429 sin registrar nada. Una credencial buena nunca lo consulta.
  - Pasado un cubo: 429 con `Retry-After` y `{ok: false, code: too_many_requests}`.
- Los cuatro controladores de `app/Http/Controllers/Api/Voice/`: primero la credencial; si falla, su cubo (401 o 429); si vale, el límite por llamada (429) y después el trabajo. En el post-llamada, un sobre válido puede recibir 429 temporal si el MISMO evento pasa de 10 por minuto (una repetición o un reenvío en bucle).
- **Pruebas:** `VoicePublicRateLimitsTest`, 5: una credencial mala no gasta el presupuesto de la buena en ninguna de las 4 rutas (todo desde la misma IP); el cubo de fallos es de su ruta y de su IP; cada llamada y cada ruta tienen su contador (también el «sin llamada»); herramientas por llamada; y ya no hay contador por IP común (125 iniciaciones buenas desde una IP, más que el antiguo 120). `TelephonyRoutesTest`: las públicas, sin `throttle`; las del CRM, con.
- **Guarda que cae a propósito:** `VoiceBoundaryTest::test_en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos` (SEC8-a) exige `throttle` en toda ruta de voz («sin limitador (D8-16)»); E5-4 lo quita en las cuatro públicas. SEC8 debe aceptar ahí que el controlador limite con `VoiceRateLimits`.

## 4. Cifras del conjunto (comandos ejecutados)

- **SQLite**, todo el alcance (voz, Twilio, telefonía, acceso, carriles, `UltronEngineIsolationTest` y las 15 `*Voice*Test` del Studio): **1 462 pruebas, 6 fallos**: las 2 guardas de E5-2 (`VoiceIsolationTest`), la de E5-4 (`VoiceBoundaryTest`) y las 3 de S8 que esperan la columna de DB8; 204 omitidas (solo PG).
- **PG** (`tests/Feature/Telephony tests/Unit/Telephony tests/Unit/Voice tests/Feature/Voice`): **622 pruebas, 3 fallos** (las 3 guardas) y ningún error.
- **Mutaciones** de esta tanda: 18 aplicadas y 18 muertas (7 del lector de E5-1 y 11 de los límites; una, la ruta en la clave de una petición autenticada, solo murió tras añadir la prueba del cubo «sin llamada» por ruta). Punto 6: 12/12. Tanda E4: 51 aplicadas, 47 muertas, 3 equivalentes y 1 hallazgo real corregido.
- `auth:coverage --unmapped` vacío; Pint `passed`.

## 5. Ficheros de esta tanda

`app/Services/Telephony/{VoicePublishedConfig,VoicePublishedPart,VoiceTransferDirectory}.php`; `app/Services/Telephony/VoiceRateLimits.php` (nuevo); `app/Http/Controllers/Api/Voice/{ElevenLabsInitiationController,ElevenLabsPostCallController,VoiceToolController,TwilioVoiceUsageTriggerController}.php`; `routes/voice.php`; `tests/Feature/Telephony/{Support/VoiceFixtures,VoiceTransferDestinationsTest,TelephonyRoutesTest,VoiceProductionNoNetworkTest}.php`; `tests/Feature/Telephony/VoicePublicRateLimitsTest.php` (nuevo); este informe. Sin rutas, permisos, variables ni migraciones nuevas.

---

# §B8-c · correcciones del paso 3 (Enmienda 8: E8-1, E8-4, E8-5, E8-6; y E7-2)

**Fecha:** 08-11-2026. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h8-voice`. Empecé sobre `c01124c`; mientras trabajaba, OPS8-b confirmó `2b3c2936` y `af6bd805` (no tocan nada mío). Todo lo de abajo se midió por última vez sobre **`af6bd805` + mis cambios, sin commit**. Cero red: toda prueba con `Http::preventStrayRequests()`. No toqué las guardas de SEC8, ni `routes/console.php`, ni `ops/**`, ni el E2E, ni el CRM.

## 1. Cifras reales (comandos ejecutados)

| Qué | Comando | Resultado |
|---|---|---|
| Voz, telefonía y Twilio, SQLite | `phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio` | **751 pruebas, 195 456 aserciones, 2 fallos** (§4: 1 y 3), 146 omitidas (solo PG) |
| Studio de voz, laboratorio y guarda del motor, SQLite | `phpunit tests/Feature/UltronStudio/UltronStudioVoice*Test.php tests/Unit/UltronStudio/*Voice*Test.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **588 pruebas, 6 460 aserciones, 1 fallo** (§4: 2), 73 omitidas |
| Todo lo anterior, puerta PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bc bash docs/ultron-studio/ops/pgunit.sh …` | **1 339 pruebas, 203 068 aserciones, 3 fallos** (los mismos 3 de §4), 15 omitidas |
| Lo que lee el fichero de ejemplo de entorno y el tope global | `SecretExposureTest`, `UltronStudioMonitorTest`, `UltronStudioIntegrationsConfigTest`, `UltronStudioPublicationConfigTest`, `UltronStudioIntegrationsNoNetworkTest`, `UltronStudioEarlyBodyCapTest` | 66 OK |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, exit 0 (ninguna ruta nueva) |
| Estilo | `pint --test` en mis 18 ficheros | `passed` salvo `AppServiceProvider.php`, que **ya falla igual en HEAD** (orden de imports y dos nombres cualificados del limitador de traslados; comprobado sobre `git show HEAD:…` en copia). No lo reformateé: ninguna de esas líneas es mía |
| Mutaciones por muestreo | copia aislada en el scratchpad (`vendor` clonado), una a la vez, `php -l`, sus pruebas, restaurar y comparar sha256 | **30 aplicadas, 30 muertas**, 0 vivas, 0 equivalentes (F-1 3, P8-1 10, P8-2 4, P8-3 5, E7-2 8) |

**Cada prueba de regresión falla sin su arreglo** (ejecutado):
- F-1: `VoiceLabPostCallWiringTest` sobre el árbol antes de registrar el oyente → 2 fallos: ningún oyente registrado y `{"ok":true,"unknown":true}` en vez de `{"ok":true,"lab":"recorded"}` (el síntoma exacto de TEST8-b).
- P8-1 y P8-2: `VoiceCrmQueryBudgetTest` sobre el código anterior → 4 fallos (sin índice; `summary` 10 sentencias; `status` 31; contactos 52). Con los topes anulados (copia en el scratchpad), **todos los valores esperados coinciden con lo que daba el código anterior**: la prueba fija los resultados de antes y los recuentos de ahora.
- E7-2: las 3 pruebas nuevas de `VoicePublicRateLimitsTest` contra `VoiceRateLimits` y `config/voice.php` de HEAD (copia) → 1 error y 2 fallos (200 en vez de 429; ninguna línea de silenciados).
- P8-3: el comando no existía; la prueba comprueba además el síntoma (las filas caducadas siguen en `cache` hasta purgarlas).

**Recuentos de sentencias** (SQLite, caché `array` de la suite; PERF8 midió con la caché `database`, por eso sus cifras son mayores):

| Lectura | Antes | Ahora |
|---|---|---|
| `VoiceEvidence::summary()` de una parte | 10 (6 de `voice_calls`) | **4** (1 de `voice_calls`); 3 si le pasan el freno |
| `VoiceTelephonyStatus::snapshot()` | 31 (cadena ×3, freno ×4, 12 recorridos de `voice_calls` por entrada) | **14** (cadena ×1, freno ×1, 2 agregaciones) |
| `GET admin/voice/status` / `metrics` (pila HTTP) | 33 / 30 | **16 / 16** |
| `GET admin/voice/contacts` con 25 / 100 filas | 52 / 202 | **4 / 4** |

**El índice con volumen** (copia por plantilla de `ultron_studio_h8_perf_seed` de PERF8, 5 101 llamadas y 20 000 eventos, en mi base `ultron_studio_h8_bc_perf`; `EXPLAIN (ANALYZE, BUFFERS)`): sin el índice, cada lectura de una entrada es `Seq Scan` con **880 búferes** (y había 6 por parte); con `vc_publication_idx`, la agregación es `Bitmap Index Scan on vc_publication_idx` con **38 búferes**, y `forPublication()` (la evidencia del Studio) también lo usa. Los eventos van por `vce_kind_idx` (478 búferes); sin el filtro por `kind`, el planificador recorre los 20 000 eventos: el filtro se queda. **Sin medir:** tiempos (p50/p95) y la caché `database`; es la pasada de tiempos de PERF8.

## 2. Qué cambió y por qué (por fichero)

**E8-1 (TEST8-b F-1), el oyente del laboratorio.**
- `app/Providers/AppServiceProvider.php`: `boot()` llama a `oyenteDelLaboratorioHablado()`, que registra `Event::listen(VoicePostCall::LAB_EVENT, [StudioVoiceLabSessions::class, 'recordProviderReport'])`. El Studio escucha a la telefonía; la telefonía sigue sin nombrar el Studio. En el arranque vale en la web, las colas y la consola, también con rutas y configuración en caché (por eso no en `routes/ultron-studio.php`: producción cachea rutas).
- `tests/Feature/Telephony/VoiceLabPostCallWiringTest.php` (nuevo, 3): el oyente registrado al arrancar, UNA vez; una sesión real abierta por la API del Studio (ElevenLabs simulado sobre `Http::fake`), su post-llamada web FIRMADA por la ruta pública → `{"ok":true,"lab":"recorded"}`, 0 `voice_call_events`, 0 `voice_brakes`, la sesión `ended` / `provider_report` con 90 s y el coste prorrateado (150 000 de 300 000); la reentrega es `duplicate` sin evento ni freno; y un agente ajeno con el oyente real sigue echando el freno. Nada conectado a mano.
- `tests/Feature/Telephony/VoiceLabPostCallTest.php`: deja de conectar el oyente a mano (con el de arranque quedaría registrado dos veces). Las tres pruebas que necesitan OTRO oyente (o ninguno) lo quitan antes con `Event::forget`; la cuarta comprueba ahora el de arranque.

**E8-4 (PERF8 P8-1).**
- `database/migrations/2026_11_10_100002_create_voice_tables.php`: **editada en sitio** (nunca ha corrido en ningún entorno; anotado en la cabecera): índice NO parcial `vc_publication_idx (voice_publication_seq, part)` en `voice_calls`. E3-5 no cambia (27 / 51 / 3 / 7): lo confirman en PG `VoiceSchemaTest` (recuentos y nombres de parciales) y las idas y vueltas por huella del esquema. Ninguna prueba de DB8 enumeraba los índices no parciales: no hubo que ajustar ninguna.
- `app/Services/Telephony/VoiceEvidence.php`: `summary(string $part, int $seq, ?bool $brakeOpen = null)`: UNA agregación (`count(*)`, `count(CASE …)` de completadas y verificadas, `sum`, `min`, `max`), los eventos en UNA sentencia con semi-join (sin traer los ids a PHP) y las desconocidas desde la primera llamada; sin llamadas, ni eventos ni desconocidas. Mismo SQL en los dos motores. La salida es la misma, clave a clave.
- `app/Services/Telephony/VoiceCallsQuery.php`: `metrics(?string $budgetDay = null, ?array $parts = null, ?bool $brakeOpen = null)`: lee el freno una vez y se lo pasa a cada `summary()`; usa la cadena si se la pasan.
- `app/Services/Telephony/VoiceTransferDirectory.php`: `countsByPart(?array $parts = null)`.
- `app/Services/Telephony/VoiceTelephonyStatus.php`: `snapshot()` lee la cadena y el freno UNA vez y los pasa a `metrics()` y a `countsByPart()`.

**E8-5 (PERF8 P8-2).**
- `app/Services/Telephony/VoiceContacts.php`: `list()` carga los consentimientos de la página con un `whereIn('contact_id')` y evalúa `VoiceConsentRule` en memoria con los mismos eventos; `row()` y `hasConsent()` (las de una fila, las que usa la política dentro de su cerrojo) no cambian de comportamiento; la fila se arma en un `present()` común.
- `app/Services/Telephony/VoiceCallFacts.php`: `excludedAmong(list<string>)`, el `excluded()` de una página en un `whereIn('phone_hash')`.

**E8-6 (PERF8 P8-3).**
- `app/Console/Commands/Voice/VoicePruneRateLimitsCommand.php` (nuevo): `voice:prune-rate-limits {--dry-run} {--json}`. Borra SOLO las claves `voice-rl:*` caducadas; registra `voice.rate_limits.pruned` (almacén y recuentos) en el canal `voice`. Sale con 0.
- `app/Support/Cache/ExpiredCachePruner.php` (nuevo, genérico): resuelve el almacén del limitador como `CacheServiceProvider` (`cache.limiter` o el de por defecto); si no es `database`, no hace nada. Borra filas con `expiration <= ahora` (lo mismo que `forgetIfExpired`) y clave que empieza EXACTAMENTE por el prefijo del almacén + el dado (LIKE con `ESCAPE '!'`: un `%` o `_` del prefijo no es comodín), en DELETE de 1 000 (como mucho 1 000 tandas por ejecución). Sin prefijo, se niega.
- `app/Services/Telephony/VoiceRateLimits.php`: `PREFIX` pasa a público (el comando lo necesita).
- `tests/Feature/Telephony/VoicePruneRateLimitsCommandTest.php` (nuevo, 4): claves escritas por el limitador REAL sobre la caché `database` con un prefijo de almacén con `_` y `%`; en seco solo cuenta; de verdad borra exactamente las caducadas de la voz y se quedan las vivas (el contador de silenciados, el techo global de una llamada nueva), una clave ajena caducada, un prefijo parecido (`voice-rlx:`) y una que solo casaría con los comodines sin escapar; el limitador sigue contando bien después; un atraso de 2 500 filas en 3 tandas; con el almacén `array`, nada; sin prefijo, excepción.

**E7-2 (SEC8-b-lite, notas residuales 1 y 3).**
- `app/Services/Telephony/VoiceRateLimits.php`: `authenticated()` comprueba el techo GLOBAL de la ruta (`voice-rl:<ruta>|global`) y el de la llamada, y solo si pasan los dos cuenta en los dos (una petición frenada por uno no gasta ninguno). `globalPerMinute()` lee `voice.public_limits.global_per_minute` y lo recorta entre el cupo por llamada y 10 veces el defecto. `failure()` con el cubo agotado llama a `silenced()`: cuenta el fallo silenciado y, como mucho una vez por minuto y ruta, registra `voice.public.failures_silenced` con `{route, silenced}` (los silenciados desde la línea anterior, este incluido); ni IP, ni credencial, ni cuerpo. Los controladores no cambian (el orden que vigila `VoiceBoundaryTest` sigue igual y la guarda pasa).
- `config/voice.php`: bloque `public_limits.global_per_minute` (`initiation` 120, `post_call` 240, `tools` 600, `usage_trigger` 60: los `throttle` por IP que quitó E5-4); `.env.example`: sus 4 variables.
- `tests/Feature/Telephony/VoicePublicRateLimitsTest.php`: 3 pruebas nuevas (el techo de cada una de las 4 rutas entre llamadas distintas, que 35 peticiones con credencial mala no gastan; frenada por su llamada no gasta el global; la línea de silenciados: una al silenciar el primero, una por ruta, la siguiente al minuto con 5, ninguna con la credencial buena, sin la IP en ninguna línea del canal). La prueba que ya existía de 125 iniciaciones buenas desde una IP (`test_ya_no_hay_un_contador_por_ip_comun_a_las_rutas`) sube el techo de la iniciación a 200: mira solo que no hay cubo por IP; el techo global tiene sus pruebas.
- `tests/Unit/Voice/ElevenLabsConfigTest.php`: el bloque nuevo en los defectos y sus recortes.

## 3. Decisiones tomadas (para confirmar)

1. **El oyente en `AppServiceProvider::boot()`**, no en un proveedor propio: es el sitio que documenta Laravel 12 para `Event::listen`, es donde el repo ya engancha los observadores y los limitadores del Studio, y no cambia la lista de proveedores (un proveedor nuevo obliga a recompilar `bootstrap/cache/services.php` en el primer arranque tras desplegar; con `umask 077` y un `artisan` como root, FPM dejaría de leerlo). Cuesta una excepción exacta en `UltronEngineIsolationTest` (§4).
2. **`count(CASE …)` en vez de `FILTER`:** el mismo SQL en SQLite y en PG, para que la suite SQLite ejecute exactamente el de producción (lo de SQLite que no ve lo que PG rechaza). Cuenta igual y da 0, no NULL, sin filas.
3. **La purga genérica fuera de la voz:** la telefonía solo puede leer `voice.*`/`telephony.*` (`TelephonyIsolationTest::test_la_voz_solo_lee_su_configuracion_y_nada_del_entorno`) y saber dónde vive la caché es leer `cache.*`. La parte de caché va en `app/Support/Cache/` (no es de ningún dominio y no lee secretos); el comando de voz solo aporta su prefijo. Si el lead prefiere el comando autocontenido en `Commands/Voice/`, cae esa guarda y SEC8 tendría que admitir `cache.*` allí.
4. **Defectos del techo global = los `throttle` por IP de antes** (120/240/600/60), holgados frente al tráfico real (≤ 4 a la vez, ≤ 20 entrantes al día); recorte entre el cupo por llamada (un techo menor frenaría a una sola llamada antes que su cupo) y 10 veces el defecto. Son techos: no encienden nada (`test_todo_apagado_por_defecto` pasa).
5. **N de la línea de silenciados = los silenciados desde la línea anterior.** El contador vive 1 h. Límite conocido: los silenciados después de la última línea no se cuentan hasta el siguiente silenciado (si la inundación para, la cola no se registra); la primera línea de cada episodio dice 1.

## 4. Guardas que caen (para SEC8, por el lead)

1. **`VoiceBoundaryTest::test_los_comandos_de_voz_son_los_del_contrato_y_por_defecto_ninguno_esta_programado`** (mía, E8-6): `COMANDOS` += `'voice:prune-rate-limits'`. La segunda mitad de esa prueba (con las banderas por defecto no se programa ningún `voice:*`) seguirá pasando si OPS8-b lo agenda dentro del bloque `voice.monitor.enabled`, como dice E8-6.
2. **`UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion`** (mía, E8-1): `app/Providers/AppServiceProvider.php` nombra exactamente `T_NAME_QUALIFIED App\Services\UltronStudio\StudioVoiceLabSessions`, una vez (el `use`). Propuesta: una excepción por fichero y referencia exacta, como la del lector de voz (`VOICE_READER`/`VOICE_READER_REFERENCES`), y si se quiere, que la única llamada sea `Event::listen(VoicePostCall::LAB_EVENT, [StudioVoiceLabSessions::class, 'recordProviderReport'])`.
3. **`VoiceIsolationTest::test_las_pruebas_de_voz_con_red_cierran_la_red`** (NO es mía; ya cae en HEAD `af6bd805`): `tests/Feature/Voice/VoiceMonitorScheduleTest.php` (OPS8-b, confirmada en `2b3c2936`) no llama a `Http::preventStrayRequests()`.

En los seis ficheros de guardas de SEC8 pasan **58 de 61** (caen solo estas 3), también la frontera de límites de `VoiceBoundaryTest` (credencial → cubo de fallos → autenticado → 429) y el registro de la voz de `TelephonyIsolationTest` con las dos líneas nuevas.

## 5. Contratos nuevos o modificados

- **Comando nuevo** `voice:prune-rate-limits [--dry-run] [--json]` → `{store, driver, supported, dryRun, expired, deleted, batches}`; exit 0.
- **Rutas públicas:** sin cambios de forma. Pueden dar 429 también por el techo global de su ruta, con la misma respuesta (`{ok:false, code:too_many_requests}` y `Retry-After`; E6-10 ya lo anuncia al CRM). Solo lo alcanza tráfico con credencial válida.
- **CRM** (`status`, `metrics`, `contacts`): mismas respuestas, byte a byte en lo comprobado (DTO completos en las pruebas).
- **Firmas internas** (todas compatibles hacia atrás, parámetros opcionales): `VoiceEvidence::summary($part, $seq, ?bool $brakeOpen = null)`; `VoiceCallsQuery::metrics(?string $budgetDay = null, ?array $parts = null, ?bool $brakeOpen = null)`; `VoiceTransferDirectory::countsByPart(?array $parts = null)`. Nuevas: `VoiceCallFacts::excludedAmong()`, `VoiceRateLimits::globalPerMinute()`, constantes `PREFIX` (ahora pública), `GLOBAL`, `GLOBAL_PER_MINUTE`, `SILENCED_NOTICE_SECONDS`, `SILENCED_COUNTER_SECONDS`.
- **Registro (canal `voice`)**: `voice.public.failures_silenced {route, silenced}` (≤ 1/min por ruta) y `voice.rate_limits.pruned {store, supported, dry_run, expired, deleted}`.
- **Evento**: `voice.lab.post_call` tiene ahora su oyente de arranque.

## 6. Migración, configuración y despliegue

- **Migración:** ninguna nueva; `2026_11_10_100002` editada en sitio (un índice). Si en algún entorno de pruebas ya se aplicó (bancos, PG desechables), hay que recrearlo para tener el índice; en producción no ha corrido. `h8-schema-check.sql` solo cuenta parciales: no cambia.
- **Variables nuevas (opcionales, con defecto):** `VOICE_PUBLIC_INITIATION_PER_MINUTE=120`, `VOICE_PUBLIC_POST_CALL_PER_MINUTE=240`, `VOICE_PUBLIC_TOOLS_PER_MINUTE=600`, `VOICE_PUBLIC_USAGE_TRIGGER_PER_MINUTE=60`. Con la configuración en caché, cambiarlas pide `config:cache` como el resto.
- **Para OPS8-b (E8-6), la línea exacta**, dentro de `if ((bool) config('voice.monitor.enabled', false)) {` de `routes/console.php`:

  ```php
      // La purga de los límites de las rutas públicas (PERF8 P8-3, E8-6): borra de la
      // caché database SOLO las claves voice-rl:* CADUCADAS. Local, sin red. Una vez al día.
      Schedule::command('voice:prune-rate-limits')
          ->dailyAt('04:40')
          ->timezone('America/Bogota')
          ->withoutOverlapping(10)
          ->onOneServer();
  ```

  `VoiceMonitorScheduleTest` (suya) fija el conjunto exacto de órdenes del bloque: hay que añadir esta. Mientras el monitor esté apagado (fase 1), se acumulan sobre todo los cubos de fallo por IP de quien sondee las rutas; se purgan a mano con el mismo comando (`--dry-run` primero).

## 7. Para tester (casos límite desde dentro)

- Techo global: la comprobación y el `hit` no son atómicos (como antes y como el `attempt()` de Laravel): con concurrencia se puede pasar por una o dos peticiones.
- Purga: una petición que reutiliza una clave caducada justo mientras se borra puede contar una vez de menos; inocuo (el limitador sería una petición más permisivo).
- Oyente: si `recordProviderReport` lanza (una guarda sin forma), la post-llamada responde 5xx y ElevenLabs reintenta; un agente de laboratorio con la sesión `failed` o sin URL responde `ignored` y no es desconocida (diseño de LAB8/B8-b).
- La fase cerrada del E2E (TEST8-b) debe repetirse con el oyente: ahora la post-llamada web del laboratorio cierra la sesión y no echa el freno.

## 8. Ficheros

Cambiados: `.env.example`, `app/Providers/AppServiceProvider.php`, `app/Services/Telephony/{VoiceCallFacts,VoiceCallsQuery,VoiceContacts,VoiceEvidence,VoiceRateLimits,VoiceTelephonyStatus,VoiceTransferDirectory}.php`, `config/voice.php`, `database/migrations/2026_11_10_100002_create_voice_tables.php`, `tests/Feature/Telephony/{VoiceLabPostCallTest,VoicePublicRateLimitsTest}.php`, `tests/Unit/Voice/ElevenLabsConfigTest.php`, este informe.
Nuevos: `app/Console/Commands/Voice/VoicePruneRateLimitsCommand.php`, `app/Support/Cache/ExpiredCachePruner.php`, `tests/Feature/Telephony/{VoiceLabPostCallWiringTest,VoiceCrmQueryBudgetTest,VoicePruneRateLimitsCommandTest}.php`.
Sin commit. Bases PG mías: `ultron_studio_h8_bc` (la puerta, se queda) y `ultron_studio_h8_bc_perf` (la copia para el EXPLAIN, borrada al terminar).

## §B8-c (2) · puntos 6–9 del lead: P8-5 y la fuga al login (punto 8), P8-7, y O-1

**Árbol:** `f84c6bc4` + mis cambios, **sin commit**. Mientras tanto, sus dueños dejaron en el árbol (sin confirmar, no los toqué) la excepción de `UltronEngineIsolationTest` para el oyente (`LAB_LISTENER`), `voice:prune-rate-limits` en `VoiceBoundaryTest::COMANDOS` (SEC8), y la línea de la purga en el bloque de voz de `routes/console.php` con su `VoiceMonitorScheduleTest` (OPS8-b). **Con eso, las tres guardas de §4 están en verde.**

### 1. Cifras (comandos ejecutados, después de todo lo anterior)

| Qué | Resultado |
|---|---|
| `tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio`, SQLite | **759 pruebas, 196 398 aserciones, 0 fallos**, 146 omitidas (solo PG) |
| Studio de voz, laboratorio y guarda del motor, SQLite | **588 pruebas, 6 464 aserciones, 0 fallos**, 73 omitidas |
| Todo lo anterior en la puerta PG (`ultron_studio_h8_bc`) | **1 347 pruebas, 204 014 aserciones, 0 fallos**, 15 omitidas |
| `auth:coverage --unmapped` | vacío, exit 0 |
| Pint en lo nuevo de esta tanda | `passed` (`AppServiceProvider`, como en §1: solo líneas previas) |
| Mutaciones de esta tanda (copia aislada, sha256 restaurado) | **16 aplicadas, 16 muertas** (P8-5/punto 8: 9; P8-7: 7). En total B8-c: **46/46** |

### 2. P8-5 y punto 8: los cupos del CRM de la telefonía

**El defecto, reproducido antes de tocar nada** (`VoiceAdminRateLimitsTest` sobre el código anterior, 6/6 en rojo): tras 50 peticiones anónimas a `admin/voice/status`, el login del panel con la contraseña buena responde **429**; dos personas desde la misma IP comparten las 120 lecturas; un bearer inventado por petición no tiene tope propio. La causa: `throttle:120,1` y `throttle:30,1` SIN NOMBRE corren ANTES que `ProtectAdminPaths` (orden real comprobado: `ThrottleRequests:120,1` es el primero), sin `$request->user()` (el panel no lo rellena) Laravel cuenta en `sha1(dominio|IP)`, y ese cubo es el MISMO que el del login (`throttle:8,1`) y el de cualquier otro `throttle` sin nombre.

**Qué cambió:**
- `routes/voice.php`: `throttle:voice-admin-read` y `throttle:voice-admin-write` (limitadores con nombre: Laravel antepone su nombre a la clave, nunca coinciden con un `throttle` sin nombre). Comentario de cabecera al día.
- `app/Providers/AppServiceProvider.php`, `limitadoresDeLaTelefonia()` (el patrón del Studio: bloque autocontenido, respuesta 429 uniforme): con sesión, un cubo por **persona** (el administrador de la sesión del bearer, resuelta aquí porque el limitador corre antes que `ProtectAdminPaths`; otra pestaña u otra sesión no dan más): 120 lecturas y 30 escrituras por minuto, los mismos números que antes, ahora por persona. Sin sesión (sin bearer, uno inventado o caducado, o el token compartido, que la telefonía no admite), un cubo **propio por ruta y red** (`ClientNetwork`: IPv4 o el /64): 60 lecturas y 30 escrituras por minuto. Su 401 o su 403 no gasta el cupo de nadie, ni el del login, y un bearer inventado por petición no estrena cubo.
- `tests/Feature/Telephony/VoiceAdminRateLimitsTest.php` (nueva, 6): las 13 rutas con su limitador con nombre y ninguno sin nombre; dos personas desde la misma IP (lectura y escritura) no se gastan el cupo; el cupo es de la persona y no de la sesión; **50 peticiones anónimas no cambian el login** (200 antes y después) ni el cupo de una persona de la misma IP; sin sesión, cada ruta y cada red tienen su cubo y 60 bearers inventados agotan el mismo; el 429 con `Retry-After` y `{ok:false, code:too_many_requests, message, retry_after}`.

**Coste:** una sentencia por petición del CRM de voz (la sesión del bearer): `GET status` y `metrics` por la pila HTTP, 16 → **17** (siguen muy por debajo de los 33/30 de antes de §B8-c).

**Decisión para confirmar:** sin el techo por red del Studio. Allí existe porque su cupo va por credencial SIN resolverla (un bearer inventado nunca lo alcanzaría); aquí lo que no es una sesión cae en el cubo anónimo de su ruta y red, y un techo por red volvería a dar un cubo común a toda la recepción (P8-5) que las anónimas podrían gastar (punto 8). Si el lead lo quiere igual, es una línea, y entonces las anónimas no deben contar en él.

**Contrato (para FE8):** un 429 de `/api/admin/voice/*` trae ahora el cuerpo uniforme del Studio (`ok`, `code: too_many_requests`, `message`, `retry_after`) con `Retry-After`; antes, el de Laravel (`{"message":"Too Many Attempts."}`). Para SEC8: cambia la superficie de limitación del CRM de voz (PERF8 lo pidió así).

### 3. P8-7: la conciliación en régimen

**Perfil (antes de tocar nada; PG local, 100 llamadas ya conciliadas, transportista en memoria):** 171–185 ms por pasada con 104 sentencias y 28–31 ms de BD visibles. Un `record()` repetido cuesta 0,667 ms frente a 0,297 de un `exists()`: los 2 por llamada × 100 explican ~133 de los ~141 ms que `DB::listen` no ve (las sentencias que fallan no se registran). **Hipótesis de PERF8 confirmada.**

**Qué cambió:** `app/Services/Telephony/VoiceReconciler.php`: la fila de la llamada (y la de la madre de un tramo) se lee con DOS marcas en la MISMA `SELECT … FOR UPDATE` que ya la bloquea: `EXISTS` del `reconciled` de su estado y del `cost_recorded` de su precio (`cost:` o `leg:`). Con la marca a sí no se intenta el INSERT. Cero sentencias más (el presupuesto de PERF8 para el régimen es 106; siguen 104; las primeras pasadas no cambian). Los eventos son de solo inserción: una marca a sí no envejece; una a no que se quede vieja (otra conciliación a la vez) vuelve al camino de siempre (el repetido devuelve null). Las marcas salen de los atributos de la fila.

**Prueba** (`tests/Feature/Telephony/VoiceReconcilerSteadyStateTest.php`, nueva, 2): en régimen, el MISMO resumen, ningún evento nuevo, los mismos costes y **0 puntos de guardado deshechos** (antes, 7 por pasada: 3 llamadas × 2 + el tramo) con una transacción por llamada; y lo que cambia entre pasadas (un estado final nuevo, un precio que llega tarde) se escribe una vez. La de antes (`VoiceReconcilerTest`, con el transportista real) sigue en verde.

**Medida A/B** (PG local, alternando el código anterior en una copia y el nuevo, en bases mías): con `RefreshDatabase` el lado nuevo empeoraba pasada a pasada (83 → 124 ms) por un artefacto de la prueba (bloquear las mismas filas en subtransacciones de una transacción que nunca se confirma); **con confirmaciones reales**, como producción, 15 pasadas: antes **166 ms** de mediana (p95 178, BD 23,9 ms; las cifras de PERF8), después **57 ms** (p95 59–67, BD 31,4 ms), 104 sentencias en los dos. Una ronda «antes» salió ruidosa (363 ms) por carga ajena de la máquina compartida.

### 4. Punto 9 (O-1): no cabe en B8; medido y anotado

**Medida** (sonda fuera del repo, SQLite y PG): una `unknown_provider_call` creada 0,4 s ANTES de publicar se guarda `2026-11-09 15:00:00`, igual que la entrada de la cadena (`created_at` de precisión 0 en las dos tablas), y `forPublication()` la cuenta (1; el contrato espera 0). La de 1 s antes no cuenta. Ventana de la ambigüedad: hasta 1 s, en el sentido seguro (bloquea promover de más, nunca de menos).

**Por qué no lo cerré aquí:** el ancla es la entrada de la cadena (`StudioVoiceEvidence`: `$entrada->created_at`, segundos; el mensaje firmado también va a segundos). Microsegundos solo en los eventos no lo arreglan (el ancla seguiría truncada), y no hay campo de marca de agua en `ultron_studio_voice_publications`. Las dos salidas tocan DB8 (`2026_11_10_100001`) y S8 (`StudioVoicePublishing`/`StudioVoiceEvidence`):
- **(A, recomendada) marca de agua:** columna nullable `events_watermark_id` en la entrada (fuera del mensaje firmado), que S8 escribe en el mismo INSERT con un lector de B8 (`max(voice_call_events.id)` leído en esa transacción); `forPublication(…, ?int $afterEventId = null)` cuenta por `id >` cuando viene. Exacta salvo los eventos en vuelo al publicar, que cuentan (sentido seguro). Sin relojes.
- **(B) microsegundos** en `created_at` de las dos tablas (`timestamp(…, 6)`), el formato de fecha de los dos modelos y la comparación con el instante formateado con `.u`.

### 5. Ficheros de esta tanda

Cambiados: `routes/voice.php`, `app/Providers/AppServiceProvider.php` (`limitadoresDeLaTelefonia()`), `app/Services/Telephony/VoiceReconciler.php`, este informe. Nuevos: `tests/Feature/Telephony/{VoiceAdminRateLimitsTest,VoiceReconcilerSteadyStateTest}.php`. Bases PG mías borradas: `ultron_studio_h8_bc_old` (A/B); queda `ultron_studio_h8_bc`.

---

# §B8-d · correcciones antes de la fase 2 (Enmienda 10: E10-4, E10-5, E10-6, E10-8, E10-9; DOC8 D-10 y D-11)

**Fecha:** 09-11-2026. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h8-voice`, sobre `a20c48a0` (árbol limpio al empezar) + mis cambios, **sin commit**. Cero red: toda prueba con `Http::preventStrayRequests()`; Twilio y ElevenLabs, solo contra `Http::fake` o adaptadores falsos. No toqué guardas (`*IsolationTest`, `VoiceBoundaryTest`), `routes/console.php`, `docs/ultron-studio/ops/**`, `docs/ultron-studio/*.md`, migraciones ni el CRM. Ni una variable nueva ni un valor cambiado.

## 1. Cifras reales (comandos ejecutados sobre el estado final)

| Qué | Comando | Resultado |
|---|---|---|
| Voz, telefonía y Twilio, SQLite | `phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio` | **782 pruebas, 200 714 aserciones, 0 fallos**, 146 omitidas (solo PG). Antes de B8-d eran 761: **+21 nuevas** |
| Studio de voz, laboratorio y guarda del motor, SQLite | `phpunit tests/Feature/UltronStudio/*Voice*Test.php tests/Unit/UltronStudio/*Voice*Test.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **588 pruebas, 6 464 aserciones, 0 fallos**, 73 omitidas (las mismas cifras que en §SEC8-b (2)) |
| Todo lo anterior, puerta PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bd2 bash docs/ultron-studio/ops/pgunit.sh …` (las dos listas) | **1 370 pruebas, 208 330 aserciones, 0 fallos**, 15 omitidas (las mismas 15). Antes eran 1 349 |
| Quien lee el fichero de ejemplo de entorno y `config/voice.php` | `SecretExposureTest`, `UltronStudioMonitorTest`, `UltronStudioIntegrationsConfigTest`, `UltronStudioPublicationConfigTest`, `UltronStudioIntegrationsNoNetworkTest`, `UltronStudioEarlyBodyCapTest` | **66 OK**, 746 aserciones |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, exit 0 (ninguna ruta nueva) |
| Estilo | `pint --test` en los 9 ficheros de `app/` y `config/` tocados y en las 6 pruebas | `passed` |
| Las guardas de SEC8 | dentro de las dos primeras filas (`VoiceIsolationTest`, `VoiceBoundaryTest`, `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`, `UltronEngineIsolationTest`) | en verde, sin tocarlas |

**Cada prueba de regresión cae sin su arreglo** (ejecutado). La copia aislada es un clon APFS del backend, sin el fichero de entorno, con los 9 ficheros de `app/` y `config/` de `HEAD` y las pruebas finales:
- SQLite: de las **21 pruebas nuevas caen 19** (con la del doctor, 20 de 32 en esos ficheros: 9 errores y 11 fallos). Las 2 que pasan a los dos lados lo hacen **a propósito**, porque fijan lo que no debe cambiar: `test_el_resto_de_la_regla_de_lo_comprometido_no_cambia` y `test_solo_se_cuelga_lo_que_corto_una_persona_no_lo_que_ya_termino`.
- **M-2 en PG, antes:** `Expected response status code [200] but received 500` … `SQLSTATE[23514] … violates check constraint "vce_codes_chk"`. Es el síntoma exacto de §SEC8-b (2). Antes de arreglarlo, el árbol compartido dio lo mismo.

**Mutaciones por muestreo.** Las corrí en otra copia aislada (clon APFS con su `vendor/`), con el banco `<scratchpad>/tools/mutar-b8d.php`. Para cada mutación: el texto de partida aparece exactamente una vez, `php -l`, sus pruebas en SQLite, se restaura y se compara el SHA-256.
- **Resultado: 35 de 35 muertas**; los **2 controles no caen**; las **37 se restauran con el SHA-256 igual** y la copia queda idéntica al árbol.
- Por punto: M-2, 5; B-2, 8; E10-8, 4; E10-9, 9; M-3, 8; D-11, 1.
- En la primera pasada **sobrevivieron 3** y cada una destapó un hueco de las pruebas, ya cerrado:
  - **B2-2** (`>` → `>=` en la observación posterior): las pruebas viajaban a una hora de Bogotá y Carbon leía las fechas de la base en `-05` (§5). Ahora el reloj de la prueba va en UTC.
  - **B2-7** (el número de generación): solo se llegaba a `r3`; ahora se llega a `r4`.
  - **D9-6** (la exclusión de las atascadas en seco): la saliente no era vieja; ahora lleva 16 minutos en `dialing`.
- Mutante equivalente, anotado y sin probar: en `costCap()`, `$comprometido <= 0` es redundante mientras el presupuesto sea ≥ 0 (`budgetMicros()` nunca da negativo). Queda porque es la regla escrita de E10-8.

## 2. Qué cambió y por qué (por fichero)

**E10-4 (SEC8-b M-2): el veredicto acotado.**
- `app/Services/Telephony/VoicePostCall.php`:
  - `MAX_GUARD_CODES = 50` (como el laboratorio) y `MAX_GUARD_CODES_BYTES = 3 584`.
  - `verdictCodes()` guarda las PRIMERAS violaciones, en orden de turno, mientras quepan las dos cotas. Añade `truncated` (cuántas no caben) solo si sobra alguna. Sin violaciones, la forma de siempre: `{violations: []}`.
  - El recuento completo sigue en la respuesta (`violations`) y en el registro.
  - El veredicto (`outcome = violation`) y el freno no dependen del tamaño: su evidencia son los códigos DISTINTOS, de un conjunto cerrado, también acotados.
  - La cota de bytes cubre códigos del motor más largos que los de hoy (≤ 31 caracteres).
- `tests/Feature/Telephony/VoicePostCallGuardCapTest.php` (nueva, 3 pruebas):
  - 200 violaciones por la ruta firmada: 200, `completed`, `seal_verified`, 50 guardadas con `truncated = 150`, JSON ≤ 4 096 bytes, freno abierto con su evento, y el reintento de ElevenLabs da `duplicate`. **En PG antes: 500.**
  - Justo en el tope: la forma de siempre.
  - Con códigos de 64 caracteres manda la cota de bytes.

**E10-6 (SEC8-b B-2): el freno se rearma si la evidencia cambió después de soltarlo.**
- `app/Services/Telephony/VoiceBrake.php`:
  - `openOrRearm()`:
    - nunca abierta: como `open()`;
    - echada: idempotente;
    - soltada: abre OTRA apertura solo si `$changedAfterRelease(apertura soltada, suelta)` lo dice. La nueva lleva la generación siguiente de la clave (`<clave>:r2`, `:r3`… o, con claves de más de 120 caracteres, `rearm:<sha256>:r<n>`, que siempre cabe en los 128 de `open_key`), `rearmOf` en la evidencia y su incidente.
  - `nextOpening()`: la misma decisión sin escribir (la usa el modo en seco). Sin regla de rearme, es la regla de `open()`: cada clave, una vez.
  - `observedAfter()`: la regla de las derivas. La observación empezó DESPUÉS de la suelta; en el mismo segundo cuenta como posterior, en el sentido seguro (la suelta se guarda a segundos).
  - `open()` no cambia: las causas de una sola vez (`unknown:`, `seal:`, `guard:`, `usage:`) no se rearman.
- `app/Services/Telephony/VoiceReconciler.php`:
  - deriva del agente y deriva del número con `observedAfter(instante antes del GET)`;
  - techo de gasto con «lo comprometido > el `committedMicros` registrado en la apertura soltada».
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`:
  - la deriva del job solo rearma con una observación hecha ahí;
  - el veredicto de la caché (≤ 5 min) es anterior: cancela la llamada con `agent_drift`, pero no reabre.
- `tests/Feature/Telephony/VoiceBrakeRearmTest.php` (nueva, 4 pruebas):
  - misma observación (la suelta llega con la lectura en vuelo) → no; observación nueva → sí (`r2`, con su incidente), idempotente mientras sigue echada, y `r3` y `r4`;
  - deriva del agente: la conciliación rearma y la caché del job no;
  - gasto igual → no; gasto mayor → sí, con lo comprometido de ahora;
  - las causas de una sola vez, la llamada a la regla con la apertura y su suelta, y la clave larga.

**E10-8 (DOC8-1): el freno de gasto con presupuesto 0.**
- `app/Services/Telephony/VoiceCostGuard.php`:
  - `denied` sale del grupo «el mayor entre la reserva y lo real» y pasa a «solo lo real conocido» (`DENIED_STATUSES`).
  - Por coherencia con M-3, `canceled` y `failed_to_start` también cuentan lo real conocido, que es 0 si no salió nada (antes, 0 fijo). Ver §4.1.
  - `contribution()` es la misma regla en PHP. La usa el modo en seco y hay una prueba de paridad, estado a estado, con el SQL.
- `VoiceReconciler::costCap()`: `cost_cap` solo si lo comprometido es mayor que 0 **y** mayor que el presupuesto. El resumen `costCap` sigue diciendo el ESTADO (se pasa del techo), se abra ahora o no: el código de salida del comando depende de él y no cambia.
- `tests/Feature/Telephony/VoiceCostCapZeroBudgetTest.php` (nueva, 5 pruebas):
  - **la prueba O-2 tal cual**: iniciación con el secreto bueno y las entrantes apagadas, que deja una fila `denied` con su reserva de un minuto. Comprometido 0, sin freno, también con más denegadas;
  - presupuesto 0 y una llamada con coste → freno;
  - lo real de una denegada sí cuenta;
  - el resto de la regla no cambia;
  - paridad PHP/SQL.

**E10-9 (DOC8-2): `voice:reconcile --dry-run`.**
- `app/Services/Telephony/VoiceReconciler.php`: `run(…, bool $dryRun = false)`, `numbers(…, bool $dryRun = false)` y `dryRunReport()`.
  - En seco lee lo mismo, también los GET a los proveedores, y decide con el MISMO código. Los sitios que escriben pasan por cuatro ayudantes (`event()`, `transition()`, `brake()` y el final de `cost()`) que en seco no escriben y apuntan lo que harían.
  - Sin transacción ni cerrojos.
  - Sin `Cache::put` del veredicto de deriva (la caché que lee el job).
  - Simula en memoria los estados nuevos y los precios sumados, para que el techo y las atascadas salgan como después de la pasada de verdad.
  - Anuncia cada clave del freno una vez, como `open()`.
  - Una línea `voice.reconcile` con `dryRun: true`.
- `app/Console/Commands/Voice/VoiceReconcileCommand.php`: la opción `--dry-run`. Con `--json` añade el informe; en texto, la cabecera «EN SECO» y dos líneas «Haría». El código de salida se calcula como siempre.
- `tests/Feature/Telephony/VoiceReconcileDryRunTest.php` (nueva, 4 pruebas):
  - **la llamada desconocida sembrada** (la página del fixture), con una pedida sin job y el techo superado. En seco: la base no cambia (filas, eventos, freno e incidentes, comparados enteros), código de salida 1, y anuncia las dos aperturas, sus 2 incidentes y los eventos. Después, de verdad: el mismo resumen, los mismos eventos por tipo, las mismas aperturas, las mismas transiciones y los mismos precios. Una segunda pasada en seco ya no anuncia nada;
  - el techo con lo que deja la pasada: el estado (`busy` deja de reservar) y el precio sumado, con dos presupuestos;
  - deriva del agente (dos partes, el mismo agente: una apertura) y deriva del número, y su rearme anunciado sin escribirlo ni tocar la caché.

**E10-5 (SEC8-b M-3): gana el corte.**
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`, `record()`: si la fila ya está `canceled` cuando el proveedor responde `started` (o `ambiguous` con `callSid`):
  - la fila no cambia: un final no cambia, y la fila `canceled` no admite ids (`vc_provider_binding_chk`);
  - el evento lleva `{conversationId, callSid, afterCancel: true}`;
  - FUERA de la transacción, sin cerrojo durante la red, se cuelga en el acto.
- `app/Services/Telephony/VoiceHangup.php`:
  - `startedAfterCancel()`: el transportista real (Twilio, la llamada a `completed`) y `hangup_requested`, actor sistema, con lo que dijo el transportista (sin valores de `outcome` nuevos);
  - el otro orden de la carrera: si la cancelación no aplica porque el job marcó entre tanto, relee la fila y corta como una viva (`hung_up`) en vez de responder `not_live`. El evento `canceled_before_dial` solo se escribe si de verdad canceló.
- `app/Services/Telephony/VoiceReconciler.php`, `canceledStartFor()`: ese SID es CONOCIDO si Twilio ya la da por terminada (estado `reconciled` y precio en la fila cortada). Si la enseña viva, el corte no surtió efecto y sigue el camino de una desconocida, con freno, como antes del arreglo.
- `tests/Feature/Telephony/PlaceOutboundVoiceCallHangupRaceTest.php` (nueva, 5 pruebas, con el transportista real contra `Http::fake`):
  - el corte primero: colgada en el acto, los ids en el evento y la conciliación la reconoce, sin freno; su precio compromete gasto;
  - el job primero: `hung_up`;
  - Twilio responde 500 al colgar: la conciliación la ve viva y frena, y la reconoce cuando termina;
  - una saliente ya terminada por el webhook (DF-S2) no se cuelga;
  - una ambigua con SID sobre una cortada se cuelga y se reconoce; sin SID, ninguna petición.

**DOC8 D-11 y D-10.**
- `app/Console/Commands/Voice/VoiceDoctorCommand.php`: la línea «Llamadas» lee `VoiceCallPolicy::CALLS_ENABLED_IN_CODE` (sin nombrarla dentro de una cadena, como exige `VoiceIsolationTest`).
- `tests/Feature/Voice/VoiceDoctorCommandTest.php` (del H6): su aserción del texto viejo («En este hito no se hace ninguna llamada.») pasa al nuevo («Llamadas   : cerradas en el código (la constante de VoiceCallPolicy)»). Una línea; es la única prueba que la fijaba.
- `config/voice.php`, solo comentarios:
  - la cabecera del H6 («SIN ACTIVAR», «no existe ningún código capaz de iniciarlas», «solo los lee ElevenLabsVoiceProvider»), con el estado del H8;
  - la del secreto del webhook («no hay ruta en este hito»);
  - la de `TWILIO_VOICE_ENABLED` («nada la usa para ejecutar»).
- El fichero de ejemplo de entorno, solo comentarios:
  - la cabecera del bloque del H6 («En este hito no se hace ninguna llamada», «estado de producción del Hito 6»; ahora con E1-12);
  - una línea para `ELEVENLABS_WEBHOOK_SECRET`;
  - la de `TWILIO_VOICE_ENABLED`;
  - la nota de E10-8 junto a `VOICE_DAILY_BUDGET_USD`.
- Ningún valor cambia. `git diff` de `config/voice.php` solo toca líneas de comentario.

## 3. Contratos nuevos o modificados

- **`voice:reconcile --dry-run [--json] [--numbers] [--hours=N]`.** El resumen de siempre más:
  - `dryRun: true`;
  - `wouldOpenBrake: bool`;
  - `would: {brakes: [{reason, cause}], incidents: N, events: {<tipo>: N}, transitions: N, costs: N}`.

  Con `--numbers`, `number.brakeOpened` es `false` y `number.wouldOpenBrake` dice si se abriría. Sin `--dry-run`, la salida no cambia ni en una clave. **El nombre:** el contrato escribió `would_open_brake`; en el JSON es `wouldOpenBrake`, en camelCase como el resto del resumen (`staleRequested`, `costCap`, `brakeOpened`). Si el lead prefiere el otro, es una línea.
- **`guard_verdict.codes`** = `{violations: [≤ 50 primeras {turn, code}], truncated?: N}`. La respuesta del webhook (`violations`) sigue con el recuento completo.
- **Frenos**:
  - las reaperturas son filas nuevas (`open_key` `<clave>:r<n>` o `rearm:<sha256>:r<n>`), con `rearmOf` (el uuid de la soltada) en la evidencia;
  - `GET voice/brakes` y `GET admin/voice/status` las enseñan como aperturas más;
  - el Studio sigue usando solo `isOpen`, `list` y `release`;
  - en IRON GUARD, un incidente por motivo mientras sigue abierto, con una ocurrencia por apertura (el agrupamiento de siempre de `IncidentRecorder`).
- **Eventos**:
  - solo en el caso cortado, `outbound_started.codes` gana `{conversationId, callSid, afterCancel}` y `outbound_ambiguous.codes` gana `afterCancel`;
  - un `hangup_requested` de sistema con `codes.afterCancel`;
  - ningún valor nuevo de `kind` ni de `outcome`: el CRM (`GET calls/{uuid}`) no necesita textos nuevos.
- **Lo comprometido del día** (`GET admin/voice/metrics` → `cost.committedMicros`, la reserva de las salientes y el techo):
  - una denegada ya no reserva: cuenta lo real conocido;
  - una cortada o rechazada cuenta lo real conocido (0 si no salió nada).
- **`VoiceHangup::hangup()`**: en la carrera con el job, `hung_up` donde antes daba `not_live`.
- **`voice:doctor`** (texto): «Llamadas   : cerradas en el código (la constante de VoiceCallPolicy): ni salientes ni entrantes autorizadas.» (con la constante abierta, «ABIERTAS …»).
- **Firmas internas** (compatibles hacia atrás):
  - nuevas: `VoiceBrake::openOrRearm()`, `::nextOpening()`, `::observedAfter()` y `REARM_READABLE_MAX`; `VoiceCostGuard::contribution()` y `DENIED_STATUSES`; `VoicePostCall::MAX_GUARD_CODES` y `MAX_GUARD_CODES_BYTES`; `VoiceReconciler::dryRunReport()`; `VoiceHangup::startedAfterCancel()`;
  - con un parámetro opcional nuevo: `VoiceReconciler::run(…, bool $dryRun = false)` y `numbers(…, bool $dryRun = false)`.

## 4. Decisiones tomadas (para confirmar)

1. **«Lo denegado no compromete gasto» = no reserva, pero lo real conocido sí cuenta.** Es el grupo de `busy`, `no_answer` y `failed`. No lo dejo en 0 siempre por dos razones:
   - una avalancha de entrantes denegadas cuesta minutos reales de Twilio y de ElevenLabs, y con 0 siempre el techo no la vería nunca;
   - las filas de la prueba O-2 son sintéticas, no tienen coste real y no echan el freno.

   Por lo mismo, `canceled` y `failed_to_start` pasan de «0» a «lo real», que es 0 salvo en el caso de M-3, donde la llamada sí salió y se colgó. Si el lead quiere la denegada en 0 siempre, es una línea; pierde esa vigilancia.
2. **El techo, rearmado frente a lo registrado en la apertura soltada.** La suelta no admite evidencia (`vbr_shape_chk` exige `evidence IS NULL`) y no toqué el esquema. Lo registrado al abrir es ≤ lo que había al soltar, así que va en el sentido seguro: un gasto que llegó entre abrir y soltar (un precio tardío) también rearma.
3. **La deriva, por instantes.** Cuenta el instante en que empieza la observación (antes del GET) frente a `created_at` de la suelta. En el mismo segundo cuenta como posterior (falla cerrado). El veredicto de la caché del job no rearma nunca: la conciliación siguiente sí.
4. **M-3 falla cerrado si el corte inmediato no surte efecto.** Pasa si Twilio está caído o si el proceso muere entre guardar y colgar. En ese caso la llamada sigue hasta su máximo (≤ 300 s), queda `hangup_requested` con el fallo y un `voice.hangup.after_cancel_failed` en el registro, y la siguiente conciliación la ve viva y echa el freno (`unknown:<sid>`). **No añadí** un reintento manual: `voice:hangup` no corta una fila `canceled`, porque su SID solo está en el evento. Queda como riesgo residual (§6).
5. **M-3 sin dos conexiones de PG.** El job no tiene cerrojo mientras espera al proveedor, así que los dos órdenes de la carrera son secuencias deterministas sobre la base, y las dos se prueban, también en PG dentro de la puerta. El único solape real es el `FOR UPDATE` de `record()` contra el `UPDATE … WHERE status = 'requested'` del corte. PG lo serializa por la fila y vuelve a evaluar el `WHERE` en READ COMMITTED: el corte afecta a 0 filas y pasa a la relectura, que es exactamente el estado de la segunda prueba. Para un bloqueo de verdad entre dos sesiones haría falta un segundo proceso: una prueba de un solo hilo se quedaría esperando.
6. **El modo en seco simula en memoria lo que dejaría la pasada** (estados y precios) para el techo y las atascadas. Sin eso, anunciaba un `cost_cap` que no iba a pasar (y al revés con los precios), y lo cazan las mutaciones D9-5 y D9-9. No es una instantánea transaccional: con escrituras concurrentes, la pasada de verdad puede diferir.
7. **El tope de bytes además del de 50.** Hoy 50 entradas ocupan unos 3 KB. La cota de 3 584 bytes cubre que el motor traiga códigos más largos sin volver a romper el CHECK.

## 5. Para tester (casos límite desde dentro)

- **Artefacto de las pruebas, no de producción.** Con `travelTo()` a un Carbon en otra zona (p. ej. `America/Bogota`), Carbon lee las fechas de la base EN ESA ZONA (`createFromFormat` toma la zona de la hora de prueba) hasta el primer `travel()`, que la deja en UTC: los instantes leídos salen 5 h desplazados.
  - Lo medí con una sonda: escrito `15:00:00` en UTC, se lee `15:00:00 -05`.
  - Las pruebas que comparan un instante con un `created_at` recién leído deben fijar el reloj en UTC; la de rearme ya lo hace.
  - En producción no hay hora de prueba y se escribe y lee en la misma zona.
- **Rearme concurrente.** Dos conciliaciones calculan el mismo `r<n>`: el UNIQUE de `open_key` deja una y la otra es idempotente. Si la suelta y una observación en vuelo se cruzan, decide el instante de la observación.
- **El colgado inmediato después de 20 s de ElevenLabs.** El plazo de Twilio es 10 s y el job tiene 30 s. Si los dos se agotan, `failed()` no toca la fila (`canceled`) y la conciliación decide.
- **`canceledStartFor()`** mira, como `ambiguousFor()`, los últimos 200 eventos de ese tipo (cupo ≤ 20 salientes al día).
- **En seco, sin transacción:** lee el estado de cada sentencia, no una foto.

## 6. Riesgos residuales y pendientes

- **M-3**, si el corte inmediato falla: no hay reintento desde Laravel sobre la fila cortada (decisión 4). La conciliación frena y el incidente avisa; se corta en la consola (nivel 3). Si el lead lo quiere, es una tanda pequeña: `voice:hangup <uuid>` sobre una `canceled` con `afterCancel` y sin colgado bueno.
- **Documentos de DOC8 y del lead** (no los toqué): las marcas «corrección en curso (B8-d)» de R8-13, R8-19 a R8-21 y R8-27 y del plan, el checklist, la guía y `ULTRON_STUDIO_H8_VOZ.md` (la fila de `voice:reconcile`, que ya admite `--dry-run`), y el nombre `wouldOpenBrake`.
- **Fuera de B8-d:** E10-10 (destinos en el laboratorio, fase 3) y E10-7 (lead).

## 7. Migración, configuración y despliegue

- **Migraciones:** ninguna. **Variables nuevas:** ninguna. **Valores:** ninguno cambia; solo comentarios en `config/voice.php` y en el fichero de ejemplo de entorno.
- **Para el runbook (OPS8/DOC8):** antes de soltar el freno, `php artisan voice:reconcile --dry-run --json` (con `--numbers` si hace falta) ya es de solo lectura sobre la base: no escribe filas, eventos, freno, incidentes ni la caché del job. Los GET a los proveedores sí salen, igual que de verdad.

## 8. Ficheros

**Cambiados:**
- `app/Services/Telephony/{VoicePostCall,VoiceBrake,VoiceReconciler,VoiceCostGuard,VoiceHangup}.php`
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`
- `app/Console/Commands/Voice/{VoiceReconcileCommand,VoiceDoctorCommand}.php`
- `config/voice.php` (comentarios)
- el fichero de ejemplo de entorno (comentarios del bloque de voz)
- `tests/Feature/Voice/VoiceDoctorCommandTest.php` (una aserción)
- este informe

**Nuevos:** `tests/Feature/Telephony/{VoicePostCallGuardCapTest,VoiceBrakeRearmTest,VoiceCostCapZeroBudgetTest,VoiceReconcileDryRunTest,PlaceOutboundVoiceCallHangupRaceTest}.php`.

**Sin commit.**

**Bases PG:** queda la mía, `ultron_studio_h8_bd2` (la puerta); borré `ultron_studio_h8_bd2_antes`.

**Fuera del repositorio** (desechable, en el scratchpad de la sesión):
- las copias `antes/backend` (HEAD) y `mut/backend`;
- el banco `tools/mutar-b8d.php`;
- `out/{mutaciones-b8d.json, mutaciones-b8d-2.json, mutaciones-b8d-final.json, mutaciones-b8d-final.txt, voz-sqlite.xml, studio-voz-sqlite.xml, puerta-pg.xml}`.

## §B8-d (2) · punto 7 del lead: los `codes` de los eventos en el detalle de la llamada (SEC8-b (3), O-1, funcional)

### 1. El defecto y el formato elegido

**El defecto:**
- `VoiceCallsQuery::show()` (el detalle de `GET admin/voice/calls/{uuid}`; el método se llama `show()`, no `detail()`) daba `codes` tal como se guarda: un objeto con códigos y números de turno.
- El CRM lee cada evento con `leerEvento` → `codigos()` (`studio-voice-parse.ts:1318` y `:209`): una LISTA, cada entrada con `RE_CODIGO = /^[a-z][a-z0-9_]{0,47}$/` (`:141`) y como mucho 64. Lo demás lo descarta («N eventos descartados»).
- Lo reproduje: la prueba nueva cae 4 de 4 sobre el código anterior. Los motivos son `array_is_list` falso, que es el síntoma exacto, y `codes: null` en los eventos sin códigos.

**El formato, para confirmar.** Los ejemplos del encargo (`violation:no_discounts@turno 12`, `reason:no_answer`, `+N más`) llevan `:`, `@`, espacios, `+` y tilde, y el CRM de hoy los seguiría descartando. Como el CRM no se toca, cada cadena casa `RE_CODIGO`:
- violaciones: `<código>_turno_<n>`, p. ej. `machine_reply_invented_discount_turno_12`;
- claves con su valor: `reason_no_answer`, `carrier_status_completed`, `before_active`, `after_completed`, `http_200`, `seq_3`, `micros_37700`;
- banderas: `after_cancel` y `has_audio_no`;
- lo que no cabe: `y_<n>_mas`.

El CRM las pinta tal cual (`e.codes.join(', ')`, `studio-voice-calls.ts:128`). Si se prefiere el formato con `:` y `@`, FE8 tiene que cambiar `leerEvento` a un lector de lista pintable; entonces es una línea por ficha aquí.

### 2. Qué cambió

`app/Services/Telephony/VoiceCallsQuery.php`: `eventCodes()`, que se aplica a cada evento de `show()`. Lo guardado no cambia.
- **Lista blanca de claves** (`SHOWN_KEYS`, `FLAG_KEYS`): no salen los ids del proveedor (`conversationId` y `callSid`; el registro no los da), la clase de un error (`error_class` del job que muere) ni ninguna clave desconocida.
- `reasons`: `reason_<motivo>` cada uno.
- `violations`: en orden de turno; el código se recorta si no cabe con su turno en 48.
- Lo que no tiene forma de código se omite.
- **Como mucho 50** (el CRM admite 64). Si sobran, o si hay `truncated` (E10-4), las 49 primeras y `y_<n>_mas`, con n = las que no caben + las no guardadas.
- `null` o vacío: `[]`.

### 3. Prueba y captura

`tests/Feature/Telephony/VoiceCallDetailEventsTest.php` (nueva, 4 pruebas). Todas piden el detalle por HTTP con `telephony.view` y comprueban cada evento con la regla del CRM: lista, `RE_CODIGO` y ≤ 64.
- **La captura con eventos reales.** Una post-llamada FIRMADA con 3 violaciones en los turnos 0, 2 y 4 y la conciliación con Twilio simulado dan cuatro eventos:
  - `post_call_transcription`: `[duration_seconds_40, agent_turns_3, tool_calls_0]`;
  - `guard_verdict`: `[voice_notice_missing_turno_0, voice_money_not_from_tool_turno_2, …_turno_4]`;
  - `reconciled`: `[carrier_status_completed, before_completed, after_completed]`;
  - `cost_recorded`: `[micros_37700, unit_usd]`.

  En la respuesta no aparecen el texto de la transcripción, el teléfono, el SID ni la conversación.
- 200 violaciones por la ruta firmada: 50 entradas, la última `y_151_mas`.
- La saliente cortada que el job colgó (M-3), el job que muere, una herramienta y una entrante no canaria. Resultados:
  - el `outbound_ambiguous` cortado da `[reason_server_error, http_502, after_cancel]`, sin la conversación ni el SID;
  - el `hangup_requested` del sistema da `[carrier_status_completed, http_200, after_cancel]`;
  - el job que muere da `[]`, sin `RuntimeException`;
  - la herramienta da `[]`;
  - la iniciación da `reason_<motivo>` cada uno y `publication_<estado>`.
- La normalización con valores raros: mayúsculas, guiones, ids, una clase, texto con un teléfono, un código de 78 caracteres, un turno no entero, un código que empieza por cifra. Más los bordes: 50 justas, 60, y `truncated` con pocas.

### 4. Cifras

| Qué | Resultado |
|---|---|
| Voz, telefonía y Twilio, SQLite | **786 pruebas, 202 001 aserciones, 0 fallos**, 146 omitidas |
| Studio de voz y guarda del motor, SQLite | **588, 0 fallos**, 73 omitidas |
| Puerta PG (`ultron_studio_h8_bd2`) | **1 374, 209 617 aserciones, 0 fallos**, 15 omitidas |
| `auth:coverage --unmapped` | vacío, exit 0 |
| Pint en los dos ficheros | `passed` |
| Antes del cambio | la copia con todo B8-d salvo esto: 4 de 4 caen |
| Mutaciones (misma copia aislada y mismo banco) | **14 de 14 muertas**, el control no cae, la copia vuelve a quedar idéntica |

Las 14 mutaciones: quitar la normalización, ignorar las violaciones, tope 64, olvidar `truncated` en la marca, enseñar los ids, quitar la lista blanca, recortar a 60, no reservar sitio para el turno, banderas falsas sin `_no`, marcar siempre, motivos sin `reason_`, sin `snake_case`, sin filtrar los nulos y `truncated` a 0.

### 5. Contrato para FE8

`GET admin/voice/calls/{uuid}` → `call.events[].codes` es ahora SIEMPRE una lista de códigos cortos (≤ 50, `RE_CODIGO`), `[]` sin códigos. Antes era un objeto o `null`.

**Comprobado contra una COPIA** de `RE_CODIGO` y del tope de `codigos()` en la prueba del backend; **no ejecuté** el lector del CRM. Hipótesis por verificar con un spec del CRM (FE8 o tester): el CRM de `45dd73a` lo lee sin cambios y los eventos descartados son 0.

Para la próxima captura de FE8, esta prueba da el detalle con eventos reales.

### 6. Ficheros de esta tanda

- Cambiados: `app/Services/Telephony/VoiceCallsQuery.php` y este informe.
- Nuevo: `tests/Feature/Telephony/VoiceCallDetailEventsTest.php`.
- Sin commit.

# §B8-e · gana el corte y además falla cerrado (E12-5, E13-9; revisión automática de `bff58d8`, MEDIO, «fail-open state drift»)

**Fecha:** 09-11-2026. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h8-voice`, sobre `fab99dcc` (Enmienda 13; árbol limpio al empezar) + mis cambios, **sin commit**.

**Cero red:** toda prueba con `Http::preventStrayRequests()`; Twilio, el transportista real contra `Http::fake`.

**No toqué:** guardas (`*IsolationTest`, `VoiceBoundaryTest`), `routes/`, `docs/ultron-studio/ops/**`, `docs/ultron-studio/*.md`, migraciones ni el CRM. Ni una variable nueva.

## 1. El defecto

- `VoiceReconciler::canceledStartFor()` daba por CONOCIDA una llamada que Twilio enseñaba terminada si tenía su `outbound_started`/`outbound_ambiguous` con `afterCancel` y su `callSid`. No miraba el colgado.
- La conciliación corre cada 10 minutos (`routes/console.php`) y una llamada dura como mucho 300 s, así que casi siempre la veía ya terminada. Si el colgado inmediato había fallado, la cortada duraba sus 5 minutos y quedaba conocida: sin freno y en silencio. El freno solo llegaba si la pasada la pillaba viva.
- `VoiceHangup::startedAfterCancel()` solo escribía el error en el registro.
- Si el adaptador lanzaba, la excepción se escapaba sin evento ni freno.
- Si el job moría a mitad del colgado, nadie frenaba. Pasa dentro de su plazo: 20 s del proveedor más 10 s de Twilio llegan a sus 30 s.

## 2. Cifras reales (comandos ejecutados sobre el estado final)

| Qué | Comando | Resultado |
|---|---|---|
| Voz y telefonía, SQLite | `phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice` | **704 pruebas, 197 932 aserciones, 0 fallos**, 146 omitidas (solo PG). En `fab99dcc`, el mismo comando: 692, 196 018, 0 fallos. **+12 nuevas** |
| Lo mismo, puerta PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_be bash docs/ultron-studio/ops/pgunit.sh` (las mismas 4 carpetas) | **704, 198 688 aserciones, 0 fallos**, 15 omitidas (las 15 del E2E, que corren en su arnés) |
| Regresión en lo relacionado, SQLite | `phpunit tests/Unit/Twilio`; la lista de B8-d del Studio de voz (`tests/Feature/UltronStudio/*Voice*Test.php tests/Unit/UltronStudio/*Voice*Test.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php`) | 94 OK (5 983 aserciones); 588, 6 464 aserciones, 0 fallos, 73 omitidas (idéntico a B8-d) |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, exit 0 (ninguna ruta nueva) |
| Estilo | `pint --test` en los 7 ficheros PHP tocados | `passed` |

**Antes del arreglo.** Corrí las 2 pruebas en un clon APFS de `fab99dcc` que solo difiere en `app/`, en SQLite y en PG (`ultron_studio_h8_be_antes`): **13 de 17 caen**, con 3 errores y 10 fallos. Las que demuestran el defecto caen en la aserción de comportamiento, no en el formato:
- el colgado que falla (400 y 500) y la prueba de la carrera de B8-d: `VoiceBrake::isOpen()` es `false` justo después del job;
- **el hallazgo tal cual**: dos cortadas ya terminadas, una sin colgado y otra con el colgado fallido, salen `known: 2, unknown: 0` (ahora `0, 2`);
- la viva: ningún reintento (`[]`);
- el freno ya soltado: no se rearma en el acto;
- `failed()`: ninguna apertura;
- el adaptador que lanza: la excepción se escapa, sin evento ni freno.

Las demás caen por el formato nuevo: `hung_up` frente a `requested`, la clasificación y el modo en seco. Las **4 que pasan a los dos lados lo hacen a propósito**, porque fijan lo que no cambia:
- el job que marca primero (dos pruebas);
- la ocupada que no se cuelga;
- la ambigua con el colgado confirmado.

**Mutaciones por muestreo.** Banco `<scratchpad>/tools/mutar-b8e.php`, en una copia aislada con su `vendor/`. Para cada mutación: el texto de partida aparece exactamente una vez, `php -l`, sus pruebas en SQLite, se restaura y se compara el SHA-256.

**Resultado final: 29 de 29 muertas.** Los 3 equivalentes y los 2 controles no caen. Los 34 se restauran con el SHA-256 igual, y la copia queda idéntica: SHA-256 de todos sus `.php`, antes y después.

En la primera pasada sobrevivieron 2:
- **H9** (el colgado fallido del job nunca rearma). Lo enmascaraba la conciliación, cuyo propio freno rearma. Faltaba la vía del job; ahora la cubre `test_el_colgado_fallido_del_job_rearma_en_el_acto_el_freno_ya_soltado_de_esa_llamada`.
- **R16**, que pasa a equivalente (E3, abajo).

Equivalentes, anotados y sin probar:
- **E1**: mirar el tramo antes que la cortada. Una cortada nunca es un tramo.
- **E2**: el cerrojo de `one()`. Una prueba de un hilo no lo distingue.
- **E3**: quitar «ninguna fila lleva ese SID» del reintento. Con datos posibles da lo mismo, porque cada SID sale de la respuesta del proveedor a UNA fila. Su papel es de rendimiento: una viva con su fila no lee los eventos de las cortadas.

Controles: un comentario y el nombre de una línea del registro (las pruebas no fijan los registros).

La copia del banco tiene de `VoiceBrake.php` la versión anterior a dos retoques de comentario; la línea de `TRIGGERS` que muta B1 es la misma.

**Hallazgo fuera de B8-e (no lo toqué): 4 pruebas dependen de la hora.**
- Son `VoiceReconcilerTest::test_una_pedida_vieja_que_perdio_su_job_pasa_a_ambigua_y_el_techo_de_gasto_frena` y tres de `VoiceReconcileDryRunTest`: `…una_desconocida_se_informa…`, `…el_techo_cuenta_el_estado…` y `…el_techo_cuenta_el_precio…`.
- Crean la fila con el `budget_day` de hoy en Bogotá y viajan 16 minutos. Entre las **04:44 y las 05:00 UTC** (23:44–00:00 en Bogotá) cruzan al día siguiente, y lo comprometido de ese día es 0.
- Caen igual en `fab99dcc`: lo comprobé a las 04:51 UTC. A mí me cayeron en tres ejecuciones, entre las 04:50 y las 04:59 UTC. Las cifras de arriba son de después de las 05:00.
- Arreglo propuesto (para su dueño): fijar la hora en UTC con `travelTo()`, como ya hace `VoiceBrakeRearmTest`.

## 3. Qué cambió y por qué (por fichero)

**`app/Services/Telephony/VoiceHangup.php`**
- `startedAfterCancel(VoiceCall, string $callSid, TelephonyCarrier, string $where = 'outbound_job'): string`:
  - cuelga, sin transacción ni cerrojo durante la red;
  - si el adaptador lanza, guarda su clase y no relanza (§5.5). La excepción: `StrayRequestException`, que solo existe en las pruebas y se relanza para que se vea, como en el adaptador;
  - clasifica con `afterCancelResult()`;
  - en UNA transacción escribe el `hangup_requested` del sistema y, si el corte no se confirma, `VoiceBrake::openOrRearm('hangup_failed', 'unknown:<sid>', {where, outcome, http}, observedAfter(instante antes del intento), fila, evento)`;
  - registro: `voice.hangup.after_cancel` (warning) o `…_failed` (error, con la clase si lanzó).
- El evento: `outcome` es `hung_up`, `not_found`, `failed` o `ambiguous`. `codes` gana `callSid` y `carrierOutcome`, `error_class` si lanzó y `where: reconcile` si lo llama la conciliación.
- `afterCancelResult()`: la tabla cerrada; lo que no se conoce es `failed`.
- `AFTER_CANCEL_CONFIRMED = [hung_up, not_found]`.
- `brakeKey()`: `unknown:<sid>`, la clave de la conciliación.
- `lastAfterCancelResult(fila, sid)`: el último colgado del sistema de esa cortada con ese SID.
- `brakeIfUnconfirmedAfterCancel(fila)`: la usa `failed()`.

**`app/Jobs/Voice/PlaceOutboundVoiceCall.php`**
- `failed()`, después de su línea de registro, llama a `brakeIfUnconfirmedAfterCancel()`. El caso: el job muere o agota su plazo a mitad del colgado, con una cortada cuyo SID está en el evento y sin colgado confirmado.
- Ese freno se echa en el acto, con la misma clave y la evidencia `{where: outbound_job, outcome: job_failed, http: null}`.
- `record()` no cambia (ya llamaba a `startedAfterCancel()`); solo su docblock.

**`app/Services/Telephony/VoiceReconciler.php`**
- `reconcileCall()` (nuevo; `run()` lo usa con cada llamada). Si Twilio la enseña VIVA, ninguna fila lleva su SID y es una cortada con `afterCancel`:
  - la vuelve a colgar FUERA de la transacción de la llamada, con `startedAfterCancel(…, 'reconcile')`, que frena si no se confirma;
  - después, `one()` en su transacción, como siempre;
  - en seco no cuelga: suma `would.events.hangup_requested`.
- `one()`: la cortada es CONOCIDA solo si su ÚLTIMO colgado se confirmó. Si no:
  - sigue el camino de una desconocida: `unknown_provider_call`, `unknown++`, el comando sale con 1;
  - echa el freno `hangup_failed` sobre `unknown:<sid>`, con la fila y el evento. Terminada, con `open()`: cada clave, una vez. Viva, con la regla de rearme por observación; de verdad ya la aplicó el reintento, y en seco se anuncia;
  - **deja su precio en su fila**.
- `one()` ya no mira la vía de los tramos para una cortada.
- `canceledStartFor(string $sid, bool $lock = true)` ya no exige que esté terminada: lo decide el colgado. Sin cerrojo, para el reintento.
- `afterCancelResult()`: en seco y con la llamada viva, `null` (cuenta como si fallara).
- `brake(…, ?int $callId)`.
- Docblock: su única escritura a un proveedor.

**`app/Services/Telephony/VoiceBrake.php`**
- `TRIGGERS['hangup_failed'] = 'unknown_provider_call'`, como `number_drift` → `agent_drift` (E4-8).
- `vbr_reason_chk` no cambia, y `TelephonyFixtureParityTest` tampoco: los 6 motivos, en el mismo orden.
- Docblocks: qué causas se rearman.

**`app/Console/Commands/Voice/VoiceReconcileCommand.php`**: docblock y `description`, que ahora dicen «GET, salvo volver a colgar una cortada que sigue viva». Ninguna prueba los fija.

**`tests/Feature/Telephony/PlaceOutboundVoiceCallHangupFailClosedTest.php`** (nueva, 12 pruebas). Usa el transportista real contra `Http::fake`, con una sonda del nivel de transacción en cada colgado (1 = fuera de toda transacción de la aplicación). Cubre:
- el colgado que falla (400): freno en el acto y `outcome=failed`. Después la conciliación: desconocida, sin duplicar el freno, el precio en su fila, otra pasada sin nada nuevo y el comando con 1;
- el hallazgo tal cual (§2);
- cada resultado del colgado: 500 y sin conexión dan `ambiguous`; 400, 401 y 403 dan `failed`, igual que la falta de credencial (sin red). Todos frenan. 404 (`not_found`) y 200 (`hung_up`) no frenan;
- el adaptador que lanza: no se traga, frena y no filtra el mensaje. La petición perdida de una prueba sí se relanza;
- el colgado confirmado y la llamada que ya no existe: sin freno, y la conciliación las reconoce;
- la viva y el colgado que vuelve a fallar: reintento fuera de la transacción, freno, sin duplicar en la segunda pasada, rearme `:r2` tras soltarlo y, terminada, desconocida con su precio;
- el rearme en el acto desde el job;
- la viva que esta vez se cuelga: conocida y sin freno nuevo; manda el último colgado. También la viva con el colgado del job confirmado (la lista de Twilio con retraso);
- **el job que marca primero: nada cambia**, también cuando Twilio no deja cortar por la vía de siempre (ni freno ni reintento sobre una llamada con su fila);
- `failed()`: con colgado confirmado no frena; una pedida o una cortada sin respuesta del proveedor, tampoco;
- en seco: no cuelga, cuenta como fallida, anuncia el rearme y la divergencia documentada (§5.7);
- la tabla de la clasificación.

**`tests/Feature/Telephony/PlaceOutboundVoiceCallHangupRaceTest.php`** (de B8-d)
- La 1: `hung_up` en vez de `requested`, los códigos con `callSid` y `carrierOutcome`, y «sin freno» justo después del job.
- La 3, reescrita y con otro nombre. Fijaba justo el comportamiento del hallazgo («cuando Twilio ya la da por terminada, es conocida»). Ahora fija: freno en el acto, `ambiguous`, el reintento de la viva sin duplicar el freno y, ya terminada, desconocida con su precio en la fila.

## 4. Contratos nuevos o modificados (para FE8, OPS8, DOC8 y tester)

**El `hangup_requested` del sistema con `codes.afterCancel`**
- `outcome` es `hung_up`, `not_found`, `failed` o `ambiguous`. Antes era lo que dijo el transportista (`requested`, `error`…), que ahora va en `codes.carrierOutcome`.
- `codes` gana `callSid`, `carrierOutcome` y, si el adaptador lanzó, `error_class`. Los tres quedan fuera de la lista blanca del detalle.
- El reintento de la conciliación lleva `where: reconcile`, que **sí** sale en el detalle, como `where_reconcile`.
- En el detalle de la llamada (`GET admin/voice/calls/{uuid}`), el colgado del job sigue dando `[carrier_status_completed, http_200, after_cancel]` (`VoiceCallDetailEventsTest`, sin tocar).
- El CRM pinta `outcome` tal cual (`studio-voice-calls.ts:128`): no necesita cambios. Las etiquetas en castellano, si se quieren, son de FE8.
- El `hangup_requested` de una persona no cambia (`VoiceHangup::hangup()`, desde el CRM y `voice:hangup`).

**El freno**
- Causa nueva `hangup_failed` → motivo `unknown_provider_call`, sin cambio de esquema.
- Clave `unknown:<sid>`, la de la conciliación: el job, el reintento y la conciliación no duplican la apertura.
- `call_id`: la fila cortada. `event_id`: el colgado o la desconocida.
- Evidencia `{trigger: hangup_failed, where, outcome, http}`; en la conciliación, `{trigger, where: reconcile, carrierStatus, outcome}`.
- Se rearma (`:r2`…) solo cuando un colgado posterior a la suelta vuelve a fallar.
- El CRM lee de la evidencia solo `codes`. El incidente de IRON GUARD es el de `unknown_provider_call`.

**`voice:reconcile`**
- Una cortada terminada sin colgado confirmado cuenta como `unknown` (antes, `known`), con su `unknown_provider_call`. Su precio va a su fila.
- **El comando sale con 1 en cada pasada mientras Twilio la liste (48 h)**, como cualquier desconocida.
- Con la llamada viva hace un **POST a Twilio**: la conciliación ya no es «solo GET».
- `--dry-run` no cuelga, suma `would.events.hangup_requested` y la cuenta como si el colgado fallara.

**Firmas internas**, compatibles hacia atrás:
- `startedAfterCancel(…, string $where = 'outbound_job')` devuelve el resultado clasificado.
- Nuevas en `VoiceHangup`: `afterCancelResult()`, `brakeKey()`, `lastAfterCancelResult()`, `brakeIfUnconfirmedAfterCancel()` y sus constantes (`AFTER_CANCEL_*`, `WHERE_*`, `BRAKE_CAUSE`, `AFTER_CANCEL_START_KINDS`).
- Nueva y privada: `VoiceReconciler::reconcileCall()`.

## 5. Decisiones tomadas (para confirmar)

1. **La clasificación.** Confirman el corte solo `hung_up` (Twilio aceptó `completed`) y `not_found` (404).
   - `failed`: un 4xx (también el 400 con 21220, «Call is not in-progress»), un 401/403, la falta de credencial o un SID sin forma.
   - `ambiguous`: un 5xx, sin conexión o tiempo agotado, o el adaptador que lanza. Es la convención del job: un 5xx no prueba que no pasó.
   - Las dos frenan.
   - El 21220 podría querer decir «ya terminó». La documentación oficial no lo dice para `Status=completed`: la página del error 21220 solo nombra grabar o redirigir.
   - Darlo por confirmado fallaría abierto si viniera de una llamada viva que Twilio no deja cortar. Se calibra en la fase 4a, con respuestas reales.
2. **Manda el ÚLTIMO colgado.** El encargo dice «existe un colgado con éxito»; yo tomo el más reciente. Un reintento que falla anula un «colgada» anterior, porque la llamada seguía viva. Solo cambia ese caso, y en el sentido seguro.
3. **El precio va a su fila aunque sea desconocida.** «Como antes de B8-d» habría perdido el coste de la llamada más cara, la que duró su máximo: el techo de gasto la contaría en 0. La fila cortada cuenta lo real (E10-8, E12-3). Si se prefiere sin precio, es una línea.
4. **`failed()` también frena.** Es el «proceso que muere» del hallazgo mientras el job sigue vivo: el plazo se agota a mitad del colgado. Sin esto, frenaba la conciliación (≤ 10 min). Un `kill -9` sigue esperando al `failed()` del siguiente worker (`retry_after`, 120 s) o a la conciliación.
5. **El adaptador que lanza no se relanza.** Su clase queda en el evento y en el registro, pero no el mensaje, que puede llevar la credencial. En la conciliación, relanzar dejaría la pasada sin el resto: las demás llamadas, la deriva y el techo.
6. **El reintento de la viva.**
   - Se hace siempre que Twilio la enseñe viva, aunque el colgado anterior se confirmara.
   - Si se confirma, es conocida y no hay freno nuevo, como pide el encargo; el freno del job, si lo hubo, sigue echado.
   - Si una pasada la ve viva justo después de un colgado bueno (la lista de Twilio con retraso), la vuelve a colgar. Si Twilio responde 21220, frena: un falso positivo, en el sentido seguro.
7. **En seco no se cuelga.** Una cortada viva cuenta como si el colgado fallara. Es la única diferencia con la pasada de verdad, y la prueba la fija: de verdad, si el colgado se confirma, es conocida.
8. **Evento y freno en una transacción**: o los dos o ninguno. El incidente se abre después de confirmar.

## 6. Para tester (casos límite desde dentro)

- **Dos procesos sobre la misma cortada.** El job, a mitad de su colgado, y una conciliación que la ve viva: dos POST (colgar es idempotente) y dos eventos. El UNIQUE de `open_key` deja una sola apertura. Si el de la conciliación falla y el del job no, el freno se queda (falso positivo, en el sentido seguro).
- **El 21220 al reintentar** una llamada que acaba de terminar entre la lista y el POST: freno.
- **Viva y Twilio responde `requested` pero no la cuelga.** Cada pasada lo reintenta, una vez cada 10 min. Sin freno, porque se confirma; lo acota el máximo de 300 s.
- **Una pasada en seco justo después de soltar el freno**: anuncia el rearme si la llamada sigue viva (probado).
- **Reloj**: las pruebas nuevas fijan la hora en UTC. Las 4 del §2, no.
- **El E2E** (`H8E2EAbiertaTest`) no recorre este caso: su corte es el de una viva (`hung_up`, `emergency_hangup`), que no cambia. No lo ejecuté, porque necesita su arnés, sus puertos y los simuladores.

## 7. Riesgos residuales y pendientes

- **`voice:hangup` sigue sin cortar a mano una fila `canceled`**, porque su SID está en el evento. El pendiente de E12-5 queda a medias: el freno ya es inmediato y la conciliación reintenta cada 10 min.
- **Lo que hay que poner al día y no toqué:**
  - lo que dice «solo GET»: `routes/console.php:426` y `ULTRON_STUDIO_H8_VOZ.md:90`, `:147` y `:279`;
  - el texto de E12-5 («Pendiente para la fase 4a…»);
  - el runbook: un freno `unknown_provider_call` con `trigger: hangup_failed` es una cortada que pudo durar su máximo, no una llamada ajena. Se reconoce por `call_id` y por el detalle de esa llamada.
- **E13-3** es el punto 2 de B8-e según el contrato: `missingPostCall` en `StudioVoiceEvidence`. No estaba en mi encargo («una sola corrección») y **no lo hice**. Toca el Studio y lo que lee el CRM (`studio-publication-text.ts`, `CRITERIOS_CONOCIDOS`). Queda a la espera de su encargo.
- **Las 4 pruebas que dependen de la hora** (§2).

## 8. Migración, configuración y despliegue

- **Migraciones:** ninguna. **Variables:** ninguna.
- **Operación:** la conciliación programada puede hacer un POST a Twilio para colgar, con la misma key del corte de emergencia. Solo lo hace con una cortada viva y con la subcuenta configurada; sin credencial, no sale a la red.
- Si OPS8 alerta por el código de salida, avisará durante 48 h por una cortada terminada sin colgado confirmado: la pasada sale con 1 mientras Twilio la liste.

## 9. Ficheros

**Cambiados:**
- `app/Services/Telephony/{VoiceHangup,VoiceReconciler,VoiceBrake}.php`
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`
- `app/Console/Commands/Voice/VoiceReconcileCommand.php` (docblock y `description`)
- `tests/Feature/Telephony/PlaceOutboundVoiceCallHangupRaceTest.php`
- este informe

**Nuevo:** `tests/Feature/Telephony/PlaceOutboundVoiceCallHangupFailClosedTest.php`.

**Sin commit.**

**No es mío:** `docs/ultron-studio/ops/h8-e2e-crm/`, sin seguimiento (el arnés del E2E del CRM).

**Bases PG:** queda `ultron_studio_h8_be` (la puerta); borré `ultron_studio_h8_be_antes`.

**Fuera del repositorio** (desechable, en el scratchpad de la sesión):
- las copias `antes/backend` (`fab99dcc`) y `mut/backend`;
- el banco `tools/mutar-b8e.php`;
- `out/` (resultados JSON y JUnit de cada ejecución).

---

# §B8-g · salientes a canarios antes que entrantes (E4-6, E13-3, número «Outbound Only», cuenta de Twilio, reloj de 4 pruebas, `voice:hangup` sobre cortadas)

**Fecha:** 09-11-2026. **Árbol:** worktree `ultron-h8`, rama `feat/ultron-studio-h8-voice`, sobre `ab54a256` (Enmienda 15) + mis cambios, **sin commit**.

**Árbol compartido:** S8-d edita a la vez `app/Services/UltronStudio/**` (`StudioVoiceEvidence`, `StudioVoicePublishing`, `StudioVoicePublications` y sus pruebas), y el tester los E2E. No toqué nada suyo.

**Cero red:** toda prueba con `Http::preventStrayRequests()`. ElevenLabs y Twilio, los adaptadores reales contra `Http::fake`.

**No toqué:**
- las guardas (`*IsolationTest`, `VoiceBoundaryTest`, `OtpUntouchedByVoiceTest`, `TwilioReadOnlyClientTest`);
- `routes/`, migraciones, `app/Services/UltronStudio/**` ni el CRM;
- el simulador de `docs/ultron-studio/ops/banco-h8/`, que es de OPS8.

**Coordinación sin `SendMessage`:** en este contexto no tengo herramienta de mensajes. Todo lo que el lead y S8-d necesitan está en este informe (§3, §8) y en el mensaje final.

## 1. Cifras reales (comandos ejecutados sobre el estado final)

| Qué | Comando | Resultado |
|---|---|---|
| Voz y telefonía, SQLite | `phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio` | **829 pruebas, 207 604 aserciones, 0 fallos**, 156 omitidas (solo PG y E2E) |
| Lo mismo, puerta PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bg bash docs/ultron-studio/ops/pgunit.sh` (las mismas 5 carpetas) | **829, 208 360 aserciones, 0 fallos**, 25 omitidas (todas E2E: 9 de la fase abierta, 6 de la cerrada y 10 de `H8E2ESalientesPrimeroTest`) |
| Studio relacionado, SQLite (con el trabajo de S8-d tal como estaba) | `phpunit tests/Feature/UltronStudio/UltronStudioIntegrationsConfigTest.php UltronEngineIsolationTest.php UltronStudioEngineReadOnlyTest.php` + `tests/{Feature,Unit}/UltronStudio/*Voice*Test.php` | **646, 9 671 aserciones, 0 fallos**, 73 omitidas (solo PG); última pasada, con el árbol de S8-d tal como estaba al final |
| Ejemplo de entorno | `phpunit tests/Feature/Security/SecretExposureTest.php` + `UltronStudioMonitorTest`, `UltronStudioPublicationConfigTest`, `UltronStudioIntegrationsConfigTest` | 59, 517 aserciones, 0 fallos |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, salida 0 (ninguna ruta nueva) |
| Estilo | `pint --test` en los 22 ficheros PHP tocados | `passed` |

**Línea base (antes de tocar nada).** Copia APFS del árbol al empezar, con su `vendor/` copiado. El mismo comando de SQLite dio **798 pruebas, 1 fallo**: `VoiceEvidenceTest::test_el_studio_la_lee…`.
- No era un fallo mío: S8-d ya exigía 7 claves en `StudioVoiceEvidence::validMeasures()` y la telefonía daba 6, así que `measure()` devolvía null.
- Mi punto 2 lo cierra.
- De **798 a 829** hay +21 pruebas mías y +10 del tester (`H8E2ESalientesPrimeroTest`, nuevo, que se omite fuera de su arnés).

**Antes del arreglo (regresión).** Copié mis 8 ficheros de prueba nuevos o cambiados a la copia de antes y los ejecuté allí: **44 pruebas, 23 caen (10 errores y 13 fallos)**. Las 21 que pasan en los dos lados lo hacen a propósito, porque fijan lo que no cambia:
- `VoiceTwilioAccountTest` (2): el punto 4 es una verificación, no un cambio;
- `VoiceNumberDriftTest`: las 4 de antes y «un agente ajeno en un número solo saliente sigue siendo deriva»;
- el resto, las pruebas previas de esos ficheros.

**Las 4 pruebas que dependían de la hora.** Usé una extensión de PHPUnit del banco (`tools/RelojFijo.php`) que fija el reloj DESPUÉS del `setUp` al instante de `B8G_RELOJ`:
- con las pruebas viejas, a las 04:50 UTC **caen las 4**, y a las 15:00 pasan;
- con las nuevas pasan las 4 a las 04:50, a las 04:59:30 y a las 04:44 del día siguiente.

**Mutaciones por muestreo.** Banco `tools/mutar_b8g.py`, en una copia aislada con su `vendor/`. Para cada mutación: el texto aparece exactamente una vez, `php -l`, sus pruebas en SQLite, se restaura y se compara el SHA-256.
- **Resultado final: 30 de 30 muertas**; los 2 controles (comentarios) sobreviven; 32 de 32 restauradas.
- La copia queda idéntica: SHA-256 de todos los `.php` de `app/` y `config/`, antes y después.
- En la primera pasada sobrevivió **V8**: el comando del Studio ignoraba que la caché no aceptara la marca. Faltaba la prueba; ahora la cubre `test_si_la_cache_no_acepta_la_marca_el_comando_lo_dice_y_sale_con_1`.
- **C2** y **H7** estaban mal escritos en el banco (uno no compilaba y el otro tenía el ancla repetida); corregidos, caen.

Lo que muta cada familia:
- **V** (verificación): leer siempre `true`, el borde `<`→`<=`, la holgura del futuro, no borrar al fallar, escribir con cualquier resultado, `$resta <= 0`, el formato de ida y vuelta y el código de salida.
- **E** (evidencia): `calls` sin `guard_verdict`, sin `NEVER_CONNECTED`, sin `call_initiation_failure`, sin `NOT_EVIDENCE` y `failed` fuera de `NEVER_CONNECTED`.
- **N** (número): exigir `assigned_agent`, convertir `supports_inbound` a booleano, la invariante del DTO, solo lo declarado, solo lo observado, sin mirar el agente y sin informar `inboundCapable`.
- **H** (corte): sin la rama de la cortada, sin «ya confirmado», solo el colgado del sistema, sin la ventana, sin traducir el 404, `--all` sin cortadas y el actor perdido.
- **C** (configuración): el vacío y lo ilegible.
- **S** (estado): sin `providerVerification`.

En SQLite las 156 omitidas son 131 de solo PG y las 25 E2E.

## 2. Qué cambió y por qué (por fichero)

### Punto 1 · E4-6: el lector de la verificación

**`app/Services/Telephony/VoiceProviderState.php`**
- Antes, `verifiedRecently()` devolvía siempre `false`. Ahora lee la marca `voice:elevenlabs:verified_at`, con la regla del Studio (`StudioIntegrations::studioVoiceState()`):
  - es un instante ISO 8601 en UTC, al segundo;
  - vale si tiene menos de 30 días (estricto, `VERIFIED_MAX_AGE_SECONDS` = 2 592 000, el mismo umbral que `StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS`).
- Falla cerrado. Cualquiera de estos casos da `UNVERIFIED`:
  - sin marca;
  - caducada;
  - ilegible (otra forma, otro formato, otro tipo o una fecha imposible: se exige la ida y vuelta exacta del formato);
  - del futuro, más allá de 5 minutos de holgura de relojes.
- `verification()` da `{state: valid|expired|missing|invalid, verifiedAt, expiresAt}` para el doctor y el estado.
- `recordStudioVerification(bool $ok, $verifiedAt)` es la **única escritura**:
  - `ok` escribe el instante de la evidencia con su vida restante;
  - otro resultado **borra** la marca (manda la última, como en el Studio);
  - una evidencia ya caducada borra también;
  - devuelve `written`, `cleared` o `not_written` (la caché no la aceptó).

**`app/Console/Commands/UltronStudio/IntegrationsSnapshotCommand.php`** (`--verify-voice`; solo la marca, como pidió el lead)
- Con la evidencia ya guardada (`recordVoiceVerification`), llama a `recordStudioVerification(outcome === 'ok', created_at de la evidencia)`. Así la ventana de 30 días es la misma en los dos.
- Sin evidencia guardada, la marca no se toca. Con la voz fuera de `UNVERIFIED`, ni se llama ni se toca.
- JSON: `voice.telephonyMark`.
- Sale 0 **solo** si las credenciales valen y la marca quedó escrita. El texto de éxito conserva el prefijo «credenciales válidas; evidencia guardada».

**`app/Services/Telephony/VoiceTelephonyStatus.php`**
- `providerVerification` (detrás de `provider`) y `number.inboundCapable` (lo declarado, punto 3).
- Afecta a `voice:doctor --telefonia --json` y a `GET /api/admin/voice/status`.

**`app/Console/Commands/Voice/VoiceDoctorCommand.php`**
- Nueva línea `Verificado : 2026-11-09T15:00:00Z (vale hasta 2026-12-09T15:00:00Z)`. Si no vale: «CADUCADA el …», «marca ilegible» o «sin marca: las salientes dicen elevenlabs_unverified (repite ultron-studio:integrations-snapshot --verify-voice)».
- En `Número`, «(solo salientes: VOICE_NUMBER_INBOUND_CAPABLE=false)».
- El doctor sigue sin leer configuración ni caché (la guarda): todo sale de `VoiceTelephonyStatus::snapshot()`.

**Por qué la telefonía escribe y lee, y no el comando con un literal.** El lead propuso `Cache::put('voice:elevenlabs:verified_at', …)` en el comando.
- Lo dejé en un método de la telefonía para que la clave, el formato, la vida y la regla de lectura vivan en un solo sitio.
- El comando (fuera de `StudioVoice*`, en la lista blanca del Studio) solo le dice qué pasó.
- Ninguna guarda cae (`TelephonyIsolationTest` vigila `StudioVoice*`, no los comandos del Studio).
- Si se prefiere el literal en el comando, es una línea.

### Punto 2 · E13-3: `missingPostCall`

**`app/Services/Telephony/VoiceEvidence.php`**
- `KEYS` y `forPublication()` llevan **siete claves**; la séptima, `missingPostCall`, va la última.
- Las llamadas que cuentan son las selladas con la entrada y la parte, sin tramos y fuera de `NOT_EVIDENCE` (E6-2). Entre ellas:
  - **`calls`** cuenta solo las que tienen `guard_verdict` (su post-llamada procesada, `POST_CALL_PROCESSED`);
  - **`missingPostCall`** cuenta las que no tienen ni `guard_verdict` ni `call_initiation_failure` (`POST_CALL_NO_CONVERSATION`) y no están en `NEVER_CONNECTED` = `busy`, `no_answer` y `failed`, que nunca conectan.
- Las `active`, `dialing` y `ambiguous` cuentan como que falta hasta que llega su post-llamada o la resuelve la conciliación (transitorio, en el sentido seguro).
- Una `hung_up` con `call_initiation_failure` (cortada mientras sonaba) no falta. Sin nada, falta.
- La consulta es un semi-join por llamada (`whereExists` / `whereNotExists` sobre `voice_call_events`), sin traer ids a PHP.
- `summary()` (métricas) no cambia.

### Punto 3 · el número «Outbound Only»

**`config/voice.php`** y **ejemplo de entorno**
- Nueva variable `VOICE_NUMBER_INBOUND_CAPABLE` → `voice.number.inbound_capable`.
- Es la clave que **ya lee S8-d** (`StudioVoicePublishing::NUMBER_INBOUND_CAPABLE_KEY`) para no asignar el número al publicar. `config/voice.php` es de B8, así que la añado yo.
- Es una **declaración sin defecto**, no una bandera:
  - vacía o ilegible → `null` (S8 asigna como siempre);
  - `true/1/on/yes` → `true`;
  - `false/0/off/no` → `false`.
- Con `null` por defecto ningún booleano nace encendido y `VoiceBoundaryTest::test_todo_apagado_por_defecto` (protección 1) sigue en verde. Con `true` por defecto habría caído.
- Nuevo cierre `$declarado`, junto a `$entero`.

**`app/Services/Voice/Dto/VoiceNumberAssignment.php`**
- `?bool $inboundCapable = null`, el último parámetro: compatible con todo `new` previo.
- Solo un número observado lo lleva (invariante).
- Va en `toArray()`.

**`app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php`** (`describeNumber`; el mismo GET, la misma ruta y sin cambiar verbos)
- `assigned_agent` **ausente** = sin asignar, como el `null`. Antes era `error/invalid_response`, y la referencia oficial lo marca opcional.
- Un valor con otra forma sigue siendo ilegible.
- `supports_inbound` booleano → `inboundCapable`. Sin el campo o con otra forma, `null`.

**`app/Services/Telephony/VoiceReconciler.php`** (`numbers()`)
- Nuevo resultado `outbound_only` (`NUMBER_OUTBOUND_ONLY`). Se da cuando el número observado **no tiene agente** y es solo saliente: declarado `false` **o** con `supports_inbound=false`. No es deriva y no abre el freno.
- En ese modo se espera «sin asignar»: **cualquier** agente asignado, también el publicado, es deriva y freno. Lo pidió SEC8-c M-2 y lo cerré en §B8-g (2); antes, el publicado contaba como bien.
- Un número que admite entrantes, o que no lo dice, sin el agente publicado sigue siendo deriva.
- El resumen gana `inboundCapable` (lo observado) e `inboundDeclared` (lo declarado).

**`app/Console/Commands/Voice/VoiceReconcileCommand.php`**
- El texto dice «solo salientes: sin agente, nadie atiende entrantes (no es deriva)».
- El código de salida no cambia: `outbound_only` sale 0.

### Punto 5 · el reloj de las 4 pruebas

- En `VoiceReconcilerTest` (1) y `VoiceReconcileDryRunTest` (3), la primera línea es `travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'))`: `Carbon::setTestNow` en UTC, como `VoiceBrakeRearmTest` y la memoria del proyecto.
- No cambia nada de lo que prueban.

### Punto 6 · `voice:hangup` sobre cortadas y el 21220

**`app/Services/Telephony/VoiceHangup.php`**
- `hangup()` sobre una fila `canceled` va a `hangupCanceled()`.
  - Si el proveedor la inició después del corte (`afterCancelSid()`: el SID del último `outbound_started`/`outbound_ambiguous` con `afterCancel`) y su último colgado no se confirmó, **repite el colgado** con `WHERE_MANUAL = 'manual'` y la persona si la hay.
  - Si tampoco se confirma, frena o rearma el freno ya soltado (`VoiceBrake::observedAfter`), como el job y la conciliación.
  - Un 404 al repetirlo es «ya no existe»: `not_live`.
  - Sin SID, o con el colgado ya confirmado, `not_live` sin red.
- `startedAfterCancel(…, ?Admin $actor = null)`: la persona queda en el evento. El cuerpo pasa a `afterCancelHangup()`, que devuelve también lo que dijo el transportista.
- `lastAfterCancelResult()` cuenta el último colgado con `afterCancel` **de cualquier actor**, no solo del sistema. Solo `afterCancelHangup()` escribe ese evento; si no, el colgado confirmado desde el CRM no lo reconocería la conciliación.
- `unconfirmedAfterCancel()`: las cortadas de la última hora (`AFTER_CANCEL_LIVE_WINDOW_MINUTES` = 60, cuando una llamada vive ≤ ~12 min) sin colgado confirmado.
- `brakeIfUnconfirmedAfterCancel()` reutiliza `afterCancelSid()`.
- Docblock de `afterCancelResult()` con la calibración del 21220 (§6).

**`app/Console/Commands/Voice/VoiceHangupCommand.php`**
- `--all` suma `unconfirmedAfterCancel()`.
- Docblock y ayuda.

### Pruebas

**Nuevas**
- `VoiceProviderVerificationTest` (7):
  - la regresión de E4-6 por la puerta real: `['calls_disabled_in_code','elevenlabs_unverified']` → `['calls_disabled_in_code']`;
  - la paridad con el Studio en el borde de los 30 días;
  - la que falla borra la marca (401 y sin conexión);
  - la caché que no la acepta;
  - la voz fuera de `UNVERIFIED`;
  - las marcas ilegibles, del futuro y el borde con la marca aún en caché;
  - el doctor y `GET status`.
- `VoiceHangupCanceledTest` (5):
  - la regresión (21220 en el job → `voice:hangup <uuid>` la cuelga → la conciliación la reconoce);
  - `--all` solo con las que aún pueden estar vivas;
  - el colgado a mano que falla (rearme `:r2` y salida 1);
  - el CRM con su persona;
  - sin nada que colgar (también el 404).
- `VoiceTwilioAccountTest` (2): punto 4.

**Cambiadas** (cambio de contrato legítimo, todas mías)
- `VoiceEvidenceTest`:
  - siete claves y recuentos nuevos;
  - la de integración siembra su `guard_verdict`;
  - +2: el escenario M-3 tal cual y estado a estado.
- `VoiceNumberDriftTest`: +4 (solo saliente observado, declarado, agente ajeno y lo de siempre).
- `ElevenLabsNumberObserverTest`:
  - el cuerpo sin `assigned_agent` pasa de ilegible a sin asignar (esquema oficial);
  - +1 del número solo saliente;
  - 2 formas imposibles más del DTO.
- `ElevenLabsConfigTest`: la forma de `config/voice.php` con `number` y las 12 lecturas de la declaración.
- `VoiceCommandsTest`: las claves de `telefonia` con `providerVerification` y `number.inboundCapable`.
- `VoiceReconcilerTest` y `VoiceReconcileDryRunTest`: el reloj (punto 5).

## 3. Contratos nuevos o modificados (FE8, S8-d, OPS8, DOC8, tester)

**Para S8-d / lead (E13-3)**
- `VoiceEvidence::forPublication()` devuelve exactamente, en este orden: `calls, guardViolations, sealMismatches, driftEvents, unknownProviderCalls, budgetBreaches, missingPostCall`.
- Coincide con `StudioVoiceEvidence::MEASURE_KEYS` de S8-d tal como está ahora en el árbol. Con los dos cambios, `VoiceEvidenceTest::test_el_studio_la_lee…` compara las dos con `assertSame` y pasa.

**Para S8-d (número)**
- `voice.number.inbound_capable` existe ya en `config/voice.php`, con la semántica de su docblock: solo un `false` explícito.
- La conciliación lo usa igual que S8 (§2, punto 3).

**`GET /api/admin/voice/status`** y `voice:doctor --telefonia --json` (aditivo)
- `providerVerification: {state, verifiedAt, expiresAt}`.
- `number.inboundCapable: true|false|null`.
- El lector del CRM (`studio-voice-parse.ts`) toma solo sus claves: no se rompe. Pintarlas es de FE8.

**`ultron-studio:integrations-snapshot --verify-voice`**
- `voice.telephonyMark: written|cleared|not_written|null`.
- **Sale 1 si la marca no se escribe**, aunque las credenciales valgan.

**`voice:reconcile --numbers`**
- Resultado nuevo `outbound_only`, que sale 0.
- El resumen gana `inboundCapable` e `inboundDeclared`.

**`VoiceNumberAssignment`**: `inboundCapable` (`?bool`) y su clave en `toArray()`.

**`voice:hangup` y `POST /api/admin/voice/calls/{uuid}/hangup` sobre una `canceled`**
- Pueden hacer **un POST a Twilio** (antes nunca).
- `result` puede ser `hung_up`, `failed`, `ambiguous` o `not_live`. El CRM sigue con `ok = result ∈ {hung_up, canceled}`.
- El evento `hangup_requested` lleva `where: manual`, que en el detalle sale como `where_manual` (`SHOWN_KEYS` ya admite `where`).

**`VoiceHangup::lastAfterCancelResult()`**: cuenta también el colgado de una persona.

## 4. Decisiones tomadas (para confirmar)

1. **Paridad exacta con el Studio** en la verificación:
   - la última manda (una que falla borra);
   - 30 días estrictos;
   - el instante es el `created_at` de la evidencia.
   - No la ato a la huella de la clave, porque el Studio tampoco. Si se rota la clave, `VERIFIED` sigue hasta la siguiente verificación, en los dos; la llamada con una clave mala da `failed_to_start` (4xx), sin salir nada.
2. **`missingPostCall`**:
   - `call_initiation_failure` cuenta como post-llamada procesada «sin conversación»;
   - `busy`, `no_answer` y `failed` nunca faltan, aunque las cierre la conciliación sin webhook. Si el webhook está caído, esas no suman a `calls`: promover se niega igual por `too_few_calls`;
   - `seal_verified` no entra en `calls`, porque el texto de E13-3 dice «con `guard_verdict`». Un sello que no cuadra ya cuenta en `sealMismatches`.
3. **`outbound_only` sin agente y por cualquiera de las dos vías** (lo declarado o lo observado), porque `supports_inbound` está obsoleto. En ese modo cualquier agente asignado es deriva (SEC8-c M-2, §B8-g (2)).
4. **`assigned_agent` ausente = sin asignar**, como dice la referencia oficial (opcional). Un valor malformado sigue siendo error.
5. **La ventana de `--all` es de 60 minutos.** Más atrás la llamada ya no puede estar viva, y repetir el colgado de una vieja podría dar 21220 y rearmar el freno sin motivo.
6. **Un colgado manual sin credencial de la subcuenta frena**, igual que el del job (B8-e: `not_configured` → `failed`).
7. **La declaración nace `null`**, no `true`, para respetar la protección 1. Para S8-d es lo mismo (`!== false`).

## 5. Punto 3: lo que exige EXACTAMENTE la primera saliente a un canario

Leído del código: puerta, job, iniciador y publicación.

**Código**
- El commit `voice(calls-open)`: `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = true`.
- Hoy cierra la puerta (`calls_disabled_in_code`), el job y `ElevenLabsCallStarter` (`not_attempted`).

**La puerta (`OutboundGate` → `OutboundPolicy`)**

| Variable / hecho | Motivo si falta |
|---|---|
| `VOICE_OUTBOUND_ENABLED=true` | `outbound_disabled` |
| `VOICE_OUTBOUND_SCOPE=canary` (el defecto) | — |
| `TWILIO_VOICE_ENABLED=true` | `twilio_voice_disabled` |
| `TWILIO_VOICE_FROM` = el E.164 del número saliente, distinto de `WHATSAPP_DISPLAY_PHONE` y de `WHATSAPP_PROTECTED_NUMBERS` | `no_voice_number`, `voice_number_is_whatsapp` |
| `ELEVENLABS_ENABLED=true`, `ELEVENLABS_API_KEY`, `ELEVENLABS_AGENT_ID` (el de verificación), `ELEVENLABS_WEBHOOK_SECRET`, `ELEVENLABS_REGION` | `elevenlabs_not_configured` o `elevenlabs_disabled` |
| La marca de verificación de menos de 30 días: `php artisan ultron-studio:integrations-snapshot --verify-voice` (un GET). Repetirla tras cualquier `cache:clear`/`optimize:clear` y antes de que caduque (la tarea semanal la mantiene con `VOICE_MONITOR_ENABLED=true`) | `elevenlabs_unverified` |
| Una entrada `canary` legible de la cadena (publicar, abajo) | `no_publication` o `publication_unreadable` |
| Sin deriva del agente (caché del job o de la conciliación) y el freno suelto | `agent_drift`, `voice_braked` |
| Un canario de voz `active` (`ultron_studio_canary_numbers`, `channel=voice`; H6 fase 2, cuatro ojos) y no excluido | `not_an_active_voice_canary`, `excluded` |
| Dentro del horario legal (L–V 07–19, S 08–15, Bogotá; ni domingos ni festivos), y del propio si se fija `VOICE_OUTBOUND_HOURS_*` | `outside_hours`, `holiday` |
| 1 al día por contacto y `VOICE_FREQUENCY_PER_WEEK` | `frequency_exceeded` |
| `VOICE_MAX_CONCURRENT_CALLS` (≥ 1) | `concurrency_exceeded` |
| `TWILIO_VOICE_DAILY_CALL_CAP` ≥ 1 (p. ej. 3) | `daily_cap_zero` o `daily_cap_reached` |
| `VOICE_DAILY_BUDGET_USD` ≥ la reserva. Una saliente a un móvil con 300 s reserva **960 500 µUS$** (5 min × (80 000 + 37 700 + 4 400) + el tramo transferido a fijo 5 × 70 000), así que **≥ 0,97 por llamada y ≈ 2,90 para 3 al día** | `budget_exhausted` |

**El job (`PlaceOutboundVoiceCall`)**
- El worker `ironbody-voice` de la cola `voice`. **No se instaló en la fase 1** (E8-7): es de OPS8.
- `describeAgent` del agente sellado observable con la clave (si no, `drift_unverified`).
- `ELEVENLABS_AGENT_PHONE_NUMBER_ID` = el id del número importado, con forma de id del proveedor (si no, se cancela con `error`).
- `VOICE_OUTBOUND_RINGING_SECONDS` (10–60, 30 por defecto).
- La petición es `agent_id` (el sellado) + `agent_phone_number_id` + `to_number`. **No usa ninguna asignación del número**.

**Publicar la parte canaria (S8)**

Sin esto, `no_publication`:
- `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=true`;
- `VOICE_WEBHOOK_BASE_URL`;
- `ELEVENLABS_INITIATION_SECRET_REF` (siempre: el compilador mete el webhook de iniciación en el agente aunque no haya entrantes);
- `ELEVENLABS_TOOL_SECRET_REF` (siempre en la práctica, porque `no_volver_a_llamar` es obligatoria, E1-8);
- `ELEVENLABS_POST_CALL_WEBHOOK_REF`. S8 lo trata como opcional, pero **sin él no hay `guard_verdict`**: ni guarda posterior ni evidencia, y `missingPostCall` > 0;
- techo del agente ≥ 1 (`TWILIO_VOICE_DAILY_CALL_CAP` + `VOICE_INBOUND_DAILY_CAP`);
- `ELEVENLABS_AGENT_PHONE_NUMBER_ID`;
- **`VOICE_NUMBER_INBOUND_CAPABLE=false`** con el número «Outbound Only». S8-d lo usa para no asignarlo. Sin ella, ElevenLabs rechazaría la asignación y publicar daría 409 `voice_number_not_assigned`;
- la ventana 07–19, cuatro ojos, el veredicto `approved` del laboratorio (o `waived`) y el freno suelto.

**Webhooks y herramientas durante la llamada**
- `ELEVENLABS_WEBHOOK_SECRET` registrado como secreto del webhook post-llamada del workspace.
- `ELEVENLABS_TOOL_SECRET` (Bearer de las herramientas).

**Para el corte y la conciliación** (no para marcar, que lo hace ElevenLabs)
- `TWILIO_VOICE_ACCOUNT_SID`, `TWILIO_VOICE_API_KEY_SID` y `TWILIO_VOICE_API_KEY_SECRET`.
- Sin ellas, `voice:hangup` no puede colgar una viva. La prueba «corte» de la 4a las necesita.
- `VOICE_MONITOR_ENABLED=true` programa la conciliación, la deriva del número y la verificación semanal.

**No hace falta**
- `VOICE_INBOUND_ENABLED` (sigue en `false`);
- asignar el número a un agente;
- `TWILIO_VOICE_SIGNING_TOKEN` (solo valida el aviso de uso de Twilio).

**Qué devuelve `describeNumber()` con un «Outbound Only».** Según la referencia oficial: un caller ID verificado en Twilio, «Cannot receive calls or be assigned to agents».
- `observed`, `unassigned`, `agentId = null`, e `inboundCapable = false` si ElevenLabs aún manda `supports_inbound` (obsoleto). Si no lo manda, `null`.
- Antes, sin `assigned_agent` en el cuerpo, era `error/invalid_response` y la conciliación quedaba ciega (`unobserved_error`, sale 0). Con `assigned_agent: null` era **deriva**: freno, incidente y salida 1 cada hora, y con el freno ninguna saliente pasaba.

## 6. Punto 4 · la cuenta de Twilio y el 21220 (documentación oficial, sin ejecutar nada real)

### 6.1 Cuenta principal o subcuenta

**Lo que se probó (`VoiceTwilioAccountTest`).**
- El transportista pide lo mismo, **byte a byte salvo el SID en la ruta**, con la principal que con una subcuenta:
  - `POST /2010-04-01/Accounts/{AC}/Calls/{CA}.json` con `Status=completed`;
  - `GET …/Calls.json?PageSize=100&StartTime>=…`;
  - Basic con `SK…:secreto`, contra `api.twilio.com`.
- Nunca viaja la credencial del OTP (`otp.twilio.token`), aunque `otp.twilio.sid` sea la misma cuenta, y nunca se toca `verify.twilio.com`.
- `OtpUntouchedByVoiceTest` sigue en verde (dentro de las cifras de §1).

**Lo que NO es igual: lo que contiene la cuenta.** `listCalls()` lista **todas** las llamadas de la cuenta configurada.
- Con la principal, cualquier llamada que no haga la voz (otro número, Studio, Flex) es `unknown_provider_call` y **echa el freno**: lo fija la segunda prueba.
- El OTP de este proyecto usa Verify solo por SMS (`TwilioVerifyService.php:49`, `Channel=sms`): no genera llamadas.
- Con la principal funciona si no tiene otro tráfico de voz. Si lo tiene, la voz se frena sola (falla cerrado) y hay que ir a la subcuenta del contrato (D8-4, D8-5).

**Token de firma.**
- Con la principal, `TWILIO_VOICE_SIGNING_TOKEN` tendría que ser el **Auth Token maestro**, el mismo secreto del OTP (`TWILIO_AUTH_TOKEN`), duplicado en otra variable.
- Solo lo usa el aviso de uso de Twilio (`/api/webhooks/voice/twilio/usage-trigger`), que no hace falta para salientes.
- **Recomendación:** con la principal, dejarlo vacío (la ruta responde 401: falla cerrado) y apoyarse en `VOICE_DAILY_BUDGET_USD` y en el techo de la conciliación.

**Claves restringidas** ([Restricted API keys](https://www.twilio.com/docs/iam/api-keys/restricted-api-keys), modificada el 03-08-2026; [API keys](https://www.twilio.com/docs/iam/api-keys); [changelog: GA el 10-12-2025](https://www.twilio.com/en-us/changelog/restricted-api-keys-ga)).
- Cita textual: «Each permission maps to one or more endpoints/actions for each API Resource». Tienen un tope de 100 permisos y «Twilio uses the Restricted key's SID and secret as your credentials when sending API requests».
- Una key se crea en una cuenta y actúa sobre ella: para la subcuenta, la key se crea **en la subcuenta**.
- La página no dice si una key de la principal alcanza recursos de una subcuenta, así que no lo doy por hecho.
- El changelog de GA dice que solo existen en la región US1.
- La key de Laravel necesita: Calls (leer, listar y actualizar), números (leer) y uso (leer), como en D8-5.
- Según la [integración nativa de ElevenLabs](https://elevenlabs.io/docs/agents-platform/phone-numbers/twilio-integration), la importación admite «Your Twilio Account SID or API Key SID» y «Your Twilio Auth Token or API Key Secret», y recomienda una key con permisos limitados.

**Mover un número entre cuentas**
- [Error 21473](https://www.twilio.com/docs/api/errors/21473), cita textual: «You can only transfer phone numbers between your main account and its subaccounts». Se envía como «HTTP `POST` to the `IncomingPhoneNumber` instance resource» y hay que «Use your main account credentials when you submit the transfer request».
- [IncomingPhoneNumber](https://www.twilio.com/docs/phone-numbers/api/incomingphonenumber-resource), cita textual: «You can transfer phone numbers between two Twilio accounts if you're using subaccounts».
- Según el [artículo de soporte](https://support.twilio.com/hc/en-us/articles/360038163473), leído por buscador (la página dio 403 al abrirla), no se puede hacer desde la consola.
- **Esto vale para números comprados.** Un «Outbound Only» de ElevenLabs es un **caller ID verificado**, que no se transfiere: según la [documentación de OutgoingCallerIds](https://www.twilio.com/docs/voice/api/outgoing-caller-ids), pertenece a la cuenta que lo verificó. Pasar a una subcuenta exige **volver a verificarlo en ella**, darle a ElevenLabs las credenciales de esa subcuenta (reimportar) y actualizar `ELEVENLABS_AGENT_PHONE_NUMBER_ID`.
- Además, Twilio dice que un caller ID verificado no recibe llamadas por Twilio. Encaja con «Outbound Only».

### 6.2 La calibración del 21220

- **Qué dice Twilio** ([error 21220](https://www.twilio.com/docs/api/errors/21220)), cita textual: «You attempted a realtime operation on a Call that was not in-progress» y «Calls that have completed cannot be recorded or redirected».
  - No lo describe para `Status=completed`.
  - La [referencia del recurso Call](https://www.twilio.com/docs/voice/api/call-resource) solo admite `canceled` y `completed` al actualizar, y no dice qué pasa con una llamada ya terminada.
- **Qué hace el código.** Un 400 es `rejected`, que da `failed`: no confirma el corte y frena (B8-e, E14-4). **Sin cambios.** Darlo por confirmado fallaría abierto si viniera de una llamada viva.
- **Dónde importa.** Solo en el colgado de una saliente cortada que el proveedor inició igual (una carrera rara).
  - El corte de una viva (`dialing`/`active`) devuelve `rejected` sin frenar, y la fila sigue hasta su post-llamada o la conciliación.
  - Con B8-g, `--all` no repite colgados de más de una hora, para no provocar 21220 sobre llamadas ya terminadas.
- **Cómo calibrarlo en la 4a** (sin código nuevo):
  1. Cada `hangup_requested` con `codes.afterCancel` guarda `errorCode` (21220), `carrierOutcome` y su instante.
  2. La conciliación lista la llamada con su `end_time`, visible en la consola de Twilio y en `voice:reconcile --dry-run --json` (estado y duración).
  3. **Regla:** si en todos los 21220 observados el `end_time` de Twilio es anterior o igual al instante del colgado, el 21220 significa «ya había terminado» y puede pasar a confirmar el corte (una línea en `afterCancelResult`, con su prueba).
  4. Si alguno llega con la llamada aún viva, se queda como `failed`.
  5. Si en la 4a no aparece ninguno (lo probable), se queda como está.

## 7. Para tester (casos límite desde dentro)

- **E2E (no los ejecuté: necesitan su arnés):**
  - `H8E2EAbiertaTest:206` espera 6 claves y `calls = 3`. Con E13-3 son 7, y `calls` solo cuenta las llamadas con su `guard_verdict`; si el recorrido no manda la post-llamada de cada una, `missingPostCall` > 0 (lo esperado).
  - Con la marca de verificación, una saliente del banco puede pasar la puerta si el arnés corre `ultron-studio:integrations-snapshot --verify-voice` contra el simulador (E8-3).
- **Simulador (OPS8):** `vistaNumero` de `sim-elevenlabs.cjs` no da `supports_inbound`, no modela un número solo saliente y su PATCH asigna cualquier número. Para un recorrido «Outbound Only» haría falta un número con `supports_inbound:false`, sin `assigned_agent`, y un PATCH que lo rechace. No lo toqué (no es mío).
- **Reloj:** las pruebas nuevas fijan la hora en UTC; la marca se escribe en UTC al segundo.
- **Carrera:** una `voice:hangup` a mano sobre una cortada mientras la conciliación la reintenta da dos POST y dos eventos. Colgar es idempotente y la clave del freno es única: si uno falla y el otro no, manda el más reciente.
- **`cache:clear`:** borra la marca y la telefonía vuelve a `UNVERIFIED` (falla cerrado), mientras el Studio sigue `VERIFIED`. El doctor lo enseña («sin marca»).

## 8. Riesgos residuales y pendientes

1. **El webhook de iniciación en una saliente (verificar en la primera llamada de la 4a).**
   - La doc de ElevenLabs lo describe «for inbound Twilio calls».
   - Si también lo llamara en una saliente, `InboundGate::responseFor()` respondería `caller_authorized=no` y `call_direction=entrante` para la fila saliente (sus `caller_authorized` son null), y el agente colgaría al canario. Si llega antes de que el job guarde el SID, además se crea una entrante `denied` con ese SID y el job choca con el UNIQUE.
   - No lo cambié sin evidencia; es un arreglo de una función.
   - **Firma a vigilar:** un `voice.inbound.*` en el canal `voice` durante una saliente.
2. **`el-verificar.sh` usa `voice:doctor --verify`, que no guarda nada:** tras rotar la clave (E2-2) hay que correr también `ultron-studio:integrations-snapshot --verify-voice`. Va a DOC8 y al runbook.
3. **El comentario de `routes/console.php`** (la tarea semanal: «el Studio sigue afirmando ELEVENLABS_VERIFIED») ya vale también para la telefonía. Es de OPS8.
4. **Despliegue:** `VOICE_NUMBER_INBOUND_CAPABLE` es opcional (ausente = `null`). Si OPS8 la añade al entorno con el número «Outbound Only», va `false` y después `config:cache`.

## 9. Migración, configuración y despliegue

- **Migraciones:** ninguna.
- **Variables:** una nueva, opcional: `VOICE_NUMBER_INBOUND_CAPABLE` (vacía = sin declarar).
- **Caché:** una clave nueva en la caché de la aplicación (`voice:elevenlabs:verified_at`, `database` en producción). No la purga `voice:prune-rate-limits`, que solo borra `voice-rl:*`. Sí la borran `cache:clear` y `optimize:clear`.
- **Operación:** tras desplegar, la telefonía estará `UNVERIFIED` hasta la primera `ultron-studio:integrations-snapshot --verify-voice`. El Studio seguirá `VERIFIED` con su evidencia de antes.

## 10. Ficheros

**Cambiados**
- `app/Services/Telephony/{VoiceProviderState,VoiceEvidence,VoiceReconciler,VoiceHangup,VoiceTelephonyStatus}.php`
- `app/Services/Voice/Dto/VoiceNumberAssignment.php`
- `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php`
- `app/Console/Commands/Voice/{VoiceDoctorCommand,VoiceHangupCommand,VoiceReconcileCommand}.php`
- `app/Console/Commands/UltronStudio/IntegrationsSnapshotCommand.php` (solo la marca)
- `config/voice.php`
- el ejemplo de entorno (bloque de voz: `VOICE_NUMBER_INBOUND_CAPABLE=`)
- `tests/Feature/Telephony/{VoiceEvidenceTest,VoiceNumberDriftTest,VoiceCommandsTest,VoiceReconcilerTest,VoiceReconcileDryRunTest}.php`
- `tests/Unit/Voice/{ElevenLabsNumberObserverTest,ElevenLabsConfigTest}.php`
- este informe

**Nuevos:** `tests/Feature/Telephony/{VoiceProviderVerificationTest,VoiceHangupCanceledTest,VoiceTwilioAccountTest}.php`.

**Sin commit.**

**Bases PG:** `ultron_studio_h8_bg` (la puerta de este paquete).

**Fuera del repositorio** (desechable, en el scratchpad de la sesión):
- las copias `antes/backend` (el árbol al empezar) y `mut/backend`;
- el banco: `tools/mutar_b8g.py`, `tools/RelojFijo.php`, `tools/bootstrap-reloj.php` y `tools/phpunit-reloj.xml`;
- `out/`: resultados de texto, JUnit y JSON de cada ejecución.

---

# §B8-g (2) · puntos 7–10: la guarda ve el español real (SEC8-c M-1), el modo solo saliente completo (M-2), la identidad del número (B-1) y el aviso de «usted» en la ruta 45

**Fecha:** 09-11-2026. **Árbol:** el mismo worktree, con §B8-g ya aceptado por el lead, **sin commit**. Siempre `php -d memory_limit=2G`.

**No toqué:**
- el motor: `OutboundContentGuard` y `SalesAgentDecisionSchema`, que solo leí (protección 5);
- las guardas, `StudioVoiceTemplate`, las migraciones, el simulador ni el CRM.

Del Studio, solo lo reasignado en el punto 10: una línea de `StudioIntegrations::voiceTemplate()` y la línea correspondiente de su prueba.

## 1. Cifras reales (estado final)

| Qué | Comando | Resultado |
|---|---|---|
| Telefonía + Voz, SQLite | `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio` | **842 pruebas, 211 683 aserciones, 0 fallos**, 156 omitidas (131 solo PG y 25 E2E) |
| Lo mismo, PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bg bash docs/ultron-studio/ops/pgunit.sh` (las mismas 5 carpetas) | **842, 212 432 aserciones, 0 fallos**, 25 omitidas (E2E). La prueba del español la cambié durante la pasada (una frase más): repetida sola en PG, 7/7 |
| Studio voz, SQLite | `UltronStudioIntegrationsConfigTest`, `UltronEngineIsolationTest`, `UltronStudioEngineReadOnlyTest` y `tests/{Feature,Unit}/UltronStudio/*Voice*Test.php` | **646, 9 673 aserciones, 0 fallos**, 73 omitidas (solo PG) |
| Lo mismo, PG | `pgunit.sh` con esa lista | **646, 10 069 aserciones, 0 fallos** |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, salida 0 |
| Estilo | `pint --test` en los 21 ficheros PHP tocados en esta tanda | `passed` |
| Mutaciones (puntos 7–10) | `tools/mutar_b8g2.py`, en una copia aislada con su `vendor/` | **25 de 25 muertas**; 2 controles sobreviven; 27 de 27 restauradas; copia idéntica (SHA-256 de `app/` y `config/`) |

**La regresión, por mutación.** No tenía una instantánea del árbol entre §B8-g y esta tanda, así que la prueba de que cada arreglo es necesario es su mutación «deshacer el arreglo», y todas caen:
- **Punto 7 (SEC8-c M-1):**
  - G2, sin el léxico de la voz: 4 fallos (las frases de ataque vuelven a pasar);
  - G1, sin la pasada plegada: «promoción» con tilde pasa;
  - G3, sin convertir los números en palabras: el precio del catálogo dicho en palabras da un falso positivo;
  - G4, sin neutralizar los modismos: «un millón de gracias» es dinero.
- **Punto 8:**
  - I1, sin el motivo de la iniciación;
  - R1, la deriva solo saliente con el agente publicado vuelve a ser `ok`.
- **Punto 9 (B-1):**
  - B1 y B2, sin la comparación con la mensajería y con `TWILIO_VOICE_FROM`;
  - B3, la puerta con solo la configuración: no dice `voice_number_is_whatsapp`;
  - B4, sin la observación en la caché;
  - B5, el desplegador sin `phone_number`.
- **Punto 10:** T1, sin `aiNotices`.

## 2. Qué cambió y por qué (por fichero)

### Punto 7 · SEC8-c M-1: la guarda posterior ve el español real

**`app/Services/Telephony/VoiceTranscriptLexicon.php`** (nueva, pura: sin base, configuración, red ni registro; no nombra el motor)

**Funciones de normalización:**
- `fold()`: minúsculas y sin tildes, también ü y ñ.
- `neutralizeIdioms()`: «un millón de gracias» y «millones de gracias» pasan a «muchas gracias», antes de convertir números.
- `numbersToDigits()`: un número con «mil» o «millón» detrás pasa a cifras con puntos de miles.
  - Vale en palabras, en cifras o mezclado, con tildes o sin ellas y con mayúsculas: «ochenta y nueve mil» → `89.000`, «un millón doscientos mil» → `1.200.000`, «89 mil» → `89.000`.
  - Solo si **empieza por un número y lleva multiplicador**, como el `NUMERAL` del motor. «Mil gracias», «más de mil socios» y «uno de los beneficios» no cambian.

**`codes()`, sobre el texto plegado.** Seis códigos, en un conjunto cerrado:
- `voice_discount_extra`: los extra de la plantilla (copiados, porque la telefonía no puede nombrar `StudioVoiceTemplate`) y los de es-CO de SEC8-c: «descuentico», «rebajita», «precio de amigo», «te lo dejo en/a», «no tiene costo», «no te cobramos», «bono», «cupón», «obsequio», «bonificación», «dos por uno», «te regalo».
- `voice_money_slang`:
  - «lucas» o «barras» detrás de un número, salvo «barras olímpicas», «de pesas», «de dominadas», «de discos» y «paralelas»;
  - «un/medio/dos/tres palo(s)»;
  - «millón» suelto;
  - USD, dólares, euros y €.
- `voice_language_not_spanish`: dos marcas distintas de inglés en el mismo turno. Una sola puede ser un préstamo.
- `voice_prompt_leak`: los nombres de las herramientas, las herramientas de sistema y las variables (de las constantes de `VoiceAgentSpec`, no escritos a mano), más `{{`, `system__`, «datos de esta llamada», «mis instrucciones», «mi prompt» e «instrucciones del sistema».
- `voice_member_or_payment_claim`: «está inscrito», «tu/su membresía/plan está…», «quedó registrado tu pago», «pago recibido/confirmado…».
- `voice_spoken_contact_data`: 7 o más dígitos dictados en palabras, o «arroba».

**`app/Services/Telephony/VoiceTranscriptGuard.php`**, para cada turno del agente:
1. Neutraliza los modismos y pasa los números a cifras **antes de tapar** los precios del catálogo. Así un precio de `consultar_planes` dicho en palabras se tapa, cuando antes daba un falso positivo, y uno inventado sigue siendo dinero.
2. Inspecciona con el motor el texto con números normalizados y, si sale limpio, el **plegado**. Se queda con el primer código.
3. Aplica el léxico al texto plegado y con los precios tapados.
   - Completa al motor: no repite la familia que el motor ya marcó en el turno (`SAME_FAMILY`: descuento con descuento, dinero con dinero).
   - Sí añade otra familia. Ejemplo: el motor se para en el precio inventado y el léxico añade el descuento de «te lo dejo en promoción».
- El plegado privado pasa a `VoiceTranscriptLexicon::fold()`, que hace lo mismo.

**Resultado sobre el fixture de SEC8-c:**
- **18 de 18 frases de ataque cazadas**, cada una con su código;
- **0 falsos positivos en las 20 legítimas**;
- los 6 controles y los extra, igual o mejor: «un millón» ya no pasa;
- «un millón de gracias» y «veinte barras olímpicas», limpios.

### Punto 8 · SEC8-c M-2: el modo solo saliente, completo

**`app/Services/Telephony/InboundGate.php`**
- Con `voice.number.inbound_capable === false`, la iniciación **deniega siempre**, sean cuales sean las banderas y la constante.
- `number_outbound_only` va como **primer motivo**, el que queda en `policy_reason`. La base solo exige la forma del código (`vc_codes_chk`), no una lista.
- La fila `denied` se crea igual (E1-2).
- Con un agente viejo en el número, además `seal_mismatch` y freno, como antes.

**`app/Services/Telephony/VoiceReconciler.php` (`numbers()`)**
- La regla que dejé a medias queda cerrada: en modo solo saliente (declarado u observado) se espera «sin asignar», y **cualquier** agente asignado, también el publicado, es deriva y freno.
- `expected` dice `unassigned` en ese modo.

### Punto 9 · SEC8-c B-1: la identidad del número que llama

**`app/Services/Voice/Dto/VoiceNumberAssignment.php`**
- `phoneNumber()` da el E.164 con el que llama ElevenLabs, envuelto en `SensitiveParameterValue`, como `OutboundCallRequest::toNumber()`: `var_export`, `json_encode`, `print_r` y `var_dump` no lo enseñan, y `toArray()` no lo lleva.
- Solo un número observado lo lleva, y tiene que ser un E.164 estricto.

**`app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php` (`describeNumber`):** lee también `phone_number`; sin un E.164 estricto, null.

**`app/Services/Telephony/VoiceNumberGuard.php`**
- `collidesWithMessaging(?string)`: un número cualquiera frente a `WHATSAPP_DISPLAY_PHONE` y `WHATSAPP_PROTECTED_NUMBERS`. `collidesWithConfig()` la usa con `TWILIO_VOICE_FROM`.
- `sameNumber()`: compara por dígitos (DF-G10).
- `rememberObservation()` y `lastObservation()`: el veredicto en la caché `voice:number:observed`, 2 h. Guarda `{at, phoneNumberId, matchesFrom, collides}`, **nunca el número ni su huella**.
- `collidesForPolicy()`: la configuración **o** lo observado.

**`app/Services/Telephony/VoiceReconciler.php` (`numbers()`)**
- Antes que la asignación, la identidad:
  - el número importado es uno de los protegidos de la mensajería → `number_is_messaging` y freno;
  - no es `TWILIO_VOICE_FROM` → `number_mismatch` y freno.
- El veredicto va a la caché, salvo en seco.
- El resumen gana `matchesFrom` y `collidesWithMessaging`.
- `NUMBER_BRAKING` agrupa los tres resultados que frenan.

**El resto del punto 9:**
- **`VoiceBrake`:** disparadores `number_mismatch` y `number_is_messaging` → `agent_drift`, con su causa en la evidencia, como `number_drift` (E6-1). La paridad del fixture no cambia (los mismos 6 motivos).
- **`OutboundGate`:** la puerta usa `collidesForPolicy()`. Un número importado que la conciliación vio en la mensajería da `voice_number_is_whatsapp` **por su nombre**, aunque `TWILIO_VOICE_FROM` diga otro.
- **`VoiceReconcileCommand`:** el código de salida es 1 con cualquiera de los tres, y el texto los nombra.
- **`VoiceTelephonyStatus`:** `number.observed` (aditivo; el lector del CRM lo ignora).
- **`VoiceDoctorCommand`:** línea `Importado  :` con el veredicto y su hora.

**Nombres:** la guarda de SEC8 prohíbe el literal «whatsapp» en la telefonía, salvo el motivo de la política (E4-4). Por eso el resultado y la causa se llaman `number_is_messaging`, con el vocabulario que ya usaba el doctor («la mensajería»).

### Punto 10 · el aviso de «usted» en la ruta 45

- `StudioIntegrations::voiceTemplate()` gana `aiNotices` (`StudioVoiceTemplate::AI_NOTICES`: tú y usted, plantilla /2).
- `UltronStudioVoiceIntegrationsTest` fija la clave, los dos textos y que el de usted lleva los tres elementos que exige la guarda posterior (`VoiceTranscriptGuard::hasNotice`).

## 3. Pruebas

**Nuevas**
- `tests/Unit/Telephony/VoiceTranscriptGuardSpanishTest.php` (7). Es **el fixture de regresión de SEC8-c**:
  - las 18 frases de ataque, cada una con su código exacto;
  - las 20 legítimas, sin nada;
  - los 6 controles y los extra;
  - P-3 con precios en palabras: el del catálogo se tapa, otro no, y con P-3 = no ninguno se tapa;
  - la normalización;
  - el conjunto cerrado de códigos;
  - que la guarda caza todo lo que caza la plantilla.
- `tests/Feature/Telephony/VoiceNumberIdentityTest.php` (4):
  - el número declarado, que no frena y guarda su veredicto sin el número;
  - otro número: `number_mismatch`, freno, y en seco nada;
  - **la regresión de B-1**: el número importado de la mensajería, con la configuración limpia, frena y la puerta real lo deniega con `voice_number_is_whatsapp`; también con la lista de protegidos;
  - sin número en la respuesta, o sin `TWILIO_VOICE_FROM`, no se compara.

**Cambiadas**
- `VoiceInitiationWebhookTest`, +2: los dos modos (sin declarar, `false` y `true`) y el agente viejo con el número declarado solo saliente (`seal_mismatch` y freno).
- `VoiceNumberDriftTest`: el modo solo saliente espera «sin asignar», y el agente publicado ahí es deriva.
- `ElevenLabsNumberObserverTest`: el número sale solo en memoria; los `phone_number` sin forma no se toman; 3 formas imposibles más del DTO.
- `VoiceCommandsTest`: `number.observed`.
- `TelephonyFixtureParityTest`: el turno «$ 80.000, te lo dejo en promoción» sin herramienta da el dinero (motor) **y** el descuento (léxico), que es otra familia. El JSON del fixture no cambia.
- `UltronStudioVoiceIntegrationsTest`: `aiNotices` (punto 10).

## 4. Contratos nuevos o modificados

- **Códigos de la guarda posterior** (en `guard_verdict.codes.violations` y en el detalle de la llamada): `voice_discount_extra`, `voice_money_slang`, `voice_language_not_spanish`, `voice_prompt_leak`, `voice_member_or_payment_claim` y `voice_spoken_contact_data`.
  - Cumplen `^[a-z][a-z0-9_]{0,47}$` (E12-6) y caben en el tope de 50 (E12-1).
  - Un turno puede llevar ahora más de un código, de familias distintas.
  - **FE8:** etiquetas en castellano, si se quieren.
- **`policy_reason` / motivos de la iniciación:** `number_outbound_only`, el primero con el número declarado solo saliente. **FE8:** su texto, si el CRM traduce motivos.
- **`voice:reconcile --numbers`:**
  - resultados nuevos `number_mismatch` y `number_is_messaging`, que salen con 1;
  - resumen con `matchesFrom` y `collidesWithMessaging`;
  - en modo solo saliente, `expected = unassigned` y cualquier agente es deriva.
- **Freno:** causas nuevas `number_mismatch` y `number_is_messaging` (motivo `agent_drift`), con claves `number:<id>:mismatch` y `number:<id>:messaging`.
- **`GET admin/voice/status` y el doctor:** `number.observed` = `{at, phoneNumberId, matchesFrom, collides}` o null. Línea `Importado  :`.
- **`VoiceNumberAssignment::phoneNumber()`:** el número, solo en memoria.
- **Ruta 45:** `template.aiNotices = {tu, usted}`.

## 5. Decisiones (para confirmar)

1. **El léxico vive en la telefonía** (`Telephony\VoiceTranscriptLexicon`), no en un `App\Support\Text` compartido con la plantilla.
   - El encargo dice «en la guarda de la transcripción de la telefonía», y `app/Support` no es mío.
   - La deriva con la plantilla la fija una prueba: todo lo que caza la plantilla lo caza la guarda.
   - Si S8 quiere una sola fuente, la clase se mueve tal cual (es pura).
2. **La pasada plegada va después de la original, y el léxico completa sin repetir familia.** El veredicto es más completo que antes: un turno puede tener el código del motor y otro del léxico.
3. **Los precios en palabras pasan a cifras solo con «mil» o «millón» detrás de un número**, la regla del motor. «Ochenta lucas» lo caza la jerga; «ochenta pesos», el motor (`pesos`).
4. **El inglés exige dos marcas distintas en el mismo turno.** Una sola puede ser un préstamo: «free», «plan».
5. **Modo solo saliente:**
   - la iniciación deniega con su motivo propio, no forzando `inbound_disabled`, para que se lea en el registro;
   - en la deriva, cualquier agente asignado es deriva, como pide SEC8-c («cualquier asignación es deriva»).
6. **B-1, en dos piezas:** la conciliación observa y frena; la puerta lee el **veredicto** guardado, porque la puerta no sale a la red y `describeNumber` solo lo llama la conciliación (E5-2).
   - Sin una observación de menos de 2 h, la puerta compara solo la configuración (lo de antes), y la siguiente pasada horaria frena si hace falta.
   - No lo hice fallar cerrado sin observación: eso convertiría `VOICE_MONITOR_ENABLED` en una dependencia implícita de cada saliente.
   - El checklist de la 4a ya exige el monitor encendido.

## 6. Riesgos y pendientes

- **Es una heurística:**
  - la lista de marcas de inglés y la jerga pueden quedarse cortas ante otras frases;
  - un falso positivo cuesta un freno que suelta una persona (el lado seguro);
  - **se calibra en la 4a** con las transcripciones reales: los códigos quedan en el veredicto.
- **Otra moneda dicha en palabras sin «mil»** («cien dólares») la caza la jerga (`dolar`). Una cifra suelta pequeña sin moneda ni «mil» («vale ochenta») no es dinero para nadie, como antes.
- **La plantilla (B-2, de S8-d)** sigue siendo el control blando del idioma y de las «otras personas». La guarda es la red que frena después.
- **`number.observed` no lleva el número:** para saber cuál es el importado hace falta la consola de ElevenLabs. Es a propósito (D8-17).

## 7. Ficheros de esta tanda

**Nuevos**
- `app/Services/Telephony/VoiceTranscriptLexicon.php`
- `tests/Unit/Telephony/VoiceTranscriptGuardSpanishTest.php`
- `tests/Feature/Telephony/VoiceNumberIdentityTest.php`

**Cambiados**
- `app/Services/Telephony/{VoiceTranscriptGuard,InboundGate,VoiceReconciler,VoiceNumberGuard,VoiceBrake,OutboundGate,VoiceTelephonyStatus}.php`
- `app/Services/Voice/Dto/VoiceNumberAssignment.php`
- `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php`
- `app/Console/Commands/Voice/{VoiceDoctorCommand,VoiceReconcileCommand}.php`
- `app/Services/UltronStudio/StudioIntegrations.php` (una línea, punto 10)
- `tests/Feature/Telephony/{VoiceInitiationWebhookTest,VoiceNumberDriftTest,VoiceCommandsTest}.php`
- `tests/Unit/Telephony/TelephonyFixtureParityTest.php`
- `tests/Unit/Voice/ElevenLabsNumberObserverTest.php`
- `tests/Feature/UltronStudio/UltronStudioVoiceIntegrationsTest.php` (punto 10)
- este informe, con las dos líneas de §B8-g que la regla de M-2 dejó desfasadas, corregidas y marcadas

**Sin commit.**

**Fuera del repositorio** (scratchpad):
- `tools/mutar_b8g2.py`;
- `tools/frases_m1.php` (la sonda de las frases);
- `mut2/backend`;
- `out/` (fin-*, final2-* y mutaciones-b8g2.json).

---

# §B8-g (3) · decisión del lead: la puerta de salientes falla CERRADA sin vigilar el número que llama (`number_unverified`)

**Fecha:** 09-11-2026. **Decisión del lead** (respuesta a §B8-g (2), decisión 6): sin una conciliación del número de menos de 2 h, la puerta deniega, como `elevenlabs_unverified` sin la marca de E4-6. En la fase 4a el monitor está encendido por contrato (`VOICE_MONITOR_ENABLED=true` y `voice:reconcile --numbers` cada hora): ninguna saliente sin vigilancia. **Sin commit.**

## 1. La puerta de salientes (documentación del contrato que cambia)

**`OutboundPolicy::REASONS`: 28 motivos, en este orden**
- **El código y el proveedor:**
  - `calls_disabled_in_code`, `outbound_disabled`, `twilio_voice_disabled`, `no_voice_number`;
  - **el número que llama:** `voice_number_is_whatsapp`, **`number_unverified`** y **`number_mismatch`** (los dos nuevos);
  - ElevenLabs: `elevenlabs_not_configured`, `elevenlabs_disabled` y `elevenlabs_unverified`.
- **La publicación:** `no_publication`, `publication_unreadable`, `agent_drift` y `voice_braked`.
- **El destino:** `source_not_allowed`, `not_an_active_voice_canary`, `contact_not_authorized`, `no_voice_consent`, `excluded` y `rne_check_missing`.
- **El calendario:** `outside_hours` y `holiday`.
- **Los cupos:** `frequency_exceeded`, `concurrency_exceeded`, `daily_cap_zero`, `daily_cap_reached` y `budget_exhausted`.
- **La idempotencia:** `duplicate_request`.

**El hecho nuevo, `numberCheck`**, lo calcula `VoiceNumberGuard::policyCheck()` a partir de la última conciliación del número (`voice:reconcile --numbers`, guardada sin el número):

| Lo que hay | `numberCheck` | Motivo de la puerta |
|---|---|---|
| Nada; una observación de **2 h o más**; ilegible; del futuro más allá de 5 min | `unverified` | `number_unverified` |
| Reciente, sin poder comparar (el proveedor no dio el número o faltaba `TWILIO_VOICE_FROM`) | `unverified` | `number_unverified` |
| Reciente y el número importado **no** es `TWILIO_VOICE_FROM` | `mismatch` | `number_mismatch` |
| Reciente y el número importado **es** `TWILIO_VOICE_FROM` | `verified` | — |

- Los dos motivos solo con `TWILIO_VOICE_FROM` configurado; sin él, `no_voice_number`, como `voice_number_is_whatsapp`.
- El choque con la mensajería sigue diciéndolo `voice_number_is_whatsapp`, por la configuración o por lo observado (B-1).
- Un hecho desconocido lanza `InvalidArgumentException`, como el alcance y la publicación.
- **En producción hoy (fase 1)** la respuesta no cambia: sin `TWILIO_VOICE_FROM` la puerta ya dice `no_voice_number` y no añade los nuevos.

**Qué exige ahora la primera saliente a un canario** (se suma al §5 de §B8-g):
- `VOICE_MONITOR_ENABLED=true`;
- una pasada de `php artisan voice:reconcile --numbers` de menos de 2 h, con el número importado igual a `TWILIO_VOICE_FROM` (la tarea horaria lo mantiene);
- después de un `cache:clear`, repetirla (como `--verify-voice`).

## 2. Qué cambió (por fichero)

**`app/Services/Telephony/OutboundPolicy.php`**
- `NUMBER_VERIFIED`, `NUMBER_MISMATCH`, `NUMBER_UNVERIFIED` y `NUMBER_CHECKS`.
- Los dos motivos en `REASONS`.
- La validación del hecho y la regla.
- El docblock.

**`app/Services/Telephony/VoiceNumberGuard.php`**
- `policyCheck()`.
- `OBSERVED_FRESH_SECONDS = 7200`: la puerta.
- `OBSERVED_CACHE_SECONDS = 86400`: lo que se guarda para enseñarlo, también viejo. Antes eran 2 h; ahora manda la comprobación explícita, no la vida de la caché.
- `FUTURE_SKEW_SECONDS = 300`.
- `lastObservation()` exige la forma exacta del instante.

**`app/Services/Telephony/OutboundGate.php`:** `'numberCheck' => VoiceNumberGuard::policyCheck()`.

**`app/Services/Telephony/VoiceTelephonyStatus.php`:** `number.check`, aditivo.

**`app/Console/Commands/Voice/VoiceDoctorCommand.php`:** la línea `Importado  :` dice si la puerta lo da por vigilado o qué motivo da.

**`app/Services/Telephony/VoiceProviderState.php` (fallo latente del punto 1, corregido).**
- Solo comprobaba la longitud (20) antes de `createFromFormat`, y Carbon **lanza** con un formato que no casa.
- Una marca de 20 caracteres basura habría dado una excepción, y un 500 en la puerta, en vez de `UNVERIFIED`.
- Ahora exige la forma exacta (`MARK_PATTERN`) y falla cerrado. Lo caza la mutación D5.

**El fixture de TEST8, alineado:**
- `tests/fixtures/voice/outbound-gate-cases.json`: `closedSets.reasonsInOrder` con los dos motivos en su sitio;
- su generador `tests/fixtures/voice/tools/build/sec_gate.py`: `ORDER` y su `assert`, de 26 a 28.

Ningún caso de `decide` los produce: el mundo del fixture tiene el número vigilado, y el constructor de hechos de la paridad pasa `numberCheck = verified`. Con eso el verificador independiente de TEST8 da OK (882 casos rehechos, 0 discrepancias).

## 3. Pruebas

**`VoiceNumberIdentityTest` (+3)**
- **La decisión, por la puerta real**, con la verificación de ElevenLabs escrita:
  - sin conciliación → `[calls_disabled_in_code, number_unverified]`;
  - fresca que cuadra → `[calls_disabled_in_code]`: pasa, solo queda la constante;
  - a 1 h 59 min sigue valiendo;
  - a **2 h 1 min** → `number_unverified`;
  - fresca que no cuadra → `[calls_disabled_in_code, number_mismatch, voice_braked]`.
- **Fresca con el número de la mensajería** → `[calls_disabled_in_code, voice_number_is_whatsapp, voice_braked]`.
- **`policyCheck()` falla cerrado:**
  - sin observación;
  - fresca sin comparar;
  - instantes ilegibles, con forma de fecha imposible o sin la «Z»;
  - del futuro;
  - y la marca de E4-6 con 20 caracteres basura (`invalid`, sin lanzar).

**Cambiadas**
- `TelephonyFixtureParityTest`: `numberCheck` en los hechos del fixture.
- `VoiceCommandsTest`: `number.check` y el texto del doctor.
- `VoiceCrmRoutesTest` y `VoiceProviderVerificationTest`: su «todo abierto» incluye ahora el número vigilado. Siembran una conciliación fresca que cuadra y conservan sus expectativas exactas.

**Mutaciones** (`tools/mutar_b8g3.py`, en una copia aislada): **7 de 7 cazadas**; el control sobrevive; 8 de 8 restauradas; copia idéntica. Cubren:
- sin `number_unverified` y sin `number_mismatch`;
- sin la caducidad;
- la puerta con `verified` fijo;
- «sin comparar» como vigilado;
- la marca leída por longitud;
- 2 h + 2 min de tolerancia.

## 4. Cifras (estado final)

| Qué | Resultado |
|---|---|
| Telefonía + Voz, SQLite (`php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio`) | **845 pruebas, 212 473 aserciones, 0 fallos**, 156 omitidas (131 solo PG y 25 E2E) |
| Lo mismo, PG (`PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bg bash docs/ultron-studio/ops/pgunit.sh …`) | **845, 213 259 aserciones, 0 fallos**, 25 omitidas (E2E) |
| Rutas (`php artisan auth:coverage --unmapped`) | vacío, salida 0 |
| Verificador de los fixtures (`python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py`) | `RESULTADO: OK` (882 casos, 0 discrepancias; 80 variantes equivocadas, todas cazadas) |
| Pint (11 ficheros PHP de esta tanda) | `passed` |

## 5. Para otros paquetes

- **TEST8:**
  - el arnés E2E (banco con simuladores) tiene que correr `voice:reconcile --numbers` con el número del simulador igual a `TWILIO_VOICE_FROM` antes de cada saliente, o la puerta dirá `number_unverified`. `vistaNumero` ya da `phone_number`;
  - si quiere, casos de `decide` para los dos motivos en su fixture.
- **FE8:** el texto de `number_unverified` y de `number_mismatch` en `voicePolicyReasonText`.
- **OPS8 / DOC8:** en el runbook de la 4a, la conciliación del número antes de la primera saliente y después de un `cache:clear`.

## 6. Ficheros de esta tanda

**Cambiados**
- `app/Services/Telephony/{OutboundPolicy,VoiceNumberGuard,OutboundGate,VoiceTelephonyStatus,VoiceProviderState}.php`
- `app/Console/Commands/Voice/VoiceDoctorCommand.php`
- `tests/Feature/Telephony/{VoiceNumberIdentityTest,VoiceCommandsTest,VoiceCrmRoutesTest,VoiceProviderVerificationTest}.php`
- `tests/Unit/Telephony/TelephonyFixtureParityTest.php`
- `tests/fixtures/voice/outbound-gate-cases.json` (solo el conjunto cerrado; de TEST8)
- `tests/fixtures/voice/tools/build/sec_gate.py` (solo `ORDER` y su `assert`; de TEST8)
- este informe

**Sin commit.**

---

# §B8-g (4) · cierre: la iniciación de una saliente y la vigilancia del número en el job (decisiones del lead, en el sentido seguro)

**Fecha:** 09-11-2026. Las dos decisiones pendientes de §B8-g (3), que el lead resolvió en el sentido seguro. **Sin commit.**

## 1. `InboundGate::responseFor()`: el webhook de iniciación con el SID de una saliente

- **Antes:** si ElevenLabs llamaba al webhook de iniciación en una saliente (la doc lo describe solo para entrantes), la fila saliente devolvía `caller_authorized=no` (sus `caller_authorized` son null) y `call_direction=entrante`, y el agente colgaba al canario.
- **Ahora**, con una fila **saliente**:
  - responde como **saliente** (`call_direction=saliente`), con su `call_ref` y su etiqueta;
  - **autorizada si la fila está viva** (`dialing` o `active`: la autorizó la puerta); si no, `no`;
  - **sin crear ninguna fila** (ni entrante `denied`) y sin evento;
  - registro `voice.inbound.initiation_for_outbound` (uuid, estado, autorizada; sin teléfonos).
- **Si el SID no es de ninguna fila**, el comportamiento de siempre: una entrante y su decisión.
- **Riesgo residual, a vigilar en la primera llamada de la 4a:**
  - si el webhook llegara **antes** de que el job guarde el SID (el SID se guarda nada más volver `outbound-call`; el webhook, si llegara, lo haría al conectar, segundos después), no hay fila y sale una **entrante denegada**;
  - en ese caso el job, al guardar el mismo SID, chocaría con el UNIQUE y la saliente quedaría `ambiguous`;
  - **qué vigilar:** la línea `voice.inbound.initiation_for_outbound`, o una entrante `denied` cuyo llamante es el número de voz.

## 2. El job vuelve a comprobar el número antes de marcar (en la lista de E6-6)

- `PlaceOutboundVoiceCall::recheckAfterCode()`, justo después de las banderas y **antes del freno y de la deriva**, para no salir a la red: `VoiceNumberGuard::policyCheck()`, una lectura de caché.
- Si no es `verified`, cancela con **el mismo motivo que la puerta** (`number_unverified` o `number_mismatch`), sin marcar, sin evento y liberando la reserva, como el resto de E6-6.
- El choque con la mensajería lo cubre el freno: la conciliación que lo ve lo abre y el job lo comprueba después.

## 3. Pruebas y cifras

**Pruebas nuevas:**
- **`VoiceInitiationWebhookTest`**, +1: el SID de una saliente viva da `{si, saliente, su call_ref, «canario · publicación N»}`; el de una terminada, `no`; **cero filas nuevas y cero eventos**; y un SID ajeno, lo de siempre (una entrante `denied`).
- **`PlaceOutboundVoiceCallRecheckTest`**, +1, la conciliación caducando entre la puerta y el marcado:
  - con la conciliación fresca, pasa;
  - a 2 h 1 min, `number_unverified`;
  - con una nueva que no cuadra, `number_mismatch`;
  - el cancelado guarda ese motivo, sin evento y sin coste;
  - con una fresca, vuelve a pasar.
  - Su ayudante `driftChecked()` renueva también la conciliación del número: las pruebas que viajan en el tiempo prueban su propio motivo.

**Ajustes:** en `VoiceBrakeRearmTest` (la recomprobación del job tras la deriva), una conciliación fresca sembrada, porque ahí se prueba la deriva.

**Mutaciones** (`tools/mutar_b8g4.py`, copia aislada): **6 de 6 cazadas**; el control sobrevive; 7 de 7 restauradas; copia idéntica. Cubren:
- sin la comprobación del job;
- sin distinguir `number_mismatch`;
- el job con `verified` fijo;
- sin la rama de la saliente;
- la saliente siempre autorizada;
- la saliente respondida como entrante.

**Cifras finales** (estado de cierre de B8-g):

| Qué | Resultado |
|---|---|
| Telefonía + Voz, SQLite (`php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio`) | **847 pruebas, 212 887 aserciones, 0 fallos**, 156 omitidas (131 solo PG y 25 E2E) |
| Lo mismo, PG (`PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_bg bash docs/ultron-studio/ops/pgunit.sh …`) | **847, 213 623 aserciones, 0 fallos**, 25 omitidas (E2E) |
| Rutas (`php artisan auth:coverage --unmapped`) | vacío, salida 0 |
| Pint (los 5 ficheros PHP de esta tanda) | `passed` |

La última pasada de SQLite tardó 19 min de reloj (carga media de la máquina ~60, con otros agentes a la vez). No falló nada.

## 4. Ficheros de esta tanda

- `app/Services/Telephony/InboundGate.php`
- `app/Jobs/Voice/PlaceOutboundVoiceCall.php`
- `tests/Feature/Telephony/{VoiceInitiationWebhookTest,PlaceOutboundVoiceCallRecheckTest,VoiceBrakeRearmTest}.php`
- este informe

**Sin commit.**

---

# B8-h · Corrección de los hallazgos de REV8-c y H-4 de TEST8-c (09/10-11-2026)

**Quién:** el paquete lo empezó el agente `h8-fix` (código de M-1, M-2, m-1 y m-2, pruebas de M-1) y lo cerró el **lead** cuando el límite de uso de la cuenta cortó al agente a las 00:00 UTC (pruebas de M-2, m-1 y m-2; corrección y prueba de H-4; suites; este informe). Sin commit. Nada en producción.

## 1. Qué se corrigió

| Hallazgo | Corrección | Dónde | Prueba que cae sin ella |
|---|---|---|---|
| **M-1 (mayor)** la observación del número no se ataba al número configurado | `VoiceNumberGuard::policyVerdict()` exige que el `phoneNumberId` observado sea `ELEVENLABS_AGENT_PHONE_NUMBER_ID`; con otro id o sin id → `number_unverified` (la puerta y el job fallan cerrados; `number.check` lo enseña en el doctor y en `GET admin/voice/status`). El mando puede cambiar el id sin invalidar cachés | `app/Services/Telephony/VoiceNumberGuard.php` | `VoiceNumberIdentityTest` (observación de otro id → `number_unverified`; mismo id → pasa) |
| **M-2 (mayor)** la guarda frenaba las negativas que la plantilla `/2` manda decir | Lista **cerrada** de negativas en primera persona (`VoiceTranscriptAllowlist::PHRASES`), cada una apuntando a la negativa fija de la plantilla que ejecuta (`ANCHORS`, citadas tal cual). `strip()` quita del turno solo una **cláusula entera** que sea exactamente una frase de la lista (con una entrada de `LEAD_INS` y una cola de `TAILS` como mucho; sin distinguir mayúsculas, tildes también en NFD ni espacios) **antes** de que busquen el motor y el léxico. Nada de análisis de negación: «no puedo ofrecerte descuentos, pero te lo dejo en promoción» pierde solo la primera cláusula y la segunda marca; una negativa con homoglifos no es de la plantilla y la guarda la ve normalizada | `VoiceTranscriptAllowlist.php` (nuevo), `VoiceTranscriptGuard::contentCodes()` | `VoiceTranscriptGuardHardeningTest`: **paridad** (la versión de la lista = `StudioVoiceTemplate::VERSION`; cada ancla sigue literal en la plantilla; cada frase apunta a un ancla existente; la expresión compila), cada negativa limpia sola, con entrada y cola y en mayúsculas; la oferta en la misma frase marca; la cláusula no exacta marca; el homoglifo marca |
| **m-1 (menor)** huecos de normalización | `VoiceTranscriptLexicon::fold()`: subconjunto NFKC del alfabeto latino (ancho completo, matemáticas, circulados), ligaduras, diacríticos combinados a su base, caracteres de ancho cero fuera, palabras partidas con guiones o puntos entre letras unidas, homoglifos cirílicos y griegos a su letra latina (`FOLD_MAP`); variantes nuevas en el léxico: «luquitas», «no te cuesta nada», «gratuitamente», «te la regalamos / regalado», «al día con sus pagos» (dato ajeno) | `VoiceTranscriptLexicon.php` | `VoiceTranscriptGuardHardeningTest::test_los_huecos_del_revisor_se_cazan` (11 sondas: NFD, cirílico, ancho completo, ancho cero, guiones, puntos, luquitas, no te cuesta nada, gratuitamente, te la regalamos, al día con sus pagos) + las 7 legítimas siguen limpias |
| **m-2 (menor)** fallo abierto si PCRE devolvía null | `replace()`, `replaceCallback()`, `matches()` y `valueOf()` del léxico y `strip()` de la lista lanzan `UnexpectedValueException` cuando PCRE devuelve null; `VoiceTranscriptGuard::inspect()` la captura y marca el turno con **`voice_guard_unprocessable`** más lo que diga el motor sobre el texto tal cual; `hasNotice()` también falla cerrado | `VoiceTranscriptLexicon.php`, `VoiceTranscriptAllowlist.php`, `VoiceTranscriptGuard.php` | `…HardeningTest::test_si_pcre_no_puede_con_el_turno_la_guarda_falla_cerrada` (UTF-8 inválido → null determinista con `/u`). La sonda de 16 KB del revisor no se puede forzar de forma fiable dentro del proceso de pruebas (el JIT de PCRE no respeta `pcre.backtrack_limit`); es el mismo camino de código |
| **H-4 (TEST8-c)** repetir la misma petición de saliente daba 409 `[frequency_exceeded, duplicate_request]` | La idempotencia se resuelve **antes** de la política en `OutboundGate::decide()`: misma persona + mismo `client_request_id` + mismo destino → 200 con la misma fila (DF-G5), sin contar frecuencia, cupo ni presupuesto, sin fila, sin evento, sin job; mismo identificador para otro destino → 409 `client_request_reused`; una petición nueva sigue pasando por la política entera. El motivo `duplicate_request` sigue en el conjunto cerrado de `OutboundPolicy` (la política recibe `duplicate = false` desde la puerta) | `OutboundGate.php` | `OutboundIdempotencyTest` (3 pruebas: dos peticiones iguales → 200 + 200 con el mismo uuid y una sola fila, sin job; otro destino → conflicto; petición nueva → la política decide y no crea nada) |

**Q-1 de `h8-fix` (decidida por el lead):** la plantilla no tiene las negativas en primera persona, solo la instrucción; la lista cerrada de frases en primera persona que ejecutan esa instrucción es la solución estricta y simple, y la prueba de paridad obliga a revisarla si cambian las instrucciones o la versión de la plantilla.

## 2. Cifras (lead, 10-11-2026 00:40 UTC)

| Qué | Resultado |
|---|---|
| `VoiceTranscriptGuardHardeningTest` + `OutboundIdempotencyTest` (nuevas) | 19 pruebas, 0 fallos (SQLite); `OutboundIdempotencyTest` 3/3 también en PG |
| Telefonía + Voz + unitarias, SQLite (`tests/Unit/Telephony`, `tests/Unit/Voice`, `tests/Feature/Telephony`, `tests/Feature/Voice`) | 776 pruebas, 212 394 aserciones, **1 fallo = `TelephonyRoutesTest`** por las 30 rutas `strategies/**` del Hito 9 sin limitador (árbol compartido; no es del H8) |
| Copia del árbol SIN el Hito 9: las 6 pruebas de contrato de rutas, aislamiento y esquema | 125 pruebas, 0 fallos |
| Telefonía + Voz + unitarias, PG (`ultron_studio_h8_lead`) | 776 pruebas, 213 151 aserciones, **1 fallo = el mismo `TelephonyRoutesTest`** del H9 (26 omitidas: E2E) |
| `voice-fixtures-check.py --php` | OK (882 casos, 0 discrepancias) |
| `auth:coverage --unmapped` | solo las 30 rutas del H9 |
| Pint (lo tocado) | passed |

**Colaterales corregidos:** (a) `ElevenLabsConfigTest::evaluar()` no limpiaba `VOICE_NUMBER_INBOUND_CAPABLE` (faltaba en `$todas`): el último valor del bucle («yes» → true) se quedaba en el entorno del proceso y contaminaba `VoiceCommandsTest` y `VoiceNumberDriftTest` en la suite completa; (b) S9 (H9) había hecho que `UltronStudioVoiceController::STUDIO_ROUTE_COUNT` apuntara a `UltronStudioStrategyController`: en una copia sin el H9 el controlador de voz no cargaba; revertido a 77.

## 3. Ficheros de esta tanda

- `app/Services/Telephony/VoiceTranscriptAllowlist.php` (nuevo)
- `app/Services/Telephony/{VoiceNumberGuard,VoiceTranscriptGuard,VoiceTranscriptLexicon,OutboundGate}.php`
- `tests/Unit/Telephony/VoiceTranscriptGuardHardeningTest.php` (nuevo, lead)
- `tests/Feature/Telephony/OutboundIdempotencyTest.php` (nuevo, lead)
- `tests/Feature/Telephony/{VoiceNumberIdentityTest,VoiceCommandsTest}.php`
- este informe (§B8-h)

