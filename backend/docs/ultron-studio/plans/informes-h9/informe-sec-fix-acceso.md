# SEC-FIX · Acceso: SC-4, SC-6 y SC-7 de la revisión de seguridad del cierre

**Agente:** `backend` (sec-fix-acceso), con la skill `api-security` · **Fecha:** 10-11-2026 · **Árbol:** backend `_worktrees/ultron-h8/Iron-Body/backend` y CRM `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, sin commit.
**Modo:** local. Sin commit, push, despliegue, producción ni red real. No imprimí secretos. La base PG desechable `ultron_studio_secacc` se creó para las pruebas y se borró al terminar.
**Entrada:** `informe-sec-cierre.md` (SC-4, SC-6, SC-7 y §3) y la fila L9-78 del contrato.

---

## 0. Resumen

| Hallazgo | Estado | Dónde | Pruebas nuevas |
|---|---|---|---|
| **SC-4** · publicar con voz no exigía la autoridad de voz | **Corregido.** Publicar, promover o reanudar algo con un paso `voice_call` exige además `telephony.call_place`. Si falta, 403 `voice_authority_required`. No hay permiso nuevo. WhatsApp y lo asistido siguen igual | `StudioStrategyPublishing.php`, `PermissionCatalog.php` (solo esa descripción) | `UltronStudioStrategyVoiceAuthorityTest` (10) |
| **Cuatro ojos en el texto de la llave** | **Corregido el texto.** El código y el contrato (D9-11.2 y T1) coinciden: la autora no publica ni promueve. El texto «quien aprueba no publica» era falso | `PermissionCatalog.php:257` | Comprobado por `test_la_llave_de_publicar_dice_lo_que_exige` |
| **SC-6** · la finalidad del vale salía del camino | **Corregido.** Ahora la decide la RUTA que atiende la petición, según una lista cerrada de `uri()`, y solo con GET | `StreamTicketService.php` (`ROUTES`, `scopeFor()`), comentario de `EnsureAdminAuth.php` | `StreamTicketRouteScopeTest` (6) |
| **SC-7** · sin limitador y sin purga | **Corregido.** Hay un cupo por persona y finalidad en el controlador (no toqué `routes/api.php`). La orden `admin:prune-stream-tickets` purga las anotaciones caducadas. **Sin programar:** eso le toca al lead | `StreamTicketController.php`, `StreamTicketService.php`, `PruneStreamTicketsCommand.php` (nuevo) | `StreamTicketIssueRateLimitTest` (5), `PruneStreamTicketsCommandTest` (3) |

**Me aparté del encargo literal en SC-6 (lo explico en §2.2).** El encargo dice que el vale `stream` autentique solo «las rutas EXACTAS del canal en vivo del Studio». El CRM, en cambio, abre **11** canales SSE con ese mismo vale: notificaciones, usuarios, clases, caja, ejercicios, agente y agenda de mercadeo, membresías, moderación, asistencias y el Studio. Dejar solo el del Studio habría apagado los otros diez en producción. La lista cerrada contiene exactamente esos 11, ninguna ruta más, y una prueba la compara con el enrutador.

---

## 1. SC-4 · Lo que llama exige la autoridad de llamar

### 1.1 Qué cambió (`app/Services/UltronStudio/StudioStrategyPublishing.php`, +126/−1; antes no tenía cambios)

- **Constantes:**
  - `VOICE_AUTHORITY = CrmPermission::TELEPHONY_CALL_PLACE`. Es la misma llave con la que se pide una llamada a mano (D8-22); no hay permiso nuevo.
  - `CODE_VOICE_AUTHORITY_REQUIRED = 'voice_authority_required'`.
- **`assertVoiceAuthority()`** (privado). Recorre las versiones que la acción deja vivas. Si alguna tiene un paso `voice_call` y quien actúa no tiene `telephony.call_place`:
  - anota el intento en el libro con `recordDenied`. Es una fila por persona, sujeto y evento cada 10 minutos, igual que `four_eyes_denied`. El evento es `strategy_version.voice_authority_denied` cuando se publica o promueve, y `strategy.voice_authority_denied` cuando se reanuda;
  - lanza un **403** con este cuerpo:
    ```json
    {"ok": false, "code": "voice_authority_required",
     "message": "Esta estrategia hace llamadas automáticas: publicarla, promoverla o reanudarla exige además el permiso «Pedir llamadas salientes y colgar».",
     "required_permission": "telephony.call_place"}
    ```
    `required_permission` sigue la misma forma que `UltronStudioException::forbidden()`.
- **`hasVoiceStep(?StudioStrategyVersion)`** (público y estático). Lee los bytes guardados de `sequence[*].channels` y busca `voice_call`. Si no puede leerlos, cuenta como si la versión llamara (**falla cerrado**), en estos casos:
  - la versión no aparece (`null`);
  - los bytes no casan con `content_hash`;
  - el contenido no es JSON;
  - no tiene `sequence`;
  - algún paso no tiene `channels`.

  Lo peor que puede pasar así es pedir una llave de más. `assisted:voice` **no** cuenta: en lo asistido llama una persona con su propia llave.
- **Dónde va:**
  - en `publish()` y `promote()`, justo después de `assertNotAuthor()` (los cuatro ojos) y **antes de la ventana**;
  - en `resume()`, después de `requirePerson()`. Las versiones que se comprueban son las de las partes `canary` y `rest` del estado de la cadena (`liveVersions()`). Para tenerlas, `stateOf()` se lee **antes** de `assertWindow()`; antes iba justo después. Es solo lectura y no cambia nada más.
  - Decisión: **la autoridad va antes que el reloj.** Quien no puede llamar tiene que saber que no puede, no que vuelva dentro del horario. Va después de los cuatro ojos para no alterar el código que ya ve la autora (`four_eyes_required`). La comprobación ocurre antes de medir la evidencia del canario.
- **Sin cambios:** la cadena, la transacción, la idempotencia, la pausa y la reversión. Pausar y revertir siguen sin pedir nada nuevo, porque apagan.

### 1.2 Cuatro ojos: qué regla manda

- **Contrato D9-11.2:** «aprueba alguien distinto de la autora; publica alguien distinto de la autora; promueve alguien distinto de la autora (la decisión 6 del H5: basta con ser distinto de la autora)». La T1 dice lo mismo: «La autora no aprueba, publica ni promueve».
- **Código:** `assertNotAuthor()` en publicar y promover. Compara la cuenta **y** la persona (`StudioPersonBindings::samePerson`).
- **Coinciden.** El texto de la llave («quien aprueba no publica») era el único que se apartaba, así que corregí el texto y no la regla.

`app/Support/Access/PermissionCatalog.php:257` (−1/+1, la única línea que cambió en el fichero):

```
- 'Publicar una versión aprobada de estrategia en canario, promoverla y reanudarla. Exige la bandera de publicación, la ventana horaria y los cuatro ojos: quien aprueba no publica.'
+ 'Publicar una versión aprobada de estrategia en canario, promoverla y reanudarla. Exige la bandera de publicación y la ventana horaria; publicar y promover, además, los cuatro ojos: nadie publica ni promueve una versión que creó, tampoco con otra cuenta suya. Si la estrategia hace llamadas automáticas (un paso de voz), exige también el permiso «Pedir llamadas salientes y colgar».'
```

«Pedir llamadas salientes y colgar» es la etiqueta con que la pantalla de Roles muestra `telephony.call_place`. Una prueba comprueba que el texto la nombra tal cual. Reanudar no tiene cuatro ojos en el código (exige una persona verificada, la bandera y la ventana), y el texto ya no dice que los tenga.

### 1.3 El catálogo del CRM

Corrí `php artisan permissions:export --path=…/Iron-Body_Front/src/app/models/backend-permission-catalog.ts` y salieron **121 permisos, sin diff**. Primero lo exporté al scratchpad y `diff -u` dijo «IDENTICO». El exportador solo vuelca las llaves y el reparto por rol, no las descripciones; estas llegan al CRM por la API de Roles (`RolePermissionController` → `PermissionCatalog::rows()`).

### 1.4 Lo que NO cambié y propongo (no son ficheros míos)

1. **Los botones del CRM.** `StudioStrategyVersions::actions()` (`app/Services/UltronStudio/StudioStrategyVersions.php:348-363`) sigue marcando `publish` y `promote` como permitidos para quien no tiene la llave de llamar. Al pulsar, el servidor devuelve el 403 y el CRM enseña su mensaje en español: `toCaApiError` conserva `message` cuando no conoce el código y `textoError(null, message)` lo muestra. Lo verifiqué leyendo el código, no en un navegador. Propuesta, en `$publicar` y `$promover`, después de `$propia => $no('four_eyes_required')`:
   ```php
   StudioStrategyPublishing::hasVoiceStep($v) && ! $access->can(StudioStrategyPublishing::VOICE_AUTHORITY) => $no(StudioStrategyPublishing::CODE_VOICE_AUTHORITY_REQUIRED),
   ```
   Hay que hacer lo mismo en `resume` de `StudioStrategies.php:246`. Ese fichero lo tiene modificado otro agente, y para resolverlo necesita las versiones vivas: habría que hacer público `liveVersions()` o añadir un ayudante. En el CRM habría que sumar `voice_authority_required` a `CA_ERROR_CODES` (`commercial-automation-api.types.ts:1037`) y su frase a `STATE_409_TEXTS` (`commercial-automation-text.ts`).
2. **Comentario del CRM:** `src/app/models/permissions.enum.ts:351` dice «(cuatro ojos: quien aprueba no publica)». Es falso por la misma razón que el texto de la llave.
3. **Documentos que quedan resueltos:**
   - `ULTRON_STUDIO_H9_COMPLETITUD.md:126` y `informe-doc9.md:84` (punto 8) describen el desajuste del texto;
   - la tabla de D9-13 podría decir: «`ultron.strategies_publish` · Publicar en canario, promover y reanudar; si la versión tiene un paso `voice_call`, además `telephony.call_place` (SC-4)».
4. **Eventos nuevos del libro:** `strategy_version.voice_authority_denied` y `strategy.voice_authority_denied`. El CHECK del libro solo restringe `subject_type` (`strategy_version` y `strategy` ya existían), así que no hace falta migración. Si la vista de auditoría del CRM no los traduce, saldrán con su nombre.

---

## 2. SC-6 · La finalidad del vale la decide la ruta

### 2.1 Qué cambió

`app/Services/Admin/StreamTicketService.php`. El fichero ya traía los cambios de P9-25; los míos son estos:

- **`ROUTES`** (constante pública) es la lista cerrada de `uri()` de ruta por finalidad:
  - `stream`: `api/admin/{classes,earnings,exercises,marketing/agent,marketing/appointments,memberships,moderation,notifications,ultron-studio,users}/stream` y `api/attendances/stream` (11 en total);
  - `face-image`: `api/attendances/face-image/{userId}` (1).
- **`scopeFor()`**:
  - responde `null` si el método no es GET. Comprueba el método **real** (`getRealMethod()`) y además `isMethod('GET')`, así que HEAD, POST y un POST con `_method=GET` o `X-HTTP-Method-Override` no se autentican con un vale;
  - después toma el `uri()` de la ruta y lo busca en `ROUTES`.
- **`routeUri()`** (privado):
  - si `$request->route()` está puesta, devuelve su `uri()`. Siempre lo está en las guardas, que corren después del enrutado (grupo `api` y alias `auth.admin`), y en `SecurityHeaders`, que llama después de `$next()`;
  - si no lo está, resuelve la ruta con el **mismo** enrutador (`router->getRoutes()->match()`). Es el caso de las pruebas que llaman a la guarda a mano, como `AdminAuthTest`;
  - un 404 o un 405 (`HttpExceptionInterface`) se traga **a propósito**: sin ruta no hay vale (falla cerrado), y el comentario lo dice.
- **Por qué la ruta y no el camino:** con el camino, dos rutas que casan con el mismo texto podrían recibir la finalidad de la otra. Con la ruta, la finalidad va atada a lo que de verdad se ejecuta.

`app/Http/Middleware/EnsureAdminAuth.php`: solo cambió el comentario del bloque del vale (rutas exactas, solo GET, «en una ruta que no lo admite ni se mira: no se gasta»). La lógica no cambia: sigue llamando a `scopeFor()` y canjeando solo si hay finalidad.

### 2.2 Por qué la lista tiene 11 canales y no solo el del Studio

En el CRM, `openStream()` (`auth.service.ts:152`) pide **siempre** `requestStreamTicket('stream')` y lo usan todos estos (grep sobre `src/app`):

| Fichero del CRM | Canal |
|---|---|
| `notifications.service.ts`, `users-list.ts`, `trainers.ts`, `face-point.ts`, `weekly-streak.ts`, `support.ts`, `attendance.ts` | `admin/notifications/stream` |
| `attendance.ts` | `attendances/stream` |
| `exercises.ts` | `admin/exercises/stream` |
| `financial-stream.service.ts`, `finanzas.ts` | `admin/earnings/stream` |
| `settings-role-catalog.ts` | `admin/users/stream` |
| `classes-stream.service.ts` | `admin/classes/stream` |
| `membership-stream.service.ts` | `admin/memberships/stream` |
| `marketing-agent.service.ts` | `admin/marketing/agent/stream` |
| `marketing-appointments.service.ts` | `admin/marketing/appointments/stream` |
| `moderation-realtime.service.ts` (con `withTicket`) | `admin/moderation/stream` |
| `studio-stream.service.ts` (con `withTicket`) | `admin/ultron-studio/stream` |

Si solo quedara la ruta del Studio, los otros diez canales recibirían 401 y el CRM dejaría de actualizarse en vivo hasta recargar. Si el lead quiere de verdad un vale exclusivo del Studio, hace falta una finalidad nueva (`studio-stream`) y un cambio en el CRM. Eso cae fuera de este encargo y no lo recomiendo: el riesgo que cierra SC-6 es la ruta que se parece por un parámetro, y eso ya está resuelto.

### 2.3 Efectos de comportamiento

1. Un vale ya **no** autentica (401 `admin_token_required`, y el vale **no se gasta**) en:
   - `GET /api/admin/marketing/analytics/breakdown/stream` (ruta `breakdown/{dimension}`);
   - `GET /api/admin/receivables/people/face-image/1` (`people/{type}/{id}`);
   - `GET /api/admin/members/face-image/identity/front` (`members/{member}/identity/{side}`, las fotos del documento de identidad). Por HTTP esta responde 404, porque el enlace de `{member}` corre antes que la puerta (PX-2); la guarda, llamada sobre esa ruta, da 401.
2. HEAD, y POST disfrazado de GET, tampoco se autentican con un vale. Ningún cliente lo hacía: `EventSource` y `<img>` solo piden GET.
3. `SecurityHeaders` solo pone `no-store`/`no-referrer` donde el vale aplica. Una respuesta 404 o 401 de un camino que no lo admite ya no los lleva. En `/api/admin` los pone igualmente `NoStoreAdminResponses`, y esas respuestas no tienen contenido sensible.
4. Si el CRM abre un canal SSE nuevo con vale, hay que añadirlo a `ROUTES`. Si no, se rechaza y la prueba del enrutador avisa (§2.4).

### 2.4 Pruebas (`tests/Feature/Security/StreamTicketRouteScopeTest.php`, 6 pruebas)

- **La sonda del informe, llamando a la guarda:** los tres caminos dan 401 y el vale sigue abriendo después su canal o su foto.
- **La sonda por HTTP:** 401, y 404 en el caso de `members/{member}` (nunca 200). El vale sin gastar abre su canal o su foto, y se compara la foto byte a byte.
- **Solo GET:** con HEAD y con POST más `X-HTTP-Method-Override: GET` no se autentica, y luego el GET sí entra. Por HTTP, HEAD y POST con `_method=GET` dan 401.
- **Cada ruta de la lista** acepta el vale de su finalidad y rechaza el de la otra con 403, sin gastarlo.
- **La guarda del enrutador:** cada `uri()` de `ROUTES` existe, es solo GET/HEAD, pasa por `EnsureAdminAuth` (vive bajo `api/admin/` o lleva `auth.admin`) y su acción es `stream` o `faceImage`. Además, el conjunto de canales SSE protegidos del enrutador es **igual** a la lista, y las rutas de fotos también.

---

## 3. SC-7 · Limitador por persona y purga

### 3.1 La ráfaga medida en el CRM (código, no suposición)

| Quién pide vales | Cuántos | Dónde |
|---|---|---|
| Un canal SSE genérico | ≈ 3 por minuto y canal abierto. El servidor cierra a los 20 s (`SseStream::response`, `maxSeconds = 20`) y `openStream()` reabre al segundo con un vale nuevo | `app/Support/SseStream.php:26`, `auth.service.ts:152-213` |
| El canal del Studio | ≈ 1 por minuto (cierra a los 55 s) | `informe-p9-25.md` §5 |
| **El catálogo facial** | **N vales a la vez por cada reconstrucción, con N = socios con foto.** `buildReferences()` hace `Promise.all(refs.map(…))` y cada `loadImage()` pide su vale `face-image`. Se reconstruye al abrir Asistencia, tras cada rostro enrolado o borrado (lo avisa el canal de notificaciones, con 800 ms de espera) y con «Actualizar rostros». Otra pantalla con la misma cuenta lo repite | `face-recognition.service.ts:82-107`, `:171-182`; `attendance.ts:2999-3027`, `:3185-3198`. La spec `face-recognition.service.spec.ts:71-75` fija «una foto, un vale, a la vez» (3 fotos dan 3 peticiones `?scope=face-image`) |

`AttendanceController::faceReferences()` devuelve todos los socios con fila en `member_biometrics` (es única por socio) y usuario. El docblock dice «solo activos», pero el código no filtra por estado. **No pude contar N en producción** (§6).

### 3.2 El limitador (`app/Http/Controllers/Api/Admin/StreamTicketController.php` y `StreamTicketService.php`)

- **Dónde:** en el controlador, después del 403 del token compartido y del 422 de una finalidad desconocida (que no gasta cupo), y antes de anotar nada en la caché. `routes/api.php` no se tocó.
- **Cubo:** `admin-stream-ticket:rate:<finalidad>:<admin_id>`, uno por **persona** (todas sus sesiones, pestañas y equipos) y por **finalidad**. Así la ráfaga del catálogo no deja sin vales a los canales, ni al revés.
- **Cupos por minuto:**
  - `stream`: **600** fijos (`STREAM_PER_MINUTE`). Equivalen a unos 200 canales abiertos a la vez por la misma cuenta;
  - `face-image`: **max(1 000, 8 × N)** (`FACE_IMAGE_FLOOR_PER_MINUTE`, `FACE_IMAGE_REBUILDS_PER_MINUTE`). N es `MemberBiometric::count()`, guardado 10 minutos en la caché (`admin-stream-ticket:faces`, una consulta como mucho cada 10 minutos). Cuenta de más, nunca de menos, frente al catálogo.
  - Por qué el cupo de fotos sigue al catálogo: N no lo conozco y crece. Un cupo fijo rompería el reconocimiento facial el día que N × reconstrucciones pasara del techo; con este, cabe por construcción. «8» deja sitio a dos pantallas de la misma cuenta con hasta 4 reconstrucciones por minuto cada una.
- **Pasado el cupo:** 429 con `Retry-After` y el cuerpo uniforme del Studio:
  ```json
  {"ok": false, "code": "too_many_requests", "message": "Demasiados vales seguidos. El CRM vuelve a intentarlo solo.", "retry_after": <s>}
  ```
  Sin anotar nada. El CRM trata cualquier respuesta que no sea `ok` como «sin vale»: el canal reintenta con espera creciente (1 s → 30 s) y la foto cuenta como fallida.
- **Atomicidad:** se usa `tooManyAttempts` y después `hit`, el mismo patrón que `ThrottleRequests` de Laravel. Bajo concurrencia puede pasarse del cupo en unas pocas peticiones (como mucho los 24 trabajadores de FPM). Para un limitador holgado es aceptable.
- **Coste:** con la caché `database`, cada vale suma unas 5 o 6 consultas (contador, temporizador y cuenta de fotos) a la que ya hacía (`Cache::put`). Con N grande la ráfaga del catálogo pesa más; no lo medí (§6).

### 3.3 La purga: `admin:prune-stream-tickets` (nueva, `app/Console/Commands/PruneStreamTicketsCommand.php`)

- **Qué borra:** solo las claves `admin-stream-ticket:*` **caducadas** (`expiration <= ahora`), es decir, las anotaciones de vales que nadie canjeó, los cubos del limitador y la cuenta de fotos. Ninguna clave viva y nada más de la caché.
- **Cómo:** reutiliza `App\Support\Cache\ExpiredCachePruner`, la misma pieza de `voice:prune-rate-limits` (P8-3, ya revisada). Usa LIKE con escape, borra en tandas de 1 000 y vuelve a exigir la caducidad en cada borrado.
- **Opciones:** `--dry-run` solo cuenta; `--json` saca el resumen; con otro almacén (redis, array…) responde «nada que purgar» y sale con 0.
- **Falla en alto:** los vales van al almacén **por defecto** y el purgador mira el del **limitador**. Si alguien separa los dos (`cache.limiter` ≠ `cache.default`), la orden lo dice, no borra nada y sale con **1**.
- **Registro:** `Log::info('auth:admin:stream_tickets_pruned', {store, supported, dry_run, expired, deleted})`. Sin claves ni vales.
- **`cache_locks` no hace falta purgarla:** el cerrojo del canje se suelta al salir, y `DatabaseLock` ya poda solo los caducados (por sorteo, 2 de cada 100).
- **Frecuencia propuesta (no la programé):** cada **10 minutos**, en el bloque general de `routes/console.php`, sin bandera, porque el vale sirve a todo el CRM:
  ```php
  // SC-7: las anotaciones caducadas de los vales de streams y fotos (y su limitador) en la caché database.
  Schedule::command('admin:prune-stream-tickets')
      ->everyTenMinutes()
      ->withoutOverlapping()
      ->onOneServer();
  ```
  - Por qué cada 10 minutos: un vale vive 60 s. Con el limitador, lo que como mucho se acumula entre dos purgas es unas 10 veces el cupo por minuto de una persona abusiva. Cada pasada es un recorrido corto de `cache` y los borrados son por tandas. Cada hora también sirve: la cota sería 6 veces mayor.
  - **Ojo al programarla:** `docs/ultron-studio/ops/deploy-h9-backend-cierre.sh:164` fija `TAREAS_NUEVAS_SIEMPRE=0` y `:686-690` exige el mismo total del programador antes y después. Una tarea sin bandera la convierte en **1**. También cambia el total que cita `RUNBOOK_H9.md:218-224` (39). Eso lo cuadra quien sea dueño del guion (OPS).

---

## 4. Diff por fichero

| Fichero | Cambio | Tipo |
|---|---|---|
| `app/Services/UltronStudio/StudioStrategyPublishing.php` | +126/−1 (todo mío): importa `OutreachCatalog`, `CrmPermission` y `JsonException`; añade las constantes `VOICE_AUTHORITY` y `CODE_VOICE_AUTHORITY_REQUIRED`, la llamada en `publish`, `promote` y `resume`, `assertVoiceAuthority()`, `hasVoiceStep()` y `liveVersions()`; y el docblock de la clase | SC-4 |
| `app/Support/Access/PermissionCatalog.php` | −1/+1 (la descripción de `ultron.strategies_publish`) | SC-4 |
| `app/Services/Admin/StreamTicketService.php` | Mío, sobre lo de P9-25: importa `MemberBiometric`, `Route` y `HttpExceptionInterface`; añade `ROUTES`, los cupos (`STREAM_PER_MINUTE`, `FACE_IMAGE_*`, `RATE_WINDOW_SECONDS`), `CACHE_PREFIX` (privado → **público**, documentado) y `FACES_COUNT_TTL_SECONDS`; reescribe `scopeFor()`; añade `routeUri()`, `perMinute()`, `rateKey()` y `enrolledFaces()`; ajusta el punto 3 del docblock | SC-6 y SC-7 |
| `app/Http/Controllers/Api/Admin/StreamTicketController.php` | Mío, sobre lo de P9-25: el limitador (cubo, cupo, 429 con `Retry-After`) y el docblock | SC-7 |
| `app/Http/Middleware/EnsureAdminAuth.php` | Solo el comentario del bloque del vale | SC-6 |
| `app/Console/Commands/PruneStreamTicketsCommand.php` | **Nuevo** (73 líneas) | SC-7 |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategyVoiceAuthorityTest.php` | **Nueva** (10 pruebas) | SC-4 |
| `tests/Feature/Security/StreamTicketRouteScopeTest.php` | **Nueva** (6) | SC-6 |
| `tests/Feature/Security/StreamTicketIssueRateLimitTest.php` | **Nueva** (5) | SC-7 |
| `tests/Feature/Security/PruneStreamTicketsCommandTest.php` | **Nueva** (3) | SC-7 |
| CRM `src/app/models/backend-permission-catalog.ts` | Regenerado, **sin diff** | SC-4 |

