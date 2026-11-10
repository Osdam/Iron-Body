# Banco local del Hito 8 (`ops/banco-h8/`)

Ensayo, **en el Mac**, de la voz y la telefonía del H8 contra una «producción» de juguete y contra simuladores de **ElevenLabs** y de la **subcuenta de voz de Twilio**.

- **Nunca toca producción** y no lleva credenciales reales: las piezas son aleatorias y se generan al montar el banco.
- **Nunca sale a la red** (protección 3 del contrato):
  - los simuladores escuchan solo en `127.0.0.1` y solo emiten hacia `127.0.0.1`; con otra dirección se niegan a arrancar;
  - todo proceso PHP del banco (la API, el worker y cada orden de artisan) sale por la **valla**, un proxy que bloquea y anota todo destino que no sea esta máquina.
- **Vive fuera del repo**: lleva un volcado, un fichero de entorno sintético y las piezas de los simuladores. Las herramientas se niegan a montarlo dentro.

Es la parte **OPS8-a** del contrato (`ULTRON_STUDIO_H8_CONTRACT.md`, fila OPS8). El despliegue de la fase 1, el smoke y el mando de alcance (`deploy-h8-backend-fase1.sh`, `smoke-run-h8.sh` y `h8-voice-scope.sh`) son de **OPS8-b**, tras la puerta 1. Hasta entonces, `ensayar.sh desplegar|smoke|alcance` respondía que aún no existían *(los tres existen desde OPS8-b y OPS8-c)*.

## Qué hay aquí

| Fichero | Qué es |
|---|---|
| `comun.sh` | Lo comparten los scripts: rutas, PG desechable, guarda «fuera del repo», `RED` (el entorno de red por la valla), `caso` |
| `montar.sh` | Monta el banco (abajo) |
| `sembrar.sh` | La cuenta del smoke; con `--canarios-voz`, dos canarios de voz activos |
| `ensayar.sh` | Arranca y para la pila; ensaya la valla, el `voice:doctor`, el worker de voz y el cierre |
| `ensayar-ops8c.sh` | (OPS8-c) El ensayo del mando, el registro de secretos y la comprobación de Twilio (abajo, «OPS8-c») |
| `sim-elevenlabs.cjs` | Simulador de ElevenLabs: lado proveedor (13 rutas) y emisor hacia Laravel |
| `sim-twilio.cjs` | Simulador de la subcuenta de voz de Twilio: 10 rutas y el emisor de avisos de gasto |
| `sim-comun.cjs` | Lo comparten los dos: registro, fallos inyectables, firmas y emisión solo a `127.0.0.1` |
| `valla.cjs` | La valla de red (la del E2E del CRM, `fence.cjs`, aquí para no depender del otro repo) |
| `sim-pruebas.cjs` | Las pruebas propias de los simuladores y la valla: `node sim-pruebas.cjs` |
| `../../../ops/supervisor/ironbody-voice.conf` | El **borrador** del worker `ironbody-voice` (sin instalar) |

Los `.cjs` solo usan módulos `node:`: no hace falta `npm install`. Como módulo (`require`), `sim-elevenlabs.cjs` exporta `firmar`, `postCall`, `VARIANTS`, `RUTAS`, `VIOLACIONES` y `GUIONES`, y `sim-twilio.cjs` exporta `RUTAS`, `PERMISOS`, `VARIANTES_AVISO` y `paramsAviso`, sin arrancar nada (para el E2E de TEST8).

## Qué monta `montar.sh`

1. **Git.** Un clon («producción») en **`cfcd0d1`**, el backend en producción el 03-11-2026. Su `origin` es otro clon cuyo `main` es la ref que se quiere desplegar (`HEAD` por defecto).
   - Con `BANCO_WIP=1`, el origen añade además los cambios sin confirmar de `backend/{app,config,routes,database}`.
   - El defecto es **`BANCO_WIP=0`**: con varios agentes escribiendo a la vez, `1` metería trabajo a medias.
2. **PostgreSQL 14 desechable** en `127.0.0.1:55432` (usuario `ironbody`). La base **`ultron_studio_banco_h8_ops`** se borra y se recrea; `BANCO_DB` tiene que empezar por `ultron_studio_banco_h8_`. Lleva el modelo de roles de producción:
   - `h5_owner` = `iron`, dueño sin superusuario;
   - `h5_app` = `iron_app`, solo DML por privilegios por defecto.
3. **Fichero de entorno SINTÉTICO de la fase 1**, con la sangría de dos espacios de producción:
   - todo apagado y **sin ninguna pieza de voz**;
   - `MAIL_MAILER=log`: el correo no sale por un socket crudo, que la valla no ve;
   - `ELEVENLABS_BASE_URL` apunta al simulador como guarda (se ignora en `production`);
   - `WHATSAPP_DISPLAY_PHONE` es sintético.
