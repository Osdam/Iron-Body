#!/bin/bash
# Despliegue POR LOTES del cierre de ULTRON STUDIO (lotes 2, 3 y 4; el lote 1 va por hotfix-backend.sh). Ficha de cada
# lote, órdenes exactas, impacto y vuelta atrás: ops/DEPLOY_CIERRE_LOTES.md. Derivado de deploy-a4-regenerar-backend.sh
# (la lista exacta, el respaldo, las cachés como www-data, la verificación sin credenciales y la vuelta atrás automática) y
# de deploy-h9-backend-cierre.sh e iron-ia/ops/deploy-recuperacion-rutinas.sh (las migraciones con el rol dueño por
# ironbody-migrate --path, desde una copia de root fuera de storage/, comprobada contra git justo antes de ejecutarla).
#
# Uso (servidor, root; la lista y las declaraciones las sube el lead desde el Mac: DEPLOY_CIERRE_LOTES.md §2):
#   bash /root/deploy-cierre-lote.sh <commit que sirve producción> <commit nuevo = origin/main> <lista>
#        [--migraciones=<fichero> --orden=migracion-primero|codigo-primero] [--config=<fichero>] [--rutas=<fichero>]
#        [--programador=<fichero>] [--solo-comprobar]
#   bash /root/deploy-cierre-lote.sh <previo> <nuevo> <lista> --vuelta-atras=<directorio del respaldo>
# Las declaraciones (una línea por entrada; «#» al principio de línea es comentario):
#   --migraciones  «nombre tipo tabla objeto marca privilegios vuelta», en el orden de aplicación (el de los nombres):
#                  tipo check      → objeto = el CHECK; marca = un literal que su definición tiene DESPUÉS y no ANTES
#                  tipo transicion → objeto = el disparador de avance de estado; marca = «desde>hasta» (la transición
#                                    que se añade a la cláusula de OLD.<col> = 'desde')
#                  tipo tabla      → objeto «-»; marca = el prefijo de sus funciones propias (o «-»); privilegios = los
#                                    de la aplicación en la tabla nueva (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE)
#                  vuelta          → deshacer (la vuelta atrás la deshace si la base lo admite: un CHECK que las filas
#                                    cumplen, un disparador que no mira filas, una tabla vacía) · conservar (no se toca)
#   --config       «clave.con.puntos=valor» (false · true · null · vacia · un número): lo que tiene que valer en la caché de
#                  configuración después; el valor nunca se imprime salvo esas formas (una cadena sale como «cadena»).
#   --rutas        la diferencia EXACTA de route:list: «+MÉTODO uri» / «-MÉTODO uri».
#   --programador  la diferencia EXACTA de schedule:list («+línea» / «-línea», con la forma que imprime el paso 0) y
#                  «ausente <texto>» / «presente <texto>».
# Los commits, en hexadecimal (7 a 40 cifras): se resuelven y se comparan enteros.
#
# 0. Comprobaciones previas (si una falla: «ABORTA (no se ha tocado nada)», código 1; con --solo-comprobar termina aquí):
#    producción en el commit previo y con el árbol limpio; tras git fetch, origin/main es EXACTAMENTE el commit nuevo y
#    desciende del previo; ningún commit `outreach(sends-open):` ni `voice(calls-open):`; las dos constantes cerradas en
#    los dos commits y sus ficheros sin tocar; el diff (--no-renames) coincide EXACTAMENTE con la lista; migraciones solo
#    las declaradas y AÑADIDAS; backend/config solo con --config; backend/routes solo con --rutas (console.php, con
#    --programador); de bootstrap solo app.php, con --rutas y únicamente para montar un fichero de rutas nuevo; nada de
#    composer ni vendor. En el servidor: los workers esperados en RUNNING y ninguno en BACKOFF/FATAL; las cachés de rutas
#    y de configuración, ficheros normales de www-data en 600; OPcache revalidando; las migraciones pendientes frente a
#    origin/main son EXACTAMENTE las declaradas (y ninguna del previo); el esquema de partida de cada una; route:list y
#    schedule:list legibles; sin credenciales, admin/voice/status → 401.
# 1. Respaldo en /var/backups/ironbody/cierre-lote-<fecha UTC> (700 de root; ficheros 600 de root): los commits, la lista y
#    las declaraciones, las cachés (la de configuración lleva secretos: nunca se imprime), route:list, schedule:list,
#    supervisor, las migraciones registradas, el esquema (solo estructura) de cada tabla que tocan las migraciones y la
#    definición de partida de cada objeto, el propio guion y SHA256SUMS. Todo lo que imprime queda en su registro.log.
# 2. En el ORDEN declarado: (M) cada migración, sola, con ironbody-migrate --path=<copia de root, comprobada contra git>
#    (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces), registrada y con su esquema de después; (C) el
#    código: git merge --ff-only con umask 022 y www-data lee cada fichero; (K) config:cache y route:cache como www-data
#    con umask 077, cuando el lote las declara. Si algo falla: VUELTA ATRÁS AUTOMÁTICA (código 4).
# 3. queue:restart; cada worker con pid nuevo (≤ 90 s) y, pasado un margen, dos lecturas: todos en RUNNING.
# 4. Comprobaciones posteriores (si una falla: VUELTA ATRÁS AUTOMÁTICA, código 4): HEAD = nuevo y árbol limpio; www-data
#    lee lo nuevo; los mismos workers en RUNNING; admin/voice/status sin token → 401; ninguna línea production.ERROR nueva
#    (salvo las de un 55P03 reintentado); migrate:status sin pendientes; el esquema de después de cada migración;
#    schedule:list y route:list con exactamente la diferencia declarada (ninguna si no se declara); las banderas
#    declaradas con su valor en la caché de configuración; las cachés, de www-data en 600.
# VUELTA ATRÁS (--vuelta-atras=<respaldo>, o la automática). Lee del respaldo el orden y las declaraciones. Solo si
#    producción está en el nuevo o en el previo y con el árbol limpio. Si el lote migró PRIMERO, vuelve primero el código
#    (las cachés del respaldo byte a byte, escritas por www-data, y después git checkout --detach <previo>) y después el
#    esquema; si migró DESPUÉS del código, al revés. Del esquema solo deshace lo que ESTE despliegue aplicó y el lote
#    declaró «deshacer», en orden inverso, en una transacción por migración y con lock_timeout: un CHECK vuelve a la
#    definición del respaldo (si alguna fila no la cumple, la base se niega y el CHECK ancho se queda), un disparador
#    vuelve a la definición del respaldo, una tabla nueva se borra solo vacía y sin dependencias (con sus funciones
#    propias); y en la misma transacción se quita su fila de migrations. Nunca migrate:rollback. Después queue:restart,
#    workers, route:list y schedule:list = las de antes, las cachés = las del respaldo y 401.
#    NO deshace: los datos que escribió el código nuevo; una migración «conservar», o cuya vuelta la base rechaza (queda
#    el esquema ancho, que el código previo admite: las de este cierre son de ampliar); el fichero de entorno (este guion
#    no lo lee ni lo escribe); n8n, el CRM, nginx, FPM, supervisor ni el crontab. HEAD queda suelto en <previo> y la rama
#    main del servidor en <nuevo> (como hotfix-backend.sh). Las copias de las migraciones en /var/lib/ironbody-deploy se
#    quedan (son la evidencia de lo que se ejecutó).
# No imprime secretos, ni teléfonos, ni valores de configuración salvo las formas de --config; no lee ficheros de entorno.
# Códigos: 0 bien · 1 aborta sin tocar nada · 2 uso (sin tocar nada) · 4 vuelta atrás automática hecha (producción en el
# previo) · 5 PARAR: vuelta atrás incompleta o salida inesperada a medias (DEPLOY_CIERRE_LOTES.md §6).
set -euo pipefail
export LC_ALL=C

# ── Lo propio de producción (el banco sustituye estas líneas y ninguna otra) ──────────────────────────────────────────────
APP=/var/www/api
WWW=www-data
ROOT_U=root
ROOT_G=root
API=https://api.ironbodyneiva.cloud/api
BK_RAIZ=/var/backups/ironbody
MIG_RAIZ=/var/lib/ironbody-deploy
MIGRADOR=/usr/local/bin/ironbody-migrate
PSQL=(sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody)
PG_DUMP=(sudo -u postgres pg_dump -d ironbody)
DUENO_BD=iron
APP_BD=iron_app
FPM_ETC=/etc/php
PROG_VOZ=ironbody-voice
SERVICIO_FACT=ironbody-billing-worker
NIVEL_ERROR=production.ERROR
GUION=/root/deploy-cierre-lote.sh

# ── Lo fijo del guion ─────────────────────────────────────────────────────────────────────────────────────────────────────
LOGS=$APP/backend/storage/logs
CACHE_RUTAS=bootstrap/cache/routes-v7.php
CACHE_CONFIG=bootstrap/cache/config.php
VCP=backend/app/Services/Voice/VoiceCallPolicy.php
OSG=backend/app/Services/Outreach/Run/OutreachSendGate.php
RE_SENDS='^outreach\(sends-open\):'
RE_CALLS='^voice\(calls-open\):'
DIR_MIG=backend/database/migrations
DECLS="migraciones config rutas programador"

uso() {
  echo "uso: bash $GUION <commit que sirve producción> <commit nuevo = origin/main> <lista> [--migraciones=<f> --orden=migracion-primero|codigo-primero] [--config=<f>] [--rutas=<f>] [--programador=<f>] [--solo-comprobar]"
  echo "     bash $GUION <previo> <nuevo> <lista> --vuelta-atras=<respaldo>"
}

