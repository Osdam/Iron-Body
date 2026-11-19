# Despliegue del backend de «regenerar antes de sustituir» (cierre V2, apartado 4; plan §9, paso 1)

**Fecha:** 19-11-2026. **Autor:** `devops`. **Estado: PREPARADO, NO EJECUTADO.** No se ha ejecutado nada contra producción: ni `ssh`, ni n8n, ni commit, ni push. El propietario todavía no ha autorizado el despliegue (gate 4-H).
**Guion:** `ops/deploy-a4-regenerar-backend.sh`. Se deriva de `ops/hotfix-backend.sh`, que se niega a desplegar si cambia algo de `backend/routes/*` (`:77`).
**Plan:** `plans/informes-motores/plan-arquitecto-a4-regenerar.md`, §9 (despliegue y vuelta atrás) y §5.5 (vuelta atrás de n8n).
**Marcas:** **[C]** comprobado (ensayo, lectura del código o ejecución local) · **[L]** leído en un documento del repositorio · **[S]** supuesto, sin verificar.

---

## 0. En cinco líneas

1. El cambio es **solo código**, pero **añade una ruta**: `POST /api/internal/marketing/ai/check`. Producción tiene las rutas en caché (`route:cache`), así que la ruta no existe hasta regenerar esa caché. Por eso no sirve `hotfix-backend.sh`, que además se niega a desplegar rutas.
2. **Antes de tocar nada**, el guion comprueba todo esto:
   - producción está en el commit previo y su árbol está limpio;
   - `origin/main` es exactamente el commit nuevo y desciende del previo;
   - la lista de ficheros coincide **exactamente** con el diff;
   - no hay migraciones, `config/`, composer ni `bootstrap/`;
   - de `routes/` solo cambia `marketing.php`, y su única línea de código nueva es la de `ai/check`;
   - las cachés son de `www-data` y están en 600;
   - los 14 workers están sanos;
   - sin credenciales, `ai/decide` da 401 y `ai/check` da 404.
3. Después hace el respaldo, avanza el código (`merge --ff-only` con umask 022) y regenera `route:cache` **como `www-data`** con umask 077. Con `route:list` comprueba las cuatro puertas internas de ULTRON y su middleware, y que la **única** ruta nueva es la de `ai/check`. **Si eso falla, vuelve atrás solo** (código 4).
4. Luego lanza `queue:restart` y espera a que cada worker tenga un pid nuevo. Pasado un margen, comprueba que los 14 siguen en RUNNING, para no caer en la trampa del BACKOFF. Por último verifica que `ai/check` sin credenciales contesta **401** sin usar ningún secreto, y que no hay líneas `production.ERROR` nuevas.
5. Lo probado **[C]**:
   - **14 casos de git** en un banco desechable: uno pasa y 13 se niegan;
   - **10 casos** del disparador de la vuelta atrás, sobre `route:list --json` reales de Laravel 12.58;
   - **10 recorridos** de los pasos 1–5 y de la vuelta atrás, con dobles de sudo, supervisor, curl y php.

   Lo que **no** se puede probar sin ejecutarlo de verdad está en el §10.

## 1. Entorno y punto de partida

| Qué | Valor | Fuente |
|---|---|---|
| Entorno | **Producción** (VPS `ironbody-vps`): repositorio en `/var/www/api`, la API en `/var/www/api/backend` | [L] guiones de `ops/` |
| Pila | Laravel 12.58 (`composer.lock`) y PHP 8.3, detrás de PHP-FPM y nginx. La aplicación corre como `www-data` | [C] `composer.lock`; [L] |
| Cachés | `bootstrap/cache/config.php` y `routes-v7.php`, de `www-data` y en 600. El guion lo exige antes de empezar | [L] `deploy-h9-backend-cierre.sh:691-698` |
| Workers | 14 programas de supervisor (13, más `ironbody-voice`), en colas propias. Producción no consume `default` | [L] encargo; `deploy-m1-backend.sh:274` |
| Commit que sirve producción | `e861e2c8`. El guion lo vuelve a comprobar; no se fía de este documento | [L] encargo |
| `origin/main` hoy | `bc5167cb` (documentación encima de `e861e2c8`) | [C] |
| La rama | `feat/ultron-studio-h9` en `d63fbd50`, con uno o dos commits más por llegar. **Para desplegar tiene que estar integrada y subida a `origin/main`** | [C] |
| Respaldos | `/var/backups/ironbody/ultron-studio-a4-regenerar-<fecha UTC>` (la carpeta de siempre) | [L] `RUNBOOK_M1.md:38` |
| n8n | **No se toca.** Sigue publicado S1. Este es el paso 1 del §9; S2 es el paso 2, con su propia orden | [L] plan §9 |

## 2. Qué cambia y qué no

Diff real `e861e2c8 → d63fbd50` [C]: 18 commits y 173 ficheros, repartidos así:

- 20 de `backend/app`: el motor de ULTRON y `UltronController`;
- 1 de `backend/routes`: `marketing.php`, que añade `Route::post('ai/check', …)` y cambia comentarios;
- 38 de `backend/tests`;
- 108 de `backend/docs`;
- 6 de `n8n/flujos/ultron-studio-s1/`: son artefactos. En el servidor quedan como ficheros y no se ejecutan.

**No cambian:**

- **Ni migraciones, ni `config/`, ni composer, ni `bootstrap/`.** El guion se niega si aparecen.
- **La caché de configuración.** No se regenera, porque `config/` no cambia; se respalda y se comprueba que sigue igual.
- **nginx, FPM, supervisor, el crontab, la base de datos y n8n.** De supervisor solo se usa `queue:restart`, que es una señal en la caché y no un trabajo de cola. Por eso no depende de la cola `default`, que producción no consume.
- **Las constantes de llamadas y de envíos.** Las dos siguen cerradas y sus ficheros no cambian.

**Rutas, medido en local** con `route:list --json` sobre exportaciones de los dos commits, sin `.env` [C]:

- Total: 1 045 → 1 046.
- Única diferencia: `+POST api/internal/marketing/ai/check`.
- Las cuatro puertas tienen `["api", "automation.internal", "throttle:internal-marketing-ai"]`.
- La acción de `check` es `UltronController@check`.

En producción el total puede ser otro. El guion compara con la lista de producción de antes, no con estos números.

## 3. Impacto

| Qué | Detalle |
|---|---|
| **Interrupción** | **Ninguna planificada en la API.** No se recarga FPM ni nginx. Hay una ventana de segundos entre el `merge` y `route:cache` con el código nuevo y la caché vieja: `ai/check` da 404, pero con S1 nadie la llama, y `decide`, `commit` e `incidents` apuntan a métodos que existen en los dos commits. Los workers se reinician con `queue:restart`: cada uno termina antes su trabajo en curso |
| **Quién lo nota** | **Los clientes de Legacy no.** El plan, §9 (2), dice que el backend nuevo funciona con S1 [L]. **La canaria sí:** lo publicado con el compilador 3 cae a `legacy · compiler_changed`, también la versión 2 de la canaria, hasta publicar una versión nueva [L: Enmienda A42-12 del H5; plan §9, paso 1, «punto de no retorno ya conocido (A4-2)»]. Es lo esperado |
| **Irreversible** | (1) Lo que el código nuevo escriba mientras está desplegado (`metadata.regeneration` y la traza) **se queda** tras volver atrás. Es metadata aditiva (plan §9). (2) No sé si volver al código del compilador 3 devuelve la versión 2 de la canaria a «aplicada» sin publicar nada [S, sin verificar]: el plan lo llama punto de no retorno y así lo trato. (3) Nada en el esquema: no hay migraciones |
| **Fuera de este paso** | La copia S2 de n8n, la versión 3 y la tercera prueba real son los pasos 2 a 4 del §9, cada uno con su orden |

## 4. Orden exacta desde el Mac

**Requisitos antes de empezar:**

- La **orden del propietario** (gate 4-H) y la del lead, en la conversación de ese momento.
- La rama integrada y **subida** a `origin/main`, con este guion dentro.
- Nadie más desplegando: el guion se niega si producción o `origin/main` se mueven.
- Elegir un momento sin turnos en curso de la canaria.

Las órdenes se lanzan desde la raíz del repositorio, en un árbol limpio.

```bash
# 1. Qué viaja (solo lectura)
git fetch -q origin
P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=8 HEAD')   # hoy e861e2c8
N=$(git rev-parse --short=8 origin/main)                                # la punta con la rama ya integrada
git merge-base --is-ancestor "$P" "$N" && echo "avance rápido"
git log --oneline "$P..$N"
git diff --name-only --no-renames "$P" "$N" | grep -vE '^backend/(app|tests|docs)/|^n8n/'   # tiene que salir SOLO backend/routes/marketing.php
git diff "$P" "$N" -- backend/routes/                                                      # una línea de código: la ruta ai/check

# 2. Subir el guion DEL COMMIT QUE SE DESPLIEGA y la lista; las dos huellas tienen que coincidir con las del Mac
git show "$N:backend/docs/ultron-studio/ops/deploy-a4-regenerar-backend.sh" | shasum -a 256
git show "$N:backend/docs/ultron-studio/ops/deploy-a4-regenerar-backend.sh" \
  | ssh ironbody-vps 'umask 077; cat > /root/deploy-a4-regenerar-backend.sh && chmod 700 /root/deploy-a4-regenerar-backend.sh && sha256sum /root/deploy-a4-regenerar-backend.sh'
git diff --name-only --no-renames "$P" "$N" | shasum -a 256
git diff --name-only --no-renames "$P" "$N" \
  | ssh ironbody-vps 'umask 077; cat > /root/a4-regenerar-lista.txt && sha256sum /root/a4-regenerar-lista.txt && wc -l < /root/a4-regenerar-lista.txt'

# 3. Ensayo en el servidor: solo lecturas y git fetch; no toca nada
ssh ironbody-vps "bash /root/deploy-a4-regenerar-backend.sh $P $N /root/a4-regenerar-lista.txt --solo-comprobar"
#    → «COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)»

# 4. El despliegue, CON LA ORDEN
ssh ironbody-vps "bash /root/deploy-a4-regenerar-backend.sh $P $N /root/a4-regenerar-lista.txt"
#    → «CORRECCIÓN CON RUTA NUEVA DESPLEGADA SIN DESVÍOS» (código 0)
```

