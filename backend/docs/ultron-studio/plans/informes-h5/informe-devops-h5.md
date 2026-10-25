# Hito 5 · Informe de devops: smoke de producción y runbook de la fase 1 (con los criterios de las fases 2 y 3)

**Fecha:** 25-10-2026. **Rama:** `feat/ultron-studio-h5`, HEAD `d98bbf9` (el código es el de `61048ff`; desde ahí solo entró documentación). **Producción hoy, según el lead (no lo he verificado: sin SSH):** backend `fef7c87` (código `18dae99`, Hito 4 en fases 1 y 2), CRM `5c1dbd7`.

**Modo:** todo en local. No he abierto SSH, no he ejecutado nada en el servidor y no he hecho ninguna petición a producción, ni siquiera a la API pública. Sin commit ni push. En el repo he escrito tres ficheros: `docs/ultron-studio/ops/smoke-h5.py`, `docs/ultron-studio/ops/smoke-run-h5.sh` y este informe. **`deploy-h5-backend-fase1.sh` y `h5-engine-scope.sh` no están editados:** las correcciones van como diff en los apéndices A, B y C, y las confirma el lead.

---

## 0. En una pantalla

**Bloqueante para la fase 1, reproducido por mí: `deploy-h5-backend-fase1.sh` no pasa de la línea 11.** La línea es `MODIFICADOS=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')`, con `set -euo pipefail`. Con el checkout limpio, `grep` no selecciona ninguna línea y sale con 1. La asignación termina el script en silencio, antes del `merge`: falla de forma segura, pero la fase 1 no se puede ejecutar tal cual. Lo detectó `security` (M-2). Lo reproduje con un repositorio desechable: código de salida 1 con el checkout limpio y también con solo ficheros `??`.
- **Por qué no le pasó al Hito 4:** el despliegue real de H4 usó el script tal como estaba en `fef7c87`, sin esa línea. La versión «endurecida» (`b68932e`) nunca se ejecutó en producción, y el script de H5 la heredó.
- **Corrección mínima:** 3 líneas, en el apéndice C. **Propuesta completa:** apéndice A, que además se ejecutó entera en un banco local.

**Segundo hallazgo nuevo: el recuento de colas fallidas de los scripts de H4 y H5 es ciego.** En Laravel 12.58, `queue:failed` ya no pinta filas `| id |`: usa `twoColumnDetail`. Lo probé con una fila en `failed_jobs`: `grep -c '^| [0-9a-f]'` da 0 y `SELECT count(*) FROM failed_jobs` da 1. El «0 colas fallidas» del registro del despliegue del Hito 4 no demuestra nada. Las propuestas cuentan por SQL.

| Qué | Resultado | Dónde |
|---|---|---|
| `smoke-h5.py` y `smoke-run-h5.sh` (nuevos) | Fases 1, 2 y 3; solo lectura sobre producción; un FALLO o un OMITIDO cambia el código de salida | §2 |
| Smoke de la fase 1 en local, contra la API real (`artisan serve`, PG 14) | 36 + 14 OK, código 0. **Las 217 tablas de la base, idénticas antes y después** | §10 |
| Smoke de las fases 2 y 3 en local | 34 + 14 OK cada una, con la cadena vacía y con una versión en canario. Nunca intenta Legacy fuera de la fase 1 | §10 |
| Negativas del smoke | Si la configuración es de otra fase, no arranca. En la fase 1 con la cadena no vacía, 4 FALLO y Legacy omitido. Contra el código de H4, 16 FALLO (las rutas nuevas dan 404). Con 429 en el login, FALLO y la cuenta vuelve al reposo. Sin la segunda cuenta, código 3 | §10 |
| Detector de fugas (`signature`, `effective_json`, el id del canario, secretos) | 22/22 casos sin red, incluidos los falsos positivos de fechas, uuids, huellas y motivos que nombran la `APP_KEY` | §10 |
| Rotación de la `APP_KEY`, ensayada | `fallback` → revertir da 409 `publication_head_invalid` → Legacy 201 → `publication-check` 0. El smoke lo detecta | §8 |
| Vuelta del código a `fef7c87` con datos de H5 | Smoke de H4 17/17, 33 rutas y las tablas intactas. Regreso con `git checkout main`: 38 rutas y 0 migraciones pendientes | §7 |
| Propuesta para `deploy-h5-backend-fase1.sh` | Ejecutada de punta a punta en un banco local: clon en `fef7c87`, `origin` local, PG 14 y sustitutos de `sudo`, `stat`, `supervisorctl` e `ironbody-migrate`. Pasó el ensayo, el despliegue, la reanudación y 3 negativas | §3.3 |
| Propuesta para `h5-engine-scope.sh` | En el banco: off → canary → all → off, y las negativas (off → all directo, alcance actual equivocado) | §3.4 |

**Queda para producción** (§11): el envoltorio `ironbody-migrate` (cómo lanza PHP, si conserva `PGOPTIONS` y con qué umask escribe la caché), el dueño de `.git` (M-3), el estado real del fichero de entorno y de los privilegios de `iron_app`, los permisos reales del rol Administrador en Roles, la longitud del id del canario y el tiempo de respuesta real del servidor.

**Pendiente de autorización** (§13): push, respaldo y fase 1, CRM, smoke (deja una huella mínima en `admins`/`admin_sessions`), endurecer los permisos de respaldos anteriores si salen 644, y las fases 2 y 3.

---

## 1. Entorno, impacto y supuestos

**Producción** (según el lead y los informes de H4; no verificado por mí):
- `/var/www/api` (raíz git) y `/var/www/api/backend` (Laravel 12, PHP 8.3-FPM, nginx).
- PostgreSQL 14 `ironbody`: dueño `iron` (se migra con `sudo /usr/local/bin/ironbody-migrate`) y `iron_app` solo DML.
- Supervisor: 13 procesos RUNNING (12 de `ironbody-workers.conf`: whatsapp 4, agent 4, media 2, commercial 2; más `ironbody-lab`), y aparte `ironbody-billing-worker` en systemd.
- Fichero de entorno con la sangría de dos espacios. Cachés de configuración y rutas de `www-data` en 600.
- Laboratorio del Hito 4 encendido en `fake` con presupuesto 0. Canario de conversación configurado. Unos 3 turnos de ULTRON por semana.

**Qué cambia la fase 1:**
- El código, con 5 rutas nuevas (38 en total).
- Dos migraciones aditivas: la tabla `ultron_studio_publications` y sus guardas, la ampliación de `usv_status_chk` y dos índices parciales.
- 7 líneas del fichero de entorno, todas apagadas.
- Las cachés y el `queue:restart`.
- Los estáticos del CRM.

No reinicia FPM, no toca la configuración de supervisor y no toca DNS, TLS ni el firewall.

**Impacto esperado:**

| Quién | Qué nota | Por qué |
|---|---|---|
| Socios, caja, ULTRON | Nada | Con la llave del motor en `off`, `UltronPublishedConfig::forConversation()` devuelve null sin consultar nada (A4.3, paso 1). Además, entre el `merge` y la caché nueva, la caché vieja no tiene `marketing.ultron.studio_config`: `null` se lee como `off` (comprobado en el código del lector y de `StudioPublishing::engineScope()`) |
| Quien use el Studio | Hasta ~1 minuto en que el detalle de una versión puede dar 500 | El código entra antes que la migración (orden heredado de H4). `GET status` lo tolera, porque `engineBlock()` atrapa el fallo. **No usar el Studio durante el despliegue** |
| Workers | Un reinicio ordenado | `queue:restart` espera a que termine el trabajo en curso |
| CRM | Nada | `rsync` sin `--delete`: las pestañas abiertas, también la de caja, siguen con sus chunks |

**Irreversible:**
- Las dos migraciones: son aditivas y se quedan. **Nunca** se ejecuta `down()` en producción.
- En la fase 1 nada escribe en la cadena.
- El smoke cambia la fila de la cuenta 32 (hash, `last_login_at` y el estado de reposo), crea 2 sesiones que borra al terminar y deja líneas en el log (§2.3).

**Ventanas de compatibilidad.** Las dos direcciones son legibles según el código. No están probadas con E2E.
- **CRM `5c1dbd7` con el backend de H5.**
  - `readStatus` (`studio-parse.ts`) lee claves concretas e ignora las nuevas, y los 10 estados ya estaban en `ESTADOS`.
  - El CRM de H4 solo pide `rules()` sin `status`, así que no le afecta la paginación de las archivadas.
  - Es solo cosmético: verá «Motor: lee versiones».
- **CRM de H5 con el backend de H4.** Pasa al modo de F/G (`informe-front-integracion.md`).
- **Orden:** backend → CRM, por convención. Cada uno se puede revertir por separado.

---

## 2. El smoke (`ops/smoke-h5.py` + `ops/smoke-run-h5.sh`)

### 2.1 Qué comprueba

| Bloque | Fase 1 (`off`, publicar cerrado) | Fases 2 (`canary`) y 3 (`all`) |
|---|---|---|
| Lanzador, antes de nada | La configuración efectiva tiene que ser `{"engine_scope":"off","publishing_enabled":false}`; si no, no se ejecuta | `canary`/`all` con `true` |
| `GET status` | `engine.readsVersions = true`. `engine.publication` presente, con `envScope: off`, `state: off` y sin cabeza, partes ni deriva. `activeVersion: null`. `publishing = {available: false, code: publishing_disabled}`. `can.publish/rollback` del Super Admin. Catálogo `2026-10-19.1` y 12 reglas. 9 estados alcanzables y `RETIRED` = «Sustituida» | `envScope` de la fase. Cadena vacía → `legacy`; con cabeza, `signatureOk` y `applied` o `legacy`. Sin deriva. `publishing.available = true` |
| `GET publications` | 200 con el paginador (`per_page=5` respetado) y la cadena vacía. La cabeza de `status` es la primera entrada | Informa del total. La misma coherencia |
| `GET versions?status=CANARY,PUBLISHED,RETIRED,ROLLED_BACK` | Total 0 | Informa |
| `GET engine/readings` | `governance.studioPublication` disponible, `env: off` y sin cabeza. `canaryConfigured` (informativo) | `env` de la fase. Con cabeza, verifica entera (contrato, huella, firma y texto). Canario configurado (obligatorio) |
| `POST versions/<uuid inventado>/publish` y `/promote` | 409 `publishing_disabled` | 404 `version_not_found` |
| `POST versions/<uuid inventado>/rollback` | 404 `version_not_found` | igual |
| `POST publications/legacy` | 409 `already_legacy`, solo si en esta misma ejecución la cadena salió vacía, sin cabeza y con la llave del Studio cerrada | **No se envía**: una publicación concurrente lo convertiría en una escritura |
| `GET versions/<uuid inventado>/publication-preview` y `/canary-evidence` | 404 (la evidencia, una sola llamada a su limitador de 10/min) | igual |
| Una versión APPROVED real, si existe | Vista previa 200, no publicable, `blocking` con `publishing_disabled` y `keys = {studio: false, engine: off}` | Vista previa 200 **sin** `publishing_disabled` ni `engine_not_reading_versions`, con `keys` de la fase |
| Sin token | Las 5 rutas nuevas → 401 `admin_token_required`. El 401 demuestra que la ruta está en la caché de rutas: una ruta inexistente da 404, comprobado contra el código de H4 | igual |
| Hito 4 | `GET lab/status` → 200 | igual |
| Cuerpos | Ninguno lleva `signature`, `effective_json` ni `effectiveJson`. Ninguna clave nombra una conversación. Ni secretos ni rutas del servidor. Ni el id del canario | igual |
| Limitador | Ninguna respuesta 429; se informa del mínimo de `X-RateLimit-Remaining` | igual |
| Administrador (la misma cuenta, con el rol cambiado durante el smoke) | La sesión trae `ultron.view` y no trae `ultron.publish` ni `ultron.rollback`. `can` en false. publish/promote → 403 `forbidden` (`ultron.publish`); rollback/legacy → 403 (`ultron.rollback`); lee `publications` y la vista previa (404) | igual |
| Base (lanzador) | Recuento exacto de las 11 tablas `ultron_studio_*` antes y después. Las filas que nombran a la cuenta en cualquier columna `*admin_id` no cambian. 0 filas de `marketing_ai_actions` con sello `studio` | Informa de los sellos |
| Cierre (lanzador) | La cuenta queda en reposo (inactiva, Super Admin), sus sesiones borradas y los dos tokens responden 401 o 403 | igual |

### 2.2 Por qué es de solo lectura (código de `61048ff`)

