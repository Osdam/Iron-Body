# ULTRON STUDIO: ElevenLabs

> **Actualización del 08-11-2026 (Hito 8, E1-12 y E4-6).** Desde el 03-11-2026 22:55 UTC ElevenLabs está configurado y verificado en producción con la autorización expresa del propietario: el Studio afirma **`ELEVENLABS_VERIFIED`** (caduca el 03-12-2026 22:55 UTC si no se renueva la verificación) y la telefonía (`voice:doctor`) dice **`ELEVENLABS_UNVERIFIED`** a propósito hasta la fase 3. Las llamadas siguen cerradas por código. El procedimiento ejecutado y el de rotación están en `ops/el-verificar.sh` (regenera también los dos secretos propios: tras rotar hay que volver a registrarlos en ElevenLabs). Lo que sigue describe el estado del Hito 6 (`ELEVENLABS_NOT_CONFIGURED`) tal como se entregó entonces.

**Fecha:** 17-10-2026; actualizado el 01-11-2026 con el Hito 6. **Estado: `ELEVENLABS_NOT_CONFIGURED`.** El adaptador está construido en la rama `feat/ultron-studio-h6` del backend (`e3a0fdd`), **apagado, sin credenciales y sin ninguna ruta pública**. No se ha generado ninguna llamada ni ninguna voz, y no está desplegado. En producción no hay ninguna variable de ElevenLabs (contrato, «Hechos de producción al congelar», 31-10-2026; matriz de credenciales del 01-11-2026, sin imprimir valores). Encender ElevenLabs exige una **autorización expresa aparte** de la del 25-10-2026 (contrato, «Autorizaciones»). Contrato: `ULTRON_STUDIO_H6_CONTRACT.md` §I6 y Enmiendas 1, 3 y 4; detalle técnico: `ULTRON_STUDIO_BACKEND_REPORT.md` («Hito 6»).

## Qué hay (Hito 6, paquete V)

Todo en `App\Services\Voice`, un espacio neutro: no nombra el Studio ni el motor, y ninguno de los dos lo nombra a él (`VoiceIsolationTest`, `UltronEngineIsolationTest`).

- **Puerto `VoiceAgentProvider`** con tres métodos: `id()`, `status()` (sin red) y `verifyCredentials()` (un GET, solo por consola). **No tiene ningún método que inicie, registre ni transfiera una llamada:** esa parte la diseña el hito de voz.
- **`NullVoiceProvider` por defecto.** `ElevenLabsVoiceProvider` solo se usa con `ELEVENLABS_ENABLED` encendida y todas las piezas válidas (`VoiceProviders::current()`), sin contenedor ni proveedores de servicio.
- **`ElevenLabsConfig`:** regiones en lista cerrada, `default` (`https://api.elevenlabs.io`) y `eu` (`https://api.eu.residency.elevenlabs.io`), las dos únicas confirmadas en la documentación oficial al congelar (otra región exige una enmienda con su URL verificada ese día). El `agent_id` tiene que casar con `^[A-Za-z0-9_-]{1,64}$` con el modificador `D` y **sin recortar** (un espacio o un salto de línea final lo invalidan; N-5, Enmienda 4). `ELEVENLABS_BASE_URL` se ignora en `production`.
- **Estados** (contrato I6.3):

  | Estado | Cuándo |
  |---|---|
  | `ELEVENLABS_NOT_CONFIGURED` | Falta alguna pieza o no tiene forma válida: `api_key`, `agent_id`, `webhook_secret` o `region`, en ese orden. **Es el estado de producción en el Hito 6** |
  | `ELEVENLABS_DISABLED` | Piezas completas y la bandera apagada |
  | `ELEVENLABS_UNVERIFIED` | La bandera encendida y las piezas completas |
  | `ELEVENLABS_VERIFIED` | Solo lo calcula el Studio: `UNVERIFIED` y, además, **la última** verificación guardada es `ok` y tiene menos de 30 días (Q-4 de la Enmienda 2: una clave revocada deja de estar verificada en la siguiente verificación) |

