#!/bin/bash
# El banco de la preparación de la primera llamada (plans/informes-motores/informe-devops-telefonia-prep.md): ensaya, SIN
# producción y SIN red (la valla del H8; ElevenLabs es su simulador en 127.0.0.1), los tres mandos que cambian:
#   · voz      h8-voice-scope.sh «salientes on» (C-1): la reserva de la PUERTA (siempre con transferencia a fijo), con el
#              mando de HEAD al lado (acepta 0.70 y la puerta lo denegaría), los rechazos sin tocar el fichero, la
#              fórmula de la puerta cambiada, la frontera 1.12/1.13, el cerrojo ocupado y un fallo forzado tras escribir y
#              tras la caché con su vuelta exacta ejecutada; la constante se abre con un commit `voice(calls-open):` SOLO
#              en el clon del banco, y se vuelve a cerrar con el checkout del runbook;
#   · el       el-verificar.sh (C-3): los tres cerrojos (cada uno ocupado por otro proceso con un flock de verdad), la
#              rotación --solo-clave y el modo completo contra el simulador, y un fallo en CADA etapa (escritura, Dotenv,
#              caché, la bandera, la caché con la bandera, una señal y doctor --verify con una clave que el simulador
#              rechaza) con su vuelta atrás automática COMPROBADA (fichero idéntico, caché de «www-data» en 600, la
#              configuración efectiva de antes y ElevenLabs SIN apagar), la restauración que falla y su orden a mano, y el
#              guion de HEAD al lado (sin cerrojos y con la vuelta que apaga ElevenLabs);
#   · monitor  h7-monitor-scope.sh «on off» con --check=prompts en la comprobación informativa (tarea 5), frente al de HEAD.
# Uso (el banco vive FUERA del repo):
#   B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-telefonia
#   bash telefonia-prep.sh "$B" montar      copia la «producción» del banco de la ola M-1 (BANCO_ORIGEN), base PROPIA clonada
#                                           de la suya (CREATE DATABASE … TEMPLATE), el código en HEAD del worktree, las
#                                           piezas SINTÉTICAS de ElevenLabs (al simulador) y un flock de verdad
#   bash telefonia-prep.sh "$B" arrancar    la valla (:8574), el simulador de ElevenLabs (:8572) y la API (:8571); la primera
#                                           vez, la verificación de ElevenLabs contra el simulador (el estado de producción)
#   bash telefonia-prep.sh "$B" voz | el | monitor
#   bash telefonia-prep.sh "$B" parar
#   bash telefonia-prep.sh "$B" borrar      parar, borrar su base y el directorio
# Cada ensayo deja el banco como estaba (el fichero de entorno de partida byte a byte, su modo y la caché) y comprueba
# CERO red (la valla). Los mandos son COPIAS del banco de los del árbol (y de HEAD): solo cambian su bloque propio de
# producción y los puntos de fallo forzado (BANCO_FALLA); cada sustitución casa las veces exactas o el ensayo se niega.
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
ORDEN=${2:-}
M=$(banco_dir "${BANCO_ORIGEN:-${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-m1}")
DB_NUEVA=ultron_studio_banco_telefonia
P_API=${BANCO_TEL_PORT:-8571}; P_EL=${BANCO_TEL_PUERTO_EL:-8572}; P_VALLA=${BANCO_TEL_PUERTO_VALLA:-8574}
ME=$(whoami); GRUPO=$(id -gn)
OPS_REL=backend/docs/ultron-studio/ops

case "$ORDEN" in
  montar)
    [ ! -e "$B/banco.env" ] || muere "$B ya está montado (para empezar de cero: bash $0 $B borrar)"
    [ -f "$M/banco.env" ] || muere "falta el banco de la ola M-1 en $M (BANCO_ORIGEN)"
    M_DB=$(. "$M/banco.env"; echo "$BANCO_DB"); M_ADMIN=$(. "$M/banco.env"; echo "${ADMIN_ID:-}")
    [ -n "$M_DB" ] && [ -n "$M_ADMIN" ] || muere "el banco de origen no tiene BANCO_DB y ADMIN_ID"
    [ "$(psql_su -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = '$DB_NUEVA'")" = 0 ] || muere "la base $DB_NUEVA ya existe (bash $0 $B borrar)"
    [ "$(psql_su -d postgres -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '$M_DB'")" = 0 ] || muere "la base del banco de origen ($M_DB) tiene conexiones: parar ese banco antes"
    git -C "$M/api" diff --quiet && git -C "$M/api" diff --cached --quiet || muere "la «producción» del banco de origen tiene cambios sin confirmar"
    mkdir -p "$B"/{root,run,logs,sim,bin}
    chmod 700 "$B" "$B/root" "$B/sim"
    echo "== 1. la «producción»: copia de $M/api (código, vendor y fichero de entorno sintético)"
    cp -Rp "$M/api" "$B/api"
    HEADC=$(git -C "$WT" rev-parse HEAD)
    git -C "$B/api" fetch -q "$WT" "$HEADC" && git -C "$B/api" checkout -q --detach "$HEADC" || muere "no se pudo poner el código de HEAD en el clon del banco"
    git -C "$B/api" diff --quiet "$HEADC" -- backend/composer.lock || muere "composer.lock cambió: el vendor copiado no vale"
    echo "   código: $(git -C "$B/api" rev-parse --short=8 HEAD) (HEAD del worktree); sin migraciones nuevas desde el origen: $(git -C "$B/api" diff --name-only "$(. "$M/banco.env"; echo "$ESPERADO")" HEAD -- backend/database/migrations | wc -l | tr -d ' ') ficheros"
    echo "== 2. base propia: $DB_NUEVA (CREATE DATABASE … TEMPLATE $M_DB)"
    psql_su -d postgres -c "CREATE DATABASE $DB_NUEVA TEMPLATE $M_DB"
    echo "== 3. piezas SINTÉTICAS de ElevenLabs (sim/piezas.conf, 600) y el fichero de entorno"
    hex() { python3 -I -c 'import secrets, sys; print(secrets.token_hex(int(sys.argv[1])))' "$1"; }
    ( umask 077; cat > "$B/sim/piezas.conf" <<PZ
LARAVEL_BASE=http://127.0.0.1:$P_API
EL_BASE=http://127.0.0.1:$P_EL
EL_API_KEY=xi_$(hex 24)
EL_WEBHOOK_SECRET=wsec_$(hex 24)
EL_TOOL_SECRET=tool_$(hex 24)
EL_INITIATION_SECRET=ini_$(hex 24)
EL_INITIATION_HEADER=X-Voice-Initiation-Secret
EL_VERIFY_AGENT_ID=agent_verificacion_$(hex 4)
EL_PHONE_NUMBER_ID=phnum_$(hex 8)
VOICE_NUMBER=+576080000001
EL_MAX_RETENTION_DAYS=30
SENTINEL_ELEVENLABS=banco-tel-centinela-el-$(hex 6 | tr 0-9 g-p)
PZ
    )
    python3 -I - "$B/sim/piezas.conf" "$B/api/backend/.env" "$DB_NUEVA" <<'PY'
