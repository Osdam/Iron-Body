# ULTRON STUDIO: plan de despliegue

**Alcance de esta versión:** la **vista previa visual** (Hito D). Solo CRM. Sin backend, sin migraciones, sin servicios externos, sin efectos comerciales. Las fases siguientes (persistencia, laboratorio, integraciones) tendrán su propio plan.

## Qué se publica

| Pieza | Contenido |
|---|---|
| Ruta `marketing/ultron-studio` | Tablero de las diez capas con datos de demostración; borrador solo en el navegador |
| Submenú «ULTRON Studio» bajo Mercadeo | Visible solo con `marketing.agent.manage` |
| Bandera `ultronStudioPreviewEnabled` | `true` en producción; con `false` la ruta y el submenú no existen |
| Dependencia nueva | `@foblex/flow` 19.3.0 y sus 4 paquetes (MIT), en un chunk diferido de ~480 kB (102 kB transferidos) que solo descarga quien abre el Studio |

**Quién la ve:** Super Admin y Administrador (los únicos roles con `marketing.agent.manage` por defecto). Recepción, Entrenador y Administrativo no ven el submenú y el guard los devuelve al inicio.

**Qué NO cambia:** la API, la base de datos, el motor de ULTRON, n8n, Meta, Twilio, Wompi, las reglas comerciales, las campañas, el resto del CRM.

## Antes de publicar (checklist)

- [ ] Suite del módulo: 46 pruebas (grafo, almacén, catálogo, página), incluidas las que vigilan la poda del catálogo, el saneado del borrador, las reglas de configuración y la versión de solo lectura.
- [ ] Suite completa del CRM: los mismos 16 fallos de la línea base, ninguno nuevo.
- [ ] `check:literals` y `test:agenda-tz`.
- [ ] E2E en Chrome real: 44 comprobaciones (carga, diez capas, conexiones SVG, indicador de zoom, zoom, pan, arrastre, deshacer/rehacer con geometría de las conexiones, inspector plegable, conectar válido/inválido con motivo, Supr sobre obligatoria, validación de configuración, validar, guardar, recarga, restaurar, versión publicada de solo lectura, cabecera sin desbordar, teclado, permisos, reduced-motion, móvil).
- [ ] Revisión de seguridad y revisión final sin bloqueantes.
- [ ] `npm run build:prod` con sello limpio (`dirty: false`) y sin errores de presupuesto.
- [ ] Commit local identificado.

## Pasos

1. **Respaldo** de lo publicado: `index.html` y `version.json` de `/var/www/app` a `/var/backups/ironbody/crm-pre-ultron-studio-<fecha>/`.
2. **Comprobar que nada se movió:** `origin/main` del CRM sigue en `4334dd8` y `version.json` público dice `4334dd8`.
3. **Publicar:** `rsync -a --no-owner --no-group` de `dist/frontend/browser/` a `/var/www/app/` **sin `--delete`** (las pestañas abiertas en caja siguen funcionando con sus chunks antiguos), después `chown -R root:root /var/www/app`.
4. **Verificar por la URL pública:** `index.html` apunta al `main` nuevo; `version.json` con el commit nuevo; el chunk del Studio responde 200; el `main` anterior sigue en 200; `/marketing/ultron-studio` responde 200 (la SPA sirve el shell; el guard decide dentro).
5. **Verificación con sesión real** (la hace el propietario): entrar como Super Admin o Administrador, abrir Mercadeo › ULTRON Studio, mover una capa, guardar, recargar. Entrar con una cuenta de Recepción y comprobar que no aparece el submenú.
6. **Observar** durante unos minutos: 0 errores 5xx en nginx (el Studio no llama a la API, así que no debería cambiar nada).

## Después

- **Push a `main`:** no está autorizado por defecto para cambios que afectan producción. Mientras el commit de la vista previa no esté en `origin/main`, **cualquiera que publique el CRM desde `origin/main` borraría el Studio**. Conviene autorizar el push justo después de verificar.
- **Apagar la vista previa sin desplegar código:** no es posible solo con configuración, porque la bandera va compilada. Apagarla es un cambio de una línea (`ultronStudioPreviewEnabled: false`) y una publicación; ver `ULTRON_STUDIO_ROLLBACK.md`.

## Hito F (persistencia): runbook de despliegue — EJECUTADO el 18-10-2026 (15:24–15:28 UTC)

Ejecutado tal como sigue, con el modelo de roles de PostgreSQL aplicado (ver abajo) y el smoke autenticado 22/22 (`ULTRON_STUDIO_FINAL_REPORT.md`). Para futuras migraciones: `sudo /usr/local/bin/ironbody-migrate` (rol dueño, `lock_timeout` 5 s, regenera la caché de configuración). Toca backend (migraciones, código, comando) y CRM. Orden obligatorio: **migraciones → código y caché → importación → CRM**; publicar el CRM antes que el backend dejaría el Studio en fase de error (el parser es estricto a propósito).

1. **Respaldo** de la base de producción (`pg_dump` de `ironbody` a `/var/backups/ironbody/`, como en entregas anteriores; el `.env` usa `DB_URL`).
2. **Comprobación previa (solo lectura):** que no existan `ultron_studio_*` ni la función `ultron_studio_forbid()`; que el rol de la base pueda crear funciones y disparadores (`\df ultron_studio_forbid` vacío; `SELECT has_schema_privilege(current_user, 'public', 'CREATE')`).
3. **Código del backend:** `git -C /var/www/api fetch && git merge --ff-only origin/main` (una vez autorizado el push de la rama a `main`), `composer install --no-dev` si cambió el lockfile (no cambió), **`PGOPTIONS='-c lock_timeout=5s' php artisan migrate` con `--database=pgsql_owner` (y la confirmación de producción)** — dos migraciones aditivas: 5 tablas, 11 CHECK, la función y **11 disparadores**; `lock_timeout` para no esperar detrás de una transacción larga al añadir la FK a `admins` —, `sudo -u www-data php artisan config:cache` y `route:cache` (las rutas están cacheadas en producción: sin esto las 19 rutas no existen), `chmod 600` de la caché y `queue:restart` (memoria `config-cache-y-los-workers`).

