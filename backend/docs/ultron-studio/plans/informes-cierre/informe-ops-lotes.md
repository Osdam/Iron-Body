# Informe de devops · despliegue por lotes del cierre (`deploy-cierre-lote.sh`)

**22-11-2026 · rama `cierre/ops-lotes` · PREPARADO Y ENSAYADO EN LOCAL, NO EJECUTADO.** Sin `ssh`, sin push. Ficha completa: `ops/DEPLOY_CIERRE_LOTES.md`.

## 1. Qué hace el guion (servidor, root)

`bash /root/deploy-cierre-lote.sh <P> <N> <lista> [--migraciones=<f> --orden=migracion-primero|codigo-primero] [--config=<f>] [--rutas=<f>] [--programador=<f>] [--solo-comprobar]` y `… <P> <N> <lista> --vuelta-atras=<respaldo>`.
`--config`, `--rutas` y `--programador` llevan fichero (no son interruptores): la comprobación posterior necesita el valor esperado de cada bandera y la diferencia exacta de `route:list`/`schedule:list`. Las declaraciones de cada lote ya están en `ops/cierre-lotes/lote-{2,3,4}/`.

0. **Previas** (fallo → `ABORTA (no se ha tocado nada)`, 1): HEAD = P y árbol limpio; tras `fetch`, `origin/main` = N y desciende de P; ni `outreach(sends-open):` ni `voice(calls-open):`; las dos constantes **cerradas en P y en N** y sus ficheros sin tocar; diff = lista exacta; migraciones solo declaradas y **añadidas**; `config/` solo con `--config`; `routes/*` solo con `--rutas` (`console.php`, con `--programador`); de `bootstrap/` solo `app.php` con `--rutas` y **solo para montar un fichero de rutas nuevo del diff**; nada de composer/vendor. Servidor: 14 workers RUNNING; cachés `www-data 600`; OPcache revalidando; pendientes frente a N = las declaradas (y ninguna de P); esquema de partida de cada migración = «antes»; huella de `ironbody-migrate` frente a la copia versionada; `route:list`, `schedule:list`, banderas de hoy; `admin/voice/status` → 401.
1. **Respaldo** `/var/backups/ironbody/cierre-lote-<UTC>` (700/600 root): commits, lista, declaraciones, las dos cachés (la de configuración nunca se imprime), `route:list`, `schedule:list`, supervisor, migraciones registradas, `pg_dump --schema-only` de cada tabla tocada, la definición de partida de cada objeto, el guion y `SHA256SUMS`; `registro.log`.
2. **En el orden declarado:** cada migración sola con `ironbody-migrate --path=<copia root en /var/lib/ironbody-deploy, comprobada contra git>` (3 intentos ante 55P03), registrada y con el esquema «después»; el código con `merge --ff-only` (umask 022) y `www-data` leyendo cada fichero; `config:cache`/`route:cache` como `www-data` (umask 077).
3. `queue:restart`, pid nuevo (≤ 90 s) y dos lecturas pasado un margen.
4. **Posteriores** (fallo → **vuelta atrás automática**, 4): HEAD = N; legibles; workers; 401; ningún `production.ERROR` nuevo (salvo 55P03 reintentados); `migrate:status --pending` = «No pending migrations»; esquema «después»; diferencias de `schedule:list` y `route:list` = las declaradas; banderas en la caché = las declaradas (solo `false/true/null/vacia` o números de ≤ 6 cifras; una cadena sale «cadena»); GET nuevas → 401; `auth:coverage --unmapped` vacío.
**Vuelta atrás** (automática o manual; lee orden y declaraciones del respaldo): si migró primero, primero cachés (byte a byte, escritas por `www-data`) y código (`checkout --detach P`), después el esquema; si migró después, al revés. Del esquema, solo lo que aplicó ESTE despliegue y se declaró `deshacer`, en orden inverso y en una transacción por migración: CHECK → la definición del respaldo (la base se niega si alguna fila no la cumple); disparador → la del respaldo; tabla → `DROP` solo vacía y sin dependencias, con sus funciones; y fuera de `migrations`. Nunca `migrate:rollback`.

## 2. La orden de cada lote (P = lo que sirve producción en ese momento; lista y declaraciones subidas como en la ficha §2)

