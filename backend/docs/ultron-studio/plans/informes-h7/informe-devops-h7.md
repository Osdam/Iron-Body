# Hito 7 · Informe de devops (OPS7): monitor, costes, migración sin root, supresión 1581, respaldos, despliegue y recuperación

**Fecha:** 01/02-11-2026. **Rama:** `feat/ultron-studio-h7`, en el worktree `_worktrees/ultron-h7`.

**Base de los ensayos:**
- `167e34c`: la confirmación en curso de OPS7, con DB7 y B7 en curso;
- `aa3451f`: DB7 y B7 completos.

Entre las dos, el código solo difiere en `AdminUserController` (+29 líneas). La migración del H7 es idéntica.

**Entorno de todo lo hecho:** local, en el Mac. **No es producción:** sin SSH, sin peticiones al servidor y sin commit (lo hace el lead).

## 0. En una pantalla

| Entrega | Resultado real |
|---|---|
| 1. Monitor con bandera | **12/12 pruebas en SQLite y en la puerta PG** (135 aserciones). `UltronEngineIsolationTest`: 7/7. `schedule:list` pasa de **26 a 28** entradas solo con la bandera encendida. Pint en verde |
| 2. Runbook de costes | §6 |
| 3. `ironbody-migrate` sin root | **21/21** en el banco (§4.3). H-5 de SEC7 corregido. `.env` escribible → rechazo. `find -L`. Guarda contra `PG*`/`LD_*` |
| 4. Supresión Ley 1581 | **17/17** en el banco, con el dueño **sin superusuario**; tras redactar `reason`, la cadena sigue verificando (§4.4) |
| 5. Respaldos | Política (§7). Ensayo `-Fc`: **14/14**, recuento exacto de las **226** tablas, RTO 1 s. **Hallazgo H-6:** `--no-owner` (§4.5) |
| 6. Scripts del H7 | Despliegue en el banco con un 55P03 real: **`DESPLIEGUE H7 (BACKEND) COMPLETADO`** dos veces (50 s y 47 s). Smoke **22–23 OK · 0 FALLO** |
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
  - `ironbody-migrate-propuesta.sh`;
  - `supresion-1581.sql`.
- **`ops/banco-h7/`:**
  - `README.md`, `comun.sh`, `montar.sh`, `preparar.sh`, `ensayar.sh`, `sembrar.sh`, `sembrar-1581.php`, `lector.php`;
  - `ensayo-supresion.sh`, `ensayo-migrador.sh`, `ensayo-restauracion.sh`, `ensayo-recuperacion.sh`, `ensayo-vuelta.sh`.
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
- **Detalle de motor:** en PostgreSQL, `incidents.evidence` es `jsonb` y reordena las claves de cada objeto (`id`, `actual`, `expected`). Lo cazaron mi puerta PG y la de B7. La prueba compara **con las claves ordenadas en los dos lados y `assertSame`**: mismos valores, mismos tipos, y el orden de las listas cuenta. Pasa aislada en PG (10 aserciones) y en el fichero entero de PG y de SQLite (12 pruebas, 135 aserciones).

## 3. Scripts del despliegue del H7 (entrega 6)

### `deploy-h7-backend.sh <commit> <respaldo> [--solo-comprobar]`

Calca el del H6.

- **`PREVIO=pendiente`:** se niega a todo, también a `--solo-comprobar`, hasta fijarlo en un commit con el HEAD de producción del día.
- **Paso 0, de solo lectura:**
  - checkout limpio, avance rápido y exactamente 1 migración;
  - **puerta de diff con lista explícita**: los 7 controladores de M-2 y `AdminUserController` (B7), la API interna de conocimiento (SEC7) y `console.php`, que solo puede añadir líneas;
  - respaldo de menos de 2 h, en 700/600, con `.sha256` y **≥ 226 tablas**; `.env` igual al respaldo;
  - esquema `43 49 4 14 iron iron,iron,iron true 1 0`, huella de F, **cadena consecutiva** (si no, la migración se negaría) y 0 cerrojos sobre la cadena;
  - 56 rutas, 13/0 workers y facturación activa.
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
  - monitor apagado y **0 tareas del Studio**; motor, H4, H5 y H6 iguales;
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

### `smoke-run-h7.sh` y `smoke-h7.py`

Cuenta 32, contraseña de un solo uso, huellas saladas, trap. Usa el detector de fugas de `smoke-h6.py`, así que se copian los dos.

- **El lanzador comprueba:** monitor apagado, 0 tareas programadas, 14 tablas idénticas, 0 incidentes y que el token muere.
- **`smoke-h7.py` comprueba:**
  - `no-store` en todas las respuestas de `/api/admin`;
  - 413 sin sesión y con sesión, y 401 por debajo del tope;
  - `verified` por entrada y en el detalle de versión, y que el de la cabeza es igual a `signatureOk`;
  - 5 invisibles → 422, con 2 contrapruebas: texto limpio y un emoji con ZWJ y VS16 → 409 de la bandera;
  - sin fugas, sin 5xx y sin 429.
