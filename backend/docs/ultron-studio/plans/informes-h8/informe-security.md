# ULTRON STUDIO · Hito 8 · SEC8-a (paso 1) · Modelo de amenazas y guardas (`security`)

**Fecha:** 03-11-2026. **Base:** `feat/ultron-studio-h8-voice` en `75a6384` (Enmienda 1), con las guardas ya en el WIP `1cdaf4d`. Árbol compartido con los paquetes del paso 1. **Sin commit.** Rutas relativas a `backend/`.

## 0. Resumen

- **Guardas:** 5 ficheros de SEC8 + las líneas del H8 de 2 guardas existentes (`UltronEngineIsolationTest` y la lista cerrada de `TwilioReadOnlyClientTest`). **41 pruebas en verde** sobre `75a6384` con los ficheros reales de B8-a.
- **Control positivo:** una copia aislada del árbol + ficheros con la forma que el contrato asigna a B8-b, S8-b, LAB8 y DB8 → las 41 en verde (100 232 aserciones). Las guardas no bloquean el trabajo legítimo de los paquetes.
- **Mutaciones:** **82 de 82 caen** en la copia aislada (más 3 de 3 en la lista cerrada de Twilio), todas restauradas con SHA-256 igual; la base vuelve a pasar después. Tabla en §4.
- **E1-12:** ninguna guarda supone `ELEVENLABS_NOT_CONFIGURED` en producción; prueba nueva con la configuración real de producción. Dos hallazgos de E1-12 para el lead (§1.2): **rotar la clave antes de la fase 2**, no de la 3, y vigilar a qué agente apunta el número.

## 1. Modelo de amenazas

### 1.1 Actores, superficies y activos

**Actores.** Quien llama al número, sin autenticar (también quien hereda un número reciclado, R-15). El contacto de una saliente. El personal del CRM con llaves de telefonía o del Studio. Un atacante en internet que conoce las URL públicas. Quien tenga una credencial filtrada: la clave de ElevenLabs, las keys de Twilio (la de Laravel o la de ElevenLabs), el token de firma o los secretos de webhook, iniciación y herramientas. Quien entre al panel de ElevenLabs o a la consola de Twilio. El propio LLM del agente, manipulable por voz.

**Superficies.** Los webhooks públicos (`post-call`, `initiation`, `twilio/usage-trigger`), las herramientas (`/api/voice/tools/{tool}`), la puerta de salientes (`POST /api/admin/voice/calls` → trabajo `voice`), el laboratorio hablado (URL firmada, micrófono del navegador), el CRM (`/api/admin/voice/*` y las rutas de voz del Studio), y fuera de Laravel: la API de ElevenLabs y la de Twilio con sus credenciales.

**Activos.** Las credenciales (D8-5) y la `APP_KEY` (subclaves de las huellas y de la cadena). Los teléfonos (canarios, contactos y destinos de transferencia). Las transcripciones y el audio. El dinero (minutos de ElevenLabs, Twilio y LLM). La cadena de voz y el sello de cada llamada. El OTP del login de socios. El motor de WhatsApp.

### 1.2 Abusos, controles y huecos

