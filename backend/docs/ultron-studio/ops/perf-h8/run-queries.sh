#!/usr/bin/env bash
# PERF8 (Hito 8): arnés EN PROCESO de la voz y la telefonía (PerfH8QueriesTest.php).
#
#   run-queries.sh seed            migrate:fresh + siembra a escala en ultron_studio_h8_perf_seed (una vez; ~minutos)
#   run-queries.sh <familia>       COPIA la base sembrada en ultron_studio_h8_perf (CREATE DATABASE … TEMPLATE)
#                                  y corre esa familia: test_a_iniciacion, test_b_post_llamada, test_c_herramientas,
#                                  test_d_aviso_twilio, test_e_crm, test_f_studio, test_g_lectores, test_h_job_y_conciliacion
#   run-queries.sh todas           las ocho, cada una en su copia nueva
#
#   PERF_N=0 (por defecto) → consultas, filas, bytes, EXPLAIN (ANALYZE, BUFFERS) y uso de índices (no depende de la carga)
#   PERF_N=30 PERF_WARM=5 PERF_REPS=3 → además, tiempos: 3 tandas de 30 tras 5 de calentamiento (sin EXPLAIN)
#   PERF_STATE_DIR (obligatoria): directorio propio FUERA del repo para el `storage` de Laravel (registros del canal `voice`,
#     vistas, sesiones): así el arnés no escribe en storage/ del árbol compartido (lo lee el E2E).
#   PG_PORT (55432), PERF_OUT_DIR (por defecto ./resultados).
#
# Solo admite sus dos bases (ultron_studio_h8_perf y ultron_studio_h8_perf_seed): nunca toca una ajena.
# Caché y cola `database` (como producción: los limitadores y la caché de permisos cuentan). Sin red: el arnés
# activa Http::preventStrayRequests() y el desplegador real solo va contra Http::fake.
# No escribe storage/test-results/junit.xml ni .phpunit.result.cache.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
STATE="${PERF_STATE_DIR:?pon PERF_STATE_DIR (un directorio propio, fuera del repo)}"
PORT_PG="${PG_PORT:-55432}"
SEED_DB=ultron_studio_h8_perf_seed
DB=ultron_studio_h8_perf
export PGGSSENCMODE=disable
PSQL=(psql -h 127.0.0.1 -p "$PORT_PG" -U ironbody -v ON_ERROR_STOP=1 -q -At -d postgres)

mkdir -p "$STATE"/storage/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,framework/testing,logs}
chmod 700 "$STATE"

ejecutar() {
  local base="$1"; shift
  case "$base" in "$SEED_DB"|"$DB") ;; *) echo "base no permitida: $base" >&2; exit 2;; esac
  env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$PORT_PG" DB_DATABASE="$base" DB_USERNAME=ironbody DB_PASSWORD= DB_URL= \
    CACHE_STORE=database QUEUE_CONNECTION=database \
    APP_KEY=base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8= \
    LARAVEL_STORAGE_PATH="$STATE/storage" \
    HTTPS_PROXY=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 http_proxy=http://127.0.0.1:9 NO_PROXY= no_proxy= \
    php -d memory_limit=3G vendor/bin/phpunit --no-logging --do-not-cache-result \
    docs/ultron-studio/ops/perf-h8/PerfH8QueriesTest.php "$@"
}

copiar() {
  "${PSQL[@]}" -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)"
  "${PSQL[@]}" -c "CREATE DATABASE $DB TEMPLATE $SEED_DB"
}

FAMILIAS=(test_a_iniciacion test_b_post_llamada test_c_herramientas test_d_aviso_twilio test_e_crm test_f_studio test_g_lectores test_h_job_y_conciliacion)

case "${1:-}" in
  seed)
    if ! "${PSQL[@]}" -c "select 1 from pg_database where datname = '$SEED_DB'" | grep -q 1; then
      "${PSQL[@]}" -c "CREATE DATABASE $SEED_DB"
    fi
    ejecutar "$SEED_DB" --filter test_00_siembra
    ;;
  todas)
    for f in "${FAMILIAS[@]}"; do
      copiar
      ejecutar "$DB" --filter "$f"
    done
    ;;
  test_*)
    copiar
    ejecutar "$DB" --filter "$1"
    ;;
  tramos)
    # Desglose de CPU de la post-llamada (PerfH8TramosTest.php): no usa la base, pero arranca la aplicación con su entorno.
    copiar
    env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$PORT_PG" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL= \
      CACHE_STORE=database QUEUE_CONNECTION=database APP_KEY=base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8= \
      LARAVEL_STORAGE_PATH="$STATE/storage" \
      php -d memory_limit=3G vendor/bin/phpunit --no-logging --do-not-cache-result docs/ultron-studio/ops/perf-h8/PerfH8TramosTest.php
    ;;
  *)
    sed -n '2,22p' "$0"; exit 2
    ;;
esac
