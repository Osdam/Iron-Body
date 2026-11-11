# P9-25 · El vale de la URL: un solo uso, un minuto y una finalidad

**Agente:** security · **Fecha:** 2026-11-10 · **Ramas:** backend y CRM `feat/ultron-studio-h9`, **sin commit** · **Origen:** hallazgo R-1 de SEC9-a (`informe-security.md` §2) y fila «Tique en la URL» de `ULTRON_STUDIO_H9_AMENAZAS.md`.

## 1. El riesgo

`EventSource` y `<img src>` no pueden mandar la cabecera `Authorization`. Por eso el CRM abre los streams SSE y carga las fotos biométricas con un **vale** en la query (`?ticket=`), y nginx guarda esa URL completa en `access.log` y en sus rotaciones.

Cómo estaba (verificado en el código de la rama antes del cambio):

| Pieza | Comportamiento |
|---|---|
| `app/Services/Admin/StreamTicketService.php:33` | `TTL_SECONDS = 300`; vale = `Crypt::encryptString({sid, exp})`, sin nonce ni consumo: **reutilizable** durante 5 minutos. |
| `app/Http/Middleware/EnsureAdminAuth.php:58` | El mismo vale abría `*/stream` **y** `*/face-image/*`. |
| CRM `src/app/services/auth.service.ts` (`ensureStreamTicket`/`withStreamToken`) | **Un único vale por pestaña**, renovado 45 s antes de caducar y repartido entre **todos** los streams del CRM (16 consumidores de `openStream`, moderación y el canal del Studio) y **todas** las fotos del catálogo facial. |
| `AttendanceController::faceImage` | `Cache-Control: private, max-age=3600`: la foto se quedaba una hora en la caché de disco del navegador, con la URL (y el vale) como clave. |

**Cómo se explotaba.** Quien leyera el access.log (o una copia o una rotación) copiaba, por ejemplo, `GET /api/admin/memberships/stream?ticket=…` y durante 5 minutos abría, como esa sesión y cuantas veces quisiera, **cualquier** stream del CRM (membresías, cajas, usuarios, clases, el Studio…) y `GET /api/attendances/face-image/{id}?ticket=…` con **cualquier** id: las fotos biométricas de todos los socios (Ley 1581). No entregaba la sesión, pero sí todo eso.

**Lo que salió al investigar** (no estaba en R-1):

