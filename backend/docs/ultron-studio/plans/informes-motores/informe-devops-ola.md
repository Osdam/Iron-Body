# Informe de devops · ola «Enmienda M-1 + plantillas de Meta» (despliegue con todo apagado)

**Autor:** `devops`. **Fecha:** 11-11-2026. **Árbol:** `feat/ultron-studio-h9`, HEAD `9d6b3252`, con el trabajo sin
commit de otros agentes (no lo he tocado). **Entorno de este trabajo: local, NO producción.** No he ejecutado nada en
producción ni en el banco; no he hecho commit ni push. He tocado solo `backend/docs/ultron-studio/ops/` y este informe.

---

## Resumen para el lead

### Qué quedó listo

| Pieza | Estado | Verificación hecha |
|---|---|---|
| `ops/deploy-m1-backend.sh` (nuevo, 910 líneas, derivado del cierre del H9) | Listo para el ensayo | `bash -n`; sus analizadores (`fase_h9`, `fase_h8`, `ola_apagada`, `rutas`, `programador`, `traza`) probados sin servidor con salidas fingidas: 18 comprobaciones, 17 como esperaba y 1 en la que mi expectativa estaba mal y el guion bien (§6) |
| `ops/smoke-h9.py` (+125 líneas) | Bloque M-1: `status.engine.prompts` y las 4 rutas de plantillas | `ast` y 6 escenarios sin red (§3.3): verde con la ola apagada, OMITIDO con el código anterior, FALLO si se exige y falta, la sonda no se toca si el módulo no consta apagado, la regresión se caza |
| `ops/smoke-run-h9.sh` (+54 −7) | 27 tablas por defecto, la traza con `SMOKE_EXPECT_M1=1` y la tolerancia estrecha de `outreach_runs` en la fase 2 | `bash -n`; la lógica de la tolerancia, con 4 casos |
| `ops/RUNBOOK_M1.md` (nuevo) | El orden completo (respaldo → CRM → `--solo-comprobar` → backend → smokes → mandos a `/root` → vigilancia de 15 min), la vuelta atrás y las etapas E0–E5 con sus mandos y su vuelta | Revisión de lectura |
| `ops/banco-h9/m1.sh` (nuevo) | El banco de esta ola: partida `728f2085` con el estado real (H9 en sombra, laboratorio 2a), la ola confirmada como origin/main, `desplegar` y `mandos` (E2, su vuelta y la trampa de M1-B con un fallo forzado) | `bash -n`; las copias del banco se generaron en un directorio vacío del scratchpad (25 líneas cambiadas en el despliegue y 8 en cada mando, ningún `/var/www/api` residual, las tres pasan `bash -n`); el adaptador de `sed -i -E` probado con la expresión exacta de los mandos (conserva la sangría y el modo 640). **El banco NO se ha ejecutado** |
| Revisión de los dos mandos endurecidos (M1-B) | 9 hallazgos (ninguno bloqueante para E2) y una política única de retención de `/root/env-pre-*` | §4 |

`smoke-h8.py` y `smoke-run-h8.sh` **valen sin cambios**: cuentan las tablas del Studio de forma dinámica (`≥ 18`,
`smoke-run-h8.sh:276-281`), no fijan el total de rutas y su programador solo cuenta las tareas `ultron-studio:` de los
monitores (la sincronización de Meta va tras su bandera). Lo que cambia es la **fase** con la que se lanzan: `2a`.

### Qué supuse (y de dónde sale cada número esperado)

