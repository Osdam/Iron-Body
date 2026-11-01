# ULTRON STUDIO: Twilio (estado)

**Fecha:** 17-10-2026; actualizado el 01-11-2026 con el Hito 6. **Estado: IMPLEMENTADO EN RAMA (Hito 6), PENDIENTE DE DESPLIEGUE.** La rama `feat/ultron-studio-h6` del backend (`e3a0fdd`) trae un **panel de solo lectura** del OTP de Twilio, una **sonda GET** apagada por bandera, la **configuración autorizada** por huellas y los **números canarios** como registro. **No se ha hecho ninguna llamada, no hay Twilio Voice y el OTP no se ha tocado.** No está en `origin/main` ni en producción. Contrato: `ULTRON_STUDIO_H6_CONTRACT.md` (§I4, §I5 e I7); runbook: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 6»); detalle técnico: `ULTRON_STUDIO_BACKEND_REPORT.md` («Hito 6»).

## Clasificación: el estado real de la credencial

| Área | Clasificación | Evidencia |
|---|---|---|
| Twilio (OTP) | **Conectada solo para OTP** (`connected_otp_only`) | Verify por SMS (`TwilioVerifyService.php`) y Messages solo para el código de vínculo socio↔personal (`TwilioSmsSender.php`), según el contrato («Clasificación al congelar»). `OTP_DRIVER=twilio`. Matriz de credenciales del 01-11-2026, sin imprimir valores: `TWILIO_ACCOUNT_SID` = `TWILIO_SID` y `TWILIO_AUTH_TOKEN` = `TWILIO_TOKEN`, con huellas iguales (**la misma credencial con dos nombres**), validadas solo para el OTP (el login de socios por SMS funciona a diario) |
| Twilio Voice | **No configurada** (`not_configured`) | Ninguna variable `TWILIO_VOICE_*`, ni número de voz ni caller ID verificado conocido, ni webhook ni código de llamada. `marketing_calls` tenía 0 filas al congelar (31-10-2026) |
| Números canarios | **Registro** (`registry`) | El canario del motor sigue siendo el id de una conversación, en el entorno (`config/marketing.php`). El registro del Studio no lo sustituye en este hito |

- **`TWILIO_FROM` está presente y vacía** en producción (matriz de credenciales). Con la regla de I5.2 (`memberLinkSenderConfigured` = `otp.twilio.from` presente), el panel diría que el remitente del código de vínculo no está configurado. [PENDIENTE: el propietario confirma si el envío por Messages del código de vínculo está en uso.]
- **Sin API key restringida:** `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` y `_KEY_SECRET` no existen (matriz), así que la sonda usaría la credencial del OTP. Crearla es opcional y del propietario; necesita lectura de los cinco recursos de la sonda, Monitor incluido (si no, el paso de alertas sale `auth_failed` y la sonda, `degraded`).

## Qué hace el Hito 6

**1. Panel de solo lectura, sin red** (ruta 43, `GET integrations/twilio`; contrato I5.2):
- **OTP:** el driver, Verify activo, el interruptor de envío, el cupo de Verify configurado, el remitente del vínculo y los retos verificados de socios y entrenadores en 24 horas y en 7 días (solo recuentos).
- **Coste del día:** el de `OtpCostGuard::snapshot()`, con sus techos blando y duro.
- **Credenciales, nunca sus valores:** presencia, la variable de origen, una referencia enmascarada (`AC…1234`, `VA…9f0e`) o una huella de 8 hex con una subclave de la `APP_KEY`. La coherencia de los dos alias se calcula **al cachear la configuración** comparando los valores sin guardarlos (`twilio_aliases`, contrato I12).
- **«Base oficial»** de `TWILIO_BASE` y `TWILIO_VERIFY_BASE`, estricta desde D-18 (N-3 de `security`): `https`, el host exacto, puerto 443 y sin credenciales en la URL. Si no, solo se enseña el host.
- **El webhook `usage-trigger`**, descrito de forma fija desde el código (firma HMAC-SHA1, idempotencia de 24 horas, cupo de 60/min y «solo log»).
- **Las llamadas:** los recuentos de `marketing_calls` y, en la ruta 44 (`GET integrations/twilio/calls`, paginada), estado, dirección, motivo, fechas y duración. **Nunca** `to_phone`, `from_phone`, `transcript`, `summary`, `metadata` ni ids de lead.

