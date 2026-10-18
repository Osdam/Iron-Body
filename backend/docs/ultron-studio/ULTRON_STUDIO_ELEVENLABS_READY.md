# ULTRON STUDIO: ElevenLabs

**Fecha:** 17-10-2026. **Estado: `ELEVENLABS_NOT_CONFIGURED`.** No hay credenciales, **no hay ninguna referencia a ElevenLabs en el código** de los dos repositorios y no se ha generado ninguna llamada ni voz. El adaptador se construirá en el Hito N sin activar el servicio.

## Lo verificado en la documentación oficial

- **Integración nativa con Twilio:** ElevenLabs importa el número (Account SID + Auth Token o API Key acotada) y lo configura por su cuenta; llamadas salientes con `POST /v1/convai/twilio/outbound-call` (`agent_id`, `agent_phone_number_id`, `to_number`).
- **Register call:** Twilio sigue en nuestras manos; nuestro servidor recibe el webhook de Twilio, llama a `POST /v1/convai/twilio/register-call` y devuelve el TwiML. Exige μ-law 8000 Hz y no permite transferir la llamada.
- **Personalización de entrantes:** ElevenLabs llama a nuestro endpoint durante el timbrado y espera `dynamic_variables`; si falla o tarda, la llamada no arranca.
- **Custom LLM:** nuestro servidor expone `/v1/chat/completions` o `/v1/responses` en streaming SSE; recibe `elevenlabs_extra_body` y herramientas como `end_call`.
- **Server tools:** el agente llama a nuestra API en mitad de la llamada (Bearer, OAuth2 o Basic).
- **Webhooks post-llamada:** `post_call_transcription`, `post_call_audio`, `call_initiation_failure`; firma `ElevenLabs-Signature: t=…,v0=<hex>` (HMAC-SHA256 de `"{t}.{cuerpo}"`, 30 min de tolerancia); reintentos ante 5xx, 429 y 408.
- **API:** cabecera `xi-api-key`; claves acotables por endpoint, crédito e IP. TTS `POST /v1/text-to-speech/{voice_id}` con `output_format=ulaw_8000` para Twilio; STT `POST /v1/speech-to-text`.
- **No basta una API key:** hacen falta además `agent_id`, `voice_id`, secreto de webhook, región, id del número (solo con la integración nativa), un secreto para las server tools, el agente configurado en μ-law 8000 con las URLs registradas, y en Twilio un número de voz colombiano o un caller ID verificado.

## Arquitectura elegida (a construir en el Hito N)

- Puerto **`VoiceAgentProvider`** con un **proveedor nulo por defecto** y **`ElevenLabsVoiceProvider` tras bandera** (`ELEVENLABS_ENABLED=false`): configuración, disponibilidad, verificación de credenciales, selección de voz, síntesis, streaming si corresponde, errores, latencia y estados de conexión.
- **Opción A («register call», Twilio en manos de Laravel):** Laravel crea la llamada saliente solo hacia la lista canaria, valida la firma del webhook de TwiML, registra la llamada en ElevenLabs con variables tomadas del contexto de ULTRON, y el webhook post-llamada guarda el resultado en `marketing_calls`. Sobre la transcripción se repasan a posteriori los guards deterministas. Las entrantes de números no canarios se rechazan sin coste.
- Más adelante, un **Custom LLM servido por Laravel en SSE** que pase cada frase por `OutboundContentGuard::inspect()` antes de hablarla, para recuperar la última palabra en vivo. El pipeline de n8n (7–13 s por turno) no sirve dentro de una llamada.
- Pruebas con mocks y fixtures, diagnósticos (`doctor`) que digan «no configurado» sin fingir conexión, y documentación.

## Qué no se hará sin autorización

Activar el servicio, generar llamadas reales, grabar (Ley 1581: consentimiento y retención), importar el número productivo de WhatsApp (está en coexistencia) ni usar la integración de ElevenLabs con WhatsApp (competiría con ULTRON).

Fuentes: documentación oficial de ElevenLabs (integración nativa, register call, Custom LLM, server tools, webhooks post-llamada, autenticación, TTS, STT) y de Twilio (Media Streams, ConversationRelay, recurso Call, seguridad de webhooks, subcuentas, permisos de marcado), consultadas el 17-10-2026.
