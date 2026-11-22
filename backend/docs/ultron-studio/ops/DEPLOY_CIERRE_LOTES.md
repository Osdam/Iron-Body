# Despliegue por lotes del cierre de ULTRON STUDIO (`deploy-cierre-lote.sh`)

**Fecha:** 22-11-2026. **Autor:** `devops`. **Estado: PREPARADO Y ENSAYADO EN LOCAL, NO EJECUTADO.** No se ha tocado producción: ni `ssh`, ni push. Cada lote se despliega con la orden del lead, y el **lote 3 solo con la autorización específica del propietario**.
**Guion:** `ops/deploy-cierre-lote.sh` (derivado de `deploy-a4-regenerar-backend.sh`; las migraciones, como `deploy-h9-backend-cierre.sh` e `iron-ia/ops/deploy-recuperacion-rutinas.sh`). **Declaraciones:** `ops/cierre-lotes/lote-{2,3,4}/`. **Banco:** `ops/banco-cierre/`.
**Marcas:** **[C]** comprobado (ensayo o lectura del código) · **[L]** leído en un documento o una memoria · **[S]** supuesto, sin verificar.

## 0. En cinco líneas

1. **Lote 1** (la bandeja asistida): va por `hotfix-backend.sh`, como está previsto. Este guion es para los lotes 2, 3 y 4.
2. El guion **no despliega nada que el lote no declare**: migraciones (con su tipo, su comprobación de esquema y si se deshacen), configuración (con el valor de cada bandera nueva), rutas HTTP (la diferencia exacta de `route:list`) y programador (la de `schedule:list`). Lo no declarado **aborta sin tocar nada**.
3. Orden: comprobaciones previas → respaldo → **en el orden del lote** las migraciones (rol dueño, una a una, `ironbody-migrate --path`) y el código (`merge --ff-only`) → `config:cache`/`route:cache` como `www-data` → `queue:restart` → comprobaciones posteriores.
4. **Si una comprobación posterior falla, vuelve atrás solo** (código 4), en el orden inverso: si el lote migró primero, primero el código y después el esquema. Del esquema solo deshace lo declarado «deshacer» y lo que la base admite.
5. Ensayado en local con artisan y PostgreSQL 14 de verdad [C]: el lote 2 real de punta a punta, en los dos órdenes, con su vuelta atrás manual y automática y 13 casos de fallo; y los lotes 3 y 4 con su rama principal (voz; consent) de punta a punta. Lo que solo existe en el servidor, en el §9.

## 1. Entorno

| Qué | Valor | Fuente |
|---|---|---|
| Entorno | **Producción**: `/var/www/api` (la API en `backend/`), PHP-FPM + nginx, `www-data` | [L] `RUNBOOK_H9.md`, guiones de `ops/` |
| Base | PostgreSQL 14; la aplicación entra como `iron_app` (solo datos); las migraciones, con el dueño `iron` por `/usr/local/bin/ironbody-migrate` (sha256 `8ecb1479…`, la copia de `iron-ia/ops/ironbody-migrate`) | [L] memoria `migraciones-produccion-rol-dueno`; [C] huella de la copia |
| Colas | 14 programas de supervisor, ninguno en `default`; `queue:restart` es una señal en caché, no un trabajo | [L] memoria `colas-produccion-sin-default` |
| Cachés | `bootstrap/cache/config.php` y `routes-v7.php`, de `www-data` en 600 | [L] memoria `config-cache-y-los-workers` |
| Respaldos | `/var/backups/ironbody/cierre-lote-<fecha UTC>` (700 de root); copias de las migraciones en `/var/lib/ironbody-deploy/cierre-lote-<fecha>/` (root, 755/644) | [C] el guion |

## 2. Cómo se prepara cada lote (desde el Mac, en la raíz del repo, árbol limpio)

Requisitos: la orden del lead (y, para el lote 3, la del propietario); la rama del lote **integrada y subida a `origin/main`**, con este guion y sus declaraciones dentro; nadie más desplegando (memoria `otra-sesion-mueve-main-y-produccion`: el guion se niega si producción u `origin/main` se mueven).

