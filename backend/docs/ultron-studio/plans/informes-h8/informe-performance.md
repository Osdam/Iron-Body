# PERF8 · Informe de rendimiento del Hito 8 (voz y telefonía)

**Fecha:** 08-11-2026. **Agente:** `performance-specialist`. **Base medida:** backend `c01124c`. Entre `6bb0808` y `c01124c` solo cambian documentos y pruebas: `app/` y las migraciones son idénticos. Durante las medidas (16:36–16:49) `app/` no cambió en el árbol: `AppServiceProvider.php`, modificado sin commit por otro agente a las 16:50 (el oyente de la post-llamada del laboratorio, TEST8-b F-1), es posterior y no toca ningún camino medido. **Sin commit.** No he tocado `app/`, `config/`, `routes/` ni migraciones. Cada hallazgo lleva fichero:línea, la evidencia y su dueño. El chunk de voz del CRM y el cliente de ElevenLabs los mide FE8-3, no este informe.

## 1. Problema y objetivo
Objetivos de la fila PERF8, D8-11 y D8-16:
- iniciación: p95 < 300 ms y ninguna petición saliente;
- herramientas: p95 < 500 ms;
- ingesta de la post-llamada: p95 < 200 ms (cuerpo de 1 MB en el peor caso);
- además: la puerta de salientes, la compilación y los presupuestos para `smoke-h8.py`.

## 2. Entorno y método
- **Máquina:** Apple M2, 8 núcleos, 8 GB, compartida. Carga a 1 min entre 1,5 y 2,6 durante todas las medidas: ninguna queda «bajo carga» según el umbral de 4 del H7. Software: PHP 8.3.33 y PostgreSQL 14.24 local (127.0.0.1:55432).
- **Siembra a escala** (`ops/perf-h8/resultados/siembra.json`, 9,8 s):
  - **Llamadas:** 5 101, es decir, 125 días × 40 (el máximo diario que permiten los cupos) más 2 ambiguas y 99 tramos de transferencia.
  - **Eventos:** 20 000.
  - **Contactos:** 200, con 185 consentimientos de cuatro ojos, 30 exclusiones y 200 consultas al RNE.
  - **Cadena de voz:** 50 entradas firmadas (25 agentes).
  - **Laboratorio:** 300 sesiones.
  - **Frenos:** 8, abiertos y sueltos.
  - **Canarios de voz:** 3, dados de alta por la API del H6.
  - Todo por los caminos de DB8: cada llamada nace como en la aplicación, hay una sola saliente viva por número y las herramientas solo cuentan con la llamada en curso. Después, `VACUUM ANALYZE`.
- **Arnés en proceso** (`PerfH8QueriesTest.php`, lanzado con `run-queries.sh`):
  - **Petición:** pasa por el kernel HTTP entero: middleware, `AuthorizationMap` y limitadores.
  - **Como producción:** caché y cola `database`; `storage` propio (`LARAVEL_STORAGE_PATH`), fuera del árbol compartido; sin red (`Http::preventStrayRequests()`).
  - **Aislamiento:** cada familia corre en una copia nueva de la base sembrada (`CREATE DATABASE … TEMPLATE`).
  - **Por ruta:** 1 petición de cebado y 1 perfilada con `DB::listen` (sentencias, tiempo de BD por tabla, transacciones y `EXPLAIN (ANALYZE, BUFFERS)` de cada SELECT con sus valores).
  - **Tiempos:** 5 peticiones de calentamiento y 3 tandas de 30 (n = 90). Los lectores, 3 × 2 000 llamadas.
- **Banco HTTP de extremo a extremo** (`serve.sh` + `http-bench-h8.py`, puerto 8610, base `ultron_studio_h8_perf_http`):
  - **Servidor:** `php artisan serve --no-reload` con 4 workers, `APP_ENV=production` y cachés de configuración, rutas y eventos.
  - **Peticiones:** 3 de calentamiento y 30 cronometradas por ruta, en serie, cada una con conexión nueva y el cuerpo leído entero.
  - **Ritmo:** por debajo de cada cupo.
  - **Red:** proxies a un puerto muerto. Resultado: 0 intentos de salida, 0 respuestas 5xx y 0 errores en los registros.
