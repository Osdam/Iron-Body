#!/bin/bash
# OPS8-f (22-11-2026) · el ensayo de la ENMIENDA 20 (decisión expresa del propietario, MATRIZ_CIERRE_V2.md §9: el número
# empresarial, el visible de WhatsApp, como ORIGEN de las salientes de Sofía, en la cuenta principal de Twilio y sin
# subcuenta) en los dos mandos que cambian: h8-voice-scope.sh (la declaración «numero-compartido», «fase 2 secreto
# --terminal», la regla del visible en «fase 2 set», el veredicto del servidor en estado, salientes on y la comprobación
# posterior, entrantes on y la conciliación en seco de monitor on) y h8-twilio-check.sh (el veredicto «APTA · cuenta
# principal», el visible como identidad compartida y lo que NO comprueba). SIN producción y SIN red: ElevenLabs y Twilio
# son los simuladores del banco en 127.0.0.1 y todo proceso PHP sale por la valla.
# Uso (el banco vive FUERA del repo):
#   B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h8-ops8f
#   bash ensayar-ops8f.sh "$B" montar     copia la «producción» del banco de telefonía (BANCO_ORIGEN, por defecto
#                                         ~/.cache/ironbody-banco-telefonia: el entorno sintético de producción con
#                                         ElevenLabs), base PROPIA (CREATE DATABASE … TEMPLATE), el código en HEAD del
#                                         worktree con sus migraciones como h5_owner, piezas SINTÉTICAS de los dos
#                                         simuladores y un flock de verdad
#   bash ensayar-ops8f.sh "$B" arrancar   la valla, los dos simuladores y la API (la primera vez, la verificación de
#                                         ElevenLabs contra el simulador: el estado de producción)
#   bash ensayar-ops8f.sh "$B" ensayar    los casos; cada orden completa queda en $B/logs/ensayo-ops8f-<fecha>.txt (600)
#   bash ensayar-ops8f.sh "$B" parar | borrar
# Puertos: BANCO_PORT 8461 (API), BANCO_PUERTO_EL 8462, BANCO_PUERTO_TW 8463, BANCO_PUERTO_VALLA 8464. Base:
# ultron_studio_banco_h8_ops8f (la que admite ayudante-ops8d.php). Las copias de los mandos solo cambian su bloque «lo
# propio de producción» y la comprobación de root (las mismas sustituciones que preparar.sh, que casan las veces exactas).
# Los números son SINTÉTICOS: el visible de WhatsApp +57 300 000 0050, otro protegido +57 300 000 0051 y el dedicado
# +57 601 000 0090; la constante de llamadas solo se abre en el clon del banco, con un commit `voice(calls-open): banco …`
# que se revierte al terminar.
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
ORDEN=${2:-}
ORIGEN=$(banco_dir "${BANCO_ORIGEN:-${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-telefonia}")
DB_NUEVA=ultron_studio_banco_h8_ops8f
P_API=${BANCO_PORT:-8461}; P_EL=${BANCO_PUERTO_EL:-8462}; P_TW=${BANCO_PUERTO_TW:-8463}; P_VALLA=${BANCO_PUERTO_VALLA:-8464}
ME=$(whoami); GRUPO=$(id -gn)
VISIBLE=+573000000050; PROTEGIDO=+573000000051; DEDICADO=+576010000090
hex() { python3 -I -c 'import secrets, sys; print(secrets.token_hex(int(sys.argv[1])))' "$1"; }
# Las migraciones de HEAD como h5_owner (el modelo de roles de producción) y las cachés, sin red (proxy a un puerto cerrado).
migrar() {
  local SINRED=(PGGSSENCMODE=disable http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
  ( cd "$B/api/backend" && env "${SINRED[@]}" php artisan config:clear -q && env "${SINRED[@]}" php artisan route:clear -q \
    && env "${SINRED[@]}" DB_USERNAME=h5_owner ULTRON_STUDIO_DB_APP_ROLE=h5_app php artisan migrate --force -q \
    && env "${SINRED[@]}" php artisan config:cache -q && env "${SINRED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php )
}

if [ "$ORDEN" = montar ]; then
  [ ! -e "$B/banco.env" ] || muere "$B ya está montado (para empezar de cero: bash $0 $B borrar)"
  [ -f "$ORIGEN/banco.env" ] || muere "falta el banco de origen en $ORIGEN (BANCO_ORIGEN): el de banco-h9/telefonia-prep.sh"
  O_DB=$(. "$ORIGEN/banco.env"; echo "$BANCO_DB"); O_ADMIN=$(. "$ORIGEN/banco.env"; echo "${ADMIN_ID:-}"); O_COMMIT=$(git -C "$ORIGEN/api" rev-parse HEAD)
  [ -n "$O_DB" ] && [ -n "$O_ADMIN" ] || muere "el banco de origen no tiene BANCO_DB y ADMIN_ID"
  [ "$(psql_su -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = '$DB_NUEVA'")" = 0 ] || muere "la base $DB_NUEVA ya existe (bash $0 $B borrar)"
  [ "$(psql_su -d postgres -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '$O_DB'")" = 0 ] || muere "la base del banco de origen ($O_DB) tiene conexiones: parar ese banco antes"
  git -C "$ORIGEN/api" diff --quiet && git -C "$ORIGEN/api" diff --cached --quiet || muere "la «producción» del banco de origen tiene cambios sin confirmar"
  for p in "$P_API" "$P_EL" "$P_TW" "$P_VALLA"; do ! lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1 || muere "el puerto $p ya está ocupado"; done
  mkdir -p "$B"/{root,run,logs,sim,bin}; chmod 700 "$B" "$B/root" "$B/sim"
  echo "== 1. la «producción»: copia de $ORIGEN/api y el código en HEAD del worktree"
  cp -Rp "$ORIGEN/api" "$B/api"
  HEADC=$(git -C "$WT" rev-parse HEAD)
  git -C "$B/api" fetch -q "$WT" "$HEADC" && git -C "$B/api" -c advice.detachedHead=false checkout -q --detach "$HEADC" || muere "no se pudo poner HEAD en el clon del banco"
  git -C "$B/api" diff --quiet "$O_COMMIT" "$HEADC" -- backend/composer.lock || muere "composer.lock cambió entre el origen y HEAD: el vendor copiado no vale"
  echo "   código: $(git -C "$B/api" rev-parse --short=8 HEAD) · migraciones nuevas desde el origen: $(git -C "$B/api" diff --name-only "$O_COMMIT" HEAD -- backend/database/migrations | wc -l | tr -d ' ')"
  echo "== 2. base propia: $DB_NUEVA (CREATE DATABASE … TEMPLATE $O_DB)"
  psql_su -d postgres -q -c "CREATE DATABASE $DB_NUEVA TEMPLATE $O_DB"
  echo "== 3. piezas SINTÉTICAS (sim/piezas.conf, 600) y el fichero de entorno (fase 1 con ElevenLabs, la voz apagada, sin piezas de Twilio)"
  python3 -I - "$B/api/backend/.env" "$B/sim/piezas.conf" "$DB_NUEVA" "$P_API" "$P_EL" "$P_TW" "$VISIBLE" "$PROTEGIDO" "$DEDICADO" <<'PY'
import os, re, secrets, sys
envp, pzp, db, papi, pel, ptw, visible, protegido, dedicado = sys.argv[1:10]
h = secrets.token_hex
s = open(envp, encoding="utf-8").read()
def lee(k):
    m = re.search(r"^[ \t]*" + re.escape(k) + r"[ \t]*=[ \t]*(.*?)[ \t]*$", s, re.M)
    return m.group(1).strip('"') if m else ""
otp_sid, otp_tok = lee("TWILIO_SID"), lee("TWILIO_TOKEN")
if not re.fullmatch(r"AC[0-9a-f]{32}", otp_sid) or len(otp_tok) < 16:
    sys.exit("el fichero de entorno del origen no trae un TWILIO_SID/TWILIO_TOKEN sintético con forma (no se imprime)")
pz = {
    "LARAVEL_BASE": f"http://127.0.0.1:{papi}", "EL_BASE": f"http://127.0.0.1:{pel}", "EL_TWILIO_BASE": f"http://127.0.0.1:{ptw}",
    "EL_API_KEY": "xi_" + h(24), "EL_WEBHOOK_SECRET": "wsec_" + h(24), "EL_TOOL_SECRET": "tool_" + h(24), "EL_INITIATION_SECRET": "ini_" + h(24),
    "EL_INITIATION_HEADER": "X-Voice-Initiation-Secret", "EL_VERIFY_AGENT_ID": "agent_verificacion_" + h(4), "EL_PHONE_NUMBER_ID": "phnum_" + h(8),
    "EL_MAX_RETENTION_DAYS": "30", "VOICE_NUMBER": dedicado, "WHATSAPP_NUMBER": visible,
    "TW_ACCOUNT_SID": "AC" + h(16), "TW_MASTER_SID": otp_sid, "TW_MASTER_TOKEN": otp_tok, "TW_SIGNING_TOKEN": h(16),
    "TW_LARAVEL_KEY_SID": "SK" + h(16), "TW_LARAVEL_KEY_SECRET": h(16), "TW_EL_KEY_SID": "SK" + h(16), "TW_EL_KEY_SECRET": h(16),
    "TW_VOICE_NUMBER_SID": "PN" + h(16), "TW_SIGN_BASE": "https://api.ironbody-banco.invalid",
    "SENTINEL_ELEVENLABS": "banco-ops8f-centinela-el-" + h(6).translate(str.maketrans("0123456789", "ghijklmnop")),
    "SENTINEL_TWILIO": "banco-ops8f-centinela-tw-" + h(6).translate(str.maketrans("0123456789", "ghijklmnop")),
}
fd = os.open(pzp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
os.write(fd, ("# Piezas SINTÉTICAS del banco OPS8-f (aleatorias, de esta máquina)\n" + "".join(f"{k}={v}\n" for k, v in pz.items())).encode())
os.close(fd)
poner = [("DB_DATABASE", db), ("APP_ENV", "local"), ("ELEVENLABS_ENABLED", "true"), ("ELEVENLABS_API_KEY", pz["EL_API_KEY"]),
         ("ELEVENLABS_AGENT_ID", pz["EL_VERIFY_AGENT_ID"]), ("ELEVENLABS_REGION", "default"), ("ELEVENLABS_BASE_URL", pz["EL_BASE"]),
         ("ELEVENLABS_WEBHOOK_SECRET", pz["EL_WEBHOOK_SECRET"]), ("ELEVENLABS_TOOL_SECRET", pz["EL_TOOL_SECRET"]),
         ("ELEVENLABS_INITIATION_SECRET", pz["EL_INITIATION_SECRET"]), ("WHATSAPP_DISPLAY_PHONE", visible),
         ("WHATSAPP_PROTECTED_NUMBERS", visible + "," + protegido), ("TWILIO_VOICE_BASE_URL", pz["EL_TWILIO_BASE"]),
         ("VOICE_INBOUND_ENABLED", "false"), ("VOICE_OUTBOUND_ENABLED", "false"), ("TWILIO_VOICE_ENABLED", "false"),
         ("VOICE_LAB_ENABLED", "false"), ("VOICE_LAB_DRIVER", "fake"), ("VOICE_MONITOR_ENABLED", "false"),
         ("ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED", "false"), ("VOICE_INBOUND_DAILY_CAP", "0"), ("TWILIO_VOICE_DAILY_CALL_CAP", "0"),
         ("VOICE_DAILY_BUDGET_USD", "0"), ("VOICE_LAB_DAILY_BUDGET_USD", "0"), ("VOICE_LAB_SESSION_BUDGET_USD", "0")]
quitar = ["TWILIO_VOICE_FROM", "TWILIO_VOICE_ACCOUNT_SID", "TWILIO_VOICE_API_KEY_SID", "TWILIO_VOICE_API_KEY_SECRET", "TWILIO_VOICE_SIGNING_TOKEN",
          "ELEVENLABS_AGENT_PHONE_NUMBER_ID", "VOICE_NUMBER_INBOUND_CAPABLE", "VOICE_NUMBER_SHARED_WITH_MESSAGING", "ULTRON_STUDIO_MONITOR_EXPECT",
          "VOICE_WEBHOOK_BASE_URL"]
for k in quitar:
    s = re.sub(r"^[ \t]*(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=[^\n]*\n?", "", s, flags=re.M)
for k, v in poner:
    s, n = re.subn(r"^([ \t]*)" + re.escape(k) + r"[ \t]*=.*$", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
    if n == 0:
        s = s.rstrip("\n") + "\n  " + k + "=" + v + "\n"
open(envp, "w", encoding="utf-8").write(s)
print("   %d piezas en sim/piezas.conf y %d claves puestas / %d quitadas en el fichero sintético (no se imprimen)" % (len(pz), len(poner), len(quitar)))
PY
  chmod 600 "$B/api/backend/.env"
  echo "== 4. sustitutos (sudo, stat, systemctl, sha256sum del origen; flock DE VERDAD; supervisorctl con ironbody-voice a voluntad)"
  for f in sudo stat systemctl sha256sum; do cp -p "$ORIGEN/bin/$f" "$B/bin/$f"; done
  printf '%s\n' '#!/bin/bash' '# flock del banco OPS8-f: «flock -n FD» de verdad (fcntl.flock sobre el descriptor heredado).' \
    '[ "${1:-}" = -n ] && [[ "${2:-}" =~ ^[0-9]+$ ]] || { echo "flock del banco: solo «flock -n FD»" >&2; exit 64; }' \
    "exec python3 -I -c 'import fcntl, sys" 'try:' '    fcntl.flock(int(sys.argv[1]), fcntl.LOCK_EX | fcntl.LOCK_NB)' 'except BlockingIOError:' "    sys.exit(1)' \"\$2\"" > "$B/bin/flock"
  cat > "$B/bin/supervisorctl" <<SUP
#!/bin/bash
# supervisorctl del banco OPS8-f: los 13 programas de producción, y ironbody-voice si existe $B/run/worker-voz.
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
  printf 'BANCO_DB=%s\nBANCO_PORT=%s\nPUERTO_VALLA=%s\nPUERTO_EL=%s\nPUERTO_TW=%s\nESPERADO=%s\nPREVIO_BANCO=%s\nADMIN_ID=%s\nORIGEN_DB=%s\nORIGEN_COMMIT=%s\n' "$DB_NUEVA" "$P_API" "$P_VALLA" "$P_EL" "$P_TW" "$(git -C "$B/api" rev-parse --short=7 HEAD)" "$HEADC" "$O_ADMIN" "$O_DB" "$O_COMMIT" > "$B/banco.env"
  cargar_banco "$B"
  echo "== 5. migraciones de HEAD como h5_owner y cachés (sin red: proxy a un puerto cerrado)"
  migrar || muere "las migraciones o las cachés fallaron"
  install -m 600 "$B/api/backend/.env" "$B/root/env-ops8f-inicial"
  echo "banco OPS8-f montado en $B (base $DB_NUEVA, código $ESPERADO). Siguiente: bash $0 $B arrancar"
  exit 0
fi

cargar_banco "$B"
[ -n "${PUERTO_TW:-}" ] && [ "$BANCO_DB" = "$DB_NUEVA" ] || muere "$B no es un banco OPS8-f (bash $0 $B montar)"
export PATH="$B/bin:$PATH"
BE="$B/api/backend"
ENVB="$BE/.env"
URL="http://127.0.0.1:$BANCO_PORT/api"
arranca() { local n=$1 d=$2; shift 2; ( cd "$d" || exit 1; nohup "$@" > "$B/logs/$n.out" 2>&1 < /dev/null & echo $! > "$B/run/$n.pid" ); }
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() { local n=$1 p; [ -f "$B/run/$n.pid" ] || return 0; p=$(cat "$B/run/$n.pid"); for c in $(descendientes "$p") "$p"; do kill "$c" 2> /dev/null || true; done; rm -f "$B/run/$n.pid"; }
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
tinker() { ( cd "$BE" && env "${RED[@]}" php artisan tinker --execute="$1" 2> /dev/null | tail -1 ); }
cache_banco() { ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php ); }
espera_vivo() { for _ in $(seq 1 40); do vivo "$1" && return 0; sleep 0.25; done; muere "$2 no responde en :$1 (ver $B/logs/)"; }

case "$ORDEN" in
  arrancar)
    for p in "$PUERTO_VALLA" "$PUERTO_EL" "$PUERTO_TW" "$BANCO_PORT"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p ya está ocupado (pid $(escuchando "$p"))"; done
    arranca valla "$B" node "$AQUI/valla.cjs" --log "$B/logs/valla.jsonl" --port "$PUERTO_VALLA"; espera_vivo "$PUERTO_VALLA" "la valla"
    arranca sim-twilio "$B" node "$AQUI/sim-twilio.cjs" --config "$B/sim/piezas.conf" --log "$B/logs/sim-twilio.jsonl" --port "$PUERTO_TW"; espera_vivo "$PUERTO_TW" "el simulador de Twilio"
    arranca sim-elevenlabs "$B" node "$AQUI/sim-elevenlabs.cjs" --config "$B/sim/piezas.conf" --log "$B/logs/sim-elevenlabs.jsonl" --port "$PUERTO_EL"; espera_vivo "$PUERTO_EL" "el simulador de ElevenLabs"
    arranca api "$BE" env "${RED[@]}" PHP_CLI_SERVER_WORKERS=2 php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload
    c=000; for _ in $(seq 1 60); do c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$URL/admin/voice/status" || true); [ "$c" = 401 ] && break; sleep 0.5; done
    [ "$c" = 401 ] || muere "la API no respondió 401 (ver $B/logs/api.out)"
    if [ "$(tinker 'echo App\Services\UltronStudio\StudioIntegrations::voiceState(), PHP_EOL;')" != ELEVENLABS_VERIFIED ]; then
      echo "   primera verificación de ElevenLabs contra el simulador (integrations-snapshot --verify-voice: el estado de producción)"
      ( cd "$BE" && env "${RED[@]}" php artisan ultron-studio:integrations-snapshot --verify-voice > "$B/logs/verificacion-inicial.out" 2>&1 ) || true
    fi
    echo "banco OPS8-f en marcha: valla :$PUERTO_VALLA · Twilio :$PUERTO_TW · ElevenLabs :$PUERTO_EL · API :$BANCO_PORT (401 sin token) · Studio: $(tinker 'echo App\Services\UltronStudio\StudioIntegrations::voiceState(), PHP_EOL;')"
    exit 0 ;;
  parar)
    for n in api sim-elevenlabs sim-twilio valla; do para "$n"; done; sleep 0.5
    for p in "$BANCO_PORT" "$PUERTO_EL" "$PUERTO_TW" "$PUERTO_VALLA"; do [ -z "$(escuchando "$p")" ] || muere "el puerto $p sigue ocupado"; done
    echo "banco OPS8-f parado"; exit 0 ;;
  borrar)
    for n in api sim-elevenlabs sim-twilio valla; do para "$n"; done
    psql_su -d postgres -q -c "DROP DATABASE IF EXISTS $BANCO_DB" && rm -rf "$B" && echo "banco OPS8-f borrado (base $BANCO_DB y $B)"; exit 0 ;;
  ensayar) ;;
  *) muere "uso: bash ensayar-ops8f.sh <banco> montar | arrancar | ensayar | parar | borrar" ;;
