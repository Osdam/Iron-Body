#!/bin/bash
# Despliegue del backend de «regenerar antes de sustituir» (cierre V2, apartado 4): SOLO CÓDIGO, pero con UNA RUTA NUEVA,
# POST /api/internal/marketing/ai/check (backend/routes/marketing.php). Plan: plans/informes-motores/plan-arquitecto-a4-regenerar.md
# §9, paso 1 (primero el servidor, después n8n). Procedimiento, riesgos y ensayo en seco: ops/DEPLOY_A4_REGENERAR.md.
# Derivado de hotfix-backend.sh, que RECHAZA backend/routes/* (:77): producción tiene las rutas en caché (route:cache) y una
# ruta nueva no existe hasta regenerarla. Mismas comprobaciones de partida y mismo avance del código (merge --ff-only con
# umask 022), más el respaldo, la caché de rutas regenerada como www-data con umask 077 (el patrón de
# deploy-h9-backend-cierre.sh:691), su comprobación con route:list y la VUELTA ATRÁS AUTOMÁTICA si no cuadra.
#
# Uso (servidor, root; la lista la sube el lead desde el Mac con git diff --name-only --no-renames: DEPLOY_A4_REGENERAR.md §4):
#   bash /root/deploy-a4-regenerar-backend.sh <commit que sirve producción> <commit nuevo = origin/main> <fichero con la lista> [--solo-comprobar]
#   bash /root/deploy-a4-regenerar-backend.sh <commit previo> <commit nuevo> <lista> --vuelta-atras=<directorio del respaldo>
# Ensayo en seco, en local (solo git, solo lectura salvo el fetch del banco; NUNCA contra /var/www/api):
#   bash deploy-a4-regenerar-backend.sh <previo> <nuevo> <lista> --ensayo=<clon de banco cuyo HEAD hace de producción>
#   bash deploy-a4-regenerar-backend.sh --ensayo-rutas <route:list --json de antes> <route:list --json de después> [vuelta]
# Los commits, en hexadecimal (7 a 40 cifras): se resuelven y se comparan enteros.
#
# 0. Comprobaciones previas (si una falla: «ABORTA (no se ha tocado nada)», código 1; con --solo-comprobar termina aquí):
#    producción en el commit previo y con el árbol limpio; tras git fetch, origin/main es EXACTAMENTE el commit nuevo y
#    desciende del previo; ningún commit `outreach(sends-open):` ni `voice(calls-open):`; el diff (--no-renames) coincide
#    EXACTAMENTE con la lista; nada de backend/database/ (migraciones), backend/config/, composer, vendor ni
#    backend/bootstrap/; de backend/routes/ solo marketing.php, que tiene que estar y cuya ÚNICA línea de código nueva es la
#    ruta de ai/check (lo demás que quite o añada, solo comentarios); las cuatro puertas escritas una vez en el marketing.php
#    nuevo; VoiceCallPolicy.php y OutreachSendGate.php sin tocar. En el servidor, además: los workers esperados (14 con la
#    voz) en RUNNING y ninguno en BACKOFF/FATAL; las cachés de rutas y de configuración, ficheros normales de www-data en
#    600; route:list con decide, commit e incidents y SIN check; y, sin credenciales, ai/decide → 401 y ai/check → 404 (la
#    sonda distingue «no existe» de «existe y pide el secreto»).
# 1. Respaldo en /var/backups/ironbody/ultron-studio-a4-regenerar-<fecha UTC> (700 de root; ficheros 600 de root): los dos
#    commits, la lista, las cachés de rutas y de configuración (esta lleva secretos: nunca se imprime), route:list y
#    supervisor de antes, el propio guion y SHA256SUMS. Todo lo que imprime queda además en su registro.log.
# 2. Código: git merge --ff-only <nuevo> como root con umask 022 (con 077 los PHP quedarían en 600 de root y FPM no los
#    leería); HEAD = nuevo; www-data lee cada fichero del merge.
# 3. route:cache como www-data con umask 077 (la caché queda de www-data en 600; root no hace chmod en bootstrap/cache);
#    route:list: las cuatro puertas internas de ULTRON (ai/decide, ai/check, ai/commit, ai/ultron/incidents) una vez cada
#    una, POST, a UltronController y con el middleware de decide (automation.internal y throttle:internal-marketing-ai); y
#    la única diferencia con la de antes, «+POST api/internal/marketing/ai/check». Si algo de 2 o 3 falla: VUELTA ATRÁS
#    AUTOMÁTICA (antes de reiniciar los workers) y código 4; si la vuelta atrás tampoco queda limpia, PARAR, código 5.
# 4. queue:restart; se espera a que cada worker tenga pid nuevo (≤ 90 s) y, pasado un margen, dos lecturas separadas: todos
#    en RUNNING y ninguno en BACKOFF/FATAL (un chmod 600 sin chown sobre una caché sale en el ARRANQUE siguiente).
# 5. Verificación de solo lectura: el commit servido; sin credenciales ni secreto alguno, ai/check → 401 (y decide, commit,
#    incidents y admin/voice/status → 401); ninguna línea production.ERROR nueva; el árbol limpio; la caché de configuración
#    igual que la del respaldo. Formato de hotfix-backend.sh: «CORRECCIÓN CON RUTA NUEVA DESPLEGADA SIN DESVÍOS» solo con 0
#    desvíos; con desvíos, código 3 (producción queda en el commit nuevo: revisar; la vuelta atrás, impresa).
# VUELTA ATRÁS (--vuelta-atras=<respaldo>, o la automática): solo si producción está en el commit nuevo (o ya en el previo)
#    y con el árbol limpio: primero la caché de rutas del respaldo, byte a byte, escrita por www-data; después git checkout
#    --detach <previo> con umask 022 (si la caché no se pudo restaurar, se regenera ahora con route:cache, ya con el código
#    previo); route:list idéntica a la de antes y sin ai/check; queue:restart; workers;
#    ai/check → 404 y ai/decide → 401. Sin migraciones no hay nada que deshacer en la base (y NUNCA migrate:rollback). Volver
#    el código no es volver los datos: lo que el código nuevo anotó (metadata aditiva) se queda.
# No usa ni imprime secretos, no lee ficheros de entorno y solo escribe en el repositorio desplegado (git, bootstrap/cache) y
# en su carpeta de respaldo. No toca nginx, FPM, supervisor (más allá de queue:restart), el crontab, la base ni n8n.
# Códigos: 0 bien · 1 aborta sin tocar nada · 2 uso (sin tocar nada) · 3 desplegado con desvíos · 4 vuelta atrás automática
# hecha (producción en el previo) · 5 PARAR: vuelta atrás incompleta o salida inesperada a medias (DEPLOY_A4_REGENERAR.md §7).
set -euo pipefail
export LC_ALL=C

