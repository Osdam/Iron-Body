# PERF7 · Informe de rendimiento del Hito 7 de ULTRON STUDIO

**Fecha:** 01/02-11-2026. **Agente:** `performance-specialist` (h7-perf). **Base medida:** backend `aa3451f` (sin cambios de código hasta `8f70d18`: la fusión `fbb1901` del H6 solo trae documentación) y CRM `8536c0e`. **Sin commit** de este informe ni de los guiones nuevos (el lead confirma). **No he cambiado código de la aplicación:** cada hallazgo lleva su propuesta y su dueño.

## 1. Problema y objetivo
El contrato, fila PERF7, pide medir sin suponer:
- el p95 de las rutas del Studio (estado, publicaciones, evidencia del canario con acta, `integrations/status`, campañas con el espejo lleno, laboratorio);
- el coste del commit del motor con la llave en `off`/`canary`/`all`;
- los N+1 con `DB::listen`;
- los bytes de cada respuesta y los chunks y tiempos de `ng build`;
- y dejar presupuestos medibles para el smoke.

No había objetivo numérico previo. Los presupuestos de §7 lo fijan a partir de esta línea base.

## 2. Entorno y método
- **Máquina:** Apple M2, 8 núcleos, 8 GB, macOS 26.6.2, compartida con otros agentes. PHP 8.3.33 con OPcache activo en `cli-server` (comprobado) y JIT apagado, sin Xdebug. PostgreSQL 14 local (127.0.0.1:55432) con valores por defecto (`fsync` on).
- **Bases propias:**
  - `ultron_studio_h7_perf` (arnés en proceso; cada familia empieza con `migrate:fresh`);
  - `ultron_studio_h7_perf_http` (banco HTTP).
- **Como producción:**
  - caché `database` (limitadores y caché de permisos) y cola `database`;
  - en el banco HTTP, además, `APP_ENV=production`, `APP_DEBUG=false` y caché de configuración, rutas y eventos guardada en el directorio de estado, nunca en `bootstrap/cache`.
- **Lo que no es como producción:** `php artisan serve --no-reload` (php -S con 4 workers) en vez de nginx + PHP-FPM, sin TLS y sin red real.
- **Dos medidas complementarias:**
  - **Banco HTTP** (`serve.sh` + `http-bench.py`): GET secuenciales de extremo a extremo, cada uno con conexión nueva y cuerpo leído entero, a concurrencia 1. Es tiempo de servicio, no capacidad. El ritmo queda por debajo de los cupos: lecturas ≥ 115 ms entre salidas, rotando 8 sesiones de personas distintas; evidencia ≥ 2,1 s. Resultado: **0 respuestas 429 y 0 distintas de 200.**
  - **Arnés en proceso** (`PerfH7QueriesTest.php`, ejecutado con `run-queries.sh`): la petición pasa por el kernel HTTP de Laravel sin arranque por petición.
    - Una petición perfilada con `DB::listen` da sentencias, tiempo de BD por tabla y la sentencia más repetida.
    - Después, 5 de calentamiento y 3 tandas de 30 cronometradas con `hrtime`.
    - Los limitadores se miden en **estado estable**: una petición de cebado crea sus filas y, antes de cada medida, el contador se pone a 5. El primer golpe de cada ventana suma 4 sentencias más.
- **Repeticiones:** n = 30 × 3 tandas por ruta y tamaño, más 5 de calentamiento. Doy p50, p95, máximo y la dispersión de los p95 entre tandas.
- **Carga:** todas las medidas de aplicación se tomaron con la carga a 1 min entre 1,8 y 3,8, es decir, ninguna «bajo carga» según el umbral de 4. Excepciones y ruido en §8.
- **Datos sembrados** (referencias de producción de los informes del H6: `meta_ad_entities` 114 filas en 3 cuentas; `marketing_ai_actions` ~100 filas/día):
  - versiones: 1/50/200;
  - cadena: 1/50/200 entradas, todas firmadas (`verified=true` comprobado);
  - canario: 0/40/200/1 000 turnos (entrante + acción sellada + saliente) sobre un fondo de 20 000 acciones y 40 000 mensajes, unos 6 meses;
  - espejo de Meta: 0/114/500 campañas con 0/20/50 asociaciones activas, y 2 periodos cerrados por campaña;
  - laboratorio: 1/50/200 ejecuciones de 12 casos (una real con el conductor falso y copias).
  
  El banco HTTP usa el tamaño grande de todo a la vez: 203 versiones, cadena de 200, 200 turnos + fondo, 500/50 campañas, 50 ejecuciones y 3 canarios activos.

