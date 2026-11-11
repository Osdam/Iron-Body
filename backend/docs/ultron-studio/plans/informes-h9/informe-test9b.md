# Informe TEST9-b · `OutreachIsolationTest::exercise()` con las 13 rutas y las 9 órdenes de la ejecución (B-T9)

**Paquete:** TEST9-b, la mitad «`exercise()`» (`tester`). La otra mitad de B-T9, el E2E #26, no entraba en este encargo y sigue abierta.
**Encargo:** que `tests/Feature/UltronStudio/OutreachIsolationTest.php::exercise()` recorra las 13 rutas de la ejecución (`UltronStudioOutreachController::ROUTES`) y todas las órdenes `outreach:*`, con las banderas que las dejan trabajar, sin relajar ninguna aserción.
**Referencias:** `ULTRON_STUDIO_AUDITORIA_9_HITOS.md` §4.1 (B-T9, línea 272); contrato H9: L9-68 («TEST9-b enciende la maestra en `exercise()` y añade la comprobación del 409»), L9-73 y L9-54.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `07e70dac`. **Sin commit.**
**Fecha:** 11-11-2026 (UTC).
**Alcance:** sin red y sin producción. No he tocado nada de `app/**` ni de `docs/ultron-studio/ops/**`. Las mutaciones se hicieron en una copia aislada, nunca en el árbol.

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros tocados | **1**: `tests/Feature/UltronStudio/OutreachIsolationTest.php` (+361 −21; sha256 `e93addd4…fa240`) y este informe |
| Lo que recorre `exercise()` | **13/13 rutas** de la ejecución y **9/9 órdenes** de `php artisan list outreach`, en sombra (prueba 1) y en `live` (prueba 2). La prueba 3 hace lo mismo con las banderas por defecto. Lo afirma la propia prueba (§1.5) |
| SQLite, el fichero solo | **5/5, 2 576 aserciones** (antes 980). En sombra, **999 sentencias y 71 escrituras** (antes 581 y 32). En `live`, **1 019 y 81** (antes 577 y 32) |
| SQLite, con `tests/Feature/Outreach/Guards` y `UltronEngineIsolationTest` | **33 pruebas** (5 + 19 + 9), **16 024 aserciones, 0 fallos** |
| PostgreSQL 14 (`UNIT_DB=ultron_studio_test9b`), el árbol | 33 pruebas, 15 417 aserciones, **2 fallos: un rojo PREVIO** (F-3). Pasa lo mismo con la prueba de HEAD; mi cambio no lo causa. Guards y `UltronEngineIsolationTest`, en verde |
| PostgreSQL 14, copia con el parche de una línea que propongo (F-3) | Las 4 pruebas que no necesitan git: **4/4, 2 561 aserciones**. En sombra, 1 007 sentencias y 71 escrituras; en `live`, 1 027 y 81 |
| Mutaciones | **6 de 6 cazadas** (M1, M2, M3, M4a, M4b, M5), revertidas. Con 4 de ellas se ve el hueco que se cierra: la prueba de HEAD, o la prueba sin la pieza nueva, sigue en verde con la mutación puesta (§3) |
| Escrituras de rutas u órdenes fuera de la lista cerrada | **0**. La lista no se amplía: los tramos nuevos usan exactamente las 10 tablas que ya tenía el tramo `run` |
| Hallazgos | F-1 y F-2, corregidos en la prueba (los dos endurecen). F-3 y F-4, sin tocar, para que decida el lead. F-5 y F-6, de diseño y ya conocidos |
| Pint | En verde (`vendor/bin/pint --test`) |

## 1. Lo que recorre ahora `exercise()`

### 1.1 Lo que se añade al mundo y a las banderas (todo antes del escuchador)

- **Un tercer socio limpio** (`world()`, `OutreachIsolationTest.php:815-817`). Vence en 5 días, no tiene deuda, tiene WhatsApp autorizado y su número es **canario**. Con él la canaria de S9 (línea base «solo canario», N3) tiene alguien a quien planificar. Los dos socios de antes no se tocan ni entran en `claves`, así que el tramo de hechos lee exactamente lo mismo. Por qué hace falta un tercero: el socio 0 tiene una cuenta por cobrar vencida, y N6 bloquea lo comercial (`collections_overdue_blocks_commercial`).
- **`commercial.events_enabled = true`** en `runFlags()` (`:844`), como hacen todas las pruebas de `Run`. Con los eventos apagados (así está producción hoy), `LadderInputBuilder::humanRequestedRecently()` devuelve `null`, y N6 toma `null !== false` como «pidió humano» (`PolicyLadder.php:475-476`). Toda inscripción comercial acabaría **derivada** y no llegaría nada al plan, al despacho ni a la bandeja. Es L9-54 / P9-24. Efecto secundario útil: durante el recorrido los observadores del motor comercial están vivos, y si escribieran en `commercial_*` saldría como escritura prohibida.
- **Las 6 llaves de la ejecución** (`OutreachAccess::PERMISSIONS`) al Super Admin, en `setUp()` (`:239`), igual que `OutreachApiTest`.

