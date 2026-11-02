# ULTRON STUDIO: reversión

## Vista previa visual (Hito D)

La vista previa es solo frontend y no guarda nada en el servidor: revertirla no deja datos que deshacer.

| Situación | Qué hacer | Tiempo |
|---|---|---|
| **Hay que retirarla del todo** | Copiar `index.html` y `version.json` del respaldo **`/var/backups/ironbody/crm-pre-ultron-studio-20261018-025639/`** (publicación del 18-10-2026, producción anterior `4334dd8`, `main-VR37CLFV.js`) sobre `/var/www/app` y `chown root:root`. Los chunks de la versión anterior siguen en disco (se publicó sin `--delete`). | 1 minuto |
| **Hay que dejar el CRM nuevo pero sin el Studio** | Poner `ultronStudioPreviewEnabled: false` en `environment.prod.ts`, `npm run build:prod` y publicar igual que siempre. La ruta y el submenú dejan de existir; el chunk queda en disco sin que nadie lo cargue. | 10 minutos |
| **Hay que quitar la dependencia** | Revertir el commit del Studio (`git revert`), que devuelve `package.json` y `package-lock.json` a su estado anterior, `npm ci` y publicar. | 15 minutos |

**Lo que queda en los navegadores:** el borrador de cada persona en `localStorage` (`ironbody.ultronStudio.preview.v1.<id>`). Es inofensivo (posiciones, aristas y parámetros de demostración), no lo lee ninguna otra pantalla y se puede borrar desde el navegador. Si en una fase siguiente cambia el formato, el almacén lo descarta solo (`v` distinto de 1 → línea base).

**Verificación tras revertir:** `version.json` público con el commit esperado, `/marketing/ultron-studio` ya no muestra el tablero (redirige al inicio o enseña el CRM sin el submenú), 0 errores en consola al navegar por Mercadeo.

## Hito F (persistencia) — desplegado el 18-10-2026; respaldo `/var/backups/ironbody/ultron-studio-f-20261018-151812/` (dump completo de la base, CRM anterior `main-GKAYY7VU.js`/`4f7d774`, entorno con el rol `iron`, caché de configuración y commit `84319be`)

| Situación | Qué hacer | Pierde datos |
|---|---|---|
| **Recién migrado, sin uso real** (ninguna versión creada) | `php artisan migrate:rollback --step=2` (quita disparadores, función, CHECK y las cinco tablas en orden inverso; verificado en PG 14 y SQLite), después el código anterior (`git checkout <commit anterior>` en `/var/www/api`), `config:cache` + `chown www-data`, y el CRM anterior desde el respaldo de `index.html`/`version.json` | Solo las 12 reglas importadas (se reimportan) |
| **Ya en uso** (borradores, versiones, auditoría) | **No se revierten las migraciones**: la auditoría es de solo inserción y revertirla destruiría historia. Se retira el Studio apagando la bandera `ultronStudioPreviewEnabled` del CRM (una línea + publicación) o publicando el CRM anterior; la API queda en su sitio pero sin interfaz. Si hace falta cerrar también la API: quitar el montaje de `routes/ultron-studio.php` en `bootstrap/app.php` y `route:cache` | Nada |
| **Una versión aprobada por error** | No hay transición hacia atrás por diseño: se crea otra versión y se rechaza la anterior en la revisión siguiente; el libro conserva ambas | Nada |

**Verificación tras revertir:** `php artisan auth:coverage --unmapped` vacío; `\dt ultron_studio_*` según el caso; el CRM publicado sin el submenú (o con él, si solo se cerró la API: entonces el Studio enseña la fase de error con «Reintentar», nunca datos inventados).

## Hito G (control funcional) — PENDIENTE DE DESPLIEGUE; respaldo `/var/backups/ironbody/ultron-studio-g-<fecha UTC>/` (lo crea el paso 2 del runbook)

**Estado:** G no está desplegado y ningún paso de esta sección se ha ejecutado. Es el plan. *(Así se escribió. **Actualización del 19-10-2026:** G se desplegó ese día, 06:00–06:04 UTC; sus respaldos reales están al final de este documento. La vuelta atrás de G sigue sin ejecutarse.)* El respaldo guarda el commit del backend (`api-commit.txt`), el volcado de la base, el CRM publicado (`app-crm.tgz`), el `.env` (modo 600) y la caché de `bootstrap/cache`.

| Situación | Qué hacer | Pierde datos |
|---|---|---|
| **Falla solo el CRM de G** (el backend de G va bien) | Restaurar `index.html` y `version.json` del CRM anterior (`020db86`) desde `app-crm.tgz` sobre `/var/www/app`, y `chown root:root`. Los chunks anteriores siguen en disco, porque se publica sin `--delete`. El CRM de F funciona con el backend de G | Nada |
| **Hay que volver el backend a F** | **Primero el CRM**, como en la fila anterior: el CRM de G con el backend de F se queda en fase `error`. Después, en `/var/www/api`, volver a `9acddc3` (`git checkout 9acddc3` o un merge inverso); `config:cache` y `route:cache` como `www-data`, con `chmod 600`, y `queue:restart`. Comprobar que vuelven a ser 19 rutas del Studio | Nada en la base (ver abajo) |
| **Una versión aprobada por error, o una regla que sobra** | Como en F: no hay transición hacia atrás. Se crea otra versión, y la regla del Studio se archiva (no se borra) | Nada |

**Qué queda en la base si el backend vuelve a F.** Lo describe el contrato (§A7, «Compatibilidad»); **la vuelta de G a F no se ha ejecutado nunca**, ni en local:
- **La columna `conditions` se queda.** F la ignora: publica las reglas campo a campo, y su parser estricto no la ve.
- **No se revierte la migración.** `down()` borra las condiciones, y volver a F no lo necesita. Solo con autorización explícita y con el rol dueño: `php artisan migrate:rollback --step=1 --database=pgsql_owner`.
- **Las versiones de formato 2** (las creadas con G) darían `integrityOk = false` con el código de F, porque su huella incluye `conditions` y `format`: se leen, pero no se envían ni se aprueban. Las de formato 1 siguen como siempre.
- **Las 8 R-xx que sincronizó G** (revisión 2) ya no coinciden con la transcripción de F. El `import-baseline` de F las resincroniza, y queda auditado.
- **Vuelve M-2 a las rutas de F.** El código de F no tiene la validación en dos fases ni el tope de cuerpo de 64 KB, que cerraban también `layers.*`, `refs.*` y `acknowledgedWarnings.*`. Es la exposición que tenía producción antes de G (`ULTRON_STUDIO_SECURITY_REPORT.md`, «Hito G»).