## 3. Línea base

### 3.1 Banco HTTP (de extremo a extremo; n = 90 por ruta = 3 × 30 tras 5; carga 2,0–3,1; todo 200)
| Ruta | Datos | p50 ms | p95 ms | máx ms | p95 por tanda | bytes |
|---|---|---|---|---|---|---|
| GET status | cabeza en canario, 2 partes | 52,2 | 55,7 | 65,3 | 55,6–64,6 | 1 839 |
| GET catalog | catálogo `2026-10-19.1` | 48,2 | 51,4 | 57,1 | 49,7–52,1 | 35 159 |
| GET draft | borrador guardado | 51,1 | 53,3 | 58,3 | 52,1–53,5 | 3 321 |
| GET rules | 12 reglas | 51,9 | 55,3 | 56,5 | 53,3–56,4 | 9 159 |
| GET versions (20) | 203 versiones | 53,2 | 55,9 | 57,8 | 55,4–56,2 | 13 597 |
| GET versions?per_page=50 | 203 versiones | 55,5 | 57,9 | 62,2 | 57,3–58,5 | 30 143 |
| GET versions/{uuid} | 1 entrada en la cadena | 52,1 | 54,8 | 57,1 | 52,9–55,3 | 10 308 |
| GET versions/{uuid} | 199 entradas (sin LIMIT) | 71,5 | 73,4 | 77,8 | 72,7–74,9 | 87 128 |
| GET publications (20) | cadena de 200 | 53,2 | 55,3 | 57,4 | 54,4–55,9 | 9 642 |
| GET publications?per_page=50 | cadena de 200 | 56,8 | 57,9 | 60,6 | 57,8–58,3 | 20 584 |
| **GET versions/{uuid}/canary-evidence** | **200 turnos + fondo** | **1 054,1** | **1 068,3** | **1 124,3** | 1 063,4–1 079,2 | 866 |
| GET audit | libro de la siembra | 50,3 | 57,1 | 81,3 | 54,1–68,4 | 10 139 |
| GET engine/readings | — | 53,9 | 57,0 | 72,1 | 55,3–57,5 | 1 317 |
| GET integrations/status | 3 cuentas, 500 campañas, 3 canarios | 60,0 | 62,7 | 64,0 | 62,6–63,5 | 1 328 |
| **GET integrations/meta/campaigns (20)** | 500 campañas / 50 activas | **108,3** | **111,1** | 114,5 | 110,5–113,9 | 11 419 |
| GET integrations/meta/campaigns?per_page=50 | ídem | 108,0 | 111,6 | 116,8 | 110,5–113,1 | 22 588 |
| GET …/campaigns?linked=yes&per_page=50 | ídem | 107,8 | 109,7 | 114,6 | 109,0–110,4 | 44 604 |
| GET integrations/meta/links (20) | 150 asociaciones | 55,1 | 59,2 | 71,9 | 57,7–69,3 | 13 388 |
| GET integrations/canaries | 3 activos | 52,6 | 55,8 | 65,8 | 55,2–56,6 | 4 107 |
| GET lab/status | 50 ejecuciones | 49,9 | 53,0 | 56,2 | 51,2–54,4 | 831 |
| GET lab/runs (20) | 50 × 12 casos | 53,9 | 57,0 | 60,4 | 57,0–57,2 | 15 703 |
| GET lab/runs?per_page=50 | ídem | 58,2 | 61,1 | 65,4 | 60,2–61,3 | 37 394 |
| GET lab/runs/{uuid} | 12 casos | 53,4 | 56,3 | 65,1 | 56,1–61,3 | 27 675 |

El suelo de ~48–55 ms es casi todo el arranque de Laravel en cada petición con php -S. La misma ruta mide 13–17 ms en proceso (§3.2), así que unos 35–40 ms son fijos del servidor de desarrollo. En producción con FPM ese coste existe, pero no se puede trasladar desde aquí.