| # | Abuso | Superficie | Controles del contrato | Hueco, nota o hallazgo |
|---|---|---|---|---|
| A-1 | **Clave de ElevenLabs expuesta en el chat del propietario** (E1-12) | La API de ElevenLabs, desde cualquier sitio | Rotar antes de la fase 3 (E1-12); key acotada con cupo (D8-5); conciliación y freno (D8-19, D8-21) | **Hoy** (plan free, sin telefonía ni número): con la clave se pueden crear, leer, cambiar o borrar agentes (también el de verificación: `voice:doctor --verify` daría `agent_not_found`), gastar los 10 000 caracteres del mes y hablar con un agente por WebRTC. Impacto bajo-medio. **Desde la fase 2** (plan de pago con telefonía y el número importado): la misma clave llama `POST /v1/convai/twilio/outbound-call` **fuera de Laravel**, a cualquier destino que permitan los permisos de marcado de la subcuenta, y puede **reasignar el número a un agente suyo** (las entrantes al número de Iron Body las atendería otro: vishing con la marca). `CALLS_ENABLED_IN_CODE` no protege nada fuera de Laravel. → **Rotar ANTES de la fase 2** (antes del plan de pago y de importar el número), con una key nueva acotada (endpoints de agentes, salientes y URL firmada; cupo de créditos; lista de IP si la consola lo permite), puesta en el servidor sin pasar por el chat (D8-5), revocar la vieja, repetir `voice:doctor --verify` y reconocer la huella nueva. Mientras tanto, el historial del chat es un almacén de la clave |
| A-2 | **Reasignar el número a otro agente** (con A-1 o desde el panel) | La asignación del número importado | Deriva del agente (D8-21) | **Hueco:** la deriva compara la proyección de cada agente vivo, pero nada comprueba **a qué agente apunta el número**. Un agente nuevo del atacante no es «deriva» de los nuestros. → B8-b/OPS8: el monitor lee el número importado (un GET) y lo compara con el agente sellado en la cabeza de la cadena; si no cuadra, freno |
| A-3 | **E1-12 abre un cerrojo en producción** | Todas las vías de gasto | Cuatro cerrojos por vía (protección 12) | Antes de E1-12, `ELEVENLABS_NOT_CONFIGURED` hacía que la fábrica (`VoiceAdapters`) devolviera nulos: en producción no había red posible. **Ahora devuelve los adaptadores reales** (estado `ELEVENLABS_UNVERIFIED`). El cerrojo de la credencial está abierto: «cero red en la fase 1» depende ya solo de los otros tres en el código. Salientes: la constante (prueba nueva, §2). **Publicar** (`createAgent`, `assignNumber`) y **laboratorio** (`createAgent`, `signedSessionUrl`) tienen que cerrar por su bandera, su alcance y su presupuesto, no por la falta de clave → S8-b y LAB8; TEST8/SEC8-b: con la configuración de E1-12 y las banderas por defecto, recorrer las rutas de voz del Studio no saca ni una petición (como `UltronStudioIntegrationsNoNetworkTest`) |
| A-4 | Fraude de tarificación con las keys de Twilio | Twilio, subcuenta «Voz» | Solo Colombia; sin crear llamadas con la key de Laravel; conciliación; freno | **La key de Laravel tiene «Calls: update»** (para colgar). Con ella, una llamada viva se puede desviar con `Twiml` o `Url` (`<Dial>` a donde dejen los permisos de marcado). Guarda nueva: el transportista solo manda `Status=completed`, nunca `Twiml`, `Url`, `To`, `From`… Recomendación: confirmar en la consola si «update» se puede acotar; si no, los permisos de marcado de la subcuenta son la última barrera (OTP-6) |
| A-5 | Una saliente sin la puerta | El código de Laravel | `OutboundPolicy`, cerrojo, reserva, trabajo con `tries = 1` (D8-12) | Guardas nuevas: `startOutbound()` solo desde `app/Jobs/Voice/`; el trabajo con `tries = 1`, carril `voice`, sin `backoff` ni `release`; las políticas nuevas componen la del H6. B8-a añadió además la comprobación de la constante dentro del iniciador (defensa en profundidad: la prueba de E1-12 la fija) |
| A-6 | Entrante de un desconocido | Webhook de iniciación | `InboundPolicy`, `caller_authorized = no` por defecto (C-A2) | Riesgo residual aceptado para el canario (C-A2). Guarda: sin credencial válida, 401/403, sin escrituras ni red |
| A-7 | Webhook falso o repetido | Las 4 rutas públicas | HMAC, secreto con `hash_equals`, firma de Twilio, idempotencia (D8-16) | **Fallo abierto típico:** secreto sin configurar + credencial vacía → `hash_equals('', '')` = true. La guarda lo prueba con los secretos sin configurar y configurados, y la credencial ausente, basura y vacía. E1-12: en producción los dos secretos ya existen en el servidor pero no en ElevenLabs; hasta registrarlos, ElevenLabs no puede firmar nada válido (bien) |
| A-8 | Inyección por voz hacia las herramientas | `/api/voice/tools/{tool}` | Catálogo cerrado sin cobro ni descuento; la llamada existe y está en `dialing` o `active` (E1-1); 20 por llamada | C-A3 sin confirmar: si el LLM rellena `conversation_id`, puede apuntar a otra llamada viva. Impacto bajo mientras las herramientas solo devuelvan datos no personales. `no_volver_a_llamar` sobre otra llamada crearía una exclusión ajena (un DoS de una persona): B8-b debe atar la exclusión al número sellado de la llamada que pide, no a un parámetro |
| A-9 | La guarda posterior se salta | `OutboundContentGuard::inspect()` | Adaptador de solo lectura (D8-10) | `inspect()` **es pura** (confirmado: solo regex y constantes; el registro está en `assertSafe`, `OutboundContentGuard.php:710`). **Hallazgo de diseño para B8-b/S8:** devuelve **el primer motivo** en un orden fijo (tarjeta → traspaso → horario → prohibido → afirmación → precio → descuento → URL, `:577-690`). Si el adaptador perdona un código (el precio permitido por P-3 o el traspaso legítimo de D8-14) sin volver a inspeccionar, **ensombrece los de detrás**: «80.000, te lo dejo en promoción, entra a x.co» da solo `invented_price`. Corrección: pasar `handoffAllowed` según el estado real de la llamada, tapar las cifras devueltas por la herramienta ANTES de llamar a `inspect()` y nunca ignorar un código. Además solo actúa con un autor de máquina (`appliesTo`): el adaptador pasa `SENDER_AI` (la guarda solo le deja nombrar esa constante) |
| A-10 | Fuga de teléfonos y transcripciones | Registro, sello, eventos, respuestas, migraciones | Huella + 4 dígitos; cifrado solo en `voice_contacts`; sin transcripciones (D8-17) | Guardas nuevas (heurísticas, §2). El riesgo mayor es guardar el **cuerpo del webhook**, que lleva `caller_id` y la transcripción: la guarda prohíbe columnas `payload`, `raw` y `body` en las migraciones del H8 |
| A-11 | Deriva por el panel | ElevenLabs | Agente inmutable, proyección observada, freno (D8-7, D8-21) | B8-a crea las **herramientas como recursos aparte** del agente (`createAgent` hace varios POST). Esos recursos se pueden cambiar en el panel y compartir entre agentes: la deriva tiene que incluirlos (B8-a los lee en `describeAgent`: bien) y cada publicación tiene que crear los suyos, nunca reutilizar ni actualizar los de otra (la guarda impide `PATCH` fuera de `assignNumber`) |
| A-12 | El OTP comprometido a través de la voz | Credenciales y rutas | Subcuenta, validador propio, rutas propias (D8-4) | Guarda nueva: el OTP byte a byte. B-6 sigue recomendado antes de la fase 2 (OTP-1) |
| A-13 | IDOR/BFLA en llamadas y contactos; consentimiento falso | `/api/admin/voice/*` | `AuthorizationMap`, 7 llaves, cuatro ojos (D8-22, P-18) | Sin código todavía: SEC8-b y `api-security-specialist` |
| A-14 | Laboratorio hablado | URL firmada y micrófono | Cuatro cerrojos, ≤ 15 min, la clave no sale (D8-23) | Con E1-12, el cerrojo de la clave ya está abierto (A-3). Cadena de suministro del cliente y CSP: SEC8-b |