4. **Piezas sintéticas** de los simuladores en `sim/piezas.conf` (700/600; el correspondiente de la fase 2, más abajo). La aplicación no las tiene.
5. **Migraciones** de `cfcd0d1` como el dueño, cachés en 600 e instantáneas de sistema de integraciones. Las órdenes de artisan del montaje van con un proxy a un puerto cerrado (`127.0.0.1:9`).
6. **Sustitutos** en `bin/`:
   - `sudo`, que ignora `-u`;
   - `stat -c` de GNU, `systemctl`, `sha256sum` y `flock` (no bloquea);
   - **`supervisorctl`**, con los 13 programas de producción por su nombre y el 14.º, `ironbody-voice`, cuando el worker está «instalado» (`ensayar.sh <banco> worker instalar`). `reread` y `update` contestan como supervisor.
7. **Respaldo** en `bk/` con el formato del runbook, en 700/600.

Resultado del montaje del 03-11-2026 (12 s):

```
rutas del Studio: 56 (56 = el H7 en producción)
instantáneas de sistema: 3 (3 = meta_ads, twilio y voice)
voz: ELEVENLABS_NOT_CONFIGURED (fase 1: ELEVENLABS_NOT_CONFIGURED)
tablas con datos en el volcado: 227
```

> **AVISO (Enmienda 1, E1-12): producción ya no es así.** Desde el 03-11-2026 a las 22:55 UTC, ElevenLabs está configurado y verificado en producción:
>
> - piezas puestas: `ELEVENLABS_API_KEY`, `ELEVENLABS_AGENT_ID`, el webhook, las herramientas, `ELEVENLABS_REGION=default` y `ELEVENLABS_ENABLED=true`;
> - `integrations-snapshot --verify-voice` dio «credenciales válidas».
>
> Este banco monta la producción de **antes** (`NOT_CONFIGURED`). OPS8-b tiene que añadirle el estado de E1-12 antes de ensayar el despliegue:
>
> - las piezas de `sim/piezas.conf` en el fichero de entorno, con `ELEVENLABS_ENABLED=true`;
> - un `integrations-snapshot --verify-voice` contra el simulador.
>
> Lo pide E1-12: el `deploy-h8-backend-fase1.sh` no debe abortar por encontrar las piezas, y el monitor y el smoke esperan `ELEVENLABS_VERIFIED`. Hoy, el camino «configurado» ya se ensaya con `ensayar.sh <banco> doctor`, en el entorno de **un** proceso.

## Puertos

Todos ≥ 8400, distintos y libres; `montar.sh` comprueba las tres cosas.

| Puerto | Qué | Variable |
|---|---|---|
| 8401 | La API del banco (`artisan serve --no-reload`, 4 workers) | `BANCO_PORT` |
| 8402 | Simulador de ElevenLabs | `BANCO_PUERTO_EL` |
| 8403 | Simulador de Twilio | `BANCO_PUERTO_TW` |
| 8404 | La valla | `BANCO_PUERTO_VALLA` |
| 55432 | El PostgreSQL desechable (no lo arranca el banco) | `BANCO_PG_PORT` |

## Uso

```bash
O=backend/docs/ultron-studio/ops/banco-h8; B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h8   # desde la raíz del worktree
bash $O/montar.sh $B                         # BANCO_WIP=0; la base ultron_studio_banco_h8_ops se recrea
bash $O/ensayar.sh $B arrancar               # valla, simuladores y API; «estado» y «parar» como siempre
bash $O/sembrar.sh $B --canarios-voz         # cuenta de smoke (ADMIN_ID en banco.env) y 2 canarios de voz activos
bash $O/ensayar.sh $B valla                  # 7 casos: la valla caza https y http; lo local no pasa por ella; la API lleva el proxy
bash $O/ensayar.sh $B doctor                 # 7 casos: NOT_CONFIGURED sin red; --verify contra el simulador = credentials_ok, 1 GET
bash $O/ensayar.sh $B worker revisar         # 13 propiedades del borrador de supervisor
bash $O/ensayar.sh $B worker comprobar       # la orden literal del borrador contra la «producción» (código 3 = PENDIENTE)
bash $O/ensayar.sh $B worker instalar        # supervisorctl: 13 → 14 programas; «worker retirar»: 14 → 13
bash $O/ensayar.sh $B cierre                 # 0 violaciones en los dos simuladores y la valla sin más que sus sondas
node $O/sim-pruebas.cjs                      # sin banco: las pruebas propias (73 casos)
# limpiar
bash $O/ensayar.sh $B parar; rm -rf $B; dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h8_ops
```

- `worker comprobar` da hoy **PENDIENTE**: ni `cfcd0d1` ni el origen tienen la conexión `voice` en `config/queue.php`. El error literal es `InvalidArgumentException: The [voice] queue connection has not been configured.` Esa conexión y su carril son de **B8-b** (Enmienda 1, E1-10).
- La siembra de canarios sigue el camino del H6: el modelo con el cast `encrypted`, `forceCreate` y la huella de `PhoneFingerprint::of`, con una segunda identidad que autoriza y activa. No pasa por la API de los cuatro ojos. Imprime solo recuentos.

## Superficie simulada

**Toda ruta o verbo fuera de estas listas es una VIOLACIÓN** (404 o 405, como el proveedor). La lista viva, con la cláusula de cada violación, sale de `GET /__surface` en cada simulador.

