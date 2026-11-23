# Despliegue por lotes del cierre de ULTRON STUDIO (`deploy-cierre-lote.sh`)

**Fecha:** 22-11-2026; el orden del cierre operativo, 23-11-2026. **Autor:** `devops`. **Estado:** lote 2 **DESPLEGADO** en producción (`fddd3450`, 14:58 UTC, sin desvíos: `evidencia-h9/2026-11-22/05-lote-2-despliegue.txt`). **El ORDEN DEL CIERRE OPERATIVO (§5 quater: siete unidades, de PT-19 a PT-15, una por `N` y cada una con la autorización del propietario) está PREPARADO Y ENSAYADO ENCADENADO EN LOCAL, NO EJECUTADO** (`evidencia-h9/2026-11-23/01-ensayo-de-la-cadena-de-despliegues.txt`). Sin `ssh` ni push. La **unidad 6 (voz)** solo con la **autorización específica del propietario**.
**Guion:** `ops/deploy-cierre-lote.sh`, con las correcciones de la revisión independiente (`plans/informes-cierre/informe-reviewer-guion.md`: C1–C3, m1–m9), la enmienda de PT-19 (`--bootstrap` y `--marcador`; sin ellas, todo igual) y la de la unidad 2b (`texto:<palabra>` admite «.»: `gpt-4.1-mini`) · **huella sha256 `db4bc1f5d856da8d945c9f305cded769ba6c9d8c14ebc8a4a8672f0bea78547a`** (con PT-19 y sin 2b, `ca03c1b5…`; antes de PT-19, `7c7298d8…`; la que desplegó el lote 2, `6911f61a…`). **La enmienda de 2b está pendiente de revisión** (reviewer y security), como lo estuvo la de PT-19. **Declaraciones:** `ops/cierre-lotes/{pt-19,lote-5,pt-17,lote-4,pt-16,lote-3,pt-15}/` (las siete unidades) y `lote-2/`. **Banco:** `ops/banco-cierre/`.
**Marcas:** **[C]** comprobado (ensayo o lectura del código) · **[L]** leído en un documento o una memoria · **[S]** supuesto, sin verificar.

## 0. En cinco líneas

1. **Lote 1** fue por `hotfix-backend.sh`; **lote 2**, por este guion (desplegado). Quedan el **3** (voz y telefonía), el **4** (consentimiento y quejas) y el **5** (laboratorio frente al motor): uno a la vez, cada uno con su `P` = lo que sirva producción en ese momento.
2. El guion **no despliega nada que el lote no declare** (migraciones, configuración, rutas, programador, filas que no puede haber y, para PT-19, `bootstrap/app.php` por su huella con un marcador sin credenciales); lo no declarado **aborta sin tocar nada**.
3. Orden: previas → respaldo → **en el orden del lote** migraciones (rol dueño, una a una) y código (`merge --ff-only`) → cachés como `www-data` → `queue:restart` → posteriores. **Solo dentro de `tmux` o `screen`** (C2).
4. Si una comprobación posterior falla, **vuelve atrás solo** (código 4). **Un `production.ERROR` ajeno al diff ya no**: es un **DESVÍO** (código 3) con la clase y el fichero:línea de cada error, y decide el lead (C1).
5. Ensayado en local con artisan y PostgreSQL 14 de verdad [C]: los tres lotes con las puntas de hoy, de punta a punta y con vuelta atrás, y un caso por cada corrección (§8). Lo que solo existe en el servidor, en el §9.
6. **Lo que queda por desplegar va en siete unidades, en este orden** (§5 quater): PT-19 · lote 5 con el modelo del laboratorio (2b) · PT-17 · lote 4 · PT-16 · voz (lote 3 + PT-14) · PT-15. Ensayadas encadenadas, las siete con 0 en comprobar, desplegar y volver; el producto al final es el de `integracion/final`.

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

**Desde el 23-11 el lote 3 no va solo: es la unidad 6 del orden del cierre, junto con PT-14** (`cierre/voz-catalogo`, §5 quater). `cierre-lotes/lote-3/migraciones.txt` declara ya las dos migraciones. Lo de esta sección vale para la parte del lote 3.

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

**Superado el 23-11 (§5 quater, unidad 4): la punta de hoy, `ba4b143e`, ya desciende de `f1fe6cac` y entra en la cadena sin choques.** Lo que sigue en este párrafo es de la punta anterior. **Antes de nada: `integracion/consent` NO desciende de `fddd3450`** [C: su base es `088589fb`; el diff directo borraría el lote 2]. Hay que integrarla con la rama principal (`feat/ultron-studio-h9`) y chocan **3 ficheros** (`.env.example`, `ULTRON_STUDIO_H9_CONTRACT.md` y `tests/Feature/Outreach/Schema/OutreachSchemaTest.php`): eso es de backend. El banco los resolvió por unión solo para ensayar.

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

**Desde el 23-11 es la unidad 2 del orden del cierre, CON 2b**: el modelo del laboratorio, escrito antes del despliegue con `cierre-lotes/lote-5/entorno-lab.sh`. Su `config.txt` lleva ahora 6 líneas (§5 quater, unidad 2). **Alcance** [C, `cierre/lab`, ensayada en `c13db1f4`; 55 ficheros frente a `fddd3450`]: 17 de `app/` (la fidelidad «motor» del laboratorio y su evidencia), la migración `2026_11_22_100001_add_engine_to_ultron_studio_lab_run_cases` (la columna `engine`, `json` nula), `config/ultron_studio.php` (la llave `publishing.lab_source`) y `.env.example`. Ni rutas ni tareas nuevas.

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

## 5 quater. Orden del cierre operativo: siete unidades, una por `N` (23-11-2026)

**Ninguna se despliega sin la autorización del propietario para ESA unidad**, dada en la conversación; la **6** (voz) además solo con su autorización específica (§4). Cada unidad se integra en `main` encima de la anterior, pasa su batería completa (la corre el lead) y se despliega con este guion; su `P` es lo que sirva producción en ese momento (`f1fe6cac` para la primera) y su `N`, la punta de `origin/main` con esa unidad y nada más fuera de `backend/docs/`. **Ensayadas encadenadas en el banco, las siete** [C]: comprobar, desplegar, volver atrás y desplegar otra vez, cada una sobre la anterior ya desplegada (`evidencia-h9/2026-11-23/01-ensayo-de-la-cadena-de-despliegues.txt`). Con el guion **`db4bc1f5…`** (la enmienda de 2b: §8, «La cadena del cierre operativo»). El producto al final de la cadena (`backend/app`, `bootstrap`, `config`, `database`, `routes`) es **idéntico** al de `integracion/final` (`4e53f04a`) [C, `git diff --stat` vacío].