- **Publicar y promover con un uuid inventado.** `StudioPublications::publish()` y `promote()` comprueban primero la llave (`require`) y después las llaves de entorno. `assertKeys()` (`:1406-1419`) solo audita `version.*_refused` si la versión **existe**. Con las llaves abiertas, la búsqueda del reintento es un `SELECT`, y `findByUuid()` lanza el 404 antes de cualquier transacción.
- **Revertir con un uuid inventado.** Llave → reintento (`SELECT`) → `findByUuid()` → 404 (`:331-340`).
- **Legacy.** Llave → reintento (`SELECT`) → `assertDeclaring($this->head())` → 409 `already_legacy` con la cadena vacía (`:421-430`, `:1229-1238`). En la fase 1 nadie puede llenar la cadena entre la lectura y el `POST`, porque publicar está cerrado y revertir exige una versión en CANARY o PUBLISHED, que no existe.
- **Administrador.** `EnforceAdminAuthorization` deniega en la puerta: solo escribe `Log::warning('auth:admin:forbidden')` y no toca la base. El 403 `forbidden` no se audita (D-6 del informe de la API). Si la sesión trajera la llave (Roles editado a mano), el smoke no envía esas peticiones y las marca OMITIDO.
- **La vista previa y la evidencia no escriben** (A9.3; ensayado: 217 tablas idénticas con una APPROVED real).

### 2.3 Qué deja en producción (inevitable, igual que el smoke de H4)

- **`admins`, fila 32:** el hash de una contraseña aleatoria de un solo uso, `last_login_at`, `updated_at` y, al final, `status = inactive` y `role = Super Admin`, su reposo documentado.
- **`admin_sessions`:** 2 filas, que el trap borra; se comprueba que quedan 0.
- **El log:**
  - unas 7 líneas INFO `auth:admin:failed`: 5 sin token y 2 de los tokens muertos;
  - 4 líneas WARNING `auth:admin:forbidden`: las del Administrador;
  - ningún ERROR (ensayado).
- **Los limitadores:** el de escritura cuenta los 4 POST denegados del Administrador (quedan 26 de 30) y el del login cuenta 2 por ejecución.

### 2.4 La segunda cuenta (Administrador sin `ultron.publish`)

El arnés de H4 no manejaba una segunda cuenta: `smoke-run-h4.sh` no la usa y `smoke-h4.py` la aceptaba con la contraseña en `argv`. Hay dos opciones:
- **Elegida: la misma cuenta 32, con el rol cambiado a `Administrador` entre las dos partes.**
  - Los permisos se calculan en cada petición a partir del rol (`CrmPermission::forAdmin()` → `RolePermissionPolicy::effectiveFor($admin->role)`). La segunda parte vuelve a iniciar sesión.
  - Al terminar, el trap la deja en Super Admin e inactiva, como estaba.
  - No crea filas nuevas.
- **Alternativa: una cuenta aparte.** Exige crear una fila permanente en `admins` de producción, y eso requiere autorización. Se activa con `SMOKE_SEGUNDA_CUENTA=no` (esa parte queda OMITIDA y el código de salida es 3) y adaptando el lanzador.

### 2.5 Cómo se lanza