- **La lista se saca con `--no-renames`.** Así un renombrado aparece con el nombre viejo y con el nuevo. El guion compara con `--no-renames`, de modo que una lista sacada sin esa opción no cuadraría si hubiera renombrados.
- **Si `origin/main` avanza** entre el paso 1 y el 4, el guion se niega. Hay que volver al paso 1 con la punta nueva.
- **No dejar que se corte la sesión entre el paso 2 y el 4 del guion.** Si se corta, el §7 explica cómo saber dónde quedó y cómo volver.

## 5. Qué se espera ver y la verificación de después

| Paso del guion | Esperado |
|---|---|
| 0 | `producción <P> limpia · origin/main <N> · … ficheros = la lista` · ni migraciones ni config · solo la línea de `ai/check` · las constantes cerradas · `workers 14 RUNNING (esperados 14) · 0 BACKOFF/FATAL` · cachés `www-data 600` · `route:list: <n> rutas · [decide=ok check=ausente commit=ok incidents=ok]` · `ai/decide → 401 · ai/check → 404` · la línea de OPcache |
| 1 | El respaldo, 700 de root, con ficheros 600 de root. La caché de configuración **no se imprime**: lleva secretos |
| 2 | `HEAD=<N>` · `www-data lee los … ficheros del merge` · los de `app` y `routes` en `root 644` |
| 3 | `caché de rutas: www-data 600` · `<n> → <n+1> rutas · puertas [decide=ok check=ok commit=ok incidents=ok] · middleware [api,automation.internal,throttle:internal-marketing-ai] · diferencia [+POST api/internal/marketing/ai/check]` |
| 4 | `antes [14 0] → [14 0] y [14 0]` · `con pid nuevo 14 de 14` · `ironbody-billing-worker active → active` |
| 5 | `POST ai/check 404 → 401`, y `decide`, `commit`, `incidents` y `admin/voice/status` en 401 |
| 6 | `production.ERROR nuevos …: 0`, después las órdenes de la vuelta atrás y `CORRECCIÓN CON RUTA NUEVA DESPLEGADA SIN DESVÍOS` |

**Vigilancia de 15 minutos** (solo lectura, en el servidor). Ninguna de estas órdenes imprime IP ni secretos:

```bash
supervisorctl status | awk '{print $2}' | sort | uniq -c                        # 14 RUNNING, ni BACKOFF ni FATAL
f=$(ls -t /var/www/api/backend/storage/logs/laravel*.log | head -1); grep -c 'production.ERROR' "$f"   # no sube
tail -n 5000 /var/log/nginx/access.log | grep -E '"POST /api/internal/marketing/ai/(decide|commit|check|ultron/incidents)' \
  | awk '{print $7, $9}' | sort | uniq -c                                       # decide/commit de n8n como antes; ningún 404 ni 5xx
cd /var/www/api/backend && sudo -u www-data php artisan ultron:prompt-trace --last=3   # el primer turno canario: flowRev S1; la versión 2 en legacy · compiler_changed (esperado)
```

## 6. Si el guion no termina en verde

| Salida | Qué significa | Qué hacer |
|---|---|---|
| **1** `ABORTA (no se ha tocado nada)` | Ha fallado una comprobación previa, o el `merge` falló sin tocar nada | Leer el motivo y corregir la causa. Nunca se salta una comprobación |
| **4** `VUELTA ATRÁS AUTOMÁTICA HECHA` | El paso 2 o el 3 falló: el `merge`, la lectura por `www-data`, `route:cache`, el dueño o el modo de la caché, o `route:list`. Producción está otra vez en `<P>`, con la caché de rutas de antes y los workers reiniciados | Diagnosticar con el registro del respaldo y no repetir sin entender la causa |
| **5** `PARAR` | La vuelta atrás no quedó limpia, o el guion salió de forma inesperada a medias | §7, a mano |
| **3**, workers en BACKOFF o FATAL | Los workers no arrancan con lo nuevo. Probablemente sea una caché ilegible, como en la trampa del chmod | **Vuelta atrás ya** (§7, c). Los programas en FATAL no los relanza supervisor solo: `supervisorctl start <programa>`, con la decisión del lead |
| **3**, `ai/check sigue en 404` | `route:list` tiene la ruta, pero el FPM no ve la caché nueva. Puede ser un `php_admin_value` del pool que el paso 0 no lee, o un `revalidate_freq` alto | Decide el lead: (a) **recargar el FPM** (`systemctl reload php8.3-fpm`, que recarga con suavidad), con orden aparte, porque recargar un servicio productivo es otra autorización; o (b) **la vuelta atrás**. Si la revalidación está apagada, la vuelta atrás también necesita esa recarga para que el FPM la vea |
| **3**, pid sin cambiar en algún worker | Un trabajo largo todavía no ha terminado | Volver a mirar `supervisorctl status` pasados unos minutos |
| **3**, `production.ERROR` nuevos | Hay errores desde el inicio | Leerlos en el log, sin pegarlos fuera si llevan datos, y decidir. Si son del código nuevo: vuelta atrás y diagnóstico |