# ── Argumentos ────────────────────────────────────────────────────────────────────────────────────────────────────────────
[ "$#" -ge 3 ] || { uso; echo "no se ha tocado nada"; exit 2; }
PREVIO=$1; NUEVO=$2; LISTA=$3; shift 3
MODO=despliegue; BK=""; ORDEN=""
F_migraciones=""; F_config=""; F_rutas=""; F_programador=""
for a in "$@"; do
  case "$a" in
    --solo-comprobar) [ "$MODO" = despliegue ] || { uso; echo "--solo-comprobar y --vuelta-atras no van juntos; no se ha tocado nada"; exit 2; }; MODO=comprobar ;;
    --vuelta-atras=?*) [ "$MODO" = despliegue ] || { uso; echo "--vuelta-atras va sola; no se ha tocado nada"; exit 2; }; MODO=vuelta; BK=${a#--vuelta-atras=} ;;
    --orden=migracion-primero|--orden=codigo-primero) [ -z "$ORDEN" ] || { echo "--orden repetido; no se ha tocado nada"; exit 2; }; ORDEN=${a#--orden=} ;;
    --migraciones=?*|--config=?*|--rutas=?*|--programador=?*)
      k=${a%%=*}; k=${k#--}; v=${a#*=}
      eval "[ -z \"\$F_$k\" ]" || { echo "--$k repetido; no se ha tocado nada"; exit 2; }
      case "$v" in /*) ;; *) v="$PWD/$v" ;; esac
      [ -f "$v" ] && [ -r "$v" ] || { echo "no se puede leer el fichero de --$k: $v; no se ha tocado nada"; exit 2; }
      eval "F_$k=\$v" ;;
    *) uso; echo "argumento desconocido: $a; no se ha tocado nada"; exit 2 ;;
  esac
done
[[ "$PREVIO" =~ ^[0-9a-f]{7,40}$ ]] && [[ "$NUEVO" =~ ^[0-9a-f]{7,40}$ ]] || { echo "los commits van en hexadecimal, de 7 a 40 cifras (git rev-parse --short=8 …); no se ha tocado nada"; exit 2; }
case "$LISTA" in /*) ;; *) LISTA="$PWD/$LISTA" ;; esac
if [ "$MODO" = vuelta ]; then
  [ -z "$ORDEN$F_migraciones$F_config$F_rutas$F_programador" ] || { uso; echo "la vuelta atrás lee las declaraciones del respaldo: no se le pasan; no se ha tocado nada"; exit 2; }
else
  if [ -n "$F_migraciones" ]; then [ -n "$ORDEN" ] || { echo "--migraciones exige --orden=migracion-primero|codigo-primero (la ficha del lote lo dice); no se ha tocado nada"; exit 2; }
  else [ -z "$ORDEN" ] || { echo "--orden solo va con --migraciones; no se ha tocado nada"; exit 2; }; fi
fi
cd /

aborta() { ETAPA=fin; echo "ABORTA (no se ha tocado nada): $*"; exit 1; }
g() { git -C "$APP" "$@"; }
corto() { g rev-parse --short=8 "$1" 2>/dev/null || echo "?"; }
artisan() { ( cd "$APP/backend" && sudo -u "$WWW" php artisan "$@" ); }
q() { "${PSQL[@]}" -c "$1"; }
PRE=""
say() { printf '%s\n' "$*"; PRE="$PRE$*"$'\n'; }
AVISOS=0
aviso() { echo "   AVISO: $*"; AVISOS=$((AVISOS + 1)); }
# Las líneas útiles de una declaración: sin \r, sin comentarios de línea entera ni líneas en blanco, sin espacios al final.
decl() { tr -d '\r' < "$1" | sed -e '/^[[:space:]]*#/d' -e 's/[[:space:]]*$//' -e '/^$/d'; }

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
sin_comentarios() { grep -vE '^[[:space:]]*(//|/\*|\*|#)' | grep -v '^[[:space:]]*$' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'; }

# ── Las declaraciones ─────────────────────────────────────────────────────────────────────────────────────────────────────
NM=0
lee_migraciones() { # <fichero> → M_NOM M_TIPO M_TABLA M_OBJ M_MARCA M_PRIV M_VUELTA (0..NM-1); el motivo si no vale
  local nom tipo tabla obj marca priv vuelta resto ant="" id='^[a-z_][a-z0-9_]{0,62}$'
  NM=0
  while read -r nom tipo tabla obj marca priv vuelta resto; do
    [ -n "${vuelta:-}" ] && [ -z "${resto:-}" ] || { echo "la línea de $nom no tiene 7 campos (nombre tipo tabla objeto marca privilegios vuelta)"; return 1; }
    [[ "$nom" =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]{1,120}$ ]] || { echo "nombre de migración no válido: $nom"; return 1; }
    [[ "$tabla" =~ $id ]] || { echo "$nom: tabla no válida ($tabla)"; return 1; }
    case "$vuelta" in deshacer|conservar) ;; *) echo "$nom: vuelta «$vuelta» (deshacer | conservar)"; return 1 ;; esac
    case "$tipo" in
      check) [[ "$obj" =~ $id ]] && [[ "$marca" =~ ^[A-Za-z0-9_:.-]{1,64}$ ]] && [ "$priv" = - ] || { echo "$nom: check exige objeto (el CHECK), una marca literal y privilegios «-»"; return 1; } ;;
      transicion) [[ "$obj" =~ $id ]] && [[ "$marca" =~ ^[a-z_][a-z0-9_]{0,62}\>[a-z_][a-z0-9_]{0,62}$ ]] && [ "$priv" = - ] || { echo "$nom: transicion exige objeto (el disparador), marca «desde>hasta» y privilegios «-»"; return 1; } ;;
      tabla) [ "$obj" = - ] && { [ "$marca" = - ] || [[ "$marca" =~ $id ]]; } && [[ "$priv" =~ ^(-|[rawdD]{1,5})$ ]] || { echo "$nom: tabla exige objeto «-», marca = prefijo de sus funciones (o «-») y privilegios en r a w d D (o «-»)"; return 1; } ;;
      *) echo "$nom: tipo «$tipo» (check | transicion | tabla)"; return 1 ;;
    esac
    [ -z "$ant" ] || [[ "$ant" < "$nom" ]] || { echo "las migraciones van en el orden de sus nombres, sin repetir ($ant antes que $nom): es el orden en que las prueban las baterías"; return 1; }
    ant=$nom
    M_NOM[NM]=$nom; M_TIPO[NM]=$tipo; M_TABLA[NM]=$tabla; M_OBJ[NM]=$obj; M_MARCA[NM]=$marca; M_PRIV[NM]=$priv; M_VUELTA[NM]=$vuelta
    NM=$((NM + 1))
  done < <(decl "$1")
  [ "$NM" -gt 0 ] || { echo "el fichero de --migraciones no declara ninguna"; return 1; }
}
valida_config() { # <fichero> → 0 si cada línea es clave=valor con las formas admitidas
  local l n=0
  while IFS= read -r l; do
    [[ "$l" =~ ^[a-z0-9_]+(\.[a-z0-9_]+)+=(false|true|null|vacia|-?[0-9]+(\.[0-9]+)?)$ ]] || { echo "línea de --config no válida: «$l» (clave.con.puntos=false|true|null|vacia|número)"; return 1; }
    n=$((n + 1))
  done < <(decl "$1")
  [ "$n" -gt 0 ] || { echo "el fichero de --config no declara ninguna bandera"; return 1; }
}
valida_rutas() { local l; while IFS= read -r l; do [[ "$l" =~ ^[+-][A-Z|]+\ [^\ ]+(\ @[^\ ]+)?$ ]] || { echo "línea de --rutas no válida: «$l» (+MÉTODO uri / -MÉTODO uri)"; return 1; }; done < <(decl "$1"); }
valida_programador() { local l; while IFS= read -r l; do [[ "$l" =~ ^([+-].+|(ausente|presente)\ .+)$ ]] || { echo "línea de --programador no válida: «$l» (+línea / -línea / ausente <texto> / presente <texto>)"; return 1; }; done < <(decl "$1"); }

# ── Lo del servidor ───────────────────────────────────────────────────────────────────────────────────────────────────────
# 13 programas de producción y el de la voz si está instalado (14 desde el 11-11-2026), como deploy-a4-regenerar-backend.sh.
workers_esperados() { local n; n=$(supervisorctl status 2>/dev/null | grep -c "^$PROG_VOZ:" || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13; }
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
reiniciados() { awk 'NR == FNR { a[$1] = $2; next } ($1 in a) && a[$1] != $2 { n++ } END { print n + 0 }' <(printf '%s\n' "$1") <(pids); }
# Tras un queue:restart: hasta 90 s a que cada worker tenga pid nuevo; después 15 s de margen (un worker que no arranca pasa
# a BACKOFF pasado startsecs) y dos lecturas separadas 8 s. Deja REIN, W1 y W2.
espera_workers() { # <pids de antes> <RUNNING esperados>
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
http() { # <ruta bajo /api> → código HTTP de un GET sin credenciales (000 si no contesta)
  local c
  c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/$1") || c=000
  echo "$c"
}
http_espera() { local i c=000; for i in 1 2 3; do c=$(http "$2"); [ "$c" = "$1" ] && break; [ "$i" = 3 ] || sleep 3; done; echo "$c"; }
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
errores_nuevos() { # <lineas_log de antes> <patrón que se descuenta, o vacío> → líneas de NIVEL_ERROR nuevas
  local f n e total=0
  for f in "$LOGS"/laravel*.log; do
    [ -f "$f" ] || continue
    n=$(printf '%s\n' "$1" | awk -v f="$f" '$1 == f {print $2}')
    if [ -n "$2" ]; then e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep "$NIVEL_ERROR" | grep -vc "$2" || true)
    else e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c "$NIVEL_ERROR" || true); fi
    total=$((total + e))
  done
  echo "$total"
}
legibles() { # <commit A> <commit B>: $WWW lee cada fichero del diff que exista ahora → 0 si todos; deja LEGIBLES «revisados ilegibles»
  local f rev=0 mal=0
  while IFS= read -r f; do
    [ -n "$f" ] && [ -e "$APP/$f" ] || continue
    rev=$((rev + 1))
    sudo -u "$WWW" test -r "$APP/$f" || { echo "   ✗ $WWW no puede leer $f ($(stat -c '%U:%G %a' "$APP/$f"))"; mal=$((mal + 1)); }
  done < <(g diff --name-only --no-renames "$1" "$2")
  LEGIBLES="$rev $mal"
  [ "$rev" -gt 0 ] && [ "$mal" = 0 ]
}
# Una caché: fichero normal (no un enlace) de www-data en 600. Si el modo no es 600, chmod COMO www-data (root no hace
# chmod en bootstrap/cache: seguiría un enlace puesto en su lugar; H-5 de SEC7).
cache_ok() { # <ruta relativa a backend>
  local p="$APP/backend/$1"
  [ -f "$p" ] && [ ! -L "$p" ] || { echo "   ✗ $1 no existe o no es un fichero normal (¿un enlace?)"; return 1; }
  [ "$(stat -c '%U' "$p")" = "$WWW" ] || { echo "   ✗ $1 es de $(stat -c '%U' "$p"), no de $WWW: los workers quedarían en BACKOFF en el siguiente arranque"; return 1; }
  if [ "$(stat -c '%a' "$p")" != 600 ]; then
    sudo -u "$WWW" chmod 600 "$p" || { echo "   ✗ $WWW no pudo dejar $1 en 600"; return 1; }
    echo "   $1 pasado a 600 (como $WWW)"
  fi
  [ "$(stat -c '%U %a' "$p")" = "$WWW 600" ]
}
# Pone en su sitio la caché del respaldo, byte a byte, escrita por www-data con umask 077 (cat > tmp && mv).
pon_cache() { # <fichero del respaldo> <ruta relativa a backend>
  cmp -s "$1" "$APP/backend/$2" && return 0
  ( cd "$APP/backend" && sudo -u "$WWW" sh -c "umask 077; cat > $2.cierre-vuelta && mv -f $2.cierre-vuelta $2" ) < "$1" && cmp -s "$1" "$APP/backend/$2"
}
# Las rutas como líneas «MÉTODO uri [@dominio]» ordenadas, o «?». Las tareas del programador como «expresión orden
# [:: descripción]» ordenadas, sin la línea de las Closure (cambia al editar routes/console.php) ni la próxima ejecución.
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
tareas_canon() {
  python3 -I -c '
import json, re, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("?")
    sys.exit(0)
out = []
for x in r:
    cmd = re.sub(r"^(Closure at: .*?):[0-9]+$", r"\1", str(x.get("command") or ""))
    d = x.get("description")
    out.append(" ".join(str(x.get("expression", "")).split()) + " " + cmd + (" :: " + str(d) if d else ""))
for l in sorted(out):
    print(l)' 2>/dev/null || echo "?"
}
diferencia() { # <antes> <después> (líneas ordenadas) → «+x» / «-x» ordenadas, una por línea
  comm -3 <(printf '%s\n' "$1" | sed '/^$/d') <(printf '%s\n' "$2" | sed '/^$/d') | awk '{ if (substr($0, 1, 1) == "\t") print "+" substr($0, 2); else print "-" $0 }' | sort
}
# Las GET que añade --rutas, sin parámetros ni dominio y bajo api/: las que se sondean sin token (401 después; 404 tras la
# vuelta atrás). Las de este cierre son todas de administración.
gets_nuevas() { decl "$1" | sed -n 's#^+GET[^ ]* \(api/[^ {]*\)$#\1#p'; }
lee_rutas() { RUTAS_JSON=$(artisan route:list --json 2>/dev/null) || RUTAS_JSON=""; RUTAS=$(printf '%s' "$RUTAS_JSON" | rutas_canon); }
lee_tareas() { TAREAS_JSON=$(artisan schedule:list --json 2>/dev/null) || TAREAS_JSON=""; TAREAS=$(printf '%s' "$TAREAS_JSON" | tareas_canon); }
# Las banderas de --config leídas de la caché de configuración, como www-data: «clave forma» (false · true · null · vacia ·
# un número · cadena · lista:N · ausente). Una cadena NUNCA se imprime: solo «cadena». Un número (o una cadena numérica,
# como los importes de config/outreach.php) solo sale si tiene como mucho 6 cifras enteras: uno más largo podría ser un
# teléfono y sale como «número largo».
banderas() { # <fichero --config> → una línea por clave, en su orden
  decl "$1" | sed 's/=.*$//' | ( cd "$APP/backend" && sudo -u "$WWW" php -r '
$c = @include "bootstrap/cache/config.php";
if (! is_array($c)) { fwrite(STDERR, "sin caché de configuración\n"); exit(3); }
foreach (file("php://stdin", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $k) {
    $v = $c; $hay = true;
    foreach (explode(".", $k) as $p) { if (is_array($v) && array_key_exists($p, $v)) { $v = $v[$p]; } else { $hay = false; break; } }
    if (! $hay) { $f = "ausente"; }
    elseif ($v === null) { $f = "null"; }
    elseif (is_bool($v)) { $f = $v ? "true" : "false"; }
    elseif ($v === "") { $f = "vacia"; }
    elseif (is_int($v) || is_float($v) || (is_string($v) && preg_match("/^-?[0-9]+(\\.[0-9]+)?\$/", $v) === 1)) {
        $s = is_float($v) ? sprintf("%.6F", $v) : (string) $v;
        if (strpos($s, ".") !== false) { $s = rtrim(rtrim($s, "0"), "."); }
        $f = preg_match("/^-?[0-9]{1,6}(\\.[0-9]+)?\$/", $s) === 1 ? $s : "número largo";
    }
    elseif (is_string($v)) { $f = "cadena"; }
    elseif (is_array($v)) { $f = "lista:" . count($v); }
    else { $f = "otro"; }
    echo $k, " ", $f, "\n";
}' )
}
banderas_esperadas() { decl "$1" | sed 's/=/ /'; }

# ── El esquema de cada migración (consultas de solo lectura con identificadores ya validados) ────────────────────────────
# estado_mig <i> → una línea con lo que se mide; clase_mig <i> <línea> → antes | despues | otro.
estado_mig() {
  local i=$1 t=${M_TABLA[$1]} o=${M_OBJ[$1]} m=${M_MARCA[$1]} d h
  case "${M_TIPO[$i]}" in
    check)
      q "SELECT count(*) || ' ' || coalesce(bool_and(c.convalidated), false) || ' ' || coalesce(bool_or(strpos(pg_get_constraintdef(c.oid), '$m') > 0), false)
         FROM pg_constraint c JOIN pg_class r ON r.oid = c.conrelid JOIN pg_namespace n ON n.oid = r.relnamespace
         WHERE n.nspname = 'public' AND r.relname = '$t' AND c.conname = '$o' AND c.contype = 'c'" ;;
    transicion)
      d=${m%%>*}; h=${m#*>}
      q "SELECT count(*) || ' ' || coalesce(bool_and(tg.tgenabled = 'O'), false) || ' ' || coalesce(string_agg(DISTINCT p.proname, ','), '-')
           || ' ' || (SELECT count(*) FROM pg_trigger x JOIN pg_class xr ON xr.oid = x.tgrelid JOIN pg_namespace xn ON xn.oid = xr.relnamespace
                      WHERE NOT x.tgisinternal AND xn.nspname = 'public' AND xr.relname = '$t')
           || ' ' || coalesce(bool_or(EXISTS (SELECT 1 FROM regexp_split_to_table(pg_get_triggerdef(tg.oid), '\(old\.') AS trozo
                                             WHERE trozo ~ '^[a-z_]+\)::text = ''$d''::text\)' AND strpos(trozo, '''$h''') > 0)), false)
         FROM pg_trigger tg JOIN pg_class r ON r.oid = tg.tgrelid JOIN pg_namespace n ON n.oid = r.relnamespace JOIN pg_proc p ON p.oid = tg.tgfoid
         WHERE NOT tg.tgisinternal AND n.nspname = 'public' AND r.relname = '$t' AND tg.tgname = '$o'" ;;
    tabla)
      q "SELECT CASE WHEN to_regclass('public.$t') IS NULL THEN 'ausente - -' ELSE 'presente '
           || (SELECT ro.rolname FROM pg_class c JOIN pg_roles ro ON ro.oid = c.relowner WHERE c.oid = to_regclass('public.$t')) || ' '
           || coalesce(nullif(concat(CASE WHEN has_table_privilege('$APP_BD', 'public.$t', 'SELECT') THEN 'r' END,
                                     CASE WHEN has_table_privilege('$APP_BD', 'public.$t', 'INSERT') THEN 'a' END,
                                     CASE WHEN has_table_privilege('$APP_BD', 'public.$t', 'UPDATE') THEN 'w' END,
                                     CASE WHEN has_table_privilege('$APP_BD', 'public.$t', 'DELETE') THEN 'd' END,
                                     CASE WHEN has_table_privilege('$APP_BD', 'public.$t', 'TRUNCATE') THEN 'D' END), ''), '-') END
           || ' ' || (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname = 'public' AND '$m' <> '-' AND left(p.proname, length('$m')) = '$m')" ;;
  esac
}
clase_mig() { # <i> <estado> [<estado de antes, del respaldo>]
  local i=$1 e=$2 a=${3:-}
  case "${M_TIPO[$i]}" in
    check) case "$e" in "1 true false") echo antes ;; "1 true true") echo despues ;; *) echo otro ;; esac ;;
    transicion)
      # «1 true <función> <disparadores de la tabla> false|true»: después, la misma función y el mismo número que antes.
      case "$e" in
        "1 true "*" false") echo antes ;;
        "1 true "*" true") if [ -z "$a" ] || [ "${e% *}" = "${a% *}" ]; then echo despues; else echo otro; fi ;;
        *) echo otro ;;
      esac ;;
    tabla)
      case "$e" in
        "ausente - - 0") echo antes ;;
        "presente $DUENO_BD ${M_PRIV[$i]} "*) if [ "${M_MARCA[$i]}" = - ] || [ "${e##* }" -gt 0 ]; then echo despues; else echo otro; fi ;;
        *) echo otro ;;
      esac ;;
  esac
}
def_mig() { # <i> → la definición exacta del objeto (para el respaldo y la vuelta atrás), en una línea
  local i=$1 t=${M_TABLA[$1]} o=${M_OBJ[$1]} m=${M_MARCA[$1]}
  case "${M_TIPO[$i]}" in
    check) q "SELECT pg_get_constraintdef(c.oid) FROM pg_constraint c JOIN pg_class r ON r.oid = c.conrelid JOIN pg_namespace n ON n.oid = r.relnamespace WHERE n.nspname = 'public' AND r.relname = '$t' AND c.conname = '$o' AND c.contype = 'c'" ;;
    transicion) q "SELECT pg_get_triggerdef(tg.oid) FROM pg_trigger tg JOIN pg_class r ON r.oid = tg.tgrelid JOIN pg_namespace n ON n.oid = r.relnamespace WHERE NOT tg.tgisinternal AND n.nspname = 'public' AND r.relname = '$t' AND tg.tgname = '$o'" ;;
    tabla) q "SELECT coalesce(string_agg(p.proname, ' ' ORDER BY p.proname), '') FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND '$m' <> '-' AND left(p.proname, length('$m')) = '$m'" ;;
  esac
}
registrada() { [ "$(q "SELECT count(*) FROM migrations WHERE migration = '$1'")" = 1 ]; }
# Una definición sin cast, sin paréntesis y con los espacios juntos: para comparar la restaurada con la del respaldo.
normaliza_def() { sed -E 's/::[a-z_ ]+(\[\])?//g; s/[()]//g; s/[[:space:]]+/ /g'; }
nombre_corto() { printf '%s' "$1" | cut -c1-60; }

# La sentencia que deshace la migración i a partir de lo que guardó el respaldo, en una transacción (la escribe en stdout).
sql_deshacer() { # <i> <directorio del respaldo>
  local i=$1 bk=$2 t=${M_TABLA[$1]} o=${M_OBJ[$1]} m=${M_MARCA[$1]} n=${M_NOM[$1]} def ant
  def=$(cat "$bk/esquema/$i.def")
  echo "BEGIN;"
  echo "SET LOCAL lock_timeout = '5s';"
  case "${M_TIPO[$i]}" in
    check)
      case "$def" in "CHECK ("*) ;; *) return 1 ;; esac
      echo "ALTER TABLE public.$t DROP CONSTRAINT $o, ADD CONSTRAINT $o $def;" ;;
    transicion)
      case "$def" in "CREATE TRIGGER $o "*) ;; *) return 1 ;; esac
      echo "CREATE OR REPLACE TRIGGER ${def#CREATE TRIGGER };" ;;
    tabla)
      ant=$(printf '%s\n' $def | sed "s/.*/'&'/" | paste -sd , -)
      echo "LOCK TABLE public.$t IN ACCESS EXCLUSIVE MODE;"
      echo "DO \$cierre\$ BEGIN IF EXISTS (SELECT 1 FROM public.$t) THEN RAISE EXCEPTION 'cierre: public.$t tiene filas: la tabla no se borra'; END IF; END \$cierre\$;"
      echo "DROP TABLE public.$t;"
      if [ "$m" != - ]; then
        echo "SELECT format('DROP FUNCTION %s(%s);', p.oid::regproc, pg_get_function_identity_arguments(p.oid)) FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public' AND left(p.proname, length('$m')) = '$m'${ant:+ AND p.proname NOT IN ($ant)} \\gexec"
      fi ;;
  esac
  echo "DELETE FROM migrations WHERE migration = '$n';"
  echo "COMMIT;"
}

