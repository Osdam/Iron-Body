# Hito 6 · Informe de devops: scripts de operación, banco local y runbook de las fases 1 y 2

**Fecha:** 31-10-2026 (01-11 UTC). **Rama:** `feat/ultron-studio-h6` (worktree `_worktrees/ultron-h6`). **Modo:** todo en local; sin SSH, sin peticiones a producción, sin commit. **Entorno de todo lo ensayado: local (no es producción).** Producción, según el lead (no verificado por mí): API `7ba0ce8` (fase 1 del H5), 13 workers, `failed_jobs` 2, esquema 30/27, 11 tablas `ultron_studio_*`.

**Ficheros (exclusivos de OPS):** `ops/deploy-h6-backend-fase1.sh`, `ops/h6-integrations-scope.sh`, `ops/smoke-h6.py`, `ops/smoke-run-h6.sh`, `ops/h6-schema-check.sql`, `ops/test_find_leaks_h6.py` (nuevo, autorizado) y este informe, confirmados por el lead en `34ab7b3`. **Añadido el 01-11:** el banco local, ya en el repo en `ops/banco-h6/` (§8).

**Actualización del 01-11-2026, sobre `d2c04a0`:** S confirmado en `4e6f5fe`, `origin/main` fusionado y `7ba0ce8` como ancestro (H-2 resuelto por el lead).
- Con el banco del repo:
  - despliegue con un 55P03 provocado → `FASE 1 (H6) COMPLETADA` (45 s; 124 ficheros del merge legibles por `www-data`);
  - smoke F1 **78 + 26 OK**, F2 con la sonda apagada **73 + 26** y F2 con la sonda encendida **73 + 26**, con 0 FALLO y las 14 tablas idénticas en cada pasada.
- Contra la rama real: exactamente las 2 migraciones del H6 y la puerta de diff vacía.
- Pendiente: el ensayo contra el arnés E2E del CRM, cuando el lead avise de que S está en verde (§8).

## 0. En una pantalla

| Qué | Resultado |
|---|---|
| `bash -n` (3 scripts), `py_compile` (2), `shellcheck` | OK · OK · **no instalado** (no lo instalé) |
| Detector de fugas sin red (`test_find_leaks_h6.py`) | **54/54**; 7 mutaciones del detector, las 7 cazadas. El del H5 sigue 22/22 |
| Funciones del entorno (`estado_var`, `presencia`, `set_var`) extraídas tal cual | **21/21** (sangría, `export`, `K = "v"`, repetidas, prefijos, sin salto final, inodo y modo conservados) |
| Configuración efectiva por tinker contra el código de la rama | idéntica a la esperada de la fase 1 |
| `h6-schema-check.sql` | antes 30/27/…/2/11, después 43/49/5-5-3/4-12-6/2/4/t/14; detecta un cerrojo abierto |
| **Banco** (clon en `7ba0ce8`, `origin` = H6 + `origin/main` + instantánea del WIP de S, PG 14 con roles dueño/app; repetido el 01-11 con el banco del repo sobre `d2c04a0`) | despliegue limpio con **55P03 real reintentado → `FASE 1 (H6) COMPLETADA`** (46 s); reanudación sin duplicar; 6 negativas del paso 0; BACKOFF → desvío; 42501 → aborta sin `route:cache` |
| Alcance (`writes`/`probe`) en el banco | idas y vueltas `APLICADO`; `probe` añade la instantánea de Twilio, `writes` ninguna |
| Smoke en el banco | F1 **78+26 OK**; F2 sonda apagada **73+26**; F2 sonda encendida **73+26**; 14 tablas idénticas; 0 FALLO |

**Hallazgos para el lead (detalle en §7):**
- H-1: en la fase 2, reconocer Twilio **después** de `probe on`. Ya es orden obligatorio del runbook (§4).
- H-2: la rama no contenía `7ba0ce8`. **Resuelto** con `d2c04a0`.
- H-3: el smoke cabe **3 veces por hora**, por el cupo de la sonda.
- H-4: `MIN_TABLAS=223`.

H-3 y H-4 van como notas en los runbooks.