### ElevenLabs: 13 rutas (cabecera `xi-api-key`)

`verificada (B8-a)`: comprobada por B8-a en la referencia oficial el 03-11-2026, según los docblocks de `ElevenLabsAgentDeployer` y `ElevenLabsLabSessionIssuer`. `por confirmar`: D8-3. Si B8 encuentra otra ruta, se cambia en `RUTAS` de `sim-elevenlabs.cjs` y en ningún otro sitio.

| Verbo | Ruta | Id | Estado | Consulta admitida |
|---|---|---|---|---|
| `POST` | `/v1/convai/tools` | `tools_create` | verificada (B8-a) | — |
| `GET` | `/v1/convai/tools/{tool_id}` | `tools_get` | verificada (B8-a) | — |
| `POST` | `/v1/convai/agents/create` | `agents_create` | verificada (B8-a) | — |
| `GET` | `/v1/convai/agents` | `agents_list` | por confirmar | `page_size`, `cursor`, `search` |
| `GET` | `/v1/convai/agents/{agent_id}` | `agents_get` | verificada (H6 y B8-a) | — |
| `GET` | `/v1/convai/phone-numbers` | `numbers_list` | por confirmar | — |
| `GET` | `/v1/convai/phone-numbers/{phone_number_id}` | `numbers_get` | por confirmar | — |
| `PATCH` | `/v1/convai/phone-numbers/{phone_number_id}` | `numbers_patch` | verificada (B8-a) | — |
| `POST` | `/v1/convai/twilio/outbound-call` | `outbound_call` | verificada (auditoría §d.1 y B8-a) | — |
| `GET` | `/v1/convai/conversations` | `conversations_list` | por confirmar | `agent_id`, `page_size`, `cursor`, `call_start_after_unix`, `call_start_before_unix`, `call_successful` |
| `GET` | `/v1/convai/conversations/{conversation_id}` | `conversations_get` | por confirmar | — |
| `DELETE` | `/v1/convai/conversations/{conversation_id}` | `conversations_delete` | por confirmar (D8-17); **apagada por defecto** | — |
| `GET` | `/v1/convai/conversation/get-signed-url` | `signed_url` | verificada (B8-a) | `agent_id` |

**No están, y por tanto son violaciones:**
- `PATCH` y `DELETE` de un agente (D8-7: un agente no se modifica nunca; B8-a no tiene ninguno);
- importar números (`POST /v1/convai/phone-numbers`: es del propietario, C-A1);
- listar o borrar herramientas;
- cualquier otra ruta.

**Además de la ruta, el simulador vigila el contenido**, y cada código de violación cita su cláusula:
- la clave: va **solo** en `xi-api-key`; sin `Authorization`, sin la clave en la consulta y sin cuerpo en un GET;
- **cada herramienta**, al crearla:
  - webhook a `/api/voice/tools/{consultar_planes|consultar_horarios|solicitar_humano|no_volver_a_llamar}` con `POST`;
  - `Authorization` como referencia `secret_id`, nunca el valor;
  - `conversation_id` y `call_sid` ligados a `system__conversation_id` y `system__call_sid` (C-A3, DF-E8);
  - de sistema, solo `end_call` y `transfer_to_number`;
- **el agente**:
  - los `tool_ids` tienen que ser emitidos por el simulador, y `end_call` tiene que estar;
  - transferencias en conferencia y como mucho 3;
  - `caller_authorized = no` por defecto (D8-11);
  - ráfaga apagada, audio guardado apagado, retención ≤ 30 días y sin sobrescrituras;
  - el webhook de iniciación en `/api/webhooks/voice/elevenlabs/initiation` y con su cabecera por referencia;
- **la saliente**:
  - grabación apagada en ElevenLabs y en Twilio, y sin `conversation_config_override`;
  - `dynamic_variables` exactamente `{caller_authorized: "si", call_ref: <uuid>}`, sin teléfonos, y `user_id = call_ref`.

Lo que valida el propio ElevenLabs no es violación, sino su error 4xx: `max_duration_seconds` fuera de 60–7200 da 422; un agente desconocido, 404; el límite de concurrencia del agente, 429.

**Llamadas salientes.** Una saliente aceptada crea la conversación (`conv_…`) y la **llamada en el simulador de Twilio, con la credencial de ElevenLabs**, como hace la integración nativa. Laravel la ve después en Twilio con la suya. `twilio_machine_detection` llega como objeto (la forma de B8-a) y pasa a Twilio como `MachineDetection=Enable`.

### Twilio, subcuenta de voz: 10 rutas (Basic; `{sid}` tiene que ser la subcuenta)