**Modelo de roles de PostgreSQL (hallazgo S1, aplicado el 18-10-2026):** la aplicación corre con el rol **`iron_app`** (LOGIN, solo `SELECT/INSERT/UPDATE/DELETE` en las tablas y `USAGE/SELECT/UPDATE` en las secuencias, con privilegios por defecto para lo que cree `iron`; sin `CREATE` en `public`); las migraciones corren con el rol **dueño `iron`** a través de la conexión `pgsql_owner` (`DB_OWNER_USERNAME`/`DB_OWNER_PASSWORD` las inyecta `/usr/local/bin/ironbody-migrate` desde `/root/.ironbody-owner.env`, modo 600, con la caché de configuración limpia; **nunca viven en el `.env` ni en la caché**; corrección del 19-10-2026, el texto anterior era inexacto). Un rol solo-DML no puede desactivar disparadores ni alterar tablas: la inmutabilidad del libro ya no depende de la buena fe de la aplicación. Reversión del modelo: volver a poner `DB_USERNAME=iron` en el `.env`, `config:cache`, `queue:restart`.
4. **Verificación SQL tras migrar** (debe salir 11 y 11):
   ```sql
   SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;
   SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';
   ```
5. **Importación de la línea base:** `php artisan ultron-studio:import-baseline --dry-run` y después sin `--dry-run` (12 reglas; repetible).
6. **Sanidad:** `php artisan auth:coverage --unmapped` vacío; `GET /api/admin/ultron-studio/status` con una sesión de Super Admin devuelve `engine.readsVersions: false` y `publishing.available: false`.
7. **CRM:** `npm run build:prod` sellado, `rsync -a --no-owner --no-group` **sin `--delete`**, `chown -R root:root`, verificación pública (`version.json`, chunk del Studio sin catálogo).
8. **Con sesión real:** Super Admin abre el Studio (fase `ready`), guarda un borrador, crea una versión y la envía; un Administrador distinto la aprueba; «Publicar» responde con el 409 del servidor. Recepción no ve el submenú.

**Antes del uso real (decisiones abiertas):** separar el rol de la base que migra del que ejecuta (S1: el dueño puede desactivar disparadores); confirmar `TrustProxies` para el cupo por red; decidir la retención de la auditoría si alguna fase vuelve a guardar IP o user agent.

## Hito G (control funcional): runbook de despliegue — EJECUTADO el 19-10-2026 (06:00–06:04 UTC)

**Estado: EJECUTADO** el 19-10-2026 entre las 06:00 y las 06:04 UTC, con el ciclo completo en verde y la autorización del usuario; evidencia paso a paso en `ULTRON_STUDIO_FINAL_REPORT.md` («Despliegue y verificación» del Hito G). Respaldos: `/var/backups/ironbody/ultron-studio-g-20261019-060047/` y `/var/backups/ironbody/ultron-studio-g-crm-20261019-060251/`. Parte del borrador del lead y está contrastado con el código de `391cffb`.

**Qué toca:**
- **Backend:**
  - una migración aditiva, `2026_10_19_100001` (la columna `conditions`);
  - el código de G: 2 rutas nuevas (21 en total) y el tope de cuerpo;
  - una importación de la línea base.

  No cambian `.env`, `config/`, `composer.lock` ni los permisos: no hay llaves nuevas.
- **CRM:** el build del commit final de G (hoy, `7d3ad2a`).

**Qué NO toca:** el motor de ULTRON (`git diff origin/main` sobre sus zonas da 0 líneas), `MARKETING_ULTRON_ENABLED`, n8n, Meta, Twilio, Wompi, las reglas comerciales ni las campañas.

**Orden obligatorio:** push con avance rápido → respaldo → backend (código, migración con el rol dueño y cachés como `www-data`) → `import-baseline` → CRM → smoke → verificación.
- **El CRM nunca antes que el backend.** El parser de G exige `bindings`: con el backend de F, el Studio se quedaría en fase `error`.
- **La ventana contraria es segura.** El backend de G con el CRM de F funciona: el parser de F ignora las claves nuevas, y los hallazgos del servidor mandan.
- **Precaución (inferida del orden de los comandos, no probada).** Entre el `git merge` y la migración, el código de G corre unos segundos sin la columna `conditions`, y crear una regla en ese intervalo fallaría. Conviene no usar el Studio durante el despliegue.

**0. Comprobaciones previas (solo lectura)**
- `git fetch` en los dos repos: `origin/main` sigue en `29ffa05` (backend) y en `020db86` (CRM), sin trabajo ajeno encima.
- Producción:
  - `/var/www/api` en `9acddc3`, con el árbol sin modificaciones;
  - el CRM sirve `main-7A6NMSXZ.js`;
  - 12 workers RUNNING;
  - 19 rutas del Studio.
- Regresión del día:
  - la suite completa del backend contra la línea base: los mismos 58 fallos, ninguno nuevo;
  - la del CRM contra sus 16 fallos conocidos.
- La revisión final (`reviewer`), sin hallazgos bloqueantes.
- El E2E con la pila real, sin fallos o con los que queden decididos por el lead. Al escribir esto: 124/126 en `391cffb`, con H-1 del E2E abierto (CRM: «Ver S-NNN» no lleva a la regla) y H-2 del E2E (la cabecera tapa el aviso a 1440 px).
- **Puerta obligatoria (H-2 del tester de backend).** `tests/Feature/UltronStudio tests/Unit/UltronStudio` en un PostgreSQL 14 desechable, en verde: unos 25 s, y 468/468 en `dfb30f5`. **Solo PostgreSQL detecta que falta el cerrojo de las reglas:** la suite SQLite por defecto sale verde sin él. Sin esta puerta no se despliega.