Si los `.ini` del FPM declaran `opcache.validate_timestamps` apagado, el **paso 0 se niega** antes de tocar nada. Sin recargar el FPM, el código nuevo no se vería, y esa recarga es otra orden.

## 7. Vuelta atrás, por capas (plan §9), sin migraciones

El orden es **versión → n8n → backend**. Cada capa es independiente, y con S2 publicado sobre el backend anterior S2 se comporta como S1 [L: plan §9 c].

**(a) La versión.** «Revertir» desde el Studio devuelve la canaria a Legacy. En este paso 1 todavía no hay versión 3 que revertir.

**(b) n8n.** Solo hace falta si ya se publicó S2 (paso 2 del §9):

- Despublicar S2 y publicar la copia S1 `QSklqHlsDIxucRfk`, que queda intacta como reserva.
- Comprobación: el siguiente turno canario sale con `flowRev S1` en `ultron:prompt-trace`.
- **Nunca reimportando un JSON**, porque no lleva los secretos de las credenciales [L: plan §5.5].

**(c) El backend.** Esta es la orden que imprime el guion al final, con el respaldo de ese día:

```bash
bash /root/deploy-a4-regenerar-backend.sh <P> <N> /root/a4-regenerar-lista.txt --vuelta-atras=/var/backups/ironbody/ultron-studio-a4-regenerar-<fecha UTC>
#    → «VUELTA ATRÁS HECHA: producción en <P>, con la caché de rutas de antes (sin ai/check)» (código 0)
```

Qué hace, por orden:

1. Se niega si producción no está ni en `<N>` ni en `<P>`, porque eso significa que otra sesión desplegó. También se niega si el árbol tiene cambios, o si el respaldo no es 700 de root o no cuadra con su `SHA256SUMS`.
2. Pone **primero la caché de rutas del respaldo**, byte a byte. La escribe `www-data` con umask 077 mediante `cat` y `mv`; root no escribe en `bootstrap/cache`.
3. **Después** ejecuta `git checkout --detach <P>` como root con umask 022. En la ventana intermedia cada ruta apunta a un método que existe en los dos commits. Si la caché del respaldo no se pudo poner, la regenera ahora con `route:cache` como `www-data`, ya con el código previo.
4. Comprueba que `route:list` es **idéntica a la de antes** y no tiene `ai/check`.
5. Lanza `queue:restart` y espera a los 14 workers con pid nuevo.
6. Comprueba que, sin credenciales, `ai/check` da 404 y `ai/decide` da 401.

A mano, sin el guion, es lo mismo (el guion lo imprime con los commits enteros):

```bash
[ "$(git -C /var/www/api rev-parse HEAD)" = <N entero> ] && cd /var/www/api/backend \
  && sudo -u www-data sh -c 'umask 077; cat > bootstrap/cache/routes-v7.php.a4-vuelta && mv -f bootstrap/cache/routes-v7.php.a4-vuelta bootstrap/cache/routes-v7.php' < $BK/bootstrap-cache/routes-v7.php \
  && ( cd /var/www/api && umask 022 && git checkout -q --detach <P entero> ) \
  && sudo -u www-data php artisan queue:restart
stat -c '%U %a' bootstrap/cache/routes-v7.php     # www-data 600
```

**Límites de la vuelta atrás:**

- **Volver el código no es volver los datos.** La metadata que escribió el código nuevo se queda: es aditiva y el código anterior no la lee.
- **Nunca `migrate:rollback`.** Además, aquí no hay migraciones que deshacer.
- **HEAD queda suelto en `<P>`** mientras la rama `main` del servidor sigue en `<N>`. Es el mismo estado que deja `hotfix-backend.sh`. El siguiente despliegue avanza desde HEAD con `merge --ff-only`.
- **La caché de configuración no se toca.** Si alguna vez hiciera falta la del respaldo, va igual, como `www-data` y con `cat > …tmp && mv`, desde `$BK/bootstrap-cache/config.php`. Ese fichero lleva secretos: no se imprime ni se copia fuera.
- **El efecto del compilador sobre la versión 2 de la canaria** está en el §3.

**Si el guion se cortó a medias** (sesión caída):

```bash
git -C /var/www/api rev-parse --short=8 HEAD                         # ¿<P> o <N>?
ls -td /var/backups/ironbody/ultron-studio-a4-regenerar-* | head -1  # el respaldo de hoy; su registro.log dice hasta dónde llegó
```

