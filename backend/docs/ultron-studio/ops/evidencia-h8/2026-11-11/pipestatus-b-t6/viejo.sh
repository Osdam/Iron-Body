#!/bin/bash
set -euo pipefail
guion() { echo "salida del guion"; return "$1"; }
EST=0
for g in a; do
  guion "$CODIGO" 2>&1 | tee /dev/null | sed 's/^/   /' || true
  [ "${PIPESTATUS[0]}" = 0 ] || EST=1
done
echo "VIEJO: guion con $CODIGO -> EST=$EST"
