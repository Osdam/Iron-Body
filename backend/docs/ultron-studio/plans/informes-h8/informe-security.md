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

---

# §SEC8-b-lite · paso 2, puerta 2 · guardas ceñidas al contrato (`security`)

**Fecha:** 08-11-2026. **Base:** `d6ed5ba` (los anexos B y C, confirmados por el lead). En paralelo, `backend` (B8-b) edita `app/Services/{Telephony,Voice}/**`, `config/voice.php` y sus pruebas, sin commit. **Sin commit.** Rutas relativas a `backend/`. Para separar mi trabajo del suyo, las cifras limpias salen de una **copia aislada: `d6ed5ba` + solo mis tres guardas** (`<scratchpad>/mut/backend`). El árbol compartido se midió aparte (§5).

## 0. Resumen

- **Los 4 fallos de E4-3 y E4-4 pasan a verde** con cuatro excepciones. Cada una va con su fichero exacto, su nombre exacto y la referencia del contrato junto a la lista blanca. Sin listas abiertas y sin `markTestSkipped`.
- **Anexos verificados.** El C (E2-6) y el B (las 8 del Studio y el recuento de rutas) pasan.
  - Al verificar el C vi que admitía **más de lo que permite E2-6**: cualquier miembro de `VoiceBrake`, también `open()`. Lo ciño al contrato (§2, H-1).
- **Cuatro endurecimientos** en las mismas guardas (§2). No los pidió nadie: tres cierran huecos que mis mutaciones dejaban vivos y uno ciñe el Anexo C. Cada uno se revierte por bloque.
- **Cifras en la copia:**

  | Suite | Pruebas | Aserciones | Fallos | Omitidas |
  |---|---|---|---|---|
  | Las 14 citadas | 42 ejecuciones (13 métodos + 29 casos de `test_route_matrix`) | 9 232 | 0 | — |
  | Voz y telefonía, SQLite | 675 | 184 545 | 0 | 131 (solo PG) |
  | Studio, SQLite | 3 085 | 32 201 | 0 | 179 (solo PG) |
  | Puerta PG | 295 | 160 254 | 0 | — |

  Pint, `passed`.
- **Mutaciones:** caen **26 de 26**, y 1 control no cae (reordenar las constantes del lector). Las 27 se restauraron con el SHA-256 igual. D4–D9 **sobreviven con las guardas de `d6ed5ba`** y caen con las nuevas.
- **Árbol compartido:** las ediciones en curso de B8-b para E4-7 y E4-8 hacen caer guardas **a propósito**. No las adapté (§5).
- **Revisión** (§6):
  - 1 hallazgo **medio**: los limitadores de las 4 rutas públicas comparten un contador por IP, y ElevenLabs sale por IP comunes a todos sus clientes;
  - 1 **bajo**: lo que permite el secreto de iniciación filtrado;
  - S8-b, sin hallazgos.

## 1. Ajustes que permite el contrato