**1. Push, solo con avance rápido** (requiere la autorización del usuario)
- Backend: `git push origin feat/ultron-studio-g:main`, solo si es avance rápido sobre `origin/main`. Si hace falta, antes un `git merge --ff-only` local. **Nunca `--force`.**
- CRM: lo mismo, con `feat/ultron-studio-g:main`.

**2. Respaldo en el VPS**

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-g-$TS; mkdir -p "$BK"
cd /var/www/api/backend && git rev-parse HEAD > "$BK/api-commit.txt"
sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"     # con el rol que corresponda, como en F
tar -C /var/www -czf "$BK/app-crm.tgz" app
cp /var/www/api/backend/.env "$BK/env.backup" && chmod 600 "$BK/env.backup"
cp -r /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
```

Antes de seguir, comprobar el respaldo: el tamaño del volcado y `pg_restore -l "$BK/ironbody.dump" | wc -l`. La copia del `.env` lleva secretos: queda con modo 600 y nunca se imprime.

**3. Backend** (desde `/var/www/api`; la aplicación Laravel está en `backend/`)

```bash
cd /var/www/api && git fetch origin && git merge --ff-only origin/main     # HEAD esperado: el commit final de G
# composer install --no-dev: no hace falta (G no cambia composer.lock)
sudo /usr/local/bin/ironbody-migrate                                       # rol dueño, lock_timeout 5 s; regenera la caché de configuración
sudo -u www-data php backend/artisan route:cache && chmod 600 backend/bootstrap/cache/routes-v7.php
sudo -u www-data php backend/artisan queue:restart
```

- **La migración.** `ironbody-migrate` aplica una sola, `2026_10_19_100001`, con el rol dueño `iron` y `lock_timeout` de 5 s. Si falla con `55P03`, es que una transacción larga tenía la tabla: no queda nada a medias, y se reintenta.
- **Cachés de `www-data`, con modo 600.** Una caché que no sea de `www-data` deja los workers en BACKOFF en el siguiente reinicio (memoria `config-cache-y-los-workers`). Hay que comprobar el dueño y el modo de `backend/bootstrap/cache/config.php` y de `routes-v7.php`.
- **Verificación SQL:** 11 disparadores, 11 CHECK y la columna nueva.

  ```sql
  SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
   WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;                         -- 11
  SELECT count(*) FROM pg_constraint
   WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';               -- 11
  SELECT column_name, data_type, is_nullable FROM information_schema.columns
   WHERE table_name = 'ultron_studio_rules' AND column_name = 'conditions';               -- conditions | json | YES
  SELECT has_column_privilege('iron_app', 'ultron_studio_rules', 'conditions', 'SELECT,INSERT,UPDATE');  -- t
  ```
- **Rutas.** `php backend/artisan route:list --path=admin/ultron-studio` → **21 rutas** (antes, 19), todas con `LimitRequestBodySize:65536`.
- **Importación de la línea base.** Primero `php backend/artisan ultron-studio:import-baseline --dry-run`, y después sin `--dry-run`. Resultado esperado: **0 nuevas, 8 sincronizadas y 4 sin cambios.** Lo fija `ImportLegacyBaselineCommandTest`, y el tester lo vio en la simulación F → G.
  - R-01, R-02, R-04, R-05, R-07, R-09, R-10 y R-12 pasan a la revisión 2, con sus `conditions` o sus aristas nuevas en `refs.edges`. Quedan 8 eventos `rule.legacy_synced` y 1 `system.baseline_imported`.
  - R-03, R-06, R-08 y R-11 siguen en la revisión 1: no ganan condiciones ni aristas, así que conservan su huella. **No cambian las 12.**
  - Una segunda ejecución dice que no hay nada que importar.
- **Cobertura.** `php backend/artisan auth:coverage --unmapped` sale vacío.
- **Workers.** `supervisorctl status | grep -c RUNNING` da 12, y ninguno está en BACKOFF.

**4. CRM**
- `npm run build:prod` en el commit final de G, con el sello limpio (`version.json` con `dirty: false`).
- Antes de publicar, comprobar que producción sigue en `020db86` (el `version.json` público). Es el guion de publicación de la vista previa, adaptado: antes comprobaba `4334dd8`.
- `rsync -a --no-owner --no-group dist/frontend/browser/ /var/www/app/`, **sin `--delete`**: las pestañas abiertas, también la de caja, siguen con sus chunks anteriores. Después, `chown -R root:root /var/www/app`.
- Verificación pública:
  - `index.html` apunta al `main` nuevo, y `version.json` trae el commit nuevo;
  - el chunk del Studio responde 200 y no contiene el catálogo ni nombres del motor (`UltronCommitService`…);
  - el `main` anterior sigue en 200.

**5. Smoke autenticado, con una cuenta temporal**
- **La cuenta:** `studio-smoke@ironbodyneiva.cloud` (admin id 32, Super Admin, inactiva desde el smoke de F).
- **Qué hace `smoke-run-g.sh`:** reactiva la cuenta con una contraseña aleatoria de un solo uso, ejecuta `smoke-g.py`, desactiva la cuenta, comprueba que su token responde 403 y borra los guiones y la salida. No imprime secretos: la línea del token se filtra.
- **Cómo se ejecuta:** se copian los dos guiones a `/root/` y se lanza `bash /root/smoke-run-g.sh`.
- **Esperado: 27/27 PASS**, y el token de la cuenta desactivada → 403.
- **Qué comprueba:**
  - login;
  - `status`: el motor no lee versiones, publicar está bloqueado, el catálogo es el `2026-10-19.1` y hay 12 reglas importadas;
  - el catálogo: 21 `bindings`, `readings` y `legacyConditions` de R-01, R-02, R-07 y R-12, sin rutas del servidor ni secretos;
  - `engine/readings`:
    - las 5 + 5 claves, con `value` solo si `available`;
    - **el cobro sigue apagado en el motor**;
    - el driver, sin secretos;
    - los planes, solo con nombre e insignia;
  - el borrador guarda y devuelve su `uuid`, y no hay hallazgos `regla-*` (producción no tiene S-NNN);
  - `compare`: línea base → borrador da `same:true`, dos lados iguales dan 422 y una versión inexistente da 404;
  - las 12 R-xx, con `conditions` en R-01, R-02, R-07 y R-12 y `analysis` en todas;
  - dos `POST rules` que tienen que dar 422 (uno contra un control y otro mal formado) sin dejar fila;
  - una R-xx no se edita (409);
  - las versiones se listan sin crear ninguna, y publicar una inexistente da 404;
  - descartar el borrador conserva el `uuid`;
  - la auditoría no lleva IP ni user agent;
  - sin token, 401.
- **Política:** solo se escribe el borrador de la cuenta de smoke; los `POST rules` tienen que responder 422 y no dejar fila; no se crean versiones, que son inmutables.

**6. Verificación final**
- `git -C /var/www/api diff HEAD` sale vacío.
- `MARKETING_ULTRON_ENABLED` sigue igual que en el respaldo. Se compara sin imprimir el `.env`: `diff <(grep '^MARKETING_ULTRON_ENABLED=' backend/.env) <(grep '^MARKETING_ULTRON_ENABLED=' "$BK/env.backup") && echo igual`.
- `php backend/artisan queue:failed` no tiene filas nuevas, y `backend/storage/logs/laravel.log` no tiene errores del Studio.
- **Con sesión real.** Lo decide el propietario. Crear reglas o versiones en producción deja filas que no se pueden borrar (las reglas solo se archivan): por eso el smoke no lo hace.
- **Cierre.** Registrar el despliegue en `ULTRON_STUDIO_MASTER_ROADMAP.md` (fecha UTC, commits y respaldo). `HITO_3_COMPLETED` se declara solo con todo verificado.

**Vuelta atrás:** `ULTRON_STUDIO_ROLLBACK.md`, «Hito G».

## Hito 4 (laboratorio de IA): runbook por fases — PENDIENTE DE EJECUCIÓN

**Estado: PENDIENTE DE EJECUCIÓN.** Ningún paso de esta sección se ha ejecutado en producción. Parte del borrador del lead (`scratchpad/hito-4/deploy-h4.md`), corregido con la Enmienda 4.8 (las variables antes de migrar) y contrastado con el código de `6dbf485`: las claves de configuración, `ironbody-lab.conf`, los comandos y el número de rutas. Las comprobaciones de la configuración efectiva y la sonda de PCRE se probaron en local sobre una instantánea de `6dbf485`; en producción, no.

**Cuándo se ejecuta:** con el ciclo completo en verde (revisión final del `reviewer` sin bloqueantes, puerta PG y regresión del día) y con **la autorización del usuario para cada fase**. Cada fase deja producción en un estado estable y se puede parar ahí.

| Fase | Qué cambia | Coste | Autoriza |
|---|---|---|---|
| 1 | Código, migraciones, worker y escenarios con la bandera **apagada** | 0 | El usuario |
| 2 | Bandera **encendida en `fake`** | 0 | El propietario |
| 3 | **OpenAI**, con la clave dedicada del propietario | El que fije el propietario | El propietario, con sus condiciones confirmadas |

**Qué NO toca ninguna fase:** el motor de ULTRON (`git diff origin/main 6dbf485` sobre sus zonas da 0 bytes), `MARKETING_ULTRON_ENABLED`, n8n, Meta, Twilio, Wompi, las reglas comerciales ni las campañas. Hasta la fase 3, tampoco OpenAI.

**Variables del laboratorio** (referencia completa en A1 del contrato; defectos de `config/ultron_studio.php` y `config/queue.php`):

| Variable | Fase 1 | Fase 2 | Fase 3 |
|---|---|---|---|
| `ULTRON_STUDIO_LAB_ENABLED` | `false` | `true` | `true` |
| `ULTRON_STUDIO_LAB_DRIVER` | `fake` | `fake` | `openai` |
| `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD` | `0` | `0` | El del propietario (propuesto: `2`) |
| `ULTRON_STUDIO_LAB_OPENAI_API_KEY` | **No se escribe** | **No se escribe** | La escribe el propietario en el servidor, nunca por chat |
| `ULTRON_STUDIO_LAB_OPENAI_MODEL`, `…_PRICE_INPUT_USD_PER_MTOK`, `…_PRICE_OUTPUT_USD_PER_MTOK` | — | — | Los del propietario, con la página oficial de precios |
| `QUEUE_STUDIO_LAB` | `studio-lab` | igual | igual |
| `ULTRON_STUDIO_LAB_OPENAI_BASE_URL` | **No debe existir** (en producción se ignora, pero no se escribe) | igual | igual |

El resto (`…_RUN_BUDGET_USD` 0,50, `…_MAX_RUNS_PER_DAY` 20, `…_MAX_CASES_PER_RUN` 12, los máximos de salida 400/900, el timeout de 60 s y `QUEUE_STUDIO_LAB_RETRY_AFTER` 300) queda con su defecto. Sin ninguna de estas líneas, los defectos son los seguros: apagado, `fake` y presupuesto 0.

### Fase 1: código desplegado con la bandera apagada

**Orden obligatorio:** comprobaciones previas → puerta PG → push con avance rápido → respaldo → backend (código, **variables antes de migrar**, migración con el rol dueño y cachés como `www-data`) → verificación SQL y de rutas → worker `ironbody-lab` → importación de escenarios → CRM → smoke → verificación final.
- **El CRM nunca antes que el backend.** El laboratorio del CRM nuevo pide `GET lab/status`, que el backend de G no tiene: la página del laboratorio quedaría en fase de error (inferido, no probado; el tablero no cambia de contrato).
- **La ventana contraria es segura:** el CRM de G tolera las llaves nuevas (`studio-lab-unknown-permission.spec.ts`: login, F5, menú y rutas no cambian) y las 21 rutas de F/G no cambian de contrato (prueba de contrato en el backend). Comprobado con pruebas, no en producción.
- **Entre el `merge` y `route:cache`**, las rutas del laboratorio no existen todavía, porque producción sirve las rutas cacheadas (inferido del orden; no probado). Conviene no usar el Studio durante el despliegue.

**0. Comprobaciones previas (solo lectura)**
- `git fetch` en los dos repos: `origin/main` sigue en `9cceca0` (backend) y en `44c0e8c` (CRM), sin trabajo ajeno encima.
- Producción:
  - `/var/www/api` en `e651dd9`, sin modificaciones;
  - el CRM sirve `main-JNV5BHFD.js`;
  - 12 workers RUNNING y 0 colas fallidas;
  - 21 rutas del Studio;
  - `QUEUE_CONNECTION=database`, sin imprimir el fichero: `grep -cE '^[[:space:]]*QUEUE_CONNECTION=database[[:space:]]*$' /var/www/api/backend/.env` → `1`. El job del laboratorio va a la conexión por defecto, como los demás.
- Base (con `iron` o `postgres`; el lead lo leyó el 19-10-2026 y hay que repetirlo):
  ```sql
  SELECT has_schema_privilege('iron', 'public', 'CREATE');                       -- t  (si sale f: PARAR)
  SELECT defaclobjtype, defaclacl FROM pg_default_acl
   WHERE pg_get_userbyid(defaclrole) = 'iron';                                   -- r: iron_app=arwd/iron · S: iron_app=rwU/iron · f: iron_app=X/iron
  SELECT count(*) FROM pg_class WHERE relname LIKE 'ultron_studio_lab_%';        -- 0
  SELECT count(*) FROM pg_proc  WHERE proname = 'ultron_studio_lab_forbid';      -- 0
  SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, l.mode
    FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
   WHERE c.relname = 'ultron_studio_versions' AND l.mode <> 'AccessShareLock';   -- vacío
  ```
  Si `has_schema_privilege` diera `f`, la migración fallaría limpia con 42501 y el despliegue se para: la operación A del informe del database-specialist (`GRANT CREATE ON SCHEMA public TO iron;` como `postgres`) **requiere autorización del usuario**.
- Regresión del día: la suite completa del backend contra la línea base del mismo día (sin fallos nuevos) y la del CRM contra sus 16 fallos conocidos.
- La revisión final (`reviewer`), sin hallazgos bloqueantes.
- **Puerta obligatoria:** `tests/Feature/UltronStudio tests/Unit/UltronStudio` en un PostgreSQL 14 desechable, en verde (934/934 en `6dbf485`). Solo PostgreSQL ejecuta los CHECK, las pruebas de dos sesiones y las de READ COMMITTED. **Sin esta puerta no se despliega.**

**1. Push, solo con avance rápido** (requiere la autorización del usuario)
- Backend: `git push origin feat/ultron-studio-h4:main`, solo si es avance rápido sobre `origin/main`. Si hace falta, antes un `git merge --ff-only` local. **Nunca `--force`.**
- CRM: lo mismo, con `feat/ultron-studio-h4:main`.

**2. Respaldo en el VPS**

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h4-$TS; mkdir -p "$BK"
cd /var/www/api/backend && git rev-parse HEAD > "$BK/api-commit.txt"
sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"     # con el rol que corresponda, como en G
tar -C /var/www -czf "$BK/app-crm.tgz" app
grep -o 'main-[A-Z0-9]*\.js' /var/www/app/index.html > "$BK/crm-main.txt"
cp /var/www/api/backend/.env "$BK/env.backup" && chmod 600 "$BK/env.backup"
cp -r /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d"
```