## 1. Impacto de la fase 1

- **Cambia:** código (18 rutas nuevas, 56), 2 migraciones aditivas (3 tablas, guardas, CHECK del libro a 7 sujetos), 8 líneas del entorno (todo apagado), cachés, `queue:restart`, estáticos del CRM y 3 instantáneas de sistema.
- **No cambia:** motor, n8n, Meta, Twilio, ElevenLabs, FPM, supervisor, DNS, TLS ni firewall. Socios, caja y ULTRON no notan nada.
- **Irreversible:** las migraciones (nunca `down()` en producción) y las instantáneas (solo inserción). El smoke deja la fila 32 en reposo, 2 sesiones borradas y líneas INFO/WARNING en el log.
- **Barrera doble:** las 18 rutas no se sirven hasta `route:cache`, que solo corre con 43/49/4/14. Aunque se sirvieran, las escrituras (47–54) y la sonda (56) están tras sus banderas.

## 2. Qué hace cada script

**`deploy-h6-backend-fase1.sh <commit 7 cifras> <respaldo> [--solo-comprobar]`** (modelo: el de `7ba0ce8`, ejecutado el 01-11):

- **Paso 0, solo lectura:**
  - `PREVIO=7ba0ce8` (se actualiza al desplegar) o reanudación;
  - checkout limpio, `origin/main` = esperado y avance rápido;
  - las 2 migraciones exactas;
  - **puerta de diff del contrato + `composer.json/lock`** (el script no hace `composer install`);
  - respaldo de menos de 2 h, directorio 700, volcado y `env.backup` de root en 600, `.sha256` verificado, **≥ 223 tablas**, `.env` = `env.backup`;
  - las 8 claves ausentes o iguales;
  - **ninguna pieza de ElevenLabs ni `TWILIO_VOICE_FROM`**;
  - piezas de la clave restringida y alias de Twilio solo por presencia;
  - esquema `30 27 2 11 iron iron iron true 0`, huella de F `4ad748b5…`, 0 cerrojos sobre libro, versiones y tablas del H6;
  - 38 rutas, 13/0 workers, facturación activa;
  - configuración del motor/H4/H5 anotada.
- **Después:**
  - merge como root con `umask 022` y lectura por `www-data` fichero a fichero (con recuento);
  - variables y Dotenv;
  - `ironbody-migrate` con **reintento acotado del 55P03** (3 intentos, 15 s);
  - **compuerta 43/49/4/14 + guardas por prefijo, 7 sujetos, dueños, privilegios de `iron_app` (con UPDATE) y 0 filas ANTES de `route:cache`**;
  - cachés 600 y `queue:restart`.
- **Aserciones:**
  - 56/18/8 GET/10 POST y cobertura vacía;
  - configuración efectiva exacta y motor sin cambios;
  - workers 13/0 dos veces, esperando (informa cuántos pid cambiaron);
  - 4 × 401 y `usage-trigger` 403;
  - colas fallidas por SQL, sellos, `check-secret-exposure`, `publication-check` y `.env` = respaldo salvo las 8 claves;
  - doctor por códigos de salida: Meta `degraded` = INFO;
  - instantánea **solo con 0 desvíos**;
  - ERROR nuevos, descontando como mucho una excepción 55P03 por reintento.
- **Las «10 variables»:** se escriben 8:
  - `…WRITES_ENABLED=false`, `…TWILIO_PROBE_ENABLED=false`;
  - `…CANARY_MAX_ACTIVE=3`, `…CANARY_COUNTRY_CODES=57`, `…CAMPAIGN_LINKS_MAX_ACTIVE=50`;
  - `ELEVENLABS_ENABLED=false`, `TWILIO_VOICE_ENABLED=false`, `TWILIO_VOICE_DAILY_CALL_CAP=0`.

  `…PROBE_KEY_SID/_SECRET` son del propietario y nunca se escriben; `…PROBE_BASE` se ignora en production (AVISO si está).

