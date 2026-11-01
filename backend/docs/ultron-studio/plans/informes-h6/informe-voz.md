# Hito 6 · Paquete V (voz y ElevenLabs, preparación sin activar): informe del backend

**Fecha:** 31-10-2026. **Rama:** `feat/ultron-studio-h6` (worktree `_worktrees/ultron-h6`). **Sin commit** (lo confirma el lead). **Estado:** núcleo, comando y pruebas unitarias hechos y en verde. **Pendiente:** `tests/Feature/Voice/**` (no se llegó a escribir: se acabó el cupo de uso) y la paridad con el fixture del tester, que no existía al terminar.

## Ficheros (todos nuevos)

| Fichero | Qué es |
|---|---|
| `app/Services/Voice/VoiceAgentProvider.php` | Puerto §I6.1: `id()`, `status()`, `verifyCredentials()`. Ningún método de llamada |
| `app/Services/Voice/VoiceProviderStatus.php` | DTO; constantes de estado y `PIECES`; el constructor rechaza lo que no es del contrato |
| `app/Services/Voice/VoiceCheckResult.php` | DTO; `OUTCOMES` de §I2 |
| `app/Services/Voice/NullVoiceProvider.php` | Por defecto; verifica `not_configured` sin red |
| `app/Services/Voice/VoiceProviders.php` | Fábrica sin contenedor |
| `app/Services/Voice/VoiceCallPolicy.php`, `VoiceCallContext.php`, `VoiceCallDecision.php` | Política pura §I6.5, `CALLS_ENABLED_IN_CODE = false` |
| `app/Services/Voice/ElevenLabs/ElevenLabsConfig.php` | Configuración §I6.1–I6.3; regiones solo `default` y `eu` |
| `app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php` | Un GET a `/v1/convai/agents/{id}`; único lector de los secretos |
| `app/Services/Voice/ElevenLabs/ElevenLabsWebhookSignature.php`, `ElevenLabsWebhookEnvelope.php` | Firma §I6.4 y sobre (puros, sin ruta) |
| `config/voice.php` | §I6.2, todo apagado |
| `app/Console/Commands/Voice/VoiceDoctorCommand.php` | `voice:doctor [--json] [--verify]` |
| `tests/Unit/Voice/*.php` (8) | `VoiceIsolationTest`, `ElevenLabsWebhookSignatureTest`, `ElevenLabsWebhookEnvelopeTest`, `VoiceCallPolicyTest`, `VoiceValueObjectsTest`, `ElevenLabsConfigTest`, `ElevenLabsVoiceProviderTest`, `VoiceProvidersTest` |

## Firmas finales

Las de §I6.1, §I6.4 y §I6.5 sin cambios. Además (aditivas, a revisar por el lead):

- `ElevenLabsConfig::agentId(): ?string` y `timeoutSeconds(): int`. Las usa el proveedor para no validar dos veces.
- `ElevenLabsVoiceProvider::secretFingerprints(string $key): array{api_key: ?string, webhook_secret: ?string, tool_secret: ?string}`. Devuelve los 8 primeros hex de `hash_hmac('sha256', trim(secreto), $key)`, o `null` si el secreto falta. Si `$key === ''`, lanza `InvalidArgumentException`. **Por qué existe:** §I5.3 pide `fp` de `apiKey`, `webhookSecret` y `toolSecret` en los `facts` de voz, y §I6.1/§I9.5 dicen que solo el proveedor lee esos secretos. Sin este método, S tendría que leerlos y romper §I9.5. **S debe llamarlo con su `$kCfg`** y no leer `voice.elevenlabs.{api_key,webhook_secret,tool_secret}` ni el bloque `voice`/`voice.elevenlabs`: `VoiceIsolationTest` lo comprueba en todo `app/` y `routes/`. Las claves no secretas (`enabled`, `region`, `agent_id`, `agent_phone_number_id`, `voice.twilio.*`) sí puede leerlas.
- `VoiceCallDecision::toArray()`. Constantes públicas `STATE_*`, `OUTCOME_*`, `CANARY_*` y `SOURCE_*`.

## Variables para `.env.example` (paquete S), con su defecto seguro

