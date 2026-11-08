#!/usr/bin/env bash
# PERF8 (Hito 8): servidor LOCAL del banco HTTP de la voz, lo más parecido a producción que da esta máquina.
#
#   serve.sh seed     copia ultron_studio_h8_perf_seed en ultron_studio_h8_perf_http y prepara el banco
#                     (PerfH8HttpPrepTest.php): sesiones, llamadas activas, cuerpos firmados y manifest.json
#   serve.sh start    config:cache + route:cache + event:cache (en PERF_STATE_DIR/http, NUNCA en bootstrap/cache)
#                     y php artisan serve --no-reload con 4 workers
#   serve.sh stop     mata nietos, hijos y padre por PID y comprueba que el puerto queda libre
#   serve.sh status   PID y una petición a /up
#   serve.sh bench    http-bench-h8.py contra el servidor (PERF_HTTP_N, PERF_HTTP_WARM; filtro opcional como 2.º argumento)
#
# Variables: PERF_STATE_DIR (obligatoria, fuera del repo: cachés, storage, PID, log, manifiesto y cuerpos),
# PERF_PORT (8610; ≥ 8600: 8000 y 8401–8404 son del E2E), PG_PORT (55432).
#
# Como producción: APP_ENV=production, APP_DEBUG=false, caché de configuración/rutas/eventos, caché y cola `database`,
# OPcache del SAPI cli-server, canal `voice` escribiendo (en el storage propio). Diferencias: php -S (no FPM ni nginx),
# sin TLS, PostgreSQL local, este portátil. CERO RED: ElevenLabs apagado, los proxies apuntan a un puerto muerto
# (127.0.0.1:9) para que una salida accidental FALLE, y las credenciales son las SINTÉTICAS del mundo de la medida.
# `CALLS_ENABLED_IN_CODE` sigue en false (la iniciación del canario se deniega con ese único motivo).
set -euo pipefail
cd "$(dirname "$0")/../../../.."
STATE="${PERF_STATE_DIR:?pon PERF_STATE_DIR (un directorio propio, fuera del repo)}/http"
PORT="${PERF_PORT:-8610}"
PORT_PG="${PG_PORT:-55432}"
DB=ultron_studio_h8_perf_http
SEED_DB=ultron_studio_h8_perf_seed
export PGGSSENCMODE=disable
if [ "$PORT" -lt 8600 ]; then echo "puerto $PORT no permitido (≥ 8600)" >&2; exit 2; fi
mkdir -p "$STATE"/storage/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,framework/testing,logs}
chmod 700 "$STATE"

ENTORNO=(
  APP_KEY=base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 "DB_PORT=$PORT_PG" "DB_DATABASE=$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
  CACHE_STORE=database QUEUE_CONNECTION=database
  "LARAVEL_STORAGE_PATH=$STATE/storage"
  TWILIO_VOICE_FROM=+576010000090
  ELEVENLABS_INITIATION_SECRET=perf-h8-initiation-00000000000000000000000001
  ELEVENLABS_TOOL_SECRET=perf-h8-tool-bearer-0000000000000000000000001
  ELEVENLABS_WEBHOOK_SECRET=whsec_perf_h8_postcall_00000000000000000001
  ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_perf_h8_0001
  TWILIO_VOICE_SIGNING_TOKEN=perfh8subaccountsigning000000001
  VOICE_WEBHOOK_BASE_URL=https://crm.ironbody.test
  VOICE_INBOUND_ENABLED=true VOICE_INBOUND_DAILY_CAP=20 VOICE_MAX_CONCURRENT_CALLS=4 VOICE_PER_CALL_MAX_SECONDS=300 VOICE_DAILY_BUDGET_USD=500
  "VOICE_FACTS_SITE=Sede Neiva centro, calle de ficción 1" "VOICE_FACTS_HOURS=De lunes a viernes de 5:00 a 22:00 y sábados de 6:00 a 14:00"
  VOICE_TRANSFER_HOURS_WEEKDAYS=00:00-23:59 VOICE_TRANSFER_HOURS_SATURDAY=00:00-23:59
  VOICE_LAB_ENABLED=true VOICE_LAB_DRIVER=fake
  HTTPS_PROXY=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 http_proxy=http://127.0.0.1:9 NO_PROXY= no_proxy=
  PGGSSENCMODE=disable
)
SERVIDOR=(
  APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning
  "APP_CONFIG_CACHE=$STATE/config.php" "APP_ROUTES_CACHE=$STATE/routes.php" "APP_EVENTS_CACHE=$STATE/events.php"
  PHP_CLI_SERVER_WORKERS=4
)
PSQL=(psql -h 127.0.0.1 -p "$PORT_PG" -U ironbody -v ON_ERROR_STOP=1 -q -At -d postgres)