Desde el Mac, con el árbol del backend en el commit desplegado:

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body/backend
git status --short docs/ultron-studio/ops/                      # sin cambios en los dos ficheros
shasum -a 256 docs/ultron-studio/ops/smoke-h5.py docs/ultron-studio/ops/smoke-run-h5.sh
scp docs/ultron-studio/ops/smoke-h5.py docs/ultron-studio/ops/smoke-run-h5.sh ironbody-vps:/root/
ssh ironbody-vps 'chmod 600 /root/smoke-h5.py && chmod 700 /root/smoke-run-h5.sh && sha256sum /root/smoke-h5.py /root/smoke-run-h5.sh'
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h5.sh'      # 2 o 3 en sus fases
```

- **Salida:** una línea por comprobación (`OK`, `FALLO`, `OMITIDO`; `INFO` y `AVISO` no cuentan), un `RESUMEN` por parte, el bloque «Base», el cierre de la cuenta y `SMOKE H5 fase N: código de salida X`.
- **Códigos:** 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
- **Lo que hace el lanzador al terminar:** borra sus copias de `/root/`. Para otra ejecución, se vuelven a copiar.
- **Limitador del login:** 8 por minuto e IP (`throttle:8,1`) y 2 logins por ejecución. **Como mucho 3 ejecuciones por minuto.** Lo provoqué en local: con 429 en el login sale FALLO y la cuenta vuelve al reposo.
- **Lo que nunca imprime:** la contraseña, el hash, los tokens, el id del canario ni valores del fichero de entorno. La contraseña va a python por entorno y el hash a tinker por la entrada estándar (comprobado: `tinker --execute` lee `fgets(STDIN)`). De tinker solo se imprime una línea que case con lo esperado: si falla, su salida podría arrastrar el valor de una línea del fichero de entorno (B-1 de `security`).
- **Ensayo local:** variables `APP_DIR`, `SMOKE_API`, `SMOKE_RUN_AS=""`, `SMOKE_PSQL`, `SMOKE_WORKDIR`, `SMOKE_PY` y `SMOKE_ADMIN_ID` (§10).

### 2.6 Si el smoke falla

| Salida | Qué significa | Qué hacer |
|---|---|---|
| `FALLO configuración efectiva …` | La caché no tiene las llaves de la fase | No hay smoke. Revisar el paso 6 del despliegue o el `config:cache` |
| FALLO en `engine.publication presente` | `describe()`/`state()` falló: tabla inexistente, privilegios de `iron_app` o una 100002 a medias | `grep ultron_studio.publication_state_unavailable` en el log; `h5-schema-check.sql` (apéndice D) |
| FALLO en el 401 de las rutas nuevas (sale 404) | La caché de rutas es la vieja | `sudo -u www-data sh -c 'umask 077; php artisan route:cache -q'` + `chmod 600` y repetir |
| FALLO `Administrador: … NO ultron.publish` | El rol Administrador tiene la llave concedida en Roles | Decisión del propietario: quitarla o aceptarla. El smoke no envía nada |
| FALLO en el recuento de la base | Una escritura de la cuenta 32 en `ultron_studio_*` | **Parar** y avisar: no debería poder pasar (§2.2) |
| FALLO al dejar la cuenta en reposo | La cuenta 32 puede seguir activa | Desactivarla y borrar sus sesiones a mano **ya** (el mismo `tinker` del trap) |

---

## 3. Revisión de `deploy-h5-backend-fase1.sh` y `h5-engine-scope.sh` contra el código final

### 3.1 Lo que está bien (comprobado)

- **Las claves de configuración que imprimen existen y son las del código:**
  - `marketing.ultron.studio_config` (`config/marketing.php:102`);
  - `ultron_studio.publishing_enabled` (`config/ultron_studio.php:63`, en la raíz y no dentro de `publishing`);
  - `ultron_studio.publishing.lab_gate`, `.canary_min_hours`, `.canary_min_turns` y `.window` (`:73-89`);
  - `queue.lanes.lab.unattended_after_seconds` (`config/queue.php:224-232`, en `lanes` y no en `connections`);
  - `ultron_studio.lab.enabled` y `.driver`.

  Reproduje en local el JSON del paso 6 con las variables de la fase 1: `{"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300,"lab_enabled":true,"lab_driver":"fake"}`.
- **Los recuentos.** 30 disparadores = 27 + `uspub_forbid_update`, `uspub_forbid_delete` y `uspub_forbid_truncate`. 27 CHECK = 24 + `uspub_action_chk`, `uspub_engine_contract_chk` y `uspub_reason_chk`; `usv_status_chk` se sustituye y no suma. Los 2 índices parciales son `usv_one_canary` y `usv_one_published`. Medido en PG 14 con las mismas consultas del script, tras migrar las 315: **30 / 27 / 2**. La huella de `ultron_studio_forbid()` es `4ad748b5f17f09b398f7f641c197d7ee`, la misma que en el informe de base de datos.
- **Las rutas:** 38 con `route:cache`, contadas con el `grep -c` del script y con `--json`. `auth:coverage --unmapped`, vacío.
- **El orden:** variables → `ironbody-migrate` → `route:cache` → `queue:restart`. Es el de la Enmienda 4.8 de H4.

### 3.2 Hallazgos

| Id | Sev. | Script | Qué | Evidencia | Propuesta |
|---|---|---|---|---|---|
| D-1 | **Bloqueante** | fase 1, `:11` | `grep -v '^??'` sin coincidencias + `pipefail` → el script sale con 1 en silencio (M-2) | Reproducido: limpio → 1; solo `??` → 1 | `git status --porcelain --untracked-files=no \| wc -l` (A y C) |
| D-2 | Media | fase 1, `:47` (y H4 `:59`) | El recuento de colas fallidas cuenta siempre 0 en Laravel 12 | Una fila en `failed_jobs`: grep 0, SQL 1 | `SELECT count(*) FROM failed_jobs` (A y C) |
| D-3 | Media | los dos | Imprimen recuentos, rutas y configuración, pero no los comparan. «COMPLETADA» sale siempre (N-8) | Lectura | Aserciones con lo esperado (30/27/3/3/2, 38, JSON exacto, 13/0, 401) y salida 1 con la lista de desvíos (A y B) |
| D-4 | Media | los dos | Se añade una línea sin comprobar el salto final: un fichero sin `\n` pega la clave a la línea anterior (B-1), Dotenv deja de parsear y `ironbody-migrate` se queda sin caché | Reproducido: `  APP_ENV=production  MARKETING_ULTRON_STUDIO_CONFIG=off` | `[ -z "$(tail -c1 F)" ] \|\| printf '\n' >> F` (A, B y C) |
| D-5 | Media | fase 1 | `add_var` da por buena una clave que ya existe con **otro** valor: la fase 1 quedaría «apagada» de nombre (N-8). `^\s*CLAVE=` no ve `CLAVE = valor` ni `export CLAVE=`, que phpdotenv acepta (`EntryParser` hace `explode('=')` + `trim`), y dentro de un mismo fichero gana la última (`ImmutableWriter`) | Banco y prueba de 14 casos | `estado_var` con `^[[:space:]]*(export[[:space:]]+)?CLAVE[[:space:]]*=`, que aborta si la clave está repetida o tiene otro valor, **antes** del `merge`. Cubre también el caso M-1: `ULTRON_STUDIO_PUBLISHING_ENABLED=off` aborta (A) |
| D-6 | Media | fase 1 | El respaldo no forma parte del script ni se exige | Lectura | El directorio del respaldo pasa a ser el 2.º argumento: volcado de menos de 2 h, `api-commit.txt` = `fef7c87`, 600 y ≥ 211 tablas con datos en `pg_restore -l` (A) |
| D-7 | Media | fase 1 | No comprueba que el envoltorio haya escrito la caché como `www-data` 600 antes de `queue:restart`: si no, los workers quedan en BACKOFF en el siguiente reinicio. `config:cache` y `route:cache` nacen con la umask (N-7) | Banco: el sustituto deja 644 y la propuesta lo pasa a 600 | Si no es de `www-data`, aborta antes de reiniciar; si no es 600, `chmod`; `route:cache` con `umask 077` (A) |
| D-8 | Media | fase 1 | No mira los cerrojos. La 100002 pide ACCESS EXCLUSIVE sobre `ultron_studio_versions`, que choca **también** con un `SELECT` abierto. La consulta del runbook de H4 filtraba `AccessShareLock`: para H5 no vale | Banco: una transacción con `SELECT` abierto hace abortar el ensayo | Cerrojos = 0 sin filtrar el modo (A) |
| D-9 | Media | fase 1 | No comprueba **qué** migraciones correrán: si `origin/main` trajera migraciones ajenas, se aplicarían sin revisión | Lectura | `git diff --name-only --diff-filter=AMDR fef7c87 origin/main -- backend/database/migrations` = exactamente las dos de H5, antes del `merge` (A) |
| D-10 | Baja | fase 1 | No comprueba los privilegios de `iron_app` sobre la tabla nueva (`security`, §4) | Lectura | `true false true` (DML, TRUNCATE, secuencia) y dueño `iron` (A) |
| D-11 | Baja | fase 1 (H4) | El recuento de ERROR de H4 (`-mmin -5`) contaba los ficheros enteros, también los errores viejos | Lectura | Solo las líneas nuevas desde el paso 0, también en un fichero rotado (prueba: 3 de 3) (A) |
| D-12 | Baja | fase 1 | Con un 55P03 a mitad de camino, hay que poder relanzar el mismo script | Banco | Modo reanudación: si HEAD ya es el commit esperado, no hay `merge` ni se exige el esquema de partida (A) |
| D-13 | — | fase 1 | git como root sobre un árbol que podría ser de `www-data` (M-3). No lo introduce H5 | Sin verificar | Verificación en §4.1. No cambio el modelo: decide `security` |
| D-14 | — | fase 1 | `PGOPTIONS` solo llega si el envoltorio conserva el entorno (N-10) | Sin verificar | Inocuo; el envoltorio ya aplica sus 5 s según el README |
| E-1 | Media | alcance | No copia el fichero de entorno antes del `sed -i` (regla 3 de `ROTACION-CREDENCIALES.md`) | Lectura | `install -m 600` en `/root/env-pre-scope-<alcance>-<ts>` y la vuelta exacta impresa si falla (B) |
| E-2 | Media | alcance | No verifica el alcance de partida: permite `off → all` y aplicar sobre un estado que no es el que se cree | Lectura | 2.º argumento obligatorio (el alcance de hoy) contrastado con la configuración efectiva; `off → all` se niega (B) |
| E-3 | Baja | alcance | No exige el canario configurado para `canary`/`all` | Lectura | Aborta sin canario (B) |
| E-4 | Nota | alcance | `canary` abre también publicar (N-9). El script no permite sellar sin publicar | — | Se deja así (es el contrato). Si el propietario quiere solo sellar, hace falta otra llave o editar el script |

### 3.3 La propuesta para la fase 1 (apéndice A), ensayada en un banco local

**Qué hace:**
- **Paso 0, solo lectura** (salvo el `git fetch`); con `--solo-comprobar` se detiene aquí:
  - el HEAD (`fef7c87`, o el esperado si es una reanudación) y el checkout limpio;
  - `origin/main` = el esperado y avance rápido;
  - las migraciones nuevas, que tienen que ser exactamente las dos de H5;
  - el respaldo;
  - el estado de las 7 claves;
  - el esquema de partida (27/24, dueños `iron`, `CREATE`, la cadena inexistente);
  - los cerrojos;
  - las colas fallidas por SQL;
  - el punto de partida del log.
- **Después:** `merge`; claves (con el salto final); `ironbody-migrate`; `route:cache` con `umask 077`; las cachés `www-data` 600; `queue:restart`.
- **Aserciones:** esquema 30/27/3/3/2, 9 estados y dueños; la misma huella de la función; los privilegios de `iron_app`; 38 rutas; cobertura vacía; el JSON exacto de la fase 1; 13 RUNNING y 0 BACKOFF; 4 × 401; las colas fallidas no crecen; 0 sellos; 0 ERROR nuevos; `security:check-secret-exposure`; `publication-check` 0; el checkout limpio.

**El banco** (`…/scratchpad/devops/banco/`):
- Un clon del repo en `fef7c87`, con un `origin` local cuyo `main` es `d98bbf9`.
- La base `ultron_studio_banco_h5` con las 313 migraciones de H4, las 12 reglas y los 11 escenarios, las cachés en 600, el respaldo en el formato del runbook y la API servida.
- Sustitutos de `sudo` (ignora `-u`), `stat -c` de GNU, `supervisorctl` (13 RUNNING) e `ironbody-migrate` (`migrate` + `config:cache` sin `chmod`, el peor caso).
- Cambian solo las rutas, `psql`, la URL, los nombres de rol (`iron` → `ironbody`; con superusuario, el `TRUNCATE` de `iron_app` sale `true`) y el canal del log (`local.ERROR`).

| Ensayo | Resultado |
|---|---|
| Una clave del Hito 5 con otro valor (`ULTRON_STUDIO_PUBLISHING_ENABLED=off`) | `ABORTA` en el paso 0; HEAD sigue en `fef7c87`, fichero intacto (sha256 igual) |
| Una transacción con `SELECT` abierto sobre versiones | `ABORTA: 1 cerrojos sobre ultron_studio_versions …` en el ensayo |
| `--solo-comprobar` limpio | `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`, código 0, HEAD sin mover |
| Ejecución completa | `FASE 1 (H5) COMPLETADA`, código 0: 7 claves añadidas, las 2 migraciones (66 y 37 ms), `config.php pasado a 600`, 30 27 3 3 2, 38 rutas, JSON exacto, 4 × 401, 0 sellos, 0 ERROR y `publication-check` 0 |
| Reanudación (otra vez el mismo comando) | Modo reanudación: «ya estaba» en las 7 claves, nada que migrar y COMPLETADA, sin duplicar líneas |
| Un worker en BACKOFF | `FASE 1 (H5) CON 1 DESVÍOS …`, código 1 |
| Despliegue → `smoke-run-h5.sh` sobre el mismo banco, con la caché en su sitio, como en producción | 35 + 14 OK, código 0 |

Lo que el banco **no** prueba: GNU `stat`/`sed`, `sudo` real, el envoltorio real ni los dueños reales. Para eso está `--solo-comprobar` en el servidor.

### 3.4 La propuesta para `h5-engine-scope.sh` (apéndice B), ensayada

| Ensayo | Resultado |
|---|---|
| `all off` | `off → all directo no: primero canary (fase 2) y su evidencia`, código 2 |
| `canary all` con la configuración en `off` | `ABORTA: la configuración efectiva no es la de «all»: {…}`, código 1, fichero intacto |
| `canary off` | Copia previa 600, llaves `canary`/`true`, caché `www-data` 600, JSON exacto, 13/0, 401, `publication-check` 0: `ALCANCE canary APLICADO` |
| `all canary` y después `off all` | Los dos aplicados igual; el fichero vuelve a `off`/`false` con la sangría de dos espacios intacta |

La expresión de `set_var` conserva la sangría, normaliza `CLAVE = v` y `export CLAVE=` y no toca `CLAVE_X` ni los comentarios (probado).

### 3.5 Lo mínimo imprescindible (si no se adopta la propuesta completa): apéndice C

Son tres cambios: D-1, el salto final (D-4) y las colas fallidas por SQL (D-2). Si solo se aplica esto, **las comprobaciones de §4.6 se hacen a mano y comparando**, porque el script no compara nada.

---

## 4. Runbook de la fase 1

### 4.0 Condiciones (ninguna depende de devops)

- [ ] Revisión final (`reviewer`) sin bloqueantes; puerta de PostgreSQL (`ops/pgunit.sh`) y comparación de suites del mismo día, en verde.
- [ ] Corrección de D-1 confirmada en el repo (apéndice A o C) y copiada desde ese commit.
- [ ] M-1/AS-1 (`ULTRON_STUDIO_PUBLISHING_ENABLED=off` abre publicar): decidido. En la fase 1 lo cubren las propuestas (la fase aborta con un valor no literal); en la fase 2 tiene que estar corregido o aceptado por escrito.
- [ ] Push con avance rápido de backend y CRM. **Autorización del usuario.**
- [ ] **Autorización expresa del usuario para la fase 1.**
- [ ] Unos 15 minutos sin nadie en el Studio. No hace falta la ventana 07:00–19:00: no se publica.
- [ ] A mano:
  - `<commit-backend>` = `git rev-parse --short origin/main` tras el push;
  - `<build-crm>` = el commit de `origin/main` del CRM;
  - `<crm-prod>` = el `commit` de `https://ironbodyneiva.cloud/version.json` (esperado `5c1dbd7`; comprobarlo).

### 4.1 Comprobaciones previas en el servidor (solo lectura)

```bash
ssh ironbody-vps
cd /var/www/api
git rev-parse --short HEAD                                         # fef7c87
git status --porcelain --untracked-files=no | wc -l                # 0
stat -c '%U:%G %a %n' /var/www/api/.git /var/www/api/.git/config /var/www/api/.git/hooks   # M-3: anotar el dueño
git config --global --get-all safe.directory                       # M-3: anotar (vacío = .git de root)
supervisorctl status | grep -c RUNNING                             # 13
systemctl is-active ironbody-billing-worker                        # active
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"   # anotar
sudo -u www-data php backend/artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 33
tail -c1 backend/.env | od -An -c                                  # \n (si no, D-4)
for k in MARKETING_ULTRON_STUDIO_CONFIG ULTRON_STUDIO_PUBLISHING_ENABLED ULTRON_STUDIO_PUBLISH_LAB_GATE ULTRON_STUDIO_CANARY_MIN_HOURS ULTRON_STUDIO_CANARY_MIN_TURNS ULTRON_STUDIO_PUBLISH_WINDOW QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS; do printf '%s %s\n' "$k" "$(grep -Ec "^[[:space:]]*(export[[:space:]]+)?$k[[:space:]]*=" backend/.env)"; done   # las 7 en 0
for p in umask 'PGOPTIONS|lock_timeout' 'config:cache' 'chmod' 'sudo -u|runuser|su -'; do printf '%-26s %s\n' "$p" "$(grep -cE "$p" /usr/local/bin/ironbody-migrate)"; done   # N-7/N-10: solo recuentos; el fichero puede llevar credenciales y no se imprime (si hace falta, lo lee el propietario en el servidor)
grep -c 'production.ERROR' backend/storage/logs/laravel.log                  # anotar: línea base de §5 (si el log es diario, el fichero del día)
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h5-schema-check.sql   # apéndice D
```

**Lo esperado en `h5-schema-check.sql` antes de migrar:**
- `disparadores_studio` 27, `check_studio` 24, `disparadores_uspub` 0, `check_uspub` 0, `indices_parciales` 0, `check_9_estados` f;
- `dueno_funcion` y `dueno_versiones` `iron`, `create_en_public` t, `existe_cadena` f;
- 0 versiones fuera de los 5 estados y **0 filas de cerrojos**.

**Se para si:** la función o `ultron_studio_versions` no son de `iron` (la 100002 fallaría con 42501: no forzar), `create_en_public` sale f, hay cerrojos o alguna de las 7 claves ya existe.

### 4.2 Respaldo (en el servidor, como root)

```bash
umask 077
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-h5-$TS; mkdir -p "$BK"
git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                     # empieza por fef7c87
sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"                    # lo escribe root: 600 por la umask
sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                      # ≥ 211 (H4: 211)
tar -C /var/www -czf "$BK/app-crm.tgz" app
grep -o 'main-[A-Z0-9]*\.js' /var/www/app/index.html > "$BK/crm-main.txt"
install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d"
stat -c '%a %n' "$BK" "$BK"/*                                                 # 700 el directorio; 600 dump y env.backup
du -h "$BK/ironbody.dump"; echo "BK=$BK"
```

- **H4 lo hizo a mano**, con el bloque del paso 2 de «Hito 4» de `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, en `/var/backups/ironbody/ultron-studio-h4-20261025-011511/`. No era un script.
- **La diferencia está en `umask 077`.** Sin ella, el volcado (escrito por la redirección de root) nace 644 en un directorio 755. Hay que comprobar los respaldos de G y de H4 (§12).
- **El respaldo automático diario** (`ironbody-db-backup.timer`, 03:15 de Bogotá, `/root/db-backups/auto`) sigue ahí, pero no sustituye a este: tiene hasta 24 h de antigüedad.

### 4.3 Copiar los scripts (desde el Mac, en el commit desplegado)

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body/backend   # en <commit-backend>, git status limpio
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h5-backend-fase1.sh $O/h5-engine-scope.sh $O/smoke-h5.py $O/smoke-run-h5.sh
scp $O/deploy-h5-backend-fase1.sh $O/h5-engine-scope.sh $O/smoke-h5.py $O/smoke-run-h5.sh ironbody-vps:/root/
ssh ironbody-vps 'chmod 700 /root/deploy-h5-backend-fase1.sh /root/h5-engine-scope.sh /root/smoke-run-h5.sh && chmod 600 /root/smoke-h5.py && sha256sum /root/deploy-h5-backend-fase1.sh /root/h5-engine-scope.sh /root/smoke-h5.py /root/smoke-run-h5.sh'
```

Las huellas tienen que coincidir. Se copia desde el Mac, y no con `git show` en el servidor, para no ejecutar git como root más de lo imprescindible (M-3). `h5-schema-check.sql` (apéndice D) se copia igual si el lead lo añade a `ops/`; si no, se pega en `/root/h5-schema-check.sql`.

### 4.4 Ensayo en el servidor (con la propuesta A)

```bash
bash /root/deploy-h5-backend-fase1.sh <commit-backend> "$BK" --solo-comprobar
```

Esperado: `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)`. Con cualquier `ABORTA`, se para y se aclara.

### 4.5 Backend (con la autorización de la fase 1)

```bash
bash /root/deploy-h5-backend-fase1.sh <commit-backend> "$BK" 2>&1 | tee /root/h5-fase1-$(date -u +%Y%m%d-%H%M%S).log
```

Lo esperado, que es la salida del banco con `iron` y los privilegios de producción:

```
0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):
   respaldo /var/backups/ironbody/ultron-studio-h5-<TS>: ≥211 tablas con datos
   HEAD fef7c87 limpio · esquema 27/24 de iron · sin cerrojos · claves del Hito 5 ausentes o iguales · colas fallidas: <N>
1. código: HEAD=<commit-backend>
2. variables (todo apagado):            (7 × «añadida») · permisos del fichero: <dueño> 600
3. migración (rol dueño, lock_timeout 5 s):
     2026_10_25_100001_create_ultron_studio_publications_table ... DONE
     2026_10_25_100002_add_ultron_studio_publication_guards ...... DONE
   cachés: www-data 600 · www-data 600
4. SQL: … 30 27 3 3 2 true iron iron 0 · iron_app sobre la cadena (DML · TRUNCATE · secuencia): true false true
5. rutas studio: 38 · auth:coverage --unmapped → []
6. {"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300}
   Hito 4 y canario (informativo): {"lab_enabled":true,"lab_driver":"fake","canary_set":true}
7. workers RUNNING: 13 · BACKOFF/FATAL: 0
8. 4 × 401 · colas fallidas <N> (antes <N>) · sellos 0 · ERROR 0 · secret-exposure OK · publication-check 0
FASE 1 (H5) COMPLETADA
```

- **Si sale `ABORTA` en el paso 0:** no se ha tocado nada.
- **Si sale `ABORTA` o un desvío después:** ver §9. Si la 100002 cayó por `55P03`, se relanza el mismo comando, que reanuda.
- **No se sigue con el CRM** si el script no termina en `COMPLETADA`.

**Con solo la corrección mínima (apéndice C):** el comando es `bash /root/deploy-h5-backend-fase1.sh <commit-backend>` y §4.6 se hace entero, comparando cada valor.

### 4.6 Verificación del backend (solo lectura)

```bash
cd /var/www/api/backend
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h5-schema-check.sql
P='^[[:space:]]*(MARKETING_ULTRON_STUDIO_CONFIG|ULTRON_STUDIO_PUBLISH|ULTRON_STUDIO_CANARY_MIN_|QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS)'
cmp -s <(grep -vE "$P" .env | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d') && echo "igual al respaldo salvo las 7 del Hito 5"
grep -cE "$P" .env                                                            # 7
cmp -s <(grep -E '^[[:space:]]*MARKETING_ULTRON_ENABLED=' .env) <(grep -E '^[[:space:]]*MARKETING_ULTRON_ENABLED=' "$BK/env.backup") && echo "MARKETING_ULTRON_ENABLED igual"
git -C /var/www/api status --porcelain --untracked-files=no | wc -l           # 0
```

**Lo esperado en `h5-schema-check.sql` después de migrar:**
- `disparadores_studio` 30, `check_studio` 27, `disparadores_uspub` 3, `check_uspub` 3, `indices_parciales` 2, `check_9_estados` t;
- la misma `huella_funcion` que antes y `existe_cadena` t;
- `app_dml` t, `app_truncate` f, `app_secuencia` t, `dueno_cadena` iron y `publicaciones` 0.

`cmp -s` no imprime nada del fichero: solo dice si son iguales.

### 4.7 CRM (desde el Mac)

```bash
curl -fsS https://ironbodyneiva.cloud/version.json                          # <crm-prod> (esperado 5c1dbd7)
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body_Front   # en <build-crm>, limpio
npm run build:prod                                                          # version.json: commit <build-crm>, dirty false
/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body/backend/docs/ultron-studio/ops/deploy-crm.sh <build-crm> <crm-prod>
```

Esperado: `index.html → main-….js OK`, los dos `main` en 200 y `/marketing/ultron-studio` en 200. **Anotar** la línea `RESPALDO: /var/backups/ironbody/crm-pre-<build-crm>-<ts>`.

### 4.8 Smoke

```bash
ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h5.sh'
```

**Esperado:**
- `OK configuración efectiva de la fase 1`;
- `RESUMEN (superadmin, fase 1): 35–36 OK · 0 FALLO · 0 OMITIDO` (36 si hay una versión APPROVED real; si no, una línea INFO);
- `RESUMEN (administrador, fase 1): 14 OK`;
- los tres OK de «Base»;
- la cuenta en reposo, los dos tokens en 401/403 y 0 sesiones;
- `SMOKE H5 fase 1: código de salida 0`.

**`smoke-h4.py` ya no sirve tal cual con el backend de H5:** su comprobación `readsVersions is False` es justo lo que H5 cambia. El estado del laboratorio lo cubre `smoke-h5.py` (`GET lab/status` 200). El smoke de H4 vuelve a valer solo si el código vuelve a H4 (§7.2).

### 4.9 Cierre de la fase 1

- `sudo -u www-data php artisan queue:health`: sin carriles desatendidos; el carril `lab` con su umbral de 300 s (m-3).
- Con sesión real del propietario (opcional): `/marketing/ultron-studio` abre. El panel de versiones enseña «Publicar» deshabilitado con el motivo del servidor (publicar desactivado). La barra de estado dice que el motor no lee la cadena.
- Registrar en `ULTRON_STUDIO_MASTER_ROADMAP.md`, `ULTRON_STUDIO_FINAL_REPORT.md` y `ULTRON_STUDIO_ROLLBACK.md` (lo hace el lead o `documentation-specialist`):
  - la fecha UTC y los commits;
  - `$BK` y el respaldo del CRM;
  - la salida del script y la del smoke;
  - el estado: «Hito 5 desplegado en la fase 1 (todo apagado)».

---

## 5. Después: a los 15 minutos y a las 24 horas (solo lectura)

```bash
cd /var/www/api/backend
supervisorctl status                                                           # 13 RUNNING; tiempo de actividad ≈ desde el queue:restart; ningún BACKOFF/FATAL
systemctl is-active ironbody-billing-worker                                    # active
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"                             # igual que en el paso 0
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"   # 0 (motor en off)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE created_at > now() - interval '24 hours';"   # informativo: ULTRON sigue anotando turnos si hubo tráfico
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM ultron_aborts WHERE released_at IS NULL;"   # el freno, igual que antes (0)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM ultron_studio_publications;"               # 0
sudo -u www-data php artisan ultron-studio:publication-check                   # código 0 («cadena vacía»)
sudo -u www-data php artisan queue:health                                      # sin incidentes
sudo -u www-data php artisan security:check-secret-exposure                    # en verde
grep -c 'production.ERROR' storage/logs/laravel.log                            # compararlo con el valor anotado en el paso 0 (o con el informe del script)
grep -cE 'ultron_studio\.publication_state_unavailable|auth:admin:unmapped_route|canary_acta_unavailable' storage/logs/laravel.log   # 0
grep ' /api/admin/ultron-studio/' /var/log/nginx/access.log | awk '{print $9}' | sort | uniq -c    # si el formato es el combined: ningún 5xx
tail -n 200 /var/log/nginx/error.log | grep -ci upstream                       # sin errores nuevos de upstream
```

- **Si el log es diario** (`laravel-AAAA-MM-DD.log`): mirar el fichero del día.
- **Qué indica cada señal:**
  - `publication_state_unavailable`: `status` sale sin `publication`;
  - `unmapped_route`: una ruta sin clasificar;
  - un 5xx del Studio: la caché de rutas, la tabla o los privilegios.
- **A las 24 h:** lo mismo, y además una segunda pasada de `SMOKE_PHASE=1` (opcional). Es la base para decidir la fase 2.

---

## 6. Criterios para pasar a la fase 2 y a la fase 3

### 6.1 Fase 2 (`canary`: el motor sella en la conversación canaria y se puede publicar)

**Antes:**
- [ ] Fase 1 estable 24 h: 0 ERROR del Studio o del motor, 0 sellos, `publication-check` 0, workers estables, colas fallidas sin crecer y el CRM probado con sesión real.
- [ ] M-1/AS-1 corregido en el backend (`filter_var(…, FILTER_VALIDATE_BOOLEAN)`) o aceptado por escrito.
- [ ] **Autorización expresa del propietario** para la fase 2, con nombre de quién publicará: con `ultron.publish` (Super Admin por defecto) y distinto del autor de la versión.
- [ ] **Puerta del laboratorio decidida:**
  - `required` (el defecto) exige un veredicto `approved` sobre la misma huella con los prompts vigentes, es decir, la fase 3 del Hito 4 con la clave de OpenAI del propietario. Es el camino recomendado (decisión 2 del contrato).
  - `waived` lo escribe el propietario en el fichero de entorno, queda en cada publicación y el CRM lo pinta en rojo. Se cambia con copia previa: `install -m 600 -o root -g root /var/www/api/backend/.env /root/env-pre-lab-gate-$(date -u +%Y%m%d-%H%M%S)`, después `sed -i -E 's/^([[:space:]]*)ULTRON_STUDIO_PUBLISH_LAB_GATE[[:space:]]*=.*/\1ULTRON_STUDIO_PUBLISH_LAB_GATE=waived/'` y `config:cache` con `umask 077` + `chmod 600`.
- [ ] El motor tiene canario (`canary_set: true`) y el freno está suelto.
- [ ] `GET engine/readings` da lo que declarará la primera versión (A1): `governance.enabled` true, `governance.canaryOnly` true, `rules.paymentLinksEnabled` false e `intent.driver` del entorno.
- [ ] Una versión de la línea base, creada por una persona y **aprobada por otra**.

**Pasos:**
1. `bash /root/h5-engine-scope.sh canary off` (con la propuesta B; la versión actual es `bash /root/h5-engine-scope.sh canary`). Esperado: `ALCANCE canary APLICADO`.
2. `ssh ironbody-vps 'SMOKE_PHASE=2 bash /root/smoke-run-h5.sh'` **antes de publicar nada**, y sin nadie publicando mientras corre. Esperado: código 0. Con una APPROVED real, su vista previa no tiene que bloquear por las llaves.
3. Publicar desde el CRM, dentro de la ventana 07:00–19:00 de Bogotá, con la vista previa y su «sin efecto» reconocido.
4. En el siguiente turno de la conversación canaria, ver el sello sin imprimir ids:

   ```sql
   SELECT created_at, metadata->'studio'->>'state' AS estado, metadata->'studio'->>'part' AS parte, metadata->'studio'->>'reason' AS motivo
     FROM marketing_ai_actions WHERE metadata ? 'studio' ORDER BY id DESC LIMIT 5;
   ```

   Esperado: `applied` / `canary` en la canaria y `out_of_scope` en el resto.

### 6.2 Fase 3 (`all`) y la promoción

**Antes:**
- [ ] La evidencia del canario pasa (`GET versions/{uuid}/canary-evidence` → `passes: true`; el CRM la enseña en «Promover a todas»):
  - al menos 24 h desde la publicación;
  - al menos 3 turnos;
  - `stamped` igual a `turns` (100 % `applied` con ESTA versión);
  - 0 frenos desde la entrada y el freno suelto ahora;
  - el acta con total 0;
  - sin deriva;
  - `signatureOk`.

  Con unos 3 turnos por semana, son días. No se fuerza: sin turnos no hay evidencia, y es correcto (A6).
- [ ] Ningún `fallback` en los sellos de la ventana: un turno así se queda para siempre en la evidencia y ese canario ya no puede promoverse.
- [ ] **Autorización expresa del propietario** para la fase 3.

**Pasos:**
1. `bash /root/h5-engine-scope.sh all canary`.
2. `ssh ironbody-vps 'SMOKE_PHASE=3 bash /root/smoke-run-h5.sh'`: la cabeza verifica, `applied` y sin deriva.
3. Promover desde el CRM (una persona distinta del autor, dentro de la ventana, con el `evidenceHash` de la evidencia que se acaba de ver).
4. Comprobar `publication-check` 0, `status.engine.activeVersion` igual a la versión y `state: applied`.

**Honestidad (A6):** con el canario del motor puesto, `canary` y `all` llegan a la misma conversación. La promoción no cambia el tráfico.

**El freno:** lo echa el vigía ante un turno mudo del canario, por cualquier causa. Lo suelta quien gobierna el motor, a mano y con firma. El Studio no lo toca. Con el freno echado, publicar y promover responden 409 `engine_braked`.

---

## 7. Vuelta atrás

### 7.1 Por fase (inmediata; no toca código ni datos del motor)

| Desde → hacia | Comando (propuesta B; la versión actual no lleva el 2.º argumento) | Qué queda |
|---|---|---|
| 3 → 2 | `bash /root/h5-engine-scope.sh canary all` | La canaria sigue sellando y el resto sale `out_of_scope`. La cadena sigue declarando la versión publicada |
| 2 → 1 (parar de leer) | `bash /root/h5-engine-scope.sh off canary` (o `off all`) | El motor deja de consultar y de sellar. Publicar y promover dan 409. La cadena queda como estaba: una versión en CANARY sigue en CANARY |
| Parar de declarar | CRM «Volver a Legacy» (`POST publications/legacy`, llave `ultron.rollback`, motivo de 5 a 500 caracteres) | La CANARY pasa a ROLLED_BACK y la PUBLISHED, a RETIRED. Funciona sin las llaves de entorno, con cualquier llave del motor y con una cabeza rota |
| Una versión concreta | CRM «Revertir»: la CANARY pasa a ROLLED_BACK; la PUBLISHED restaura la anterior si es íntegra, y si no, Legacy | Sin llaves, sin cuatro ojos y sin ventana |

En la opción A ninguna de estas palancas cambia lo que ULTRON dice o hace: cambian el sello y la declaración. La emergencia de verdad (ULTRON) se gobierna con su pausa y su freno, que el Studio no toca.

### 7.2 De código: el backend de H5 → `fef7c87`, con las tablas en su sitio (ensayado en el banco)

Solo si el código de H5 se porta mal (por ejemplo, errores en la ruta del commit del motor). **Rollback de código no es rollback de datos.**

```bash
# 0. (fases 2–3) Antes, parar de leer y de declarar: el código de H4 no sabe mover versiones de H5.
bash /root/h5-engine-scope.sh off <alcance actual>                       # si no está ya en off
#    y «Volver a Legacy» desde el CRM si la cadena declara algo.
# 1. CRM anterior (opcional: los dos CRM leen los dos backends según el código; mantiene la pareja coherente)
cp /var/backups/ironbody/crm-pre-<build-crm>-<ts>/index.html /var/backups/ironbody/crm-pre-<build-crm>-<ts>/version.json /var/www/app/ && chown root:root /var/www/app/index.html /var/www/app/version.json
# 2. Backend (como en el despliegue; con M-3 resuelto, como el dueño del árbol)
cd /var/www/api && git checkout -q --detach fef7c87
cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'   # 33
sudo -u www-data php artisan auth:coverage --unmapped                     # vacío
supervisorctl status | grep -c RUNNING                                    # 13
# 3. Smoke de H4 (vuelve a valer con el código de H4)
SMOKE_LAB_EXPECT_ENABLED=1 bash /root/smoke-run-h4.sh                     # copiado antes desde ops/
```

**Ensayado:**
- En el banco: `git checkout --detach fef7c87` + cachés → 33 rutas, `GET status` 401, `GET publications` 404 (la ruta ya no existe), checkout limpio y la tabla `ultron_studio_publications` en su sitio.
- En la instantánea local con datos de H5 (cadena con 2 entradas y una versión `ROLLED_BACK`):
  - el smoke de H4, **sin modificar**, da 17/17;
  - `GET versions` y el detalle de la versión `ROLLED_BACK` dan 200, con `actions` deshabilitadas y `reachable: false`;
  - su `rollback` da 409 (el provisional de F/G) y `publications` 404. Es lo que dice A3.5.

**Lo que no se hace nunca en producción:**
- `migrate:rollback` / `down()`: con filas de H5 se niega, y borraría historia.
- Quitar las 7 líneas: el código de H4 no las lee.

**Volver a H5:** `cd /var/www/api && git checkout -q main` (la rama local sigue en el commit de H5) + las mismas cachés + `queue:restart` → 38 rutas y 0 migraciones pendientes (ensayado). Si `origin/main` avanzó entretanto, el script de la fase 1 (propuesta A) reanuda desde ahí.

**Límites:** la cadena es de solo inserción. Lo publicado, promovido o revertido en las fases 2 y 3 se queda en el historial, y el código de H4 lo enseña sin poder moverlo.

### 7.3 De datos (último recurso)

Solo ante corrupción de datos, con **autorización expresa** y coordinado por `database-specialist`, siguiendo `docs/ops/BACKUPS.md` («Restauración real»):
- primero a una base aparte;
- **nunca encima de la viva**;
- se pierde todo lo escrito desde el volcado.

Nada de la fase 1 lo justifica: sus migraciones son aditivas y no tocan datos.

---

## 8. Rotación de la `APP_KEY` con la cadena viva

**Lo que rompe la rotación en la cadena** (leído en el código y **ensayado en local**: publicación en canario con la clave A, reinicio con la clave B):
- **La cabeza deja de verificar:**
  - `status.engine.publication.state` = `fallback`, con `head.signatureOk: false`;
  - en `engine/readings`, `hashOk: true` y `signatureOk: false` (así se distingue de un contenido alterado, que da `hashOk: false`);
  - `ultron-studio:publication-check` sale con 1, `head_invalid`: **es la alarma**.
- **El motor sella `fallback/signature_invalid`** en todas las conversaciones (con su llave distinta de `off`). En la opción A no cambia nada más.
- **Revertir, publicar y promover** responden 409 `publication_head_invalid`.
- **`APP_PREVIOUS_KEYS` no salva la cadena:** `UltronPublishedConfig::subkey()` solo usa `config('app.key')` (`:462-473`).
- **Fuera de H5** (informe de `security`, §2): el `decide_token` (un turno en curso puede quedar mudo y, en la canaria, el vigía echa el freno), otras HMAC (`access_hash`, kiosco, etc.) y lo cifrado. **Rotar la `APP_KEY` es un evento de todo el sistema; H5 no lo justifica por sí solo.**

**Procedimiento** (solo con autorización del propietario y por otro motivo, por ejemplo una sospecha de fuga):
1. **Condiciones:**
   - una ventana sin tráfico de ULTRON: al menos 120 s desde el último `decide`, y mejor con ULTRON en pausa por quien la gobierna;
   - respaldo del fichero de entorno y de `bootstrap/cache/config.php` (root, 600).
2. **Foto:** `sudo -u www-data php artisan ultron-studio:publication-check --json > /root/pre-rotacion-publication.json` (sin secretos ni ids). Anotar el `seq`, la acción y la versión de cada parte.
3. **Si hay una versión en CANARY**, decidir antes: (a) `bash /root/h5-engine-scope.sh off <actual>`, para no sellar `fallback` durante la transición, o (b) aceptar que ese canario se pierde (`stamped ≠ turns` para siempre).
4. **Rotar** (procedimiento global, fuera de este informe):
   - `sudo -u www-data php artisan key:generate --force` (nunca `--show`);
   - la clave vieja, en `APP_PREVIOUS_KEYS`;
   - `config:cache` con `umask 077` y `chmod 600`;
   - `queue:restart` y `supervisorctl restart 'ironbody-lab:*'`.
5. **Comprobar la alarma:** `publication-check` con 1 y `head_invalid` (si la cadena tenía entradas). Con la cadena vacía, sale 0 y no hay nada más que hacer.
6. **Salida:** CRM «Volver a Legacy» (o `POST publications/legacy`), con el motivo «Rotación de la APP_KEY del <fecha>, autorizada por <quién>». El smoke no confunde ese motivo con una fuga, porque los campos de texto libre quedan fuera del patrón de secretos (probado). Es la única escritura que acepta una cabeza que no verifica, y firma `{null, null}` con la clave nueva.
7. **Comprobar:** `publication-check` 0, con `state: legacy` (u `off`) y `signatureOk: true`. `SMOKE_PHASE=<fase actual>`: código 0.
8. Si en el paso 3 se apagó la llave del motor, volver al alcance anterior **con la autorización de esa fase**.
9. **Recuperar la declaración:** versión nueva con el mismo contenido → aprobación de otra persona → laboratorio → publicar en la ventana → canario (24 h y 3 turnos sellados) → promover. **`ROLLED_BACK` y `RETIRED` no vuelven.** Las entradas viejas siguen en el historial, pero ya no verifican ni sirven de origen de una restauración: dejarlo escrito en el acta. Esto corrige R6 del plan, que decía «volver a publicar» (B-3 de `security`).

**Ensayado en local** (instantánea `d98bbf9`, PG 14):
- Antes de rotar, `seq 1 publish`, `applied`.
- Tras rotar:
  - `state: fallback` y `signatureOk: false`;
  - `publication-check` con 1, «NO VERIFICA … head_invalid»;
  - el sello del motor, `{"state":"fallback","reason":"signature_invalid"}` en la canaria y en otra conversación;
  - el smoke con 2 FALLO (`firma=False` con `hash=True`);
  - revertir → 409 `publication_head_invalid`.
- Legacy → 201, `seq 2`, `signatureOk: true`, la versión → `ROLLED_BACK`, `publication-check` 0 y el smoke en 0.

---

## 9. Incidencias previsibles durante el despliegue

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `ABORTA` en el paso 0 | Algo de partida no es lo esperado | No se ha tocado nada. Aclarar la causa y repetir |
| `55P03 canceling statement due to lock timeout` en la migración | Alguien tenía `ultron_studio_versions` (lectura incluida) | Si cayó la 100001, no queda nada. Si cayó la 100002, queda la tabla sin guardas: no la usa nadie con las llaves apagadas. **Relanzar el mismo comando** (reanuda) hasta 30/27/3/3/2. Mientras espera (hasta 5 s), las lecturas del Studio sobre versiones hacen cola; el motor no lee versiones |
| `42501 must be owner of function ultron_studio_forbid` | La función o la tabla no son de `iron` | Parar. La transferencia de propiedad es de `database-specialist`, con autorización. **No forzar** |
| Workers en BACKOFF tras `queue:restart` | La caché no es de `www-data` o no se puede leer | `stat -c '%U %a' bootstrap/cache/*.php`; `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'` + `chmod 600`; `supervisorctl restart all` **solo con autorización** (reinicia servicios productivos) |
| El smoke da 429 en el login | Más de 3 ejecuciones por minuto | Esperar un minuto. La cuenta ya está en reposo |
| `publication_head_invalid` sin rotación de clave | La cabeza no verifica: fila alterada o envenenamiento de `seq` (N-3/AS-3) | Salida inmediata, `h5-engine-scope.sh off <actual>`. Si `seq` = 2147483647, Legacy da 500 (22003) y el Studio se queda sin salida: la reparación es del rol dueño, con autorización del propietario, porque toca el libro (`database-specialist`). Avisar a `security` |
| `status` sin `engine.publication` | `state()` falla: tabla, privilegios o 100002 a medias | `grep ultron_studio.publication_state_unavailable` en el log y `h5-schema-check.sql` |

---

## 10. Ensayo local: qué se validó y cómo repetirlo

**Entorno:**
- Instantánea `git archive` de `d98bbf9` con `vendor` clonado por APFS (`cp -cR`, no enlazado).
- PostgreSQL 14.24 desechable en `127.0.0.1:55432`, base propia `ultron_studio_smoke_h5`, 315 migraciones.
- `php artisan serve --no-reload` en `127.0.0.1:8137`, con `PGGSSENCMODE=disable` y sin fichero de entorno: todo va por variables, con una `APP_KEY` desechable en un fichero 0600.
- Canario configurado (id ficticio de 9 cifras), laboratorio en `fake`.
- Cuentas locales: la de smoke (inactiva), una autora y una revisora (Super Admin). La versión APPROVED se creó por HTTP, como el CRM.
- No ejecuté PHPUnit en el worktree ni usé puertos de otros agentes (solo los mayores de 8100).

| Prueba | Resultado |
|---|---|
| `python3 -m py_compile smoke-h5.py` (Python 3.9.6), `bash -n smoke-run-h5.sh` | OK |
| Fase 1, sin y con una versión APPROVED | 35 + 14 y 36 + 14 OK, código 0. Las **217 tablas** con el mismo recuento exacto antes y después. Log: 8 INFO `auth:admin:failed` y 4 WARNING `auth:admin:forbidden`, ningún ERROR |
| Fase 2 (con la cadena vacía y con una versión en CANARY) y fase 3 | 34 + 14 OK cada una, código 0, 217 tablas idénticas; con la CANARY no intentó Legacy |
| Pasada final con los ficheros definitivos (base recreada) | Fases 1, 2 y 3: código 0, 0 FALLO, 0 OMITIDO, 0 líneas de diferencia en las 217 tablas |
| Lanzador de la fase 1 contra la configuración de la fase 2 | Se niega antes de activar la cuenta (código 1), sin temporales |
| Fase 1 con la cadena no vacía | 4 FALLO; Legacy OMITIDO («podría escribir»); 217 tablas idénticas; cuenta en reposo |
| Contra el código de H4 (`fef7c87`) | Lanzador: `engine_scope` null, no se ejecuta. Python: 16 FALLO; las 5 rutas nuevas dan 404 en vez de 401 |
| 429 del login | FALLO con la pista del limitador; la cuenta en reposo |
| Sin segunda cuenta | Código 3 |
| `test_find_leaks.py` (sin red) | 22/22 |
| Rotación de la `APP_KEY` | §8 |
| Código de H4 sobre datos de H5 | §7.2 |
| `h5-schema-check.sql` | 30/27/3/3/2/t, huella `4ad748b5…`, 0 cerrojos |
| Banco de los scripts de despliegue | §3.3 y §3.4 |

**Cómo repetirlo.** Los ayudantes están en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/ea963cf5-0bc6-4c7f-b9ea-04e40544815f/scratchpad/devops/`, **que se purga a los 3 días**: `local-env.sh`, `serve.sh`, `seed-approved.py`, `chain-local.py`, `test_find_leaks.py`, `test-ensure-var.sh`, `test-log-count.sh`, `all-counts.sql`, `banco/` y `propuesta/`. Si se quieren conservar, el lead decide qué pasa a `ops/`; propongo `h5-schema-check.sql` y `test_find_leaks.py`. El núcleo del ensayo, sin los ayudantes, es este:

```bash
# instantánea y base desechable (nunca la del fichero de entorno del worktree)
git -C <repo> archive --format=tar HEAD backend | tar -x -C <dir>; cp -cR <worktree>/backend/vendor <dir>/backend/vendor
createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_smoke_h5
env APP_KEY=base64:<desechable> DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_smoke_h5 DB_USERNAME=ironbody DB_PASSWORD= DB_URL= PGGSSENCMODE=disable MARKETING_ULTRON_STUDIO_CONFIG=off ULTRON_STUDIO_PUBLISHING_ENABLED=false … php artisan migrate --force
# … admin de smoke inactivo + import-baseline + lab-import-scenarios + artisan serve --no-reload en :8137
env APP_DIR=<dir>/backend SMOKE_API=http://127.0.0.1:8137/api SMOKE_RUN_AS= SMOKE_PSQL="psql -X -Atq -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_smoke_h5" \
    SMOKE_WORKDIR=<scratch> SMOKE_PY=<repo>/backend/docs/ultron-studio/ops/smoke-h5.py SMOKE_ADMIN_ID=<id local> SMOKE_PHASE=1 \
    bash <repo>/backend/docs/ultron-studio/ops/smoke-run-h5.sh
```

Las bases `ultron_studio_smoke_h5` y `ultron_studio_banco_h5` siguen en el clúster local. Las creé yo para esto y se pueden borrar con `dropdb -h 127.0.0.1 -p 55432 -U ironbody <base>`. No las he borrado.

---

## 11. Lo que no pude verificar (queda para producción, con cómo hacerlo)

| Qué | Por qué no | Cómo se verifica |
|---|---|---|
| El envoltorio `ironbody-migrate`: cómo lanza PHP, si conserva `PGOPTIONS`, con qué umask escribe `config.php` y si hace `chmod` | No está en el repo; sin SSH | Los recuentos de §4.1 (sin imprimir el fichero, que puede llevar credenciales). La propuesta A corrige el modo de todos modos |
| El dueño de `.git` y `safe.directory` (M-3) | Sin SSH | §4.1. Si `.git` es escribible por `www-data`, vuelve a `security` |
| Que el fichero de entorno acabe en salto de línea y que las 7 claves no existan | Sin SSH | §4.1 (el paso 0 de A lo comprueba) |
| Los privilegios reales de `iron_app` sobre la tabla nueva | Solo existen tras migrar | §4.6 y el paso 4 de A |
| Los permisos reales del rol Administrador (¿se tocaron en Roles?) | Sin acceso | Lo dice el smoke (parte del Administrador) |
| La longitud del id del canario | Sin acceso | El lanzador lo lee sin imprimirlo. Con menos de 4 cifras, el detector solo lo busca bajo claves de canario o conversación (lo avisa con un INFO) |
| Que el `sudo` de producción pase la entrada estándar a `tinker` | Probado en macOS sin `sudo` | La activación del smoke falla de forma visible si no («no se pudo activar la cuenta») |
| GNU `grep`/`sed`/`stat` con las funciones nuevas | Docker parado; no lo arranqué | Son POSIX (`[[:space:]]`, `sed -E` sin `-i` en la fase 1); el ensayo `--solo-comprobar` lo confirma en el servidor |
| Que FPM vea el `config:cache` sin recargarse | No se puede probar en local | Inferido del Hito 4: el smoke de su fase 2 vio `enabled: true` sin recargar FPM. El smoke de cada fase lo vuelve a comprobar |
| El tiempo real del smoke y del despliegue en el servidor | — | El banco tarda unos 40 s en el despliegue y unos 15 s en el smoke |
| Si la `APP_KEY` de producción es distinta de la de otros entornos (N-2) | Sin acceso | Comparar el `sha256` de la clave decodificada en cada entorno, sin imprimirla (`security`) |

---

## 12. Riesgos residuales, deuda y hallazgos para otros dueños

- **`deploy-h4-backend-fase1.sh` en el repo (versión `b68932e`) tiene D-1 *después* del `merge`.** Si alguien lo reutiliza, aborta con el código fusionado y sin variables, migraciones ni cachés, es decir, a medias. Marcarlo como obsoleto o corregirlo igual (lead).
- **Permisos de los respaldos de G y de H4.** Se crearon sin `umask 077`: el volcado completo (datos personales y hashes) puede estar en 644 dentro de un directorio 755. Verificar con `stat -c '%a %n' /var/backups/ironbody /var/backups/ironbody/ultron-studio-*/ /var/backups/ironbody/ultron-studio-*/ironbody.dump`. Si salen abiertos, `chmod 700` a los directorios y `chmod 600` a los volcados, **con autorización**. **Nunca se borra un respaldo.**
- **Las copias `/root/env-pre-scope-*` de la propuesta B** (y `env.backup` de cada respaldo) llevan secretos. Están en 600 dentro de `/root`. Quitarlas lo decide el propietario.
- **M-1/AS-1** (`=off` abre publicar): es del backend (`filter_var`) y del tester. En la fase 1, las propuestas A y B lo detectan (el JSON tendría `publishing_enabled: true` y abortan). Tiene que estar cerrado o aceptado antes de la fase 2.
- **AS-2** (promover ejecuta el acta sin el limitador de la evidencia): backend y `api-security-specialist`.
- **AS-3/N-3** (el envoltorio de `seq` deja la cadena sin salida): runbook en §9. La corrección estructural (`CHECK (seq >= 1)`, `verified` por entrada) es del architect y de `database-specialist`.
- **D-13/M-3** (git como root): verificar en §4.1; la decisión es de `security`.
- **El smoke deja huella** en la fila 32 y en el log (§2.3), y el limitador del login permite como mucho 3 ejecuciones por minuto.
- **`PREVIO=fef7c87` está fijo en la propuesta A**, a propósito. Si producción se mueve antes de la fase 1 (otro equipo despliega), el paso 0 aborta: hay que revisar qué entró y actualizar `PREVIO` en un commit.
- **La deriva de `smoke-h4.py`:** con el backend de H5 falla por diseño (§4.8). No es un fallo de producción.

---

## 13. Acciones pendientes de autorización (comandos exactos)

| # | Acción | Quién autoriza | Comando |
|---|---|---|---|
| 1 | Confirmar las correcciones de los scripts (A o C, y B) | Lead | `git apply` de los apéndices y su commit (no lo hago yo) |
| 2 | Push con avance rápido de backend y CRM | Usuario | `git push origin feat/ultron-studio-h5:main` en cada repo, solo si es avance rápido; **nunca `--force`** |
| 3 | Respaldo y despliegue de la fase 1 | Usuario | §4.2; `bash /root/deploy-h5-backend-fase1.sh <commit-backend> "$BK" --solo-comprobar` y después sin `--solo-comprobar` |
| 4 | CRM | Usuario | `docs/ultron-studio/ops/deploy-crm.sh <build-crm> <crm-prod>` |
| 5 | Smoke de la fase 1 (escribe en la fila 32 y en `admin_sessions`, y lo deja en reposo) | Usuario | `ssh ironbody-vps 'SMOKE_PHASE=1 bash /root/smoke-run-h5.sh'` |
| 6 | Endurecer los respaldos de G y H4 si salen abiertos | Usuario | `chmod 700 /var/backups/ironbody/ultron-studio-<…>/ && chmod 600 /var/backups/ironbody/ultron-studio-<…>/ironbody.dump` |
| 7 | Fase 2 | Propietario | `bash /root/h5-engine-scope.sh canary off` → `SMOKE_PHASE=2` → publicar desde el CRM |
| 8 | Fase 3 | Propietario | `bash /root/h5-engine-scope.sh all canary` → `SMOKE_PHASE=3` → promover desde el CRM |
| 9 | Remediar M-3 si `.git` es escribible por `www-data` | `security` + usuario | Lo que decida `security` |

---

## Apéndices

Los diffs se generaron con `diff -u` sobre `HEAD` (`d98bbf9`; los dos scripts no han cambiado desde `61048ff`) y **`git apply --check` pasa con los tres**. Las propuestas completas, con su huella, están en el scratchpad (`propuesta/`). Si se pierden, el diff de abajo basta.

### Apéndice A. Propuesta completa para `deploy-h5-backend-fase1.sh` (ejecutada en el banco, §3.3)

Huella del script resultante: `sha256 e27740212ab0bf6d46f398016d573ea34e6673f00936941ecfa0f3954acca531`.

```diff
--- a/backend/docs/ultron-studio/ops/deploy-h5-backend-fase1.sh
+++ b/backend/docs/ultron-studio/ops/deploy-h5-backend-fase1.sh
@@ -1,49 +1,141 @@
 #!/bin/bash
 # Fase 1 del Hito 5 en producción: código desplegado con TODO apagado (el motor no lee la cadena y publicar sigue
-# en 409). Se ejecuta EN EL SERVIDOR como root tras el respaldo. Orden: código → variables ANTES de ironbody-migrate
-# (regenera la caché como www-data) → route:cache → queue:restart → comprobaciones SQL/rutas/config → sanidad.
-# Nunca imprime valores del fichero de entorno.   Uso: bash /root/deploy-h5-backend-fase1.sh <commit esperado>
+# en 409). Se ejecuta EN EL SERVIDOR como root tras el respaldo. Orden: comprobaciones de solo lectura (abortan sin
+# tocar nada) → código → variables ANTES de ironbody-migrate (regenera la caché como www-data) → route:cache →
+# cachés www-data 600 → queue:restart → aserciones SQL/rutas/config → sanidad. Cada aserción que falla se dice y
+# el script termina con 1; «COMPLETADA» solo sale con todo en verde. Nunca imprime valores del fichero de entorno.
+# Uso: bash /root/deploy-h5-backend-fase1.sh <commit esperado> <directorio del respaldo de hoy> [--solo-comprobar]
+#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
 set -euo pipefail
 export LC_ALL=C
 ESPERADO="${1:?commit esperado en origin/main}"
+BK="${2:?directorio del respaldo de hoy (/var/backups/ironbody/ultron-studio-h5-<fecha UTC>)}"
+SOLO_COMPROBAR="${3:-}"
+PREVIO=fef7c87                                   # lo que sirve producción antes del Hito 5
+PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
+aborta() { echo "ABORTA: $*"; exit 1; }
+DESVIOS=0
+desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
+VARS="MARKETING_ULTRON_STUDIO_CONFIG=off ULTRON_STUDIO_PUBLISHING_ENABLED=false ULTRON_STUDIO_PUBLISH_LAB_GATE=required ULTRON_STUDIO_CANARY_MIN_HOURS=24 ULTRON_STUDIO_CANARY_MIN_TURNS=3 ULTRON_STUDIO_PUBLISH_WINDOW=07:00-19:00 QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS=300"
+ENVF=/var/www/api/backend/.env
+estado_var() { # CLAVE VALOR → falta | igual | distinto | repetida (sin imprimir el valor)
+  local n v
+  n=$(grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true)
+  [ "$n" = 0 ] && { echo falta; return; }
+  [ "$n" = 1 ] || { echo repetida; return; }
+  v=$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\\1/")
+  [ "$v" = "$2" ] && echo igual || echo distinto
+}
+LOGS=/var/www/api/backend/storage/logs
+lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
+
+echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
 cd /var/www/api
 git fetch -q origin
-MODIFICADOS=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')
-[ "$MODIFICADOS" = "0" ] || { echo "el checkout de producción tiene cambios locales: no se despliega"; exit 1; }
+ACTUAL=$(git rev-parse --short HEAD)
+case "$ACTUAL" in
+  "$PREVIO")   REANUDA=0 ;;
+  "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variables, migración y comprobaciones" ;;
+  *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL)" ;;
+esac
+MODIFICADOS=$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')   # sin grep: con pipefail, un grep sin coincidencias mataría el script
+[ "$MODIFICADOS" = 0 ] || aborta "el checkout de producción tiene $MODIFICADOS cambios locales"
+[ "$(git rev-parse --short origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(git rev-parse --short origin/main), no $ESPERADO"
+git merge-base --is-ancestor "$PREVIO" origin/main || aborta "origin/main no es avance rápido desde $PREVIO"
+NUEVAS=$(git diff --name-only --diff-filter=AMDR "$PREVIO" origin/main -- backend/database/migrations | sort | tr '\n' ' ')
+[ "$NUEVAS" = "backend/database/migrations/2026_10_25_100001_create_ultron_studio_publications_table.php backend/database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php " ] \
+  || aborta "migraciones nuevas o tocadas entre $PREVIO y origin/main distintas de las dos del Hito 5: [$NUEVAS]"
+case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in "$PREVIO"*) ;; *) aborta "$BK/api-commit.txt no es $PREVIO" ;; esac
+[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
+[ "$(stat -c '%a' "$BK/ironbody.dump")" = 600 ] && [ "$(stat -c '%a' "$BK/env.backup")" = 600 ] || aborta "el volcado y env.backup tienen que ser 600"
+TABLAS=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
+[ "$TABLAS" -ge 211 ] || aborta "el volcado solo lista $TABLAS tablas con datos (al menos 211)"
+echo "   respaldo $BK: $TABLAS tablas con datos"
+[ -z "$(tail -c1 "$ENVF")" ] || echo "   el fichero de entorno no acaba en salto de línea: se añadirá uno antes de las claves"
+for kv in $VARS; do
+  case "$(estado_var "${kv%%=*}" "${kv#*=}")" in
+    falta|igual) ;;
+    *) aborta "${kv%%=*} ya existe con otro valor o repetida (no se imprime): revisar a mano antes de desplegar" ;;
+  esac
+done
+PRE=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(to_regclass('public.ultron_studio_publications') IS NOT NULL);")
+if [ "$REANUDA" = 0 ]; then
+  [ "$PRE" = "27 24 iron iron true false" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [27 24 iron iron true false]); si la función o versiones no son de iron, la 100002 falla con 42501"
+else
+  case "$PRE" in *" iron iron true "*) ;; *) aborta "dueños o permiso CREATE inesperados: [$PRE]" ;; esac
+fi
+HUELLA=$($PSQL -c "SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';")
+BLOQ=$($PSQL -c "SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'ultron_studio_versions' AND l.pid <> pg_backend_pid();")
+[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos sobre ultron_studio_versions (la 100002 pide ACCESS EXCLUSIVE, que choca también con un SELECT abierto): esperar y repetir"
+LOG_ANTES=$(lineas_log)                          # para contar solo los ERROR que se escriban desde aquí
+FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;")   # por SQL: en Laravel 12 queue:failed ya no pinta «| id |» y el grep de H4 contaba siempre 0
+[ "$REANUDA" = 0 ] && ESQUEMA="esquema 27/24 de iron" || ESQUEMA="reanudación: dueños iron y CREATE comprobados"
+echo "   HEAD $ACTUAL limpio · $ESQUEMA · sin cerrojos · claves del Hito 5 ausentes o iguales · colas fallidas: $FALLIDAS_ANTES"
+[ "$SOLO_COMPROBAR" != --solo-comprobar ] || { echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"; exit 0; }
+
 git merge -q --ff-only origin/main
 HEAD=$(git rev-parse --short HEAD)
-[ "$HEAD" = "$ESPERADO" ] || { echo "HEAD $HEAD no es $ESPERADO"; exit 1; }
+[ "$HEAD" = "$ESPERADO" ] || aborta "HEAD $HEAD no es $ESPERADO"
 echo "1. código: HEAD=$HEAD"
 cd backend
-ENVF=.env
-add_var() { if grep -Eq "^\s*$1=" "$ENVF"; then echo "   $1 ya existe (no se toca)"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; echo "   $1 añadida"; fi; }
 echo "2. variables (todo apagado):"
-add_var MARKETING_ULTRON_STUDIO_CONFIG off
-add_var ULTRON_STUDIO_PUBLISHING_ENABLED false
-add_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
-add_var ULTRON_STUDIO_CANARY_MIN_HOURS 24
-add_var ULTRON_STUDIO_CANARY_MIN_TURNS 3
-add_var ULTRON_STUDIO_PUBLISH_WINDOW 07:00-19:00
-add_var QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS 300
-echo "   permisos del fichero: $(stat -c '%U %a' $ENVF)"
+[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
+for kv in $VARS; do
+  k=${kv%%=*}; v=${kv#*=}
+  if [ "$(estado_var "$k" "$v")" = falta ]; then printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida"; else echo "   $k ya estaba con el valor de la fase 1"; fi
+done
+echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
 echo "3. migración (rol dueño, lock_timeout 5 s):"
 sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1 | sed 's/^/   /'
-sudo -u www-data php artisan route:cache -q
-chmod 600 bootstrap/cache/routes-v7.php
+sudo -u www-data sh -c 'umask 077; php artisan route:cache -q'
+for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
+  [ "$(stat -c '%U' "$f")" = www-data ] || aborta "$f no es de www-data: no se reinician los workers (quedarían en BACKOFF)"
+  [ "$(stat -c '%a' "$f")" = 600 ] || { chmod 600 "$f"; echo "   $f pasado a 600"; }
+done
 echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
 sudo -u www-data php artisan queue:restart -q
 echo "4. SQL:"
-sudo -u postgres psql -Atd ironbody -c "SELECT 'disparadores studio: '||count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;" | sed 's/^/   /'
-sudo -u postgres psql -Atd ironbody -c "SELECT 'CHECK studio: '||count(*) FROM pg_constraint WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';" | sed 's/^/   /'
-sudo -u postgres psql -Atd ironbody -c "SELECT 'cadena: '||count(*)||' filas · índices parciales: '||(SELECT count(*) FROM pg_indexes WHERE tablename='ultron_studio_versions' AND indexname IN ('usv_one_canary','usv_one_published')) FROM ultron_studio_publications;" | sed 's/^/   /'
-echo "5. rutas studio: $(sudo -u www-data php artisan route:list --path=admin/ultron-studio 2>/dev/null | grep -c ultron-studio) · auth:coverage --unmapped → [$(sudo -u www-data php artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-40)]"
+POST=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'uspub_%')||' '||(SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uspub_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('usv_one_canary', 'usv_one_published') AND i.indisunique AND i.indpred IS NOT NULL)||' '||(SELECT pg_get_constraintdef(oid) LIKE '%ROLLED_BACK%' FROM pg_constraint WHERE conname = 'usv_status_chk')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications')||' '||(SELECT count(*) FROM ultron_studio_publications);")
+echo "   disparadores · CHECK · uspub disp. · uspub CHECK · parciales · 9 estados · dueño función · dueño cadena · filas: $POST"
+[ "$POST" = "30 27 3 3 2 true iron iron 0" ] || desvio "esquema tras migrar [$POST], esperado [30 27 3 3 2 true iron iron 0] (si la 100002 cayó por 55P03, relanzar este mismo script: reanuda)"
+[ "$($PSQL -c "SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';")" = "$HUELLA" ] || desvio "la función ultron_studio_forbid cambió de cuerpo"
+PRIV=$($PSQL -c "SELECT has_table_privilege('iron_app', 'public.ultron_studio_publications', 'SELECT,INSERT,UPDATE,DELETE')||' '||has_table_privilege('iron_app', 'public.ultron_studio_publications', 'TRUNCATE')||' '||has_sequence_privilege('iron_app', 'public.ultron_studio_publications_id_seq', 'USAGE');")
+echo "   iron_app sobre la cadena (DML · TRUNCATE · secuencia): $PRIV"
+[ "$PRIV" = "true false true" ] || desvio "privilegios de iron_app sobre la cadena [$PRIV], esperado [true false true]"
+RUTAS=$(sudo -u www-data php artisan route:list --path=admin/ultron-studio --json 2>/dev/null | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' || echo '?')
+COBERTURA=$(sudo -u www-data php artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
+echo "5. rutas studio: $RUTAS · auth:coverage --unmapped → [$COBERTURA]"
+[ "$RUTAS" = 38 ] || desvio "rutas del Studio: $RUTAS (esperado 38)"
+[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
 echo "6. configuración efectiva:"
-sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_min" => [config("ultron_studio.publishing.canary_min_hours"), config("ultron_studio.publishing.canary_min_turns")], "window" => config("ultron_studio.publishing.window"), "lab_unattended_s" => config("queue.lanes.lab.unattended_after_seconds"), "lab_enabled" => (bool) config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver")]), PHP_EOL;' 2>&1 | tail -1 | sed 's/^/   /'
+H5=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_min" => [config("ultron_studio.publishing.canary_min_hours"), config("ultron_studio.publishing.canary_min_turns")], "window" => config("ultron_studio.publishing.window"), "lab_unattended_s" => config("queue.lanes.lab.unattended_after_seconds")]), PHP_EOL;' 2>&1 | tail -1 || true)
+case "$H5" in '{"engine_scope"'*) echo "   $H5" ;; *) echo "   (tinker no devolvió la configuración; su salida no se imprime)" ;; esac
+[ "$H5" = '{"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300}' ] || desvio "la configuración efectiva no es la de la fase 1"
+LAB=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["lab_enabled" => (bool) config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null]), PHP_EOL;' 2>&1 | tail -1 || true)
+case "$LAB" in '{"lab_enabled"'*) echo "   Hito 4 y canario (informativo): $LAB" ;; *) echo "   (sin lectura del laboratorio)" ;; esac
 sleep 6
