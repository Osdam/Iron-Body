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

## Hito 4 (laboratorio de IA): runbook por fases — EJECUTADO en fases 1 y 2 el 25-10-2026 (ver `ULTRON_STUDIO_FINAL_REPORT.md`, «Despliegue y verificación» del Hito 4; scripts en `ops/`)

**Estado (actualizado el 31-10-2026): fases 1 y 2 EJECUTADAS el 25-10-2026, 01:15–01:25 UTC; fase 3 (OpenAI) NO ejecutada**, a la espera de la clave y del proyecto del propietario. Evidencia en `ULTRON_STUDIO_FINAL_REPORT.md` («Hito 4: despliegue y verificación»). *(Antes del despliegue decía: «PENDIENTE DE EJECUCIÓN. Ningún paso de esta sección se ha ejecutado en producción».)* Parte del borrador del lead (`scratchpad/hito-4/deploy-h4.md`), corregido con la Enmienda 4.8 (las variables antes de migrar) y contrastado con el código de `6dbf485`: las claves de configuración, `ironbody-lab.conf`, los comandos y el número de rutas. Las comprobaciones de la configuración efectiva y la sonda de PCRE se probaron en local sobre una instantánea de `6dbf485`; en producción, no.

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
5. Smoke con `SMOKE_LAB_EXPECT_ENABLED=1`, también sin filas positivas: `lab/status` con `enabled: true`, `mode: fake`, presupuesto diario 0, 11 escenarios importados y la etiqueta «No es ULTRON»; los 12 escenarios del catálogo, de solo lectura; una estimación en `fake` (coste 0, `allowed`, no crea nada); `GET lab/runs` con el paginador; un `POST lab/scenarios` con un correo → 422 sin fila; y los mismos escenarios al final.
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

## Hito 5 (gobierno y versionado): runbook por fases — PENDIENTE DE EJECUCIÓN