# ── Lo propio de producción y de este cambio ─────────────────────────────────────────────────────────────────────────────
APP=/var/www/api
WWW=www-data
API=https://api.ironbodyneiva.cloud/api
BK_RAIZ=/var/backups/ironbody
LOGS=$APP/backend/storage/logs
NIVEL_ERROR=production.ERROR
CACHE_RUTAS=bootstrap/cache/routes-v7.php
CACHE_CONFIG=bootstrap/cache/config.php
FICHERO_RUTAS=backend/routes/marketing.php
LINEA_RUTA_NUEVA="Route::post('ai/check', [UltronController::class, 'check']);"
RUTA_NUEVA="POST api/internal/marketing/ai/check"
PUERTAS_ANTES="decide=ok check=ausente commit=ok incidents=ok"
PUERTAS_DESPUES="decide=ok check=ok commit=ok incidents=ok"
VCP=backend/app/Services/Voice/VoiceCallPolicy.php
OSG=backend/app/Services/Outreach/Run/OutreachSendGate.php
RE_SENDS='^outreach\(sends-open\):'
RE_CALLS='^voice\(calls-open\):'
GUION=/root/deploy-a4-regenerar-backend.sh

uso() {
  echo "uso: bash $GUION <commit que sirve producción> <commit nuevo = origin/main> <fichero con la lista> [--solo-comprobar | --vuelta-atras=<respaldo> | --ensayo=<clon de banco>]"
  echo "     bash deploy-a4-regenerar-backend.sh --ensayo-rutas <route:list --json de antes> <route:list --json de después> [vuelta]"
}

# ── Las rutas: dos lectores de `route:list --json` (stdin) y la comparación ───────────────────────────────────────────────
# «total decide=… check=… commit=… incidents=… | middleware de decide» (cada puerta: ok · ausente · repetida · mal:…), o «?».
puertas() {
  python3 -I -c '
import json, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("?")
    sys.exit(0)
base = "api/internal/marketing/ai/"
ctl = "App\\Http\\Controllers\\Api\\Internal\\UltronController@"
puertas = [("decide", "decide", "decide"), ("check", "check", "check"), ("commit", "commit", "commit"), ("incidents", "ultron/incidents", "incident")]
uri = lambda x: str(x.get("uri", "")).lstrip("/")
def mw(x):
    m = x.get("middleware") or []
    return [str(i) for i in m] if isinstance(m, list) else [str(m)]
ref = None
out = []
for nombre, sufijo, accion in puertas:
    hits = [x for x in r if uri(x) == base + sufijo]
    if not hits:
        out.append(nombre + "=ausente")
        continue
    if len(hits) > 1:
        out.append(nombre + "=repetida")
        continue
    x = hits[0]
    m = mw(x)
    mal = []
    if str(x.get("method")) != "POST":
        mal.append("metodo")
    if str(x.get("action")) != ctl + accion:
        mal.append("accion")
    if "automation.internal" not in m and not any(i.endswith("VerifyInternalAutomationSignature") for i in m):
        mal.append("sin_firma")
    if not any(i.endswith(":internal-marketing-ai") for i in m):
        mal.append("sin_limitador")
    if nombre == "decide":
        ref = m
    elif ref is not None and m != ref:
        mal.append("middleware_distinto_de_decide")
    out.append(nombre + "=" + ("ok" if not mal else "mal:" + ",".join(mal)))
print(len(r), " ".join(out), "|", ",".join(ref) if ref is not None else "-")' 2>/dev/null || echo "?"
}
# Las rutas como líneas «MÉTODO uri» ordenadas (para comparar antes y después), o «?».
rutas_canon() {
  python3 -I -c '
import json, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("?")
    sys.exit(0)
for l in sorted("%s %s%s" % (x.get("method"), str(x.get("uri", "")).lstrip("/"), " @" + str(x["domain"]) if x.get("domain") else "") for x in r):
    print(l)' 2>/dev/null || echo "?"
}
# verifica_rutas <despliegue|vuelta> <json de antes> <json de después> → 0 si cuadra; deja RV_RESUMEN.
#   despliegue: las cuatro puertas bien y la ÚNICA diferencia es «+POST api/internal/marketing/ai/check».
#   vuelta: decide, commit e incidents bien, check ausente y ninguna diferencia con la de antes.
verifica_rutas() {
  local modo=$1 p0 p1 c0 c1 dif g1 mw esp_g esp_d
  p0=$(printf '%s' "$2" | puertas); p1=$(printf '%s' "$3" | puertas)
  c0=$(printf '%s' "$2" | rutas_canon); c1=$(printf '%s' "$3" | rutas_canon)
  if [ "$p0" = "?" ] || [ "$c0" = "?" ]; then RV_RESUMEN="la route:list de antes no se puede leer"; return 1; fi
  if [ "$p1" = "?" ] || [ "$c1" = "?" ]; then RV_RESUMEN="la route:list de después no se puede leer"; return 1; fi
  dif=$(comm -3 <(printf '%s\n' "$c0") <(printf '%s\n' "$c1") | awk '{ if (substr($0, 1, 1) == "\t") print "+" substr($0, 2); else print "-" $0 }' | paste -sd '|' -)
  g1=${p1#* }; g1=${g1%% |*}; mw=${p1##*| }
  case "$modo" in
    despliegue) esp_g=$PUERTAS_DESPUES; esp_d="+$RUTA_NUEVA" ;;
    *)          esp_g=$PUERTAS_ANTES;   esp_d="" ;;
  esac
  RV_RESUMEN="${p0%% *} → ${p1%% *} rutas · puertas [$g1] · middleware [$mw] · diferencia [${dif:-ninguna}]"
  [ "$g1" = "$esp_g" ] && [ "$dif" = "$esp_d" ]
}

