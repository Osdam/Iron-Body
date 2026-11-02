# comun.sh · lo cargan las herramientas del banco del Hito 7 («. "$(dirname "$0")/comun.sh"»). No se ejecuta solo.
# El banco es una «producción» de juguete en el Mac: nunca toca producción, nunca guarda credenciales reales y nunca
# vive dentro del repo (lleva volcados y un fichero de entorno sintético).
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable          # PGGSSENCMODE: php -S en macOS muere en GSSAPI de libpq tras el fork
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
OPS=$(cd "$AQUI/.." && pwd -P)                 # backend/docs/ultron-studio/ops
WT=$(cd "$AQUI/../../../../.." && pwd -P)      # raíz del worktree (Iron-Body)
PG_HOST=127.0.0.1
PG_PORT=${BANCO_PG_PORT:-55432}
PG_USER=ironbody                               # superusuario del clúster desechable (trust), como pgunit.sh
PREVIO_BANCO=7d2c6d8                           # la «producción» del banco: el H6 (base de la rama del H7)
muere() { echo "banco: $*" >&2; exit 1; }
psql_su() { psql -X -q -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$@"; }
banco_dir() {
  local d=${1:-}
  [ -n "$d" ] || muere "falta el directorio del banco (fuera del repo; p. ej. \${XDG_CACHE_HOME:-\$HOME/.cache}/ironbody-banco-h7)"
  d=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$d")
  case "$d/" in "$WT"/*) muere "el banco no puede vivir dentro del repo ($d): llevaría un volcado y un fichero de entorno" ;; esac
  mkdir -p "$(dirname "$d")"
  printf '%s\n' "$d"
}
cargar_banco() {
  [ -f "$1/banco.env" ] || muere "$1 no es un banco montado (falta banco.env): bash $AQUI/montar.sh $1"
  # shellcheck disable=SC1091
  . "$1/banco.env"
}
# Resultado de un caso de ensayo: «OK|FALLO nombre [detalle]»; FALLOS cuenta los fallos.
FALLOS=0; CASOS=0
caso() { CASOS=$((CASOS + 1)); if [ "$2" = 0 ]; then echo "OK      $1${3:+  [$3]}"; else echo "FALLO   $1${3:+  [$3]}"; FALLOS=$((FALLOS + 1)); fi; }
