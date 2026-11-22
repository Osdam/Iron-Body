# Despliegue por lotes del cierre de ULTRON STUDIO (`deploy-cierre-lote.sh`)

**Fecha:** 22-11-2026. **Autor:** `devops`. **Estado:** lote 2 **DESPLEGADO** en producción (`fddd3450`, 14:58 UTC, sin desvíos: `evidencia-h9/2026-11-22/05-lote-2-despliegue.txt`); lotes 3, 4 y 5 **PREPARADOS Y ENSAYADOS EN LOCAL, NO EJECUTADOS**; **PT-19 (§5 ter) preparado y ensayado en local, NO ejecutado** (`evidencia-h9/2026-11-22/11-ensayo-pt19-y-regresion-lote5.txt`). Sin `ssh` ni push. Cada lote se despliega con la orden del lead, y el **lote 3 solo con la autorización específica del propietario**.
**Guion:** `ops/deploy-cierre-lote.sh`, con las correcciones de la revisión independiente (`plans/informes-cierre/informe-reviewer-guion.md`: C1–C3, m1–m9) y la enmienda de PT-19 (`--bootstrap` y `--marcador`; sin ellas, todo igual) · **huella sha256 `ca03c1b5d5ec0c5d46918a91d37c188f09dd070093e5af3ad553cd16380503be`** (antes de la enmienda, `7c7298d8…`; la que desplegó el lote 2, `6911f61a…`). **Declaraciones:** `ops/cierre-lotes/lote-{2,3,4,5}/` y `ops/cierre-lotes/pt-19/`. **Banco:** `ops/banco-cierre/`.
**Marcas:** **[C]** comprobado (ensayo o lectura del código) · **[L]** leído en un documento o una memoria · **[S]** supuesto, sin verificar.

## 0. En cinco líneas

1. **Lote 1** fue por `hotfix-backend.sh`; **lote 2**, por este guion (desplegado). Quedan el **3** (voz y telefonía), el **4** (consentimiento y quejas) y el **5** (laboratorio frente al motor): uno a la vez, cada uno con su `P` = lo que sirva producción en ese momento.
2. El guion **no despliega nada que el lote no declare** (migraciones, configuración, rutas, programador, filas que no puede haber y, para PT-19, `bootstrap/app.php` por su huella con un marcador sin credenciales); lo no declarado **aborta sin tocar nada**.
3. Orden: previas → respaldo → **en el orden del lote** migraciones (rol dueño, una a una) y código (`merge --ff-only`) → cachés como `www-data` → `queue:restart` → posteriores. **Solo dentro de `tmux` o `screen`** (C2).
4. Si una comprobación posterior falla, **vuelve atrás solo** (código 4). **Un `production.ERROR` ajeno al diff ya no**: es un **DESVÍO** (código 3) con la clase y el fichero:línea de cada error, y decide el lead (C1).
5. Ensayado en local con artisan y PostgreSQL 14 de verdad [C]: los tres lotes con las puntas de hoy, de punta a punta y con vuelta atrás, y un caso por cada corrección (§8). Lo que solo existe en el servidor, en el §9.

## 1. Entorno

| Qué | Valor | Fuente |
|---|---|---|
| Entorno | **Producción**: `/var/www/api` (la API en `backend/`), PHP-FPM + nginx, `www-data` | [L] `RUNBOOK_H9.md` |
| Base | PostgreSQL 14; la aplicación entra como `iron_app` (solo datos); las migraciones, con el dueño `iron` por `/usr/local/bin/ironbody-migrate` (sha256 `8ecb1479…`, la copia de `iron-ia/ops/ironbody-migrate`) | [L] memoria `migraciones-produccion-rol-dueno`; [C] el lote 2 lo confirmó |
| Colas | 14 programas de supervisor, ninguno en `default` | [L] memoria `colas-produccion-sin-default`; [C] lote 2: 14 de 14 |
| Cachés | `bootstrap/cache/config.php` y `routes-v7.php`, de `www-data` en 600 | [L] memoria `config-cache-y-los-workers` |
| Respaldos | `/var/backups/ironbody/cierre-lote-<fecha UTC>` (700 de root); copias de las migraciones en `/var/lib/ironbody-deploy/cierre-lote-<fecha>/` | [C] el guion |

## 2. Cómo se prepara cada lote (desde el Mac, en la raíz del repo, árbol limpio)

Requisitos: la orden del lead (y, para el lote 3, la del propietario); **la rama del lote integrada en `main` y subida a `origin/main`, con este guion dentro** (`cierre/ops-lotes-2` integrada antes o a la vez: el guion se sube desde `N`, y el de antes no tiene las correcciones); **un lote por `N`** (si `main` lleva dos lotes, las declaraciones no cuadran y aborta); nadie más desplegando.

