# Hito 7 · Informe de devops (OPS7): monitor, costes, migración sin root, supresión 1581, respaldos, despliegue y recuperación

**Fecha:** 01/02-11-2026. **Rama:** `feat/ultron-studio-h7`, en el worktree `_worktrees/ultron-h7`.

**Base de los ensayos:**
- `167e34c`: la confirmación en curso de OPS7, con DB7 y B7 en curso;
- `aa3451f`: DB7 y B7 completos;
- `ee9f128`: la tanda del 02-11 que cierra las discrepancias de DOC7 y la T-1 del tester (§4.9), con F-1 y F-2 ya confirmados;
- `0d43401`: la m-1 del reviewer del H7 (`schedule:list` con su código de salida aparte y `TAREAS` exigido; §4.10).

Entre las dos primeras, el código solo difiere en `AdminUserController` (+29 líneas). La migración del H7 es idéntica en las tres.

**Entorno de todo lo hecho:** local, en el Mac. **No es producción:** sin SSH, sin peticiones al servidor y sin commit (lo hace el lead).

## 0. En una pantalla

| Entrega | Resultado real |
|---|---|
| 1. Monitor con bandera | **12/12 pruebas en SQLite y en la puerta PG** (135 aserciones). `UltronEngineIsolationTest`: 7/7. `schedule:list` suma **2** entradas solo con la bandera encendida (26 → 28 en las pruebas; 27 → 29 en el banco: el total depende de otras banderas, H-10). Pint en verde. **Mando** `h7-monitor-scope.sh`: **16/16** en el banco, ida y vuelta, el fichero de entorno idéntico byte a byte al volver (§3, §4.9). **m-1:** `schedule:list` con su código de salida aparte en el despliegue, el smoke y el mando; el total, exigido contra `TAREAS` (pendiente hasta leerlo en el servidor) en el despliegue y el smoke, y el mando con su salto de ±2: **21/21** y, tras dejar el mando solo con el código de salida, **11/11** en el banco (§4.10) |
| 2. Runbook de costes | §6 |
| 3. `ironbody-migrate` sin root | **21/21** en el banco (§4.3). H-5 de SEC7 corregido. `.env` escribible → rechazo. `find -L`. Guarda contra `PG*`/`LD_*` |
| 4. Supresión Ley 1581 | **17/17** en el banco, con el dueño **sin superusuario**; tras redactar `reason`, la cadena sigue verificando (§4.4). **Runbook: §10 «Supresión»** (huella del teléfono sin imprimirla, en seco → real, comprobaciones tras el COMMIT y registro); sus órdenes, ensayadas en el banco |
| 5. Respaldos | Política (§7). Ensayo `-Fc`: **14/14**, recuento exacto de las **226** tablas, RTO 1 s. **Hallazgo H-6:** `--no-owner` (§4.5) |
| 6. Scripts del H7 | Despliegue en el banco con un 55P03 real: **`DESPLIEGUE H7 (BACKEND) COMPLETADO`** cuatro veces (50 s, 47 s, 45 s en `ee9f128` y 45 s en `0d43401` con `TAREAS=27` exigido antes y después). Smoke con los **presupuestos de PERF7**: recién desplegado **36 OK · 0 FALLO · 3 OMITIDO** (código 3: sin versiones, sin CANARY y sin ejecuciones); con esos datos, **41 OK · 0 FALLO · 0 OMITIDO** (las 17 rutas medidas); con dos techos apretados en una copia del JSON, **2 FALLO** (código 1) sin tocar código (§4.9) |
| 7. Recuperación | PG caído o cortado durante el commit: **11/11**. Worker del laboratorio muerto: **10/10**. Vuelta atrás por niveles: **13/13** (§4.6–4.8) |

## 1. Ficheros (exclusivos de OPS7, salvo los dos señalados)

- **Código:**
  - `app/Console/Commands/UltronStudio/MonitorCommand.php`;
  - `config/ultron_studio.php`, solo el bloque `monitor`;
  - `routes/console.php`: +35 líneas, 0 borradas.
- **Pruebas** (las pidió el lead; fuera de la lista del contrato): `tests/Feature/UltronStudio/UltronStudioMonitorTest.php`, 12 pruebas.
- **`.env.example`** (lo pidió el lead): las dos variables del monitor.
- **`ops/`:**
  - `deploy-h7-backend.sh`, `h7-schema-check.sql`;
  - `smoke-h7.py`, `smoke-run-h7.sh`;
  - `h7-monitor-scope.sh` (tanda del 02-11: el mando del monitor);
  - `ironbody-migrate-propuesta.sh`;
  - `supresion-1581.sql`.
- **`ops/banco-h7/`:**
  - `README.md`, `comun.sh`, `montar.sh`, `preparar.sh`, `ensayar.sh`, `sembrar.sh`, `sembrar-1581.php`, `lector.php`;
  - `sembrar-presupuestos.sh` y `sembrar-presupuestos.php` (tanda del 02-11: el estado para medir las 17 rutas de los presupuestos);
  - `ensayo-supresion.sh`, `ensayo-migrador.sh`, `ensayo-restauracion.sh`, `ensayo-recuperacion.sh`, `ensayo-vuelta.sh`.
- **`ops/README.md`:** solo la fila de `h7-monitor-scope.sh` (el resto es de DOC7).
- **Informe:** este.

## 2. Monitor (entrega 1)

- **`ULTRON_STUDIO_MONITOR_ENABLED`:** con `filter_var`, `false` por defecto.
- **`ULTRON_STUDIO_MONITOR_EXPECT`:** vacía, la del código: `twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED`. Mal escrita, sale `expectation_invalid`.
- **`ultron-studio:monitor [--check=publication|integrations|queues] [--dry-run] [--json]`:**
  - aplica las reglas de `publication-check` y de `integrations-doctor --expect` sobre los mismos servicios, más la deriva de la configuración autorizada;
  - las colas, con el criterio de `queue:health`;
  - una prueba de paridad compara su veredicto con el de los comandos.
- **Por qué no lanza los otros comandos:** `Artisan::call` pisaría la salida de quien lo llama, y el arnés de pruebas de Laravel les cambia la salida.
- **Códigos:** 0 si cuadra · 1 si no cuadra · 2 si se usa mal.
- **Incidentes:** con `IncidentRecorder::record()` (el patrón de `iron-guard:scan`), fuente `ultron_studio`:
  - `publication_drift` (alto);
  - `integrations_drift` (alto);
  - `monitor_check_failed` (medio).
  - Las colas no abren incidente propio: ya lo hace `iron-guard:scan`.
  - Sin notificaciones externas.
- **Programación** (solo con la bandera encendida):
  - `ultron-studio:monitor` cada 30 min: decisión D-1, **aceptada por el lead**, por la ventana de 60 min de IRON GUARD;
  - `ultron-studio:integrations-snapshot` a las 07:10 de Bogotá;
  - las dos con `withoutOverlapping(10)` y `onOneServer()`.
- **El mando:** la bandera se enciende y se apaga con `ops/h7-monitor-scope.sh` (§3), nunca a mano.
- **Detalle de motor:** en PostgreSQL, `incidents.evidence` es `jsonb` y reordena las claves de cada objeto (`id`, `actual`, `expected`). Lo cazaron mi puerta PG y la de B7. La prueba compara **con las claves ordenadas en los dos lados y `assertSame`**: mismos valores, mismos tipos, y el orden de las listas cuenta. Pasa aislada en PG (10 aserciones) y en el fichero entero de PG y de SQLite (12 pruebas, 135 aserciones).

## 3. Scripts del despliegue del H7 (entrega 6)

### `deploy-h7-backend.sh <commit> <respaldo> [--solo-comprobar]`

Calca el del H6.

- **`PREVIO`:** con `pendiente` se niega a todo, también a `--solo-comprobar`. El lead lo fijó en **`1e5f9a0`** (HEAD de producción del 02-11-2026, fase 1 del H6) en `1c76a7f`, que además exige siempre `PREVIO` en `api-commit.txt` (m-6).
- **`TAREAS`** (m-1 del reviewer del H7): las entradas de `schedule:list` en producción con el monitor apagado. Depende de las banderas del servidor: 26 en las pruebas y 27 en el banco, así que no se puede escribir de antemano y llega como `pendiente`.
  - Con `pendiente`, el despliegue de verdad se niega (código 2). `--solo-comprobar` lo lee, lo imprime y acaba en **`COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=N`** (código 0, sin «EN VERDE»).
  - Se fija con el mismo valor en `deploy-h7-backend.sh` y `smoke-run-h7.sh`, en un commit, y se vuelven a copiar los dos. `h7-monitor-scope.sh` no lo usa: comprueba el salto de ±2.
  - Con el monitor apagado el H7 no cambia el programador (`console.php` solo añade el bloque del monitor, tras su bandera: 35 líneas, 0 borradas desde `1e5f9a0`), así que es el total de antes **y** el de después.
