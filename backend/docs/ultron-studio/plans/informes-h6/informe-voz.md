# Hito 6 · Paquete V (voz y ElevenLabs, preparación sin activar): informe del backend

**Fecha:** 31-10-2026. **Rama:** `feat/ultron-studio-h6` (worktree `_worktrees/ultron-h6`). El núcleo quedó en el WIP `7241ad8` del lead; las pruebas Feature y esta actualización están en el árbol, **sin commit**.

**Estado:** paquete completo y en verde.
- Núcleo, comando, pruebas Unit y Feature hechos.
- **Pendiente:** la paridad con `tests/fixtures/ultron-studio/integrations-cases.json`. El tester aún no lo ha entregado y el lead avisará.

## Ficheros (todos nuevos)

| Fichero | Qué es |
|---|---|
| `app/Services/Voice/VoiceAgentProvider.php` | Puerto §I6.1: `id()`, `status()`, `verifyCredentials()`. Ningún método de llamada |
| `app/Services/Voice/VoiceProviderStatus.php` | DTO, con constantes de estado y `PIECES`. El constructor rechaza lo que no es del contrato |
| `app/Services/Voice/VoiceCheckResult.php` | DTO, con los `OUTCOMES` de §I2 |
| `app/Services/Voice/NullVoiceProvider.php` | Proveedor por defecto: verifica `not_configured` sin red |
| `app/Services/Voice/VoiceProviders.php` | Fábrica sin contenedor |
| `app/Services/Voice/VoiceCallPolicy.php`, `VoiceCallContext.php`, `VoiceCallDecision.php` | Política pura de §I6.5, con `CALLS_ENABLED_IN_CODE = false` |
| `app/Services/Voice/ElevenLabs/ElevenLabsConfig.php` | Configuración de §I6.1–I6.3. Regiones: solo `default` y `eu` |
| `app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php` | Un GET a `/v1/convai/agents/{id}`. Único lector de los secretos |
| `app/Services/Voice/ElevenLabs/ElevenLabsWebhookSignature.php`, `ElevenLabsWebhookEnvelope.php` | Firma de §I6.4 y sobre: puros, sin ruta |
| `config/voice.php` | §I6.2, todo apagado |
| `app/Console/Commands/Voice/VoiceDoctorCommand.php` | `voice:doctor [--json] [--verify]` |
| `tests/Unit/Voice/` (8 ficheros) | `VoiceIsolationTest` (§I9.5), `ElevenLabsWebhookSignatureTest`, `ElevenLabsWebhookEnvelopeTest`, `VoiceCallPolicyTest`, `VoiceValueObjectsTest`, `ElevenLabsConfigTest`, `ElevenLabsVoiceProviderTest`, `VoiceProvidersTest` |
| `tests/Feature/Voice/VoiceDoctorCommandTest.php` | El doctor de punta a punta con `Http::preventStrayRequests()` (detalle abajo) |
| `tests/Feature/Voice/VoiceBoundaryTest.php` | La frontera con la aplicación arrancada (detalle abajo) |

`VoiceDoctorCommandTest` cubre:
- solo responden los dos hosts cerrados; en producción se ignora la base del simulador;
- `--verify` con credenciales válidas da 0;
- 401, 403, 404, 500 y sin conexión dan 1;
- `DISABLED` y `NOT_CONFIGURED` no llaman a nada;
- el JSON tiene su forma exacta;
- el simulador del E2E se usa fuera de producción;
- una región no válida se nombra sin su valor;
- ningún secreto, valor ni cuerpo aparece en la salida.

`VoiceBoundaryTest` cubre:
- en la tabla de rutas **real** no hay ninguna ruta de voz (ni clase de `App\Services\Voice`, ni controlador `*Voice*`/`*ElevenLabs*`, ni URI con `elevenlabs`);
- `voice:doctor` es el único comando `voice:*` y no está programado;
- la configuración cargada es la de producción del H6;
- por defecto, ninguna entrada pública sale a la red.

## Firmas finales

Las de §I6.1, §I6.4 y §I6.5 no cambian. Las adiciones:

- **`ElevenLabsVoiceProvider::secretFingerprints(string $key): array{api_key: ?string, webhook_secret: ?string, tool_secret: ?string}`**. **Aprobada por el lead; entra en la Enmienda 1 del contrato** y cierra el hueco entre I5.3 e I9.5.
  - Devuelve los 8 primeros hex de `hash_hmac('sha256', trim(secreto), $key)`, o `null` si el secreto falta.
  - Con `$key === ''` lanza `InvalidArgumentException`.
  - S la llama con su `$kCfg` y no lee `voice.elevenlabs.{api_key,webhook_secret,tool_secret}` ni el bloque `voice`/`voice.elevenlabs`; `VoiceIsolationTest` lo comprueba en todo `app/` y `routes/`.
  - Las claves no secretas (`enabled`, `region`, `agent_id`, `agent_phone_number_id` y `voice.twilio.*`) sí puede leerlas.
- `ElevenLabsConfig::agentId(): ?string` y `timeoutSeconds(): int`. Las usa el proveedor para no validar dos veces.
- `VoiceCallDecision::toArray()`.
- Las constantes públicas `STATE_*`, `OUTCOME_*`, `CANARY_*` y `SOURCE_*`.

## Variables para `.env.example` (paquete S), con su defecto seguro

```
ELEVENLABS_ENABLED=false
ELEVENLABS_API_KEY=
ELEVENLABS_AGENT_ID=
ELEVENLABS_WEBHOOK_SECRET=
ELEVENLABS_REGION=default
ELEVENLABS_AGENT_PHONE_NUMBER_ID=
ELEVENLABS_TOOL_SECRET=
ELEVENLABS_BASE_URL=
ELEVENLABS_TIMEOUT_SECONDS=10
TWILIO_VOICE_ENABLED=false
TWILIO_VOICE_FROM=
TWILIO_VOICE_DAILY_CALL_CAP=0
```