Antes de seguir: el tamaño del volcado y `pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'` (como root; al menos 209, como en G). La copia del `.env` lleva secretos: modo 600 y nunca se imprime.

**3. Backend** (desde `/var/www/api`; la aplicación Laravel está en `backend/`)

```bash
cd /var/www/api && git fetch origin && git merge --ff-only origin/main     # HEAD esperado: el commit final del Hito 4
# composer install --no-dev: no hace falta (composer.lock no cambia)
```

**Primero las variables nuevas** (Enmienda 4.8), en `backend/.env`, con el mismo formato que las líneas vecinas y **sin la clave**:

```
ULTRON_STUDIO_LAB_ENABLED=false
ULTRON_STUDIO_LAB_DRIVER=fake
ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0
QUEUE_STUDIO_LAB=studio-lab
```

**Después, la migración y las cachés:**

```bash
sudo /usr/local/bin/ironbody-migrate                       # rol dueño iron, lock_timeout 5 s; regenera config:cache con las variables de arriba
sudo -u www-data php backend/artisan route:cache && chmod 600 backend/bootstrap/cache/routes-v7.php
stat -c '%U %a %n' backend/bootstrap/cache/config.php backend/bootstrap/cache/routes-v7.php   # www-data 600, las dos
sudo -u www-data php backend/artisan queue:restart
```