### 3.2 En proceso, por tamaño (p50 / p95 en ms; n = 90; carga 2,3–3,8)
| Ruta | Pequeño | Medio | Grande | Sentencias | Bytes (pequeño → grande) |
|---|---|---|---|---|---|
| GET status (1/50/200 versiones) | 14,8 / 15,2 | 14,8 / 15,7 | 14,8 / 15,1 | 19 | 1 763 |
| GET catalog | 13,4 / 13,6 | 13,5 / 14,6 | 13,5 / 13,9 | 16 | 35 159 |
| GET draft | 14,9 / 15,3 | 15,0 / 15,4 | 15,0 / 15,9 | 18 | 3 321 |
| GET rules | 16,2 / 16,8 | 16,3 / 19,1 | 16,2 / 16,9 | 18 | 9 159 |
| GET versions (20) | 15,0 / 15,9 | 17,2 / 17,7 | 17,2 / 17,7 | 19 | 1 289 → 13 132 |
| GET versions?per_page=50 | 15,1 / 15,3 | 20,5 / 21,1 | 20,8 / 21,3 | 19 | 1 289 → 29 585 |
| GET audit | 15,2 / 15,6 | 15,2 / 15,8 | 15,3 / 16,4 | 18 | 6 912 |
| GET engine/readings | 16,9 / 17,4 | 17,1 / 17,5 | 17,0 / 17,6 | 24 | 1 269 |
| GET publications (20) (cadena 1/50/200) | 15,1 / 15,7 | 17,9 / 18,5 | 17,6 / 18,3 | 19 | 1 027 → 9 554 |
| GET publications?per_page=50 | 15,1 / 15,5 | 21,5 / 22,1 | 22,1 / 22,6 | 19 | 1 027 → 20 526 |
| GET versions/{uuid} con n entradas (1/50/200) | 15,6 / 16,0 | 20,9 / 21,5 | **36,6 / 37,4** | 19 | 10 307 → 29 280 → **87 506** |
| **canary-evidence** (0/40/200/1 000 turnos) | 30,1 / 31,1 (0) · 79,9 / 81,0 (40) | **1 041,6 / 1 049,8** (200) | **3 947,3 / 5 708,4** (1 000; máx 7 314) | 42–43 | 868 |
| GET integrations/status (0/114/500 campañas) | 22,4 / 23,5 | 22,4 / 23,6 | 22,0 / 22,9 | 34 | 1 326 |
| **GET integrations/meta/campaigns (20)** (0/0 · 114/20 · 500/50) | 19,7 / 20,7 | 37,3 / 38,7 | **73,6 / 76,8** | 28 → 33 | 1 265 → 11 338 |
| GET …/campaigns?linked=yes&per_page=50 | 19,9 / 20,7 | 37,4 / 38,6 | 75,0 / 76,5 | 27 → 33 | 1 265 → 44 907 |
| GET integrations/meta/links (20) | 14,3 / 15,3 | 20,1 / 21,1 | 20,0 / 20,7 | 17 → 21 | 693 → 13 530 |
| GET integrations/canaries / config / twilio / voice | 17,3–17,5 / 17,6–18,4 · 14,1–14,3 / 14,8–14,9 · 16,4–16,5 / 17,5–19,2 · 14,2–14,7 / 14,5–15,7 | | | 23 · 17 · 23 · 18 | 3 894 · 1 533 · 1 101 · 862 |
| GET lab/status (1/50/200 ejecuciones) | 14,9 / 15,5 | 15,7 / 18,6 | 26,7 / 27,2 ¹ | 19 | 834 |
| GET lab/runs (20) | 16,0 / 17,0 | 19,2 / 35,0 ² | 19,1 / 19,7 | 20 | 1 341 → 16 119 |
| GET lab/runs?per_page=50 | 16,1 / 16,6 | 23,6 / 24,4 | 24,4 / 27,7 | 20 | 1 341 → 37 024 |
| GET lab/runs/{uuid} (12 casos) | 17,9 / 18,4 | 18,0 / 18,5 | 18,0 / 19,5 | 20 | 27 659 |
| GET lab/scenarios / events | 15,0 / 16,6 · 15,5 / 15,8 | | | 17 · 18 | 11 222 · 6 809 |

¹ Artefacto de la siembra, no hallazgo. La consulta del gasto del día (`… where c.budget_day = ?`) suma los casos de HOY, y mis 200 copias comparten día. Producción tiene un tope de 20 ejecuciones al día, es decir, ≤ 240 casos.

² Una tanda ruidosa (23,1–38,7); la mediana no se movió.

### 3.3 El commit del motor (POST `internal/marketing/ai/commit`, decide → commit como n8n, en proceso, solo se cronometra el commit; n = 90 por celda)
| Llave / cadena | Juego 1 (carga 3,4–3,8) p50 / p95 | Juego 2 (carga 1,8–2,3) p50 / p95 | Sentencias | Del Studio |
|---|---|---|---|---|
| off / 0 · 1 · 50 | 41,3/45,7 · 38,1/55,2 · 38,3/44,6 | 46,1/50,3 · 45,5/48,9 · 45,3/51,0 | 93 | 0 |
| canary / 0 · 1 · 50 | 41,6/46,3 · 38,7/44,8 · 39,0/46,2 | 46,4/49,4 · 44,3/56,7 · 46,2/51,3 | 94 | 1 SELECT |
| all / 0 · 1 · 50 | 38,7/51,0 · 38,9/51,4 · 50,0/141,3 ³ | 45,0/48,8 · 47,2/51,3 · 45,1/49,6 | 94 | 1 SELECT |

