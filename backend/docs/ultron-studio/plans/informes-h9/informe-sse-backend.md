# Informe SSE9-b · Canal SSE único de ULTRON Studio (backend)

**Fecha:** 09-11-2026 · **Paquete:** SSE9-b (`backend`) · **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md`, «Paquete SSE9» · **Worktree:** `_worktrees/ultron-h8/Iron-Body/backend` (rama `feat/ultron-studio-h8-voice`) · **Estado:** implementado y probado — suite del canal **32 pruebas / 297 aserciones en verde en SQLite y en PostgreSQL 14** (puerta `pgunit.sh`, base desechable `ultron_studio_h9_sse`), Pint en verde; **sin commit**, sin red, sin tocar producción.

**Decisión L9-43 aplicada:** el canal vive donde viven las cosas del Studio: controlador en `app/Http/Controllers/Crm/UltronStudio/` (la lista blanca de `UltronEngineIsolationTest`) y ruta en un bloque propio al final de `routes/ultron-studio.php`. **`routes/api.php`, `app/Support/Access/AuthorizationMap.php` y el ejemplo de entorno quedan idénticos a HEAD en lo que a este paquete respecta** (van en el commit del Hito 8); los dos bloques que el lead añade en la rama del Hito 9 están en §9. No se cambia ninguna constante ajena: `UltronStudioStreamController::ROUTE_COUNT = 1` (L9-38).

## 1. Qué se entrega

| Fichero | Papel |
|---|---|
| `config/ultron_studio_stream.php` | Bandera `ULTRON_STUDIO_STREAM_ENABLED` (nace apagada; solo `true/1/on/yes` encienden), latido 15 s, máximo 55 s, sondeo 1,5 s, `batch_limit` 50, `retry_ms` 3000, cupo `studio-stream` (30/min por tique, 10/min anónimo), fuentes de incidentes. |
| `app/Services/UltronStudio/Stream/StreamTopic.php` | La interfaz del tema: `name()`, `permission()`, `listRoute()`, `cursor()`, `since(string $cursor, int $limit): array`. |
| `…/Stream/StreamEvent.php` | El evento: cursor + `data` con **lista blanca impuesta en el constructor** (`type`, `id`, `state`, `at`, `hash`, `ref`; escalares ≤ 128 caracteres; `type`, `id`, `at` obligatorios). Ningún adaptador puede colar otra clave. |
| `…/Stream/StreamCursors.php` | El vector de cursores `tema:cursor,tema:cursor` (parse/format), `id()` e `iso()`. |
| `…/Stream/StreamTopics.php` | El registro de los 11 temas del contrato, construido para quien mira (`StreamTopics::for($access)`). |
| `…/Stream/StreamViewerGate.php` | Puerta adicional opcional de un tema (la usa `monitor.incidents` para los roles de IRON GUARD). |
| `…/Stream/StreamCountable.php` | Opcional para los temas por id: `countThrough(cursor)`, el oído de los commits tardíos (ver §2 y §4.8). |
| `…/Stream/StreamPacer.php`, `StreamClock.php`, `SystemStreamClock.php` | El compás: sondeo, latido `: ping`, cierre, `retry:`; reloj inyectable para probar el bucle entero sin esperar. Reutiliza `SseStream::emit/flush` y sus cabeceras. |
| `…/Stream/StreamRateLimit.php` | El limitador con nombre `studio-stream` (por huella del tique, por persona, o por red). |
| `…/Stream/LedgerTopic.php` | `ledger`. |
| `…/Stream/VoiceCallEventsTopic.php`, `VoiceCallsTopic.php`, `VoiceReconcileTopic.php` | `voice.calls`, `voice.reconcile`. |
| `…/Stream/VoiceBrakeTopic.php`, `VoicePublicationsTopic.php` | `voice.brake`, `voice.publications`. |
| `…/Stream/TouchCursorTopic.php`, `VoiceLabTopic.php`, `IncidentsTopic.php` | Base de cursor compuesto para tablas que se actualizan; `voice.lab`, `monitor.incidents`. |
| `…/Stream/PendingTopic.php` | `outreach.runs|jobs|outcomes|brakes`: registrados y vacíos hasta B9-run. |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioStreamController.php` | El canal (`ROUTE_COUNT = 1`). |
| `routes/ultron-studio.php` (bloque propio al final, +14 líneas, y su `use` en orden alfabético, +1: Pint exige el import en vez del nombre cualificado) | `GET admin/ultron-studio/stream`, con el mismo tope de cuerpo que el resto del Studio (uniformidad; es un GET). `routes/api.php` **sin cambios**. |
| `app/Support/Access/AuthorizationMap.php` y ejemplo de entorno | **Sin cambios en este árbol** (L9-43): los bloques literales para la rama del Hito 9 están en §9. |
| `tests/Feature/UltronStudio/Stream/**` | 7 clases de prueba, `StreamTestCase` y `FakeStreamClock`. |