- **Paso 0, de solo lectura:**
  - checkout limpio, avance rápido y exactamente 1 migración;
  - **puerta de diff con lista explícita**: los 7 controladores de M-2 y `AdminUserController` (B7), la API interna de conocimiento (SEC7) y `console.php`, que solo puede añadir líneas;
  - respaldo de menos de 2 h, en 700/600, con `.sha256` y **≥ 226 tablas**; `.env` igual al respaldo;
  - `ULTRON_STUDIO_MONITOR_EXPECT` (no se escribe nunca): ausente o vacía, como en `.env.example`, rige la del código y no se avisa. Lo decide Dotenv, el analizador de Laravel, con las reglas de `env()` (`empty`/`null`/`false` la vacían) y el `trim()` del bloque `monitor`, sin imprimir el valor: «presente y vacía», «escrita con la misma expectativa que el código» o **«AVISO: … tiene valor»**, porque entonces el paso 7 lo contará como desvío. Hasta el 02-11 avisaba también con la clave vacía (discrepancia 4 de DOC7);
  - esquema `43 49 4 14 iron iron,iron,iron true 1 0`, huella de F, **cadena consecutiva** (si no, la migración se negaría) y 0 cerrojos sobre la cadena;
  - 56 rutas, 13/0 workers y facturación activa;
  - **el programador**, con `schedule:list --json` y su código de salida aparte: si no sale con 0, aborta. Sin base o sin caché lanza una QueryException sobre `cache_locks` y la salida queda vacía; el `grep -c 'ultron-studio:'` de antes la contaba como «0 tareas del Studio» (m-1). Exige 0 tareas del Studio y, con `TAREAS` fijado, exactamente `TAREAS` entradas (si no, aborta con los dos números).
- **Después:**
  - merge, con lectura por `www-data` de cada fichero;
  - `ULTRON_STUDIO_MONITOR_ENABLED=false` ANTES de migrar, y Dotenv;
  - `ironbody-migrate` con reintento del 55P03 (3 intentos);
  - compuerta **44/50/4/14**, disparadores de la cadena `uspub_chain_next:O…`, `CHECK ((seq >= 1))`, función `iron/false` y 0 disparadores apagados;
  - privilegios de `iron_app` uno a uno: `ar` en libro, instantáneas y cadena, `arwd` en asociaciones y canarios; filas sin cambio;
  - `route:cache`. El `chmod` de las cachés lo hace `www-data` y nunca sobre un enlace (H-5);
  - `queue:restart`.
- **Aserciones:**
  - 56/18/8/10 y cobertura vacía;
  - monitor apagado y el programador en **`TAREAS 0`**: `schedule:list` sale con 0 (si no, desvío: m-1), `TAREAS` entradas y ninguna del Studio; motor, H4, H5 y H6 iguales;
  - 13/0 dos veces;
  - **`curl -I` → `Cache-Control: no-store, private`** en 3 rutas del Studio, `auth/me` y un 404;
  - **70 000 bytes sin sesión → 413**;
  - `usage-trigger` 403, colas fallidas, sellos, `check-secret-exposure`, `publication-check`;
  - **`monitor --dry-run` 0** y 0 incidentes nuevos;
  - `.env` igual al respaldo salvo la clave;
  - el doctor;
  - **`integrations-snapshot` sin anotar nada** (el H7 no cambia ninguna integración);
  - 0 ERROR.

### `h7-schema-check.sql`

De solo lectura, por la entrada estándar. Recuentos, cadena, función, dueños, rol de la aplicación, integridad de la cadena (`rotas`), cerrojos y privilegios uno a uno, por columna. Cada columna lleva lo esperado.

### `h7-monitor-scope.sh on|off <estado actual> | estado` (tanda del 02-11, discrepancia 3 de DOC7)

El mando de `ULTRON_STUDIO_MONITOR_ENABLED`, calcado de `h6-integrations-scope.sh`. Hasta ahora el runbook hablaba de un `set_var` que solo existía dentro de los mandos del H5 y del H6.

- **Uso:** `on off` enciende, `off on` apaga (la vuelta atrás de nivel 2), `estado` solo lee. Sin el estado actual, o si no coincide con la configuración efectiva, no toca nada (código 2 o `ABORTA`).
- **Antes de escribir:** la clave no está repetida; `schedule:list` sale con 0 (si no, aborta: m-1) y el programador cuadra con la bandera (2 tareas del Studio encendido, 0 apagado); el fichero de entorno **en 600** (si no, aborta); copia previa en `/root/env-pre-h7-monitor-<valor>-<fecha>` (600, root).
- **Escribe** la bandera con la sangría que tuviera la línea, sobre el **mismo fichero** (mismo inodo, dueño y modo; sin `sed -i`).
- **Después:**
  - el fichero parsea (Dotenv) antes de tocar la caché;
  - `config:cache` como www-data con `umask 077`; la caché, de www-data, en 600 y nunca un enlace (el `chmod`, si hace falta, lo hace www-data: H-5);
  - la configuración efectiva por tinker es la de antes con solo la bandera cambiada; motor, H4, H5 y H6 iguales;
  - `schedule:list --json`, con su código de salida aparte (si no sale con 0, desvío: m-1): el total cambia en **exactamente ±2** y las tareas del Studio pasan a 2 (encendido) o 0 (apagado). No compara con un 26/28 fijo: el total depende de las demás banderas del servidor (H-10);
  - `queue:restart` (como el mando del H6: los workers cargan la caché nueva mientras se mira), 13 RUNNING y 0 BACKOFF/FATAL dos veces, y 401 sin token.
- **Al encender, informativo** (no cambia el veredicto): el monitor en seco sobre la publicación y las integraciones, y quién ejecuta `schedule:run` en el crontab (H-1). Termina indicando la primera pasada real, que es el paso siguiente del runbook (§5, paso 10).
- **Veredicto:** `MANDO monitor → on APLICADO (copia previa: …)` solo con 0 desvíos. Con alguno, `ESCRITO CON N DESVÍOS`, código 1 y la orden exacta para volver (restaurar la copia previa, `config:cache`, `chmod` como www-data y `queue:restart`). Nunca imprime valores del fichero de entorno.

### `smoke-run-h7.sh` y `smoke-h7.py`

Cuenta 32, contraseña de un solo uso, huellas saladas, trap. Usa el detector de fugas de `smoke-h6.py` y los presupuestos de `perf-h7/presupuestos-h7.json`, así que se copian los cuatro.