| N.º | Unidad · rama (punta) | Banderas (`cierre-lotes/…`) | Migraciones | Configuración | Impacto | Después: marcador o comprobación | Vuelta atrás |
|---|---|---|---|---|---|---|---|
| 1 | **PT-19** · `cierre/puertas-internas` `c78e248e` (+ `cierre/ops-orden`, que trae el guion) | `pt-19/`: `--bootstrap=d8e9cd27…cb710 --marcador=marcador.txt` | — | — | Sin corte; las 21 rutas de `api/internal/` responden 401 sin gastar cupo (§5 ter) | El marcador `401 x-ratelimit-limit` presente → ausente (lo exige el paso 4) | Código; el marcador vuelve a presente (si no, PARAR) |
| 2 | **Lote 5 + 2b** · `cierre/lab` `bdb93199` | `lote-5/`: `--migraciones --orden=migracion-primero --config=config.txt --sin-filas`; antes, `entorno-lab.sh aplicar` | `columna ultron_studio_lab_run_cases engine json`, **conservar** | `lab_source=texto:simulation`, `lab_gate=texto:required` y las 4 del modelo del laboratorio (2b) | El laboratorio frente al motor; la puerta de publicar no cambia; el laboratorio pasa a `gpt-4.1-mini` | El paso 4 exige las 6 banderas; `labGate` de la canaria igual; `publication-check` 0 | Código y cachés (la columna se queda); 2b, `entorno-lab.sh vuelta=…` |
| 3 | **PT-17** · `cierre/planes-c5` `e5a673c1` **+ `c4323242`** (el pegamento) | `pt-17/`: **ninguna** (solo código) | — | — | **La versión publicada (compilador 4) deja de ofrecerse: la canaria vuelve al asesor anterior (Legacy, `compiler_changed`) hasta publicar una versión nueva** | `ultron:prompt-trace --json`: `compiler` 5 y `current.reason` `compiler_changed`; `publication-check` 0 (no lo ve); la sonda | Código: vuelve el compilador 4 y, con él, la versión publicada |
| 4 | **Lote 4** · `integracion/consent` `ba4b143e` | `lote-4/`: `--migraciones --orden=migracion-primero --config --rutas` | `tabla outreach_consents`, **deshacer** | 6 `legal.*=null` y `complaints.per_account_per_day=null` | 6 rutas de administración nuevas, sin llaves asignadas (§5) | Privilegios uno a uno; 1046 → 1052 rutas; la GET nueva → 401; `auth:coverage` vacío | Cachés y código; la tabla, solo vacía |
| 5 | **PT-16** · `cierre/pagos-guarda-iva` `e1c2af3c` | `pt-16/`: **ninguna** | — | — | Solo con `billing.pricing.v2_enabled` = `true`: el cobro manual de un plan con IVA adicional deja de chocar con la regla del precio crudo | Antes y después, `config:show billing.pricing.v2_enabled`; un cobro manual, la persona | Código |
| 6 | **Voz: lote 3 + PT-14** · `cierre/voz-catalogo` `e610d035` · **autorización específica del propietario** | `lote-3/`: `--migraciones --orden=migracion-primero --config` | `transicion voice_calls vc_status_forward ambiguous>failed_to_start` (**deshacer**) y `columna plans commercial_sale_by bigint` (**conservar**; la otra y las dos llaves, a mano) | `voice.number.shared_with_messaging=false` | Ninguna llamada posible (§4); **el nombre y los beneficios de un plan vendible, solo con `plans.commercial_text` (por defecto, solo Super Admin)** | `voice:doctor`; la consulta de las dos columnas y las dos llaves (abajo) | Código; el disparador; las columnas y sus llaves se quedan |
| 7 | **PT-15** · `cierre/planes-condiciones` `a994b050` | `pt-15/`: `--config --rutas` (diferencia vacía) | — | `automation.rate_limits.marketing_ai_check=60` | `ai/check` en su propio cubo (60 por minuto): ya no gasta el del asesor | **`route:list -v --path=internal/marketing/ai/check`** con `throttle:internal-marketing-ai-check`; la sonda | Cachés y código: `ai/check` vuelve al cubo del asesor |

**Fusiones en `main`** [C, en el clon de la cadena]: las unidades 1 a 5 entran sin choques. La **3** lleva, además de la rama, el pegamento del laboratorio: el commit `c4323242` de `integracion/final` (`StudioLabEngineFlow::COMPILER = 5` y dos pruebas), que entra limpio (`cherry-pick -x`). Sin él, `StudioLabEngineFlowParityTest` falla con el compilador 5. La **6** choca en `AuthorizationMap.php` y en `UltronStudioEngineReadOnlyTest.php`; la **7**, en `ULTRON_STUDIO_H5_CONTRACT.md`. Los tres choques se resuelven con los guiones del lead (`resuelve-fusion-pt14.py` y `resuelve-contrato.py`), que conservan las dos partes, y el resultado es byte a byte el de `integracion/final`. **Lo que `integracion/final` tiene y la cadena no** [C]: una prueba, `tests/…/UltronRecorridoWhatsappSimuladoTest.php` (PT-12, `14a99e69`, un commit hecho directamente en `integracion/final`, que no está en ninguna rama de unidad). No es producto ni cambia ninguna lista, pero si se quiere en `main` hay que llevarla aparte.

**Preparación de cada unidad** (desde el Mac, árbol limpio; como el §2, con el directorio de su declaración):