**Verificación tras revertir:**
- `route:list --path=admin/ultron-studio` da 19 rutas, y `auth:coverage --unmapped` sale vacío;
- el `version.json` público trae el commit esperado;
- con el CRM de F, el Studio abre en fase `ready`, no en `error`;
- 12 workers RUNNING, ninguno en BACKOFF.

## Hito 4 (laboratorio de IA) — desplegado en fases 1–2 el 25-10-2026 (01:15–01:25 UTC); respaldo `/var/backups/ironbody/ultron-studio-h4-20261025-011511/`

**Estado:** el Hito 4 no está desplegado y ningún paso de esta sección se ha ejecutado, ni en local. *(Así se escribió. **Actualización del 31-10-2026:** el Hito 4 se desplegó en las fases 1 y 2 el 25-10-2026, entre las 01:15 y las 01:25 UTC: backend `fef7c87`, CRM `5c1dbd7`, laboratorio en `fake` con presupuesto 0 y sin clave; la fase 3 no se ha ejecutado. Sus respaldos reales están al final de este documento. No consta que se haya ejecutado ninguna vuelta atrás de esta sección. **Ojo:** desde entonces producción avanzó con trabajo de otros módulos (API en `7318122` el 31-10-2026), así que volver el backend a G con `git checkout e651dd9` (o a `d86a2b5`, como dice el registro del final) desharía también ese trabajo, sin deshacer sus migraciones. Si alguna vez hace falta, se decide con `devops`: no está ensayado.)* Es el plan, a partir del borrador del lead (`scratchpad/hito-4/deploy-h4.md`) y del informe del database-specialist. El respaldo guarda el commit del backend (`api-commit.txt`), el volcado de la base, el CRM publicado (`app-crm.tgz` y `crm-main.txt`), el `.env` (modo 600), la caché de `bootstrap/cache` y una copia de `/etc/supervisor/conf.d/`.

**Regla que no cambia: nunca `migrate:rollback` (`down()`) en producción.** El `down()` de las dos migraciones borra escenarios, ejecuciones, veredictos y libro: `DROP` no pasa por los disparadores de fila. Las tablas se quedan aunque el código vuelva a G: el código de G no las nombra (frontera de aislamiento) y vacías no cuestan nada. Retirar el laboratorio es apagar la bandera.

| Situación | Qué hacer | Pierde datos |
|---|---|---|
| **Parar el laboratorio ya** (gasto inesperado, fallo del proveedor, cualquier duda), en cualquier fase | `ULTRON_STUDIO_LAB_ENABLED=false` en `backend/.env`; `sudo -u www-data php backend/artisan config:cache && chmod 600 backend/bootstrap/cache/config.php`; `supervisorctl restart 'ironbody-lab:*'`. Desde ese momento todas las rutas `lab/*` responden 409 `lab_disabled` (salvo `GET lab/status`, 200 apagado) y el siguiente paso de cada ejecución viva la cierra `stopped: lab_disabled`. La llamada que esté en vuelo termina y se cobra (como mucho, un caso). **No basta con `config:cache`:** el worker se queda con la configuración que leyó al arrancar hasta que se reinicia | Nada: lo ya ejecutado queda en el libro |
| **Volver a la fase 2 desde la 3** (sin OpenAI, pero con el laboratorio en `fake`) | `ULTRON_STUDIO_LAB_DRIVER=fake` y, si se quiere, quitar la clave del `.env`; `config:cache` como `www-data` y reiniciar `ironbody-lab:*`. Las ejecuciones `openai` vivas se cierran `stopped: not_configured` en su siguiente paso | Nada |
| **Falla solo el CRM del Hito 4** (el backend va bien) | Restaurar `index.html` y `version.json` del CRM anterior (`44c0e8c`, `main-JNV5BHFD.js`) desde `app-crm.tgz` sobre `/var/www/app` y `chown root:root`. Los chunks anteriores siguen en disco, porque se publica sin `--delete`. El CRM de G funciona con el backend del Hito 4: tolera las llaves nuevas (comprobado con pruebas, no en producción) | Nada |
| **Hay que volver el backend a G** | **Primero el CRM**, como en la fila anterior. Después: (1) bandera apagada, `config:cache` como `www-data` y `supervisorctl restart 'ironbody-lab:*'`; (2) dejar que el worker cierre las ejecuciones vivas: con la bandera apagada, la API no deja cancelar (409), pero el siguiente paso de cada una la cierra `stopped: lab_disabled`. Esperar a que `SELECT count(*) FROM ultron_studio_lab_runs WHERE status IN ('queued', 'running');` dé 0. Una ejecución huérfana (H-P2-1) no se cierra sola: ver «Incidencias conocidas del laboratorio» en el runbook; (3) `supervisorctl stop 'ironbody-lab:*'`, quitar `/etc/supervisor/conf.d/ironbody-lab.conf` y `supervisorctl reread && supervisorctl update`; (4) en `/var/www/api`, volver a `e651dd9` (`git checkout e651dd9` o un merge inverso); (5) `config:cache` y `route:cache` como `www-data`, con `chmod 600`, y `queue:restart`; (6) comprobar que vuelven a ser 21 rutas del Studio | Nada en la base: las tablas y sus filas se quedan |
| **Un veredicto o un escenario por error** | No hay transición hacia atrás por diseño: el veredicto es inmutable y uno por ejecución (se lanza otra ejecución y se emite otro veredicto), y el escenario se archiva (no se borra) | Nada |