-echo "7. workers RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
+RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
+echo "7. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL"
+[ "$RUNNING" = 13 ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado 13 y 0)"
 echo "8. sanidad:"
 sleep 3
-for p in status lab/status publications; do echo "   $p sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p)"; done
-echo "   colas fallidas: $(sudo -u www-data php artisan queue:failed 2>/dev/null | grep -c '^| [0-9a-f]')"
-echo "   filas de marketing_ai_actions con sello 'studio' (debe ser 0 con el motor en off): $(sudo -u postgres psql -Atd ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio'")"
-echo "FASE 1 (H5) COMPLETADA"
+for p in status lab/status publications versions/00000000-0000-4000-8000-0000000005a5/publication-preview; do
+  c=$(curl -s -o /dev/null -w '%{http_code}' "https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p")
+  echo "   $p sin token → $c"; [ "$c" = 401 ] || desvio "$p sin token respondió $c (esperado 401)"
+done
+FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
+echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
+SELLOS=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';")
+echo "   filas de marketing_ai_actions con sello 'studio' (0 con el motor en off): $SELLOS"; [ "$SELLOS" = 0 ] || desvio "hay sellos del Studio con el motor en off"
+ERRORES=0
+for f in "$LOGS"/laravel*.log; do
+  [ -f "$f" ] || continue
+  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
+  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR' || true)
+  ERRORES=$((ERRORES + e))
+done
+echo "   laravel.log ERROR desde el inicio del despliegue: $ERRORES"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
+sudo -u www-data php artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa"
+sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
+[ "$(git -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
+if [ "$DESVIOS" = 0 ]; then echo "FASE 1 (H5) COMPLETADA"; else echo "FASE 1 (H5) CON $DESVIOS DESVÍOS: no seguir con el CRM ni con el smoke hasta aclararlos"; exit 1; fi
```

### Apéndice B. Propuesta para `h5-engine-scope.sh` (ejecutada en el banco, §3.4)

Huella del script resultante: `sha256 752d8cebf3ccb71ccc733dc03552a055871ffdcbdb5e554eaf22cb20bec7270e`.

```diff
--- a/backend/docs/ultron-studio/ops/h5-engine-scope.sh
+++ b/backend/docs/ultron-studio/ops/h5-engine-scope.sh
@@ -1,37 +1,86 @@
 #!/bin/bash
 # Hito 5 · alcance de lectura del motor sobre el Studio. Se ejecuta EN EL SERVIDOR como root y SOLO con la
-# autorización expresa del propietario para la fase que corresponda:
-#   bash h5-engine-scope.sh off      (fase 1 / vuelta atrás: el motor no consulta la cadena; Legacy exacto)
-#   bash h5-engine-scope.sh canary   (fase 2: el motor lee en la conversación canaria y el Studio puede publicar)
-#   bash h5-engine-scope.sh all      (fase 3: todas las conversaciones)
-# Escribe las dos llaves de entorno (MARKETING_ULTRON_STUDIO_CONFIG y ULTRON_STUDIO_PUBLISHING_ENABLED), regenera la
-# caché de configuración como www-data con modo 600, reinicia los workers y comprueba la configuración efectiva sin
-# imprimir valores sensibles. Nunca toca la clave de OpenAI, la pausa, el freno ni el canario del motor.
+# autorización expresa del propietario para la fase que corresponda. El segundo argumento es el alcance que hay
+# HOY: si no coincide con la configuración efectiva, no se toca nada.
+#   bash h5-engine-scope.sh canary off      (fase 2: el motor lee en la conversación canaria y el Studio puede publicar)
+#   bash h5-engine-scope.sh all canary      (fase 3: todas las conversaciones)
+#   bash h5-engine-scope.sh canary all      (vuelta de la fase 3 a la 2)
+#   bash h5-engine-scope.sh off canary|all  (vuelta a la fase 1: el motor no consulta la cadena; Legacy exacto)
+# off → all directo no se admite: la fase 3 llega después del canario y de su evidencia.
+# Escribe las dos llaves de entorno (MARKETING_ULTRON_STUDIO_CONFIG y ULTRON_STUDIO_PUBLISHING_ENABLED) tras una copia
+# previa 600 del fichero, regenera la caché de configuración como www-data con umask 077, comprueba que la
+# configuración efectiva es la pedida, reinicia los workers y comprueba sanidad, sin imprimir valores sensibles.
+# Nunca toca la clave de OpenAI, la pausa, el freno, el canario del motor ni la cadena de publicaciones.
 set -euo pipefail
 export LC_ALL=C
-ALCANCE="${1:?uso: off|canary|all}"
+ALCANCE="${1:?uso: h5-engine-scope.sh off|canary|all <alcance actual>}"
+DESDE="${2:?falta el alcance actual (off|canary|all): se comprueba antes de tocar nada}"
 case "$ALCANCE" in
-  off)    PUBLICAR=false ;;
-  canary) PUBLICAR=true ;;
-  all)    PUBLICAR=true ;;
+  off)        PUBLICAR=false ;;
+  canary|all) PUBLICAR=true ;;
   *) echo "alcance no válido: $ALCANCE (off|canary|all)"; exit 2 ;;
 esac