# ── Ensayo de las rutas (local): el disparador de la vuelta atrás sobre dos salidas reales de route:list ──────────────────
if [ "${1:-}" = --ensayo-rutas ]; then
  { [ "$#" = 3 ] || { [ "$#" = 4 ] && [ "$4" = vuelta ]; }; } || { uso; exit 2; }
  [ -r "$2" ] && [ -r "$3" ] || { echo "no se pueden leer $2 y $3"; exit 2; }
  A=$(cat "$2"); B=$(cat "$3"); M=${4:-despliegue}
  echo "== ENSAYO DE RUTAS ($M) · antes $2 · después $3"
  P0=$(printf '%s' "$A" | puertas)
  echo "   antes: $P0"
  if [ "$M" = despliegue ]; then
    G0=${P0#* }; G0=${G0%% |*}
    [ "$G0" = "$PUERTAS_ANTES" ] || { echo "   el paso 0 ABORTARÍA: route:list de antes [$G0], esperado [$PUERTAS_ANTES] (no se tocaría nada)"; exit 1; }
  fi
  echo "   después: $(printf '%s' "$B" | puertas)"
  if verifica_rutas "$M" "$A" "$B"; then
    echo "   $RV_RESUMEN"
    echo "ENSAYO DE RUTAS: CUADRA ($([ "$M" = despliegue ] && echo "el despliegue seguiría al paso 4" || echo "la vuelta atrás daría las rutas de antes"))"
    exit 0
  fi
  echo "   $RV_RESUMEN"
  if [ "$M" = despliegue ]; then echo "ENSAYO DE RUTAS: NO CUADRA → en producción, VUELTA ATRÁS AUTOMÁTICA (código 4)"; exit 4; fi
  echo "ENSAYO DE RUTAS: NO CUADRA → en producción, la vuelta atrás acabaría en PARAR (código 5)"; exit 5
fi

# ── Argumentos ────────────────────────────────────────────────────────────────────────────────────────────────────────────
[ "$#" -ge 3 ] && [ "$#" -le 4 ] || { uso; echo "no se ha tocado nada"; exit 2; }
PREVIO=$1; NUEVO=$2; LISTA=$3; MODO=despliegue; BK=""; BANCO=""
case "${4:-}" in
  "") ;;
  --solo-comprobar) MODO=comprobar ;;
  --vuelta-atras=?*) MODO=vuelta; BK=${4#--vuelta-atras=} ;;
  --ensayo=?*) MODO=ensayo; BANCO=${4#--ensayo=} ;;
  *) uso; echo "argumento desconocido: $4; no se ha tocado nada"; exit 2 ;;