```bash
L=3                                                                       # 3, 4 o 5
git fetch -q origin
P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=8 HEAD')       # lo que sirve producción HOY (fddd3450 tras el lote 2)
N=$(git rev-parse --short=8 origin/main)
git merge-base --is-ancestor "$P" "$N" && echo "avance rápido"
git log --oneline "$P..$N"                                                # ¿solo lo del lote (y documentación)?
git diff --name-only --no-renames "$P" "$N" | grep -vE '^backend/(app|tests|docs)/'   # tiene que ser lo del §4, §5 o §5 bis
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" | shasum -a 256     # ca03c1b5… si N trae cierre/ops-pt19 sin más cambios en el guion
# Subir el guion y las declaraciones DEL COMMIT QUE SE DESPLIEGA, y la lista (como en el lote 2)
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-cierre-lote.sh && chmod 700 /root/deploy-cierre-lote.sh && sha256sum /root/deploy-cierre-lote.sh'
git diff --name-only --no-renames "$P" "$N" | ssh ironbody-vps "umask 077; mkdir -p /root/cierre-lote-$L && cat > /root/cierre-lote-$L/lista.txt && sha256sum /root/cierre-lote-$L/lista.txt"
for f in $(git ls-tree --name-only "$N" "backend/docs/ultron-studio/ops/cierre-lotes/lote-$L/"); do
  git show "$N:$f" | ssh ironbody-vps "umask 077; cat > /root/cierre-lote-$L/$(basename "$f")"
done
```

En el servidor: `tmux new -s lote$L` (o `tmux attach`), y ahí, primero `--solo-comprobar` (esa no exige tmux) y, con la orden, la misma línea sin esa bandera. **Fuera de tmux el despliegue y la vuelta atrás se niegan** (código 2); `--sin-tmux` lo salta a sabiendas y queda como AVISO en el registro.

## 3. Lote 2: gobierno, motores y aviso de pagos — DESPLEGADO (22-11-2026, `45b53023 → fddd3450`)

71 ficheros, la migración `2026_11_22_100001_allow_classifier_budget_scope` en 24,9 ms, 14 de 14 workers con pid nuevo, 0 errores, vigilancia de 15 min limpia [C, evidencia `05-lote-2-despliegue.txt`]. Vuelta atrás, si hiciera falta: `bash /root/deploy-cierre-lote.sh 45b53023 fddd3450 /root/cierre-lote-2/lista.txt --vuelta-atras=/var/backups/ironbody/cierre-lote-20261122-145816`. **Ya con el guion nuevo** (lo que hay en `/root` es el de `6911f61a…`): subir antes el de la cabecera (`ca03c1b5…`; con un respaldo sin `decl/marcador` su vuelta es la de `7c7298d8…`), que lee el mismo respaldo (mismos ficheros; [C] por lectura, no ensayado con ese respaldo); con el fichero de entorno cambiado desde las 14:58, regenera la caché de configuración en vez de reponerla (C3).

## 4. Lote 3: voz y telefonía

**NO SE DESPLIEGA SIN LA AUTORIZACIÓN ESPECÍFICA DEL PROPIETARIO, dada en la conversación.** Ni la orden del lead ni la de este documento bastan.

**Alcance** [C, `integracion/voz` = `cierre/voz` + `cierre/telefonia`, ensayada en `67988eb6`; 281 ficheros frente a `fddd3450`]: 23 de `app/` (la transición `ambiguous → failed_to_start`, la orden `voice:resolve-ambiguous`, el número empresarial como origen de las salientes bajo siete condiciones), `config/voice.php` (una clave), `.env.example`, la migración `2026_11_22_100002_allow_unstarted_ambiguous_voice_calls_to_fail_to_start`, 24 de pruebas y 231 de documentación.

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt \
  --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-3/config.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Impacto | Ninguna llamada posible: `VoiceCallPolicy::CALLS_ENABLED_IN_CODE` cerrada en `P` y en `N` (el paso 0 lo exige). Sin rutas nuevas ni tareas nuevas. `ironbody-migrate` deja la app sin caché de configuración 1–3 s (lee el entorno: funciona) |
| Migración | `transicion voice_calls vc_status_forward ambiguous>failed_to_start` · antes `[1 true voice_forbid 6 false]`, después `[… 6 true]` · `deshacer` |
| Orden | **`migracion-primero`, obligatorio** (sin la migración, el código creería admitida una transición que la base rechaza con P0001). Al volver: primero el código, después el disparador |
| Configuración | `voice.number.shared_with_messaging=false`: el paso 0 tiene que dar `ausente`, el 4 `false`. Si el entorno de producción ya tuviera `VOICE_NUMBER_SHARED_WITH_MESSAGING`, PARAR y preguntar |
| Bloqueo | `CREATE OR REPLACE TRIGGER`: SHARE ROW EXCLUSIVE sobre `voice_calls`, 16–20 ms en el banco; frena a quien escribe, no a quien lee; con las llamadas cerradas no escribe nadie [S]. 5 s × 3 intentos si otra sesión la tiene |
| Vuelta atrás | Código y cachés; después el disparador del respaldo (byte a byte o «equivalente»: PostgreSQL puede reescribir los cast al recrearlo; ningún guion ni monitor compara ese texto). **No deshace** las llamadas que `voice:resolve-ambiguous` haya cerrado como `failed_to_start` (valen también con el disparador de DB8 [L]) |
| Después | `voice:doctor` como antes; `admin/voice/status` 401 (el guion); vigilancia §7 |

## 5. Lote 4: autorizaciones de contacto y quejas

