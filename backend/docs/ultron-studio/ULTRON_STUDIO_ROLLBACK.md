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

## Hito 4 (laboratorio de IA) — PENDIENTE DE DESPLIEGUE; respaldo `/var/backups/ironbody/ultron-studio-h4-<fecha UTC>/` (lo crea el paso 2 del runbook)

**Estado:** el Hito 4 no está desplegado y ningún paso de esta sección se ha ejecutado, ni en local. Es el plan, a partir del borrador del lead (`scratchpad/hito-4/deploy-h4.md`) y del informe del database-specialist. El respaldo guarda el commit del backend (`api-commit.txt`), el volcado de la base, el CRM publicado (`app-crm.tgz` y `crm-main.txt`), el `.env` (modo 600), la caché de `bootstrap/cache` y una copia de `/etc/supervisor/conf.d/`.

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

## Fases siguientes

Cuando exista persistencia (borradores, versiones, aprobaciones), la reversión se hará **por versión**, no por despliegue: una versión publicada es inmutable y `ROLLED_BACK` devuelve la anterior conocida, sin migraciones destructivas. El procedimiento se documentará con esa fase.

**Respaldos reales del despliegue de G (19-10-2026):** backend y base `/var/backups/ironbody/ultron-studio-g-20261019-060047/` (`ironbody.dump` 209 tablas, `env.backup`, `bootstrap-cache/`, `api-commit.txt` = `9acddc3`); CRM `/var/backups/ironbody/ultron-studio-g-crm-20261019-060251/` (`index.html` y `version.json` de `020db86`, bundle `main-7A6NMSXZ.js`, que sigue en disco porque no se usó `--delete`).

**Respaldos reales del despliegue del Hito 4 (25-10-2026):** `/var/backups/ironbody/ultron-studio-h4-20261025-011511/` (dump de 211 tablas, `env.backup`, `bootstrap-cache/`, `supervisor-conf.d/` antes de añadir `ironbody-lab.conf`, `api-commit.txt` = `d86a2b5`) y CRM `/var/backups/ironbody/crm-pre-5c1dbd7-20261025-012110/`. Vuelta atrás inmediata del laboratorio: `bash docs/ultron-studio/ops/deploy-h4-fase2.sh off` (bandera apagada, caché y reinicio del worker). De código: bandera apagada → `supervisorctl stop 'ironbody-lab:*'` y retirar `/etc/supervisor/conf.d/ironbody-lab.conf` (`reread && update`) → `git checkout d86a2b5` → cachés como www-data → `queue:restart` → `index.html`/`version.json` del respaldo del CRM. Las tablas se quedan; **nunca `migrate:rollback` en producción**.