| Prueba | Fichero:línea | Qué se admite, exactamente | Contrato |
|---|---|---|---|
| `TelephonyIsolationTest::…canarios_y_su_cadena` | `tests/Feature/Telephony/TelephonyIsolationTest.php:89-99, 182, 207-213` | `'ultron_studio_voice_agents'`, además de `'ultron_studio_voice_publications'`, **solo** en `app/Services/Telephony/VoicePublishedConfig.php`. Del Studio, exactamente 2 apariciones de `ultron_studio_`, una de cada tabla. Sigue sin escrituras | E4-3 |
| `UltronEngineIsolationTest::…nadie_nombra…` | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:99-113, 208-211, 515-525` | Las dos referencias, una vez cada una y solo en ese fichero. Se comparan como conjunto: el orden de las constantes no cuenta (control A8) | E4-3 |
| `TelephonyIsolationTest::…dos_adaptadores` | `TelephonyIsolationTest.php:127-136, 250-252` | El literal `voice_number_is_whatsapp`, **solo** en `app/Services/Telephony/OutboundPolicy.php` | E4-4 (motivo de D8-5 y D8-12) |
| `VoiceIsolationTest::…lectores_enumerados` | `tests/Unit/Voice/VoiceIsolationTest.php:147-154, 520-522` | El literal `telephony`, **solo** en dos sitios y nunca como argumento de una lectura de configuración (`config()`, `Config::`, `config()->`):<br>• `app/Support/Access/CrmPermission.php:266`, en `SHARED_TOKEN_EXCLUDED_DOMAINS`;<br>• `app/Support/Access/PermissionCatalog.php:57`, en la ficha del dominio | E4-4 (D8-22) |

## 2. Endurecimientos (dentro de las mismas guardas)

| # | Qué | Por qué | Dónde |
|---|---|---|---|
| H-1 | De la evidencia y del freno, el Studio solo usa `VoiceEvidence::forPublication`, `VoiceBrake::{isOpen, list, release}`, `::class` (para `method_exists`) y las constantes | El Anexo C admitía cualquier miembro, pero E2-6 dice «lee la evidencia y suelta el freno». `VoiceBrake::open()` o `VoiceEvidence::summary()` desde el Studio pasaban (D4, D7) | `TelephonyIsolationTest.php:62-75, 515-523` |
| H-2 | La comprobación de miembros vale también con el nombre cualificado | `\App\Services\Telephony\VoicePublishedConfig::read()` pasaba porque solo se miraba el nombre corto (D5, D6). Hueco de SEC8-a | ídem |
| H-3 | El Studio de voz no nombra tablas de la telefonía (`voice_calls`…`voice_brakes`, tampoco con alias ni con columna) | E2-6: «nunca escribe en `voice_*` directamente». Ninguna guarda lo miraba (D8, D9) | `TelephonyIsolationTest.php:140-142, 524-526` |
| H-4 | Un prefijo de un solo segmento (`'telephony.'`, `'voice.'`) cuenta como bloque | El patrón exigía dos puntos (`\.[a-z0-9_.]*\.$`), así que `'telephony.'.$x` leía el bloque sin caer (C6). Hueco de SEC8-a. No hay ningún literal así en `app`, `routes`, `config`, `bootstrap` ni `database` | `VoiceIsolationTest.php:517-519` |

- Los cuatro pasan hoy con el código de `d6ed5ba`: el Studio usa justo esos miembros y ninguna tabla `voice_*`.
- **Límite:** siguen siendo rastreos de tokens. No ven una llamada dinámica (`[VoiceBrake::class, 'open']()`) ni un nombre de tabla compuesto en tiempo de ejecución.

## 3. Lo verificado

| Comprobación | Dónde | Resultado |
|---|---|---|
| Las 14 pruebas citadas: las 6 guardas de E4-3, E4-4, E2-6 y del recuento, y las 8 del Studio del Anexo B | copia | 42 ejecuciones OK, 9 232 aserciones |
| Las 3 guardas tocadas | árbol, antes de que B8-b empezara a editar | 30 OK (128 432 aserciones) |
| `tests/Feature/Telephony tests/Feature/Voice tests/Unit/Voice tests/Unit/Twilio tests/Unit/Telephony`, SQLite | copia | 675 OK, 184 545 aserciones, 131 omitidas (solo PG) |
| `tests/Unit/UltronStudio tests/Feature/UltronStudio`, SQLite | copia | 3 085 OK, 32 201 aserciones, 179 omitidas |
| `tests/Feature/Telephony tests/Feature/Voice`, PG desechable (`UNIT_DB=ultron_studio_h8_sec_copia`) | copia | 295 OK, 160 254 aserciones |
| Pint `--test` en las 3 guardas | árbol | passed |
| Las mismas suites en el árbol compartido | árbol, con B8-b en curso | §5 |

Las 8 del Studio, por nombre:
- `UltronStudioEarlyBodyCapTest::test_el_tope_global_es_el_de_las_56_rutas_del_studio`;
- `UltronStudioEngineReadOnlyTest::test_recorrer_todas_las_rutas_del_studio_no_cambia_nada_del_motor`;
- `UltronStudioIntegrationsParityHttpTest::test_route_matrix` (29 casos);
- `UltronStudioIntegrationsPermissionsTest::test_el_mapa_resuelve_exactamente_las_56_rutas_y_las_18_nuevas_con_su_permiso` y `::test_valores_por_defecto_catalogo_y_token_compartido`;
- `UltronStudioLabNoEngineEffectsTest::test_el_contrato_de_las_21_rutas_de_f_y_g_no_cambia`;
- `UltronStudioPermissionsTest::test_el_catalogo_describe_las_ocho_llaves_en_su_orden` y `::test_el_mapa_resuelve_exactamente_las_38_rutas_del_contrato`.

Ninguna sigue cayendo con el Anexo B: no hubo nada que diagnosticar.

## 4. Mutaciones

**Procedimiento** (en la copia; el árbol compartido no se tocó):
1. aplicar la mutación y comprobar su sintaxis con `php -l`;
2. correr las 3 guardas, con el resultado en JUnit;
3. restaurar y comparar el SHA-256.

**Resultado:**
- base: 30 OK; después: 30 OK;
- **caen 26 de 26**;
- el control A8 no cae;
- las 27 se restauraron con el SHA-256 igual.

Siglas: TI `TelephonyIsolationTest`, VI `VoiceIsolationTest`, UEI `UltronEngineIsolationTest`.

| # | Mutación | Fichero | Resultado | La caza |
|---|---|---|---|---|
| A1 | Otro fichero de la telefonía nombra la tabla de los agentes | `app/Services/Telephony/VoiceEvidence.php` | CAE | TI `del_studio_la_telefonia_solo_nombra_los_canarios_y_su_cadena`; UEI `fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` |
| A2 | El lector nombra la tabla de los perfiles (lo que pediría E4-7) | `app/Services/Telephony/VoicePublishedConfig.php` | CAE | TI `…canarios_y_su_cadena`; UEI `…nadie_nombra…` |
| A3 | El lector nombra dos veces la tabla de los agentes | ídem | CAE | TI `…canarios_y_su_cadena`; UEI `…nadie_nombra…` |
| A4 | El lector nombra la cadena del motor | ídem | CAE | TI `…canarios_y_su_cadena`; UEI `…nadie_nombra…` |
| A5 | El lector escribe en la tabla de los agentes | ídem | CAE | TI `…canarios_y_su_cadena` |
| A6 | El lector importa un modelo del Studio | ídem | CAE | TI `…canarios_y_su_cadena`; UEI `…nadie_nombra…` |
| A7 | El lector lee la configuración del Studio | ídem | CAE | TI `…canarios_y_su_cadena`; TI `la_voz_solo_lee_su_configuracion_y_nada_del_entorno`; UEI `…nadie_nombra…` |
| A8 | **Control:** el lector reordena sus dos constantes | ídem | no cae (bien) | — |
| B1 | Otro fichero de la telefonía nombra el motivo del número | `app/Services/Telephony/VoiceTelephonyStatus.php` | CAE | TI `del_motor_la_voz_solo_lee_por_los_dos_adaptadores` |
| B2 | La política de salientes nombra otro literal con «whatsapp» | `app/Services/Telephony/OutboundPolicy.php` | CAE | TI `…dos_adaptadores` |
| B3 | La política de salientes lee una clave de Meta, que ya lee `VoiceNumberGuard` | ídem | CAE | TI `…dos_adaptadores` |
| B4 | Un fichero neutro nombra el motivo del número | `app/Services/Voice/VoiceCallContext.php` | CAE | TI `…dos_adaptadores`; VI `outbound_call_solo_en_el_iniciador_y_nada_de_register_call_lotes_sip_ni_whatsapp` |
| C1 | Otro fichero de Access nombra el literal `telephony` | `app/Support/Access/AuthorizationMap.php` | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C2 | Un servicio de la telefonía lee el bloque `telephony` | `app/Services/Telephony/VoiceCostGuard.php` | CAE | VI `…lectores_enumerados` |
| C3 | `CrmPermission` lee el bloque con `config()` | `app/Support/Access/CrmPermission.php` | CAE | VI `…lectores_enumerados` |
| C4 | `PermissionCatalog` lee el bloque con `Config::get()` | `app/Support/Access/PermissionCatalog.php` | CAE | VI `…lectores_enumerados` |
| C5 | `CrmPermission` lee el bloque con `config()->get()` | `app/Support/Access/CrmPermission.php` | CAE | VI `…lectores_enumerados` |
| C6 | `CrmPermission` nombra el prefijo `'telephony.'` | ídem | CAE (con H-4) | VI `…lectores_enumerados` |
| D1 | Otro fichero del Studio de voz importa el freno | `app/Services/UltronStudio/StudioVoicePublishing.php` | CAE | TI `el_studio_de_voz_solo_nombra_los_adaptadores_enumerados` |
| D2 | `StudioVoiceEvidence` importa otra clase de la telefonía | `app/Services/UltronStudio/StudioVoiceEvidence.php` | CAE | TI `…adaptadores_enumerados` |
| D3 | `StudioVoiceEvidence` importa un modelo de la voz | ídem | CAE | TI `…adaptadores_enumerados` |
| D4 | `StudioVoiceEvidence` abre un freno | ídem | CAE (con H-1) | TI `…adaptadores_enumerados` |
| D5 | Ídem, con el nombre cualificado | ídem | CAE (con H-1 y H-2) | TI `…adaptadores_enumerados` |
| D6 | El Studio llama a `VoicePublishedConfig::read()` con el nombre cualificado | `app/Services/UltronStudio/StudioVoicePublishing.php` | CAE (con H-2) | TI `…adaptadores_enumerados` |
| D7 | `StudioVoiceEvidence` pide `VoiceEvidence::summary()` | `app/Services/UltronStudio/StudioVoiceEvidence.php` | CAE (con H-1) | TI `…adaptadores_enumerados` |
| D8 | `StudioVoiceEvidence` inserta en `voice_brakes` directamente | ídem | CAE (con H-3) | TI `…adaptadores_enumerados` |
| D9 | `UltronStudioVoiceController` lee `voice_calls as c` | `app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php` | CAE (con H-3) | TI `…adaptadores_enumerados` |

**Contraprueba de los endurecimientos.** Con las tres guardas de `d6ed5ba` puestas otra vez en la copia, en modo relativo (los 4 fallos previstos como base):
- D1–D3 caen;
- **D4, D5, D6, D7, D8 y D9 sobreviven**.

El hueco de C6 se ve en el patrón de `d6ed5ba` sin correrlo: VI ya cae ahí por E4-4.

## 5. Árbol compartido: lo que B8-b tiene en curso y cae (no adaptado)

**Foto del 08-11 a las 11:37** (`git status` igual antes y después de correr): 23 ficheros cambiados y 7 nuevos de B8-b (E4-1, E4-2, E4-7 y E4-8, sin commit).

| Suite | Resultado |
|---|---|
| Voz y telefonía, SQLite | 701 pruebas, 188 507 aserciones, **3 fallos**, los tres de guardas y por el trabajo nuevo |
| Studio, SQLite | 3 085 pruebas, 32 203 aserciones, **1 fallo**: UEI `…nadie_nombra…` (E4-7); 179 omitidas |
| PG (`tests/Feature/Telephony tests/Feature/Voice`, `UNIT_DB=ultron_studio_h8_sec`) | 317 pruebas, 165 113 aserciones, **1 fallo**: TI `…canarios_y_su_cadena` (E4-7). Las de VI no entran en esta puerta (son `tests/Unit`) |

| Cae | Fichero y motivo | Qué hace falta |
|---|---|---|
| TI `…canarios_y_su_cadena` y UEI `…nadie_nombra…` | `app/Services/Telephony/VoicePublishedConfig.php:92` declara `PROFILES = 'ultron_studio_voice_profiles'` y hace JOIN al perfil (`:368`) para los destinos de E4-7. **E4-3 solo admite la cadena y los agentes** | **Una decisión del lead.** Si se enmienda E4-3 para el perfil, SEC8 añade la tercera tabla (solo en ese fichero, una vez) en TI y UEI |
| VI `estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato` | `app/Services/Voice/VoiceNumberObserver.php` (interfaz nueva de E4-8) no está en la lista cerrada | Ver abajo (E4-8) |
| VI `la_red_solo_sale_de_los_metodos_enumerados` | `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php` sale a la red desde `describeNumber`, que no está enumerado | Ver abajo (E4-8) |

**E4-8: lo que SEC8 enumerará cuando el lead lo confirme** (no enumerado, como pediste). B8-b eligió una **interfaz segregada nueva**, `VoiceNumberObserver`, en lugar de ampliar `VoiceAgentDeployer`. Tiene su nulo `NullVoiceNumberObserver`, el DTO `Dto/VoiceNumberAssignment`, la fábrica `VoiceAdapters::numberObserver()` y la usa `VoiceReconciler::numbers()`. En `VoiceIsolationTest` hará falta:
1. `PAQUETE_H8`: añadir `VoiceNumberObserver.php`. El nulo entra por su patrón y el DTO por `Dto/`, si es inerte.
2. `INTERFACES`: `'VoiceNumberObserver' => ['describeNumber']`. Sin esto cae también el nulo («implementa una interfaz que no es del paquete»).
3. `RED`: `self::DESPLEGADOR => [… 'describeNumber' => ['get']]`.
4. `UNA_PETICION`: `self::DESPLEGADOR.'::describeNumber'`, porque es un GET.
5. Opcional, para decidir: llevar `describenumber` a la puerta única de TI (`$quien`), solo desde `app/Services/Telephony/VoiceReconciler.php` (y `StudioVoiceDrift`, si S8 la usa). Es solo lectura, pero es red.

**Nota de seguridad sobre E4-7** (no bloquea):
- Hoy `VoicePublishedConfig` descifra en la telefonía `transfer_destinations_encrypted` (`Crypt::decryptString`, `:336`) solo para leer la huella `phoneHash` de cada destino. Los números del personal pasan por la memoria de la telefonía sin necesidad.
- **Menor privilegio:** que S8 guarde las huellas `voice-phone` de los destinos aprobados **sin cifrar**, en la fila del perfil o del agente. Una huella no es el número. Así la telefonía no descifra nada del Studio.
- Encaja con la unificación en `PhoneFingerprint` de E4-9.

## 6. Revisión de seguridad: B8-b en `61513a1` (controladores públicos de voz) y S8-b

Leído el código de `61513a1`, que es `d6ed5ba` en `app/`: las 4 rutas públicas de `routes/voice.php`, sus 4 controladores y lo que llaman (`VoiceInitiationAuth`, `VoiceToolAuth`, `ElevenLabsEventVerifier`/`ElevenLabsWebhookSignature`, `TwilioRequestSignature`, `InboundGate`, `VoiceTools`, `VoicePostCall`, `TwilioUsageAlert`, `LimitRequestBodySize`) y `UltronStudioVoiceController` con sus servicios. Los controladores del CRM (`Crm/Voice`) y el BOLA de llamadas y contactos quedan para el SEC8-b completo (paso 4) con `api-security-specialist`.

### M-1 · Media · Los limitadores de las rutas públicas cuentan, antes de autenticar, en un contador por IP que comparten las 4 rutas y todos los clientes de ElevenLabs

**Dónde:**
- `routes/voice.php:36, 40, 45, 49`: `throttle:240,1`, `120,1`, `600,1` y `60,1`.
- Sin usuario, la clave del limitador es `sha1(dominio|IP)`, **sin la ruta ni el prefijo**: `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:98, 227` (v12.58.0). Las 4 rutas suben **un mismo contador** por IP, cada una con su tope.
- El limitador corre antes del controlador, así que **una petición con credencial mala también cuenta**.
- ElevenLabs manda **todo** su tráfico saliente (webhooks post-llamada y herramientas de webhook incluidos) desde dos IP estáticas por región, comunes a todos sus servicios ([IP allowlisting](https://elevenlabs.io/docs/eleven-api/resources/ip-allowlisting)).

**Cómo se explota** (desde la fase 3; hoy todo está apagado):
1. Cualquiera con una cuenta de ElevenLabs crea un agente con una herramienta de webhook o un webhook post-llamada que apunta a `https://<host>/api/voice/tools/consultar_planes`, y lo hace llamar en bucle desde unas pocas conversaciones.
2. Esas peticiones salen de las mismas IP que las nuestras. Con más de 120 por minuto en ese contador, **la iniciación de nuestras entrantes recibe 429**. ElevenLabs sigue entonces con los valores por defecto (`caller_authorized = no`) y el canario oye «línea no disponible». Falla cerrado, pero es una denegación de la línea.
3. Con más de 240 por minuto, **el post-llamada recibe 429**. Según D8-16 y la auditoría, ElevenLabs desactiva el webhook tras 10 fallos (**por confirmar** que cuente los 429). Mientras siga desactivado no hay guarda posterior (capa 4 de D8-10), ni `seal_verified`, ni coste por webhook, y **una violación no acciona el freno**.
4. Con más de 600 por minuto, las herramientas reciben 429.

