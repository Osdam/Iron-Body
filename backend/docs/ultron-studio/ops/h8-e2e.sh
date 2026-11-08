#!/bin/bash
# h8-e2e.sh · E2E del Hito 8 (voz y telefonía) con la PILA REAL y los simuladores (TEST8-b; contrato H8, «Orden y
# puertas», paso 3, y Enmienda 6, E6-11). Nunca toca producción ni sale a la red: ElevenLabs y Twilio son los
# simuladores de OPS8 (`ops/banco-h8/`) en 127.0.0.1, con los adaptadores REALES apuntados a ellos (nunca los nulos).
#
#   bash backend/docs/ultron-studio/ops/h8-e2e.sh [cerrada|abierta|ambas]        (desde la raíz del worktree; ambas por defecto)
#
# Por cada fase, en una COPIA del árbol (`git archive` del commit, fuera del repo; protección 2 del contrato):
#   cerrada  el código tal cual (CALLS_ENABLED_IN_CODE = false) con TODAS las banderas encendidas: la constante es la
#            única cerradura que queda, y tiene que cerrar sola (publicar se detiene sin asignar el número, las entrantes
#            dicen «no», las salientes dan 409 y nada sale a la red);
#   abierta  la copia del banco con la constante en true (el único cambio: se comprueba con diff), y los recorridos
#            a–h del paso 3 (perfil, laboratorio, publicar, entrantes, evidencia, freno, revertir, apagar y salientes).
# Y en cada una: PostgreSQL desechable con el modelo de roles de producción (dueño que migra, aplicación con solo DML y
# el REVOKE de DB8), las migraciones del H8, la valla (proxy que bloquea y anota todo lo que no es 127.0.0.1), los dos
# simuladores, `artisan serve --no-reload` (4 workers), el worker REAL de la cola `voice`, tres cuentas creadas aquí
# (dos Super Admin para los cuatro ojos y una Administradora para los 403), los recorridos como pruebas PHPUnit
# (`tests/Feature/Voice/E2E/`) contra esa pila, y el CIERRE: violaciones de los simuladores, la valla, las muestras de
# `lsof`, `laravel.log`, `failed_jobs`, la cola y el barrido de secretos y teléfonos.
#
# Al final: el resumen con el hash de resultados y la evidencia copiada a `ops/evidencia-h8/<fecha>/` (/tmp se purga a
# los 3 días; la evidencia va al repo, sin secretos: el barrido también la recorre).
#
# Variables: H8_E2E_REF (HEAD) · H8_E2E_DIR (~/.cache/ironbody-h8-e2e) · H8_E2E_PG_PORT (55432) · H8_E2E_DB
#   (ultron_studio_h8_e2e) · H8_E2E_PUERTO_API|_EL|_TW|_VALLA (8401–8404) · H8_E2E_VENTANA (07:00-19:00: la ventana de
#   publicar de P-25; vacía = sin ventana) · H8_E2E_HOLD=1 (deja la pila de UNA fase en pie hasta SIGTERM, para
#   desarrollar) · H8_E2E_EVIDENCIA (1: copia la evidencia al repo).
# Trampas conocidas (README del E2E del CRM): PID de la subshell, nietos de `php -S`, `--no-reload`, PGGSSENCMODE en
# macOS, el fichero de ejemplo de entorno no vale como entorno, bash 3.2, `http_proxy` en minúsculas bajo `php -S`.
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable
OPS=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
AQUI="$OPS/h8-e2e"
BANCO="$OPS/banco-h8"
WT=$(cd "$OPS/../../../.." && pwd -P)
BE="$WT/backend"
FASES=${1:-ambas}
REF=${H8_E2E_REF:-HEAD}
BASE_DIR=${H8_E2E_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-h8-e2e}
PG_HOST=127.0.0.1
PG_PORT=${H8_E2E_PG_PORT:-55432}
PG_SU=ironbody
DB=${H8_E2E_DB:-ultron_studio_h8_e2e}
P_API=${H8_E2E_PUERTO_API:-8401}
P_EL=${H8_E2E_PUERTO_EL:-8402}
P_TW=${H8_E2E_PUERTO_TW:-8403}
P_VA=${H8_E2E_PUERTO_VALLA:-8404}
VENTANA=${H8_E2E_VENTANA-07:00-19:00}
HOLD=${H8_E2E_HOLD:-0}
EVIDENCIA=${H8_E2E_EVIDENCIA:-1}
ROL_DUENO=h8e2e_owner
ROL_APP=h8e2e_app
PHP=$(command -v php)

muere() { echo "h8-e2e: $*" >&2; exit 1; }
psql_su() { psql -X -q -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SU" "$@"; }
hora() { date -u +%Y-%m-%dT%H:%M:%SZ; }
case "$FASES" in cerrada) LISTA="cerrada" ;; abierta) LISTA="abierta" ;; ambas) LISTA="cerrada abierta" ;; *) muere "uso: h8-e2e.sh [cerrada|abierta|ambas]" ;; esac
[ "$HOLD" = 1 ] && [ "$FASES" = ambas ] && muere "H8_E2E_HOLD=1 necesita UNA fase (cerrada o abierta)"
[[ "$DB" =~ ^ultron_studio_h8_e2e[a-z0-9_]{0,40}$ ]] || muere "H8_E2E_DB tiene que empezar por ultron_studio_h8_e2e (se BORRA y se recrea)"