**2. Sonda de solo lectura** (ruta 56, `POST integrations/twilio/probe`; contrato I5.4 y Enmienda 1 §E1.4). **Apagada por bandera** (`ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`: 409 `probe_disabled`):
- **Cinco GET**, siempre a la primera página: la ficha de la cuenta, los números, los caller IDs verificados, las llamadas de los últimos 7 días y las alertas de las últimas 24 horas (Monitor).
- **Hosts fijos en `production`** (`api.twilio.com` y `monitor.twilio.com`): ignora `TWILIO_BASE` y la base configurable de la sonda. Sin redirecciones ni reintentos; 10 s por consulta y 30 s en total; enfriamiento de 300 s (una segunda pulsación devuelve la misma instantánea, sin red); cupo de 6 por hora y persona y 20 por hora y red.
- **Permiso `ultron.integrations_manage`**, que por defecto solo tiene el Super Admin. Deja la instantánea y el evento `integration.probe_run` en el libro. Un fallo del proveedor nunca es un 5xx.
- **Saneado con lista blanca** (`TwilioProbeSanitizer`): solo se guardan recuentos, los estados de conjuntos cerrados y los 4 últimos dígitos de **los números de la cuenta del gimnasio**. Se descartan `friendly_name`, los números completos, `to`, `from`, los textos de las alertas, las URL y los cuerpos; el `auth_token` que devuelve la ficha de la cuenta se elimina antes de nada. La credencial va envuelta (`SensitiveParameterValue`): no sale en trazas ni volcados.
- **Credencial:** la API key restringida si el propietario la crea; si no, la del OTP. Fuera de `production` respeta la base configurable (el simulador del E2E): `h6-integrations-scope.sh probe on` se niega a encenderla si el entorno efectivo no es `production` (AS-5).

**3. Configuración autorizada** (rutas 46 y 55, contrato I5.3): una instantánea por huellas de la configuración de Twilio (y de Meta y de la voz). Un Super Admin la reconoce como autorizada; si después cambia, el panel enseña la deriva clave a clave. Reconocer no depende de la bandera de escrituras. **La huella de Twilio incluye `probe.enabled`:** se reconoce después de encender la sonda (H-1 de DevOps).

**4. Twilio Voice tras bandera** (`TWILIO_VOICE_ENABLED=false`, `TWILIO_VOICE_FROM` vacía y `TWILIO_VOICE_DAILY_CALL_CAP=0`): **nada las usa para ejecutar.** Solo las leen el panel y la política de llamadas (`VoiceCallPolicy`, con `CALLS_ENABLED_IN_CODE = false`). Sin TwiML, webhook de voz, subcuenta ni marcador.

**5. Números canarios** (rutas 42 y 49–54; contrato I4). Ver «Números canarios», abajo.

## Auditoría de lo existente (contrato I5.1): se conserva tal cual