**Estado (31-10-2026): PENDIENTE DE EJECUCIÓN.** Ningún paso de esta sección se ha ejecutado en producción, y el Hito 5 no está en `origin/main` de ninguno de los dos repos. Sale del runbook de DevOps (`plans/informes-h5/informe-devops-h5.md`, §4–§9), del informe de recuperación del lead (`plans/informes-h5/informe-recuperacion-2026-10-31.md`) y de los scripts de `ops/`. Donde no coinciden, mandan los scripts. El lead los reconstruyó sobre las propuestas A y B de DevOps (`5772bdf`) y el 31-10-2026 les aplicó las observaciones del reviewer (`db1d8f6` y `940b0c2`): `git` en el servidor va **como root con `umask 022`**, porque el árbol y `.git` son de root desde el 25-10 (I-3, comprobado en el servidor el 31-10-2026) y tras el `merge` se comprueba que `www-data` lee cada fichero cambiado; un tercer argumento que no sea `--solo-comprobar` no despliega nada (I-2); los commits se comparan con `--short=7` (m-6); los privilegios de `iron_app` se comprueban uno a uno, y UPDATE y DELETE se enseñan como información (m-5); `deploy-crm.sh` falla en voz alta (m-4); y `h5-engine-scope.sh` solo dice «APLICADO» en verde (m-3). **Lo que se copia al servidor es la versión confirmada de los scripts.** DevOps ensayó sus propuestas en un banco local sobre `fef7c87` (§3.3 y §3.4); los cambios posteriores y `PREVIO=7318122` no se han ensayado, y en el servidor no se ha ejecutado nada. Vuelta atrás: `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5». Scripts: `ops/README.md`.

**Producción de partida** (lectura del lead por SSH, solo lectura, 31-10-2026 a las 21:01 UTC; informe de recuperación §3): API en `7318122` y CRM en `4105016` (build del 29-10). El Studio está como lo dejó el Hito 4: 33 rutas, 27 disparadores y 24 CHECK, 10 tablas `ultron_studio_*`, 13 workers RUNNING (con `ironbody-lab`) y el laboratorio encendido en `fake`, con presupuesto 0 y sin clave. Ninguna de las 7 variables del Hito 5 está en el fichero de entorno. En la base hay 1 borrador, 0 versiones y 0 ejecuciones del laboratorio. ULTRON está encendido, con el canario configurado y el cobro apagado.

**Qué es publicar en este hito (opción A del contrato).** La versión aprobada pasa a ser la declaración oficial y firmada de la configuración de ULTRON, y el motor la anota en cada turno (`marketing_ai_actions.metadata.studio`). **No cambia lo que ULTRON dice ni hace.**

| Fase | Qué cambia | Coste | Autoriza |
|---|---|---|---|
| 1 | Código (38 rutas), 2 migraciones aditivas, las 7 variables apagadas y el CRM. El motor no consulta la cadena y publicar responde 409 | 0 | El usuario |
| 2 | Motor en `canary`: sella los turnos de la conversación canaria. Publicar queda abierto (siempre en canario) | 0 | El propietario |
| 3 | Motor en `all` y promoción de la versión en canario | 0 | El propietario |

**Qué NO toca ninguna fase:** lo que ULTRON dice o hace, `MARKETING_ULTRON_ENABLED`, la pausa, el freno, el canario del motor, el cobro, n8n, Meta, Twilio, Wompi, la clave de OpenAI ni las campañas. `h5-engine-scope.sh` solo escribe sus dos llaves (y `ULTRON_STUDIO_PUBLISH_LAB_GATE=required` si la línea faltara).

**Variables del Hito 5** (contrato A13 y Enmienda 2 §E2.2; defectos en `config/ultron_studio.php`, `config/marketing.php` y `config/queue.php`):

| Variable | Fase 1 | Fase 2 | Fase 3 | Si falta, está vacía o no es válida |
|---|---|---|---|---|
| `MARKETING_ULTRON_STUDIO_CONFIG` | `off` | `canary` | `all` | `off`: solo `canary` y `all` (sin distinguir mayúsculas ni espacios alrededor) encienden la lectura |
| `ULTRON_STUDIO_PUBLISHING_ENABLED` | `false` | `true` | `true` | Apagada. Solo la encienden `true`, `1`, `on` o `yes` (M-1/AS-1, corregido en `d24c512`). `h5-engine-scope.sh` la abre con `canary` y con `all` (N-9) |
| `ULTRON_STUDIO_PUBLISH_LAB_GATE` | `required` | `required`, o `waived` por decisión escrita del propietario (ver «Fase 2») | La que quede en la fase 2 | `required`: solo `waived` dispensa |
| `ULTRON_STUDIO_CANARY_MIN_HOURS` | `24` | `24` | `24` | 24; un entero se acota a 1–168 |
| `ULTRON_STUDIO_CANARY_MIN_TURNS` | `3` | `3` | `3` | 3; un entero se acota a 1–100 |
| `ULTRON_STUDIO_PUBLISH_WINDOW` | `07:00-19:00` | igual | igual | Hora de Bogotá, `[inicio, fin)`. Con la línea vacía (`ULTRON_STUDIO_PUBLISH_WINDOW=`), sin ventana. Mal escrita, con el inicio después del fin o cruzando la medianoche: `07:00-19:00` |
| `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS` | `300` | igual | igual | 300; un entero se acota a 60–3600 (umbral de desatención del carril `lab`, m-3 del Hito 4) |

Las siete tienen su defecto en el código. La fase 1 las escribe explícitas para que la configuración real quede a la vista (`decisiones-lead-h5.md`, paso 3).

**Orden y ventanas de compatibilidad** (informe de DevOps §1 y E2E del tester):
- **Backend → CRM**, por convención. Cada uno se revierte por separado.
- **CRM del Hito 5 con el backend de hoy:** pasa al modo de F/G. Comprobado con la pila real: 131/131, lo mismo que la línea base (ejecución R3 del E2E, `0366009` + `e7ba41b`).
- **CRM de hoy con el backend del Hito 5:** legible según el código (lee claves concretas e ignora las nuevas, y los 10 estados ya estaban); solo cambia un texto («Motor: lee versiones»). No probado con E2E. El módulo del Studio de `4105016` es el mismo que el de `5c1dbd7` (diff vacío en `src/app/modules/ultron-studio`, comprobado el 31-10-2026).
- **Socios, caja y ULTRON no notan nada.** Con la llave del motor en `off`, `UltronPublishedConfig::forConversation()` devuelve `null` sin consultar la base. Entre el `merge` y la caché nueva, la caché vieja no tiene `marketing.ultron.studio_config`, y `null` se lee como `off` (DevOps §1, leído en el código).
- **No usar el Studio durante el despliegue.** El código entra antes que la migración (orden heredado de H4): durante aproximadamente un minuto, el detalle de una versión puede responder 500. `GET status` lo tolera.

### Fase 1: código desplegado con todo apagado

**Qué cambia:** el código, con 5 rutas nuevas (38 en el Studio); las migraciones `2026_10_25_100001_create_ultron_studio_publications_table` y `2026_10_25_100002_add_ultron_studio_publication_guards` (la cadena, sus guardas, `usv_status_chk` con 9 estados y los índices únicos parciales `usv_one_canary` y `usv_one_published`); las 7 variables; las cachés y `queue:restart`; los estáticos del CRM. No reinicia FPM ni toca la configuración de supervisor, DNS, TLS o el firewall.

**Lo que se queda:** las dos migraciones (**nunca `down()` en producción**). En la fase 1 nada escribe en la cadena. El smoke cambia la fila de la cuenta 32 (hash, `last_login_at` y su reposo), crea 2 sesiones que borra al terminar y deja líneas en el log.

**Condiciones previas:**
- [ ] **Revisión final (`reviewer`) sin bloqueantes.** Veredicto del 31-10-2026 (`plans/informes-h5/informe-reviewer.md`, confirmado en `db1d8f6`): **APROBADO CON OBSERVACIONES**, sin bloqueantes en el código. Antes de la fase 1 pedía cerrar I-2 e I-3 (los scripts, cerradas en `db1d8f6`) e I-4 (la vuelta atrás del código, corregida en `ULTRON_STUDIO_ROLLBACK.md` el 31-10). [PENDIENTE: segunda pasada del reviewer sobre la documentación del Hito 5, incluida esta sección.]
- [ ] **Puerta PostgreSQL en verde sobre el commit que se despliega**, posterior a la fusión de `origin/main` del 31-10-2026: `docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio`. Lo último registrado es anterior a esa fusión: 1441 pruebas en PG sobre `61048ff` más las carreras, con 9 rojas que eran H-1 (informe del tester de backend §5); `96397ea` corrige H-1 y añade tres pruebas de cobertura, y las 38 carreras pasan contra el arreglo (`decisiones-lead-h5.md`). Con las carreras, la puerta tarda de 3 a 8 minutos; si en una máquina lenta la carrera no llega a ocurrir, la prueba falla en voz alta, nunca en verde. Tras la fusión, el reviewer pasó en `cf59cef` solo un subconjunto: 103/103 en SQLite (`UltronStudioPublicationLifecycleTest`, `UltronStudioPublicationRollbackTest` y `UltronEngineIsolationTest`) y 9/9 carreras en PostgreSQL. **Sin esta puerta no se despliega.** **Hecho:** Puertas completas del lead (31-10-2026, tras la fusión): Studio + Access + Chaos en SQLite **1697 OK** (56 omitidas, de solo PostgreSQL) sobre `cdb9dc8`; puerta PostgreSQL **1446 OK** sobre `cdb9dc8` y **1447 OK** sobre `cf59cef` (con las 38 carreras); Studio SQLite **1447 OK** sobre `cf59cef`; Marketing (Feature + Unit) **4977 OK**; `auth:coverage --unmapped` vacío; **38 rutas**. **E2E de cierre ejecutado por el lead el 31-10-2026 (21:26–21:30 UTC) sobre `cf59cef` + `9fe1817`: 408/408** (f-versions 40, g-inspector-compare 25, h4-lab-fake 27, ultron-turn 30, h5-governance 276, cierre 10; 257 s; sin 5xx en 465 peticiones del CRM, `laravel.log` sin ERROR, colas vacías, 0 violaciones de los simuladores de OpenAI y n8n, valla de red vacía, ninguno de los 4 secretos en 19 registros ni en 9 tablas). Es la mejor pasada registrada del arnés. [PENDIENTE: build de producción del CRM sobre el commit final con I-1 (lead).]
- [ ] **Comparación de suites del mismo día** contra `origin/main`, sin rojos nuevos (`docs/ultron-studio/ops/suite-compare.sh`).
- [ ] **`PREVIO=7318122`** en `deploy-h5-backend-fase1.sh` (lo que sirve producción; antes, `fef7c87`), confirmado en `cf59cef`, y las correcciones de los scripts del 31-10-2026 (I-2, I-3, m-3 a m-6), confirmadas en `db1d8f6` y `940b0c2`: el script se copia desde el commit que se despliega y se comparan las huellas. Si producción se mueve antes de la fase 1, el paso 0 aborta; entonces se revisa qué entró y se actualiza `PREVIO` en otro commit (DevOps §12).
- [ ] **Push con avance rápido de los dos repos** (autorización del usuario): `git push origin feat/ultron-studio-h5:main` en cada uno, solo si es avance rápido sobre `origin/main` (el 31-10-2026: backend `7318122`, CRM `4105016`). **Nunca `--force`.**
- [ ] **Autorización expresa del usuario para la fase 1**, en la conversación de ese momento.
- [ ] Unos 15 minutos sin nadie en el Studio. No hace falta la ventana 07:00–19:00: no se publica nada.
- [ ] Anotados a mano:
  - `<commit-backend>`: `git rev-parse --short=7 origin/main` del backend tras el push (siete cifras exactas: el script compara con `--short=7`, m-6);
  - `<build-crm>`: el commit de `origin/main` del CRM;
  - `<crm-prod>`: el `commit` de `https://ironbodyneiva.cloud/version.json` (esperado `4105016`; comprobarlo).

