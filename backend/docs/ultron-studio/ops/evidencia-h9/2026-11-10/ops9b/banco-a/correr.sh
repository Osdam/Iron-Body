#!/bin/bash
set -u
WT=/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body
O=$WT/backend/docs/ultron-studio/ops/banco-h9
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9
LOGD=$(cd "$(dirname "$0")" && pwd)
paso() { echo "#### $(date -u +%Y-%m-%dT%H:%M:%SZ) $1"; }
cd "$WT" || exit 1
paso "0 parar y limpiar el banco anterior (ab54a25, antes de la integración)"
bash "$O/ensayar.sh" "$B" parar > "$LOGD/00-parar-anterior.log" 2>&1; echo "rc=$?"
rm -rf "$B"
paso "1 montar (PREVIO=HEAD del árbol integrado)"; bash "$O/montar.sh" "$B" > "$LOGD/01-montar.log" 2>&1; echo "rc=$?"
paso "2 arrancar"; bash "$O/ensayar.sh" "$B" arrancar > "$LOGD/02-arrancar.log" 2>&1; echo "rc=$?"
paso "3 sembrar --personas=5"; bash "$O/sembrar.sh" "$B" --personas=5 > "$LOGD/03-sembrar.log" 2>&1; echo "rc=$?"
paso "4 valla"; bash "$O/ensayar.sh" "$B" valla > "$LOGD/04-valla.log" 2>&1; echo "rc=$?"
paso "5 paso 0 (--solo-comprobar)"; bash "$O/ensayar.sh" "$B" desplegar --solo-comprobar > "$LOGD/05-solo-comprobar.log" 2>&1; echo "rc=$?"
paso "6 todo (paso 0 → despliegue → índices → esquema → smoke → cierre)"; bash "$O/ensayar.sh" "$B" todo > "$LOGD/06-todo.log" 2>&1; echo "rc=$?"
paso "7 alcance estado"; bash "$O/ensayar.sh" "$B" alcance estado > "$LOGD/07-alcance-estado.log" 2>&1; echo "rc=$?"
paso "8 alcance parada-global"; bash "$O/ensayar.sh" "$B" alcance parada-global --motivo="ensayo OPS9-b del banco: PARADA GLOBAL" > "$LOGD/08-alcance-parada-global.log" 2>&1; echo "rc=$?"
paso "9 alcance estado tras la parada"; bash "$O/ensayar.sh" "$B" alcance estado > "$LOGD/09-alcance-estado-tras-parada.log" 2>&1; echo "rc=$?"
paso "10 cierre"; bash "$O/ensayar.sh" "$B" cierre > "$LOGD/10-cierre.log" 2>&1; echo "rc=$?"
paso "FIN"