- **La migración.** Aplica `2026_10_19_100002` y `2026_10_19_100003` con el rol dueño. El borrador del lead la escribe con `env PGOPTIONS="-c lock_timeout=5s"` delante; según el runbook de G, el envoltorio ya aplica esos 5 s. En local tardaron unas decenas de milisegundos.
- **Si sale `55P03`:** una transacción abierta que había escrito en `ultron_studio_versions` tenía la tabla. No queda nada a medias: se reintenta. Si la 100002 entró y la 100003 no, el reintento aplica solo la 100003. **No se sigue hasta ver 27/24** (paso 4).
- **Cachés de `www-data`, con modo 600.** Una caché que no sea de `www-data` deja los workers en BACKOFF en el siguiente reinicio (memoria `config-cache-y-los-workers`). Si `config.php` no sale `www-data 600`: `sudo -u www-data php backend/artisan config:cache && chmod 600 backend/bootstrap/cache/config.php`, antes del `queue:restart`.
- **`queue:restart`** relanza los 12 workers existentes cuando terminan su trabajo en curso, como en G: cargan el código nuevo.

**Comprobación de la configuración efectiva, sin imprimir secretos:**

```bash
sudo -u www-data php backend/artisan tinker --execute='echo json_encode([app()->environment(), config("ultron_studio.lab.enabled"), config("ultron_studio.lab.driver"), config("ultron_studio.lab.daily_budget_usd"), config("ultron_studio.lab.openai.base_url"), (bool) config("ultron_studio.lab.openai.api_key")]), PHP_EOL;'
```