- **Limitadores:** en el CRM y el Studio, en estado estable (como en el H7). En las 4 rutas públicas, en su **primer golpe**: su clave es por llamada (E6-4), así que cada llamada estrena cubo, que es lo que pasa en producción.
- **Desviaciones:**
  - **Cupo de entrantes:** lo subo en memoria; la sentencia que lo cuenta no depende del valor.
  - **Constante de llamadas:** `CALLS_ENABLED_IN_CODE` sigue cerrada, así que la iniciación del canario se deniega solo por eso (comprobado en el evento: `reasons = [calls_disabled_in_code]`, `caller = active_voice`, `publication = ok`). La puerta calcula todos los hechos antes de decidir, por lo que las sentencias son las del camino autorizado salvo el estado de la fila.
  - **Job de salientes:** lo que va detrás de la constante (`recheckAfterCode`, `record`) se mide por reflexión, sin girarla.
- **Lo que las cifras no cuentan:** `DB::listen` no ve BEGIN/SAVEPOINT/COMMIT; los cuento aparte (`transactions`). Tampoco ve las sentencias que fallan contra un UNIQUE (los duplicados).

## 3. Línea base (mediana / p95 en ms)
Columnas: «En proceso» es n = 90 salvo donde se indica; «Ruido» es el rango de p95 entre las tres tandas. «HTTP local» es n = 30, de extremo a extremo.

**Rutas públicas**
| Ruta | Datos | Sentencias · BD ms | En proceso | Ruido | HTTP local |
|---|---|---|---|---|---|
| POST initiation, canario (todo abierto salvo la constante) | 5 101 llamadas | 18 · 7,0 | 10,6 / 10,9 | 10,8–11,0 | **34,5 / 41,9** |
| POST initiation, desconocido | | 18 · 5,4 | 10,5 / 10,9 | 10,7–10,9 | |
| POST initiation, reentrega | | 6 · 1,7 | 3,3 / 4,1 | 3,4–5,0 | |
| POST initiation, secreto malo (401) | | 5 · 1,1 | 2,8 / 3,2 | 3,0–3,4 | 18,7 / 38,3 |
| POST post-call, transcripción | 9 KB, 40 turnos | 13 · 5,8 | 11,0 / 12,2 | 11,7–19,8 | **32,2 / 40,3** |
| ídem | 40 KB, 200 turnos | 13 · 5,6 | 17,2 / 17,6 | 17,5–17,7 | |
| ídem | 128 579 B, 200 turnos | 13 · 5,6 | 45,1 / 46,0 | 45,4–47,2 | |
| ídem | **1 046 865 B, ~1 000 turnos** | 13 · 6,5 | **313,8 / 317,9** | 315,3–319,9 | **320,3 / 328,6** |
| POST post-call, busy · duplicada · audio · firma mala | | 10 · 6 · 9 · 5 | 6,4/6,8 · 5,6/5,9 · 5,0/5,5 · 3,6/3,8 | | |
| POST tools/consultar_planes, primera · siguientes | | 12 · 10 | 7,7/8,0 · 6,6/7,2 | | 22,5 / 25,2 |
| POST tools/consultar_horarios, primera · siguientes | | 11 · 9 | 6,6/7,0 · 5,9/6,2 | | |
| POST tools/solicitar_humano, primera · siguientes | | 12 · 10 | 7,8/8,0 · 6,9/7,2 | | 24,9 / 33,5 |
| POST tools/no_volver_a_llamar, primera · siguientes | | 13 · 10 | 7,7/8,2 · 6,3/6,6 | | |
| POST twilio/usage-trigger, nuevo · repetido · firma mala | | 11 · 5 · 5 | 7,2/7,6 · 4,1/5,0 · 2,9/3,1 | | 25,3 / 34,2 |

**CRM de telefonía**
| Ruta | Datos | Sentencias · BD ms | En proceso | Ruido | HTTP local |
|---|---|---|---|---|---|
| **GET admin/voice/status** | | **41 · 30,6** | **43,9 / 45,0** | 44,7–45,5 | **93,4 / 101,3** |
| GET admin/voice/calls | 25 por página | 12 · 3,8 | 15,4 / 16,4 | 15,8–17,1 | 62,4 / 66,7 |
| GET admin/voice/calls | perPage = 100 · completed · inbound | 12 | 23,1/23,7 · 16,7/18,2 · 17,0/18,4 | | |
| GET admin/voice/calls | page = 150 (profunda) | 12 · 10,1 | 21,9 / 22,9 | | |
| GET admin/voice/calls/{uuid} | | 12 · 3,4 | 13,0 / 13,8 | | |
| **GET admin/voice/metrics** | | **38 · 28,0** | **41,6 / 43,4** | 42,2–44,9 | **90,9 / 96,2** |
| **GET admin/voice/contacts** | 25 · 100 | **62 · 212** | **21,7/22,8 · 61,7/64,6** | 62,1–73,8 (100) | 67,7 / 78,7 (25) |
| POST admin/voice/calls (puerta de salientes, 409 por la constante) | canario · contacto con lead | 22 · 25 | 18,1/19,3 · 19,0/20,1 | 19,1–29,8 | |