**Antes de nada: `integracion/consent` NO desciende de `fddd3450`** [C: su base es `088589fb`; el diff directo borraría el lote 2]. Hay que integrarla con la rama principal (`feat/ultron-studio-h9`) y chocan **3 ficheros** (`.env.example`, `ULTRON_STUDIO_H9_CONTRACT.md` y `tests/Feature/Outreach/Schema/OutreachSchemaTest.php`): eso es de backend. El banco los resolvió por unión solo para ensayar.

**Alcance** [C, `83c48957` fusionada sobre `fddd3450` en el banco: 81 ficheros]: 30 de `app/`, la migración `2026_11_22_100001_create_outreach_consents_table` (tabla nueva, de solo inserción, con 3 funciones propias, el REVOKE a la aplicación y los índices `ocon_subject_idx` y `ocon_revoked_phone_idx`), `config/outreach_consents.php` (nuevo) y una clave en `config/outreach.php`, `routes/commercial-automation.php` (**nuevo**) montado en `bootstrap/app.php` (lo único que añade: lo comprueba el paso 0), y **4 llaves de permiso** nuevas en el código (`commercial_automation.consents_record`, `consents_confirm`, `complaints_record`, `complaints_dismiss`), que no llegan por defecto a ningún rol: nada que sembrar en la base [L].

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-4/lista.txt \
  --migraciones=/root/cierre-lote-4/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-4/config.txt --rutas=/root/cierre-lote-4/rutas.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Impacto | 6 rutas de administración nuevas que nadie puede usar hasta que se le den las llaves; registrar y confirmar autorizaciones dan 409 (no hay texto legal); retirar, rechazar y registrar una queja funcionan. Cambia código de la bandeja y de la automatización comercial, con los envíos cerrados por `OutreachSendGate` |
| Migración | `tabla outreach_consents - outreach_consent_ ra` · antes `[ausente - - 0]`, después `[presente iron ra 3]` · `deshacer` |
| Rutas | **exactamente 6** (`rutas.txt`): `GET|HEAD …/consents`, `POST …/consents`, `…/consents/revoke`, `…/consents/{uuid}/confirm`, `…/consents/{uuid}/reject` y `POST …/complaints`; 1046 → 1052 en el banco; `auth:coverage --unmapped` vacío; la GET nueva sin token → 401 (después) y 404 (tras la vuelta) |
| Configuración | las 6 de `outreach_consents.legal.*=null` y `outreach.complaints.per_account_per_day=null` (sin la variable, el código lo lee como 20) |
| Bloqueo | **ninguno sobre tablas existentes**: la única clave foránea es de la tabla a sí misma [C]. 62–92 ms en el banco |
| Vuelta atrás | Cachés (las rutas desaparecen) y código; después `DROP TABLE` **solo vacía**, con sus funciones, en una transacción. **No deshace**: si alguien ya retiró o rechazó una autorización (funciona sin texto legal), la tabla tiene filas y **se queda**, con sus funciones; las quejas registradas por la ruta nueva (son hechos `complaint_created` en tablas que ya existían) y las llaves que alguien haya asignado a un rol |
| Después (database-specialist C-B1 y C-B2) | **Privilegios de `iron_app` en la tabla nueva, uno a uno**: el paso 4 imprime `SELECT sí · INSERT sí · UPDATE no · DELETE no · TRUNCATE no · REFERENCES no · TRIGGER no · sus secuencias [1 1]` y falla si no es exactamente eso. Antes, el paso 0 imprime los privilegios por defecto de `iron` para `iron_app`: tienen que ser `SELECT` e `INSERT` más, como mucho, `UPDATE`, `DELETE` y `TRUNCATE` (la migración los quita); **si salen `REFERENCES` o `TRIGGER`, PARAR** (quedarían y el paso 4 volvería atrás) y avisar a database-specialist. **`queue:restart`**: lo hace siempre el paso 3, con 14 de 14 pid nuevos. Vigilancia: ningún 5xx en `/api/admin/commercial-automation/` |

## 5 bis. Lote 5: el laboratorio frente al motor

**Alcance** [C, `cierre/lab`, ensayada en `c13db1f4`; 55 ficheros frente a `fddd3450`]: 17 de `app/` (la fidelidad «motor» del laboratorio y su evidencia), la migración `2026_11_22_100001_add_engine_to_ultron_studio_lab_run_cases` (la columna `engine`, `json` nula), `config/ultron_studio.php` (la llave `publishing.lab_source`) y `.env.example`. Ni rutas ni tareas nuevas.

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-5/lista.txt \
  --migraciones=/root/cierre-lote-5/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-5/config.txt --sin-filas=/root/cierre-lote-5/sin-filas.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Impacto | El código admite ejecuciones del laboratorio «frente al motor», con los permisos del laboratorio [L, informe-security-lab]; **la puerta de publicar no cambia**: `lab_source` nace en `simulation` y `lab_gate` sigue en `required` (los dos los comprueba el paso 4 como `texto:`) |