esac
vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" && [ -n "$(escuchando "$BANCO_PORT")" ] || muere "arranca antes: bash $0 $B arrancar"

# ── Las copias del banco de los dos mandos (las sustituciones de preparar.sh, que casan las veces exactas) ────────────
python3 -I - "$OPS" "$B" "$ME" "$URL" "$GRUPO" "$PUERTO_TW" <<'PY'
import sys
ops, b, me, url, grupo, ptw = sys.argv[1:7]
def copia(nombre, reglas):
    s = open(f"{ops}/{nombre}", encoding="utf-8").read()
    for viejo, nuevo, veces in reglas:
        n = s.count(viejo)
        if n != veces:
            sys.exit(f"ensayar-ops8f: «{viejo[:70]}» aparece {n} veces en {nombre} (esperado {veces}): el mando cambió")
        s = s.replace(viejo, nuevo)
    open(f"{b}/root/{nombre}", "w", encoding="utf-8").write(s)
propio = [("APPDIR=/var/www/api/backend", f"APPDIR={b}/api/backend", 1), ("WWW=www-data", f"WWW={me}", 1), ('[ "$(id -u)" = 0 ]', "true", 1)]
copia("h8-voice-scope.sh", propio + [("API=https://api.ironbodyneiva.cloud/api", f"API={url}", 1), ("ROOT_U=root", f"ROOT_U={me}", 1),
      ("ROOT_G=root", f"ROOT_G={grupo}", 1), ("COPIAS=/root", f"COPIAS={b}/root", 1),
      ("CERROJO=/run/lock/h8-voice-scope.lock", f"CERROJO={b}/run/h8-voice-scope.lock", 1), ("NIVEL_ERROR=production.ERROR", "NIVEL_ERROR=local.ERROR", 1)])