- **`verifyCredentials()`:** `GET {base}/v1/convai/agents/{agent_id}` con las cabeceras `xi-api-key` y `Accept: application/json`, sin redirecciones ni reintentos, con el tiempo de `ELEVENLABS_TIMEOUT_SECONDS` (de 3 a 30 s; 10 por defecto). Resultados: `credentials_ok` (200 con un objeto JSON), `invalid_credentials` (401 o 403; una IP fuera de la lista permitida da 403), `agent_not_found` (404), `unreachable` (sin conexión o tiempo agotado) y `error` (cualquier otra cosa, incluido un 200 cuyo cuerpo no es un objeto JSON). Informa si el agente usa μ-law 8000 (`agentAudioUlaw8000`) cuando la respuesta trae `conversation_config.tts.agent_output_audio_format` y `conversation_config.asr.user_input_audio_format`; esos nombres y el valor `ulaw_8000` están confirmados en la referencia oficial consultada el 31-10-2026 (`plans/informes-h6/informe-voz.md`). **Nunca** registra ni devuelve la clave ni el cuerpo de la respuesta.
- **Los secretos** (`api_key`, `webhook_secret` y `tool_secret`) solo los lee `ElevenLabsVoiceProvider`, en el momento de la petición. Sus huellas de 8 hex para la configuración autorizada salen solo de `ElevenLabsVoiceProvider::secretFingerprints()` (Enmienda 1 §E1.2), con la subclave marcada como parámetro sensible. Una prueba estática vigila que nadie más los lea.
- **`voice:doctor [--json] [--verify]`:** imprime el estado y las piezas que faltan **por nombre**, nunca valores, y sale con 0. `--verify` solo actúa en `UNVERIFIED`: llama a `verifyCredentials()`, imprime el resultado sin guardarlo y sale con 0 solo con `credentials_ok`. Con otro estado no llama a nada y sale con 1. Es el único comando `voice:*`, y no está programado.
- **`ultron-studio:integrations-snapshot --verify-voice`** (paquete S) hace la misma verificación y deja la evidencia: una instantánea `probe` del proveedor `voice`. Es lo que lleva el Studio a `ELEVENLABS_VERIFIED`.
- **Firma del webhook post-llamada, pura y sin ruta pública** (`ElevenLabsWebhookSignature::verify`, contrato I6.4 y Q-5): cabecera `ElevenLabs-Signature: t=…,v0=…`; exactamente un `t` de 1 a 12 dígitos y uno o más `v0` de 64 hex; caducada si `t` es anterior a `now − 1800` y del futuro si es posterior a `now + 300` (decisión defensiva: el ejemplo oficial solo mira el pasado); `HMAC-SHA256(secreto, t + "." + cuerpo crudo)`, comparado con `hash_equals`. Un `v0` mal formado junto a otro válido da `malformed_signature`. Un secreto vacío es un fallo de configuración (excepción), no una firma mala. `ElevenLabsWebhookEnvelope::type()` solo reconoce `post_call_transcription`, `post_call_audio` y `call_initiation_failure`, y no conserva nada más (ni la transcripción).
- **Política de llamadas** (`VoiceCallPolicy`, pura): `CALLS_ENABLED_IN_CODE = false`. En el Hito 6, `allowed` es siempre `false`, con `calls_disabled_in_code` el primero de los motivos (después, `twilio_voice_disabled`, `no_voice_number`, el estado de ElevenLabs, `not_an_active_voice_canary`, `source_not_allowed` y `daily_cap_zero`). La constante se gira **solo** en el commit que implemente las llamadas, con autorización expresa.
- **Ruta 45 `GET integrations/voice`** (sin red): el estado, las piezas que faltan, la política y tres niveles: simulación de texto (disponible, en el laboratorio, sin coste), prueba de integración y llamada real (no disponibles, con sus motivos). Texto obligatorio en el CRM: «En este hito no se hace ninguna llamada.»
- **`config/voice.php`**, todo apagado:

  | Variable | Defecto | Regla |
  |---|---|---|
  | `ELEVENLABS_ENABLED` | `false` | Solo `true`, `1`, `on` o `yes` la encienden |
  | `ELEVENLABS_API_KEY` | vacía | Secreto |
  | `ELEVENLABS_AGENT_ID` | vacía | `^[A-Za-z0-9_-]{1,64}$` con `D`, sin recorte |
  | `ELEVENLABS_WEBHOOK_SECRET` | vacía | Secreto |
  | `ELEVENLABS_REGION` | `default` | En minúsculas y sin espacios; `default` o `eu`. Otra, `missing` incluye `region` |
  | `ELEVENLABS_AGENT_PHONE_NUMBER_ID` | vacía | Solo para la integración nativa, descartada; no se exige |
  | `ELEVENLABS_TOOL_SECRET` | vacía | Para las server tools futuras; no se exige |
  | `ELEVENLABS_BASE_URL` | vacía | Se ignora en `production`; fuera, el simulador del E2E |
  | `ELEVENLABS_TIMEOUT_SECONDS` | `10` | Entero, recortado a 3–30 |
  | `TWILIO_VOICE_ENABLED` | `false` | Como `ELEVENLABS_ENABLED` |
  | `TWILIO_VOICE_FROM` | vacía | E.164 (número de voz o caller ID verificado) |
  | `TWILIO_VOICE_DAILY_CALL_CAP` | `0` | Entero, recortado a 0–20 |

