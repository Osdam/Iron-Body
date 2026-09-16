# Conectar WhatsApp Business desde el CRM — Embedded Signup

Cómo funciona la pantalla `Configuración → Integraciones → WhatsApp Business`,
qué falta para que el flujo se complete de extremo a extremo, y cómo grabar la
evidencia que pide Meta en la App Review.

> Los tokens y el App Secret viven SOLO en el `.env` del servidor o cifrados en
> la base de datos. Nunca en Angular, nunca en Flutter, nunca en este documento.

---

## 0. Por qué existe esta pantalla

Meta rechazó la evidencia de la revisión porque no se mostraba una experiencia
completa del caso de uso: el flujo no empezaba dentro de nuestra aplicación.

Hasta ahora conectar el canal era una tarea de consola —entrar por SSH, pegar
identificadores copiados del panel de Meta en el `.env` y recachear—. Nadie del
negocio podía hacerlo, no quedaba constancia de quién lo hizo, y un valor mal
pegado no se descubría hasta que un mensaje no salía.

Ahora el recorrido entero empieza y termina en el CRM.

Y hay una segunda razón, más cara: **registrar el número por el flujo tradicional
lo SACA de la app WhatsApp Business y el personal pierde WhatsApp Web.** Ya pasó
en este negocio el 2026-06-30 y hubo que deshacerlo. Embedded Signup con
`featureType: whatsapp_business_app_onboarding` (coexistencia) es la única vía
que conserva ambos usos. Ver `docs/marketing-meta-whatsapp.md` §8.

---

## 1. El recorrido, y quién hace cada tramo

```
  CRM                        Backend                      Meta
   │                            │                          │
   │ 1. clic en "Conectar"      │                          │
   ├───── POST .../start ──────►│                          │
   │◄──── app_id, config_id,    │                          │
   │      scopes, state ────────┤                          │
   │                            │                          │
   │ 2. carga el SDK y abre el diálogo                     │
   ├──────────────────────────────────────────────────────►│
   │                            │      login, negocio,     │
   │                            │      número, permisos    │
   │◄──────── postMessage: waba_id, phone_number_id ───────┤
   │◄──────── FB.login: code (un solo uso) ────────────────┤
   │                            │                          │
   │ 3. POST .../callback       │                          │
   ├──── code + state + ids ───►│                          │
   │                            ├─ canje del code ────────►│
   │                            │◄─ access_token ──────────┤
   │                            ├─ datos del negocio ─────►│
   │                            ├─ subscribed_apps ───────►│
   │                            │                          │
   │◄──── conexión confirmada ──┤                          │
```

**El App Secret solo aparece en el tramo 3, servidor contra servidor.** El
navegador maneja únicamente `app_id` y `config_id`, que Meta publica igualmente
en el propio diálogo.

El `state` lo emite el backend, va ligado al administrador que lo pidió y **vale
una sola vez**: un código capturado no puede canjearlo otra sesión, y un doble
clic no reintenta un canje ya gastado.

---

## 2. La app, la configuración y los permisos

**Estado a 2026-09-16.** La configuración de Facebook Login for Business ya
existe; esta sección describe cuál es, no cómo crearla.

| Qué | Valor |
|---|---|
| App de Embedded Signup | **`1747474522949342`** — `META_EMBEDDED_SIGNUP_APP_ID` |
| Configuración (`config_id`) | **`1643115916774956`** — `META_EMBEDDED_SIGNUP_CONFIG_ID` |
| Tipo de inicio de sesión | *WhatsApp Embedded Signup* |
| Token que produce | *System User Access Token*, sin caducidad declarada |
| Permisos solicitados | `whatsapp_business_management`, `whatsapp_business_messaging` |

> **La app del canal es OTRA.** `META_APP_ID` sigue apuntando a la app histórica
> `906146885861728`, dueña del webhook y de su firma. Los dos secretos viven
> separados a propósito: canjear un código de una app firmando con el secreto de
> la otra falla siempre, y falla al final del recorrido. Ver la cabecera de
> `config/meta.php`.

### Por qué NO se pide `business_management`

Se pidió, se rechazó en App Review, y se retiró el 2026-09-16 (commit
`1141bd2`). El motivo no fue el rechazo, sino que **no hacía falta**:

- Meta lo documenta como **opcional**: *«only needed if you need to
  programmatically access your business portfolio (this is rarely needed, since
  you can access your portfolio using Meta Business Suite)»*.