**Corrección** (B8, `routes/voice.php` y los limitadores con nombre; no es estructural):
- no limitar por IP **antes** de autenticar en estas 4 rutas;
- limitar **después** de autenticar, con una clave por ruta y, cuando exista, por llamada. Las herramientas ya tienen el tope de 20 por llamada en la base; la iniciación es idempotente por `call_sid` y el post-llamada deduplica por evento;
- los fallos de autenticación, en un cubo propio (`ruta|IP|fallo`), para que el tráfico malo no gaste el presupuesto del bueno.

**Coste:** quien tenga una credencial válida solo queda limitado por llamada. Es aceptable, porque la credencial es la defensa principal.

**Nota para P-26:** la lista de IP de ElevenLabs no distingue nuestro workspace del de otro cliente. Solo frena lo que no sale de ElevenLabs, así que es un refuerzo débil. La firma y los secretos son el control real.

### B-1 · Baja · El secreto de iniciación es una credencial estática sin atadura al cuerpo (diseño de D8-16), y filtrado da tres palancas

**Dónde:** `app/Http/Controllers/Api/Voice/ElevenLabsInitiationController.php:27` y `app/Services/Telephony/InboundGate.php:63-66, 117-119, 157-161`.

Con el secreto (no la firma: la iniciación no la tiene), quien lo tenga puede:
- **Saber si un teléfono es un canario de voz activo.** `caller_authorized` responde `si` o `no` para el `caller_id` que mande, con el número de voz, que es público, en `called_number`.
- **Echar el freno de voz.** Basta un `agent_id` distinto del sellado mientras haya publicación (`seal_mismatch`, `VoiceBrake::open`). La voz queda parada hasta que una persona con `ultron.voice_publish` lo suelte.
- **Ocupar la concurrencia y el cupo diario de entrantes.** Con el número de un canario puede crear filas `active` que no terminan hasta la conciliación («atascadas»).

**Corrección:** son consecuencias del diseño (D8-16, el secreto en una cabecera fija). Hay que guardar el secreto como el resto de D8-5 y rotarlo si se sospecha. La lista de IP (P-26) no ayuda contra otro cliente de ElevenLabs (M-1). Si ElevenLabs llega a ofrecer firma en la iniciación, migrar a ella.

### Confirmado sin hallazgo

- **Firmas en tiempo constante y fallo cerrado.**
  - Herramientas e iniciación: `hash_equals`, y sin secreto configurado o con la cabecera vacía no pasa nada, nunca `hash_equals('', '')` (`VoiceToolAuth.php:22-31`, `VoiceInitiationAuth.php:24-29`).
  - Post-llamada: `hash_equals` sin cortocircuito entre las variantes, ventana de 1 800 s hacia atrás y 300 hacia delante, y `reason` de una lista cerrada (`ElevenLabsWebhookSignature.php:98-111`, `VoiceWebhookVerdict.php:111`).
  - Twilio: el token vacío da `not_configured`; base canónica + `getRequestUri()`, nunca `fullUrl()`; `bodySHA256` único, hexadecimal y comparado con `hash_equals`; variantes de puerto sin cortocircuito (`TwilioRequestSignature.php:152-166, 199-241, 281-288`).
- **Tope de cuerpo antes de leer.** `LimitRequestBodySize` mide `Content-Length` y la longitud real antes del controlador, en las 4 rutas (`routes/voice.php:34-50`). Que el JSON lo pueda decodificar antes un middleware global es lo ya conocido (M-2 del Hito G).
- **Cero datos personales en respuestas y registros.**
  - La iniciación responde solo `caller_authorized`, `call_direction`, `call_ref` y `version_label`, y guarda del llamante solo la huella y los 4 últimos dígitos (`InboundGate.php:137-138, 178-189`).
  - Las herramientas devuelven planes, horario, alias y un booleano.
  - Todos los registros van por `Log::channel('voice')`, con uuid, códigos y booleanos.
  - `no_volver_a_llamar` excluye **el número sellado de la llamada** (`remote_hash`), nunca un parámetro (A-8 de SEC8-a, cerrado: `VoiceTools.php:216-237`).
  - Las herramientas exigen la llamada viva, autorizada si es entrante, sin freno y con cupo (`VoiceTools.php:57-90`). Si el cuerpo trae `call_ref`, tiene que ser el de esa llamada (`:131-133`).
- **S8-b (`UltronStudioVoiceController`).**
  - El permiso de cada ruta sigue D8-22 (`:52-68`).
  - El contenido del perfil es un conjunto cerrado (`profile_field_unknown`, `StudioVoiceProfiles.php:424-433`), aunque el controlador pasa `content` crudo (`:160`).
  - Los destinos se devuelven como alias y 4 dígitos (`:112-118`).
  - `compile-preview` devuelve la `VoiceAgentSpec`, con alias y huella del destino, nunca el número (`VoiceAgentSpec.php:32, 566-571`).
  - Publicar devuelve del número solo la acción y el resultado (`StudioVoicePublishing.php:670-682`).
  - La URL firmada del laboratorio (LAB8) sale una sola vez, al crear la sesión: ni al repetir la petición ni en el detalle (`StudioVoiceLabSessions.php:257, 339-342, 371`).

## 7. Para el lead

1. **E4-7 choca con E4-3.** El JOIN al perfil nombra una tercera tabla del Studio. Hace falta enmendar E4-3, o que S8 exponga las huellas de los destinos fuera del perfil (es lo recomendado, §5). Hasta entonces, TI y UEI caen a propósito en el árbol compartido.
2. **E4-8:** la lista de §5, para cuando B8-b confirme `VoiceNumberObserver`.
3. **M-1** va a B8 (límites después de autenticar en las 4 rutas públicas) antes de la fase 3.
4. **Los endurecimientos H-1 a H-4** no estaban pedidos. Si no los quieres, cada uno es una vuelta atrás de un bloque.

## 8. Ficheros

- `tests/Feature/Telephony/TelephonyIsolationTest.php`: E4-3, E4-4, H-1, H-2 y H-3.
- `tests/Unit/Voice/VoiceIsolationTest.php`: E4-4 y H-4.
- `tests/Feature/UltronStudio/UltronEngineIsolationTest.php`: E4-3, solo las líneas del H8.
- `docs/ultron-studio/plans/informes-h8/informe-security.md`: este apartado.

No tocados: nada de `app/`, `config/` ni `routes/`, ni las otras guardas de SEC8 (`VoiceBoundaryTest`, `OtpUntouchedByVoiceTest`, `TwilioReadOnlyClientTest`, que pasan).

**Fuera del repositorio** (scratchpad, desechable):
- la copia `mut/backend`;
- el banco `tools/mutar.php`;
- las salidas en `out/`, con JUnit y JSON;
- en el PG desechable, las bases `ultron_studio_h8_sec` y `ultron_studio_h8_sec_copia`.

---

# §SEC8 · E5-2 y E5-4 (tras B8-b tanda 2)

**Fecha:** 08-11-2026. **Base:** `2af0102` (B8-b, tanda 2). Mientras trabajaba, el lead confirmó S8-c (`c8a94f7`, con el Anexo D) y la Enmienda 6 (`6bb0808`); E6-4 y E6-5 recogen lo mismo que aquí se fija. **Sin commit.**

**Dónde se midió:**
- **Copia aislada** (`<scratchpad>/mut2/backend`): `2af0102` + solo mis dos guardas, con los 12 ficheros de S8-c en curso devueltos a `HEAD`. De ahí salen las cifras limpias.
- **Árbol compartido**, aparte. La primera foto cogió a S8-c a medias (`expectedSpecHash` ya obligatorio y `walkVoice()` aún sin el Anexo D) y se repitió sobre `c8a94f7`/`6bb0808`, ya estable.

## 0. Resumen

- **E5-2 enumerado** en `VoiceIsolationTest`:
  - `VoiceNumberObserver.php` en la lista cerrada;
  - su único método, `describeNumber`;
  - `describeNumber` como el único GET de `ElevenLabsAgentDeployer`, una sola petición, a `/v1/convai/phone-numbers/{id}` con el id codificado;
  - solo `VoiceReconciler` lo llama: ni otro fichero, ni una llamada dinámica.
- **E5-4 aceptado** en `VoiceBoundaryTest`. Las 4 rutas públicas ya no exigen `throttle`: ahora lo **prohíben**. En su lugar se exige, por tokens, el patrón de B8-b en cada controlador: credencial → rama del fallo con `failure(su ruta, IP)` que termina en `return` → `authenticated(su ruta, sin IP)` → su 429 → trabajo. También se exige que el limitador no mezcle los cubos.
- **Verificado a mano:** una credencial mala no consulta el cubo de las buenas en ninguna de las 4 rutas (§3). No encontré ningún hueco en esa propiedad; dejo 3 notas residuales.
- **Guardas:** las 6 de SEC8 pasan, 61/61 (59 + 1 en cada fichero tocado). La prueba en marcha de B8-b, `VoicePublicRateLimitsTest`, pasa 5/5. Pint, `passed`.
- **Mutaciones:** caen **25 de 25** y los 2 controles no caen. Las 27 se restauraron con el SHA-256 igual, y el fichero creado se borró.
- **Suites:**

  | Suite | Copia | Árbol compartido |
  |---|---|---|
  | Voz y telefonía, SQLite | 718, 0 fallos | 718, 0 fallos |
  | Studio, SQLite | 3 085: solo los 3 previstos de `UltronStudioVoicePublishingTest`, que esperaban la columna de S8-c | 3 091, 0 fallos (ya con la columna de `c8a94f7`) |
  | Puerta PG | 333, 0 fallos | 333, 0 fallos |

  Árbol medido sobre `6bb0808` + mis tres ficheros, con `git status` igual antes y después.

