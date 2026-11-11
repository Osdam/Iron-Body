#!/bin/bash
# h8-e2e-crm.sh · E2E del CRM («Voz y telefonía») contra la PILA REAL del arnés del H8 (TEST8-b, parte 2; contrato H8,
# D8-13, D8-19, D8-22, D8-23 y Enmiendas 4–13). Nunca toca producción ni sale a la red: la pila es la de `h8-e2e.sh`
# (PG desechable, simuladores de OPS8 en 127.0.0.1, valla, `artisan serve --no-reload`, worker real de `voice`), retenida
# con H8_E2E_HOLD=1 (sin duplicar su lógica), y el CRM es una copia `git archive` de su commit servida con `ng serve`.
#
#   bash backend/docs/ultron-studio/ops/h8-e2e-crm/h8-e2e-crm.sh [abierta|cerrada] [guion …]     (desde la raíz del worktree)
#
# Por defecto: fase abierta (la copia del banco con el giro de UNA línea de la constante: publicar asigna el número y
# las entrantes del simulador llegan) y el guion `recorridos`. La cerrada sirve para mirar el CRM con la constante tal
# cual (publicar se detiene, la puerta da 409).
#
# Piezas, como el E2E del CRM del H6 (`Iron-Body_Front/e2e/ultron-studio/run.sh`):
#   · el CRM en una ruta estable por commit (`~/.cache/ironbody-h8-e2e-crm/crm-<sha>`, la caché de Angular sirve de una
#     ejecución a otra) con el node_modules del worktree del CRM ENLAZADO; `ng serve` en 127.0.0.1:4300; su
#     `environment.ts` apunta la API a 127.0.0.1:8080;
#   · el proxy del H6 (`e2e/ultron-studio/proxy.cjs` de la MISMA copia del CRM) en :8080 → la API del arnés (:8401):
#     presenta el origen :4300 como :4200 (CORS del backend) y anota cada petición SIN valores de la consulta;
#   · Chrome headless (puppeteer-core del CRM) con la resolución de nombres cerrada salvo 127.0.0.1 y localhost
#     (`--host-resolver-rules`): ninguna petición del navegador puede salir; el registro de red (CDP) las anota todas.
# Al final: el cierre (simuladores, valla, lsof, laravel.log, failed_jobs, colas, barrido de secretos y teléfonos), la
# parada limpia de TODO y la evidencia en `ops/evidencia-h8/<fecha>/crm-<sello>-<backend>-<crm>/`.
#
# Variables: H8_CRM_DIR (el worktree del CRM; por defecto ../Iron-Body_Front) · H8_CRM_REF (HEAD) · H8_E2E_REF (HEAD del
#   backend, lo lee el arnés) · H8_E2E_ARBOL=1 (el backend del árbol de trabajo, lo lee el arnés) · H8_CRM_EVIDENCIA (1) ·
#   CHROME_PATH · H8_CRM_NG_ESPERA (900 s) · H8_CRM_HOLD=1 (deja la
#   pila y el CRM en pie al terminar los guiones, hasta SIGTERM) · H8_CRM_ARBOL=1 (el CRM del ÁRBOL DE TRABAJO sobre su
#   HEAD: los ficheros versionados con su contenido de ahora y los sin seguimiento no ignorados; su huella va en el
#   nombre de la ejecución, de la evidencia y en el resumen, como H8_E2E_ARBOL en el backend; no admite H8_CRM_REF).
# Trampas: las del arnés (README de h8-e2e) y las del CRM (README de este directorio).
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
OPS=$(cd "$AQUI/.." && pwd -P)
WT=$(cd "$OPS/../../../.." && pwd -P)
CRM_REPO=$(cd "${H8_CRM_DIR:-$WT/../Iron-Body_Front}" && pwd -P)
CRM_REF=${H8_CRM_REF:-HEAD}
FASE=${1:-abierta}
shift || true
GUIONES=("$@")
[ ${#GUIONES[@]} -gt 0 ] || GUIONES=(recorridos)
NG_PORT=4300
PROXY_PORT=8080
P_API=${H8_E2E_PUERTO_API:-8401}
P_EL=${H8_E2E_PUERTO_EL:-8402}
P_TW=${H8_E2E_PUERTO_TW:-8403}
P_VA=${H8_E2E_PUERTO_VALLA:-8404}
CHROME=${CHROME_PATH:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}
BASE_DIR=${H8_CRM_RUN_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-h8-e2e-crm}
EVIDENCIA=${H8_CRM_EVIDENCIA:-1}
HOLD=${H8_CRM_HOLD:-0}
NG_ESPERA=${H8_CRM_NG_ESPERA:-900}

muere() { echo "h8-e2e-crm: $*" >&2; exit 1; }
hora() { date -u +%Y-%m-%dT%H:%M:%SZ; }
case "$FASE" in abierta|cerrada) ;; *) muere "uso: h8-e2e-crm.sh [abierta|cerrada] [guion …]" ;; esac
for g in "${GUIONES[@]}"; do [ -f "$AQUI/$g.cjs" ] || muere "no existe el guion $AQUI/$g.cjs"; done