**`h6-integrations-scope.sh writes|probe on|off <actual>` · `estado`:**
- independientes;
- sin `<actual>`, imprime y sale con 2;
- copia `/root/env-pre-h6-…` 600;
- reescribe **el mismo fichero** (conserva dueño y modo; sin `sed -i`);
- Dotenv, `config:cache` con `umask 077` y 600;
- configuración = la de antes con solo esa llave cambiada, y motor igual;
- `queue:restart`, 13/0 dos veces, 401;
- instantánea de sistema;
- `APLICADO` solo en verde.

**`h6-schema-check.sql`** (por la entrada estándar), con lo esperado en cada columna:
- recuentos, prefijos y parciales;
- 7 sujetos;
- función de F;
- dueños y `CREATE`;
- eventos del libro;
- cerrojos (5 tablas);
- privilegios uno a uno;
- instantáneas por proveedor.

**`smoke-run-h6.sh` + `smoke-h6.py` (`SMOKE_PHASE=1|2`):**
- **Base:** el lanzador del H5 (cuenta 32, contraseña de un solo uso, trap, tokens 0600) más:
  - las banderas exactas de la fase;
  - número de cuentas de Meta;
  - **huellas saladas de los secretos calculadas dentro de PHP** (centinela exacto; no se lee el teléfono del lead canario);
  - fotografía de las 14 tablas: un cambio en una tabla del H6 es FALLO.
- **Sin filas positivas:**
  - fase 1: escrituras con cuerpo válido → 409 de bandera; el registro (49) solo con la bandera apagada vista en la misma ejecución y con una versión esperada inventada; la sonda → 409;
  - fase 2: recursos inventados → 404 tras la bandera, y el 49 y la sonda encendida con un cuerpo inválido → 422;
  - el 55 no se llama con el Super Admin;
  - el Administrador ve 403 en 50, 55 y 56.
- **Detector:** claves prohibidas (con los nombres de campo del 422 exentos), contenedores de secretos, formatos de SID/tokens/claves, teléfonos e ids de 10 cifras, conversación, id del canario y centinela.

## 3. Runbook de la fase 1

1. **Condiciones** (las del contrato, ninguna de devops):
   - puertas en verde: `reviewer`, `pgunit.sh`, suites del mismo día, E2E `h6-integrations` + regresión del H5 con la valla vacía, `security` y `api-security-specialist`;
   - la rama del H6 con `origin/main` fusionado (hecho en `d2c04a0`, H-2) y push con avance rápido;
   - `<commit>` = `git rev-parse --short=7 origin/main`.
2. **Respaldo** (servidor, root):

   ```bash
   umask 077; TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h6-$TS; mkdir -p "$BK"
   git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"
   sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
   sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
   pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'   # ≥ 223
   tar -C /var/www -czf "$BK/app-crm.tgz" app
   install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
   cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"; cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d"
   stat -c '%a %U %n' "$BK" "$BK"/*; echo "BK=$BK"
   ```

   > **Nota H-4.** El paso 0 exige que el volcado liste al menos 223 tablas: las 222 del volcado del H5 del 01-11 (antes de migrar) más `ultron_studio_publications`. Si aborta por eso, cuenta las tablas a mano (`pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'` frente a `SELECT count(*) FROM pg_tables WHERE schemaname = 'public'`) antes de pensar en bajar el umbral, que solo se cambia en un commit.
3. **Copia de los scripts** (Mac, árbol en el commit desplegado):

   ```bash
   O=docs/ultron-studio/ops
   shasum -a 256 $O/{deploy-h6-backend-fase1.sh,h6-integrations-scope.sh,smoke-h6.py,smoke-run-h6.sh,h6-schema-check.sql}
   scp $O/{deploy-h6-backend-fase1.sh,h6-integrations-scope.sh,smoke-h6.py,smoke-run-h6.sh,h6-schema-check.sql} ironbody-vps:/root/
   ```

   Después, `chmod 700` a los `.sh`, `chmod 600` a los `.py`/`.sql` y `sha256sum` para comparar. El lanzador del smoke borra sus copias al terminar.