**Qué queda si el backend vuelve a G** (inferido del código; **no se ha ejecutado nunca**, ni en local):
- Las 5 tablas `ultron_studio_lab_*`, sus 16 disparadores, los 13 CHECK y la función `ultron_studio_lab_forbid()` se quedan. El código de G no las lee ni las escribe.
- Las variables `ULTRON_STUDIO_LAB_*` y `QUEUE_STUDIO_LAB*` del `.env` se pueden quedar: el código de G no las lee. Si se quitan, se compara contra `env.backup` sin imprimir el fichero.
- Si se concedió `ultron.lab_run` a mano en Roles, esa excepción queda en `role_permissions`. El código de G no conoce la llave: qué hace con ella no se ha probado; el CRM de G tolera llaves desconocidas.
- Los pasos del laboratorio que quedaran en `jobs` (cola `studio-lab`) no los atiende nadie sin `ironbody-lab`, y nadie los vería: en G el carril `lab` no existe y `queue:health` no lo vigila. Por eso se cierran las ejecuciones antes de retirar el worker, y antes de volver el código se comprueba `SELECT count(*) FROM jobs WHERE queue = 'studio-lab';` → 0.

**Solo con autorización expresa del usuario, y nunca como paso rutinario:** retirar las tablas con `php artisan migrate:rollback --step=2 --database=pgsql_owner --force` y el rol dueño, **sabiendo que borra todo el laboratorio**. Antes, `migrate:status` para confirmar que `2026_10_19_100002` y `2026_10_19_100003` son las dos últimas (si no, `--step=2` revertiría otras), y un respaldo nuevo de la base.

**Verificación tras revertir:**
- con la bandera apagada: `GET lab/status` responde 200 con `enabled: false`, y cualquier otra ruta `lab/*`, 409 `lab_disabled`;
- con el backend en G: `route:list --path=admin/ultron-studio` da 21 rutas y `auth:coverage --unmapped` sale vacío;
- el `version.json` público trae el commit esperado y el Studio abre en fase `ready`;
- 12 workers RUNNING (13 si `ironbody-lab` sigue instalado), ninguno en BACKOFF.

## Hito 5 (gobierno y versionado) — FASE 1 DESPLEGADA el 01-11-2026; respaldo `/var/backups/ironbody/ultron-studio-h5-20261101-011245/` (y CRM `crm-pre-ab78a6a-20261101-011457/`)

**Estado (01-11-2026):** fase 1 desplegada el 01-11-2026 01:12–01:16 UTC: producción sirve la API en `7ba0ce8` y el CRM en `ab78a6a`, con el motor en `off` y publicar en 409; fases 2 y 3 pendientes. *(Hasta el 31-10-2026: API `7318122`, CRM `4105016`, nada ejecutado.)* DevOps ensayó en local la vuelta del código a `fef7c87` con datos de H5 y la rotación de la `APP_KEY` (`plans/informes-h5/informe-devops-h5.md` §7.2 y §8). La vuelta a `7318122` no se ha ensayado; el código del Studio de los dos commits es el mismo (diff vacío en sus ficheros, comprobado el 31-10-2026). El resto se deduce del código y de las pruebas. Para quien opera, con o sin acceso al código.

**Reglas que no cambian:**
- **Nunca `migrate:rollback` / `down()` en producción.** Con filas de H5 se niega (contrato §A3.4) y, sin ellas, borraría la estructura de la cadena. Volver al código de H4 no lo necesita (§A3.5).
- **No se quitan las 7 líneas del Hito 5** del fichero de entorno: el código de H4 no las lee.
- **En la opción A, ninguna palanca de esta sección cambia lo que ULTRON dice o hace:** cambian el sello y la declaración. La emergencia real del agente se gobierna con su pausa y su freno, que el Studio no toca.

### Vuelta atrás por fase (inmediata; no toca código ni datos del motor)

`h5-engine-scope.sh` se ejecuta en el servidor como root, **con la autorización del propietario**, y exige como segundo argumento el alcance que hay HOY: si la configuración efectiva no coincide, no toca nada. Antes de escribir deja una copia del fichero de entorno en `/root/env-pre-scope-<alcance>-<fecha>` (root, 600) y, si algo sale mal, imprime la orden exacta para volver.

| Desde → hacia | Qué hacer | Qué queda |
|---|---|---|
| Fase 3 → 2 | `bash /root/h5-engine-scope.sh canary all` | La canaria sigue sellando; el resto sale `out_of_scope`. La cadena sigue declarando lo publicado |
| Fase 2 o 3 → 1 (**parar de leer**) | `bash /root/h5-engine-scope.sh off canary` (o `off all`) | El motor deja de consultar la cadena y de sellar (Legacy exacto). Publicar y promover responden 409. La cadena queda como estaba: una versión en `CANARY` sigue en `CANARY` |
| **Parar de declarar** | CRM, barra de la publicación: «Volver a Legacy» (`POST /api/admin/ultron-studio/publications/legacy`, llave `ultron.rollback`, motivo de 5 a 500 caracteres) | La `CANARY` pasa a `ROLLED_BACK` y la `PUBLISHED`, a `RETIRED`. Funciona sin las llaves de entorno, con cualquier alcance del motor y con una cabeza que no verifica |
| Una versión concreta | CRM, detalle de la versión: «Revertir» (`POST /api/admin/ultron-studio/versions/{uuid}/rollback`, llave `ultron.rollback`, motivo de 5 a 500 caracteres) | `CANARY` → `ROLLED_BACK`: el canario vuelve a lo que tenga el resto. `PUBLISHED` → `ROLLED_BACK`, y vuelve la anterior `RETIRED` si su entrada y su huella verifican; si no había anterior, la cadena queda en Legacy; si no verifica, 409 `restore_integrity_mismatch` y la salida es «Volver a Legacy». Una versión que esté a la vez en `CANARY` conserva su parte (Q-2 de la Enmienda 1). Sin llaves de entorno, sin cuatro ojos y sin ventana |

`off → all` directo no se admite, y `canary`/`all` exigen el canario del motor configurado. **`canary` y `all` abren también la llave de publicar** (N-9): para que el motor selle sin publicaciones nuevas hay que poner a mano `ULTRON_STUDIO_PUBLISHING_ENABLED=false` y regenerar la caché como hace el script.

**Verificación:** el script termina en `ALCANCE <alcance> APLICADO (copia previa: …)` (código 0) tras comprobar la configuración efectiva, 13 workers RUNNING y 0 en BACKOFF/FATAL, `status` sin token → 401 y `ultron-studio:publication-check` con código 0. Después, `SMOKE_PHASE=<fase> bash /root/smoke-run-h5.sh` (ver `ops/README.md`), copiando antes `smoke-h5.py` y `smoke-run-h5.sh` a `/root/`: el lanzador los borra al terminar cada ejecución.
- **Al volver a la fase 1 con la cadena ya escrita, el smoke de la fase 1 no sirve:** espera la cadena vacía, ninguna versión en `CANARY`, `PUBLISHED`, `RETIRED` o `ROLLED_BACK` y ninguna cabeza, así que da 4 FALLO por diseño y omite Legacy (ensayo de DevOps §10, «Fase 1 con la cadena no vacía»). En ese caso se verifica con la salida del script, `publication-check` y la consulta de DevOps §5, `SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';`, que con la llave en `off` no crece aunque haya turnos nuevos (contrato A4.3: sin consulta y sin sello).