| Migración | `columna ultron_studio_lab_run_cases engine json` · antes `[0 - - -]`, después `[1 json null -]` (una columna, nula, sin defecto: solo catálogo) · **`conservar`, nunca `deshacer`** (el guion no lo admite para una columna) |
| Orden | `migracion-primero` (el código nuevo lee y escribe la columna; el previo no la ve) |
| Condición previa (database-specialist C-A2) | **ninguna ejecución del laboratorio en curso**: `--sin-filas` exige 0 en `ultron_studio_lab_runs.status` `queued,running` en el paso 0 y otra vez justo antes de migrar; si hay, ABORTA (no se ha tocado nada) y hay que esperar |
| Bloqueo | ACCESS EXCLUSIVE sobre `ultron_studio_lab_run_cases`, 7–19 ms en el banco (la tabla no se reescribe); quien lea la tabla detrás espera ese rato. Solo la usa el laboratorio |
| Vuelta atrás | Cachés y código; **la columna se queda**, con su fila en `migrations` (`SE CONSERVA el esquema de: …`): el código previo no la ve. Repetir el lote después lo trata como reanudación (`ya registrada`) [C]. **No deshace** las ejecuciones del motor que se hayan lanzado: su `results_hash` cuenta `engine` y el código previo no podría verificarlas [L, informe-db-cierre A.1] |
| Después | **La puerta de publicar de la versión en canario se lee igual que antes**: con la sesión de superadmin del smoke, `GET /api/admin/ultron-studio/versions/39cfae9f-19a1-44bd-8d8e-f9f3327fed81/publication-preview` antes y después, y el bloque `labGate` (`mode`, `ok`, `latest`, `runUuid`, `verdictId`, `promptVersion`) idéntico [S: la ruta y la forma, de la rama; no lo pude llamar sin credenciales]; y `publication-check` con salida 0 y la misma huella `26d57565…` |

## 5 ter. PT-19: la puerta del secreto interno antes que el limitador

**Qué es** [L, `plans/informes-cierre/informe-backend-pt19-puertas-internas.md` e `informe-apisec-pt19.md`]: **un fichero de producto**, `backend/bootstrap/app.php` (+16 líneas: `prependToPriorityList(before: ThrottleRequests, prepend: VerifyInternalAutomationSignature)`), sobre el código de producción (`f1fe6cac`). Sin secreto, las 21 rutas de `api/internal/` responden 401 **sin descontar** del cupo global de n8n y **sin** `X-RateLimit-*`; con el secreto, el mismo tope y el mismo 429 de siempre. Seguridad de API: APTO, 0 bloqueantes; su NB-4 es esta vía. Sin migraciones, rutas, configuración, programador ni dependencias.

**Por qué necesita banderas propias** [C]: `bootstrap/app.php` solo pasaba por este guion para montar un fichero de rutas (`--rutas`), y `hotfix-backend.sh` rechaza todo `backend/bootstrap/*`. Los dos siguen igual. Esta vía es **`--bootstrap=<sha256>`**: el guion exige que `backend/bootstrap/app.php` en `N` sea, byte a byte, el que se revisó (su huella entera). Va con **`--marcador`**, porque ninguna caché contiene ese fichero y lo único que prueba que el servidor lo sirve es la respuesta. Sin las dos banderas, el mismo diff **sigue abortando** (ensayado: §8).

**Alcance** [C]: la unidad es `origin/main` de hoy (`bfe61ba6`) más la fusión de `cierre/puertas-internas` (`c78e248e`). `origin/main` es antecesor de la rama, así que la fusión tiene el árbol de `c78e248e`. Frente a `f1fe6cac` son **46 ficheros**: `backend/bootstrap/app.php` (M), `backend/tests/Feature/Marketing/PuertasInternasSecretoAntesDelCupoTest.php` (A) y 44 de `backend/docs/`. La lista entera, para comparar, está en `cierre-lotes/pt-19/lista-referencia.txt`. **`N` tiene que traer también `cierre/ops-pt19`**, porque el guion enmendado se sube desde `N`. Eso suma solo ficheros de `backend/docs/`: el guion, esta ficha, la declaración, la evidencia y `MATRIZ_CIERRE_V2.md` de la rama principal, sin choques con `cierre/puertas-internas`. **Fuera de `docs/` tienen que salir exactamente esos dos.** El paso 0 lo dice así: `… 0 de backend/app · 0 de config · 0 de routes · 0 migración(es) · 1 de bootstrap · 1 de tests · N de docs · 0 otros`.

**Declaraciones** (`cierre-lotes/pt-19/`):
- **`--bootstrap=d8e9cd270b928afe0e5495809c779c4cd0c753abd025f4919cc1c755c26cb710`**: el sha256 de `backend/bootstrap/app.php` en `5cd201eb` (el que revisó seguridad y pasó la batería completa), igual en `c78e248e`. El de producción hoy, `f1fe6cac`, es `294e57ab…387c`. **Se copia de esta ficha y no se recalcula desde `N`**, porque entonces la comprobación no comprobaría nada.
- **`--marcador=marcador.txt`**: `GET api/internal/marketing/ai/doctor 401 x-ratelimit-limit presente ausente`. Con el previo, el 401 lleva `X-RateLimit-Limit` (evidencia `10-…`, [3]); con el nuevo, no. El guion solo mira el código y si la cabecera está, nunca su valor.
- Ni `--migraciones`, ni `--config`, ni `--rutas` (no van juntas con `--bootstrap`), ni `--programador`, ni `--sin-filas`.