## 2. El canal, tal como quedó

`GET /api/admin/ultron-studio/stream?ticket=<vale>&topics=a,b[&after=a:1,b:2]`

**Cerraduras, en orden:** puerta `ultron.view` (AuthorizationMap, entrada pendiente en la rama del Hito 9: hasta entonces el mapa **deniega** la ruta por no clasificada, y `auth:coverage --unmapped` la lista junto a las 30 de S9, como espera el lead; el token compartido no recibe `ultron.*`: 403) → una **persona** (`StudioAccess::requirePerson`; el vale resuelve a una sesión) → la **bandera** (`409 stream_disabled`; se comprueba después de la puerta para no revelarla a quien no entra) → los **temas**: `topics` obligatorio, sin repetidos, solo nombres del registro (`422` si no); cada tema exige además el permiso del GET de lista de su módulo; el que no se puede leer **se omite** y queda `Log::notice('ultron_studio.stream_topic_denied', {admin_id, topic})` — en el log de la aplicación, **nunca en una tabla** (el canal no escribe; escribir un rechazo por tema y reconexión cada 55 s sería un amplificador contra el libro de solo inserción). Si no queda ningún tema legible: `403 stream_no_topics`.

**Eventos:**
- Preludio `retry: 3000` y `: connected`.
- `event: ready` · `data: {"topics": {"ledger": "123", …}, "resync": <bool>}` · `id: <vector>`: los cursores de partida. `resync: true` si algún tema arranca en la cabeza (primera conexión, tema sin cursor) o con un cursor recortado: el panel relee las API canónicas; **`resync: false` cuando se honraron exactamente todos los cursores que trajo el cliente** (una reconexión limpia tras el cierre de los 55 s no obliga a releer: responde a Q-3 del informe de SSE9-f). En medio de la conexión puede llegar otro `ready` con `{"topics": {"<tema>": "<cursor sin mover>"}, "resync": true}`: un **commit tardío bajo el cursor** de ese tema (ids reservados antes del commit, como en el canal de membresías); el panel de ese tema relee su lista.
- `event: <tema>` · `data: {type, id, state, at[, hash][, ref]}` · `id: <vector de cursores de todos los temas suscritos>`.
- `event: stream.notice` · `data: {type: "topic_unavailable", id: <tema>, state: null, at}`: un tema dejó de poder leerse en esta conexión (excepción al sondear: se anota con `Log::warning('ultron_studio.stream_topic_failed')`, se avisa y se deja de sondear ese tema; el canal sigue).
- Latido `: ping` cada 15 s (comentario, no evento); **cierre a los 55 s**; sondeo cada 1,5 s (un evento llega en < 2 s).

**Por qué el `id` es un vector y no `<tema>:<cursor>` a secas.** `EventSource` recuerda solo el ÚLTIMO `id:` recibido, del tema que sea: con un id por tema, una caída perdería la posición de los demás. El `id` lleva los cursores de todos los temas suscritos (`ledger:12,voice.calls:9`; con un solo tema es exactamente `<tema>:<cursor>`). El cliente lo devuelve por `Last-Event-ID` en una reconexión automática del navegador o, tras el cierre de los 55 s (donde el navegador **no** manda la cabecera), por **`?after=`**, que manda sobre la cabecera. Una cabecera sin forma se ignora (se arranca en la cabeza); un `?after=` sin forma es `422`. Un cursor numérico por delante de la cabeza se recorta a ella (como el canal de membresías).