esac
[[ "$PREVIO" =~ ^[0-9a-f]{7,40}$ ]] && [[ "$NUEVO" =~ ^[0-9a-f]{7,40}$ ]] || { echo "los commits van en hexadecimal, de 7 a 40 cifras (git rev-parse --short=8 …); no se ha tocado nada"; exit 2; }
case "$LISTA" in /*) ;; *) LISTA="$PWD/$LISTA" ;; esac
if [ "$MODO" = ensayo ]; then
  [ -d "$BANCO" ] || { echo "el banco $BANCO no existe; no se ha tocado nada"; exit 2; }
  BANCO=$(cd "$BANCO" && pwd -P)
  [ "$BANCO" != "$APP" ] || { echo "--ensayo es para un clon de banco, nunca para $APP; no se ha tocado nada"; exit 2; }
  APP=$BANCO
fi
cd /tmp

aborta() { ETAPA=fin; echo "ABORTA (no se ha tocado nada): $*"; exit 1; }
g() { git -C "$APP" "$@"; }
corto() { g rev-parse --short=8 "$1" 2>/dev/null || echo "?"; }
artisan() { ( cd "$APP/backend" && sudo -u "$WWW" php artisan "$@" ); }
PRE=""
say() { printf '%s\n' "$*"; PRE="$PRE$*"$'\n'; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }

# Las constantes de llamadas y de envíos, con las reglas de hotfix-backend.sh (estado_vcp / estado_osg).
estado_constante() { # <commit> <fichero> <CONSTANTE> → cerrada | abierta | incoherente (…)
  local t c a x y
  t=$(g show "$1:$2" 2>/dev/null) || { echo "incoherente (el fichero no está en $1)"; return 0; }
  c=$(printf '%s\n' "$t" | grep -cE "^[[:space:]]*public const $3 = false;[[:space:]]*\$" || true)
  a=$(printf '%s\n' "$t" | grep -cE "^[[:space:]]*public const $3 = true;[[:space:]]*\$" || true)
  x=$(printf '%s\n' "$t" | grep -cE "$3[[:space:]]*=[[:space:]]*false" || true)
  y=$(printf '%s\n' "$t" | grep -cE "$3[[:space:]]*=[[:space:]]*true" || true)
  if [ "$c $y" = "1 0" ]; then echo cerrada; elif [ "$a $x" = "1 0" ]; then echo abierta
  else echo "incoherente (declaraciones = false: $c, = true: $a; menciones de false: $x, de true: $y)"; fi
}
# Las líneas de código de un trozo de PHP (fuera comentarios y líneas en blanco), sin espacios a los lados.
sin_comentarios() { grep -vE '^[[:space:]]*(//|/\*|\*|#)' | grep -v '^[[:space:]]*$' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }

# ── Lo del servidor (no se usa en el ensayo) ──────────────────────────────────────────────────────────────────────────────
# 13 programas de producción e ironbody-voice si el H8 lo instaló (14 desde el 11-11-2026; L9-102), como deploy-m1-backend.sh.
workers_esperados() { local n; n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13; }
workers() { # <RUNNING esperados> <segundos> → «RUNNING BACKOFF/FATAL», en cuanto cuadra o al agotar el plazo
  local hasta s r m
  hasta=$(( $(date +%s) + $2 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = "$1" ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
pids() { supervisorctl status 2>/dev/null | awk '$2 == "RUNNING" { p = $4; sub(/,$/, "", p); print $1, p }' | sort || true; }
reiniciados() { # <pids de antes> → cuántos de los que estaban en RUNNING lo están ahora con otro pid
  awk 'NR == FNR { a[$1] = $2; next } ($1 in a) && a[$1] != $2 { n++ } END { print n + 0 }' <(printf '%s\n' "$1") <(pids)
}
# Tras un queue:restart: hasta 90 s a que cada worker tenga pid nuevo; después un margen de 15 s (un worker que no arranca
# pasa a BACKOFF pasado startsecs y reintenta) y dos lecturas separadas 8 s. Deja REIN, W1 y W2.
espera_workers() { # <pids de antes del queue:restart> <RUNNING esperados>
  local hasta
  sleep 5
  hasta=$(( $(date +%s) + 90 ))
  while :; do
    REIN=$(reiniciados "$1")
    if [ "$REIN" -ge "$2" ] || [ "$(date +%s)" -ge "$hasta" ]; then break; fi
    sleep 3
  done
  sleep 15
  W1=$(workers "$2" 30); sleep 8; W2=$(workers "$2" 30)
}
http() { # <GET|POST> <ruta bajo /api> → código HTTP (000 si no contesta). Sin credenciales: no se usa ningún secreto.
  local c
  if [ "$1" = POST ]; then
    c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary '{}' "$API/$2") || c=000
  else
    c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/$2") || c=000
  fi
  echo "$c"
}
http_espera() { # <código esperado> <método> <ruta> → el último código (3 intentos, 3 s entre ellos: la revalidación de OPcache)
  local i c=000
  for i in 1 2 3; do c=$(http "$2" "$3"); [ "$c" = "$1" ] && break; [ "$i" = 3 ] || sleep 3; done
  echo "$c"
}
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
errores_nuevos() { # <lineas_log de antes> → líneas production.ERROR nuevas (un fichero nuevo, p. ej. el del día, cuenta entero)
  local f n e total=0
  for f in "$LOGS"/laravel*.log; do
    [ -f "$f" ] || continue
    n=$(printf '%s\n' "$1" | awk -v f="$f" '$1 == f {print $2}')
    e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c "$NIVEL_ERROR" || true)
    total=$((total + e))
  done
  echo "$total"
}
legibles() { # <commit A> <commit B>: www-data lee cada fichero del diff que exista ahora → 0 si todos; deja LEGIBLES «revisados ilegibles»
  local f rev=0 mal=0
  while IFS= read -r f; do
    [ -n "$f" ] && [ -e "$APP/$f" ] || continue
    rev=$((rev + 1))
    sudo -u "$WWW" test -r "$APP/$f" || { echo "   ✗ $WWW no puede leer $f ($(stat -c '%U:%G %a' "$APP/$f"))"; mal=$((mal + 1)); }
  done < <(g diff --name-only --no-renames "$1" "$2")
  LEGIBLES="$rev $mal"
  [ "$rev" -gt 0 ] && [ "$mal" = 0 ]
}
# La caché de rutas: fichero normal (no un enlace) de www-data en 600. Si el modo no es 600, chmod COMO www-data (root no
# hace chmod en bootstrap/cache: seguiría un enlace puesto en su lugar; H-5 de SEC7).
cache_ok() {
  local p="$APP/backend/$CACHE_RUTAS"
  [ -f "$p" ] && [ ! -L "$p" ] || { echo "   ✗ $CACHE_RUTAS no es un fichero normal (¿un enlace?)"; return 1; }
  [ "$(stat -c '%U' "$p")" = "$WWW" ] || { echo "   ✗ $CACHE_RUTAS es de $(stat -c '%U' "$p"), no de $WWW: los workers quedarían en BACKOFF en el siguiente arranque"; return 1; }
  if [ "$(stat -c '%a' "$p")" != 600 ]; then
    sudo -u "$WWW" chmod 600 "$p" || { echo "   ✗ $WWW no pudo dejar $CACHE_RUTAS en 600"; return 1; }
    echo "   $CACHE_RUTAS pasado a 600 (como $WWW)"
  fi
  [ "$(stat -c '%U %a' "$p")" = "$WWW 600" ]
}
orden_vuelta() {
  echo "   vuelta atrás: bash $GUION $PREVIO $NUEVO $LISTA --vuelta-atras=$BK"
  echo "   a mano (lo mismo): [ \"\$(git -C $APP rev-parse HEAD)\" = $N ] && ( cd $APP && umask 022 && git checkout -q --detach $P ) && cd $APP/backend && sudo -u $WWW sh -c 'umask 077; cat > $CACHE_RUTAS.a4-vuelta && mv -f $CACHE_RUTAS.a4-vuelta $CACHE_RUTAS' < $BK/bootstrap-cache/routes-v7.php && sudo -u $WWW php artisan queue:restart"
  echo "     (o, en vez de la caché del respaldo: sudo -u $WWW sh -c 'umask 077; php artisan route:cache -q'; después stat -c '%U %a' $CACHE_RUTAS → $WWW 600)"
}

# La vuelta atrás (la automática y la de --vuelta-atras). Usa P, N, BK y RUN0. 0 si producción queda en P con las rutas de antes.
# Orden: primero la caché de rutas de antes y después el código. En la ventana intermedia (código nuevo, rutas viejas) cada
# ruta apunta a un método que existe en los dos commits; al revés, ai/check apuntaría a un método que el código viejo no tiene.
# Se ejecuta en un `if`: `set -e` no corta aquí dentro, así que cada paso se comprueba a mano.
vuelta_atras_ejecuta() {
  local h ok=1 j c1 c2 p0 restaurada=0
  h=$(g rev-parse HEAD) || return 1
  case "$h" in
    "$N"|"$P") ;;
    *) echo "   ✗ producción está en $(corto HEAD), ni en el commit nuevo ni en el previo: otra sesión desplegó; no se toca"; return 1 ;;
  esac
  [ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "   ✗ el árbol desplegado tiene cambios sin confirmar: no se toca"; return 1; }
  if ( cd "$APP/backend" && sudo -u "$WWW" sh -c "umask 077; cat > $CACHE_RUTAS.a4-vuelta && mv -f $CACHE_RUTAS.a4-vuelta $CACHE_RUTAS" ) < "$BK/bootstrap-cache/routes-v7.php" \
     && cmp -s "$BK/bootstrap-cache/routes-v7.php" "$APP/backend/$CACHE_RUTAS"; then
    restaurada=1; echo "   a. caché de rutas: la del respaldo, byte a byte (escrita por $WWW; root no escribe en bootstrap/cache)"
  else
    echo "   a. la caché del respaldo no se pudo restaurar: se regenerará con route:cache después del código"
  fi
  if [ "$h" = "$N" ]; then
    ( cd "$APP" && umask 022 && git checkout -q --detach "$P" ) || { echo "   ✗ git checkout --detach $(corto "$P") falló"; return 1; }
  else
    echo "   el código ya estaba en $(corto "$P")"
  fi
  [ "$(g rev-parse HEAD)" = "$P" ] || { echo "   ✗ HEAD no quedó en $(corto "$P")"; return 1; }
  if legibles "$P" "$N"; then echo "   b. código: HEAD=$(corto HEAD) (checkout --detach con umask 022) · $WWW lee los ${LEGIBLES%% *} ficheros del diff"
  else echo "   ✗ b. código: HEAD=$(corto HEAD), pero hay ficheros que $WWW no lee (arriba)"; ok=0; fi
  if [ "$restaurada" = 0 ]; then
    ( cd "$APP/backend" && sudo -u "$WWW" sh -c 'umask 077; php artisan route:cache -q' ) || { echo "   ✗ route:cache falló"; return 1; }
    echo "   b'. caché de rutas regenerada con el código de $(corto "$P") (route:cache como $WWW, umask 077)"
  fi
  cache_ok || return 1
  j=$(artisan route:list --json 2>/dev/null) || j=""
  if verifica_rutas vuelta "$(cat "$BK/rutas-antes.json")" "$j"; then echo "   c. route:list: $RV_RESUMEN (la de antes)"
  else echo "   ✗ c. route:list: $RV_RESUMEN (esperado: idéntica a la de antes y sin ai/check)"; ok=0; fi
  cmp -s "$BK/bootstrap-cache/config.php" "$APP/backend/$CACHE_CONFIG" || echo "   AVISO: la caché de configuración no es la del respaldo (este guion no la toca; si hace falta, a mano: DEPLOY_A4_REGENERAR.md §7)"
  p0=$(pids)
  ( cd "$APP/backend" && sudo -u "$WWW" php artisan queue:restart -q ) || { echo "   ✗ queue:restart falló"; ok=0; }
  espera_workers "$p0" "$RUN0"
  echo "   d. workers (RUNNING · BACKOFF/FATAL): [$W1] y [$W2] · con pid nuevo $REIN de $RUN0"
  { [ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] && [ "$REIN" -ge "$RUN0" ]; } || { echo "   ✗ d. workers: esperado [$RUN0 0] dos veces y $RUN0 con pid nuevo"; ok=0; }
  c1=$(http_espera 404 POST internal/marketing/ai/check); c2=$(http POST internal/marketing/ai/decide)
  echo "   e. sin credenciales: ai/check → $c1 (esperado 404) · ai/decide → $c2 (esperado 401)"
  [ "$c1" = 404 ] && [ "$c2" = 401 ] || ok=0
  [ "$ok" = 1 ]
}

ETAPA=previa
al_salir() {
  local rc=$?
  case "$ETAPA" in previa|fin) return 0 ;; esac
  if [ "$ETAPA" = respaldo ]; then
    echo "SALIDA INESPERADA durante el respaldo (código $rc): producción sin tocar; el respaldo ${BK:-—} puede estar incompleto"
    exit 1
  fi
  echo "SALIDA INESPERADA en la etapa «$ETAPA» (código $rc): PARAR. Producción puede haber quedado a medias (HEAD=$(corto HEAD))."
  echo "   vuelta atrás: bash $GUION $PREVIO $NUEVO $LISTA --vuelta-atras=${BK:-<respaldo>} (DEPLOY_A4_REGENERAR.md §7)"
  exit 5
}
trap al_salir EXIT

# ── Modo vuelta atrás (--vuelta-atras=<respaldo>) ────────────────────────────────────────────────────────────────────────
if [ "$MODO" = vuelta ]; then
  [ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
  { [ -d "$BK" ] && [ ! -L "$BK" ] && [ "$(stat -c '%U %a' "$BK")" = "root 700" ]; } || { echo "el respaldo $BK no existe o no es un directorio 700 de root; no se ha tocado nada"; exit 2; }
  for f in api-commit.txt api-commit-nuevo.txt rutas-antes.json bootstrap-cache/routes-v7.php bootstrap-cache/config.php SHA256SUMS; do
    [ -s "$BK/$f" ] || { echo "falta $BK/$f; no se ha tocado nada"; exit 2; }
  done
  ( cd "$BK" && sha256sum -c --status SHA256SUMS ) || { echo "el respaldo no coincide con su SHA256SUMS; no se ha tocado nada"; exit 2; }
  P=$(cat "$BK/api-commit.txt"); N=$(cat "$BK/api-commit-nuevo.txt")
  case "$P" in "$PREVIO"*) ;; *) echo "el respaldo es del commit previo $P, no de $PREVIO; no se ha tocado nada"; exit 2 ;; esac
  case "$N" in "$NUEVO"*) ;; *) echo "el respaldo es del commit nuevo $N, no de $NUEVO; no se ha tocado nada"; exit 2 ;; esac
  LOG="$BK/vuelta-atras-$(date -u +%Y%m%d-%H%M%S).log"
  ( umask 077; : > "$LOG" ) || { echo "no se pudo crear $LOG; no se ha tocado nada"; exit 2; }
  exec > >(tee -a "$LOG") 2>&1
  echo "== deploy-a4-regenerar-backend.sh · VUELTA ATRÁS · $(date -u +%Y-%m-%dT%H:%M:%SZ) · de $(corto "$N") a $(corto "$P") · respaldo $BK · registro $LOG"
  RUN0=$(workers_esperados)
  ETAPA=vuelta
  if vuelta_atras_ejecuta; then
    ETAPA=fin; echo "VUELTA ATRÁS HECHA: producción en $(corto "$P"), con la caché de rutas de antes (sin ai/check)"; exit 0
  fi
  ETAPA=fin; echo "VUELTA ATRÁS CON DESVÍOS: PARAR y revisar a mano (DEPLOY_A4_REGENERAR.md §7)"; exit 5
fi

# ── 0. Comprobaciones previas ─────────────────────────────────────────────────────────────────────────────────────────────
if [ "$MODO" = ensayo ]; then
  say "== deploy-a4-regenerar-backend.sh · ENSAYO EN SECO · $(date -u +%Y-%m-%dT%H:%M:%SZ) · banco $APP · PREVIO=$PREVIO · NUEVO=$NUEVO · lista $LISTA"
  say "   (solo las comprobaciones de git del paso 0; las del servidor —workers, cachés, route:list, HTTP— no se ensayan aquí)"
else
  [ "$(id -u)" = 0 ] || aborta "se ejecuta como root en el servidor"
  say "== deploy-a4-regenerar-backend.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · modo $MODO · PREVIO=$PREVIO · NUEVO=$NUEVO · lista $LISTA"
fi
[ -r "$LISTA" ] && [ -f "$LISTA" ] || aborta "no se puede leer la lista $LISTA"
g rev-parse --is-inside-work-tree > /dev/null 2>&1 || aborta "$APP no es un checkout de git"
say "0. comprobaciones previas (solo lectura y git fetch; si algo falla, no se toca nada):"
P=$(g rev-parse -q --verify "${PREVIO}^{commit}" 2>/dev/null) || aborta "el commit previo $PREVIO no existe en $APP"
[ "$(g rev-parse HEAD)" = "$P" ] || aborta "producción no está en $PREVIO (está en $(corto HEAD)): si se desplegó otra cosa, revisar qué entró y rehacer la lista"
[ -z "$(g status --porcelain --untracked-files=no)" ] || aborta "el árbol desplegado tiene cambios sin confirmar"
g fetch -q origin main || aborta "git fetch origin main falló"
N=$(g rev-parse -q --verify "${NUEVO}^{commit}" 2>/dev/null) || aborta "el commit nuevo $NUEVO no existe tras git fetch (¿está subido a origin/main?)"
[ "$(g rev-parse origin/main)" = "$N" ] || aborta "origin/main no es $NUEVO (es $(corto origin/main)): si main avanzó, revisar lo nuevo y rehacer la lista con la punta"
[ "$N" != "$P" ] || aborta "el commit nuevo es el que ya sirve producción: no hay nada que desplegar"
g merge-base --is-ancestor "$P" "$N" || aborta "$NUEVO no desciende de $PREVIO: no es un avance rápido"
K=$(g rev-list --count "$P..$N")
COMMITS=$(g log --format='%H %s' "$P..$N")
while IFS= read -r l; do
  [ -n "$l" ] || continue
  s=${l#* }
  if [[ $s =~ $RE_SENDS ]]; then aborta "el commit ${l:0:8} abre los envíos de la automatización (\`outreach(sends-open):\`): nunca por este guion"; fi
  if [[ $s =~ $RE_CALLS ]]; then aborta "el commit ${l:0:8} abre las llamadas (\`voice(calls-open):\`): solo deploy-h8-backend-fase1.sh --fase=4a"; fi
done <<< "$COMMITS"
# La lista: EXACTAMENTE el diff (--no-renames: un renombrado lleva el nombre viejo y el nuevo), línea a línea.
CAMBIADOS=$(g diff --name-only --no-renames "$P" "$N")
DIFF_ORD=$(printf '%s\n' "$CAMBIADOS" | sed '/^$/d' | sort)
LISTA_ORD=$(tr -d '\r' < "$LISTA" | sed '/^[[:space:]]*$/d' | sort)
if [ "$DIFF_ORD" != "$LISTA_ORD" ]; then
  FALTAN=$(comm -23 <(printf '%s\n' "$DIFF_ORD") <(printf '%s\n' "$LISTA_ORD"))
  SOBRAN=$(comm -13 <(printf '%s\n' "$DIFF_ORD") <(printf '%s\n' "$LISTA_ORD"))
  NF=$(printf '%s\n' "$FALTAN" | grep -c . || true); NS=$(printf '%s\n' "$SOBRAN" | grep -c . || true)
  aborta "la lista no coincide EXACTAMENTE con git diff --name-only --no-renames $(corto "$P") $(corto "$N"): $NF en el diff que la lista no trae [$(printf '%s\n' "$FALTAN" | sed -n 1,5p | paste -sd ' ' -)] · $NS en la lista que el diff no trae (o repetidas) [$(printf '%s\n' "$SOBRAN" | sed -n 1,5p | paste -sd ' ' -)]"
fi
NF_TOTAL=$(printf '%s\n' "$DIFF_ORD" | grep -c . || true)
# Las zonas que este guion no despliega.
while IFS= read -r f; do
  [ -n "$f" ] || continue
  case "$f" in
    backend/database/*) aborta "el diff trae $f: migraciones y base de datos no van por este guion (y nunca migrate:rollback)" ;;
    backend/config/*) aborta "el diff trae $f: la configuración necesita config:cache y su propia vía" ;;
    backend/composer.*|composer.*|backend/vendor/*) aborta "el diff trae $f: las dependencias (composer) no van por este guion" ;;
    backend/bootstrap/*) aborta "el diff trae $f: el arranque de la aplicación (bootstrap/) no va por este guion" ;;
    "$FICHERO_RUTAS") ;;
    backend/routes/*) aborta "el diff trae $f: de backend/routes/ solo puede cambiar marketing.php (la ruta ai/check)" ;;
    "$VCP"|"$OSG") aborta "el diff trae $f: las constantes de llamadas y de envíos no se tocan por este guion" ;;
  esac
done <<< "$CAMBIADOS"
# marketing.php: tiene que cambiar, y la ÚNICA línea de código nueva es la ruta de ai/check (lo demás, comentarios).
grep -qxF "$FICHERO_RUTAS" <<< "$CAMBIADOS" || aborta "el diff no cambia $FICHERO_RUTAS: este guion es para el cambio que añade ai/check; una corrección sin rutas va por hotfix-backend.sh"
CUERPO=$(g diff -U0 --no-renames "$P" "$N" -- "$FICHERO_RUTAS" | sed -n '/^@@ /,$p' | grep -v '^@@ ' || true)
QUITADAS=$(printf '%s\n' "$CUERPO" | sed -n 's/^-//p' | sin_comentarios || true)
ANADIDAS=$(printf '%s\n' "$CUERPO" | sed -n 's/^+//p' | sin_comentarios || true)
[ -z "$QUITADAS" ] || aborta "$FICHERO_RUTAS quita líneas de código, no solo comentarios: [$(printf '%s\n' "$QUITADAS" | sed -n 1,3p | paste -sd '|' -)]"
[ "$ANADIDAS" = "$LINEA_RUTA_NUEVA" ] || aborta "en $FICHERO_RUTAS la única línea de código nueva tiene que ser «$LINEA_RUTA_NUEVA»; trae: [$(printf '%s\n' "$ANADIDAS" | sed -n 1,5p | paste -sd '|' -)]"
RM=$(g show "$N:$FICHERO_RUTAS")
for par in decide:decide check:check commit:commit ultron/incidents:incident; do
  n=$(grep -cF "Route::post('ai/${par%%:*}', [UltronController::class, '${par#*:}']);" <<< "$RM" || true)
  [ "$n" = 1 ] || aborta "$FICHERO_RUTAS en $(corto "$N") declara la puerta ai/${par%%:*} $n veces (esperado 1)"
done
E_VCP=$(estado_constante "$N" "$VCP" CALLS_ENABLED_IN_CODE); E_OSG=$(estado_constante "$N" "$OSG" SENDS_ENABLED_IN_CODE)
case "$E_VCP" in cerrada|abierta) ;; *) aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE en $(corto "$N"): $E_VCP" ;; esac
case "$E_OSG" in cerrada|abierta) ;; *) aborta "OutreachSendGate::SENDS_ENABLED_IN_CODE en $(corto "$N"): $E_OSG" ;; esac
cuenta() { printf '%s\n' "$DIFF_ORD" | grep -cE "$1" || true; }
say "   producción $(corto "$P") limpia · origin/main $(corto "$N") · $K commit(s) · $NF_TOTAL ficheros = la lista: $(cuenta '^backend/app/') de backend/app · 1 de backend/routes (marketing.php) · $(cuenta '^backend/tests/') de backend/tests · $(cuenta '^backend/docs/') de backend/docs · $(cuenta '^(backend/(app|routes|tests|docs)/)' | awk -v t="$NF_TOTAL" '{print t - $1}') otros"
say "   ni migraciones, ni backend/config, ni composer, ni bootstrap; de backend/routes solo marketing.php: + «$LINEA_RUTA_NUEVA» y, aparte, solo comentarios"
say "   las cuatro puertas declaradas una vez en el marketing.php nuevo (decide, check, commit, ultron/incidents)"
say "   constante de llamadas (VoiceCallPolicy): $E_VCP · de envíos (OutreachSendGate): $E_OSG · los dos ficheros sin cambios"
while IFS= read -r l; do if [ -n "$l" ]; then say "      ${l:0:8} $(printf '%s' "${l#* }" | cut -c1-110)"; fi; done <<< "$COMMITS"
if [ "$MODO" = ensayo ]; then
  ETAPA=fin
  echo "ENSAYO: COMPROBACIONES PREVIAS DE GIT EN VERDE (no se ha tocado nada)"
  exit 0
fi

# El servidor: workers, cachés, rutas de antes, la sonda HTTP de referencia y el registro de errores.
RUN0=$(workers_esperados)
SUP0=$(supervisorctl status 2>/dev/null || true)
R0=$(printf '%s\n' "$SUP0" | grep -c RUNNING || true)
M0=$(printf '%s\n' "$SUP0" | grep -cE 'BACKOFF|FATAL' || true)
[ "$M0" = 0 ] || aborta "workers en BACKOFF o FATAL antes de empezar ($M0): no se despliega sobre un sistema degradado"
[ "$R0" = "$RUN0" ] || aborta "workers RUNNING antes de empezar: $R0 (esperados $RUN0)"
FACT0=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
for f in "$CACHE_RUTAS" "$CACHE_CONFIG"; do
  p="$APP/backend/$f"
  { [ -f "$p" ] && [ ! -L "$p" ]; } || aborta "$f no existe o no es un fichero normal: este guion parte de producción CON las rutas y la configuración en caché"
  [ "$(stat -c '%U %a' "$p")" = "$WWW 600" ] || aborta "$f es $(stat -c '%U %a' "$p") (esperado $WWW 600): aclararlo antes (un chmod 600 sin chown deja los workers en BACKOFF en el siguiente arranque)"
done
RUTAS0_JSON=$(artisan route:list --json 2>/dev/null) || RUTAS0_JSON=""
PU0=$(printf '%s' "$RUTAS0_JSON" | puertas)
[ "$PU0" != "?" ] || aborta "route:list --json (como $WWW) no se puede leer"
G0=${PU0#* }; G0=${G0%% |*}
[ "$G0" = "$PUERTAS_ANTES" ] || aborta "route:list de producción: [$G0] (esperado [$PUERTAS_ANTES])"
C_DEC0=$(http POST internal/marketing/ai/decide); C_CHK0=$(http POST internal/marketing/ai/check)
[ "$C_DEC0" = 401 ] || aborta "POST ai/decide sin credenciales → $C_DEC0 (esperado 401): sin esa referencia la sonda de ai/check no distingue nada"
[ "$C_CHK0" = 404 ] || aborta "POST ai/check sin credenciales → $C_CHK0 antes de desplegar (esperado 404: la ruta todavía no existe)"
LOG_ANTES=$(lineas_log)
OPC=$(grep -rhE '^[[:space:]]*opcache\.(validate_timestamps|revalidate_freq)' /etc/php/*/fpm/ 2>/dev/null | tr -d ' ' | sort -u | paste -sd ' ' - || true)
# Con la revalidación apagada, el FPM no vería ni el código ni la caché nuevos sin recargarse, y recargarlo es otra orden.
# (Solo se leen los .ini del FPM: un php_admin_value del pool no se ve aquí; lo delataría la sonda del paso 5, ai/check en 404.)
case " $OPC " in *"validate_timestamps=0"*|*"validate_timestamps=Off"*|*"validate_timestamps=off"*|*"validate_timestamps=false"*)
  aborta "el FPM tiene la revalidación de OPcache apagada ($OPC): sin recargar php-fpm no vería el código ni la caché de rutas nuevos; recargarlo es otra orden (DEPLOY_A4_REGENERAR.md §6)" ;;