# ── La vuelta atrás (la automática y la de --vuelta-atras) ────────────────────────────────────────────────────────────────
# Usa P, N, BK, RUN0 y las declaraciones LEÍDAS DEL RESPALDO. 0 si producción queda en P con todo lo de antes (salvo el
# esquema que se conserva, que se dice). Se ejecuta en un `if`: `set -e` no corta aquí dentro, cada paso se comprueba.
VA_CONSERVADO=""
va_codigo() {
  local h ok=1
  h=$(g rev-parse HEAD) || return 1
  if pon_cache "$BK/bootstrap-cache/routes-v7.php" "$CACHE_RUTAS" && pon_cache "$BK/bootstrap-cache/config.php" "$CACHE_CONFIG"; then
    echo "   · cachés de rutas y de configuración: las del respaldo, byte a byte (escritas por $WWW; root no escribe en bootstrap/cache)"
  else
    echo "   ✗ las cachés del respaldo no se pudieron poner: se regeneran después del código"; ok=2
  fi
  if [ "$h" = "$N" ]; then
    ( cd "$APP" && umask 022 && git checkout -q --detach "$P" ) || { echo "   ✗ git checkout --detach $(corto "$P") falló"; return 1; }
  fi
  [ "$(g rev-parse HEAD)" = "$P" ] || { echo "   ✗ HEAD no quedó en $(corto "$P")"; return 1; }
  if legibles "$P" "$N"; then echo "   · código: HEAD=$(corto HEAD) (checkout --detach, umask 022) · $WWW lee los ${LEGIBLES%% *} ficheros del diff"
  else echo "   ✗ código: HEAD=$(corto HEAD), pero hay ficheros que $WWW no lee (arriba)"; return 1; fi
  if [ "$ok" = 2 ]; then
    ( cd "$APP/backend" && sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' ) || { echo "   ✗ config:cache o route:cache falló"; return 1; }
    echo "   · cachés regeneradas con el código de $(corto "$P") (como $WWW, umask 077)"
  fi
  cache_ok "$CACHE_RUTAS" && cache_ok "$CACHE_CONFIG"
}
va_esquema() {
  local j e d k intento sal igual ok=0 cuenta=0
  for ((j = NM - 1; j >= 0; j--)); do
    e=$(estado_mig "$j" 2>/dev/null) || e="?"
    if grep -qxF "${M_NOM[$j]}" "$BK/migraciones-registradas-antes.txt"; then echo "   · $(nombre_corto "${M_NOM[$j]}"): ya estaba antes de este despliegue: no se toca"; continue; fi
    if ! registrada "${M_NOM[$j]}"; then
      if [ "$(clase_mig "$j" "$e")" = antes ]; then echo "   · $(nombre_corto "${M_NOM[$j]}"): no llegó a aplicarse (esquema de antes, sin registrar)"; continue; fi
      echo "   ✗ $(nombre_corto "${M_NOM[$j]}"): sin registrar y con el esquema [$e], que no es el de antes"; ok=1; continue
    fi
    if [ "${M_VUELTA[$j]}" = conservar ]; then echo "   · $(nombre_corto "${M_NOM[$j]}"): SE CONSERVA (el lote lo declara «conservar»)"; VA_CONSERVADO="$VA_CONSERVADO ${M_NOM[$j]}"; continue; fi
    sql_deshacer "$j" "$BK" > "$BK/vuelta-$j.sql" || { echo "   ✗ $(nombre_corto "${M_NOM[$j]}"): la definición del respaldo no tiene la forma esperada; no se deshace"; ok=1; continue; }
    intento=0
    while :; do
      intento=$((intento + 1))
      if sal=$("${PSQL[@]}" < "$BK/vuelta-$j.sql" 2>&1); then break; fi
      case "$sal" in *55P03*|*"lock timeout"*|*"canceling statement due to lock timeout"*) [ "$intento" -lt 3 ] && { echo "   · intento $intento: 55P03 (otra sesión con la tabla): reintento en 15 s"; sleep 15; continue; } ;; esac
      break
    done
    e=$(estado_mig "$j" 2>/dev/null) || e="?"
    d=$(def_mig "$j" 2>/dev/null) || d="?"
    # Al recrear un objeto desde su pg_get_*def, PostgreSQL puede reescribir los cast (ARRAY[…]::text[] pasa a
    # ARRAY[(…)::text, …]: medido con vc_status_forward en 14.24): equivale, pero no es igual byte a byte.
    igual=no
    if [ "${M_TIPO[$j]}" = tabla ]; then igual="tabla y funciones borradas"
    elif [ "$d" = "$(cat "$BK/esquema/$j.def")" ]; then igual="la definición del respaldo, byte a byte"
    elif [ "$(printf '%s\n' "$d" | normaliza_def)" = "$(normaliza_def < "$BK/esquema/$j.def")" ]; then igual="la definición del respaldo, equivalente: PostgreSQL reescribe los cast al recrearla"
    fi
    if ! registrada "${M_NOM[$j]}" && [ "$(clase_mig "$j" "$e")" = antes ] && [ "$igual" != no ]; then
      echo "   · $(nombre_corto "${M_NOM[$j]}"): DESHECHA (${M_TIPO[$j]} ${M_TABLA[$j]}: $igual; fuera de migrations) · esquema [$e]"; cuenta=$((cuenta + 1))
    elif registrada "${M_NOM[$j]}" && [ "$(clase_mig "$j" "$e")" = despues ]; then
      k=$(printf '%s\n' "$sal" | grep -E 'ERROR|cierre:' | head -1 | cut -c1-200)
      echo "   · $(nombre_corto "${M_NOM[$j]}"): NO SE DESHACE, la base se negó y no cambió nada [${k:-sin detalle}]: el esquema de después se queda (el código previo lo admite)"
      VA_CONSERVADO="$VA_CONSERVADO ${M_NOM[$j]}"
    else
      echo "   ✗ $(nombre_corto "${M_NOM[$j]}"): tras la vuelta, esquema [$e] y registrada $(registrada "${M_NOM[$j]}" && echo sí || echo no): ni el de antes ni el de después"; ok=1
    fi
  done
  [ "$ok" = 0 ]
}
vuelta_atras_ejecuta() {
  local h ok=1 c r t
  h=$(g rev-parse HEAD) || return 1
  case "$h" in "$N"|"$P") ;; *) echo "   ✗ producción está en $(corto HEAD), ni en el nuevo ni en el previo: otra sesión desplegó; no se toca"; return 1 ;; esac
  [ -z "$(g status --porcelain --untracked-files=no)" ] || { echo "   ✗ el árbol desplegado tiene cambios sin confirmar: no se toca"; return 1; }
  if [ "$NM" -gt 0 ] && [ "$(cat "$BK/orden.txt")" = codigo-primero ]; then
    echo "   a. esquema (el lote migró DESPUÉS del código: el esquema vuelve antes que el código):"
    va_esquema || ok=0
    echo "   b. cachés y código:"
    va_codigo || return 1
  else
    echo "   a. cachés y código$([ "$NM" -gt 0 ] && echo " (el lote migró ANTES del código: el código vuelve antes que el esquema)"):"
    va_codigo || return 1
    if [ "$NM" -gt 0 ]; then echo "   b. esquema:"; va_esquema || ok=0; fi
  fi
  r=$(pids)
  ( cd "$APP/backend" && sudo -u "$WWW" php artisan queue:restart -q ) || { echo "   ✗ queue:restart falló"; ok=0; }
  espera_workers "$r" "$RUN0"
  echo "   c. workers (RUNNING · BACKOFF/FATAL): [$W1] y [$W2] · con pid nuevo $REIN de $RUN0"
  { [ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ]; } || { echo "   ✗ workers: esperado [$RUN0 0] las dos veces"; ok=0; }
  lee_rutas; lee_tareas
  [ "$RUTAS" != "?" ] && [ "$RUTAS" = "$(rutas_canon < "$BK/rutas-antes.json")" ] && r=igual || { r="DISTINTA [$(diferencia "$(rutas_canon < "$BK/rutas-antes.json")" "$RUTAS" | sed -n 1,4p | paste -sd '|' -)]"; ok=0; }
  [ "$TAREAS" != "?" ] && [ "$TAREAS" = "$(tareas_canon < "$BK/programador-antes.json")" ] && t=igual || { t="DISTINTO [$(diferencia "$(tareas_canon < "$BK/programador-antes.json")" "$TAREAS" | sed -n 1,4p | paste -sd '|' -)]"; ok=0; }
  c=$(http_espera 401 admin/voice/status)
  echo "   d. route:list $r que la de antes · schedule:list $t · admin/voice/status sin token → $c (esperado 401)"
  [ "$c" = 401 ] || ok=0
  if [ -s "$BK/decl/rutas" ]; then
    while IFS= read -r u; do
      c=$(http_espera 404 "${u#api/}")
      echo "      GET $u sin token → $c (esperado 404: la ruta nueva ya no existe)"
      [ "$c" = 404 ] || ok=0
    done < <(gets_nuevas "$BK/decl/rutas")
  fi
  cmp -s "$BK/bootstrap-cache/config.php" "$APP/backend/$CACHE_CONFIG" && cmp -s "$BK/bootstrap-cache/routes-v7.php" "$APP/backend/$CACHE_RUTAS" \
    || { echo "   ✗ las cachés no son las del respaldo"; ok=0; }
  [ "$ok" = 1 ]
}
# Los `grep -c … || true` de este guion: grep -c imprime 0 y sale con 1 cuando no casa nada; con pipefail cortaría. El
# recuento es lo que se usa. Igual con supervisorctl status (sale distinto de 0 si algún programa no está en RUNNING: lo
# que importa son sus líneas) y systemctl is-active (informativo).
cuenta_lineas() { printf '%s\n' "$1" | grep -c . || true; }
# migrate:status --pending: solo vale el «No pending migrations» explícito (si artisan falla, su texto no puede pasar por
# «sin pendientes»); con pendientes sale distinto de 0, y por eso se lee el texto y no el código. Deja ST_RESUMEN.
sin_pendientes() { # <salida>
  ST_RESUMEN=$(printf '%s\n' "$1" | sed 's/^[[:space:]]*//' | grep -v '^$' | sed -n 1,4p | cut -c1-120 | paste -sd '|' -)
  case "$1" in *"No pending migrations"*) return 0 ;; esac
  return 1
}
orden_vuelta() { echo "   vuelta atrás: bash $GUION $PREVIO $NUEVO $LISTA --vuelta-atras=$BK"; }

