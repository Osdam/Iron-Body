#!/bin/bash
# Envoltorio de los ensayos del banco del Hito 7: los scripts del repo con los sustitutos del banco delante del PATH.
#   bash ensayar.sh <banco> desplegar [--55p03] [--solo-comprobar]   copia del banco de deploy-h7-backend.sh, con la
#                                                                    copia del banco de la propuesta de ironbody-migrate
#                                                                    como migrador (--55p03: el primer intento choca con
#                                                                    un lector de la cadena y cae por lock_timeout)
#   bash ensayar.sh <banco> smoke [<id de admin>]                    smoke-run-h7.sh del REPO con las variables locales
#                                                                    (SMOKE_BUDGETS, si se exporta, pasa tal cual)
#   bash ensayar.sh <banco> smoke-h6 1 [<id de admin>]               smoke-run-h6.sh del REPO (regresión del H6)
#   bash ensayar.sh <banco> monitor on|off <actual>|estado           copia del banco de h7-monitor-scope.sh (el mando)
#   bash ensayar.sh <banco> tareas <n>                               fija BANCO_TAREAS (el TAREAS del banco: lo que dio
#                                                                    «desplegar --solo-comprobar», como en producción)
# Las copias se regeneran desde el repo antes de cada ensayo (preparar.sh), con PREVIO y TAREAS del banco.
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ORDEN=${2:-}
shift 2 || true
if [ "$ORDEN" = tareas ]; then
  N=${1:-}
  [[ "$N" =~ ^[0-9]+$ ]] || muere "uso: bash ensayar.sh <banco> tareas <n> (el total que leyó desplegar --solo-comprobar)"
  python3 -I - "$B/banco.env" "$N" <<'PY'
import re, sys
p, n = sys.argv[1], sys.argv[2]
s = open(p, encoding='utf-8').read()
s = re.sub(r'(?m)^BANCO_TAREAS=.*\n?', '', s)
s += ('' if s.endswith('\n') or not s else '\n') + f'BANCO_TAREAS={n}\n'
open(p, 'w', encoding='utf-8').write(s)
PY
  echo "BANCO_TAREAS=$N fijado en el banco (la copia de deploy-h7-backend.sh y el smoke lo usan)"
  exit 0
fi
bash "$AQUI/preparar.sh" "$B" > /dev/null
export PATH="$B/bin:$PATH"
LOCAL=(APP_DIR="$B/api/backend" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS=
       SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_WORKDIR="$B/smokework")
case "$ORDEN" in
  desplegar)
    EXTRA=()
    for a in "$@"; do case "$a" in --55p03) touch "$B/lock-once" ;; --solo-comprobar) EXTRA+=(--solo-comprobar) ;; *) muere "opción desconocida: $a" ;; esac; done
    exec bash "$B/root/deploy-h7-backend.sh" "$ESPERADO" "$B/bk" ${EXTRA[@]+"${EXTRA[@]}"} ;;
  smoke)
    ID=${1:-${ADMIN_ID:-}}
    [ -n "$ID" ] || muere "sin cuenta de smoke: bash $AQUI/sembrar.sh $B"
    mkdir -p "$B/smokework"
    exec env "${LOCAL[@]}" SMOKE_PY="$OPS/smoke-h7.py" SMOKE_H6_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" \
      SMOKE_BUDGETS="${SMOKE_BUDGETS:-$OPS/perf-h7/presupuestos-h7.json}" SMOKE_TAREAS="${SMOKE_TAREAS:-${BANCO_TAREAS:-}}" \
      bash "$OPS/smoke-run-h7.sh" ;;
  monitor)
    exec bash "$B/root/h7-monitor-scope.sh" "$@" ;;
  smoke-h6)
    FASE=${1:-1}; ID=${2:-${ADMIN_ID:-}}
    [ -n "$ID" ] || muere "sin cuenta de smoke: bash $AQUI/sembrar.sh $B"
    mkdir -p "$B/smokework"
    exec env "${LOCAL[@]}" SMOKE_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" SMOKE_PHASE="$FASE" bash "$OPS/smoke-run-h6.sh" ;;
  *) muere "uso: bash ensayar.sh <banco> desplegar|smoke|smoke-h6|monitor …" ;;
esac