**Límite por tique:** limitador con nombre `studio-stream`, cubo por huella sha256 del vale (nunca el vale), por persona con cabecera, por red sin credencial; `429 {ok:false, code:"too_many_requests", retry_after}` con `Retry-After`. Cuenta también con la bandera apagada. Se declara como middleware del controlador (`HasMiddleware`) y se registra al declararlo (`StreamRateLimit::register()`, idempotente), porque `AppServiceProvider` no es de este paquete y lo tocan otros; **mover la llamada a `AppServiceProvider::boot()` es una línea** y deja la del controlador en vacío.

**Solo lecturas:** por cursor, con `limit` acotado, columnas explícitas; cero red (`Http::preventStrayRequests()` en todas las pruebas).

## 3. Contrato de `data` por tema

| Tema | Fuente (cursor) | Permiso = GET de lista | `type` | `id` | `state` | `at` | `hash` / `ref` |
|---|---|---|---|---|---|---|---|
| `ledger` | `ultron_studio_audit_events` (id) | `ultron.view` = `GET …/audit` | evento del libro (`version.approved`, `rule.archived`, `voice_publication.published`…) | uuid del sujeto (o su tipo si no tiene: `system`) | `to_status` | `created_at` UTC | `hash` = `content_hash`; `ref` = `subject_type`. Sin `audit.view`, los borradores ajenos no viajan (mismo filtro que el GET). |
| `voice.calls` | `voice_call_events` (id), kinds del ciclo de vida | `telephony.view` = `GET api/admin/voice/calls` | `voice_call.<kind>` | uuid de la llamada (o del evento si no tiene) | estado actual de la llamada | `occurred_at ?? created_at` | `ref` = sentido (`inbound`/`outbound`) |
| `voice.reconcile` | `voice_call_events` (id), kinds `reconciled`, `cost_recorded`, `unknown_provider_call` | `telephony.view` = `GET api/admin/voice/status` | `voice_reconcile.<kind>` | ídem | ídem | ídem | `ref` = sentido |
| `voice.brake` | `voice_brakes` (id) | `ultron.view` = `GET …/voice/brakes` | `voice_brake.open` / `voice_brake.release` | uuid de la fila | motivo (en la suelta, el de la apertura) | `created_at` | `ref` = uuid de la apertura que se suelta |
| `voice.publications` | `ultron_studio_voice_publications` (id) | `ultron.view` = `GET …/voice/publications` | `voice_publication.publish|promote|rollback|off` | uuid de la entrada | parte (`canary`/`rest`) | `created_at` | `hash` = `effective_hash`; `ref` = `seq` |
| `voice.lab` | `ultron_studio_voice_lab_sessions` (`<unix>-<id>` del último toque: `status_changed_at`, `verdict_at`, `updated_at`, `created_at`) | `ultron.view` = `GET …/voice-lab/sessions` | `voice_lab.session` | uuid de la sesión | estado | el toque | `ref` = veredicto o motivo de fin |
| `monitor.incidents` | `incidents` con `source ∈ {ultron_studio, outreach}` (`<unix>-<id>` sobre `updated_at`) | `security.view` = `GET api/admin/iron-guard/incidents` **y** rol ∈ `IronGuardController::ALLOWED_ROLES` | `incident.opened` (abierto o reabierto: una ocurrencia y `open`) / `incident.updated` | id del incidente | estado | el toque | `ref` = clase del fallo (`publication_drift`…) |
| `outreach.*` (4) | pendientes (B9-run) | provisional `ultron.view`, `listRoute()` null | — | — | — | — | responden cabeza `0` y vacío |

Nunca viajan: teléfono, nombre, documento, cuerpo de mensaje, importe, secreto, evidencia, notas, títulos, resúmenes, diff/metadata, actores (ni por id), ip ni user agent. Probado con filas sembradas que los llevan (libro, llamadas, freno, cadena, laboratorio, incidentes).

## 4. Decisiones que conviene conocer