+case "$DESDE" in off|canary|all) ;; *) echo "alcance actual no válido: $DESDE (off|canary|all)"; exit 2 ;; esac
+[ "$ALCANCE" != "$DESDE" ] || { echo "el alcance ya es $ALCANCE: nada que hacer"; exit 0; }
+[ "$DESDE:$ALCANCE" != "off:all" ] || { echo "off → all directo no: primero canary (fase 2) y su evidencia"; exit 2; }
 cd /var/www/api/backend
 ENVF=.env
-set_var() { # escribe la clave con la sangría de dos espacios del fichero (la añade si falta)
-  if grep -Eq "^\s*$1=" "$ENVF"; then sed -i -E "s/^(\s*$1=).*/\1$2/" "$ENVF"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; fi
+aborta() { echo "ABORTA: $*"; exit 1; }
+DESVIOS=0
+desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
+leer() { # la configuración efectiva en una línea JSON, o nada (la salida de un tinker fallido no se imprime)
+  local l
+  l=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => (bool) config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
+  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
 }
+cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
+set_var() { # escribe la clave con la sangría que tuviera la línea (la añade, con dos espacios, si falta)
+  if [ "$(cuenta_var "$1")" != 0 ]; then
+    sed -i -E "s/^([[:space:]]*)(export[[:space:]]+)?$1[[:space:]]*=.*/\\1$1=$2/" "$ENVF"
+  else
+    [ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"     # sin salto final, la línea nueva se pegaría a la anterior
+    printf '  %s=%s\n' "$1" "$2" >> "$ENVF"
+  fi
+}
+
+ANTES=$(leer)
+[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva"
+case "$ANTES" in *"\"engine_scope\":\"$DESDE\""*) ;; *) aborta "la configuración efectiva no es la de «$DESDE»: $ANTES" ;; esac
+if [ "$ALCANCE" != off ]; then
+  case "$ANTES" in *'"canary_set":true'*) ;; *) aborta "el motor no tiene canario configurado: sin él no hay fase 2 ni 3" ;; esac
+fi
+for k in MARKETING_ULTRON_STUDIO_CONFIG ULTRON_STUDIO_PUBLISHING_ENABLED ULTRON_STUDIO_PUBLISH_LAB_GATE; do
+  [ "$(cuenta_var "$k")" -le 1 ] || aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
+done
+DUENO=$(stat -c '%U:%G' "$ENVF")
+TS=$(date -u +%Y%m%d-%H%M%S)
+COPIA="/root/env-pre-scope-$ALCANCE-$TS"
+install -m 600 -o root -g root "$ENVF" "$COPIA"
+echo "0. desde $DESDE · copia previa del fichero de entorno: $COPIA (600, root)"
+
 set_var MARKETING_ULTRON_STUDIO_CONFIG "$ALCANCE"
 set_var ULTRON_STUDIO_PUBLISHING_ENABLED "$PUBLICAR"