import re, sys
pz = dict(l.split("=", 1) for l in open(sys.argv[1]).read().splitlines() if "=" in l)
p, db = sys.argv[2], sys.argv[3]
s = open(p, encoding="utf-8").read()
claves = [("DB_DATABASE", db), ("ELEVENLABS_ENABLED", "true"), ("ELEVENLABS_API_KEY", pz["EL_API_KEY"]),
          ("ELEVENLABS_AGENT_ID", pz["EL_VERIFY_AGENT_ID"]), ("ELEVENLABS_REGION", "default"), ("ELEVENLABS_BASE_URL", pz["EL_BASE"]),
          ("ELEVENLABS_WEBHOOK_SECRET", pz["EL_WEBHOOK_SECRET"]), ("ELEVENLABS_TOOL_SECRET", pz["EL_TOOL_SECRET"]),
          ("ELEVENLABS_INITIATION_SECRET", pz["EL_INITIATION_SECRET"])]
for k, v in claves:
    s, n = re.subn(r"^([ \t]*)" + k + r"=.*$", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
    if n == 0:
        s = s.rstrip("\n") + "\n  " + k + "=" + v + "\n"
open(p, "w", encoding="utf-8").write(s)
print("   %d claves en el fichero sintético (la base propia y ElevenLabs al simulador, como producción tras E1-12); no se imprimen" % len(claves))
PY
    chmod 600 "$B/api/backend/.env"
    echo "== 4. sustitutos (sudo, stat, supervisorctl con el worker de voz a voluntad) y un flock DE VERDAD"
    cp -p "$M"/bin/* "$B/bin/"
    printf '%s\n' '#!/bin/bash' '# flock del banco de telefonía: «flock -n FD» de verdad (fcntl.flock sobre el descriptor heredado).' \
      '[ "${1:-}" = -n ] && [[ "${2:-}" =~ ^[0-9]+$ ]] || { echo "flock del banco: solo «flock -n FD»" >&2; exit 64; }' \
      "exec python3 -I -c 'import fcntl, sys" 'try:' '    fcntl.flock(int(sys.argv[1]), fcntl.LOCK_EX | fcntl.LOCK_NB)' 'except BlockingIOError:' "    sys.exit(1)' \"\$2\"" > "$B/bin/flock"
    cat > "$B/bin/supervisorctl" <<SUP
#!/bin/bash
# supervisorctl del banco de telefonía: los 13 programas de producción, y ironbody-voice si existe $B/run/worker-voz.
case "\${1:-}" in status) ;; *) exit 0 ;; esac
n=1000
linea() { n=\$((n + 1)); printf '%-38s RUNNING   pid %s, uptime 0:10:00\n' "\$1:\$1_\$2" "\$n"; }
for i in 00 01 02 03; do linea ironbody-whatsapp "\$i"; done
for i in 00 01 02 03; do linea ironbody-agent "\$i"; done
for i in 00 01; do linea ironbody-media "\$i"; done
for i in 00 01; do linea ironbody-commercial "\$i"; done
linea ironbody-lab 00
[ ! -e "$B/run/worker-voz" ] || linea ironbody-voice 00
exit 0
SUP
    chmod 755 "$B/bin/flock" "$B/bin/supervisorctl"
    printf 'BANCO_DB=%s\nBANCO_PORT=%s\nPUERTO_VALLA=%s\nPUERTO_EL=%s\nESPERADO=%s\nPREVIO_BANCO=%s\nADMIN_ID=%s\n' "$DB_NUEVA" "$P_API" "$P_VALLA" "$P_EL" "$(git -C "$B/api" rev-parse --short=7 HEAD)" "$HEADC" "$M_ADMIN" > "$B/banco.env"
    cargar_banco "$B"
    ( cd "$B/api/backend" && env "${RED[@]}" php artisan config:clear -q && env "${RED[@]}" php artisan route:clear -q \
      && env "${RED[@]}" php artisan config:cache -q && env "${RED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php ) || muere "las cachés no se regeneraron"
    echo "banco de telefonía montado en $B (base $DB_NUEVA, código $ESPERADO). Siguiente: bash $0 $B arrancar"
    exit 0 ;;
esac

cargar_banco "$B"
[ -n "${PUERTO_EL:-}" ] || muere "$B no es un banco de telefonía (falta PUERTO_EL en banco.env)"
export PATH="$B/bin:$PATH"
BE="$B/api/backend"
ENVF="$BE/.env"
URL="http://127.0.0.1:$BANCO_PORT/api"
arranca() { local n=$1 d=$2; shift 2; ( cd "$d" || exit 1; nohup "$@" > "$B/logs/$n.out" 2>&1 < /dev/null & echo $! > "$B/run/$n.pid" ); }
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() { local n=$1 p; [ -f "$B/run/$n.pid" ] || return 0; p=$(cat "$B/run/$n.pid"); for c in $(descendientes "$p") "$p"; do kill "$c" 2> /dev/null || true; done; rm -f "$B/run/$n.pid"; }
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
valla_total() { curl -s --max-time 3 "http://127.0.0.1:$PUERTO_VALLA/__stats" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(int(d["blocked"]) + int(d.get("localThroughFence", 0)))' 2> /dev/null || echo '?'; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
art() { ( cd "$BE" && env "${RED[@]}" php artisan "$@" ); }
tinker() { ( cd "$BE" && env "${RED[@]}" php artisan tinker --execute="$1" 2> /dev/null | tail -1 ); }
cache_banco() { ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php ); }
arranca_sim() {
  arranca sim-elevenlabs "$B" node "$OPS/banco-h8/sim-elevenlabs.cjs" --config "$B/sim/piezas.conf" --log "$B/logs/sim-elevenlabs.jsonl" --port "$PUERTO_EL"
  for _ in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PUERTO_EL/__stats" && return 0; sleep 0.25; done
  muere "el simulador de ElevenLabs no responde en :$PUERTO_EL (ver $B/logs/sim-elevenlabs.out)"
}
# La rotación «en la consola»: una clave nueva en las piezas del simulador (nunca por la línea de órdenes) y el simulador
# reiniciado con ella (la de antes deja de valer). La nueva queda también en $B/sim/clave-<n> (600) para compararla.
sim_rota() {
  python3 -I - "$B/sim/piezas.conf" "$B/sim/clave-$1" <<'PY'
import os, secrets, sys
p, copia = sys.argv[1:3]
k = "xi_" + secrets.token_hex(24)
s = [("EL_API_KEY=" + k) if l.startswith("EL_API_KEY=") else l for l in open(p).read().splitlines()]
open(p, "w").write("\n".join(s) + "\n")
fd = os.open(copia, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
os.write(fd, k.encode()); os.close(fd)
PY
  para sim-elevenlabs; sleep 0.3; arranca_sim
}

case "$ORDEN" in
  arrancar)
    for p in "$PUERTO_VALLA" "$PUERTO_EL" "$BANCO_PORT"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p ya está ocupado (pid $(escuchando "$p"))"; done
    arranca valla "$B" node "$OPS/banco-h8/valla.cjs" --log "$B/logs/valla.jsonl" --port "$PUERTO_VALLA"
    for _ in $(seq 1 40); do [ "$(valla_total)" != '?' ] && break; sleep 0.25; done
    [ "$(valla_total)" != '?' ] || muere "la valla no responde"
    arranca_sim
    arranca api "$BE" env "${RED[@]}" PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload
    for _ in $(seq 1 40); do c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$URL/admin/voice/status" || true); [ "$c" = 401 ] && break; sleep 0.5; done
    [ "$c" = 401 ] || muere "la API no respondió (ver $B/logs/api.out)"
    if [ "$(tinker 'echo App\Services\UltronStudio\StudioIntegrations::voiceState(), PHP_EOL;')" != ELEVENLABS_VERIFIED ]; then
      echo "   primera verificación de ElevenLabs contra el simulador (integrations-snapshot --verify-voice: el estado de producción)"
      art ultron-studio:integrations-snapshot --verify-voice > "$B/logs/verificacion-inicial.out" 2>&1 || true
    fi
    echo "banco de telefonía en marcha: valla :$PUERTO_VALLA ($(valla_total) peticiones) · ElevenLabs simulado :$PUERTO_EL · API :$BANCO_PORT (401 sin token) · Studio: $(tinker 'echo App\Services\UltronStudio\StudioIntegrations::voiceState(), PHP_EOL;')" ;;
  parar)
    for n in api sim-elevenlabs valla; do para "$n"; done; sleep 0.5
    for p in "$BANCO_PORT" "$PUERTO_EL" "$PUERTO_VALLA"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p sigue ocupado"; done
    echo "banco de telefonía parado" ;;
  borrar)
    for n in api sim-elevenlabs valla; do para "$n"; done
    psql_su -d postgres -c "DROP DATABASE IF EXISTS $BANCO_DB" && rm -rf "$B" && echo "banco de telefonía borrado (base $BANCO_DB y $B)" ;;
  voz|el|monitor) ;;
  *) muere "uso: bash telefonia-prep.sh <banco> montar | arrancar | voz | el | monitor | parar | borrar" ;;
esac
case "$ORDEN" in arrancar|parar|borrar) exit 0 ;; esac
[ -n "$(escuchando "$BANCO_PORT")" ] && [ "$(valla_total)" != '?' ] && curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PUERTO_EL/__stats" || muere "arranca antes: bash $0 $B arrancar"

# ── Las copias del banco ────────────────────────────────────────────────────────────────────────────────────────────
copia() { # origen destino reglas(JSON: [[viejo, nuevo, veces], …]); veces 0 = al menos una
  python3 -I - "$1" "$2" "$3" <<'PY'
import json, sys
origen, destino, reglas = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
s = open(origen, encoding="utf-8").read()
for viejo, nuevo, veces in reglas:
    n = s.count(viejo)
    if n == 0 or (veces and n != veces):
        sys.exit(f"telefonia-prep: «{viejo[:70]}» aparece {n} veces en {origen} (esperado {veces or '≥ 1'}): el guion cambió")
    s = s.replace(viejo, nuevo)
open(destino, "w", encoding="utf-8").write(s)
PY
  chmod 700 "$2"
  bash -n "$2"
  ! grep -v '^[[:space:]]*#' "$2" | grep -nE '/var/www|(^|[^A-Za-z0-9_./-])/root/|/run/lock|ironbodyneiva' || muere "la copia del banco $2 todavía nombra rutas o la URL de producción"
}
head_de() { git -C "$WT" show "HEAD:$OPS_REL/$1" > "$B/root/$2"; }
reglas_voz() { python3 -I -c 'import json, sys
be, url, me, grupo, b = sys.argv[1:6]
print(json.dumps([
  ["[ \"$(id -u)\" = 0 ]", "true", 1],
  ["APPDIR=/var/www/api/backend\n", f"APPDIR={be}\n", 1],
  ["API=https://api.ironbodyneiva.cloud/api\n", f"API={url}\n", 1],
  ["WWW=www-data\n", f"WWW={me}\n", 1], ["ROOT_U=root\n", f"ROOT_U={me}\n", 1], ["ROOT_G=root\n", f"ROOT_G={grupo}\n", 1],
  ["COPIAS=/root\n", f"COPIAS={b}/root\n", 1],
  ["CERROJO=/run/lock/h8-voice-scope.lock\n", f"CERROJO={b}/run/h8-voice-scope.lock\n", 1],
  ["NIVEL_ERROR=production.ERROR\n", "NIVEL_ERROR=local.ERROR\n", 1],
  ["= \"$ROOT_U:$WWW 640\" ]", "= \"$ROOT_U:$ROOT_G 640\" ]", 1],
  ["parsea || aborta \"el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA\"", "{ [ \"${BANCO_FALLA:-}\" != parsea ] && parsea; } || aborta \"el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA\"", 1],
  ["DESPUES=$(leer_voz)\n", "DESPUES=$(leer_voz); [ \"${BANCO_FALLA:-}\" != cache ] || DESPUES=\"\"\n", 1],
]))' "$BE" "$URL" "$ME" "$GRUPO" "$B"; }
reglas_el() { python3 -I -c 'import json, sys
be, me, grupo, b = sys.argv[1:5]
print(json.dumps([
  ["APPDIR=/var/www/api/backend;", f"APPDIR={be};", 1],
  ["WWW=www-data\n", f"WWW={me}\n", 1],
  ["CERROJOS=/run/lock\n", f"CERROJOS={b}/run\n", 1],
  ["= \"www-data 600\"", f"= \"{me} 600\"", 1],
  ["-o root -g root", f"-o {me} -g {grupo}", 1],
  ["/root/env-pre-elevenlabs-", f"{b}/root/env-pre-elevenlabs-", 1],
  ["production.ERROR", "local.ERROR", 1],
  ["parsea_el || falla", "{ [ \"${BANCO_FALLA:-}\" != parsea ] && parsea_el; } || falla", 1],
  ["cache || falla \"config:cache falló o la caché no quedó de $WWW en 600 y legible (paso 2)\"", "{ cache && [ \"${BANCO_FALLA:-}\" != cache2 ]; } || falla \"config:cache falló o la caché no quedó de $WWW en 600 y legible (paso 2)\"", 1],
  ["escribir_bandera || falla", "{ escribir_bandera && [ \"${BANCO_FALLA:-}\" != bandera ]; } || falla", 1],
  ["cache || falla \"config:cache falló o la caché no quedó bien con la bandera encendida (paso 3)\"", "{ cache && [ \"${BANCO_FALLA:-}\" != cache3 ]; } || falla \"config:cache falló o la caché no quedó bien con la bandera encendida (paso 3)\"\n[ \"${BANCO_FALLA:-}\" != senal ] || kill -TERM $$", 1],
  ["  if cache; then echo \"   caché regenerada", "  if cache && [ \"${BANCO_FALLA_VUELTA:-}\" != 1 ]; then echo \"   caché regenerada", 1],
]))' "$BE" "$ME" "$GRUPO" "$B"; }
reglas_el_head() { python3 -I -c 'import json, sys
be, me, grupo, b = sys.argv[1:5]
print(json.dumps([
  ["APPDIR=/var/www/api/backend", f"APPDIR={be}", 1], ["/var/www/api/backend", be, 0],
  ["= \"www-data 600\"", f"= \"{me} 600\"", 1], ["-o root -g root", f"-o {me} -g {grupo}", 1],
  ["/root/env-pre-elevenlabs-", f"{b}/root/env-pre-elevenlabs-", 0], ["production.ERROR", "local.ERROR", 1],
]))' "$BE" "$ME" "$GRUPO" "$B"; }
reglas_h7() { python3 -I -c 'import json, sys
b, url, me, grupo = sys.argv[1:5]
print(json.dumps([
  ["/var/www/api", f"{b}/api", 0], ["https://api.ironbodyneiva.cloud/api", url, 1], ["[ \"$(id -u)\" = 0 ]", "true", 1],
  ["/root/", f"{b}/root/", 0], ["install -m 600 -o root -g root", "install -m 600", 1], ["= www-data ]", f"= {me} ]", 1],
  ["ENV_600=www-data", f"ENV_600={me}", 1], ["ENV_640=root:www-data", f"ENV_640={me}:{grupo}", 1],
]))' "$B" "$URL" "$ME" "$GRUPO"; }

LOG="$B/logs/ensayo-$ORDEN-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
ULTIMA=""
paso() { # nombre código_esperado texto_esperado orden…
  local nombre=$1 esperado=$2 texto=$3 rc
  shift 3
  ULTIMA=$("$@" 2>&1); rc=$?
  { echo "════ $nombre · código $rc"; printf '%s\n' "$ULTIMA" | sed "s#$B#\$B#g"; } >> "$LOG"
  caso "$nombre" "$([ "$rc" = "$esperado" ] && printf '%s' "$ULTIMA" | grep -qF -- "$texto" && echo 0 || echo 1)" "código $rc$([ "$rc" = "$esperado" ] || printf ' (esperado %s): %s' "$esperado" "$(printf '%s' "$ULTIMA" | grep -E 'ABORTA|NO SE APLICA|✗|APLICADO|TERMINADA|FALLIDA' | head -2 | tr '\n' ' ' | cut -c1-220)")"
}
igual() { caso "$1" "$(cmp -s "$ENVF" "$2" && echo 0 || echo 1)"; }
modo_env() { caso "$1: el fichero de entorno sigue en $2 y de $ME" "$([ "$(stat -c '%a %U' "$ENVF")" = "$2 $ME" ] && echo 0 || echo 1)" "$(stat -c '%a %U' "$ENVF")"; }
cache_ok() { caso "$1: la caché de configuración es un fichero de $ME en 600 (lo que en producción es www-data)" "$(c="$BE/bootstrap/cache/config.php"; [ -f "$c" ] && [ ! -L "$c" ] && [ "$(stat -c '%U %a' "$c")" = "$ME 600" ] && echo 0 || echo 1)" "$(stat -c '%U %a' "$BE/bootstrap/cache/config.php" 2> /dev/null)"; }
# Lecturas INDEPENDIENTES de los mandos (en el banco; nunca se imprimen valores): la configuración efectiva de ElevenLabs.
efectiva() { tinker 'echo json_encode(["enabled" => config("voice.elevenlabs.enabled"), "key" => hash("sha256", (string) config("voice.elevenlabs.api_key")), "voz" => hash("sha256", (string) json_encode(config("voice"))), "proveedor" => App\Services\Telephony\VoiceProviderState::current(), "marca" => App\Services\Telephony\VoiceProviderState::verification()["verifiedAt"], "studio" => App\Services\UltronStudio\StudioIntegrations::voiceState()]), PHP_EOL;'; }
ef() { printf '%s' "$1" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get(sys.argv[1]))' "$2" 2> /dev/null; }
sha() { python3 -I -c 'import hashlib, sys; print(hashlib.sha256(open(sys.argv[1], "rb").read().strip()).hexdigest())' "$1"; }
env_val_sha() { python3 -I -c 'import hashlib, re, sys
s = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"^[ \t]*" + sys.argv[2] + r"[ \t]*=[ \t]*(.*?)[ \t]*$", s, re.M)
print(hashlib.sha256(m.group(1).encode()).hexdigest() if m else "-")' "$ENVF" "$1"; }
temporales() { ls -a "$BE" | grep -cE '^\.env\.(tmp-|h8-voice-)' || true; }
toma_cerrojo() { # fichero → deja un proceso que lo retiene (TENEDOR) hasta que se le mate
  rm -f "$B/run/cerrojo-tomado"
  python3 -I -c 'import fcntl, sys, time
f = open(sys.argv[1], "w"); fcntl.flock(f, fcntl.LOCK_EX); open(sys.argv[2], "w").close(); time.sleep(120)' "$1" "$B/run/cerrojo-tomado" &
  TENEDOR=$!
  for _ in $(seq 1 50); do [ -e "$B/run/cerrojo-tomado" ] && break; sleep 0.1; done
}
suelta_cerrojo() { kill "$TENEDOR" 2> /dev/null; wait "$TENEDOR" 2> /dev/null; rm -f "$B/run/cerrojo-tomado"; }
V0=$(valla_total)
set +e
MODO_ORIG=$(stat -c '%a' "$ENVF")
install -m 600 "$ENVF" "$B/root/env-ref-0"
echo "#### $ORDEN · banco $(git -C "$B/api" rev-parse --short=8 HEAD) · valla en $V0 peticiones · registro \$B/logs/$(basename "$LOG")"

case "$ORDEN" in
# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
  voz)
    copia "$OPS/h8-voice-scope.sh" "$B/root/h8-voice-scope.sh" "$(reglas_voz)" || muere "copia del mando"
    head_de h8-voice-scope.sh h8-voice-scope-head.orig
    copia "$B/root/h8-voice-scope-head.orig" "$B/root/h8-voice-scope-head.sh" "$(reglas_voz)" || muere "copia del mando de HEAD"
    rm -f "$B/root/h8-voice-scope-head.orig" "$B"/root/env-pre-voice-* "$B/run/worker-voz"
    mando() { ( export "${RED[@]}"; bash "$B/root/$1" "${@:2}" ); }
    mando_falla() { ( export "${RED[@]}" BANCO_FALLA="$1"; bash "$B/root/h8-voice-scope.sh" "${@:2}" ); }
    # «salientes on» añade VOICE_OUTBOUND_SCOPE=canary y «salientes off» no la quita (diseño del mando, igual en HEAD): la
    # ida y vuelta funcional deja el fichero igual SALVO esa línea; la exacta es la copia previa. Después se repone.
    igual_salvo_alcance() { caso "$1" "$(python3 -I -c 'import re, sys
f = lambda p: [l for l in open(p, encoding="utf-8").read().splitlines() if not re.match(r"[ \t]*VOICE_OUTBOUND_SCOPE=", l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$2" && echo 0 || echo 1)"; }
    repon() { install -m 600 "$1" "$ENVF"; cache_banco; rm -f "$B"/root/env-pre-voice-*; }
    gira() { # abre la constante en el CLON del banco con el commit de una línea `voice(calls-open):` (nunca en el repo)
      local f="$BE/app/Services/Voice/VoiceCallPolicy.php"
      sed -i '' 's/^    public const CALLS_ENABLED_IN_CODE = false;$/    public const CALLS_ENABLED_IN_CODE = true;/' "$f"
      git -C "$B/api" -c user.name=banco -c user.email=banco@banco.invalid commit -q -am "voice(calls-open): banco de telefonía (C-1), solo en el clon del banco" -m "Autorización: ensayo local, sin producción."
    }
    COD0=$(git -C "$B/api" rev-parse HEAD)
    echo "#### partida: constante cerrada, sin piezas de Twilio, salientes apagadas"
    paso "salientes on con la constante CERRADA → se niega (la constante va antes que el presupuesto)" 2 "la constante VoiceCallPolicy::CALLS_ENABLED_IN_CODE está cerrada" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    igual "  … el fichero no cambió" "$B/root/env-ref-0"
    echo "#### las piezas de la fase 2 con el mando (sintéticas; el número declarado «Outbound Only», W1/C-5)"
    ( umask 077; python3 -I -c 'import secrets; print("TWILIO_VOICE_API_KEY_SID=SK" + secrets.token_hex(16)); print("TWILIO_VOICE_API_KEY_SECRET=" + secrets.token_hex(16))' > "$B/root/tw-voz.txt" )
    paso "fase 2 secreto <fichero 600> → APLICADO (y el fichero destruido)" 0 "MANDO fase2-secreto APLICADO" mando h8-voice-scope.sh fase 2 secreto "$B/root/tw-voz.txt"
    paso "fase 2 set de la cuenta, el número, el id importado, la base y VOICE_NUMBER_INBOUND_CAPABLE=false → APLICADO" 0 "MANDO fase2-set APLICADO" mando h8-voice-scope.sh fase 2 set "TWILIO_VOICE_ACCOUNT_SID=AC$(python3 -I -c 'import secrets; print(secrets.token_hex(16))')" TWILIO_VOICE_FROM=+576080000001 "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$(pieza EL_PHONE_NUMBER_ID)" VOICE_WEBHOOK_BASE_URL=https://voz.banco-telefonia.test VOICE_NUMBER_INBOUND_CAPABLE=false
    paso "estado: «NO: «Outbound Only» declarado» y la reserva informativa de siempre" 0 "NO: «Outbound Only» declarado" mando h8-voice-scope.sh estado
    install -m 600 "$ENVF" "$B/root/env-ref-piezas"
    gira
    paso "salientes on SIN el worker ironbody-voice (13 programas) → se niega" 2 "el worker ironbody-voice no está en RUNNING" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    : > "$B/run/worker-voz"
    paso "estado con la constante abierta (commit voice(calls-open) en el clon) y 14 workers" 0 "constante de llamadas: abierta" mando h8-voice-scope.sh estado
    echo "#### C-1 · el mando de HEAD (lo instalado hoy) acepta lo que la puerta denegaría"
    paso "HEAD: salientes on --presupuesto=0.70 → APLICADO (el defecto: 0.70 ≥ 0.6105 sin transferencia)" 0 "MANDO salientes-on APLICADO" mando h8-voice-scope-head.sh salientes on --cupo=1 --presupuesto=0.70
    caso "  … y la regla de la puerta (VoiceCostGuard::fits con la reserva de OutboundGate a un móvil) NO lo cubre" "$([ "$(tinker '$g = App\Services\Telephony\VoiceCostGuard::class; echo json_encode($g::fits($g::budgetFromConfig(), 0, $g::reserveMicros($g::KIND_MOBILE, (int) config("voice.limits.per_call_max_seconds", 300), $g::KIND_LANDLINE, $g::prices()))), PHP_EOL;')" = false ] && echo 0 || echo 1)"
    paso "HEAD: salientes off → APLICADO (vuelve)" 0 "MANDO salientes-off APLICADO" mando h8-voice-scope-head.sh salientes off
    igual_salvo_alcance "  … ida y vuelta con el de HEAD: el fichero igual al de las piezas salvo VOICE_OUTBOUND_SCOPE=canary" "$B/root/env-ref-piezas"
    repon "$B/root/env-ref-piezas"
    echo "#### C-1 · el mando del árbol: rechazos con las cifras de la puerta (código 2, sin copia, sin tocar el fichero)"
    MIN=$(python3 -I -c 'print(5 * (80000 + 37700 + 4400 + 70000), 5 * (80000 + 70000 + 4400 + 70000))')   # independiente: DEFAULT_PRICES a mano
    caso "  (a mano, con los precios del 03-11: un móvil con transferencia 960500 µUS$ y otro destino 1122000)" "$([ "$MIN" = '960500 1122000' ] && echo 0 || echo 1)" "$MIN"
    for p in 0.61 0.70 0.96 0.97 1.00 1.12; do
      paso "salientes on --presupuesto=$p → se niega con las cifras (0.9605 móvil · 1.1220 otro · mínimo 1.13)" 2 "0.9605 US\$ a un móvil colombiano (+573…) · 1.1220 US\$ a cualquier otro destino · peor caso 1.1220 US\$ (presupuesto mínimo: 1.13)" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=$p
      igual "  … el fichero no cambió" "$B/root/env-ref-piezas"
    done
    caso "  … ningún rechazo hizo copia previa" "$([ "$(ls "$B"/root/env-pre-voice-* 2> /dev/null | wc -l | tr -d ' ')" = 0 ] && echo 0 || echo 1)"
    echo "#### C-1 · la fórmula de la puerta cambiada en el código desplegado → se niega (no adivina)"
    G="$BE/app/Services/Telephony/OutboundGate.php"; cp -p "$G" "$B/root/OutboundGate.php.orig"
    python3 -I - "$G" <<'PY'
import sys
p = sys.argv[1]; s = open(p, encoding="utf-8").read()
v = "$maxima, VoiceCostGuard::KIND_LANDLINE, $precios);"
assert s.count(v) == 2
open(p, "w", encoding="utf-8").write(s.replace(v, "$maxima, null, $precios);", 1))
PY
    paso "OutboundGate reserva SIN transferencia en UNO de sus dos caminos (decide) → se niega: la reserva de la puerta ya no es la que conoce el mando" 2 "la reserva de la puerta ya no es la que conoce este mando" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    igual "  … el fichero no cambió" "$B/root/env-ref-piezas"
    cp -p "$B/root/OutboundGate.php.orig" "$G"; rm -f "$B/root/OutboundGate.php.orig"
    caso "  … OutboundGate.php restaurado (el clon sin cambios sin confirmar)" "$(git -C "$B/api" diff --quiet && echo 0 || echo 1)"
    echo "#### C-1 · casos felices: la frontera 1.13 y el 2.00 de la autorización"
    paso "salientes on --presupuesto=1.13 → APLICADO, y dice las cifras" 0 "MANDO salientes-on APLICADO" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=1.13
    caso "  … imprime «peor caso 1.1220 … el presupuesto (1.13 US$) la cubre»" "$(printf '%s' "$ULTIMA" | grep -qF 'peor caso 1.1220 US$ (presupuesto mínimo: 1.13); el presupuesto (1.13 US$) la cubre' && echo 0 || echo 1)"
    caso "  … la regla de la puerta, leída aparte, cubre el peor caso con la configuración efectiva" "$([ "$(tinker '$g = App\Services\Telephony\VoiceCostGuard::class; echo json_encode($g::fits($g::budgetFromConfig(), 0, $g::reserveMicros($g::KIND_LANDLINE, (int) config("voice.limits.per_call_max_seconds", 300), $g::KIND_LANDLINE, $g::prices()))), PHP_EOL;')" = true ] && echo 0 || echo 1)"
    paso "salientes off → APLICADO" 0 "MANDO salientes-off APLICADO" mando h8-voice-scope.sh salientes off
    igual_salvo_alcance "  … ida y vuelta: el fichero igual al de las piezas salvo VOICE_OUTBOUND_SCOPE=canary" "$B/root/env-ref-piezas"
    repon "$B/root/env-ref-piezas"
    paso "salientes on --cupo=1 --presupuesto=2.00 (la orden autorizada) → APLICADO" 0 "MANDO salientes-on APLICADO" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    paso "repetirla → nada que escribir (idempotente)" 0 "nada que escribir" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    paso "salientes off → APLICADO" 0 "MANDO salientes-off APLICADO" mando h8-voice-scope.sh salientes off
    igual_salvo_alcance "  … ida y vuelta: el fichero igual al de las piezas salvo VOICE_OUTBOUND_SCOPE=canary" "$B/root/env-ref-piezas"
    repon "$B/root/env-ref-piezas"
    echo "#### fallos forzados tras escribir (paso 1: Dotenv) y tras la caché (paso 3): ABORTA con la vuelta exacta, que se ejecuta"
    for f in parsea cache; do
      paso "fallo forzado ($f) → ABORTA, código 1, con la vuelta impresa" 1 "Vuelta: install -m" mando_falla "$f" salientes on --cupo=1 --presupuesto=2.00
      V=$(printf '%s\n' "$ULTIMA" | grep -oE 'install -m .*queue:restart -q' | tail -1)
      caso "  … la vuelta exacta impresa sale con 0" "$( ( export "${RED[@]}"; eval "$V" ) > /dev/null 2>&1 && echo 0 || echo 1)"
      igual "  … y deja el fichero idéntico byte a byte al de antes" "$B/root/env-ref-piezas"
      modo_env "  … tras la vuelta ($f)" 600
      cache_ok "  … tras la vuelta ($f)"
      caso "  … la configuración efectiva vuelve a salientes apagadas, cupo 0 y presupuesto 0" "$(tinker 'echo json_encode([config("voice.outbound.enabled"), (int) config("voice.twilio.daily_call_cap"), (string) config("voice.limits.daily_budget_usd")]), PHP_EOL;' | grep -qxE '\[false,0,"0(\.00)?"\]' && echo 0 || echo 1)" "$(tinker 'echo json_encode([config("voice.outbound.enabled"), (int) config("voice.twilio.daily_call_cap"), (string) config("voice.limits.daily_budget_usd")]), PHP_EOL;')"
    done
    echo "#### el cerrojo del mando ocupado por otro proceso"
    toma_cerrojo "$B/run/h8-voice-scope.lock"
    paso "con h8-voice-scope.lock tomado → ABORTA sin tocar nada" 1 "otro mando de la voz está en curso" mando h8-voice-scope.sh salientes on --cupo=1 --presupuesto=2.00
    suelta_cerrojo
    igual "  … el fichero no cambió" "$B/root/env-ref-piezas"
    echo "#### cierre: la constante se cierra con la vuelta del runbook (checkout del commit anterior) y el entorno de partida"
    rm -f "$B/run/worker-voz"
    git -C "$B/api" checkout -q --detach "$COD0"
    caso "constante cerrada otra vez en el clon (checkout del commit de antes)" "$(grep -qxF '    public const CALLS_ENABLED_IN_CODE = false;' "$BE/app/Services/Voice/VoiceCallPolicy.php" && echo 0 || echo 1)"
    ;;
# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
  el)
    copia "$OPS/el-verificar.sh" "$B/root/el-verificar.sh" "$(reglas_el)" || muere "copia de el-verificar.sh"
    head_de el-verificar.sh el-verificar-head.orig
    copia "$B/root/el-verificar-head.orig" "$B/root/el-verificar-head.sh" "$(reglas_el_head)" || muere "copia de el-verificar.sh de HEAD"
    rm -f "$B/root/el-verificar-head.orig" "$B"/root/env-pre-elevenlabs-* "$B"/root/el-clave*.txt
    el() { ( export "${RED[@]}"; bash "$B/root/el-verificar.sh" "$@" ); }
    el_falla() { ( export "${RED[@]}" BANCO_FALLA="$1"; bash "$B/root/el-verificar.sh" "${@:2}" ); }
    el_head() { ( export "${RED[@]}"; bash "$B/root/el-verificar-head.sh" "$@" ); }
    copias() { ls "$B"/root/env-pre-elevenlabs-* 2> /dev/null | wc -l | tr -d ' '; }
    clave_a() { install -m 600 "$B/sim/clave-$1" "$B/root/el-clave.txt"; }   # la clave «de la consola» al fichero 600
    clave_mala() { ( umask 077; python3 -I -c 'import secrets; print("xi_" + secrets.token_hex(24))' > "$B/root/el-clave.txt" ); }
    E0=$(efectiva)
    echo "#### partida: $(ef "$E0" proveedor) · Studio $(ef "$E0" studio) · ELEVENLABS_ENABLED $(ef "$E0" enabled)"
    caso "partida: ElevenLabs verificado contra el simulador (el estado de producción)" "$([ "$(ef "$E0" proveedor)" = ELEVENLABS_VERIFIED ] && [ "$(ef "$E0" studio)" = ELEVENLABS_VERIFIED ] && echo 0 || echo 1)" "$(ef "$E0" proveedor) · $(ef "$E0" studio)"
    sim_rota 1   # la clave nueva «en la consola»; la de antes deja de valer en el simulador
    echo "#### rechazos (código 1, sin copia y sin tocar nada; el fichero de la clave se queda)"
    : > "$B/root/el-clave.txt"
    paso "fichero de la clave vacío → ABORTA" 1 "vacío" el "$B/root/el-clave.txt" --solo-clave
    igual "  … el fichero no cambió" "$B/root/env-ref-0"
    clave_a 1
    paso "agent_id con forma inválida → ABORTA" 1 "agent_id con forma inválida" el "$B/root/el-clave.txt" 'agente;rm'
    chmod 640 "$ENVF"
    paso "fichero de entorno en 640 → ABORTA (el-verificar exige www-data 600)" 1 "fichero de entorno en" el "$B/root/el-clave.txt" --solo-clave
    chmod 600 "$ENVF"
    for c in ironbody-env h8-voice-scope h9-outreach-scope; do
      toma_cerrojo "$B/run/$c.lock"
      paso "con $c.lock tomado por otro proceso → ABORTA sin tocar nada" 1 "otro mando que escribe el fichero de entorno está en curso" el "$B/root/el-clave.txt" --solo-clave
      suelta_cerrojo
      igual "  … el fichero no cambió" "$B/root/env-ref-0"
      caso "  … sin copia previa y el fichero de la clave sigue (para repetir)" "$([ "$(copias)" = 0 ] && [ -s "$B/root/el-clave.txt" ] && echo 0 || echo 1)" "copias $(copias)"
    done
    echo "#### HEAD (lo de hoy): sin cerrojos, y su fallo deja ElevenLabs sin verificar (y propone apagarlo)"
    toma_cerrojo "$B/run/ironbody-env.lock"
    paso "HEAD con ironbody-env.lock tomado → NO lo mira: escribe y verifica igual" 0 "ELEVENLABS VERIFICACIÓN TERMINADA" el_head "$B/root/el-clave.txt" --solo-clave
    suelta_cerrojo
    install -m 600 "$B/root/env-ref-0" "$ENVF"; cache_banco
    clave_mala
    paso "HEAD con una clave que el simulador rechaza → FALLIDA y propone ELEVENLABS_ENABLED=false" 1 "ELEVENLABS_ENABLED=false" el_head "$B/root/el-clave.txt" --solo-clave
    EH=$(efectiva)
    caso "  … HEAD deja puesta la clave que NO verificó, con ELEVENLABS_ENABLED=true (no vuelve a la de antes)" "$([ "$(ef "$EH" key)" != "$(ef "$E0" key)" ] && [ "$(ef "$EH" enabled)" = True ] && echo 0 || echo 1)" "telefonía $(ef "$EH" proveedor) · Studio $(ef "$EH" studio)"
    pon_bandera_falsa() { python3 -I -c 'import re, sys
p = sys.argv[1]; s = open(p, encoding="utf-8").read()
open(p, "w", encoding="utf-8").write(re.sub(r"^([ \t]*)ELEVENLABS_ENABLED[ \t]*=.*$", r"\1ELEVENLABS_ENABLED=false", s, count=1, flags=re.M))' "$ENVF"; }
    pon_bandera_falsa; cache_banco
    EHV=$(efectiva)
    caso "  … y la vuelta que propone (ELEVENLABS_ENABLED=false y la caché), aplicada, APAGA el proveedor de la voz (laboratorio incluido)" "$([ "$(ef "$EHV" proveedor)" = ELEVENLABS_DISABLED ] && echo 0 || echo 1)" "telefonía $(ef "$EHV" proveedor)"
    install -m 600 "$B/root/env-ref-0" "$ENVF"; cache_banco
    caso "  (banco devuelto a la partida a mano)" "$(cmp -s "$ENVF" "$B/root/env-ref-0" && [ "$(ef "$(efectiva)" voz)" = "$(ef "$E0" voz)" ] && echo 0 || echo 1)"
    rm -f "$B"/root/env-pre-elevenlabs-* "$B/root/el-clave.txt"
    E0=$(efectiva)   # el de HEAD renovó la marca de 30 días (está en la base, no en el fichero): la partida de los fallos es esta
    echo "#### fallos en CADA etapa: vuelta atrás automática y COMPROBADA, sin apagar ElevenLabs"
    for f in parsea cache2 bandera cache3 senal verify; do
      if [ "$f" = verify ]; then clave_mala; else clave_a 1; fi
      N0=$(copias)
      if [ "$f" = senal ]; then
        # SIGTERM a mitad (tras la caché con la bandera): la trampa EXIT restaura igual; bash vuelve a lanzar la señal al
        # terminar, así que el código es 143 (128 + 15), no 1.
        paso "etapa señal (SIGTERM tras la caché con la bandera) → código 143, restaurado y COMPROBADO" 143 "restaurado y COMPROBADO" el_falla "$f" "$B/root/el-clave.txt" --solo-clave
      elif [ "$f" = verify ]; then
        paso "etapa doctor --verify (clave que el simulador rechaza: 401) → código 1, restaurado y COMPROBADO" 1 "restaurado y COMPROBADO" el "$B/root/el-clave.txt" --solo-clave
      else
        paso "etapa $f (forzado) → código 1, restaurado y COMPROBADO" 1 "restaurado y COMPROBADO" el_falla "$f" "$B/root/el-clave.txt" --solo-clave
      fi
      caso "  … dice que ElevenLabs NO se apaga y termina en «VERIFICACIÓN FALLIDA (restaurado: sí)»" "$(printf '%s' "$ULTIMA" | grep -qF 'ElevenLabs NO se apaga' && printf '%s' "$ULTIMA" | grep -qF 'ELEVENLABS VERIFICACIÓN FALLIDA (restaurado: sí)' && echo 0 || echo 1)"
      igual "  … el fichero, idéntico byte a byte al de antes" "$B/root/env-ref-0"
      modo_env "  … tras $f" 600
      cache_ok "  … tras $f"
      EF=$(efectiva)
      caso "  … configuración efectiva la de antes: ELEVENLABS_ENABLED=true, la clave de antes, proveedor y Studio VERIFIED (independiente)" "$([ "$EF" = "$E0" ] && echo 0 || echo 1)" "$(ef "$EF" enabled) · $(ef "$EF" proveedor) · $(ef "$EF" studio)"
      caso "  … una copia previa nueva (600) y ningún temporal .env.tmp-*" "$([ "$(copias)" = $((N0 + 1)) ] && [ "$(temporales)" = 0 ] && [ "$(stat -c '%a' "$(ls -t "$B"/root/env-pre-elevenlabs-* | head -1)")" = 600 ] && echo 0 || echo 1)"
      if [ "$f" = verify ]; then
        caso "  … el fichero de la clave que no verificó se destruyó (REV8 m-5)" "$([ ! -e "$B/root/el-clave.txt" ] && echo 0 || echo 1)"
      else
        caso "  … el fichero de la clave sigue (600) para repetir, y lo avisa" "$([ -s "$B/root/el-clave.txt" ] && printf '%s' "$ULTIMA" | grep -qF 'sigue en disco' && echo 0 || echo 1)"
      fi
    done
    echo "#### la restauración que falla: lo dice y da la orden a mano, que se ejecuta"
    clave_a 1
    paso "etapa cache2 con la caché de la vuelta rota → código 1, restauración INCOMPLETA y la orden a mano" 1 "restauración INCOMPLETA: vuelta a mano: install -m" env BANCO_FALLA_VUELTA=1 bash -c "$(declare -f el_falla); RED=(${RED[*]}); B='$B'; el_falla cache2 '$B/root/el-clave.txt' --solo-clave"
    V=$(printf '%s\n' "$ULTIMA" | sed -n 's/^   ✗ restauración INCOMPLETA: vuelta a mano: //p' | tail -1)
    caso "  … la orden a mano sale con 0" "$( ( export "${RED[@]}"; eval "$V" ) > /dev/null 2>&1 && echo 0 || echo 1)"
    igual "  … y deja el fichero idéntico al de antes" "$B/root/env-ref-0"
    caso "  … y la configuración efectiva la de antes" "$([ "$(efectiva)" = "$E0" ] && echo 0 || echo 1)"
    echo "#### casos felices: --solo-clave (la rotación de §4.9) y el modo completo"
    clave_a 1
    H_RESTO0=$(for k in ELEVENLABS_AGENT_ID ELEVENLABS_REGION ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_INITIATION_SECRET ELEVENLABS_BASE_URL; do env_val_sha "$k"; done | tr '\n' ' ')
    paso "el-verificar --solo-clave con la clave nueva → ELEVENLABS VERIFICACIÓN TERMINADA" 0 "ELEVENLABS VERIFICACIÓN TERMINADA" el "$B/root/el-clave.txt" --solo-clave
    E1=$(efectiva)
    caso "  … la clave del fichero de entorno es la nueva (huella), y el agente, la región, los tres secretos y la base siguen igual" "$([ "$(env_val_sha ELEVENLABS_API_KEY)" = "$(sha "$B/sim/clave-1")" ] && [ "$(for k in ELEVENLABS_AGENT_ID ELEVENLABS_REGION ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_INITIATION_SECRET ELEVENLABS_BASE_URL; do env_val_sha "$k"; done | tr '\n' ' ')" = "$H_RESTO0" ] && echo 0 || echo 1)"
    caso "  … telefonía y Studio ELEVENLABS_VERIFIED con la clave nueva, ELEVENLABS_ENABLED=true y la marca de 30 días RENOVADA" "$([ "$(ef "$E1" proveedor)" = ELEVENLABS_VERIFIED ] && [ "$(ef "$E1" studio)" = ELEVENLABS_VERIFIED ] && [ "$(ef "$E1" enabled)" = True ] && [ "$(ef "$E1" marca)" != "$(ef "$E0" marca)" ] && echo 0 || echo 1)" "$(ef "$E1" proveedor) · $(ef "$E1" studio) · $(ef "$E1" enabled) · marca $(ef "$E0" marca) → $(ef "$E1" marca)"
    caso "  … el fichero de la clave destruido; la vuelta impresa es restaurar la copia (no apagar ElevenLabs)" "$([ ! -e "$B/root/el-clave.txt" ] && printf '%s' "$ULTIMA" | grep -qF 'vuelta atrás (solo mientras la clave de antes no esté revocada en la consola): install -m' && ! printf '%s' "$ULTIMA" | grep -qF 'ELEVENLABS_ENABLED=false' && echo 0 || echo 1)"
    modo_env "  … tras la rotación" 600
    cache_ok "  … tras la rotación"
    V=$(printf '%s\n' "$ULTIMA" | sed -n 's/^   vuelta atrás (solo mientras la clave de antes no esté revocada en la consola): //p' | tail -1)
    caso "  la vuelta impresa tras el éxito (con la clave de antes aún válida) sale con 0" "$( ( export "${RED[@]}"; eval "$V" ) > /dev/null 2>&1 && echo 0 || echo 1)"
    igual "  … y deja el fichero idéntico al de antes de la rotación" "$B/root/env-ref-0"
    caso "  … y la configuración efectiva de la voz la de antes (la marca, renovada, se queda: es de la base, no del fichero)" "$([ "$(ef "$(efectiva)" voz)" = "$(ef "$E0" voz)" ] && echo 0 || echo 1)"
    sim_rota 2; clave_a 2
    paso "el-verificar <agent_id> (modo completo, alta) → TERMINADA" 0 "modo: completo" el "$B/root/el-clave.txt" "$(pieza EL_VERIFY_AGENT_ID)"
    caso "  … TERMINADA y ELEVENLABS_VERIFIED" "$(printf '%s' "$ULTIMA" | grep -qF 'ELEVENLABS VERIFICACIÓN TERMINADA' && [ "$(ef "$(efectiva)" proveedor)" = ELEVENLABS_VERIFIED ] && echo 0 || echo 1)"
    echo "#### cierre"
    caso "ninguna clave en ninguna salida ni en el registro del ensayo" "$(K1=$(cat "$B/sim/clave-1"); K2=$(cat "$B/sim/clave-2"); ! grep -qF -e "$K1" -e "$K2" -e "$(pieza EL_TOOL_SECRET)" "$LOG" && echo 0 || echo 1)"
    caso "copias previas en 600 ($(copias))" "$([ "$(for f in "$B"/root/env-pre-elevenlabs-*; do stat -c '%a' "$f"; done | sort -u | tr -d '\n')" = 600 ] && echo 0 || echo 1)"
    # el simulador vuelve a la clave de partida para el siguiente ensayo
    python3 -I - "$B/sim/piezas.conf" "$B/root/env-ref-0" <<'PY'
import re, sys
p, ref = sys.argv[1:3]
k = re.search(r"^[ \t]*ELEVENLABS_API_KEY=(.*)$", open(ref, encoding="utf-8").read(), re.M).group(1).strip()
s = [("EL_API_KEY=" + k) if l.startswith("EL_API_KEY=") else l for l in open(p).read().splitlines()]
open(p, "w").write("\n".join(s) + "\n")
PY
    para sim-elevenlabs; sleep 0.3; arranca_sim
    rm -f "$B"/root/env-pre-elevenlabs-* "$B"/sim/clave-* "$B/root/el-clave.txt"
    ;;
# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
  monitor)
    copia "$OPS/h7-monitor-scope.sh" "$B/root/h7-monitor-scope.sh" "$(reglas_h7)" || muere "copia del mando del H7"
    head_de h7-monitor-scope.sh h7-monitor-scope-head.orig
    copia "$B/root/h7-monitor-scope-head.orig" "$B/root/h7-monitor-scope-head.sh" "$(reglas_h7)" || muere "copia del mando del H7 de HEAD"
    rm -f "$B/root/h7-monitor-scope-head.orig" "$B"/root/env-pre-h7-monitor-*
    mando() { ( export "${RED[@]}"; bash "$B/root/$1" "${@:2}" ); }
    ultimo_monitor() { grep -h 'ultron_studio.monitor' "$BE"/storage/logs/laravel*.log 2> /dev/null | tail -1; }
    # El estado de producción del monitor del H7: la expectativa «fase1» (ElevenLabs verificado, sin número de voz), puesta
    # con el mando de la voz, que la comprueba con integrations-doctor antes de escribirla.
    copia "$OPS/h8-voice-scope.sh" "$B/root/h8-voice-scope.sh" "$(reglas_voz)" || muere "copia del mando de la voz"
    paso "preparación: h8-voice-scope.sh expectativa fase1 (la de producción) → APLICADO" 0 "MANDO expectativa-fase1 APLICADO" mando h8-voice-scope.sh expectativa fase1
    install -m 600 "$ENVF" "$B/root/env-ref-exp"; rm -f "$B"/root/env-pre-voice-*
    for v in head arbol; do
      f=$([ "$v" = head ] && echo h7-monitor-scope-head.sh || echo h7-monitor-scope.sh)
      install -m "$MODO_ORIG" "$B/root/env-ref-exp" "$ENVF"; cache_banco
      paso "$v: «on off» → APLICADO" 0 "MANDO monitor → on APLICADO" mando "$f" on off
      L=$(ultimo_monitor)
      if [ "$v" = head ]; then
        caso "  … HEAD: el ensayo informativo es «publicación e integraciones» y el registro del monitor NO trae prompts" "$(printf '%s' "$ULTIMA" | grep -qF 'monitor --dry-run (publicación e integraciones)' && printf '%s' "$L" | grep -qF '"dry_run":true' && ! printf '%s' "$L" | grep -qF '"prompts"' && echo 0 || echo 1)"
      else
        caso "  … árbol: el ensayo informativo es «publicación, integraciones y prompts»: 0" "$(printf '%s' "$ULTIMA" | grep -qF 'monitor --dry-run (publicación, integraciones y prompts): 0' && echo 0 || echo 1)"
        caso "  … y el registro del monitor (en seco) trae las TRES comprobaciones, prompts incluida (independiente)" "$(printf '%s' "$L" | grep -qF '"dry_run":true' && printf '%s' "$L" | grep -qF '"prompts":' && printf '%s' "$L" | grep -qF '"publication":' && printf '%s' "$L" | grep -qF '"integrations":' && echo 0 || echo 1)" "$(printf '%s' "$L" | grep -oE '"checks":\{[^}]*\}')"
      fi
      paso "$v: «off on» → APLICADO" 0 "MANDO monitor → off APLICADO" mando "$f" off on
      igual "  … ida y vuelta: el fichero idéntico al de antes de encender" "$B/root/env-ref-exp"
    done
    rm -f "$B"/root/env-pre-h7-monitor-*
    ;;
esac

echo "#### cierre común"
V1=$(valla_total)
caso "CERO red: la valla no recibió ninguna petición en todo el ensayo" "$([ "$V0" = "$V1" ] && [ "$V0" != '?' ] && echo 0 || echo 1)" "valla: $V0 → $V1"
install -m "$MODO_ORIG" "$B/root/env-ref-0" "$ENVF"; cache_banco
caso "el banco vuelve a su fichero de entorno de partida, su modo ($MODO_ORIG) y su caché" "$(cmp -s "$ENVF" "$B/root/env-ref-0" && [ "$(stat -c '%a' "$ENVF")" = "$MODO_ORIG" ] && echo 0 || echo 1)"
rm -f "$B"/root/env-ref-* "$B"/root/env-pre-voice-* "$B/run/worker-voz"
set -e
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO (registro: \$B/logs/$(basename "$LOG"))"
[ "$FALLOS" = 0 ]