# ── 0. Requisitos ────────────────────────────────────────────────────────────────────────────────────────────────────
for b in node git lsof shasum python3 curl psql; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -x "$CHROME" ] || muere "no encuentro Chrome en $CHROME (CHROME_PATH)"
[ -d "$CRM_REPO/node_modules/puppeteer-core" ] || muere "falta node_modules/puppeteer-core en el CRM ($CRM_REPO)"
[ -x "$CRM_REPO/node_modules/.bin/ng" ] || muere "falta node_modules/.bin/ng en el CRM ($CRM_REPO)"
for p in "$NG_PORT" "$PROXY_PORT"; do
  if lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1; then muere "el puerto $p está ocupado: no arranco encima de un proceso ajeno"; fi
done
CRM_SHA=$(git -C "$CRM_REPO" rev-parse --verify "$CRM_REF^{commit}")
BE_SHA=$(git -C "$WT" rev-parse --verify "${H8_E2E_REF:-HEAD}^{commit}")
# H8_E2E_ARBOL=1 (la lee el arnés): el backend del árbol de trabajo sobre HEAD; la misma huella que calcula el arnés.
BE_ID=${BE_SHA:0:8}
if [ -n "${H8_E2E_FUENTE:-}" ]; then
  # H8_E2E_FUENTE (la lee el arnés): la copia del backend de una ejecución anterior; su huella va en el nombre de esa ejecución.
  BE_ID="$BE_ID-arbol-$(basename "$(dirname "$H8_E2E_FUENTE")" | sed -n 's/.*-arbol-\([0-9a-f]\{12\}\)$/\1/p')"
elif [ "${H8_E2E_ARBOL:-0}" = 1 ]; then
  BE_ID="$BE_ID-arbol-$( { git -C "$WT" diff --binary HEAD -- backend; git -C "$WT" ls-files -o --exclude-standard -z -- backend | (cd "$WT" && xargs -0 shasum -a 256); } | shasum -a 256 | cut -c1-12)"
fi
CRM_ID=${CRM_SHA:0:7}
CRM_ARBOL_SHA=""
if [ "${H8_CRM_ARBOL:-0}" = 1 ]; then
  [ "$CRM_REF" = HEAD ] || muere "H8_CRM_ARBOL=1 copia el árbol de trabajo del CRM sobre su HEAD: no admite H8_CRM_REF"
  # La huella del árbol del CRM: el diff respecto de HEAD y el contenido de los ficheros sin seguimiento no ignorados.
  CRM_ARBOL_SHA=$( { git -C "$CRM_REPO" diff --binary HEAD; git -C "$CRM_REPO" ls-files -o --exclude-standard -z | (cd "$CRM_REPO" && xargs -0 shasum -a 256); } | shasum -a 256 | cut -c1-12)
  CRM_ID="$CRM_ID-arbol-$CRM_ARBOL_SHA"
