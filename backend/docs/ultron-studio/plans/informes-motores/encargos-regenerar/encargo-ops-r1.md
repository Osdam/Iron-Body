Eres el agente `devops` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 en un VPS; n8n). PREPARAS, sin ejecutar nada, el guion de despliegue del servidor para un cambio que añade una ruta. Todo en español, con el estilo de la casa.

## REGLA DURA

**NO despliegas ni tocas producción.** Nada de `ssh`, nada de comandos contra el servidor, nada de n8n. El propietario no ha autorizado el despliegue: solo se prepara. Tampoco haces commit ni push. Lo único que escribes son los ficheros de «Entregable».

## De qué va

El cambio «regenerar antes de sustituir» del motor de WhatsApp (ULTRON) está en la rama `feat/ultron-studio-h9` (HEAD hoy `d63fbd50`; seguirán uno o dos commits más de código y documentos), por delante de `origin/main` (`bc5167cb`) y de lo que sirve producción (`e861e2c8`). Es SOLO CÓDIGO (sin migraciones, sin `config/`, sin composer), pero añade una ruta: `POST /api/internal/marketing/ai/check` (`backend/routes/marketing.php`). El guion de siempre para correcciones de solo código, `backend/docs/ultron-studio/ops/hotfix-backend.sh`, RECHAZA los cambios en `backend/routes/*`, y producción tiene las rutas en caché (`route:cache`). Hace falta un guion derivado.

Plan de despliegue acordado (léelo): `backend/docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`, §9 («Despliegue y vuelta atrás») y §5.5.

Repositorio: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body`.

## Precedentes que tienes que leer antes de escribir (son la referencia de estilo y de seguridad)

- `backend/docs/ultron-studio/ops/hotfix-backend.sh`: qué comprueba (el commit previo esperado, la lista cerrada de ficheros, las zonas que rechaza, el respaldo, las cachés, los workers, la verificación final y el mensaje «CORRECCIÓN DESPLEGADA SIN DESVÍOS») y cómo vuelve atrás.
- `backend/docs/ultron-studio/ops/deploy-h9-backend-cierre.sh` (hacia la línea 691 y alrededores): cómo se regenera `route:cache` como `www-data` con `umask 077`, y cómo se cuidan los permisos.
- Los otros guiones de `backend/docs/ultron-studio/ops/` que despliegan el backend, para no inventar convenciones.

Trampas ya conocidas de este servidor, que el guion tiene que respetar:
- La aplicación corre como `www-data`; `config:cache` y `route:cache` se ejecutan como `www-data`. Un `chmod 600` sin `chown www-data` sobre un fichero de caché deja los doce workers en BACKOFF, y el síntoma sale en el SIGUIENTE reinicio.
- Con `umask 077`, un `git merge` deja los PHP en 600 de root y FPM no los lee: el umask restrictivo va en un subshell acotado al respaldo, no alrededor del merge.
- Hay 14 workers de supervisor en colas propias; tras desplegar código, `queue:restart`.
- Producción no consume la cola `default`.
- Otras sesiones despliegan desde `main`: el guion debe comprobar qué commit sirve producción ANTES de tocar nada y negarse si no es el esperado.
- Nunca `migrate:rollback`. Este cambio no tiene migraciones: el guion debe NEGARSE si el diff trae alguna, o cambios en `config/`, composer o `bootstrap/`.

## Qué tiene que hacer el guion (parámetros: commit previo esperado, commit nuevo, fichero con la lista de ficheros)

1. Comprobaciones previas, todas con negativa clara: el commit que sirve producción es el esperado; el commit nuevo existe en `origin/main` y es descendiente del previo; el diff entre los dos coincide EXACTAMENTE con la lista; el diff no trae migraciones, `config/`, composer ni `bootstrap/`; y de `backend/routes/` solo trae `marketing.php`.
2. Respaldo (código previo identificado, cachés de rutas y de configuración) con permisos restrictivos, en la carpeta de respaldos de siempre.
3. El avance del código, con el mismo mecanismo que el guion de solo código.
4. `route:cache` como `www-data`, con los permisos correctos, y comprobación con `route:list` de que existen las cuatro rutas internas de ULTRON (`ai/decide`, `ai/commit`, `ai/check`, `ai/ultron/incidents`) con su middleware. Si falta alguna, VUELTA ATRÁS automática y salida distinta de cero.
5. `queue:restart` y comprobación de que los workers quedan en RUNNING pasado un margen (no al instante: la trampa del BACKOFF).
6. Verificación final de solo lectura: el commit servido, que la ruta nueva contesta 401 SIN el secreto (una petición local sin credenciales: no se usa ningún secreto en el guion), y un resumen en el mismo formato que el guion de siempre.
7. Vuelta atrás explícita (función y orden impresa): volver al commit previo, restaurar la caché de rutas del respaldo o regenerarla, `queue:restart`.

El guion no contiene ni imprime secretos, no lee ficheros de entorno y no escribe nada fuera del repositorio desplegado y de la carpeta de respaldos.

## Cómo lo compruebas SIN producción

- `bash -n` y, si está disponible, `shellcheck`.
- Un ENSAYO EN SECO local: una opción del propio guion (o un arnés aparte) que ejecute las comprobaciones previas contra el repositorio local (los commits reales `e861e2c8` → `d63fbd50`, y la lista sacada con `git diff --name-only`) y que demuestre que se NIEGA en cada caso que debe negarse: commit previo equivocado, lista que no cuadra, un diff con una migración simulada, un diff con `config/`, y una ruta distinta de `marketing.php`. Sin tocar el árbol de trabajo (usa `git` de solo lectura o una copia en tu scratchpad). Pega las salidas en el LEEME.

## Entregable (lo único que escribes)

- `backend/docs/ultron-studio/ops/deploy-a4-regenerar-backend.sh` (el guion, ejecutable, sin ejecutar contra producción).
- `backend/docs/ultron-studio/ops/DEPLOY_A4_REGENERAR.md`: el procedimiento paso a paso para el lead (qué orden exacta se lanza desde el Mac, qué se espera ver, cómo se verifica después, la vuelta atrás por capas del plan §9: versión → n8n → backend), los riesgos, y las salidas de tu ensayo en seco.

Respuesta final a mí en pocas líneas: qué comprueba el guion, qué probaste en seco y qué NO se puede comprobar hasta ejecutarlo de verdad.
