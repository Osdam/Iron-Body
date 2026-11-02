#!/usr/bin/env bash
# PERF7 (Hito 7): servidor LOCAL del banco HTTP del Studio, lo más parecido a producción que da esta máquina.
#
#   serve.sh seed     siembra ultron_studio_h7_perf_http (PerfH7SeedHttpTest.php): manifest.json y sesiones.txt
#   serve.sh start    config:cache + route:cache (en PERF_STATE_DIR, NUNCA en bootstrap/cache) y php artisan serve
#   serve.sh stop     mata nietos, hijo y padre por PID y comprueba que el puerto queda libre
#   serve.sh status   PID y una petición a /up
#   serve.sh bench    http-bench.py contra el servidor (PERF_N, PERF_WARM, PERF_REPS; filtro opcional como 2.º argumento)
#
# Variables: PERF_STATE_DIR (obligatoria: directorio propio fuera del repo; guarda la clave de la app, las cachés,
# el PID, el log, las sesiones y el manifiesto), PERF_PORT (8310; ≥ 8300: 8080–8200 son de la pila del E2E),
# PG_PORT (55432). Base: ultron_studio_h7_perf_http (la del arnés en proceso es otra).
#
# Como producción: APP_ENV=production, APP_DEBUG=false, caché de configuración y de rutas, caché y cola `database`,
# OPcache activo en el SAPI cli-server (opcache.enable=On; JIT apagado), sin Xdebug. Diferencias que quedan: php -S
# (no FPM ni nginx), PostgreSQL local sin red, este portátil (no el VPS). Las cifras son relativas, no de producción.
#
# Red: CERO. Solo GET a rutas del Studio sin red; además HTTP(S)_PROXY apunta a un puerto muerto (127.0.0.1:9) para
# que cualquier salida accidental de Guzzle falle en vez de salir, META_ENABLED=false, sonda de Twilio apagada,
# escrituras de integraciones apagadas y laboratorio con el conductor falso.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
STATE="${PERF_STATE_DIR:?pon PERF_STATE_DIR (un directorio propio, fuera del repo)}"
PORT="${PERF_PORT:-8310}"
DB=ultron_studio_h7_perf_http
mkdir -p "$STATE"
chmod 700 "$STATE"
if [ "$PORT" -lt 8300 ]; then echo "puerto $PORT no permitido (≥ 8300)" >&2; exit 2; fi

clave() {
  local f="$STATE/app.key"
  if [ ! -s "$f" ]; then
    (umask 077; php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$f")
  fi
  cat "$f"
}

comun() {
  APP_KEY_VALUE="$(clave)"
  ENTORNO=(
    "APP_KEY=$APP_KEY_VALUE"
    DB_CONNECTION=pgsql DB_HOST=127.0.0.1 "DB_PORT=${PG_PORT:-55432}" "DB_DATABASE=$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
    CACHE_STORE=database QUEUE_CONNECTION=database
    MARKETING_ULTRON_ENABLED=true MARKETING_ULTRON_STUDIO_CONFIG=canary MARKETING_ULTRON_CANARY_CONVERSATION_ID=987654321
    MARKETING_ULTRON_PAYMENT_LINKS_ENABLED=false MARKETING_INBOUND_AUTO_ANALYZE=false
    ULTRON_STUDIO_PUBLISHING_ENABLED=true ULTRON_STUDIO_PUBLISH_LAB_GATE=waived ULTRON_STUDIO_PUBLISH_WINDOW=
    ULTRON_STUDIO_CANARY_MIN_HOURS=24 ULTRON_STUDIO_CANARY_MIN_TURNS=3
    ULTRON_STUDIO_LAB_ENABLED=true ULTRON_STUDIO_LAB_DRIVER=fake
    ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false
    META_ENABLED=false META_ADS_ACCESS_TOKEN=perf-h7-centinela-sin-red META_AD_ACCOUNT_IDS=1000000000004714,1000000000009023,1000000000005856
    META_ADS_SYNC_ENABLED=true META_ADS_STALE_AFTER_MINUTES=180
    HTTPS_PROXY=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 NO_PROXY= no_proxy=
    PGGSSENCMODE=disable
  )
}

servidor_env() {
  comun
  ENTORNO+=(
    APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning
    "APP_CONFIG_CACHE=$STATE/config.php" "APP_ROUTES_CACHE=$STATE/routes.php" "APP_EVENTS_CACHE=$STATE/events.php"
    PHP_CLI_SERVER_WORKERS=4
  )
}

case "${1:-}" in
  seed)
    comun
    env "${ENTORNO[@]}" "PERF_STATE_DIR=$STATE" php -d memory_limit=2G vendor/bin/phpunit --no-logging --do-not-cache-result \
      docs/ultron-studio/ops/perf-h7/PerfH7SeedHttpTest.php
    ;;
  start)
    if [ -s "$STATE/server.pid" ] && kill -0 "$(cat "$STATE/server.pid")" 2>/dev/null; then echo "ya está arrancado"; exit 0; fi
    servidor_env
    env "${ENTORNO[@]}" php artisan config:cache >/dev/null
    env "${ENTORNO[@]}" php artisan route:cache >/dev/null
    env "${ENTORNO[@]}" php artisan event:cache >/dev/null
    nohup env "${ENTORNO[@]}" php artisan serve --no-reload --host=127.0.0.1 "--port=$PORT" > "$STATE/server.log" 2>&1 &
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
    PERF_TOKENS_FILE="$STATE/sesiones.txt" PERF_BASE="http://127.0.0.1:$PORT/api/admin/ultron-studio" \
      python3 -I docs/ultron-studio/ops/perf-h7/http-bench.py "$STATE/manifest.json" ${2:+"$2"}
    ;;
  *)
    sed -n '2,20p' "$0"; exit 2
    ;;
esac