**Órdenes.** Preparación, en el Mac (§2 con estos nombres):

```bash
git fetch -q origin
P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=8 HEAD')        # f1fe6cac si no se ha desplegado nada más
N=$(git rev-parse --short=8 origin/main)                                     # con cierre/puertas-internas y cierre/ops-pt19
git merge-base --is-ancestor "$P" "$N" && echo "avance rápido"
git diff --name-only --no-renames "$P" "$N" | grep -v '^backend/docs/'       # EXACTAMENTE: bootstrap/app.php y la prueba
git show "$N:backend/bootstrap/app.php" | shasum -a 256                     # d8e9cd27…cb710; si no, PARAR (no es lo revisado)
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" | shasum -a 256   # la huella del guion de la cabecera
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-cierre-lote.sh && chmod 700 /root/deploy-cierre-lote.sh && sha256sum /root/deploy-cierre-lote.sh'
git diff --name-only --no-renames "$P" "$N" | ssh ironbody-vps "umask 077; mkdir -p /root/cierre-pt19 && cat > /root/cierre-pt19/lista.txt && sha256sum /root/cierre-pt19/lista.txt"
git show "$N:backend/docs/ultron-studio/ops/cierre-lotes/pt-19/marcador.txt" | ssh ironbody-vps 'umask 077; cat > /root/cierre-pt19/marcador.txt'
```

En el servidor, dentro de `tmux new -s pt19`:

```bash
H=d8e9cd270b928afe0e5495809c779c4cd0c753abd025f4919cc1c755c26cb710
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt --solo-comprobar
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt   # con la orden
# vuelta atrás (la imprime el guion al terminar):
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --vuelta-atras=/var/backups/ironbody/cierre-lote-<fecha UTC>
```

Comprobación a mano, desde fuera y sin credenciales. Cuenta las cabeceras `x-ratelimit-*` sin imprimir su valor:

```bash
curl -s -o /dev/null -D - -H 'Accept: application/json' https://api.ironbodyneiva.cloud/api/internal/marketing/ai/doctor \
  | tr -d '\r' | awk 'substr($0,1,5)=="HTTP/"{print "estado", $2} tolower($0) ~ /^x-ratelimit-/{n++} END{print "x-ratelimit-*:", n+0}'
# antes: «estado 401» y «x-ratelimit-*: 2» · después: «estado 401» y «x-ratelimit-*: 0»
```

| Qué | Detalle |
|---|---|
| Impacto | **Sin corte** [S: no se recarga ni reinicia el FPM; el cambio entra cuando OPcache revalida, en `revalidate_freq` s]. n8n con el secreto: el mismo recorrido, el mismo tope y el mismo 429 [L, apisec §2 b]. Sin el secreto: 401 sin contar y sin `X-RateLimit-*`. De las 1 046 rutas, solo cambian de orden las 21 de `api/internal/` [L, apisec §1]. El `queue:restart` del paso 3 reinicia los 14 workers, como en todos los lotes. El cambio no les afecta: el núcleo de consola no usa esa lista [L, apisec §2 c]. **Lo que se pierde a propósito (NB-2):** la aplicación ya no frena los intentos con un secreto equivocado; quedan nginx (30 por segundo y por IP) y el `access.log` |
| Marcador | Paso 0: `401 presente` (gasta 1 de los 120 por minuto del asesor). Paso 4: `401 ausente` antes de `revalidate_freq` + 30 s (32 s por defecto); si no llega, **vuelta atrás automática (4)**. Vuelta atrás: `401 presente` otra vez; si no, **PARAR (5)**. Cada lectura hecha con el previo gasta 1 del cupo: el guion para en cuanto cuadra (como mucho ~7 lecturas a 5 s) |
| OPcache | **El paso 0 ya aborta si el FPM no revalida** (`opcache.validate_timestamps=0` en `/etc/php/*/fpm/`: comprobación anterior, ensayada en N7a). Con `--marcador` aborta también si `revalidate_freq` no es un número de como mucho 300 s (N7b). Lo que el paso 0 no ve, como un `php_admin_value[opcache.validate_timestamps]` en un pool, lo caza el marcador: vuelta atrás automática (N9). **Esta vía no recarga el FPM.** Si hiciera falta, es otra orden con su autorización |
| Bloqueos | **Ninguno**: ni migraciones, ni rutas, ni configuración. No se regenera ninguna caché. El paso 4 exige `route:list` sin ninguna diferencia y avisa si la caché de configuración no es la del respaldo |
| Vuelta atrás | Código (`checkout --detach P`) y cachés del respaldo (iguales), `queue:restart`, y el marcador otra vez en `presente`. **No hay datos que deshacer**: el cambio no escribe nada. Con el previo vuelve el defecto: un anónimo puede volver a gastar el cupo de n8n |
| Después (una persona, 15 min, §7) | **El siguiente turno real del asesor de WhatsApp** responde como siempre (`decide`/`commit` sin 429) y salen las **notificaciones a socios** (`internal/automation`). En el registro de ejecuciones de n8n, ninguna llamada a `/api/internal/` con 401, 403 o 429 nueva. En `access.log`, las llamadas de n8n a `/api/internal/` con los códigos de siempre. `production.ERROR` nuevos: 0. La comprobación a mano de arriba da `x-ratelimit-*: 0` |

