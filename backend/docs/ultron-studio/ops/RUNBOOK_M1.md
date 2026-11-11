# Runbook · ola «Enmienda M-1 + plantillas de Meta» (despliegue con todo apagado y activación de M-1 por etapas)

**Entorno:** PRODUCCIÓN (VPS de Iron Body: API en `/var/www/api`, CRM en `/var/www/app`, n8n en Docker en el mismo
servidor). **Lo ejecuta el lead, como root, con la orden expresa del propietario para cada bloque** (G5 del plan de
motores: E0, E1, E2 y E3–E5 son órdenes distintas, cada una después de ver la evidencia de la anterior).
**Informe de la preparación:** `plans/informes-motores/informe-devops-ola.md`. **Guion:** `ops/deploy-m1-backend.sh`.

| | |
|---|---|
| Producción hoy | API `728f208` (`9d6b325` solo añade docs, pruebas y ops) · CRM `a539690` · H9 en **sombra** desde el 11-11 05:23 UTC (L9-101) · laboratorio hablado **encendido** en `elevenlabs` (2a, L9-100) · H6 en fase 2 · 14 workers (13 + `ironbody-voice`) · n8n: flujo vivo `YspRwsXnqt33NouP` versión `29de5119`; copia S1 `QSklqHlsDIxucRfk` importada e **inactiva** |
| Lo que entra | Motor de M-1 (oferta, sello, lector `/2`, `ultron:prompt-trace`), Studio de M-1 (publicar `/2`, vista previa, `status.engine.prompts`), plantillas de Meta (4 rutas, llave `ultron.meta_templates_submit`, bloque del programador tras su bandera), 3 migraciones, guardas |
| Lo que NO cambia al desplegar | Ninguna bandera se enciende (9 claves nuevas, apagadas). El motor, Legacy, el H4–H9 y la voz quedan **igual que antes** (el guion lo lee antes y lo exige después). n8n, nginx, DNS, TLS, crontab y supervisor no se tocan |

---

## 1. Antes del día

1. **Commits definitivos de la ola** en `feat/ultron-studio-h9` y `main` en avance rápido (sin push no hay despliegue).
   Prefijos admitidos por el guion (`PREFIJO_OLA`): `tipo(ultron-studio)`, `tipo(ultron-studio-h5|h6|h7|h8|h9)`,
   `tipo(ultron-studio-m1)`, `tipo(ultron-studio-motores)` y `tipo(ultron-studio-plantillas)`. Ninguno
   `outreach(sends-open):` ni `voice(calls-open):` (el guion aborta).
2. **La puerta de diff** (`LISTA_CERRADA`, `ZONAS_LIBRES`, `ZONAS_INERTES` y `MIG_OLA` del guion) se revisa contra el
   árbol final: si un agente tocó un fichero fuera de la lista, el paso 0 lo dirá y se decide (añadirlo con su dueño o
   sacarlo de la ola). Los marcados con `*` solo pueden añadir código.
3. **Ensayo en el banco** con esos commits (§6): `--solo-comprobar` en verde, despliegue sin desvíos, smokes y mandos.
4. **El CRM de la ola** construido y sellado (`version.json` con su commit y `dirty: false`).
5. **Ventana:** de noche (como el cierre del H9, 03:14 UTC): con el H9 en sombra el programador corre cada minuto y el
   smoke de la fase 2 compara recuentos exactos; con tráfico real de WhatsApp las tablas de envío pueden moverse por
   motivos ajenos (ver §4, «falsos rojos»). **Nadie usa el Studio, el laboratorio hablado ni el canal canario durante
   el despliegue y la vigilancia.**
6. **Sesión:** dentro de `tmux` (o `screen`): si se corta la conexión, el guion sigue y su registro queda en `/root`.

## 2. El orden del día (≈ 45 min)

### 2.1 Respaldo (justo antes; el guion exige < 2 h y el fichero de entorno idéntico)