³ La tanda tuvo interferencia (p95 por tanda 76–242). Por eso repetí el juego: en el juego 2 la celda da 49,6.

**El lector `UltronPublishedConfig::forConversation()` a pelo** (2 000 llamadas por celda):

| Caso | p50 | p95 |
|---|---|---|
| `off` | 0,003 ms | — |
| `canary` / `all`, en autocommit | 0,19–0,24 ms | 0,19–0,28 ms |
| dentro de una transacción (punto de guardado) | 0,23–0,34 ms | 0,27–0,71 ms |

Es igual con 0, 1 y 50 publicaciones. Su sentencia es `select "uuid", "seq", "engine_contract", "effective_hash", "signature", substr(effective_json, 1, 16385) … order by "seq" desc limit 1`, con 0,16–0,51 ms de BD dentro del commit y sin punto de guardado: en el commit corre fuera de transacción. No cachea, y es lo correcto: cada commit debe ver la cabeza de su momento.

**Conclusión:** el lector añade ~0,2 ms por commit, menos del 0,5 % de un commit de ~40–46 ms. La diferencia entre llaves no se distingue del ruido entre juegos (~±10 %).

### 3.4 CRM (`ng build`, configuración production)
- **Tiempo de pared:** 84,4 s / 54,5 s / 47,5 s; mediana 54,5 s. La primera corrida fue en frío. El build carga los 8 núcleos: la carga de 9–10 que se ve después la provoca él mismo.
- **Inicial:** 816 505 B crudos / 196 842 B gzip en 10 ficheros, de los que 253 762 B son CSS. Supera el **aviso** `initial` de 500 kB de `angular.json` (el error es 1 MB). No viene del Studio.
- **Chunks perezosos del Studio:**

| Chunk | Crudo | gzip |
|---|---|---|
| `ultron-studio-page` | 719 517 B | 183 312 B |
| `studio-lab-page` | 188 576 B | 53 403 B |
| `studio-integrations-page` | 162 459 B | 44 753 B |

- **Avisos de estilos:** `src/app/modules/ultron-studio/ultron-studio.scss` = **27,88 kB**, frente al aviso de 8 kB y al **error de build en 32 kB** de `anyComponentStyle`: margen de 4,12 kB (F-4).

## 4. Diagnóstico

### 4.1 N+1: no hay en el Studio (hecho comprobado)
**Ninguna ruta del Studio crece en consultas con los datos.** Las sentencias por ruta son las mismas con 1/50/200 versiones, entradas o ejecuciones, con 0→1 000 turnos del canario y con 0/114/500 campañas (§3.2). Hay ±1 por el `touch` de la sesión, que escribe como mucho una vez por segundo, y por la lotería de `cache_locks`.

**B7 `verified` no es N+1:** 1 consulta por lista con su versión precargada. Desglose con `tramos-verified.php`, 200 entradas firmadas:

| Tramo | Coste por entrada |
|---|---|
| `entryDto()` | 53,8 µs |
| `historyEntryDto()` | 101,3 µs |
| **`verified` (la diferencia)** | **≈ 47,5 µs** |
| de ello, la firma (`signatureFor`) | 14,8 µs |

Una página de 50 entradas suma ~2,4 ms. El detalle de la versión no tiene `LIMIT` (≈ 0,39 KB por entrada); con 200 entradas se va a 37,4 ms y 87,5 KB, pero la máquina de estados lo deja en ~3–4 entradas por versión. No requiere acción; el presupuesto de bytes de §7 lo vigila.

### 4.2 Coste fijo de cada petición (hecho comprobado)
Cada petición del Studio ejecuta:
- 10 sentencias de los **dos límites del throttle** sobre la caché `database`. En cada límite: lectura, dos `add` y `increment`, este con `SELECT … FOR UPDATE` + `UPDATE` en su propia transacción. Son **2 COMMIT por petición**.
- **4 relecturas de la caché de permisos** (`crm.role_permissions.v1`; `Cache::remember` va a la base en cada comprobación).
- 2 de autenticación.

Son **3,6 ms de los 5,1 ms de BD** (mediana) de una lectura ligera, ~24 % de sus 15,3 ms en proceso. La evidencia, con dos limitadores, gasta 7,1 ms. No es del Studio: afecta a toda ruta con limitador (F-3).

