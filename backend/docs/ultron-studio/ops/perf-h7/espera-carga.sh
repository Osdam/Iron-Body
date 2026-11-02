#!/usr/bin/env bash
# PERF7: espera a que la carga media a 1 minuto lleve VENTANA segundos seguidos por debajo de UMBRAL
# (por defecto 4 durante 300 s, muestreando cada 10 s) y sale con 0; con TOPE segundos sin conseguirlo, sale con 1.
# Uso: espera-carga.sh [UMBRAL] [VENTANA] [TOPE]
set -euo pipefail
UMBRAL="${1:-4}"; VENTANA="${2:-300}"; TOPE="${3:-7200}"
seguidos=0; pasado=0
while [ "$pasado" -lt "$TOPE" ]; do
  carga=$(sysctl -n vm.loadavg | awk '{print $2}')
  if awk -v c="$carga" -v u="$UMBRAL" 'BEGIN{exit !(c < u)}'; then seguidos=$((seguidos + 10)); else seguidos=0; fi
  if [ "$seguidos" -ge "$VENTANA" ]; then echo "carga < $UMBRAL durante ${VENTANA}s (última $carga) a las $(date +%T)"; exit 0; fi
  sleep 10; pasado=$((pasado + 10))
done
echo "sin ventana de carga < $UMBRAL en ${TOPE}s (última $carga)"; exit 1