### Vuelta atrás del código: backend de H5 → el commit anterior (`api-commit.txt` del respaldo; hoy, `7318122`), con las tablas en su sitio

Solo si el código de H5 se porta mal (por ejemplo, errores en la ruta del commit del motor). **Volver el código no es volver los datos.** El destino es lo que servía producción justo antes del Hito 5: el commit que guardó el respaldo de la fase 1 en `api-commit.txt`, que hoy empieza por `7318122` (el `PREVIO` de `deploy-h5-backend-fase1.sh`; hasta el 29-10-2026 era `fef7c87`). **Se lee del respaldo, nunca de un literal** (I-4 del reviewer). Hasta el 31-10-2026 este procedimiento mandaba volver a `fef7c87`, y eso dejaría aplicadas las 9 migraciones de `origin/main` sin el código que las usa y quitaría de producción sus funciones: correcciones de caja, planes archivados (sin la guarda `plan_archived`, un plan archivado volvería a poder cobrarse por enlace), segundo factor biométrico, transferencias de membresía y potencias de IRON IA. Entre `fef7c87` y `7318122` entraron 19 commits: 14 de esos módulos y 5 del Studio que solo son documentación y scripts de `ops/`. **El código del Studio es idéntico en los dos** (diff vacío en sus ficheros, comprobado el 31-10-2026; en las zonas del motor cambia una línea, la de `plan_archived` en `SalesPaymentGuardrailService`), así que el ensayo de DevOps sigue valiendo con el destino corregido: en un banco local (sin `sudo` real) y sobre `fef7c87`, 33 rutas, `GET publications` 404, la tabla intacta y el smoke de H4 sin modificar, 17/17 sobre datos de H5. La vuelta a `7318122` en sí no se ha ejecutado.

```bash
# 0. (fases 2–3) Antes, parar de leer y de declarar: el código de H4 no sabe mover versiones de H5.
bash /root/h5-engine-scope.sh off <alcance actual>          # si no está ya en off
#    y «Volver a Legacy» desde el CRM si la cadena declara algo.
# 1. CRM anterior (opcional: cada CRM lee los dos backends; mantiene la pareja coherente)
cp /var/backups/ironbody/crm-pre-<build-crm>-<ts>/index.html /var/backups/ironbody/crm-pre-<build-crm>-<ts>/version.json /var/www/app/ && chown root:root /var/www/app/index.html /var/www/app/version.json
# 2. Backend: git como root (dueño del árbol y de .git, I-3 del reviewer) y con umask 022, para que www-data lea lo que reescriba
BK=/var/backups/ironbody/ultron-studio-h5-<fecha UTC>                                     # el respaldo del paso 3 de la fase 1
FASE1=<commit desplegado en la fase 1 del Hito 5>                                          # el ESPERADO del script de la fase 1 (registro de despliegues de la hoja de ruta)
# 2a. SOLO si producción sigue en el commit de la fase 1 (D2-1 del reviewer): si main se desplegó después durante el
#     canario, este checkout borraría ese trabajo y dejaría sus migraciones sin el código que las usa (el mismo fallo
#     que I-4, en el futuro). En ese caso NO se hace checkout: se sale por fase (paso 0) y se decide con devops un
#     revert de los commits del Hito 5 sobre main.
[ "$(git -C /var/www/api rev-parse HEAD)" = "$(git -C /var/www/api rev-parse "$FASE1")" ] \
  || { echo "producción ($(git -C /var/www/api rev-parse --short=7 HEAD)) ya no está en el commit de la fase 1: no hacer checkout; salir por fase y planear un revert"; exit 1; }
( cd /var/www/api && umask 022 && git checkout -q --detach "$(cat "$BK/api-commit.txt")" )   # hoy, 7318122
cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 33
sudo -u www-data php artisan auth:coverage --unmapped                                   # vacío
supervisorctl status | grep -c RUNNING                                                  # 13
# 3. Smoke de H4 (vuelve a valer con el código de H4; copiado antes desde ops/)
SMOKE_LAB_EXPECT_ENABLED=1 bash /root/smoke-run-h4.sh
```

- **Qué queda:** la tabla `ultron_studio_publications`, sus 3 disparadores y 3 CHECK, `usv_status_chk` con 9 estados y los dos índices parciales. El código de H4 enseña las versiones de H5 con `reachable: false` y sus acciones deshabilitadas; revertir una responde el 409 provisional de F/G y `GET publications`, 404. Lo publicado, promovido o revertido queda en el historial.
- **Mientras el árbol esté en `--detach`, nadie despliega:** el siguiente `git pull`/`merge --ff-only` de otro equipo fallaría; se vuelve a `main` (abajo) o se decide el revert antes de cualquier otro despliegue.
- **Volver a H5:** `( cd /var/www/api && umask 022 && git checkout -q main )` (la rama local sigue en el commit de H5), las mismas cachés y `queue:restart` → 38 rutas y 0 migraciones pendientes (ensayado por DevOps en su banco local, sobre `fef7c87`).
- **`api-commit.txt` tiene que ser el `PREVIO`** (reanudación tardía; decisión del lead, 31-10-2026). Si la fase 1 se corta y se relanza más de 2 horas después del respaldo, hay que rehacer el respaldo, y su `api-commit.txt` tiene que seguir siendo el `PREVIO` (`7318122`), no el HEAD de ese momento. Si no, el script aborta en el paso 0 (falla cerrado) y esta vuelta atrás leería un destino equivocado. El procedimiento, en el paso 5 del runbook.
- **Por qué `git` como root** (I-3; decisión del lead, comprobada en el servidor en solo lectura el 31-10-2026): desde el 25-10, el árbol de `/var/www/api` (2.493 entradas, entre ellas todo lo que cambia el Hito 5) y `.git` (5.089 de sus 5.131 entradas) son de root, y `www-data` los lee pero no puede escribir ni en `objects/` ni en los directorios del código. Hasta ese día este procedimiento decía `sudo -u www-data -H git` (M-3), que fallaría a medias. Con `umask 077` en la sesión, lo que `git` reescribe quedaría ilegible para `www-data`: de ahí la subshell con `umask 022`.