```bash
TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ultron-studio-m1-$TS
( umask 077; mkdir -p "$BK"
  git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"                      # 728f208…
  sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
  sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
  install -m 600 -o root -g root /var/www/api/backend/.env "$BK/env.backup"
  cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache"
  cp -a /etc/supervisor/conf.d "$BK/supervisor-conf.d" )
pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA'                        # 254 (las tablas de hoy)
stat -c '%a %U %n' "$BK" "$BK"/*; du -h "$BK/ironbody.dump"; echo "BK=$BK"      # 700 el directorio; 600 y root los ficheros
```

El respaldo **no se borra nunca** desde un guion (lleva el volcado y una copia del fichero de entorno: su retención la
decide el propietario; ver `informe-devops-ola.md` §4.3).

### 2.2 El CRM de la ola (ANTES que el backend)

El CRM nuevo tolera la **ausencia** de los campos nuevos (funciona con el backend de hoy); un CRM viejo puede no
tolerar su **presencia**. Se publica con el procedimiento de siempre: `rsync` **sin** `--delete`, respaldo previo de
`index.html`, `version.json` y la lista de ficheros, verificación de que nada desapareció y de que el `main` viejo y
los `chunk-*.js` viejos siguen sirviendo.

- **Guion:** el genérico (decisión del lead, ya usado el 11-11 dos veces):
  `bash backend/docs/ultron-studio/ops/deploy-crm.sh <CRM> a539690 /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front`
  con `<CRM>` = `3f01c85` (el CRM de la ola, sellado; sobre `89a24f9` lee `phoneLast4`)
  (build sellado, nada se movió, `origin/main` = el build, respaldo, `rsync` sin `--delete`, verificación pública;
  termina en `CRM PUBLICADO Y VERIFICADO`). El backend solo exige que `version.json` diga `<CRM>` y que no sea `a539690`.
- Verificación: `curl -fsS https://ironbodyneiva.cloud/version.json` → el commit del build. Las pestañas abiertas
  recargan solas al navegar (vigilante de versión); recepción y el punto facial: recargarlas a mano.

### 2.3 El backend, solo comprobaciones (no toca nada)

```bash
# desde el Mac, el guion DEL COMMIT QUE SE DESPLIEGA (no el del árbol):
git fetch -q origin && git show <commit>:backend/docs/ultron-studio/ops/deploy-m1-backend.sh | shasum -a 256
git show <commit>:backend/docs/ultron-studio/ops/deploy-m1-backend.sh \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-m1-backend.sh && chmod 700 /root/deploy-m1-backend.sh && sha256sum /root/deploy-m1-backend.sh'
# en el servidor:
bash /root/deploy-m1-backend.sh <commit (7)> "$BK" --crm=<commit del CRM (7)> --solo-comprobar
```

Tiene que terminar en `COMPROBACIONES PREVIAS EN VERDE`. Con `PENDIENTE DE DECISIÓN` (código 3) hay commits ajenos
que viajarían con el merge: o se despliegan antes por su vía, o el propietario los acepta con `--acepta-main=…`.
Lo que tiene que leer: esquema `127 141 9 26 51 75 3 8`, efectos `f f f`, contrato `1 t t f`, W9 `15 4 4`, rutas
`121 30 13 9 4 1 0`, fases `H9 2 (sombra)` y `voz 2a`, workers `14 0`, n8n «el flujo vivo 29de5119».

### 2.4 El backend

```bash
bash /root/deploy-m1-backend.sh <commit (7)> "$BK" --crm=<commit del CRM (7)>
```

Pasos y lo que se espera en cada uno (cada desvío se dice; `DESPLIEGUE M-1 (BACKEND) COMPLETADO` solo con 0):