## 1. E5-2 en `tests/Unit/Voice/VoiceIsolationTest.php`

| Qué | Línea | Contrato |
|---|---|---|
| `VoiceNumberObserver.php` en `PAQUETE_H8`. Su nulo `NullVoiceNumberObserver` entra por el patrón de nulos; su DTO `Dto/VoiceNumberAssignment`, por `Dto/` si es inerte | 85-87 | E5-2 (E4-8) |
| `INTERFACES`: `'VoiceNumberObserver' => ['describeNumber']` | 124-125 | E5-2 |
| `RED[DESPLEGADOR]` += `'describeNumber' => ['get']`. El verbo, sin reintentos, con `withoutRedirecting` y `timeout` y la cadena permitida los comprueba la prueba existente | 163-170 | E5-2 |
| `UNA_PETICION` += `DESPLEGADOR::describeNumber` | 186-187 | E5-2 («una petición GET») |
| Prueba nueva `test_el_numero_se_observa_con_un_get_a_su_ruta_y_solo_desde_la_conciliacion`:<br>• solo `app/Services/Telephony/VoiceReconciler.php` llama a `describeNumber` (`->`, `?->` o `::`);<br>• ningún literal `'describeNumber'` (sin llamada dinámica);<br>• el GET usa `PHONE_NUMBERS_PATH` = `/v1/convai/phone-numbers/` y `rawurlencode` del id | 190-194, 691-724 | E5-2 («solo `VoiceReconciler` la llama») |

Hoy: `VoiceReconciler.php:262` es el único llamante. El comando `voice:reconcile --numbers` pasa por `VoiceReconciler::numbers(VoiceAdapters::numberObserver())`.

## 2. E5-4 en `tests/Feature/Voice/VoiceBoundaryTest.php`

- **`…vive_en_sus_prefijos`** (`:145-169`): en las 4 rutas públicas, cualquier `throttle…` o `ThrottleRequests` es un fallo. Era «sin limitador» y ahora es «con limitador de ruta antes de la credencial». Las rutas del CRM no cambian.
- **Prueba nueva `test_las_cuatro_publicas_limitan_en_su_controlador_despues_de_autenticar`** (`:174-216`, ayudantes en `:398-616`). Sobre la tabla de rutas real, para cada controlador:
  - `VoiceRateLimits::failure(VoiceRateLimits::ROUTE_<suya>, …->ip())` aparece **una vez**, dentro del bloque que rechaza la credencial, después de comprobarla, y ese bloque **termina en `return`**;
  - `VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_<suya>, …)` aparece **una vez**, en el cuerpo del método, después de ese bloque y **sin `->ip()`** en sus argumentos;
  - `return VoiceRateLimits::tooMany(…)` va entre `authenticated()` y el trabajo (`initiate`, `ingest` o `handle`).

  Además, en `VoiceRateLimits`, `failure()` usa `$ip` y `'|fallo'`, y `authenticated()` no usa ninguno de los dos.
- La propiedad, en marcha, la prueba `VoicePublicRateLimitsTest` (B8-b), que no es una guarda.

## 3. Verificado a mano: una credencial mala no consulta el cubo de las buenas

**Las cuatro rutas, sobre `2af0102`:**

| Controlador | Credencial | Rama del fallo | Lo autenticado |
|---|---|---|---|
| `ElevenLabsInitiationController` | `:31` | `:33-40`: `failure()`, y 429 o 401 con `return` | `:45-49` |
| `ElevenLabsPostCallController` | `:38` | `:41-47` | `:51-55` |
| `VoiceToolController` | `:32` | `:34-40` | `:45-49` |
| `TwilioVoiceUsageTriggerController` | `:34` | `:37-43` | `:47-51` |

- Una credencial mala sale siempre por su rama con `return`, y nunca llega a `authenticated()`.
- Una buena nunca llama a `failure()`.
- **Las claves no pueden coincidir:**
  - fallos: `voice-rl:<ruta>|<IP>|fallo` (`VoiceRateLimits.php:83`);
  - autenticadas: `voice-rl:<ruta>|<40 hex de sha256(llamada)>` o `voice-rl:<ruta>|sin-llamada` (`:67`). Llevan una sola `|` tras la ruta, y ni la huella ni `sin-llamada` pueden contener `|`.
- El cubo de fallos que otro cliente de ElevenLabs puede agotar desde las mismas IP solo cambia nuestras respuestas de fallo de 401 a 429. Nunca toca una petición bien autenticada.

**Notas residuales** (no son huecos de la propiedad; para DOC8 y el registro de riesgos):
1. **Con una credencial válida ya no hay techo por ruta.** La clave de las herramientas se toma del cuerpo crudo, antes de validar el esquema, y la de la iniciación es cualquier `call_sid` bien formado. Quien tenga el secreto puede estrenar un cubo por petición. Las herramientas responden `call_not_found` sin escribir. Cada iniciación, en cambio, crea una fila `denied` en `voice_calls` y pasa por `VoiceLocks::gate()`.
   - Antes lo frenaba el `throttle` por IP (120/min). Es el coste aceptado en E5-4 y solo importa con el secreto filtrado (E5-5/B-1).
   - **Mitigación barata:** un techo global por ruta para lo autenticado (`voice-rl:<ruta>|todas`, holgado). Quien no tiene la credencial no puede gastarlo.
2. **El cubo `sin-llamada`** (10/min en iniciación, post-llamada y aviso; 60 en herramientas) lo comparten las peticiones autenticadas que no traen ningún id. Un tipo nuevo de evento post-llamada sin `conversation_id` ni `call_sid`, a más de 10/min, recibiría 429 y ElevenLabs lo reintentaría. Hoy no hay ninguno: `post_call_transcription`, `post_call_audio` y `call_initiation_failure` traen la conversación.
3. **Con el cubo de fallos agotado no se registra nada** (diseño de B8-b, contra la inundación del registro). Otro cliente de ElevenLabs puede así apagar nuestros avisos `voice.*.unauthorized` desde las IP comunes. En una rotación de secretos mal hecha, el síntoma visible sería el 429.

## 4. Lo verificado

| Comprobación | Dónde | Resultado |
|---|---|---|
| Las 6 guardas de SEC8: `TelephonyIsolationTest`, `VoiceIsolationTest`, `UltronEngineIsolationTest`, `VoiceBoundaryTest`, `OtpUntouchedByVoiceTest`, `TwilioReadOnlyClientTest` | árbol | 61 OK (182 846 aserciones) |
| `VoicePublicRateLimitsTest` (B8-b) | árbol | 5 OK |
| `tests/Feature/Telephony tests/Feature/Voice tests/Unit/Voice tests/Unit/Twilio tests/Unit/Telephony`, SQLite | copia / árbol | 718 OK (192 784 aserciones) / 718 OK (192 971); 131 omitidas, solo PG |
| `tests/Unit/UltronStudio tests/Feature/UltronStudio`, SQLite | copia / árbol | 3 085 (32 193 aserciones, 179 omitidas): solo los 3 previstos de `UltronStudioVoicePublishingTest` (publicar, revertir y apagar; esperaban la columna) / 3 091 OK (32 406 aserciones, 179 omitidas) |
| `tests/Feature/Telephony tests/Feature/Voice`, PG desechable | copia (`ultron_studio_h8_sec_copia`) / árbol (`ultron_studio_h8_sec`) | 333 OK (167 946 aserciones) / 333 OK (168 119) |
| Pint `--test` en las 2 guardas tocadas | árbol | passed |

## 5. Mutaciones

**Procedimiento** (en la copia; el árbol compartido no se tocó):
1. aplicar la mutación y comprobar su sintaxis con `php -l`;
2. correr las 6 guardas, con el resultado en JUnit;
3. restaurar y comparar el SHA-256.

**Resultado:**
- base: 61 OK; después: 61 OK;
- **caen 25 de 25**;
- los 2 controles no caen;
- las 27 se restauraron con el SHA-256 igual (N12 crea un fichero y lo borra).

Siglas: VI `VoiceIsolationTest`, VB `VoiceBoundaryTest`.