fi
SELLO=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$BASE_DIR"
RUN="$BASE_DIR/run-$SELLO-$BE_ID-$CRM_ID"
FE="$BASE_DIR/crm-${CRM_SHA:0:12}${CRM_ARBOL_SHA:+-arbol-$CRM_ARBOL_SHA}"
case "$(python3 -I -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$BASE_DIR")/" in "$WT"/*|"$CRM_REPO"/*) muere "la ejecución no puede vivir dentro de un repo" ;; esac
umask 077
mkdir -p "$RUN"/{logs,run,capturas,red}
T0=$(date +%s)
echo "== E2E CRM H8 · $(hora) · backend $BE_ID · CRM $CRM_ID · fase $FASE · guiones: ${GUIONES[*]} · $RUN"

# ── Procesos (las trampas del arnés: el PID real, y parar nietos, hijos y padre) ──────────────────────────────────────
arranca() { # nombre directorio orden…
  local n=$1 d=$2; shift 2
  ( cd "$d" || exit 1; nohup "$@" > "$RUN/logs/$n.out" 2>&1 < /dev/null & echo $! > "$RUN/run/$n.pid" )
}
descendientes() { local c; for c in $(pgrep -P "$1" 2> /dev/null); do descendientes "$c"; echo "$c"; done; }
para() { # nombre [señal]
  local f="$RUN/run/$1.pid" pid arbol i p vivo
  [ -f "$f" ] || return 0
  pid=$(cat "$f"); arbol="$(descendientes "$pid") $pid"
  kill -TERM $arbol 2> /dev/null || true
  for i in $(seq 1 100); do vivo=0; for p in $arbol; do kill -0 "$p" 2> /dev/null && vivo=1; done; [ "$vivo" = 0 ] && break; sleep 0.1; done
  kill -KILL $arbol 2> /dev/null || true
  rm -f "$f"
}
# El arnés se para con SIGTERM a SU proceso (no a sus hijos): su trampa EXIT para la pila entera, en orden.
para_arnes() {
  local f="$RUN/run/arnes.pid" pid i
  [ -f "$f" ] || return 0
  pid=$(cat "$f")
  kill -TERM "$pid" 2> /dev/null || true
  for i in $(seq 1 300); do kill -0 "$pid" 2> /dev/null || break; sleep 0.1; done
  kill -0 "$pid" 2> /dev/null && { echo "AVISO   el arnés no paró en 30 s: lo mato con su árbol"; para arnes; }
  rm -f "$f"
}
escuchando() { { lsof -nP -iTCP:"$1" -sTCP:LISTEN 2> /dev/null || true; } | tail -n +2 | awk '{print $2}' | sort -u | tr '\n' ' '; }
espera_http() { local i; for i in $(seq 1 "$2"); do curl -s -o /dev/null --max-time 2 "$1" && return 0; sleep 1; done; return 1; }
PARADO=0
parar_todo() {
  [ "$PARADO" = 1 ] && return 0
  local n p
  for n in ng proxy; do para "$n"; done
  para_arnes
  sleep 0.5
  for p in "$NG_PORT" "$PROXY_PORT" "$P_API" "$P_EL" "$P_TW" "$P_VA"; do
    [ -z "$(escuchando "$p")" ] || { echo "AVISO   el puerto $p sigue ocupado (pid $(escuchando "$p")): lo paro"; kill -KILL $(escuchando "$p") 2> /dev/null || true; }
  done
  PARADO=1
}
trap 'rc=$?; parar_todo; exit $rc' EXIT
trap 'echo "h8-e2e-crm: interrumpido"; exit 130' INT TERM

# ── 1. El CRM: copia del commit en una ruta estable, node_modules enlazado, ng serve ─────────────────────────────────
echo "== 1. CRM $CRM_ID en $FE"
mkdir -p "$FE"
# Se reextrae entero en cada ejecución (deshace cualquier cambio a mano en la copia); la caché de Angular se queda.
if [ -z "$CRM_ARBOL_SHA" ]; then
  git -C "$CRM_REPO" archive --format=tar "$CRM_SHA" | tar -x -C "$FE"
