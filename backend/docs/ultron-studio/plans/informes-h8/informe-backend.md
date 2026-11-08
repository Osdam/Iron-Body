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
