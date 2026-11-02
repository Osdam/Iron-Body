#!/bin/bash
# Envoltorio de los ensayos del banco del Hito 7: los scripts del repo con los sustitutos del banco delante del PATH.
#   bash ensayar.sh <banco> desplegar [--55p03] [--solo-comprobar]   copia del banco de deploy-h7-backend.sh, con la
#                                                                    copia del banco de la propuesta de ironbody-migrate
#                                                                    como migrador (--55p03: el primer intento choca con
#                                                                    un lector de la cadena y cae por lock_timeout)
#   bash ensayar.sh <banco> smoke [<id de admin>]                    smoke-run-h7.sh del REPO con las variables locales
#   bash ensayar.sh <banco> smoke-h6 1 [<id de admin>]               smoke-run-h6.sh del REPO (regresión del H6)
# Las copias se regeneran desde el repo antes de cada ensayo (preparar.sh).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ORDEN=${2:-}
shift 2 || true
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
    exec env "${LOCAL[@]}" SMOKE_PY="$OPS/smoke-h7.py" SMOKE_H6_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" bash "$OPS/smoke-run-h7.sh" ;;
  smoke-h6)
    FASE=${1:-1}; ID=${2:-${ADMIN_ID:-}}
    [ -n "$ID" ] || muere "sin cuenta de smoke: bash $AQUI/sembrar.sh $B"
    mkdir -p "$B/smokework"
    exec env "${LOCAL[@]}" SMOKE_PY="$OPS/smoke-h6.py" SMOKE_ADMIN_ID="$ID" SMOKE_PHASE="$FASE" bash "$OPS/smoke-run-h6.sh" ;;
  *) muere "uso: bash ensayar.sh <banco> desplegar|smoke|smoke-h6 …" ;;
esac