## 6. Si no termina en verde

| Salida | Qué significa | Qué hacer |
|---|---|---|
| **1** `ABORTA (no se ha tocado nada)` | Falló una comprobación previa (incluidas la autoprueba del analizador de errores y `--sin-filas`) | Leer el motivo y corregir la causa; nunca se salta una comprobación |
| **2** | Uso, un respaldo que no cuadra con su `SHA256SUMS`, o **fuera de tmux/screen** | Revisar la orden; entrar en tmux |
| **3** `LOTE DESPLEGADO CON DESVÍO` | Todo en verde salvo errores nuevos **ajenos al diff** (ninguno nombra un fichero del lote). Producción en `<N>` | Mirar en `laravel.log` las entradas que lista (clase y fichero:línea). Si son del despliegue, la vuelta atrás que imprime el guion; si son ajenas, seguir con la vigilancia |
| **4** `VUELTA ATRÁS AUTOMÁTICA HECHA` | Falló una migración, el `merge`, una caché o una comprobación posterior (o un error nuevo **nombra un fichero del diff**). Producción en `<P>` | Diagnosticar con `registro.log` y `migracion-*-intento-*.log` del respaldo; no repetir sin la causa |
| **5** `PARAR` | La vuelta atrás no quedó limpia, o una salida inesperada a medias | Ver abajo |

**Si se corta la sesión** (C2: con un SIGHUP no sale ni la línea PARAR). Dentro de tmux no pasa: `tmux attach -t lote<L>` y seguir leyendo. Si aun así se cortó: (1) `git -C /var/www/api rev-parse --short=8 HEAD` y `tail -40` del último `registro.log` en `/var/backups/ironbody/cierre-lote-*` dicen hasta dónde llegó; (2) `ls -l /var/www/api/backend/bootstrap/cache/`: si **falta `config.php`**, el corte cayó dentro de `ironbody-migrate` (la app sigue con el entorno, pero el guion no se puede relanzar: su paso 0 lo exige); (3) con HEAD en `<P>` o en `<N>`, `bash /root/deploy-cierre-lote.sh <P> <N> <lista> --vuelta-atras=<respaldo>` dentro de tmux: repone la caché (o la regenera si el entorno es posterior al respaldo) y deja todo en `<P>`; (4) si la migración llegó a registrarse, la vuelta la deshace o la conserva según el lote. Relanzar el despliegue después es seguro: una migración ya registrada no se repite.

**A mano** (si ni la vuelta del guion sirve): en `backend/`, `sudo -u www-data sh -c 'umask 077; cat > bootstrap/cache/X.cierre-vuelta && mv -f bootstrap/cache/X.cierre-vuelta bootstrap/cache/X' < $BK/bootstrap-cache/X` (X = `routes-v7.php` y, **solo si el fichero de entorno no es posterior al respaldo**, `config.php`; si lo es, `sudo -u www-data sh -c 'umask 077; php artisan config:cache'` después del código), `( cd /var/www/api && umask 022 && git checkout -q --detach <P> )`, el esquema con `sudo -u postgres psql -d ironbody < $BK/vuelta-<i>.sql` (si existe; si no, es de database-specialist) y `sudo -u www-data php artisan queue:restart`. **Nunca `migrate:rollback`.**

**Lo que la vuelta atrás NO deshace** (en todos): los datos que escribió el código nuevo; el esquema `conservar` o cuya vuelta la base rechaza; el fichero de entorno (el guion solo mira su fecha); n8n, el CRM, nginx, FPM, supervisor y el crontab. HEAD queda suelto en `<P>` y `main` del servidor en `<N>`. Las copias de `/var/lib/ironbody-deploy` quedan como evidencia. Lo propio de cada lote, en su tabla.

## 7. Vigilancia (15 min, solo lectura)

- `supervisorctl status | awk '{print $2}' | sort | uniq -c` → 14 RUNNING, **dos lecturas separadas**. Si el paso 3 dijo «solo N de 14 con pid nuevo» y, tras los 300 s de más, quedó el AVISO, mirar hasta ver los 14 con pid nuevo y en RUNNING (un worker que no arranca con el código nuevo cae en su próximo reinicio: la trampa del retardo).
- `production.ERROR` nuevos en `laravel.log` desde la hora del despliegue; si el guion salió con 3, las entradas que listó (clase y fichero:línea) y si se repiten.
- `failed_jobs` desde la hora del despliegue; 5xx en `access.log`.
- Lote 3: `voice:doctor` como antes. Lote 4: ningún 5xx en `/api/admin/commercial-automation/`. Lote 5: `publication-check` con salida 0 y el `labGate` de la canaria igual (§5 bis).
- No lanzarlo a la hora de `logrotate`/`cron.daily` (m6: el recuento de errores es ciego si el registro se rota en medio).