### 1.2 Tramos y dónde puede escribir cada uno (`ALLOWED_WRITES_BY_SEGMENT`, `:121-145`)

| Tramo | Qué ejecuta | Dónde puede escribir |
|---|---|---|
| `s9`, `facts`, `bridge`, `run`, `sse` | Lo de antes, sin cambios | Lo de antes. `run` pasa a apuntar a `RUN_WRITES`, que tiene las **mismas 10 tablas en el mismo orden** |
| **`run-cli`** (`:552-610`) | Las órdenes, sobre la canaria recién publicada (§1.4) | `RUN_WRITES`: las 8 `outreach_*`, `incidents` e `incident_events` |
| **`run-read`** (`:617-645`) | Las 9 lecturas de la API (§1.3) | **Ninguna**: se exige solo lectura (`assertReadOnly`, prueba 1) y en la prueba 2 la lista vacía hace caer cualquier escritura |
| **`run-api`** (`:648-653`) | El freno de canal por HTTP y su suelta a cuatro ojos | `RUN_WRITES` |
| La bandeja (`:657-668`) | `claim` y `outcome` | En `live`, a `run-api`. En sombra, a `run-read`: ahí no hay bandeja y las dos rutas tienen que responder 404 **sin escribir nada** |

Entre `run-cli` y la API, fuera del escuchador, el reloj avanza 15 minutos (`OutreachPlanner::OFFSET_MINUTES`, `:562`): el desfase determinista de cada persona va de 0 a 14 minutos. También fuera del escuchador se abren **sesiones nuevas a ese reloj** (`:614`). Así, el `touch` de `EnsureAdminAuth.php:105` (que no es código del H9) no escribe `admin_sessions`, y los tramos de la ejecución no necesitan admitir la sesión. Lo admite solo `s9`, como antes.

### 1.3 Las 13 rutas, una a una

Cada respuesta pasa por `assertNoSeededPersonalData`.

| Ruta | Sombra (prueba 1) | `live` (prueba 2) | Banderas por defecto (prueba 3, L9-68) |
|---|---|---|---|
| `GET outreach/status` | 200: `enabled` true, el modo, `sendsEnabledInCode` false, `brakes.own.global` false, cabeza de la cadena verificada, `live.assistedQueue` 0 | Igual, con `assistedQueue` 1 | 200: `enabled` false, `sendsEnabledInCode` false, `live` null |
| `GET outreach/metrics` | 200, `data.mode` = sombra | 200, `data.mode` = `live` | 409 `outreach_disabled` |
| `GET outreach/enrollments?per_page=10` | 200, `total` 3, `subjectRef` de 64 hex, sin `subject_key` ni `member_id`, sin `"m:` en el cuerpo | Igual | 409 |
| `GET outreach/enrollments/{uuid}` | 200, `jobs` = [el trabajo del socio limpio] | Igual | 409 |
| `GET outreach/decisions/{uuid}` | 200, `kind` plan, `traceHash` = el guardado | Igual | 409 |
| `GET outreach/jobs` | 200, [el trabajo], sin `phone_ref` | Igual | 409 |
| `GET outreach/brakes` | 200, `meta.open.global` false (la PARADA de `run` ya se soltó) | Igual | 409 |
| `POST outreach/brakes` | 201, freno `channel` `voice_call` por Ana | Igual | 409 |
| `POST outreach/brakes/{uuid}/release` | 200, `released` true por Beto (otra persona) | Igual | 409 |
| `GET outreach/people/{subjectRef}/timeline` | 200, `available` true, entradas no vacías | Igual | 409 |
| `GET outreach/assisted/queue` | 200, `[]` (en sombra no hay bandeja) | 200, [el trabajo] | 409 |
| `POST outreach/assisted/{uuid}/claim` | **404 `job_not_found`, sin escribir** | **201**, `claimedBy.id` = Ana | 409 |
| `POST outreach/assisted/{uuid}/outcome` | **404 `job_not_found`, sin escribir** | **201**, `outcome` answered, `replayed` false | 409 |