Con HEAD en `<N>` o en `<P>`, la vuelta atrás de (c) sirve igual: es idempotente sobre `<P>`. Si el corte fue antes de que existiera el respaldo, no se tocó nada.

## 8. Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | La caché la escribe root, o queda en 600 sin `chown`, y los workers caen en BACKOFF al siguiente arranque | `route:cache` corre como `www-data` con umask 077 y se comprueba fichero normal de `www-data` en 600. El chmod, si hace falta, también como `www-data`. Si no cuadra, vuelta atrás automática **antes** de reiniciar los workers. Después del reinicio: pid nuevo y dos lecturas pasado un margen |
| R2 | Con umask 077, el `merge` deja los PHP en 600 de root y FPM no los lee | El `merge` corre en un subshell con umask 022; el 077 solo se aplica al respaldo y a la caché. Además se comprueba que `www-data` lee cada fichero del merge |
| R3 | Otra sesión despliega desde `main` en medio | Se comprueba `HEAD = <P>` y `origin/main = <N>` antes de tocar nada. La vuelta atrás se niega si HEAD es un tercer commit |
| R4 | El FPM no revalida y no ve la ruta ni el código nuevos | El paso 0 se niega si los `.ini` lo declaran. El paso 5 lo detecta (404) y el §6 dice qué hacer. El override del pool no se lee [S] |
| R5 | `queue:restart` encuentra un trabajo largo | Se esperan 90 s a que cambien los pid; si no, desvío y volver a mirar |
| R6 | Corte de la sesión a medias | Hay un `trap` que avisa (código 5) y queda el respaldo con su `registro.log`. La vuelta atrás es idempotente (§7) |
| R7 | **El punto de no retorno ya conocido (A4-2)**: la versión 2 de la canaria cae a Legacy | Aceptado en el plan. Se recupera publicando la versión 3 (paso 3 del §9, gate del propietario) |
| R8 | Producción ya no tiene las rutas en caché: la memoria operativa de octubre menciona un `deploy-produccion.sh` que hacía `route:clear` [L] | El paso 0 se niega si no existe `routes-v7.php`. Entonces lo decide el lead: no se despliega a ciegas |

## 9. El ensayo en seco (local, sin producción) [C]

- **Herramientas.** `bash -n` (bash 3.2.57 del Mac): **OK**. `shellcheck` **no está disponible**: no hay binario, ni imagen de Docker, ni caché de npx. No lo he descargado.
- **El banco.** Todo vive en el scratchpad de la sesión, que se borra; el repositorio del proyecto no recibe ningún commit, ref ni fetch:
  - `origen.git` es un repositorio desnudo **desechable**. Hace de GitHub y lee los objetos del repositorio real por *alternates*, en solo lectura. Su `main` es `d63fbd50`.
  - `prod` es un clon `--shared` de `origen.git` con HEAD en `e861e2c8`, y hace de `/var/www/api`.
  - Los casos simulados son **commits sintéticos que solo existen en `origen.git`**. Se crearon por plumbing (`hash-object`, `update-index`, `write-tree`, `commit-tree`) encima de `d63fbd50`.
  - La lista se sacó con `git diff --name-only --no-renames e861e2c8 d63fbd50` (173 líneas).
- **Abreviaturas en las salidas.** Las rutas largas del scratchpad se han sustituido por `<banco>` y `<scratchpad>`, y la lista de 18 commits se ha recortado. Donde pone «…» dentro de una salida, falta un trozo que repite lo de la fila de arriba.

### 9.1 Las comprobaciones previas de git (`--ensayo=<banco>`): 1 en verde y 13 negativas

**A. El caso real, `e861e2c8 → d63fbd50`, con su lista (código 0):**

```text
== deploy-a4-regenerar-backend.sh · ENSAYO EN SECO · 2026-11-19T17:25:57Z · banco <banco>/prod · PREVIO=e861e2c8 · NUEVO=d63fbd50 · lista <banco>/lista-ok.txt
   (solo las comprobaciones de git del paso 0; las del servidor —workers, cachés, route:list, HTTP— no se ensayan aquí)
0. comprobaciones previas (solo lectura y git fetch; si algo falla, no se toca nada):
   producción e861e2c8 limpia · origin/main d63fbd50 · 18 commit(s) · 173 ficheros = la lista: 20 de backend/app · 1 de backend/routes (marketing.php) · 38 de backend/tests · 108 de backend/docs · 6 otros
   ni migraciones, ni backend/config, ni composer, ni bootstrap; de backend/routes solo marketing.php: + «Route::post('ai/check', [UltronController::class, 'check']);» y, aparte, solo comentarios
   las cuatro puertas declaradas una vez en el marketing.php nuevo (decide, check, commit, ultron/incidents)
   constante de llamadas (VoiceCallPolicy): cerrada · de envíos (OutreachSendGate): cerrada · los dos ficheros sin cambios
      d63fbd50 feat(ultron): regenerar antes de sustituir, ola 4: la puerta de solo lectura `ai/check`, el presupuesto de ree
      ee8e0df0 feat(ultron): regenerar antes de sustituir, ola 3: la lista de planes la redacta el modelo y el servidor pone
      2d3d02ba feat(ultron): regenerar antes de sustituir, olas 1 y 2: las piezas puras de la reescritura guiada y el parche
      … (15 commits más, de documentación y del apartado 4, hasta 82dddf78)
ENSAYO: COMPROBACIONES PREVIAS DE GIT EN VERDE (no se ha tocado nada)
→ código de salida 0
```