```bash
U=lote-5        # 2 lote-5 · 3 pt-17 · 4 lote-4 · 5 pt-16 · 6 lote-3 · 7 pt-15 (la 1, con la preparación del §5 ter: /root/cierre-pt19/)
git fetch -q origin
P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=8 HEAD')       # lo que sirve producción HOY
N=$(git rev-parse --short=8 origin/main)
git merge-base --is-ancestor "$P" "$N" && echo "avance rápido"
diff <(git diff --name-only --no-renames "$P" "$N" | grep -v '^backend/docs/' | LC_ALL=C sort) \
     <(git show "$N:backend/docs/ultron-studio/ops/cierre-lotes/$U/fuera-de-docs.txt" | grep -v '^#') && echo "fuera de docs/: lo de la unidad, exacto"
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" | shasum -a 256         # db4bc1f5… (si no, PARAR)
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-cierre-lote.sh && chmod 700 /root/deploy-cierre-lote.sh && sha256sum /root/deploy-cierre-lote.sh'
git diff --name-only --no-renames "$P" "$N" | ssh ironbody-vps "umask 077; mkdir -p /root/cierre-$U && cat > /root/cierre-$U/lista.txt && sha256sum /root/cierre-$U/lista.txt"
for f in $(git ls-tree --name-only "$N" "backend/docs/ultron-studio/ops/cierre-lotes/$U/"); do
  git show "$N:$f" | ssh ironbody-vps "umask 077; cat > /root/cierre-$U/$(basename "$f")"
done
```

`fuera-de-docs.txt` es la lista exacta de lo que la unidad cambia fuera de `backend/docs/`, sacada del banco de la cadena. Si el `diff` da algo, la unidad no es la ensayada: **PARAR**. En el servidor, cada unidad en su `tmux new -s u<k>`: primero `--solo-comprobar` y, con la autorización, la misma línea sin esa bandera. Las órdenes de cada una están en su ficha (§5 ter para la 1; abajo, las demás). Tras cada unidad, la vigilancia del §7.

### Unidad 2 · el lote 5 encima de PT-19, con 2b: el modelo del laboratorio

**Alcance**: el del §5 bis, sin cambios. Frente a la unidad 1 son 46 ficheros, 42 fuera de `docs/`, más **2b**: cuatro líneas del fichero de entorno, que el propietario ya decidió. Son `ULTRON_STUDIO_LAB_OPENAI_MODEL=gpt-4.1-mini`, `…_OPENAI_REASONING_EFFORT=` (vacía), `…_PRICE_INPUT_USD_PER_MTOK=0.40` y `…_PRICE_OUTPUT_USD_PER_MTOK=1.60`. Los precios son los de la página oficial, consultados el 22-11. **Los presupuestos no cambian.** Las cuatro claves **ya existen** en la configuración de producción (`config/ultron_studio.php` en `f1fe6cac`) [C], así que 2b no necesita código nuevo. Va con esta unidad para que **una sola** regeneración de la caché y **un solo** `queue:restart` (el del paso 3, que reinicia `ironbody-lab`) lo pongan en marcha, y para que el guion lo compruebe con su `--config`.

**Cómo se escribe** (`cierre-lotes/lote-5/entorno-lab.sh`, con lo decidido en `entorno-lab.txt`): como root, con el mecanismo de `h8-voice-scope.sh`. El respaldo va a `/var/backups/ironbody/entorno-lab-<fecha UTC>/` (700 de root): `env.antes` (600, byte a byte), el estado de las claves sin valores, la declaración y `SHA256SUMS`. La escritura es atómica: un temporal del mismo directorio con `O_EXCL`/`O_NOFOLLOW`, el dueño y el modo del original por el descriptor, `fsync` y `os.replace`. Antes de escribir, Dotenv, como `www-data`, comprueba que **cambian exactamente esas cuatro claves y ninguna otra**. Después lo relee; si no cuadra, repone el respaldo solo (código 4). **No imprime ninguna línea del fichero ni ningún valor previo**: de cada clave, solo `ausente`, `vacía`, `= lo decidido` u `otro valor`. **No toca ninguna caché.**

**Corregido al desplegar (23-11-2026)** [S]: en el primer intento en producción, `aplicar` **falló cerrado** (código 1, «no se ha tocado nada») con un `TypeError` de su comparación previa. Causa raíz, demostrada en el servidor: `compara()` pasaba `DECIDIDO` y `COMO` puestas **delante** de `sudo`, y el `sudo` de producción (`Defaults env_reset`) las borra; `php` se quedaba sin la declaración. El banco no lo vio porque su `sudo` conservaba el entorno. Corrección de **una línea**: `sudo -u "$WWW" env DECIDIDO=… COMO=… php -r` (sha256 del guion corregido `1fac5d1f…37a4`; el del commit `9c2325a1` era `ead13949…5400`). Ensayada en el propio servidor contra un fichero de entorno inventado con `cierre-lotes/lote-5/banco-en-servidor.sh` (el original falla igual que falló; el corregido comprueba, aplica exactamente las cuatro líneas, vuelve a comprobar y vuelve atrás byte a byte) y usada en el despliegue real, que terminó con código 0. **Sin revisión independiente.** La `vuelta` del guion original habría funcionado, con un AVISO engañoso (su comparación no decide si se repone). **Regla para cualquier guion que cruce a `www-data`: los datos, por la entrada estándar o con `env` DESPUÉS de `sudo`; nunca variables delante de `sudo`. Y ensayarlo en el servidor contra un árbol de pega antes de usarlo.** Evidencia: `evidencia-h9/2026-11-23/04-despliegue-de-la-unidad-2.txt`.

**Quién regenera la caché de configuración, y con qué usuario**: **el guion, al desplegar esta unidad**. Primero en el paso M: `ironbody-migrate`, que corre como `www-data` por `setpriv`, hace `config:clear` y, tras migrar, `config:cache`; ahí ya lee el entorno nuevo. Después en el paso K: `config:cache` como `www-data` con umask 077, porque la unidad declara `--config`. **Nunca root**: una caché de root deja los workers en BACKOFF. El paso 3 reinicia los 14 workers, `ironbody-lab` incluido, y el paso 4 lee la caché y exige `config.txt` entero. Son 6 líneas: las 2 del lote 5 y estas 4. `0.40` y `1.60` se declaran **`0.4` y `1.6`**, porque el guion normaliza los números (§ «banderas»), y la vacía se declara `vacia`. `config-sin-2b.txt` (las 2 de siempre) es solo para el caso en que el propietario retirase 2b.