**1. Copiar los scripts** (desde el Mac, con el árbol del backend en `<commit-backend>` y `git status` limpio). Va antes que las comprobaciones porque estas usan `h5-schema-check.sql`. Se copian desde el Mac, y no con `git show` en el servidor, para no ejecutar git como root más de lo imprescindible (M-3).

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body/backend
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h5-backend-fase1.sh $O/h5-engine-scope.sh $O/smoke-h5.py $O/smoke-run-h5.sh $O/h5-schema-check.sql
scp $O/deploy-h5-backend-fase1.sh $O/h5-engine-scope.sh $O/smoke-h5.py $O/smoke-run-h5.sh $O/h5-schema-check.sql ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/deploy-h5-backend-fase1.sh /root/h5-engine-scope.sh /root/smoke-run-h5.sh && chmod 600 /root/smoke-h5.py /root/h5-schema-check.sql && sha256sum /root/deploy-h5-backend-fase1.sh /root/h5-engine-scope.sh /root/smoke-h5.py /root/smoke-run-h5.sh /root/h5-schema-check.sql'
```

Las huellas del Mac y del servidor tienen que coincidir. **El smoke borra sus dos ficheros de `/root/` al terminar:** antes de cada ejecución del smoke (también en las fases 2 y 3) se vuelven a copiar, con sus huellas.

**2. Comprobaciones previas en el servidor (solo lectura)**

```bash
ssh ironbody-vps
cd /var/www/api
git rev-parse --short=7 HEAD                                                          # 7318122
git status --porcelain --untracked-files=no | wc -l                                   # 0 (los cinco ficheros vacíos sin seguimiento de backend/ no cuentan)
stat -c '%U:%G %a %n' /var/www/api/.git /var/www/api/.git/config /var/www/api/.git/hooks   # anotar el dueño (I-3: root desde el 25-10)
git config --global --get-all safe.directory                                          # anotar (configuración de root, no del repo)
supervisorctl status | grep -c RUNNING                                                # 13
systemctl is-active ironbody-billing-worker                                           # active
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"      # anotar
sudo -u www-data php backend/artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 33
tail -c1 backend/.env | od -An -c                                                     # \n (si no, el script añade el salto antes de las claves)
for k in MARKETING_ULTRON_STUDIO_CONFIG ULTRON_STUDIO_PUBLISHING_ENABLED ULTRON_STUDIO_PUBLISH_LAB_GATE ULTRON_STUDIO_CANARY_MIN_HOURS ULTRON_STUDIO_CANARY_MIN_TURNS ULTRON_STUDIO_PUBLISH_WINDOW QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS; do printf '%s %s\n' "$k" "$(grep -Ec "^[[:space:]]*(export[[:space:]]+)?$k[[:space:]]*=" backend/.env)"; done   # las 7 en 0
for p in umask 'PGOPTIONS|lock_timeout' 'config:cache' 'chmod' 'sudo -u|runuser|su -'; do printf '%-26s %s\n' "$p" "$(grep -cE "$p" /usr/local/bin/ironbody-migrate)"; done   # N-7/N-10: solo recuentos; el fichero puede llevar credenciales y no se imprime
grep -c 'production.ERROR' backend/storage/logs/laravel.log                          # anotar: línea base de la verificación posterior (si el log es diario, el fichero del día)
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h5-schema-check.sql
```

- **`git` sobre `/var/www/api` va como root, con `umask 022`, igual que en los scripts.** Según la comprobación del 31-10-2026 que cita el script (I-3 del reviewer), el árbol del código y casi todo `.git` son de root desde el 25-10 y `www-data` no puede escribirlos: un `merge` como `www-data` fallaría a medias. Código y `.git` de root, legibles por `www-data`, es la postura más segura, y la M-3 original (hooks o configuración de un `.git` de `www-data` ejecutados por root) ya no se da: sin hooks ni `core.hooksPath`, y con `safe.directory` para esa ruta. *(El 25-10 se leyó lo contrario, `.git` de `www-data`, y los scripts pasaron a `sudo -u www-data -H git`; el informe de recuperación del 31-10 aún dice que solo `.git/index` es de root. Si el `stat` de arriba no dice root, parar y aclararlo con el lead.)*
- **Cinco ficheros vacíos sin seguimiento** en `/var/www/api/backend/` (`-`, `0,`, `1,`, `2,` y `21000.00`, de root, del 8-10-2026: restos de una redirección de shell ajena) no afectan al script, que ignora lo no seguido (informe de recuperación §3). No se borran sin orden.
- **Lo esperado en `h5-schema-check.sql` antes de migrar:** `disparadores_studio` 27, `check_studio` 24, `disparadores_uspub` 0, `check_uspub` 0, `indices_parciales` 0, `check_9_estados` f; `dueno_funcion` y `dueno_versiones` `iron`, `create_en_public` t, `existe_cadena` f; 0 versiones fuera de los 5 estados de F y **0 filas de cerrojos**.
- **Se para si:** la función o `ultron_studio_versions` no son de `iron` (la 100002 fallaría con 42501: no forzar), `create_en_public` sale f, hay cerrojos o alguna de las 7 claves ya existe.
- **No verificado en el servidor:** `psql -f` abre el fichero con el usuario `postgres`. Si `/root` no es legible para `postgres` (es lo habitual, 700), sale `Permission denied`. En ese caso, el mismo fichero por la entrada estándar, que abre root: `sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h5-schema-check.sql`. DevOps validó la consulta solo en local.

**3. Respaldo** (en el servidor, como root, menos de 2 horas antes del paso 5: el script lo exige)

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h5-$TS
( umask 077
  mkdir -p "$BK"
  git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                       # empieza por 7318122
  sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"                       # lo escribe root: 600 por la umask
  sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
  tar -C /var/www -czf "$BK/app-crm.tgz" app
  grep -o 'main-[A-Z0-9]*\.js' /var/www/app/index.html > "$BK/crm-main.txt"
  install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
  cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
  cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                          # al menos 211 (el respaldo del Hito 4 tenía 211)
stat -c '%a %n' "$BK" "$BK"/*                                                     # 700 el directorio; 600 el volcado y env.backup
du -h "$BK/ironbody.dump"; echo "BK=$BK"
```