| Paso | Esperado |
|---|---|
| 1 código | `HEAD=<commit>`; los ficheros del merge legibles por `www-data`; la base de los prompts casa con sus 5 sha256 |
| 2 variables | 9 claves añadidas (`MARKETING_ULTRON_STUDIO_PROMPTS=off`, las 8 `ULTRON_STUDIO_META_TEMPLATES_*` con su defecto y `URL_HOSTS=ironbodyneiva.cloud`); el fichero parsea |
| 3 migraciones | `137 153 11 27 51 75 3 8`, efectos `t t t`, voz igual (un 55P03 se reintenta 3 veces) |
| 4 esquema | 37 funciones sin `SECURITY DEFINER`; contrato `1 t t t`; W9 `18 5 6`; envíos `9 10 2` y vacía; `iron_app`: envíos `raw--s`, W9 `ra---s`, el resto igual; las tablas del Studio con las mismas filas; la cadena sin `/2` |
| 5 cachés | `www-data 600` las dos; `queue:restart` |
| 6 rutas | `125 34 13 9 4 1 4`; `StudioRouteCount::TOTAL` 125; `auth:coverage --unmapped` vacío |
| 7 configuración | la ola apagada; `ultron:prompt-trace` → `off legacy prompts_off`; H9, motor, Legacy, H4–H8 iguales; programador igual (sin la sincronización de Meta) |
| 8 workers | `14 0` dos veces |
| 9 sanidad | 401 y `no-store` (también las 4 rutas nuevas); 413 temprano (también la sonda de Meta); `decide`/`commit` sin firma 401; OTP 403; colas, publication-check, monitor en seco igual; el entorno = respaldo salvo las 9 claves |
| 10–11 | ninguna instantánea nueva; 0 ERROR nuevos |
| 12 smokes | H9 en fase 2 (sombra) con `SMOKE_EXPECT_M1=1` y 27 tablas; pausa de 65 s; H8 en fase 2a. Código 0 o 3 (3 = OMITIDO por los presupuestos de PERF9, conocido) |

Con `--sin-smoke`, los smokes después: `bash /root/deploy-m1-backend.sh <commit> "$BK" --solo-smoke` (≥ 2 min desde
otra pasada de un smoke: misma cuenta 32 y mismo cupo de inicio de sesión por IP).

### 2.5 Los mandos del commit desplegado a `/root` (necesarios para E2; no se ejecutan hoy)

```bash
for f in h5-engine-scope.sh motores/motores-prompts-scope.sh; do
  n=$(basename "$f"); [ ! -e "/root/$n" ] || cp -p "/root/$n" "/root/$n.pre-m1-$(date -u +%Y%m%d-%H%M%S)"
  install -m 700 -o root -g root "/var/www/api/backend/docs/ultron-studio/ops/$f" "/root/$n"
done
sha256sum /root/h5-engine-scope.sh /root/motores-prompts-scope.sh   # = los del commit desplegado
```

### 2.6 Vigilancia (15 min, una lectura por minuto, solo lectura)

- `supervisorctl status`: los 14 en RUNNING, ninguno en BACKOFF/FATAL.
- 5xx en las últimas 2 000 líneas del `access.log` de nginx: 0.
- `failed_jobs`: los de antes (0 nuevos). `incidents` de `ultron_studio`, `outreach` y `voice`: los de antes.
- `SELECT count(*) FROM ultron_studio_meta_template_submissions` → 0; `SELECT count(*) FILTER (WHERE engine_contract = 'ultron-engine-config/2') FROM ultron_studio_publications` → 0.
- `sudo -u www-data php artisan ultron:prompt-trace` → llave `off`, oferta `legacy` `prompts_off`.
- `laravel*.log`: 0 ERROR nuevos.
- El siguiente turno canario de ULTRON sale con normalidad (sin sello de prompts: n8n sigue en el flujo vivo).
- `outreach_runs` crece ~2 filas por minuto (la sombra): normal. `outreach_jobs` en modo `live`: 0.

## 3. Puntos de no retorno e irreversibles