| # | Mutación | Fichero | Resultado | La caza |
|---|---|---|---|---|
| N1 | El comando llama a `describeNumber` sin pasar por la conciliación | `app/Console/Commands/Voice/VoiceReconcileCommand.php` | CAE | VI `el_numero_se_observa_con_un_get_a_su_ruta_y_solo_desde_la_conciliacion` |
| N2 | La conciliación lo llama de forma dinámica (`call_user_func([$o, 'describeNumber'])`) | `app/Services/Telephony/VoiceReconciler.php` | CAE | VI `el_numero_se_observa…` |
| N3 | `describeNumber` hace un segundo GET | `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php` | CAE | VI `la_red_solo_sale_de_los_metodos_enumerados` |
| N4 | `describeNumber` usa POST | ídem | CAE | VI `la_red…` |
| N5 | `describeNumber` reintenta (`retry`) | ídem | CAE | VI `la_red…` |
| N6 | `describeNumber` sigue redirecciones (sin `withoutRedirecting`) | ídem | CAE | VI `la_red…` |
| N7 | `describeNumber` pide `/v1/convai/conversations/` | ídem | CAE | VI `el_numero_se_observa…` |
| N8 | `PHONE_NUMBERS_PATH` apunta a las conversaciones | ídem | CAE | VI `el_numero_se_observa…` |
| N9 | El id va sin codificar | ídem | CAE | VI `el_numero_se_observa…` |
| N10 | La interfaz gana un segundo método | `app/Services/Voice/VoiceNumberObserver.php` | CAE | VI `el_puerto_no_sabe_llamar_a_nadie` |
| N11 | El DTO lee el reloj | `app/Services/Voice/Dto/VoiceNumberAssignment.php` | CAE | VI `estan_los_ficheros_del_h6_y_ninguno_que_no_nombre_el_contrato`; VI `el_espacio_es_neutro_y_sin_estado` |
| N12 | Un segundo nulo de la misma interfaz | `app/Services/Voice/NullVoiceNumberObserverDos.php` (nuevo) | CAE | VI `estan_los_ficheros…` |
| N13 | `describeNumber` lee el secreto del webhook | `ElevenLabsAgentDeployer.php` | CAE | VI `los_secretos_solo_en_sus_lectores_enumerados` |
| C1 | **Control:** la conciliación llama dos veces a `describeNumber` | `VoiceReconciler.php` | no cae (bien) | — |
| R1 | La iniciación vuelve a llevar `throttle:120,1` | `routes/voice.php` | CAE | VB `en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos` |
| R2 | Las herramientas llevan `ThrottleRequests::class` | ídem | CAE | VB `…vive_en_sus_prefijos` |
| R3 | Las herramientas no cuentan los fallos | `app/Http/Controllers/Api/Voice/VoiceToolController.php` | CAE | VB `las_cuatro_publicas_limitan_en_su_controlador_despues_de_autenticar` |
| R4 | La rama del fallo de la iniciación no devuelve: sigue hasta el cubo de las buenas | `ElevenLabsInitiationController.php` | CAE | VB `…limitan…`; VB `sin_credencial_ninguna_entrada_publica_de_voz_pasa_ni_escribe_ni_sale_a_la_red` |
| R5 | Las herramientas limitan antes de comprobar la credencial | `VoiceToolController.php` | CAE | VB `…limitan…` |
| R6 | Las herramientas limitan lo autenticado por IP | ídem | CAE | VB `…limitan…` |
| R7 | El aviso de Twilio cuenta los fallos sin la IP | `TwilioVoiceUsageTriggerController.php` | CAE | VB `…limitan…` |
| R8 | Las herramientas cuentan en el cubo de la iniciación | `VoiceToolController.php` | CAE | VB `…limitan…` |
| R9 | La iniciación no devuelve el 429 de lo autenticado | `ElevenLabsInitiationController.php` | CAE | VB `…limitan…` |
| R10 | El limitador mete la IP en la clave de lo autenticado | `app/Services/Telephony/VoiceRateLimits.php` | CAE | VB `…limitan…` |
| R11 | El cubo de los fallos pierde su marca `\|fallo` | ídem | CAE | VB `…limitan…` |
| R12 | Las herramientas cuentan también lo bueno en el cubo de los fallos | `VoiceToolController.php` | CAE | VB `…limitan…` |
| C2 | **Control:** las herramientas renombran su variable `$espera` | ídem | no cae (bien) | — |

R4 es la mutación que rompería la propiedad que pediste verificar, y la cazan las dos pruebas: la estática nueva y la de «sin credencial».

**Nota:** después del banco moví a su sitio un docblock que yo mismo había dejado fuera de lugar en `VoiceBoundaryTest` (el de `secretosDeVoz`). Es solo un comentario. Con la versión final, las 6 guardas vuelven a pasar en la copia (61/61) y en el árbol estable (61/61).

## 6. Ficheros

- `tests/Unit/Voice/VoiceIsolationTest.php`: E5-2.
- `tests/Feature/Voice/VoiceBoundaryTest.php`: E5-4.
- `docs/ultron-studio/plans/informes-h8/informe-security.md`: este apartado.

No tocados: nada de `app/`, `config/`, `routes/` ni `database/`, ni las otras cuatro guardas.

**Fuera del repositorio** (desechable): la copia `mut2/backend`, el banco `tools/mutar2.php` y las salidas en `out/` (`mutaciones-e5.json`, `c2-*`, `a2-*`).

---

# §SEC8-b · paso 4 · revisión final del backend — PARCIAL (cortada por el límite de uso)

**Fecha:** 08-11-2026. **Base:** `8d3c41f` (Enmienda 9). **Estado: INCOMPLETA.** Lo de abajo sale de leer el código; las pruebas de concepto, la puerta PG, las mutaciones, los endurecimientos de las guardas y el CRM quedan pendientes (§5). **Sin commit.** Solo se ha tocado este informe.

## 1. Hallazgos (lectura de código; sin prueba de concepto ejecutada todavía)

### M-2 · Media · Una post-llamada con muchas violaciones no echa el freno y puede desactivar el webhook
- **Dónde:** `app/Services/Telephony/VoicePostCall.php:143-150` guarda `codes = {violations: [...]}` sin tope. El CHECK `vce_codes_chk` exige `octet_length(codes::text) <= 4096` (`database/migrations/2026_11_10_100002_create_voice_tables.php:421`, solo PG).
- **Cómo se explota:** cada entrada pesa unos 54 bytes (`machine_reply_invented_discount`). Bastan unos 76 turnos del agente con violación. `\bdescuento\b` también salta con «no tengo ningún descuento». Quien habla (un canario en la fase 3, un contacto en la 4b) repite «¿me haces un descuento?» durante una llamada larga.
  - En PG el INSERT cae con 23514 y `ingest()` deshace toda la transacción: ni evento, ni `seal_verified`, ni **freno**.
  - Laravel responde 5xx y ElevenLabs reintenta el mismo cuerpo, que vuelve a caer. Tras 10 fallos desactiva el webhook (D8-16): ya no hay guarda posterior para ninguna llamada.
  - SQLite no tiene ese CHECK, así que la suite SQLite no lo ve.
- **Corrección (B8):** acotar lo que se guarda, como ya hace el laboratorio (`StudioVoiceLabSessions.php:479`, `MAX_GUARD_CODES`): códigos únicos con su recuento y el primer turno. El freno no debe depender del tamaño de la evidencia. Falta la prueba en PG.

### M-3 · Media · El corte de una saliente `requested` con el job en vuelo deja una llamada real que Laravel ya no puede colgar
- **Dónde:**
  - `VoiceHangup.php:40-44` cancela la `requested` sin coordinarse con el job.
  - `PlaceOutboundVoiceCall.php:241-251`: si ElevenLabs responde `started`, la transición `requested→dialing` falla en silencio. Los ids no se guardan (el evento `outbound_started` solo lleva `reason`/`http`).
  - `voice:hangup --all` no ve las `canceled` (`VoiceHangupCommand.php:49`).
  - La conciliación las trata como desconocidas (`VoiceReconciler.php:110, 119-125`).
- **Cómo se explota:** una persona pulsa «colgar», o hace el corte de emergencia del nivel 0b, mientras el job espera la respuesta (hasta 20 s). La llamada suena y se atiende. El CRM dice «cancelada», el corte responde `not_live` y el freno solo llega con la siguiente conciliación.
- **Corrección (B8, antes de la fase 4a):** que gane el corte. En `record()`, un `started` sobre una fila que ya no está `requested` cuelga en el acto el `callSid` devuelto con el transportista. Además, guardar los ids en el evento y que `ambiguousFor()` mire también `outbound_started`.

### B-2 · Baja · Una apertura del freno soltada no vuelve a abrirse por la misma causa
- **Dónde:** `VoiceBrake.php:108-113`. `open_key` es UNIQUE para siempre, y estas claves se repiten:
  - `number:<id>:<agente>` (`VoiceReconciler.php:315`);
  - `drift:<agente>` (`VoiceReconciler.php:265` y `PlaceOutboundVoiceCall.php:180`);
  - `cost_cap:<día>` (`VoiceReconciler.php:333`).
- **Cómo se explota:** con la clave filtrada (E2-2), se reapunta el número al mismo agente propio después de que lo corrigieran y soltaran el freno. No hay ni freno ni incidente; solo una línea `info`. Lo mismo pasa con el techo de gasto soltado en el mismo día: las entrantes no miran el presupuesto.
- **Corrección (B8):** una generación en la clave (aperturas previas con esa clave), o reabrir si la anterior ya está soltada.

### B-3 · Baja (despliegue) · La fase 1 solo comprueba la constante después de desplegar
- **Dónde:** `ops/deploy-h8-backend-fase1.sh:216` y `:530` (aserción posterior). En `:298`, un commit `voice(calls-open):` cuenta como del H8.
- **Corrección (OPS8):** en el paso 0, antes del merge, exigir `CALLS_ENABLED_IN_CODE = false` en `origin/main` y ningún `voice(calls-open):` en `BASE..origin/main`.

### Huecos de las guardas (para SEC8; sin aplicar)
1. `app/Support/Cache/ExpiredCachePruner.php`, que llama a diario el comando de voz, no está en `SIN_RED` (`VoiceIsolationTest.php:219`). Un `Http::` ahí no caería. Contrato: E9-2.
2. `voice.lab.post_call` tiene hoy un solo oyente (`AppServiceProvider.php:753`), pero ninguna guarda lo fija. Un segundo oyente (o un comodín) que responda `recorded` o `ignored` evitaría el freno de toda conversación desconocida (`VoicePostCall.php:194-199`).
   - Además, la excepción `LAB_LISTENER` (`UltronEngineIsolationTest.php:107-115, 217-219`) no ata el `use` a ese `Event::listen(…, 'recordProviderReport')`.
   - Hay un docblock huérfano en `:101-106`.
   - Contrato: E8-1 y E9-1.
3. Los limitadores con nombre del CRM solo los fija una prueba de B8 (`VoiceAdminRateLimitsTest.php:53`), no una guarda. Contrato: E9-4.

## 2. Verificado sin hallazgo (lectura)
- **Las 4 rutas públicas.** Comparan en tiempo constante y fallan cerrado sin secreto. La firma va sobre el cuerpo crudo, con 1 800 s hacia atrás y 300 hacia delante. Hay tope de cuerpo antes de leer, y el límite va después de autenticar, con el cubo de fallos aparte y un 429 sin fuga. La profundidad del JSON está acotada. Las claves duplicadas no importan: un solo analizador sobre los mismos bytes.
- **Herramientas.**
  - Los ids del cuerpo salen de variables dinámicas, no del LLM (`ElevenLabsAgentMapper.php:80-84, 205-225`).
  - `no_volver_a_llamar` excluye la huella sellada.
- **CRM y Studio.**
  - Cada ruta tiene su llave en `AuthorizationMap`, más persona y llave en el controlador.
  - `clientRequestId` va por persona y da 409 si es de otra.
  - Hay cuatro ojos por identidad en perfiles y publicación, y `expectedSpecHash` antes de desplegar.
  - Los permisos por rol siguen D8-22.
  - El registro no devuelve huella, `call_ref`, ids del proveedor ni números.
