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

**Estado:** G no está desplegado y ningún paso de esta sección se ha ejecutado. Es el plan. El respaldo guarda el commit del backend (`api-commit.txt`), el volcado de la base, el CRM publicado (`app-crm.tgz`), el `.env` (modo 600) y la caché de `bootstrap/cache`.

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

## Fases siguientes

Cuando exista persistencia (borradores, versiones, aprobaciones), la reversión se hará **por versión**, no por despliegue: una versión publicada es inmutable y `ROLLED_BACK` devuelve la anterior conocida, sin migraciones destructivas. El procedimiento se documentará con esa fase.

**Respaldos reales del despliegue de G (19-10-2026):** backend y base `/var/backups/ironbody/ultron-studio-g-20261019-060047/` (`ironbody.dump` 209 tablas, `env.backup`, `bootstrap-cache/`, `api-commit.txt` = `9acddc3`); CRM `/var/backups/ironbody/ultron-studio-g-crm-20261019-060251/` (`index.html` y `version.json` de `020db86`, bundle `main-7A6NMSXZ.js`, que sigue en disco porque no se usó `--delete`).