### Vuelta atrás de datos (último recurso)

Solo ante corrupción de datos, con **autorización expresa** y coordinado por `database-specialist`, siguiendo `docs/ops/BACKUPS.md` («Restauración real»): primero a una base aparte, **nunca encima de la viva**, y sabiendo que se pierde todo lo escrito desde el volcado (`ironbody.dump` del respaldo del paso 3 del runbook; §4.2 del informe de DevOps). Nada de la fase 1 lo justifica: sus dos migraciones son aditivas y no tocan datos.

### Incidencia: la cabeza no verifica y no hubo rotación de clave

- **Síntoma:** `ultron-studio:publication-check` sale con 1 y `head_invalid`; `status.engine.publication.state` = `fallback`; publicar, promover y revertir → 409 `publication_head_invalid`.
- **Causa probable:** una fila alterada o insertada con DML directo (N-3/AS-3).
- **Mitigación:** `bash /root/h5-engine-scope.sh off <alcance actual>` (el motor deja de sellar `fallback`) y después «Volver a Legacy».
- **Si Legacy responde 500** (SQLSTATE 22003): la cabeza tiene `seq = 2147483647` y el Studio se queda sin salida. **No improvisar:** la reparación es del rol dueño, con autorización del propietario, porque toca el libro (`database-specialist`). Avisar a `security`.

### Rotación de la `APP_KEY` con la cadena viva

**Lo que rompe** (leído en el código y ensayado en local): ninguna entrada firmada con la clave vieja vuelve a verificar. El motor, con su llave distinta de `off`, sella `fallback/signature_invalid` en cada turno (en la opción A no cambia nada más). En `engine/readings` se ve `hashOk: true` con `signatureOk: false`. Publicar, promover y revertir responden 409 `publication_head_invalid`, y `publication-check` sale con 1 (`head_invalid`): **esa es la alarma**. `APP_PREVIOUS_KEYS` no salva la cadena: la firma solo usa la clave vigente.

**Fuera de H5:** la `APP_KEY` alimenta otros mecanismos. Un turno con un `decide_token` en vuelo puede quedar mudo y, en la conversación canaria, el vigía echaría el freno (hipótesis por lectura de código, no ejecutada). También se usa en otras HMAC (`access_hash`, códigos y tokens del kiosco, entre otras) y en lo cifrado (sesiones, cookies, columnas cifradas). **Rotar la `APP_KEY` es un evento de todo el sistema; H5 no lo justifica por sí solo.**

**Procedimiento** (solo con autorización expresa del propietario y por otro motivo, por ejemplo una sospecha de fuga):
1. **Condiciones:** una ventana sin tráfico de ULTRON, con al menos 120 s desde el último `decide` (mejor con ULTRON en pausa por quien la gobierna), y respaldo del fichero de entorno y de `bootstrap/cache/config.php` (root, 600).
2. **Foto:** `sudo -u www-data php artisan ultron-studio:publication-check --json > /root/pre-rotacion-publication.json` (sin secretos ni ids de conversación). Anotar el `seq`, la acción y la versión de cada parte.
3. **Si hay una versión en `CANARY`, decidir antes:** (a) `bash /root/h5-engine-scope.sh off <alcance actual>`, para no sellar `fallback` durante la transición, o (b) aceptar que ese canario se pierde. Un turno sellado `fallback` en su ventana impide para siempre `stamped = turns`.
4. **Rotar** (procedimiento global): `sudo -u www-data php artisan key:generate --force` (**nunca** `--show`); la clave vieja, en `APP_PREVIOUS_KEYS`; `config:cache` como `www-data` con `umask 077` y `chmod 600`; `queue:restart` y `supervisorctl restart 'ironbody-lab:*'`.
5. **Comprobar la alarma:** `publication-check` con 1 y `head_invalid` si la cadena tenía entradas. Con la cadena vacía sale 0 y no hay nada más que hacer.
6. **Salida:** CRM «Volver a Legacy» (o `POST /api/admin/ultron-studio/publications/legacy`), con el motivo «Rotación de la APP_KEY del <fecha>, autorizada por <quién>». Es la única escritura que acepta una cabeza que no verifica, y firma `{canary: null, rest: null}` con la clave nueva. La `CANARY` pasa a `ROLLED_BACK` y la `PUBLISHED`, a `RETIRED`.
7. **Comprobar:** `publication-check` con 0, `state: legacy` (u `off`) y `head.signatureOk: true`; `SMOKE_PHASE=<fase actual> bash /root/smoke-run-h5.sh` con código 0. Si en el paso 3 se apagó la llave del motor, la configuración es la de la fase 1 con la cadena ya escrita, y el smoke de la fase 1 da FALLO por diseño (ver «Vuelta atrás por fase»): el smoke se pasa después del paso 8.
8. Si en el paso 3 se apagó la llave del motor, volver al alcance anterior **con la autorización de esa fase**.
9. **Recuperar la declaración:** versión nueva con el mismo contenido → aprobación de otra persona → laboratorio → publicar en la ventana → canario (24 h y 3 turnos sellados por defecto) → promover. Las versiones `ROLLED_BACK` y `RETIRED` no vuelven, y las entradas viejas siguen en el historial sin verificar ni servir de origen de una restauración: dejarlo escrito en el acta de la rotación.

**Qué NO hacer:** «volver a publicar» encima de la cabeza vieja (responde 409; R6 del plan, corregido en la Enmienda 2 del contrato), imprimir la clave o pegarla en un chat, y rotarla solo por H5.

## Hito 6 (integraciones comerciales) — DESPLEGADO EN FASE 1 el 02-11-2026; respaldo `/var/backups/ironbody/ultron-studio-h6-20261102-043714/` (CRM: `/var/backups/ironbody/crm-pre-16c433c-20261102-045554/`)

