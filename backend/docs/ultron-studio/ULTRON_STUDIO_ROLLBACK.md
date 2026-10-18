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

## Hito F (persistencia)

| Situación | Qué hacer | Pierde datos |
|---|---|---|
| **Recién migrado, sin uso real** (ninguna versión creada) | `php artisan migrate:rollback --step=2` (quita disparadores, función, CHECK y las cinco tablas en orden inverso; verificado en PG 14 y SQLite), después el código anterior (`git checkout <commit anterior>` en `/var/www/api`), `config:cache` + `chown www-data`, y el CRM anterior desde el respaldo de `index.html`/`version.json` | Solo las 12 reglas importadas (se reimportan) |
| **Ya en uso** (borradores, versiones, auditoría) | **No se revierten las migraciones**: la auditoría es de solo inserción y revertirla destruiría historia. Se retira el Studio apagando la bandera `ultronStudioPreviewEnabled` del CRM (una línea + publicación) o publicando el CRM anterior; la API queda en su sitio pero sin interfaz. Si hace falta cerrar también la API: quitar el montaje de `routes/ultron-studio.php` en `bootstrap/app.php` y `route:cache` | Nada |
| **Una versión aprobada por error** | No hay transición hacia atrás por diseño: se crea otra versión y se rechaza la anterior en la revisión siguiente; el libro conserva ambas | Nada |

**Verificación tras revertir:** `php artisan auth:coverage --unmapped` vacío; `\dt ultron_studio_*` según el caso; el CRM publicado sin el submenú (o con él, si solo se cerró la API: entonces el Studio enseña la fase de error con «Reintentar», nunca datos inventados).

## Fases siguientes

Cuando exista persistencia (borradores, versiones, aprobaciones), la reversión se hará **por versión**, no por despliegue: una versión publicada es inmutable y `ROLLED_BACK` devuelve la anterior conocida, sin migraciones destructivas. El procedimiento se documentará con esa fase.