| # | Hallazgo | Qué hace el Hito 6 |
|---|---|---|
| T-1 | La firma de `usage-trigger` usa `fullUrl()` (`TwilioUsageTriggerController.php:78`) y no hay `trustProxies` (en `bootstrap/app.php` no aparece, comprobado el 01-11-2026). Si falla, falla cerrada (403): pierde el aviso, no abre nada | Nada en el código. El fixture del contrato lleva los vectores oficiales de Twilio para el día que se extraiga el validador. **Hallazgo nuevo** (S, `informe-api.md` §6.2; Enmienda 4): el validador privado **rechaza los 6 vectores oficiales válidos**, porque `fullUrl()` reordena la consulta (`?foo=1&bar=2` pasa a `?bar=2&foo=1`), quita los puertos 443 y 80, no trata `bodySHA256` ni las claves repetidas. **Sin consulta en la URL** (como la de `usage-trigger`) coincide con el algoritmo publicado. Impacto hoy: ninguno si la URL configurada en Twilio no lleva consulta. [PENDIENTE: comprobar en la consola de Twilio la URL configurada del aviso (propietario).] Se corrige en el Hito 7 |
| T-2 | El validador es privado del controlador y no admite JSON (`bodySHA256`) | No se extrae en el Hito 6: no hay segundo consumidor |
| T-3 | Los avisos solo van al log | El panel lo dice (`persistence: "solo log"`); no se persisten (decisión 12) |
| T-4 | Idempotencia con `Cache::add` (24 h) | En producción la caché es `database`: sobrevive a reinicios |
| T-5 | Dos pares de alias | En producción son la misma credencial; el panel lo vigila sin leer valores |
| T-6 | `TWILIO_BASE` y `TWILIO_VERIFY_BASE` vienen del entorno, y el OTP manda las credenciales en Basic a ese host | Nada en el OTP. La sonda ignora esas variables en `production`, y el panel dice si son la base oficial |
| T-7 | `marketing:dispatch-followups` puede crear filas `pending` en `marketing_calls` con el teléfono en claro (0 al congelar) | Regla del futuro hito de voz, ya codificada en `VoiceCallPolicy` (`source_not_allowed`): un marcador nunca consume `marketing_calls` |
| T-8 | `TwilioVerifyService::toE164()` es permisivo | Los canarios usan `StudioCanaryPhone`, estricto |

## Números canarios (registro, sin efecto en el motor)

- **Qué guarda** (`ultron_studio_canary_numbers`): por canal (`whatsapp` o `voice`), un alias por función (no el nombre de una persona), la huella HMAC del teléfono con una subclave de la `APP_KEY` y sus 4 últimos dígitos; el teléfono **cifrado solo si es de voz**, y a NULL en la misma sentencia que lo revoca. El número completo nunca sale del servidor, ni tras registrarlo. El consentimiento lo declara quien registra.
- **La huella es un seudónimo, no un dato anonimizado** (Enmienda 3, B-1): con la `APP_KEY` y los 4 dígitos, el número se recupera en milisegundos. Revocar **no suprime** la huella, los 4 dígitos ni el alias: el procedimiento de supresión es del Hito 7.
- **Estados:** `pending` → `authorized` → `active`, y `revoked` (definitivo) desde cualquiera. **Cuatro ojos por identidad:** nadie autoriza ni activa un número que registró, en el servicio y en la base (`uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk`). Topes: 3 activos y 10 vivos por canal.
- **Coherencia con el canario del motor:** el Studio lee el teléfono del lead de la conversación canaria, calcula su huella en memoria, lo descarta y compara (`in_sync`, `engine_canary_not_registered`, `registry_has_extra_active`…; nunca `in_sync` por defecto). Ese teléfono nunca se registra, se devuelve ni se guarda.
- **El motor no lee el registro en este hito** (T0 y T1). T2 (el motor exige además que el número esté activo) y T3 (el registro sustituye al id de conversación; la voz lo usa como lista de permitidos) son fases futuras, cada una con su autorización expresa. Texto obligatorio en el CRM: «Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.»

## Qué no hace

- **No envía mensajes ni hace llamadas**, ni crea, cambia o borra nada en Twilio.
- **No toca el OTP:** `app/Services/Sms/**`, `app/Services/Otp/**`, `TwilioUsageTriggerController`, `routes/api.php`, `config/otp.php` y `routes/console.php` sin cambios (puerta de diff vacía, comprobada sobre `e3a0fdd` el 01-11-2026).
- **No persiste los avisos de `usage-trigger`** ni extrae su validador (T-2, T-3).
- **Ninguna ruta del hito sale a la red con las banderas por defecto** (`UltronStudioIntegrationsNoNetworkTest`); con la sonda encendida, solo sus 5 GET a los hosts esperados.

## Evidencia (en la rama)