- **`umask 077` va dentro de una subshell:** el volcado y la copia del entorno nacen en 600 dentro de un directorio 700, y la sesión de root no se queda con esa umask: un `git` posterior en esa misma sesión (por ejemplo, una vuelta atrás a mano) dejaría en 600 lo que reescribiera, ilegible para `www-data`. El script de la fase 1 fija su propia `umask 022` por el mismo motivo. El §4.2 del informe de DevOps la fija en la sesión entera. Sin umask, el volcado (escrito por la redirección de root) nacería 644 en un directorio 755: así se hicieron los respaldos de G y del Hito 4, cuyos directorios siguen en 755 (comprobado por el lead el 31-10-2026; ver el paso 9).
- **Lo que comprueba el script de este respaldo:** que `api-commit.txt` empiece por `7318122`, que el volcado tenga menos de 2 horas, que el volcado y `env.backup` sean 600 y que `pg_restore -l` liste al menos 211 tablas con datos.
- **El respaldo automático diario** (`ironbody-db-backup.timer`, 03:15 de Bogotá, `/root/db-backups/auto`) no sustituye a este: puede tener hasta 24 horas.
- `env.backup` lleva secretos: modo 600 y nunca se imprime.

**4. Ensayo en el servidor** (solo lectura, salvo el `git fetch`)

```bash
bash /root/deploy-h5-backend-fase1.sh <commit-backend> "$BK" --solo-comprobar
```

Esperado: `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`. Con cualquier `ABORTA`, se para y se aclara la causa.

**5. Backend** (con la autorización de la fase 1)

```bash
bash /root/deploy-h5-backend-fase1.sh <commit-backend> "$BK" 2>&1 | tee /root/h5-fase1-$(date -u +%Y%m%d-%H%M%S).log
```

Lo esperado, según las líneas que imprime el script (no se ha ejecutado en el servidor; `…` sustituye detalle que no cambia el resultado y `<…>`, valores del momento):

```
0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):
   respaldo /var/backups/ironbody/ultron-studio-h5-<TS>: <≥ 211> tablas con datos
   HEAD 7318122 limpio · esquema 27/24 de iron · sin cerrojos · claves del Hito 5 ausentes o iguales · colas fallidas: <N>
1. código: HEAD=<commit-backend>
   todos los ficheros del merge son legibles por www-data
2. variables (todo apagado):
   MARKETING_ULTRON_STUDIO_CONFIG añadida
   … (las otras seis, igual)
   permisos del fichero: <dueño> <modo>
   el fichero de entorno parsea
3. migración (rol dueño, lock_timeout 5 s):
   … 2026_10_25_100001_create_ultron_studio_publications_table … DONE
   … 2026_10_25_100002_add_ultron_studio_publication_guards … DONE
   cachés: www-data 600 · www-data 600
4. SQL:
   disparadores · CHECK · uspub disp. · uspub CHECK · parciales · 9 estados · dueño función · dueño cadena · filas: 30 27 3 3 2 true iron iron 0
   iron_app sobre la cadena (SELECT · INSERT · TRUNCATE · secuencia): true true false true
   iron_app UPDATE · DELETE sobre la cadena (informativo; los paran los disparadores): true true
5. rutas studio: 38 · auth:coverage --unmapped → []
6. configuración efectiva:
   {"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300}
   Hito 4 y canario (informativo): {"lab_enabled":true,"lab_driver":"fake","canary_set":true}
7. workers RUNNING: 13 · BACKOFF/FATAL: 0
8. sanidad:
   … sin token → 401 (cuatro líneas: status, lab/status, publications y una vista previa)
   colas fallidas: <N> (antes <N>)
   filas de marketing_ai_actions con sello 'studio' (0 con el motor en off): 0
   laravel.log ERROR desde el inicio del despliegue: 0
   security:check-secret-exposure: OK
   ultron-studio:publication-check: 0 (cuadra)
FASE 1 (H5) COMPLETADA
```

