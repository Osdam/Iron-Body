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

### Fase 3: OpenAI (solo con la clave dedicada del propietario) — EJECUTADA el 02-11-2026 (validación 2 pendiente)

**EJECUTADA el 02-11-2026, 00:59–01:00 UTC (lead, con autorización expresa del propietario en el chat del 01-11-2026).** Script `/root/lab-openai-fase3.sh` (calcado de los pasos 1–5; registro en `/root/h4-fase3-20261102-*.log`; copia previa del fichero de entorno en `/root/env-pre-lab-openai-20261102-005926`, 600 root):
- **Modelo:** `gpt-6.1-terra`, el más reciente del catálogo que ve la clave (134 modelos; fecha de creación 13-10-2026), con los precios de la página oficial (`developers.openai.com/api/docs/pricing`, consultada el 02-11-2026): **1,00 US$ de entrada y 5,00 US$ de salida por millón de tokens** (0,05 en caché; la estimación cobra la caché como entrada normal, a propósito). Sondeado antes desde el Mac con la misma forma de petición del cliente (`/v1/responses`, `store:false`, `json_schema` estricto, 400 tokens): admite `reasoning.effort` `none`, `low`, `medium`, `high`, `xhigh` y `max` (**no** `minimal`) y **rechaza `temperature`** («Unsupported parameter»): por eso la temperatura queda sin configurar y el esfuerzo en `medium` (el valor por defecto del modelo, explícito). Con `medium`, la sonda gastó 110 tokens de razonamiento de los 143 de salida; con `low` o `none`, 0.
- **Variables escritas** (10): `ULTRON_STUDIO_LAB_DRIVER=openai`, la clave, el modelo, `_REASONING_EFFORT=medium`, los dos precios, `_DAILY_BUDGET_USD=2`, `_RUN_BUDGET_USD=0.50`, `_ACTOR_MAX_OUTPUT_TOKENS=800` y `_JUDGE_MAX_OUTPUT_TOKENS=1600` (los topes duros: el máximo de salida incluye el razonamiento, y con 400/900 un juez con `medium` podría quedarse sin sitio). Con esos topes, el máximo estimado de una ejecución de 12 casos ronda 0,21 US$ (<0,50) y el día admite unas 9 ejecuciones en el peor caso.
- **Comprobado:** fichero de entorno `www-data 600` y parsea; caché `www-data 600`; configuración efectiva `{"env":"production","enabled":true,"driver":"openai","model":"gpt-6.1-terra","effort":"medium","temperature":null,"price_in":"1.00","price_out":"5.00","daily":"2","run":"0.50","actor_max":800,"judge_max":1600,"key_len":164,"shared_key":false,"configured":true,"reasons":[]}`; `security:check-secret-exposure` OK; `ironbody-lab:*` reiniciado (13 RUNNING, 0 BACKOFF/FATAL); `lab/status` sin token → 401; ERROR del log 345 → 345. La clave no queda en `/root/` (destruida tras escribirla).
- **Validación 1 hecha (01:12 UTC):** una ejecución real con la cuenta temporal (admin 32, activa solo durante la prueba; `/root/lab-validacion-run.sh`, calcado del smoke), 2 escenarios sobre la línea base («Solo saluda» y «Pregunta directa por el precio»), `promptVersion 2026-10-19.1`: estimación `expectedMicros 22545 / maxMicros 34545`, aceptada con `acceptedMaxCostMicros` (obligatorio: el CRM lo manda desde la estimación); ejecución `2d25ed20-96c5-4a06-8179-ee0b413b281b`, `completed` en 30 s, 2/2 casos con propuesta `review`, **coste real 14 081 micro-US$ (0,014 US$)** frente a los 34 545 reservados. Queda en la base como la primera ejecución del laboratorio. [PENDIENTE del propietario: cotejar ese coste con el panel de uso del proyecto en OpenAI.]
- **Desviación del runbook, anotada:** el propietario envió la clave por el chat en vez de escribirla él mismo en el servidor. El lead la llevó al servidor por `scp` desde un fichero 600 y destruyó las copias; aun así, **se recomienda rotarla** en el panel de OpenAI cuando el laboratorio deje de usarse a diario (el cambio es repetir este script con la clave nueva). El proyecto de OpenAI debe tener su propio límite de gasto; el del laboratorio (2 US$/día) es la segunda barrera.
- **Validación 2 pendiente:** una ejecución sobre una versión y un veredicto de una segunda persona (cuenta distinta de la autora). Es el mismo paso que la fase 2 del Hito 5 exige para la primera publicación con la puerta en `required`. Hasta entonces, `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`.

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

## Hito 5 (gobierno y versionado): runbook por fases — FASE 1 EJECUTADA el 01-11-2026 (fases 2 y 3 pendientes)

**Estado (01-11-2026): FASE 1 EJECUTADA** el 01-11-2026, 01:12–01:16 UTC (backend `7ba0ce8`, CRM `ab78a6a`; evidencia, smoke y verificación a los 15 minutos en `ULTRON_STUDIO_FINAL_REPORT.md`, «Hito 5»); **fases 2 y 3 pendientes** (24 h de estabilidad; decisión del propietario sobre la puerta del laboratorio y dos identidades reales para la primera publicación). *(Hasta el 31-10-2026 esta sección decía «pendiente de ejecución».)* Sale del runbook de DevOps (`plans/informes-h5/informe-devops-h5.md`, §4–§9), del informe de recuperación del lead (`plans/informes-h5/informe-recuperacion-2026-10-31.md`) y de los scripts de `ops/`. Donde no coinciden, mandan los scripts. El lead los reconstruyó sobre las propuestas A y B de DevOps (`5772bdf`) y el 31-10-2026 les aplicó las observaciones del reviewer (`db1d8f6` y `940b0c2`): `git` en el servidor va **como root con `umask 022`**, porque el árbol y `.git` son de root desde el 25-10 (I-3, comprobado en el servidor el 31-10-2026) y tras el `merge` se comprueba que `www-data` lee cada fichero cambiado; un tercer argumento que no sea `--solo-comprobar` no despliega nada (I-2); los commits se comparan con `--short=7` (m-6); los privilegios de `iron_app` se comprueban uno a uno, y UPDATE y DELETE se enseñan como información (m-5); `deploy-crm.sh` falla en voz alta (m-4); y `h5-engine-scope.sh` solo dice «APLICADO» en verde (m-3). **Lo que se copia al servidor es la versión confirmada de los scripts.** DevOps ensayó sus propuestas en un banco local sobre `fef7c87` (§3.3 y §3.4); los cambios posteriores y `PREVIO=7318122` no se han ensayado, y en el servidor no se ha ejecutado nada. Vuelta atrás: `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5». Scripts: `ops/README.md`.

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
| `ULTRON_STUDIO_PUBLISHING_ENABLED` | `false` | `true` | `true` | Apagada: ver la nota de debajo de la tabla (M-1/AS-1, corregido en `d24c512`). `h5-engine-scope.sh` la abre con `canary` y con `all` (N-9) |
| `ULTRON_STUDIO_PUBLISH_LAB_GATE` | `required` | `required`, o `waived` por decisión escrita del propietario (ver «Fase 2») | La que quede en la fase 2 | `required`: solo `waived` dispensa |
| `ULTRON_STUDIO_CANARY_MIN_HOURS` | `24` | `24` | `24` | 24; un entero se acota a 1–168 |
| `ULTRON_STUDIO_CANARY_MIN_TURNS` | `3` | `3` | `3` | 3; un entero se acota a 1–100 |
| `ULTRON_STUDIO_PUBLISH_WINDOW` | `07:00-19:00` | igual | igual | Hora de Bogotá, `[inicio, fin)`. Con la línea vacía (`ULTRON_STUDIO_PUBLISH_WINDOW=`), sin ventana. Mal escrita, con el inicio después del fin o cruzando la medianoche: `07:00-19:00` |
| `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS` | `300` | igual | igual | 300; un entero se acota a 60–3600 (umbral de desatención del carril `lab`, m-3 del Hito 4) |

Las siete tienen su defecto en el código. La fase 1 las escribe explícitas para que la configuración real quede a la vista (`decisiones-lead-h5.md`, paso 3).

**`ULTRON_STUDIO_PUBLISHING_ENABLED` (y `ULTRON_STUDIO_LAB_ENABLED`, del Hito 4): la encienden `true`, `1`, `on` o `yes` (sin distinguir mayúsculas); cualquier otro valor, incluido `off`, `no` o vacío, la deja apagada** (Enmienda 2 §E2.2; m-1 y m-8 del reviewer). Quien edite el fichero de entorno a mano para congelar las publicaciones escribe `false`.

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
- [ ] **Revisión final (`reviewer`) sin bloqueantes.** Veredicto del 31-10-2026 (`plans/informes-h5/informe-reviewer.md`, confirmado en `db1d8f6`): **APROBADO CON OBSERVACIONES**, sin bloqueantes en el código. Antes de la fase 1 pedía cerrar I-2 e I-3 (los scripts, cerradas en `db1d8f6`) e I-4 (la vuelta atrás del código, corregida en `ULTRON_STUDIO_ROLLBACK.md` el 31-10). I-1 (CRM) es de antes de la fase 2: ver «Fase 2». [PENDIENTE: segunda pasada del reviewer sobre la documentación del Hito 5, incluida esta sección.]
- [ ] **Puerta PostgreSQL en verde sobre el commit que se despliega**, posterior a la fusión de `origin/main` del 31-10-2026: `docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio`. **Hecho por el lead tras la fusión (31-10-2026):** la puerta PostgreSQL, **1446 OK** sobre `cdb9dc8` y **1447 OK** sobre `cf59cef`, con las 38 carreras; Studio + Access + Chaos en SQLite, **1697 OK** sobre `cdb9dc8` (56 omitidas, las de solo PostgreSQL); el Studio en SQLite, **1447 OK** sobre `cf59cef`; Marketing (Feature + Unit), **4977 OK**; `auth:coverage --unmapped` vacío y **38 rutas**. Los commits posteriores del backend (`db1d8f6`, `940b0c2` y la documentación) no tocan código. Con las carreras, la puerta tarda de 3 a 8 minutos; si en una máquina lenta la carrera no llega a ocurrir, la prueba falla en voz alta, nunca en verde. **Sin esta puerta no se despliega:** se repite si el backend cambia de código antes de la fase 1.
- [ ] **E2E con la pila real en verde.** **Hecho por el lead (31-10-2026, 21:26–21:30 UTC) sobre `cf59cef` + `9fe1817`: 408/408** (F 40, G 25, H4 27, turno 30, `h5-governance` 276 y cierre 10, en 257 s): sin 5xx en 465 peticiones del CRM, `laravel.log` sin ERROR, colas vacías, 0 violaciones de los simuladores de OpenAI y de n8n, la valla de red vacía y ninguno de los 4 secretos de la ejecución en 19 registros ni en 9 tablas. Es la mejor pasada registrada del arnés.
- [ ] **Build de producción del CRM** sobre el commit final, con la corrección de I-1 si llega antes (ver «Fase 2»). **Hecho:** build `ab78a6a` (módulo 882/882, `tsc`, literales; sellado y limpio), desplegado en la fase 1.
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
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h5-schema-check.sql
```

- **`git` sobre `/var/www/api` va como root, con `umask 022`, igual que en los scripts** (I-3; decisión del lead, comprobada en el servidor en solo lectura el 31-10-2026). Desde el 25-10, el árbol de `/var/www/api` (2.493 entradas, entre ellas todo lo que cambia el Hito 5) y `.git` (5.089 de sus 5.131 entradas) son de root: `www-data` los lee, pero no puede escribir ni en `objects/` ni en los directorios del código, así que un `merge` como `www-data` fallaría a medias. Código y `.git` de root, legibles por `www-data`, es la postura más segura (PHP-FPM no puede reescribir el código), y la M-3 original ya no se da: no hay hooks ni `core.hooksPath`, y root tiene `safe.directory=/var/www/api`. Tras el `merge`, el script comprueba fichero a fichero (`git diff --name-only 7318122 HEAD`, con `sudo -u www-data test -r`) que `www-data` lee cada uno, y aborta si no. *(El 25-10 se leyó `.git` como de `www-data` y los scripts pasaron a `sudo -u www-data -H git`; esa lectura ya no es la de hoy. Si el `stat` de arriba no da root, no seguir y avisar al lead.)*
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
- **Reanudación tardía** (nota del reviewer; decisión del lead, 31-10-2026). Si la fase 1 se corta y se relanza más de 2 horas después del respaldo, el script aborta en el paso 0 porque el volcado ya no tiene menos de 2 horas (falla cerrado), y **hay que rehacer el respaldo**: el mismo bloque del paso 3, pero con `api-commit.txt` igual al `PREVIO` y no al HEAD de ese momento, que ya es el commit nuevo. Es decir, la línea de `api-commit.txt` pasa a ser `git -C /var/www/api rev-parse 7318122 > "$BK/api-commit.txt"`. Con el HEAD, el script volvería a abortar en el paso 0, y la vuelta atrás del código (que lee ese fichero) apuntaría al commit equivocado.
- **No se sigue con el CRM** si el script no termina en `COMPLETADA`.

**6. Verificación del backend (solo lectura)**