- **El lanzador comprueba:** que el JSON de presupuestos existe y **es válido con el mismo código del smoke, antes de activar la cuenta** (si no, código 2 y la cuenta sin tocar; con `python3 -B`, para que importar `smoke-h7.py` no deje `__pycache__` en `/root`). Comprueba también `TAREAS` fijado (si no, código 2 sin tocar la cuenta; `SMOKE_TAREAS` lo sustituye en un ensayo) y monitor apagado. Exige `schedule:list` con código 0: si falla, FALLO y no se ejecuta el smoke (m-1). Pide además el programador en `TAREAS 0`, 14 tablas idénticas, 0 incidentes y que el token muera. Al salir borra de `/root` sus copias, también la del JSON.
- **`smoke-h7.py` comprueba:**
  - `no-store` en todas las respuestas de `/api/admin`, también las de la fase de presupuestos;
  - 413 sin sesión y con sesión, y 401 por debajo del tope;
  - `verified` por entrada y en el detalle de versión, y que el de la cabeza es igual a `signatureOk`;
  - 5 invisibles → 422, con 2 contrapruebas: texto limpio y un emoji con ZWJ y VS16 → 409 de la bandera;
  - **los presupuestos de rendimiento** (tanda del 02-11, discrepancia 1 de DOC7), exactamente como dice `informe-performance.md` §7.1:
    - por cada ruta del JSON, `warmup` GET sin contar y `n` cronometradas (`perf_counter` alrededor de la petición: conexión nueva y cuerpo leído entero), en serie y separadas al menos `minIntervalSeconds`. Por defecto 1 + 5 a ≥ 0,6 s; la evidencia del canario, 0 + 3 a ≥ 6,5 s;
    - **FALLO** si la mediana supera `p50Ms`, si el máximo supera el techo p95, si el cuerpo supera `maxBytes` o si el estado no es 200. El techo p95 es `p95MsAfterF1` cuando la ruta lo trae (la evidencia: **400 ms**, porque F-1 está en el código del H7 desde `e2411e3`) y `p95Ms` si no;
    - **OMITIDO** si falta lo que pide `requires`: sin versión en CANARY (la evidencia), sin versiones, laboratorio apagado (409 `lab_disabled`) o sin ejecuciones. Cuenta como OMITIDO, así que el código es 3;
    - un FALLO **solo de tiempo** se mide otra vez, una sola, y vale la repetición: con 5 muestras el «p95» es el máximo y un pico basta (`calibration` del JSON);
    - cada ruta imprime la propuesta de techo para recalibrar, `max(1,5 × medida, medida + 50 ms)`: el p50 con la mediana y el p95 con el máximo. Es mi lectura de la regla del JSON, que solo nombra el p95: **PERF7 debe confirmarla**;
    - las rutas del JSON se validan: solo GET a `/admin/ultron-studio/…`, sin `..` ni consulta, y solo los marcadores `{canary}`, `{firstVersion}` y `{firstRun}`, cuyo uuid resuelto se valida antes de usarlo. Un JSON manipulado no convierte el smoke en otra cosa;
    - el ritmo nunca pasa de **90 lecturas con sesión en ningún minuto** (el cupo es 120/min por credencial; si hace falta, el smoke espera) ni de 6 evidencias por minuto (cupo 10). La pasada entera dura 70–80 s;
    - los cuerpos de esta fase no entran en el detector de fugas: son las mismas lecturas y su asunto es el tiempo;
  - sin fugas, sin 5xx y sin 429.
- **Recalibrar sin tocar código:** los techos solo viven en el JSON. Tras la primera pasada en producción (con `SMOKE_BUDGETS_OUT=/root/<fichero>.json`, que deja en 0600 las medidas y la propuesta de cada ruta), se editan `p50Ms`/`p95Ms` en el JSON del repo (en la evidencia también `p95MsAfterF1`, que manda mientras exista), se confirma y se vuelve a copiar con su huella. El smoke imprime la ruta, la versión y el sha256 del JSON que usa.
- **Sin filas positivas:** solo envía lo que el servidor rechaza antes de escribir; la fase de presupuestos solo hace GET.

## 4. Ensayos en el banco (resultados reales)

### 4.1 Despliegue

**Banco 1** (`167e34c`):
- **Paso 0:** `COMPROBACIONES PREVIAS EN VERDE`.
- **Con `--55p03`:**
  - intento 1: `2026_11_02_100001 … 5s FAIL` (55P03 a los 5 s: **el `lock_timeout` llega a la migración**);
  - reintento a los 15 s: `24.50ms DONE`;
  - después: 44/50/4/14, privilegios correctos e instantáneas **3 → 3**;
  - 0 ERROR y **COMPLETADO** en 50 s.
- **Fidelidad del banco:** el primer ensayo dio 1 desvío, la instantánea 0 → 3, porque el banco no imitaba el paso 11 del H6. Corregido en `montar.sh`.

**Banco 2** (`aa3451f`): **COMPLETADO** en 47 s, también con el 55P03.

**La puerta funciona:** con `BANCO_WIP=1`, cazó `AdminUserController.php`, que entonces era trabajo en curso de B7. Luego B7 lo confirmó (`aa3451f`) y lo añadí con su paquete.

### 4.2 Smoke

| Estado del banco | Resultado |
|---|---|
| Cadena vacía | **22 OK · 0 FALLO · 0 OMITIDO** |
| Con 1 entrada (redactada) | **23 OK**: `verified true` = `signatureOk` |

En las dos pasadas: 23 respuestas con `no-store` y 22 cuerpos sin fugas, con el centinela de 12 huellas saladas.

### 4.3 Propuesta de `ironbody-migrate`: 21/21

- **Modos:** `--check` → 0 (326 migraciones `Ran`) · `--pretend` → `Nothing to migrate` · `--status` → 0.
- **Marcador plantado** en `services.php` y `packages.php` de `bootstrap/cache`:
  - un artisan normal (lo que hace hoy el envoltorio como root) **lo ejecuta**: `artisan migrate:status`;
  - la propuesta en `--status`: **marcador vacío**;
  - la propuesta al migrar: solo `artisan config:cache` (la regeneración como www-data).
- **La caché:** queda en 600.
- **`PGOPTIONS`:** con `search_path` → 2; con dos opciones → 2; `-c lock_timeout=2s` → se respeta.
- **Credencial:** en 644 → 2; con `APP_KEY` → 2; sin `DB_OWNER_PASSWORD` → 2.
- **`.env`:**
  - con `DB_HOST`/`DB_PORT` falsos → 0: **manda la credencial**;
  - con `PGHOSTADDR` → 2: libpq lo leería del entorno, que Laravel rellena con `putenv`;
  - escribible por el grupo → 2.
- **Código:** un fichero de `app/` escribible → 2; un enlace en `app/` a un fichero 666 → 2 (`find -L`).
- **H-5:**
  - caché convertida en enlace → el destino **sigue en 644**, sin `chmod` a través del enlace;
  - enlace a una caché válida → la caché vuelve a ser un fichero normal en 600 y el destino sigue en 644.
- **Registro:** 25 líneas, sin valores.

### 4.4 Supresión Ley 1581: 17/17

Contexto: `h5_owner` sin superusuario, con `SET LOCAL ROLE` desde el superusuario del clúster. Datos sintéticos de «Zoila Pérez Sintética» con `sembrar-1581.php`:
- una entrada de Legacy **firmada** con la clave del banco;
- 3 eventos del libro, uno con `é` en el JSON;
- una instantánea con nota;
- un canario **revocado**, con la huella calculada con `PhoneFingerprint::of` (la vía del runbook), y uno **activo**;
- un evento del laboratorio.

Resultados:
- **En seco:** 8 campos; ROLLBACK, huella de las 14 tablas idéntica.
- **`owner=h5_app`:** aborta con 42501 sin cambiar nada. Además, `h5_app` no puede `DISABLE TRIGGER` (`must be owner`).
- **Huella de un canario vivo:** aborta sin cambiar nada. Los 44 disparadores siguen activos.
- **Redacción:**
  - 8 campos en 6 tablas, **0 coincidencias** después;
  - el canario queda `suprimido 0000` con una huella nueva; el vivo, intacto;
  - **44 disparadores activos antes y después**;
  - +1 fila `system.personal_data_redacted`, sin el dato;
  - **`publication-check` 0: la firma no cubre `reason` ni el actor**.
- **Por `admin_id`:** nombres `[suprimido]`, IP y navegador vacíos; los de otra persona, intactos.
- **Repetir la solicitud:** 0 campos.

Además, `reply`, `checks` y `judge` entran en `results_hash`: una ejecución del laboratorio **sin veredicto** con un caso redactado ya no se puede juzgar. El modo en seco lo cuenta y lo avisa.

### 4.5 Respaldo y restauración: 14/14

- **Volcado** `-Fc -Z9`, por el camino del timer: 1 166 442 bytes, 3 009 objetos en el índice, 1 s.
- **Permisos:** 700/600 y `.sha256` correcto; 226 tablas.
- **Restauración en bases desechables**, con dueños y con `--no-owner`:
  - 0 errores, RTO 1 s;
  - **recuento EXACTO de las 226 tablas**;
  - 44/50/4/14 y la huella de F;
  - la cadena restaurada **verifica con la `APP_KEY` del entorno**.
- **H-6 (hallazgo):** con `--no-owner`, que es lo que pone la «Restauración real» de `docs/ops/BACKUPS.md`, las tablas pasan a ser del superusuario. El siguiente `ironbody-migrate` fallaría con 42501 y se rompe el modelo S1. Los permisos de `h5_app` sí se conservan.
  - Corrección propuesta (DOC7 o el propietario): restaurar **con dueños**, porque los roles existen en el mismo servidor. `--no-owner` se queda solo para la verificación semanal.

### 4.6 Recuperación A · PostgreSQL cae durante el commit: 11/11

Clúster **propio** en 55440: el de 55432 lo comparten otros agentes y no se para.

- **Montaje:** una copia de la base desplegada y un proceso largo que hace de commit (`lector.php`, llave del motor en `canary`).
- **PG arriba:** `legacy` en 10 ms.
- **PG caído** (`pg_ctl stop -m immediate`):
  - el sello es **`fallback/read_failed` en 7 ms, sin excepción**;
  - la escritura del commit falla: no es del Studio y no se tapa.
