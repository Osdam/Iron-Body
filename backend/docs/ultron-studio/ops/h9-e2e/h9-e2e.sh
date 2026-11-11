#!/bin/bash
# h9-e2e.sh · E2E del Hito 9 con navegador real (prueba #26 del contrato, §13.1; TEST9-b). Nunca toca producción ni sale a
# la red: el backend y el CRM son COPIAS de sus commits (`git archive`, fuera de los repos), la base es PostgreSQL
# DESECHABLE con el modelo de roles de producción, todo proceso PHP sale por la valla (proxy que no deja pasar nada y
# anota el host) y Chrome no resuelve ningún nombre salvo 127.0.0.1 y localhost.
#
#   bash backend/docs/ultron-studio/ops/h9-e2e/h9-e2e.sh [recorridos|pila]    (desde la raíz del worktree del backend)
#
#   recorridos  (por defecto) la pila, el guion `recorridos-h9.cjs` con dos personas, el cierre y la evidencia.
#   pila        la pila en pie hasta SIGTERM (desarrollo del guion: `node recorridos-h9.cjs <run>/context.json`).
#
# Las banderas del H9 se encienden SOLO en el fichero de entorno de la copia (`entorno-h9.ejemplo`): gobierno, publicar,
# la maestra, el modo `shadow`, los eventos del motor comercial y el canal en vivo. La constante de envío sigue cerrada
# (la comprobación previa lo exige). La fase «asistido» del guion cambia en la copia el modo a `live` con el asistido
# encendido (tres líneas) y reinicia la API: el asistido es trabajo de una persona, no un envío (README, «Desviaciones»).
#
# Variables: H9_E2E_REF (HEAD) · H9_CRM_DIR (../Iron-Body_Front) · H9_CRM_REF (HEAD) · H9_E2E_DIR (~/.cache/ironbody-h9-e2e)
#   · H9_E2E_PG_PORT (55432) · H9_E2E_DB (ultron_studio_h9_e2e: se BORRA y se recrea) · H9_E2E_PUERTO_API (8411)
#   · H9_E2E_PUERTO_VALLA (8414) · H9_E2E_VENTANA (07:00-19:00, la del contrato; vacía = sin ventana de publicar)
#   · H9_E2E_EVIDENCIA (1: copia la evidencia a ops/evidencia-h9/<fecha>/e2e/) · H9_NG_ESPERA (3600 s: la compilación
#   del CRM, una vez por commit) · CHROME_PATH
#   · H9_PASOS=a,b (solo esos pasos del guion; desarrollo) · H9_E2E_ARBOL=1 (el backend del ÁRBOL DE TRABAJO sobre HEAD:
#   versionados con su contenido de ahora y sin seguimiento no ignorados) · H9_CRM_ARBOL=1 (lo mismo con el CRM). Sus
#   huellas van en el nombre de la ejecución, de la evidencia y en el resumen; no admiten H9_E2E_REF / H9_CRM_REF.
# Trampas conocidas: README del E2E del H8 (`--no-reload` y PHP_CLI_SERVER_WORKERS, nietos de `php -S`, PGGSSENCMODE en
# macOS, bash 3.2, `http_proxy` en minúsculas) y README de este directorio.
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
OPS=$(dirname "$AQUI")
WT=$(cd "$OPS/../../../.." && pwd -P)
BE="$WT/backend"
QUE=${1:-recorridos}
REF=${H9_E2E_REF:-HEAD}
CRM_REPO=${H9_CRM_DIR:-$(cd "$WT/../Iron-Body_Front" 2> /dev/null && pwd -P || echo "")}
CRM_REF=${H9_CRM_REF:-HEAD}
BASE_DIR=${H9_E2E_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-h9-e2e}
PG_HOST=127.0.0.1
PG_PORT=${H9_E2E_PG_PORT:-55432}
PG_SU=ironbody
DB=${H9_E2E_DB:-ultron_studio_h9_e2e}
P_API=${H9_E2E_PUERTO_API:-8411}
P_VA=${H9_E2E_PUERTO_VALLA:-8414}
PROXY_PORT=8080
NG_PORT=4300
NG_ESPERA=${H9_NG_ESPERA:-3600}
VENTANA=${H9_E2E_VENTANA-07:00-19:00}
EVIDENCIA=${H9_E2E_EVIDENCIA:-1}
CHROME=${CHROME_PATH:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}
ROL_DUENO=h9e2e_owner
ROL_APP=h9e2e_app
ROL_LECTOR=h9e2e_lector
PHP=$(command -v php)