1. **`monitor.incidents` lee la fila, no `incident_events`.** `IncidentRecorder::increment()` solo actualiza la fila (ocurrencias, `last_seen_at`, gravedad) y **no deja evento**; una cola sobre `incident_events` no vería las repeticiones, que es lo que un monitor necesita. Se lee `incidents` con cursor compuesto sobre `updated_at`, que Eloquent sube al abrir, repetir, reabrir y cambiar de estado.
2. **Cursor compuesto (`voice.lab`, `monitor.incidents`).** La base guarda instantes con precisión de segundo: dos toques a la misma fila en el mismo segundo son un solo evento; dos filas distintas tocadas en el mismo segundo salen por id y, si la de id menor llega después de emitida la mayor, se ve en el sondeo siguiente solo si cambia de nuevo o al reconectar (`ready` con `resync`). Documentado en `TouchCursorTopic`. Las dos tablas son pequeñas; `ultron_studio_voice_lab_sessions` no tiene índice por toque (consulta por tabla).
3. **Kinds de llamada repartidos.** `voice.calls` = `VoiceCallEvent::KINDS` − {`reconciled`, `cost_recorded`, `unknown_provider_call`}; `voice.reconcile` = esos tres. Una guarda comprueba que la partición es exacta: un kind nuevo en la telefonía cae en `voice.calls` sin tocar nada aquí.
4. **El permiso de cada tema está declarado en el tema y vigilado por una guarda** que lo compara con `AuthorizationMap::resolve()` de su `listRoute()` (7 temas). `monitor.incidents` añade los roles de IRON GUARD (`StreamViewerGate`), comparados por reflexión con `IronGuardController::ALLOWED_ROLES` (privada allí).
5. **Los temas se construyen para quien mira** (`StreamTopics::for($access)`): el libro filtra borradores ajenos por identidad. El controlador prefiere una instancia dejada en el contenedor (pruebas).
6. **Los `outreach.*` son `PendingTopic`.** B9-run los sustituye en `StreamTopics::defaults()` por su adaptador real: misma interfaz, mismo nombre, `listRoute()` con su GET de lista y `permission()` igual a lo que el mapa resuelva (la guarda lo exige). Hoy no hay rutas `outreach` montadas.
7. **Fuentes de incidentes.** `ultron_studio` y `outreach` (config). `VoiceBrake` abre incidentes con `source = 'voice'` (`VoiceBrake::INCIDENT_SOURCE`): no se incluyen porque el freno ya tiene su tema; si el panel del monitor quiere verlos, es añadir `'voice'` a `incident_sources`. Decisión del lead.
8. **`resync` y commits tardíos (`StreamCountable`).** Los ids se reservan antes del commit: una transacción larga (publicar, con su despliegue) puede hacerse visible después que otra con un id mayor ya emitido, y esa fila queda bajo el cursor sin que `since()` la vea nunca. El canal de membresías lo resuelve contando en cada sondeo las filas hasta el cursor; aquí igual, para los cinco temas por id (`ledger`, `voice.calls`, `voice.reconcile`, `voice.brake`, `voice.publications`): si el recuento crece sin que el cursor se mueva, un `ready` con `resync: true` **solo para ese tema**. Coste: un `COUNT` indexado por tema contable y sondeo (rango de clave primaria; tablas pequeñas o de crecimiento lento). Gracias a ese oído, el `ready` de una reconexión que honra los cursores va con `resync: false` y los paneles dejan de releer cada 55 s (Q-3 de SSE9-f), sin perder un commit tardío. Los temas de cursor compuesto (`voice.lab`, `monitor.incidents`) no cuentan: una actualización tardía con toque anterior al cursor se vería al siguiente cambio de la fila o en una reconexión no honrada; son tablas pequeñas y de cambios secuenciales. Matiz: el oído compara el recuento con el esperado, no solo si sube; si **baja** (en el libro, un borrador descartado deja de ser visible para quien no audita), también manda la relectura. Inocuo: una lectura de más.

## 5. Pruebas (T24)

**Suite del canal: 32 pruebas / 297 aserciones, en verde en SQLite (por ficheros, uno a uno) y en PostgreSQL 14** (`UNIT_DB=ultron_studio_h9_sse docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/Stream`, tras el traslado L9-43; la base desechable se creó en el clúster local 55432). Pint en verde sobre todos los ficheros del paquete. `auth:coverage --unmapped` lista la ruta del canal junto a las 30 de S9 hasta que el lead añada la entrada del mapa en la rama del Hito 9 (esperado, L9-43).