- **Lector de la cadena.** Falla cerrado. La URL firmada del laboratorio sale una vez y no se guarda.
- **Configuración y despliegue.**
  - La configuración arranca toda apagada.
  - El script de la fase 1 no imprime valores del entorno: registro 600, `--acepta-main` explícito y vuelta atrás documentada.
  - El smoke solo manda lo que se rechaza, con contraseña de un uso y un `trap` que deja la cuenta 32 en reposo.

## 3. Registro de riesgos para DOC8 (además de los del encargo)
- B-1 (secreto de iniciación estático).
- E4-6 (verificación diferida; caduca el 03-12-2026 a las 22:55 UTC).
- E9-6 (O-1 diferido).
- P8-4 (post-llamada de 1 MB).
- La lista de IP de ElevenLabs, que es un refuerzo débil.
- **Nuevos:**
  - [por confirmar] `metadata.phone_call.call_sid` en los cuerpos reales: sin él, `VoicePostCall.php:184` no distingue una llamada de teléfono de una conversación web.
  - El agente de verificación tiene que ser privado (`enable_auth`): cualquier conversación de un agente que no sea de laboratorio echa el freno y gasta créditos.
  - Falta `h8-voice-scope.sh` (nivel 0 de D8-20) antes de la fase 2.
  - La cuenta del smoke es Super Admin mientras está activa.

## 4. Lo ejecutado
- Las 6 guardas de SEC8 en SQLite sobre `8d3c41f` (árbol compartido, sin tocarlo): **61 OK, 184 759 aserciones**.

## 5. Pendiente
1. Las pruebas de concepto de M-2 en PG (`UNIT_DB=ultron_studio_h8_secb`) y de M-3.
2. Aplicar los 3 endurecimientos de las guardas y hacer mutaciones por muestreo en una copia aislada.
3. La puerta PG y las suites de voz y telefonía.
4. La revisión del CRM, cuando el lead lo confirme.

---

# §SEC8-b (2) · pruebas de concepto, guardas endurecidas, mutaciones y suites (backend)

**Fecha:** 08-11-2026. **Base:** `b81af59`, con el mismo código que `8d3c41f`: entre los dos, nada cambia en `app/`, `routes/`, `config/`, `bootstrap/`, `database/` ni `tests/`. **Sin commit.** Toco 4 guardas y este informe. Lo que sigue abierto del punto 5 anterior es solo el 4, el CRM (§6).

**Decisiones del lead (mensaje del 08-11):**
- M-2, B-2 y los dos hallazgos de DOC8 los corrige un agente nuevo, B8-d, antes de la fase 2.
- M-3, antes de la fase 4a.
- B-3 lo corrigió el lead en `c8c4eb4`; lo verifico en §5.

## 1. Pruebas de concepto (ejecutadas; salida real)

**Cómo se ejecutaron:**
- Fichero fuera del repositorio: `<scratchpad>/poc/Sec8bPocTest.php`, con las ayudas de `VoiceFixtures`.
- Sin red (`Http::preventStrayRequests()`) y con la constante cerrada.
- Lanzado desde el árbol compartido, sin escribir en él.
- PG con `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_secb bash docs/ultron-studio/ops/pgunit.sh <fichero>`; SQLite con la configuración de `phpunit.xml`.

### M-2 · confirmado, y solo en PG

Post-llamada firmada de una saliente `active`, con N intercambios en los que el agente dice «No tengo ningún descuento para ofrecerte.».

```
POC M-2 pgsql  {"n":120,"http":[500,500],"excepcion":"Illuminate\\Database\\QueryException SQLSTATE=23514 vce_codes_chk","brakeOpen":false,"aperturas":0,"eventos":0,"estado":"active"}
POC M-2 sqlite {"n":120,"http":[200,200],"excepcion":null,"brakeOpen":true,"aperturas":1,"eventos":2,"estado":"completed"}
```

| Turnos con violación | Violaciones | Bytes de `codes` | PG | Freno en PG |
|---|---|---|---|---|
| 10 | 11 | 582 | 200 | sí |
| 60 | 61 | 3 242 | 200 | sí |
| 74 | 75 | 3 998 | 200 | sí |
| 75 | 76 | 4 052 | 200 | sí |
| **76** | **77** | **4 106** | **500** | **no** |
| 80 | 81 | 4 322 | 500 | no |

**Lo que muestra:**
- Desde el turno 76 con violación, el INSERT del veredicto (`VoicePostCall.php:144`) rompe `vce_codes_chk` (`…100002_create_voice_tables.php:421`).
- `ingest()` (`VoicePostCall.php:58`) deshace TODO: ni `post_call_transcription`, ni `seal_verified`, ni el freno de `:148-150`. La llamada sigue `active`.
- El reintento del mismo cuerpo vuelve a dar 500, así que es determinista: ElevenLabs acabaría desactivando el webhook (D8-16).
- En SQLite (sin ese CHECK) la misma post-llamada da 200 y echa el freno: **la suite SQLite no lo puede ver**.
- El umbral depende del código: 54 bytes por entrada con `machine_reply_invented_discount`.

**Para la prueba de B8-d:** que caiga en PG con 76 turnos o más, sin el arreglo.

### M-3 · confirmado (PG y SQLite, igual)

Una saliente `requested`. Se corta con `VoiceHangup::hangup()` mientras el job espera a ElevenLabs. Después, `record()` del job recibe `started`.

```
POC M-3 pgsql {"corte":"canceled","estadoTrasStarted":"canceled","sidGuardado":null,"conversacionGuardada":null,
  "eventoStarted":{"clave":"conv_sec8b_m3","codes":{"reason":null,"http":200}},"segundoCorte":"not_live",
  "laVeHangupAll":false,"conciliacion":"unknown","freno":["unknown_provider_call"]}
```

**Lo que muestra:**
- La fila queda `canceled` y sin ids (`PlaceOutboundVoiceCall.php:241-251`: la transición falla en silencio).
- El evento `outbound_started` no lleva el `callSid`: solo `{reason, http}`.
- Un segundo corte da `not_live` (`VoiceHangup.php:46-48`), y `voice:hangup --all` no la ve (`VoiceHangupCommand.php:49`).
- La conciliación la da por desconocida y echa el freno `unknown_provider_call` (`VoiceReconciler.php:110, 119-125`).
- Mientras tanto, la llamada real sigue hasta su duración máxima.

### B-2 · confirmado (PG y SQLite, igual)

Deriva del número al agente `agent_ajeno_sec8b_01`. Una Super Admin suelta el freno. La conciliación ve la misma deriva otra vez.

```
POC B-2 pgsql {"primera":["drift",true],"sueltoTrasSoltar":true,"segunda":["drift",false],"frenoEchadoTrasLaSegunda":false,"aperturas":1}
```

La segunda vez `VoiceReconciler::numbers()` da `drift`, pero no echa el freno ni abre incidente: la clave `number:<id>:<agente>` es UNIQUE para siempre (`VoiceBrake.php:108-113`). El rearme que decidió el lead lo hace B8-d.

## 2. Los 3 huecos de las guardas, cerrados (H-5, H-6, H-7)