-grep -Eq "^\s*ULTRON_STUDIO_PUBLISH_LAB_GATE=" "$ENVF" || set_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
-echo "1. llaves → motor=$ALCANCE publicar=$PUBLICAR (permisos $(stat -c '%U %a' $ENVF))"
-sudo -u www-data php artisan config:cache -q
-chmod 600 bootstrap/cache/config.php
-echo "2. caché: $(stat -c '%U %a' bootstrap/cache/config.php)"
-sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => (bool) config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1 | sed 's/^/   /'
+[ "$(cuenta_var ULTRON_STUDIO_PUBLISH_LAB_GATE)" != 0 ] || set_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
+echo "1. llaves → motor=$ALCANCE publicar=$PUBLICAR (permisos $(stat -c '%U %a' "$ENVF"))"
+sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
+CACHE=bootstrap/cache/config.php
+[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF). Vuelta: install -m 600 -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"
+[ "$(stat -c '%a' "$CACHE")" = 600 ] || chmod 600 "$CACHE"
+echo "2. caché: $(stat -c '%U %a' "$CACHE")"
+DESPUES=$(leer)
+case "$DESPUES" in
+  *"\"engine_scope\":\"$ALCANCE\",\"publishing_enabled\":$PUBLICAR,"*) echo "   $DESPUES" ;;
+  *) echo "ABORTA: la configuración efectiva no es la pedida: ${DESPUES:-ilegible}"
+     echo "   vuelta: install -m 600 -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 $CACHE"
+     exit 1 ;;
+esac
 sudo -u www-data php artisan queue:restart -q
 supervisorctl restart 'ironbody-lab:*' > /dev/null
 sleep 8