4. **Esquema antes:** `sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h6-schema-check.sql`. Esperado: 30/27, 0 en todo lo del H6, `funcion_es_la_de_f` t y 0 cerrojos.
5. **Ensayo:** `bash /root/deploy-h6-backend-fase1.sh <commit> "$BK" --solo-comprobar` → `COMPROBACIONES PREVIAS EN VERDE`.
6. **Backend:** `bash /root/deploy-h6-backend-fase1.sh <commit> "$BK" 2>&1 | tee /root/h6-fase1-$(date -u +%Y%m%d-%H%M%S).log` → `FASE 1 (H6) COMPLETADA`.
   - Con un `ABORTA` en el paso 3 (55P03 agotado): no hay `route:cache`. Se mira quién tiene cerrojos con el `.sql` y **se relanza el mismo comando** (reanuda).
   - No se sigue con desvíos.
7. **Esquema después:** el mismo `.sql`. Esperado:
   - 43/49, 5-5-3, 4-12-6, 2/4 y 7 sujetos `t`;
   - la misma huella, 14 tablas;
   - privilegios SELECT/INSERT/UPDATE `t`, TRUNCATE `f`, secuencias `t`;
   - 3 instantáneas `config/system`.
8. **CRM:** `ops/deploy-crm.sh <build-crm> ab78a6a /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body_Front`. **El tercer argumento es obligatorio:** por defecto apunta al worktree del H5.
9. **Smoke:** `ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h6.sh'` → código 0 (≈78 + 26 OK; 77 si Meta sale `degraded`).

   > **Nota H-3.** Como mucho **3 smokes por hora** con la cuenta 32: el cupo de la sonda (6/h por persona) corre antes de autenticar, y cada smoke gasta 2 (Super Admin y Administrador). Una cuarta ejecución da 429 en la sonda, que cuenta como FALLO. También hay un tope de 3 por minuto, por el login (8/min por IP).
10. **No regresión:**
    - la sonda n8n↔backend sin efectos (procedimiento del lead, como en el H5);
    - `SELECT max(started_at) FROM meta_sync_runs;` posterior al despliegue en la hora siguiente;
    - `usage-trigger` 403 (ya en el script);
    - **sin OTP de prueba**.
11. **A los 15 min y a las 24 h** (solo lectura):
    - `supervisorctl status`: 13 RUNNING y uptime desde el reinicio;
    - `failed_jobs` igual;
    - `integrations-doctor --expect=…` → 0;
    - `voice:doctor` → `ELEVENLABS_NOT_CONFIGURED`;
    - `publication-check` 0, `queue:health`, `check-secret-exposure`;
    - ERROR del log frente al paso 0;
    - 0 respuestas 5xx en `access.log` para `/api/admin/ultron-studio/integrations`;
    - las tres tablas a 0 salvo las 3 instantáneas.

## 4. Runbook de la fase 2

Con la fase 1 verificada 24 h y dos personas disponibles. **El orden es obligatorio** (H-1): la huella de la configuración de Twilio incluye `facts.probe.enabled`. Si la configuración se reconoce antes de encender la sonda, `probe on` la deja en `drift`, y el criterio de cierre exige los 3 proveedores en `authorized`. Por eso el reconocimiento (paso 6) va **después** de `probe on` (paso 4).
- Si alguien reconoció antes, se **vuelve a reconocer Twilio** después de `probe on`.
- Si la sonda se apaga más tarde (`probe off on`), Twilio vuelve a `drift` hasta que se reconozca de nuevo.

1. `bash /root/h6-integrations-scope.sh writes on off` → `APLICADO`.
2. `SMOKE_PHASE=2 bash /root/smoke-run-h6.sh` → 0. Hay que copiar antes otra vez `smoke-h6.py` y `smoke-run-h6.sh` a `/root/`: el lanzador borra sus copias al terminar.
3. **T1 con personas reales:**
   - A (tiene el teléfono de la conversación canaria) registra el número de WhatsApp;
   - B (Super Admin, ≠ A) lo autoriza y lo activa → `in_sync`;
   - evidencia sin datos personales: `SELECT channel, status, registered_by_admin_id <> authorized_by_admin_id, activated_by_admin_id <> registered_by_admin_id FROM ultron_studio_canary_numbers WHERE status <> 'revoked';` y `SELECT event, actor_admin_id, created_at FROM ultron_studio_audit_events WHERE subject_type = 'canary_number' ORDER BY id;`.