```bash
cd /var/www/api/backend
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h5-schema-check.sql
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
- [ ] M-1/AS-1 cerrado: corregido en `d24c512`, con prueba negativa en `UltronStudioPublicationConfigTest` (`off`, `no`, `disabled` o ` false` ya no abren publicar). El E2E con la pila real lo detectó sobre `2bc053b` (su B-1), y en la pasada de cierre del lead sobre `cf59cef` + `9fe1817` (31-10-2026, 408/408) esas comprobaciones ya salen en verde.
- [x] **I-1 del reviewer corregido en el CRM** (importante, antes de la fase 2): en la misma pestaña, un segundo «Volver a Legacy» con el mismo motivo reenvía la misma `clientRequestId`, el servidor responde 200 `replayed` con la entrada vieja y la salida no se ejecuta, aunque entretanto otra persona haya publicado. No afecta a la fase 1. Lo está corrigiendo `frontend` (31-10-2026), previsiblemente antes del build de la fase 1: así el CRM se despliega una sola vez. **Corregido** en el CRM `ab78a6a` (31-10-2026, `frontend`): la clave de idempotencia se olvida tras cualquier respuesta definitiva (2xx legible, 4xx) y se conserva solo tras 429, 5xx, sin red o 2xx ilegible; 31 specs nuevas que fallaban sin la corrección; `ab78a6a` es el build desplegado en la fase 1.
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

## Hito 6 (integraciones comerciales): runbook por fases — FASE 1 EJECUTADA el 02-11-2026 (fase 2 pendiente)

**Estado (02-11-2026): la fase 1 se ejecutó el 02-11-2026 entre las 04:37 y las 04:57 UTC (ver «Fase 1: ejecución», abajo); la fase 2 no.** El Hito 6 está implementado en la rama `feat/ultron-studio-h6` de los dos repos (backend `411d8b1`, cuyo código es el de `4e2e8cf`; CRM `16c433c`: la página «Integraciones», el fixture reexportado `3bac1e2`, el arreglo H-1 `8536c0e` y el E2E completo), **sin empujar ni desplegar** *(así se escribió antes de la ejecución; desde el 02-11-2026 04:54 UTC está en `origin/main` y en producción en su fase 1: backend `1e5f9a0`, CRM `16c433c`)*. **Al 02-11-2026 04:40 UTC están cerradas todas las condiciones previas salvo el build del CRM, que se hace en el paso 7:** E2E 660/660, segunda pasada de `security`, `reviewer` APROBADO CON OBSERVACIONES y línea base por nombre con 0 rojos nuevos (ver «Condiciones previas»). Esta sección sale del runbook de DevOps (`plans/informes-h6/informe-devops-h6.md`, §1–§7), del contrato (`ULTRON_STUDIO_H6_CONTRACT.md`, «Despliegue por fases» y Enmiendas 1–4) y de los scripts de `ops/` (`ops/README.md`, «Hito 6»). **Donde no coinciden, mandan los scripts**, en la versión confirmada en el commit que se despliega. DevOps los ensayó en un banco local (`ops/banco-h6/`, sobre el código de S confirmado en `d2c04a0`) y contra el arnés E2E del CRM (§8 de su informe). Vuelta atrás: `ULTRON_STUDIO_ROLLBACK.md`, «Hito 6». Scripts: `ops/README.md`.

**Producción de partida** (la que dejó la fase 1 del Hito 5, 01-11-2026 01:12–01:16 UTC; ver «Hito 5» y `ULTRON_STUDIO_FINAL_REPORT.md`): API `7ba0ce8`, CRM `ab78a6a`, 38 rutas del Studio, 30 disparadores y 27 CHECK en `ultron_studio_%`, 2 índices parciales, 11 tablas `ultron_studio_*`, 13 workers RUNNING y `failed_jobs` en 2. Según el lead (01-11-2026), la fase 1 del H5 sigue estable a las 12 horas; en este repositorio solo consta por escrito la verificación de los 15 minutos. **Verificación a las 24 horas hecha (lead, 02-11-2026 01:26 UTC, solo lectura):** HEAD `7ba0ce8`; 13 RUNNING; facturación activa; `failed_jobs` 2 (igual); 0 sellos; 70 turnos de ULTRON en 24 h; freno suelto; 0 publicaciones; `publication-check` 0; colas al día; `security:check-secret-exposure` OK; ERROR del log 345 (igual que la base); 0 señales; 0 respuestas 5xx de la API desde el despliegue; CRM `ab78a6a`. La fase 1 del Hito 5 es estable: es la base para su fase 2.

**Hechos de producción al congelar el contrato** (lead, 31-10-2026, solo lectura y sin imprimir valores; la matriz de credenciales del 01-11-2026, `plans/informes-h6/matriz-credenciales-vps.md`, los repite): `OTP_DRIVER=twilio`; `CACHE_STORE=database`; `TWILIO_SID` y `TWILIO_ACCOUNT_SID`, y `TWILIO_TOKEN` y `TWILIO_AUTH_TOKEN`, con huellas iguales (la misma credencial con dos nombres); ninguna variable `TWILIO_VOICE_*` y 0 filas en `marketing_calls`; `META_AD_ACCOUNT_IDS` con 3 cuentas y `META_ADS_SYNC_ENABLED=true`; ninguna referencia a ElevenLabs.

**Qué es este hito en producción.** El Studio lee, sin escribirlos, el espejo local de Meta Ads (`meta_ad_entities`, `meta_sync_runs`), los retos del OTP, `marketing_calls` y la conversación canaria del motor, y guarda tres registros propios: las asociaciones campaña ↔ línea base o versión, los números canarios y las instantáneas de configuración. **Nada de eso cambia lo que ULTRON dice o hace:** el motor no lee nada nuevo (T0 y T1 del contrato, I4.7).

| Fase | Qué cambia | Coste | Autoriza |
|---|---|---|---|
| 1 | Código (18 rutas nuevas; 56 en el Studio), 2 migraciones aditivas (3 tablas, sus guardas y el CHECK del libro de 4 a 7 sujetos), 8 variables (escrituras, sonda y voz apagadas), cachés, `queue:restart`, el CRM y 3 instantáneas de sistema. Las escrituras 47–54 responden 409 `integrations_writes_disabled` y la sonda, 409 `probe_disabled` | 0 | La autorización del 25-10-2026 (contrato, «Autorizaciones»), con la puerta en verde y el H5 en su fase 1 |
| 2 | `writes on`; T1 (una persona registra el número de la conversación canaria y otra lo autoriza y lo activa); `probe on` y una pulsación de la sonda; reconocimiento de la configuración de los 3 proveedores | 0: la sonda son 5 GET a la API de Twilio, con enfriamiento de 300 s y cupo | La misma, con la fase 1 verificada y **dos personas** disponibles |

**Qué NO toca ninguna fase:** el motor (cero líneas en `app/Services/Marketing/**`, `app/Jobs/**`, `app/Http/Controllers/Api/Internal/**`, `routes/marketing.php` y `config/marketing.php`), `MARKETING_ULTRON_ENABLED`, la pausa, el freno, el id de la conversación canaria del entorno (el T1 no lo toca), el cobro, n8n, el OTP y su configuración (`app/Services/Sms/**`, `app/Services/Otp/**`, `config/otp.php`, `config/meta.php`), Meta (ninguna escritura), ElevenLabs (se queda en `ELEVENLABS_NOT_CONFIGURED`) y las llamadas (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`). Tampoco FPM, supervisor, DNS, TLS ni el firewall. **Fuera de este runbook, con autorización expresa aparte:** T2 y T3 (que el motor lea el registro), cualquier llamada, cualquier escritura en Meta y encender ElevenLabs.

**Variables del Hito 6** (contrato I12 e I6.2; defectos en `config/ultron_studio.php` y `config/voice.php`; `.env.example` las lleva todas con su defecto seguro). La fase 1 escribe **8**, explícitas aunque coincidan con el defecto, para que la configuración real quede a la vista. *(El contrato, «Fase 1» paso 2, enumera 6, y el informe de DevOps habla de «las 10 variables» y escribe 8; manda el script: la lista `VARS` de `deploy-h6-backend-fase1.sh`.)*

| Variable | Fase 1 | Fase 2 | Si falta, está vacía o no es válida |
|---|---|---|---|
| `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` | `false` | `true` (`h6-integrations-scope.sh writes on off`) | `false` |
| `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` | `false` | `true` (`… probe on off`); puede quedarse encendida | `false` |
| `ULTRON_STUDIO_CANARY_MAX_ACTIVE` | `3` | `3` | 3; un entero se recorta a 1–10 |
| `ULTRON_STUDIO_CANARY_COUNTRY_CODES` | `57` | `57` | `['57']` si queda vacía; lista separada por comas de prefijos `^[1-9]\d{0,2}$` |
| `ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE` | `50` | `50` | 50; un entero se recorta a 1–200 |
| `ELEVENLABS_ENABLED` | `false` | `false` | `false` |
| `TWILIO_VOICE_ENABLED` | `false` | `false` | `false` |
| `TWILIO_VOICE_DAILY_CALL_CAP` | `0` | `0` | 0; un entero se recorta a 0–20 |

- **No se escriben nunca:** `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` y `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET` son del propietario (la API key restringida de solo lectura, opcional). Sin las dos, la sonda usa la credencial del OTP (`probe_credential: otp`). Con solo una, el script avisa y la sonda usa también la del OTP.
- **Se ignoran en `production`** (el script avisa si están): `ULTRON_STUDIO_TWILIO_PROBE_BASE` y `ELEVENLABS_BASE_URL`. La sonda y la verificación de voz usan siempre los hosts oficiales.
- **Tienen que estar ausentes o vacías** (si no, el paso 0 aborta: el hito se cierra en `ELEVENLABS_NOT_CONFIGURED` y sin número de voz): `ELEVENLABS_API_KEY`, `ELEVENLABS_AGENT_ID`, `ELEVENLABS_WEBHOOK_SECRET`, `ELEVENLABS_TOOL_SECRET`, `ELEVENLABS_AGENT_PHONE_NUMBER_ID` y `TWILIO_VOICE_FROM`. `ELEVENLABS_REGION`, ausente o `default`.
- **Las cuatro banderas** (escrituras, sonda, `ELEVENLABS_ENABLED` y `TWILIO_VOICE_ENABLED`) se leen con `filter_var(…, FILTER_VALIDATE_BOOLEAN)`: solo `true`, `1`, `on` o `yes` las encienden; cualquier otro valor las deja apagadas. Quien edite a mano para cerrar escribe `false`.

**Orden y ventanas de compatibilidad:**
- **Backend → CRM.** Cada uno se revierte por separado.
- **CRM del Hito 6 con el backend del Hito 5:** las 18 rutas `integrations/*` responden 404 y la página dice «El servidor todavía no tiene integraciones (Hito 6)»; el resto del Studio no pide ninguna. Lo prueban `studio-integrations-compat.spec.ts` (`plans/informes-h6/informe-front-datos.md` §5.1) y el esqueleto del E2E con `7ba0ce8` (las 8 lecturas, 404; `informe-e2e-arnes.md`). **Comprobado en el E13 del E2E con la pila real (02-11-2026): 35/35 con el backend `7ba0ce8`; la página dice «El servidor todavía no tiene integraciones (Hito 6)» y el resto del Studio sigue funcionando.**
- **CRM del Hito 5 con el backend del Hito 6:** no pide `integrations/*`. Su panel del libro solo conoce 4 sujetos y descarta como ilegibles los eventos `campaign_link`, `canary_number` e `integration` (`informe-front-datos.md` §5.3, leído en el código). En la fase 1 no hay ninguno: la instantánea de sistema no escribe en el libro (solo lo hacen reconocer y la sonda).
- **Barrera doble** (DevOps §1): las 18 rutas no se sirven hasta `route:cache`, y el script solo lo ejecuta con el esquema completo (43/49/4/14). Aun servidas, las escrituras y la sonda están tras sus banderas.
- **Socios, caja y ULTRON no notan nada:** el motor no lee nada nuevo y las tablas nuevas solo las usa el Studio.
- **No usar el Studio durante el despliegue:** la 100002 pide ACCESS EXCLUSIVE sobre el libro, y una pestaña del Studio con una lectura abierta basta para provocar el 55P03 (`informe-db.md` §7.5 y R-1).

### Fase 1: código desplegado con escrituras, sonda y voz apagadas

**Qué cambia:** el código (18 rutas nuevas: 8 GET y 10 POST; 56 en el Studio); las migraciones `2026_11_01_100001_create_ultron_studio_integration_tables` y `2026_11_01_100002_add_ultron_studio_integration_guards` (3 tablas, 13 disparadores, 22 CHECK, los índices únicos parciales `uscl_one_active` y `uscn_one_live`, y `usae_subject_type_chk` de 4 a 7 sujetos); las 8 variables; las cachés y `queue:restart`; los estáticos del CRM; y la primera instantánea «observada» de `meta_ads`, `twilio` y `voice`. No reinicia FPM ni toca supervisor, DNS, TLS ni el firewall.

**Lo que se queda:** las dos migraciones (**nunca `down()` en producción**) y las instantáneas de sistema (solo inserción). El smoke deja la fila 32 de `admins` en reposo, 2 sesiones borradas y líneas INFO y WARNING en el log.

**Condiciones previas:**
- [x] **El Hito 5 en su fase 1** (requisito previo del contrato): desplegado el 01-11-2026.
- [x] **Revisión final del `reviewer` sin bloqueantes.** **Hecho (02-11-2026, `informe-reviewer.md`, confirmado en `411d8b1`): APROBADO CON OBSERVACIONES**, sin bloqueantes ni mayores; menores m-1..m-4 (CRM: longitudes UTF-16 en `valorAdmitido`, spec de regresión de H-1, errata de concordancia, rótulo de la última sincronización) diferidas al Hito 7; m-5 (estados desfasados en los documentos) y m-6 (`api-commit.txt` exige siempre `PREVIO`) corregidas en el commit que cierra las puertas. `security` y `api-security-specialist` ya revisaron, sin bloqueantes (Enmienda 3), y sus correcciones están confirmadas (`3e1ee20`, `bd1c7af`, `6241758` y `4e2e8cf`). **Segunda pasada de `security` sobre la página del CRM hecha** (01-11-2026, `informe-security.md` «Segunda pasada», confirmada en `8c3eb7e`): sin bloqueantes ni hallazgos; notas N-6 (lector de las bases de Twilio con host IPv6 o guion bajo) y N-7 (fixture desfasado, cerrada en `3bac1e2`).
- [ ] **La puerta del contrato en verde sobre el commit que se despliega** («Puerta antes de cada fase»): Studio + Access + Chaos en SQLite; el grupo del Studio en PostgreSQL con `docs/ultron-studio/ops/pgunit.sh`, carreras del tester incluidas; las suites de ULTRON, Marketing y OTP iguales a la base; `auth:coverage --unmapped` vacío y 56 rutas; Pint en lo tocado; el módulo del CRM y su build; el E2E `h6-integrations` y la regresión del H5 en verde con la valla vacía; y la puerta de diff vacía. **Hecho por el lead sobre `e3a0fdd`** (el código final del backend, igual que `4e2e8cf`; 01-11-2026, 18:43–18:49 UTC): Studio + Access + Chaos + Voice + Twilio + OTP en SQLite, **2879 OK** (102 omitidas, las de solo PostgreSQL); la puerta PostgreSQL, **2455 OK** en 4 minutos (base `ultron_studio_h6_lead`); **56 rutas**, `auth:coverage --unmapped` vacío y la puerta de diff del motor vacía; Marketing, **4977 OK** sobre `d2c04a0`. **Línea base por nombre hecha (lead, 02-11-2026 00:28–01:16 UTC, `suite-compare.sh`: rama `2ac7f54`, código `4e2e8cf`, contra `origin/main` `2660173`, el mismo día y en secuencia): rama 12 399 pruebas / base 11 288; 57 rojos idénticos en los dos lados (la línea base conocida, artefactos de la instantánea incluidos) y 0 nuevos; una prueba de la base desaparece porque el Hito 6 la renombra (`ExportStudioFixturesCommandTest`, «five files» → «six files»).** El E2E está hecho (abajo); el build del CRM, en el paso 7. **Sin esta puerta no se despliega:** se repite si el backend cambia de código antes de la fase 1.
- [x] **E2E con la pila real.** **Hecho (tester, 02-11-2026 01:00 UTC, `informe-tester-e2e.md`; CRM `16c433c`): pasada completa final 660/660 en 1548 s con backend `2ac7f54` y CRM `8536c0e`** (F 40, G 25, H4 27, turno 30, H5 276, `h6-integrations` 250 con E1–E12, E14 y E15, cierre 12: 17 tablas del motor idénticas, 0 respuestas 5xx, 0 ERROR, colas vacías, valla vacía, 0 violaciones en los 4 simuladores y 0 secretos); E13 (CRM del H6 contra el backend del H5 `7ba0ce8`) 35/35; mutaciones 3/3 cazadas; H-1 corregido en `8536c0e`; observaciones O-1..O-4 sin bloquear (m-3 y m-4 del reviewer, al Hito 7).
- [ ] **La puerta de diff vacía** (contrato), desde `backend/`: `git diff 7ba0ce8 --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api app/Services/Sms app/Services/Otp routes/api.php routes/marketing.php routes/console.php config/marketing.php config/meta.php config/otp.php app/Services/UltronStudio/StudioCatalog.php composer.json composer.lock`. **Comprobado en `e3a0fdd` (01-11-2026):** vacía, también desde `e1b1d26`; `7ba0ce8` es ancestro de HEAD, y entre los dos solo hay las 2 migraciones del Hito 6. El script la repite en su paso 0.
- [ ] **Build de producción del CRM** sobre el commit final, con la página. **Hecho sobre `f38699d`** (`plans/informes-h6/informe-front-integracion.md` §5.2): salida 0, los mismos 36 avisos de presupuesto que en `ede716b`, nombre a nombre, y el chunk nuevo `studio-integrations-page` de 162,46 kB (38,03 kB transferidos); módulo y modelos 1096/1096. **Hecho (lead, 01-11-2026):** `backend-integrations.testing.ts` reexportado desde el backend final y paridad repetida (CRM `3bac1e2`, módulo + modelos 70/70 ficheros); suite completa del CRM sobre `3bac1e2`, 171/176 ficheros con solo los 5 de la línea base (16 rojos conocidos, 0 nuevos). Después, el H-1 del E2E (topes de longitud en unidades UTF-16) quedó corregido en `8536c0e` (`studio-parse.ts`: puntos de código; módulo 69/69). [PENDIENTE: rehacer el build de producción sobre el commit que se despliega (`8536c0e` o posterior), en el paso 7.]
- [ ] **`PREVIO` = el HEAD de producción el día del despliegue.** Hoy, `7ba0ce8`. Si producción se mueve antes (otro equipo despliega después del H5), el paso 0 aborta («producción no está en … ni en …»): se revisa qué entró, se actualiza `PREVIO` en un commit y se vuelve a copiar el script.
- [ ] **Push con avance rápido de los dos repos** (autorización del 25-10-2026): `git push origin feat/ultron-studio-h6:main` en cada uno, solo si es avance rápido sobre `origin/main`. **Nunca `--force`.** La rama del backend ya trae `origin/main` fusionado (`d2c04a0`, H-2 de DevOps).
- [ ] Unos 15 minutos sin nadie en el Studio.
- [ ] Anotados a mano:
  - `<commit-backend>`: `git rev-parse --short=7 origin/main` del backend tras el push (siete cifras: el script compara con `--short=7`);
  - `<build-crm>`: el commit de `origin/main` del CRM;
  - `<crm-prod>`: el `commit` de `https://ironbodyneiva.cloud/version.json` (esperado `ab78a6a`; comprobarlo).

**1. Respaldo** (en el servidor, como root, menos de 2 horas antes del paso 5: el script lo exige)

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h6-$TS
( umask 077
  mkdir -p "$BK"
  git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                       # empieza por PREVIO (hoy, 7ba0ce8)
  sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
  sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
  tar -C /var/www -czf "$BK/app-crm.tgz" app
  install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
  cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
  cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                          # al menos 223 (H-4)
stat -c '%a %U %n' "$BK" "$BK"/*                                                  # 700 el directorio; 600 y root el volcado y env.backup
du -h "$BK/ironbody.dump"; echo "BK=$BK"
```

- **`umask 077` va dentro de una subshell**, como en el Hito 5: el volcado y la copia del entorno nacen en 600 dentro de un directorio 700, y la sesión de root no se queda con esa umask. Un `git` posterior en la misma sesión (por ejemplo, una vuelta atrás a mano) dejaría en 600 lo que reescribiera, ilegible para `www-data`. *(El §3 del informe de DevOps la fija en la sesión entera; aquí no.)*
- **Lo que comprueba el script de este respaldo:** que `api-commit.txt` empiece por `PREVIO`; que el volcado tenga menos de 2 horas y su `.sha256` cuadre; el directorio en 700; el volcado y `env.backup` de root y en 600; al menos 223 tablas con datos; y, si no es una reanudación, que el fichero de entorno sea idéntico a `env.backup`. **Por eso el respaldo va después de cualquier cambio a mano en el fichero de entorno.**
- **H-4 de DevOps, el umbral de 223:** las 222 tablas del volcado del Hito 5 del 01-11-2026 (antes de migrar) más `ultron_studio_publications`. Si el script aborta por él, se cuentan las tablas a mano (`pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'` frente a `SELECT count(*) FROM pg_tables WHERE schemaname = 'public';`) antes de pensar en bajarlo, y solo se baja en un commit.
- **El respaldo es tan sensible como el servidor** (B-1 de `security`): guarda juntos el volcado y `env.backup`, y con la `APP_KEY` las huellas de los teléfonos canarios se recuperan. `env.backup` lleva secretos: 600 y nunca se imprime. **Nunca se borra un respaldo.**
- **El respaldo automático diario** (`ironbody-db-backup.timer`) no sustituye a este: puede tener hasta 24 horas.

**2. Copiar los scripts** (desde el Mac, con el árbol del backend en `<commit-backend>` y `git status` limpio)

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body/backend
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h6-backend-fase1.sh $O/h6-integrations-scope.sh $O/smoke-h6.py $O/smoke-run-h6.sh $O/h6-schema-check.sql
scp $O/deploy-h6-backend-fase1.sh $O/h6-integrations-scope.sh $O/smoke-h6.py $O/smoke-run-h6.sh $O/h6-schema-check.sql ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/deploy-h6-backend-fase1.sh /root/h6-integrations-scope.sh /root/smoke-run-h6.sh && chmod 600 /root/smoke-h6.py /root/h6-schema-check.sql && sha256sum /root/deploy-h6-backend-fase1.sh /root/h6-integrations-scope.sh /root/smoke-h6.py /root/smoke-run-h6.sh /root/h6-schema-check.sql'
```

Las huellas del Mac y del servidor tienen que coincidir. Se copian desde el Mac, y no con `git show` en el servidor, para que root ejecute git lo imprescindible. **El smoke borra sus dos ficheros de `/root/` al terminar:** antes de cada ejecución (también en la fase 2) se vuelven a copiar, con sus huellas.

**3. Esquema antes** (solo lectura)

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h6-schema-check.sql
```

Esperado: `disparadores_studio` 30 y `check_studio` 27; 0 en todo lo del Hito 6 (disparadores y CHECK `uscl_`, `uscn_` y `usis_`, `parciales_h6` y `tablas_h6`); `parciales_studio` 2; `check_7_sujetos` f; `funcion_es_la_de_f` t; `dueno_funcion`, `dueno_libro` y `dueno_versiones` `iron`; `create_en_public` t; `tablas_studio` 11; `eventos_sujetos_nuevos` 0; y **0 filas de cerrojos**. Va por la entrada estándar porque `postgres` no puede leer `/root`. **Se para si** la función o alguna de las dos tablas no es de `iron` (la 100002 fallaría con 42501: no forzar), si `funcion_es_la_de_f` sale f o si hay cerrojos.

**4. Ensayo en el servidor** (solo lectura, salvo el `git fetch`)

```bash
bash /root/deploy-h6-backend-fase1.sh <commit-backend> "$BK" --solo-comprobar
```

Esperado: `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`. Es lo único que cubre en el servidor lo que el banco local no puede: GNU `stat` y `grep`, `sudo` real, los dueños `root` y `www-data` y el envoltorio `ironbody-migrate`. Con cualquier `ABORTA`, no se ha tocado nada: se aclara la causa y se repite.

**5. Backend**

```bash
bash /root/deploy-h6-backend-fase1.sh <commit-backend> "$BK" 2>&1 | tee /root/h6-fase1-$(date -u +%Y%m%d-%H%M%S).log
```

Lo esperado, según las líneas que imprime el script. No se ha ejecutado en el servidor; en el banco local terminó en `FASE 1 (H6) COMPLETADA` en 45–46 s, con un 55P03 provocado y reintentado. `…` sustituye detalle que no cambia el resultado, y `<…>`, valores del momento:

```
0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):
   respaldo /var/backups/ironbody/ultron-studio-h6-<TS>: <≥ 223> tablas con datos · 700/600 de root
   claves del Hito 6 ausentes o iguales · sonda: credencial otp · Twilio: SID por "TWILIO_SID", token por "TWILIO_TOKEN" (sin valores)
   HEAD 7ba0ce8 limpio · puerta de diff vacía · esquema 30/27 del H5, 2 parciales, 11 tablas, de iron · función de F · sin cerrojos · rutas [38 0 0 0] · workers 13/0 · facturación activa
   libro: <N> 0 (eventos · de sujetos nuevos) · colas fallidas: <N> · sellos studio: 0
   motor, H4 y H5 (informativo; no cambia): {"engine_scope":"off", …}
1. código: HEAD=<commit-backend>
   los <N> ficheros del merge son legibles por www-data
2. variables (escrituras y sonda apagadas, voz apagada, topes por defecto):
   ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED añadida
   … (las otras siete, igual)
   permisos del fichero: <dueño> <modo>
   el fichero de entorno parsea
3. migración (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):
   … 2026_11_01_100001_create_ultron_studio_integration_tables … DONE
   … 2026_11_01_100002_add_ultron_studio_integration_guards … DONE
   esquema 43 disparadores · 49 CHECK · 4 parciales · 14 tablas (intento 1)
4. esquema del Hito 6 (antes de servir las rutas nuevas):
   … : 5 5 3 4 12 6 2 true iron,iron,iron 0 0 0 0
   iron_app (SELECT/INSERT/UPDATE/TRUNCATE/secuencia) en asociaciones · canarios · instantáneas: true/true/true/false/true true/true/true/false/true true/true/n/false/true
5. cachés y workers:
   cachés: www-data 600 · www-data 600
6. rutas del Studio (total · integrations · GET · POST): 56 18 8 10 · auth:coverage --unmapped → []
7. configuración efectiva:
   {"writes_enabled":false,"probe_enabled":false,"probe_cooldown_s":300,"probe_credential":"otp", …}
   motor, H4 y H5 iguales que antes del despliegue
8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [13 0] y [13 0]
9. sanidad:
   … sin token → 401 (cuatro líneas: integrations/status, canaries, config y POST meta/links)
   usage-trigger sin firma → 403 (falla cerrada; T-1)
   … colas fallidas sin crecer, 0 sellos, security:check-secret-exposure OK, publication-check 0,
   fichero de entorno igual al respaldo salvo las 8 claves del Hito 6
10. integrations-doctor:
   twilio connected_otp_only · twilio_voice not_configured · elevenlabs ELEVENLABS_NOT_CONFIGURED: 0
   meta_ads connected_read: 0                   (o una línea INFO si Meta sale degraded)
11. primera instantánea de sistema (integrations-snapshot; inserta solo si la huella de un proveedor cambió):
   instantáneas: 0 → 3 · observadas por proveedor: meta_ads=1 twilio=1 voice=1
12. laravel.log ERROR desde el inicio del despliegue: 0
FASE 1 (H6) COMPLETADA
```

- **Lo que hace el script, en orden:** comprobaciones de solo lectura (abortan sin tocar nada) → `merge --ff-only` como root con `umask 022` → comprobación de que `www-data` lee cada fichero de `git diff --name-only PREVIO HEAD` → las 8 variables (con el salto final asegurado) → comprobación de que el fichero de entorno parsea → `ironbody-migrate` con `PGOPTIONS="-c lock_timeout=5s"` y hasta 3 intentos ante un 55P03 → **compuerta del esquema (43/49/4/14, guardas por prefijo, 7 sujetos, función de F, dueños, privilegios de `iron_app` y 0 filas) antes de `route:cache`** → `route:cache` con `umask 077`, cachés de `www-data` en 600 y `queue:restart` → aserciones (rutas, configuración efectiva exacta y motor sin cambios, workers dos veces, 401, `usage-trigger` 403, colas, sellos, secretos, `publication-check`, entorno igual al respaldo) → `integrations-doctor` → la primera instantánea, **solo con 0 desvíos** → ERROR nuevos en el log, descontando como mucho una excepción 55P03 por reintento. Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado.
- **`integrations-doctor`:** el script lo llama dos veces. La primera exige Twilio en `connected_otp_only`, la voz de Twilio en `not_configured` y ElevenLabs en `ELEVENLABS_NOT_CONFIGURED`. La segunda acepta Meta en `connected_read` o en `degraded`; `degraded` es un dato (un dato viejo, una pasada fallida o la sincronización apagada), no un fallo del despliegue. *(El contrato, «Fase 1» paso 7, lo escribe como una sola llamada con las cuatro expectativas.)*
- **Un tercer argumento que no sea exactamente `--solo-comprobar`** sale con 2 sin tocar nada.
- **Con `ABORTA` porque `www-data` no puede leer algún fichero del `merge`:** el código nuevo ya está en el disco, sin variables ni migraciones. Se corrigen los permisos como dice el mensaje (644 los ficheros y 755 los directorios de `git diff --name-only 7ba0ce8 HEAD`) y se relanza el mismo comando, que reanuda.
- **Con `ABORTA` en el paso 3 (55P03 agotado tras 3 intentos):** no hay `route:cache`, así que las 18 rutas no se sirven y nadie usa las tablas. Si cayó la 100002, las 3 tablas quedan **sin guardas** hasta el reintento. Se mira quién tiene cerrojos con `h6-schema-check.sql` y **se relanza el mismo comando**, que reanuda (HEAD ya en `<commit-backend>`, sin `merge` ni claves duplicadas).
- **Con 42501 al migrar:** la función, el libro o versiones no son de `iron`. El script aborta sin reintentar y sin `route:cache`. Parar y avisar a `database-specialist`. **No forzar.**
- **Con `FASE 1 (H6) CON N DESVÍOS`** (código 1): no se sigue con el CRM ni con el smoke hasta aclararlos. La primera instantánea no se toma con desvíos: la toma la siguiente ejecución, que reanuda.
- **Reanudación tardía.** Si la fase 1 se corta y se relanza más de 2 horas después del respaldo, el paso 0 aborta (falla cerrado) y **hay que rehacer el respaldo** con el bloque del paso 1, pero con `api-commit.txt` igual a `PREVIO`, no al HEAD de ese momento: `git -C /var/www/api rev-parse 7ba0ce8 > "$BK/api-commit.txt"`. **Corregido (m-6 del reviewer, 02-11-2026):** el paso 0 exige siempre que `api-commit.txt` empiece por `PREVIO`, también en una reanudación (como el H5), y el mensaje de abortar dice cómo escribirlo.

**6. Esquema después** (solo lectura): el mismo `h6-schema-check.sql` del paso 3. Esperado: `disparadores_studio` 43 y `check_studio` 49; disparadores `uscl` 5, `uscn` 5 y `usis` 3; CHECK `uscl` 4, `uscn` 12 y `usis` 6; `parciales_h6` 2 y `parciales_studio` 4; `check_7_sujetos` t; la misma `huella_funcion` (`4ad748b5…`); `tablas_studio` 14 y `tablas_h6` 3; `eventos_sujetos_nuevos` 0; de `iron_app` en las tres tablas, SELECT, INSERT y UPDATE t, TRUNCATE f y secuencia t (DELETE sale t y es informativo: lo paran los disparadores); y, en las instantáneas, `meta_ads`, `twilio` y `voice` con una fila `config`/`system` cada uno.

**7. CRM** (desde el Mac)

```bash
curl -fsS https://ironbodyneiva.cloud/version.json                          # <crm-prod> (esperado ab78a6a)
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body_Front   # en <build-crm>, limpio
npm run build:prod                                                          # version.json: commit <build-crm>, dirty false
/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body/backend/docs/ultron-studio/ops/deploy-crm.sh <build-crm> <crm-prod> /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body_Front
```

**El tercer argumento es obligatorio:** sin él, `deploy-crm.sh` toma el build de `_worktrees/thermal-receipts/Iron-Body_Front`, el worktree que se usó en el Hito 5. Esperado: **`CRM PUBLICADO Y VERIFICADO`**; con cualquier fallo de verificación, `CRM PUBLICADO CON N FALLOS DE VERIFICACIÓN` y código 1 (el CRM ya está publicado: se revisa antes de seguir). **Anotar** la línea `RESPALDO: /var/backups/ironbody/crm-pre-<build-crm>-<ts>`.

**8. Smoke** (escribe en la fila 32 de `admins` y en `admin_sessions`), copiando antes `smoke-h6.py` y `smoke-run-h6.sh` a `/root/` (paso 2):

```bash
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h6.sh'
```

Esperado:
- `OK      configuración efectiva de la fase 1: {"writes_enabled":false,"probe_enabled":false}` y `OK      14 tablas ultron_studio_* en la base`;
- la parte del Super Admin, unas **78 OK** con 0 FALLO y 0 OMITIDO (77 si Meta sale `degraded`), y la del Administrador (la misma cuenta con el rol cambiado), **26 OK**;
- en la base, el recuento exacto de las 14 tablas igual antes y después (un cambio en una tabla del Hito 6 es FALLO) y ninguna fila nueva que nombre a la cuenta;
- la cuenta en reposo (inactiva, Super Admin, sin sesiones) y los tokens muertos;
- código de salida 0.

Lo que comprueba la fase 1 (contrato, «Fase 1» paso 7, y la cabecera de `smoke-h6.py`): las 56 rutas; Twilio con `aliasesConsistent = {sid: true, token: true}` y `calls.total = 0`; las escrituras 47–54 en 409 `integrations_writes_disabled` y la sonda en 409 `probe_disabled`; los canarios vacíos con `engine_canary_not_registered`; las campañas con su antigüedad; la voz en `ELEVENLABS_NOT_CONFIGURED` con `policy.allowed = false`; la configuración en `never_authorized` con su `observed`; y un detector de fugas (claves prohibidas, formatos de SID, tokens y claves, teléfonos, ids de 10 cifras, la conversación canaria y las huellas saladas de los secretos del servidor). **Sin filas positivas:** solo envía lo que el servidor rechaza antes de escribir, y la ruta 55 (reconocer) no la llama nunca el Super Admin.

Códigos: 0 todo OK · 1 algún FALLO · 3 algo OMITIDO · 2 uso incorrecto. Ensayado: 78 + 26 en el banco local y contra el arnés E2E (`f549c50`), con las 14 tablas idénticas y 0 peticiones a los simuladores.

> **H-3 de DevOps: como mucho 3 smokes por hora** con la cuenta 32. El cupo de la sonda (6/h por persona) corre antes de autenticar, y cada smoke gasta 2 (Super Admin y Administrador): una cuarta ejecución en la misma hora da 429 en la sonda, que cuenta como FALLO. Además, 3 por minuto, por el login (8/min por IP). Las llamadas denegadas desde la oficina comparten también el cupo por red (20/h).

**9. Controles de no regresión** (solo lectura): la sonda del contrato n8n↔backend sin efectos, como en el Hito 5; `SELECT max(started_at) FROM meta_sync_runs;` posterior al despliegue en la hora siguiente (la sincronización de Meta sigue sumando pasadas); y `usage-trigger` en 403 sin firma (ya lo comprueba el script). **No se lanza un OTP de prueba.**

**10. Cierre de la fase 1:** registrar en `ULTRON_STUDIO_MASTER_ROADMAP.md`, `ULTRON_STUDIO_FINAL_REPORT.md` y `ULTRON_STUDIO_ROLLBACK.md` la fecha UTC, los commits, `$BK` y el respaldo del CRM, la salida del script y la del smoke, y el estado «Hito 6 desplegado en la fase 1 (escrituras y sonda apagadas)».

### Fase 1: ejecución (02-11-2026, 04:37–04:57 UTC; lead, con la autorización del 25-10-2026 y el propietario presente)

**Resultado: `FASE 1 (H6) COMPLETADA`.** Backend `origin/main` = `1e5f9a0` (código `4e2e8cf`; el push de avance rápido lo bloqueó dos veces la protección de secretos de GitHub por los dos SID sintéticos de Twilio de las pruebas y del oráculo, `AC0123456789abcdef…`, y el propietario los permitió como «se utiliza en pruebas» desde la cuenta que empuja); CRM `origin/main` = `16c433c`. Registros en el servidor: `/root/h6-fase1-20261102-*.log` y `/root/h6-smoke-fase1-20261102-*.log`.

1. **Respaldo** `/var/backups/ironbody/ultron-studio-h6-20261102-043714` (04:37 UTC): 223 tablas con datos (el umbral), 700/600 de root, `api-commit.txt` = `7ba0ce8`, volcado de 38 MB con su `.sha256`, `app-crm.tgz`, `env.backup`, cachés y `supervisor-conf.d`.
2. **Scripts** copiados a `/root/` con las cinco huellas iguales en el Mac y en el servidor.
3. **Esquema antes:** 30/27, 0 objetos del Hito 6, 2 parciales, `check_7_sujetos` f, función de F (`4ad748b5…`), dueños `iron`, 11 tablas, libro 27 eventos / 0 de sujetos nuevos, 0 cerrojos.
4. **`--solo-comprobar`:** `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`; motor, H4 y H5 informativos: `engine_scope off`, `publishing_enabled false`, `lab_gate required`, `lab_driver openai`, `canary_set true`, `ultron_enabled true`.
5. **Backend** (04:52–04:54 UTC): HEAD `1e5f9a0`, los 183 ficheros del merge legibles por `www-data`; las 8 variables añadidas, fichero de entorno `www-data 600` y parsea; migraciones `100001` en 79,50 ms y `100002` en 43,85 ms al primer intento (sin 55P03); esquema 43 disparadores · 49 CHECK · 4 parciales · 14 tablas; compuerta del Hito 6 `5 5 3 4 12 6 2 true iron,iron,iron 0 0 0 0` y privilegios de `iron_app` como se esperaba; cachés `www-data 600`; rutas 56 · 18 · 8 · 10 y `auth:coverage --unmapped` vacío; configuración efectiva exacta (`writes_enabled false`, `probe_enabled false`, `probe_cooldown_s 300`, `probe_credential otp`, topes 3/10/50, prefijo 57, alias de Twilio coherentes, `elevenlabs_enabled false`, voz `none`/`ELEVENLABS_NOT_CONFIGURED`), motor/H4/H5 iguales; workers `[13 0]` y `[13 0]`, 13 de 13 con pid nuevo; 401 en las cuatro lecturas y el POST sin token; `usage-trigger` sin firma 403; colas fallidas 2 → 2; 0 sellos; secretos OK; `publication-check` 0; entorno igual al respaldo salvo las 8 claves; `integrations-doctor` `meta_ads connected_read · twilio connected_otp_only · twilio_voice not_configured · canaries registry · elevenlabs ELEVENLABS_NOT_CONFIGURED` (códigos 0 y 0); instantáneas 0 → 3 (`meta_ads`, `twilio`, `voice`, `config`/`system`, 04:54:00 UTC); 0 ERROR nuevos.
6. **Esquema después:** 43/49; `uscl` 5/4, `uscn` 5/12, `usis` 3/6; parciales 2 (H6) y 4 (Studio); `check_7_sujetos` t; la misma huella de la función; 14 tablas (3 del H6); libro 27/0; `iron_app` en las tres tablas SELECT/INSERT/UPDATE t, TRUNCATE f, secuencia t (DELETE t, informativo); 3 instantáneas de sistema.
7. **CRM** (04:55 UTC): build de producción sobre `16c433c` (`dirty: false`, 29 avisos de presupuesto, ninguno nuevo); `deploy-crm.sh 16c433c ab78a6a <worktree del H6>` → `CRM PUBLICADO Y VERIFICADO` (`main-3AFEFR5U.js`, el anterior `main-ESWP7UG3.js` sigue servido, `/marketing/ultron-studio` 200); respaldo `/var/backups/ironbody/crm-pre-16c433c-20261102-045554`.
8. **Smoke** (04:56–04:57 UTC, `SMOKE_PHASE=1`): Super Admin **78 OK · 0 FALLO · 0 OMITIDO**, Administrador **26 OK · 0 FALLO · 0 OMITIDO**; configuración efectiva de la fase 1 y 14 tablas; recuentos iguales antes y después; Meta con la última pasada correcta hace 41 min (las 17 campañas con estado de hace 41 min); gasto estimado del OTP hoy 0 de 12 US$; configuración `never_authorized` en los tres proveedores; H5 `envScope=off`; limitador mínimo restante 5 y 4; la cuenta 32 en reposo (2 sesiones borradas, token → 403); código de salida 0.
9. **Controles de no regresión:** sonda del contrato n8n↔backend sin efectos → 422 de validación pura (sin `unexpected_fields`, sin errores sobre `human_handoff_*`), filas con `source_type` 250 → 250; `usage-trigger` 403 (paso 9 del script); `meta_sync_runs` posterior al despliegue en la verificación de los 15 minutos.
10. **A los 15 minutos:** **Hecha (lead, 02-11-2026 05:08 UTC, solo lectura):** HEAD `1e5f9a0`; 13 RUNNING; `failed_jobs` 2 (igual); `integrations-doctor` 0 (Twilio `connected_otp_only`, voz de Twilio `not_configured`, ElevenLabs `ELEVENLABS_NOT_CONFIGURED`) y Meta `connected_read`; `voice:doctor` `ELEVENLABS_NOT_CONFIGURED`; `publication-check` 0; colas al día; `security:check-secret-exposure` OK; ERROR del log 345 (igual); 0 respuestas 5xx en `integrations/*` (18 × 200, 21 × 401, 5 × 403 y 16 × 409, todas del script y del smoke); tablas del Hito 6 0 | 0 | 3; 0 sellos. Los avisos `upstream` del `error.log` de nginx (3 desde las 04:50) son tiempos de espera de `GET /mcp-server/http` en `n8n.ironbodyneiva.cloud`, los mismos que se ven durante todo el día (7 a las 02 h, 21 a las 03 h, antes del despliegue) y ajenos a la API y al CRM. La última pasada de `meta_sync_runs` es de las 04:15:07 UTC (`ok`, 3 cuentas, anterior al despliegue); la siguiente se comprueba en la verificación de las 24 horas. **La fase 1 es estable a los 15 minutos.**

**Estado del hito:** `HITO_6_DEPLOYED_PHASE_1` (escrituras, sonda y voz apagadas; el motor no lee nada nuevo). La fase 2 (`writes on`, T1 con dos cuentas, `probe on`, reconocimiento de la configuración) sigue en su sección, con la verificación de las 24 horas y la autorización del propietario con dos personas. Antes de la fase 2, el propietario pone `log_error_verbosity = terse` en PostgreSQL (H-2 del tester: con el valor por defecto, un 23505 deja la huella del teléfono en el log del servidor).

### Después de la fase 1: a los 15 minutos y a las 24 horas (solo lectura)

```bash
cd /var/www/api/backend
supervisorctl status                                                           # 13 RUNNING; tiempo de actividad ≈ desde el queue:restart; ningún BACKOFF/FATAL
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"                             # igual que en el paso 0
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED   # código 0
sudo -u www-data php artisan voice:doctor                                      # Estado: ELEVENLABS_NOT_CONFIGURED
sudo -u www-data php artisan ultron-studio:publication-check                   # código 0
sudo -u www-data php artisan queue:health                                      # sin incidentes
sudo -u www-data php artisan security:check-secret-exposure                    # en verde
grep -c 'production.ERROR' storage/logs/laravel.log                            # frente al valor del paso 0 del script
grep ' /api/admin/ultron-studio/integrations' /var/log/nginx/access.log | awk '{print $9}' | sort | uniq -c   # si el formato es el combined: ningún 5xx
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT (SELECT count(*) FROM ultron_studio_campaign_links), (SELECT count(*) FROM ultron_studio_canary_numbers), (SELECT count(*) FROM ultron_studio_integration_snapshots);"   # 0|0|3
```

- **Si el log es diario** (`laravel-AAAA-MM-DD.log`), el fichero del día.
- **Meta:** `integrations-doctor --expect=meta_ads:connected_read`; si sale `degraded`, se anota el motivo (dato viejo o pasada fallida), no es un fallo.
- **A las 24 horas:** lo mismo y, si se quiere, otra pasada de `SMOKE_PHASE=1` (copiando antes los dos ficheros; H-3). Es la base para decidir la fase 2.

### Fase 2: escrituras encendidas, T1, sonda y configuración autorizada

**Antes:**
- [ ] Fase 1 estable 24 horas: 0 ERROR nuevos del Studio, 0 respuestas 5xx en `integrations/*`, workers estables y colas fallidas sin crecer.
- [ ] **Dos personas reales** disponibles: A, que tiene el teléfono de la conversación canaria, y B, Super Admin con `ultron.canary_authorize`, distinta de A. **Dos personas, no dos cuentas** (`security` §3 #13): ninguna de las dos cuentas creada o elevada por la otra en los días previos. La cuenta temporal del smoke **nunca** sirve para el T1 real.
- [ ] **Aislamiento del rol de la aplicación** (O-2 del tester, Enmienda 4): con `default_transaction_isolation = repeatable read` en `iron_app`, 7 de las 37 carreras del tester responderían 500 (40001); el rol tiene que estar en READ COMMITTED. [PENDIENTE: comprobación de solo lectura por `devops` en producción, con su resultado anotado aquí.]
- [ ] **El FPM ve la caché nueva sin recargarse** (N-4 a de `security`): `h6-integrations-scope.sh` no recarga FPM y da el mando por aplicado con la lectura de tinker, que es CLI. [PENDIENTE: comprobar una vez `opcache.validate_timestamps` del pool de FPM y anotarlo aquí (`devops`).] Lo que sí ve el proceso web lo comprueba el smoke de la fase 2.
- [ ] La «base oficial» estricta de N-3 (D-18) está en el código desplegado (`3e1ee20`) **antes de reconocer Twilio**: `https`, host exacto, puerto 443 y sin credenciales en la URL. Con las bases por defecto, la huella de producción no cambia.

**Pasos, en este orden** (H-1 de DevOps: la huella de la configuración de Twilio incluye `facts.probe.enabled`, así que **reconocer va después de `probe on`**; con el orden del contrato, Twilio quedaría en `drift` y el criterio de cierre exige los 3 proveedores en `authorized`):

1. `bash /root/h6-integrations-scope.sh writes on off` (servidor, root). Esperado: `MANDO writes → on APLICADO (copia previa: /root/env-pre-h6-writes-on-<TS>)`. El script exige el fichero de entorno en 600 (N-4 b; si no, aborta sin tocar nada) y que el estado actual coincida con la configuración efectiva; deja una copia previa 600; reescribe la llave en el mismo fichero (conserva dueño y modo); comprueba que parsea; regenera la caché como `www-data` con `umask 077` y 600; comprueba que la configuración efectiva es la de antes con solo esa llave cambiada y que el motor no cambió; `queue:restart`; 13/0 workers dos veces; `integrations/status` sin token → 401; y una instantánea de sistema si alguna huella cambió (`writes` no cambia ninguna). Con algún desvío, `ESCRITO CON N DESVÍOS` y código 1, con la orden exacta para volver.
2. `ssh ironbody-vps 'SMOKE_PHASE=2 bash /root/smoke-run-h6.sh'` (copiando antes los dos ficheros). Esperado: código 0, **73 + 26 OK** en el banco y en el arnés. Comprueba desde el proceso web que `flags.writesEnabled` es `true` y sigue sin escribir: recursos inventados (404) o cuerpos inválidos (422).
3. **T1 con personas reales** (CRM, pestaña «Números canarios»):
   1. A registra el número de WhatsApp de la conversación canaria (canal `whatsapp`, un alias por función y no el nombre de una persona, y la casilla de consentimiento);
   2. B lo autoriza (`ultron.canary_authorize`, ≠ A);
   3. B lo activa (`ultron.canary_manage`, ≠ A) → el banner de coherencia dice **`in_sync`**.

   Evidencia sin datos personales:

   ```sql
   SELECT channel, status, registered_by_admin_id <> authorized_by_admin_id AS autoriza_otra, activated_by_admin_id <> registered_by_admin_id AS activa_otra
     FROM ultron_studio_canary_numbers WHERE status <> 'revoked';
   SELECT event, actor_admin_id, created_at FROM ultron_studio_audit_events WHERE subject_type = 'canary_number' ORDER BY id;
   ```

   Esperado: `whatsapp`, `active`, t, t; y `canary_number.registered`, `.authorized` y `.activated` con dos identidades distintas. **El entorno no se toca:** ULTRON sigue atendiendo solo la conversación canaria configurada en el servidor.
4. `bash /root/h6-integrations-scope.sh probe on off`. Esperado: `MANDO probe → on APLICADO (…)`, con una instantánea de sistema nueva de Twilio (la huella cambió). Antes de escribir, el script comprueba que el entorno efectivo de la aplicación es `production` (AS-5, `6241758`): fuera de `production` la sonda no fija los hosts oficiales, y no se enciende. Imprime la credencial que usará: la del OTP mientras el propietario no cree la API key restringida.
5. **Una** pulsación de la sonda desde el CRM (pestaña «Twilio»; el botón solo aparece con `can.integrationsManage` y `flags.twilioProbeEnabled`, es decir, para el Super Admin). Su instantánea, con cualquier `outcome`, es la evidencia: el estado de la cuenta y la respuesta a «¿hay número de voz o solo caller ID?» (`numbers.voiceCapable` y `callerIds.count`). `SELECT outcome, created_at FROM ultron_studio_integration_snapshots WHERE kind = 'probe' ORDER BY id DESC LIMIT 1;`. Una segunda pulsación en menos de 300 s devuelve la misma instantánea (`reused`), sin red. Si el propietario crea una API key restringida sin lectura de Monitor, el paso `alerts` sale `auth_failed` y la sonda, `degraded`, sin que sea un fallo (`informe-twilio.md`, «Datos y dudas», punto 5).
6. **Obligatorio después del paso 4:** un Super Admin reconoce `meta_ads`, `twilio` y `voice` (pestaña «Twilio», «Configuración autorizada») → los tres en `authorized`. Comprobación:

   ```sql
   SELECT provider, count(*) FROM ultron_studio_integration_snapshots WHERE kind = 'config' AND actor_type = 'admin' GROUP BY provider;
   ```

   y `GET integrations/config` sin `drift`. Si alguien reconoció antes, **se vuelve a reconocer Twilio** después de `probe on`. Si la sonda se apaga más tarde (`probe off on`), Twilio vuelve a `drift` hasta que se reconozca de nuevo. Un 409 `config_changed` al reconocer significa que la configuración cambió entre leerla y pulsar: se recarga y se vuelve a reconocer lo que se ve.
7. `SMOKE_PHASE=2` otra vez, con la sonda encendida → código 0. Ese smoke **no** pulsa la sonda: le manda un cuerpo inválido (422). H-3: como mucho 3 smokes por hora, contando los de la fase 1.

- **Asociar campañas en producción no es condición** (el E2E prueba el flujo). Si se asocia alguna, queda en el libro y no cambia nada en Meta ni en el motor.
- **La bandera de la sonda puede quedarse encendida:** son GET sin coste, con enfriamiento de 300 s y cupo de 6 por hora y persona y 20 por hora y red.
- **Las copias del fichero de entorno** (`/root/env-pre-h6-*`, root, 600) llevan secretos: quitarlas lo decide el propietario.

**Criterio de cierre** (contrato, «Criterio de cierre»): **`HITO_6_COMPLETED`**, con la evidencia en los informes, cuando las fases 1 y 2 estén ejecutadas; el T1, en `in_sync` en producción con dos identidades en el libro; la configuración de los 3 proveedores, reconocida; una sonda ejecutada con su instantánea; el panel en vivo, igual que la clasificación al congelar (Meta `connected_read`, Twilio `connected_otp_only`, Twilio Voice `not_configured`, canarios `registry` y ElevenLabs `ELEVENLABS_NOT_CONFIGURED`) o con la diferencia explicada; y ElevenLabs, todavía en **`ELEVENLABS_NOT_CONFIGURED`**, con el adaptador, el `doctor`, la firma, la política y el procedimiento de activación entregados (`ULTRON_STUDIO_ELEVENLABS_READY.md`). **`HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`** si falta la segunda persona para el T1 o el despliegue previo del Hito 5.

### Incidencias previsibles durante el despliegue

Del informe de DevOps (§6) y del de datos (§9); ninguna se ha visto en producción.

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `ABORTA` en el paso 0 del script | Algo de partida no es lo esperado (HEAD, checkout, `origin/main`, migraciones, puerta de diff, respaldo, claves, esquema, cerrojos, rutas o workers) | No se ha tocado nada. Aclarar la causa y repetir |
| 55P03 en la migración | Alguien tenía el libro, versiones o una tabla nueva, aunque solo fuera leyendo: la 100002 pide ACCESS EXCLUSIVE | El script reintenta hasta 3 veces. Si se agota, no hay `route:cache`: las 3 tablas pueden quedar sin guardas, pero nadie las usa. `h6-schema-check.sql` para ver quién tiene cerrojos y **relanzar el mismo comando** (reanuda) hasta 43/49 |
| 42501 al migrar | La función, el libro o versiones no son de `iron` | Parar y avisar a `database-specialist`. Ensayado en el banco: aborta sin reintentar y sin `route:cache`. **No forzar** |
| Workers en BACKOFF tras `queue:restart` | La caché no es de `www-data` o no se puede leer | `stat -c '%U %a' bootstrap/cache/*.php`; `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'` y `chmod 600`. `supervisorctl restart` **solo con autorización**: reinicia servicios productivos |
| `el fichero de entorno NO parsea` | Una línea mal formada | Restaurarlo desde `$BK/env.backup` (o desde la copia `/root/env-pre-h6-*` que deja el mando de alcance), con el mismo dueño y modo 600, antes de seguir. Solo se imprime la clase del error |
| Meta `degraded` | Dato viejo (más de 180 min sin pasada con éxito), una pasada fallida o la sincronización apagada | Es un dato, no un fallo. Anotarlo |
| El doctor no da Twilio, la voz o ElevenLabs esperados | La configuración no es la del congelado (por ejemplo, una pieza de ElevenLabs en el entorno) | Desvío: revisar la configuración sin imprimir valores. ElevenLabs no debe tener piezas |
| El smoke da 429 | Más de 3 ejecuciones por hora (cupo de la sonda) o por minuto (login) | Esperar. La cuenta ya está en reposo |
| El smoke dice «no se pudo activar la cuenta» | Otra fila de `admins` tiene el correo del smoke | Falla sin efectos. Aclararlo antes de repetir |
| Veredicto `engine_canary_phone_unusable` | El lead de la conversación canaria no tiene teléfono utilizable | Dato de producción que hay que aclarar antes del T1 |
| Smoke de la fase 2 con FALLO por banderas (el CLI dice `writes_enabled: true` y el proceso web, `flags.writesEnabled: false`) | El proceso web no ve la caché nueva | En el arnés pasó con `artisan serve --no-reload`, que fija el entorno al arrancar (DevOps §8). En producción, ver la condición de `opcache.validate_timestamps` |

**Rotación de la `APP_KEY`:** no forma parte de ninguna fase. Con el Hito 6 desplegado, deja inservibles también las huellas de los teléfonos canarios, los cifrados de voz, los ids opacos de Meta y las huellas de configuración, y pierde el T1. El procedimiento ampliado está en `ULTRON_STUDIO_ROLLBACK.md` («Hito 6»).

## Hito 7 (seguridad y producción): runbook del backend — NO EJECUTADO (02-11-2026)

**Estado (03-11-2026): EN PRODUCCIÓN.** Commit final del hito `d688418` (empujado a `origin/main` el 02-11-2026 18:30 UTC); producción en `cfcd0d1` desde el 03-11-2026 18:29 UTC por un despliegue manual ajeno, verificado y regularizado por el lead a las 20:03 UTC (ver «Hito 7: ejecución real», más abajo). Confirmados: DB7 `bbf9916`; B7 `aa3451f`; SEC7 `bd4634d` y la corrección de M-1 y m-3 de REV7 `601398c`; OPS7 `fa6eb81` y sus correcciones `1c76a7f` (`PREVIO=1e5f9a0` y `api-commit.txt` siempre igual a `PREVIO`), `0d43401` (presupuestos en el smoke, mando del monitor y sección «Supresión»), `7c838ca` (m-1 de REV7: `schedule:list` con su código de salida y `TAREAS`) y `2139e00` (calentamiento de la evidencia); PERF7 `d629202`; F-1 y F-2 `e2411e3`; y TEST7, fases A (`1426130`), B (`df0140f`) y C (`cdade1e`). REV7, primera pasada (`3fbb155`): **APROBADO CON OBSERVACIONES**, sin bloqueantes; M-1, m-1, m-2 y m-3, corregidos. En curso: la fase D de TEST7, sobre el HEAD final con M-1. Esta sección sale de los scripts de OPS7 (`ops/deploy-h7-backend.sh`, `ops/h7-schema-check.sql`, `ops/smoke-run-h7.sh`, `ops/smoke-h7.py` y `ops/h7-monitor-scope.sh`, en su versión de `cdade1e`), del runbook de `plans/informes-h7/informe-devops-h7.md` §3–§5, §10 y §11 y de los recuentos de `informe-db.md` §4. **Donde no coinciden, mandan los scripts**, en la versión confirmada en el commit que se despliega. En el banco local (`ops/banco-h7/`) terminó en `DESPLIEGUE H7 (BACKEND) COMPLETADO` dos veces (50 s y 47 s) con un 55P03 real, el smoke dio 22 OK (23 con una entrada en la cadena) y la vuelta atrás por niveles, 13/13. **En el servidor, el `--solo-comprobar d688418` salió `EN VERDE` el 02-11-2026 18:45 UTC; el despliegue llegó por otra vía el 03-11-2026 (ver «Hito 7: ejecución real»).** Vuelta atrás: `ULTRON_STUDIO_ROLLBACK.md`, «Hito 7». Lista resumida: `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md` §3.2.

**Producción de partida** (la que dejó la fase 1 del Hito 6, 02-11-2026 04:37–04:57 UTC): API `1e5f9a0`, CRM `16c433c`, 56 rutas del Studio (18 de integraciones: 8 GET y 10 POST), esquema `43 49 4 14` (disparadores, CHECK, índices parciales y tablas `ultron_studio_*`), 13 workers RUNNING, `failed_jobs` 2, 0 sellos, 0 publicaciones, 3 instantáneas de sistema y 345 ERROR en el log (verificación de los 15 minutos del H6, 05:08 UTC).

**Qué es este hito en producción.** Endurece lo que ya está; no añade rutas ni cambia el CRM, las banderas del H4, del H5 ni del H6, ni lo que ULTRON dice o hace.

| Pieza | Qué cambia | Quién lo nota |
|---|---|---|
| Migración `2026_11_02_100001_add_ultron_studio_hardening` (DB7) | En la cadena de publicaciones, el disparador `uspub_chain_next` (solo admite la entrada siguiente a la cabeza y encadenada a ella) y `uspub_seq_chk` (`CHECK (seq >= 1)`); `REVOKE UPDATE, DELETE` de `iron_app` en la cadena, el libro y las instantáneas (de `arwd` a `ar`). PostgreSQL: 43 → 44 disparadores y 49 → 50 CHECK. `up()` se niega si la cadena existente no es consecutiva | Nadie: el código nunca hace UPDATE ni DELETE en esas tablas |
| `Cache-Control: no-store, private` en toda `/api/admin/*` (B7-a) | Antes, `no-cache, private` (y `public` en la exportación XLSX) | Nadie: el navegador deja de guardar copias |
| Tope de 64 KB del Studio antes de `TrimStrings` (B7-d) | 413 `payload_too_large` también sin sesión, sin recorrer el cuerpo | Nadie con uso normal |
| `verified` por entrada en `GET publications` y en el detalle de la versión (B7-c) | Clave aditiva al final de cada entrada | El CRM `16c433c` no la lee |
| Caracteres invisibles (SEC7-a, con la corrección de M-1 de REV7) | **En el Studio**, `PlainText` rechaza con 422 la clase completa de invisibles (un emoji compuesto con ZWJ pasa), también al volver a guardar el borrador o al crear una versión desde un texto guardado antes del H7; las versiones ya creadas no se bloquean, porque enviar, aprobar, promover y publicar no revalidan el contenido (`informe-security.md` §7.3). **Fuera del Studio (agenda de valoraciones, Caja) no cambia nada.** La API interna de conocimiento (n8n) los limpia y lo registra (`marketing.knowledge.invisibles_cleaned`), nunca 500. *Es la corrección de M-1 de REV7 (opción b), en `601398c`:* `PlainText` vuelve por defecto a la lista de producción (rechaza 80 puntos de código en una línea y 77 en varias, con el mismo mensaje de siempre, «El texto contiene caracteres no permitidos.»), y la clase completa solo se aplica con `strict: true`, en los 19 sitios del Studio (8 controladores y el normalizador del borrador); los 15 de fuera quedan como en `1e5f9a0`. `PlainTextOutsideStudioTest`, 42 pruebas; la lectura de REV7 en producción, hecha por el lead con escapes: **0 filas** afectadas (`informe-security.md` §7) | El personal del Studio, si pega un texto con un invisible: el CRM enseña el mensaje del servidor junto al campo (`fieldErrors`; leído por REV7) |
| Topes de listas antes que sus elementos (B7-e, M-2) | 8 rutas fuera del Studio (9 contando `bundles` y `preview`) | Nadie con uso normal: cada tope es ≥ el máximo legítimo |
| Restablecer la contraseña de un administrador (H-2 de SEC7, en B7) | Cierra sus demás sesiones, también los «recuérdame» | La cuenta afectada vuelve al login (403 `admin_token_invalid`) |
| `ultron-studio:monitor` (OPS7) | El comando existe; con la bandera apagada **no se programa nada** | Nadie hasta que se encienda (paso 10) |

**Qué NO toca:** el motor (la puerta de diff del paso 0), las banderas del H4, del H5 y del H6, las rutas (siguen 56), el CRM, FPM, supervisor, el crontab, DNS, TLS y el firewall. **Tampoco instala `ironbody-migrate` sin root:** la migración la ejecuta el envoltorio de hoy (como root) y la propuesta (`ops/ironbody-migrate-propuesta.sh`) la instala el propietario aparte (§«Pendientes del propietario» de la hoja de ruta).

**Variables:** una, `ULTRON_STUDIO_MONITOR_ENABLED=false`, explícita aunque coincida con el defecto y escrita **antes** de migrar. `ULTRON_STUDIO_MONITOR_EXPECT` no se escribe: rige la del código (`twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED`). El paso 0 la lee con Dotenv, sin imprimir el valor: ausente, vacía o escrita con la misma expectativa del código, no avisa (como mucho, una línea informativa: «presente y vacía»); con otro valor, «AVISO: … tiene valor», y el paso 7 lo contará como desvío. Hasta `0d43401` avisaba también con la clave vacía. Si `ULTRON_STUDIO_MONITOR_ENABLED` ya existe con otro valor o repetida, el paso 0 aborta. Solo `true`, `1`, `on` o `yes` encienden el monitor.

**Orden y compatibilidad:**
- **Solo backend.** El H7 no tiene código del CRM: no se compila ni se publica nada (`origin/main` del CRM es `16c433c`, lo que sirve producción).
- **Sin rutas nuevas:** el código nuevo sirve desde el `merge`; lo que falta hasta la migración es la guarda de la base, no una ruta.
- **No usar el Studio durante el despliegue:** la migración pide ACCESS EXCLUSIVE sobre la cadena, y una lectura dentro de una transacción abierta la hace caer por 55P03 (DB7 lo midió a los 5,58 s). El motor lee la cadena solo con su llave distinta de `off` (hoy `off`).
- **Socios, caja y ULTRON no notan nada**, salvo quien reciba un restablecimiento de contraseña en ese momento.

### Condiciones previas

- [x] **El Hito 6 en su fase 1, verificado a las 24 horas.** **Hecho de forma equivalente (lead, 03-11-2026 20:00 UTC, a las 39 horas; la secuencia programada para las 05:00 UTC no corrió porque el Mac del lead se durmió):** 13 workers RUNNING, `failed_jobs` 2 (igual), tablas del H6 `0 | 0 | 3`, `integrations-doctor` en orden (según `ultron-studio:monitor --dry-run`), 0 sellos, y los únicos ERROR nuevos del registro son 45 `42P01` sobre `receivable_payment_splits` entre las 16:51 y las 16:55 UTC, de un despliegue ajeno (ver «Hito 7: ejecución real»). El smoke de la fase 1 del H6 repetido ese día: 78 + 26 OK, 0 FALLO.
- [ ] **REV7 sin bloqueantes** (`plans/informes-h7/informe-reviewer.md`). **Primera pasada (`3fbb155`): APROBADO CON OBSERVACIONES**, sin bloqueantes. Las cuatro observaciones, corregidas: **M-1** (mayor: `PlainText` cambiaba también fuera del Studio) con la opción (b) en `601398c` (`security`); **m-1** («0 tareas del Studio» pasaba también si `schedule:list` fallaba) en `7c838ca` (`devops`: su código de salida aparte y `TAREAS`); **m-2** (documentos desfasados) en las pasadas segunda y tercera de DOC7; y **m-3** (la lista blanca de aislamiento admitía `routes/console.php` entero) en `601398c` (`security`, por encargo del lead; el reviewer la había asignado a `tester`). **Segunda pasada (`4166d94`, §9, sobre `3111d29`): APROBADO CON OBSERVACIONES, sin bloqueantes ni mayores abiertos**; M-1, m-1, m-3 y N-10, cerrados; dos menores nuevos: **m-4** (`devops`/propietario: los mandos exigen el fichero de entorno en 600 y la propuesta de `ironbody-migrate` lo deja en 640; paso 10) y **m-5** (Dotenv en el paso 0; **corregido por el lead en `285a585`**, paso 5). Pone dos condiciones antes del paso 5: la fase D de TEST7 en verde y esta tercera pasada de DOC7 (§9.6), que revisará cuando se confirme. [PENDIENTE: la revisión de la tercera pasada de DOC7 (`reviewer`).]
- [x] **TEST7 completo:** fase A hecha (`1426130`: 53/53 mutantes, Legacy 5 011/5 011 con la llave en `off` y en `all`, puerta PostgreSQL 2 799/2 799); **fase B hecha** (`df0140f`): la suite por nombre contra `1e5f9a0` con `ops/suite-compare.sh`, 57 = 57 rojos y **0 nuevos**; el CRM `16c433c`, 3 312/3 328 con solo los 16 de la línea base; el E2E de todas las specs, **660/660** (sobre `5ed9525`); y la recuperación con OPS7, 21/21, con la vuelta atrás 13/13; **fase C hecha** (`cdade1e`, `informe-tester.md` §3bis, sobre el código de `e2411e3`, con F-1 y F-2): la puerta PostgreSQL, **2 881/2 881**; el motor con la llave en `off` y en `all`, **5 015/5 015**; la suite por nombre contra `1e5f9a0`, 57 = 57 rojos y **0 nuevos**, con 195 pruebas nuevas; y el E2E, **660/660** (backend `0d43401`). **Fase D hecha (`6399d9e`, `informe-tester.md` §3ter; sobre el HEAD final con M-1, `601398c`):** puerta PostgreSQL 3 854 pruebas con 2 rojos del entorno (`SecretExposureTest`, la instantánea no es un repo git, y `ValuationOverlapGuardMigrationTest`, que exige un rol sin superusuario; los dos fallan igual sobre `1e5f9a0`); comparación por nombre contra `1e5f9a0` 57 = 57 rojos y **0 nuevos** (239 pruebas nuevas, 44 de M-1); E2E completo **660/660** (el primer intento cayó por saturación del arnés, no por el código); mutaciones de invisibles y `PlainText` 5/5 cazadas.
- [x] **F-1 y F-2 de PERF7**, hechos en `e2411e3` (`plans/informes-h7/informe-backend.md`, §F-1 y §F-2). F-1: el acta del canario en una sola pasada; `turnos()`, de 3 835 a 13,7 ms con 200 turnos y 2 000 mensajes; la evidencia, con p95 de 1 050 a 70 ms con 200 turnos y de 5 708 a 96 ms con 1 000; el JSON, el texto y el código de salida idénticos byte a byte y las mismas 6 sentencias; 13/13 mutaciones. F-2: campañas de Meta, de 36,5 a 27,2 ms (p50 de `campaignsPage()`), sin cambiar la respuesta; 4/4 mutaciones. Cumple el criterio de PERF7 §6. Tocan `app/Console/Commands/Marketing/UltronCanaryReport.php`, `StudioCampaignLinks.php` y `StudioMetaCampaigns.php`, fuera de la puerta de diff, y no traen migraciones.
- [ ] **La puerta del contrato en verde sobre el commit que se despliega** (contrato H7, «Traspasos»): el Studio, Access, Chaos y Hardening en SQLite; el grupo del Studio en PostgreSQL con `ops/pgunit.sh`; las suites del motor con la llave en `off` y en `all`; 56 rutas y `auth:coverage --unmapped` vacío; Pint en lo tocado. Se repite si el código cambia: con la corrección de M-1 (`601398c`) es la fase D de TEST7.
- [ ] **La puerta de diff** (la repite el paso 0): entre `PREVIO` y `origin/main`, en el motor, las integraciones existentes, el catálogo y las dependencias, solo los 10 ficheros que el contrato nombra (los 7 controladores de M-2 y `AdminUserController` de B7, la API interna de conocimiento de SEC7 y `routes/console.php`, que solo puede añadir líneas). **Comprobado el 02-11-2026 sobre `5ed9525` y otra vez sobre `3fbb155` (con F-1 y F-2), frente a `1e5f9a0`:** exactamente esos 10, `routes/console.php` +35/−0 y una sola migración nueva, la del H7.
- [x] **`PREVIO` fijado en un commit:** `1c76a7f` lo deja en `1e5f9a0`, el HEAD de producción del 02-11-2026 (`deploy-h7-backend.sh:41`). Si producción se mueve antes del despliegue (`git -C /var/www/api rev-parse --short=7 HEAD` en el servidor), el paso 0 aborta («producción no está en … ni en …»): se revisa qué entró, se actualiza `PREVIO` en otro commit y se vuelve a copiar el script.
- [x] **`TAREAS` fijado en un commit** (m-1 de REV7; `informe-devops-h7.md` §5, pasos 1 y 5, y §11). Es el total de entradas de `schedule:list` en producción con el monitor apagado; depende de las banderas del servidor (26 en las pruebas y 27 en el banco), así que no se podía escribir de antemano y llegó como `pendiente` (`deploy-h7-backend.sh:50` y `smoke-run-h7.sh:34`). Con `pendiente`, el despliegue y el smoke se niegan (código 2), y `--solo-comprobar` termina en **`COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=N`** con el total real (paso 4). El lead fija ese N en `deploy-h7-backend.sh` y en `smoke-run-h7.sh` en un commit, se vuelven a copiar con su huella y se repite el paso 4 hasta `EN VERDE`. `h7-monitor-scope.sh` no lo usa: comprueba que el total cambie en ±2. Con el monitor apagado el H7 no cambia el programador, así que el total es el mismo antes y después.
  - **Fijado: `TAREAS=39`**, en `deploy-h7-backend.sh:50` (`57b1178`) y como valor por defecto de `smoke-run-h7.sh:34` (`08bdd1d`; `SMOKE_TAREAS` lo sustituye solo en los bancos). Lo leyó el lead en producción el 02-11-2026 a las 17:35 UTC con `schedule:list --json`: 39 tareas, 0 del Studio; con el monitor encendido serán 41. Así lo dicen los commits y los comentarios de los scripts. Si el programador de producción cambia antes de desplegar, el paso 0 aborta («el programador tiene X tareas y TAREAS dice 39») y hay que volver a fijarlo en los dos.
- [ ] **Push con avance rápido del backend** a `main`, solo si es avance rápido sobre `origin/main`. **Nunca `--force`.** El CRM no se empuja: no cambia.
- [ ] Unos 15 minutos sin nadie en el Studio.
- [ ] Anotado `<commit-backend>`: `git rev-parse --short=7 origin/main` tras el push (siete cifras).

### 1. Respaldo (en el servidor, como root, menos de 2 horas antes del paso 5)

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h7-$TS
( umask 077
  mkdir -p "$BK"
  git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                       # empieza por PREVIO (hoy, 1e5f9a0)
  sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
  sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
  tar -C /var/www -czf "$BK/app-crm.tgz" app
  install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
  cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
  cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                          # al menos 226 (MIN_TABLAS del script)
stat -c '%a %U %n' "$BK" "$BK"/*                                                  # 700 el directorio; 600 y root el volcado y env.backup
du -h "$BK/ironbody.dump"; echo "BK=$BK"
```

Es el bloque del Hito 6 con el nombre del H7 (`informe-devops-h7.md` §5, paso 2). **Lo que comprueba el script:** que `api-commit.txt` empiece por `PREVIO`, también en una reanudación (`deploy-h7-backend.sh:157-160`, desde `1c76a7f`), el volcado de menos de 2 horas con su `.sha256`, el directorio en 700, el volcado y `env.backup` de root y en 600, **al menos 226 tablas con datos** (las 223 del H6 más sus 3 tablas) y, si no es una reanudación, el fichero de entorno idéntico a `env.backup`. **Nunca se borra un respaldo**: guarda juntos el volcado y la copia del entorno.

### 2. Copiar los scripts (desde el Mac, con el árbol del backend en `<commit-backend>` y `git status` limpio)

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h7/Iron-Body/backend
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py $O/perf-h7/presupuestos-h7.json $O/h7-monitor-scope.sh
scp $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py $O/perf-h7/presupuestos-h7.json $O/h7-monitor-scope.sh ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/deploy-h7-backend.sh /root/smoke-run-h7.sh /root/h7-monitor-scope.sh && chmod 600 /root/h7-schema-check.sql /root/smoke-h7.py /root/smoke-h6.py /root/presupuestos-h7.json && sha256sum /root/deploy-h7-backend.sh /root/h7-schema-check.sql /root/smoke-h7.py /root/smoke-run-h7.sh /root/smoke-h6.py /root/presupuestos-h7.json /root/h7-monitor-scope.sh'
```

**Son 7 ficheros.** `smoke-h6.py` también hace falta: `smoke-h7.py` carga su detector de fugas (`smoke-h7.py:92-93`), y sin él el lanzador sale con 2 sin tocar la cuenta (`smoke-run-h7.sh:51`; su cabecera, `:5-6`, lo pide junto a los otros). `presupuestos-h7.json` queda en `/root/`, junto al smoke: el lanzador lo busca ahí y lo borra al salir. `h7-monitor-scope.sh` es para el paso 10. Los dos scripts ya llevan `TAREAS=39` (`57b1178` y `08bdd1d`). **Si hubiera que volver a fijarlo** (paso 4), se vuelven a copiar `deploy-h7-backend.sh` y `smoke-run-h7.sh` con su huella.

Las huellas del Mac y del servidor tienen que coincidir (`informe-devops-h7.md` §5, paso 3: `.sh` en 700 y el resto en 600). `smoke-h6.py` viaja porque `smoke-h7.py` usa su detector de fugas. **El smoke del H7 borra al terminar** su lanzador, `smoke-h7.py` y `smoke-h6.py`: para la regresión del paso 8 se copian otra vez.

### 3. Esquema antes (solo lectura)

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
```

Esperado (lo de antes de la flecha en cada comentario del fichero): `disparadores_studio` 43, `check_studio` 49, `parciales_studio` 4, `tablas_studio` 14, `disparadores_uspub` 3, `disparadores_cadena` `uspub_forbid_delete:O,uspub_forbid_truncate:O,uspub_forbid_update:O`, `disparadores_apagados` 0, `check_seq` 0, `funcion_es_la_de_f` t (`huella_funcion` `4ad748b5f17f09b398f7f641c197d7ee`), los tres dueños `iron`, `create_en_public` t y `existe_rol_app` 1; en la cadena, `cabeza_seq` igual a `entradas_cadena` y **`rotas` 0**; **0 filas de cerrojos**; y los privilegios de `iron_app` en las cinco tablas con SELECT, INSERT, UPDATE y DELETE en t, TRUNCATE f y secuencia t (los privilegios por defecto, `arwd`). **Se para si** `rotas` no es 0 (la migración se negaría: la reparación es del rol dueño, con autorización del propietario), si `existe_rol_app` es 0 (en `production` la migración falla), si algún dueño no es `iron` (42501) o si hay cerrojos (esperar y repetir). Va por la entrada estándar porque `postgres` no puede leer `/root`.

### 4. Ensayo en el servidor (solo lectura, salvo el `git fetch`)

```bash
bash /root/deploy-h7-backend.sh <commit-backend> "$BK" --solo-comprobar
```

Esperado, en el formato del script (`<…>` son valores del momento):

```
0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):
   respaldo /var/backups/ironbody/ultron-studio-h7-<TS>: <≥ 226> tablas con datos · 700/600 de root
   programador: <N> tareas, ninguna del Studio · TAREAS sin fijar: fijar TAREAS=<N> aquí y en smoke-run-h7.sh, en un commit      (solo con TAREAS=pendiente)
   HEAD 1e5f9a0 limpio · migración: 1 · puerta: [<los 10 ficheros nombrados>] (todas nombradas por el contrato) · esquema [43 49 4 14 iron iron,iron,iron true 1 0] · función de F · cadena consecutiva · sin cerrojos · rutas [56 18 8 10] · programador <N> tareas (TAREAS=<N o pendiente>), 0 del Studio · workers 13/0 · facturación activa
   iron_app (SELECT/INSERT/UPDATE/DELETE/TRUNCATE/secuencia) en libro · asociaciones · canarios · instantáneas · cadena: <informativo>
   filas cadena · libro · instantáneas: <N N N> · colas fallidas: <N> · sellos studio: 0 · incidentes ultron_studio: <N>
   motor, H4, H5 y H6 (informativo; no cambia): {"engine_scope":"off", …}
   el fichero de entorno parsea (antes de tocar nada)
COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=<N> (no se ha tocado nada): …       (con TAREAS=pendiente)
COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)                                 (con TAREAS fijado)
```

**`TAREAS`** (m-1 de REV7; `informe-devops-h7.md` §5, paso 5): **ya está fijado en 39** en los dos scripts (`57b1178` y `08bdd1d`), así que el ensayo debe dar `programador 39 tareas (TAREAS=39), 0 del Studio` y `EN VERDE`. Con `pendiente`, el ensayo leería el programador (`schedule:list --json`, con su código de salida aparte) y terminaría en `COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=N`, sin «EN VERDE»: el lead fijaría ese N en los dos scripts en un commit, se volverían a copiar con su huella (paso 2) y se repetiría este paso hasta `EN VERDE`. Así se obtuvo el 39. **Con `TAREAS` fijado y otro total, aborta** («el programador tiene X tareas y TAREAS dice Y»): algo cambió en el servidor (una bandera, otro despliegue) y se revisa antes de seguir. **Si `schedule:list` no sale con 0, aborta**: sin poder leer el programador (la base o la caché) no se despliega.

Es lo único que cubre en el servidor lo que el banco no puede: GNU `stat`, `grep` y `find`, `sudo` real, los dueños `root` y `www-data` y el envoltorio `ironbody-migrate`. Con cualquier `ABORTA`, no se ha tocado nada: se aclara la causa y se repite.

### 5. Backend

```bash
bash /root/deploy-h7-backend.sh <commit-backend> "$BK" 2>&1 | tee /root/h7-$(date -u +%Y%m%d-%H%M%S).log
```

Lo esperado, según las líneas que imprime el script (no ejecutado en el servidor; en el banco, `COMPLETADO` en 47–50 s, con un 55P03 reintentado):

```
1. código: HEAD=<commit-backend>
   los <N> ficheros del merge son legibles por www-data
2. variable (monitor apagado):
   ULTRON_STUDIO_MONITOR_ENABLED añadida
   permisos del fichero: <dueño> <modo>
   el fichero de entorno parsea
3. migración (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):
   … 2026_11_02_100001_add_ultron_studio_hardening … DONE
   esquema 44 disparadores · 50 CHECK · 4 parciales · 14 tablas (intento 1)
4. esquema del Hito 7:
   disparadores de la cadena · CHECK de seq · dueño/definer de la función · disparadores apagados: uspub_chain_next:O,uspub_forbid_delete:O,uspub_forbid_truncate:O,uspub_forbid_update:O CHECK ((seq >= 1)) iron/false 0
   iron_app (SELECT/INSERT/UPDATE/DELETE/TRUNCATE/secuencia) en libro · asociaciones · canarios · instantáneas · cadena: true/true/false/false/false/true true/true/true/true/false/true true/true/true/true/false/true true/true/false/false/false/true true/true/false/false/false/true
   filas de la cadena, el libro y las instantáneas: <N N N> (las mismas: la migración no toca filas)
5. cachés y workers:
   cachés: www-data 600 · www-data 600
6. rutas del Studio (total · integrations · GET · POST): 56 18 8 10 · auth:coverage --unmapped → []
7. configuración efectiva:
   {"monitor_enabled":false,"monitor_expect_default":true}
   programador (total · del Studio): <TAREAS> 0 (esperado <TAREAS> 0: con el monitor apagado el H7 no añade tareas)
   motor, H4, H5 y H6 iguales que antes del despliegue
8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [13 0] y [13 0]
   INFO: workers con pid nuevo tras queue:restart: <N> de 13 (el resto termina su trabajo antes de reiniciarse)
9. sanidad:
   HEAD admin/ultron-studio/status sin token → 401 · Cache-Control: no-store, private
   … (lo mismo en publications, integrations/status y auth/me; admin/no-existe-h7 → 404 · Cache-Control: no-store, private)
   POST versions con 70 000 bytes sin token → 413 "code":"payload_too_large"
   usage-trigger sin firma → 403 (falla cerrada; T-1)
   colas fallidas: <N> (antes <N>)
   sellos studio en marketing_ai_actions (motor en off): 0
   security:check-secret-exposure: OK
   ultron-studio:publication-check: 0 (cuadra)
   ultron-studio:monitor --dry-run (publicación e integraciones): 0
   incidentes ultron_studio: <N> (los mismos)
   fichero de entorno igual al respaldo salvo la clave del Hito 7
10. integrations-doctor (las clasificaciones del H6, sin cambios):
   twilio connected_otp_only · twilio_voice not_configured · elevenlabs ELEVENLABS_NOT_CONFIGURED: 0
   meta_ads connected_read: 0                  (o una línea INFO si Meta sale degraded)
11. integrations-snapshot (el H7 no cambia ninguna integración: no tiene que anotar nada):
   instantáneas: <N> → <N>                      (3 → 3 si la fase 2 del H6 no se ha hecho)
12. laravel.log ERROR desde el inicio del despliegue: 0
DESPLIEGUE H7 (BACKEND) COMPLETADO
```

- **Lo que hace el script, en orden:** comprobaciones de solo lectura (abortan sin tocar nada) → `merge --ff-only` como root con `umask 022` → comprobación de que `www-data` lee cada fichero de `git diff --name-only PREVIO HEAD` → la variable del monitor (con el salto final asegurado) y comprobación de que el fichero de entorno parsea → `sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate`, con hasta 3 intentos ante un 55P03 → **la compuerta `44 50 4 14`** y las aserciones del esquema (disparadores de la cadena, `CHECK ((seq >= 1))`, función `iron`/INVOKER, 0 disparadores apagados, `ultron_studio_forbid()` intacta, privilegios de `iron_app` uno a uno y las filas sin cambio) → `route:cache` como `www-data` con `umask 077`; el `chmod 600` de las cachés lo hace `www-data` y nunca sobre un enlace (H-5 de SEC7) → `queue:restart` → las aserciones de los pasos 6 a 12. «`DESPLIEGUE H7 (BACKEND) COMPLETADO`» solo sale con 0 desvíos; si no, «`DESPLIEGUE H7 (BACKEND) CON N DESVÍOS: no seguir con el smoke hasta aclararlos`» y código 1. Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado.
- **Un tercer argumento que no sea exactamente `--solo-comprobar`** sale con 2 sin tocar nada.
- **Con `ABORTA` porque `www-data` no puede leer algún fichero del `merge`:** el código nuevo ya está en el disco, sin la variable ni la migración. Se corrigen los permisos como dice el mensaje (644 los ficheros y 755 los directorios de `git diff --name-only <PREVIO> HEAD`) y se relanza el mismo comando, que reanuda.
- **Con `ABORTA` en el paso 3 (55P03 agotado tras 3 intentos):** la migración es una sola transacción: o está entera o no está (con `43 49 4 14`, no se aplicó). El código nuevo sirve igual; lo que falta es la guarda. Se mira quién tiene la cadena con `h7-schema-check.sql` y **se relanza el mismo comando**, que reanuda.
- **El `lock_timeout` de 5 s está garantizado** (N-10 de `security` del H5, cerrada): el envoltorio de producción, `/usr/local/bin/ironbody-migrate`, fija él mismo `PGOPTIONS="-c lock_timeout=5s"`, según la comprobación del lead en el servidor del 02-11-2026, que coincide con lo que ya decían los runbooks de F y de G. No hace falta vigilar el paso 3 a mano. *(La segunda versión de esta sección pedía vigilarlo, porque REV7 §8 lo daba por no comprobado.)*
- **El fichero de entorno parsea antes de tocar nada** (m-5 de REV7, corregido por el lead en `285a585`; era la decisión que OPS7 dejaba abierta en su §11): el paso 0 lo comprueba con Dotenv como `www-data` (`deploy-h7-backend.sh:283-285`). Si no parsea, aborta sin tocar nada: «el fichero de entorno de producción NO parsea antes de empezar». El paso 2 lo repite después de escribir la variable (`:318-320`); si falla solo allí, lo rompió esa escritura, y su mensaje pide restaurarlo desde `$BK/env.backup`. *(Hasta `285a585` solo lo comprobaba el paso 2, con el `merge` ya hecho.)* La corrección no consta ensayada en el banco: el commit solo toca el script. [PENDIENTE: un `--solo-comprobar` en el banco con el script de `285a585` (`devops`).]
- **Con 42501 o con el mensaje de la guarda de producción al migrar:** la cadena, el libro o las instantáneas no son de `iron`, o falta el rol `iron_app`. Parar y avisar a `database-specialist`. **No forzar.**
- **Reanudación tardía.** Si se relanza más de 2 horas después del respaldo, el paso 0 aborta y **hay que rehacer el respaldo con `api-commit.txt` igual a `PREVIO`**, no al HEAD de ese momento: `git -C /var/www/api rev-parse 1e5f9a0 > "$BK/api-commit.txt"`. El paso 0 lo exige siempre, también en una reanudación, y su mensaje dice cómo escribirlo (`deploy-h7-backend.sh:157-160`, corregido en `1c76a7f` como el del H6 tras m-6). *(En la primera versión de esta sección se pedía comprobarlo a mano, porque el script aceptaba el commit nuevo; ya no hace falta.)*

### 6. Esquema después (solo lectura)

El mismo `h7-schema-check.sql` del paso 3. Esperado: `disparadores_studio` 44, `check_studio` 50, `parciales_studio` 4, `tablas_studio` 14, `disparadores_uspub` 4, `disparadores_cadena` con `uspub_chain_next:O` además de los tres de antes, `disparadores_apagados` 0, `check_seq` 1 con `CHECK ((seq >= 1))`, `dueno_funcion_cadena` `iron` y `funcion_cadena_definer` f, la misma `huella_funcion`, `rotas` 0, y en `iron_app`: UPDATE y DELETE **f** en la cadena, el libro y las instantáneas, t en asociaciones y canarios, TRUNCATE f y SELECT, INSERT y secuencia t en las cinco. Las filas, las mismas que antes.

### 7. CRM

Nada que publicar: el H7 no cambia el CRM. Comprobación: `curl -fsS https://ironbodyneiva.cloud/version.json` → `16c433c`.

### 8. Smoke (escribe en la fila 32 de `admins` y en `admin_sessions`)

```bash
ssh ironbody-vps 'SMOKE_BUDGETS_OUT=/root/h7-presupuestos-medidos-$(date -u +%Y%m%d).json bash /root/smoke-run-h7.sh'
```

`SMOKE_BUDGETS_OUT` solo en esta primera pasada: deja en un fichero 0600 las medidas y la propuesta de techo de cada ruta, sin datos personales. **Es la línea base de producción** (`informe-devops-h7.md` §5, paso 8). Antes de activar la cuenta, el lanzador exige `TAREAS` fijado y el JSON de presupuestos presente y válido; si falta cualquiera de los dos, sale con 2 sin tocar la cuenta.

Esperado:
- del lanzador: `OK      presupuestos de rendimiento válidos: …`, `OK      monitor apagado: {"monitor_enabled":false}`, `OK      el programador: <TAREAS> tareas (TAREAS), ninguna del Studio` (con `schedule:list` y su código de salida aparte: si falla, FALLO y el smoke no se ejecuta) y `OK      14 tablas ultron_studio_* en la base`;
- de `smoke-h7.py` (parte del Super Admin): login; lecturas autenticadas → 200; sin token → 401 y una ruta inexistente → 404; 70 000 bytes sin sesión y con sesión → 413 `payload_too_large`; 60 000 bytes sin sesión → 401; las entradas del historial con `verified` booleano al final y en `true`, y la de la cabeza igual a `head.signatureOk`; un motivo con cada uno de los 5 invisibles (U+2060, U+00AD, U+061C, U+2028 y U+E0041) → 422, y las dos contrapruebas (texto limpio y un emoji con ZWJ y U+FE0F) → 409 `integrations_writes_disabled` con las escrituras del H6 apagadas; `Cache-Control: no-store, private` en todas las respuestas de `/api/admin`; ninguna fuga, ningún 5xx y ningún 429;
- **la fase de presupuestos** (PERF7 §7.1; los techos viven **solo** en `presupuestos-h7.json`): por ruta (son 17), 1 GET de calentamiento y 5 cronometradas a ≥ 0,6 s, salvo la evidencia del canario: 1 de calentamiento y 3 muestras a ≥ 6,5 s (4 por pasada y 4 más si se repite, por debajo de su cupo de 10 por minuto). FALLO si la mediana pasa del `p50Ms`, si el máximo pasa del techo p95 (en la evidencia, `p95MsAfterF1` = **400 ms**, porque F-1 ya está en el código), si el cuerpo pasa de `maxBytes` o si el estado no es 200; un FALLO solo de tiempo se mide otra vez, una sola, y vale la repetición. OMITIDO si falta lo que pide la ruta (`requires`): **sin versión en CANARY, la evidencia**; sin versiones, el detalle de una versión; con el laboratorio apagado, sus dos rutas de ejecuciones; sin ejecuciones, el detalle de una ejecución. Nunca más de 90 lecturas con sesión por minuto (cupo de 120); la pasada entera dura unos 2 minutos. Termina en la línea `PRESUPUESTOS: N OK · N FALLO · N OMITIDO · N s`;
- en la base: ninguna fila nueva de `ultron_studio_*` nombra a la cuenta, el recuento exacto de las 14 tablas igual antes y después, y los incidentes del Studio iguales;
- la cuenta en reposo (`OK      token tras desactivar → 401` o `403` y `OK      sesiones de la cuenta tras el smoke: 0`) y `SMOKE H7: código de salida 0` **o `3`**.

Códigos: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto. **En producción se espera 0, o 3 si lo único OMITIDO son presupuestos que dependen del estado.** Sin versión en CANARY, la evidencia sale OMITIDO y el código es 3: es lo esperable en este despliegue (`informe-devops-h7.md` §5, paso 8). Se lee la línea `PRESUPUESTOS:` y el motivo de cada OMITIDO; cualquier otro OMITIDO, o un FALLO, no es esperable (decisión 1 del lead, `informe-devops-h7.md` §11). **Sin filas positivas:** solo envía lo que el servidor rechaza antes de escribir, y la fase de presupuestos solo hace GET. En el banco: recién desplegado, 36 OK · 3 OMITIDO (código 3); con una versión en CANARY y una ejecución, 41 OK (código 0). Cupo: 3 por minuto (el login); no toca la sonda de Twilio.

**Si sale un FALLO de tiempo en los presupuestos:** la fase ya repitió una vez esa ruta. Se repite la pasada entera una vez más antes de darlo por bueno, y **nunca se sube un techo para que pase** (el campo `calibration` del JSON).

**Recalibrar los techos** (después de esta primera pasada, en un commit aparte y con su autorización): se editan `p50Ms` y `p95Ms` (en la evidencia, también `p95MsAfterF1`, que manda mientras exista) en `perf-h7/presupuestos-h7.json` del repo, con las medidas del fichero de `SMOKE_BUDGETS_OUT`; se confirma y se vuelve a copiar con su huella. **Nunca se edita el código.** El smoke imprime la ruta, la versión y el sha256 del JSON que usa.

**Regresión del H6** (`informe-devops-h7.md` §5, paso 8), copiando otra vez `smoke-h6.py` y `smoke-run-h6.sh` a `/root/` con sus huellas:

```bash
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h6.sh'
```

Esperado: código 0 y unas 78 + 26 OK, como en la fase 1 del H6 (77 si Meta sale `degraded`). Cupo: **3 por hora** (la sonda). `smoke-h6.py` no comprueba cabeceras de caché, 413 ni invisibles (leído), así que el H7 no debería afectarle; **no consta ensayado contra el código del H7** (en el banco se ejecutó sobre el código del H6, nivel 3 de la vuelta atrás). [PENDIENTE: ensayarlo en el banco con `ensayar.sh <banco> smoke-h6 1` sobre el H7 desplegado (`devops` o `tester`).]

### 9. A los 15 minutos y a las 24 horas (solo lectura)

```bash
cd /var/www/api/backend
sudo -u www-data php artisan ultron-studio:monitor --dry-run                                        # código 0: publicación, integraciones y colas (sin abrir incidentes)
supervisorctl status                                                                                # 13 RUNNING; ningún BACKOFF/FATAL
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"                    # igual que en el paso 0
grep -c 'production.ERROR' storage/logs/laravel.log                                                 # frente al valor de antes del despliegue
grep ' /api/admin/' /var/log/nginx/access.log | awk '{print $9}' | sort | uniq -c                   # si el formato es el combined: ningún 5xx desde el despliegue
curl -sI -H 'Accept: application/json' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status | grep -i '^cache-control'   # Cache-Control: no-store, private
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';"   # igual que en el paso 0 (el monitor está apagado)
sudo -u www-data php -r 'require "vendor/autoload.php"; var_dump(App\Support\Text\InvisibleCharacters::violation("a\u{00AD}b"), App\Support\Text\InvisibleCharacters::violation("\u{1F468}\u{200D}\u{1F469}"));'   # string(6) "U+00AD" y NULL (solo a los 15 minutos)
```

- **La sonda de la última línea** es la de `plans/informes-h7/informe-security.md` §4, punto 7: la clase de invisibles se escribió para el PCRE 10.39 de producción (solo `\p{Cf}`, rangos explícitos y miradas atrás; cabecera de `InvisibleCharacters.php`), pero solo se ha probado con el PCRE 10.47 del Mac. El smoke ya ejercita las dos ramas por HTTP (un invisible → 422; un emoji con ZWJ → no 422); la sonda lo confirma sin red.

Las colas se comprueban aquí y no en el script: justo después de `queue:restart`, un carril puede tener cola sin latido unos segundos (comentario del paso 9 del script). Si el log es diario, el fichero del día. **A las 24 horas,** lo mismo; es la base para encender el monitor.

### 10. Encender el monitor (con autorización aparte del propietario)

Con el mando **`h7-monitor-scope.sh`** (`0d43401`; `informe-devops-h7.md` §3 y §5, paso 10), copiado en el paso 2. *(Las dos primeras versiones de esta sección daban pasos a mano, porque no había script.)* Antes:
- **El fichero de entorno, en 600** (m-4 de REV7, abierto): el mando lo exige (`h7-monitor-scope.sh:144`, como el del H6 en `:110`). Si el propietario ha instalado ya la propuesta de `ironbody-migrate` con su paso 3b (`root:www-data 640`; B-3 de la hoja de ruta), el mando aborta sin tocar nada, también al apagar. Con la propuesta instalada, el fichero no puede volver a 600: tendría que ser de root, y `www-data` dejaría de leerlo para `config:cache` (deducido de `ironbody-migrate-propuesta.sh:120-122` y `:255-258`; no ensayado). La salida es adaptar los mandos. [PENDIENTE: que los mandos acepten también 640 sin escritura de grupo ni de otros, antes de que el propietario instale la propuesta (`devops`).]
- **H-1 de OPS7:** si `schedule:run` está en el crontab de root, el monitor (y cada tarea programada) cargará como root las cachés de `www-data` cada minuto. El mando lo avisa al encender. Comprobarlo (`crontab -l -u root | grep -n 'schedule:run'; crontab -l -u www-data 2>/dev/null | grep -n 'schedule:run'`) y moverlo al de `www-data` es del propietario (paso 6b de `ironbody-migrate-propuesta.sh`).

```bash
bash /root/h7-monitor-scope.sh estado          # monitor=off y el programador sin tareas del Studio
bash /root/h7-monitor-scope.sh on off          # → MANDO monitor → on APLICADO (copia previa: /root/env-pre-h7-monitor-on-<fecha>)
sudo -u www-data php artisan ultron-studio:monitor     # (desde /var/www/api/backend) la primera pasada real: código 0 y ningún incidente ultron_studio nuevo
```

**Lo que hace el mando** (exige como segundo argumento el estado actual: sin él, o si no coincide con la configuración efectiva, no toca nada y sale con 2 o aborta; si ya está en el valor pedido, lo dice y sale con 0): la clave sin repetir y `schedule:list` con código 0 (si no, aborta); el fichero de entorno **en 600** (si no, aborta) y una copia previa en `/root/env-pre-h7-monitor-<valor>-<fecha>` (600, root); escribe la bandera en el **mismo fichero** (mismo inodo, dueño y modo; sin `sed -i`); comprueba que parsea; `config:cache` como `www-data` con `umask 077` y la caché en 600, nunca a través de un enlace (H-5); la configuración efectiva, con solo la bandera cambiada y el motor, el H4, el H5 y el H6 iguales; el programador, con `schedule:list` y su código de salida aparte, **con exactamente 2 entradas más** (las 2 del Studio: `ultron-studio:monitor` cada 30 minutos e `ultron-studio:integrations-snapshot` a las 07:10 de Bogotá) — no usa `TAREAS`: comprueba el salto de ±2 (en producción, con las 39 leídas el 02-11-2026, sería `39 0 → 41 2`); `queue:restart`; 13 RUNNING y 0 BACKOFF/FATAL dos veces; y 401 sin token. Al encender, informativo: el monitor en seco y quién ejecuta `schedule:run`. **«APLICADO» solo sale con 0 desvíos**; con alguno, `ESCRITO CON N DESVÍOS`, código 1 y la orden exacta para volver. Nunca imprime valores del fichero de entorno. Ensayado en el banco: 16/16 (`informe-devops-h7.md` §4.9: ida `27 0 → 29 2` y vuelta `29 2 → 27 0`, con el fichero de entorno byte a byte igual al volver) y, ya sin `TAREAS`, otra vez dentro de la repetición 11/11 de m-1 (§4.10; con `schedule:list` fallando, `estado` sale con 1 y `on off` aborta antes de escribir).

Por qué cada 30 minutos (decisión D-1 de OPS7, aceptada por el lead): la ventana de agrupación de IRON GUARD es de 60 minutos; con una pasada por hora, cada una reabriría el incidente.

### 11. Cierre

Registrar en `ULTRON_STUDIO_MASTER_ROADMAP.md` (fila del H7 y registro de despliegues), `ULTRON_STUDIO_FINAL_REPORT.md` («Hito 7») y `ULTRON_STUDIO_ROLLBACK.md` («Hito 7») la fecha UTC, el commit, `$BK`, la salida del script y la del smoke, y el estado. **Hecho (lead, 03-11-2026): ver «Hito 7: ejecución real», abajo, y las filas de la hoja de ruta.**

### Hito 7: ejecución real (03-11-2026)

**El Hito 7 entró en producción fuera de este runbook.** La secuencia nocturna del lead (`/root/h7-noche.sh d688418`, programada para las 05:00 UTC del 03-11-2026) no llegó a ejecutarse: el Mac que la lanzaba se durmió y el temporizador quedó congelado (la tarea se paró después, sin haber tocado nada). Ese mismo día un compañero desplegó sus propios cambios a mano, sin este runbook: `git pull` a las 16:51 UTC (a `7e7a94d`, «arreglo», con la migración `2026_11_03_000001_create_receivable_payment_splits_table`), `migrate` hacia las 16:55 UTC (lote 132: esa migración **y** la del Hito 7, `2026_11_02_100001_add_ultron_studio_hardening`), `merge origin/main` a las 18:29 UTC (a `cfcd0d1`, duraciones de valoraciones) y el CRM con `deploy-crm.sh` (`6535030` a las 18:31 y `73dbcd3` a las 19:38 UTC). Entre el `pull` y el `migrate` hubo 45 ERROR `42P01` (`relation "receivable_payment_splits" does not exist`), de su funcionalidad, no del Studio. Sin respaldo previo propio (el del lead, `/var/backups/ironbody/ultron-studio-h7-20261102-182549`, con `api-commit.txt` = `1e5f9a0`, es el único anterior al H7, 24 horas más viejo que los datos del momento), sin `--solo-comprobar`, sin las variables del monitor y sin smoke. El commit final del hito es `d688418`; producción quedó en `cfcd0d1` = `d688418` + los dos commits ajenos.

**Verificación y regularización del lead (03-11-2026, 20:00–20:05 UTC; registro `/root/h7-regularizar-20261103-200330.log`):**
- Esquema: 44 disparadores · 50 CHECK · 4 parciales · 14 tablas; `uspub_chain_next` activo; `CHECK (seq >= 1)`; `rotas` 0; `iron_app` sin UPDATE ni DELETE en la cadena, el libro y las instantáneas (comprobado en `role_table_grants`); 56 rutas y `auth:coverage --unmapped` vacío; programador 39 tareas, 0 del Studio; `Cache-Control: no-store, private` en `api/admin/*` (401 sin token); 13 workers RUNNING.
- Regularización: las dos variables del monitor escritas explícitas (`ULTRON_STUDIO_MONITOR_ENABLED=false`, `ULTRON_STUDIO_MONITOR_EXPECT` vacía; copia previa `/root/env-pre-h7-regularizar-20261103-200330`, 600 root); el fichero de entorno `www-data 600` y parsea; cachés de configuración y rutas regeneradas como `www-data` con `umask 077` y en 600 (la de rutas estaba en `root 644`); `queue:restart` → 13/0; monitor apagado; `publication-check` 0; `security:check-secret-exposure` OK; `ultron-studio:monitor --dry-run`: «publication OK · llave del motor off · estado off · sin cabeza (Legacy)», «integrations OK · meta_ads, twilio y voice never_authorized», «queues OK · 6 carriles», **«El Studio cuadra»**.
- Smoke del H7 (20:01 UTC, `/root/h7-smoke-20261103-200115.log`): **37 OK · 0 FALLO · 2 OMITIDO** (por estado: sin versiones y sin versión en CANARY), código de salida 3, el esperado; **presupuestos 15 OK · 0 FALLO · 2 OMITIDO** en 68 s (21 s esperando al cupo de 90 lecturas por minuto); la primera medición de producción queda en `/root/h7-presupuestos-medidos-20261103.json` como línea base para recalibrar los techos.
- Smoke de regresión del H6, fase 1 (20:03 UTC): **78 + 26 OK, 0 FALLO**, código 0.
- Queda sin hacer, por no ser de este hito: en `/var/www/api/backend/` hay 8 ficheros vacíos sin seguimiento (`-`, `0,`, `1,`, `2,`, `21000.00`, `214`, `3]`, `=`), de root, creados el 08-10-2026 por un comando mal escrito; conviene que el propietario los borre.

**Estado del hito: `HITO_7_COMPLETED`**, con la desviación documentada: en producción con la verificación completa hecha a posteriori; el monitor sigue apagado (encenderlo: `bash /root/h7-monitor-scope.sh on off`, con autorización). **Lección para el equipo:** cualquier `migrate` en producción aplica también las migraciones pendientes de otros hitos; los despliegues del Studio van siempre por su runbook (respaldo, ensayo, variables, smoke), y conviene avisar al lead antes de desplegar.


### Incidencias previsibles durante el despliegue

De `informe-devops-h7.md` §4 y §8 y de `informe-db.md` §10; ninguna se ha visto en producción.

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `ABORTA` en el paso 0 | Algo de partida no es lo esperado (HEAD, `PREVIO`, `origin/main`, la puerta de diff, la migración, el respaldo, la variable, el esquema, la cadena, cerrojos, rutas o workers) | No se ha tocado nada. Aclarar la causa y repetir |
| `PREVIO sin fijar (pendiente)` | El script no tiene el HEAD de producción del día | Fijarlo en un commit y volver a copiar el script. *(Ya fijado en `1e5f9a0` por `1c76a7f`.)* |
| `TAREAS sin fijar` (código 2), o `COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR TAREAS=N` en el ensayo | `TAREAS` sigue en `pendiente` | Fijar ese N en `deploy-h7-backend.sh` y `smoke-run-h7.sh` en un commit, volver a copiarlos con su huella y repetir el ensayo hasta `EN VERDE` |
| `el programador tiene X tareas y TAREAS dice Y` | Algo cambió en el servidor desde que se leyó (una bandera, otro despliegue) | Revisar qué cambió antes de seguir; si X es lo correcto, fijarlo en los dos scripts en otro commit |
| `el fichero de entorno de producción NO parsea antes de empezar` (paso 0) | Dotenv no lee el fichero de entorno tal como está en producción (desde `285a585`) | No se ha tocado nada. Aclarar con `devops` qué línea no parsea, sin imprimir valores, y corregirla con autorización; después, rehacer el respaldo (el paso 0 exige que el fichero sea igual a `env.backup`) |
| `schedule:list no sale con 0` | No se puede leer el programador (la base o la caché de `cache_locks`) | No se sigue: sin poder leerlo no se despliega ni se ejecuta el smoke (m-1). Aclararlo con `devops` |
| `la cadena de publicaciones tiene N entradas no consecutivas o mal encadenadas` | La cadena se tocó con DML (AS-3/N-3) | La migración se negaría. Reparar es del rol dueño con autorización del propietario (`ULTRON_STUDIO_ROLLBACK.md`, «Hito 5»). No forzar |
| 55P03 en la migración | Alguien leía la cadena dentro de una transacción (una pestaña del Studio, o el motor si su llave no estuviera en `off`) | El script reintenta 3 veces cada 15 s. Si se agota, `h7-schema-check.sql` para ver quién y **relanzar el mismo comando** |
| 42501 o la guarda de producción | Dueños distintos de `iron`, o sin el rol `iron_app` | Parar y avisar a `database-specialist`. **No forzar** |
| Privilegios de `iron_app` distintos de lo esperado tras migrar | `iron_app` hereda UPDATE o DELETE de otro rol o de `PUBLIC` | La migración ya lo comprueba y falla (D-4 de DB7). Avisar a `database-specialist` |
| Workers en BACKOFF tras `queue:restart` | La caché no es de `www-data` o no se puede leer | `stat -c '%U %a' bootstrap/cache/*.php`; `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'`. `supervisorctl restart` **solo con autorización** |
| `el fichero de entorno NO parsea` | Una línea mal formada | Restaurarlo desde `$BK/env.backup` (mismo dueño, modo 600) antes de seguir |
| El 70 000 sin sesión no da 413 | El tope temprano no está en la pila | Desvío del paso 9: revisar `bootstrap/app.php` con `backend` antes del smoke |
| `integrations-snapshot` anota alguna instantánea | Una huella de integraciones cambió con el H7 (no debería) | Revisar `GET integrations/config` antes de seguir |
| El smoke da 429 | Más de 3 ejecuciones por minuto (login) | Esperar. La cuenta ya está en reposo |

## Hito 8 (voz y telefonía): runbook por fases — FASE 1 PREPARADA Y ENSAYADA EN EL BANCO (08-11-2026), SIN EJECUTAR

**Estado (10-11-2026 02:50 UTC, lead):** el **despliegue 1b del cierre integral está en producción** desde las 02:20 UTC (backend `a608450`) y las 02:24 UTC (CRM `e0f7983`): sin migraciones, las 9 claves iguales, todo apagado; registro, desvío explicado y vigilancia en la Enmienda 16 (E16-30, E16-31). El guion incorporó `PREVIO=fe29126` y admite un despliegue solo de código cuando las dos migraciones ya estaban en PREVIO. **Estado (09-11-2026, lead):** la **fase 1 está en producción** desde las 12:06 UTC (backend `fe29126`, CRM `45dd73a`; Enmienda 15 del contrato: respaldos, registro, smoke y vigilancia). Lo que sigue describe cómo se preparó y ensayó. *(08-11-2026, OPS8-b: nada del H8 estaba en producción.)* Los scripts de la fase 1 están en `ops/` y se ensayaron en el banco (`ops/banco-h8/`, producción de juguete en `7c84cb5` con el estado de E1-12): `--solo-comprobar` EN VERDE, el despliegue en `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO` y el smoke (ver `plans/informes-h8/informe-devops.md`, §OPS8-b). **Donde este texto y los scripts no coincidan, mandan los scripts** del commit que se despliega. Contrato: `ULTRON_STUDIO_H8_CONTRACT.md` («Fases de despliegue», D8-19, D8-20, D8-21 y las enmiendas 1–13).

**Producción de partida** (leída el 08-11-2026 por devops, solo lectura y con la autorización del lead: `git rev-parse HEAD` y `migrate:status | tail`): API **`32a2560`** con la migración ajena `2026_11_06_000001_add_benefit_only_to_plans_table` ya aplicada (lote 133); el H7 completo (`HITO_7_COMPLETED`); ElevenLabs como lo dejó E1-12: piezas del H6 puestas, `ELEVENLABS_ENABLED=true`, verificación guardada el 03-11-2026 a las 22:55 UTC. Por eso el Studio afirma `ELEVENLABS_VERIFIED` hasta el **03-12-2026 a las 22:55 UTC**. `CALLS_ENABLED_IN_CODE = false`, `TWILIO_VOICE_ENABLED=false` y el OTP intacto. El CRM, el que diga `version.json` el día del despliegue.

**Dónde vive cada estado de ElevenLabs (decisión (b) del lead).**
- `voice:doctor` y la telefonía (`GET admin/voice/status`, `voice:doctor --telefonia`) dicen **`ELEVENLABS_UNVERIFIED`**. Es a propósito: el espacio neutro no guarda evidencia, y la telefonía no lee la verificación hasta la fase 3 (E4-6).
- El Studio (`integrations-doctor`, la ruta 45) dice **`ELEVENLABS_VERIFIED`**.
- Los scripts esperan cada estado en su sitio.

### Bloqueantes antes de la fase 1

1. **La rama de voz perdió la ascendencia de producción.** La reescritura autorizada del 08-11-2026 dejó copias de los commits ajenos de planes y beneficios con otro hash: `32a2560` (producción) y `7c84cb5` (`origin/main`) **no son ancestros** del HEAD de la rama, y su base común con `origin/main` es `d828bf7`.
   - Así, el push a `main` no sería de avance rápido, y el `merge --ff-only` del servidor fallaría (el paso 0 aborta: «origin/main no es avance rápido desde 32a2560»).
   - **Arreglo, del lead:** unir `origin/main` en la rama con un merge normal, sin `--force` ni reescritura. Los árboles de las copias son idénticos a los originales y el merge sale limpio. Ensayado en el clon del banco (`BANCO_UNIR=7c84cb52`): sin conflictos.
   - Después, push con avance rápido.
   - **Resuelto (nota del 08-11-2026, DOC8):** el paso 0 repetido sobre la rama con la ascendencia corregida ya ve `32a2560` como ancestro y solo lista `7c84cb5` como ajeno; el ensayo sobre el HEAD final `8d3c41f` sale en verde con `--acepta-main=7c84cb5`. *Fuentes: `plans/informes-h8/informe-devops.md`, «Paso 0 repetido tras la ascendencia corregida» y «Cierre del lead (08-11-2026 22:46 UTC)».*
2. **`7c84cb5` no está en producción** («Un cobro que no da vigencia no es la membresía de nadie»: `MembershipRebuilder` y su prueba, sin migración y fuera de la puerta). El H8 lo llevaría consigo.
   - El paso 0 lo detecta por parche, aunque esté copiado, y **aborta** («commits ajenos al H8 … que producción no tiene»).
   - **Decisión del lead (08-11-2026):** el paso 0 lo detecta y lo lista, y el despliegue **solo sigue si se pasa la bandera explícita `--acepta-main=7c84cb5`**. La decide el propietario en el momento de desplegar, con los hashes tal como los imprima el paso 0. Sin la bandera, el despliegue aborta y `--solo-comprobar` termina en «FALTA DECIDIR» (código 3). Un commit aceptado que no esté entre los ajenos también aborta: nada se acepta a ciegas.
3. **REV8 sin bloqueantes** (puerta 4 del contrato).
4. ~~**Recomendado, B8 (hallazgo de OPS8-b en el banco):** las 13 rutas del CRM de la telefonía usan `throttle:120,1` y `throttle:30,1` y exigen la sesión en el controlador. Una petición **sin sesión** a `/api/admin/voice/*` suma en el cubo por IP que Laravel comparte con el login del CRM (`throttle:8,1`): 9 seguidas desde la misma IP dejan el login en 429 durante un minuto (medido en el banco).~~
   - ~~Riesgo: una pestaña con la sesión caducada en la oficina podría dejar sin login a toda la oficina, el mismo caso que ya se resolvió en la agenda y en valoraciones.~~
   - ~~Arreglo: limitadores con nombre, por credencial, como `marketing-agenda`.~~
   - **Resuelto en B8-c (`8ed0924`; corregido aquí el 08-11-2026, DOC8):** las 13 rutas usan `throttle:voice-admin-read` y `throttle:voice-admin-write`: 120 lecturas y 30 escrituras por minuto **por persona** con sesión y, sin sesión, un cubo propio por ruta y red (60 y 30) que nunca toca el del login. 50 peticiones anónimas ya no cambian el login. *Fuentes: contrato H8, E9-4; `routes/voice.php:56-74`; `plans/informes-h8/informe-backend.md`, §B8-c (2), punto 2.*

### Fase 1 (todo apagado): qué entra y qué no

| Pieza | Qué cambia | Quién lo nota |
|---|---|---|
| Migraciones `2026_11_10_100001` (Studio, 4 tablas) y `…100002` (telefonía, 7 tablas) | Esquema `ultron_studio_%` 44/50/4/14 → **68/84/6/18**; `voice_%` 0 → **27/51/3/7**; 4 funciones INVOKER del dueño; `iron_app` en `ra` en las 6 de solo inserción y en `rawd` en las 5 con estados (E3-5). La 100001 pide SHARE ROW EXCLUSIVE sobre `ultron_studio_versions` (choca con un escritor, no con un lector) | Nadie |
| 21 rutas del Studio (56 → 77) y 17 de la telefonía (13 del CRM y 4 públicas) | Cerradas: banderas apagadas (409), sin credencial (401), validación (422) | El CRM nuevo; nadie más |
| `routes/console.php` | Bloque de voz bajo `VOICE_MONITOR_ENABLED`, **apagado**: no programa nada | Nadie |
| 9 variables explícitas | 5 interruptores en `false` (`VOICE_INBOUND/OUTBOUND/LAB/MONITOR_ENABLED`, `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`) y 4 cerrojos de gasto (`VOICE_INBOUND_DAILY_CAP=0`, `VOICE_DAILY_BUDGET_USD=0`, `VOICE_LAB_DAILY_BUDGET_USD=0`, `VOICE_LAB_DRIVER=fake`) | Nadie |
| CRM | Sección «Voz y telefonía» (`marketing/ultron-studio/voz`, chunk `studio-voice-page`) y su enlace en el tablero | Quien tenga `ultron.view`: ve la voz apagada |

**Qué NO entra en la fase 1:**
- ninguna pieza del H8 (secretos, subcuenta, número, bases: el paso 0 aborta si encuentra una, porque cambiaría la huella de voz, S8-b 9);
- el worker `ironbody-voice` (solo la saliente usa la cola `voice`; se instala en la 4a);
- `composer install` (el H8 no cambia `composer.lock`; si cambia, el paso 0 aborta);
- nginx, DNS, TLS, el firewall y el crontab.

**Orden obligatorio:** backend → smoke del backend → CRM. Publicar el CRM antes dejaría la página nueva hablando con rutas que no existen, y `deploy-h8-crm-fase1.sh` lo comprueba: sin token, `admin/voice/status` tiene que dar 401 y no 404.

#### 1. Respaldo (en el servidor, como root, menos de 2 horas antes del paso 5)

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h8-fase1-$TS
( umask 077
  mkdir -p "$BK"
  git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                       # empieza por PREVIO (hoy, 32a2560)
  sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
  sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"                      # con la ruta absoluta: el paso 0 lo comprueba
  tar -C /var/www -czf "$BK/app-crm.tgz" app
  install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
  cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
  cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                          # al menos 227 (MIN_TABLAS del script)
stat -c '%a %U %n' "$BK" "$BK"/*; du -h "$BK/ironbody.dump"; echo "BK=$BK"
```

**Nunca se borra un respaldo.** La política de respaldos del H8 está más abajo (E3-7).

#### 2. Copiar los scripts (desde el Mac, con el árbol del backend en `<commit-backend>` y `git status` limpio)

```bash
O=backend/docs/ultron-studio/ops
shasum -a 256 $O/deploy-h8-backend-fase1.sh $O/h8-schema-check.sql $O/smoke-h8.py $O/smoke-run-h8.sh $O/smoke-h6.py $O/perf-h8/presupuestos-h8.json
scp $O/deploy-h8-backend-fase1.sh $O/h8-schema-check.sql $O/smoke-h8.py $O/smoke-run-h8.sh $O/smoke-h6.py $O/perf-h8/presupuestos-h8.json ironbody-vps:/root/   # el JSON de PERF8 llega como /root/presupuestos-h8.json
ssh ironbody-vps 'chmod 700 /root/deploy-h8-backend-fase1.sh /root/smoke-run-h8.sh && chmod 600 /root/h8-schema-check.sql /root/smoke-h8.py /root/smoke-h6.py /root/presupuestos-h8.json && sha256sum /root/deploy-h8-backend-fase1.sh /root/h8-schema-check.sql /root/smoke-h8.py /root/smoke-run-h8.sh /root/smoke-h6.py /root/presupuestos-h8.json'
```

Son 6 ficheros. Los presupuestos son los de PERF8 (`ops/perf-h8/presupuestos-h8.json`, medidos con datos: p95 y bytes por ruta), los únicos (el provisional de OPS8-b se retiró). El smoke imprime cuál usa y su huella; recalibrar es editar ese JSON en un commit, nunca el código. `smoke-h6.py` viaja porque `smoke-h8.py` usa su detector de fugas. El lanzador borra al terminar sus copias de `/root`.

#### 3. Esquema antes (solo lectura)

```bash
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h8-schema-check.sql
```

**Esperado:**
- Studio 44/50/4/14 y `voice_%` 0/0/0/0;
- `funciones_h8` 0;
- `funcion_es_la_de_f` t;
- `dueno_versiones` iron;
- `create_en_public` t;
- `existe_rol_app` 1;
- `migraciones_h8_registradas` 0;
- 0 filas de cerrojos de escritura en versiones.

**Se para si** hay tablas `voice_*` antes de migrar, si falta `iron_app` (en `production` las dos migraciones se niegan) o si hay escritores abiertos de versiones (esperar y repetir).

#### 4. Ensayo en el servidor (solo lectura, salvo el `git fetch`)

```bash
bash /root/deploy-h8-backend-fase1.sh <commit-backend> "$BK" --solo-comprobar [--acepta-main=7c84cb5]
```

**Termina en `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`.**

Comprueba:
- `PREVIO` = el HEAD de producción;
- `origin/main` = `<commit-backend>` y avance rápido desde `PREVIO`;
- en el diff, solo las 2 migraciones del H8;
- las pendientes (ficheros de `origin/main` menos la tabla `migrations`): exactamente las que falten del H8;
- la puerta de diff (OTP, motor, API interna, Meta, catálogo y dependencias), que solo cruzan los 4 controladores públicos de la voz, el job de la saliente y `console.php`, este último solo añadiendo líneas;
- los commits ajenos por parche;
- el respaldo (huella, 700/600, al menos 227 tablas, `env.backup` = el entorno);
- las 9 claves ausentes o iguales, ninguna pieza del H8 ni del número, `TWILIO_VOICE_*` apagado;
- el esquema de partida, la función de F y que no haya escritores de versiones;
- 56 rutas y 0 de voz;
- el programador con su código de salida: 0 del Studio y 0 de voz;
- el monitor del H7 apagado;
- que el Studio afirme `ELEVENLABS_VERIFIED`, y cuántas horas le quedan;
- `integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED` con código 0;
- el monitor en seco sin `config_drift`;
- 13 workers y 0 en BACKOFF/FATAL, y la facturación;
- que el entorno parsea.

Todo queda en `/root/h8-fase1-comprobar-<fecha>.log` (600).

**Con cualquier `ABORTA` no se ha tocado nada:** se aclara y se repite. Las causas conocidas están en «Incidencias previsibles».

#### 5. Backend

```bash
bash /root/deploy-h8-backend-fase1.sh <commit-backend> "$BK" [--acepta-main=7c84cb5]      # la bandera, solo si el propietario lo decide ahí
```

El registro queda en `/root/h8-fase1-<fecha>.log` (600).

**Lo que hace, en orden:**
1. El paso 0.
2. `merge --ff-only`, como root con `umask 022`, y comprobación de que `www-data` lee cada fichero del merge.
3. Las 9 variables, añadidas solo si faltan, y comprobación de que el entorno parsea.
4. `sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate`: hasta 3 intentos ante un 55P03, con 15 s entre ellos. Si las dos migraciones ya estaban aplicadas por otro, no se llama y se comprueba el esquema.
5. **La compuerta `68 84 6 18 27 51 3 7`**: sin ella no hay `route:cache`.
6. Las aserciones del esquema: 4 funciones INVOKER del dueño, ningún disparador apagado, F intacta, las 11 tablas de `iron`, privilegios uno a uno, 0 filas, y las tablas del H6/H7 iguales.
7. `config:cache` y `route:cache` como `www-data` con `umask 077`, en 600 y nunca a través de un enlace.
8. `queue:restart`.
9. Las comprobaciones posteriores:
   - rutas `77 18 8 10 15 6 13 5 8 4` y `auth:coverage --unmapped` vacío;
   - la configuración efectiva toda apagada;
   - el motor, el H4–H7 y la voz iguales que antes;
   - el programador con el mismo total y 0/0, y la cola `voice` vacía;
   - 13 workers dos veces;
   - la sanidad HTTP: 401 y `no-store` en lo nuevo, 401 en las 4 públicas sin credencial, 413 con 9 000 bytes en la iniciación, el 413 del Studio y el 403 del OTP;
   - las colas, los sellos, los secretos y `publication-check`;
   - el monitor con el mismo veredicto y sin deriva;
   - el entorno igual al respaldo salvo las 9 claves;
   - `voice:doctor --telefonia`;
   - el `integrations-doctor` de la fase;
   - `integrations-snapshot` sin instantáneas nuevas;
   - 0 ERROR en el log.

**Termina en `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO`.** En el banco tardó unos 35 s, con 0 desvíos.

#### 6. Esquema después (solo lectura)

El mismo `h8-schema-check.sql`. **Esperado:**
- Studio 68/84/6/18 y `voice_%` 27/51/3/7;
- `disparadores_apagados` 0;
- `funciones_h8` 4 y `funciones_h8_definer` 0, las cuatro de `iron`;
- la huella de F igual;
- `migraciones_h8_registradas` 2;
- las 11 tablas de `iron`;
- `ra---s` en `ultron_studio_voice_publications`, `voice_brakes`, `voice_call_events`, `voice_consents`, `voice_exclusions` y `voice_rne_checks`, y `rawd-s` en las otras 5;
- `filas_h8` todas a 0.

#### 7. Smoke (escribe en la fila 32 de `admins` y en `admin_sessions`)

```bash
ssh ironbody-vps 'SMOKE_BUDGETS_OUT=/root/h8-presupuestos-medidos-$(date -u +%Y%m%d).json bash /root/smoke-run-h8.sh'
```

**Antes de activar la cuenta**, el lanzador comprueba:
- la voz toda apagada (las 5 banderas, la constante cerrada y los dos monitores);
- el programador: 0 del Studio y 0 de voz, con su código de salida;
- `voice:doctor --telefonia` en el estado de la fase 1;
- el monitor del H7 sin deriva;
- 18 + 7 tablas.

**El smoke (`smoke-h8.py`):**
- el login primero;
- la telefonía con sesión: constante cerrada, banderas apagadas, cadena vacía, freno suelto, cupos a 0, listas vacías, 404 a lo inventado y 422 a cada POST vacía;
- las 21 del Studio con sesión: lecturas 200, inventados 404, laboratorio 409 `voice_lab_disabled`, publicar y promover 409 `voice_publishing_disabled`, y revertir y apagar solo con cuerpos inválidos (422);
- la ruta 45: `ELEVENLABS_VERIFIED`, publicar apagado y las 7 llaves de `can`;
- los presupuestos;
- **después**, lo anónimo:
  - las 13 del CRM de la telefonía dan 401 y las 4 públicas, 401 con su motivo;
  - sus topes, 413;
  - una post-llamada de exactamente 1 MB sin firma: si contesta Laravel con 401, nginx deja pasar el tope entero; si contesta nginx con un 413 HTML, hay que subir su `client_max_body_size` antes de la fase 3;
  - las 21 del Studio sin sesión, 401;
- el detector de fugas.

**En la base y los registros, antes y después:**
- el recuento exacto de las 25 tablas;
- las filas que nombran a la cuenta;
- los incidentes y la cola `voice`;
- en el canal `voice`, solo los 4 rechazos esperados;
- 0 ERROR.

**Red saliente:** en el servidor, un muestreo informativo de los sockets de PHP hacia ElevenLabs (débil: las IP de una CDN se comparten). Lo estricto es la base y el canal `voice`. En el banco, la valla.

**Esperado: código 3**, y lo único OMITIDO es el presupuesto de `GET voice-lab/sessions`: el laboratorio está apagado (409 `voice_lab_disabled`), como en la fase 1. Cualquier otro OMITIDO, o un FALLO, no se espera. Sin filas positivas: solo se envía lo que el servidor rechaza antes de escribir. **Ritmo:** el smoke hace el login primero y no pasa de 25 peticiones por minuto a `/api/admin/voice/*`. No se lanzan dos pasadas en el mismo minuto. *(Corregido el 08-11-2026, DOC8: esto decía que la telefonía del CRM comparte el cubo por IP con el login; desde B8-c ya no lo comparte (bloqueante 4, resuelto). El ritmo del smoke se queda: no estorba.)*

#### 8. CRM (desde el Mac)

```bash
bash backend/docs/ultron-studio/ops/deploy-h8-crm-fase1.sh <commit-crm> <commit-crm-en-producción> /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front --solo-comprobar   # build y dist, sin publicar
bash backend/docs/ultron-studio/ops/deploy-h8-crm-fase1.sh <commit-crm> <commit-crm-en-producción> /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front
```

**Lo que hace:**
- comprueba que el backend del H8 ya sirve: 401 en `admin/voice/status` y en `admin/ultron-studio/voice/publications`;
- el árbol limpio en el commit del build, igual a `origin/main` y conteniendo el commit de producción;
- `npm ci` solo si cambia el lock;
- `npm run build:prod` y la comprobación del sello;
- el chunk de voz (selector `app-ultron-studio-voice`) y el del tablero (`data-voz-enlace` y la ruta), con el main del build importándolos;
- respaldo en el servidor (`index.html`, `version.json` y `app.tgz` de todo `/var/www/app`);
- `rsync` **sin `--delete`** y dueños `root:root`;
- la verificación pública: `version.json`, el main nuevo, el viejo, el chunk de voz y el del tablero en 200, y `/marketing/ultron-studio` y `/marketing/ultron-studio/voz` en 200.

**Imprime la orden exacta de su vuelta atrás.**

#### 9. A los 15 minutos y a las 24 horas (solo lectura)

```bash
cd /var/www/api/backend
sudo -u www-data php artisan voice:doctor --telefonia                                   # constante cerrada, banderas apagadas, cadena none/none, freno suelto
sudo -u www-data php artisan ultron-studio:monitor --check=publication --dry-run        # 0
supervisorctl status | grep -c RUNNING                                                  # 13
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"        # igual que en el paso 0
grep -c 'production.ERROR' storage/logs/laravel.log                                     # frente al de antes
ls storage/logs/voice-*.log 2>/dev/null && grep -hoE '\] [a-z]+\.[A-Z]+: [a-z0-9_.]+' storage/logs/voice-*.log | sort | uniq -c   # solo rechazos de rutas públicas, si alguien las tocó
```

#### 10. Antes del 03-12-2026 a las 22:55 UTC: la verificación de ElevenLabs (decisión (c) del lead)

`ELEVENLABS_VERIFIED` caduca a los 30 días de la última verificación «ok». En la fase 1, la verificación semanal está programada, pero en el bloque de voz, que está apagado. **Antes de esa fecha**, una de dos, con autorización:
- **a mano:** `cd /var/www/api/backend && sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice`. Hace un GET de solo lectura al agente de verificación y guarda la evidencia. Esperado: «credenciales válidas; evidencia guardada». Con la clave nueva si ya se rotó (E2-2);
- **encender el monitor de voz** (`VOICE_MONITOR_ENABLED=true`, con el mando de las fases 2–4b), que programa la verificación los lunes a las 07:20 de Bogotá.

**Encender el monitor del Studio (H7) no renueva la verificación:** solo avisa cuando caduca, y eso si se le ha fijado la expectativa de la fase (paso 11). Si caduca sin renovarse, el Studio pasa a `ELEVENLABS_UNVERIFIED` (clasificación `degraded`). Nada se rompe (las salientes siguen fallando cerradas, E4-6), pero un ensayo posterior de este script aborta en el paso 0 hasta renovarla.

#### 11. Antes de encender el monitor del H7: su expectativa de la fase 1 (decisión (d) del lead)

El defecto del código sigue en `elevenlabs:ELEVENLABS_NOT_CONFIGURED`: no se cambia. Encendido así, abriría un incidente `integrations_drift`. **Antes de `h7-monitor-scope.sh on off`**, la expectativa se fija en el entorno, en el mismo fichero (mismo inodo) y con copia previa:

```bash
cd /var/www/api/backend
install -m 600 -o root -g root .env /root/env-pre-h8-expect-$(date -u +%Y%m%d-%H%M%S)
python3 - .env <<'PY'
import re, sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
v = 'twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED'
n = len(re.findall(r'(?m)^[ \t]*ULTRON_STUDIO_MONITOR_EXPECT[ \t]*=.*$', s))
assert n <= 1, 'clave repetida: deduplicar a mano'
s = re.sub(r'(?m)^[ \t]*ULTRON_STUDIO_MONITOR_EXPECT[ \t]*=.*$', '  ULTRON_STUDIO_MONITOR_EXPECT=' + v, s) if n else s.rstrip('\n') + '\n  ULTRON_STUDIO_MONITOR_EXPECT=' + v + '\n'
with open(p, 'r+', encoding='utf-8') as f:
    f.seek(0); f.write(s); f.truncate()
print('expectativa escrita (no es un secreto)')
PY
sudo -u www-data php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createArrayBacked(".")->load(); echo "parsea\n";'
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
sudo -u www-data php artisan ultron-studio:monitor --check=integrations --dry-run            # integrations: OK
```

En las fases siguientes, cada mando reescribe esta expectativa con la de su fase (tabla de abajo).

### Fases 2 → 3 → 4a → 4b

Cada fase pide **autorización escrita** del propietario, con su entorno, su impacto y su vuelta atrás declarados antes (protección 13). Ninguna se encadena con la siguiente sin su criterio de entrada. **El mando es `ops/h8-voice-scope.sh` (OPS8-c, 09-11-2026; ensayado en el banco, abajo, «OPS8-c»):** mueve solo las claves de cada orden en el mismo fichero, con copia previa y `config:cache` como `www-data`, como `h7-monitor-scope.sh`, y se niega a encender llamadas sin el commit `voice(calls-open)` desplegado. Sin él no se enciende ninguna fase: las ediciones a mano del entorno de producción no son el procedimiento. *(Hasta el 09-11-2026 decía «pendiente (OPS8)».)*

| Fase | Criterio de entrada | Qué se enciende o se pone | Expectativa del monitor | Vuelta atrás |
|---|---|---|---|---|
| **2. Credenciales y número** | La fase 1 estable 24 h; B-6 hecho [P-4]; [P-5], [P-21], [P-22] y [P-23] decididos; **la clave de ElevenLabs rotada (E2-2, abajo)**; el plan de pago con telefonía (P-22) | Las piezas del propietario: la subcuenta y sus 2 keys, el token de firma, el número (`TWILIO_VOICE_FROM`), `ELEVENLABS_AGENT_PHONE_NUMBER_ID`, `ELEVENLABS_INITIATION_SECRET`, las referencias de los secretos y `VOICE_WEBHOOK_BASE_URL`. La importación del número en ElevenLabs. **`VOICE_MONITOR_ENABLED=true`** en cuanto el número esté importado: la conciliación avisa de cualquier llamada que no haya pedido Laravel. El laboratorio hablado con presupuesto mayor que 0 (`VOICE_LAB_ENABLED=true`, `VOICE_LAB_DRIVER=elevenlabs`). **La configuración autorizada se reconoce otra vez** (la huella de voz cambia). **Ni una llamada telefónica** | `twilio_voice:disabled` (con `TWILIO_VOICE_ENABLED=false` solo puede ser `disabled`; DOC8, 08-11-2026); `elevenlabs:ELEVENLABS_VERIFIED` | Nivel 2 de D8-20: `voice/off`, `ELEVENLABS_ENABLED=false`. Nivel 3: revocar las keys en las consolas **Nginx para el laboratorio hablado del CRM (E10-12, 08-11-2026):** `Content-Security-Policy` con `connect-src wss://api.elevenlabs.io` y `Permissions-Policy: microphone=(self)`; los worklets de audio se sirven desde el propio CRM, nada de `script-src blob: data:`. |
| **3. Entrantes en canario** | La fase 2 verificada; [P-7] a [P-9], [P-12], [P-16] y [P-24] decididos; la **salud del webhook** automatizada (B8, decisión (f)); nginx deja pasar 1 MB a la post-llamada (el INFO del smoke); el lector de la verificación de E4-6 (B8); canarios de voz activos (fase 2 del H6) | El commit `voice(calls-open)`, desplegado; **`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=true`**, la única bandera de publicar (E4-1), sin la cual publicar da 409 `voice_publishing_disabled`; una publicación de voz en `canary`; `VOICE_INBOUND_ENABLED=true`, `VOICE_INBOUND_DAILY_CAP` > 0 y `TWILIO_VOICE_ENABLED=true`. *(La bandera de publicar, añadida el 08-11-2026, DOC8: publicar el canario asigna el número, que la constante cierra hasta esta fase; `config/ultron_studio.php:272`, `informe-studio.md` §9)* | `twilio_voice:connected_voice`; `elevenlabs:ELEVENLABS_VERIFIED` | Nivel 0: `VOICE_INBOUND_ENABLED=false`. Nivel 0b: `voice:hangup --all --confirm`. Nivel 1: revertir la publicación |
| **4a. Salientes a canarios** | La 3 estable 24 h y N llamadas sin incidencias; **el worker `ironbody-voice` instalado y en RUNNING** (borrador en `docs/ops/supervisor/ironbody-voice.conf`; antes de copiarlo, comprobar la conexión `voice`, E1-10: 14 RUNNING) | `VOICE_OUTBOUND_ENABLED=true` con alcance `canary`, `TWILIO_VOICE_DAILY_CALL_CAP` pequeño (p. ej., 3) y `VOICE_DAILY_BUDGET_USD` > 0 | La misma | Nivel 0: `VOICE_OUTBOUND_ENABLED=false`; 0b; 1 |
| **4b. Contactos con consentimiento** | La 4a estable; **el visto bueno legal**; [P-11], [P-13] a [P-15] y [P-20]; el RNE decidido (P-14); **una publicación promovida a `rest`**: el alcance `contacts` lee esa parte, y sin ella toda saliente a un contacto da `no_publication` *(añadido el 08-11-2026, DOC8; `app/Services/Telephony/VoicePublishedConfig.php:252-255`)* | `VOICE_OUTBOUND_SCOPE=contacts` y `VOICE_RNE_VALIDITY_DAYS` > 0 | La misma | `VOICE_OUTBOUND_SCOPE=canary` o nivel 0 |

**Cómo se suelta el freno (D8-19).**
- El freno lo abren solos:
  - una violación de la guarda;
  - un `seal_mismatch`;
  - la deriva del agente o del número (`agent_drift` con `trigger: number_drift`, E6-1);
  - el techo de gasto;
  - una llamada que Laravel no inició.
- **Nunca se suelta solo.** Lo suelta una persona con `ultron.voice_publish` (por defecto, solo el Super Admin), con una nota de 5 a 300 caracteres. Desde el CRM («Voz y telefonía» → freno), o con `POST /api/admin/ultron-studio/voice/brakes/{uuid}/release` `{clientRequestId, note}`. *(Corregido el 08-11-2026, DOC8: decía «de 5 a 500»; el código admite de 5 a 300, `StudioVoiceEvidence::NOTE_MAX` y `VoiceBrake::NOTE_MAX`.)* **Cada apertura se suelta por separado y una sola vez:** con varias abiertas, el freno sigue echado hasta soltarlas todas (`VoiceBrake::isOpen()`).
- El libro lo registra, y cada apertura abrió un incidente de IRON GUARD.
- **Antes de soltarlo**, se aclara la causa con `GET voice/brakes` y `voice:reconcile --dry-run --json` (no escribe nada; E12-4). Soltar sin causa conocida no es el procedimiento. **Ojo:** sin `--dry-run`, `voice:reconcile` escribe eventos y precios y puede abrir otra apertura (también la del techo de gasto): para diagnosticar, siempre `--dry-run` (E12-4, corregido en B8-d).

**El secreto de iniciación, comprobado ANTES de conectar el número a las entrantes (fase 2; O-2 de TEST8-b).**
- **Riesgo:** si el secreto está mal puesto en ElevenLabs (otro valor, otra cabecera o sin poner), cada entrante recibe un **401 en la iniciación, sin fila en `voice_calls`**: la autenticación va antes de la puerta. La conciliación ve en Twilio una llamada sin su fila, la marca `unknown_provider_call` y **echa el freno**. Falla cerrado, pero ruidoso: un freno y un incidente por llamada, hasta que se arregle.
- **Antes de asignar el número al agente**, una iniciación de prueba:
  - **con la herramienta de prueba del webhook de ElevenLabs** [por confirmar en la consola]: esperado 200 y `caller_authorized` = «no» (las entrantes siguen apagadas en la fase 2), y en `voice-*.log`, **ningún** `voice.inbound.unauthorized`;
  - o **desde el servidor**, con el valor leído del entorno por PHP y pasado a `curl` por la entrada estándar (sin la línea de órdenes ni el terminal), un cuerpo sintético con un número de prueba (`+57 300 000 00xx`) y un `call_sid` inventado: esperado 200 y `caller_authorized` = «no». **Deja una fila `denied`** con esa huella sintética: es la evidencia de la prueba y no es un dato personal.
  - **Nota (09-11-2026, E12-3):** esa fila denegada no compromete reserva y su coste real es 0, así que con `VOICE_DAILY_BUDGET_USD=0` no echa el freno `cost_cap` (corregido en B8-d).
  - Con un 401, el secreto no casa: no se conecta el número.
  - En el banco, el mismo ensayo es `ensayar.sh <banco>` con `/__inbound?from=…&variant=valid` del simulador; `variant=bad_secret` da el síntoma.
- **Diagnóstico, si ya pasó:**
  - `voice.inbound.unauthorized` en `storage/logs/voice-*.log`, con `has_header: true` (cabecera presente y valor distinto) o `false` (sin cabecera o con otro nombre; la cabecera es `X-Voice-Initiation-Secret`);
  - ninguna fila en `voice_calls` para esas llamadas;
  - en `GET admin/voice/metrics`, el freno echado;
  - `voice:reconcile --json` con `unknown` > 0;
  - en IRON GUARD, un incidente por llamada.
- **Remedio:** corregir el secreto en ElevenLabs o en el entorno (el mando, sin imprimirlo) y repetir la iniciación de prueba. **Solo después** lo suelta una persona (`ultron.voice_publish`), con la nota de la causa.

**Rotación de la clave de ElevenLabs antes de la fase 2 (E2-2; la del 03-11 viajó por el chat).**
1. El propietario crea una clave nueva en la consola, acotada y con cupo de créditos (P-22).
2. La pone en el servidor sin pasar por el chat, con el procedimiento de `ULTRON_STUDIO_ELEVENLABS_READY.md` (pasos 1–6: el fichero de la clave en 600 y `backend/docs/ultron-studio/ops/el-verificar.sh`, en el repo desde el 08-11-2026: `bash /root/el-verificar.sh /root/el-clave.txt <agent_id>`). **Ojo:** el guion regenera también `ELEVENLABS_WEBHOOK_SECRET` y `ELEVENLABS_TOOL_SECRET` (`el-verificar.sh:24-34`). Se ejecuta **antes** de registrar esos dos secretos en ElevenLabs (abajo); ejecutarlo después rompería la post-llamada y las herramientas hasta registrarlos otra vez. *(Precisado el 08-11-2026, DOC8.)*
3. `ultron-studio:integrations-snapshot --verify-voice` (un GET) tiene que dar «credenciales válidas».
4. **Solo entonces** se revoca la clave vieja en la consola.
5. La configuración autorizada se reconoce otra vez, porque cambia la huella de la clave.

**Registrar en ElevenLabs los dos secretos propios y el webhook post-llamada, sin imprimirlos: `ops/h8-secrets-register.sh` (OPS8-c, 09-11-2026).** E1-12 generó en el servidor el secreto de las herramientas y el del webhook; ninguno está registrado y el de iniciación no existe todavía (lo genera el script).
- **Verificado en la referencia oficial el 09-11-2026** (sustituye a los «[por confirmar]» de OPS8-b; fuentes: `elevenlabs.io/docs/api-reference/workspace/secrets/{list,create,update}` y `…/webhooks/{list,create}`, y la guía `eleven-agents/workflows/post-call-webhooks`):
  - `GET /v1/convai/secrets?search=<prefijo>&page_size=…&cursor=…` → `{secrets: [{type: stored, secret_id, name, used_by}], next_cursor}`; `POST /v1/convai/secrets` `{type: new, name, value}` → `{secret_id}`; `PATCH /v1/convai/secrets/{secret_id}` `{type: update, name, value}` cambia el valor **sin cambiar el id** (los agentes que lo citan siguen valiendo).
  - `GET /v1/workspace/webhooks` y `POST /v1/workspace/webhooks` `{settings: {auth_type: hmac, name, webhook_url}}` → `{webhook_id, webhook_secret}`. **El secreto HMAC lo genera ElevenLabs** («the shared secret generated upon creation of the webhook») y solo lo enseña al crearlo: el script lo escribe en `ELEVENLABS_WEBHOOK_SECRET` (sustituye al de E1-12, que nunca se registró) sin pasar por un terminal.
  - El agente cita el webhook **por agente** (`platform_settings.workspace_overrides.webhooks.post_call_webhook_id`, que ya compila `ElevenLabsAgentMapper` con `ELEVENLABS_POST_CALL_WEBHOOK_REF`): el script no toca la configuración del workspace ni ningún agente. Un webhook que falla 10 veces seguidas sin una entrega buena en 7 días se desactiva solo.
- **Nombres fijos:** los secretos `ironbody-voz-herramientas` (valor `Bearer <ELEVENLABS_TOOL_SECRET>`: la cabecera `Authorization` entera que compara `VoiceToolAuth`) e `ironbody-voz-iniciacion` (valor `ELEVENLABS_INITIATION_SECRET`, la cabecera `X-Voice-Initiation-Secret` de `VoiceInitiationAuth`); el webhook `ironbody-voz-post-llamada` → `<VOICE_WEBHOOK_BASE_URL>/api/webhooks/voice/elevenlabs/post-call`.
- **Cómo lo hace:** las peticiones salen de un proceso PHP de `www-data` con la extensión curl (la clave, en una cabecera en memoria; nunca en la línea de órdenes, `ps` ni el historial); su salida va **directa** al escritor del fichero de entorno (python aislado, como root, escritura atómica que conserva dueño y modo), de modo que el secreto del webhook no pasa por la pantalla. Antes de crear nada lista por nombre: **nunca duplica**; si la lista falla, no crea nada. **Nunca borra** nada en ElevenLabs.
- **Uso:** primero `bash /root/h8-secrets-register.sh --solo-comprobar` (solo GET: dice qué existe y qué haría; código 3 si falta algo), después sin opciones. Si un secreto con su nombre ya existe con otro id que el del entorno, se para con «FALTA DECIDIR»: `--actualizar` sube el valor del entorno a ese id; `--reusar` escribe su id sin tocar el valor (solo si se sabe que el valor es el del entorno).
- **Permisos de la clave** (API keys de ElevenLabs, «Scope restriction»): `convai_read` y `convai_write` (secretos) y `webhooks_write` (webhooks) **[por confirmar en la consola qué permiso exige `GET /v1/workspace/webhooks`]**. La clave de Laravel no necesita `webhooks_write`: lo recomendable es **una clave de usuario temporal**, con caducidad de 15 minutos (la mínima de la consola) y solo esos permisos, en un fichero 600 de root, con `--clave-fichero=/root/el-registro.txt` (el script lo destruye al terminar).
- **Si la consola es la única vía para el webhook:** el propietario lo crea en la consola con el **nombre exacto** y la URL de arriba, ve su secreto una vez y, en su propia sesión SSH, ejecuta `bash /root/h8-secrets-register.sh --webhook-consola`: lo pega en un `read -rs` (sin eco, fuera del historial), y el script busca el id por nombre (GET) y escribe id y secreto. Nunca por el chat, una nota ni un ticket.
- **Ojo con `el-verificar.sh` después del registro:** regenera `ELEVENLABS_WEBHOOK_SECRET` y `ELEVENLABS_TOOL_SECRET` (`el-verificar.sh:24-34`). En la fase 2 se ejecuta **antes** del registro (rotación, arriba), y entonces no rompe nada. **Para una rotación posterior** rompería la post-llamada (ElevenLabs firma con el secreto que generó, que ya no estaría en el entorno; a los 10 fallos desactiva el webhook) y las herramientas hasta `--actualizar`. **[PENDIENTE: un `--solo-clave` en `el-verificar.sh` que no toque los dos secretos; dueño: lead/OPS8.]**
- **Después:** `voice:doctor` dice las piezas por nombre, sin valores; la configuración autorizada **se reconoce otra vez** (cambian las huellas de voz); el smoke compara los cuerpos con las huellas saladas de los secretos del servidor; un secreto que haya pasado por el chat se da por quemado y se rota.
- **Vuelta atrás:** el fichero de entorno, con la orden que imprime (`install` de la copia previa `/root/env-pre-voice-secretos-<fecha>` y la caché). Lo creado en ElevenLabs se queda: si hay que quitarlo, el propietario en la consola, sabiendo que un agente que lo cite deja de autenticar.

### OPS8-c (09-11-2026): los tres scripts de las fases 2 → 4a

Nuevo rumbo del propietario (09-11-2026): **prioridad a las salientes**. Ya importó en ElevenLabs el número empresarial de Iron Body como «Outbound Only» y una llamada real salió por Twilio (desde la consola de ElevenLabs, fuera de Laravel). Los tres scripts están en `ops/`, se copian a `/root` como los de la fase 1 y se ensayaron en el banco (`ops/banco-h8/ensayar-ops8c.sh`; cifras en `plans/informes-h8/informe-devops.md`, §OPS8-c). **Ninguno se ha ejecutado en el servidor.**

| Script | Qué hace | Red | Autorización | Vuelta atrás |
|---|---|---|---|---|
| `h8-voice-scope.sh` (el mando) | `estado` (sin red); `lab on/off`; `fase 2 set` (piezas NO secretas: `VOICE_WEBHOOK_BASE_URL`, `ELEVENLABS_AGENT_PHONE_NUMBER_ID`, `TWILIO_VOICE_FROM`, `TWILIO_VOICE_ACCOUNT_SID`, las tres `*_REF` y `VOICE_NUMBER_INBOUND_CAPABLE`); `fase 2 secreto <fichero 600>` (la key de Laravel y el token de firma de Twilio, nunca por argumentos); `salientes on/off`; `entrantes on/off`; `monitor on/off`; `publicar on/off`; `expectativa fase1/fase2/llamadas` | Solo `monitor on` (una conciliación **en seco**: lecturas a Twilio y ElevenLabs) | La de cada fase; **apagar no espera a nadie** (nivel 0 de D8-20) | La orden contraria («vuelta atrás funcional») o la exacta que imprime (la copia previa `/root/env-pre-voice-<orden>-<fecha>` y la caché) |
| `h8-secrets-register.sh` | Registra los dos secretos y crea el webhook post-llamada (arriba) | Sí: GET y, sin `--solo-comprobar`, POST/PATCH a ElevenLabs | **Fase 2, escrita** (crea recursos en la cuenta real) | El fichero, con la orden que imprime; lo creado en ElevenLabs se queda |
| `h8-twilio-check.sh` | Comprueba la cuenta y el número de la voz con la key restringida y da el **veredicto** «misma cuenta» frente a «subcuenta» (abajo) | Solo GET a Twilio | La de la fase 2 (lee la cuenta real) | No escribe nada |

```bash
# desde el Mac, con el repo en el commit que se va a usar
scp backend/docs/ultron-studio/ops/{h8-voice-scope.sh,h8-secrets-register.sh,h8-twilio-check.sh} ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/h8-voice-scope.sh /root/h8-secrets-register.sh /root/h8-twilio-check.sh && bash /root/h8-voice-scope.sh estado'
```

**Lo que el mando exige y comprueba en cada orden que escribe:** el fichero de entorno, un fichero normal (nunca un enlace) de `www-data` en 600 **o** de `root:www-data` en 640 (C-14: así sirve antes y después de la propuesta de `ironbody-migrate`); cada clave una sola vez; copia previa 600 de root que nunca se sobrescribe; solo las claves de la orden, en una escritura atómica que conserva dueño y modo; que parsea; `config:cache` como `www-data` con `umask 077`; que la configuración efectiva cambió **solo** en lo pedido y que el motor, el H4–H7 y ElevenLabs siguen igual; que el fichero es la copia salvo esas claves; el programador (±4 tareas de voz solo con `monitor`); `queue:restart` y los workers como antes (13, o 14 con `ironbody-voice`), dos lecturas; 401 sin token; 0 ERROR nuevos. «APLICADO» solo con 0 desvíos. **Se niega** (código 2, sin tocar nada): a `salientes on`/`entrantes on` sin la constante abierta en el código desplegado (en ejecución, una sola declaración anclada `= true;` y el último commit del fichero `voice(calls-open):`); a `salientes on` sin `ironbody-voice` en RUNNING, sin número, id importado, subcuenta y su key, con el número de WhatsApp o con un presupuesto que no cubre ni una llamada (la reserva de `VoiceCostGuard::reserveMicros`: 0,6105 US$ a un móvil sin transferencia con 300 s; 1,122 US$ a un fijo con transferencia); a `entrantes on` con el número declarado «Outbound Only»; a `lab on --driver=elevenlabs` sin las tres referencias y la base, o sin `ELEVENLABS_VERIFIED` en el Studio; a cambiar piezas con llamadas encendidas; a `monitor on` si la conciliación en seco echaría el freno (salvo `--acepta-freno`); a `expectativa <fase>` si `integrations-doctor` no la confirma.

### Fase 2a: solo el laboratorio hablado (sin teléfono)

**Criterio de entrada:** la fase 1 estable 24 h (desde el 10-11-2026 a las 12:06 UTC); el plan de pago de ElevenLabs con agentes (P-22); la clave rotada (E2-2); P-21 decidido; la **autorización escrita** de la 2a (coste: los minutos del laboratorio, dentro del techo). No hace falta Twilio, ni el número, ni la constante: **ninguna llamada telefónica**.

1. **Respaldo** con el bloque del paso 1 de la fase 1 (`BK=/var/backups/ironbody/ultron-studio-h8-fase2a-$TS`).
2. **Rotación de la clave de ElevenLabs** (E2-2, arriba): clave nueva con `convai_read` y `convai_write`, cupo de créditos y, si la consola lo admite, la IP del servidor en su lista → `bash /root/el-verificar.sh /root/el-clave.txt <agent_id>` → «ELEVENLABS VERIFICACIÓN TERMINADA» → revocar la vieja → reconocer la configuración.
3. **La base de los webhooks:** `bash /root/h8-voice-scope.sh fase 2 set VOICE_WEBHOOK_BASE_URL=https://api.ironbodyneiva.cloud` → `MANDO fase2-set APLICADO`.
4. **Secretos y webhook:** `bash /root/h8-secrets-register.sh --solo-comprobar` (esperado: código 3, «no existe: se crearía» en los tres y «se generaría» el de iniciación) → `bash /root/h8-secrets-register.sh --clave-fichero=/root/el-registro.txt` (la clave temporal de 15 min, si la de producción no tiene `webhooks_write`) → `SECRETOS Y WEBHOOK REGISTRADOS` → otra vez `--solo-comprobar`: `TODO REGISTRADO`.
5. **Reconocer la configuración autorizada** (Studio → Integraciones → Voz): cambiaron las huellas de voz.
6. `bash /root/h8-voice-scope.sh estado`: las tres referencias y la base presentes, ElevenLabs `ELEVENLABS_VERIFIED` en el Studio, todo lo demás apagado.
7. **Encender:** `bash /root/h8-voice-scope.sh lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=300` → `MANDO lab-on APLICADO` (techos del script: 5 US$ al día, 1 US$ por sesión, 10 sesiones, 300 s).
8. **Smoke:** `SMOKE_PHASE=2a bash /root/smoke-run-h8.sh` (abajo, «Smoke de las fases 2a y 4a»).
9. **Primera sesión** (el propietario, Super Admin con `ultron.voice_lab_run`): CRM → Studio → Voz → Laboratorio hablado, ≤ 5 min. Después: la sesión se cierra con el informe del proveedor (el oyente de E8-1), el coste queda dentro del presupuesto, ningún `voice.post_call.rejected` en `storage/logs/voice-*.log` (si aparece, el secreto del webhook no casa: paso 4) y el veredicto lo da **otra** identidad.
10. **Vuelta atrás:** `bash /root/h8-voice-scope.sh lab off` (bandera, driver `fake` y los dos presupuestos a 0: los cuatro cerrojos). Cada sesión crea un agente de laboratorio en ElevenLabs que se queda (E4-9, D8-7).

### Fase 4a-primero: salientes a canarios SIN pasar por la fase 3 (nuevo rumbo, 09-11-2026)

El contrato ponía la 4a después de la 3 («la fase 3 estable 24 h»). Hacer la 4a primero **cambia ese criterio de entrada**: lo decide el propietario por escrito y se registra como enmienda. Lo que no cambia es la puerta: **`OutboundPolicy` deniega, en este orden, si falta cualquiera de estas cosas** (`app/Services/Telephony/OutboundPolicy.php`; D8-12):

| # | La puerta pide | De dónde sale | Dueño |
|---|---|---|---|
| 1 | La constante abierta (`calls_disabled_in_code`) | El commit de una línea `voice(calls-open): …` con la autorización escrita citada, desplegado | Propietario + lead |
| 2 | Salientes y Twilio Voice encendidos (`outbound_disabled`, `twilio_voice_disabled`) | `salientes on` | El mando |
| 3 | El número y que no sea de WhatsApp (`no_voice_number`, `voice_number_is_whatsapp`) | `fase 2 set TWILIO_VOICE_FROM=…` | El mando (lo comprueba antes de escribir) |
| 4 | ElevenLabs verificado **en la telefonía** (`elevenlabs_unverified`) | El lector de la última verificación (E4-6), en curso en **B8-g**: hasta desplegarlo, toda saliente falla cerrada | B8-g + despliegue |
| 5 | La publicación canary vigente, sin deriva del agente y sin freno (`no_publication`, `publication_unreadable`, `agent_drift`, `voice_braked`) | Perfil de voz aprobado (cuatro ojos) + veredicto del laboratorio de **otra** identidad (puerta `required`) + `publicar on` + publicar el canario con su `expectedSpecHash` | Propietario y otra persona |
| 6 | El destino es un canario de voz **activo** (`not_an_active_voice_canary`) | El registro de canarios del H6 (fase 2 del H6: quien tiene el teléfono registra; otra persona autoriza y activa) | Propietario + segunda persona (P-24) |
| 7 | Horario legal y no festivo (`outside_hours`, `holiday`) | L-V 07:00–19:00, sábados 08:00–15:00 (Bogotá), sin domingos ni los 19 festivos | — |
| 8 | Frecuencia, concurrencia, cupo y presupuesto (`frequency_exceeded`, `concurrency_exceeded`, `daily_cap_zero`, `daily_cap_reached`, `budget_exhausted`) | 1 al día por contacto; `salientes on --cupo=1 --presupuesto=2.00` (la reserva de una llamada a un móvil es 0,6105 US$) | El mando |
| 9 | No duplicada (`duplicate_request`) | `client_request_id` del CRM | — |

**Además, fuera de la puerta:** el worker `ironbody-voice` (sin él, la saliente se queda en `requested`); la key restringida de Laravel en la cuenta de la voz (sin ella no hay corte de emergencia ni conciliación); el número importado en ElevenLabs **con una key restringida, nunca con el Account SID + Auth Token**; y, con un número «Outbound Only», **la declaración `VOICE_NUMBER_INBOUND_CAPABLE=false`** (código de B8-g/S8-d, en curso): sin ella, publicar el canario intenta asignarle el agente y la deriva del número (`voice:reconcile --numbers`) echaría el freno.

**Permisos de las keys de Twilio** (los nombres exactos de los PDF de permisos de las «Restricted API keys», leídos el 09-11-2026):
- **Laravel** (`TWILIO_VOICE_API_KEY_SID/SECRET`): `/twilio/voice/calls/list`, `/twilio/voice/calls/read`, `/twilio/voice/calls/update` (colgar), `twilio/phone-numbers/active-numbers/list`, `twilio/phone-numbers/active-numbers/read`, `/twilio/voice/outgoing-caller-ids/list` (para encontrar un caller ID verificado), `/twilio/billing/usage/read`; opcionales para la comprobación: `/twilio/iam/accounts/read` y `twilio/phone-numbers/regulatory-compliance.bundles/read`. **Ninguno de `twilio/messaging/*`**.
- **ElevenLabs** (la de la importación): lo que pida su integración **[por confirmar con la cuenta real: crear, leer, listar y actualizar llamadas, y leer números y caller ID]**; para entrantes ElevenLabs pide además el Auth Token de la cuenta (`account_auth_token`, referencia `phone-numbers/create`), que con un número «Outbound Only» no hace falta.

**El orden exacto** (cada paso, con su autorización y su verificación antes del siguiente):
1. **Rotar la clave de ElevenLabs** (E2-2; fase 2a, paso 2).
2. **La base y el registro de secretos y webhook** (fase 2a, pasos 3–5). *La base va antes del registro porque la URL del webhook la necesita.*
3. **Las variables de Twilio y del número:**
   - el propietario crea las keys restringidas (arriba) en la cuenta que decida el veredicto (abajo) y deja en `/root/tw-voz.txt` (600, root) `TWILIO_VOICE_API_KEY_SID=…`, `TWILIO_VOICE_API_KEY_SECRET=…` y, si la quiere, `TWILIO_VOICE_SIGNING_TOKEN=…` → `bash /root/h8-voice-scope.sh fase 2 secreto /root/tw-voz.txt` (lo destruye al aplicar);
   - `bash /root/h8-voice-scope.sh fase 2 set TWILIO_VOICE_ACCOUNT_SID=AC… TWILIO_VOICE_FROM=+57… ELEVENLABS_AGENT_PHONE_NUMBER_ID=…` y, con el código de B8-g/S8-d desplegado, `VOICE_NUMBER_INBOUND_CAPABLE=false`;
   - `bash /root/h8-twilio-check.sh` → el veredicto (abajo) y ningún FALLO;
   - reconocer la configuración autorizada; `bash /root/h8-voice-scope.sh expectativa fase2` si el monitor del H7 está encendido.
4. **El mando:** `monitor on` (la conciliación; **la llamada de prueba del 09-11 cae dentro de su ventana de 48 h hasta el 11-11 a la misma hora**: antes de eso, el mando se niega y habría que encender con `--acepta-freno` y soltar el freno con la nota de la causa), `publicar on`, `lab on` (fase 2a).
5. **Laboratorio y publicación:** primera sesión y veredicto de otra identidad (fase 2a, 9) → perfil de voz aprobado → publicar el canario (CRM → Studio → Voz → Publicar, con su `expectedSpecHash`) → `estado`: cadena `canary:ok`.
6. **Llamadas:** el commit `voice(calls-open)` desplegado (con el despliegue del código de B8-g/S8-d) → instalar `ironbody-voice` (`docs/ops/supervisor/ironbody-voice.conf`; antes, `ensayar.sh worker comprobar` en el banco con ese código: 14 RUNNING) → `bash /root/h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00` → `SMOKE_PHASE=4a bash /root/smoke-run-h8.sh` → **la primera saliente, al propietario como canario**, desde el CRM («Voz y telefonía» → llamada saliente), en horario.
7. **Después de la llamada:** la fila en `voice_calls` pasa `requested → dialing → active → completed`; la post-llamada procesada (evento `guard_verdict`, sin violaciones) y su coste; `voice:reconcile --dry-run --json` con `unknown` 0 y sin freno; en el registro de voz, ningún rechazo. Con el cupo 1, una segunda petición el mismo día da `daily_cap_reached`: es lo esperado.

**Vuelta atrás de la 4a:** nivel 0 `bash /root/h8-voice-scope.sh salientes off` (cupo y presupuesto a 0; Twilio Voice apagado si las entrantes lo están); 0b `sudo -u www-data php artisan voice:hangup --all --confirm`; 1 revertir la publicación (Studio); 2 voz apagada; 3 revocar las keys en las consolas y quitar la importación del número; 4 el código anterior (la constante cerrada vuelve con él).

### Smoke de las fases 2a y 4a (`SMOKE_PHASE`)

`SMOKE_PHASE=1` (por defecto) es el de la fase 1 sin cambios. Con `2a` o `4a`, el lanzador y `smoke-h8.py` comprueban el estado de su fase y **siguen sin filas positivas**: con su bandera encendida no abren, cierran ni juzgan sesiones del laboratorio y no publican ni promueven (OMITIDO, con el motivo), porque escriben o gastan.

| | 2a (laboratorio) | 4a (salientes a canarios) |
|---|---|---|
| Configuración efectiva | constante cerrada; entrantes, salientes, Twilio Voice y publicar apagados; laboratorio encendido | constante abierta; salientes, Twilio Voice, publicar y monitor de voz encendidos; entrantes apagadas |
| Programador | +4 de voz si el monitor de voz está encendido; +2 del Studio si lo está el del H7 | Ídem (el de voz, encendido) |
| `voice:doctor --telefonia` | cadena `none/none`, freno suelto, cupos `0/0/0` | cadena `canary:ok,rest:none`, freno suelto, salientes ≥ 1 con presupuesto, entrantes 0, número y subcuenta |
| `GET admin/voice/status` | banderas de la fase; piezas de la fase 2, informativas | banderas de la fase; número, id importado y subcuenta puestos, sin WhatsApp |
| Studio | laboratorio: lista 200 e inventada 404; sus 3 POST, OMITIDOS; publicar y promover 409 | publicar y promover, OMITIDOS; `voice/publications` con publicar encendido y ≥ 1 entrada |
| Canal `voice` | los 4 rechazos y, con el monitor de voz, sus tareas (`voice.reconcile*`, `voice.prune*`) | ídem |
| Código esperado | 3 (los OMITIDOS) y 0 FALLO | 3 (los OMITIDOS) y 0 FALLO |

### El número empresarial y la cuenta de Twilio: ¿se reutiliza sin comprar ni crear subcuenta?

`bash /root/h8-twilio-check.sh` lo responde con la key restringida y da el veredicto. Lo que dice la documentación oficial (leída el 09-11-2026):
- **«Outbound Only» en ElevenLabs = un caller ID verificado en Twilio** («Ideal for using your existing business number for outbound AI calls»): sirve para **salientes sin comprar nada**, pero **no recibe llamadas ni se asigna a un agente**. La fase 3 (entrantes) exigiría un número comprado o portado. *Fuente: `elevenlabs.io/docs/eleven-agents/phone-numbers/twilio-integration/native-integration`.*
- **Misma cuenta con keys restringidas:** técnicamente posible, pero **no es lo que pide el contrato** (D8-4, D8-5) y tiene costes concretos: la conciliación lista **todas** las llamadas de la cuenta (48 h, sin filtrar por número), así que toda llamada de voz que no pida Laravel echa el freno; validar los avisos de gasto de voz exigiría el Auth Token **maestro** (el del OTP) en `TWILIO_VOICE_SIGNING_TOKEN` (se deja vacío: sin avisos de Twilio para la voz); los techos de gasto de Twilio se comparten con el OTP. Solo sería aceptable con 0 llamadas ajenas, la importación de ElevenLabs hecha con una key `SK…` restringida, el token de firma vacío y una **enmienda del contrato con el visto bueno de security**.
- **Subcuenta (recomendada):** crearla no tiene coste propio (el uso se factura a la cuenta madre; *fuente: `twilio.com/docs/iam/api/subaccounts`, «Billing»*). Las keys se crean **dentro** de la subcuenta («Main account API Keys are only available to access main account resources»). Y el número **no se compra**:
  - **si es un caller ID verificado** (el caso de hoy), no se traslada: se **verifica otra vez en la subcuenta** (`POST /2010-04-01/Accounts/{SUBCUENTA}/OutgoingCallerIds.json` con `PhoneNumber`: Twilio llama al número del negocio y se teclea el código de 6 dígitos; *fuente: `twilio.com/docs/voice/api/outgoing-caller-ids`*), se reimporta en ElevenLabs con la key de la subcuenta y se quita la importación vieja;
  - **si fuera un número comprado**, se traslada con `POST /2010-04-01/Accounts/{MADRE}/IncomingPhoneNumbers/{PN…}.json` y `AccountSid={SUBCUENTA}`, con las credenciales de la cuenta madre; si exige dirección, se crea antes en la subcuenta; Trust Hub y A2P se reenvían; un remitente de WhatsApp conectado no se mueve. *Fuente: `twilio.com/docs/iam/api/subaccounts#exchanging-numbers`.*
- **Lo que el script no puede ver:** con qué credencial importó el propietario el número en ElevenLabs. Si fue con el **Account SID + Auth Token** de la cuenta madre, ElevenLabs tiene la credencial maestra del OTP: hay que **reimportarlo con una key `SK…` restringida** y, tras B-6 (el OTP en su key restringida), rotar el Auth Token. Pregunta para el propietario antes de cualquier fase.
- **La llamada de prueba del 09-11:** salió por la cuenta de Twilio fuera de Laravel. Si esa cuenta es la de la voz, la primera conciliación dentro de las 48 h siguientes la vería como `unknown_provider_call` y echaría el freno: `monitor on` lo comprueba en seco y se niega.

### Política de respaldos del H8 (E3-7)

- **Restauración completa** (`pg_dump -Fc` → `pg_restore`, esquema y datos): funciona, porque los disparadores se crean después de los datos. Es la vía por defecto, siempre a una **base aparte** y nunca encima de la viva, con dueños (sin `--no-owner`) y coordinada por `database-specialist` con autorización (`docs/ops/BACKUPS.md`, «Restauración real»).
- **Restauración de solo datos** sobre un esquema ya migrado: **falla**, porque los disparadores de «nace en su estado inicial» rechazan llamadas, perfiles, agentes y sesiones en estados posteriores (R-2 de DB8). Solo se hace con `pg_restore --data-only --disable-triggers` como superusuario, sabiendo que durante la carga **no hay ninguna guarda**: ni las de solo inserción, ni las de transiciones, ni las de la cadena de voz. Después, `h8-schema-check.sql` tiene que dar `disparadores_apagados` 0 y la cadena de voz tiene que verificar.
- **Nunca `migrate:rollback` en producción:** `down()` se niega con datos y deja las tablas (D8-20, nivel 4).
- Cada fase lleva su respaldo con el bloque del paso 1 (`ultron-studio-h8-<fase>-<fecha>`), de menos de 2 horas. **Nunca se borra un respaldo.** Una supresión de la Ley 1581 hecha después obliga a repetirla sobre cualquier respaldo restaurado.

### Vuelta atrás de la fase 1

**No hay bandera que bajar:** todo está apagado.

**Código** (solo si producción sigue en el commit del H8; si se desplegó otra cosa después, se planea un revert con devops):

```bash
BK=/var/backups/ironbody/ultron-studio-h8-fase1-<fecha UTC>; FASE1=<commit-backend>
[ "$(git -C /var/www/api rev-parse HEAD)" = "$(git -C /var/www/api rev-parse "$FASE1")" ] || { echo "producción ya no está en el commit del H8: no hacer checkout"; exit 1; }
( cd /var/www/api && umask 022 && git checkout -q --detach "$(cat "$BK/api-commit.txt")" )      # hoy, 32a2560
cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
stat -c '%U %a %F' bootstrap/cache/config.php bootstrap/cache/routes-v7.php                      # www-data 600 regular file
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 56
supervisorctl status | grep -c RUNNING                                                            # 13
```

- **Lo que no vuelve:** las 11 tablas quedan vacías y sin lector; no molestan. Quitarlas es de `database-specialist`, con autorización y nunca con `migrate:rollback`. Las 9 variables quedan y el código anterior no las lee; si se quieren fuera, se restaura `env.backup` (mismo dueño y modo) y `config:cache`.
- **CRM:** la orden que imprimió `deploy-h8-crm-fase1.sh`, que restaura `index.html` y `version.json`; o `tar -xzpf <BK>/app.tgz -C /var/www`.
- **Volver al H8:** `git checkout -q main`, las mismas cachés, `queue:restart` y el smoke del H8.

### Incidencias previsibles durante la fase 1

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `origin/main no es avance rápido desde 32a2560` | La rama reescrita sin volver a unir `origin/main` (bloqueante 1) | El lead une `origin/main` en la rama (merge) y empuja con avance rápido |
| `commits ajenos al H8 … que producción no tiene: …` (o `FALTA DECIDIR` en el ensayo) | `7c84cb5` u otro trabajo ajeno sin desplegar (bloqueante 2) | Lo despliega su dueño antes, o el propietario lo acepta al desplegar con `--acepta-main=<commits>` |
| `migraciones pendientes en origin/main frente a la base` | Migraciones ajenas sin aplicar en producción | `ironbody-migrate` las aplicaría todas (lección del H7): se despliegan antes por su vía |
| `las dos migraciones del H8 YA están aplicadas` (AVISO) | Un `migrate` ajeno las aplicó | No es un fallo: el script no vuelve a migrar y comprueba el esquema |
| `el Studio no afirma ELEVENLABS_VERIFIED` | La verificación tiene más de 30 días (paso 10) | Renovarla (un GET, con autorización) y repetir |
| `… está en el entorno: es una pieza de la fase 2` | Alguien puso ya una pieza del H8 o el número | Aclararlo con el propietario: cambiaría la huella de voz |
| 55P03 en la migración | Un escritor de `ultron_studio_versions` (alguien guardando en el Studio) | El script reintenta 3 veces. Si se agota, `h8-schema-check.sql` para ver quién y **relanzar el mismo comando** (reanuda) |
| El login del smoke da 429 | Más de 8 logins por minuto desde la IP del servidor. *(Corregido el 08-11-2026: desde B8-c las peticiones anónimas a la telefonía ya no cuentan en ese cubo; bloqueante 4, resuelto)* | Esperar un minuto. La cuenta queda en reposo |
| El smoke informa de que nginx corta la post-llamada de 1 MB | `client_max_body_size` menor que el tope | Subirlo antes de la fase 3 (cambio de nginx con autorización: `nginx -t` y `reload`) |

## Fases siguientes (no incluidas aquí)

*(Actualización del 02-11-2026: el Hito 6 se ejecutó en su fase 1 el 02-11-2026, y el Hito 7 tiene su runbook en «Hito 7», más arriba, sin ejecutar.)*

Campañas Meta (solo lectura), Twilio Voice y números canarios (tabla auditada), adaptador de ElevenLabs tras bandera. Cada una con su migración revisada en PostgreSQL, su suite y su plan de reversión. (La persistencia, que también figuraba en esta lista, se desplegó con el Hito F el 18-10-2026; el laboratorio y la evaluación con OpenAI tienen su runbook en «Hito 4», más arriba, y la publicación, el canario y la reversión por versión, en «Hito 5». Actualizado el 31-10-2026.) *(Actualización del 01-11-2026: las campañas de Meta en lectura, los números canarios, la sonda de Twilio y el adaptador de ElevenLabs sin activar tienen su runbook en «Hito 6», más arriba, pendiente de ejecución. Twilio Voice y las llamadas siguen fuera: los trae el hito de voz, con autorización expresa.)*