# ── 0. Requisitos: herramientas, puertos libres, PG, vendor, fuera del repo ─────────────────────────────────────────
for b in php node psql pg_dump git lsof shasum python3 curl openssl; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$BE/vendor" ] || muere "falta $BE/vendor (composer install en el worktree)"
for p in "$P_API" "$P_EL" "$P_TW" "$P_VA"; do
  [[ "$p" =~ ^[0-9]+$ ]] && [ "$p" -ge 8400 ] || muere "puerto no válido: $p"
  if lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1; then muere "el puerto $p está ocupado: no arranco encima de un proceso ajeno (¿el banco de OPS8? ensayar.sh <banco> parar)"; fi
done
psql_su -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en $PG_HOST:$PG_PORT (ops/pgunit.sh lo levanta)"
SHA=$(git -C "$WT" rev-parse --verify "$REF^{commit}")
CORTO=$(git -C "$WT" rev-parse --short=8 "$SHA")
SELLO=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$BASE_DIR"
RUN="$BASE_DIR/run-$SELLO-$CORTO"
RUN_REAL=$(python3 -I -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$BASE_DIR")/run-$SELLO-$CORTO
case "$RUN_REAL/" in "$WT"/*) muere "la ejecución no puede vivir dentro del repo ($RUN_REAL)" ;; esac
umask 077
mkdir -p "$RUN"/{sim,tokens,run}
T0=$(date +%s)
echo "== E2E H8 · $(hora) · $CORTO ($REF) · fases: $LISTA · $RUN"

# ── Procesos (trampas 1 y 2: el PID real, y parar nietos, hijos y padre) ────────────────────────────────────────────
arranca() { # nombre directorio orden…
  local n=$1 d=$2; shift 2
  ( cd "$d" || exit 1; nohup "$@" > "$F/logs/$n.out" 2>&1 < /dev/null & echo $! > "$RUN/run/$n.pid" )
}
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() {
  local f="$RUN/run/$1.pid" pid arbol i p vivo
  [ -f "$f" ] || return 0
  pid=$(cat "$f"); arbol="$(descendientes "$pid") $pid"
  kill -TERM $arbol 2> /dev/null || true
  for i in $(seq 1 60); do vivo=0; for p in $arbol; do kill -0 "$p" 2> /dev/null && vivo=1; done; [ "$vivo" = 0 ] && break; sleep 0.1; done
  kill -KILL $arbol 2> /dev/null || true
  rm -f "$f"
}
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
espera_http() { local i; for i in $(seq 1 "$2"); do curl -s -o /dev/null --max-time 2 "$1" && return 0; sleep 0.5; done; return 1; }
PARADO=0
parar_todo() {
  [ "$PARADO" = 1 ] && return 0
  local n p
  for n in muestreo worker api sim-elevenlabs sim-twilio valla; do para "$n"; done
  sleep 0.5
  for p in "$P_API" "$P_EL" "$P_TW" "$P_VA"; do
    [ -z "$(escuchando "$p")" ] || { echo "AVISO   el puerto $p sigue ocupado (pid $(escuchando "$p")): lo paro"; kill -KILL $(escuchando "$p") 2> /dev/null || true; }
  done
  PARADO=1
}
trap 'rc=$?; parar_todo; exit $rc' EXIT
trap 'echo "h8-e2e: interrumpido"; exit 130' INT TERM

# ── 1. Las dos copias del árbol (trampa 5: git archive desde la raíz) ───────────────────────────────────────────────
copia() { # destino; deja $1/backend con el vendor (clonado en APFS) y las pruebas del E2E del árbol de trabajo
  mkdir -p "$1"
  git -C "$WT" archive --format=tar "$SHA" backend | tar -xf - -C "$1"
  [ -f "$1/backend/artisan" ] && [ -f "$1/backend/composer.lock" ] || muere "la copia $1 salió vacía"
  cp -cR "$BE/vendor" "$1/backend/vendor" 2> /dev/null || cp -R "$BE/vendor" "$1/backend/vendor"
  # Las pruebas del E2E (TEST8-b) aún sin confirmar: van a la copia tal como están en el árbol de trabajo.
  mkdir -p "$1/backend/tests/Feature/Voice/E2E"
  cp "$BE"/tests/Feature/Voice/E2E/*.php "$1/backend/tests/Feature/Voice/E2E/"
  mkdir -p "$1/backend/storage/logs" "$1/backend/storage/framework/cache" "$1/backend/storage/framework/sessions" "$1/backend/storage/framework/views" "$1/backend/bootstrap/cache"
}
echo "== 1. copias de $CORTO"
copia "$RUN/cerrada"
copia "$RUN/abierta"
POL=app/Services/Voice/VoiceCallPolicy.php
grep -c 'public const CALLS_ENABLED_IN_CODE = false;' "$RUN/abierta/backend/$POL" | grep -qx 1 || muere "la constante no está en false exactamente una vez en $POL"
sed -i '' 's/public const CALLS_ENABLED_IN_CODE = false;/public const CALLS_ENABLED_IN_CODE = true;/' "$RUN/abierta/backend/$POL"
DIF=$(diff -r -q -x vendor "$RUN/cerrada/backend" "$RUN/abierta/backend" || true)
[ "$DIF" = "Files $RUN/cerrada/backend/$POL and $RUN/abierta/backend/$POL differ" ] || muere "la copia abierta difiere en algo más que la constante: $DIF"
LINEAS=$(diff "$RUN/cerrada/backend/$POL" "$RUN/abierta/backend/$POL" | grep -c '^[<>]' || true)
[ "$LINEAS" = 2 ] || muere "el giro de la constante no es de UNA línea ($LINEAS líneas de diff)"
diff "$RUN/cerrada/backend/$POL" "$RUN/abierta/backend/$POL" > "$RUN/giro-constante.diff" || true
echo "   cerrada: el commit tal cual · abierta: el commit + el giro de UNA línea ($LINEAS líneas cambiadas en el diff: una fuera y una dentro, solo $POL)"
PRUEBAS_SHA=$(cat "$BE"/tests/Feature/Voice/E2E/*.php | shasum -a 256 | cut -c1-16)
SIMS_SHA=$(cat "$BANCO"/sim-comun.cjs "$BANCO"/sim-elevenlabs.cjs "$BANCO"/sim-twilio.cjs "$BANCO"/valla.cjs | shasum -a 256 | cut -c1-16)   # los simuladores son del árbol de trabajo (OPS8)

# ── 2. Piezas SINTÉTICAS de la ejecución (600; nunca en la línea de órdenes ni en la salida) ─────────────────────────
hex() { openssl rand -hex "$1"; }
letras() { openssl rand -hex "$1" | tr '0-9' 'g-p'; }   # centinelas sin cifras: una cifra en un turno del agente es «precio»
cat > "$RUN/sim/piezas.conf" <<PZ
# Piezas SINTÉTICAS del E2E del H8 ($SELLO). Las leen los simuladores (--config), el emisor del laboratorio y el barrido.
LARAVEL_BASE=http://127.0.0.1:$P_API
EL_BASE=http://127.0.0.1:$P_EL
EL_TWILIO_BASE=http://127.0.0.1:$P_TW
EL_API_KEY=xi_$(hex 24)
EL_WEBHOOK_SECRET=wsec_$(hex 24)
EL_TOOL_SECRET=tool_$(hex 24)
EL_INITIATION_SECRET=ini_$(hex 24)
EL_INITIATION_HEADER=X-Voice-Initiation-Secret
EL_VERIFY_AGENT_ID=agent_verificacion_$(hex 4)
EL_PHONE_NUMBER_ID=phnum_$(hex 8)
EL_MAX_RETENTION_DAYS=30
VOICE_NUMBER=+576010000090
WHATSAPP_NUMBER=+573000000050
TW_ACCOUNT_SID=AC$(hex 16)
TW_MASTER_SID=AC$(hex 16)
TW_MASTER_TOKEN=$(hex 16)
TW_VERIFY_SID=VA$(hex 16)
TW_SIGNING_TOKEN=$(hex 16)
TW_LARAVEL_KEY_SID=SK$(hex 16)
TW_LARAVEL_KEY_SECRET=$(hex 16)
TW_EL_KEY_SID=SK$(hex 16)
TW_EL_KEY_SECRET=$(hex 16)
TW_VOICE_NUMBER_SID=PN$(hex 16)
TW_SIGN_BASE=https://api.ironbody-banco.invalid
SENTINEL_ELEVENLABS=centinela-el-$(letras 8)
SENTINEL_TWILIO=centinela-tw-$(letras 8)
PZ
chmod 600 "$RUN/sim/piezas.conf"

# El fichero de entorno de una copia: la plantilla del E2E con las piezas (trampa 3: nunca el de ejemplo del repo).
entorno() { # copia alcance
  python3 -I - "$AQUI/entorno-banco.ejemplo" "$RUN/sim/piezas.conf" "$1/backend/.env" "$2" <<'PY'
import base64, os, sys
plantilla, piezas, destino, alcance = sys.argv[1:5]
p = {}
for l in open(piezas, encoding='utf-8'):
    l = l.strip()
    if l and not l.startswith('#') and '=' in l:
        k, v = l.split('=', 1); p[k.strip()] = v.strip()
mapa = {k: p[k] for k in ('EL_API_KEY', 'EL_VERIFY_AGENT_ID', 'EL_WEBHOOK_SECRET', 'EL_PHONE_NUMBER_ID', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET',
                         'TW_ACCOUNT_SID', 'TW_LARAVEL_KEY_SID', 'TW_LARAVEL_KEY_SECRET', 'TW_SIGNING_TOKEN', 'TW_MASTER_SID', 'TW_MASTER_TOKEN', 'TW_VERIFY_SID')}
mapa.update({'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(), 'PUERTO_API': os.environ['P_API'], 'PUERTO_EL': os.environ['P_EL'],
             'PUERTO_TW': os.environ['P_TW'], 'PG_PORT': os.environ['PG_PORT'], 'DB': os.environ['DB'], 'DB_APP': os.environ['ROL_APP'],
             'VENTANA': os.environ['VENTANA'], 'ALCANCE_SALIENTES': alcance})
texto = open(plantilla, encoding='utf-8').read()
for k, v in mapa.items():
    texto = texto.replace('@@' + k + '@@', v)
if '@@' in texto:
    sys.exit('h8-e2e: quedan marcadores sin sustituir en el entorno')
fd = os.open(destino, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w', encoding='utf-8') as f:
    f.write(texto)
PY
}
export P_API P_EL P_TW PG_PORT DB ROL_APP VENTANA

# El entorno de TODO proceso PHP de la copia: limpio (env -i) y con la red por la valla (trampa 32: también en minúsculas).
VALLA="http://127.0.0.1:$P_VA"
RED=(PATH="$PATH" HOME="$HOME" LC_ALL=C PGGSSENCMODE=disable "http_proxy=$VALLA" "https_proxy=$VALLA" "HTTP_PROXY=$VALLA" "HTTPS_PROXY=$VALLA"
  "no_proxy=127.0.0.1,localhost" "NO_PROXY=127.0.0.1,localhost")
php_copia() { (cd "$COPIA" && env -i "${RED[@]}" "$PHP" "$@"); }

# ── 3. Una fase ────────────────────────────────────────────────────────────────────────────────────────────────────
TOTAL_OK=0; TOTAL_FALLO=0; TOTAL_OMITIDO=0
RESUMENES=""
fase() {
  local FASE=$1 t0 alcance pre est
  t0=$(date +%s)
  F="$RUN/$FASE"; COPIA="$RUN/$FASE/backend"
  mkdir -p "$F/logs" "$F/red"
  : > "$F/checks-$FASE.jsonl"
  PARADO=0
  echo "== fase $FASE · $(hora)"

  # 3.1 Base desechable con el modelo de roles de producción (DB7/DB8): dueño sin superusuario y aplicación con DML.
  for r in "$ROL_DUENO" "$ROL_APP"; do
    psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = '$r'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE $r LOGIN;"
  done
  psql_su -d postgres -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB'" | grep -qx 0 || muere "hay conexiones abiertas a $DB"
  psql_su -d postgres -c "DROP DATABASE IF EXISTS $DB;" -c "CREATE DATABASE $DB;"
  psql_su -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" -c "GRANT USAGE, CREATE ON SCHEMA public TO $ROL_DUENO;" \
    -c "GRANT USAGE ON SCHEMA public TO $ROL_APP;" \
    -c "ALTER DEFAULT PRIVILEGES FOR ROLE $ROL_DUENO IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $ROL_APP;" \
    -c "ALTER DEFAULT PRIVILEGES FOR ROLE $ROL_DUENO IN SCHEMA public GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO $ROL_APP;"

  # 3.2 Entorno y migraciones (las del H8 incluidas) como el dueño, sin red (proxy a un puerto cerrado).
  entorno "$RUN/$FASE" canary
  local SINRED=(PATH="$PATH" HOME="$HOME" LC_ALL=C PGGSSENCMODE=disable http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9
    HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
  (cd "$COPIA" && env -i "${SINRED[@]}" DB_USERNAME="$ROL_DUENO" "$PHP" artisan migrate --force -q) > "$F/logs/migrate.out" 2>&1 || { tail -20 "$F/logs/migrate.out"; muere "las migraciones fallaron ($FASE)"; }
  echo "   migraciones: $(psql_su -d "$DB" -Atc "SELECT count(*) FROM migrations;") · tablas de voz: $(psql_su -d "$DB" -Atc "SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND (tablename LIKE 'voice\_%' OR tablename LIKE 'ultron\_studio\_voice\_%');")"
  (cd "$COPIA" && env -i "${SINRED[@]}" "$PHP" artisan ultron-studio:import-baseline -q) > "$F/logs/import-baseline.out" 2>&1 || muere "import-baseline falló ($FASE)"

  # 3.3 Comprobación previa: entorno local, base del E2E, los adaptadores REALES hacia los simuladores, la constante.
  pre=$(cd "$COPIA" && env -i "${SINRED[@]}" "$PHP" "$AQUI/ayudante.php" "$COPIA" preflight) || muere "preflight falló ($FASE)"
  printf '%s\n' "$pre" > "$F/preflight.json"
  python3 -I - "$F/preflight.json" "$FASE" "$P_EL" "$P_TW" "$DB" "$ROL_APP" <<'PY' || muere "la comprobación previa no cuadra ($FASE): ver $F/preflight.json"
import json, sys
d = json.load(open(sys.argv[1])); fase, pel, ptw, db, app = sys.argv[2:7]
espera = {
    'appEnv': 'local', 'production': False, 'db': {'database': db, 'user': app},
    'callsEnabledInCode': fase == 'abierta',
    'elevenlabsBase': f'http://127.0.0.1:{pel}', 'twilioBase': f'http://127.0.0.1:{ptw}', 'twilioConfigured': True,
    'providerState': 'ELEVENLABS_UNVERIFIED', 'queueVoice': 'database', 'outboundScope': 'canary',
}
mal = {k: (d.get(k), v) for k, v in espera.items() if d.get(k) != v}
if mal:
    print('preflight: ' + json.dumps(mal, ensure_ascii=False)); sys.exit(1)
print(f"   preflight: local · {db} como {app} · constante {d['callsEnabledInCode']} · EL y Twilio → 127.0.0.1 · {d['providerState']}")
PY

  # 3.4 La valla, los simuladores (con los registros de esta fase), la API y el worker real de `voice`.
  arranca valla "$F" node "$BANCO/valla.cjs" --log "$F/logs/valla.jsonl" --port "$P_VA"
  arranca sim-twilio "$F" node "$BANCO/sim-twilio.cjs" --config "$RUN/sim/piezas.conf" --log "$F/logs/sim-twilio.jsonl" --port "$P_TW"
  arranca sim-elevenlabs "$F" node "$BANCO/sim-elevenlabs.cjs" --config "$RUN/sim/piezas.conf" --log "$F/logs/sim-elevenlabs.jsonl" --port "$P_EL"
  for p in "$P_VA" "$P_TW" "$P_EL"; do espera_http "http://127.0.0.1:$p/__stats" 30 || muere "no responde :$p (ver $F/logs/*.out)"; done
  arranca api "$COPIA" env -i "${RED[@]}" PHP_CLI_SERVER_WORKERS=4 "$PHP" artisan serve --host=127.0.0.1 --port="$P_API" --no-reload
  local c=""
  for _ in $(seq 1 60); do
    c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$P_API/api/admin/ultron-studio/status" || true)
    [ "$c" = 401 ] && break
    sleep 0.5
  done
  [ "$c" = 401 ] || muere "la API no respondió (ver $F/logs/api.out)"
  grep -q 'PHP_CLI_SERVER_WORKERS' "$F/logs/api.out" && muere "artisan serve ignoró PHP_CLI_SERVER_WORKERS (trampa 7)"
  arranca worker "$COPIA" env -i "${RED[@]}" "$PHP" artisan queue:work voice --queue=voice --tries=1 --timeout=60 --sleep=1 --max-time=3600
  sleep 1
  kill -0 "$(cat "$RUN/run/worker.pid")" 2> /dev/null || { tail -5 "$F/logs/worker.out"; muere "el worker de voz no arrancó"; }
  local HIJOS CON TOT p2
  HIJOS=$(descendientes "$(cat "$RUN/run/api.pid")" | tr '\n' ' '); CON=0; TOT=0
  for p2 in $HIJOS; do TOT=$((TOT + 1)); ps eww -o command= -p "$p2" 2> /dev/null | grep -q "https_proxy=$VALLA" && CON=$((CON + 1)); done
  [ "$TOT" -gt 0 ] && [ "$CON" = "$TOT" ] || muere "los procesos de la API no llevan la valla ($CON de $TOT)"
  echo "   pila: valla :$P_VA · ElevenLabs :$P_EL · Twilio :$P_TW · API :$P_API ($TOT procesos de php -S con la valla) · worker voice (pid $(cat "$RUN/run/worker.pid"))"

  # 3.5 Muestreo de red (lsof, cada segundo): toda conexión TCP/UDP de la pila que no sea de esta máquina.
  ( while :; do
      for pf in "$RUN"/run/*.pid; do
        [ -f "$pf" ] || continue
        for p3 in $(cat "$pf") $(descendientes "$(cat "$pf")"); do
          lsof -nP -a -p "$p3" -i 2> /dev/null | tail -n +2 | grep -v -E '127\.0\.0\.1|\[::1\]|localhost|\*:[0-9]+ \(LISTEN\)' || true
        done
      done >> "$F/red/fuera-de-127.txt"
      echo "$(date -u +%H:%M:%S)" >> "$F/red/muestras.txt"
      sleep 1
    done ) > /dev/null 2>&1 &
  echo $! > "$RUN/run/muestreo.pid"

  # 3.6 La valla funciona (control positivo): una sonda https y una http desde PHP con el entorno de la pila.
  local SONDA
  SONDA=$(php_copia -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (["https://h8-e2e-sonda.invalid/x", "http://h8-e2e-sonda.invalid/x"] as $u) { try { echo Illuminate\Support\Facades\Http::timeout(5)->get($u)->status(), " "; } catch (Throwable $e) { echo class_basename($e), " "; } }')
  echo "   valla: sondas → $SONDA(esperado: ConnectionException 403)"
  [ "$SONDA" = "ConnectionException 403 " ] || muere "la valla no cortó las sondas ($SONDA)"

  # 3.7 Cuentas, plan, la verificación de la voz contra el simulador (un GET) y el contexto de PHPUnit.
  mkdir -p "$RUN/tokens/$FASE"; chmod 700 "$RUN/tokens/$FASE"
  php_copia "$AQUI/ayudante.php" "$COPIA" cuentas "$RUN/tokens/$FASE" > "$F/cuentas.json"
  php_copia "$AQUI/ayudante.php" "$COPIA" plan > "$F/plan.json"
  php_copia artisan ultron-studio:integrations-snapshot --verify-voice --json > "$F/verify-voice.json" 2> "$F/logs/verify-voice.err" || { cat "$F/logs/verify-voice.err" | head -5; muere "--verify-voice falló"; }
  echo "   cuentas: $(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1]))["accounts"]; print(", ".join("%s=%s (%s)" % (k, v["id"], v["role"]) for k, v in d.items()))' "$F/cuentas.json") · verificación de voz: $(python3 -I -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])), ensure_ascii=False)[:160])' "$F/verify-voice.json")"
  python3 -I - "$F/context.json" <<PY
import json, os
ctx = {
    'phase': '$FASE', 'sha': '$SHA', 'api': 'http://127.0.0.1:$P_API', 'backend': '$COPIA', 'php': '$PHP', 'run': '$F',
    'checksFile': '$F/checks-$FASE.jsonl', 'pieces': '$RUN/sim/piezas.conf',
    'ports': {'api': $P_API, 'elevenlabs': $P_EL, 'twilio': $P_TW, 'valla': $P_VA},
    'db': {'host': '$PG_HOST', 'port': $PG_PORT, 'database': '$DB', 'reader': '$PG_SU', 'app': '$ROL_APP', 'owner': '$ROL_DUENO'},
    'tokens': {k: '$RUN/tokens/$FASE/token-' + k for k in ('a', 'b', 'c')},
    'accounts': json.load(open('$F/cuentas.json'))['accounts'],
    'helpers': {'ayudante': '$AQUI/ayudante.php', 'reloj': '$AQUI/reloj.php'},
    'red': {'http_proxy': '$VALLA', 'https_proxy': '$VALLA', 'HTTP_PROXY': '$VALLA', 'HTTPS_PROXY': '$VALLA', 'no_proxy': '127.0.0.1,localhost', 'NO_PROXY': '127.0.0.1,localhost'},
    'canaries': ['+573000000011', '+573000000012'], 'voiceNumber': '+576010000090', 'window': '$VENTANA',
}
fd = os.open('$F/context.json', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w') as f:
    json.dump(ctx, f, indent=1)
PY

  if [ "$HOLD" = 1 ]; then
    echo "== PILA EN PIE (H8_E2E_HOLD=1): H8_E2E_CONTEXT=$F/context.json · parar con SIGTERM a este proceso ($$)"
    while :; do sleep 5; done
  fi

  # 3.8 Los recorridos: PHPUnit en la copia, contra la pila.
  local CLASE=H8E2ECerradaTest
  [ "$FASE" = abierta ] && CLASE=H8E2EAbiertaTest
  echo "   recorridos: tests/Feature/Voice/E2E/$CLASE.php"
  (cd "$COPIA" && env -i PATH="$PATH" HOME="$HOME" LC_ALL=C PGGSSENCMODE=disable H8_E2E_CONTEXT="$F/context.json" "$PHP" -d memory_limit=1G \
    vendor/bin/phpunit --no-coverage --do-not-cache-result --log-junit "$F/junit-$FASE.xml" "tests/Feature/Voice/E2E/$CLASE.php") > "$F/phpunit.out" 2>&1 && est=0 || est=$?
  tail -n 25 "$F/phpunit.out" | sed 's/^/   │ /'

  # 3.9 Cierre de la fase.
  cierre "$FASE" "$est"
  parar_todo
  php_copia "$AQUI/ayudante.php" "$COPIA" revocar > "$F/revocar.json" 2>&1 || true
  RESUMENES="$RESUMENES$FASE: $(cat "$F/resumen-linea.txt") · $(( $(date +%s) - t0 )) s
"
}

# ── 4. El cierre: lo que el resto del E2E no ve desde dentro ───────────────────────────────────────────────────────
cierre() {
  local FASE=$1 EST=$2
  local OUT="$F/cierre-$FASE.txt"; : > "$OUT"
  local n_ok=0 n_fallo=0
  caso() { if [ "$2" = 0 ]; then echo "OK      $1${3:+  [$3]}" >> "$OUT"; n_ok=$((n_ok + 1)); else echo "FALLO   $1${3:+  [$3]}" >> "$OUT"; n_fallo=$((n_fallo + 1)); fi
    python3 -I -c 'import json,sys; print(json.dumps({"phase": sys.argv[1], "step": "cierre", "check": sys.argv[2], "ok": sys.argv[3] == "0", "detail": sys.argv[4]}, ensure_ascii=False))' "$FASE" "$1" "$2" "${3:-}" >> "$F/checks-$FASE.jsonl"; }
  curl -s --max-time 5 "http://127.0.0.1:$P_EL/__stats" > "$F/stats-elevenlabs.json" || true
  curl -s --max-time 5 "http://127.0.0.1:$P_TW/__stats" > "$F/stats-twilio.json" || true
  curl -s --max-time 5 "http://127.0.0.1:$P_VA/__stats" > "$F/stats-valla.json" || true
  local VIO
  VIO=$(python3 -I - "$F/falsos-positivos-f6.json" "$F/stats-elevenlabs.json" "$F/stats-twilio.json" <<'PY'
import json, os, sys
# Sin deriva conocida (F-2 corregido por OPS8-b en 2b3c2936). Solo se restan los falsos positivos de F-6 que el paso h
# VERIFICÓ con su oráculo (las variables que de verdad llegaron, sin teléfonos), y se dicen aparte.
conocidas = set()
fp = json.load(open(sys.argv[1])) if os.path.exists(sys.argv[1]) else {}
for f in sys.argv[2:]:
    d = json.load(open(f)); v = dict(d.get('violationsByCode', {}))
    deriva = {k: n for k, n in v.items() if k in conocidas}
    propias = {k: n for k, n in v.items() if k not in conocidas}
    restados = {}
    if d.get('sim') == 'elevenlabs':
        for k, n in fp.items():
            if k in propias:
                restados[k] = min(n, propias[k]); propias[k] -= restados[k]
                if propias[k] <= 0: del propias[k]
    compacto = lambda x: json.dumps(x, sort_keys=True, separators=(',', ':'))   # sin espacios: lo lee `read`
    print(d.get('sim'), compacto(propias), compacto({**deriva, **{'F-6:' + k: n for k, n in restados.items()}}), d.get('requests'))
PY
)
  local sim propias deriva peticiones
  while read -r sim propias deriva peticiones; do
    caso "simulador $sim: 0 violaciones del contrato (solo se restan los falsos positivos de F-6 verificados por el paso h)" "$([ "$propias" = '{}' ] && echo 0 || echo 1)" "propias $propias · restados $deriva · $peticiones peticiones"
  done <<< "$VIO"
  local HOSTS
  HOSTS=$(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(" ".join(sorted(h for h in d.get("byHost", {}) if h not in ("h8-e2e-sonda.invalid", "h8-e2e-sonda.invalid:443"))) or "ninguno")' "$F/stats-valla.json")
  caso "valla: ningún host fuera de 127.0.0.1 salvo las 2 sondas del arranque" "$([ "$HOSTS" = ninguno ] && echo 0 || echo 1)" "$HOSTS · bloqueadas $(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1])).get("blocked"))' "$F/stats-valla.json")"
  caso "valla: ningún tráfico local por la valla (no_proxy respetado)" "$(python3 -I -c 'import json,sys; print(0 if json.load(open(sys.argv[1])).get("localThroughFence") == 0 else 1)' "$F/stats-valla.json")"
  local FUERA MUESTRAS
  FUERA=$(grep -c . "$F/red/fuera-de-127.txt" 2> /dev/null || true); MUESTRAS=$(grep -c . "$F/red/muestras.txt" 2> /dev/null || true)
  caso "lsof: ninguna conexión de la pila fuera de 127.0.0.1" "$([ "${FUERA:-0}" = 0 ] && echo 0 || echo 1)" "${MUESTRAS:-0} muestras · ${FUERA:-0} líneas fuera"
  local ERRS
  ERRS=$(cat "$COPIA"/storage/logs/*.log 2> /dev/null | grep -c -E '\.(ERROR|CRITICAL|EMERGENCY|ALERT):' || true)
  caso "laravel.log y el canal voice sin ERROR" "$([ "${ERRS:-0}" = 0 ] && echo 0 || echo 1)" "${ERRS:-0}"
  local FJ JQ
  FJ=$(psql_su -d "$DB" -Atc "SELECT count(*) FROM failed_jobs;"); JQ=$(psql_su -d "$DB" -Atc "SELECT count(*) FROM jobs;")
  caso "failed_jobs vacío" "$([ "$FJ" = 0 ] && echo 0 || echo 1)" "$FJ"
  caso "ningún trabajo pendiente en ninguna cola" "$([ "$JQ" = 0 ] && echo 0 || echo 1)" "$JQ"
  local BAR
  pg_dump -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SU" --data-only "$DB" > "$F/datos.sql" 2> /dev/null || true
  BAR=$(python3 -I "$AQUI/barrido.py" "$RUN/sim/piezas.conf" "$COPIA/.env" "$RUN/tokens/$FASE" -- "$F/logs" "$COPIA/storage/logs" "$F/datos.sql" \
    "$F/checks-$FASE.jsonl" "$F/junit-$FASE.xml" "$F/phpunit.out" "$F/stats-elevenlabs.json" "$F/stats-twilio.json" "$F/stats-valla.json" 2>&1) && b=0 || b=$?
  printf '%s\n' "$BAR" > "$F/barrido.txt"
  caso "barrido: ningún secreto, token, centinela ni teléfono completo en registros, base, respuestas ni evidencia" "$b" "$(printf '%s' "$BAR" | tr '\n' ' ' | cut -c1-300)"
  rm -f "$F/datos.sql"
  caso "PHPUnit de la fase salió con 0" "$([ "$EST" = 0 ] && echo 0 || echo 1)" "código $EST"
  cat "$OUT" | sed 's/^/   /'
  # El recuento de la fase: PHPUnit (JUnit) + el cierre.
  python3 -I - "$F/junit-$FASE.xml" "$F/checks-$FASE.jsonl" "$F/resumen-linea.txt" <<'PY'
import json, sys, xml.etree.ElementTree as ET
junit, checks, salida = sys.argv[1:4]
pruebas = fallos = omitidas = 0
try:
    raiz = ET.parse(junit).getroot()
    for tc in raiz.iter('testcase'):
        pruebas += 1
        if tc.find('failure') is not None or tc.find('error') is not None:
            fallos += 1
        elif tc.find('skipped') is not None:
            omitidas += 1
except (OSError, ET.ParseError):
    pass
ok = ko = 0
for l in open(checks, encoding='utf-8'):
    l = l.strip()
    if l:
        d = json.loads(l); ok += d['ok'] is True; ko += d['ok'] is not True
open(salida, 'w').write(f'pruebas {pruebas} ({pruebas - fallos - omitidas} OK, {fallos} FALLO, {omitidas} OMITIDAS) · comprobaciones {ok + ko} ({ok} OK, {ko} FALLO)')
PY
  echo "   fase $FASE: $(cat "$F/resumen-linea.txt")"
}

for FA in $LISTA; do fase "$FA"; done

# ── 5. Resumen, hash de resultados y evidencia ─────────────────────────────────────────────────────────────────────
DUR=$(( $(date +%s) - T0 ))
HASH=$(cat "$RUN"/*/checks-*.jsonl | python3 -I -c 'import hashlib, json, sys
filas = sorted(json.dumps({k: d[k] for k in ("phase", "step", "check", "ok")}, ensure_ascii=False, sort_keys=True) for d in (json.loads(l) for l in sys.stdin if l.strip()))
print(hashlib.sha256("\n".join(filas).encode()).hexdigest())')
FALLOS_TOT=$(cat "$RUN"/*/checks-*.jsonl | python3 -I -c 'import json, sys; print(sum(1 for l in sys.stdin if l.strip() and json.loads(l)["ok"] is not True))')
{
  echo "E2E H8 · backend $CORTO ($SHA) · $(hora) · $DUR s"
  echo "pruebas del E2E (sha256 de tests/Feature/Voice/E2E/*.php): $PRUEBAS_SHA"
  echo "simuladores y valla de ops/banco-h8 (sha256 de los 4 .cjs, del árbol de trabajo): $SIMS_SHA"
  printf '%s' "$RESUMENES"
  echo "comprobaciones que fallaron: $FALLOS_TOT"
  echo "hash de resultados (sha256 de las comprobaciones ordenadas: fase, paso, nombre, ok): $HASH"
} > "$RUN/resumen.txt"
cat "$RUN/resumen.txt"
if [ "$EVIDENCIA" = 1 ]; then
  EV="$OPS/evidencia-h8/$(date -u +%Y-%m-%d)/$SELLO-$CORTO"
  mkdir -p "$EV"
  cp "$RUN/resumen.txt" "$RUN/giro-constante.diff" "$EV/"
  for FA in $LISTA; do
    mkdir -p "$EV/$FA/logs"
    for x in "checks-$FA.jsonl" "junit-$FA.xml" phpunit.out "cierre-$FA.txt" preflight.json verify-voice.json stats-elevenlabs.json stats-twilio.json stats-valla.json barrido.txt resumen-linea.txt; do
      [ -f "$RUN/$FA/$x" ] && cp "$RUN/$FA/$x" "$EV/$FA/"
    done
    for x in sim-elevenlabs.jsonl sim-twilio.jsonl valla.jsonl; do [ -f "$RUN/$FA/logs/$x" ] && cp "$RUN/$FA/logs/$x" "$EV/$FA/logs/"; done
    cp "$RUN/$FA/red/fuera-de-127.txt" "$EV/$FA/red-fuera-de-127.txt" 2> /dev/null || : > "$EV/$FA/red-fuera-de-127.txt"
    echo "$(grep -c . "$RUN/$FA/red/muestras.txt" 2> /dev/null || echo 0) muestras de lsof" > "$EV/$FA/red-muestras.txt"
  done
  # La evidencia también pasa el barrido (va al repo), con la APP_KEY y los tokens de CADA fase.
  : > "$EV/barrido-evidencia.txt"
  for FA in $LISTA; do
    { echo "con las piezas, la APP_KEY y los tokens de la fase $FA:"; python3 -I "$AQUI/barrido.py" "$RUN/sim/piezas.conf" "$RUN/$FA/backend/.env" "$RUN/tokens/$FA" -- "$EV"; } \
      >> "$EV/barrido-evidencia.txt" 2>&1 || { cat "$EV/barrido-evidencia.txt"; muere "la evidencia lleva algo que no debe: NO la confirmes ($EV)"; }
  done
  chmod -R u+rwX,go+rX "$EV"; find "$EV" -type f -exec chmod 644 {} +
  echo "evidencia: ${EV#"$WT"/}"
fi
[ "$FALLOS_TOT" = 0 ]