esac
say "   workers $R0 RUNNING (esperados $RUN0) · 0 BACKOFF/FATAL · ironbody-billing-worker ${FACT0:-?} · cachés de rutas y de configuración: $WWW 600"
say "   route:list: ${PU0%% *} rutas · [$G0] · middleware [${PU0##*| }]"
say "   sin credenciales: POST ai/decide → $C_DEC0 · POST ai/check → $C_CHK0 (todavía no existe) · OPcache del FPM: ${OPC:-sin líneas (validate_timestamps = 1 y revalidate_freq = 2, los valores por defecto)}"
if [ "$MODO" = comprobar ]; then
  ETAPA=fin
  echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"
  exit 0
fi

# ── 1. Respaldo ───────────────────────────────────────────────────────────────────────────────────────────────────────────
ETAPA=respaldo
TS=$(date -u +%Y%m%d-%H%M%S)
BK="$BK_RAIZ/ultron-studio-a4-regenerar-$TS"
[ ! -e "$BK" ] || aborta "el respaldo $BK ya existe"
( umask 077; mkdir -p "$BK" ) || aborta "no se pudo crear el respaldo $BK"
[ "$(stat -c '%U %a' "$BK")" = "root 700" ] || aborta "el respaldo $BK no quedó 700 de root"
LOG="$BK/registro.log"
( umask 077; printf '%s' "$PRE" > "$LOG" ) || aborta "no se pudo crear el registro $LOG"
exec > >(tee -a "$LOG") 2>&1
# Encadenado con &&: dentro de un subshell que se evalúa con ||, `set -e` no corta (un install fallido pasaría callado).
(
  umask 077 \
  && printf '%s\n' "$P" > "$BK/api-commit.txt" \
  && printf '%s\n' "$N" > "$BK/api-commit-nuevo.txt" \
  && install -m 600 -o root -g root "$LISTA" "$BK/lista.txt" \
  && mkdir "$BK/bootstrap-cache" \
  && install -m 600 -o root -g root "$APP/backend/$CACHE_RUTAS" "$BK/bootstrap-cache/routes-v7.php" \
  && install -m 600 -o root -g root "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" \
  && printf '%s\n' "$RUTAS0_JSON" > "$BK/rutas-antes.json" \
  && printf '%s\n' "$SUP0" > "$BK/supervisor-antes.txt" \
  && { [ ! -f "$0" ] || install -m 600 -o root -g root "$0" "$BK/deploy-a4-regenerar-backend.sh"; } \
  && cd "$BK" \
  && sha256sum api-commit.txt api-commit-nuevo.txt lista.txt bootstrap-cache/routes-v7.php bootstrap-cache/config.php rutas-antes.json > SHA256SUMS
) || aborta "no se pudo completar el respaldo $BK"
cmp -s "$APP/backend/$CACHE_RUTAS" "$BK/bootstrap-cache/routes-v7.php" && cmp -s "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" \
  || aborta "las cachés del respaldo no son las de producción (¿cambiaron mientras se copiaban?)"