### 1.4 Las 9 órdenes, una a una

Cada salida pasa por `assertNoSeededPersonalData`.

| Orden | Sombra / `live` | Banderas por defecto |
|---|---|---|
| `outreach:sweep --force --json` | 0. Ingesta no omitida, **3 inscritos**. Plan: `due` 3, **`planned` 1** (el limpio), **`denied` 2**: el socio 0 en **N3** (no es canario) y el 1 en **N1** (sin WhatsApp) | 0, `master_flag_off` |
| `outreach:whatsapp-outcomes --json` | 0, `skipped` false, `jobs` 0 (la canaria es asistida) | 0, `master_flag_off` |
| `outreach:voice-outcomes --json` | 0, `skipped` false, `jobs` 0 | 0, `master_flag_off` |
| `outreach:dispatch --inline --json` | 0. Concilia, `claimed` 1, y el trabajo queda `simulated` en sombra o `started` en vivo | 0, `master_flag_off` |
| `outreach:reconcile --json` | 0, `skipped` false | 0, `master_flag_off` |
| `outreach:replay <decisión plan> --json` | 0, **`same`**, `storedHash` = `replayedHash` = `trace_hash` | Con un uuid que no existe: 2 |
| `outreach:suppress-subject <socio 1> --json` | 0, en seco: `applied` false, `openEnrollments` 1, `enrollmentsClosed` 0 | — |
| `outreach:suppress-subject <socio 1> --apply --json` | 0, `enrollmentsClosed` 1 | 0, `liveJobs` 0 (no tiene filas) |
| `outreach:monitor --json` | 0, en `run` (×1) y al final de `run-cli`, ya con datos: `skipped` false, `opened` [] | 0 |
| `outreach:stop --all` | 0, en `run` (sin cambios; `--by` Ana, la suelta Beto) | 0 en una captura aparte: la PARADA GLOBAL funciona **siempre** (L9-50, L9-63). 5 sentencias, 1 escritura, clasificada en las tablas de la ejecución |

### 1.5 Completitud (`assertExercisedEverything()`, `:717`)

Las rutas se llaman por su **patrón** de `ROUTES`, con el ayudante `outreach()` (`:913`); las órdenes, con `orden()` (`:930`). Los dos ayudantes anotan lo que se recorre. Al final de `exercise()` (`:670`) y de la prueba 3 se compara lo recorrido con `UltronStudioOutreachController::ROUTES` (13; `OutreachApiTest` ya la compara con el enrutador) y con las órdenes `outreach:*` de `Artisan::all()`. **Una ruta o una orden nueva hace caer la prueba hasta que entre en el recorrido.** M4a y M4b lo demuestran.

### 1.6 Cero red (`assertNoNetwork()`, `:741`; el vigía en `setUp()`, `:231`)

Se mantienen `Http::preventStrayRequests()` + `Http::fake([])` + `Http::assertNothingSent()`, y se añade un vigía del evento `RequestSending`. El motivo es F-2. La prueba no usaba ningún vigía de sockets y sigue sin usarlo: lo que no pase por el cliente HTTP de Laravel lo cubre, solo estáticamente y solo en el código de la ejecución, `OutreachStaticGuardsTest` (§5).

### 1.7 Datos personales (`assertNoSeededPersonalData()`, `:764`)

Ahora se aplica a las 13 rutas y a las 9 salidas de consola. Su lista es un **superconjunto** de la anterior: conserva las 8 cadenas y añade los teléfonos que el mundo siembra de verdad (F-1).

## 2. Escrituras observadas por tramo

Cifras de un recorrido de diagnóstico, en SQLite, con la misma lógica.

| Tramo | Sombra | `live` |
|---|---|---|
| `s9` | 26 escrituras en las tablas del gobierno (como antes) | Igual |
| `run` | 6: `outreach_brakes` (1 alta, 1 cambio), `outreach_runs` (2 y 2) | Igual |
| `run-cli` | 37 de 316 sentencias: altas en `outreach_cursors` 1, `outreach_decisions` 6, `outreach_enrollments` 3, `outreach_jobs` 1, `outreach_outcomes` 3, `outreach_runs` 7; cambios en `outreach_enrollments` 6, `outreach_jobs` 3, `outreach_runs` 7 | 44 de 319: lo mismo **más la reserva**, `outreach_budget_counters` (3 altas, 6 cambios); `outreach_outcomes` 2 |
| `run-read` | **0 de 86** | **0 de 84** |
| `run-api` | 2 de 14: `outreach_brakes` (1 y 1) | 5 de 37: `outreach_brakes` (1 y 1), `outreach_jobs` (el `claim`), `outreach_outcomes` y `outreach_enrollments` (el `outcome`) |
| Total | 71 = 26 + 6 + 37 + 2 | 81 = 26 + 6 + 44 + 5 |