copia("h8-twilio-check.sh", propio + [("TW_API=https://api.twilio.com", f"TW_API=http://127.0.0.1:{ptw}", 1), ("TW_NUMBERS=https://numbers.twilio.com", f"TW_NUMBERS=http://127.0.0.1:{ptw}", 1)])
PY
chmod 700 "$B/root/h8-voice-scope.sh" "$B/root/h8-twilio-check.sh"
bash -n "$B/root/h8-voice-scope.sh" && bash -n "$B/root/h8-twilio-check.sh" || muere "las copias del banco no pasan bash -n"
! grep -v '^[[:space:]]*#' "$B/root/h8-voice-scope.sh" "$B/root/h8-twilio-check.sh" | grep -nE '/var/www|ironbodyneiva|api\.twilio\.com|numbers\.twilio\.com' || muere "las copias del banco todavía nombran rutas o hosts de producción"

LOG="$B/logs/ensayo-ops8f-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
SALIDA=""
paso() { # <nombre> <código esperado> <patrón ERE que tiene que salir> <orden…>
  local nombre=$1 esperado=$2 patron=$3 rc=0
  shift 3
  SALIDA=$("$@" 2>&1) || rc=$?
  { echo "════ $nombre · código $rc"; printf '%s\n' "$SALIDA"; } >> "$LOG"
  if [ "$rc" = "$esperado" ] && printf '%s' "$SALIDA" | grep -qE -- "$patron"; then
    caso "$nombre" 0 "código $rc"
  else
    caso "$nombre" 1 "código $rc (esperado $esperado); $(printf '%s' "$SALIDA" | grep -E 'ABORTA|NO SE APLICA|✗|VEREDICTO|APLICADO|FALLO|ERROR|veredicto del servidor' | head -3 | tr '\n' ' ' | cut -c1-300)"
  fi
}
tiene() { printf '%s' "$SALIDA" | grep -qE -- "$1"; }   # el patrón sale en la última salida
comprueba() { caso "$1" "$(tiene "$2" && echo 0 || echo 1)" "${3:-}"; }
alcance() { env "${RED[@]}" bash "$B/root/h8-voice-scope.sh" "$@" < /dev/null; }
twilio() { env "${RED[@]}" bash "$B/root/h8-twilio-check.sh" "$@" < /dev/null; }
ctl_el() { curl -s --max-time 5 "http://127.0.0.1:$PUERTO_EL$1"; }
ctl_tw() { curl -s --max-time 5 "http://127.0.0.1:$PUERTO_TW$1"; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
observar() { env "${RED[@]}" php "$AQUI/ayudante-ops8d.php" "$BE" observar-numero 2>&1; }
pon_env() { # CLAVE VALOR (VALOR vacío = quitar la línea): solo para lo que el banco simula fuera de los mandos; sin imprimir
  python3 -I - "$ENVB" "$1" "${2:-}" <<'PY'
import re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding="utf-8").read()
pat = re.compile(r"^([ \t]*)" + re.escape(k) + r"[ \t]*=.*$", re.M)
if v == "":
    s = re.sub(r"^[ \t]*" + re.escape(k) + r"[ \t]*=[^\n]*\n?", "", s, flags=re.M)
else:
    s = pat.sub(lambda m: m.group(1) + k + "=" + v, s, count=1) if pat.search(s) else s.rstrip("\n") + "\n  " + k + "=" + v + "\n"
open(p, "w", encoding="utf-8").write(s)
PY
  cache_banco
}
constante() {
  local P="$BE/app/Services/Voice/VoiceCallPolicy.php"
  case "$1" in
    abrir) [ "$(grep -cE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' "$P" || true)" = 1 ] || muere "la constante no está cerrada en el clon"
           sed -i '' -E 's/^([[:space:]]*public const CALLS_ENABLED_IN_CODE = )false;/\1true;/' "$P"
           git -C "$B/api" -c user.name=banco -c user.email=banco@local commit -q -m "voice(calls-open): banco ops8f: abre las llamadas en el clon del banco (nunca en el repo)" -- backend/app/Services/Voice/VoiceCallPolicy.php ;;
    cerrar) [[ "$(git -C "$B/api" log -1 --format=%s)" =~ ^voice\(calls-open\):\ banco ]] || return 0
            git -C "$B/api" -c user.name=banco -c user.email=banco@local revert --no-edit HEAD > /dev/null ;;
  esac
  cache_banco
}
# «fase 2 secreto --terminal» en una terminal de verdad (pty): los dos valores llegan a este python por su entrada
# estándar (nunca por argumentos), y se teclean SOLO cuando el mando ha pedido cada uno (si se escribieran antes, el eco
# de la terminal, que read -s aún no ha quitado, los enseñaría: eso sería un artefacto del ensayo, no del mando).
terminal_secreto() { # <sid> <secreto> → la salida de la terminal y el código del mando
  printf '%s\n%s\n' "$1" "$2" | env "${RED[@]}" python3 -I -c '
import os, pty, select, sys, time
sid, sec = sys.stdin.read().split("\n")[:2]
pid, fd = pty.fork()
if pid == 0:
    os.execvp("bash", ["bash", sys.argv[1], "fase", "2", "secreto", "--terminal"])
salida = b""
def lee_hasta(marca, plazo=60):
    global salida
    fin = time.time() + plazo
    while marca not in salida and time.time() < fin:
        r, _, _ = select.select([fd], [], [], 0.5)
        if r:
            try:
                d = os.read(fd, 4096)
            except OSError:
                return
            if not d:
                return
            salida += d
for marca, valor in ((b"TWILIO_VOICE_API_KEY_SID (", sid), (b"TWILIO_VOICE_API_KEY_SECRET (", sec)):
    lee_hasta(marca)
    if marca in salida:
        time.sleep(0.3)
        os.write(fd, valor.encode() + b"\n")
lee_hasta(b"\x00", 240)
_, st = os.waitpid(pid, 0)
sys.stdout.write(salida.decode("utf-8", "replace"))
sys.exit(os.waitstatus_to_exitcode(st))' "$B/root/h8-voice-scope.sh"
}