## 2. Guardas

| Guarda | Fichero | Qué la hace caer |
|---|---|---|
| Lista cerrada | `tests/Unit/Voice/VoiceIsolationTest.php` | Un fichero en `app/Services/Voice/` que no sea del H6, del contrato (más `VoiceAdapters`, la fábrica de B8-a), un nulo (uno por interfaz) o un DTO **inerte** de `Dto/`; un comando de voz con otra forma |
| La constante | ídem | `CALLS_ENABLED_IN_CODE` distinta de `false`, declarada en otro sitio, leída de otra clase o nombrada en una cadena |
| La red | ídem | `Http::` fuera de los adaptadores y de sus métodos enumerados; otro verbo; sin `withoutRedirecting` o `timeout`; `retry`, `withOptions`…; más de una petición en `startOutbound`, `verifyCredentials`, `signedSessionUrl`, `assignNumber`, `hangup` y `listCalls`; red desde la telefonía, los trabajos, los comandos o el Studio de voz |
| El transportista | ídem | `Twiml`, `Url`, `To`, `From`… hacia Twilio; colgar contra `Calls.json`; `next_page_*` dentro de una petición; una URL que no construye un método suyo con `isProduction()` |
| Fuera del H8 | ídem | `outbound-call` fuera de `ElevenLabsCallStarter`; `register-call`, `batch-calling`, `sip-trunk`; `whatsapp` en el paquete |
| Hosts | ídem | Una URL literal a un host no oficial o `http://`; red sin `isProduction()` ni `ElevenLabsConfig::baseUrl()` |
| Secretos | ídem | Un secreto de `voice.*` leído fuera de sus lectores de D8-5; un secreto nuevo sin lector; un bloque entero fuera de `ElevenLabsConfig`; las variables de voz fuera de `config/voice.php` |
| Pruebas con red | ídem | Una prueba de voz con red sin `Http::preventStrayRequests()` (en el fichero, o en una clase o rasgo del que herede) |
| Rutas reales | `tests/Feature/Voice/VoiceBoundaryTest.php` | Los prefijos de voz servidos por otros controladores, o al revés; una ruta pública fuera de las 4 de D8-16, sin POST único, sin tope o sin limitador |
| Sin credencial | ídem | Una entrada pública que, con los secretos sin configurar o configurados y la credencial ausente, basura o **vacía**, no da 401/403, escribe o sale a la red |
| Apagado por defecto | ídem | Banderas `true` o techos y presupuestos ≠ 0 (también en texto) en `config/voice.php` sin variables y en el bloque de voz de `config/ultron_studio.php`; laboratorio fuera de `fake`; un comando `voice:*` fuera del contrato o programado por defecto; base no oficial en `production` |
| **E1-12** | ídem | Con la configuración de producción (clave, agente, secretos, `ELEVENLABS_ENABLED=true`): la política deja de dar solo `calls_disabled_in_code`, el iniciador marca, o el doctor sin `--verify` sale a la red |
| Studio, motor, configuración | `tests/Feature/Telephony/TelephonyIsolationTest.php` | La telefonía nombra el Studio salvo `StudioCanaryNumber` (un adaptador sin escrituras), `StudioCanaryPhone` y la tabla de su cadena en `VoicePublishedConfig`; del motor, algo distinto de `inspect()` y las señales del lead, cada uno en un adaptador sin escrituras; el motor nombra la voz; configuración fuera de `voice.*`, `telephony.*`, `app.key` y los dos números de WhatsApp |
| Teléfonos y textos | ídem | Números escritos en el código; registro fuera del canal `voice` o con teléfonos, textos, cuerpos o volcados; claves guardadas o devueltas con un teléfono sin `_hash`, `_last4` o `_encrypted`; transcripciones; columnas de DB8 con el número, la transcripción o el cuerpo |
| Validadores y puerta única | ídem | Un controlador público sin validador; un validador sin `hash_equals` o con `fullUrl()`; `startOutbound` fuera de `app/Jobs/Voice/`; `assignNumber` fuera de la publicación; `createAgent` o `signedSessionUrl` fuera de publicación y laboratorio; políticas que no componen la del H6 |
| OTP intacto | `tests/Feature/Telephony/OtpUntouchedByVoiceTest.php` | Un byte de `app/Services/{Sms,Otp}/**`, `config/otp.php` o `TwilioUsageTriggerController` (SHA-256 de `cfcd0d1` = producción); un fichero más o menos; las 4 rutas del OTP; voz en `routes/api.php`; la voz leyendo `otp.*`, la credencial maestra o clases del OTP, y al revés |
| Líneas del H8 | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | Por cadena (m-3): `VoicePublishedConfig` solo con `'ultron_studio_voice_publications'`; la telefonía solo importa el modelo y el normalizador de canarios; migraciones `2026_11_1*voice*` por patrón; `app/Jobs/Voice/` no es el motor |
| Lista cerrada de Twilio | `tests/Unit/Twilio/TwilioReadOnlyClientTest.php` (solo la lista) | Un tercer fichero en `app/Services/Twilio/` que no sea de la voz; un adaptador de voz que dependa de algo fuera de `App\Services\{Twilio,Voice}` o nombre el Studio. Los dos de B8-a quedan fuera de «no lee configuración»: su configuración la vigilan las otras dos guardas |