**Studio de voz**
| Ruta | Datos | Sentencias · BD ms | En proceso | Ruido | HTTP local |
|---|---|---|---|---|---|
| GET integrations/voice (ruta 45) | | 30 · 8,6 | 20,7 / 21,9 | | 58,7 / 87,7 |
| GET voice/publications | 20 · 50 por página (todas `verified = ok`) | 33 · 33 | 28,3/30,9 · 37,5/40,7 | | 66,6 / 76,0 |
| GET voice/canary-evidence | | 42 · 13,7 | 26,2 / 26,8 | | 80,1 / 94,0 |
| GET voice/brakes · profiles · profiles/{uuid} | | 19 · 18 · 17 | 15,5/16,8 · 17,3/17,7 · 14,4/15,0 | | 55,3/65,5 · 55,0/60,3 · — |
| GET voice-lab/status · sessions (20 · 50) · {uuid} | 300 sesiones | 18 · 21 · 21 · 20 | 14,6/14,9 · 19,9/20,5 · 24,8/25,3 · 15,6/16,6 | | 54,7/58,8 · 62,1/68,8 |
| POST voice/compile-preview | | 20 · 5,7 | 17,0 / 17,9 | | |
| `StudioVoiceCompiler::compile`, a pelo | n = 200 | — | 0,74 / 0,76 | | |
| POST voice/publish, desplegador falso (201) · real + `Http::fake` (409) | n = 20 cada uno | 53 · 43 | 34,2/36,0 · 29,9/30,6 | | |

**Lectores, job y conciliación**
| Ruta | Datos | Sentencias · BD ms | En proceso | Ruido | HTTP local |
|---|---|---|---|---|---|
| `VoicePublishedConfig::read()` | cadena de 50 · de 200 (n = 6 000) | 1 · 1 | 0,81/0,84 · 0,91/0,95 | | |
| `VoiceBrake::isOpen()` | 16 · 200 filas | 1 · 1 | 0,27/0,28 · 0,36/0,38 | | |
| `OutboundGate::targetFacts(targetOf())` | canario · contacto con lead | 2 · 5 | 0,58/0,61 · 1,41/1,47 | | |
| Job: `run()` cerrado · `recheckAfterCode()` · `record()` | | 3 · 6 · 5 | 2,0/2,2 · 3,3/3,4 · 3,7/3,9 | | |
| `voice:reconcile`, primera pasada | 100 llamadas (n = 20) · 1 000 llamadas (n = 1) | 506 · 5 024 | 384,7/397,5 · 3 999,9 | | |
| `voice:reconcile`, régimen (ya conciliadas) | 100 llamadas (n = 20) | 104 · 23,6 | 168,1 / 186,2 | | |

`publish` con el adaptador real hace **9 peticiones HTTP secuenciales por publicación**: unas 3,5 `POST tools`, 1 `agents/create`, 1 `GET agent` y unas 3,5 `GET tools`. Con la constante abierta habría 1 más, la de asignar el número. Todas van **fuera de la transacción** (`StudioVoicePublishing.php:262-281`). No se ha medido con red real.

**Contra el contrato** (HTTP local, p95):
- iniciación: 41,9 ms frente a 300 ✓;
- herramientas: 25,2–33,5 ms frente a 500 ✓;
- post-llamada típica (9 KB): 40,3 ms frente a 200 ✓;
- **post-llamada de ~1 MB: 328,6 ms frente a 200 ✗** (P8-4, aceptado por el lead como caso extremo documentado; hasta 128 KB de cuerpo, 46 ms en proceso).