# ── Punto de partida: el fichero de entorno inicial, la constante cerrada, sin el worker, los simuladores limpios ─────
# y la BASE REHECHA desde la plantilla de origen (DROP … WITH (FORCE), CREATE … TEMPLATE y las migraciones de HEAD):
# una pasada anterior deja el freno echado (la conciliación real con un agente asignado, abajo) y la observación del
# número en la caché de la base; soltar el freno exige una persona con ultron.voice_publish (se reparte a mano en Roles).
# Así cada pasada empieza igual. La verificación de ElevenLabs contra el simulador se repite (el estado de producción).
constante cerrar
rm -f "$B/run/worker-voz"
install -m 600 "$B/root/env-ops8f-inicial" "$ENVB"
[ -n "${ORIGEN_DB:-}" ] && [ "$(psql_su -d postgres -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '$ORIGEN_DB'")" = 0 ] || muere "la base de origen (${ORIGEN_DB:-?}) no se sabe o tiene conexiones: parar ese banco antes"
psql_su -d postgres -q -c "DROP DATABASE IF EXISTS $BANCO_DB WITH (FORCE)" && psql_su -d postgres -q -c "CREATE DATABASE $BANCO_DB TEMPLATE $ORIGEN_DB" || muere "no se pudo rehacer la base del banco"
migrar || muere "las migraciones o las cachés fallaron al rehacer la base"
( cd "$BE" && env "${RED[@]}" php artisan ultron-studio:integrations-snapshot --verify-voice > "$B/logs/verificacion-ensayo.out" 2>&1 ) || true
[ "$(tinker 'echo App\Services\UltronStudio\StudioIntegrations::voiceState(), PHP_EOL;')" = ELEVENLABS_VERIFIED ] || muere "ElevenLabs no quedó verificado contra el simulador (ver $B/logs/verificacion-ensayo.out)"
ctl_el '/__reset?state=1' > /dev/null; ctl_tw '/__reset?state=1' > /dev/null
# La observación del número vive en la caché de la base (CACHE_STORE=database): la de una ejecución anterior admitiría
# antes de tiempo. Se borra (solo esa clave) para que cada ejecución empiece «sin observar».
[ "$(tinker 'Illuminate\Support\Facades\Cache::forget(App\Services\Telephony\VoiceNumberGuard::OBSERVED_CACHE_KEY); echo Illuminate\Support\Facades\Cache::has(App\Services\Telephony\VoiceNumberGuard::OBSERVED_CACHE_KEY) ? "queda" : "borrada", PHP_EOL;')" = borrada ] || muere "no se pudo borrar la observación del número de la caché del banco"
EL0=$(ctl_el /__stats); TW0=$(ctl_tw /__stats)
echo "== ensayo OPS8-f (enmienda 20) · banco $B · código $(git -C "$B/api" rev-parse --short=8 HEAD) · registro $LOG"
ACC=$(pieza TW_ACCOUNT_SID); PHN=$(pieza EL_PHONE_NUMBER_ID)