4. `bash /root/h6-integrations-scope.sh probe on off` → `APLICADO`, con una instantánea de sistema nueva de Twilio (la huella cambió).
5. **Una** pulsación de la sonda desde el CRM. Evidencia: `SELECT outcome, created_at FROM ultron_studio_integration_snapshots WHERE kind = 'probe' ORDER BY id DESC LIMIT 1;`.
6. **Obligatorio después del paso 4:** un Super Admin reconoce `meta_ads`, `twilio` y `voice` → los tres en `authorized`. Comprobación: `SELECT provider, count(*) FROM ultron_studio_integration_snapshots WHERE kind = 'config' AND actor_type = 'admin' GROUP BY provider;` y `GET integrations/config`, sin `drift`.
7. `SMOKE_PHASE=2` otra vez, con la sonda encendida → 0. Ese smoke **no** pulsa la sonda: le manda un cuerpo inválido, 422.

> **Nota H-3.** Los smokes de esta fase cuentan para el mismo cupo que los de la fase 1: como mucho 3 por hora con la cuenta 32.

## 5. Vuelta atrás (niveles del contrato)

- **1 · Studio:** desactivar asociaciones y revocar números desde el CRM.
- **2 · Banderas:** `h6-integrations-scope.sh writes off on` / `probe off on`.
- **3 · Código:**

  ```bash
  cd /var/www/api && umask 022 && git checkout -q --detach 7ba0ce8
  cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
  sudo -u www-data php artisan queue:restart
  ```

  Esperado: 38 rutas, cobertura vacía y 13/0. Después, `SMOKE_PHASE=1 bash /root/smoke-run-h5.sh`, y el CRM desde su respaldo.
  - Las 8 claves y las tablas se quedan.
  - **Para volver al H6:** `git checkout -q main` + las mismas cachés + `queue:restart` + smoke H6. **No** el script de despliegue: exigiría el esquema del H5 y abortaría.
- **4 · Esquema:** `down()` **nunca en producción**.
- **Datos:** solo con autorización y coordinado por `database-specialist`, restaurando en una base aparte.

## 6. Incidencias previsibles

| Síntoma | Qué hacer |
|---|---|
| `ABORTA` en el paso 0 | No se tocó nada. Aclarar y repetir |
| 55P03 | Reintento automático. Si se agota, no hay `route:cache` y quedan 3 tablas sin guardas que nadie puede usar. `.sql` para ver los cerrojos y relanzar el mismo script |
| 42501 al migrar | Dueños: parar y avisar a `database-specialist`. Ensayado en el banco: aborta sin reintentar |
| Workers en BACKOFF | `stat` de las cachés; volver a generar la caché como `www-data` con `umask 077` + 600. `supervisorctl restart` solo con autorización |
| Meta `degraded` | Es un dato, no un fallo |
| Doctor ≠ Twilio, voz o ElevenLabs | Desvío: revisar la configuración (ElevenLabs no debe tener piezas) |
| Smoke 429 | Más de 3 ejecuciones por hora (sonda) o por minuto (login): esperar |
| Smoke «no se pudo activar la cuenta» | Otra fila de `admins` tiene el correo del smoke. Falla sin efectos |
| Veredicto `engine_canary_phone_unusable` | El lead canario no tiene teléfono: dato de producción que hay que aclarar |

## 7. Hallazgos, riesgos y deuda

- **H-1 (incorporado al runbook de la fase 2 como orden obligatorio):** `facts.probe.enabled` forma parte de la huella de Twilio. Con el orden del contrato (reconocer → `probe on`), Twilio quedaría en `drift`.
- **H-2 (resuelto por el lead):** la rama nacía de `e1b1d26` sin `7ba0ce8`. `d2c04a0` fusiona `origin/main`, y `PREVIO=7ba0ce8` sigue siendo correcto. Comprobado sobre la rama: entre `7ba0ce8` y `HEAD`, exactamente las 2 migraciones del H6 y la puerta de diff vacía.
- **H-3:** el cupo de la sonda corre **antes** de autenticar. Los 403/409/422 del smoke gastan 2 de 6/h por persona, así que caben 3 smokes por hora.
  - Además, las llamadas denegadas desde la oficina comparten el cupo por red (20/h). Es informativo para `api-security-specialist`.