- **PG de vuelta:** el mismo proceso **reconecta** (`legacy`) y escribe.
- **Cadena bloqueada 3 s** (como la migración del H7): la lectura **espera** 2 505 ms y sella bien. Durante la migración del H7, cada commit con la llave distinta de `off` espera como mucho el `lock_timeout` (5 s).
- **Conexión terminada a mitad:** Laravel reconecta y **repite** la sentencia (19 510 ms esperando al cerrojo) y sella `legacy`. No hay turno mudo.
- **Registro:** el aviso va al canal `channel` (`channel-AAAA-MM-DD.log`) con el motivo y la clase, nunca el mensaje SQL.

### 4.7 Recuperación B · worker del laboratorio muerto: 10/10

Ejecución `fake` de 2 casos. El worker muere con `kill -9` dentro del paso, con el trabajo reservado.

- **Camino 1** (muere antes de reclamar el caso): el trabajo se da por fallido (intentos agotados), el paso de rescate sigue y la ejecución acaba `completed · done,done` en **8 s**.
- **Camino 2** (muere con el caso reclamado): la ventana es de microsegundos con `fake`, así que el estado se deja con SQL, documentado. El caso huérfano acaba `error/worker_lost` y la ejecución `completed · error/worker_lost,done` en **4 s**.
- **Una huérfana con el plazo vencido** acabó `stopped/deadline`. Salió por un error de zona horaria de mi ensayo (corregido), pero prueba el cierre por plazo.
- **H-7 (hallazgo):** mientras el worker está muerto con su trabajo reservado, **`queue:health`, el monitor e IRON GUARD no ven nada** (`problemas []`). La espera solo cuenta trabajos sin reservar.
  - En producción, supervisor relanza el worker y el trabajo vuelve a los 300 s (`retry_after`); si el worker no vuelve, nadie avisa.
  - Propuesta para `backend`/observabilidad: contar como desatendido un trabajo reservado hace más de `retry_after`.

### 4.8 Vuelta atrás por niveles: 13/13

Banco 2, recién desplegado.

- **Nivel 2 (bandera del monitor):**
  - `true` + `config:cache` → **2 tareas**; una pasada real da 0 y ningún incidente;
  - `false` → 0 tareas; el `.env` queda idéntico.
- **Nivel 3 (código):**
  - con el H6 (`7d2c6d8`) sobre el esquema del H7: 56 rutas y 44/50;
  - vuelve `no-cache, private` y el 401 en lugar del 413;
  - `publication-check` 0;
  - **smoke del H6 de la fase 1: 78 + 26 OK, 0 FALLO**;
  - volver a Legacy con la cadena vacía → 409 `already_legacy`, sin escritura (la escritura encadenada bajo el disparador la prueba DB7 en PG);
  - de vuelta al H7, el smoke da **22 OK**.
- **Nivel 4 (esquema):** `migrate:rollback` con filas → **se niega** (las filas de cadena, libro e instantáneas siguen `0 0 3`) y el esquema sigue en 44/50.

### 4.9 Tanda del 02-11: las discrepancias de DOC7 y la T-1 del tester (banco 3, `ee9f128`)

**Banco propio y nuevo**, para no tocar los del tester (`ultron_studio_banco_h7_test7*`, puertos 8177/8178, clúster 55440): `~/.cache/ironbody-banco-h7-doc7`, base `ultron_studio_banco_h7_doc7`, puerto 8173, `BANCO_WIP=0` (solo lo confirmado: `ee9f128`, con F-1 y F-2). Su «producción» sigue en `7d2c6d8`, que en `app/`, `config/`, `routes/` y `database/` es idéntico a `1e5f9a0` (`git diff --stat` vacío). Carga del Mac durante los ensayos: 1,8–2,5.

**T-1 (preparar.sh):** la regla literal `PREVIO=pendiente` dejó de casar con `PREVIO=1e5f9a0` y el banco no se montaba. Ahora sustituye la línea `PREVIO=…` **sea cual sea su valor**, exige que haya exactamente una y deja anotado el valor del repo: `PREVIO=7d2c6d8   # BANCO: la «producción» del banco (en el repo, PREVIO=1e5f9a0)`. Todo lo demás de la copia es el guion que irá a producción (22 líneas sustituidas: rutas, psql, roles, dueños, canal del log y esa).

**Discrepancia 4 (el aviso de la expectativa del monitor):**

| Prueba | Resultado |
|---|---|
| La función contra Dotenv, 18 ficheros sintéticos (ausente, vacía, `export`, `""`, `''`, solo un comentario, espacios, `null`, `(empty)`, `false`, la del código con y sin comillas, otra, `true`, repetida en los dos órdenes, comilla sin cerrar y un fichero que no parsea) | **18/18**. Dotenv ignora una línea con la comilla sin cerrar (la clave no existe para Laravel: rige la del código) y un fichero que no parsea no devuelve nada, que se avisa como «no se pudo comprobar» |
| `--solo-comprobar` en el banco con la clave ausente, vacía, `null`, la del código, otra y repetida con valor al final | **6/6**, las seis en `COMPROBACIONES PREVIAS EN VERDE`: sin línea; «presente y vacía»; «presente y vacía»; «escrita con la misma expectativa que el código»; `AVISO: … tiene valor`; `AVISO: … tiene valor (aparece 2 veces: rige la última)`. El fichero de entorno y `env.backup` del banco, restaurados y comparados con `cmp` |

**Despliegue:** `--solo-comprobar` en verde y después `ensayar.sh desplegar --55p03` → **`DESPLIEGUE H7 (BACKEND) COMPLETADO` en 45 s**: intento 1 con 55P03 real, intento 2 `9.90ms DONE`; 44/50/4/14; disparadores y privilegios correctos; 56/18/8/10; `no-store` en las 5 cabeceras; 413 sin sesión; el monitor en seco 0; instantáneas 3 → 3; 0 ERROR.

**Discrepancia 1 (los presupuestos en el smoke):**

| Pasada | Estado del banco | Resultado | Presupuestos |
|---|---|---|---|
| A | recién desplegado: cadena vacía, sin versiones, laboratorio `fake` sin ejecuciones | **36 OK · 0 FALLO · 3 OMITIDO**, código 3 | 14 OK · 3 OMITIDO (sin versiones, sin CANARY, sin ejecuciones) en 65 s, de ellos 22 s esperando al cupo de 90/min |
| B | tras `sembrar-presupuestos.sh`: una ejecución `fake` lanzada por la API (2 casos, `completed`), una versión en CANARY con su entrada firmada (`publication-check` 0) | **41 OK · 0 FALLO · 0 OMITIDO**, código 0 | **17 OK**, las 17 rutas medidas, en 73 s |
| C | B, con una copia del JSON: `p50Ms` 10 en status y `maxBytes` 30 000 en el catálogo | **39 OK · 2 FALLO**, código 1 | el de tiempo, repetido una vez (54,0 → 53,7 ms > 10); el de bytes, sin repetir (35 159 B > 30 000). La cabecera enseña la copia y su sha256 (`06c69593cdc8` frente a `8e34dbcc9daa`): **recalibrar es editar el JSON** |
| — | JSON ausente, con una ruta fuera del Studio o truncado | **3/3**: código 2 y la cuenta de smoke sin tocar (estado, contraseña, fecha y sesiones iguales). Repetido tras añadir `-B` a la validación previa: 3/3 y ningún `__pycache__` junto al smoke (sin `-B` aparecía uno: en producción habría quedado en `/root`) | — |

En las tres pasadas: 0 respuestas 429, 0 5xx, `no-store` en todas las de `/api/admin` (107, 123 y 123) y **las 14 tablas idénticas antes y después**: ninguna lectura de la lista escribe.

Latencias medidas en el banco (php -S local, sin TLS ni nginx: **no son la línea base de producción**; mediana / máximo en ms, n = 5; la evidencia, n = 3 sin calentamiento):

