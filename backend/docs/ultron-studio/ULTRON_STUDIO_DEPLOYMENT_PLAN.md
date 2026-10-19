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

## Fases siguientes (no incluidas aquí)

Laboratorio, evaluación con OpenAI (clave propia), campañas Meta (solo lectura), Twilio Voice y números canarios (tabla auditada), adaptador de ElevenLabs tras bandera. Cada una con su migración revisada en PostgreSQL, su suite y su plan de reversión. (La persistencia, que también figuraba en esta lista, se desplegó con el Hito F el 18-10-2026.)