ETAPA=previa
al_salir() {
  local rc=$?
  case "$ETAPA" in previa|fin) return 0 ;; esac
  if [ "$ETAPA" = respaldo ]; then
    echo "SALIDA INESPERADA durante el respaldo (código $rc): producción sin tocar; el respaldo ${BK:-—} puede estar incompleto"
    exit 1
  fi
  echo "SALIDA INESPERADA en la etapa «$ETAPA» (código $rc): PARAR. Producción puede haber quedado a medias (HEAD=$(corto HEAD))."
  echo "   vuelta atrás: bash $GUION $PREVIO $NUEVO $LISTA --vuelta-atras=${BK:-<respaldo>} (DEPLOY_CIERRE_LOTES.md §6)"
  exit 5
}
trap al_salir EXIT

# ── Modo vuelta atrás (--vuelta-atras=<respaldo>) ────────────────────────────────────────────────────────────────────────
if [ "$MODO" = vuelta ]; then
  [ "$(id -un)" = "$ROOT_U" ] || { echo "se ejecuta como $ROOT_U en el servidor; no se ha tocado nada"; exit 2; }
  { [ -d "$BK" ] && [ ! -L "$BK" ] && [ "$(stat -c '%U %a' "$BK")" = "$ROOT_U 700" ]; } || { echo "el respaldo $BK no existe o no es un directorio 700 de $ROOT_U; no se ha tocado nada"; exit 2; }
  for f in api-commit.txt api-commit-nuevo.txt orden.txt rutas-antes.json programador-antes.json migraciones-registradas-antes.txt bootstrap-cache/routes-v7.php bootstrap-cache/config.php SHA256SUMS; do
    [ -s "$BK/$f" ] || { echo "falta $BK/$f; no se ha tocado nada"; exit 2; }
  done
  ( cd "$BK" && sha256sum -c --status SHA256SUMS ) || { echo "el respaldo no coincide con su SHA256SUMS; no se ha tocado nada"; exit 2; }
  P=$(cat "$BK/api-commit.txt"); N=$(cat "$BK/api-commit-nuevo.txt")
  case "$P" in "$PREVIO"*) ;; *) echo "el respaldo es del commit previo $P, no de $PREVIO; no se ha tocado nada"; exit 2 ;; esac
  case "$N" in "$NUEVO"*) ;; *) echo "el respaldo es del commit nuevo $N, no de $NUEVO; no se ha tocado nada"; exit 2 ;; esac
  if [ -s "$BK/decl/migraciones" ]; then m=$(lee_migraciones "$BK/decl/migraciones") || { echo "las migraciones del respaldo no se leen: $m; no se ha tocado nada"; exit 2; }; lee_migraciones "$BK/decl/migraciones" > /dev/null; fi
  LOG="$BK/vuelta-atras-$(date -u +%Y%m%d-%H%M%S).log"
  ( umask 077; : > "$LOG" ) || { echo "no se pudo crear $LOG; no se ha tocado nada"; exit 2; }
  exec > >(tee -a "$LOG") 2>&1
  echo "== deploy-cierre-lote.sh · VUELTA ATRÁS · $(date -u +%Y-%m-%dT%H:%M:%SZ) · de $(corto "$N") a $(corto "$P") · orden del lote: $(cat "$BK/orden.txt") · $NM migración(es) declarada(s) · respaldo $BK · registro $LOG"
  RUN0=$(workers_esperados)
  ETAPA=vuelta
  if vuelta_atras_ejecuta; then
    ETAPA=fin; echo "VUELTA ATRÁS HECHA: producción en $(corto "$P"), con las cachés, las rutas y el programador de antes${VA_CONSERVADO:+; SE CONSERVA el esquema de:$VA_CONSERVADO}"; exit 0
  fi
  ETAPA=fin; echo "VUELTA ATRÁS CON DESVÍOS: PARAR y revisar a mano (DEPLOY_CIERRE_LOTES.md §6)"; exit 5