| Punto de T24 | Prueba |
|---|---|
| Bandera apagada → 409 `stream_disabled` (sesión y vale); nace apagada sin la variable; solo `true` enciende; puerta y persona antes que la bandera (401/403 sin revelarla) | `UltronStudioStreamFlagTest` (4) |
| Un evento del libro llega en el sondeo siguiente; forma de `data` y del `id`; `ready` con la cabeza | `UltronStudioStreamLedgerTest::test_un_evento_del_libro…` |
| `Last-Event-ID` / `?after=` ni repite ni pierde; `after` manda; cursor futuro recortado; cabecera sin forma ignorada; `after` roto 422 | `…LedgerTest::test_reanudar…` |
| `resync` false solo cuando se honran todos los cursores (primera conexión, cursor recortado o tema sin cursor → true) | `…LedgerTest::test_una_reconexion_que_honra…` |
| Commit tardío bajo el cursor (ids explícitos 3 y 5; el 4 llega después): un solo `ready` de relectura, solo del libro, cursor sin mover; la fila posterior sigue llegando como evento | `…LedgerTest::test_un_commit_tardio…` |
| Sin datos personales (fila sembrada con teléfono, nombre, documento, cuerpo, importe, secreto, actor, ip, user agent) y claves exactas | `…LedgerTest::test_un_evento_nunca_lleva…`, y en cada tema de voz e incidentes |
| Borradores ajenos ocultos sin `audit.view` | `…LedgerTest::test_los_borradores_ajenos…` |
| Latido a los 15/30/45 s y cierre a los 55 s (37 esperas, 38 sondeos, última espera recortada a 1 s); el cliente se va → el bucle termina | `…LedgerTest::test_el_latido…`, `…::test_si_el_cliente_se_va…` |
| Temas mal pedidos 422; repetidos no duplican | `…LedgerTest::test_temas_mal_pedidos…` |
| Tema sin permiso: se omite, no se nombra, queda el `notice`; sin ninguno 403 | `UltronStudioStreamVoiceTopicsTest::test_un_tema_sin_permiso…`, `UltronStudioStreamIncidentsTest::test_la_llave_security_view…` |
| Llamadas/conciliación, freno (apertura y suelta reales con `VoiceBrake`), cadena de voz, laboratorio (toques y reanudación) | `UltronStudioStreamVoiceTopicsTest` (5) |
| Incidentes: abrir, repetir (sin evento en `incident_events`), reconocer; otras fuentes no; roles | `UltronStudioStreamIncidentsTest` (2) |
| Limitador por tique: nombre, cubo = huella del vale (nunca el vale), 4.ª conexión 429 con `Retry-After`, otro vale otro cubo, por persona con cabecera, cuenta con la bandera apagada | `UltronStudioStreamRateLimitTest` (3) |
| Guardas: 11 temas del contrato; permiso = mapa para los 7 con ruta; partición de kinds; roles de IRON GUARD; lista blanca del evento; vector de cursores; tema pendiente; los cinco temas por id cuentan con el mismo filtro que su cola (el libro, por persona) | `UltronStudioStreamTopicsGuardTest` (8) |
| Cero red | `Http::preventStrayRequests()` en `StreamTestCase::setUp` |
| La ruta del canal es del Studio (1 ruta, `ROUTE_COUNT`), lleva `throttle:studio-stream` y, si está clasificada, lo está con `ultron.view` | `UltronStudioStreamTopicsGuardTest::test_la_ruta_del_canal…` |

Como las pruebas de S9 (`StrategyFixtures`), las del canal levantan `EnforceAdminAuthorization` mientras la entrada del mapa vive en otra rama: la puerta que se prueba es la del controlador (`StudioAccess::requirePerson`: persona y `ultron.view`, códigos `person_required` y `forbidden`) y la de cada tema (su permiso de lista). Cuando el lead añada la entrada, quitar esa línea de `StreamTestCase` y `UltronStudioStreamFlagTest` vuelve a probar la puerta del mapa sin cambiar nada más.

## 6. Guardas existentes: qué estaba rojo y qué provoca este paquete