# ── 1. Sin nada declarado: las negativas del camino (nada se toca) ─────────────────────────────────────────────────
paso "estado inicial: identidad compartida no declarada; el servidor dice not_declared" 0 'NO admitida \(not_declared\)' alcance estado
comprueba "estado inicial: la línea de la identidad compartida dice «no declarada»" 'identidad compartida \(VOICE_NUMBER_SHARED_WITH_MESSAGING, enmienda 20\): no declarada'
paso "fase 2 set VOICE_NUMBER_SHARED_WITH_MESSAGING=true → se niega (solo por numero-compartido)" 2 'no es una pieza de «fase 2 set»' alcance fase 2 set VOICE_NUMBER_SHARED_WITH_MESSAGING=true
paso "numero-compartido on sin --autorizacion → se niega" 2 'exige --autorizacion' alcance numero-compartido on
paso "numero-compartido on con una referencia fuera de forma → se niega" 2 'de 6 a 120 caracteres' alcance numero-compartido on --autorizacion=x
paso "numero-compartido on sin el número «Outbound Only» declarado → se niega" 2 'no está declarado «Outbound Only»' alcance numero-compartido on "--autorizacion=AUT-VOZ-0 banco ops8f"
paso "fase 2 set del visible de WhatsApp SIN la declaración → se niega (protección 6)" 2 'identidad compartida NO está declarada' alcance fase 2 set "TWILIO_VOICE_FROM=$VISIBLE"
paso "fase 2 set de OTRO número protegido → se niega (nunca se admite)" 2 'protegido de WhatsApp que NO es el visible' alcance fase 2 set "TWILIO_VOICE_FROM=$PROTEGIDO"

# ── 2. La key restringida de Laravel por la terminal, sin eco ──────────────────────────────────────────────────────
paso "fase 2 secreto --terminal sin una terminal → se niega" 2 'exige una terminal interactiva' alcance fase 2 secreto --terminal
RC=0; SALIDA=$(terminal_secreto "$ACC" "$(hex 16)") || RC=$?; { echo "════ secreto --terminal con un Account SID · código $RC"; printf '%s\n' "$SALIDA"; } >> "$LOG"
caso "fase 2 secreto --terminal con un Account SID en vez de la key → se niega sin tocar nada" "$([ "$RC" = 2 ] && tiene 'parece un Account SID' && echo 0 || echo 1)" "código $RC"
K_SID=$(pieza TW_LARAVEL_KEY_SID); K_SEC=$(pieza TW_LARAVEL_KEY_SECRET)
RC=0; SALIDA=$(terminal_secreto "$K_SID" "$K_SEC") || RC=$?; { echo "════ secreto --terminal con la key · código $RC"; printf '%s\n' "$SALIDA"; } >> "$LOG"
caso "fase 2 secreto --terminal con la key restringida → APLICADO" "$([ "$RC" = 0 ] && tiene 'MANDO fase2-secreto APLICADO' && echo 0 || echo 1)" "código $RC"
caso "fase 2 secreto --terminal: ni el SID ni el secreto aparecen en la terminal (sin eco)" "$(printf '%s' "$SALIDA" | grep -qF -e "$K_SID" -e "$K_SEC" && echo 1 || echo 0)"
H=$(python3 -I - "$ENVB" "$B/sim/piezas.conf" <<'PY'
import re, sys
e = open(sys.argv[1], encoding="utf-8").read(); p = dict(l.strip().split("=", 1) for l in open(sys.argv[2]) if "=" in l and not l.startswith("#"))
v = lambda k: (re.search(r"^[ \t]*" + k + r"=(.*)$", e, re.M) or [None, None])[1]
print("si" if v("TWILIO_VOICE_API_KEY_SID") == p["TW_LARAVEL_KEY_SID"] and v("TWILIO_VOICE_API_KEY_SECRET") == p["TW_LARAVEL_KEY_SECRET"] and v("TWILIO_VOICE_SIGNING_TOKEN") is None else "no")
PY
)
caso "fase 2 secreto --terminal: el fichero de entorno tiene exactamente el par tecleado y ningún token de firma" "$([ "$H" = si ] && echo 0 || echo 1)"
unset K_SEC