muere() { echo "h9-e2e: $*" >&2; exit 1; }
psql_su() { psql -X -q -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SU" "$@"; }
hora() { date -u +%Y-%m-%dT%H:%M:%SZ; }
case "$QUE" in recorridos|pila) ;; *) muere "uso: h9-e2e.sh [recorridos|pila]" ;; esac
[[ "$DB" =~ ^ultron_studio_h9_e2e[a-z0-9_]{0,40}$ ]] || muere "H9_E2E_DB tiene que empezar por ultron_studio_h9_e2e (se BORRA y se recrea)"
[ -z "$VENTANA" ] || [[ "$VENTANA" =~ ^[0-2][0-9]:[0-5][0-9]-[0-2][0-9]:[0-5][0-9]$ ]] || muere "H9_E2E_VENTANA: HH:MM-HH:MM o vacía"

# ── 0. Requisitos: herramientas, puertos libres, PG, vendor, CRM con su node_modules, Chrome ────────────────────────
for b in php node psql git lsof shasum python3 curl openssl pgrep; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$BE/vendor" ] || muere "falta $BE/vendor (composer install en el worktree)"
[ -n "$CRM_REPO" ] && [ -d "$CRM_REPO/.git" -o -f "$CRM_REPO/.git" ] || muere "no encuentro el repo del CRM (H9_CRM_DIR)"
[ -d "$CRM_REPO/node_modules/puppeteer-core" ] || muere "falta node_modules/puppeteer-core en el CRM ($CRM_REPO)"
[ -x "$CRM_REPO/node_modules/.bin/ng" ] || muere "falta node_modules/.bin/ng en el CRM ($CRM_REPO)"
[ -x "$CHROME" ] || muere "no encuentro Chrome en $CHROME (CHROME_PATH)"
for p in "$P_API" "$P_VA" "$PROXY_PORT" "$NG_PORT"; do
  if lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1; then muere "el puerto $p está ocupado: no arranco encima de un proceso ajeno"; fi
done
psql_su -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en $PG_HOST:$PG_PORT (ops/pgunit.sh lo levanta)"
SHA=$(git -C "$WT" rev-parse --verify "$REF^{commit}")
CORTO=$(git -C "$WT" rev-parse --short=8 "$SHA")
CRM_SHA=$(git -C "$CRM_REPO" rev-parse --verify "$CRM_REF^{commit}")
CRM_CORTO=$(git -C "$CRM_REPO" rev-parse --short=7 "$CRM_SHA")
ARBOL_SHA=""
CRM_ARBOL_SHA=""
if [ "${H9_E2E_ARBOL:-0}" = 1 ]; then
  [ "$REF" = HEAD ] || muere "H9_E2E_ARBOL=1 copia el árbol de trabajo sobre HEAD: no admite H9_E2E_REF"
  ARBOL_SHA=$( { git -C "$WT" diff --binary HEAD -- backend; git -C "$WT" ls-files -o --exclude-standard -z -- backend | (cd "$WT" && xargs -0 shasum -a 256); } | shasum -a 256 | cut -c1-12)
  CORTO="$CORTO-arbol-$ARBOL_SHA"
fi
if [ "${H9_CRM_ARBOL:-0}" = 1 ]; then
  [ "$CRM_REF" = HEAD ] || muere "H9_CRM_ARBOL=1 copia el árbol de trabajo del CRM sobre su HEAD: no admite H9_CRM_REF"
  CRM_ARBOL_SHA=$( { git -C "$CRM_REPO" diff --binary HEAD; git -C "$CRM_REPO" ls-files -o --exclude-standard -z | (cd "$CRM_REPO" && xargs -0 shasum -a 256); } | shasum -a 256 | cut -c1-12)
  CRM_CORTO="$CRM_CORTO-arbol-$CRM_ARBOL_SHA"