-echo "3. workers RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
-echo "4. status sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status)"
-echo "ALCANCE $ALCANCE APLICADO"
+RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
+echo "3. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL"
+[ "$RUNNING" = 13 ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado 13 y 0)"
+c=$(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status)
+echo "4. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c"
+if sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1; then echo "5. publication-check: 0 (cuadra)"; else desvio "publication-check no sale con 0: ver «sudo -u www-data php artisan ultron-studio:publication-check»"; fi
+if [ "$DESVIOS" = 0 ]; then echo "ALCANCE $ALCANCE APLICADO (copia previa: $COPIA)"; else echo "ALCANCE $ALCANCE APLICADO CON $DESVIOS DESVÍOS (copia previa: $COPIA)"; exit 1; fi
```

### Apéndice C. Lo mínimo imprescindible para `deploy-h5-backend-fase1.sh` (D-1, D-4 y D-2), si no se adopta A

Huella del script resultante: `sha256 5306391be3f6e9f1a689a6eb277e7dc7bd079c68e9905f46859686b72a408672`.

```diff
--- a/backend/docs/ultron-studio/ops/deploy-h5-backend-fase1.sh
+++ b/backend/docs/ultron-studio/ops/deploy-h5-backend-fase1.sh
@@ -8,7 +8,7 @@
 ESPERADO="${1:?commit esperado en origin/main}"
 cd /var/www/api
 git fetch -q origin