fi

# ── 0. Comprobaciones previas ─────────────────────────────────────────────────────────────────────────────────────────────
[ "$(id -un)" = "$ROOT_U" ] || aborta "se ejecuta como $ROOT_U en el servidor"
say "== deploy-cierre-lote.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · modo $MODO · PREVIO=$PREVIO · NUEVO=$NUEVO · lista $LISTA"
say "   declaraciones: migraciones ${F_migraciones:-—}${ORDEN:+ (orden $ORDEN)} · config ${F_config:-—} · rutas ${F_rutas:-—} · programador ${F_programador:-—}"
[ -r "$LISTA" ] && [ -f "$LISTA" ] || aborta "no se puede leer la lista $LISTA"
if [ -n "$F_migraciones" ]; then m=$(lee_migraciones "$F_migraciones") || aborta "--migraciones: $m"; lee_migraciones "$F_migraciones" > /dev/null; fi
if [ -n "$F_config" ]; then m=$(valida_config "$F_config") || aborta "--config: $m"; fi
if [ -n "$F_rutas" ]; then m=$(valida_rutas "$F_rutas") || aborta "--rutas: $m"; fi
if [ -n "$F_programador" ]; then m=$(valida_programador "$F_programador") || aborta "--programador: $m"; fi
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
for lado in "$P" "$N"; do
  e=$(estado_constante "$lado" "$VCP" CALLS_ENABLED_IN_CODE); [ "$e" = cerrada ] || aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE en $(corto "$lado"): $e (este guion solo despliega con las llamadas cerradas en los dos lados)"
  e=$(estado_constante "$lado" "$OSG" SENDS_ENABLED_IN_CODE); [ "$e" = cerrada ] || aborta "OutreachSendGate::SENDS_ENABLED_IN_CODE en $(corto "$lado"): $e (este guion solo despliega con los envíos cerrados en los dos lados)"
done
# La lista: EXACTAMENTE el diff (--no-renames: un renombrado lleva el nombre viejo y el nuevo), línea a línea.
CAMBIADOS=$(g diff --name-only --no-renames "$P" "$N")
DIFF_ORD=$(printf '%s\n' "$CAMBIADOS" | sed '/^$/d' | sort)
LISTA_ORD=$(tr -d '\r' < "$LISTA" | sed '/^[[:space:]]*$/d' | sort)
if [ "$DIFF_ORD" != "$LISTA_ORD" ]; then
  FALTAN=$(comm -23 <(printf '%s\n' "$DIFF_ORD") <(printf '%s\n' "$LISTA_ORD"))
  SOBRAN=$(comm -13 <(printf '%s\n' "$DIFF_ORD") <(printf '%s\n' "$LISTA_ORD"))
  aborta "la lista no coincide EXACTAMENTE con git diff --name-only --no-renames $(corto "$P") $(corto "$N"): $(cuenta_lineas "$FALTAN") en el diff que la lista no trae [$(printf '%s\n' "$FALTAN" | sed -n 1,5p | paste -sd ' ' -)] · $(cuenta_lineas "$SOBRAN") en la lista que el diff no trae (o repetidas) [$(printf '%s\n' "$SOBRAN" | sed -n 1,5p | paste -sd ' ' -)]"