| Ruta | A | B | Bytes (B) | Techos p50 / p95 / bytes |
|---|---|---|---|---|
| GET status | 50,7 / 55,8 | 52,9 / 54,7 | 1 741 | 150 / 250 / 4 096 |
| GET catalog | 48,6 / 51,8 | 48,6 / 52,0 | 35 159 | 150 / 250 / 49 152 |
| GET draft | 50,8 / 54,3 | 50,9 / 55,2 | 3 265 | 150 / 250 / 81 920 |
| GET rules | 49,2 / 52,3 | 50,9 / 52,1 | 21 | 150 / 250 / 32 768 |
| GET versions (20) | 50,2 / 52,8 | 52,1 / 54,5 | 1 335 | 150 / 250 / 24 576 |
| GET versions/{uuid} | OMITIDO | 53,4 / 54,3 | 5 070 | 150 / 250 / 98 304 |
| GET publications (20) | 49,3 / 52,1 | 51,5 / 57,8 | 1 096 | 150 / 250 / 16 384 |
| GET versions/{uuid}/canary-evidence | OMITIDO | **84,0 / 231,9** | 676 | 2 000 / **400** (tras F-1) / 2 048 |
| GET audit | 49,8 / 53,4 | 48,5 / 52,3 | 633 | 150 / 250 / 24 576 |
| GET engine/readings | 53,9 / 56,5 | 56,2 / 56,9 | 1 266 | 150 / 250 / 4 096 |
| GET integrations/status | 58,5 / 61,6 | 60,1 / 61,5 | 1 273 | 150 / 250 / 4 096 |
| GET integrations/meta/campaigns (20) | 54,8 / 58,1 | 56,7 / 61,1 | 1 192 | 250 / 400 / 20 480 |
| GET integrations/meta/links (20) | 49,4 / 52,8 | 50,0 / 56,9 | 713 | 150 / 250 / 24 576 |
| GET integrations/canaries | 51,7 / 72,5 | 54,0 / 55,2 | 919 | 150 / 250 / 8 192 |
| GET lab/status | 51,2 / 56,7 | 53,3 / 57,3 | 830 | 150 / 250 / 2 048 |
| GET lab/runs (20) | 49,8 / 53,3 | 53,5 / 58,2 | 1 367 | 150 / 250 / 24 576 |
| GET lab/runs/{uuid} | OMITIDO | 54,9 / 56,0 | 8 714 | 150 / 250 / 40 960 |

El máximo de la evidencia en B (231,9 ms) fue su primera muestra, en frío: el JSON le da 0 de calentamiento para no gastar el cupo de 10/min, y con 3 muestras ese primer golpe decide el «p95» (H-11). En la pasada de cierre con el lanzador definitivo (B2, mismo estado: **41 OK · 0 FALLO · 0 OMITIDO**, código 0), la evidencia dio 106,2 / 109,8 ms.

**Una regresión mía, cazada por el propio smoke:** al reescribir `smoke-h7.py`, el U+2028 literal de `INVISIBLES` se convirtió en un espacio normal y la primera pasada A dio `FALLO motivo con un invisible … [U+2028=409]`. Corregido: los cinco invisibles, el ZWJ y el VS16 van ahora con escapes (`\u2028`…), sus valores son idénticos a los de `ee9f128` (comparados importando las dos versiones) y el fichero ya no lleva ningún carácter invisible literal. La pasada A repetida dio 36 OK.

**Discrepancia 3 (el mando del monitor): 16/16** en el banco.
- `estado`: código 0, `monitor=off`, programador `27 0`.
- Usos que no tocan nada: `on` sin estado → 2; `on on` → `ABORTA` (la efectiva es off); `off off` → «ya está en off», 0; un valor desconocido → 2. El fichero, igual (`cmp`) en los cuatro.
- `on off` → **`MANDO monitor → on APLICADO (copia previa: …/env-pre-h7-monitor-on-20261102-161443)`**: programador **27 0 → 29 2**; la copia previa en 600 y byte a byte el fichero de antes; mismo inodo y modo 600; la caché en 600 y sin enlace; ningún valor del fichero de entorno en la salida (comprobado contra los valores sintéticos del banco). La primera pasada real del monitor: 0 y 0 incidentes.
- `off on` → **`MANDO monitor → off APLICADO`**: **29 2 → 27 0**; el fichero de entorno vuelve a ser **byte a byte** el de antes, con el mismo inodo.

**Discrepancia 2:** la sección «Supresión» es el §10. Sus órdenes de la huella y del modo en seco se ensayaron en este banco (§10, «Ensayo de las órdenes»).

### 4.10 m-1 del reviewer del H7: `schedule:list` con su código de salida y `TAREAS` exigido (banco 4, `0d43401`)

**El fallo de m-1:** `$(artisan schedule:list 2>/dev/null | grep -c 'ultron-studio:' || true)` daba «0 tareas del Studio» también cuando `schedule:list` fallaba.
- **Dónde estaba:** en el paso 7 del despliegue y en el paso 1 del lanzador del smoke. El mando lo cazaba de rebote, porque su JSON ilegible acababa en «?».
- **Corrección:** en los tres, `schedule:list --json` con su código de salida **aparte** (`FALLO <código>` o `ILEGIBLE` en lugar de un recuento). En el despliegue y el smoke, además, el total absoluto exigido contra **`TAREAS`**; el mando conserva su salto de ±2 (lo pidió el lead):
  - `TAREAS` es una variable al principio de esos dos scripts, con `pendiente` hasta leerla en el servidor con `--solo-comprobar`;
  - en el banco la sustituye `preparar.sh`, como `PREVIO`, con `BANCO_TAREAS`, que fija la nueva orden `ensayar.sh <banco> tareas <n>`;
  - el smoke recibe `SMOKE_TAREAS`.
- **Por qué no 26/28:** era la medida de las pruebas, y en el banco el total es **27** (H-10).

**Banco propio y nuevo:** `~/.cache/ironbody-banco-h7-m1`, base `ultron_studio_banco_h7_m1`, puerto 8174, `BANCO_WIP=0` (`0d43401`), producción en `7d2c6d8`. **21/21:**

| Prueba | Resultado |
|---|---|
| Despliegue de verdad con `TAREAS=pendiente` | código 2, «TAREAS sin fijar»; HEAD sigue en `7d2c6d8` |
| `--solo-comprobar` con `pendiente` | código 0: `programador: 27 tareas, ninguna del Studio · TAREAS sin fijar: fijar TAREAS=27 …` y **`COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=27`** (nunca «EN VERDE») |
| `TAREAS=26` (el valor de las pruebas) frente a un programador de 27 | **`ABORTA: el programador tiene 27 tareas y TAREAS dice 26`**: un 26 fijo habría parado el banco |
| `TAREAS=27` | `COMPROBACIONES PREVIAS EN VERDE`, con `programador 27 tareas (TAREAS=27), 0 del Studio` en el resumen; las copias del banco llevan `TAREAS=27` y anotan el del repo (`pendiente`) |
| `schedule:list` que falla de verdad: sin privilegios de `h5_app` sobre `cache_locks`, donde `has_mutex` toma un cerrojo (la misma QueryException del reviewer) | `schedule:list` → código 1, y el `grep -c` antiguo sobre esa salida **da 0**, que es el fallo de m-1. Ahora: |
| · el despliegue, paso 0 | `ABORTA: schedule:list no sale con 0 (código 1; su salida no se imprime): sin poder leer el programador no se despliega` |
| · el smoke (ya desplegado) | `FALLO   schedule:list no sale con 0 (código 1 …): … no se ejecuta el smoke`, código 1, y la cuenta 32 sin tocar (estado, contraseña, fecha y sesiones iguales) |
| · el mando, `estado` | `schedule:list NO sale con 0 …`, código 1 |
| · el mando, `on off` | `ABORTA: schedule:list no sale con 0 …` antes de escribir; el fichero de entorno, igual (`cmp`) |
| Privilegios de `h5_app` sobre `cache_locks` | restaurados exactamente las dos veces (`DELETE,INSERT,SELECT,UPDATE`); `schedule:list` vuelve a salir con 0 |
| Despliegue de verdad con `TAREAS=27` y `--55p03` | **`DESPLIEGUE H7 (BACKEND) COMPLETADO` en 45 s**: 55P03 en el intento 1, `11.22ms DONE` en el 2; paso 7: `programador (total · del Studio): 27 0 (esperado 27 0 …)` |
| El mando | `on off` → APLICADO, `27 0 → 29 2`; `estado` → `29 2`; `off on` → APLICADO, `29 2 → 27 0`, y el fichero de entorno vuelve **byte a byte** al de antes |
| El smoke con `TAREAS=27` | `OK el programador: 27 tareas (TAREAS), ninguna del Studio`; **36 OK · 0 FALLO · 3 OMITIDO** (código 3: los 3 presupuestos por estado), presupuestos 14 OK en 65 s |
| El smoke con `SMOKE_TAREAS=pendiente` | código 2 y la cuenta sin tocar |