```bash
L=2                                                                       # 2, 3 o 4
git fetch -q origin
P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=8 HEAD')       # lo que sirve producción HOY (tras el lote anterior)
N=$(git rev-parse --short=8 origin/main)
git merge-base --is-ancestor "$P" "$N" && echo "avance rápido"
git log --oneline "$P..$N"                                                # ¿solo lo del lote? Lo ajeno se despliega antes por su vía
git diff --name-only --no-renames "$P" "$N" | grep -vE '^backend/(app|tests|docs)/|^n8n/'   # tiene que ser lo del §3, §4 o §5

# Subir el guion y las declaraciones DEL COMMIT QUE SE DESPLIEGA, y la lista (las huellas, iguales en los dos lados)
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" | shasum -a 256
git show "$N:backend/docs/ultron-studio/ops/deploy-cierre-lote.sh" \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-cierre-lote.sh && chmod 700 /root/deploy-cierre-lote.sh && sha256sum /root/deploy-cierre-lote.sh'
git diff --name-only --no-renames "$P" "$N" | shasum -a 256
git diff --name-only --no-renames "$P" "$N" | ssh ironbody-vps "umask 077; mkdir -p /root/cierre-lote-$L && cat > /root/cierre-lote-$L/lista.txt && sha256sum /root/cierre-lote-$L/lista.txt"
for f in $(git ls-tree --name-only "$N" "backend/docs/ultron-studio/ops/cierre-lotes/lote-$L/"); do
  git show "$N:$f" | ssh ironbody-vps "umask 077; cat > /root/cierre-lote-$L/$(basename "$f")"
done
```

- **La lista se saca con `--no-renames`** (un renombrado lleva el nombre viejo y el nuevo) y con el `P` que sirve producción **en ese momento**: tras el lote 1, `P` ya lo incluye.
- Después, **en el servidor**, primero `--solo-comprobar` (solo lee y hace `git fetch`) y, con la orden, la misma línea sin esa bandera. La vuelta atrás manual es la que imprime el guion al final.

## 3. Lote 2: gobierno, motores y aviso de pagos

**Alcance** [C, diff del banco `8ae7646f → fusión`, 51 ficheros]: `cierre/gobierno` (solo `app/Services/UltronStudio/…`), `cierre/motores` (el clasificador AI9: código, `config/outreach.php`, `.env.example`, una línea en `AppServiceProvider`, y la migración `2026_11_22_100001_allow_classifier_budget_scope`) y `cierre/pagos-aviso` (la orden `payments:approved-unactivated-check`, `config/observability.php`, `routes/console.php`, `.env.example`).

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-2/lista.txt \
  --migraciones=/root/cierre-lote-2/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-2/config.txt --programador=/root/cierre-lote-2/programador.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Migración | `check outreach_budget_counters obud_scope_chk classifier:reply` · antes `[1 true false]`, después `[1 true true]` (un `obud_scope_chk`, validado, con el alcance nuevo) · `deshacer` |
| Orden | `migracion-primero` (la de siempre al ampliar). `codigo-primero` también vale aquí: con la bandera apagada el código nuevo no escribe `classifier:*` (ensayados los dos) |
| Configuración | `outreach.classifier.{enabled=false, daily_cap=0, daily_budget_usd=0, model=vacia, price_input…=0, price_output…=0, api_key=null}` y `observability.approved_unactivated.enabled=false`. Producción no tiene ninguna de esas claves en su entorno: valen los defectos [C en el banco, sin entorno] |
| Programador | sin diferencia; `ausente payments:approved-unactivated-check` (solo se agenda con su bandera) |
| Rutas | ninguna: `route:list` igual (1046 en el banco) |
| `queue:restart` | sí (siempre) |
| Bloqueo | `ALTER TABLE … DROP CONSTRAINT, ADD CONSTRAINT` toma ACCESS EXCLUSIVE sobre `outreach_budget_counters` y revisa sus filas: 15–29 ms en el banco [C]. Si otra sesión la tiene, espera como mucho 5 s por intento (lock_timeout) y lo reintenta 3 veces; mientras espera, quien llegue detrás también espera. Con los envíos cerrados, esa tabla apenas se usa [S] |
| Otras ventanas | `ironbody-migrate` vacía la caché de configuración durante la migración (1–3 s: la app lee el entorno en cada petición, funciona); entre el `merge` y `config:cache`, el código nuevo con la caché vieja: las claves nuevas leen `null` → apagado |
| Vuelta atrás | cachés del respaldo y código; después el CHECK estrecho del respaldo, **solo si ninguna fila lo incumple** (si ya hay una `classifier:reply`, la base se niega y el CHECK ancho se queda: el código previo lo admite) |