1. **El vale era compartido por todo el CRM**: corregir solo el Studio y las fotos dejaba abiertos los demás streams. Un vale de un solo uso obliga, además, a que el CRM pida uno por conexión en todos los consumidores, no solo en el Studio.
2. **`Cache::pull` no es atómico** (leer y luego borrar). Era la recomendación original de R-1; la carrera de §3.2 lo demuestra con 2 a 4 ganadores de 8.
3. **La guarda corre hasta tres veces sobre la misma petición** (`ProtectAdminPaths`, `EnforceAdminAuthorization` y el alias `auth.admin`, por ejemplo en `api/admin/memberships/stream` y en `face-image`). Un vale de un solo uso aplicado a ciegas habría roto esas rutas con un 403 en la segunda pasada.
4. **El limitador del canal del Studio (`studio-stream`) agrupaba por vale.** Con vales de un solo uso, cada reconexión estrena vale y cubo, y el tope de 30/min dejaba de frenar un bucle de reconexión. Además, en la caché `database` de producción eso multiplicaría las filas muertas (2 por conexión). Pasar a un cubo por persona tenía a su vez una trampa, que también quedó cerrada: si el limitador atribuyera a su dueño cualquier vale descifrable, quien repitiera vales viejos del log le agotaría el cupo y su canal pasaría a sondeo. Por eso solo cuenta como de esa persona un vale que todavía se puede canjear.
5. **«La voz del H8» no usa vales.** No hay ruta de voz que los acepte (`grep -i ticket routes/voice.php` y los controladores de voz y telefonía: nada). Su limitador va por persona y el laboratorio hablado usa la URL firmada de ElevenLabs. No había compatibilidad que mantener ahí.
6. **El único cliente del vale es el CRM.** `LocalGym.App` (lector de la sede, C#) descarga `face-image` con `Authorization: Bearer` (`CrmApiClient.cs:83`, `:371`) y no abre SSE; la app móvil (`Iron_Body_App/lib`) no usa vales; n8n usa el token compartido, que no obtiene vales (403). El cambio no les afecta.

## 2. La corrección

### 2.1 Backend

| Fichero:línea | Cambio |
|---|---|
| `app/Services/Admin/StreamTicketService.php:52` | `TTL_SECONDS = 60`. |
| `…StreamTicketService.php:55-60` | Finalidades `stream` y `face-image` (`SCOPES`). |
| `…StreamTicketService.php:75-97` (`issue`) | El vale cifrado lleva `{v: 2, sid, scope, jti, exp}`; `jti` = `Str::random(40)`. Se anota en caché `admin-stream-ticket:<sha256(jti)>` → finalidad, hasta `exp` (`:84`). El `jti` nunca se guarda en claro. |
| `…StreamTicketService.php:107-122` (`redeem`) | Descifra y valida la forma → finalidad igual a la de la ruta (si no coincide, rechaza **sin gastar** el vale) → `exp` → **canje atómico** → sesión activa. Nunca lanza. |
| `…StreamTicketService.php:180-195` (`consume`) | `Cache::lock("<clave>:redeem", 10)->get(fn () => Cache::pull(<clave>))`: solo quien toma el cerrojo y encuentra la anotación gana. En `database` el cerrojo es un INSERT sobre la clave primaria de `cache_locks`. Si el almacén falla, rechaza y anota `auth:admin:ticket_store_failed` (sin el vale). La anotación y el cerrojo desaparecen al canjear. |
| `…StreamTicketService.php:162-169` (`scopeFor`) | La finalidad la decide la RUTA: `*/face-image/*` → `face-image`; `*/stream` → `stream`; nada más admite vale. |
| `…StreamTicketService.php` (`peekSessionId`) | Devuelve el `sid` de un vale que **todavía se puede canjear** (vigente y con su anotación), sin canjearlo, solo para agrupar en el limitador. Un vale gastado da null: quien repite el del access.log no le agota el cupo a su dueño. No autoriza nada. |
| `app/Http/Middleware/EnsureAdminAuth.php:36`, `:68-88` | Pide la finalidad a `scopeFor`, canjea con `redeem($ticket, $scope)` y marca la petición con la huella del vale (`auth_admin_ticket`, atributo del servidor): las pasadas siguientes de la guarda **sobre la misma petición** no vuelven a canjearlo. Otra petición con el mismo vale: 403 `admin_ticket_invalid`. |
| `app/Http/Controllers/Api/Admin/StreamTicketController.php:43-54` | `GET admin/stream-ticket?scope=stream\|face-image`; sin `scope`, `stream` (lo que pedía el CRM anterior); otra finalidad → 422 `ticket_scope_invalid`. Respuesta con `Referrer-Policy: no-referrer`; `Cache-Control: no-store, private` ya lo ponía `NoStoreAdminResponses` en todo `/api/admin`. |
| `app/Http/Middleware/SecurityHeaders.php:48-60` | Si la petición trae un vale en la URL de una ruta que lo admite: `Cache-Control: no-store, private` y `Referrer-Policy: no-referrer`, sustituyendo los de la ruta. La foto pedida por vale deja de quedarse una hora en disco; pedida por cabecera (`LocalGym.App`) sigue igual. |
| `app/Services/UltronStudio/Stream/StreamRateLimit.php` (`key`) | El cubo del limitador pasa a ser la **persona** de la sesión del vale (`studio-stream:admin:<id>`, el mismo cubo que con cabecera, como en los limitadores de voz). Un vale ilegible, caducado o gastado va al cubo anónimo de la red de quien lo manda. Se lee con `peekSessionId`, sin gastar el vale. |

### 2.2 CRM

| Fichero | Cambio |
|---|---|
| `src/app/services/auth.service.ts` | Se retira el vale guardado (`ensureStreamTicket`, `withStreamToken` y el caché por pestaña). `requestStreamTicket(scope)` pide un vale **nuevo** en cada llamada, con `cache: 'no-store'`, sin compartir uno en vuelo. Un contador de generación descarta un vale que llegue después de cerrar sesión. `withTicket(url, ticket)` es pura. `openStream` pide su vale en **cada** conexión y reconexión, con el mismo retroceso (1 → 30 s). Se quitan los dos «calentamientos» (constructor y `setSession`): un vale pedido por adelantado no lo usaría nadie. `clearStreamTicket()` se conserva (cierre de sesión y specs). |
| `src/app/services/studio-stream.service.ts` | `conectar()` pide un tique `stream` nuevo por conexión; retroceso, rechazos y sondeo de respaldo, sin cambios. |
| `src/app/services/face-recognition.service.ts` | `loadImage()` pide un vale `face-image` nuevo por foto. |
| `src/app/shared/services/moderation-realtime.service.ts` | `connect()` espera su vale; un contador de generación impide abrir dos `EventSource` si entre tanto hubo `stop()` o `reconnectNow()`. |

## 3. La demostración

### 3.1 Pruebas del backend

PENDIENTE-BACKEND

### 3.2 La carrera: dos canjes a la vez, gana uno

`UltronStudioStreamTicketTest::test_dos_canjes_simultaneos_del_mismo_vale_solo_uno_gana`: **8 procesos** (`pcntl_fork`) con el **mismo** vale salen a la vez de una barrera y lo canjean contra un almacén que comparten de verdad. Hay dos conjuntos de datos: `database` (el de producción) sobre un SQLite en fichero, con `busy_timeout` 10 s, y `file`.

| Variante | Ganadores de 8 |
|---|---|
| Código entregado, `database` | **1** (los otros 7 pierden sin error); `cache` y `cache_locks` quedan con **0 filas** |
| Código entregado, `file` | **1** |
| Mutante «leer, esperar 20 ms, borrar», `database` | 2 |
| Mutante «leer, esperar 20 ms, borrar», `file` | 8 |
| Mutante `Cache::pull` sin cerrojo (la recomendación original), 3 corridas | 2, 3 y 4 (cayó en las tres corridas) |

### 3.3 Mutaciones

PENDIENTE-MUTACIONES

### 3.4 CRM

PENDIENTE-CRM

## 4. Impacto en operación

- **Nada que configurar**: ni variables, ni migraciones, ni cambios de nginx. Usa la caché por defecto (`CACHE_STORE=database` en producción) y sus tablas `cache` y `cache_locks`, que crea `0001_01_01_000001_create_cache_table.php`. `Cache::lock` ya se usa en producción (OTP, trabajos de marketing).
- **Orden de despliegue: primero el CRM, luego el backend.** El CRM nuevo funciona con el backend viejo: pide un vale por conexión y por foto, y el backend viejo ignora `scope` y acepta sus vales. Al revés no: una pestaña con el CRM viejo, tras desplegar el backend, reparte un vale de un solo uso entre varios streams (gana el primero; los demás reintentan con retroceso y se recuperan a trompicones) y **sus fotos fallan** (vale de `stream` en `face-image`): el reconocimiento facial de esa pestaña queda sin catálogo hasta recargarla. **Hay que recargar las pestañas de recepción y del punto facial después de desplegar el CRM y antes de desplegar el backend.** El CRM ayuda: `VersionWatcherService` lee `/version.json` cada 5 minutos, avisa de la versión nueva y recarga en la siguiente navegación. Pero una pestaña que no navega (el punto facial) se queda con el aviso hasta que alguien pulse «recargar». Es una deducción del código, no se probó de punta a punta (§5).
- **Peticiones de más**: una `GET admin/stream-ticket` por cada conexión SSE y por cada foto. Los streams genéricos cierran a los 20 s (`app/Support/SseStream.php:26`) y el CRM reabre al segundo: ≈ 3 vales por minuto y stream abierto; el del Studio cierra a los 55 s: ≈ 1 por minuto. Antes, uno cada ~4 minutos por pestaña. Reconstruir el catálogo facial (al abrir Asistencia, al enrolar o borrar un rostro, o con «Actualizar rostros») pasa de N a 2N peticiones con N socios enrolados. La emisión no tiene limitador.
- **Caché `database`**: emitir = 1 INSERT en `cache`; canjear = INSERT y DELETE en `cache_locks` y SELECT y DELETE en `cache`. Un vale canjeado no deja filas. Un vale emitido y nunca canjeado (pestaña cerrada entre pedirlo y usarlo, un 502, el 429 del limitador del Studio) deja una fila caducada: Laravel no purga la tabla `cache` por su cuenta y el proyecto no lo programa. El limitador por persona deja de crear un cubo por vale, que hasta ahora eran dos filas por vale usado en el Studio.
- **Limitador del Studio**: 30 conexiones/min **por persona** (todas sus pestañas y sesiones), con 403 si se repite un vale gastado y 429 si se insiste. Una pestaña abre ≈ 1/min: hacen falta unas 28 pestañas a la vez para tocar el tope; si se toca, el canal pasa a sondeo y reintenta con calma.
- **Vales antiguos**: los `{sid, exp}` que estén en el access.log de hoy dejan de valer al desplegar (`v` ≠ 2, sin finalidad ni `jti`).
- **Registro**: los rechazos siguen en `auth:admin:failed` con `reason: admin_ticket_invalid` (sin el vale). Es nuevo `auth:admin:ticket_store_failed` (solo la clase del error) si el almacén de caché falla durante un canje.

## 5. Lo no verificado

- **PostgreSQL como almacén de la caché.** La carrera corrió sobre `database` en SQLite de fichero y sobre `file`; no hay base de pruebas en PostgreSQL (el usuario `ironbody` no puede crear bases, `phpunit.xml`). En PostgreSQL la atomicidad descansa en el mismo INSERT sobre la clave primaria de `cache_locks` (`DatabaseLock::acquire`). Redis no se probó.
- **Navegador real y nginx real.** Las specs usan `EventSource` e `Image` simulados (jsdom). Que nginx escriba la línea del access.log al **terminar** la petición (y, por tanto, que un vale que aparece en el log ya esté gastado) es el comportamiento documentado de nginx, no algo medido aquí.
- **Las pestañas con el CRM viejo** tras desplegar el backend (§4): razonado, no ejecutado.
- **La carga real** de las peticiones de vale añadidas, en particular la ráfaga del catálogo facial con el pool de 24 trabajadores.
- **Riesgo residual.** Un vale que no llega a la guarda (el 429 del limitador del Studio, que va antes que la puerta; un 503 de mantenimiento; un 502 o 504 de nginx; un 499) aparece en el log sin gastar y vale hasta su minuto. Recomendación para devops: no registrar la query de `*/stream` y `*/face-image/*` en nginx.
- **Mejora opcional** (no hecha): atar el vale de la foto al `user_id` concreto. Hoy un vale de `face-image` sin gastar abre una foto cualquiera, una sola vez y durante su minuto.
- **Decisiones para el lead:** (1) el limitador `studio-stream` pasa de «por tique» (texto del contrato SSE9) a «por persona», porque con un solo uso el cubo por tique no frenaba nada; (2) el orden de despliegue CRM → backend.

PENDIENTE-SUITES