else
  python3 -I - "$CRM_REPO" "$FE" <<'PY' || muere "no se pudo copiar el árbol de trabajo del CRM"
import os, shutil, subprocess, sys
repo, dest = sys.argv[1:3]
lista = subprocess.run(['git', '-C', repo, 'ls-files', '-c', '-o', '--exclude-standard', '-z'], capture_output=True, check=True).stdout
n = 0
for rel in lista.decode().split('\0'):
    src = os.path.join(repo, rel)
    if not rel or not (os.path.isfile(src) or os.path.islink(src)):
        continue  # borrado en el árbol: no viaja
    d = os.path.join(dest, rel)
    os.makedirs(os.path.dirname(d), exist_ok=True)
    if os.path.lexists(d):
        os.remove(d)
    if os.path.islink(src):
        os.symlink(os.readlink(src), d)
    else:
        shutil.copy2(src, d)
    n += 1
print(f'   árbol de trabajo del CRM: {n} ficheros (versionados y sin seguimiento no ignorados)')
PY
fi
[ -f "$FE/angular.json" ] && [ -f "$FE/e2e/ultron-studio/proxy.cjs" ] || muere "la copia del CRM salió vacía"
ln -sfn "$CRM_REPO/node_modules" "$FE/node_modules"
grep -q "apiBaseUrl: 'http://127.0.0.1:$PROXY_PORT/api'" "$FE/src/environments/environment.ts" || muere "environment.ts no apunta la API a 127.0.0.1:$PROXY_PORT"
arranca ng "$FE" env NG_CLI_ANALYTICS=false "$CRM_REPO/node_modules/.bin/ng" serve --host 127.0.0.1 --port "$NG_PORT" --live-reload=false --hmr=false
echo "   ng serve :$NG_PORT (pid $(cat "$RUN/run/ng.pid")) compilando en paralelo"

# ── 2. La pila del arnés del backend, retenida (H8_E2E_HOLD=1, sin copiar su evidencia: la de aquí es otra) ─────────
echo "== 2. pila del arnés (fase $FASE, retenida)"
arranca arnes "$WT" env H8_E2E_HOLD=1 H8_E2E_EVIDENCIA=0 bash "$OPS/h8-e2e.sh" "$FASE"
CTX=""
for _ in $(seq 1 900); do
  CTX=$(sed -n 's/^== PILA EN PIE (H8_E2E_HOLD=1): H8_E2E_CONTEXT=\([^ ]*\) .*/\1/p' "$RUN/logs/arnes.out" | head -1)
  [ -n "$CTX" ] && break
  kill -0 "$(cat "$RUN/run/arnes.pid")" 2> /dev/null || { tail -25 "$RUN/logs/arnes.out"; muere "el arnés del backend terminó antes de dejar la pila en pie"; }
  sleep 1
done
[ -n "$CTX" ] && [ -f "$CTX" ] || { tail -25 "$RUN/logs/arnes.out"; muere "el arnés no dejó la pila en pie en 15 min"; }
sed 's/^/   │ /' "$RUN/logs/arnes.out"
F_ARNES=$(dirname "$CTX")
RUN_ARNES=$(dirname "$F_ARNES")

# ── 3. El proxy del H6 (de la misma copia del CRM) → la API del arnés ───────────────────────────────────────────────
arranca proxy "$RUN" env PROXY_PORT="$PROXY_PORT" UPSTREAM_PORT="$P_API" NG_PORT="$NG_PORT" node "$FE/e2e/ultron-studio/proxy.cjs" "$RUN/red/proxy.jsonl"
espera_http "http://127.0.0.1:$PROXY_PORT/up" 30 || muere "el proxy no responde en :$PROXY_PORT"
echo "   proxy :$PROXY_PORT → API :$P_API"

# ── 4. ng serve listo ──────────────────────────────────────────────────────────────────────────────────────────────
espera_http "http://127.0.0.1:$NG_PORT/" "$NG_ESPERA" || { tail -30 "$RUN/logs/ng.out"; muere "ng serve no arrancó en $NG_ESPERA s"; }
echo "   ng serve listo ($(( $(date +%s) - T0 )) s desde el inicio)"