## 4. Lote 3: voz y telefonía (CON LA AUTORIZACIÓN ESPECÍFICA DEL PROPIETARIO)

**Alcance** [C para `cierre/voz`; L para `cierre/telefonia`]: `cierre/voz` (la migración `2026_11_22_100002_allow_unstarted_ambiguous_voice_calls_to_fail_to_start`, que rehace el disparador `vc_status_forward`, y el código que usa la transición `ambiguous → failed_to_start`) y `cierre/telefonia` (`config/voice.php`, `.env.example`). **Las dos ramas chocan en un documento** (`ULTRON_STUDIO_H8_CONTRACT.md`): lo resuelve quien integre; el banco ensayó `cierre/voz` sola.

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt \
  --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-3/config.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Migración | `transicion voice_calls vc_status_forward ambiguous>failed_to_start` · antes `[1 true voice_forbid 6 false]`, después `[… 6 true]` (un disparador, encendido, la misma función y los mismos 6 disparadores en la tabla; la cláusula de `'ambiguous'` admite `'failed_to_start'`) · `deshacer` |
| Orden | **`migracion-primero`, obligatorio** (demostrado por la rama: sin la migración, el código creería admitida una transición que la base rechaza con P0001). Al volver: **primero el código, después el disparador**: el guion lo hace así al leer el orden del respaldo |
| Configuración | `voice.number.shared_with_messaging=false` (telefonía). Si el lote integrado no trae `config/`, se quita `--config` |
| Programador / rutas | sin diferencia en ninguno de los dos |
| Bloqueo | `CREATE OR REPLACE TRIGGER` toma SHARE ROW EXCLUSIVE sobre `voice_calls`: frena a quien escribe, no a quien lee [L: la migración]; con las llamadas cerradas no escribe nadie [S] |
| Vuelta atrás | código y cachés; después el disparador con la definición del respaldo. **Queda equivalente, no idéntico byte a byte**: PostgreSQL reescribe los cast del `ARRAY` al recrearlo (medido [C]); ningún guion ni monitor del repo compara ese texto [C, grep]. Las filas que el código nuevo cerrara así se quedan (valen también con el disparador de DB8 [L]) |

## 5. Lote 4: autorizaciones de contacto (consent)

**Alcance** [C, diff del banco, 34 ficheros]: la migración `2026_11_22_100001_create_outreach_consents_table` (tabla nueva, aditiva e inerte, con 3 funciones propias y el REVOKE a la aplicación), `config/outreach_consents.php` (nuevo), `routes/commercial-automation.php` (**fichero de rutas nuevo**), su montaje en `bootstrap/app.php` (como `routes/voice.php`) y dos llaves de permiso en el catálogo, que viven en el código (no llegan por defecto a ningún rol; no hay nada que sembrar en la base) [C].

```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-4/lista.txt \
  --migraciones=/root/cierre-lote-4/migraciones.txt --orden=migracion-primero \
  --config=/root/cierre-lote-4/config.txt --rutas=/root/cierre-lote-4/rutas.txt [--solo-comprobar]
```

| Qué | Detalle |
|---|---|
| Migración | `tabla outreach_consents - outreach_consent_ ra` · antes `[ausente - - 0]`, después `[presente iron ra 3]` (de `iron`; `iron_app` con SELECT e INSERT y nada más; 3 funciones `outreach_consent_*`) · `deshacer`. El paso 0 imprime los privilegios por defecto de `iron` para `iron_app`: tienen que incluir SELECT e INSERT |
| Orden | `migracion-primero` (el código consulta la tabla) |
| Configuración | las 6 de `outreach_consents.legal.{commercial,collections,service}.{version,text}=null` (el texto legal no existe: registrar y confirmar dan 409) |
| Rutas | exactamente las 5 de `rutas.txt` (`GET|HEAD …/consents`, `POST …/consents`, `…/revoke`, `…/{uuid}/confirm`, `…/{uuid}/reject`); `auth:coverage --unmapped` vacío; `GET api/admin/commercial-automation/consents` sin token → 401 después y 404 tras la vuelta atrás. `bootstrap/app.php` solo puede añadir ese montaje (lo comprueba el paso 0) |
| `queue:restart` | sí: tras migrar lo exige la rama, y el guion lo hace siempre |
| Bloqueo | ninguno sobre tablas existentes: la migración crea objetos nuevos [C] |
| Vuelta atrás | cachés (las rutas nuevas desaparecen) y código; después `DROP TABLE` **solo si está vacía y sin dependencias**, con sus funciones, y fuera de `migrations`, en una transacción. Si alguien ya retiró o rechazó una autorización (eso funciona sin texto legal), la tabla tiene filas y **se queda** |