fi
NF_TOTAL=$(cuenta_lineas "$DIFF_ORD")
# Las zonas: cada una solo con su declaración.
ESTADOS=$(g diff --name-status --no-renames "$P" "$N")
RUTAS_NUEVAS=""; HAY_CONFIG=0; HAY_RUTAS=0; HAY_CONSOLA=0; HAY_BOOT=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  case "$f" in
    "$VCP"|"$OSG") aborta "el diff trae $f: las constantes de llamadas y de envíos no se tocan por este guion" ;;
    "$DIR_MIG"/*.php)
      b=${f#"$DIR_MIG"/}; b=${b%.php}
      [ "$(printf '%s\n' "$ESTADOS" | awk -F '\t' -v f="$f" '$2 == f {print $1}')" = A ] || aborta "el diff MODIFICA o BORRA $f: una migración ya desplegada no se toca (y nunca migrate:rollback)"
      found=0; for ((i = 0; i < NM; i++)); do [ "${M_NOM[$i]}" = "$b" ] && found=1; done
      [ "$found" = 1 ] || aborta "el diff trae la migración $b y no está en --migraciones: una migración no declarada no se ejecuta (su ficha dice tipo, comprobación y vuelta)" ;;
    backend/database/*) aborta "el diff trae $f: de backend/database/ solo van migraciones declaradas" ;;
    backend/composer.*|composer.*|backend/vendor/*) aborta "el diff trae $f: las dependencias (composer, vendor) no van por este guion" ;;
    backend/config/*) [ -n "$F_config" ] || aborta "el diff trae $f y no va --config: la configuración necesita config:cache y la comprobación de sus banderas"; HAY_CONFIG=1 ;;
    backend/routes/console.php) [ -n "$F_programador" ] || aborta "el diff trae $f y no va --programador: el programador cambia y hay que declarar cómo"; HAY_CONSOLA=1 ;;
    backend/routes/*) [ -n "$F_rutas" ] || aborta "el diff trae $f y no va --rutas: las rutas HTTP necesitan route:cache y la diferencia declarada"; HAY_RUTAS=1
      [ "$(printf '%s\n' "$ESTADOS" | awk -F '\t' -v f="$f" '$2 == f {print $1}')" = A ] && RUTAS_NUEVAS="$RUTAS_NUEVAS ${f#backend/}" ;;
    backend/bootstrap/app.php) [ -n "$F_rutas" ] || aborta "el diff trae $f y no va --rutas: bootstrap/app.php solo cambia aquí para montar un fichero de rutas nuevo"; HAY_BOOT=1 ;;
    backend/bootstrap/*) aborta "el diff trae $f: el arranque de la aplicación (bootstrap/) no va por este guion (salvo app.php para montar rutas)" ;;
  esac
done <<< "$CAMBIADOS"
for ((i = 0; i < NM; i++)); do
  grep -qxF "$DIR_MIG/${M_NOM[$i]}.php" <<< "$CAMBIADOS" || aborta "--migraciones declara ${M_NOM[$i]} y el diff no la trae"
done
# bootstrap/app.php: no quita código, y lo único que añade es el montaje «Route::middleware('api')->prefix('api')->group(
# base_path('routes/<fichero nuevo del diff>'))», como el de routes/voice.php; lo demás, comentarios.
if [ "$HAY_BOOT" = 1 ]; then
  CUERPO=$(g diff -U0 --no-renames "$P" "$N" -- backend/bootstrap/app.php | sed -n '/^@@ /,$p' | grep -v '^@@ ' || true)
  QUITADAS=$(printf '%s\n' "$CUERPO" | sed -n 's/^-//p' | sin_comentarios || true)
  ANADIDAS=$(printf '%s\n' "$CUERPO" | sed -n 's/^+//p' | sin_comentarios || true)
  [ -z "$QUITADAS" ] || aborta "backend/bootstrap/app.php quita código: [$(printf '%s\n' "$QUITADAS" | sed -n 1,3p | paste -sd '|' -)]"
  nm=0; np=0; ng=0
  while IFS= read -r l; do
    [ -n "$l" ] || continue
    case "$l" in
      "Route::middleware('api')") nm=$((nm + 1)) ;;
      "->prefix('api')") np=$((np + 1)) ;;
      "->group(base_path('routes/"*"'));")
        r=${l#"->group(base_path('"}; r=${r%"'));"}
        case " $RUTAS_NUEVAS " in *" $r "*) ng=$((ng + 1)) ;; *) aborta "backend/bootstrap/app.php monta $r, que no es un fichero de rutas NUEVO de este diff" ;; esac ;;
      *) aborta "backend/bootstrap/app.php añade código que no es el montaje de un fichero de rutas: «$l»" ;;
    esac
  done <<< "$ANADIDAS"
  [ "$ng" -gt 0 ] && [ "$nm" = "$ng" ] && [ "$np" = "$ng" ] || aborta "backend/bootstrap/app.php: el montaje no está completo (middleware $nm · prefix $np · group $ng)"
fi
cuenta() { printf '%s\n' "$DIFF_ORD" | grep -cE "$1" || true; }
say "   producción $(corto "$P") limpia · origin/main $(corto "$N") · $K commit(s) · $NF_TOTAL ficheros = la lista: $(cuenta '^backend/app/') de backend/app · $(cuenta '^backend/config/') de config · $(cuenta '^backend/routes/') de routes · $(cuenta "^$DIR_MIG/") migración(es) · $(cuenta '^backend/bootstrap/') de bootstrap · $(cuenta '^backend/tests/') de tests · $(cuenta '^backend/docs/') de docs · $(cuenta '^(backend/(app|config|routes|database|bootstrap|tests|docs)/)' | awk -v t="$NF_TOTAL" '{print t - $1}') otros"
say "   ni composer, ni vendor; migraciones solo las declaradas y añadidas; config $([ "$HAY_CONFIG" = 1 ] && echo "con --config" || echo "sin cambios") · rutas HTTP $([ "$HAY_RUTAS$HAY_BOOT" != 00 ] && echo "con --rutas${RUTAS_NUEVAS:+ (fichero nuevo:$RUTAS_NUEVAS, montado en bootstrap/app.php)}" || echo "sin cambios") · programador $([ "$HAY_CONSOLA" = 1 ] && echo "con --programador" || echo "sin cambios en routes/console.php")"
say "   constantes de llamadas (VoiceCallPolicy) y de envíos (OutreachSendGate): cerradas en $(corto "$P") y en $(corto "$N"); los dos ficheros sin cambios"
while IFS= read -r l; do if [ -n "$l" ]; then say "      ${l:0:8} $(printf '%s' "${l#* }" | cut -c1-110)"; fi; done <<< "$(printf '%s\n' "$COMMITS" | sed -n 1,12p)"
[ "$K" -le 12 ] || say "      … y $((K - 12)) commit(s) más"

# El servidor: workers, cachés, OPcache, base, rutas, programador, HTTP y el registro de errores.
RUN0=$(workers_esperados)
SUP0=$(supervisorctl status 2>/dev/null || true)
R0=$(printf '%s\n' "$SUP0" | grep -c RUNNING || true)
M0=$(printf '%s\n' "$SUP0" | grep -cE 'BACKOFF|FATAL' || true)
[ "$M0" = 0 ] || aborta "workers en BACKOFF o FATAL antes de empezar ($M0): no se despliega sobre un sistema degradado"
[ "$R0" = "$RUN0" ] || aborta "workers RUNNING antes de empezar: $R0 (esperados $RUN0)"
FACT0=$(systemctl is-active "$SERVICIO_FACT" 2>/dev/null || true)
for f in "$CACHE_RUTAS" "$CACHE_CONFIG"; do
  p="$APP/backend/$f"
  { [ -f "$p" ] && [ ! -L "$p" ]; } || aborta "$f no existe o no es un fichero normal: este guion parte de producción CON las rutas y la configuración en caché"
  [ "$(stat -c '%U %a' "$p")" = "$WWW 600" ] || aborta "$f es $(stat -c '%U %a' "$p") (esperado $WWW 600): aclararlo antes (un chmod 600 sin chown deja los workers en BACKOFF en el siguiente arranque)"
done
OPC=$(grep -rhE '^[[:space:]]*opcache\.(validate_timestamps|revalidate_freq)' "$FPM_ETC"/*/fpm/ 2>/dev/null | tr -d ' ' | sort -u | paste -sd ' ' - || true)
case " $OPC " in *"validate_timestamps=0"*|*"validate_timestamps=Off"*|*"validate_timestamps=off"*|*"validate_timestamps=false"*)
  aborta "el FPM tiene la revalidación de OPcache apagada ($OPC): sin recargar php-fpm no vería el código nuevo; recargarlo es otra orden" ;;
esac
say "   workers $R0 RUNNING (esperados $RUN0) · 0 BACKOFF/FATAL · $SERVICIO_FACT ${FACT0:-?} · cachés de rutas y de configuración: $WWW 600 · OPcache del FPM: ${OPC:-sin líneas (valores por defecto)}"
# La base: lo pendiente frente a origin/main tiene que ser EXACTAMENTE lo declarado (y nada pendiente del previo).
REG0=$(q "SELECT migration FROM migrations ORDER BY migration") || aborta "no se pudo leer la tabla migrations (psql)"
REG0=$(printf '%s\n' "$REG0" | sort -u)
en_arbol() { g ls-tree --name-only "$1" -- "$DIR_MIG/" | sed -n "s#^$DIR_MIG/\\([^/]*\\)\\.php\$#\\1#p" | sort -u; }
PEND_P=$(comm -23 <(en_arbol "$P") <(printf '%s\n' "$REG0"))
[ -z "$PEND_P" ] || aborta "la base tiene migraciones del PROPIO previo sin aplicar [$(printf '%s\n' "$PEND_P" | paste -sd ' ' -)]: producción no está al día; aclararlo antes"
PEND_N=$(comm -23 <(en_arbol "$N") <(printf '%s\n' "$REG0") | paste -sd ' ' -)
ESPERADAS=""; YA=""
for ((i = 0; i < NM; i++)); do
  e=$(estado_mig "$i") || aborta "no se pudo leer el esquema de ${M_NOM[$i]} (psql)"
  c=$(clase_mig "$i" "$e")
  if grep -qxF "${M_NOM[$i]}" <<< "$REG0"; then
    [ "$c" = despues ] || aborta "${M_NOM[$i]} ya está registrada pero su esquema es [$e] ($c), no el de después: PARAR y avisar a database-specialist"
    YA="$YA ${M_NOM[$i]}"
  else
    [ "$c" = antes ] || aborta "${M_NOM[$i]}: el esquema de partida es [$e] ($c), no el esperado antes de migrar (${M_TIPO[$i]} ${M_TABLA[$i]} ${M_OBJ[$i]}): PARAR y avisar a database-specialist"
    ESPERADAS="$ESPERADAS ${M_NOM[$i]}"
  fi
  M_E0[i]=$e
  say "   migración $(nombre_corto "${M_NOM[$i]}") · ${M_TIPO[$i]} ${M_TABLA[$i]} ${M_OBJ[$i]} ${M_MARCA[$i]} · esquema [$e] = $c · vuelta: ${M_VUELTA[$i]}"
  if [ "${M_TIPO[$i]}" = tabla ] && [ "$c" = antes ]; then
    # Informativo: lo que la tabla nueva heredará del dueño (memoria «migraciones-produccion-rol-dueno»); el paso 4 exige
    # después exactamente lo declarado, ya con los REVOKE que haga la propia migración.
    dp=$(q "SELECT coalesce(string_agg(DISTINCT a.privilege_type, ',' ORDER BY a.privilege_type), '-') FROM pg_default_acl d CROSS JOIN LATERAL aclexplode(d.defaclacl) a JOIN pg_roles o ON o.oid = d.defaclrole JOIN pg_roles r ON r.oid = a.grantee WHERE o.rolname = '$DUENO_BD' AND r.rolname = '$APP_BD' AND d.defaclobjtype = 'r'" 2>/dev/null) || dp="?"
    say "      privilegios por defecto de $DUENO_BD para $APP_BD en tablas nuevas: [$dp] · después se exige «${M_PRIV[$i]}» (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE)"
  fi
done
[ "$PEND_N" = "${ESPERADAS# }" ] || aborta "migraciones pendientes frente a origin/main [$PEND_N], distintas de las declaradas que faltan [${ESPERADAS# }]: lo ajeno se despliega antes por su vía"
[ -z "$YA" ] || say "   ya registradas (reanudación; no se vuelven a ejecutar):$YA"
sin_pendientes "$(artisan migrate:status --pending --no-ansi 2>&1)" || aborta "migrate:status --pending (como $WWW) en el árbol desplegado: [$ST_RESUMEN] (esperado «No pending migrations»)"
if [ "$NM" -gt 0 ]; then
  T_MIG=$(for ((i = 0; i < NM; i++)); do printf "'%s'," "${M_TABLA[$i]}"; done); T_MIG=${T_MIG%,}
  BLOQ=$(q "SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE l.database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND c.relname IN ($T_MIG) AND l.pid <> pg_backend_pid()") || BLOQ="?"
  LARGAS=$(q "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND state <> 'idle' AND xact_start < now() - interval '30 seconds' AND pid <> pg_backend_pid()") || LARGAS="?"
  say "   pendientes frente a origin/main: [${PEND_N:-ninguna}] = las declaradas · cerrojos de otras sesiones sobre [$T_MIG]: $BLOQ · transacciones de más de 30 s: $LARGAS"
  [ -x "$MIGRADOR" ] || aborta "falta $MIGRADOR (el envoltorio de las migraciones con el rol dueño)"
  # Este guion cuenta con lo que hace el envoltorio versionado (config:clear antes, config:cache después y --path tal cual)
  # y aguanta también uno que no vacíe la caché; si el instalado no es esa copia, se dice para que el lead lo mire.
  H_MIG=$(sha256sum < "$MIGRADOR" | cut -c1-16); H_VER=$(g show "$N:backend/docs/iron-ia/ops/ironbody-migrate" 2>/dev/null | sha256sum | cut -c1-16)
  if [ "$H_MIG" = "$H_VER" ]; then say "   $MIGRADOR = la copia versionada de iron-ia/ops (sha256 $H_MIG…)"
  else say "   AVISO: $MIGRADOR (sha256 $H_MIG…) no es la copia versionada de iron-ia/ops en $(corto "$N") ($H_VER…): mirar qué hace antes de desplegar"; fi
  [ "$BLOQ" = 0 ] || say "   AVISO: hay cerrojos sobre las tablas de las migraciones (lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces)"
else
  say "   pendientes frente a origin/main: ninguna (el lote no migra)"