| Verbo | Ruta (`/2010-04-01/Accounts/{sid}…`) | Id | Quién puede | Consulta admitida |
|---|---|---|---|---|
| `GET` | `.json` | `account_get` | Laravel | — |
| `POST` | `/Calls.json` | `calls_create` | ElevenLabs | — |
| `GET` | `/Calls.json` | `calls_list` | Laravel y ElevenLabs | `PageSize`, `Page`, `PageToken`, `StartTime>`, `StartTime<`, `StartTime`, `EndTime>`, `EndTime<`, `Status`, `ParentCallSid` |
| `GET` | `/Calls/{CallSid}.json` | `calls_get` | Laravel y ElevenLabs | — |
| `POST` | `/Calls/{CallSid}.json` | `calls_update` | Laravel (solo `Status=completed`) y ElevenLabs | — |
| `GET` | `/IncomingPhoneNumbers.json` | `numbers_list` | Laravel y ElevenLabs | `PageSize`, `Page`, `PageToken` |
| `GET` | `/IncomingPhoneNumbers/{PhoneNumberSid}.json` | `numbers_get` | Laravel y ElevenLabs | — |
| `POST` | `/IncomingPhoneNumbers/{PhoneNumberSid}.json` | `numbers_update` | ElevenLabs | — |
| `GET` | `/Usage/Records.json` | `usage_records` | Laravel | `Category`, `StartDate`, `EndDate`, `IncludeSubaccounts`, `PageSize`, `Page`, `PageToken` |
| `GET` | `/Usage/Records/{Today\|Yesterday\|ThisMonth\|LastMonth\|Daily\|Monthly}.json` | `usage_records_sub` | Laravel | ídem |

**Credenciales, por clase** (el registro anota la clase, nunca el valor):

| Clase | Qué es | Qué hace el simulador |
|---|---|---|
| `laravel_key` | La API key restringida de Laravel (D8-5) | Solo lo de su columna; lo demás, 401 y violación: `call_create_by_laravel`, `number_update_by_laravel` o `permission_denied:*` |
| `elevenlabs_key` | La de ElevenLabs (C-A1, **por confirmar**) | Crear y actualizar llamadas y números |
| `signing_token` | El Auth Token de la subcuenta | Se atiende, como Twilio, y es violación `signing_token_used_for_api`: solo firma. La ficha de la cuenta trae `auth_token` |
| `master` | La credencial maestra, la del OTP (los mismos valores sintéticos que el `TWILIO_SID`/`TWILIO_TOKEN` del entorno) | Se atiende y es violación `master_credential_used` (D8-4) |
| `mixed`, `none` | Usuario de una y secreto de otra; o sin Basic | 401 y violación |
| `unknown` | Otra credencial | 401, `authRejected`, sin violación |

**Otras reglas del simulador de Twilio:**
- ningún teléfono en la consulta (`To`, `From`… → `phone_in_query:*`);
- un `PageToken` que el simulador no emitió es `page_token_forged`;
- Laravel solo cuelga, con exactamente `Status=completed`:
  - en curso → `completed`;
  - sonando → `canceled`;
  - ya terminada → 200 sin cambios (idempotente; la respuesta exacta de Twilio está **por confirmar**);
- una llamada con el número de WhatsApp como `From` es violación.

**Precios:** por minuto empezado, con un mínimo de 1 en una completada. Son las tarifas de la auditoría §d.2 (de octubre de 2026, «$» sin código de moneda):

| Concepto | US$ por minuto |
|---|---|
| Entrante | 0,0945 |
| Saliente a móvil | 0,0377 |
| Saliente a fijo | 0,0700 |
| Media Streams (se suma a cada llamada) | 0,0044 |

El uso (`Usage/Records`) se agrega de las llamadas, por periodo y categoría: `calls`, `calls-inbound`, `calls-outbound` y `totalprice`.

## Emisores hacia Laravel (por el control, solo GET)

| Emisor | Destino (contrato D8-16) | Autenticación | Variantes y comprobaciones |
|---|---|---|---|
| `sim-elevenlabs /__inbound?from=…` | `POST /api/webhooks/voice/elevenlabs/initiation` | Cabecera `X-Voice-Initiation-Secret` (la misma que los fixtures de TEST8-a) | Cuerpo oficial: `caller_id`, `agent_id`, `called_number`, `call_sid` y `conversation_id`. Variantes: `valid`, `no_secret`, `bad_secret` y `oversize` (> 8 KB) |
| `sim-elevenlabs /__tool?conversation_id=…&tool=…` | `POST /api/voice/tools/{tool}` | `Authorization: Bearer` | Variantes: `valid`, `no_auth`, `bad_bearer`, `basic_scheme`, `unknown_tool` y `oversize` |
| `sim-elevenlabs /__emit/post-call?…` | `POST /api/webhooks/voice/elevenlabs/post-call` | `ElevenLabs-Signature: t=…,v0=HMAC-SHA256(secreto, "t.cuerpo crudo")` | Tipos y **21 variantes** de firma (abajo), con `repeat=N` para los duplicados |
| `sim-twilio /__emit/usage-trigger?variant=…` | `POST /api/webhooks/voice/twilio/usage-trigger` | `X-Twilio-Signature` con el token de la **subcuenta**, sobre la **base canónica** (`TW_SIGN_BASE` = `VOICE_WEBHOOK_BASE_URL`) + la URI tal como se envía | Los 10 parámetros del aviso, en el orden de los fixtures de TEST8-a. **13 variantes** (abajo) |