## 8. El ensayo en local [C]

Banco en el scratchpad (nada en el repo): `banco-cierre/montar.sh <banco> fddd3450 ultron_studio_ops2_<x> <punta>` (con `BANCO_UNION=1` para consent) y `banco-cierre/ensayar.sh` (la copia del guion con **solo sus 15 líneas de cabecera** sustituidas). PostgreSQL 14.24 en 127.0.0.1:55432; bash 3.2 y el awk de macOS; lanzado dentro de tmux. Cuatro bases `ultron_studio_ops2_*`, **borradas al terminar**.

| Caso | Resultado |
|---|---|
| Lote 3 (`67988eb6`): `--solo-comprobar`, despliegue, `--vuelta-atras` | 0 · 0 (migración 16 ms, 14/14, `shared_with_messaging false`, 0 errores) · 0 (código primero, disparador del respaldo byte a byte) |
| Lote 4 (`83c48957` + unión): ídem | 0 · 0 (tabla `[presente h5_owner ra 3]`, privilegios uno a uno, 1046 → 1052, `auth:coverage` vacío, GET → 401) · 0 (404; tabla y 3 funciones fuera) |
| Lote 5 (`c13db1f4`): ídem | 0 · 0 (`[1 json null -]`, `texto:simulation`, `texto:required`, `--sin-filas` 0 dos veces) · 0 (`SE CONSERVA`); repetirlo → reanudación, 0 |
| C1: un webhook y un `Log::error` ajenos / un error con `VoiceReconciler.php:210` en la traza / un `Log::error` cuya clave escribe el diff | DESVÍO, 3 (sin vuelta atrás) / VUELTA ATRÁS AUTOMÁTICA, 4 / ídem, 4. El dato del mensaje, 0 veces en la salida y en `registro.log` |
| C1: autoprueba del analizador / con un awk que no lo entiende | «bien» / ABORTA, 1 |
| C2: huérfano fuera de tmux / su `--solo-comprobar` / dentro de tmux sin `TMUX` / `--sin-tmux` | se niega, 2 (HEAD intacto) / 0 / lo detecta por los padres / sigue |
| C3: vuelta manual con el fichero de entorno posterior al respaldo | «La de configuración NO… se regenera» → 600 → VUELTA ATRÁS HECHA, 0 |
| m1 · m2 · m3 | sin tubería (artisan no da el falso negativo; un productor que muere por SIGPIPE, sí: 141) · 30/30 expresiones · ABORTA con su motivo, 1 |
| m4 · m5 · m8 · m9 | caché del envoltorio en 600 (antes 644) · lanzado con umask 077, 0 ficheros ilegibles · vuelta de un lote de ficheros nuevos, 0 · worker lento: 13 → 14 de 14 y dos lecturas `[14 0]` |
| `--sin-filas` con una fila `running` | ABORTA, 1 |

**PT-19 y la regresión, con el guion enmendado** (`ca03c1b5…`; 22-11-2026, 23:06–23:12 UTC; `evidencia-h9/2026-11-22/11-ensayo-pt19-y-regresion-lote5.txt`). Previo `f1fe6cac` y `N` = `f1fe6cac` + `cierre/puertas-internas` (`c78e248e`), el mismo árbol que `origin/main` + esa rama: 46 ficheros. La base `ultron_studio_ops2_pt19a` se borró al terminar. Al banco se le puso un **secreto interno sintético** (sin él la puerta da 503), que no aparece en ningún registro.

| Caso | Resultado |
|---|---|
| PT-19: `--solo-comprobar`, despliegue, `--vuelta-atras` | 0 · 0 (`1 de bootstrap · 1 de tests · 44 de docs · 0 otros`; la huella `= la declarada`; el marcador `401 presente` → **`401 ausente` a la 3.ª lectura**: las dos primeras aún veían el fichero viejo, por la revalidación de OPcache del servidor del banco, `revalidate_freq=2`) · 0 (el marcador vuelve a `401 presente`) |
| N1 · marcador que no cumple en el paso 0 (el banco sin secreto: 503) | ABORTA, 1 (`[503 presente]`, no `[401 presente]`) |
| N2 · el diff de PT-19 sin `--bootstrap` / N3 · con `--rutas` (la vía de NB-4) | ABORTA, 1 (`no va --rutas`) / ABORTA, 1 (`añade código que no es el montaje…: «$middleware->prependToPriorityList(»`), las dos como antes de la enmienda |
| N4 · `--bootstrap` con la huella equivocada (la del `app.php` de producción) | ABORTA, 1 (`tiene sha256 d8e9cd27…, no la declarada 294e57ab…`) |
| N5 · 16 cifras / sin `--marcador` / sin `--bootstrap` / con `--rutas` / en la vuelta | 2 en los cinco, sin tocar nada |
| N6 · marcador declarado al revés | ABORTA, 1 (`[401 presente]`, no `[401 ausente]`) |
| N7 · `validate_timestamps=0` en `etc-php/*/fpm/` / `revalidate_freq=900` | ABORTA, 1 (la comprobación de antes) / ABORTA, 1 (la nueva) |
| N8 · marcador mal declarado que no cambia (`content-type`) | VUELTA ATRÁS AUTOMÁTICA, 4; el marcador vuelve a `presente` |
| N9 · el servidor **no revalida** el PHP (OPcache con `validate_timestamps=0` solo en ese proceso: el paso 0 no lo ve) | el marcador sigue `401 presente` 32 s → **VUELTA ATRÁS AUTOMÁTICA, 4**, producción en `f1fe6cac` |
| N10 · `--bootstrap` en el lote 5, cuyo diff no trae `app.php` | ABORTA, 1 (`la ficha no es de este diff`) |
| **Regresión del lote 5** (`fbd5010f` + `72e76551`, como el lead a las 16:27) | 0 · 0 · 0; normalizado (rutas del banco, horas, `N` y fusiones del banco, milisegundos), **idéntico** a `ensayo-lead-lote5.out` salvo la línea añadida que dice qué guion se ejecutó |
| **Regresión del lote 4** (`f7f00fdc` + `72e76551`, como el lead a las 16:25; la vía `--rutas` + montaje en `app.php`, la rama del `case` que toca la enmienda) | 0 · 0 · 0; normalizado, **idéntico** a `ensayo-lead-lote4.out` salvo esa misma línea |