**Estado (01-11-2026):** el Hito 6 está implementado en la rama `feat/ultron-studio-h6` (backend `411d8b1`, código `4e2e8cf`; CRM `16c433c`) y **está desplegado en su fase 1** desde el 02-11-2026 04:54 UTC (backend `1e5f9a0`, CRM `16c433c`; escrituras y sonda apagadas). Para la vuelta atrás de nivel 3: `BK` = `/var/backups/ironbody/ultron-studio-h6-20261102-043714`, `api-commit.txt` = `7ba0ce8` y `FASE1` = `1e5f9a0`. Sale del contrato (`ULTRON_STUDIO_H6_CONTRACT.md`, «Reversión» e I13, con las Enmiendas 3 y 4), del runbook de DevOps (`plans/informes-h6/informe-devops-h6.md` §5), del informe de datos (`informe-db.md` §9), del de seguridad (`informe-security.md` §2) y de los del CRM (`informe-front-datos.md` §5.3 e `informe-front-integracion.md`, D-F12 y Q-4). DevOps ensayó en su banco local las idas y vueltas de las dos banderas, pero **no** la vuelta del código. El resto se deduce del código y de las pruebas.

**Reglas que no cambian:**
- **Nunca `migrate:rollback` / `down()` en producción** (límite del 25-10-2026). En otros entornos, las dos migraciones se niegan con una fila en cualquiera de las 3 tablas o con un evento del libro de un sujeto nuevo: salen con código 1, con los cerrojos ya tomados y sin deshacer nada (`informe-db.md` §7).
- **No se quitan las 8 líneas del Hito 6** del fichero de entorno: el código del Hito 5 no las lee.
- **Ninguna palanca de esta sección cambia lo que ULTRON dice o hace:** el motor no lee nada del Hito 6, ni antes ni después.

**Los cuatro niveles del contrato** («Reversión»):

| Nivel | Cómo | Efecto | Pierde datos |
|---|---|---|---|
| **1 · Studio** | CRM, «Integraciones»: desactivar las asociaciones de campaña (`POST integrations/meta/links/{uuid}/deactivate`, motivo de 5 a 500 caracteres) y revocar los números canarios (`POST integrations/canaries/{uuid}/revoke`, motivo de 5 a 500). Necesitan la bandera de escrituras encendida (si no, 409 `integrations_writes_disabled`) y `ultron.campaigns_manage` o `ultron.canary_manage` | El registro deja de declarar. El motor no notaba nada antes ni lo nota después. Revocar es definitivo y pone a NULL el teléfono cifrado de voz en la misma sentencia | Nada se borra: la fila revocada conserva su huella, sus 4 dígitos y su alias (ver «Datos personales», abajo) |
| **2 · Banderas** | `bash /root/h6-integrations-scope.sh writes off on` y `bash /root/h6-integrations-scope.sh probe off on` (servidor, root) | Las escrituras 47–54 vuelven a 409 `integrations_writes_disabled` y la sonda, a 409 `probe_disabled`, tras la caché de configuración. Las lecturas y reconocer la configuración siguen (reconocer no depende de la bandera de escrituras) | Nada |
| **3 · Código** | Volver al backend del Hito 5 (el commit de `api-commit.txt`, solo si producción sigue en el commit de la fase 1 del Hito 6) y, si se quiere, al CRM anterior | Seguro con filas del H6: las tablas se quedan sin lector. Ver «Vuelta atrás del código», abajo | Nada en la base |
| **4 · Esquema** | `down()` | **Nunca en producción.** En otros entornos, solo sin filas en las 3 tablas ni eventos con los sujetos nuevos | Todo lo del Hito 6 |

**Nivel 2, la verificación.** El mando termina en `MANDO <writes|probe> → off APLICADO (copia previa: /root/env-pre-h6-<mando>-off-<fecha>)` (código 0) tras comprobar la configuración efectiva por tinker, el motor sin cambios, 13 workers RUNNING y 0 en BACKOFF/FATAL, `integrations/status` sin token → 401 y una instantánea de sistema si alguna huella cambió (`probe off` cambia la de Twilio). Si algo sale mal, imprime la orden exacta para volver a la copia previa. Exige como último argumento el estado de HOY: si no coincide con la configuración efectiva, no toca nada; y exige el fichero de entorno en 600 (N-4 b de `security`).
- **La vista del proceso web** (N-4 a de `security`): tinker es CLI y no comparte el OPcache del FPM, y el mando no recarga FPM. Con **las dos** banderas apagadas, `SMOKE_PHASE=1 bash /root/smoke-run-h6.sh` (copiando antes `smoke-h6.py` y `smoke-run-h6.sh` a `/root/`) comprueba desde el proceso web que las escrituras y la sonda responden 409. **Con las escrituras apagadas y la sonda encendida no hay smoke que lo compruebe:** el lanzador solo admite apagado/apagado (fase 1), encendido/apagado y encendido/encendido (fase 2). En ese caso se mira `flags` de `GET integrations/status` con una sesión real. [PENDIENTE: `opcache.validate_timestamps` del pool de FPM, comprobado una vez y anotado (`devops`).]
- **Tras `probe off`, Twilio vuelve a `drift`** frente a la configuración reconocida (la huella incluye `probe.enabled`, H-1 de DevOps): se vuelve a reconocer si se quiere el estado `authorized`.

### Vuelta atrás del código: backend del H6 → el commit anterior (`api-commit.txt` del respaldo; hoy sería `7ba0ce8`), con las tablas en su sitio

Solo si el código del Hito 6 se porta mal. **Volver el código no es volver los datos.** El destino es lo que servía producción justo antes del Hito 6: el commit que guardó el respaldo de la fase 1 en `api-commit.txt` (el `PREVIO` de `deploy-h6-backend-fase1.sh`; hoy, `7ba0ce8`). **Se lee del respaldo, nunca de un literal** (I-4 del reviewer del H5). No se ha ensayado: el ensayo de DevOps cubre las banderas, no el código.