**Iniciación:**
- **la respuesta de Laravel se revisa**: `type`, y `dynamic_variables` exactamente `{caller_authorized ∈ {si, no}, call_ref uuid, version_label}`; sin claves de más, sin `conversation_config_override`, sin datos personales y ≤ 8 KB;
- **si Laravel falla**, `fail_mode=defaults` sigue con el `caller_authorized` por defecto del agente y `abort` no arranca la llamada: son las dos lecturas de C-A2.

**Herramientas:**
- el cuerpo se rellena **desde el esquema compilado del agente**, con las propiedades `dynamic_variable: system__*`, como C-A3;
- con el esquema estricto de DF-E8, lo que no está en el esquema no viaja;
- la respuesta no puede llevar teléfonos.

**Post-llamada:**
- tipos: `post_call_transcription`, `post_call_audio`, `call_initiation_failure`, `unknown_type`, `no_type` e `invalid_json`;
- 7 guiones de transcripción: `limpio`, `sin_aviso`, `precio_inventado`, `descuento`, `url`, `tarjeta` y `transferencia`;
- modela la **desactivación automática** de ElevenLabs: 10 fallos seguidos sin una entrega buena en 7 días;
- el generador puro, sin envío, es `/__post-call` (el del H6).

**Variantes de la post-llamada**, con su resultado esperado (`ok` o el error):
`valid`, `edge_past_ok`, `edge_future_ok`, `multi_v0`, `uppercase`, `empty_parts` y `unknown_keys` → `ok`; `expired` → `expired`; `from_future` → `from_future`; `mismatch_secret` y `mismatch_body` → `mismatch`; `null_header`, `missing` y `blank` → `missing_signature`; `no_t`, `two_t`, `bad_t`, `t_13_digits`, `no_v0`, `short_v0` y `part_without_eq` → `malformed_signature`.

**Variantes del aviso de gasto:**
- `accepted`: `valid`, `replay` (dos entregas idénticas) y `query_order` (`?b=2&a=1` intacta);
- `rejected`: `master_token`, `wrong_token`, `tampered_param`, `extra_param`, `missing_header`, `empty_header`, `scheme_flip` y `signed_over_real_url` (lo que vería `fullUrl()`);
- `signature_ok`: `repeated_keys` y `json_body_sha256` (no 401/403).

**El simulador también caza a Laravel:** si Laravel **acepta** (2xx) algo que debía rechazar, es una violación:
- `post_call_bad_signature_accepted`;
- `initiation_bad_secret_accepted` e `initiation_oversize_accepted`;
- `tool_bad_auth_accepted`, `tool_outside_catalog_accepted` y `tool_oversize_accepted`;
- `usage_trigger_bad_signature_accepted`.

## Control (solo GET; otro verbo es la violación `control_method`)

- **Los dos:**
  - `/__stats`: recuentos, violaciones por código y latencias p50/p95 de las emisiones;
  - `/__requests?since=N`, `/__surface`, `/__state` y `/__fault`;
  - `/__reset?state=1`, que vacía el estado del dominio **pero nunca las violaciones**.
- **ElevenLabs:**
  - `/__call?conversation_id=…&event=ringing|answered|voicemail|completed|busy|no-answer|failed|canceled&duration=N[&transfer_to=E.164]`: la transferencia crea en Twilio el tramo hijo con `parent_call_sid` (DF-W4);
  - `/__scenario?to=…&outcome=success_false|twilio_down`;
  - `/__panel-call?to=…`: una llamada que Laravel no pidió (R-2);
  - `/__drift?agent_id=…|tool_id=…&path=…&value=…`: un cambio «en el panel»;
  - `/__webhook?reset=1|last_success_days_ago=N` y `/__config?allow_conversation_delete=0|1&initiation_fail_mode=…&initiation_timeout_ms=…`.
- **Twilio:**
  - `/__seed-call?direction=…&from=…&to=…&status=…[&parent_call_sid=CA…]`: una llamada que nadie pidió, o una entrante;
  - `/__call-status?sid=…&status=…`: un estado final no cambia.
- **La valla:** solo `/__stats` y `/__requests` por petición **directa**; todo lo que llega como proxy se bloquea.

**Fallos inyectables por ruta** (`/__fault?route=<id|all>&kind=…&times=…&delayMs=…`; no son violaciones, se anotan como `fault`):

| `kind` | Qué hace |
|---|---|
| `status` | Responde 400–599 antes de cualquier efecto |
| `garbage` | 200 con HTML |
| `reset` | Corta la conexión |
| `timeout` | No responde y no hay efecto |
| `delay` | Espera y luego atiende; si el cliente ya se fue, sin efecto |
| `hang_after_effect` | **Hace** el efecto (crea la llamada) y no responde: el `ambiguous` de verdad |

## Registro

- Un JSONL por proceso en `logs/`, en 600, más `/__requests` en memoria.
- **Se guarda:** el método, la plantilla de ruta, el id de ruta, la clase de credencial, el estado, los milisegundos, las violaciones y el fallo.
- **Nunca se guardan** cabeceras, claves, cuerpos, valores de consulta ni teléfonos. La prueba de higiene lo barre.