## 9. Lo que NO se puede comprobar en local, y cómo lo ve el lead con `--solo-comprobar`

- **bash 5 y glibc (m2)**: en el lote 3, `migración … transicion voice_calls vc_status_forward ambiguous>failed_to_start · esquema [1 true voice_forbid 6 false] = antes` y no «transicion exige…».
- **mawk (C1)**: `analizador de errores (C1), autoprueba con el awk de este servidor: bien`; si no, ABORTA.
- **sudo y PAM (m4)**: `umask de sudo -u www-data desde un umask 077: 0077`; si sale otro, AVISO (la ventana sigue abierta: para security) y se puede seguir.
- **tmux en Ubuntu (C2)**: el despliegue sin `--sin-tmux` dentro de tmux no tiene que negarse; si se niega estando dentro, comprobar `echo $TMUX` antes de usar `--sin-tmux`.
- **supervisor, FPM, `sudo -u www-data`, GNU `stat`/`install`**: `workers 14 RUNNING`, `cachés …: www-data 600`, OPcache sin `validate_timestamps=0`.
- **El envoltorio, el esquema y los privilegios reales**: `= la copia versionada (sha256 8ecb1479…)`; cada migración `= antes`; `pendientes … = las declaradas`; lote 4, privilegios por defecto sin `REFERENCES` ni `TRIGGER`; lote 5, `sin filas vivas … 0`.
- **Las banderas del entorno real**: las nuevas, `ausente`; `lab_gate texto:required`.
- **PT-19, lo que solo tiene el servidor**: que la respuesta real, a través de nginx y HTTP/2, lleve hoy `x-ratelimit-limit` (la evidencia `10-…` [3] dice que sí); el `revalidate_freq` real del FPM y si hay un `php_admin_value` de OPcache en el pool, que el paso 0 no lee (si lo hay, el marcador lo caza y vuelve atrás); mawk en el analizador del marcador. Este último se comprueba solo: exige `presente` en el paso 0 y `ausente` en el 4, así que un analizador que leyera siempre lo mismo no deja pasar nada. En el `--solo-comprobar` tiene que salir `marcador sin credenciales: GET api/internal/marketing/ai/doctor → 401, x-ratelimit-limit presente (= el previo; 1 lectura(s)) · … antes de <revalidate_freq + 30> s`. Y el tráfico real de n8n: lo mira una persona (§5 ter, «Después»).

## 10. Riesgos y deuda

- **Las ramas se mueven**: la lista se rehace SIEMPRE con el `P` y el `N` de ese momento; las declaraciones, si cambian migraciones, `config/`, `routes/` o los estados vivos del laboratorio (lo detalla `plans/informes-cierre/informe-ops-lotes-2.md`).
- **El envoltorio** sigue vaciando la caché antes de migrar y su `chmod 600` de root sigue un enlace (H-5): la propuesta del H7 (`ironbody-migrate-propuesta.sh`) lo corrige y no está instalada: para security.
- **C1 decide por la traza**: un error causado por el despliegue que solo pase por `vendor/` sale «ajeno» → DESVÍO (3), no vuelta atrás; por eso el 3 exige mirar las entradas.
- **PT-19**: `hotfix-backend.sh` **sigue rechazando** todo `backend/bootstrap/*`, a propósito: PT-19 va solo por este guion. Cada lectura del marcador con el previo gasta 1 de los 120 por minuto del asesor (en el paso 0, 1; tras la vuelta, 1; si el FPM tarda, hasta ~7 en el paso 4). La comprobación de OPcache del paso 0 solo lee líneas `opcache.*=` de `/etc/php/*/fpm/`: no ve un `php_admin_value[...]` en un pool ni `opcache.preload`. Es anterior a la enmienda y no se toca; en los lotes con `--marcador` lo cubre el marcador.
- `shellcheck`, sin pasar (no está instalado). Ningún secreto expuesto ni que rotar: los registros del ensayo no llevan la clave de la aplicación ni la del dueño del banco.