MALOS=$(find "$BK" -mindepth 1 \( -type f ! -perm 600 \) -o \( -type d ! -perm 700 \) -o ! -user root | wc -l | tr -d ' ')
[ "$MALOS" = 0 ] || aborta "$MALOS entradas del respaldo no son de root en 600/700"
PU_BK=$(puertas < "$BK/rutas-antes.json")
[ "$PU_BK" = "$PU0" ] || aborta "rutas-antes.json del respaldo no se lee igual que la route:list de antes"
echo "1. respaldo $BK (700 de root; ficheros 600 de root): commit previo y nuevo, lista, caché de rutas (sha256 $(sha256sum "$BK/bootstrap-cache/routes-v7.php" | cut -c1-12)…), caché de configuración (lleva secretos: no se imprime), route:list y supervisor de antes, el guion y SHA256SUMS"

# ── 2. Código ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
ETAPA=codigo
revertir_auto() {
  echo "   ✗ $*"
  echo "VUELTA ATRÁS AUTOMÁTICA (los workers no se han reiniciado con el código nuevo):"
  ETAPA=vuelta
  if vuelta_atras_ejecuta; then
    ETAPA=fin
    echo "VUELTA ATRÁS AUTOMÁTICA HECHA: producción de nuevo en $(corto "$P"), con la caché de rutas de antes. Motivo: $*. Diagnosticar antes de repetir; el respaldo queda en $BK"
    exit 4
  fi
  ETAPA=fin
  echo "PARAR: la vuelta atrás automática no quedó limpia (arriba). Producción en $(corto HEAD). A mano: DEPLOY_A4_REGENERAR.md §7"
  orden_vuelta
  exit 5
}
if ! ( umask 022; g merge -q --ff-only "$N" ); then
  if [ "$(g rev-parse HEAD)" = "$P" ] && [ -z "$(g status --porcelain --untracked-files=no)" ]; then
    ETAPA=fin; echo "ABORTA: git merge --ff-only falló y producción sigue en $(corto "$P"), sin tocar (el respaldo queda en $BK)"; exit 1
  fi
  revertir_auto "git merge --ff-only falló a medias"