Ningún incidente: en este mundo, el monitor y la conciliación no tienen nada que abrir.

## 3. Mutaciones

Se hicieron en una copia (`rsync` del backend al scratchpad, con su `vendor` real) y en SQLite, con `mutar.py`. Cada mutación se aplica sobre los ficheros prístinos, se ejecuta y se revierte. El control sin mutación (M0) da 4/4 en verde. Al terminar: `cmp` de los 5 ficheros de la copia contra el árbol, idénticos; `grep MUTACIÓN` en `app/` del árbol, vacío.

| # | Dónde (en la copia) | Qué | Prueba nueva | Prueba de HEAD, o la nueva sin la pieza |
|---|---|---|---|---|
| M1 | `OutreachReadModels::jobs()` (`GET outreach/jobs`) | `update marketing_leads … where id = 0`: 0 filas, así que los recuentos no cambian | **2/2 rojas**: «El H9 escribe en tablas ajenas: run-read: update "marketing_leads"…» | HEAD: **3/3 en verde** (no recorría la ruta) |
| M2 | `OutreachDispatchCommand::handle()` | `update ultron_studio_strategies … where id = 0`, con el nombre armado en ejecución (`implode`) para que la guarda estática no lo vea | **3/3 rojas**: «run-cli → ultron_studio_strategies» (pruebas 1 y 2) y «banderas por defecto ejecutó algo que no es una lectura» (prueba 3). La estática (prueba 4) sigue en verde: no lo ve | HEAD: **4/4 en verde** |
| M3 | `VoiceCallOutcomes::run()` (`outreach:voice-outcomes`) | `Http::get('https://api.elevenlabs.io/…')` dentro de `try { } catch (\Throwable) {}` | **3/3 rojas**: «El código intentó salir a la red… GET https://api.elevenlabs.io/…» | Sin el vigía (solo `preventStrayRequests` + `assertNothingSent`): **3/3 en verde** |
| M4a | Una orden nueva, `outreach:noop` (`app/Console/Commands/Outreach/OutreachNoopCommand.php`) | Algo que el recorrido no conoce | **3/3 rojas**: «no ejecuta todas las órdenes outreach:* registradas» | — |
| M4b | `exercise()` sin `GET outreach/metrics` | Una ruta menos | **1/1 roja**: «no visita las 13 rutas» | — |
| M5 | `UltronStudioStrategyController::catalog()` (`GET strategies/catalog`, de S9) | `'contacto' =>` el teléfono del primer prospecto | **1/1 roja**: «GET strategies/catalog lleva un teléfono sembrado (3006660090)» | HEAD: **1/1 en verde**, aunque ya recorría esa ruta con la lista de `…0099` (F-1) |

## 4. Hallazgos

### F-1 · Corregido en la prueba: la aserción de datos personales no podía caer

`assertNoSeededPersonalData` buscaba las formas de `LEAD_PHONE` / `VOICE_PHONE` (`…0099`). Pero `world()` siembra `substr(LEAD_PHONE, 0, 9).$i`, es decir `3006660090` y `3006660091` (y `+57…0090` y `+57…0091` para la voz). Los teléfonos de verdad no estaban en la lista, y los canarios solo se guardan como huella y últimos cuatro dígitos. M5 lo demuestra: HEAD no ve un teléfono real en una ruta que ya recorría. Se ha corregido porque las comprobaciones nuevas de las 13 rutas y de las 9 salidas dependen de esta aserción. La lista es un superconjunto: se conservan las 8 cadenas y los teléfonos salen de `leadPhone()` y `voicePhone()`, que ahora usa también `world()` con los mismos valores.

### F-2 · Corregido en la prueba: un intento de red tragado no se veía

Con `preventStrayRequests()`, el stub lanza `StrayRequestException` (`vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1515-1520`) **antes** del registro (`:1483`). Si el código atrapa `Throwable` alrededor de una llamada a un proveedor, `Http::assertNothingSent()` sigue en verde. El evento `RequestSending` sale antes del stub (orden de manejadores en `:1451-1453`), y el vigía nuevo lo escucha. M3 lo demuestra.