## 4. Diagnóstico (hechos comprobados salvo donde se dice)
- **Las 4 rutas públicas no crecen con los datos.** La post-llamada hace 13 sentencias tanto con 9 KB como con 1 MB. Su base de datos es fija, de 5 a 7 ms, y 7 de esas sentencias son del limitador en la caché `database`.
- **Lo que crece con el cuerpo es CPU**, y está medido por tramos (`PerfH8TramosTest.php`, `resultados/tramos-post-llamada.json`). Con ~1 MB:
  - guarda del motor: **260 ms** (p50; una `OutboundContentGuard::inspect()` por turno del agente);
  - HMAC del cuerpo: 7,3 ms;
  - `json_decode` de `Envelope::type`: 2,8 ms;
  - sobre (segundo `json_decode`): 6,4 ms.

  La guarda es lineal con el texto del agente, unos 0,29 ms por KB: 2 KB → 0,85 ms; 9 KB → 3,9 ms; 97 KB → 29 ms; 902 KB → 260 ms.
- **Del HTTP local al de producción.** La diferencia entre HTTP local y en proceso es el arranque por petición de `php -S`: entre 16 y 30 ms en las públicas y entre 40 y 55 ms en las lecturas con sesión. En producción, con FPM, ese coste existe, pero no se puede trasladar desde aquí.
- **Lectores calientes:**
  - `read()` es una sola sentencia, sin caché y, por tanto, sin invalidación que gestionar: lee lo vigente cada vez, que es lo correcto para un fallo que deniega. Verifica 2 entradas, no la cadena entera: cuesta lo mismo con 50 entradas que con 200.
  - `GET voice/publications` verifica la página (≤ 50 entradas) y las 2 partes, con las huellas anteriores en una sola consulta.
  - `isOpen()` también es una sola sentencia.
- **Índices** (`EXPLAIN` por familia, `pg_stat_user_indexes` y el ensayo `indices-parciales.sql`, cuya salida está en `resultados/indices-parciales.txt`):
  - Ninguna lectura secuencial sobre `voice_calls` ni sobre `voice_call_events` en las rutas públicas ni en los lectores. Las que aparecen son de tablas diminutas (caché, frenos, planes, exclusiones), donde es la elección correcta del planificador.
  - **Índices parciales de DB8 (E3-5):** con el volumen de la siembra, el planificador **usa** `vct_one_live`, `usva_one_canary`, `usva_one_rest` y `vc_one_live_outbound` cuando se consultan.
  - Con `enable_seqscan = off` **también son aplicables** `vex_one_opt_out_per_call` y `vex_phone_idx`. Con volumen (19 532 exclusiones y 5 200 contactos) **el planificador los elige solo** (0,006–0,049 ms).
  - La unicidad de `vc_one_live_outbound` sigue viva: una segunda saliente viva al mismo número da 23505.
  - Ninguna consulta de la aplicación lee `vc_one_live_outbound`: es una guarda de unicidad, y sus comprobaciones al insertar no cuentan como `idx_scan`.
- **El limitador del CRM de telefonía cuenta por IP.** El banco rotó 8 sesiones de personas distintas y recibió **429 en la lectura 121 del minuto**. En la tabla `cache` hay **una sola** clave del `throttle:120,1` con valor 120. Las del Studio, que usan limitadores con nombre por persona, quedaron en 3–5 cada una. Laravel usa `sha1(dominio|IP)` cuando `$request->user()` es null (`vendor/.../Routing/Middleware/ThrottleRequests.php:222-231`), y la sesión de admin no lo rellena (P8-5).

