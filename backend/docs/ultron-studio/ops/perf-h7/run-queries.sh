#!/usr/bin/env bash
# PERF7 (Hito 7): arnés de consultas, bytes y tiempo EN PROCESO (PerfH7QueriesTest.php).
# Uso:  run-queries.sh [filtro de phpunit]            p. ej. run-queries.sh test_b
#   PERF_N=0 (por defecto) → solo consultas y bytes (no depende de la carga de la máquina)
#   PERF_N=30 PERF_WARM=5 PERF_REPS=3 → además, 3 tandas de 30 peticiones cronometradas tras 5 de calentamiento
#   PERF_DB (ultron_studio_h7_perf*), PG_PORT (55432), PERF_OUT_DIR (por defecto ./resultados), PERF_BG_ACTIONS (20000)
# Cada familia hace migrate:fresh en PERF_DB: nunca apuntes a una base ajena (el guion y el arnés lo impiden).
# No escribe storage/test-results/junit.xml ni .phpunit.result.cache (son el rastro de la suite de otros).
set -euo pipefail
cd "$(dirname "$0")/../../../.."
DB="${PERF_DB:-ultron_studio_h7_perf}"
case "$DB" in ultron_studio_h7_perf*) ;; *) echo "base no permitida: $DB (ultron_studio_h7_perf*)" >&2; exit 2;; esac
export PGGSSENCMODE=disable
exec env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL= \
  CACHE_STORE=database QUEUE_CONNECTION=database \
  php -d memory_limit=2G vendor/bin/phpunit --no-logging --do-not-cache-result \
  docs/ultron-studio/ops/perf-h7/PerfH7QueriesTest.php ${1:+--filter "$1"}