## Estado real

- **Producción: `ELEVENLABS_NOT_CONFIGURED`**, porque no hay ninguna pieza en el entorno. El paso 0 de `deploy-h6-backend-fase1.sh` aborta si encuentra alguna (`ELEVENLABS_API_KEY`, `_AGENT_ID`, `_WEBHOOK_SECRET`, `_TOOL_SECRET`, `_AGENT_PHONE_NUMBER_ID` o `TWILIO_VOICE_FROM`, o una región distinta de `default`), y el script comprueba `elevenlabs:ELEVENLABS_NOT_CONFIGURED` con `integrations-doctor`.
- **En el worktree** (31-10-2026, `informe-voz.md`): `voice:doctor` da `Estado: ELEVENLABS_NOT_CONFIGURED` con `Faltan: api_key (ELEVENLABS_API_KEY), agent_id (ELEVENLABS_AGENT_ID), webhook_secret (ELEVENLABS_WEBHOOK_SECRET)`, código 0; `voice:doctor --verify` no llama a nada y sale con 1. *(La matriz de credenciales del 01-11-2026 dice que `voice:doctor` responde `ELEVENLABS_NOT_CONFIGURED` en el servidor; el comando no existe en producción hasta que se despliegue el Hito 6, así que esa salida es la del worktree.)*
- **El criterio de cierre del Hito 6 exige que siga así** (`HITO_6_COMPLETED` con ElevenLabs en `ELEVENLABS_NOT_CONFIGURED` y el adaptador, el doctor, la firma, la política y este procedimiento entregados).

**Evidencia en la rama:** paquete V, 72 pruebas en verde tras N-5 (`fb152fe`); `VoiceIsolationTest` caza los 26 mutantes del paquete (la constante de llamadas a `true`, un POST, `outbound-call` o `register-call`, una ruta a la voz, un secreto leído fuera de su sitio…); `VoiceBoundaryTest` comprueba en la tabla de rutas real que no hay ninguna ruta de voz (`informe-voz.md`). Paridad con el fixture del contrato: `elevenlabsSignature` 52, `elevenlabsEnvelope` 13, `voiceDoctor` 38, `voiceVerify` 15 y `voiceCallPolicy` 24 casos contra las clases reales (`informe-api.md` §4.1), con el `agent_id` sin recorte cerrado en `4e2e8cf`. Simulador de ElevenLabs en el arnés E2E (`127.0.0.1:8096`), que cuenta como violación cualquier verbo que no sea GET y cualquier ruta que inicie o registre llamadas. [PENDIENTE: los escenarios de voz del E2E del Hito 6 (`ELEVENLABS_NOT_CONFIGURED`, y `voice:doctor --verify` con piezas de prueba contra el simulador).]