```bash
# 0. Antes, cerrar las escrituras y la sonda (nivel 2): el código del H5 no conoce las rutas, pero así nada escribe mientras tanto.
bash /root/h6-integrations-scope.sh writes off on            # si estaba en on
bash /root/h6-integrations-scope.sh probe off on             # si estaba en on
# 1. CRM anterior (opcional: el CRM del H6 lee los dos backends; ver «Qué ve el CRM», abajo)
cp /var/backups/ironbody/crm-pre-<build-crm>-<ts>/index.html /var/backups/ironbody/crm-pre-<build-crm>-<ts>/version.json /var/www/app/ && chown root:root /var/www/app/index.html /var/www/app/version.json
# 2. Backend: git como root (dueño del árbol y de .git) y con umask 022, para que www-data lea lo que reescriba
BK=/var/backups/ironbody/ultron-studio-h6-<fecha UTC>                                     # el respaldo del paso 1 de la fase 1
FASE1=<commit desplegado en la fase 1 del Hito 6>                                          # el ESPERADO del script (registro de despliegues de la hoja de ruta)
# 2a. SOLO si producción sigue en el commit de la fase 1 del H6 (D2-1 del reviewer del H5): si se desplegó otra cosa
#     después, este checkout borraría ese trabajo y dejaría sus migraciones sin el código que las usa. En ese caso NO
#     se hace checkout: se sale por banderas (paso 0) y se decide con devops un revert de los commits del Hito 6.
[ "$(git -C /var/www/api rev-parse HEAD)" = "$(git -C /var/www/api rev-parse "$FASE1")" ] \
  || { echo "producción ($(git -C /var/www/api rev-parse --short=7 HEAD)) ya no está en el commit de la fase 1 del H6: no hacer checkout; salir por banderas y planear un revert"; exit 1; }
( cd /var/www/api && umask 022 && git checkout -q --detach "$(cat "$BK/api-commit.txt")" )   # hoy sería 7ba0ce8
cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 38
sudo -u www-data php artisan auth:coverage --unmapped                                   # vacío
supervisorctl status | grep -c RUNNING                                                  # 13
# 3. Smoke del H5 (vuelve a valer con el código del H5; copiado antes desde ops/)
SMOKE_PHASE=1 bash /root/smoke-run-h5.sh
```

- **`api-commit.txt` tiene que ser el `PREVIO`.** Si la fase 1 se relanzó más de 2 horas después del respaldo y hubo que rehacerlo, su `api-commit.txt` tiene que seguir siendo el `PREVIO`, no el HEAD de ese momento. **Leído en el script:** en una reanudación, el paso 0 del Hito 6 acepta también un `api-commit.txt` que empiece por el commit nuevo, así que el script no lo avisaría; esta vuelta atrás leería entonces el destino equivocado. Comprobarlo antes: `cat "$BK/api-commit.txt"` tiene que empezar por el `PREVIO` del día del despliegue.
- **Smoke del H5 con datos del H6:** el lanzador del H5 compara el recuento exacto de todas las tablas `ultron_studio_*` antes y después, sin fijar cuántas son (leído en `smoke-run-h5.sh`), así que las 3 tablas del H6 no deberían hacerlo fallar. [PENDIENTE: no ensayado con datos del H6; DevOps (§5) lo da como la verificación de este nivel.]
- **Qué queda:** las 3 tablas, sus 13 disparadores, 22 CHECK y 2 índices parciales, y `usae_subject_type_chk` con 7 sujetos (un superconjunto: las inserciones del H5 siguen pasando, `informe-db.md` R-3). Las 8 claves del fichero de entorno. El código del H5 no lee nada de eso.
- **Qué ve el CRM** (leído en el código, `informe-front-datos.md` §5.3; corrige la frase del contrato «el libro pinta los sujetos nuevos en genérico», que solo vale con el CRM del H6):
  - **con el CRM del H6** y el backend del H5: la página «Integraciones» dice «El servidor todavía no tiene integraciones (Hito 6)» (404 en `integrations/*`), y el libro pinta en genérico, clave a clave, los eventos `campaign_link`, `canary_number` e `integration`, que el backend del H5 sigue sirviendo en `GET audit` sin filtro (leído en `UltronStudioController.php` y `StudioAudit::page()` de `7ba0ce8`). Su filtro `subjectType` solo admite los 4 de antes: filtrar por uno de los nuevos da un 422, que el CRM dice como «este filtro todavía no existe en el servidor» (D-F12 de `informe-front-integracion.md`);
  - **con el CRM anterior** (`ab78a6a`): su panel del libro solo conoce 4 sujetos y **descarta esos eventos como ilegibles** («N eventos llegaron ilegibles y no se enseñan»). No se pierde nada: el libro sigue intacto en la base.
- **Mientras el árbol esté en `--detach`, nadie despliega:** se vuelve a `main` o se decide el revert antes de cualquier otro despliegue.
- **Volver al H6:** `( cd /var/www/api && umask 022 && git checkout -q main )` (la rama local sigue en el commit del H6), las mismas cachés, `queue:restart` y el smoke del H6. **No** el script de despliegue: exigiría el esquema del H5 y abortaría (DevOps §5).

### Vuelta atrás de datos (último recurso)

Solo ante corrupción de datos, con **autorización expresa** y coordinado por `database-specialist`, siguiendo `docs/ops/BACKUPS.md` («Restauración real»): primero a una base aparte, **nunca encima de la viva**, sabiendo que se pierde todo lo escrito desde el volcado (`ironbody.dump` del respaldo de la fase 1). Nada de la fase 1 lo justifica: sus dos migraciones son aditivas y no tocan datos de otras tablas.

### Datos personales: revocar no es suprimir

- **La huella del teléfono es un seudónimo, no un dato anonimizado** (B-1 de `security` = AS-4 de `api-security-specialist`; I13 corregido en la Enmienda 3). Con la `APP_KEY`, la huella y los 4 dígitos que guarda la fila, el número se recupera en milisegundos; sin los 4 dígitos, el espacio de WhatsApp (10⁹) se recorre en unos 24 minutos con un núcleo (H-3 del tester, Enmienda 4). Lo que protege los teléfonos canarios es el secreto de la `APP_KEY`.
- **Revocar no suprime:** pone a NULL el cifrado de voz, pero la huella, los 4 dígitos y el alias son columnas inmutables y la fila no se puede borrar con el rol de la aplicación. **No hay todavía un procedimiento de supresión** (derecho de supresión, Ley 1581): es del Hito 7 (architect + `database-specialist`, con decisión del propietario), y tiene que cubrir las 3 tablas nuevas además de las de F, del Hito 4 y del Hito 5.
- **Copias fuera de las tablas:** el respaldo de la fase 1 (volcado y `env.backup` juntos), y la huella del teléfono en el `DETAIL` del error 23505 del log del servidor PostgreSQL en cada registro duplicado (H-2 del tester, diferido al Hito 7).