## Piezas del banco y variables de la aplicación

`sim/piezas.conf` lleva lo que el propietario pondría en la fase 2, sintético. La correspondencia con las variables que fija B8-a en `config/voice.php` sirve al mando de alcance de OPS8-b y al E2E de TEST8:

| Pieza | Variable de la aplicación |
|---|---|
| `EL_API_KEY` | `ELEVENLABS_API_KEY` |
| `EL_VERIFY_AGENT_ID` | `ELEVENLABS_AGENT_ID` (el agente de verificación, D8-5) |
| `EL_WEBHOOK_SECRET` | `ELEVENLABS_WEBHOOK_SECRET` |
| `EL_TOOL_SECRET` | `ELEVENLABS_TOOL_SECRET` |
| `EL_INITIATION_SECRET` | `ELEVENLABS_INITIATION_SECRET` |
| `EL_PHONE_NUMBER_ID` | `ELEVENLABS_AGENT_PHONE_NUMBER_ID` |
| `EL_BASE` | `ELEVENLABS_BASE_URL` (ya está en el entorno de la fase 1) |
| `TW_ACCOUNT_SID` | `TWILIO_VOICE_ACCOUNT_SID` |
| `TW_LARAVEL_KEY_SID` y `TW_LARAVEL_KEY_SECRET` | `TWILIO_VOICE_API_KEY_SID` y `TWILIO_VOICE_API_KEY_SECRET` |
| `TW_SIGNING_TOKEN` | `TWILIO_VOICE_SIGNING_TOKEN` |
| `EL_TWILIO_BASE` | `TWILIO_VOICE_BASE_URL` |
| `VOICE_NUMBER` | `TWILIO_VOICE_FROM` |
| `TW_SIGN_BASE` | `VOICE_WEBHOOK_BASE_URL` (`https://api.ironbody-banco.invalid`: distinta de la URL real del banco, para cazar un `fullUrl()`) |
| `WHATSAPP_NUMBER` | `WHATSAPP_DISPLAY_PHONE` (ya está en el entorno) |
| `TW_EL_KEY_SID` y `TW_EL_KEY_SECRET` | Ninguna: es la credencial de ElevenLabs y la aplicación nunca la tiene |

Las referencias de secretos del gestor de ElevenLabs (`ELEVENLABS_*_REF`) no son secretos: en el banco vale cualquier texto opaco.

**Números sintéticos**, con la convención de los fixtures de TEST8-a:

| Número | Qué es |
|---|---|
| +57 601 000 0090 | El número de voz |
| +57 300 000 0050 | El de WhatsApp |
| +57 300 000 0011 y 0012 | Los canarios |

## Pruebas propias

`node sim-pruebas.cjs` da **73 OK · 0 FALLO · 0 OMITIDO** el 03-11-2026.

- **Cómo:** arranca los dos simuladores y la valla en puertos del sistema, con un receptor que hace de Laravel.
- **Oráculos independientes:**
  - para ElevenLabs, la clase **real** del H6, `ElevenLabsWebhookSignature::verify`, sobre las 21 variantes;
  - para Twilio, 3 vectores de la documentación y de los SDK, recomputados, y una segunda implementación en **Python**.
- **Contraste con TEST8-a:** si existen sus fixtures (`tests/fixtures/voice/`), se contrastan:
  - el firmador de Twilio reproduce sus vectores de cálculo;
  - el de ElevenLabs da la misma cabecera en sus válidos canónicos;
  - las formas de los cuerpos coinciden clave por clave.
- **Configuración:** `BANCO_H8_BACKEND` cambia el backend que hace de oráculo; por defecto, el del worktree.

## Qué no prueba

- El cambio de usuario real, `sudo`, `flock`, `crontab`, GNU `stat`, `grep`, `sed` y `find`, los dueños `root` y `www-data`, FPM y supervisor de verdad.
- El WebSocket del laboratorio hablado: la URL firmada apunta a `ws://127.0.0.1`, pero no hay servidor.
- La telefonía real: audio, contestador, conferencia y tiempos de Twilio y ElevenLabs.
- Lo que la documentación no dice:
  - el plazo de la iniciación (el simulador espera 5 s, configurable);
  - el código de error exacto de una key restringida sin permiso;
  - la respuesta de Twilio al colgar una llamada ya terminada;
  - el formato de `DateFired` y de `IdempotencyToken`;
  - las unidades de `metadata.cost`.
- La valla cubre los clientes que respetan las variables de proxy (Guzzle/cURL, que es el `Http::` de Laravel), no los sockets crudos.

Para todo eso, el banco no sustituye la comprobación en el servidor ni las pruebas físicas de la fase 3.

## OPS8-b (08-11-2026): el ensayo de la fase 1 y los cambios del simulador

**Órdenes nuevas de `ensayar.sh`** (con `preparar.sh`, que copia en `$B/root` el `deploy-h8-backend-fase1.sh` y la propuesta de `ironbody-migrate` con las sustituciones del banco; cada una tiene que casar):

