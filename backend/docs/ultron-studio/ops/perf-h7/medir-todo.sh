#!/usr/bin/env bash
# PERF7: la tanda completa de latencias EN SERIE (nunca dos medidas a la vez):
#   1. espera a que no quede ningún http-bench.py corriendo y para el servidor del banco (serve.sh stop);
#   2. familias A–F del arnés en proceso con PERF_N=30, PERF_WARM=5, PERF_REPS=3;
#   3. ng build ×3 del CRM (build-crm.py);
#   4. familia G (desglose de la evidencia) con PERF_N=${PERF_N_G:-10}.
# Anota la carga (vm.loadavg) antes y después de cada paso. Uso: PERF_STATE_DIR=<dir del banco> medir-todo.sh <raíz del CRM>
set -uo pipefail
cd "$(dirname "$0")/../../../.."
CRM="${1:?falta la raíz del CRM}"
H=docs/ultron-studio/ops/perf-h7
carga() { echo "[$(date +%T)] $1 · carga $(sysctl -n vm.loadavg)"; }
while pgrep -f "perf-h7/http-bench.py" >/dev/null; do sleep 5; done
carga "banco HTTP terminado"
[ -n "${PERF_STATE_DIR:-}" ] && "$H/serve.sh" stop
for f in a b c d e f; do
  carga "familia $f: empieza"
  PERF_N=30 PERF_WARM=5 PERF_REPS=3 "$H/run-queries.sh" "test_$f" 2>&1 | grep -E "OK \(|FAILURES|Tests:|Time:|Failed|Expected" || true
  carga "familia $f: termina"
done
carga "build del CRM: empieza"
python3 -I "$H/build-crm.py" "$CRM" 3
carga "build del CRM: termina"
carga "familia g: empieza"
PERF_N="${PERF_N_G:-10}" "$H/run-queries.sh" test_g 2>&1 | grep -E "OK \(|FAILURES|Tests:|Time:|Failed|Expected" || true
carga "familia g: termina"
