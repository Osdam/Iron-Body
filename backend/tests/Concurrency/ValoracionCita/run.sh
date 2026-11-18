#!/usr/bin/env bash
# Carreras reales del ciclo de valoraciones contra PostgreSQL (ver README.md).
#
# Uso: run.sh <escenario>     doble_envio | proxima_cita_doble | all
#
# Variables:
#   RACE_APP   backend a probar (por defecto, el de este árbol).
#   RACE_DB    base PG desechable; debe empezar por vc_a_ (por defecto vc_a_race)
#   PG_PORT    puerto del PostgreSQL local (por defecto 55432)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="${RACE_APP:-$(cd "$HERE/../../.." && pwd)}"
DB="${RACE_DB:-vc_a_race}"
PHP="${PHP:-php}"
case "$DB" in vc_a_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
export APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null MAIL_MAILER=array SESSION_DRIVER=array
export FACTUS_ENABLED=false LOG_CHANNEL=single FCM_ENABLED=false
export TRAINER_AUTH_ENABLED=true TRAINER_PROFESSIONAL_ASSESSMENTS_ENABLED=true
# macOS: libpq con GSSAPI falla en procesos hijos; no hace falta para una base local.
export PGGSSENCMODE=disable

ESCENARIOS="doble_envio proxima_cita_doble"

correr() {
  local sc="$1" out
  out="$(mktemp -d)/escenario.json"
  (cd "$APP" && "$PHP" artisan migrate:fresh --force >/dev/null 2>&1) || { echo "migrate:fresh falló"; return 2; }
  "$PHP" "$HERE/setup.php" "$APP" "$sc" "$out" >/dev/null || return 2
  "$PHP" "$HERE/race.php" "$APP" "$out" "$DB"
}

case "${1:-}" in
doble_envio|proxima_cita_doble) correr "$1" ;;
all)
  rc=0
  for sc in $ESCENARIOS; do correr "$sc" || rc=$?; done
  exit $rc ;;
*) echo "uso: run.sh doble_envio|proxima_cita_doble|all" >&2; exit 2 ;;
esac
