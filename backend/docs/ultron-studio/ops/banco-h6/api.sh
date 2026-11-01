#!/bin/bash
# La API del banco (php artisan serve --no-reload) en 127.0.0.1:$BANCO_PORT.
# Uso: bash api.sh <banco> start|stop|status
# stop mata nietos, hijos y padre por PID (php -S deja procesos hijos; memoria «php -S en macOS»).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
escuchando() { lsof -nP -iTCP:"$BANCO_PORT" -sTCP:LISTEN 2> /dev/null | tail -n +2 | awk '{print $2}' | sort -u; }
case "${2:-}" in
  start)
    [ -z "$(escuchando)" ] || muere "el puerto $BANCO_PORT ya está ocupado (pid $(escuchando | tr '\n' ' '))"
    ( cd "$B/api/backend" && nohup php artisan serve --host=127.0.0.1 --port="$BANCO_PORT" --no-reload > "$B/logs/serve.log" 2>&1 & echo $! > "$B/serve.pid" )
    for _ in $(seq 1 20); do
      c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://127.0.0.1:$BANCO_PORT/api/admin/ultron-studio/status" || true)
      [ "$c" = 401 ] && { echo "API del banco en http://127.0.0.1:$BANCO_PORT (status sin token → 401; pid $(cat "$B/serve.pid"))"; exit 0; }
      sleep 1
    done
    muere "la API no respondió en 20 s (ver $B/logs/serve.log)" ;;
  stop)
    P=$(cat "$B/serve.pid" 2> /dev/null || true)
    if [ -n "$P" ]; then
      HIJOS=$(pgrep -P "$P" 2> /dev/null || true); NIETOS=""
      for h in $HIJOS; do NIETOS="$NIETOS $(pgrep -P "$h" 2> /dev/null || true)"; done
      for p in $NIETOS $HIJOS $P; do kill "$p" 2> /dev/null || true; done
      rm -f "$B/serve.pid"
    fi
    sleep 1
    [ -z "$(escuchando)" ] && echo "API del banco parada" || muere "el puerto $BANCO_PORT sigue ocupado (pid $(escuchando | tr '\n' ' ')): pararlo a mano" ;;
  status)
    [ -n "$(escuchando)" ] && echo "escuchando en $BANCO_PORT (pid $(escuchando | tr '\n' ' '))" || echo "parada" ;;
  *) muere "uso: bash api.sh <banco> start|stop|status" ;;
esac