```bash
# Lote 1: hotfix-backend.sh (bandeja asistida), como estaba previsto.
# Lote 2 (gobierno + motores + pagos-aviso)
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-2/lista.txt --migraciones=/root/cierre-lote-2/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-2/config.txt --programador=/root/cierre-lote-2/programador.txt
# Lote 3 (voz + telefonía; SOLO con la autorización específica del propietario; orden obligatorio)
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-3/config.txt
# Lote 4 (consent)
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-4/lista.txt --migraciones=/root/cierre-lote-4/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-4/config.txt --rutas=/root/cierre-lote-4/rutas.txt
```
Siempre primero con `--solo-comprobar`. Bloqueos esperados: lote 2, ACCESS EXCLUSIVE sobre `outreach_budget_counters` 15–29 ms en el banco (hasta 5 s × 3 si otra sesión la retiene); lote 3, SHARE ROW EXCLUSIVE sobre `voice_calls` (frena escrituras, no lecturas); lote 4, ninguno sobre tablas existentes. En los tres, `ironbody-migrate` deja la app sin caché de configuración 1–3 s (funciona leyendo el entorno).

## 3. El ensayo (salidas finales, abreviadas; `<banco>` = el scratchpad)

Banco: `banco-cierre/montar.sh` (origen desnudo desechable con alternates, fusión `--no-ff` de las ramas sobre `8ae7646f`, PostgreSQL 14.24 local, roles `h5_owner`/`h5_app`, 349 migraciones del previo) y `banco-cierre/ensayar.sh` (la copia del guion con **solo sus 15 líneas de cabecera** sustituidas, lo comprueba; el envoltorio versionado sin `setpriv`; artisan, psql, git y curl reales). Las 5 bases `ultron_studio_ops_*` están **borradas**.

**Lote 2, camino feliz y vuelta atrás manual** (gobierno + motores + pagos-aviso fusionadas sin conflictos; 34 commits, 51 ficheros):
```text
   migración 2026_11_22_100001_allow_classifier_budget_scope · check outreach_budget_counters obud_scope_chk classifier:reply · esquema [1 true false] = antes · vuelta: deshacer
COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)                                                    → 0
M.    2026_11_22_100001_allow_classifier_budget_scope ............... 14.68ms DONE
   …: aplicada (intento 1) · esquema [1 true true] = después · caché de configuración: … la misma que la del respaldo
C. código: HEAD=5e6b91d2 (merge --ff-only, umask 022) · alejodev lee los 51 ficheros del merge
K. cachés: config:cache · rutas sin regenerar (como alejodev, umask 077) · alejodev 600 / alejodev 600
3. workers (RUNNING · BACKOFF/FATAL): antes [14 0] → [14 0] y [14 0] · con pid nuevo 14 de 14
4. admin/voice/status → 401 · migrate:status --pending: ninguna pendiente · declaradas registradas: 1 de 1
   schedule:list: 27 → 27 · diferencia [] · sin «payments:approved-unactivated-check» · route:list: 1046 → 1046 · diferencia []
   banderas: outreach.classifier.enabled false|…daily_cap 0|…model vacia|…api_key null|observability.approved_unactivated.enabled false · local.ERROR nuevos: 0
LOTE DESPLEGADO SIN DESVÍOS                                                                               → 0
   a. cachés y código: … byte a byte · HEAD=8ae7646f · b. esquema: …: DESHECHA (check …: la definición del respaldo, byte a byte; fuera de migrations)
   c. workers [14 0] y [14 0] · d. route:list igual · schedule:list igual · 401
VUELTA ATRÁS HECHA: producción en 8ae7646f, con las cachés, las rutas y el programador de antes          → 0
```
Con `--orden=codigo-primero`: `código → migraciones → cachés` y la vuelta `a. esquema … b. cachés y código` → 0 y 0.

