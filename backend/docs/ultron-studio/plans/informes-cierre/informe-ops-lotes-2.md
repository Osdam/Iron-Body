# Informe de devops · el guion por lotes corregido y los lotes 3, 4 y 5 (`informe-ops-lotes-2`)

**22-11-2026 · rama `cierre/ops-lotes-2` (desde `feat/ultron-studio-h9` `5defc2e8`) · PREPARADO Y ENSAYADO EN LOCAL, NO EJECUTADO.** Sin `ssh`, sin push, sin producción. **[C]** comprobado · **[L]** leído · **[S]** supuesto.
**Guion:** `ops/deploy-cierre-lote.sh`, 964 → 1245 líneas, **sha256 `7c7298d8b19d3cc595acbbd8689f9990ca5c991fb976c07d966f121141bebacb`** (el del lote 2: `6911f61a…`). Ficha: `ops/DEPLOY_CIERRE_LOTES.md`. Banco: 4 bancos en el scratchpad, PostgreSQL 14.24, bash 3.2 y awk de macOS, dentro de tmux; las 4 bases `ultron_studio_ops2_*`, **borradas** (0 quedan).

## A · Las correcciones (línea en el guion nuevo → lo que lo demuestra en el banco)
- **m1** (l. 1192) sin tubería: `artisan list --raw` a una variable y `grep -qE` sobre ella; si `auth:coverage` no aparece, **falla** (antes: salto callado). Hallazgo: con artisan **no se reproduce** (PHP CLI ignora SIGPIPE y Symfony no comprueba el `fwrite`: `artisan list --raw | head -c 10` → `PIPESTATUS 0 0`, 8 de 8 «está»); con un productor que muere por SIGPIPE, la tubería antigua da `NO ESTÁ (PIPESTATUS 141 0)`. Lote 4: `auth:coverage --unmapped → [vacío]`.
- **m2** (l. 124–137): todas las `[[ =~ ]]` con la expresión en una variable y sin barras (`[>]`, `[.]`), definidas antes de leer argumentos. Bash 3.2: 30/30 casos; lote 3: `transicion voice_calls vc_status_forward ambiguous>failed_to_start · esquema [1 true voice_forbid 6 false] = antes`. **No hay bash 5 ni glibc en la máquina** (§E).
- **m3** (l. 941): `ABORTA (no se ha tocado nada): falta la copia versionada del envoltorio (backend/docs/iron-ia/ops/ironbody-migrate) en f4a44287…` → 1.
- **C1** (l. 319–386, 1108, 1222–1235, 1242): los errores cuentan **desde el primer cambio** (la primera migración o el merge, lo que vaya antes: mejor que «desde el merge», porque en `migracion-primero` entra también el código previo contra el esquema nuevo). De cada entrada solo clase (o clave de un `Log::error`) y primer fichero:línea de la aplicación, validados al leerlos. Decide y lo dice:
  `· excepcion RuntimeException · backend/app/Http/Controllers/Api/WhatsappWebhookController.php:88 → ajena al diff` · `decisión: ninguna de las 1 nombra un fichero del diff → DESVÍO con aviso, SIN vuelta atrás automática` → `LOTE DESPLEGADO CON DESVÍO…` **3**.
  `· excepcion Illuminate\Database\QueryException · backend/app/Services/Telephony/VoiceReconciler.php:210 → DEL DIFF` → `VUELTA ATRÁS AUTOMÁTICA HECHA` **4**. `· registro commercial.complaint.staff_record_failed · - → DEL DIFF: …/OutreachComplaintController.php (escribe esa clave)` → **4**. El dato del mensaje: 0 veces en la salida y en `registro.log`.
  Además (l. 359, 969): **autoprueba del analizador en el paso 0** con el awk del servidor (en Ubuntu, mawk, que no pude probar): `…autoprueba con el awk de este servidor: bien`; con un awk que no lo entiende, ABORTA, 1.