## 5. Hallazgos (por impacto; ninguno aplicado por mí)
| Id | Gravedad | Dueño | Dónde | Evidencia | Propuesta |
|---|---|---|---|---|---|
| **P8-1** | Media · **aceptado** (B8-c + DB8) | B8-c, DB8 | `VoiceEvidence::summary()` en `app/Services/Telephony/VoiceEvidence.php:91-116`, que se llama 2 veces desde `VoiceCallsQuery::metrics()` (`VoiceCallsQuery.php:110-112`); `status` lee la cadena 3 veces (`VoiceTelephonyStatus.php:24-26`, `VoiceTransferDirectory.php:39`) | 12 lecturas secuenciales de `voice_calls` por petición. `status`: 41 sentencias, 30,6 ms de BD, p95 45,0 ms en proceso y 101,3 ms por HTTP. Con el índice candidato `(voice_publication_seq, part) WHERE transfer_of_call_id IS NULL`, cada lectura baja de **0,9–2,0 a 0,04–0,10 ms**, y la agregación única con `FILTER`, de 0,94 a 0,08 ms (medido en una copia desechable, dentro de una transacción deshecha) | Lo que ya está asignado. Criterio de aceptación, medido con `run-queries.sh test_e_crm` en las mismas condiciones: `status` ≤ 22 sentencias, 0 lecturas secuenciales de `voice_calls` y p95 en proceso ≤ 30 ms (hipótesis a partir del ensayo; se comprobará) |
| **P8-2** | Media · **aceptado** | B8-c | `VoiceContacts::list()`/`row()` en `VoiceContacts.php:357-389` (`hasConsent` en `:47-57` y `VoiceCallFacts::excluded` en `VoiceCallFacts.php:59-62`) | N+1: 2 sentencias por fila. Con 25 filas, 62 sentencias y p95 22,8 ms; con 100, 212 sentencias y p95 64,6 ms | Dos `whereIn` por página. Criterio: `contacts?perPage=100` ≤ 16 sentencias, p95 en proceso ≤ 25 ms y DTO idéntico byte a byte |
| **P8-3** | Baja · **aceptado** | B8-c / OPS8 | `VoiceRateLimits::authenticated()` en `VoiceRateLimits.php:65-74`; Laravel solo borra una fila caducada de la caché cuando vuelve a leer esa clave (`vendor/.../Cache/DatabaseStore.php:147-156, 414-421`) | Cada iniciación deja **2 filas** en `cache` que nunca se borran; lo mismo con cada evento de post-llamada, cada llamada de herramientas y cada aviso. Unas 60–90 mil filas al año con el cupo actual (estimación) | La purga programada ya asignada. No tiene impacto medible en la latencia (la búsqueda es por clave primaria) |
| **P8-4** | Media · **aceptado por el lead como caso extremo documentado** (08-11) | — (sin cambio de código) | La guarda posterior: `VoiceTranscriptGuard::inspect` (`VoiceTranscriptGuard.php:55-94`) llama a `OutboundContentGuard::inspect()` (`app/Services/Marketing/OutboundContentGuard.php:577`), el **motor, que el H8 no puede tocar** (protección 5) | Post-llamada de ~1 MB: **p95 317,9 ms en proceso y 328,6 ms por HTTP, por encima de los 200 ms del contrato**; el 82 % es la guarda (260 ms), lineal a ~0,29 ms por KB de texto del agente (medido). Hasta 128 KB de cuerpo (~97 KB de texto del agente) se cumple con holgura: p95 46 ms en proceso (medido) | **Decisión del lead:** el tope de 1 MB se mantiene, como margen frente a la variabilidad del proveedor; bajarlo arriesga 4xx que hagan que ElevenLabs desactive el webhook tras 10 fallos (D8-16). El coste del caso extremo lo acota el limitador por evento (10/min por evento, E6-4). No se recorta la inspección: saltarse texto degradaría la guarda. Tampoco se pasa a un job: guardaría la transcripción en la cola, contra P-8. **ESTIMACIÓN, no medida:** con `VOICE_PER_CALL_MAX_SECONDS` ≤ 600 s, un agente que habla sin parar (~150 palabras/min) produce del orden de 10–20 KB de texto, ≤ 10 ms de guarda; con la pendiente medida, los 200 ms aguantan hasta unos 550 KB de texto del agente. Opcional para TEST8: una regresión «la guarda ≤ 0,5 ms por KB». Optimizar el motor queda para un hito con su puerta |
| **P8-5** | Baja–media (capacidad) · **asignado a B8-c** | B8-c (`routes/voice.php`, limitador) + SEC8 | `Route::middleware('throttle:120,1')` y `('throttle:30,1')` en `routes/voice.php:53,61`. La clave es `sha1(dominio|IP)`, porque la sesión de admin no rellena `$request->user()` | 429 en la lectura 121 del minuto **compartida por las 8 personas del banco** (una sola clave a 120 en `cache`) | Un limitador con nombre por persona y por red, como `ultron-studio-read` (`RateLimiter::for('ultron-studio-read')` en `AppServiceProvider.php`, `:604-607` en `c01124c`). Hoy todo el personal detrás de la misma IP (la recepción) comparte 120 lecturas y 30 escrituras por minuto de telefonía. Cambia la superficie de limitación: SEC8 debe verlo |
| P8-6 | Baja | B8 | `VoiceCallsQuery::list()` en `VoiceCallsQuery.php:26-33` | `page = 150`: lectura secuencial más orden; p95 22,9 ms frente a 16,4 en la primera página | Paginación por cursor solo si el CRM pagina hondo. Hoy no compensa |
| P8-7 | Observación · **asignado a B8-c** | B8-c | `VoiceReconciler::one()`/`cost()` en `VoiceReconciler.php:106-182` y `VoiceEvents::record()` en `VoiceEvents.php:26-59` | En régimen (100 llamadas ya conciliadas), 168 ms de mediana con solo 104 sentencias y 23,6 ms de BD. Hipótesis: el resto son los 2 INSERT duplicados por llamada que fallan contra el UNIQUE (excepción, deshacer el punto de guardado y la transacción) | `insertOrIgnore` / `ON CONFLICT DO NOTHING`, o comprobar antes de insertar. Hay que perfilarlo antes de tocar nada; es una tarea de fondo con un tope de 1 000 llamadas por pasada (4,0 s) |
| P8-8 | Observación | S8 | `labVerdict()`: lectura de `ultron_studio_voice_agents` por `spec_hash` | Lectura secuencial de 325 agentes, 0,07 ms; crece un agente por sesión (E4-9) | Índice por `spec_hash` solo si los agentes del laboratorio pasan de miles |
| — | Previo (F-3 del H7) | — | Limitadores y caché de permisos en la caché `database` | Son 14 de las 17–33 sentencias de cada ruta del Studio | Sin cambios desde el H7 |