- **H-4:** `MIN_TABLAS=223` = 222 del volcado del 01-11 más la tabla de publicaciones. Si aborta, contar a mano antes de bajarlo.
- **Expectativas fijadas a hechos de producción:** los alias de Twilio coherentes (`true`) y 3 cuentas de Meta. Si cambian, el script lo marca como desvío.
- **No verificado:**
  - GNU `stat`/`grep`, `sudo` real, el envoltorio `ironbody-migrate` real (en el banco hubo que vaciar la caché antes de migrar como dueño), los dueños reales, FPM viendo la caché nueva sin recargar, ni datos reales de Meta/Twilio;
  - el arnés E2E con el smoke: pendiente del aviso del lead (§8). El banco ya se repitió con el código confirmado de S (`d2c04a0`).
- **Secretos:** ninguno impreso. Las copias `/root/env-pre-h6-*` y `env.backup` llevan secretos (600); quitarlas lo decide el propietario.
- **Banco local:** las herramientas ya están en el repo (`ops/banco-h6/`, §8). En el clúster local queda la base `ultron_studio_banco_h6`; se borra con `dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h6`, y el directorio del banco con `rm -rf`, siempre fuera del repo.

## 8. Pendiente de validar contra la pila real (comandos exactos)

1. **Banco local, ya en el repo** (`ops/banco-h6/`; ver su `README.md`).
   - **Herramientas:**
     - `montar.sh`: clon en `7ba0ce8` con `origin` = la ref; PG 14 con `h5_owner`/`h5_app`; fichero de entorno sintético; respaldo; sustitutos de `sudo`, `stat`, `supervisorctl`, `systemctl`, `sha256sum` e `ironbody-migrate`;
     - `preparar.sh`, `sembrar.sh`, `api.sh`;
     - `ensayar.sh` (`desplegar [--55p03] [--solo-comprobar]`, `alcance`, `smoke`);
     - `prueba-entorno.sh` (23/23).
   - **Garantías:** sin credenciales ni volcados en el repo, y se niega a montar el banco dentro de él.
   - **Uso, con la ref confirmada en el worktree** (hecho el 01-11 sobre `d2c04a0`, en verde):

     ```bash
     O=backend/docs/ultron-studio/ops/banco-h6; B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h6
     bash $O/montar.sh $B && bash $O/api.sh $B start
     bash $O/ensayar.sh $B desplegar --solo-comprobar && bash $O/ensayar.sh $B desplegar --55p03
     bash $O/sembrar.sh $B && bash $O/ensayar.sh $B smoke 1
     bash $O/ensayar.sh $B alcance writes on off && bash $O/ensayar.sh $B smoke 2
     # sonda encendida: ULTRON_STUDIO_TWILIO_PROBE_BASE=http://127.0.0.1:9 en $B/api/backend/.env + config:cache, y luego
     bash $O/ensayar.sh $B alcance probe on off && bash $O/ensayar.sh $B smoke 2
     bash $O/ensayar.sh $B alcance probe off on && bash $O/ensayar.sh $B alcance writes off on && bash $O/api.sh $B stop
     ```