- **C3** (l. 616, 649–669, 727): vuelta manual con el fichero de entorno posterior al respaldo (solo `stat`): `La de configuración NO: el fichero de entorno es 28 s posterior al respaldo (solo stat)` · `caché de configuración regenerada con el código de fddd3450… (umask 077)` → 600 → `VUELTA ATRÁS HECHA`, 0. La automática repone las dos, como antes.
- **C2** (l. 179–194, `uso()`): fuera de tmux/screen, despliegue y vuelta **se niegan** (2); mira `TMUX`/`STY` y los procesos padres (sudo borra las variables). Huérfano fuera de tmux → `este guion despliega y vuelve atrás SOLO dentro de tmux…` 2, HEAD intacto; su `--solo-comprobar` → 0; dentro de tmux sin `TMUX` → lo detecta; `--sin-tmux` → sigue (AVISO en el registro).
- **m4** (l. 945, 1054): el envoltorio, desde un umask 077; el paso 0 dice el umask efectivo de `sudo -u www-data`. Banco: caché tras el `config:cache` del envoltorio `600` (lanzado desde 022, `644`). Sin tocar el envoltorio instalado; su `chmod` que sigue un enlace (H-5) sigue siendo de **security**.
- **m5** (l. 92) `umask 022` arriba: lanzado desde `umask 077`, 0 de 4 ficheros escritos por git en `.git` ilegibles. **m8** (l. 396): vuelta de un lote de un fichero nuevo → `ningún fichero del diff existe en fddd3450 (todos nuevos): nada que leer` → 0. **m9** (l. 1128): worker lento 100 s → `solo 13 de 14… se espera hasta 300 s más` → `ya con pid nuevo 14 de 14; pasado el margen: [14 0] y [14 0]` → 0.
- Nuevo, para los lotes: tipo **`columna`** (l. 236; siempre `conservar`), **`texto:<palabra>`** en `--config` (l. 485), **`--sin-filas`** (l. 266, 957, 1103), y **privilegios uno a uno** con `x`/`t`, por columna y USAGE en la secuencia (l. 537; C-B1).

## B · La orden de cada lote (P = lo que sirva producción; uno por N; dentro de tmux; primero `--solo-comprobar`)
```bash
# Lote 3 · SOLO con la autorización específica del propietario
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-3/config.txt
# Lote 4 · antes, integracion/consent integrada con la rama principal (no desciende de fddd3450)
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-4/lista.txt --migraciones=/root/cierre-lote-4/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-4/config.txt --rutas=/root/cierre-lote-4/rutas.txt
# Lote 5
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-5/lista.txt --migraciones=/root/cierre-lote-5/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-5/config.txt --sin-filas=/root/cierre-lote-5/sin-filas.txt
```
- **Lote 3** (`integracion/voz` `67988eb6`, 281 ficheros): `…100002_allow_unstarted_ambiguous… transicion voice_calls vc_status_forward ambiguous>failed_to_start - deshacer` · `voice.number.shared_with_messaging=false`. Las llamadas, cerradas por la constante (el paso 0 lo exige). La primera línea de sus dos declaraciones y de su sección en la ficha: la autorización del propietario.
- **Lote 4** (`integracion/consent` `83c48957`, 81 en el banco): `…100001_create_outreach_consents_table tabla outreach_consents - outreach_consent_ ra deshacer` · 6 `outreach_consents.legal.*=null` y `outreach.complaints.per_account_per_day=null` · **6 rutas exactas** (no 5): `+GET|HEAD …/consents`, `+POST …/consents`, `…/consents/revoke`, `…/consents/{uuid}/confirm`, `…/consents/{uuid}/reject` y **`+POST api/admin/commercial-automation/complaints`**.
- **Lote 5** (`cierre/lab` `c13db1f4`, 55): `…100001_add_engine_to_ultron_studio_lab_run_cases columna ultron_studio_lab_run_cases engine json - conservar` · `ultron_studio.publishing.lab_source=texto:simulation` y `…lab_gate=texto:required` · `--sin-filas`: `ultron_studio_lab_runs status queued,running`.

## C · Salidas finales de los ensayos (puntas de hoy, guion `7c7298d8…`)
```text
L3  M. …100002_allow_unstarted_ambiguous_voice_calls_to_fail_to_start  16.42ms DONE · esquema [1 true voice_forbid 6 true] = despues
    route:list 1046 → 1046 · banderas: voice.number.shared_with_messaging false · local.ERROR nuevos: 0 · 0      LOTE DESPLEGADO SIN DESVÍOS → 0
    a. cachés y código (código primero) · b. …: DESHECHA (transicion voice_calls: la definición del respaldo, byte a byte; fuera de migrations)
    VUELTA ATRÁS HECHA: producción en fddd3450, con las cachés de antes, las rutas y el programador de antes          → 0
L4  …create_outreach_consents_table … 71.52ms DONE · [presente h5_owner ra 3] = despues
    h5_app en outreach_consents, uno a uno: SELECT sí · INSERT sí · UPDATE no · DELETE no · TRUNCATE no · REFERENCES no · TRIGGER no · sus secuencias [1 1]
    route:list: 1046 → 1052 (las 6) · auth:coverage --unmapped → [vacío] · GET …/consents sin token → 401              LOTE DESPLEGADO SIN DESVÍOS → 0
    …: DESHECHA (tabla outreach_consents: tabla y funciones borradas; fuera de migrations) · GET …/consents → 404     VUELTA ATRÁS HECHA → 0
L5  sin filas vivas, justo antes del primer cambio: ultron_studio_lab_runs.status en [queued,running]: 0
    …add_engine_to_ultron_studio_lab_run_cases … 6.61ms DONE · [1 json null -] = despues
    banderas: ultron_studio.publishing.lab_source texto:simulation|ultron_studio.publishing.lab_gate texto:required   LOTE DESPLEGADO SIN DESVÍOS → 0
    …: SE CONSERVA (el lote lo declara «conservar») · VUELTA ATRÁS HECHA …; SE CONSERVA el esquema de: …add_engine…   → 0
```
También: `--solo-comprobar` 0 en los tres; repetir el lote 5 tras su vuelta → `ya registradas (reanudación)`, 0; `--sin-filas` con una fila `running` → ABORTA, 1. Primero ensayé con `b6d9dc44`/`80657d66`/`67631aed` (todo en verde) y lo repetí con las puntas de arriba.