### 4.3 F-1: la evidencia del canario es cuadrática y sin tope (hecho comprobado)
`GET versions/{uuid}/canary-evidence`, y `promote`, que calcula la misma evidencia:

| Turnos del canario | Tiempo |
|---|---|
| 0 | 31 ms |
| 40 | 81 ms |
| 200 | **1 050 ms** (HTTP: p95 1 068 ms) |
| 1 000 | **3,9 s** de p50 y 5,7 s de p95 |

Familia G (n = 10): el acta `ultron:canary-report` es el 68 % del tiempo con 40 turnos, el **97 %** con 200 y el **99 %** con 1 000, con solo 4–12 ms de BD.

Dentro del acta (`tramos-acta.php`, carga ~3,6):

| T (turnos analizados) | M (mensajes desde `--since`) | consultas | `turnos()` | `revisar()` | JSON | total |
|---|---|---|---|---|---|---|
| 40 | 80 | 2,2 | 44,2 | 1,6 | 0,4 | 48,4 |
| 100 | 200 | 5,7 | 253,0 | 3,5 | 1,0 | 263,2 |
| 200 | 400 | 7,3 | **981,2** | 6,6 | 1,9 | 997,0 |
| 200 | 800 | 12,1 | **1 690,9** | 6,6 | 1,9 | 1 711,5 |
| 200 | 2 000 | 26,9 | **3 850,7** | 6,7 | 1,9 | 3 886,2 |

(tiempos en ms)

**Causa raíz** (falsable y medida): `UltronCanaryReport::turnos()` cuesta ~10–13 µs por par (turno × mensaje), O(T×M).
- Por cada entrante vuelve a filtrar todos los mensajes (`where` + `filter` sobre modelos Eloquent).
- Busca la acción recorriendo todas las acciones; `data_get($a->metadata, …)` decodifica el JSON en cada acceso.
- El comando carga **todos** los mensajes y acciones desde `--since`, sin límite. `--turns` (40–200) acota T pero no M, y M crece mientras la versión siga en CANARY.

**Decisión del lead:** hallazgo para `backend` en el H7, con tope de mensajes, una sola pasada y prueba de JSON idéntico byte a byte. Detalle en §5.

### 4.4 Campañas con el espejo lleno (hecho comprobado)
Desglose con `tramos-campanas.php` sobre la base del banco, en ms:

| Tramo | Sentencias | p50 | p95 |
|---|---|---|---|
| `connection()` | 10 | 4,8 | 5,3 |
| **`campaigns()`**: las 500 filas del espejo a DTO + orden, **antes** de paginar en PHP | 4 | **37,0** | 38,1 |
| asociaciones activas + versión con `content` | 2 | 3,4 | 3,5 |
| **`dtos()` de 50 asociaciones**: `integrityOk()` recalculado por asociación (0,26 ms) para solo 3 versiones distintas | 2 | **15,0** | 15,3 |
| `campaignsPage()` entero | 17 | 60,7 | 61,4 |

Cuadra con los +56 ms de la ruta en HTTP frente a las demás. Escala con el tamaño del espejo (~74 µs por campaña; con las 114 filas de producción, unos 8,5 ms, hipótesis lineal) y con las asociaciones activas (F-2).

### 4.5 El commit del motor (hecho comprobado)
El lector del Studio: §3.3. Aparte, cada commit hace **15 `Schema::hasTable()`**: consultas a `pg_class` sobre `marketing_knowledge_items` ×6, `classes` ×3, `trainers` ×3, `payment_transactions`, `marketing_conversations` y `marketing_ai_actions`. Suman **2,9–5,1 ms de BD por commit**, el 7–12 % del commit y 15–25 veces el lector del Studio. Es código del motor anterior al H7 (F-5).

