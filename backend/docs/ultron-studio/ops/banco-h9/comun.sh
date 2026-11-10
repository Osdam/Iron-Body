# comun.sh · lo cargan las herramientas del banco del Hito 9 («. "$(dirname "$0")/comun.sh"»). No se ejecuta solo.
# El banco es una «producción» de juguete en el Mac: nunca toca producción, nunca guarda credenciales reales y nunca
# vive dentro del repo (lleva un volcado y un fichero de entorno sintético). Es el banco del Hito 8 (banco-h8/) sin los
# simuladores de los proveedores: la fase 1 del H9 tiene CERO red (§9.3 del contrato), así que basta la VALLA (el proxy
# del H8 que bloquea y anota; se reutiliza tal cual, banco-h8/valla.cjs). Todo proceso PHP del banco sale por ella salvo
# hacia esta máquina.
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable          # PGGSSENCMODE: php -S en macOS muere en GSSAPI de libpq tras el fork
AQUI=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
OPS=$(cd "$AQUI/.." && pwd -P)                 # backend/docs/ultron-studio/ops
WT=$(cd "$AQUI/../../../../.." && pwd -P)      # raíz del worktree (Iron-Body)
VALLA_CJS="$OPS/banco-h8/valla.cjs"            # la valla del H8, sin cambios
PG_HOST=127.0.0.1
PG_PORT=${BANCO_PG_PORT:-55432}
PG_USER=ironbody                               # superusuario del clúster desechable (trust), como pgunit.sh
# La «producción» del banco: por defecto, HEAD del worktree MÁS el cierre del H8 sin confirmar (montar.sh lo separa de
# lo del H9 por rutas, L9-34); BANCO_PREVIO=<commit> la fija en otro commit. banco.env guarda la resuelta.
PREVIO_BANCO=${BANCO_PREVIO:-HEAD}
[[ "$PREVIO_BANCO" =~ ^([0-9a-f]{7,40}|HEAD)$ ]] || { echo "banco: BANCO_PREVIO no es un commit ni HEAD" >&2; exit 1; }
muere() { echo "banco: $*" >&2; exit 1; }
psql_su() { psql -X -q -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$@"; }
banco_dir() {
  local d=${1:-}
  [ -n "$d" ] || muere "falta el directorio del banco (fuera del repo; p. ej. \${XDG_CACHE_HOME:-\$HOME/.cache}/ironbody-banco-h9)"
  d=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$d")
  case "$d/" in "$WT"/*) muere "el banco no puede vivir dentro del repo ($d): llevaría un volcado y un fichero de entorno" ;; esac
  mkdir -p "$(dirname "$d")"
  printf '%s\n' "$d"
}
# Carga $B/banco.env (BANCO_DB, BANCO_PORT, PUERTO_VALLA, ESPERADO, PREVIO_BANCO y, tras sembrar, ADMIN_ID) y prepara
# RED: el entorno de red de TODO proceso PHP del banco (lo que no sea 127.0.0.1 sale por la valla y se queda).
cargar_banco() {
  [ -f "$1/banco.env" ] || muere "$1 no es un banco montado (falta banco.env): bash $AQUI/montar.sh $1"
  # shellcheck disable=SC1091
  . "$1/banco.env"
  local v="http://127.0.0.1:$PUERTO_VALLA"
  RED=(PGGSSENCMODE=disable "http_proxy=$v" "https_proxy=$v" "HTTP_PROXY=$v" "HTTPS_PROXY=$v" "no_proxy=127.0.0.1,localhost" "NO_PROXY=127.0.0.1,localhost")
}
# ¿Responde el control GET de la valla?
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
# Las rutas del H9 en el árbol (lo que viaja en el commit `wip(ultron-studio-h9)` del origen del banco; lo demás que
# esté sin confirmar es el cierre del H8 y va ANTES, en `wip(ultron-studio-h8)`, como manda L9-34). Con el árbol
# integrado (L9-59) TODO lo que no está confirmado es del H9: el cierre del H8 ya está en main (b8d00f7 y el 1b).
# Los ficheros que tocan los dos hitos (config/ultron_studio.php, StudioAuditEvent.php, UltronStudioVoiceController.php,
# routes/ultron-studio.php, app/Support/Access/**) van con el H9: no están en la puerta de diff del despliegue. Los
# controladores de la ejecución y del canal viven en Crm/UltronStudio (L9-43, L9-56, L9-57): routes/api.php y
# Api/Admin/UltronStudio NO son del H9 (si aparecieran sin confirmar, irían al commit del H8 y el paso 0 abortaría por
# la puerta, que es lo correcto).
es_h9() { # ruta relativa a la raíz del repo → 0 si es del H9
  case "$1" in
    backend/app/Services/Outreach/*|backend/app/Models/Outreach/*|backend/app/Jobs/Outreach/*|backend/app/Console/Commands/Outreach/*) return 0 ;;
    backend/app/Services/UltronStudio/StudioStrateg*|backend/app/Services/UltronStudio/StudioMessageTemplates.php|backend/app/Services/UltronStudio/StudioPersonBindings.php|backend/app/Services/UltronStudio/Stream/*) return 0 ;;
    backend/app/Models/UltronStudio/StudioStrateg*|backend/app/Models/UltronStudio/StudioMessageTemplate.php|backend/app/Models/UltronStudio/StudioPersonBinding.php|backend/app/Models/UltronStudio/StudioAuditEvent.php) return 0 ;;
    backend/app/Http/Controllers/Crm/UltronStudio/UltronStudioStrateg*|backend/app/Http/Controllers/Crm/UltronStudio/UltronStudioOutreach*|backend/app/Http/Controllers/Crm/UltronStudio/UltronStudioStreamController.php|backend/app/Http/Controllers/Crm/UltronStudio/StudioRouteCount.php|backend/app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php) return 0 ;;
    backend/app/Console/Commands/UltronStudio/PersonBindingsCommand.php|backend/app/Support/Access/*|backend/bootstrap/app.php) return 0 ;;
    backend/config/outreach.php|backend/config/ultron_studio_stream.php|backend/config/ultron_studio.php) return 0 ;;
    backend/routes/ultron-studio.php|backend/routes/console.php) return 0 ;;
    backend/database/migrations/2026_11_12_*|backend/.env.example) return 0 ;;
    *) return 1 ;;
  esac
}