| Qué | ¿Se deshace? | Nota |
|---|---|---|
| Las 3 migraciones | **No en producción** (nunca `migrate:rollback`) | El esquema QUEDA aunque el código vuelva: la tabla de envíos vacía y sin lector, las 3 columnas de W9 sin lector, el CHECK `/1|/2` (superconjunto). Quitarlas es de database-specialist con autorización; sus `down()` se niegan con filas |
| La primera entrada `/2` en la cadena (E3/E4) | **No** (la cadena es de solo inserción, R10) | Inocuo: «volver a Legacy» escribe `/1`; el `down()` de M-1 se niega mientras exista una `/2` |
| Las claves nuevas en el fichero de entorno | Sí (`env.backup`) | El código anterior no las lee |
| Enviar una plantilla a Meta | **No** (Meta bloquea el nombre 30 días) | **Fuera de esta ola**: `ULTRON_STUDIO_META_TEMPLATES_*` quedan apagadas; encenderlas es otra orden |
| E1 (n8n) | Sí | El flujo vivo queda intacto como reserva |

## 4. Vuelta atrás del despliegue (nivel 3; el guion la imprime con el respaldo de hoy)

En este orden:

0. Si ya se movió la llave de prompts (E2): `bash /root/motores-prompts-scope.sh off canary`. Si hay entradas `/2`
   (E3+): antes «volver a Legacy» en el Studio (escribe `/1`).
1. Código: `[ "$(git -C /var/www/api rev-parse --short=7 HEAD)" = <commit> ] && ( cd /var/www/api && umask 022 && git checkout -q --detach "$(cat $BK/api-commit.txt)" )`.
2. `cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && sudo -u www-data php artisan queue:restart`.
3. `stat -c '%U %a' bootstrap/cache/config.php bootstrap/cache/routes-v7.php` → `www-data 600`; rutas del Studio 121; 14 workers.
4. (Opcional) el fichero de entorno de antes: `install -m <modo> -o <dueño> -g <grupo> $BK/env.backup /var/www/api/backend/.env` y `config:cache` como arriba.
5. **n8n:** si S1 ya está publicada (E1 hecha), puede quedarse con el código anterior: «Preparar Commit» de S1 solo
   manda el bloque `prompts` si `decide` ofreció `studio_prompts` (`n8n/flujos/ultron-studio-s1/preparar-commit.js:58`),
   y el `decide` anterior ni lo ofrece ni rechaza `prompt_contract` (solo `commit` e `incident` rechazan claves de
   más). **Excepción:** un turno canario en vuelo en el instante del cambio (`decide` con el código nuevo, `commit`
   con el viejo) cae con 422 `unexpected_fields`; el vigía lo cubre. Si se quiere volver n8n: `n8n-copia-s1/LEEME.md`.
6. **CRM:** no hace falta volverlo (tolera la ausencia de los campos nuevos).

**Falsos rojos conocidos** (antes de volver atrás, mirar cuál es):
- El smoke del H9 en la fase 2 compara recuentos exactos: si solo crece `outreach_runs` con pasadas en sombra del
  programador, lo informa y no es FALLO (`smoke-run-h9.sh`); cualquier otra tabla sí lo es. Si cambia
  `marketing_messages` en la pasada en sombra, mirar si son mensajes ENTRANTES o de una persona (tráfico real) antes
  de concluir que la sombra envió algo.
- `incidents`: el monitor de outreach corre cada 10 min en sombra; uno nuevo durante el despliegue puede no ser de la ola.

## 5. Activación de M-1 por etapas (después del despliegue; cada una con su orden escrita)

Solo la conversación canaria (`MARKETING_ULTRON_CANARY_CONVERSATION_ID`), nunca clientes. Lo que dice la traza:
`sudo -u www-data php artisan ultron:prompt-trace --last=5` (sin ids ni textos). **Nunca** leer ejecuciones del flujo
de producción en n8n (enseñan el bearer en claro): la evidencia es el sello y la traza.

### E0 · el despliegue de §2

