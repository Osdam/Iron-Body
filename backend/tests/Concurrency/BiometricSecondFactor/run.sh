#!/usr/bin/env bash
# Carreras reales del segundo factor biométrico contra PostgreSQL (ver README.md).
#
# Uso: run.sh <escenario>          escenarios: pin | pair | exc_renewal | exc_first | twin
#      run.sh serve-start | serve-stop
#
# Variables:
#   RACE_APP   copia AISLADA del backend (con su vendor real y su .env de pruebas). Obligatoria:
#              nunca el árbol de trabajo (las mutaciones y los logs van ahí).
#   RACE_DB    base PG desechable; debe empezar por ultron_studio_ (por defecto ultron_studio_biometria_race)
#   RACE_PORT  puerto de artisan serve (por defecto 8791)
#   PG_PORT    puerto del PostgreSQL local (por defecto 55432)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
: "${RACE_APP:?RACE_APP = copia aislada del backend}"
DB="${RACE_DB:-ultron_studio_biometria_race}"
PORT="${RACE_PORT:-8791}"
PHP="${PHP:-/opt/homebrew/bin/php}"
case "$DB" in ultron_studio_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac
# macOS: sin esto los workers de php -S mueren en GSSAPI tras el fork («other side closed»).
export PGGSSENCMODE=disable

serve_pids() { pgrep -f "artisan serve --no-reload --host=127.0.0.1 --port=${PORT}"; }

case "${1:-}" in
serve-start)
  cd "$RACE_APP" && (DB_DATABASE="$DB" PHP_CLI_SERVER_WORKERS=16 nohup "$PHP" artisan serve --no-reload --host=127.0.0.1 --port="$PORT" > "$RACE_APP/storage/logs/serve.log" 2>&1 &)
  sleep 3
  grep -qi "unable to respect" "$RACE_APP/storage/logs/serve.log" && { echo "AVISO: servidor en serie (falta --no-reload)"; exit 3; }
  for p in $(serve_pids); do for c in $(pgrep -P "$p"); do echo "artisan=$p php-S=$c workers=$(pgrep -P "$c" | wc -l | tr -d ' ')"; done; done
  curl -s -o /dev/null -w "up=%{http_code}\n" "http://127.0.0.1:${PORT}/up" ;;
serve-stop)
  # Matar solo al padre deja huérfanos escuchando: nietos, hijo y padre por PID.
  for p in $(serve_pids); do for c in $(pgrep -P "$p"); do kill $(pgrep -P "$c") 2>/dev/null; kill "$c" 2>/dev/null; done; kill "$p" 2>/dev/null; done
  sleep 1; for w in $(lsof -tiTCP:"$PORT" -sTCP:LISTEN); do kill "$w" 2>/dev/null; done; sleep 1
  lsof -iTCP:"$PORT" -sTCP:LISTEN >/dev/null && echo "AÚN ESCUCHA" || echo "parado" ;;
pin|pair|exc_renewal|exc_first|twin)
  cd "$RACE_APP" || exit 2
  DB_DATABASE="$DB" "$PHP" artisan migrate:fresh --force >/dev/null 2>&1 || { echo "migrate:fresh falló"; exit 2; }
  DB_DATABASE="$DB" "$PHP" "$HERE/setup.php" "$1" "$RACE_APP/storage/race-$1.json" >/dev/null || exit 2
  PG_PORT="${PG_PORT:-55432}" "$PHP" "$HERE/race.php" "$RACE_APP/storage/race-$1.json" "http://127.0.0.1:${PORT}" "$DB" ;;
*) echo "uso: run.sh pin|pair|exc_renewal|exc_first|twin|serve-start|serve-stop" >&2; exit 2 ;;
esac
