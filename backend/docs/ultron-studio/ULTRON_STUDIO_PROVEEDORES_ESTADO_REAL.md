# ULTRON STUDIO · Estado real de los proveedores de voz (sondas de solo lectura del lead, 10-11-2026)

Leído desde el servidor de producción con peticiones GET a ElevenLabs y a Twilio usando las credenciales que ya existen allí. **Nada se creó, cambió ni consumió.** Los números y los identificadores van enmascarados; ningún secreto se copió ni se imprimió. Guiones: `scratchpad/sonda-proveedores.sh`, `sonda-2.sh`, `sonda-3.sh`, `sonda-twilio.sh`, `sonda-voz.sh` (sesión del lead; no están en el repositorio por llevar rutas del servidor).

## 1. ElevenLabs

| Elemento | Estado real | Qué significa |
|---|---|---|
| Plan | **Actualizado el 11-11-2026 (sonda GET del lead, 01:3x UTC): `creator`, ACTIVO, mensual**, 3 133 / 125 306 caracteres. El propietario lo compró el 11-11. La API de suscripción no dice los minutos de agentes: confirmarlos en la consola de ElevenLabs. Antes: Free (5 694 / 10 000 caracteres usados; 3 voces propias; sin minutos de agente conversacional) | Sin plan de pago no hay laboratorio hablado real ni llamadas con agente (D-4). El plan a evaluar: Pro anual (capacidad, precio y minutos por confirmar por el propietario) |
| Agente «ULTRON Voice — Iron Body Neiva» | Existe (`…r2fn`). Idioma **`en`**, voz **«Eric - Smooth, Trustworthy»** (prefabricada, hombre, inglés americano), modelo `eleven_v4_turbo`, 0 herramientas, autenticación del agente deshabilitada, ningún número asignado | Es el agente de prueba manual del propietario. **No es el que llamará:** el Studio compila sus propios agentes (canario y resto) desde el perfil de voz aprobado al publicar (E4-9); este agente solo sirve de referencia y para la verificación de la clave |
| Perfil de voz aprobado en el Studio (id 1, revisión 1) | `language = es`, **`voiceId = yfUfwZTRubVrsUZWqzwp` = «Lina - Colombian Warm & Confident»** (profesional, español colombiano, mujer; disponible para cuentas gratuitas), trato pendiente de elegir (`addressForm` vacío) | La voz Lina ya está elegida en el perfil y añadida a la cuenta (22 voces visibles). D-6 queda reducida a aprobar los textos del aviso de IA y el trato tú/usted |
| Número empresarial importado | id `phnum_1001m6hsdgrqe2mtwa8dhp2gv7sh`, termina en **5483**, proveedor twilio, etiqueta «ULTRON Voice - Iron Body Neiva», **`supports_inbound: false`, `supports_outbound: true`**, sin agente asignado | Confirmado «Outbound Only»: un caller ID verificado de Twilio, no un número de Twilio. **Las llamadas entrantes son imposibles con este número** (D-10): no se marcan como operativas |
| Credencial con la que se importó | El workspace guarda **un solo secreto: `twilio_token_account_AC936142bc…`** (tipo `stored`) | **El número se importó con el Auth Token MAESTRO de la cuenta raíz de Twilio del OTP** (A-1 / H-14 confirmado). ElevenLabs custodia la credencial que firma el OTP de todos los socios. Recomendación vigente (B-6): rotar ese token, reimportar con una credencial restringida si ElevenLabs la admite para importar (por confirmar en su consola: la importación por Twilio pide SID y token), y borrar el secreto viejo |
| Webhooks del workspace | `post_call_webhook_id: null`; webhook de iniciación: ausente; eventos configurados `['transcript']`, `send_audio: false` | **No hay webhook post-llamada ni de iniciación registrados.** Los registra `h8-secrets-register.sh` (junto con los dos secretos de Laravel) con tu autorización escrita (D-5); el `--solo-comprobar` solo hace GET y dirá si el plan gratuito lo admite (H-23) |
| Secretos de Laravel en ElevenLabs (webhook y herramientas) | Ausentes (solo existe el de Twilio) | Los crea el mismo registro (D-5) |

## 2. Twilio