## Salidas reales (31-10-2026, en el worktree)

**Pruebas y estilo**
- `php -d memory_limit=2G vendor/bin/phpunit tests/Unit/Voice tests/Feature/Voice` → `OK (71 tests, 4783 assertions)`: 56 Unit y 15 Feature.
- `vendor/bin/pint --test app/Services/Voice app/Console/Commands/Voice config/voice.php tests/Unit/Voice tests/Feature/Voice` → `passed`.

**`voice:doctor`**

| Orden | Salida | Código |
|---|---|---|
| `php artisan voice:doctor` | `Estado: ELEVENLABS_NOT_CONFIGURED` y `Faltan: api_key (ELEVENLABS_API_KEY), agent_id (ELEVENLABS_AGENT_ID), webhook_secret (ELEVENLABS_WEBHOOK_SECRET)` | 0 |
| `voice:doctor --json` | `{"provider":"none","state":"ELEVENLABS_NOT_CONFIGURED","enabled":false,"missing":["api_key","agent_id","webhook_secret"],"region":"default"}` | 0 |
| `voice:doctor --verify` | No llama a nada | 1 |
| `voice:doctor --verify --json` | Añade `"verification": null`; no llama a nada | 1 |

**Grep:** `grep -rln "elevenlabs\|ElevenLabs" app/ config/` → solo los 11 ficheros del paquete.

**Mutaciones** (sobre copias en el scratchpad, sin tocar el árbol compartido):
- **`VoiceIsolationTest`** (pasada final contra el código y la prueba definitivos): mueren los 26 mutantes y sobreviven los 2 controles (sin mutación, y un método privado `send*`, que sí vale). Los mutantes:
  - la constante de llamadas a `true`;
  - un POST, una segunda llamada `Http::`, `curl`, o `Http::` fuera de `verifyCredentials()`;
  - `outbound-call` o `register-call`;
  - rutas a la voz;
  - un secreto o el bloque leídos fuera de su sitio;
  - `env()` de voz fuera de `config/voice.php`;
  - el paquete nombra el Studio o usa `Log`;
  - métodos de llamada (públicos o en el puerto);
  - un fichero nuevo en el paquete;
  - el comando lee la configuración.
- **`VoiceBoundaryTest`** (subclase en el scratchpad que inyecta rutas):
  - fallan los 3 mutantes (`webhooks/elevenlabs`, una ruta a `App\Services\Voice` y `VoiceCallbackController`);
  - pasa el control (`integrations/voice` del Studio y `ElectronicInvoiceController`);
  - una petición no simulada en `--verify` lanza `StrayRequestException`.

**Vectores HMAC** (firma y huellas): derivados con `openssl` 3.6.4 y con `hmac` de Python, que coinciden. Van como literales en las pruebas.

**Regresión de las suites vecinas:** `tests/Unit/Twilio`, `StudioLabIsolationTest` y `UltronEngineIsolationTest` junto a las de voz → 112 pruebas, 1 fallo.
- El fallo **no es de V**: `UltronEngineIsolationTest::test_fuera_del_studio_…` señala las dos migraciones nuevas del paquete DB (`2026_11_01_100001_…` y `…100002_…`), que todavía no están en su lista blanca.
- Según I9.1, esa lista la amplía **S**. Ningún fichero de voz aparece en el informe del fallo.

## Decisiones fuera del contrato

Aceptadas por el lead salvo objeción del reviewer.

1. **`provider`** es `elevenlabs` si y solo si el estado es `UNVERIFIED`; si no, `none`. Así `status()->provider === current()->id()`.
2. **Región:**
   - ausente (`null`) → `default`;
   - vacía o fuera de la lista → entra en `missing`;
   - se normaliza con `strtolower(trim())`, en la configuración y en la clase.
3. **Firma:** un `v0` mal formado junto a otro bueno da `malformed_signature` (lectura estricta). Las claves distinguen mayúsculas.
4. **`verifyCredentials()`:**
   - un 200 cuyo cuerpo no es un objeto JSON da `error`;
   - vuelve a comprobar el estado antes de salir y responde `not_configured` sin red aunque se instancie a mano;
   - usa `connectTimeout(min(5, timeout))`, sin redirecciones ni reintentos;
   - `StrayRequestException` se relanza: solo existe en las pruebas.
5. **Validación en los constructores** de los DTO y del contexto: conjuntos cerrados y coherencia entre estado, bandera, piezas y región.
6. **Huellas** sobre el secreto recortado, que es el valor efectivo que se envía.

## Datos y dudas para el lead

- **Get agent:** la referencia oficial (consultada hoy) confirma `conversation_config.tts.agent_output_audio_format`, `conversation_config.asr.user_input_audio_format` y el valor `ulaw_8000`.
  - Lista además los servidores `api.us.elevenlabs.io`, `api.in.residency…` y `api.sg.residency…`.
  - No se añaden: según el ajuste 3 del lead, exigen una enmienda.
- **Webhooks post-llamada:** la página oficial muestra hoy la verificación con el SDK (`constructEvent`), sin el algoritmo manual. Se implementa el de §I6.4.
- **IP fuera de la lista permitida:** ElevenLabs responde 403, que se lee como `invalid_credentials`.
- **Paridad con el fixture:** pendiente. Cuando llegue `integrations-cases.json` se añadirá contra las secciones `elevenlabsSignature`, `voiceDoctor` y `voiceCallPolicy`. Si discrepa, se corrige PHP, no el fixture.