### F-3 · Sin tocar, decisión del lead: la prueba está en rojo en PostgreSQL, también en HEAD

En PostgreSQL, las pruebas 1 y 2 caen en `assertWritesStayHome` por 4 sentencias del tramo `s9`: `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`. `writeTarget()` (`OutreachIsolationTest.php:963`) las cuenta como «no se sabe clasificar».

- **Origen:** `StudioIntegrations::lock()` (`app/Services/UltronStudio/StudioIntegrations.php:150`). Lo llaman `StudioMessageTemplates.php:286` (crear la plantilla) y `StudioStrategyPublishing.php:282` (publicar, pausar y reanudar).
- **No es mío:** comprobado ejecutando la prueba de HEAD (`07e70dac`) en PostgreSQL, que cae igual.
- **Desde cuándo:** el cerrojo y el clasificador conviven desde `e4a44b9`. Hipótesis, no comprobada en `e4a44b9`: la prueba nunca ha estado en verde en PostgreSQL, y el «120/120 (2 omitidas, PG)» de L9-70 sería una pasada en SQLite con las dos de solo PostgreSQL omitidas.
- **Qué es la sentencia:** no escribe ninguna tabla. Es de transacción, como `BEGIN` o `SAVEPOINT`, y el docblock de `writeTarget()` dice que esas van a `null`. Aun así, aceptarla es tocar una aserción existente, y el encargo lo prohíbe; no podía consultarlo durante el trabajo. **No lo he cambiado en el árbol.**
- **Parche que propongo**, una línea y solo esa cadena exacta. Nunca un `set` genérico: `SET session_replication_role = replica` apagaría los disparadores de inmutabilidad.

```diff
-        if (preg_match('/^(select|pragma|savepoint|release\s+savepoint|rollback|begin|commit|explain)\b/', $s) === 1) {
+        if (preg_match('/^(select|pragma|savepoint|release\s+savepoint|rollback|begin|commit|explain)\b/', $s) === 1 || $s === 'set transaction isolation level read committed') {
```

Con ese parche, en la copia, las 4 pruebas que no necesitan git pasan en PostgreSQL (§0). En el árbol, la línea `[TEST9-b]` sale en sombra y en `live` también en PostgreSQL: todo lo que hay dentro de `exercise()` pasa allí, y solo cae la clasificación posterior.

### F-4 · Sin tocar, adyacente: los 4 temas `outreach.*` del canal SSE no emiten nada en esta prueba

El tramo `sse` pide `after=<tema>:0` para los 11 temas (`OutreachIsolationTest.php:531`). Para los `outreach.*`, el cursor es compuesto (`<unix>-<id>`), y `OutreachTopic::compositeSince()` toma «0» como un cursor roto y arranca en la cabeza, sin nada que emitir (`app/Services/Outreach/Run/Stream/OutreachTopic.php:58-62`). Lo comprobé: el `ready` suscribe los 11 temas, pero solo llegan `ledger` (14) y `voice.calls` (1), aunque ya existen pasadas y un freno. Así que «el canal con sus once temas» no prueba nada de esos 4. Propuesta: `0-0` para los `outreach.*`. No lo he aplicado porque no hace falta para las rutas ni para las órdenes; lo decide el lead. Además, el tramo `sse` corre antes que `run-cli`: aun con el parche, solo verían datos `outreach.runs` y `outreach.brakes`.

### F-5 · Conocido (L9-54, P9-24): con los eventos apagados no se planifica nada comercial

Es la causa de §1.1. Consecuencia para la prueba 2 («configuración de producción»): ahora corre con los eventos encendidos, como será la sombra según P9-24. El estado de hoy (maestra en sombra con los eventos apagados) ya no se recorre aquí. Su único camino propio es la derivación de N6, que solo escribe en `outreach_decisions` y `outreach_enrollments` (`OutreachPlanner.php:505-507`).

### F-6 · De diseño: la bandeja asistida solo admite trabajos en vivo

`AssistedQueue.php:64` y `:196` filtran `mode = live`. En sombra, `claim` y `outcome` responden 404 por diseño, y la prueba 1 exige que no escriban. Su camino de escritura solo lo recorre la prueba 2, que clasifica cada sentencia por tramo pero no compara los recuentos de `COUNTED`. No los he añadido allí para no cambiar más de lo pedido.

## 5. Qué verifiqué, con comandos