## 6. Si no termina en verde

| Salida | Qué significa | Qué hacer |
|---|---|---|
| **1** `ABORTA (no se ha tocado nada)` | Falló una comprobación previa | Leer el motivo y corregir la causa; nunca se salta una comprobación |
| **2** | Uso, o un respaldo que no cuadra con su `SHA256SUMS` | Revisar la orden |
| **4** `VUELTA ATRÁS AUTOMÁTICA HECHA` | Falló una migración (tras 3 intentos), el `merge`, una caché o una comprobación posterior. Producción en `<P>`, cachés del respaldo, workers reiniciados | Diagnosticar con `registro.log` y `migracion-*-intento-*.log` del respaldo; no repetir sin la causa |
| **5** `PARAR` | La vuelta atrás no quedó limpia, o una salida inesperada a medias | Ver abajo |

**A mano** (lo mismo que hace el guion, con los valores del respaldo `$BK`): `bash /root/deploy-cierre-lote.sh <P> <N> <lista> --vuelta-atras=$BK`. Si ni eso sirve: en `backend/`, `sudo -u www-data sh -c 'umask 077; cat > bootstrap/cache/X.cierre-vuelta && mv -f bootstrap/cache/X.cierre-vuelta bootstrap/cache/X' < $BK/bootstrap-cache/X` (X = `routes-v7.php` y `config.php`), `( cd /var/www/api && umask 022 && git checkout -q --detach <P> )`, y el esquema con `sudo -u postgres psql -d ironbody < $BK/vuelta-<i>.sql` (lo escribe el guion la primera vez; si no existe, es de database-specialist). Después `sudo -u www-data php artisan queue:restart`. **Nunca `migrate:rollback`.** Si el guion se cortó: `git -C /var/www/api rev-parse --short=8 HEAD` y el último `registro.log` dicen hasta dónde llegó; la vuelta atrás vale con HEAD en `<N>` o en `<P>`.

**Lo que la vuelta atrás NO deshace:** los datos que escribió el código nuevo; el esquema de una migración `conservar` o cuya vuelta la base rechaza (se dice, y queda el esquema ancho, que el código previo admite); el fichero de entorno (el guion no lo lee ni lo escribe); n8n, el CRM, nginx, FPM, supervisor y el crontab. HEAD queda suelto en `<P>` y la rama `main` del servidor en `<N>` (como tras `hotfix-backend.sh`). Las copias de las migraciones en `/var/lib/ironbody-deploy` se quedan como evidencia.

## 7. Vigilancia (15 min, solo lectura)

`supervisorctl status | awk '{print $2}' | sort | uniq -c` (14 RUNNING) · `production.ERROR` nuevos en el último `laravel*.log` · `failed_jobs` · 5xx en `access.log`. Lote 4: ningún 5xx en `/api/admin/commercial-automation/`. Lote 3: `voice:doctor` como antes.

## 8. El ensayo en local [C]

Banco en el scratchpad de la sesión (nada en el repo): `banco-cierre/montar.sh <banco> 8ae7646f ultron_studio_ops_<x> <ramas…>` fusiona las ramas con `--no-ff` en un clon desechable y migra el previo (349 migraciones, 38 s); `banco-cierre/ensayar.sh <banco> guion …` ejecuta la copia del guion con **solo sus 15 líneas de cabecera sustituidas** (lo comprueba). PostgreSQL 14.24 en 127.0.0.1:55432 (la misma versión mayor que producción); bases `ultron_studio_ops_*`, **borradas al terminar** (0 quedan).

