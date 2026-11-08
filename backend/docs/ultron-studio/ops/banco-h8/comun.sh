# comun.sh · lo cargan las herramientas del banco del Hito 8 («. "$(dirname "$0")/comun.sh"»). No se ejecuta solo.
# El banco es una «producción» de juguete en el Mac: nunca toca producción, nunca guarda credenciales reales y nunca
# vive dentro del repo (lleva un volcado, un fichero de entorno sintético y las piezas sintéticas de los simuladores).
# Protección 3 del contrato H8: ninguna llamada real; los simuladores escuchan en 127.0.0.1 y todo proceso PHP del
# banco sale por la VALLA (proxy que bloquea y anota) salvo hacia esta máquina.
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable          # PGGSSENCMODE: php -S en macOS muere en GSSAPI de libpq tras el fork
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
OPS=$(cd "$AQUI/.." && pwd -P)                 # backend/docs/ultron-studio/ops
WT=$(cd "$AQUI/../../../../.." && pwd -P)      # raíz del worktree (Iron-Body)
SUPERVISOR_VOZ="$WT/backend/docs/ops/supervisor/ironbody-voice.conf"   # el BORRADOR del worker (sin instalar)
PG_HOST=127.0.0.1
PG_PORT=${BANCO_PG_PORT:-55432}
PG_USER=ironbody                               # superusuario del clúster desechable (trust), como pgunit.sh
# La «producción» del banco: cfcd0d1 (la del 03-11-2026) por defecto; BANCO_PREVIO la cambia al montar y banco.env la
# guarda (OPS8-b: 7c84cb5, con lo ajeno de planes y beneficios ya en ella, como la producción del 08-11-2026 más un commit).
PREVIO_BANCO=${BANCO_PREVIO:-cfcd0d1}
[[ "$PREVIO_BANCO" =~ ^[0-9a-f]{7,40}$ ]] || { echo "banco: BANCO_PREVIO no es un commit" >&2; exit 1; }
muere() { echo "banco: $*" >&2; exit 1; }
psql_su() { psql -X -q -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$@"; }
banco_dir() {
  local d=${1:-}
  [ -n "$d" ] || muere "falta el directorio del banco (fuera del repo; p. ej. \${XDG_CACHE_HOME:-\$HOME/.cache}/ironbody-banco-h8)"
  d=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$d")
  case "$d/" in "$WT"/*) muere "el banco no puede vivir dentro del repo ($d): llevaría un volcado y un fichero de entorno" ;; esac
  mkdir -p "$(dirname "$d")"
  printf '%s\n' "$d"
}
# Carga $B/banco.env (BANCO_DB, BANCO_PORT, PUERTO_EL, PUERTO_TW, PUERTO_VALLA, ESPERADO y, tras sembrar, ADMIN_ID) y
# prepara RED: el entorno de red de TODO proceso PHP del banco (lo que no sea 127.0.0.1 sale por la valla y se queda).
cargar_banco() {
  [ -f "$1/banco.env" ] || muere "$1 no es un banco montado (falta banco.env): bash $AQUI/montar.sh $1"
  # shellcheck disable=SC1091
  . "$1/banco.env"
  local v="http://127.0.0.1:$PUERTO_VALLA"
  RED=(PGGSSENCMODE=disable "http_proxy=$v" "https_proxy=$v" "HTTP_PROXY=$v" "HTTPS_PROXY=$v" "no_proxy=127.0.0.1,localhost" "NO_PROXY=127.0.0.1,localhost")
}
# ¿Responde un control GET de 127.0.0.1? (los simuladores y la valla)
vivo() { curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$1/__stats"; }
# Un campo de un JSON por la entrada estándar (ruta con puntos), sin jq.
campo() { python3 -I -c 'import json, sys
d = json.load(sys.stdin)
for p in sys.argv[1].split("."):
    d = d.get(p) if isinstance(d, dict) else None
print(json.dumps(d) if isinstance(d, (dict, list)) else ("" if d is None else d))' "$1"; }
# Resultado de un caso de ensayo: «OK|FALLO nombre [detalle]»; FALLOS cuenta los fallos.
FALLOS=0; CASOS=0
caso() { CASOS=$((CASOS + 1)); if [ "$2" = 0 ]; then echo "OK      $1${3:+  [$3]}"; else echo "FALLO   $1${3:+  [$3]}"; FALLOS=$((FALLOS + 1)); fi; }