**Limitaciones.** Son rastreos de tokens. Las de teléfonos y registro son heurísticas por nombre: cazan los errores corrientes, no los disimulados, y no sustituyen la lectura de SEC8-b.

**Ajustes por los ficheros legítimos de B8-a** (la lista blanca, nunca el código ajeno): `Dto/` admite cualquier nombre si el fichero es inerte; `VoiceAdapters` entra en la lista; `createAgent` y `describeAgent` pueden hacer varias peticiones (la API separa las herramientas del agente); el transportista puede leer `next_page_uri` para sacar el token, nunca pedir esa URL; `ultron-voice-spec/1` no cuenta como clave del Studio.

## 3. Lo verificado

| Comprobación | Resultado |
|---|---|
| Las 5 guardas de SEC8 sobre `75a6384` + este árbol | **41 OK** |
| `tests/Unit/Twilio` (con la lista nueva) | **41 OK** (5 846 aserciones) |
| HEAD limpio `57bab8a` + solo las guardas (copia; paso 1 antes de B8-a) | 40 OK (49 702) |
| Control positivo en copia aislada (`75a6384` + ficheros con la forma de B8-b, S8-b, LAB8 y DB8: rutas públicas y del CRM, 4 controladores que validan, políticas, puerta, trabajo, modelo, comandos, 2 migraciones, publicación, laboratorio y pruebas con su rasgo) | **41 OK** (100 232) |
| Lista cerrada de Twilio: 3 mutaciones en otra copia (tercer fichero, dependencia de `App\Support`, el validador nombra el Studio) | **3 de 3 caen**; restaurado con SHA-256 igual |
| Pint `--test` en los 6 ficheros de pruebas | passed |

