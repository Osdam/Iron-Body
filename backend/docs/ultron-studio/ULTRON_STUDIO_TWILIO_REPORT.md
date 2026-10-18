# ULTRON STUDIO: Twilio (estado)

**Fecha:** 17-10-2026. **Estado: NO IMPLEMENTADO.** No se ha tocado Twilio ni se ha hecho ninguna llamada. Lo que sigue es lo verificado en la auditoría.

## Lo que existe hoy

- Twilio se usa **solo para Verify por SMS** (OTP del login de socios). La Messages API está cerrada salvo el código de vínculo socio↔personal.
- **No hay Voice, TwiML ni Media Streams.** La tabla `marketing_calls` es andamiaje sin uso. **ULTRON no usa Twilio**: es solo texto de WhatsApp.
- Un único webhook (`usage-trigger`) con firma HMAC-SHA1 e idempotencia; el validador es privado del controlador y no soporta cuerpos JSON.
- Palancas de coste solo para OTP (interruptor, techo diario blando y duro, cupos, usage triggers de 4 y 8 USD/día). **Ningún mecanismo propio frenaría un gasto de voz.**
- Permisos de marcado: solo Colombia, y la cuenta no había hecho ninguna llamada a 13-09.

## Lo que hará el Hito L/M

- **Panel de solo lectura** en el Studio: estado del servicio y números autorizados (sin secretos ni teléfonos en claro).
- **Números canarios en base de datos** (teléfono cifrado + hash, alias, canal, activo, versión asignada, revocación), auditados; `MARKETING_ULTRON_ENABLED` sigue mandando por encima.
- **Twilio Voice tras bandera** (`TWILIO_VOICE_ENABLED=false`): subcuenta de voz, `TimeLimit`, cupo diario propio, rangos de alto riesgo cerrados; `TwilioSignatureValidator` extraído del controlador actual; idempotencia por `CallSid`; nunca una llamada desde una acción visual accidental ni sin confirmación expresa.
- **Diferenciar siempre:** simulación de texto (no consume Twilio), prueba de integración y llamada real (cuesta dinero y exige autorización).

## Preguntas abiertas

¿Hay número de voz de Twilio o solo un caller ID verificado? ¿Se acepta una subcuenta? ¿Solo pruebas a números canarios, o también llamadas a prospectos (consentimiento, franjas horarias, coste)?