**Ajuste pedido por el lead, y repetición: 11/11.** En esa primera pasada (21/21) el mando también exigía `TAREAS` (sin él, `on`/`off` se negaban). El lead pidió que solo añadiera el código de salida, porque ya comprueba el salto de ±2. Quitado, el mando no lleva `TAREAS` y `preparar.sh` solo lo sustituye en la copia del despliegue. Repetido en el mismo banco, ya desplegado con `TAREAS=27`:
- `--solo-comprobar` (reanudación) en verde, con `programador 27 tareas (TAREAS=27)`;
- el mando: `estado` → `27 0`; `on off` → APLICADO, `27 0 → 29 2`; `off on` → APLICADO, `29 2 → 27 0`, con el fichero de entorno byte a byte el de antes y el mismo inodo;
- con `schedule:list` fallando (`cache_locks` sin privilegios): el mando `estado` sale con 1; `on off` aborta antes de escribir; el smoke da FALLO con la cuenta sin tocar; el paso 0 aborta. Privilegios restaurados exactamente;
- el smoke con `TAREAS` sin fijar: código 2, y el mensaje ya nombra los dos scripts.

**Queda el mismo patrón en `banco-h7/ensayo-vuelta.sh`** (su nivel 2 cuenta con `grep -c` las tareas del Studio). Solo afecta al ensayo del banco, no a producción. No lo cambio en esta tanda porque habría que repetir sus 12 minutos de vuelta atrás. Propuesta: que su nivel 2 use `h7-monitor-scope.sh`, que ya está ensayado. Su línea de uso en `banco-h7/README.md` ya incluye `ensayar.sh <banco> tareas 27`, porque el despliegue y el smoke del banco lo exigen ahora.

## 5. Runbooks por fase (producción; todo con autorización)

1. **Condiciones:**
   - el H6 en su fase 1, verificado a las 24 h;
   - REV7 sin bloqueantes;
   - push con avance rápido;
   - **`PREVIO` fijado en un commit** con el HEAD de producción del día;
   - **`TAREAS` fijado en el mismo commit** en `deploy-h7-backend.sh` y `smoke-run-h7.sh`, con el total que imprime `--solo-comprobar` en el servidor (paso 5). Los dos se niegan a actuar sin él.
2. **Respaldo:** el del H6 con `BK=/var/backups/ironbody/ultron-studio-h7-<ts>`, en `umask 077` y ≥ 226 tablas.
3. **Copiar los scripts** (con su huella):
   ```bash
   O=docs/ultron-studio/ops
   shasum -a 256 $O/{deploy-h7-backend.sh,h7-schema-check.sql,smoke-h7.py,smoke-run-h7.sh,smoke-h6.py,perf-h7/presupuestos-h7.json,h7-monitor-scope.sh}
   scp $O/{deploy-h7-backend.sh,h7-schema-check.sql,smoke-h7.py,smoke-run-h7.sh,smoke-h6.py,perf-h7/presupuestos-h7.json,h7-monitor-scope.sh} ironbody-vps:/root/
   ```
   En el servidor: `.sh` en 700, el resto en 600, y `sha256sum` para comparar. `presupuestos-h7.json` queda en `/root/` junto al smoke (el lanzador lo busca ahí y lo borra al salir); `h7-monitor-scope.sh` es para el paso 10.
4. **Esquema antes:**
   ```bash
   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
   ```
   Esperado: 43/49/4/14, `existe_rol_app` 1, `rotas` 0, 0 cerrojos.
5. **Ensayo:** `bash /root/deploy-h7-backend.sh <commit> "$BK" --solo-comprobar` → `COMPROBACIONES PREVIAS EN VERDE`.
   - Con `TAREAS=pendiente` acaba en `COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=N` y no toca nada. Se fija `TAREAS=N` en `deploy-h7-backend.sh` y `smoke-run-h7.sh` en un commit, se vuelven a copiar con su huella y se repite hasta `EN VERDE`.
   - Si aborta por `schedule:list no sale con 0`, no se sigue: el programador no se puede leer (base o caché).
6. **Despliegue:** `bash /root/deploy-h7-backend.sh <commit> "$BK" 2>&1 | tee /root/h7-$(date -u +%Y%m%d-%H%M%S).log` → **`DESPLIEGUE H7 (BACKEND) COMPLETADO`**.
   - Con un 55P03 agotado, se relanza: reanuda.
7. **Esquema después:** el mismo `.sql`. Esperado: 44/50, `uspub_chain_next:O` y `ar` en las 3 tablas.
8. **Smoke:**
   - `SMOKE_BUDGETS_OUT=/root/h7-presupuestos-medidos-$(date -u +%Y%m%d).json bash /root/smoke-run-h7.sh` → 0, **o 3 si lo único OMITIDO son presupuestos por estado**. En producción es lo esperable: sin versión en CANARY, la evidencia sale `OMITIDO`. Leer la línea `PRESUPUESTOS:` y el motivo de cada OMITIDO; cualquier otro OMITIDO o un FALLO no son esperables;
   - un FALLO de presupuestos de tiempo: la fase ya repitió una vez esa ruta; se repite la pasada entera una vez más antes de darlo por bueno (el JSON, `calibration`), y nunca se sube un techo para que pase;
   - las medidas de esta primera pasada son la línea base de producción: con ellas se recalibran los techos en el JSON del repo (§3), en un commit aparte;
   - regresión: `SMOKE_PHASE=1 bash /root/smoke-run-h6.sh`, copiándolo otra vez (cupo de 3 por hora).
9. **A los 15 min y a las 24 h** (solo lectura):
   - `sudo -u www-data php artisan ultron-studio:monitor --dry-run`: las tres comprobaciones;
   - `supervisorctl status`, `failed_jobs`;
   - ERROR en el log;
   - 5xx en `access.log` de `/api/admin`;
   - `curl -sI …/api/admin/ultron-studio/status | grep -i cache-control`.
10. **Encender el monitor**, con autorización:
    - antes, revisar **H-1** (§8): con `schedule:run` en el crontab de root, el monitor corre como root (el mando lo avisa);
    - `bash /root/h7-monitor-scope.sh estado` → `monitor=off` y el programador sin tareas del Studio;
    - `bash /root/h7-monitor-scope.sh on off` → **`MANDO monitor → on APLICADO (copia previa: …)`**. Hace la copia previa en 600, exige el fichero en 600, escribe la bandera en el mismo fichero, comprueba que parsea, `config:cache` como www-data con la caché en 600, la configuración efectiva, el programador (`schedule:list` con código 0, +2 entradas y las 2 del Studio), `queue:restart`, 13/0 dos veces y 401;
    - con desvíos sale con 1 e imprime la orden exacta para volver;
    - primera pasada: `sudo -u www-data php artisan ultron-studio:monitor` → 0 y ningún incidente `ultron_studio` nuevo.

**Vuelta atrás:**

| Nivel | Qué se hace | Qué se queda |
|---|---|---|
| 2 · Banderas | `bash /root/h7-monitor-scope.sh off on` → `MANDO monitor → off APLICADO`: la bandera en `false`, `config:cache`, 0 tareas del Studio, `queue:restart` (ensayado ida y vuelta: el fichero vuelve a ser byte a byte el de antes) | — |
| 3 · Código | `git checkout -q --detach <PREVIO>`, las cachés como www-data en 600 y `queue:restart`. Smoke del H6 (ensayado: 78 + 26) | El esquema del H7 (compatible) y la variable |
| 4 · Esquema | **Nunca en producción.** `down()` se niega con filas (ensayado) | — |

## 6. Costes operativos (entrega 2)

| Coste | Palancas, de más rápida a más profunda | Dónde se mira |
|---|---|---|
| **OTP (Twilio Verify)** | `TWILIO_SEND_ENABLED=false` · techo `TWILIO_DAILY_SOFT/HARD_LIMIT_USD` (6/12) · cupos `OTP_*` · login adaptativo · Usage Triggers (avisan, no frenan) | `OtpPolicy::costSnapshot()`; `grep otp.cost_guard`; Usage Records (la cifra real). Detalle: `docs/ops/TWILIO-CONTROL-DE-COSTE.md` |
| **OpenAI del motor** | `MARKETING_ULTRON_ENABLED`; la pausa del agente en el CRM | El proyecto de OpenAI del motor; `marketing_ai_actions` por día |
| **OpenAI del laboratorio** | Cuatro cerrojos: bandera, `DRIVER=fake`, presupuesto diario 0 y clave propia. Además: `RUN_BUDGET_USD` 0,50, `MAX_RUNS_PER_DAY` 20, topes de casos y tokens, plazo de 30 min | `GET lab/status`; el proyecto propio del laboratorio (`store:false`) |
| **Twilio fuera del OTP** | Voz apagada (tope diario 0); la sonda del Studio es un GET gratis, con bandera, enfriamiento y cupo | `integrations-doctor`; `marketing_calls` |
| **Infraestructura** | VPS fijo; respaldos de ~3 MB al día (< 100 MB retenidos) | `df -h`; `/var/lib/ironbody/backup-status.json` |