*Verificación:* turno canario normal; `metadata.studio_prompts` ausente (traza: estado vacío, «sin sello»).

### E1 · n8n: del flujo vivo a la copia S1 (`n8n-copia-s1/LEEME.md`)

Requisitos: E0 verde; la copia S1 (`QSklqHlsDIxucRfk`) inactiva y con el selector = el generado (comprobador OK, 111).

```
unpublish_workflow(YspRwsXnqt33NouP)  →  publish_workflow(QSklqHlsDIxucRfk)
```

*Verificación:* en la base de n8n, `webhook_entity` de `iron-body-ultron` apunta a `QSklqHlsDIxucRfk` y su
`activeVersionId` no es nulo; el primer turno canario → traza `legacy`, huella observada `3f6a5674…c902` (Legacy),
`flowRev S1`. **Esto demuestra en producción que Legacy es idéntico.** Entre las dos órdenes pasan unos segundos sin
flujo publicado (solo la canaria pasa por n8n; el vigía lo cubre).
*Vuelta atrás:* `unpublish_workflow(QSklqHlsDIxucRfk)` → `publish_workflow(YspRwsXnqt33NouP)`; el siguiente turno
canario sale `not_reported`.

### E2 · las llaves (dos mandos, en este orden)

```bash
bash /root/h5-engine-scope.sh canary off          # el motor lee la cadena en la canaria Y abre publicar (ULTRON_STUDIO_PUBLISHING_ENABLED=true)
bash /root/motores-prompts-scope.sh canary off    # decide ofrece prompts a la canaria
```

Cada uno: copia previa 600, el fichero parsea antes de la caché, `config:cache` como `www-data`, la configuración
efectiva es la pedida, colas y laboratorio reiniciados, 14 workers, 401, y (el segundo) la traza. Si falla a medias,
restaura solo el fichero de entorno y la caché (M1-B). Termina en `ALCANCE canary APLICADO` /
`LLAVE DE PROMPTS canary APLICADA` (copia previa borrada). Con desvíos: código 1, la copia se conserva y se dice dónde.
*Verificación:* turno canario → `legacy` con motivo `no_publication` (sin nada publicado) o `declaration_v1`.
*Vuelta atrás (en orden inverso):* `bash /root/motores-prompts-scope.sh off canary` (traza `off legacy prompts_off`) y
`bash /root/h5-engine-scope.sh off canary`.
*Ojo:* `h5-engine-scope.sh canary` abre también la llave de publicar del H5: desde E2 se puede publicar desde el CRM.

### P1 y P2 · las plantillas de Meta desde el CRM (mando `plantillas/meta-templates-scope.sh`)

Independientes de E1–E5 (no tocan ni n8n ni los prompts), pero **después de E0** y cada una con su orden escrita.
Tres estados y ninguno más: `off` (lo que deja el despliegue) · `read` (maestra y sincronización: el CRM ve el estado de
las plantillas en Meta y no envía nada) · `full` (además, enviar desde el CRM). **No existe «enviar sin sincronizar»**
(N-1: un envío que se quedara en SENDING no se recuperaría desde el CRM). `off → full` directo no se admite. Banderas
a mano, nunca: solo el mando (sus garantías y su ensayo: `informe-devops-ola.md`, «Mando de plantillas»).

**Antes de P1, una vez:** el mando y los smokes que conocen `read`/`full` todavía no están en el checkout de
producción (llegan en un commit de docs posterior a E0). Se llevan del COMMIT `<C>` que los trae, con su huella
(la del mando, `1fc36935…`, en `informe-devops-ola.md`, «Mando de plantillas», con las órdenes completas):