```bash
# en el servidor, en tmux new -s u2 (lista y declaraciones subidas a /root/cierre-lote-5/ por el bloque de arriba)
cd /root/cierre-lote-5
bash entorno-lab.sh comprobar                                                     # solo lectura; esperado «aplicar cambiaría 4 de 4» (o menos, si alguna ya vale lo decidido)
bash /root/deploy-cierre-lote.sh $P $N lista.txt --migraciones=migraciones.txt --orden=migracion-primero \
  --config=config.txt --sin-filas=sin-filas.txt --solo-comprobar                    # paso 0: «…lab.openai.model cadena|…» (lo de hoy, sin valores de texto)
bash entorno-lab.sh aplicar                                                       # con la autorización; anota el respaldo que imprime
bash /root/deploy-cierre-lote.sh $P $N lista.txt --migraciones=migraciones.txt --orden=migracion-primero \
  --config=config.txt --sin-filas=sin-filas.txt                                     # el paso 4 tiene que imprimir las 6 banderas como config.txt
```

| Qué | Detalle |
|---|---|
| Impacto | El del lote 5 (§5 bis). Además, **desde el paso M el laboratorio lee `gpt-4.1-mini`**, sin esfuerzo de razonamiento y con los precios nuevos: la estimación de cada ejecución cambia; los topes y el presupuesto, no. `--sin-filas` garantiza que ninguna ejecución del laboratorio esté en curso cuando cambia |
| Si el despliegue aborta (1) con 2b ya escrito | El fichero de entorno lleva lo nuevo, pero **nadie lo lee mientras exista la caché**; `ironbody-migrate` no ha corrido. O se repite cuando cuadre, o `bash entorno-lab.sh vuelta=<respaldo>`, que no regenera nada |
| Si vuelve atrás solo (4) | El código vuelve a `P` y la caché a la del respaldo, **hecha con el entorno de antes**: la aplicación queda como antes de 2b, aunque el fichero lleve lo nuevo. Después, `bash entorno-lab.sh vuelta=<respaldo>`, sin `--regenera` (la caché ya es la de antes). Ensayado tras la vuelta a mano, que repone la caché del respaldo byte a byte igual que la automática [C]; el 4 en sí, ensayado con 2b sin aplicar (el NEGATIVO del §8) |
| Vuelta atrás a mano de la unidad | **Con 2b también**: primero `bash entorno-lab.sh vuelta=<respaldo de 2b>`. Como deja el fichero de entorno más nuevo que el respaldo del guion, la vuelta del guion **regenera** la caché con el código previo y el entorno repuesto (C3). Después la del guion, `--vuelta-atras=…`. **Conservando 2b**: la vuelta del guion repone la caché del respaldo, que lleva el modelo de antes. Hay que regenerarla después: `sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'` y `sudo -u www-data php artisan queue:restart -q` |
| Solo 2b, con la unidad desplegada | `bash entorno-lab.sh vuelta=<respaldo> --regenera`: repone el fichero, ejecuta `config:cache` como `www-data` (umask 077), deja la caché en 600 de `www-data` y lanza `queue:restart`. Ensayado al final de la cadena [C] |
| Lo que no se deshace | Las ejecuciones del laboratorio hechas con `gpt-4.1-mini`, con su coste |
| Después | El del §5 bis (`labGate` de la canaria igual y `publication-check` 0). En `GET lab/status` (sesión de superadmin), el modelo `gpt-4.1-mini` [S: la forma, de la rama; no lo llamé sin credenciales] |

### Unidad 3 · PT-17: el compilador 5 del turno de planes

**Alcance** [C, frente a la unidad 2: 35 ficheros, 31 fuera de `docs/`]: **2 de `app/`**. `UltronPromptCompiler.php` sube `VERSION` 4 → 5 y lleva los textos del parche del motor de la lista de planes: once entradas en vez de cuatro. `StudioLabEngineFlow.php`, el pegamento `c4323242`, sube `COMPILER` 4 → 5. Además, 17 pruebas y 3 fixtures de pruebas, y **9 ficheros de `n8n/`**: fixtures, las huellas y, en `flujo-s2.json`, solo `_generado.compiler` 4 → 5, el bloque para el comprobador. El guion los cuenta como «otros» y **no toca n8n**: el flujo publicado no cambia [C]. Sin migraciones, configuración, rutas ni programador. El paso 0 dice `2 de backend/app · 0 de config · 0 de routes · 0 migración(es) · 0 de bootstrap · 20 de tests · 4 de docs · 9 otros`.

```bash
# en tmux new -s u3
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-17/lista.txt --solo-comprobar
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-17/lista.txt
```

| Qué | Detalle |
|---|---|
| Impacto | **La versión publicada en canario está firmada con el compilador 4. Con el código en 5, `decide` deja de ofrecerla** (`UltronPromptOffer`, paso 5: `compiler_changed`) **y la conversación canaria vuelve a los prompts Legacy, el asesor anterior, hasta que se publique una versión nueva** con el compilador 5 [C, por lectura del código; el banco no tiene publicación]. Ninguna otra conversación cambia (ya son Legacy). El laboratorio frente al motor nombra sus recorridos `motor-S2-c5-…`: los veredictos `c4` no se comparan con los `c5`. La puerta de publicar no se mueve: `lab_source` sigue en `simulation` |
| Qué orden lo comprueba | **`sudo -u www-data php artisan ultron:prompt-trace --json --last=1`**, que es de solo lectura y sin ids ni textos. Antes: `compiler: 4`, `current.mode: studio`, `current.reason: null`. Después: `compiler: 5`, `current.mode: legacy`, `current.reason: compiler_changed`. **`ultron-studio:publication-check` NO lo ve**: comprueba la cadena (firma y deriva de lo declarado), no el compilador, y sale 0 antes y después. Hay que ejecutarlo igualmente, para ver que la cadena sigue verificando [C: en el banco, 0 en las cuatro pasadas]. La orden se llama `ultron-studio:publication-check`, no `ultron:publication-check` |
| Antes (obligatorio) | La sonda del motor con las clases de `N` (`ops/comprobacion-produccion/sonda-registro-y-panorama.php`, su cabecera dice cómo), en el servidor: su `HUELLA` tiene que ser **`ff5042f4fb3910f6`**, la del banco (PCRE 10.47; producción tiene 10.39 [L]), con 213 expresiones y 0 que no compilan |
| Bloqueos | Ninguno. Sin cachés que regenerar |
| Vuelta atrás | Código (`--vuelta-atras`): vuelve el compilador 4 y **la versión firmada con 4 se vuelve a ofrecer sola** (`prompt-trace`: `studio`). **Si entretanto se publicó una versión con el 5, al volver cae a Legacy** (`compiler_changed`, al revés): eso no se deshace con el código |
| Después (una persona) | `prompt-trace` como arriba. El siguiente turno de la canaria sale con los prompts Legacy (`lastTurns[0].observed`); nada de turnos mudos. Republicar es del propietario, desde el Studio |