- **Lo que hace el script, en orden:** comprobaciones de solo lectura (abortan sin tocar nada) → `merge --ff-only` como root con `umask 022` → comprobación de que `www-data` lee cada fichero que cambió → las 7 variables (con el salto final asegurado) → comprobación de que el fichero de entorno parsea → `ironbody-migrate` con `PGOPTIONS="-c lock_timeout=5s"` → `route:cache` con `umask 077` → cachés de `www-data` en 600 → `queue:restart` → aserciones. `FASE 1 (H5) COMPLETADA` solo sale con todo en verde; si no, `FASE 1 (H5) CON N DESVÍOS` y código 1. Nunca imprime valores del fichero de entorno.
- **Un tercer argumento que no sea exactamente `--solo-comprobar`** (por ejemplo, `--solo-comprobr`) sale con 2 sin tocar nada (I-2 del reviewer).
- **Con `ABORTA` en el paso 0:** no se ha tocado nada.
- **Con `ABORTA` porque `www-data` no puede leer algún fichero del `merge`:** el código nuevo ya está en el disco, sin variables ni migraciones. Corregir los permisos como indica el mensaje (644 los ficheros y 755 los directorios de `git diff --name-only 7318122 HEAD`) y relanzar el mismo comando, que reanuda.
- **Con `ABORTA` o un desvío después:** ver «Incidencias previsibles», abajo. Si la 100002 cayó por `55P03`, se relanza el mismo comando: el script reanuda (HEAD ya en `<commit-backend>`, sin `merge`, sin duplicar claves).
- **La reanudación tiene que llegar antes de que el volcado cumpla 2 horas.** Pasado ese plazo, el script aborta por el respaldo, y un respaldo nuevo, tomado con el código ya fusionado, guardaría en `api-commit.txt` el commit esperado y no `7318122`, así que también abortaría (nota del reviewer). En ese caso, se decide con el lead cómo rehacer el respaldo antes de seguir.
- **No se sigue con el CRM** si el script no termina en `COMPLETADA`.

**6. Verificación del backend (solo lectura)**

```bash
cd /var/www/api/backend
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h5-schema-check.sql
P='^[[:space:]]*(MARKETING_ULTRON_STUDIO_CONFIG|ULTRON_STUDIO_PUBLISH|ULTRON_STUDIO_CANARY_MIN_|QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS)'
cmp -s <(grep -vE "$P" .env | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d') && echo "igual al respaldo salvo las 7 del Hito 5"
grep -cE "$P" .env                                                            # 7
cmp -s <(grep -E '^[[:space:]]*MARKETING_ULTRON_ENABLED=' .env) <(grep -E '^[[:space:]]*MARKETING_ULTRON_ENABLED=' "$BK/env.backup") && echo "MARKETING_ULTRON_ENABLED igual"
git -C /var/www/api status --porcelain --untracked-files=no | wc -l           # 0
```

Lo esperado en `h5-schema-check.sql` después de migrar: `disparadores_studio` 30, `check_studio` 27, `disparadores_uspub` 3, `check_uspub` 3, `indices_parciales` 2, `check_9_estados` t; la misma `huella_funcion` que antes y `existe_cadena` t; `app_select` t, `app_insert` t, `app_truncate` f, `app_secuencia` t, `dueno_cadena` `iron` y `publicaciones` 0; `app_update` y `app_delete` salen t y son informativos: los da el privilegio por defecto del esquema, y lo que hace la cadena de solo inserción son los disparadores `uspub_*` (revocarlos es un refuerzo candidato del Hito 7). `SELECT` e `INSERT` se preguntan por separado porque, con una lista, `has_table_privilege` responde t si se tiene cualquiera de ellos (m-5 del reviewer). `cmp -s` no imprime nada del fichero: solo dice si son iguales.

**7. CRM** (desde el Mac)

```bash
curl -fsS https://ironbodyneiva.cloud/version.json                          # <crm-prod> (esperado 4105016)
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body_Front   # en <build-crm>, limpio
npm run build:prod                                                          # version.json: commit <build-crm>, dirty false
/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body/backend/docs/ultron-studio/ops/deploy-crm.sh <build-crm> <crm-prod>
```

`deploy-crm.sh` exige el build sellado y limpio, producción en `<crm-prod>` y `origin/main` del CRM igual al build; respalda `index.html` y `version.json`, publica con `rsync` **sin `--delete`** (las pestañas abiertas, también la de caja, siguen con sus chunks) y verifica por la URL pública: `version.json` con `<build-crm>`, `index.html → main-….js OK`, los dos `main` en 200 y `/marketing/ultron-studio` en 200. Esperado: **`CRM PUBLICADO Y VERIFICADO`**. Con cualquier fallo de verificación, `CRM PUBLICADO CON N FALLOS DE VERIFICACIÓN` y código 1 (m-4 del reviewer: antes solo imprimía): el CRM ya está publicado, así que se revisa antes de seguir y, si hace falta, se vuelve al respaldo. **Anotar** la línea `RESPALDO: /var/backups/ironbody/crm-pre-<build-crm>-<ts>`.