### Rotación de la `APP_KEY` con el Hito 6 desplegado (amplía la del Hito 5)

**No forma parte de ninguna fase.** Rotar la `APP_KEY` es un evento de todo el sistema (ver «Hito 5»), y el Hito 6 suma lo que deja de valer (`informe-security.md` §2, leído en el código; no ensayado):

| Pieza | Tras rotar |
|---|---|
| Huellas de los teléfonos canarios (`phone_hash`, `PhoneFingerprint`, etiqueta `ultron-canary-phone/1`) | No casan con las nuevas. La coherencia pasa a `engine_canary_not_registered` aunque la fila siga activa: **el T1 se pierde**. Registrar otra vez el mismo número **no** choca con la fila vieja en `uscn_one_live` (es otra huella): habría dos filas vivas del mismo teléfono hasta revocar la vieja y, al activar la nueva, `registry_has_extra_active`. Reintentar un registro anterior da 409 `client_request_reused` |
| Teléfonos cifrados de voz (`phone_encrypted`) | En el Hito 6 nada los descifra. Sin la clave vieja en `APP_PREVIOUS_KEYS` quedan indescifrables; con ella, siguen descifrables con ella |
| Ids opacos de Meta (`MetaAdEntity::opaqueId`) | Cambian todos. El CRM con ids viejos recibe 404 `campaign_not_found` o listas vacías: hay que recargar. Las asociaciones guardan el id de Meta y sobreviven. Los eventos `campaign_link.created` conservan el id opaco viejo: se correlacionan por el uuid del sujeto |
| Huellas de valores de la configuración (`fp`, etiqueta `ultron-studio-config-fingerprint/1`) | Cambian las del token de Meta y del de Twilio **aunque el secreto sea el mismo**: `meta_ads` y `twilio` pasan a `drift`, que parece un cambio de credenciales y no lo es |
| Lo que no cambia | El enmascarado (sale de `phone_last4`), las asociaciones, el libro y la huella de la configuración de voz mientras no tenga secretos |

**Procedimiento** (se suma al del Hito 5, en el mismo acto, solo con autorización expresa del propietario y por otro motivo):
1. **Antes de rotar, la foto:** `GET integrations/canaries?status=all` y `GET integrations/config` (no llevan secretos ni teléfonos). Anotar de cada número vivo el canal, el alias, los 4 dígitos y el estado, y el estado de los 3 proveedores.
2. **Revocar los números vivos**, con el motivo «Rotación de la APP_KEY (fecha, autorizada por …)», sin teléfonos ni correos (AS-1: el servidor los rechaza con 422). Borra los cifrados de voz, deja la evidencia en el libro y evita las filas duplicadas. Si la rotación es por compromiso de la clave, las filas revocadas siguen siendo recuperables con la clave vieja (B-1).
3. **Rotar** como en el Hito 5, paso 4: `key:generate --force` sin `--show`, la clave vieja en `APP_PREVIOUS_KEYS`, `config:cache` con `umask 077` y `chmod 600`, y los reinicios.
4. `sudo -u www-data php artisan ultron-studio:integrations-snapshot` → instantáneas de sistema nuevas de `meta_ads` y `twilio`.
5. **Un Super Admin vuelve a reconocer** los 3 proveedores, con la nota «Rotación de la APP_KEY: la deriva es solo de huellas».
6. **T1 de nuevo:** registra quien tiene el teléfono de la conversación canaria; otra persona autoriza y activa; el resultado tiene que ser `in_sync`.
7. **Recargar el CRM:** los ids opacos cambiaron.
8. **Si fue por compromiso,** la clave vieja no se queda en `APP_PREVIOUS_KEYS` más de lo imprescindible.

## Fases siguientes

*(Actualización del 25-10-2026: la reversión por versión que anunciaba este apartado es la del Hito 5, arriba.)* Cuando exista persistencia (borradores, versiones, aprobaciones), la reversión se hará **por versión**, no por despliegue: una versión publicada es inmutable y `ROLLED_BACK` devuelve la anterior conocida, sin migraciones destructivas. El procedimiento se documentará con esa fase.

**Respaldos reales del despliegue de G (19-10-2026):** backend y base `/var/backups/ironbody/ultron-studio-g-20261019-060047/` (`ironbody.dump` 209 tablas, `env.backup`, `bootstrap-cache/`, `api-commit.txt` = `9acddc3`); CRM `/var/backups/ironbody/ultron-studio-g-crm-20261019-060251/` (`index.html` y `version.json` de `020db86`, bundle `main-7A6NMSXZ.js`, que sigue en disco porque no se usó `--delete`).

**Respaldos reales del despliegue del Hito 4 (25-10-2026):** `/var/backups/ironbody/ultron-studio-h4-20261025-011511/` (dump de 211 tablas, `env.backup`, `bootstrap-cache/`, `supervisor-conf.d/` antes de añadir `ironbody-lab.conf`, `api-commit.txt` = `d86a2b5`) y CRM `/var/backups/ironbody/crm-pre-5c1dbd7-20261025-012110/`. Vuelta atrás inmediata del laboratorio: `bash docs/ultron-studio/ops/deploy-h4-fase2.sh off` (bandera apagada, caché y reinicio del worker). De código: bandera apagada → `supervisorctl stop 'ironbody-lab:*'` y retirar `/etc/supervisor/conf.d/ironbody-lab.conf` (`reread && update`) → `git checkout d86a2b5` → cachés como www-data → `queue:restart` → `index.html`/`version.json` del respaldo del CRM. Las tablas se quedan; **nunca `migrate:rollback` en producción**.

**Respaldos reales del despliegue del Hito 5 (fase 1, 01-11-2026 01:12–01:16 UTC):** `$BK` = `/var/backups/ironbody/ultron-studio-h5-20261101-011245/` (`api-commit.txt` = `7318122`, `ironbody.dump` 38 MB con 222 tablas, copia del fichero de entorno, `app-crm.tgz`, `bootstrap-cache/`, `supervisor-conf.d/`; 700/600) y CRM `/var/backups/ironbody/crm-pre-ab78a6a-20261101-011457/` (`index.html` con `main-5VXOT43E.js` y `version.json` `4105016`). El commit de la fase 1 (`FASE1` de la vuelta atrás del código) es `7ba0ce8`.