### Unidad 4 · el lote 4 encima de la unidad 3, con la punta de hoy

**Alcance** [C]: `integracion/consent` `ba4b143e`, que ya incluye PT-18 (el identificador del socio). **Ya desciende de `f1fe6cac`**: el aviso del §5, que no descendía de `fddd3450`, es de una punta anterior, y aquí **no choca nada**. Frente a la unidad 3 son 80 ficheros: `31 de backend/app · 2 de config · 1 de routes · 1 migración · 1 de bootstrap · 32 de tests · 11 de docs · 1 otros` (`.env.example`). En `bootstrap/app.php` lo único que se añade es el montaje de `routes/commercial-automation.php`, encima del cambio de PT-19 que ya está en `P`. El paso 0 lo admite por la vía `--rutas`; **no lleva `--bootstrap`**. Las declaraciones de `lote-4/` valen sin cambios. Órdenes, impacto y vuelta: las del §5, con `/root/cierre-lote-4/`.

### Unidad 5 · PT-16: el cobro manual con IVA adicional

**Alcance** [C, 2 ficheros]: `app/Http/Controllers/Api/PaymentController.php`, donde `assertAmountMatchesPlan()` no corre con Pricing V2 encendido (+10 líneas), y `tests/baseline-failures.txt`, que pierde la línea del fallo que esto corrige. Sin migraciones, configuración ni rutas. El paso 0 dice `1 de backend/app · … · 1 de tests · 0 de docs · 0 otros`.

```bash
# en tmux new -s u5
sudo -u www-data php /var/www/api/backend/artisan config:show billing.pricing.v2_enabled    # antes: true o false (decide el impacto)
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-16/lista.txt --solo-comprobar
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-16/lista.txt
```

| Qué | Detalle |
|---|---|
| Impacto | **Con `v2_enabled` = `false`, ninguno**: la regla de siempre. **Con `true`**: el cobro manual de un plan con IVA adicional (`base_plus_tax`) ya no lo rechaza la regla del precio crudo. Antes ningún importe pasaba las dos reglas. Ahora la comparación estricta la hace `applyAuthoritativePricing()` contra el bruto que cotiza el servidor, con las mismas salidas [L, el comentario del código y la prueba `PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote`] |
| Bloqueos | Ninguno |
| Vuelta atrás | Código. **No deshace** los cobros registrados entretanto (son datos) |
| Después | Con V2 encendido, un cobro manual de un plan con IVA adicional, por una persona, desde el CRM: lo hace el propietario o quien cobre, no este guion |

### Unidad 6 · la voz: el lote 3 y PT-14 en una sola unidad

**NO SE DESPLIEGA SIN LA AUTORIZACIÓN ESPECÍFICA DEL PROPIETARIO, dada en la conversación** (como el §4). Ni la orden del lead ni la de este documento bastan.

**Alcance** [C, `cierre/voz-catalogo` `e610d035`, que contiene el lote 3 (`b1f66c32`); frente a la unidad 5, 304 ficheros, 77 fuera de `docs/`]. Lo del lote 3 (§4), más **PT-14**: la migración `2026_11_23_100001_add_commercial_text_author_to_plans_table`, el código (`PlanController`, `Plan`, `PlanArchive`, `PlanCommercialText` nuevo, los cuatro ojos de la publicación de la voz, `AuthorizationMap`, `CrmPermission` y `PermissionCatalog` con la llave nueva `plans.commercial_text`), y ni configuración ni rutas nuevas. La única configuración es la del lote 3 (`config/voice.php`). La fusión choca en dos ficheros, que se resuelven con `resuelve-fusion-pt14.py` y quedan iguales a `integracion/final` [C].

```bash
# en tmux new -s u6, con la autorización específica del propietario
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt \
  --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-3/config.txt [--solo-comprobar]
```

**¿Admite el tipo «columna» una columna con llave foránea?** [C, lectura del guion y ensayo]. **Sí la admite**, pero **no la comprueba entera**:
1. **Una columna por migración.** `lee_migraciones` exige los nombres en orden estricto y sin repetir, así que la migración de PT-14 solo puede declarar una de sus dos columnas. Se declara `commercial_sale_by`, la última que añade. Su forma `[1 bigint null -]` sí la mira (antes `[0 - - -]`).
2. **No ve las llaves foráneas.** `estado_mig` de una columna solo lee `pg_attribute`. La migración va en **una** transacción (`bounded()`, con `lock_timeout` 5 s, dentro de la del migrador), así que «registrada y con `commercial_sale_by`» implica que `commercial_text_by` y las dos llaves también se crearon: **por atomicidad, no por comprobación**.
3. **El paso 0 solo mira los cerrojos de las tablas declaradas** (`voice_calls` y `plans`), **no los de `admins`**, que las llaves bloquean con SHARE ROW EXCLUSIVE.

Por eso **el «Después» de esta unidad lleva, obligatoria, la consulta de abajo**. La **enmienda mínima** que lo cerraría la propongo en el informe (`informe-ops-orden.md` §4) y **NO está hecha**: un tipo `columna_fk` con la marca `<tipo>><tabla referida>`, que mire también `pg_constraint` (`contype f`, `confrelid`, `confdeltype`, `convalidated`) y los cerrojos de la tabla referida, y que admita varias líneas con el mismo nombre de migración. Toca `lee_migraciones`, `estado_mig`, `clase_mig`, el cálculo de pendientes (`ESPERADAS`) y `migra`: no es una línea. Sin ella, la unidad se despliega con lo de aquí.