## 5. Hallazgos y cambios propuestos (ninguno aplicado por mí)
| Id | Gravedad | Dueño | Qué | Propuesta | Ganancia esperada (hipótesis hasta medirla) |
|---|---|---|---|---|---|
| **F-1** | **Importante** (decidido: H7) | `backend` | Evidencia y `promote` O(T×M) en `app/Console/Commands/Marketing/UltronCanaryReport.php::turnos()`, y carga sin tope desde `--since` | Ver los cuatro puntos bajo la tabla | Con 200 turnos / 400 mensajes, `turnos()` de ~1 s a < 20 ms y la evidencia de 1 050 ms a ~50–80 ms en proceso. A medir con `run-queries.sh test_c`/`test_g` y `tramos-acta.php`; criterio en §6 |
| F-2 | Menor | `backend` | Campañas: `integrityOk()` por asociación sin memoizar en `StudioCampaignLinks::dtos()`/`versionRef()`; DTO de todo el espejo antes de paginar | (a) memoizar `integrityOk` por `version_id` dentro de una llamada a `dtos()`; (b) solo si el espejo pasa de ~1 000 filas, filtrar, ordenar y paginar en SQL | (a) −~12 ms con 50 activas / 3 versiones (47 de 50 cálculos sobran); (b) hoy no compensa (~8,5 ms estimados con 114 filas) |
| F-3 | Menor, transversal | `backend` (a) y `devops` (b) | Coste fijo por petición sobre la caché `database` (§4.2) | (a) memoizar `RolePermissionPolicy` dentro de la petición (−3 sentencias); (b) evaluar `cache.limiter` en un almacén compartido sin transacciones de BD (Redis si existe; `file` vale con un solo servidor; `array` NO, porque no se comparte entre workers) | (a) ~1 ms por petición; (b) hasta −10 sentencias y −2 COMMIT por petición, 3–4 ms en local. Afecta a todas las rutas con limitador: medir antes y después; cambio de infraestructura, no del Studio |
| F-4 | Menor (riesgo de build) | `frontend` | `ultron-studio.scss` en 27,88 kB con el error de build en 32 kB (4,12 kB de margen); inicial en 816,5 kB > aviso de 500 kB (previo, no del Studio) | Vigilar con el presupuesto de §7.3; si hay que añadir estilos al Studio, repartirlos antes en hojas por panel | Evita un build de producción roto |
| F-5 | Observación (motor, fuera del H7) | `backend` con la puerta del motor | 15 `Schema::hasTable()` por commit (2,9–5,1 ms) | En un hito futuro: memoizar por proceso o quitar las guardas de tablas que siempre existen | ~3–5 ms por commit; queda fuera del H7 por el contrato (no tocar el motor) |

**F-1, propuesta para backend:**
1. Una sola pasada: indexar las acciones una vez por `source_event_id` y por `metadata.message_id`, decodificando cada `metadata` una sola vez, y repartir los salientes al recorrer los mensajes en orden de id, cortando en T.
2. Tope de mensajes **conservando la semántica actual**. Hoy el acta analiza los **primeros** T turnos desde `--since`, no los últimos: con más de 200 turnos describe los 200 más antiguos de la ventana. Eso hay que mantenerlo o decidirlo aparte. La propuesta es leer solo hasta el id del entrante T+1 (dos consultas acotadas).
3. Prueba dorada: JSON idéntico byte a byte con varios conjuntos (turnos sin acción, varios salientes por turno, acción encontrada por `metadata.message_id`, corte de `--since`, T < y > número de turnos).
4. La puerta de diff del motor cubre `app/Services/Marketing`, `app/Jobs`, `Api/Internal`, `routes/{marketing,api}.php` y `config/marketing.php`. Este fichero queda fuera de esas rutas, pero es la herramienta del motor y el dueño debe saberlo.

**No son hallazgos:**
- `verified` (§4.1);
- el crecimiento de `lab/status` (artefacto ¹);
- el detalle de versión sin `LIMIT` (acotado por la máquina de estados; presupuesto de bytes).

## 6. Medición posterior
No hay: no apliqué ningún cambio.

**Criterio de aceptación propuesto para F-1**, medido con este arnés en esta máquina y en las mismas condiciones:
- `turnos()` ≤ 20 ms con T = 200 / M = 2 000 (`tramos-acta.php`);
- evidencia en proceso: p95 ≤ 100 ms con 200 turnos y ≤ 150 ms con 1 000 (`run-queries.sh test_c` con `PERF_N=30`);
- prueba dorada en verde;
- las sentencias de la ruta sin cambiar (42–43).

Si no llega, se revierte.

## 7. Presupuestos (fichero máquina: `ops/perf-h7/presupuestos-h7.json`)

### 7.1 Para `smoke-h7.py` (OPS7 los carga; yo no toco su fichero)
- **Método:** por ruta, 1 GET de calentamiento y 5 cronometradas en serie, separadas ≥ 0,6 s; la evidencia, 1 petición de calentamiento y 3 muestras separadas ≥ 6,5 s (el calentamiento lo añadió devops el 02-11-2026, decisión 4: en frío la primera petición decidía el máximo, 231,9 frente a 80,9 ms) y solo si hay versión en CANARY.
- **FALLO** si la mediana supera el p50, si el máximo supera el p95, si los bytes superan el máximo o si el estado no es 200. **OMITIDO** si falta lo que la ruta requiere (laboratorio apagado o sin CANARY).
- El smoke se ejecuta en el propio servidor, por nginx y TLS sin internet de por medio.
- **Los p50/p95 son techos iniciales** (≈ 3–4 veces el p95 local), no medidas de producción. La primera pasada en producción deja la línea base real; después OPS7 ajusta cada techo a max(1,5 × p95 de producción, p95 + 50 ms).