**Las negativas.** Todas terminan sin tocar nada, con código 1. Se muestra la línea que da el motivo; la cabecera es la misma que en A.

| Caso | Orden (sobre `--ensayo=<banco>/prod`) | Salida |
|---|---|---|
| **B. Commit previo equivocado** | `bc5167cb d63fbd50 lista-ok.txt` | `ABORTA (no se ha tocado nada): producción no está en bc5167cb (está en e861e2c8): si se desplegó otra cosa, revisar qué entró y rehacer la lista` |
| **C1. Lista que no cuadra (falta uno)** | `e861e2c8 d63fbd50 lista-falta-una.txt` | `ABORTA (no se ha tocado nada): la lista no coincide EXACTAMENTE con git diff --name-only --no-renames e861e2c8 d63fbd50: 1 en el diff que la lista no trae [backend/app/Services/Marketing/Ultron/UltronDraftCheck.php] · 0 en la lista que el diff no trae (o repetidas) []` |
| **C2. Lista que no cuadra (sobra uno)** | `e861e2c8 d63fbd50 lista-sobra-una.txt` | `ABORTA (no se ha tocado nada): la lista no coincide EXACTAMENTE con … : 0 en el diff que la lista no trae [] · 1 en la lista que el diff no trae (o repetidas) [backend/app/Services/Marketing/Ultron/Inventado.php]` |
| **D. Migración simulada** (lista = su diff exacto) | `e861e2c8 db3aa7b8 lista-D.txt` | `ABORTA (no se ha tocado nada): el diff trae backend/database/migrations/2026_11_19_000001_ensayo_simulada.php: migraciones y base de datos no van por este guion (y nunca migrate:rollback)` |
| **E. `config/`** | `e861e2c8 411f385d lista-E.txt` | `ABORTA (no se ha tocado nada): el diff trae backend/config/marketing.php: la configuración necesita config:cache y su propia vía` |
| **F. Una ruta distinta de `marketing.php`** | `e861e2c8 e502aa09 lista-F.txt` | `ABORTA (no se ha tocado nada): el diff trae backend/routes/api.php: de backend/routes/ solo puede cambiar marketing.php (la ruta ai/check)` |
| G. `marketing.php` con otra ruta además de `ai/check` | `e861e2c8 3232d2b0 lista-G.txt` | `ABORTA (no se ha tocado nada): en backend/routes/marketing.php la única línea de código nueva tiene que ser «Route::post('ai/check', [UltronController::class, 'check']);»; trae: [Route::post('ai/check', …);\|Route::post('ai/otra', [UltronController::class, 'check']);]` |
| H. `bootstrap/` | `e861e2c8 23b2f02b lista-H.txt` | `ABORTA (no se ha tocado nada): el diff trae backend/bootstrap/app.php: el arranque de la aplicación (bootstrap/) no va por este guion` |
| I. `VoiceCallPolicy.php` | `e861e2c8 3326f43e lista-I.txt` | `ABORTA (no se ha tocado nada): el diff trae backend/app/Services/Voice/VoiceCallPolicy.php: las constantes de llamadas y de envíos no se tocan por este guion` |
| J. Un commit `voice(calls-open):` | `e861e2c8 d404e4a6 lista-J.txt` | ``ABORTA (no se ha tocado nada): el commit d404e4a6 abre las llamadas (`voice(calls-open):`): solo deploy-h8-backend-fase1.sh --fase=4a`` |
| K. `origin/main` avanzó | `e861e2c8 d63fbd50 lista-ok.txt`, con `main` = `967fb6c6` | `ABORTA (no se ha tocado nada): origin/main no es d63fbd50 (es 967fb6c6): si main avanzó, revisar lo nuevo y rehacer la lista con la punta` |
| L. Árbol desplegado sucio | `e861e2c8 d63fbd50 lista-ok.txt`, con `backend/routes/web.php` tocado en `prod` | `ABORTA (no se ha tocado nada): el árbol desplegado tiene cambios sin confirmar` |
| M. El nuevo no desciende del previo | `e861e2c8 7823b90f lista-ok.txt` (el padre de `e861e2c8`) | `ABORTA (no se ha tocado nada): 7823b90f no desciende de e861e2c8: no es un avance rápido` |

Al terminar, el banco estaba como al principio: HEAD `e861e2c8`, `main` de `origen.git` en `d63fbd50` y ningún cambio en `prod`.

### 9.2 El disparador de la vuelta atrás automática (`--ensayo-rutas`)

**Las entradas:**