Esperado: `["production",false,"fake","0",null,false]` (con `""` en lugar de `null` si la línea de la URL existiera vacía). En local, con las variables de la fase 1, salió `["production",false,"fake","0","",false]`. El primer valor tiene que ser `production`: de él depende que la URL de OpenAI sea fija. Otra forma, igual de segura: `StudioLabConfig::current()->toArray()`, que no lleva ni la clave ni su huella.

**4. Verificación SQL y de rutas (solo lectura)**

```sql
SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
 WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;                          -- 27 (11 de F/G + 16)
SELECT count(*) FROM pg_constraint
 WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';                -- 24 (11 + 13)
SELECT count(*) FROM pg_class WHERE relkind = 'r' AND relname LIKE 'ultron_studio_lab_%';   -- 5
SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_lab_forbid';   -- iron
SELECT c.relname, has_table_privilege('iron_app', c.oid, 'SELECT') s, has_table_privilege('iron_app', c.oid, 'INSERT') i,
       has_table_privilege('iron_app', c.oid, 'UPDATE') u, has_table_privilege('iron_app', c.oid, 'DELETE') d,
       has_table_privilege('iron_app', c.oid, 'TRUNCATE') t
  FROM pg_class c WHERE c.relkind = 'r' AND c.relname LIKE 'ultron_studio_lab_%' ORDER BY 1;  -- s, i, u, d = t; t = f
```

- `php backend/artisan route:list --path=admin/ultron-studio` → **33 rutas** (antes, 21), todas con `LimitRequestBodySize:65536`; `POST lab/runs` lleva además `throttle:ultron-studio-lab-run`.
- `php backend/artisan auth:coverage --unmapped` → vacío.

**5. Worker del laboratorio**

```bash
supervisorctl status                                   # anotar el tiempo de actividad de los 12 (ya relanzados por queue:restart)
cp /var/www/api/backend/docs/ops/supervisor/ironbody-lab.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update           # solo añade ironbody-lab
supervisorctl status                                   # 13 RUNNING; los 12 conservan el tiempo de actividad anotado
sudo -u www-data php backend/artisan queue:health      # el carril lab, atendido
```

- `ironbody-lab.conf` define `[program:ironbody-lab]`: `queue:work lab --queue=studio-lab --sleep=3 --tries=1 --timeout=150 --max-jobs=100 --max-time=3600`, `numprocs=1`, `stopwaitsecs=180`, `user=www-data` y log en `backend/storage/logs/worker-lab.log`. Con la bandera apagada no tiene trabajo: duerme.
- `reread && update` solo arranca este programa; los otros 12 no se reinician. En los comandos de supervisor, el grupo se nombra `ironbody-lab:*`.
- Si `queue:health` ve el carril sin atender, IRON GUARD abriría `queue_unattended` en cuanto hubiera trabajo; el carril es P5.

**6. Importación de los escenarios del catálogo**

```bash
sudo -u www-data php backend/artisan ultron-studio:lab-import-scenarios --dry-run
sudo -u www-data php backend/artisan ultron-studio:lab-import-scenarios
```

Esperado: «Catálogo del laboratorio 2026-10-19.1: 12 nuevos, 0 sincronizados, 0 sin cambios, 0 retirados.» y un evento `system.lab_catalog_imported`. Funciona con la bandera apagada y es idempotente: una segunda ejecución no crea nada.

**7. CRM**
- `npm run build:prod` en el commit final del Hito 4, con el sello limpio (`version.json` con `dirty: false`).
- Antes de publicar, comprobar que producción sigue en `44c0e8c` (el `version.json` público).
- `rsync -a --no-owner --no-group dist/frontend/browser/ /var/www/app/`, **sin `--delete`**: las pestañas abiertas, también la de caja, siguen con sus chunks anteriores. Después, `chown -R root:root /var/www/app`.
- Verificación pública: `index.html` apunta al `main` nuevo y `version.json` trae el commit nuevo; el chunk del laboratorio responde 200 y no contiene nombres del motor, variables de entorno ni `sk-`; el `main` anterior, `main-JNV5BHFD.js`, sigue en 200.

**8. Smoke autenticado, sin filas positivas** (decisión 5 del lead)
- **La cuenta:** `studio-smoke@ironbodyneiva.cloud` (admin id 32, Super Admin, inactiva desde el smoke de G).
- **Los guiones:** `smoke-h4.py` y `smoke-run-h4.sh` (`scratchpad/hito-4/`). Se copian a `/root/` y se lanza `bash /root/smoke-run-h4.sh`. El guion reactiva la cuenta con una contraseña aleatoria de un solo uso, ejecuta el smoke con `SMOKE_LAB_EXPECT_ENABLED=0`, desactiva la cuenta, comprueba que su token responde 403 y borra los guiones y la salida. No imprime secretos: la línea del token se filtra.
- **Qué comprueba en la fase 1:**
  - login, y que la sesión trae `ultron.lab_manage` y `ultron.lab_run`;
  - `GET status` de F/G: el motor no lee versiones, publicar sigue bloqueado, catálogo `2026-10-19.1` y 12 reglas del código;
  - `GET lab/status` → 200 con `enabled: false` y `can` completo, sin claves, rutas del servidor ni nombres de variables;
  - las otras 11 rutas `lab/*` → 409 `lab_disabled`, con cuerpos que nunca crearían una fila aunque pasaran;
  - sin token → 401;
  - opcional, con una cuenta sin llaves `ultron.*`: 403 en `lab/status` y en `POST lab/runs`.