2. **Arnés E2E del CRM: EJECUTADO el 01-11-2026**, con backend `d2c04a0` y CRM `ede716b`:
   - **Pila:** `E2E_HOLD=1 bash e2e/ultron-studio/run.sh d2c04a0 ede716b none`, desde `_worktrees/ultron-h6/Iron-Body_Front`, en pie en 88 s, con la valla y los simuladores de Twilio (:8095) y ElevenLabs (:8096) en marcha.
   - **Preparación,** como haría el despliegue (lo que `run.sh` siembra con `h6-integrations` en la lista):
     - `h6-seed.php` con `meta-ads` (las 3 cuentas), `canary-lead <waId>` y el refresco de la cuenta 3;
     - `ultron-studio:integrations-snapshot`: 3 observadas;
     - una cuenta de smoke inactiva en `ultron_studio_e2e`;
     - y la huella de inicio rehecha (la original se guardó como `fingerprint-start.orig.json`), para que el cierre mida solo el smoke.
   - **Clasificación del doctor antes del smoke:** la de producción al congelar (Meta `connected_read`, Twilio `connected_otp_only`, voz `not_configured`, canarios `engine_canary_not_registered`, ElevenLabs `ELEVENLABS_NOT_CONFIGURED`).
   - **Smoke** (lanzador y `smoke-h6.py` del repo, `SMOKE_API=http://127.0.0.1:8080/api` por el proxy, `SMOKE_RUN_AS=`, `APP_DIR=$RUN/backend`, `SMOKE_PSQL` contra `ultron_studio_e2e`):

     | Pasada | Super Admin | Administrador | Base |
     |---|---|---|---|
     | Fase 1 | **78 OK · 0 FALLO · 0 OMITIDO** | **26 OK** | 14 tablas idénticas; 0 filas que nombren la cuenta; tokens muertos (403); 0 sesiones |
     | Fase 2, sonda apagada (`…WRITES_ENABLED=true` + `config:cache`) | **73 OK · 0 FALLO** | **26 OK** | Igual |
     | Fase 2, sonda encendida (`…PROBE_ENABLED=true` + `config:cache` + `integrations-snapshot`, como `h6-integrations-scope.sh`) | **73 OK · 0 FALLO** | **26 OK** | Igual; observado de Twilio = efectivo |

   - **Simuladores:** de Twilio y de ElevenLabs, **0 peticiones y 0 violaciones** en las cuatro pasadas. El smoke nunca pulsa la sonda: con la sonda encendida, 422; para el Administrador, 403.
   - **`cierre.cjs` del arnés al terminar: 12/12.**
     - Las 17 tablas del motor y las colas, idénticas.
     - 258 peticiones por el proxy sin 5xx.
     - `laravel.log` sin ERROR; `failed_jobs` y colas vacíos.
     - Los 4 simuladores con 0 violaciones y la valla vacía.
     - Ninguno de los 12 secretos de la ejecución en 22 registros ni en 12 tablas.
   - **Hallazgo del arnés, no de S ni de los scripts:** con `artisan serve --no-reload`, el proceso web fija el entorno al arrancar y **no ve un cambio del `.env`**.
     - El primer intento de la fase 2 lo cazó el propio smoke: el CLI daba `writes_enabled: true` y el web, `flags.writesEnabled: false`, con 409 en 47–54. Eran 16 FALLO, con la base intacta.
     - Se resolvió como en producción, con `config:cache` en la instantánea; al final, `config:clear`.
     - Es la comprobación cruzada que `h6-integrations-scope.sh` delega en el smoke («la vista del proceso web la comprueba el smoke»).
   - **Cupo:** ese intento gastó 2 de las 6 llamadas por hora de la sonda de la cuenta, así que la tercera pasada fue con una segunda cuenta (el correo del smoke es único y hubo que cambiárselo a la primera). Confirma H-3.
   - **Parada:** SIGTERM a `run.sh`, no al envoltorio (trampa 34 del arnés: el primer SIGTERM fue al envoltorio). Al final, «procesos parados» y los 8 puertos libres. Las llaves volvieron a `false` antes del cierre.
3. **Servidor:** `--solo-comprobar` (cubre GNU, `sudo`, los dueños y el envoltorio).

## 9. Acciones pendientes de autorización

| # | Acción | Comando |
|---|---|---|
| 1 | Commit de estos ficheros | Lo pide el lead; yo no lo hago |
| 2 | Fusionar `origin/main` en la rama del H6 y push con avance rápido | Lead, nunca `--force` |
| 3 | Fase 1 | §3 (autorización del 25-10) |
| 4 | Fase 2 y sonda | §4 (autorización del 25-10; T1 con dos personas) |