| Qué | Detalle |
|---|---|
| Impacto | El del lote 3 (§4: ninguna llamada posible). Y PT-14: **cambiar el nombre o los beneficios de un plan vendible** (ULTRON, Sofía, enlaces de pago y la app), **o ponerlo a la venta, pide la llave nueva `plans.commercial_text`, que por defecto solo tiene Super Admin** [L, `CrmPermission`]. Quien hoy edite planes sin ser Super Admin recibe la negativa en esos cambios hasta que el propietario le asigne la llave en Usuarios y roles. Apagar, archivar y borrar no la piden. Los planes que ya existen quedan con las dos autorías en `null` («sin autor registrado»). Ni quien escribió el texto ni quien lo puso a la venta pueden publicar la voz que lo recoge |
| Migraciones | 1) `transicion voice_calls vc_status_forward ambiguous>failed_to_start`, **deshacer** (§4). 2) `columna plans commercial_sale_by bigint`, **conservar**: antes `[0 - - -]`, después `[1 bigint null -]` |
| Orden | **`migracion-primero`, obligatorio para las dos**: el código nuevo escribe las columnas y cree admitida la transición. Al volver: primero el código y después el esquema, en orden inverso (las columnas se conservan y el disparador se deshace) |
| Bloqueos | La transición: SHARE ROW EXCLUSIVE sobre `voice_calls` (§4). PT-14: **ACCESS EXCLUSIVE sobre `plans`** (la tabla no se reescribe: columnas nulas sin defecto) y **SHARE ROW EXCLUSIVE sobre `admins`**, por las dos llaves, que validan `plans` (pocas filas, todas `null`). Cada cobro lee `plans` con FOR KEY SHARE: un cobro en curso hace esperar al ALTER hasta 5 s; si no lo suelta, 55P03 y reintento (3 veces). Mientras dura (24 ms en el banco, la migración entera), los cobros esperan y nadie escribe en `admins` |
| Vuelta atrás | Código y cachés; después el disparador del respaldo. **Las dos columnas y sus llaves se quedan** (`SE CONSERVA`): el código previo no las ve, y borrar una cuenta deja `null` (ON DELETE SET NULL), así que el código previo no tropieza. Repetir la unidad después es una reanudación [C]. **No deshace**: las autorías ya escritas, las llamadas que `voice:resolve-ambiguous` haya cerrado (§4) ni las llaves `plans.commercial_text` asignadas |
| Después (obligatorio) | `voice:doctor` como antes (§4). Y, **como root y de solo lectura**, la consulta de abajo, que tiene que dar **2 columnas `bigint null -` con `UPDATE de iron_app: true`** y **2 llaves → `admins` con `ON DELETE n` y `validada true`** (el banco lo dio así, tras desplegar y tras volver atrás) |

```bash
sudo -u postgres psql -X -Atq -d ironbody \
  -c "SELECT a.attname || ' ' || format_type(a.atttypid, a.atttypmod) || ' ' || CASE WHEN a.attnotnull THEN 'notnull' ELSE 'null' END || ' ' || CASE WHEN a.atthasdef THEN 'defecto' ELSE '-' END || ' · UPDATE de iron_app: ' || has_column_privilege('iron_app', 'public.plans', a.attname, 'UPDATE') FROM pg_attribute a WHERE a.attrelid = 'public.plans'::regclass AND a.attname IN ('commercial_text_by', 'commercial_sale_by') AND NOT a.attisdropped ORDER BY 1" \
  -c "SELECT conname || ' → ' || confrelid::regclass || ' · ON DELETE ' || confdeltype || ' · validada ' || convalidated FROM pg_constraint WHERE conrelid = 'public.plans'::regclass AND contype = 'f' AND conname LIKE 'plans_commercial_%' ORDER BY 1"
# esperado (4 líneas): commercial_sale_by bigint null - · UPDATE de iron_app: true / commercial_text_by … true /
#           plans_commercial_sale_by_foreign → admins · ON DELETE n · validada true / plans_commercial_text_by_foreign → … true
```

### Unidad 7 · PT-15: las condiciones previas a ampliar WhatsApp

**Alcance** [C, frente a la unidad 6: 11 ficheros, 9 fuera de `docs/`]: `routes/marketing.php` (`POST api/internal/marketing/ai/check` pasa a su propio grupo, con el mismo secreto, `automation.internal`, y el limitador `throttle:internal-marketing-ai-check`; **ninguna ruta nueva ni quitada**), `config/automation.php` (`rate_limits.marketing_ai_check`, 60 por defecto), `AppServiceProvider` (el limitador nuevo, por minuto y con su propio cubo) y `CommercialTurnPolicy` (C-1: una petición del panorama vale solo si acaba ahí), más 5 pruebas. La fusión choca en el contrato del H5 y se resuelve con `resuelve-contrato.py` [C].