- `route:list --json` **reales** de Laravel 12.58, sacadas de exportaciones de `e861e2c8` y de `d63fbd50` (`git archive` más una copia de `vendor`, sin `.env`).
- Mutaciones de la de `d63fbd50`, hechas con Python en el scratchpad.

Se usan las mismas funciones (`puertas`, `rutas_canon`, `verifica_rutas`) que el paso 3 y la vuelta atrás.

| Caso | Salida (resumen literal) | Código |
|---|---|---|
| R1. `e861e2c8` → `d63fbd50` | `1045 → 1046 rutas · puertas [decide=ok check=ok commit=ok incidents=ok] · middleware [api,automation.internal,throttle:internal-marketing-ai] · diferencia [+POST api/internal/marketing/ai/check]` → `CUADRA (el despliegue seguiría al paso 4)` | 0 |
| R2. `route:cache` no recogió la ruta (después = antes) | `1045 → 1045 · [… check=ausente …] · diferencia [ninguna]` → `NO CUADRA → en producción, VUELTA ATRÁS AUTOMÁTICA (código 4)` | 4 |
| R3. `ai/check` sin el limitador | `check=mal:sin_limitador,middleware_distinto_de_decide` → NO CUADRA | 4 |
| R4. Falta `ai/commit` | `commit=ausente` · `diferencia [+POST …/ai/check\|-POST …/ai/commit]` → NO CUADRA | 4 |
| R5. Una ruta de más | `1045 → 1047` · `diferencia [+POST …/ai/check\|+POST …/ai/otra]` → NO CUADRA | 4 |
| R6. `ai/check` apunta a otro método | `check=mal:accion` → NO CUADRA | 4 |
| R7. `route:list` ilegible | `la route:list de después no se puede leer` → NO CUADRA | 4 |
| R8. `ai/check` ya presente antes | `el paso 0 ABORTARÍA: route:list de antes [decide=ok check=ok commit=ok incidents=ok], esperado [decide=ok check=ausente commit=ok incidents=ok] (no se tocaría nada)` | 1 |
| R9. Vuelta atrás: después = la de antes | `1045 → 1045 · [… check=ausente …] · diferencia [ninguna]` → `CUADRA (la vuelta atrás daría las rutas de antes)` | 0 |
| R10. Vuelta atrás con la caché todavía nueva | `1045 → 1046 · diferencia [+POST …/ai/check]` → `NO CUADRA → en producción, la vuelta atrás acabaría en PARAR (código 5)` | 5 |

### 9.3 Simulación con dobles de los pasos 1–5 y de la vuelta atrás

**Esto no prueba el servidor.** Recorre con bash real y `set -euo pipefail` las ramas que el ensayo de git no alcanza, incluidas las que solo corren si algo falla.

**Cómo está montada.** Es una copia del guion en la que solo cambian `APP`, `BK_RAIZ` y `GUION`. Los dobles, en `bin/`, son estos:

- `sudo -u` ejecuta la orden como el usuario actual.
- `supervisorctl` da 13 workers más `ironbody-voice`, y el pid cambia con cada `queue:restart`.
- `curl` devuelve `ai/check` en 404 o 401 según la caché.
- `php artisan` implementa `route:list`, `route:cache` y `queue:restart` sobre cachés falsas que contienen `e861` o `d63`.
- `stat -c`, `install`, `find`, `id`, `systemctl` y `sleep` también son falsos.

El «servidor» es otro clon `--shared` del banco.

| Caso | Final y código | Estado después |
|---|---|---|
| S1. `--solo-comprobar` | `COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)` · 0 | HEAD `e861e2c8`, caché `e861`, ningún respaldo |
| S2. Despliegue sin fallos | `3. … 1045 → 1046 rutas · … diferencia [+POST api/internal/marketing/ai/check]` · `4. … [14 0] y [14 0] · con pid nuevo 14 de 14` · `5. POST ai/check 404 → 401` · `CORRECCIÓN CON RUTA NUEVA DESPLEGADA SIN DESVÍOS` · 0 | HEAD `d63fbd50`, caché `d63` (600). El respaldo queda 700 con ficheros 600 y `SHA256SUMS` OK |
| S3. `--vuelta-atras=<respaldo>` | `a. caché de rutas: la del respaldo, byte a byte` · `b. código: HEAD=e861e2c8` · `c. … diferencia [ninguna] (la de antes)` · `e. ai/check → 404 · ai/decide → 401` · `VUELTA ATRÁS HECHA` · 0 | HEAD `e861e2c8`, caché `e861` |
| S4. `route:cache` no recoge la ruta | `✗ route:list no cuadra … diferencia [ninguna]` · `VUELTA ATRÁS AUTOMÁTICA HECHA` · 4 | HEAD `e861e2c8`, caché `e861` |
| S5. Igual que S4, y además falla la restauración de la caché | `a. la caché del respaldo no se pudo restaurar` · `b'. caché de rutas regenerada con el código de e861e2c8` · `VUELTA ATRÁS AUTOMÁTICA HECHA` · 4 | HEAD `e861e2c8`, caché `e861` |
| S6. La caché nueva queda de root | `✗ bootstrap/cache/routes-v7.php es de root, no de www-data: los workers quedarían en BACKOFF …` · `VUELTA ATRÁS AUTOMÁTICA HECHA` · 4 | HEAD `e861e2c8`, caché `e861` |
| S7. Un worker en BACKOFF tras `queue:restart` | `4. … [13 1] y [13 1]` · `✗ … con BACKOFF/FATAL, la vuelta atrás YA` · `CON 2 DESVÍOS` · 3 | HEAD `d63fbd50`: la decisión es del lead (§6) |
| S8. El FPM no ve la caché | `5. POST ai/check 404 → 404` · `✗ ai/check sigue en 404 aunque route:list la tiene …` · `CON 1 DESVÍOS` · 3 | HEAD `d63fbd50` (§6) |
| S9. `--vuelta-atras` con un respaldo inexistente | `… no existe o no es un directorio 700 de root; no se ha tocado nada` · 2 | Sin cambios |
| S10. `--vuelta-atras` con producción en un tercer commit | `✗ producción está en bc5167cb, ni en el commit nuevo ni en el previo: otra sesión desplegó; no se toca` · `PARAR` · 5 | Sin cambios |