Todas se cambian en `.env` y se aplican con `config:cache`. Ninguna necesita desplegar.

## 7. Política de respaldos (entrega 5)

- **Vigente** (`docs/ops/BACKUPS.md`), y no la cambio:
  - diario a las 03:15 (Bogotá);
  - `-Fc -Z9` validado, 600 y `.sha256`;
  - 14 diarios + 8 semanales;
  - verificación semanal en una base desechable;
  - IRON GUARD vigila los dos ficheros de estado;
  - RPO 24 h.
- **Respaldos de despliegue:** en 700/600 y **nunca se borran sin orden**.
- **Recomiendo al propietario:**
  - **H-6:** restaurar con dueños en la emergencia;
  - una copia **fuera del VPS**, cifrada;
  - un **registro de supresiones 1581**, para volver a aplicarlas tras cualquier restauración: los volcados llevan el dato hasta que caducan, ~3 meses los automáticos, y los manuales hasta que se decida.

## 8. Hallazgos

| # | Severidad | Qué | Dueño |
|---|---|---|---|
| H-1 | Media, **por comprobar en el servidor** | Según `IRON_BODY_VALUATION_SCHEDULING_REPORT.md` §2, `schedule:run` corre como **root**: cada tarea (el monitor incluido) incluye como root las cachés de www-data, cada minuto. Pasar al crontab de www-data: paso 6b de la instalación de `ironbody-migrate-propuesta.sh` | Propietario |
| H-3 | Media | `.env` probablemente `www-data 600`: www-data puede cambiar la configuración de todo. La propuesta saca el destino del dueño de la credencial y **exige** `root:www-data 640` (paso 3b) | Propietario (recomendación; nada cambia en producción) |
| H-5 | Baja, **corregido** | El `chmod` de root sobre `bootstrap/cache` (SEC7). Ahora lo hace www-data y nunca sobre un enlace, en la propuesta y en `deploy-h7-backend.sh` | — |
| H-6 | Media | `--no-owner` en la restauración de emergencia de `BACKUPS.md` rompe el modelo S1 (ensayado) | DOC7 / propietario |
| H-7 | Baja | Un worker muerto con su trabajo reservado no lo ve `queue:health`, ni el monitor, ni IRON GUARD (ensayado) | `backend` / observabilidad |
| H-8 | Informativo | La puerta del despliegue cazó trabajo en curso no nombrado. Cualquier fichero nuevo en la puerta exige añadirlo con su paquete | Lead |
| H-9 | Informativo | Las columnas `timestamp` sin zona guardan UTC. En SQL manual, `now() AT TIME ZONE 'UTC'` (el ensayo se equivocó una vez: §4.7) | — |
| H-10 | Informativo | El total de `schedule:list` depende de las banderas del servidor (coach, bienestar, wompi, billing, seguimientos, sincronización de Meta): **26 → 28** en las pruebas y **27 → 29** en el banco. Un 26/28 fijo habría dado un desvío falso en el banco (ensayado: `TAREAS=26` aborta con 27) y, casi seguro, en producción. Tras m-1, el total se exige contra `TAREAS`, leído en el servidor con `--solo-comprobar` y fijado en un commit | — |
| m-1 | Menor (del reviewer), **corregido** | `schedule:list` que falla se contaba como «0 tareas del Studio» en el despliegue y en el smoke. Ahora, en los tres scripts, su código de salida va aparte; el despliegue y el smoke exigen además el total contra `TAREAS` y el mando conserva el salto de ±2 (§4.10: 21/21 y 11/11) | — |
| H-11 | Baja | La evidencia del canario se mide con 0 de calentamiento y 3 muestras (el cupo es de 10/min): su «p95» lo puede decidir la primera petición, en frío (en el banco, 231,9 ms frente a una mediana de 84,0 en una pasada; 109,8 en la siguiente). Cabe en los 400 ms tras F-1, pero es la ruta con menos margen al recalibrar | PERF7 (decidir si 1 de calentamiento, que el cupo admite: 4 por pasada) |
| H-12 | Informativo | `ops/README.md` (filas de `deploy-h7-backend.sh`, `smoke-run-h7.sh` y `supresion-1581.sql`) y `ULTRON_STUDIO_GUIA_ADMINISTRACION.md` §10 describen todavía el estado de antes de esta tanda (`PREVIO=pendiente`, `api-commit.txt` en la reanudación, «no carga los presupuestos», «el runbook Supresión no existe»). Con m-1, además, el runbook del despliegue (DEPLOYMENT_PLAN) tiene que incluir fijar `TAREAS` en `deploy-h7-backend.sh` y `smoke-run-h7.sh` junto a `PREVIO` (§5, pasos 1 y 5), y la expectativa del smoke pasa a «0, o 3 con solo presupuestos OMITIDO por estado». No los toco: son de DOC7 | DOC7 |

## 9. Pendiente de validar en producción, y lo que queda del propietario

- **Producción** (no ensayable en local):
  - el `--check` y el `--pretend` del nuevo `ironbody-migrate` (`setpriv`, usuarios, `flock` y `sudo` reales);
  - `--solo-comprobar` del despliegue;
  - GNU `stat`/`find`;
  - dueños `root` y `www-data`;
  - FPM;
  - los tiempos reales del 55P03 con el motor leyendo;
  - **los presupuestos con nginx, TLS y FPM**: la primera pasada del smoke en producción es la línea base; después se recalibran los techos en el JSON del repo (§3), en un commit aparte y con su autorización;
  - el mando del monitor con `crontab`, `supervisorctl` y los dueños reales (el aviso H-1 sale de verdad allí);
  - **`TAREAS`**: el total de `schedule:list` de producción, que imprime `--solo-comprobar`. Se fija en `deploy-h7-backend.sh` y `smoke-run-h7.sh` en un commit antes del despliegue (§5, pasos 1 y 5).
- **De PERF7:** confirmar la regla de la propuesta para el p50 (aplico al p50 la misma de su JSON, que solo nombra el p95) y decidir H-11.
- **De DOC7:** H-12.
- **Del propietario:**
  - instalar `ironbody-migrate` (pasos 0–7 del script, con 3b y 6b);
  - `log_error_verbosity = terse` (H-2 del H6; nota de DB7);
  - H-6;
  - la copia de respaldos fuera del VPS;
  - autorizar el despliegue y el encendido del monitor;
  - decidir si, en una supresión del personal, se quita su nombre de la autoría (trazabilidad).
- **Limpieza local** (mía, cuando el lead diga):
  - los bancos `~/.cache/ironbody-banco-h7{,-vuelta,-doc7,-m1}` (sus API, paradas; se arrancan con `banco-h6/api.sh <banco> start`, puertos 8173 y 8174);
  - sus bases `ultron_studio_banco_h7{,_vuelta,_doc7,_m1}`;
  - mis bases de pruebas `ultron_studio_h7_ops` y `ultron_studio_h7_ops7`.
  - Las órdenes están en `banco-h7/README.md`.

## 10. Supresión (Ley 1581): el runbook de `supresion-1581.sql`

Es la sección a la que remite la cabecera del SQL. **Nunca ejecutado en producción.** Lo que hace el SQL y su política (qué se redacta, qué solo se informa y por qué) están en su cabecera; aquí, el orden de las órdenes. El ensayo de la lógica es el §4.4 (17/17) y el de estas órdenes, el final de esta sección.

**Cuándo y quién:**
- ante una solicitud real de supresión, con número de radicado y con la **autorización del propietario**. Es una modificación de la base de producción;
- la ejecuta quien opera el servidor, como root, con `psql` como `postgres` y `SET LOCAL ROLE iron` (el rol dueño). `iron_app` no puede: el SQL aborta con 42501 y la aplicación no puede apagar un disparador.