# ── 3. Las piezas, la declaración y el visible ─────────────────────────────────────────────────────────────────────
paso "fase 2 set «Outbound Only», la cuenta, el id importado y la base de los webhooks → APLICADO" 0 'MANDO fase2-set APLICADO' alcance fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false "TWILIO_VOICE_ACCOUNT_SID=$ACC" "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$PHN" "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)"
paso "numero-compartido on --autorizacion=… → APLICADO con la cita" 0 'MANDO numero-compartido-on APLICADO .*autorización citada: «AUT-VOZ-0 banco ops8f»' alcance numero-compartido on "--autorizacion=AUT-VOZ-0 banco ops8f"
comprueba "numero-compartido on: avisa de que la declaración sola no admite nada" 'la declaración SOLA no admite nada'
comprueba "numero-compartido on: el antes → después es solo shared_declared" 'shared_declared: false → true'
paso "estado: DECLARADA y el servidor NO la admite todavía (not_business_number: el número de voz no está puesto)" 0 'NO admitida \(not_business_number\)' alcance estado
comprueba "estado: la línea dice DECLARADA" 'enmienda 20\): DECLARADA'
paso "numero-compartido on otra vez → nada que escribir" 0 'ya tiene esos valores' alcance numero-compartido on "--autorizacion=AUT-VOZ-0 banco ops8f"
paso "fase 2 set del visible con VOICE_NUMBER_INBOUND_CAPABLE=true → se niega" 2 'VOICE_NUMBER_INBOUND_CAPABLE=true con la identidad compartida declarada' alcance fase 2 set "TWILIO_VOICE_FROM=$VISIBLE" VOICE_NUMBER_INBOUND_CAPABLE=true
paso "fase 2 set de otro protegido con la declaración puesta → se niega igual" 2 'protegido de WhatsApp que NO es el visible' alcance fase 2 set "TWILIO_VOICE_FROM=$PROTEGIDO"
paso "fase 2 set del visible con la declaración y «Outbound Only» → APLICADO, PENDIENTE de conciliar (aviso, no desvío)" 0 'MANDO fase2-set APLICADO' alcance fase 2 set "TWILIO_VOICE_FROM=$VISIBLE"
comprueba "fase 2 set del visible: el aviso de «pendiente de la conciliación del número»" 'PENDIENTE de la conciliación del número'
paso "estado: declarada sin observar → observation_unverified; whatsapp=CHOCA" 0 'NO admitida \(observation_unverified\)' alcance estado
comprueba "estado: el resumen del doctor dice whatsapp=CHOCA (no admitida)" 'whatsapp=CHOCA'
comprueba "estado: TWILIO_VOICE_FROM es el visible de WhatsApp: sí" 'TWILIO_VOICE_FROM es el visible de WhatsApp: sí'

# ── 4. La comprobación de Twilio (cuenta principal, caller ID verificado = el visible) ───────────────────────────────
ctl_tw '/__numero?numero=whatsapp&tipo=caller_id&cuenta=madre&compartido=1' > /dev/null
paso "twilio · cuenta principal, key restringida, caller ID = el visible declarado → APTA · cuenta principal (código 0)" 0 'VEREDICTO: APTA · cuenta principal' twilio
comprueba "twilio · el visible como identidad compartida declarada es INFO, no FALLO" 'INFO +el número de voz es el VISIBLE de WhatsApp como identidad compartida declarada'
comprueba "twilio · dice que NO comprueba lo que verá el destino (Colombia, Non-guaranteed)" 'NO comprueba que el teléfono de destino vea este número'
comprueba "twilio · avisa de la credencial de la importación (la confirma el propietario)" 'AVISO +la importación del número en ElevenLabs tiene que estar hecha con una API key SK'
ctl_tw "/__seed-call?direction=inbound&from=%2B573000000077&to=%2B576019999999&status=completed" > /dev/null
paso "twilio · una llamada ajena en la ventana → no apta para la cuenta principal (código 3)" 3 'hay 1 llamada\(s\) ajena\(s\)' twilio
ctl_tw '/__reset?state=1' > /dev/null; ctl_tw '/__numero?numero=whatsapp&tipo=caller_id&cuenta=madre&compartido=1&sms_laravel=1' > /dev/null
paso "twilio · una key que puede leer mensajes → NO APTA (no es la restringida de voz)" 3 'VEREDICTO: NO APTA' twilio
comprueba "twilio · el FALLO dice que la key PUEDE leer mensajes" 'la key PUEDE leer mensajes'
ctl_tw '/__numero?sms_laravel=0' > /dev/null
pon_env TWILIO_VOICE_SIGNING_TOKEN "$(pieza TW_SIGNING_TOKEN)"
paso "twilio · con TWILIO_VOICE_SIGNING_TOKEN en la cuenta principal → no apta (código 3)" 3 'TWILIO_VOICE_SIGNING_TOKEN no está vacío' twilio
pon_env TWILIO_VOICE_SIGNING_TOKEN ""
pon_env TWILIO_VOICE_API_KEY_SID "$ACC"
paso "twilio · un Account SID en lugar de la key → NO APTA" 3 'es un Account SID' twilio
pon_env TWILIO_VOICE_API_KEY_SID "$(pieza TW_LARAVEL_KEY_SID)"
ctl_tw '/__numero?tipo=comprado' > /dev/null
paso "twilio · el visible como número COMPRADO de la cuenta → FALLO (nunca se compra ni porta)" 3 'no es un CALLER ID VERIFICADO \(tipo: comprado' twilio
ctl_tw '/__numero?tipo=caller_id&cuenta=subcuenta' > /dev/null
paso "twilio · regresión: la subcuenta dedicada sigue APTA" 0 'VEREDICTO: APTA: subcuenta dedicada' twilio
ctl_tw '/__numero?cuenta=madre' > /dev/null