No toqué `routes/**`, `AuthorizationMap` (no hizo falta), `app/Services/Outreach/**`, Telephony/Voice, las guardas ni ninguna prueba ajena.

**Contrato de la API (consumidores: CRM):**

1. `POST strategies/versions/{uuid}/publish|promote` y `POST strategies/{uuid}/resume` pueden devolver **403 `voice_authority_required`** con `required_permission`.
2. `GET admin/stream-ticket` puede devolver **429 `too_many_requests`** con `retry_after` y `Retry-After`.
3. Un vale ya solo vale en las 12 rutas de `ROUTES` y con GET.

No hay migraciones, variables de entorno ni rutas nuevas. La orden nueva hay que programarla.

---

## 5. Lo que verifiqué ejecutando (comando → resultado)

Todo desde `backend/`, un proceso pesado a la vez, `--no-logging --do-not-cache-result`.

| # | Qué | Resultado |
|---|---|---|
| 1 | SQLite: `phpunit tests/Feature/UltronStudio/Strategies tests/Feature/UltronStudio/Stream tests/Feature/AdminAuthTest.php tests/Feature/MemberFaceSelfEnrollmentTest.php tests/Feature/Attendance/FaceBiometricAccessTest.php tests/Feature/Security/StreamTicketTest.php` + mis 3 de `Security` + `tests/Feature/Members/MembershipRealtimeTest.php` | **OK (170 pruebas, 1 898 aserciones)**. Comprobé con `--list-tests` que entraron las 24 clases: suman 170 |
| 2 | SQLite, permisos, mapa de rutas y guardas: `Access/{RolePermission,SessionPermissions,AuthorizationCoverage}Test`, `UltronStudio{,Integrations}PermissionsTest`, `ModerationPermissionMatrixTest`, `PlanTransferPermissionsTest`, `StoryLivePermissionsTest`, `TrainerRolesPermissionsTest`, `Outreach/Guards/{OutreachRoutesMapGuard,OutreachFourEyesGuard}Test`, `OutreachIsolationTest`, `UltronEngineIsolationTest` | **OK (107 pruebas, 5 191 aserciones)**. Las líneas `[SEC9-a]` de la salida son informativas de las guardas, no fallos |
| 3 | **PG 14** (`createdb ultron_studio_secacc`; `UNIT_DB=ultron_studio_secacc pgunit.sh`), el mismo conjunto que el #1 | **OK (170 pruebas, 1 892 aserciones)**. La diferencia con SQLite son las ramas solo-SQLite de FourEyes |
| 4 | **Mutaciones** (guion `mutate.sh` en el scratchpad; copia, muta, corre y restaura, comprobando con `cmp` que el fichero quedó igual) | **17 de 17 muertas, 17 restauradas.** SC-4 (7): sin la comprobación; reanudar sin ella; promover sin ella; detector que falla abierto; detector que ignora la huella; reloj antes que la autoridad; `assisted:voice` contando como llamada. SC-6 (4): finalidad por camino; cualquier método; sin el método real; ruta con parámetro libre en la lista. SC-7 (4): sin limitador; cubo por sesión; cupo de fotos fijo; un solo cubo para las dos finalidades. Purga (2): sin la comprobación de almacenes; prefijo sin `:` |
| 5 | `vendor/bin/pint --test` sobre los 10 ficheros PHP míos | **passed** |
| 6 | `php artisan auth:coverage --unmapped` | Vacío, exit 0 |
| 7 | `php artisan list admin` y `admin:prune-stream-tickets --help` | La orden se registra con `--dry-run` y `--json` |
| 8 | `permissions:export` al scratchpad y `diff -u` con el del CRM; después, en su ruta | «IDENTICO», 121 permisos; `git diff` del CRM vacío |
| 9 | CRM: `npx ng test --watch=false` con `permission-contract`, `auth.permissions`, `commercial-automation-permissions`, `studio-integrations-unknown-permission`, `studio-lab-unknown-permission`, `studio-stream.service`, `auth.stream-ticket` y `face-recognition.service` | **8 ficheros, 82 specs en verde.** Los avisos NG8107 son de ficheros ajenos |
| 10 | CRM: `npx tsc -p tsconfig.app.json --noEmit` | exit 0 |
| 11 | Pasada final tras el último retoque (solo comentario) de mis 4 ficheros nuevos + `StreamTicketTest`, `AdminAuthTest`, `Stream/*`, `FaceBiometricAccessTest` | **OK (107 pruebas, 1 048 aserciones)** |