**8. Smoke** (escribe en la fila 32 de `admins` y en `admin_sessions`: **autorización del usuario**)

```bash
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h5.sh'
```

Esperado:
- `OK      configuración efectiva de la fase 1: motor=off publicar=false`;
- la parte del Super Admin con 0 FALLO y 0 OMITIDO: 35 OK, o 36 si hubiera una versión APPROVED (el 31-10-2026 no hay ninguna; sale una línea INFO);
- la parte del Administrador (la misma cuenta con el rol cambiado), 14 OK;
- los tres OK de «Base»: ninguna fila de `ultron_studio_*` nombra a la cuenta, el recuento exacto de las tablas `ultron_studio_*` es igual antes y después, y 0 sellos `studio`;
- la cuenta en reposo (inactiva, Super Admin, sin sesiones) y los dos tokens en 401 o 403;
- `SMOKE H5 fase 1: código de salida 0`.

Códigos: 0 todo OK · 1 algún FALLO · 3 algo OMITIDO · 2 uso incorrecto. Como mucho 3 ejecuciones por minuto (limitador del login). Qué hacer con cada FALLO: informe de DevOps §2.6. **`smoke-h4.py` ya no sirve con el backend del Hito 5:** su comprobación `readsVersions is False` es justo lo que H5 cambia. Vuelve a valer solo si el código vuelve al del Hito 4.

**9. Cierre de la fase 1**
- `sudo -u www-data php artisan queue:health`: sin carriles desatendidos; el carril `lab`, con su umbral de 300 s.
- Con sesión real del propietario (opcional): `/marketing/ultron-studio` abre y la barra de la publicación dice Legacy, la llave del motor apagada, ningún sello y que publicar está desactivado (con el mensaje del servidor). En una versión aprobada no habría botón de publicar, sino el motivo en texto (D-F1); el 31-10-2026 no hay ninguna versión.
- **Endurecer los respaldos de G y del Hito 4** (autorización del usuario; informe de recuperación §3 y DevOps §13): sus directorios están en 755 (comprobado por el lead el 31-10-2026; los demás respaldos, en 700). **Nunca se borra un respaldo.**

  ```bash
  stat -c '%a %n' /var/backups/ironbody/ultron-studio-g-*/ /var/backups/ironbody/ultron-studio-h4-*/ /var/backups/ironbody/ultron-studio-*/ironbody.dump
  chmod 700 /var/backups/ironbody/ultron-studio-g-*/ /var/backups/ironbody/ultron-studio-h4-*/
  chmod 600 /var/backups/ironbody/ultron-studio-g-*/ironbody.dump /var/backups/ironbody/ultron-studio-h4-*/ironbody.dump
  ```
- Registrar en `ULTRON_STUDIO_MASTER_ROADMAP.md`, `ULTRON_STUDIO_FINAL_REPORT.md` y `ULTRON_STUDIO_ROLLBACK.md`: la fecha UTC, los commits, `$BK` y el respaldo del CRM, la salida del script y la del smoke, y el estado «Hito 5 desplegado en la fase 1 (todo apagado)».

### Después de la fase 1: a los 15 minutos y a las 24 horas (solo lectura)

```bash
cd /var/www/api/backend
supervisorctl status                                                           # 13 RUNNING; tiempo de actividad ≈ desde el queue:restart; ningún BACKOFF/FATAL
systemctl is-active ironbody-billing-worker                                    # active
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"                             # igual que en el paso 2
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"   # 0 (motor en off)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE created_at > now() - interval '24 hours';"   # informativo: ULTRON sigue anotando turnos si hubo tráfico
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM ultron_aborts WHERE released_at IS NULL;"   # el freno: igual que antes (0 = suelto)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM ultron_studio_publications;"               # 0
sudo -u www-data php artisan ultron-studio:publication-check                   # código 0 («cadena vacía»)
sudo -u www-data php artisan queue:health                                      # sin incidentes
sudo -u www-data php artisan security:check-secret-exposure                    # en verde
grep -c 'production.ERROR' storage/logs/laravel.log                            # compararlo con el valor anotado en el paso 2 (o con el informe del script)
grep -cE 'ultron_studio\.publication_state_unavailable|auth:admin:unmapped_route|canary_acta_unavailable' storage/logs/laravel.log   # 0
grep ' /api/admin/ultron-studio/' /var/log/nginx/access.log | awk '{print $9}' | sort | uniq -c    # si el formato es el combined: ningún 5xx
tail -n 200 /var/log/nginx/error.log | grep -ci upstream                       # sin errores nuevos de upstream
```

- **Si el log es diario** (`laravel-AAAA-MM-DD.log`), el fichero del día.
- **Qué indica cada señal:** `publication_state_unavailable`, que `status` sale sin `publication` (tabla, privilegios de `iron_app` o una 100002 a medias); `unmapped_route`, una ruta sin clasificar; un 5xx del Studio, la caché de rutas, la tabla o los privilegios.
- **A las 24 horas:** lo mismo y, si se quiere, otra pasada de `SMOKE_PHASE=1` (copiando antes los dos ficheros). Es la base para decidir la fase 2.

### Fase 2: el motor sella en la conversación canaria y se puede publicar