# ── 5. El contexto de los guiones (sin secretos: rutas a los ficheros 600 del arnés) ─────────────────────────────────
python3 -I - "$RUN/crm-context.json" <<PY
import json, os
ctx = {
    'phase': '$FASE', 'run': '$RUN', 'harnessContext': '$CTX', 'harnessRun': '$F_ARNES', 'harnessRoot': '$RUN_ARNES',
    'crmUrl': 'http://127.0.0.1:$NG_PORT', 'proxyApi': 'http://127.0.0.1:$PROXY_PORT/api', 'crmRepo': '$CRM_REPO', 'crmCopy': '$FE',
    'crmSha': '$CRM_SHA', 'backendSha': '$BE_SHA', 'chrome': '$CHROME', 'checksFile': '$RUN/checks-crm.jsonl',
    'shots': '$RUN/capturas', 'red': '$RUN/red', 'ops': '$OPS', 'aqui': '$AQUI',
}
fd = os.open('$RUN/crm-context.json', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, 'w') as f:
    json.dump(ctx, f, indent=1)
PY
: > "$RUN/checks-crm.jsonl"

# ── 6. Los guiones ─────────────────────────────────────────────────────────────────────────────────────────────────
EST=0
for g in "${GUIONES[@]}"; do
  echo "== guion $g · $(hora)"
  # Sin «|| true» detrás de la tubería: ese `true` pisaba PIPESTATUS y «los guiones salieron con 0» salía siempre en
  # verde (corregido el 10-11 por el tester final del H9; informe-tester-final §B-T6).
  set +e
  node "$AQUI/$g.cjs" "$RUN/crm-context.json" 2>&1 | tee "$RUN/logs/guion-$g.out" | sed 's/^/   /'
  GEST=${PIPESTATUS[0]}
  set -e
  [ "$GEST" = 0 ] || EST=1
done

if [ "$HOLD" = 1 ]; then
  echo "== H8_CRM_HOLD=1: pila y CRM en pie (http://127.0.0.1:$NG_PORT, contexto $RUN/crm-context.json). SIGTERM a $$ para parar."
  while :; do sleep 5; done
fi

# ── 7. El cierre: lo que el navegador no ve ─────────────────────────────────────────────────────────────────────────
echo "== cierre · $(hora)"
caso() { python3 -I -c 'import json,sys; print(json.dumps({"phase": sys.argv[1], "step": "cierre", "check": sys.argv[2], "ok": sys.argv[3] == "0", "detail": sys.argv[4]}, ensure_ascii=False))' "$FASE" "$1" "$2" "${3:-}" >> "$RUN/checks-crm.jsonl"
  if [ "$2" = 0 ]; then echo "   OK      $1${3:+  [$3]}"; else echo "   FALLO   $1${3:+  [$3]}"; fi; }
for s in "$P_EL:elevenlabs" "$P_TW:twilio" "$P_VA:valla"; do curl -s --max-time 5 "http://127.0.0.1:${s%%:*}/__stats" > "$RUN/stats-${s#*:}.json" || echo '{}' > "$RUN/stats-${s#*:}.json"; done
while read -r sim n peticiones; do
  caso "simulador $sim: 0 violaciones del contrato durante el E2E del CRM" "$([ "$n" = 0 ] && echo 0 || echo 1)" "$n violaciones · $peticiones peticiones"
done <<< "$(python3 -I -c 'import json,sys
for f in sys.argv[1:]:
    d = json.load(open(f)); v = d.get("violationsByCode", {}) or {}
    print(d.get("sim"), sum(v.values()), d.get("requests"))' "$RUN/stats-elevenlabs.json" "$RUN/stats-twilio.json")"