```bash
# Mac (raíz Iron-Body/): C = el commit de main que trae plantillas/meta-templates-scope.sh
O=backend/docs/ultron-studio/ops
git show "$C:$O/plantillas/meta-templates-scope.sh" | ssh ironbody-vps 'umask 077; cat > /root/meta-templates-scope.sh && chmod 700 /root/meta-templates-scope.sh && sha256sum /root/meta-templates-scope.sh'
for f in smoke-run-h9.sh smoke-h9.py smoke-h6.py; do git show "$C:$O/$f" | ssh ironbody-vps "umask 077; mkdir -p /root/smoke-p-h9 && cat > /root/smoke-p-h9/$f"; done
for f in smoke-run-h8.sh smoke-h8.py smoke-h6.py; do git show "$C:$O/$f" | ssh ironbody-vps "umask 077; mkdir -p /root/smoke-p-h8 && cat > /root/smoke-p-h8/$f"; done
git show "$C:$O/perf-h8/presupuestos-h8.json" | ssh ironbody-vps 'umask 077; cat > /root/smoke-p-h8/presupuestos-h8.json'
```

Los smokes, en cualquier momento de P1/P2 (los lanzadores borran sus copias al terminar; rehacer las copias para otra
pasada; ≥ 2 min entre uno y otro):

```bash
SMOKE_WORKDIR=/root/smoke-p-h9 SMOKE_PHASE=2 SMOKE_EXPECT_M1=1 SMOKE_EXPECT_META_TEMPLATES=read bash /root/smoke-p-h9/smoke-run-h9.sh   # o =full
SMOKE_WORKDIR=/root/smoke-p-h8 SMOKE_PHASE=2a bash /root/smoke-p-h8/smoke-run-h8.sh
```

Sin esas copias, `deploy-m1-backend.sh --solo-smoke` toma los smokes de E0, que exigen el módulo APAGADO: en `read` y
`full` darían un FALLO falso en el H9 («el módulo en off») y en el H8 (la sincronización contada como tarea de monitor).

#### P1 · `off → read`

```bash
bash /root/meta-templates-scope.sh read off
```

- **Requisitos que comprueba solo** (si falta uno, aborta sin tocar nada y lo dice): el código con las 4 rutas de Meta
  y 125 en el Studio; las tres claves una vez en el fichero; el estado de hoy `off` en el fichero Y en la
  configuración efectiva; el cliente efectivo **Graph en `production`** (PM-6); la cuenta de WhatsApp (WABA) y el token
  **«presente»** tal como los lee el código (nunca su valor).
- **Termina en** `PLANTILLAS DE META read APLICADO`. Lo que dice por el camino: la configuración efectiva
  `maestra=true sincronización=true envío=false`; el programador con `1 */5_*_*_*_*` (la sincronización cada 5 minutos);
  la orden en seco con código 0 y `nothing_to_watch` (o `ok`/`partial` si ya hay plantillas registradas a mano: 1–3
  GET de lectura); workers; 401; publication-check y la traza de los prompts iguales.
- **Qué mirar después** (15 min y al día siguiente):
  - la primera pasada programada: en `laravel*.log`, ningún ERROR de `ultron_studio.meta_templates.*`; si la orden
    sale con 1 (Meta falló en toda la pasada) el programador lo deja en el registro cada 5 minutos;
  - en el CRM, la pestaña «Plantillas» enseña la conexión como Graph v25.0 y configurada; una persona con
    `ultron.integrations_manage` y su vínculo verificado lanza **una** sonda de permisos desde la pestaña
    (`POST strategies/templates/meta/probe`: 2 GET de lectura; enfriamiento de 300 s) → `ok` con
    `whatsapp_business_management`; con `missing_permission` o `no_waba_access`, P2 espera a que se arregle en Meta;
  - `ultron_studio_template_meta_checks`: filas nuevas solo si hay plantillas vigiladas que cambiaron en Meta;
  - el smoke del H9 con `SMOKE_EXPECT_META_TEMPLATES=read`: 0 FALLO.
- **Vuelta atrás:** `bash /root/meta-templates-scope.sh off read` (las rutas vuelven a 409 `meta_templates_disabled`,
  la sincronización sale del programador; el canal de W9 sigue leyendo la última fila registrada).