**Antes:**
- [ ] Fase 1 estable 24 horas: 0 ERROR del Studio o del motor, 0 sellos, `publication-check` 0, workers estables, colas fallidas sin crecer y el CRM probado con sesión real.
- [ ] M-1/AS-1 cerrado: corregido en `d24c512`, con prueba negativa en `UltronStudioPublicationConfigTest` (`off`, `no`, `disabled` o ` false` ya no abren publicar). El E2E con la pila real lo detectó sobre `2bc053b` (su B-1) y no se ha repetido sobre el código corregido.
- [ ] **I-1 del reviewer corregido en el CRM** (importante, antes de la fase 2): en la misma pestaña, un segundo «Volver a Legacy» con el mismo motivo reenvía la misma `clientRequestId`, el servidor responde 200 `replayed` con la entrada vieja y la salida no se ejecuta, aunque entretanto otra persona haya publicado. No afecta a la fase 1. Si se corrige antes de la fase 1, el CRM se despliega una sola vez. [PENDIENTE: corrección de I-1, con su spec y su build (frontend).]
- [ ] **Autorización expresa del propietario para la fase 2**, con el nombre de quién publicará: con `ultron.publish` (Super Admin por defecto) y distinto del autor de la versión.
- [ ] **Puerta del laboratorio decidida por el propietario, por escrito:**
  - **`required`** (el defecto) exige un veredicto `approved` del laboratorio sobre la misma huella y con la versión de prompts vigente. Un veredicto solo existe sobre ejecuciones en modo `openai` (Hito 4, §A6): **mientras el laboratorio siga en `fake` y sin clave, como el 31-10-2026, toda publicación responde 409 `lab_verdict_required`.** El camino recomendado es cerrar antes la fase 3 del Hito 4 (decisión 2 del contrato).
  - **`waived`** lo decide el propietario y se escribe en el fichero de entorno. Según el informe de recuperación del lead (§6), la autorización del 25-10-2026 permite tomar esa decisión si queda documentada por escrito en cada publicación y en el informe. La cadena la registra sola en cada publicación (`lab_gate.mode = waived`), el CRM la pinta en rojo, y la decisión (fecha y quién la autoriza) se anota en `ULTRON_STUDIO_FINAL_REPORT.md` («Hito 5: despliegue por fases»). Se cambia con copia previa y sin imprimir el fichero:

    ```bash
    install -m 600 -o root -g root /var/www/api/backend/.env /root/env-pre-lab-gate-$(date -u +%Y%m%d-%H%M%S)
    sed -i -E 's/^([[:space:]]*)ULTRON_STUDIO_PUBLISH_LAB_GATE[[:space:]]*=.*/\1ULTRON_STUDIO_PUBLISH_LAB_GATE=waived/' /var/www/api/backend/.env
    cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 bootstrap/cache/config.php
    ```

    El informe de DevOps (§6.1) da la expresión de `sed` sin el fichero; aquí va con el fichero de entorno de producción. El paso 1 de abajo vuelve a leer la configuración efectiva y la imprime con `lab_gate`.
- [ ] El motor tiene canario (`canary_set: true`) y el freno está suelto.
- [ ] `GET engine/readings` da lo que declarará la primera versión (A1): `governance.enabled` true, `governance.canaryOnly` true, `rules.paymentLinksEnabled` false e `intent.driver` del entorno. Es lo que leyó el lead el 25-10 (decisión 10 del contrato) y lo que confirma su lectura del 31-10 (ULTRON encendido, canario configurado, cobro apagado).
- [ ] Una versión que refleje el motor tal como está (A1), creada por una persona y **aprobada por otra**. Crear y aprobar versiones deja filas que no se borran: es el objetivo de esta fase.

**Pasos:**
1. `bash /root/h5-engine-scope.sh canary off` (servidor, root). Esperado: `ALCANCE canary APLICADO (copia previa: /root/env-pre-scope-canary-<fecha>)`. El script comprueba antes que la configuración efectiva es `off` y que el motor tiene canario; deja una copia 600 del fichero de entorno; escribe `MARKETING_ULTRON_STUDIO_CONFIG=canary` y `ULTRON_STUDIO_PUBLISHING_ENABLED=true`; comprueba que el fichero parsea; regenera la caché con `umask 077`; comprueba la configuración efectiva pedida; ejecuta `queue:restart` y reinicia `ironbody-lab:*`; y comprueba 13 RUNNING y 0 BACKOFF/FATAL, `status` sin token → 401 y `publication-check` 0. Con algún desvío, `ALCANCE canary ESCRITO CON N DESVÍOS: no seguir con el smoke ni publicar hasta aclararlos` y código 1 (m-3 del reviewer): la palabra «APLICADO» solo sale en verde.
2. `ssh ironbody-vps 'SMOKE_PHASE=2 bash /root/smoke-run-h5.sh'` (copiando antes los dos ficheros), **antes de publicar nada** y sin nadie publicando mientras corre. Esperado: código 0. Con una versión APPROVED real, su vista previa no bloquea por las llaves.
3. **Publicar desde el CRM**, en el detalle de la versión, «Publicar en canario»: dentro de la ventana 07:00–19:00 de Bogotá, por una persona distinta de la autora, con la vista previa a la vista y reconociendo exactamente lo «sin efecto». Es la **primera publicación**: tiene que ser la versión que refleja el motor tal como está (A1). El servidor revalida todas las puertas (A5.1) y la cadena gana una entrada firmada.
4. **En el siguiente turno de la conversación canaria**, ver el sello sin imprimir ids:

   ```sql
   SELECT created_at, metadata->'studio'->>'state' AS estado, metadata->'studio'->>'part' AS parte, metadata->'studio'->>'reason' AS motivo
     FROM marketing_ai_actions WHERE metadata ? 'studio' ORDER BY id DESC LIMIT 5;
   ```

   Esperado: `applied` y `canary` en los turnos de la conversación canaria. Con el canario del motor puesto, las demás conversaciones no llegan al commit (`not_canary_conversation`, observación O-3 del E2E), así que no dejan filas con sello: no hay `out_of_scope` que ver. ULTRON tiene poco tráfico (3 turnos en 7 días al 25-10, decisión 10 del contrato): el primer sello puede tardar días.
5. (Opcional, plan del architect §8) Simulacro de reversión: revertir y comprobar que el turno siguiente sale `legacy`. La versión revertida queda `ROLLED_BACK`, que es terminal: volver a publicar exige otra versión y otra aprobación.