fi
# Copia los ficheros de un árbol de trabajo (versionados y sin seguimiento no ignorados; los borrados no viajan).
copia_arbol() { # repo destino [ruta dentro del repo]
  python3 -I - "$1" "$2" "${3:-}" <<'PY' || muere "no se pudo copiar el árbol de trabajo de $1"
import os, shutil, subprocess, sys
repo, dest, sub = sys.argv[1:4]
args = ['git', '-C', repo, 'ls-files', '-c', '-o', '--exclude-standard', '-z'] + (['--', sub] if sub else [])
n = 0
for rel in subprocess.run(args, capture_output=True, check=True).stdout.decode().split('\0'):
    src = os.path.join(repo, rel)
    if not rel or not (os.path.isfile(src) or os.path.islink(src)):
        continue
    d = os.path.join(dest, rel)
    os.makedirs(os.path.dirname(d), exist_ok=True)
    if os.path.lexists(d):
        os.remove(d)
    if os.path.islink(src):
        os.symlink(os.readlink(src), d)
    else:
        shutil.copy2(src, d)
    n += 1
print(f'   árbol de trabajo de {os.path.basename(repo)}: {n} ficheros')
PY
}
SELLO=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$BASE_DIR"
RUN="$BASE_DIR/run-$SELLO-$CORTO-crm-$CRM_CORTO"
RUN_REAL=$(python3 -I -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$BASE_DIR")/$(basename "$RUN")
case "$RUN_REAL/" in "$WT"/*) muere "la ejecución no puede vivir dentro del repo ($RUN_REAL)" ;; esac
umask 077
mkdir -p "$RUN"/{logs,red,run,tokens,capturas,filas}
chmod 700 "$RUN/tokens"
T0=$(date +%s)
echo "== E2E H9 · $(hora) · backend $CORTO · CRM $CRM_CORTO · $QUE · $RUN"

# ── Procesos (el PID real; parar nietos, hijos y padre) ─────────────────────────────────────────────────────────────
arranca() { # nombre directorio orden…
  local n=$1 d=$2; shift 2
  ( cd "$d" || exit 1; nohup "$@" > "$RUN/logs/$n.out" 2>&1 < /dev/null & echo $! > "$RUN/run/$n.pid" )
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
espera_http() { local i; for i in $(seq 1 "$2"); do curl -s -o /dev/null --max-time 2 "$1" && return 0; sleep 1; done; return 1; }
PARADO=0
parar_todo() {
  [ "$PARADO" = 1 ] && return 0
  local n p
  [ -f "$RUN/backend/artisan" ] && [ -f "$RUN/backend/.env" ] && php_copia "$AQUI/ayudante-h9.php" "$RUN/backend" revocar > "$RUN/logs/revocar.json" 2> /dev/null || true
  for n in muestreo crm proxy api valla; do para "$n"; done
  sleep 0.5
  for p in "$P_API" "$P_VA" "$PROXY_PORT" "$NG_PORT"; do
    [ -z "$(escuchando "$p")" ] || { echo "AVISO   el puerto $p sigue ocupado (pid $(escuchando "$p")): lo paro"; kill -KILL $(escuchando "$p") 2> /dev/null || true; }
  done
  PARADO=1
}
trap 'rc=$?; parar_todo; exit $rc' EXIT
trap 'echo "h9-e2e: interrumpido"; exit 130' INT TERM

# ── 1. Las copias: el backend del commit (con el vendor clonado) y el CRM del suyo (ruta estable: caché de Angular) ───
echo "== 1. copias"
if [ -z "$ARBOL_SHA" ]; then
  git -C "$WT" archive --format=tar "$SHA" backend | tar -xf - -C "$RUN"
else
  copia_arbol "$WT" "$RUN" backend
fi
COPIA="$RUN/backend"
[ -f "$COPIA/artisan" ] && [ -f "$COPIA/composer.lock" ] || muere "la copia del backend salió vacía"
cp -cR "$BE/vendor" "$COPIA/vendor" 2> /dev/null || cp -R "$BE/vendor" "$COPIA/vendor"
mkdir -p "$COPIA/storage/logs" "$COPIA/storage/framework/cache" "$COPIA/storage/framework/sessions" "$COPIA/storage/framework/views" "$COPIA/bootstrap/cache"
grep -c 'public const SENDS_ENABLED_IN_CODE = false;' "$COPIA/app/Services/Outreach/Run/OutreachSendGate.php" | grep -qx 1 || muere "la constante de envío no está en false exactamente una vez en la copia"
# La valla, DEL COMMIT (como en el E2E del H8): la de banco-h8.
git -C "$WT" archive --format=tar "$SHA" backend/docs/ultron-studio/ops/banco-h8/valla.cjs | tar -xOf - > "$RUN/valla.cjs"
[ -s "$RUN/valla.cjs" ] || muere "falta la valla en el commit $CORTO"
FE="${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-h9-e2e-crm/crm-${CRM_SHA:0:12}${CRM_ARBOL_SHA:+-arbol-$CRM_ARBOL_SHA}"
mkdir -p "$FE"
if [ -z "$CRM_ARBOL_SHA" ]; then
  git -C "$CRM_REPO" archive --format=tar "$CRM_SHA" | tar -x -C "$FE"
else
  copia_arbol "$CRM_REPO" "$FE"
fi
[ -f "$FE/angular.json" ] && [ -f "$FE/e2e/ultron-studio/proxy.cjs" ] || muere "la copia del CRM salió vacía"
ln -sfn "$CRM_REPO/node_modules" "$FE/node_modules"
grep -q "apiBaseUrl: 'http://127.0.0.1:$PROXY_PORT/api'" "$FE/src/environments/environment.ts" || muere "environment.ts del CRM no apunta la API a 127.0.0.1:$PROXY_PORT"
# El CRM COMPILADO una vez por commit (`development`: el environment.ts que apunta a 127.0.0.1:8080) y servido estático
# por `servidor-crm.cjs`: con la máquina cargada, `ng serve` no terminaba su primera compilación en 10 min por vuelta.
DIST="$FE/dist-e2e/browser"
if [ ! -f "$DIST/index.html" ]; then
  echo "   compilando el CRM (development) en $FE/dist-e2e (una vez por commit; hasta $NG_ESPERA s)"
  ( cd "$FE" && env NG_CLI_ANALYTICS=false "$CRM_REPO/node_modules/.bin/ng" build --configuration development --output-path "$FE/dist-e2e" ) > "$RUN/logs/ng-build.out" 2>&1 &
  NGB=$!
  for _ in $(seq 1 "$NG_ESPERA"); do kill -0 "$NGB" 2> /dev/null || break; sleep 1; done
  kill -0 "$NGB" 2> /dev/null && { kill "$NGB" 2> /dev/null || true; muere "la compilación del CRM no terminó en $NG_ESPERA s"; }
  wait "$NGB" || { tail -30 "$RUN/logs/ng-build.out"; muere "la compilación del CRM falló"; }
fi
[ -f "$DIST/index.html" ] || muere "no hay CRM compilado en $DIST"
arranca crm "$RUN" node "$AQUI/servidor-crm.cjs" --dir "$DIST" --port "$NG_PORT"
GUION_SHA=$(cat "$AQUI"/*.cjs "$AQUI"/*.php "$AQUI"/*.py "$AQUI"/*.sh "$AQUI"/entorno-h9.ejemplo | shasum -a 256 | cut -c1-16)
echo "   backend $CORTO en $COPIA · CRM $CRM_CORTO compilado en $DIST, servido en :$NG_PORT · arnés $GUION_SHA"

# ── 2. El entorno de la copia (600): la plantilla con la APP_KEY de la ejecución ─────────────────────────────────────
python3 -I - "$AQUI/entorno-h9.ejemplo" "$COPIA/.env" "$P_API" "$PG_PORT" "$DB" "$ROL_APP" "$VENTANA" <<'PY'
import base64, os, sys
plantilla, destino, p_api, pg_port, db, app, ventana = sys.argv[1:8]
mapa = {'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(), 'PUERTO_API': p_api, 'PG_PORT': pg_port, 'DB': db, 'DB_APP': app,
        'VENTANA': ventana, 'MODO': 'shadow', 'ASISTIDO': 'false', 'ASISTIDO_CUPO': '0'}
texto = open(plantilla, encoding='utf-8').read()
for k, v in mapa.items():
    texto = texto.replace('@@' + k + '@@', v)
if '@@' in texto:
    sys.exit('h9-e2e: quedan marcadores sin sustituir en el entorno')
fd = os.open(destino, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w', encoding='utf-8') as f:
    f.write(texto)
PY

VALLA="http://127.0.0.1:$P_VA"
RED=(PATH="$PATH" HOME="$HOME" LC_ALL=C PGGSSENCMODE=disable "http_proxy=$VALLA" "https_proxy=$VALLA" "HTTP_PROXY=$VALLA" "HTTPS_PROXY=$VALLA"
  "no_proxy=127.0.0.1,localhost" "NO_PROXY=127.0.0.1,localhost")
SINRED=(PATH="$PATH" HOME="$HOME" LC_ALL=C PGGSSENCMODE=disable http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9
  HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
php_copia() { (cd "$COPIA" && env -i "${RED[@]}" "$PHP" "$@"); }

# ── 3. Base desechable con el modelo de roles de producción y las migraciones ───────────────────────────────────────
echo "== 2. base $DB"
for r in "$ROL_DUENO" "$ROL_APP" "$ROL_LECTOR"; do
  psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = '$r'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE $r LOGIN;"
done
psql_su -d postgres -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB'" | grep -qx 0 || muere "hay conexiones abiertas a $DB"
psql_su -d postgres -c "DROP DATABASE IF EXISTS $DB;" -c "CREATE DATABASE $DB;"
psql_su -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" -c "GRANT USAGE, CREATE ON SCHEMA public TO $ROL_DUENO;" \
  -c "GRANT USAGE ON SCHEMA public TO $ROL_APP, $ROL_LECTOR;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE $ROL_DUENO IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $ROL_APP;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE $ROL_DUENO IN SCHEMA public GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO $ROL_APP;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE $ROL_DUENO IN SCHEMA public GRANT SELECT ON TABLES TO $ROL_LECTOR;"
(cd "$COPIA" && env -i "${SINRED[@]}" DB_USERNAME="$ROL_DUENO" "$PHP" artisan migrate --force -q) > "$RUN/logs/migrate.out" 2>&1 || { tail -20 "$RUN/logs/migrate.out"; muere "las migraciones fallaron"; }
(cd "$COPIA" && env -i "${SINRED[@]}" "$PHP" artisan ultron-studio:import-baseline -q) > "$RUN/logs/import-baseline.out" 2>&1 || { tail -20 "$RUN/logs/import-baseline.out"; muere "import-baseline falló"; }
echo "   migraciones: $(psql_su -d "$DB" -Atc "SELECT count(*) FROM migrations;") · tablas del H9: $(psql_su -d "$DB" -Atc "SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND (tablename LIKE 'outreach\_%' OR tablename IN ('ultron_studio_message_templates','ultron_studio_strategies','ultron_studio_strategy_versions','ultron_studio_strategy_simulations','ultron_studio_strategy_reviews','ultron_studio_strategy_publications','ultron_studio_strategy_publication_chain','ultron_studio_person_bindings'));")"

# ── 4. Comprobación previa: local, la base del E2E, las banderas efectivas y la constante cerrada ───────────────────
(cd "$COPIA" && env -i "${SINRED[@]}" "$PHP" "$AQUI/ayudante-h9.php" "$COPIA" preflight) > "$RUN/preflight.json" || muere "preflight falló"
python3 -I - "$RUN/preflight.json" "$DB" "$ROL_APP" "$VENTANA" <<'PY' || muere "la comprobación previa no cuadra: ver $RUN/preflight.json"
import json, sys
d = json.load(open(sys.argv[1])); db, app, ventana = sys.argv[2:5]
espera = {
    'appEnv': 'local', 'production': False, 'db': {'database': db, 'user': app}, 'sendsEnabledInCode': False,
    'strategies': {'enabled': True, 'publishing': True, 'window': ventana, 'simulationGate': 'required'},
    'outreach': {'enabled': True, 'mode': 'shadow', 'assisted': False, 'assistedCap': 0, 'scheduler': False, 'monitor': False,
                 'whatsapp': False, 'voice': False, 'classifier': False},
    'commercialEvents': True, 'stream': True,
}
mal = {k: (d.get(k), v) for k, v in espera.items() if d.get(k) != v}
if mal:
    print('preflight: ' + json.dumps(mal, ensure_ascii=False)); sys.exit(1)
print(f"   preflight: local · {db} como {app} · constante de envío {d['sendsEnabledInCode']} · modo {d['outreach']['mode']} · ventana «{ventana or 'sin ventana'}» · eventos y canal en vivo encendidos")
PY

# ── 5. La valla, la API (8 workers de php -S, todos con la valla) y el muestreo de red ───────────────────────────────
arranca valla "$RUN" node "$RUN/valla.cjs" --log "$RUN/logs/valla.jsonl" --port "$P_VA"
espera_http "http://127.0.0.1:$P_VA/__stats" 30 || muere "la valla no responde en :$P_VA"
API_CMD=("$PHP" artisan serve --host=127.0.0.1 --port="$P_API" --no-reload)
arranca api "$COPIA" env -i "${RED[@]}" PHP_CLI_SERVER_WORKERS=16 "${API_CMD[@]}"
c=""
for _ in $(seq 1 60); do
  c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$P_API/api/admin/ultron-studio/strategies/catalog" || true)
  [ "$c" = 401 ] && break
  sleep 0.5
done
[ "$c" = 401 ] || muere "la API no respondió con 401 sin sesión (ver $RUN/logs/api.out)"
grep -q 'PHP_CLI_SERVER_WORKERS' "$RUN/logs/api.out" && muere "artisan serve ignoró PHP_CLI_SERVER_WORKERS"
HIJOS=$(descendientes "$(cat "$RUN/run/api.pid")" | tr '\n' ' '); CON=0; TOT=0
for p2 in $HIJOS; do TOT=$((TOT + 1)); ps eww -o command= -p "$p2" 2> /dev/null | grep -q "https_proxy=$VALLA" && CON=$((CON + 1)); done
[ "$TOT" -gt 0 ] && [ "$CON" = "$TOT" ] || muere "los procesos de la API no llevan la valla ($CON de $TOT)"
( while :; do
    for pf in "$RUN"/run/api.pid; do
      [ -f "$pf" ] || continue
      for p3 in $(cat "$pf") $(descendientes "$(cat "$pf")"); do
        lsof -nP -a -p "$p3" -i 2> /dev/null | tail -n +2 | grep -v -E '127\.0\.0\.1|\[::1\]|localhost|\*:[0-9]+ \(LISTEN\)' || true
      done
    done >> "$RUN/red/fuera-de-127.txt"
    echo "$(date -u +%H:%M:%S)" >> "$RUN/red/muestras.txt"
    sleep 1
  done ) > /dev/null 2>&1 &
echo $! > "$RUN/run/muestreo.pid"
SONDA=$(php_copia -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  foreach (["https://h9-e2e-sonda.invalid/x", "http://h9-e2e-sonda.invalid/x"] as $u) { try { echo Illuminate\Support\Facades\Http::timeout(5)->get($u)->status(), " "; } catch (Throwable $e) { echo class_basename($e), " "; } }')
[ "$SONDA" = "ConnectionException 403 " ] || muere "la valla no cortó las sondas ($SONDA)"
echo "   pila: valla :$P_VA (sondas → $SONDA) · API :$P_API ($TOT procesos de php -S, todos con la valla)"

# ── 6. El proxy del H6 (de la MISMA copia del CRM) → la API: el origen :4300 se presenta como :4200 (CORS) ─────────────
arranca proxy "$RUN" env PROXY_PORT="$PROXY_PORT" UPSTREAM_PORT="$P_API" NG_PORT="$NG_PORT" node "$FE/e2e/ultron-studio/proxy.cjs" "$RUN/red/proxy.jsonl"
espera_http "http://127.0.0.1:$PROXY_PORT/up" 30 || muere "el proxy no responde en :$PROXY_PORT"

# ── 7. Las dos personas: cuentas con sesión, el ARRANQUE de los vínculos por consola (P9-24) y la siembra ─────────────
php_copia "$AQUI/ayudante-h9.php" "$COPIA" cuentas "$RUN/tokens" > "$RUN/cuentas.json"
read -r A_ID A_IDENT B_ID B_IDENT <<< "$(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1]))["accounts"]; print(d["a"]["id"], d["a"]["identity"], d["b"]["id"], d["b"]["identity"])' "$RUN/cuentas.json")"
php_copia artisan ultron-studio:person-bindings --bootstrap --admin-a="$A_ID" --identity-a="$A_IDENT" --admin-b="$B_ID" --identity-b="$B_IDENT" \
  --authorization="E2E H9 local en base desechable: arranque de las dos personas de prueba" > "$RUN/logs/arranque-vinculos.out" 2>&1 || { cat "$RUN/logs/arranque-vinculos.out"; muere "el arranque de los vínculos falló"; }
php_copia artisan ultron-studio:person-bindings --list > "$RUN/logs/vinculos.out" 2>&1 || true
# El RELOJ DEL MOTOR (README, «Costuras»): toda ejecución de `outreach:*` por consola corre en T, el próximo día hábil a
# las 10:00 de Bogotá, y la siembra se calcula relativa a T: el resultado no depende de la hora a la que corra el E2E.
T_MOTOR=$(python3 -I -c '
import datetime as dt
festivos = {"2026-11-16", "2026-12-08", "2026-12-25", "2027-01-01", "2027-01-11"}
d = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(hours=5)).date()
while True:
    d += dt.timedelta(days=1)
    if d.weekday() < 5 and d.isoformat() not in festivos:
        break
print(d.isoformat() + "T15:00:00Z")')
php_copia "$AQUI/ayudante-h9.php" "$COPIA" sembrar sombra "$RUN/manifiesto.json" "$T_MOTOR" > "$RUN/siembra-sombra.json" || muere "la siembra falló"
echo "   personas: Ana (cuenta $A_ID) y Beto (cuenta $B_ID), vinculadas y verificadas por la orden de consola · siembra relativa a T=$T_MOTOR: $(cat "$RUN/siembra-sombra.json")"

# ── 8. El contexto del guion (600) ───────────────────────────────────────────────────────────────────────────────────
espera_http "http://127.0.0.1:$NG_PORT/" 30 || { tail -30 "$RUN/logs/crm.out"; muere "el servidor del CRM no responde en :$NG_PORT"; }
echo "   CRM listo ($(( $(date +%s) - T0 )) s desde el inicio)"
python3 -I - "$RUN/context.json" <<PY
import json, os
ctx = {
    'sha': '$SHA', 'crmSha': '$CRM_SHA', 'run': '$RUN', 'backend': '$COPIA', 'php': '$PHP', 'crmRepo': '$CRM_REPO', 'crmCopy': '$FE',
    'api': 'http://127.0.0.1:$P_API', 'proxyApi': 'http://127.0.0.1:$PROXY_PORT', 'crmUrl': 'http://127.0.0.1:$NG_PORT', 'chrome': '$CHROME',
    'checksFile': '$RUN/checks-h9.jsonl', 'shots': '$RUN/capturas', 'red': '$RUN/red', 'filas': '$RUN/filas', 'manifest': '$RUN/manifiesto.json',
    'ports': {'api': $P_API, 'valla': $P_VA, 'proxy': $PROXY_PORT, 'ng': $NG_PORT},
    'db': {'host': '$PG_HOST', 'port': $PG_PORT, 'database': '$DB', 'reader': '$ROL_LECTOR', 'app': '$ROL_APP', 'owner': '$ROL_DUENO'},
    'tokens': {k: '$RUN/tokens/token-' + k for k in ('a', 'b')},
    'accounts': json.load(open('$RUN/cuentas.json'))['accounts'],
    'helpers': {'ayudante': '$AQUI/ayudante-h9.php', 'reloj': '$AQUI/reloj-h9.php'},
    'apiPid': '$RUN/run/api.pid', 'apiLog': '$RUN/logs/api.out', 'apiCmd': ['$PHP', 'artisan', 'serve', '--host=127.0.0.1', '--port=$P_API', '--no-reload'],
    'red_env': {'http_proxy': '$VALLA', 'https_proxy': '$VALLA', 'HTTP_PROXY': '$VALLA', 'HTTPS_PROXY': '$VALLA', 'no_proxy': '127.0.0.1,localhost', 'NO_PROXY': '127.0.0.1,localhost'},
    'window': '$VENTANA', 'steps': os.environ.get('H9_PASOS', ''), 'relojMotor': '$T_MOTOR',
}
fd = os.open('$RUN/context.json', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w') as f:
    json.dump(ctx, f, indent=1)
PY
: > "$RUN/checks-h9.jsonl"

if [ "$QUE" = pila ]; then
  echo "== PILA EN PIE: H9_E2E_CONTEXT=$RUN/context.json · CRM http://127.0.0.1:$NG_PORT · SIGTERM a $$ para parar"
  while :; do sleep 5; done
fi

# ── 9. El guion con el navegador ─────────────────────────────────────────────────────────────────────────────────────
echo "== guion · $(hora)"
set +e
node "$AQUI/recorridos-h9.cjs" "$RUN/context.json" 2>&1 | tee "$RUN/logs/guion.out" | sed 's/^/   /'
EST=${PIPESTATUS[0]}
set -e

# ── 10. El cierre: lo que el navegador no ve ────────────────────────────────────────────────────────────────────────
echo "== cierre · $(hora)"
caso() { python3 -I -c 'import json,sys; print(json.dumps({"phase": "e2e", "step": "cierre", "check": sys.argv[1], "ok": sys.argv[2] == "0", "detail": sys.argv[3]}, ensure_ascii=False))' "$1" "$2" "${3:-}" >> "$RUN/checks-h9.jsonl"
  if [ "$2" = 0 ]; then echo "   OK      $1${3:+  [$3]}"; else echo "   FALLO   $1${3:+  [$3]}"; fi; }
curl -s --max-time 5 "http://127.0.0.1:$P_VA/__stats" > "$RUN/stats-valla.json" || echo '{}' > "$RUN/stats-valla.json"
HOSTS=$(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(" ".join(sorted(h for h in d.get("byHost", {}) if h not in ("h9-e2e-sonda.invalid", "h9-e2e-sonda.invalid:443"))) or "ninguno")' "$RUN/stats-valla.json")
caso "valla: ningún host fuera de 127.0.0.1 salvo las 2 sondas del arranque" "$([ "$HOSTS" = ninguno ] && echo 0 || echo 1)" "$HOSTS"
caso "valla: ningún tráfico local por la valla (no_proxy respetado)" "$(python3 -I -c 'import json,sys; print(0 if json.load(open(sys.argv[1])).get("localThroughFence", 0) == 0 else 1)' "$RUN/stats-valla.json")"
FUERA=$(grep -c . "$RUN/red/fuera-de-127.txt" 2> /dev/null || true); MUESTRAS=$(grep -c . "$RUN/red/muestras.txt" 2> /dev/null || true)
caso "lsof: ninguna conexión de la API fuera de 127.0.0.1" "$([ "${FUERA:-0}" = 0 ] && echo 0 || echo 1)" "${MUESTRAS:-0} muestras · ${FUERA:-0} líneas fuera"
ERRS=$(cat "$COPIA"/storage/logs/*.log 2> /dev/null | grep -c -E '\.(ERROR|CRITICAL|EMERGENCY|ALERT):' || true)
caso "laravel.log sin ERROR" "$([ "${ERRS:-0}" = 0 ] && echo 0 || echo 1)" "${ERRS:-0}"
FJ=$(psql -X -h "$PG_HOST" -p "$PG_PORT" -U "$ROL_LECTOR" -d "$DB" -Atc "SELECT count(*) FROM failed_jobs;")
caso "failed_jobs vacío" "$([ "$FJ" = 0 ] && echo 0 || echo 1)" "$FJ"
read -r P5 P5R <<< "$(python3 -I "$AQUI/cinco-xx.py" "$RUN/red/proxy.jsonl" "$RUN/reinicios-api.jsonl")"
caso "proxy del CRM: ninguna respuesta 5xx de la API (salvo 502 dentro del reinicio deliberado de la fase asistida)" "$([ "$P5" = 0 ] && echo 0 || echo 1)" "$P5 fuera · $P5R durante el reinicio"
php_copia "$AQUI/ayudante-h9.php" "$COPIA" filas "$RUN/filas" > "$RUN/filas.json" || echo '{}' > "$RUN/filas.json"
BAR=$(python3 -I "$AQUI/barrido-h9.py" "$RUN/manifiesto.json" "$COPIA/.env" "$RUN/tokens" -- "$RUN/logs" "$RUN/red" "$RUN/checks-h9.jsonl" \
  "$COPIA/storage/logs" "$RUN/filas" "$RUN/stats-valla.json" 2>&1) && b=0 || b=$?
printf '%s\n' "$BAR" > "$RUN/barrido.txt"
caso "barrido: ningún nombre ni teléfono sintético, APP_KEY ni sesión en registros, red, comprobaciones ni tablas del H9" "$b" "$(printf '%s' "$BAR" | tr '\n' ' ' | cut -c1-300)"
caso "el guion salió con 0" "$EST" "código $EST"

# ── 11. Parada, resumen, hash y evidencia ──────────────────────────────────────────────────────────────────────────
parar_todo
LIBRES=0
for p in "$NG_PORT" "$PROXY_PORT" "$P_API" "$P_VA"; do [ -z "$(escuchando "$p")" ] || LIBRES=1; done
caso "parada limpia: los 4 puertos (CRM, proxy, API y valla) libres" "$LIBRES"
DUR=$(( $(date +%s) - T0 ))
HASH=$(python3 -I -c 'import hashlib, json, sys
filas = sorted(json.dumps({k: d[k] for k in ("phase", "step", "check", "ok")}, ensure_ascii=False, sort_keys=True) for d in (json.loads(l) for l in open(sys.argv[1]) if l.strip()))
print(hashlib.sha256("\n".join(filas).encode()).hexdigest())' "$RUN/checks-h9.jsonl")
RECUENTO=$(python3 -I -c 'import json, sys
ok = ko = 0
for l in open(sys.argv[1]):
    if l.strip():
        d = json.loads(l); ok += d["ok"] is True; ko += d["ok"] is not True
print(f"{ok + ko} comprobaciones ({ok} OK, {ko} FALLO)")' "$RUN/checks-h9.jsonl")
{
  echo "E2E H9 (prueba #26) · backend $CORTO ($SHA${ARBOL_SHA:+ + el árbol de trabajo sin confirmar}) · CRM $CRM_CORTO ($CRM_SHA${CRM_ARBOL_SHA:+ + el árbol de trabajo sin confirmar}) · $(hora) · $DUR s"
  echo "arnés (sha256 de ops/h9-e2e: guion, ayudante, barrido, lanzador y plantilla): $GUION_SHA"
  echo "ventana de publicar: ${VENTANA:-sin ventana} · reloj del motor (consola): $T_MOTOR"
  echo "$RECUENTO"
  echo "hash de resultados (sha256 de las comprobaciones ordenadas: fase, paso, nombre, ok): $HASH"
} > "$RUN/resumen.txt"
cat "$RUN/resumen.txt"
if [ "$EVIDENCIA" = 1 ]; then
  EV="$OPS/evidencia-h9/$(date -u +%Y-%m-%d)/e2e/$SELLO-$CORTO-crm-$CRM_CORTO"
  mkdir -p "$EV/capturas" "$EV/red" "$EV/logs" "$EV/filas"
  cp "$RUN/resumen.txt" "$RUN/checks-h9.jsonl" "$RUN/barrido.txt" "$RUN/stats-valla.json" "$RUN/preflight.json" "$RUN/filas.json" "$EV/"
  cp "$RUN/reinicios-api.jsonl" "$RUN/estado-guion.json" "$EV/" 2> /dev/null || true
  cp "$RUN"/capturas/*.png "$EV/capturas/" 2> /dev/null || true
  cp "$RUN"/red/*.jsonl "$EV/red/" 2> /dev/null || true
  cp "$RUN/red/fuera-de-127.txt" "$EV/red/lsof-fuera-de-127.txt" 2> /dev/null || : > "$EV/red/lsof-fuera-de-127.txt"
  cp "$RUN/logs/guion.out" "$RUN/logs/arranque-vinculos.out" "$RUN/logs/vinculos.out" "$RUN"/logs/consola-*.out "$EV/logs/" 2> /dev/null || true
  cp "$RUN"/filas/*.json "$EV/filas/" 2> /dev/null || true
  { python3 -I "$AQUI/barrido-h9.py" "$RUN/manifiesto.json" "$COPIA/.env" "$RUN/tokens" -- "$EV"; } > "$EV/barrido-evidencia.txt" 2>&1 \
    || { cat "$EV/barrido-evidencia.txt"; muere "la evidencia lleva algo que no debe: NO la confirmes ($EV)"; }
  (cd "$EV" && find . -type f ! -name sha256-evidencia.txt -print0 | sort -z | xargs -0 shasum -a 256 > sha256-evidencia.txt)
  echo "evidencia: $EV ($(find "$EV" -type f | wc -l | tr -d ' ') ficheros, barrida)"
fi
python3 -I -c 'import json,sys; sys.exit(0 if all(json.loads(l)["ok"] for l in open(sys.argv[1]) if l.strip()) else 1)' "$RUN/checks-h9.jsonl"