| Elemento | Estado real | Qué significa |
|---|---|---|
| Cuenta que originó la llamada real | **La cuenta del OTP** (`AC…2dca`, «My first Twilio account», tipo Full, activa, cuenta raíz, **sin subcuentas, sin API keys**, saldo 21,80 USD) | Es la misma cuenta cuyo token guarda ElevenLabs. Hoy OTP y voz comparten la credencial maestra: contra D8-4/D8-5 y M-3 de SEC8-c |
| Llamadas en el historial de la cuenta | **Una sola:** 03-11-2026 22:48 UTC, `outbound-api`, de …5483 a …6026, completada, 53 s | Es la prueba manual desde ElevenLabs. No hay ninguna llamada de Laravel (correcto: la constante sigue cerrada) |
| Caller IDs verificados | 2: **…5483** (el número empresarial) y **…6026** | El número empresarial ya es caller ID verificado en la cuenta raíz; una subcuenta nueva (b′) exigiría volver a verificarlo allí |
| Números propios de Twilio | **0** | Sin número de Twilio no hay entrantes (compra o portabilidad, decisión D-10); ninguna llamada entrante puede marcarse operativa |
| Credenciales en Laravel | `TWILIO_VOICE_*` **ausentes** (la voz no tiene credenciales propias); las del OTP las ve Laravel por la configuración cacheada, no están en el fichero de entorno del backend | Para que el CRM «reconozca» el número hace falta: claves restringidas solo de Voz (hoy 0 API keys en la cuenta), `TWILIO_VOICE_ACCOUNT_SID`, `TWILIO_VOICE_FROM` (…5483), `ELEVENLABS_AGENT_PHONE_NUMBER_ID` (el id de arriba) y `VOICE_NUMBER_INBOUND_CAPABLE=false`, todo por el mando `h8-voice-scope.sh fase 2 secreto|set` |
| Restricciones para llamar a Colombia | Por confirmar en la consola de Twilio (permisos geográficos de voz: Colombia debe estar habilitada para salientes; la llamada del 03-11 salió, así que la cuenta raíz ya lo permite) | Una subcuenta hereda o fija sus propios permisos geográficos: comprobar con `h8-twilio-check.sh` antes de la 4a |

## 3. Respuestas a las preguntas del propietario (fase 2.2)

- **¿Qué cuenta originó aquella llamada?** La cuenta raíz del OTP (`AC…2dca`), desde ElevenLabs con el token maestro.
- **¿Con qué credenciales se importó el número?** Con el Auth Token maestro de esa cuenta (secreto `twilio_token_account_AC936142bc…` en ElevenLabs).
- **¿Qué número es caller ID verificado?** …5483 (el empresarial) y …6026.
- **¿Puede reutilizarse desde Laravel y ElevenLabs?** Sí para salientes: ElevenLabs ya lo tiene importado; Laravel necesita las claves restringidas de voz y las cuatro variables del mando (fase 2). La reutilización segura exige antes rotar el token maestro y reimportar con una credencial de alcance mínimo si ElevenLabs lo admite.
- **¿Qué falta para que el CRM lo reconozca?** Las piezas de la fase 2 del H8 (claves, cuenta, número, id importado, `inbound_capable=false`) y reconocer la configuración en el Studio.
- **¿Cómo separar voz de SMS/OTP?** Claves restringidas solo de Voz en la cuenta raíz (mínimo) o subcuenta de voz (recomendado, b′) con el número vuelto a verificar como caller ID y su propio token de firma; el OTP sigue con lo suyo y rota su token maestro.
- **¿Aportaría seguridad una subcuenta sin romper el identificador actual?** Sí, si el número se vuelve a verificar como caller ID en la subcuenta (el identificador es el número, no la cuenta); hay que reimportarlo en ElevenLabs desde esa subcuenta.
- **¿Qué hace falta para entrantes?** Un número propio de Twilio (comprar o portar) con capacidad de voz, importado en ElevenLabs con entrantes y asignado al agente publicado; hoy no existe: **entrantes = no operativas, registrado explícitamente**.

## 4. Qué se puede hacer hoy sin plan y qué no

- **Sin plan, con tu autorización:** rotar la clave de ElevenLabs (D-1); rotar el token maestro de Twilio y decidir D-2/D-3 (reimportación con credencial restringida o subcuenta); registrar los dos secretos y el webhook post-llamada en ElevenLabs (D-5, si el plan gratuito lo admite: lo dirá `--solo-comprobar`); fijar la base de los webhooks; cargar las piezas de Twilio por el mando; `h8-twilio-check.sh`; elegir el trato tú/usted y aprobar los textos (D-6); dar `ultron.voice_lab_run` a quien hará la primera sesión; la segunda persona para el veredicto (D-7); la fase 2 del Hito 6 y el canario de voz (D-13); `lab on --driver=fake` y el smoke 2a; el worker; el commit `voice(calls-open)`.
- **Solo con plan (o excepción escrita D-4):** la primera sesión real del laboratorio con Lina, su veredicto a cuatro ojos, la publicación canaria y el smoke 4a.
- **Solo con prueba real:** la primera saliente autorizada desde el CRM, las tres llamadas con post-llamada y las 24 h sin incidentes.

## 5. Actualización del 11-11-2026 (sonda GET del lead)

- **Plan:** `creator`, activo, facturación mensual; 125 306 caracteres de cupo. D-4 queda resuelta por la compra; los minutos de agentes se confirman en la consola.
- **Agentes del workspace:** 2: «ULTRON Voice — Iron Body Neiva» y **«Sofia Asesora de ventas»**, creado a mano fuera del Studio. Ninguno es el que llamará: el Studio compila sus propios agentes al publicar la voz.
- **La clave de ElevenLabs de producción sigue siendo la que pasó por el chat (A-2)** y ahora tiene detrás un plan de pago: rotarla es más urgente (riesgo de gasto).