### Fase 3: todas las conversaciones y la promoción

**Antes:**
- [ ] **La evidencia del canario pasa** (`GET versions/{uuid}/canary-evidence` → `passes: true`; el CRM la enseña en «Promover a todas»):
  - al menos 24 horas desde la publicación;
  - al menos 3 turnos;
  - `stamped` igual a `turns`: el 100 % de los turnos, `applied` con ESTA versión;
  - 0 frenos desde la entrada y el freno suelto ahora;
  - el acta (`ultron:canary-report`) con total 0;
  - sin deriva;
  - `signatureOk`.

  Con unos 3 turnos por semana, son días. No se fuerza: sin turnos no hay evidencia, y es lo correcto (A6).
- [ ] **Ningún `fallback` en los sellos de la ventana.** Un turno así se queda para siempre en la evidencia, y ese canario ya no se puede promover.
- [ ] **Autorización expresa del propietario para la fase 3.**

**Pasos:**
1. `bash /root/h5-engine-scope.sh all canary`. Esperado: `ALCANCE all APLICADO (copia previa: …)`.
2. `ssh ironbody-vps 'SMOKE_PHASE=3 bash /root/smoke-run-h5.sh'` (copiando antes los dos ficheros): la cabeza verifica, `applied` y sin deriva.
3. **Promover desde el CRM**, «Promover a todas»: una persona distinta de la autora, dentro de la ventana y con la `evidenceHash` de la evidencia que se acaba de ver. Promover exige la llave del motor en `all` (si no, 409 `scope_not_allowed`) y comparte con `GET canary-evidence` el limitador de 10 por minuto y persona y 30 por minuto y red (AS-2).
4. Comprobar: `cd /var/www/api/backend && sudo -u www-data php artisan ultron-studio:publication-check` con código 0, `status.engine.activeVersion` igual a la versión y `state: applied`.

- **Honestidad (A6):** con el canario del motor puesto, `canary` y `all` llegan a la misma conversación. La promoción no cambia el tráfico.
- **El freno** lo echa el vigía ante un turno mudo del canario, por cualquier causa. Lo suelta quien gobierna el motor, a mano y con firma. El Studio no lo toca. Con el freno echado, publicar y promover responden 409 `engine_braked`.

**Criterio de cierre** (decisión 4 del contrato): **`HITO_5_COMPLETED`** solo con las fases 1, 2 y 3 ejecutadas y su evidencia en los informes. Si una dependencia externa lo impide (autorización, tráfico del canario o puerta del laboratorio), el hito queda en **`HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**.

### Incidencias previsibles durante el despliegue

Del informe de DevOps (§9); ninguna se ha visto en producción.

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `ABORTA` en el paso 0 del script | Algo de partida no es lo esperado (HEAD, checkout, `origin/main`, migraciones, respaldo, claves, esquema o cerrojos) | No se ha tocado nada. Aclarar la causa y repetir |
| `55P03 canceling statement due to lock timeout` en la migración | Alguien tenía `ultron_studio_versions`, aunque solo fuera leyéndola: la 100002 pide ACCESS EXCLUSIVE | Si cayó la 100001, no queda nada. Si cayó la 100002, queda la tabla sin guardas, que nadie usa con las llaves apagadas. **Relanzar el mismo comando** (reanuda) hasta 30/27/3/3/2. Mientras espera (hasta 5 s), las lecturas del Studio sobre versiones hacen cola; el motor no lee versiones |
| `42501 must be owner of function ultron_studio_forbid` | La función o la tabla no son de `iron` | Parar. Transferir la propiedad es de `database-specialist`, con autorización. **No forzar** |
| Workers en BACKOFF tras `queue:restart` | La caché no es de `www-data` o no se puede leer | `stat -c '%U %a' bootstrap/cache/*.php`; `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'` + `chmod 600`. `supervisorctl restart all` **solo con autorización**: reinicia servicios productivos |
| `el fichero de entorno NO parsea` (el script aborta después de escribir las claves y antes de tocar las cachés) | Una línea mal formada (el salto final ya lo asegura el script antes de añadir) | Restaurarlo desde `$BK/env.backup` (mismo dueño, modo 600) antes de seguir. El script solo imprime la clase del error, nunca el valor |
| El smoke da 429 en el login | Más de 3 ejecuciones por minuto | Esperar un minuto. La cuenta ya está en reposo |
| `publication_head_invalid` sin rotación de clave | La cabeza no verifica: una fila alterada, o `seq` envenenado con DML (N-3/AS-3) | Salida inmediata: `h5-engine-scope.sh off <alcance actual>`. Si `seq` = 2147483647, Legacy responde 500 (22003) y el Studio se queda sin salida: la reparación es del rol dueño, con autorización del propietario, porque toca el libro (`database-specialist`). Avisar a `security`. Ver `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5» |
| `status` sin `engine.publication` | `describe()` falla: la tabla, los privilegios o una 100002 a medias | `grep ultron_studio.publication_state_unavailable` en el log y `h5-schema-check.sql` |

**Rotación de la `APP_KEY` con la cadena viva:** no forma parte de ninguna fase. Si el propietario la autoriza por otro motivo, el procedimiento está en `ULTRON_STUDIO_ROLLBACK.md` («Hito 5»).

## Fases siguientes (no incluidas aquí)

Campañas Meta (solo lectura), Twilio Voice y números canarios (tabla auditada), adaptador de ElevenLabs tras bandera. Cada una con su migración revisada en PostgreSQL, su suite y su plan de reversión. (La persistencia, que también figuraba en esta lista, se desplegó con el Hito F el 18-10-2026; el laboratorio y la evaluación con OpenAI tienen su runbook en «Hito 4», más arriba, y la publicación, el canario y la reversión por versión, en «Hito 5». Actualizado el 31-10-2026.)