fi
lee_rutas; [ "$RUTAS" != "?" ] || aborta "route:list --json (como $WWW) no se puede leer"
RUTAS0=$RUTAS; RUTAS0_JSON=$RUTAS_JSON
lee_tareas; [ "$TAREAS" != "?" ] || aborta "schedule:list --json (como $WWW) no se puede leer"
TAREAS0=$TAREAS; TAREAS0_JSON=$TAREAS_JSON
say "   route:list: $(cuenta_lineas "$RUTAS0") rutas · schedule:list: $(cuenta_lineas "$TAREAS0") tareas"
if [ -n "$F_config" ]; then
  B0=$(banderas "$F_config") || aborta "no se pudo leer la caché de configuración como $WWW"
  say "   banderas declaradas, hoy en la caché: $(printf '%s\n' "$B0" | paste -sd '|' -)"
fi
C_VOZ0=$(http admin/voice/status)
[ "$C_VOZ0" = 401 ] || aborta "GET admin/voice/status sin token → $C_VOZ0 (esperado 401): sin esa referencia no se puede verificar nada después"
LOG_ANTES=$(lineas_log)
say "   sin credenciales: admin/voice/status → $C_VOZ0"
if [ "$NM" -gt 0 ] && [ "$ORDEN" = migracion-primero ]; then PLAN="migraciones → código → cachés"; elif [ "$NM" -gt 0 ]; then PLAN="código → migraciones → cachés"; else PLAN="código → cachés"; fi
say "   plan: respaldo → $PLAN ($([ "$HAY_CONFIG" = 1 ] || [ -n "$F_config" ] && echo "config:cache" || echo "sin config:cache")$([ -n "$F_rutas" ] && echo " · route:cache" || echo " · sin route:cache")) → queue:restart → comprobaciones posteriores"
if [ "$MODO" = comprobar ]; then
  ETAPA=fin
  echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"
  exit 0
fi

# ── 1. Respaldo ───────────────────────────────────────────────────────────────────────────────────────────────────────────
ETAPA=respaldo
TS=$(date -u +%Y%m%d-%H%M%S)
BK="$BK_RAIZ/cierre-lote-$TS"
[ ! -e "$BK" ] || aborta "el respaldo $BK ya existe"
( umask 077; mkdir -p "$BK" ) || aborta "no se pudo crear el respaldo $BK"
[ "$(stat -c '%U %a' "$BK")" = "$ROOT_U 700" ] || aborta "el respaldo $BK no quedó 700 de $ROOT_U"
LOG="$BK/registro.log"
( umask 077; printf '%s' "$PRE" > "$LOG" ) || aborta "no se pudo crear el registro $LOG"
exec > >(tee -a "$LOG") 2>&1
# Encadenado con &&: dentro de un subshell que se evalúa con ||, `set -e` no corta (un install fallido pasaría callado).
(
  umask 077 \
  && printf '%s\n' "$P" > "$BK/api-commit.txt" \
  && printf '%s\n' "$N" > "$BK/api-commit-nuevo.txt" \
  && printf '%s\n' "${ORDEN:-sin-migraciones}" > "$BK/orden.txt" \
  && install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$LISTA" "$BK/lista.txt" \
  && mkdir "$BK/decl" "$BK/bootstrap-cache" "$BK/esquema" \
  && for d in $DECLS; do eval "f=\$F_$d"; if [ -n "$f" ]; then install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$f" "$BK/decl/$d" || exit 1; fi; done \
  && install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$APP/backend/$CACHE_RUTAS" "$BK/bootstrap-cache/routes-v7.php" \
  && install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" \
  && printf '%s\n' "$RUTAS0_JSON" > "$BK/rutas-antes.json" \
  && printf '%s\n' "$TAREAS0_JSON" > "$BK/programador-antes.json" \
  && printf '%s\n' "$SUP0" > "$BK/supervisor-antes.txt" \
  && printf '%s\n' "$REG0" > "$BK/migraciones-registradas-antes.txt" \
  && { [ ! -f "$0" ] || install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$0" "$BK/deploy-cierre-lote.sh"; }
) || aborta "no se pudo completar el respaldo $BK"
for ((i = 0; i < NM; i++)); do
  ( umask 077; def_mig "$i" > "$BK/esquema/$i.def" && printf '%s\n' "${M_E0[$i]}" > "$BK/esquema/$i.estado" ) || aborta "no se pudo guardar la definición de partida de ${M_NOM[$i]}"
  t=${M_TABLA[$i]}
  if [ ! -e "$BK/esquema/$t.sql" ] && [ "$(q "SELECT to_regclass('public.$t') IS NOT NULL")" = t ]; then
    ( umask 077; "${PG_DUMP[@]}" --schema-only -t "public.$t" > "$BK/esquema/$t.sql" ) || aborta "pg_dump --schema-only de $t falló"
  fi
done
( cd "$BK" && umask 077 && find . -type f ! -name registro.log ! -name SHA256SUMS | sed 's#^\./##' | sort | xargs sha256sum > SHA256SUMS ) || aborta "no se pudo escribir SHA256SUMS"
cmp -s "$APP/backend/$CACHE_RUTAS" "$BK/bootstrap-cache/routes-v7.php" && cmp -s "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" \
  || aborta "las cachés del respaldo no son las de producción (¿cambiaron mientras se copiaban?)"
MALOS=$(find "$BK" -mindepth 1 \( -type f ! -perm 600 \) -o \( -type d ! -perm 700 \) -o ! -user "$ROOT_U" | wc -l | tr -d ' ')
[ "$MALOS" = 0 ] || aborta "$MALOS entradas del respaldo no son de $ROOT_U en 600/700"
echo "1. respaldo $BK (700 de $ROOT_U; ficheros 600): commits, lista, declaraciones, cachés (la de configuración lleva secretos: no se imprime), route:list, schedule:list, supervisor, migraciones registradas, $NM definición(es) de partida y $(find "$BK/esquema" -name '*.sql' | wc -l | tr -d ' ') esquema(s) de tabla, el guion y SHA256SUMS ($(cuenta_lineas "$(cat "$BK/SHA256SUMS")") ficheros)"

# ── 2. En el orden del lote: migraciones, código y cachés ────────────────────────────────────────────────────────────────
revertir_auto() {
  echo "   ✗ $*"
  echo "VUELTA ATRÁS AUTOMÁTICA:"
  ETAPA=vuelta
  if vuelta_atras_ejecuta; then
    ETAPA=fin
    echo "VUELTA ATRÁS AUTOMÁTICA HECHA: producción de nuevo en $(corto "$P"), con las cachés, las rutas y el programador de antes${VA_CONSERVADO:+; SE CONSERVA el esquema de:$VA_CONSERVADO}. Motivo: $*. Diagnosticar antes de repetir; el respaldo queda en $BK"
    exit 4
  fi
  ETAPA=fin
  echo "PARAR: la vuelta atrás automática no quedó limpia (arriba). Producción en $(corto HEAD). A mano: DEPLOY_CIERRE_LOTES.md §6"
  orden_vuelta
  exit 5
}
REINTENTOS=0
migra() {
  local i=$1 dir f intento sal rc e
  if grep -qxF "${M_NOM[$i]}" <<< "$REG0"; then echo "   $(nombre_corto "${M_NOM[$i]}"): ya registrada (paso 0): no se ejecuta"; return 0; fi
  dir="$MIG_RAIZ/cierre-lote-$TS/$i-${M_NOM[$i]}"; f="$dir/${M_NOM[$i]}.php"
  # Fuera de storage/: de root y sin escritura para www-data (que solo la lee); la copia, comprobada contra git JUSTO antes.
  ( umask 022 && install -d -o "$ROOT_U" -g "$ROOT_G" -m 755 "$MIG_RAIZ" "$MIG_RAIZ/cierre-lote-$TS" "$dir" ) || revertir_auto "no se pudo crear $dir"
  ( umask 022 && g show "$N:$DIR_MIG/${M_NOM[$i]}.php" > "$f" && chmod 644 "$f" ) || revertir_auto "no se pudo copiar ${M_NOM[$i]} de git"
  [ "$(sha256sum < "$f")" = "$(g show "$N:$DIR_MIG/${M_NOM[$i]}.php" | sha256sum)" ] || revertir_auto "la copia de ${M_NOM[$i]} no coincide con git"
  intento=0
  while :; do
    intento=$((intento + 1))
    set +e; sal=$("$MIGRADOR" --path="$dir" --realpath 2>&1); rc=$?; set -e
    # La salida entera (con la traza, si falla) queda en el respaldo; aquí, solo el resumen (el filtro puede no casar
    # nada, y eso no es un error: de ahí el || true).
    ( umask 077; printf '%s\n' "$sal" > "$BK/migracion-$i-intento-$intento.log" )
    printf '%s\n' "$sal" | grep -E 'DONE|FAIL|SQLSTATE|Exception|config cache' | sed 's/^[[:space:]]*/      /' | cut -c1-220 || true
    e=$(estado_mig "$i" 2>/dev/null) || e="?"
    if registrada "${M_NOM[$i]}" && [ "$(clase_mig "$i" "$e" "${M_E0[$i]}")" = despues ]; then break; fi
    case "$sal" in *55P03*|*"lock timeout"*) if [ "$intento" -lt 3 ] && [ "$(clase_mig "$i" "$e")" = antes ]; then REINTENTOS=$((REINTENTOS + 1)); echo "   intento $intento: 55P03 (otra sesión con ${M_TABLA[$i]}): esquema de antes, sin registrar; reintento en 15 s"; sleep 15; continue; fi ;; esac
    # ironbody-migrate hace config:clear antes de migrar: si la migración falla, no llega a su config:cache.
    [ -f "$APP/backend/$CACHE_CONFIG" ] || echo "   la caché de configuración NO EXISTE tras el fallo (el config:clear de ironbody-migrate): se pone la del respaldo"
    pon_cache "$BK/bootstrap-cache/config.php" "$CACHE_CONFIG" || echo "   ✗ no se pudo restaurar la caché de configuración del respaldo"
    revertir_auto "la migración ${M_NOM[$i]} no quedó aplicada (ironbody-migrate salió con $rc, intento $intento; esquema [$e], registrada: $(registrada "${M_NOM[$i]}" && echo sí || echo no))"
  done
  [ "$rc" = 0 ] || aviso "ironbody-migrate salió con $rc aunque la migración quedó aplicada (arriba)"
  cache_ok "$CACHE_CONFIG" || revertir_auto "tras ironbody-migrate, la caché de configuración no es un fichero de $WWW en 600"
  echo "   $(nombre_corto "${M_NOM[$i]}"): aplicada (intento $intento) · esquema [$e] = después · copia $f ($(sha256sum < "$f" | cut -c1-12)…)"
}
paso_migraciones() {
  ETAPA=migraciones
  echo "M. migraciones (una a una, con ironbody-migrate --path: el rol dueño, lock_timeout 5 s):"
  for ((i = 0; i < NM; i++)); do migra "$i"; done
  cmp -s "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" && echo "   caché de configuración: la regeneró ironbody-migrate y es la misma que la del respaldo" \
    || echo "   caché de configuración: la regeneró ironbody-migrate y no es byte a byte la del respaldo ($([ "$HAY_CONFIG" = 1 ] && echo "se rehace con config:cache en K" || echo "mismo config/; comprobada en el paso 4"))"
}
paso_codigo() {
  ETAPA=codigo
  if ! ( umask 022; g merge -q --ff-only "$N" ); then
    if [ "$(g rev-parse HEAD)" = "$P" ] && [ -z "$(g status --porcelain --untracked-files=no)" ] && [ "$NM" = 0 ]; then
      ETAPA=fin; echo "ABORTA: git merge --ff-only falló y producción sigue en $(corto "$P"), sin tocar (el respaldo queda en $BK)"; exit 1
    fi
    revertir_auto "git merge --ff-only falló"
  fi
  [ "$(g rev-parse HEAD)" = "$N" ] || revertir_auto "tras el merge, HEAD es $(corto HEAD) y no $(corto "$N")"
  legibles "$P" "$N" || revertir_auto "${LEGIBLES#* } de ${LEGIBLES%% *} ficheros del merge no los lee $WWW"
  echo "C. código: HEAD=$(corto HEAD) (merge --ff-only, umask 022) · $WWW lee los ${LEGIBLES%% *} ficheros del merge"
}
paso_caches() {
  ETAPA=caches
  if [ -n "$F_config" ]; then
    ( cd "$APP/backend" && sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' ) || revertir_auto "config:cache (como $WWW) falló"
    cache_ok "$CACHE_CONFIG" || revertir_auto "la caché de configuración no quedó como fichero normal de $WWW en 600"
  fi
  if [ -n "$F_rutas" ]; then
    ( cd "$APP/backend" && sudo -u "$WWW" sh -c 'umask 077; php artisan route:cache -q' ) || revertir_auto "route:cache (como $WWW) falló"
    cache_ok "$CACHE_RUTAS" || revertir_auto "la caché de rutas no quedó como fichero normal de $WWW en 600"
  fi
  echo "K. cachés: $([ -n "$F_config" ] && echo "config:cache" || echo "configuración sin regenerar") · $([ -n "$F_rutas" ] && echo "route:cache" || echo "rutas sin regenerar") (como $WWW, umask 077) · $(stat -c '%U %a' "$APP/backend/$CACHE_CONFIG") / $(stat -c '%U %a' "$APP/backend/$CACHE_RUTAS")"
}
echo "2. $PLAN:"
if [ "$NM" -gt 0 ] && [ "$ORDEN" = migracion-primero ]; then paso_migraciones; paso_codigo
elif [ "$NM" -gt 0 ]; then paso_codigo; paso_migraciones
else paso_codigo; fi
paso_caches

# ── 3. Workers ────────────────────────────────────────────────────────────────────────────────────────────────────────────
ETAPA=workers
PIDS_PRE=$(pids)
artisan queue:restart -q || revertir_auto "queue:restart falló: los workers siguen con el código anterior"
espera_workers "$PIDS_PRE" "$RUN0"
FACT1=$(systemctl is-active "$SERVICIO_FACT" 2>/dev/null || true)
echo "3. workers (RUNNING · BACKOFF/FATAL): antes [$R0 0] → [$W1] y [$W2] (dos lecturas, pasado el margen) · con pid nuevo $REIN de $RUN0 · $SERVICIO_FACT ${FACT0:-?} → ${FACT1:-?}"

# ── 4. Comprobaciones posteriores (si una falla: vuelta atrás automática) ───────────────────────────────────────────────
ETAPA=verificacion
FALLOS=""
falla() { echo "   ✗ $*"; FALLOS="$FALLOS · $*"; }
{ [ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ]; } || falla "workers [$W1] y [$W2] (esperado [$RUN0 0] las dos veces)"
[ "$REIN" -ge "$RUN0" ] || aviso "solo $REIN de $RUN0 workers tienen pid nuevo tras 90 s (¿un trabajo largo?): volver a mirar supervisorctl status"
[ "$FACT1" = "$FACT0" ] || aviso "$SERVICIO_FACT pasó de «$FACT0» a «$FACT1»"
[ "$(g rev-parse HEAD)" = "$N" ] || falla "producción ya no está en $(corto "$N") (HEAD $(corto HEAD))"
[ -z "$(g status --porcelain --untracked-files=no)" ] || falla "el checkout tiene cambios tras el despliegue"
legibles "$P" "$N" > /dev/null || falla "${LEGIBLES#* } ficheros del merge no los lee $WWW"
C_VOZ1=$(http_espera 401 admin/voice/status)
echo "4. sin credenciales: admin/voice/status → $C_VOZ1 (esperado 401)"
[ "$C_VOZ1" = 401 ] || falla "admin/voice/status sin token respondió $C_VOZ1"
for f in "$CACHE_RUTAS" "$CACHE_CONFIG"; do cache_ok "$f" || falla "$f no es un fichero normal de $WWW en 600"; done
NR1=0; for ((i = 0; i < NM; i++)); do registrada "${M_NOM[$i]}" && NR1=$((NR1 + 1)); done
if sin_pendientes "$(artisan migrate:status --pending --no-ansi 2>&1)"; then NP1="ninguna pendiente"; else NP1="[$ST_RESUMEN]"; fi
echo "   migrate:status --pending (como $WWW): $NP1 · declaradas registradas: $NR1 de $NM"
[ "$NP1" = "ninguna pendiente" ] || falla "migrate:status --pending no dice «No pending migrations»: $NP1"
[ "$NR1" = "$NM" ] || falla "solo $NR1 de $NM migraciones declaradas están registradas"
for ((i = 0; i < NM; i++)); do
  e=$(estado_mig "$i" 2>/dev/null) || e="?"; c=$(clase_mig "$i" "$e" "${M_E0[$i]}")
  echo "   esquema de $(nombre_corto "${M_NOM[$i]}"): [$e] = $c"
  [ "$c" = despues ] || falla "el esquema de ${M_NOM[$i]} es [$e], no el de después"
done
lee_tareas
if [ "$TAREAS" = "?" ]; then falla "schedule:list no se puede leer"
else
  DT=$(diferencia "$TAREAS0" "$TAREAS"); ET=""
  [ -z "$F_programador" ] || ET=$(decl "$F_programador" | grep -E '^[+-]' | sort || true)
  echo "   schedule:list: $(cuenta_lineas "$TAREAS0") → $(cuenta_lineas "$TAREAS") tareas · diferencia [$(printf '%s\n' "$DT" | sed '/^$/d' | paste -sd '|' -)] (declarada [$(printf '%s\n' "$ET" | sed '/^$/d' | paste -sd '|' -)])"
  [ "$DT" = "$ET" ] || falla "schedule:list no tiene la diferencia declarada"
  if [ -n "$F_programador" ]; then
    while IFS= read -r l; do
      case "$l" in
        "ausente "*) if grep -qF -- "${l#ausente }" <<< "$TAREAS"; then falla "schedule:list tiene «${l#ausente }» y el lote la declara ausente"; else echo "   schedule:list sin «${l#ausente }» (declarada ausente)"; fi ;;
        "presente "*) if grep -qF -- "${l#presente }" <<< "$TAREAS"; then echo "   schedule:list con «${l#presente }» (declarada presente)"; else falla "schedule:list no tiene «${l#presente }»"; fi ;;
      esac
    done < <(decl "$F_programador")
  fi
