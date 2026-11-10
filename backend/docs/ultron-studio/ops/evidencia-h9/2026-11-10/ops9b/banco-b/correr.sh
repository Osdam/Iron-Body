#!/bin/bash
# Banco B: la «producción» del banco en a608450 (lo que sirve producción hoy) → el merge trae a921fbb, 307ad51 (docs del H8)
# y el H9: el paso 0 los lista como ajenos (exit 3) y con --acepta-main queda EN VERDE; después el despliegue completo.
set -u
WT=/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body
O=$WT/backend/docs/ultron-studio/ops/banco-h9
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9-prev
LOGD=$(cd "$(dirname "$0")" && pwd)
export BANCO_DB=ultron_studio_banco_h9_ops9b_prev BANCO_PORT=8511 BANCO_PUERTO_VALLA=8514 BANCO_PREVIO=a608450c
paso() { echo "#### $(date -u +%Y-%m-%dT%H:%M:%SZ) $1"; }
cd "$WT" || exit 1
[ -e "$B" ] && { bash "$O/ensayar.sh" "$B" parar > /dev/null 2>&1; rm -rf "$B"; }
paso "1 montar (BANCO_PREVIO=a608450c)"; bash "$O/montar.sh" "$B" > "$LOGD/01-montar.log" 2>&1; echo "rc=$?"
paso "2 arrancar"; bash "$O/ensayar.sh" "$B" arrancar > "$LOGD/02-arrancar.log" 2>&1; echo "rc=$?"
paso "3 sembrar"; bash "$O/sembrar.sh" "$B" > "$LOGD/03-sembrar.log" 2>&1; echo "rc=$?"
paso "4 paso 0 sin --acepta-main (esperado: FALTA DECIDIR, código 3)"; bash "$O/ensayar.sh" "$B" desplegar --solo-comprobar > "$LOGD/04-solo-comprobar-sin-aceptar.log" 2>&1; echo "rc=$?"
paso "5 paso 0 con --acepta-main=a921fbb,307ad51 (esperado: EN VERDE)"; bash "$O/ensayar.sh" "$B" desplegar --solo-comprobar --acepta-main=a921fbb,307ad51 > "$LOGD/05-solo-comprobar-aceptando.log" 2>&1; echo "rc=$?"
paso "6 despliegue con --acepta-main"; bash "$O/ensayar.sh" "$B" desplegar --acepta-main=a921fbb,307ad51 > "$LOGD/06-desplegar.log" 2>&1; echo "rc=$?"
paso "7 esquema"; bash "$O/ensayar.sh" "$B" esquema > "$LOGD/07-esquema.log" 2>&1; echo "rc=$?"
paso "8 parar"; bash "$O/ensayar.sh" "$B" parar > "$LOGD/08-parar.log" 2>&1; echo "rc=$?"
paso "FIN"