- **Además:** el smoke de G (`smoke-g.py`) sigue en 29/29.
- **Política:** no se crean escenarios, ejecuciones ni veredictos, que no se pueden borrar. El smoke de G solo escribe el borrador de la cuenta de smoke, como en G.

**9. Verificación final**
- `git -C /var/www/api diff HEAD` sale vacío.
- El entorno es el del respaldo más las 4 líneas nuevas, sin imprimir nada del fichero (bash):
  ```bash
  cmp -s <(grep -vE '^[[:space:]]*(ULTRON_STUDIO_LAB_|QUEUE_STUDIO_LAB)' backend/.env) "$BK/env.backup" && echo "igual salvo las del laboratorio"
  grep -cE '^[[:space:]]*(ULTRON_STUDIO_LAB_|QUEUE_STUDIO_LAB)' backend/.env                 # 4
  grep -cE '^[[:space:]]*ULTRON_STUDIO_LAB_OPENAI_API_KEY=' backend/.env                     # 0
  ```
- `MARKETING_ULTRON_ENABLED` sigue igual: `diff <(grep '^MARKETING_ULTRON_ENABLED=' backend/.env) <(grep '^MARKETING_ULTRON_ENABLED=' "$BK/env.backup") && echo igual`.
- `sudo -u www-data php backend/artisan security:check-secret-exposure` en verde; `queue:health` sin incidentes; `queue:failed` sin filas nuevas; `backend/storage/logs/laravel.log` sin errores del Studio; 13 RUNNING y ninguno en BACKOFF o FATAL.
- **Sonda de PCRE** (PCRE 10.39 de producción; el patrón de `lab-invisibles` no estaba en la sonda del 19-10-2026):
  ```bash
  sudo -u www-data php backend/artisan tinker --execute='$d = App\Services\UltronStudio\StudioLabPersonalData::class; $c = App\Services\UltronStudio\StudioLabChecks::class; echo "PCRE ", PCRE_VERSION, PHP_EOL; foreach (["filtro: invisibles" => $d::INVISIBLE_PATTERN, "filtro: correo" => $d::EMAIL_PATTERN, "filtro: +57" => $d::PHONE_PREFIX_PATTERN, "filtro: dígitos" => $d::DIGITS_PATTERN, "emojis" => $c::EMOJI_PATTERN, "lab-invisibles" => $c::INVISIBLE_PATTERN, "recorte" => "/[".$c::JS_TRIM."]+/u"] as $n => $p) { echo $n, ": ", (@preg_match($p, "prueba 300 123 4567 correo@x.co") === false ? "NO COMPILA" : "ok"), PHP_EOL; }'
  ```
  Esperado: las siete líneas en `ok` (en local, con PCRE 10.47, salieron así). Si alguna dice «NO COMPILA», no se pasa a la fase 2 y se avisa al backend; con la bandera apagada, ninguna ruta usa esas expresiones.
- **Con sesión real:** lo decide el propietario. Con la bandera apagada, el CRM enseña «El laboratorio está apagado en el servidor.».
- **Cierre:** registrar en `ULTRON_STUDIO_MASTER_ROADMAP.md` la fecha UTC, los commits y el respaldo, y el estado «desplegado en la fase 1 (apagado)».

### Fase 2: bandera encendida en `fake` (sin coste)

Con el visto bueno del propietario. Se deshace con otro `config:cache`.

1. En `backend/.env`: `ULTRON_STUDIO_LAB_ENABLED=true`. El driver sigue en `fake` y el presupuesto en 0.
2. `sudo -u www-data php backend/artisan config:cache && chmod 600 backend/bootstrap/cache/config.php`.
3. `supervisorctl restart 'ironbody-lab:*'`: el worker lee la configuración al arrancar.
4. La comprobación del paso 3 de la fase 1 → `["production",true,"fake","0",null,false]`.
5. Smoke con `SMOKE_LAB_EXPECT_ENABLED=1`, también sin filas positivas: `lab/status` con `enabled: true`, `mode: fake`, presupuesto diario 0, 12 escenarios importados y la etiqueta «No es ULTRON»; los 12 escenarios del catálogo, de solo lectura; una estimación en `fake` (coste 0, `allowed`, no crea nada); `GET lab/runs` con el paginador; un `POST lab/scenarios` con un correo → 422 sin fila; y los mismos escenarios al final.
6. Si el propietario quiere ver la fontanería, lanza una ejecución `fake` desde el CRM con un Super Admin: «Ensayo sin IA», coste 0, sin propuesta y sin veredicto posible. **Deja filas que no se pueden borrar** (la ejecución, sus casos y su evento): es decisión suya.

### Fase 3: OpenAI (solo con la clave dedicada del propietario)

**Checklist del propietario, antes de tocar nada:**
- [ ] Un **proyecto de OpenAI dedicado** al laboratorio, con presupuesto propio y una **clave nueva**, distinta de `OPENAI_API_KEY` (IRON IA y el cerebro legado) y de la credencial de n8n.
- [ ] **Retención confirmada:** qué conserva OpenAI con `store: false` en su cuenta (por defecto, los registros de vigilancia de abusos hasta 30 días, según la documentación oficial consultada el 19-10-2026).
- [ ] **Modelo y precios** de entrada y salida por millón de tokens, de la página oficial (los del plan son de terceros y solo orientativos).
- [ ] **Topes:** propuestos US$ 2 al día, US$ 0,50 por ejecución, 20 ejecuciones al día y 12 casos.
- [ ] Que un 429 del límite de gasto del proyecto que llegue sin `insufficient_quota` deja la ejecución reintentando sin gastar hasta el plazo de 30 min (Enmienda 3): aceptado.