#### P2 · `read → full`

```bash
bash /root/meta-templates-scope.sh full read
```

- **Requisitos:** P1 con su evidencia (pasadas sin error, la sonda `ok`); `META_ENABLED` efectivo en true (lo
  comprueba el mando: el código lo exige para enviar); la orden escrita del propietario sabiendo que **enviar es
  irreversible en Meta** (una plantilla aprobada solo se borra, y su nombre queda bloqueado 30 días).
- **Termina en** `PLANTILLAS DE META full APLICADO`: `maestra=true sincronización=true envío=true`; programador y orden
  en seco como en P1.
- **Qué mirar en el primer envío real** (lo hace una persona desde el CRM con una versión aprobada a cuatro ojos):
  el envío pasa de `SENDING` a `SUBMITTED` (o `FAILED` con el error de Meta, nunca un 5xx); la sincronización lo lleva
  a `PENDING` y después a `APPROVED` o `REJECTED`; **V-1**: si Meta retocó el texto, la fila sale `components_mismatch`
  y el canal NO usa la plantilla (falla cerrado). Un `SENDING` de más de 120 s pasa a `UNKNOWN` y la reconciliación lo
  busca por nombre.
- **Vuelta atrás:** `bash /root/meta-templates-scope.sh read full` (no se envía nada nuevo; lo enviado sigue en Meta y
  se sigue leyendo). De urgencia, todo: `bash /root/meta-templates-scope.sh off full`.

### E3–E5 · publicar y revertir desde el CRM (dos personas)

- **Requisitos:** G7 (una segunda persona real con cuenta propia: quien publica ≠ autora; no hay atajo) y G6 (la
  puerta del laboratorio). **Decisión pendiente:** G6 propone `waived` solo para E3–E5, y eso es
  `ULTRON_STUDIO_PUBLISH_LAB_GATE=waived` en el fichero de entorno, que **ningún mando escribe hoy**
  (`h5-engine-scope.sh` solo la pone en `required` si falta). O se decide `required` (cada versión con su veredicto
  del laboratorio) o se añade la opción a un mando, con su ensayo; a mano, no (sería deriva).
- **E3 (recomendada):** publicar en canario una versión con los valores de la línea base. La vista previa enseña
  `effect: prompts` y el paquete con la huella Legacy; el texto obligatorio del diálogo dice que publicar cambia lo que
  ULTRON dice en la canaria. *Verificación:* turno → `applied` con huella `3f6a5674…` (cero cambio de comportamiento).
  Después, revertirla (una sola `CANARY` a la vez).
- **E4:** publicar V2 (`persona.tone=profesional`, `persona.maxChars=200`). *Verificación:* 2–3 turnos desde el
  teléfono canario → `applied` con huella `702890f7…` y respuestas de usted y cortas. **Criterio de aceptación.**
- **E5:** revertir V2 en el Studio → el turno siguiente `legacy` (huella Legacy) y el estilo de antes.
- *Vuelta atrás de cualquiera:* «Revertir» o «Volver a Legacy» en el Studio (Legacy siempre se escribe en `/1`); y,
  si hace falta parar del todo, la vuelta atrás de E2.
- *Qué vigilar:* `mismatch` o `legacy_drift` en la traza (n8n dice haber aplicado otra cosa, o alguien editó Legacy
  en n8n): parar en E2 y avisar a security. Desde la alerta M1-A (11-11-2026) el monitor del H7 lo vigila cada 30 minutos y abre el incidente de IRON GUARD `ultron_studio · prompts_drift` (alto), sin frenar nada; a mano: `php artisan ultron-studio:monitor --check=prompts --dry-run --json`. Con la llave de prompts en `off` no vigila (`not_watched`).
  `engine_fallback`: n8n rechazó la oferta (el motivo dice por qué).