- **Sin filas positivas:** solo envía lo que el servidor rechaza antes de escribir.

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

## 5. Runbooks por fase (producción; todo con autorización)

1. **Condiciones:**
   - el H6 en su fase 1, verificado a las 24 h;
   - REV7 sin bloqueantes;
   - push con avance rápido;
   - **`PREVIO` fijado en un commit** con el HEAD de producción del día.
2. **Respaldo:** el del H6 con `BK=/var/backups/ironbody/ultron-studio-h7-<ts>`, en `umask 077` y ≥ 226 tablas.
3. **Copiar los scripts** (con su huella):
   ```bash
   O=docs/ultron-studio/ops
   shasum -a 256 $O/{deploy-h7-backend.sh,h7-schema-check.sql,smoke-h7.py,smoke-run-h7.sh,smoke-h6.py}
   scp $O/{deploy-h7-backend.sh,h7-schema-check.sql,smoke-h7.py,smoke-run-h7.sh,smoke-h6.py} ironbody-vps:/root/
   ```
   En el servidor: `.sh` en 700, el resto en 600, y `sha256sum` para comparar.
4. **Esquema antes:**
   ```bash
   sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
   ```
   Esperado: 43/49/4/14, `existe_rol_app` 1, `rotas` 0, 0 cerrojos.
5. **Ensayo:** `bash /root/deploy-h7-backend.sh <commit> "$BK" --solo-comprobar` → `COMPROBACIONES PREVIAS EN VERDE`.
6. **Despliegue:** `bash /root/deploy-h7-backend.sh <commit> "$BK" 2>&1 | tee /root/h7-$(date -u +%Y%m%d-%H%M%S).log` → **`DESPLIEGUE H7 (BACKEND) COMPLETADO`**.
   - Con un 55P03 agotado, se relanza: reanuda.
7. **Esquema después:** el mismo `.sql`. Esperado: 44/50, `uspub_chain_next:O` y `ar` en las 3 tablas.
8. **Smoke:**
   - `bash /root/smoke-run-h7.sh` → 0;
   - regresión: `SMOKE_PHASE=1 bash /root/smoke-run-h6.sh`, copiándolo otra vez (cupo de 3 por hora).
9. **A los 15 min y a las 24 h** (solo lectura):
   - `sudo -u www-data php artisan ultron-studio:monitor --dry-run`: las tres comprobaciones;
   - `supervisorctl status`, `failed_jobs`;
   - ERROR en el log;
   - 5xx en `access.log` de `/api/admin`;
   - `curl -sI …/api/admin/ultron-studio/status | grep -i cache-control`.
10. **Encender el monitor**, con autorización:
    - copia de `.env` en 600;
    - `ULTRON_STUDIO_MONITOR_ENABLED=true` con `set_var` (mismo inodo y modo);
    - `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'` y 600 (como www-data);
    - `schedule:list` con **2** tareas del Studio;
    - primera pasada: `ultron-studio:monitor` → 0 y ningún incidente `ultron_studio` nuevo.
    - Antes, revisar **H-1** (§8): con `schedule:run` en el crontab de root, el monitor corre como root.

**Vuelta atrás:**

| Nivel | Qué se hace | Qué se queda |
|---|---|---|
| 2 · Banderas | `ULTRON_STUDIO_MONITOR_ENABLED=false` + `config:cache`: 0 tareas | — |
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

## 9. Pendiente de validar en producción, y lo que queda del propietario

- **Producción** (no ensayable en local):
  - el `--check` y el `--pretend` del nuevo `ironbody-migrate` (`setpriv`, usuarios, `flock` y `sudo` reales);
  - `--solo-comprobar` del despliegue;
  - GNU `stat`/`find`;
  - dueños `root` y `www-data`;
  - FPM;
  - los tiempos reales del 55P03 con el motor leyendo.
- **Del propietario:**
  - instalar `ironbody-migrate` (pasos 0–7 del script, con 3b y 6b);
  - `log_error_verbosity = terse` (H-2 del H6; nota de DB7);
  - H-6;
  - la copia de respaldos fuera del VPS;
  - autorizar el despliegue y el encendido del monitor;
  - decidir si, en una supresión del personal, se quita su nombre de la autoría (trazabilidad).
- **Limpieza local** (mía, cuando el lead diga):
  - los bancos `~/.cache/ironbody-banco-h7{,-vuelta}`;
  - sus bases `ultron_studio_banco_h7{,_vuelta}`;
  - mis bases de pruebas `ultron_studio_h7_ops` y `ultron_studio_h7_ops7`.
  - Las órdenes están en `banco-h7/README.md`.