```bash
# SQLite, el fichero (final):          OK (5 tests, 2576 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/OutreachIsolationTest.php
# SQLite, con Guards y el motor:       OK (33 tests, 16024 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/OutreachIsolationTest.php tests/Feature/Outreach/Guards tests/Feature/UltronStudio/UltronEngineIsolationTest.php
# PostgreSQL 14 desechable (createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_test9b, PGGSSENCMODE=disable):
#   Tests: 33, Assertions: 15417, Failures: 2   (F-3: las pruebas 1 y 2 por los 8 «s9: SET TRANSACTION…»)
UNIT_DB=ultron_studio_test9b bash docs/ultron-studio/ops/pgunit.sh --no-logging <los mismos tres caminos>
# La prueba de HEAD en PostgreSQL (en la copia): las mismas 2 rojas por la misma causa.
# La nueva con el parche de F-3 (en la copia), sin la puerta de diff, que necesita git:  OK (4 tests, 2561 assertions)
# Línea base antes del cambio (SQLite): OK (5 tests, 980 assertions); 581/32 y 577/32
vendor/bin/pint --test tests/Feature/UltronStudio/OutreachIsolationTest.php   # passed
```

La puerta de diff de la prueba (Legacy, n8n, OTP y motor comercial frente a `origin/main`) sigue en verde en los dos motores, en el árbol. Las 21 líneas borradas del diff se revisaron una a una: cada una tiene un sustituto igual o más estricto. La lista de `run` es idéntica; los tres `Http::assertNothingSent()` siguen dentro de `assertNoNetwork()`; las 8 cadenas de teléfonos se conservan; las llamadas a `Artisan::call` pasan por `orden()` con la misma comprobación del código de salida.

## 6. Qué NO verifiqué, y por qué

- **El E2E #26** (nueva estrategia desde el CRM con la pila real) no estaba en este encargo. **B-T9 sigue abierta en esa mitad.**
- **Los caminos de escritura de `outreach:whatsapp-outcomes` y `outreach:voice-outcomes`** (enlazar `marketing_message_id` y `voice_call_id`, registrar resultados) **y los canales de WhatsApp y voz del despacho.** La canaria de S9 es asistida y la constante de envío está cerrada, así que aquí solo corren sus pasadas vacías. Los cubren `WhatsappW9IsolationTest` y las pruebas de V9; no los he vuelto a ejecutar.
- **Los caminos de escritura de la conciliación** (ambiguo con incidente, trabajo vencido con la reserva liberada, ventana acabada) **y el monitor abriendo frenos.** En este mundo no hay nada que conciliar ni que frenar, así que `incidents` e `incident_events` no se escriben nunca. Los cubren `OutreachDispatcherTest` y `H9MonitorThresholdsTest`.
- **`outreach:dispatch` sin `--inline`** (por la cola, `DispatchOutreachJob`): producción va `--inline` (`routes/console.php:536`), y en la suite la cola es `sync`. **`outreach:stop --strategy` o `--channel` por consola**: el alcance de canal se prueba por HTTP. **La derivación de N6** (F-5).
- **PostgreSQL:** con el fichero del árbol, la prueba sigue en rojo por F-3. En verde solo con el parche, en la copia. Las mutaciones, solo en SQLite.
- **La red fuera del cliente HTTP de Laravel** (sockets, `curl_*`, `file_get_contents`) no se vigila en ejecución. `OutreachStaticGuardsTest` la vigila estáticamente, pero solo en `app/Services/Outreach/**` y lo que cubra; no en el código ajeno al que llega el recorrido.
- **La regresión completa** no la he ejecutado. Cambié un solo fichero de prueba, y la única otra mención (`UltronEngineIsolationTest.php:82`, `:473`) es un comentario. Producción, el banco y los E2E tampoco.

## 7. Estado al cierre

- **En el árbol, sin commit:** `tests/Feature/UltronStudio/OutreachIsolationTest.php` (modificado) y este informe (nuevo). Nada de `app/**`, de `docs/ultron-studio/ops/**` ni de los ficheros sucios de otros agentes.
- **Base desechable** `ultron_studio_test9b` en 127.0.0.1:55432: la dejo creada para quien quiera repetir la pasada.
- **Copia de mutaciones y evidencia** en el scratchpad de la sesión: `…/scratchpad/copia/backend`, `mutar.py`, `mut-M0…M5.txt`, `verif-sqlite.txt`, `verif-pg.txt`. Es desechable.
- **Pendiente del lead:** aprobar o no el parche de F-3 (con él, la puerta PostgreSQL de esta prueba queda en verde) y decidir sobre F-4.