# ── 5. La conciliación en seco de «monitor on» con el número importado de cada forma ────────────────────────────────
ctl_el '/__numero?phone=whatsapp&supports_inbound=ausente&agente=ninguno' > /dev/null
paso "monitor on · importado sin supports_inbound → se niega: echaría number_is_messaging (observed_inbound)" 2 'compartida=observed_inbound haria_freno=SÍ' alcance monitor on
ctl_el '/__numero?supports_inbound=true' > /dev/null
paso "monitor on · importado que admite entrantes → se niega (observed_inbound)" 2 'compartida=observed_inbound haria_freno=SÍ' alcance monitor on
ctl_el '/__numero?supports_inbound=false&agente=ajeno' > /dev/null
paso "monitor on · importado con un agente asignado → se niega (observed_agent)" 2 'compartida=observed_agent haria_freno=SÍ' alcance monitor on
ctl_el '/__numero?phone=voz&agente=ninguno' > /dev/null
paso "monitor on · importado que no es el visible → se niega (number_mismatch, observation_unverified)" 2 'numero=number_mismatch compartida=observation_unverified haria_freno=SÍ' alcance monitor on
ctl_el '/__numero?phone=whatsapp&supports_inbound=false&agente=ninguno' > /dev/null
paso "monitor on · importado = el visible, solo saliente y sin agente → no frenaría (admitida) → APLICADO" 0 'MANDO monitor-on APLICADO' alcance monitor on
comprueba "monitor on · la conciliación en seco dice outbound_only y compartida=admitida" 'numero=outbound_only compartida=admitida haria_freno=no'
paso "estado tras monitor on: en seco no se guarda nada → sigue observation_unverified" 0 'NO admitida \(observation_unverified\)' alcance estado

# ── 6. Con la constante abierta en el clon y el worker: salientes y entrantes frente al veredicto del servidor ────────
constante abrir; touch "$B/run/worker-voz"
paso "salientes on · declarada sin observar → se niega (observation_unverified)" 2 'no admite la identidad compartida.*observation_unverified' alcance salientes on --cupo=1 --presupuesto=2.00
ctl_el '/__numero?agente=ajeno' > /dev/null
O=$(observar); echo "════ observar-numero (agente ajeno): $O" >> "$LOG"
caso "conciliación real del número con un agente asignado → number_is_messaging y freno (el lado seguro)" "$([[ "$O" == *'"result":"number_is_messaging"'* ]] && echo 0 || echo 1)" "$O"
paso "estado · observada con agente → NO admitida (observed_agent)" 0 'NO admitida \(observed_agent\)' alcance estado
paso "salientes on · observada con agente → se niega (observed_agent)" 2 'no admite la identidad compartida.*observed_agent' alcance salientes on --cupo=1 --presupuesto=2.00
ctl_el '/__numero?agente=ninguno&supports_inbound=true' > /dev/null
O=$(observar); echo "════ observar-numero (entrante): $O" >> "$LOG"
paso "estado · observada como entrante → NO admitida (observed_inbound)" 0 'NO admitida \(observed_inbound\)' alcance estado
paso "salientes on · observada como entrante → se niega (observed_inbound)" 2 'no admite la identidad compartida.*observed_inbound' alcance salientes on --cupo=1 --presupuesto=2.00
ctl_el '/__numero?supports_inbound=false' > /dev/null
O=$(observar); echo "════ observar-numero (admitida): $O" >> "$LOG"
caso "conciliación real del número = el visible, solo saliente y sin agente → outbound_only sin freno nuevo" "$([[ "$O" == *'"result":"outbound_only"'* && "$O" == *'"brakeOpened":false'* ]] && echo 0 || echo 1)" "$O"
paso "estado · ADMITIDA por el servidor; el doctor dice whatsapp=COMPARTIDO" 0 'ADMITIDA por el servidor' alcance estado
comprueba "estado · whatsapp=COMPARTIDO en el resumen del doctor" 'whatsapp=COMPARTIDO'
paso "salientes on --cupo=1 --presupuesto=2.00 con la identidad ADMITIDA → APLICADO (todo lo de antes se sigue exigiendo)" 0 'MANDO salientes-on APLICADO' alcance salientes on --cupo=1 --presupuesto=2.00
comprueba "salientes on · lo dice: número de voz = el visible, ADMITIDA" 'número de voz = el visible de WhatsApp: ADMITIDA'
comprueba "salientes on · la reserva de la puerta se sigue comprobando (C-1)" 'el presupuesto \(2\.00 US\$\) la cubre'
paso "salientes on · el presupuesto que no cubre la reserva se sigue negando con la identidad admitida" 2 'no cubre la reserva' alcance salientes on --cupo=1 --presupuesto=0.30
paso "entrantes on · con la identidad compartida declarada → se niega por su nombre" 2 'identidad compartida con la mensajería está declarada' alcance entrantes on --cupo=1
paso "salientes off → APLICADO" 0 'MANDO salientes-off APLICADO' alcance salientes off

# ── 7. La vuelta atrás: numero-compartido off ──────────────────────────────────────────────────────────────────────
paso "numero-compartido off → APLICADO (la política vuelve a negar el visible)" 0 'MANDO numero-compartido-off APLICADO' alcance numero-compartido off
comprueba "numero-compartido off · lo dice: declaración RETIRADA, la política deniega por nombre" 'la declaración está RETIRADA'
comprueba "numero-compartido off · avisa del freno de la siguiente conciliación" 'echará el freno number_is_messaging'
COPIA=$(printf '%s' "$SALIDA" | sed -n 's/^MANDO numero-compartido-off APLICADO (copia previa: \([^;)]*\).*$/\1/p')
VUELTA=$(printf '%s' "$SALIDA" | sed -n 's/^   vuelta atrás exacta (el fichero de antes): //p')
paso "estado · tras off: no declarada (not_declared) y whatsapp=CHOCA" 0 'NO admitida \(not_declared\)' alcance estado
comprueba "estado · tras off: whatsapp=CHOCA" 'whatsapp=CHOCA'
paso "salientes on · tras off → se niega (not_declared)" 2 'no admite la identidad compartida.*not_declared' alcance salientes on --cupo=1 --presupuesto=2.00
paso "twilio · tras off: el visible sin la declaración vuelve a ser FALLO" 3 'la identidad compartida no está declarada' twilio
( eval "export ${RED[*]}; $VUELTA" ) > /dev/null 2>&1 && cmp -s "$ENVB" "$COPIA" && caso "la vuelta atrás EXACTA que imprime «off» deja el fichero byte a byte como su copia previa" 0 || caso "la vuelta atrás exacta de «off»" 1 "$COPIA"
paso "estado · tras la vuelta exacta: DECLARADA otra vez y ADMITIDA (la observación sigue fresca)" 0 'ADMITIDA por el servidor' alcance estado
paso "numero-compartido off otra vez → APLICADO" 0 'MANDO numero-compartido-off APLICADO' alcance numero-compartido off