`ELEVENLABS_ENABLED=false`, `ELEVENLABS_API_KEY=`, `ELEVENLABS_AGENT_ID=`, `ELEVENLABS_WEBHOOK_SECRET=`, `ELEVENLABS_REGION=default`, `ELEVENLABS_AGENT_PHONE_NUMBER_ID=`, `ELEVENLABS_TOOL_SECRET=`, `ELEVENLABS_BASE_URL=`, `ELEVENLABS_TIMEOUT_SECONDS=10`, `TWILIO_VOICE_ENABLED=false`, `TWILIO_VOICE_FROM=`, `TWILIO_VOICE_DAILY_CALL_CAP=0`.

## Salidas reales

- `php -d memory_limit=2G vendor/bin/phpunit tests/Unit/Voice` → `OK (56 tests, 4547 assertions)`. `tests/Feature/Voice` **no existe todavía**.
- `php artisan voice:doctor` → `Estado: ELEVENLABS_NOT_CONFIGURED`, `Faltan: api_key (ELEVENLABS_API_KEY), agent_id (…), webhook_secret (…)`, región `default`. Sale con **0**.
- `voice:doctor --json` → `{"provider":"none","state":"ELEVENLABS_NOT_CONFIGURED","enabled":false,"missing":["api_key","agent_id","webhook_secret"],"region":"default"}`. Sale con **0**.
- `voice:doctor --verify` (y con `--json`, que añade `"verification": null`) → no llama a nada y sale con **1**.
- `vendor/bin/pint --test` sobre los ficheros del paquete → `passed`.
- `grep -rln "elevenlabs\|ElevenLabs" app/ config/` → solo los 11 ficheros del paquete.
- Mutaciones de `VoiceIsolationTest` sobre una copia en el scratchpad: mueren las 26 (constante a `true`; POST; una segunda llamada `Http::`; `curl`; `outbound-call`/`register-call`; rutas a la voz; secretos o bloque leídos fuera de su sitio; `env()` de voz fuera de `config/voice.php`; el Studio nombrado; `Log`; métodos de llamada; ficheros nuevos; el comando leyendo la configuración). Sobreviven los 2 controles.
- Vectores HMAC (firma y huellas) derivados con `openssl` 3.6.4 y con `hmac` de Python, que coinciden; van como literales en las pruebas.

## Decisiones fuera del contrato (a confirmar)

1. **`provider`** = `elevenlabs` si y solo si el estado es `UNVERIFIED`; si no, `none`. Así `status()->provider === current()->id()`.
2. **Región:** si la clave falta (`null`), vale `default`. Una cadena vacía o cualquier valor fuera de la lista va a `missing`. Se normaliza con `strtolower(trim())`, tanto en la configuración como en la clase.
3. **Firma:** un `v0` mal formado junto a otro bueno da `malformed_signature` (lectura estricta de «uno o más v0 que casen»). Las claves distinguen mayúsculas.
4. **`verifyCredentials()`:**
   - un 200 cuyo cuerpo no es un objeto JSON es `error` (sin falso `credentials_ok`);
   - vuelve a comprobar el estado y responde `not_configured` sin red aunque se instancie a mano;
   - `connectTimeout(min(5, timeout))`, sin redirecciones ni reintentos;
   - la `StrayRequestException` se relanza (solo existe en las pruebas, como en el laboratorio).
5. **Validación en los constructores** de los DTO y del contexto: conjuntos cerrados y coherencia entre estado, bandera, piezas y región.
6. **Huellas** sobre el secreto recortado (el valor efectivo que se envía).

## Dudas y datos para el lead

- La referencia oficial de «Get agent» (consultada hoy) confirma `conversation_config.tts.agent_output_audio_format`, `conversation_config.asr.user_input_audio_format` y el valor `ulaw_8000`. También lista los servidores `api.us.elevenlabs.io`, `api.in.residency…` y `api.sg.residency…`; no se añaden (ajuste 3 del lead: exigen enmienda).
- La página de webhooks post-llamada muestra hoy la verificación con el SDK (`constructEvent`), sin el algoritmo manual. Se implementa el de §I6.4.
- Un 403 por IP fuera de la lista permitida de la clave se lee como `invalid_credentials`.
- **Fixture:** `tests/fixtures/ultron-studio/integrations-cases.json` no existía al terminar. Los casos son míos, escritos a mano desde el contrato. Cuando llegue hay que añadir la paridad con `elevenlabsSignature`, `voiceDoctor` y `voiceCallPolicy`.
