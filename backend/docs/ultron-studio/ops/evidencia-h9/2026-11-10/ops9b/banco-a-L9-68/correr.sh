#!/bin/bash
# Banco A remontado con el árbol actual (L9-64..L9-68): la secuencia completa otra vez, más el mando y la parada.
set -u
WT=/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body
O=$WT/backend/docs/ultron-studio/ops/banco-h9
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9
LOGD=$(cd "$(dirname "$0")" && pwd)
paso() { echo "#### $(date -u +%Y-%m-%dT%H:%M:%SZ) $1"; }
cd "$WT/backend" || exit 1
python3 -m py_compile docs/ultron-studio/ops/smoke-h9.py && rm -rf docs/ultron-studio/ops/__pycache__ && bash -n docs/ultron-studio/ops/h9-outreach-scope.sh || { echo "SINTAXIS MAL: no se ensaya"; exit 2; }
cd "$WT" || exit 1
paso "0 parar y limpiar el banco anterior"; bash "$O/ensayar.sh" "$B" parar > "$LOGD/00-parar-anterior.log" 2>&1; echo "rc=$?"; rm -rf "$B"
paso "1 montar (PREVIO=HEAD, árbol con L9-68)"; bash "$O/montar.sh" "$B" > "$LOGD/01-montar.log" 2>&1; echo "rc=$?"
paso "2 arrancar"; bash "$O/ensayar.sh" "$B" arrancar > "$LOGD/02-arrancar.log" 2>&1; echo "rc=$?"
paso "3 sembrar --personas=5"; bash "$O/sembrar.sh" "$B" --personas=5 > "$LOGD/03-sembrar.log" 2>&1; echo "rc=$?"
paso "4 valla"; bash "$O/ensayar.sh" "$B" valla > "$LOGD/04-valla.log" 2>&1; echo "rc=$?"
paso "5 todo (paso 0 → despliegue → índices → esquema → smoke → cierre)"; bash "$O/ensayar.sh" "$B" todo > "$LOGD/05-todo.log" 2>&1; echo "rc=$?"
paso "6 alcance estado"; bash "$O/ensayar.sh" "$B" alcance estado > "$LOGD/06-alcance-estado.log" 2>&1; echo "rc=$?"
paso "7 alcance parada-global"; bash "$O/ensayar.sh" "$B" alcance parada-global --motivo="ensayo OPS9-b del banco: PARADA GLOBAL" > "$LOGD/07-alcance-parada-global.log" 2>&1; echo "rc=$?"
paso "8 alcance apagar (nada que escribir: ya en nivel 0)"; bash "$O/ensayar.sh" "$B" alcance apagar > "$LOGD/08-alcance-apagar.log" 2>&1; echo "rc=$?"
paso "9 cierre"; bash "$O/ensayar.sh" "$B" cierre > "$LOGD/09-cierre.log" 2>&1; echo "rc=$?"
paso "10 parar"; bash "$O/ensayar.sh" "$B" parar > "$LOGD/10-parar.log" 2>&1; echo "rc=$?"
paso "FIN"