**Pasos** (el propietario escribe la clave **él mismo** en el servidor, nunca por chat):
1. En `backend/.env`: `ULTRON_STUDIO_LAB_OPENAI_API_KEY`, `ULTRON_STUDIO_LAB_OPENAI_MODEL`, `ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK`, `ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK`, `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=2` (o su valor) y `ULTRON_STUDIO_LAB_DRIVER=openai`. Los precios, con punto decimal (`0.40`): «0,40» da `missing_price`.
2. `backend/.env` con modo 600 y un dueño que permita a `www-data` leerlo para `config:cache` (el plan del architect indica `chown www-data`; comprobar antes cómo está con `stat -c '%U %a' backend/.env`). Después, `sudo -u www-data php backend/artisan config:cache && chmod 600 backend/bootstrap/cache/config.php`.
3. `sudo -u www-data php backend/artisan security:check-secret-exposure` en verde y la comprobación de la configuración efectiva → `["production",true,"openai",…,null,true]`, sin imprimir la clave.
4. `GET lab/status` con un Super Admin: `mode: openai`, `configured: true` y `reasons: []`. Si sale `shared_key`, la clave es la del motor: parar.
5. `supervisorctl restart 'ironbody-lab:*'`.

**Validación (lo que exige `HITO_4_COMPLETED`):**
1. Una ejecución de **2 escenarios sobre la línea base**; comparar su coste registrado con el panel de uso del proyecto en OpenAI (tienen que coincidir aproximadamente; la estimación sobreestima a propósito).
2. Una ejecución sobre **una versión** y un **veredicto de una segunda persona**, distinta del autor de la versión.
3. Registrar en la hoja de ruta: **`HITO_4_COMPLETED`**. Sin la clave, el hito queda en **`HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**.

**Vuelta atrás:** `ULTRON_STUDIO_ROLLBACK.md`, «Hito 4». La inmediata, en cualquier fase: bandera apagada, `config:cache` como `www-data` y `supervisorctl restart 'ironbody-lab:*'`.

**Lo que IRON GUARD puede hacer solo:** su remedio `retry_failed_job` ejecuta `queue:retry all`, que reencola también los pasos fallidos del laboratorio. No paga dos veces: `failed()` ya liquidó el caso y cada paso reclama con un UPDATE condicionado (I-4 de security).

### Incidencias conocidas del laboratorio

**Una ejecución se queda «En curso», no avanza y no se cancela (H-P2-1).** Abierto en `6dbf485`; la Enmienda 5 del contrato (`403a12b`) decide su corrección, **pendiente de implementar**. Si se despliega una versión que ya la incluya, esta entrada queda obsoleta: cancelar la cerrará en el acto. Reproducido por el tester en local; **no probado en producción**.
- **Síntoma:** una ejecución en `running` que no avanza durante minutos; «Cancelar» responde, pero sigue en curso; no caduca ni se para a los 30 min.
- **Causa:** un paso falló **antes** de reclamar un caso (por ejemplo, se cortó la conexión con la base mientras esperaba el cerrojo, o el worker murió entre un caso y el siguiente paso). `failed()` no encola nada si no hay caso en curso, y el cierre lo hace siempre «el siguiente paso».
- **Impacto:** no gasta dinero ni bloquea otras ejecuciones. Su reserva cuenta en el presupuesto del día hasta que cambia el día de Bogotá.
- **Diagnóstico (solo lectura):**
  ```sql
  SELECT r.uuid, r.status, r.cancel_requested_at, count(*) FILTER (WHERE c.status = 'running') AS en_curso
    FROM ultron_studio_lab_runs r JOIN ultron_studio_lab_run_cases c ON c.run_id = r.id
   WHERE r.status IN ('queued', 'running') GROUP BY r.id;                       -- huérfana: running y en_curso = 0
  SELECT count(*) FROM jobs WHERE queue = 'studio-lab';                         -- 0 si es la única ejecución viva
  ```
  `sudo -u www-data php backend/artisan queue:failed` lista el `App\Services\UltronStudio\StudioLabStepJob` fallido, en la cola `studio-lab`.
- **Mitigación:**
  1. Pedir la cancelación desde el CRM («Cancelar», con `ultron.lab_run`): responde 200 con `cancelRequested: true`. Con la bandera apagada la API no deja cancelar (409), y no hace falta: el paso reencolado la cierra `stopped: lab_disabled`.
  2. Reencolar **solo ese paso**: `sudo -u www-data php backend/artisan queue:retry <id del fallo>`. El worker lo toma y, con la cancelación pedida, cierra la ejecución `cancelled` (los pendientes, `skipped`). En local, el tester lo cerró así con `queue:retry all`; reencolar solo el paso es lo mismo para esa ejecución (inferido, no probado).
- **Qué NO hacer:** `queue:retry all` a mano reencola los trabajos fallidos de **todos** los carriles, también los del motor. Y reencolar el paso **sin** pedir antes la cancelación (o sin apagar la bandera) continúa la ejecución: en modo `openai`, gasta.
- **Verificación:** la ejecución queda `cancelled` y baja lo reservado del día en `GET lab/status`.
- **Escalado:** al backend. La corrección está decidida (Enmienda 5): que cancelar cierre en el acto una ejecución en curso sin caso en curso, que el plazo vencido se presente como `stopped: deadline` y que `failed()` sin caso reclamado reencole un paso acotado.

## Fases siguientes (no incluidas aquí)

Campañas Meta (solo lectura), Twilio Voice y números canarios (tabla auditada), adaptador de ElevenLabs tras bandera. Cada una con su migración revisada en PostgreSQL, su suite y su plan de reversión. (La persistencia, que también figuraba en esta lista, se desplegó con el Hito F el 18-10-2026; el laboratorio y la evaluación con OpenAI tienen su runbook en «Hito 4», más arriba.)