## Procedimiento de activación (contrato I6.7) — **en el Hito 6 NO se ejecuta**

Requisitos: el Hito 6 desplegado, una **autorización expresa** del propietario para encender ElevenLabs y las decisiones del punto 1. Nada de esto se ha hecho.

1. **Decisiones del propietario:**
   - grabación y retención (Ley 1581: consentimiento y plazo);
   - si en voz se pueden decir precios;
   - número de voz colombiano en Twilio o caller ID verificado (la sonda de Twilio del Hito 6 lo responde);
   - subcuenta de voz y su presupuesto;
   - **nunca** importar el número productivo de WhatsApp.
2. **Credenciales** (las pone el propietario en el servidor, **nunca por chat**): una clave de API **acotada** (por endpoint, crédito e IP si la consola lo permite), el `agent_id`, el secreto del webhook y la región (`default` o `eu`); y, para Twilio Voice, el número o el caller ID y la subcuenta.
3. **Pasos** (servidor, como root; ningún comando imprime valores):
   1. Las variables, **antes** de cualquier `ironbody-migrate` (que regenera la caché de configuración), con `ELEVENLABS_ENABLED=false`.
   2. La caché como `www-data` con `umask 077` y `chmod 600`: `cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 bootstrap/cache/config.php`.
   3. `sudo -u www-data php artisan voice:doctor`: tiene que salir `ELEVENLABS_DISABLED` (piezas completas, bandera apagada).
   4. `ELEVENLABS_ENABLED=true` y la caché otra vez, igual que en el paso 2.
   5. `sudo -u www-data php artisan voice:doctor --verify`: código 0 solo con `credentials_ok`; el resultado se imprime y no se guarda.
   6. `sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice`: deja la instantánea de la verificación; el Studio pasa a `ELEVENLABS_VERIFIED`.
   7. La evidencia (estado, resultado y si el agente usa μ-law 8000) en el informe final, sin valores.

   Después, un Super Admin vuelve a reconocer la configuración de `voice` en el Studio: sus huellas cambian al llegar los secretos. *(Los scripts de la fase 1 del Hito 6 abortan si encuentran piezas de ElevenLabs en el entorno: la activación va después de esa fase y no se mezcla con un despliegue.)*
4. **Lo que sigue cerrado aunque todo esté verde:** las llamadas. `CALLS_ENABLED_IN_CODE = false` hasta el hito de voz, que traerá su TwiML, el validador de Twilio extraído, la ruta del webhook post-llamada con este verificador, la lista de permitidos de canarios de voz, `TimeLimit`, el cupo diario y su propio canario.
5. **Vuelta atrás:** `ELEVENLABS_ENABLED=false` y la caché como en el paso 2. Vuelve a `ELEVENLABS_DISABLED` y no se ha llamado a nadie.

**Dato abierto:** la matriz de credenciales del 01-11-2026 cita un `voice_id` entre lo que haría falta para activar. El contrato del Hito 6 no tiene esa variable (I6.2) y ningún código del Hito 6 la lee. [PENDIENTE: decidir en el hito de voz si hace falta, con una enmienda.]

---

## Investigación y diseño del 17-10-2026 (histórico)

*Así se escribió el 17-10-2026, con el estado `ELEVENLABS_NOT_CONFIGURED` y sin código. Lo que llamaba «Hito N» es la parte de voz del Hito 6. **Construido en el Hito 6:** el puerto con el proveedor nulo, el adaptador tras bandera con la configuración y la verificación de credenciales, el `doctor`, el verificador de la firma del webhook post-llamada (sin ruta) y la política de llamadas. **Sin construir:** la selección de voz, la síntesis, el streaming, «register call», las rutas de webhook, el Custom LLM y cualquier llamada. Fuentes oficiales consultadas de nuevo el 31-10-2026: las tres que cita el contrato (post-call webhooks, register Twilio calls y data residency).*