case "${1:-}" in
  seed)
    "${PSQL[@]}" -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)"
    "${PSQL[@]}" -c "CREATE DATABASE $DB TEMPLATE $SEED_DB"
    env "${ENTORNO[@]}" "PERF_HTTP_DIR=$STATE" php -d memory_limit=3G vendor/bin/phpunit --no-logging --do-not-cache-result \
      docs/ultron-studio/ops/perf-h8/PerfH8HttpPrepTest.php
    ;;
  start)
    if [ -s "$STATE/server.pid" ] && kill -0 "$(cat "$STATE/server.pid")" 2>/dev/null; then echo "ya está arrancado"; exit 0; fi
    if lsof -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then echo "el puerto $PORT está ocupado" >&2; exit 1; fi
    env "${ENTORNO[@]}" "${SERVIDOR[@]}" php artisan config:cache >/dev/null
    env "${ENTORNO[@]}" "${SERVIDOR[@]}" php artisan route:cache >/dev/null
    env "${ENTORNO[@]}" "${SERVIDOR[@]}" php artisan event:cache >/dev/null
    nohup env "${ENTORNO[@]}" "${SERVIDOR[@]}" php artisan serve --no-reload --host=127.0.0.1 "--port=$PORT" > "$STATE/server.log" 2>&1 &
    echo $! > "$STATE/server.pid"
    for _ in $(seq 1 60); do
      if curl -fsS -o /dev/null "http://127.0.0.1:$PORT/up"; then break; fi
      sleep 0.5
    done
    curl -fsS -o /dev/null -w "arrancado: /up → %{http_code} en %{time_total}s\n" "http://127.0.0.1:$PORT/up"
    if grep -q "Unable to respect the PHP_CLI_SERVER_WORKERS" "$STATE/server.log"; then echo "AVISO: sin workers (falta --no-reload)" >&2; fi
    ;;
  stop)
    if [ -s "$STATE/server.pid" ]; then
      padre="$(cat "$STATE/server.pid")"
      hijos="$(pgrep -P "$padre" || true)"
      nietos=""
      for h in $hijos; do nietos="$nietos $(pgrep -P "$h" || true)"; done
      for p in $nietos $hijos $padre; do kill "$p" 2>/dev/null || true; done
      sleep 1
      for p in $nietos $hijos $padre; do kill -9 "$p" 2>/dev/null || true; done
      rm -f "$STATE/server.pid"
    fi
    if lsof -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then echo "el puerto $PORT sigue ocupado:" >&2; lsof -iTCP:"$PORT" -sTCP:LISTEN >&2; exit 1; fi
    echo "parado; puerto $PORT libre"
    ;;
  status)
    [ -s "$STATE/server.pid" ] && echo "pid $(cat "$STATE/server.pid")" || echo "sin pid"
    curl -sS -o /dev/null -w "/up → %{http_code} en %{time_total}s\n" "http://127.0.0.1:$PORT/up" || true
    ;;
  bench)
    PERF_BASE="http://127.0.0.1:$PORT/api" python3 -I docs/ultron-studio/ops/perf-h8/http-bench-h8.py "$STATE" ${2:+"$2"}
    ;;
  *)
    sed -n '2,22p' "$0"; exit 2
    ;;
esac