## 6. Medición posterior
**EN ESPERA de B8-c.** El lead avisa cuando P8-1, P8-2 y, si los toca, P8-5 y P8-7 lleguen a la rama. Entonces mediré, en las mismas condiciones (misma base sembrada `ultron_studio_h8_perf_seed`, mismo arnés y mismo banco):
- P8-1 y P8-2: `run-queries.sh test_e_crm`, en modo consultas y en modo tiempos, y `serve.sh bench` (`status`, `metrics`, `contacts`).
- P8-7, si lo toca: `run-queries.sh test_h_job_y_conciliacion`.
- P8-5, si lo toca: el banco HTTP con 8 sesiones, que no debe dar 429 por IP.

Se compara contra el criterio de §5 y contra el ruido entre tandas. Si no hay mejora significativa, se revierte. Después se regeneran los presupuestos (`presupuestos.py`) y se cierra este informe con las cifras finales y la lista exacta de ficheros.

## 7. Presupuestos (`ops/perf-h8/presupuestos-h8.json`; lo genera `presupuestos.py` a partir de lo medido)
- **Esquema y validación:** el fichero usa el esquema que valida `smoke-h8.py` y **lo he pasado por su propio cargador** (`load_budgets`: válido; `budgets_path()` lo elige antes que el provisional de OPS8-b).
- **Reglas:**
  - p50Ms = ↑10(max(3 × p50 HTTP local, p50 + 100)); p95Ms = ↑10(max(3 × p95, p95 + 150)): son techos INICIALES, no medidas de producción, y se recalibran con la primera pasada en producción (regla de OPS8);
  - maxBytes = ↑KB(max(1,5 × bytes, bytes + 4 KB)) con las páginas llenas de la siembra.

| Ruta del smoke | p50 ≤ | p95 ≤ | bytes ≤ |
|---|---|---|---|
| GET admin/voice/status | 290 | 310 | 7 168 |
| GET admin/voice/calls | 190 | 220 | 24 576 |
| GET admin/voice/metrics | 280 | 290 | 6 144 |
| GET admin/voice/contacts | 210 | 240 | 11 264 |
| GET voice/profiles | 170 | 220 | 28 672 |
| GET voice/publications | 200 | 230 | 40 960 |
| GET voice/brakes | 170 | 220 | 7 168 |
| GET voice-lab/status | 170 | 210 | 7 168 |
| GET voice-lab/sessions | 190 | 220 | 52 224 |
| GET integrations/voice | 180 | 270 | 11 264 |
| GET voice/canary-evidence | 250 | 290 | 5 120 |
| `public401.maxMs` (401 de las públicas) | — | 140 | — |