HOSTS=$(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(" ".join(sorted(h for h in d.get("byHost", {}) if h not in ("h8-e2e-sonda.invalid", "h8-e2e-sonda.invalid:443"))) or "ninguno")' "$RUN/stats-valla.json")
caso "valla del backend: ningún host fuera de 127.0.0.1 salvo las 2 sondas del arranque" "$([ "$HOSTS" = ninguno ] && echo 0 || echo 1)" "$HOSTS"
FUERA=$(grep -c . "$F_ARNES/red/fuera-de-127.txt" 2> /dev/null || true); MUESTRAS=$(grep -c . "$F_ARNES/red/muestras.txt" 2> /dev/null || true)
caso "lsof del arnés: ninguna conexión de la pila fuera de 127.0.0.1" "$([ "${FUERA:-0}" = 0 ] && echo 0 || echo 1)" "${MUESTRAS:-0} muestras · ${FUERA:-0} líneas fuera"
COPIA=$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["backend"])' "$CTX")
ERRS=$(cat "$COPIA"/storage/logs/*.log 2> /dev/null | grep -c -E '\.(ERROR|CRITICAL|EMERGENCY|ALERT):' || true)
caso "laravel.log y el canal voice sin ERROR" "$([ "${ERRS:-0}" = 0 ] && echo 0 || echo 1)" "${ERRS:-0}"
DBN=$(python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1]))["db"]; print(d["database"], d["port"], d["reader"])' "$CTX")
read -r DB_NOMBRE DB_PUERTO DB_LECTOR <<< "$DBN"
FJ=$(psql -X -h 127.0.0.1 -p "$DB_PUERTO" -U "$DB_LECTOR" -d "$DB_NOMBRE" -Atc "SELECT count(*) FROM failed_jobs;")
JQ=$(psql -X -h 127.0.0.1 -p "$DB_PUERTO" -U "$DB_LECTOR" -d "$DB_NOMBRE" -Atc "SELECT count(*) FROM jobs;")
caso "failed_jobs vacío" "$([ "$FJ" = 0 ] && echo 0 || echo 1)" "$FJ"
caso "ningún trabajo pendiente en ninguna cola" "$([ "$JQ" = 0 ] && echo 0 || echo 1)" "$JQ"
P5=$(python3 -I -c 'import json,sys; print(sum(1 for l in open(sys.argv[1]) if l.strip() and json.loads(l).get("status", 0) >= 500))' "$RUN/red/proxy.jsonl")
caso "proxy del CRM: ninguna respuesta 5xx de la API" "$([ "$P5" = 0 ] && echo 0 || echo 1)" "$P5"
# El barrido del arnés (nunca imprime un valor) sobre TODO lo que este E2E deja: registros, red, capturas no (binarias).
TOKENS_DIR="$RUN_ARNES/tokens/$FASE"
pg_dump -h 127.0.0.1 -p "$DB_PUERTO" -U "$DB_LECTOR" --data-only "$DB_NOMBRE" > "$RUN/datos.sql" 2> /dev/null || true
BAR=$(python3 -I "$OPS/h8-e2e/barrido.py" "$RUN_ARNES/sim/piezas.conf" "$COPIA/.env" "$TOKENS_DIR" -- "$RUN/logs" "$RUN/red" "$RUN/checks-crm.jsonl" \
  "$COPIA/storage/logs" "$RUN/datos.sql" "$RUN/stats-elevenlabs.json" "$RUN/stats-twilio.json" "$RUN/stats-valla.json" 2>&1) && b=0 || b=$?
rm -f "$RUN/datos.sql"
printf '%s\n' "$BAR" > "$RUN/barrido.txt"
caso "barrido: ningún secreto, token, centinela ni teléfono completo en registros, red, base ni comprobaciones" "$b" "$(printf '%s' "$BAR" | tr '\n' ' ' | cut -c1-300)"
caso "los guiones salieron con 0" "$EST" "código $EST"

# ── 8. Parada, resumen, hash y evidencia ───────────────────────────────────────────────────────────────────────────
parar_todo
LIBRES=0
for p in "$NG_PORT" "$PROXY_PORT" "$P_API" "$P_EL" "$P_TW" "$P_VA"; do [ -z "$(escuchando "$p")" ] || LIBRES=1; done
caso "parada limpia: los 6 puertos (CRM, proxy, API, simuladores y valla) libres" "$LIBRES"
DUR=$(( $(date +%s) - T0 ))
HASH=$(python3 -I -c 'import hashlib, json, sys
filas = sorted(json.dumps({k: d[k] for k in ("phase", "step", "check", "ok")}, ensure_ascii=False, sort_keys=True) for d in (json.loads(l) for l in open(sys.argv[1]) if l.strip()))
print(hashlib.sha256("\n".join(filas).encode()).hexdigest())' "$RUN/checks-crm.jsonl")
RECUENTO=$(python3 -I -c 'import json, sys
ok = ko = 0
for l in open(sys.argv[1]):
    if l.strip():
        d = json.loads(l); ok += d["ok"] is True; ko += d["ok"] is not True
print(f"{ok + ko} comprobaciones ({ok} OK, {ko} FALLO)")' "$RUN/checks-crm.jsonl")
GUIONES_SHA=$(cat "$AQUI"/*.cjs | shasum -a 256 | cut -c1-16)
{
  echo "E2E CRM H8 · backend $BE_ID ($BE_SHA$([ "${H8_E2E_ARBOL:-0}" = 1 ] && echo " + el árbol de trabajo sin confirmar")$([ -n "${H8_E2E_FUENTE:-}" ] && echo " + el árbol de trabajo sin confirmar, relanzado desde la copia de una ejecución anterior")) · CRM $CRM_ID ($CRM_SHA${CRM_ARBOL_SHA:+ + el árbol de trabajo sin confirmar del CRM}) · fase $FASE · $(hora) · $DUR s"
  echo "guiones (sha256 de ops/h8-e2e-crm/*.cjs): $GUIONES_SHA"
  echo "$RECUENTO"
  echo "hash de resultados (sha256 de las comprobaciones ordenadas: fase, paso, nombre, ok): $HASH"
} > "$RUN/resumen.txt"
cat "$RUN/resumen.txt"
if [ "$EVIDENCIA" = 1 ]; then
  EV="$OPS/evidencia-h8/$(date -u +%Y-%m-%d)/crm-$SELLO-$BE_ID-$CRM_ID"
  mkdir -p "$EV/capturas" "$EV/red" "$EV/logs"
  cp "$RUN/resumen.txt" "$RUN/checks-crm.jsonl" "$RUN/barrido.txt" "$RUN"/stats-*.json "$EV/"
  cp "$RUN"/capturas/*.png "$EV/capturas/" 2> /dev/null || true
  cp "$RUN"/red/* "$EV/red/" 2> /dev/null || true
  cp "$RUN"/logs/guion-*.out "$EV/logs/" 2> /dev/null || true
  # El registro del arnés sin la línea de la pila retenida (lleva rutas locales, no secretos).
  cp "$RUN/logs/arnes.out" "$EV/logs/arnes.out"
  cp "$F_ARNES/red/fuera-de-127.txt" "$EV/red/lsof-fuera-de-127.txt" 2> /dev/null || : > "$EV/red/lsof-fuera-de-127.txt"
  { python3 -I "$OPS/h8-e2e/barrido.py" "$RUN_ARNES/sim/piezas.conf" "$COPIA/.env" "$TOKENS_DIR" -- "$EV"; } > "$EV/barrido-evidencia.txt" 2>&1 \
    || { cat "$EV/barrido-evidencia.txt"; muere "la evidencia lleva algo que no debe: NO la confirmes ($EV)"; }
  chmod -R u+rwX,go+rX "$EV"; find "$EV" -type f -exec chmod 644 {} +
  echo "evidencia: ${EV#"$WT"/} · sha256 del directorio: $(cd "$EV" && find . -type f ! -name 'sha256-evidencia.txt' | LC_ALL=C sort | xargs shasum -a 256 | tee sha256-evidencia.txt | shasum -a 256 | cut -c1-64)"
fi
python3 -I -c 'import json,sys; sys.exit(0 if all(json.loads(l)["ok"] is True for l in open(sys.argv[1]) if l.strip()) else 1)' "$RUN/checks-crm.jsonl"