---

## 6. Lo que NO verifiqué

- **N en producción**, es decir, cuántos socios con foto hay y por tanto el cupo efectivo de `face-image`, y cuántas anotaciones de vales hay hoy en `cache`. Lectura sugerida, solo lectura:
  ```sql
  SELECT count(*) FROM member_biometrics;
  SELECT count(*) FILTER (WHERE expiration <= extract(epoch FROM now())) AS caducadas, count(*) AS total
    FROM cache WHERE key LIKE '%admin-stream-ticket:%';
  ```
  Si P9-25 no está desplegado, la segunda debería dar 0.
- **La carga real de la ráfaga del catálogo** con N grande sobre los 24 trabajadores de FPM y el limitador en la caché `database`: unas 5 o 6 consultas más por vale y contención sobre una fila por persona. Tampoco lo midió P9-25. Si N pasa de unos cientos, se lo pasaría a `performance-specialist`.
- **Navegador y nginx reales:** que el CRM se recupere bien de un 429 del vale (por código, el canal reintenta y la foto cuenta como fallida). Un 429 en el catálogo mostraría «foto sin rostro nítido», un mensaje engañoso; se lo dejo como mejora no bloqueante a `frontend`.
- **El camino con `route:cache` (`CompiledRouteCollection`)** del respaldo `match()` de `routeUri()`. En producción la ruta siempre está puesta antes de las guardas y de `SecurityHeaders`, así que ese camino no se usa. Las pruebas lo ejercitan con la colección normal.
- **La suite entera de `tests/Feature/Outreach`.** Otros agentes la están editando. Mi cambio no la alcanza: sus estrategias publicadas se firman directamente en `RunWorld`, no pasan por `StudioStrategyPublishing` (grep: ningún uso). Sí corrí las dos guardas de Outreach que tocan el gobierno (#2).
- **Permisos y guardas en PG:** solo en SQLite, como pedía el encargo. En PG corrí lo de vales y publicación.
- **La pantalla del CRM con el 403 nuevo:** lo verifiqué leyendo `toCaApiError` y `textoError`, no en un navegador.
- **SC-8** (no registrar la query en nginx y atar el vale de la foto al `user_id`): fuera de este encargo.

---

## 7. Decisiones pendientes para el lead

1. **Programar `admin:prune-stream-tickets`** (§3.3), con `TAREAS_NUEVAS_SIEMPRE=1` en `deploy-h9-backend-cierre.sh:164` y el total del runbook ajustado.
2. **Confirmar la lista de 11 canales** de SC-6 (§2.2) en lugar de «solo el del Studio».
3. **Alinear los botones y el texto del CRM** con el 403 nuevo (§1.4, puntos 1 y 2). Es UX: el servidor ya lo hace cumplir.
4. **Anotar en el contrato** la enmienda de D9-13 y la fila de este cierre (§1.4, punto 3).

---

## 8. Resumen para el lead

- **SC-4 corregido.** Publicar, promover o reanudar una estrategia con un paso `voice_call` exige además `telephony.call_place`; sin él, 403 `voice_authority_required`, que queda en el libro. La comprobación va antes que la ventana y falla cerrado si no puede leer el contenido. WhatsApp y lo asistido (también `assisted:voice`) siguen igual. El texto de `ultron.strategies_publish` ya dice la verdad: el código y D9-11.2 coinciden en que la autora no publica ni promueve, y «quien aprueba no publica» era falso. El catálogo del CRM se regeneró sin diff, porque el exportador no lleva descripciones.
- **SC-6 corregido.** La finalidad del vale la decide la ruta, según una lista cerrada de `uri()`, y solo GET. Los tres caminos de la sonda dan 401 y el vale no se gasta. **Me aparté del encargo literal:** la lista tiene los 11 canales SSE que el CRM abre con vale, no solo el del Studio, porque si no los otros diez se caen. Una prueba compara la lista con el enrutador.
- **SC-7 corregido.** Hay limitador por persona y finalidad en el controlador: 600 vales por minuto para `stream`, y max(1 000, 8 × fotos) para `face-image`, de modo que sigue al catálogo facial (que pide N vales de golpe). Pasado el cupo, 429 con `Retry-After`. La orden nueva `admin:prune-stream-tickets` purga solo lo caducado; está **sin programar**. Propongo cada 10 minutos, y entonces `TAREAS_NUEVAS_SIEMPRE` pasa de 0 a 1 en el guion del cierre.
- **Cifras:**
  - SQLite: 170/170 y 107/107;
  - PG 14: 170/170;
  - mutaciones: 17/17 muertas;
  - Pint en verde; `auth:coverage` vacío;
  - CRM: 82/82 specs y `tsc` 0.
- **Sin verificar:** N y las filas de `cache` en producción (dejo el SQL), la carga de la ráfaga con N grande, y el navegador y nginx reales.
