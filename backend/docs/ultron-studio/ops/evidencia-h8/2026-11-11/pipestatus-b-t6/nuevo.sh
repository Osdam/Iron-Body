#!/bin/bash
set -euo pipefail
guion() { echo "salida del guion"; return "$1"; }
EST=0
for g in a; do
  set +e
  guion "$CODIGO" 2>&1 | tee /dev/null | sed 's/^/   /'
  GEST=${PIPESTATUS[0]}
  set -e
  [ "$GEST" = 0 ] || EST=1
done
echo "NUEVO: guion con $CODIGO -> EST=$EST"