fi
lee_rutas
if [ "$RUTAS" = "?" ]; then falla "route:list no se puede leer"
else
  DR=$(diferencia "$RUTAS0" "$RUTAS"); ER=""
  [ -z "$F_rutas" ] || ER=$(decl "$F_rutas" | sort)
  echo "   route:list: $(cuenta_lineas "$RUTAS0") → $(cuenta_lineas "$RUTAS") rutas · diferencia [$(printf '%s\n' "$DR" | sed '/^$/d' | sed -n 1,8p | paste -sd '|' -)] (declarada: $(cuenta_lineas "$ER") línea(s))"
  [ "$DR" = "$ER" ] || falla "route:list no tiene exactamente la diferencia declarada"
  if [ -n "$F_rutas" ] && artisan list --raw 2>/dev/null | grep -q '^auth:coverage '; then
    COB=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-120 || true)
    echo "   auth:coverage --unmapped → [${COB:-vacío}]"
    [ -z "$COB" ] || falla "auth:coverage --unmapped no está vacío: hay rutas sin su llave de permiso"
  fi
  if [ -n "$F_rutas" ]; then
    while IFS= read -r u; do
      c=$(http_espera 401 "${u#api/}")
      echo "   GET $u sin token → $c (esperado 401: el servidor ve la caché de rutas nueva)"
      [ "$c" = 401 ] || falla "GET $u sin token respondió $c (esperado 401)"
    done < <(gets_nuevas "$F_rutas")
  fi
fi
if [ -n "$F_config" ]; then
  B1=$(banderas "$F_config" 2>/dev/null) || B1="?"
  EB=$(banderas_esperadas "$F_config")
  echo "   banderas en la caché de configuración: $(printf '%s\n' "$B1" | paste -sd '|' -)"
  [ "$B1" = "$EB" ] || falla "las banderas no valen lo declarado: $(diferencia "$(printf '%s\n' "$EB" | sort)" "$(printf '%s\n' "$B1" | sort)" | paste -sd ' ' -) (- declarado · + en la caché)"
elif [ "$NM" = 0 ]; then
  cmp -s "$APP/backend/$CACHE_CONFIG" "$BK/bootstrap-cache/config.php" || aviso "la caché de configuración cambió durante el despliegue y este lote no la toca: ¿otra sesión?"
fi
DESCUENTO=""; [ "$REINTENTOS" = 0 ] || DESCUENTO="55P03"
ERR=$(errores_nuevos "$LOG_ANTES" "$DESCUENTO")
echo "   $NIVEL_ERROR nuevos en laravel*.log desde el inicio: $ERR$([ "$REINTENTOS" = 0 ] || echo " (sin contar los del 55P03 reintentado, $REINTENTOS)")"
[ "$ERR" = 0 ] || falla "$ERR líneas $NIVEL_ERROR nuevas en el log desde el inicio del despliegue"
if [ -n "$FALLOS" ]; then revertir_auto "comprobaciones posteriores${FALLOS}"; fi
echo "   producción sirve $(corto HEAD) · respaldo $BK · registro $LOG"
echo "   VIGILANCIA (15 min, solo lectura): supervisorctl status (los $RUN0 en RUNNING) · $NIVEL_ERROR nuevos · failed_jobs · 5xx del access.log (DEPLOY_CIERRE_LOTES.md §7)"
orden_vuelta
ETAPA=fin
echo "LOTE DESPLEGADO SIN DESVÍOS$([ "$AVISOS" = 0 ] || echo " (con $AVISOS aviso(s) arriba)")"
exit 0
