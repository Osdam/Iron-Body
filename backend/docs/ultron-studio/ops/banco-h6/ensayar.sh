#!/bin/bash
# Envoltorio de los ensayos del banco: los scripts del repo con los sustitutos del banco delante en el PATH.
#   bash ensayar.sh <banco> desplegar [--55p03] [--solo-comprobar]   copia del banco de deploy-h6-backend-fase1.sh
#                                                                    (--55p03: el primer ironbody-migrate choca con un
#                                                                    lector del libro y la 100002 cae por lock_timeout)
#   bash ensayar.sh <banco> alcance writes|probe on|off <actual>     copia del banco de h6-integrations-scope.sh
#   bash ensayar.sh <banco> smoke 1|2 [<id de admin>]                smoke-run-h6.sh del REPO, con las variables del
#                                                                    ensayo local (sin sudo, psql y API del banco)
# Las copias se regeneran desde el repo antes de cada ensayo (preparar.sh). Cupo de la sonda: 6/h por persona y el
# smoke gasta 2 (como mucho 3 smokes por hora y cuenta; otra cuenta tiene su propio cupo).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ORDEN=${2:-}
shift 2 || true
bash "$AQUI/preparar.sh" "$B" > /dev/null
export PATH="$B/bin:$PATH"
case "$ORDEN" in
  desplegar)
    EXTRA=()
    for a in "$@"; do case "$a" in --55p03) touch "$B/lock-once" ;; --solo-comprobar) EXTRA+=(--solo-comprobar) ;; *) muere "opción desconocida: $a" ;; esac; done
    exec bash "$B/root/deploy-h6-backend-fase1.sh" "$ESPERADO" "$B/bk" ${EXTRA[@]+"${EXTRA[@]}"} ;;
  alcance)
    exec bash "$B/root/h6-integrations-scope.sh" "$@" ;;
  smoke)
    FASE=${1:-}; ID=${2:-${ADMIN_ID:-}}
    [ -n "$ID" ] || muere "sin cuenta de smoke: bash $AQUI/sembrar.sh $B"
    mkdir -p "$B/smokework"
    exec env APP_DIR="$B/api/backend" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS= \
      SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_WORKDIR="$B/smokework" \
      SMOKE_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" SMOKE_PHASE="$FASE" bash "$OPS/smoke-run-h6.sh" ;;
  *) muere "uso: bash ensayar.sh <banco> desplegar|alcance|smoke …" ;;
esac