| Ruta | p50 ≤ ms | p95 ≤ ms | bytes ≤ | Local p95 / bytes |
|---|---|---|---|---|
| status, catalog, rules, audit, engine/readings, integrations/status, links, canaries, lab/status | 150 | 250 | 4 KB (status, engine/readings, integrations/status) · 48 KB (catalog) · 32 KB (rules) · 24 KB (audit, links) · 8 KB (canaries) · 2 KB (lab/status) | 51–63 ms |
| draft | 150 | 250 | 80 KB (el borrador admite 64 KB) | 53,3 / 3 321 |
| versions (20) | 150 | 250 | 24 KB (si saliera `content`, ~10 KB por versión) | 55,9 / 13 597 |
| versions/{primera} | 150 | 250 | 96 KB | 54,8 / 10 308 |
| publications (20) | 150 | 250 | 16 KB (si saliera `effective_json`, se dispara) | 55,3 / 9 642 |
| **canary-evidence** | 2 000 | **2 500**; **400 tras F-1** | 2 KB | 1 068 / 866 |
| integrations/meta/campaigns (20) | 250 | 400 | 20 KB | 111,1 / 11 419 |
| lab/runs (20) · lab/runs/{primera} | 150 | 250 | 24 KB · 40 KB | 57,0 · 56,3 |

### 7.2 Para CI / TEST7 (no se pueden medir desde el smoke: no hay `DB::listen` en producción)
- **Regla 1.** Por ruta, las sentencias con los datos pequeños y con los grandes son las mismas (±1).
- **Regla 2.** Máximo = medido + 2, con `CACHE_STORE=database` y limitadores en estado estable. Con `array`, restar 14 (restar 24 en la evidencia).

| Ruta | Máximo |
|---|---|
| status | 21 |
| catalog | 18 |
| draft | 20 |
| rules | 20 |
| versions | 21 |
| versions/{uuid} | 21 |
| publications | 21 |
| audit | 20 |
| engine/readings | 26 |
| canary-evidence | 45 |
| integrations/status | 36 |
| meta/campaigns | 35 |
| meta/links | 23 |
| canaries | 25 |
| config | 19 |
| twilio | 25 |
| voice | 20 |
| lab/status | 21 |
| lab/runs | 22 |
| lab/runs/{uuid} | 22 |
| lab/scenarios | 19 |
| lab/events | 20 |

- **Regla 3.** Commit: con `off`, 0 sentencias nombran `ultron_studio_*`; con `canary`/`all`, exactamente 1 `SELECT`, sin punto de guardado, igual con 0/1/50 publicaciones. Ya lo fija `UltronCommitStudioStampTest`.
- **Regla 4.** Lector a pelo: p95 ≤ 1 ms. En local es 0,28 ms, o 0,71 ms dentro de una transacción.

### 7.3 CRM (build de production)
| Elemento | Medido | Techo propuesto |
|---|---|---|
| Inicial | 816,5 kB / 196,8 kB gzip | ≤ 860 kB / ≤ 207 kB gzip («no crecer sin decisión», +5 %) |
| `ultron-studio-page` | 719,5 kB / 183,3 kB gzip | ≤ 755 kB / ≤ 193 kB gzip |
| `studio-lab-page` | 188,6 kB / 53,4 kB gzip | ≤ 198 kB / ≤ 56 kB gzip |
| `studio-integrations-page` | 162,5 kB / 44,8 kB gzip | ≤ 171 kB / ≤ 47 kB gzip |
| `ultron-studio.scss` | 27,88 kB | < 32 kB (error de build) |

El tiempo de `ng build` (mediana 54,5 s) es informativo, no presupuesto.

## 8. Qué medí, bajo qué carga, y qué no pude verificar
**Ejecutado de verdad** (resultados en `ops/perf-h7/resultados/`):
- `run-queries.sh test_a…test_f` con `PERF_N=0` (consultas) y con `PERF_N=30 PERF_WARM=5 PERF_REPS=3` (tiempos), todo «OK»; `test_f` repetido (juego 2);
- `test_g` con `PERF_N=5` y con `PERF_N=10`;
- `tramos-acta.php`, `tramos-campanas.php` y `tramos-verified.php` (200/200 entradas `verified=true`);
- `serve.sh seed/start/bench/stop`: 23 rutas × 95, todas 200, puerto libre al parar;
- `build-crm.py` con 3 corridas, todas con salida 0.