```bash
# en tmux new -s u7
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-15/lista.txt \
  --config=/root/cierre-pt-15/config.txt --rutas=/root/cierre-pt-15/rutas.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Impacto | `ai/check` deja de gastar el cupo del asesor (120 por minuto) y tiene el suyo (60 por minuto): el `commit` de un turno del Studio ya no recibe un 429 por las comprobaciones del mismo turno. Si se agota, n8n trata el 429 como «no reescribir» y el turno sigue. El asesor deja de dar la lista de planes ante preguntas por una característica («¿qué planes tienen sauna?»): esas las contesta el modelo |
| Configuración | `automation.rate_limits.marketing_ai_check=60`: el paso 0 tiene que dar `ausente` (la clave no existe hoy) y el paso 4, `60`. **Si el entorno de producción ya tuviera `MARKETING_AI_CHECK_RATE_LIMIT_PER_MINUTE`, PARAR y preguntar** |
| Rutas | `--rutas` con la diferencia **vacía** (`rutas.txt` solo lleva comentarios): `route:cache`, `route:list` sin ninguna diferencia (1052 → 1052 en el banco) y `auth:coverage --unmapped` vacío. **El guion compara «MÉTODO uri» y no ve el limitador** |
| Antes y después (obligatorio) | **`cd /var/www/api/backend && sudo -u www-data php artisan route:list -v --path=internal/marketing/ai/check`**: después, la ruta con `automation.internal` y **`throttle:internal-marketing-ai-check`**; antes y tras la vuelta, `throttle:internal-marketing-ai` [C en el banco]. Y **la sonda** con las clases de `N`: `HUELLA` **`ff5042f4fb3910f6`** (la del banco), con 215 expresiones y 0 que no compilan. Es la misma huella que en las unidades 2 y 3: su corpus fijo no ejercita los casos nuevos, así que la sonda mide la compatibilidad del PCRE, no el comportamiento. Esta unidad trae `\p{L}` y `\p{N}` nuevos en `CommercialTurnPolicy`, y el PCRE de producción no es el local |
| Bloqueos | Ninguno; `route:cache` y `config:cache` como `www-data` |
| Vuelta atrás | Cachés y código: `ai/check` vuelve al cubo del asesor (el 429 del `commit` puede volver) |
| Después (una persona) | En n8n, ningún 429 nuevo en `commit`; las llamadas a `ai/check` con los códigos de siempre |

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
- Orden del cierre (§5 quater). Unidad 2: las 6 banderas y el modelo en `lab/status`. Unidad 3: `ultron:prompt-trace` (`compiler_changed` en la canaria es lo esperado) y los turnos de la canaria sin mudos. Unidad 5: un cobro manual, si V2 está encendido. Unidad 6: la consulta de las dos columnas y las dos llaves, y ningún 403 inesperado en `PUT …/plans` (la llave nueva). Unidad 7: `route:list -v` y ningún 429 nuevo en `commit`.
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

**La cadena del cierre operativo, con el guion enmendado para 2b** (`db4bc1f5…`; 23-11-2026, 00:45–01:01 UTC; `evidencia-h9/2026-11-23/01-ensayo-de-la-cadena-de-despliegues.txt`). Montaje: `montar.sh` con dos cambios. Los commits vienen de un clon desechable con la cadena, y el fichero de entorno sintético lleva además un secreto interno y cuatro valores «previos» del laboratorio, todos sintéticos. Producción del banco en `f1fe6cac`; cada unidad, dentro de tmux y **sin `--sin-tmux`**, sobre la anterior ya desplegada. La base `ultron_studio_ops2_orden` se borró al terminar. La enmienda es una línea: `RE_CONFIG` admite «.» dentro de `texto:<palabra>`, que sigue empezando por letra, sin mayúsculas, espacios, barras, `$` ni comillas, hasta 32. Sin ella, `texto:gpt-4.1-mini` no pasa la validación.

| Caso | Resultado |
|---|---|
| Enmienda: prueba de `RE_CONFIG`, con la expresión leída del guion | 28 de 28 (12 líneas que tiene que aceptar y 16 que tiene que rechazar); con `ca03c1b5…`, 26 (rechaza `texto:gpt-4.1-mini`) |
| Enmienda: todas las líneas de `--config` del repo (`lote-2`, `lote-3`, `lote-4`, `lote-5` con y sin 2b, `pt-15`) | 25 de 25; con `ca03c1b5…`, 24 de 25 (solo la del modelo) |
| 1 · PT-19 | 0 · 0 · 0 · 0 (comprobar · desplegar · volver · otra vez); marcador presente → ausente → presente → ausente. **Es la regresión de la vía `--bootstrap`** con el guion enmendado |
| 2 · lote 5 + 2b | `entorno-lab comprobar` 0 · comprobar 0 · `entorno-lab aplicar` 0 · desplegar 0 (`[1 json null -]`; las 6 banderas: `texto:simulation`, `texto:required`, `texto:gpt-4.1-mini`, `vacia`, `0.4`, `1.6`) · volver 0 (`SE CONSERVA`; la caché, la de antes: `cadena · cadena · 9.99 · 19.99`) · `entorno-lab vuelta` 0 · **NEGATIVO: desplegar sin 2b → el paso 4 ve `cadena` → VUELTA ATRÁS AUTOMÁTICA, 4** · `aplicar` 0 · otra vez 0 (reanudación) |
| 3 · PT-17 | 0 · 0 · 0 · 0; `ultron:prompt-trace` `compiler` 4 → 5 → 4 → 5; `publication-check` 0 en las cuatro; la sonda, 213 expresiones, 0 rotas, `HUELLA ff5042f4fb3910f6` |
| 4 · lote 4 (`ba4b143e`) | 0 · 0 · 0 · 0; `[presente h5_owner ra 3]`, privilegios uno a uno, 1046 → 1052, `auth:coverage` vacío, la GET 401 → 404 → 401 |
| 5 · PT-16 | 0 · 0 · 0 · 0 |
| 6 · voz (lote 3 + PT-14) | 0 · 0 · 0 · 0; las dos migraciones aplicadas (20 y 24 ms); volver: la de PT-14 `SE CONSERVA` y el disparador `DESHECHA` («equivalente»); la consulta a mano: 2 columnas `bigint null -` con `UPDATE` de la aplicación y 2 llaves → `admins` (`ON DELETE n`, validadas), antes de volver y después; otra vez: reanudación de PT-14 y la transición aplicada de nuevo |
| 7 · PT-15 | 0 · 0 · 0 · 0; `marketing_ai_check` `ausente` → `60`; `route:list` 1052 → 1052 sin diferencia; `auth:coverage` vacío; `route:list -v`: `throttle:internal-marketing-ai` → `…-check` → `…-ai` → `…-check`; la sonda, 215 expresiones, 0 rotas, la misma huella |
| 2b al final: `entorno-lab vuelta --regenera`, con la unidad 2 desplegada | 0: el fichero, el del respaldo, byte a byte; `config:cache` (600) y `queue:restart`; la caché, `cadena · cadena · 9.99 · 19.99` |
| `entorno-lab.sh`, casos límite (aparte) | claves ausentes → añadidas al final, lo demás intacto (0) · una clave repetida → 1, sin respaldo · `export` y sangría → sin `export`, con su sangría (0) · modo 640 → 640 (0) · declarar `…_API_KEY` → 1 · `.env` enlace → 1 · respaldo alterado → 2 · sin salto de línea final (0) |
| Final | Producción del banco en `u7`; 354 migraciones, ninguna pendiente; producto = `integracion/final`. La clave sintética, el secreto interno y el modelo previo: 0 apariciones en la evidencia |

## 9. Lo que NO se puede comprobar en local, y cómo lo ve el lead con `--solo-comprobar`

- **bash 5 y glibc (m2)**: en el lote 3, `migración … transicion voice_calls vc_status_forward ambiguous>failed_to_start · esquema [1 true voice_forbid 6 false] = antes` y no «transicion exige…».
- **mawk (C1)**: `analizador de errores (C1), autoprueba con el awk de este servidor: bien`; si no, ABORTA.
- **sudo y PAM (m4)**: `umask de sudo -u www-data desde un umask 077: 0077`; si sale otro, AVISO (la ventana sigue abierta: para security) y se puede seguir.
- **tmux en Ubuntu (C2)**: el despliegue sin `--sin-tmux` dentro de tmux no tiene que negarse; si se niega estando dentro, comprobar `echo $TMUX` antes de usar `--sin-tmux`.
- **supervisor, FPM, `sudo -u www-data`, GNU `stat`/`install`**: `workers 14 RUNNING`, `cachés …: www-data 600`, OPcache sin `validate_timestamps=0`.
- **El envoltorio, el esquema y los privilegios reales**: `= la copia versionada (sha256 8ecb1479…)`; cada migración `= antes`; `pendientes … = las declaradas`; lote 4, privilegios por defecto sin `REFERENCES` ni `TRIGGER`; lote 5, `sin filas vivas … 0`.
- **Las banderas del entorno real**: las nuevas, `ausente`; `lab_gate texto:required`.
- **PT-19, lo que solo tiene el servidor**: que la respuesta real, a través de nginx y HTTP/2, lleve hoy `x-ratelimit-limit` (la evidencia `10-…` [3] dice que sí); el `revalidate_freq` real del FPM y si hay un `php_admin_value` de OPcache en el pool, que el paso 0 no lee (si lo hay, el marcador lo caza y vuelve atrás); mawk en el analizador del marcador. Este último se comprueba solo: exige `presente` en el paso 0 y `ausente` en el 4, así que un analizador que leyera siempre lo mismo no deja pasar nada. En el `--solo-comprobar` tiene que salir `marcador sin credenciales: GET api/internal/marketing/ai/doctor → 401, x-ratelimit-limit presente (= el previo; 1 lectura(s)) · … antes de <revalidate_freq + 30> s`. Y el tráfico real de n8n: lo mira una persona (§5 ter, «Después»).
- **La enmienda de 2b en bash 5 y glibc**: `[a-z0-9_.-]` va entre corchetes, sin barras (regla m2), y con `LC_ALL=C`. En el `--solo-comprobar` de la unidad 2 no tiene que salir `línea de --config no válida`.
- **2b, lo que solo tiene el servidor**: el dueño y el modo reales del fichero de entorno (el guion los conserva; el `comprobar` los imprime); si alguna de las cuatro claves está repetida o escrita con `export` (lo dice `comprobar`; una repetida, PARAR); si el pool de FPM o supervisor fijan alguna de esas variables por su cuenta (con la caché de configuración no cuenta, pero conviene saberlo); el valor de hoy, que nunca se imprime (`otro valor`, `vacía` o `ausente`).
- **Unidad 3, lo que el banco no tiene**: una publicación firmada con el compilador 4 en la canaria. Que `prompt-trace` pase de `studio` a `legacy` con `compiler_changed` solo se ve en producción [C, por lectura de `UltronPromptOffer`].
- **Unidad 5**: el valor real de `billing.pricing.v2_enabled`, que decide si hay impacto.
- **Unidad 6**: el tamaño de `plans` y el tráfico de cobros durante el ALTER (en el banco, 24 ms sin nadie); los cerrojos sobre `admins`, que el paso 0 no mira; qué cuentas editan hoy planes vendibles sin ser Super Admin (las que la llave nueva deja fuera).
- **Unidades 3 y 7**: la huella de la sonda con el PCRE de producción (10.39 [L]; el banco, 10.47) y `route:list -v` sobre la caché de rutas real.

## 10. Riesgos y deuda

- **Las ramas se mueven**: la lista se rehace SIEMPRE con el `P` y el `N` de ese momento; las declaraciones, si cambian migraciones, `config/`, `routes/` o los estados vivos del laboratorio (lo detalla `plans/informes-cierre/informe-ops-lotes-2.md`).
- **El envoltorio** sigue vaciando la caché antes de migrar y su `chmod 600` de root sigue un enlace (H-5): la propuesta del H7 (`ironbody-migrate-propuesta.sh`) lo corrige y no está instalada: para security.
- **C1 decide por la traza**: un error causado por el despliegue que solo pase por `vendor/` sale «ajeno» → DESVÍO (3), no vuelta atrás; por eso el 3 exige mirar las entradas.
- **PT-19**: `hotfix-backend.sh` **sigue rechazando** todo `backend/bootstrap/*`, a propósito: PT-19 va solo por este guion. Cada lectura del marcador con el previo gasta 1 de los 120 por minuto del asesor (en el paso 0, 1; tras la vuelta, 1; si el FPM tarda, hasta ~7 en el paso 4). La comprobación de OPcache del paso 0 solo lee líneas `opcache.*=` de `/etc/php/*/fpm/`: no ve un `php_admin_value[...]` en un pool ni `opcache.preload`. Es anterior a la enmienda y no se toca; en los lotes con `--marcador` lo cubre el marcador.
- **El tipo «columna» y las llaves foráneas** (unidad 6): el guion comprueba una columna por migración y ninguna llave. PT-14 se cubre por la atomicidad de su migración y por la consulta a mano obligatoria del «Después». La enmienda que lo cerraría (`columna_fk`, propuesta en `informe-ops-orden.md`) no está hecha.
- **La enmienda de 2b** (`RE_CONFIG`) cambia la huella del guion a `db4bc1f5…`: **reviewer y security tienen que verla antes de usarla**, y `N` tiene que traer `cierre/ops-orden` desde la unidad 1 (o, como muy tarde, la 2).
- **Unidad 3: la canaria vuelve a Legacy** hasta que el propietario publique una versión con el compilador 5. Es lo esperado, no un fallo.
- **La prueba de PT-12** (`UltronRecorridoWhatsappSimuladoTest.php`) está en `integracion/final` y en ninguna unidad: si se quiere en `main`, va aparte (solo pruebas).
- `shellcheck`, sin pasar (no está instalado). Ningún secreto expuesto ni que rotar: los registros del ensayo no llevan la clave de la aplicación ni la del dueño del banco.