Después de esto añadí al paso 0 la negativa si los `.ini` del FPM apagan la revalidación de OPcache. Repetí `bash -n`, el caso A del §9.1 y la simulación entera (S1–S10), con el mismo resultado. La condición nueva la probé aparte con cinco valores: sin línea → sigue; `revalidate_freq=2` → sigue; `validate_timestamps=1` → sigue; `validate_timestamps=0` → se niega; `validate_timestamps=Off` → se niega.

## 10. Lo que NO se puede comprobar hasta ejecutarlo de verdad

- **El servidor real:**
  - el bash del servidor (el ensayo usó bash 3.2);
  - GNU `stat` e `install`, `sudo -u www-data` y `supervisorctl`, con su formato real;
  - `python3` en el servidor, que ya usan los guiones anteriores;
  - el `git fetch` contra GitHub.
- **Que `route:cache` y `route:list` de producción den lo mismo que en local.** El total de rutas puede diferir. Lo que se exige es la diferencia exacta respecto a la `route:list` de producción de antes, y eso solo se ve allí.
- **Que el FPM vea la caché nueva sin recargarse.** El ajuste del pool está sin comprobar desde el H6 (C-8 y N-4 a) [L]; el paso 5 lo delata.
- **El comportamiento real de los 14 workers con `queue:restart`:** cuánto tardan los trabajos largos y si algún programa queda en BACKOFF.
- **Que, sin credenciales, `ai/decide` dé 401 y `ai/check` 404** desde el propio servidor contra `https://api.ironbodyneiva.cloud` (la URL de los guiones anteriores, que ya han dado 401 así). No uso `--resolve … 127.0.0.1` porque no sé si nginx escucha esa vhost en el loopback.
- **El efecto en la canaria:** que la versión 2 caiga a `legacy · compiler_changed`, y qué hace la vuelta atrás con ella.
- **Que n8n S1 siga funcionando contra el backend nuevo** con turnos reales (plan §9 (2) [L]).

## 11. Pendiente de autorización (no ejecutado)

Las cuatro órdenes del §4 (pasos 2 a 4) y, si hiciera falta, la del §7 (c).

**Lo que hace cada una:**

- El paso 2 escribe dos ficheros en `/root`.
- El paso 3 solo lee, y hace `git fetch`.
- El paso 4 es **el despliegue a producción**: cambia el código, regenera la caché de rutas y reinicia los workers con `queue:restart`.
- La recarga del FPM del §6 sería **otra orden**.

**Lo que tiene que ocurrir antes:**

- El propietario aprueba en el gate 4-H.
- Lo que tenga abierto el `reviewer` sobre este guion queda resuelto.
- La rama se integra y se sube a `origin/main`. Ese commit y ese push son del lead, con orden.

## 12. Deuda y señalado

- **`shellcheck` sin pasar,** porque no está instalado aquí. Conviene pasarlo antes de usar el guion.
- **El ajuste `opcache.validate_timestamps` del pool de FPM sigue sin anotarse** en el runbook. Es pendiente de `devops` desde el H6 (`ULTRON_STUDIO_CHECKLIST_OPERATIVO.md:174`). El paso 0 solo lee los `.ini`.
- **Discrepancia por aclarar (R8).** La memoria operativa de octubre habla de `backend/scripts/deploy-produccion.sh`, que hacía `route:clear` y recargaba el FPM. Los guiones del H9 y del M1 dan por hecho que las rutas están en caché. El paso 0 de este guion lo comprueba y se niega si no es así.
- **Ningún secreto expuesto ni que rotar en este trabajo.** El respaldo guarda la caché de configuración, que lleva secretos: queda en 600 de root, dentro de un directorio 700, y nunca se imprime.