| Orden | Qué hace |
|---|---|
| `e112` | Pone la «producción» del banco en el estado de E1-12. Escribe las piezas del H6 de ElevenLabs (de `sim/piezas.conf`, sin valores en la línea de órdenes) y `ELEVENLABS_ENABLED=true`. Guarda `integrations-snapshot --verify-voice` contra el simulador (un GET y nada por la valla), de modo que el Studio afirma `ELEVENLABS_VERIFIED` y el proveedor neutro `ELEVENLABS_UNVERIFIED`. Rehace el respaldo de partida: `env.backup` y un volcado con su huella, con ruta absoluta como el runbook |
| `desplegar [--solo-comprobar]` | La copia del banco del despliegue de la fase 1, con RED |
| `smoke [<id>]` | `smoke-run-h8.sh` del repo con la valla como prueba estricta de cero red |

**Variables nuevas de `montar.sh`:**
- `BANCO_PREVIO`: la «producción» del banco; se guarda en `banco.env` (por defecto, `cfcd0d1`).
- `BANCO_UNIR`: une esa ref en el **clon** del banco, nunca en el repo. Sirve para ensayar la rama reescrita, que perdió la ascendencia de producción.

La instantánea de `BANCO_WIP=1` lleva el prefijo `wip(ultron-studio-h8):`, que es como el paso 0 reconoce los commits del hito.

**El ensayo del 08-11-2026:**

```bash
B=$HOME/.cache/ironbody-banco-h8-ops8b
BANCO_DB=ultron_studio_banco_h8_ops8b BANCO_PORT=8411 BANCO_PUERTO_EL=8412 BANCO_PUERTO_TW=8413 BANCO_PUERTO_VALLA=8414 \
  BANCO_PREVIO=7c84cb5 BANCO_UNIR=7c84cb52 BANCO_WIP=1 bash montar.sh "$B" HEAD
bash ensayar.sh "$B" arrancar && bash ensayar.sh "$B" e112 && bash sembrar.sh "$B"
bash ensayar.sh "$B" desplegar --solo-comprobar     # COMPROBACIONES PREVIAS EN VERDE
bash ensayar.sh "$B" desplegar                      # DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO
bash ensayar.sh "$B" smoke                          # ver informe-devops.md §OPS8-b
```

**El simulador de ElevenLabs:**
- **Variables dinámicas de E1-5.**
  - La saliente lleva exactamente `call_direction` (= `saliente`), `call_ref`, `caller_authorized` y `version_label`. Las violaciones nuevas son `outbound_call_direction` y `outbound_version_label`, y `dynamic_variables_beyond_contract` pasa al conjunto de 4.
  - La respuesta de la iniciación, igual, con `call_direction` = `entrante`: violaciones `initiation_response_call_direction` y `initiation_response_version_label`.
  - Si Laravel falla, `fail_mode=defaults` usa también el defecto de `call_direction`.
- **Conversaciones web del laboratorio hablado (D8-23).**
  - La URL firmada registra una conversación con `direction: 'web'`, sin número ni `call_sid`.
  - Ni su detalle (`GET /v1/convai/conversations/{id}`) ni su post-llamada llevan `metadata.phone_call`.
  - `postCall({conv: {direction: 'web', …}})` (puro) lo hace igual, y para una conversación web `call_initiation_failure` es un error.
  - El emisor: `GET /__emit/post-call?web=1&agent_id=<agente de laboratorio>`, que usa la conversación de su URL o crea una.
  - El arnés de TEST8-b (`ops/h8-e2e/lab-post-call.cjs`) sigue sirviendo sin cambios: su `delete` de `phone_call` ya no tiene nada que quitar, y la firma es la misma.
- **Pruebas propias:** `node sim-pruebas.cjs`, **78 OK · 0 FALLO** (73 de antes y 5 nuevas). Provocan a propósito 50 violaciones de ElevenLabs (antes 46) y 22 de Twilio.

**F-6 (TEST8-b), en `sim-comun.cjs`:** `pareceDatoPersonal` ya no toma la cola de un uuid por un teléfono: los uuid se quitan enteros y un número no puede llevar letras ni dígitos pegados. La detección de teléfonos reales no cambia. Medido sobre 20 000 uuid aleatorios: antes 57 falsos, ahora 0. `sim-pruebas.cjs`: 80 OK.

## OPS8-c (09-11-2026): el mando, el registro de secretos y la comprobación de Twilio

**Órdenes nuevas de `ensayar.sh`** (cada una rehace con `preparar.sh` la copia del banco del script, que solo cambia su bloque «lo propio de producción» y la comprobación de root):