**0. Antes de tocar nada.**
- Qué se busca: el nombre y sus variantes de escritura (con y sin tildes, apodos: una por pasada), el teléfono en E.164, si fue un **número canario** y si es **personal** del gimnasio (`admin_id`). Quitar el nombre del personal de la autoría es decisión del propietario (§9).
- Si fue un número canario, tiene que estar **revocado** desde el CRM antes: con uno vivo, el SQL aborta (55000) sin cambiar nada, porque su huella la usa la coherencia con el motor.

**1. El fichero de parámetros, solo de root.** El dato no pasa por la línea de órdenes (`ps`), ni por el historial, ni por un fichero que `postgres` pueda leer:

```bash
install -d -m 700 /root/supresion
R=1581-2026-001                                   # el radicado: [A-Za-z0-9._-], 3–40
install -m 600 /dev/null /root/supresion/$R.psql
```

**2. La huella del teléfono, si fue canario.** Es `App\Support\Privacy\PhoneFingerprint::of`, un HMAC-SHA256 con una subclave derivada de la `APP_KEY`: solo la calcula la aplicación. Es la misma vía que `banco-h7/ensayo-supresion.sh`. El teléfono entra por la entrada estándar, nunca como argumento, y la huella va directa al fichero, nunca a la pantalla:

```bash
cd /var/www/api/backend
read -rs -p 'Teléfono (E.164, +57…): ' TEL; echo           # sin eco y fuera del historial
H=$(printf '%s\n' "$TEL" | sudo -u www-data php artisan tinker --execute='echo App\Support\Privacy\PhoneFingerprint::of(trim((string) fgets(STDIN))), PHP_EOL;' 2>/dev/null | tail -1)
[[ "$H" =~ ^[0-9a-f]{64}$ ]] && echo "huella calculada (no se imprime)" || echo "NO se pudo calcular: ¿E.164 estricto (+ y 8–15 cifras, sin espacios)?"
{ printf "\\\\set phone '%s'\n" "$TEL"; printf "\\\\set phone_hash '%s'\n" "$H"; } >> /root/supresion/$R.psql
unset TEL H
```

- Como www-data porque es quien lee la configuración cacheada, y para no ejecutar como root código del árbol (H-1/H-5).
- Un número que no fue canario no necesita huella: basta `\set phone` para buscarlo en los textos.
- **La huella original desaparece al redactar** (se sustituye por una aleatoria): es irreversible.

**3. El resto de parámetros, con un editor** (`nano`/`vi`) sobre ese mismo fichero:

```
\set radicado '1581-2026-001'
\set term 'Nombre Apellido'          una variante por pasada; ≥ 4 caracteres, sin comillas dobles ni barras
\set cc 57                           el prefijo del país del teléfono, si no es 57
\set admin_id 42                     solo si es personal y el propietario lo decidió
\set owner iron
\set dry 1
```

**4. Que el servidor no registre el dato.** La línea `set_config` lleva los valores:

```bash
sudo -u postgres psql -X -Atq -d ironbody -c 'SHOW log_statement;' -c 'SHOW log_min_duration_statement;'
```

- Hace falta `log_statement` en `none` o `ddl`, y `log_min_duration_statement` en `-1` o en un umbral, **nunca `0`**: con `0`, PostgreSQL guarda en su log el texto de cada sentencia.
- Si no se cumple, se para y se avisa a `database-specialist`; el ajuste de esos parámetros no es parte de este procedimiento.

**5. En seco** (READ ONLY, termina en ROLLBACK):

```bash
umask 077
cat /root/supresion/$R.psql /root/supresion-1581.sql | sudo -u postgres psql -X -q -d ironbody 2>&1 | tee /root/supresion/$R-seco.log
```

Se lee:
- el recuento por tabla y columna;
- el total de campos;
- cualquier `WARNING columna sin política`: **se para y se avisa a devops**, porque es una columna nueva en la que no se busca;
- `NO SE REDACTA (firmada o con huella)`: el remedio es una versión nueva sin el dato, que decide el propietario con su jurídico;
- el `AVISO` de ejecuciones del laboratorio sin veredicto: habrá que repetirlas.

Una pasada en seco por cada variante del nombre.

**6. Redacción.**
- Antes: un respaldo fresco (el del despliegue: `pg_dump -Fc` en 700/600) y nadie editando el Studio.
- Cada tabla tocada queda en ACCESS EXCLUSIVE hasta el COMMIT, con `lock_timeout` de 5 s: si alguien la tiene, aborta entero sin cambiar nada y se repite.
- En el fichero, `\set dry 1` → `\set dry 0` (con el editor). Después:

```bash
cat /root/supresion/$R.psql /root/supresion-1581.sql | sudo -u postgres psql -X -q -d ironbody 2>&1 | tee /root/supresion/$R-real.log
```

- Esperado: `redactado: {…} · disparadores del Studio activos: N (los mismos que antes) · quedan 0 coincidencias · 1 fila en el libro` y `SUPRESIÓN CONFIRMADA (COMMIT)`.
- Es **una sola transacción**: si algo falla, no cambia nada y nunca queda un disparador apagado.
- **No hay vuelta atrás después del COMMIT.** Restaurar un respaldo devolvería el dato, que es justo lo que se pidió quitar.

**7. Tras el COMMIT.** El SQL ya comprueba antes de confirmar que quedan 0 coincidencias y los mismos disparadores activos; aquí se confirma desde fuera:
- **la cadena sigue verificando:** `sudo -u www-data php artisan ultron-studio:publication-check` → 0. La firma no cubre `reason` ni el actor;
- **los disparadores, activos:**
  ```bash
  sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FILTER (WHERE t.tgenabled = 'O') || ' activos · ' || count(*) FILTER (WHERE t.tgenabled <> 'O') || ' apagados' FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%';"
  ```
  Esperado: los mismos activos que imprimió el modo en seco al empezar (44 con el H7) y **0 apagados**;
- **0 coincidencias:** se vuelve a `\set dry 1` y se repite el paso 5 con el mismo fichero → `total: 0 campo(s) que redactar`. Solo pueden seguir apareciendo, como «NO SE REDACTA», las columnas firmadas;
- **el registro:** `SELECT id, created_at, summary FROM ultron_studio_audit_events WHERE event = 'system.personal_data_redacted' ORDER BY id DESC LIMIT 1;` → el radicado y los recuentos.

**8. Qué queda registrado y qué se limpia.**
- **En la base**, una fila del libro (de solo inserción) por cada pasada real:
  - `event` `system.personal_data_redacted`, `actor_type` `system` y `actor_name` `supresion-1581`;
  - el resumen con el radicado y los recuentos;
  - en `metadata`: el radicado, los tipos de búsqueda (`term`, `phone`, `phone_hash`, `admin_id`), los campos redactados por tabla y columna, lo firmado que no se redactó y la herramienta.
  - **Nunca el dato ni sus huellas.**
- **En `/root/supresion/`**, los registros de cada paso (600): solo recuentos.
- **El fichero de parámetros lleva el dato:** se borra al terminar, con `shred -u /root/supresion/$R.psql` (o `rm -f`).
- **Fuera de la base**, el propietario anota el radicado en el **registro de supresiones** (§7): fecha, quién lo ejecutó, tipos de búsqueda, recuentos y el id de la fila del libro. Sin el dato.
- **Los respaldos siguen llevando el dato** hasta que caducan: los automáticos, unos 3 meses (14 diarios y 8 semanales); los de despliegue, hasta que se decida. Tras **cualquier** restauración, se vuelve a aplicar cada supresión del registro, en seco y después real.

**Ensayo de las órdenes en el banco (02-11, `ee9f128`): 12/12.** Pasos 1–8 sobre los datos sintéticos de `sembrar-1581.php`, con `owner h5_owner` (sin superusuario) y el superusuario del clúster en lugar de `sudo -u postgres`:
- la huella, calculada con `PhoneFingerprint::of` por la entrada estándar: 64 hex, la del canario revocado sembrado, nunca impresa;
- el fichero de parámetros en 600 con sus 6 `\set`;
- `log_statement` `none` y `log_min_duration_statement` `-1`;
- en seco: 8 campos, ROLLBACK y ni el nombre, ni el teléfono, ni ninguna huella en el registro;
- redacción: código 0 y COMMIT;
- después:
  - `publication-check` 0;
  - 44 disparadores activos y 0 apagados;
  - el mismo fichero otra vez en seco: 0 campos;
  - una fila más en el libro, con el radicado y sin el dato;
- el fichero de parámetros, borrado.

(La comprobación de los parámetros del log falló una vez por el propio arnés: un `case` dentro de `$( )` que el bash 3.2 de macOS no analiza. Corregido el arnés, dio OK con esos mismos valores.)