**Fallos** (cada uno, su línea final):
```text
lista sin un fichero      ABORTA (no se ha tocado nada): la lista no coincide EXACTAMENTE … 1 en el diff que la lista no trae [backend/app/Services/ReplyClassification/OpenAiReplyClassifierTransport.php]  → 1
sin --migraciones         ABORTA …: el diff trae la migración 2026_11_22_100001_allow_classifier_budget_scope y no está en --migraciones …                                   → 1
sin --config              ABORTA …: el diff trae backend/config/observability.php y no va --config …                                                                         → 1
sin --programador         ABORTA …: el diff trae backend/routes/console.php y no va --programador …                                                                          → 1
voice(calls-open):        ABORTA …: el commit dbd34393 abre las llamadas (`voice(calls-open):`) …  · origin/main avanzó → «origin/main no es 5e6b91d2 (es dbd34393)» · árbol sucio → 1
bandera mal declarada     ✗ comprobaciones posteriores · las banderas no valen lo declarado … → VUELTA ATRÁS AUTOMÁTICA HECHA (CHECK DESHECHO)                                  → 4
worker en BACKOFF         3. workers … [13 1] y [13 1] → VUELTA ATRÁS AUTOMÁTICA HECHA (con el previo, [14 0])                                                               → 4
tabla retenida (55P03)    3 × «… 5s FAIL / SQLSTATE[55P03]»; la caché de configuración vuelve del respaldo → VUELTA ATRÁS AUTOMÁTICA HECHA · «no llegó a aplicarse»               → 4
fila classifier:reply     NO SE DESHACE, la base se negó … «obud_scope_chk … is violated by some row» → VUELTA ATRÁS HECHA …; SE CONSERVA el esquema de: 2026_11_22_100001_…  → 0
tercer commit             ✗ producción está en 8bf2aad7, ni en el nuevo ni en el previo … → VUELTA ATRÁS CON DESVÍOS: PARAR                                                  → 5
respaldo manipulado       el respaldo no coincide con su SHA256SUMS; no se ha tocado nada                                                                                    → 2
```
**Lote 3** (`cierre/voz` sola): disparador `[1 true voice_forbid 6 false] → [… true]`, despliegue → 0; vuelta: código primero, después «DESHECHA (… la definición del respaldo, equivalente: PostgreSQL reescribe los cast al recrearla)» → 0. **Lote 4** (`cierre/consent`): tabla `[presente h5_owner ra 3]`, `route:list` 1046 → 1051 con las 5 declaradas, `auth:coverage` vacío, GET nueva → 401 → 0; vuelta: 404, tabla y 3 funciones fuera → 0. `bootstrap/app.php` con otro código, o sin `--rutas` → 1 y 1.

**Corregido por el ensayo:** (1) las cuentas de cerrojos y transacciones largas miraban todo el clúster; (2) los importes de `config/outreach.php` son cadenas (`'0'`); (3) la vuelta del disparador exigía igualdad byte a byte y PostgreSQL no la da: ahora compara la definición normalizada y dice cuál fue; (4) `migrate:status` sin su «No pending migrations» explícito habría pasado por limpio si artisan fallaba.

## 4. Lo que la vuelta atrás NO deshace

Los datos del código nuevo; el esquema `conservar` o cuya vuelta la base rechaza (queda el ancho, que el código previo admite); el disparador del lote 3 queda **equivalente, no idéntico** (ningún guion ni monitor del repo compara ese texto); el entorno (no se lee ni se escribe); n8n, CRM, nginx, FPM, supervisor, crontab; HEAD queda suelto en P y `main` del servidor en N; las copias de `/var/lib/ironbody-deploy` quedan.

## 5. Lo que NO se pudo ensayar y cómo lo comprueba el lead con `--solo-comprobar`

| Solo en el servidor | Qué tiene que decir el paso 0 |
|---|---|
| supervisor, FPM, `sudo -u www-data`, GNU `stat`/`install`, bash 5 (el banco: dobles y bash 3.2) | `workers 14 RUNNING (esperados 14) · 0 BACKOFF/FATAL` · `cachés …: www-data 600` · la línea de OPcache sin `validate_timestamps=0` |
| El rol dueño real y el envoltorio instalado | `/usr/local/bin/ironbody-migrate = la copia versionada de iron-ia/ops (sha256 8ecb1479…)`; si sale AVISO, mirar antes |
| El esquema y los privilegios reales | cada migración `= antes`; `pendientes … = las declaradas`; lote 4: privilegios por defecto con `INSERT,SELECT` |
| Las banderas del entorno real | las nuevas en `ausente` en «banderas declaradas, hoy en la caché» |
| El `git fetch` contra GitHub y la URL real | sin ABORTA de git; `admin/voice/status → 401` |
| Tiempos reales de cerrojo y de `queue:restart` | `cerrojos de otras sesiones …: 0`; después, pid nuevo 14 de 14 (si no, AVISO) |

## 6. Riesgos y deuda

- El envoltorio instalado vacía la caché de configuración antes de migrar y no la rehace si falla (el guion la repone); su `config:cache` + `chmod 600` de root deja un instante la caché legible [S]. La propuesta del H7 lo corrige y no está instalada: **para security**.
- Una comprobación posterior ruidosa (un `production.ERROR` ajeno) dispara la vuelta atrás: es lo pedido; mirar el motivo antes de repetir.
- `shellcheck` **no pasado**: no está instalado, y no lo he descargado. `bash -n` OK en los tres guiones.
- Voz y telefonía chocan en `ULTRON_STUDIO_H8_CONTRACT.md`: la fusión es de quien integre; el lote 3 se ensayó con `cierre/voz` sola.
- Un `git merge-tree`/`commit-tree` de prueba dejó un objeto suelto (sin ref) en la base de objetos del repo; ningún commit ni ref.
- Ningún secreto expuesto ni que rotar: los registros del ensayo no contienen la clave de la aplicación ni la del dueño del banco.