**Rojo ajeno en las carpetas de voz** (`tests/Unit/Voice`, `tests/Feature/Voice`, `tests/Feature/Telephony`: 230 pruebas): 1 error y 1 fallo, **ninguno de una guarda**. `ElevenLabsCallStarterTest::test_el_cuerpo_de_la_peticion` (B8-a) usa una política de buzón que E1-9 quitó; `ElevenLabsConfigTest::test_config_voice_aplica_las_reglas_al_leer_el_entorno` (H6) fija el árbol exacto de `config/voice.php` y no conoce las claves nuevas. Las dos son de B8.

## 4. Mutaciones

Copia aislada de `75a6384` + el control positivo (`<scratchpad>/mut/backend`). Cada mutación: aplicar, correr las 5 guardas, restaurar y comparar el SHA-256 de cada fichero tocado. **82 de 82 caen; 82 restauradas con SHA-256 igual; la base vuelve a pasar después (41 OK, 100 232 aserciones).** Más las 3 de la lista cerrada de Twilio (§3): 3 de 3.

Siglas: VI `VoiceIsolationTest`, VB `VoiceBoundaryTest`, TI `TelephonyIsolationTest`, OTP `OtpUntouchedByVoiceTest`, UEI `UltronEngineIsolationTest`.

| # | Mutación | Resultado | La caza |
|---|---|---|---|
| O1 | Una línea más en TwilioVerifyService | CAE | OTP `el_codigo_del_otp_es_byte_a_byte_el_de_produccion` |
| O2 | Una línea más en config/otp.php | CAE | OTP `el_codigo_del_otp_es_byte_a_byte_el_de_produccion` |
| O3 | Un fichero nuevo en app/Services/Otp | CAE | OTP `el_codigo_del_otp_es_byte_a_byte_el_de_produccion` |
| O4 | El aviso del OTP valida con el token de la voz | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados`; OTP `el_codigo_del_otp_es_byte_a_byte_el_de_produccion`; OTP `el_otp_no_lee_la_voz` |
| O5 | El limitador del aviso del OTP pasa de 60 a 600 | CAE | OTP `las_rutas_del_otp_no_cambian_y_la_api_no_declara_nada_de_la_voz` |
| O6 | La auth de herramientas lee el token del OTP | CAE | TI `la_voz_solo_lee_su_configuracion_y_nada_del_entorno`; OTP `la_voz_no_lee_el_otp` |
| O7 | config/voice.php reutiliza la credencial maestra | CAE | OTP `la_voz_no_lee_el_otp` |
| O8 | La telefonía usa TwilioVerifyService | CAE | OTP `la_voz_no_lee_el_otp` |
| O9 | Una ruta de voz en routes/api.php | CAE | VI `ninguna_ruta_apunta_a_un_servicio_de_voz_y_los_controladores_de_voz_viven_en_su_fichero`; VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos`; OTP `las_rutas_del_otp_no_cambian_y_la_api_no_declara_nada_de_la_voz` |
| C1 | La iniciación lee el secreto de las herramientas | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C2 | El iniciador lee el secreto del webhook | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C3 | Un controlador lee el secreto de las herramientas | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C4 | Un secreto nuevo sin lector enumerado | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C5 | La telefonía lee el bloque entero de la voz | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C6 | La telefonía lee una variable de voz del entorno | CAE | VI `nadie_fuera_de_su_fichero_de_configuracion_lee_las_variables_de_voz_del_entorno`; TI `la_voz_solo_lee_su_configuracion_y_nada_del_entorno` |
| E1 | El motor importa una clase de la telefonía | CAE | TI `el_motor_no_nombra_la_voz` |
| E2 | Un trabajo del motor lee voice_calls | CAE | TI `el_motor_no_nombra_la_voz` |
| E3 | La voz usa assertSafe (registra) en vez de inspect | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores` |
| E4 | La voz importa MarketingCall | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores` |
| E5 | El adaptador del lead escribe en el lead | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores` |
| E6 | La telefonía importa el servicio de canarios | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`; UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| E7 | El adaptador de canarios cambia un estado | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena` |
| E8 | El lector de voz lee la cadena del motor | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`; UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| E9 | El trabajo de voz despacha un trabajo del motor | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores` |
| E10 | La telefonía lee la configuración del motor | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores`; TI `la_voz_solo_lee_su_configuracion_y_nada_del_entorno` |
| E11 | El Studio de voz nombra la puerta de salientes | CAE | TI `el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` |
| E12 | El Studio de voz usa el iniciador concreto | CAE | TI `el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` |
| E13 | El Studio de voz lee la cabeza de la cadena de voz | CAE | TI `el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` |
| E14 | La telefonía nombra la publicación del Studio | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`; UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| K1 | CALLS_ENABLED_IN_CODE = true | CAE | VI `las_llamadas_estan_cerradas_en_el_codigo`; VB `por_defecto_ninguna_entrada_publica_sale_a_la_red`; VB `con_elevenlabs_configurado_como_en_produccion_ninguna_llamada_sale` |
| K2 | Otra constante CALLS_ENABLED_IN_CODE en la telefonía | CAE | VI `las_llamadas_estan_cerradas_en_el_codigo` |
| K3 | La constante leída por su nombre en una cadena | CAE | VI `las_llamadas_estan_cerradas_en_el_codigo`; TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| K4 | OutboundPolicy no compone la política del H6 | CAE | TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| K5 | InboundPolicy no mira la constante | CAE | TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| K6 | E1-12: con ElevenLabs configurado, el iniciador marca sin mirar la constante | CAE | VB `con_elevenlabs_configurado_como_en_produccion_ninguna_llamada_sale` |
| H1 | El iniciador escribe el host del simulador | CAE | VI `hosts_oficiales_y_la_base_se_decide_en_produccion` |
| H2 | El transportista decide la base sin isProduction() | CAE | VI `el_transportista_solo_cuelga_y_lista`; VI `hosts_oficiales_y_la_base_se_decide_en_produccion` |
| H3 | El desplegador lee la base configurable a mano | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| H4 | El iniciador sigue redirecciones | CAE | VI `la_red_solo_sale_de_los_metodos_enumerados` |
| H5 | El iniciador reintenta | CAE | VI `la_red_solo_sale_de_los_metodos_enumerados` |
| H6 | En production la base de ElevenLabs sigue la configurada | CAE | VB `en_produccion_la_base_de_elevenlabs_es_la_oficial` |
| N1 | El desplegador gana updateAgent (PATCH a un agente) | CAE | VI `la_red_solo_sale_de_los_metodos_enumerados` |
| N2 | La telefonía sale a la red directamente | CAE | VI `la_red_solo_sale_de_los_metodos_enumerados`; VI `hosts_oficiales_y_la_base_se_decide_en_produccion` |
| N3 | outbound-call fuera del iniciador | CAE | VI `outbound_call_solo_en_el_iniciador_y_nada_de_register_call_lotes_sip_ni_whatsapp` |
| N4 | Llamadas por lotes en el paquete | CAE | VI `outbound_call_solo_en_el_iniciador_y_nada_de_register_call_lotes_sip_ni_whatsapp` |
| N5 | Colgar manda TwiML a la llamada viva | CAE | VI `el_transportista_solo_cuelga_y_lista`; TI `sin_numeros_escritos_en_el_codigo` |
| N6 | El CRM inicia la saliente sin la puerta | CAE | TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| N7 | El laboratorio asigna el número a un agente | CAE | TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| N8 | El trabajo de la saliente reintenta | CAE | TI `una_sola_puerta_de_salientes_y_cada_accion_del_proveedor_desde_su_sitio` |
| N9 | La interfaz del desplegador gana deleteAgent | CAE | VI `el_puerto_no_sabe_llamar_a_nadie` |
| N10 | Un DTO sale a la red | CAE | VI `estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato`; VI `la_red_solo_sale_de_los_metodos_enumerados`; VI `hosts_oficiales_y_la_base_se_decide_en_produccion`; VI `el_espacio_es_neutro_y_sin_estado` |
| N11 | Un fichero nuevo fuera de la lista cerrada | CAE | VI `estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato`; VI `outbound_call_solo_en_el_iniciador_y_nada_de_register_call_lotes_sip_ni_whatsapp` |
| N12 | Colgar crea una llamada (POST a Calls.json) | CAE | VI `el_transportista_solo_cuelga_y_lista` |
| N13 | El transportista pide la URL de next_page_uri | CAE | VI `el_transportista_solo_cuelga_y_lista` |
| N14 | El transportista pide una URL que no construye él | CAE | VI `el_transportista_solo_cuelga_y_lista` |
| P1 | El registro lleva un teléfono | CAE | TI `el_registro_de_la_voz_va_por_su_canal_y_sin_telefonos_ni_textos`; TI `lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado` |
| P2 | Registro fuera del canal voice | CAE | TI `el_registro_de_la_voz_va_por_su_canal_y_sin_telefonos_ni_textos` |
| P3 | El sello guarda el número en claro | CAE | TI `lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado` |
| P4 | Un número escrito en el código | CAE | TI `sin_numeros_escritos_en_el_codigo` |
| P5 | Una columna con el número en claro | CAE | TI `lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado` |
| P6 | Una columna con el cuerpo del webhook | CAE | TI `lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado` |
| P7 | El verificador del paquete registra | CAE | VI `el_espacio_es_neutro_y_sin_estado`; TI `el_registro_de_la_voz_va_por_su_canal_y_sin_telefonos_ni_textos` |
| P8 | El modelo declara la transcripción | CAE | TI `lo_que_se_guarda_o_se_devuelve_lleva_huella_cuatro_digitos_o_cifrado` |
| P9 | El registro vuelca un cuerpo crudo | CAE | TI `el_registro_de_la_voz_va_por_su_canal_y_sin_telefonos_ni_textos` |
| W1 | La herramienta no autentica | CAE | VB `sin_credencial_ninguna_entrada_publica_de_voz_pasa_ni_escribe_ni_sale_a_la_red` |
| W2 | Auth de herramientas que abre con secreto y credencial vacíos | CAE | VB `sin_credencial_ninguna_entrada_publica_de_voz_pasa_ni_escribe_ni_sale_a_la_red` |
| W3 | El post-call sin tope de cuerpo | CAE | VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos` |
| W4 | Un controlador público sin validador | CAE | TI `cada_entrada_publica_llega_a_su_validador_y_los_validadores_comparan_bien` |
| W5 | El validador de Twilio usa fullUrl() | CAE | TI `cada_entrada_publica_llega_a_su_validador_y_los_validadores_comparan_bien` |
| W10 | El validador de Twilio compara sin hash_equals | CAE | TI `cada_entrada_publica_llega_a_su_validador_y_los_validadores_comparan_bien` |
| W6 | Una ruta pública que no es de D8-16 | CAE | VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos`; VB `sin_credencial_ninguna_entrada_publica_de_voz_pasa_ni_escribe_ni_sale_a_la_red` |
| W7 | Las herramientas aceptan cualquier verbo | CAE | VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos` |
| W8 | La auth de herramientas compara con === | CAE | TI `cada_entrada_publica_llega_a_su_validador_y_los_validadores_comparan_bien` |
| W9 | Un controlador de voz servido fuera de su prefijo | CAE | VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos` |
| T1 | Una prueba de la telefonía con Http::fake sin cerrar la red | CAE | VI `las_pruebas_de_voz_con_red_cierran_la_red` |
| T2 | Una prueba unitaria del iniciador sin cerrar la red | CAE | VI `las_pruebas_de_voz_con_red_cierran_la_red` |
| T3 | Una prueba del Studio de voz que llama rutas sin cerrar la red | CAE | VI `las_pruebas_de_voz_con_red_cierran_la_red` |
| U1 | Una migración que no es de voz nombra el Studio | CAE | UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| U2 | El trabajo de voz lee la configuración del Studio | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`; TI `la_voz_solo_lee_su_configuracion_y_nada_del_entorno`; UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| D1 | La bandera de entrantes nace encendida | CAE | VB `todo_apagado_por_defecto` |
| D2 | El presupuesto diario nace en 5 | CAE | VB `todo_apagado_por_defecto` |
| D3 | Un comando de voz que no está en el contrato | CAE | VB `los_comandos_de_voz_son_los_del_contrato_y_por_defecto_ninguno_esta_programado` |