| Orden | Qué hace |
|---|---|
| `alcance <orden del mando…>` | `h8-voice-scope.sh` del repo contra la «producción» del banco, con RED |
| `secretos [opciones…]` | `h8-secrets-register.sh` contra el simulador de ElevenLabs |
| `twilio [--dias=N]` | `h8-twilio-check.sh` contra el simulador de Twilio |
| `constante abrir\|cerrar` | Gira `CALLS_ENABLED_IN_CODE` **solo en el clon del banco** (`$B/api`) con un commit `voice(calls-open): banco: …` de una línea; «cerrar» lo deshace con un `git revert` (sin reescribir). Nunca en el árbol compartido (protección 2) |
| `piezas-twilio <fichero>` | Escribe en un fichero 600 las tres piezas secretas de Twilio del banco (de `sim/piezas.conf`) para `alcance fase 2 secreto`; nada pasa por la línea de órdenes |

**`ensayar-ops8c.sh <banco>`:** el ensayo completo y reproducible (banco montado, en marcha, con E1-12, sembrado y con la fase 1 desplegada). Recorre las negativas del mando, la fase 2 (base, registro, número y piezas secretas), la comprobación de Twilio escenario a escenario, el monitor (con la conciliación en seco que se niega con una llamada ajena), publicar con su vuelta atrás exacta byte a byte, el laboratorio, la expectativa y las llamadas con la constante girada en el clon, el worker y el presupuesto. Termina con 0 violaciones nuevas en los dos simuladores y la valla sin hosts de fuera. Cada orden queda en `$B/logs/ensayo-ops8c-<fecha>.txt` (600, sin secretos: las huellas se comparan dentro del script).

**Cambios del simulador de ElevenLabs** (18 rutas: las 13 de antes y 5 nuevas, verificadas en la referencia oficial el 09-11-2026):

| Verbo | Ruta | Id |
|---|---|---|
| `GET` | `/v1/convai/secrets` (`page_size`, `cursor`, `search`, `dependency_limit`) | `secrets_list` |
| `POST` | `/v1/convai/secrets` (`{type: new, name, value}`) | `secrets_create` |
| `PATCH` | `/v1/convai/secrets/{secret_id}` (`{type: update, name, value}`) | `secrets_update` |
| `GET` | `/v1/workspace/webhooks` (`include_usages`) | `webhooks_list` |
| `POST` | `/v1/workspace/webhooks` (`{settings: {auth_type, name, webhook_url}}` → `{webhook_id, webhook_secret}`: el secreto lo GENERA el simulador, como ElevenLabs) | `webhooks_create` |

- **Violaciones nuevas:** `secret_request_shape`, `secret_name_not_ours`, `secret_duplicate_name`, `secret_tool_not_bearer`, `secret_update_name_changed`, `webhook_request_shape`, `webhook_auth_not_hmac`, `webhook_url_not_post_call` (la base canónica `TW_SIGN_BASE` + la ruta de la post-llamada), `webhook_name_not_ours`, `webhook_duplicate_name` y `webhook_custom_headers`. Borrar secretos o webhooks no está: es una violación (el registro nunca borra).
- **Los emisores usan lo registrado, como ElevenLabs:** la iniciación manda el valor del secreto que cita el agente (`request_headers['X-Voice-Initiation-Secret'].secret_id`), la herramienta su `Authorization` y la post-llamada firma con el secreto del webhook de `workspace_overrides.webhooks.post_call_webhook_id`. Si el agente no cita nada registrado aquí, el de las piezas, como antes: el E2E de TEST8 no cambia.
- **`/__state?secrets=1`:** lo registrado con la HUELLA sha256 de cada valor, nunca el valor (el oráculo del ensayo la compara con la del fichero de entorno).

**Cambios del simulador de Twilio** (13 rutas):

| Verbo | Ruta | Id | Quién puede |
|---|---|---|---|
| `GET` | `/2010-04-01/Accounts/{sid}/OutgoingCallerIds.json` | `caller_ids_list` | Laravel y ElevenLabs |
| `GET` | `/2010-04-01/Accounts/{sid}/Messages.json` (`PageSize`) | `messages_list` | **Nadie de la voz: SONDA.** La denegación (401) no es violación; se cuenta en `sondasDenegadas` |
| `GET` | `/v2/RegulatoryCompliance/Bundles/{BundleSid}` (sin cuenta en la ruta) | `bundles_get` | Laravel |

- **Control `/__numero`:** `tipo=comprado|caller_id` (el número sale de los comprados y aparece como caller ID verificado: el «Outbound Only»), `bundle=none|aprobado|pendiente`, `voice_url=elevenlabs|otra|vacia`, `address=none|local`, `sms_laravel=0|1` (una key de la voz MAL HECHA, con permisos de mensajería: la sonda la tiene que cazar) y `cuenta=madre|subcuenta` (`owner_account_sid` de la ficha: la propia o la del OTP). `/__reset?state=1` lo devuelve todo a su defecto.
- Crear o verificar un caller ID (`POST OutgoingCallerIds.json`) sigue sin estar: 405.

**Pruebas propias:** `node sim-pruebas.cjs`, **88 OK · 0 FALLO · 0 OMITIDO** (80 de antes y 8 nuevas en la sección L; 2 ajustadas: la superficie de ElevenLabs pasa de 13 a 18 rutas y el ejemplo de «ruta no listada» de Twilio pasa a ser la ficha de un caller ID). Provocan a propósito 62 violaciones de ElevenLabs y 23 de Twilio.