- **Corrección al provisional de OPS8-b:** sus `maxBytes` de 16 384 se quedan cortos con datos reales. Medido con las páginas llenas: `voice/publications` 27 030 B, `voice/profiles` 18 727 B y `admin/voice/calls` 15 829 B, muy cerca del techo. Con la cadena llena, el smoke habría dado FALLO.
- **`status` y `metrics`:** sus techos se derivan de la medida previa a P8-1. Tras B8-c conviene regenerarlos (`presupuestos.py`).
- **Para CI/TEST8:** el mismo fichero trae `maxQueries` (64 rutas y llamadas; medido + 2) y `publicRoutes` (objetivo del contrato, p95 HTTP local y p95 en proceso).

## 8. Qué verifiqué ejecutando y qué no
**Ejecutado:**
- `run-queries.sh seed` y `run-queries.sh todas` en modo consultas: 8/8 OK.
- `PERF_N=30 PERF_WARM=5 PERF_REPS=3 run-queries.sh todas` (modo tiempos): 8/8 OK.
- `run-queries.sh tramos`: OK.
- `serve.sh seed` / `start` / `bench` / `stop`: 18 rutas, n = 30 en todas. Contactos se repitió aparte tras el 429 del primer intento. Al parar, el puerto 8610 quedó libre y sin procesos.
- `psql -f indices-parciales.sql` sobre una copia nueva: transacción deshecha.
- `presupuestos.py` y la validación con el cargador de `smoke-h8.py`.

Sin red: `Http::preventStrayRequests()` en proceso y proxies muertos en HTTP, con 0 intentos de salida.

**Sin verificar:**
- **Producción y plataforma:** no lancé carga contra producción (prohibido); nginx, FPM, TLS y la CPU del VPS no se reproducen aquí. Los techos son hipótesis por calibrar.
- **Constante de llamadas:** el camino autorizado completo con `CALLS_ENABLED_IN_CODE = true` (solo en la copia del banco de TEST8-b).
- **Red con ElevenLabs:** la latencia real de `publish` (9 viajes).
- **Concurrencia:** todo fue en serie.
- **Memoria y RSS:** sin medir.
- **El tiempo restante de la conciliación en régimen (P8-7):** sin perfilar.

## 9. Siguiente cuello de botella
- **Tras P8-1 y P8-2,** el coste fijo de las rutas del CRM y del Studio lo dominan los limitadores y los permisos en la caché `database` (F-3 del H7).
- **En la post-llamada,** la guarda del motor (P8-4).
- **En `publish`,** la red con ElevenLabs.
- Nada más compensa hoy: las públicas cumplen sus objetivos con entre 5 y 15 veces de margen, salvo el caso de 1 MB.

## 10. Riesgos y pendientes
- **Decisiones del lead (08-11):**
  - P8-1, P8-2 y P8-3: aceptados; P8-5 y P8-7, asignados a B8-c.
  - P8-4: aceptado como caso extremo documentado (el tope de 1 MB se mantiene).
  - OPS8-b, avisado para que el smoke use `perf-h8/presupuestos-h8.json`, con sus `maxBytes` medidos.
- **Pendiente:** el antes y después de B8-c (§6) y el cierre del informe.
- **Bases desechables en el PG local:** `ultron_studio_h8_perf_seed` (se necesita para el antes y después de B8-c), `ultron_studio_h8_perf` y `ultron_studio_h8_perf_http`.
- **Estado del banco:** en `PERF_STATE_DIR`, en el directorio de trabajo, con permisos 700. Ahí están las cachés, el `storage`, el manifiesto con **credenciales sintéticas**, sesiones del banco y los cuerpos.
- **Deuda que introduzco en la aplicación:** ninguna.

## Ficheros (sin commit)
- `docs/ultron-studio/plans/informes-h8/informe-performance.md` (este informe)
- En `docs/ultron-studio/ops/perf-h8/`:
  - `PerfH8QueriesTest.php`: arnés en proceso, familias A–H;
  - `PerfH8Seeding.php`: siembra a escala;
  - `PerfH8TramosTest.php`: tramos de CPU de la post-llamada;
  - `PerfH8HttpPrepTest.php`: preparación del banco HTTP;
  - `run-queries.sh`, `serve.sh`, `http-bench-h8.py`, `resumen.py`, `presupuestos.py`, `indices-parciales.sql`;
  - `presupuestos-h8.json`;
  - `resultados/`: `siembra.json`, `{a..h}-*-consultas.json`, `{a..h}-*-tiempos.json`, `http-20261108T214830Z.json`, `http-20261108T214851Z.json`, `tramos-post-llamada.json` e `indices-parciales.txt`.