alcance monitor off >> "$LOG" 2>&1 || true
rm -f "$B/run/worker-voz"; constante cerrar

# ── 8. Los mandos nuevos con el código ANTERIOR a la enmienda (el del origen): fallan cerrado y lo de siempre sigue ──
# El día del despliegue los mandos pueden llegar a /root antes que el código del lote 3. Sin VOICE_NUMBER_SHARED_WITH_MESSAGING
# ni VoiceNumberGuard::sharedIdentity() en el código, nada de la enmienda se puede usar y el número dedicado sigue igual.
ACTUAL=$(git -C "$B/api" rev-parse HEAD)
if [ -n "${ORIGEN_COMMIT:-}" ] && git -C "$B/api" cat-file -e "$ORIGEN_COMMIT^{commit}" 2> /dev/null; then
  install -m 600 "$B/root/env-ops8f-inicial" "$ENVB"
  git -C "$B/api" -c advice.detachedHead=false checkout -q --detach "$ORIGEN_COMMIT" && cache_banco
  paso "código anterior · estado: el código no lee la declaración y no tiene la identidad compartida" 0 'el código desplegado no tiene la identidad compartida' alcance estado
  comprueba "código anterior · estado: «el código desplegado no la lee»" 'enmienda 20\): el código desplegado no la lee'
  paso "código anterior · fase 2 set «Outbound Only», la cuenta y el id importado → APLICADO (lo de siempre)" 0 'MANDO fase2-set APLICADO' alcance fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false "TWILIO_VOICE_ACCOUNT_SID=$ACC" "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$PHN"
  paso "código anterior · numero-compartido on → se niega (el código no la lee)" 2 'no lee VOICE_NUMBER_SHARED_WITH_MESSAGING' alcance numero-compartido on "--autorizacion=AUT-VOZ-0 banco ops8f"
  paso "código anterior · fase 2 set del visible → se niega (protección 6 de siempre)" 2 'TWILIO_VOICE_FROM .*WhatsApp' alcance fase 2 set "TWILIO_VOICE_FROM=$VISIBLE"
  paso "código anterior · fase 2 set del número dedicado → APLICADO sin desvíos (regresión)" 0 'MANDO fase2-set APLICADO' alcance fase 2 set "TWILIO_VOICE_FROM=$DEDICADO"
  comprueba "código anterior · el antes → después del número dedicado (solo sus cuatro últimas cifras)" 'from_last4: null → "0090"'
  K_SEC=$(pieza TW_LARAVEL_KEY_SECRET); pon_env TWILIO_VOICE_API_KEY_SID "$(pieza TW_LARAVEL_KEY_SID)"; pon_env TWILIO_VOICE_API_KEY_SECRET "$K_SEC"; unset K_SEC
  ctl_tw '/__reset?state=1' > /dev/null; ctl_tw '/__numero?tipo=caller_id&cuenta=subcuenta' > /dev/null
  paso "código anterior · twilio: la subcuenta con el número dedicado sigue APTA (regresión)" 0 'VEREDICTO: APTA: subcuenta dedicada' twilio
  pon_env TWILIO_VOICE_FROM "$VISIBLE"; ctl_tw '/__numero?numero=whatsapp&cuenta=madre' > /dev/null
  paso "código anterior · twilio: el visible sin la identidad compartida → FALLO (y la comprobación no se rompe)" 3 'el número de voz es el de WhatsApp o uno protegido' twilio
  git -C "$B/api" -c advice.detachedHead=false checkout -q --detach "$ACTUAL" && install -m 600 "$B/root/env-ops8f-inicial" "$ENVB" && cache_banco
  caso "código anterior · el clon vuelve a su commit" "$([ "$(git -C "$B/api" rev-parse HEAD)" = "$ACTUAL" ] && echo 0 || echo 1)"
else
  caso "código anterior · ORIGEN_COMMIT en banco.env (el commit del banco de origen)" 1 "falta: montar de nuevo"
fi

# ── 9. Cierre ──────────────────────────────────────────────────────────────────────────────────────────────────────
paso "estado final: constante cerrada, salientes y entrantes apagadas" 0 'constante de llamadas: cerrada' alcance estado
comprueba "estado final: salientes no y entrantes no" 'salientes: no .*'
install -m 600 "$B/root/env-ops8f-inicial" "$ENVB"; cache_banco
ctl_el '/__reset?state=1' > /dev/null; ctl_tw '/__reset?state=1' > /dev/null
EL1=$(ctl_el /__stats); TW1=$(ctl_tw /__stats)
dv() { python3 -I -c 'import json, sys; a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2]); print(b["violations"] - a["violations"], json.dumps({k: v - a["violationsByCode"].get(k, 0) for k, v in b["violationsByCode"].items() if v - a["violationsByCode"].get(k, 0)}))' "$1" "$2"; }
caso "cierre · 0 violaciones nuevas en el simulador de ElevenLabs" "$([ "$(dv "$EL0" "$EL1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$EL0" "$EL1")"
caso "cierre · 0 violaciones nuevas en el simulador de Twilio" "$([ "$(dv "$TW0" "$TW1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$TW0" "$TW1")"
V=$(curl -s --max-time 5 "http://127.0.0.1:$PUERTO_VALLA/__stats" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" ".join(h for h in d["byHost"] if not h.startswith("h8-valla-sonda")) or "ninguno")')
caso "cierre · la valla no vio ningún host de fuera" "$([ "$V" = ninguno ] && echo 0 || echo 1)" "$V"
SEC=$(python3 -I - "$B/sim/piezas.conf" "$LOG" <<'PY'
import sys
p = dict(l.strip().split("=", 1) for l in open(sys.argv[1]) if "=" in l and not l.startswith("#"))
s = open(sys.argv[2], encoding="utf-8", errors="replace").read()
claves = ["EL_API_KEY", "EL_WEBHOOK_SECRET", "EL_TOOL_SECRET", "EL_INITIATION_SECRET", "TW_LARAVEL_KEY_SID", "TW_LARAVEL_KEY_SECRET", "TW_SIGNING_TOKEN", "TW_MASTER_TOKEN", "TW_EL_KEY_SECRET"]
print(" ".join(k for k in claves if p.get(k) and p[k] in s) or "ninguno")
PY
)
caso "cierre · el registro del ensayo no lleva ningún secreto de las piezas" "$([ "$SEC" = ninguno ] && echo 0 || echo 1)" "$SEC"
TEL=$(grep -cE '(\+?57)?(3000000050|3000000051|6010000090)' "$LOG" || true)
caso "cierre · el registro del ensayo no lleva ningún teléfono completo (solo las cuatro últimas cifras)" "$([ "$TEL" = 0 ] && echo 0 || echo 1)" "$TEL líneas"
chmod 600 "$LOG"
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO · registro $LOG"
[ "$FALLOS" = 0 ]