- **Ninguna** llamada Graph de este backend toca un nodo Business. Se estaba
  pidiendo un permiso que la aplicación nunca usó — y un permiso sin uso real no
  se puede demostrar honestamente en una revisión.
- No lo exigen Cloud API, ni Embedded Signup, ni Marketing Messages API. Solo
  hace falta para compartir línea de crédito como Solution Partner.

`whatsapp_business_management` cubre WABA, números, plantillas y analíticas;
`whatsapp_business_messaging` cubre enviar y recibir. Con esos dos está todo.

### ¿Hace falta la App Review aprobada para grabar la evidencia?

No. Los usuarios **con rol en la app** —administrador, desarrollador o
probador— pueden recorrer el flujo con **Standard Access**, que Meta aprueba
automáticamente a las apps de negocios. Eso vale tanto en modo desarrollo como
en modo activo: *«permissions with Standard Access can only be requested from
role users»*.

---

## 3. Grabar la evidencia para Meta

Lo que Meta pide es el caso de uso completo, empezando en nuestra aplicación.
Este es el guion, y la pantalla está construida para que se pueda seguir sin
cortes.

### Antes de grabar

- [ ] `META_EMBEDDED_SIGNUP_CONFIG_ID` definido y `config:cache` hecho.
- [ ] La cuenta de Meta con la que se graba figura como administrador,
      desarrollador o probador de la app **`1747474522949342`** — la del
      Embedded Signup, no la del canal.
- [ ] Sesión iniciada en el CRM con un usuario **Super Admin** o
      **Administrador** (los demás roles ven la pantalla pero no el botón, a
      propósito).
- [ ] Grabar la pantalla completa, sin cortes y sin acelerar.

### El guion

| # | Qué se ve | Qué demuestra |
|---|---|---|
| 1 | El CRM ya abierto, menú lateral → **Configuración** | El flujo empieza en nuestra aplicación |
| 2 | Pestaña **Integraciones**: tarjeta *WhatsApp Business*, estado **No conectado**, y los 5 pasos que van a ocurrir | Contexto e intención declarada antes de salir |
| 3 | Clic en **«Conectar WhatsApp Business con Meta»** | El punto de entrada real |
| 4 | Se abre el diálogo de Meta → **inicio de sesión** | Autenticación en Meta |
| 5 | Selección del negocio **IRON BODY NEIVA** | Elección del Business Manager |
| 6 | Selección de la **cuenta de WhatsApp Business** y del número | Elección del activo |
| 7 | Pantalla de **permisos** → autorizar | Consentimiento explícito |
| 8 | Vuelta automática al CRM: estado **Conectado**, con negocio, número, nombre verificado, WABA, identificador del número y permisos concedidos | El resultado se refleja en nuestra aplicación |
| 9 | (Opcional) **Actualizar datos** → los datos se refrescan desde Graph | La conexión es real, no una pantalla estática |

El paso 8 es el que faltaba en la evidencia anterior: no basta con salir hacia
Meta, hay que volver y **enseñar el resultado dentro del producto**.

### Qué NO hacer en el vídeo

- No mostrar el `.env`, la consola del servidor ni ninguna terminal.
- No abrir las herramientas de desarrollo del navegador.
- No cortar entre el paso 7 y el 8: ese salto es justo lo que se está probando.

---

## 4. Endpoints

Todos bajo `/api/admin/*`, así que el blindaje global (`ProtectAdminPaths` →
`EnsureAdminAuth`) los cubre. Encima, `WhatsappIntegrationAuthorizationService`
exige **sesión de administrador real**: el secreto compartido de automatización
(`ADMIN_API_TOKEN`) responde 401 `integration_requires_admin`, porque la fila que
se guarda registra quién conectó y una máquina no tiene nombre que registrar.

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| `GET`  | `/api/admin/integrations/whatsapp` | cualquier admin activo | Estado de conexión, del canal y capacidades |
| `POST` | `/api/admin/integrations/whatsapp/start` | rol pleno | Parámetros del diálogo + `state` |
| `POST` | `/api/admin/integrations/whatsapp/callback` | rol pleno | Canjea el código y persiste |
| `POST` | `/api/admin/integrations/whatsapp/disconnect` | rol pleno | Suelta la cuenta |
| `POST` | `/api/admin/integrations/whatsapp/refresh` | cualquier admin activo | Re-lee los datos desde Graph |

Códigos de error estables que devuelve el callback:

| `code` | HTTP | Significa |
|---|---|---|
| `meta_app_not_configured` | 503 | Falta `META_EMBEDDED_SIGNUP_APP_ID`, `META_EMBEDDED_SIGNUP_CONFIG_ID` o `META_EMBEDDED_SIGNUP_APP_SECRET` |
| `invalid_signup_state` | 422 | El `state` caducó, ya se usó, o es de otra sesión |
| `code_exchange_failed` | 502 | Meta rechazó el código (caducado, ya usado, permiso retirado) |
| `whatsapp_not_connected` | 404 | No hay conexión que desconectar o refrescar |
| `integration_requires_admin` | 401 | Se usó el secreto compartido, no una sesión real |
| `integration_forbidden` | 403 | El rol no puede conectar ni desconectar |

---

## 5. Dónde acaban los datos

Tabla `whatsapp_business_integrations`. Una fila por par (WABA, número); volver a
conectar el mismo número **actualiza** la fila en vez de acumular otra.

- `access_token` va **cifrado** en reposo (cast `encrypted`) y oculto en
  cualquier serialización. Un volcado de la base de datos —lo primero que se
  comparte cuando hay que depurar algo— no puede convertirse en permiso para
  escribirle a los clientes.
- La API nunca devuelve el token: solo `has_access_token`.
- Desconectar **no borra la fila**, destruye el token. Saber qué número estuvo
  conectado y cuándo es lo primero que se pregunta cuando algo dejó de llegar.

---

## 6. Cómo afecta al canal que ya funcionaba

Las credenciales del canal tienen ahora **dos orígenes y un orden estricto**
(`App\Services\Meta\WhatsappIntegrationRegistry`):

1. La conexión hecha desde el CRM, **si está usable** — conectada, con token y
   sin caducar.
2. El `.env` del servidor, como siempre.

**Sin fila conectada, todo se comporta byte a byte igual que antes.** Es el
estado actual de producción, y está cubierto por
`tests/Feature/Integrations/WhatsappCredentialPrecedenceTest.php`.

Puntos que consultan el resolvedor: `MetaMessagingService` (envío),
`MetaMediaService` (subida de adjuntos), `ProcessMetaWebhookEvent` (filtro
multi-número de los entrantes), `WebhookMetaController` (traza),
`SupervisionService` (estado del canal) y `MetaDoctorService`, que además reporta
`credential_source` para que un token del `.env` y uno guardado no sean
indistinguibles en el diagnóstico.

### Lo que esta funcionalidad NO hace

**Conectar no enciende el envío.** `META_ENABLED` sigue siendo el único
interruptor que autoriza salir a la red. Guardar credenciales y autorizar
mensajes a clientes reales son dos decisiones distintas, y quien hace la primera
desde el CRM no debería estar disparando la segunda sin saberlo. La pantalla lo
dice explícitamente: *«La cuenta está conectada… pero no sale ninguno»*.

---

## 7. Vuelta atrás

Por orden de menor a mayor intervención, y ninguna requiere desplegar:

| Situación | Qué hacer |
|---|---|
| La conexión guardada da problemas y quieres volver al `.env` | `META_DB_CREDENTIALS_PRECEDENCE=false` + `config:cache`. La fila se queda intacta. |
| Quieres soltar la cuenta del todo | Botón **Desconectar** en la pantalla. El número NO se borra de Meta. |
| Quieres cortar la salida de mensajes | `META_ENABLED=false` + `config:cache` (lo de siempre; ver `ACTIVACION-META.md` §12) |
| Quieres retirar la pantalla | Quitar la pestaña `integrations` de `frontend/src/app/modules/settings.ts` y reconstruir. El backend queda inerte: sin fila conectada no cambia nada. |

---

## 8. Lo que queda pendiente para la coexistencia completa

Fuera del alcance de esta entrega, anotado para no perderlo:

- **Suscribirse al campo `smb_message_echoes`** del webhook, además de
  `messages`. Sin él, los mensajes que el personal escriba desde la app
  WhatsApp Business **no llegan** al CRM.
  `MetaWebhookService::parseEvents()` hoy solo procesa `messages` y `statuses`;
  necesita una rama para ese campo.
- **Sincronizar el historial** en las 24 h siguientes al onboarding.
- **Partner-Led Business Verification (PLBV)**: para cuentas de coexistencia la
  verificación estándar no es el camino. El `not_verified` actual **no impide**
  arrancar el onboarding.
- Límites propios de coexistencia: 20 mensajes/segundo, sin insignia de cuenta
  oficial, sin grupos ni listas de difusión.