**Carga:**
- Banco HTTP: 2,0–3,1.
- En proceso: 1,8–3,8.
- Los builds generan su propia carga (~10).
- **Ninguna cifra queda «bajo carga»** según el umbral de 4.

**Ruido señalado:**
- commit juego 1, celda `all/50` (repetido: limpio en el juego 2);
- evidencia con 1 000 turnos: p95 por tanda 3 979–6 739 ms, con el p50 estable en 3,9 s;
- campañas `per_page=50` con 500/50: una tanda con p95 130,6 frente a 75;
- `lab/runs (20)` con 50: una tanda con p95 38,7.

**No verificado y por qué:**
- **Producción:** no lancé carga contra producción (prohibido). Los techos del smoke son hipótesis a calibrar.
- **Plataforma:** nginx + PHP-FPM + TLS y la CPU del VPS (Linux) no se reproducen aquí.
- **Concurrencia:** todo fue secuencial. La página del Studio lanza 5 GET en paralelo y eso no está medido.
- **Memoria y RSS:** sin medir.
- **Datos:** los tamaños de producción se sembraron por referencia (114 entidades de Meta, ~100 acciones al día).
- **F-5:** no es del Studio; su coste solo está medido en tiempo de BD.

## 9. Siguiente cuello de botella
- **Tras F-1,** la evidencia quedaría en ~30 ms en proceso (el caso de 0 turnos) y la dominaría el coste fijo: sus dos limitadores, 20 sentencias + 4 de permisos (F-3).
- **En campañas,** el DTO de todo el espejo (F-2b) solo compensa si el espejo crece mucho.
- **En el resto de rutas** no hay nada que compense atacar: 13–17 ms en proceso, sin crecimiento con los datos.

## 10. Riesgos, deuda y decisiones pendientes
- **F-1:** decidido por el lead para `backend` en el H7. El riesgo está en la corrección (la semántica de los «primeros T turnos» y el JSON idéntico), no en el rendimiento.
- **F-2, F-3 y F-5:** decisión de cada dueño. F-3b es infraestructura: propuesta con su coste, sin acción.
- **OPS7:** incorporar `presupuestos-h7.json` al smoke y calibrar los techos con la primera pasada en producción.
- **TEST7:** convertir §7.2 en prueba de regresión de rendimiento. El arnés `PerfH7QueriesTest.php` sirve de base, pero vive fuera de `tests/` a propósito: hace `migrate:fresh` en su propia base.
- **Deuda que introduzco:** ninguna en la aplicación. Los guiones viven en `ops/perf-h7/`.
- **Bases desechables:** `ultron_studio_h7_perf` y `ultron_studio_h7_perf_http` quedan en el PG local. Las sesiones del banco caducan solas en 12 h.

## Ficheros (todos sin commit salvo el WIP `8f70d18` que hizo el lead)
- `docs/ultron-studio/plans/informes-h7/informe-performance.md` (este informe)
- en `docs/ultron-studio/ops/perf-h7/`:
  - `PerfH7QueriesTest.php` — arnés de consultas, bytes y tiempo en proceso, familias A–G
  - `PerfH7Seeding.php` — siembra común
  - `PerfH7SeedHttpTest.php` — siembra de la base del banco HTTP
  - `run-queries.sh` — lanzador del arnés: base propia, caché/cola `database`, sin pisar `junit.xml`
  - `serve.sh` — servidor local del banco: seed, start, stop, status, bench
  - `http-bench.py` — banco HTTP secuencial con ritmo por debajo de los cupos
  - `medir-todo.sh` — la tanda completa en serie
  - `espera-carga.sh` — vigilante de carga
  - `build-crm.py` — `ng build` ×N y tamaños crudo/gzip
  - `resumen.py` — tablas en Markdown de los JSON
  - `tramos-acta.php`, `tramos-campanas.php`, `tramos-verified.php` — desgloses
  - `presupuestos-h7.json` — presupuestos máquina: smoke, CI y CRM
  - `resultados/*.json` — datos crudos: `*-consultas.json`, `*-tiempos.json`, `f-commit-tiempos-juego1.json`, `http-tanda1.json`, `crm-build-20261102T045341Z.json`, `a-pagina-consultas-primer-golpe.json`