## 5. Para el lead

1. **Rotar la clave de ElevenLabs antes de la fase 2**, no de la 3 (A-1): el riesgo de llamadas fuera de Laravel empieza al importar el número. Propuesta de texto para la Enmienda: «antes de la fase 2».
2. **Hueco de vigilancia (A-2):** nadie mira a qué agente apunta el número importado. Una línea en D8-21 para B8-b/OPS8.
3. **E1-12 y la fase 1 (A-3):** el cerrojo de la credencial está abierto en producción. S8-b y LAB8 tienen que cerrar publicar y el laboratorio por su bandera; hace falta una prueba de cero red con esa configuración en el paso 2.
4. **Toqué `tests/Unit/Twilio/TwilioReadOnlyClientTest.php`** (solo su lista cerrada), según tu indicación de ajustar la lista blanca: caía sobre HEAD por los dos ficheros de B8-a que el contrato pone en `app/Services/Twilio/`. Si no lo quieres, es una vuelta atrás de un bloque.
5. **Roturas previstas en el paso 2, no tocadas** (no son lista blanca): las ~20 rutas de voz del Studio rompen el recuento de 56 de `UltronEngineIsolationTest` y el de `UltronStudioEngineReadOnlyTest` (propuesta: 56 exactas sin voz y las de voz solo en `UltronStudioVoice(Lab)Controller`); si OPS8 programa `voice:*` dentro del bloque del monitor, cae la regla m-3 (propuesta: admitir `voice:` con argumentos literales).
6. **D8-2 incoherente:** el Studio solo puede nombrar `VoicePublishedConfig::signatureFor()` de la telefonía, pero tiene que leer la evidencia del canario y soltar el freno. Hay que enumerar uno o dos adaptadores antes del paso 2; hasta entonces la guarda cae a propósito.
7. **`StudioCanaryPhone`:** D8-2 no lo nombra y la auditoría (OTP-4) lo pide. Lo admití (es puro; solo `parse`, `masked` y `message`).
8. **Huellas:** D8-5 pide un único punto; B8-a calcula la de cada secreto en su lector (tres puntos). Es neutro o mejor para la seguridad; conviene anotarlo en el contrato.
9. **A-8:** `no_volver_a_llamar` tiene que excluir el número sellado de la llamada que pide, nunca uno que llegue como parámetro.

## 6. Ficheros

**Guardas de SEC8** (las cinco primeras ya en el WIP `1cdaf4d`; desde entonces cambió `VoiceBoundaryTest`):
- `tests/Unit/Voice/VoiceIsolationTest.php`
- `tests/Feature/Voice/VoiceBoundaryTest.php` (prueba de E1-12 y comentarios)
- `tests/Feature/Telephony/TelephonyIsolationTest.php`
- `tests/Feature/Telephony/OtpUntouchedByVoiceTest.php`
- `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` (solo las líneas del H8)

**Lista blanca ajena ajustada:** `tests/Unit/Twilio/TwilioReadOnlyClientTest.php` (solo la lista cerrada; §5.4).

**Informe:** `docs/ultron-studio/plans/informes-h8/informe-security.md` (este).

**Fuera del repositorio** (scratchpad, desechable): el banco `tools/banco.php` (27 operaciones de control y las mutaciones) y `tools/correr.php`, y las copias `mut/` y `mut2/`.