fi
[ "$(g rev-parse HEAD)" = "$N" ] || revertir_auto "tras el merge, HEAD es $(corto HEAD) y no $(corto "$N")"
legibles "$P" "$N" || revertir_auto "${LEGIBLES#* } de ${LEGIBLES%% *} ficheros del merge no los lee $WWW"
echo "2. código: HEAD=$(corto HEAD) (merge --ff-only, umask 022) · $WWW lee los ${LEGIBLES%% *} ficheros del merge; los de app y rutas:"
while IFS= read -r f; do
  case "$f" in backend/app/*|backend/routes/*) if [ -f "$APP/$f" ]; then echo "   $(stat -c '%U %a' "$APP/$f") $f"; fi ;; esac
done <<< "$CAMBIADOS"

# ── 3. Caché de rutas y route:list ───────────────────────────────────────────────────────────────────────────────────────
ETAPA=rutas
( cd "$APP/backend" && sudo -u "$WWW" sh -c 'umask 077; php artisan route:cache -q' ) || revertir_auto "route:cache (como $WWW) falló"
cache_ok || revertir_auto "la caché de rutas no quedó como fichero normal de $WWW en 600"
RUTAS1_JSON=$(artisan route:list --json 2>/dev/null) || RUTAS1_JSON=""
verifica_rutas despliegue "$RUTAS0_JSON" "$RUTAS1_JSON" || revertir_auto "route:list no cuadra: $RV_RESUMEN (esperado [$PUERTAS_DESPUES] y la diferencia [+$RUTA_NUEVA])"
echo "3. caché de rutas: $(stat -c '%U %a' "$APP/backend/$CACHE_RUTAS") (route:cache como $WWW, umask 077) · route:list: $RV_RESUMEN"
cmp -s "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" || desvio "la caché de configuración cambió durante el despliegue (este guion no la toca): ¿otra sesión?"

# ── 4. Workers ────────────────────────────────────────────────────────────────────────────────────────────────────────────
ETAPA=workers
PIDS_PRE=$(pids)
artisan queue:restart -q || desvio "queue:restart falló: los workers siguen con el código anterior"
espera_workers "$PIDS_PRE" "$RUN0"
FACT1=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
echo "4. workers (RUNNING · BACKOFF/FATAL): antes [$R0 0] → [$W1] y [$W2] (dos lecturas, pasado el margen) · con pid nuevo $REIN de $RUN0 · ironbody-billing-worker ${FACT0:-?} → ${FACT1:-?}"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado [$RUN0 0] las dos veces): con BACKOFF/FATAL, la vuelta atrás YA (abajo)"
[ "$REIN" -ge "$RUN0" ] || desvio "solo $REIN de $RUN0 workers tienen pid nuevo tras 90 s: los demás siguen con el código anterior (¿un trabajo largo?); volver a mirar supervisorctl status"
[ "$FACT1" = "$FACT0" ] || desvio "ironbody-billing-worker pasó de «$FACT0» a «$FACT1»"

# ── 5. Verificación (solo lectura; sin credenciales) ─────────────────────────────────────────────────────────────────────
ETAPA=verificacion
C_CHK1=$(http_espera 401 POST internal/marketing/ai/check)
C_DEC1=$(http POST internal/marketing/ai/decide); C_COM1=$(http POST internal/marketing/ai/commit); C_INC1=$(http POST internal/marketing/ai/ultron/incidents)
C_VOZ1=$(http GET admin/voice/status)
echo "5. sin credenciales: POST ai/check $C_CHK0 → $C_CHK1 (esperado 401) · ai/decide → $C_DEC1 · ai/commit → $C_COM1 · ai/ultron/incidents → $C_INC1 · admin/voice/status → $C_VOZ1 (todas 401)"
if [ "$C_CHK1" != 401 ]; then
  if [ "$C_CHK1" = 404 ]; then desvio "ai/check sigue en 404 aunque route:list la tiene: el FPM no ve la caché nueva (¿opcache.validate_timestamps=0?). Recargar el FPM es otra orden: decidir entre esa recarga y la vuelta atrás (DEPLOY_A4_REGENERAR.md §6)"
  else desvio "ai/check sin credenciales respondió $C_CHK1 (esperado 401)"; fi
fi
for par in "ai/decide:$C_DEC1" "ai/commit:$C_COM1" "ai/ultron/incidents:$C_INC1" "admin/voice/status:$C_VOZ1"; do
  [ "${par##*:}" = 401 ] || desvio "${par%:*} sin credenciales respondió ${par##*:} (esperado 401)"
done
[ "$(g rev-parse HEAD)" = "$N" ] || desvio "producción ya no está en $(corto "$N") (HEAD $(corto HEAD))"
[ -z "$(g status --porcelain --untracked-files=no)" ] || desvio "el checkout tiene cambios tras el despliegue"
ERR=$(errores_nuevos "$LOG_ANTES")
echo "6. $NIVEL_ERROR nuevos en laravel*.log desde el inicio: $ERR"
[ "$ERR" = 0 ] || desvio "$ERR líneas $NIVEL_ERROR nuevas en el log desde el inicio del despliegue"
echo "   producción sirve $(corto HEAD) · respaldo $BK · registro $LOG"
echo "   VIGILANCIA (15 min, solo lectura): supervisorctl status (los $RUN0 en RUNNING) · $NIVEL_ERROR nuevos · 5xx de /api/internal/marketing/ai/ en el access.log · failed_jobs · el primer turno canario (DEPLOY_A4_REGENERAR.md §5)"
orden_vuelta
ETAPA=fin
if [ "$DESVIOS" = 0 ]; then echo "CORRECCIÓN CON RUTA NUEVA DESPLEGADA SIN DESVÍOS"; exit 0; fi
echo "CORRECCIÓN CON RUTA NUEVA DESPLEGADA CON $DESVIOS DESVÍOS: revisar arriba (producción queda en $(corto HEAD); si un desvío lo pide, la vuelta atrás de arriba y después el diagnóstico)"
exit 3