Antes de escribir nada, `UltronEngineIsolationTest` **ya estaba roja** (1 fallo: `test_fuera_del_studio_nadie_nombra…`, por las migraciones ajenas del H9, `2026_11_12_100001_create_ultron_studio_strategy_tables.php` y `2026_11_12_100002_create_outreach_tables.php`, que nombran tablas `ultron_studio_*` fuera de su lista blanca; lo cierra SEC9). Con las 30 rutas de S9 montadas y sin sus entradas en `AuthorizationMap`, también están rojas (comprobado por sus mensajes: 107 ≠ 77, 30 rutas sin clasificar): `UltronStudioVoiceRoutesTest`, `UltronStudioEarlyBodyCapTest`, `UltronStudioIntegrationsPermissionsTest`, `UltronStudioEngineReadOnlyTest::test_recorrer_todas_las_rutas…`, `Strategies/UltronStudioStrategyRoutesTest` (las dos), `Access/AuthorizationCoverageTest`, `Telephony/TelephonyRoutesTest` (llama a `auth:coverage --unmapped`) y `UltronEngineIsolationTest::test_ninguna_ruta_del_studio…` (recuento). **Nada de eso lo origina SSE9.**

**Con la decisión L9-43 (controlador en `Crm/UltronStudio`, ruta en `routes/ultron-studio.php`, `routes/api.php` intacto), este paquete no añade ningún fichero ni referencia fuera de la lista blanca de `UltronEngineIsolationTest`:** `test_fuera_del_studio…` y `test_las_zonas_del_motor…` no cambian por SSE9 (comprobado antes del traslado: los únicos hallazgos de SSE9 eran el controlador en `Api/Admin/` y la línea de `routes/api.php`, ya retirados). Lo único que SSE9 aporta a las guardas es **una ruta más del Studio** (108 con S9; 78 si se cuenta desde la constante vigente, 77): las pruebas que suman el total (`UltronEngineIsolationTest::test_ninguna_ruta…`, `UltronStudioVoiceRoutesTest`, `UltronStudioEarlyBodyCapTest`, `UltronStudioIntegrationsPermissionsTest`, `Strategies/UltronStudioStrategyRoutesTest::test_las_30_rutas…`) y las que recorren todas las rutas (`UltronStudioEngineReadOnlyTest::walkEveryRoute`, `AuthorizationCoverageTest`, `TelephonyRoutesTest` por `auth:coverage`) se adaptan en la rama del Hito 9 (**L9-38**): sumar `UltronStudioStreamController::ROUTE_COUNT` al total, añadir `$this->walk('GET', 'stream?topics=ledger', $ana)->assertStatus(409)` al recorrido (bandera apagada: no escribe nada; encendida, 200 y tampoco escribe) y la entrada del mapa de §9. **Pasada de confirmación de `UltronEngineIsolationTest` tras el traslado (09-11, tregua levantada): 9 pruebas, 1 fallo, el recuento (`test_ninguna_ruta_del_studio…`: 108 frente a 77).** `test_fuera_del_studio…` (que al empezar estaba rojo por las migraciones de DB9) y `test_las_zonas_del_motor…` están **en verde**: SSE9 no añade ningún fallo nuevo a la guarda; al recuento aporta la ruta 108.ª, y sin ella seguiría rojo (107 frente a 77). `TelephonyIsolationTest`, en verde antes del traslado (12 pruebas); el traslado no la toca (no rastrea `Stream/`).

## 7. Pendientes y riesgos (con dueño)

- **Ocupación de workers PHP-FPM (OPS/lead).** Cada pestaña del CRM con el canal encendido ocupa un worker 55 s de cada 55 s (reconexión inmediata con retroceso de 1 a 30 s): la ocupación es ≈ número de pestañas abiertas. El pool se amplió a 24 el 06-11; encender la bandera exige vigilar la cola del pool (`listen queue`) en el pico diurno. La bandera es el freno: apagarla devuelve al CRM al sondeo en la siguiente reconexión.
- **Tique en la URL (SEC9).** Igual que los demás streams: el vale va en la query (nginx lo registra), caduca en 300 s y no es la sesión. El cubo del limitador usa su huella, nunca el vale; el canal no lo escribe en ningún log.
- **B9-run:** sustituir los cuatro `PendingTopic` en `StreamTopics::defaults()` por sus adaptadores (`listRoute()` con su GET de lista; la guarda exige que `permission()` coincida con el mapa). La variable de entorno y la entrada del mapa ya existen.
- **SSE9-f (frontend):** su informe (`informe-sse-frontend.md`) ya está alineado con esta forma (vector en `id`, `?after=`, `ready`, `stream.notice`, 409/403/422 → sondeo). **Q-3 queda respondida:** una reconexión con `?after=` exacto recibe `ready` con `resync: false`; releer solo cuando `resync` sea `true`, y un `ready` intermedio con un solo tema debería releer solo ese tema (hoy el cliente relee a todos los oyentes: funciona, pero puede afinarse). **Q-5** (no distinguir 409 de 403/5xx desde `EventSource`) no es del backend.
- **Mover `RateLimiter::for('studio-stream')` a `AppServiceProvider`** (una línea) cuando el lead integre, para que quede con los de la voz; `StreamRateLimit::register()` es idempotente y no estorba.
- **`incident_sources`:** decidir si `voice` entra (hoy no).
- **Suite completa contra la línea base:** ver §8.