**Lo verificado en la documentación oficial (17-10-2026):**
- **Integración nativa con Twilio:** ElevenLabs importa el número (Account SID + Auth Token o API Key acotada) y lo configura por su cuenta; llamadas salientes con `POST /v1/convai/twilio/outbound-call` (`agent_id`, `agent_phone_number_id`, `to_number`).
- **Register call:** Twilio sigue en nuestras manos; nuestro servidor recibe el webhook de Twilio, llama a `POST /v1/convai/twilio/register-call` y devuelve el TwiML. Exige μ-law 8000 Hz y no permite transferir la llamada.
- **Personalización de entrantes:** ElevenLabs llama a nuestro endpoint durante el timbrado y espera `dynamic_variables`; si falla o tarda, la llamada no arranca.
- **Custom LLM:** nuestro servidor expone `/v1/chat/completions` o `/v1/responses` en streaming SSE; recibe `elevenlabs_extra_body` y herramientas como `end_call`.
- **Server tools:** el agente llama a nuestra API en mitad de la llamada (Bearer, OAuth2 o Basic).
- **Webhooks post-llamada:** `post_call_transcription`, `post_call_audio`, `call_initiation_failure`; firma `ElevenLabs-Signature: t=…,v0=<hex>` (HMAC-SHA256 de `"{t}.{cuerpo}"`, 30 min de tolerancia); reintentos ante 5xx, 429 y 408.
- **API:** cabecera `xi-api-key`; claves acotables por endpoint, crédito e IP. TTS `POST /v1/text-to-speech/{voice_id}` con `output_format=ulaw_8000` para Twilio; STT `POST /v1/speech-to-text`.
- **No basta una API key:** hacen falta además `agent_id`, `voice_id`, secreto de webhook, región, id del número (solo con la integración nativa), un secreto para las server tools, el agente configurado en μ-law 8000 con las URLs registradas, y en Twilio un número de voz colombiano o un caller ID verificado.

**Arquitectura elegida (a construir en el Hito N):**
- Puerto **`VoiceAgentProvider`** con un **proveedor nulo por defecto** y **`ElevenLabsVoiceProvider` tras bandera** (`ELEVENLABS_ENABLED=false`): configuración, disponibilidad, verificación de credenciales, selección de voz, síntesis, streaming si corresponde, errores, latencia y estados de conexión.
- **Opción A («register call», Twilio en manos de Laravel):** Laravel crea la llamada saliente solo hacia la lista canaria, valida la firma del webhook de TwiML, registra la llamada en ElevenLabs con variables tomadas del contexto de ULTRON, y el webhook post-llamada guarda el resultado en `marketing_calls`. Sobre la transcripción se repasan a posteriori los guards deterministas. Las entrantes de números no canarios se rechazan sin coste.
- Más adelante, un **Custom LLM servido por Laravel en SSE** que pase cada frase por `OutboundContentGuard::inspect()` antes de hablarla, para recuperar la última palabra en vivo. El pipeline de n8n (7–13 s por turno) no sirve dentro de una llamada.
- Pruebas con mocks y fixtures, diagnósticos (`doctor`) que digan «no configurado» sin fingir conexión, y documentación.

**Qué no se hará sin autorización:** activar el servicio, generar llamadas reales, grabar (Ley 1581: consentimiento y retención), importar el número productivo de WhatsApp (está en coexistencia) ni usar la integración de ElevenLabs con WhatsApp (competiría con ULTRON).

Fuentes: documentación oficial de ElevenLabs (integración nativa, register call, Custom LLM, server tools, webhooks post-llamada, autenticación, TTS, STT) y de Twilio (Media Streams, ConversationRelay, recurso Call, seguridad de webhooks, subcuentas, permisos de marcado), consultadas el 17-10-2026.
