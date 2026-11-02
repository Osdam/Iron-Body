# PERF7 · Informe de rendimiento del Hito 7. PARCIAL (01-11-2026)

**Estado: PARCIAL.** Hecha la parte que no depende de la carga: consultas, N+1, bytes, el desglose del acta y una comprobación n=1. **Faltan:** las latencias n=30×3 (en proceso y HTTP), el `ng build` ×3 con los chunks y la tabla definitiva de presupuestos. El arnés para todo eso está listo (ver §6). No hay nada confirmado (sin commit).

## 1. Entorno
Backend `aa3451f` (worktree h7) y CRM `8536c0e`. Portátil compartido (macOS, PHP 8.3.33 con OPcache activo en cli-server y JIT apagado, PG 14 local en 127.0.0.1:55432). Bases propias: `ultron_studio_h7_perf` (arnés) y `ultron_studio_h7_perf_http` (banco). Caché y cola `database`, como en producción. Las medidas se tomaron con los limitadores en **estado estable**: las filas existen y el contador está en 5. El primer golpe de cada ventana suma 4 sentencias.

## 2. Consultas por ruta (deterministas, `DB::listen`)
**Ninguna ruta del Studio crece en consultas con los datos. No hay N+1.**

| Familia | Tamaños probados | Sentencias por petición |
|---|---|---|
| status, catalog, draft, rules, versions, versions/{uuid}, audit | 1/50/200 versiones | 18–20 (engine/readings 24) |
| publications (20 y 50), versions/{uuid} con `verified` | cadena 1/50/200 | 19 (±1) |
| canary-evidence | 0/40/200/1000 turnos + 20 000 acciones de fondo | 42–43 |
| integrations/* | 0/114/500 campañas · 0/20/50 activas | 17–34 (campaigns 27→33 con campañas, constante después) |
| lab/* | 1/50/200 ejecuciones de 12 casos | 17–21 |
| commit del motor | llave off/canary/all × cadena 0/1/50 | 93 (off) · 94 (canary/all) |

- **Coste fijo de cada petición:**
  - 10 sentencias de los dos límites del `throttle` sobre la caché `database` (dos con `SELECT … FOR UPDATE` + `UPDATE` en transacción);
  - 4 lecturas de la caché de permisos (`crm.role_permissions.v1` se relee en cada comprobación);
  - 2 de autenticación;
  - y, como mucho una vez por segundo y sesión, un `UPDATE admin_sessions.last_seen_at`.
  
  La evidencia pasa por dos limitadores: 20 + 4.
- **B7 `verified`:** no es N+1. Es 1 consulta por lista; el resto es CPU pura por entrada (sha256 + HMAC + decodificación estricta). La lista está paginada (≤ 50). El detalle de una versión no tiene `LIMIT` (10,3 KB → 29,3 KB → 87,5 KB con 1/50/200 entradas), pero la máquina de estados lo acota en la práctica a ~3–4 entradas por versión. Observación menor.
- **Lector del motor (`UltronPublishedConfig`):**
  - añade exactamente 1 `SELECT` por commit (`ORDER BY seq DESC LIMIT 1`, 0,29–0,38 ms de BD en proceso), sin punto de guardado y constante con la cadena 0/1/50;
  - con `off`, ninguna sentencia toca `ultron_studio_*`;
  - no cachea: lee la cabeza en cada commit, que es lo correcto para el contrato.
- **Observación previa al H7, fuera de mi mano:** cada commit del motor hace **15 `Schema::hasTable()`** (consultas a `pg_class`): conocimiento ×6, clases ×3, entrenadores ×3, `payment_transactions`, conversaciones y acciones. Está en servicios del motor (puerta de diff); su coste está sin cuantificar en tiempo.

## 3. Bytes (datos sembrados)
| Ruta | Bytes |
|---|---|
| catalog | 35 159 |
| versions | 13,1 KB (20) · 29,6 KB (50) |
| publications | 9,6 KB (20) · 20,5 KB (50) |
| lab/runs/{uuid}, 12 casos | 27,7 KB |
| lab/runs?per_page=50 | 37,4 KB |
| campaigns | 11,3 KB (20) · 22,5 KB (50) · `linked=yes&per_page=50`: 44,6 KB |
| status | 1,8 KB |
| evidence | 0,9 KB |
| commit | ~315 B |

## 4. Hallazgos
**F-1 (importante): la evidencia del canario es cuadrática y sin tope.** `GET versions/{uuid}/canary-evidence`, y `promote`, que calcula la misma evidencia, tarda:

| Turnos | Tiempo en proceso |
|---|---|
| 40 | 84 ms |
| 200 | 1 051 ms |
| 1 000 | 4 038 ms |

Valores indicativos: n=5, carga 3,6–4,4. Con n=1 en el servidor local: 1 188 ms con 200 turnos.

Desglose con `ops/perf-h7/tramos-acta.php`: **`UltronCanaryReport::turnos()` es ≥ 98 % del tiempo**, a ~10–13 µs por par (turno × mensaje):

| T | M | `turnos()` |
|---|---|---|
| 40 | 80 | 44 ms |
| 100 | 200 | 253 ms |
| 200 | 400 | 981 ms |
| 200 | 800 | 1 691 ms |
| 200 | 2 000 | 3 851 ms |

Las consultas, `revisar()` y el JSON suman menos de 30 ms; la BD, 5–17 ms.

- **Causa:** por cada entrante, `turnos()` vuelve a filtrar TODOS los mensajes (`where` + `filter` sobre modelos Eloquent) y busca la acción recorriendo TODAS las acciones; `data_get($a->metadata, …)` decodifica el JSON en cada acceso. Además el comando carga todos los mensajes y acciones desde `--since` sin límite (`--turns` solo acota T, no M).
- **Arreglo propuesto (backend; necesita el visto bueno del dueño del acta):** una sola pasada:
  - indexar las acciones por `source_event_id` y por `metadata.message_id` una vez;
  - repartir los salientes al recorrer los mensajes en orden de id;
  - y cortar en T.
  
  Con eso pasa de O(T×M) a O(M+A). Ganancia esperada, como hipótesis a medir: con 200/400, de ~1 s a ~20 ms. Exige una prueba dorada con el JSON del acta idéntico byte a byte antes y después.
- **Ubicación del fichero:** `app/Console/Commands/Marketing/UltronCanaryReport.php`. Queda fuera de las rutas de la puerta de diff del motor, pero es su herramienta: el enrutado lo decide el lead.

**F-2 (menor, transversal, no del Studio):**
- el `throttle` sobre la caché `database` cuesta 10 sentencias y 2 commits por petición;
- los permisos se releen 4 veces por petición.

Antes de proponer nada hay que cuantificarlo en tiempo.

## 5. Comprobación n=1 en el servidor local (NO son medidas)
23 rutas, todas 200. Casi todas entre 55 y 75 ms; `status` 224 ms (la primera petición), campañas ~111 ms y evidencia 1 188 ms.

## 6. Pendiente y cómo seguir (guiones en `ops/perf-h7/`)
1. Esperar a que la carga a 1 min lleve 5 min por debajo de 4: `espera-carga.sh`.
2. Latencias en proceso: `PERF_N=30 PERF_WARM=5 PERF_REPS=3 run-queries.sh test_X`, para X = a, b, c, d, e, f, g.
3. Banco HTTP: `PERF_STATE_DIR=<dir> serve.sh seed|start|bench|stop`, en el puerto 8310.
4. CRM: `python3 -I build-crm.py <CRM> 3`.
5. Tablas con `python3 -I resumen.py --detalle`.

Los presupuestos que propongo son preliminares:
- **consultas:** que no crezcan con los datos (±1), con los valores de §2 + 2;
- **bytes:** los de §3 + 25 %;
- **p95:** queda por medir.

Ficheros: `PerfH7QueriesTest.php`, `PerfH7Seeding.php`, `PerfH7SeedHttpTest.php`, `run-queries.sh`, `serve.sh`, `http-bench.py`, `resumen.py`, `tramos-acta.php`, `espera-carga.sh`, `build-crm.py` y `resultados/*.json`.
