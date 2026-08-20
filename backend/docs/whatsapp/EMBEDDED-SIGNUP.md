# Conectar WhatsApp Business desde el CRM — Embedded Signup

Cómo funciona la pantalla `Configuración → Integraciones → WhatsApp Business`,
qué falta para que el flujo se complete de extremo a extremo, y cómo grabar la
evidencia que pide Meta en la revisión de `business_management`.

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

## 2. Lo que FALTA para que el flujo se complete

**Una configuración de Facebook Login for Business (`config_id`).** Se crea a
mano en el panel de Meta; no se puede generar desde código.

Verificado contra Graph API el 2026-08-03 y sin cambios desde entonces:

| Comprobación | Resultado |
|---|---|
| `GET /906146885861728/business_login_configs` | *Unknown path components* — no existe ninguna |
| App `906146885861728` | `app_type: 0`, sin permisos aprobados por App Review |
| WABA `1355229980038956` | `APPROVED` / `ACTIVE`, propiedad `SELF` |
| Business | `verification_status: not_verified` |

### Cómo crearla

1. Meta for Developers → App `906146885861728`.
2. Productos → **WhatsApp** → *Embedded Signup* (o *Inicio de sesión con
   Facebook para empresas* → **Configuraciones**).
3. Crear una configuración con:
   - **Tipo de inicio de sesión:** *WhatsApp Embedded Signup*.
   - **Permisos:** `whatsapp_business_management`, `whatsapp_business_messaging`,
     `business_management`.
   - **Token:** código de autorización de un solo uso (*authorization code*), no
     token de acceso — el canje lo hace el backend.
4. Copiar el **ID de la configuración**.

Y en el servidor, `/var/www/api/backend/.env`:

```
META_APP_ID=906146885861728
META_APP_SECRET=<el App Secret real de esa app>
META_EMBEDDED_SIGNUP_CONFIG_ID=<el ID copiado>
```

Después: `php artisan config:clear && php artisan config:cache`.

> Mientras falte cualquiera de las tres, la pantalla NO ofrece el botón: dice
> qué falta **por nombre de variable**. Un botón que abre una ventana de Meta que
> falla convierte un arreglo de dos minutos en una tarde de depuración.

### ¿Hace falta la App Review aprobada para grabar la evidencia?

No. En **modo desarrollo**, los administradores, desarrolladores y probadores de
la app pueden recorrer el flujo completo con permisos aún sin aprobar. Eso es
exactamente lo que Meta espera ver en el vídeo.

---

## 3. Grabar la evidencia para Meta

Lo que Meta pide es el caso de uso completo, empezando en nuestra aplicación.
Este es el guion, y la pantalla está construida para que se pueda seguir sin
cortes.

### Antes de grabar

- [ ] `META_EMBEDDED_SIGNUP_CONFIG_ID` definido y `config:cache` hecho.
- [ ] La cuenta de Meta con la que se graba figura como administrador,
      desarrollador o probador de la app `906146885861728`.
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
| `meta_app_not_configured` | 503 | Falta `META_APP_ID`, `META_APP_SECRET` o el `config_id` |
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