| # | Qué fija | Dónde | Contrato |
|---|---|---|---|
| H-5 | El podador de la caché entra en la guarda de «sin red» con su propia lista, `SIN_RED_FUERA_DE_LA_VOZ`, dentro de `SIN_RED`. La prueba nueva exige que exista: `php()` se salta en silencio lo que no existe. También exige que todo lo que la voz (paquete, telefonía, comandos, trabajos, controladores y Studio de voz) use de `App\Support\Cache` esté en esa lista, y prohíbe importar el espacio entero | `tests/Unit/Voice/VoiceIsolationTest.php:218-228` (constantes) y `:419-448` (`test_lo_que_la_voz_usa_de_support_cache_esta_enumerado_y_sin_red`) | E9-2 |
| H-6 | El informe del laboratorio tiene **una entrega y un oyente**:<br>• el nombre `voice.lab.post_call`, una vez y solo en `VoicePostCall::LAB_EVENT`;<br>• `LAB_EVENT`, solo en `VoicePostCall.php` (su declaración y un `event(self::LAB_EVENT,`) y en `AppServiceProvider.php`, un `Event::listen(VoicePostCall::LAB_EVENT, [StudioVoiceLabSessions::class, 'recordProviderReport']);` exacto;<br>• ningún `listen()` con un nombre literal de voz o comodín, y ninguna clave `'*' =>` en `app/Providers` | `tests/Feature/Telephony/TelephonyIsolationTest.php:548-629` (`test_el_informe_del_laboratorio_tiene_una_sola_entrega_y_un_solo_oyente` y el ayudante `veces()`) | D8-19, E8-1, E9-1 |
| H-6b | La excepción `LAB_LISTENER` del arranque pasa a ser exacta: el `use` sin alias y una vez, y el nombre corto una sola vez, dentro de `LAB_LISTENER_CALL`. Además, el docblock huérfano del lector de la cadena vuelve a su constante | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:99-122` (constantes), `:222-226` (la rama) y `:548-565` (`onlyTheLabListener`) | E8-1, E9-1 |
| H-7 | Los limitadores con nombre del CRM, por guarda (antes solo los fijaba `VoiceAdminRateLimitsTest`, de B8):<br>• cada ruta de `Crm\Voice`, con `throttle:voice-admin-read` (GET) o `throttle:voice-admin-write` (POST) y ninguno más;<br>• esos dos, en ninguna otra ruta;<br>• los dos, registrados (`RateLimiter::limiter`) | `tests/Feature/Voice/VoiceBoundaryTest.php:100-103` (constantes), `:147-158` (en `test_en_la_tabla_de_rutas_real_la_voz_vive_en_sus_prefijos`) y el final de esa prueba | E9-4 (P8-5) |

**Lo que no ven:** siguen siendo rastreos de tokens. No ven un nombre de evento compuesto en tiempo de ejecución (`'voice.lab.'.$x`), un oyente por `$dispatcher` inyectado con un nombre construido, ni un limitador añadido en tiempo de ejecución.

## 3. Mutaciones (copia aislada)

**La copia:** `<scratchpad>/mut/backend`, un clon APFS del backend con su propio `vendor/` (la carga automática apunta a la copia). El banco es `<scratchpad>/tools/mutar-b.php`.

**El procedimiento, por mutación:**
1. el texto de partida tiene que aparecer exactamente una vez;
2. `php -l`;
3. las 4 guardas tocadas, con su JUnit;
4. restaurar y comparar el SHA-256.

**Resultado:**
- base 41/41; **caen 21 de 21**; **los 4 controles no caen**; después 41/41;
- **25 de 25 restauradas con el SHA-256 igual**; los ficheros creados, borrados.

**Contraprueba:** las mismas 25 con las 4 guardas de `HEAD` (base 39/39): **sobreviven las 21**. Cada endurecimiento cierra un hueco real.

Siglas: VI `VoiceIsolationTest`, TI `TelephonyIsolationTest`, UEI `UltronEngineIsolationTest`, VB `VoiceBoundaryTest`.

| # | Mutación | La caza |
|---|---|---|
| P1 | `ExpiredCachePruner` llama a `Http::get(…)` | VI `la_red_solo_sale…`, VI `hosts_oficiales…` |
| P2 | `ExpiredCachePruner` usa `file_get_contents('https://…')` | VI `la_red_solo_sale…`, VI `hosts_oficiales…` |
| P3 | El comando usa una clase nueva de `app/Support/Cache` que sale a la red | VI `lo_que_la_voz_usa_de_support_cache…` |
| P4 | El podador se renombra (con su `use … as`) | VI `lo_que_la_voz_usa_de_support_cache…` |
| P5 | El comando importa `App\Support\Cache` entero | VI `lo_que_la_voz_usa_de_support_cache…` |
| L1 | Un segundo `Event::listen(VoicePostCall::LAB_EVENT, fn () => 'recorded')` | TI `informe_del_laboratorio…` |
| L2 | `Event::listen('voice.*', …)` en el arranque | TI |
| L3 | `Event::listen('*', …)` en `routes/console.php` | TI |
| L4 | Otro proveedor escucha `'voice.lab.post_call'` | TI |
| L5 | El oyente apunta a otro método del laboratorio | TI, UEI |
| L6 | El arranque usa `StudioVoiceLabSessions` para otra cosa | UEI |
| L7 | El `use` con alias y el oyente con el alias | TI, UEI |
| L8 | `VoiceTools` dispara el informe del laboratorio | TI |
| L9 | `VoicePostCall` entrega el informe dos veces | TI |
| L10 | Un `$listen = ['*' => …]` en un proveedor | TI |
| T1 | Las lecturas del CRM vuelven a `throttle:120,1` | VB `vive_en_sus_prefijos` |
| T2 | Una POST del CRM con el cupo de lectura | VB |
| T3 | Una ruta del CRM sin limitador | VB |
| T4 | Una ruta del CRM con un `throttle:60,1` de más | VB |
| T5 | `voice-admin-read` registrado con otro nombre | VB |
| T6 | Una ruta del Studio usa `throttle:voice-admin-write` | VB |
| C1 | **Control:** el podador renombra una variable local | no cae (bien) |
| C2 | **Control:** el arranque reordena dos `use` | no cae (bien) |
| C3 | **Control:** el oyente se registra desde otro método privado, idéntico | no cae (bien) |
| C4 | **Control:** se reordenan dos lecturas del CRM | no cae (bien) |

## 4. Suites (árbol compartido con las 4 guardas nuevas; `git status` igual antes y después)

| Suite | Resultado |
|---|---|
| Las 6 guardas de SEC8, SQLite | **63 OK** (61 + 2 nuevas), 184 791 aserciones |
| `tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio`, SQLite | **761 OK**, 196 430 aserciones, 146 omitidas (solo PG) |
| `tests/Feature/UltronStudio/*Voice*Test.php tests/Unit/UltronStudio/*Voice*Test.php` y `UltronEngineIsolationTest`, SQLite | **588 OK**, 6 464 aserciones, 73 omitidas |
| Lo mismo en la puerta PG (`ultron_studio_h8_secb`) | **1 349 OK**, 204 046 aserciones, 15 omitidas, **0 fallos** |
| `php artisan auth:coverage --unmapped` | vacío, exit 0 |
| Pint `--test` en las 4 guardas tocadas | `passed` |

## 5. B-3, verificado en `c8c4eb4`
- `deploy-h8-backend-fase1.sh:330-331` exige, en el paso 0 y antes del merge (`:449`), un `CALLS_ENABLED_IN_CODE = false;` en `origin/main` (que es `ESPERADO`). **Cerrado.**
- **Detalle, para el lead:** con `set -euo pipefail` (`:41`), si no hay coincidencia `grep -c` sale con 1 y el guion muere en `:330` **sin imprimir su `ABORTA`**. Ensayado: `X=$(printf 'nada\n' | grep -c …)` termina con 1. Sigue fallando cerrado y antes de tocar nada, pero sin el mensaje. Corrección: `… | grep -cE '…' || true` dentro de la sustitución, como ya hace `cuenta_var`.

## 6. Pendiente
- **La revisión del CRM** (D8-23: CSP `connect-src`, micrófono y la cadena de suministro del cliente de ElevenLabs con su versión exacta; los textos de los motivos; nada de datos personales en la vista), cuando el lead avise de que el paso 3 de FE8 está confirmado.
- **Para B8-d** (decisión del lead), con estas pruebas de concepto como regresión:
  - M-2 tiene que dar 200 con el freno echado en PG con 76 turnos o más;
  - B-2 tiene que reabrir con la segunda observación;
  - M-3 (antes de la 4a): `record()` sobre una `canceled` cuelga el `callSid` devuelto y la conciliación la reconoce.

## 7. Ficheros
- `tests/Unit/Voice/VoiceIsolationTest.php` (H-5).
- `tests/Feature/Telephony/TelephonyIsolationTest.php` (H-6).
- `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` (H-6b; solo las líneas del H8).
- `tests/Feature/Voice/VoiceBoundaryTest.php` (H-7).
- `docs/ultron-studio/plans/informes-h8/informe-security.md` (este apartado).

No tocados: nada de `app/`, `config/`, `routes/`, `database/` ni de los guiones; tampoco `OtpUntouchedByVoiceTest` ni `TwilioReadOnlyClientTest`, que pasan.

**Fuera del repositorio** (desechable):
- `<scratchpad>/poc/Sec8bPocTest.php`;
- la copia `<scratchpad>/mut/backend`;
- `<scratchpad>/tools/mutar-b.php`;
- las salidas `<scratchpad>/out/{mutaciones-sec8b.json, mutaciones-sec8b-contraprueba.json, poc-pg.err, poc-sqlite.err, guardas-sqlite-2.xml, voz-sqlite.xml, studio-voz-sqlite.xml, puerta-pg.xml}`;
- las bases PG `ultron_studio_h8_secb` y `ultron_studio_h8_secb_copia` (esta, sin usar).

---

# §SEC8-b (3) · el CRM del paso 3 (`Iron-Body_Front`, `45dd73a`)

**Fecha:** 08-11-2026. **Base:** `45dd73a` (FE8 paso 3, encima de `c6c48b6`), con el árbol limpio, frente a producción `73dbcd3`. Lectura del código, del cliente 1.30.0 instalado en `node_modules` y del registro público de npm. **No edité el CRM.** No ejecuté su suite (es de FE8). Rutas relativas a `src/app/modules/ultron-studio/`.

**Veredicto: sin hallazgos de severidad crítica, alta ni media en el CRM.**
- 1 baja, previa al H8: el CRM no manda CSP (§1.1);
- 1 observación funcional, no de seguridad (§1.2);
- el dictamen de la `ref` (§3).

## 1. Hallazgos

### B-4 · Baja · preexistente · El CRM no manda ninguna CSP ni `Permissions-Policy`: la nota de FE8-3 para OPS8 parte de una CSP que no existe
- **Dónde:** el vhost de nginx de `https://ironbodyneiva.cloud/`.
  - Leído hoy con un `HEAD` anónimo: solo `x-content-type-options: nosniff`, `x-frame-options: SAMEORIGIN`, `referrer-policy: strict-origin-when-cross-origin` y HSTS.
  - `src/index.html` no tiene `<meta http-equiv>`.
- **Impacto:**
  - Hoy, ninguno sobre el laboratorio: sin `Permissions-Policy`, `microphone` vale `self` por defecto, y sin CSP el WebSocket no tiene restricción.
  - Para OPS8, por tanto, **no hay que «añadir» nada para que funcione la fase 2**. Lo de `informe-front.md` §3.4 vale solo cuando exista una CSP.
  - La falta de CSP es un hueco de endurecimiento anterior al H8: si entrara un XSS, nada limitaría a dónde habla la página. El H8 lo agrava poco, porque el cliente de ElevenLabs va empaquetado y sus worklets salen del propio origen.
- **Corrección (OPS8, después de la fase 1 y fuera del H8):** una CSP en `Content-Security-Policy-Report-Only` primero. Como mínimo: `default-src 'self'; script-src 'self'; connect-src 'self' https://api.ironbodyneiva.cloud wss://api.elevenlabs.io; img-src 'self' data:; frame-ancestors 'self'`.
  - **No** añadir `https://api.elevenlabs.io` ni `wss://livekit.rtc.elevenlabs.io`: con la URL firmada el cliente solo abre el WebSocket.
  - Con [P-21] = `eu`, `wss://api.eu.residency.elevenlabs.io`.
  - Además, `Permissions-Policy: microphone=(self), camera=(), geolocation=()`.

### O-1 · Observación funcional (no de seguridad) · El detalle de una llamada descarta casi todos sus eventos
- **Dónde:** `studio-voice-parse.ts:1318` lee `codes` como una **lista** de códigos cortos (`codigos()` → `lista()`). El backend manda un **objeto**:
  - `VoiceCallsQuery.php:54` devuelve `'codes' => $e->codes`, con el cast `array` de un JSON objeto;
  - por ejemplo `{reason, http}` en `outbound_started`, `{violations: [...]}` en `guard_verdict` y `{scope, seq, part, reservedMicros}` en `outbound_requested`.
- **Efecto:**
  - `leerLista` descarta cada evento con códigos y lo cuenta: «N eventos descartados» (`studio-voice-calls.ts:131`).
  - La captura del backend (`testing/backend-voice.testing.ts:5043`) trae `events: []`, así que ninguna prueba vio la forma real.
  - Falla cerrado, sin fuga, pero quien atienda un incidente no verá en el CRM los turnos de la guarda ni el antes y después de la conciliación.
- **Corrección (decisión del lead entre B8 y FE8):** o el servidor aplana `codes` a una lista de códigos, o el CRM lee del objeto solo los escalares de forma de código.

## 2. Verificado sin hallazgo

**Teléfonos, URL, almacenamiento y consola (foco 1).**
- En los 30 ficheros de voz del H8 no hay `console.*`, `localStorage`/`sessionStorage`/`indexedDB`, `innerHTML`, `bypassSecurityTrust*`, `window.open`, `eval` ni URL fijas. El único `localStorage` es la política de acceso de `access-control.service.ts`, que ya existía.
- Los teléfonos viajan solo en dos cuerpos POST: `createProfile` (los destinos, `studio-voice-api.service.ts:221`) y `createContact` (`:526`). Nunca en una URL ni en un parámetro: cada uuid se valida (`isLowerUuid`) y se codifica antes de entrar en la ruta.
- Cada respuesta de escritura se comprueba contra lo pedido (`:197-199`, `:271-273`, `:296-300`…).

**Nada ilegible llega a la vista.**
- Un fallo del lector sale como `unreadable` con un mensaje genérico (`studio-voice-api.service.ts:631-634`).
- El motivo, que con `muestra()` puede repetir hasta 40 caracteres del valor (`studio-parse.ts:230-235`), se queda en `payload.reason` y **ninguna plantilla lo pinta**: solo `error.message`.
- En los campos de teléfono, el lector no repite el valor (`studio-voice-parse.ts:212-225`), y los textos que se pintan rechazan series de 7 dígitos o un «+57» (`:152-153`, `:250-254`).
- Las claves desconocidas se ignoran campo a campo.

**`clientRequestId`.**
- Lo genera `crypto.randomUUID()` (`studio-api.service.ts:152-158`).
- Se reutiliza solo si el cuerpo no cambió: lo compara una firma cyrb53, no el cuerpo, así que el teléfono no queda en claro en la sesión (`studio-voice-session.ts:887-901`).
- Se descarta cuando el servidor lo gasta.

**Laboratorio (foco 2).**
- **Orden:** cerrojos y presupuesto vistos → `supported()` (AudioWorklet, `getUserMedia` y la restricción `sampleRate`) → **permiso de micrófono antes del POST que gasta** (`studio-voice-session.ts:748-760`), con la pista soltada al momento (`studio-voice-lab-conversation.ts:93-103`).
- **La URL firmada:**
  - pasa directa a `Conversation.startSession({signedUrl, connectionType: 'websocket'})` y no entra en ninguna señal (`studio-voice-session.ts:818-843`, `studio-voice-lab-conversation.ts:105-122`);
  - los errores del cliente se resumen sin texto;
  - la repetición 200 no la trae, y el componente no la toca (`studio-voice-lab.ts:406`).
- **Sin CDN:**
  - los dos worklets se copian del paquete verificado a `assets/elevenlabs-client-1.30.0/` (`angular.json`, sin `scribeAudioProcessor.js`);
  - `libsampleratePath` apunta a una ruta propia que no existe. En 1.30.0, `addLibsamplerateModule` usa `customPath || CDN` (`node_modules/@elevenlabs/client/dist/platform/web/addLibsamplerateModule.js:4`): con una ruta propia, **nunca va a jsDelivr**. Si la salida de audio tuviera que remuestrear (`output.js:55-59`), la sesión falla en el propio origen.
  - El cliente solo hace `fetch` en `uploadFile`, `postOverallFeedback` y WebRTC, que el CRM no usa.
- **Residual ya anotado por FE8:** si el WebSocket falla, la consola del navegador puede mostrar la URL que falló. Es de un solo uso y caduca en ≤ 15 min.

**`expectedSpecHash` y 409 (foco 3).**
- Publicar manda la `specHash` de la previsualización de ESA versión, perfil y propósito (`studio-voice-publications.ts:288-301, 380-400`), y promover, la del agente canario que se vio.
- Un 409 `spec_changed` borra la previsualización (`studio-voice-session.ts:576`) y la vista solo avisa: **ningún reintento**.
- La huella nueva cambia el cuerpo, y con él el `clientRequestId`.

**Botones por `can` (foco 4).**
- `can` vale «todo denegado» mientras no se lea (`studio-voice-session.ts:244-246`).
- Cada escritura tiene su bloqueo, de `can` más el estado de `actions` del servidor (`:281-340, 618, 649, 798, 852`).
- Las plantillas ofrecen la acción solo con el bloqueo a `null` (por ejemplo `studio-voice-calls.ts:54-58`, `studio-voice-publications.ts:95`, `studio-voice-lab.ts:84`), y `previo()` vuelve a negar.
- El servidor decide de todos modos.

**Rutas.** `marketing/ultron-studio/voz` va con `AuthGuard` y `ultron.view` (`app.routes.ts`). Los 4 permisos que son solo de Super Admin quedan fuera del Administrador por defecto (`access-control.service.ts`), como `CrmPermission::byRole()`.

## 3. Dictamen: la `ref` de los destinos en `compile-preview` (foco 6)

**Qué es:**
- `ref` = `VoicePhone::hash()` del E.164 del destino: HMAC-SHA256 con la subclave `voice-phone` de la `APP_KEY` (`StudioVoiceCompiler.php:264`, `VoicePhone.php`).
- Viaja dentro de `spec` en la respuesta de `POST voice/compile-preview` (`StudioVoicePublishing.php:212`), a quien tiene `ultron.voice_manage` (Administrador y Super Admin), en una respuesta autenticada y `no-store`.

**Dictamen:**
1. **Para Iron Body sigue siendo un dato personal seudonimizado** en el sentido de la Ley 1581: quien tiene la clave puede volver a ligarla a un número calculando la huella de un candidato.
2. **No es un «teléfono en claro»** (protección 9). D8-5 y D8-17 hacen de esa huella la forma permitida del teléfono en todo el H8: `remote_hash`, `phone_hash`, `transfer_destination_hashes`.
3. **Para quien la recibe no identifica a nadie:** sin la subclave no se puede recorrer el espacio de números de Colombia.
4. **No se puede cruzar con nada en el CRM:** ninguna otra respuesta devuelve huellas `voice-phone` (llamadas, contactos y perfiles dan `last4`; `VoiceCallsQuery.php:64-98`, `VoiceContacts.php:390-400`).
5. **El CRM la ignora:** el lector solo toma `alias` y `condition` (`studio-voice-parse.ts:862-865`).

**Conclusión: conforme con el contrato; S8 no está obligado a quitarla.**
- **Mejora de minimización (baja, opcional, después de la fase 1):** que la respuesta de `compile-preview` omita la `ref` o la ponga a `null`. La `specHash` se sigue calculando en el servidor sobre la especificación completa, y el CRM no la recalcula.
- **Condición para mantenerla:** que ninguna respuesta futura del CRM devuelva huellas `voice-phone`, porque permitiría cruzarlas.

## 4. Cadena de suministro: `@elevenlabs/client` 1.30.0 (foco 7)

**El lock.**
- El H8 añade **14 paquetes**: el cliente y 13 transitivos.
- **`jose` 6.2.3** pasa de dependencia de desarrollo a dependencia de producción: lo arrastra `livekit-client`, con la misma versión e integridad.
- La raíz solo gana `"@elevenlabs/client": "1.30.0"` (versión exacta). Nada más cambia.

**Contra el registro** (`https://registry.npmjs.org/<paquete>/<versión>`, los 15):
- `dist.integrity` y `dist.tarball` **iguales en los 15**;
- `hasInstallScript` falso en todos y sin `binding.gyp`;
- **ningún gancho de instalación** (`preinstall`, `install`, `postinstall`);
- solo `sdp` (`npm run build`) y `webrtc-adapter` (`grunt build`) traen `prepare`, que npm no ejecuta al instalar desde el registro;
- con procedencia firmada (Sigstore): `@elevenlabs/client`, `@elevenlabs/types`, `@livekit/protocol`, `livekit-client` y `jose`.

| Paquete | Versión | Integridad y tarball | Ganchos |
|---|---|---|---|
| `@elevenlabs/client` | 1.30.0 | iguales | ninguno |
| `@elevenlabs/types` | 0.25.0 | iguales | ninguno |
| `livekit-client` | 2.23.0 | iguales | ninguno |
| `@livekit/protocol` | 1.52.1 | iguales | ninguno |
| `@livekit/mutex` | 1.1.1 | iguales | ninguno |
| `@bufbuild/protobuf` | 1.10.1 | iguales | ninguno |
| `@types/dom-mediacapture-record` | 1.0.22 | iguales | ninguno |
| `events` | 3.3.0 | iguales | ninguno |
| `jose` | 6.2.3 | iguales | ninguno |
| `loglevel` | 1.9.2 | iguales | ninguno |
| `machina` | 7.0.1 | iguales | ninguno |
| `sdp` | 3.2.2 | iguales | `prepare` (no se ejecuta) |
| `sdp-transform` | 2.15.0 | iguales | ninguno |
| `typed-emitter` | 2.1.0 | iguales | ninguno |
| `webrtc-adapter` | 9.0.6 | iguales | `prepare` (no se ejecuta) |

**Hosts escritos en el cliente** (`dist/`):
- `api.elevenlabs.io` (https y wss);
- `livekit.rtc.elevenlabs.io` (WebRTC, que no se usa);
- `cdn.jsdelivr.net` (solo `libsamplerate`, desviado, §2).

El despliegue del CRM ejecuta `npm ci --no-audit --no-fund` en el Mac que compila, y solo si cambia el lock (`deploy-h8-crm-fase1.sh:74-78`), nunca en el servidor.

## 5. Ficheros
- Este apartado de `docs/ultron-studio/plans/informes-h8/informe-security.md`.
- **CRM, sin tocar.**
- **Fuera del repositorio** (desechable): `<scratchpad>/out/{lock-antes.json, lock-despues.json, lock-registro.txt, crm-ficheros.txt}`.