| Supuesto o número | Valor | Fuente |
|---|---|---|
| Producción sirve la API | `728f208` | encargo del lead. `9d6b325` sobre él: solo docs y pruebas (`git diff --name-status 728f2085 HEAD` fuera de docs y tests: vacío) |
| Producción sirve el CRM | `a539690` | encargo del lead (`CRM_PREVIO`) |
| Esquema de partida | `127 141 9 26 51 75 3 8`, 34 funciones, `iron` | `deploy-h9-backend-cierre.sh:191,193` y L9-94 (`ULTRON_STUDIO_H9_CONTRACT.md:1553`, «esquema del Studio 127 141 9 26»). **Comprobado:** ninguna migración entre `07e70da` y `728f208` (`git diff --name-status 07e70dac 728f2085 -- backend/database/migrations` vacío) |
| Esquema de llegada | `137 153 11 27 51 75 3 8` | `informe-db.md:244-247` (+10 disparadores, +12 CHECK, +2 parciales, +1 tabla) y `:267-273`; M-1 no suma (`informe-db-motores.md:82-91`) |
| Funciones | 34 → 37 sin `SECURITY DEFINER` | `informe-db.md:76-79` (2), `:133` (1), `:246` (+3); las tres casan con `ultron_studio_chk_%`, el filtro del cierre (`deploy-h9-backend-cierre.sh:350`) |
| Tabla de W9 (columnas · disparadores · CHECK) | `15 4 4` → `18 5 6` | `informe-db.md:177` y `:277-278` |
| Tabla de envíos (disparadores · CHECK · únicos parciales) | `9 10 2`, vacía | `informe-db.md:55-58`, `:60-72`, `:81-94`, `:277` |
| `iron_app` | envíos `raw--s` (REVOKE DELETE); W9 `ra---s`; el resto, igual | `informe-db.md:96-101`, `:137-140`; `deploy-h9-backend-cierre.sh:195` |
| `uspub_engine_contract_chk` | `1 t t f` → `1 t t t` | `informe-db-motores.md:66-69`; `ops/motores-schema-check.sql` |
| Rutas (Studio · strategies/** · ejecución · GET · POST · SSE · Meta) | `121 30 13 9 4 1 0` → `125 34 13 9 4 1 4` | `informe-backend.md:18,54`; las 4 cuelgan de `strategies/templates/**` (`UltronStudioMetaTemplateController.php:37-44`): por eso `strategies/**` pasa de 30 a 34 |
| Las 9 claves nuevas | `MARKETING_ULTRON_STUDIO_PROMPTS=off` + las 8 `ULTRON_STUDIO_META_TEMPLATES_*` con su defecto, `URL_HOSTS=ironbodyneiva.cloud` | `backend/.env.example:298`, `:426-447`; Q1 de L9-96 (`informe-backend.md:159`) |
| Programador | el mismo total: la ola no añade tareas sin bandera | `informe-backend.md:168` (26 → 27 solo con la bandera); bloque `routes/console.php:566-571` |
| H9 en sombra y su programador | 5 tareas `outreach:*` | L9-101 (`…H9_CONTRACT.md:1560`: «programador 40 → 45») |
| Laboratorio hablado | 2a, driver `elevenlabs` | L9-100 (`…:1559`) |
| Workers | 14 (13 + `ironbody-voice`) | L9-102 (`…:1561`); el guion lo deduce como los mandos (`h5-engine-scope.sh:105-107`) |
| Tablas de la base hoy (respaldo) | 254 | L9-100 (`…:1559`, «254 tablas») |
| Traza con la llave apagada | `off legacy prompts_off` | `informe-bm.md:156`; `motores-prompts-scope.sh:115` |
| Base de los prompts | 5 sha256 | `informe-bm.md:95-99`; **comprobados** sobre el árbol con `shasum -a 256` (casan los 5) |

El guion **no** da por hecho el estado de partida: lee el H9 y la voz, deduce la fase y aborta si no es «sombra o todo
apagado» (H9) o «2a o todo apagado» (voz), porque son las únicas que cubren los smokes.

### Qué falta para el ensayo

1. **Tu orden** y los **commits definitivos** de la ola en la rama (con ficheros de la ola sin confirmar, `m1.sh ola` se niega).
2. El **commit del CRM** de la ola (el guion exige `--crm` y que no sea `a539690`).
3. PostgreSQL 14 desechable en `127.0.0.1:55432` (`ops/pgunit.sh`), `vendor` en el worktree y la valla del H8 (como siempre).
4. Revisar la **puerta de diff** contra el árbol final (§2.2): bs-studio y crm-fe siguen trabajando; un fichero nuevo fuera de la lista hará abortar el paso 0, que es lo correcto.
5. **Falta el guion del CRM de esta ola** (`deploy-m1-crm.sh`): `deploy-h9-crm-cierre.sh` comprueba los marcadores del H9 y el vale P9-25, que ya no son los de ahora. Lo escribo cuando crm-fe entregue sus marcadores (la pestaña «Plantillas» y la traza de M-1), si me lo pides.

Órdenes del ensayo (`RUNBOOK_M1.md` §6): `m1.sh <banco> montar` → `ola` → `crm <commit>` → `desplegar --crm=<commit> --solo-comprobar` → `desplegar --crm=<commit>` → `mandos` → `ensayar.sh <banco> cierre` → `parar`.

### Riesgos y puntos de no retorno

- **No retorno:** las tres migraciones (el esquema QUEDA; nunca `migrate:rollback` en producción); la primera entrada `/2` en la cadena (E3/E4; R10, inocua); enviar una plantilla a Meta (**fuera de esta ola**: queda apagado).
- **Falso rojo del smoke del H9 en sombra (corregido, sin verificar en producción):** con el programador vivo, `outreach:dispatch --inline` abre DOS filas en `outreach_runs` por minuto (`OutreachDispatchCommand.php:38-40`, `OutreachDispatcher.php:136`) y el smoke compara recuentos exactos durante ~2 min. **No lo puedo conciliar con el «23 OK · 0 FALLO» de L9-101** (o el programador no corrió en esa ventana, o algo que no veo); he añadido una tolerancia estrecha (§3.2). El cierre del H9 no lo vio porque se desplegó con el H9 apagado.
- **Ventana de segundos con código nuevo y esquema viejo** (entre el `merge` y `ironbody-migrate`, como en todas las olas): `informe-db.md:332` pide las migraciones «antes del código». Con este mecanismo no es posible sin otra ola; el puente de W9 falla cerrado sin la columna (`informe-backend.md:166`) y hoy no hay estrategias de WhatsApp publicadas (L9-101). Lo acepto conscientemente y lo digo.
- **Privilegios por defecto de `iron_app` en la tabla nueva sin verificar en producción** (`informe-db.md:324`): si no da `raw--s`, el paso 4 aborta ANTES de las cachés (código nuevo con las cachés viejas: las rutas nuevas no existen todavía); se arregla con database-specialist y se relanza (reanuda) o se vuelve el código.
- **La puerta del laboratorio para E3–E5 (G6 `waived`)** es una clave de entorno que **ningún mando escribe**: decisión pendiente (§7).
- **Copias del fichero de entorno con todos los secretos**: 9 mandos las dejan en `/root/env-pre-*` y solo 2 las purgan (§4.3).

---

## 1. Entorno y alcance

- Producción (lo que el guion encuentra y deja): API `728f208`, CRM `a539690`, H9 en sombra (gobierno, publicar,
  ventana, canario, canal en vivo y eventos encendidos; L9-101), H8 en 2a (L9-100), H6 en fase 2, 14 workers, n8n con
  el flujo vivo `29de5119` y la copia S1 importada e inactiva (`ops/motores/n8n-copia-s1/LEEME.md`).
- Esta ola **no enciende nada**: la llave de prompts y las tres banderas de plantillas nacen apagadas. Lo que ya estaba
  encendido tiene que quedar IGUAL: por eso el guion compara antes y después en lugar de exigir «todo apagado» como el
  cierre.

## 2. `deploy-m1-backend.sh`

### 2.1 Qué cambia respecto a `deploy-h9-backend-cierre.sh`

| | Cierre del H9 | Esta ola |
|---|---|---|
| Estado del H9 | exigido **apagado** antes y después | leído y exigido **igual**; la fase (2 sombra, 1 apagado) decide el smoke; otra cualquiera aborta |
| Voz | los smokes en fase 1 | fase deducida (`2a` hoy); 4a o mixta aborta |
| Filas | iguales en todas las tablas del H9 (incluidas `outreach_*`) | iguales en todas las `ultron_studio_*`; `outreach_*` solo informativo (la sombra escribe pasadas cada minuto) y **0 trabajos `live`** antes y después |
| Pestañas viejas (P9-25) | puerta del access.log | retirada (era del vale de un solo uso del H9) |
| Programador | +1 sin bandera | +0, y la sincronización de Meta ausente (5.º campo) |
| Rutas | iguales (121) | `125 34 13 9 4 1 4` (7.º campo: las de Meta) |
| Esquema | 4 migraciones de voz y W9 | las 3 de la ola, con el CHECK del contrato, la forma de W9 y la de envíos, y los privilegios de TODAS las tablas del Studio y de la ejecución |
| Configuración | H9 apagado | la ola apagada (`ola_apagada`) + `ultron:prompt-trace` = `off legacy prompts_off` + H9 y motor/Legacy/H4–H8 iguales (con el driver del laboratorio hablado) |
| Base de los prompts | — | sus 5 sha256 en el checkout desplegado (con la llave en off nadie vería una base rota) |
| Sanidad | — | + las 4 rutas nuevas (401 y `no-store`), el 413 temprano en la sonda de Meta, `decide`/`commit` internos sin firma → 401 (la firma va antes del limitador: no gasta el cupo de n8n) |
| n8n | — | informativo: qué flujo atiende el webhook (lectura en la base de n8n con `timeout 10`) |
| Smokes | H9 y H8 en fase 1 seguidos | H9 en la fase deducida con `SMOKE_EXPECT_M1=1`, 27 tablas y las tareas de sombra; **pausa de 65 s**; H8 en `2a` |
| Commits de la ola | `(ultron-studio-h[89])` | `(ultron-studio)`, `-h5…h9`, `-m1`, `-motores`, `-plantillas` (`9d6b325` es `docs(ultron-studio):`) |

Igual que el cierre: respaldo exigido (< 2 h, 700/600, huella, `env.backup` = el entorno), merge `--ff-only` como root
con `umask 022`, lectura por `www-data` de cada fichero del merge, claves escritas apagadas ANTES de migrar y el fichero
parsea, `ironbody-migrate` con `lock_timeout 5s` y 55P03 reintentado 3 veces, aborta sin cachés si el esquema no cuadra,
cachés como `www-data` con `umask 077` sin `chmod` de root (H-5), `queue:restart`, dos lecturas de workers, nunca imprime
valores del entorno, registro `/root/m1-<fecha>.log` (600) y la vuelta atrás impresa.

### 2.2 La puerta de diff (cerrada)

- **Zonas libres** (Studio, pruebas y docs): `app/Services/UltronStudio/`, `app/Models/UltronStudio/`,
  `app/Http/Controllers/Crm/UltronStudio/`, `app/Console/Commands/UltronStudio/`, `config/ultron_studio.php`,
  `routes/ultron-studio.php`, `tests/`, `docs/`. **Más estrecha que el cierre:** la ejecución (Outreach) ya no es
  zona libre; esta ola solo toca un fichero suyo.
- **Zonas inertes** (viajan en el repositorio, nadie las ejecuta en el servidor): `n8n/flujos/ultron-studio-s1/` y
  `n8n/respaldos/20261111-ultron-29de5119/`. Sin esto el paso 0 abortaría: la puerta del cierre mira TODO el diff, no
  solo `backend/`.
- **Lista cerrada** (37 entradas, con su dueño): el motor de M-1 (`UltronPublishedConfig`, `UltronDecideService`,
  `UltronCommitService`, `UltronController`, `config/marketing.php`, los 6 de `Prompts/`, las 2 órdenes, los 7 de
  `resources/ultron-prompts/`), el cliente neutro de Meta (9), el puente de W9 (`OutreachPublishedStrategies.php`),
  acceso (`AuthorizationMap`, `CrmPermission`, `PermissionCatalog`), `AppServiceProvider`, `UltronStudioException`,
  `routes/console.php` y `.env.example`.
- **Solo añaden código** (`*`): `routes/console.php`, `config/marketing.php`, `AuthorizationMap.php`,
  `CrmPermission.php`, `AppServiceProvider.php`, `UltronStudioException.php`. Medido hoy: 0 líneas de código quitadas
  en los seis.
- **Migraciones:** exactamente las tres de la lista, añadidas.

### 2.3 Los artefactos de n8n que viajan

Revisados sin imprimir valores (guion del scratchpad): el respaldo (24 nodos) y `flujo-s1.json` (25) llevan las
credenciales **solo como referencia** (`httpHeaderAuth`, `openAiApi`), **ningún bearer literal** en los parámetros y
**sin `pinData`, `staticData` ni `meta`**: no hay secretos ni datos personales en el repositorio por ellos.

## 3. Los smokes

### 3.1 Si siguen valiendo

| Smoke | Con 125 rutas y los DTO nuevos | Cambio |
|---|---|---|
| `smoke-h8.py` / `smoke-run-h8.sh` | Sí: tablas por nombre y `≥ 18` del Studio; no cuenta rutas; programador solo de los monitores | Ninguno; se lanza con `SMOKE_PHASE=2a` |
| `smoke-h9.py` | Sí para lo de antes (sus 30 rutas del gobierno y las 13 de la ejecución están enumeradas; las 4 nuevas no chocan: `{uuid}` lleva `whereUuid`) | Bloque M-1 (§3.3) |
| `smoke-run-h9.sh` | **No con su defecto**: exigía 26 tablas `ultron_studio_*` (habrá 27) y, en la fase 2, recuentos exactos de `outreach_*` | 27 por defecto, la traza y la tolerancia de `outreach_runs` |

### 3.2 La tolerancia de `outreach_runs` (solo fase 2)

Si entre antes y después del smoke **solo** cambia `outreach_runs`, crece, y **todas** las filas nuevas son pasadas
`shadow` de las clases que programa el bloque del H9 (`dispatch`, `reconcile`, `sweep`, `ingest`, `monitor`), se
informa y no es FALLO. Cualquier otra tabla, una pasada que no sea en sombra o una que no cuadre, sigue siendo FALLO.
La pasada a mano del paso 7 del smoke (fase 2) no cambia: ya comparaba solo las tablas del Studio y las de envío.

### 3.3 El bloque M-1 de `smoke-h9.py` (sin filas positivas)

- `GET status` → `engine.prompts`: `key = off`, base `n8n-29de5119`, compilador 1, huella Legacy `3f6a5674…`,
  `current = {legacy, null, null, prompts_off}`, `lastTurns` ≤ 20 con exactamente `{at, declared, offered, observed,
  state}`. Solo ese subárbol entra en el barrido de fugas (el resto de `status` lo barren el H5 y el H7).
- `GET strategies/templates/meta/status` → con las estrategias apagadas 409 `strategies_disabled`; encendidas, 200 con
  `enabled`, `submitEnabled` y `syncEnabled` en false, sin sonda en 24 h y 0 envíos.
- La sonda, enviar (`{}`) y comprobar (uuid inventado) → 409 `meta_templates_disabled` (o `strategies_disabled`). **La
  sonda solo se toca si `meta/status` acaba de decir que el módulo está apagado**: encendido, haría 2 GET a Graph.
- Sin sesión, las 4 → 401. «Sin filas a nombre de la cuenta»: ya lo mide el lanzador sobre toda columna `*admin_id`
  de `ultron_studio_*` (incluye `submitted_by_admin_id` de la tabla nueva).
- `SMOKE_EXPECT_M1=1` (lo pone el despliegue): lo que falte es FALLO. Sin ella: OMITIDO (para usar este smoke contra
  el código anterior). Cupos: +2 lecturas y +3 escrituras con sesión; la sonda gasta 1 de las 6/h de `ultron-studio-probe`
  de la cuenta 32 (cubo compartido con la sonda de Twilio).
- `smoke-run-h9.sh`: con `SMOKE_EXPECT_M1=1`, `ultron:prompt-trace` = `off legacy prompts_off` (FALLO si no).

## 4. Revisión de los mandos endurecidos (M1-B)

`ops/h5-engine-scope.sh` y `ops/motores/motores-prompts-scope.sh`. **Lo que está bien:** el modo original se guarda
(`:58` / `:52`) y se usa en la restauración; dueño y modo se comprueban tras escribir; el fichero parsea antes de la
caché; la trampa restaura en las etapas 1 y 2 y regenera la caché en la 2; la palabra APLICADO solo sale en verde; la
copia se borra solo sin desvíos. **Comprobado en local:** bash ejecuta la trampa `EXIT` también con SIGTERM, SIGINT y
SIGHUP (bash 3.2; el `queue:restart` de la trampa cubre también `ironbody-lab`, que es un `queue:work`:
`docs/ops/supervisor/ironbody-lab.conf:34`).

### 4.1 Hallazgos (ninguno bloquea E2; propuestas, NO aplicadas)

| # | Severidad | Dónde | Qué | Propuesta |
|---|---|---|---|---|
| MB-1 | Baja | `h5:92-93,72` · `motores:84-85,66` | `chmod 600 "$CACHE"` como **root** en `bootstrap/cache` (que escribe `www-data`) y `stat -c '%U'` sin `-L`: con un enlace puesto en su lugar, root seguiría el enlace. Es exactamente lo que el cierre evita (H-5 de SEC7) | `[ -f "$CACHE" ] && [ ! -L "$CACHE" ] \|\| aborta …` y `sudo -u www-data chmod 600 "$CACHE"` (como `deploy-h9-backend-cierre.sh:693-697`) |
| MB-2 | Baja | `h5:61-62` · `motores:55-56` (antes de `trap`, `:78`/`:72`) | La copia con secretos se hace ANTES de instalar la trampa: un `install` cortado (disco lleno) deja una copia parcial sin aviso | Instalar la trampa antes de la copia, con una etapa «copiando» que borre la parcial |
| MB-3 | Baja | trampa `h5:65-77` · `motores:59-71` | La restauración no **comprueba** el resultado: dice «caché regenerada con el valor anterior» por el código de salida, sin volver a `leer` | Tras restaurar: `leer` y comparar con `$ANTES`; decir «restaurado y comprobado» o «✗ no cuadra» |
| MB-4 | Media-baja | los dos mandos enteros | **Sin exclusión mutua**: escriben el mismo fichero que el mando del H9 (que sí usa `flock`, `h9-outreach-scope.sh:540,744`), los del H6–H8 y el despliegue. Si dos corren a la vez, la restauración de uno (copia ENTERA del fichero) **revierte el cambio del otro** | Un cerrojo común `/run/lock/ironbody-env.lock` (`flock -n`) para todo lo que escribe el fichero de entorno |
| MB-5 | Baja | los dos | **Sin registro persistente** (el despliegue sí: `/root/*.log` 600). Si se corta la sesión, la trampa restaura pero nadie ve lo que dijo | `exec > >(tee -a /root/<mando>-<fecha>.log)` con `umask 077`, como el despliegue |
| MB-6 | Info | `h5:66` · `motores:60` | En la trampa, `$?` vale **0** si el proceso murió por una señal (medido en bash 3.2; el servidor tiene otro bash, sin verificar): el mensaje dirá «código 0» | Capturar la señal (`trap 'ETAPA_SENAL=…' INT TERM HUP`) o no mostrar el código |
| MB-7 | Info | `h5:41` · `motores:36` | `sed -i` cambia el inodo; `h7-monitor-scope.sh` escribe a propósito sobre el mismo inodo. Las dos estrategias conviven | Elegir una para todos los mandos (la de `sed -i` es atómica; la del mismo inodo conserva ACL y enlaces duros) |
| MB-8 | Baja | `motores:46-49` | `canary` no comprueba que el CHECK de la cadena ya admita `/2`: con la llave encendida y sin la migración, publicar daría 500 (`informe-bs.md` §3). Hoy el despliegue lo garantiza (código y migración van juntos) | Leer `uspub_engine_contract_chk` (como `Q_CONTRATO` del despliegue) antes de escribir `canary` |
| MB-9 | Info | `h5:14-15,21-22` | `canary` abre también la llave de publicar del H5: E2 habilita publicar desde el CRM (documentado; lo repito en el runbook) | — |

**No hay errores de lógica en las etapas** (repasado caso a caso): un fallo en la etapa 1 (escritura o parseo)
restaura el fichero; en la etapa 2 (caché, lectura de después, `queue:restart` o el reinicio del laboratorio)
restaura el fichero, regenera la caché con el valor anterior y reinicia las colas; desde la 3, un desvío se dice, sale
con 1 y la copia se conserva. La ida y vuelta con un fallo forzado y el modo 640 está preparada en `m1.sh mandos` (§5).

### 4.2 Lo que no pude verificar de los mandos

Su ejecución real (son de root en el servidor). El ensayo del banco cubre E2, su vuelta y un fallo forzado a medias,
pero no los usuarios de sistema, `flock`, `shred` de verdad ni el bash del servidor.

### 4.3 Propuesta: una política ÚNICA para las copias del fichero de entorno

Hoy dejan copias completas (600, root, con **todos** los secretos) en `/root`: `env-pre-scope-*` (H5),
`env-pre-prompts-*` (M-1), `env-pre-h6-*`, `env-pre-h7-monitor-*`, `env-pre-voice-*`, `env-pre-voice-secretos-*`,
`env-pre-otp-token-*`, `env-pre-elevenlabs-*` y `env-pre-outreach-*`. Solo las dos de M-1 se purgan, y cada una solo
las suyas. Según la memoria del proyecto existen además `/root/env-backup-<fecha>.bak` antiguos.

1. **Dos clases, dos reglas.** (a) El `env.backup` de un despliegue vive con su volcado en
   `/var/backups/ironbody/<ola>-<fecha>/` (700/600): es un **respaldo** y ningún guion lo borra; su retención la decide
   el propietario (propuesta: los 3 últimos en el servidor y, los anteriores, fuera de línea y cifrados, o destruidos
   con su volcado). (b) La copia previa de un mando, `/root/env-pre-<mando>-<orden>-<fecha UTC>`: es **transitoria**.
2. **Mando aplicado sin desvíos → su copia se destruye al terminar** (como hacen ya los dos de M-1).
3. **Con desvíos o fallo a medias → se conserva como mucho 7 días.** Cualquier mando, al empezar, purga **todas** las
   `env-pre-*` de más de 7 días (no solo las suyas), dice cuántas y sus nombres (nunca su contenido).
4. **Tras rotar cualquier secreto** (APP_KEY, OpenAI, ElevenLabs, Twilio, Meta, el secreto interno): se destruyen
   **todas** las `env-pre-*` y `env-backup-*` en el acto (llevan el secreto viejo). Lo añaden los guiones de rotación.
5. **Una sola implementación**: un `ops/lib/env-copias.sh` (crear con `umask 077`, registrar, purgar) que cargan todos
   los mandos, en lugar de nueve variantes. Cada copia se anota en `/root/env-pre.registro` (600): fecha, mando, orden,
   resultado y la huella sha256 de la copia (nunca su contenido).
6. **`shred -u` no garantiza nada** en ext4 con diario, en disco SSD ni con instantáneas del proveedor: la mitigación de
   verdad es la vida corta y la **rotación**. Una copia que haya salido del servidor = secretos expuestos → rotar.
7. **Inventario en la vigilancia de cada ola** (solo nombres y fechas):
   `find /root -maxdepth 1 -type f \( -name 'env-pre-*' -o -name 'env-backup-*' \) -printf '%TY-%Tm-%Td %f\n' | sort`.

7 días en vez de 14: basta para investigar un fallo; para más atrás está el respaldo del despliegue. Si el
propietario prefiere 14, vale igual: lo importante es que sea **una** y que la aplique cualquier mando. **Escalado a
`security`** (la retención es transversal, como pedía M1-B). No la he aplicado a ningún mando.

## 5. El banco de esta ola (preparado, sin ejecutar)

- **Commit de partida: `728f2085`** (lo que sirve producción), por `montar.sh` con `BANCO_PREVIO=728f2085
  BANCO_WIP=0`: el esquema de producción por las migraciones de ese commit con los REVOKE (`h5_owner`/`h5_app`).
- **Estado de producción** escrito en el entorno sintético (`estado_prod`): eventos, escrituras del H6, gobierno,
  publicar, ventana y canario de estrategias, sombra (maestra, `shadow`, programador y monitor), canal en vivo y el
  laboratorio hablado (`elevenlabs`, 3,00 US$). **Diferencias con producción:** la sonda de Twilio queda apagada (sin
  credenciales en el banco) y el laboratorio no tiene clave de ElevenLabs (FALLO conocido del smoke del H8 en el banco,
  como en L9-94).
- **La ola:** `origin/main` = HEAD del worktree, **solo con todo confirmado** (`ola` se niega si hay ficheros de la ola
  sin commit: el ensayo es de los commits definitivos).
- **`desplegar`:** la copia del banco de `deploy-m1-backend.sh` (11 sustituciones exactas del bloque de producción y
  las rutas), con la red por la valla y las variables `SMOKE_*` del banco.
- **`mandos`:** las copias del banco de los dos mandos (7 sustituciones exactas cada uno, entre ellas el fallo forzado
  `BANCO_FALLA=1` en la lectura de después) con adaptadores de `sed -i -E` y `shred` SOLO para ellos
  (`$B/bin-mandos`). Casos: E2 (`h5 canary off` y `motores canary off`) APLICADOS · modo 640 conservado · fallo forzado
  → código 1, «FALLO A MEDIAS (etapa 2», fichero idéntico byte a byte, modo 640, la llave sigue en `canary`, la copia
  del fallo se conserva · vuelta (`motores off canary` con traza `off legacy prompts_off`, `h5 off canary`) · fichero
  idéntico al de partida · queda una sola copia (la del fallo). Al final deja el banco como estaba.
- **La automatización interna** (`AUTOMATION_INTERNAL_SECRET`) se escribe con un valor sintético aleatorio si falta:
  sin ella el middleware de `decide`/`commit` responde 503 y la sanidad del despliegue (sin firma → 401) daría un
  desvío falso en el banco.
- **No prueba:** el programador vivo (no hay cron), ElevenLabs, los usuarios de sistema, `flock`, `timeout` ni la
  lectura de n8n (sale «?»).
- **Memoria:** el mismo perfil que el banco del cierre (PostgreSQL desechable, `artisan serve` con 4 workers y la
  valla). Con 8 GB y otros agentes, mejor sin suites corriendo a la vez.

## 6. Qué verifiqué ejecutando (sin producción ni banco)

| Comando | Resultado |
|---|---|
| `bash -n` de `deploy-m1-backend.sh`, `smoke-run-h9.sh` y `banco-h9/m1.sh` | OK los tres |
| `python3 -I -c 'ast.parse(…)'` de `smoke-h9.py` | OK (sin `__pycache__` en el árbol) |
| Analizadores del despliegue con salidas fingidas (`prueba_deploy.sh` del scratchpad) | 18 comprobaciones: 17 como esperaba (`fase_h9` 2/1/?/?, `fase_h8` 2a/1/?, `ola_apagada` OK y 4 negativas, `rutas` `125 34 13 9 4 1 4`, `traza` off/canary/falla, `programador` que falla). La 18.ª, `programador` con mi lista de prueba, dio `10 2 2 5 0` y yo esperaba `10 3 2 5 0`: el guion acierta (`--verify-voice` cuenta como voz, igual que en el cierre) |
| Bloque M-1 del smoke con `call` fingido (`prueba_m1.py`) | 6 escenarios: 9 OK; 2 OMITIDO; 2 FALLO; 8 OK + 1 FALLO + 1 OMITIDO (sonda sin tocar); 7 OK (fase 1); 7 OK + 2 FALLO (regresión cazada) |
| Generación de las copias del banco en un directorio vacío (`prueba_copias.sh`) | 25 / 8 / 8 líneas cambiadas, 0 `/var/www/api`, `bash -n` OK |
| Adaptador `sed -i -E` con la expresión de los mandos | sangría y modo 640 conservados, sin fichero de respaldo |
| Tolerancia de `outreach_runs` (4 casos) | solo crece `outreach_runs` → candidata; otra tabla, decrece o cambia el Studio → FALLO |
| `shasum -a 256` de la base de los prompts | los 5 casan con `informe-bm.md:95-99` |
| Exportaciones de n8n | sin bearer literal ni `pinData`; credenciales por referencia |
| Trampa `EXIT` de bash ante TERM/INT/HUP | se ejecuta (con `$?` = 0 dentro) |
| Que `decide` del código de producción no rechaza `prompt_contract` y que S1 solo manda el bloque si hubo oferta | leído: `UltronController.php` de `728f208` (`unexpectedKeys` solo en `commit` e `incident`, `:285`, `:404-418`); `preparar-commit.js:56-69` |

**No verificado:** nada contra producción (ni el estado real, ni los privilegios por defecto de `iron_app`, ni el bash
del servidor, ni si el programador escribe hoy en `outreach_runs`); el banco; la suite; el CRM; n8n.

## 7. Pendiente de autorización o de decisión

1. **El ensayo en el banco** (tu orden; los commits definitivos y el del CRM).
2. **El guion del CRM de la ola** (`deploy-m1-crm.sh`), cuando crm-fe entregue sus marcadores.
3. **G6:** puerta del laboratorio en E3–E5. `ULTRON_STUDIO_PUBLISH_LAB_GATE=waived` no lo escribe ningún mando. O
   `required` (cada versión con su veredicto), o una opción en un mando con su ensayo; a mano, no.
4. **La política de retención** de §4.3 (propietario y security) y, si se acepta, `ops/lib/env-copias.sh` en los nueve mandos.
5. **Los hallazgos MB-1 a MB-5 de los mandos** (tuyos: los endureciste tú; no los he tocado).
6. En producción, con la orden del propietario: el respaldo, el CRM, `deploy-m1-backend.sh … --solo-comprobar` y el
   despliegue (`RUNBOOK_M1.md` §2), y después cada etapa E1–E5 con su orden (G5).

## 8. Riesgos residuales y deuda que dejo señalada

- La tolerancia de `outreach_runs` es nueva y el banco no la ejercita (no hay cron): se verá en producción. Si sale
  FALLO por otra tabla de `outreach_*` (p. ej. `outreach_cursors` la primera vez), es del programador, no de la ola:
  mirar la tabla antes de volver atrás.
- En la pasada en sombra del smoke, `marketing_messages` cuenta TODOS los mensajes: con tráfico real puede dar un falso
  «la sombra escribió en una tabla de envío». Por eso el despliegue va de noche; no he relajado esa comprobación.
- `ops/README.md` (la tabla de guiones) no lista todavía `deploy-m1-backend.sh`, `RUNBOOK_M1.md` ni `banco-h9/m1.sh`.
- `ultron:prompts-n8n` escribe en el checkout: no se ejecuta nunca en producción (lo dice el runbook).
- No ha habido exposición de secretos en este trabajo: no he leído ningún fichero de entorno real ni ejecución de n8n.
  Nada que rotar por mi parte.