- Paquete TW: 31 pruebas y 5782 aserciones, 22 de 22 mutaciones cazadas, cero red (`plans/informes-h6/informe-twilio.md`). Rutas de la sonda comprobadas con `Http::fake` y con la documentación oficial del 31-10-2026.
- Paridad con el fixture: `twilioProbeSanitize` 28 casos contra la clase real, `probeRules` 22 y `twilioSignatureOfficial` 7, contra una implementación de referencia del algoritmo publicado (`informe-api.md` §4.1, D-12).
- Arnés E2E: simulador de Twilio en `127.0.0.1:8095`, que exige Basic, solo GET, las 5 rutas y la primera página, y cuyo modo hostil siembra teléfonos, correos y `next_page_uri` de cebo (`informe-e2e-arnes.md`). [PENDIENTE: el escenario de la sonda del E2E del Hito 6 (saneada, el modo 401 da `auth_failed` y el enfriamiento, `reused`).]

## Preguntas abiertas

Las del 17-10-2026, con lo que responde el Hito 6:
- **¿Hay número de voz de Twilio o solo un caller ID verificado?** Lo responde la primera sonda en producción (fase 2): `numbers.voiceCapable` y `callerIds.count` de su instantánea. [PENDIENTE: la sonda de la fase 2.]
- **¿Se acepta una subcuenta de voz?** Decisión del propietario, en el procedimiento de activación de la voz (`ULTRON_STUDIO_ELEVENLABS_READY.md`).
- **¿Solo pruebas a números canarios, o también llamadas a prospectos?** En el Hito 6, ninguna llamada. La política ya fija que un marcador solo partirá del registro de canarios y nunca de `marketing_calls`; llamar a prospectos (consentimiento, franjas horarias y coste) es del hito de voz.

---

## Diseño del 17-10-2026 (histórico)

*Así se escribió el 17-10-2026, con el estado «NO IMPLEMENTADO»; lo que llamaba «Hito L/M» es la parte de Twilio y de los canarios del Hito 6. Construido en el Hito 6: el panel de solo lectura, los números canarios en base de datos (huella y 4 dígitos; cifrado solo en voz) y las variables de Twilio Voice tras bandera. Sigue sin construir, para el hito de voz: la subcuenta, `TimeLimit`, el cupo diario en uso, los rangos de alto riesgo, el validador extraído e idempotente por `CallSid` y cualquier llamada.*

**Lo que existía el 17-10-2026:**
- Twilio se usa **solo para Verify por SMS** (OTP del login de socios). La Messages API está cerrada salvo el código de vínculo socio↔personal.
- **No hay Voice, TwiML ni Media Streams.** La tabla `marketing_calls` es andamiaje sin uso. **ULTRON no usa Twilio**: es solo texto de WhatsApp.
- Un único webhook (`usage-trigger`) con firma HMAC-SHA1 e idempotencia; el validador es privado del controlador y no soporta cuerpos JSON.
- Palancas de coste solo para OTP (interruptor, techo diario blando y duro, cupos, usage triggers de 4 y 8 USD/día). **Ningún mecanismo propio frenaría un gasto de voz.**
- Permisos de marcado: solo Colombia, y la cuenta no había hecho ninguna llamada a 13-09.

**Lo que hará el Hito L/M:**
- **Panel de solo lectura** en el Studio: estado del servicio y números autorizados (sin secretos ni teléfonos en claro).
- **Números canarios en base de datos** (teléfono cifrado + hash, alias, canal, activo, versión asignada, revocación), auditados; `MARKETING_ULTRON_ENABLED` sigue mandando por encima.
- **Twilio Voice tras bandera** (`TWILIO_VOICE_ENABLED=false`): subcuenta de voz, `TimeLimit`, cupo diario propio, rangos de alto riesgo cerrados; `TwilioSignatureValidator` extraído del controlador actual; idempotencia por `CallSid`; nunca una llamada desde una acción visual accidental ni sin confirmación expresa.
- **Diferenciar siempre:** simulación de texto (no consume Twilio), prueba de integración y llamada real (cuesta dinero y exige autorización).