## D · Lo que la vuelta atrás no deshace
- **Lote 3:** las llamadas que `voice:resolve-ambiguous` cierre como `failed_to_start` (valen con el disparador de DB8 [L]); el disparador puede volver «equivalente» y no idéntico (PostgreSQL reescribe los cast; hoy volvió byte a byte).
- **Lote 4:** la tabla, si ya tiene filas (retirar y rechazar funcionan sin texto legal): `DROP` se niega y se queda con sus funciones; las quejas registradas por la ruta nueva (hechos en tablas que ya existían); las llaves que alguien haya asignado a un rol.
- **Lote 5:** la columna `engine` y su fila en `migrations` (siempre); las ejecuciones del motor ya lanzadas, cuyo `results_hash` el código previo no podría verificar [L, informe-db-cierre A.1].
- En todos: los datos del código nuevo, el fichero de entorno, n8n, CRM, nginx, FPM, supervisor y crontab.

## E · Lo que NO pude ensayar y qué tiene que mirar el lead en `--solo-comprobar`
- **bash 5 + glibc (m2):** la línea de la migración del lote 3 `= antes` (no «transicion exige…»). **mawk (C1):** `autoprueba con el awk de este servidor: bien`. **sudo/PAM (m4):** `umask de sudo -u www-data desde un umask 077: 0077` (si no, AVISO: seguir y pasarlo a security). **tmux en Ubuntu (C2):** dentro de tmux, el despliegue no se niega.
- **Lote 4:** `privilegios por defecto de iron para iron_app en tablas nuevas: […]` **sin `REFERENCES` ni `TRIGGER`** (si salen, PARAR: database-specialist). **Lote 5:** `sin filas vivas (--sin-filas): …: 0` y `lab_gate texto:required`; y, fuera del guion, el `labGate` de `GET /api/admin/ultron-studio/versions/39cfae9f-…/publication-preview` (sesión de superadmin) igual antes y después, y `publication-check` 0 con la huella `26d57565` [S: no lo pude llamar].
- En los tres: las banderas nuevas `ausente`; `= la copia versionada (sha256 8ecb1479…)`; `workers 14 RUNNING`; cachés `www-data 600`. No ensayé la vuelta del lote 2 con su respaldo real y el guion nuevo (mismos ficheros: [C] por lectura).

## F · Si las ramas reciben más commits, qué se vuelve a generar
1. **La lista, siempre**, con el `P` y el `N` del momento (y la del lote 4 solo después de integrarla: la unión del banco no vale).
2. **El guion y su huella** salen de `N`: `N` tiene que traer `cierre/ops-lotes-2` (si no, se subiría el de `6911f61a…`, sin las correcciones).
3. **Las declaraciones**, si cambian: una migración añadida o renombrada, su tipo/marca/privilegios o el prefijo y número de funciones (lote 4) → `migraciones.txt`; una clave de `config/` que tenga que valer algo → `config.txt` (el paso 4 compara lo declarado; lo nuevo sin declarar no se mira); una ruta o los `ROUTES` de un controlador → `rutas.txt` (medir con `route:list --json` en el banco); `routes/console.php` → `programador.txt` (hoy, ninguno); los estados vivos del laboratorio → `sin-filas.txt`.
4. **Volver a ensayar** en el banco si cambia algo de 3 (unos 3 min por lote). Commits solo en `app/`/`tests/`/docs: basta la lista y el `--solo-comprobar`.

## G · Hallazgos y deuda
- **`integracion/consent` no desciende de `fddd3450`** y choca en 3 ficheros (`.env.example`, el contrato H9, `OutreachSchemaTest.php`): es de backend antes del lote 4.
- `integracion/voz` tocó `config/voice.php` desde `b6d9dc44` (solo un comentario de `TWILIO_VOICE_FROM`): ninguna clave cambia.
- C1 decide por la traza: un error causado por el lote que solo pase por `vendor/` sale «ajeno» → 3, no 4. Por eso el 3 exige mirar las entradas.
- Al empezar, `git merge-tree --write-tree` (3 sondeos) dejó objetos sueltos sin ref en la base de objetos del repo; ningún commit ni ref. Mi sesión corre dentro de tmux: el primer caso de C2 desplegó de verdad **en el banco** y lo volví atrás (el caso válido, el huérfano, está arriba).
- `shellcheck`, sin pasar (no instalado). Ningún secreto expuesto ni que rotar: 0 ficheros de registro con la clave de la aplicación o la del dueño del banco.