-MODIFICADOS=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')
+MODIFICADOS=$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')
 [ "$MODIFICADOS" = "0" ] || { echo "el checkout de producción tiene cambios locales: no se despliega"; exit 1; }
 git merge -q --ff-only origin/main
 HEAD=$(git rev-parse --short HEAD)
@@ -16,6 +16,7 @@
 echo "1. código: HEAD=$HEAD"
 cd backend
 ENVF=.env
+[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
 add_var() { if grep -Eq "^\s*$1=" "$ENVF"; then echo "   $1 ya existe (no se toca)"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; echo "   $1 añadida"; fi; }
 echo "2. variables (todo apagado):"
 add_var MARKETING_ULTRON_STUDIO_CONFIG off
@@ -44,6 +45,6 @@
 echo "8. sanidad:"
 sleep 3
 for p in status lab/status publications; do echo "   $p sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p)"; done
-echo "   colas fallidas: $(sudo -u www-data php artisan queue:failed 2>/dev/null | grep -c '^| [0-9a-f]')"
+echo "   colas fallidas: $(sudo -u postgres psql -Atd ironbody -c 'SELECT count(*) FROM failed_jobs;')"
 echo "   filas de marketing_ai_actions con sello 'studio' (debe ser 0 con el motor en off): $(sudo -u postgres psql -Atd ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio'")"
 echo "FASE 1 (H5) COMPLETADA"
```

### Apéndice D. `h5-schema-check.sql` (solo lectura; antes y después de migrar; validada en PG 14)

```sql
-- h5-schema-check.sql · SOLO LECTURA · Hito 5, antes y después de ironbody-migrate.
-- Producción (como root): sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody -f /root/h5-schema-check.sql
-- Ensayo local:           psql -X -v ON_ERROR_STOP=1 -v owner=ironbody -v app=ironbody -x -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_smoke_h5 -f h5-schema-check.sql
-- Basada en informe-db.md §13; se ejecuta como postgres, así que el rol dueño y el de la aplicación se nombran
-- explícitamente (current_user sería postgres y siempre daría «t»).
SELECT
  (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')                         AS disparadores_studio,  -- 27 antes → 30 después
  (SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')                            AS check_studio,         -- 24 → 27
  (SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'uspub_%')                            AS disparadores_uspub,   -- 0 → 3
  (SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uspub_%')      AS check_uspub,          -- 0 → 3
  (SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
    WHERE c.relname IN ('usv_one_canary', 'usv_one_published') AND i.indisunique AND i.indpred IS NOT NULL) AS indices_parciales, -- 0 → 2
  (SELECT pg_get_constraintdef(oid) LIKE '%ROLLED_BACK%' FROM pg_constraint WHERE conname = 'usv_status_chk') AS check_9_estados, -- f → t
  (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')   AS dueno_funcion,        -- iron (si no: PARAR, la 100002 falla con 42501)
  (SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid') AS huella_funcion,      -- igual antes y después
  (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions') AS dueno_versiones, -- iron (ALTER TABLE lo exige)
  has_schema_privilege(:'owner', 'public', 'CREATE')                                       AS create_en_public,     -- t
  to_regclass('public.ultron_studio_publications') IS NOT NULL                             AS existe_cadena;        -- f → t
SELECT count(*) AS versiones_fuera_de_los_5_estados_de_f
  FROM ultron_studio_versions WHERE status NOT IN ('DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED');  -- 0 antes
-- Cerrojos sobre versiones: la 100002 pide ACCESS EXCLUSIVE, que choca TAMBIÉN con AccessShareLock (un SELECT en
-- una transacción abierta). A diferencia de H4, aquí no se filtra AccessShareLock. Esperado: 0 filas.
SELECT a.pid, a.state, now() - a.xact_start AS en_transaccion, l.mode
  FROM pg_locks l JOIN pg_class c ON c.oid = l.relation JOIN pg_stat_activity a ON a.pid = l.pid
 WHERE c.relname IN ('ultron_studio_versions', 'ultron_studio_publications') AND a.pid <> pg_backend_pid();
SELECT to_regclass('public.ultron_studio_publications') IS NOT NULL AS hay_cadena \gset
\if :hay_cadena
SELECT has_table_privilege(:'app', 'public.ultron_studio_publications', 'SELECT,INSERT,UPDATE,DELETE') AS app_dml,       -- t
       has_table_privilege(:'app', 'public.ultron_studio_publications', 'TRUNCATE')                    AS app_truncate,  -- f
       has_sequence_privilege(:'app', 'public.ultron_studio_publications_id_seq', 'USAGE')             AS app_secuencia, -- t
       (SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications') AS dueno_cadena, -- iron
       (SELECT count(*) FROM ultron_studio_publications)                                                    AS publicaciones; -- 0
\endif
```