## 8. Suite completa (SQLite) frente a `tests/baseline-failures.txt`

**No hay comparación nominal:** la suite completa que lancé en segundo plano (SQLite, `--log-junit`) llegó al **81 % (12 744 de 15 688 pruebas)** durante la tregua de memoria del lead (0,3 GB libres; dos suites ajenas murieron) y la detuve yo al levantarse la tregua, siguiendo su indicación de cerrar con cifras por ficheros y no relanzarla. En el rastro de progreso hasta ese punto: **2 F, 127 E, 156 S** (sin nombres: PHPUnit solo los imprime al final; el junit quedó vacío). Para situarlo: la línea base tolera 119 nombres, y las carpetas `Telephony/`, `UltronStudio/` y `Voice/` —donde están las guardas rojas por S9 (§6)— van al final del orden de la suite y no habían corrido aún. La comparación nominal queda para la pasada final del lead (`bin/suite`, que archiva y compara contra la línea base). Lo que sí está comprobado con nombre, con el código final: la suite del canal (32/297, SQLite y PG), `UltronEngineIsolationTest` (1 fallo, el recuento de S9 + la ruta del canal), y las 8 guardas de §6 corridas juntas antes del traslado (82 pruebas, 8 fallos, todos atribuidos: 7 de S9/DB9 y el recuento).

## 9. Bloques literales para la rama del Hito 9 (los añade el lead; L9-43)

`app/Support/Access/AuthorizationMap.php`, en `OVERRIDES`, tras `'POST api/admin/ultron-studio/voice-lab/sessions/{uuid}/verdict' => 'ultron.approve',`:

```php
        // ULTRON STUDIO · Hito 9, paquete SSE9: el canal SSE único del Studio
        // (routes/ultron-studio.php, bloque propio al final; se abre con el vale). La
        // puerta es la base del Studio, `ultron.view`; dentro, cada tema exige el
        // permiso del GET de lista de su módulo y el que falta se omite.
        'GET api/admin/ultron-studio/stream' => 'ultron.view',
```

Ejemplo de entorno, tras `ULTRON_STUDIO_MONITOR_EXPECT=`:

```dotenv

# Canal SSE único del Studio (Hito 9, paquete SSE9; contrato ULTRON_STUDIO_H9_CONTRACT.md,
# «Paquete SSE9»; config/ultron_studio_stream.php). Un GET .../ultron-studio/stream por
# pestaña del CRM con temas por módulo (ledger, voice.*, monitor.incidents, outreach.*):
# solo lecturas por cursor, cero red, nunca datos personales. Apagado, responde 409
# stream_disabled y el CRM sigue sondeando las API. Solo true/1/on/yes encienden; la
# reversión es volver a false. Latido 15 s, conexión máxima 55 s, cupo studio-stream.
ULTRON_STUDIO_STREAM_ENABLED=false
```

Al integrar la entrada del mapa: quitar `withoutMiddleware(EnforceAdminAuthorization::class)` de `tests/Feature/UltronStudio/Stream/StreamTestCase.php` y de `UltronStudioStreamFlagTest.php` (vuelven a probar la puerta del mapa; el `assertContains(…, [null, 'ultron.view'])` de la guarda pasa a exigir `'ultron.view'`), y sumar `UltronStudioStreamController::ROUTE_COUNT` donde se cuenten las rutas del Studio (L9-38).
