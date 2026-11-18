#!/usr/bin/env bash
# Carreras reales de «SOLICITAR VALORACIÓN» contra PostgreSQL (ver README.md).
#
# Uso: run.sh <escenario>     dos_toques_misma_llave | dos_toques_llaves_distintas | agendar_vs_rechazar | all
#
# Variables:
#   RACE_APP   backend a probar (por defecto, el de este árbol). Para demostrar un
#              fallo, apúntalo a una COPIA mutada con su vendor y su .env.
#   RACE_DB    base PG desechable; debe empezar por vc_c_ (por defecto vc_c_solicitud_race)
#   PG_PORT    puerto del PostgreSQL local (por defecto 55432)
#   RACE_DROP_PENDING_INDEX=1  borra el índice único parcial tras migrar (solo demostraciones)
#   RACE_REVERSE=1  lanza las peticiones en orden inverso
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="${RACE_APP:-$(cd "$HERE/../../.." && pwd)}"
DB="${RACE_DB:-vc_c_solicitud_race}"
PHP="${PHP:-php}"
case "$DB" in vc_c_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
export APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null MAIL_MAILER=array SESSION_DRIVER=array
export FACTUS_ENABLED=false LOG_CHANNEL=single
# macOS: libpq con GSSAPI falla en procesos hijos; no hace falta para una base local.
export PGGSSENCMODE=disable

ESCENARIOS="dos_toques_misma_llave dos_toques_llaves_distintas agendar_vs_rechazar"

correr() {
  local sc="$1" out
  out="$(mktemp -d)/escenario.json"
  (cd "$APP" && "$PHP" artisan migrate:fresh --force >/dev/null 2>&1) || { echo "migrate:fresh falló"; return 2; }
  "$PHP" "$HERE/setup.php" "$APP" "$sc" "$out" >/dev/null || return 2
  "$PHP" "$HERE/race.php" "$APP" "$out" "$DB"
}

case "${1:-}" in
dos_toques_misma_llave|dos_toques_llaves_distintas|agendar_vs_rechazar) correr "$1" ;;
all)
  rc=0
  for sc in $ESCENARIOS; do correr "$sc" || rc=$?; done
  exit $rc ;;
*) echo "uso: run.sh dos_toques_misma_llave|dos_toques_llaves_distintas|agendar_vs_rechazar|all" >&2; exit 2 ;;
esac