- `all` no existe en esta entrega.
- **Compilador 2 (M1-18, frases de las reglas S-NNN):** al desplegarlo, cualquier parte publicada con el compilador 1
  sigue sellándose `applied` pero `decide` ofrece Legacy con `compiler_changed`. Para recuperarla: revertirla y publicar
  una versión NUEVA con el mismo contenido (una revertida es terminal; promover no recompila). Por eso se despliega sin
  nada publicado en canario. n8n no se toca. Vuelta atrás del código: ANTES, «Volver a Legacy» en el Studio (obligatorio:
  el código anterior no sabe componer sobre una cabeza del compilador 2 y sellaría `fallback` en cada turno).
- **Antes de publicar la primera versión con frases** (condiciones de seguridad): el CRM nuevo desplegado; los dos textos
  fijos aprobados por el propietario; mirar en la vista previa lo que arrastra el resto; y que quien aprueba y quien
  publica no sea quien escribió o editó por última vez una regla S-NNN que aporta frases.

## 6. Ensayo en el banco (antes del día; con la orden del lead)

Banco nuevo, fuera del repo, con `ops/banco-h9/m1.sh` (usa `montar.sh`, `preparar.sh`, `ensayar.sh` y `sembrar.sh`
sin cambiarlos). **Commit de partida: `728f2085`** (lo que sirve producción), con el estado de producción escrito en el
entorno sintético (H9 en sombra con el gobierno, publicar y el canal en vivo; laboratorio hablado 2a). La ola: los
commits **confirmados** del worktree (con código de la ola sin confirmar, `ola` se niega salvo
`BANCO_OLA_COMMIT=<sha>`, que ensaya ese commit y dice lo que queda fuera). Base propia del banco (`BANCO_DB`) para
no borrar la de otro banco.

```bash
export BANCO_DB=ultron_studio_banco_h9_m1
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-m1
bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" montar
BANCO_OLA_COMMIT=<commit de la ola> bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" ola
bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" crm <commit del CRM de la ola (7)>
bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" desplegar --crm=<ese commit> --solo-comprobar
bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" desplegar --crm=<ese commit>
bash backend/docs/ultron-studio/ops/banco-h9/m1.sh "$B" mandos
bash backend/docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" cierre       # la valla: ningún host de fuera
bash backend/docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" parar && rm -rf "$B"
```

Lo que el banco **no** prueba: el programador vivo (no hay cron: la tolerancia de `outreach_runs` del smoke no se
ejercita), ElevenLabs (el smoke del H8 en 2a tiene un FALLO conocido del banco), los usuarios de sistema, `flock`,
`timeout` y la lectura de n8n (sale «?»), y la sonda de Twilio (apagada en el banco).

**Ensayado el 11-11 dos veces.**

- **Primer ensayo** (13:50–14:06 UTC, `1c8f70bb` y CRM `89a24f9`): pasos 0–11 sin desvíos y mandos 13/13. El smoke del
  H9 cazó `phoneNumber` en `GET strategies/catalog`; se corrigió en el código (`phoneLast4`).
- **Segundo ensayo** (14:16–14:31 UTC, `b1959a05` y CRM `3f01c85`, lista cerrada de 56): pasos 0–11 sin desvíos.
  Smoke del H9: **0 FALLO** (1 OMITIDO de PERF9). Smoke del H8: solo su FALLO conocido del banco (ElevenLabs). Valla
  sin salidas.

Las órdenes de producción válidas están en `informe-devops-ola.md`, «Segundo ensayo (b1959a05)».

## 7. Lo que NO es de este runbook

- Encender las plantillas de Meta **a mano**: solo con el mando (§5, P1 y P2), cada etapa con su orden escrita.
- `php artisan ultron:prompts-n8n` **no se ejecuta en producción**: escribe en el checkout; los artefactos ya viajan
  en el repositorio.
- La política de copias del fichero de entorno (`/root/env-pre-*`): propuesta en `informe-devops-ola.md` §4.3,
  pendiente de decisión.