| Caso | Resultado |
|---|---|
| Lote 2, `--solo-comprobar` | `COMPROBACIONES PREVIAS EN VERDE`, código 0 |
| Lote 2, despliegue (`migracion-primero`) | migración 15 ms, `merge`, `config:cache`, 14/14 con pid nuevo, banderas como se declaran, `schedule:list` y `route:list` sin diferencia, 0 errores → `LOTE DESPLEGADO SIN DESVÍOS`, 0 (15 s) |
| Lote 2, `--vuelta-atras` | código y cachés byte a byte; CHECK del respaldo byte a byte y fuera de `migrations` → `VUELTA ATRÁS HECHA`, 0 |
| Lote 2, `codigo-primero` y su vuelta | `código → migraciones → cachés`; la vuelta hace primero el esquema y después el código → 0 y 0 |
| Lote 2, vuelta con una fila `classifier:reply` | `NO SE DESHACE, la base se negó … is violated by some row` → `SE CONSERVA el esquema`, 0 |
| Lote 2, reanudación (migración ya registrada) | `ya registrada: no se ejecuta` → 0; su vuelta no la toca |
| Lista que no cuadra · migración no declarada · `config/` sin `--config` · `console.php` sin `--programador` · `voice(calls-open):` · `origin/main` avanzó · árbol sucio | `ABORTA (no se ha tocado nada)`, 1 (cada uno con su motivo) |
| `--migraciones` sin `--orden` · respaldo manipulado | código 2, sin tocar nada |
| Una bandera que no vale lo declarado (comprobación posterior) | `VUELTA ATRÁS AUTOMÁTICA HECHA`, 4, con el CHECK deshecho |
| Un worker en BACKOFF con el código nuevo | `[13 1]` → vuelta atrás automática; con el previo `[14 0]` → 4 |
| La migración sin cerrojo (otra sesión retiene la tabla) | 3 × 55P03; la caché de configuración que vació `ironbody-migrate` vuelve del respaldo; vuelta atrás automática → 4 |
| Vuelta atrás con producción en un tercer commit | `PARAR`, 5, sin tocar |
| Lote 3 (`cierre/voz`), despliegue y vuelta | disparador `[… false] → [… true]`; vuelta: código primero, después el disparador (equivalente) → 0 y 0 |
| Lote 4 (`cierre/consent`), despliegue y vuelta | tabla `[presente h5_owner ra 3]`, 5 rutas exactas, `auth:coverage` vacío, GET nueva → 401; vuelta: 404, tabla y 3 funciones fuera → 0 y 0 |
| `bootstrap/app.php` con otro código · sin `--rutas` | `ABORTA`, 1 y 1 |

## 9. Lo que NO se puede comprobar en local, y cómo lo ve el lead con `--solo-comprobar`

- **supervisor, FPM, `sudo -u www-data`, GNU `stat`/`install`, el bash del servidor**: en el banco son dobles (bash 3.2 del Mac). `--solo-comprobar` tiene que dar `workers 14 RUNNING (esperados 14)` y `cachés …: www-data 600`.
- **El rol dueño real y el envoltorio instalado**: el banco usa `h5_owner`/`h5_app` y la copia del envoltorio sin `setpriv`. `--solo-comprobar` tiene que decir `/usr/local/bin/ironbody-migrate = la copia versionada de iron-ia/ops (sha256 8ecb1479…)`; si no, AVISO y mirar qué hace.
- **El esquema de partida de producción**: tiene que salir `= antes` en cada migración (si no, aborta y es de database-specialist), `pendientes … = las declaradas` y, en el lote 4, privilegios por defecto que incluyan `INSERT,SELECT`.
- **Que el FPM vea el código y la caché de rutas nuevos sin recargarse**: la línea de OPcache del paso 0 y, en el lote 4, la GET nueva en 401 después.
- **Las banderas en el entorno real**: `banderas declaradas, hoy en la caché` tiene que dar `ausente` en las nuevas; si una ya existe con otro valor, el paso 4 lo verá y volverá atrás.
- **Los tiempos reales** de cerrojo y de `queue:restart` con trabajos largos (se esperan 90 s al pid nuevo; si no, AVISO).

## 10. Riesgos y deuda

- **El envoltorio instalado vacía la caché de configuración antes de migrar** y, si la migración falla, sale sin regenerarla (comprobado con el envoltorio versionado [C]): el guion la repone del respaldo. Además, su `config:cache` se hace con el umask de sudo y el `chmod 600` lo da root después: hay un instante en que la caché con secretos puede quedar legible [S]. La propuesta del H7 (`ironbody-migrate-propuesta.sh`) lo corrige y sigue sin instalar: para security.
- **Una comprobación posterior ruidosa** (un `production.ERROR` ajeno en esos segundos) dispara la vuelta atrás: es lo pedido, y la vuelta es segura, pero hay que mirar el motivo antes de repetir.
- **`shellcheck` sin pasar**: no está instalado (ni imagen de Docker) y no lo he descargado.
- **Ningún secreto expuesto ni que rotar.** Los registros del ensayo no contienen la clave de la aplicación ni la del dueño del banco [C].
